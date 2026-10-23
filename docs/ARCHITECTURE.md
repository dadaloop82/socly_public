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

## Document text extraction (RUNTS)

The setup wizard can prefill the association statute and privacy notice from the PDFs downloaded
from the RUNTS register (`Socly\Services\PdfTextExtractor`):

1. `pdftotext` runs first. If the PDF has a text layer, the complete text is used and no OCR is needed.
2. Scanned PDFs are rendered with `pdftoppm` **one page at a time** and OCR'd with `tesseract`
   (`--psm 6`, Italian language pack).

Rules and limits:

- `max_pages = 0` means “all pages”. The background worker (`bin/runts-legal-prefill.php`) uses no
  page cap and a 30-minute budget; the manual PDF upload in the wizard also reads the whole file.
- `pdftoppm -f N -l N` names the output after the **real page number** (`p3-3.png`, not `p3-1.png`):
  resolve rendered files with a glob, never assume the `-1` suffix.
- The extractor returns `processed_pages`, `complete` and `truncated`. `SetupService` turns them into
  a `partial` flag so the wizard can warn when a legal text was only partially read.
- `legal.statute` / `legal.privacy` are stored as JSON per locale and are treated as official
  documents: synthetic notes (“text truncated…”) must never be written inside them.
- When OCR is unavailable, the wizard degrades gracefully and asks the administrator to paste the text.

## Platform telemetry & installation registry

`Socly\Services\PlatformService` talks to the Socly platform API (`SOCLY_SITE_URL`, default
`/api/platform.php`):

- **Anonymous telemetry** (`maybeSendTelemetry`): version, locale, member range and exact counts,
  enabled components/plugins, PHP and database version. No names, emails or personal data. Sent at
  login at most every 6 hours.
- **Installation registry** (`maybeSyncInstallation`): association identity and contacts, seat,
  admin/president, installed version, usage counts and enabled modules. Requires
  `platform.contacts_opt_in` (default on, revocable) and is **never sent by demo instances**
  (`app.temporary_instance`). Sent when consents are saved, when association settings change, on
  update check/install, at least daily, and immediately when the installed version changes.
- Receiver: `_webSite/api/platform.php` → `platform_instances` / `platform_installations` (SQLite).
  Admin pages: “Piattaforma” (KPIs + charts + telemetry table) and “Installazioni” (contact cards
  with filters and detail).

Demos are excluded from both payloads, so platform numbers only describe real installations.

## Installation and maintenance access

The install wizard creates no SuperAdmin: it only configures the database, association, membership
period/type and profile fields. The single **maintenance SuperAdmin** is hardcoded in
`Socly\Support\PlatformMasterAccess` (email digest + Argon2id password hash); the database row is
created on first maintenance login with an unusable random DB password. Association users are created
by the first-run setup (`/setup`) with granular permissions and never receive `is_system_admin`.

## Plugins

See [PLUGIN_API.md](PLUGIN_API.md). Plugins are discovered by scanning `plugins/*.php`. Only rows marked enabled in `plugins` are booted. Disabling a plugin unloads hooks; data remains.

## Internationalization

Translations are PHP arrays under `lang/{locale}/`. See [I18N.md](I18N.md).

## Design principles

- One installation = one association.
- Core stays generic; domain-specific features belong in plugins.
- Prefer explicit validation rules on every field.
- Prefer auditability for mutating operations.
