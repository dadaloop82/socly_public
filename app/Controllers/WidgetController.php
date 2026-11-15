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
use Socly\Services\WidgetLayoutService;

/** Per-user widget layout (chart order + visibility). */
final class WidgetController extends BaseController
{
    public function __construct(
        View $view,
        private readonly WidgetLayoutService $widgets
    ) {
        parent::__construct($view);
    }

    public function save(Request $request): void
    {
        $user = auth_user();
        if (!$user) {
            $this->json(['ok' => false, 'error' => 'auth'], 401);
            return;
        }
        $order = $request->input('order', []);
        $hidden = $request->input('hidden', []);
        $sizes = $request->input('sizes', []);
        $result = $this->widgets->save(
            (int) ($user['id'] ?? 0),
            (string) $request->input('context', ''),
            is_array($order) ? $order : [],
            is_array($hidden) ? $hidden : [],
            is_array($sizes) ? $sizes : [],
            $request->ip()
        );
        $this->json($result, !empty($result['ok']) ? 200 : 422);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
