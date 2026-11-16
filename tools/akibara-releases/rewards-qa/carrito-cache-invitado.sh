#!/bin/bash
# QA · ¿el endpoint del carrito se sirve cacheado y viejo a un visitante SIN sesión?
# Solo lee y usa un carrito de invitado propio. No toca datos de nadie.
set -u
J=$(mktemp)
B=https://akibara.cl
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 QA-akibara"
PROD=${1:-27854}   # Death Note 12

hdr() { grep -i "^$1:" "$2" | tail -1 | tr -d '\r' | sed "s/^$1: //I"; }

echo "== 1. primer GET (crea sesión de invitado) =="
curl -s -c "$J" -b "$J" -A "$UA" -D /tmp/qa-h1 -o /tmp/qa-b1 "$B/wp-json/wc/store/v1/cart"
echo "   litespeed=$(hdr x-litespeed-cache /tmp/qa-h1)  items=$(python3 -c "import json;print(len(json.load(open('/tmp/qa-b1')).get('items',[])))" 2>/dev/null)"
NONCE=$(hdr nonce /tmp/qa-h1)
echo "   nonce=${NONCE:0:6}…"

echo "== 2. AÑADO un producto =="
curl -s -c "$J" -b "$J" -A "$UA" -H "Content-Type: application/json" -H "Nonce: $NONCE" \
  -D /tmp/qa-h2 -o /tmp/qa-b2 -X POST \
  -d "{\"id\":$PROD,\"quantity\":1}" "$B/wp-json/wc/store/v1/cart/add-item"
echo "   http=$(head -1 /tmp/qa-h2 | tr -d '\r')  items=$(python3 -c "import json;print(len(json.load(open('/tmp/qa-b2')).get('items',[])))" 2>/dev/null)"

echo "== 3. vuelvo a LEER el carrito, tres veces, sin parámetro =="
for i in 1 2 3; do
  curl -s -c "$J" -b "$J" -A "$UA" -D /tmp/qa-h3 -o /tmp/qa-b3 "$B/wp-json/wc/store/v1/cart"
  echo "   [$i] litespeed=$(hdr x-litespeed-cache /tmp/qa-h3)  items=$(python3 -c "import json;print(len(json.load(open('/tmp/qa-b3')).get('items',[])))" 2>/dev/null)  total=$(python3 -c "import json;print(json.load(open('/tmp/qa-b3')).get('totals',{}).get('total_price'))" 2>/dev/null)"
  sleep 1
done

echo "== 4. y con parámetro que rompe la caché =="
curl -s -c "$J" -b "$J" -A "$UA" -D /tmp/qa-h4 -o /tmp/qa-b4 "$B/wp-json/wc/store/v1/cart?qa=$RANDOM$RANDOM"
echo "   litespeed=$(hdr x-litespeed-cache /tmp/qa-h4)  items=$(python3 -c "import json;print(len(json.load(open('/tmp/qa-b4')).get('items',[])))" 2>/dev/null)  total=$(python3 -c "import json;print(json.load(open('/tmp/qa-b4')).get('totals',{}).get('total_price'))" 2>/dev/null)"

rm -f "$J" /tmp/qa-h? /tmp/qa-b?
