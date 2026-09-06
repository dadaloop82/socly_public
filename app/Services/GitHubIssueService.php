<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Encryptor;

/**
 * Creates GitHub issues on the private development repo (server-side only).
 */
final class GitHubIssueService
{
    private const DEFAULT_REPO = 'dadaloop82/socly';
    private const MANUAL_MAX_PER_HOUR = 5;
    private const CRASH_DEDUP_SECONDS = 900;

    private ?string $resolvedToken = null;

    public function __construct(
        private readonly RateLimiter $limiter
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->repo() !== '';
    }

    /**
     * Manual report from the footer dialog.
     *
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

        $created = $this->createIssue($title, $body);
        if (!$created['ok']) {
            return $created;
        }
        $this->limiter->hit($limitKey, 3600);
        return $created;
    }

    /**
     * Automatic crash report. Never throws.
     *
     * @return array{ok:bool,issue_url?:string,skipped?:bool,error?:string}
     */
    public function reportCrash(\Throwable $e, string $ref = ''): array
    {
        try {
            if (!$this->isConfigured()) {
                return ['ok' => false, 'skipped' => true, 'error' => 'not_configured'];
            }

            $sig = hash('sha256', $e::class . '|' . $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine());
            $dedupKey = 'report-crash:' . $sig;
            if ($this->limiter->tooManyAttempts($dedupKey, 1, self::CRASH_DEDUP_SECONDS)) {
                return ['ok' => false, 'skipped' => true, 'error' => 'dedup'];
            }

            $ip = $this->clientIp();
            $ipKey = 'report-crash-ip:' . $ip;
            if ($this->limiter->tooManyAttempts($ipKey, self::MANUAL_MAX_PER_HOUR, 3600)) {
                return ['ok' => false, 'skipped' => true, 'error' => 'rate_limited'];
            }

            if ($ref === '') {
                $ref = function_exists('socly_error_ref') ? socly_error_ref() : strtoupper(dechex(time() & 0xffffff));
            }

            $ctx = $this->collectContext([
                'ref' => $ref,
                'error_type' => $e::class,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile() . ':' . $e->getLine(),
                'traceback' => $e->getTraceAsString(),
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

            $created = $this->createIssue($title, $body);
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
            return ['ok' => false, 'error' => 'exception'];
        }
    }

    /**
     * @return array{ok:bool,issue_url?:string,error?:string,code?:string}
     */
    public function createIssue(string $title, string $body): array
    {
        $token = $this->token();
        $repo = $this->repo();
        if ($token === '' || $repo === '') {
            return ['ok' => false, 'error' => 'not_configured', 'code' => 'not_configured'];
        }

        $title = mb_substr(trim($title), 0, 200);
        if ($title === '') {
            $title = '[report] SOCLY';
        }
        $body = $this->truncate($body, 60000);

        $url = 'https://api.github.com/repos/' . $repo . '/issues';
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return ['ok' => false, 'error' => 'encode_failed', 'code' => 'encode_failed'];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 12,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'User-Agent: SOCLY-IssueReporter/1.0',
                    'Accept: application/vnd.github+json',
                    'Authorization: Bearer ' . $token,
                    'X-GitHub-Api-Version: 2022-11-28',
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($payload),
                ]) . "\r\n",
                'content' => $payload,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }

        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status >= 200 && $status < 300 && is_array($data)) {
            $htmlUrl = trim((string) ($data['html_url'] ?? ''));
            return [
                'ok' => true,
                'issue_url' => $htmlUrl !== '' ? $htmlUrl : ('https://github.com/' . $repo . '/issues'),
            ];
        }

        $ghMessage = is_array($data) ? (string) ($data['message'] ?? '') : '';
        try {
            if (function_exists('app')) {
                app('logger')->error('github_issue.create_failed', [
                    'status' => $status,
                    'message' => $ghMessage,
                    'repo' => $repo,
                ]);
            }
        } catch (\Throwable) {
        }

        return ['ok' => false, 'error' => 'github_failed', 'code' => 'github_failed'];
    }

    private function token(): string
    {
        if ($this->resolvedToken !== null) {
            return $this->resolvedToken;
        }

        $candidates = [];

        // 1) Sealed ciphertext from private bootstrap file.
        foreach ($this->sealedSecretCiphertexts('github_issues_token') as $cipher) {
            $candidates[] = $cipher;
        }

        // 2) Env/config ciphertext override (e.g. demo .env without sealed file).
        try {
            $enc = trim((string) config('github_issues.token_enc', ''));
        } catch (\Throwable) {
            $enc = '';
        }
        if ($enc === '') {
            $enc = trim((string) ($_ENV['GITHUB_ISSUES_TOKEN_ENC'] ?? getenv('GITHUB_ISSUES_TOKEN_ENC') ?: ''));
        }
        if ($enc !== '') {
            $candidates[] = $enc;
        }

        foreach ($candidates as $cipher) {
            $plain = $this->decryptSealed($cipher);
            if ($plain !== '') {
                return $this->resolvedToken = $plain;
            }
        }

        // 3) Local plaintext override only (never commit). Useful for one-off debugging.
        try {
            $plain = trim((string) config('github_issues.token', ''));
        } catch (\Throwable) {
            $plain = '';
        }
        if ($plain === '') {
            $plain = trim((string) ($_ENV['GITHUB_ISSUES_TOKEN'] ?? getenv('GITHUB_ISSUES_TOKEN') ?: ''));
        }
        // Ignore values that look like ciphertext placeholders.
        if ($plain !== '' && !str_starts_with($plain, 'enc:')) {
            return $this->resolvedToken = $plain;
        }

        return $this->resolvedToken = '';
    }

    /** @return list<string> */
    private function sealedSecretCiphertexts(string $key): array
    {
        $out = [];
        $paths = [];
        try {
            $paths[] = base_path('bootstrap/sealed_secrets.php');
        } catch (\Throwable) {
        }
        if (defined('SOCLY_CODE_PATH')) {
            $paths[] = rtrim((string) SOCLY_CODE_PATH, '/') . '/bootstrap/sealed_secrets.php';
        }
        if (defined('SOCLY_PRIVATE_PATH')) {
            $paths[] = rtrim((string) SOCLY_PRIVATE_PATH, '/') . '/bootstrap/sealed_secrets.php';
        }
        // Demo code tree is public; private sealed file may live beside the marketing site checkout.
        $paths[] = dirname(__DIR__, 2) . '/bootstrap/sealed_secrets.php';

        foreach (array_unique($paths) as $path) {
            if (!is_file($path)) {
                continue;
            }
            try {
                $data = require $path;
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($data)) {
                continue;
            }
            $cipher = trim((string) ($data[$key] ?? ''));
            if ($cipher !== '') {
                $out[] = $cipher;
            }
        }
        return $out;
    }

    private function decryptSealed(string $ciphertext): string
    {
        $ciphertext = trim($ciphertext);
        if ($ciphertext === '') {
            return '';
        }
        foreach ($this->sealKeys() as $key) {
            try {
                $plain = (new Encryptor($key))->decrypt($ciphertext);
                $plain = trim($plain);
                if ($plain !== '') {
                    return $plain;
                }
            } catch (\Throwable) {
                // try next key
            }
        }
        return '';
    }

    /** @return list<string> */
    private function sealKeys(): array
    {
        $keys = [];
        try {
            $seal = trim((string) config('github_issues.seal_key', ''));
        } catch (\Throwable) {
            $seal = '';
        }
        if ($seal === '') {
            $seal = trim((string) ($_ENV['SOCLY_SEAL_KEY'] ?? getenv('SOCLY_SEAL_KEY') ?: ''));
        }
        if ($seal !== '') {
            $keys[] = $seal;
        }
        // Fallback: instance APP_KEY (for env-local re-encrypted blobs).
        try {
            $appKey = trim((string) config('app.key', ''));
        } catch (\Throwable) {
            $appKey = '';
        }
        if ($appKey === '') {
            $appKey = trim((string) ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: ''));
        }
        if ($appKey !== '') {
            $keys[] = $appKey;
        }
        return array_values(array_unique($keys));
    }

    private function repo(): string
    {
        $fromConfig = '';
        try {
            $fromConfig = trim((string) config('github_issues.repo', ''));
        } catch (\Throwable) {
        }
        $repo = $fromConfig !== ''
            ? $fromConfig
            : trim((string) ($_ENV['GITHUB_ISSUES_REPO'] ?? getenv('GITHUB_ISSUES_REPO') ?: self::DEFAULT_REPO));
        $repo = preg_replace('#^https?://github\.com/#i', '', $repo) ?? $repo;
        $repo = trim($repo, '/');
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            return self::DEFAULT_REPO;
        }
        return $repo;
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
        // Never include query strings that may carry tokens.
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
        $parts[] = '_Inviata automaticamente da SOCLY Issue Reporter_';
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
