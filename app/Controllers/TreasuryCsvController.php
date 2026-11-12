<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\CurrencyService;
use Socly\Services\TreasuryCsvService;
use Socly\Services\TreasuryService;

/**
 * Import/export CSV of the first note.
 * The import follows the same flow as the member import: upload → column mapping → value
 * normalisation → dry run → final report. Only the confirmation writes to the first note.
 */
final class TreasuryCsvController extends BaseController
{
    public function __construct(
        View $view,
        private readonly TreasuryService $treasury,
        private readonly TreasuryCsvService $csv
    ) {
        parent::__construct($view);
    }

    public function export(Request $request): void
    {
        if (!$this->ensureAccess('treasury.view')) {
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
            'month' => (string) max(0, min(12, (int) $request->input('month', 0))),
            'year' => (int) $request->input('year', 0) >= 1900 && (int) $request->input('year', 0) <= 2200
                ? (string) (int) $request->input('year', 0)
                : '',
        ];
        $rows = $this->csv->exportRows(trim((string) $request->input('q', '')), $filters);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="prima-nota-' . date('Ymd') . '.csv"');
        header('Cache-Control: no-store');
        echo $this->csv->toCsv($rows);
    }

    public function template(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="prima-nota-modello.csv"');
        echo $this->csv->templateCsv();
    }

    public function index(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $this->render('treasury/import/upload', [
            'title' => __('treasury.import.title'),
            'state' => $this->csv->state(),
            'maxMegabytes' => 5,
        ]);
    }

    public function upload(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $result = $this->csv->startFromUpload($request->file('csv_file'));
        if (empty($result['ok'])) {
            $this->flash('errors', ['csv_file' => __((string) ($result['error'] ?? 'treasury.import.error_missing'))]);
            redirect('/treasury/import');
        }
        redirect('/treasury/import/map');
    }

    public function map(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $table = $this->csv->table();
        if (empty($table['ok'])) {
            $this->flash('errors', ['csv_file' => __((string) ($table['error'] ?? 'treasury.import.error_no_file'))]);
            redirect('/treasury/import');
        }
        $state = $this->csv->state() ?? [];
        $this->render('treasury/import/map', [
            'title' => __('treasury.import.step_map_title'),
            'table' => $table,
            'columns' => $this->csv->columns($table),
            'targetGroups' => $this->csv->targetGroups(),
            'map' => $this->csv->mappedTargets(),
            'options' => $this->csv->options(),
            'accounts' => $this->treasury->accountOptions(false),
            'categories' => $this->treasury->categoryOptions(),
            'methodGroups' => $this->methodGroups(),
            'sourceName' => (string) ($state['source_name'] ?? ''),
        ]);
    }

    public function header(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $this->csv->setHasHeader((string) $request->input('has_header', '0') === '1');
        redirect('/treasury/import/map');
    }

    public function saveMap(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $rawMap = $request->input('map', []);
        $result = $this->csv->saveMapping(is_array($rawMap) ? $rawMap : [], $request->all());
        if (empty($result['ok'])) {
            $errorKey = (string) ($result['error'] ?? 'treasury.import.error_need_field');
            $this->flash('errors', ['map' => __($errorKey, ['field' => (string) ($result['field'] ?? '')])]);
            redirect('/treasury/import/map');
        }
        redirect('/treasury/import/normalize');
    }

    public function normalize(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $normalization = $this->csv->normalization();
        if (empty($normalization['ok'])) {
            $this->flash('errors', ['csv_file' => __((string) ($normalization['error'] ?? 'treasury.import.error_no_mapping'))]);
            redirect('/treasury/import/map');
        }
        $state = $this->csv->state() ?? [];
        $this->render('treasury/import/normalize', [
            'title' => __('treasury.import.step_normalize_title'),
            'fields' => $normalization['fields'],
            'valueMap' => $this->csv->valueMap(),
            'memberOptions' => $this->csv->memberChoices(),
            'sourceName' => (string) ($state['source_name'] ?? ''),
        ]);
    }

    public function saveNormalize(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $rawValues = $request->input('value_raw', []);
        $targets = $request->input('value_map', []);
        $result = $this->csv->saveNormalization(
            is_array($rawValues) ? $rawValues : [],
            is_array($targets) ? $targets : []
        );
        if (empty($result['ok'])) {
            $this->flash('errors', ['normalize' => __((string) ($result['error'] ?? 'treasury.import.error_no_mapping'))]);
            redirect('/treasury/import/normalize');
        }
        redirect('/treasury/import/preview');
    }

    public function preview(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $analysis = $this->csv->analyze();
        if (empty($analysis['ok'])) {
            $this->flash('errors', ['csv_file' => __((string) ($analysis['error'] ?? 'treasury.import.error_no_mapping'))]);
            redirect('/treasury/import/map');
        }
        $state = $this->csv->state() ?? [];
        $this->render('treasury/import/preview', [
            'title' => __('treasury.import.step_preview_title'),
            'analysis' => $analysis,
            'sourceName' => (string) ($state['source_name'] ?? ''),
            'currency' => app(CurrencyService::class),
            'accounts' => $this->treasury->accountOptions(false),
        ]);
    }

    /**
     * Manual corrections, ignored rows, duplicates to include and recurring choices of the dry run.
     */
    public function saveRows(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $result = $this->csv->saveRowChoices($request->all());
        if (empty($result['ok'])) {
            $this->flash('errors', ['import' => __((string) ($result['error'] ?? 'treasury.import.error_no_file'))]);
            redirect('/treasury/import');
        }
        $this->flash('success', __('treasury.import.rows_saved'));
        redirect('/treasury/import/preview');
    }

    public function check(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $analysis = $this->csv->analyze(0);
        if (empty($analysis['ok'])) {
            $this->flash('errors', ['csv_file' => __((string) ($analysis['error'] ?? 'treasury.import.error_no_mapping'))]);
            redirect('/treasury/import/map');
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="prima-nota-test-' . date('Ymd') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }
        fputcsv($out, ['line', 'action', 'date', 'direction', 'amount', 'account', 'category', 'description', 'document_type', 'document_number', 'document_date', 'errors', 'warnings'], ';');
        foreach (($analysis['rows'] ?? []) as $row) {
            fputcsv($out, [
                (int) ($row['line'] ?? 0),
                (string) ($row['action'] ?? ''),
                (string) ($row['date'] ?? ''),
                (string) ($row['direction'] ?? ''),
                number_format((float) ($row['amount'] ?? 0), 2, ',', ''),
                (string) ($row['account'] ?? ''),
                (string) ($row['category'] ?? ''),
                (string) ($row['description'] ?? ''),
                (string) ($row['document_type'] ?? ''),
                (string) ($row['invoice_number'] ?? ''),
                (string) ($row['invoice_date'] ?? ''),
                implode(' | ', array_map('strval', (array) ($row['errors'] ?? []))),
                implode(' | ', array_map('strval', (array) ($row['warnings'] ?? []))),
            ], ';');
        }
        fclose($out);
        exit;
    }

    public function commit(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $analysis = $this->csv->analyze(0);
        if (empty($analysis['ok'])) {
            $this->flash('errors', ['import' => __((string) ($analysis['error'] ?? 'treasury.import.error_no_mapping'))]);
            redirect('/treasury/import/map');
        }
        if ((int) ($analysis['stats']['error'] ?? 0) > 0 && (string) $request->input('confirm_errors', '0') !== '1') {
            $this->flash('errors', ['import' => __('treasury.import.confirm_errors_required')]);
            redirect('/treasury/import/preview');
        }
        $result = $this->csv->commit($request->ip(), auth_user()['id'] ?? null);
        if (empty($result['ok'])) {
            $this->flash('errors', ['import' => __((string) ($result['error'] ?? 'treasury.import.error_no_mapping'))]);
            redirect('/treasury/import/map');
        }
        $report = is_array($result['report'] ?? null) ? $result['report'] : [];
        $this->flash('success', __('treasury.import.done', [
            'created' => (string) (int) ($report['created'] ?? 0),
            'skipped' => (string) (int) ($report['skipped'] ?? 0),
            'ignored' => (string) (int) ($report['ignored'] ?? 0),
            'failed' => (string) (int) ($report['failed'] ?? 0),
        ]));
        redirect('/treasury/import/report');
    }

    public function report(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $report = $this->csv->lastReport();
        if ($report === []) {
            redirect('/treasury');
        }
        $this->render('treasury/import/report', [
            'title' => __('treasury.import.report_title'),
            'report' => $report,
        ]);
    }

    public function errorsCsv(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="prima-nota-errori-' . date('Ymd') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }
        fputcsv($out, ['line', 'description', 'message'], ';');
        foreach ($this->csv->errorRows() as $row) {
            fputcsv($out, [
                (int) ($row['line'] ?? 0),
                (string) ($row['description'] ?? ''),
                (string) ($row['message'] ?? ''),
            ], ';');
        }
        fclose($out);
        exit;
    }

    public function cancel(Request $request): void
    {
        if (!$this->ensureAccess()) {
            return;
        }
        $this->csv->reset();
        redirect('/treasury/import');
    }

    /** @return list<array{label:string,options:list<array{value:string,label:string}>}> */
    private function methodGroups(): array
    {
        $groups = [];
        foreach ($this->treasury->paymentMethodGroups() as $group) {
            $options = [];
            foreach ((array) ($group['keys'] ?? []) as $key) {
                $options[] = [
                    'value' => (string) $key,
                    'label' => (string) __('treasury.method_' . $key),
                ];
            }
            if ($options !== []) {
                $groups[] = ['label' => (string) ($group['label'] ?? ''), 'options' => $options];
            }
        }

        return $groups;
    }

    private function ensureAccess(string $permission = 'treasury.manage'): bool
    {
        require_component('treasury');
        if (!can($permission)) {
            http_response_code(403);
            $this->render('errors/403');
            return false;
        }

        return true;
    }
}