#!/usr/bin/env bash
#
# correr-esceptico.sh — las sondas del ESCÉPTICO, EN SERIE.
#
# No se mezclan con `correr-bordes.sh` ni con `run-all.mjs` a propósito: son
# de otro autor y la regla de la casa prohíbe dos flujos sobre el mismo
# fichero. Se corren igual que las otras: de una en una, contra el único
# espejo (http://localhost:8080).
#
#   cd tools/akibara-releases/rewards-qa   # ahí vive node_modules
#   ./gate-bordes/correr-esceptico.sh
#
# Sale con el NÚMERO de sondas en rojo (0 = todo verde).
#
set -u

AQUI="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
QA_DIR="$( cd "$AQUI/.." && pwd )"
cd "$QA_DIR" || exit 1

SONDAS=(
	esceptico-01-triple-click-pedido-unico.mjs
	esceptico-02-renaca-valparaiso-medible.mjs
	esceptico-03-bis-avisame-hueco.mjs
	esceptico-04-cumpleanos-acredita-hueco.mjs
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
echo "  escéptico: $(( ${#SONDAS[@]} - ROJAS ))/${#SONDAS[@]} sondas en verde"
echo "════════════════════════════════════════════════════════════"
exit "$ROJAS"
