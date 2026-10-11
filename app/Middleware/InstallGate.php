<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\App;
use Socly\Core\Database;
use Socly\Core\Http\Request;

final class InstallGate
{
    public function __construct(private readonly App $app)
    {
    }

    public function handle(Request $request): bool
    {
        $path = $request->path();
        $isInstall = str_starts_with($path, '/install');
        $isLogin = $path === '/login' || str_starts_with($path, '/login/');
        $installed = $this->app->isInstalled();
        $dbOk = $this->databaseReachable();

        // Misconfigured DB: keep the login screen (with setup CTA), allow /install only as recovery.
        if (!$dbOk) {
            if ($isInstall || $isLogin) {
                return true;
            }
            redirect('/login');
        }

        if (!$installed && !$isInstall) {
            redirect('/install');
        }
        if ($installed && $isInstall) {
            redirect('/login');
        }
        return true;
    }

    private function databaseReachable(): bool
    {
        try {
            return $this->app->get(Database::class)->isReachable();
        } catch (\Throwable $e) {
            return !Database::isConnectionException($e);
        }
    }
}
