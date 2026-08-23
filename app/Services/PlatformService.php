<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

final class PlatformService
{
    private const TELEMETRY_INTERVAL = 21600; // 6 hours

    public function __construct(
        private readonly SettingsService $settings,
        private readonly BrandingService $branding,
        private readonly ComponentService $components,
        private readonly MemberService $members,
        private readonly Database $db
    ) {
    }

    public function isNewsEnabled(): bool
    {
        return (string) $this->settings->get('platform.news_opt_in', '1') !== '0';
    }

    public function isStatsEnabled(): bool
    {
        return (string) $this->settings->get('platform.usage_stats_opt_in', '1') !== '0';
    }

    public function isShowcaseEnabled(): bool
    {
        return (string) $this->settings->get('platform.showcase_consent', '1') !== '0';
    }

    public function instanceToken(): string
    {
        $token = trim((string) $this->settings->get('platform.instance_token', ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $this->settings->set('platform.instance_token', $token);
        }

        return $token;
    }

    public function maybeSendTelemetry(): void
    {
        if (!$this->isStatsEnabled()) {
            return;
        }

        $last = (int) $this->settings->get('platform.telemetry_last_at', '0');
        if ($last > 0 && (time() - $last) < self::TELEMETRY_INTERVAL) {
            return;
        }

        $payload = [
            'action' => 'telemetry',
            'token' => $this->instanceToken(),
            'version' => app_version(),
            'locale' => (string) $this->settings->get('app.locale', 'it'),
            'members_bucket' => $this->membersBucket(),
            'components' => $this->components->enabledKeys(),
            'php_major' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        ];

        if ($this->post($payload)) {
            $this->settings->set('platform.telemetry_last_at', (string) time());
        }
    }

    public function syncShowcase(): void
    {
        if (!$this->isShowcaseEnabled()) {
            $this->post([
                'action' => 'showcase_revoke',
                'token' => $this->instanceToken(),
            ]);
            return;
        }

        $name = trim((string) $this->settings->get('association.name', ''));
        if ($name === '') {
            return;
        }

        $this->post([
            'action' => 'showcase_upsert',
            'token' => $this->instanceToken(),
            'name' => $name,
            'legal_name' => trim((string) $this->settings->get('association.legal_name', '')),
            'website' => trim((string) $this->settings->get('association.website', '')),
            'logo_url' => $this->branding->logoUrl() ?? '',
            'locale' => (string) $this->settings->get('app.locale', 'it'),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): bool
    {
        $url = socly_platform_api_url();
        if ($url === '') {
            return false;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return false;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return false;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'SoclyPlatform/1.0',
            ]);
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($response) || $code >= 400) {
                return false;
            }
            $decoded = json_decode($response, true);

            return is_array($decoded) && !empty($decoded['ok']);
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 8,
            ],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if (!is_string($response)) {
            return false;
        }
        $decoded = json_decode($response, true);

        return is_array($decoded) && !empty($decoded['ok']);
    }

    private function membersBucket(): string
    {
        try {
            $count = (int) ($this->db->fetch('SELECT COUNT(*) AS c FROM members WHERE deleted_at IS NULL')['c'] ?? 0);
        } catch (\Throwable) {
            $count = 0;
        }

        return match (true) {
            $count <= 0 => '0',
            $count <= 50 => '1-50',
            $count <= 200 => '51-200',
            $count <= 500 => '201-500',
            default => '500+',
        };
    }
}
