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

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\PlatformService;

final class SessionController extends BaseController
{
    public function __construct(View $view, private readonly PlatformService $platform)
    {
        parent::__construct($view);
    }

    public function ping(Request $request): void
    {
        $_SESSION['last_activity'] = time();
        try {
            app(\Socly\Services\PresenceService::class)->touch();
        } catch (\Throwable) {
            // never block session ping
        }
        try {
            $this->platform->maybeSendTelemetry();
        } catch (\Throwable) {
            // never block session ping
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'ts' => time()]);
    }
}
