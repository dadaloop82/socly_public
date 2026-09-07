<?php

declare(strict_types=1);

/**
 * Run pending DB migrations for an isolated demo instance (no seed, no wipe).
 *
 * Usage:
 *   SOCLY_CODE_PATH=/path/to/public-mirror \
 *   php bin/demo-migrate.php /path/to/instance
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$instancePath = $argv[1] ?? '';
if ($instancePath === '' || !is_dir($instancePath)) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => 'Missing instance path'], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

$privatePath = dirname(__DIR__);
$codePath = getenv('SOCLY_CODE_PATH') ?: $privatePath;
$codePath = rtrim($codePath, '/');

define('SOCLY_PRIVATE_PATH', $privatePath);
define('SOCLY_CODE_PATH', $codePath);
define('SOCLY_INSTANCE_PATH', rtrim($instancePath, '/'));

require SOCLY_CODE_PATH . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Socly\\')) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen('Socly\\')));
    $publicFile = SOCLY_CODE_PATH . '/app/' . $rel . '.php';
    if (is_file($publicFile)) {
        return;
    }
    $privateFile = SOCLY_PRIVATE_PATH . '/app/' . $rel . '.php';
    if (is_file($privateFile)) {
        require $privateFile;
    }
}, true, true);

try {
    /** @var \Socly\Core\App $app */
    $app = require SOCLY_CODE_PATH . '/bootstrap/app.php';
    $app->get(\Socly\Services\InstallerService::class)->runMigrations();
    $version = '';
    $versionFile = SOCLY_CODE_PATH . '/VERSION';
    if (is_file($versionFile)) {
        $version = trim((string) file_get_contents($versionFile));
    }
    echo json_encode(['ok' => true, 'version' => $version], JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
