<?php

declare(strict_types=1);

namespace Socly\Core\Addon;

use Socly\Core\Database;
use Socly\Core\Migrator;
use Socly\Core\Plugin\PluginInterface;
use Socly\Services\AuditService;

/**
 * Installed add-on packages: lifecycle, enable/disable, updates and plugin discovery.
 *
 * Packages live in storage/addons/<id>/<version>/ and are only booted when the signed
 * licence grants the add-on; the `plugins` table keeps the enable/disable state so the
 * existing plugin machinery (menus, routes, settings, migrations) keeps working.
 */
final class AddonManager
{
    public function __construct(
        private readonly Database $db,
        private readonly LicenseService $license,
        private readonly AddonPackage $package,
        private readonly Migrator $migrator,
        private readonly AuditService $audit
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
     * Install a package uploaded from the admin page.
     *
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
