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
use Socly\Services\PlatformMasterService;
use Socly\Services\RecurringService;

final class AuthMiddleware
{
    /** Seconds before the session copy of the permissions is refreshed from the DB. */
    private const PERMISSIONS_TTL = 300;

    public function __construct(
        private readonly AuthService $auth,
        private readonly SessionIdleMiddleware $idle,
        private readonly PlatformMasterService $masters
    ) {
    }

    public function handle(Request $request): bool
    {
        if (!auth_user()) {
            $this->auth->attemptRememberLogin($request->ip());
        }
        if (!auth_user()) {
            return $this->denyUnauthenticated($request);
        }
        if (!$this->idle->handle($request)) {
            return false;
        }
        // A maintenance session must die with its signed credential (an upgrade to a
        // build without master support, a revoked .env credential). Close it with an
        // explicit notice instead of leaving an authenticated shell that only gets 403s.
        if (!$this->auth->masterSessionIsValid()) {
            try {
                $this->masters->demoteInactiveAccounts($request->ip());
            } catch (\Throwable) {
                // Hygiene only: never block the logout below.
            }
            $this->auth->terminateSessionWithNotice($request->ip(), __('auth.master_unavailable'));
            return $this->denyUnauthenticated($request);
        }
        // The maintenance row/name is code-owned: refresh the label of an open session
        // after a rename (the header reads the session copy, not the DB).
        $this->auth->syncMasterIdentity();
        $this->refreshPermissionsIfStale();
        $this->runRecurringGenerator($request);
        // After auth, force setup wizard for admins with missing config.
        app('mw.setup')->handle($request);
        return true;
    }

    /** Login required: JSON for XHR, redirect for a normal page load. */
    private function denyUnauthenticated(Request $request): bool
    {
        $wantsJson = strtolower((string) ($request->header('X-Requested-With') ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($request->header('Accept') ?? '')), 'application/json');
        if ($wantsJson) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'error' => 'unauthenticated',
                'redirect' => url('/login'),
            ], JSON_UNESCAPED_UNICODE);
            return false;
        }
        redirect('/login');
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
