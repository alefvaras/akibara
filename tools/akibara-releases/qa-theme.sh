#!/usr/bin/env bash
#
# qa-theme.sh — gate del ARTEFACTO VIVO del tema: el ZIP publicado en dist/
# pasa la suite actual del tema y los invariantes de empaquetado.
#
# Uso:
#   bash tools/akibara-releases/qa-theme.sh [version] [--candidate-dir RUTA]
#
# Sin argumento toma la `Version:` de style.css, que es la que el carril
# publica. La verdad de lo publicado es el ZIP, no el árbol (que mezcla trabajo
# sin terminar): por eso se prueba el ZIP extraído, con las pruebas del árbol
# copiadas encima.
#
# `--candidate-dir` toma el ZIP del TEMA (con su sidecar y su inventario) de otro
# directorio en vez de dist/; los plugins hermanos salen siempre de dist/. Lo usa
# test-packages.sh para pasar este gate a un ZIP recién construido del árbol.
#
# El ZIP se extrae con la MISMA estructura que el repo
# (`public_html/wp-content/{themes,plugins}`, y `tools/akibara-releases/` con lo
# que las pruebas importan de ahí): una prueba del tema que alcanza algo fuera
# del tema por ruta relativa resuelve igual aquí que en el árbol. Hasta el
# 2026-09-23 el ZIP iba a `wp-content/` suelto y min-assets.test.js, que importa
# el generador de las copias `.min`, no cargaba: el gate moría rojo en
# `node --test` (T4-R1).
#
# Hasta el 2026-09-11 este gate validaba el artefacto CONGELADO 2.1.48 con las
# pruebas del árbol actual. Sesenta y nueve versiones después esas pruebas
# describen otro tema (line-clamp de la intro de archivo, `blog.css`…) y el
# gate llevaba semanas en rojo sin que nadie lo corriera: un gate que nadie
# corre lleva rojo. Los artefactos congelados (superset 2.1.49, SEO 2.1.50)
# conservan sus gates de bytes e inventario en `qa-theme-production-superset.sh`
# y `qa-theme-seo-production.sh`; el comportamiento se prueba aquí, sobre el ZIP
# de la versión que de verdad se despliega.
#
# Las pruebas de acople (`cart-semantics`, `search-context`, `visual-polish`)
# leen los plugins hermanos por ruta relativa, así que se extraen también los
# ZIP publicados de Checkout Blocks, Search y Business en las versiones que
# declaran sus cabeceras.

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
theme_src="${repo_root}/public_html/wp-content/themes/akibara-gp"
plugins_src="${repo_root}/public_html/wp-content/plugins"
dist="${repo_root}/dist"

for command_name in node php shasum unzip; do
	command -v "${command_name}" >/dev/null 2>&1 || {
		echo "Falta el comando requerido para el gate del tema: ${command_name}" >&2
		exit 1
	}
done

header_version() {
	# `Version:` de una cabecera de plugin o tema; sale !=0 si no la encuentra.
	local file="$1"
	local version
	version="$(sed -nE 's/^[[:space:]*]*Version:[[:space:]]+([0-9]+\.[0-9]+\.[0-9]+).*$/\1/p' "${file}" | head -n1)"
	[[ -n "${version}" ]] || {
		echo "No encuentro la cabecera Version: en ${file}" >&2
		return 1
	}
	printf '%s\n' "${version}"
}

theme_version=""
candidate_dist="${dist}"
while (($#)); do
	case "$1" in
		--candidate-dir)
			[[ $# -ge 2 && -d "$2" ]] || {
				echo "--candidate-dir necesita un directorio existente." >&2
				exit 2
			}
			candidate_dist="$(cd -- "$2" && pwd)"
			shift 2
			;;
		-*)
			echo "Opción desconocida: $1" >&2
			exit 2
			;;
		*)
			[[ -z "${theme_version}" ]] || {
				echo "Sobra un argumento: $1" >&2
				exit 2
			}
			theme_version="$1"
			shift
			;;
	esac
done
theme_version="${theme_version:-$(header_version "${theme_src}/style.css")}"
[[ "${theme_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || {
	echo "Versión del tema no válida: ${theme_version}" >&2
	exit 2
}
checkout_version="$(header_version "${plugins_src}/akibara-cart-checkout-blocks/akibara-cart-checkout-blocks.php")"
search_version="$(header_version "${plugins_src}/akibara-search/akibara-search.php")"
business_version="$(header_version "${plugins_src}/akibara-business/akibara-business.php")"

test_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-theme-gate.XXXXXX")"
cleanup() {
	case "${test_root}" in
		*/akibara-theme-gate.*) rm -rf -- "${test_root}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

# Un ZIP publicado va con su sidecar SHA-256: se exige que coincidan.
extract_sealed() {
	local from="$1" slug="$2" version="$3" dest="$4"
	local archive="${from}/${slug}-${version}.zip"
	local sidecar="${archive}.sha256"
	[[ -f "${archive}" && ! -L "${archive}" ]] || {
		echo "Falta el ZIP publicado: ${archive}" >&2
		exit 1
	}
	[[ -f "${sidecar}" && ! -L "${sidecar}" ]] || {
		echo "Falta el sidecar SHA-256: ${sidecar}" >&2
		exit 1
	}
	( cd "${from}" && shasum -a 256 -c "$(basename -- "${sidecar}")" >/dev/null ) || {
		echo "El sidecar SHA-256 rechazó ${archive}" >&2
		exit 1
	}
	mkdir -p "${dest}"
	unzip -q "${archive}" -d "${dest}"
	[[ -d "${dest}/${slug}" && ! -L "${dest}/${slug}" ]] || {
		echo "${archive} no contiene la raíz esperada ${slug}/" >&2
		exit 1
	}
}

wp_content="${test_root}/public_html/wp-content"
extract_sealed "${candidate_dist}" akibara-gp "${theme_version}" "${wp_content}/themes"
extract_sealed "${dist}" akibara-cart-checkout-blocks "${checkout_version}" "${wp_content}/plugins"
extract_sealed "${dist}" akibara-search "${search_version}" "${wp_content}/plugins"
extract_sealed "${dist}" akibara-business "${business_version}" "${wp_content}/plugins"
candidate="${wp_content}/themes/akibara-gp"

# El inventario sellado describe cada fichero del ZIP.
inventory="${candidate_dist}/akibara-gp-${theme_version}.files.sha256"
[[ -f "${inventory}" && ! -L "${inventory}" ]] || {
	echo "Falta el inventario sellado: ${inventory}" >&2
	exit 1
}
actual_inventory="${test_root}/actual.files.sha256"
(
	cd "${wp_content}/themes"
	find akibara-gp -type f -print | LC_ALL=C sort |
		while IFS= read -r relative; do
			printf '%s  %s\n' "$(shasum -a 256 "${relative}" | awk '{print $1}')" "${relative}"
		done
) > "${actual_inventory}"
cmp -s "${inventory}" "${actual_inventory}" || {
	echo "El contenido extraído del tema no coincide con el inventario sellado." >&2
	exit 1
}

while IFS= read -r php_file; do
	php -l "${php_file}" >/dev/null
done < <(find "${candidate}" -type f -name '*.php' | LC_ALL=C sort)
while IFS= read -r js_file; do
	node --check "${js_file}"
done < <(find "${candidate}" -type f -name '*.js' | LC_ALL=C sort)

# Las pruebas viven en el árbol (el ZIP no las lleva): se copian TODAS encima
# del candidato y miden lo que hay en el ZIP.
mkdir -p "${candidate}/tests"
test_count=0
while IFS= read -r source_test; do
	[[ ! -L "${source_test}" ]] || {
		echo "Enlace simbólico en las pruebas del tema: ${source_test}" >&2
		exit 1
	}
	cp -- "${source_test}" "${candidate}/tests/"
	test_count=$((test_count + 1))
done < <(find "${theme_src}/tests" -maxdepth 1 -type f -name '*.test.js' | LC_ALL=C sort)
[[ "${test_count}" -gt 0 ]] || {
	echo "No hay pruebas *.test.js en ${theme_src}/tests: el gate no mediría nada." >&2
	exit 1
}

# Lo que las pruebas importan de tools/ viaja del árbol a la misma ruta relativa.
# Hoy es el generador de las copias `.min`: min-assets.test.js recalcula con él la
# huella de cada copia del ZIP. Es el mismo generador que construyó el ZIP; si no
# lo es, la prueba lo dice («se generó con otra versión»).
gate_tools=( "tools/akibara-releases/build-theme-min-assets.mjs" )
for relative in "${gate_tools[@]}"; do
	[[ -f "${repo_root}/${relative}" && ! -L "${repo_root}/${relative}" ]] || {
		echo "Falta ${relative} en el árbol: las pruebas del tema lo importan." >&2
		exit 1
	}
	mkdir -p "${test_root}/$(dirname -- "${relative}")"
	cp -- "${repo_root}/${relative}" "${test_root}/${relative}"
done
# Una prueba nueva que importe otra cosa de tools/ no puede dejar el gate rojo con
# un ERR_MODULE_NOT_FOUND: se nombra lo que falta y dónde declararlo.
missing_tools="$(
	{ grep -ohE "(from|import)[[:space:]]*\(?[[:space:]]*['\"](\.\./)+tools/[^'\"]+" "${candidate}/tests/"*.test.js || true; } |
		sed -E 's#^.*(tools/)#\1#' | LC_ALL=C sort -u |
		while IFS= read -r relative; do
			[[ -f "${test_root}/${relative}" ]] || printf '%s\n' "${relative}"
		done
)"
[[ -z "${missing_tools}" ]] || {
	echo "Las pruebas del tema importan de tools/ algo que el gate no copia (añádelo a gate_tools):" >&2
	echo "${missing_tools}" >&2
	exit 1
}

AKIBARA_EXPECTED_THEME_VERSION="${theme_version}" \
	node --test "${candidate}/tests/"*.test.js

rm -rf -- "${candidate}/tests"
AKIBARA_EXPECTED_VERSION="${theme_version}" \
	bash "${script_dir}/package-runtime.sh" theme \
	--source-dir "${candidate}" \
	--verify-only

echo "PASS: akibara-gp ${theme_version} (ZIP publicado) pasa ${test_count} ficheros de pruebas del tema y los invariantes de empaquetado (acoples: checkout ${checkout_version}, search ${search_version}, business ${business_version})"
