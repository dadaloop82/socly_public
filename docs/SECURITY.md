# Security

## Authentication

- Passwords hashed with `password_hash` / verified with `password_verify` (PASSWORD_DEFAULT).
- Session regeneration on login.
- System admin (`is_system_admin`) cannot be deleted; may change password. **No magic `user_id === 1` bypass.**
- Server-side login rate limiting by IP + email (`storage/cache/rate_limits`), not session-only.

## Sessions

- Cookie name `socly_session`
- `httponly`, `SameSite=Lax`, `secure` when HTTPS / `APP_URL` is https
- Session files under `storage/sessions` (outside document root)

## CSRF

All state-changing requests (POST/PUT/PATCH/DELETE) require a valid CSRF token from:
- form field `_token`, or
- header `X-CSRF-Token`

## Settings encryption

- `APP_KEY` is a 32-byte key stored in `.env` (generated at install).
- Settings marked `is_encrypted` are stored as `base64(nonce + ciphertext)` via OpenSSL AES-256-GCM.
- Decryption happens only in `SettingsService` when reading.

## Authorization

Permission checks on routes and UI via stable keys in `Socly\Support\Permission`. System admin bypasses checks. Deny by default.

- Presence links (“open where the colleague is working”) are built **server-side** with the same
  permission and component flag as the destination page: the client never supplies a path, and no link
  is returned when the viewer could not open the page anyway.
- The reported screen is only a local path with an optional fragment: absolute URLs (`//host`,
  `scheme://`) are refused and the query string is always stripped, so searches and filters never leak
  through the presence payload. An unknown path yields no link.
- Presence payloads are allowlisted: the focused field must match `[A-Za-z0-9_\-\[\]]` (max 120
  characters) and the draft is capped at 300 characters with control characters stripped; both are
  ignored after 45 seconds of silence and are never interpreted as markup.

## Audit & logging

- Mutations write to `audit_logs` (actor, entity, before/after, IP).
- The `/audit` screen (permission `audit.view`) is the read-only activity log: filters by actor, area and
  period, with expandable before/after payloads. It never writes audit rows.
- Add-on packages are Ed25519-signed (`addon.json.sig` + per-file SHA-256) and extracted only under
  `storage/addons/`; unsigned or modified files are refused. Licences (`SOCLY-ENT1…`) are verified locally
  with the public key shipped in `bootstrap/addons_keys.php`; private keys never live in the repository or
  the public export.
- Application logger redacts passwords, tokens, fiscal codes; never dump full `$_POST` / `$_SESSION`.
- Production errors (`APP_DEBUG=false`) show a generic page; details go to `storage/logs/app.log`.

## HTTP hardening

- Security headers middleware: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, CSP (no `unsafe-eval`), HSTS on HTTPS.
- Document root must be `public/` only; `.htaccess` blocks accidental exposure patterns.

## Data integrity

- Unique `member_number`.
- Duplicate identity check (first name + last name + birth date) and unique fiscal code when present.
- Empty optional fields normalized to `null`.
- Fiscal code validated with Italian check digit (no invented birthplace defaults).

## Maintenance SuperAdmin

- Exactly one system administrator exists: the built-in **maintenance account**. The email is a fixed
  technical identifier (HMAC digest); the app never displays it in lists, setup or installers.
- The credential is **per-deployment and signed**: Argon2id hash in `.env` as
  `PLATFORM_MASTER_PASSWORD_HASH` plus an Ed25519 `PLATFORM_MASTER_SIGNATURE` bound to `APP_KEY`.
  The signing key is held only by the platform owner and is never committed. A plain GitHub fork
  cannot mint or change a valid credential; fresh installs start with
  `SOCLY_DISABLE_PLATFORM_MASTER=true`.
- Create or rotate only in the private/hosted build, with the signing key available, via
  `php bin/platform-master-password.php`. The DB row keeps an unusable random hash; login is verified
  against the signed env credential.
- It cannot be created, edited, deleted or password-reset from the UI; no other user can be promoted
  to `is_system_admin`.
- The first-run setup stays open until an association Admin exists; the installer never opens a
  maintenance session and never writes a one-shot credential file.
- The public base edition replaces this class with a disabled stub: no maintenance SuperAdmin
  exists there. The signed implementation is part of the private/hosted build only.
- Legacy system-admin rows are demoted at login/session restore, and `can()` grants the system-admin
  bypass only to the maintenance email.
- Keep the signing key and the maintenance password offline. Never put a shared password hash or the
  private signing key back into the repository.

## Public endpoints

- `GET /api/updates/check` — guests receive version availability plus public release metadata
  (`released_at`, `notes_url`, short `changelog` highlights). Repo URL, commit fingerprint and
  download links stay admin-only. Guests are rate-limited per IP.
- `GET /i18n/messages` — required on the login language switcher; rate-limited per IP.
- `public/_debug*` and `public/router.php` are blocked by `.htaccess` and must not be deployed.

## Marketing admin / demos (socly.it)

- Optional IP allowlist: setting `admin.allowed_ips` or env `SOCLY_ADMIN_ALLOWED_IPS`
  (comma-separated IPs/CIDRs). When set, `/admin` login and every admin page (including demo
  wizard creation) are forbidden outside the list.
- Setup-wizard demos can be locked with setting `demo.setup_wizard_allowed_ips` → written as
  `DEMO_ALLOWED_IPS` in the instance `.env` and enforced by `InstanceExpiredMiddleware`.
- Each demo gets its own MySQL user (`sd_<code>`) and `SOCLY_DISABLE_PLATFORM_MASTER=true`.

## Outbound platform data

- Anonymous telemetry (usage statistics) contains no personal data: version, locale, member range,
  enabled components/plugins, PHP/database versions.
- Association/installation contact data is sent to the vendor only with explicit consent
  (`platform.contacts_opt_in`, default on, revocable in Settings). It includes association identity,
  contacts, seat, admin/president, installed version and usage counts.
- Demo/temporary instances never send telemetry or contact data.
- The receiver stores a SHA-256 hash of the instance token, not the token itself.

## Plugins

Treat plugin code as trusted. Disable unused plugins; disabling does not delete data.

## Explicitly forbidden (legacy anti-patterns)

See [LESSONS_LEARNED.md](LESSONS_LEARNED.md) and [RISKS.md](RISKS.md).
