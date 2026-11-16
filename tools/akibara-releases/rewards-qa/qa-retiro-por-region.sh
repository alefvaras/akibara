#!/usr/bin/env bash
# QA · ¿aparece el retiro en Metro San Miguel según la región? Sesión de INVITADO.
# No toca ninguna cuenta ni ningún dato de un tercero.
set -u
BASE="https://akibara.cl"
PID="${1:?falta el id de producto en stock}"

probar_region () {
  local region="$1" etiqueta="$2"
  local jar; jar="$(mktemp)"
  local nonce
  nonce="$(curl -s -c "$jar" -b "$jar" -D - -o /dev/null "$BASE/wp-json/wc/store/v1/cart" | awk -F': ' 'tolower($1)=="nonce"{gsub(/\r/,"");print $2}')"
  curl -s -c "$jar" -b "$jar" -H "Nonce: $nonce" -H 'Content-Type: application/json' \
       -X POST "$BASE/wp-json/wc/store/v1/cart/add-item" \
       -d "{\"id\":$PID,\"quantity\":1}" > /dev/null
  local out
  out="$(curl -s -c "$jar" -b "$jar" -H "Nonce: $nonce" -H 'Content-Type: application/json' \
       -X POST "$BASE/wp-json/wc/store/v1/cart/update-customer" \
       -d "{\"shipping_address\":{\"country\":\"CL\",\"state\":\"$region\",\"city\":\"\",\"postcode\":\"\"}}")"
  echo "── $etiqueta ($region)"
  echo "$out" | python3 -c "
import json,sys
try: c=json.load(sys.stdin)
except Exception as e: print('   no pude leer la respuesta:',e); sys.exit()
rates=[]
for p in c.get('shipping_rates',[]):
    for r in p.get('shipping_rates',[]):
        rates.append((r.get('method_id'), r.get('name'), r.get('price')))
if not rates: print('   sin métodos de envío')
for m,n,pr in rates:
    marca = '  ← RETIRO' if m=='local_pickup' else ''
    print(f'   {m:16} {n[:44]:46} {pr}{marca}')
"
  rm -f "$jar"
}

probar_region "CL-RM" "Región Metropolitana"
probar_region "CL-ML" "Maule (la región de la dirección guardada)"
probar_region "CL-AP" "Arica y Parinacota (extremo norte)"
