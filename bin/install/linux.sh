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
PLAN_ONLY=0
PORT_EXPLICIT=0
APP_URL_EXPLICIT=0
# The option loop below consumes "$@": keep a copy for the sudo re-exec.
ORIG_ARGS=("$@")
if [[ -n "${SOCLY_PORT:-}" ]]; then PORT_EXPLICIT=1; fi
if [[ -n "$APP_URL" ]]; then APP_URL_EXPLICIT=1; fi

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
  --plan                 Show what would be done without changing anything
  -h, --help             Show this help

The script installs PHP 8.4/8.3 (preferred) or 8.2, MariaDB, Composer and
optional OCR tools, creates the database, writes .env, runs migrations and
starts the local server. Complete the wizard at http://BIND:PORT/install.
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
    if have ss; then
        ss -ltn 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]${port}$"
        return $?
    fi
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

check_environment() {
    echo "Socly — verifica ambiente Linux"
    if [[ -f /etc/os-release ]]; then
        # shellcheck disable=SC1091
        . /etc/os-release
        echo "  Sistema:   ${PRETTY_NAME:-Linux}"
    fi
    if [[ "${EUID}" -eq 0 ]]; then
        echo "  Admin:     sì (necessario per pacchetti, servizio DB e permessi)"
    else
        echo "  Admin:     no (lo script si eleva con sudo)"
    fi
    if have php; then
        echo "  PHP:       $(php -r 'echo PHP_VERSION;')"
        php_extensions_ok && echo "  Estensioni: pdo_mysql, mbstring, openssl, json, fileinfo OK" \
            || echo "  Estensioni: MANCANTI (verranno installate)"
        echo "  php.ini:   display_errors=$(php -r 'echo ini_get("display_errors") ?: "0";') log_errors=$(php -r 'echo ini_get("log_errors") ?: "0";')"
    else
        echo "  PHP:       assente (verrà installato)"
    fi
    have composer && echo "  Composer:  $(composer --version 2>/dev/null | head -1)" || echo "  Composer:  assente (verrà installato)"
    if have mysql || have mariadb; then
        echo "  MariaDB:   client presente"
    else
        echo "  MariaDB:   assente (verrà installato)"
    fi
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
    if command -v df >/dev/null 2>&1; then
        echo "  Disco:     $(df -Pk "$ROOT" | awk 'NR==2 {printf "%.1f GB liberi", $4/1024/1024}')"
    fi
    if have curl; then
        curl -fsI --max-time 6 https://getcomposer.org >/dev/null 2>&1 \
            && echo "  Rete:      getcomposer.org raggiungibile" \
            || echo "  Rete:      getcomposer.org NON raggiungibile (proxy/firewall?)"
    fi
    [[ -f "$ROOT/.env.example" ]] && echo "  .env.example: presente" || echo "  .env.example: MANCANTE"
    [[ -d "$ROOT/vendor" ]] && echo "  vendor/:   presente" || echo "  vendor/:   assente (composer install)"
    [[ -w "$ROOT" ]] && echo "  Progetto:  scrivibile" || echo "  Progetto:  NON scrivibile (usa sudo)"
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
        echo "  PHP:       installazione pacchetti 8.2+ con estensioni richieste"
    fi
    have composer && echo "  Composer:  riuso eseguibile di sistema" || echo "  Composer:  download verificato SHA-256 in /usr/local/bin/composer"
    if have mysql || have mariadb; then
        echo "  MariaDB:   riuso client esistente"
    else
        echo "  MariaDB:   installazione pacchetto server + client"
    fi
    echo "  .env:      creato/aggiornato, chmod 600"
    echo "  Migrazioni: php bin/install/bootstrap.php"
    if [[ "$DO_SERVE" -eq 0 ]]; then
        echo "  Server:    non avviato (--no-serve)"
    else
        echo "  Server:    php -S $BIND:<porta libera> -t public public/router.php"
    fi
}

if [[ "$CHECK_ONLY" -eq 1 ]]; then
    check_environment
    exit 0
fi

if [[ "$PLAN_ONLY" -eq 1 ]]; then
    show_plan
    exit 0
fi

if [[ "${EUID}" -ne 0 ]]; then
    if [[ ${#ORIG_ARGS[@]} -gt 0 ]]; then
        exec sudo -E bash "$0" "${ORIG_ARGS[@]}"
    fi
    exec sudo -E bash "$0"
fi
APP_USER="${SUDO_USER:-$(id -un)}"
export DEBIAN_FRONTEND=noninteractive
if [[ "$DO_SERVE" -eq 1 ]]; then
    resolve_local_port
fi

install_composer_official() {
    local tmp base expected actual
    tmp="$(mktemp -d)"
    base='https://getcomposer.org/download/latest-stable'
    curl -fsSL -o "$tmp/composer.phar" "$base/composer.phar"
    curl -fsSL -o "$tmp/composer.phar.sha256sum" "$base/composer.phar.sha256sum"
    expected="$(awk '{print $1}' "$tmp/composer.phar.sha256sum")"
    if have sha256sum; then
        actual="$(sha256sum "$tmp/composer.phar" | awk '{print $1}')"
    elif have shasum; then
        actual="$(shasum -a 256 "$tmp/composer.phar" | awk '{print $1}')"
    else
        rm -rf "$tmp"
        die 'sha256sum/shasum non disponibile: impossibile verificare Composer.'
    fi
    if [[ -z "$expected" || "$actual" != "$expected" ]]; then
        rm -rf "$tmp"
        die 'Checksum Composer non valido (download corrotto o manomesso).'
    fi
    install -m 0755 "$tmp/composer.phar" /usr/local/bin/composer
    rm -rf "$tmp"
    ok 'Composer installato e verificato'
}

install_packages() {
    if have apt-get; then
        ok "Pacchetti Debian/Ubuntu"
        apt-get update
        apt-get install -y ca-certificates curl unzip mariadb-server mariadb-client
        if ! php_version_ok; then
            local installed=0 v
            for v in 8.4 8.3 8.2; do
                if apt-cache show "php${v}-cli" >/dev/null 2>&1; then
                    if apt-get install -y "php${v}-cli" "php${v}-mysql" "php${v}-mbstring" "php${v}-xml" \
                        "php${v}-curl" "php${v}-zip" "php${v}-gd" "php${v}-intl"; then
                        installed=1
                        break
                    fi
                fi
            done
            if [[ "$installed" -eq 0 ]]; then
                warn "PHP 8.2+ non disponibile nei repository: provo il repository ondrej/php"
                apt-get install -y software-properties-common gnupg
                add-apt-repository -y ppa:ondrej/php || true
                apt-get update
                for v in 8.4 8.3 8.2; do
                    if apt-cache show "php${v}-cli" >/dev/null 2>&1; then
                        if apt-get install -y "php${v}-cli" "php${v}-mysql" "php${v}-mbstring" "php${v}-xml" \
                            "php${v}-curl" "php${v}-zip" "php${v}-gd" "php${v}-intl"; then
                            installed=1
                            break
                        fi
                    fi
                done
            fi
            [[ "$installed" -eq 1 ]] || die "PHP 8.2+ non installabile automaticamente: installalo e riprova."
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
        apk update >/dev/null 2>&1 || true
        # Alpine ships PHP per version and not every branch has the newest one.
        # json/session/fileinfo are not separate packages on Alpine.
        local apk_php=''
        for v in 84 83 82; do
            if apk info -e "php${v}" >/dev/null 2>&1; then apk_php="$v"; break; fi
        done
        [[ -n "$apk_php" ]] || die "Nessun pacchetto PHP 8.2+ nei repository Alpine: aggiorna la distro e riprova."
        apk add --no-cache "php${apk_php}" \
            "php${apk_php}-pdo_mysql" "php${apk_php}-mbstring" "php${apk_php}-openssl" \
            "php${apk_php}-curl" "php${apk_php}-zip" "php${apk_php}-gd" "php${apk_php}-intl" \
            "php${apk_php}-phar" "php${apk_php}-tokenizer" "php${apk_php}-xml" \
            "php${apk_php}-session" "php${apk_php}-fileinfo" \
            composer mariadb mariadb-client curl unzip
        ln -sf "/usr/bin/php${apk_php}" /usr/local/bin/php
        if [[ "$WITH_OCR" -eq 1 ]]; then
            apk add --no-cache poppler-utils tesseract-ocr tesseract-ocr-data-ita \
                || warn "OCR non installato (opzionale): puoi aggiungerlo dopo"
        fi
    else
        die "Package manager non supportato: installa PHP 8.2+, MariaDB e Composer manualmente."
    fi
}

db_ping() {
    local host="$1" port="$2"
    if [[ ( "$host" == "localhost" || "$host" == "127.0.0.1" || "$host" == "::1" ) && "$port" == "3306" ]]; then
        MYSQL_PWD="$DB_ROOT_PASS" mysqladmin -uroot ping --silent >/dev/null 2>&1 && return 0
    fi
    MYSQL_PWD="$DB_ROOT_PASS" mysqladmin -uroot -h "$host" -P "$port" --protocol=TCP ping --silent >/dev/null 2>&1
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
        if db_ping "$DB_HOST" "$DB_PORT"; then
            return 0
        fi
        sleep 1
    done
    return 1
}

mysql_root() {
    MYSQL_PWD="$DB_ROOT_PASS" mysql -uroot "$@"
}

mysql_root_tcp() {
    MYSQL_PWD="$DB_ROOT_PASS" mysql -uroot -h "$DB_HOST" -P "$DB_PORT" --protocol=TCP "$@"
}

# Local servers are usually reached through the unix socket (root@localhost
# with unix_socket auth); remote or custom-port servers must use TCP.
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

build_database_sql() {
    local pass_literal="$1"
    cat <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${user_sql}'@'localhost' IDENTIFIED BY '${pass_literal}';
CREATE USER IF NOT EXISTS '${user_sql}'@'127.0.0.1' IDENTIFIED BY '${pass_literal}';
ALTER USER '${user_sql}'@'localhost' IDENTIFIED BY '${pass_literal}';
ALTER USER '${user_sql}'@'127.0.0.1' IDENTIFIED BY '${pass_literal}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${user_sql}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${user_sql}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
}

create_database() {
    [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "Nome database non valido: usa lettere, numeri e _"
    [[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "Nome utente database non valido: usa lettere, numeri e _"

    if [[ -z "$DB_PASS" ]]; then
        DB_PASS="$(env_value DB_PASSWORD || true)"
    fi
    if [[ -z "$DB_PASS" ]]; then
        if have openssl; then
            DB_PASS="$(openssl rand -hex 16)"
        else
            DB_PASS="$(php -r 'echo bin2hex(random_bytes(16));')"
        fi
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
        if have ss; then
            if ss -ltn 2>/dev/null | awk '{print $4}' | grep -Eq "^(0\\.0\\.0\\.0|\\*|\\[::\\]):${DB_PORT}$"; then
                exposed=1
            fi
        elif have lsof; then
            if lsof -nP -iTCP:"${DB_PORT}" -sTCP:LISTEN 2>/dev/null | grep -Eq "\\*:${DB_PORT}|0\\.0\\.0\\.0:${DB_PORT}"; then
                exposed=1
            fi
        fi
        if [[ "$exposed" -eq 1 ]]; then
            die "root MariaDB accetta connessioni TCP senza password ed è in ascolto su tutte le interfacce. Imposta una password root e rilancia con --db-root-pass='...'."
        fi
        warn "root MariaDB accetta TCP senza password su loopback: imposta una password root se il servizio può essere raggiunto dalla rete."
    fi

    local db_pass_sql user_sql sql_exec sql_redacted
    db_pass_sql="$(sql_escape "$DB_PASS")"
    user_sql="$(sql_escape "$DB_USER")"
    sql_exec="$(build_database_sql "$db_pass_sql")"
    sql_redacted="$(build_database_sql '***')"

    if ! mysql_root_any -e "$sql_exec"; then
        cat >&2 <<SQL
✖ Creazione database non riuscita.
Esegui manualmente come root MySQL/MariaDB:

${sql_redacted}

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
        # -H: give the child the target user's HOME, otherwise Composer tries
        # to write its cache into /root.
        sudo -H -u "$APP_USER" "$@"
    fi
}

echo "Socly — installazione one-click (Linux)"
install_packages

php_version_ok || die "PHP 8.2+ non disponibile dopo l'installazione."
php_extensions_ok || die "Estensioni PHP mancanti (pdo_mysql, mbstring, openssl, json, fileinfo)."
have composer || install_composer_official
have composer || die "Composer non disponibile."

if [[ "$DB_HOST" == "localhost" || "$DB_HOST" == "127.0.0.1" || "$DB_HOST" == "::1" ]]; then
    start_db || die "MariaDB/MySQL non risponde. Avvialo e riprova."
    ok "MariaDB/MySQL attivo"
else
    ok "Database remoto ${DB_HOST}:${DB_PORT}: salto l'avvio del servizio locale"
fi
create_database

cd "$ROOT"
[[ -f .env.example ]] || die ".env.example non trovato in $ROOT"
cp -n .env.example .env 2>/dev/null || true
chown "$APP_USER":"$APP_USER" .env 2>/dev/null || true
chmod 600 .env 2>/dev/null || true

ok "Dipendenze PHP (Composer)"
if ! as_app composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist; then
    warn "composer install come ${APP_USER} non riuscito: riprovo come root"
    if ! composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist; then
        warn "composer install non riuscito: provo composer update"
        composer update --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
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
    echo "Completa il wizard in ${APP_URL}/install e poi il setup in ${APP_URL}/setup dopo il login."
    exit 0
fi

# The port was resolved before .env was written; only fail on a late race.
if port_in_use "$LOCAL_PORT"; then
    die "La porta ${LOCAL_PORT} è stata occupata nel frattempo: rilancia lo script."
fi
LOCAL_URL="http://${CONNECT_HOST}:${LOCAL_PORT}/install"

mkdir -p storage/logs
touch storage/logs/install-server.log 2>/dev/null || true
chown "$APP_USER":"$APP_USER" storage/logs/install-server.log 2>/dev/null || true
if [[ "$APP_USER" == "root" ]]; then
    php -S "${BIND}:${LOCAL_PORT}" -t public public/router.php > storage/logs/install-server.log 2>&1 &
else
    # shellcheck disable=SC2024 # log file ownership handled above
    sudo -H -u "$APP_USER" php -S "${BIND}:${LOCAL_PORT}" -t public public/router.php > storage/logs/install-server.log 2>&1 &
fi
SERVER_PID=$!
cleanup() {
    kill "$SERVER_PID" 2>/dev/null || true
    pkill -P "$SERVER_PID" 2>/dev/null || true
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
    if have xdg-open; then
        xdg-open "$LOCAL_URL" >/dev/null 2>&1 &
    elif have sensible-browser; then
        sensible-browser "$LOCAL_URL" >/dev/null 2>&1 &
    fi
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
