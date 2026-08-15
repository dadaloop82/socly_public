# Architecture

Socly is a lightweight custom MVC application on PHP 8.2+ with Composer PSR-4 autoloading (`Socly\` → `app/`).

## Request lifecycle

1. `public/index.php` boots the app (`bootstrap/app.php`).
2. Environment is loaded from `.env` (after install) or from wizard-written config.
3. Session starts; locale middleware resolves language (user preference → default).
4. Router matches the path to a controller action.
5. Middleware stack runs (CSRF for POST, auth where required, permissions).
6. Controller calls services; services use PDO models.
7. Enabled plugins receive hooks and may register extra routes/menu/settings.

## Layers

| Layer | Responsibility |
|-------|----------------|
| `app/Core` | App kernel, Router, Container, Database, Encryptor, PluginManager, View, Validator |
| `app/Controllers` | HTTP adapters |
| `app/Services` | Business logic (members, payments, install, audit, settings) |
| `app/Models` | Thin PDO data access |
| `app/Middleware` | Auth, CSRF, Locale, InstallGate |
| `plugins/` | Optional feature modules (single file each) |

## Configuration

- Runtime settings live in the `settings` table.
- Sensitive values are encrypted at rest with `APP_KEY` (see [SECURITY.md](SECURITY.md)).
- Branding (name, colors, logo path) is stored as settings and exposed as CSS variables in the layout.

## Plugins

See [PLUGIN_API.md](PLUGIN_API.md). Plugins are discovered by scanning `plugins/*.php`. Only rows marked enabled in `plugins` are booted. Disabling a plugin unloads hooks; data remains.

## Internationalization

Translations are PHP arrays under `lang/{locale}/`. See [I18N.md](I18N.md).

## Design principles

- One installation = one association.
- Core stays generic; domain-specific features belong in plugins.
- Prefer explicit validation rules on every field.
- Prefer auditability for mutating operations.
