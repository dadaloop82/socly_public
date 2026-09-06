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
        $store = $encrypted
            ? $this->encryptor->encrypt((string) $value)
            : $this->encodeValue($value);
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

    private function encodeValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $encoded = json_encode($value, $flags);
        if (is_string($encoded)) {
            return $encoded;
        }
        // Last resort: drop non-UTF8 recursively then retry.
        $clean = $this->utf8Clean($value);
        $encoded = json_encode($clean, $flags);
        return is_string($encoded) ? $encoded : '{}';
    }

    private function utf8Clean(mixed $value): mixed
    {
        if (is_string($value)) {
            if (mb_check_encoding($value, 'UTF-8')) {
                return $value;
            }
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            return is_string($converted) ? $converted : '';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->utf8Clean($v);
            }
            return $out;
        }
        return $value;
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
        $repair = [];
        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            $value = $row['value'];
            if ((int) $row['is_encrypted'] === 1 && $value !== null && $value !== '') {
                try {
                    $value = $this->encryptor->decrypt((string) $value);
                } catch (\Throwable) {
                    $value = null;
                }
            }
            if (in_array($key, ['legal.privacy', 'legal.statute'], true) && is_string($value)) {
                $shrunk = $this->shrinkLegalSettingValue($value);
                if ($shrunk !== $value) {
                    $value = $shrunk;
                    $repair[$key] = $shrunk;
                }
            }
            $out[$key] = [
                'value' => $value,
                'is_encrypted' => (bool) $row['is_encrypted'],
            ];
        }
        $this->cache = $out;
        foreach ($repair as $key => $store) {
            try {
                $this->db->update('settings', [
                    'value' => $store,
                    'is_encrypted' => 0,
                ], '`key` = :k', ['k' => $key]);
            } catch (\Throwable) {
            }
        }
        return $this->cache;
    }

    /**
     * Cap multilingual legal blobs so setup pages never load multi‑MB OCR dumps into memory.
     */
    private function shrinkLegalSettingValue(string $raw): string
    {
        $maxJsonBytes = 100_000;
        $maxLangChars = 40_000;
        if (strlen($raw) <= $maxJsonBytes) {
            return $raw;
        }
        $decoded = null;
        if (($raw[0] ?? '') === '{') {
            $decoded = json_decode($raw, true);
        }
        if (!is_array($decoded)) {
            $text = $raw;
            if (!mb_check_encoding($text, 'UTF-8')) {
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
                $text = is_string($converted) ? $converted : '';
            }
            if (mb_strlen($text, 'UTF-8') > $maxLangChars) {
                $text = rtrim(mb_substr($text, 0, $maxLangChars, 'UTF-8'))
                    . "\n\n[… testo troncato automaticamente]";
            }
            $encoded = json_encode(['it' => $text, 'de' => '', 'en' => ''], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return is_string($encoded) ? $encoded : '{"it":"","de":"","en":""}';
        }
        foreach (['it', 'de', 'en'] as $lang) {
            $text = (string) ($decoded[$lang] ?? '');
            if (!mb_check_encoding($text, 'UTF-8')) {
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
                $text = is_string($converted) ? $converted : '';
            }
            if (mb_strlen($text, 'UTF-8') > $maxLangChars) {
                $text = rtrim(mb_substr($text, 0, $maxLangChars, 'UTF-8'))
                    . "\n\n[… testo troncato automaticamente]";
            }
            $decoded[$lang] = $text;
        }
        // Prefer Italian only when all locales still push the blob over the limit.
        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($encoded) || strlen($encoded) > $maxJsonBytes) {
            $encoded = json_encode([
                'it' => (string) ($decoded['it'] ?? ''),
                'de' => '',
                'en' => '',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return is_string($encoded) ? $encoded : '{"it":"","de":"","en":""}';
    }
}
