# Socly

Self-hosted membership management for associations.

**Socly** is a self-hosted, modular membership management platform for associations.

The **base edition is free** to download and run on your own server. Professional installation,
configuration, dedicated support, and optional paid add-ons are available separately from
[socly.it](https://socly.it).

## Features

- Member archive with configurable profile fields
- Membership types and periods, payments (paid / partial / due)
- Multi-language UI (Italian, German, English)
- Installation wizard and control panel
- Drop-in plugins (`plugins/*.php`)
- Association branding, documents, treasury, deadlines (modular components)
- RUNTS lookup: association data, documents, and statute/privacy prefill (PDF text + OCR)

## Requirements

- PHP 8.2+
- MariaDB / MySQL 8+
- Apache or Nginx with document root pointing to `public/`
- PHP extensions: `pdo_mysql`, `openssl`, `mbstring`, `json`
- Composer
- OCR (optional): `poppler-utils`, `tesseract-ocr`, `tesseract-ocr-ita`

## Quick start

```bash
git clone https://github.com/dadaloop82/socly_public.git socly
cd socly
composer install --no-dev
cp .env.example .env
# Point the web server document root to public/
# Open the site and complete the installation wizard
```

Full guide (Apache, Nginx, permissions, database, OCR, updates and troubleshooting) in
[docs/INSTALLATION.md](docs/INSTALLATION.md). Alternatively, the one-click scripts in `bin/install/`
install the environment, database and application and start the wizard:

```bash
sudo bash bin/install/linux.sh   # Linux
bash bin/install/macos.sh        # macOS
powershell -ExecutionPolicy Bypass -File bin\install\windows.ps1   # Windows
```

During the wizard you configure the database, association branding, and membership period. The
**maintenance SuperAdmin** account is hardcoded in the application and is not created by the wizard.
After install, sign in with the maintenance account and complete the first-run setup, which creates
the association **Admin** account used day to day. Setup is not anonymous.

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Requirements](docs/REQUIREMENTS.md)
- [Data model](docs/DATA_MODEL.md)
- [Plugin API](docs/PLUGIN_API.md)
- [Internationalization](docs/I18N.md)
- [Design system](docs/DESIGN.md)
- [Security](docs/SECURITY.md)
- [Risks](docs/RISKS.md)
- [Roadmap](docs/ROADMAP.md)
- [Lessons learned](docs/LESSONS_LEARNED.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)

## Updates

Installed instances read the public release manifest (`latest.json`, HTTPS, no credentials) and notify
administrators when a newer version is published; every release also ships a curated
[CHANGELOG](CHANGELOG.md).

**One-click / automatic update** — when the app is a **git checkout** whose `origin` remote is
readable (a clone of this public repository needs no SSH key, and no `.env` flag is required):

1. Sidebar card → **Update** (confirmation required): the updater takes a full backup, pulls,
   runs migrations and verifies the result; on failure it restores the previous version.
2. Or from the command line: `php bin/update.php --apply` — same engine as the button.
3. Schedule it (nightly, fully automatic):

   ```cron
   15 4 * * * php /path/to/socly/bin/update.php --apply >> /path/to/socly/storage/logs/update.log 2>&1
   ```

The updater refuses to run on showcase instances, when `UPDATE_ENABLED=false` or when the working tree
has local modifications, and every attempt is written to the audit log.

Every update takes a snapshot **before** touching anything: `storage/backups/update-<date>-v<version>/`
contains the previous code (`git archive`) and a database dump (`mysqldump`). After the fast-forward
the updater verifies the new version, the database connection and that no migration is left pending;
if any step fails it restores the previous commit (and the database dump) and reports the failure with
the backup path.

**Manual update** (ZIP download, no git):

1. Back up the database, `.env` and `storage/`.
2. Download the latest release from this repository (branch `main` or release ZIP).
3. Replace application files, keeping `.env`, `.env.user`, `storage/` and `plugins/`.
4. Run `composer install --no-dev` if dependencies changed.
5. Open the site — pending database migrations run automatically on first request.

## Project layout

| Path | Purpose |
|------|---------|
| `app/` | Application code |
| `plugins/` | Drop-in plugin PHP files |
| `lang/` | Translations (`it`, `de`, `en`) |
| `database/migrations/` | Schema migrations |
| `public/` | Web document root |
| `resources/views/` | Server-rendered templates |
| `storage/` | Logs, cache, sessions, install lock |
| `docs/` | Architecture, installation and contributor docs |

## License

Source-available. Free for self-hosted base use. See [LICENSE](LICENSE).
Installation services, support, and paid add-ons: [socly.it](https://socly.it).
