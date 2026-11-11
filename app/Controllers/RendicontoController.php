<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\CurrencyService;
use Socly\Services\RuntsReportService;
use Socly\Services\SettingsService;

/**
 * Bilancio consuntivo — Rendiconto per cassa (Modello D, DM 18 aprile 2020).
 *
 * Gli ETS sotto i 220.000 € di entrate redigono il rendiconto per cassa: la pagina genera il
 * modello ministeriale dai movimenti di prima cassa approvati. Sopra soglia i modelli per
 * competenza (A/B/C) non sono ancora generati: la pagina lo dice esplicitamente.
 */
final class RendicontoController extends BaseController
{
    public function __construct(
        View $view,
        private readonly RuntsReportService $runts,
        private readonly CurrencyService $currency,
        private readonly SettingsService $settings
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        require_component('treasury');
        // Il consuntivo ora vive nella scheda “Consuntivo” della pagina Bilanci.
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
}
