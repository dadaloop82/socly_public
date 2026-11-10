<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\MemberImportService;
use Socly\Services\MemberService;
use Socly\Support\MemberFieldTypes;

/**
 * Import soci da CSV: upload → mappatura → anteprima → riferimenti mancanti → report.
 */
final class MemberImportController extends BaseController
{
    public function __construct(
        View $view,
        private readonly MemberImportService $import,
        private readonly MemberService $members
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        require_component('members');
        $this->render('members/import/upload', [
            'title' => __('members.import.title'),
            'state' => $this->import->state(),
            'maxMegabytes' => 5,
        ]);
    }

    public function upload(Request $request): void
    {
        require_component('members');
        $result = $this->import->startFromUpload($request->file('csv_file'));
        if (empty($result['ok'])) {
            $this->flash('errors', ['csv_file' => __((string) ($result['error'] ?? 'members.import.error_missing'))]);
            redirect('/members/import');
        }
        redirect('/members/import/map');
    }

    public function map(Request $request): void
    {
        require_component('members');
        $table = $this->import->table();
        if (empty($table['ok'])) {
            $this->flash('errors', ['csv_file' => __((string) ($table['error'] ?? 'members.import.error_no_file'))]);
            redirect('/members/import');
        }
        $state = $this->import->state() ?? [];
        $this->render('members/import/map', [
            'title' => __('members.import.step_map_title'),
            'table' => $table,
            'columns' => $this->import->columns($table),
            'targetGroups' => $this->import->targetGroups(),
            'map' => $this->import->mappedTargets(),
            'options' => $this->import->options(),
            'types' => $this->members->types(false),
            'periods' => $this->members->periods(),
            'statuses' => MemberService::memberStatuses(),
            'sourceName' => (string) ($state['source_name'] ?? ''),
            'profileApplied' => $this->import->profileApplied(),
        ]);
    }

    public function saveMap(Request $request): void
    {
        require_component('members');
        $rawMap = $request->input('map', []);
        $result = $this->import->saveMapping(is_array($rawMap) ? $rawMap : [], $request->all());
        if (empty($result['ok'])) {
            $errorKey = (string) ($result['error'] ?? 'members.import.error_need_identity');
            $this->flash('errors', ['map' => __($errorKey, ['field' => (string) ($result['field'] ?? '')])]);
            redirect('/members/import/map');
        }
        redirect('/members/import/preview');
    }

    public function preview(Request $request): void
    {
        require_component('members');
        $analysis = $this->import->analyze();
        if (empty($analysis['ok'])) {
            $this->flash('errors', ['csv_file' => __((string) ($analysis['error'] ?? 'members.import.error_no_mapping'))]);
            redirect('/members/import/map');
        }
        $this->render('members/import/preview', [
            'title' => __('members.import.step_preview_title'),
            'analysis' => $analysis,
        ]);
    }

    public function references(Request $request): void
    {
        require_component('members');
        $analysis = $this->import->analyze();
        if (empty($analysis['ok'])) {
            $this->flash('errors', ['csv_file' => __((string) ($analysis['error'] ?? 'members.import.error_no_mapping'))]);
            redirect('/members/import');
        }
        $formSteps = [];
        foreach ($this->members->formSteps() as $step) {
            $key = (string) ($step['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $label = localized($step['title_json'] ?? '');
            $formSteps[] = ['key' => $key, 'label' => $label !== '' ? $label : $key];
        }
        $fieldTypes = [];
        foreach (MemberFieldTypes::keys() as $type) {
            $fieldTypes[] = ['value' => $type, 'label' => MemberFieldTypes::label($type)];
        }
        $this->render('members/import/references', [
            'title' => __('members.import.step_references_title'),
            'analysis' => $analysis,
            'types' => $analysis['types'],
            'periods' => $analysis['periods'],
            'formSteps' => $formSteps,
            'fieldTypes' => $fieldTypes,
        ]);
    }

    public function commit(Request $request): void
    {
        require_component('members');
        $result = $this->import->commit($request->all(), $request->ip());
        if (empty($result['ok'])) {
            $this->flash('errors', ['import' => __((string) ($result['error'] ?? 'members.import.err_references'))]);
            redirect('/members/import/references');
        }
        $this->flash('success', __('members.import.done'));
        redirect('/members/import/report');
    }

    public function report(Request $request): void
    {
        require_component('members');
        $report = $this->import->lastReport();
        if ($report === []) {
            redirect('/members');
        }
        $this->render('members/import/report', [
            'title' => __('members.import.report_title'),
            'report' => $report,
        ]);
    }

    public function errorsCsv(Request $request): void
    {
        require_component('members');
        $report = $this->import->lastReport();
        $rows = $this->import->errorRows((int) ($report['run_id'] ?? 0));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="import-errors-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            return;
        }
        fputcsv($out, ['line', 'message', 'values', 'errors']);
        foreach ($rows as $row) {
            fputcsv($out, [
                (int) ($row['line_no'] ?? 0),
                (string) ($row['message'] ?? ''),
                (string) ($row['payload_json'] ?? ''),
                (string) ($row['errors_json'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }

    public function cancel(Request $request): void
    {
        require_component('members');
        $this->import->reset();
        redirect('/members');
    }

    public function header(Request $request): void
    {
        require_component('members');
        $this->import->setHasHeader((string) $request->input('has_header', '0') === '1');
        redirect('/members/import/map');
    }
}
