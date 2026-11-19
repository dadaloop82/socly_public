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
            // The sidebar appends version() in small type at the end of every entry.
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

Public pages must not leak member data. The `Iscrizione rapida` add-on is the reference
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

## Workflow events (optional)

Settings → Workflow sends an email when a domain event occurs. A plugin/add-on can publish its own
events by implementing `Socly\Core\Plugin\WorkflowEventProviderInterface` **in addition to**
`PluginInterface`:

```php
use Socly\Core\Plugin\WorkflowEventProviderInterface;

final class MyPlugin implements PluginInterface, WorkflowEventProviderInterface
{
    /** Group label => [event key => final display label]. */
    public function workflowEventGroups(): array
    {
        return [
            'My plugin' => [
                'my_plugin.request_created' => 'My plugin · new request',
            ],
        ];
    }

    /** Audit action => event key; AuditService forwards it automatically. */
    public function workflowAuditMap(): array
    {
        return ['my_plugin.request.created' => 'my_plugin.request_created'];
    }
}
```

- The groups and events appear in the Workflow picker **only while the plugin is enabled**.
- Add-ons that do **not** implement the interface are still detected. `WorkflowService` scans every
  add-on available on disk (`storage/addons/<id>/<version>/`, `addons/`, `addons-src/<id>/`) for
  `$this->audit->log('namespace.action', …)` and `$this->workflow->dispatch('namespace.event', …)`,
  including quoted actions passed through variables that share the audit namespace. One group per
  add-on is cached in `storage/cache/workflow_addons.json`; the interface remains the way to provide
  polished/localized labels and an explicit audit map.
- Auto-discovered groups are listed for every available add-on, but their rules only fire when the
  add-on is installed and active.
- Labels must be final display strings (use the plugin's own `AddonText`); core labels are lang keys.
- Event keys are stored in `workflow_rules.event_key` (max 80 chars, `[a-z0-9_.-]`).
- The audit bridge is optional: the plugin can also emit explicitly:
  `app(\Socly\Services\WorkflowService::class)->dispatch('my_plugin.request_created', ['email' => $to, ...])`.
- A plugin event needs a recipient (`email` or `_to`) to send; otherwise `dispatch()` returns 0.
- Core events are listed by `WorkflowService::coreEventGroups()` (users, members, enrollment, payments,
  documents, deadlines, treasury, budgets, bodies/org roles).

## Settings

Keys returned by `registerSettings()` appear under Configuration when the plugin is enabled. Encrypted flags use the same Encryptor as core settings.

## Files and documents

Every file uploaded by a plugin/add-on must be stored and registered in the central repository
(“Verbali e documenti”), never as a loose file:

```php
$repository = $ctx->get(\Socly\Services\FileRepositoryService::class);
$stored = $repository->storeUpload($_FILES['attachment'], [
    'section' => 'my_addon',
    'ref' => 'request:' . $requestId,
    'category' => 'other',
    'title' => $originalName,
    'actor_type' => 'operator',       // or participant/member/addon/public
    'actor_id' => $userId,
    'actor_name' => $displayName,
]);
// $stored['document_id'], $stored['path'], $stored['mime'], $stored['size'], $stored['hash']
```

- `storeUpload()` dedupes by SHA-256: the same bytes already archived are reused and linked to the new
  section/context, no copy is written.
- A file generated on disk goes through `registerStoredFile()`; base64/raw bytes through
  `registerContent()`.
- Offer the “already uploaded document” choice with `listForPicker($section, $q, $year, $limit, $contextRef)`
  and validate the choice with `pick($documentId, $section)` before writing your own row.
- Store the returned relative path in your own table (it stays valid after dedupe) and use
  `signedUrl($documentId)` when the add-on authenticates with its own token instead of the operator
  session (the `/files/{id}` route validates the HMAC signature).
- `$repository->link($documentId, $section, $ref, $identity)` records an additional context without
  touching the bytes; `unlink()` releases it.


Plugin code runs with full application privileges. Only install plugins from trusted sources.
