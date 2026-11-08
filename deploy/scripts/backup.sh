#!/bin/bash
# Backup DB + images + metadata + .env (+ optionally the video library).  aaPanel Cron: daily 03:20
#   /www/wwwroot/movexa.lan/deploy/scripts/backup.sh            # metadata only
#   INCLUDE_MEDIA=1 .../backup.sh                                # also rsync the library (large!)
set -euo pipefail
APP=${APP:-/www/wwwroot/movexa.lan}; DATA=${DATA:-/www/data/movexa}; DEST=${DEST:-/www/backup/movexa}; KEEP=${KEEP:-14}
set -a; source <(grep -E '^DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=' "$APP/.env"); set +a
TS=$(date +%Y%m%d-%H%M%S); OUT="$DEST/$TS"; mkdir -p "$OUT"; chmod 700 "$DEST"
MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --routines -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" "$DB_DATABASE" | gzip > "$OUT/db.sql.gz"
tar -C "$DATA" -czf "$OUT/images.tar.gz" images
tar -C "$DATA/library" -czf "$OUT/subtitles.tar.gz" .subtitles 2>/dev/null || true
cp "$APP/.env" "$OUT/env" && chmod 600 "$OUT/env"
[[ "${INCLUDE_MEDIA:-0}" == 1 ]] && rsync -a --delete "$DATA/library/" "$DEST/library-mirror/"
( cd "$OUT" && sha256sum * > SHA256SUMS )
ls -1dt "$DEST"/2* | tail -n +$((KEEP+1)) | xargs -r rm -rf
echo "backup ok: $OUT"
