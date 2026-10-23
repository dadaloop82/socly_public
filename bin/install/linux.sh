#!/usr/bin/env bash
# Socly one-click installer — Linux
# Supports Debian/Ubuntu, Fedora/RHEL, Arch, openSUSE and Alpine.
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
Socly one-click installer (Linux)

Usage:
  sudo bash bin/install/linux.sh [options]

Options:
  --port=PORT            HTTP port for the local server (default 8080)
  --bind=ADDR            Bind address (default 127.0.0.1)
  --db-name=NAME         Database name (default socly)
  --db-user=USER         Database user (default socly)
  --db-pass=PASS         Database password (default: generated)
  --db-root-pass=PASS    MariaDB/MySQL root password (if needed)
  --url=URL              Public application URL (default http://BIND:PORT)
  --locale=it|de|en      Default locale (default it)
  --no-ocr               Skip poppler/tesseract installation
  --no-browser           Do not open the browser
  --no-serve             Install only, do not start the local server
  --check                Only verify the environment and exit
  -h, --help             Show this help

The script installs PHP 8.2+, MariaDB, Composer and optional OCR tools,
creates the database, writes .env, runs migrations and starts the local
server. Complete the wizard at http://BIND:PORT/install.
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

check_environment() {
    echo "Socly — verifica ambiente Linux"
    if [[ -f /etc/os-release ]]; then
        # shellcheck disable=SC1091
        . /etc/os-release
        echo "  Sistema:   ${PRETTY_NAME:-Linux}"
    fi
    if have php; then
        echo "  PHP:       $(php -r 'echo PHP_VERSION;')"
        php_extensions_ok && echo "  Estensioni: pdo_mysql, mbstring, openssl, json, fileinfo OK" \
            || echo "  Estensioni: MANCANTI (verranno installate)"
    else
        echo "  PHP:       assente (verrà installato)"
    fi
    have composer && echo "  Composer:  $(composer --version 2>/dev/null | head -1)" || echo "  Composer:  assente (verrà installato)"
    if have mysql || have mariadb; then
        echo "  MariaDB:   client presente"
    else
        echo "  MariaDB:   assente (verrà installato)"
    fi
    [[ -w "$ROOT" ]] && echo "  Progetto:  scrivibile" || echo "  Progetto:  NON scrivibile (usa sudo)"
    if [[ "$WITH_OCR" -eq 1 ]]; then
        have pdftoppm && have tesseract && echo "  OCR:       presente" || echo "  OCR:       assente (opzionale)"
    fi
    echo "  URL:       ${APP_URL}/install"
}

if [[ "$CHECK_ONLY" -eq 1 ]]; then
    check_environment
    exit 0
fi

if [[ "${EUID}" -ne 0 ]]; then
    exec sudo -E bash "$0" "$@"
fi
APP_USER="${SUDO_USER:-$(id -un)}"
export DEBIAN_FRONTEND=noninteractive

install_composer_official() {
    curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
}

install_packages() {
    if have apt-get; then
        ok "Pacchetti Debian/Ubuntu"
        apt-get update
        apt-get install -y ca-certificates curl unzip mariadb-server mariadb-client
        if ! php_version_ok; then
            if ! apt-get install -y php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd php8.2-intl; then
                warn "php8.2 non disponibile: provo il repository ondrej/php"
                apt-get install -y software-properties-common gnupg
                add-apt-repository -y ppa:ondrej/php || true
                apt-get update
                apt-get install -y php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd php8.2-intl
            fi
        else
            local ver
            ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
            apt-get install -y "php${ver}-mysql" "php${ver}-mbstring" "php${ver}-xml" "php${ver}-curl" "php${ver}-zip" "php${ver}-gd" "php${ver}-intl" \
                || apt-get install -y php-mysql php-mbstring php-xml php-curl php-zip php-gd php-intl
        fi
        have composer || apt-get install -y composer || install_composer_official
        if [[ "$WITH_OCR" -eq 1 ]]; then
            apt-get install -y poppler-utils tesseract-ocr tesseract-ocr-ita \
                || warn "OCR non installato (opzionale): puoi aggiungerlo dopo"
        fi
    elif have dnf; then
        ok "Pacchetti Fedora/RHEL"
        dnf install -y curl unzip mariadb-server mariadb \
            php-cli php-mysqlnd php-mbstring php-openssl php-curl php-zip php-gd php-intl
        if [[ "$WITH_OCR" -eq 1 ]]; then
            dnf install -y poppler-utils tesseract tesseract-langpack-ita \
                || warn "OCR non installato (opzionale): puoi aggiungerlo dopo"
        fi
    elif have pacman; then
        ok "Pacchetti Arch"
        pacman -Sy --noconfirm --needed php php-gd composer mariadb curl unzip
        if [[ "$WITH_OCR" -eq 1 ]]; then
            pacman -S --noconfirm --needed poppler tesseract tesseract-data-ita \
                || warn "OCR non installato (opzionale): puoi aggiungerlo dopo"
        fi
    elif have zypper; then
        ok "Pacchetti openSUSE"
        zypper --non-interactive install php8 php8-mysql php8-mbstring php8-openssl \
            php8-curl php8-zip php8-gd php8-intl mariadb mariadb-client curl unzip
        if [[ "$WITH_OCR" -eq 1 ]]; then
            zypper --non-interactive install poppler-tools tesseract-ocr tesseract-ocr-ita \
                || warn "OCR non installato (opzionale): puoi aggiungerlo dopo"
        fi
    elif have apk; then
        ok "Pacchetti Alpine"
        apk add --no-cache php82 php82-pdo_mysql php82-mbstring php82-openssl php82-json \
            php82-fileinfo php82-curl php82-zip php82-gd php82-intl php82-session php82-tokenizer php82-xml php82-phar \
            composer mariadb mariadb-client curl unzip
        ln -sf /usr/bin/php82 /usr/local/bin/php
        if [[ "$WITH_OCR" -eq 1 ]]; then
            apk add --no-cache poppler-utils tesseract-ocr tesseract-ocr-data-ita \
                || warn "OCR non installato (opzionale): puoi aggiungerlo dopo"
        fi
    else
        die "Package manager non supportato: installa PHP 8.2+, MariaDB e Composer manualmente."
    fi
}

start_db() {
    if have systemctl; then
        systemctl enable --now mariadb >/dev/null 2>&1 || systemctl enable --now mysql >/dev/null 2>&1 || true
    elif have rc-service; then
        rc-update add mariadb default >/dev/null 2>&1 || true
        rc-service mariadb start >/dev/null 2>&1 || true
    elif have service; then
        service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null 2>&1 || true
    fi
    for _ in $(seq 1 30); do
        mysqladmin ping --silent >/dev/null 2>&1 && return 0
        sleep 1
    done
    return 1
}

mysql_root() {
    if [[ -n "$DB_ROOT_PASS" ]]; then
        mysql -uroot -p"$DB_ROOT_PASS" "$@"
    else
        mysql -uroot "$@"
    fi
}

create_database() {
    [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "Nome database non valido: usa lettere, numeri e _"
    [[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "Nome utente database non valido: usa lettere, numeri e _"
    [[ "$DB_PASS" =~ ^[A-Za-z0-9._-]*$ ]] || die "Password database non valida: usa lettere, numeri, punto, trattino, underscore"

    if [[ -z "$DB_PASS" ]]; then
        if have openssl; then
            DB_PASS="$(openssl rand -hex 16)"
        else
            DB_PASS="$(php -r 'echo bin2hex(random_bytes(16));')"
        fi
    fi

    local sql
    sql="CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;"

    if ! mysql_root -e "$sql"; then
        cat >&2 <<SQL
✖ Creazione database non riuscita.
Esegui manualmente come root MySQL/MariaDB:

${sql}

Se root ha una password, rilancia con: sudo bash bin/install/linux.sh --db-root-pass='...'
SQL
        exit 3
    fi
    ok "Database ${DB_NAME} e utente ${DB_USER} pronti"
}

as_app() {
    if [[ "$APP_USER" == "root" ]]; then
        "$@"
    else
        sudo -u "$APP_USER" "$@"
    fi
}

echo "Socly — installazione one-click (Linux)"
install_packages

php_version_ok || die "PHP 8.2+ non disponibile dopo l'installazione."
php_extensions_ok || die "Estensioni PHP mancanti (pdo_mysql, mbstring, openssl, json, fileinfo)."
have composer || install_composer_official
have composer || die "Composer non disponibile."

start_db || die "MariaDB/MySQL non risponde. Avvialo e riprova."
ok "MariaDB/MySQL attivo"
create_database

cd "$ROOT"
[[ -f .env.example ]] || die ".env.example non trovato in $ROOT"
cp -n .env.example .env 2>/dev/null || true
chown "$APP_USER":"$APP_USER" .env 2>/dev/null || true
chmod 600 .env 2>/dev/null || true

ok "Dipendenze PHP (Composer)"
if ! as_app composer install --no-dev --optimize-autoloader --no-interaction; then
    warn "composer install come ${APP_USER} non riuscito: riprovo come root"
    if ! composer install --no-dev --optimize-autoloader --no-interaction; then
        warn "composer install non riuscito: provo composer update"
        composer update --no-dev --optimize-autoloader --no-interaction
    fi
fi

ok "Configurazione applicazione"
as_app env SOCLY_DB_PASS="$DB_PASS" php bin/install/bootstrap.php \
    --db-host="$DB_HOST" --db-port="$DB_PORT" --db-name="$DB_NAME" --db-user="$DB_USER" \
    --url="$APP_URL" --locale="$LOCALE"

mkdir -p storage/logs storage/cache storage/sessions storage/uploads storage/documents storage/runts
chown -R "$APP_USER":"$APP_USER" storage 2>/dev/null || true
chmod -R u+rwX,g+rwX storage 2>/dev/null || true
chmod +x bin/install/*.sh 2>/dev/null || true

if [[ "$DO_SERVE" -eq 0 ]]; then
    ok "Installazione completata (server non avviato)."
    echo "Apri ${APP_URL}/install per completare il wizard."
    exit 0
fi

# Pick a free local port when the default one is busy.
LOCAL_PORT="$PORT"
if have ss; then
    for _ in $(seq 1 20); do
        if ! ss -ltn 2>/dev/null | grep -q ":${LOCAL_PORT} "; then
            break
        fi
        LOCAL_PORT=$((LOCAL_PORT + 1))
    done
fi
LOCAL_URL="http://${BIND}:${LOCAL_PORT}/install"

mkdir -p storage/logs
touch storage/logs/install-server.log 2>/dev/null || true
chown "$APP_USER":"$APP_USER" storage/logs/install-server.log 2>/dev/null || true
if [[ "$APP_USER" == "root" ]]; then
    php -S "${BIND}:${LOCAL_PORT}" -t public public/router.php > storage/logs/install-server.log 2>&1 &
else
    # shellcheck disable=SC2024 # log file ownership handled above
    sudo -u "$APP_USER" php -S "${BIND}:${LOCAL_PORT}" -t public public/router.php > storage/logs/install-server.log 2>&1 &
fi
SERVER_PID=$!
cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
    pkill -P "$SERVER_PID" 2>/dev/null || true
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
    if have xdg-open; then
        xdg-open "$LOCAL_URL" >/dev/null 2>&1 &
    elif have sensible-browser; then
        sensible-browser "$LOCAL_URL" >/dev/null 2>&1 &
    fi
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
