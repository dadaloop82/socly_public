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

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\DocumentService;
use Socly\Services\FileRepositoryService;
use Socly\Services\MemberService;
use Socly\Services\MinutesService;

final class DocumentsController extends BaseController
{
    public function __construct(
        View $view,
        private readonly DocumentService $documents,
        private readonly MemberService $members,
        private readonly FileRepositoryService $files,
        private readonly MinutesService $minutes
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        require_component('documents');
        $query = trim((string) $request->input('q', ''));
        if (mb_strlen($query) > 120) {
            $query = mb_substr($query, 0, 120);
        }
        $filters = $this->listFilters($request);
        $sections = [];
        foreach ($this->files->sections() as $sectionKey) {
            $sections[$sectionKey] = $this->files->sectionLabel($sectionKey);
        }
        $this->render('documents/index', [
            'title' => __('documents.title'),
            'documents' => $this->documents->all(5000, $query, $filters),
            'counts' => $this->documents->counts(),
            'minutes_overview' => $this->documents->minutesOverview(),
            'filters' => $filters,
            'categories' => $this->documents->categoryOptions(),
            'category_groups' => $this->documents->categoryGroupedOptions(),
            'default_category' => $this->documents->defaultCategory(),
            'languages' => DocumentService::LANGUAGES,
            'upload_max_mb' => $this->documents->uploadMaxMb(),
            'members' => $this->members->listForSelect(),
            'sibling_options' => $this->documents->all(100),
            'search_query' => $query,
            'repository_sections' => $sections,
            'repository_years' => $this->files->years(),
            'repository_stats' => $this->files->stats(),
            'excerpt_repair' => $this->excerptRepairSummary(),
        ]);
    }

    /** Final minutes are immutable: corrections require a new minute. */
    private function minutesDocumentLocked(array $doc): bool
    {
        return (string) ($doc['source_ref'] ?? '') === 'minutes'
            && in_array((string) ($doc['status'] ?? ''), ['approved', 'signed', 'archived'], true);
    }

    /** “Crea nuovo verbale”: template wizard with data already stored in SOCLY. */
    public function minutesCreate(Request $request): void
    {
        $this->renderMinutesWizard();
    }

    /** Saves the wizard as an archived document with its generated PDF. */
    public function minutesStore(Request $request): void
    {
        require_component('documents');
        $input = $request->all();
        $result = $this->minutes->save($input, $request->ip(), (int) (auth_user()['id'] ?? 0));
        if (empty($result['ok'])) {
            // Back to the wizard with the input preserved: a dedicated flash (not `errors`) so the
            // reasons are shown inside the wizard instead of a generic banner above it.
            $this->flash('minutes_errors', is_array($result['errors'] ?? null) ? $result['errors'] : []);
            $this->rememberOld($input);
            redirect('/documents/minutes/create');
        }
        $this->clearOld();
        $this->flash('success', __('documents.minutes_created'));
        redirect('/documents/' . (int) ($result['id'] ?? 0));
    }

    /**
     * Data every render of the “new minute” wizard needs (GET and a failed POST share it).
     *
     * @param array<string,string>|null $errors Null = read the one-shot flash of a rejected save.
     */
    private function renderMinutesWizard(?array $errors = null): void
    {
        require_component('documents');
        if ($errors === null) {
            $flashed = flash('minutes_errors');
            $errors = is_array($flashed) ? $flashed : [];
        }
        $templates = $this->minutes->templates();
        $year = (int) date('Y');
        $numbers = [];
        foreach (MinutesService::TEMPLATES as $config) {
            $category = (string) $config['category'];
            if (!isset($numbers[$category])) {
                $numbers[$category] = $this->minutes->nextNumber($category, date('Y-m-d'));
            }
        }
        $pending = $this->minutes->pendingMemberLabels();
        $budget = $this->minutes->budgetSummary($year);
        $pendingText = $pending !== []
            ? (string) __('documents.minutes_pending_prefix') . ' ' . implode(', ', $pending) . '.'
            : '';
        $budgetText = $budget !== null
            ? (string) __('documents.minutes_budget_text', [
                'year' => (string) $budget['year'],
                'income' => number_format($budget['income'], 2, ',', '.'),
                'expense' => number_format($budget['expense'], 2, ',', '.'),
                'need' => number_format($budget['need'], 2, ',', '.'),
            ])
            : '';

        $this->render('documents/minutes', [
            'title' => __('documents.minutes_wizard_title'),
            'templates' => $templates,
            'people' => $this->minutes->peopleOptions(),
            'board_members' => $this->minutes->boardMembers(),
            'members_list' => $this->minutes->activeMembers(),
            'numbers' => $numbers,
            'default_date' => old('minutes_date', date('Y-m-d')),
            'default_number' => old('minutes_number', (string) ($numbers['board_minutes'] ?? sprintf('01/%s', date('Y')))),
            'default_place' => $this->minutes->defaultPlace(),
            'pending_text' => $pendingText,
            'budget_text' => $budgetText,
            'budget_voices' => $this->minutes->budgetVoices((int) date('Y')),
            'convocation_notice_days' => $this->minutes->convocationNoticeDays(),
            'errors' => $errors,
        ]);
    }

    public function create(Request $request): void
    {
        require_component('documents');
        $categories = $this->documents->categoryOptions();
        $category = trim((string) $request->input('category', ''));
        if ($category !== '' && !in_array($category, array_column($categories, 'key'), true)) {
            $category = '';
        }
        $this->render('documents/form', [
            'title' => __('documents.add'),
            'isEdit' => false,
            'action' => url('/documents'),
            'document' => null,
            'default_category' => $category !== '' ? $category : $this->documents->defaultCategory(),
            'categories' => $categories,
            'category_groups' => $this->documents->categoryGroupedOptions(),
            'minutes_progressives' => $this->documents->minutesProgressives(),
            'suggest_number' => true,
            'languages' => DocumentService::LANGUAGES,
            'upload_max_mb' => $this->documents->uploadMaxMb(),
            'existing_file_name' => '',
            'members' => $this->members->listForSelect(),
            'sibling_options' => $this->documents->all(100),
            'document_id' => 0,
            'reuse_options' => $this->files->listForPicker('documents', '', null, 80),
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        require_component('documents');
        $doc = $this->documents->find((int) $id);
        if ($doc === null) {
            http_response_code(404);
            $this->flash('errors', ['document' => __('errors.404')]);
            redirect('/documents');
        }
        if ($this->minutesDocumentLocked($doc)) {
            $this->flash('errors', ['document' => __('documents.minutes_locked')]);
            redirect('/documents/' . (int) $id);
        }
        $categories = $this->documents->categoryOptions();
        $catKey = trim((string) ($doc['category'] ?? ''));
        if ($catKey !== '' && !in_array($catKey, array_column($categories, 'key'), true)) {
            $categories[] = [
                'key' => $catKey,
                'label' => $this->documents->categoryLabel($catKey),
                'builtin' => false,
            ];
        }
        $filePath = trim((string) ($doc['file_path'] ?? ''));
        $siblings = array_values(array_filter(
            $this->documents->all(100),
            static fn (array $row): bool => (int) ($row['id'] ?? 0) !== (int) $id
        ));
        $this->render('documents/form', [
            'title' => __('documents.edit'),
            'isEdit' => true,
            'action' => url('/documents/' . (int) $id),
            'document' => $doc,
            'default_category' => (string) ($doc['category'] ?? 'minutes'),
            'categories' => $categories,
            'category_groups' => $this->documents->categoryGroupedOptions(),
            'minutes_progressives' => $this->documents->minutesProgressives(),
            'suggest_number' => false,
            'languages' => DocumentService::LANGUAGES,
            'upload_max_mb' => $this->documents->uploadMaxMb(),
            'existing_file_name' => $filePath !== '' ? basename($filePath) : '',
            'members' => $this->members->listForSelect(),
            'sibling_options' => $siblings,
            'document_id' => (int) $id,
            'reuse_options' => array_values(array_filter(
                $this->files->listForPicker('documents', '', null, 80),
                static fn (array $row): bool => (int) ($row['id'] ?? 0) !== (int) $id
            )),
        ]);
    }

    public function show(Request $request, string $id): void
    {
        require_component('documents');
        $doc = $this->documents->find((int) $id);
        if ($doc === null) {
            http_response_code(404);
            $this->flash('errors', ['document' => __('errors.404')]);
            redirect('/documents');
        }
        $repoId = (int) ($doc['id'] ?? 0);
        $signed = $this->files->signedUrl($repoId, 900);
        // “Referenze da altre sezioni”: readable labels instead of the raw section/ref codes.
        $repositoryLinks = [];
        foreach ($this->files->linksFor($repoId) as $link) {
            $section = (string) ($link['section'] ?? '');
            $ref = trim((string) ($link['context_ref'] ?? ''));
            $repositoryLinks[] = [
                'section' => $section,
                'section_label' => $this->files->sectionLabel($section),
                'context_label' => $this->contextRefLabel($section, $ref),
                'created_at' => (string) ($link['created_at'] ?? ''),
            ];
        }
        $this->render('documents/show', [
            'title' => (string) ($doc['title'] ?? __('documents.title')),
            'document' => $doc,
            'minutes' => $this->documents->minutesMeta($doc),
            'category_label' => $this->documents->categoryLabel((string) ($doc['category'] ?? 'other')),
            'section_label' => $this->files->sectionLabel((string) ($doc['source_section'] ?? 'documents')),
            'repository_links' => $repositoryLinks,
            'signed_url' => $signed,
        ]);
    }

    /** Human label of a repository context (“minutes” → “Verbale”, “treasury_bank” → “Banca”). */
    private function contextRefLabel(string $section, string $ref): string
    {
        if ($ref === '') {
            return '';
        }
        $key = 'documents.source_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($ref));
        $label = (string) __($key);

        return $label !== $key ? $label : ucfirst(str_replace('_', ' ', $ref));
    }

    public function update(Request $request, string $id): void
    {
        require_component('documents');
        $existing = $this->documents->find((int) $id);
        if ($existing !== null && $this->minutesDocumentLocked($existing)) {
            $this->flash('errors', ['document' => __('documents.minutes_locked')]);
            redirect('/documents/' . (int) $id);
        }
        $file = $_FILES['document_file'] ?? null;
        $result = $this->documents->update(
            (int) $id,
            $request->all(),
            is_array($file) ? $file : null,
            $request->ip()
        );
        if (empty($result['ok'])) {
            $this->flash('errors', $result['errors'] ?? []);
            $this->rememberOld($request->all());
            redirect('/documents/' . (int) $id . '/edit');
        }
        $this->flash('success', __('documents.updated'));
        redirect('/documents');
    }

    public function destroy(Request $request, string $id): void
    {
        require_component('documents');
        $docId = (int) $id;
        $result = $this->documents->deleteDraft($docId, $request->ip());
        if (empty($result['ok'])) {
            $this->flash('errors', is_array($result['errors'] ?? null) ? $result['errors'] : []);
            redirect($this->documents->find($docId) !== null && $docId > 0
                ? '/documents/' . $docId
                : '/documents');
        }
        $this->flash('success', __('documents.deleted'));
        redirect('/documents');
    }

    public function upload(Request $request): void
    {
        require_component('documents');
        header('Content-Type: application/json; charset=utf-8');
        if (upload_post_too_large()) {
            http_response_code(413);
            echo json_encode([
                'ok' => false,
                'error' => __('documents.upload_too_large', ['max' => $this->documents->uploadMaxMb()]),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        $file = $_FILES['document_file'] ?? null;
        if (!is_array($file)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => __('documents.upload_required')], JSON_UNESCAPED_UNICODE);
            return;
        }
        $result = $this->documents->upload($file, [
            'section' => 'documents',
            'ref' => trim((string) $request->input('source_ref', '')),
        ]);
        if (empty($result['ok'])) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'error' => (string) ($result['error'] ?? __('documents.upload_fail')),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        echo json_encode([
            'ok' => true,
            'path' => $result['path'],
            'mime' => $result['mime'],
            'name' => $result['name'],
            'hash' => $result['hash'] ?? '',
            'message' => __('documents.upload_ok'),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request): void
    {
        require_component('documents');
        $file = $_FILES['document_file'] ?? null;
        $result = $this->documents->create(
            $request->all(),
            is_array($file) ? $file : null,
            $request->ip(),
            auth_user()['id'] ?? null
        );
        if (empty($result['ok'])) {
            $this->flash('errors', $result['errors'] ?? []);
            $this->rememberOld($request->all());
            redirect('/documents/create');
        }
        $this->flash('success', !empty($result['reused']) ? __('documents.reused_existing') : __('documents.saved'));
        redirect('/documents/' . (int) ($result['id'] ?? 0));
    }

    /**
     * Archive filters from the query string (validated).
     *
     * @return array{status:string,category:string,expiring:bool,section:string,year:int,uploader:string}
     */
    private function listFilters(Request $request): array
    {
        $status = trim((string) $request->input('status', ''));
        $expiring = $status === 'expiring';
        if ($expiring) {
            $status = '';
        } elseif (!in_array($status, DocumentService::STATUSES, true)) {
            $status = '';
        }
        $category = trim((string) $request->input('category', ''));
        if ($category !== '' && !in_array($category, array_column($this->documents->categoryOptions(), 'key'), true)) {
            $category = '';
        }
        $section = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim((string) $request->input('section', '')))) ?? '';
        if ($section !== '' && !in_array($section, $this->files->sections(), true)) {
            $section = '';
        }
        $year = (int) $request->input('year', '0');
        if ($year > 0 && !in_array($year, $this->files->years(), true)) {
            $year = 0;
        }
        $uploader = mb_substr(trim((string) $request->input('uploader', '')), 0, 120);

        return [
            'status' => $status,
            'category' => $category,
            'expiring' => $expiring,
            'section' => $section,
            'year' => $year,
            'uploader' => $uploader,
        ];
    }

    public function download(Request $request, string $id): void
    {
        $this->serveFile((int) $id, false);
    }

    public function forceDownload(Request $request, string $id): void
    {
        $this->serveFile((int) $id, true);
    }

    private function serveFile(int $id, bool $attachment): void
    {
        require_component('documents');
        $row = $this->files->fileInfo($id);
        $path = $this->files->absolutePath($id);
        if ($row === null || $path === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $this->files->registerDownload($id);
        $mime = trim((string) ($row['file_mime'] ?? '')) ?: (mime_content_type($path) ?: 'application/octet-stream');
        $name = trim((string) ($row['original_name'] ?? '')) ?: basename($path);
        $name = str_replace(['"', "\r", "\n"], '', $name);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) (filesize($path) ?: 0));
        readfile($path);
    }

    /**
     * Search the central repository for the “choose an already uploaded document” control
     * used by every section and by the add-ons.
     */
    public function picker(Request $request): void
    {
        require_component('documents');
        header('Content-Type: application/json; charset=utf-8');
        $section = trim((string) $request->input('section', ''));
        if ($section !== '' && !in_array($section, $this->files->sections(), true)) {
            $section = '';
        }
        $year = (int) $request->input('year', '0');
        $rows = $this->files->listForPicker(
            $section,
            trim((string) $request->input('q', '')),
            $year > 0 ? $year : null,
            60
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $out[] = [
                'id' => $id,
                'title' => (string) ($row['title'] ?? ''),
                'number' => (string) ($row['document_number'] ?? ''),
                'year' => (int) ($row['reference_year'] ?? 0),
                'date' => (string) ($row['document_date'] ?? ''),
                'section' => (string) ($row['source_section'] ?? 'documents'),
                'section_label' => $this->files->sectionLabel((string) ($row['source_section'] ?? 'documents')),
                'uploaded_by' => (string) ($row['uploaded_by_name'] ?? ''),
                'name' => (string) ($row['original_name'] ?? ''),
                'mime' => (string) ($row['file_mime'] ?? ''),
                'size' => (int) ($row['file_size'] ?? 0),
                'open_url' => url('/documents/' . $id . '/file'),
                'download_url' => url('/documents/' . $id . '/download'),
                'signed_url' => $this->files->signedUrl($id, 1800),
            ];
        }
        echo json_encode(['ok' => true, 'documents' => $out], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Compute hash/size/year for the files uploaded before the repository existed and merge
     * physical duplicates (same bytes, different copies).
     */
    public function reindex(Request $request): void
    {
        require_component('documents');
        $result = $this->files->backfill();
        app(\Socly\Services\AuditService::class)->log('document.repository_reindexed', 'document', null, null, $result, $request->ip());
        $this->flash('success', __('documents.reindex_done', [
            'scanned' => (string) $result['scanned'],
            'merged' => (string) $result['merged'],
            'pruned' => (string) ($result['pruned'] ?? 0),
        ]));
        redirect('/documents');
    }

    /**
     * Start regenerating missing bank-statement excerpts for ledger movements in the period of
     * the archived statement PDFs.
     */
    public function repairExcerptsStart(Request $request): void
    {
        require_component('documents');
        header('Content-Type: application/json; charset=utf-8');
        try {
            $service = $this->statementImportService();
            if ($service === null) {
                http_response_code(503);
                echo json_encode(['ok' => false, 'error' => __('documents.repair_excerpts_unavailable')], JSON_UNESCAPED_UNICODE);
                return;
            }
            $result = $service->startExcerptRepair($request->ip(), auth_user()['id'] ?? null);
            if (empty($result['ok'])) {
                $error = (string) ($result['error'] ?? 'empty');
                if ($error === 'resource_busy') {
                    $busyName = trim((string) ($result['busy_name'] ?? '')) ?: __('resource_busy.someone');
                    $op = (string) ($result['busy_operation'] ?? 'bank_statement_repair');
                    try {
                        $opLabel = app(\Socly\Services\ResourceBusyLock::class)->operationLabel($op);
                    } catch (\Throwable) {
                        $opLabel = __('resource_busy.operation_generic');
                    }
                    echo json_encode([
                        'ok' => false,
                        'error' => __('resource_busy.blocked_start', [
                            'name' => $busyName,
                            'operation' => $opLabel,
                        ]),
                        'resource_busy' => true,
                    ], JSON_UNESCAPED_UNICODE);
                    return;
                }
                echo json_encode([
                    'ok' => false,
                    'error' => $error === 'empty'
                        ? __('documents.repair_excerpts_synced')
                        : __('documents.repair_excerpts_unavailable'),
                    'synced' => $error === 'empty',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
            echo json_encode([
                'ok' => true,
                'job' => (string) ($result['job'] ?? ''),
                'total' => (int) ($result['total'] ?? 0),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            try {
                app('logger')->error('documents.repair_excerpts_start_failed', [
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => __('documents.repair_excerpts_unavailable'),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /** Advance one unit of the excerpt-repair job (polled by the documents overlay). */
    public function repairExcerptsTick(Request $request): void
    {
        require_component('documents');
        header('Content-Type: application/json; charset=utf-8');
        try {
            $service = $this->statementImportService();
            if ($service === null) {
                http_response_code(503);
                echo json_encode(['ok' => false, 'finished' => true, 'error' => __('documents.repair_excerpts_unavailable')], JSON_UNESCAPED_UNICODE);
                return;
            }
            $job = trim((string) $request->input('job', ''));
            $progress = $service->tickExcerptRepair($job);
            echo json_encode($progress, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            try {
                app('logger')->error('documents.repair_excerpts_tick_failed', [
                    'error' => $e->getMessage(),
                    'job' => trim((string) $request->input('job', '')),
                ]);
            } catch (\Throwable) {
            }
            // Keep finished=false so the browser can retry the same job instead of aborting.
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'finished' => false,
                'retryable' => true,
                'current' => 0,
                'total' => 0,
                'stored' => 0,
                'failed' => 0,
                'eta_seconds' => 0,
                'error' => __('documents.repair_excerpts_retry'),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function repairExcerptsStatus(Request $request): void
    {
        require_component('documents');
        header('Content-Type: application/json; charset=utf-8');
        $service = $this->statementImportService();
        if ($service === null) {
            echo json_encode(['ok' => true, 'finished' => true, 'current' => 0, 'total' => 0, 'stored' => 0, 'failed' => 0, 'eta_seconds' => 0], JSON_UNESCAPED_UNICODE);
            return;
        }
        $job = trim((string) $request->input('job', ''));
        echo json_encode($service->excerptRepairStatus($job), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{available:bool,missing:int,statements:int}
     */
    private function excerptRepairSummary(): array
    {
        $service = $this->statementImportService();
        if ($service === null) {
            return ['available' => false, 'missing' => 0, 'statements' => 0];
        }
        try {
            return $service->missingExcerptsSummary();
        } catch (\Throwable) {
            return ['available' => false, 'missing' => 0, 'statements' => 0];
        }
    }

    private function statementImportService(): ?object
    {
        if (!class_exists(\Socly\Addons\BankStatementImport\StatementImportService::class)) {
            return null;
        }
        try {
            $service = app(\Socly\Addons\BankStatementImport\StatementImportService::class);
        } catch (\Throwable) {
            return null;
        }

        return is_object($service) ? $service : null;
    }
}
