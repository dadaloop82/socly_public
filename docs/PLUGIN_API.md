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
            // Public page (no login): 5th element overrides the default middleware.
            // ['GET', '/example/{token}', [ExampleController::class, 'publicPage'], null, ['mw.csrf']],
        ];
    }

    public function registerMenu(): array
    {
        return [
            // ['label' => 'example.menu', 'path' => '/admin/example', 'permission' => 'plugins.manage'],
            // Nested under a core nav item: the group is the component key (members, treasury, org, …).
            // ['label' => 'example.menu', 'path' => '/admin/example', 'permission' => 'members.manage', 'group' => 'members'],
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

## Routes and middleware

`registerRoutes()` entries are `[method, path, action, permission?, middleware?]`.

- Default middleware is `['mw.csrf', 'mw.auth']`: the route requires a logged-in operator.
- The 5th element overrides it. Use `['mw.csrf']` for a **public** page that must work
  without login (a kiosk, a customer-facing form). Keep `mw.csrf` on POST routes and always
  validate an access token / signature inside the action: a public route is reachable by anyone.
- Global gates (security headers/CSP, locale, install gate, instance expiry) run for every
  request, so a public route still gets sessions, CSRF and the hardened headers.
- Actions are controller classes resolved from the container or closures. Bind your own
  services in `boot()` (`$ctx->app()->bind(...)`) when the route points to a class.
- Paths support `{name}` placeholders; the action receives them after the `Request`:
  `public function show(Request $request, string $token): void`.

Public pages must not leak member data. The `Iscrizione Tablet` add-on is the reference
implementation (token in the path, server-rendered wizard, core services for writes).

## Gating core features on an add-on

Paid add-ons are not in the public tree. Core code must never assume the package exists:
use `addon_active('addon_id')` (installed + enabled + licensed) before enabling a feature or
a settings option. When the add-on is missing, the feature must degrade gracefully instead of
erroring (see the “Tablet” enrollment validation in `EnrollmentService::method()`).

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
