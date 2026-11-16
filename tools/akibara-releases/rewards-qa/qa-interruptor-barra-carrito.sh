#!/bin/bash
# QA · ¿el interruptor «Mostrar en carrito» apaga de verdad la barra del carrito
# de bloques? Enciende, apaga y restaura. Toca UNA option de producción.
set -u
cd /Users/alefvaras/Documents/akibara || exit 1
cat > /tmp/qa-sw.sh <<'EOF'
cd ~/domains/akibara.cl/public_html || exit 1
case "$1" in
  leer)  /usr/local/bin/wp option get akibara_fsp_show_cart ;;
  poner) /usr/local/bin/wp option update akibara_fsp_show_cart "$2" >/dev/null && echo "puesto a $2" ;;
esac
EOF
sw() { ssh akibara 'bash -s' -- "$@" < /tmp/qa-sw.sh; }

ORIG=$(sw leer)
echo "valor original: $ORIG"
echo "NOTA: la barra la pinta el carrito de BLOQUES en el navegador; este guion sólo"
echo "      mueve el interruptor. La comprobación visual la hago desde el navegador."
echo
echo "→ apagando"
sw poner no
echo "→ ahora mira el carrito en el navegador y vuelve a ejecutar con 'restaurar'"
echo "   para dejarlo en: $ORIG"
echo "$ORIG" > /tmp/qa-sw-original.txt
