<?php

declare(strict_types=1);

namespace Socly\Core\Plugin;

use Socly\Core\App;
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
            $plugin = require $file;
            if (!$plugin instanceof PluginInterface) {
                continue;
            }
            $this->discovered[$plugin->id()] = $plugin;
        }
        return $this->discovered;
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
            $plugin->boot($ctx);
            foreach ($plugin->registerRoutes() as $route) {
                $method = $route[0];
                $path = $route[1];
                $action = $route[2];
                $permission = $route[3] ?? null;
                $router->add($method, $path, $action, $permission, ['mw.csrf', 'mw.auth']);
            }
            foreach ($plugin->registerMenu() as $item) {
                $this->menu[] = $item;
            }
        }
    }

    /** @return list<array{label:string,path:string,permission?:string}> */
    public function menuItems(): array
    {
        return $this->menu;
    }

    public function fire(string $event, mixed ...$payload): void
    {
        $this->hooks->fire($event, ...$payload);
    }
}
