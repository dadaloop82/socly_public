<?php

declare(strict_types=1);

namespace Socly\Core;

use Socly\Core\Http\Request;
use Socly\Core\Http\Router;
use Socly\Core\Plugin\PluginManager;
use Socly\Core\Migrator;
use Socly\Services\SettingsService;

final class App
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var array<string, mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function bind(string $abstract, mixed $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
        unset($this->resolved[$abstract]);
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->resolved[$abstract] = $instance;
        $this->bindings[$abstract] = $instance;
    }

    public function get(string $abstract): mixed
    {
        if (array_key_exists($abstract, $this->resolved)) {
            return $this->resolved[$abstract];
        }
        if (!array_key_exists($abstract, $this->bindings)) {
            throw new \RuntimeException("Binding [{$abstract}] not found.");
        }
        $concrete = $this->bindings[$abstract];
        $value = $concrete instanceof \Closure ? $concrete($this) : $concrete;
        $this->resolved[$abstract] = $value;
        return $value;
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->config;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function isInstalled(): bool
    {
        return is_file(storage_path('installed.lock'));
    }

    public function run(): void
    {
        /** @var Request $request */
        $request = $this->get('request');
        /** @var Router $router */
        $router = $this->get('router');

        $this->get('mw.security')->handle($request);
        $this->get('mw.locale')->handle($request);
        if ($this->get('mw.install')->handle($request) !== true) {
            return;
        }
        if ($this->isInstalled() && $this->get('mw.instance_expired')->handle($request) !== true) {
            return;
        }

        if ($this->isInstalled()) {
            try {
                $this->get(Migrator::class)->migrate();
                $this->get(\Socly\Services\InstallerService::class)
                    ->seedFields(\Socly\Services\InstallerService::defaultFields());
                /** @var PluginManager $plugins */
                $plugins = $this->get('plugins');
                $plugins->bootEnabled();
            } catch (\Throwable) {
                // DB may be misconfigured; route handlers will surface errors.
            }
        }

        $router->dispatch($request);
    }

    public function branding(): array
    {
        $defaults = [
            'name' => 'SOCLY',
            'legal_name' => null,
            'primary' => '#0D6E66',
            'accent' => '#B84A1B',
            'logo' => null,
        ];
        if (!$this->isInstalled()) {
            return $defaults;
        }
        try {
            /** @var SettingsService $settings */
            $settings = $this->get(SettingsService::class);
            $name = trim((string) ($settings->get('association.name') ?: ($_ENV['ASSOCIATION_NAME'] ?? '')));
            $legal = trim((string) ($settings->get('association.legal_name') ?: ($_ENV['ASSOCIATION_LEGAL_NAME'] ?? '')));
            $primary = trim((string) ($settings->get('branding.primary') ?: ($_ENV['BRANDING_PRIMARY'] ?? '')));
            $accent = trim((string) ($settings->get('branding.accent') ?: ($_ENV['BRANDING_ACCENT'] ?? '')));
            $logo = $settings->get('branding.logo') ?: null;
            return [
                'name' => $name !== '' ? $name : null,
                'legal_name' => $legal !== '' ? $legal : null,
                'primary' => $primary !== '' ? $primary : $defaults['primary'],
                'accent' => $accent !== '' ? $accent : $defaults['accent'],
                'logo' => $logo,
            ];
        } catch (\Throwable) {
            return $defaults;
        }
    }
}
