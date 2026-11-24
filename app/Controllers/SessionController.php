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
use Socly\Services\ResourceBusyLock;

final class SessionController extends BaseController
{
    public function __construct(View $view, private readonly PlatformService $platform)
    {
        parent::__construct($view);
    }

    public function ping(Request $request): void
    {
        if ($this->rejectBrowserNavigationForJson($request)) {
            return;
        }
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

    /**
     * Lightweight busy-lock probe for other browser tabs / users (never blocked by the lock).
     */
    public function resourceBusyStatus(Request $request): void
    {
        if ($this->rejectBrowserNavigationForJson($request)) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            /** @var ResourceBusyLock $lock */
            $lock = app(ResourceBusyLock::class);
            $status = $lock->status();
            if ($status === null) {
                echo json_encode(['ok' => true, 'busy' => false], JSON_UNESCAPED_UNICODE);

                return;
            }
            $user = auth_user();
            $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
            $sessionId = session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';
            $holder = $lock->isHolder($status, $userId > 0 ? $userId : null, $sessionId);
            $name = trim((string) ($status['user_name'] ?? '')) ?: __('resource_busy.someone');
            $op = (string) ($status['operation'] ?? '');
            $opLabel = trim((string) ($status['label'] ?? ''));
            if ($opLabel === '') {
                $opLabel = $lock->operationLabel($op);
            }
            $startedAt = (string) ($status['started_at'] ?? '');
            $startedTime = '';
            if ($startedAt !== '') {
                try {
                    $startedTime = (new \DateTimeImmutable($startedAt))->format('H:i');
                } catch (\Throwable) {
                    $startedTime = '';
                }
            }
            echo json_encode([
                'ok' => true,
                'busy' => true,
                'holder' => $holder,
                'user_name' => $name,
                'operation' => $op,
                'operation_label' => $opLabel,
                'started_at' => $startedAt,
                'started_time' => $startedTime,
                'started_by' => __('resource_busy.started_by', [
                    'name' => $name,
                    'time' => $startedTime !== '' ? $startedTime : '—',
                ]),
                'message' => __('resource_busy.message'),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable) {
            echo json_encode(['ok' => true, 'busy' => false], JSON_UNESCAPED_UNICODE);
        }
    }
}
