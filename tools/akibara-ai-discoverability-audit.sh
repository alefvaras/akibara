#!/usr/bin/env bash
#
# Auditor read-only de elegibilidad tecnica para asistentes IA.
#
# Este wrapper no acepta credenciales ni ejecuta metodos HTTP mutantes. El
# motor Node escribe las respuestas publicas en un temporal privado que se
# elimina siempre al terminar y entrega un unico reporte JSON por stdout.
#
# Exit codes:
#   0  todas las comprobaciones obligatorias pasaron
#   1  una o mas comprobaciones fallaron
#   2  no hubo fallos, pero si advertencias
#
set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
engine="${script_dir}/akibara-ai-discoverability-audit.mjs"
audit_tmp="$(mktemp -d "${TMPDIR:-/tmp}/akibara-ai-discoverability.XXXXXX")"

cleanup() {
	case "${audit_tmp}" in
		"${TMPDIR:-/tmp}"/akibara-ai-discoverability.*)
			rm -rf -- "${audit_tmp}"
			;;
		*)
			printf 'WARN: no se elimino un temporal con ruta inesperada: %s\n' "${audit_tmp}" >&2
			;;
	esac
}
trap cleanup EXIT HUP INT TERM

if ! command -v node >/dev/null 2>&1; then
	printf '%s\n' '{"schema_version":3,"tool":"akibara-ai-discoverability-audit","summary":{"status":"fail","exit_code":1,"message":"Falta el comando requerido: node"}}'
	exit 1
fi

if [[ ! -f "${engine}" || -L "${engine}" ]]; then
	printf '%s\n' '{"schema_version":3,"tool":"akibara-ai-discoverability-audit","summary":{"status":"fail","exit_code":1,"message":"Falta el motor regular akibara-ai-discoverability-audit.mjs"}}'
	exit 1
fi

set +e
node "${engine}" --work-dir "${audit_tmp}" "$@"
status=$?
set -e

case "${status}" in
	0|1|2) exit "${status}" ;;
	*)
		printf '%s\n' '{"schema_version":3,"tool":"akibara-ai-discoverability-audit","summary":{"status":"fail","exit_code":1,"message":"El motor termino con un codigo no contractual"}}'
		exit 1
		;;
esac
