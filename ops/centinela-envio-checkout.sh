#!/usr/bin/env bash
# Centinela de SÓLO LECTURA: ¿la caja cotiza con la dirección que ve el cliente?
# Ver ops/centinela-envio-checkout.php (qué mide y por qué).
#
# Uso: bash ops/centinela-envio-checkout.sh [produccion|espejo] [desde_unix]
#   desde_unix: hora del despliegue de checkout-blocks 2.2.31 (por defecto, la
#   anotada abajo). Sale 0 con OK/AVISO y 1 con ALERTA.
set -euo pipefail

destino="${1:-produccion}"
# Despliegue de checkout-blocks 2.2.31 en producción (se fija al desplegar).
desde="${2:-${AKB_CENTINELA_DESDE:-1790621718}}"
aqui="$(cd "$(dirname "$0")" && pwd)"
raiz="$(cd "${aqui}/.." && pwd)"
php="${aqui}/centinela-envio-checkout.php"

case "${destino}" in
	produccion)
		# El fichero viaja por stdin a /tmp (nunca al docroot) y se borra pase lo que pase.
		salida="$(ssh -T -o BatchMode=yes akibara "f=\$(mktemp /tmp/akb-centinela-XXXXXX.php); cat > \"\$f\"; cd ~/domains/akibara.cl/public_html && wp eval-file \"\$f\" ${desde}; rc=\$?; rm -f \"\$f\"; exit \$rc" < "${php}")"
		;;
	espejo)
		tmp="akb-centinela-$$.php"
		cp "${php}" "${raiz}/public_html/${tmp}"
		trap 'rm -f "${raiz}/public_html/${tmp}"' EXIT
		salida="$(cd "${raiz}" && docker compose exec -T cron wp eval-file "${tmp}" "${desde}" 2>/dev/null)"
		;;
	*)
		echo "destino desconocido: ${destino} (produccion|espejo)" >&2
		exit 2
		;;
esac

printf '%s\n' "${salida}"
grep -q '^VEREDICTO: ALERTA' <<< "${salida}" && exit 1
exit 0
