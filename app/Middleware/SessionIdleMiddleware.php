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

namespace Socly\Middleware;

use Socly\Core\Http\Request;
use Socly\Services\AuthService;
use Socly\Services\ResourceBusyLock;

/**
 * Enforces idle session timeout and refreshes last_activity.
 */
final class SessionIdleMiddleware
{
    /** Default idle timeout (15 minutes); runtime value comes from session_idle_seconds(). */
    public const IDLE_SECONDS = 900; // 15 minutes

    public function __construct(
        private readonly AuthService $auth
    ) {
    }

    public function handle(Request $request): bool
    {
        if (!auth_user()) {
            return true;
        }

        $path = $request->path();
        // Open setup wizard keeps its own session keeper; do not idle-kick mid-config.
        if (str_starts_with($path, '/setup')) {
            $_SESSION['last_activity'] = time();
            $this->refreshCookie();
            return true;
        }

        $now = time();
        // Heavy jobs (statement audit, excerpt repair, in-app update) hold the resource lock for
        // longer than the idle window: never log the holder out mid-procedure.
        if ($this->holdsHeavyJobLock()) {
            $_SESSION['last_activity'] = $now;
            $this->refreshCookie();
            return true;
        }

        $last = (int) ($_SESSION['last_activity'] ?? 0);
        $idleSeconds = function_exists('session_idle_seconds') ? session_idle_seconds() : self::IDLE_SECONDS;
        if ($last > 0 && ($now - $last) > $idleSeconds) {
            // Close the session and hand the login page a notice stored in a session
            // the browser actually owns (fresh id + fresh cookie).
            $this->auth->terminateSessionWithNotice($request->ip(), __('auth.session_expired'));
            $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
            $wantsJson = str_contains($accept, 'application/json') || str_starts_with($path, '/session/');
            if ($wantsJson) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'session_expired', 'redirect' => url('/login?expired=1')]);
                exit;
            }
            redirect('/login?expired=1');
        }

        $_SESSION['last_activity'] = $now;
        $this->refreshCookie();
        return true;
    }

    /**
     * Sliding session cookie.
     *
     * PHP sends the session cookie only when it creates (or regenerates) the id: with
     * SESSION_LIFETIME minutes the browser dropped a perfectly active login at that fixed
     * deadline, however much the operator kept working. Re-sending the same id with a fresh
     * expiry makes the cookie slide, so the only reasons to be signed out are the idle window
     * (SESSION_IDLE_MINUTES), a heavy job ending or an explicit logout.
     */
    private function refreshCookie(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }
        $name = (string) session_name();
        $id = (string) session_id();
        if ($name === '' || $id === '' || (string) ($_COOKIE[$name] ?? '') !== $id) {
            // Brand-new or just-regenerated id: PHP is already sending its own cookie.
            return;
        }
        $params = session_get_cookie_params();
        $lifetime = (int) ($params['lifetime'] ?? 0);
        if ($lifetime <= 0) {
            // Browser-session cookie: nothing to renew.
            return;
        }
        $options = [
            'expires' => time() + $lifetime,
            'path' => (string) ($params['path'] ?? '/'),
            'secure' => !empty($params['secure']),
            'httponly' => !empty($params['httponly']),
            'samesite' => (string) ($params['samesite'] ?? 'Lax'),
        ];
        $domain = trim((string) ($params['domain'] ?? ''));
        if ($domain !== '') {
            $options['domain'] = $domain;
        }
        setcookie($name, $id, $options);
    }

    private function holdsHeavyJobLock(): bool
    {
        try {
            /** @var ResourceBusyLock $lock */
            $lock = app(ResourceBusyLock::class);
            $status = $lock->status();
            if ($status === null) {
                return false;
            }
            $user = auth_user();
            $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
            $sessionId = session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';

            return $lock->isHolder($status, $userId > 0 ? $userId : null, $sessionId);
        } catch (\Throwable) {
            return false;
        }
    }
}
