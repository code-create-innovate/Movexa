#!/bin/bash
# Run as www from /www/wwwroot/movexa.lan
set -euo pipefail
PHP=${PHP:-/www/server/php/83/bin/php}
cd "$(dirname "$0")/../.."
git pull --ff-only
$PHP /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction
if command -v npm >/dev/null; then npm ci && npm run build; else echo "npm missing: upload a prebuilt public/build"; fi
$PHP artisan down --retry=30 || true
$PHP artisan migrate --force
$PHP artisan optimize:clear && $PHP artisan optimize
$PHP artisan up
supervisorctl restart 'movexa-queue:*' 'movexa-scan:*' 'movexa-transcode:*' movexa-reverb movexa-watch movexa-live || true
echo "deployed $(git rev-parse --short HEAD)"
