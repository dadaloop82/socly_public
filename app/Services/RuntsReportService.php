<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Cash flow statement - Form D of the Ministerial Decree of 18 April 2020 (RUNTS).
 * The ministerial model is in Italian by law: the official labels of the items remain in Italian,
 * while the interface (titles, buttons, notes) is translated.
 * The amounts come from the approved cash book movements of the year: each movement is classified
 * in its own item (`treasury_movements.runts_line`), with fallback to the treasury category when
 * the explicit item is missing.
 */
final class RuntsReportService
{
    /**
     * Legal threshold: the cash flow statement is drawn up below this revenue figure (Form D).
     */
    public const CASH_LIMIT = 220000.0;

    /**
     * Technical item: internal movements (account transfers, deposits/withdrawals) outside tables
     * A–E.
     */
    public const INTERNAL_LINE = 'X1';

    /**
     * Official structure of Model D: sections A–E with exit and entry entries.
     *
     * @var array<string,array{label:string,expense:array<string,string>,income:array<string,string>}>
     */
    private const SECTIONS = [
        'A' => [
            'label' => 'A) Attività di interesse generale',
            'expense' => [
                'A-E1' => '1) Materie prime, sussidiarie, di consumo e di merci',
                'A-E2' => '2) Servizi',
                'A-E3' => '3) Godimento di beni di terzi',
                'A-E4' => '4) Personale',
                'A-E5' => '5) Uscite diverse di gestione',
            ],
            'income' => [
                'A-I1' => '1) Entrate da quote associative e apporti dei fondatori',
                'A-I2' => '2) Entrate dagli associati per attività mutualistiche',
                'A-I3' => '3) Entrate per prestazioni e cessioni ad associati e fondatori',
                'A-I4' => '4) Erogazioni liberali',
                'A-I5' => '5) Entrate del 5 per mille',
                'A-I6' => '6) Contributi da soggetti privati',
                'A-I7' => '7) Entrate per prestazioni e cessioni a terzi',
                'A-I8' => '8) Contributi da enti pubblici',
                'A-I9' => '9) Entrate da contratti con enti pubblici',
                'A-I10' => '10) Altre entrate',
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
                'B-I1' => '1) Entrate per prestazioni e cessioni ad associati e fondatori',
                'B-I2' => '2) Contributi da soggetti privati',
                'B-I3' => '3) Entrate per prestazioni e cessioni a terzi',
                'B-I4' => '4) Contributi da enti pubblici',
                'B-I5' => '5) Entrate da contratti con enti pubblici',
                'B-I6' => '6) Altre entrate',
            ],
        ],
        'C' => [
            'label' => 'C) Attività di raccolta fondi',
            'expense' => [
                'C-E1' => '1) Uscite per raccolte fondi abituali',
                'C-E2' => '2) Uscite per raccolte fondi occasionali',
                'C-E3' => '3) Altre uscite',
            ],
            'income' => [
                'C-I1' => '1) Entrate da raccolte fondi abituali',
                'C-I2' => '2) Entrate da raccolte fondi occasionali',
                'C-I3' => '3) Altre entrate',
            ],
        ],
        'D' => [
            'label' => 'D) Attività finanziarie e patrimoniali',
            'expense' => [
                'D-E1' => '1) Su rapporti bancari',
                'D-E2' => '2) Su investimenti finanziari',
                'D-E3' => '3) Su patrimonio edilizio',
                'D-E4' => '4) Su altri beni patrimoniali',
                'D-E5' => '5) Altre uscite',
            ],
            'income' => [
                'D-I1' => '1) Da rapporti bancari',
                'D-I2' => '2) Da altri investimenti finanziari',
                'D-I3' => '3) Da patrimonio edilizio',
                'D-I4' => '4) Da altri beni patrimoniali',
                'D-I5' => '5) Altre entrate',
            ],
        ],
        'E' => [
            'label' => 'E) Supporto generale',
            'expense' => [
                'E-E1' => '1) Materie prime, sussidiarie, di consumo e di merci',
                'E-E2' => '2) Servizi',
                'E-E3' => '3) Godimento di beni di terzi',
                'E-E4' => '4) Personale',
                'E-E5' => '5) Altre uscite',
            ],
            'income' => [
                'E-I1' => '1) Entrate da distacco del personale',
                'E-I2' => '2) Altre entrate di supporto generale',
            ],
        ],
    ];

    /**
     * Investment and financing section of Model D (rows 79–82): it sits outside the operating
     * totals of sections A–E, exactly like the official workbook.
     *
     * @var array{label:string,expense:array<string,string>,income:array<string,string>}
     */
    private const INVESTMENTS = [
        'label' => 'Investimenti e finanziamenti',
        'expense' => [
            'INV-E1' => '1) Investimenti in immobilizzazioni inerenti alle attività di interesse generale',
            'INV-E2' => '2) Investimenti in immobilizzazioni inerenti alle attività diverse',
            'INV-E3' => '3) Investimenti in attività finanziarie e patrimoniali',
            'INV-E4' => '4) Rimborso di finanziamenti per quota capitale e di prestiti',
        ],
        'income' => [
            'INV-I1' => '1) Disinvestimenti in immobilizzazioni inerenti alle attività di interesse generale',
            'INV-I2' => '2) Disinvestimenti in immobilizzazioni inerenti alle attività diverse',
            'INV-I3' => '3) Disinvestimenti in attività finanziarie e patrimoniali',
            'INV-I4' => '4) Ricevimento di finanziamenti e di prestiti',
        ],
    ];

    /**
     * Treasury category ⇒ item of Model D. Explicit direction because the same category can be used
     * for incoming and outgoing.
     *
     * @var array<string,array{expense?:string,income?:string}>
     */
    private const CATEGORY_MAP = [
        'membership_fee' => ['income' => 'A-I1'],
        'public_grants' => ['income' => 'A-I8'],
        'donation' => ['income' => 'A-I4'],
        'five_per_thousand' => ['income' => 'A-I5'],
        'institutional_activities' => ['income' => 'A-I7', 'expense' => 'A-E2'],
        'sponsorship' => ['income' => 'B-I3'],
        'commercial_sales' => ['income' => 'B-I3', 'expense' => 'B-E5'],
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
        'equipment' => ['expense' => 'INV-E1'],
        'marketing_promo' => ['expense' => 'E-E2'],
        'taxes' => ['expense' => 'E-E5'],
        'bank_fees' => ['expense' => 'D-E1'],
        'member_loan' => ['expense' => 'INV-E4', 'income' => 'INV-I4'],
        'security_deposit' => ['expense' => 'INV-E3', 'income' => 'INV-I3'],
        'internal_transfer' => ['expense' => self::INTERNAL_LINE, 'income' => self::INTERNAL_LINE],
        'cash_deposit_withdrawal' => ['expense' => self::INTERNAL_LINE, 'income' => self::INTERNAL_LINE],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Model D entry label (for summary of changes).
     */
    public static function lineLabel(string $line): string
    {
        $line = strtoupper(trim($line));
        if ($line === '') {
            return '';
        }
        foreach (self::SECTIONS as $section) {
            foreach (['expense', 'income'] as $direction) {
                foreach ($section[$direction] as $key => $label) {
                    if ($key === $line) {
                        return $label;
                    }
                }
            }
        }
        foreach (['expense', 'income'] as $direction) {
            foreach (self::INVESTMENTS[$direction] as $key => $label) {
                if ($key === $line) {
                    return $label;
                }
            }
        }

        return $line;
    }

    /**
     * Model D entry label prefixed with its section: the same wording appears in several sections
     * (“1) Servizi” in A, B and E), so the bare label is ambiguous in the import menus.
     */
    public static function lineLabelWithSection(string $line): string
    {
        $line = strtoupper(trim($line));
        if ($line === '') {
            return '';
        }
        foreach (self::SECTIONS as $letter => $section) {
            foreach (['expense', 'income'] as $direction) {
                if (isset($section[$direction][$line])) {
                    return self::sectionLabel((string) $letter) . ' — ' . $section[$direction][$line];
                }
            }
        }
        foreach (['expense', 'income'] as $direction) {
            if (isset(self::INVESTMENTS[$direction][$line])) {
                return self::sectionLabel('INV') . ' — ' . self::INVESTMENTS[$direction][$line];
            }
        }

        return $line;
    }

    /**
     * Model D entries (expenses first, then income) with the section inside the label, ready for the
     * selects that are not grouped by section.
     *
     * @return list<array{value:string,label:string}>
     */
    public static function lineOptions(): array
    {
        $out = [];
        foreach (['expense', 'income'] as $direction) {
            foreach (self::SECTIONS as $letter => $section) {
                foreach ($section[$direction] as $key => $label) {
                    if (isset($out[$key])) {
                        continue;
                    }
                    $out[$key] = [
                        'value' => (string) $key,
                        'label' => self::sectionLabel((string) $letter) . ' — ' . $label,
                    ];
                }
            }
            foreach (self::INVESTMENTS[$direction] as $key => $label) {
                if (isset($out[$key])) {
                    continue;
                }
                $out[$key] = [
                    'value' => (string) $key,
                    'label' => self::sectionLabel('INV') . ' — ' . $label,
                ];
            }
        }

        return array_values($out);
    }

    /**
     * Section label (A–E and the investment block); the official text of Model D remains the fallback.
     */
    public static function sectionLabel(string $letter): string
    {
        $letter = strtoupper($letter);
        if ($letter === 'INV') {
            $key = 'runts.section_investments';
            $translated = (string) __($key);
            return $translated !== $key && $translated !== '' ? $translated : self::INVESTMENTS['label'];
        }
        $fallback = (string) (self::SECTIONS[$letter]['label'] ?? '');
        $key = 'runts.section_' . strtolower($letter);
        $translated = (string) __($key);
        return $translated !== $key && $translated !== '' ? $translated : $fallback;
    }

    /**
     * Items of Model D grouped by section, for the selects of the movements form.
     *
     * @return list<array{key:string,label:string,options:list<array{value:string,label:string}>}>
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
                $groups[] = [
                    'key' => (string) $letter,
                    'label' => self::sectionLabel((string) $letter),
                    'options' => $options,
                ];
            }
        }
        $investmentOptions = [];
        foreach (self::INVESTMENTS[$direction] as $key => $label) {
            $investmentOptions[] = ['value' => $key, 'label' => $label];
        }
        if ($investmentOptions !== []) {
            $groups[] = [
                'key' => 'INV',
                'label' => self::sectionLabel('INV'),
                'options' => $investmentOptions,
            ];
        }
        return $groups;
    }

    /**
     * Voice chosen explicitly, otherwise the one suggested by the category.
     */
    public static function normalizeLine(string $line, string $category, string $direction): string
    {
        $line = strtoupper(trim($line));
        if ($line === '' || !self::isKnownLine($line, $direction)) {
            return self::lineForCategory($category, $direction);
        }
        return $line;
    }

    /**
     * Model D item suggested by the treasury category.
     */
    public static function lineForCategory(string $category, string $direction): string
    {
        $direction = $direction === 'income' ? 'income' : 'expense';
        $category = strtolower(trim($category));
        $mapped = self::CATEGORY_MAP[$category][$direction] ?? '';
        if ($mapped !== '') {
            return $mapped;
        }
        // Custom categories: they end up among the "others" of section A and remain editable.
        return $direction === 'income' ? 'A-I10' : 'A-E5';
    }

    /**
     * True when the voice belongs to the indicated direction (or is the internal technical one).
     */
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
        return isset(self::INVESTMENTS[$direction][$line]);
    }

    /**
     * Years with approved cash flows.
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

    /**
     * Revenue for the year on a cash basis (excluding internal movements).
     */
    public function totalIncome(int $year): float
    {
        $row = $this->db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL
               AND direction = 'income'
               AND YEAR(movement_date) = :y
               AND (runts_line IS NULL OR UPPER(runts_line) <> 'X1')
               AND category NOT IN ('internal_transfer', 'cash_deposit_withdrawal')",
            ['y' => $year]
        );
        return round((float) ($row['total'] ?? 0), 2);
    }

    /**
     * One year can be drawn up with Form D only below the legal threshold.
     */
    public function canGenerate(int $year): bool
    {
        return $this->totalIncome($year) < self::CASH_LIMIT;
    }

    /**
     * Cash flow statement for the year: sections A–E, totals, cash/bank statement.
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
        $investments = [
            'label' => self::sectionLabel('INV'),
            'expense' => self::INVESTMENTS['expense'],
            'income' => self::INVESTMENTS['income'],
            'totals' => ['expense' => 0.0, 'income' => 0.0],
            'lines' => ['expense' => [], 'income' => []],
            'counts' => ['expense' => 0, 'income' => 0],
        ];
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
            // Investments and financing are outside the operating totals of sections A–E, exactly
            // like the official workbook (rows 79–82).
            if (isset(self::INVESTMENTS[$direction][$line])) {
                $investments['totals'][$direction] += $amount;
                $investments['lines'][$direction][$line] = ($investments['lines'][$direction][$line] ?? 0.0) + $amount;
                $investments['counts'][$direction] += 1;
                continue;
            }
            // Unrecognized entry (legacy data or direction changed): reported, not lost.
            $unclassified[] = [
                'direction' => $direction,
                'category' => $category,
                'line' => $line,
                'amount' => $amount,
            ];
        }

        $balance = round($totalIncome - $totalExpense, 2);
        $investmentBalance = round($investments['totals']['income'] - $investments['totals']['expense'], 2);

        return [
            'year' => $year,
            'sections' => $sections,
            'investments' => $investments,
            'total_expense' => round($totalExpense, 2),
            'total_income' => round($totalIncome, 2),
            'balance' => $balance,
            'investment_expense' => round($investments['totals']['expense'], 2),
            'investment_income' => round($investments['totals']['income'], 2),
            'investment_balance' => $investmentBalance,
            'result' => round($balance + $investmentBalance, 2),
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
     * Cash and bank balances (from approved transactions recorded).
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
