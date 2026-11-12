# Changelog

All notable changes to the public Socly base product are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versions follow the automatic `1.<series>.<patch>` scheme: every commit on `main` bumps the patch,
every 100 patches bump the series, and each bump is published to this repository by the release sync.

## [Unreleased]

### Added

- The treasury tab of the dashboard now carries five more charts on top of the three existing ones:
  income and expense **month by month** over the last 12 months, the **running balance** at the end
  of each month, the **liquidity per account** (cash desk, bank, … with negative wallets in red), the
  **top beneficiaries** of the expenses and the **movement status** (approved vs awaiting approval).
  They obey the widget manager like the others: move, hide, reload.
- The badge of another operator now opens **exactly the record they are working in**: the link is
  built server-side (same component flag and permission as the page, so no link when you could not
  open it anyway), travels with the heartbeat and a click (or Enter) jumps there. When no versioned
  record is open the badge opens the **screen they are on** (list, dashboard, chosen tab): the
  heartbeat now reports the current path + fragment, the query string is always stripped and the
  link is rebuilt server-side.
- The ledger cost-centre filter now groups the lines by budget and section (“2026 · Personnel”) exactly
  like the “Voce del bilancio consuntivo (Modello D)” filter, so two sections repeating a wording can no
  longer be confused; the summaries keep reading “year · section · line”.
- The free-text description (“causale”) of a movement is stored **capitalised** — first letter upper
  case, the rest lower case — whatever the operator typed, so the ledger, the CSV export and the
  reports read consistently.
- Charts drop their legend as soon as it has more than three entries: a wall of colours over the plot
  is unreadable and the tooltip already names every series.

- Budgets drill down to the first note: clicking a cost-centre line in “Forecast vs actuals”, a type in
  the “not imputed” table, a year row or a top category of the statistics tab opens the ledger
  filtered on exactly the movements that compose that figure (same year, approved only), so every
  number can be checked movement by movement. The filter survives the pager, “Back” and the movement
  card; the budget page header offers the same shortcut for the whole year.
- The field held by another operator is now **locked** for you (read-only for text fields, blocked for
  selects and checkboxes, in the person's colour) until they leave it, and their **draft value** is
  shown live under the field, so you see the change as they type it. 45 seconds of silence (closed
  tab, crash) release the field; the server keeps refusing stale saves, so the lock is a courtesy,
  not the safety net.
- The “Documento” box of a movement is now behind a flag (“This movement has a document”): hidden by
  default, it opens when the operator asks for it and starts open when the record already has a
  document (type, number, date, subject, attachment, invoice payment). Turning the flag off clears
  those fields on save; the CSV import is unaffected (it does not send the flag).
- Field-level presence: while another operator is editing the same record, the field they are working
  in is outlined with the colour of their badge and labelled with their name (the heartbeat carries
  the field and is polled faster while a record is open). The “record updated” banner also lists **what
  changed** since the page was opened — field by field, old → new — so you decide whether to reload.
- First-note entries have a **Notes** field for internal annotations: the note is searchable from the
  ledger, is carried by the CSV export and re-imported with it, and never replaces the short
  description used by reports.
- The movement page now has a single **Document** box: document type, number and date are always
  shown, on incoming and outgoing entries alike (an invoice and a receipt share the same box), and
  the attachment lives with them. The “invoice payment” flag only adds the optional subject and due
  date on expenses.
- While classifying a first-note entry (type, Model D line, cost centre) Socly now looks for other
  entries with the same sign and a similar description and offers to apply the same changes: it
  lists up to three examples with date and amount, summarises what will change (“Type: … · Model D
  line: … · Cost centre: …”) and applies everything in one click (audited). Only entries **still
  without** a type, Model D line or cost centre are proposed; anything already classified is skipped
  and can never be overwritten (the update only fills empty fields). The matching is deterministic
  (keyword similarity, no external service) and never mixes opposite signs.
- Budget macro-categories can be reordered by drag & drop in the forecast grid: the numbers follow the
  new order and the change is saved by the existing autosave.
- Custom types (categories) can be renamed from the pencil next to the type select in the forecast
  grid; the taxonomy is shared, so movements and reports follow the new name. System types stay
  locked (create a new one instead).
- Recurring first-note entries: enable “Recurring entry” on a movement, choose how often (weekly,
  monthly, every 3/6 months, yearly), the day of the month, the first due date, an optional end date
  and whether the amount is fixed or variable. When due, Socly creates the entry: cash accounts are
  approved immediately, bank and card accounts stay **to approve**. Recurrences are listed in the
  first note with pause/resume and delete.
- Safe concurrent editing: every editable record now carries a row version, and a save is **refused**
  (naming who saved last and when) when another operator changed the record after you opened it.
  Nothing is overwritten silently: the form reopens with your values and an explicit “Overwrite with
  my data” / “Reload updated data” choice. While a form is open, a banner appears as soon as the
  record changes and a notice shows when a colleague is editing the same record. Wired for members,
  first-note movements and forecast budgets (the 5 s autosave stops instead of overwriting).
- Concurrent operators: every user works in their own PHP session (per-session file, no shared lock),
  and user accounts now carry a **first name / last name** or a **link to a member record** (same idea
  as association roles). A presence strip shows the initials of everyone connected with a stable
  colour per person, announces “Daniel Stimpfl just connected to Socly” for a few seconds, fades the
  dots of people idle for more than five minutes and animates the exit of those who disconnect;
  accounts without a name appear as “Unknown user”. The strip only appears when at least two people
  are online.
- Dashboard, Statistics and treasury charts: drag a card to reorder it while the three-column grid
  shows the available slots, resize it to one third, two thirds or the full row (width button or the
  bottom-right handle) and hide the cards you do not need; the layout is saved per user.
- Chart cards: copy the chart as a PNG from the bottom-right tools (clipboard when the browser allows
  it, otherwise the image is downloaded) — the export keeps the card title and a solid background.
- Bilanci: a single **Bilanci** menu entry with three tabs — **Preventivo** (forecast budgets by year),
  **Consuntivo** (RUNTS cash report, **Model D** of the Italian DM 18/04/2020: sections A–E, totals,
  cash and bank statement, optional figurative section, signature block and print/PDF, generated from
  the approved cash-book movements, each tagged with its Model D line automatically from the treasury
  category and overridable; above the **€220,000** income threshold the page states that the accrual
  statements, Models A/B/C, must be prepared manually) and **Statistiche** (planned vs actual per year,
  expense coverage, funding need, membership fees share of income, top expense and income categories).
- Budget lines can create a new treasury category on the spot (“+ New category…”), no need to fall
  back to “Other”; the label is then shared with movements, documents and deadlines.
- Budget grid autosaves 5 seconds after the last change (also when adding/removing lines), with a
  discreet status next to “Save draft” and a background flush when leaving mid-edit.

### Changed

- The **Cost centre / project** field of a movement now names the budget **section first and then the
  line** (“Spese di gestione e funzionamento · Affitto della Sede”, with the year in front when the
  line comes from another budget), so the chosen value keeps its context even when the field is
  collapsed; the full text stays available as a tooltip and the automatic imputation by category is
  unchanged.
- First-note filters are now a compact bar: search, period and type stay visible, while a **Filters**
  button (with the number of active filters) opens type, Model D line, cost centre, account, approval
  and sorting. The three classification filters include **“Not assigned”**, and “Group by category”
  is a proper toggle button instead of a loose checkbox.
- The first-note entry form is now organised into cards — amount and date, account and method,
  invoice, category and details, recurrence, counterparty, document — with a sticky action bar.
- The financial accounts section has a single “Save account changes” button instead of one save per
  row (deleting an unused account stays a per-row action).
- Approving a movement updates the balance, income and expense totals on the spot (no reload) and the
  “to approve” counter follows immediately.
- RUNTS section titles are translated in the interface; the single Model D lines keep the official
  Italian wording required by the form.
- The year-end report is no longer a separate menu item: `/rendiconto` redirects to the **Consuntivo**
  tab of the Bilanci page and the active tab is kept in the URL hash (`#bil-consuntivo`), so links and
  bookmarks keep working.
- Budget line fields are now **Expense / income type** (treasury category), **Description** (free
  text) and **Type**, with new types for associations: Expense, Capital expense, Project/activity
  expense, Income, Grant/restricted income (the type sets the accounting sign, so totals and the
  comparison with the cash book stay correct). A section is labelled **Macro-category**.

### Fixed

- Dashboard charts were broken by the cards card: its legend options read an undefined variable and the
  exception aborted the whole chart init (every later chart stayed empty, then a second pass threw
  “Canvas is already in use”). The options now come from the dataset and a re-init destroys the chart a
  canvas still owns before mounting a new one.
- The “Documento” box of a movement is really hidden when the flag is off: the block is a grid and the
  component rule was winning over the `hidden` attribute, so the fields stayed on screen (and were
  only disabled) after unticking “This movement has a document”.
- In the printed financial statement (“Piano finanziario”) each receipt is now a single line —
  **date · invoice number · amount** — instead of three or four wrapped lines with the “Numero
  fattura” wording and the counterparty, so the table stays readable (and no longer grows a tall row
  per cost centre).
- Field-level presence now covers **selects** too: when another operator picks an option you see the
  selection change in their colour (the field stays locked and the note shows their label). Your own
  selection comes back when they leave, and their pick is never saved by you.
- Settings → Financial accounts: the rows are aligned again — the accordion rhythm rule turned their
  forms into plain columns — and the **internal slug of each wallet** (`cash`, `carta_di_credito`) is
  no longer shown: only the name, the “active” toggle and what prevents deletion. The “new account”
  row is a proper row (name, active, button) instead of a floating block.
- Confirming an update no longer throws you back to the ledger: the card stays open with the saved
  values and the confirmation message, and the “similar movements” proposal now appears above the
  form (applying it keeps you on the card). “Back” still returns to the filtered ledger.
- The € of the amount field is centred inside the control again: it was anchored to the bottom of the
  wrapper and slid to the lower edge of the taller amount input.
- The cost centre is no longer missing on some movements: when the movement year has no budget the
  closest one is offered (labelled with its year) instead of hiding the field, and a cost centre
  already assigned from another budget stays selectable. In the movement page the “Document” box now
  comes after “Type and details”.
- The previous/next arrows of a movement now follow the order of the list you are reading: same
  filters, same search, same sort (date, description, method, amount, grouped by type) and therefore
  the same sequence of the ledger, page after page.
- A financial account is always shown with its name: a custom wallet (“Carta di credito”) is no
  longer displayed with its internal slug (`carta_di_credito`) in the ledger, in the superseded /
  transfer notes and in the account pickers; a legacy slug without a wallet still reads as a name.
- The ledger keeps its filters, its sort and the page when you open a movement, create a new one and
  come back: the “Back” button, the previous/next arrows, a retry after a validation error and the
  redirect after the save all return to the same filtered view (the state is handed to the “apply to
  similar” action too). Only internal `/treasury` URLs are accepted, so the parameter cannot be used
  to redirect somewhere else.
- Dates with an impossible year (0026, 0206, …) are no longer stored: the movement date, the
  document date and due date, the archive document date/expiry, the deadline date, the enrolment
  periods and the recurring series dates show the accepted range (1900 … current year + 5) and ask
  for a correction; the first-note import flags the row in the dry run, where the date can be typed
  again, and the member import reports the value in the warnings instead of writing it. The date
  check also rejects calendar days that do not exist (31 February, month 13) everywhere.
- Sorting the first note while “Group by type” is on now applies to the **whole** list, not just the
  visible page: the category is part of the database order, so pages keep the group blocks
  contiguous and the chosen column (date, description, method, amount) sorts every row of the
  register — pagination included.
- Opening “Edit movement” could show an empty form (amount, account and method blank, today's date,
  type always “income”): the page reused a stale `_old` payload left in the session by another form.
  The edit/create screens now discard foreign old input (only a retry of the same form keeps it) and
  the success path clears it, so the record's real values are always shown.
- The planned-vs-actual view showed the raw type key under each line (`rent`, `utilities`,
  `personnel`): it now shows the translated type name (also for older rows imported with foreign
  names).
- Association logo returned HTTP 500 on some installations: a controller declared `jsonResponse()`
  with a stricter visibility than the base controller (fatal error in PHP 8). The endpoint is also
  hardened now: missing file, unreadable file or a missing `fileinfo` extension return 404 instead
  of an error page.
- The presence dots no longer take space in the layout: they float over the top-right corner.
- Budget categories imported from CSV with foreign names (“rent”, “utilities”, …) are mapped to the
  canonical category, so they appear translated.
- Print enrollment form: “read the rest on the website” now links to the association website when
  configured (falling back to the installation URL).
- The members list search keeps the focus and the caret while the list filters itself: the field no
  longer “jumps away” after every keystroke.
- Long labels inside a badge now wrap in a rounded rectangle instead of turning the badge into a
  circle.
- Print enrollment form: statute and privacy texts longer than ten lines are clipped with an ellipsis
  and a “read the rest on the website <url>” line, so the sheet stays printable.
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
- Categories are now one shared taxonomy for expense notes (movements), forecast budgets and the
  year-end report: the budget grid uses the same grouped list (income / expense / financial /
  custom), a category already used by a movement or a budget line is always kept available, and
  categories are never deleted.
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
