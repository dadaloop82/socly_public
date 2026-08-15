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
        $name = mb_strtolower(trim($name));
        if ($name === '') {
            return null;
        }
        foreach ($this->comuni() as $row) {
            if (mb_strtolower($row['nome']) === $name) {
                return $row;
            }
        }
        // partial unique match
        $matches = [];
        foreach ($this->comuni() as $row) {
            if (str_starts_with(mb_strtolower($row['nome']), $name)) {
                $matches[] = $row;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
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
