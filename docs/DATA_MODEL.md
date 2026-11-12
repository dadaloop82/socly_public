# Data model

## Tables

### users
| Column | Notes |
|--------|-------|
| id | PK |
| email | unique |
| password | `password_hash` |
| name | display name (composed from `first_name` + `last_name` when present) |
| first_name, last_name | operator identity shown in the presence dots |
| member_id | optional link to the member record (`members.id`, FK) used to derive the display name |
| locale | `it` / `de` / `en` |
| role | operator role label (`president`, `treasurer`, `secretary`, `board`, `volunteer`) — NULL = custom permissions |
| is_system_admin | immutable admin flag; only the hardcoded maintenance account may have it |
| is_active | soft disable |
| created_at, updated_at | |

### permissions / user_permissions
Named permission keys (e.g. `members.view`, `members.manage`, `payments.manage`, `settings.manage`, `users.manage`, `plugins.manage`). System admin implicitly has all.

### user_presence
One row per open session, refreshed by the presence polling.

| Column | Notes |
|--------|-------|
| session_id | PK — PHP session id |
| user_id | FK `users.id` ON DELETE CASCADE |
| connected_at | first heartbeat of the session |
| last_seen | last heartbeat; older than 5 minutes ⇒ shown as idle, older than 15 ⇒ offline |
| ended_at | set on logout; non-NULL rows are never shown |
| editing_entity / editing_id | record the operator is editing right now (presence “X is editing”) |
| editing_field | focused form field of that record: allowlisted name (`[A-Za-z0-9_\-\[\]]`, max 120 chars), `NULL` when nothing is focused |
| editing_value | draft being typed (max 300 chars, control characters stripped). Field and value are ignored once `last_seen` is older than 45 s (`PresenceService::FIELD_TTL_SECONDS`) |

### row_version (members, treasury_movements, budgets, budget_items, association_documents, deadline_items, association_people)
`INT UNSIGNED NOT NULL DEFAULT 1`, incremented by `Database::updateVersioned()`. A save posted with a
stale version is refused (HTTP 409 on JSON endpoints) and the form is reopened with the conflict
banner; `force=1` overwrites deliberately.

### settings
| Column | Notes |
|--------|-------|
| `key` | PK string |
| `value` | `LONGTEXT` — plaintext or ciphertext; JSON for multilingual values |
| `is_encrypted` | bool |
| `plugin_id` | nullable; scopes plugin settings |

#### Legal texts

`legal.statute` and `legal.privacy` store `{"it":"…","de":"","en":""}` and are rendered as official
documents (they feed the enrollment form, member area and generated PDFs). Therefore:

- never truncate them with synthetic “… text truncated …” markers inside the value;
- keep the read cap generous (`400 000` characters per locale) and surface truncation/partial OCR as
  an explicit warning in the UI instead;
- the RUNTS prefill writes Italian only and sets `legal.<key>_from_runts = 1`; saving the step manually
  resets that flag.

### member_types
Name (JSON i18n or plain), `price`, `is_active`, `sort_order`.

### membership_periods
`label`, `starts_on`, `ends_on`, `is_current`.

### members
`member_number`, `member_type_id`, `membership_period_id`, `status`, `notes`, `balance_due`, timestamps.

### member_field_definitions
Configurable fields: `key`, `type` (`text`, `email`, `phone`, `date`, `fiscal_code`, `textarea`, `address`, …), labels JSON i18n, `is_required`, `validation_rule`, `is_enabled`, `sort_order`.

### member_field_values
EAV: `member_id`, `field_definition_id`, `value`.

### payments
`member_id`, `amount`, `method` (`cash`, `bank`, `other`), `type` (`membership`, `debt`, `adjustment`), `note`, `created_by`, `created_at`.

### membership_cards
Membership cards: `member_id` (nullable, `SET NULL` on delete), `card_number` (unique — it matches
`members.member_number`: the card number *is* the member number), `qr_code`, `member_type_id` (card
type), `issued_on`, `expires_on`, `is_active`, `notes`. Populated by the CSV import and reusable for
card management.

### member_import_runs / member_import_rows
Traceability of CSV member imports. `member_import_runs`: `user_id`, `source_name`, `source_hash`, `delimiter`, `encoding`, `mapping_json`, `options_json`, `stats_json`, `status` (`preview` / `committed` / `failed`), `created_at`, `committed_at`. `member_import_rows`: one row per processed member (`run_id`, `line_no`, `member_id`, `card_id`, `payment_id`, `action` `create|update|skip|error`, `result` `created|updated|skipped|failed`, `message`, `payload_json`, `diff_json`, `errors_json`).

### audit_logs
`user_id`, `action`, `entity_type`, `entity_id`, `before_json`, `after_json`, `ip`, `created_at`.

### treasury_movements
Cash book rows: `movement_date`, `direction` (`income` / `expense` / `transfer`), `category`, `runts_line` (line of the RUNTS cash report — Model D — automatically derived from the category and overridable per movement), `budget_item_id` (nullable cost centre), `amount`, `description` (the short “causale” used by reports and exports — stored capitalised, first letter upper case and the rest lower case, `TreasuryService::normalizeDescription()`, whatever the operator typed), `notes` (free-text internal memo, searchable and carried by the CSV export/import — never a replacement for the description), `payment_method`, `account`, `transfer_account`, invoice fields, `document_type` (kind of document: invoice, receipt, quittance, contract, insurance premium, credit/debit note, statement, other — `TreasuryService::DOCUMENT_TYPES`, nullable and independent from the invoice flag, never set on transfers), `attachment_path`, `member_id`, `payment_id`, `created_by`.

Treasury categories are the built-in list plus `treasury.custom_categories` in the component config
(`[{slug, label}]`). New categories can be created on the fly from movements, documents, deadlines
and budget lines; the label is shared by every feature that offers the same picker.

### budgets / budget_sections / budget_items
Yearly forecast budget, one per fiscal year (`budgets.fiscal_year` unique; `status` `draft` / `approved`).
Only one document per year is allowed: the create form proposes just the free years and, while a draft
is open, it points to that draft instead of offering another document.

- `budget_sections` — collapsible groups (`title`, `sort_order`), cascade-deleted with the budget.
  In the UI a section is the **macro-category** of its lines.
- `budget_items` — one line: `direction` (`income` / `expense`, the accounting sign), `kind`
  (`expense`, `investment`, `project`, `income`, `grant` — the “tipo” shown in the grid), `label`
  (description), `amount`, optional `category` (matches the treasury categories, custom ones
  included), `notes`, `sort_order`.

`kind` is stored next to `direction` on purpose: a capital expense stays an expense and a restricted
grant stays income, while every total keeps reading `direction` only. `BudgetService` derives the sign
from the type (`expense` / `investment` / `project` → expense, `income` / `grant` → income).

The actuals are **derived**: `SUM(treasury_movements.amount)` grouped by `budget_item_id`. No duplicated actuals table; movements without imputation are reported separately as “not imputed”.

### user_widget_layouts
Per-user chart layout for the dashboard, statistics and treasury card grids: `user_id`, `context`
(e.g. `dashboard.members`, `statistics.finance`), `layout` JSON, unique on `user_id + context`,
cascade-deleted with the user.

`layout` is `{"order": [ids…], "hidden": [ids…], "sizes": {id: 1|2|3}}`: the order and visibility of
the cards plus their width as a span of 1–3 columns of the chart grid.

Contexts in use: `dashboard.members`, `statistics.finance` and `dashboard.treasury` (cards `flow`,
`monthly`, `balance`, `accounts`, `beneficiaries`, `status`, `expense`, `income`).

### plugins
`id` (plugin slug), `is_enabled`, `enabled_at`, `meta_json`.

### Platform settings

`platform.instance_token` (random, hashed by the receiver), `platform.news_opt_in`,
`platform.usage_stats_opt_in`, `platform.contacts_opt_in`, `platform.consents_configured/confirmed_at/name`,
`platform.telemetry_last_at`, `platform.installation_last_at`, `platform.installation_version`,
`platform.installation_sent`, `platform.installation_last_try`, `platform.installation_needs_sync`.
Demos set `app.temporary_instance = 1` and never send platform data.

## Economic status (derived)

- **paid** — `balance_due <= 0`
- **partial** — `0 < balance_due < type.price` (or historical partial payments)
- **due** — `balance_due > 0` with no (or insufficient) payments

Exact filter helpers live in `MemberService`.
