<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚══════╝ ╚══════╝   ╚═╝
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
use Socly\Services\ResourceBusyLock;

/**
 * While a heavy job holds ResourceBusyLock, other people get a full-page pause
 * so the instance stays responsive for the operator who started the work.
 */
final class ResourceBusyMiddleware
{
    public function __construct(private readonly ResourceBusyLock $lock)
    {
    }

    public function handle(Request $request): bool
    {
        $status = $this->lock->status();
        if ($status === null) {
            return true;
        }

        $path = $request->path();
        if ($this->isExemptPath($path)) {
            return true;
        }

        $user = auth_user();
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $sessionId = session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';
        if ($this->lock->isHolder($status, $userId > 0 ? $userId : null, $sessionId)) {
            return true;
        }

        $wantsJson = strtolower((string) ($request->header('X-Requested-With') ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($request->header('Accept') ?? '')), 'application/json');
        if ($wantsJson) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: 30');
            echo json_encode([
                'ok' => false,
                'error' => 'resource_busy',
                'user_name' => (string) ($status['user_name'] ?? ''),
                'operation' => (string) ($status['operation'] ?? ''),
                'message' => $this->message($status),
            ], JSON_UNESCAPED_UNICODE);

            return false;
        }

        $this->renderBusyPage($status);

        return false;
    }

    private function isExemptPath(string $path): bool
    {
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }
        $exact = [
            '/login',
            '/logout',
            '/forgot-password',
            '/reset-password',
            '/branding/logo',
            '/branding/favicon',
        ];
        if (in_array($path, $exact, true)) {
            return true;
        }
        // Install / recovery must stay reachable even during a heavy job.
        if ($path === '/install' || str_starts_with($path, '/install/')) {
            return true;
        }
        if (str_starts_with($path, '/password/')) {
            return true;
        }

        return false;
    }

    /** @param array<string,mixed> $status */
    private function message(array $status): string
    {
        $name = trim((string) ($status['user_name'] ?? ''));
        if ($name === '') {
            $name = __('resource_busy.someone');
        }
        $operation = (string) ($status['operation'] ?? '');
        $opLabel = trim((string) ($status['label'] ?? ''));
        if ($opLabel === '') {
            $opLabel = $this->lock->operationLabel($operation);
        }

        return __('resource_busy.message', [
            'name' => $name,
            'operation' => $opLabel,
        ]);
    }

    /** @param array<string,mixed> $status */
    private function renderBusyPage(array $status): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 30');
        header('Cache-Control: no-store');

        $locale = 'it';
        try {
            $locale = (string) app('translator')->getLocale();
        } catch (\Throwable) {
            $locale = 'it';
        }
        if (!in_array($locale, ['it', 'en', 'de'], true)) {
            $locale = 'it';
        }

        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = $esc(__('resource_busy.title'));
        $heading = $esc(__('resource_busy.heading'));
        $body = $esc($this->message($status));
        $hint = $esc(__('resource_busy.hint'));
        $retry = $esc(__('resource_busy.retry'));
        $rawName = trim((string) ($status['user_name'] ?? ''));
        if ($rawName === '') {
            $rawName = __('resource_busy.someone');
        }
        $who = $esc(__('resource_busy.who', ['name' => $rawName]));

        echo '<!DOCTYPE html><html lang="' . $esc($locale) . '"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta http-equiv="refresh" content="20">';
        echo '<title>' . $title . '</title>';
        echo '<style>';
        echo ':root{--ink:#142a28;--muted:#5a6f6c;--paper:#f3f7f5;--card:#fff;--line:#d7e3df;--brand:#0D6E66;--brand-deep:#0a524c;}';
        echo '*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:system-ui,sans-serif;color:var(--ink);';
        echo 'background:radial-gradient(900px 420px at 12% -10%,rgba(13,110,102,.16),transparent 60%),var(--paper);';
        echo 'display:flex;align-items:center;justify-content:center;padding:1.5rem}';
        echo '.card{width:min(34rem,100%);background:var(--card);border:1px solid var(--line);border-radius:22px;';
        echo 'padding:clamp(1.4rem,3vw,2rem);box-shadow:0 18px 40px rgba(20,42,40,.08)}';
        echo 'h1{font-size:clamp(1.35rem,3.2vw,1.75rem);line-height:1.2;margin:0 0 .85rem;letter-spacing:-.02em}';
        echo 'p{margin:0 0 1rem;line-height:1.55;color:var(--muted)}';
        echo '.who{margin:0 0 1rem;padding:.85rem 1rem;border-radius:14px;background:color-mix(in srgb,var(--brand) 7%,#fff);';
        echo 'border:1px solid color-mix(in srgb,var(--brand) 16%,var(--line));color:var(--ink);font-weight:600}';
        echo '.hint{font-size:.92rem;margin-bottom:1.25rem}';
        echo '.btn{appearance:none;border:0;border-radius:999px;padding:.72rem 1.15rem;font:inherit;font-weight:700;';
        echo 'cursor:pointer;background:var(--brand);color:#fff;text-decoration:none;display:inline-flex}';
        echo '.btn:hover{background:var(--brand-deep)}';
        echo '.pulse{display:inline-block;width:.65rem;height:.65rem;border-radius:50%;background:var(--brand);';
        echo 'margin-right:.45rem;animation:pulse 1.4s ease-in-out infinite;vertical-align:middle}';
        echo '@keyframes pulse{0%,100%{opacity:.35;transform:scale(.85)}50%{opacity:1;transform:scale(1)}}';
        echo '</style></head><body><div class="card">';
        echo '<h1><span class="pulse" aria-hidden="true"></span>' . $heading . '</h1>';
        echo '<p class="who">' . $who . '</p>';
        echo '<p>' . $body . '</p>';
        echo '<p class="hint">' . $hint . '</p>';
        echo '<p><button type="button" class="btn" onclick="location.reload()">' . $retry . '</button></p>';
        echo '<script>setTimeout(function(){location.reload()},20000);</script>';
        echo '</div></body></html>';
    }
}
