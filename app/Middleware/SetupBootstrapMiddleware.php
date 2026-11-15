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
use Socly\Services\SetupService;
use Socly\Support\Permission;
use Socly\Support\SetupSessionKeeper;

/**
 * First-time setup: public demos stay open, real installations require the
 * built-in maintenance login. After completion, only authenticated admins may
 * re-enter.
 */
final class SetupBootstrapMiddleware
{
    public function __construct(
        private readonly SetupService $setup
    ) {
    }

    public function handle(Request $request): bool
    {
        // The guided setup is not available inside a demo: the showcase must
        // never rewrite its own seeded data through the wizard.
        if (function_exists('is_demo_instance') && is_demo_instance()) {
            redirect('/dashboard');
        }

        if (!$this->setup->isComplete()) {
            SetupSessionKeeper::keepAlive();
            if ($this->setup->allowsAnonymousSetup()) {
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
