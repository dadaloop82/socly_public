<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\App;
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
        if (!$this->app->isInstalled() && !$isInstall) {
            redirect('/install');
        }
        if ($this->app->isInstalled() && $isInstall) {
            redirect('/login');
        }
        return true;
    }
}
