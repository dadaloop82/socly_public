<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Support\ItalyMap;

/**
 * Aggregate, read-only statistics for the dedicated statistics page.
 *
 * Every figure comes from data the app really stores (members, custom fields, cards, payments):
 * the external reference product also counts events/attendance/feedback, which this product has
 * no table for, so those blocks are simply not offered.
 */
final class StatisticsService
{
    /**
     * Country names that must not be geocoded as Italian municipalities
     * (e.g. "Argentina" is also a comune in Imperia, but it means the country here).
     *
     * @var array<string, true>
     */
    private const FOREIGN_PLACES = [
        'italia' => true, 'italy' => true, 'argentina' => true, 'austria' => true, 'brasile' => true,
        'brazil' => true, 'germania' => true, 'deutschland' => true, 'svizzera' => true, 'switzerland' => true,
        'francia' => true, 'france' => true, 'romania' => true, 'albania' => true, 'marocco' => true,
        'tunisia' => true, 'senegal' => true, 'nigeria' => true, 'ghana' => true, 'cina' => true,
        'china' => true, 'india' => true, 'pakistan' => true, 'bangladesh' => true, 'sri lanka' => true,
        'filippine' => true, 'philippines' => true, 'vietnam' => true, 'giappone' => true, 'japan' => true,
        'corea' => true, 'ucraina' => true, 'ukraine' => true, 'polonia' => true, 'poland' => true,
        'moldavia' => true, 'russia' => true, 'bulgaria' => true, 'serbia' => true, 'croazia' => true,
        'croatia' => true, 'slovenia' => true, 'kosovo' => true, 'macedonia' => true, 'bosnia' => true,
        'montenegro' => true, 'ungheria' => true, 'hungary' => true, 'slovacchia' => true, 'repubblica ceca' => true,
        'spagna' => true, 'spain' => true, 'portogallo' => true, 'belgio' => true, 'olanda' => true,
        'paesi bassi' => true, 'lussemburgo' => true, 'regno unito' => true, 'inghilterra' => true,
        'irlanda' => true, 'grecia' => true, 'turchia' => true, 'egitto' => true, 'israele' => true,
        'libano' => true, 'siria' => true, 'perù' => true, 'peru' => true, 'colombia' => true,
        'ecuador' => true, 'venezuela' => true, 'messico' => true, 'cuba' => true,
        'repubblica dominicana' => true, 'stati uniti' => true, 'usa' => true, 'canada' => true,
        'australia' => true, 'sudafrica' => true, 'kenya' => true, 'etiopia' => true, 'eritrea' => true,
    ];
    public function __construct(
        private readonly Database $db,
        private readonly GeoService $geo
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
            'under18' => 0,
            '18_25' => 0,
            '26_35' => 0,
            '36_50' => 0,
            '51_65' => 0,
            'over65' => 0,
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
                $bands['under18']++;
            } elseif ($age <= 25) {
                $bands['18_25']++;
            } elseif ($age <= 35) {
                $bands['26_35']++;
            } elseif ($age <= 50) {
                $bands['36_50']++;
            } elseif ($age <= 65) {
                $bands['51_65']++;
            } else {
                $bands['over65']++;
            }
        }
        $ageTotal = array_sum($bands);
        // Largest band first, like the reference report.
        arsort($bands);
        $ageLabels = [];
        $ageValues = [];
        $agePercents = [];
        foreach ($bands as $band => $count) {
            if ($band === 'unknown' && $count === 0) {
                continue;
            }
            $ageLabels[] = $band === 'unknown'
                ? (string) __('statistics.unknown')
                : (string) __('statistics.age_' . $band);
            $ageValues[] = $count;
            $agePercents[] = $ageTotal > 0 ? round($count * 100 / $ageTotal, 1) : 0.0;
        }

        return [
            'gender' => ['labels' => $genderLabels, 'values' => $genderValues, 'total' => $genderTotal],
            'age_bands' => [
                'labels' => $ageLabels,
                'values' => $ageValues,
                'percents' => $agePercents,
            ],
        ];
    }

    /**
     * Places of origin with map coordinates and simple distance clustering.
     *
     * The city of residence wins when present; the place of birth (the fiscal-code source) is the
     * fallback for members without it.
     *
     * @return array{
     *   cities:list<array{place:string,count:int,x:float|null,y:float|null}>,
     *   clusters:list<array{label:string,count:int,cities:list<string>,x:float,y:float}>,
     *   located:int,total:int,max:int,pending:int,unresolved:int,foreign:int
     * }
     */
    public function cityMap(int $maxLookups = 12): array
    {
        $rows = $this->db->fetchAll(
            'SELECT COALESCE(NULLIF(TRIM(cz.value), \'\'), NULLIF(TRIM(bp.value), \'\')) AS place
             FROM members m
             LEFT JOIN member_field_values bp ON bp.member_id = m.id
                AND bp.field_definition_id = (SELECT d1.id FROM member_field_definitions d1 WHERE d1.`key` = \'birth_place\' LIMIT 1)
             LEFT JOIN member_field_values cz ON cz.member_id = m.id
                AND cz.field_definition_id = (SELECT d2.id FROM member_field_definitions d2 WHERE d2.`key` = \'city\' LIMIT 1)'
        );

        $counts = [];
        $foreign = 0;
        foreach ($rows as $row) {
            $place = $this->normalizePlaceName((string) ($row['place'] ?? ''));
            if ($place === '') {
                continue;
            }
            if (self::isForeignPlace($place)) {
                $foreign++;
                $counts['__foreign__'] = ($counts['__foreign__'] ?? 0) + 1;
                continue;
            }
            $counts[$place] = ($counts[$place] ?? 0) + 1;
        }
        arsort($counts);
        $total = array_sum($counts);

        $lookups = 0;
        $located = 0;
        $pending = 0;
        $unresolved = 0;
        $points = [];
        $out = [];
        foreach ($counts as $place => $count) {
            if ($place === '__foreign__') {
                continue;
            }
            $coords = null;
            // The per-request lookup budget only applies to network calls, cached places are free.
            $known = $this->geo->hasCoordinatesEntry((string) $place);
            if ($known || $lookups < $maxLookups) {
                $coords = $this->geo->cityCoordinates((string) $place);
                if (!$known) {
                    $lookups++;
                }
            }
            $point = ['x' => null, 'y' => null];
            if ($coords !== null) {
                $projected = ItalyMap::project((float) $coords['lat'], (float) $coords['lon']);
                $point = ['x' => $projected['x'], 'y' => $projected['y']];
                $located += $count;
                $points[] = [
                    'city' => (string) $place,
                    'count' => $count,
                    'x' => $projected['x'],
                    'y' => $projected['y'],
                ];
            } else {
                if ($known) {
                    $unresolved++;
                } else {
                    $pending++;
                }
            }
            $out[] = ['city' => (string) $place, 'count' => $count, 'x' => $point['x'], 'y' => $point['y']];
        }

        // Greedy clustering: points closer than CLUSTER_RADIUS map-percent units merge into one bubble.
        $radius = 4.0;
        $clusters = [];
        foreach ($points as $point) {
            $merged = false;
            foreach ($clusters as $index => $cluster) {
                $dx = (float) $cluster['x'] - (float) $point['x'];
                $dy = (float) $cluster['y'] - (float) $point['y'];
                if (sqrt($dx * $dx + $dy * $dy) <= $radius) {
                    $clusters[$index]['count'] += (int) $point['count'];
                    $clusters[$index]['cities'][] = (string) $point['city'];
                    $merged = true;
                    break;
                }
            }
            if (!$merged) {
                $clusters[] = [
                    'label' => (string) $point['city'],
                    'count' => (int) $point['count'],
                    'cities' => [(string) $point['city']],
                    'x' => (float) $point['x'],
                    'y' => (float) $point['y'],
                ];
            }
        }
        usort($clusters, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        if ($foreign > 0) {
            $out[] = [
                'city' => (string) __('statistics.foreign'),
                'count' => $foreign,
                'x' => null,
                'y' => null,
            ];
        }

        return [
            'cities' => $out,
            'clusters' => $clusters,
            'located' => $located,
            'total' => $total,
            'max' => $clusters === [] ? 0 : (int) max(array_column($clusters, 'count')),
            'pending' => $pending,
            'unresolved' => $unresolved,
            'foreign' => $foreign,
        ];
    }

    /** True when the value is a country (born abroad), not an Italian municipality. */
    public static function isForeignPlace(string $place): bool
    {
        $key = mb_strtolower(trim($place));
        return $key !== '' && isset(self::FOREIGN_PLACES[$key]);
    }

    /** Strip province suffixes/parentheses so "Bolzano (BZ)" geocodes as "Bolzano". */
    private function normalizePlaceName(string $place): string
    {
        $place = trim($place);
        if ($place === '') {
            return '';
        }
        $place = (string) preg_replace('/\s*\([^)]*\)\s*/u', ' ', $place);
        $place = (string) preg_replace('/\s*[·,]\s*[A-Z]{2}\s*$/u', '', $place);
        $place = (string) preg_replace('/\s+/u', ' ', $place);
        return trim($place);
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

        $months = $allMonths === []
            ? []
            : $this->monthRange((string) array_key_first($allMonths), date('Y-m'));
        if (count($months) > 240) {
            $months = array_slice($months, -240);
        }
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
        $seasonTotal = array_sum($seasonValues);
        $seasonPercents = [];
        foreach ($seasonValues as $value) {
            $seasonPercents[] = $seasonTotal > 0 ? round($value * 100 / $seasonTotal, 1) : 0.0;
        }
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
                'percents' => $seasonPercents,
                'best' => $seasonLabels[$bestSeasonIndex],
                'best_count' => $seasonValues[$bestSeasonIndex],
                'best_percent' => $seasonPercents[$bestSeasonIndex],
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
    private function monthRange(string $from, string $to): array
    {
        $keys = [];
        $cursor = \DateTimeImmutable::createFromFormat('Y-m-d', $from . '-01');
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $to . '-01');
        if (!$cursor instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable) {
            return $keys;
        }
        while ($cursor <= $end) {
            $keys[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
            if (count($keys) > 600) {
                break;
            }
        }
        return $keys;
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
