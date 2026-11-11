<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\TreasuryCsvService;
use Socly\Services\TreasuryService;

/**
 * Import/export CSV della prima nota (stesso impianto dell'import soci: upload, mappatura
 * automatica delle colonne, anteprima e conferma).
 */
final class TreasuryCsvController extends BaseController
{
    private const SESSION_KEY = 'treasury_csv_import';

    public function __construct(
        View $view,
        private readonly TreasuryService $treasury,
        private readonly TreasuryCsvService $csv
    ) {
        parent::__construct($view);
    }

    public function export(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.view')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $filters = [
            'direction' => in_array((string) $request->input('direction', ''), TreasuryService::DIRECTIONS, true)
                ? (string) $request->input('direction', '')
                : '',
            'category' => (string) $request->input('category', ''),
            'account' => TreasuryService::normalizeAccount((string) $request->input('account', '')),
            'approval' => in_array((string) $request->input('approval', ''), ['pending', 'approved'], true)
                ? (string) $request->input('approval', '')
                : '',
            'from' => (string) $request->input('from', ''),
            'to' => (string) $request->input('to', ''),
        ];
        $rows = $this->csv->exportRows(trim((string) $request->input('q', '')), $filters);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="prima-nota-' . date('Ymd') . '.csv"');
        header('Cache-Control: no-store');
        echo $this->csv->toCsv($rows);
    }

    public function template(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="prima-nota-modello.csv"');
        echo $this->csv->templateCsv();
    }

    public function importForm(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $preview = null;
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($state) && is_file((string) ($state['path'] ?? ''))) {
            $parsed = $this->csv->parse((string) $state['path']);
            $preview = [
                'headers' => $parsed['headers'],
                'map' => $parsed['map'],
                'unmapped' => $parsed['unmapped'],
                'rows' => array_slice($parsed['rows'], 0, 25),
                'total' => count($parsed['rows']),
            ];
        }
        $this->render('treasury/import', [
            'title' => __('treasury.csv_import_title'),
            'preview' => $preview,
            'fields' => $this->csv->headers(),
        ]);
    }

    public function upload(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $file = $request->file('csv_file');
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flash('errors', ['csv_file' => __('treasury.csv_error_upload')]);
            redirect('/treasury/import');
        }
        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            $this->flash('errors', ['csv_file' => __('treasury.csv_error_type')]);
            redirect('/treasury/import');
        }
        $target = storage_path('cache/treasury_import_' . bin2hex(random_bytes(6)) . '.csv');
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            $this->flash('errors', ['csv_file' => __('treasury.csv_error_upload')]);
            redirect('/treasury/import');
        }
        unset($_SESSION[self::SESSION_KEY]);
        $_SESSION[self::SESSION_KEY] = ['path' => $target, 'created_at' => time()];
        redirect('/treasury/import');
    }

    public function commit(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($state) || !is_file((string) ($state['path'] ?? ''))) {
            $this->flash('errors', ['csv_file' => __('treasury.csv_error_no_file')]);
            redirect('/treasury/import');
        }
        $parsed = $this->csv->parse((string) $state['path']);
        $result = $this->csv->importRows($parsed['rows'], $parsed['map'], $request->ip(), auth_user()['id'] ?? null);
        @unlink((string) $state['path']);
        unset($_SESSION[self::SESSION_KEY]);
        if ($result['errors'] !== []) {
            $this->flash('errors', ['csv' => implode(' ', array_slice($result['errors'], 0, 5))]);
        }
        $this->flash('success', __('treasury.csv_import_done', [
            'created' => (string) $result['created'],
            'skipped' => (string) $result['skipped'],
        ]));
        redirect('/treasury');
    }

    public function cancel(Request $request): void
    {
        require_component('treasury');
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($state) && is_file((string) ($state['path'] ?? ''))) {
            @unlink((string) $state['path']);
        }
        unset($_SESSION[self::SESSION_KEY]);
        redirect('/treasury/import');
    }
}
