#!/bin/bash
# QA · separa «no recalculó» de «está cacheado»: mira la option, el seq editorial,
# el _price materializado y lo servido, en ese orden.
set -u
cd /Users/alefvaras/Documents/akibara || exit 1
SLUG=${1:-dan-da-dan-1-ivrea-argentina}
ID=${2:-}
B=http://localhost:8080
wpc() { docker compose run --rm -T cli "$@" --path=/var/www/html 2>/dev/null; }
servido() { curl -sL -A QA "$B/$SLUG/" | python3 -c "
import sys,html,re
h=html.unescape(sys.stdin.read())
m=re.findall(r'\"price\"\s*:\s*\"?([0-9]+(?:\.[0-9]+)?)\"?', h)
print(m[0] if m else '?')"; }
estado() { wpc option get akb_search_editorial_discount_state | python3 -c "
import sys,json
try:
  d=json.load(sys.stdin); print('seq=%s fp=%s' % (d['wanted_seq'], d['wanted_fingerprint'][:8]))
except Exception: print('sin estado')"; }
[ -z "$ID" ] && ID=$(wpc db query "SELECT ID FROM wp_posts WHERE post_name='$SLUG' AND post_type='product' LIMIT 1" | tail -1)
materializado() { wpc db query "SELECT meta_value FROM wp_postmeta WHERE post_id=$ID AND meta_key='_price'" | tail -1; }

linea() { printf '   %-14s option=%-8s %-24s _price=%-8s servido=%s\n' "$1" "$(wpc option get akb_discount_max_pct | tail -1 || echo ausente)" "$(estado)" "$(materializado)" "$(servido)"; }

echo "producto $ID ($SLUG)"
linea "inicio"
echo "— pongo max_pct=9 —"
wpc option update akb_discount_max_pct 9 >/dev/null
for s in 5 15 30 60; do sleep "$s"; linea "+${s}s"; done
echo "— borro la option —"
wpc option delete akb_discount_max_pct >/dev/null
for s in 5 25; do sleep "$s"; linea "+${s}s"; done
