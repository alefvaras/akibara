#!/usr/bin/env bash
# Despliegue de akibara-business 1.111.0 (claridad de plazos «a pedido» +
# fix del fallback por país). Riel sellado: subir → verificar hash → swap
# atómico con ventana de mantenimiento → purga → verificación servida.
# Gate QA de 4 agentes + suite 1985/6107 en VERDE el 2026-08-31.
set -euo pipefail

DIST="/Users/alefvaras/Documents/akibara/dist"
ZIP="akibara-business-1.111.0.zip"
REMOTO="akibara"
WEB="domains/akibara.cl/public_html"
PLUGINS="$WEB/wp-content/plugins"
TS="$(date +%s)"

echo "== 1/6 Subiendo $ZIP =="
ssh "$REMOTO" "mkdir -p ~/deploy"
scp -q "$DIST/$ZIP" "$REMOTO:deploy/$ZIP"

echo "== 2/6 Verificando sha256 remoto =="
LOCAL_SHA="$(cut -d' ' -f1 "$DIST/$ZIP.sha256")"
REMOTE_SHA="$(ssh "$REMOTO" "sha256sum deploy/$ZIP" | cut -d' ' -f1)"
if [ "$LOCAL_SHA" != "$REMOTE_SHA" ]; then
  echo "SHA NO COINCIDE: local=$LOCAL_SHA remoto=$REMOTE_SHA" >&2
  exit 1
fi
echo "sha256 OK: $REMOTE_SHA"

echo "== 3/6 Preparando staging remoto =="
ssh "$REMOTO" "rm -rf deploy/stage-1.111.0 && mkdir -p deploy/stage-1.111.0 && cd deploy/stage-1.111.0 && unzip -q ../$ZIP && test -f akibara-business/akibara-business.php"

echo "== 4/6 Swap atómico (ventana de mantenimiento) =="
ssh "$REMOTO" "printf '<?php \$upgrading = time();' > $WEB/.maintenance \
  && mv $PLUGINS/akibara-business $PLUGINS/.akibara-business-old-$TS \
  && mv deploy/stage-1.111.0/akibara-business $PLUGINS/akibara-business \
  && rm -f $WEB/.maintenance"
echo "swap hecho (respaldo: .akibara-business-old-$TS)"

echo "== 5/6 Purga de cachés (LiteSpeed + Cloudflare, in-process) =="
ssh "$REMOTO" "cd $WEB && wp eval 'do_action(\"litespeed_purge_all\");' 2>/dev/null || true"

echo "== 6/6 Verificación de la superficie SERVIDA =="
ssh "$REMOTO" "grep -m1 'Version:' $PLUGINS/akibara-business/akibara-business.php"
FRASE="$(curl -s https://akibara.cl/a-pedido/ | grep -o 'Sale en el pedido que cierra[^<]*' | head -1 || true)"
FICHA="$(curl -s "$(curl -s https://akibara.cl/a-pedido/ | grep -o 'https://akibara.cl/product/[^"]*' | head -1)" | grep -o 'Sale en el pedido que cierra[^<]*' | head -1 || true)"
echo "grilla:  ${FRASE:-'(la grilla no lleva la nota: correcto)'}"
echo "ficha:   ${FICHA:-'SIN FRASE NUEVA — REVISAR ANTES DE CERRAR'}"
echo "error_log fresco:"
ssh "$REMOTO" "tail -3 $WEB/wp-content/debug.log 2>/dev/null || tail -3 ~/error_log 2>/dev/null || echo '(sin log nuevo)'"
echo
echo "Respaldos antiguos pendientes de limpieza (bórralos cuando confirmes que todo va bien):"
ssh "$REMOTO" "ls -d $PLUGINS/.akibara-*-old-* 2>/dev/null || echo '(ninguno)'"
echo "== LISTO =="
