#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Socly one-click installer — shared CLI bootstrap.
 *
 * Validates the PHP environment, writes .env, applies migrations and seeds
 * permissions. It does NOT create the association/admin account: the web
 * installer (/install) keeps that interactive step and the Terms acceptance.
 */

$codePath = dirname(__DIR__, 2);
if (!defined('SOCLY_CODE_PATH')) {
    define('SOCLY_CODE_PATH', $codePath);
}
if (!defined('SOCLY_INSTANCE_PATH')) {
    define('SOCLY_INSTANCE_PATH', $codePath);
}

require $codePath . '/vendor/autoload.php';

use Socly\Core\Database;
use Socly\Core\Encryptor;
use Socly\Core\Migrator;
use Socly\Services\AuditService;
use Socly\Services\InstallerService;
use Socly\Services\SettingsService;
use Socly\Support\EnvWriter;

// Portable long-option parser (getopt() is unreliable on Windows).
$options = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (!is_string($arg) || $arg === '--') {
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        $options['help'] = true;
        continue;
    }
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $arg = substr($arg, 2);
    if (str_contains($arg, '=')) {
        [$key, $value] = explode('=', $arg, 2);
        $options[$key] = $value;
    } else {
        $options[$arg] = true;
    }
}

if (isset($options['help'])) {
    echo <<<TXT
Socly installer bootstrap

Usage:
  php bin/install/bootstrap.php [options]

Options:
  --db-host=HOST     MySQL/MariaDB host (default: 127.0.0.1)
  --db-port=PORT     MySQL/MariaDB port (default: 3306)
  --db-name=NAME     Database name (default: socly)
  --db-user=USER     Database user (default: socly)
  --db-pass=PASS     Database password (or SOCLY_DB_PASS env var)
  --url=URL          Public application URL (default: http://127.0.0.1:8080)
  --locale=it|de|en  Default locale (default: it)
  --create-db        Create the database if the user is allowed to
  --skip-migrate     Write .env only, skip migrations/permissions
  --check            Validate PHP/extensions/writability and exit
  --help             Show this help

TXT;
    exit(0);
}

$fail = static function (string $message, int $code = 1): never {
    fwrite(STDERR, "✖ {$message}\n");
    exit($code);
};
$ok = static function (string $message): void {
    echo "✔ {$message}\n";
};

$option = static function (string $name) use ($options): string {
    $value = $options[$name] ?? '';
    return is_string($value) ? trim($value) : '';
};

// ---------------------------------------------------------------------------
// Environment checks
// ---------------------------------------------------------------------------
if (PHP_VERSION_ID < 80200) {
    $fail('PHP 8.2+ richiesto, trovato ' . PHP_VERSION);
}
$required = ['pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo'];
$missing = array_values(array_filter($required, static fn (string $ext): bool => !extension_loaded($ext)));
if ($missing !== []) {
    $fail('Estensioni PHP mancanti: ' . implode(', ', $missing));
}
if (!extension_loaded('curl')) {
    echo "⚠ Estensione curl assente: telemetria/aggiornamenti automatici disabilitati.\n";
}

$envPath = base_path('.env');
$examplePath = $codePath . '/.env.example';
$checkMode = isset($options['check']);
if (!is_file($envPath) && !$checkMode) {
    if (!is_file($examplePath)) {
        $fail('File .env.example non trovato.');
    }
    if (!@copy($examplePath, $envPath)) {
        $fail('Impossibile creare .env (permessi sulla cartella del progetto).');
    }
    @chmod($envPath, 0600);
}
$existing = EnvWriter::parseFile($envPath);
$pick = static function (string $key, string $default = '') use ($existing): string {
    $value = $existing[$key] ?? '';
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
};

$dbHost = $option('db-host') ?: (getenv('SOCLY_DB_HOST') ?: $pick('DB_HOST', '127.0.0.1'));
$dbPort = $option('db-port') ?: (getenv('SOCLY_DB_PORT') ?: $pick('DB_PORT', '3306'));
$dbName = $option('db-name') ?: (getenv('SOCLY_DB_NAME') ?: $pick('DB_DATABASE', 'socly'));
$dbUser = $option('db-user') ?: (getenv('SOCLY_DB_USER') ?: $pick('DB_USERNAME', 'socly'));
$dbPass = $option('db-pass');
if ($dbPass === '') {
    $dbPass = (string) (getenv('SOCLY_DB_PASS') ?: $pick('DB_PASSWORD', ''));
}
$url = rtrim($option('url') ?: (getenv('SOCLY_APP_URL') ?: $pick('APP_URL', 'http://127.0.0.1:8080')), '/');
$locale = $option('locale') ?: (getenv('SOCLY_LOCALE') ?: $pick('APP_LOCALE', 'it'));
if (!in_array($locale, ['it', 'de', 'en'], true)) {
    $locale = 'it';
}
$appKey = $pick('APP_KEY');
if ($appKey === '') {
    $appKey = Encryptor::generateKey();
}

if (isset($options['check'])) {
    $ok('PHP ' . PHP_VERSION);
    $ok('Estensioni richieste: ' . implode(', ', $required));
    $ok('vendor/ ' . (is_dir($codePath . '/vendor') ? 'presente' : 'MANCANTE — esegui composer install'));
    $ok('.env ' . (!is_file($envPath) ? 'assente (verra creato)' : (is_writable($envPath) ? 'scrivibile' : 'NON scrivibile')));
    $ok('storage/ ' . (is_writable(base_path('storage')) ? 'scrivibile' : 'NON scrivibile'));
    $ok('Database configurato per ' . $dbUser . '@' . $dbHost . ':' . $dbPort . '/' . $dbName);
    exit(0);
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 6,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
    $server = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbHost, $dbPort),
        $dbUser,
        $dbPass,
        $pdoOptions
    );
} catch (Throwable $e) {
    $fail(
        "Database non raggiungibile ({$dbUser}@{$dbHost}:{$dbPort}).\n" .
        "Verifica che MariaDB/MySQL sia avviato e che utente/password siano corretti.\n" .
        'Dettaglio: ' . $e->getMessage(),
        3
    );
}
if (isset($options['create-db'])) {
    try {
        $server->exec(
            'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbName) . '` ' .
            'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        $fail('Impossibile creare il database "' . $dbName . '": ' . $e->getMessage(), 3);
    }
}
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName),
        $dbUser,
        $dbPass,
        $pdoOptions
    );
} catch (Throwable $e) {
    $fail(
        'Database "' . $dbName . '" non accessibile. Creane uno o passa --create-db. ' . $e->getMessage(),
        3
    );
}
$ok('Connessione database');

// ---------------------------------------------------------------------------
// .env + storage + migrations + permissions
// ---------------------------------------------------------------------------
/**
 * chmod(0600) is a no-op on Windows: use icacls to restrict the file to the
 * current account, SYSTEM and the local Administrators group.
 */
$hardenSecretFile = static function (string $path): void {
    if (PHP_OS_FAMILY === 'Windows') {
        if (!function_exists('exec')) {
            fwrite(STDERR, "⚠ ACL non applicate a .env: exec() disabilitato.\n");
            return;
        }
        $user = trim((string) getenv('USERNAME'));
        $domain = trim((string) getenv('USERDOMAIN'));
        if ($user === '') {
            fwrite(STDERR, "⚠ ACL non applicate a .env: utente Windows non rilevato.\n");
            return;
        }
        $identity = $domain !== '' ? $domain . '\\' . $user : $user;
        $command = 'icacls ' . escapeshellarg($path)
            . ' /inheritance:r /grant:r ' . escapeshellarg($identity . ':(R,W)')
            . ' ' . escapeshellarg('*S-1-5-18:(F)')
            . ' ' . escapeshellarg('*S-1-5-32-544:(F)') . ' 2>&1';
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        if ($code !== 0) {
            fwrite(STDERR, "⚠ ACL non applicate a .env (icacls exit {$code}).\n");
        }
        return;
    }
    if (!chmod($path, 0600)) {
        fwrite(STDERR, "⚠ Impossibile impostare chmod 0600 su {$path}.\n");
    }
};

InstallerService::writeEnv([
    'app_url' => $url,
    'app_key' => $appKey,
    'db_host' => $dbHost,
    'db_port' => $dbPort,
    'db_database' => $dbName,
    'db_username' => $dbUser,
    'db_password' => $dbPass,
    'app_locale' => $locale,
]);
$hardenSecretFile($envPath);
$ok('.env aggiornato');

foreach (['logs', 'cache', 'sessions', 'uploads', 'documents', 'runts'] as $dir) {
    $path = storage_path($dir);
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        $fail('Impossibile creare ' . $path);
    }
}
@chmod(storage_path(), 0775);
$ok('Cartelle storage pronte');

if (!isset($options['skip-migrate'])) {
    try {
        $database = new Database([
            'host' => $dbHost,
            'port' => $dbPort,
            'database' => $dbName,
            'username' => $dbUser,
            'password' => $dbPass,
        ]);
        $migrator = new Migrator($database, $codePath . '/database/migrations');
        $settings = new SettingsService($database, new Encryptor($appKey));
        $installer = new InstallerService($database, $migrator, $settings, new AuditService($database));
        $installer->runMigrations();
        $installer->seedPermissions();
    } catch (Throwable $e) {
        $fail('Migrazioni/permessi non applicati: ' . $e->getMessage(), 4);
    }
    $ok('Migrazioni e permessi applicati');
}

echo "\nSocly è pronto per l'installazione guidata.\n";
echo "  URL:       {$url}/install\n";
echo "  Database:  {$dbName} su {$dbHost}:{$dbPort}\n";
echo "  Lingua:    {$locale}\n";
echo "Completa il wizard nel browser (associazione, tesseramento, campi), poi accedi con l'account\n";
echo "di manutenzione e completa il setup in /setup per creare l'Admin dell'associazione.\n";

