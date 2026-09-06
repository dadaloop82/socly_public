#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Seal a secret (AES-256-GCM) for bootstrap/sealed_secrets.php.
 * Used ONLY on the private marketing host (socly.it) that relays bug reports to GitHub.
 * Public installs never need this — they POST to /api/platform.php.
 *
 * Usage:
 *   php bin/seal-secret.php github_issues_token 'ghp_…'
 *   php bin/seal-secret.php github_issues_token   # reads token from stdin
 *
 * Requires SOCLY_SEAL_KEY in .env (base64:… 32 bytes). If missing, generates one
 * and appends it to .env — keep SOCLY_SEAL_KEY private; only ciphertext is committed.
 */

use Socly\Core\Encryptor;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$keyName = $argv[1] ?? '';
$plain = $argv[2] ?? '';
if ($keyName === '') {
    fwrite(STDERR, "Usage: php bin/seal-secret.php <key_name> [plaintext]\n");
    exit(1);
}
if ($plain === '') {
    $plain = trim((string) stream_get_contents(STDIN));
}
if ($plain === '') {
    fwrite(STDERR, "Empty plaintext.\n");
    exit(1);
}

$envFile = $root . '/.env';
$env = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
}

$sealKey = (string) ($env['SOCLY_SEAL_KEY'] ?? '');
if ($sealKey === '') {
    $sealKey = Encryptor::generateKey();
    $append = "\n# Seal key for bootstrap/sealed_secrets.php (never commit)\nSOCLY_SEAL_KEY={$sealKey}\n";
    file_put_contents($envFile, $append, FILE_APPEND | LOCK_EX);
    fwrite(STDERR, "Generated SOCLY_SEAL_KEY and appended to .env\n");
}

$enc = new Encryptor($sealKey);
$cipher = $enc->encrypt($plain);

$sealedPath = $root . '/bootstrap/sealed_secrets.php';
$existing = [];
if (is_file($sealedPath)) {
    $loaded = require $sealedPath;
    if (is_array($loaded)) {
        $existing = $loaded;
    }
}
$existing[$keyName] = $cipher;

$export = var_export($existing, true);
$content = <<<PHP
<?php

declare(strict_types=1);

/**
 * Sealed secrets (AES-256-GCM). Ciphertext only — decrypt with SOCLY_SEAL_KEY.
 * Private repo only; excluded from socly_public sync.
 *
 * @return array<string, string>
 */
return {$export};

PHP;

file_put_contents($sealedPath, $content);
fwrite(STDOUT, "Sealed {$keyName} → bootstrap/sealed_secrets.php\n");
