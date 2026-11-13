<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\Http\Request;
use Socly\Services\AuthService;

final class AuthMiddleware
{
    /** Seconds before the session copy of the permissions is refreshed from the DB. */
    private const PERMISSIONS_TTL = 300;

    public function __construct(
        private readonly AuthService $auth,
        private readonly SessionIdleMiddleware $idle
    ) {
    }

    public function handle(Request $request): bool
    {
        if (!auth_user()) {
            $this->auth->attemptRememberLogin($request->ip());
        }
        if (!auth_user()) {
            redirect('/login');
        }
        if (!$this->idle->handle($request)) {
            return false;
        }
        $this->refreshPermissionsIfStale();
        // After auth, force setup wizard for admins with missing config.
        app('mw.setup')->handle($request);
        return true;
    }

    /**
     * Upgrades may grant new permissions (migrations): refresh the session copy so
     * menus/routes appear without asking the operator to log out and in again.
     */
    private function refreshPermissionsIfStale(): void
    {
        $syncedAt = (int) ($_SESSION['permissions_synced_at'] ?? 0);
        if ($syncedAt > 0 && (time() - $syncedAt) < self::PERMISSIONS_TTL) {
            return;
        }
        $this->auth->refreshPermissions();
    }
}
