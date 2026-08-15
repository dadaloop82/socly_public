<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\Http\Request;
use Socly\Services\AuthService;

final class AuthMiddleware
{
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
        // After auth, force setup wizard for admins with missing config.
        app('mw.setup')->handle($request);
        return true;
    }
}
