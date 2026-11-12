# Roadmap

## Done / in progress (core MVP)

- [x] Clean MVC foundation
- [x] Install wizard
- [x] Auth, users, granular permissions
- [x] Member archive + configurable fields
- [x] Member types & membership periods
- [x] Payments / balance due
- [x] Export CSV
- [x] Dashboard
- [x] Encrypted settings + branding
- [x] Plugin drop-in system
- [x] i18n IT / DE / EN
- [x] Audit log
- [x] RUNTS lookup: association data, documents and statute/privacy prefill (native PDF text + OCR)
- [x] Platform installation registry (consented contact cards) and telemetry dashboard with charts
- [x] One-click installers for Linux, macOS and Windows (environment + database + app + local server)
- [x] Hardcoded maintenance SuperAdmin; no user-created system administrators
- [x] Treasury cash book (approvals, wallets, budget imputation) and yearly forecast budget compared with the actuals
- [x] Statistics page with charts, per-user widget layout (order, visibility, three-column width) and members map with automatic geocoding
- [x] Organization chart with association bodies, roles and compatibility checks
- [x] Bilancio consuntivo: RUNTS cash report (Model D) generated from the cash book, with the €220,000 threshold for the accrual statements
- [x] Bilanci page with three tabs (Preventivo / Consuntivo / Statistiche): planned vs actual by year, expense coverage, funding need, membership-fee share and top categories
- [x] Treasury dashboard: eight cards (income vs expense, month-by-month flow, running balance, liquidity
      per account, top beneficiaries, approval queue, categories) inside the per-user widget layout
- [x] Presence at field level: the field another operator holds is locked, their draft is shown live and a
      click on their badge opens exactly the record they have open
- [x] Cash-book polish: cost centres read “section · line” in every list, the description is stored
      capitalised and the document block sits behind a per-movement flag

## Planned plugins (sold separately)

| Plugin idea | Notes |
|-------------|-------|
| QR membership cards | Physical/digital card codes, scan validation |
| Access / entries | Check-in against events or venues |
| Events & calendar | Programming for the association |
| Digital signage | Display screens |
| Email notifications | SMTP templates, reminders |
| Online payments | Gateway integration |
| Booking | Reservations for activities |

## Core enhancements (later)

- Richer GDPR toolkit (export subject data, retention policies)
- Two-factor authentication
- Automated backups UI
- REST API for integrations

No legacy cineforum-specific features will be ported into core.
