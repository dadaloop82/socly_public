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
use Socly\Services\PauseGameService;

/**
 * Scoreboard of the pause mini-game: read the shared record and store the operator's best score.
 * Lightweight JSON used while a heavy job keeps the instance paused, so the endpoint is exempt from
 * the resource-busy block and never touches the audit log.
 */
final class PauseGameController extends BaseController
{
    public function __construct(View $view, private readonly PauseGameService $game)
    {
        parent::__construct($view);
    }

    public function state(Request $request): void
    {
        // Never dump the scoreboard into a browser tab (address bar / bookmark / history).
        if ($this->rejectBrowserNavigationForJson($request)) {
            return;
        }
        $userId = $this->userId();
        if ($userId < 1) {
            $this->json(['ok' => false, 'error' => 'forbidden'], 403);
            return;
        }
        $this->json(['ok' => true] + $this->game->state($userId));
    }

    public function save(Request $request): void
    {
        $userId = $this->userId();
        if ($userId < 1) {
            $this->json(['ok' => false, 'error' => 'forbidden'], 403);
            return;
        }
        $user = auth_user();
        $name = trim((string) ($user['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($user['email'] ?? ''));
        }
        if ($name === '') {
            $name = (string) __('resource_busy.someone');
        }
        $this->json($this->game->save($userId, $name, (int) $request->input('score', 0)));
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function userId(): int
    {
        $user = auth_user();

        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }
}
