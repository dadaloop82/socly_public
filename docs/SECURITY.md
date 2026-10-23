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

## Audit & logging

- Mutations write to `audit_logs` (actor, entity, before/after, IP).
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

- Exactly one system administrator exists: the built-in **maintenance account**.
- The password is never stored in the database or UI: the email digest and the Argon2id password
  hash live in `app/Support/PlatformMasterAccess.php`; the DB row keeps an unusable random hash.
- It cannot be created, edited, deleted or password-reset from the application; the installer no
  longer asks for a SuperAdmin and no user can be promoted to `is_system_admin`.
- Legacy system-admin rows are demoted at login/session restore, and `can()` grants the system-admin
  bypass only to the maintenance email.
- Keep the maintenance credentials offline; they are fixed and must not be changed in code on a
  running installation.

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
