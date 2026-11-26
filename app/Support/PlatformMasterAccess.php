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
 */

declare(strict_types=1);

namespace Socly\Support;

/**
 * Public base edition: no maintenance SuperAdmin is available.
 *
 * The hosted/private build replaces this stub with the signed credential
 * implementation. Keeping the API here lets shared services load safely while
 * guaranteeing that a fork cannot enable master access by editing .env.
 */
final class PlatformMasterAccess
{
    /** Display name used by shared services; no hidden row is created here. */
    public const NAME = 'SOCLY Platform';

    /** Empty on purpose: the public edition exposes no maintenance identity. */
    public const EMAIL = '';

    /** HMAC-SHA256 of the lowercase email; keeps legacy rows hidden without showing it. */
    private const EMAIL_DIGEST = '410fa23b3631295cb853e7ddc40bdade413d4904ae0fcf562790a02810fc3d49';

    public static function isDisabled(): bool
    {
        return true;
    }

    public static function passwordHash(): string
    {
        return '';
    }

    public static function signature(): string
    {
        return '';
    }

    public static function isConfigured(): bool
    {
        return false;
    }

    public static function matches(string $email, string $password): bool
    {
        return false;
    }

    public static function isMasterEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        return hash_equals(
            self::EMAIL_DIGEST,
            hash_hmac('sha256', $email, self::keyMaterial())
        );
    }

    public static function signaturePayload(string $hash, ?string $appKey = null): string
    {
        return '';
    }

    public static function isSignatureValid(string $hash, string $signature, ?string $appKey = null): bool
    {
        return false;
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array{password:string,hash:string} */
    public static function generateSecret(): array
    {
        throw new \RuntimeException('Platform master is not available in the public edition');
    }

    /** Key material is assembled at runtime so a plain secret string is not grep-friendly. */
    private static function keyMaterial(): string
    {
        $a = 'aeaf2ea982ea7c814144ae0c0afa4917';
        $b = '0dc2a6b21c5e946f14c363aa7108b4c8';
        $bin = hex2bin($a . $b);
        return $bin !== false && strlen($bin) === 32 ? $bin : '';
    }
}