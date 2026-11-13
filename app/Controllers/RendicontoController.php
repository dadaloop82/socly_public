<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\CurrencyService;
use Socly\Services\RuntsReportService;
use Socly\Services\RuntsXlsxService;
use Socly\Services\SettingsService;

/**
 * Final balance sheet - Cash flow statement (Form D, Ministerial Decree 18 April 2020).
 * ETSs under €220,000 in revenue draw up the cash flow statement: the page generates the
 * ministerial model from the approved cash book movements. Above threshold the accrual models
 * (A/B/C) are not yet generated: the page says so explicitly.
 */
final class RendicontoController extends BaseController
{
    public function __construct(
        View $view,
        private readonly RuntsReportService $runts,
        private readonly RuntsXlsxService $xlsx,
        private readonly CurrencyService $currency,
        private readonly SettingsService $settings
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        require_component('treasury');
        // The final balance now lives in the “Final” tab on the Financial Statements page.
        redirect('/budgets#bil-consuntivo');
    }

    public function show(Request $request, string $year): void
    {
        require_component('treasury');
        $year = (int) $year;
        if ($year < 2000 || $year > 2100) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $income = $this->runts->totalIncome($year);
        $canGenerate = $income < RuntsReportService::CASH_LIMIT;
        $branding = app()->branding();
        $legalCode = strtoupper(trim((string) ($branding['legal_name'] ?? '')));
        $legalLabel = '';
        if ($legalCode !== '') {
            $forms = \Socly\Setup\AssociationLegalForms::all();
            if (isset($forms[$legalCode])) {
                $legalLabel = (string) __($forms[$legalCode]);
            }
        }
        $this->render('rendiconto/modello_d', [
            'report' => $canGenerate ? $this->runts->report($year) : null,
            'year' => $year,
            'income' => $income,
            'limit' => RuntsReportService::CASH_LIMIT,
            'can_generate' => $canGenerate,
            'currency' => $this->currency,
            'assocName' => assoc_capitalize_name((string) ($branding['name'] ?? 'SOCLY')),
            'assocLegalCode' => $legalCode,
            'assocLegalLabel' => $legalLabel,
            'assocFiscalCode' => trim((string) $this->settings->get('association.fiscal_code', '')),
            'assocAddress' => trim((string) ($this->settings->get('association.address_full', '')
                ?: $this->settings->get('association.address', ''))),
            'assocCity' => trim((string) $this->settings->get('association.city', '')),
            'brandPrimary' => (string) ($branding['primary'] ?? ''),
            'brandAccent' => (string) ($branding['accent'] ?? ''),
            'generatedAt' => date('d/m/Y H:i'),
        ], null);
    }

    /**
     * Official Model D workbook (CSV Emilia / Ministry editable form), pre-filled with the
     * amounts of the approved cash book for the year and the previous one.
     */
    public function xlsx(Request $request, string $year): void
    {
        require_component('treasury');
        $year = (int) $year;
        if ($year < 2000 || $year > 2100) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $report = $this->runts->report($year);
        $previous = $year > 2000 ? $this->runts->report($year - 1) : null;
        $branding = app()->branding();
        try {
            $bytes = $this->xlsx->build($year, $report, $previous, [
                'ente' => assoc_capitalize_name((string) ($branding['name'] ?? 'SOCLY')),
            ]);
        } catch (\Throwable $e) {
            try {
                app('logger')->error('runts.xlsx_failed', ['year' => $year, 'error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
            http_response_code(500);
            $this->render('errors/500');
            return;
        }
        $filename = 'Mod-D-' . $year . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $bytes;
    }
}
