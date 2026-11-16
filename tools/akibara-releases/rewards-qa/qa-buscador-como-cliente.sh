#!/usr/bin/env bash
# QA · el buscador visto por un cliente. Sólo lectura, sin sesión.
set -u
BASE="${BASE:-https://akibara.cl}"
API="$BASE/wp-json/akibara/v1/search/suggest"

probar () {
  local q="$1" espera="$2"
  local t0 t1 ms out n primero
  t0=$(python3 -c 'import time;print(int(time.time()*1000))')
  out=$(curl -s --max-time 20 -G "$API" --data-urlencode "q=$q" --data-urlencode "_qa=$(date +%s%N)")
  t1=$(python3 -c 'import time;print(int(time.time()*1000))')
  ms=$((t1-t0))
  read -r n primero <<<"$(printf '%s' "$out" | python3 -c "
import json,sys
try: d=json.load(sys.stdin)
except Exception: print('ERR -'); raise SystemExit
it = d.get('items') or d.get('results') or d.get('products') or []
if isinstance(d,list): it=d
first=''
if it:
    f=it[0]
    first=(f.get('title') or f.get('name') or f.get('label') or '?') if isinstance(f,dict) else str(f)
print(len(it), (first[:38] or '-').replace(' ','_'))
")"
  printf '  %-26s %5s res %5s ms  %-40s %s\n' "[${q}]" "$n" "$ms" "${primero//_/ }" "$espera"
}

echo "── consultas normales"
probar "one piece"        "debe encontrar"
probar "naruto"           "debe encontrar"
probar "jujutsu"          "debe encontrar"
echo "── acentos y mayúsculas (el cliente escribe como quiere)"
probar "botICARIA"        "debe encontrar Los diarios de la boticaria"
probar "boticaria"        "idem sin mayúsculas"
probar "Pokémon"          "con tilde"
probar "Pokemon"          "sin tilde — DEBE dar lo mismo"
echo "── erratas típicas"
probar "one pece"         "errata de una letra"
probar "narutoo"          "letra de más"
echo "── bordes"
probar ""                 "vacío"
probar "a"                "una letra"
probar "zzzzzzzzzz"       "sin resultados"
probar "one piece 99999"  "tomo que no existe"
probar "<script>x</script>" "inyección — no debe reflejarse"
probar "%%%"              "signos de porcentaje"
