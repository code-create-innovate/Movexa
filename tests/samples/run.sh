#!/bin/bash
# Tests deploy/sample-films.sh against a local fake of download.blender.org + archive.org,
# using the site that tests/install/run.sh installed in /www/wwwroot/movexa.lan (throwaway container only).
set -uo pipefail
REPO=$(cd "$(dirname "$0")/../.." && pwd)
SITE=/www/wwwroot/movexa.lan
PASS=0; FAIL=0
ok() { echo "✓ $1"; PASS=$((PASS+1)); }
ko() { echo "✗ $1"; FAIL=$((FAIL+1)); }
[ -f $SITE/artisan ] || { echo "run tests/install/run.sh first"; exit 1; }
pgrep -x mariadbd >/dev/null || { (mysqld_safe --user=root >/dev/null 2>&1 &); sleep 4; }
LOG=$(mktemp); python3 -I "$REPO/tests/samples/fake_sources.py" 18089 2>"$LOG" & SRV=$!; sleep 1
trap 'kill $SRV 2>/dev/null' EXIT
export SAMPLES_BLENDER=http://127.0.0.1:18089/blender SAMPLES_ARCHIVE=http://127.0.0.1:18089/archive NO_PROXY=127.0.0.1 no_proxy=127.0.0.1
DEST=/www/data/movexa/library/free-films
rm -rf "$DEST" /www/data/movexa/cache/sample-downloads
q() { cd $SITE && MYSQL_PWD=$(grep ^DB_PASSWORD= .env | cut -d= -f2) mysql -umovexa -h127.0.0.1 movexa -Nse "$1"; }
q "delete from ingest_jobs where inbox_path like '$DEST/%'; delete from libraries where path='$DEST'"

O=$(bash "$REPO/deploy/sample-films.sh" --list); [ "$(echo "$O" | grep -c '^ *[0-9]*\. ')" = 10 ] && echo "$O" | grep -q "Night of the Living Dead (1968) *Public domain" && ok "--list: 10 films with licenses" || ko "--list"

# run 1: download only (Blender direct link + archive.org fallback)
bash "$REPO/deploy/sample-films.sh" --site $SITE --only 1 --only 5 --no-scan >/tmp/samples1.log 2>&1 && ok "run 1 (download only) completed" || { ko "run 1"; tail -8 /tmp/samples1.log; }
F1="$DEST/Big Buck Bunny (2008)/Big Buck Bunny (2008).mov"; F5="$DEST/Night of the Living Dead (1968)/Night of the Living Dead (1968).mp4"
[ "$(stat -c %s "$F1" 2>/dev/null)" = 1200000 ] && grep -q "CC BY 3.0" "$DEST/Big Buck Bunny (2008)/LICENSE.txt" && ok "Blender film from the official link, with LICENSE.txt" || ko "blender film"
[ "$(stat -c %s "$F5" 2>/dev/null)" = 1200000 ] && grep -q "GET /archive/download/night_of_the_living_dead/nld%20HQ.mp4" "$LOG" && ! grep -q trailer.mp4 "$LOG" && ok "archive.org: full film (largest MP4 of the right length), not the trailer" || ko "archive film"
[ "$(stat -c %U "$F1")" = www ] && ok "files owned by the site user (www)" || ko "owner"
[ -z "$(ls -A /www/data/movexa/cache/sample-downloads)" ] && ok "no partial files left behind" || ko "partials left"
[ "$(q "select count(*) from libraries where path='$DEST'")" = 0 ] && ok "--no-scan does not touch Movexa" || ko "no-scan"

# run 2: finished films skipped, missing film reported, library created and scanned
: > "$LOG"
bash "$REPO/deploy/sample-films.sh" --site $SITE --only 1 --only 5 --only 4 >/tmp/samples2.log 2>&1; RC=$?
[ $RC = 1 ] && grep -q "Elephants Dream (2006) — no matching file" /tmp/samples2.log && grep -q "not downloaded: Elephants Dream (2006)" /tmp/samples2.log && ok "film not found → reported, exit code 1" || { ko "failure report (rc=$RC)"; tail -8 /tmp/samples2.log; }
grep -q "Big Buck Bunny (2008) — already downloaded" /tmp/samples2.log && ! grep -q "download/night" "$LOG" && ok "re-run skips finished films (no new download)" || ko "skip"
[ "$(q "select concat(name,'|',type,'|',watch) from libraries where path='$DEST'")" = "Free films|movies|1" ] && ok "library \"Free films\" created in Movexa (watched)" || ko "library"
[ "$(q "select count(*) from ingest_jobs where inbox_path like '$DEST/%'")" = 2 ] && ok "both films sent through the normal security pipeline" || ko "ingest: $(q "select inbox_path, status from ingest_jobs where inbox_path like '$DEST/%'")"

echo "passed: $PASS  failed: $FAIL"; [ "$FAIL" = 0 ]
