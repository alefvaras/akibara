#!/usr/bin/env bash
#
# QA · salud de producción. SOLO LECTURA: no escribe nada, no inicia sesión,
# no toca la base. Pensado para correr después de cada despliegue.
#
#   bash tools/akibara-releases/rewards-qa/qa-salud-produccion.sh
#
# DOS LECCIONES APRENDIDAS A GOLPES, POR LAS QUE ESTE GUION ES COMO ES:
#
# 1. UNA SOLA MEDICIÓN DE UN TIEMPO NO ES UNA MEDICIÓN. Dos veces en un mismo
#    día estuve a punto de reportar «regresión de rendimiento en /carrito»
#    (1,75 s una vez, 2,27 s otra) cuando era caché fría tras el despliegue: al
#    muestrear cinco veces bajaba a 0,35-0,63 s, y en una ronda el pico se mudó
#    a /checkout. El pico es del PRIMER GOLPE, no de la página. Por eso aquí
#    cada página se mide TRES veces y se informa la MEDIANA, y sólo se marca
#    LENTA si la mediana pasa de 1,5 s.
#
# 2. LAS FACETAS SE COMPRUEBAN CONTRA EL TOTAL, NO DE OÍDAS. instock +
#    outofstock + a_pedido tiene que dar exactamente el total del catálogo. Si
#    no suma, o falta un valor de disponibilidad o sobra un producto sin
#    clasificar. Ojo: el valor es «a_pedido», NO «onbackorder» — preguntar por
#    onbackorder devuelve 0 y parece una avería que no existe.
#
set -u
O="${1:-/tmp/qa-salud-produccion.txt}"
B=https://akibara.cl
N=$(date +%s%N)
: > "$O"

# OJO CON ESTO, que me lo comí en la primera versión: si los tres golpes van a
# la MISMA url, el 2.o y el 3.o los sirve la caché (Cloudflare/LiteSpeed) y la
# mediana mide la CDN, no el origen — justo lo contrario de lo que busco. Cada
# golpe lleva su propio parámetro anticaché para que los tres lleguen al origen.
mediana3() {  # imprime "codigo mediana" de tres golpes al ORIGEN
  local u="$1" c a b c2 sep
  case "$u" in *\?*) sep='&';; *) sep='?';; esac
  read -r c  a  < <(curl -sL --max-time 30 -A AkibaraQA -o /dev/null -w "%{http_code} %{time_total}" "$u${sep}_m=${RANDOM}a$$")
  read -r _  b  < <(curl -sL --max-time 30 -A AkibaraQA -o /dev/null -w "%{http_code} %{time_total}" "$u${sep}_m=${RANDOM}b$$")
  read -r _  c2 < <(curl -sL --max-time 30 -A AkibaraQA -o /dev/null -w "%{http_code} %{time_total}" "$u${sep}_m=${RANDOM}c$$")
  printf '%s %s\n' "$c" "$(printf '%s\n%s\n%s\n' "$a" "$b" "$c2" | sort -n | sed -n 2p)"
}

echo "══ PÁGINAS  (mediana de 3 golpes)" >> "$O"
malas=0
for u in "" "tienda" "a-pedido" "carrito" "checkout" "mi-cuenta" "akibara-puntos" \
         "dan-da-dan-1-ivrea-argentina" "frieren-1-ivrea-argentina" \
         "serie/demon-slayer-kimetsu-no-yaiba"; do
  read -r c t < <(mediana3 "$B/$u")
  m=""
  if [ "$c" != "200" ]; then m="  <-- HTTP $c"; malas=$((malas+1)); fi
  case "$t" in [2-9].*|1.[5-9]*) m="$m  <-- LENTA de verdad (mediana)";; esac
  printf '  %-40s %s %ss%s\n' "/$u" "$c" "$t" "$m" >> "$O"
done

echo "" >> "$O"
echo "══ BUSCADOR  (el tomo 1 debe salir primero)" >> "$O"
for q in "one piece 1" "frieren" "kagurabachi"; do
  curl -s --max-time 25 -A AkibaraQA -G "$B/wp-json/akibara/v1/search/suggest" \
       --data-urlencode "q=$q" --data-urlencode "_qa=$N" > "/tmp/.qa-sug.json"
  r=$(python3 -c "
import json
try: d=json.load(open('/tmp/.qa-sug.json'))
except Exception: print('(sin respuesta)'); raise SystemExit
it = d.get('items') or d.get('results') or []
print(' · '.join((x.get('title','?') if isinstance(x,dict) else str(x))[:24] for x in it[:3]) or '(vacío)')")
  printf '  %-16s %s\n' "[$q]" "$r" >> "$O"
done
rm -f /tmp/.qa-sug.json

echo "" >> "$O"
echo "══ CATÁLOGO  (las tres facetas deben sumar el total)" >> "$O"
tot=0
for v in instock outofstock a_pedido; do
  n=$(curl -s --max-time 25 -A AkibaraQA "$B/wp-json/akibara/v1/search/count?_disponibilidad%5B%5D=$v&_qa=$N" | tr -dc '0-9')
  n=${n:-0}
  printf '  %-12s %s\n' "$v" "$n" >> "$O"
  tot=$((tot + n))
done
g=$(curl -s --max-time 25 -A AkibaraQA "$B/wp-json/akibara/v1/search/count?_qa=$N" | tr -dc '0-9')
g=${g:-0}
if [ "$tot" = "$g" ]; then
  printf '  %-12s %s   (las tres suman %s — CUADRA)\n' "TOTAL" "$g" "$tot" >> "$O"
else
  printf '  %-12s %s   (las tres suman %s — NO CUADRA, faltan %s)\n' "TOTAL" "$g" "$tot" "$((g-tot))" >> "$O"
  malas=$((malas+1))
fi

echo "" >> "$O"
echo "══ INFRAESTRUCTURA" >> "$O"
for par in "no-existe-qa-$N/:404" "sitemap_index.xml:200" "llms.txt:200" "robots.txt:200"; do
  ruta=${par%:*}; esperado=${par##*:}
  c=$(curl -sL --max-time 25 -A AkibaraQA -o /dev/null -w "%{http_code}" "$B/$ruta")
  m=""; [ "$c" != "$esperado" ] && { m="  <-- se esperaba $esperado"; malas=$((malas+1)); }
  printf '  %-28s %s%s\n' "$(echo "$ruta" | cut -c1-28)" "$c" "$m" >> "$O"
done

echo "" >> "$O"
if [ "$malas" -eq 0 ]; then
  echo "RESULTADO: producción sana, 0 anomalías." >> "$O"
else
  echo "RESULTADO: $malas anomalía(s) — mira las marcas <-- de arriba." >> "$O"
fi
cat "$O"
exit "$([ "$malas" -eq 0 ] && echo 0 || echo 1)"
