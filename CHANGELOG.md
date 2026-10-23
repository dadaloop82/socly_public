# Changelog

All notable changes to the public Socly base product are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows a simple `1.0.x` versioning scheme driven by release syncs.

## [Unreleased]

### Added

- Public source-available distribution of the Socly base edition
- Installation wizard, membership core, plugins API, and multi-language UI
- Setup wizard warning when statute/privacy OCR could not read every page
- Installation guide (`docs/INSTALLATION.md`) covering Apache/Nginx, permissions, database, optional
  OCR tools, updates and troubleshooting
- Bilingual README (Italian and English accordions) and bilingual installation guide

### Fixed

- RUNTS legal-text prefill now OCRs **every page** of statute and privacy PDFs (previously only the
  first page was read because `pdftoppm` names output files after the real page number)
- Statute and privacy texts are no longer truncated by page/byte caps and never contain synthetic
  “text truncated…” notes inside the official document
- Long scanned PDFs are read with no page cap in the background worker and with a larger time budget
- “Fetch RUNTS data”, “Fetch website data” and “Retry” buttons pulse smoothly when they are ready to
  be pressed; the “more content below” ↓ cue blinks smoothly (both honour reduced motion)

### Changed

- Legal texts are stored with a generous 400 000-character cap per locale and partial OCR is surfaced
  as a warning instead of silently shortening the document
- Texts cut by the previous OCR build are refreshed automatically on the next RUNTS lookup

## [1.0.0] - 2026-08-15

### Added

- Initial public release synchronized from the Socly product line
- Free self-hosted base edition under the Socly Source-Available License
