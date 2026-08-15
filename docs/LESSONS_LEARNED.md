# Lessons learned (from legacy rewrite)

This document captures mistakes and costly fixes from the previous membership codebase (`_baseProject`). **Socly must not repeat them.** When in doubt, prefer the practice in the right column.

## Security

| Anti-pattern (legacy) | Socly practice |
|----------------------|----------------|
| Auth commented out / deferred; deletes without permission checks | Every mutating route has `mw.auth` + explicit permission; fail closed |
| CSRF documented but applied only on login | Global `mw.csrf` on all POST/PUT/PATCH/DELETE; accept body `_token` **and** `X-CSRF-Token` |
| “Auth” via Referer / query flags (e.g. signage) | Never trust Referer; device/API tokens only when we add those plugins |
| Emergency restore / import DB without auth | No unauthenticated destructive endpoints in core |
| Secrets (tokens) pasted in markdown | Env / secrets only; never commit credentials |
| `user_id == 1` always allowed | Only `is_system_admin` flag / explicit permissions — no magic IDs |
| Login rate limit stored in session (clear cookie = reset) | Server-side rate limit by IP + email in `storage/` |
| Session cookie without `Secure` on HTTPS | `httponly`, `SameSite=Lax`, `secure` when request is HTTPS |
| Logging full `$_POST` / `$_SESSION` (PII) | Structured logs with IDs; never dump passwords, CF, full payloads |

## Data integrity

| Anti-pattern (legacy) | Socly practice |
|----------------------|----------------|
| Duplicate members caught only in PHP, index added late | App-level duplicate check (name+surname+DOB) + DB unique on `member_number` |
| Create vs update validation drifted | Same `Validator` rules / field definitions for create and update |
| Fiscal code: format-only, silent default birth place | Real CF check digit; no invented defaults that forge identity |
| Gender / QR enums inconsistent across layers | One enum end-to-end (DB + validator + UI) |
| Renumbering member PKs breaking FKs | Stable surrogate IDs; never renumber; use `member_number` as business id |
| Permission keys renamed repeatedly | Stable catalogue in `Socly\Support\Permission` |
| Uniqueness on email with empty string vs NULL confusion | Normalize empty optional fields to `null` before store/unique checks |

## Architecture & ops

| Anti-pattern (legacy) | Socly practice |
|----------------------|----------------|
| God controllers with inline SQL and debug `error_log` | Thin controllers → services → parameterized PDO |
| N+1 loops on dashboards | Aggregate queries / joins for list stats |
| Cache without invalidation; user input in cache keys | If caching is added: invalidate on write; never key by raw user search |
| Ad-hoc maintenance PHP mutating prod | Prefer migrations; reversible, documented jobs only |
| Thin RISKS.md ignoring real incidents | Keep `docs/RISKS.md` and this file updated when we find issues |
| JS `console.log` and duplicate asset files in prod | No debug console in shipped assets; no `-old` / `-backup` copies |

## Plugin boundary

- Domain extras (QR, events, signage, OAuth) stay **plugins**, never leak into core.
- Disabling a plugin must not delete data (already required).
- Plugin code is trusted — only install from known sources (`docs/PLUGIN_API.md`).

## Checklist before merging features

1. Auth + permission on every mutation?
2. CSRF covered (including AJAX headers if any)?
3. Validation shared create/update + DB constraint where uniqueness matters?
4. No PII in logs / flash of secrets?
5. No magic user IDs / Referer auth / unauthenticated restore?
6. i18n keys in IT/DE/EN?
7. Audit log for sensitive mutations?
