# Changelog

All notable changes to the public Socly base product are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versions follow the automatic `1.<series>.<patch>` scheme: every commit on `main` bumps the patch,
every 100 patches bump the series, and each bump is published to this repository by the release sync.

## [Unreleased]

### Added

- Dashboard, Statistics and treasury charts: drag a card to reorder it while the three-column grid
  shows the available slots, resize it to one third, two thirds or the full row (width button or the
  bottom-right handle) and hide the cards you do not need; the layout is saved per user.
- Chart cards: copy the chart as a PNG from the bottom-right tools (clipboard when the browser allows
  it, otherwise the image is downloaded) — the export keeps the card title and a solid background.
- Budget lines can create a new treasury category on the spot (“+ New category…”), no need to fall
  back to “Other”; the label is then shared with movements, documents and deadlines.
- Budget grid autosaves 5 seconds after the last change (also when adding/removing lines), with a
  discreet status next to “Save draft” and a background flush when leaving mid-edit.

### Changed

- Budget line fields are now **Expense / income type** (treasury category), **Description** (free
  text) and **Type**, with new types for associations: Expense, Capital expense, Project/activity
  expense, Income, Grant/restricted income (the type sets the accounting sign, so totals and the
  comparison with the cash book stay correct). A section is labelled **Macro-category**.

### Fixed

- The chart grid now packs cards densely, so a resized card sits next to its neighbours instead of
  leaving an empty column.
- The statistics map completes itself: new or changed places are geocoded automatically after a
  member save and during statistics visits, so the “run `php bin/geo-warm.php`” notice is gone
  (the command stays available as an optional bulk warm-up).
- Appointing someone to an association body no longer requires a settled membership fee: open
  balances are a treasury matter and are ignored in the org chart, both in the picker and on save.
- Budget: the CSV upload now uses the same drag-and-drop area as the other uploads, amounts keep
  the thousands separator and the currency symbol no longer overlaps the number.
- Budget: creating a second budget for a year that already has one is no longer proposed, and while
  a draft is open the page points to it (finish/approve or delete it) instead of offering a new one.
- Budget cards give the amounts more room: a single budget fills the row, the KPI columns grow with
  the card and the currency symbol never wraps onto its own line.
- Saving an org-chart appointment no longer hangs for minutes (the background geocoding used to keep
  the PHP session locked, so the next click waited for it): the deferred pass now releases the session
  first, and the save button shows “Saving…” while it ignores double clicks.
- Org chart: the member picker now shows the position a member already holds (e.g. “Mario Rossi —
  Presidente”) instead of the “member since …” note, so you never assign the same person twice by
  mistake.

## [1.5.41] - 2026-11-10

This entry covers everything published up to v1.5.41 (the automatic sync published intermediate
versions without curated notes).

### Added

- Public source-available distribution of the Socly base edition
- Installation wizard, membership core, plugins API, and multi-language UI
- Setup wizard warning when statute/privacy OCR could not read every page
- Installation guide (`docs/INSTALLATION.md`) covering Apache/Nginx, permissions, database, optional
  OCR tools, updates and troubleshooting
- Bilingual README (Italian and English accordions) and bilingual installation guide
- Setup/settings consent to send association and installation details to Socly (default on,
  revocable; demo instances excluded)
- Socly admin “Installazioni” registry with filters and full contact cards, plus a platform dashboard
  with charts (versions, languages, member ranges, components, provinces, activity)
- One-click installers for Linux (`bin/install/linux.sh`), macOS (`bin/install/macos.sh`) and Windows
  (`bin/install/windows.ps1`): environment checks, PHP/Composer/MariaDB/OCR setup, database creation,
  `.env`, migrations and local server
- Single built-in maintenance SuperAdmin (hardcoded credentials); the installer no longer asks for or
  creates a SuperAdmin, and users cannot be promoted to system administrator
- `/install` now only wires the database; the guided setup wizard is the real configuration flow, with
  a treasury step that can create custom cash accounts
- Member import from any CSV with guided column mapping, a preview of the first five rows and search
  across every row of the file; importing updates changed values instead of wiping existing data
- Server-side QR membership card rendered as SVG (MIT-licensed encoder vendored)
- Automatic reminder of the membership cards to renew for each period, with a counter that decreases
  on every renewal
- Yearly forecast budget with actuals derived from the cash book
- Two-level settings index (macro areas → sections), one section at a time
- CLI updater (`bin/update.php`) sharing the same engine as the sidebar “Install” button
- RUNTS-first onboarding step with a “fill in the fields manually” link; the fetch button stays
  visible (disabled) and pulses clearly once a code is entered

### Fixed

- RUNTS legal-text prefill now OCRs **every page** of statute and privacy PDFs (previously only the
  first page was read because `pdftoppm` names output files after the real page number)
- Statute and privacy texts are no longer truncated by page/byte caps and never contain synthetic
  “text truncated…” notes inside the official document
- Long scanned PDFs are read with no page cap in the background worker and with a larger time budget
- “Fetch RUNTS data”, “Fetch website data” and “Retry” buttons pulse smoothly when they are ready to
  be pressed; the “more content below” ↓ cue blinks smoothly (both honour reduced motion)
- Typing a RUNTS number no longer locks the name/legal fields before the lookup (required-field error)
- President-name confirmation is case-insensitive and ignores apostrophes, hyphens and spacing;
  the two confirmation inputs are no longer auto-capitalised
- German denomination field is shown only for South Tyrol associations (or when already filled)
- Member list and search: crash on search, avatars, gender/age/birthday columns, card expiry under the
  membership type, pager, column sorting and multi-row trash
- Branding: the logo refreshes immediately across the page after upload (sidebar, guest layout,
  org chart); horizontal logos are accepted and the upload outcome is shown in settings
- Import: cash book entries are dated with the payment date
- Settings: the outcome banner stays anchored when returning to a section and the “unsaved changes”
  save button actually saves
- Setup: no login form before the configuration is complete, no hangs, no silent database password
  reset and no duplicated PHP/DB stack
- OCR binaries and the PHP CLI are detected inside an `open_basedir` cage
- Legal editor fills the viewport in full screen and AI translation output is bounded
- Address lookup finds squares and “piazza” names
- Registry export no longer flags a residence that is disabled or optional in the member profile
- Member list warning (red triangle) now also flags a missing fiscal code or birth date — matching the
  registry export — even when those fields are optional in the member form
- The missing-data triangle is red and pulsing, the “Missing: …” label is translated in IT/DE/EN, the
  list shows a banner with the number of incomplete members (one click filters only those) and the
  member page offers “Fix automatically” when the fiscal code can be derived from stored data
- The incomplete-members banner is red and can fix the whole list at once (“Fix N automatically”),
  reporting how many were corrected and how many lack the data needed; values are only derived from
  stored data, never invented
- The bulk-fix button is enabled only when the derivation really succeeds (the count runs the same
  routine as the fix); otherwise it is disabled and reads “Cannot fix them automatically”
- One-click and scheduled updates now take a backup of the previous version (code archive + database
  dump), verify the new version and the migrations, and roll back automatically on failure, reporting
  version, backup path and outcome in the UI flash, the CLI and the audit log
- First-note movements generated automatically (membership payments, imports) now require manual
  approval: they are listed as “Awaiting approval” and do not affect balances, charts, budgets or
  reports until approved (one by one or in bulk); manually entered movements are approved on save
- First note: multi-select with bulk approve and delete, like the member list
- First note: pagination (50 rows per page, search/filters/sort preserved) and a “select all”
  checkbox on every ledger table
- First note: approval-status filter (all / awaiting approval / approved)
- In-app update works out of the box on git checkouts: the sidebar shows **Update** (with confirmation)
  instead of “Download” and runs the whole procedure — backup of code and database, pull, migrations,
  verification and automatic rollback on failure; the update is refused when the database backup
  cannot be taken, and `UPDATE_DISABLED=true` turns it off explicitly
- Approving a single movement or a bulk selection no longer reloads the page: rows update in place and
  filters, page and scroll position are preserved
- Membership card: larger QR, watermark anchored to the top-left corner, gender + birth month/year
  under the name, membership type as large as the card number, member-since and expiry dates
- Member edit: consent rows (photo release, newsletter, phone notices) render as one full-width line
  with the checkbox on the left and a readable label
- Org chart: the member picker lists every member (eligible ones first) with a “not active” /
  “fee outstanding” suffix, instead of hiding members whose fee is unpaid
- Org chart: a checkbox declares that the association has no control body — the auditor/ombudsman
  roles and the “incomplete configuration” reminder disappear
- Dashboard: KPIs turn red/orange when expired memberships, suspended members or unpaid fees are
  above zero
- Dashboard chart: income still awaiting approval is drawn as a grey translucent segment (chart only,
  never in balances or totals)
- Member list: the payment badge now shows when and how much was paid last
  (`Paid (50.00 € on 09/11/2026)`); the “Balance due” column became **Standing**, flagging the
  outstanding balance, a credit, an expired card or “all good”
- Member page: previous/next arrows to walk through the members without going back to the list
- Membership card on the member page: association name, person name and card number are noticeably
  larger
- Wording (IT): payment states now read **Saldato / Parzialmente saldato / Da saldare / Non saldato**
  instead of “Pagato / Parziale / Da pagare / Non pagato”; “Importo pagato” became “Totale versato”
- Update check: when `git fetch` fails on a git checkout (typically the web server has no SSH key for
  the private remote) the instance no longer claims to be up to date — it falls back to the public
  `latest.json` and reports the newer release, with a hint to update from the command line
- Update check: the cached snapshot is invalidated when the installed version differs from the one it
  was taken on
- Updates: optional `UPDATE_SSH_KEY` lets the PHP user read a deploy key; `GIT_TERMINAL_PROMPT=0` and
  `BatchMode=yes` stop a web request from hanging on a credential prompt
- Updates: `php bin/update.php --apply --force` applies the remote branch even when the check reports
  “nothing to do” (recovery for an instance stuck on an older release)
- Dashboard (members tab): new **Card distribution** doughnut (active / expired / revoked) plus a
  **Detailed statistics** button
- New **Statistics** page (`/statistics`, last entry of the sidebar menu): KPI row (members, active
  members, cards issued, active cards, expiring soon, collected, members owing, settled share) and
  four tabs — Demographics (gender, age bands, e-mail provider, cities, language), Sign-ups
  (24-month trend, seasons, weekday, top-10 months table), Cards (status, type, year) and Financial
  (12-month collections, by method, by reason). Every chart is a bar or a doughnut built from real
  data; events, attendance and feedback are not offered because the product stores no such data
- Statistics: **Cities of residence** is now a map — Italy outline generated from Natural Earth
  (public domain) with proportional bubbles and distance clustering; unknown cities are geocoded once
  and cached (`storage/cache/geo_city_coords.json`), with the top cities listed under the map
- Statistics map: uses the **city of residence**, falling back to the **place of birth** (the field
  that feeds the fiscal code) for members without it; countries are grouped under “Abroad”, and
  `php bin/geo-warm.php` pre-computes every coordinate so the map is complete and works offline
- **Fix**: the in-app update could never finish — the post-merge verification compared the version
  from the in-memory config (built before the merge) with itself and always rolled back with
  `verify_version_unchanged`. The version is now read from the `VERSION` file on disk
  (`clearstatcache` included) and the running config is realigned via `App::setConfigValue()`
- Update safety: success is verified by the **commit SHA** as well (a fast-forward that does not bump
  `VERSION` is still an update), the schema check runs **before** the migrations, and the database
  dump is re-imported on rollback **only when migrations were actually applied** — so a failed
  verification no longer does a full `DROP/CREATE` re-import on a live database
- Update: opcache is flushed after the merge (`opcache.validate_timestamps=0` servers pick up the new
  code immediately), and `UPDATE_ENABLED=false` now really disables the updater (empty = auto-detect)
- Update: live progress overlay (backup → download → merge → migrate → verify), polled from
  `/api/updates/progress`, and `set_time_limit(0)` so the request cannot time out mid-update
- Statistics: age bands follow the reference report (largest band first, share printed above each
  bar); e-mail providers and preferred language removed
- Statistics: the sign-ups chart now covers the **whole period** instead of the last 24 months, and
  seasons show their share
- The registry PDF hides the fiscal code, birth and residence columns when those profile fields are
  disabled
- The registry PDF prints the membership-card QR next to the card number when the card stores one
- Dashboard charts count new members by registration date (`admitted_at`, falling back to
  `created_at`): imported members no longer pile up in the import month
- Payments can be recorded with their real date (new “payment date” field, default today); charts and
  the first note use that date
- Dashboard collections chart stacks each month by payment account (cash / bank / other) with
  distinct colours; the tooltip also shows the month total
- Fixed a crash (fatal error page) on every member page: the service binding was missing the new
  database dependency
- Import/export/download actions use bare 80%-opacity icons with tooltips everywhere (member list,
  organisation chart exports, budget report, documents, import error report)
- Anonymous telemetry counters pointed at non-existent table/column names and always reported zero

### Changed

- Fresh installations start with only **Cassa contanti** and **Banca** active; postal, PayPal,
  prepaid card and “other” remain available but disabled until enabled during setup or in settings.
- Legal texts are stored with a generous 400 000-character cap per locale and partial OCR is surfaced
  as a warning instead of silently shortening the document
- Texts cut by the previous OCR build are refreshed automatically on the next RUNTS lookup
- Anonymous telemetry now includes exact member/user counts, plugins and PHP/database versions
- Installation cards are refreshed on consent save, settings changes, update checks/installs, daily
  and immediately when the installed version changes
- Public showcase consent was removed from the wizard and the admin panel (existing data is retained)
- Trial/showcase instances detect new releases without running the guided setup, and the login footer
  always shows the installed version plus the update notice
- Long configuration pages were split into macro areas with one section open at a time
- Member detail page shows the filled membership card (same artwork as the wizard preview) under the
  sidebar panel
- Membership card shows the QR code in the top-right corner when the card has one (detail page and
  edit preview); the card in the member sidebar is full width with larger photo, name, number, type
  and QR, so the content fills the badge like a real membership card
- Sidebar card is capped at 200px height (no aspect-ratio) on wide screens, the QR is larger, the
  gender avatar is shown when there is no photo, and the physical card code appears when it differs
  from the member number
- Member edit: “Save” is available on every wizard step and “Back” on the first step leaves the
  wizard, so a single change no longer requires walking through all steps; the card preview sits
  under the photo block
- Member types editor: the annual fee, its € suffix and the “Active” checkbox share one compact row
  (no more € detached from the amount and no full-width checkbox box)
- CSV import remembers the choices made for the same file layout (register in first note / treasury,
  payment method, update strategy, duplicate policy) and proposes them again on the next import
- CSV import is idempotent: one imported payment per member, updated when amount, date or method
  change instead of being duplicated; the first-note movement is created only when missing and
  realigned when the payment changes
- One placeholder avatar per gender, shared by the member page, the photo field and the membership
  card preview (the card and the detail page no longer draw their own silhouettes)
- Member list actions: import and export are icon buttons, “Registry” sits after the primary actions

## [1.0.0] - 2026-08-15

### Added

- Initial public release synchronized from the Socly product line
- Free self-hosted base edition under the Socly Source-Available License
