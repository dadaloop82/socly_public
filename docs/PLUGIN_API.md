# Plugin API

A plugin is **one PHP file** in `plugins/`. Socly scans the directory, loads each file, and expects it to `return` an object implementing `Socly\Core\Plugin\PluginInterface`.

## Contract

```php
<?php
declare(strict_types=1);

use Socly\Core\Plugin\PluginInterface;
use Socly\Core\Plugin\PluginContext;

return new class implements PluginInterface {
    public function id(): string { return 'example'; }
    public function name(): string { return 'Example'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'Example plugin'; }

    public function boot(PluginContext $ctx): void
    {
        $ctx->hooks()->on('member.created', function (array $member) {
            // ...
        });
    }

    public function registerRoutes(): array
    {
        return [
            // ['GET', '/admin/example', [ExampleController::class, 'index'], 'plugins.manage'],
        ];
    }

    public function registerMenu(): array
    {
        return [
            // ['label' => 'example.menu', 'path' => '/admin/example', 'permission' => 'plugins.manage'],
        ];
    }

    public function registerSettings(): array
    {
        return [
            // 'example.api_key' => ['label' => 'API key', 'encrypted' => true, 'validate' => 'required|string'],
        ];
    }

    public function migrations(): array
    {
        return [
            // __DIR__ . '/../database/plugin_example_001.sql' — or inline SQL strings keyed by version
        ];
    }
};
```

## Lifecycle

1. **Discover** — every `plugins/*.php` file is listed in the Plugins admin page.
2. **Enable** — mark enabled in DB; run pending plugin migrations; boot on subsequent requests.
3. **Disable** — stop booting; **do not** drop tables or delete data.
4. **No dependencies** — each plugin is self-contained.

## Hooks (core)

| Hook | Payload |
|------|---------|
| `member.created` | member row (+ fields) |
| `member.updated` | before, after |
| `member.deleted` | member id |
| `payment.recorded` | payment row |
| `settings.saved` | keys changed |

## Settings

Keys returned by `registerSettings()` appear under Configuration when the plugin is enabled. Encrypted flags use the same Encryptor as core settings.

## Security

Plugin code runs with full application privileges. Only install plugins from trusted sources.
