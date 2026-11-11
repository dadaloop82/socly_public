<?php

declare(strict_types=1);

/**
 * Optional bulk warm-up of the coordinates cache used by the statistics map.
 *
 * Usage:
 *   php bin/geo-warm.php              # geocode every place of birth / residence
 *   php bin/geo-warm.php --force      # ignore already cached entries
 *
 * Not required for normal use: new or changed places are geocoded automatically after member
 * saves and statistics visits (see GeoService::deferWarm() / deferPendingWarm()).
 * Places are geocoded once (Photon, Nominatim fallback) and stored in
 * storage/cache/geo_city_coords.json, so the map renders instantly and works offline afterwards.
 */

$codePath = getenv('SOCLY_CODE_PATH') ?: dirname(__DIR__);
if (!defined('SOCLY_CODE_PATH')) {
    define('SOCLY_CODE_PATH', rtrim((string) $codePath, '/'));
}
if (!defined('SOCLY_INSTANCE_PATH')) {
    define('SOCLY_INSTANCE_PATH', SOCLY_CODE_PATH);
}

require SOCLY_CODE_PATH . '/vendor/autoload.php';
$app = require SOCLY_CODE_PATH . '/bootstrap/app.php';

$force = in_array('--force', array_slice($argv ?? [], 1), true);

/** @var \Socly\Core\Database $db */
$db = $app->get(\Socly\Core\Database::class);
/** @var \Socly\Services\GeoService $geo */
$geo = $app->get(\Socly\Services\GeoService::class);

$rows = $db->fetchAll(
    "SELECT v.value AS place
     FROM member_field_values v
     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id
     WHERE d.`key` IN ('birth_place', 'city') AND v.value IS NOT NULL AND v.value <> ''"
);

$places = [];
foreach ($rows as $row) {
    $place = trim((string) ($row['place'] ?? ''));
    if ($place === '') {
        continue;
    }
    $place = \Socly\Services\GeoService::cleanPlaceName($place);
    if ($place === '' || \Socly\Services\StatisticsService::isForeignPlace($place)) {
        continue;
    }
    $places[$place] = true;
}
$places = array_keys($places);
sort($places);

$cachePath = storage_path('cache/geo_city_coords.json');
$cache = [];
if (is_file($cachePath)) {
    $decoded = json_decode((string) file_get_contents($cachePath), true);
    $cache = is_array($decoded) ? $decoded : [];
}

printf("Luoghi da geolocalizzare: %d%s\n", count($places), $force ? ' (force)' : '');
$ok = 0;
$missing = 0;
foreach ($places as $index => $place) {
    $key = mb_strtolower($place);
    if (!$force && isset($cache[$key]['lat'], $cache[$key]['lon'])) {
        $ok++;
        continue;
    }
    $coords = $geo->cityCoordinates($place, $force);
    if ($coords === null) {
        $missing++;
        printf("  [%d/%d] %s → non trovato\n", $index + 1, count($places), $place);
        continue;
    }
    $ok++;
    printf("  [%d/%d] %s → %.4f, %.4f\n", $index + 1, count($places), $place, $coords['lat'], $coords['lon']);
}

printf("Pronti: %d · non trovati: %d · cache: %s\n", $ok, $missing, $cachePath);
exit($missing > 0 ? 1 : 0);
