<?php

declare(strict_types=1);

namespace Socly\Services;

/**
 * Bug reports / crash issues via the socly.it platform relay.
 * Installations never hold a GitHub PAT — only the marketing host does.
 */
final class GitHubIssueService
{
    private const MANUAL_MAX_PER_HOUR = 5;
    private const CRASH_DEDUP_SECONDS = 900;

    public function __construct(
        private readonly RateLimiter $limiter
    ) {
    }

    public function isConfigured(): bool
    {
        return function_exists('socly_platform_api_url') && socly_platform_api_url() !== '';
    }

    /**
     * Manual report from the footer dialog.
     *
     * @param array<string, mixed> $clientContext
     * @return array{ok:bool,issue_url?:string,error?:string,code?:string}
     */
    public function reportProblem(string $description, array $clientContext = []): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'not_configured', 'code' => 'not_configured'];
        }

        $description = trim($description);
        if ($description === '') {
            return ['ok' => false, 'error' => 'description_required', 'code' => 'description_required'];
        }
        if (mb_strlen($description) > 4000) {
            $description = mb_substr($description, 0, 4000) . '…';
        }

        $ip = $this->clientIp();
        $limitKey = 'report-problem:' . $ip;
        if ($this->limiter->tooManyAttempts($limitKey, self::MANUAL_MAX_PER_HOUR, 3600)) {
            return ['ok' => false, 'error' => 'rate_limited', 'code' => 'rate_limited'];
        }

        $ctx = $this->collectContext($clientContext);
        $title = '[user-report] ' . $this->titleFromDescription($description);
        $body = $this->buildBody([
            '## Descrizione utente',
            $description,
            '',
            '## Contesto',
            $this->contextMarkdown($ctx),
        ]);

        $created = $this->relay([
            'kind' => 'user-report',
            'title' => $title,
            'body' => $body,
        ]);
        if (!$created['ok']) {
            return $created;
        }
        $this->limiter->hit($limitKey, 3600);
        return $created;
    }

    /**
     * Automatic crash report. Never throws.
     *
     * @return array{ok:bool,issue_url?:string,skipped?:bool,error?:string,code?:string}
     */
    public function reportCrash(\Throwable $e, string $ref = ''): array
    {
        try {
            if (!$this->isConfigured()) {
                return ['ok' => false, 'skipped' => true, 'error' => 'not_configured', 'code' => 'not_configured'];
            }

            $sig = hash('sha256', $e::class . '|' . $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine());
            $dedupKey = 'report-crash:' . $sig;
            if ($this->limiter->tooManyAttempts($dedupKey, 1, self::CRASH_DEDUP_SECONDS)) {
                return ['ok' => false, 'skipped' => true, 'error' => 'dedup', 'code' => 'dedup'];
            }

            $ip = $this->clientIp();
            $ipKey = 'report-crash-ip:' . $ip;
            if ($this->limiter->tooManyAttempts($ipKey, self::MANUAL_MAX_PER_HOUR, 3600)) {
                return ['ok' => false, 'skipped' => true, 'error' => 'rate_limited', 'code' => 'rate_limited'];
            }

            if ($ref === '') {
                $ref = function_exists('socly_error_ref') ? socly_error_ref() : strtoupper(dechex(time() & 0xffffff));
            }

            $ctx = $this->collectContext([
                'ref' => $ref,
                'error_type' => $e::class,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            $shortMsg = mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage(), 0, 80);
            $title = sprintf('[auto-crash] %s: %s (%s)', $this->shortClass($e::class), $shortMsg, $ref);
            $body = $this->buildBody([
                '## Crash automatico',
                '- **Ref:** `' . $ref . '`',
                '- **Tipo:** `' . $e::class . '`',
                '- **Messaggio:** ' . $e->getMessage(),
                '- **File:** `' . $e->getFile() . ':' . $e->getLine() . '`',
                '',
                '## Contesto',
                $this->contextMarkdown($ctx),
                '',
                '## Traceback',
                '```',
                $this->truncate($e->getTraceAsString(), 12000),
                '```',
            ]);

            $created = $this->relay([
                'kind' => 'auto-crash',
                'title' => $title,
                'body' => $body,
                'dedup' => $sig,
            ]);
            if ($created['ok']) {
                $this->limiter->hit($dedupKey, self::CRASH_DEDUP_SECONDS);
                $this->limiter->hit($ipKey, 3600);
            }
            return $created;
        } catch (\Throwable $inner) {
            try {
                if (function_exists('app')) {
                    app('logger')->error('github_issue.crash_report_failed', [
                        'error' => $inner->getMessage(),
                    ]);
                }
            } catch (\Throwable) {
            }
            return ['ok' => false, 'error' => 'exception', 'code' => 'exception'];
        }
    }

    /**
     * @param array{kind:string,title:string,body:string,dedup?:string} $payload
     * @return array{ok:bool,issue_url?:string,error?:string,code?:string}
     */
    private function relay(array $payload): array
    {
        $url = socly_platform_api_url();
        if ($url === '') {
            return ['ok' => false, 'error' => 'not_configured', 'code' => 'not_configured'];
        }

        $body = [
            'action' => 'report_problem',
            'token' => $this->instanceTokenSoft(),
            'kind' => $payload['kind'],
            'title' => $payload['title'],
            'body' => $payload['body'],
        ];
        if (!empty($payload['dedup'])) {
            $body['dedup'] = $payload['dedup'];
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return ['ok' => false, 'error' => 'encode_failed', 'code' => 'encode_failed'];
        }

        $response = null;
        $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'error' => 'relay_failed', 'code' => 'relay_failed'];
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => 'SoclyIssueRelay/1.0',
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                    'content' => $json,
                    'timeout' => 15,
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($url, false, $ctx);
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $line) {
                    if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                        $status = (int) $m[1];
                        break;
                    }
                }
            }
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($status >= 200 && $status < 300 && is_array($decoded) && !empty($decoded['ok'])) {
            return [
                'ok' => true,
                'issue_url' => (string) ($decoded['issue_url'] ?? ''),
            ];
        }

        $code = is_array($decoded) ? (string) ($decoded['error'] ?? 'relay_failed') : 'relay_failed';
        try {
            if (function_exists('app')) {
                app('logger')->error('github_issue.relay_failed', [
                    'status' => $status,
                    'code' => $code,
                ]);
            }
        } catch (\Throwable) {
        }

        return ['ok' => false, 'error' => $code, 'code' => $code];
    }

    private function instanceTokenSoft(): string
    {
        try {
            if (function_exists('app')) {
                /** @var PlatformService $platform */
                $platform = app(PlatformService::class);
                return $platform->instanceToken();
            }
        } catch (\Throwable) {
        }
        return 'anon-' . substr(hash('sha256', $this->clientIp() . '|' . date('Y-m-d')), 0, 24);
    }

    /** @param array<string, mixed> $clientContext */
    private function collectContext(array $clientContext = []): array
    {
        $version = '';
        try {
            $version = function_exists('app_version') ? app_version() : (string) config('app.version', '');
        } catch (\Throwable) {
        }

        $demoId = '';
        $urlPrefix = (string) (getenv('SOCLY_URL_PREFIX') ?: ($_ENV['SOCLY_URL_PREFIX'] ?? ''));
        if ($urlPrefix !== '' && preg_match('#/demo/([a-f0-9]+)#i', $urlPrefix, $m)) {
            $demoId = $m[1];
        } elseif (defined('SOCLY_INSTANCE_PATH')) {
            $base = basename((string) SOCLY_INSTANCE_PATH);
            if (preg_match('/^[a-f0-9]{8,}$/i', $base)) {
                $demoId = $base;
            }
        }

        $assoc = '';
        try {
            if (function_exists('app')) {
                /** @var SettingsService $settings */
                $settings = app(SettingsService::class);
                $assoc = trim(localized((string) $settings->get('association.name', '')));
            }
        } catch (\Throwable) {
        }

        $path = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $pathOnly = (string) (parse_url($path, PHP_URL_PATH) ?: $path);

        return [
            'ref' => (string) ($clientContext['ref'] ?? ''),
            'version' => $version,
            'php' => PHP_VERSION,
            'path' => $pathOnly,
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'demo_id' => $demoId,
            'url_prefix' => $urlPrefix,
            'temporary_instance' => function_exists('is_temporary_instance') && is_temporary_instance() ? 'yes' : 'no',
            'association' => $assoc,
            'locale' => (string) ($_ENV['APP_LOCALE'] ?? 'it'),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
            'memory' => function_exists('socly_memory_usage_label') ? socly_memory_usage_label() : '',
            'error_type' => (string) ($clientContext['error_type'] ?? ''),
            'error_message' => (string) ($clientContext['error_message'] ?? ''),
            'error_file' => (string) ($clientContext['error_file'] ?? ''),
            'page_url' => $this->safeClientUrl((string) ($clientContext['page_url'] ?? '')),
            'client_note' => mb_substr(trim((string) ($clientContext['client_note'] ?? '')), 0, 500),
        ];
    }

    /** @param array<string, string> $ctx */
    private function contextMarkdown(array $ctx): string
    {
        $lines = [];
        $map = [
            'ref' => 'Ref',
            'version' => 'Versione',
            'php' => 'PHP',
            'method' => 'Method',
            'path' => 'Path',
            'page_url' => 'Page URL',
            'demo_id' => 'Demo ID',
            'url_prefix' => 'URL prefix',
            'temporary_instance' => 'Temporary instance',
            'association' => 'Associazione',
            'locale' => 'Locale',
            'memory' => 'Memory',
            'user_agent' => 'User-Agent',
            'error_type' => 'Error type',
            'error_message' => 'Error message',
            'error_file' => 'Error file',
            'client_note' => 'Client note',
        ];
        foreach ($map as $key => $label) {
            $value = trim((string) ($ctx[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $lines[] = '- **' . $label . ':** `' . str_replace('`', "'", $value) . '`';
        }
        return $lines === [] ? '_nessun contesto_' : implode("\n", $lines);
    }

    /** @param list<string> $parts */
    private function buildBody(array $parts): string
    {
        $parts[] = '';
        $parts[] = '---';
        $parts[] = '_Inviata da SOCLY via relay socly.it_';
        return implode("\n", $parts);
    }

    private function titleFromDescription(string $description): string
    {
        $one = preg_replace('/\s+/', ' ', $description) ?? $description;
        return mb_substr($one, 0, 90);
    }

    private function shortClass(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max) . "\n…(truncated)";
    }

    private function safeClientUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $path = (string) ($parts['path'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        if ($host !== '') {
            return $host . $path;
        }
        return $path;
    }

    private function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return preg_replace('/[^a-fA-F0-9:.]/', '', $ip) ?: '0.0.0.0';
    }
}
