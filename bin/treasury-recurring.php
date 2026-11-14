#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate the recurring cash-book entries that are due.
 *
 * Usage:
 *   php bin/treasury-recurring.php            # create the entries that are due
 *   php bin/treasury-recurring.php --dry-run  # only list the rules that are due
 *
 * Cron (hourly or daily):
 *   15 * * * * php /path/to/socly/bin/treasury-recurring.php >> /path/to/socly/storage/logs/recurring.log 2>&1
 *
 * Authenticated requests already run the same generator at most once an hour, so this
 * script is only needed on installations that can stay idle for days.
 *
 * Exit codes: 0 = ok, 1 = the generator failed.
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
$dryRun = in_array('--dry-run', $args, true);

/** @var \Socly\Services\RecurringService $recurring */
$recurring = $app->get(\Socly\Services\RecurringService::class);

if ($dryRun) {
    $today = date('Y-m-d');
    $due = array_values(array_filter(
        $recurring->all(),
        static fn (array $rule): bool => !empty($rule['is_active'])
            && trim((string) ($rule['next_run_date'] ?? '')) !== ''
            && (string) $rule['next_run_date'] <= $today
    ));
    echo 'Operazioni ricorrenti in scadenza: ' . count($due) . PHP_EOL;
    foreach ($due as $rule) {
        echo '  #' . (int) $rule['id'] . ' ' . (string) ($rule['description'] ?? '')
            . ' · ' . (string) ($rule['next_run_date'] ?? '') . PHP_EOL;
    }
    exit(0);
}

try {
    $created = $recurring->runDue('cli');
} catch (\Throwable $e) {
    fwrite(STDERR, 'Errore: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Movimenti ricorrenti creati: ' . $created . PHP_EOL;
exit(0);
