# One-click installers

Scripts that turn a fresh machine into a working Socly instance:

1. verify the environment
2. install PHP 8.2+ with the required extensions, Composer, MariaDB/MySQL and optional OCR tools
3. create the database and user
4. write `.env` (with a generated `APP_KEY` and database password)
5. run migrations and seed permissions
6. start a local server and open the installation wizard

| System | Command |
|--------|---------|
| Linux (Debian/Ubuntu, Fedora/RHEL, Arch, openSUSE, Alpine) | `sudo bash bin/install/linux.sh` |
| macOS (Homebrew) | `bash bin/install/macos.sh` |
| Windows 10/11 (PowerShell as Administrator) | `powershell -ExecutionPolicy Bypass -File bin\install\windows.ps1` |

Then open **http://127.0.0.1:8080/install** and complete the wizard: database, association,
membership period/type and profile fields. Then sign in with the maintenance account and complete
the first-run setup, which creates the association **Admin** account. The single **maintenance
SuperAdmin** is hardcoded in the application: it is never created by the wizard and cannot be changed
from the UI. Setup is not anonymous. The scripts do not accept the Terms of Service for you.

## Common options

```bash
--port=8080            local HTTP port
--bind=127.0.0.1       bind address
--db-name=socly        database name
--db-user=socly        database user
--db-pass=...          database password (default: generated)
--db-root-pass=...     MariaDB/MySQL root password, when required
--url=https://...      public application URL written to .env
--locale=it|de|en      default locale
--no-ocr               skip poppler/tesseract installation
--no-browser           do not open the browser
--no-serve             install only, do not start the local server
--check                verify the environment and exit
```

On Windows the PowerShell parameters are `-Port`, `-Bind`, `-DbName`, `-DbUser`, `-DbPass`,
`-DbRootPass`, `-Url`, `-Locale`, `-NoOcr`, `-NoBrowser`, `-NoServe`, `-Check`.

## What gets installed

- **PHP 8.2+** with `pdo_mysql`, `mbstring`, `openssl`, `json`, `fileinfo`, `curl`
- **Composer** (system package or a local `composer.phar` on Windows)
- **MariaDB/MySQL** server and client, started automatically
- **OCR (optional)**: `poppler-utils`, `tesseract`, Italian language data
- A dedicated database + user with a generated password
- `.env` with a generated `APP_KEY` (chmod 0600 on Unix)
- Applied migrations and seeded permissions

## Notes

- The local server uses PHP's built-in web server: it is meant for installation and evaluation.
  For production use Apache/Nginx + HTTPS, see [`docs/INSTALLATION.md`](../../docs/INSTALLATION.md).
- On Windows, MariaDB is installed with `winget` or Chocolatey when available; otherwise install
  MariaDB Server manually and re-run the script.
- The PHP download for Windows requires the Microsoft Visual C++ Redistributable (usually present).
- The scripts are idempotent: re-running them keeps the existing database, `.env` and `APP_KEY`.
- Never commit `.env`, `storage/` or the generated passwords.
