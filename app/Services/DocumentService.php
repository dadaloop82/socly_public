<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */


declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Validator;
use Socly\Support\PlausibleDate;

final class DocumentService
{
    /** @var list<string> */
    public const BUILTIN_CATEGORIES = [
        'minutes',
        'board_minutes',
        'members_register',
        'statute',
        'constitutive_act',
        'runts_cert',
        'balance',
        'statement_excerpt',
        'five_per_thousand',
        'receipt',
        'f24',
        'invoice',
        'contract',
        'insurance',
        'lease',
        'dvr',
        'safeguarding',
        'regulation',
        'other',
    ];

    /** @var array<string, list<string>> */
    public const CATEGORY_GROUPS = [
        'organs' => ['minutes', 'board_minutes', 'audit_minutes', 'members_register'],
        'legal' => ['statute', 'constitutive_act', 'runts_cert', 'regulation'],
        'finance' => ['balance', 'statement_excerpt', 'five_per_thousand', 'receipt', 'f24', 'invoice'],
        'contracts' => ['contract', 'insurance', 'lease', 'dvr', 'safeguarding'],
        'other' => ['other'],
    ];

    /**
     * Mandatory corporate books (art. 15 CTS): the minutes of the assembly, board of directors and
     * supervisory body.
     *
     * @var list<string>
     */
    public const MINUTES_CATEGORIES = ['minutes', 'board_minutes', 'audit_minutes'];

    /** @var list<string> */
    public const VISIBILITIES = ['reserved', 'internal', 'public'];

    /** @var list<string> */
    public const STATUSES = ['draft', 'pending_approval', 'approved', 'signed', 'archived', 'cancelled'];

    /** @var list<string> */
    public const LANGUAGES = [
        'it',
        'de',
        'en',
        'multilingual',
        'other',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly Validator $validator,
        private readonly ComponentService $components,
        private readonly FileRepositoryService $files
    ) {
    }

    /** @return list<array<string,mixed>> */
    /**
     * @param array{status?:string,category?:string,expiring?:bool} $filters
     */
    public function all(int $limit = 5000, string $query = '', array $filters = []): array
    {
        $query = trim($query);
        // Archive must surface every year: bank-statement excerpts from the current year
        // used to fill a tight LIMIT and hide older verbali / statute files.
        $limitSql = ' LIMIT ' . max(1, min(10000, $limit));
        $effectiveYear = $this->effectiveYearSql('d');
        $base = 'SELECT d.*, u.name AS author_name
             FROM association_documents d
             LEFT JOIN users u ON u.id = d.created_by
             WHERE d.deleted_at IS NULL';

        $where = [];
        $params = [];
        if ($query !== '') {
            $where[] = $this->buildSearchWhere($query, $params);
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'd.status = :filter_status';
            $params['filter_status'] = $status;
        }
        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $where[] = 'd.category = :filter_category';
            $params['filter_category'] = $category;
        } else {
            // Bank-statement crops are evidence attached to a movement, not archive documents:
            // keep them out of the default list so hundreds of crops never hide verbali,
            // statuto and the other real documents. Pick the category explicitly to browse them.
            $where[] = "d.category <> 'statement_excerpt'";
        }
        $section = trim((string) ($filters['section'] ?? ''));
        if ($section !== '') {
            $where[] = 'd.source_section = :filter_section';
            $params['filter_section'] = $section;
        }
        $year = (int) ($filters['year'] ?? 0);
        if ($year > 0) {
            $where[] = $effectiveYear . ' = :filter_year';
            $params['filter_year'] = $year;
        }
        $uploader = trim((string) ($filters['uploader'] ?? ''));
        if ($uploader !== '') {
            $where[] = 'd.uploaded_by_name LIKE :filter_uploader';
            $params['filter_uploader'] = '%' . $this->escapeLike($uploader) . '%';
        }
        if (!empty($filters['expiring'])) {
            $where[] = 'd.expires_at IS NOT NULL AND d.expires_at >= :expires_from AND d.expires_at <= :expires_to';
            $params['expires_from'] = date('Y-m-d');
            $params['expires_to'] = date('Y-m-d', strtotime('+30 days'));
        }
        $sql = $base;
        if ($where !== []) {
            $sql .= ' AND ' . implode(' AND ', $where);
        }
        // Official books/docs before statement excerpts so older years are never truncated
        // away by hundreds of current-year bank PDFs; then newest reference period first.
        $sql .= ' ORDER BY CASE WHEN d.category = \'statement_excerpt\' THEN 1 ELSE 0 END ASC,
                         ' . $effectiveYear . ' DESC,
                         COALESCE(d.reference_month_to, d.reference_month_from, MONTH(COALESCE(d.document_date, d.created_at)), 0) DESC,
                         COALESCE(d.document_date, d.created_at) DESC,
                         d.id DESC' . $limitSql;
        return $this->db->fetchAll($sql, $params);
    }

    /** SQL expression: declared reference year, else year of document/upload date. */
    private function effectiveYearSql(string $alias = 'd'): string
    {
        $a = preg_replace('/[^a-z_]/', '', $alias) ?: 'd';

        return 'COALESCE(' . $a . '.reference_year, YEAR(COALESCE(' . $a . '.document_date, ' . $a . '.created_at)))';
    }

    /**
     * Company books of the current year: how many minutes there are per category and when the last
     * one was uploaded (for the "no minutes this year" warning).
     *
     * @return list<array{key:string,label:string,total:int,current_year:int,year:int,last_date:?string}>
     */
    public function minutesOverview(): array
    {
        $year = (int) date('Y');
        $effectiveYear = $this->effectiveYearSql('d');
        $out = [];
        foreach (self::MINUTES_CATEGORIES as $key) {
            $row = $this->db->fetch(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN ' . $effectiveYear . ' = :year THEN 1 ELSE 0 END) AS current_year,
                        MAX(COALESCE(d.document_date, d.created_at)) AS last_date
                 FROM association_documents d
                 WHERE d.deleted_at IS NULL AND d.category = :category',
                ['year' => $year, 'category' => $key]
            );
            $lastDate = trim((string) ($row['last_date'] ?? ''));
            $out[] = [
                'key' => $key,
                'label' => (string) __('documents.category_' . $key),
                'total' => (int) ($row['total'] ?? 0),
                'current_year' => (int) ($row['current_year'] ?? 0),
                'year' => $year,
                'last_date' => $lastDate !== '' ? $lastDate : null,
            ];
        }
        return $out;
    }

    /**
     * Suggested numbers for the minutes: progressive number of the current year by category.
     *
     * @return array<string,int> category => next progressive number
     */
    public function minutesProgressives(): array
    {
        $out = [];
        foreach (self::MINUTES_CATEGORIES as $key) {
            $row = $this->db->fetch(
                'SELECT COUNT(*) AS c FROM association_documents
                 WHERE category = :category AND document_date IS NOT NULL AND YEAR(document_date) = :year',
                ['category' => $key, 'year' => (int) date('Y')]
            );
            $out[$key] = (int) ($row['c'] ?? 0) + 1;
        }
        return $out;
    }

    /** @return array{total:int,draft:int,approved:int,expiring:int} */
    public function counts(): array
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = \'draft\' THEN 1 ELSE 0 END) AS draft,
                    SUM(CASE WHEN status IN (\'approved\', \'signed\') THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at >= :expires_from AND expires_at <= :expires_to THEN 1 ELSE 0 END) AS expiring
             FROM association_documents
             WHERE deleted_at IS NULL
               AND category <> \'statement_excerpt\'',
            [
                'expires_from' => date('Y-m-d'),
                'expires_to' => date('Y-m-d', strtotime('+30 days')),
            ]
        );
        return [
            'total' => (int) ($row['total'] ?? 0),
            'draft' => (int) ($row['draft'] ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'expiring' => (int) ($row['expiring'] ?? 0),
        ];
    }

    public function countAll(): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS c
               FROM association_documents
              WHERE deleted_at IS NULL
                AND category <> \'statement_excerpt\''
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Documents grouped by category key, preserving category order.
     *
     * @return list<array{key:string,label:string,items:list<array<string,mixed>>}>
     */
    public function groupedByCategory(int $limit = 200, string $query = ''): array
    {
        $docs = $this->all($limit, $query);
        $labels = $this->categoryMap();
        $buckets = [];
        foreach ($docs as $doc) {
            $key = trim((string) ($doc['category'] ?? 'other'));
            if ($key === '') {
                $key = 'other';
            }
            if (!isset($buckets[$key])) {
                $buckets[$key] = [];
            }
            $buckets[$key][] = $doc;
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
        // Orphan categories still present on old rows.
        ksort($buckets);
        foreach ($buckets as $key => $items) {
            $ordered[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $this->humanizeSlug($key),
                'items' => $items,
            ];
        }
        return $ordered;
    }

    /**
     * Build WHERE clause for archive search (FULLTEXT + LIKE + category/language labels).
     *
     * @param array<string,mixed> $params
     */
    private function buildSearchWhere(string $query, array &$params): string
    {
        $like = '%' . $this->escapeLike($query) . '%';
        $params['like_title'] = $like;
        $params['like_number'] = $like;
        $params['like_summary'] = $like;
        $params['like_category'] = $like;
        $params['like_language'] = $like;

        $parts = [
            'd.title LIKE :like_title',
            'd.document_number LIKE :like_number',
            'd.summary LIKE :like_summary',
            'd.category LIKE :like_category',
            'd.language LIKE :like_language',
        ];

        $boolean = $this->toBooleanFulltext($query);
        if ($boolean !== '') {
            $params['ft'] = $boolean;
            $parts[] = 'MATCH(d.title, d.summary, d.category, d.language) AGAINST (:ft IN BOOLEAN MODE)';
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

        $langKeys = [];
        foreach (self::LANGUAGES as $lang) {
            $label = mb_strtolower(__('documents.language_' . $lang));
            if (str_contains($label, $needle) || str_contains($lang, $needle) || str_contains($needle, $lang)) {
                $langKeys[] = $lang;
            }
        }
        if ($langKeys !== []) {
            $in = [];
            foreach (array_values(array_unique($langKeys)) as $i => $key) {
                $param = 'lang_' . $i;
                $params[$param] = $key;
                $in[] = ':' . $param;
            }
            $parts[] = 'd.language IN (' . implode(', ', $in) . ')';
        }

        $statusKeys = [];
        foreach (['draft', 'approved', 'signed'] as $status) {
            $label = mb_strtolower(__('documents.status_' . $status));
            if (str_contains($label, $needle) || str_contains($status, $needle)) {
                $statusKeys[] = $status;
            }
        }
        if ($statusKeys !== []) {
            $in = [];
            foreach (array_values(array_unique($statusKeys)) as $i => $key) {
                $param = 'st_' . $i;
                $params[$param] = $key;
                $in[] = ':' . $param;
            }
            $parts[] = 'd.status IN (' . implode(', ', $in) . ')';
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
            // Prefix match helps partial Italian/German terms; + requires presence.
            $out[] = '+' . $token . '*';
        }
        return implode(' ', $out);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return list<array{key:string,label:string,builtin:bool,group?:string}>
     */
    public function categoryOptions(): array
    {
        $options = [];
        foreach (self::CATEGORY_GROUPS as $groupKey => $keys) {
            foreach ($keys as $key) {
                $options[] = [
                    'key' => $key,
                    'label' => __('documents.category_' . $key),
                    'builtin' => true,
                    'group' => $groupKey,
                ];
            }
        }
        foreach ($this->customCategories() as $row) {
            $options[] = [
                'key' => (string) $row['slug'],
                'label' => (string) $row['label'],
                'builtin' => false,
                'group' => 'custom',
            ];
        }
        return $options;
    }

    /**
     * @return list<array{key:string,label:string,options:list<array{key:string,label:string}>}>
     */
    public function categoryGroupedOptions(): array
    {
        $groups = [];
        foreach ($this->categoryOptions() as $opt) {
            $g = (string) ($opt['group'] ?? 'other');
            if (!isset($groups[$g])) {
                $groups[$g] = [
                    'key' => $g,
                    'label' => __('documents.category_group_' . $g),
                    'options' => [],
                ];
            }
            $groups[$g]['options'][] = [
                'key' => (string) $opt['key'],
                'label' => (string) $opt['label'],
            ];
        }
        return array_values($groups);
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
        $cfg = $this->components->config('documents', ['default_category' => 'minutes']);
        $key = trim((string) ($cfg['default_category'] ?? 'minutes'));
        $valid = array_column($this->categoryOptions(), 'key');
        return in_array($key, $valid, true) ? $key : 'minutes';
    }

    /**
     * Immediate upload used by the document form (AJAX). The bytes land in the central
     * repository folder; the document row is written when the form is submitted.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{ok:bool,error?:string,path?:string,mime?:string,name?:string,hash?:string}
     */
    public function upload(array $file, array $context = []): array
    {
        $stored = $this->files->stageUpload($file, $context + [
            'section' => 'documents',
            'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
        ], '');
        if (empty($stored['ok'])) {
            return ['ok' => false, 'error' => (string) ($stored['error'] ?? __('documents.upload_fail'))];
        }
        return [
            'ok' => true,
            'path' => (string) $stored['path'],
            'mime' => (string) $stored['mime'],
            'name' => (string) ($file['name'] ?? basename((string) $stored['path'])),
            'hash' => (string) ($stored['hash'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $input */
    public function create(array $input, ?array $file, string $ip, ?int $userId = null): array
    {
        // Linking an existing repository file: prefill the title so the form can be submitted
        // without retyping the metadata of a document that is already archived.
        $reuseId = (int) ($input['reuse_document_id'] ?? 0);
        if ($reuseId > 0 && trim((string) ($input['title'] ?? '')) === '') {
            $reuseRow = $this->files->fileInfo($reuseId);
            if ($reuseRow !== null) {
                $input['title'] = (string) ($reuseRow['title'] ?? '');
                if (trim((string) ($input['category'] ?? '')) === '') {
                    $input['category'] = (string) ($reuseRow['category'] ?? 'other');
                }
            }
        }
        $parsed = $this->parseDocumentInput($input, $file, null);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'errors' => $parsed['errors'] ?? ['title' => __('validation.required')]];
        }
        /** @var array<string,mixed> $data */
        $data = $parsed['data'];
        $data['created_by'] = $userId;
        $actor = auth_user();
        $data['uploaded_by_type'] = 'operator';
        $data['uploaded_by_id'] = $userId ?? (int) ($actor['id'] ?? 0) ?: null;
        $data['uploaded_by_name'] = is_array($actor)
            ? mb_substr((string) ($actor['name'] ?? ($actor['email'] ?? '')), 0, 190)
            : null;
        $data['uploaded_ip'] = $ip !== '' ? mb_substr($ip, 0, 45) : null;

        // “Choose an already uploaded document”: reuse the repository file instead of a copy.
        $reuseId = (int) ($input['reuse_document_id'] ?? 0);
        if ($reuseId > 0 && trim((string) ($data['file_path'] ?? '')) === '') {
            $reused = $this->files->pick($reuseId);
            if ($reused === null) {
                return ['ok' => false, 'errors' => ['reuse_document_id' => __('documents.upload_missing')]];
            }
            $this->files->link($reuseId, (string) ($data['source_section'] ?? 'documents'), (string) ($data['source_ref'] ?? ''), [
                'actor_type' => 'operator',
                'actor_id' => $userId,
            ]);

            return ['ok' => true, 'id' => $reuseId, 'reused' => true];
        }

        // One single physical file in the repository: the same bytes are never archived twice.
        $hash = trim((string) ($data['content_hash'] ?? ''));
        if ($hash !== '') {
            $existing = $this->files->findByHash($hash);
            if ($existing !== null) {
                // The file is already archived: accept the upload but do not create a second
                // document. Drop a copy staged under another year folder, if any.
                $stagedPath = trim((string) ($data['file_path'] ?? ''));
                $existingPath = trim((string) ($existing['file_path'] ?? ''));
                if ($stagedPath !== '' && $stagedPath !== $existingPath && str_contains($stagedPath, '/repository/')) {
                    $stagedAbsolute = resolve_upload_absolute_path($stagedPath);
                    if ($stagedAbsolute !== null) {
                        @unlink($stagedAbsolute);
                    }
                }
                $this->files->link((int) $existing['id'], (string) ($data['source_section'] ?? 'documents'), (string) ($data['source_ref'] ?? ''), [
                    'actor_type' => 'operator',
                    'actor_id' => $userId,
                ]);

                return ['ok' => true, 'id' => (int) $existing['id'], 'reused' => true];
            }
        }

        if (trim((string) ($data['document_number'] ?? '')) === '') {
            $data['document_number'] = $this->suggestProtocolNumber((string) $data['category'], $data['document_date'] ?? null);
        }
        $id = $this->db->insert('association_documents', $data);
        $this->files->link((int) $id, (string) ($data['source_section'] ?? 'documents'), (string) ($data['source_ref'] ?? ''), [
            'actor_type' => 'operator',
            'actor_id' => $userId,
        ]);
        $this->syncExpiryDeadline((int) $id, $data, $ip);
        $this->audit->log('document.created', 'document', (string) $id, null, [
            'title' => $data['title'],
            'document_number' => $data['document_number'],
            'category' => $data['category'],
            'language' => $data['language'],
            'source_section' => $data['source_section'] ?? 'documents',
            'content_hash' => $data['content_hash'] ?? null,
        ], $ip);
        return ['ok' => true, 'id' => $id];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        return $this->db->fetch(
            'SELECT d.*, u.name AS author_name
             FROM association_documents d
             LEFT JOIN users u ON u.id = d.created_by
             WHERE d.id = :id AND d.deleted_at IS NULL',
            ['id' => $id]
        );
    }

    /**
     * Structured snapshot of an archived minute (president, secretary, attendance, agenda and
     * outcomes). Null for an ordinary document or for minutes archived before the snapshot.
     *
     * @param array<string,mixed> $document
     * @return array<string,mixed>|null
     */
    public function minutesMeta(array $document): ?array
    {
        $raw = trim((string) ($document['minutes_meta'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Structured minutes arrive as a JSON string (or array) from the wizard: keep it only when it
     * decodes, never let the column receive garbage.
     */
    private function normalizeMinutesMeta(mixed $raw): ?string
    {
        if (is_array($raw)) {
            $raw = json_encode($raw, JSON_UNESCAPED_UNICODE);
        }
        $json = trim((string) $raw);
        if ($json === '' || strlen($json) > 60000) {
            return null;
        }

        return is_array(json_decode($json, true)) ? $json : null;
    }

    /** @param array<string,mixed> $input */
    public function update(int $id, array $input, ?array $file, string $ip): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return ['ok' => false, 'errors' => ['id' => __('errors.404')]];
        }
        $parsed = $this->parseDocumentInput($input, $file, $existing);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'errors' => $parsed['errors'] ?? ['title' => __('validation.required')]];
        }
        /** @var array<string,mixed> $data */
        $data = $parsed['data'];
        // Editing the document metadata must never erase the structured minute snapshot.
        if (!array_key_exists('minutes_meta', $input)) {
            unset($data['minutes_meta']);
        }
        $this->db->update('association_documents', $data, 'id = :id', ['id' => $id]);
        $this->syncExpiryDeadline($id, $data, $ip);
        $oldPath = trim((string) ($existing['file_path'] ?? ''));
        $newPath = trim((string) ($data['file_path'] ?? ''));
        if ($oldPath !== $newPath && $newPath !== '') {
            // The old bytes are never deleted here: in the central repository they can be used by
            // another section (treasury, forum, poll). Orphan cleanup runs from the archive page.
            $absolute = resolve_upload_absolute_path($newPath);
            if ($absolute !== null) {
                $hash = hash_file('sha256', $absolute);
                $patch = [
                    'content_hash' => is_string($hash) ? $hash : null,
                    'file_size' => (int) (@filesize($absolute) ?: 0),
                ];
                $this->db->update('association_documents', $patch, 'id = :id', ['id' => $id]);
                $data = array_merge($data, $patch);
            }
        }
        $this->audit->log('document.updated', 'document', (string) $id, [
            'title' => $existing['title'] ?? null,
            'document_number' => $existing['document_number'] ?? null,
            'category' => $existing['category'] ?? null,
            'language' => $existing['language'] ?? null,
        ], [
            'title' => $data['title'],
            'document_number' => $data['document_number'],
            'category' => $data['category'],
            'language' => $data['language'],
        ], $ip);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Soft-delete a draft document. Approved/signed/archived documents are historical records and
     * must never be removed here.
     *
     * @return array{ok:bool,errors?:array<string,string>}
     */
    public function deleteDraft(int $id, string $ip = ''): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return ['ok' => false, 'errors' => ['document' => __('errors.404')]];
        }
        if ((string) ($existing['status'] ?? '') !== 'draft') {
            return ['ok' => false, 'errors' => ['document' => __('documents.delete_only_draft')]];
        }

        $deleted = $this->db->query(
            'UPDATE association_documents
                SET deleted_at = :deleted_at
              WHERE id = :id AND status = \'draft\' AND deleted_at IS NULL',
            ['deleted_at' => date('Y-m-d H:i:s'), 'id' => $id]
        )->rowCount();
        if ($deleted < 1) {
            return ['ok' => false, 'errors' => ['document' => __('documents.delete_only_draft')]];
        }

        // The document's expiry reminder must not survive the document itself.
        $this->db->query(
            'DELETE FROM deadline_items WHERE source = :source',
            ['source' => 'system:document:' . $id]
        );
        $this->audit->log('document.deleted', 'document', (string) $id, [
            'title' => $existing['title'] ?? null,
            'document_number' => $existing['document_number'] ?? null,
            'category' => $existing['category'] ?? null,
            'status' => $existing['status'] ?? null,
            'source_section' => $existing['source_section'] ?? null,
        ], ['deleted' => true], $ip);

        return ['ok' => true];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array{ok:bool,errors?:array<string,string>,data?:array<string,mixed>}
     */
    private function parseDocumentInput(array $input, ?array $file, ?array $existing): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'errors' => ['title' => __('validation.required')]];
        }
        $docDate = trim((string) ($input['document_date'] ?? ''));
        if ($docDate !== '' && !$this->validator->validate(['document_date' => $docDate], ['document_date' => 'date'])) {
            return ['ok' => false, 'errors' => ['document_date' => __('validation.date')]];
        }
        if ($docDate !== '' && !PlausibleDate::isPlausible($docDate)) {
            return ['ok' => false, 'errors' => ['document_date' => __('validation.year_out_of_range', [
                'min' => (string) PlausibleDate::MIN_YEAR,
                'max' => (string) PlausibleDate::maxYear(),
            ])]];
        }

        $categoryResult = $this->resolveCategory($input, $existing);
        if (empty($categoryResult['ok'])) {
            return ['ok' => false, 'errors' => ['category' => (string) ($categoryResult['error'] ?? __('validation.required'))]];
        }
        $category = (string) $categoryResult['key'];

        $language = trim((string) ($input['language'] ?? ''));
        if ($language !== '' && !in_array($language, self::LANGUAGES, true)) {
            return ['ok' => false, 'errors' => ['language' => __('validation.in')]];
        }
        if ($language === '') {
            $language = null;
        }

        $status = (string) ($input['status'] ?? 'draft');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'draft';
        }

        $visibility = (string) ($input['visibility'] ?? 'internal');
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            $visibility = 'internal';
        }

        $expiresAt = trim((string) ($input['expires_at'] ?? ''));
        if ($expiresAt !== '' && !$this->validator->validate(['expires_at' => $expiresAt], ['expires_at' => 'date'])) {
            return ['ok' => false, 'errors' => ['expires_at' => __('validation.date')]];
        }
        if ($expiresAt !== '' && !PlausibleDate::isPlausible($expiresAt)) {
            return ['ok' => false, 'errors' => ['expires_at' => __('validation.year_out_of_range', [
                'min' => (string) PlausibleDate::MIN_YEAR,
                'max' => (string) PlausibleDate::maxYear(),
            ])]];
        }

        $memberId = trim((string) ($input['member_id'] ?? ''));
        $siblingId = trim((string) ($input['sibling_document_id'] ?? ''));

        $attachment = $this->resolveAttachment($input, $file, $existing);
        if (empty($attachment['ok'])) {
            return ['ok' => false, 'errors' => ['file' => (string) ($attachment['error'] ?? __('documents.upload_fail'))]];
        }

        $documentNumber = mb_substr(trim((string) ($input['document_number'] ?? '')), 0, 80);

        // Repository metadata: hash, size, original name, origin section and reference year.
        $contentHash = null;
        $fileSize = null;
        $storedPath = trim((string) ($attachment['path'] ?? ''));
        if ($storedPath !== '') {
            $absolute = resolve_upload_absolute_path($storedPath);
            if ($absolute !== null) {
                $hash = hash_file('sha256', $absolute);
                $contentHash = is_string($hash) ? $hash : null;
                $fileSize = (int) (@filesize($absolute) ?: 0);
            }
        }
        $originalName = trim((string) ($input['uploaded_name'] ?? ''));
        if ($originalName === '') {
            $originalName = (string) ($file['name'] ?? '');
        }
        if ($originalName === '' && $storedPath !== '') {
            $originalName = basename($storedPath);
        }
        $sourceSection = strtolower(trim((string) ($input['source_section'] ?? 'documents')));
        $sourceSection = preg_replace('/[^a-z0-9_]/', '_', $sourceSection) ?: 'documents';
        $sourceRef = mb_substr(trim((string) ($input['source_ref'] ?? '')), 0, 190);
        $referenceYear = $docDate !== '' ? (int) substr($docDate, 0, 4) : (int) date('Y');

        return [
            'ok' => true,
            'data' => [
                'title' => $title,
                'document_number' => $documentNumber,
                'category' => $category,
                'language' => $language,
                'document_date' => $docDate !== '' ? $docDate : null,
                'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                'file_path' => $attachment['path'],
                'file_mime' => $attachment['mime'],
                'summary' => trim((string) ($input['summary'] ?? '')),
                'status' => $status,
                'visibility' => $visibility,
                'member_id' => $memberId !== '' ? (int) $memberId : null,
                'sibling_document_id' => $siblingId !== '' ? (int) $siblingId : null,
                'content_hash' => $contentHash,
                'file_size' => $fileSize,
                'original_name' => $originalName !== '' ? mb_substr($originalName, 0, 255) : null,
                'source_section' => $sourceSection,
                'source_ref' => $sourceRef !== '' ? $sourceRef : null,
                'minutes_meta' => $this->normalizeMinutesMeta($input['minutes_meta'] ?? null),
                'reference_year' => max(1900, min(2100, $referenceYear)),
            ],
        ];
    }

    public function suggestProtocolNumber(string $category, ?string $documentDate = null): string
    {
        $year = date('Y');
        if ($documentDate !== null && preg_match('/^(\d{4})/', $documentDate, $m)) {
            $year = $m[1];
        }
        $prefixMap = [
            'minutes' => 'VERB',
            'board_minutes' => 'DELIB',
            'statute' => 'STAT',
            'invoice' => 'FATT',
            'contract' => 'CONT',
            'insurance' => 'POL',
        ];
        $prefix = $prefixMap[$category] ?? strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $category) ?: 'DOC', 0, 4));
        $like = $prefix . '-' . $year . '-%';
        $row = $this->db->fetch(
            'SELECT document_number FROM association_documents
             WHERE document_number LIKE :like
             ORDER BY id DESC LIMIT 1',
            ['like' => $like]
        );
        $seq = 1;
        if ($row && preg_match('/-(\d+)$/', (string) ($row['document_number'] ?? ''), $m)) {
            $seq = (int) $m[1] + 1;
        }
        return sprintf('%s-%s-%03d', $prefix, $year, $seq);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function syncExpiryDeadline(int $documentId, array $data, string $ip): void
    {
        $expires = trim((string) ($data['expires_at'] ?? ''));
        $source = 'system:document:' . $documentId;
        $existing = $this->db->fetch(
            'SELECT id FROM deadline_items WHERE source = :source LIMIT 1',
            ['source' => $source]
        );
        if ($expires === '') {
            if ($existing) {
                $this->db->query('DELETE FROM deadline_items WHERE id = :id', ['id' => (int) $existing['id']]);
            }
            return;
        }
        $payload = [
            'title' => (string) ($data['title'] ?? __('documents.title')),
            'category' => 'general',
            'due_date' => $expires,
            'member_id' => $data['member_id'] ?? null,
            'notes' => __('documents.deadline_from_doc'),
            'status' => 'open',
            'source' => $source,
        ];
        if ($existing) {
            $this->db->update('deadline_items', $payload, 'id = :id', ['id' => (int) $existing['id']]);
        } else {
            $this->db->insert('deadline_items', $payload);
            $this->audit->log('deadline.created', 'deadline', $source, null, $payload, $ip);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array{ok:bool,path:?string,mime:?string,error?:string}
     */
    private function resolveAttachment(array $input, ?array $file, ?array $existing): array
    {
        $uploadedPath = trim((string) ($input['uploaded_path'] ?? ''));
        $uploadedMime = trim((string) ($input['uploaded_mime'] ?? ''));

        if ($uploadedPath !== '' && $this->isSafeStoredPath($uploadedPath)) {
            $full = resolve_upload_absolute_path($uploadedPath);
            if ($full === null) {
                return ['ok' => false, 'path' => null, 'mime' => null, 'error' => __('documents.upload_missing')];
            }
            return [
                'ok' => true,
                'path' => $uploadedPath,
                'mime' => $uploadedMime !== '' ? $uploadedMime : (mime_content_type($full) ?: null),
            ];
        }

        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $refDate = trim((string) ($input['document_date'] ?? ''));
            $stored = $this->storeFile($file, $refDate !== '' ? $refDate : null);
            if (!$stored['ok']) {
                return [
                    'ok' => false,
                    'path' => null,
                    'mime' => null,
                    'error' => (string) ($stored['error'] ?? __('documents.upload_fail')),
                ];
            }
            return [
                'ok' => true,
                'path' => (string) $stored['path'],
                'mime' => (string) $stored['mime'],
            ];
        }

        if ($existing !== null) {
            $keepPath = trim((string) ($existing['file_path'] ?? ''));
            $keepMime = trim((string) ($existing['file_mime'] ?? ''));
            if ($keepPath !== '' && $this->isSafeStoredPath($keepPath) && resolve_upload_absolute_path($keepPath) !== null) {
                return [
                    'ok' => true,
                    'path' => $keepPath,
                    'mime' => $keepMime !== '' ? $keepMime : null,
                ];
            }
            return ['ok' => true, 'path' => null, 'mime' => null];
        }

        return ['ok' => true, 'path' => null, 'mime' => null];
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{ok:bool,error?:string,path?:string,mime?:string,hash?:string,size?:int,original_name?:string}
     */
    private function storeFile(array $file, ?string $referenceDate = null): array
    {
        $staged = $this->files->stageUpload($file, [
            'section' => 'documents',
            'date' => $referenceDate,
            'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
        ], '');
        if (empty($staged['ok'])) {
            return ['ok' => false, 'error' => (string) ($staged['error'] ?? __('documents.upload_fail'))];
        }

        return [
            'ok' => true,
            'path' => (string) $staged['path'],
            'mime' => (string) $staged['mime'],
            'hash' => (string) ($staged['hash'] ?? ''),
            'size' => (int) ($staged['size'] ?? 0),
            'original_name' => (string) ($staged['original_name'] ?? ''),
        ];
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
                return ['ok' => false, 'error' => __('documents.category_new_required')];
            }
            if (mb_strlen($label) > 80) {
                return ['ok' => false, 'error' => __('validation.max_string', ['max' => '80'])];
            }
            $slug = $this->slugifyCategory($label);
            if ($slug === '' || in_array($slug, self::BUILTIN_CATEGORIES, true) || $slug === '__new__') {
                return ['ok' => false, 'error' => __('documents.category_new_invalid')];
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
        $cfg = $this->components->config('documents', []);
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
        $cfg = $this->components->config('documents', ['default_category' => 'minutes']);
        $list = $this->customCategories();
        foreach ($list as $row) {
            if ($row['slug'] === $slug) {
                return;
            }
        }
        $list[] = ['slug' => $slug, 'label' => mb_substr(sentence_case($label), 0, 80)];
        $cfg['custom_categories'] = $list;
        $this->components->saveConfig('documents', $cfg);
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
        return $slug !== '' ? mb_convert_case($slug, MB_CASE_TITLE, 'UTF-8') : __('documents.category_other');
    }

    private function uploadMaxBytes(): int
    {
        $appCap = 25 * 1024 * 1024;
        $ini = min(
            $this->parseIniBytes((string) ini_get('upload_max_filesize')),
            $this->parseIniBytes((string) ini_get('post_max_size'))
        );
        return max(1, min($appCap, $ini > 0 ? $ini : $appCap));
    }

    public function uploadMaxMb(): int
    {
        return max(1, (int) ceil($this->uploadMaxBytes() / (1024 * 1024)));
    }

    public function uploadLimitBytes(): int
    {
        return $this->uploadMaxBytes();
    }

    private function parseIniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function isSafeStoredPath(string $relative): bool
    {
        return is_user_upload_relative_path($relative);
    }

    public function filePath(int $id): ?string
    {
        $row = $this->db->fetch('SELECT file_path FROM association_documents WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        $rel = trim((string) ($row['file_path'] ?? ''));
        if ($rel === '' || !$this->isSafeStoredPath($rel)) {
            return null;
        }
        return resolve_upload_absolute_path($rel);
    }
}
