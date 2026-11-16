#!/usr/bin/env bash
#
# correr-superficies.sh — gate de SUPERFICIES Y CACHÉ, en SERIE.
#
# Las sondas comparten el espejo (http://localhost:8080) y una de ellas cambia
# la contraseña del cliente 229: dos a la vez se pisan. Se corren de una en una
# y el guion sale con el número de sondas en rojo.
#
#   cd tools/akibara-releases/rewards-qa   # ahí vive node_modules
#   ./gate-bordes/correr-superficies.sh
#
set -u

AQUI="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
QA_DIR="$( cd "$AQUI/.." && pwd )"
cd "$QA_DIR" || exit 1

SONDAS=(
	sonda-superficies-cards.mjs
	sonda-superficies-busqueda.mjs
	sonda-superficies-pjax-atras.mjs
	sonda-superficies-red-lenta.mjs
	sonda-superficies-frescura.mjs
	sonda-superficies-micuenta-movil.mjs
	sonda-superficies-404-anclas.mjs
)

ROJAS=0
for sonda in "${SONDAS[@]}"; do
	echo ""
	echo "════════════════════════════════════════════════════════════"
	echo "  $sonda"
	echo "════════════════════════════════════════════════════════════"
	node "$AQUI/$sonda"
	codigo=$?
	if [ "$codigo" -ne 0 ]; then
		ROJAS=$(( ROJAS + 1 ))
		echo "→ $sonda TERMINÓ EN ROJO (exit $codigo)"
	fi
done

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  gate de superficies: $(( ${#SONDAS[@]} - ROJAS ))/${#SONDAS[@]} sondas en verde"
echo "════════════════════════════════════════════════════════════"
exit "$ROJAS"
