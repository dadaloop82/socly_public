<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Support\FiscalCode;

final class GeoService
{
    /** @var list<array{nome:string,belfiore:string,provincia:string,cap:string}>|null */
    private static ?array $comuni = null;

    /** @return list<array{label:string,city:string,belfiore:string,provincia:string,cap:string}> */
    public function searchComuni(string $query, int $limit = 8): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $q = mb_strtolower($query);
        $starts = [];
        $contains = [];
        foreach ($this->comuni() as $row) {
            $name = mb_strtolower($row['nome']);
            if (!str_contains($name, $q)) {
                continue;
            }
            $item = [
                'label' => $row['nome'] . ' (' . $row['provincia'] . ')',
                'city' => $row['nome'],
                'belfiore' => $row['belfiore'],
                'provincia' => $row['provincia'],
                'cap' => $row['cap'],
            ];
            if (str_starts_with($name, $q)) {
                $starts[] = $item;
            } else {
                $contains[] = $item;
            }
            if (count($starts) >= $limit) {
                break;
            }
        }
        return array_slice(array_merge($starts, $contains), 0, $limit);
    }

    public function findComune(string $name): ?array
    {
        $name = $this->normalizePlace($name);
        if ($name === '') {
            return null;
        }
        foreach ($this->comuni() as $row) {
            if ($this->normalizePlace($row['nome']) === $name) {
                return $row;
            }
        }
        // partial unique match (accent/case insensitive)
        $matches = [];
        foreach ($this->comuni() as $row) {
            $n = $this->normalizePlace($row['nome']);
            if (str_starts_with($n, $name) || str_contains($n, $name)) {
                $matches[] = $row;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        // Prefer exact-ish starts-with when multiple contains matches
        $starts = array_values(array_filter(
            $matches,
            fn (array $row): bool => str_starts_with($this->normalizePlace($row['nome']), $name)
        ));
        return count($starts) === 1 ? $starts[0] : null;
    }

    /** @return list<array{label:string,address:string,house_number:string,city:string,postal_code:string,lat:float,lon:float}> */
    public function searchAddresses(string $query, string $city = '', int $limit = 6): array
    {
        $query = trim($query);
        $city = trim($city);
        // Address suggestions are always scoped to the preselected city.
        if ($city === '' || mb_strlen($query) < 3) {
            return [];
        }

        $cityNeedle = $this->normalizePlace($city);
        // Prefer structured Nominatim search so results stay in the chosen city.
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'street' => $query,
            'city' => $city,
            'country' => 'Italy',
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => max($limit * 2, 8),
            'countrycodes' => 'it',
        ]);
        $json = $this->httpGet($url);
        if ($json === null || $json === '[]') {
            // Fallback: free-form query still pinned to city + Italy.
            $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                'q' => $query . ', ' . $city . ', Italia',
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => max($limit * 2, 8),
                'countrycodes' => 'it',
            ]);
            $json = $this->httpGet($url);
        }
        if ($json === null) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($data as $row) {
            $addr = is_array($row['address'] ?? null) ? $row['address'] : [];
            $road = trim((string) ($addr['road'] ?? $addr['pedestrian'] ?? $addr['residential'] ?? $addr['path'] ?? ''));
            $houseNumber = trim((string) ($addr['house_number'] ?? ''));
            $cityName = trim((string) ($addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? $addr['city_district'] ?? ''));
            $display = (string) ($row['display_name'] ?? '');
            if ($road === '') {
                continue;
            }
            if (!$this->addressMatchesCity($cityNeedle, $cityName, $display, $addr)) {
                continue;
            }
            $postalCode = trim((string) ($addr['postcode'] ?? ''));
            $streetLabel = trim($road . ($houseNumber !== '' ? ' ' . $houseNumber : ''));
            $item = [
                'label' => trim($streetLabel . ', ' . $city),
                'address' => $road,
                'house_number' => $houseNumber,
                'city' => $city,
                'postal_code' => $postalCode,
                'lat' => (float) ($row['lat'] ?? 0),
                'lon' => (float) ($row['lon'] ?? 0),
            ];
            $uniqueKey = $this->addressUniqueKey($item);
            if (isset($seen[$uniqueKey])) {
                // Keep the richer duplicate (with house number / CAP) if we already stored a poorer one.
                $existingIdx = $seen[$uniqueKey];
                if ($this->addressRichness($item) > $this->addressRichness($out[$existingIdx])) {
                    $out[$existingIdx] = $item;
                }
                continue;
            }
            $seen[$uniqueKey] = count($out);
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }
        return array_values($out);
    }

    /** @param array{address?:string,house_number?:string,city?:string,postal_code?:string,label?:string} $item */
    private function addressUniqueKey(array $item): string
    {
        $parts = [
            $this->normalizePlace((string) ($item['address'] ?? '')),
            $this->normalizePlace((string) ($item['house_number'] ?? '')),
            $this->normalizePlace((string) ($item['city'] ?? '')),
            $this->normalizePlace((string) ($item['postal_code'] ?? '')),
        ];
        $key = implode('|', $parts);
        if ($key === '|||' || preg_match('/^\|+$/', $key) === 1) {
            return $this->normalizePlace((string) ($item['label'] ?? ''));
        }
        return $key;
    }

    /** @param array{house_number?:string,postal_code?:string} $item */
    private function addressRichness(array $item): int
    {
        $score = 0;
        if (trim((string) ($item['house_number'] ?? '')) !== '') {
            $score += 2;
        }
        if (trim((string) ($item['postal_code'] ?? '')) !== '') {
            $score += 1;
        }
        return $score;
    }

    private function normalizePlace(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = mb_strtolower($transliterated);
        }
        $value = preg_replace('/[^a-z0-9\s\'-]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** @param array<string, mixed> $addr */
    private function addressMatchesCity(string $cityNeedle, string $resultCity, string $display, array $addr): bool
    {
        if ($cityNeedle === '') {
            return false;
        }
        $candidates = array_filter([
            $resultCity,
            (string) ($addr['municipality'] ?? ''),
            (string) ($addr['city'] ?? ''),
            (string) ($addr['town'] ?? ''),
            (string) ($addr['village'] ?? ''),
        ], static fn ($v) => trim((string) $v) !== '');

        foreach ($candidates as $candidate) {
            if ($this->normalizePlace((string) $candidate) === $cityNeedle) {
                return true;
            }
        }

        $displayNorm = $this->normalizePlace($display);
        // Require the city as a whole token in the display name (avoid "Roma" matching "Romagnano").
        return (bool) preg_match(
            '/(?:^|[\s,])' . preg_quote($cityNeedle, '/') . '(?:[\s,]|$)/u',
            $displayNorm
        );
    }

    /** @return list<array{label:string,city:string,belfiore:string,provincia:string,cap:string}> */
    private function fuzzySearchComuni(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) {
            return [];
        }
        $typedNorm = $this->normalizePlace($query);
        if ($typedNorm === '') {
            return [];
        }

        $candidates = [];
        foreach ($this->comuni() as $row) {
            $nameNorm = $this->normalizePlace($row['nome']);
            if ($nameNorm === $typedNorm || abs(mb_strlen($nameNorm) - mb_strlen($typedNorm)) > 3) {
                continue;
            }
            if (strlen($typedNorm) > 64 || strlen($nameNorm) > 64) {
                continue;
            }
            $dist = levenshtein($typedNorm, $nameNorm);
            if ($dist <= 2) {
                $candidates[] = [
                    'dist' => $dist,
                    'prefix' => $this->prefixMatchScore($typedNorm, $nameNorm),
                    'row' => $row,
                ];
            }
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['dist'] !== $b['dist']) {
                return $a['dist'] <=> $b['dist'];
            }
            if ($a['prefix'] !== $b['prefix']) {
                return $b['prefix'] <=> $a['prefix'];
            }
            return 0;
        });
        $out = [];
        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $out[] = $this->formatComuneItem($candidate['row']);
        }
        return $out;
    }

    /**
     * Resolve a typed city/comune on blur (local registry + optional geocoder fallback).
     *
     * @return array{action:'none'|'apply'|'confirm'|'not_found',item?:array<string,mixed>,label?:string}
     */
    public function resolveComuneQuery(string $query, bool $allowForeign = false): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return ['action' => 'none'];
        }

        $typedNorm = $this->normalizePlace($query);
        $exact = $this->findComune($query);
        if ($exact !== null) {
            $item = $this->formatComuneItem($exact);
            if ($item['city'] === $query) {
                return ['action' => 'none'];
            }
            return ['action' => 'apply', 'item' => $item, 'label' => $item['label']];
        }

        $results = $this->searchComuni($query, 5);
        if ($results === []) {
            $results = $this->fuzzySearchComuni($query, 5);
        }
        if ($allowForeign) {
            $results = $this->mergeForeignGeoResult(
                $typedNorm,
                $results,
                $this->searchCityNominatim($query, false)
            );
        } elseif ($results === []) {
            $external = $this->searchCityNominatim($query, true);
            if ($external !== null) {
                $results = [$external];
            }
        }
        if ($results === []) {
            return ['action' => 'not_found'];
        }

        return $this->resolvePlaceChoice($typedNorm, $query, $results, static fn (array $row): string => (string) ($row['city'] ?? ''));
    }

    /**
     * Resolve a typed street on blur (scoped to the selected city).
     *
     * @return array{action:'none'|'apply'|'confirm',item?:array<string,mixed>,label?:string}
     */
    public function resolveAddressQuery(string $query, string $city, string $houseNumber = ''): array
    {
        $query = trim($query);
        $city = trim($city);
        if ($city === '' || mb_strlen($query) < 3) {
            return ['action' => 'none'];
        }

        $typedNorm = $this->normalizePlace($query);
        $results = $this->searchAddresses($query, $city, 5);
        if ($results === []) {
            return ['action' => 'none'];
        }

        return $this->resolvePlaceChoice(
            $typedNorm,
            $query,
            $results,
            static fn (array $row): string => (string) ($row['address'] ?? ''),
            static fn (array $row): string => (string) ($row['label'] ?? '')
        );
    }

    /**
     * @param list<array<string,mixed>> $results
     * @param callable(array<string,mixed>):string $valueFromRow
     * @param callable(array<string,mixed>):string|null $labelFromRow
     * @return array{action:'none'|'apply'|'confirm',item?:array<string,mixed>,label?:string}
     */
    private function resolvePlaceChoice(
        string $typedNorm,
        string $typedRaw,
        array $results,
        callable $valueFromRow,
        ?callable $labelFromRow = null
    ): array {
        $top = $results[0];
        $canonical = trim($valueFromRow($top));
        $canonicalNorm = $this->normalizePlace($canonical);
        $label = $labelFromRow !== null ? trim($labelFromRow($top)) : $canonical;
        if ($label === '') {
            $label = $canonical;
        }

        if ($canonicalNorm === $typedNorm && $canonical === $typedRaw) {
            return ['action' => 'none'];
        }

        if ($canonicalNorm === $typedNorm || $this->placesSimilar($typedNorm, $canonicalNorm)) {
            return ['action' => 'apply', 'item' => $top, 'label' => $label];
        }

        $closeMatches = array_values(array_filter(
            $results,
            fn (array $row): bool => $this->placesSimilar(
                $typedNorm,
                $this->normalizePlace($valueFromRow($row))
            )
        ));
        if (count($closeMatches) === 1) {
            $match = $closeMatches[0];
            $matchLabel = $labelFromRow !== null ? trim($labelFromRow($match)) : trim($valueFromRow($match));
            return [
                'action' => 'apply',
                'item' => $match,
                'label' => $matchLabel !== '' ? $matchLabel : trim($valueFromRow($match)),
            ];
        }

        if (mb_strlen($typedNorm) < 4 && count($results) > 1) {
            return ['action' => 'confirm', 'item' => $top, 'label' => $label];
        }

        return ['action' => 'confirm', 'item' => $top, 'label' => $label];
    }

    /** @param list<array<string,mixed>> $local @param array<string,mixed>|null $external @return list<array<string,mixed>> */
    private function mergeForeignGeoResult(string $typedNorm, array $local, ?array $external): array
    {
        if ($external === null) {
            return $local;
        }
        if ($local === []) {
            return [$external];
        }

        $extNorm = $this->normalizePlace((string) ($external['city'] ?? ''));
        if ($extNorm === '' || strlen($typedNorm) > 64 || strlen($extNorm) > 64) {
            return $local;
        }
        $extDist = levenshtein($typedNorm, $extNorm);

        $localTop = $local[0];
        $localNorm = $this->normalizePlace((string) ($localTop['city'] ?? ''));
        $localDist = $localNorm !== '' && strlen($localNorm) <= 64
            ? levenshtein($typedNorm, $localNorm)
            : 99;

        $localIsItalian = trim((string) ($localTop['belfiore'] ?? '')) !== '';
        $preferExternal = $extDist < $localDist
            || ($extDist <= $localDist + 1 && $localIsItalian && trim((string) ($external['belfiore'] ?? '')) === '');

        if (!$preferExternal) {
            if ($extDist <= 3 && !$this->resultsContainCity($local, (string) ($external['city'] ?? ''))) {
                $local[] = $external;
            }
            return $local;
        }

        $merged = [$external];
        foreach ($local as $item) {
            if ($this->normalizePlace((string) ($item['city'] ?? '')) !== $extNorm) {
                $merged[] = $item;
            }
        }
        return $merged;
    }

    /** @param list<array<string,mixed>> $results */
    private function resultsContainCity(array $results, string $city): bool
    {
        $needle = $this->normalizePlace($city);
        foreach ($results as $row) {
            if ($this->normalizePlace((string) ($row['city'] ?? '')) === $needle) {
                return true;
            }
        }
        return false;
    }

    /** @param array{nome:string,belfiore:string,provincia:string,cap:string} $row */
    private function formatComuneItem(array $row): array
    {
        return [
            'label' => $row['nome'] . ' (' . $row['provincia'] . ')',
            'city' => $row['nome'],
            'belfiore' => $row['belfiore'],
            'provincia' => $row['provincia'],
            'cap' => $row['cap'],
        ];
    }

    /** @return array{label:string,city:string,belfiore:string,provincia:string,cap:string}|null */
    private function searchCityNominatim(string $query, bool $italyOnly): ?array
    {
        $params = [
            'q' => $query,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => 1,
        ];
        if ($italyOnly) {
            $params['countrycodes'] = 'it';
        }
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);
        $json = $this->httpGet($url);
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
            return null;
        }
        $row = $data[0];
        $addr = is_array($row['address'] ?? null) ? $row['address'] : [];
        $city = trim((string) ($addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? ''));
        if ($city === '') {
            return null;
        }
        $state = trim((string) ($addr['state'] ?? $addr['county'] ?? ''));
        $label = $state !== '' ? $city . ' (' . $state . ')' : $city;
        return [
            'label' => $label,
            'city' => $city,
            'belfiore' => '',
            'provincia' => $state,
            'cap' => trim((string) ($addr['postcode'] ?? '')),
        ];
    }

    private function prefixMatchScore(string $typed, string $candidate): int
    {
        $max = min(strlen($typed), strlen($candidate));
        $score = 0;
        for ($i = 0; $i < $max; $i++) {
            if ($typed[$i] !== $candidate[$i]) {
                break;
            }
            $score++;
        }
        return $score;
    }

    private function placesSimilar(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if (str_starts_with($b, $a) || str_starts_with($a, $b)) {
            return abs(mb_strlen($a) - mb_strlen($b)) <= 3;
        }
        if (strlen($a) <= 64 && strlen($b) <= 64) {
            return levenshtein($a, $b) <= 2;
        }
        return false;
    }

    /** @return array{ok:bool,fiscal_code?:string,error?:string} */
    public function computeFiscalCode(string $firstName, string $lastName, string $birthDate, string $gender, string $birthPlace): array
    {
        $comune = $this->findComune($birthPlace);
        if (!$comune) {
            $matches = $this->searchComuni($birthPlace, 1);
            if ($matches) {
                $comune = [
                    'nome' => $matches[0]['city'],
                    'belfiore' => $matches[0]['belfiore'],
                    'provincia' => $matches[0]['provincia'],
                    'cap' => $matches[0]['cap'],
                ];
            }
        }
        if (!$comune || $comune['belfiore'] === '') {
            return ['ok' => false, 'error' => __('members.cf_place_unknown')];
        }
        if (!in_array(strtoupper($gender), ['M', 'F'], true)) {
            return ['ok' => false, 'error' => __('members.cf_gender_other')];
        }
        $code = FiscalCode::compute($firstName, $lastName, $birthDate, $gender, $comune['belfiore']);
        if ($code === null) {
            return ['ok' => false, 'error' => __('members.cf_incomplete')];
        }
        return ['ok' => true, 'fiscal_code' => $code];
    }

    /** @return list<array{nome:string,belfiore:string,provincia:string,cap:string}> */
    private function comuni(): array
    {
        if (self::$comuni !== null) {
            return self::$comuni;
        }
        $path = base_path('database/data/comuni.slim.json');
        if (!is_file($path)) {
            return self::$comuni = [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return self::$comuni = is_array($decoded) ? $decoded : [];
    }

    private function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_USERAGENT => 'SoclyMemberGeo/1.0 (association membership app)',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code >= 400) {
                return null;
            }
            return (string) $body;
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 6,
                'header' => "User-Agent: SoclyMemberGeo/1.0\r\nAccept: application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
}
