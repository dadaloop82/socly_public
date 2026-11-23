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

- Exactly one system administrator exists: the built-in **maintenance account** (`admin@socly.it`).
- The email is public and fixed in code (HMAC digest). The **password is per-install**: Argon2id hash
  in `.env` as `PLATFORM_MASTER_PASSWORD_HASH` (never committed). The DB row keeps an unusable random
  hash. Create or rotate with `php bin/platform-master-password.php`.
- Set `SOCLY_DISABLE_PLATFORM_MASTER=true` to refuse master login entirely (recommended when only a
  local association admin is used).
- It cannot be created, edited, deleted or password-reset from the UI; no other user can be promoted
  to `is_system_admin`.
- On real installations the first-run setup requires the maintenance login; public demo instances
  keep anonymous setup. Fresh installs write a one-shot credential file under
  `storage/PLATFORM_MASTER_CREDENTIALS.txt` (mode 0600) — delete after first use.
- Legacy system-admin rows are demoted at login/session restore, and `can()` grants the system-admin
  bypass only to the maintenance email.
- Keep the maintenance credentials offline. Never put a shared password hash back into the repository.

## Public endpoints

- `GET /api/updates/check` — guests receive only version availability (no repo URL / commit /
  download links). Admins get the full payload. Guests are rate-limited per IP.
- `GET /i18n/messages` — required on the login language switcher; rate-limited per IP.
- `public/_debug*` and `public/router.php` are blocked by `.htaccess` and must not be deployed.

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
