#!/bin/bash
# QA · bordes del buscador. Solo lee. No escribe nada en el sitio.
set -u
B=https://akibara.cl
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 QA-akibara"

med() {
  ETIQ="$1"; URL="$2"
  OUT=$(curl -sL -A "$UA" -w '\n@@%{http_code}@@%{time_total}@@%{size_download}@@%{url_effective}' "$URL")
  COD=$(printf '%s' "$OUT" | tail -1 | awk -F'@@' '{print $2}')
  TIM=$(printf '%s' "$OUT" | tail -1 | awk -F'@@' '{print $3}')
  SIZ=$(printf '%s' "$OUT" | tail -1 | awk -F'@@' '{print $4}')
  FIN=$(printf '%s' "$OUT" | tail -1 | awk -F'@@' '{print $5}' | sed "s|https://akibara.cl||")
  CUERPO=$(printf '%s' "$OUT" | sed '$d')
  # nº de tarjetas de producto y señales de error
  N=$(printf '%s' "$CUERPO" | grep -o 'add-to-cart=[0-9]*' | sort -u | wc -l | tr -d ' ')
  FATAL=$(printf '%s' "$CUERPO" | grep -ci 'fatal error\|Warning:\|Notice:\|Deprecated:' | tr -d ' ')
  SINRES=$(printf '%s' "$CUERPO" | grep -ci 'no encontramos\|sin resultados\|no hay resultados\|no se encontraron' | tr -d ' ')
  printf '%-34s http=%-4s %6ss %8sB  prod=%-4s warn=%-3s vacio=%-3s %s\n' "$ETIQ" "$COD" "$TIM" "$SIZ" "$N" "$FATAL" "$SINRES" "${FIN:0:60}"
}

echo "=== BORDES DEL BUSCADOR ==="
med "control: naruto"                 "$B/?s=naruto&post_type=product"
med "vacía"                           "$B/?s=&post_type=product"
med "un solo carácter: a"             "$B/?s=a&post_type=product"
med "un solo carácter: ñ"             "$B/?s=%C3%B1&post_type=product"
med "500 caracteres"                  "$B/?s=$(python3 -c "print('a'*500)")&post_type=product"
med "acentos: pokemon"                "$B/?s=pokemon&post_type=product"
med "acentos: pokémon"                "$B/?s=pok%C3%A9mon&post_type=product"
med "ñ: nino"                         "$B/?s=nino&post_type=product"
med "ñ: niño"                         "$B/?s=ni%C3%B1o&post_type=product"
med "editorial inexistente"           "$B/?s=editorialquenoexiste123&post_type=product"
med "orderby basura"                  "$B/?s=naruto&post_type=product&orderby=;DROP"
med "orderby inyección"               "$B/?s=naruto&post_type=product&orderby=price%29%20UNION%20SELECT"
med "order basura"                    "$B/?s=naruto&post_type=product&order=BASURA"
med "paged negativo"                  "$B/?s=naruto&post_type=product&paged=-1"
med "paged enorme"                    "$B/?s=naruto&post_type=product&paged=999999"
med "comillas y <script>"             "$B/?s=%22%3Cscript%3Ealert(1)%3C/script%3E&post_type=product"
med "porcentaje suelto"               "$B/?s=%25&post_type=product"
med "guion bajo y comodines SQL"      "$B/?s=%25_%25&post_type=product"
