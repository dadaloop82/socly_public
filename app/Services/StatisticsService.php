<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Aggregate, read-only statistics for the dedicated statistics page.
 *
 * Every figure comes from data the app really stores (members, custom fields, cards, payments):
 * the external reference product also counts events/attendance/feedback, which this product has
 * no table for, so those blocks are simply not offered.
 */
final class StatisticsService
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Headline counters (KPI row).
     *
     * @return array{
     *   members_total:int,members_new_30:int,members_active:int,members_settled:int,
     *   members_overdue:int,balance_due_total:float,positive_growth:bool,
     *   cards_total:int,cards_active:int,cards_expired:int,cards_inactive:int,
     *   cards_expiring_soon:int,cards_issued_12m:int,
     *   collected_total:float,collected_year:float,collected_month:float,average_payment:float,
     *   average_age:float|null
     * }
     */
    public function overview(): array
    {
        $members = $this->db->fetch(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN balance_due <= 0 THEN 1 ELSE 0 END) AS settled,
                    SUM(CASE WHEN balance_due > 0 THEN 1 ELSE 0 END) AS overdue,
                    COALESCE(SUM(balance_due), 0) AS balance_due
             FROM members"
        ) ?? [];
        $new30 = $this->db->fetch(
            'SELECT COUNT(*) AS c FROM members
             WHERE COALESCE(admitted_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
        );

        $cards = $this->db->fetch(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN is_active = 1 AND (expires_on IS NULL OR expires_on >= CURDATE()) THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN expires_on IS NOT NULL AND expires_on < CURDATE() THEN 1 ELSE 0 END) AS expired,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive,
                    SUM(CASE WHEN is_active = 1 AND expires_on IS NOT NULL
                              AND expires_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                             THEN 1 ELSE 0 END) AS expiring_soon,
                    SUM(CASE WHEN issued_on IS NOT NULL
                              AND issued_on >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                             THEN 1 ELSE 0 END) AS issued_12m
             FROM membership_cards'
        ) ?? [];

        $payments = $this->db->fetch(
            'SELECT COALESCE(SUM(amount), 0) AS total,
                    COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) THEN amount ELSE 0 END), 0) AS this_year,
                    COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE())
                                       AND MONTH(created_at) = MONTH(CURDATE()) THEN amount ELSE 0 END), 0) AS this_month,
                    COALESCE(AVG(amount), 0) AS average
             FROM payments'
        ) ?? [];

        return [
            'members_total' => (int) ($members['total'] ?? 0),
            'members_new_30' => (int) ($new30['c'] ?? 0),
            'members_active' => (int) ($members['active'] ?? 0),
            'members_settled' => (int) ($members['settled'] ?? 0),
            'members_overdue' => (int) ($members['overdue'] ?? 0),
            'balance_due_total' => round((float) ($members['balance_due'] ?? 0), 2),
            'positive_growth' => (int) ($new30['c'] ?? 0) > 0,
            'cards_total' => (int) ($cards['total'] ?? 0),
            'cards_active' => (int) ($cards['active'] ?? 0),
            'cards_expired' => (int) ($cards['expired'] ?? 0),
            'cards_inactive' => (int) ($cards['inactive'] ?? 0),
            'cards_expiring_soon' => (int) ($cards['expiring_soon'] ?? 0),
            'cards_issued_12m' => (int) ($cards['issued_12m'] ?? 0),
            'collected_total' => round((float) ($payments['total'] ?? 0), 2),
            'collected_year' => round((float) ($payments['this_year'] ?? 0), 2),
            'collected_month' => round((float) ($payments['this_month'] ?? 0), 2),
            'average_payment' => round((float) ($payments['average'] ?? 0), 2),
            'average_age' => $this->averageAge(),
        ];
    }

    /** Mean age from the birth_date custom field (null when unknown). */
    private function averageAge(): ?float
    {
        $rows = $this->db->fetchAll(
            "SELECT v.value FROM member_field_values v
             INNER JOIN member_field_definitions d ON d.id = v.field_definition_id
             WHERE d.`key` = 'birth_date' AND v.value IS NOT NULL AND v.value <> ''"
        );
        $today = new \DateTimeImmutable('today');
        $sum = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            $raw = trim((string) ($row['value'] ?? ''));
            $birth = \DateTimeImmutable::createFromFormat('Y-m-d', substr($raw, 0, 10));
            if (!$birth instanceof \DateTimeImmutable || $birth > $today) {
                continue;
            }
            $sum += $birth->diff($today)->y;
            $count++;
        }
        return $count > 0 ? round($sum / $count, 1) : null;
    }

    /**
     * Demographic breakdowns (gender, age bands, e-mail provider, city, language).
     *
     * @return array<string,mixed>
     */
    public function demographics(): array
    {
        $genderLabels = [];
        $genderValues = [];
        $genderTotal = 0;
        $genderKeys = ['M' => 'gender_m', 'F' => 'gender_f', 'X' => 'gender_x'];
        foreach ($this->fieldCounts('gender') as $row) {
            $raw = strtoupper(trim((string) ($row['val'] ?? '')));
            $genderLabels[] = isset($genderKeys[$raw])
                ? (string) __('members.' . $genderKeys[$raw])
                : (string) __('statistics.unknown');
            $genderValues[] = (int) $row['c'];
            $genderTotal += (int) $row['c'];
        }

        $bands = [
            '0-17' => 0,
            '18-25' => 0,
            '26-35' => 0,
            '36-50' => 0,
            '51-65' => 0,
            '66+' => 0,
            'unknown' => 0,
        ];
        $today = new \DateTimeImmutable('today');
        foreach ($this->fieldRawValues('birth_date') as $raw) {
            $birth = \DateTimeImmutable::createFromFormat('Y-m-d', substr(trim($raw), 0, 10));
            if (!$birth instanceof \DateTimeImmutable || $birth > $today) {
                $bands['unknown']++;
                continue;
            }
            $age = $birth->diff($today)->y;
            if ($age < 18) {
                $bands['0-17']++;
            } elseif ($age <= 25) {
                $bands['18-25']++;
            } elseif ($age <= 35) {
                $bands['26-35']++;
            } elseif ($age <= 50) {
                $bands['36-50']++;
            } elseif ($age <= 65) {
                $bands['51-65']++;
            } else {
                $bands['66+']++;
            }
        }
        $ageLabels = [];
        $ageValues = [];
        foreach ($bands as $band => $count) {
            if ($band === 'unknown' && $count === 0) {
                continue;
            }
            $ageLabels[] = $band === 'unknown' ? (string) __('statistics.unknown') : $band;
            $ageValues[] = $count;
        }

        $providers = [];
        foreach ($this->fieldRawValues('email') as $email) {
            $email = trim($email);
            $domain = '';
            $at = strrpos($email, '@');
            if ($at !== false) {
                $domain = strtolower(substr($email, $at + 1));
            }
            $key = $this->emailProviderKey($domain);
            $providers[$key] = ($providers[$key] ?? 0) + 1;
        }
        arsort($providers);

        $cityLabels = [];
        $cityValues = [];
        $cityRest = 0;
        foreach ($this->fieldCounts('city') as $index => $row) {
            if ($index < 8) {
                $cityLabels[] = (string) ($row['val'] ?? '');
                $cityValues[] = (int) $row['c'];
            } else {
                $cityRest += (int) $row['c'];
            }
        }
        if ($cityRest > 0) {
            $cityLabels[] = (string) __('statistics.other');
            $cityValues[] = $cityRest;
        }

        $languageLabels = [];
        $languageValues = [];
        foreach ($this->fieldCounts('preferred_language') as $row) {
            $code = strtolower(trim((string) ($row['val'] ?? '')));
            $languageLabels[] = $code === '' ? (string) __('statistics.unknown') : strtoupper($code);
            $languageValues[] = (int) $row['c'];
        }

        return [
            'gender' => ['labels' => $genderLabels, 'values' => $genderValues, 'total' => $genderTotal],
            'age_bands' => ['labels' => $ageLabels, 'values' => $ageValues],
            'email_providers' => ['labels' => array_keys($providers), 'values' => array_values($providers)],
            'cities' => ['labels' => $cityLabels, 'values' => $cityValues],
            'languages' => ['labels' => $languageLabels, 'values' => $languageValues],
        ];
    }

    /** Group an e-mail domain into a readable provider label. */
    private function emailProviderKey(string $domain): string
    {
        if ($domain === '') {
            return (string) __('statistics.no_email');
        }
        $known = [
            'gmail.com' => 'Gmail',
            'googlemail.com' => 'Gmail',
            'libero.it' => 'Libero',
            'virgilio.it' => 'Virgilio',
            'outlook.com' => 'Outlook',
            'outlook.it' => 'Outlook',
            'hotmail.com' => 'Outlook',
            'hotmail.it' => 'Outlook',
            'live.com' => 'Outlook',
            'live.it' => 'Outlook',
            'icloud.com' => 'iCloud',
            'me.com' => 'iCloud',
            'yahoo.com' => 'Yahoo',
            'yahoo.it' => 'Yahoo',
            'tiscali.it' => 'Tiscali',
            'alice.it' => 'Alice',
            'poste.it' => 'Poste',
            'aruba.it' => 'Aruba',
            'pec.it' => 'PEC',
        ];
        if (isset($known[$domain])) {
            return $known[$domain];
        }
        if (str_starts_with($domain, 'pec.') || str_ends_with($domain, '.pec.it')) {
            return 'PEC';
        }
        return (string) __('statistics.other_provider');
    }

    /** @return list<array{val:string,c:int}> */
    private function fieldCounts(string $key): array
    {
        return $this->db->fetchAll(
            "SELECT v.value AS val, COUNT(*) AS c
             FROM member_field_values v
             INNER JOIN member_field_definitions d ON d.id = v.field_definition_id
             WHERE d.`key` = :key AND v.value IS NOT NULL AND v.value <> ''
             GROUP BY v.value
             ORDER BY c DESC, v.value ASC",
            ['key' => $key]
        );
    }

    /**
     * Registrations over time, seasonality, record month, top months and weekday preference.
     * The "admission date" is used when present, else the creation timestamp.
     *
     * @return array<string,mixed>
     */
    public function registrations(): array
    {
        $allMonths = [];
        foreach ($this->db->fetchAll(
            "SELECT DATE_FORMAT(COALESCE(admitted_at, created_at), '%Y-%m') AS ym, COUNT(*) AS c
             FROM members
             GROUP BY ym
             ORDER BY ym ASC"
        ) as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if ($ym !== '') {
                $allMonths[$ym] = (int) $row['c'];
            }
        }

        $months = $this->monthKeys(24);
        $monthLabels = [];
        $monthValues = [];
        foreach ($months as $ym) {
            $monthLabels[] = $this->monthLabel($ym);
            $monthValues[] = $allMonths[$ym] ?? 0;
        }

        $last12 = array_slice($months, -12);
        $last12Sum = 0;
        foreach ($last12 as $ym) {
            $last12Sum += $allMonths[$ym] ?? 0;
        }

        $total = array_sum($allMonths);
        $sorted = $allMonths;
        arsort($sorted);
        $peakKey = (string) (array_key_first($sorted) ?? '');
        $peakCount = $peakKey !== '' ? (int) $sorted[$peakKey] : 0;

        $topLabels = [];
        $topValues = [];
        $topPercents = [];
        foreach (array_slice($sorted, 0, 10, true) as $ym => $count) {
            $topLabels[] = $this->monthLabel((string) $ym);
            $topValues[] = (int) $count;
            $topPercents[] = $total > 0 ? round($count * 100 / $total, 1) : 0.0;
        }

        $seasons = ['winter' => 0, 'spring' => 0, 'summer' => 0, 'autumn' => 0];
        foreach ($allMonths as $ym => $count) {
            $month = (int) substr((string) $ym, 5, 2);
            if (in_array($month, [12, 1, 2], true)) {
                $seasons['winter'] += $count;
            } elseif (in_array($month, [3, 4, 5], true)) {
                $seasons['spring'] += $count;
            } elseif (in_array($month, [6, 7, 8], true)) {
                $seasons['summer'] += $count;
            } else {
                $seasons['autumn'] += $count;
            }
        }
        $seasonLabels = [
            (string) __('statistics.season_winter'),
            (string) __('statistics.season_spring'),
            (string) __('statistics.season_summer'),
            (string) __('statistics.season_autumn'),
        ];
        $seasonValues = array_values($seasons);
        $bestSeasonIndex = 0;
        foreach ($seasonValues as $index => $value) {
            if ($value > $seasonValues[$bestSeasonIndex]) {
                $bestSeasonIndex = $index;
            }
        }

        $weekdays = array_fill(0, 7, 0);
        foreach ($this->db->fetchAll(
            'SELECT WEEKDAY(COALESCE(admitted_at, created_at)) AS wd, COUNT(*) AS c
             FROM members
             GROUP BY wd'
        ) as $row) {
            $index = (int) ($row['wd'] ?? -1);
            if ($index >= 0 && $index <= 6) {
                $weekdays[$index] = (int) $row['c'];
            }
        }
        $weekdayLabels = [];
        for ($i = 0; $i < 7; $i++) {
            $weekdayLabels[] = (string) __('statistics.weekday_' . $i);
        }
        $bestWeekday = 0;
        foreach ($weekdays as $index => $value) {
            if ($value > $weekdays[$bestWeekday]) {
                $bestWeekday = $index;
            }
        }

        return [
            'monthly' => ['labels' => $monthLabels, 'values' => $monthValues],
            'average_per_month' => round($last12Sum / 12, 1),
            'total' => $total,
            'peak' => ['label' => $peakKey !== '' ? $this->monthLabel($peakKey) : '', 'count' => $peakCount],
            'top_months' => [
                'labels' => $topLabels,
                'values' => $topValues,
                'percents' => $topPercents,
            ],
            'seasons' => [
                'labels' => $seasonLabels,
                'values' => $seasonValues,
                'best' => $seasonLabels[$bestSeasonIndex],
                'best_count' => $seasonValues[$bestSeasonIndex],
            ],
            'weekdays' => [
                'labels' => $weekdayLabels,
                'values' => $weekdays,
                'best' => $weekdayLabels[$bestWeekday],
                'best_count' => $weekdays[$bestWeekday],
            ],
        ];
    }

    /** @return list<string> */
    private function fieldRawValues(string $key): array
    {
        $rows = $this->db->fetchAll(
            "SELECT v.value
             FROM member_field_values v
             INNER JOIN member_field_definitions d ON d.id = v.field_definition_id
             WHERE d.`key` = :key AND v.value IS NOT NULL AND v.value <> ''",
            ['key' => $key]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = (string) ($row['value'] ?? '');
        }
        return $out;
    }

    /** @return list<string> */
    private function monthKeys(int $count): array
    {
        $keys = [];
        $cursor = new \DateTimeImmutable('first day of this month');
        for ($i = $count - 1; $i >= 0; $i--) {
            $keys[] = $cursor->modify("-{$i} months")->format('Y-m');
        }
        return $keys;
    }

    private function monthLabel(string $ym): string
    {
        $ts = strtotime($ym . '-01');
        return $ts === false ? $ym : date('m/Y', $ts);
    }

    /**
     * Membership-card distribution: by status, by card type and by issue year.
     *
     * @return array<string,mixed>
     */
    public function cards(): array
    {
        $stats = $this->db->fetch(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN is_active = 1 AND (expires_on IS NULL OR expires_on >= CURDATE()) THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN expires_on IS NOT NULL AND expires_on < CURDATE() THEN 1 ELSE 0 END) AS expired,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive,
                    SUM(CASE WHEN is_active = 1 AND expires_on IS NOT NULL
                              AND expires_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                             THEN 1 ELSE 0 END) AS expiring_soon
             FROM membership_cards'
        ) ?? [];

        $byTypeLabels = [];
        $byTypeValues = [];
        foreach ($this->db->fetchAll(
            'SELECT mt.name_json, COUNT(*) AS c
             FROM membership_cards c
             LEFT JOIN member_types mt ON mt.id = c.member_type_id
             GROUP BY mt.id, mt.name_json
             ORDER BY c DESC'
        ) as $row) {
            $raw = $row['name_json'] ?? null;
            $byTypeLabels[] = $raw !== null && $raw !== ''
                ? localized($raw)
                : (string) __('statistics.no_type');
            $byTypeValues[] = (int) $row['c'];
        }

        $byYearLabels = [];
        $byYearValues = [];
        foreach ($this->db->fetchAll(
            'SELECT YEAR(issued_on) AS y, COUNT(*) AS c
             FROM membership_cards
             WHERE issued_on IS NOT NULL
             GROUP BY y
             ORDER BY y ASC'
        ) as $row) {
            $byYearLabels[] = (string) ($row['y'] ?? '');
            $byYearValues[] = (int) $row['c'];
        }

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'active' => (int) ($stats['active'] ?? 0),
            'expired' => (int) ($stats['expired'] ?? 0),
            'inactive' => (int) ($stats['inactive'] ?? 0),
            'expiring_soon' => (int) ($stats['expiring_soon'] ?? 0),
            'status' => [
                'labels' => [
                    (string) __('statistics.card_active'),
                    (string) __('statistics.card_expired'),
                    (string) __('statistics.card_inactive'),
                ],
                'values' => [
                    (int) ($stats['active'] ?? 0),
                    (int) ($stats['expired'] ?? 0),
                    (int) ($stats['inactive'] ?? 0),
                ],
            ],
            'by_type' => ['labels' => $byTypeLabels, 'values' => $byTypeValues],
            'by_year' => ['labels' => $byYearLabels, 'values' => $byYearValues],
        ];
    }

    /**
     * Money side: collections per month, method and payment type, plus the outstanding balance.
     *
     * @return array<string,mixed>
     */
    public function finances(): array
    {
        $monthly = [];
        foreach ($this->db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(amount), 0) AS s
             FROM payments
             WHERE created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
             GROUP BY ym"
        ) as $row) {
            $monthly[(string) $row['ym']] = round((float) $row['s'], 2);
        }
        $months = $this->monthKeys(12);
        $monthLabels = [];
        $monthValues = [];
        foreach ($months as $ym) {
            $monthLabels[] = $this->monthLabel($ym);
            $monthValues[] = $monthly[$ym] ?? 0.0;
        }

        $methodLabels = [
            'cash' => (string) __('payments.cash'),
            'bank' => (string) __('payments.bank'),
            'other' => (string) __('payments.other'),
        ];
        $methodValues = ['cash' => 0.0, 'bank' => 0.0, 'other' => 0.0];
        foreach ($this->db->fetchAll(
            'SELECT method, COALESCE(SUM(amount), 0) AS s FROM payments GROUP BY method'
        ) as $row) {
            $key = (string) ($row['method'] ?? 'other');
            if (!isset($methodValues[$key])) {
                $key = 'other';
            }
            $methodValues[$key] += round((float) $row['s'], 2);
        }

        $typeLabels = [
            'membership' => (string) __('statistics.payment_membership'),
            'debt' => (string) __('statistics.payment_debt'),
            'adjustment' => (string) __('statistics.payment_adjustment'),
        ];
        $typeValues = ['membership' => 0.0, 'debt' => 0.0, 'adjustment' => 0.0];
        foreach ($this->db->fetchAll(
            'SELECT type, COALESCE(SUM(amount), 0) AS s FROM payments GROUP BY type'
        ) as $row) {
            $key = (string) ($row['type'] ?? 'membership');
            if (!isset($typeValues[$key])) {
                $key = 'membership';
            }
            $typeValues[$key] += round((float) $row['s'], 2);
        }

        $debt = $this->db->fetch('SELECT COALESCE(SUM(balance_due), 0) AS s FROM members');

        return [
            'monthly' => ['labels' => $monthLabels, 'values' => $monthValues],
            'by_method' => [
                'labels' => array_values($methodLabels),
                'values' => array_values($methodValues),
            ],
            'by_type' => [
                'labels' => array_values($typeLabels),
                'values' => array_values($typeValues),
            ],
            'balance_due_total' => round((float) ($debt['s'] ?? 0), 2),
            'months_total' => round(array_sum($monthValues), 2),
        ];
    }
}
