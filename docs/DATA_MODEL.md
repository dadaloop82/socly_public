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
| is_system_admin | immutable admin flag; cannot delete |
| is_active | soft disable |
| created_at, updated_at | |

### permissions / user_permissions
Named permission keys (e.g. `members.view`, `members.manage`, `payments.manage`, `settings.manage`, `users.manage`, `plugins.manage`). System admin implicitly has all.

### settings
| Column | Notes |
|--------|-------|
| `key` | PK string |
| `value` | plaintext or ciphertext |
| `is_encrypted` | bool |
| `plugin_id` | nullable; scopes plugin settings |

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

### plugins
`id` (plugin slug), `is_enabled`, `enabled_at`, `meta_json`.

## Economic status (derived)

- **paid** — `balance_due <= 0`
- **partial** — `0 < balance_due < type.price` (or historical partial payments)
- **due** — `balance_due > 0` with no (or insufficient) payments

Exact filter helpers live in `MemberService`.
