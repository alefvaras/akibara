#!/bin/bash
# QA · borde de dinero: ¿la ficha enseña un precio y el carrito cobra otro?
# Carrito de INVITADO propio. No toca datos de nadie.
set -u
B=https://akibara.cl
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 QA-akibara"
hdr() { grep -i "^$1:" "$2" | tail -1 | tr -d '\r' | sed "s/^$1: //I"; }

probar() {
  ID=$1; SLUG=$2; ETIQUETA=$3
  J=$(mktemp)
  # 1. precio que enseña la ficha
  FICHA=$(curl -s -A "$UA" "$B/$SLUG/" | python3 -c "
import sys,re,html
h=html.unescape(sys.stdin.read())
m=re.findall(r'\"price\"\s*:\s*\"?([0-9]+(?:\.[0-9]+)?)\"?', h)
ld=[x for x in m][:3]
p=re.findall(r'\\\$\s?([0-9][0-9.]{2,})', h)[:6]
print('json='+(','.join(ld) if ld else '-')+'  visibles='+(','.join(p) if p else '-'))
")
  # 2. precio que cobra el carrito
  curl -s -c "$J" -b "$J" -A "$UA" -D /tmp/qa-hh -o /dev/null "$B/wp-json/wc/store/v1/cart"
  N=$(hdr nonce /tmp/qa-hh)
  curl -s -c "$J" -b "$J" -A "$UA" -H "Content-Type: application/json" -H "Nonce: $N" \
    -o /tmp/qa-bb -X POST -d "{\"id\":$ID,\"quantity\":1}" "$B/wp-json/wc/store/v1/cart/add-item" >/dev/null
  CARRITO=$(python3 -c "
import json
d=json.load(open('/tmp/qa-bb'))
it=[i for i in d.get('items',[]) if i['id']==$ID]
print('sin linea' if not it else 'precio='+it[0]['prices']['price']+'  regular='+it[0]['prices']['regular_price']+'  linea='+it[0]['totals']['line_total'])
" 2>/dev/null || echo "error")
  echo "$ETIQUETA (id $ID)"
  echo "   ficha  : $FICHA"
  echo "   carrito: $CARRITO"
  rm -f "$J"
}

probar 27836 "death-note-03-ivrea-argentina" "Death Note 03  SIN rebaja manual"
probar 27838 "death-note-04-ivrea-argentina" "Death Note 04  CON rebaja manual 9600"
probar 27854 "death-note-12-ivrea-argentina" "Death Note 12  SIN rebaja manual"
rm -f /tmp/qa-hh /tmp/qa-bb
