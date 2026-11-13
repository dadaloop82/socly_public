<?php

declare(strict_types=1);

namespace Socly\Core\Addon;

use Socly\Core\Database;
use Socly\Core\Migrator;
use Socly\Core\Plugin\PluginInterface;
use Socly\Services\AuditService;
use Socly\Services\SettingsService;

/**
 * Installed add-on packages: lifecycle, enable/disable, updates and plugin discovery.
 *
 * Packages live in storage/addons/<id>/<version>/ and are only booted when the signed
 * licence grants the add-on; the `plugins` table keeps the enable/disable state so the
 * existing plugin machinery (menus, routes, settings, migrations) keeps working.
 */
final class AddonManager
{
    /** Minimum interval between automatic checks/downloads (seconds). */
    private const AUTO_CHECK_TTL = 3600;
    private const SET_AUTO_CHECK_AT = 'addons.auto_check_at';
    private const SET_PENDING = 'addons.pending';

    public function __construct(
        private readonly Database $db,
        private readonly LicenseService $license,
        private readonly AddonPackage $package,
        private readonly Migrator $migrator,
        private readonly AuditService $audit,
        private readonly SettingsService $settings
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function installed(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM addons ORDER BY id ASC');
        $plugins = [];
        foreach ($this->db->fetchAll('SELECT id, is_enabled FROM plugins') as $plugin) {
            $plugins[(string) $plugin['id']] = !empty($plugin['is_enabled']);
        }
        $out = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $status = $this->license->statusFor($id);
            $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
            $meta = is_array($meta) ? $meta : [];
            $entry = $this->entryPath($id, (string) $row['version'], (string) ($meta['entry'] ?? 'plugin.php'));
            $out[] = $row + [
                'name' => (string) ($meta['name'] ?? $id),
                'entry' => (string) ($meta['entry'] ?? 'plugin.php'),
                'entry_exists' => is_file($entry),
                'is_enabled' => !empty($plugins[$id]),
                'licensed' => $status['licensed'],
                'license_reason' => $status['reason'],
                'license_id' => $status['license_id'],
                'versions' => $this->package->versions($id),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->installed() as $row) {
            if ((string) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Entry files of the add-ons that may boot: installed, enabled and covered by the licence.
     *
     * @return list<string>
     */
    public function pluginFiles(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM addons WHERE status = \'active\'');
        $enabled = [];
        foreach ($this->db->fetchAll('SELECT id FROM plugins WHERE is_enabled = 1') as $plugin) {
            $enabled[(string) $plugin['id']] = true;
        }
        $files = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if (!isset($enabled[$id]) || !$this->license->statusFor($id)['licensed']) {
                continue;
            }
            $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
            $meta = is_array($meta) ? $meta : [];
            $entry = $this->entryPath($id, (string) $row['version'], (string) ($meta['entry'] ?? 'plugin.php'));
            if (is_file($entry)) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /**
     * Packages dropped in storage/addons/incoming, verified and merged with the
     * install/licence state so the settings page can propose them (no upload).
     *
     * @return list<array{
     *   file:string,ok:bool,error:string,id:string,name:string,version:string,min_core:string,
     *   size:int,sha256:string,installed_version:string,licensed:bool,update:bool,can_install:bool
     * }>
     */
    public function discoverIncoming(int $limit = 100): array
    {
        $installed = [];
        foreach ($this->db->fetchAll('SELECT id, version FROM addons') as $row) {
            $installed[(string) $row['id']] = (string) $row['version'];
        }
        $out = [];
        foreach ($this->package->scanIncoming($limit) as $entry) {
            $manifest = is_array($entry['manifest'] ?? null) ? $entry['manifest'] : [];
            $id = (string) ($manifest['id'] ?? '');
            $version = (string) ($manifest['version'] ?? '');
            $installedVersion = $installed[$id] ?? '';
            $licensed = $id !== '' && !empty($this->license->statusFor($id)['licensed']);
            $update = $installedVersion !== '' && $version !== '' && version_compare($version, $installedVersion, '>');
            $out[] = [
                'file' => (string) $entry['file'],
                'ok' => (bool) $entry['ok'],
                'error' => (string) ($entry['error'] ?? ''),
                'id' => $id,
                'name' => (string) ($manifest['name'] ?? $id),
                'version' => $version,
                'min_core' => (string) ($manifest['min_core'] ?? ''),
                'size' => (int) $entry['size'],
                'sha256' => (string) $entry['sha256'],
                'installed_version' => $installedVersion,
                'licensed' => $licensed,
                'update' => $update,
                'can_install' => (bool) $entry['ok'] && $licensed && ($installedVersion === '' || $update),
            ];
        }

        return $out;
    }

    /**
     * Install a package already on disk (incoming folder), keeping the file.
     *
     * @return array{ok:bool,error?:string,id?:string,version?:string,replaced?:bool}
     */
    public function installFromIncoming(string $file, string $ip): array
    {
        $path = $this->package->resolveIncomingFile($file);
        if ($path === null) {
            return ['ok' => false, 'error' => __('addons.error_package_missing')];
        }

        return $this->installFromPath($path, $ip);
    }

    /** @return array{ok:bool,error?:string,id?:string,version?:string,replaced?:bool} */
    private function installFromPath(string $path, string $ip): array
    {
        $inspected = $this->package->inspect($path);
        if (empty($inspected['ok']) || !is_array($inspected['manifest'] ?? null)) {
            return ['ok' => false, 'error' => (string) ($inspected['error'] ?? __('addons.error_package_invalid'))];
        }
        // The file stays in the incoming folder: it is the administrator's copy.
        $inspected['staged'] = $path;

        return $this->installInspected($inspected, $ip);
    }

    /**
     * Add-ons granted by the licence, read from the signed manifest cached on the
     * instance (no network call on page load: «Cerca aggiornamenti» refreshes it).
     *
     * @return array{ok:bool,error?:string,rows:list<array{
     *   id:string,latest:string,min_core:string,installed_version:string,
     *   licensed:bool,compatible:bool,update:bool,can_install:bool
     * }>}
     */
    public function availableFromLicence(): array
    {
        $manifest = $this->license->cachedManifest();
        if ($manifest === null) {
            return ['ok' => false, 'rows' => []];
        }
        $addons = is_array($manifest['addons'] ?? null) ? $manifest['addons'] : [];
        $installed = [];
        foreach ($this->db->fetchAll('SELECT id, version FROM addons') as $row) {
            $installed[(string) $row['id']] = (string) $row['version'];
        }
        $core = function_exists('app_version') ? app_version() : '';
        $rows = [];
        foreach ($addons as $id => $meta) {
            $id = (string) $id;
            if (preg_match('/^[a-z0-9][a-z0-9_.-]{2,80}$/', $id) !== 1 || !is_array($meta)) {
                continue;
            }
            $latest = (string) ($meta['latest'] ?? '');
            if (preg_match('/^\d+\.\d+\.\d+/', $latest) !== 1) {
                continue;
            }
            $minCore = (string) ($meta['min_core'] ?? '');
            $installedVersion = $installed[$id] ?? '';
            $compatible = $minCore === '' || $core === '' || version_compare($minCore, $core, '<=');
            $update = $installedVersion !== '' && version_compare($latest, $installedVersion, '>');
            $rows[] = [
                'id' => $id,
                'latest' => $latest,
                'min_core' => $minCore,
                'installed_version' => $installedVersion,
                'licensed' => !empty($this->license->statusFor($id)['licensed']),
                'compatible' => $compatible,
                'update' => $update,
                'can_install' => $compatible && ($installedVersion === '' || $update),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return ['ok' => true, 'rows' => $rows];
    }

    /**
     * Install every add-on granted by the licence that is missing or outdated.
     *
     * Runs right after an online activation, when the Add-ons page is opened (at most
     * once per hour) and from `bin/addons-update.php` (cron). Only signed packages from
     * the licence catalogue are downloaded; offline licences cannot download and must
     * use the drop folder instead.
     *
     * @return array{
     *   ok:bool,
     *   reason?:string,
     *   installed:list<array{id:string,from:string,to:string}>,
     *   pending:list<array{id:string,min_core:string}>,
     *   errors:list<string>
     * }
     */
    public function autoInstall(string $ip, bool $force = false): array
    {
        $result = ['ok' => false, 'installed' => [], 'pending' => [], 'errors' => []];
        if ($this->license->licenseKey() === '') {
            return $result + ['reason' => 'offline'];
        }
        $last = (int) $this->settings->get(self::SET_AUTO_CHECK_AT, '0');
        if (!$force && $last > 0 && (time() - $last) < self::AUTO_CHECK_TTL) {
            return ['ok' => true, 'reason' => 'cached', 'installed' => [], 'pending' => $this->pendingFromSettings(), 'errors' => []];
        }
        $this->settings->set(self::SET_AUTO_CHECK_AT, (string) time());

        $manifestResult = $this->license->fetchManifest($ip);
        if (empty($manifestResult['ok']) || !is_array($manifestResult['manifest'] ?? null)) {
            return $result + [
                'reason' => 'catalogue',
                'errors' => [(string) ($manifestResult['error'] ?? __('addons.error_online'))],
            ];
        }
        $manifest = $manifestResult['manifest'];
        $core = function_exists('app_version') ? app_version() : '';
        $installed = [];
        foreach ($this->db->fetchAll('SELECT id, version FROM addons') as $row) {
            $installed[(string) $row['id']] = (string) $row['version'];
        }

        $installedNow = [];
        $pending = [];
        $errors = [];
        foreach (is_array($manifest['addons'] ?? null) ? $manifest['addons'] : [] as $id => $meta) {
            $id = (string) $id;
            if (preg_match('/^[a-z0-9][a-z0-9_.-]{2,80}$/', $id) !== 1
                || !is_array($meta)
                || empty($this->license->statusFor($id)['licensed'])) {
                continue;
            }
            $current = $installed[$id] ?? '';
            $target = null;
            $blockedMin = '';
            foreach (is_array($meta['versions'] ?? null) ? $meta['versions'] : [] as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $version = trim((string) ($candidate['version'] ?? ''));
                $minCore = trim((string) ($candidate['min_core'] ?? ''));
                if ($version === '' || preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
                    continue;
                }
                if ($minCore !== '' && $core !== '' && version_compare($minCore, $core, '>')) {
                    if ($blockedMin === '' || version_compare($minCore, $blockedMin, '<')) {
                        $blockedMin = $minCore;
                    }
                    continue;
                }
                if ($target === null || version_compare($version, (string) $target['version'], '>')) {
                    $target = $candidate;
                }
            }
            if ($target === null) {
                if ($current === '' && $blockedMin !== '') {
                    $pending[] = ['id' => $id, 'min_core' => $blockedMin];
                }
                continue;
            }
            $targetVersion = (string) $target['version'];
            if ($current !== '' && version_compare($targetVersion, $current, '<=')) {
                continue;
            }
            $install = $this->installFromLicence($id, $targetVersion, $ip);
            if (empty($install['ok'])) {
                $errors[] = $id . ': ' . (string) ($install['error'] ?? __('addons.error_generic'));
                continue;
            }
            $newVersion = (string) ($install['version'] ?? $targetVersion);
            $installedNow[] = ['id' => $id, 'from' => $current, 'to' => $newVersion];
            $this->audit->log('addon.auto_installed', 'addon', $id, null, [
                'from' => $current,
                'to' => $newVersion,
            ], $ip);
        }
        $this->settings->set(self::SET_PENDING, json_encode($pending, JSON_UNESCAPED_UNICODE) ?: '[]');

        return [
            'ok' => true,
            'installed' => $installedNow,
            'pending' => $pending,
            'errors' => $errors,
        ];
    }

    /** @return list<array{id:string,min_core:string}> */
    private function pendingFromSettings(): array
    {
        $raw = trim((string) $this->settings->get(self::SET_PENDING, '[]'));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * Download a licensed add-on from the signed manifest and install it.
     *
     * @return array{ok:bool,error?:string,id?:string,version?:string,replaced?:bool}
     */
    public function installFromLicence(string $id, string $version, string $ip): array
    {
        $id = strtolower(trim($id));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{2,80}$/', $id) !== 1) {
            return ['ok' => false, 'error' => __('addons.error_package_invalid')];
        }
        if (empty($this->license->statusFor($id)['licensed'])) {
            return ['ok' => false, 'error' => __('addons.license_required_for', ['addon' => $id])];
        }
        $licenseKey = $this->license->licenseKey();
        if ($licenseKey === '') {
            return ['ok' => false, 'error' => __('addons.error_offline_only')];
        }
        $manifestResult = $this->license->fetchManifest($ip);
        if (empty($manifestResult['ok']) || !is_array($manifestResult['manifest'] ?? null)) {
            return ['ok' => false, 'error' => (string) ($manifestResult['error'] ?? __('addons.error_online'))];
        }
        $meta = is_array($manifestResult['manifest']['addons'][$id] ?? null)
            ? $manifestResult['manifest']['addons'][$id]
            : null;
        if ($meta === null) {
            return ['ok' => false, 'error' => __('addons.error_manifest_invalid')];
        }
        $core = function_exists('app_version') ? app_version() : '';
        $requested = trim($version);
        $target = null;
        foreach (is_array($meta['versions'] ?? null) ? $meta['versions'] : [] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateVersion = trim((string) ($candidate['version'] ?? ''));
            $candidateMin = trim((string) ($candidate['min_core'] ?? ''));
            if ($candidateVersion === ''
                || ($candidateMin !== '' && $core !== '' && version_compare($candidateMin, $core, '>'))
                || ($requested !== '' && $candidateVersion !== $requested)) {
                continue;
            }
            if ($target === null || version_compare($candidateVersion, (string) $target['version'], '>')) {
                $target = $candidate;
            }
        }
        if ($target === null) {
            return ['ok' => false, 'error' => __('addons.error_manifest_invalid')];
        }
        $latest = (string) $target['version'];
        $url = (string) ($target['url'] ?? '');
        $sha = (string) ($target['sha256'] ?? '');
        if ($url === '' || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            return ['ok' => false, 'error' => __('addons.error_manifest_invalid')];
        }
        $tmpDir = $this->package->installRoot() . '/.incoming';
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0750, true) && !is_dir($tmpDir)) {
            return ['ok' => false, 'error' => __('addons.error_storage')];
        }
        $tmp = $tmpDir . '/remote-' . $id . '-' . $latest . '-' . bin2hex(random_bytes(4)) . '.soclyaddon';
        $download = $this->license->download($url, $tmp, $licenseKey);
        if (empty($download['ok'])) {
            @unlink($tmp);
            return ['ok' => false, 'error' => (string) ($download['error'] ?? __('addons.error_download'))];
        }
        if (!hash_equals($sha, (string) hash_file('sha256', $tmp))) {
            @unlink($tmp);
            return ['ok' => false, 'error' => __('addons.error_file_hash', ['file' => $latest])];
        }
        $inspected = $this->package->inspect($tmp);
        if (empty($inspected['ok'])
            || (string) ($inspected['manifest']['id'] ?? '') !== $id
            || (string) ($inspected['manifest']['version'] ?? '') !== $latest) {
            @unlink($tmp);
            return ['ok' => false, 'error' => (string) ($inspected['error'] ?? __('addons.error_manifest_invalid'))];
        }
        $inspected['staged'] = $tmp;
        $install = $this->installInspected($inspected, $ip);
        @unlink($tmp);

        return $install;
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{ok:bool,error?:string,id?:string,version?:string,replaced?:bool}
     */
    public function installUpload(array $file, string $ip): array
    {
        $inspected = $this->package->inspectUpload($file);
        if (empty($inspected['ok'])) {
            return ['ok' => false, 'error' => (string) ($inspected['error'] ?? __('addons.error_package_invalid'))];
        }
        $staged = (string) ($inspected['staged'] ?? '');
        $result = $this->installInspected($inspected, $ip);
        if ($staged !== '' && is_file($staged)) {
            @unlink($staged);
        }

        return $result;
    }

    /**
     * @param array{ok:bool,manifest?:array<string,mixed>,sha256?:string} $inspected
     * @return array{ok:bool,error?:string,id?:string,version?:string,replaced?:bool}
     */
    private function installInspected(array $inspected, string $ip): array
    {
        $manifest = $inspected['manifest'] ?? null;
        if (!is_array($manifest)) {
            return ['ok' => false, 'error' => __('addons.error_manifest_invalid')];
        }
        $id = (string) $manifest['id'];
        $status = $this->license->statusFor($id);
        if (!$status['licensed']) {
            return ['ok' => false, 'error' => __('addons.license_required_for', ['addon' => $id])];
        }
        $zip = (string) ($inspected['staged'] ?? '');
        if ($zip === '') {
            return ['ok' => false, 'error' => __('addons.error_storage')];
        }
        $install = $this->package->install($zip, $manifest);
        if (empty($install['ok'])) {
            return ['ok' => false, 'error' => (string) ($install['error'] ?? __('addons.error_storage'))];
        }
        $this->storeRow($id, $manifest, (string) ($inspected['sha256'] ?? ''), $status['license_id']);
        $enable = $this->activate($id, $manifest, $ip);
        if (empty($enable['ok'])) {
            return $enable;
        }

        return [
            'ok' => true,
            'id' => $id,
            'version' => (string) $manifest['version'],
            'replaced' => !empty($install['replaced']),
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{ok:bool,error?:string}
     */
    private function activate(string $id, array $manifest, string $ip, string $event = 'addon.enabled'): array
    {
        $version = (string) ($manifest['version'] ?? '');
        $entry = $this->entryPath($id, $version, (string) ($manifest['entry'] ?? 'plugin.php'));
        $plugin = $this->pluginFromEntry($id, $entry);
        if (is_string($plugin)) {
            return ['ok' => false, 'error' => $plugin];
        }
        try {
            $this->migrator->runPluginSql($id, $plugin->migrations());
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('addons.error_migrations', ['detail' => $e->getMessage()])];
        }
        $this->setPluginEnabled($id, true, $plugin->version());
        $this->db->query(
            'UPDATE addons SET status = \'active\', updated_at = NOW() WHERE id = :id',
            ['id' => $id]
        );
        $this->audit->log($event, 'addon', $id, null, ['version' => $version], $ip);

        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public function enable(string $id, string $ip): array
    {
        $row = $this->db->fetch('SELECT * FROM addons WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return ['ok' => false, 'error' => __('errors.404')];
        }
        if (!$this->license->statusFor($id)['licensed']) {
            return ['ok' => false, 'error' => __('addons.license_required_for', ['addon' => $id])];
        }
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
        $meta = is_array($meta) ? $meta : [];

        return $this->activate($id, [
            'version' => (string) $row['version'],
            'entry' => (string) ($meta['entry'] ?? 'plugin.php'),
        ], $ip);
    }

    /** @return array{ok:bool,error?:string} */
    public function disable(string $id, string $ip): array
    {
        $row = $this->db->fetch('SELECT * FROM addons WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return ['ok' => false, 'error' => __('errors.404')];
        }
        $this->setPluginEnabled($id, false, (string) $row['version']);
        $this->db->query('UPDATE addons SET status = \'disabled\', updated_at = NOW() WHERE id = :id', ['id' => $id]);
        $this->audit->log('addon.disabled', 'addon', $id, null, ['version' => (string) $row['version']], $ip);

        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public function remove(string $id, string $ip): array
    {
        $row = $this->db->fetch('SELECT * FROM addons WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return ['ok' => false, 'error' => __('errors.404')];
        }
        if ((string) $row['status'] === 'active') {
            return ['ok' => false, 'error' => __('addons.error_disable_first')];
        }
        $this->db->query('DELETE FROM plugins WHERE id = :id', ['id' => $id]);
        $this->db->query('DELETE FROM addons WHERE id = :id', ['id' => $id]);
        $this->package->removeAll($id);
        $this->audit->log('addon.removed', 'addon', $id, null, ['version' => (string) $row['version']], $ip);

        return ['ok' => true];
    }

    /**
     * Check the signed manifest and install newer versions of the licensed add-ons.
     *
     * @return array{ok:bool,error?:string,updated?:list<array{id:string,from:string,to:string}>,errors?:list<string>}
     */
    public function checkUpdates(string $ip): array
    {
        $status = $this->license->syncStatus($ip);
        if (!empty($status['revoked'])) {
            return ['ok' => false, 'error' => __('addons.revoked_banner')];
        }
        $manifestResult = $this->license->fetchManifest($ip);
        if (empty($manifestResult['ok'])) {
            return ['ok' => false, 'error' => (string) ($manifestResult['error'] ?? __('addons.error_online'))];
        }
        $manifest = is_array($manifestResult['manifest'] ?? null) ? $manifestResult['manifest'] : [];
        $available = is_array($manifest['addons'] ?? null) ? $manifest['addons'] : [];
        $licenseKey = $this->license->licenseKey();
        if ($licenseKey === '') {
            return ['ok' => false, 'error' => __('addons.error_offline_only')];
        }
        $updated = [];
        $errors = [];
        foreach ($this->db->fetchAll('SELECT * FROM addons ORDER BY id') as $row) {
            $id = (string) $row['id'];
            if (!$this->license->statusFor($id)['licensed'] || !isset($available[$id]) || !is_array($available[$id])) {
                continue;
            }
            $meta = $available[$id];
            $currentCore = function_exists('app_version') ? app_version() : '0.0.0';
            $versions = is_array($meta['versions'] ?? null) ? $meta['versions'] : [];
            $target = null;
            foreach ($versions as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $candidateVersion = trim((string) ($candidate['version'] ?? ''));
                $candidateMin = trim((string) ($candidate['min_core'] ?? ''));
                if ($candidateVersion === ''
                    || ($candidateMin !== '' && version_compare($candidateMin, $currentCore, '>'))
                    || version_compare($candidateVersion, (string) $row['version'], '<=')) {
                    continue;
                }
                if ($target === null || version_compare($candidateVersion, (string) $target['version'], '>')) {
                    $target = $candidate;
                }
            }
            if ($target === null) {
                continue;
            }
            $latest = (string) $target['version'];
            $url = (string) ($target['url'] ?? '');
            $sha = (string) ($target['sha256'] ?? '');
            if ($url === '' || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
                $errors[] = $id . ': ' . __('addons.error_manifest_invalid');
                continue;
            }
            $tmp = $this->package->installRoot() . '/.incoming/remote-' . $id . '-' . $latest . '.soclyaddon';
            if (!is_dir(dirname($tmp)) && !@mkdir(dirname($tmp), 0750, true) && !is_dir(dirname($tmp))) {
                $errors[] = $id . ': ' . __('addons.error_storage');
                continue;
            }
            $download = $this->license->download($url, $tmp, $licenseKey);
            if (empty($download['ok'])) {
                $errors[] = $id . ': ' . (string) ($download['error'] ?? __('addons.error_download'));
                continue;
            }
            if (!hash_equals($sha, (string) hash_file('sha256', $tmp))) {
                @unlink($tmp);
                $errors[] = $id . ': ' . __('addons.error_file_hash', ['file' => $latest]);
                continue;
            }
            $inspected = $this->package->inspect($tmp);
            if (empty($inspected['ok'])
                || (string) ($inspected['manifest']['id'] ?? '') !== $id
                || (string) ($inspected['manifest']['version'] ?? '') !== $latest) {
                @unlink($tmp);
                $errors[] = $id . ': ' . (string) ($inspected['error'] ?? __('addons.error_manifest_invalid'));
                continue;
            }
            $inspected['staged'] = $tmp;
            $from = (string) $row['version'];
            $install = $this->installInspected($inspected, $ip);
            @unlink($tmp);
            if (empty($install['ok'])) {
                $errors[] = $id . ': ' . (string) ($install['error'] ?? __('addons.error_storage'));
                continue;
            }
            $updated[] = ['id' => $id, 'from' => $from, 'to' => $latest];
        }

        return ['ok' => true, 'updated' => $updated, 'errors' => $errors];
    }

    /** @param array<string,mixed> $manifest */
    private function storeRow(string $id, array $manifest, string $sha, string $licenseId): void
    {
        $meta = json_encode([
            'name' => (string) ($manifest['name'] ?? $id),
            'entry' => (string) ($manifest['entry'] ?? 'plugin.php'),
            'min_core' => (string) ($manifest['min_core'] ?? ''),
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
        $this->db->query(
            'INSERT INTO addons (id, version, status, license_id, package_sha256, meta_json, installed_at, updated_at)
             VALUES (:id, :version, \'disabled\', :license_id, :sha, :meta, NOW(), NOW())
             ON DUPLICATE KEY UPDATE version = VALUES(version), license_id = VALUES(license_id),
                package_sha256 = VALUES(package_sha256), meta_json = VALUES(meta_json), updated_at = NOW()',
            [
                'id' => $id,
                'version' => (string) ($manifest['version'] ?? ''),
                'license_id' => $licenseId,
                'sha' => $sha,
                'meta' => $meta,
            ]
        );
    }

    private function setPluginEnabled(string $id, bool $enabled, string $version): void
    {
        $meta = json_encode(['version' => $version, 'source' => 'addon'], JSON_UNESCAPED_UNICODE) ?: '{}';
        $this->db->query(
            'INSERT INTO plugins (id, is_enabled, enabled_at, meta_json)
             VALUES (:id, :enabled, :enabled_at, :meta)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), meta_json = VALUES(meta_json),
                enabled_at = IF(VALUES(is_enabled) = 1, VALUES(enabled_at), enabled_at)',
            [
                'id' => $id,
                'enabled' => $enabled ? 1 : 0,
                'enabled_at' => date('Y-m-d H:i:s'),
                'meta' => $meta,
            ]
        );
    }

    private function entryPath(string $id, string $version, string $entry): string
    {
        $safe = preg_match('/^[a-z0-9][a-z0-9_.-]{2,80}$/', $id) === 1
            && preg_match('/^\d+\.\d+\.\d+(?:[-.+][A-Za-z0-9.-]+)?$/', $version) === 1
            && preg_match('#^[A-Za-z0-9._/-]+\.php$#', $entry) === 1
            && !str_contains($entry, '..');

        return $safe ? $this->package->installRoot() . '/' . $id . '/' . $version . '/' . $entry : '';
    }

    /** @return PluginInterface|string the plugin, or the error message */
    private function pluginFromEntry(string $id, string $entry): PluginInterface|string
    {
        if ($entry === '' || !is_file($entry)) {
            return __('addons.error_entry_missing');
        }
        try {
            $plugin = require $entry;
        } catch (\Throwable $e) {
            return __('addons.error_entry_invalid', ['detail' => $e->getMessage()]);
        }
        if (!$plugin instanceof PluginInterface) {
            return __('addons.error_entry_contract');
        }
        if ($plugin->id() !== $id) {
            return __('addons.error_entry_id', ['expected' => $id, 'got' => $plugin->id()]);
        }

        return $plugin;
    }
}
