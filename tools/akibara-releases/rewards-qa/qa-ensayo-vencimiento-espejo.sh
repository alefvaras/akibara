#!/bin/bash
# QA · ENSAYO del vencimiento en el ESPEJO: ¿qué ve el cliente cuando la promo
# termina? Mueve `to` a ayer, mira el precio servido, y restaura.
set -u
cd /Users/alefvaras/Documents/akibara || exit 1
SLUG=${1:-dan-da-dan-1-ivrea-argentina}
B=http://localhost:8080
wpc() { docker compose run --rm -T cli "$@" --path=/var/www/html 2>/dev/null; }
servido() { curl -sL -A QA "$B/$SLUG/" | python3 -c "
import sys,html,re
h=html.unescape(sys.stdin.read())
m=re.findall(r'\"price\"\s*:\s*\"?([0-9]+(?:\.[0-9]+)?)\"?', h)
r=re.findall(r'\"regular_?[Pp]rice\"\s*:\s*\"?([0-9]+)\"?', h)
print((m[0] if m else '?') + '  regular=' + (r[0] if r else '?'))"; }
estado() { wpc option get akb_search_editorial_discount_state | python3 -c "
import sys,json
try:
  d=json.load(sys.stdin)
  print('seq=%s activa=%s boundary=%s' % (d['wanted_seq'], d['window_active'], d.get('next_boundary_at')))
except Exception: print('sin estado')"; }

DESDE=$(wpc option get akibara_taxonomy_discount_from | tail -1)
HASTA=$(wpc option get akibara_taxonomy_discount_to | tail -1)
AYER=$(date -u -v-1d '+%Y-%m-%d' 2>/dev/null || date -u -d 'yesterday' '+%Y-%m-%d')
echo "ventana actual: $DESDE → $HASTA   · ayer = $AYER"
printf '  ANTES     %s | %s\n' "$(estado)" "$(servido)"

echo "→ muevo `to` a ayer (la promo queda vencida)"
wpc option update akibara_taxonomy_discount_to "$AYER" >/dev/null
for s in 5 10 20; do sleep $s; printf '  +%-3ss     %s | %s\n' "$s" "$(estado)" "$(servido)"; done

echo "→ restauro la ventana original ($HASTA)"
wpc option update akibara_taxonomy_discount_to "$HASTA" >/dev/null
for s in 5 15; do sleep $s; printf '  +%-3ss     %s | %s\n' "$s" "$(estado)" "$(servido)"; done
echo "ventana final: $(wpc option get akibara_taxonomy_discount_from | tail -1) → $(wpc option get akibara_taxonomy_discount_to | tail -1)"
