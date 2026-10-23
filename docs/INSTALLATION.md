# Installazione / Installation

Guida all'installazione self-hosted di Socly. · Step-by-step self-hosted installation guide.

<details open>
<summary><strong>🇮🇹 Italiano</strong></summary>

## Requisiti

| Componente | Versione / note |
|------------|-----------------|
| PHP | 8.2+ con `pdo_mysql`, `openssl`, `mbstring`, `json`, `fileinfo` |
| Database | MariaDB 10.4+ / MySQL 8+ (`utf8mb4`) |
| Web server | Apache 2.4+ con `mod_rewrite`, oppure Nginx + PHP-FPM |
| Composer | 2.x (solo per installazione e aggiornamenti) |
| Permessi | `storage/` e `.env` scrivibili dall'utente del web server |
| HTTPS | consigliato (cookie di sessione `Secure`, HSTS) |

OCR **opzionale**, necessario solo per precompilare statuto e informativa privacy dai PDF del RUNTS:

```bash
sudo apt install poppler-utils tesseract-ocr tesseract-ocr-ita
```

Serve `exec()` abilitato in PHP e il binario CLI `php` (il worker OCR parte in background con `nohup`).
Se `exec()` è disabilitato Socly usa un OCR inline ridotto e segnala nell'interfaccia quando il testo
letto è parziale.

## Installazione one-click (Linux, macOS, Windows)

Gli script in `bin/install/` verificano l'ambiente, installano PHP 8.2+ con le estensioni richieste,
MariaDB/MySQL, Composer e (opzionale) gli strumenti OCR, creano database e `.env`, applicano le
migrazioni e avviano il server locale:

```bash
sudo bash bin/install/linux.sh                                      # Linux
bash bin/install/macos.sh                                           # macOS (Homebrew)
powershell -ExecutionPolicy Bypass -File bin\install\windows.ps1    # Windows (admin)
```

Opzioni utili: `--check` (solo verifica), `--no-ocr`, `--no-serve`, `--port=8080`,
`--db-root-pass=...`. Su Windows i parametri sono `-Check`, `-NoOcr`, `-NoServe`, `-Port`, `-DbRootPass`.
Dettagli in [`bin/install/README.md`](../bin/install/README.md).

Al termine apri `http://127.0.0.1:8080/install` e completa il wizard (associazione, periodo/quota,
campi). Il wizard di primo avvio crea poi l'account **Admin dell'associazione**; l'account SuperAdmin
di manutenzione è predefinito nel codice e non viene creato dal wizard. Il server PHP integrato serve
per installazione e valutazione: per la produzione usa Apache/Nginx + HTTPS come descritto sotto.

## 1. Scarica il codice

```bash
git clone https://github.com/dadaloop82/socly_public.git socly
cd socly
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Imposta almeno `APP_URL` e i parametri `DB_*` in `.env` (oppure lascia che li scriva il wizard).
`APP_KEY` viene generato dall'installer: **salvalo insieme ai backup**, senza di esso le impostazioni
cifrate non sono più leggibili.

## 2. Database

Crea database e utente (in alternativa l'installer crea il database se l'utente ha il privilegio
`CREATE`):

```sql
CREATE DATABASE socly CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'socly'@'localhost' IDENTIFIED BY 'una-password-lunga';
GRANT ALL PRIVILEGES ON socly.* TO 'socly'@'localhost';
FLUSH PRIVILEGES;
```

Le migrazioni (`database/migrations/`) girano automaticamente al primo avvio e a ogni aggiornamento:
non serve lanciare comandi manuali.

## 3. Permessi

```bash
# adatta l'utente al tuo sistema: www-data (Debian/Ubuntu), apache, nginx…
sudo chown -R www-data:www-data storage
sudo find storage -type d -exec chmod 775 {} \;
sudo chown www-data:www-data .env
```

La document root deve puntare **solo** a `public/`. `.env`, `storage/` e `vendor/` restano fuori.

## 4. Web server

**Apache** (`a2enmod rewrite`, `AllowOverride All`):

```apache
<VirtualHost *:443>
    ServerName socly.example.org
    DocumentRoot /var/www/socly/public
    <Directory /var/www/socly/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx + PHP-FPM**:

```nginx
server {
    listen 443 ssl;
    server_name socly.example.org;
    root /var/www/socly/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

`public/.user.ini` alza i limiti di upload a 25 MB per i PDF. Con Nginx + PHP-FPM verifica anche
`upload_max_filesize` / `post_max_size` nel `php.ini`.

## 5. Installazione guidata

Apri `https://socly.example.org/install` e completa i cinque passaggi:

1. **Database e URL** — credenziali MySQL e indirizzo pubblico dell'applicazione.
2. **Associazione** — nome, contatti, colori del brand, lingua predefinita, GDPR.
3. **Periodo associativo e tipo di quota** — etichetta, date, nome e prezzo del tipo di socio.
4. **Campi profilo** — campi predefiniti da abilitare e quelli obbligatori.
5. **Riepilogo** — conferma: vengono create le tabelle, i permessi e il file `storage/installed.lock`.

L'**account di manutenzione** (l'unico SuperAdmin) è predefinito nel codice: non viene creato dal
wizard, non è modificabile né eliminabile dall'interfaccia e non può essere creato da altri utenti.
Dopo l'installazione il **wizard di primo avvio** su `/setup` crea l'account **Admin
dell'associazione** e guida la configurazione: dati dell'associazione, recupero dal RUNTS o dal sito
web, logo e colori, statuto e informativa privacy, tesoreria e scadenze.

## 6. Verifica

- `https://socly.example.org/login` risponde e il login funziona.
- Dashboard, creazione di un socio e aggiornamento di una quota funzionano.
- Se usi l'OCR, controlla la diagnostica:

```bash
php -r 'require "vendor/autoload.php"; print_r((new Socly\Services\PdfTextExtractor())->ocrDiagnostics());'
```

Log utili: `storage/logs/app.log`, `storage/cache/runts_ocr_job.log`,
`storage/cache/runts_ocr_last.json`.

## 7. Aggiornamenti

Le istanze installate leggono il manifest pubblico `latest.json` e avvisano gli amministratori quando
esce una nuova versione. Aggiornamento manuale:

1. Backup di database, `.env` e `storage/`.
2. Scarica l'ultima release da `dadaloop82/socly_public` (branch `main` o ZIP).
3. Sostituisci i file applicativi conservando `.env`, `.env.user` e `storage/`.
4. `composer install --no-dev` se sono cambiate le dipendenze.
5. Apri il sito: le migrazioni in attesa partono al primo request.

## Problemi comuni

| Sintomo | Causa / soluzione |
|---------|-------------------|
| Errore 500 immediato | Permessi su `storage/` o `.env` non scrivibile; leggi `storage/logs/app.log` |
| “DB error” nel wizard | Credenziali errate o database mancante; riapri `/install` (passo 1) per correggerle |
| OCR non disponibile | `exec()` disabilitato, oppure mancano `pdftoppm`/`tesseract`/`tessdata` italiano |
| OCR molto lento | Normale su PDF scansionati lunghi: il worker legge tutte le pagine (fino a 30 minuti) |
| Upload PDF rifiutato | Limite 25 MB (`public/.user.ini`) o `post_max_size` del `php.ini` |
| Migrazioni fallite | L'utente DB deve poter creare/alterare tabelle e indici |

</details>

<details>
<summary><strong>🇬🇧 English</strong></summary>

## Requirements

| Component | Version / notes |
|-----------|-----------------|
| PHP | 8.2+ with `pdo_mysql`, `openssl`, `mbstring`, `json`, `fileinfo` |
| Database | MariaDB 10.4+ / MySQL 8+ (`utf8mb4`) |
| Web server | Apache 2.4+ with `mod_rewrite`, or Nginx + PHP-FPM |
| Composer | 2.x (install and updates only) |
| Permissions | `storage/` and `.env` writable by the web server user |
| HTTPS | recommended (`Secure` session cookie, HSTS) |

OCR is **optional**; it is only needed to prefill the statute and privacy notice from RUNTS PDFs:

```bash
sudo apt install poppler-utils tesseract-ocr tesseract-ocr-ita
```

`exec()` must be enabled in PHP and the `php` CLI binary must be available (the OCR worker starts in
the background via `nohup`). When `exec()` is disabled, Socly falls back to a short inline OCR and
warns in the UI when the extracted text is partial.

## One-click installation (Linux, macOS, Windows)

The scripts in `bin/install/` verify the environment, install PHP 8.2+ with the required extensions,
MariaDB/MySQL, Composer and optional OCR tools, create the database and `.env`, apply migrations and
start the local server:

```bash
sudo bash bin/install/linux.sh                                      # Linux
bash bin/install/macos.sh                                           # macOS (Homebrew)
powershell -ExecutionPolicy Bypass -File bin\install\windows.ps1    # Windows (admin)
```

Useful flags: `--check` (verify only), `--no-ocr`, `--no-serve`, `--port=8080`,
`--db-root-pass=...`. On Windows the parameters are `-Check`, `-NoOcr`, `-NoServe`, `-Port`,
`-DbRootPass`. Details in [`bin/install/README.md`](../bin/install/README.md).

Then open `http://127.0.0.1:8080/install` and complete the wizard (association, period/type, fields).
The first-run setup then creates the association **Admin** account; the maintenance SuperAdmin is
hardcoded in the application and is not created by the wizard. The built-in PHP server is for
installation and evaluation: for production use Apache/Nginx + HTTPS as described below.

## 1. Get the code

```bash
git clone https://github.com/dadaloop82/socly_public.git socly
cd socly
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Set at least `APP_URL` and the `DB_*` values in `.env` (or let the wizard write them).
`APP_KEY` is generated by the installer: **keep it with your backups**, otherwise encrypted settings
cannot be decrypted.

## 2. Database

Create the database and user (the installer can create the database itself when the user has the
`CREATE` privilege):

```sql
CREATE DATABASE socly CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'socly'@'localhost' IDENTIFIED BY 'a-long-password';
GRANT ALL PRIVILEGES ON socly.* TO 'socly'@'localhost';
FLUSH PRIVILEGES;
```

Migrations (`database/migrations/`) run automatically on first boot and after every update: no manual
command is required.

## 3. Permissions

```bash
# adapt the user to your system: www-data (Debian/Ubuntu), apache, nginx…
sudo chown -R www-data:www-data storage
sudo find storage -type d -exec chmod 775 {} \;
sudo chown www-data:www-data .env
```

The document root must point to `public/` **only**. `.env`, `storage/` and `vendor/` stay outside.

## 4. Web server

**Apache** (`a2enmod rewrite`, `AllowOverride All`):

```apache
<VirtualHost *:443>
    ServerName socly.example.org
    DocumentRoot /var/www/socly/public
    <Directory /var/www/socly/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx + PHP-FPM**:

```nginx
server {
    listen 443 ssl;
    server_name socly.example.org;
    root /var/www/socly/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

`public/.user.ini` raises upload limits to 25 MB for PDFs. With Nginx + PHP-FPM also check
`upload_max_filesize` / `post_max_size` in `php.ini`.

## 5. Guided installation

Open `https://socly.example.org/install` and complete the five steps:

1. **Database and URL** — MySQL credentials and the public application URL.
2. **Association** — name, contacts, brand colours, default locale, GDPR.
3. **Membership period and type** — label, dates, name and price of the member type.
4. **Profile fields** — default fields to enable and the required ones.
5. **Summary** — confirm: tables, permissions and `storage/installed.lock` are created.

The **maintenance account** (the only SuperAdmin) is hardcoded in the application: it is not created
by the wizard, cannot be edited or deleted from the UI, and no other user can be promoted to
SuperAdmin. After installation the **first-run wizard** at `/setup` creates the association **Admin**
account and guides the configuration: association data, RUNTS or website lookup, logo and colours,
statute and privacy notice, treasury and deadlines.

## 6. Verify

- `https://socly.example.org/login` responds and login works.
- Dashboard, member creation and payment update work.
- If you use OCR, check the diagnostics:

```bash
php -r 'require "vendor/autoload.php"; print_r((new Socly\Services\PdfTextExtractor())->ocrDiagnostics());'
```

Useful logs: `storage/logs/app.log`, `storage/cache/runts_ocr_job.log`,
`storage/cache/runts_ocr_last.json`.

## 7. Updates

Installed instances read the public `latest.json` manifest and notify administrators when a new
version is published. Manual update:

1. Back up the database, `.env` and `storage/`.
2. Download the latest release from `dadaloop82/socly_public` (branch `main` or ZIP).
3. Replace application files, keeping `.env`, `.env.user` and `storage/`.
4. Run `composer install --no-dev` if dependencies changed.
5. Open the site: pending migrations run on the first request.

## Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| Immediate 500 error | `storage/` permissions or `.env` not writable; check `storage/logs/app.log` |
| “DB error” in the wizard | Wrong credentials or missing database; reopen `/install` (step 1) to fix them |
| OCR unavailable | `exec()` disabled, or missing `pdftoppm`/`tesseract`/Italian `tessdata` |
| OCR very slow | Normal for long scanned PDFs: the worker reads every page (up to 30 minutes) |
| PDF upload rejected | 25 MB limit (`public/.user.ini`) or `php.ini` `post_max_size` |
| Migrations failed | The DB user must be allowed to create/alter tables and indexes |

</details>

