#!/bin/bash
# QA · bordes de FECHAS de la ventana editorial, en el ESPEJO.
# Para cada caso: escribe la ventana, lee el estado resultante (activa +
# boundary) y el precio servido. Restaura la ventana original al terminar.
set -u
cd /Users/alefvaras/Documents/akibara || exit 1
B=http://localhost:8080
SLUG=dan-da-dan-1-ivrea-argentina
wpc() { docker compose run --rm -T cli "$@" --path=/var/www/html 2>/dev/null; }

leer() {
  wpc option get akb_search_editorial_discount_state | python3 -c "
import sys, json, datetime
try:
    d = json.load(sys.stdin)
    b = d.get('next_boundary_at')
    cuando = datetime.datetime.utcfromtimestamp(b).strftime('%Y-%m-%d %H:%M UTC') if b else 'null'
    print('seq=%-5s activa=%-5s boundary=%s' % (d['wanted_seq'], d['window_active'], cuando))
except Exception as e:
    print('sin estado (%s)' % e)"
}
precio() { curl -sL -A QA "$B/$SLUG/" | python3 -c "
import sys,html,re
h=html.unescape(sys.stdin.read())
m=re.findall(r'\"price\"\s*:\s*\"?([0-9]+(?:\.[0-9]+)?)\"?', h)
print(m[0] if m else '?')"; }

caso() {
  local etiqueta="$1" desde="$2" hasta="$3"
  wpc option update akibara_taxonomy_discount_from "$desde" >/dev/null
  wpc option update akibara_taxonomy_discount_to   "$hasta" >/dev/null
  sleep 8
  printf '%-42s desde=%-12s hasta=%-12s %s  precio=%s\n' "$etiqueta" "${desde:-(vacío)}" "${hasta:-(vacío)}" "$(leer)" "$(precio)"
}

DESDE0=$(wpc option get akibara_taxonomy_discount_from | tail -1)
HASTA0=$(wpc option get akibara_taxonomy_discount_to   | tail -1)
echo "ventana original: '$DESDE0' → '$HASTA0'"
echo

caso "control: ventana normal"              2026-09-04 2026-09-06
caso "un solo día (desde = hasta = hoy)"    2026-09-06 2026-09-06
caso "desde > hasta (invertida)"            2026-09-10 2026-09-02
caso "ambas vacías (vigencia indefinida)"   ""         ""
caso "solo desde (sin fin)"                 2026-09-04 ""
caso "solo hasta (sin inicio)"              ""         2026-09-30
caso "29 de febrero de año NO bisiesto"     2027-02-01 2027-02-29
caso "fecha imposible (mes 13)"             2026-13-01 2026-13-05
caso "texto que no es fecha"                "ayer"     "mañana"
caso "empieza el día del salto de hora"     2026-09-06 2026-09-20
caso "termina la víspera del salto otoñal"  2027-03-01 2027-04-03

echo
echo "— restauro '$DESDE0' → '$HASTA0' —"
wpc option update akibara_taxonomy_discount_from "$DESDE0" >/dev/null
wpc option update akibara_taxonomy_discount_to   "$HASTA0" >/dev/null
sleep 10
printf '%-42s %s  precio=%s\n' "FINAL" "$(leer)" "$(precio)"
echo "ventana final: '$(wpc option get akibara_taxonomy_discount_from | tail -1)' → '$(wpc option get akibara_taxonomy_discount_to | tail -1)'"
