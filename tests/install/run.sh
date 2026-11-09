#!/bin/bash
# Tests deploy/install.sh against a SIMULATED aaPanel server (only run in a throwaway container: it writes to
# /www, /etc/init.d, /etc/hosts, /etc/cron.d, /etc/systemd/system and creates system users).
# It recreates the state right after "aaPanel → Website → Add site movexa.lan (PHP 8.5)", runs the installer
# twice (idempotency), and checks the result over real HTTPS on :8088.
set -uo pipefail
REPO=$(cd "$(dirname "$0")/../.." && pwd)
PASS=0; FAIL=0
ok() { echo "✓ $1"; PASS=$((PASS+1)); }
ko() { echo "✗ $1"; FAIL=$((FAIL+1)); }
export NO_PROXY="movexa.lan,127.0.0.1,localhost${NO_PROXY:+,$NO_PROXY}" no_proxy="$NO_PROXY"

# ---------- prerequisites the real script would apt-install ----------
DEBIAN_FRONTEND=noninteractive apt-get install -y -q sqlite3 rsync >/dev/null 2>&1 || true
pgrep -x mariadbd >/dev/null || (mysqld_safe --user=root >/dev/null 2>&1 &) ; sleep 4
redis-cli ping >/dev/null 2>&1 || redis-server --daemonize yes >/dev/null
pkill -f "nginx: master" 2>/dev/null; sleep 0.5

# ---------- site auto-detection (isolated fake panels; stops at the PHP check on purpose: PHP_VER=99) ----------
detect() { # $1 = panel root, rest = extra args → prints the installer output
    MOVEXA_INSTALL_TEST=1 PANEL=$1/panel WWWROOT=$1/wwwroot PHP_VER=99 bash "$REPO/deploy/install.sh" "${@:2}" 2>&1 | sed 's/\x1b\[[0-9;]*m//g'
}
mkpanel() { rm -rf "$1"; mkdir -p "$1/panel/data" "$1/panel/vhost/nginx" "$1/wwwroot"; }
D=/tmp/movexa-detect
# a) site added in aaPanel under another name, only known from aaPanel's site database
mkpanel $D/a; mkdir -p $D/a/wwwroot/movexa.home
python3 -c "import sqlite3; c=sqlite3.connect('$D/a/panel/data/default.db'); c.execute('create table sites(id integer primary key, name text, path text)'); c.execute(\"insert into sites(name,path) values('movexa.home','$D/a/wwwroot/movexa.home')\"); c.commit()"
echo "server { listen 80; server_name movexa.home; root $D/a/wwwroot/movexa.home; }" > $D/a/panel/vhost/nginx/movexa.home.conf
O=$(detect $D/a); echo "$O" | grep -q "website: movexa.home → $D/a/wwwroot/movexa.home" && echo "$O" | grep -q "domain: movexa.home" && ok "detects the site from aaPanel's site database (other name → domain movexa.home)" || { ko "detect a"; echo "$O" | tail -5; }
# b) folder called "Movexa", vhost with root …/public
mkpanel $D/b; mkdir -p $D/b/wwwroot/Movexa
echo "server { listen 80; server_name 192.168.1.50; root $D/b/wwwroot/Movexa/public; }" > $D/b/panel/vhost/nginx/Movexa.conf
O=$(detect $D/b); echo "$O" | grep -q "website: Movexa → $D/b/wwwroot/Movexa" && echo "$O" | grep -q "domain: movexa.lan" && ok "detects a site named \"Movexa\" (no domain in the name → movexa.lan)" || { ko "detect b"; echo "$O" | tail -5; }
# c) explicit --site / --domain
O=$(detect $D/b --site $D/b/wwwroot/Movexa/ --domain films.lan); echo "$O" | grep -q "website: Movexa → $D/b/wwwroot/Movexa" && echo "$O" | grep -q "domain: films.lan" && ok "--site / --domain options" || { ko "detect c"; echo "$O" | tail -5; }
# d) no site at all → a clear error that is actually printed (exit code 1)
mkpanel $D/d
O=$(detect $D/d); RC=$?
echo "$O" | grep -q "✗ no website found for Movexa" && echo "$O" | grep -q -- "--site" && ok "no site → clear error printed (with --site hint)" || { ko "detect d"; echo "$O" | tail -5; }
rm -rf $D

# ---------- fake aaPanel ----------
rm -rf /www /root/movexa-ca /root/movexa-install-summary.txt
mkdir -p /www/server/panel/{install,data} /www/server/panel/vhost/{nginx,cert,rewrite} /www/server/php/85/{bin,etc} /www/server/nginx/{sbin,conf} /www/wwwlogs /www/wwwroot/movexa.lan
ln -s "$(command -v php8.3 || command -v php)" /www/server/php/85/bin/php
# aaPanel default: dangerous functions disabled (incl. some Movexa needs)
for f in php.ini php-cli.ini; do
    printf '[PHP]\ndisable_functions = passthru,exec,system,putenv,chroot,chgrp,chown,shell_exec,popen,proc_open,pcntl_exec,ini_alter,ini_restore,dl,openlog,syslog,readlink,symlink,popepassthru,pcntl_alarm,pcntl_fork,pcntl_waitpid,pcntl_wait,pcntl_signal,proc_get_status,posix_kill\nupload_max_filesize = 50M\n' > /www/server/php/85/etc/$f
done
cp /etc/nginx/mime.types /etc/nginx/fastcgi.conf /www/server/nginx/conf/
cat > /www/server/nginx/conf/nginx.conf <<'CONF'
user root;
worker_processes 2;
pid /www/server/nginx/nginx.pid;
error_log /www/wwwlogs/nginx_error.log;
events { worker_connections 256; }
http { include mime.types; sendfile on; include /www/server/panel/vhost/nginx/*.conf; }
CONF
cat > /www/server/nginx/conf/enable-php-85.conf <<'CONF'
location ~ [^/]\.php(/|$) {
    try_files $uri =404;
    fastcgi_pass unix:/tmp/php-cgi-85.sock;
    fastcgi_index index.php;
    include fastcgi.conf;
}
CONF
printf '#!/bin/bash\nexec /usr/sbin/nginx -c /www/server/nginx/conf/nginx.conf "$@"\n' > /www/server/nginx/sbin/nginx; chmod +x /www/server/nginx/sbin/nginx
# aaPanel php-fpm service script
cat > /etc/init.d/php-fpm-85 <<'SVC'
#!/bin/bash
CONF=/www/server/php/85/etc/php-fpm.conf
cat > $CONF <<C
[global]
pid = /tmp/php-fpm-85.pid
error_log = /www/wwwlogs/php-fpm-85.log
daemonize = yes
[www]
user = www
group = www
listen = /tmp/php-cgi-85.sock
listen.mode = 0666
pm = static
pm.max_children = 6
clear_env = no
C
case "$1" in
  start) php-fpm8.3 -R -y $CONF ;;
  reload|restart) [ -f /tmp/php-fpm-85.pid ] && kill "$(cat /tmp/php-fpm-85.pid)" 2>/dev/null; sleep 1; php-fpm8.3 -R -y $CONF ;;
esac
SVC
chmod +x /etc/init.d/php-fpm-85
# "Add site" result: placeholder pages, immutable .user.ini, default vhost on :80
echo '<h1>Congratulations, the site is created successfully!</h1>' > /www/wwwroot/movexa.lan/index.html
echo '404' > /www/wwwroot/movexa.lan/404.html
echo 'open_basedir=/www/wwwroot/movexa.lan/:/tmp/' > /www/wwwroot/movexa.lan/.user.ini
cat > /www/server/panel/vhost/nginx/movexa.lan.conf <<'CONF'
server { listen 80; server_name movexa.lan; root /www/wwwroot/movexa.lan; index index.html; include enable-php-85.conf; }
CONF
id www >/dev/null 2>&1 || useradd --system --no-create-home www
/etc/init.d/php-fpm-85 start
/www/server/nginx/sbin/nginx

# ---------- run the installer (twice) ----------
export MOVEXA_INSTALL_TEST=1 ADMIN_PASS=AdminPass-12345 REBUILD_FRONTEND=${REBUILD_FRONTEND:-1}
if bash "$REPO/deploy/install.sh" > /tmp/install-run1.log 2>&1; then ok "install.sh run 1 completed"; else ko "install.sh run 1 failed"; tail -25 /tmp/install-run1.log; fi
ENV1=$(sha256sum /www/wwwroot/movexa.lan/.env 2>/dev/null | cut -d' ' -f1)
REBUILD_FRONTEND=0 bash "$REPO/deploy/install.sh" > /tmp/install-run2.log 2>&1 && ok "install.sh run 2 (idempotent) completed" || { ko "install.sh run 2 failed"; tail -25 /tmp/install-run2.log; }
[ -n "$ENV1" ] && [ "$ENV1" = "$(sha256sum /www/wwwroot/movexa.lan/.env | cut -d' ' -f1)" ] && ok ".env (secrets) unchanged by re-run" || ko ".env changed on re-run"

# ---------- checks ----------
S=/www/wwwroot/movexa.lan
CA=/root/movexa-ca/rootCA.pem
C="curl -s --noproxy movexa.lan --resolve movexa.lan:8088:127.0.0.1 --cacert $CA"
[ ! -f $S/index.html ] && [ -f $S/artisan ] && ok "placeholder pages replaced by the app" || ko "site files"
grep -q '^open_basedir=.*/www/data/movexa/' $S/.user.ini && ok ".user.ini open_basedir includes the data folder (protection kept)" || ko ".user.ini"
df=$(grep '^disable_functions' /www/server/php/85/etc/php.ini)
echo "$df" | grep -qE '(^|[=,])(proc_open|putenv|pcntl_signal|shell_exec|posix_kill)(,|$)' && ko "needed functions still disabled: $df" || ok "only the needed PHP functions re-enabled"
echo "$df" | grep -q 'passthru' && echo "$df" | grep -q 'system' && ok "other dangerous functions stay disabled (exec, system, passthru…)" || ko "too much enabled"
grep -q '^upload_max_filesize = 16M' /www/server/php/85/etc/php.ini && ok "upload chunk limits set" || ko "php limits"
grep -q 'listen 8088 ssl' /www/server/panel/vhost/nginx/movexa.lan.conf && ! grep -qE 'listen (80|443)[ ;]' /www/server/panel/vhost/nginx/movexa.lan.conf && ok "vhost: :8088 only" || ko "vhost ports"
[ -f /www/server/panel/vhost/nginx/movexa.lan.conf.movexa.bak ] && ok "original vhost backed up" || ko "vhost backup"
H=$($C -m 10 https://movexa.lan:8088/api/health); echo "$H" | grep -q '"service":"movexa"' && ok "https://movexa.lan:8088/api/health (TLS with the generated CA): $H" || ko "health: $H"
R=$($C -o /dev/null -w '%{http_code} %{redirect_url}' http://movexa.lan:8088/); [ "$R" = "302 https://movexa.lan:8088/" ] && ok "http://…:8088 → https" || ko "redirect: $R"
curl -s --noproxy '*' -m 3 -o /dev/null http://127.0.0.1:80/ && ko "something on :80" || ok "nothing on :80/:443"
grep -q "^APP_URL=https://movexa.lan:8088" $S/.env && grep -q "^REVERB_PORT=8088" $S/.env && grep -q "^DB_PASSWORD=.\{20,\}" $S/.env && ok ".env: APP_URL/Reverb on 8088, random DB password" || ko ".env values"
[ "$(stat -c %a $S/.env)" = 640 ] && [ "$(stat -c %U $S/.env)" = www ] && ok ".env 640 www (outside public/)" || ko ".env perms"
MYSQL_PWD=$(grep ^DB_PASSWORD= $S/.env | cut -d= -f2) mysql -umovexa -h127.0.0.1 movexa -e 'select count(*) from users' >/dev/null 2>&1 && ok "database + user created, migrations ran" || ko "database"
miss=0; for u in movexa-queue movexa-scan movexa-reverb movexa-watch movexa-live movexa-transcode@; do [ -f /etc/systemd/system/$u.service ] || { ko "unit $u missing"; miss=1; }; done; [ $miss = 0 ] && ok "systemd units written (auto-start on boot)"
grep -q 'schedule:run' /etc/cron.d/movexa && grep -q 'backup.sh' /etc/cron.d/movexa && ok "cron: scheduler every minute + nightly backup" || ko "cron"
[ -x /usr/local/sbin/movexa-sandbox ] && [ -f /etc/sudoers.d/movexa-sandbox ] && id movexa-sandbox >/dev/null && ok "sandbox wrapper + user + sudoers" || ko "sandbox"
grep -q "movexa.lan" /etc/hosts && ok "server resolves movexa.lan" || ko "hosts"
CRT=/www/server/panel/vhost/cert/movexa.lan/fullchain.pem
DAYS=$(( ( $(date -d "$(openssl x509 -in $CRT -noout -enddate | cut -d= -f2)" +%s) - $(date +%s) ) / 86400 ))
openssl x509 -in $CRT -noout -text | grep -q "DNS:movexa.lan" && [ "$DAYS" -le 825 ] && openssl verify -CAfile $CA $CRT >/dev/null && ok "certificate: signed by the local CA, SAN movexa.lan, $DAYS days (≤ 825 for iPhone)" || ko "certificate"
# Sign in as the admin created by the installer (username, not email)
J=$(mktemp); $C -c $J -b $J -o /dev/null https://movexa.lan:8088/login
X=$(grep XSRF-TOKEN $J | awk '{print $7}' | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
L=$($C -c $J -b $J -o /dev/null -w '%{http_code} %{redirect_url}' -H "X-XSRF-TOKEN: $X" -H 'Accept: text/html' --data-urlencode email=admin --data-urlencode password=AdminPass-12345 https://movexa.lan:8088/login)
[ "$L" = "302 https://movexa.lan:8088/home" ] && ok "admin signs in with username → /home" || ko "admin login: $L"
[ "$($C -c $J -b $J -o /dev/null -w '%{http_code}' https://movexa.lan:8088/admin/users)" = 200 ] && ok "admin can open Users & permissions" || ko "admin users page"
grep -q 'Password:       AdminPass-12345' /root/movexa-install-summary.txt && [ "$(stat -c %a /root/movexa-install-summary.txt)" = 600 ] && ok "summary written (root-only)" || ko "summary"

/www/server/nginx/sbin/nginx -s stop 2>/dev/null; kill "$(cat /tmp/php-fpm-85.pid 2>/dev/null)" 2>/dev/null
echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" = 0 ]
