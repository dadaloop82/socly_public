<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\Http\Request;
use Socly\Services\AuthService;
use Socly\Services\RecurringService;

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
        $this->runRecurringGenerator($request);
        // After auth, force setup wizard for admins with missing config.
        app('mw.setup')->handle($request);
        return true;
    }

    /**
     * Recurring journal entries must appear on their own: a throttled check on ordinary
     * authenticated requests generates the entries that are due, without waiting for
     * somebody to open the cash book. The marker file keeps the cost to a stat().
     */
    private function runRecurringGenerator(Request $request): void
    {
        try {
            if (!RecurringService::runIsDue(3600)) {
                return;
            }
            if (!function_exists('component_enabled') || !component_enabled('treasury')) {
                return;
            }
            $userId = (int) (auth_user()['id'] ?? 0);
            app(RecurringService::class)->runDueThrottled(3600, $request->ip(), $userId > 0 ? $userId : null);
        } catch (\Throwable) {
            // A broken rule must never block the page that is being served.
        }
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
