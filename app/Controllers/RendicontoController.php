<?php
/*
 * Socly Project Header
 *
 *   ███████╗ ██████╗  ██████╗ ██╗   ██╗
 *   ██╔════╝██╔═══██╗██╔════╝ ██║   ██║
 *   ███████╗██║   ██║██║      ██║   ██║
 *   ╚════██║██║   ██║██║      ██║   ██║
 *   ███████║╚██████╔╝╚██████╗ ╚██████╔╝
 *   ╚══════╝ ╚═════╝  ╚═════╝  ╚═════╝
 *
 *   Socly — https://socly.it/
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
        // The Model D is generated per exercise: ask which one before opening the sheet.
        $requested = (int) $request->input('year', 0);
        if ($requested >= 2000 && $requested <= 2100) {
            redirect('/rendiconto/' . $requested);
        }
        $this->render('rendiconto/choose', [
            'title' => __('runts.choose_title'),
            'years' => $this->runts->yearsOverview(),
            'currentYear' => (int) date('Y'),
        ]);
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
        // Exercises offered by the page: every year in the cash book, plus the requested
        // one (a previous year can be opened for comparison even without movements).
        $years = [];
        $approvedYears = [];
        foreach ($this->runts->yearsOverview() as $row) {
            $years[] = (int) $row['year'];
            if ((int) $row['approved'] > 0) {
                $approvedYears[] = (int) $row['year'];
            }
        }
        if (!in_array($year, $years, true)) {
            $years[] = $year;
            rsort($years);
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
            'years' => $years,
            'year_has_movements' => in_array($year, $approvedYears, true),
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
