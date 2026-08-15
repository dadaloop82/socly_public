<?php

declare(strict_types=1);

namespace Socly\Support;

/**
 * Keeps the PHP session cookie alive during first-run setup.
 * Draft form data lives in the session and must not vanish mid-wizard.
 */
final class SetupSessionKeeper
{
    private const BOOTSTRAP_LIFETIME = 7 * 24 * 3600; // 7 days

    public static function keepAlive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        @ini_set('session.gc_maxlifetime', (string) self::BOOTSTRAP_LIFETIME);
        $_SESSION['_setup_boot'] = true;
        $_SESSION['_setup_touched_at'] = time();

        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + self::BOOTSTRAP_LIFETIME,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?: '',
            'secure' => (bool) $params['secure'],
            'httponly' => true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
}
