<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Migrator;
use Socly\Core\Plugin\PluginManager;

final class PluginAdminService
{
    public function __construct(
        private readonly Database $db,
        private readonly PluginManager $plugins,
        private readonly Migrator $migrator,
        private readonly AuditService $audit,
        private readonly SettingsService $settings
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function catalog(): array
    {
        $discovered = $this->plugins->discover();
        $rows = $this->db->fetchAll('SELECT * FROM plugins');
        $enabled = [];
        foreach ($rows as $row) {
            $enabled[$row['id']] = $row;
        }
        $out = [];
        foreach ($discovered as $id => $plugin) {
            $out[] = [
                'id' => $id,
                'name' => $plugin->name(),
                'version' => $plugin->version(),
                'description' => $plugin->description(),
                'is_enabled' => !empty($enabled[$id]['is_enabled']),
                'settings' => $plugin->registerSettings(),
            ];
        }
        return $out;
    }

    public function enable(string $id, string $ip): bool
    {
        $plugin = $this->plugins->find($id);
        if (!$plugin) {
            return false;
        }
        $this->migrator->runPluginSql($id, $plugin->migrations());
        $exists = $this->db->fetch('SELECT id FROM plugins WHERE id = :id', ['id' => $id]);
        if ($exists) {
            $this->db->update('plugins', [
                'is_enabled' => 1,
                'enabled_at' => date('Y-m-d H:i:s'),
                'meta_json' => json_encode(['version' => $plugin->version()]),
            ], 'id = :id', ['id' => $id]);
        } else {
            $this->db->insert('plugins', [
                'id' => $id,
                'is_enabled' => 1,
                'enabled_at' => date('Y-m-d H:i:s'),
                'meta_json' => json_encode(['version' => $plugin->version()]),
            ]);
        }
        $this->audit->log('plugin.enabled', 'plugin', $id, null, ['enabled' => true], $ip);
        return true;
    }

    public function disable(string $id, string $ip): bool
    {
        $exists = $this->db->fetch('SELECT * FROM plugins WHERE id = :id', ['id' => $id]);
        if (!$exists) {
            return false;
        }
        $this->db->update('plugins', ['is_enabled' => 0], 'id = :id', ['id' => $id]);
        $this->audit->log('plugin.disabled', 'plugin', $id, $exists, ['enabled' => false], $ip);
        return true;
    }

    public function savePluginSettings(string $pluginId, array $input, string $ip): void
    {
        $plugin = $this->plugins->find($pluginId);
        if (!$plugin) {
            return;
        }
        $defs = $plugin->registerSettings();
        foreach ($defs as $key => $def) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $this->settings->set($key, $input[$key], !empty($def['encrypted']), $pluginId);
        }
        $this->audit->log('plugin.settings', 'plugin', $pluginId, null, array_keys($input), $ip);
        $this->plugins->fire('settings.saved', array_keys($input));
    }
}
