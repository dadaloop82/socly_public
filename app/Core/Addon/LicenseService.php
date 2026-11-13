<?php

declare(strict_types=1);

namespace Socly\Core\Addon;

use Socly\Services\AuditService;
use Socly\Services\PlatformService;
use Socly\Services\SettingsService;
use SodiumException;

/**
 * Licence lifecycle for add-ons: online activation, offline codes, signed manifest,
 * local cache. Packages and licences are verified locally with Ed25519; no personal
 * data is ever sent (only the instance fingerprint, host, core version and licence id).
 */
final class LicenseService
{
    private const SET_ENTITLEMENT = 'addons.entitlement';
    private const SET_LICENSE_KEY = 'addons.license_key';
    private const SET_REVOKED = 'addons.revoked';
    private const SET_LAST_SEEN = 'addons.license_last_seen';
    private const SET_MANIFEST = 'addons.manifest';
    private const SET_MANIFEST_SIG = 'addons.manifest_signature';
    private const SET_MANIFEST_AT = 'addons.manifest_fetched_at';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PlatformService $platform,
        private readonly AuditService $audit
    ) {
    }

    /** Stable, non-reversible instance fingerprint (first 16 hex of sha256(token)). */
    public function instanceCode(): string
    {
        return substr(hash('sha256', $this->platform->instanceToken()), 0, 16);
    }

    public function host(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            return mb_substr(strtolower(preg_replace('/:\\d+$/', '', $host) ?? $host), 0, 190);
        }
        $url = (string) ($_ENV['APP_URL'] ?? '');
        $parsed = parse_url($url, PHP_URL_HOST);

        return is_string($parsed) ? strtolower($parsed) : '';
    }

    /**
     * Unsigned request code the customer sends to the vendor for an offline licence.
     *
     * @param list<string> $addonIds
     */
    public function requestCode(array $addonIds): string
    {
        $addons = [];
        foreach ($addonIds as $id) {
            $id = trim((string) $id);
            if (preg_match('/^[a-z0-9][a-z0-9_.-]{2,80}$/', $id) === 1) {
                $addons[$id] = $id;
            }
        }
        $body = [
            'iid' => $this->instanceCode(),
            'host' => $this->host(),
            'core' => function_exists('app_version') ? app_version() : '',
            'addons' => array_values($addons),
            'nonce' => bin2hex(random_bytes(8)),
            'at' => gmdate('c'),
        ];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return 'SOCLY-REQ1.' . AddonKeys::b64u($json);
    }

    /** @return array<string,mixed>|null */
    public static function decodeRequestCode(string $code): ?array
    {
        $code = trim($code);
        if (!str_starts_with($code, 'SOCLY-REQ1.')) {
            return null;
        }
        $json = AddonKeys::b64uDecode(substr($code, strlen('SOCLY-REQ1.')));
        $payload = $json !== '' ? json_decode($json, true) : null;
        if (!is_array($payload) || preg_match('/^[a-f0-9]{16}$/', (string) ($payload['iid'] ?? '')) !== 1) {
            return null;
        }

        return $payload;
    }

    public function entitlement(): ?Entitlement
    {
        if ($this->isRevoked()) {
            return null;
        }
        $token = trim((string) $this->settings->get(self::SET_ENTITLEMENT, ''));
        if ($token === '') {
            return null;
        }
        $entitlement = Entitlement::verify($token);
        if ($entitlement === null || !$entitlement->matchesInstance($this->instanceCode())) {
            return null;
        }
        if ($entitlement->isExpired()) {
            return null;
        }
        $lastSeen = (int) $this->settings->get(self::SET_LAST_SEEN, '0');
        $now = time();
        if ($lastSeen > 0 && $now < $lastSeen - 86400) {
            // Clock moved backwards: keep the licence but do not boot add-ons.
            return null;
        }
        if ($now > $lastSeen) {
            $this->settings->set(self::SET_LAST_SEEN, (string) $now);
        }

        return $entitlement;
    }

    /** @return list<string> */
    public function licensedAddonIds(): array
    {
        return $this->entitlement()?->addonIds() ?? [];
    }

    /** @return array{licensed:bool, reason:string, license_id:string} */
    public function statusFor(string $addonId): array
    {
        if ($this->isRevoked()) {
            return ['licensed' => false, 'reason' => 'revoked', 'license_id' => ''];
        }
        $raw = trim((string) $this->settings->get(self::SET_ENTITLEMENT, ''));
        if ($raw === '') {
            return ['licensed' => false, 'reason' => 'missing', 'license_id' => ''];
        }
        $entitlement = Entitlement::verify($raw);
        if ($entitlement === null || !$entitlement->matchesInstance($this->instanceCode())) {
            return ['licensed' => false, 'reason' => 'invalid', 'license_id' => ''];
        }
        if ($entitlement->isExpired()) {
            return ['licensed' => false, 'reason' => 'expired', 'license_id' => $entitlement->licenseId()];
        }
        if (!$entitlement->hasAddon($addonId)) {
            return ['licensed' => false, 'reason' => 'not_licensed', 'license_id' => $entitlement->licenseId()];
        }

        return ['licensed' => true, 'reason' => 'active', 'license_id' => $entitlement->licenseId()];
    }

    public function licenseKey(): string
    {
        return trim((string) $this->settings->get(self::SET_LICENSE_KEY, ''));
    }

    public function isRevoked(): bool
    {
        return (string) $this->settings->get(self::SET_REVOKED, '0') === '1';
    }

    /**
     * Import an offline licence code (perpetual or trial) without any network call.
     *
     * @return array{ok:bool,error?:string,addons?:list<string>,license_id?:string}
     */
    public function import(string $code, string $ip): array
    {
        $code = trim($code);
        if (str_starts_with($code, 'SOCLY-KEY-')) {
            // The online licence key does not belong in the offline field.
            return ['ok' => false, 'error' => __('addons.error_code_is_online_key')];
        }
        $entitlement = Entitlement::verify($code);
        if ($entitlement === null) {
            return ['ok' => false, 'error' => __('addons.error_code_invalid')];
        }
        if (!$entitlement->matchesInstance($this->instanceCode())) {
            return ['ok' => false, 'error' => __('addons.error_code_instance')];
        }
        if ($entitlement->isExpired()) {
            return ['ok' => false, 'error' => __('addons.error_code_expired')];
        }
        $this->storeEntitlement($entitlement, $ip, 'addon.license_imported');

        return [
            'ok' => true,
            'addons' => $entitlement->addonIds(),
            'license_id' => $entitlement->licenseId(),
        ];
    }

    /**
     * Activate with a licence key through socly.it (online).
     *
     * @return array{ok:bool,error?:string,addons?:list<string>,license_id?:string}
     */
    public function activateOnline(string $licenseKey, string $ip): array
    {
        $licenseKey = trim($licenseKey);
        if (str_starts_with($licenseKey, 'SOCLY-ENT1')) {
            // The offline activation code is not the online licence key.
            return ['ok' => false, 'error' => __('addons.error_key_is_offline_code')];
        }
        if (strlen($licenseKey) < 16) {
            return ['ok' => false, 'error' => __('addons.error_key_invalid')];
        }
        $response = $this->post([
            'action' => 'activate',
            'token' => $this->platform->instanceToken(),
            'iid' => $this->instanceCode(),
            'license_key' => $licenseKey,
            'host' => $this->host(),
            'core_version' => function_exists('app_version') ? app_version() : '',
        ]);
        if (empty($response['ok'])) {
            return ['ok' => false, 'error' => (string) ($response['error'] ?? __('addons.error_online'))];
        }
        $token = trim((string) ($response['entitlement'] ?? ''));
        $entitlement = Entitlement::verify($token);
        if ($entitlement === null || !$entitlement->matchesInstance($this->instanceCode())) {
            return ['ok' => false, 'error' => __('addons.error_code_invalid')];
        }
        $this->settings->set(self::SET_LICENSE_KEY, $licenseKey, true);
        $this->storeEntitlement($entitlement, $ip, 'addon.license_activated');
        if (is_string($response['manifest'] ?? null) && is_string($response['signature'] ?? null)) {
            $this->storeManifest((string) $response['manifest'], (string) $response['signature']);
        }

        return [
            'ok' => true,
            'addons' => $entitlement->addonIds(),
            'license_id' => $entitlement->licenseId(),
        ];
    }

    /**
     * Ask socly.it whether the licence is still valid (revocation) and refresh it.
     *
     * @return array{ok:bool,error?:string,revoked?:bool,updated?:bool}
     */
    public function syncStatus(string $ip): array
    {
        $licenseKey = $this->licenseKey();
        if ($licenseKey === '') {
            return ['ok' => false, 'error' => __('addons.error_offline_only')];
        }
        $response = $this->post([
            'action' => 'status',
            'token' => $this->platform->instanceToken(),
            'iid' => $this->instanceCode(),
            'license_key' => $licenseKey,
            'core_version' => function_exists('app_version') ? app_version() : '',
        ]);
        if (empty($response['ok'])) {
            return ['ok' => false, 'error' => (string) ($response['error'] ?? __('addons.error_online'))];
        }
        if (!empty($response['revoked'])) {
            $this->revoke($ip, 'addon.license_revoked');
            return ['ok' => true, 'revoked' => true];
        }
        $updated = false;
        $token = trim((string) ($response['entitlement'] ?? ''));
        $entitlement = $token !== '' ? Entitlement::verify($token) : null;
        if ($entitlement !== null && $entitlement->matchesInstance($this->instanceCode())) {
            $this->storeEntitlement($entitlement, $ip, 'addon.license_refreshed');
            $updated = true;
        }
        if (is_string($response['manifest'] ?? null) && is_string($response['signature'] ?? null)) {
            $updated = $this->storeManifest((string) $response['manifest'], (string) $response['signature']) || $updated;
        }

        return ['ok' => true, 'revoked' => false, 'updated' => $updated];
    }

    /**
     * Signed manifest already verified and stored on this instance (no network).
     *
     * @return array<string,mixed>|null
     */
    public function cachedManifest(): ?array
    {
        $raw = trim((string) $this->settings->get(self::SET_MANIFEST, ''));
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Fetch the signed manifest of the add-on versions (latest compatible release).
     *
     * @return array{ok:bool,error?:string,manifest?:array<string,mixed>}
     */
    public function fetchManifest(string $ip): array
    {
        $cached = trim((string) $this->settings->get(self::SET_MANIFEST, ''));
        if ($cached !== '' && (time() - (int) $this->settings->get(self::SET_MANIFEST_AT, '0')) < 300) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return ['ok' => true, 'manifest' => $decoded];
            }
        }
        $licenseKey = $this->licenseKey();
        if ($licenseKey === '') {
            return ['ok' => false, 'error' => __('addons.error_offline_only')];
        }
        $response = $this->post([
            'action' => 'manifest',
            'token' => $this->platform->instanceToken(),
            'iid' => $this->instanceCode(),
            'license_key' => $licenseKey,
            'core_version' => function_exists('app_version') ? app_version() : '',
        ]);
        if (empty($response['ok']) || !is_string($response['manifest'] ?? null) || !is_string($response['signature'] ?? null)) {
            return ['ok' => false, 'error' => (string) ($response['error'] ?? __('addons.error_online'))];
        }
        $raw = (string) $response['manifest'];
        $signature = AddonKeys::b64uDecode((string) $response['signature']);
        $publicKey = AddonKeys::publicKey('license');
        if ($signature === '' || $publicKey === '') {
            return ['ok' => false, 'error' => __('addons.error_key_missing')];
        }
        try {
            if (!sodium_crypto_sign_verify_detached($signature, $raw, $publicKey)) {
                return ['ok' => false, 'error' => __('addons.error_manifest_signature')];
            }
        } catch (SodiumException) {
            return ['ok' => false, 'error' => __('addons.error_manifest_signature')];
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            return ['ok' => false, 'error' => __('addons.error_manifest_invalid')];
        }
        $this->storeManifest($raw, (string) $response['signature']);

        return ['ok' => true, 'manifest' => $manifest];
    }

    /** Download a signed package URL with the licence key (HTTPS only). */
    public function download(string $url, string $destination, string $licenseKey): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || !in_array($host, $this->allowedHosts(), true)) {
            return ['ok' => false, 'error' => __('addons.error_download_url')];
        }
        $fh = @fopen($destination, 'wb');
        if ($fh === false) {
            return ['ok' => false, 'error' => __('addons.error_storage')];
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                fclose($fh);
                return ['ok' => false, 'error' => __('addons.error_online')];
            }
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/octet-stream',
                    'Authorization: Bearer ' . $licenseKey,
                ],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_USERAGENT => 'SoclyAddons/1.0',
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fh);
            if ($ok === false || $status < 200 || $status >= 300) {
                @unlink($destination);
                return ['ok' => false, 'error' => __('addons.error_download')];
            }

            return ['ok' => true];
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/octet-stream\r\nAuthorization: Bearer " . $licenseKey . "\r\n",
                'timeout' => 60,
            ],
        ]);
        $data = @file_get_contents($url, false, $context);
        fclose($fh);
        if (!is_string($data)) {
            @unlink($destination);
            return ['ok' => false, 'error' => __('addons.error_download')];
        }
        file_put_contents($destination, $data);

        return ['ok' => true];
    }

    public function clear(string $ip, string $reason): void
    {
        $licenseId = $this->entitlement()?->licenseId() ?? '';
        $this->settings->set(self::SET_ENTITLEMENT, '');
        $this->settings->set(self::SET_LICENSE_KEY, '');
        $this->settings->set(self::SET_REVOKED, '0');
        $this->settings->set(self::SET_MANIFEST, '');
        $this->settings->set(self::SET_MANIFEST_SIG, '');
        $this->settings->set(self::SET_MANIFEST_AT, '0');
        if ($licenseId !== '') {
            $this->audit->log($reason, 'addon', $licenseId, null, null, $ip);
        }
    }

    private function revoke(string $ip, string $reason): void
    {
        $licenseId = $this->entitlement()?->licenseId() ?? '';
        $this->settings->set(self::SET_REVOKED, '1');
        $this->settings->set(self::SET_ENTITLEMENT, '');
        $this->settings->set(self::SET_MANIFEST, '');
        $this->settings->set(self::SET_MANIFEST_SIG, '');
        $this->audit->log($reason, 'addon', $licenseId !== '' ? $licenseId : 'license', null, null, $ip);
    }

    private function storeEntitlement(Entitlement $entitlement, string $ip, string $event): void
    {
        $this->settings->set(self::SET_ENTITLEMENT, $entitlement->raw(), true);
        $this->settings->set(self::SET_REVOKED, '0');
        $this->settings->set(self::SET_LAST_SEEN, (string) time());
        $this->audit->log($event, 'addon', $entitlement->licenseId(), null, [
            'addons' => $entitlement->addonIds(),
            'trial' => $entitlement->isTrial(),
        ], $ip);
    }

    private function storeManifest(string $raw, string $signature): bool
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return false;
        }
        $this->settings->set(self::SET_MANIFEST, $raw);
        $this->settings->set(self::SET_MANIFEST_SIG, $signature);
        $this->settings->set(self::SET_MANIFEST_AT, (string) time());

        return true;
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        $hosts = ['socly.it', 'www.socly.it'];
        $api = parse_url($this->apiUrl(), PHP_URL_HOST);
        if (is_string($api) && $api !== '') {
            $hosts[] = strtolower($api);
        }

        return array_values(array_unique($hosts));
    }

    private function apiUrl(): string
    {
        $override = trim((string) ($_ENV['SOCLY_ADDONS_API_URL'] ?? ''));
        if ($override !== '') {
            return $override;
        }

        return function_exists('socly_site_api_url') ? socly_site_api_url('/api/addons.php') : '';
    }

    /** @return array<string,mixed> */
    private function post(array $payload): array
    {
        $url = $this->apiUrl();
        if ($url === '') {
            return ['ok' => false, 'error' => __('addons.error_online')];
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'error' => __('addons.error_online')];
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'SoclyAddons/1.0',
            ]);
            $result = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $transport = curl_error($ch);
            curl_close($ch);
            if (!is_string($result) || $errno !== 0) {
                // DNS, TLS, refused connection or timeout: that is the only “unreachable”.
                return ['ok' => false, 'error' => __('addons.error_online'), 'transport' => $transport];
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                    'content' => $body,
                    'timeout' => 10,
                ],
            ]);
            $result = @file_get_contents($url, false, $context);
            if (!is_string($result)) {
                return ['ok' => false, 'error' => __('addons.error_online')];
            }
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $matches) === 1) {
                    $status = (int) $matches[1];
                }
            }
        }
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => __('addons.error_api_response', ['status' => (string) $status]), 'status' => $status];
        }
        if ($status >= 400 || empty($decoded['ok'])) {
            // socly.it answered: report its own reason instead of a fake “unreachable”.
            return [
                'ok' => false,
                'error' => $this->apiError((string) ($decoded['error'] ?? ''), $status),
                'status' => $status,
            ];
        }

        return $decoded;
    }

    /**
     * Turn a socly.it refusal into an actionable message. The site answers with a human
     * sentence in Italian; unmapped refusals keep the original detail plus the HTTP status.
     */
    private function apiError(string $message, int $status): string
    {
        $needle = mb_strtolower(trim($message));
        if ($needle === '') {
            return __('addons.error_api_response', ['status' => (string) $status]);
        }

        return match (true) {
            str_contains($needle, 'istanza non valida') => __('addons.error_instance_invalid'),
            str_contains($needle, 'altra installazione') => __('addons.error_license_other_instance'),
            str_contains($needle, 'revocata') => __('addons.error_license_revoked'),
            str_contains($needle, 'scaduta') => __('addons.error_license_expired'),
            str_contains($needle, 'non trovata'),
            str_contains($needle, 'non valida'),
            str_contains($needle, 'licenza mancante') => __('addons.error_license_not_found'),
            str_contains($needle, 'non licenziato'),
            str_contains($needle, 'non contiene add-on') => __('addons.error_addon_not_licensed'),
            default => __('addons.error_api_rejected', ['status' => (string) $status, 'detail' => trim($message)]),
        };
    }
}
