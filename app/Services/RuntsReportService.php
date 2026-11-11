<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Rendiconto per cassa — Modello D del DM 18 aprile 2020 (RUNTS).
 *
 * Il modello ministeriale è in italiano per legge: le etichette ufficiali delle voci restano in
 * italiano, mentre l'interfaccia (titoli, pulsanti, note) passa dalle traduzioni.
 *
 * Gli importi arrivano dai movimenti di prima cassa approvati dell'anno: ogni movimento è
 * classificato nella sua voce (`treasury_movements.runts_line`), con fallback sulla categoria
 * tesoreria quando la voce esplicita manca.
 */
final class RuntsReportService
{
    /** Soglia di legge: sotto questa cifra di entrate si redige il rendiconto per cassa (Modello D). */
    public const CASH_LIMIT = 220000.0;

    /** Voce tecnica: movimenti interni (giroconti, versamenti/prelievi) fuori dai prospetti A–E. */
    public const INTERNAL_LINE = 'X1';

    /**
     * Struttura ufficiale del Modello D: sezioni A–E con le voci di uscita e di entrata.
     *
     * @var array<string,array{label:string,expense:array<string,string>,income:array<string,string>}>
     */
    private const SECTIONS = [
        'A' => [
            'label' => 'A) Attività di interesse generale',
            'expense' => [
                'A-E1' => '1) Acquisto materie prime, sussidiarie, di consumo e merci',
                'A-E2' => '2) Servizi',
                'A-E3' => '3) Godimento beni di terzi',
                'A-E4' => '4) Personale',
                'A-E5' => '5) Uscite diverse di gestione',
            ],
            'income' => [
                'A-I1' => '1) Quote associative',
                'A-I2' => '2) Contributi da enti pubblici',
                'A-I3' => '3) Contributi da privati',
                'A-I4' => '4) Proventi da contratti con enti pubblici',
                'A-I5' => '5) Proventi da attività di interesse generale',
                'A-I6' => '6) Erogazioni liberali',
                'A-I7' => '7) 5 per mille',
                'A-I8' => '8) Altri proventi',
            ],
        ],
        'B' => [
            'label' => 'B) Attività diverse',
            'expense' => [
                'B-E1' => '1) Acquisto materie prime, sussidiarie, di consumo e merci',
                'B-E2' => '2) Servizi',
                'B-E3' => '3) Godimento beni di terzi',
                'B-E4' => '4) Personale',
                'B-E5' => '5) Uscite diverse di gestione',
            ],
            'income' => [
                'B-I1' => '1) Proventi da attività diverse',
            ],
        ],
        'C' => [
            'label' => 'C) Attività di raccolta fondi',
            'expense' => [
                'C-E1' => '1) Raccolte fondi abituali',
                'C-E2' => '2) Raccolte fondi occasionali',
            ],
            'income' => [
                'C-I1' => '1) Raccolte fondi abituali',
                'C-I2' => '2) Raccolte fondi occasionali',
            ],
        ],
        'D' => [
            'label' => 'D) Attività finanziarie e patrimoniali',
            'expense' => [
                'D-E1' => '1) Acquisto immobilizzazioni materiali e immateriali',
                'D-E2' => '2) Acquisto immobilizzazioni finanziarie',
                'D-E3' => '3) Altre uscite finanziarie',
            ],
            'income' => [
                'D-I1' => '1) Proventi da attività finanziarie',
                'D-I2' => '2) Proventi da attività patrimoniali',
                'D-I3' => '3) Altre entrate finanziarie',
            ],
        ],
        'E' => [
            'label' => 'E) Supporto generale',
            'expense' => [
                'E-E1' => '1) Acquisto materie prime, sussidiarie, di consumo e merci',
                'E-E2' => '2) Servizi',
                'E-E3' => '3) Godimento beni di terzi',
                'E-E4' => '4) Personale',
                'E-E5' => '5) Uscite diverse di gestione',
            ],
            'income' => [
                'E-I1' => '1) Proventi da supporto generale',
            ],
        ],
    ];

    /**
     * Categoria tesoreria ⇒ voce del Modello D. Direzione esplicita perché la stessa categoria
     * può essere usata in entrata e in uscita.
     *
     * @var array<string,array{expense?:string,income?:string}>
     */
    private const CATEGORY_MAP = [
        'membership_fee' => ['income' => 'A-I1'],
        'public_grants' => ['income' => 'A-I2'],
        'donation' => ['income' => 'A-I6'],
        'five_per_thousand' => ['income' => 'A-I7'],
        'institutional_activities' => ['income' => 'A-I5', 'expense' => 'A-E2'],
        'sponsorship' => ['income' => 'B-I1'],
        'commercial_sales' => ['income' => 'B-I1', 'expense' => 'B-E5'],
        'events' => ['income' => 'C-I1', 'expense' => 'C-E1'],
        'financial_income' => ['income' => 'D-I1'],
        'rent' => ['expense' => 'A-E3'],
        'utilities' => ['expense' => 'A-E2'],
        'supplies' => ['expense' => 'A-E1'],
        'event_costs' => ['expense' => 'C-E1'],
        'volunteer_reimbursement' => ['expense' => 'A-E5'],
        'personnel' => ['expense' => 'A-E4'],
        'services_consulting' => ['expense' => 'A-E2'],
        'insurance' => ['expense' => 'A-E2'],
        'maintenance' => ['expense' => 'A-E2'],
        'equipment' => ['expense' => 'D-E1'],
        'marketing_promo' => ['expense' => 'E-E2'],
        'taxes' => ['expense' => 'A-E5'],
        'bank_fees' => ['expense' => 'D-E3'],
        'member_loan' => ['expense' => 'D-E3', 'income' => 'D-I3'],
        'security_deposit' => ['expense' => 'D-E2', 'income' => 'D-I2'],
        'internal_transfer' => ['expense' => self::INTERNAL_LINE, 'income' => self::INTERNAL_LINE],
        'cash_deposit_withdrawal' => ['expense' => self::INTERNAL_LINE, 'income' => self::INTERNAL_LINE],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Etichetta della voce di Modello D (per il riepilogo delle modifiche).
     */
    public static function lineLabel(string $line): string
    {
        $line = strtoupper(trim($line));
        if ($line === '') {
            return '';
        }
        foreach (self::SECTIONS as $letter => $section) {
            foreach (['expense', 'income'] as $direction) {
                foreach ($section[$direction] as $key => $label) {
                    if ($key === $line) {
                        return $label;
                    }
                }
            }
        }

        return $line;
    }

    /**
     * Etichetta della sezione (A–E); il testo ufficiale del Modello D resta il fallback.
     */
    public static function sectionLabel(string $letter): string
    {
        $letter = strtoupper($letter);
        $fallback = (string) (self::SECTIONS[$letter]['label'] ?? '');
        $key = 'runts.section_' . strtolower($letter);
        $translated = (string) __($key);
        return $translated !== $key && $translated !== '' ? $translated : $fallback;
    }

    /**
     * Voci del Modello D raggruppate per sezione, per i select del form movimenti.
     *
     * @return list<array{label:string,options:list<array{value:string,label:string}>}>
     */
    public static function selectGroups(string $direction): array
    {
        $direction = $direction === 'income' ? 'income' : 'expense';
        $groups = [];
        foreach (self::SECTIONS as $letter => $section) {
            $options = [];
            foreach ($section[$direction] as $key => $label) {
                $options[] = ['value' => $key, 'label' => $label];
            }
            if ($options !== []) {
                $groups[] = ['label' => self::sectionLabel((string) $letter), 'options' => $options];
            }
        }
        return $groups;
    }

    /** Voce scelta esplicitamente, altrimenti quella suggerita dalla categoria. */
    public static function normalizeLine(string $line, string $category, string $direction): string
    {
        $line = strtoupper(trim($line));
        if ($line === '' || !self::isKnownLine($line, $direction)) {
            return self::lineForCategory($category, $direction);
        }
        return $line;
    }

    /** Voce del Modello D suggerita dalla categoria tesoreria. */
    public static function lineForCategory(string $category, string $direction): string
    {
        $direction = $direction === 'income' ? 'income' : 'expense';
        $category = strtolower(trim($category));
        $mapped = self::CATEGORY_MAP[$category][$direction] ?? '';
        if ($mapped !== '') {
            return $mapped;
        }
        // Categorie personalizzate: finiscono tra gli “altri” della sezione A e restano modificabili.
        return $direction === 'income' ? 'A-I8' : 'A-E5';
    }

    /** True quando la voce appartiene alla direzione indicata (o è quella tecnica interna). */
    public static function isKnownLine(string $line, string $direction): bool
    {
        if ($line === self::INTERNAL_LINE) {
            return true;
        }
        foreach (self::SECTIONS as $section) {
            if (isset($section[$direction][$line])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Anni con movimenti di cassa approvati.
     *
     * @return list<int>
     */
    public function years(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT YEAR(movement_date) AS y
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL
             ORDER BY y DESC'
        );
        $out = [];
        foreach ($rows as $row) {
            $year = (int) ($row['y'] ?? 0);
            if ($year >= 2000) {
                $out[] = $year;
            }
        }
        return $out;
    }

    /** Entrate dell'anno su base cassa (esclusi i movimenti interni). */
    public function totalIncome(int $year): float
    {
        $row = $this->db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL
               AND direction = 'income'
               AND YEAR(movement_date) = :y",
            ['y' => $year]
        );
        return round((float) ($row['total'] ?? 0), 2);
    }

    /** Un anno si può redigere con il Modello D solo sotto la soglia di legge. */
    public function canGenerate(int $year): bool
    {
        return $this->totalIncome($year) < self::CASH_LIMIT;
    }

    /**
     * Rendiconto per cassa dell'anno: sezioni A–E, totali, prospetto cassa/banche.
     *
     * @return array<string,mixed>
     */
    public function report(int $year): array
    {
        $movements = $this->db->fetchAll(
            'SELECT direction, category, runts_line, account, transfer_account, amount, movement_date
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL AND YEAR(movement_date) = :y
             ORDER BY movement_date ASC, id ASC',
            ['y' => $year]
        );

        $sections = [];
        foreach (self::SECTIONS as $letter => $section) {
            $sections[$letter] = [
                'label' => self::sectionLabel((string) $letter),
                'expense' => $section['expense'],
                'income' => $section['income'],
                'totals' => ['expense' => 0.0, 'income' => 0.0],
                'lines' => ['expense' => [], 'income' => []],
                'counts' => ['expense' => 0, 'income' => 0],
            ];
        }
        $totalExpense = 0.0;
        $totalIncome = 0.0;
        $internal = 0.0;
        $unclassified = [];

        foreach ($movements as $row) {
            $direction = (string) ($row['direction'] ?? '');
            $amount = (float) ($row['amount'] ?? 0);
            if ($direction === 'transfer') {
                $internal += $amount;
                continue;
            }
            if ($direction !== 'income' && $direction !== 'expense') {
                continue;
            }
            $category = (string) ($row['category'] ?? '');
            $line = self::normalizeLine((string) ($row['runts_line'] ?? ''), $category, $direction);
            if ($line === self::INTERNAL_LINE) {
                $internal += $amount;
                continue;
            }
            $placed = false;
            foreach ($sections as $letter => $section) {
                if (!isset($section[$direction][$line])) {
                    continue;
                }
                $sections[$letter]['totals'][$direction] += $amount;
                $sections[$letter]['lines'][$direction][$line] = ($sections[$letter]['lines'][$direction][$line] ?? 0.0) + $amount;
                $sections[$letter]['counts'][$direction] += 1;
                $placed = true;
                break;
            }
            if ($placed) {
                if ($direction === 'income') {
                    $totalIncome += $amount;
                } else {
                    $totalExpense += $amount;
                }
                continue;
            }
            // Voce non riconosciuta (dato legacy o direzione cambiata): segnalata, non persa.
            $unclassified[] = [
                'direction' => $direction,
                'category' => $category,
                'line' => $line,
                'amount' => $amount,
            ];
        }

        return [
            'year' => $year,
            'sections' => $sections,
            'total_expense' => round($totalExpense, 2),
            'total_income' => round($totalIncome, 2),
            'balance' => round($totalIncome - $totalExpense, 2),
            'internal' => round($internal, 2),
            'movements' => count($movements),
            'unclassified' => $unclassified,
            'prospect' => [
                'opening' => $this->balances('YEAR(movement_date) < :y', $year),
                'closing' => $this->balances('YEAR(movement_date) <= :y', $year),
            ],
        ];
    }

    /**
     * Saldi per cassa e banche (dai movimenti approvati registrati).
     *
     * @return array{cash:float,bank:float,total:float}
     */
    private function balances(string $where, int $year): array
    {
        $rows = $this->db->fetchAll(
            'SELECT account, transfer_account, direction, amount
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL AND ' . $where,
            ['y' => $year]
        );
        $balances = [];
        foreach ($rows as $row) {
            $account = (string) ($row['account'] ?? '');
            $direction = (string) ($row['direction'] ?? '');
            $amount = (float) ($row['amount'] ?? 0);
            if ($account !== '') {
                $balances[$account] = ($balances[$account] ?? 0.0)
                    + ($direction === 'income' ? $amount : -$amount);
            }
            $target = (string) ($row['transfer_account'] ?? '');
            if ($direction === 'transfer' && $target !== '') {
                $balances[$target] = ($balances[$target] ?? 0.0) + $amount;
            }
        }
        $cash = round((float) ($balances['cash'] ?? 0), 2);
        $bank = 0.0;
        foreach ($balances as $account => $balance) {
            if ($account !== 'cash') {
                $bank += (float) $balance;
            }
        }
        $bank = round($bank, 2);
        return ['cash' => $cash, 'bank' => $bank, 'total' => round($cash + $bank, 2)];
    }
}
