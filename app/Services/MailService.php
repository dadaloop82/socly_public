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

namespace Socly\Services;

/**
 * Outbound mail via SMTP (AUTH LOGIN, optional STARTTLS/SMTPS).
 */
final class MailService
{
    /**
     * After an automatic search (success or failure) the discovery rests this long: the probe
     * hammers the provider and holds the session for up to two minutes, so the operator works
     * with the pre-filled/manual configuration instead.
     */
    public const DISCOVERY_COOLDOWN_SECONDS = 21600; // 6 hours

    public function __construct(
        private readonly SettingsService $settings
    ) {
    }

    public function isConfigured(): bool
    {
        $cfg = $this->config();
        return $cfg['host'] !== ''
            && $cfg['from_address'] !== ''
            && filter_var($cfg['from_address'], FILTER_VALIDATE_EMAIL);
    }

    /** Configured and last test succeeded. */
    public function isReady(): bool
    {
        if ($this->isOutboundDisabled()) {
            return false;
        }

        return $this->isConfigured()
            && ((string) $this->settings->get('mail.last_test_ok', '0')) === '1';
    }

    public function isOutboundDisabled(): bool
    {
        return (string) $this->settings->get('mail.outbound_disabled', '0') === '1';
    }

    /** Skip outbound mail: mark step done and turn off mail-dependent features. */
    public function disableOutbound(): void
    {
        $this->settings->set('mail.outbound_disabled', '1');
        $this->settings->set('mail.configured', '1');
        $this->settings->set('mail.last_test_ok', '0');
        $this->disableMailDependentOptions();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,error?:string,errors?:array<string,string>,needs_manual?:bool,host?:string,port?:int,encryption?:string,username?:string,from_address?:string}
     */
    public function discover(array $input): array
    {
        // Automatic search rested after the previous attempt: the operator verifies the pre-filled
        // (or manual) configuration instead of re-running a slow probe.
        $autoSearch = trim((string) ($input['host'] ?? '')) === '';
        if ($autoSearch) {
            $cooldown = $this->discoveryCooldown();
            if ($cooldown['active']) {
                return [
                    'ok' => false,
                    'cooldown' => true,
                    'cooldown_until' => date('c', $cooldown['until']),
                    'cooldown_remaining' => $cooldown['remaining'],
                    'needs_manual' => true,
                    'error' => __('mail.discovery_cooldown', [
                        'time' => date('H:i', $cooldown['until']),
                        'minutes' => (string) max(1, (int) ceil($cooldown['remaining'] / 60)),
                    ]),
                ];
            }
        }

        $fromAddress = trim((string) ($input['from_address'] ?? ''));
        $passwordIn = (string) ($input['password'] ?? '');
        $existingPassword = (string) $this->settings->get('mail.password', '');
        $password = $passwordIn !== '' ? $passwordIn : $existingPassword;
        $username = trim((string) ($input['username'] ?? ''));
        if ($username === '' && $fromAddress !== '') {
            $username = $fromAddress;
        }

        $errors = [];
        if ($fromAddress === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $errors['from_address'] = __('validation.email');
        }
        if ($password === '') {
            $errors['password'] = __('mail.password_required');
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        // Manual host already filled: verify that config instead of searching again.
        $host = trim((string) ($input['host'] ?? ''));
        if ($host !== '') {
            $port = (int) ($input['port'] ?? 587);
            $encryption = (string) ($input['encryption'] ?? 'tls');
            [$port, $encryption] = $this->normalizePortEncryption($port, $encryption);
            $cfg = [
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'username' => $username,
                'password' => $password,
                'from_address' => $fromAddress,
            ];
            $probe = $this->testConnection($cfg, 12);
            if (!empty($probe['ok'])) {
                return [
                    'ok' => true,
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $encryption,
                    'username' => $username,
                    'from_address' => $fromAddress,
                    'connection_ok' => true,
                ];
            }

            $probeError = (string) ($probe['error'] ?? '');
            $probeRaw = trim((string) ($probe['raw_error'] ?? ''));
            $looksTimeout = stripos($probeError, 'timed out') !== false
                || stripos($probeError, 'timeout') !== false
                || stripos($probeError, 'Connection refused') !== false;

            $probeMessage = $looksTimeout
                ? __('mail.discovery_unreachable', ['host' => $host, 'tried' => '1'])
                : ($probeError !== '' ? $probeError : __('mail.discovery_failed', ['tried' => '1']));
            $this->logDiscoveryFailure(1, $looksTimeout, $host, $probeRaw !== '' ? $probeRaw : $probeError);

            return [
                'ok' => false,
                'error' => $this->withDiscoveryDetail($probeMessage, $probeRaw),
                'needs_manual' => true,
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'username' => $username,
                'from_address' => $fromAddress,
                'tried' => 1,
                'unreachable' => $looksTimeout,
                'last_error' => $probeRaw !== '' ? $probeRaw : $probeError,
            ];
        }

        @set_time_limit(130);
        // An attempt is an attempt, even if it times out: the cooldown starts before the probe.
        $this->settings->set('mail.discovery_last_attempt', (string) time());
        $found = app(SmtpDiscoveryService::class)->discover($fromAddress, $password, $username);
        if (empty($found['ok']) || empty($found['config'])) {
            $suggestion = is_array($found['suggestion'] ?? null) ? $found['suggestion'] : null;
            $this->storeDiscoverySuggestion($suggestion);
            $hostHint = (string) ($suggestion['host'] ?? 'smtp');
            $error = !empty($found['unreachable'])
                ? __('mail.discovery_unreachable', [
                    'host' => $hostHint,
                    'tried' => (string) ((int) ($found['tried'] ?? 0)),
                ])
                : __('mail.discovery_failed', ['tried' => (string) ((int) ($found['tried'] ?? 0))]);
            $detail = trim((string) ($found['last_error'] ?? ''));
            $this->logDiscoveryFailure(
                (int) ($found['tried'] ?? 0),
                !empty($found['unreachable']),
                $hostHint,
                $detail
            );

            return [
                'ok' => false,
                'error' => $this->withDiscoveryDetail($error, $detail),
                'needs_manual' => true,
                'tried' => (int) ($found['tried'] ?? 0),
                'unreachable' => !empty($found['unreachable']),
                'last_error' => (string) ($found['last_error'] ?? ''),
                'suggestion' => $suggestion,
                'host' => $suggestion['host'] ?? '',
                'port' => $suggestion['port'] ?? 587,
                'encryption' => $suggestion['encryption'] ?? 'tls',
                'username' => $suggestion['username'] ?? $username,
                'from_address' => $fromAddress,
            ];
        }

        $cfg = $found['config'];
        $this->clearDiscoverySuggestion();
        return [
            'ok' => true,
            'host' => $cfg['host'],
            'port' => $cfg['port'],
            'encryption' => $cfg['encryption'],
            'username' => $cfg['username'],
            'from_address' => $cfg['from_address'],
            'connection_ok' => true,
            'tried' => (int) ($found['tried'] ?? 0),
        ];
    }

    /**
     * Append the technical SMTP reason so the operator (and support) know *why* the automatic
     * search failed instead of only “we could not connect”. Never carries credentials: the
     * string comes from the socket handshake (e.g. “535 5.7.8 Username and Password not accepted”).
     */
    private function withDiscoveryDetail(string $message, string $detail): string
    {
        $detail = trim((string) (preg_replace('/\s+/', ' ', $detail) ?? ''));
        if ($detail === '' || str_contains($message, $detail)) {
            return $message;
        }
        if (mb_strlen($detail, 'UTF-8') > 200) {
            $detail = mb_substr($detail, 0, 200, 'UTF-8') . '…';
        }

        return $message . ' ' . __('mail.discovery_detail', ['error' => $detail]);
    }

    /** Support trail for a failed discovery: host/port + SMTP answer only, never the mailbox. */
    private function logDiscoveryFailure(int $tried, bool $unreachable, string $host, string $detail): void
    {
        if ($detail === '') {
            return;
        }
        try {
            app('logger')->warning('mail.discovery_failed', [
                'tried' => $tried,
                'unreachable' => $unreachable,
                'host' => $host,
                'detail' => mb_substr($detail, 0, 300, 'UTF-8'),
            ]);
        } catch (\Throwable) {
            // Logging must never break the mail step of the wizard.
        }
    }

    /**
     * Remaining rest of the automatic discovery (per instance).
     *
     * @return array{active:bool,until:int,remaining:int}
     */
    public function discoveryCooldown(): array
    {
        $last = (int) $this->settings->get('mail.discovery_last_attempt', '0');
        $until = $last > 0 ? $last + self::DISCOVERY_COOLDOWN_SECONDS : 0;
        $remaining = max(0, $until - time());

        return ['active' => $remaining > 0, 'until' => $until, 'remaining' => $remaining];
    }

    /**
     * Host/port/encryption/username suggested by the last failed search: the manual form starts
     * from these values instead of an empty box.
     *
     * @return array{host:string,port:int,encryption:string,username:string}
     */
    public function discoveryPrefill(): array
    {
        return [
            'host' => trim((string) $this->settings->get('mail.suggestion_host', '')),
            'port' => (int) ($this->settings->get('mail.suggestion_port', '0') ?: 0),
            'encryption' => trim((string) $this->settings->get('mail.suggestion_encryption', '')),
            'username' => trim((string) $this->settings->get('mail.suggestion_username', '')),
        ];
    }

    /** @param array<string,mixed>|null $suggestion */
    private function storeDiscoverySuggestion(?array $suggestion): void
    {
        if ($suggestion === null) {
            return;
        }
        $host = trim((string) ($suggestion['host'] ?? ''));
        if ($host === '') {
            return;
        }
        $this->settings->set('mail.suggestion_host', $host);
        $this->settings->set('mail.suggestion_port', (string) max(1, (int) ($suggestion['port'] ?? 587)));
        $this->settings->set('mail.suggestion_encryption', (string) ($suggestion['encryption'] ?? 'tls'));
        $this->settings->set('mail.suggestion_username', trim((string) ($suggestion['username'] ?? '')));
    }

    private function clearDiscoverySuggestion(): void
    {
        foreach (['mail.suggestion_host', 'mail.suggestion_port', 'mail.suggestion_encryption', 'mail.suggestion_username'] as $key) {
            $this->settings->set($key, '');
        }
    }

    /** @return array{host:string,port:int,encryption:string,username:string,password:string,from_address:string,from_name:string,has_password:bool,last_test_ok:bool,last_test_at:string} */
    public function config(): array
    {
        $password = (string) $this->settings->get('mail.password', '');
        return [
            'host' => trim((string) $this->settings->get('mail.host', '')),
            'port' => (int) $this->settings->get('mail.port', 587),
            'encryption' => (string) $this->settings->get('mail.encryption', 'tls'),
            'username' => trim((string) $this->settings->get('mail.username', '')),
            'password' => $password,
            'from_address' => trim((string) $this->settings->get('mail.from_address', '')),
            'from_name' => trim((string) $this->settings->get('mail.from_name', '')),
            'has_password' => $password !== '',
            'last_test_ok' => ((string) $this->settings->get('mail.last_test_ok', '0')) === '1',
            'last_test_at' => (string) $this->settings->get('mail.last_test_at', ''),
            'outbound_disabled' => $this->isOutboundDisabled(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>,needs_manual?:bool}
     */
    public function saveSimple(array $input, bool $requireTestPass = false): array
    {
        $host = trim((string) ($input['host'] ?? ''));
        if ($host !== '') {
            return $this->save($input, $requireTestPass);
        }

        $fromAddress = trim((string) ($input['from_address'] ?? ''));
        $passwordIn = (string) ($input['password'] ?? '');
        $existingPassword = (string) $this->settings->get('mail.password', '');
        $password = $passwordIn !== '' ? $passwordIn : $existingPassword;
        $username = trim((string) ($input['username'] ?? ''));

        $errors = [];
        if ($fromAddress === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $errors['from_address'] = __('validation.email');
        }
        if ($password === '') {
            $errors['password'] = __('mail.password_required');
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $discovery = app(SmtpDiscoveryService::class);
        $found = $discovery->discover($fromAddress, $password, $username);
        if (!empty($found['cooldown'])) {
            // The automatic search is resting: continue with the saved suggestion when there is
            // one, otherwise ask for the manual data instead of failing for hours.
            $prefill = $this->discoveryPrefill();
            if ($prefill['host'] !== '') {
                return $this->save(array_merge($input, [
                    'host' => $prefill['host'],
                    'port' => $prefill['port'] > 0 ? $prefill['port'] : 587,
                    'encryption' => $prefill['encryption'] !== '' ? $prefill['encryption'] : 'tls',
                    'username' => $prefill['username'] !== '' ? $prefill['username'] : $username,
                ]), $requireTestPass);
            }

            return [
                'ok' => false,
                'errors' => ['from_address' => (string) ($found['error'] ?? '')],
                'needs_manual' => true,
            ];
        }
        if (empty($found['ok']) || empty($found['config'])) {
            $msg = !empty($found['unreachable'])
                ? __('mail.discovery_unreachable', [
                    'host' => (string) (($found['suggestion']['host'] ?? null) ?: 'smtp'),
                    'tried' => (string) ((int) ($found['tried'] ?? 0)),
                ])
                : __('mail.discovery_failed', ['tried' => (string) ((int) ($found['tried'] ?? 0))]);
            $msg = $this->withDiscoveryDetail($msg, (string) ($found['last_error'] ?? ''));

            return [
                'ok' => false,
                'errors' => ['from_address' => $msg],
                'needs_manual' => true,
                'suggestion' => $found['suggestion'] ?? null,
            ];
        }
        $found = $found['config'];

        $payload = array_merge($input, $found);
        if (trim((string) ($payload['from_name'] ?? '')) === '') {
            $payload['from_name'] = trim((string) $this->settings->get('association.name', 'SOCLY'));
        }
        if (trim((string) ($payload['test_to'] ?? '')) === '') {
            $payload['test_to'] = $fromAddress;
        }

        return $this->save($payload, $requireTestPass);
    }

    /**
     * @param array{host:string,port:int,encryption:string,username:string,password:string,from_address?:string,from_name?:string} $cfg
     * @return array{ok:bool,error?:string}
     */
    public function testConnection(array $cfg, int $timeoutSeconds = 20): array
    {
        $host = trim((string) ($cfg['host'] ?? ''));
        $port = (int) ($cfg['port'] ?? 0);
        $encryption = (string) ($cfg['encryption'] ?? 'tls');
        [$port, $encryption] = $this->normalizePortEncryption($port, $encryption);
        if ($host === '' || $port < 1 || $port > 65535) {
            return ['ok' => false, 'error' => 'invalid host/port'];
        }

        try {
            $fp = $this->smtpConnect($host, $port, $encryption, max(2, $timeoutSeconds));
            try {
                $username = trim((string) ($cfg['username'] ?? ''));
                if ($username !== '') {
                    $this->smtpAuthenticate($fp, $username, (string) ($cfg['password'] ?? ''));
                }
            } finally {
                @fwrite($fp, "QUIT\r\n");
                @fclose($fp);
            }
            return ['ok' => true];
        } catch (\Throwable $e) {
            $raw = $e->getMessage();
            $friendly = $this->friendlySmtpAuthError($raw);
            return ['ok' => false, 'error' => $friendly, 'raw_error' => $raw];
        }
    }

    private function friendlySmtpAuthError(string $raw): string
    {
        $lower = strtolower($raw);
        if (preg_match(
            '/\b(535|534)\b|authentication failed|bad auth|#auth|invalid (user|login|credentials)|username and password not accepted|login failed|auth(?:entication)? (?:fail|error|denied|rejected)/i',
            $raw
        ) === 1) {
            return (string) __('mail.auth_failed');
        }
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return (string) __('mail.connection_timeout');
        }

        return $raw !== '' ? $raw : (string) __('mail.discovery_failed', ['tried' => '1']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    public function save(array $input, bool $requireTestPass = false): array
    {
        $host = trim((string) ($input['host'] ?? ''));
        $port = (int) ($input['port'] ?? 587);
        $encryption = (string) ($input['encryption'] ?? 'tls');
        [$port, $encryption] = $this->normalizePortEncryption($port, $encryption);
        $username = trim((string) ($input['username'] ?? ''));
        $fromAddress = trim((string) ($input['from_address'] ?? ''));
        $fromName = trim((string) ($input['from_name'] ?? ''));
        $passwordIn = (string) ($input['password'] ?? '');
        if ($username === '' && $fromAddress !== '') {
            $username = $fromAddress;
        }

        $errors = [];
        if ($host === '') {
            $errors['host'] = __('validation.required');
        }
        if ($port < 1 || $port > 65535) {
            $errors['port'] = __('validation.required');
        }
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            $errors['encryption'] = __('validation.in');
        }
        if ($fromAddress === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $errors['from_address'] = __('validation.email');
        }
        $existingPassword = (string) $this->settings->get('mail.password', '');
        if ($passwordIn === '' && $existingPassword === '' && $username !== '') {
            $errors['password'] = __('mail.password_required');
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $this->settings->set('mail.host', $host);
        $this->settings->set('mail.port', (string) $port);
        $this->settings->set('mail.encryption', $encryption);
        $this->settings->set('mail.username', $username);
        $this->settings->set('mail.from_address', $fromAddress);
        $this->settings->set('mail.from_name', $fromName);
        if ($passwordIn !== '') {
            $this->settings->set('mail.password', $passwordIn, true);
        }
        $this->settings->set('mail.outbound_disabled', '0');
        $this->settings->set('mail.configured', '1');

        if ($requireTestPass) {
            // Client-side Continua pipeline may have just verified; avoid a second test email.
            $lastTestAt = strtotime((string) $this->settings->get('mail.last_test_at', '')) ?: 0;
            $recentlyVerified = $this->isReady() && $lastTestAt > 0 && (time() - $lastTestAt) < 180;
            if ($recentlyVerified
                && $host === trim((string) $this->settings->get('mail.host', ''))
                && $username === trim((string) $this->settings->get('mail.username', ''))
                && $fromAddress === trim((string) $this->settings->get('mail.from_address', ''))
            ) {
                return ['ok' => true];
            }
            $testTo = trim((string) ($input['test_to'] ?? $fromAddress));
            $test = $this->sendTest($testTo !== '' ? $testTo : $fromAddress);
            if (!$test['ok']) {
                $this->settings->set('mail.last_test_ok', '0');
                return ['ok' => false, 'errors' => ['test' => (string) ($test['error'] ?? __('mail.test_failed'))]];
            }
        }

        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public function sendTest(string $to): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => __('validation.email')];
        }
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => __('mail.not_configured')];
        }

        $subject = __('mail.test_subject');
        $body = __('mail.test_body');
        $result = $this->send($to, $subject, $body);
        $this->settings->set('mail.last_test_at', date('c'));
        $this->settings->set('mail.last_test_ok', $result['ok'] ? '1' : '0');
        if (!$result['ok']) {
            $this->disableMailDependentOptions();
        }
        return $result;
    }

    /**
     * When mail stops working, turn off options that require outbound email.
     */
    public function disableMailDependentOptions(): void
    {
        $this->settings->set('platform.usage_stats_opt_in', '0');
        $this->settings->set('platform.showcase_consent', '0');
        $this->settings->set('platform.news_opt_in', '0');
        $method = (string) $this->settings->get('membership.enrollment_validation', 'none');
        if ($method === 'otp_email') {
            $this->settings->set('membership.enrollment_validation', 'none');
        }
    }

    /** @return array{ok:bool,error?:string} */
    public function sendUserWelcome(string $email, string $plainPassword, string $locale = 'it'): array
    {
        if (!$this->isReady()) {
            return ['ok' => false, 'error' => 'mail_not_ready'];
        }
        $prev = $_SESSION['locale'] ?? null;
        $_SESSION['locale'] = in_array($locale, ['it', 'de', 'en'], true) ? $locale : 'it';
        $loginUrl = url('/login');
        $subject = __('users.welcome_email_subject');
        $body = __('users.welcome_email_body', [
            'url' => $loginUrl,
            'email' => $email,
            'password' => $plainPassword,
        ]);
        if ($prev !== null) {
            $_SESSION['locale'] = $prev;
        } else {
            unset($_SESSION['locale']);
        }

        return $this->send($email, $subject, $body);
    }

    /** @return array{ok:bool,error?:string} */
    public function send(string $to, string $subject, string $bodyText, ?string $bodyHtml = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => __('mail.not_configured')];
        }

        $cfg = $this->config();
        try {
            $this->smtpSend($cfg, $to, $subject, $bodyText, $bodyHtml);
            return ['ok' => true];
        } catch (\Throwable $e) {
            try {
                app('logger')->error('mail.send_failed', ['error' => $e->getMessage(), 'to' => $to]);
            } catch (\Throwable) {
            }
            return ['ok' => false, 'error' => __('mail.send_failed') . ' ' . $e->getMessage()];
        }
    }

    /**
     * @param array{host:string,port:int,encryption:string,username:string,password:string,from_address:string,from_name:string} $cfg
     */
    private function smtpSend(array $cfg, string $to, string $subject, string $bodyText, ?string $bodyHtml): void
    {
        $fp = $this->smtpConnect($cfg['host'], (int) $cfg['port'], (string) $cfg['encryption']);

        try {
            if ($cfg['username'] !== '') {
                $this->smtpAuthenticate($fp, (string) $cfg['username'], (string) $cfg['password']);
            }

            $from = $cfg['from_address'];
            $fromName = $cfg['from_name'] !== '' ? $cfg['from_name'] : 'SOCLY';
            $this->command($fp, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($fp, 'DATA', [354]);

            $headers = [
                'Date: ' . date('r'),
                'From: ' . $this->formatAddress($fromName, $from),
                'To: <' . $to . '>',
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@socly.local>',
            ];
            if ($bodyHtml !== null && $bodyHtml !== '') {
                $boundary = 'b_' . bin2hex(random_bytes(8));
                $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
                $data = implode("\r\n", $headers) . "\r\n\r\n";
                $data .= '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
                $data .= chunk_split(base64_encode($bodyText)) . "\r\n";
                $data .= '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
                $data .= chunk_split(base64_encode($bodyHtml)) . "\r\n";
                $data .= '--' . $boundary . "--\r\n";
            } else {
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
                $headers[] = 'Content-Transfer-Encoding: base64';
                $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($bodyText));
            }
            $data = preg_replace('/^\./m', '..', $data) ?? $data;
            fwrite($fp, $data . "\r\n.\r\n");
            $this->expect($fp, [250]);
            $this->command($fp, 'QUIT', [221]);
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /** @return resource */
    private function smtpConnect(string $host, int $port, string $encryption, int $timeoutSeconds = 20)
    {
        [$port, $encryption] = $this->normalizePortEncryption($port, $encryption);
        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $context = stream_context_create([
            'ssl' => [
                'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0)
                    | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0),
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
        $fp = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$fp) {
            throw new \RuntimeException(trim($errstr !== '' ? $errstr : 'connection failed'));
        }
        stream_set_timeout($fp, $timeoutSeconds);

        $this->expect($fp, [220]);
        $this->command($fp, 'EHLO socly.local', [250]);
        if ($encryption === 'tls') {
            $this->command($fp, 'STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                throw new \RuntimeException('STARTTLS failed');
            }
            $this->command($fp, 'EHLO socly.local', [250]);
        }

        return $fp;
    }

    /**
     * Align encryption with common SMTP port conventions.
     * Port 465 = implicit SSL/TLS; 587/2525 = STARTTLS.
     *
     * @return array{0:int,1:string}
     */
    private function normalizePortEncryption(int $port, string $encryption): array
    {
        if ($port < 1 || $port > 65535) {
            $port = 587;
        }
        $encryption = strtolower(trim($encryption));
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            $encryption = 'tls';
        }

        if ($port === 465 && $encryption !== 'ssl') {
            $encryption = 'ssl';
        } elseif (($port === 587 || $port === 2525) && $encryption === 'ssl') {
            $encryption = 'tls';
        }

        return [$port, $encryption];
    }

    /** @param resource $fp */
    private function smtpAuthenticate($fp, string $username, string $password): void
    {
        $this->command($fp, 'AUTH LOGIN', [334]);
        $this->command($fp, base64_encode($username), [334]);
        $this->command($fp, base64_encode($password), [235]);
    }

    /** @param resource $fp @param list<int> $codes */
    private function command($fp, string $cmd, array $codes): void
    {
        fwrite($fp, $cmd . "\r\n");
        $this->expect($fp, $codes);
    }

    /** @param resource $fp @param list<int> $codes */
    private function expect($fp, array $codes): void
    {
        $response = '';
        while (($line = fgets($fp, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            $detail = trim($response);
            if ($detail === '') {
                throw new \RuntimeException(
                    'SMTP unexpected reply (porta/crittografia non compatibili: usa SSL/TLS su 465 oppure STARTTLS su 587)'
                );
            }
            throw new \RuntimeException($detail);
        }
    }

    private function formatAddress(string $name, string $email): string
    {
        $safe = str_replace(["\r", "\n"], '', $name);
        return $this->encodeHeader($safe) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
