# Paid add-ons

Paid extensions are **signed PHP packages** that the core verifies locally. The public product
repository never contains paid add-on code: it only carries the verifier, the public keys and this
document. Licences, packages and updates are distributed by socly.it (`/admin`) and verified on the
instance with Ed25519, also without any internet connection.

## Decisions (current product model)

| Topic | Decision |
|---|---|
| Price | One-off (perpetual licence) |
| Licence | One licence = one installation (an association with several seats uses the same instance) |
| Several licences | An installation can hold more than one licence (one key each): every key grants only its own add-ons and activating a new one never replaces the existing licences |
| Offline codes | Perpetual, signed; no network required |
| Add-ons | Multi-file packages with one PHP entry point implementing `PluginInterface` |
| Updates | The vendor builds and publishes new signed versions; the instance updates online or by uploading the new package |
| Grants | The vendor decides manually in `socly.it/admin` (payments are handled outside the product) |

## Where the code lives

| Location | Content |
|---|---|
| Public repo (`socly_public`) | `app/Core/Addon/*` (verifier only), `bootstrap/addons_keys.php` (**public** keys), docs, migration `048_addons.sql` |
| Private repo (`tools/addons/`, `addons-src/`) | key generation, package builder/signer, licence signing CLI, guard tests, add-on sources |
| socly.it (`_webSite`) | catalogue, signed package storage, licence issuance/revocation, `/api/addons.php` |
| Instance storage | `storage/addons/<id>/<version>/` (code), settings (signed entitlements and licence keys, encrypted, one entry per licence), `storage/addons/keys` (package private key in the private repo only) |

The private keys never belong to the public tree:

- `_webSite/storage/keys/license_ed25519.key` — **production socly.it** signing key (licences + update manifest).
- `storage/addons/keys/package_ed25519.key` — package signing key. It must be the private half of the
  `package` public key in `bootstrap/addons_keys.php`; packages are built on the host that holds it
  (vendor/CI, e.g. the socly.it host) and never on a machine without it.
- `bootstrap/addons_keys.php` — **public** keys shipped with the core: production licence key and package key.
  Every instance trusts these two.
- `_webSite/storage/keys/addons_public_keys.php` — copy of the public keys used by socly.it (same two values).

Keep the site file aligned with the core file: admin → Add-on → «Inizializza chiavi» derives the licence public
key from the private one and writes the site file; the package public key must stay the package-key one.
Verify the alignment on the host that holds the private keys with:

```bash
php tools/addons/addon-tool.php key-status
```

Both rows must report `allineata al core: sì`. On a machine without the production private keys the licence
row (and possibly the package row) shows `no`: build/sign licences on socly.it instead.

## Formats

### Entitlement (licence token)

```
SOCLY-ENT1.<base64url(payload JSON)>.<base64url(Ed25519 signature)>
```

The signature covers the literal `SOCLY-ENT1.<payload segment>`, so the prefix cannot be swapped.
Payload:

```json
{
  "v": 1,
  "lic": "lic_…",
  "iid": "478cab5f58af8633",
  "host": "assoc.example.it",
  "iat": "2026-11-12T21:00:00+00:00",
  "exp": "2027-11-12T21:00:00+00:00",
  "addons": [{"id": "advanced_reports"}]
}
```

- `iid` = first 16 hex of `sha256(platform.instance_token)`; it is stable, non-reversible and does not
  reveal the association.
- `exp` is optional (perpetual licences omit it).
- The instance verifies the signature with `bootstrap/addons_keys.php` and refuses a token issued for
  another `iid`.

### Request code (offline sale)

```
SOCLY-REQ1.<base64url({"iid":"…","host":"…","core":"1.7.81","addons":["…"],"nonce":"…"})>
```

Unsigned on purpose: it only carries the instance identity and the requested add-ons. The vendor pastes
it into the admin licence form to bind the licence; the real security is the signed entitlement.

### Package (`.soclyaddon`)

A zip with:

```
addon.json          manifest: id, name, version, min_core, entry, files{path: sha256}
addon.json.sig      detached Ed25519 signature of the exact addon.json bytes
plugin.php          entry point returning a PluginInterface (multi-file via lib/…)
```

Install rules: signature valid, every declared file hash matches, no undeclared files, safe relative
paths only, extension allowlist, `min_core` compatible, PHP files pass `php -l`. The package is
extracted atomically under `storage/addons/<id>/<version>/` (outside the document root, preserved by
core updates) and the previous version is kept as a rollback copy.

### Manifest (updates)

`POST /api/addons.php action=manifest` returns `{manifest: "<json string>", signature: "<b64url>"}`.
The signature is made with the **licence** key and covers the exact JSON string. Content:

```json
{"v":1,"generated_at":"…","addons":{"advanced_reports":{"latest":"1.2.0","min_core":"1.7.70",
 "versions":[{"version":"1.2.0","sha256":"…","url":"https://socly.it/api/addons.php?action=download&addon=advanced_reports&version=1.2.0"}]}}}
```

## Admin UI (socly.it)

| Page | What you do |
|---|---|
| `/admin/addons.php` | **Chiavi di firma** with the «Inizializza chiavi» button (creates `storage/keys/license_ed25519.key` when missing and writes `storage/keys/addons_public_keys.php`, so no CLI is needed), catalogue (id/name/description), signed package upload with signature + hash verification, per add-on detail (current version, entry point, declared files, versions with download, licences attached) |
| `/admin/licenses.php` | **New licence** from an existing Socly installation (iid/host/customer filled automatically), from a `SOCLY-REQ1…` request code, or with a manual `iid` for offline installations; licence manager with filters (search, add-on, status, bound/unbound), per add-on coverage ("who has what"), licence detail with add-on/expiry editing, installation (re)binding, offline `.soclylic` code and event log |
| `/admin/installations.php` | add-on column and detail row: which add-ons are licensed per installation, linking to the filtered licence list |

How an add-on is "attached": the catalogue id **is** the `id` inside `addon.json`. The signed package
uploaded for that id is stored under `storage/addons/packages/<id>/<version>.soclyaddon`; the licence
grants `<id>` to one installation (`iid` = first 16 hex of `sha256(instance token)` = first 16 hex of the
stored `token_hash`) and the signed manifest serves that package to the instance. The public site never
needs the private repository: it only needs the signed package and the public verification keys.

## Vendor workflow

```bash
php tools/addons/addon-tool.php keygen          # only when the site cannot use the admin button
php tools/addons/addon-tool.php build ./addon-src ./dist/advanced_reports-1.2.0.soclyaddon
php tools/addons/addon-tool.php verify-package ./dist/advanced_reports-1.2.0.soclyaddon
php tools/addons/addon-tool.php sign-license payload.json --out=license.txt   # only if socly.it is down
```

1. **socly.it → Add-on → Chiavi di firma**: click «Inizializza chiavi» (idempotent, never rotates
   existing keys). If the licence keypair is new, copy the generated `bootstrap/addons_keys.php` snippet
   into the core repo and release a core version; the package public key must stay the one used by the
   build machine.
2. **socly.it → Add-on → Catalogo**: register the add-on with the same id used in `addon.json`.
3. **socly.it → Add-on → Pacchetto firmato**: upload the `.soclyaddon`; signature and per-file hashes are
   verified before publication under `storage/addons/packages/<id>/<version>.soclyaddon`.
4. **socly.it → Licenze → Nuova licenza**: pick an existing installation (online activation), paste a
   request code, or type the `iid` for an offline installation; choose the add-ons.
5. Send the customer the **licence key** (`SOCLY-KEY-…`) for online activation, or the **offline code**
   (`.soclylic` download) plus the package file.
6. Revoke/reactivate from the licence detail; every event is logged in `license_events`.

## First add-on: Iscrizione rapida (`tablet_enrollment`)

Source: `addons-src/tablet_enrollment/` (private repo; `addons-src/` is not part of the public export).
Requires core **1.7.88+** (public plugin routes with a middleware override + `addon_active()`).

| File | Purpose |
|---|---|
| `addon.json` | id/name/version/min_core/entry consumed by the build tool |
| `plugin.php` | `PluginInterface`: public kiosk route, admin page + menu, migration |
| `lib/KioskService.php` | token gate, session wizard, validation, member/payment/card creation |
| `lib/AddonText.php` | it/de/en strings shipped with the package |
| `views/*.php` | branded layout, wizard steps, admin panel, error page |

Behaviour:

- **Core gating** — the “Tablet” enrollment validation (`tablet_sign`) is selectable only when
  `addon_active('tablet_enrollment')` is true. Setup and settings reject it otherwise; if the
  add-on is removed/disabled/unlicensed, `EnrollmentService::method()` falls back to `none` so
  member creation is never blocked by a method that no longer exists.
- **Kiosk** — `GET|POST /tablet/<token>`, public (no operator login), CSRF + access token in the
  path. Steps: Tessera (type + period + optional existing card number) → Dati (all enabled member
  fields, dynamically rendered) → Firma (core signature pad + acknowledgements) → Pagamento
  (paid/partial/unpaid, method, note, summary) → riepilogo with member number and card.
- **Writes** go through `MemberService::create()` (payment row, balance, treasury, audit, hooks)
  and `EnrollmentService::storeArtifact()` (signed PNG); the add-on issues the
  `membership_cards` row and logs every sign-up in `addon_tablet_enrollment_log`.
- **Admin** — `/tablet-enrollment` (menu entry, `settings.manage`): kiosk URL, status, card-number
  mode (`auto` = member number, `existing` = ask for the printed card) and the latest sign-ups.
- **Security** — 24 random bytes token stored encrypted and rotatable from the admin page,
  per-IP rate limit, `noindex`, no member list exposed by the kiosk.

Build and publish (private tooling):

```bash
php tools/addons/addon-tool.php build addons-src/tablet_enrollment dist/tablet_enrollment-1.0.9.soclyaddon --incoming
php tools/addons/addon-tool.php verify-package dist/tablet_enrollment-1.0.0.soclyaddon
```

`--incoming` also copies the package into `storage/addons/incoming/` of the **instance** (Settings → Add-ons
verifies it and lists it in “Add-on disponibili”); `--site` copies it into `_webSite/storage/addons/incoming/`
of the **socly.it** catalogue (Admin → Add-on → Pubblica). On another server, copy the file into the matching
folder manually. A licence containing the add-on is still required: activate it first (online key, offline
code or request code), then install from the drop folder or from the licence catalogue.

## Board voting add-on (`board_voting`)

Official polls of the board (consiglio direttivo), published as a drop-in add-on: a five-step
wizard (title/period, participants, topics, preview, share link), participants identified by first
name + last name + fiscal code, one ballot per person written inside a transaction, live results
with charts, participation log, CSV export and a branded public page carrying the “part of the
Socly package” line. Questions are numeric, single/multiple choice or predetermined text: voters
can never type free text. Polls can be open or secret (aggregate only). The “Argomenti” step also
collects the reference material of the poll: PDF/JPG/PNG files uploaded to `storage/uploads` and
external http(s) links; the public ballot page opens the documents inline (same-origin PDF iframe,
image preview) or downloads them with the original name. A poll can also be linked to a published
topic of the Internal forum (step “Info”): the public page shows the discussion link and the local
identity is shared, so a participant identified on the ballot page enters the forum without a second
login (same fiscal code, same browser). The add-on also publishes the Workflow events
`board_voting.published`, `board_voting.closed` and `board_voting.vote_cast`. Source:
`addons-src/board_voting/` (private repo; `addons-src/` is not part of the public export).

```bash
php tools/addons/addon-tool.php build addons-src/board_voting dist/board_voting-1.2.1.soclyaddon --incoming
```

## Internal forum add-on (`internal_forum`)

Reserved discussion space for the people enabled by the association: forums with their own
settings (posting allowed to everybody or moderators only, pre-moderation of new topics, author
editing), participants enabled per forum exactly like the board polls (members from the archive or
external people with first name, last name and fiscal code), moderators appointed per forum,
topics and replies, search, pagination, pinning and locking. Topics and replies accept up to six
images each (JPG/PNG/WebP/GIF, 5 MB per file, served by the add-on only for published messages) and
offer a small formatting toolbar (`[b]`, `[i]`, `[u]`, `[s]`, `[mark]`, `[quote]`, `[code]`,
`[url]`). Public pages are token-based and identify participants by name + fiscal code; operators
manage forums, participants and moderation from `/internal-forum` (permission `members.manage`,
nested under “Soci”). Forum settings include an email notification block (master switch plus “new
topic” / “new reply”) that uses the core SMTP configuration and the system templates
`internal-forum-new-topic` / `internal-forum-new-reply`, editable in Settings → Email templates.
Every write is audited (`internal_forum.*`) and the add-on publishes the Workflow events
`internal_forum.opened`, `internal_forum.closed`, `internal_forum.topic_created`,
`internal_forum.reply_created` and `internal_forum.topic_moderated`. Source:
`addons-src/internal_forum/`.

```bash
php tools/addons/addon-tool.php build addons-src/internal_forum dist/internal_forum-1.1.1.soclyaddon --incoming
```

## Bank statement import (`bank_statement_import`)

Imports the movements of a bank statement PDF into the first cash book, under “Prima cassa”. The
operator picks an **immutable bank model** shipped with the package (`templates/*.json`), uploads the
statement and reviews the result next to the rendered page: hovering a movement highlights its lines
on the document and every row must be confirmed (or corrected) before the summary. The first model
covers **Intesa Sanpaolo** (Conto Business Insieme): native text provides dates and descriptions, a
narrow OCR pass over the amount columns fills in the numbers, and scanned PDFs fall back to a
full-page OCR. The parser follows movements across page breaks, skips repeated headers/footnotes,
reconciles the declared totals with the sum of the detected rows and flags duplicates already in the
ledger. Pairing is not greedy: every statement row is matched with the ledger movement of the same
direction and amount using **same day → same month and year → same year** (never another year), and
all plausible pairs are scored first (tier, date distance, description/counterparty similarity) and
assigned globally, so an earlier row cannot steal the movement that belongs to a later one. Runs
created with the old greedy pairing are re-matched once on their next read (`match_version` in
`run.json`); rows excluded only by the previous pairing become available again. Writes go through
`TreasuryService::create()` (audit, approval queue, RUNTS line) and
the import is logged as `treasury.bank_statement_imported`. Files live in
`storage/uploads/bank-statements/<run>/` and are deleted on commit, on cancel or after 24 hours.

The summary mirrors the cash-book list (same columns and client-side filters) and lets the operator
skip or overwrite movements already present; a check-only screen compares those rows with the
ledger, confirms the correct ones and aligns a row to the existing movement without importing
anything. Data, amount and beneficiary are mandatory before a row can be confirmed. The counterparty
is **always read from the statement** — the printed counterparty field when present, otherwise the
description text and the bank model rules: it is never guessed from previous movements, from the
amount or from the bank name. When the statement does not name it the field stays empty and the
operator must fill it before confirming. Statement lines that only carry reference codes (IBAN-like
tokens, long digit runs) do not become a counterparty, and the comparison ignores case, punctuation
and symbols (`AMAZON*` = `Amazon`). The selected
movement shares the same highlight colour on the PDF and on its card, and the amount is emphasised
because it is the value that decides whether a row is correct. When the counterparty is a **member**
(first + last name, either order, punctuation ignored) the row is linked to the member profile
automatically — also after a correction in the review — and the link badge shows the member name.
On the verification page the recorded card shows that member name when the ledger row's free-text
counterparty is empty (the PDF card still shows only what the statement prints), and the duplicate
matcher uses the same effective name.
The “Intendevi …” suggestion (Yes / No, like the cash-book form) compares the typed name with both
recorded counterparties and member names; it runs while typing (debounced) and on blur, so a
mistyped name is corrected before the row is saved. The counterparty field uses the same
suggestions as the cash-book form: a datalist of the recorded counterparties plus a proposal when the
typed name is a near-duplicate, an abbreviation or a unique prefix. An edit
is propagated to later similar movements only where the target field is still empty, and “Salva e
continua” stays disabled until every row to import is complete, issue-free and confirmed.

Counterparty extraction also understands the “Bonifico da Voi disposto a favore di: …” wording, and a
member named in the counterparty or description is linked to the movement automatically. Rows to fix
are highlighted until they are corrected; the blinking counter offers **Vedi movimenti da correggere**
and jumps to the first one. **Conferma e importa tutti** is repeated inside the card being fixed: it
stays visible until the row is confirmed (correcting the anomaly does not hide it), and a click
continues with the next blocked card — or with the summary once every row is complete. A possible
duplicate inside the same statement can be accepted with
**Importa comunque**, and on the summary the **Importa** button stays disabled until the operator
checks “Confermo che i movimenti verificati corrispondono all’estratto conto”. Hovering a movement
switches the PDF to its page and scrolls the highlighted lines into view.

The verification screen was reworked around the statement: “letto dall’estratto conto” wording,
registered value before the statement value in the differences, a PDF excerpt for every row, a slider
that shows position and remaining rows, a wide in-page editor for the recorded movement, and an
unsaved-changes guard. The apply bar counts the movements being corrected (“Applica le modifiche ai
X movimenti”) and “Usa valore Estratto conto” is a button. The ledger movements missing from the
statement sit at the top and can be deleted after two confirmations. The upload button stays disabled
until a valid PDF is selected and the reading-engine line is only shown when something is missing.

Amounts get a second chance: a word sitting right on the column boundary is still accepted, and every
row whose amount is still missing is re-read with a focused OCR pass on its own line (enlarged single
line). A number that cannot be read stays at 0, disables the row and is reported as unreadable — a
0,00 movement is never imported silently. In the check-only screen the suggested fields keep the
*inferred* badge, and a row missing from the cash book can be added straight away with **Aggiungi a
Prima cassa** (bank account, confirmation, audit log).

Reconciliation is checked explicitly: when the declared totals and the sum of the detected rows
differ, the review shows a red alert with the per-direction difference and the import asks for an
explicit confirmation; a declared total that was not detected is reported as a partial
reconciliation. The sum always includes every detected row, also the duplicates excluded from the
import, so an already imported statement still reconciles.

The parser joins amount fragments the text layer prints separately (`"108,"` + `"42"`) and reads the
declared totals from the `Totale/Totali` line. Thousands are handled too: Intesa hides the separator
in a control character (`6<0x19>957,06`), which is normalised before the fragments are joined. When the
text layer already carries readable amounts the OCR pass is skipped (much faster analysis); the focused
per-row OCR remains the fallback. Step 2
shows a live progress overlay (elapsed time and steps), marks every 0,00 row in red until a valid
amount is typed, pulses the unreadable-amount alert and offers **Mostra log OCR**
(`/treasury/bank-statements/ocr-log`, also downloadable) with the native text, the words found in the
amount area, the OCR lines and the parsed movements.

Since v1.3.7 the review explains the excluded rows (already in the cash book / amount not read / other
reasons) instead of a bare count, the save button says “Continua al prossimo step” and cannot post twice,
and “Conferma ed importa tutti i movimenti” confirms every importable row (after an explicit
confirmation for rows with reading issues) and continues to the summary/import step. Rows already in
the cash book are confirmed as well — the confirmation is what enables “overwrite” on the summary —
but they stay excluded, so the button also works on a statement that was already imported and the
summary then offers to skip or overwrite them. When some rows
lack a valid date, amount or counterparty it confirms the others, lists the reasons in a branded
popup (“Ho capito”) and jumps to the first row to fix. Movements that still need a fix and are not
confirmed are outlined in red on the PDF and update while editing.
Continuing with excluded or included-but-unconfirmed rows opens a branded warning (never snoozed)
that lists how many will not be considered; on confirmation those included rows are excluded for
real, so the summary and the import only consider the confirmed ones. Corrections to payment method,
RUNTS line and cost centre are propagated to the **later**
similar rows of the same statement (never to previous ones) unless the operator changed them explicitly.
On the verification page any difference from the statement — amount, date, direction and the
descriptive fields (description, counterparty, category, cost centre) — is “Da correggere” and appears
in the one-card wizard (Previous/Next or the arrow keys). A movement leaves the list only when its
stored values match the statement or when the operator explicitly declares it correct with “Questo
movimento è corretto”, which stores a fingerprint of the recorded row so it is not flagged again until
it changes (`addon_bank_statement_accepted`). Applying changes stores the selected values and then
records that movement as correct **with the updated values** (same fingerprint as “Questo movimento è
corretto”): the card disappears and the wizard moves to the next one, while movements left untouched
stay “Da correggere”. “Seleziona tutte le differenze” only touches the card on screen. The 1.3.12
upgrade cleared the fingerprints that
earlier releases had stored for untouched movements, and “Riapri i movimenti confermati” clears them
again on demand. Rows with readable date, amount and counterparty and no reading issue are confirmed
automatically in the review step: “to review” is reserved for OCR doubts, missing/undated/duplicated
rows and unreadable values, while a balanced statement is the proof that the rest is complete. After
“Apply changes” every selected field is read back from the ledger and compared with the statement: a
value that did not persist is reported as an error instead of being counted as applied.

**Statement excerpts.** At the end of a successful import each movement gets a PNG crop of its
statement line, stored in the central repository (`source_section = bank_statement_import`,
`source_ref = movement:<id>`) and linked to the movement. Prima cassa shows it in the ledger row and
in the movement card, and the same file is listed in Verbali e documenti. Generation is best effort
and capped at 20 s: a missing ImageMagick or a very large statement never fails the import, it only
skips the missing excerpts.

**Learning.** “Applica le modifiche”, “Questo movimento è corretto” and confirmed review corrections
teach the add-on: the significant words of the statement description plus the direction identify the
kind of movement, and the decided category, payment method, RUNTS line and cost centre
are stored in `addon_bank_statement_learned`. The counterparty is not guessed: it is still read from
the statement, but when the operator **renames** a raw counterparty (e.g. “AMZN Mktp” → “Amazon”) the
add-on stores that alias in the same table (`beneficiary`, keyed by the normalised raw text). The alias
is applied to the following rows of the current statement and to the next uploads; rows that carry a
different literal counterparty are never overwritten. Next uploads — and the remaining cards of the same
statement — start from those values: the latest decision per field wins, values read literally from
the PDF are never overridden, and a learned value carries the “appreso / gelernt / learned” badge
instead of “dedotto / abgeleitet / inferred”. Deductions therefore get more reliable as the operator
works through the statements.

Requirements: `poppler-utils`, `tesseract-ocr` (+ `ita` language data) and ImageMagick. A new bank
model is a new JSON file in `templates/`; it never changes the PHP code. Source:
`addons-src/bank_statement_import/` (private repo; `addons-src/` is not part of the public export).

```bash
php tools/addons/addon-tool.php build addons-src/bank_statement_import dist/bank_statement_import-1.4.17.soclyaddon --incoming
```

## Revenue Agency invoices (`agenzia_entrate_invoices`)

v2.0.0 replaced the server-side Chromium (CDP) harvester with a **Chrome extension** that runs in the
operator's own profile, where the SPID session actually lives. Socly never sees the credentials and no
browser process is executed on the host: the add-on exposes a token-protected JSON bridge
(`/agenzia-entrate/api/...`) and a dashboard with the installation wizard, the live run and the
invoices to confirm.

- **Pairing** — *Genera codice di collegamento* creates a one-time string (`SOCLYPAIR1.…` carrying the
  base URL + token) shown once; only `sha256(token)` is stored in `addon_ade_agents`, revoking a browser
  refuses its next call immediately.
- **Run** — the operator (or the extension popup, or the automatic schedule `next_sync_at`) creates a
  run; the extension **claims** it, opens `start_url` (SPID), detects the login page, reaches *Fatture e
  corrispettivi*, applies the year/direction filters when present, reads table rows **and** the JSON
  payloads captured by a page hook, then uploads each invoice (`/invoice`) and its XML/PDF/P7M
  documents in base64 chunks (`/document/begin|chunk|complete`).
- **Journal** — every step is posted to `/event` and stored in `addon_ade_agent_events`; the panel
  polls `/agenzia-entrate/status` and shows the same progress (stage, counters, current invoice).
- **Matching** — `MatchingService` keeps its logic (only the reason labels come from `AddonText`): a
  suggestion never writes on the cash book until the operator confirms it; invoices/documents live in
  `storage/addons/agenzia_entrate_invoices/`.

The extension source is in `addons-src/agenzia_entrate_invoices/extension/` (MV3, no build step) and is
also served as a ZIP from the add-on screen (`/agenzia-entrate/extension.zip`). Chrome warns that an
extension loaded unpacked is not from the Web Store: the wizard and the bundled README explain it. If
the extension is published on the Chrome Web Store (see `extension/STORE.md`), the operator pastes the
listing URL in the add-on settings (`store_url` / `store_edge_url`) and step 2 shows the native **Add to
Chrome** button instead of the local install steps. The package builder allows `html` files for exactly
this case (extension pages).

```bash
php tools/addons/addon-tool.php build addons-src/agenzia_entrate_invoices dist/agenzia_entrate_invoices-2.0.4.soclyaddon --incoming
```

## Workflow events from add-ons

Settings → Workflow lists a group for every add-on available on disk, even when the package does not
implement `WorkflowEventProviderInterface`: core scans the add-on PHP sources for audit actions and
explicit workflow dispatches, caches the result in `storage/cache/workflow_addons.json`, and forwards
matching audit actions automatically. Implementing the interface is still recommended for localized
labels and an explicit audit map. Rules only run while the add-on is installed and active.

## Instance workflow

Settings → Add-ons (`/settings/addons`, permission `addons.manage`):

- **Licence ≠ package** — activating a licence stores the entitlement; an online activation also
  downloads and enables the licensed packages, while an offline `.soclylic` code installs the
  package from the drop folder or manual upload. Until the package is installed the menus/routes
  of the add-on do not exist and the core features gated on it stay off.
- **Add-on disponibili** — the page merges three sources into one plain-language table: the licensed
  catalogue downloaded from socly.it, the packages found in `storage/addons/incoming/` and the installed
  rows. Each row shows the state (`Da installare`, `Aggiornamento disponibile`, `Installato, da attivare`,
  `Serve Socly x.y.z`, `Licenza richiesta`, `Pacchetto non valido`) and the matching action, so the
  administrator never has to understand portals, folders or manifests.
- **Automatic check, visible updates** — the signed manifest is refreshed at most once an hour (the
  sidebar badge calls it on every admin page, the Add-ons page repeats it) and missing licensed add-ons
  are installed automatically. New versions are **not applied silently**: the sidebar shows
  “Aggiornamenti add-on: N” with the name and version range, and the Add-ons page lists them with the
  “Aggiorna” button; “Installa tutto” and the nightly cron `php bin/addons-update.php` apply them. The
  two buttons on the page post in the background and refresh the lists in place (the outcome appears
  under the buttons, the page does not reload); without JavaScript they fall back to a flash message.
  The manifest is cached and the package is downloaded server-side, hash-checked and signature-checked
  before installation.
- **Offline installations** — everything technical lives under “Opzioni avanzate”: offline activation
  (`.soclylic` code, no network call, no data sent), request code (`SOCLY-REQ1…` to send to the vendor),
  manual `.soclyaddon` upload, drop folder (`storage/addons/incoming/`), instance code and verification
  keys. Packages dropped there are verified (signature, hashes, `min_core`) and appear in the same
  “Add-on disponibili” table; `php tools/addons/addon-tool.php build … --incoming` copies them there.
- **Check updates** — with an online licence, verifies the signed catalogue, downloads newer versions
  and installs them (audit `addon.updated`). Offline installations update through the drop folder.
- **Disable / remove** — disabling keeps files and data; removal is allowed only when disabled and keeps
  the database tables (data is never destroyed automatically).

## Error reporting

`LicenseService::post()` separates a real network failure from an API refusal: only DNS, TLS, timeout
or refused connections read “the licence service is unreachable from this server”, while an HTTP 4xx/5xx
that carries the site JSON is mapped to an actionable message (invalid key, revoked, expired, bound to
another installation, installation not recognised) that points the customer to the Socly team
(`info@socly.it`) — never to the website as if it managed the licence. Unmapped refusals keep the site
wording plus the HTTP status (`error_api_rejected`), and a non-JSON body reports `error_api_response`
with the status. The licence key never appears in a message or in the log.

## When an add-on breaks the panel

- `php bin/addons-toggle.php` lists the installed add-ons with their state; `php bin/addons-toggle.php
  <id> off` disables one from the shell (files and data stay), `on` re-enables it (an active licence is
  required). It is the rescue tool when the browser UI cannot be opened.
- The panel isolates failures: a drop-in plugin or add-on that throws while loading or booting is logged
  (`plugin.boot_failed`) and skipped for that request instead of taking every page down; `failedPlugins()`
  lists them.
- Errors keep a reference code. Signed-in administrators see the technical block (type, file:line,
  request, message) on the error page; `SOCLY_SHOW_ERRORS=1` in `.env` shows it to anyone for support.
- Every crash is reported automatically through the relay (`App::run()` and the global exception
  handler), and the site stores it in `bug_reports` (Admin → Reports) **before** trying GitHub: a
  disabled relay or a revoked token no longer hides crashes. The error page shows the message and
  whether the report went out (`SOCLY_REPORT_PROBLEMS=0` disables sending on the instance).
- Crashes raised by scripts that live **outside the application tree** (a hand-run file in `/tmp`, a
  foreign cron job) are written to the local log only and never become GitHub issues: they are not
  actionable product bugs. The check compares `$_SERVER['SCRIPT_FILENAME']` with `base_path()`,
  `code_path()` and `SOCLY_INSTANCE_PATH`; unreadable paths are still reported.

## Package lint and PHP-FPM

Packages are linted with `php -l` before installation. Under PHP-FPM `PHP_BINARY` points at the FPM
binary, which has no `-l` switch (it answers with its usage screen), so the instance resolves a CLI
binary first: `SOCLY_PHP_BIN` from `.env` when set, the CLI binary, `PHP_BINDIR/php`, then `php` from
`PATH`. If no usable CLI exists — or `exec()` is disabled on hardened hosting — the lint is skipped
on purpose: the signature is the real boundary, the lint is only a safety net.

On hosts with a restrictive `open_basedir` (Plesk) every probe is silent — a warning there would be
turned into an exception by the error handler and would abort the install of a valid, signed package —
and the resolver falls back to `command -v`. To let the lint actually run, allow the PHP bin directory
and the system bins in `open_basedir`. On Plesk, with the supported command (it survives a `php.ini`
regeneration):

```bash
cat > /tmp/socly-php-extra.ini <<'EOF'
open_basedir = "/var/www/vhosts/<domain>/:/tmp/:/opt/plesk/php/:/usr/bin/:/usr/local/bin/:/bin/"
EOF
plesk bin site --update-php-settings <domain> -additional-settings /tmp/socly-php-extra.ini
```

## Crash reports and the relay

`php bin/report-check.php` prints the relay URL, the site URL and whether sending is enabled;
`php bin/report-check.php --send` sends a test crash report and prints the relay answer (the row appears
in socly.it → Reports). Reports are sent from `App::run()`, the global exception handler and the fatal
shutdown handler; the site stores them **before** trying GitHub, so a disabled relay or a revoked token
no longer hides a crash. `SOCLY_REPORT_PROBLEMS=0` in `.env` stops sending on the instance, and the error
page always says whether the automatic report went out.

## Licence management (socly.it admin)

`/admin/licenses.php` → licence detail:

- **Sblocca licenza** — clears the installation binding (`installation_token_hash`, `installation_code`,
  host, last activation): the key can be activated on another installation and, once a new installation
  is bound, the offline code is rebuilt. Use it after a reinstall or when the instance answers “already
  activated on another installation”.
- **Rigenera chiave** — replaces the `SOCLY-KEY-…` activation key; the previous one stops working
  immediately while the installation binding and the offline `SOCLY-ENT1…` code stay valid.
- **Elimina licenza** — deletes the licence with its add-on links and event history (irreversible):
  key and offline code stop working at once. Installations and packages are not touched.

The instance refuses the two codes in the wrong field with a dedicated message (`error_key_is_offline_code`
and `error_code_is_online_key`), so the operator is sent to the right form instead of reading “key not
recognised”.

## API (socly.it)

| Method | Action | Notes |
|---|---|---|
| `POST` | `activate` | body: instance `token`, `iid`, `license_key`, `host`, `core_version`; binds an unbound licence on first use; returns entitlement + signed manifest |
| `POST` | `status` | returns `revoked: true/false`; revocation clears the entitlement on the instance at the next check |
| `POST` | `manifest` | signed catalogue of the licensed add-ons |
| `GET` | `download&addon=&version=` | `Authorization: Bearer <licence key>`; streams the package; verifies licence and version |

Only `https` download URLs are accepted by the instance. Associate the API host with the licence
authority and keep `SOCLY_ADDONS_API_URL` (`.env`, optional) pointing to socly.it.

## Production checklist

1. **Create/verify the keys from the admin UI** (`/admin/addons.php` → «Inizializza chiavi»), which writes
   `_webSite/storage/keys/addons_public_keys.php` on the server. Back up both private keys offline; losing
   them means you can no longer sign licences or packages. Use `keygen --force` only for a deliberate
   rotation, then update `bootstrap/addons_keys.php` with the snippet shown in the page and release a core
   version.
2. Commit the generated `bootstrap/addons_keys.php`. The site copy
   (`_webSite/storage/keys/addons_public_keys.php`) is generated on the server and stays in
   `storage/` (never committed); never commit the private keys.
3. `storage/addons` and `_webSite/storage` must stay outside the document root (the site `.htaccess`
   denies `storage/`, but an nginx deployment must deny it too).
4. Keep `UPDATE_ENABLED`/core updates working: `storage/` is preserved by the updater, so packages and
   licences survive core updates.
5. Run `php tools/checks/addons-guard.php` in CI (private repo) on every change to the add-on layer.

## Security and honest limits

- Add-ons are **signed**; the instance refuses modified or unsigned packages. PHP code is readable, so a
  determined customer can copy it: the licence is what keeps updates and support tied to a paying
  installation, not a DRM. Do not promise otherwise in the contract.
- Offline licences are perpetual and cannot be revoked remotely. Revocation works for online licences
  (next status check) and stops new codes/packages; an offline code already delivered remains valid.
- The instance stores only the signed token (encrypted) and the licence key (encrypted); no association
  data is sent by the add-on layer. Online activation sends only `iid`, host, core version and licence id.
- Private keys are the real secret. Generate them on a trusted machine, back them up offline, and rotate
  by adding a new `kid`/public key to `bootstrap/addons_keys.php` in a core release.
