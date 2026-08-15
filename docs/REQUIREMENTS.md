# Requirements — Core MVP

## Functional

1. **Installation wizard** — database, APP_KEY generation, association profile, admin user, membership period, member types, configurable fields, optional GDPR toggles.
2. **Authentication** — email + password; session-based; per-user locale (admin default Italian).
3. **Users & permissions** — granular permissions per user; system admin cannot be deleted; password change allowed.
4. **Member archive** — CRUD; member number; type; period; status (`active`, `suspended`, `expired`, `cancelled`); notes; configurable profile fields with validation (Italian fiscal code algorithm when field type is fiscal code).
5. **Member types & periods** — core configuration; price on type; current period flag.
6. **Economics** — ledger of payments; balance due; statuses derived: paid / partial / due.
7. **Export** — CSV export of members (filtered).
8. **Dashboard** — counts, due balances, recent members.
9. **Settings** — branding, association data, field definitions, plugin enable/disable and plugin settings.
10. **Audit log** — record who changed what on sensitive entities.
11. **i18n** — Italian, German, English UI strings, centralized.

## Non-functional

- Self-hosted; single association per install.
- PHP 8.2+, MariaDB/MySQL.
- CSRF protection on state-changing requests.
- Encrypted sensitive settings at rest.
- Proprietary license; no third-party branding in core.
- Plugin drop-in: one file in `plugins/` is sufficient to register a feature.

## Explicitly out of scope (core)

QR codes, events, digital signage, Google OAuth, multi-association, legacy data migration.
