<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\Http\Request;
use Socly\Services\AuthService;

/**
 * Enforces idle session timeout and refreshes last_activity.
 */
final class SessionIdleMiddleware
{
    public const IDLE_SECONDS = 1800; // 30 minutes

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
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        if ($last > 0 && ($now - $last) > self::IDLE_SECONDS) {
            $this->auth->logout($request->ip());
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['_flash']['errors'] = ['session' => __('auth.session_expired')];
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
}
