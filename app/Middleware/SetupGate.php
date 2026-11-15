<?php
/*
 * Socly Project Header
 *
 *   ███████╗ ██████╗  ██████╗ ██╗   ██╗
 *   ██╔════╝██╔═══██╗██╔════╝ ██║   ██║
 *   ███████╗██║   ██║██║      ██║   ██║
 *   ╚════██║██║   ██║██║      ██║   ██║
 *   ███████║╚██████╔╝╚██████╗ ╚██████╔╝
 *   ╚══════╝ ╚═════╝  ╚═════╝  ╚═════╝
 *
 *   Socly — https://socly.it/
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
use Socly\Services\SetupService;

final class SetupGate
{
    public function __construct(
        private readonly SetupService $setup
    ) {
    }

    public function handle(Request $request): bool
    {
        if (!app()->isInstalled()) {
            return true;
        }
        // A demo/showcase is not an installation to complete: never hijack its
        // navigation into the guided setup wizard.
        if (function_exists('is_demo_instance') && is_demo_instance()) {
            return true;
        }
        $path = $request->path();
        if (str_starts_with($path, '/setup') || str_starts_with($path, '/logout') || str_starts_with($path, '/updates')) {
            return true;
        }
        if (!$this->setup->isAdmin()) {
            return true;
        }
        if ($this->setup->isComplete()) {
            return true;
        }
        redirect('/setup');
    }
}
