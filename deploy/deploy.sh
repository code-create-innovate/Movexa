#!/bin/bash
# Update an installed Movexa (run as root from the site folder): pull, build, migrate, restart services.
set -euo pipefail
PHP=${PHP:-/www/server/php/85/bin/php}
cd "$(dirname "$0")/.."
git pull --ff-only
COMPOSER_ALLOW_SUPERUSER=1 $PHP "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction
[ -x /opt/node22/bin/node ] && export PATH=/opt/node22/bin:$PATH
if command -v npm >/dev/null; then npm ci --no-audit --no-fund --loglevel=error && npm run build; else echo "npm missing: upload a prebuilt public/build"; fi
$PHP artisan down --retry=30 || true
$PHP artisan migrate --force
$PHP artisan optimize:clear && $PHP artisan optimize
$PHP artisan up
chown -R www:www . && chmod -R ug+rwX storage bootstrap/cache
systemctl restart movexa-queue movexa-scan movexa-reverb movexa-watch movexa-live 'movexa-transcode@*' 2>/dev/null || true
echo "deployed $(git rev-parse --short HEAD)"
