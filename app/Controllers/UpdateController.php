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
use Socly\Services\RateLimiter;
use Socly\Services\SetupService;
use Socly\Services\UpdateService;

final class UpdateController extends BaseController
{
    private const CHECK_MAX_ATTEMPTS = 30;
    private const CHECK_DECAY_SECONDS = 60;

    public function __construct(
        View $view,
        private readonly UpdateService $updates,
        private readonly SetupService $setup,
        private readonly PlatformService $platform,
        private readonly RateLimiter $rateLimiter
    ) {
        parent::__construct($view);
    }

    public function install(Request $request): void
    {
        if (!can_manage_updates()) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $result = $this->updates->apply($request->ip());
        if ($result['ok']) {
            try {
                // New version: refresh the installation card immediately.
                $this->platform->maybeSyncInstallation(true);
            } catch (\Throwable) {
            }
            $message = (string) $result['message'];
            if (!empty($result['backup'])) {
                $message .= ' ' . __('updates.backup_created', ['path' => (string) $result['backup']]);
            }
            if (!empty($result['database_backup_error'])) {
                $message .= ' ' . __('updates.backup_missing', ['error' => (string) $result['database_backup_error']]);
            }
            $this->flash('success', $message);
            if (!$this->setup->isComplete()) {
                unset($_SESSION['setup_greeted']);
                redirect('/setup');
            }
            redirect('/dashboard');
        }
        $this->flash('errors', [$result['message']]);
        redirect('/dashboard');
    }

    /** Current step of a running update (polled by the UI). */
    public function progress(Request $request): void
    {
        if (!can_manage_updates()) {
            $this->json(['ok' => false, 'error' => 'forbidden'], 403);
            return;
        }
        $this->json(['ok' => true, 'progress' => $this->updates->progressState()]);
    }

    public function check(Request $request): void
    {
        $force = (string) $request->input('force', '') === '1';
        // The update endpoint is also called by the public login page: technical
        // details (git/SSH/permission errors, repo URL) are visible to admins only.
        $isAdmin = can_manage_updates();
        $ipKey = 'updates.check:ip:' . $request->ip();
        if (!$isAdmin
            && $this->rateLimiter->tooManyAttempts($ipKey, self::CHECK_MAX_ATTEMPTS, self::CHECK_DECAY_SECONDS)
        ) {
            $this->json(['ok' => false, 'error' => 'rate_limited'], 429);
            return;
        }
        if (!$isAdmin) {
            $this->rateLimiter->hit($ipKey, self::CHECK_DECAY_SECONDS);
        }
        try {
            $info = $this->updates->check($force);
            if ($isAdmin) {
                try {
                    // Keep the registry in sync even when the update is only checked (next heartbeat).
                    app(\Socly\Services\SettingsService::class)->set('platform.installation_needs_sync', '1');
                    $this->platform->maybeSyncInstallation();
                } catch (\Throwable) {
                }
            }
            // Guests: version availability only (no repo / commit fingerprinting).
            if (!$isAdmin) {
                $this->json([
                    'ok' => true,
                    'available' => !empty($info['available']),
                    'current' => (string) ($info['current'] ?? app_version()),
                    'remote' => (string) ($info['remote'] ?? ''),
                    'install_available' => false,
                    'error' => '',
                ]);
                return;
            }
            $this->json([
                'ok' => true,
                'available' => !empty($info['available']),
                'current' => (string) ($info['current'] ?? app_version()),
                'remote' => (string) ($info['remote'] ?? ''),
                'develop_version' => (string) ($info['develop_version'] ?? ''),
                'public_version' => (string) ($info['public_version'] ?? ''),
                'source' => (string) ($info['source'] ?? ''),
                'released_at' => (string) ($info['released_at'] ?? ''),
                'repository_url' => (string) ($info['repository_url'] ?? ''),
                'last_commit' => is_array($info['last_commit'] ?? null) ? $info['last_commit'] : null,
                'install_available' => !empty($info['install_available']),
                'notes_url' => (string) ($info['notes_url'] ?? ''),
                'download_url' => (string) ($info['download_url'] ?? ''),
                'install_guide_url' => (string) ($info['install_guide_url'] ?? ''),
                'error' => (string) ($info['error'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $isAdmin ? $e->getMessage() : ''], 500);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
