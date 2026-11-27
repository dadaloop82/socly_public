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
            // Keep presence dots and idle heartbeat alive for everyone during a heavy job.
            '/presence/state',
            '/session/ping',
            '/resource-busy/status',
            // The pause mini-game must be able to read and save its scoreboard while the
            // instance is busy (the request is tiny and never touches association data).
            '/pause-game/score',
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
    private function operationLabel(array $status): string
    {
        $opLabel = trim((string) ($status['label'] ?? ''));
        if ($opLabel !== '') {
            return $opLabel;
        }

        return $this->lock->operationLabel((string) ($status['operation'] ?? ''));
    }

    /** @param array<string,mixed> $status */
    private function message(array $status): string
    {
        $name = trim((string) ($status['user_name'] ?? ''));
        if ($name === '') {
            $name = __('resource_busy.someone');
        }

        return __('resource_busy.api_message', [
            'name' => $name,
            'operation' => $this->operationLabel($status),
        ]);
    }

    /** @param array<string,mixed> $status */
    private function startedByLine(array $status): string
    {
        $rawName = trim((string) ($status['user_name'] ?? ''));
        if ($rawName === '') {
            $rawName = __('resource_busy.someone');
        }
        $startedTime = '—';
        $startedAt = (string) ($status['started_at'] ?? '');
        if ($startedAt !== '') {
            try {
                $startedTime = (new \DateTimeImmutable($startedAt))->format('H:i');
            } catch (\Throwable) {
                $startedTime = '—';
            }
        }

        return __('resource_busy.started_by', [
            'name' => $rawName,
            'time' => $startedTime,
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
        $csrf = '';
        try {
            $csrf = function_exists('csrf_token') ? (string) csrf_token() : '';
        } catch (\Throwable) {
            // The busy page must render even when the session is not available.
        }
        $title = $esc(__('resource_busy.title'));
        $kicker = $esc(__('resource_busy.pause_kicker'));
        $heading = $esc(__('resource_busy.heading'));
        $operation = $esc($this->operationLabel($status));
        $startedBy = $esc($this->startedByLine($status));
        $body = $esc(__('resource_busy.message'));
        $eta = $esc($this->lock->etaLabel($status));
        $pauseText = $esc(__('resource_busy.pause_text'));
        $hint = $esc(__('resource_busy.hint'));
        $retry = $esc(__('resource_busy.retry'));
        $gameLabels = [
            'data-game-url' => $esc(url('/pause-game/score')),
            'data-game-title' => $esc(__('resource_busy.game_title')),
            'data-game-hint' => $esc(__('resource_busy.game_hint')),
            'data-game-score-label' => $esc(__('resource_busy.game_score_label')),
            'data-game-record-label' => $esc(__('resource_busy.game_record_label')),
            'data-game-record-none' => $esc(__('resource_busy.game_record_none')),
            'data-game-record-you' => $esc(__('resource_busy.game_record_you')),
            'data-game-personal-label' => $esc(__('resource_busy.game_personal_label')),
            'data-game-record-new' => $esc(__('resource_busy.game_record_new')),
            'data-game-players' => $esc(__('resource_busy.game_players', ['count' => ':count'])),
            'data-game-aria' => $esc(__('resource_busy.game_aria')),
            'data-game-bubble' => $esc(__('resource_busy.game_bubble')),
        ];
        $gameAttrs = '';
        foreach ($gameLabels as $name => $value) {
            $gameAttrs .= ' ' . $name . '="' . $value . '"';
        }

        echo '<!DOCTYPE html><html lang="' . $esc($locale) . '"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta name="csrf-token" content="' . $esc($csrf) . '">';
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560;9..144,700&family=Manrope:wght@400;600;700;800&display=swap">';
        echo '<title>' . $title . '</title>';
        echo '<style>' . $this->busyPageStyles($this->brandColor()) . '</style></head><body>';
        echo '<div class="pause-scene" aria-hidden="true"><span class="pause-grid"></span>';
        echo '<span class="pause-orb pause-orb--1"></span><span class="pause-orb pause-orb--2"></span>';
        echo '<span class="pause-orb pause-orb--3"></span><span class="pause-orb pause-orb--4"></span></div>';
        echo '<main class="pause-card" role="status" aria-live="polite">';
        echo '<p class="pause-kicker">' . $kicker . '</p>';
        echo '<div class="pause-pulse" aria-hidden="true"><span></span><span></span><span></span></div>';
        echo '<h1 class="pause-title">' . $heading . '</h1>';
        echo '<p class="pause-operation">' . $operation . '</p>';
        echo '<p class="pause-started">' . $startedBy . '</p>';
        echo '<p class="pause-message">' . $body . '</p>';
        echo '<p class="pause-eta" data-busy-eta>' . $eta . '</p>';
        echo '<p class="pause-wait">' . $pauseText . '</p>';
        echo '<p class="pause-hint">' . $hint . '</p>';
        echo '<div class="pause-game" data-pause-game' . $gameAttrs . '></div>';
        echo '<div class="pause-actions"><button type="button" class="btn" data-busy-retry>' . $retry . '</button></div>';
        echo '</main>';
        echo '<script src="' . $esc(asset('js/pause-game.js') . '?v=' . app_version()) . '"></script>';
        echo '<script>' . $this->busyPageScript(url('/resource-busy/status')) . '</script>';
        echo '</body></html>';
    }

    /** Brand primary from settings, with a safe fallback. */
    private function brandColor(): string
    {
        try {
            $value = strtoupper(trim((string) app(\Socly\Services\SettingsService::class)->get('branding.primary', '')));
            if (preg_match('/^#[0-9A-F]{6}$/', $value)) {
                return $value;
            }
        } catch (\Throwable) {
            // Not installed / settings unavailable: keep the Socly default.
        }

        return '#0D6E66';
    }

    /** Standalone styles: the busy page cannot rely on app.css (it may be blocked). */
    private function busyPageStyles(string $brand): string
    {
        return $this->busyPageStylesBase($brand) . $this->busyPageStylesGame($brand);
    }

    private function busyPageStylesBase(string $brand): string
    {
        return ':root{--ink:#142a28;--muted:#5d6f6c;--paper:#f4f8f6;--line:#d7e3df;--brand:' . $brand . '}'
            . '*{box-sizing:border-box}'
            . 'html,body{min-height:100%}'
            . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.25rem;'
            . 'font-family:Manrope,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);overflow:hidden;'
            . 'background:radial-gradient(1100px 620px at 8% -10%,color-mix(in srgb,var(--brand) 42%,transparent),transparent 65%),'
            . 'radial-gradient(900px 520px at 92% 108%,color-mix(in srgb,var(--brand) 30%,#e7c46a),transparent 62%),'
            . 'linear-gradient(152deg,#08211f 0%,#123b37 54%,#071c1a 100%)}'
            . '.pause-scene{position:fixed;inset:0;overflow:hidden;pointer-events:none}'
            . '.pause-grid{position:absolute;inset:-20%;opacity:.5;transform:rotate(-8deg);'
            . 'background-image:linear-gradient(rgba(255,255,255,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.06) 1px,transparent 1px);'
            . 'background-size:46px 46px;-webkit-mask-image:radial-gradient(circle at 50% 45%,#000 12%,transparent 72%);mask-image:radial-gradient(circle at 50% 45%,#000 12%,transparent 72%)}'
            . '.pause-orb{position:absolute;border-radius:50%;filter:blur(18px);opacity:.55;animation:drift 22s ease-in-out infinite alternate}'
            . '.pause-orb--1{width:22rem;height:22rem;left:-6rem;top:-6rem;background:color-mix(in srgb,var(--brand) 72%,#fff 8%);animation-duration:26s}'
            . '.pause-orb--2{width:16rem;height:16rem;right:-4rem;top:12%;background:color-mix(in srgb,#e7c46a 52%,var(--brand));animation-duration:19s;animation-delay:-6s}'
            . '.pause-orb--3{width:20rem;height:20rem;right:8%;bottom:-8rem;background:color-mix(in srgb,#2f9e8f 58%,#fff 4%);animation-duration:30s;animation-delay:-12s}'
            . '.pause-orb--4{width:12rem;height:12rem;left:12%;bottom:-3rem;background:color-mix(in srgb,#e7c46a 30%,transparent);animation-duration:17s;animation-delay:-3s}'
            . '@keyframes drift{from{transform:translate3d(0,0,0) scale(1)}to{transform:translate3d(2.5rem,-2rem,0) scale(1.12)}}'
            . '.pause-card{position:relative;z-index:1;width:min(31rem,94vw);max-height:min(92vh,52rem);overflow-y:auto;'
            . 'padding:1.5rem 1.6rem 1.35rem;border-radius:20px;text-align:left;background:linear-gradient(180deg,#fff 0%,#f3f8f6 100%);'
            . 'border:1px solid color-mix(in srgb,var(--brand) 14%,#fff);box-shadow:0 30px 80px rgba(4,18,15,.55),inset 0 2px 0 #fff;'
            . 'animation:card-in .35s ease}'
            . '@keyframes card-in{from{opacity:0;transform:translateY(12px) scale(.985)}to{opacity:1;transform:none}}'
            . '.pause-kicker{margin:0 0 .55rem;font-size:.72rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--brand)}'
            . '.pause-pulse{display:inline-flex;gap:.32rem;margin:0 0 .65rem}'
            . '.pause-pulse span{width:.5rem;height:.5rem;border-radius:50%;background:var(--brand);animation:bounce 1.15s ease-in-out infinite}'
            . '.pause-pulse span:nth-child(2){animation-delay:.14s}.pause-pulse span:nth-child(3){animation-delay:.28s}'
            . '@keyframes bounce{0%,100%{opacity:.32;transform:translateY(0) scale(.85)}50%{opacity:1;transform:translateY(-3px) scale(1)}}'
            . '.pause-title{margin:0 0 .6rem;font-family:"Fraunces",Georgia,serif;font-size:1.5rem;line-height:1.15;letter-spacing:-.02em}'
            . '.pause-operation{margin:0 0 .4rem;padding:.7rem .85rem;border-radius:12px;font-weight:700;font-size:1.02rem;'
            . 'color:var(--ink);background:color-mix(in srgb,var(--brand) 7%,#fff);border:1px solid color-mix(in srgb,var(--brand) 18%,var(--line))}'
            . '.pause-started{margin:0 0 .7rem;font-size:.85rem;font-weight:600;color:#4c5f5c}'
            . '.pause-message{margin:0 0 .5rem;font-size:.95rem;line-height:1.5;color:var(--ink)}'
            . '.pause-eta{margin:0 0 .55rem;padding:.5rem .75rem;border-radius:12px;font-size:.92rem;font-weight:700;'
            . 'color:#0a4f6a;background:color-mix(in srgb,var(--brand) 8%,#fff);border:1px solid color-mix(in srgb,var(--brand) 20%,#fff)}'
            . '.pause-wait{margin:0 0 .55rem;font-size:.95rem;line-height:1.45;color:var(--ink)}'
            . '.pause-hint{margin:0 0 .85rem;font-size:.82rem;line-height:1.45;color:#5d6f6c}'
            . '.pause-actions{display:flex;justify-content:flex-end}'
            . '.btn{appearance:none;border:0;border-radius:999px;padding:.72rem 1.15rem;font:inherit;font-weight:700;cursor:pointer;'
            . 'background:var(--brand);color:#fff;text-decoration:none;display:inline-flex}'
            . '.btn:hover{filter:brightness(.92)}'
            . '@media(max-width:520px){.pause-card{padding:1.2rem 1.1rem 1.1rem;max-height:94vh}}';
    }

    private function busyPageStylesGame(string $brand): string
    {
        return '.pause-game{margin:0 0 1rem;padding:.75rem .8rem .85rem;border-radius:16px;'
            . 'border:1px solid color-mix(in srgb,var(--brand) 16%,var(--line));background:color-mix(in srgb,var(--brand) 4%,#fff)}'
            . '.pause-game-head{display:flex;align-items:baseline;justify-content:space-between;gap:.75rem;margin-bottom:.2rem}'
            . '.pause-game-head strong{font-family:"Fraunces",Georgia,serif;font-size:1rem}'
            . '.pause-hud{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.45rem;margin:0 0 .55rem}'
            . '.pause-stat{display:flex;flex-direction:column;gap:.1rem;padding:.45rem .55rem;border-radius:12px;'
            . 'border:1px solid color-mix(in srgb,var(--brand) 14%,#fff);background:#fff}'
            . '.pause-stat-label{font-size:.68rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#6b7c79}'
            . '.pause-stat-value{font-family:"Fraunces",Georgia,serif;font-size:1.15rem;line-height:1.1;color:var(--ink);'
            . 'font-variant-numeric:tabular-nums}'
            . '.pause-stat-meta{font-size:.68rem;color:#6b7c79;line-height:1.25}'
            . '.pause-stat--record .pause-stat-value{color:var(--brand)}'
            . '.pause-hud.is-record{animation:pulseRecord 1.2s ease-in-out 2}'
            . '@keyframes pulseRecord{0%,100%{filter:none}50%{filter:drop-shadow(0 0 10px color-mix(in srgb,#e7c46a 70%,transparent))}}'
            . '.pause-game-hint{margin:0 0 .55rem;font-size:.76rem;color:#6b7c79}'
            . '.pause-playfield{position:relative;height:9.5rem;overflow:hidden;border-radius:12px;'
            . 'background:radial-gradient(120px 80px at 18% 22%,color-mix(in srgb,var(--brand) 12%,transparent),transparent 70%),'
            . 'radial-gradient(140px 90px at 82% 78%,color-mix(in srgb,#e7c46a 16%,transparent),transparent 72%),linear-gradient(180deg,#f8fcfb,#eef6f4);'
            . 'border:1px solid color-mix(in srgb,var(--brand) 10%,#fff)}'
            . '.pause-bubble{position:absolute;width:var(--bubble-size,1rem);height:var(--bubble-size,1rem);padding:0;border-radius:50%;cursor:pointer;'
            . 'border:1px solid color-mix(in srgb,var(--brand) 30%,#fff);'
            . 'background:radial-gradient(circle at 32% 28%,#fff 0 18%,color-mix(in srgb,var(--brand) 22%,#fff) 55%,color-mix(in srgb,var(--brand) 38%,#fff) 100%);'
            . 'box-shadow:0 4px 12px color-mix(in srgb,var(--brand) 20%,transparent),inset 0 -3px 6px rgba(255,255,255,.55);'
            . 'animation:float 4s ease-in-out infinite alternate;transition:transform .16s ease,opacity .16s ease}'
            . '.pause-bubble:hover,.pause-bubble:focus-visible{transform:scale(1.12);outline:2px solid color-mix(in srgb,var(--brand) 55%,#fff);outline-offset:2px}'
            . '.pause-bubble.is-popped{transform:scale(.25);opacity:0;pointer-events:none}'
            . '@keyframes float{from{transform:translate3d(0,0,0)}to{transform:translate3d(0,-7px,0)}}'
            . '.pause-record-flash{margin:.4rem 0 0;padding:.45rem .6rem;border-radius:10px;font-size:.82rem;font-weight:700;'
            . 'color:#0a5a4a;background:color-mix(in srgb,#2f9e8f 14%,#fff);border:1px solid color-mix(in srgb,#2f9e8f 30%,#fff)}'
            . '.pause-record-flash[hidden]{display:none!important}'
            . '@media(max-width:520px){.pause-playfield{height:8rem}.pause-hud{grid-template-columns:repeat(2,minmax(0,1fr))}.pause-stat--personal{grid-column:span 2}}'
            . '@media(prefers-reduced-motion:reduce){.pause-orb,.pause-bubble,.pause-pulse span,.pause-card{animation:none!important}.pause-bubble{transition:none}}';
    }

    /** Polls the exempt status endpoint and reloads when the lock is released. */
    private function busyPageScript(string $statusUrl): string
    {
        return '(function(){'
            . 'var url=' . json_encode($statusUrl, JSON_UNESCAPED_SLASHES) . ';'
            . 'var retry=document.querySelector("[data-busy-retry]");'
            . 'if(retry){retry.addEventListener("click",function(){location.reload();});}'
            . 'if(window.SoclyPauseGame){window.SoclyPauseGame.init(document);}'
            . 'var eta=document.querySelector("[data-busy-eta]");'
            . 'function check(){fetch(url,{credentials:"same-origin",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest"},cache:"no-store"})'
            . '.then(function(r){return r.ok?r.json():null;})'
            . '.then(function(d){if(d&&typeof d.eta_label==="string"&&d.eta_label!==""){document.querySelectorAll("[data-busy-eta]").forEach(function(el){el.textContent=d.eta_label;});}if(d&&d.busy===false){location.reload();}})'
            . '.catch(function(){});}'
            . 'window.setTimeout(check,1500);window.setInterval(check,5000);'
            . '})();';
    }
}
