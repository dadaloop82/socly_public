# Socly

**Socly** is a self-hosted, modular membership management platform for associations.

The **base edition is free** to download and run on your own server. Professional installation, configuration, dedicated support, and optional paid add-ons are available separately from [socly.it](https://socly.it).

## Features

- Member archive with configurable profile fields
- Membership types and periods, payments (paid / partial / due)
- Multi-language UI (Italian, German, English)
- Installation wizard and control panel
- Drop-in plugins (`plugins/*.php`)
- Association branding, documents, treasury, deadlines (modular components)

## Requirements

- PHP 8.2+
- MariaDB / MySQL 8+
- Apache or Nginx with document root pointing to `public/`
- PHP extensions: `pdo_mysql`, `openssl`, `mbstring`, `json`
- Composer

## Quick start

```bash
git clone https://github.com/dadaloop82/socly_public.git socly
cd socly
composer install --no-dev
cp .env.example .env
# Point the web server document root to public/
# Open the site and complete the installation wizard
```

During the wizard you configure the database, association branding, and the platform **SuperAdmin** account. After install, first-run setup creates the association **Admin** used day to day.

## Aggiornamento

Installed instances check the public release manifest at `latest.json` (HTTPS, no credentials) and notify administrators when a newer version is published.

To update manually:

1. Back up the database and `storage/` (except cache/sessions if you prefer).
2. Download the latest release from [socly_public](https://github.com/dadaloop82/socly_public) (branch `main` or release ZIP).
3. Replace application files, keeping your `.env`, `.env.user`, and `storage/` data.
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
| `docs/` | Architecture and contributor docs |

## Documentation

- [Architecture](docs/ARCHITECTURE.md)
- [Requirements](docs/REQUIREMENTS.md)
- [Data model](docs/DATA_MODEL.md)
- [Plugin API](docs/PLUGIN_API.md)
- [Internationalization](docs/I18N.md)
- [Design system](docs/DESIGN.md)
- [Security](docs/SECURITY.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)

## License

Source-available. Free for self-hosted base use. See [LICENSE](LICENSE).
Installation services, support, and paid add-ons: [socly.it](https://socly.it).
