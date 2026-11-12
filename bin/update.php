#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Update helper (CLI) — same engine as the admin “Update” button.
 *
 * Usage:
 *   php bin/update.php            # only check, prints installed vs online
 *   php bin/update.php --apply    # fetch + fast-forward + migrations (one click)
 *   php bin/update.php --apply --force-check
 *   php bin/update.php --apply --force   # apply even if the check reports "nothing to do"
 *                                        # (recovery when a stale/blocked check says up to date)
 *
 * Cron (automatic nightly updates):
 *   15 4 * * * php /path/to/socly/bin/update.php --apply >> /path/to/socly/storage/logs/update.log 2>&1
 *
 * Exit codes: 0 = ok (also “already up to date”), 1 = check/update failed,
 * 2 = update disabled on this instance (UPDATE_ENABLED=false or temp demo).
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
$apply = in_array('--apply', $args, true);
$force = in_array('--force-check', $args, true);
$forceApply = in_array('--force', $args, true);

@set_time_limit(0);

/** @var \Socly\Services\UpdateService $updates */
$updates = $app->get(\Socly\Services\UpdateService::class);

$stamp = date('c');
$current = $updates->currentVersion();

try {
    $info = $updates->check($force);
} catch (\Throwable $e) {
    fwrite(STDERR, "[{$stamp}] check failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$current = (string) ($info['current'] ?? $current);
$remote = (string) ($info['remote'] ?? '');
$public = (string) ($info['public_version'] ?? '');
$available = !empty($info['available']);

echo "[{$stamp}] installed v{$current}"
    . ($public !== '' ? " · published v{$public}" : '')
    . ($remote !== '' ? " · remote v{$remote}" : '')
    . ($available ? ' · update available' : ' · up to date')
    . PHP_EOL;

if (!$apply) {
    exit(0);
}

if (!$available && !$forceApply) {
    echo "Nothing to do." . PHP_EOL;
    exit(0);
}

if (!$available) {
    echo "Forcing: applying the remote branch even though the check reported no update." . PHP_EOL;
}

if (!$updates->installEnabled()) {
    fwrite(STDERR, "Automatic update is disabled on this instance (UPDATE_ENABLED)." . PHP_EOL);
    exit(2);
}

$result = $updates->apply('cli');
if (!empty($result['ok'])) {
    echo 'Updated from v' . (string) ($result['from'] ?? '?') . ' to v' . (string) ($result['version'] ?? $updates->currentVersion()) . PHP_EOL;
    if (!empty($result['backup'])) {
        echo 'Backup: ' . (string) $result['backup'] . PHP_EOL;
    }
    if (!empty($result['database_backup_error'])) {
        echo 'Warning: database backup not created (' . (string) $result['database_backup_error'] . ')' . PHP_EOL;
    }
    exit(0);
}

if (!empty($result['rolled_back'])) {
    fwrite(STDERR, 'Update failed and the previous version was restored: ' . (string) ($result['message'] ?? 'unknown error') . PHP_EOL);
    exit(1);
}
fwrite(STDERR, 'Update failed: ' . (string) ($result['message'] ?? 'unknown error') . PHP_EOL);
exit(1);
