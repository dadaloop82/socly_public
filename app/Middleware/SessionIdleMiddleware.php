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
    /** Idle timeout: no authenticated request / activity for this long → login. */
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
            return true;
        }

        $now = time();
        // Heavy jobs (statement audit, excerpt repair, in-app update) hold the resource lock for
        // longer than the idle window: never log the holder out mid-procedure.
        if ($this->holdsHeavyJobLock()) {
            $_SESSION['last_activity'] = $now;
            return true;
        }

        $last = (int) ($_SESSION['last_activity'] ?? 0);
        if ($last > 0 && ($now - $last) > self::IDLE_SECONDS) {
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
        return true;
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
