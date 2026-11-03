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
RESET_DB_PASS=0
FORCE_ENV=0
APP_USER_OVERRIDE="${SOCLY_APP_USER:-}"
APT_UPDATED=0
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
  --reset-db-pass        Rotate the password of an already existing DB user
  --user=USER            System user that owns .env/storage and runs the server
                         (default: the user that invoked sudo, else the owner
                         of storage/, else the current user)
  --force                Overwrite an .env pointing to another database
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

is_local_db() {
    case "$DB_HOST" in
        localhost|127.0.0.1|::1) return 0 ;;
    esac
    return 1
}

# Composer >= 2.5 asks for an interactive confirmation when it runs as root:
# always call it non-interactively, otherwise diagnostics such as --check look
# frozen when the script runs on a TTY.
composer_version() {
    COMPOSER_ALLOW_SUPERUSER=1 composer --version --no-interaction 2>/dev/null </dev/null | head -1
}

# The wizard rewrites APP_URL/DB_* in .env by design: doing that inside a
# directory that already hosts another instance would silently repoint it.
guard_existing_env() {
    [[ "$FORCE_ENV" -eq 1 ]] && return 0
    local env_db env_user
    env_db="$(env_value DB_DATABASE || true)"
    [[ -n "$env_db" ]] || return 0
    env_user="$(env_value DB_USERNAME || true)"
    if [[ "$env_db" == "$DB_NAME" && ( -z "$env_user" || "$env_user" == "$DB_USER" ) ]]; then
        return 0
    fi
    cat >&2 <<MSG
✖ ${ROOT}/.env punta già al database '${env_db}' (utente '${env_user:-?}').
  Sovrascriverlo romperebbe l'istanza esistente. Scegli:
    --db-name=${env_db} --db-user=${env_user:-$DB_USER}   riusa l'istanza presente
    installa in una cartella nuova (es. /opt/socly o un altro vhost)
    --force                                              sovrascrive comunque
MSG
    exit 2
}

# Resolve the port before .env is written, so APP_URL never points at a port
# that the built-in server does not use. An explicitly requested port that is
# busy stops the installation instead of silently serving somewhere else.
resolve_local_port() {
    CONNECT_HOST="$BIND"
    if [[ "$BIND" == "0.0.0.0" || "$BIND" == "::" ]]; then
        CONNECT_HOST="127.0.0.1"
    fi
    case "$BIND" in
        127.*|::1|localhost) ;;
        *)
            warn "--bind=${BIND} espone il wizard /install (nessuna autenticazione) su tutte le interfacce."
            echo "  Preferisci 127.0.0.1 + tunnel SSH (ssh -L ${PORT}:127.0.0.1:${PORT} utente@server)," >&2
            echo "  oppure --no-serve e servi l'app con un vhost Apache/Nginx." >&2
            ;;
    esac
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
        --user=*) APP_USER_OVERRIDE="${1#*=}"; shift ;;
        --user) APP_USER_OVERRIDE="$2"; shift 2 ;;
        --reset-db-pass) RESET_DB_PASS=1; shift ;;
        --force) FORCE_ENV=1; shift ;;
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

php_extra_extensions_ok() {
    have php || return 1
    php -r '$r=["curl","zip","gd","intl","xml"]; foreach($r as $e){ if(!extension_loaded($e)) exit(1); }' >/dev/null 2>&1
}

# The unix user that will own .env/storage and run the local server. Running as
# root is a trap: the app then cannot be served by www-data/php-fpm, so an
# existing storage/ owner wins over "root".
app_user_candidate() {
    local user="${APP_USER_OVERRIDE:-${SUDO_USER:-$(id -un)}}" owner
    if [[ "$user" == "root" ]]; then
        owner="$(stat -c '%U' "$ROOT/storage" 2>/dev/null || true)"
        if [[ -n "$owner" && "$owner" != "root" && "$owner" != "UNKNOWN" ]]; then
            printf '%s' "$owner"
            return 0
        fi
    fi
    printf '%s' "$user"
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
    echo "  Utente app: $(app_user_candidate) (proprietario di .env e storage/)"
    if have php; then
        echo "  PHP:       $(php -r 'echo PHP_VERSION;') ($(command -v php))"
        php_extensions_ok && echo "  Estensioni: pdo_mysql, mbstring, openssl, json, fileinfo OK" \
            || echo "  Estensioni: MANCANTI (verranno installate)"
        php_extra_extensions_ok || echo "  Extra:     mancano curl/zip/gd/intl/xml (OCR e PDF)"
        echo "  php.ini:   display_errors=$(php -r 'echo ini_get("display_errors") ?: "0";') log_errors=$(php -r 'echo ini_get("log_errors") ?: "0";')"
    else
        echo "  PHP:       assente (verrà installato)"
    fi
    if have composer; then
        echo "  Composer:  $(composer_version)"
    else
        echo "  Composer:  assente (verrà installato)"
    fi
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
    if [[ "$RESET_DB_PASS" -eq 1 ]]; then
        echo "             password dell'utente esistente RUOTATA (--reset-db-pass)"
    fi
    echo "  Utente app: $(app_user_candidate) (proprietario di .env e storage/)"
    if php_version_ok && php_extensions_ok; then
        echo "  PHP:       riuso $(php -r 'echo PHP_VERSION;')"
    else
        echo "  PHP:       installazione pacchetti 8.2+ con estensioni richieste"
    fi
    have composer && echo "  Composer:  riuso eseguibile di sistema" || echo "  Composer:  download verificato SHA-256 in /usr/local/bin/composer"
    if have mysql || have mariadb; then
        echo "  MariaDB:   riuso client esistente"
    else
        echo "  MariaDB:   installazione del client"
    fi
    if is_local_db && ! have mysqld && ! have mariadbd; then
        echo "  MariaDB:   installazione del server (nessuna istanza installata)"
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
# .env (chmod 600) and storage/ must belong to the user that will serve the app:
# root-owned files silently break a www-data/php-fpm deployment.
resolve_app_user() {
    local raw="${APP_USER_OVERRIDE:-${SUDO_USER:-$(id -un)}}"
    APP_USER="$(app_user_candidate)"
    if [[ "$raw" == "root" && "$APP_USER" != "root" ]]; then
        warn "Esecuzione come root ma storage/ appartiene a ${APP_USER}: uso --user=${APP_USER}"
        echo "  per non rompere l'istanza esistente (forzalo con --user=root se è voluto)." >&2
    fi
    id -u "$APP_USER" >/dev/null 2>&1 \
        || die "Utente '${APP_USER}' inesistente: correggi --user=<utente> e rilancia."
    if [[ "$APP_USER" == "root" ]]; then
        warn "Esecuzione come root: .env (chmod 600), storage/ e il server locale saranno di root."
        echo "  Se l'app sarà servita da www-data/php-fpm rilancia con --user=www-data" >&2
        echo "  (oppure SUDO_USER=www-data bash bin/install/linux.sh ...)." >&2
    fi
}
resolve_app_user
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

mysql_client_ok() {
    have mysql || have mariadb
}

# The local DB server is reachable or at least installed: a shared/corporate
# host (Plesk, cPanel, a pre-existing MariaDB) must never get a second one.
mysql_server_ok() {
    db_ping "$DB_HOST" "$DB_PORT" && return 0
    have mysqld || have mariadbd || return 1
    return 0
}

apt_prepare() {
    if [[ "$APT_UPDATED" -eq 0 ]]; then
        apt-get update || warn "apt-get update ha segnalato errori: proseguo con gli indici esistenti."
        APT_UPDATED=1
    fi
}

# Install only when the argument list is not empty, refreshing the indexes once.
apt_install() {
    [[ $# -gt 0 ]] || return 0
    apt_prepare
    apt-get install -y "$@"
}

# PHP is installed only when it is missing or incomplete: on a host that already
# provides a full PHP stack (Plesk, cPanel, distro packages) adding php8.x-*
# would create a second, unused stack.
install_php_apt() {
    local v ver pkg installable=()
    if php_version_ok && php_extensions_ok; then
        ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
        for pkg in curl zip gd intl xml; do
            php -r "exit(extension_loaded('${pkg}') ? 0 : 1);" >/dev/null 2>&1 && continue
            # Only a package that really exists for this PHP version is safe to add.
            apt-cache show "php${ver}-${pkg}" >/dev/null 2>&1 && installable+=("php${ver}-${pkg}")
        done
        if [[ ${#installable[@]} -eq 0 ]]; then
            ok "PHP $(php -r 'echo PHP_VERSION;') con le estensioni richieste: nessun pacchetto PHP aggiunto"
            return 0
        fi
        apt_install "${installable[@]}" || warn "Estensioni PHP opzionali non installate: ${installable[*]}"
        return 0
    fi

    local installed=0
    for v in 8.4 8.3 8.2; do
        apt-cache show "php${v}-cli" >/dev/null 2>&1 || continue
        if apt_install "php${v}-cli" "php${v}-mysql" "php${v}-mbstring" "php${v}-xml" \
            "php${v}-curl" "php${v}-zip" "php${v}-gd" "php${v}-intl"; then
            installed=1
            break
        fi
    done
    if [[ "$installed" -eq 0 ]]; then
        warn "PHP 8.2+ non disponibile nei repository: provo il repository ondrej/php"
        apt_install software-properties-common gnupg
        add-apt-repository -y ppa:ondrej/php || true
        APT_UPDATED=0
        apt_prepare
        for v in 8.4 8.3 8.2; do
            apt-cache show "php${v}-cli" >/dev/null 2>&1 || continue
            if apt_install "php${v}-cli" "php${v}-mysql" "php${v}-mbstring" "php${v}-xml" \
                "php${v}-curl" "php${v}-zip" "php${v}-gd" "php${v}-intl"; then
                installed=1
                break
            fi
        done
    fi
    [[ "$installed" -eq 1 ]] || die "PHP 8.2+ non installabile automaticamente: installalo e riprova."
}

install_packages() {
    if have apt-get; then
        ok "Pacchetti Debian/Ubuntu"
        local base=()
        have curl || base+=(curl)
        have unzip || base+=(unzip)
        [[ -f /etc/ssl/certs/ca-certificates.crt ]] || base+=(ca-certificates)
        apt_install "${base[@]}"
        if ! mysql_client_ok; then
            apt_install mariadb-client || apt_install default-mysql-client \
                || warn "Client MySQL/MariaDB non installato: installalo e riprova."
        fi
        if is_local_db && ! mysql_server_ok; then
            apt_install mariadb-server \
                || die "MariaDB non installabile e nessun server raggiungibile su ${DB_HOST}:${DB_PORT}."
        fi
        install_php_apt
        have composer || apt_install composer || install_composer_official
        if [[ "$WITH_OCR" -eq 1 ]] && { ! have pdftoppm || ! have tesseract; }; then
            apt_install poppler-utils tesseract-ocr tesseract-ocr-ita \
                || warn "OCR non installato (opzionale): puoi aggiungerlo dopo"
        fi
    elif have dnf; then
        ok "Pacchetti Fedora/RHEL"
        have curl || dnf install -y curl
        have unzip || dnf install -y unzip
        mysql_client_ok || dnf install -y mariadb
        if is_local_db && ! mysql_server_ok; then
            dnf install -y mariadb-server
        fi
        if ! php_version_ok || ! php_extensions_ok; then
            dnf install -y php-cli php-mysqlnd php-mbstring php-openssl php-curl php-zip php-gd php-intl
        fi
        have composer || dnf install -y composer || install_composer_official
        if [[ "$WITH_OCR" -eq 1 ]] && { ! have pdftoppm || ! have tesseract; }; then
            dnf install -y poppler-utils tesseract tesseract-langpack-ita \
                || warn "OCR non installato (opzionale): puoi aggiungerlo dopo"
        fi
    elif have pacman; then
        ok "Pacchetti Arch"
        if ! php_version_ok || ! php_extensions_ok; then
            pacman -Sy --noconfirm --needed php php-gd
        fi
        have composer || pacman -S --noconfirm --needed composer
        if ! mysql_client_ok; then
            pacman -S --noconfirm --needed mariadb-clients
        fi
        if is_local_db && ! mysql_server_ok; then
            pacman -S --noconfirm --needed mariadb
        fi
        have curl || pacman -S --noconfirm --needed curl
        have unzip || pacman -S --noconfirm --needed unzip
        if [[ "$WITH_OCR" -eq 1 ]] && { ! have pdftoppm || ! have tesseract; }; then
            pacman -S --noconfirm --needed poppler tesseract tesseract-data-ita \
                || warn "OCR non installato (opzionale): puoi aggiungerlo dopo"
        fi
    elif have zypper; then
        ok "Pacchetti openSUSE"
        if ! php_version_ok || ! php_extensions_ok; then
            zypper --non-interactive install php8 php8-mysql php8-mbstring php8-openssl \
                php8-curl php8-zip php8-gd php8-intl
        fi
        mysql_client_ok || zypper --non-interactive install mariadb-client
        if is_local_db && ! mysql_server_ok; then
            zypper --non-interactive install mariadb
        fi
        have curl || zypper --non-interactive install curl
        have unzip || zypper --non-interactive install unzip
        if [[ "$WITH_OCR" -eq 1 ]] && { ! have pdftoppm || ! have tesseract; }; then
            zypper --non-interactive install poppler-tools tesseract-ocr tesseract-ocr-ita \
                || warn "OCR non installato (opzionale): puoi aggiungerlo dopo"
        fi
    elif have apk; then
        ok "Pacchetti Alpine"
        apk update >/dev/null 2>&1 || true
        # Alpine ships PHP per version and not every branch has the newest one.
        # json/session/fileinfo are not separate packages on Alpine.
        if ! php_version_ok || ! php_extensions_ok; then
            local apk_php=''
            for v in 84 83 82; do
                if apk info -e "php${v}" >/dev/null 2>&1; then apk_php="$v"; break; fi
            done
            [[ -n "$apk_php" ]] || die "Nessun pacchetto PHP 8.2+ nei repository Alpine: aggiorna la distro e riprova."
            apk add --no-cache "php${apk_php}" \
                "php${apk_php}-pdo_mysql" "php${apk_php}-mbstring" "php${apk_php}-openssl" \
                "php${apk_php}-curl" "php${apk_php}-zip" "php${apk_php}-gd" "php${apk_php}-intl" \
                "php${apk_php}-phar" "php${apk_php}-tokenizer" "php${apk_php}-xml" \
                "php${apk_php}-session" "php${apk_php}-fileinfo"
            ln -sf "/usr/bin/php${apk_php}" /usr/local/bin/php
        fi
        have composer || apk add --no-cache composer
        mysql_client_ok || apk add --no-cache mariadb-client
        if is_local_db && ! mysql_server_ok; then
            apk add --no-cache mariadb
        fi
        have curl || apk add --no-cache curl
        have unzip || apk add --no-cache unzip
        if [[ "$WITH_OCR" -eq 1 ]] && { ! have pdftoppm || ! have tesseract; }; then
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

# Does the account already exist on this server? 0 = yes, 1 = no, 2 = unknown.
db_user_exists() {
    local user_sql="$1" host="$2" out
    out="$(mysql_root_any -N -B -e "SELECT COUNT(*) FROM mysql.user WHERE user = '${user_sql}' AND host = '${host}';" 2>/dev/null)" || return 2
    [[ "$out" =~ ^[0-9]+$ ]] || return 2
    [[ "$out" -gt 0 ]] && return 0
    return 1
}

# Can the application account already log in with the password we would use?
db_user_auth_ok() {
    local user="$1" pass="$2"
    MYSQL_PWD="$pass" mysql -u "$user" -e 'SELECT 1' >/dev/null 2>&1 && return 0
    MYSQL_PWD="$pass" mysql -u "$user" -h 127.0.0.1 -P "$DB_PORT" --protocol=TCP -e 'SELECT 1' >/dev/null 2>&1
}

# Never rotate the password of an account this run did not create: another Socly
# instance (or another application) may be using it. Rotation is opt-in
# (--reset-db-pass) and an unknown state is always treated as "leave it alone".
build_database_sql() {
    local pass_literal="$1" reset="$2" account="$3" host
    printf 'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' "$DB_NAME"
    for host in localhost 127.0.0.1; do
        if db_user_exists "$account" "$host"; then
            if [[ "$reset" == "1" ]]; then
                printf "ALTER USER '%s'@'%s' IDENTIFIED BY '%s';\n" "$account" "$host" "$pass_literal"
            fi
        else
            # 2 (unknown state) also lands here: CREATE IF NOT EXISTS never
            # changes the password of an account that already exists.
            printf "CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY '%s';\n" "$account" "$host" "$pass_literal"
        fi
        printf 'GRANT ALL PRIVILEGES ON `%s`.* TO %s;\n' "$DB_NAME" "'${account}'@'${host}'"
    done
    printf 'FLUSH PRIVILEGES;\n'
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

    local db_pass_sql user_sql sql_exec sql_redacted host state
    db_pass_sql="$(sql_escape "$DB_PASS")"
    user_sql="$(sql_escape "$DB_USER")"

    # Refuse to take over an account that already exists with a different
    # password: it may belong to another Socly instance on the same server.
    for host in localhost 127.0.0.1; do
        if db_user_exists "$user_sql" "$host"; then
            state=0
        else
            state=$?
        fi
        if [[ "$state" -eq 2 ]]; then
            warn "Stato dell'utente '${DB_USER}'@'${host}' non determinabile: la password esistente non viene toccata."
            continue
        fi
        if [[ "$state" -ne 0 || "$RESET_DB_PASS" -eq 1 ]]; then
            continue
        fi
        if ! db_user_auth_ok "$DB_USER" "$DB_PASS"; then
            cat >&2 <<MSG
✖ L'utente MySQL '${DB_USER}'@'${host}' esiste già con un'altra password.
  Non la sovrascrivo per non interrompere un'istanza che la sta usando. Scegli:
    --db-user=<altro nome>            usa un account nuovo (consigliato su server condivisi)
    --db-pass=<password attuale>      riusa l'account esistente
    --reset-db-pass                   ruota la password (rompe le altre istanze che la usano)
  Per vedere gli account già presenti:
    mysql -e "select user,host from mysql.user where user like '${DB_USER}%'"
MSG
            exit 3
        fi
    done

    sql_exec="$(build_database_sql "$db_pass_sql" "$RESET_DB_PASS" "$user_sql")"
    sql_redacted="$(build_database_sql '***' "$RESET_DB_PASS" "$user_sql")"

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

AS_APP_WARNED=0
as_app() {
    if [[ "$APP_USER" == "root" ]]; then
        "$@"
        return
    fi
    if ! have sudo; then
        if [[ "$AS_APP_WARNED" -eq 0 ]]; then
            warn "sudo non disponibile: eseguo come root e riassegno .env e storage/ a ${APP_USER} alla fine."
            AS_APP_WARNED=1
        fi
        "$@"
        return
    fi
    # -H: give the child the target user's HOME, otherwise Composer tries
    # to write its cache into /root.
    sudo -H -u "$APP_USER" "$@"
}

echo "Socly — installazione one-click (Linux)"
install_packages

php_version_ok || die "PHP 8.2+ non disponibile dopo l'installazione."
php_extensions_ok || die "Estensioni PHP mancanti (pdo_mysql, mbstring, openssl, json, fileinfo)."
have composer || install_composer_official
have composer || die "Composer non disponibile."
# Fail before touching the database if this folder already hosts another instance.
guard_existing_env

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

STORAGE_PREEXISTING=0
STORAGE_OWNER=''
if [[ -d storage ]]; then
    STORAGE_PREEXISTING=1
    STORAGE_OWNER="$(stat -c '%U' storage 2>/dev/null || true)"
fi
mkdir -p storage/logs storage/cache storage/sessions storage/uploads storage/documents storage/runts
# Only take ownership of a fresh tree, of files we already own or of a root-owned
# leftover: a storage/ belonging to another user means another instance lives here.
if [[ "$STORAGE_PREEXISTING" -eq 1 && -n "$STORAGE_OWNER" && "$STORAGE_OWNER" != "$APP_USER" && "$STORAGE_OWNER" != "root" ]]; then
    warn "storage/ appartiene a ${STORAGE_OWNER}: non lo riassegno a ${APP_USER} (usa --user=${STORAGE_OWNER})."
else
    chown -R "$APP_USER":"$APP_USER" storage 2>/dev/null || true
fi
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
    as_app php -S "${BIND}:${LOCAL_PORT}" -t public public/router.php > storage/logs/install-server.log 2>&1 &
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

open_wizard_browser() {
    local url="$1" opener=''
    [[ "$OPEN_BROWSER" -eq 1 ]] || return 0
    if [[ -n "${SSH_CONNECTION:-}${SSH_TTY:-}" ]]; then
        echo "  Browser:  sessione SSH: apri il wizard dal tuo PC (tunnel qui sotto)." >&2
        return 0
    fi
    if [[ -z "${DISPLAY:-}" && -z "${WAYLAND_DISPLAY:-}" ]]; then
        echo "  Browser:  nessun ambiente grafico: apri ${url} manualmente." >&2
        return 0
    fi
    if have xdg-open; then
        opener='xdg-open'
    elif have sensible-browser; then
        opener='sensible-browser'
    fi
    if [[ -z "$opener" ]]; then
        echo "  Browser:  apri ${url} manualmente." >&2
        return 0
    fi
    # A headless/misconfigured opener must never hang the installer.
    if have timeout; then
        timeout 10 "$opener" "$url" >/dev/null 2>&1 &
    else
        "$opener" "$url" >/dev/null 2>&1 &
    fi
    echo "  Browser:  apro ${url} (se non compare, aprilo a mano)." >&2
}
open_wizard_browser "$LOCAL_URL"

case "$BIND" in
    127.*|::1|localhost)
        TUNNEL_HINT="  Tunnel:   ssh -L ${LOCAL_PORT}:127.0.0.1:${LOCAL_PORT} <utente>@<server>   (da un altro PC)"
        ;;
    *)
        TUNNEL_HINT="  Nota:     il wizard è raggiungibile dalla rete su ${BIND}:${LOCAL_PORT} (usa un firewall)"
        ;;
esac

cat <<TXT

✔ Socly è installato e il server locale è attivo.
  Wizard:   ${LOCAL_URL}
  Login:    http://${CONNECT_HOST}:${LOCAL_PORT}/login (account di manutenzione: admin@socly.it)
  Setup:    http://${CONNECT_HOST}:${LOCAL_PORT}/setup (dopo il wizard e il login)
  Log:      ${ROOT}/storage/logs/install-server.log
  Stop:     Ctrl+C
${TUNNEL_HINT}

Completa il wizard (associazione, periodo, quote, campi); poi accedi con admin@socly.it e completa /setup.
La password di manutenzione non viene stampata né salvata dallo script.
Il server locale vive in questa sessione: se chiudi SSH si ferma (--no-serve per un vhost).
Per la produzione (Apache/Nginx, HTTPS) vedi docs/INSTALLATION.md.
TXT

wait "$SERVER_PID"
