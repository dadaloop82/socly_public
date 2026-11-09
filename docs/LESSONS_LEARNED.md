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
| User-facing SuperAdmin creation | Single built-in maintenance account; `is_system_admin` is never settable from the UI |
| Uniqueness on email with empty string vs NULL confusion | Normalize empty optional fields to `null` before store/unique checks |

## Document extraction (OCR)

| Anti-pattern | Socly practice |
|--------------|----------------|
| Assuming `pdftoppm` writes `<prefix>-1.png` for every page | With `-f/-l` poppler names files after the real page number (`p3-3.png`); resolve with `glob($prefix . '-*.png')` |
| Silent page/time caps on legal-document OCR | The background worker reads every page (no cap, 30-minute budget); caps exist only as documented guards |
| Injecting “text truncated…” notes into extracted text | Return `text` + `complete` + `truncated` separately; show a UI warning and never mutate the official document |
| Copying OCR output into every locale | Store Italian only (`{it, de:"", en:""}`) and translate later |
| Byte caps that silently rewrite settings | Legal texts are `LONGTEXT`: keep caps generous and never persist shortened copies with markers |
| Probing absolute paths (`/usr/bin/pdftotext`, `/usr/bin/php8.4`) with a bare `is_file()` | Plesk sets `open_basedir`: the probe raises a warning, our error handler throws and OCR/worker discovery dies with a misleading message. Probe with `@`, add `PHP_BINDIR`, then fall back to `command -v` (exec is not bound by `open_basedir`) and trust its absolute answer |
| One `try/catch` around download **and** OCR in the setup import | Keep them separate: `runts_docs_fail` only when attachments could not be downloaded; OCR failures get their own key (`runts_docs_ocr_fail`) and a logged `setup.runts_docs_ocr_failed` |

## Frontend (UI/JS)

| Anti-pattern | Socly practice |
|--------------|----------------|
| Calling `form.requestSubmit()` while a modal `<dialog>` is open | The rest of the page is inert, so constraint validation shows no bubble and cannot focus the field: the click looks like it does nothing. Close the dialog first, then submit and fall back to `reportValidity()` if the submit was blocked |
| Submitting a form programmatically without its save button | `requestSubmit()` without a submitter drops the button's name/value (`action=save`, `data-tpl-submit`) and skips its click handlers: pick the first non-destructive submit button and `.click()` it |

## Geocoding (addresses)

| Anti-pattern | Socly practice |
|--------------|----------------|
| Asking Photon for `osm_tag=highway` only | Squares and parks are `place`/`leisure` features: “Piazza Matteotti” came back empty. Skip the filter for square-ish names (piazza/largo/parco/platz…) and retry unfiltered when the filtered pass has nothing usable |
| Comparing the typed street with plain equality against the OSM name | OSM names are often bilingual (“Piazza Giacomo Matteotti - Giacomo-Matteotti-Platz”): split on ` - `/` / `, normalize, and accept a typed name when every one of its words appears in the canonical one (“Piazza Matteotti” ⊂ “Piazza Giacomo Matteotti”) |
| Expecting the street field to hold only the street | Users paste the whole address (“Piazza Matteotti a Bolzano 39100”): strip a trailing “, city [CAP]”, “a/in city [CAP]” or “city CAP” before searching, but only with an explicit separator so “Via Roma” in Rome is never truncated |

## Setup & settings

| Anti-pattern | Socly practice |
|--------------|----------------|
| Re-requiring every wizard field when the user edits settings | Validate only what belongs to the section being saved: a step deferred in the wizard (“do it later”) must stay empty without blocking an unrelated edit (logo, colours, one field). Formats are still checked whenever a value is typed; only the association name is mandatory |
| Letting an automatic source (website scrape) win over an explicit user choice | Record who chose: `branding.logo_source = user` on a manual upload/pick, `scraped` when the scraper fills an empty slot. Scrapers may only fill gaps — never replace, and never resurrect a logo the user removed |
| Skipping a step because a guess filled it | Show the value and label its origin (“suggested by your site”), so the user can replace it before finishing |

## Architecture & ops

| Anti-pattern (legacy) | Socly practice |
|----------------------|----------------|
| God controllers with inline SQL and debug `error_log` | Thin controllers → services → parameterized PDO |
| N+1 loops on dashboards | Aggregate queries / joins for list stats |
| Cache without invalidation; user input in cache keys | If caching is added: invalidate on write; never key by raw user search |
| Ad-hoc maintenance PHP mutating prod | Prefer migrations; reversible, documented jobs only |
| Thin RISKS.md ignoring real incidents | Keep `docs/RISKS.md` and this file updated when we find issues |
| Telemetry counters on guessed table/column names wrapped in `catch` → silent zeros | Check names against migrations; never hide “empty stats” behind a catch-all |
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
8. OCR output that feeds official documents verified on a **multi-page** scanned PDF (page coverage,
   `complete` flag), not only on the first page?
