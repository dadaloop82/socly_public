#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Background OCR/prefill of legal texts from RUNTS-imported PDFs.
 * Usage: php bin/runts-legal-prefill.php /path/to/job.json
 *
 * For thin demos, pass SOCLY_CODE_PATH + SOCLY_INSTANCE_PATH via env
 * (SetupService sets both when queueing).
 */

$jobFile = $argv[1] ?? '';
if ($jobFile === '' || !is_file($jobFile)) {
    fwrite(STDERR, "Usage: php bin/runts-legal-prefill.php /path/to/job.json\n");
    exit(1);
}

$codePath = getenv('SOCLY_CODE_PATH') ?: dirname(__DIR__);
$instancePath = getenv('SOCLY_INSTANCE_PATH') ?: '';
if ($instancePath === '' || !is_dir($instancePath)) {
    // job.json lives at {instance}/storage/cache/runts_ocr_job_*.json
    $guess = dirname($jobFile, 3);
    if (is_dir($guess)) {
        $instancePath = $guess;
    } else {
        $instancePath = $codePath;
    }
}

if (!defined('SOCLY_CODE_PATH')) {
    define('SOCLY_CODE_PATH', rtrim((string) $codePath, '/'));
}
if (!defined('SOCLY_INSTANCE_PATH')) {
    define('SOCLY_INSTANCE_PATH', rtrim((string) $instancePath, '/'));
}

require SOCLY_CODE_PATH . '/vendor/autoload.php';
$app = require SOCLY_CODE_PATH . '/bootstrap/app.php';

/** @var array<string, mixed> $job */
$job = json_decode((string) file_get_contents($jobFile), true);
if (!is_array($job)) {
    fwrite(STDERR, "Invalid job JSON\n");
    exit(1);
}

@set_time_limit(0);
$lock = storage_path('cache/runts_ocr.lock');
@file_put_contents($lock, (string) json_encode([
    'started_at' => date('c'),
    'job' => basename($jobFile),
], JSON_UNESCAPED_UNICODE));

try {
    /** @var \Socly\Services\SetupService $setup */
    $setup = $app->get(\Socly\Services\SetupService::class);
    $docs = is_array($job['documents'] ?? null) ? $job['documents'] : [];
    $result = $setup->prefillLegalTextsFromDocuments($docs, true);
    $log = storage_path('cache/runts_ocr_last.json');
    @file_put_contents($log, (string) json_encode([
        'finished_at' => date('c'),
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "OK prefilled=" . implode(',', $result['prefilled'] ?? []) . " status=" . ($result['status'] ?? '') . "\n";
} catch (Throwable $e) {
    try {
        /** @var \Socly\Services\SetupService $setup */
        $setup = $app->get(\Socly\Services\SetupService::class);
        $setup->storeLegalOcrState('failed', [], [], false);
    } catch (Throwable $ignored) {
    }
    $log = storage_path('cache/runts_ocr_last.json');
    @file_put_contents($log, (string) json_encode([
        'finished_at' => date('c'),
        'error' => $e->getMessage(),
        'result' => ['prefilled' => [], 'status' => 'failed', 'pending_ocr' => false],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    @unlink($lock);
    @unlink($jobFile);
}

exit(0);
