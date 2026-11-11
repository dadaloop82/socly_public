<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Support\FiscalCode;
use Socly\Support\ItalianProvinces;

final class GeoService
{
    /** @var list<array{nome:string,belfiore:string,provincia:string,cap:string}>|null */
    private static ?array $comuni = null;

    /** @var array<string, string|null> Short-lived HTTP response cache (same PHP request / worker). */
    private static array $httpCache = [];

    /** @var list<callable> Geolocation jobs queued to run after the response has been flushed. */
    private static array $deferredTasks = [];

    private static bool $deferredRegistered = false;

    /** Three failed lookups in a row mean the geocoder is unreachable for this request. */
    private static bool $providerDown = false;

    private static int $nullStreak = 0;

    /** A failed lookup is retried after a week, so a geocoder outage heals itself. */
    private const MISS_RETRY_SECONDS = 604800;

    public function __construct(private readonly ?Database $db = null)
    {
    }

    /** @return list<array{label:string,city:string,belfiore:string,provincia:string,cap:string}> */
    public function searchComuni(string $query, int $limit = 12): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $q = mb_strtolower($query);
        $scored = [];
        foreach ($this->comuni() as $row) {
            $name = mb_strtolower((string) ($row['nome'] ?? ''));
            if ($name === '' || !str_contains($name, $q)) {
                continue;
            }
            $score = 0;
            if ($name === $q) {
                $score = 300;
            } elseif (str_starts_with($name, $q)) {
                // Prefer shorter names so "Bol" surfaces Bolzano before Bologna-long lists flood the quota.
                $score = 200 - min(80, mb_strlen($name));
            } else {
                $score = 50 - min(40, mb_strlen($name));
            }
            // Capitals / province seats (often shorter CAP lists) get a tiny boost when tied.
            if (($row['provincia'] ?? '') !== '' && str_starts_with($name, $q)) {
                $score += 5;
            }
            $scored[] = [
                'score' => $score,
                'item' => [
                    'label' => $row['nome'] . ' (' . $row['provincia'] . ')',
                    'city' => $row['nome'],
                    'belfiore' => $row['belfiore'],
                    'provincia' => $row['provincia'],
                    'cap' => $row['cap'],
                ],
            ];
        }
        usort($scored, static function (array $a, array $b): int {
            return ($b['score'] <=> $a['score']) ?: strcmp((string) $a['item']['city'], (string) $b['item']['city']);
        });
        $out = [];
        foreach (array_slice($scored, 0, max(1, $limit)) as $row) {
            $out[] = $row['item'];
        }
        return $out;
    }

    /** @return list<array{label:string,city:string,belfiore:string,provincia:string,cap:string}> */
    public function findComuniByCap(string $cap, int $limit = 8): array
    {
        $cap = preg_replace('/\D+/', '', trim($cap)) ?? '';
        if (strlen($cap) !== 5) {
            return [];
        }
        $out = [];
        foreach ($this->comuni() as $row) {
            if ((string) ($row['cap'] ?? '') !== $cap) {
                continue;
            }
            $out[] = $this->formatComuneItem($row);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Resolve a typed CAP on blur (local registry).
     *
     * @return array{action:'none'|'apply'|'confirm'|'not_found',item?:array<string,mixed>,label?:string}
     */
    public function resolveCapQuery(string $cap, string $cityHint = ''): array
    {
        $cap = preg_replace('/\D+/', '', trim($cap)) ?? '';
        if (strlen($cap) !== 5) {
            return ['action' => 'none'];
        }

        $results = $this->findComuniByCap($cap, 8);
        if ($results === []) {
            return ['action' => 'not_found'];
        }

        $cityHintNorm = $this->normalizePlace($cityHint);
        if ($cityHintNorm !== '') {
            foreach ($results as $row) {
                if ($this->normalizePlace((string) ($row['city'] ?? '')) === $cityHintNorm) {
                    return ['action' => 'none'];
                }
            }
        }

        if (count($results) === 1) {
            $item = $results[0];
            return ['action' => 'apply', 'item' => $item, 'label' => (string) ($item['label'] ?? $item['city'] ?? '')];
        }

        $top = $results[0];
        return [
            'action' => 'confirm',
            'item' => $top,
            'label' => (string) ($top['label'] ?? $top['city'] ?? ''),
        ];
    }

    /** @return list<array{label:string,name:string,sigla:string}> */
    public function searchProvinces(string $query, int $limit = 8): array
    {
        return ItalianProvinces::search($query, $limit);
    }

    /**
     * Latitude/longitude for a city name, cached on disk (statistics map).
     *
     * One HTTP lookup per unknown city, then the answer is reused across requests. When the
     * provider is unreachable the lookups stop for the rest of the request instead of timing out
     * once per city.
     *
     * @param bool $refreshMiss Retry a city that is cached as “not found” (stale misses).
     * @return array{lat:float,lon:float}|null
     */
    public function cityCoordinates(string $city, bool $refreshMiss = false): ?array
    {
        $city = trim($city);
        if ($city === '' || self::$providerDown) {
            return null;
        }
        $key = mb_strtolower($city);
        $cachePath = function_exists('storage_path') ? storage_path('cache/geo_city_coords.json') : '';
        $cache = $this->coordinatesCache($cachePath);
        if (array_key_exists($key, $cache)) {
            $row = $cache[$key];
            if (is_array($row) && isset($row['lat'], $row['lon'])) {
                return ['lat' => (float) $row['lat'], 'lon' => (float) $row['lon']];
            }
            if (!$refreshMiss) {
                return null;
            }
        }

        $coords = $this->geocodeCity($city);
        // Three misses in a row mean the provider is unreachable: stop trying in this request.
        if ($coords === null) {
            self::$nullStreak++;
            if (self::$nullStreak >= 3) {
                self::$providerDown = true;
            }
        } else {
            self::$nullStreak = 0;
        }
        $cache[$key] = $coords ?? ['failed_at' => time()];
        if ($cachePath !== '') {
            $dir = dirname($cachePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents(
                $cachePath,
                (string) json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        }
        return $coords;
    }

    /** True when the city already has an entry in the coordinates cache (hit or miss). */
    public function hasCoordinatesEntry(string $city): bool
    {
        static $cache = null;
        $city = trim($city);
        if ($city === '') {
            return false;
        }
        if ($cache === null) {
            $path = function_exists('storage_path') ? storage_path('cache/geo_city_coords.json') : '';
            $cache = $this->coordinatesCache($path);
        }
        return array_key_exists(mb_strtolower($city), $cache);
    }

    /**
     * Queue places for geocoding once the response has been sent: the statistics map completes
     * itself as members are created or updated, without any CLI command.
     *
     * @param array<int,string|null> $places
     */
    public static function deferWarm(array $places): void
    {
        $clean = [];
        foreach ($places as $place) {
            $name = self::cleanPlaceName((string) $place);
            if ($name === '' || StatisticsService::isForeignPlace($name)) {
                continue;
            }
            $clean[mb_strtolower($name)] = $name;
        }
        if ($clean === []) {
            return;
        }
        self::defer(static function () use ($clean): void {
            $geo = app(GeoService::class);
            foreach ($clean as $place) {
                $geo->cityCoordinates($place);
            }
        });
    }

    /**
     * Queue a background pass over the member places still missing from the coordinates cache,
     * so an existing archive fills the map visit after visit.
     */
    public static function deferPendingWarm(int $limit = 25): void
    {
        self::defer(static function () use ($limit): void {
            app(GeoService::class)->warmPending($limit);
        });
    }

    /**
     * Geocode member places that have no cache entry yet (city, else place of birth) and retry
     * lookups that failed long ago, so the map heals itself after a geocoder outage.
     *
     * @return int Number of lookups performed.
     */
    public function warmPending(int $limit = 25): int
    {
        if ($limit < 1 || $this->db === null || !$this->acquireWarmLock()) {
            return 0;
        }
        try {
            $rows = $this->db->fetchAll(
                "SELECT v.value AS place
                 FROM member_field_values v
                 INNER JOIN member_field_definitions d ON d.id = v.field_definition_id
                 WHERE d.`key` IN ('birth_place', 'city') AND v.value IS NOT NULL AND v.value <> ''"
            );
        } catch (\Throwable) {
            return 0;
        }
        $places = [];
        foreach ($rows as $row) {
            $place = self::cleanPlaceName((string) ($row['place'] ?? ''));
            if ($place === '' || StatisticsService::isForeignPlace($place)) {
                continue;
            }
            $places[mb_strtolower($place)] = $place;
        }
        if ($places === []) {
            return 0;
        }

        $cachePath = function_exists('storage_path') ? storage_path('cache/geo_city_coords.json') : '';
        $cache = $this->coordinatesCache($cachePath);
        $retryBefore = time() - self::MISS_RETRY_SECONDS;
        $queue = [];
        foreach ($places as $key => $place) {
            if (!array_key_exists($key, $cache)) {
                $queue[] = $place;
                continue;
            }
            $entry = $cache[$key];
            $failedAt = is_array($entry) && !isset($entry['lat']) ? (int) ($entry['failed_at'] ?? 0) : 0;
            if (is_array($entry) && isset($entry['lat'])) {
                continue;
            }
            // Legacy misses (stored as null) and old failures are worth another try.
            if ($failedAt === 0 || $failedAt < $retryBefore) {
                $queue[] = $place;
            }
        }

        $lookups = 0;
        foreach ($queue as $place) {
            if ($lookups >= $limit) {
                break;
            }
            $coords = $this->cityCoordinates($place, true);
            $lookups++;
            if ($coords === null && self::$providerDown) {
                // The geocoder is unreachable: the remaining places stay queued for the next pass.
                break;
            }
        }
        return $lookups;
    }

    /**
     * Strip province suffixes/parentheses so “Bolzano (BZ)” geocodes as “Bolzano”.
     */
    public static function cleanPlaceName(string $place): string
    {
        $place = trim($place);
        if ($place === '') {
            return '';
        }
        $place = (string) preg_replace('/\s*\([^)]*\)\s*/u', ' ', $place);
        $place = (string) preg_replace('/\s+/u', ' ', $place);
        return trim($place);
    }

    /** Run a job after the response has been flushed, so nobody waits for the geocoder. */
    private static function defer(callable $task): void
    {
        self::$deferredTasks[] = $task;
        if (self::$deferredRegistered) {
            return;
        }
        self::$deferredRegistered = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            foreach (self::$deferredTasks as $job) {
                try {
                    $job();
                } catch (\Throwable) {
                    // Geolocation is best effort: never break the request shutdown.
                }
            }
        });
    }

    /** Cheap throttle so concurrent visits cannot hammer the public geocoder. */
    private function acquireWarmLock(int $seconds = 30): bool
    {
        if (!function_exists('storage_path')) {
            return true;
        }
        $dir = storage_path('cache');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return true;
        }
        $lock = $dir . '/geo_warm.lock';
        if (is_file($lock) && (time() - (int) filemtime($lock)) < $seconds) {
            return false;
        }
        touch($lock);
        return true;
    }

    /** @return array{lat:float,lon:float}|null */
    private function geocodeCity(string $city): ?array
    {
        $json = $this->httpGet('https://photon.komoot.io/api/?' . http_build_query([
            'q' => $city,
            'limit' => 1,
            'lang' => 'it',
        ]));
        if ($json !== null) {
            $data = json_decode($json, true);
            $feature = is_array($data['features'][0] ?? null) ? $data['features'][0] : null;
            $coords = is_array($feature) ? ($feature['coordinates'] ?? null) : null;
            $country = strtoupper((string) ($feature['properties']['countrycode'] ?? 'IT'));
            if (is_array($coords) && ($country === 'IT' || $country === '')) {
                $lat = (float) ($coords[1] ?? 0);
                $lon = (float) ($coords[0] ?? 0);
                if ($this->isInItaly($lat, $lon)) {
                    return ['lat' => round($lat, 5), 'lon' => round($lon, 5)];
                }
            }
        }

        $json = $this->httpGet('https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $city . ', Italia',
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => 'it',
        ]));
        if ($json !== null) {
            $data = json_decode($json, true);
            $row = is_array($data[0] ?? null) ? $data[0] : null;
            if ($row !== null) {
                $lat = (float) ($row['lat'] ?? 0);
                $lon = (float) ($row['lon'] ?? 0);
                if ($this->isInItaly($lat, $lon)) {
                    return ['lat' => round($lat, 5), 'lon' => round($lon, 5)];
                }
            }
        }
        return null;
    }

    private function isInItaly(float $lat, float $lon): bool
    {
        return $lat >= 35.5 && $lat <= 47.5 && $lon >= 6.0 && $lon <= 19.0
            && !($lat === 0.0 && $lon === 0.0);
    }

    /** @return array<string,mixed> */
    private function coordinatesCache(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{action:'none'|'apply'|'confirm'|'not_found',item?:array<string,mixed>,label?:string}
     */
    public function resolveProvinceQuery(string $query): array
    {
        return ItalianProvinces::resolveQuery($query);
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
    public function searchAddresses(string $query, string $city = '', int $limit = 6, string $houseNumber = ''): array
    {
        $query = trim($query);
        $city = trim($city);
        $houseNumber = trim($houseNumber);
        // Address suggestions are always scoped to the preselected city.
        if ($city === '' || mb_strlen($query) < 2) {
            return [];
        }
        $query = $this->stripCityFromQuery($query, $city);

        // Photon is built for typeahead (partial streets like "Via Ro"); Nominatim is a fallback.
        // Squares and parks are OSM `place`/`leisure` features, not highways: asking for
        // highways only hides them, so skip the filter for those names.
        $highwaysOnly = !$this->queryLooksLikeSquare($query);
        $photon = $this->searchAddressesPhoton($query, $city, $limit, $houseNumber, $highwaysOnly);
        if ($photon === [] && $highwaysOnly) {
            // Nothing usable among highways (squares, courtyards, addresses): retry unfiltered.
            $photon = $this->searchAddressesPhoton($query, $city, $limit, $houseNumber, false);
        }
        if ($photon !== []) {
            return $photon;
        }

        return $this->searchAddressesNominatim($query, $city, $limit, $houseNumber);
    }

    /** True for names that usually map to a square/park instead of a street. */
    private function queryLooksLikeSquare(string $query): bool
    {
        return (bool) preg_match(
            '/^(piazza|piazzetta|piazzale|largo|parco|giardino|platz)\b/iu',
            trim($query)
        );
    }

    /**
     * The street field is often filled with the whole address ("Piazza Matteotti
     * a Bolzano 39100") although city and CAP have their own fields: drop the
     * trailing city (+ CAP) before searching. Only explicit separators count, so
     * "Via Roma" in Rome is never truncated to "Via".
     */
    private function stripCityFromQuery(string $query, string $city): string
    {
        $query = trim($query);
        $city = trim($city);
        if ($query === '' || $city === '') {
            return $query;
        }
        $needle = preg_quote($city, '/');
        $patterns = [
            // "…, Bolzano", "… - Bolzano", "… a Bolzano", "… in Bolzano"
            '/(?:\s*[,\-–]\s*|\s+(?:a|in|di)\s+)' . $needle . '(?:\s*,?\s*\d{5})?\s*$/iu',
            // "… Bolzano 39100" (CAP makes the intent explicit)
            '/\s+' . $needle . '\s*,?\s*\d{5}\s*$/iu',
        ];
        foreach ($patterns as $pattern) {
            $stripped = preg_replace($pattern, '', $query);
            if (is_string($stripped) && $stripped !== $query) {
                $stripped = trim($stripped);
                return mb_strlen($stripped) >= 3 ? $stripped : $query;
            }
        }
        return $query;
    }

    /**
     * Fast autocomplete via Photon (Komoot). One request, prefix-friendly.
     *
     * @return list<array{label:string,address:string,house_number:string,city:string,postal_code:string,lat:float,lon:float}>
     */
    private function searchAddressesPhoton(string $query, string $city, int $limit, string $houseNumber = '', bool $highwaysOnly = true): array
    {
        $q = $houseNumber !== '' ? trim($query . ' ' . $houseNumber) : $query;
        $params = [
            'q' => $q . ' ' . $city,
            'lang' => 'en',
            'limit' => max($limit * 3, 12),
        ];
        if ($highwaysOnly) {
            $params['osm_tag'] = 'highway';
        }
        // Avoid Nominatim bbox lookup here — it is slow/rate-limited and blocks typeahead.
        // City name in the query + post-filter is enough for suggestions.

        $json = $this->httpGet('https://photon.komoot.io/api/?' . http_build_query($params));
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        $features = is_array($decoded) ? ($decoded['features'] ?? null) : null;
        if (!is_array($features) || $features === []) {
            return [];
        }

        $cityNeedle = $this->normalizePlace($city);
        $comune = $this->findComune($city);
        $cityCap = trim((string) ($comune['cap'] ?? ''));
        $out = [];
        $seen = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
            $coords = is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [];
            $road = trim((string) ($props['street'] ?? ''));
            if ($road === '') {
                $road = trim((string) ($props['name'] ?? ''));
            }
            if ($road === '') {
                continue;
            }
            $rowHouse = trim((string) ($props['housenumber'] ?? $props['house_number'] ?? ''));
            // When Photon returns a highway feature, name is the road; housenumber stays empty.
            if ($rowHouse === '' && $houseNumber !== '' && $this->roadMatchesQuery($query, $road)) {
                $rowHouse = $houseNumber;
            }
            $cityName = trim((string) (
                $props['city'] ?? $props['town'] ?? $props['village'] ?? $props['municipality']
                ?? $props['locality'] ?? ''
            ));
            $lat = isset($coords[1]) ? (float) $coords[1] : 0.0;
            $lon = isset($coords[0]) ? (float) $coords[0] : 0.0;
            // Match city on Photon locality fields only — never on the road label
            // (e.g. "Via Policoro" in another comune must not pass).
            $cityOk = $this->addressMatchesCity($cityNeedle, $cityName, '', $props);
            if (!$cityOk || !$this->roadSuggestMatches($query, $road)) {
                continue;
            }
            $postalCode = trim((string) ($props['postcode'] ?? ''));
            if ($postalCode === '' && $cityCap !== '') {
                $postalCode = $cityCap;
            }
            $streetLabel = trim($road . ($rowHouse !== '' ? ' ' . $rowHouse : ''));
            $item = [
                'label' => trim($streetLabel . ', ' . $city),
                'address' => $road,
                'house_number' => $rowHouse,
                'city' => $city,
                'postal_code' => $postalCode,
                'lat' => $lat,
                'lon' => $lon,
            ];
            $uniqueKey = $this->addressUniqueKey($item);
            if (isset($seen[$uniqueKey])) {
                continue;
            }
            $seen[$uniqueKey] = true;
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Nominatim fallback — few requests, stop early, cache-friendly.
     *
     * @return list<array{label:string,address:string,house_number:string,city:string,postal_code:string,lat:float,lon:float}>
     */
    private function searchAddressesNominatim(string $query, string $city, int $limit, string $houseNumber = ''): array
    {
        $cityNeedle = $this->normalizePlace($city);
        $bbox = $this->cityBoundingBox($city);
        $data = [];
        $variants = $this->streetQueryVariants($query);
        // Live typing: prefer the raw query (+ Via prefix variant at most). Avoid exploding Nominatim.
        $variants = array_slice($variants, 0, 2);
        foreach ($variants as $street) {
            $streetWithNo = $houseNumber !== '' ? trim($street . ' ' . $houseNumber) : $street;
            $paramSets = [
                [
                    'q' => $streetWithNo . ', ' . $city . ', Italia',
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => max($limit * 2, 8),
                    'countrycodes' => 'it',
                ],
            ];
            if ($bbox !== null) {
                $paramSets[] = [
                    'q' => $streetWithNo,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => max($limit * 2, 8),
                    'countrycodes' => 'it',
                    'viewbox' => $bbox['viewbox'],
                    'bounded' => 1,
                ];
            }
            foreach ($paramSets as $params) {
                $json = $this->httpGet('https://nominatim.openstreetmap.org/search?' . http_build_query($params));
                if ($json === null || $json === '[]') {
                    continue;
                }
                $decoded = json_decode($json, true);
                if (!is_array($decoded) || $decoded === []) {
                    continue;
                }
                $data = array_merge($data, $decoded);
                if (count($data) >= $limit) {
                    break 2;
                }
            }
        }
        if ($data === []) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $addr = is_array($row['address'] ?? null) ? $row['address'] : [];
            $road = trim((string) ($addr['road'] ?? $addr['pedestrian'] ?? $addr['square'] ?? $addr['place'] ?? $addr['residential'] ?? $addr['path'] ?? $row['name'] ?? ''));
            $rowHouse = trim((string) ($addr['house_number'] ?? ''));
            $cityName = trim((string) ($addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? $addr['city_district'] ?? ''));
            $display = (string) ($row['display_name'] ?? '');
            if ($road === '') {
                continue;
            }
            $lat = (float) ($row['lat'] ?? 0);
            $lon = (float) ($row['lon'] ?? 0);
            $cityOk = $this->addressMatchesCity($cityNeedle, $cityName, $display, $addr);
            $inBox = $bbox !== null && $this->pointInBoundingBox($lat, $lon, $bbox);
            $roadMatches = $this->roadSuggestMatches($query, $road);
            // Require the road to relate to what the user typed (avoid "Via R" → random Via X).
            if (!$roadMatches) {
                continue;
            }
            if (!$cityOk && !($inBox && $roadMatches)) {
                continue;
            }
            $postalCode = trim((string) ($addr['postcode'] ?? ''));
            if (!$cityOk && $inBox) {
                $comune = $this->findComune($city);
                $cityCap = trim((string) ($comune['cap'] ?? ''));
                if ($cityCap !== '') {
                    $postalCode = $cityCap;
                }
            }
            $streetLabel = trim($road . ($rowHouse !== '' ? ' ' . $rowHouse : ''));
            $item = [
                'label' => trim($streetLabel . ', ' . $city),
                'address' => $road,
                'house_number' => $rowHouse,
                'city' => $city,
                'postal_code' => $postalCode,
                'lat' => $lat,
                'lon' => $lon,
            ];
            $uniqueKey = $this->addressUniqueKey($item);
            if (isset($seen[$uniqueKey])) {
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
            $uniqueCap = $this->uniqueCapForComune((string) ($item['city'] ?? $query));
            if ($uniqueCap !== null) {
                $item['cap'] = $uniqueCap;
            }
            if ($item['city'] === $query) {
                return ['action' => 'none', 'item' => $item];
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
        if ($city === '' || mb_strlen($query) < 2) {
            return ['action' => 'none'];
        }
        $query = $this->stripCityFromQuery($query, $city);

        $typedNorm = $this->normalizePlace($query);
        $results = $this->searchAddresses($query, $city, 5, $houseNumber);
        if ($results === []) {
            return ['action' => 'not_found'];
        }

        $top = $results[0];
        $road = trim((string) ($top['address'] ?? ''));
        if ($road !== '' && $this->roadMatchesQuery($query, $road)) {
            $canonicalNorm = $this->normalizePlace($road);
            $label = trim((string) ($top['label'] ?? $road));
            if ($canonicalNorm === $typedNorm && $road === $query) {
                return ['action' => 'none'];
            }
            return ['action' => 'apply', 'item' => $top, 'label' => $label !== '' ? $label : $road];
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
            $item = $top;
            if (isset($item['city'])) {
                $uniqueCap = $this->uniqueCapForComune((string) $item['city']);
                if ($uniqueCap !== null) {
                    $item['cap'] = $uniqueCap;
                }
            }
            return ['action' => 'none', 'item' => $item];
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

    /** When a comune maps to exactly one CAP in the registry, return it (for auto-fill). */
    public function uniqueCapForComune(string $name): ?string
    {
        $norm = $this->normalizePlace(trim($name));
        if ($norm === '') {
            return null;
        }
        // Use a list (not assoc keys): PHP casts numeric string keys like "39100" to int.
        $caps = [];
        foreach ($this->comuni() as $row) {
            if ($this->normalizePlace((string) ($row['nome'] ?? '')) !== $norm) {
                continue;
            }
            $cap = preg_replace('/\D+/', '', (string) ($row['cap'] ?? '')) ?? '';
            if (strlen($cap) === 5 && !in_array($cap, $caps, true)) {
                $caps[] = $cap;
            }
        }
        if (count($caps) !== 1) {
            return null;
        }

        return $caps[0];
    }

    /** @param array{nome:string,belfiore:string,provincia:string,cap:string} $row */
    private function formatComuneItem(array $row): array
    {
        $sigla = (string) ($row['provincia'] ?? '');
        return [
            'label' => $row['nome'] . ' (' . $sigla . ')',
            'city' => $row['nome'],
            'belfiore' => $row['belfiore'],
            'provincia' => $sigla,
            'provincia_name' => ItalianProvinces::expandName($sigla),
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
            'provincia_name' => ItalianProvinces::expandName($state),
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

    /**
     * Check city / CAP / province coherence against the Italian comuni registry.
     *
     * @return array{ok:bool,error?:string,field?:string,expected_province?:string,expected_cap?:string}
     */
    public function validateItalianAddressTriplet(string $city, string $postalCode = '', string $province = ''): array
    {
        $city = trim($city);
        $postalCode = preg_replace('/\D+/', '', trim($postalCode)) ?? '';
        $province = trim($province);
        if ($city === '') {
            return ['ok' => true];
        }

        $comune = $this->findComune($city);
        if ($comune === null) {
            // Unknown city: leave soft (foreign / typo handled by UI resolve).
            return ['ok' => true];
        }

        $sigla = strtoupper(trim((string) ($comune['provincia'] ?? '')));
        $expectedProvince = ItalianProvinces::expandName($sigla);
        $expectedCap = preg_replace('/\D+/', '', (string) ($comune['cap'] ?? '')) ?? '';

        if ($province !== '' && $sigla !== '' && !ItalianProvinces::matchesSigla($province, $sigla)) {
            return [
                'ok' => false,
                'field' => 'province',
                'error' => 'province_mismatch',
                'expected_province' => $expectedProvince !== '' ? $expectedProvince : $sigla,
                'expected_cap' => $expectedCap,
            ];
        }

        if ($postalCode !== '' && strlen($postalCode) === 5) {
            $matches = $this->findComuniByCap($postalCode, 20);
            $cityNorm = $this->normalizePlace($city);
            $capMatchesCity = false;
            foreach ($matches as $row) {
                if ($this->normalizePlace((string) ($row['city'] ?? '')) === $cityNorm) {
                    $capMatchesCity = true;
                    break;
                }
            }
            if (!$capMatchesCity && $expectedCap !== '' && $postalCode !== $expectedCap) {
                // Fallback: registry primary CAP for the comune.
                return [
                    'ok' => false,
                    'field' => 'postal_code',
                    'error' => 'cap_mismatch',
                    'expected_province' => $expectedProvince !== '' ? $expectedProvince : $sigla,
                    'expected_cap' => $expectedCap,
                ];
            }
            if ($matches !== [] && !$capMatchesCity) {
                return [
                    'ok' => false,
                    'field' => 'postal_code',
                    'error' => 'cap_mismatch',
                    'expected_province' => $expectedProvince !== '' ? $expectedProvince : $sigla,
                    'expected_cap' => $expectedCap !== '' ? $expectedCap : $postalCode,
                ];
            }
        }

        return [
            'ok' => true,
            'expected_province' => $expectedProvince !== '' ? $expectedProvince : $sigla,
            'expected_cap' => $expectedCap,
        ];
    }

    /** @return list<array{nome:string,belfiore:string,provincia:string,cap:string}> */
    private function comuni(): array
    {
        if (self::$comuni !== null) {
            return self::$comuni;
        }
        $path = code_path('database/data/comuni.slim.json');
        if (!is_file($path)) {
            // Fallback for odd installs that only ship data next to the instance.
            $fallback = base_path('database/data/comuni.slim.json');
            $path = is_file($fallback) ? $fallback : '';
        }
        if ($path === '' || !is_file($path)) {
            return self::$comuni = [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return self::$comuni = is_array($decoded) ? $decoded : [];
    }


    /** @return list<string> */
    private function streetQueryVariants(string $query): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
        if ($query === '') {
            return [];
        }
        $variants = [$query];
        // OSM often stores only the main road: "Via Roma - Secondo Tronco" → "Via Roma"
        $withoutBranch = trim((string) preg_replace('/\s*[-–—,]\s+.+$/u', '', $query));
        if ($withoutBranch !== '' && mb_strtolower($withoutBranch) !== mb_strtolower($query)) {
            $variants[] = $withoutBranch;
        }
        $withoutParen = trim((string) preg_replace('/\s*\([^)]*\)\s*/u', ' ', $query));
        $withoutParen = trim((string) preg_replace('/\s+/u', ' ', $withoutParen));
        if ($withoutParen !== '' && mb_strtolower($withoutParen) !== mb_strtolower($query)) {
            $variants[] = $withoutParen;
        }
        $norm = $this->normalizePlace($query);
        $prefixes = ['via', 'viale', 'corso', 'piazza', 'piazzale', 'largo', 'vicolo', 'strada', 'contrada', 'localita', 'località'];
        $hasPrefix = false;
        foreach ($prefixes as $prefix) {
            if (str_starts_with($norm, $prefix . ' ') || $norm === $prefix) {
                $hasPrefix = true;
                break;
            }
        }
        if (!$hasPrefix) {
            $variants[] = 'Via ' . $query;
            if ($withoutBranch !== '' && mb_strtolower($withoutBranch) !== mb_strtolower($query)) {
                $variants[] = 'Via ' . $withoutBranch;
            }
        }
        return array_values(array_unique($variants));
    }

    private function roadMatchesQuery(string $query, string $road): bool
    {
        $q = $this->normalizePlace($query);
        if ($q === '') {
            return false;
        }
        // OSM names are often bilingual ("Via Roma - Romstraße"): compare every part.
        foreach ($this->placeNameVariants($road) as $variant) {
            if ($this->roadNameMatches($q, $variant)) {
                return true;
            }
        }
        return false;
    }

    /** Compare one normalized name variant against the typed query. */
    private function roadNameMatches(string $q, string $r): bool
    {
        if ($q === '' || $r === '') {
            return false;
        }
        if ($q === $r || $this->placesSimilar($q, $r)) {
            return true;
        }
        // "granara" vs "via granara"
        if (str_contains($r, $q) || str_contains($q, $r)) {
            return true;
        }
        $qCore = $this->stripRoadPrefix($q);
        $rCore = $this->stripRoadPrefix($r);
        if ($qCore !== '' && $rCore !== '' && ($qCore === $rCore || $this->placesSimilar($qCore, $rCore))) {
            return true;
        }
        // "Piazza Matteotti" vs "Piazza Giacomo Matteotti": the typed name may omit
        // middle names, as long as every typed word appears in the canonical one.
        return $this->nameWordsContained($qCore !== '' ? $qCore : $q, $rCore !== '' ? $rCore : $r);
    }

    /**
     * Normalized name variants, one per language of a bilingual OSM name.
     *
     * @return list<string>
     */
    private function placeNameVariants(string $name): array
    {
        $variants = [];
        $whole = $this->normalizePlace($name);
        if ($whole !== '') {
            $variants[] = $whole;
        }
        foreach (preg_split('/\s+[-–—\/]\s+/u', $name) ?: [] as $part) {
            $norm = $this->normalizePlace((string) $part);
            if ($norm !== '') {
                $variants[] = $norm;
            }
        }
        return array_values(array_unique($variants));
    }

    /** Drop the street-type prefix ("piazza matteotti" → "matteotti"). */
    private function stripRoadPrefix(string $value): string
    {
        return trim(preg_replace(
            '/^(via|viale|corso|piazza|piazzetta|piazzale|largo|vicolo|strada|contrada|localita|platz|strasse)\s+/u',
            '',
            $value
        ) ?? $value);
    }

    /** True when every word of the typed name appears in the canonical one. */
    private function nameWordsContained(string $typed, string $canonical): bool
    {
        $typedWords = array_values(array_filter(preg_split('/\s+/u', trim($typed)) ?: []));
        $canonicalWords = array_values(array_filter(preg_split('/\s+/u', trim($canonical)) ?: []));
        if ($typedWords === [] || count($typedWords) > count($canonicalWords)) {
            return false;
        }
        foreach ($typedWords as $word) {
            // Ignore 1-2 letter noise ("a", "di") instead of treating it as a match.
            if (mb_strlen($word) < 3 || !in_array($word, $canonicalWords, true)) {
                return false;
            }
        }
        return true;
    }

    /** Prefix match for live typing: "via ro" → "via roma". */
    private function roadPrefixMatches(string $query, string $road): bool
    {
        $q = $this->normalizePlace($query);
        if ($q === '') {
            return false;
        }
        $qCore = $this->stripRoadPrefix($q);
        foreach ($this->placeNameVariants($road) as $variant) {
            if (str_starts_with($variant, $q)) {
                return true;
            }
            $rCore = $this->stripRoadPrefix($variant);
            if ($qCore !== '' && $rCore !== '' && str_starts_with($rCore, $qCore)) {
                return true;
            }
        }
        return false;
    }

    /** Match rules for typeahead: prefer prefixes; avoid "vi" matching inside "… paolo vi". */
    private function roadSuggestMatches(string $query, string $road): bool
    {
        if ($this->roadPrefixMatches($query, $road)) {
            return true;
        }
        $q = $this->normalizePlace($query);
        // Short fragments are prefix-only so every "Via…" does not collapse to noise.
        if (mb_strlen($q) < 4) {
            return false;
        }
        return $this->roadMatchesQuery($query, $road);
    }

    /**
     * @return array{viewbox:string,south:float,north:float,west:float,east:float}|null
     */
    private function cityBoundingBox(string $city): ?array
    {
        static $cache = [];
        $key = $this->normalizePlace($city);
        if ($key === '') {
            return null;
        }
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $json = $this->httpGet('https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $city . ', Italia',
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => 1,
            'countrycodes' => 'it',
        ]));
        if ($json === null) {
            return $cache[$key] = null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
            return $cache[$key] = null;
        }
        $box = $data[0]['boundingbox'] ?? null;
        if (!is_array($box) || count($box) < 4) {
            return $cache[$key] = null;
        }
        $south = (float) $box[0];
        $north = (float) $box[1];
        $west = (float) $box[2];
        $east = (float) $box[3];
        // Pad slightly so border streets just outside the polygon still match.
        $padLat = max(0.01, ($north - $south) * 0.08);
        $padLon = max(0.01, ($east - $west) * 0.08);
        $south -= $padLat;
        $north += $padLat;
        $west -= $padLon;
        $east += $padLon;
        return $cache[$key] = [
            'viewbox' => implode(',', [$west, $north, $east, $south]),
            'south' => $south,
            'north' => $north,
            'west' => $west,
            'east' => $east,
        ];
    }

    /** @param array{south:float,north:float,west:float,east:float} $bbox */
    private function pointInBoundingBox(float $lat, float $lon, array $bbox): bool
    {
        if ($lat == 0.0 && $lon == 0.0) {
            return false;
        }
        return $lat >= $bbox['south'] && $lat <= $bbox['north']
            && $lon >= $bbox['west'] && $lon <= $bbox['east'];
    }

    private function httpGet(string $url): ?string
    {
        if (array_key_exists($url, self::$httpCache)) {
            return self::$httpCache[$url];
        }
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
                return self::$httpCache[$url] = null;
            }
            return self::$httpCache[$url] = (string) $body;
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 6,
                'header' => "User-Agent: SoclyMemberGeo/1.0\r\nAccept: application/json\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return self::$httpCache[$url] = ($body === false ? null : $body);
    }
}
