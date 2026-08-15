<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\Http\Request;
use Socly\Services\SetupService;
use Socly\Support\Permission;
use Socly\Support\SetupSessionKeeper;

/**
 * First-time setup is open without login while incomplete.
 * After completion, only authenticated admins may re-enter.
 */
final class SetupBootstrapMiddleware
{
    public function __construct(
        private readonly SetupService $setup
    ) {
    }

    public function handle(Request $request): bool
    {
        if (!$this->setup->isComplete()) {
            SetupSessionKeeper::keepAlive();
            return true;
        }

        if (!auth_user()) {
            redirect('/login');
        }

        if (!can(Permission::SETTINGS_MANAGE)) {
            try {
                app('logger')->anomaly('setup.access_denied', [
                    'path' => $request->path(),
                    'user_id' => auth_user()['id'] ?? null,
                ]);
            } catch (\Throwable) {
            }
            redirect('/dashboard');
        }

        return true;
    }
}
