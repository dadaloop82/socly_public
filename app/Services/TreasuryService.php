<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Validator;

final class TreasuryService
{
    /** @var list<string> */
    public const BUILTIN_CATEGORIES = [
        'membership_fee',
        'donation',
        'rent',
        'utilities',
        'events',
        'supplies',
        'other',
    ];

    /** @var list<string> */
    public const METHODS = ['cash', 'bank', 'pos', 'other'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly Validator $validator,
        private readonly ComponentService $components
    ) {
    }

    /** @return array{movements:list<array<string,mixed>>,balance:float,income:float,expense:float} */
    public function ledger(int $limit = 200, string $query = ''): array
    {
        $params = [];
        $sql = "SELECT m.*,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'first_name'
                     WHERE v.member_id = mem.id LIMIT 1) AS first_name,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'last_name'
                     WHERE v.member_id = mem.id LIMIT 1) AS last_name,
                    mem.member_number
             FROM treasury_movements m
             LEFT JOIN members mem ON mem.id = m.member_id";

        $query = trim($query);
        if ($query !== '') {
            $sql .= ' WHERE ' . $this->buildSearchWhere($query, $params);
        }
        $sql .= ' ORDER BY m.movement_date DESC, m.id DESC LIMIT ' . max(1, min(500, $limit));
        $movements = $this->db->fetchAll($sql, $params);

        $balanceRow = $this->db->fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) AS inc,
                COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) AS exp
             FROM treasury_movements"
        );
        $balance = (float) ($balanceRow['inc'] ?? 0) - (float) ($balanceRow['exp'] ?? 0);

        return [
            'movements' => $movements,
            'balance' => $balance,
            'income' => (float) ($balanceRow['inc'] ?? 0),
            'expense' => (float) ($balanceRow['exp'] ?? 0),
        ];
    }

    /**
     * Association balance charts for the dashboard.
     *
     * @return array{
     *   flow: array{labels:list<string>,values:list<float>},
     *   expense_by_category: array{labels:list<string>,values:list<float>},
     *   income_by_category: array{labels:list<string>,values:list<float>}
     * }
     */
    public function dashboardCharts(): array
    {
        $totals = $this->db->fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) AS inc,
                COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) AS exp
             FROM treasury_movements"
        );
        $income = (float) ($totals['inc'] ?? 0);
        $expense = (float) ($totals['exp'] ?? 0);

        return [
            'flow' => [
                'labels' => [
                    __('treasury.direction_income'),
                    __('treasury.direction_expense'),
                ],
                'values' => [$income, $expense],
            ],
            'expense_by_category' => $this->sumsByCategory('expense'),
            'income_by_category' => $this->sumsByCategory('income'),
        ];
    }

    /**
     * @return array{labels:list<string>,values:list<float>}
     */
    private function sumsByCategory(string $direction): array
    {
        $rows = $this->db->fetchAll(
            "SELECT category, COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE direction = :dir
             GROUP BY category
             HAVING total > 0
             ORDER BY total DESC
             LIMIT 8",
            ['dir' => $direction]
        );
        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['category'] ?? ''));
            if ($key === '') {
                $key = 'other';
            }
            $labels[] = $this->categoryLabel($key);
            $values[] = (float) ($row['total'] ?? 0);
        }
        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @return list<array{key:string,label:string,items:list<array<string,mixed>>}>
     */
    public function groupedByCategory(int $limit = 200, string $query = ''): array
    {
        $ledger = $this->ledger($limit, $query);
        $items = $ledger['movements'];
        $labels = $this->categoryMap();
        $buckets = [];
        foreach ($items as $item) {
            $key = trim((string) ($item['category'] ?? 'other'));
            if ($key === '') {
                $key = 'other';
            }
            if (!isset($buckets[$key])) {
                $buckets[$key] = [];
            }
            $buckets[$key][] = $item;
        }

        $ordered = [];
        foreach ($this->categoryOptions() as $opt) {
            $key = (string) $opt['key'];
            if (!isset($buckets[$key])) {
                continue;
            }
            $ordered[] = [
                'key' => $key,
                'label' => (string) $opt['label'],
                'items' => $buckets[$key],
            ];
            unset($buckets[$key]);
        }
        ksort($buckets);
        foreach ($buckets as $key => $groupItems) {
            $ordered[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $this->humanizeSlug($key),
                'items' => $groupItems,
            ];
        }
        return $ordered;
    }

    /**
     * @return list<array{key:string,label:string,builtin:bool}>
     */
    public function categoryOptions(): array
    {
        $options = [];
        foreach (self::BUILTIN_CATEGORIES as $key) {
            $options[] = [
                'key' => $key,
                'label' => __('treasury.category_' . $key),
                'builtin' => true,
            ];
        }
        foreach ($this->customCategories() as $row) {
            $options[] = [
                'key' => (string) $row['slug'],
                'label' => (string) $row['label'],
                'builtin' => false,
            ];
        }
        return $options;
    }

    /** @return array<string,string> */
    public function categoryMap(): array
    {
        $map = [];
        foreach ($this->categoryOptions() as $opt) {
            $map[(string) $opt['key']] = (string) $opt['label'];
        }
        return $map;
    }

    public function categoryLabel(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            $key = 'other';
        }
        $map = $this->categoryMap();
        return $map[$key] ?? $this->humanizeSlug($key);
    }

    public function defaultCategory(): string
    {
        $cfg = $this->components->config('treasury', ['default_category' => 'membership_fee']);
        $key = trim((string) ($cfg['default_category'] ?? 'membership_fee'));
        $valid = array_column($this->categoryOptions(), 'key');
        return in_array($key, $valid, true) ? $key : 'membership_fee';
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        return $this->db->fetch(
            "SELECT m.*,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'first_name'
                     WHERE v.member_id = mem.id LIMIT 1) AS first_name,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'last_name'
                     WHERE v.member_id = mem.id LIMIT 1) AS last_name,
                    mem.member_number
             FROM treasury_movements m
             LEFT JOIN members mem ON mem.id = m.member_id
             WHERE m.id = :id",
            ['id' => $id]
        );
    }

    /** @param array<string,mixed> $input */
    public function create(array $input, string $ip, ?int $userId = null): array
    {
        $parsed = $this->parseInput($input, null);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'errors' => $parsed['errors'] ?? ['amount' => __('validation.required')]];
        }
        /** @var array<string,mixed> $data */
        $data = $parsed['data'];
        $data['created_by'] = $userId;
        $id = $this->db->insert('treasury_movements', $data);
        $this->audit->log('treasury.created', 'treasury', (string) $id, null, $input, $ip);
        return ['ok' => true, 'id' => $id];
    }

    /** @param array<string,mixed> $input */
    public function update(int $id, array $input, string $ip): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return ['ok' => false, 'errors' => ['id' => __('errors.404')]];
        }
        $parsed = $this->parseInput($input, $existing);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'errors' => $parsed['errors'] ?? ['amount' => __('validation.required')]];
        }
        /** @var array<string,mixed> $data */
        $data = $parsed['data'];
        $this->db->update('treasury_movements', $data, 'id = :id', ['id' => $id]);
        $this->audit->log('treasury.updated', 'treasury', (string) $id, [
            'direction' => $existing['direction'] ?? null,
            'amount' => $existing['amount'] ?? null,
            'category' => $existing['category'] ?? null,
            'movement_date' => $existing['movement_date'] ?? null,
        ], [
            'direction' => $data['direction'],
            'amount' => $data['amount'],
            'category' => $data['category'],
            'movement_date' => $data['movement_date'],
        ], $ip);
        return ['ok' => true, 'id' => $id];
    }

    public function autoRegisterFromPayment(int $paymentId, int $memberId, float $amount, string $method, string $date): void
    {
        if (!$this->components->isEnabled('treasury')) {
            return;
        }
        $cfg = $this->components->config('treasury', ['auto_from_payments' => true]);
        if (empty($cfg['auto_from_payments'])) {
            return;
        }
        $exists = $this->db->fetch('SELECT id FROM treasury_movements WHERE payment_id = :p', ['p' => $paymentId]);
        if ($exists) {
            return;
        }
        $this->db->insert('treasury_movements', [
            'movement_date' => $date,
            'direction' => 'income',
            'category' => 'membership_fee',
            'amount' => $amount,
            'description' => __('treasury.auto_membership_payment'),
            'payment_method' => $method,
            'member_id' => $memberId,
            'payment_id' => $paymentId,
        ]);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array{ok:bool,errors?:array<string,string>,data?:array<string,mixed>}
     */
    private function parseInput(array $input, ?array $existing): array
    {
        $direction = (string) ($input['direction'] ?? '');
        if (!in_array($direction, ['income', 'expense'], true)) {
            return ['ok' => false, 'errors' => ['direction' => __('validation.in')]];
        }
        $amount = (float) str_replace(',', '.', (string) ($input['amount'] ?? '0'));
        if ($amount <= 0) {
            return ['ok' => false, 'errors' => ['amount' => __('validation.required')]];
        }
        $date = trim((string) ($input['movement_date'] ?? date('Y-m-d')));
        if (!$this->validator->validate(['movement_date' => $date], ['movement_date' => 'date'])) {
            return ['ok' => false, 'errors' => ['movement_date' => __('validation.date')]];
        }
        $categoryResult = $this->resolveCategory($input, $existing);
        if (empty($categoryResult['ok'])) {
            return ['ok' => false, 'errors' => ['category' => (string) ($categoryResult['error'] ?? __('validation.required'))]];
        }
        $method = trim((string) ($input['payment_method'] ?? 'cash'));
        if (!in_array($method, self::METHODS, true)) {
            $method = 'cash';
        }
        $memberId = trim((string) ($input['member_id'] ?? ''));
        $memberId = $memberId !== '' ? (int) $memberId : null;

        return [
            'ok' => true,
            'data' => [
                'movement_date' => $date,
                'direction' => $direction,
                'category' => (string) $categoryResult['key'],
                'amount' => $amount,
                'description' => trim((string) ($input['description'] ?? '')),
                'payment_method' => $method,
                'member_id' => $memberId,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function buildSearchWhere(string $query, array &$params): string
    {
        $like = '%' . $this->escapeLike($query) . '%';
        $params['like_desc'] = $like;
        $params['like_category'] = $like;
        $params['like_method'] = $like;
        $parts = [
            'm.description LIKE :like_desc',
            'm.category LIKE :like_category',
            'm.payment_method LIKE :like_method',
        ];

        $boolean = $this->toBooleanFulltext($query);
        if ($boolean !== '') {
            $params['ft'] = $boolean;
            $parts[] = 'MATCH(m.description, m.category, m.payment_method) AGAINST (:ft IN BOOLEAN MODE)';
        }

        $needle = mb_strtolower($query);
        $catKeys = [];
        foreach ($this->categoryOptions() as $opt) {
            $label = mb_strtolower((string) $opt['label']);
            $key = mb_strtolower((string) $opt['key']);
            if (str_contains($label, $needle) || str_contains($key, $needle) || str_contains($needle, $key)) {
                $catKeys[] = (string) $opt['key'];
            }
        }
        if ($catKeys !== []) {
            $in = [];
            foreach (array_values(array_unique($catKeys)) as $i => $key) {
                $param = 'cat_' . $i;
                $params[$param] = $key;
                $in[] = ':' . $param;
            }
            $parts[] = 'm.category IN (' . implode(', ', $in) . ')';
        }

        foreach (['income' => 'treasury.direction_income', 'expense' => 'treasury.direction_expense'] as $dir => $labelKey) {
            $label = mb_strtolower(__($labelKey));
            if (str_contains($label, $needle) || str_contains($needle, $dir)) {
                $param = 'dir_' . $dir;
                $params[$param] = $dir;
                $parts[] = 'm.direction = :' . $param;
            }
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    private function toBooleanFulltext(string $query): string
    {
        $tokens = preg_split('/\s+/u', $query) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            $token = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $token) ?? '';
            if ($token === '' || mb_strlen($token) < 2) {
                continue;
            }
            $out[] = '+' . $token . '*';
        }
        return implode(' ', $out);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array{ok:bool,key?:string,error?:string}
     */
    private function resolveCategory(array $input, ?array $existing = null): array
    {
        $selected = trim((string) ($input['category'] ?? ''));
        if ($selected === '__new__') {
            $label = trim((string) ($input['new_category'] ?? ''));
            if ($label === '') {
                return ['ok' => false, 'error' => __('treasury.category_new_required')];
            }
            if (mb_strlen($label) > 80) {
                return ['ok' => false, 'error' => __('validation.max_string', ['max' => '80'])];
            }
            $slug = $this->slugifyCategory($label);
            if ($slug === '' || in_array($slug, self::BUILTIN_CATEGORIES, true) || $slug === '__new__') {
                return ['ok' => false, 'error' => __('treasury.category_new_invalid')];
            }
            $this->ensureCustomCategory($slug, $label);
            return ['ok' => true, 'key' => $slug];
        }

        $valid = array_column($this->categoryOptions(), 'key');
        $current = trim((string) ($existing['category'] ?? ''));
        if ($current !== '' && !in_array($current, $valid, true)) {
            $valid[] = $current;
        }
        if ($selected === '' || !in_array($selected, $valid, true)) {
            return ['ok' => false, 'error' => __('validation.in')];
        }
        return ['ok' => true, 'key' => $selected];
    }

    /** @return list<array{slug:string,label:string}> */
    private function customCategories(): array
    {
        $cfg = $this->components->config('treasury', []);
        $raw = $cfg['custom_categories'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = $this->slugifyCategory((string) ($row['slug'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            if ($slug === '' || $label === '' || isset($seen[$slug]) || in_array($slug, self::BUILTIN_CATEGORIES, true)) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = ['slug' => $slug, 'label' => mb_substr($label, 0, 80)];
        }
        return $out;
    }

    private function ensureCustomCategory(string $slug, string $label): void
    {
        $cfg = $this->components->config('treasury', ['auto_from_payments' => true, 'default_category' => 'membership_fee']);
        $list = $this->customCategories();
        foreach ($list as $row) {
            if ($row['slug'] === $slug) {
                return;
            }
        }
        $list[] = ['slug' => $slug, 'label' => mb_substr(trim($label), 0, 80)];
        $cfg['custom_categories'] = $list;
        $this->components->saveConfig('treasury', $cfg);
    }

    private function slugifyCategory(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($trans) && $trans !== '') {
                $value = $trans;
            }
        }
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');
        return mb_substr($value, 0, 60);
    }

    private function humanizeSlug(string $slug): string
    {
        $slug = str_replace('_', ' ', trim($slug));
        return $slug !== '' ? mb_convert_case($slug, MB_CASE_TITLE, 'UTF-8') : __('treasury.category_other');
    }
}
