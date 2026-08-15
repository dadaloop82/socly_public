<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\Csrf;
use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\SetupService;

final class CsrfMiddleware
{
    public function __construct(
        private readonly Csrf $csrf,
        private readonly SetupService $setup
    ) {
    }

    public function handle(Request $request): bool
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        // First-run setup must never die on expired CSRF/session.
        if ($this->isOpenSetup($request)) {
            $this->csrf->token(); // keep a token available for later authenticated pages
            return true;
        }

        $token = $request->input('_token');
        if (!is_string($token) || $token === '') {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        }
        if (!$this->csrf->validate(is_string($token) ? $token : null)) {
            $setupOpen = false;
            try {
                $setupOpen = !$this->setup->isComplete();
            } catch (\Throwable) {
            }

            try {
                app('logger')->anomaly('csrf.failed', [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'has_token' => is_string($token) && $token !== '',
                    'has_session_csrf' => !empty($_SESSION['_csrf']),
                    'setup_open' => $setupOpen,
                    'ip' => $request->ip(),
                ]);
            } catch (\Throwable) {
            }

            // Soft recovery during open setup: never strand the user on a dead 419.
            if ($setupOpen) {
                redirect('/setup');
            }

            http_response_code(419);
            $layout = auth_user() ? 'layouts/app' : 'layouts/guest';
            echo app(View::class)->render('errors/419', [
                'title' => __('errors.419'),
                'setupOpen' => false,
            ], $layout);
            return false;
        }
        return true;
    }

    private function isOpenSetup(Request $request): bool
    {
        try {
            if ($this->setup->isComplete()) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        $path = $request->path();
        if ($path === '/setup' || str_starts_with($path, '/setup/')) {
            return true;
        }
        // Helper APIs used by the open setup wizard.
        return in_array($path, ['/api/fiscal-code', '/api/geo/cities', '/api/geo/addresses'], true);
    }
}
