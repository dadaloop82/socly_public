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
PLAN_ONLY=0
PORT_EXPLICIT=0
APP_URL_EXPLICIT=0
if [[ -n "${SOCLY_PORT:-}" ]]; then PORT_EXPLICIT=1; fi
if [[ -n "$APP_URL" ]]; then APP_URL_EXPLICIT=1; fi

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
  --plan                 Show what would be done without changing anything
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

env_value() {
    local key="$1" file="${2:-$ROOT/.env}" line value
    [[ -f "$file" ]] || return 1
    line="$(grep -m1 -E "^[[:space:]]*${key}[[:space:]]*=" "$file" 2>/dev/null || true)"
    [[ -n "$line" ]] || return 1
    value="${line#*=}"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    if [[ "$value" == \"*\" && "$value" == *\" ]]; then
        value="${value:1:${#value}-2}"
        value="${value//\\\$/\$}"
        value="${value//\\\"/\"}"
        value="${value//\\\\/\\}"
    elif [[ "$value" == \'*\' && "$value" == *\' ]]; then
        value="${value:1:${#value}-2}"
    fi
    printf '%s' "$value"
}

sql_escape() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\'/\'\'}"
    printf '%s' "$value"
}

port_in_use() {
    local port="$1"
    if have lsof; then
        lsof -nP -iTCP:"${port}" -sTCP:LISTEN >/dev/null 2>&1
        return $?
    fi
    (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null && { exec 3>&-; return 0; }
    return 1
}

# Resolve the port before .env is written, so APP_URL never points at a port
# that the built-in server does not use. An explicitly requested port that is
# busy stops the installation instead of silently serving somewhere else.
resolve_local_port() {
    CONNECT_HOST="$BIND"
    if [[ "$BIND" == "0.0.0.0" || "$BIND" == "::" ]]; then
        CONNECT_HOST="127.0.0.1"
    fi
    LOCAL_PORT="$PORT"
    for _ in $(seq 1 25); do
        port_in_use "$LOCAL_PORT" || break
        if [[ "$PORT_EXPLICIT" -eq 1 ]]; then
            die "La porta ${PORT} è già occupata: scegli un'altra porta con --port."
        fi
        warn "Porta ${LOCAL_PORT} occupata: provo $((LOCAL_PORT + 1))"
        LOCAL_PORT=$((LOCAL_PORT + 1))
    done
    if port_in_use "$LOCAL_PORT"; then
        die "Nessuna porta libera tra ${PORT} e $((PORT + 25))."
    fi
    if [[ "$APP_URL_EXPLICIT" -eq 0 ]]; then
        APP_URL="http://${CONNECT_HOST}:${LOCAL_PORT}"
    fi
}

while (($#)); do
    case "$1" in
        --port=*) PORT="${1#*=}"; PORT_EXPLICIT=1; shift ;;
        --port) PORT="$2"; PORT_EXPLICIT=1; shift 2 ;;
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
        --plan) PLAN_ONLY=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Opzione sconosciuta: $1 (usa --help)" ;;
    esac
done

APP_HOST="$BIND"
if [[ "$BIND" == "0.0.0.0" || "$BIND" == "::" ]]; then
    APP_HOST="127.0.0.1"
fi
APP_URL="${APP_URL:-http://${APP_HOST}:${PORT}}"

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
    if port_in_use "$PORT"; then
        echo "  Porta app: ${PORT} OCCUPATA (lo script cercherà la successiva libera)"
    else
        echo "  Porta app: ${PORT} libera"
    fi
    if port_in_use "$DB_PORT"; then
        echo "  Porta DB:  ${DB_PORT} in ascolto"
    else
        echo "  Porta DB:  ${DB_PORT} libera"
    fi
    echo "  Disco:     $(df -Pk "$ROOT" | awk 'NR==2 {printf "%.1f GB liberi", $4/1024/1024}')"
    curl -fsI --max-time 6 https://getcomposer.org >/dev/null 2>&1 \
        && echo "  Rete:      getcomposer.org raggiungibile" \
        || echo "  Rete:      getcomposer.org NON raggiungibile (proxy/firewall?)"
    [[ -f "$ROOT/.env.example" ]] && echo "  .env.example: presente" || echo "  .env.example: MANCANTE"
    [[ -d "$ROOT/vendor" ]] && echo "  vendor/:   presente" || echo "  vendor/:   assente (composer install)"
    [[ -w "$ROOT" ]] && echo "  Progetto:  scrivibile" || echo "  Progetto:  NON scrivibile"
    if [[ "$WITH_OCR" -eq 1 ]]; then
        have pdftoppm && have tesseract && echo "  OCR:       presente" || echo "  OCR:       assente (opzionale)"
    fi
    echo "  URL:       ${APP_URL}/install"
}

show_plan() {
    echo "Socly — piano di installazione (nessuna modifica)"
    echo "  Progetto:  $ROOT"
    local plan_port="$PORT"
    for _ in $(seq 1 25); do
        port_in_use "$plan_port" || break
        plan_port=$((plan_port + 1))
    done
    if [[ "$plan_port" == "$PORT" ]]; then
        echo "  Porta app: $PORT (bind $BIND) libera"
    else
        echo "  Porta app: $PORT (bind $BIND) occupata -> userà $plan_port"
    fi
    echo "  Database:  $DB_NAME su $DB_HOST:$DB_PORT (utente $DB_USER)"
    if php_version_ok && php_extensions_ok; then
        echo "  PHP:       riuso $(php -r 'echo PHP_VERSION;')"
    else
        echo "  PHP:       installazione Homebrew (php@8.4/8.3/8.2 o php)"
    fi
    have composer && echo "  Composer:  riuso eseguibile di sistema" || echo "  Composer:  installazione Homebrew"
    have mysql && echo "  MariaDB:   riuso client esistente" || echo "  MariaDB:   installazione Homebrew"
    echo "  .env:      creato/aggiornato, chmod 600"
    echo "  Migrazioni: php bin/install/bootstrap.php"
    if [[ "$DO_SERVE" -eq 0 ]]; then
        echo "  Server:    non avviato (--no-serve)"
    else
        echo "  Server:    php -S $BIND:<porta libera> -t public public/router.php"
    fi
}

if [[ "$CHECK_ONLY" -eq 1 ]]; then
    brew_env
    check_environment
    exit 0
fi

if [[ "$PLAN_ONLY" -eq 1 ]]; then
    show_plan
    exit 0
fi

if [[ "$DO_SERVE" -eq 1 ]]; then
    resolve_local_port
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
    for formula in php@8.4 php@8.3 php@8.2 php; do
        if brew list "$formula" >/dev/null 2>&1 || brew install "$formula"; then
            brew link --force --overwrite "$formula" >/dev/null 2>&1 || true
            if php_version_ok; then
                break
            fi
        fi
    done
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

db_ping() {
    local host="$1" port="$2"
    if [[ ( "$host" == "localhost" || "$host" == "127.0.0.1" || "$host" == "::1" ) && "$port" == "3306" ]]; then
        MYSQL_PWD="$DB_ROOT_PASS" mysqladmin -uroot ping --silent >/dev/null 2>&1 && return 0
    fi
    MYSQL_PWD="$DB_ROOT_PASS" mysqladmin -uroot -h "$host" -P "$port" --protocol=TCP ping --silent >/dev/null 2>&1
}

if [[ "$DB_HOST" == "localhost" || "$DB_HOST" == "127.0.0.1" || "$DB_HOST" == "::1" ]]; then
    ok "Avvio MariaDB"
    brew services start mariadb >/dev/null 2>&1 || brew services start mysql >/dev/null 2>&1 || true
else
    ok "Database remoto ${DB_HOST}:${DB_PORT}: salto l'avvio del servizio locale"
fi
for _ in $(seq 1 30); do
    if db_ping "$DB_HOST" "$DB_PORT"; then
        break
    fi
    sleep 1
done
db_ping "$DB_HOST" "$DB_PORT" \
    || die "MariaDB non risponde. Prova: brew services start mariadb"
ok "MariaDB attivo"

mysql_root() {
    MYSQL_PWD="$DB_ROOT_PASS" mysql -uroot "$@"
}

mysql_root_tcp() {
    MYSQL_PWD="$DB_ROOT_PASS" mysql -uroot -h "$DB_HOST" -P "$DB_PORT" --protocol=TCP "$@"
}

mysql_root_any() {
    case "$DB_HOST" in
        localhost|127.0.0.1|::1)
            # Only the default instance is certainly reachable through the
            # local socket; a custom port may point to another server.
            if [[ "$DB_PORT" == "3306" ]]; then
                if mysql_root "$@" 2>/dev/null; then
                    return 0
                fi
            fi
            ;;
    esac
    mysql_root_tcp "$@"
}

[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "Nome database non valido: usa lettere, numeri e _"
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "Nome utente database non valido: usa lettere, numeri e _"
if [[ -z "$DB_PASS" ]]; then
    DB_PASS="$(env_value DB_PASSWORD || true)"
fi
if [[ -z "$DB_PASS" ]]; then
    DB_PASS="$(openssl rand -hex 16 2>/dev/null || php -r 'echo bin2hex(random_bytes(16));')"
fi
[[ ${#DB_PASS} -ge 8 ]] || die "Password database troppo corta: usa almeno 8 caratteri."
case "$DB_PASS" in
    *[[:cntrl:]]*) die "La password database non può contenere caratteri di controllo." ;;
esac

# A root account reachable over TCP without a password is dangerous when
# the server listens on a public interface. Loopback-only setups (common
# with Homebrew) get a warning instead of a hard failure.
if [[ -z "$DB_ROOT_PASS" ]] \
    && MYSQL_PWD='' mysql -uroot -h 127.0.0.1 -P "$DB_PORT" --protocol=TCP -e 'SELECT 1' >/dev/null 2>&1; then
    exposed=0
    if have lsof; then
        if lsof -nP -iTCP:"${DB_PORT}" -sTCP:LISTEN 2>/dev/null | grep -Eq "\\*:${DB_PORT}|0\\.0\\.0\\.0:${DB_PORT}"; then
            exposed=1
        fi
    fi
    if [[ "$exposed" -eq 1 ]]; then
        die "root MariaDB accetta connessioni TCP senza password ed è in ascolto su tutte le interfacce. Imposta una password root e rilancia con --db-root-pass='...'."
    fi
    warn "root MariaDB accetta TCP senza password su loopback: imposta una password root se il servizio può essere raggiunto dalla rete."
fi

build_database_sql() {
    local pass_literal="$1"
    cat <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER_SQL}'@'localhost' IDENTIFIED BY '${pass_literal}';
CREATE USER IF NOT EXISTS '${DB_USER_SQL}'@'127.0.0.1' IDENTIFIED BY '${pass_literal}';
ALTER USER '${DB_USER_SQL}'@'localhost' IDENTIFIED BY '${pass_literal}';
ALTER USER '${DB_USER_SQL}'@'127.0.0.1' IDENTIFIED BY '${pass_literal}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER_SQL}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER_SQL}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
}

DB_PASS_SQL="$(sql_escape "$DB_PASS")"
DB_USER_SQL="$(sql_escape "$DB_USER")"
SQL_EXEC="$(build_database_sql "$DB_PASS_SQL")"
SQL_REDACTED="$(build_database_sql '***')"

if ! mysql_root_any -e "$SQL_EXEC"; then
    cat >&2 <<SQL
✖ Creazione database non riuscita.
Esegui manualmente nel client MySQL/MariaDB:

${SQL_REDACTED}

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
if ! composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist; then
    warn "composer install non riuscito: provo composer update"
    composer update --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
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
    echo "Completa il wizard in ${APP_URL}/install e poi il setup in ${APP_URL}/setup dopo il login."
    exit 0
fi

# The port was resolved before .env was written; only fail on a late race.
if port_in_use "$LOCAL_PORT"; then
    die "La porta ${LOCAL_PORT} è stata occupata nel frattempo: rilancia lo script."
fi
LOCAL_URL="http://${CONNECT_HOST}:${LOCAL_PORT}/install"

php -S "${BIND}:${LOCAL_PORT}" -t public public/router.php > storage/logs/install-server.log 2>&1 &
SERVER_PID=$!
cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

READY=0
for _ in $(seq 1 120); do
    response="$(curl -sS -i --max-time 3 "$LOCAL_URL" 2>/dev/null || true)"
    if printf '%s' "$response" | grep -Eiq '^HTTP/[0-9.]+ (200|302|303)' \
        && printf '%s' "$response" | grep -qi '^X-Socly-Installer: 1' \
        && printf '%s' "$response" | grep -qi '^Content-Type: text/html'; then
        READY=1
        break
    fi
    sleep 0.5
done
if [[ "$READY" -ne 1 ]]; then
    tail -n 30 storage/logs/install-server.log >&2 || true
    die "Il server locale non risponde su ${LOCAL_URL} (marcatore X-Socly-Installer assente)."
fi

if [[ "$OPEN_BROWSER" -eq 1 ]]; then
    open "$LOCAL_URL" >/dev/null 2>&1 || true
fi

cat <<TXT

✔ Socly è installato e il server locale è attivo.
  Wizard:   ${LOCAL_URL}
  Login:    http://${CONNECT_HOST}:${LOCAL_PORT}/login (account di manutenzione: admin@socly.it)
  Setup:    http://${CONNECT_HOST}:${LOCAL_PORT}/setup (dopo il wizard e il login)
  Log:      ${ROOT}/storage/logs/install-server.log
  Stop:     Ctrl+C

Completa il wizard (associazione, periodo, quote, campi); poi accedi con admin@socly.it e completa /setup.
La password di manutenzione non viene stampata né salvata dallo script.
Per la produzione (Apache/Nginx, HTTPS) vedi docs/INSTALLATION.md.
TXT

wait "$SERVER_PID"
