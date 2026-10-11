<?php

declare(strict_types=1);

/**
 * Render a safe (or verbose) HTML/JSON error page for uncaught failures.
 */

if (!function_exists('socly_should_show_error_details')) {
    function socly_should_show_error_details(): bool
    {
        try {
            if ((bool) config('app.debug', false)) {
                return true;
            }
        } catch (Throwable) {
        }
        if ((string) (getenv('SOCLY_SHOW_ERRORS') ?: '') === '1') {
            return true;
        }
        $appDebug = (string) (getenv('APP_DEBUG') ?: '');
        if ($appDebug === 'true' || $appDebug === '1') {
            return true;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        // Temporary evaluation demos always show diagnostics so we can fix crashes quickly.
        if (str_contains($uri, '/demo/')) {
            return true;
        }
        try {
            if (function_exists('is_temporary_instance') && is_temporary_instance()) {
                return true;
            }
        } catch (Throwable) {
        }
        return false;
    }
}

if (!function_exists('socly_error_ref')) {
    function socly_error_ref(): string
    {
        try {
            return strtoupper(bin2hex(random_bytes(4)));
        } catch (Throwable) {
            return strtoupper(dechex((int) (microtime(true) * 1000) & 0xffffff));
        }
    }
}

if (!function_exists('socly_bytes_label')) {
    function socly_bytes_label(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . 'KB';
        }
        return round($bytes / 1048576, 1) . 'MB';
    }
}

if (!function_exists('socly_memory_usage_label')) {
    function socly_memory_usage_label(): string
    {
        $used = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = (string) ini_get('memory_limit');
        return sprintf('used=%s peak=%s limit=%s', socly_bytes_label($used), socly_bytes_label($peak), $limit);
    }
}

if (!function_exists('socly_log_uncaught_error')) {
    /** @param array<string, mixed> $extra */
    function socly_log_uncaught_error(Throwable $e, string $ref, array $extra = []): void
    {
        $line = sprintf(
            "[%s] ERROR uncaught ref=%s %s in %s:%d path=%s mem=%s\n%s\n",
            date('c'),
            $ref,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            (string) ($_SERVER['REQUEST_URI'] ?? ''),
            socly_memory_usage_label(),
            $e->getTraceAsString()
        );
        $paths = [];
        try {
            $paths[] = storage_path('logs/app.log');
        } catch (Throwable) {
        }
        if (defined('SOCLY_INSTANCE_PATH')) {
            $paths[] = rtrim((string) SOCLY_INSTANCE_PATH, '/') . '/storage/logs/app.log';
        }
        foreach (array_unique($paths) as $path) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents(
                $path,
                $line . ($extra === [] ? '' : json_encode($extra, JSON_UNESCAPED_UNICODE) . "\n"),
                FILE_APPEND | LOCK_EX
            );
        }
        try {
            if (function_exists('app')) {
                app('logger')->error('uncaught', [
                    'ref' => $ref,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                    'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
                    'memory' => socly_memory_usage_label(),
                ] + $extra);
            }
        } catch (Throwable) {
        }
    }
}

if (!function_exists('socly_auto_report_crash')) {
    /**
     * Best-effort GitHub issue for uncaught crashes. Never throws.
     *
     * @return array{ok:bool,issue_url?:string,skipped?:bool,error?:string}
     */
    function socly_auto_report_crash(Throwable $e, string $ref = ''): array
    {
        try {
            if (!function_exists('app')) {
                return ['ok' => false, 'skipped' => true, 'error' => 'no_app'];
            }
            /** @var \Socly\Services\GitHubIssueService $svc */
            $svc = app(\Socly\Services\GitHubIssueService::class);
            return $svc->reportCrash($e, $ref);
        } catch (Throwable $inner) {
            try {
                if (function_exists('app')) {
                    app('logger')->error('github_issue.auto_report_failed', [
                        'error' => $inner->getMessage(),
                    ]);
                }
            } catch (Throwable) {
            }
            return ['ok' => false, 'error' => 'exception'];
        }
    }
}

if (!function_exists('socly_msg')) {
    /**
     * Translate UI copy; falls back to lang files when __() is unavailable.
     *
     * @param array<string, string|int|float> $replace
     */
    function socly_msg(string $key, array $replace = []): string
    {
        try {
            if (function_exists('__')) {
                $out = (string) __($key, $replace);
                if ($out !== $key && $out !== '') {
                    return $out;
                }
            }
        } catch (Throwable) {
        }

        $locale = 'it';
        try {
            if (function_exists('app')) {
                $locale = (string) app('translator')->getLocale();
            }
        } catch (Throwable) {
            $locale = (string) ($_SESSION['locale'] ?? getenv('APP_LOCALE') ?: 'it');
        }
        if (!in_array($locale, ['it', 'en', 'de'], true)) {
            $locale = 'it';
        }

        $path = null;
        try {
            if (function_exists('code_path')) {
                $path = code_path('lang/' . $locale . '/messages.php');
            }
        } catch (Throwable) {
        }
        if ($path === null || !is_file($path)) {
            $base = defined('SOCLY_CODE_PATH') ? (string) SOCLY_CODE_PATH : dirname(__DIR__, 2);
            $path = $base . '/lang/' . $locale . '/messages.php';
        }
        if (!is_file($path)) {
            return $key;
        }

        /** @var array<string, mixed> $messages */
        $messages = require $path;
        $node = $messages;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $key;
            }
            $node = $node[$part];
        }
        if (!is_string($node) || $node === '') {
            return $key;
        }
        $out = $node;
        foreach ($replace as $k => $v) {
            $out = str_replace(':' . $k, (string) $v, $out);
        }
        return $out;
    }
}

if (!function_exists('socly_error_support_email')) {
    function socly_error_support_email(): string
    {
        $email = trim(socly_msg('errors.500_support_email'));
        if ($email === '' || $email === 'errors.500_support_email' || !str_contains($email, '@')) {
            return 'info@socly.it';
        }
        return $email;
    }
}

if (!function_exists('socly_error_logo_src')) {
    function socly_error_logo_src(): string
    {
        try {
            if (function_exists('socly_mark_url')) {
                return socly_mark_url('dark');
            }
        } catch (Throwable) {
        }
        try {
            if (function_exists('asset')) {
                return asset('img/socly-mark.png');
            }
        } catch (Throwable) {
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if (str_ends_with($base, '/public')) {
            return $base . '/assets/img/socly-mark.png';
        }
        return '/assets/img/socly-mark.png';
    }
}

if (!function_exists('socly_error_home_url')) {
    function socly_error_home_url(): string
    {
        try {
            if (function_exists('url')) {
                return url('/');
            }
        } catch (Throwable) {
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return ($base === '' ? '/' : $base . '/');
    }
}

if (!function_exists('socly_render_error_page')) {
    /** @param array<string, mixed> $extra */
    function socly_render_error_page(Throwable $e, bool $verbose = false, array $extra = []): void
    {
        if (!headers_sent()) {
            http_response_code(500);
        }
        $ref = (string) ($extra['ref'] ?? socly_error_ref());
        $report = $extra['report'] ?? null;
        if (!is_array($report)) {
            $report = socly_auto_report_crash($e, $ref);
        }
        $wantsJson = (
            str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0
        );

        $detail = [
            'ref' => $ref,
            'type' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'memory' => socly_memory_usage_label(),
            'php' => PHP_VERSION,
        ];

        $short = socly_msg('errors.500_short');
        $supportEmail = socly_error_support_email();

        if ($wantsJson) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            $payload = [
                'ok' => false,
                'error' => $short,
                'ref' => $ref,
                'support_email' => $supportEmail,
            ];
            if ($verbose) {
                $payload['detail'] = $detail;
            }
            if (!empty($report['ok'])) {
                $payload['reported'] = true;
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $reportNote = '';
        if (!empty($report['ok'])) {
            $reportNote = socly_msg('report.auto_sent');
        } elseif (($report['error'] ?? '') === 'not_configured' || !empty($report['skipped'])) {
            $reportNote = '';
        } else {
            $reportNote = socly_msg('report.auto_failed', ['email' => $supportEmail]);
        }

        $locale = 'it';
        try {
            if (function_exists('app')) {
                $locale = (string) app('translator')->getLocale();
            }
        } catch (Throwable) {
            $locale = (string) ($_SESSION['locale'] ?? getenv('APP_LOCALE') ?: 'it');
        }
        if (!in_array($locale, ['it', 'en', 'de'], true)) {
            $locale = 'it';
        }

        $logo = socly_error_logo_src();
        $home = socly_error_home_url();
        $mailHref = 'mailto:' . $supportEmail . '?subject=' . rawurlencode('SOCLY ' . $ref);

        echo '<!DOCTYPE html><html lang="' . $esc($locale) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $esc(socly_msg('errors.500_page_title')) . '</title>'
            . '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">'
            . '<style>'
            . ':root{--ink:#142a28;--muted:#5a6f6c;--paper:#f3f7f5;--card:#fff;--line:#d7e3df;--brand:#0D6E66;--brand-deep:#0a524c;--accent:#B84A1B;}'
            . '*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Manrope,system-ui,sans-serif;color:var(--ink);'
            . 'background:radial-gradient(900px 420px at 12% -10%,rgba(13,110,102,.16),transparent 60%),'
            . 'radial-gradient(700px 360px at 100% 0%,rgba(184,74,27,.10),transparent 55%),var(--paper);'
            . 'display:flex;align-items:center;justify-content:center;padding:1.5rem}'
            . '.shell{width:min(34rem,100%)}.mark{display:block;height:2.75rem;width:auto;margin:0 0 1.35rem}'
            . '.card{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:clamp(1.4rem,3vw,2rem);'
            . 'box-shadow:0 18px 40px rgba(20,42,40,.08)}.card h1{font-family:Fraunces,Georgia,serif;font-size:clamp(1.45rem,3.5vw,1.85rem);'
            . 'line-height:1.2;letter-spacing:-.02em;margin:0 0 .75rem}.lede{margin:0 0 1.15rem;color:var(--muted);line-height:1.55;font-size:1.02rem}'
            . '.block{margin:0 0 1.15rem;padding:0.95rem 1rem;border-radius:14px;background:color-mix(in srgb,var(--brand) 6%,#fff);border:1px solid color-mix(in srgb,var(--brand) 14%,var(--line))}'
            . '.block h2{margin:0 0 .35rem;font-size:.92rem;letter-spacing:.02em;text-transform:uppercase;color:var(--brand-deep)}.block p{margin:0;line-height:1.5;color:var(--ink)}'
            . '.block a{color:var(--brand-deep);font-weight:700;text-decoration:underline;text-underline-offset:2px}'
            . '.note{margin:0 0 1rem;color:var(--muted);font-size:.92rem;line-height:1.45}'
            . '.ref{margin:0 0 .35rem;font-size:.9rem}.ref code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#eef4f2;padding:.15rem .45rem;border-radius:8px;font-size:.88rem}'
            . '.hint{margin:0 0 1.25rem;color:var(--muted);font-size:.82rem;line-height:1.4}'
            . '.actions{display:flex;flex-wrap:wrap;gap:.65rem}.btn{appearance:none;border:0;border-radius:999px;padding:.72rem 1.15rem;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}'
            . '.btn-primary{background:var(--brand);color:#fff}.btn-primary:hover{background:var(--brand-deep)}'
            . '.btn-ghost{background:transparent;color:var(--ink);border:1px solid var(--line)}.btn-ghost:hover{border-color:color-mix(in srgb,var(--ink) 22%,var(--line))}'
            . 'details{margin-top:1.25rem}summary{cursor:pointer;color:var(--muted);font-size:.9rem}'
            . 'pre{white-space:pre-wrap;word-break:break-word;background:#10221f;color:#d7fff3;padding:1rem;border-radius:12px;font-size:.82rem;overflow:auto;margin:.75rem 0 0}'
            . '</style></head><body><div class="shell">'
            . '<img class="mark" src="' . $esc($logo) . '" alt="SOCLY" decoding="async">'
            . '<div class="card">'
            . '<h1>' . $esc(socly_msg('errors.500_title')) . '</h1>'
            . '<p class="lede">' . $esc(socly_msg('errors.500_text')) . '</p>'
            . '<div class="block"><h2>' . $esc(socly_msg('errors.500_what_title')) . '</h2>'
            . '<p>' . $esc(socly_msg('errors.500_what_text')) . '</p></div>'
            . '<div class="block"><h2>' . $esc(socly_msg('errors.500_contact_title')) . '</h2><p>';
        $contactParts = explode('{{EMAIL}}', socly_msg('errors.500_contact_text', ['email' => '{{EMAIL}}']), 2);
        echo $esc((string) ($contactParts[0] ?? ''))
            . '<a href="' . $esc($mailHref) . '">' . $esc($supportEmail) . '</a>'
            . $esc((string) ($contactParts[1] ?? ''))
            . '</p></div>';

        if ($reportNote !== '') {
            echo '<p class="note">' . $esc($reportNote) . '</p>';
        }

        echo '<p class="ref">' . $esc(socly_msg('errors.500_ref')) . ' <code>' . $esc($ref) . '</code></p>'
            . '<p class="hint">' . $esc(socly_msg('errors.500_ref_hint')) . '</p>'
            . '<div class="actions">'
            . '<a class="btn btn-primary" href="' . $esc($home) . '">' . $esc(socly_msg('errors.500_home')) . '</a>'
            . '<a class="btn btn-ghost" href="javascript:location.reload()">' . $esc(socly_msg('errors.500_retry')) . '</a>'
            . '<a class="btn btn-ghost" href="' . $esc($mailHref) . '">' . $esc($supportEmail) . '</a>'
            . '</div>';

        if ($verbose) {
            echo '<details><summary>' . $esc(socly_msg('errors.500_tech_title')) . '</summary>'
                . '<pre>' . $esc(
                    $detail['type'] . "\n"
                    . $detail['message'] . "\n"
                    . 'file: ' . $detail['file'] . ':' . $detail['line'] . "\n"
                    . 'request: ' . $detail['method'] . ' ' . $detail['path'] . "\n"
                    . 'memory: ' . $detail['memory'] . "\n"
                    . 'php: ' . $detail['php']
                ) . '</pre>'
                . '<p class="hint">' . $esc(socly_msg('errors.500_copy_hint')) . '</p></details>';
        }

        echo '</div></div></body></html>';
    }
}

if (!function_exists('socly_register_fatal_error_page')) {
    function socly_register_fatal_error_page(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err === null) {
                return;
            }
            $type = (int) ($err['type'] ?? 0);
            if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            $message = (string) ($err['message'] ?? 'Fatal error');
            $file = (string) ($err['file'] ?? '');
            $line = (int) ($err['line'] ?? 0);
            $e = new ErrorException($message, 0, $type, $file, $line);
            $ref = socly_error_ref();
            socly_log_uncaught_error($e, $ref, ['fatal' => true]);
            $report = socly_auto_report_crash($e, $ref);
            if (!headers_sent() || (string) ob_get_contents() === '') {
                if (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                socly_render_error_page($e, socly_should_show_error_details(), [
                    'ref' => $ref,
                    'report' => $report,
                ]);
            }
        });
    }
}
