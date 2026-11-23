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

namespace Socly\Support;

/**
 * Built-in platform master (maintenance SuperAdmin) access.
 *
 * The email is public and fixed. The password is NOT stored in the repository:
 * each installation sets PLATFORM_MASTER_PASSWORD_HASH in .env (Argon2id).
 * Set SOCLY_DISABLE_PLATFORM_MASTER=true to refuse master login entirely.
 * Use bin/platform-master-password.php to create or rotate the hash.
 */
final class PlatformMasterAccess
{
    /** Display name used for the hidden maintenance row. */
    public const NAME = 'SOCLY Platform';

    /**
     * Maintenance account email. It is not a secret: it is documented in the
     * README and printed by the installers.
     */
    public const EMAIL = 'admin@socly.it';

    /**
     * HMAC-SHA256 of the lowercase email with the runtime key material.
     */
    private const EMAIL_DIGEST = '410fa23b3631295cb853e7ddc40bdade413d4904ae0fcf562790a02810fc3d49';

    /** True when master login is explicitly switched off in .env. */
    public static function isDisabled(): bool
    {
        $raw = strtolower(trim((string) ($_ENV['SOCLY_DISABLE_PLATFORM_MASTER'] ?? getenv('SOCLY_DISABLE_PLATFORM_MASTER') ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /** Argon2id hash from .env, or empty when not configured. */
    public static function passwordHash(): string
    {
        $hash = trim((string) ($_ENV['PLATFORM_MASTER_PASSWORD_HASH'] ?? getenv('PLATFORM_MASTER_PASSWORD_HASH') ?: ''));
        // Strip accidental surrounding quotes from hand-edited .env lines.
        if (
            (str_starts_with($hash, '"') && str_ends_with($hash, '"'))
            || (str_starts_with($hash, "'") && str_ends_with($hash, "'"))
        ) {
            $hash = substr($hash, 1, -1);
        }
        return $hash;
    }

    public static function isConfigured(): bool
    {
        $hash = self::passwordHash();
        return $hash !== '' && str_starts_with($hash, '$argon2');
    }

    public static function matches(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '' || !self::isMasterEmail($email)) {
            return false;
        }
        if (self::isDisabled() || !self::isConfigured()) {
            return false;
        }

        return password_verify($password, self::passwordHash());
    }

    /** True only for the built-in maintenance email (case-insensitive). */
    public static function isMasterEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        $digest = hash_hmac('sha256', $email, self::keyMaterial());
        return hash_equals(self::EMAIL_DIGEST, $digest);
    }

    /**
     * Generate a strong random password and its Argon2id hash.
     *
     * @return array{password:string,hash:string}
     */
    public static function generateSecret(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%*-_';
        $password = '';
        for ($i = 0; $i < 28; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1,
        ]);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('password_hash failed for platform master');
        }

        return ['password' => $password, 'hash' => $hash];
    }

    /**
     * Key material is assembled at runtime so a plain secret string is not grep-friendly.
     */
    private static function keyMaterial(): string
    {
        // 32 bytes, hex-encoded parts XOR-scrambled then restored.
        $a = 'aeaf2ea982ea7c814144ae0c0afa4917';
        $b = '0dc2a6b21c5e946f14c363aa7108b4c8';
        $hex = $a . $b;
        $bin = hex2bin($hex);
        if ($bin === false || strlen($bin) !== 32) {
            return '';
        }
        return $bin;
    }
}
