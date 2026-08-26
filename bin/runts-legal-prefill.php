#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Background OCR/prefill of legal texts from RUNTS-imported PDFs.
 * Usage: php bin/runts-legal-prefill.php /path/to/job.json
 */

$jobFile = $argv[1] ?? '';
if ($jobFile === '' || !is_file($jobFile)) {
    fwrite(STDERR, "Usage: php bin/runts-legal-prefill.php /path/to/job.json\n");
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';

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
    echo "OK prefilled=" . implode(',', $result['prefilled'] ?? []) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    @unlink($lock);
    @unlink($jobFile);
}

exit(0);
