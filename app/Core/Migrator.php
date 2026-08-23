<?php

declare(strict_types=1);

namespace Socly\Core;

final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $path
    ) {
    }

    public function migrate(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT UNSIGNED NOT NULL,
                ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->repairLegacyMigrationNames();

        $ran = array_column($this->db->fetchAll('SELECT migration FROM migrations'), 'migration');
        $batch = (int) ($this->db->fetch('SELECT MAX(batch) AS m FROM migrations')['m'] ?? 0) + 1;
        $files = glob($this->path . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $ran, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("Cannot read migration {$name}");
            }
            $this->execSqlFile($sql);
            $this->db->insert('migrations', ['migration' => $name, 'batch' => $batch]);
        }
    }

    private function execSqlFile(string $sql): void
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($parts as $statement) {
            if ($statement !== '') {
                $this->db->pdo()->exec($statement);
            }
        }
    }

    public function runPluginSql(string $pluginId, array $migrations): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS plugin_migrations (
                plugin_id VARCHAR(100) NOT NULL,
                version VARCHAR(50) NOT NULL,
                ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (plugin_id, version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        foreach ($migrations as $version => $sql) {
            $exists = $this->db->fetch(
                'SELECT 1 AS x FROM plugin_migrations WHERE plugin_id = :p AND version = :v',
                ['p' => $pluginId, 'v' => (string) $version]
            );
            if ($exists) {
                continue;
            }
            $this->db->pdo()->exec($sql);
            $this->db->insert('plugin_migrations', [
                'plugin_id' => $pluginId,
                'version' => (string) $version,
            ]);
        }
    }

    /** Some installs applied treasury invoice columns under the old filename 015_treasury_invoice_details.sql. */
    private function repairLegacyMigrationNames(): void
    {
        try {
            $ran = array_column($this->db->fetchAll('SELECT migration FROM migrations'), 'migration');
        } catch (\Throwable) {
            return;
        }
        if (in_array('016_treasury_invoice_details.sql', $ran, true)) {
            return;
        }
        if (!in_array('015_treasury_invoice_details.sql', $ran, true)) {
            return;
        }
        try {
            $col = $this->db->fetch(
                "SELECT 1 AS x FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'treasury_movements'
                   AND COLUMN_NAME = 'invoice_payment'
                 LIMIT 1"
            );
        } catch (\Throwable) {
            return;
        }
        if (!$col) {
            return;
        }
        $batch = (int) ($this->db->fetch('SELECT MAX(batch) AS m FROM migrations')['m'] ?? 0);
        try {
            $this->db->insert('migrations', [
                'migration' => '016_treasury_invoice_details.sql',
                'batch' => $batch > 0 ? $batch : 1,
            ]);
        } catch (\Throwable) {
            // Already recorded by a parallel request.
        }
    }
}
