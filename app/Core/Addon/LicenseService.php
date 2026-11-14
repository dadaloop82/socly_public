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
    private const SET_ENTITLEMENTS = 'addons.entitlements';
    private const SET_LICENSE_KEY = 'addons.license_key';
    private const SET_LICENSE_KEYS = 'addons.license_keys';
    private const SET_REVOKED = 'addons.revoked';
    private const SET_LAST_SEEN = 'addons.license_last_seen';
    private const SET_MANIFEST = 'addons.manifest';
    private const SET_MANIFEST_SIG = 'addons.manifest_signature';
    private const SET_MANIFESTS = 'addons.manifests';
    private const SET_MANIFEST_AT = 'addons.manifest_fetched_at';

    /** @var list<Entitlement>|null memoised per request: statusFor() runs once per add-on */
    private ?array $entitlementsCache = null;

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

    /**
     * Every licence stored on this instance: one key = one licence = its own add-ons.
     * Tokens are verified locally (signature, installation binding and expiry); activating
     * a new key never replaces the licences that are already installed.
     *
     * @return list<Entitlement>
     */
    public function entitlements(): array
    {
        if ($this->entitlementsCache !== null) {
            return $this->entitlementsCache;
        }
        if ($this->clockMovedBack()) {
            // Clock moved backwards: keep the licences but do not boot add-ons.
            return $this->entitlementsCache = [];
        }
        $out = [];
        foreach ($this->storedTokens() as $token) {
            $entitlement = Entitlement::verify($token);
            if ($entitlement === null
                || $entitlement->licenseId() === ''
                || !$entitlement->matchesInstance($this->instanceCode())
                || $entitlement->isExpired()) {
                continue;
            }
            $out[] = $entitlement;
        }
        $this->touchLastSeen();

        return $this->entitlementsCache = $out;
    }

    /** First valid licence: kept for the callers that only need "is there any licence". */
    public function entitlement(): ?Entitlement
    {
        return $this->entitlements()[0] ?? null;
    }

    /** @return list<string> */
    public function licensedAddonIds(): array
    {
        $ids = [];
        foreach ($this->entitlements() as $entitlement) {
            foreach ($entitlement->addonIds() as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * One row per stored licence, for the settings page.
     *
     * @return list<array{id:string,addons:list<string>,trial:bool,expires_at:?int,key_present:bool}>
     */
    public function licenses(): array
    {
        $keys = $this->licenseKeys();
        $rows = [];
        foreach ($this->entitlements() as $entitlement) {
            $licenseId = $entitlement->licenseId();
            $rows[] = [
                'id' => $licenseId,
                'addons' => $entitlement->addonIds(),
                'trial' => $entitlement->isTrial(),
                'expires_at' => $entitlement->expiresAt(),
                'key_present' => isset($keys[$licenseId]),
            ];
        }

        return $rows;
    }

    /**
     * Add-on licence state. A licence covers only its own add-ons: with several licences
     * the add-on is active as soon as at least one of them lists it.
     *
     * @return array{licensed:bool, reason:string, license_id:string}
     */
    public function statusFor(string $addonId): array
    {
        $entitlements = $this->entitlements();
        foreach ($entitlements as $entitlement) {
            if ($entitlement->hasAddon($addonId)) {
                return ['licensed' => true, 'reason' => 'active', 'license_id' => $entitlement->licenseId()];
            }
        }
        if ($entitlements !== []) {
            return ['licensed' => false, 'reason' => 'not_licensed', 'license_id' => $entitlements[0]->licenseId()];
        }
        if ($this->isRevoked()) {
            return ['licensed' => false, 'reason' => 'revoked', 'license_id' => ''];
        }
        if ($this->clockMovedBack()) {
            return ['licensed' => false, 'reason' => 'invalid', 'license_id' => ''];
        }
        $tokens = $this->storedTokens();
        if ($tokens === []) {
            return ['licensed' => false, 'reason' => 'missing', 'license_id' => ''];
        }
        foreach ($tokens as $token) {
            $entitlement = Entitlement::verify($token);
            if ($entitlement !== null && $entitlement->matchesInstance($this->instanceCode())) {
                return [
                    'licensed' => false,
                    'reason' => $entitlement->isExpired() ? 'expired' : 'not_licensed',
                    'license_id' => $entitlement->licenseId(),
                ];
            }
        }

        return ['licensed' => false, 'reason' => 'invalid', 'license_id' => ''];
    }

    /** First online activation key (empty when the instance only has offline licences). */
    public function licenseKey(): string
    {
        $keys = $this->licenseKeys();

        return $keys === [] ? '' : (string) reset($keys);
    }

    /**
     * Activation key of the licence that covers the add-on: packages are downloaded with
     * the key of their own licence, never with a key that does not grant them.
     */
    public function licenseKeyFor(string $addonId): string
    {
        $keys = $this->licenseKeys();
        foreach ($this->entitlements() as $entitlement) {
            if ($entitlement->hasAddon($addonId)) {
                return $keys[$entitlement->licenseId()] ?? '';
            }
        }

        return '';
    }

    /**
     * Licence id => activation key (online licences only; offline codes have no key).
     *
     * @return array<string,string>
     */
    public function licenseKeys(): array
    {
        $keys = $this->decodeMap(self::SET_LICENSE_KEYS);
        $legacy = trim((string) $this->settings->get(self::SET_LICENSE_KEY, ''));
        if ($legacy !== '') {
            $legacyToken = trim((string) $this->settings->get(self::SET_ENTITLEMENT, ''));
            $licenseId = $legacyToken !== '' ? (Entitlement::verify($legacyToken)?->licenseId() ?? '') : '';
            if ($licenseId !== '' && !isset($keys[$licenseId])) {
                // Single-licence installs written by a core without the multi-licence map.
                $keys[$licenseId] = $legacy;
            }
        }

        return $keys;
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
        // An offline code adds/refreshes its own licence and keeps the others untouched.
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
        // The new licence is added to the stored ones: activating a second key must not
        // disable the add-ons granted by the first (each key covers its own add-ons).
        $this->storeEntitlement($entitlement, $ip, 'addon.license_activated', $licenseKey);
        if (is_string($response['manifest'] ?? null) && is_string($response['signature'] ?? null)) {
            $this->storeManifest($entitlement->licenseId(), (string) $response['manifest'], (string) $response['signature']);
        }

        return [
            'ok' => true,
            'addons' => $entitlement->addonIds(),
            'license_id' => $entitlement->licenseId(),
        ];
    }

    /**
     * Ask socly.it whether every stored licence is still valid (revocation) and refresh it.
     * Only the revoked licence is dropped: the other keys keep working.
     *
     * @return array{ok:bool,error?:string,revoked?:bool,updated?:bool,errors?:list<string>}
     */
    public function syncStatus(string $ip): array
    {
        $keys = $this->licenseKeys();
        if ($keys === []) {
            return ['ok' => false, 'error' => __('addons.error_offline_only')];
        }
        $updated = false;
        $revokedAny = false;
        $errors = [];
        foreach ($keys as $licenseId => $licenseKey) {
            $response = $this->post([
                'action' => 'status',
                'token' => $this->platform->instanceToken(),
                'iid' => $this->instanceCode(),
                'license_key' => $licenseKey,
                'core_version' => function_exists('app_version') ? app_version() : '',
            ]);
            if (empty($response['ok'])) {
                $errors[] = (string) ($response['error'] ?? __('addons.error_online'));
                continue;
            }
            if (!empty($response['revoked'])) {
                $this->dropLicense($licenseId, $ip, 'addon.license_revoked', true);
                $revokedAny = true;
                continue;
            }
            $token = trim((string) ($response['entitlement'] ?? ''));
            $entitlement = $token !== '' ? Entitlement::verify($token) : null;
            if ($entitlement !== null && $entitlement->matchesInstance($this->instanceCode())) {
                $this->storeEntitlement($entitlement, $ip, 'addon.license_refreshed', $licenseKey);
                $updated = true;
            }
            if (is_string($response['manifest'] ?? null) && is_string($response['signature'] ?? null)) {
                $updated = $this->storeManifest(
                    $licenseId,
                    (string) $response['manifest'],
                    (string) $response['signature']
                ) || $updated;
            }
        }
        if ($errors !== [] && !$updated && !$revokedAny) {
            return ['ok' => false, 'error' => $errors[0], 'errors' => $errors];
        }

        return [
            'ok' => true,
            // “All revoked” is the only state that must stop the add-ons: one revoked licence
            // while another one is still active must not disable the rest.
            'revoked' => $revokedAny && $this->licenseKeys() === [],
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /**
     * Signed manifests of every stored licence, merged by add-on (no network).
     *
     * @return array<string,mixed>|null
     */
    public function cachedManifest(): ?array
    {
        $manifests = [];
        foreach ($this->storedManifests() as $row) {
            $decoded = json_decode((string) ($row['raw'] ?? ''), true);
            if (is_array($decoded)) {
                $manifests[] = $decoded;
            }
        }
        if ($manifests === []) {
            // Manifest cached by a core without the per-licence map.
            $raw = trim((string) $this->settings->get(self::SET_MANIFEST, ''));
            $decoded = $raw !== '' ? json_decode($raw, true) : null;

            return is_array($decoded) ? $decoded : null;
        }

        return $this->mergeManifests($manifests);
    }

    /**
     * Fetch the signed manifest of the add-on versions (latest compatible release) for
     * every stored licence and merge the catalogues.
     *
     * @return array{ok:bool,error?:string,manifest?:array<string,mixed>}
     */
    public function fetchManifest(string $ip): array
    {
        $keys = $this->licenseKeys();
        if ($keys === []) {
            $cached = $this->cachedManifest();

            return $cached !== null
                ? ['ok' => true, 'manifest' => $cached]
                : ['ok' => false, 'error' => __('addons.error_offline_only')];
        }
        $stored = $this->storedManifests();
        $manifests = [];
        $errors = [];
        foreach ($keys as $licenseId => $licenseKey) {
            $cached = $stored[$licenseId] ?? null;
            if (is_array($cached) && (time() - (int) ($cached['at'] ?? 0)) < 300) {
                $decoded = json_decode((string) ($cached['raw'] ?? ''), true);
                if (is_array($decoded)) {
                    $manifests[] = $decoded;
                    continue;
                }
            }
            $response = $this->post([
                'action' => 'manifest',
                'token' => $this->platform->instanceToken(),
                'iid' => $this->instanceCode(),
                'license_key' => $licenseKey,
                'core_version' => function_exists('app_version') ? app_version() : '',
            ]);
            if (empty($response['ok']) || !is_string($response['manifest'] ?? null) || !is_string($response['signature'] ?? null)) {
                $errors[] = (string) ($response['error'] ?? __('addons.error_online'));
                continue;
            }
            $raw = (string) $response['manifest'];
            $signature = AddonKeys::b64uDecode((string) $response['signature']);
            $publicKey = AddonKeys::publicKey('license');
            if ($signature === '' || $publicKey === '') {
                $errors[] = (string) __('addons.error_key_missing');
                continue;
            }
            try {
                if (!sodium_crypto_sign_verify_detached($signature, $raw, $publicKey)) {
                    $errors[] = (string) __('addons.error_manifest_signature');
                    continue;
                }
            } catch (SodiumException) {
                $errors[] = (string) __('addons.error_manifest_signature');
                continue;
            }
            $manifest = json_decode($raw, true);
            if (!is_array($manifest)) {
                $errors[] = (string) __('addons.error_manifest_invalid');
                continue;
            }
            $this->storeManifest($licenseId, $raw, (string) $response['signature']);
            $manifests[] = $manifest;
        }
        if ($manifests === []) {
            $cached = $this->cachedManifest();
            if ($cached !== null) {
                return ['ok' => true, 'manifest' => $cached];
            }

            return ['ok' => false, 'error' => $errors[0] ?? __('addons.error_online')];
        }

        return ['ok' => true, 'manifest' => $this->mergeManifests($manifests)];
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

    /**
     * Remove one stored licence. The add-ons it granted stay installed but stop booting;
     * the other licences keep working.
     *
     * @return array{ok:bool,error?:string}
     */
    public function removeLicense(string $licenseId, string $ip): array
    {
        $licenseId = trim($licenseId);
        if ($licenseId === '' || !isset($this->storedTokens()[$licenseId])) {
            return ['ok' => false, 'error' => __('errors.404')];
        }
        $this->dropLicense($licenseId, $ip, 'addon.license_removed');

        return ['ok' => true];
    }

    public function clear(string $ip, string $reason): void
    {
        $this->entitlementsCache = null;
        $licenseIds = array_keys($this->storedTokens());
        $this->settings->set(self::SET_ENTITLEMENTS, '');
        $this->settings->set(self::SET_LICENSE_KEYS, '');
        $this->settings->set(self::SET_ENTITLEMENT, '');
        $this->settings->set(self::SET_LICENSE_KEY, '');
        $this->settings->set(self::SET_REVOKED, '0');
        $this->settings->set(self::SET_MANIFEST, '');
        $this->settings->set(self::SET_MANIFEST_SIG, '');
        $this->settings->set(self::SET_MANIFESTS, '');
        $this->settings->set(self::SET_MANIFEST_AT, '0');
        foreach ($licenseIds as $licenseId) {
            $this->audit->log($reason, 'addon', $licenseId, null, null, $ip);
        }
    }

    /**
     * Drop a single licence (revoked by socly.it or removed by the administrator) and keep
     * the legacy single-value settings aligned with a licence that is still valid.
     */
    private function dropLicense(string $licenseId, string $ip, string $event, bool $revoked = false): void
    {
        $this->entitlementsCache = null;
        $tokens = $this->storedTokens();
        $keys = $this->licenseKeys();
        $manifests = $this->storedManifests();
        unset($tokens[$licenseId], $keys[$licenseId], $manifests[$licenseId]);
        $this->storeMap(self::SET_ENTITLEMENTS, $tokens, true);
        $this->storeMap(self::SET_LICENSE_KEYS, $keys, true);
        if ($manifests === []) {
            $this->settings->set(self::SET_MANIFESTS, '');
            // Do not fall back to a single-licence catalogue that may belong to the removed licence.
            $this->settings->set(self::SET_MANIFEST, '');
            $this->settings->set(self::SET_MANIFEST_SIG, '');
        } else {
            $this->storeMap(self::SET_MANIFESTS, $manifests);
        }
        $mirror = null;
        foreach ($tokens as $token) {
            $candidate = Entitlement::verify($token);
            if ($candidate !== null) {
                $mirror = $candidate;
                break;
            }
        }
        $this->settings->set(self::SET_ENTITLEMENT, $mirror?->raw() ?? '', $mirror !== null);
        $mirrorKey = $mirror !== null ? ($keys[$mirror->licenseId()] ?? '') : '';
        $this->settings->set(self::SET_LICENSE_KEY, $mirrorKey, $mirrorKey !== '');
        if ($tokens === [] && $revoked) {
            $this->settings->set(self::SET_REVOKED, '1');
        }
        $this->audit->log($event, 'addon', $licenseId, null, null, $ip);
    }

    private function storeEntitlement(
        Entitlement $entitlement,
        string $ip,
        string $event,
        ?string $licenseKey = null
    ): void {
        $this->entitlementsCache = null;
        $licenseId = $entitlement->licenseId();
        // storedTokens() also carries the licence written by a core without the map:
        // the first save after the upgrade must not drop it.
        $tokens = $this->storedTokens();
        $tokens[$licenseId] = $entitlement->raw();
        $this->storeMap(self::SET_ENTITLEMENTS, $tokens, true);
        $keys = $this->licenseKeys();
        if ($licenseKey !== null && $licenseKey !== '') {
            $keys[$licenseId] = $licenseKey;
        }
        if ($keys !== []) {
            $this->storeMap(self::SET_LICENSE_KEYS, $keys, true);
        }
        // Single-value mirrors, kept for older readers and the “has a key” checks.
        $this->settings->set(self::SET_ENTITLEMENT, $entitlement->raw(), true);
        $mirrorKey = $keys[$licenseId] ?? '';
        $this->settings->set(self::SET_LICENSE_KEY, $mirrorKey, $mirrorKey !== '');
        $this->settings->set(self::SET_REVOKED, '0');
        $this->settings->set(self::SET_LAST_SEEN, (string) time());
        $this->audit->log($event, 'addon', $licenseId, null, [
            'addons' => $entitlement->addonIds(),
            'trial' => $entitlement->isTrial(),
        ], $ip);
    }

    private function storeManifest(string $licenseId, string $raw, string $signature): bool
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $licenseId === '') {
            return false;
        }
        $manifests = $this->storedManifests();
        $manifests[$licenseId] = [
            'raw' => $raw,
            'signature' => $signature,
            'at' => time(),
        ];
        $this->storeMap(self::SET_MANIFESTS, $manifests);
        $this->settings->set(self::SET_MANIFEST_AT, (string) time());

        return true;
    }

    /**
     * Stored signed tokens (licence id => token). Not verified here: one broken token must
     * never hide the others.
     *
     * @return array<string,string>
     */
    private function storedTokens(): array
    {
        $tokens = $this->decodeMap(self::SET_ENTITLEMENTS);
        $legacy = trim((string) $this->settings->get(self::SET_ENTITLEMENT, ''));
        if ($legacy !== '') {
            $licenseId = Entitlement::verify($legacy)?->licenseId() ?? '';
            if ($licenseId !== '' && !isset($tokens[$licenseId])) {
                // Licence imported by a core that only knew the single-licence setting.
                $tokens[$licenseId] = $legacy;
            }
        }

        return $tokens;
    }

    /** @return array<string,array{raw:string,signature:string,at:int}> */
    private function storedManifests(): array
    {
        $raw = trim((string) $this->settings->get(self::SET_MANIFESTS, ''));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $licenseId => $row) {
            if (!is_string($licenseId) || !is_array($row) || !is_string($row['raw'] ?? null)) {
                continue;
            }
            $out[$licenseId] = [
                'raw' => (string) $row['raw'],
                'signature' => (string) ($row['signature'] ?? ''),
                'at' => (int) ($row['at'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Merge the per-licence catalogues: the same add-on keeps the highest advertised version.
     *
     * @param list<array<string,mixed>> $manifests
     * @return array<string,mixed>
     */
    private function mergeManifests(array $manifests): array
    {
        $merged = ['v' => 1, 'generated_at' => gmdate('c'), 'addons' => []];
        foreach ($manifests as $manifest) {
            foreach (is_array($manifest['addons'] ?? null) ? $manifest['addons'] : [] as $id => $meta) {
                if (!is_string($id) || !is_array($meta)) {
                    continue;
                }
                $current = $merged['addons'][$id] ?? null;
                if (is_array($current)
                    && version_compare(
                        (string) ($current['latest'] ?? '0'),
                        (string) ($meta['latest'] ?? '0'),
                        '>='
                    )) {
                    continue;
                }
                $merged['addons'][$id] = $meta;
            }
        }

        return $merged;
    }

    /** @return array<string,string> */
    private function decodeMap(string $setting): array
    {
        $raw = trim((string) $this->settings->get($setting, ''));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && $key !== '' && is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $map */
    private function storeMap(string $setting, array $map, bool $encrypted = false): void
    {
        if ($map === []) {
            $this->settings->set($setting, '');

            return;
        }
        $encoded = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->settings->set($setting, is_string($encoded) ? $encoded : '{}', $encrypted);
    }

    /** Refuse to grant anything when the clock moved backwards (licences are time-bound). */
    private function clockMovedBack(): bool
    {
        $lastSeen = (int) $this->settings->get(self::SET_LAST_SEEN, '0');

        return $lastSeen > 0 && time() < $lastSeen - 86400;
    }

    private function touchLastSeen(): void
    {
        $now = time();
        if ($now > (int) $this->settings->get(self::SET_LAST_SEEN, '0')) {
            $this->settings->set(self::SET_LAST_SEEN, (string) $now);
        }
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
