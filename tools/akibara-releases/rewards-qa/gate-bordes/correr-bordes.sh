#!/usr/bin/env bash
#
# correr-bordes.sh — las seis sondas de BORDES DE COMPRA, EN SERIE.
#
# En serie a propósito: el espejo es uno solo, y dos Playwright a la vez
# comparten sesión de WooCommerce, se pisan el carrito y sus conteos de
# pedidos se cruzan. Nunca paralelizar esto.
#
# Uso:  bash tools/akibara-releases/rewards-qa/gate-bordes/correr-bordes.sh
# Sale != 0 si alguna sonda falla. Cada sonda restaura lo que siembra y
# comprueba antes = después.

set -uo pipefail

AQUI="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
QA_DIR="$( dirname "$AQUI" )"
cd "$QA_DIR" || exit 1

SONDAS=(
	sonda-bordes-comuna.mjs
	sonda-bordes-rut.mjs
	sonda-bordes-doble-click.mjs
	sonda-bordes-carrito-mixto.mjs
	sonda-bordes-canje-limite.mjs
	sonda-bordes-aviso-santiago.mjs
)

FALLIDAS=()

for sonda in "${SONDAS[@]}"; do
	printf '\n════════ %s ════════\n' "$sonda"
	if node "gate-bordes/${sonda}"; then
		printf '── %s: OK\n' "$sonda"
	else
		printf '── %s: FALLA\n' "$sonda"
		FALLIDAS+=( "$sonda" )
	fi
done

printf '\n════════ RESUMEN ════════\n'
if [ ${#FALLIDAS[@]} -eq 0 ]; then
	printf 'Las %d sondas pasaron.\n' "${#SONDAS[@]}"
	exit 0
fi

printf 'Sondas con fallas (%d/%d): %s\n' "${#FALLIDAS[@]}" "${#SONDAS[@]}" "${FALLIDAS[*]}"
exit 1
