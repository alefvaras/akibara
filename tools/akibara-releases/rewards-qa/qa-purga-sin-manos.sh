#!/bin/bash
# QA · petición de dev: cambiar `akb_discount_max_pct` y comprobar que la ficha
# servida deja de tener el precio viejo SIN purgar a mano.
# Uso: bash qa-purga-sin-manos.sh [slug-del-producto]
set -u
cd /Users/alefvaras/Documents/akibara || exit 1
SLUG=${1:-death-note-12-ivrea-argentina}
B=http://localhost:8080
UA="QA-akibara/1.0"
wpc() { docker compose run --rm -T cli "$@" --path=/var/www/html 2>/dev/null; }

precio() {
  curl -sL -A "$UA" "$B/$SLUG/" | python3 -c "
import sys,html,re
h=html.unescape(sys.stdin.read())
m=re.findall(r'\"price\"\s*:\s*\"?([0-9]+(?:\.[0-9]+)?)\"?', h)
print(m[0] if m else '?')"
}

echo "— 0. estado inicial —"
echo "   max_pct = $(wpc option get akb_discount_max_pct | tail -1 || echo '(ausente)')"
echo "   precio servido = $(precio)"

echo "— 1. pongo max_pct=9 (el descuento del término es 10) —"
wpc option update akb_discount_max_pct 9 >/dev/null
for i in 1 2 3 4 5 6; do
  sleep 10
  P=$(precio)
  echo "   +$((i*10))s  precio servido = $P"
  [ "$P" != "8991" ] && { echo "   ✔ cambió sin purgar a mano, en ~$((i*10))s"; break; }
done

echo "— 2. borro la option (vuelta al 10%) —"
wpc option delete akb_discount_max_pct >/dev/null
for i in 1 2 3 4 5 6; do
  sleep 10
  P=$(precio)
  echo "   +$((i*10))s  precio servido = $P"
  [ "$P" = "8991" ] && { echo "   ✔ volvió sin purgar a mano, en ~$((i*10))s"; break; }
done

echo "— 3. estado final —"
echo "   max_pct = $(wpc option get akb_discount_max_pct | tail -1 || echo '(ausente)')"
echo "   precio servido = $(precio)"
