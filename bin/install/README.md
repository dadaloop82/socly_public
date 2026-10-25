# One-click installers

Scripts that turn a fresh machine into a working Socly instance:

1. verify the environment
2. install PHP 8.4/8.3 (preferred) or 8.2 with the required extensions, Composer, MariaDB/MySQL and optional OCR tools
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
membership period/type and profile fields. The final step sends you to `/login`: sign in with the
maintenance account (`admin@socly.it`, password defined in `app/Support/PlatformMasterAccess.php`)
and complete the first-run **setup** at `/setup`, which creates the association **Admin** account.
The single **maintenance SuperAdmin** is hardcoded in the application: it is never created by the
wizard and cannot be changed from the UI. Setup is not anonymous. The scripts do not accept the
Terms of Service for you.

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
--plan                 show what would be done without changing anything
```

On Windows the PowerShell parameters are `-Port`, `-Bind`, `-DbName`, `-DbUser`, `-DbPass`,
`-DbRootPass`, `-Url`, `-Locale`, `-Ocr`, `-NoOcr`, `-NoBrowser`, `-NoServe`, `-Check`, `-Json`,
`-ReportPath`, `-Plan`, `-Uninstall`, `-PurgeDatabase`, `-PurgeMariaDb`.

`-Check -Json` prints a machine-readable report (OS, admin rights, free disk, ports, network,
PHP/Composer/MariaDB, project files, firewall/Defender). `-Plan` only prints the actions.
`-Uninstall` removes the PHP runtime/shim and the local server recorded in
`storage/cache/install-manifest.json`; add `-PurgeDatabase` to drop the Socly database/user and
`-PurgeMariaDb` to uninstall MariaDB when this script installed it.

## What gets installed

- **PHP 8.4/8.3** (preferred) or **8.2** with `pdo_mysql`, `mbstring`, `openssl`, `json`, `fileinfo`,
  `curl`, `gd`, `intl`, `zip`
- **Composer** (system package or a local `composer.phar` on Windows, verified against its SHA-256)
- **MariaDB/MySQL** server and client. On Windows the installer sets a generated root password,
  stores it in `storage/cache/db-root.pass` with restricted ACLs, binds MariaDB to `127.0.0.1`
  and rejects existing empty-root TCP access. It does **not** add PHP to the Machine `PATH`.
- **OCR (optional)**: `poppler-utils`, `tesseract`, Italian language data on Linux/macOS;
  on Windows use `-Ocr` for Tesseract (Poppler remains a manual step).
- A dedicated database + user with a generated password
- `.env` with a generated `APP_KEY` (chmod 0600 on Unix, restricted ACL on Windows)
- Applied migrations and seeded permissions
- A manifest at `storage/cache/install-manifest.json` recording what the Windows script created

## Notes

- The local server uses PHP's built-in web server: it is meant for installation and evaluation.
  For production use Apache/Nginx + HTTPS, see [`docs/INSTALLATION.md`](../../docs/INSTALLATION.md).
- The maintenance SuperAdmin email is `admin@socly.it`; its password is stored only as an Argon2id
  hash in `app/Support/PlatformMasterAccess.php` and is never printed by the installers.
- On Windows the script auto-elevates through UAC, writes a transcript to `storage/logs/install-*.log`
  and keeps the elevated window/log available on failure. MariaDB is installed with
  `winget`/Chocolatey when available; otherwise install MariaDB Server manually and re-run.
- On Windows the generated MariaDB root password is stored in `storage/cache/db-root.pass` with
  restricted ACLs. `-DbRootPass` can be supplied for pre-existing servers. An existing root account
  reachable over TCP without a password is rejected instead of being used silently.
- The PHP download for Windows requires the Microsoft Visual C++ Redistributable (usually present).
- `-NoOcr` is kept for compatibility but OCR is opt-in on Windows: use `-Ocr` to install Tesseract.
  Poppler still has to be installed manually; without it the RUNTS PDF prefill is partial.
- The scripts are idempotent: re-running them keeps the existing database, `.env`, `APP_KEY` and
  generated passwords. Reuse `-DbRootPass`/`--db-root-pass` for a root account you manage yourself.
- A port requested explicitly (`--port`/`-Port`) that is already busy stops the installation. The
  default port is shifted to the next free one and `APP_URL` is written with the port actually used.
- Never commit `.env`, `storage/` or the generated passwords.
