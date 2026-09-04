<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\DocumentService;
use Socly\Services\MemberService;

final class DocumentsController extends BaseController
{
    public function __construct(
        View $view,
        private readonly DocumentService $documents,
        private readonly MemberService $members
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
        $this->render('documents/index', [
            'title' => __('documents.title'),
            'documents' => $this->documents->all(200, $query),
            'categories' => $this->documents->categoryOptions(),
            'category_groups' => $this->documents->categoryGroupedOptions(),
            'default_category' => $this->documents->defaultCategory(),
            'languages' => DocumentService::LANGUAGES,
            'upload_max_mb' => $this->documents->uploadMaxMb(),
            'members' => $this->members->listForSelect(),
            'sibling_options' => $this->documents->all(100),
            'search_query' => $query,
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
        $this->render('documents/edit', [
            'title' => __('documents.edit'),
            'document' => $doc,
            'categories' => $categories,
            'category_groups' => $this->documents->categoryGroupedOptions(),
            'languages' => DocumentService::LANGUAGES,
            'upload_max_mb' => $this->documents->uploadMaxMb(),
            'existing_file_name' => $filePath !== '' ? basename($filePath) : '',
            'members' => $this->members->listForSelect(),
            'sibling_options' => $siblings,
            'document_id' => (int) $id,
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
        $this->render('documents/show', [
            'title' => (string) ($doc['title'] ?? __('documents.title')),
            'document' => $doc,
            'category_label' => $this->documents->categoryLabel((string) ($doc['category'] ?? 'other')),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        require_component('documents');
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
        $result = $this->documents->upload($file);
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
            redirect('/documents');
        }
        $this->flash('success', __('documents.saved'));
        redirect('/documents');
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
        $path = $this->documents->filePath($id);
        if ($path === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="' . basename($path) . '"');
        readfile($path);
    }
}
