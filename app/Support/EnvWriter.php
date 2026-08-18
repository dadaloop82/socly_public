<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * Loads/writes .env.user without touching system .env keys.
 */
final class EnvWriter
{
    /** @var list<string> */
    public const SYSTEM_KEYS = [
        'APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_KEY',
        'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'SESSION_LIFETIME',
        'UPDATE_REPO', 'UPDATE_CHANNEL', 'UPDATE_ENABLED', 'UPDATE_SSH_HOST',
        'UPDATE_NOTIFY', 'UPDATE_MANIFEST_URL',
    ];

    /** @return array<string, string> */
    public static function parseFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /** Apply .env.user into $_ENV / putenv, skipping system keys. */
    public static function loadUserEnv(string $basePath): void
    {
        $path = rtrim($basePath, '/') . '/.env.user';
        foreach (self::parseFile($path) as $key => $value) {
            if (in_array($key, self::SYSTEM_KEYS, true)) {
                continue;
            }
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    /** @param array<string, string|int|bool|null> $pairs */
    public static function setUserValues(array $pairs): void
    {
        $path = base_path('.env.user');
        $current = self::parseFile($path);
        foreach ($pairs as $key => $value) {
            $key = strtoupper((string) $key);
            if (in_array($key, self::SYSTEM_KEYS, true)) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $current[$key] = $value === null ? '' : (string) $value;
            $_ENV[$key] = $current[$key];
            $_SERVER[$key] = $current[$key];
            putenv($key . '=' . $current[$key]);
        }
        self::writeFile($path, $current);
    }

    /** @param array<string, string> $pairs */
    private static function writeFile(string $path, array $pairs): void
    {
        ksort($pairs);
        $lines = [
            '# Socly user / association choices — managed by the setup wizard.',
            '# Do not store DB credentials or APP_KEY here.',
            '',
        ];
        foreach ($pairs as $key => $value) {
            $escaped = $value;
            if ($escaped !== '' && preg_match('/[\s#"\']/', $escaped)) {
                $escaped = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $escaped) . '"';
            }
            $lines[] = $key . '=' . $escaped;
        }
        $lines[] = '';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, implode("\n", $lines), LOCK_EX);
    }

    /** Wipe all user association choices (keeps the file with header only). */
    public static function resetUserFile(): void
    {
        $path = base_path('.env.user');
        self::writeFile($path, []);
        foreach ([
            'ASSOCIATION_NAME', 'ASSOCIATION_LEGAL_NAME', 'ASSOCIATION_EMAIL', 'ASSOCIATION_PHONE',
            'ASSOCIATION_ADDRESS', 'ASSOCIATION_CITY', 'ASSOCIATION_POSTAL_CODE', 'ASSOCIATION_HOUSE_NUMBER',
            'ASSOCIATION_FISCAL_CODE', 'ASSOCIATION_VAT', 'ASSOCIATION_PEC', 'ASSOCIATION_RUNTS',
            'ASSOCIATION_WEBSITE',
            'BRANDING_PRIMARY', 'BRANDING_ACCENT', 'APP_LOCALE', 'GDPR_ENABLED',
        ] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }
}
