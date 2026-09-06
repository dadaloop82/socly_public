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

if (!function_exists('socly_render_error_page')) {
    /** @param array<string, mixed> $extra */
    function socly_render_error_page(Throwable $e, bool $verbose = false, array $extra = []): void
    {
        if (!headers_sent()) {
            http_response_code(500);
        }
        $ref = (string) ($extra['ref'] ?? socly_error_ref());
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

        if ($wantsJson) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            $payload = ['ok' => false, 'error' => 'Errore temporaneo. Riprova tra poco.', 'ref' => $ref];
            if ($verbose) {
                $payload['detail'] = $detail;
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Errore · SOCLY</title>';
        echo '<style>body{font-family:Manrope,system-ui,sans-serif;margin:0;background:#f4f7f6;color:#123;padding:2rem}'
            . '.box{max-width:44rem;margin:0 auto;background:#fff;border-radius:16px;padding:1.4rem 1.5rem;box-shadow:0 10px 30px rgba(0,0,0,.08)}'
            . 'h1{margin:0 0 .6rem;font-size:1.45rem}p{line-height:1.45}.muted{color:#567}'
            . 'pre{white-space:pre-wrap;word-break:break-word;background:#0b1f1c;color:#d7fff3;padding:1rem;border-radius:12px;font-size:.85rem;overflow:auto}'
            . 'code{background:#eef3f1;padding:.1rem .35rem;border-radius:6px}</style></head><body><div class="box">';
        echo '<h1>Errore temporaneo</h1>';
        echo '<p>Riprova tra poco o contatta il supporto SOCLY.</p>';
        echo '<p class="muted">Codice riferimento: <code>' . $esc($ref) . '</code></p>';

        if ($verbose) {
            echo '<h2 style="font-size:1.05rem;margin:1.2rem 0 .4rem">Dettagli tecnici</h2>';
            echo '<pre>' . $esc(
                $detail['type'] . "\n"
                . $detail['message'] . "\n"
                . 'file: ' . $detail['file'] . ':' . $detail['line'] . "\n"
                . 'request: ' . $detail['method'] . ' ' . $detail['path'] . "\n"
                . 'memory: ' . $detail['memory'] . "\n"
                . 'php: ' . $detail['php']
            ) . '</pre>';
            echo '<p class="muted">Copia questi dettagli in chat così individuiamo il bug.</p>';
        }

        echo '</div></body></html>';
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
            if (!headers_sent() || (string) ob_get_contents() === '') {
                if (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                socly_render_error_page($e, socly_should_show_error_details(), ['ref' => $ref]);
            }
        });
    }
}
