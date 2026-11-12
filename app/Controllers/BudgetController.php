<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\BudgetService;
use Socly\Services\CurrencyService;
use Socly\Services\RuntsReportService;
use Socly\Services\SettingsService;
use Socly\Services\TreasuryService;
use Socly\Services\VersionService;

/**
 * Budget estimate for the financial year + comparison with the final cash balance.
 */
final class BudgetController extends BaseController
{
    public function __construct(
        View $view,
        private readonly BudgetService $budgets,
        private readonly TreasuryService $treasury,
        private readonly CurrencyService $currency,
        private readonly SettingsService $settings,
        private readonly RuntsReportService $runts,
        private readonly VersionService $versions
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        require_component('treasury');
        $currentYear = (int) date('Y');
        $budgets = $this->budgets->listDocuments();
        // One budget per social year, and one open draft at a time: until the existing draft is
        // completed (approved) or deleted, the page does not propose creating another one.
        $drafts = array_values(array_filter(
            $budgets,
            static fn (array $row): bool => (string) ($row['status'] ?? '') !== BudgetService::STATUS_APPROVED
        ));
        $years = $this->budgets->creatableYears();
        $defaultYear = in_array($currentYear + 1, $years, true) ? $currentYear + 1 : ($years[0] ?? $currentYear + 1);
        $rendicontoYears = [];
        foreach ($this->runts->years() as $year) {
            $income = $this->runts->totalIncome($year);
            $rendicontoYears[] = [
                'year' => $year,
                'income' => $income,
                'can_generate' => $income < RuntsReportService::CASH_LIMIT,
            ];
        }
        $this->render('budgets/index', [
            'title' => __('budget.title'),
            'budgets' => $budgets,
            'drafts' => $drafts,
            'years' => $years,
            'default_year' => $defaultYear,
            'rendiconto_years' => $rendicontoYears,
            'runts_limit' => RuntsReportService::CASH_LIMIT,
            'statistics' => $this->budgets->statistics(),
            'currency' => $this->currency,
        ]);
    }

    public function store(Request $request): void
    {
        require_component('treasury');
        $year = (int) $request->input('fiscal_year', date('Y'));
        $template = (string) $request->input('template', 'empty');
        $coefficient = BudgetService::parseAmount((string) $request->input('coefficient', '0'));
        $title = trim((string) $request->input('title', ''));
        $csvFile = $request->file('csv_file');
        $importCsv = $template === 'csv_import';
        if ($importCsv) {
            $template = 'empty';
        }
        $result = $this->budgets->create($year, $template, $coefficient, $title, $request->ip(), $this->userId());
        if (empty($result['ok'])) {
            $this->flash('errors', [
                'fiscal_year' => ($result['error'] ?? '') === 'exists'
                    ? __('budget.error_exists', ['year' => (string) $year])
                    : __('validation.in'),
            ]);
            redirect('/budgets');
        }
        $budgetId = (int) $result['id'];
        if ($importCsv) {
            $importError = $this->importCsvInto($budgetId, $csvFile);
            if ($importError !== '') {
                $this->budgets->delete($budgetId, $request->ip());
                $this->flash('errors', ['csv_file' => $importError]);
                redirect('/budgets');
            }
        }
        $this->flash('success', __('budget.created'));
        redirect('/budgets/' . $budgetId);
    }

    /**
     * Import the uploaded CSV; returns an error message ('' = success).
     *
     * @param array<string,mixed>|null $file
     */
    private function importCsvInto(int $budgetId, ?array $file): string
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return (string) __('budget.csv_upload_missing');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size > 2 * 1024 * 1024) {
            return (string) __('budget.csv_too_large');
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            return (string) __('budget.csv_error');
        }
        $path = (string) ($file['tmp_name'] ?? '');
        $result = $this->budgets->importCsv($budgetId, $path);
        if (empty($result['ok'])) {
            return (string) __('budget.csv_error');
        }
        $this->flash('success', __('budget.csv_imported', ['count' => (string) ($result['imported'] ?? 0)]));
        return '';
    }

    public function show(Request $request, string $id): void
    {
        require_component('treasury');
        $budget = $this->budgets->find((int) $id);
        if ($budget === null) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $conflictVersion = (int) $request->input('v', 0);
        $conflictActive = (string) $request->input('conflict', '') !== '' && $conflictVersion > 0;
        $conflict = null;
        if ($conflictActive) {
            $editor = $this->versions->lastEditor('budget', (int) $budget['id']);
            $conflict = [
                'user' => $editor['name'],
                'at' => $editor['at'],
                'current' => $conflictVersion,
                'reload_url' => url('/budgets/' . (int) $budget['id']),
            ];
        }
        $this->render('budgets/show', [
            'title' => __('budget.title_year', ['year' => (string) $budget['fiscal_year']]),
            'budget' => $budget,
            'budget_version' => $conflictActive ? $conflictVersion : (int) ($budget['row_version'] ?? 0),
            'budget_conflict' => $conflict,
            'categories' => $this->treasury->categoryOptions(),
            // Same taxonomy as movements (Income / Expenses / Financial / Custom).
            'category_groups' => $this->treasury->categorySelectGroups(false),
            'currency' => $this->currency,
            'canManage' => can('treasury.manage'),
        ]);
    }

    public function save(Request $request, string $id): void
    {
        require_component('treasury');
        $posted = $request->all();
        $result = $this->budgets->saveGrid(
            (int) $id,
            $posted,
            $request->ip(),
            (int) ($posted['row_version'] ?? 0),
            !empty($posted['force'])
        );
        $wantsJson = strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest'
            || str_contains(strtolower((string) $request->header('Accept')), 'application/json');
        if (empty($result['ok'])) {
            if (!empty($result['conflict'])) {
                $payload = [
                    'ok' => false,
                    'conflict' => true,
                    'version' => (int) ($result['current_version'] ?? 0),
                    'editor' => $result['editor'] ?? ['name' => '', 'at' => ''],
                    'message' => (string) __('concurrency.conflict_flash', [
                        'user' => (string) ($result['editor']['name'] ?? ''),
                        'at' => (string) ($result['editor']['at'] ?? ''),
                    ]),
                    'messages' => [
                        'stale_title' => (string) __('concurrency.stale_title'),
                        'reload' => (string) __('concurrency.reload'),
                    ],
                ];
                if ($wantsJson) {
                    $this->jsonResponse($payload, 409);
                    return;
                }
                $this->flash('errors', ['budget' => $payload['message']]);
                redirect('/budgets/' . (int) $id . '?conflict=1&v=' . (int) ($result['current_version'] ?? 0));
            }
            $message = trim((string) ($result['message'] ?? ''));
            $message = $message !== '' ? $message : (string) __('budget.save_failed');
            if ($wantsJson) {
                $this->jsonResponse(['ok' => false, 'message' => $message], 422);
                return;
            }
            $this->flash('errors', ['budget' => $message]);
        } elseif ($wantsJson) {
            // Autosave: the page needs the new row/section ids, otherwise the next save would
            // re-insert the freshly created lines.
            $this->jsonResponse([
                'ok' => true,
                'message' => (string) __('budget.saved'),
                'sections' => $result['sections'] ?? [],
                'items' => $result['items'] ?? [],
                'row_version' => (int) ($result['row_version'] ?? 0),
            ]);
            return;
        } else {
            $this->flash('success', __('budget.saved'));
        }
        redirect('/budgets/' . (int) $id);
    }

    public function approve(Request $request, string $id): void
    {
        require_component('treasury');
        $this->budgets->approve((int) $id, $request->ip(), $this->userId());
        $this->flash('success', __('budget.approved'));
        redirect('/budgets/' . (int) $id);
    }

    public function reopen(Request $request, string $id): void
    {
        require_component('treasury');
        $this->budgets->reopen((int) $id, $request->ip());
        $this->flash('success', __('budget.reopened'));
        redirect('/budgets/' . (int) $id);
    }

    public function destroy(Request $request, string $id): void
    {
        require_component('treasury');
        $result = $this->budgets->delete((int) $id, $request->ip());
        if (empty($result['ok'])) {
            $this->flash('errors', ['budget' => __('errors.404')]);
            redirect('/budgets');
        }
        $this->flash('success', __('budget.deleted'));
        redirect('/budgets');
    }

    /**
     * Rename a shared category (pencil in budget grid). Response JSON: {ok, label} or {ok:false,
     * error}.
     */
    public function renameCategory(Request $request): void
    {
        require_component('treasury');
        if (!can('treasury.manage')) {
            $this->jsonResponse(['ok' => false, 'error' => __('errors.403')], 403);
            return;
        }
        $result = $this->treasury->renameCategory(
            (string) $request->input('slug', ''),
            (string) $request->input('label', '')
        );
        if (empty($result['ok'])) {
            $this->jsonResponse(['ok' => false, 'error' => (string) ($result['error'] ?? __('validation.required'))], 422);
            return;
        }
        $this->jsonResponse(['ok' => true, 'label' => (string) ($result['label'] ?? '')]);
    }

    public function compare(Request $request, string $id): void
    {
        require_component('treasury');
        $comparison = $this->budgets->comparison((int) $id);
        if ($comparison === []) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $this->render('budgets/compare', [
            'title' => __('budget.compare_title', ['year' => (string) $comparison['budget']['fiscal_year']]),
            'comparison' => $comparison,
            'category_labels' => $this->treasury->categoryMap(),
            'currency' => $this->currency,
        ]);
    }

    public function rendiconto(Request $request, string $id): void
    {
        require_component('treasury');
        $comparison = $this->budgets->comparison((int) $id);
        if ($comparison === []) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $branding = app()->branding();
        $legalCode = strtoupper(trim((string) ($branding['legal_name'] ?? '')));
        $legalLabel = '';
        if ($legalCode !== '') {
            $forms = \Socly\Setup\AssociationLegalForms::all();
            if (isset($forms[$legalCode])) {
                $legalLabel = (string) __($forms[$legalCode]);
            }
        }
        $this->render('budgets/rendiconto', [
            'comparison' => $comparison,
            'evidence' => $this->budgets->evidence((int) $id),
            'currency' => $this->currency,
            'assocName' => assoc_capitalize_name((string) ($branding['name'] ?? 'SOCLY')),
            'assocLegalCode' => $legalCode,
            'assocLegalLabel' => $legalLabel,
            'assocFiscalCode' => trim((string) $this->settings->get('association.fiscal_code', '')),
            'assocCity' => trim((string) $this->settings->get('association.city', '')),
            'brandPrimary' => (string) ($branding['primary'] ?? ''),
            'brandAccent' => (string) ($branding['accent'] ?? ''),
            'generatedAt' => date('d/m/Y H:i'),
        ], null);
    }

    private function userId(): ?int
    {
        $id = (int) (auth_user()['id'] ?? 0);
        return $id > 0 ? $id : null;
    }
}
