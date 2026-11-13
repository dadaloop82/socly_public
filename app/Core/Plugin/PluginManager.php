<?php

declare(strict_types=1);

namespace Socly\Core\Plugin;

use Socly\Core\App;
use Socly\Core\Addon\AddonManager;
use Socly\Core\Http\Router;

final class HookBus
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function fire(string $event, mixed ...$payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$payload);
        }
    }
}

final class PluginContext
{
    public function __construct(
        private readonly App $app,
        private readonly HookBus $hooks
    ) {
    }

    public function app(): App
    {
        return $this->app;
    }

    public function hooks(): HookBus
    {
        return $this->hooks;
    }

    public function get(string $abstract): mixed
    {
        return $this->app->get($abstract);
    }
}

final class PluginManager
{
    /** @var array<string, PluginInterface> */
    private array $discovered = [];

    /** @var list<array{label:string,path:string,permission?:string}> */
    private array $menu = [];

    /** @var array<string,string> plugins that failed to load or boot (id/file => message) */
    private array $failed = [];

    public function __construct(
        private readonly App $app,
        private readonly string $pluginPath,
        private readonly HookBus $hooks
    ) {
    }

    public function hooks(): HookBus
    {
        return $this->hooks;
    }

    /** @return array<string, PluginInterface> */
    public function discover(): array
    {
        if ($this->discovered !== []) {
            return $this->discovered;
        }
        foreach (glob($this->pluginPath . '/*.php') ?: [] as $file) {
            try {
                $plugin = require $file;
            } catch (\Throwable $e) {
                // A drop-in plugin with a fatal must not break the whole panel.
                $this->failed[basename($file)] = $e->getMessage();
                continue;
            }
            if (!$plugin instanceof PluginInterface) {
                continue;
            }
            $this->discovered[$plugin->id()] = $plugin;
        }
        foreach ($this->addonPluginFiles() as $file) {
            try {
                $plugin = require $file;
            } catch (\Throwable $e) {
                $this->failed[basename($file)] = $e->getMessage();
                continue;
            }
            if ($plugin instanceof PluginInterface) {
                $this->discovered[$plugin->id()] = $plugin;
            }
        }
        return $this->discovered;
    }

    /**
     * Entry files of the paid add-ons that may boot (installed, enabled and licensed).
     *
     * @return list<string>
     */
    private function addonPluginFiles(): array
    {
        try {
            if (!function_exists('app') || !app()->isInstalled()) {
                return [];
            }

            return app(AddonManager::class)->pluginFiles();
        } catch (\Throwable) {
            return [];
        }
    }

    public function find(string $id): ?PluginInterface
    {
        return $this->discover()[$id] ?? null;
    }

    public function bootEnabled(): void
    {
        $db = $this->app->get(\Socly\Core\Database::class);
        $enabled = $db->fetchAll('SELECT id FROM plugins WHERE is_enabled = 1');
        $ids = array_column($enabled, 'id');
        $router = $this->app->get(Router::class);
        $ctx = new PluginContext($this->app, $this->hooks);

        foreach ($this->discover() as $id => $plugin) {
            if (!in_array($id, $ids, true)) {
                continue;
            }
            // A broken add-on must never take the whole panel down: boot it in isolation,
            // log the failure and leave it out of the menu/routes for this request.
            try {
                $plugin->boot($ctx);
                foreach ($plugin->registerRoutes() as $route) {
                    $method = (string) $route[0];
                    $path = (string) $route[1];
                    $action = $route[2];
                    $permission = $route[3] ?? null;
                    // 5th element: explicit middleware list. Public add-on endpoints
                    // (tablet kiosk) can opt out of mw.auth while keeping CSRF/install gates.
                    $middleware = $route[4] ?? null;
                    if (!is_array($middleware)) {
                        $middleware = ['mw.csrf', 'mw.auth'];
                    }
                    $router->add($method, $path, $action, $permission, array_values($middleware));
                }
                foreach ($plugin->registerMenu() as $item) {
                    $this->menu[] = $item;
                }
            } catch (\Throwable $e) {
                $this->failed[$id] = $e->getMessage();
                try {
                    $this->app->get('logger')->error('plugin.boot_failed', [
                        'plugin' => $id,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                } catch (\Throwable) {
                    // logging must never break the request
                }
            }
        }
    }

    /** @return array<string,string> plugins that failed to boot in this request (id => message) */
    public function failedPlugins(): array
    {
        return $this->failed;
    }

    /** @return list<array{label:string,path:string,permission?:string,group?:string}> */
    public function menuItems(): array
    {
        return $this->menu;
    }

    public function fire(string $event, mixed ...$payload): void
    {
        $this->hooks->fire($event, ...$payload);
    }
}
