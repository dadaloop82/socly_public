<?php

declare(strict_types=1);

namespace Socly\Services;

/**
 * Outbound mail via SMTP (AUTH LOGIN, optional STARTTLS/SMTPS).
 */
final class MailService
{
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
            $probe = $this->testConnection($cfg, 8);
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
            $looksTimeout = stripos($probeError, 'timed out') !== false
                || stripos($probeError, 'timeout') !== false
                || stripos($probeError, 'Connection refused') !== false;

            return [
                'ok' => false,
                'error' => $looksTimeout
                    ? __('mail.discovery_unreachable', ['host' => $host, 'tried' => '1'])
                    : ($probeError !== '' ? $probeError : __('mail.discovery_failed', ['tried' => '1'])),
                'needs_manual' => true,
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'username' => $username,
                'from_address' => $fromAddress,
                'tried' => 1,
                'unreachable' => $looksTimeout,
            ];
        }

        @set_time_limit(45);
        $found = app(SmtpDiscoveryService::class)->discover($fromAddress, $password, $username);
        if (empty($found['ok']) || empty($found['config'])) {
            $suggestion = is_array($found['suggestion'] ?? null) ? $found['suggestion'] : null;
            $hostHint = (string) ($suggestion['host'] ?? 'smtp');
            $error = !empty($found['unreachable'])
                ? __('mail.discovery_unreachable', [
                    'host' => $hostHint,
                    'tried' => (string) ((int) ($found['tried'] ?? 0)),
                ])
                : __('mail.discovery_failed', ['tried' => (string) ((int) ($found['tried'] ?? 0))]);

            return [
                'ok' => false,
                'error' => $error,
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
        if (empty($found['ok']) || empty($found['config'])) {
            $msg = !empty($found['unreachable'])
                ? __('mail.discovery_unreachable', [
                    'host' => (string) (($found['suggestion']['host'] ?? null) ?: 'smtp'),
                    'tried' => (string) ((int) ($found['tried'] ?? 0)),
                ])
                : __('mail.discovery_failed', ['tried' => (string) ((int) ($found['tried'] ?? 0))]);

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
            return ['ok' => false, 'error' => $e->getMessage()];
        }
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
