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

/**
 * Central file repository. Every upload of the app and of the add-ons is stored here once
 * (SHA-256 dedupe) and described by an `association_documents` row: section of origin, context
 * reference, reference year, who uploaded it and when. `document_links` lists every place that
 * references the same bytes, so an attachment coming from the forum, a poll or the treasury is
 * still one single file in “Verbali e documenti”.
 */
final class FileRepositoryService
{
    /** @var array<string,string> */
    public const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /** @var array<string,string> default document category per source section */
    private const SECTION_CATEGORIES = [
        'documents' => 'other',
        'treasury' => 'invoice',
        'members' => 'other',
        'enrollment' => 'members_register',
        'internal_forum' => 'other',
        'board_voting' => 'board_minutes',
        'bank_statement_import' => 'balance',
        'agenzia_entrate_invoices' => 'invoice',
        'tablet_enrollment' => 'members_register',
        'system' => 'other',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit
    ) {
    }

    /**
     * Store a multipart upload in the repository without creating a document row yet.
     * Used by the documents form (the metadata arrives with the following submit).
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @param array<string,mixed> $context
     * @return array{ok:bool,error?:string,error_code?:string,path?:string,mime?:string,size?:int,hash?:string,original_name?:string,duplicated?:bool}
     */
    public function stageUpload(array $file, array $context = [], string $ip = ''): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $maxBytes = $this->uploadMaxBytes();
        $maxMb = max(1, (int) ceil($maxBytes / (1024 * 1024)));
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
            || (int) ($file['size'] ?? 0) > $maxBytes) {
            return [
                'ok' => false,
                'error_code' => 'too_large',
                'error' => __('documents.upload_too_large', ['max' => (string) $maxMb]),
            ];
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error_code' => 'no_file', 'error' => __('documents.upload_required')];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error_code' => 'upload', 'error' => __('documents.upload_fail')];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'error_code' => 'upload', 'error' => __('documents.upload_fail')];
        }
        $originalName = mb_substr(basename((string) ($file['name'] ?? 'document')), 0, 255);
        $detected = $this->detectMime($tmp, $originalName, $context);
        if (empty($detected['ok'])) {
            return $detected;
        }
        $mime = (string) $detected['mime'];
        $ext = (string) $detected['ext'];
        $hash = hash_file('sha256', $tmp);
        if (!is_string($hash) || strlen($hash) !== 64) {
            return ['ok' => false, 'error_code' => 'upload', 'error' => __('documents.upload_fail')];
        }
        $referenceYear = $this->resolveYear($context, $originalName);
        $relative = $this->canonicalRelativePath($hash, $ext, $referenceYear);
        $absolute = storage_path('uploads/' . $relative);
        $duplicated = is_file($absolute);
        if (!$duplicated) {
            if (!is_uploaded_file($tmp) || !move_uploaded_file($tmp, $absolute)) {
                return ['ok' => false, 'error_code' => 'storage', 'error' => __('documents.upload_storage')];
            }
            @chmod($absolute, 0664);
        }
        $size = (int) (@filesize($absolute) ?: ($file['size'] ?? 0));

        return [
            'ok' => true,
            'path' => $relative,
            'mime' => $mime,
            'size' => $size,
            'hash' => $hash,
            'original_name' => $originalName,
            'duplicated' => $duplicated,
        ];
    }

    /**
     * Store an upload and register it in the repository (one document row per hash).
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @param array<string,mixed> $context
     * @return array{ok:bool,error?:string,error_code?:string,document_id?:int,path?:string,mime?:string,size?:int,hash?:string,original_name?:string,duplicated?:bool,reused?:bool}
     */
    public function storeUpload(array $file, array $context = [], string $ip = ''): array
    {
        $staged = $this->stageUpload($file, $context, $ip);
        if (empty($staged['ok'])) {
            return $staged;
        }

        return $this->registerStoredFile((string) $staged['path'], $context + [
            'hash' => (string) $staged['hash'],
            'mime' => (string) $staged['mime'],
            'size' => (int) $staged['size'],
            'original_name' => (string) $staged['original_name'],
        ], $ip, true);
    }

    /**
     * Register a file already written on disk (bank statements, invoice PDFs, signatures).
     * The bytes are copied into the canonical repository folder unless they are already there;
     * an existing row with the same hash is reused and linked to the new context.
     *
     * @param array<string,mixed> $context
     * @return array{ok:bool,error?:string,error_code?:string,document_id?:int,path?:string,mime?:string,size?:int,hash?:string,original_name?:string,duplicated?:bool,reused?:bool}
     */
    public function registerStoredFile(string $source, array $context = [], string $ip = '', bool $discardDuplicate = false): array
    {
        $source = $this->absoluteSource($source);
        if ($source === '' || !is_file($source)) {
            return ['ok' => false, 'error_code' => 'missing', 'error' => __('documents.upload_missing')];
        }
        $hash = hash_file('sha256', $source);
        if (!is_string($hash) || strlen($hash) !== 64) {
            return ['ok' => false, 'error_code' => 'upload', 'error' => __('documents.upload_fail')];
        }
        $originalName = mb_substr(
            trim((string) ($context['original_name'] ?? basename($source))),
            0,
            255
        );
        if ($originalName === '') {
            $originalName = basename($source);
        }
        $existing = $this->findByHash($hash);
        if ($existing !== null) {
            $this->link((int) $existing['id'], (string) ($context['section'] ?? 'documents'), (string) ($context['ref'] ?? ''), $context);
            $existingFile = resolve_upload_absolute_path((string) ($existing['file_path'] ?? ''));
            if ($source !== $existingFile && $discardDuplicate) {
                @unlink($source);
            }

            return [
                'ok' => true,
                'document_id' => (int) $existing['id'],
                'path' => (string) $existing['file_path'],
                'mime' => (string) ($existing['file_mime'] ?? ($context['mime'] ?? 'application/octet-stream')),
                'size' => (int) ($existing['file_size'] ?? (@filesize($source) ?: 0)),
                'hash' => $hash,
                'original_name' => (string) ($existing['original_name'] ?? $originalName),
                'duplicated' => true,
                'reused' => true,
            ];
        }

        $mime = (string) ($context['mime'] ?? '');
        if ($mime === '') {
            $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($source) ?: 'application/octet-stream');
        }
        $ext = (string) ($context['ext'] ?? (self::MIME_EXTENSIONS[$mime] ?? strtolower(pathinfo($source, PATHINFO_EXTENSION)) ?: 'bin'));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $referenceYear = $this->resolveYear($context, $originalName);
        $relative = $this->canonicalRelativePath($hash, $ext, $referenceYear);
        $absolute = storage_path('uploads/' . $relative);
        if (realpath($source) !== realpath($absolute)) {
            if (!is_file($absolute) && !@copy($source, $absolute)) {
                return ['ok' => false, 'error_code' => 'storage', 'error' => __('documents.upload_storage')];
            }
            if (is_file($absolute)) {
                @chmod($absolute, 0664);
            }
            if ($discardDuplicate) {
                @unlink($source);
            }
        }
        $size = (int) (@filesize($absolute) ?: 0);

        $months = $this->resolveMonths($context);

        return $this->insertRepositoryRow([
            'hash' => $hash,
            'path' => $relative,
            'mime' => $mime,
            'size' => $size,
            'original_name' => $originalName,
            'context' => $context,
            'reference_year' => $referenceYear,
            'reference_month_from' => $months['from'],
            'reference_month_to' => $months['to'],
        ], $ip);
    }

    /**
     * Register raw bytes (base64 signature, generated PDF) without an HTTP upload.
     *
     * @param array<string,mixed> $context
     * @return array{ok:bool,error?:string,error_code?:string,document_id?:int,path?:string,mime?:string,size?:int,hash?:string,original_name?:string,duplicated?:bool,reused?:bool}
     */
    public function registerContent(string $bytes, string $originalName, array $context = [], string $ip = ''): array
    {
        if ($bytes === '') {
            return ['ok' => false, 'error_code' => 'empty', 'error' => __('documents.upload_required')];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'socly_repo_');
        if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
            return ['ok' => false, 'error_code' => 'storage', 'error' => __('documents.upload_storage')];
        }
        try {
            return $this->registerStoredFile($tmp, $context + ['original_name' => $originalName], $ip, true);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }


    /** @return array<string,mixed>|null */
    public function findByHash(string $hash): ?array
    {
        $hash = strtolower(trim($hash));
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return null;
        }

        $existing = $this->db->fetch(
            'SELECT * FROM association_documents
              WHERE content_hash = :hash AND deleted_at IS NULL
              ORDER BY id ASC LIMIT 1',
            ['hash' => $hash]
        );
        if ($existing !== null) {
            return $existing;
        }

        // Legacy rows uploaded before content_hash existed: compute on the fly and keep the oldest.
        $candidates = $this->db->fetchAll(
            "SELECT * FROM association_documents
              WHERE deleted_at IS NULL
                AND file_path IS NOT NULL AND file_path <> ''
                AND (content_hash IS NULL OR content_hash = '')
              ORDER BY id ASC
              LIMIT 400"
        );
        foreach ($candidates as $row) {
            $absolute = resolve_upload_absolute_path(ltrim((string) ($row['file_path'] ?? ''), '/'));
            if ($absolute === null) {
                continue;
            }
            $computed = hash_file('sha256', $absolute);
            if (!is_string($computed) || !hash_equals($hash, $computed)) {
                continue;
            }
            try {
                $this->db->update(
                    'association_documents',
                    [
                        'content_hash' => $hash,
                        'file_size' => (int) (@filesize($absolute) ?: 0),
                    ],
                    'id = :id',
                    ['id' => (int) $row['id']]
                );
            } catch (\Throwable) {
            }
            $row['content_hash'] = $hash;

            return $row;
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public function fileInfo(int $documentId): ?array
    {
        if ($documentId < 1) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT * FROM association_documents WHERE id = :id AND deleted_at IS NULL',
            ['id' => $documentId]
        );
        if ($row === null || trim((string) ($row['file_path'] ?? '')) === '') {
            return null;
        }

        return $row;
    }

    /** Find the repository row that owns a stored relative path. */
    public function findByPath(string $relative): ?array
    {
        $relative = ltrim(trim($relative), '/');
        if ($relative === '') {
            return null;
        }

        return $this->db->fetch(
            'SELECT * FROM association_documents
              WHERE file_path = :path AND deleted_at IS NULL
              ORDER BY id ASC LIMIT 1',
            ['path' => $relative]
        );
    }

    /** Remove a context link knowing only the stored path (member photos, enrollments). */
    public function unlinkByPath(string $relative, string $section, string $contextRef = ''): void
    {
        $row = $this->findByPath($relative);
        if ($row !== null) {
            $this->unlink((int) $row['id'], $section, $contextRef);
        }
    }

    /** Absolute path of a repository file (null when missing or unsafe). */
    public function absolutePath(int $documentId): ?string
    {
        $row = $this->fileInfo($documentId);
        if ($row === null) {
            return null;
        }
        $relative = ltrim((string) $row['file_path'], '/');
        if (!is_user_upload_relative_path($relative)) {
            return null;
        }

        return resolve_upload_absolute_path($relative);
    }

    /**
     * Record that `$section` / `$contextRef` references the file. Idempotent.
     *
     * @param array<string,mixed> $identity
     */
    public function link(int $documentId, string $section, string $contextRef = '', array $identity = []): void
    {
        if ($documentId < 1) {
            return;
        }
        $section = $this->normalizeSection($section);
        $contextRef = mb_substr(trim($contextRef), 0, 190);
        $who = $this->identity($identity);
        try {
            $this->db->query(
                'INSERT INTO document_links
                    (document_id, section, context_ref, linked_by_type, linked_by_id, linked_by_name)
                 VALUES (:document, :section, :ref, :type, :uid, :name)
                 ON DUPLICATE KEY UPDATE document_id = document_id',
                [
                    'document' => $documentId,
                    'section' => $section,
                    'ref' => $contextRef,
                    'type' => $who['type'],
                    'uid' => $who['id'],
                    'name' => $who['name'],
                ]
            );
        } catch (\Throwable) {
            // The repository must never block the section that is saving its file.
        }
    }

    /** Remove a context link (the bytes stay while another section references them). */
    public function unlink(int $documentId, string $section, string $contextRef = ''): void
    {
        if ($documentId < 1) {
            return;
        }
        try {
            $this->db->query(
                'DELETE FROM document_links WHERE document_id = :document AND section = :section AND context_ref = :ref',
                [
                    'document' => $documentId,
                    'section' => $this->normalizeSection($section),
                    'ref' => mb_substr(trim($contextRef), 0, 190),
                ]
            );
        } catch (\Throwable) {
        }
    }

    /** @return list<array<string,mixed>> */
    public function linksFor(int $documentId): array
    {
        if ($documentId < 1) {
            return [];
        }
        try {
            return $this->db->fetchAll(
                'SELECT * FROM document_links WHERE document_id = :id ORDER BY section ASC, context_ref ASC',
                ['id' => $documentId]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Validate a document chosen through the “already uploaded file” picker.
     * When a section is given, the document must belong to it (origin or link).
     *
     * @return array<string,mixed>|null
     */
    public function pick(int $documentId, string $section = ''): ?array
    {
        $row = $this->fileInfo($documentId);
        if ($row === null) {
            return null;
        }
        if ($section !== '') {
            $section = $this->normalizeSection($section);
            if ((string) ($row['source_section'] ?? '') !== $section) {
                $linked = $this->db->fetch(
                    'SELECT 1 AS x FROM document_links WHERE document_id = :id AND section = :section LIMIT 1',
                    ['id' => $documentId, 'section' => $section]
                );
                if ($linked === null) {
                    return null;
                }
            }
        }

        return $row;
    }

    /**
     * Documents linked to a context through `document_links`, grouped by `context_ref`.
     * Used by the treasury ledger to show the statement excerpts attached to a movement without
     * knowing which add-on produced them.
     *
     * @param list<string> $refs
     * @return array<string,list<array<string,mixed>>>
     */
    public function linkedDocumentsForRefs(string $section, array $refs, string $sourceSection = ''): array
    {
        $section = $this->normalizeSection($section);
        $clean = [];
        foreach ($refs as $ref) {
            $ref = mb_substr(trim((string) $ref), 0, 190);
            if ($ref !== '') {
                $clean[$ref] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $clean = array_slice(array_keys($clean), 0, 500);
        $placeholders = [];
        $params = ['section' => $section];
        foreach ($clean as $position => $ref) {
            $placeholder = ':ref' . $position;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $ref;
        }
        $sql = 'SELECT d.*, l.context_ref, l.section AS link_section, l.created_at AS linked_at
                  FROM document_links l
                  INNER JOIN association_documents d ON d.id = l.document_id
                 WHERE l.section = :section
                   AND l.context_ref IN (' . implode(', ', $placeholders) . ')
                   AND d.deleted_at IS NULL';
        if ($sourceSection !== '') {
            $sql .= ' AND d.source_section = :source_section';
            $params['source_section'] = $this->normalizeSection($sourceSection);
        }
        $sql .= ' ORDER BY d.created_at DESC, d.id DESC';
        try {
            $rows = $this->db->fetchAll($sql, $params);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $ref = (string) ($row['context_ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            $out[$ref][] = $row;
        }

        return $out;
    }

    /**
     * Files available in the picker of one section (searchable, bounded).
     *
     * @return list<array<string,mixed>>
     */
    public function listForPicker(string $section = '', string $query = '', ?int $year = null, int $limit = 60, string $contextRef = ''): array
    {
        $where = ['deleted_at IS NULL', 'file_path IS NOT NULL', "file_path <> ''"];
        $params = [];
        if ($section !== '') {
            $where[] = '(source_section = :section OR id IN (SELECT document_id FROM document_links WHERE section = :section_link))';
            $params['section'] = $this->normalizeSection($section);
            $params['section_link'] = $this->normalizeSection($section);
        }
        if ($contextRef !== '') {
            $where[] = "(source_ref = :context_ref OR id IN (
                SELECT document_id FROM document_links
                 WHERE section = :context_section AND context_ref = :context_ref_link
            ))";
            $params['context_ref'] = mb_substr(trim($contextRef), 0, 190);
            $params['context_ref_link'] = mb_substr(trim($contextRef), 0, 190);
            $params['context_section'] = $this->normalizeSection($section);
        }
        if ($year !== null && $year > 0) {
            $where[] = 'reference_year = :year';
            $params['year'] = $year;
        }
        $query = trim(mb_substr($query, 0, 120));
        if ($query !== '') {
            $like = '%' . $this->escapeLike($query) . '%';
            $where[] = '(title LIKE :like_title OR original_name LIKE :like_name OR document_number LIKE :like_number)';
            $params['like_title'] = $like;
            $params['like_name'] = $like;
            $params['like_number'] = $like;
        }
        $limitSql = ' LIMIT ' . max(1, min(200, $limit));

        return $this->db->fetchAll(
            'SELECT id, title, document_number, category, document_date, reference_year, source_section,
                    original_name, file_mime, file_size, uploaded_by_name, created_at
               FROM association_documents
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY COALESCE(document_date, created_at) DESC, id DESC' . $limitSql,
            $params
        );
    }

    /** @return list<string> */
    public function sections(): array
    {
        try {
            $rows = $this->db->fetchAll(
                'SELECT DISTINCT source_section AS section FROM association_documents ORDER BY source_section ASC'
            );
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['section'] ?? ''),
            $rows
        )));
    }

    /** @return list<int> */
    public function years(): array
    {
        try {
            // Include years inferred from document/upload date when reference_year is null,
            // otherwise older uploads vanish from the year filter and look "missing".
            $rows = $this->db->fetchAll(
                'SELECT DISTINCT COALESCE(reference_year, YEAR(COALESCE(document_date, created_at))) AS y
                   FROM association_documents
                  WHERE deleted_at IS NULL
                    AND COALESCE(reference_year, YEAR(COALESCE(document_date, created_at))) IS NOT NULL
                  ORDER BY y DESC'
            );
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['y'] ?? 0),
            $rows
        ), static fn (int $y): bool => $y >= 1900 && $y <= 2100));
    }

    /** @return array{files:int,size:int,linked:int,current_year:int,duplicates:int} */
    public function stats(): array
    {
        try {
            $row = $this->db->fetch(
                'SELECT COUNT(*) AS files,
                        COALESCE(SUM(file_size), 0) AS size,
                        COALESCE(SUM(CASE WHEN COALESCE(reference_year, YEAR(COALESCE(document_date, created_at))) = :year THEN 1 ELSE 0 END), 0) AS current_year
                   FROM association_documents
                  WHERE deleted_at IS NULL',
                ['year' => (int) date('Y')]
            );
            $links = $this->db->fetch("SELECT COUNT(*) AS c FROM document_links WHERE context_ref <> ''");
            $dup = $this->db->fetch(
                'SELECT COUNT(*) AS c FROM (
                    SELECT content_hash FROM association_documents
                     WHERE deleted_at IS NULL AND content_hash IS NOT NULL
                     GROUP BY content_hash HAVING COUNT(*) > 1
                 ) d'
            );
        } catch (\Throwable) {
            return ['files' => 0, 'size' => 0, 'linked' => 0, 'current_year' => 0, 'duplicates' => 0];
        }

        return [
            'files' => (int) ($row['files'] ?? 0),
            'size' => (int) ($row['size'] ?? 0),
            'linked' => (int) ($links['c'] ?? 0),
            'current_year' => (int) ($row['current_year'] ?? 0),
            'duplicates' => (int) ($dup['c'] ?? 0),
        ];
    }

    /** Human label of a source section (translation with a readable fallback). */
    public function sectionLabel(string $section): string
    {
        $section = $this->normalizeSection($section);
        $key = 'documents.section_' . $section;
        $label = (string) __('documents.section_' . $section);

        return $label !== $key ? $label : ucfirst(str_replace('_', ' ', $section));
    }

    /**
     * Signed, login-free URL for add-on pages that authenticate through their own token
     * (internal forum, board voting). Empty string when no APP_KEY is available.
     */
    public function signedUrl(int $documentId, int $ttl = 900): string
    {
        $key = (string) config('app.key', '');
        if ($documentId < 1 || $key === '') {
            return '';
        }
        $exp = time() + max(60, min(86400, $ttl));
        $sig = hash_hmac('sha256', $documentId . '|' . $exp, $key);

        return url('/files/' . $documentId) . '?exp=' . $exp . '&sig=' . $sig;
    }

    public function verifySignature(int $documentId, string $sig, int $exp): bool
    {
        $key = (string) config('app.key', '');
        if ($documentId < 1 || $key === '' || $exp < time()) {
            return false;
        }
        $expected = hash_hmac('sha256', $documentId . '|' . $exp, $key);

        return hash_equals($expected, $sig);
    }

    /** Count one open/download of the file (audit-friendly, never throws). */
    public function registerDownload(int $documentId): void
    {
        if ($documentId < 1) {
            return;
        }
        try {
            $this->db->query(
                'UPDATE association_documents
                    SET download_count = download_count + 1, last_downloaded_at = NOW()
                  WHERE id = :id',
                ['id' => $documentId]
            );
        } catch (\Throwable) {
        }
    }

    /**
     * Fill hash/size/year for files uploaded before the repository existed and report how many
     * identical files are still stored twice.
     *
     * @return array{scanned:int,updated:int,merged:int}
     */
    public function backfill(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, file_path, file_mime, document_date, created_at, content_hash, file_size
               FROM association_documents
              WHERE deleted_at IS NULL AND file_path IS NOT NULL AND file_path <> ''
              ORDER BY id ASC"
        );
        $scanned = 0;
        $updated = 0;
        $merged = 0;
        $seen = [];
        $referenced = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $referenced[ltrim((string) $row['file_path'], '/')] = true;
            $absolute = resolve_upload_absolute_path(ltrim((string) $row['file_path'], '/'));
            if ($absolute === null) {
                continue;
            }
            $scanned++;
            $hash = hash_file('sha256', $absolute);
            if (!is_string($hash)) {
                continue;
            }
            $size = (int) (@filesize($absolute) ?: 0);
            $changes = [];
            if ((string) ($row['content_hash'] ?? '') !== $hash) {
                $changes['content_hash'] = $hash;
            }
            if ((int) ($row['file_size'] ?? 0) !== $size) {
                $changes['file_size'] = $size;
            }
            if (!empty($changes)) {
                $this->db->update('association_documents', $changes, 'id = :id', ['id' => $id]);
                $updated++;
            }
            if (isset($seen[$hash])) {
                // Same bytes already archived: keep the oldest document (original name/title) and
                // soft-delete the duplicate row so the archive never lists the same file twice.
                $canonical = $seen[$hash];
                $this->link((int) $canonical['id'], 'documents', 'duplicate:' . $id, ['section' => 'documents']);
                if (ltrim((string) $canonical['file_path'], '/') !== ltrim((string) $row['file_path'], '/')) {
                    @unlink($absolute);
                }
                try {
                    $this->db->update(
                        'association_documents',
                        ['deleted_at' => date('Y-m-d H:i:s')],
                        'id = :id AND deleted_at IS NULL',
                        ['id' => $id]
                    );
                } catch (\Throwable) {
                    // If soft-delete is unavailable, at least retarget the path to the original file.
                    $this->db->update(
                        'association_documents',
                        [
                            'file_path' => (string) $canonical['file_path'],
                            'file_mime' => $canonical['file_mime'] ?: ($row['file_mime'] ?? null),
                            'content_hash' => $hash,
                        ],
                        'id = :id',
                        ['id' => $id]
                    );
                }
                $merged++;
                continue;
            }
            $seen[$hash] = [
                'id' => $id,
                'file_path' => (string) $row['file_path'],
                'file_mime' => $row['file_mime'] ?? null,
            ];
        }

        // Orphan canonical files (uploads staged but never saved, or replaced attachments): the
        // repository keeps only the bytes referenced by a document row.
        $pruned = 0;
        $base = rtrim(storage_path('uploads/users'), '/') . '/';
        foreach (glob($base . '*/*/repository/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $relative = 'users/' . str_replace('\\', '/', substr($file, strlen($base)));
            if (!isset($referenced[$relative])) {
                @unlink($file);
                $pruned++;
            }
        }

        return ['scanned' => $scanned, 'updated' => $updated, 'merged' => $merged, 'pruned' => $pruned];
    }


    /**
     * Soft-delete a repository row. Bytes stay on disk until backfill/prune; live queries ignore it.
     */
    public function softDelete(int $documentId, string $ip = ''): bool
    {
        if ($documentId < 1) {
            return false;
        }
        $row = $this->fileInfo($documentId);
        if ($row === null) {
            return false;
        }
        try {
            $this->db->update(
                'association_documents',
                ['deleted_at' => date('Y-m-d H:i:s')],
                'id = :id AND deleted_at IS NULL',
                ['id' => $documentId]
            );
        } catch (\Throwable) {
            return false;
        }
        $this->audit->log('document.repository_deleted', 'document', (string) $documentId, null, [
            'title' => (string) ($row['title'] ?? ''),
            'section' => (string) ($row['source_section'] ?? ''),
            'path' => (string) ($row['file_path'] ?? ''),
        ], $ip);

        return true;
    }

    /**
     * Update descriptive metadata of a repository file (e.g. the invoice title typed in Treasury).
     *
     * @param array<string,mixed> $meta
     */
    public function annotate(int $documentId, array $meta, string $ip = ''): void
    {
        $row = $this->fileInfo($documentId);
        if ($row === null) {
            return;
        }
        $data = [];
        foreach (['title', 'category', 'summary', 'source_section', 'source_ref', 'original_name'] as $key) {
            if (array_key_exists($key, $meta)) {
                $value = trim((string) $meta[$key]);
                $max = match ($key) {
                    'summary' => 2000,
                    'original_name' => 255,
                    default => 190,
                };
                $data[$key] = $value !== '' ? mb_substr($value, 0, $max) : null;
            }
        }
        if (array_key_exists('document_date', $meta)) {
            $date = trim((string) $meta['document_date']);
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $data['document_date'] = $date;
                $data['reference_year'] = (int) substr($date, 0, 4);
            }
        }
        if ($data === []) {
            return;
        }
        $this->db->update('association_documents', $data, 'id = :id', ['id' => $documentId]);
        $this->audit->log('document.repository_updated', 'document', (string) $documentId, null, $data, $ip);
    }

    /**
     * @param array{hash:string,path:string,mime:string,size:int,original_name:string,context:array<string,mixed>,reference_year:int,reference_month_from:?int,reference_month_to:?int} $args
     * @return array{ok:bool,document_id:int,path:string,mime:string,size:int,hash:string,original_name:string,duplicated:bool,reused:bool}
     */
    private function insertRepositoryRow(array $args, string $ip): array
    {
        $context = $args['context'];
        $section = $this->normalizeSection((string) ($context['section'] ?? 'documents'));
        $contextRef = mb_substr(trim((string) ($context['ref'] ?? '')), 0, 190);
        $category = mb_substr(trim((string) ($context['category'] ?? '')), 0, 60);
        if ($category === '') {
            $category = self::SECTION_CATEGORIES[$section] ?? 'other';
        }
        $title = trim((string) ($context['title'] ?? ''));
        if ($title === '') {
            $name = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $args['original_name']) ?? '';
            $title = trim($name) !== '' ? ucfirst($name) : __('documents.repository_untitled');
        }
        $title = mb_substr($title, 0, 190);
        $who = $this->identity($context);
        $date = trim((string) ($context['date'] ?? ''));
        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = date('Y-m-d');
        }
        $now = date('Y-m-d H:i:s');
        // `created_by` is a real users.id: CLI imports, add-ons and system tasks may pass an id
        // that no longer exists, and the FK must never abort the archive.
        $createdBy = null;
        if ($who['type'] === 'operator' && $who['id'] !== null && $who['id'] > 0) {
            try {
                $userExists = $this->db->fetch('SELECT 1 AS x FROM users WHERE id = :id', ['id' => $who['id']]);
                if ($userExists !== null) {
                    $createdBy = $who['id'];
                }
            } catch (\Throwable) {
                $createdBy = null;
            }
        }
        $monthFrom = isset($args['reference_month_from']) ? (int) $args['reference_month_from'] : 0;
        $monthTo = isset($args['reference_month_to']) ? (int) $args['reference_month_to'] : 0;
        $id = $this->db->insert('association_documents', [
            'title' => $title,
            'document_number' => mb_substr(trim((string) ($context['document_number'] ?? '')), 0, 80) ?: null,
            'category' => $category,
            'language' => null,
            'document_date' => $date,
            'expires_at' => null,
            'file_path' => $args['path'],
            'file_mime' => $args['mime'],
            'summary' => trim((string) ($context['summary'] ?? '')) !== ''
                ? mb_substr((string) $context['summary'], 0, 2000)
                : null,
            'status' => 'approved',
            'visibility' => in_array((string) ($context['visibility'] ?? 'internal'), ['reserved', 'internal', 'public'], true)
                ? (string) ($context['visibility'] ?? 'internal')
                : 'internal',
            'created_by' => $createdBy,
            'member_id' => isset($context['member_id']) ? (int) $context['member_id'] ?: null : null,
            'content_hash' => $args['hash'],
            'file_size' => $args['size'],
            'original_name' => $args['original_name'],
            'source_section' => $section,
            'source_ref' => $contextRef !== '' ? $contextRef : null,
            'reference_year' => max(1900, min(2100, (int) $args['reference_year'])),
            'reference_month_from' => ($monthFrom >= 1 && $monthFrom <= 12) ? $monthFrom : null,
            'reference_month_to' => ($monthTo >= 1 && $monthTo <= 12) ? $monthTo : null,
            'uploaded_by_type' => $who['type'],
            'uploaded_by_id' => $who['id'],
            'uploaded_by_name' => $who['name'],
            'uploaded_ip' => $ip !== '' ? mb_substr($ip, 0, 45) : null,
            'created_at' => $now,
        ]);
        $this->link($id, $section, $contextRef, $context);
        $this->audit->log('document.repository_added', 'document', (string) $id, null, [
            'title' => $title,
            'section' => $section,
            'context' => $contextRef,
            'hash' => $args['hash'],
            'size' => $args['size'],
            'uploaded_by' => $who['name'],
        ], $ip);

        return [
            'ok' => true,
            'document_id' => $id,
            'path' => $args['path'],
            'mime' => $args['mime'],
            'size' => $args['size'],
            'hash' => $args['hash'],
            'original_name' => $args['original_name'],
            'duplicated' => false,
            'reused' => false,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array{ok:true,mime:string,ext:string}|array{ok:false,error_code:string,error:string}
     */
    private function detectMime(string $tmp, string $originalName, array $context): array
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmp) ?: '');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $byExtension = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
        ];
        // Some clients (and the browser extension) send PDF/images as application/octet-stream.
        if ($mime === 'application/octet-stream' && isset($byExtension[$ext])) {
            $mime = $byExtension[$ext];
        }
        $allowed = $context['allowed_mimes'] ?? array_keys(self::MIME_EXTENSIONS);
        if (!is_array($allowed) || $allowed === []) {
            $allowed = array_keys(self::MIME_EXTENSIONS);
        }
        if (!in_array($mime, $allowed, true)) {
            return ['ok' => false, 'error_code' => 'type', 'error' => __('documents.upload_type')];
        }

        return ['ok' => true, 'mime' => $mime, 'ext' => self::MIME_EXTENSIONS[$mime] ?? 'bin'];
    }

    /** @param array<string,mixed> $context */
    private function resolveYear(array $context, string $originalName): int
    {
        foreach ([$context['year'] ?? null, $context['date'] ?? null] as $candidate) {
            if (is_int($candidate) && $candidate >= 1900 && $candidate <= 2100) {
                return $candidate;
            }
            if (is_string($candidate) && preg_match('/\b(19|20)\d{2}\b/', $candidate, $m) === 1) {
                return (int) $m[0];
            }
        }
        $haystack = implode(' ', [
            (string) ($context['title'] ?? ''),
            (string) ($context['ref'] ?? ''),
            $originalName,
        ]);
        if (preg_match('/\b(19|20)\d{2}\b/', $haystack, $m) === 1) {
            return (int) $m[0];
        }

        return (int) date('Y');
    }

    /**
     * Optional reference months (1–12) for period documents such as bank statements.
     *
     * @param array<string,mixed> $context
     * @return array{from:?int,to:?int}
     */
    private function resolveMonths(array $context): array
    {
        $from = $this->normalizeMonth($context['month_from'] ?? $context['reference_month_from'] ?? null);
        $to = $this->normalizeMonth($context['month_to'] ?? $context['reference_month_to'] ?? null);
        if ($from === null && $to === null) {
            $date = trim((string) ($context['date'] ?? ''));
            if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $date, $m) === 1) {
                $from = (int) $m[2];
                $to = $from;
            }
        }
        if ($from !== null && $to === null) {
            $to = $from;
        }
        if ($to !== null && $from === null) {
            $from = $to;
        }
        if ($from !== null && $to !== null && $to < $from) {
            [$from, $to] = [$to, $from];
        }

        return ['from' => $from, 'to' => $to];
    }

    private function normalizeMonth(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))) {
            $month = (int) $value;
            if ($month >= 1 && $month <= 12) {
                return $month;
            }
        }

        return null;
    }

    /**
     * Persist reference months on an existing repository document (used when a statement period
     * is refined after re-parsing the PDF).
     */
    public function setReferencePeriod(int $documentId, int $year, ?int $monthFrom, ?int $monthTo): void
    {
        if ($documentId < 1) {
            return;
        }
        $from = $this->normalizeMonth($monthFrom);
        $to = $this->normalizeMonth($monthTo);
        if ($from !== null && $to === null) {
            $to = $from;
        }
        if ($to !== null && $from === null) {
            $from = $to;
        }
        $data = [
            'reference_year' => max(1900, min(2100, $year > 0 ? $year : (int) date('Y'))),
            'reference_month_from' => $from,
            'reference_month_to' => $to,
        ];
        try {
            $this->db->update('association_documents', $data, 'id = :id', ['id' => $documentId]);
        } catch (\Throwable) {
            // Columns may be missing before migration 060: never break the archive.
        }
    }

    private function canonicalRelativePath(string $hash, string $ext, int $year): string
    {
        $name = strtolower($hash) . '.' . $ext;
        $paths = user_upload_paths('repository', $year . '-01-01', $name);

        return $paths['relative'];
    }

    private function absoluteSource(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }
        if (is_file($source)) {
            return $source;
        }
        $source = ltrim($source, '/');
        if (str_starts_with($source, 'uploads/') || str_starts_with($source, 'documents/')) {
            $candidate = storage_path($source);
        } else {
            $candidate = storage_path('uploads/' . $source);
        }

        return is_file($candidate) ? $candidate : (resolve_upload_absolute_path($source) ?? '');
    }

    /** @param array<string,mixed> $context */
    private function identity(array $context): array
    {
        $type = strtolower(trim((string) ($context['actor_type'] ?? '')));
        $id = isset($context['actor_id']) ? (int) $context['actor_id'] : null;
        $name = trim((string) ($context['actor_name'] ?? ''));
        if ($name === '') {
            $user = auth_user();
            if (is_array($user)) {
                if ($type === '') {
                    $type = 'operator';
                }
                $id = $id ?? (int) ($user['id'] ?? 0);
                $name = (string) ($user['name'] ?? ($user['email'] ?? ''));
            }
        }
        if ($type === '') {
            $type = 'system';
        }
        if (in_array($type, ['operator', 'member', 'participant', 'addon', 'public', 'system'], true) === false) {
            $type = 'system';
        }

        return [
            'type' => $type,
            'id' => $id !== null && $id > 0 ? $id : null,
            'name' => $name !== '' ? mb_substr($name, 0, 190) : null,
        ];
    }

    private function normalizeSection(string $section): string
    {
        $section = strtolower(trim($section));
        $section = preg_replace('/[^a-z0-9_]/', '_', $section) ?? '';

        return $section !== '' ? mb_substr($section, 0, 80) : 'documents';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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
}

