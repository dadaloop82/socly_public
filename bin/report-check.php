#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Check the automatic crash/bug report relay (socly.it) from this installation.
 *
 * Usage:
 *   php bin/report-check.php          # show the relay URL and whether sending is enabled
 *   php bin/report-check.php --send   # send a test crash report and print the relay answer
 *
 * Exit codes: 0 = ok, 1 = not configured or the relay refused the report.
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
$send = in_array('--send', $args, true);

/** @var \Socly\Services\GitHubIssueService $reports */
$reports = $app->get(\Socly\Services\GitHubIssueService::class);

echo 'relay url  : ' . (function_exists('socly_platform_api_url') ? socly_platform_api_url() : '(helper missing)') . PHP_EOL;
echo 'site url   : ' . (function_exists('socly_site_url') ? socly_site_url() : '(helper missing)') . PHP_EOL;
echo 'enabled    : ' . ($reports->isConfigured() ? 'yes' : 'no (SOCLY_REPORT_PROBLEMS=0 or relay URL missing)') . PHP_EOL;

if (!$send) {
    echo 'tip        : php bin/report-check.php --send   (sends a test crash report)' . PHP_EOL;
    exit($reports->isConfigured() ? 0 : 1);
}

$ref = 'CHECK-' . strtoupper(bin2hex(random_bytes(3)));
$result = $reports->reportCrash(
    new \RuntimeException('SOCLY relay check from ' . (gethostname() ?: 'unknown host')),
    $ref
);

echo 'test ref   : ' . $ref . PHP_EOL;
echo 'result     : ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$ok = !empty($result['ok']);
echo $ok
    ? 'Report accepted by socly.it (Admin → Reports).' . PHP_EOL
    : 'Report NOT accepted: code=' . (string) ($result['code'] ?? $result['error'] ?? 'unknown') . PHP_EOL;

exit($ok ? 0 : 1);