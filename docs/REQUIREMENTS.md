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
12. **Charts & statistics** — chart cards on the dashboard (members and treasury tabs) and on the
    statistics page with per-user order, visibility and width on a three-column grid. The treasury tab
    ships income vs expense, month-by-month flow, running balance, liquidity per account, top
    beneficiaries and the approval queue. Legends disappear above three entries (the tooltip names
    every series). Statistics page with demographics, registrations, cards, finances and a members map
    whose places are geocoded automatically.
13. **Organization chart** — association bodies and appointments (members or external people) with role compatibility checks; outstanding membership fees never block an appointment.
14. **Documents, deadlines, treasury** — modular components: document archive with categories,
    deadlines, cash book with approvals, wallet balances and budget imputation. A cost centre always
    reads “section · line” and the ledger filter groups the lines by budget and section (like the
    Model D line filter), the description (“causale”) is stored capitalised and the document block of a
    movement sits behind a per-movement flag. The ledger selection can be reclassified in bulk
    (account, type, Model D line, cost centre, notes) with an explicit confirmation and per-row audit.
15. **Forecast budget** — one document per fiscal year, inline grid with autosave, expense/income types, custom categories, comparison with the cash actuals and a printable rendiconto; the year-end RUNTS report and the budget statistics live in the same **Bilanci** page (Preventivo / Consuntivo / Statistiche tabs).
16. **Concurrent editing & presence** — optimistic locking on every versioned record; the field another
    operator is working in is locked and their draft is shown live; a click on their presence badge
    opens exactly the record they have open (server-side link, same permission); a stale save is
    refused, never silently overwritten.

## Non-functional

- Self-hosted; single association per install.
- PHP 8.2+, MariaDB/MySQL.
- CSRF protection on state-changing requests.
- Encrypted sensitive settings at rest.
- Source-available license (Socly Source-Available License, Community / Base Edition); free for self-hosted use; no third-party branding in core.
- Plugin drop-in: one file in `plugins/` is sufficient to register a feature.

## Explicitly out of scope (core)

QR codes, events, digital signage, Google OAuth, multi-association, legacy data migration.
