# Socly

Self-hosted membership management for associations · Gestione associativa self-hosted.

<details open>
<summary><strong>🇮🇹 Italiano</strong></summary>

## Socly

**Socly** è una piattaforma **self-hosted** e modulare per la gestione delle associazioni.

L'**edizione base è gratuita**: puoi scaricarla e installarla sul tuo server. Installazione
professionale, configurazione, supporto dedicato e moduli aggiuntivi opzionali sono disponibili
separatamente su [socly.it](https://socly.it).

### Funzionalità

- Archivio soci con campi profilo configurabili
- Tipi e periodi di iscrizione, quote e pagamenti (pagato / parziale / dovuto)
- Interfaccia multilingua (italiano, tedesco, inglese)
- Wizard di installazione e pannello di controllo
- Plugin drop-in (`plugins/*.php`)
- Branding, documenti, tesoreria, scadenze (componenti modulari)
- Recupero dal RUNTS: dati dell'ente, documenti e precompilazione di statuto e informativa privacy
  (testo del PDF + OCR)

### Requisiti

- PHP 8.2+
- MariaDB / MySQL 8+
- Apache o Nginx con document root su `public/`
- Estensioni PHP: `pdo_mysql`, `openssl`, `mbstring`, `json`
- Composer
- OCR (opzionale): `poppler-utils`, `tesseract-ocr`, `tesseract-ocr-ita`

### Avvio rapido

```bash
git clone https://github.com/dadaloop82/socly_public.git socly
cd socly
composer install --no-dev
cp .env.example .env
# Punta la document root del web server su public/
# Apri il sito nel browser e completa il wizard di installazione
```

La guida completa (Apache, Nginx, permessi, database, OCR, aggiornamenti e troubleshooting) è in
[docs/INSTALLATION.md](docs/INSTALLATION.md). In alternativa, gli script one-click in `bin/install/`
installano ambiente, database e applicazione e avviano il wizard:

```bash
sudo bash bin/install/linux.sh   # Linux
bash bin/install/macos.sh        # macOS
powershell -ExecutionPolicy Bypass -File bin\install\windows.ps1   # Windows
```

Durante il wizard configuri database, branding dell'associazione e account **SuperAdmin** della
piattaforma. Dopo l'installazione il primo avvio crea l'account **Admin** dell'associazione usato
quotidianamente.

### Documentazione

- [Installazione](docs/INSTALLATION.md)
- [Architettura](docs/ARCHITECTURE.md)
- [Requisiti](docs/REQUIREMENTS.md)
- [Modello dati](docs/DATA_MODEL.md)
- [Plugin API](docs/PLUGIN_API.md)
- [Internazionalizzazione](docs/I18N.md)
- [Design system](docs/DESIGN.md)
- [Sicurezza](docs/SECURITY.md)
- [Rischi](docs/RISKS.md)
- [Roadmap](docs/ROADMAP.md)
- [Lessons learned](docs/LESSONS_LEARNED.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)

### Licenza

Source-available. Gratuita per uso self-hosted base. Vedi [LICENSE](LICENSE).
Installazione, supporto e moduli aggiuntivi: [socly.it](https://socly.it).

</details>

<details>
<summary><strong>🇬🇧 English</strong></summary>

## Socly

**Socly** is a self-hosted, modular membership management platform for associations.

The **base edition is free** to download and run on your own server. Professional installation,
configuration, dedicated support, and optional paid add-ons are available separately from
[socly.it](https://socly.it).

### Features

- Member archive with configurable profile fields
- Membership types and periods, payments (paid / partial / due)
- Multi-language UI (Italian, German, English)
- Installation wizard and control panel
- Drop-in plugins (`plugins/*.php`)
- Association branding, documents, treasury, deadlines (modular components)
- RUNTS lookup: association data, documents, and statute/privacy prefill (PDF text + OCR)

### Requirements

- PHP 8.2+
- MariaDB / MySQL 8+
- Apache or Nginx with document root pointing to `public/`
- PHP extensions: `pdo_mysql`, `openssl`, `mbstring`, `json`
- Composer
- OCR (optional): `poppler-utils`, `tesseract-ocr`, `tesseract-ocr-ita`

### Quick start

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

During the wizard you configure the database, association branding, and the platform **SuperAdmin**
account. After install, first-run setup creates the association **Admin** used day to day.

### Documentation

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

### Updates

Installed instances check the public release manifest at `latest.json` (HTTPS, no credentials) and
notify administrators when a newer version is published.

To update manually:

1. Back up the database and `storage/`.
2. Download the latest release from [socly_public](https://github.com/dadaloop82/socly_public)
   (branch `main` or release ZIP).
3. Replace application files, keeping `.env`, `.env.user`, and `storage/`.
4. Run `composer install --no-dev` if dependencies changed.
5. Open the site — pending database migrations run automatically on first request.

### Project layout

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

### License

Source-available. Free for self-hosted base use. See [LICENSE](LICENSE).
Installation services, support, and paid add-ons: [socly.it](https://socly.it).

</details>

