#!/usr/bin/env php
<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║PRATIA
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */


declare(strict_types=1);

/**
 * Create, rotate, sign or inspect the platform maintenance password.
 * Requires the platform-owner Ed25519 signing key (never committed) to issue or
 * sign a credential; a plain GitHub fork cannot produce a valid one. Writes the
 * Argon2id hash and its signature into .env and clears SOCLY_DISABLE_PLATFORM_MASTER.
 *
 * Usage:
 *   php bin/platform-master-password.php              # generate; print once to stdout (no file)
 *   php bin/platform-master-password.php --set '…'    # use the given plaintext once
 *   php bin/platform-master-password.php --sign       # sign the existing hash (recovery: no password change)
 *   php bin/platform-master-password.php --status     # show whether master auth is ready (read-only)
 *   php bin/platform-master-password.php --repair     # demote orphan system-admin rows (read/write DB)
 *   php bin/platform-master-password.php --disable    # refuse master login (kill-switch)
 *
 * Exit codes: 0 = ok, 1 = error.
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

use Socly\Support\PlatformMasterAccess;

$args = array_slice($argv ?? [], 1);
$mode = 'generate';
$password = '';
foreach ($args as $i => $arg) {
    if ($arg === '--status') {
        $mode = 'status';
    } elseif ($arg === '--repair') {
        $mode = 'repair';
    } elseif ($arg === '--sign') {
        $mode = 'sign';
    } elseif ($arg === '--disable') {
        $mode = 'disable';
    } elseif ($arg === '--set') {
        $mode = 'set';
        $password = (string) ($args[$i + 1] ?? '');
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php bin/platform-master-password.php [--status|--sign|--repair|--disable|--set PASSWORD]\n");
        exit(0);
    }
}

// Diagnostic modes never write .env: they must work on a read-only deployment.
if ($mode === 'status') {
    $edition = defined(PlatformMasterAccess::class . '::EDITION')
        ? (string) constant(PlatformMasterAccess::class . '::EDITION')
        : 'base';
    try {
        /** @var \Socly\Services\PlatformMasterService $service */
        $service = $app->get(\Socly\Services\PlatformMasterService::class);
        $status = $service->status();
    } catch (Throwable $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    echo 'account=maintenance' . PHP_EOL;
    echo 'edition=' . $edition . PHP_EOL;
    echo 'supported=' . ($status['supported'] ? 'yes' : 'no') . PHP_EOL;
    echo 'hash=' . ($status['hash_present'] ? 'present' : 'missing') . PHP_EOL;
    echo 'signature=' . $status['signature'] . PHP_EOL;
    echo 'disabled=' . ($status['disabled'] ? 'yes' : 'no') . PHP_EOL;
    echo 'configured=' . ($status['configured'] ? 'yes' : 'no') . PHP_EOL;
    echo 'login_possible=' . ($status['login_possible'] ? 'yes' : 'no') . PHP_EOL;
    echo 'db_row=' . $status['db_row'] . PHP_EOL;
    echo 'orphan_system_admins=' . (int) $status['orphan_system_admins'] . PHP_EOL;
    foreach ($status['warnings'] as $warning) {
        echo 'warning=' . $warning . PHP_EOL;
    }
    if (in_array('hash_without_signature', $status['warnings'], true)) {
        echo 'hint=run "--sign" with the platform signing key to keep the current password' . PHP_EOL;
    }
    if (in_array('hash_present_but_disabled', $status['warnings'], true)) {
        echo 'hint=SOCLY_DISABLE_PLATFORM_MASTER=true blocks the credential above' . PHP_EOL;
    }
    if (in_array('orphan_system_admins', $status['warnings'], true)) {
        echo 'hint=run "--repair" to clear the orphan system-admin rows' . PHP_EOL;
    }
    if (!$status['supported']) {
        echo 'hint=this build does not verify signed master credentials; install the full release' . PHP_EOL;
    }
    exit(0);
}

$envPath = base_path('.env');
if ($mode === 'repair') {
    try {
        /** @var \Socly\Services\PlatformMasterService $service */
        $service = $app->get(\Socly\Services\PlatformMasterService::class);
        $changed = $service->demoteInactiveAccounts();
        echo 'demoted_rows=' . $changed . PHP_EOL;
        echo 'credential_active=' . ($service->credentialActive() ? 'yes' : 'no') . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

if (!is_file($envPath) || !is_writable($envPath)) {
    fwrite(STDERR, "error: .env missing or not writable at {$envPath}\n");
    exit(1);
}

/** @param array<string,string> $pairs */
$writeEnvPairs = static function (array $pairs) use ($envPath): void {
    $current = \Socly\Support\EnvWriter::parseFile($envPath);
    foreach ($pairs as $key => $value) {
        $current[$key] = $value;
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    $order = [
        'APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_KEY',
        'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'APP_LOCALE', 'SESSION_LIFETIME',
        'SOCLY_SITE_URL', 'SOCLY_PLATFORM_API_URL', 'SOCLY_REPORT_PROBLEMS',
        'SOCLY_DISABLE_PLATFORM_MASTER', 'PLATFORM_MASTER_PASSWORD_HASH', 'PLATFORM_MASTER_SIGNATURE',
        'UPDATE_NOTIFY', 'UPDATE_MANIFEST_URL', 'UPDATE_REPO', 'UPDATE_CHANNEL', 'UPDATE_ENABLED',
    ];
    $escape = static fn (string $value): string => \Socly\Services\InstallerService::envEscape($value);

    $lines = ['# Socly system environment', ''];
    $written = [];
    foreach ($order as $key) {
        if (!array_key_exists($key, $current)) {
            continue;
        }
        $lines[] = $key . '=' . $escape((string) $current[$key]);
        $written[$key] = true;
        if (in_array($key, ['APP_KEY', 'DB_PASSWORD', 'SESSION_LIFETIME', 'PLATFORM_MASTER_PASSWORD_HASH', 'PLATFORM_MASTER_SIGNATURE', 'UPDATE_ENABLED'], true)) {
            $lines[] = '';
        }
    }
    foreach ($current as $key => $value) {
        if (isset($written[$key])) {
            continue;
        }
        $lines[] = $key . '=' . $escape((string) $value);
    }
    $lines[] = '';
    if (file_put_contents($envPath, implode("\n", $lines)) === false) {
        throw new RuntimeException('failed writing .env');
    }
    @chmod($envPath, 0600);
};

/**
 * Read the platform-owner Ed25519 signing key. The key never ships with an
 * instance: a deployment that does not hold it cannot issue master credentials.
 */
$loadSigningKey = static function (): string {
    $signingKeyPath = (string) (getenv('SOCLY_PLATFORM_MASTER_SIGNING_KEY') ?: storage_path('platform_master_signing.key'));
    if (!is_file($signingKeyPath)) {
        throw new RuntimeException("platform master signing key missing ({$signingKeyPath})");
    }
    $signingKey = base64_decode(trim((string) file_get_contents($signingKeyPath)), true);
    if (!is_string($signingKey) || strlen($signingKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('invalid platform master signing key');
    }
    return $signingKey;
};

/** Best-effort: reactivate the DB row once a valid credential is in .env. */
$reactivateRow = static function () use ($app): void {
    try {
        /** @var \Socly\Core\Database $db */
        $db = $app->get(\Socly\Core\Database::class);
        $row = $db->fetch('SELECT id FROM users WHERE email = :e LIMIT 1', ['e' => PlatformMasterAccess::EMAIL]);
        if ($row) {
            $db->update('users', [
                'is_active' => 1,
                'is_system_admin' => 1,
                'name' => PlatformMasterAccess::NAME,
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            ], 'id = :id', ['id' => (int) $row['id']]);
        }
    } catch (Throwable) {
        // .env is enough for auth; the DB row is created on first successful login.
    }
};

try {
    if ($mode === 'disable') {
        $writeEnvPairs([
            'SOCLY_DISABLE_PLATFORM_MASTER' => 'true',
        ]);
        echo "Platform master login disabled (SOCLY_DISABLE_PLATFORM_MASTER=true).\n";
        exit(0);
    }

    if ($mode === 'sign') {
        // Recovery path: the hash already in .env stays valid, only the missing (or
        // invalid) signature is issued. Used after an upgrade that dropped the
        // signature, or when .env carries a legacy hash from before signatures.
        $current = \Socly\Support\EnvWriter::parseFile($envPath);
        $hash = trim((string) ($current['PLATFORM_MASTER_PASSWORD_HASH'] ?? ''));
        if ($hash === '' || !str_starts_with($hash, '$argon2')) {
            fwrite(STDERR, "error: .env has no Argon2id PLATFORM_MASTER_PASSWORD_HASH to sign\n");
            exit(1);
        }
        $signatureRaw = sodium_crypto_sign_detached(
            PlatformMasterAccess::signaturePayload($hash),
            $loadSigningKey()
        );
        $writeEnvPairs([
            'SOCLY_DISABLE_PLATFORM_MASTER' => 'false',
            'PLATFORM_MASTER_SIGNATURE' => PlatformMasterAccess::base64UrlEncode($signatureRaw),
        ]);
        $reactivateRow();
        echo 'account=maintenance' . PHP_EOL;
        echo 'signature=' . (PlatformMasterAccess::isSignatureValid($hash, PlatformMasterAccess::signature()) ? 'valid' : 'invalid') . PHP_EOL;
        echo 'login_possible=' . (PlatformMasterAccess::isConfigured() ? 'yes' : 'no') . PHP_EOL;
        echo "Existing password unchanged; PLATFORM_MASTER_SIGNATURE written to .env.\n";
        exit(0);
    }

    if ($mode === 'set') {
        if ($password === '') {
            fwrite(STDERR, "error: --set requires a non-empty password\n");
            exit(1);
        }
        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1,
        ]);
        if (!is_string($hash) || $hash === '') {
            fwrite(STDERR, "error: password_hash failed\n");
            exit(1);
        }
        $secret = ['password' => $password, 'hash' => $hash];
    } else {
        $secret = PlatformMasterAccess::generateSecret();
    }

    $signatureRaw = sodium_crypto_sign_detached(
        PlatformMasterAccess::signaturePayload($secret['hash']),
        $loadSigningKey()
    );

    $writeEnvPairs([
        'SOCLY_DISABLE_PLATFORM_MASTER' => 'false',
        'PLATFORM_MASTER_PASSWORD_HASH' => $secret['hash'],
        'PLATFORM_MASTER_SIGNATURE' => PlatformMasterAccess::base64UrlEncode($signatureRaw),
    ]);

    $reactivateRow();

    echo 'Account: maintenance' . PHP_EOL;
    echo 'Password: ' . $secret['password'] . PHP_EOL;
    echo "SOCLY_DISABLE_PLATFORM_MASTER=false\n";
    echo "PLATFORM_MASTER_PASSWORD_HASH + PLATFORM_MASTER_SIGNATURE written to .env (quoted safely for Dotenv)\n";
    echo "Store the password offline — it will not be shown again and is never written to disk.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
