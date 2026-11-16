#!/usr/bin/env bash
# QA · ¿el envío gratis sobre $60.000 se cumple DE VERDAD en el cobro?
# La barra de progreso es cosmética; esto mide el precio del envío. Invitado.
set -u
BASE="${BASE:-https://akibara.cl}"
PID="${1:?falta id de producto en stock}"

carrito () {
  local qty="$1"
  local jar; jar="$(mktemp)"
  local nonce
  nonce="$(curl -s -c "$jar" -b "$jar" -D - -o /dev/null "$BASE/wp-json/wc/store/v1/cart" | awk -F': ' 'tolower($1)=="nonce"{gsub(/\r/,"");print $2}')"
  curl -s -c "$jar" -b "$jar" -H "Nonce: $nonce" -H 'Content-Type: application/json' \
       -X POST "$BASE/wp-json/wc/store/v1/cart/add-item" -d "{\"id\":$PID,\"quantity\":$qty}" >/dev/null
  curl -s -c "$jar" -b "$jar" -H "Nonce: $nonce" -H 'Content-Type: application/json' \
       -X POST "$BASE/wp-json/wc/store/v1/cart/update-customer" \
       -d '{"shipping_address":{"country":"CL","state":"CL-RM","city":"Santiago","postcode":"8320000","address_1":"Av. Siempre Viva 100"}}' \
   | python3 -c "
import json,sys
c=json.load(sys.stdin)
t=c.get('totals',{})
div=10**int(t.get('currency_minor_unit',0))
items=int(t.get('total_items',0))/div
envios=[]
for p in c.get('shipping_rates',[]):
    for r in p.get('shipping_rates',[]):
        envios.append((r.get('method_id'), r.get('name',''), int(r.get('price',0))/div))
print('  subtotal \$%s' % format(int(items),',d').replace(',','.'))
for m,n,pr in sorted(envios, key=lambda x: x[2]):
    et = 'GRATIS' if pr==0 else ('\$'+format(int(pr),',d').replace(',','.'))
    print('     %-14s %-40s %s' % (m, n[:40], et))
"
  rm -f "$jar"
}

echo "── carrito PEQUEÑO"; carrito 1
echo "── carrito JUSTO POR DEBAJO del umbral"; carrito "${2:-6}"
echo "── carrito POR ENCIMA del umbral"; carrito "${3:-8}"
