#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Enable or disable an installed add-on from the shell, without opening the panel.
 * The rescue tool when an add-on makes the pages fail and the browser UI is not usable.
 *
 * Usage:
 *   php bin/addons-toggle.php                 # list the installed add-ons and their state
 *   php bin/addons-toggle.php <id> off        # disable (keeps files and data)
 *   php bin/addons-toggle.php <id> on         # enable (an active licence is required)
 *
 * Exit codes: 0 = ok, 1 = refused, not found or bad usage.
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
$id = trim((string) ($args[0] ?? ''));
$action = strtolower(trim((string) ($args[1] ?? '')));

/** @var \Socly\Core\Addon\AddonManager $manager */
$manager = $app->get(\Socly\Core\Addon\AddonManager::class);

if ($id === '') {
    $rows = $manager->installed();
    if ($rows === []) {
        echo 'no add-ons installed' . PHP_EOL;
        exit(0);
    }
    foreach ($rows as $row) {
        echo (string) $row['id'] . ' ' . (string) $row['version'] . ' '
            . (!empty($row['is_enabled']) ? 'enabled' : 'disabled')
            . (!empty($row['licensed']) ? '' : ' · licence missing') . PHP_EOL;
    }
    exit(0);
}

if (!in_array($action, ['on', 'off'], true)) {
    fwrite(STDERR, 'Usage: php bin/addons-toggle.php <id> on|off' . PHP_EOL);
    exit(1);
}

$result = $action === 'on' ? $manager->enable($id, 'cli') : $manager->disable($id, 'cli');
if (empty($result['ok'])) {
    fwrite(STDERR, 'error: ' . (string) ($result['error'] ?? 'operation failed') . PHP_EOL);
    exit(1);
}

echo ($action === 'on' ? 'enabled ' : 'disabled ') . $id . PHP_EOL;
exit(0);