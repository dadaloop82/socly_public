<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\Http\Request;
use Socly\Services\AuthService;
use Socly\Services\SetupService;

final class GuestMiddleware
{
    public function __construct(
        private readonly AuthService $auth
    ) {
    }

    public function handle(Request $request): bool
    {
        if (!auth_user()) {
            $this->auth->attemptRememberLogin($request->ip());
        }
        if (auth_user()) {
            try {
                $setup = app(SetupService::class);
                // Demos never point to the wizard: straight to the dashboard.
                if (function_exists('is_demo_instance') && is_demo_instance()) {
                    redirect('/dashboard');
                }
                if ($setup->isAdmin() && !$setup->isComplete()) {
                    redirect('/setup');
                }
            } catch (\Throwable) {
                // ignore if container not ready
            }
            redirect('/dashboard');
        }
        return true;
    }
}
