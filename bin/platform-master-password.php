#!/usr/bin/env php
<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
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
 * Create or rotate the platform master (admin@socly.it) password.
 * Writes PLATFORM_MASTER_PASSWORD_HASH into .env and clears SOCLY_DISABLE_PLATFORM_MASTER.
 *
 * Usage:
 *   php bin/platform-master-password.php              # generate a random password
 *   php bin/platform-master-password.php --set '…'    # use the given plaintext once
 *   php bin/platform-master-password.php --disable    # refuse master login (kill-switch)
 *   php bin/platform-master-password.php --status     # show whether master auth is ready
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
    } elseif ($arg === '--disable') {
        $mode = 'disable';
    } elseif ($arg === '--set') {
        $mode = 'set';
        $password = (string) ($args[$i + 1] ?? '');
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php bin/platform-master-password.php [--status|--disable|--set PASSWORD]\n");
        exit(0);
    }
}

$envPath = base_path('.env');
if (!is_file($envPath) || !is_writable($envPath)) {
    fwrite(STDERR, "error: .env missing or not writable at {$envPath}\n");
    exit(1);
}

if ($mode === 'status') {
    $disabled = PlatformMasterAccess::isDisabled();
    $configured = PlatformMasterAccess::isConfigured();
    echo 'email=' . PlatformMasterAccess::EMAIL . PHP_EOL;
    echo 'disabled=' . ($disabled ? 'yes' : 'no') . PHP_EOL;
    echo 'configured=' . ($configured ? 'yes' : 'no') . PHP_EOL;
    echo 'login_possible=' . (!$disabled && $configured ? 'yes' : 'no') . PHP_EOL;
    exit(0);
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
        'SOCLY_DISABLE_PLATFORM_MASTER', 'PLATFORM_MASTER_PASSWORD_HASH',
        'UPDATE_NOTIFY', 'UPDATE_MANIFEST_URL', 'UPDATE_REPO', 'UPDATE_CHANNEL', 'UPDATE_ENABLED',
    ];
    $escape = static function (string $value): string {
        if ($value !== '' && preg_match('/[\s#"\'\\\\$]/', $value)) {
            return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"';
        }
        return $value;
    };

    $lines = ['# Socly system environment', ''];
    $written = [];
    foreach ($order as $key) {
        if (!array_key_exists($key, $current)) {
            continue;
        }
        $lines[] = $key . '=' . $escape((string) $current[$key]);
        $written[$key] = true;
        if (in_array($key, ['APP_KEY', 'DB_PASSWORD', 'SESSION_LIFETIME', 'PLATFORM_MASTER_PASSWORD_HASH', 'UPDATE_ENABLED'], true)) {
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

try {
    if ($mode === 'disable') {
        $writeEnvPairs([
            'SOCLY_DISABLE_PLATFORM_MASTER' => 'true',
        ]);
        echo "Platform master login disabled (SOCLY_DISABLE_PLATFORM_MASTER=true).\n";
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

    $writeEnvPairs([
        'SOCLY_DISABLE_PLATFORM_MASTER' => 'false',
        'PLATFORM_MASTER_PASSWORD_HASH' => $secret['hash'],
    ]);

    // Best-effort: reactivate the DB row if the database is reachable.
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
        // .env is enough for auth; DB row is created on first successful login.
    }

    $credPath = storage_path('PLATFORM_MASTER_CREDENTIALS.txt');
    $cred = [
        'SOCLY platform master credentials (one-shot)',
        'Generated (UTC): ' . gmdate('c'),
        'Email: ' . PlatformMasterAccess::EMAIL,
        'Password: ' . $secret['password'],
        '',
        'Store offline and delete this file after login.',
        '',
    ];
    file_put_contents($credPath, implode("\n", $cred));
    @chmod($credPath, 0600);

    echo 'Email: ' . PlatformMasterAccess::EMAIL . PHP_EOL;
    echo 'Password: ' . $secret['password'] . PHP_EOL;
    echo 'Saved to: ' . $credPath . PHP_EOL;
    echo "SOCLY_DISABLE_PLATFORM_MASTER=false\n";
    echo "PLATFORM_MASTER_PASSWORD_HASH written to .env\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
