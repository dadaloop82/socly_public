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
 * The email is a fixed technical identifier (never displayed in the UI). The
 * password is NOT stored in the repository: the deployment stores an Argon2id
 * hash plus an Ed25519 signature in .env. The signing key is held only by the
 * platform owner, so a GitHub fork cannot mint or change a valid master
 * credential. Fresh installs start with master login disabled.
 *
 * Set SOCLY_DISABLE_PLATFORM_MASTER=true to refuse master login entirely.
 * Use bin/platform-master-password.php (with the signing key) to rotate.
 */
final class PlatformMasterAccess
{
    /** Display name used for the hidden maintenance row. */
    public const NAME = 'SOCLY Platform';

    /**
     * Build edition marker.
     *
     * `full` = this implementation verifies the signed maintenance credential,
     * so an instance provisioned by the platform owner keeps its SuperAdmin
     * through updates. A package whose PlatformMasterAccess.php does not carry
     * this marker is a disabled stub: UpdateService refuses to install it on an
     * instance that still has a maintenance account (silent self-lockout).
     */
    public const EDITION = 'full';

    /**
     * Maintenance account email. It is a fixed technical identifier: it is not
     * a secret, but the app never displays it in lists, setup or installers.
     */
    public const EMAIL = 'admin@socly.it';

    /**
     * HMAC-SHA256 of the lowercase email with the runtime key material.
     */
    private const EMAIL_DIGEST = '410fa23b3631295cb853e7ddc40bdade413d4904ae0fcf562790a02810fc3d49';

    /** Ed25519 public key of the platform owner (signing key never ships). */
    private const PUBLIC_KEY = 'TVhlRlg6ysLNO1lHat1CGfdkdNyn0Dq3BPbxo1KOPoE';

    /** Versioned canonical payload signed by the platform owner. */
    private const SIGNATURE_VERSION = 'socly-platform-master-v1';

    /**
     * True when this build can verify a signed maintenance credential at all
     * (a base-edition stub has no maintenance identity and cannot).
     */
    public static function isSupportedBuild(): bool
    {
        return self::EMAIL !== '';
    }

    /** True when master login is explicitly switched off in .env. */
    public static function isDisabled(): bool
    {
        $raw = strtolower(trim((string) ($_ENV['SOCLY_DISABLE_PLATFORM_MASTER'] ?? getenv('SOCLY_DISABLE_PLATFORM_MASTER') ?: '')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /** Argon2id hash from .env, or empty when not configured. */
    public static function passwordHash(): string
    {
        return self::envValue('PLATFORM_MASTER_PASSWORD_HASH');
    }

    /** Ed25519 signature (base64url) from .env, or empty when missing. */
    public static function signature(): string
    {
        return self::envValue('PLATFORM_MASTER_SIGNATURE');
    }

    public static function isConfigured(): bool
    {
        $hash = self::passwordHash();
        return $hash !== ''
            && str_starts_with($hash, '$argon2')
            && self::isSignatureValid($hash, self::signature());
    }

    /**
     * True when the credential can authenticate right now: the signed .env
     * credential OR the fixed credential embedded in the hosted/private build
     * (PlatformMasterBuiltin), unless master login is switched off entirely.
     */
    public static function isUsable(): bool
    {
        return !self::isDisabled() && (self::isConfigured() || self::builtinHash() !== '');
    }

    /**
     * Fixed maintenance credential of the hosted/private build.
     *
     * The class lives in a private-only file (`app/Support/PlatformMasterBuiltin.php`,
     * never exported to the public tree) and holds an obfuscated Argon2id hash: the
     * plaintext password is never written anywhere. Fresh installs and forks that
     * do not ship that file authenticate only with the signed .env credential.
     */
    public static function builtinHash(): string
    {
        if (!class_exists(PlatformMasterBuiltin::class)) {
            return '';
        }
        $hash = PlatformMasterBuiltin::hash();
        return str_starts_with($hash, '$argon2') ? $hash : '';
    }

    /**
     * Any accepted maintenance credential (signed env credential or built-in hash).
     */
    public static function matches(string $email, string $password): bool
    {
        return self::matchesSigned($email, $password) || self::matchesBuiltin($email, $password);
    }

    /** Signed .env credential: Argon2id hash + Ed25519 signature bound to APP_KEY. */
    public static function matchesSigned(string $email, string $password): bool
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

    /** Fixed credential embedded in the hosted/private build (see builtinHash()). */
    public static function matchesBuiltin(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '' || !self::isMasterEmail($email)) {
            return false;
        }
        if (self::isDisabled()) {
            return false;
        }
        $hash = self::builtinHash();

        return $hash !== '' && password_verify($password, $hash);
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
     * Canonical bytes signed by the platform owner. APP_KEY binds the credential
     * to this installation, so copying .env values into a fork does not work.
     */
    public static function signaturePayload(string $hash, ?string $appKey = null): string
    {
        $appKey ??= (string) ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '');
        return self::SIGNATURE_VERSION . "\n"
            . strtolower(self::EMAIL) . "\n"
            . trim($hash) . "\n"
            . hash('sha256', $appKey);
    }

    /**
     * Verify a hash/signature pair against this installation's APP_KEY.
     * Returns false for malformed values, wrong keys, copied signatures and
     * unsupported hashes.
     */
    public static function isSignatureValid(string $hash, string $signature, ?string $appKey = null): bool
    {
        $hash = trim($hash);
        $signature = trim($signature);
        if ($hash === '' || $signature === '' || !str_starts_with($hash, '$argon2')) {
            return false;
        }

        $signatureRaw = self::base64UrlDecode($signature);
        $publicKey = self::base64UrlDecode(self::PUBLIC_KEY);
        if (strlen($signatureRaw) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                $signatureRaw,
                self::signaturePayload($hash, $appKey),
                $publicKey
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /** Base64url helper shared with the rotation CLI. */
    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
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

    /** Read an env value and strip accidental surrounding quotes. */
    private static function envValue(string $key): string
    {
        $value = trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        return $value;
    }

    private static function base64UrlDecode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : '';
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
