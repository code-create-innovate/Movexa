#!/bin/bash
# Movexa — 10 legally free films for trying the server (separate from the installer; run it only if you want them).
#
#   sudo bash sample-films.sh                 download all 10, then add them to Movexa
#   sudo bash sample-films.sh --list          show the films and their licenses
#   sudo bash sample-films.sh --only 1 --only 5
#   options: --site /www/wwwroot/movexa.lan   --php /www/server/php/85/bin/php   --no-scan
#
# Sources: download.blender.org (Blender open movies, Creative Commons Attribution) and archive.org
# (feature films in the public domain). Nothing else is contacted: no streaming sites, no torrents.
# Re-running resumes unfinished downloads and skips finished films.
set -euo pipefail

BLENDER=${SAMPLES_BLENDER:-https://download.blender.org}
ARCHIVE=${SAMPLES_ARCHIVE:-https://archive.org}
SITE_DIR=""; PHP=""; ONLY=(); LIST=0; SCAN=1

# title | year | license | Blender path (empty = archive.org) | min-max minutes
FILMS=(
"Big Buck Bunny|2008|CC BY 3.0 — Blender Foundation|/peach/bigbuckbunny_movies/big_buck_bunny_1080p_h264.mov|8-12"
"Sintel|2010|CC BY 3.0 — Blender Foundation|/durian/movies/Sintel.2010.1080p.mkv|12-17"
"Tears of Steel|2012|CC BY 3.0 — Blender Foundation|/demo/movies/ToS/tears_of_steel_1080p.mov|10-15"
"Elephants Dream|2006|CC BY 2.5 — Blender Foundation||9-13"
"Night of the Living Dead|1968|Public domain (published without a copyright notice)||85-105"
"Charade|1963|Public domain (invalid copyright notice)||100-120"
"His Girl Friday|1940|Public domain (copyright not renewed)||85-100"
"Nosferatu|1922|Public domain (published 1922)||60-100"
"The General|1926|Public domain (published 1926)||65-90"
"Detour|1945|Public domain (copyright not renewed)||60-75"
)

ok()   { printf '\033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '\033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\033[31m✗\033[0m %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --list) LIST=1 ;;
        --only) ONLY+=("${2:?--only needs a number}"); shift ;;
        --site) SITE_DIR=${2%/}; shift ;;
        --php) PHP=$2; shift ;;
        --no-scan) SCAN=0 ;;
        -h|--help) sed -n '2,11p' "$0"; exit 0 ;;
        *) die "unknown option: $1 (see --help)" ;;
    esac
    shift
done

if [ "$LIST" = 1 ]; then
    i=0; for f in "${FILMS[@]}"; do i=$((i+1)); IFS='|' read -r t y l _ _ <<<"$f"; printf '%2d. %-34s %s\n' "$i" "$t ($y)" "$l"; done
    exit 0
fi

[ "$(id -u)" = 0 ] || die "run it with sudo: sudo bash $0"
for c in curl python3; do command -v $c >/dev/null || die "$c is missing (apt install $c)"; done

# ---------- find the Movexa site and its data folders ----------
if [ -z "$SITE_DIR" ]; then
    for d in /www/wwwroot/*/; do
        [ -f "$d/artisan" ] && grep -q '^MOVEXA_' "$d/.env" 2>/dev/null && { SITE_DIR=${d%/}; break; }
    done
fi
[ -n "$SITE_DIR" ] && [ -f "$SITE_DIR/.env" ] || die "Movexa site not found — pass it: --site /www/wwwroot/movexa.lan"
envv() { grep -E "^$1=" "$SITE_DIR/.env" | tail -1 | cut -d= -f2- | tr -d '"' || true; }
DATA=$(envv MOVEXA_DATA_ROOT); DATA=${DATA:-/www/data/movexa}
LIB=$(envv MOVEXA_LIBRARY); LIB=${LIB:-$DATA/library}
CACHE=$(envv MOVEXA_CACHE); CACHE=${CACHE:-$DATA/cache}
DEST=$LIB/free-films
TMP=$CACHE/sample-downloads     # outside the library, so the watcher never sees half a file
OWNER=$(stat -c %U "$SITE_DIR/.env")
if [ -z "$PHP" ]; then for p in /www/server/php/85/bin/php "$(command -v php || true)"; do [ -n "$p" ] && [ -x "$p" ] && { PHP=$p; break; }; done; fi
mkdir -p "$DEST" "$TMP"
ok "site $SITE_DIR → films go to $DEST"

# ---------- archive.org: best MP4 of the most downloaded item whose length fits the film ----------
archive_url() { # $1 title, $2 min, $3 max
    local ids id meta
    ids=$(curl -fsS -m 30 -G "$ARCHIVE/advancedsearch.php" \
        --data-urlencode "q=title:(\"$1\") AND mediatype:(movies)" --data-urlencode 'fl[]=identifier' \
        --data-urlencode 'sort[]=downloads desc' --data-urlencode 'rows=8' --data-urlencode 'output=json' \
        | python3 -I -c 'import json,re,sys
for d in json.load(sys.stdin).get("response",{}).get("docs",[]):
    i=d.get("identifier","")
    if re.fullmatch(r"[\w.\-]+",i): print(i)') || return 1
    for id in $ids; do
        meta=$(curl -fsS -m 30 "$ARCHIVE/metadata/$id" | python3 -I -c 'import json,re,sys,urllib.parse
lo,hi=float(sys.argv[1]),float(sys.argv[2])
def mins(v):
    v=str(v or "")
    if re.fullmatch(r"\d+(\.\d+)?",v): return float(v)/60
    m=re.fullmatch(r"(?:(\d+):)?(\d+):(\d+)(?:\.\d+)?",v)
    return int(m[1] or 0)*60+int(m[2])+int(m[3])/60 if m else None
ok=[f for f in json.load(sys.stdin).get("files",[]) if f.get("name","").lower().endswith(".mp4") and "/" not in f["name"]
    and (mins(f.get("length")) or 0)>=lo and (mins(f.get("length")) or 1e9)<=hi]
if ok: print(urllib.parse.quote(max(ok,key=lambda f:int(f.get("size") or 0))["name"]))' "$2" "$3") || continue
        [ -n "$meta" ] && { echo "$ARCHIVE/download/$id/$meta"; return 0; }
    done
    return 1
}

# ---------- download ----------
FAILED=(); i=0
for f in "${FILMS[@]}"; do
    i=$((i+1)); IFS='|' read -r title year license bpath range <<<"$f"
    if [ ${#ONLY[@]} -gt 0 ] && ! printf '%s\n' "${ONLY[@]}" | grep -qx "$i"; then continue; fi
    name="$title ($year)"; dir="$DEST/$name"
    if [ -n "$(find "$dir" -maxdepth 1 -type f -size +1M 2>/dev/null | head -1)" ]; then ok "$name — already downloaded"; continue; fi
    url=""; ext=mp4
    if [ -n "$bpath" ] && curl -fsIL -m 20 "$BLENDER$bpath" >/dev/null 2>&1; then url=$BLENDER$bpath; ext=${bpath##*.}; fi
    [ -n "$url" ] || url=$(archive_url "$title" "${range%-*}" "${range#*-}") || url=""
    if [ -z "$url" ]; then FAILED+=("$name"); warn "$name — no matching file found on the official sources"; continue; fi
    echo "↓ $name ← ${url%%\?*}"
    part="$TMP/$(printf '%s' "$url" | md5sum | cut -c1-32).part"
    if ! curl -fL --retry 3 --retry-delay 5 -C - --progress-bar -o "$part" "$url"; then
        rm -f "$part"   # the server may not support resuming: start this one over once
        curl -fL --retry 3 --retry-delay 5 --progress-bar -o "$part" "$url" || { rm -f "$part"; FAILED+=("$name"); warn "$name — download failed"; continue; }
    fi
    [ "$(stat -c %s "$part")" -gt 1000000 ] || { rm -f "$part"; FAILED+=("$name"); warn "$name — file too small"; continue; }
    mkdir -p "$dir"
    printf '%s\nLicense: %s\nSource: %s\n' "$name" "$license" "$url" > "$dir/LICENSE.txt"
    mv "$part" "$dir/$name.$ext"
    ok "$name → $dir/$name.$ext"
done
chown -R "$OWNER:$OWNER" "$DEST"

# ---------- add to Movexa (library "Free films") and run the usual security check ----------
if [ "$SCAN" = 1 ]; then
    [ -n "$PHP" ] || die "PHP not found — pass --php /www/server/php/85/bin/php"
    TH=$(mktemp -d); chown "$OWNER" "$TH"   # tinker needs a writable home; www has none on aaPanel
    LIBID=$(cd "$SITE_DIR" && runuser -u "$OWNER" -- env HOME="$TH" XDG_CONFIG_HOME="$TH" FREE_DIR="$DEST" "$PHP" artisan tinker --execute='
        $l = App\Modules\Library\Models\Library::firstOrCreate(["path" => getenv("FREE_DIR")], ["name" => "Free films", "type" => "movies", "source" => "local", "watch" => true]);
        echo "LIB=".$l->id.PHP_EOL;' 2>&1 | grep -o 'LIB=[0-9]*' | cut -d= -f2) || true
    rm -rf "$TH"
    [ -n "$LIBID" ] || die "could not create the \"Free films\" library (is Movexa installed in $SITE_DIR?)"
    (cd "$SITE_DIR" && runuser -u "$OWNER" -- "$PHP" artisan movexa:scan --library="$LIBID")
    systemctl restart movexa-watch 2>/dev/null || true   # start watching the new folder
    ok "library \"Free films\" ready — the films show up after the security check"
fi

if [ ${#FAILED[@]} -gt 0 ]; then warn "not downloaded: ${FAILED[*]} — run the script again later"; exit 1; fi
ok "done"
