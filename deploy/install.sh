#!/bin/bash
# =====================================================================================================
#  Movexa — one-shot installer for Ubuntu 24.04+ (24.04 / 26.04) + aaPanel
#
#  The ONLY manual step before running it:
#     aaPanel → Website → Add site → domain "movexa.lan", PHP 8.5   (nothing else: no SSL, no DB, no rewrite)
#
#  Then, as root, from a checkout of this repository:
#     sudo bash deploy/install.sh                      (finds the site automatically)
#     sudo bash deploy/install.sh --site /www/wwwroot/movexa.lan --domain movexa.lan   (explicit)
#
#  Everything else is configured here: system packages (ffmpeg, ClamAV, …), PHP settings, the data
#  folders (noexec), the ffmpeg sandbox, a local CA + HTTPS certificate, nginx on port 8088 only, MariaDB
#  database, Redis, the app itself (.env, Composer, front-end build, migrations), background services,
#  cron, firewall (LAN/VPN only) and the first admin account. Safe to run again (idempotent).
#
#  Options (environment variables):
#     DOMAIN=movexa.lan PORT=8088 PHP_VER=85 DATA_DIR=/www/data/movexa
#     ADMIN_USER=admin ADMIN_PASS=…            (default: generated and printed once)
#     LAN_CIDRS="192.168.0.0/16 10.0.0.0/8 172.16.0.0/12 100.64.0.0/10"   (who may reach :8088)
#     TRANSCODE_WORKERS=2  MYSQL_ROOT_PASSWORD=… (only if it can't be read from aaPanel)
# =====================================================================================================
set -Eeuo pipefail

# Log everything to /var/log/movexa-install.log. Re-exec through a pipe (not `exec > >(tee)`): this guarantees
# every message — including the final error — is printed before the prompt comes back.
LOG=/var/log/movexa-install.log
if [ -z "${MOVEXA_LOGGED:-}" ]; then
    mkdir -p "$(dirname "$LOG")" 2>/dev/null || true
    MOVEXA_LOGGED=1 bash "$(readlink -f "$0")" "$@" 2>&1 | tee -a "$LOG"
    exit "${PIPESTATUS[0]}"
fi

usage() {
    cat <<'U'
Usage: sudo bash deploy/install.sh [--site /www/wwwroot/NAME] [--domain movexa.lan] [--port 8088] [--php 85]
  --site    folder of the website you added in aaPanel (auto-detected if omitted)
  --domain  local domain name (default: the site's name, or movexa.lan)
U
}
while [ $# -gt 0 ]; do
    case "$1" in
        --site) SITE_DIR=${2%/}; shift 2 ;;
        --domain) DOMAIN=$2; shift 2 ;;
        --port) PORT=$2; shift 2 ;;
        --php) PHP_VER=${2/./}; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "unknown option: $1"; usage; exit 2 ;;
    esac
done

PORT=${PORT:-8088}
PHP_VER=${PHP_VER:-85}
DATA_DIR=${DATA_DIR:-/www/data/movexa}
ADMIN_USER=${ADMIN_USER:-admin}
LAN_CIDRS=${LAN_CIDRS:-"192.168.0.0/16 10.0.0.0/8 172.16.0.0/12 100.64.0.0/10"}
TRANSCODE_WORKERS=${TRANSCODE_WORKERS:-2}
PANEL=${PANEL:-/www/server/panel}
WWWROOT=${WWWROOT:-/www/wwwroot}
PHP_DIR=${PHP_DIR:-/www/server/php/$PHP_VER}
NGINX_BIN=${NGINX_BIN:-/www/server/nginx/sbin/nginx}
CA_DIR=${CA_DIR:-/root/movexa-ca}
SRC=${SRC:-$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)}
# Test mode (tests/install/run.sh): skips apt, systemd, mounts, firewall and ClamAV — things a container can't do.
TEST_MODE=${MOVEXA_INSTALL_TEST:-0}
SUMMARY=/root/movexa-install-summary.txt
PHP="$PHP_DIR/bin/php"

c_ok() { printf '\e[32m✓\e[0m %s\n' "$*"; }
c_step() { printf '\n\e[1;34m▶ %s\e[0m\n' "$*"; }
c_warn() { printf '\e[33m! %s\e[0m\n' "$*"; WARNINGS+=("$*"); }
die() { printf '\e[31m✗ %s\e[0m\n' "$*"; exit 1; }
WARNINGS=()
trap 'die "failed at line $LINENO: $BASH_COMMAND (see $LOG)"' ERR
rand() { local s; s=$(openssl rand -base64 96 | tr -dc 'A-Za-z0-9'); echo "${s:0:${1:-24}}"; }  # no SIGPIPE under pipefail
have_systemd() { [ "$TEST_MODE" != 1 ] && [ -d /run/systemd/system ]; }

# Lists the websites aaPanel knows as "name|path" lines: its site database first, then the nginx vhosts, then /www/wwwroot.
list_sites() ( # subshell: a non-matching grep here must never trip the error trap
    set +eE +o pipefail; trap - ERR
    {
        for db in "$PANEL/data/db/site.db" "$PANEL/data/default.db"; do
            [ -f "$db" ] && python3 - "$db" <<'PYDB' 2>/dev/null
import sqlite3, sys
try:
    for name, path in sqlite3.connect(sys.argv[1]).execute("select name, path from sites"):
        print(f"{name}|{path}")
except Exception:
    pass
PYDB
        done
        for f in "$PANEL"/vhost/nginx/*.conf; do
            [ -f "$f" ] || continue
            root=$(grep -oE '(^|[[:space:];{])root[[:space:]]+[^;[:space:]]+' "$f" | head -1 | awk '{print $NF}')
            [ -n "$root" ] && echo "$(basename "$f" .conf)|${root%/public}"
        done
        for d in "$WWWROOT"/*/; do
            [ -d "$d" ] && [ "$(basename "$d")" != default ] && echo "$(basename "$d")|${d%/}"
        done
    } | awk -F'|' '$2 != "" && !seen[$2]++'
)

# -----------------------------------------------------------------------------------------------------
c_step "1/16 Checks"
[ "$(id -u)" = 0 ] || die "run as root: sudo bash deploy/install.sh"
. /etc/os-release 2>/dev/null || true
if [ "${ID:-}" != ubuntu ] || [ "$(printf '%s\n24.04\n' "${VERSION_ID:-0}" | sort -V | head -1)" != "24.04" ]; then
    c_warn "made for Ubuntu 24.04 or newer — this system is ${PRETTY_NAME:-unknown}"
elif [ "${VERSION_ID:-}" != "24.04" ]; then
    c_ok "${PRETTY_NAME} (newer than the tested 24.04 — fine)"
fi
[ -d "$PANEL" ] || die "aaPanel not found ($PANEL). Install aaPanel first."

# Find the website that was added in aaPanel.
if [ -z "${SITE_DIR:-}" ] || [ ! -d "$SITE_DIR" ]; then
    [ -n "${SITE_DIR:-}" ] && c_warn "folder $SITE_DIR does not exist — looking for the site in aaPanel"
    mapfile -t SITES < <(list_sites)
    want=${DOMAIN:-movexa.lan}
    pick=""
    for row in "${SITES[@]}"; do  # exact name / folder name
        n=${row%%|*}; d=${row#*|}
        if [ "${n,,}" = "${want,,}" ] || [ "$(basename "$d")" = "$want" ]; then pick=$row; break; fi
    done
    if [ -z "$pick" ]; then        # anything called *movexa*
        for row in "${SITES[@]}"; do [[ "${row,,}" == *movexa* ]] && { pick=$row; break; }; done
    fi
    if [ -z "$pick" ] && [ ${#SITES[@]} = 1 ]; then pick=${SITES[0]}; fi
    if [ -z "$pick" ] && [ ${#SITES[@]} -gt 1 ] && [ -r /dev/tty ]; then
        echo "Websites found in aaPanel:"
        i=1; for row in "${SITES[@]}"; do printf '  %d) %s   (%s)\n' $i "${row%%|*}" "${row#*|}"; i=$((i+1)); done
        read -r -p "Which one is Movexa? [1-${#SITES[@]}] " n </dev/tty
        [[ "$n" =~ ^[0-9]+$ ]] && [ "$n" -ge 1 ] && [ "$n" -le ${#SITES[@]} ] && pick=${SITES[$((n-1))]}
    fi
    if [ -z "$pick" ]; then
        echo "Websites found: ${SITES[*]:-none}"
        echo "Contents of $WWWROOT: $(ls -1 "$WWWROOT" 2>/dev/null | tr '\n' ' ')"
        die "no website found for Movexa — add it in aaPanel (Website → Add site → movexa.lan, PHP ${PHP_VER:0:1}.${PHP_VER:1}), or run: sudo bash deploy/install.sh --site $WWWROOT/<name>"
    fi
    SITE_NAME=${pick%%|*}; SITE_DIR=${pick#*|}
    [ -d "$SITE_DIR" ] || die "aaPanel lists site $SITE_NAME at $SITE_DIR, but that folder does not exist"
fi
SITE_NAME=${SITE_NAME:-$(basename "$SITE_DIR")}
# Domain: --domain, else the site's name if it is a hostname, else movexa.lan
if [ -z "${DOMAIN:-}" ]; then
    if [[ "$SITE_NAME" == *.* ]] && ! [[ "$SITE_NAME" =~ ^[0-9.]+$ ]]; then DOMAIN=${SITE_NAME%%:*}; else DOMAIN=movexa.lan; fi
fi
VHOST=${VHOST:-$PANEL/vhost/nginx/$SITE_NAME.conf}
if [ ! -f "$VHOST" ]; then
    VHOST=$(grep -l -E "root[[:space:]]+$SITE_DIR(/public)?;" "$PANEL"/vhost/nginx/*.conf 2>/dev/null | head -1 || true)
fi
[ -n "$VHOST" ] && [ -f "$VHOST" ] || die "nginx config of the site not found in $PANEL/vhost/nginx/ — was the site created with nginx (not Apache/OpenLiteSpeed)?"
CERT_DIR=${CERT_DIR:-$PANEL/vhost/cert/$SITE_NAME}
c_ok "website: $SITE_NAME → $SITE_DIR (nginx: $VHOST), domain: $DOMAIN"

if [ ! -x "$PHP" ]; then
    c_warn "PHP ${PHP_VER:0:1}.${PHP_VER:1} not found in aaPanel — trying the aaPanel installer"
    [ -f "$PANEL/install/install_soft.sh" ] && bash "$PANEL/install/install_soft.sh" 0 install php "${PHP_VER:0:1}.${PHP_VER:1}" || true
    [ -x "$PHP" ] || die "install PHP ${PHP_VER:0:1}.${PHP_VER:1} from aaPanel → App Store, then run this script again"
fi
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || die "PHP >= 8.3 required"
c_ok "aaPanel, PHP $("$PHP" -r 'echo PHP_VERSION;')"
if ss -ltnH "sport = :$PORT" 2>/dev/null | grep -q . && ! grep -q "listen $PORT" "$VHOST"; then
    die "port $PORT is already used by another service: $(ss -ltnpH "sport = :$PORT" | head -1)"
fi
c_ok "port $PORT available"

# -----------------------------------------------------------------------------------------------------
c_step "2/16 System packages (apt)"
if [ "$TEST_MODE" != 1 ]; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -q || c_warn "apt-get update reported errors (continuing)"
    REQUIRED="ffmpeg curl rsync openssl python3 git unzip ca-certificates sudo"
    OPTIONAL="clamav clamav-daemon clamav-freshclam inotify-tools bubblewrap acl sqlite3 pciutils"
    # fast path: everything at once; if a package name doesn't exist on this release, fall back to one by one
    if ! apt-get install -y -q $REQUIRED $OPTIONAL; then
        for p in $REQUIRED; do apt-get install -y -q "$p" || die "required package $p could not be installed"; done
        for p in $OPTIONAL; do apt-get install -y -q "$p" || c_warn "optional package $p not available on this Ubuntu release"; done
    fi
    # Hardware video acceleration drivers (if a GPU is present) — optional
    if lspci 2>/dev/null | grep -qi 'vga.*intel'; then apt-get install -y -q intel-media-va-driver-non-free vainfo || apt-get install -y -q intel-media-va-driver vainfo || c_warn "Intel VAAPI driver not installed (CPU transcoding still works)"; fi
    if lspci 2>/dev/null | grep -qi 'vga.*\(amd\|ati\)'; then apt-get install -y -q mesa-va-drivers vainfo || c_warn "AMD VAAPI driver not installed"; fi
    if lspci 2>/dev/null | grep -qi 'nvidia'; then command -v nvidia-smi >/dev/null || c_warn "NVIDIA GPU found: install the driver (ubuntu-drivers install) and reboot to enable NVENC"; fi
fi
for b in ffmpeg ffprobe openssl rsync curl python3; do command -v $b >/dev/null || die "missing $b"; done
c_ok "ffmpeg $(ffmpeg -version | head -1 | awk '{print $3}'), tools"

# -----------------------------------------------------------------------------------------------------
c_step "3/16 PHP ${PHP_VER:0:1}.${PHP_VER:1} settings"
missing=()
for ext in redis fileinfo intl exif gd pcntl sodium pdo_mysql zip curl mbstring openssl posix; do
    "$PHP" -m 2>/dev/null | grep -qix "$ext" || missing+=("$ext")
done
for ext in "${missing[@]}"; do
    # aaPanel's own extension installer (same as App Store → PHP → Install extensions)
    if [ -f "$PANEL/install/$ext.sh" ]; then bash "$PANEL/install/$ext.sh" install "$PHP_VER" || true;
    elif [ -f "$PANEL/install/install_soft.sh" ]; then bash "$PANEL/install/install_soft.sh" 1 install "$ext" "$PHP_VER" || true; fi
done
still=(); for ext in "${missing[@]}"; do "$PHP" -m | grep -qix "$ext" || still+=("$ext"); done
[ ${#still[@]} = 0 ] || die "PHP extensions missing: ${still[*]} — aaPanel → App Store → PHP ${PHP_VER:0:1}.${PHP_VER:1} → Install extensions"
# Only the functions Movexa needs are re-enabled (documented in docs/aapanel-setup.md):
#   proc_open/proc_get_status → ffmpeg & Composer · putenv → Laravel · pcntl_signal/pcntl_alarm → queue workers
#   shell_exec/posix_kill → live-TV ffmpeg processes
NEEDED="putenv proc_open proc_get_status pcntl_signal pcntl_alarm pcntl_async_signals pcntl_signal_dispatch shell_exec posix_kill"
for ini in "$PHP_DIR/etc/php.ini" "$PHP_DIR/etc/php-cli.ini"; do
    [ -f "$ini" ] || continue
    [ -f "$ini.movexa.bak" ] || cp "$ini" "$ini.movexa.bak"
    line=$(grep -E '^disable_functions' "$ini" | head -1 | cut -d= -f2- | tr -d ' ' || true)
    keep=$(echo "$line" | tr ',' '\n' | grep -v -x -F -f <(echo "$NEEDED" | tr ' ' '\n') | grep -v '^$' | paste -sd, - || true)
    sed -i "s/^disable_functions.*/disable_functions = $keep/" "$ini"
    for kv in "upload_max_filesize = 16M" "post_max_size = 16M" "max_execution_time = 120" "memory_limit = 512M"; do
        k=${kv%% =*}; grep -qE "^$k\s*=" "$ini" && sed -i "s/^$k\s*=.*/$kv/" "$ini" || echo "$kv" >> "$ini"
    done
done
c_ok "disabled_functions trimmed (only: $NEEDED), upload chunk size 16M"

# -----------------------------------------------------------------------------------------------------
c_step "4/16 Data folders (outside the site), noexec"
id www >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin www
mkdir -p "$DATA_DIR"/{inbox/drop/movies,inbox/drop/shows,library,quarantine,cache,images,uploads} /www/backup/movexa
chown -R www:www "$DATA_DIR"; chmod 2775 "$DATA_DIR" "$DATA_DIR"/*; chmod 2770 "$DATA_DIR/quarantine"
if [ "$TEST_MODE" != 1 ]; then
    # Bind-mount the data folder onto itself with noexec,nosuid,nodev (works on the same disk; a separate disk is better).
    if ! findmnt -no OPTIONS "$DATA_DIR" 2>/dev/null | grep -q noexec; then
        grep -q " $DATA_DIR none bind,noexec" /etc/fstab || echo "$DATA_DIR $DATA_DIR none bind,noexec,nosuid,nodev 0 0" >> /etc/fstab
        mountpoint -q "$DATA_DIR" || mount --bind "$DATA_DIR" "$DATA_DIR"
        mount -o remount,bind,noexec,nosuid,nodev "$DATA_DIR"
    fi
    findmnt -no OPTIONS "$DATA_DIR" | grep -q noexec && c_ok "$DATA_DIR mounted noexec,nosuid,nodev" || c_warn "could not mount $DATA_DIR noexec"
fi
c_ok "$DATA_DIR ready"

# -----------------------------------------------------------------------------------------------------
c_step "5/16 ffmpeg sandbox (systemd-run: no network, read-only system, limits)"
id movexa-sandbox >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin -G www movexa-sandbox
install -m 0755 -o root -g root "$SRC/deploy/scripts/movexa-sandbox" /usr/local/sbin/movexa-sandbox
sed -i "s#^DATA_ROOT=.*#DATA_ROOT=\"\${MOVEXA_DATA_ROOT:-$DATA_DIR}\"#" /usr/local/sbin/movexa-sandbox
echo 'www ALL=(root) NOPASSWD: /usr/local/sbin/movexa-sandbox' > /etc/sudoers.d/movexa-sandbox; chmod 440 /etc/sudoers.d/movexa-sandbox
for g in render video; do getent group $g >/dev/null && usermod -aG $g www && usermod -aG $g movexa-sandbox; done
SANDBOX=systemd; have_systemd || SANDBOX=unshare
c_ok "sandbox driver: $SANDBOX"

# -----------------------------------------------------------------------------------------------------
c_step "6/16 ClamAV"
if [ "$TEST_MODE" != 1 ]; then
    systemctl enable --now clamav-freshclam clamav-daemon >/dev/null 2>&1 || true
    for i in $(seq 1 60); do [ -S /var/run/clamav/clamd.ctl ] && break; sleep 5; done
    [ -S /var/run/clamav/clamd.ctl ] && c_ok "clamd running, signatures auto-update (freshclam)" || c_warn "clamd not ready yet (first signature download can take minutes) — uploads wait in quarantine until it is"
fi

# -----------------------------------------------------------------------------------------------------
c_step "7/16 Local CA + HTTPS certificate for $DOMAIN"
mkdir -p "$CA_DIR" "$CERT_DIR"; chmod 700 "$CA_DIR"
# Local certificate authority (10 years). Kept in $CA_DIR — never served; only rootCA.pem is copied for devices.
if [ ! -f "$CA_DIR/rootCA.pem" ]; then
    openssl req -x509 -newkey rsa:3072 -nodes -sha256 -days 3650 -subj "/O=Movexa/CN=Movexa Local CA ($(hostname -s))" \
        -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
        -keyout "$CA_DIR/rootCA-key.pem" -out "$CA_DIR/rootCA.pem" 2>/dev/null
    chmod 600 "$CA_DIR/rootCA-key.pem"
fi
# Server certificate: 825 days max (iPhone/macOS limit), SAN = domain + this server's LAN address.
if [ ! -f "$CERT_DIR/fullchain.pem" ] || ! openssl x509 -in "$CERT_DIR/fullchain.pem" -noout -checkend 2592000 >/dev/null 2>&1 \
   || ! openssl x509 -in "$CERT_DIR/fullchain.pem" -noout -text 2>/dev/null | grep -q "DNS:$DOMAIN"; then
    LANIP=$(hostname -I 2>/dev/null | awk '{print $1}')
    SAN="DNS:$DOMAIN,DNS:localhost,IP:127.0.0.1${LANIP:+,IP:$LANIP}"
    printf 'basicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=%s\n' "$SAN" > "$CA_DIR/leaf.ext"
    openssl req -newkey rsa:2048 -nodes -sha256 -subj "/O=Movexa/CN=$DOMAIN" -keyout "$CERT_DIR/privkey.pem" -out "$CA_DIR/leaf.csr" 2>/dev/null
    openssl x509 -req -sha256 -days 825 -in "$CA_DIR/leaf.csr" -CA "$CA_DIR/rootCA.pem" -CAkey "$CA_DIR/rootCA-key.pem" -CAcreateserial \
        -extfile "$CA_DIR/leaf.ext" -out "$CERT_DIR/fullchain.pem" 2>/dev/null
    chmod 600 "$CERT_DIR/privkey.pem"
fi
openssl verify -CAfile "$CA_DIR/rootCA.pem" "$CERT_DIR/fullchain.pem" >/dev/null || die "generated certificate does not verify"
# PHP (Reverb publishing via https://$DOMAIN:$PORT) and curl must trust the local CA:
cp "$CA_DIR/rootCA.pem" /usr/local/share/ca-certificates/movexa-local-ca.crt
update-ca-certificates >/dev/null 2>&1 || true
cp "$CA_DIR/rootCA.pem" /root/movexa-rootCA.crt
c_ok "certificate in $CERT_DIR (install /root/movexa-rootCA.crt on every device)"

# -----------------------------------------------------------------------------------------------------
c_step "8/16 Name resolution on the server"
grep -qE "\s$DOMAIN(\s|$)" /etc/hosts || echo "127.0.0.1 $DOMAIN" >> /etc/hosts
c_ok "$DOMAIN → 127.0.0.1 on this server (devices: add $DOMAIN in your router / Pi-hole / AdGuard DNS)"

# -----------------------------------------------------------------------------------------------------
c_step "9/16 Database (MariaDB)"
DB_NAME=${DB_NAME:-movexa}; DB_USER=${DB_USER:-movexa}
ENV_FILE="$SITE_DIR/.env"
if [ -f "$ENV_FILE" ] && grep -q '^DB_PASSWORD=.\+' "$ENV_FILE"; then
    DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
else
    DB_PASS=$(rand 28)
fi
MYSQL_ADMIN_USER=${MYSQL_ADMIN_USER:-root}
# Client: aaPanel's own first, then the system one.
MYSQL=${MYSQL:-}
for c in /www/server/mysql/bin/mysql "$(command -v mariadb || true)" "$(command -v mysql || true)"; do
    [ -z "$MYSQL" ] && [ -n "$c" ] && [ -x "$c" ] && MYSQL=$c
done
# Server running? aaPanel uses /tmp/mysql.sock; Ubuntu packages use /run/mysqld/mysqld.sock.
db_up() { for so in /tmp/mysql.sock /run/mysqld/mysqld.sock /var/run/mysqld/mysqld.sock; do [ -S "$so" ] && return 0; done; ss -ltnH 'sport = :3306' 2>/dev/null | grep -q .; }
if ! db_up || [ -z "$MYSQL" ]; then
    if [ "$TEST_MODE" != 1 ]; then
        c_warn "no MySQL/MariaDB found in aaPanel — installing MariaDB with apt (you can also install it from aaPanel → App Store)"
        apt-get install -y -q mariadb-server mariadb-client && systemctl enable --now mariadb
        MYSQL=$(command -v mariadb || command -v mysql)
    fi
    db_up || die "no MySQL/MariaDB server is running — install MySQL or MariaDB from aaPanel → App Store, then run this script again"
fi
# Root password: env → any aaPanel database (old data/default.db or newer data/db/*.db) → socket auth → ask.
panel_root_pw() {
    python3 - "$PANEL" <<'PYPW' 2>/dev/null || true
import glob, sqlite3, sys
p = sys.argv[1]
for f in glob.glob(p + "/data/*.db") + glob.glob(p + "/data/db/*.db"):
    try:
        c = sqlite3.connect(f)
        for (t,) in c.execute("select name from sqlite_master where type='table'"):
            cols = [r[1] for r in c.execute(f'pragma table_info("{t}")')]
            if "mysql_root" in cols:
                for (v,) in c.execute(f'select mysql_root from "{t}"'):
                    if v:
                        print(v); sys.exit(0)
    except SystemExit:
        raise
    except Exception:
        pass
PYPW
}
MYSQL_ARGS=()
try_db() { # $1 = password ("" = none) → sets MYSQL_ARGS on success
    local pw=$1 target
    for target in "-S/tmp/mysql.sock" "-S/run/mysqld/mysqld.sock" "-S/var/run/mysqld/mysqld.sock" "-h127.0.0.1" ""; do
        case "$target" in -S*) [ -S "${target#-S}" ] || continue ;; esac
        if MYSQL_PWD="$pw" "$MYSQL" -u"$MYSQL_ADMIN_USER" $target -e "select 1" >/dev/null 2>&1; then
            MYSQL_ARGS=(-u"$MYSQL_ADMIN_USER"); [ -n "$target" ] && MYSQL_ARGS+=("$target"); DB_ROOT_PW=$pw; return 0
        fi
    done
    return 1
}
DB_ROOT_PW=""
{ [ -n "${MYSQL_ROOT_PASSWORD:-}" ] && try_db "$MYSQL_ROOT_PASSWORD"; } \
  || { pw=$(panel_root_pw); [ -n "$pw" ] && try_db "$pw"; } \
  || try_db "" \
  || {
      for attempt in 1 2 3; do
          [ -r /dev/tty ] || break
          printf 'MariaDB/MySQL root password (aaPanel → Databases → "Root password"): ' >/dev/tty
          read -r -s pw </dev/tty; echo >/dev/tty
          try_db "$pw" && break
          echo "  wrong password" >/dev/tty
      done
      [ ${#MYSQL_ARGS[@]} -gt 0 ]
  } || die "cannot connect to MariaDB as $MYSQL_ADMIN_USER — run again with: sudo MYSQL_ROOT_PASSWORD='…' bash deploy/install.sh (aaPanel → Databases → Root password)"
mysql_root() { MYSQL_PWD="$DB_ROOT_PW" "$MYSQL" "${MYSQL_ARGS[@]}" "$@"; }
c_ok "connected to the database server as $MYSQL_ADMIN_USER via ${MYSQL_ARGS[1]:-default socket}"
mysql_root -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
  CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
  ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
  GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1'; FLUSH PRIVILEGES;"
c_ok "database $DB_NAME / user $DB_USER (aaPanel → Databases → 'Get DB from server' shows it in the panel)"

# -----------------------------------------------------------------------------------------------------
c_step "10/16 Redis"
if ! (command -v redis-cli >/dev/null && redis-cli ping 2>/dev/null | grep -q PONG); then
    if [ "$TEST_MODE" != 1 ]; then apt-get install -y -q redis-server && systemctl enable --now redis-server; fi
fi
redis-cli ping 2>/dev/null | grep -q PONG && c_ok "redis running" || die "Redis is not running (aaPanel → App Store → Redis, or apt install redis-server)"

# -----------------------------------------------------------------------------------------------------
c_step "11/16 Application files → $SITE_DIR"
# aaPanel puts a .user.ini (immutable) and placeholder pages in new sites.
for f in "$SITE_DIR/.user.ini" "$SITE_DIR/public/.user.ini"; do [ -f "$f" ] && chattr -i "$f" 2>/dev/null || true; done
rm -f "$SITE_DIR/index.html" "$SITE_DIR/404.html" "$SITE_DIR/.htaccess"
if [ "$(realpath "$SRC")" != "$(realpath "$SITE_DIR")" ]; then
    rsync -a --delete --exclude .env --exclude '.user.ini' --exclude node_modules --exclude vendor --exclude 'storage/logs/*' \
        --exclude 'storage/framework/sessions/*' --exclude 'storage/framework/cache/*' --exclude 'storage/framework/views/*' --exclude 'public/build' \
        --exclude 'database/*.sqlite' "$SRC/" "$SITE_DIR/"
fi
# open_basedir: allow the data folder (never disable the protection)
OB="$SITE_DIR/:$DATA_DIR/:/www/data/cloudsync/:/tmp/:/usr/bin/:/usr/local/sbin/:/var/run/clamav/:/proc/"
for f in "$SITE_DIR/.user.ini" "$SITE_DIR/public/.user.ini"; do
    [ -f "$f" ] || continue
    if grep -q '^open_basedir' "$f"; then sed -i "s#^open_basedir.*#open_basedir=$OB#" "$f"; else echo "open_basedir=$OB" >> "$f"; fi
done  # re-locked (immutable) after the permission pass in step 12
c_ok "code synced, open_basedir allows $DATA_DIR"

# -----------------------------------------------------------------------------------------------------
c_step "12/16 Configuration (.env), Composer, front-end, migrations"
cd "$SITE_DIR"
if [ ! -f .env ]; then
    cp .env.example .env
    setv() { if grep -q "^$1=" .env; then sed -i "s#^$1=.*#$1=$2#" .env; else echo "$1=$2" >> .env; fi; }
    setv APP_URL "https://$DOMAIN:$PORT"
    setv DB_DATABASE "$DB_NAME"; setv DB_USERNAME "$DB_USER"; setv DB_PASSWORD "$DB_PASS"
    setv REVERB_APP_ID "movexa"; setv REVERB_APP_KEY "$(rand 20 | tr 'A-Z' 'a-z')"; setv REVERB_APP_SECRET "$(rand 32)"
    setv REVERB_HOST "$DOMAIN"; setv REVERB_PORT "$PORT"; setv REVERB_SCHEME https
    setv HOMECORE_OIDC_REDIRECT "https://$DOMAIN:$PORT/auth/homecore/callback"
    setv MOVEXA_DATA_ROOT "$DATA_DIR"; setv MOVEXA_SANDBOX "$SANDBOX"
    for d in inbox library quarantine cache images uploads; do setv "MOVEXA_${d^^}" "$DATA_DIR/$d"; done
    setv MOVEXA_MAX_TRANSCODES "$TRANSCODE_WORKERS"
    if [ "$TEST_MODE" = 1 ]; then setv MOVEXA_REQUIRE_SCANNER false; setv SESSION_DRIVER file; setv CACHE_STORE file; setv QUEUE_CONNECTION sync; fi
    chmod 640 .env
    NEW_ENV=1
    c_ok ".env created (secrets generated)"
else
    c_ok ".env kept (existing installation)"
fi
COMPOSER=${COMPOSER_BIN:-$(command -v composer || true)}
if [ -z "$COMPOSER" ]; then
    curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o /usr/local/bin/composer && chmod +x /usr/local/bin/composer
    COMPOSER=/usr/local/bin/composer
fi
COMPOSER_ALLOW_SUPERUSER=1 "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress
[ "${NEW_ENV:-0}" = 1 ] && "$PHP" artisan key:generate --force
if [ ! -f public/build/manifest.json ] || [ "${REBUILD_FRONTEND:-1}" = 1 ]; then
    NODE=$(command -v node || true)
    if [ -z "$NODE" ] || [ "$("$NODE" -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
        if [ ! -x /opt/node22/bin/node ]; then
            arch=$(uname -m | sed 's/x86_64/x64/;s/aarch64/arm64/')
            curl -fsSL "https://nodejs.org/dist/latest-v22.x/SHASUMS256.txt" -o /tmp/node.sums
            file=$(grep "linux-$arch.tar.xz" /tmp/node.sums | awk '{print $2}')
            curl -fsSL "https://nodejs.org/dist/latest-v22.x/$file" -o "/tmp/$file"
            (cd /tmp && grep " $file\$" node.sums | sha256sum -c -) >/dev/null
            mkdir -p /opt/node22 && tar -xJf "/tmp/$file" -C /opt/node22 --strip-components=1
        fi
        export PATH=/opt/node22/bin:$PATH
    fi
    npm ci --no-audit --no-fund --loglevel=error && npm run build >/dev/null
fi
"$PHP" artisan migrate --force
"$PHP" artisan optimize:clear >/dev/null && "$PHP" artisan optimize >/dev/null
for f in "$SITE_DIR/.user.ini" "$SITE_DIR/public/.user.ini"; do [ -f "$f" ] && chattr -i "$f" 2>/dev/null || true; done
chown -R www:www "$SITE_DIR"
find "$SITE_DIR" -path "$SITE_DIR/node_modules" -prune -o -type d -exec chmod 755 {} + -o -type f -exec chmod 644 {} +
chmod -R ug+rwX "$SITE_DIR/storage" "$SITE_DIR/bootstrap/cache"; chmod 640 "$SITE_DIR/.env"
chmod +x "$SITE_DIR/artisan" "$SITE_DIR"/deploy/*.sh "$SITE_DIR"/deploy/scripts/*
for f in "$SITE_DIR/.user.ini" "$SITE_DIR/public/.user.ini"; do  # aaPanel convention: root-owned + immutable
    [ -f "$f" ] && chown root:root "$f" && chmod 644 "$f" && { chattr +i "$f" 2>/dev/null || true; }
done
c_ok "app built and migrated (Laravel $("$PHP" artisan --version | awk '{print $3}'))"

# -----------------------------------------------------------------------------------------------------
c_step "13/16 nginx: https://$DOMAIN:$PORT only"
[ -f "$VHOST.movexa.bak" ] || cp "$VHOST" "$VHOST.movexa.bak"
sed -e "s#movexa.lan#$DOMAIN#g" -e "s#listen 8088#listen $PORT#g" -e "s#/www/wwwroot/$DOMAIN#$SITE_DIR#g" \
    -e "s#/www/data/movexa/#$DATA_DIR/#g" -e "s#enable-php-83.conf#enable-php-$PHP_VER.conf#g" \
    -e "s#/www/server/panel/vhost/cert/$DOMAIN#$CERT_DIR#g" "$SITE_DIR/deploy/nginx/movexa.lan.conf" > "$VHOST"
NGINX_VER=$("$NGINX_BIN" -v 2>&1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
if [ "$(printf '%s\n1.25.1\n' "$NGINX_VER" | sort -V | head -1)" = "1.25.1" ]; then  # >= 1.25.1: new http2 syntax
    sed -i "s#listen $PORT ssl http2;#listen $PORT ssl;\n    http2 on;#" "$VHOST"
fi
"$NGINX_BIN" -t 2>&1 | tail -2 || { cp "$VHOST.movexa.bak" "$VHOST"; die "nginx config test failed — original restored"; }
"$NGINX_BIN" -s reload 2>/dev/null || /etc/init.d/nginx reload || true
[ -x "/etc/init.d/php-fpm-$PHP_VER" ] && "/etc/init.d/php-fpm-$PHP_VER" reload >/dev/null 2>&1 || true
c_ok "nginx $NGINX_VER: $DOMAIN on :$PORT (HTTP on :$PORT → HTTPS), no 80/443 — original vhost saved as $VHOST.movexa.bak"

# -----------------------------------------------------------------------------------------------------
c_step "14/16 Background services (start on boot) + cron"
UNITS=/etc/systemd/system
unit() { # name, description, exec
cat > "$UNITS/$1.service" <<UNIT
[Unit]
Description=Movexa $2
After=network.target redis-server.service mysqld.service mariadb.service
[Service]
User=www
Group=www
WorkingDirectory=$SITE_DIR
ExecStart=$3
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
UNIT
}
unit movexa-queue "queue worker" "$PHP artisan queue:work redis --queue=default --sleep=3 --tries=3 --max-time=3600"
unit movexa-scan "scan & security worker" "$PHP artisan queue:work redis --queue=scan --timeout=21600 --tries=1"
unit movexa-reverb "realtime (Reverb, 127.0.0.1:8080)" "$PHP artisan reverb:start --host=127.0.0.1 --port=8080"
unit movexa-watch "library folder watcher (inotify)" "$PHP artisan movexa:watch"
unit movexa-live "live TV reaper" "$PHP artisan movexa:live-reaper --loop"
cat > "$UNITS/movexa-transcode@.service" <<UNIT
[Unit]
Description=Movexa transcode worker %i
After=network.target redis-server.service
[Service]
User=www
Group=www
WorkingDirectory=$SITE_DIR
ExecStart=$PHP artisan queue:work redis --queue=transcode --timeout=21600 --tries=1
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/cron.d/movexa <<CRON
# Movexa (installed by deploy/install.sh) — not shown in aaPanel's Cron page
* * * * * www cd $SITE_DIR && $PHP artisan schedule:run >> /dev/null 2>&1
20 3 * * * root APP=$SITE_DIR DATA=$DATA_DIR $SITE_DIR/deploy/scripts/backup.sh >> /var/log/movexa-backup.log 2>&1
CRON
chmod 644 /etc/cron.d/movexa
if have_systemd; then
    systemctl daemon-reload
    systemctl enable --now movexa-queue movexa-scan movexa-reverb movexa-watch movexa-live >/dev/null
    for i in $(seq 1 "$TRANSCODE_WORKERS"); do systemctl enable --now "movexa-transcode@$i" >/dev/null; done
    systemctl restart movexa-queue movexa-scan movexa-reverb movexa-watch movexa-live "movexa-transcode@*" || true
    c_ok "services enabled: queue, scan, transcode×$TRANSCODE_WORKERS, reverb, watch, live (systemd, auto-start on boot)"
else
    c_warn "no systemd (test/container): unit files written to $UNITS but not started"
fi

# -----------------------------------------------------------------------------------------------------
c_step "15/16 Firewall: :$PORT for LAN / VPN only"
if [ "$TEST_MODE" != 1 ] && command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
    for cidr in $LAN_CIDRS; do ufw allow from "$cidr" to any port "$PORT" proto tcp comment "Movexa LAN/VPN" >/dev/null; done
    c_ok "ufw: $PORT/tcp allowed only from $LAN_CIDRS (also visible in aaPanel → Security)"
else
    c_warn "firewall not changed: in aaPanel → Security add port $PORT/TCP limited to your LAN/VPN ranges ($LAN_CIDRS). Keep the aaPanel port LAN-only + security entrance + 2FA."
fi

# -----------------------------------------------------------------------------------------------------
c_step "16/16 First admin account + health check"
if [ -z "${ADMIN_PASS:-}" ] && "$PHP" artisan tinker --execute "echo App\Models\User::where('username', '$ADMIN_USER')->exists() ? 'yes' : 'no';" 2>/dev/null | grep -q yes; then
    c_ok "admin \"$ADMIN_USER\" already exists (password unchanged)"
    ADMIN_PASS="(unchanged)"
else
    ADMIN_PASS=${ADMIN_PASS:-$(rand 16)}
    sudo -u www "$PHP" artisan movexa:create-admin "$ADMIN_USER" --name="Admin" --password="$ADMIN_PASS" --emergency >/dev/null 2>&1 \
        || "$PHP" artisan movexa:create-admin "$ADMIN_USER" --name="Admin" --password="$ADMIN_PASS" --emergency >/dev/null
    c_ok "admin \"$ADMIN_USER\" created"
fi
HEALTH=$(curl -s --noproxy "$DOMAIN" --resolve "$DOMAIN:$PORT:127.0.0.1" --cacert "$CA_DIR/rootCA.pem" -m 15 "https://$DOMAIN:$PORT/api/health" || true)
echo "$HEALTH" | grep -q '"service":"movexa"' && c_ok "https://$DOMAIN:$PORT/api/health → $HEALTH" || c_warn "health check did not answer yet: ${HEALTH:-no response}"

umask 077
cat > "$SUMMARY" <<SUM
Movexa is installed — تم تثبيت Movexa
=====================================
URL:            https://$DOMAIN:$PORT
Admin:          $ADMIN_USER
Password:       $ADMIN_PASS
Root CA:        /root/movexa-rootCA.crt   ← install on every phone / PC / TV (trust it) — needed for HTTPS, PWA and downloads
Database:       $DB_NAME (user $DB_USER, password in $SITE_DIR/.env)
Data:           $DATA_DIR (noexec)
Logs:           $LOG, $SITE_DIR/storage/logs/
Services:       systemctl status 'movexa-*'
Update later:   sudo bash $SITE_DIR/deploy/deploy.sh
Backups:        daily 03:20 → /www/backup/movexa   (restore: deploy/scripts/restore.sh <dir>)

Next steps
 1. DNS: point $DOMAIN to $(hostname -I 2>/dev/null | awk '{print $1}') in your router / Pi-hole / AdGuard Home.
 2. Install /root/movexa-rootCA.crt on each device.
 3. Sign in as "$ADMIN_USER", then Admin → Users & permissions: create one account per person.
 4. Admin → Library: add your movies / shows folders (inside $DATA_DIR) or upload files.

الخطوات التالية
 1. في الراوتر أو Pi-hole أو AdGuard: وجّه $DOMAIN إلى $(hostname -I 2>/dev/null | awk '{print $1}').
 2. ثبّت الشهادة /root/movexa-rootCA.crt على كل جهاز (هاتف، كمبيوتر، تلفزيون).
 3. ادخل بالمستخدم "$ADMIN_USER"، ثم الإدارة ← المستخدمون والصلاحيات: أنشئ حساباً لكل فرد وحدد صلاحياته.
 4. الإدارة ← المكتبة: أضف مجلدات الأفلام والمسلسلات (داخل $DATA_DIR) أو ارفع الملفات.
$( [ ${#WARNINGS[@]} -gt 0 ] && printf '\nWarnings:\n' && printf ' - %s\n' "${WARNINGS[@]}" )
SUM
echo; cat "$SUMMARY"; echo; echo "(saved to $SUMMARY)"
