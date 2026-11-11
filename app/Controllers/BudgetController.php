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

/**
 * Bilancio preventivo dell'anno sociale + confronto con il consuntivo di cassa.
 */
final class BudgetController extends BaseController
{
    public function __construct(
        View $view,
        private readonly BudgetService $budgets,
        private readonly TreasuryService $treasury,
        private readonly CurrencyService $currency,
        private readonly SettingsService $settings,
        private readonly RuntsReportService $runts
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
        $this->render('budgets/show', [
            'title' => __('budget.title_year', ['year' => (string) $budget['fiscal_year']]),
            'budget' => $budget,
            'categories' => $this->treasury->categoryOptions(),
            // Stessa tassonomia dei movimenti (Entrate / Uscite / Finanziarie / Personalizzate).
            'category_groups' => $this->treasury->categorySelectGroups(false),
            'currency' => $this->currency,
            'canManage' => can('treasury.manage'),
        ]);
    }

    public function save(Request $request, string $id): void
    {
        require_component('treasury');
        $result = $this->budgets->saveGrid((int) $id, $request->all(), $request->ip());
        $wantsJson = strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest'
            || str_contains(strtolower((string) $request->header('Accept')), 'application/json');
        if (empty($result['ok'])) {
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
