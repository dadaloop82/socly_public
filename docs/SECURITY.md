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

## Plugins

Treat plugin code as trusted. Disable unused plugins; disabling does not delete data.

## Explicitly forbidden (legacy anti-patterns)

See [LESSONS_LEARNED.md](LESSONS_LEARNED.md) and [RISKS.md](RISKS.md).
