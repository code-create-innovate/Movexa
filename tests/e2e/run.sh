#!/bin/bash
# Runs the browser E2E test against a throwaway app instance (sqlite, sync queue, php file serving).
set -euo pipefail
cd "$(dirname "$0")/../.."
WORK=${WORK:-$(mktemp -d)}
export APP_ENV=local APP_DEBUG=true DB_CONNECTION=sqlite DB_DATABASE="$WORK/e2e.sqlite" SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync \
  BROADCAST_CONNECTION=null MOVEXA_DATA_ROOT="$WORK/data" MOVEXA_SERVE_MODE=php MOVEXA_SANDBOX=${MOVEXA_SANDBOX:-unshare} \
  MOVEXA_SCANNER=clamd MOVEXA_REQUIRE_SCANNER=false MOVEXA_HWACCEL=none MOVEXA_STREAMS_PER_USER=10 MOVEXA_LIVE_FILE_SOURCES=true APP_URL=http://127.0.0.1:8765 PHP_CLI_SERVER_WORKERS=6
for d in inbox library quarantine cache images uploads; do export "MOVEXA_${d^^}=$WORK/data/$d"; done
touch "$DB_DATABASE"
[ -f public/build/manifest.json ] || npx vite build
IDS=$(php tests/e2e/seed.php | tail -1)
ITEM=$(echo "$IDS" | sed -E 's/.*"item":([0-9]+).*/\1/')
rm -f public/hot
for i in $(seq 1 20); do ss -ltn | grep -q ":8765 " || break; sleep 0.5; done
php artisan serve --no-reload --host=127.0.0.1 --port=8765 > "$WORK/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for i in $(seq 1 30); do curl -sf http://127.0.0.1:8765/up >/dev/null && break; sleep 0.5; done
FILE=$(echo "$IDS" | sed -E 's/.*"file":([0-9]+).*/\1/')
BASE=http://127.0.0.1:8765 ITEM=$ITEM SHOTS=${SHOTS:-$WORK} node tests/e2e/player.e2e.mjs
BASE=http://127.0.0.1:8765 FILE=$FILE node tests/e2e/devices.e2e.mjs
for spec in tests/e2e/visuals.e2e.mjs tests/e2e/accounts.e2e.mjs; do [ -f "$spec" ] && BASE=http://127.0.0.1:8765 ITEM=$ITEM FILE=$FILE node "$spec"; done; true
