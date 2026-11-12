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
period/type and profile fields. The single **maintenance SuperAdmin** (`admin@socly.it`) is hardcoded in
`Socly\Support\PlatformMasterAccess` (email digest + Argon2id password hash); the database row is
created on first maintenance login with an unusable random DB password. On real installations the
first-run setup requires that login; public demos stay anonymous. Association users are created by the
first-run setup (`/setup`) with granular permissions and never receive `is_system_admin`.

## Plugins

See [PLUGIN_API.md](PLUGIN_API.md). Plugins are discovered by scanning `plugins/*.php`. Only rows marked enabled in `plugins` are booted. Disabling a plugin unloads hooks; data remains.

## Internationalization

Translations are PHP arrays under `lang/{locale}/`. See [I18N.md](I18N.md).

## Dashboard & statistics widgets

Chart cards in the dashboard, statistics and treasury panels share one layout engine.
`WidgetLayoutService` keeps a per-user JSON in `user_widget_layouts` (`order`, `hidden`, `sizes`) and
`POST /api/widgets/layout` stores it; the in-card controls (drag, move up/down, hide, width cycle and
the bottom-right resize grip) are mounted client-side by `initWidgetLayouts()`.

The grid is three columns wide (`data-chart-size` spans 1–3, dense packing). It becomes visible while
dragging or resizing — and while the “manage charts” panel is open — so cards can be placed side by
side, and the reorder previews live before the drop. Every change survives a reload.

The dashboard **Treasury** tab carries eight cards fed by `TreasuryService::dashboardCharts()` (approved,
non-superseded movements only): income vs expense, month-by-month income/expense of the last 12 months,
the running balance at the end of each month (a transfer between own wallets never moves it), the
liquidity per account from `accountBalances()` (negative wallets in red), the top five expense
beneficiaries, the approval queue (approved vs pending) and the two category splits (expenses and
income). Month labels reuse the shared `common.month_N` keys.

Chart legends are dropped above **three** entries (`chartLegendOptions()` in `app.js`): the tooltip
already names every series, and a longer legend takes more room than the plot itself.

The bottom-right tools also export the card as a PNG (`copyChartToPng()`): the canvas is redrawn on a
solid background with the card title, copied through the async clipboard when available and
downloaded otherwise (plain-HTTP installs are not a secure context, so they always fall back to the
file).

## Geocoding (statistics map)

`GeoService` caches coordinates in `storage/cache/geo_city_coords.json`. Lookups happen
automatically, never in the critical path:

- saving a member queues the residence / birth place for geocoding **after** the response
  (`register_shutdown_function()` + `fastcgi_finish_request()` when available);
- the deferred pass closes the PHP session first (`session_write_close()`), so the browser's next
  request never waits on the session lock while the geocoder runs;
- each statistics visit runs a throttled background pass (25 places, 30 s lock) over the places still
  missing, so an existing archive fills the map visit after visit;
- failed lookups are stored with a timestamp and retried after a week, so a geocoder outage heals
  itself instead of leaving “not found” forever.

`bin/geo-warm.php` remains an optional bulk / `--force` pass. No CLI step is required for the map to
complete.

## Budget editor

`budgets/show` is an inline grid: sections (macro-categories) and lines with
type / description / amount. The form autosaves through `POST /budgets/{id}` after 5 seconds of
inactivity (and flushes with `keepalive` when leaving mid-edit):

- the request asks for JSON (`Accept: application/json` or `X-Requested-With: XMLHttpRequest`);
- the controller answers with the ids of the sections and lines it created, so the page updates its
  hidden `item_id[]` / `section_id[]` inputs and the next save updates the rows instead of inserting
  duplicates;
- categories are resolved **before** the transaction, so a new label registers a treasury custom
  category and an invalid one never leaves half-saved rows;
- one document per year and one open draft at a time are enforced by the controller: the create form
  lists only the free years and links the open draft until it is approved or deleted.

## Similar movements (bulk classification)

`POST /treasury/similar` takes the direction, description (or beneficiary) and the classification the
operator is setting (type, Model D line, cost centre) and returns the similar entries.
`TreasuryService::similarMovements()` filters by `direction` (income/expense are never mixed), scores
the last 400 descriptions with Sørensen–Dice over stop-word-filtered tokens plus a containment bonus,
and keeps everything above 0.45. The form shows up to three examples, the summary of changes and a
one-click `POST /treasury/similar/apply`, which updates the chosen rows through
`applyClassification()` (audit `treasury.classified_bulk`). No external service, fully deterministic.

## Bulk edit of the selection

`POST /treasury/bulk` with `action=update` reclassifies every selected movement at once
(`TreasuryService::updateMany()`): financial account, type, Model D line, cost centre, description and
notes. Each field carries a “keep” value (`''`), so only the chosen ones overwrite what was there;
`__none__` clears the Model D line or the cost centre, while the description and the notes are
replaced only when their checkbox is ticked (the description is normalised like the single form: first
letter upper case, max 500 characters). The dialog summarises the changes and the number of movements
before sending the request; the service skips historical (`superseded_at`) rows, applies only
account/description/notes to internal transfers and writes one `treasury.bulk_updated` audit row per
movement (before/after), so the “modified by” trail and the freshness banner keep working. Row versions
are bumped: a form left open elsewhere reopens on the conflict banner instead of overwriting.

## Concurrent editing (optimistic locking)
Editable records carry `row_version`. Every form posts it in the hidden `row_version` field
(`version_field()` / `version_attrs()` helpers + `partials/record_lock_notice.php`), and the service
writes with `Database::updateVersioned()`: the `UPDATE` only succeeds when the version still matches,
so two operators can never silently overwrite each other.

- **On save conflict**: nothing is written. The controller reopens the form with the submitted values
  (`rememberOld`) and a banner naming who saved last and when (from `audit_logs`), with “Reload
  updated data” (fresh record) and “Overwrite with my data” (`force=1`, which bumps the version).
- **While editing**: `GET /freshness?entity=&id=&version=` is polled every 15 s (`initFreshnessGuard`);
  when the version moved, an inline banner offers a reload without touching the typed values.
- **Who is editing**: the presence heartbeat carries `editing=entity:id` (plus the focused `field` and
  its draft value); `/presence/state` returns `editors` for that record and the form shows “X is
  editing this record”.
- **Wired entities**: members, first-note movements and forecast budgets (including the 5 s autosave,
  which returns the new `row_version` and stops on HTTP 409 instead of overwriting). The version
  column and the endpoint are already in place for documents, deadlines and association people.

## Presence and concurrent operators

Every operator has its own PHP session file (`storage/sessions`), so concurrent users never wait on
each other; long tasks (geocoding, presence) call `session_write_close()` before background work so
the user's next click is not blocked.

`PresenceService` keeps a row per open session in `user_presence` (session id, user, `connected_at`,
`last_seen`, `ended_at`). `GET /presence/state` is polled by `initPresence()` every 12 s: it touches
the current session and returns the connected people (one entry per person, merged across tabs) with
name, initials, a stable hue (`(user_id * 137) % 360`), an `idle` flag (`last_seen` older than 5
minutes) and the `new` arrivals since the previous poll (used for the “X just connected” toast).
`/logout` marks the session as ended. The identity is `users.first_name`/`users.last_name`, else the
linked `users.member_id` profile name, else “Unknown user”.

**Field-level presence** — while a record is open the heartbeat also carries the focused `field` and
the draft `value` (`editing_field` / `editing_value`, migrations 045/046). The field another operator
is working in is outlined in their colour, locked for everyone else (read-only for
text/date/textarea, blocked for selects and checkboxes — the row version stays the real safety net)
and shows their draft live underneath. 45 seconds of silence (`FIELD_TTL_SECONDS`) release it, so a
closed tab or a crash cannot keep a field blocked; values travel on focus change and ~0.9 s after the
last keystroke, never for password/file/checkbox controls. A `<select>` changed by another operator is
previewed in their colour and restored on release, and the operator who is only watching never saves
it (submit guard).

**Jumping to a colleague** — each badge carries the record the operator has open (`editing_entity` /
`editing_id`), and when none is open the screen they are on (`page_path`: local path + fragment, query
string always stripped). The URL is built server-side (`PresenceService::locationUrl()` for the record,
`pageUrl()` for the screen, mapped by path prefix to the same component flag and permission as the
page), so a click (or Enter) opens exactly that member / movement / budget / list: no client-supplied
path, no link for the own badge, for idle sessions, for unknown paths or when the viewer could not open
the page anyway. Switching tab or section inside a page (hash change) publishes the new screen at once.

## Budgets

`/budgets` (menu **Bilanci**) hosts three tabs — `data-budget-tabs` in the markup, active tab kept in
the URL hash (`#bil-preventivo`, `#bil-consuntivo`, `#bil-statistiche`):

- **Preventivo**: the forecast documents (cards, create form, CSV import);
- **Consuntivo**: the RUNTS year-end list that opens `/rendiconto/{year}`;
- **Statistiche**: `BudgetService::statistics()` reads the forecast lines and the approved cash-book
  movements and returns, per year (last 5), planned vs actual expense/income with expense coverage and
  funding need, plus the membership-fee share of income and the top expense/income categories of the
  latest year that actually has movements.

## Year-end report (RUNTS — Model D)

`/rendiconto/{year}` is the **Bilancio consuntivo**, rendered inside the Bilanci page (`/budgets`) as
the **Consuntivo** tab; `/rendiconto` redirects to `/budgets#bil-consuntivo`.
`RuntsReportService` rebuilds the ministerial **Rendiconto per cassa (Model D, DM 18 April 2020)**
from the approved cash-book movements: sections A–E with their official rows, totals, the cash and
bank statement (opening + year balance = closing) and the optional figurative block; the sheet is
printed with the browser (legal header, place/date and signature line).

- Each movement carries `runts_line` (automatic from the treasury category, overridable in the form);
  internal movements (transfers, deposits/withdrawals) are excluded from the statements but still
  count in the cash balances.
- The “Pezze giustificative” column of the printed sheet lists only the movements that carry a
  document (invoice/receipt number or a document type), one line each — date · number · amount; a cost
  centre without documented movements shows “—”.
- The legal threshold is **€ 220,000** of income per year (`RuntsReportService::CASH_LIMIT`). Below it
  the Model D is generated; at or above it the page states that the accrual statements (Models A/B/C)
  must be prepared manually — Socly does not generate them yet.
- The old “Rendiconto” link of a budget is now labelled **Piano finanziario** (forecast vs actuals).

## Design principles

- One installation = one association.
- Core stays generic; domain-specific features belong in plugins.
- Prefer explicit validation rules on every field.
- Prefer auditability for mutating operations.
