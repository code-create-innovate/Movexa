#!/bin/bash
# Real nginx + php-fpm + Reverb integration test of deploy/nginx/movexa.lan.conf on port 8088.
# Needs: nginx, php8.3-fpm, openssl, curl, node+playwright (optional browser part). Run as root in a throwaway env.
set -uo pipefail
cd "$(dirname "$0")/../.."
APP=$(pwd)
W=${WORK:-$(mktemp -d)}; mkdir -p "$W"/{conf,logs,data,cert}
PASS=0; FAIL=0
# Local names must never go through an outbound proxy.
export NO_PROXY="movexa.lan,127.0.0.1,localhost${NO_PROXY:+,$NO_PROXY}" no_proxy="movexa.lan,127.0.0.1,localhost${no_proxy:+,$no_proxy}"
ok() { echo "✓ $1"; PASS=$((PASS+1)); }
ko() { echo "✗ $1"; FAIL=$((FAIL+1)); }

# movexa.lan must resolve for PHP (Reverb publish) too — in production this is the LAN DNS (Pi-hole / router).
grep -q " movexa.lan" /etc/hosts || echo "127.0.0.1 movexa.lan  # added by tests/nginx/run.sh" >> /etc/hosts

# --- local CA + certificate for movexa.lan (what mkcert does) ---------------------------------------
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=Movexa Test CA" -keyout "$W/cert/ca.key" -out "$W/cert/ca.pem" 2>/dev/null
openssl req -newkey rsa:2048 -nodes -subj "/CN=movexa.lan" -keyout "$W/cert/privkey.pem" -out "$W/cert/req.csr" 2>/dev/null
printf "subjectAltName=DNS:movexa.lan\nextendedKeyUsage=serverAuth\n" > "$W/cert/ext"
openssl x509 -req -in "$W/cert/req.csr" -CA "$W/cert/ca.pem" -CAkey "$W/cert/ca.key" -CAcreateserial -days 30 -extfile "$W/cert/ext" -out "$W/cert/fullchain.pem" 2>/dev/null

# --- app environment ---------------------------------------------------------------------------------
export APP_ENV=production APP_DEBUG=false APP_URL=https://movexa.lan:8088 DB_CONNECTION=sqlite DB_DATABASE="$W/app.sqlite" \
  SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync SESSION_SECURE_COOKIE=true \
  BROADCAST_CONNECTION=reverb REVERB_APP_ID=movexa REVERB_APP_KEY=mxkey REVERB_APP_SECRET=mxsecret \
  REVERB_HOST=movexa.lan REVERB_PORT=8088 REVERB_SCHEME=https REVERB_SERVER_HOST=127.0.0.1 REVERB_SERVER_PORT=8080 \
  REVERB_CA_BUNDLE="$W/cert/ca.pem" MOVEXA_DATA_ROOT="$W/data" MOVEXA_SERVE_MODE=xaccel MOVEXA_SANDBOX=unshare \
  MOVEXA_SCANNER=clamd MOVEXA_REQUIRE_SCANNER=false MOVEXA_HWACCEL=none LOG_CHANNEL=single
for d in inbox library quarantine cache images uploads; do export "MOVEXA_${d^^}=$W/data/$d"; mkdir -p "$W/data/$d"; done
touch "$DB_DATABASE"
php tests/e2e/seed.php > "$W/ids.json" 2>"$W/logs/seed.log" || { cat "$W/logs/seed.log"; exit 1; }
ITEM=$(sed -E 's/.*"item":([0-9]+).*/\1/' "$W/ids.json" | tail -1)
[ -f public/build/manifest.json ] || npx vite build >/dev/null

# --- php-fpm (aaPanel uses unix:/tmp/php-cgi-83.sock) ------------------------------------------------
cat > "$W/conf/php-fpm.conf" <<CONF
[global]
error_log = $W/logs/fpm.log
daemonize = yes
pid = $W/fpm.pid
[www]
user = root
group = root
listen = $W/php-cgi-83.sock
pm = static
pm.max_children = 6
clear_env = no
CONF
php-fpm8.3 -R -y "$W/conf/php-fpm.conf"

# --- nginx: the shipped server block, with paths of this throwaway environment ------------------------
cat > "$W/conf/enable-php-83.conf" <<CONF
location ~ [^/]\.php(/|\$) {
    try_files \$uri =404;
    fastcgi_pass unix:$W/php-cgi-83.sock;
    fastcgi_index index.php;
    include /etc/nginx/fastcgi.conf;
}
CONF
sed -e "s#/www/wwwroot/movexa.lan#$APP#g" -e "s#/www/data/movexa/#$W/data/#g" -e "s#/www/data/cloudsync/#$W/cloudsync/#g" \
    -e "s#/www/server/panel/vhost/cert/movexa.lan#$W/cert#g" -e "s#/www/wwwlogs#$W/logs#g" \
    -e "s#include enable-php-83.conf;#include $W/conf/enable-php-83.conf;#g" deploy/nginx/movexa.lan.conf > "$W/conf/site.conf"
cat > "$W/conf/nginx.conf" <<CONF
user root;
worker_processes 2;
pid $W/nginx.pid;
error_log $W/logs/nginx-error.log;
events { worker_connections 256; }
http { include /etc/nginx/mime.types; sendfile on; include $W/conf/site.conf; }
CONF
nginx -t -c "$W/conf/nginx.conf" 2>"$W/logs/nginx-t.log" && ok "nginx -t accepts deploy/nginx/movexa.lan.conf" || { ko "nginx -t"; cat "$W/logs/nginx-t.log"; }
nginx -c "$W/conf/nginx.conf"
php artisan reverb:start --host=127.0.0.1 --port=8080 > "$W/logs/reverb.log" 2>&1 &
REVERB=$!
trap 'kill $REVERB 2>/dev/null; nginx -c "$W/conf/nginx.conf" -s stop 2>/dev/null; kill $(cat "$W/fpm.pid") 2>/dev/null' EXIT
sleep 2

C="curl -s --noproxy movexa.lan --resolve movexa.lan:8088:127.0.0.1 --cacert $W/cert/ca.pem"
# 1. health over HTTPS on 8088 (certificate verified against the local CA)
[ "$($C -o "$W/h.json" -w '%{http_code}' https://movexa.lan:8088/api/health)" = 200 ] && grep -q '"service":"movexa"' "$W/h.json" && ok "https://movexa.lan:8088/api/health → 200 (TLS verified)" || ko "health on 8088: $(cat "$W/h.json")"
# 2. plain HTTP on 8088 → https, same port
LOC=$($C -o /dev/null -w '%{http_code} %{redirect_url}' http://movexa.lan:8088/api/health)
[ "$LOC" = "302 https://movexa.lan:8088/api/health" ] || [ "$LOC" = "301 https://movexa.lan:8088/api/health" ] && ok "http://…:8088 → $LOC" || ko "497 redirect: $LOC"
# 3. not served on 80/443
for p in 80 443; do curl -s --noproxy '*' -m 3 -o /dev/null --resolve movexa.lan:$p:127.0.0.1 http://movexa.lan:$p/ && ko "something answers on $p" || ok "nothing served on port $p"; done
# 4. internal locations and secrets are not reachable
[ "$($C -o /dev/null -w '%{http_code}' https://movexa.lan:8088/_protected/library/)" = 404 ] && ok "/_protected/ is internal (404)" || ko "/_protected reachable"
[ "$($C -o /dev/null -w '%{http_code}' https://movexa.lan:8088/.env)" = 404 ] && ok ".env not served" || ko ".env reachable"
[ "$($C -o /dev/null -w '%{http_code}' https://movexa.lan:8088/m/x.y/stream.mp4)" = 403 ] && ok "media without session → 403" || ko "media without session"
# 5. WebSocket upgrade to Reverb through 8088
WS=$($C -m 3 --http1.1 -i -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' "https://movexa.lan:8088/app/mxkey?protocol=7&client=js&version=8.4.0" 2>/dev/null | head -1)
echo "$WS" | grep -q " 101" && ok "wss://movexa.lan:8088/app/… → 101 Switching Protocols" || ko "websocket: $WS"
# 6. PHP publishes to Reverb via the external https://movexa.lan:8088 address, trusting the local CA
PUB=$(php -r '
require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try { Illuminate\Support\Facades\Broadcast::connection("reverb")->broadcast(["test-channel"], "ping", ["ok" => 1]); echo "OK"; } catch (Throwable $e) { echo "ERR ".$e->getMessage(); }' 2>&1)
[ "$PUB" = OK ] && ok "PHP → Reverb publish over https://movexa.lan:8088 with local CA" || ko "publish: $PUB"
# 7. browser: login, play (X-Accel + Range), HLS, subtitles, signed URLs carry the port (relative), CSP wss
if node -e "require('playwright')" 2>/dev/null; then
  BASE=https://movexa.lan:8088 ITEM=$ITEM SHOTS=$W NGINX=1 node tests/e2e/player.e2e.mjs && ok "Playwright E2E through nginx :8088" || ko "Playwright through nginx"
fi
grep -q "X-Accel" "$W/logs/movexa.lan.log" 2>/dev/null; true
echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" = 0 ]
