#!/usr/bin/env bash
# Socly one-click installer — macOS (Homebrew)
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"

PORT="${SOCLY_PORT:-8080}"
BIND="${SOCLY_BIND:-127.0.0.1}"
DB_HOST="${SOCLY_DB_HOST:-127.0.0.1}"
DB_PORT="${SOCLY_DB_PORT:-3306}"
DB_NAME="${SOCLY_DB_NAME:-socly}"
DB_USER="${SOCLY_DB_USER:-socly}"
DB_PASS="${SOCLY_DB_PASS:-}"
DB_ROOT_PASS="${SOCLY_DB_ROOT_PASS:-}"
APP_URL="${SOCLY_APP_URL:-}"
LOCALE="${SOCLY_LOCALE:-it}"
WITH_OCR=1
OPEN_BROWSER=1
DO_SERVE=1
CHECK_ONLY=0

usage() {
    cat <<'TXT'
Socly one-click installer (macOS)

Usage:
  bash bin/install/macos.sh [options]

Options:
  --port=PORT            HTTP port for the local server (default 8080)
  --bind=ADDR            Bind address (default 127.0.0.1)
  --db-name=NAME         Database name (default socly)
  --db-user=USER         Database user (default socly)
  --db-pass=PASS         Database password (default: generated)
  --db-root-pass=PASS    MariaDB root password (if needed)
  --url=URL              Public application URL (default http://BIND:PORT)
  --locale=it|de|en      Default locale (default it)
  --no-ocr               Skip poppler/tesseract installation
  --no-browser           Do not open the browser
  --no-serve             Install only, do not start the local server
  --check                Only verify the environment and exit
  -h, --help             Show this help

Requires Homebrew (the script offers to install it). Installs PHP 8.2+,
MariaDB, Composer and optional OCR tools, creates the database, writes .env,
runs migrations and starts the local server at http://BIND:PORT/install.
TXT
}

die() { printf '✖ %s\n' "$*" >&2; exit 1; }
ok() { printf '✔ %s\n' "$*"; }
warn() { printf '⚠ %s\n' "$*" >&2; }
have() { command -v "$1" >/dev/null 2>&1; }

while (($#)); do
    case "$1" in
        --port=*) PORT="${1#*=}"; shift ;;
        --port) PORT="$2"; shift 2 ;;
        --bind=*) BIND="${1#*=}"; shift ;;
        --bind) BIND="$2"; shift 2 ;;
        --db-name=*) DB_NAME="${1#*=}"; shift ;;
        --db-name) DB_NAME="$2"; shift 2 ;;
        --db-user=*) DB_USER="${1#*=}"; shift ;;
        --db-user) DB_USER="$2"; shift 2 ;;
        --db-pass=*) DB_PASS="${1#*=}"; shift ;;
        --db-pass) DB_PASS="$2"; shift 2 ;;
        --db-root-pass=*) DB_ROOT_PASS="${1#*=}"; shift ;;
        --db-root-pass) DB_ROOT_PASS="$2"; shift 2 ;;
        --url=*) APP_URL="${1#*=}"; shift ;;
        --url) APP_URL="$2"; shift 2 ;;
        --locale=*) LOCALE="${1#*=}"; shift ;;
        --locale) LOCALE="$2"; shift 2 ;;
        --no-ocr) WITH_OCR=0; shift ;;
        --no-browser) OPEN_BROWSER=0; shift ;;
        --no-serve) DO_SERVE=0; shift ;;
        --check) CHECK_ONLY=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Opzione sconosciuta: $1 (usa --help)" ;;
    esac
done

APP_URL="${APP_URL:-http://${BIND}:${PORT}}"

php_version_ok() {
    have php || return 1
    php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' >/dev/null 2>&1
}
php_extensions_ok() {
    have php || return 1
    php -r '$r=["pdo_mysql","mbstring","openssl","json","fileinfo"]; foreach($r as $e){ if(!extension_loaded($e)) exit(1); }' >/dev/null 2>&1
}

brew_env() {
    if [[ -x /opt/homebrew/bin/brew ]]; then
        eval "$(/opt/homebrew/bin/brew shellenv)"
    elif [[ -x /usr/local/bin/brew ]]; then
        eval "$(/usr/local/bin/brew shellenv)"
    fi
}

check_environment() {
    echo "Socly — verifica ambiente macOS"
    echo "  Sistema:   macOS $(sw_vers -productVersion 2>/dev/null || echo '?')"
    have brew && echo "  Homebrew:  $(brew --version | head -1)" || echo "  Homebrew:  assente (verrà installato)"
    have php && echo "  PHP:       $(php -r 'echo PHP_VERSION;')" || echo "  PHP:       assente (verrà installato)"
    php_extensions_ok && echo "  Estensioni: pdo_mysql, mbstring, openssl, json, fileinfo OK" \
        || echo "  Estensioni: MANCANTI (verranno installate)"
    have composer && echo "  Composer:  $(composer --version 2>/dev/null | head -1)" || echo "  Composer:  assente (verrà installato)"
    have mysql && echo "  MariaDB:   client presente" || echo "  MariaDB:   assente (verrà installato)"
    [[ -w "$ROOT" ]] && echo "  Progetto:  scrivibile" || echo "  Progetto:  NON scrivibile"
    if [[ "$WITH_OCR" -eq 1 ]]; then
        have pdftoppm && have tesseract && echo "  OCR:       presente" || echo "  OCR:       assente (opzionale)"
    fi
    echo "  URL:       ${APP_URL}/install"
}

if [[ "$CHECK_ONLY" -eq 1 ]]; then
    brew_env
    check_environment
    exit 0
fi

brew_env
if ! have brew; then
    echo "Homebrew non trovato: avvio l'installazione (può richiedere la password)…"
    NONINTERACTIVE=1 /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" \
        || die "Installazione Homebrew non riuscita. Installa Homebrew da https://brew.sh e riprova."
    brew_env
    have brew || die "Homebrew non disponibile dopo l'installazione."
fi

ok "Pacchetti Homebrew"
if ! php_version_ok; then
    if brew list php@8.2 >/dev/null 2>&1; then
        brew link --force --overwrite php@8.2 >/dev/null 2>&1 || true
    else
        brew install php@8.2 || brew install php
        brew link --force --overwrite php@8.2 >/dev/null 2>&1 || true
    fi
fi
brew list mariadb >/dev/null 2>&1 || brew install mariadb
brew list composer >/dev/null 2>&1 || brew install composer
if [[ "$WITH_OCR" -eq 1 ]]; then
    brew list poppler >/dev/null 2>&1 || brew install poppler
    brew list tesseract >/dev/null 2>&1 || brew install tesseract
    brew list tesseract-lang >/dev/null 2>&1 || brew install tesseract-lang || warn "Pacchetto lingue OCR non installato"
fi

php_version_ok || die "PHP 8.2+ non disponibile dopo l'installazione."
php_extensions_ok || die "Estensioni PHP mancanti (pdo_mysql, mbstring, openssl, json, fileinfo)."
have composer || die "Composer non disponibile."

ok "Avvio MariaDB"
brew services start mariadb >/dev/null 2>&1 || brew services start mysql >/dev/null 2>&1 || true
for _ in $(seq 1 30); do
    mysqladmin ping --silent >/dev/null 2>&1 && break
    sleep 1
done
mysqladmin ping --silent >/dev/null 2>&1 || die "MariaDB non risponde. Prova: brew services start mariadb"
ok "MariaDB attivo"

mysql_root() {
    if [[ -n "$DB_ROOT_PASS" ]]; then
        mysql -uroot -p"$DB_ROOT_PASS" "$@"
    else
        mysql -uroot "$@"
    fi
}

[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "Nome database non valido: usa lettere, numeri e _"
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "Nome utente database non valido: usa lettere, numeri e _"
[[ "$DB_PASS" =~ ^[A-Za-z0-9._-]*$ ]] || die "Password database non valida: usa lettere, numeri, punto, trattino, underscore"
if [[ -z "$DB_PASS" ]]; then
    DB_PASS="$(openssl rand -hex 16 2>/dev/null || php -r 'echo bin2hex(random_bytes(16));')"
fi

SQL="CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;"

if ! mysql_root -e "$SQL"; then
    cat >&2 <<SQL
✖ Creazione database non riuscita.
Esegui manualmente nel client MySQL/MariaDB:

${SQL}

Se root ha una password, rilancia con: bash bin/install/macos.sh --db-root-pass='...'
SQL
    exit 3
fi
ok "Database ${DB_NAME} e utente ${DB_USER} pronti"

cd "$ROOT"
[[ -f .env.example ]] || die ".env.example non trovato in $ROOT"
cp -n .env.example .env 2>/dev/null || true
chmod 600 .env 2>/dev/null || true

ok "Dipendenze PHP (Composer)"
if ! composer install --no-dev --optimize-autoloader --no-interaction; then
    warn "composer install non riuscito: provo composer update"
    composer update --no-dev --optimize-autoloader --no-interaction
fi

ok "Configurazione applicazione"
SOCLY_DB_PASS="$DB_PASS" php bin/install/bootstrap.php \
    --db-host="$DB_HOST" --db-port="$DB_PORT" --db-name="$DB_NAME" --db-user="$DB_USER" \
    --url="$APP_URL" --locale="$LOCALE"

mkdir -p storage/logs storage/cache storage/sessions storage/uploads storage/documents storage/runts
chmod -R u+rwX,g+rwX storage 2>/dev/null || true
chmod +x bin/install/*.sh 2>/dev/null || true

if [[ "$DO_SERVE" -eq 0 ]]; then
    ok "Installazione completata (server non avviato)."
    echo "Apri ${APP_URL}/install per completare il wizard."
    exit 0
fi

LOCAL_PORT="$PORT"
if have lsof; then
    for _ in $(seq 1 20); do
        lsof -nP -iTCP:"${LOCAL_PORT}" -sTCP:LISTEN >/dev/null 2>&1 || break
        LOCAL_PORT=$((LOCAL_PORT + 1))
    done
fi
LOCAL_URL="http://${BIND}:${LOCAL_PORT}/install"

php -S "${BIND}:${LOCAL_PORT}" -t public public/router.php > storage/logs/install-server.log 2>&1 &
SERVER_PID=$!
cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

READY=0
for _ in $(seq 1 40); do
    code="$(curl -s -o /dev/null -w '%{http_code}' "$LOCAL_URL" 2>/dev/null || true)"
    if [[ "$code" =~ ^(200|302|303)$ ]]; then
        READY=1
        break
    fi
    sleep 0.5
done
if [[ "$READY" -ne 1 ]]; then
    tail -n 30 storage/logs/install-server.log >&2 || true
    die "Il server locale non risponde su ${LOCAL_URL}"
fi

if [[ "$OPEN_BROWSER" -eq 1 ]]; then
    open "$LOCAL_URL" >/dev/null 2>&1 || true
fi

cat <<TXT

✔ Socly è installato e il server locale è attivo.
  Wizard:   ${LOCAL_URL}
  Log:      ${ROOT}/storage/logs/install-server.log
  Stop:     Ctrl+C

Completa il wizard (associazione, account Admin, periodo, quote, campi).
Per la produzione (Apache/Nginx, HTTPS) vedi docs/INSTALLATION.md.
TXT

wait "$SERVER_PID"
