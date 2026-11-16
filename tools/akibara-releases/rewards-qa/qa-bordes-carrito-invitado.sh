#!/bin/bash
# QA · bordes del CARRITO con una sesión de INVITADO propia, contra producción.
# No toca el carrito de nadie: crea el suyo y lo abandona.
set -u
B=https://akibara.cl
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) QA-akibara"
J=$(mktemp)
hdr() { grep -i "^$1:" "$2" | tail -1 | tr -d '\r' | sed "s/^$1: //I"; }
PROD=${1:-27854}      # Death Note 12, instock 2
AGOTADO=${2:-13384}   # Jujutsu Kaisen 21, outofstock

api() {  # metodo ruta json etiqueta
  local m="$1" ruta="$2" cuerpo="$3" et="$4"
  curl -s -c "$J" -b "$J" -A "$UA" -H "Content-Type: application/json" -H "Nonce: $NONCE" \
    -D /tmp/qa-h -o /tmp/qa-b -X "$m" ${cuerpo:+-d "$cuerpo"} "$B$ruta"
  local http; http=$(head -1 /tmp/qa-h | awk '{print $2}')
  local res; res=$(python3 -c "
import json
try:
    d=json.load(open('/tmp/qa-b'))
except Exception:
    print('(no json)'); raise SystemExit
if 'code' in d:
    print('%s: %s' % (d.get('code'), str(d.get('message'))[:90]))
else:
    it=d.get('items',[])
    print('items=%d cantidades=%s total=%s' % (len(it), [i['quantity'] for i in it], d.get('totals',{}).get('total_price')))
" 2>/dev/null)
  printf '%-38s http=%-4s %s\n' "$et" "$http" "$res"
}

curl -s -c "$J" -b "$J" -A "$UA" -D /tmp/qa-h0 -o /dev/null "$B/wp-json/wc/store/v1/cart"
NONCE=$(hdr nonce /tmp/qa-h0)
echo "sesión de invitado creada · nonce ${NONCE:0:6}…"
echo

api POST /wp-json/wc/store/v1/cart/add-item  "{\"id\":$PROD,\"quantity\":1}"      "añadir 1 (control)"
api POST /wp-json/wc/store/v1/cart/add-item  "{\"id\":$PROD,\"quantity\":0}"      "añadir cantidad 0"
api POST /wp-json/wc/store/v1/cart/add-item  "{\"id\":$PROD,\"quantity\":-3}"     "añadir cantidad NEGATIVA"
api POST /wp-json/wc/store/v1/cart/add-item  "{\"id\":$PROD,\"quantity\":999}"    "añadir 999 (stock 2)"
api POST /wp-json/wc/store/v1/cart/add-item  "{\"id\":$AGOTADO,\"quantity\":1}"   "añadir un AGOTADO"
api POST /wp-json/wc/store/v1/cart/add-item  '{"id":999999999,"quantity":1}'      "añadir un id inexistente"
api POST /wp-json/wc/store/v1/cart/apply-coupon '{"code":"NOEXISTE-QA"}'          "cupón inexistente"
api GET  /wp-json/wc/store/v1/cart ''                                             "estado final"

rm -f "$J" /tmp/qa-h /tmp/qa-b /tmp/qa-h0
