#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Install/update the add-ons granted by the licence (same engine as the Add-ons page).
 *
 * Usage:
 *   php bin/addons-update.php           # check the signed catalogue and install/update
 *   php bin/addons-update.php --check   # respect the hourly cache (no forced download)
 *
 * Cron (nightly add-on updates):
 *   25 4 * * * php /path/to/socly/bin/addons-update.php >> /path/to/socly/storage/logs/addons.log 2>&1
 *
 * Exit codes: 0 = ok, 1 = one or more add-ons failed, 2 = no online licence
 * (offline installations install packages from storage/addons/incoming).
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

$args = array_slice($argv ?? [], 1);
$force = !in_array('--check', $args, true);

@set_time_limit(0);

$result = $app->get(\Socly\Core\Addon\AddonManager::class)->autoInstall('cli', $force);

foreach ($result['installed'] as $row) {
    $from = (string) ($row['from'] ?? '') !== '' ? (string) $row['from'] : '—';
    echo 'installed ' . $row['id'] . ' ' . $from . ' -> ' . $row['to'] . PHP_EOL;
}
foreach ($result['pending'] as $row) {
    echo 'pending ' . $row['id'] . ' (core >= ' . $row['min_core'] . ')' . PHP_EOL;
}
foreach ($result['errors'] as $error) {
    echo 'error ' . $error . PHP_EOL;
}
if (($result['reason'] ?? '') === 'offline') {
    echo 'no online licence: packages must be copied to storage/addons/incoming' . PHP_EOL;
    exit(2);
}
if ($result['installed'] === [] && $result['pending'] === [] && $result['errors'] === []) {
    echo 'nothing to install' . PHP_EOL;
}

exit($result['errors'] === [] ? 0 : 1);
