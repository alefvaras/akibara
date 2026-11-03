#!/usr/bin/env bash
#
# Concatena el CSS del NÚCLEO del tema en un solo fichero servido.
#
# Por qué existe
# --------------
# Medido en producción el 2026-08-24 sobre la portada, móvil, HTTP/3: las 26
# hojas de estilo arrancan juntas a los ~120 ms y la ÚLTIMA termina a los
# 1.706 ms — para ficheros de 1 a 3 KB. No es ancho de banda: son 26 flujos
# compitiendo por la misma conexión. El TTFB era 101 ms y la página no quedaba
# interactiva hasta los 1.000 ms; ese hueco es casi todo CSS.
#
# `tokens`, `fonts`, `base`, `header` y `footer` se cargan en TODAS las páginas y
# en un orden fijo de dependencias, así que servirlas como cinco peticiones no
# compra nada: se mantienen separadas para poder editarlas, y se sirven juntas.
#
# NO se toca el `CSS Combine` de LiteSpeed: está apagado a propósito porque mata
# el `media=print` + `onload` con que el tema difiere sus hojas no críticas (ver
# la memoria «CSS Combine rompe el tema»). Esto es concatenación de código
# nuestro, en tiempo de build, y no altera qué hojas se difieren.
#
# El orden es el de dependencias declarado en inc/enqueue.php y NO es negociable:
# tokens define las variables que el resto consume.
#
# Al final genera las copias `.min` que el tema SIRVE (CSS y JS, y woocommerce.css
# partida por contexto) con build-theme-min-assets.mjs: ver su cabecera. Después
# de editar CUALQUIER hoja o guion del tema hay que correr este script;
# tests/min-assets.test.js (tema) falla con este mismo comando si alguien no lo hizo.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
theme_dir="${repo_root}/public_html/wp-content/themes/akibara-gp"
css_dir="${theme_dir}/assets/css"
out="${css_dir}/core.css"

sources=(tokens fonts base header footer)

for name in "${sources[@]}"; do
	if [[ ! -f "${css_dir}/${name}.css" ]]; then
		echo "Falta ${css_dir}/${name}.css" >&2
		exit 1
	fi
done

tmp="$(mktemp)"
trap 'rm -f "${tmp}"' EXIT

{
	echo "/*!"
	echo " * GENERADO — no editar a mano."
	echo " * Fuente: tools/akibara-releases/build-theme-core-css.sh"
	echo " * Orden de dependencias: ${sources[*]}"
	echo " * Edita los ficheros de origen y vuelve a ejecutar el script."
	echo " */"
	for name in "${sources[@]}"; do
		echo "/* --- ${name}.css --- */"
		cat "${css_dir}/${name}.css"
		echo
	done
} > "${tmp}"

mv "${tmp}" "${out}"
trap - EXIT
# mktemp crea el fichero con 0600; el resto del tema es 0644.
chmod 0644 "${out}"

printf 'core.css: %s ficheros, %s bytes\n' "${#sources[@]}" "$(wc -c < "${out}" | tr -d ' ')"

node "$(dirname "${BASH_SOURCE[0]}")/build-theme-min-assets.mjs"
