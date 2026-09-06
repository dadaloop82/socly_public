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
 * Seal key is stored in admin settings (github.seal_key) and
 * _webSite/storage/github_seal.key — never in product .env / socly_public.
 */

use Socly\Core\Encryptor;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/_webSite/lib/db.php';

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

$sealFile = $root . '/_webSite/storage/github_seal.key';
$sealKey = trim(socly_setting_get('github.seal_key', ''));
if ($sealKey === '' && is_file($sealFile)) {
    $sealKey = trim((string) file_get_contents($sealFile));
}
if ($sealKey === '') {
    $sealKey = Encryptor::generateKey();
    fwrite(STDERR, "Generated new seal key (stored in settings + storage file).\n");
}

socly_setting_set('github.seal_key', $sealKey);
$storageDir = dirname($sealFile);
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0775, true);
}
file_put_contents($sealFile, $sealKey . "\n");
@chmod($sealFile, 0640);

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
 * Sealed secrets (AES-256-GCM). Ciphertext only — decrypt with github.seal_key / github_seal.key.
 * Private repo only; excluded from socly_public sync. Product installs never use this.
 *
 * @return array<string, string>
 */
return {$export};

PHP;

file_put_contents($sealedPath, $content);

if ($keyName === 'github_issues_token') {
    socly_setting_set('github.issues_token_enc', $cipher);
    socly_setting_set('github.issues_token', '');
    if (socly_setting_get('github.issues_repo', '') === '') {
        socly_setting_set('github.issues_repo', 'dadaloop82/socly');
    }
}

fwrite(STDOUT, "Sealed {$keyName} → bootstrap/sealed_secrets.php (+ admin settings)\n");
