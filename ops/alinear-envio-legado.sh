#!/usr/bin/env bash
# Alinea envío = facturación en las cuentas anteriores a checkout-blocks 2.2.31.
# Ver ops/alinear-envio-legado.php (qué toca y por qué).
#
# Uso:
#   bash ops/alinear-envio-legado.sh listar   [produccion|espejo]   # sólo lectura
#   bash ops/alinear-envio-legado.sh aplicar  [produccion|espejo]   # respalda en local, alinea, re-cuenta
#   bash ops/alinear-envio-legado.sh deshacer <respaldo.json> [produccion|espejo]
#
# El respaldo queda SÓLO en este equipo: .local-backups/alinear-envio-legado-<fecha>/respaldo.json
set -euo pipefail

modo="${1:-listar}"
aqui="$(cd "$(dirname "$0")" && pwd)"
raiz="$(cd "${aqui}/.." && pwd)"
php="${aqui}/alinear-envio-legado.php"

if [ "${modo}" = "deshacer" ]; then
	respaldo="${2:?falta el respaldo.json}"
	destino="${3:-produccion}"
else
	respaldo=""
	destino="${2:-produccion}"
fi

# Ejecuta el PHP en el destino. $1 = argumentos para eval-file; $2 = fichero extra opcional (se sube a /tmp).
correr() {
	local argumentos="$1" extra="${2:-}"
	case "${destino}" in
		produccion)
			if [ -n "${extra}" ]; then
				# Dos ficheros por un solo stdin: el PHP y el JSON, separados por una marca.
				{ cat "${php}"; printf '\n__AKB_JSON__\n'; cat "${extra}"; } | ssh -T -o BatchMode=yes akibara \
					"d=\$(mktemp -d /tmp/akb-alinear-XXXXXX); awk -v d=\"\$d\" '/^__AKB_JSON__\$/{f=1;next} {print > (d (f?\"/r.json\":\"/a.php\"))}'; cd ~/domains/akibara.cl/public_html && wp eval-file \"\$d/a.php\" ${argumentos} \"\$d/r.json\"; rc=\$?; rm -rf \"\$d\"; exit \$rc"
			else
				ssh -T -o BatchMode=yes akibara \
					"f=\$(mktemp /tmp/akb-alinear-XXXXXX.php); cat > \"\$f\"; cd ~/domains/akibara.cl/public_html && wp eval-file \"\$f\" ${argumentos}; rc=\$?; rm -f \"\$f\"; exit \$rc" < "${php}"
			fi
			;;
		espejo)
			local tmp="akb-alinear-$$.php" json="akb-alinear-$$.json"
			cp "${php}" "${raiz}/public_html/${tmp}"
			[ -n "${extra}" ] && cp "${extra}" "${raiz}/public_html/${json}"
			local rc=0
			(cd "${raiz}" && docker compose exec -T cron wp eval-file "${tmp}" ${argumentos} ${extra:+${json}} 2>/dev/null) || rc=$?
			rm -f "${raiz}/public_html/${tmp}" "${raiz}/public_html/${json}"
			return ${rc}
			;;
		*) echo "destino desconocido: ${destino}" >&2; return 2 ;;
	esac
}

case "${modo}" in
	listar)
		correr listar
		;;
	aplicar)
		dir="${raiz}/.local-backups/alinear-envio-legado-${destino}-$(date +%Y%m%d%H%M%S)"
		mkdir -p "${dir}"
		correr respaldo | grep '^{' > "${dir}/respaldo.json"
		python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); assert isinstance(d,(dict,list)); print("respaldo:", len(d), "cuentas")' "${dir}/respaldo.json" \
			|| { echo "respaldo inválido: no se aplica nada" >&2; exit 1; }
		echo "antes:   $(correr listar)"
		echo "aplicar: $(correr aplicar)"
		echo "después: $(correr listar)"
		echo "deshacer con: bash ops/alinear-envio-legado.sh deshacer ${dir}/respaldo.json ${destino}"
		;;
	deshacer)
		correr restaurar "${respaldo}"
		echo "después: $(correr listar)"
		;;
	*)
		echo "uso: bash ops/alinear-envio-legado.sh listar|aplicar [produccion|espejo] · deshacer <respaldo.json> [produccion|espejo]" >&2
		exit 2
		;;
esac
