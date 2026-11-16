#!/usr/bin/env bash
#
# Coordinación entre sesiones — índice acotado y verificación previa.
#
# Por qué existe: COORDINACION.md pasó de 300 KB y el 2026-09-05 dos sesiones
# depuraron el MISMO incidente durante horas porque ninguna leyó la fila de la
# otra. Un tablero que cuesta 2.800 líneas leer es un tablero que nadie lee.
# Aquí el estado vivo son ficheros pequeños, uno por asunto, y la lectura
# completa cabe en pantalla. COORDINACION.md sigue siendo la bitácora.
#
#   bash ops/coordinacion.sh abiertos              # una línea por asunto abierto
#   bash ops/coordinacion.sh para <sesión>         # sólo lo dirigido a ti
#   bash ops/coordinacion.sh ver <id>              # el asunto completo
#   bash ops/coordinacion.sh chequear <ruta...>    # ANTES de editar; sale !=0 si está reclamado
#   bash ops/coordinacion.sh cerrar <id>           # lo mueve a resueltos/
#
# Cabecera de cada asunto (`ops/coordinacion/abiertos/<id>.md`):
#   de:        sesión que escribe
#   para:      sesión destinataria, o "cualquiera"
#   estado:    abierto | esperando-respuesta | en-curso
#   archivos:  rutas separadas por espacios (las que NO se deben tocar sin hablar)
#   prueba:    comando que reproduce el asunto (vacío si no aplica)

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
abiertos="${script_dir}/coordinacion/abiertos"
resueltos="${script_dir}/coordinacion/resueltos"

die() { printf '%s\n' "$*" >&2; exit 2; }

campo() { # campo <fichero> <clave>
	sed -n "s/^$2:[[:space:]]*//p" "$1" | head -1
}

listar() { # listar [sesión]
	local filtro="${1:-}"
	local encontrados=0 f id
	[[ -d "${abiertos}" ]] || die "Falta ${abiertos}"
	shopt -s nullglob
	for f in "${abiertos}"/*.md; do
		local para de estado archivos
		para="$(campo "$f" para)"
		de="$(campo "$f" de)"
		estado="$(campo "$f" estado)"
		archivos="$(campo "$f" archivos)"
		if [[ -n "${filtro}" && "${para}" != *"${filtro}"* && "${para}" != 'cualquiera' ]]; then
			continue
		fi
		id="$(basename "$f" .md)"
		printf '%-46s  %-18s  de:%-22s para:%-22s\n' "${id}" "${estado}" "${de}" "${para}"
		printf '    archivos: %s\n' "${archivos:-—}"
		encontrados=$((encontrados + 1))
	done
	shopt -u nullglob
	if (( encontrados == 0 )); then
		printf 'Nada abierto%s.\n' "${filtro:+ para ${filtro}}"
	else
		printf '\n%d asunto(s). Detalle: bash ops/coordinacion.sh ver <id>\n' "${encontrados}"
	fi
}

chequear() { # chequear <ruta...>
	(( $# > 0 )) || die 'Uso: chequear <ruta...>'
	local choques=0 f ruta archivo
	shopt -s nullglob
	for f in "${abiertos}"/*.md; do
		local archivos de para
		archivos="$(campo "$f" archivos)"
		[[ -n "${archivos}" ]] || continue
		de="$(campo "$f" de)"
		para="$(campo "$f" para)"
		local -a items=()
		IFS=$' \t' read -r -a items <<< "${archivos}"
		for ruta in "$@"; do
			for archivo in "${items[@]}"; do
				[[ -n "${archivo}" ]] || continue
				if [[ "${ruta}" == *"${archivo}"* || "${archivo}" == *"${ruta}"* ]]; then
					printf 'RECLAMADO  %s\n  asunto: %s\n  de: %s   para: %s\n  lee:  bash ops/coordinacion.sh ver %s\n\n' \
						"${ruta}" "$(basename "$f" .md)" "${de}" "${para}" "$(basename "$f" .md)"
					choques=$((choques + 1))
				fi
			done
		done
	done
	shopt -u nullglob
	if (( choques > 0 )); then
		printf 'No edites sin hablar antes. %d choque(s).\n' "${choques}" >&2
		return 1
	fi
	printf 'Libre: %s\n' "$*"
}

case "${1:-abiertos}" in
	abiertos) listar ;;
	para)     [[ -n "${2:-}" ]] || die 'Uso: para <sesión>'; listar "$2" ;;
	ver)      [[ -n "${2:-}" ]] || die 'Uso: ver <id>'
	          [[ -f "${abiertos}/$2.md" ]] || die "No existe ${abiertos}/$2.md"
	          cat "${abiertos}/$2.md" ;;
	chequear) shift; chequear "$@" ;;
	cerrar)   [[ -n "${2:-}" ]] || die 'Uso: cerrar <id>'
	          [[ -f "${abiertos}/$2.md" ]] || die "No existe ${abiertos}/$2.md"
	          mkdir -p "${resueltos}"
	          mv "${abiertos}/$2.md" "${resueltos}/$2.md"
	          printf 'Cerrado: %s → ops/coordinacion/resueltos/%s.md\n' "$2" "$2" ;;
	*)        die "Orden desconocida: ${1}. Usa: abiertos | para <sesión> | ver <id> | chequear <ruta...> | cerrar <id>" ;;
esac
