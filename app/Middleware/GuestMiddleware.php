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
