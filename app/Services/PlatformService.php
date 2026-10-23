<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

final class PlatformService
{
    private const TELEMETRY_INTERVAL = 21600; // 6 hours
    private const INSTALLATION_INTERVAL = 86400; // 24 hours

    public function __construct(
        private readonly SettingsService $settings,
        private readonly BrandingService $branding,
        private readonly ComponentService $components,
        private readonly MemberService $members,
        private readonly Database $db
    ) {
    }

    public function isTemporaryInstance(): bool
    {
        return (string) $this->settings->get('app.temporary_instance', '0') === '1';
    }

    public function isNewsEnabled(): bool
    {
        return (string) $this->settings->get('platform.news_opt_in', '1') !== '0';
    }

    public function isStatsEnabled(): bool
    {
        return (string) $this->settings->get('platform.usage_stats_opt_in', '1') !== '0';
    }

    /**
     * Explicit consent to send association/installation details to the Socly servers
     * (populates the “Contatti installazioni” registry). Never available on demos.
     */
    public function isContactsEnabled(): bool
    {
        return !$this->isTemporaryInstance()
            && (string) $this->settings->get('platform.contacts_opt_in', '1') !== '0';
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
        // Demos are disposable: never pollute platform statistics.
        if ($this->isTemporaryInstance()) {
            return;
        }

        // Installation/contact sync has its own consent and cadence.
        $this->maybeSyncInstallation(
            (string) $this->settings->get('platform.installation_needs_sync', '0') === '1'
        );

        if (!$this->isStatsEnabled()) {
            return;
        }

        $last = (int) $this->settings->get('platform.telemetry_last_at', '0');
        if ($last > 0 && (time() - $last) < self::TELEMETRY_INTERVAL) {
            return;
        }
        // Backoff after a failed attempt: never block every session ping on a dead endpoint.
        $lastTry = (int) $this->settings->get('platform.telemetry_last_try', '0');
        if ($lastTry > 0 && (time() - $lastTry) < 900) {
            return;
        }
        $this->settings->set('platform.telemetry_last_try', (string) time());

        $payload = [
            'action' => 'telemetry',
            'token' => $this->instanceToken(),
            'version' => app_version(),
            'locale' => (string) $this->settings->get('app.locale', 'it'),
            'members_bucket' => $this->membersBucket(),
            'members_exact' => $this->countRows('members'),
            'users_exact' => $this->countRows('users'),
            'components' => $this->components->enabledKeys(),
            'plugins' => $this->enabledPlugins(),
            'php_major' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'php_version' => PHP_VERSION,
            'db_version' => $this->databaseVersion(),
            'installed_at' => $this->installedAt(),
            'counts' => [
                'documents' => $this->countRows('association_documents'),
                'deadlines' => $this->countRows('deadline_items'),
                'treasury_movements' => $this->countRows('treasury_movements'),
            ],
        ];

        if ($this->post($payload)) {
            $this->settings->set('platform.telemetry_last_at', (string) time());
        }
    }

    /**
     * Send (or revoke) the association/installation card shown in the Socly admin
     * “Contatti installazioni” registry. Runs daily, on version change and on demand.
     */
    public function maybeSyncInstallation(bool $force = false): void
    {
        if ($this->isTemporaryInstance()) {
            return;
        }

        if (!$this->isContactsEnabled()) {
            if ((string) $this->settings->get('platform.installation_sent', '0') === '1') {
                $lastTry = (int) $this->settings->get('platform.installation_last_try', '0');
                if ($lastTry > 0 && (time() - $lastTry) < 600) {
                    return;
                }
                $this->settings->set('platform.installation_last_try', (string) time());
                if ($this->post([
                    'action' => 'installation_revoke',
                    'token' => $this->instanceToken(),
                ])) {
                    $this->settings->set('platform.installation_sent', '0');
                }
            }
            return;
        }

        $last = (int) $this->settings->get('platform.installation_last_at', '0');
        $version = app_version();
        $lastVersion = (string) $this->settings->get('platform.installation_version', '');
        $versionChanged = $lastVersion !== '' && $lastVersion !== $version;
        if (!$force && !$versionChanged && $last > 0 && (time() - $last) < self::INSTALLATION_INTERVAL) {
            return;
        }
        // Backoff after a failed attempt: retry at most every 10 minutes even when forced.
        $lastTry = (int) $this->settings->get('platform.installation_last_try', '0');
        $failedRecently = $lastTry > $last && (time() - $lastTry) < 600;
        if ($failedRecently) {
            return;
        }
        if (!$force && $lastTry > 0 && (time() - $lastTry) < 3600) {
            return;
        }
        $this->settings->set('platform.installation_last_try', (string) time());

        if ($this->post($this->installationPayload())) {
            $this->settings->set('platform.installation_last_at', (string) time());
            $this->settings->set('platform.installation_version', $version);
            $this->settings->set('platform.installation_sent', '1');
            $this->settings->set('platform.installation_needs_sync', '0');
        }
    }

    /** @return array<string, mixed> */
    private function installationPayload(): array
    {
        $admin = $this->adminContact();
        $president = $this->presidentContact();

        return [
            'action' => 'installation_upsert',
            'token' => $this->instanceToken(),
            'version' => app_version(),
            'locale' => (string) $this->settings->get('app.locale', 'it'),
            'app_url' => (string) $this->settings->get('app.url', ''),
            'installed_at' => $this->installedAt(),
            'association' => [
                'name' => $this->setting('association.name'),
                'legal_name' => $this->setting('association.legal_name'),
                'email' => $this->setting('association.email'),
                'pec' => $this->setting('association.pec'),
                'phone' => $this->setting('association.phone'),
                'website' => $this->setting('association.website'),
                'address' => $this->setting('association.address'),
                'house_number' => $this->setting('association.house_number'),
                'postal_code' => $this->setting('association.postal_code'),
                'city' => $this->setting('association.city'),
                'province' => $this->setting('association.province'),
                'country' => $this->setting('association.country'),
                'fiscal_code' => $this->setting('association.fiscal_code'),
                'vat_number' => $this->setting('association.vat_number'),
                'runts' => $this->setting('association.runts'),
                'founded_on' => $this->setting('association.founded_on'),
                'members_declared' => $this->setting('association.members_count'),
                'volunteers_declared' => $this->setting('association.volunteers_count'),
            ],
            'contact' => [
                'admin_name' => $admin['name'],
                'admin_email' => $admin['email'],
                'president_name' => $president['name'],
                'president_email' => $president['email'],
            ],
            'usage' => [
                'members' => $this->countRows('members'),
                'users' => $this->countRows('users'),
                'documents' => $this->countRows('association_documents'),
                'deadlines' => $this->countRows('deadline_items'),
                'treasury_movements' => $this->countRows('treasury_movements'),
            ],
            'features' => [
                'components' => $this->components->enabledKeys(),
                'plugins' => $this->enabledPlugins(),
                'gdpr' => (string) $this->settings->get('gdpr.enabled', '0') === '1',
            ],
            'server' => [
                'php_version' => PHP_VERSION,
                'db_version' => $this->databaseVersion(),
                'os' => PHP_OS_FAMILY,
            ],
            'consents' => [
                'news' => $this->isNewsEnabled(),
                'usage_stats' => $this->isStatsEnabled(),
                'contacts' => $this->isContactsEnabled(),
            ],
            'sent_at' => date('c'),
        ];
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

    private function setting(string $key): string
    {
        return trim((string) $this->settings->get($key, ''));
    }

    /** @return array{name:string,email:string} */
    private function adminContact(): array
    {
        $id = (int) $this->settings->get('app.admin_user_id', 0);
        if ($id > 0) {
            try {
                $row = $this->db->fetch('SELECT name, email FROM users WHERE id = :id', ['id' => $id]);
                if (is_array($row)) {
                    return [
                        'name' => trim((string) ($row['name'] ?? '')),
                        'email' => trim((string) ($row['email'] ?? '')),
                    ];
                }
            } catch (\Throwable) {
            }
        }
        return ['name' => '', 'email' => $this->setting('association.email')];
    }

    /** @return array{name:string,email:string} */
    private function presidentContact(): array
    {
        try {
            $row = $this->db->fetch(
                "SELECT first_name, last_name, email FROM association_people
                 WHERE role_key = 'president' AND is_active = 1
                 ORDER BY sort_order ASC, id ASC LIMIT 1"
            );
            if (is_array($row)) {
                return [
                    'name' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
                    'email' => trim((string) ($row['email'] ?? '')),
                ];
            }
        } catch (\Throwable) {
        }
        return ['name' => '', 'email' => ''];
    }

    /** @return list<string> */
    private function enabledPlugins(): array
    {
        try {
            $rows = $this->db->fetchAll('SELECT id FROM plugins WHERE is_enabled = 1 ORDER BY id ASC');
            return array_values(array_filter(array_map(
                static fn (array $row): string => trim((string) ($row['id'] ?? '')),
                $rows
            )));
        } catch (\Throwable) {
            return [];
        }
    }

    private function databaseVersion(): string
    {
        try {
            $row = $this->db->fetch('SELECT VERSION() AS v');
            return is_array($row) ? trim((string) ($row['v'] ?? '')) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function installedAt(): string
    {
        $lock = storage_path('installed.lock');
        if (!is_file($lock)) {
            return '';
        }
        $raw = trim((string) @file_get_contents($lock));
        return strlen($raw) <= 40 ? $raw : '';
    }

    private function countRows(string $table, string $where = ''): int
    {
        try {
            $sql = 'SELECT COUNT(*) AS c FROM ' . $table . ($where !== '' ? ' WHERE ' . $where : '');
            $row = $this->db->fetch($sql);
            return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
        } catch (\Throwable) {
            return 0;
        }
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
            $row = $this->db->fetch('SELECT COUNT(*) AS c FROM members');
            $count = is_array($row) ? (int) ($row['c'] ?? 0) : 0;
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
