<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Validator;

final class DocumentService
{
    /** @var list<string> */
    public const BUILTIN_CATEGORIES = [
        'minutes',
        'board_minutes',
        'statute',
        'regulation',
        'contract',
        'other',
    ];

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
        private readonly ComponentService $components
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $limit = 200, string $query = ''): array
    {
        $query = trim($query);
        $limitSql = ' LIMIT ' . max(1, min(500, $limit));
        $base = 'SELECT d.*, u.name AS author_name
             FROM association_documents d
             LEFT JOIN users u ON u.id = d.created_by';

        if ($query === '') {
            return $this->db->fetchAll(
                $base . ' ORDER BY COALESCE(d.document_date, d.created_at) DESC, d.id DESC' . $limitSql
            );
        }

        $params = [];
        $where = $this->buildSearchWhere($query, $params);
        return $this->db->fetchAll(
            $base . ' WHERE ' . $where . ' ORDER BY COALESCE(d.document_date, d.created_at) DESC, d.id DESC' . $limitSql,
            $params
        );
    }

    public function countAll(): int
    {
        $row = $this->db->fetch('SELECT COUNT(*) AS c FROM association_documents');
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
     * @return list<array{key:string,label:string,builtin:bool}>
     */
    public function categoryOptions(): array
    {
        $options = [];
        foreach (self::BUILTIN_CATEGORIES as $key) {
            $options[] = [
                'key' => $key,
                'label' => __('documents.category_' . $key),
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
        $cfg = $this->components->config('documents', ['default_category' => 'minutes']);
        $key = trim((string) ($cfg['default_category'] ?? 'minutes'));
        $valid = array_column($this->categoryOptions(), 'key');
        return in_array($key, $valid, true) ? $key : 'minutes';
    }

    /**
     * Immediate upload used by the document form (AJAX).
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{ok:bool,error?:string,path?:string,mime?:string,name?:string}
     */
    public function upload(array $file): array
    {
        $stored = $this->storeFile($file);
        if (!$stored['ok']) {
            return ['ok' => false, 'error' => (string) ($stored['error'] ?? __('documents.upload_fail'))];
        }
        return [
            'ok' => true,
            'path' => (string) $stored['path'],
            'mime' => (string) $stored['mime'],
            'name' => (string) ($file['name'] ?? basename((string) $stored['path'])),
        ];
    }

    /** @param array<string,mixed> $input */
    public function create(array $input, ?array $file, string $ip, ?int $userId = null): array
    {
        $parsed = $this->parseDocumentInput($input, $file, null);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'errors' => $parsed['errors'] ?? ['title' => __('validation.required')]];
        }
        /** @var array<string,mixed> $data */
        $data = $parsed['data'];
        $data['created_by'] = $userId;
        $id = $this->db->insert('association_documents', $data);
        $this->audit->log('document.created', 'document', (string) $id, null, [
            'title' => $data['title'],
            'document_number' => $data['document_number'],
            'category' => $data['category'],
            'language' => $data['language'],
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
             WHERE d.id = :id',
            ['id' => $id]
        );
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
        $this->db->update('association_documents', $data, 'id = :id', ['id' => $id]);
        $oldPath = trim((string) ($existing['file_path'] ?? ''));
        $newPath = trim((string) ($data['file_path'] ?? ''));
        if ($oldPath !== '' && $newPath !== $oldPath && $this->isSafeStoredPath($oldPath)) {
            $oldFullPath = storage_path($oldPath);
            if (is_file($oldFullPath)) {
                @unlink($oldFullPath);
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
        if (!in_array($status, ['draft', 'approved', 'signed'], true)) {
            $status = 'draft';
        }

        $attachment = $this->resolveAttachment($input, $file, $existing);
        if (empty($attachment['ok'])) {
            return ['ok' => false, 'errors' => ['file' => (string) ($attachment['error'] ?? __('documents.upload_fail'))]];
        }

        return [
            'ok' => true,
            'data' => [
                'title' => $title,
                'document_number' => mb_substr(trim((string) ($input['document_number'] ?? '')), 0, 80),
                'category' => $category,
                'language' => $language,
                'document_date' => $docDate !== '' ? $docDate : null,
                'file_path' => $attachment['path'],
                'file_mime' => $attachment['mime'],
                'summary' => trim((string) ($input['summary'] ?? '')),
                'status' => $status,
            ],
        ];
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
            $full = storage_path($uploadedPath);
            if (!is_file($full)) {
                return ['ok' => false, 'path' => null, 'mime' => null, 'error' => __('documents.upload_missing')];
            }
            return [
                'ok' => true,
                'path' => $uploadedPath,
                'mime' => $uploadedMime !== '' ? $uploadedMime : (mime_content_type($full) ?: null),
            ];
        }

        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $stored = $this->storeFile($file);
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
            if ($keepPath !== '' && $this->isSafeStoredPath($keepPath) && is_file(storage_path($keepPath))) {
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

    /** @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file */
    private function storeFile(array $file): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $maxBytes = $this->uploadMaxBytes();
        $maxMb = max(1, (int) ceil($maxBytes / (1024 * 1024)));

        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'error' => __('documents.upload_too_large', ['max' => (string) $maxMb])];
        }
        if ($err === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => __('documents.upload_required')];
        }
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => __('documents.upload_fail')];
        }
        if (($file['size'] ?? 0) > $maxBytes) {
            return ['ok' => false, 'error' => __('documents.upload_too_large', ['max' => (string) $maxMb])];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'error' => __('documents.upload_fail')];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        $original = strtolower((string) ($file['name'] ?? ''));
        // Some clients send PDF as application/octet-stream.
        if ($mime === 'application/octet-stream' && str_ends_with($original, '.pdf')) {
            $mime = 'application/pdf';
        }
        $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            return ['ok' => false, 'error' => __('documents.upload_type')];
        }
        $ext = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };
        $dir = storage_path('documents');
        if (!ensure_directory($dir)) {
            return ['ok' => false, 'error' => __('documents.upload_storage')];
        }
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($tmp, $dest)) {
            return ['ok' => false, 'error' => __('documents.upload_storage')];
        }
        @chmod($dest, 0664);
        return ['ok' => true, 'path' => 'documents/' . $name, 'mime' => $mime];
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
        $appCap = 10 * 1024 * 1024;
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
        if (!str_starts_with($relative, 'documents/')) {
            return false;
        }
        if (str_contains($relative, '..') || str_contains($relative, '\\')) {
            return false;
        }
        return (bool) preg_match('#^documents/[a-zA-Z0-9._-]+$#', $relative);
    }

    public function filePath(int $id): ?string
    {
        $row = $this->db->fetch('SELECT file_path FROM association_documents WHERE id = :id', ['id' => $id]);
        $rel = trim((string) ($row['file_path'] ?? ''));
        if ($rel === '' || !$this->isSafeStoredPath($rel)) {
            return null;
        }
        $full = storage_path($rel);
        return is_file($full) ? $full : null;
    }
}
