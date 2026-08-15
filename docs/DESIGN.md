# Design system

Socly uses a fixed visual language across auth, install and the control panel.

## Palette

| Token | Default | Role |
|-------|---------|------|
| `--brand-primary` | `#0D6E66` | Teal — main actions, links, product |
| `--brand-accent` | `#B84A1B` | Copper — association highlight, secondary emphasis |
| `--brand-ink` | `#0B1F1C` | Body text |
| `--brand-paper` | `#F4FBF9` | Light surfaces / auth text on dark |
| `--surface` | `#E7F1EE` | Page atmosphere |

Association branding may override `--brand-primary` and `--brand-accent` via settings; fonts and structure stay the same.

## Typography

- Display: **Fraunces** (`--font-display`) — titles, product lockup, motto
- Body: **Manrope** (`--font-body`) — UI copy, forms, tables

## Auth lockup

When an association name is configured:

**Socly** (paper / primary light) **per** (muted) **[Association]** (accent)

Logo: `public/assets/img/logo.svg` until a custom file is set in `branding.logo`.

## Layout & mobile (corporate standard)

Every screen must work on phone, tablet and desktop using the shared shell in `layouts/app.php` + `public/assets/css/app.css`.

Rules for current and future views:

1. **One shell** — authenticated pages use the app shell (sidebar + topbar + main). Do not invent alternate chrome.
2. **Mobile nav** — below 960px the sidebar is off-canvas; the sticky topbar opens it. Do not stack a full sidebar above content.
3. **Fluid grids** — use `.grid-2`, `.grid-3`, `.stats`, `.charts`, `.detail-grid`. They collapse to one column on small screens.
4. **Tables** — always wrap in `.table-wrap` so wide tables scroll horizontally without breaking the page.
5. **Page headers** — `.page-header` with `.titles` + `.actions`; actions wrap / full-width on small screens.
6. **Touch** — buttons keep usable height; form controls stay `font-size: 1rem` (avoids iOS zoom).
7. **No fixed desktop widths** in new views; prefer `%`, `minmax`, `clamp`, and existing utility classes.
