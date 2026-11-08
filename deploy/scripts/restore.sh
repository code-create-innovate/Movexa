#!/bin/bash
# restore.sh /www/backup/movexa/<timestamp>   — restores DB, images, subtitles (and .env if missing)
set -euo pipefail
SRC=${1:?backup dir}; APP=${APP:-/www/wwwroot/movexa.lan}; DATA=${DATA:-/www/data/movexa}; PHP=${PHP:-/www/server/php/83/bin/php}
( cd "$SRC" && sha256sum -c SHA256SUMS )
[[ -f "$APP/.env" ]] || cp "$SRC/env" "$APP/.env"
set -a; source <(grep -E '^DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=' "$APP/.env"); set +a
gunzip -c "$SRC/db.sql.gz" | MYSQL_PWD="$DB_PASSWORD" mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" "$DB_DATABASE"
mkdir -p "$DATA/library" && tar -C "$DATA" -xzf "$SRC/images.tar.gz"
[[ -f "$SRC/subtitles.tar.gz" ]] && tar -C "$DATA/library" -xzf "$SRC/subtitles.tar.gz"
[[ -d "$(dirname "$SRC")/library-mirror" && "${RESTORE_MEDIA:-0}" == 1 ]] && rsync -a "$(dirname "$SRC")/library-mirror/" "$DATA/library/"
( cd "$APP" && $PHP artisan migrate --force && $PHP artisan optimize:clear )
echo "restore ok from $SRC"
