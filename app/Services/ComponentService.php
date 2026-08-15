<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Components\ComponentRegistry;

final class ComponentService
{
    private const ENABLED_KEY = 'components.enabled';
    private const CONFIGURED_KEY = 'components.configured';

    public function __construct(
        private readonly SettingsService $settings
    ) {
    }

    /** @return list<string> */
    public function enabledKeys(): array
    {
        $raw = $this->settings->get(self::ENABLED_KEY, '');
        if ($raw === '' || $raw === null) {
            return $this->defaultEnabledKeys();
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return $this->defaultEnabledKeys();
        }
        $valid = array_fill_keys(ComponentRegistry::keys(), true);
        $keys = [];
        foreach ($decoded as $key) {
            $key = (string) $key;
            if (isset($valid[$key])) {
                $keys[] = $key;
            }
        }
        return $keys !== [] ? $keys : $this->defaultEnabledKeys();
    }

    public function isEnabled(string $key): bool
    {
        return in_array($key, $this->enabledKeys(), true);
    }

    /** @param list<string> $keys */
    public function setEnabled(array $keys): void
    {
        $valid = array_fill_keys(ComponentRegistry::keys(), true);
        $normalized = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (isset($valid[$key]) && !in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }
        if ($normalized === []) {
            $normalized = ['members'];
        }
        sort($normalized);
        $this->settings->set(self::ENABLED_KEY, json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    public function markConfigured(): void
    {
        $this->settings->set(self::CONFIGURED_KEY, '1');
    }

    public function isConfigured(): bool
    {
        return (string) ($this->settings->get(self::CONFIGURED_KEY, '0') ?: '0') === '1';
    }

    /** @return list<array{key:string,label_key:string,path:string,permission:string,icon:string}> */
    public function menuItems(): array
    {
        $items = [];
        foreach (ComponentRegistry::all() as $component) {
            if (!$this->isEnabled($component['key'])) {
                continue;
            }
            $items[] = [
                'key' => $component['key'],
                'label' => $component['nav_label_key'],
                'path' => $component['path'],
                'permission' => $component['permission'],
                'icon' => $component['icon'],
            ];
        }
        return $items;
    }

    /** @return array<string, mixed> */
    public function config(string $key, array $defaults = []): array
    {
        $def = ComponentRegistry::find($key);
        if ($def === null || empty($def['settings_key'])) {
            return $defaults;
        }
        $raw = $this->settings->get((string) $def['settings_key'], '');
        if ($raw === '' || $raw === null) {
            return $defaults;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
    }

    /** @param array<string, mixed> $config */
    public function saveConfig(string $key, array $config): void
    {
        $def = ComponentRegistry::find($key);
        if ($def === null || empty($def['settings_key'])) {
            return;
        }
        $this->settings->set((string) $def['settings_key'], json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> */
    private function defaultEnabledKeys(): array
    {
        $keys = [];
        foreach (ComponentRegistry::all() as $component) {
            if (!empty($component['default_enabled'])) {
                $keys[] = $component['key'];
            }
        }
        return $keys !== [] ? $keys : ['members'];
    }
}
