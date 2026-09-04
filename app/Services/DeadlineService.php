<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Validator;

final class DeadlineService
{
    /** @var list<string> */
    public const BUILTIN_CATEGORIES = [
        'membership',
        'certificate',
        'assembly',
        'mandate',
        'general',
    ];

    /** @var list<string> */
    public const STATUSES = ['open', 'done', 'dismissed'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly Validator $validator,
        private readonly ComponentService $components
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function upcoming(int $limit = 200, string $query = '', bool $openOnly = true, string $bucket = '', ?string $untilDate = null): array
    {
        $this->syncSystemDeadlines();
        $limitSql = ' LIMIT ' . max(1, min(500, $limit));
        $base = "SELECT d.*,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions dfn ON dfn.id = v.field_definition_id AND dfn.`key` = 'first_name'
                     WHERE v.member_id = m.id LIMIT 1) AS first_name,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions dln ON dln.id = v.field_definition_id AND dln.`key` = 'last_name'
                     WHERE v.member_id = m.id LIMIT 1) AS last_name,
                    m.member_number
             FROM deadline_items d
             LEFT JOIN members m ON m.id = d.member_id";

        $where = [];
        $params = [];
        $bucket = trim($bucket);
        if ($bucket === 'done') {
            $where[] = "d.status = 'done'";
        } elseif ($openOnly) {
            $where[] = "d.status = 'open'";
        }
        $query = trim($query);
        if ($query !== '') {
            $where[] = $this->buildSearchWhere($query, $params);
        }
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+30 days'));
        if ($bucket === 'overdue') {
            $where[] = 'd.due_date < :bucket_today';
            $params['bucket_today'] = $today;
        } elseif ($bucket === 'soon') {
            $where[] = 'd.due_date >= :bucket_today AND d.due_date <= :bucket_soon';
            $params['bucket_today'] = $today;
            $params['bucket_soon'] = $soon;
        } elseif ($bucket === 'open') {
            // "Aperte" = tutte le scadenze aperte (nessun filtro data).
        }
        if ($untilDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $untilDate)) {
            $where[] = 'd.due_date <= :until_date';
            $params['until_date'] = $untilDate;
        }
        $sql = $base;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY d.due_date ASC, d.id ASC' . $limitSql;
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return list<array{key:string,label:string,items:list<array<string,mixed>>}>
     */
    public function groupedByCategory(int $limit = 200, string $query = ''): array
    {
        $items = $this->upcoming($limit, $query, true);
        $labels = $this->categoryMap();
        $buckets = [];
        foreach ($items as $item) {
            $key = trim((string) ($item['category'] ?? 'general'));
            if ($key === '') {
                $key = 'general';
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

    public function syncSystemDeadlines(): void
    {
        $sources = [];
        $periods = $this->db->fetchAll(
            'SELECT id, label, ends_on FROM membership_periods WHERE ends_on IS NOT NULL'
        );
        foreach ($periods as $period) {
            $source = 'system:membership_period:' . (int) $period['id'];
            $sources[] = $source;
            $this->upsertSystemDeadline($source, [
                'title' => __('deadlines.auto_membership_period', [
                    'label' => (string) ($period['label'] ?? ''),
                ]),
                'category' => 'membership',
                'due_date' => (string) $period['ends_on'],
                'member_id' => null,
                'notes' => __('deadlines.auto_generated_note'),
            ]);
        }

        $people = $this->db->fetchAll(
            'SELECT p.id, p.first_name, p.last_name, p.mandate_ends_at, p.member_id,
                    r.label_key, r.custom_label
             FROM association_people p
             INNER JOIN association_roles r ON r.`key` = p.role_key
             WHERE p.is_active = 1 AND p.mandate_ends_at IS NOT NULL'
        );
        foreach ($people as $person) {
            $source = 'system:mandate:' . (int) $person['id'];
            $sources[] = $source;
            $role = trim((string) ($person['custom_label'] ?? ''));
            if ($role === '') {
                $role = __((string) ($person['label_key'] ?? ''));
            }
            $memberId = (int) ($person['member_id'] ?? 0);
            $this->upsertSystemDeadline($source, [
                'title' => __('deadlines.auto_mandate', [
                    'name' => trim((string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? '')),
                    'role' => $role,
                ]),
                'category' => 'mandate',
                'due_date' => (string) $person['mandate_ends_at'],
                'member_id' => $memberId > 0 ? $memberId : null,
                'notes' => __('deadlines.auto_mandate_note'),
            ]);
        }

        $params = [];
        $keep = [];
        foreach ($sources as $i => $source) {
            $key = 'source_' . $i;
            $params[$key] = $source;
            $keep[] = ':' . $key;
        }
        $sql = "DELETE FROM deadline_items WHERE source LIKE 'system:%'";
        if ($keep !== []) {
            $sql .= ' AND source NOT IN (' . implode(', ', $keep) . ')';
        }
        $this->db->query($sql, $params);
    }

    public function isSystem(array $item): bool
    {
        return str_starts_with((string) ($item['source'] ?? ''), 'system:');
    }

    /** @return array{overdue:int,due_soon:int,open:int} */
    public function counts(): array
    {
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+30 days'));
        $row = $this->db->fetch(
            'SELECT
                SUM(CASE WHEN status = \'open\' AND due_date < :today_a THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN status = \'open\' AND due_date >= :today_b AND due_date <= :soon THEN 1 ELSE 0 END) AS due_soon,
                SUM(CASE WHEN status = \'open\' THEN 1 ELSE 0 END) AS open_count
             FROM deadline_items',
            ['today_a' => $today, 'today_b' => $today, 'soon' => $soon]
        );
        return [
            'overdue' => (int) ($row['overdue'] ?? 0),
            'due_soon' => (int) ($row['due_soon'] ?? 0),
            'open' => (int) ($row['open_count'] ?? 0),
        ];
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
                'label' => __('deadlines.category_' . $key),
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
            $key = 'general';
        }
        $map = $this->categoryMap();
        return $map[$key] ?? $this->humanizeSlug($key);
    }

    public function defaultCategory(): string
    {
        $cfg = $this->components->config('deadlines', ['default_category' => 'general']);
        $key = trim((string) ($cfg['default_category'] ?? 'general'));
        $valid = array_column($this->categoryOptions(), 'key');
        return in_array($key, $valid, true) ? $key : 'general';
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        return $this->db->fetch(
            "SELECT d.*,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions dfn ON dfn.id = v.field_definition_id AND dfn.`key` = 'first_name'
                     WHERE v.member_id = m.id LIMIT 1) AS first_name,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions dln ON dln.id = v.field_definition_id AND dln.`key` = 'last_name'
                     WHERE v.member_id = m.id LIMIT 1) AS last_name,
                    m.member_number
             FROM deadline_items d
             LEFT JOIN members m ON m.id = d.member_id
             WHERE d.id = :id",
            ['id' => $id]
        );
    }

    /** @param array<string,mixed> $input */
    public function create(array $input, string $ip): array
    {
        $parsed = $this->parseInput($input, null);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'errors' => $parsed['errors'] ?? ['title' => __('validation.required')]];
        }
        /** @var array<string,mixed> $data */
        $data = $parsed['data'];
        $data['source'] = 'manual';
        $id = $this->db->insert('deadline_items', $data);
        $this->audit->log('deadline.created', 'deadline', (string) $id, null, [
            'title' => $data['title'],
            'category' => $data['category'],
            'due_date' => $data['due_date'],
        ], $ip);
        return ['ok' => true, 'id' => $id];
    }

    /** @param array<string,mixed> $input */
    public function update(int $id, array $input, string $ip): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return ['ok' => false, 'errors' => ['id' => __('errors.404')]];
        }
        if ($this->isSystem($existing)) {
            return ['ok' => false, 'errors' => ['id' => __('deadlines.system_readonly')]];
        }
        $parsed = $this->parseInput($input, $existing);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'errors' => $parsed['errors'] ?? ['title' => __('validation.required')]];
        }
        /** @var array<string,mixed> $data */
        $data = $parsed['data'];
        $this->db->update('deadline_items', $data, 'id = :id', ['id' => $id]);
        $this->audit->log('deadline.updated', 'deadline', (string) $id, [
            'title' => $existing['title'] ?? null,
            'category' => $existing['category'] ?? null,
            'due_date' => $existing['due_date'] ?? null,
            'status' => $existing['status'] ?? null,
        ], [
            'title' => $data['title'],
            'category' => $data['category'],
            'due_date' => $data['due_date'],
            'status' => $data['status'],
        ], $ip);
        return ['ok' => true, 'id' => $id];
    }

    public function markDone(int $id, string $ip): void
    {
        $existing = $this->find($id);
        if ($existing === null || $this->isSystem($existing)) {
            return;
        }
        $this->db->update('deadline_items', ['status' => 'done'], 'id = :id', ['id' => $id]);
        $this->audit->log('deadline.done', 'deadline', (string) $id, null, null, $ip);
    }

    /**
     * Mark current deadline done and create a copy one year later.
     *
     * @return array{ok:bool,id?:int,error?:string}
     */
    public function renewPlusYear(int $id, string $ip): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return ['ok' => false, 'error' => 'missing'];
        }
        if ($this->isSystem($existing)) {
            return ['ok' => false, 'error' => 'system'];
        }
        $due = trim((string) ($existing['due_date'] ?? ''));
        if ($due === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            return ['ok' => false, 'error' => 'date'];
        }
        $nextDue = date('Y-m-d', strtotime($due . ' +1 year'));
        $this->db->update('deadline_items', ['status' => 'done'], 'id = :id', ['id' => $id]);
        $newId = $this->db->insert('deadline_items', [
            'title' => (string) ($existing['title'] ?? ''),
            'category' => (string) ($existing['category'] ?? 'general'),
            'due_date' => $nextDue,
            'member_id' => $existing['member_id'] !== null ? (int) $existing['member_id'] : null,
            'notes' => (string) ($existing['notes'] ?? ''),
            'status' => 'open',
            'source' => 'manual',
        ]);
        $this->audit->log('deadline.renewed', 'deadline', (string) $id, [
            'due_date' => $due,
            'status' => $existing['status'] ?? null,
        ], [
            'new_id' => $newId,
            'due_date' => $nextDue,
        ], $ip);
        return ['ok' => true, 'id' => (int) $newId];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array{ok:bool,errors?:array<string,string>,data?:array<string,mixed>}
     */
    private function parseInput(array $input, ?array $existing): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'errors' => ['title' => __('validation.required')]];
        }
        $due = trim((string) ($input['due_date'] ?? ''));
        if (!$this->validator->validate(['due_date' => $due], ['due_date' => 'required|date'])) {
            return ['ok' => false, 'errors' => ['due_date' => __('validation.date')]];
        }

        $categoryResult = $this->resolveCategory($input, $existing);
        if (empty($categoryResult['ok'])) {
            return ['ok' => false, 'errors' => ['category' => (string) ($categoryResult['error'] ?? __('validation.required'))]];
        }

        $memberId = trim((string) ($input['member_id'] ?? ''));
        $status = trim((string) ($input['status'] ?? ($existing['status'] ?? 'open')));
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'open';
        }

        return [
            'ok' => true,
            'data' => [
                'title' => $title,
                'category' => (string) $categoryResult['key'],
                'due_date' => $due,
                'member_id' => $memberId !== '' ? (int) $memberId : null,
                'notes' => trim((string) ($input['notes'] ?? '')),
                'status' => $status,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function buildSearchWhere(string $query, array &$params): string
    {
        $like = '%' . $this->escapeLike($query) . '%';
        $params['like_title'] = $like;
        $params['like_notes'] = $like;
        $params['like_category'] = $like;
        $parts = [
            'd.title LIKE :like_title',
            'd.notes LIKE :like_notes',
            'd.category LIKE :like_category',
        ];

        $boolean = $this->toBooleanFulltext($query);
        if ($boolean !== '') {
            $params['ft'] = $boolean;
            $parts[] = 'MATCH(d.title, d.notes, d.category) AGAINST (:ft IN BOOLEAN MODE)';
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
            $parts[] = 'd.category IN (' . implode(', ', $in) . ')';
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
                return ['ok' => false, 'error' => __('deadlines.category_new_required')];
            }
            if (mb_strlen($label) > 80) {
                return ['ok' => false, 'error' => __('validation.max_string', ['max' => '80'])];
            }
            $slug = $this->slugifyCategory($label);
            if ($slug === '' || in_array($slug, self::BUILTIN_CATEGORIES, true) || $slug === '__new__') {
                return ['ok' => false, 'error' => __('deadlines.category_new_invalid')];
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
        $cfg = $this->components->config('deadlines', []);
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
        $cfg = $this->components->config('deadlines', ['warn_days' => 30, 'default_category' => 'general']);
        $list = $this->customCategories();
        foreach ($list as $row) {
            if ($row['slug'] === $slug) {
                return;
            }
        }
        $list[] = ['slug' => $slug, 'label' => mb_substr(sentence_case($label), 0, 80)];
        $cfg['custom_categories'] = $list;
        $this->components->saveConfig('deadlines', $cfg);
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
        return $slug !== '' ? mb_convert_case($slug, MB_CASE_TITLE, 'UTF-8') : __('deadlines.category_general');
    }

    /** @param array<string,mixed> $data */
    private function upsertSystemDeadline(string $source, array $data): void
    {
        $existing = $this->db->fetch(
            'SELECT id, title, category, due_date, member_id, notes, status, source
             FROM deadline_items WHERE source = :source ORDER BY id ASC LIMIT 1',
            ['source' => $source]
        );
        $data['source'] = $source;
        $data['status'] = 'open';
        if ($existing === null) {
            $this->db->insert('deadline_items', $data);
            return;
        }
        $changed = false;
        foreach ($data as $key => $value) {
            if ((string) ($existing[$key] ?? '') !== (string) ($value ?? '')) {
                $changed = true;
                break;
            }
        }
        if ($changed) {
            $this->db->update('deadline_items', $data, 'id = :id', ['id' => (int) $existing['id']]);
        }
        $this->db->query(
            'DELETE FROM deadline_items WHERE source = :source AND id <> :id',
            ['source' => $source, 'id' => (int) $existing['id']]
        );
    }
}
