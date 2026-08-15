<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Encryptor;

final class SettingsService
{
    /** @var array<string, array{value:mixed,is_encrypted:bool}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Database $db,
        private readonly Encryptor $encryptor
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (!isset($all[$key])) {
            return $default;
        }
        return $all[$key]['value'];
    }

    public function set(string $key, mixed $value, bool $encrypted = false, ?string $pluginId = null): void
    {
        $store = $encrypted ? $this->encryptor->encrypt((string) $value) : (is_scalar($value) || $value === null ? (string) $value : json_encode($value));
        $existing = $this->db->fetch('SELECT `key` FROM settings WHERE `key` = :k', ['k' => $key]);
        if ($existing) {
            $this->db->update('settings', [
                'value' => $store,
                'is_encrypted' => $encrypted ? 1 : 0,
                'plugin_id' => $pluginId,
            ], '`key` = :k', ['k' => $key]);
        } else {
            $this->db->insert('settings', [
                'key' => $key,
                'value' => $store,
                'is_encrypted' => $encrypted ? 1 : 0,
                'plugin_id' => $pluginId,
            ]);
        }
        $this->cache = null;
    }

    public function delete(string $key): void
    {
        $this->db->query('DELETE FROM settings WHERE `key` = :k', ['k' => $key]);
        $this->cache = null;
    }

    /** @param list<string> $keys */
    public function deleteMany(array $keys): void
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
    }

    /** @param array<string, mixed> $pairs */
    public function setMany(array $pairs, bool $encrypted = false, ?string $pluginId = null): void
    {
        foreach ($pairs as $key => $value) {
            $this->set($key, $value, $encrypted, $pluginId);
        }
    }

    /** @return array<string, array{value:mixed,is_encrypted:bool}> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $rows = $this->db->fetchAll('SELECT `key`, `value`, is_encrypted FROM settings');
        $out = [];
        foreach ($rows as $row) {
            $value = $row['value'];
            if ((int) $row['is_encrypted'] === 1 && $value !== null && $value !== '') {
                try {
                    $value = $this->encryptor->decrypt((string) $value);
                } catch (\Throwable) {
                    $value = null;
                }
            }
            $out[$row['key']] = [
                'value' => $value,
                'is_encrypted' => (bool) $row['is_encrypted'],
            ];
        }
        return $this->cache = $out;
    }
}
