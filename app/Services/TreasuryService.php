<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
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

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Validator;
use Socly\Support\PlausibleDate;

final class TreasuryService
{
    /** @var list<string> */
    public const BUILTIN_CATEGORIES = [
        'membership_fee', 'donation', 'five_per_thousand', 'public_grants', 'institutional_activities',
        'events', 'sponsorship', 'commercial_sales', 'financial_income',
        'rent', 'utilities', 'supplies', 'event_costs', 'volunteer_reimbursement', 'personnel',
        'services_consulting', 'insurance', 'bank_fees', 'maintenance', 'equipment', 'marketing_promo', 'taxes',
        'internal_transfer', 'cash_deposit_withdrawal', 'member_loan', 'security_deposit',
        'other',
    ];

    /** @var list<string> */
    public const MOVEMENT_KINDS = [
        'operating', 'internal_transfer', 'advance', 'refund', 'loan', 'deposit', 'reversal', 'adjustment',
    ];

    /** @var list<string> */
    public const METHODS = [
        'cash', 'bank', 'bank_transfer', 'pos', 'pos_card', 'credit_card', 'satispay', 'paypal',
        'online_gateway', 'sepa_direct_debit', 'postal_slip', 'check', 'internal_transfer',
        'offset_compensation', 'other',
    ];

    /**
     * Stored directions. `transfer` is a internal transfer between the association's own
     * accounts: it moves liquidity without changing the cash book balance, so it
     * stays out of the income/expense aggregates.
     *
     * @var list<string>
     */
    public const DIRECTIONS = ['income', 'expense', 'transfer'];

    /**
     * Movements listed inside the “also apply to similar movements?” proposal: enough to judge the
     * batch without turning the banner into a second ledger (the total stays exact).
     */
    public const SIMILAR_LIST_LIMIT = 50;

    /**
     * Orders accepted by the ledger and by the sortable table headers: date, registration date,
     * last modification, document date, description, payment method and the amount of the
     * income/expense column.
     *
     * @var list<string>
     */
    public const SORTS = [
        'date_desc', 'date_asc', 'created_desc', 'updated_desc', 'updated_asc', 'invoice_desc',
        'description_asc', 'description_desc', 'method_asc', 'method_desc',
        'income_asc', 'income_desc', 'expense_asc', 'expense_desc',
    ];

    /**
     * Classification fields compared with the fingerprint of similar movements.
     *
     * @var list<string>
     */
    public const PATTERN_FIELDS = ['payment_method', 'category', 'runts_line', 'budget_item_id'];

    /** Minimum similarity for a movement to contribute to the “typical” values. */
    private const PATTERN_MIN_SCORE = 0.45;
    /** Maximum number of similar movements kept for one fingerprint. */
    private const PATTERN_SAMPLE_LIMIT = 24;
    /** A field needs at least two similar examples before it can be reported as an anomaly. */
    private const PATTERN_MIN_ANOMALY_SAMPLES = 2;
    /** Candidate pool used by the fingerprint scan (newest movements first). */
    private const PATTERN_CANDIDATE_LIMIT = 4000;

    /**
     * Earliest plausible year for an operation date: a typo like 0206 would otherwise pollute the
     * period filters and the totals.
     */
    public const MIN_MOVEMENT_YEAR = PlausibleDate::MIN_YEAR;

    /**
     * Cap of the free-text annotations (`notes`): a memo, not a second description.
     */
    public const NOTES_MAX_LENGTH = 2000;

    /**
     * Built-in wallets, seeded by migration 028. Custom wallets live in the `treasury_accounts`
     * table (label, active flag, order) and are managed in Settings → Financial Accounts.
     *
     * @var list<string>
     */
    public const BUILTIN_ACCOUNTS = ['cash', 'bank', 'postal', 'paypal', 'prepaid_card', 'other'];

    /**
     * Kind of document attached to a movement. An invoice (a tax document with a number and a date) is
     * not the same as a receipt (proof of payment): they are separate entries so the association can
     * tell them apart in the first note and in the export.
     *
     * @var list<string>
     */
    public const DOCUMENT_TYPES = [
        'invoice', 'receipt', 'quittance', 'contract', 'premium', 'credit_note', 'debit_note', 'statement', 'other',
    ];

    /**
     * Wallets active by default on a fresh install (and after a full reset):
     * an association normally starts with cash and a bank account, the other
     * built-ins (postal, PayPal, prepaid card, other) are opt-in and get
     * switched on by the treasurer only when actually used.
     *
     * @var list<string>
     */
    public const DEFAULT_ACTIVE_ACCOUNTS = ['cash', 'bank'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly Validator $validator,
        private readonly ComponentService $components,
        private readonly DocumentService $documents,
        private readonly CurrencyService $currency,
        private readonly VersionService $versions,
        private readonly FileRepositoryService $files
    ) {
    }

    /** @var list<array{id:int,slug:string,label:?string,builtin:bool,active:bool,sort_order:int}>|null */
    private ?array $accountsCache = null;

    public function movementCount(): int
    {
        return (int) ($this->db->fetch('SELECT COUNT(*) AS c FROM treasury_movements')['c'] ?? 0);
    }

    /**
     * Movements waiting for manual approval (they do not count towards balances yet). The cash desk
     * never waits: a cash row is never part of the queue, whatever its approval columns say.
     */
    public function pendingCount(): int
    {
        return (int) ($this->db->fetch(
            "SELECT COUNT(*) AS c FROM treasury_movements
              WHERE approved_at IS NULL
                AND (account IS NULL OR account <> 'cash')"
        )['c'] ?? 0);
    }

    /**
     * Ledger health alerts shown on the treasury home page.
     *
     * @return list<array<string,mixed>>
     */
    public function ledgerHealthAlerts(): array
    {
        $alerts = [];
        $dismissed = $this->healthDismissalMap();

        try {
            $rows = $this->db->fetchAll(
                'SELECT YEAR(movement_date) AS y, MONTH(movement_date) AS m
                 FROM treasury_movements
                 WHERE approved_at IS NOT NULL
                   AND superseded_at IS NULL
                   AND movement_date IS NOT NULL
                 GROUP BY y, m
                 ORDER BY y DESC, m ASC'
            );
            $byYear = [];
            foreach ($rows as $row) {
                $year = (int) ($row['y'] ?? 0);
                $month = (int) ($row['m'] ?? 0);
                if ($year < 1 || $month < 1 || $month > 12) {
                    continue;
                }
                $byYear[$year][$month] = true;
            }
            $currentYear = (int) date('Y');
            $currentMonth = (int) date('n');
            foreach ($byYear as $year => $monthsMap) {
                if ($year > $currentYear) {
                    continue;
                }
                $limitMonth = $year === $currentYear ? max(1, $currentMonth) : 12;
                $missing = [];
                $missingLabels = [];
                for ($month = 1; $month <= $limitMonth; $month++) {
                    if (!isset($monthsMap[$month])) {
                        $missing[] = $month;
                        $missingLabels[] = (string) __('common.month_' . $month);
                    }
                }
                if ($missing === []) {
                    continue;
                }
                $fingerprint = $this->healthFingerprint('missing_months', [(string) $year, implode(',', $missing)]);
                if ($this->healthAlertDismissed($dismissed, 'missing_months', $fingerprint)) {
                    continue;
                }
                $alerts[] = [
                    'key' => 'missing_months',
                    'fingerprint' => $fingerprint,
                    'severity' => 'warn',
                    'title' => __('treasury.health_missing_months', ['year' => (string) $year]),
                    'detail' => __('treasury.health_missing_months_detail', [
                        'year' => (string) $year,
                        'months' => implode(', ', $missingLabels),
                    ]),
                    'count' => count($missing),
                    'href' => url('/treasury?year=' . $year),
                    'dismissable' => true,
                    'meta' => [
                        'year' => $year,
                        'months' => $missing,
                    ],
                ];
            }
        } catch (\Throwable) {
        }

        try {
            // Same day + wallet + direction + amount + description/beneficiary: likely double imports.
            $rows = $this->db->fetchAll(
                "SELECT movement_date, account, direction, ROUND(amount, 2) AS amount,
                        COALESCE(NULLIF(TRIM(description), ''), TRIM(beneficiary), '') AS text_key,
                        COUNT(*) AS cnt,
                        GROUP_CONCAT(id ORDER BY id ASC) AS ids
                 FROM treasury_movements
                 WHERE superseded_at IS NULL
                   AND movement_date >= DATE_SUB(CURDATE(), INTERVAL 3 YEAR)
                 GROUP BY movement_date, account, direction, ROUND(amount, 2), text_key
                 HAVING cnt > 1
                 ORDER BY movement_date DESC
                 LIMIT 40"
            );
            $groupKeys = [];
            $movementTotal = 0;
            foreach ($rows as $row) {
                $cnt = (int) ($row['cnt'] ?? 0);
                if ($cnt < 2) {
                    continue;
                }
                $groupKeys[] = implode('|', [
                    (string) ($row['movement_date'] ?? ''),
                    mb_strtolower(trim((string) ($row['account'] ?? ''))),
                    mb_strtolower(trim((string) ($row['direction'] ?? ''))),
                    number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
                    mb_strtolower(trim((string) ($row['text_key'] ?? ''))),
                ]);
                $movementTotal += $cnt;
            }
            if ($groupKeys !== []) {
                sort($groupKeys);
                $fingerprint = $this->healthFingerprint('duplicates', $groupKeys);
                if (!$this->healthAlertDismissed($dismissed, 'duplicates', $fingerprint)) {
                    $alerts[] = [
                        'key' => 'duplicates',
                        'fingerprint' => $fingerprint,
                        'severity' => 'warn',
                        'title' => __('treasury.health_duplicates'),
                        'detail' => __('treasury.health_duplicates_detail', [
                            'groups' => (string) count($groupKeys),
                            'count' => (string) $movementTotal,
                        ]),
                        'count' => count($groupKeys),
                        'href' => url('/treasury'),
                        'dismissable' => true,
                        'meta' => [
                            'groups' => count($groupKeys),
                            'movements' => $movementTotal,
                        ],
                    ];
                }
            }
        } catch (\Throwable) {
        }

        try {
            $countRow = $this->db->fetch(
                "SELECT COUNT(*) AS c
                   FROM treasury_movements m
                  WHERE m.superseded_at IS NULL
                    AND (
                        m.notes LIKE 'Estratto conto%'
                     OR m.notes LIKE 'Kontoauszug%'
                     OR m.notes LIKE 'Bank statement%'
                    )
                    AND NOT EXISTS (
                        SELECT 1
                          FROM document_links l
                          INNER JOIN association_documents d ON d.id = l.document_id
                         WHERE l.section = 'treasury'
                           AND l.context_ref = CONCAT('movement:', m.id)
                           AND d.source_section = 'bank_statement_import'
                           AND d.deleted_at IS NULL
                           AND (d.file_mime LIKE 'image/%' OR LOWER(COALESCE(d.original_name, '')) LIKE '%.png')
                    )"
            );
            $missingExcerptCount = (int) ($countRow['c'] ?? 0);
            if ($missingExcerptCount > 0) {
                $fingerprint = $this->healthFingerprint('missing_excerpts', [(string) $missingExcerptCount]);
                // Stable-ish: re-show when the count changes after a repair tick.
                if (!$this->healthAlertDismissed($dismissed, 'missing_excerpts', $fingerprint)) {
                    $alerts[] = [
                        'key' => 'missing_excerpts',
                        'fingerprint' => $fingerprint,
                        'severity' => 'warn',
                        'title' => __('treasury.health_missing_excerpts'),
                        'detail' => __('treasury.health_missing_excerpts_detail', [
                            'count' => (string) $missingExcerptCount,
                        ]),
                        'count' => $missingExcerptCount,
                        'href' => url('/treasury/bank-statements'),
                        'dismissable' => true,
                        'meta' => [
                            'count' => $missingExcerptCount,
                        ],
                    ];
                }
            }
        } catch (\Throwable) {
        }

        try {
            $ids = array_values(array_filter(array_map('intval', $this->classificationAnomalyIds())));
            if ($ids !== []) {
                sort($ids);
                $fingerprint = $this->healthFingerprint('classification', [implode(',', $ids)]);
                if (!$this->healthAlertDismissed($dismissed, 'classification', $fingerprint)) {
                    $alerts[] = [
                        'key' => 'classification',
                        'fingerprint' => $fingerprint,
                        'severity' => 'warn',
                        'title' => __('treasury.health_classification'),
                        'detail' => __('treasury.health_classification_detail', [
                            'count' => (string) count($ids),
                        ]),
                        'count' => count($ids),
                        'href' => url('/treasury?anomaly=1'),
                        'dismissable' => true,
                        'meta' => [
                            'ids' => $ids,
                        ],
                    ];
                }
            }
        } catch (\Throwable) {
        }

        try {
            $rows = $this->db->fetchAll(
                "SELECT id
                   FROM treasury_movements
                  WHERE approved_at IS NOT NULL
                    AND superseded_at IS NULL
                    AND direction IN ('income', 'expense')
                    AND (runts_line IS NULL OR runts_line = '')
                  ORDER BY movement_date DESC, id DESC"
            );
            $ids = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows)));
            if ($ids !== []) {
                sort($ids);
                $fingerprint = $this->healthFingerprint('runts_line', [implode(',', $ids)]);
                if (!$this->healthAlertDismissed($dismissed, 'runts_line', $fingerprint)) {
                    $alerts[] = [
                        'key' => 'runts_line',
                        'fingerprint' => $fingerprint,
                        'severity' => 'warn',
                        'title' => __('treasury.health_no_runts'),
                        'detail' => __('treasury.health_no_runts_detail', [
                            'count' => (string) count($ids),
                        ]),
                        'count' => count($ids),
                        'dismissable' => true,
                        'meta' => [
                            'ids' => $ids,
                        ],
                    ];
                }
            }
        } catch (\Throwable) {
        }

        try {
            $rows = $this->db->fetchAll(
                'SELECT id
                   FROM treasury_movements
                  WHERE superseded_at IS NULL
                    AND movement_date > CURDATE()
                  ORDER BY movement_date ASC, id ASC'
            );
            $ids = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows)));
            if ($ids !== []) {
                sort($ids);
                $fingerprint = $this->healthFingerprint('future_dates', [implode(',', $ids)]);
                if (!$this->healthAlertDismissed($dismissed, 'future_dates', $fingerprint)) {
                    $alerts[] = [
                        'key' => 'future_dates',
                        'fingerprint' => $fingerprint,
                        'severity' => 'warn',
                        'title' => __('treasury.health_future_dates'),
                        'detail' => __('treasury.health_future_dates_detail', [
                            'count' => (string) count($ids),
                        ]),
                        'count' => count($ids),
                        'dismissable' => true,
                        'meta' => [
                            'ids' => $ids,
                        ],
                    ];
                }
            }
        } catch (\Throwable) {
        }

        return $alerts;
    }

    public function dismissHealthAlert(string $issueKey, string $fingerprint, ?int $userId, string $ip): bool
    {
        $issueKey = mb_substr(trim($issueKey), 0, 64);
        $fingerprint = mb_substr(trim($fingerprint), 0, 190);
        if ($issueKey === '' || $fingerprint === '') {
            return false;
        }
        if ($userId !== null && $userId > 0) {
            try {
                $user = $this->db->fetch('SELECT id FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
                if ($user === null) {
                    $userId = null;
                }
            } catch (\Throwable) {
                $userId = null;
            }
        } else {
            $userId = null;
        }
        try {
            $this->db->query(
                'INSERT IGNORE INTO treasury_health_dismissals (issue_key, fingerprint, user_id)
                 VALUES (:issue_key, :fingerprint, :user_id)',
                [
                    'issue_key' => $issueKey,
                    'fingerprint' => $fingerprint,
                    'user_id' => $userId,
                ]
            );
        } catch (\Throwable) {
            return false;
        }
        $this->audit->log('treasury.health_dismissed', 'treasury', null, null, [
            'issue_key' => $issueKey,
            'fingerprint' => $fingerprint,
        ], $ip, $userId);

        return true;
    }

    /**
     * @return array<string,array<string,true>>
     */
    private function healthDismissalMap(): array
    {
        try {
            $rows = $this->db->fetchAll(
                'SELECT issue_key, fingerprint
                   FROM treasury_health_dismissals
                  ORDER BY issue_key ASC, fingerprint ASC'
            );
        } catch (\Throwable) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            $issueKey = trim((string) ($row['issue_key'] ?? ''));
            $fingerprint = trim((string) ($row['fingerprint'] ?? ''));
            if ($issueKey === '' || $fingerprint === '') {
                continue;
            }
            $map[$issueKey][$fingerprint] = true;
        }

        return $map;
    }

    /**
     * @param array<string,array<string,true>> $dismissed
     */
    private function healthAlertDismissed(array $dismissed, string $issueKey, string $fingerprint): bool
    {
        return isset($dismissed[$issueKey][$fingerprint]);
    }

    /**
     * @param list<string> $parts
     */
    private function healthFingerprint(string $issueKey, array $parts): string
    {
        return sha1($issueKey . '|' . implode('|', $parts));
    }

    /** @return list<string> */
    public static function movementKinds(): array
    {
        return self::MOVEMENT_KINDS;
    }

    /**
     * UI metadata for movement kind + income/expense sign.
     *
     * @return list<array{key:string,directions:string,default:string}>
     */
    public static function movementKindMeta(): array
    {
        $defs = [
            'operating' => ['directions' => 'both', 'default' => 'income'],
            'internal_transfer' => ['directions' => 'both', 'default' => 'income'],
            'advance' => ['directions' => 'both', 'default' => 'expense'],
            'refund' => ['directions' => 'both', 'default' => 'income'],
            'loan' => ['directions' => 'both', 'default' => 'income'],
            'deposit' => ['directions' => 'both', 'default' => 'income'],
            'reversal' => ['directions' => 'both', 'default' => 'expense'],
            'adjustment' => ['directions' => 'both', 'default' => 'income'],
        ];
        $out = [];
        foreach (self::MOVEMENT_KINDS as $key) {
            $meta = $defs[$key] ?? ['directions' => 'both', 'default' => 'income'];
            $out[] = ['key' => $key, 'directions' => $meta['directions'], 'default' => $meta['default']];
        }
        return $out;
    }

    /**
     * @return list<array{label:string,keys:list<string>}>
     */
    public function paymentMethodGroups(): array
    {
        $groups = [
            ['label_key' => 'treasury.method_group_digital', 'keys' => [
                'bank_transfer', 'pos_card', 'satispay', 'paypal', 'online_gateway', 'sepa_direct_debit',
            ]],
            ['label_key' => 'treasury.method_group_traditional', 'keys' => ['cash', 'postal_slip', 'check']],
            ['label_key' => 'treasury.method_group_other', 'keys' => ['other']],
        ];
        $out = [];
        foreach ($groups as $group) {
            $keys = [];
            foreach ($group['keys'] as $key) {
                if (in_array($key, self::METHODS, true)) {
                    $keys[] = $key;
                }
            }
            if ($keys !== []) {
                $out[] = ['label' => __($group['label_key']), 'keys' => $keys];
            }
        }
        return $out;
    }

    /**
     * @return list<array{label:string,keys:list<string>}>
     */
    public function categoryGroups(): array
    {
        $groups = [
            ['label_key' => 'treasury.cat_group_income', 'keys' => [
                'membership_fee', 'donation', 'five_per_thousand', 'public_grants', 'institutional_activities',
                'events', 'sponsorship', 'commercial_sales', 'financial_income',
            ]],
            ['label_key' => 'treasury.cat_group_expense', 'keys' => [
                'rent', 'utilities', 'supplies', 'event_costs', 'volunteer_reimbursement', 'personnel',
                'services_consulting', 'insurance', 'bank_fees', 'maintenance', 'equipment', 'marketing_promo', 'taxes',
            ]],
            ['label_key' => 'treasury.cat_group_financial', 'keys' => [
                'internal_transfer', 'cash_deposit_withdrawal', 'member_loan', 'security_deposit',
            ]],
        ];
        $out = [];
        foreach ($groups as $group) {
            $keys = [];
            foreach ($group['keys'] as $key) {
                if (in_array($key, self::BUILTIN_CATEGORIES, true)) {
                    $keys[] = $key;
                }
            }
            if ($keys !== []) {
                $out[] = ['label' => __($group['label_key']), 'keys' => $keys];
            }
        }
        return $out;
    }

    /**
     * Categories ready for a <select>, with the same structure in movements, estimate and final
     * balance: Income / Expenses / Financial transactions / Custom / Other groups.
     *
     * @return list<array{label:string,options:list<array{value:string,label:string}>}>
     */
    public function categorySelectGroups(bool $includeNewOption = true): array
    {
        $map = $this->categoryMap();
        $groups = [];
        foreach ($this->categoryGroups() as $group) {
            $options = [];
            foreach ($group['keys'] as $key) {
                if ($key === 'other' || !isset($map[$key])) {
                    continue;
                }
                $options[] = ['value' => (string) $key, 'label' => (string) $map[$key]];
            }
            if ($options !== []) {
                $groups[] = ['label' => (string) $group['label'], 'options' => $options];
            }
        }
        $custom = [];
        foreach ($this->customCategories() as $row) {
            if ($row['slug'] === 'other') {
                continue;
            }
            $custom[] = ['value' => $row['slug'], 'label' => $row['label']];
        }
        if ($custom !== []) {
            $groups[] = ['label' => (string) __('treasury.cat_group_custom'), 'options' => $custom];
        }
        $other = [];
        if (isset($map['other'])) {
            $other[] = ['value' => 'other', 'label' => (string) $map['other']];
        }
        if ($includeNewOption) {
            $other[] = ['value' => '__new__', 'label' => (string) __('treasury.category_new')];
        }
        if ($other !== []) {
            $groups[] = ['label' => (string) __('treasury.cat_group_other'), 'options' => $other];
        }
        return $groups;
    }

    public static function normalizePaymentMethod(string $method): string
    {
        $method = trim($method);
        $aliases = [
            'bank' => 'bank_transfer',
            'pos' => 'pos_card',
            'credit_card' => 'pos_card',
        ];
        if (isset($aliases[$method])) {
            $method = $aliases[$method];
        }
        return in_array($method, self::METHODS, true) ? $method : 'other';
    }

    /**
     * Latest plausible year for an operation date (a few years ahead are tolerated for planning).
     */
    public static function maxMovementYear(): int
    {
        return PlausibleDate::maxYear();
    }

    /**
     * True when the year of an operation date is plausible (1900 … current + 5). Typed or imported
     * dates such as 03/05/0206 (year 206) are rejected instead of creating a movement in year 206.
     */
    public static function isPlausibleMovementYear(int $year): bool
    {
        return PlausibleDate::isPlausibleYear($year);
    }

    /**
     * Canonical document type key (empty when the value is not known).
     */
    public static function normalizeDocumentType(string $type): string
    {
        $type = mb_strtolower(trim($type));
        if ($type === '') {
            return '';
        }
        $type = (string) preg_replace('/[^a-z0-9]+/', '_', $type);
        $type = trim($type, '_');
        $aliases = [
            'fattura' => 'invoice',
            'invoice' => 'invoice',
            'rechnung' => 'invoice',
            'ricevuta' => 'receipt',
            'receipt' => 'receipt',
            'quittung' => 'receipt',
            'quietanza' => 'quittance',
            'quittance' => 'quittance',
            'zahlungsquittung' => 'quittance',
            'contratto' => 'contract',
            'contract' => 'contract',
            'vertrag' => 'contract',
            'premio' => 'premium',
            'premium' => 'premium',
            'praemie' => 'premium',
            'prämie' => 'premium',
            'nota_di_credito' => 'credit_note',
            'credit_note' => 'credit_note',
            'gutschrift' => 'credit_note',
            'nota_di_debito' => 'debit_note',
            'debit_note' => 'debit_note',
            'belastungsanzeige' => 'debit_note',
            'estratto_conto' => 'statement',
            'statement' => 'statement',
            'kontoauszug' => 'statement',
            'altro' => 'other',
            'altro_documento' => 'other',
            'other' => 'other',
            'sonstiger_beleg' => 'other',
        ];

        return $aliases[$type] ?? (in_array($type, self::DOCUMENT_TYPES, true) ? $type : '');
    }

    /**
     * Capitalisation of the free-text description (the short “causale”): the first letter is always
     * upper case and the rest lower case, whatever the operator typed, so the ledger, the CSV export
     * and the reports read consistently. The internal notes are left untouched (they are a memo).
     */
    public static function normalizeDescription(string $description): string
    {
        $description = trim($description);
        if ($description === '') {
            return '';
        }

        return mb_ucfirst(mb_strtolower($description));
    }

    /**
     * Wallets as configured in `treasury_accounts` (built-ins + custom ones).
     *
     * @return list<array{id:int,key:string,label:string,builtin:bool,active:bool,sort_order:int}>
     */
    public function accountOptions(bool $onlyActive = false): array
    {
        $out = [];
        foreach ($this->accountRows() as $row) {
            if ($onlyActive && !$row['active']) {
                continue;
            }
            $out[] = [
                'id' => $row['id'],
                'key' => $row['slug'],
                'label' => $this->accountRowLabel($row),
                'builtin' => $row['builtin'],
                'active' => $row['active'],
                'sort_order' => $row['sort_order'],
            ];
        }
        return $out;
    }

    public function accountLabel(string $key): string
    {
        $key = self::normalizeAccount($key);
        if ($key === '') {
            return '';
        }
        foreach ($this->accountRows() as $row) {
            if ($row['slug'] === $key) {
                return $this->accountRowLabel($row);
            }
        }
        // A slug without a wallet row (legacy or imported value) still has to read as a name.
        return sentence_case(str_replace('_', ' ', $key));
    }

    public function accountExists(string $key): bool
    {
        $key = self::normalizeAccount($key);
        if ($key === '') {
            return false;
        }
        foreach ($this->accountRows() as $row) {
            if ($row['slug'] === $key) {
                return true;
            }
        }
        return false;
    }

    public function accountIsActive(string $key): bool
    {
        $key = self::normalizeAccount($key);
        foreach ($this->accountRows() as $row) {
            if ($row['slug'] === $key) {
                return $row['active'];
            }
        }
        return false;
    }

    /** Whether a wallet is active by default on a fresh install / after a full reset. */
    public static function isDefaultActiveAccount(string $key): bool
    {
        return in_array(self::normalizeAccount($key), self::DEFAULT_ACTIVE_ACCOUNTS, true);
    }

    /** Wallet behind an incoming member payment, used by the auto-registered income. */
    public static function accountForPaymentMethod(string $method): string
    {
        return match (self::normalizePaymentMethod($method)) {
            'cash' => 'cash',
            'postal_slip' => 'postal',
            'paypal' => 'paypal',
            'other', 'internal_transfer', 'offset_compensation' => 'other',
            default => 'bank',
        };
    }

    /**
     * Ledger rows + global balance (the KPI boxes always reflect the whole cash book).
     *
     * @param array{direction?:string,category?:string,from?:string,to?:string,account?:string} $filters
     * @return array{movements:list<array<string,mixed>>,balance:float,income:float,expense:float,total:int,limit:int,offset:int}
     */
    public function ledger(int $limit = 200, string $query = '', string $sort = 'date_desc', array $filters = [], int $offset = 0, bool $groupByCategory = false): array
    {
        $sql = "SELECT m.*,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'first_name'
                     WHERE v.member_id = mem.id LIMIT 1) AS first_name,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'last_name'
                     WHERE v.member_id = mem.id LIMIT 1) AS last_name,
                    mem.member_number,
                    u.name AS creator_name,
                    (SELECT COALESCE(NULLIF(o.account, ''), NULL) FROM treasury_movements o
                     WHERE o.id = m.transfer_origin_id LIMIT 1) AS transfer_origin_account,
                    (SELECT o.movement_date FROM treasury_movements o
                     WHERE o.id = m.transfer_origin_id LIMIT 1) AS transfer_origin_date,
                    (SELECT CONCAT(b.fiscal_year, ' · ', i.label)
                     FROM budget_items i
                     INNER JOIN budgets b ON b.id = i.budget_id
                     WHERE i.id = m.budget_item_id) AS budget_item_label
             FROM treasury_movements m
             LEFT JOIN members mem ON mem.id = m.member_id
             LEFT JOIN users u ON u.id = m.created_by";

        [$where, $params] = $this->ledgerConditions($query, $filters);
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $order = $this->ledgerOrder($sort, $groupByCategory);
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $sql .= ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $movements = $this->db->fetchAll($sql, $params);
        // Display fallback for legacy/plugin rows: the first letter of the description and of the
        // counterparty is always upper case, even when the stored text predates the normalisation.
        foreach ($movements as &$movement) {
            $movement['description'] = mb_ucfirst((string) ($movement['description'] ?? ''));
            $beneficiary = trim((string) ($movement['beneficiary'] ?? ''));
            if ($beneficiary !== '') {
                $movement['beneficiary'] = mb_ucfirst($beneficiary);
            }
        }
        unset($movement);

        $balanceRow = $this->db->fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) AS inc,
                COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) AS exp
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL"
        );
        $balance = (float) ($balanceRow['inc'] ?? 0) - (float) ($balanceRow['exp'] ?? 0);

        return [
            'movements' => $movements,
            'balance' => $balance,
            'income' => (float) ($balanceRow['inc'] ?? 0),
            'expense' => (float) ($balanceRow['exp'] ?? 0),
            'total' => $this->ledgerTotal($query, $filters),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * ORDER BY clause of the ledger: whitelisted keys (the SQL never contains user input) with a
     * deterministic tie-breaker, so pagination stays stable.
     *
     * When the ledger is read grouped by type the category comes first: the rows of a page are then
     * bucketed by the view, so the group has to be part of the *database* order — otherwise every
     * page would rebucket whatever slice it received and the chosen sort would only reorder the
     * visible page.
     */
    private function ledgerOrder(string $sort, bool $groupByCategory = false): string
    {
        $tie = 'm.movement_date DESC, m.id DESC';

        $order = match ($sort) {
            'date_asc' => 'm.movement_date ASC, m.id ASC',
            'created_desc' => 'm.created_at DESC, m.id DESC',
            'updated_desc' => 'COALESCE(m.updated_at, m.created_at) DESC, m.id DESC',
            'updated_asc' => 'COALESCE(m.updated_at, m.created_at) ASC, m.id ASC',
            'invoice_desc' => 'm.invoice_date IS NULL, m.invoice_date DESC, ' . $tie,
            'description_asc' => '(m.description IS NULL OR m.description = \'\'), m.description ASC, ' . $tie,
            'description_desc' => '(m.description IS NULL OR m.description = \'\'), m.description DESC, ' . $tie,
            'method_asc' => $this->methodOrderClause('ASC') . ', ' . $tie,
            'method_desc' => $this->methodOrderClause('DESC') . ', ' . $tie,
            // “Entrata” / “Uscita” columns: the amount counts only for its own direction, so mixed
            // rows (income, expense, transfers) keep a predictable order.
            'income_asc' => "CASE WHEN m.direction = 'income' THEN m.amount ELSE 0 END ASC, " . $tie,
            'income_desc' => "CASE WHEN m.direction = 'income' THEN m.amount ELSE 0 END DESC, " . $tie,
            'expense_asc' => "CASE WHEN m.direction = 'expense' THEN m.amount ELSE 0 END ASC, " . $tie,
            'expense_desc' => "CASE WHEN m.direction = 'expense' THEN m.amount ELSE 0 END DESC, " . $tie,
            default => $tie,
        };

        return $groupByCategory
            ? $this->categoryOrderClause() . ', ' . $order
            : $order;
    }

    /**
     * Category rank in the same order as the group list shown by the ledger (built-in types first,
     * then the custom ones), so pages stay contiguous while the chosen sort applies inside each
     * group. Unknown/empty types go last, matching `groupedByCategory()`.
     */
    private function categoryOrderClause(): string
    {
        $when = [];
        $rank = 1;
        foreach ($this->categoryOptions() as $option) {
            $key = (string) ($option['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $when[] = "WHEN '" . str_replace("'", "''", $key) . "' THEN " . $rank;
            $rank++;
        }
        if ($when === []) {
            return 'm.category ASC';
        }

        return 'CASE COALESCE(NULLIF(m.category, \'\'), \'other\') ' . implode(' ', $when)
            . ' ELSE ' . $rank . ' END ASC';
    }

    /**
     * Payment methods ordered by their localised label (the stored value is a slug such as
     * `bank_transfer`), using FIELD() over the whitelisted methods.
     */
    private function methodOrderClause(string $direction): string
    {
        $labels = [];
        foreach (self::METHODS as $method) {
            $labels[$method] = (string) __('treasury.method_' . $method);
        }
        uksort($labels, static fn (string $a, string $b): int => strcasecmp($labels[$a], $labels[$b]));
        $values = [];
        foreach (array_keys($labels) as $key) {
            $values[] = "'" . str_replace("'", "''", (string) $key) . "'";
        }

        return 'FIELD(m.payment_method, ' . implode(', ', $values) . ') ' . $direction;
    }

    /** Total rows matching the current ledger filters (for the pager). @param array<string,mixed> $filters */
    public function ledgerTotal(string $query = '', array $filters = []): int
    {
        [$where, $params] = $this->ledgerConditions($query, $filters);
        $sql = 'SELECT COUNT(*) AS c FROM treasury_movements m LEFT JOIN members mem ON mem.id = m.member_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return (int) ($this->db->fetch($sql, $params)['c'] ?? 0);
    }

    /**
     * Shared WHERE clause for the ledger list and its count.
     *
     * @param array<string,mixed> $filters
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    private function ledgerConditions(string $query, array $filters): array
    {
        $where = [];
        $params = [];
        $query = trim($query);
        if ($query !== '') {
            $where[] = $this->buildSearchWhere($query, $params);
        }
        $direction = (string) ($filters['direction'] ?? '');
        if ($direction === 'transfer') {
            // “Internal transfer” collects the classic internal transfers, the superseded (struck
            // through) originals and the copies generated on the destination account.
            $where[] = "(m.direction = 'transfer' OR m.superseded_at IS NOT NULL OR m.transfer_origin_id IS NOT NULL)";
        } elseif (in_array($direction, self::DIRECTIONS, true)) {
            $where[] = 'm.direction = :filter_direction';
            $params['filter_direction'] = $direction;
        }
        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            if ($category === '__none__') {
                // “Not assigned”: movements without category.
                $where[] = "(m.category IS NULL OR m.category = '')";
            } else {
                $where[] = 'm.category = :filter_category';
                $params['filter_category'] = $category;
            }
        }
        $runtsFilter = trim((string) ($filters['runts_line'] ?? ''));
        if ($runtsFilter !== '') {
            // Model D is computed on the approved register only: superseded originals (the struck
            // through copies of an internal transfer) must not inflate the drill-down count.
            $where[] = 'm.superseded_at IS NULL';
            if ($runtsFilter === '__none__') {
                $where[] = "(m.runts_line IS NULL OR m.runts_line = '')";
            } else {
                $condition = $this->runtsLineCondition(strtoupper($runtsFilter), $params);
                if ($condition !== null) {
                    $where[] = $condition;
                }
            }
        }
        $budgetFilter = $filters['budget_item_id'] ?? '';
        if ((string) $budgetFilter === '__none__') {
            $where[] = 'm.budget_item_id IS NULL';
        } elseif ((int) $budgetFilter > 0) {
            $where[] = 'm.budget_item_id = :filter_budget_item';
            $params['filter_budget_item'] = (int) $budgetFilter;
        }
        if (array_key_exists('pattern_ids', $filters)) {
            $patternIds = [];
            foreach ((array) $filters['pattern_ids'] as $patternId) {
                $patternId = (int) $patternId;
                if ($patternId > 0) {
                    $patternIds[$patternId] = $patternId;
                }
            }
            if ($patternIds === []) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'm.id IN (' . implode(', ', array_values($patternIds)) . ')';
            }
        }
        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $where[] = 'm.movement_date >= :filter_from';
            $params['filter_from'] = $from;
        }
        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $where[] = 'm.movement_date <= :filter_to';
            $params['filter_to'] = $to;
        }
        // Month/year of the operation date. A month + year becomes a date range, so the index on
        // `movement_date` stays usable; a month alone compares the month number.
        $month = (int) ($filters['month'] ?? 0);
        $year = (int) ($filters['year'] ?? 0);
        $validMonth = $month >= 1 && $month <= 12;
        $validYear = $year >= 1900 && $year <= 2200;
        if ($validMonth && $validYear) {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $where[] = 'm.movement_date >= :filter_month_from AND m.movement_date < :filter_month_to';
            $params['filter_month_from'] = $start;
            $params['filter_month_to'] = date('Y-m-d', (int) strtotime($start . ' +1 month'));
        } elseif ($validMonth) {
            $where[] = 'MONTH(m.movement_date) = :filter_month';
            $params['filter_month'] = $month;
        } elseif ($validYear) {
            $where[] = 'm.movement_date >= :filter_year_from AND m.movement_date < :filter_year_to';
            $params['filter_year_from'] = sprintf('%04d-01-01', $year);
            $params['filter_year_to'] = sprintf('%04d-01-01', $year + 1);
        }
        $account = self::normalizeAccount((string) ($filters['account'] ?? ''));
        if ($account !== '') {
            // A wallet filter also matches the destination of a internal transfer.
            $where[] = '(m.account = :filter_account OR m.transfer_account = :filter_account_to)';
            $params['filter_account'] = $account;
            $params['filter_account_to'] = $account;
        }
        $approval = (string) ($filters['approval'] ?? '');
        if ($approval === 'pending') {
            // The cash desk never needs the approval: cash rows never show up in the queue.
            $where[] = "m.approved_at IS NULL AND (m.account IS NULL OR m.account <> 'cash')";
        } elseif ($approval === 'approved') {
            $where[] = "(m.approved_at IS NOT NULL OR m.account = 'cash')";
        }
        // Supplier drill-down: the stored spelling wins, comparison ignores case and extra spaces.
        $beneficiary = trim((string) preg_replace('/\s+/u', ' ', (string) ($filters['beneficiary'] ?? '')));
        if ($beneficiary !== '') {
            $where[] = 'LOWER(TRIM(m.beneficiary)) = LOWER(:filter_beneficiary)';
            $params['filter_beneficiary'] = $beneficiary;
        }
        // Drill-down from a register row without any counterparty (no linked member and no
        // free-text beneficiary, e.g. after a member was deleted).
        if ((string) ($filters['no_counterparty'] ?? '') === '1') {
            $where[] = "(m.member_id IS NULL OR m.member_id = 0)"
                . " AND (m.beneficiary IS NULL OR TRIM(m.beneficiary) = '')";
        }

        return [$where, $params];
    }

    /**
     * WHERE condition for one Model D item. The explicit item wins when it is valid for the movement
     * direction; legacy rows with an invalid item still fall back to the treasury category. Rows
     * without any explicit item are not assigned: they stay in the “no Model D line” filter until
     * the operator chooses one, matching RuntsReportService::report().
     *
     * @param array<string,mixed> $params
     */
    private function runtsLineCondition(string $line, array &$params): ?string
    {
        $directions = RuntsReportService::lineDirections($line);
        if ($directions === []) {
            return null;
        }
        $bindList = static function (array $values, string $prefix) use (&$params): string {
            $placeholders = [];
            foreach (array_values($values) as $index => $value) {
                $name = $prefix . '_' . $index;
                $placeholders[] = ':' . $name;
                $params[$name] = (string) $value;
            }
            return $placeholders === [] ? 'NULL' : implode(', ', $placeholders);
        };

        $branches = [];
        foreach ($directions as $index => $direction) {
            $suffix = (string) $index;
            $fallback = [];
            $categories = RuntsReportService::categoriesForLine($line, $direction);
            if ($categories !== []) {
                $fallback[] = 'LOWER(m.category) IN (' . $bindList($categories, 'filter_runts_cat_' . $suffix) . ')';
            }
            if (RuntsReportService::isFallbackLine($line, $direction)) {
                $mapped = RuntsReportService::categoriesMappedFor($direction);
                $fallback[] = "(m.category IS NULL OR m.category = '' OR LOWER(m.category) NOT IN ("
                    . $bindList($mapped, 'filter_runts_mapped_' . $suffix) . '))';
            }
            if ($fallback === []) {
                continue;
            }
            $directionParam = 'filter_runts_dir_' . $suffix;
            $lineParam = 'filter_runts_line_' . $suffix;
            $params[$directionParam] = $direction;
            $params[$lineParam] = $line;
            $known = $bindList(RuntsReportService::knownLines($direction), 'filter_runts_known_' . $suffix);
            $branches[] = "(m.direction = :{$directionParam}"
                . " AND (UPPER(TRIM(m.runts_line)) = :{$lineParam}"
                . " OR ((m.runts_line IS NOT NULL AND TRIM(m.runts_line) <> ''"
                . " AND UPPER(TRIM(m.runts_line)) NOT IN ({$known}))"
                . ' AND (' . implode(' OR ', $fallback) . '))))';
        }

        return $branches === [] ? null : '(' . implode(' OR ', $branches) . ')';
    }

    /** @return array{id:int,slug:string,label:?string,builtin:bool,active:bool,sort_order:int}|null */
    public function accountById(int $id): ?array
    {
        foreach ($this->accountRows() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string,int> slug => ledger rows referencing the wallet */
    public function accountUsage(): array
    {
        $usage = [];
        foreach ($this->db->fetchAll(
            "SELECT account AS slug, COUNT(*) AS c
             FROM treasury_movements
             WHERE account IS NOT NULL AND account <> ''
             GROUP BY account"
        ) as $row) {
            $slug = self::normalizeAccount((string) $row['slug']);
            if ($slug !== '') {
                $usage[$slug] = ($usage[$slug] ?? 0) + (int) $row['c'];
            }
        }
        foreach ($this->db->fetchAll(
            "SELECT transfer_account AS slug, COUNT(*) AS c
             FROM treasury_movements
             WHERE transfer_account IS NOT NULL AND transfer_account <> ''
             GROUP BY transfer_account"
        ) as $row) {
            $slug = self::normalizeAccount((string) $row['slug']);
            if ($slug !== '') {
                $usage[$slug] = ($usage[$slug] ?? 0) + (int) $row['c'];
            }
        }
        return $usage;
    }

    /**
     * Net balance for wallets. The ledger stays unique, but every wallet is its own entity: a
     * transfer is an outflow from the source wallet and an inflow to the destination, while
     * income/expense only touch one wallet.
     *
     * @param array{from?:string,to?:string} $range
     * @return list<array{key:string,label:string,active:bool,income:float,expense:float,transfer_in:float,transfer_out:float,balance:float}>
     */
    public function accountBalances(array $range = []): array
    {
        $where = '';
        $params = [];
        $from = trim((string) ($range['from'] ?? ''));
        if ($from !== '') {
            $where .= ' AND movement_date >= :range_from';
            $params['range_from'] = $from;
        }
        $to = trim((string) ($range['to'] ?? ''));
        if ($to !== '') {
            $where .= ' AND movement_date <= :range_to';
            $params['range_to'] = $to;
        }

        $empty = ['income' => 0.0, 'expense' => 0.0, 'transfer_in' => 0.0, 'transfer_out' => 0.0];
        $totals = [];
        foreach ($this->db->fetchAll(
            'SELECT account, direction, COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL' . $where . '
             GROUP BY account, direction',
            $params
        ) as $row) {
            $key = self::normalizeAccount((string) ($row['account'] ?? ''));
            $totals[$key] ??= $empty;
            $total = (float) ($row['total'] ?? 0);
            switch ((string) ($row['direction'] ?? '')) {
                case 'income':
                    $totals[$key]['income'] += $total;
                    break;
                case 'expense':
                    $totals[$key]['expense'] += $total;
                    break;
                case 'transfer':
                    $totals[$key]['transfer_out'] += $total;
                    break;
            }
        }
        foreach ($this->db->fetchAll(
            "SELECT transfer_account, COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE direction = 'transfer' AND approved_at IS NOT NULL AND superseded_at IS NULL AND transfer_account IS NOT NULL AND transfer_account <> ''" . $where . '
             GROUP BY transfer_account',
            $params
        ) as $row) {
            $key = self::normalizeAccount((string) ($row['transfer_account'] ?? ''));
            if ($key === '') {
                continue;
            }
            $totals[$key] ??= $empty;
            $totals[$key]['transfer_in'] += (float) ($row['total'] ?? 0);
        }

        $out = [];
        foreach ($this->accountOptions() as $opt) {
            $key = (string) $opt['key'];
            $sums = $totals[$key] ?? $empty;
            unset($totals[$key]);
            $out[] = [
                'key' => $key,
                'label' => (string) $opt['label'],
                'active' => (bool) $opt['active'],
                'income' => $sums['income'],
                'expense' => $sums['expense'],
                'transfer_in' => $sums['transfer_in'],
                'transfer_out' => $sums['transfer_out'],
                'balance' => $sums['income'] - $sums['expense'] + $sums['transfer_in'] - $sums['transfer_out'],
            ];
        }
        // Legacy rows without a (known) wallet get their own bucket.
        foreach ($totals as $key => $sums) {
            $out[] = [
                'key' => $key,
                'label' => $key === '' ? (string) __('treasury.account_unassigned') : $this->accountLabel($key),
                'active' => false,
                'income' => $sums['income'],
                'expense' => $sums['expense'],
                'transfer_in' => $sums['transfer_in'],
                'transfer_out' => $sums['transfer_out'],
                'balance' => $sums['income'] - $sums['expense'] + $sums['transfer_in'] - $sums['transfer_out'],
            ];
        }
        return $out;
    }

    /**
     * Wallets for the "Cash book" overview and its ledger filter: only the wallets the association
     * has actually enabled, plus the deactivated ones that still carry movements (a closed account
     * with a residual balance must remain visible, otherwise money would disappear from the
     * summary).
     *
     * @param array{from?:string,to?:string} $range
     * @return array{
     * balances: list<array{key:string,label:string,active:bool,income:float,expense:float,transfer_in:float,transfer_out:float,balance:float}>,
     * accounts: list<array{id:int,key:string,label:string,builtin:bool,active:bool,sort_order:int}>
     * }
     */
    public function overviewWallets(array $range = []): array
    {
        $balances = [];
        $visible = [];
        foreach ($this->accountBalances($range) as $row) {
            if (!$row['active'] && !$this->walletHasActivity($row)) {
                continue;
            }
            $balances[] = $row;
            $visible[(string) $row['key']] = true;
        }

        $accounts = array_values(array_filter(
            $this->accountOptions(),
            static fn (array $option): bool => isset($visible[(string) $option['key']])
        ));

        return ['balances' => $balances, 'accounts' => $accounts];
    }

    /** @param array<string,mixed> $row */
    private function walletHasActivity(array $row): bool
    {
        return (float) ($row['income'] ?? 0) !== 0.0
            || (float) ($row['expense'] ?? 0) !== 0.0
            || (float) ($row['transfer_in'] ?? 0) !== 0.0
            || (float) ($row['transfer_out'] ?? 0) !== 0.0;
    }

    /** @return array{ok:bool,id?:int,slug?:string,errors?:array<string,string>} */
    public function createAccount(string $label, bool $active = true, string $ip = '', ?int $userId = null): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 80) {
            return ['ok' => false, 'errors' => ['label' => __('treasury.account_label_invalid')]];
        }
        $slug = $this->slugifyAccount($label);
        if ($slug === '' || in_array($slug, self::BUILTIN_ACCOUNTS, true) || $this->accountExists($slug)) {
            return ['ok' => false, 'errors' => ['label' => __('treasury.account_exists')]];
        }
        $max = (int) ($this->db->fetch('SELECT COALESCE(MAX(sort_order), 100) AS m FROM treasury_accounts')['m'] ?? 100);
        $id = $this->db->insert('treasury_accounts', [
            'slug' => $slug,
            'label' => $label,
            'is_builtin' => 0,
            'is_active' => $active ? 1 : 0,
            'sort_order' => $max + 10,
        ]);
        $this->accountsCache = null;
        $this->audit->log('treasury.account_created', 'treasury', (string) $id, null, ['slug' => $slug, 'label' => $label], $ip, $userId);
        return ['ok' => true, 'id' => $id, 'slug' => $slug];
    }

    /** @return array{ok:bool,errors?:array<string,string>} */
    public function updateAccount(int $id, string $label, bool $active, string $ip = '', ?int $userId = null): array
    {
        $row = $this->accountById($id);
        if ($row === null) {
            return ['ok' => false, 'errors' => ['account' => __('errors.404')]];
        }
        $label = trim($label);
        // Built-ins may fall back to the translated label (empty = reset).
        if (mb_strlen($label) > 80 || ($label === '' && !$row['builtin'])) {
            return ['ok' => false, 'errors' => ['label' => __('treasury.account_label_invalid')]];
        }
        $this->db->update('treasury_accounts', [
            'label' => $label !== '' ? $label : null,
            'is_active' => $active ? 1 : 0,
        ], 'id = :id', ['id' => $id]);
        $this->accountsCache = null;
        $this->audit->log('treasury.account_updated', 'treasury', (string) $id, [
            'label' => $row['label'],
            'active' => $row['active'],
        ], [
            'label' => $label !== '' ? $label : null,
            'active' => $active,
        ], $ip, $userId);
        return ['ok' => true];
    }

    /** @return array{ok:bool,errors?:array<string,string>} */
    public function deleteAccount(int $id, string $ip = '', ?int $userId = null): array
    {
        $row = $this->accountById($id);
        if ($row === null) {
            return ['ok' => false, 'errors' => ['account' => __('errors.404')]];
        }
        $usage = $this->accountUsage()[$row['slug']] ?? 0;
        if ($usage > 0) {
            return ['ok' => false, 'errors' => ['account' => __('treasury.account_in_use', ['count' => (string) $usage])]];
        }
        $this->db->query('DELETE FROM treasury_accounts WHERE id = :id', ['id' => $id]);
        $this->accountsCache = null;
        $this->audit->log('treasury.account_deleted', 'treasury', (string) $id, ['slug' => $row['slug'], 'label' => $row['label']], null, $ip, $userId);
        return ['ok' => true];
    }

    /**
     * Opening balances currently stored in the ledger, keyed by wallet slug.
     *
     * @return array<string,float>
     */
    public function openingBalances(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT account, amount FROM treasury_movements
              WHERE is_opening_balance = 1 AND superseded_at IS NULL
              ORDER BY id ASC'
        );
        $out = [];
        foreach ($rows as $row) {
            $key = self::normalizeAccount((string) ($row['account'] ?? ''));
            if ($key !== '') {
                $out[$key] = round((float) ($row['amount'] ?? 0), 2);
            }
        }

        return $out;
    }

    /**
     * Flags an already-created movement as the opening balance of its wallet (the setup wizard
     * writes it through create() and then marks it here).
     */
    public function markOpeningBalance(int $id): void
    {
        if ($id < 1) {
            return;
        }
        $this->db->update('treasury_movements', ['is_opening_balance' => 1], 'id = :id', ['id' => $id]);
    }

    /**
     * Sets (or removes, with 0) the opening balance of a wallet. It is stored as the first income
     * movement of that account, so every balance, chart and report keeps reading the ledger only.
     *
     * @return array{ok:bool,errors?:array<string,string>}
     */
    public function setAccountOpeningBalance(string $account, float $amount, string $ip = '', ?int $userId = null): array
    {
        $account = self::normalizeAccount($account);
        $label = '';
        foreach ($this->accountOptions() as $option) {
            if ((string) ($option['key'] ?? '') === $account) {
                $label = (string) ($option['label'] ?? '');
                break;
            }
        }
        if ($account === '' || $label === '') {
            return ['ok' => false, 'errors' => ['account' => __('errors.404')]];
        }
        if (!is_finite($amount) || $amount < 0 || $amount > 999999999.99) {
            return ['ok' => false, 'errors' => ['account' => __('setup.accounts_opening_invalid')]];
        }
        $amount = round($amount, 2);
        $existing = $this->db->fetch(
            "SELECT id, amount FROM treasury_movements
              WHERE is_opening_balance = 1 AND account = :account AND superseded_at IS NULL
              ORDER BY id ASC LIMIT 1",
            ['account' => $account]
        );
        if ($amount <= 0.0) {
            if ($existing === null) {
                return ['ok' => true];
            }
            $movementId = (int) $existing['id'];
            $this->db->query('DELETE FROM treasury_movements WHERE id = :id', ['id' => $movementId]);
            $this->audit->log('treasury.opening_balance_removed', 'treasury', (string) $movementId, [
                'account' => $account,
                'amount' => (float) ($existing['amount'] ?? 0),
            ], null, $ip, $userId);

            return ['ok' => true];
        }
        $description = mb_substr(
            (string) __('setup.accounts_opening_movement', ['account' => $label]),
            0,
            500
        );
        if ($existing !== null) {
            $movementId = (int) $existing['id'];
            $this->db->update('treasury_movements', [
                'amount' => $amount,
                'description' => $description,
                'approved_at' => date('Y-m-d H:i:s'),
                'approved_by' => $userId,
            ], 'id = :id', ['id' => $movementId]);
            $this->audit->log('treasury.opening_balance_updated', 'treasury', (string) $movementId, [
                'amount' => (float) ($existing['amount'] ?? 0),
            ], ['amount' => $amount, 'account' => $account], $ip, $userId);

            return ['ok' => true];
        }
        $movementId = $this->db->insert('treasury_movements', [
            'movement_date' => date('Y-m-d'),
            'direction' => 'income',
            'category' => 'other',
            'amount' => $amount,
            'description' => $description,
            'payment_method' => $account === 'cash' ? 'cash' : 'bank_transfer',
            'account' => $account,
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => $userId,
            'is_opening_balance' => 1,
        ]);
        $this->audit->log('treasury.opening_balance_set', 'treasury', (string) $movementId, null, [
            'account' => $account,
            'amount' => $amount,
        ], $ip, $userId);

        return ['ok' => true];
    }

    /** Slug sanitised to `[a-z0-9_]` (max 40 chars), matching the stored wallet keys. */
    public static function normalizeAccount(string $account): string
    {
        $account = mb_strtolower(trim($account));
        if ($account === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $account);
            if (is_string($trans) && $trans !== '') {
                $account = $trans;
            }
        }
        $account = preg_replace('/[^a-z0-9]+/', '_', $account) ?? '';
        return trim(mb_substr(trim($account, '_'), 0, 40), '_');
    }

    private function slugifyAccount(string $label): string
    {
        return self::normalizeAccount($label);
    }

    /** @return list<array{id:int,slug:string,label:?string,builtin:bool,active:bool,sort_order:int}> */
    private function accountRows(): array
    {
        if ($this->accountsCache !== null) {
            return $this->accountsCache;
        }
        $rows = $this->db->fetchAll(
            'SELECT id, slug, label, is_builtin, is_active, sort_order
             FROM treasury_accounts
             ORDER BY sort_order ASC, label ASC, id ASC'
        );
        $out = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $out[] = [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
                'label' => $label !== '' ? $label : null,
                'builtin' => !empty($row['is_builtin']),
                'active' => !empty($row['is_active']),
                'sort_order' => (int) $row['sort_order'],
            ];
        }
        $this->accountsCache = $out;
        return $out;
    }

    /** @param array{slug:string,label:?string,builtin:bool} $row */
    private function accountRowLabel(array $row): string
    {
        $label = trim((string) ($row['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        if ($row['builtin'] && in_array($row['slug'], self::BUILTIN_ACCOUNTS, true)) {
            return (string) __('treasury.account_' . $row['slug']);
        }
        return $this->humanizeSlug((string) $row['slug']);
    }

    /**
     * Association balance charts for the dashboard.
     *
     * @return array{
     *   flow: array{labels:list<string>,values:list<float>},
     *   expense_by_category: array{labels:list<string>,values:list<float>},
     *   income_by_category: array{labels:list<string>,values:list<float>},
     *   monthly_flow: array{labels:list<string>,income:list<float>,expense:list<float>},
     *   balance_trend: array{labels:list<string>,values:list<float>},
     *   by_account: array{labels:list<string>,values:list<float>},
     *   top_beneficiaries: array{labels:list<string>,values:list<float>},
     *   status: array{labels:list<string>,values:list<int>}
     * }
     */
    public function dashboardCharts(): array
    {
        $totals = $this->db->fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) AS inc,
                COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) AS exp
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL"
        );
        $income = (float) ($totals['inc'] ?? 0);
        $expense = (float) ($totals['exp'] ?? 0);

        return [
            'flow' => [
                'labels' => [
                    __('treasury.direction_income'),
                    __('treasury.direction_expense'),
                ],
                'values' => [$income, $expense],
            ],
            'expense_by_category' => $this->sumsByCategory('expense'),
            'income_by_category' => $this->sumsByCategory('income'),
            'monthly_flow' => $this->monthlyFlow(),
            'balance_trend' => $this->balanceTrend(),
            'by_account' => $this->walletBalances(),
            'top_beneficiaries' => $this->topBeneficiaries(),
            'status' => $this->approvalStatus(),
        ];
    }

    /**
     * @return array{labels:list<string>,values:list<float>}
     */
    private function sumsByCategory(string $direction): array
    {
        $rows = $this->db->fetchAll(
            "SELECT category, COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE direction = :dir AND approved_at IS NOT NULL AND superseded_at IS NULL
             GROUP BY category
             HAVING total > 0
             ORDER BY total DESC
             LIMIT 8",
            ['dir' => $direction]
        );
        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['category'] ?? ''));
            if ($key === '') {
                $key = 'other';
            }
            $labels[] = $this->categoryLabel($key);
            $values[] = (float) ($row['total'] ?? 0);
        }
        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Income and expense of the last 12 months, month by month (approved movements only): the trend a
     * treasurer looks at first. Month names reuse the shared `common.month_N` labels.
     *
     * @return array{labels:list<string>,income:list<float>,expense:list<float>}
     */
    private function monthlyFlow(int $months = 12): array
    {
        $months = max(1, min(24, $months));
        $start = (new \DateTimeImmutable('first day of this month'))->modify('-' . ($months - 1) . ' months');
        $byMonth = [];
        foreach ($this->db->fetchAll(
            "SELECT DATE_FORMAT(movement_date, '%Y-%m') AS ym,
                    COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) AS inc,
                    COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) AS exp
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL AND movement_date >= :from
             GROUP BY ym",
            ['from' => $start->format('Y-m-d')]
        ) as $row) {
            $byMonth[(string) ($row['ym'] ?? '')] = [(float) ($row['inc'] ?? 0), (float) ($row['exp'] ?? 0)];
        }

        $labels = [];
        $income = [];
        $expense = [];
        for ($i = 0; $i < $months; $i++) {
            $cursor = $start->modify('+' . $i . ' months');
            $key = $cursor->format('Y-m');
            $labels[] = mb_substr((string) __('common.month_' . (int) $cursor->format('n')), 0, 3);
            $income[] = $byMonth[$key][0] ?? 0.0;
            $expense[] = $byMonth[$key][1] ?? 0.0;
        }

        return ['labels' => $labels, 'income' => $income, 'expense' => $expense];
    }

    /**
     * Running cash-book balance at the end of each of the last 12 months: the trend of the
     * association's liquidity (a transfer between own wallets never moves it).
     *
     * @return array{labels:list<string>,values:list<float>}
     */
    private function balanceTrend(int $months = 12): array
    {
        $months = max(1, min(24, $months));
        $flow = $this->monthlyFlow($months);
        $start = (new \DateTimeImmutable('first day of this month'))->modify('-' . ($months - 1) . ' months');
        $opening = $this->db->fetch(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'income' THEN amount WHEN direction = 'expense' THEN -amount ELSE 0 END), 0) AS net
             FROM treasury_movements
             WHERE approved_at IS NOT NULL AND superseded_at IS NULL AND movement_date < :from",
            ['from' => $start->format('Y-m-d')]
        );
        $running = (float) ($opening['net'] ?? 0);
        $values = [];
        foreach ($flow['labels'] as $index => $_) {
            $running += (float) ($flow['income'][$index] ?? 0) - (float) ($flow['expense'][$index] ?? 0);
            $values[] = round($running, 2);
        }

        return ['labels' => $flow['labels'], 'values' => $values];
    }

    /**
     * Current liquidity per wallet (cash desk, bank, …), internal transfers included: “where the
     * money actually is”, which the global income − expense balance cannot show.
     *
     * @return array{labels:list<string>,values:list<float>}
     */
    private function walletBalances(): array
    {
        $labels = [];
        $values = [];
        foreach ($this->accountBalances() as $account) {
            $balance = (float) ($account['balance'] ?? 0);
            if (abs($balance) < 0.005) {
                continue;
            }
            $labels[] = (string) ($account['label'] ?? '');
            $values[] = round($balance, 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Counterparties the association pays the most (top expenses by beneficiary).
     *
     * @return array{labels:list<string>,values:list<float>}
     */
    private function topBeneficiaries(int $limit = 5): array
    {
        $labels = [];
        $values = [];
        foreach ($this->db->fetchAll(
            "SELECT TRIM(beneficiary) AS name, COALESCE(SUM(amount), 0) AS total
             FROM treasury_movements
             WHERE direction = 'expense' AND approved_at IS NOT NULL AND superseded_at IS NULL
               AND beneficiary IS NOT NULL AND TRIM(beneficiary) <> ''
             GROUP BY name
             ORDER BY total DESC
             LIMIT " . max(1, min(10, $limit))
        ) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $labels[] = mb_strlen($name) > 26 ? mb_substr($name, 0, 25) . '…' : $name;
            $values[] = (float) ($row['total'] ?? 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Movements still waiting for the treasurer's approval: a to-do queue on the dashboard.
     *
     * @return array{labels:list<string>,values:list<int>}
     */
    private function approvalStatus(): array
    {
        $row = $this->db->fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN approved_at IS NOT NULL OR account = 'cash' THEN 1 ELSE 0 END), 0) AS approved,
                COALESCE(SUM(CASE WHEN approved_at IS NULL AND (account IS NULL OR account <> 'cash') THEN 1 ELSE 0 END), 0) AS pending
             FROM treasury_movements
             WHERE superseded_at IS NULL"
        );

        return [
            'labels' => [
                (string) __('dashboard.treasury_status_approved'),
                (string) __('dashboard.treasury_status_pending'),
            ],
            'values' => [(int) ($row['approved'] ?? 0), (int) ($row['pending'] ?? 0)],
        ];
    }

    /**
     * @return list<array{key:string,label:string,items:list<array<string,mixed>>}>
     */
    /**
     * @param array{direction?:string,category?:string,from?:string,to?:string} $filters
     * @return list<array{key:string,label:string,items:list<array>}>
     */
    public function groupedByCategory(int $limit = 200, string $query = '', string $sort = 'date_desc', array $filters = [], int $offset = 0): array
    {
        // The category is part of the SQL order: grouping a page after the fact would only sort the
        // rows that page happened to receive (see ledgerOrder()).
        $ledger = $this->ledger($limit, $query, $sort, $filters, $offset, true);
        $items = $ledger['movements'];
        $labels = $this->categoryMap();
        $buckets = [];
        foreach ($items as $item) {
            $key = trim((string) ($item['category'] ?? 'other'));
            if ($key === '') {
                $key = 'other';
            }
            if (!isset($buckets[$key])) {
                $buckets[$key] = [];
            }
            $buckets[$key][] = $item;
        }

        $ordered = [];
        foreach ($this->categoryOptions() as $opt) {
            $key = (string) $opt['key'];
            if (!isset($buckets[$key])) {
                continue;
            }
            $ordered[] = [
                'key' => $key,
                'label' => (string) $opt['label'],
                'items' => $buckets[$key],
            ];
            unset($buckets[$key]);
        }
        ksort($buckets);
        foreach ($buckets as $key => $groupItems) {
            $ordered[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $this->humanizeSlug($key),
                'items' => $groupItems,
            ];
        }
        return $ordered;
    }

    /**
     * Cost-centre filter, grouped exactly like the “Voce del bilancio consuntivo (Modello D)” select:
     * one optgroup per budget and section (“2026 · Personnel”) with the lines inside. The section is
     * read before the line and the collapsed control shows the line alone — the group carries the
     * context — so two sections repeating a wording stay distinguishable without a long label.
     *
     * @return list<array{label:string,options:list<array{value:string,label:string}>}>
     */
    public function budgetItemFilterGroups(): array
    {
        $groups = [];
        foreach ($this->db->fetchAll(
            "SELECT i.id, b.fiscal_year, i.label, s.title AS section_title
             FROM budget_items i
             INNER JOIN budgets b ON b.id = i.budget_id
             LEFT JOIN budget_sections s ON s.id = i.section_id
             ORDER BY b.fiscal_year DESC, s.sort_order ASC, i.sort_order ASC, i.id ASC"
        ) as $row) {
            $item = mb_substr(trim((string) $row['label']), 0, 90);
            if ($item === '') {
                continue;
            }
            $groupLabel = implode(' · ', array_filter(
                [(string) $row['fiscal_year'], trim((string) ($row['section_title'] ?? ''))],
                static fn (string $part): bool => $part !== ''
            ));
            if (!isset($groups[$groupLabel])) {
                $groups[$groupLabel] = ['label' => $groupLabel, 'options' => []];
            }
            $groups[$groupLabel]['options'][] = [
                'value' => (string) (int) $row['id'],
                'label' => $item,
            ];
        }

        return array_values($groups);
    }

    /**
     * Years with at least one movement (newest first) for the period filters; the current year is
     * always offered so a fresh installation can filter by it. Implausible years (a typo such as
     * 0206) are left out: they only pollute the menu.
     *
     * @return list<int>
     */
    public function movementYears(): array
    {
        $years = [];
        foreach ($this->db->fetchAll(
            'SELECT DISTINCT YEAR(movement_date) AS y FROM treasury_movements
             WHERE movement_date IS NOT NULL ORDER BY y DESC'
        ) as $row) {
            $year = (int) ($row['y'] ?? 0);
            if ($year > 0 && self::isPlausibleMovementYear($year) && !in_array($year, $years, true)) {
                $years[] = $year;
            }
        }
        $current = (int) date('Y');
        if (!in_array($current, $years, true)) {
            $years[] = $current;
            rsort($years);
        }

        return $years;
    }

    /**
     * Previous/next movement in chronological order (operation date, then id) for the arrows of the
     * edit page. `prev` is the older movement, `next` the newer one; null at the ends.
     *
     * @return array{prev:array{id:int,date:string}|null,next:array{id:int,date:string}|null}
     */
    /**
     * Previous/next movement **in the order of the ledger the operator is looking at**: the same
     * filters, the same sort (date, description, method, amount, grouped by type) and therefore the
     * same sequence the list shows, page after page.
     *
     * The row position comes from a window function over the ledger ordering, so every sort key is
     * supported without duplicating the comparison per column.
     *
     * @param array<string,mixed> $filters ledger filters (see ledgerConditions)
     * @return array{prev:array{id:int,date:string}|null,next:array{id:int,date:string}|null}
     */
    public function adjacentMovements(
        int $id,
        string $sort = 'date_desc',
        array $filters = [],
        string $query = '',
        bool $groupByCategory = false
    ): array {
        if ($id < 1 || !in_array($sort, self::SORTS, true)) {
            return ['prev' => null, 'next' => null];
        }
        $orderBy = $this->ledgerOrder($sort, $groupByCategory);
        [$where, $params] = $this->ledgerConditions($query, $filters);
        $position = $this->ledgerPosition($id, $orderBy, $where, $params);
        if ($position < 1) {
            // The record just left the filtered ledger (reclassified while filtering by cost
            // centre, date moved outside the period, …): fall back to the whole register with the
            // same order so the arrows never disappear on a movement that still exists.
            [$where, $params] = [[], []];
            $position = $this->ledgerPosition($id, $orderBy, $where, $params);
            if ($position < 1) {
                return ['prev' => null, 'next' => null];
            }
        }
        // Integer literals (already cast) keep the outer query free of bound parameters, so the
        // filter parameters of the inner query are never reused ambiguously.
        $ordered = 'SELECT m.id, m.movement_date, ROW_NUMBER() OVER (ORDER BY ' . $orderBy . ') AS rn'
            . ' FROM treasury_movements m' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        $rows = $this->db->fetchAll(
            'SELECT t.id, t.movement_date, t.rn FROM (' . $ordered . ') t'
            . ' WHERE t.rn IN (' . max(1, $position - 1) . ', ' . ($position + 1) . ')',
            $params
        );
        $prev = null;
        $next = null;
        foreach ($rows as $row) {
            $entry = [
                'id' => (int) ($row['id'] ?? 0),
                'date' => (string) ($row['movement_date'] ?? ''),
            ];
            if ((int) ($row['rn'] ?? 0) === $position - 1) {
                $prev = $entry;
            }
            if ((int) ($row['rn'] ?? 0) === $position + 1) {
                $next = $entry;
            }
        }

        return ['prev' => $prev, 'next' => $next];
    }

    /**
     * 1-based position of a movement in the ordered ledger, 0 when it matches no row (e.g. it just
     * left the current filter).
     *
     * @param list<string> $where
     * @param array<string,mixed> $params
     */
    private function ledgerPosition(int $id, string $orderBy, array $where, array $params): int
    {
        $ordered = 'SELECT m.id, ROW_NUMBER() OVER (ORDER BY ' . $orderBy . ') AS rn'
            . ' FROM treasury_movements m'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        $current = $this->db->fetch('SELECT t.rn FROM (' . $ordered . ') t WHERE t.id = ' . $id, $params);

        return (int) ($current['rn'] ?? 0);
    }

    /**
     * Cost centres with the full path “year · section · item”, for the menus that are not grouped by
     * section (CSV import/export): the bare item label is ambiguous when sections repeat a wording
     * (“Personale” in two macro-categories).
     *
     * @return list<array{value:string,label:string,fiscal_year:string,section:string,item:string}>
     */
    public function budgetItemOptionsWithSection(): array
    {
        $options = [];
        foreach ($this->db->fetchAll(
            "SELECT i.id, b.fiscal_year, i.label, s.title AS section_title
             FROM budget_items i
             INNER JOIN budgets b ON b.id = i.budget_id
             LEFT JOIN budget_sections s ON s.id = i.section_id
             ORDER BY b.fiscal_year DESC, s.sort_order ASC, i.sort_order ASC, i.id ASC"
        ) as $row) {
            $year = (string) ($row['fiscal_year'] ?? '');
            $section = trim((string) ($row['section_title'] ?? ''));
            $item = mb_substr(trim((string) ($row['label'] ?? '')), 0, 90);
            $parts = array_filter([$year, $section, $item], static fn (string $part): bool => $part !== '');
            $options[] = [
                'value' => (string) (int) $row['id'],
                'label' => implode(' · ', $parts),
                'fiscal_year' => $year,
                'section' => $section,
                'item' => $item,
            ];
        }

        return $options;
    }

    /**
     * “year · section · line” label of a budget line (summaries, movement card, similar changes):
     * the section always precedes the line, so two sections repeating a wording stay distinguishable.
     */
    public function budgetItemLabel(int $id): string
    {
        if ($id < 1) {
            return '';
        }
        $row = $this->db->fetch(
            'SELECT i.label, b.fiscal_year, s.title AS section_title
             FROM budget_items i
             INNER JOIN budgets b ON b.id = i.budget_id
             LEFT JOIN budget_sections s ON s.id = i.section_id
             WHERE i.id = :id',
            ['id' => $id]
        );
        if ($row === null) {
            return '';
        }
        $parts = array_filter(
            [
                (string) ($row['fiscal_year'] ?? ''),
                trim((string) ($row['section_title'] ?? '')),
                trim((string) ($row['label'] ?? '')),
            ],
            static fn (string $part): bool => $part !== ''
        );

        return implode(' · ', $parts);
    }

    /**
     * Movements "similar" to the one being compiled: same direction (entry/exit), same counterparty
     * and similar description. The scoring is deterministic (similarity between keywords), without
     * external services.
     *
     * @param array{direction?:string,description?:string,beneficiary?:string,member_id?:int} $criteria
     * @return array{count:int,total:int,ids:list<int>,matches:list<array<string,mixed>>,score_min:float}
     */
    public function similarMovements(array $criteria, int $limit = 3, int $excludeId = 0, array $applying = [], array $originals = []): array
    {
        $empty = ['count' => 0, 'total' => 0, 'skipped' => 0, 'ids' => [], 'matches' => [], 'score_min' => 0.45];
        // Fields we are applying: A row enters the proposal if THOSE fields are empty. The other fields (e.g.
        // type already set) do not count and are not touched.
        $fields = array_values(array_intersect($applying, ['category', 'runts_line', 'budget_item_id']));
        if ($fields === []) {
            $fields = ['category', 'runts_line', 'budget_item_id'];
        }
        $direction = strtolower(trim((string) ($criteria['direction'] ?? '')));
        if (!in_array($direction, ['income', 'expense'], true)) {
            return $empty;
        }
        $candidate = trim((string) ($criteria['description'] ?? ''));
        if ($candidate === '') {
            $candidate = trim((string) ($criteria['beneficiary'] ?? ''));
        }
        // The counterparty is part of the identity: movements with different members or different
        // free-text counterparties are never proposed for a batch update, even when the description
        // looks alike (e.g. dozens of “Bonifico” rows from different people).
        $criteriaMemberId = max(0, (int) ($criteria['member_id'] ?? 0));
        $criteriaBeneficiary = trim((string) ($criteria['beneficiary'] ?? ''));
        $criteriaMemberName = '';
        if ($criteriaMemberId > 0) {
            $criteriaMemberName = (string) ($this->memberNames([$criteriaMemberId])[$criteriaMemberId] ?? '');
        }
        $criteriaParty = $this->normalizeCounterpartyName(
            $criteriaMemberId > 0 ? $criteriaMemberName : $criteriaBeneficiary
        );
        $tokens = $this->similarityTokens($candidate);
        if ($tokens === []) {
            return $empty;
        }

        $rows = $this->db->fetchAll(
            "SELECT m.id, m.movement_date, m.description, m.beneficiary, m.amount, m.category, m.direction,
                    m.runts_line, m.budget_item_id, m.approved_at, m.account, m.payment_method, m.member_id
             FROM treasury_movements m
             WHERE m.direction = :direction AND m.id <> :exclude
               AND m.description IS NOT NULL AND m.description <> ''
             ORDER BY m.movement_date DESC, m.id DESC
             LIMIT 400",
            ['direction' => $direction, 'exclude' => max(0, $excludeId)]
        );

        $scored = [];
        $memberNames = $this->memberNames(array_map(
            static fn (array $row): int => (int) ($row['member_id'] ?? 0),
            $rows
        ));
        foreach ($rows as $row) {
            $rowMemberId = (int) ($row['member_id'] ?? 0);
            if ($criteriaMemberId > 0 && $rowMemberId > 0) {
                if ($criteriaMemberId !== $rowMemberId) {
                    continue;
                }
            } else {
                $rowParty = $this->normalizeCounterpartyName(
                    $rowMemberId > 0
                        ? (string) ($memberNames[$rowMemberId] ?? '')
                        : (string) ($row['beneficiary'] ?? '')
                );
                if ($criteriaParty !== $rowParty) {
                    continue;
                }
                // A member whose name is not available cannot be matched to free text, and vice
                // versa: the batch would otherwise touch an unknown counterparty.
                if (($criteriaMemberId > 0 || $rowMemberId > 0) && ($criteriaParty === '' || $rowParty === '')) {
                    continue;
                }
            }
            $score = $this->similarityScore($tokens, $this->similarityTokens((string) ($row['description'] ?? '')));
            if (!empty($row['beneficiary'])) {
                $score = max($score, $this->similarityScore($tokens, $this->similarityTokens((string) $row['beneficiary'])) - 0.05);
            }
            if ($score < 0.45) {
                continue;
            }
            $row['score'] = round($score, 3);
            $scored[] = $row;
        }
        usort($scored, static fn (array $a, array $b): int => $a['score'] === $b['score']
            ? strcmp((string) $b['movement_date'], (string) $a['movement_date'])
            : ($a['score'] < $b['score'] ? 1 : -1));

        // Only the rows in which the fields we are applying are still empty: a value already present is not
        // proposed and is never overwritten.
        $open = [];
        $skipped = 0;
        foreach ($scored as $row) {
            $blocked = false;
            $missing = [];
            foreach ($fields as $field) {
                $current = trim((string) ($row[$field] ?? ''));
                $empty = $field === 'budget_item_id' ? (int) $current === 0 : $current === '';
                // “Automatic” value = identical to that of the movement you are modifying: it still needs to
                // be really classified, so it enters the proposal.
                $original = trim((string) ($originals[$field] ?? ''));
                $sameAsOriginal = $original !== '' && $current === $original;
                if (!$sameAsOriginal && $field === 'runts_line' && $current !== '') {
                    // Item automatically derived from the category: still to be classified.
                    $automatic = strtoupper(RuntsReportService::lineForCategory(
                        (string) ($row['category'] ?? ''),
                        (string) ($row['direction'] ?? 'expense')
                    ));
                    $sameAsOriginal = $automatic !== '' && strtoupper($current) === $automatic;
                }
                if ($empty) {
                    $missing[] = $field;
                }
                if (!$empty && !$sameAsOriginal) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                $skipped++;
                continue;
            }
            $row['missing'] = $missing;
            $open[] = $row;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $open);
        // Details for the proposal: counterparty, wallet and amount — plus the overall range and
        // total, so the operator can judge the batch before touching anything.
        $memberNames = $this->memberNames(array_map(
            static fn (array $row): int => (int) ($row['member_id'] ?? 0),
            $open
        ));
        $accountLabels = [];
        foreach ($this->accountOptions() as $accountOption) {
            $accountLabels[(string) $accountOption['key']] = (string) $accountOption['label'];
        }
        $amountTotal = 0.0;
        $dateFrom = '';
        $dateTo = '';
        foreach ($open as $row) {
            $amountTotal += (float) ($row['amount'] ?? 0);
            $rowDate = trim((string) ($row['movement_date'] ?? ''));
            if ($rowDate === '') {
                continue;
            }
            if ($dateFrom === '' || $rowDate < $dateFrom) {
                $dateFrom = $rowDate;
            }
            if ($dateTo === '' || $rowDate > $dateTo) {
                $dateTo = $rowDate;
            }
        }
        $matches = [];
        foreach (array_slice($open, 0, max(1, $limit)) as $row) {
            $categoryKey = trim((string) ($row['category'] ?? ''));
            $accountKey = (string) ($row['account'] ?? '');
            $methodKey = (string) ($row['payment_method'] ?? '');
            $memberId = (int) ($row['member_id'] ?? 0);
            $beneficiary = trim((string) ($row['beneficiary'] ?? ''));
            if ($beneficiary !== '') {
                $beneficiary = mb_ucfirst($beneficiary);
            }
            $matches[] = [
                'id' => (int) $row['id'],
                'date' => (string) ($row['movement_date'] ?? ''),
                'description' => mb_ucfirst(mb_substr(trim((string) ($row['description'] ?? '')), 0, 160)),
                'amount' => (float) ($row['amount'] ?? 0),
                'amount_formatted' => $this->currency->format((float) ($row['amount'] ?? 0)),
                'date_formatted' => (string) (format_date((string) ($row['movement_date'] ?? '')) ?: (string) ($row['movement_date'] ?? '')),
                'category' => $categoryKey,
                'category_label' => $categoryKey !== '' ? $this->categoryLabel($categoryKey) : '',
                'runts_line' => (string) ($row['runts_line'] ?? ''),
                'budget_item_id' => (int) ($row['budget_item_id'] ?? 0),
                'approved' => trim((string) ($row['approved_at'] ?? '')) !== '',
                'score' => (float) ($row['score'] ?? 0),
                'account' => $accountKey,
                'account_label' => $accountKey !== ''
                    ? ($accountLabels[$accountKey] ?? $this->accountLabel($accountKey))
                    : '',
                'method' => $methodKey,
                'method_label' => $methodKey !== '' ? (string) __('treasury.method_' . $methodKey) : '',
                'member_id' => $memberId,
                'beneficiary' => $beneficiary,
                'counterparty' => $memberId > 0
                    ? (string) ($memberNames[$memberId] ?? '')
                    : $beneficiary,
                'missing' => (array) ($row['missing'] ?? []),
            ];
        }
        // Display order: the batch reads best by date (the selection itself stays by relevance).
        usort($matches, static fn (array $a, array $b): int => strcmp(
            (string) ($b['date'] ?? ''),
            (string) ($a['date'] ?? '')
        ));

        return [
            'count' => count($matches),
            'total' => count($open),
            'skipped' => $skipped,
            'ids' => $ids,
            'matches' => $matches,
            'fields' => $fields,
            'amount_total' => $amountTotal,
            'amount_total_formatted' => $this->currency->format($amountTotal),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'date_from_formatted' => $dateFrom !== '' ? (string) (format_date($dateFrom) ?: $dateFrom) : '',
            'date_to_formatted' => $dateTo !== '' ? (string) (format_date($dateTo) ?: $dateTo) : '',
            'score_min' => 0.45,
        ];
    }

    /**
     * Display names of the given members in one query (used by the “similar movements” list).
     *
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function memberNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            "SELECT v.member_id, d.`key`, v.value
             FROM member_field_values v
             INNER JOIN member_field_definitions d ON d.id = v.field_definition_id
             WHERE v.member_id IN (" . implode(',', $ids) . ")
               AND d.`key` IN ('first_name', 'last_name')"
        );
        $parts = [];
        foreach ($rows as $row) {
            $id = (int) $row['member_id'];
            $parts[$id] = $parts[$id] ?? ['first' => '', 'last' => ''];
            if ((string) $row['key'] === 'first_name') {
                $parts[$id]['first'] = trim((string) $row['value']);
            } else {
                $parts[$id]['last'] = trim((string) $row['value']);
            }
        }
        $names = [];
        foreach ($parts as $id => $nameParts) {
            $label = trim($nameParts['last'] . ' ' . $nameParts['first']);
            if ($label !== '') {
                $names[$id] = $label;
            }
        }

        return $names;
    }

    /**
     * Counterparties (the free-text party of a movement) with their income/expense shares.
     * The “Gestione controparti” page reads this list and links every row to the filtered ledger;
     * member-linked movements carry no beneficiary and therefore never show up here.
     * A counterparty whose name matches a member profile gets `is_member`/`member_id`, so the page
     * can show the “Socio” badge and filter members in or out.
     *
     * @param string $party '' = all, 'members' = only members, 'others' = exclude members
     * @return array{
     *     rows:list<array{name:string,is_member:bool,member_id:int,movements:int,income:float,expense:float,net:float,last_date:string,last_date_formatted:string}>,
     *     totals:array{suppliers:int,members:int,movements:int,income:float,expense:float}
     * }
     */
    public function supplierOverview(string $query = '', string $sort = 'volume', string $party = ''): array
    {
        $query = trim($query);
        if (!in_array($party, ['members', 'others'], true)) {
            $party = '';
        }
        $params = [];
        $where = "m.beneficiary IS NOT NULL AND TRIM(m.beneficiary) <> ''"
            . " AND m.direction IN ('income', 'expense')";
        if ($query !== '') {
            $where .= ' AND LOWER(m.beneficiary) LIKE :q';
            $params['q'] = '%' . mb_strtolower($query) . '%';
        }
        $rows = $this->db->fetchAll(
            "SELECT TRIM(m.beneficiary) AS name,
                    COUNT(*) AS movements,
                    COALESCE(SUM(CASE WHEN m.direction = 'income' THEN m.amount ELSE 0 END), 0) AS income,
                    COALESCE(SUM(CASE WHEN m.direction = 'expense' THEN m.amount ELSE 0 END), 0) AS expense,
                    MAX(m.movement_date) AS last_date
             FROM treasury_movements m
             WHERE " . $where . "
             GROUP BY TRIM(m.beneficiary)
             ORDER BY name ASC",
            $params
        );
        // Older data can store the same supplier with different spelling/case: merge by the
        // normalised name and keep the spelling used most often.
        $merged = [];
        foreach ($rows as $row) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            $movements = (int) ($row['movements'] ?? 0);
            $entry = $merged[$key] ?? [
                'spellings' => [],
                'movements' => 0,
                'income' => 0.0,
                'expense' => 0.0,
                'last_date' => '',
            ];
            $entry['spellings'][$name] = (int) ($entry['spellings'][$name] ?? 0) + $movements;
            $entry['movements'] += $movements;
            $entry['income'] += (float) ($row['income'] ?? 0);
            $entry['expense'] += (float) ($row['expense'] ?? 0);
            $lastDate = (string) ($row['last_date'] ?? '');
            if ($lastDate > (string) $entry['last_date']) {
                $entry['last_date'] = $lastDate;
            }
            $merged[$key] = $entry;
        }

        $counterpartyNames = [];
        foreach ($merged as $entry) {
            foreach (array_keys((array) $entry['spellings']) as $spelling) {
                $counterpartyNames[] = (string) $spelling;
            }
        }
        $memberIndex = $this->memberNameIndex($counterpartyNames);
        $list = [];
        foreach ($merged as $entry) {
            $spellings = (array) $entry['spellings'];
            arsort($spellings);
            $name = (string) array_key_first($spellings);
            $name = mb_ucfirst($name);
            $identity = $this->normalizePartyName($name);
            $isMember = $identity !== '' && array_key_exists($identity, $memberIndex);
            $income = (float) $entry['income'];
            $expense = (float) $entry['expense'];
            $lastDate = (string) $entry['last_date'];
            $list[] = [
                'name' => $name,
                'is_member' => $isMember,
                'member_id' => $isMember ? (int) $memberIndex[$identity] : 0,
                'movements' => (int) $entry['movements'],
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
                'last_date' => $lastDate,
                'last_date_formatted' => $lastDate !== '' ? (string) (format_date($lastDate) ?: $lastDate) : '',
            ];
        }
        // Members in / members out: filter before counting and sorting so the totals describe
        // exactly the rows the operator is looking at.
        if ($party !== '') {
            $list = array_values(array_filter(
                $list,
                static fn (array $row): bool => $party === 'members' ? $row['is_member'] : !$row['is_member']
            ));
        }
        $totals = ['suppliers' => 0, 'members' => 0, 'movements' => 0, 'income' => 0.0, 'expense' => 0.0];
        foreach ($list as $row) {
            $totals['suppliers']++;
            if ($row['is_member']) {
                $totals['members']++;
            }
            $totals['movements'] += (int) $row['movements'];
            $totals['income'] += (float) $row['income'];
            $totals['expense'] += (float) $row['expense'];
        }
        if ($sort === 'name') {
            usort($list, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        } else {
            usort($list, static fn (array $a, array $b): int => (($b['income'] + $b['expense']) <=> ($a['income'] + $a['expense']))
                ?: strnatcasecmp($a['name'], $b['name']));
        }

        return ['rows' => $list, 'totals' => $totals];
    }

    /**
     * Normalised member display names used to recognise a counterparty that is also a member.
     * Only members whose first or last name shares a token with a counterparty are loaded, so the
     * lookup stays small even on large archives. Both “first last” and “last first” forms are
     * indexed; when two members share the same name the id becomes 0 (ambiguous) so the UI never
     * links the wrong profile.
     *
     * @param list<string> $counterpartyNames
     * @return array<string,int> normalised name => member id (0 = ambiguous)
     */
    private function memberNameIndex(array $counterpartyNames): array
    {
        $tokens = [];
        foreach ($counterpartyNames as $name) {
            $normalised = $this->normalizePartyName($name);
            if ($normalised === '') {
                continue;
            }
            foreach (explode(' ', $normalised) as $token) {
                if ($token !== '' && mb_strlen($token) >= 2) {
                    $tokens[$token] = true;
                }
            }
        }
        if ($tokens === []) {
            return [];
        }
        $tokenList = array_keys($tokens);
        $placeholders = implode(',', array_fill(0, count($tokenList), '?'));
        $rows = $this->db->fetchAll(
            "SELECT v.member_id, d.`key`, v.value
             FROM member_field_values v
             INNER JOIN member_field_definitions d ON d.id = v.field_definition_id
             WHERE d.`key` IN ('first_name', 'last_name')
               AND LOWER(TRIM(v.value)) IN ({$placeholders})",
            $tokenList
        );
        $parts = [];
        foreach ($rows as $row) {
            $id = (int) ($row['member_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $parts[$id] = $parts[$id] ?? ['first' => '', 'last' => ''];
            if ((string) $row['key'] === 'first_name') {
                $parts[$id]['first'] = trim((string) $row['value']);
            } else {
                $parts[$id]['last'] = trim((string) $row['value']);
            }
        }
        $index = [];
        foreach ($parts as $id => $nameParts) {
            $first = $nameParts['first'];
            $last = $nameParts['last'];
            if ($first === '' || $last === '') {
                continue;
            }
            foreach ([$first . ' ' . $last, $last . ' ' . $first] as $label) {
                $key = $this->normalizePartyName($label);
                if ($key === '') {
                    continue;
                }
                if (!array_key_exists($key, $index)) {
                    $index[$key] = $id;
                } elseif ($index[$key] !== $id) {
                    $index[$key] = 0;
                }
            }
        }

        return $index;
    }

    /** Case/space-insensitive key used to compare counterparty names with member names. */
    private function normalizePartyName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        return mb_strtolower($name);
    }

    /**
     * Display names of the given members (public wrapper used by the financial statement and by
     * the loan/deposit registers, which group movements by counterparty).
     *
     * @param list<int> $ids
     * @return array<int,string>
     */
    public function memberDisplayNames(array $ids): array
    {
        return $this->memberNames($ids);
    }

    /**
     * Counterparties of one managed category (loans, security deposits) with what came in and what
     * went out for each of them. Member-linked movements take the member name; everything else uses
     * the free-text counterparty.
     *
     * @return array{
     *     rows:list<array{name:string,member_id:int,movements:int,income:float,expense:float,net:float,last_date:string,last_date_formatted:string}>,
     *     totals:array{count:int,movements:int,income:float,expense:float}
     * }
     */
    public function positionOverview(string $category, string $query = '', string $sort = 'volume'): array
    {
        $category = trim($category);
        $totals = ['count' => 0, 'movements' => 0, 'income' => 0.0, 'expense' => 0.0];
        if ($category === '') {
            return ['rows' => [], 'totals' => $totals];
        }
        $rows = $this->db->fetchAll(
            "SELECT m.beneficiary, m.member_id, m.direction, m.amount, m.movement_date
             FROM treasury_movements m
             WHERE m.category = :category AND m.superseded_at IS NULL
               AND (m.approved_at IS NOT NULL OR m.account = 'cash')
             ORDER BY m.movement_date DESC, m.id DESC",
            ['category' => $category]
        );
        $ids = [];
        foreach ($rows as $row) {
            $memberId = (int) ($row['member_id'] ?? 0);
            if ($memberId > 0) {
                $ids[] = $memberId;
            }
        }
        $names = $ids === [] ? [] : $this->memberNames($ids);
        $needle = mb_strtolower(trim($query));
        $groups = [];
        foreach ($rows as $row) {
            $memberId = (int) ($row['member_id'] ?? 0);
            $name = trim((string) ($names[$memberId] ?? ''));
            if ($name === '') {
                $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['beneficiary'] ?? '')));
            }
            $counterpartyMissing = $name === '';
            if ($counterpartyMissing) {
                $name = (string) __('treasury.counterparty_missing');
            }
            $name = mb_ucfirst($name);
            if ($needle !== '' && !str_contains(mb_strtolower($name), $needle)) {
                continue;
            }
            $key = mb_strtolower($name);
            $entry = $groups[$key] ?? [
                'name' => $name,
                'member_id' => 0,
                'counterparty_missing' => false,
                'movements' => 0,
                'income' => 0.0,
                'expense' => 0.0,
                'last_date' => '',
            ];
            if ($entry['member_id'] === 0 && $memberId > 0) {
                $entry['member_id'] = $memberId;
            }
            if ($counterpartyMissing) {
                $entry['counterparty_missing'] = true;
            }
            $entry['movements']++;
            if ((string) ($row['direction'] ?? '') === 'income') {
                $entry['income'] += (float) ($row['amount'] ?? 0);
            } else {
                $entry['expense'] += (float) ($row['amount'] ?? 0);
            }
            $lastDate = (string) ($row['movement_date'] ?? '');
            if ($lastDate > $entry['last_date']) {
                $entry['last_date'] = $lastDate;
            }
            $groups[$key] = $entry;
        }
        $list = [];
        foreach ($groups as $entry) {
            $income = round((float) $entry['income'], 2);
            $expense = round((float) $entry['expense'], 2);
            $lastDate = (string) $entry['last_date'];
            $list[] = [
                'name' => (string) $entry['name'],
                'member_id' => (int) $entry['member_id'],
                'counterparty_missing' => (bool) ($entry['counterparty_missing'] ?? false),
                'movements' => (int) $entry['movements'],
                'income' => $income,
                'expense' => $expense,
                'net' => round($income - $expense, 2),
                'last_date' => $lastDate,
                'last_date_formatted' => $lastDate !== '' ? (string) (format_date($lastDate) ?: $lastDate) : '',
            ];
            $totals['count']++;
            $totals['movements'] += (int) $entry['movements'];
            $totals['income'] += $income;
            $totals['expense'] += $expense;
        }
        if ($sort === 'name') {
            usort($list, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        } else {
            usort($list, static fn (array $a, array $b): int => (($b['income'] + $b['expense']) <=> ($a['income'] + $a['expense']))
                ?: strnatcasecmp($a['name'], $b['name']));
        }
        $totals['income'] = round((float) $totals['income'], 2);
        $totals['expense'] = round((float) $totals['expense'], 2);

        return ['rows' => $list, 'totals' => $totals];
    }

    /** Loans and member financing: received (income) versus repaid (expense). */
    public function loanOverview(string $query = '', string $sort = 'volume'): array
    {
        return $this->positionOverview('member_loan', $query, $sort);
    }

    /** Security deposits (SIAE, municipality, rent contract, …): paid versus refunded. */
    public function depositOverview(string $query = '', string $sort = 'volume'): array
    {
        return $this->positionOverview('security_deposit', $query, $sort);
    }

    /** Deletes one movement (used by the member card to unlink a payment from the cash book). */
    public function delete(int $id, string $ip = '', ?int $userId = null): bool
    {
        if ($id < 1) {
            return false;
        }
        $row = $this->db->fetch('SELECT * FROM treasury_movements WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return false;
        }
        $this->db->query('DELETE FROM treasury_movements WHERE id = :id', ['id' => $id]);
        $this->audit->log('treasury.deleted', 'treasury', (string) $id, [
            'direction' => $row['direction'] ?? null,
            'amount' => $row['amount'] ?? null,
            'movement_date' => $row['movement_date'] ?? null,
            'description' => $row['description'] ?? null,
            'payment_id' => $row['payment_id'] ?? null,
        ], null, $ip, $userId);

        return true;
    }

    /**
     * Typical classification of the movements that look like the given one.
     *
     * @param list<array<string,mixed>> $movements
     * @return array<int,array<string,mixed>>
     */
    public function classificationProfiles(array $movements, int $sampleLimit = self::PATTERN_SAMPLE_LIMIT): array
    {
        // Interactive hints only need a recent candidate pool: the form must stay responsive.
        return $this->classificationProfilesInternal($movements, $this->patternCandidates(1200), $sampleLimit);
    }

    /**
     * One movement's fingerprint: typical values, current values and examples.
     *
     * @param array<string,mixed> $movement
     * @return array<string,mixed>
     */
    public function classificationProfile(array $movement, int $excludeId = 0, int $sampleLimit = 20): array
    {
        $id = $excludeId > 0 ? $excludeId : (int) ($movement['id'] ?? 0);
        $movement['id'] = $id;
        $profiles = $this->classificationProfiles([$movement], $sampleLimit);

        return $profiles[$id] ?? [
            'fields' => [],
            'examples' => [],
            'total' => 0,
            'sample_count' => 0,
        ];
    }

    /**
     * Typical classification of “similar movements” for a batch of drafts: used by the CSV import
     * “Intuisci” switches. Drafts are de-duplicated by the caller; this method only matches them
     * against the same recent candidate pool the form hints use.
     *
     * @param list<array{id:int,direction:string,description:string,beneficiary:string}> $drafts
     * @return array<int,array{category:string,runts_line:string,budget_item_id:int}>
     */
    public function suggestClassification(array $drafts, int $sampleLimit = 8): array
    {
        if ($drafts === []) {
            return [];
        }
        $profiles = $this->classificationProfiles($drafts, $sampleLimit);
        $out = [];
        foreach ($profiles as $id => $profile) {
            $fields = is_array($profile['fields'] ?? null) ? $profile['fields'] : [];
            $out[(int) $id] = [
                'category' => trim((string) ($fields['category']['typical'] ?? '')),
                'runts_line' => strtoupper(trim((string) ($fields['runts_line']['typical'] ?? ''))),
                'budget_item_id' => max(0, (int) ($fields['budget_item_id']['typical'] ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * IDs of movements with at least one unacknowledged classification anomaly.
     *
     * @return list<int>
     */
    public function classificationAnomalyIds(): array
    {
        $movements = $this->db->fetchAll(
            'SELECT id, movement_date, description, beneficiary, amount, direction, payment_method, category,
                    runts_line, budget_item_id, updated_at
             FROM treasury_movements
             WHERE direction IN (\'income\', \'expense\') AND superseded_at IS NULL
             ORDER BY movement_date DESC, id DESC
             LIMIT ' . self::PATTERN_CANDIDATE_LIMIT
        );
        $profiles = $this->classificationProfilesInternal($movements, $movements, self::PATTERN_SAMPLE_LIMIT);
        $ids = [];
        foreach ($profiles as $id => $profile) {
            if ((int) $id < 1) {
                continue;
            }
            foreach (($profile['fields'] ?? []) as $field) {
                if (!empty($field['anomaly'])) {
                    $ids[] = (int) $id;
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Store “the current values are correct” for one field. The acknowledgement stops applying
     * automatically when the current value or the typical one changes.
     */
    public function acknowledgePattern(
        int $movementId,
        string $field,
        string $currentValue,
        string $typicalValue,
        ?int $userId = null,
        string $ip = ''
    ): bool {
        if ($movementId < 1 || !in_array($field, self::PATTERN_FIELDS, true)) {
            return false;
        }
        if ($this->db->fetch('SELECT id FROM treasury_movements WHERE id = :id', ['id' => $movementId]) === null) {
            return false;
        }
        if ($userId !== null) {
            // The session may outlive a deleted operator: never break the acknowledgement on the FK.
            $user = $this->db->fetch('SELECT id FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
            if ($user === null) {
                $userId = null;
            }
        }
        $currentValue = mb_substr(trim($currentValue), 0, 190);
        $typicalValue = mb_substr(trim($typicalValue), 0, 190);
        $existing = $this->db->fetch(
            'SELECT id FROM treasury_classification_acknowledgements WHERE movement_id = :movement_id AND field = :field',
            ['movement_id' => $movementId, 'field' => $field]
        );
        if ($existing !== null) {
            $this->db->update('treasury_classification_acknowledgements', [
                'current_value' => $currentValue,
                'typical_value' => $typicalValue,
                'user_id' => $userId,
            ], 'id = :id', ['id' => (int) $existing['id']]);
        } else {
            $this->db->insert('treasury_classification_acknowledgements', [
                'movement_id' => $movementId,
                'field' => $field,
                'current_value' => $currentValue,
                'typical_value' => $typicalValue,
                'user_id' => $userId,
            ]);
        }
        $this->audit->log('treasury.pattern_acknowledged', 'treasury', (string) $movementId, null, [
            'field' => $field,
        ], $ip);

        return true;
    }

    /**
     * Apply the same classification (type, Model D item, cost center) to multiple transactions.
     *
     * @param list<int> $ids
     * @param array{category?:string,runts_line?:string,budget_item_id?:string} $changes
     */
    public function applyClassification(array $ids, array $changes, string $ip, array $originals = []): int
    {
        $payload = [];
        $category = trim((string) ($changes['category'] ?? ''));
        if ($category !== '' && $category !== '__new__') {
            $payload['category'] = $category;
        }
        $runtsLine = strtoupper(trim((string) ($changes['runts_line'] ?? '')));
        if ($runtsLine !== '') {
            $payload['runts_line'] = $runtsLine;
        }
        $budgetItemId = (int) ($changes['budget_item_id'] ?? 0);
        if ($budgetItemId > 0 && $this->db->fetch('SELECT id FROM budget_items WHERE id = :id', ['id' => $budgetItemId]) !== null) {
            $payload['budget_item_id'] = $budgetItemId;
        }
        if ($payload === []) {
            return 0;
        }
        $updated = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id < 1) {
                continue;
            }
            $row = $this->db->fetch(
                'SELECT id, direction, category, runts_line, budget_item_id FROM treasury_movements WHERE id = :id',
                ['id' => $id]
            );
            if ($row === null) {
                continue;
            }
            $update = [];
            if (isset($payload['category'])) {
                $current = trim((string) ($row['category'] ?? ''));
                $original = trim((string) ($originals['category'] ?? ''));
                if ($current === '' || $current === $original) {
                    $update['category'] = $payload['category'];
                }
            }
            if (isset($payload['runts_line'])) {
                $current = strtoupper(trim((string) ($row['runts_line'] ?? '')));
                $original = strtoupper(trim((string) ($originals['runts_line'] ?? '')));
                // Value automatically derived from the category: it is still "to be classified".
                $automatic = strtoupper(RuntsReportService::lineForCategory(
                    (string) ($row['category'] ?? ''),
                    (string) ($row['direction'] ?? 'expense')
                ));
                if ($current === '' || $current === $original || ($automatic !== '' && $current === $automatic)) {
                    $update['runts_line'] = $payload['runts_line'];
                }
            }
            if (isset($payload['budget_item_id'])) {
                $current = (int) ($row['budget_item_id'] ?? 0);
                $original = (int) ($originals['budget_item_id'] ?? 0);
                if ($current === 0 || $current === $original) {
                    $update['budget_item_id'] = $payload['budget_item_id'];
                }
            }
            if ($update !== []) {
                $this->db->update('treasury_movements', $update, 'id = :id', ['id' => $id]);
                $updated++;
            }
        }
        if ($updated > 0) {
            $this->audit->log('treasury.classified_bulk', 'treasury', null, null, [
                'count' => $updated,
                'fields' => array_keys($payload),
            ], $ip);
        }

        return $updated;
    }

    /**
     * Counterparty name used for identity checks: lowercase, no accents, single spaces. Unlike
     * similarityTokens() it keeps every word, so “Al” or “Srls” still count.
     */
    private function normalizeCounterpartyName(string $name): string
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
        if ($name === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($trans) && $trans !== '') {
                $name = $trans;
            }
        }
        $name = (string) (preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name);

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Description keywords: lowercase, no accents, no blank words.
     *
     * @return list<string>
     */
    private function similarityTokens(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return [];
        }
        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($trans) && $trans !== '') {
                $text = $trans;
            }
        }
        $text = (string) (preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text);
        $stop = [
            'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'di', 'del', 'dello', 'della', 'dei', 'degli',
            'delle', 'da', 'dal', 'dalla', 'in', 'nel', 'nella', 'per', 'con', 'su', 'al', 'alla', 'ai', 'e', 'ed',
            'the', 'and', 'for', 'with', 'of', 'to', 'der', 'die', 'das', 'und', 'fur', 'von', 'mit', 'im', 'den',
            'dem', 'des', 'ein', 'eine', 'eur', 'euro', 'pagamento', 'payment', 'zahlung',
        ];
        $tokens = [];
        foreach (explode(' ', $text) as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 3 || in_array($token, $stop, true)) {
                continue;
            }
            $tokens[$token] = true;
        }

        return array_keys($tokens);
    }

    /**
     * Sørensen–Dice similarity between two sets of words, with containment bonus.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private function similarityScore(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $shared = count(array_intersect($a, $b));
        if ($shared === 0) {
            return 0.0;
        }
        $dice = (2 * $shared) / (count($a) + count($b));
        $joinedA = implode(' ', $a);
        $joinedB = implode(' ', $b);
        if (str_contains($joinedA, $joinedB) || str_contains($joinedB, $joinedA)) {
            $dice = min(1.0, $dice + 0.15);
        }

        return min(1.0, $dice);
    }

    /**
     * @param list<array<string,mixed>> $movements
     * @param list<array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>>
     */
    private function classificationProfilesInternal(array $movements, array $candidates, int $sampleLimit): array
    {
        $targets = [];
        foreach ($movements as $movement) {
            if (!is_array($movement)) {
                continue;
            }
            $direction = (string) ($movement['direction'] ?? '');
            if (!in_array($direction, ['income', 'expense'], true)) {
                continue;
            }
            $id = (int) ($movement['id'] ?? 0);
            $movement['id'] = $id;
            $targets[$id] = $movement;
        }
        if ($targets === []) {
            return [];
        }

        $candidateById = [];
        $tokenMap = [];
        $postings = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $id = (int) ($candidate['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $candidateById[$id] = $candidate;
            $tokens = $this->similarityTokens($this->patternText($candidate));
            $tokenMap[$id] = $tokens;
            foreach ($tokens as $token) {
                $postings[$token][] = $id;
            }
        }

        $acknowledgements = $this->patternAcknowledgements(array_keys($targets));
        $out = [];
        foreach ($targets as $id => $movement) {
            $out[$id] = $this->patternProfileForOne(
                $movement,
                (int) $id,
                $candidateById,
                $tokenMap,
                $postings,
                $acknowledgements,
                $sampleLimit
            );
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function patternCandidates(int $limit = self::PATTERN_CANDIDATE_LIMIT): array
    {
        $limit = max(1, min(self::PATTERN_CANDIDATE_LIMIT, $limit));

        return $this->db->fetchAll(
            'SELECT id, movement_date, description, beneficiary, amount, direction, payment_method, category,
                    runts_line, budget_item_id, updated_at
             FROM treasury_movements
             WHERE direction IN (\'income\', \'expense\') AND superseded_at IS NULL
               AND (
                    (description IS NOT NULL AND description <> \'\')
                    OR (beneficiary IS NOT NULL AND beneficiary <> \'\')
               )
             ORDER BY movement_date DESC, id DESC
             LIMIT ' . $limit
        );
    }

    /**
     * @param list<int> $movementIds
     * @return array<int,array<string,array{current_value:string,typical_value:string}>>
     */
    private function patternAcknowledgements(array $movementIds): array
    {
        $ids = [];
        foreach ($movementIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $name = 'ack_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $id;
        }
        $rows = $this->db->fetchAll(
            'SELECT movement_id, field, current_value, typical_value
             FROM treasury_classification_acknowledgements
             WHERE movement_id IN (' . implode(', ', $placeholders) . ')',
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) ($row['movement_id'] ?? 0)][(string) ($row['field'] ?? '')] = [
                'current_value' => (string) ($row['current_value'] ?? ''),
                'typical_value' => (string) ($row['typical_value'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function patternText(array $row): string
    {
        $description = trim((string) ($row['description'] ?? ''));

        return $description !== '' ? $description : trim((string) ($row['beneficiary'] ?? ''));
    }

    /**
     * Normalised value of a pattern field. The Model D line follows the report logic: when the
     * explicit line is missing or not valid for the direction, the category suggests it.
     *
     * @param array<string,mixed> $row
     */
    private function patternValue(array $row, string $field): string
    {
        $direction = (string) ($row['direction'] ?? 'expense');
        if (!in_array($direction, ['income', 'expense'], true)) {
            $direction = 'expense';
        }
        if ($field === 'payment_method') {
            $raw = trim((string) ($row['payment_method'] ?? ''));

            return $raw !== '' ? self::normalizePaymentMethod($raw) : '';
        }
        if ($field === 'category') {
            return trim((string) ($row['category'] ?? ''));
        }
        if ($field === 'runts_line') {
            return RuntsReportService::normalizeLine(
                (string) ($row['runts_line'] ?? ''),
                (string) ($row['category'] ?? ''),
                $direction
            );
        }
        if ($field === 'budget_item_id') {
            $id = (int) ($row['budget_item_id'] ?? 0);

            return $id > 0 ? (string) $id : '';
        }

        return '';
    }

    /**
     * @return array{fields:array<string,mixed>,examples:list<array<string,mixed>>,total:int,sample_count:int}
     */
    private function emptyPatternProfile(): array
    {
        return ['fields' => [], 'examples' => [], 'total' => 0, 'sample_count' => 0];
    }

    /**
     * @param array<string,mixed> $movement
     * @param array<int,array<string,mixed>> $candidateById
     * @param array<int,list<string>> $tokenMap
     * @param array<string,list<int>> $postings
     * @param array<int,array<string,array{current_value:string,typical_value:string}>> $acknowledgements
     * @return array<string,mixed>
     */
    private function patternProfileForOne(
        array $movement,
        int $excludeId,
        array $candidateById,
        array $tokenMap,
        array $postings,
        array $acknowledgements,
        int $sampleLimit
    ): array {
        $tokens = $this->similarityTokens($this->patternText($movement));
        if ($tokens === []) {
            return $this->emptyPatternProfile();
        }

        $candidateIds = [];
        foreach ($tokens as $token) {
            foreach (array_slice($postings[$token] ?? [], 0, 250) as $candidateId) {
                if ($candidateId === $excludeId) {
                    continue;
                }
                $candidateIds[$candidateId] = true;
            }
            if (count($candidateIds) >= 400) {
                break;
            }
        }

        $scored = [];
        foreach (array_keys($candidateIds) as $candidateId) {
            if (!isset($candidateById[$candidateId])) {
                continue;
            }
            $score = $this->similarityScore($tokens, $tokenMap[$candidateId] ?? []);
            if ($score >= self::PATTERN_MIN_SCORE) {
                $scored[$candidateId] = $score;
            }
        }
        if ($scored === []) {
            return $this->emptyPatternProfile();
        }
        arsort($scored);
        $total = count($scored);
        $top = array_slice($scored, 0, max(1, min(60, $sampleLimit)), true);

        $fields = [];
        foreach (self::PATTERN_FIELDS as $field) {
            $weights = [];
            $counts = [];
            foreach (array_keys($top) as $candidateId) {
                $value = $this->patternValue($candidateById[$candidateId], $field);
                if ($value === '') {
                    continue;
                }
                $weights[$value] = ($weights[$value] ?? 0.0) + max(0.1, (float) $scored[$candidateId]);
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
            if ($counts === []) {
                continue;
            }
            uksort($weights, static function (string $a, string $b) use ($weights, $counts): int {
                return ($weights[$b] <=> $weights[$a])
                    ?: ($counts[$b] <=> $counts[$a])
                    ?: strcmp($a, $b);
            });
            $typical = (string) array_key_first($weights);
            $samples = array_sum($counts);
            $current = $this->patternValue($movement, $field);
            $ack = $acknowledgements[$excludeId][$field] ?? null;
            $acknowledged = is_array($ack)
                && (string) ($ack['current_value'] ?? '') === $current
                && (string) ($ack['typical_value'] ?? '') === $typical;
            $fields[$field] = [
                'typical' => $typical,
                'typical_label' => $this->patternValueLabel($field, $typical),
                'current' => $current,
                'current_label' => $this->patternValueLabel($field, $current),
                'samples' => $samples,
                'is_match' => $current === $typical,
                'acknowledged' => $acknowledged,
                'anomaly' => $samples >= self::PATTERN_MIN_ANOMALY_SAMPLES
                    && $current !== $typical
                    && !$acknowledged,
            ];
        }

        $examples = [];
        foreach (array_slice($top, 0, 5, true) as $candidateId => $score) {
            $row = $candidateById[$candidateId];
            $values = [];
            foreach (self::PATTERN_FIELDS as $field) {
                $values[$field] = $this->patternValue($row, $field);
            }
            $examples[] = [
                'id' => (int) $candidateId,
                'date' => (string) ($row['movement_date'] ?? ''),
                'date_formatted' => (string) (format_date((string) ($row['movement_date'] ?? '')) ?: ($row['movement_date'] ?? '')),
                'description' => mb_ucfirst(mb_substr(trim((string) ($row['description'] ?? '')), 0, 160)),
                'amount' => (float) ($row['amount'] ?? 0),
                'amount_formatted' => $this->currency->format((float) ($row['amount'] ?? 0)),
                'values' => $values,
                'score' => round((float) $score, 3),
            ];
        }

        return [
            'fields' => $fields,
            'examples' => $examples,
            'total' => $total,
            'sample_count' => count($top),
        ];
    }

    private function patternValueLabel(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }
        if ($field === 'payment_method') {
            $key = 'treasury.method_' . $value;
            $label = (string) __($key);

            return $label !== $key ? $label : $value;
        }
        if ($field === 'category') {
            return $this->categoryLabel($value);
        }
        if ($field === 'runts_line') {
            return RuntsReportService::lineLabelWithSection($value);
        }
        if ($field === 'budget_item_id') {
            $label = $this->budgetItemLabel((int) $value);

            return $label !== '' ? $label : $value;
        }

        return $value;
    }

    /**
     * @return list<array{key:string,label:string,builtin:bool}>
     */
    public function categoryOptions(): array
    {
        $options = [];
        foreach (self::BUILTIN_CATEGORIES as $key) {
            $options[] = [
                'key' => $key,
                'label' => __('treasury.category_' . $key),
                'builtin' => true,
            ];
        }
        foreach ($this->customCategories() as $row) {
            $options[] = [
                'key' => (string) $row['slug'],
                'label' => (string) $row['label'],
                'builtin' => false,
            ];
        }
        return $options;
    }

    /** @return array<string,string> */
    public function categoryMap(): array
    {
        $map = [];
        foreach ($this->categoryOptions() as $opt) {
            $map[(string) $opt['key']] = (string) $opt['label'];
        }
        return $map;
    }

    public function categoryLabel(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            $key = 'other';
        }
        $map = $this->categoryMap();
        return $map[$key] ?? $this->humanizeSlug($key);
    }

    /**
     * Recognizes a category from a free label (CSV import, handwritten texts): accepts the slug,
     * English/Italian/German name of system categories and custom labels. Returns the canonical key
     * or null if it is truly new.
     */
    public function resolveCategoryFromLabel(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $raw) ?? '');
        $slug = trim($slug, '_');
        if ($slug !== '' && in_array($slug, self::BUILTIN_CATEGORIES, true)) {
            return $slug;
        }
        $normalize = static fn (string $value): string => strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
        $needle = $normalize($raw);
        foreach (self::BUILTIN_CATEGORIES as $key) {
            foreach (['it', 'en', 'de'] as $locale) {
                $label = $normalize((string) (function_exists('__') ? $this->translate('treasury.category_' . $key, $locale) : ''));
                if ($label !== '' && $label === $needle) {
                    return $key;
                }
            }
        }
        foreach ($this->categoryOptions() as $option) {
            if (empty($option['builtin']) && $normalize((string) $option['label']) === $needle) {
                return (string) $option['key'];
            }
        }
        return null;
    }

    /**
     * Translation into a specific language, without changing the language of the request.
     */
    private function translate(string $key, string $locale): string
    {
        static $cache = [];
        if (!isset($cache[$locale])) {
            $file = dirname(__DIR__, 2) . '/lang/' . $locale . '/messages.php';
            $cache[$locale] = is_file($file) ? (array) require $file : [];
        }
        $messages = $cache[$locale];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($messages) || !array_key_exists($segment, $messages)) {
                return '';
            }
            $messages = $messages[$segment];
        }
        return is_string($messages) ? $messages : '';
    }

    /** @return list<string> */
    public function beneficiaries(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT beneficiary
             FROM treasury_movements
             WHERE beneficiary IS NOT NULL AND beneficiary <> ''
             ORDER BY beneficiary
             LIMIT 400"
        );
        $seen = [];
        foreach ($rows as $row) {
            $value = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['beneficiary'] ?? '')));
            if ($value === '') {
                continue;
            }
            $value = mb_ucfirst($value);
            $key = mb_strtolower($value);
            if (!isset($seen[$key])) {
                $seen[$key] = $value;
            }
        }
        ksort($seen, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($seen);
    }

    public function defaultCategory(): string
    {
        $cfg = $this->components->config('treasury', ['default_category' => 'membership_fee']);
        $key = trim((string) ($cfg['default_category'] ?? 'membership_fee'));
        $valid = array_column($this->categoryOptions(), 'key');
        return in_array($key, $valid, true) ? $key : 'membership_fee';
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = $this->db->fetch(
            "SELECT m.*,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'first_name'
                     WHERE v.member_id = mem.id LIMIT 1) AS first_name,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'last_name'
                     WHERE v.member_id = mem.id LIMIT 1) AS last_name,
                    mem.member_number,
                    u.name AS creator_name,
                    (SELECT CONCAT(b.fiscal_year, ' · ', i.label)
                     FROM budget_items i
                     INNER JOIN budgets b ON b.id = i.budget_id
                     WHERE i.id = m.budget_item_id) AS budget_item_label
             FROM treasury_movements m
             LEFT JOIN members mem ON mem.id = m.member_id
             LEFT JOIN users u ON u.id = m.created_by
             WHERE m.id = :id",
            ['id' => $id]
        );
        if (is_array($row)) {
            $row['description'] = mb_ucfirst((string) ($row['description'] ?? ''));
            $beneficiary = trim((string) ($row['beneficiary'] ?? ''));
            if ($beneficiary !== '') {
                $row['beneficiary'] = mb_ucfirst($beneficiary);
            }
        }

        return $row;
    }

    /**
     * Current values of a movement exactly as the form shows them, for the “what changed” list of
     * the freshness banner: the client compares them with its own fields and lists the differences.
     *
     * `values` are the raw form values (comparison), `display` the human labels of the choice
     * fields and `labels` the translated field names (presentation).
     *
     * @return array{values:array<string,string>,display:array<string,string>,labels:array<string,string>}
     */
    public function formSnapshot(int $id): array
    {
        $row = $this->find($id);
        if ($row === null) {
            return ['values' => [], 'display' => [], 'labels' => []];
        }
        $amount = (float) ($row['amount'] ?? 0);
        $entered = trim((string) ($row['amount_entered'] ?? ''));
        $currency = trim((string) ($row['amount_currency'] ?? ''));
        $direction = (string) ($row['direction'] ?? '');
        $category = (string) ($row['category'] ?? '');
        $account = (string) ($row['account'] ?? '');
        $method = trim((string) ($row['payment_method'] ?? ''));
        $documentType = (string) ($row['document_type'] ?? '');
        $justificationCode = trim((string) ($row['justification_code'] ?? ''));
        $values = [
            'movement_date' => (string) ($row['movement_date'] ?? ''),
            'amount' => $entered !== '' ? number_format((float) $entered, 2, ',', '') : number_format($amount, 2, ',', ''),
            'amount_currency' => $currency,
            'direction' => $direction,
            'category' => $category,
            'account' => $account,
            'transfer_account' => (string) ($row['transfer_account'] ?? ''),
            'payment_method' => $method,
            'description' => (string) ($row['description'] ?? ''),
            'notes' => (string) ($row['notes'] ?? ''),
            'beneficiary' => (string) ($row['beneficiary'] ?? ''),
            'document_type' => $documentType,
            'justification_code' => $justificationCode,
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'invoice_due_date' => (string) ($row['invoice_due_date'] ?? ''),
            'invoice_title' => (string) ($row['invoice_title'] ?? ''),
            'invoice_payment' => !empty($row['invoice_payment']) ? '1' : '',
            'member_involved' => !empty($row['member_id']) ? '1' : '',
            'member_id' => (string) (int) ($row['member_id'] ?? 0),
            'runts_line' => (string) ($row['runts_line'] ?? ''),
            'budget_item_id' => (string) (int) ($row['budget_item_id'] ?? 0),
        ];
        $display = [
            'movement_date' => format_date($values['movement_date']) ?: $values['movement_date'],
            'amount' => $values['amount'] . ($currency !== '' && $currency !== $this->currency->code() ? ' ' . $currency : ''),
            'direction' => match ($direction) {
                'income' => (string) __('treasury.direction_income'),
                'expense' => (string) __('treasury.direction_expense'),
                'transfer' => (string) __('treasury.direction_transfer'),
                default => $direction,
            },
            'category' => $category !== '' ? $this->categoryLabel($category) : '',
            'account' => $account !== '' ? $this->accountLabel($account) : '',
            'transfer_account' => $values['transfer_account'] !== '' ? $this->accountLabel($values['transfer_account']) : '',
            'payment_method' => $method !== '' ? (string) __('treasury.method_' . self::normalizePaymentMethod($method)) : '',
            'document_type' => $documentType !== ''
                ? (string) __('treasury.document_type_' . self::normalizeDocumentType($documentType))
                : '',
            'justification_code' => $justificationCode,
            'invoice_date' => $values['invoice_date'] !== '' ? (format_date($values['invoice_date']) ?: $values['invoice_date']) : '',
            'invoice_due_date' => $values['invoice_due_date'] !== '' ? (format_date($values['invoice_due_date']) ?: $values['invoice_due_date']) : '',
            'budget_item_id' => (int) $values['budget_item_id'] > 0 ? $this->budgetItemLabel((int) $values['budget_item_id']) : '',
        ];

        return [
            'values' => $values,
            'display' => $display,
            'labels' => [
                'movement_date' => (string) __('treasury.operation_date'),
                'amount' => (string) __('treasury.amount'),
                'amount_currency' => (string) __('treasury.amount_currency'),
                'direction' => (string) __('treasury.direction_sign'),
                'category' => (string) __('treasury.category'),
                'account' => (string) __('treasury.account'),
                'transfer_account' => (string) __('treasury.transfer_to'),
                'payment_method' => (string) __('treasury.payment_method_label'),
                'description' => (string) __('treasury.description'),
                'notes' => (string) __('treasury.notes'),
                'beneficiary' => (string) __('treasury.counterparty'),
                'document_type' => (string) __('treasury.document_type'),
                'justification_code' => (string) __('budget.evidence_code'),
                'invoice_number' => (string) __('treasury.doc_number'),
                'invoice_date' => (string) __('treasury.doc_date'),
                'invoice_due_date' => (string) __('treasury.invoice_due_date'),
                'invoice_title' => (string) __('treasury.invoice_title'),
                'invoice_payment' => (string) __('treasury.invoice_payment'),
                'member_involved' => (string) __('treasury.member'),
                'member_id' => (string) __('treasury.member'),
                'runts_line' => (string) __('runts.line_label'),
                'budget_item_id' => (string) __('treasury.budget_item'),
            ],
        ];
    }

    public function attachmentFilePath(int $id): ?string
    {
        $row = $this->find($id);
        $relative = trim((string) ($row['attachment_path'] ?? ''));
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, '\\')) {
            return null;
        }
        if (!is_user_upload_relative_path($relative)) {
            return null;
        }
        $path = resolve_upload_absolute_path($relative);
        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * Code of a supporting document (“pezza giustificativa”): a letter plus the movement number,
     * zero-padded to six digits. The id makes it unique by construction and stable over time.
     */
    public static function justificationCode(int $id): string
    {
        return $id > 0 ? 'P' . str_pad((string) $id, 6, '0', STR_PAD_LEFT) : '';
    }

    /**
     * Keeps the justification code aligned with the document fields: assigned on the first save
     * that carries a document, cleared when the document is removed. Existing codes never change.
     */
    private function syncJustificationCode(int $id): void
    {
        if ($id < 1) {
            return;
        }
        $row = $this->db->fetch(
            'SELECT id, invoice_number, document_type, justification_code
               FROM treasury_movements WHERE id = :id',
            ['id' => $id]
        );
        if ($row === null) {
            return;
        }
        $hasDocument = trim((string) ($row['invoice_number'] ?? '')) !== ''
            || trim((string) ($row['document_type'] ?? '')) !== '';
        $stored = trim((string) ($row['justification_code'] ?? ''));
        $code = $hasDocument ? ($stored !== '' ? $stored : self::justificationCode($id)) : null;
        if ((string) ($row['justification_code'] ?? '') !== (string) ($code ?? '')) {
            $this->db->update('treasury_movements', ['justification_code' => $code], 'id = :id', ['id' => $id]);
        }
    }

    /** @param array<string,mixed> $input */
    public function create(array $input, ?array $file, string $ip, ?int $userId = null): array
    {
        $parsed = $this->parseInput($input, null);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'errors' => $parsed['errors'] ?? ['amount' => __('validation.required')]];
        }
        /** @var array<string,mixed> $data */
        $data = $parsed['data'];
        // “This movement has a document” off ⇒ no attachment either (the whole box is hidden).
        $detach = !empty($input['detach_invoice_pdf'])
            || (array_key_exists('has_document', $input) && empty($input['has_document']));
        $pickedDocumentId = (int) ($input['attachment_document_id'] ?? $input['repository_document_id'] ?? 0);
        $attachment = $this->syncInvoiceDocument($data, $file, $ip, $userId, null, $detach, $pickedDocumentId);
        if (empty($attachment['ok'])) {
            return ['ok' => false, 'errors' => ['invoice_pdf' => (string) ($attachment['error'] ?? __('documents.upload_fail'))]];
        }
        $data['attachment_path'] = $attachment['path'] ?? null;
        $data['document_id'] = $attachment['document_id'] ?? null;
        $data['created_by'] = $userId;
        // Manually entered movements are approved on save; automatic ones stay pending.
        $data['approved_at'] = date('Y-m-d H:i:s');
        $data['approved_by'] = $userId;
        $id = $this->db->insert('treasury_movements', $data);
        if ((int) ($data['document_id'] ?? 0) > 0) {
            $this->files->link((int) $data['document_id'], 'treasury', 'movement:' . $id, [
                'actor_type' => 'operator',
                'actor_id' => $userId,
            ]);
        }
        $this->syncJustificationCode($id);
        $this->audit->log('treasury.created', 'treasury', (string) $id, null, $input, $ip);
        return ['ok' => true, 'id' => $id];
    }

    /** @param array<string,mixed> $input */
    public function update(int $id, array $input, ?array $file, string $ip, ?int $userId = null, ?int $expectedVersion = null, bool $force = false): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return ['ok' => false, 'errors' => ['id' => __('errors.404')]];
        }
        $expected = (int) ($expectedVersion ?? 0);
        if (!$force && $expected > 0) {
            $current = $this->versions->current('treasury', $id);
            if ($current !== null && $current !== $expected) {
                return [
                    'ok' => false,
                    'conflict' => true,
                    'current_version' => $current,
                    'editor' => $this->versions->lastEditor('treasury', $id),
                    'errors' => [],
                ];
            }
        }
        $parsed = $this->parseInput($input, $existing);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'errors' => $parsed['errors'] ?? ['amount' => __('validation.required')]];
        }
        /** @var array<string,mixed> $data */
        $data = $parsed['data'];
        // “This movement has a document” off ⇒ the attachment is detached with the rest.
        $detach = !empty($input['detach_invoice_pdf'])
            || (array_key_exists('has_document', $input) && empty($input['has_document']));
        $pickedDocumentId = (int) ($input['attachment_document_id'] ?? $input['repository_document_id'] ?? 0);
        $attachment = $this->syncInvoiceDocument($data, $file, $ip, $userId, $existing, $detach, $pickedDocumentId);
        if (empty($attachment['ok'])) {
            return ['ok' => false, 'errors' => ['invoice_pdf' => (string) ($attachment['error'] ?? __('documents.upload_fail'))]];
        }
        if ($detach) {
            $data['attachment_path'] = null;
            $data['document_id'] = null;
        } else {
            $data['attachment_path'] = $attachment['path'] ?? ($existing['attachment_path'] ?? null);
            $data['document_id'] = $attachment['document_id'] ?? ($existing['document_id'] ?? null);
        }
        $stored = $this->db->updateVersioned('treasury_movements', $data, 'id', $id, $force ? 0 : $expected);
        if (!$stored) {
            return [
                'ok' => false,
                'conflict' => true,
                'current_version' => $this->versions->current('treasury', $id) ?? 0,
                'editor' => $this->versions->lastEditor('treasury', $id),
                'errors' => [],
            ];
        }
        if ($detach && (int) ($existing['document_id'] ?? 0) > 0) {
            $this->files->unlink((int) $existing['document_id'], 'treasury', 'movement:' . $id);
        } elseif ((int) ($data['document_id'] ?? 0) > 0) {
            $this->files->link((int) $data['document_id'], 'treasury', 'movement:' . $id, [
                'actor_type' => 'operator',
                'actor_id' => $userId,
            ]);
        }
        $this->syncJustificationCode($id);
        $this->audit->log('treasury.updated', 'treasury', (string) $id, [
            'direction' => $existing['direction'] ?? null,
            'amount' => $existing['amount'] ?? null,
            'category' => $existing['category'] ?? null,
            'movement_date' => $existing['movement_date'] ?? null,
        ], [
            'direction' => $data['direction'],
            'amount' => $data['amount'],
            'category' => $data['category'],
            'movement_date' => $data['movement_date'],
        ], $ip);
        // Reclassified to the cash desk: a cash movement never waits for the first-note approval.
        if (!self::requiresApproval((string) ($data['account'] ?? ''))
            && trim((string) ($existing['approved_at'] ?? '')) === '') {
            $this->db->update('treasury_movements', [
                'approved_at' => date('Y-m-d H:i:s'),
                'approved_by' => $userId,
            ], 'id = :id', ['id' => $id]);
            $this->audit->log('treasury.approved', 'treasury', (string) $id, null, [
                'approved_by' => $userId,
                'reason' => 'cash',
            ], $ip);
        }
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Approve a pending movement: only approved movements count towards balances, charts,
     * budgets and reports.
     *
     * @return array{ok:bool,message?:string}
     */
    public function approve(int $id, string $ip, ?int $userId = null): array
    {
        $row = $this->db->fetch('SELECT id, approved_at FROM treasury_movements WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return ['ok' => false, 'message' => __('errors.404')];
        }
        if (trim((string) ($row['approved_at'] ?? '')) !== '') {
            return ['ok' => true];
        }
        $this->db->update('treasury_movements', [
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => $userId,
        ], 'id = :id', ['id' => $id]);
        $this->audit->log('treasury.approved', 'treasury', (string) $id, null, ['approved_by' => $userId], $ip);
        return ['ok' => true];
    }


    /**
     * Removes approval (or restores it) on multiple movements in one fell swoop.
     *
     * @param list<int> $ids
     */
    public function setApprovalMany(array $ids, bool $approved, string $ip, ?int $userId = null): int
    {
        $count = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id < 1) {
                continue;
            }
            if (!$approved) {
                // The cash desk never needs the approval: un-approving it would only put it back in
                // the queue, so those rows are left alone.
                $row = $this->db->fetch('SELECT account FROM treasury_movements WHERE id = :id', ['id' => $id]);
                if ($row === null || !self::requiresApproval((string) ($row['account'] ?? ''))) {
                    continue;
                }
            }
            $this->db->update('treasury_movements', [
                'approved_at' => $approved ? date('Y-m-d H:i:s') : null,
                'approved_by' => $approved ? $userId : null,
            ], 'id = :id', ['id' => $id]);
            $count++;
        }
        if ($count > 0) {
            $this->audit->log($approved ? 'treasury.approved_bulk' : 'treasury.unapproved_bulk', 'treasury', null, null, ['count' => $count], $ip);
        }

        return $count;
    }


    /**
     * "Historical" transfer of the selected transactions: creates the copy on the destination
     * account and marks the original as transfer transfer (excluded from the counts, not editable).
     *
     * @param list<int> $ids
     * @return array{ok:bool,count?:int,error?:string}
     */
    public function transferSelected(array $ids, string $targetAccount, string $ip, ?int $userId = null): array
    {
        $target = self::normalizeAccount($targetAccount);
        if ($target === '' || !$this->accountExists($target) || !$this->accountIsActive($target)) {
            return ['ok' => false, 'error' => __('treasury.account_required')];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ['ok' => false, 'error' => __('treasury.bulk_none')];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM treasury_movements WHERE id IN (' . implode(',', $ids) . ')'
        );
        if (count($rows) !== count($ids)) {
            return ['ok' => false, 'error' => __('errors.404')];
        }
        $source = '';
        foreach ($rows as $row) {
            if (trim((string) ($row['superseded_at'] ?? '')) !== '') {
                return ['ok' => false, 'error' => __('treasury.transfer_already')];
            }
            $account = self::normalizeAccount((string) ($row['account'] ?? ''));
            if ($source === '') {
                $source = $account;
            } elseif ($account !== $source) {
                return ['ok' => false, 'error' => __('treasury.transfer_mixed')];
            }
        }
        if ($source === $target) {
            return ['ok' => false, 'error' => __('treasury.transfer_same')];
        }
        $count = 0;
        foreach ($rows as $row) {
            $copy = $row;
            unset($copy['id'], $copy['created_at'], $copy['updated_at'], $copy['row_version'], $copy['creator_name']);
            $copy['account'] = $target;
            $copy['transfer_origin_id'] = (int) $row['id'];
            $copy['superseded_at'] = null;
            $copy['superseded_account'] = null;
            $copy['superseded_note'] = null;
            $copy['superseded_by_id'] = null;
            // Landing on the cash desk: the copy is approved at once, like any cash movement.
            if (!self::requiresApproval($target)) {
                $copy['approved_at'] = date('Y-m-d H:i:s');
                $copy['approved_by'] = $userId;
            }
            $newId = $this->db->insert('treasury_movements', array_filter(
                $copy,
                static fn (string $key): bool => !str_starts_with($key, 'first_name') && !str_starts_with($key, 'last_name'),
                ARRAY_FILTER_USE_KEY
            ));
            $note = __('treasury.superseded_note', [
                'account' => (string) ($this->accountLabel($target) ?: $target),
                'date' => date('d/m/Y'),
            ]);
            $this->db->update('treasury_movements', [
                'superseded_at' => date('Y-m-d H:i:s'),
                'superseded_account' => $target,
                'superseded_note' => mb_substr($note, 0, 190),
                'superseded_by_id' => $userId,
            ], 'id = :id', ['id' => (int) $row['id']]);
            $count++;
            $this->audit->log('treasury.transferred', 'treasury', (string) $row['id'], null, [
                'to' => $target,
                'new_id' => $newId,
            ], $ip);
        }

        return ['ok' => true, 'count' => $count];
    }

    /** @param list<int> $ids @return int Number of approved movements */
    public function approveMany(array $ids, string $ip, ?int $userId = null): int
    {
        $count = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id < 1) {
                continue;
            }
            if (!empty($this->approve($id, $ip, $userId)['ok'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Bulk classification (“Modifica selezionati”): apply the same account / category / payment method /
     * Model D line / cost centre / description / notes to the selected movements. Every field is
     * optional — an empty value keeps the current one, `__none__` clears the Model D line and the cost
     * centre — and the overwrite is explicit: what is set replaces what was there (the description and
     * the notes only when their action is `replace`). Internal transfers only take account, description
     * and notes (their category, method and imputation are meaningless) and historical (superseded)
     * rows are never touched.
     *
     * @param list<int> $ids
     * @param array{account?:string,category?:string,payment_method?:string,runts_line?:string,budget_item_id?:string,description?:string,description_action?:string,notes?:string,notes_action?:string} $changes
     * @return array{count:int,skipped:int}
     */
    public function updateMany(array $ids, array $changes, string $ip): array
    {
        $payload = [];
        $account = self::normalizeAccount(trim((string) ($changes['account'] ?? '')));
        if ($account !== '' && $this->accountExists($account) && $this->accountIsActive($account)) {
            $payload['account'] = $account;
        }
        $category = trim((string) ($changes['category'] ?? ''));
        if ($category !== '' && isset($this->categoryMap()[$category])) {
            $payload['category'] = $category;
        }
        // Whitelisted payment methods only (legacy slugs such as `bank` are normalised); an empty
        // selection keeps the current value, because the column always holds one.
        $method = trim((string) ($changes['payment_method'] ?? ''));
        if ($method !== '' && in_array($method, self::METHODS, true)) {
            $payload['payment_method'] = self::normalizePaymentMethod($method);
        }
        // The cash wallet implies a cash payment (same rule as the movement form): moving the
        // selection to `cash` without choosing a method keeps the ledger consistent.
        if (($payload['account'] ?? '') === 'cash' && !isset($payload['payment_method'])) {
            $payload['payment_method'] = 'cash';
        }
        $runts = trim((string) ($changes['runts_line'] ?? ''));
        if ($runts === '__none__') {
            $payload['runts_line'] = null;
        } elseif ($runts !== '') {
            $line = strtoupper($runts);
            if (RuntsReportService::isKnownLine($line, 'expense')
                || RuntsReportService::isKnownLine($line, 'income')) {
                $payload['runts_line'] = $line;
            }
        }
        $budget = trim((string) ($changes['budget_item_id'] ?? ''));
        if ($budget === '__none__') {
            $payload['budget_item_id'] = null;
        } elseif ($budget !== '') {
            $budgetId = (int) $budget;
            if ($budgetId > 0 && $this->db->fetch('SELECT id FROM budget_items WHERE id = :id', ['id' => $budgetId]) !== null) {
                $payload['budget_item_id'] = $budgetId;
            }
        }
        // Counterparty: a linked member and a free-text supplier exclude each other (same rule as
        // the movement form). An empty selection keeps the current value, `__none__` unlinks the
        // member and `beneficiary_action = clear` empties the “fornitore”.
        $memberRaw = trim((string) ($changes['member_id'] ?? ''));
        $beneficiaryRaw = (string) ($changes['beneficiary'] ?? '');
        if ($memberRaw === '__none__') {
            $payload['member_id'] = null;
        } elseif ($memberRaw !== '') {
            $memberId = (int) $memberRaw;
            if ($memberId > 0 && $this->db->fetch('SELECT id FROM members WHERE id = :id', ['id' => $memberId]) !== null) {
                $payload['member_id'] = $memberId;
                $payload['beneficiary'] = null;
            }
        } elseif (trim($beneficiaryRaw) !== '') {
            $payload['beneficiary'] = $this->normalizeBeneficiary($beneficiaryRaw);
            $payload['member_id'] = null;
        } elseif ((string) ($changes['beneficiary_action'] ?? '') === 'clear') {
            $payload['beneficiary'] = null;
        }
        if ((string) ($changes['description_action'] ?? '') === 'replace') {
            // Same normalisation as the single form (first letter upper case, rest lower case); the
            // “causale” column is NOT NULL, so clearing it stores an empty string.
            $description = self::normalizeDescription((string) ($changes['description'] ?? ''));
            $payload['description'] = $description !== '' ? mb_substr($description, 0, 500) : '';
        }
        if ((string) ($changes['notes_action'] ?? '') === 'replace') {
            $notes = trim((string) ($changes['notes'] ?? ''));
            $payload['notes'] = $notes !== '' ? mb_substr($notes, 0, self::NOTES_MAX_LENGTH) : null;
        }
        if ($payload === []) {
            return ['count' => 0, 'skipped' => 0];
        }

        $count = 0;
        $skipped = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id < 1) {
                continue;
            }
            $row = $this->db->fetch('SELECT * FROM treasury_movements WHERE id = :id', ['id' => $id]);
            if ($row === null || trim((string) ($row['superseded_at'] ?? '')) !== '') {
                $skipped++;
                continue;
            }
            $isTransfer = (string) ($row['direction'] ?? '') === 'transfer';
            $update = [];
            foreach ($payload as $field => $value) {
                if ($isTransfer && in_array($field, ['category', 'payment_method', 'runts_line', 'budget_item_id'], true)) {
                    continue;
                }
                $update[$field] = $value;
            }
            if ($update === []) {
                $skipped++;
                continue;
            }
            $sets = [];
            $params = ['id' => $id];
            $before = [];
            $after = [];
            foreach ($update as $field => $value) {
                $sets[] = '`' . $field . '` = :' . $field;
                $params[$field] = $value;
                $before[$field] = $row[$field] ?? null;
                $after[$field] = $value;
            }
            $this->db->query(
                'UPDATE treasury_movements SET ' . implode(', ', $sets) . ', row_version = row_version + 1 WHERE id = :id',
                $params
            );
            // One audit row per movement: the “modified by” trail and the freshness banner keep working.
            $this->audit->log('treasury.bulk_updated', 'treasury', (string) $id, $before, $after, $ip);
            $count++;
        }

        return ['count' => $count, 'skipped' => $skipped];
    }

    /** @param list<int> $ids @return int Number of deleted movements */
    public function deleteMany(array $ids, string $ip): int
    {
        $count = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id < 1) {
                continue;
            }
            $row = $this->db->fetch('SELECT * FROM treasury_movements WHERE id = :id', ['id' => $id]);
            if ($row === null) {
                continue;
            }
            $this->db->query('DELETE FROM treasury_movements WHERE id = :id', ['id' => $id]);
            $this->audit->log('treasury.deleted', 'treasury', (string) $id, [
                'direction' => $row['direction'] ?? null,
                'amount' => $row['amount'] ?? null,
                'movement_date' => $row['movement_date'] ?? null,
                'description' => $row['description'] ?? null,
            ], null, $ip);
            $count++;
        }
        return $count;
    }

    /**
     * Cash movements never wait for the first-note approval: the cash desk is booked as it happens.
     * Bank, card and the other wallets keep the approval step (automatic entries land as pending).
     */
    public static function requiresApproval(string $account): bool
    {
        return self::normalizeAccount($account) !== 'cash';
    }

    public function autoRegisterFromPayment(
        int $paymentId,
        int $memberId,
        float $amount,
        string $method,
        string $date,
        ?int $userId = null
    ): void {
        if (!$this->components->isEnabled('treasury')) {
            return;
        }
        $cfg = $this->components->config('treasury', ['auto_from_payments' => true]);
        if (empty($cfg['auto_from_payments'])) {
            return;
        }
        $this->registerMembershipIncome($paymentId, $memberId, [
            'amount' => $amount,
            'movement_date' => $date,
            'payment_method' => $method,
            'description' => __('treasury.auto_membership_payment'),
            'category' => 'membership_fee',
        ], $userId);
    }

    /**
     * @param array{amount:float|int|string,movement_date?:string,payment_method?:string,description?:string,category?:string} $input
     */
    public function registerMembershipIncome(int $paymentId, int $memberId, array $input, ?int $userId = null): void
    {
        if (!$this->components->isEnabled('treasury')) {
            return;
        }
        $exists = $this->db->fetch(
            'SELECT id FROM treasury_movements WHERE payment_id = :p AND superseded_at IS NULL LIMIT 1',
            ['p' => $paymentId]
        );
        if ($exists) {
            return;
        }
        $amount = (float) ($input['amount'] ?? 0);
        if ($amount <= 0) {
            return;
        }
        $date = trim((string) ($input['movement_date'] ?? date('Y-m-d')));
        if (!$this->validator->validate(['movement_date' => $date], ['movement_date' => 'date'])) {
            $date = date('Y-m-d');
        }
        $method = self::normalizePaymentMethod(trim((string) ($input['payment_method'] ?? 'cash')));
        $category = trim((string) ($input['category'] ?? 'membership_fee'));
        if ($category === '' || $category === '__new__') {
            $category = 'membership_fee';
        }
        $description = self::normalizeDescription((string) ($input['description'] ?? ''));
        if ($description === '') {
            $description = (string) __('treasury.auto_membership_payment');
        }
        $account = self::accountForPaymentMethod($method);
        // The cash desk does not need the first-note approval: a cash payment is booked at once.
        $approved = !self::requiresApproval($account);
        $this->db->insert('treasury_movements', [
            'movement_date' => $date,
            'direction' => 'income',
            'category' => $category,
            'amount' => $amount,
            'description' => mb_substr($description, 0, 500),
            'payment_method' => $method,
            'account' => $account,
            'member_id' => $memberId,
            'payment_id' => $paymentId,
            'approved_at' => $approved ? date('Y-m-d H:i:s') : null,
            'approved_by' => $approved ? $userId : null,
        ]);
        // The payment now has a cash-book movement: drop a previous “already collected” flag.
        $this->db->update('payments', [
            'cashbook_ack_at' => null,
            'cashbook_ack_by' => null,
        ], 'id = :id', ['id' => $paymentId]);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array{ok:bool,errors?:array<string,string>,data?:array<string,mixed>}
     */
    private function parseInput(array $input, ?array $existing): array
    {
        $direction = (string) ($input['direction'] ?? '');
        if (!in_array($direction, self::DIRECTIONS, true)) {
            return ['ok' => false, 'errors' => ['direction' => __('validation.in')]];
        }
        $isTransfer = $direction === 'transfer';
        // “This movement has a document”: the form always sends the flag (hidden 0 + checkbox 1).
        // When it is off every document field is cleared; when the key is absent (CSV import,
        // internal calls) the provided values are kept as they are.
        $documentsOff = array_key_exists('has_document', $input) && empty($input['has_document']);
        $amountRaw = (float) str_replace(',', '.', (string) ($input['amount'] ?? '0'));
        if ($amountRaw <= 0) {
            return ['ok' => false, 'errors' => ['amount' => __('validation.required')]];
        }
        $baseCurrency = $this->currency->code();
        $inputCurrency = strtoupper(trim((string) ($input['amount_currency'] ?? $baseCurrency)));
        if (!in_array($inputCurrency, $this->currency->supportedCodes(), true)) {
            $inputCurrency = $baseCurrency;
        }
        $amount = $amountRaw;
        $amountEntered = null;
        $amountCurrency = null;
        if ($inputCurrency !== $baseCurrency) {
            $converted = $this->currency->convertToBase($amountRaw, $inputCurrency);
            if ($converted === null) {
                return ['ok' => false, 'errors' => ['amount_currency' => __('treasury.currency_rate_unavailable')]];
            }
            $amount = $converted;
            $amountEntered = $amountRaw;
            $amountCurrency = $inputCurrency;
        }
        $date = trim((string) ($input['movement_date'] ?? date('Y-m-d')));
        if (!$this->validator->validate(['movement_date' => $date], ['movement_date' => 'date'])) {
            return ['ok' => false, 'errors' => ['movement_date' => __('validation.date')]];
        }
        if (!$this->isPlausibleMovementYear((int) substr($date, 0, 4))) {
            return ['ok' => false, 'errors' => ['movement_date' => __('treasury.date_out_of_range', [
                'date' => $date,
                'min' => (string) self::MIN_MOVEMENT_YEAR,
                'max' => (string) self::maxMovementYear(),
            ])]];
        }
        if ($isTransfer) {
            // A internal transfer has no category of its own: it always moves liquidity internally.
            $categoryResult = ['ok' => true, 'key' => 'internal_transfer'];
        } else {
            $categoryResult = $this->resolveCategory($input, $existing);
        }
        if (empty($categoryResult['ok'])) {
            return ['ok' => false, 'errors' => ['category' => (string) ($categoryResult['error'] ?? __('validation.required'))]];
        }
        // RUNTS item (Model D) of the movement: explicit choice, otherwise suggested by the category.
        // The CSV importer passes “__none__” because it cannot know the association's Model D line.
        $runtsInput = trim((string) ($input['runts_line'] ?? ''));
        $existingRunts = $existing !== null ? trim((string) ($existing['runts_line'] ?? '')) : null;
        $runtsLine = $runtsInput === '__none__'
            || ($runtsInput === '' && $existingRunts === '')
            ? null
            : RuntsReportService::normalizeLine(
                $runtsInput,
                (string) ($categoryResult['key'] ?? ''),
                $direction
            );
        // "Account" (wallet: where the money sits) and "method" (how it moved) are
        // two different things and therefore two different columns.
        $account = self::normalizeAccount((string) ($input['account'] ?? ''));
        $existingAccount = self::normalizeAccount((string) ($existing['account'] ?? ''));
        if ($account === '' || !$this->accountExists($account)) {
            return ['ok' => false, 'errors' => ['account' => __('treasury.account_required')]];
        }
        if (!$this->accountIsActive($account) && $account !== $existingAccount) {
            return ['ok' => false, 'errors' => ['account' => __('treasury.account_inactive')]];
        }
        $method = '';
        $transferAccount = null;
        if ($isTransfer) {
            // A internal transfer moves liquidity between two own wallets: no payment method.
            $transferRaw = trim((string) ($input['transfer_account'] ?? ''));
            $transferAccount = self::normalizeAccount($transferRaw);
            $existingTransfer = self::normalizeAccount((string) ($existing['transfer_account'] ?? ''));
            if ($transferRaw === '' || $transferAccount === '' || !$this->accountExists($transferAccount)) {
                return ['ok' => false, 'errors' => ['transfer_account' => __('treasury.transfer_to_required')]];
            }
            if (!$this->accountIsActive($transferAccount) && $transferAccount !== $existingTransfer) {
                return ['ok' => false, 'errors' => ['transfer_account' => __('treasury.account_inactive')]];
            }
            if ($transferAccount === $account) {
                return ['ok' => false, 'errors' => ['transfer_account' => __('treasury.transfer_same_account')]];
            }
        } else {
            $methodRaw = trim((string) ($input['payment_method'] ?? ''));
            if ($account === 'cash') {
                $method = 'cash'; // cash wallet ⇒ cash payment, the method is implied
            } elseif ($methodRaw !== '') {
                $method = self::normalizePaymentMethod($methodRaw);
            }
        }
        $memberInvolved = !empty($input['member_involved']);
        $memberId = $memberInvolved ? trim((string) ($input['member_id'] ?? '')) : '';
        $memberId = $memberId !== '' ? (int) $memberId : null;
        $isInvoice = $direction === 'expense' && !$documentsOff && !empty($input['invoice_payment']);
        $invoiceNumber = $documentsOff ? '' : mb_substr(trim((string) ($input['invoice_number'] ?? '')), 0, 120);
        $invoiceTitle = $isInvoice ? mb_substr(trim((string) ($input['invoice_title'] ?? '')), 0, 190) : null;
        $invoiceDate = null;
        $invoiceDueDate = null;
        $invoiceDateRaw = trim((string) ($input['invoice_date'] ?? ''));
        if (!$documentsOff && $invoiceDateRaw !== '') {
            if (!$this->validator->validate(['invoice_date' => $invoiceDateRaw], ['invoice_date' => 'date'])) {
                return ['ok' => false, 'errors' => ['invoice_date' => __('validation.date')]];
            }
            // A document dated 0026/0206 is a typo: ask for the correction instead of storing it.
            if (!PlausibleDate::isPlausible($invoiceDateRaw)) {
                return ['ok' => false, 'errors' => ['invoice_date' => __('treasury.date_out_of_range', [
                    'date' => $invoiceDateRaw,
                    'min' => (string) PlausibleDate::MIN_YEAR,
                    'max' => (string) PlausibleDate::maxYear(),
                ])]];
            }
            $invoiceDate = $invoiceDateRaw;
        }
        if ($isInvoice) {
            $invoiceDueRaw = trim((string) ($input['invoice_due_date'] ?? ''));
            if ($invoiceDueRaw !== '') {
                if (!$this->validator->validate(['invoice_due_date' => $invoiceDueRaw], ['invoice_due_date' => 'date'])) {
                    return ['ok' => false, 'errors' => ['invoice_due_date' => __('validation.date')]];
                }
                if (!PlausibleDate::isPlausible($invoiceDueRaw)) {
                    return ['ok' => false, 'errors' => ['invoice_due_date' => __('treasury.date_out_of_range', [
                        'date' => $invoiceDueRaw,
                        'min' => (string) PlausibleDate::MIN_YEAR,
                        'max' => (string) PlausibleDate::maxYear(),
                    ])]];
                }
                $invoiceDueDate = $invoiceDueRaw;
            }
        }
        $documentType = '';
        if (!$isTransfer && !$documentsOff) {
            $documentTypeRaw = trim((string) ($input['document_type'] ?? ''));
            if ($documentTypeRaw !== '') {
                $documentType = self::normalizeDocumentType($documentTypeRaw);
                if ($documentType === '') {
                    return ['ok' => false, 'errors' => ['document_type' => __('validation.in')]];
                }
            }
        }
        // A selected member is the counterparty: any free text typed before the member was chosen
        // is ignored and cleared, never stored next to member_id.
        $beneficiary = $memberId !== null
            ? ''
            : $this->normalizeBeneficiary((string) ($input['beneficiary'] ?? ''));

        $description = self::normalizeDescription((string) ($input['description'] ?? ''));
        $description = $description !== '' ? mb_substr($description, 0, 500) : '';
        // Internal memo: kept separate from the short "causale" so a working annotation never
        // endangers the description that ends up in the reports.
        $notes = trim((string) ($input['notes'] ?? ''));
        $notes = $notes !== '' ? mb_substr($notes, 0, self::NOTES_MAX_LENGTH) : '';

        $budgetItemId = 0;
        $budgetItemRaw = trim((string) ($input['budget_item_id'] ?? ''));
        if (!$isTransfer && $budgetItemRaw !== '' && $budgetItemRaw !== '0') {
            $budgetItemId = (int) $budgetItemRaw;
            $exists = $this->db->fetch('SELECT id FROM budget_items WHERE id = :id', ['id' => $budgetItemId]);
            if ($exists === null) {
                return ['ok' => false, 'errors' => ['budget_item_id' => __('validation.in')]];
            }
        }

        return [
            'ok' => true,
            'data' => [
                'movement_date' => $date,
                'direction' => $direction,
                'category' => (string) $categoryResult['key'],
                'runts_line' => $runtsLine,
                'budget_item_id' => $budgetItemId > 0 ? $budgetItemId : null,
                'amount' => $amount,
                'amount_entered' => $amountEntered,
                'amount_currency' => $amountCurrency,
                'description' => $description,
                'notes' => $notes !== '' ? $notes : null,
                'payment_method' => $method,
                'account' => $account,
                'transfer_account' => $transferAccount,
                'member_id' => $memberId,
                'invoice_payment' => $isInvoice ? 1 : 0,
                'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
                'invoice_date' => $invoiceDate,
                'invoice_due_date' => $invoiceDueDate,
                'invoice_title' => $invoiceTitle !== '' && $invoiceTitle !== null ? $invoiceTitle : null,
                'document_type' => $documentType !== '' ? $documentType : null,
                'beneficiary' => $beneficiary !== '' ? $beneficiary : null,
            ],
        ];
    }

    /**
     * Counterparty name as it gets stored: trimmed, single-spaced, capped with the first letter
     * upper case — and reusing the spelling the register already knows so “Municipality of
     * Bolzano” and “municipality of bolzano” never become two different suppliers.
     */
    private function normalizeBeneficiary(string $raw): string
    {
        $beneficiary = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if ($beneficiary === '') {
            return '';
        }
        $beneficiary = mb_substr($beneficiary, 0, 190);
        $existingName = $this->db->fetch(
            "SELECT beneficiary FROM treasury_movements
             WHERE beneficiary IS NOT NULL AND beneficiary <> ''
               AND LOWER(TRIM(beneficiary)) = LOWER(:name)
             ORDER BY id ASC LIMIT 1",
            ['name' => $beneficiary]
        );
        if (is_array($existingName) && trim((string) ($existingName['beneficiary'] ?? '')) !== '') {
            return mb_ucfirst((string) $existingName['beneficiary']);
        }

        return mb_ucfirst($beneficiary);
    }

    /**
     * Store an optional PDF once, then mirror its metadata into Documents when enabled.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $file
     * @param array<string,mixed>|null $existing
     * @return array{ok:bool,path?:?string,document_id?:?int,error?:string}
     */
    private function syncInvoiceDocument(
        array $data,
        ?array $file,
        string $ip,
        ?int $userId,
        ?array $existing = null,
        bool $detach = false,
        int $pickedDocumentId = 0
    ): array {
        if ($detach) {
            return ['ok' => true, 'path' => null, 'document_id' => null];
        }
        $hasUpload = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $documentId = isset($existing['document_id']) ? (int) $existing['document_id'] : 0;
        $actor = ['actor_type' => 'operator', 'actor_id' => $userId];
        if (!$hasUpload && $pickedDocumentId < 1 && $documentId < 1) {
            return [
                'ok' => true,
                'path' => $existing['attachment_path'] ?? null,
                'document_id' => null,
            ];
        }

        if ($hasUpload) {
            $uploadMime = strtolower((string) ($file['type'] ?? ''));
            $uploadExt = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
            $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($uploadMime, $allowedMimes, true) && !in_array($uploadExt, $allowedExts, true)) {
                return ['ok' => false, 'error' => __('treasury.invoice_pdf_invalid')];
            }
            $stored = $this->files->storeUpload($file, [
                'section' => 'treasury',
                'ref' => '',
                'category' => 'invoice',
                'title' => (string) ($data['invoice_title'] ?? ''),
                'date' => (string) ($data['invoice_date'] ?? ($data['movement_date'] ?? '')),
            ] + $actor, $ip);
            if (empty($stored['ok'])) {
                return ['ok' => false, 'error' => (string) ($stored['error'] ?? __('documents.upload_fail'))];
            }
            $path = (string) $stored['path'];
            $mime = (string) ($stored['mime'] ?? 'application/pdf');
            $documentId = (int) ($stored['document_id'] ?? 0);
        } elseif ($pickedDocumentId > 0) {
            $picked = $this->files->pick($pickedDocumentId, 'treasury');
            if ($picked === null) {
                return ['ok' => false, 'error' => __('documents.upload_missing')];
            }
            $path = (string) ($picked['file_path'] ?? '');
            $mime = (string) ($picked['file_mime'] ?? 'application/pdf');
            $documentId = $pickedDocumentId;
        } else {
            $path = trim((string) ($existing['attachment_path'] ?? ''));
            $mime = 'application/pdf';
        }

        if ($documentId > 0) {
            $description = trim((string) ($data['description'] ?? ''));
            $beneficiary = trim((string) ($data['beneficiary'] ?? ''));
            $invoice = trim((string) ($data['invoice_number'] ?? ''));
            $invoiceTitle = trim((string) ($data['invoice_title'] ?? ''));
            $titleParts = [__('treasury.invoice_document_title')];
            if ($invoice !== '') {
                $titleParts[] = $invoice;
            }
            if ($invoiceTitle !== '') {
                $titleParts[] = $invoiceTitle;
            } elseif ($beneficiary !== '') {
                $titleParts[] = $beneficiary;
            }
            $this->files->annotate($documentId, [
                'title' => implode(' - ', $titleParts),
                'category' => 'invoice',
                'document_date' => trim((string) ($data['invoice_date'] ?? '')) !== ''
                    ? (string) $data['invoice_date']
                    : (string) ($data['movement_date'] ?? ''),
                'summary' => $description !== '' ? $description : $invoiceTitle,
                'source_section' => 'treasury',
            ], $ip);
        }

        return ['ok' => true, 'path' => $path, 'document_id' => $documentId > 0 ? $documentId : null];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function buildSearchWhere(string $query, array &$params): string
    {
        $like = '%' . $this->escapeLike($query) . '%';
        $params['like_desc'] = $like;
        $params['like_category'] = $like;
        $params['like_method'] = $like;
        $params['like_account'] = $like;
        $params['like_invoice_title'] = $like;
        $params['like_beneficiary'] = $like;
        $params['like_invoice_number'] = $like;
        $params['like_notes'] = $like;
        $parts = [
            'm.description LIKE :like_desc',
            'm.notes LIKE :like_notes',
            'm.category LIKE :like_category',
            'm.payment_method LIKE :like_method',
            'm.account LIKE :like_account',
            'm.invoice_title LIKE :like_invoice_title',
            'm.beneficiary LIKE :like_beneficiary',
            'm.invoice_number LIKE :like_invoice_number',
        ];

        $needle = mb_strtolower($query);
        $catKeys = [];
        foreach ($this->categoryOptions() as $opt) {
            $label = mb_strtolower((string) $opt['label']);
            $key = mb_strtolower((string) $opt['key']);
            if (str_contains($label, $needle) || str_contains($key, $needle) || str_contains($needle, $key)) {
                $catKeys[] = (string) $opt['key'];
            }
        }
        if ($catKeys !== []) {
            $in = [];
            foreach (array_values(array_unique($catKeys)) as $i => $key) {
                $param = 'cat_' . $i;
                $params[$param] = $key;
                $in[] = ':' . $param;
            }
            $parts[] = 'm.category IN (' . implode(', ', $in) . ')';
        }

        foreach (['income' => 'treasury.direction_income', 'expense' => 'treasury.direction_expense', 'transfer' => 'treasury.direction_transfer'] as $dir => $labelKey) {
            $label = mb_strtolower(__($labelKey));
            if (str_contains($label, $needle) || str_contains($needle, $dir)) {
                $param = 'dir_' . $dir;
                $params[$param] = $dir;
                $parts[] = 'm.direction = :' . $param;
            }
        }

        // Wallets are searchable by label too ("bank", "cash office", ...).
        $accKeys = [];
        foreach ($this->accountOptions() as $opt) {
            $label = mb_strtolower((string) $opt['label']);
            $key = mb_strtolower((string) $opt['key']);
            if (str_contains($label, $needle) || str_contains($key, $needle) || str_contains($needle, $key)) {
                $accKeys[] = (string) $opt['key'];
            }
        }
        if ($accKeys !== []) {
            $in = [];
            $inTransfer = [];
            foreach (array_values(array_unique($accKeys)) as $i => $key) {
                $param = 'acc_' . $i;
                $params[$param] = $key;
                $in[] = ':' . $param;
                $paramTransfer = 'acc_to_' . $i;
                $params[$paramTransfer] = $key;
                $inTransfer[] = ':' . $paramTransfer;
            }
            $parts[] = '(m.account IN (' . implode(', ', $in) . ') OR m.transfer_account IN (' . implode(', ', $inTransfer) . '))';
        }

        // Payment methods are searchable by label too ("bank transfer", "POS", …).
        $methodKeys = [];
        foreach ($this->paymentMethodGroups() as $group) {
            foreach ($group['keys'] as $methodKey) {
                $label = mb_strtolower((string) __('treasury.method_' . $methodKey));
                if (str_contains($label, $needle)) {
                    $methodKeys[] = (string) $methodKey;
                }
            }
        }
        if ($methodKeys !== []) {
            $in = [];
            foreach (array_values(array_unique($methodKeys)) as $i => $key) {
                $param = 'pm_' . $i;
                $params[$param] = $key;
                $in[] = ':' . $param;
            }
            $parts[] = 'm.payment_method IN (' . implode(', ', $in) . ')';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    private function toBooleanFulltext(string $query): string
    {
        $tokens = preg_split('/\s+/u', $query) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            $token = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $token) ?? '';
            if ($token === '' || mb_strlen($token) < 2) {
                continue;
            }
            $out[] = '+' . $token . '*';
        }
        return implode(' ', $out);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Resolve a category picked in a form (builtin, custom or a brand-new label) and register the
     * new one when needed. Shared by treasury movements and budget lines.
     *
     * @return array{ok:bool,key?:string,error?:string}
     */
    public function resolveCategoryChoice(string $selected, string $newLabel = '', string $existingKey = ''): array
    {
        return $this->resolveCategory(
            ['category' => $selected, 'new_category' => $newLabel],
            ['category' => $existingKey]
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array{ok:bool,key?:string,error?:string}
     */
    private function resolveCategory(array $input, ?array $existing = null): array
    {
        $selected = trim((string) ($input['category'] ?? ''));
        if ($selected === '__new__') {
            $label = trim((string) ($input['new_category'] ?? ''));
            if ($label === '') {
                return ['ok' => false, 'error' => __('treasury.category_new_required')];
            }
            if (mb_strlen($label) > 80) {
                return ['ok' => false, 'error' => __('validation.max_string', ['max' => '80'])];
            }
            $slug = $this->slugifyCategory($label);
            if ($slug === '' || in_array($slug, self::BUILTIN_CATEGORIES, true) || $slug === '__new__') {
                return ['ok' => false, 'error' => __('treasury.category_new_invalid')];
            }
            $this->ensureCustomCategory($slug, $label);
            return ['ok' => true, 'key' => $slug];
        }

        $valid = array_column($this->categoryOptions(), 'key');
        $current = trim((string) ($existing['category'] ?? ''));
        if ($current !== '' && !in_array($current, $valid, true)) {
            $valid[] = $current;
        }
        if ($selected === '' || !in_array($selected, $valid, true)) {
            if ($selected === '' && (string) ($input['allow_empty_category'] ?? '') === '1') {
                // CSV import with “no default category”: the row stays unclassified.
                return ['ok' => true, 'key' => ''];
            }
            return ['ok' => false, 'error' => __('validation.in')];
        }
        return ['ok' => true, 'key' => $selected];
    }

    /** @return list<array{slug:string,label:string}> */
    private function customCategories(): array
    {
        $cfg = $this->components->config('treasury', []);
        $raw = $cfg['custom_categories'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = $this->slugifyCategory((string) ($row['slug'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            if ($slug === '' || $label === '' || isset($seen[$slug]) || in_array($slug, self::BUILTIN_CATEGORIES, true)) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = ['slug' => $slug, 'label' => mb_substr($label, 0, 80)];
        }
        // A category already used by a movement or a budget item cannot disappear: we keep it in the list
        // even if it has been removed from the configuration (label from the slug).
        $known = [];
        foreach ($out as $row) {
            $known[$row['slug']] = true;
        }
        foreach ($this->categoriesInUse() as $slug) {
            if (isset($known[$slug]) || in_array($slug, self::BUILTIN_CATEGORIES, true)) {
                continue;
            }
            $out[] = ['slug' => $slug, 'label' => $this->humanizeSlug($slug)];
            $known[$slug] = true;
        }
        return $out;
    }

    /**
     * Categories referenced by movements or budget items (so you never lose them).
     *
     * @return list<string>
     */
    private function categoriesInUse(): array
    {
        $out = [];
        try {
            $rows = $this->db->fetchAll(
                "SELECT category FROM treasury_movements WHERE category IS NOT NULL AND category <> ''
                 UNION
                 SELECT category FROM budget_items WHERE category IS NOT NULL AND category <> ''"
            );
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $row) {
            $slug = $this->slugifyCategory((string) ($row['category'] ?? ''));
            if ($slug !== '') {
                $out[$slug] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Rename a custom type (the taxonomy is shared: movements and quotes follow the new name).
     * System categories are not renamed.
     *
     * @return array{ok:bool,error?:string,label?:string}
     */
    public function renameCategory(string $slug, string $label): array
    {
        $slug = $this->slugifyCategory($slug);
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        if ($slug === '' || $label === '') {
            return ['ok' => false, 'error' => __('validation.required')];
        }
        if (in_array($slug, self::BUILTIN_CATEGORIES, true)) {
            return ['ok' => false, 'error' => __('treasury.category_system_locked')];
        }
        $label = mb_substr(sentence_case($label), 0, 80);
        $cfg = $this->components->config('treasury', ['auto_from_payments' => true, 'default_category' => 'membership_fee']);
        $list = $this->customCategories();
        $found = false;
        foreach ($list as $index => $row) {
            if ($row['slug'] === $slug) {
                $list[$index]['label'] = $label;
                $found = true;
                break;
            }
        }
        if (!$found) {
            return ['ok' => false, 'error' => __('errors.404')];
        }
        $cfg['custom_categories'] = $list;
        $this->components->saveConfig('treasury', $cfg);

        return ['ok' => true, 'label' => $label];
    }

    private function ensureCustomCategory(string $slug, string $label): void
    {
        $cfg = $this->components->config('treasury', ['auto_from_payments' => true, 'default_category' => 'membership_fee']);
        $list = $this->customCategories();
        foreach ($list as $row) {
            if ($row['slug'] === $slug) {
                return;
            }
        }
        $list[] = ['slug' => $slug, 'label' => mb_substr(sentence_case($label), 0, 80)];
        $cfg['custom_categories'] = $list;
        $this->components->saveConfig('treasury', $cfg);
    }

    private function slugifyCategory(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($trans) && $trans !== '') {
                $value = $trans;
            }
        }
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');
        return mb_substr($value, 0, 60);
    }

    private function humanizeSlug(string $slug): string
    {
        $slug = str_replace('_', ' ', trim($slug));
        return $slug !== '' ? mb_convert_case($slug, MB_CASE_TITLE, 'UTF-8') : __('treasury.category_other');
    }
}
