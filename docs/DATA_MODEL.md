# Data model

## Tables

### users
| Column | Notes |
|--------|-------|
| id | PK |
| email | unique |
| password | `password_hash` |
| name | display name |
| locale | `it` / `de` / `en` |
| role | operator role label (`president`, `treasurer`, `secretary`, `board`, `volunteer`) — NULL = custom permissions |
| is_system_admin | immutable admin flag; cannot delete |
| is_active | soft disable |
| created_at, updated_at | |

### permissions / user_permissions
Named permission keys (e.g. `members.view`, `members.manage`, `payments.manage`, `settings.manage`, `users.manage`, `plugins.manage`). System admin implicitly has all.

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

### audit_logs
`user_id`, `action`, `entity_type`, `entity_id`, `before_json`, `after_json`, `ip`, `created_at`.

### treasury_movements
Cash book rows: `movement_date`, `direction` (`income` / `expense` / `transfer`), `category`, `budget_item_id` (nullable cost centre), `amount`, `description`, `payment_method`, `account`, `transfer_account`, invoice fields, `attachment_path`, `member_id`, `payment_id`, `created_by`.

### budgets / budget_sections / budget_items
Yearly forecast budget, one per fiscal year (`budgets.fiscal_year` unique; `status` `draft` / `approved`).

- `budget_sections` — collapsible groups (`title`, `sort_order`), cascade-deleted with the budget.
- `budget_items` — one line: `direction` (`income` / `expense`), `label`, `amount`, optional `category` (matches the treasury categories), `notes`, `sort_order`.

The actuals (consuntivo) are **derived**: `SUM(treasury_movements.amount)` grouped by `budget_item_id`. No duplicated actuals table; movements without imputation are reported separately as “non imputati”.

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
