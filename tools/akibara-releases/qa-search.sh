#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
plugin_dir="${repo_root}/public_html/wp-content/plugins/akibara-search"
theme_dir="${repo_root}/public_html/wp-content/themes/akibara-gp"
search_phpunit_config="${repo_root}/tools/akibara-search/phpunit.xml.dist"
integration_dir="${repo_root}/tools/akibara-search-business"
integration_phpunit_config="${integration_dir}/phpunit.xml.dist"
phpunit_bin="${repo_root}/tools/akibara-business/vendor/bin/phpunit"
local_origin="http://127.0.0.1:8080"

die() {
	echo "$*" >&2
	exit 1
}

require_directory() {
	[[ -d "$1" ]] || die "Falta el directorio requerido para QA Search: $1"
}

require_file() {
	[[ -f "$1" ]] || die "Falta el archivo requerido para QA Search: $1"
}

# `set -e` no propaga el estado de un `find` dentro de una sustitución de
# proceso. El NUL vacío final funciona como testigo: sólo aparece si `find`
# terminó bien, y nunca puede confundirse con una ruta de archivo válida.
collect_files() {
	local root="$1"
	local pattern="$2"
	local find_completed=0
	local item=""

	collected_files=()
	while IFS= read -r -d '' item; do
		if [[ -z "${item}" ]]; then
			find_completed=1
			continue
		fi
		collected_files+=("${item}")
	done < <(
		find "${root}" -type f -name "${pattern}" ! -path '*/vendor/*' -print0 \
			&& printf '\0'
	)

	((find_completed == 1)) \
		|| die "No se pudo descubrir la suite QA bajo: ${root}"
}

tap_test_count() {
	awk '$1 == "#" && $2 == "tests" { count = $3 } END { print count + 0 }'
}

run_node_suite() {
	local label="$1"
	local tap_output=""
	local test_count=0
	shift

	if ! tap_output="$(node --test --test-reporter=tap "$@" 2>&1)"; then
		printf '%s\n' "${tap_output}"
		die "Falló la suite Node de ${label}"
	fi

	printf '%s\n' "${tap_output}"
	test_count="$(tap_test_count <<<"${tap_output}")"
	((test_count > 0)) || die "Node ejecutó cero tests de ${label}"
}

phpunit_test_count() {
	awk '
		$1 == "OK" && $2 ~ /^\([0-9]+$/ {
			gsub(/[^0-9]/, "", $2)
			count = $2
		}
		$1 == "Tests:" && $2 ~ /^[0-9]+,/ {
			gsub(/[^0-9]/, "", $2)
			count = $2
		}
		END { print count + 0 }
	'
}

run_phpunit_suite() {
	local label="$1"
	local workdir="$2"
	local configuration="$3"
	local phpunit_output=""
	local test_count=0

	if ! phpunit_output="$(
		docker run --rm --network none --read-only --tmpfs /tmp \
			--user "$(id -u):$(id -g)" \
			--volume "${repo_root}:/workspace:ro" \
			--workdir "${workdir}" \
			--entrypoint php wordpress:php8.4-apache \
			/workspace/tools/akibara-business/vendor/bin/phpunit \
			--configuration "${configuration}" \
			--colors=never 2>&1
	)"; then
		printf '%s\n' "${phpunit_output}"
		die "Falló la suite PHPUnit de ${label}"
	fi

	printf '%s\n' "${phpunit_output}"
	test_count="$(phpunit_test_count <<<"${phpunit_output}")"
	((test_count > 0)) || die "PHPUnit ejecutó cero tests de ${label}"
}

for command_name in awk curl docker find grep id jq node; do
	if ! command -v "${command_name}" >/dev/null 2>&1; then
		echo "Falta el comando requerido para QA Search: ${command_name}" >&2
		exit 1
	fi
done

for required_directory in \
	"${plugin_dir}/assets" \
	"${plugin_dir}/tests" \
	"${theme_dir}/tests" \
	"${integration_dir}/tests"; do
	require_directory "${required_directory}"
done

for required_file in \
	"${repo_root}/compose.yaml" \
	"${plugin_dir}/akibara-search.php" \
	"${plugin_dir}/tests/bootstrap.php" \
	"${theme_dir}/inc/flyout-button.php" \
	"${theme_dir}/tests/shop-toolbar-layout.test.js" \
	"${search_phpunit_config}" \
	"${integration_dir}/bootstrap.php" \
	"${integration_phpunit_config}" \
	"${phpunit_bin}" \
	"${script_dir}/qa-search-mariadb.sh"; do
	require_file "${required_file}"
done

collect_files "${plugin_dir}/assets" '*.js'
search_js_sources=("${collected_files[@]}")
((${#search_js_sources[@]} > 0)) || die "Search no tiene fuentes JavaScript para validar"

collect_files "${plugin_dir}/tests" '*.test.js'
search_js_tests=("${collected_files[@]}")
((${#search_js_tests[@]} > 0)) || die "Search no tiene tests JavaScript"

collect_files "${plugin_dir}/tests" '*Test.php'
search_php_tests=("${collected_files[@]}")
((${#search_php_tests[@]} > 0)) || die "Search no tiene tests PHPUnit"

collect_files "${integration_dir}/tests" '*Test.php'
integration_php_tests=("${collected_files[@]}")
((${#integration_php_tests[@]} > 0)) \
	|| die "Search -> Business no tiene tests PHPUnit de integración"

if ! docker compose -f "${repo_root}/compose.yaml" ps --status running wordpress \
	| grep -q 'wordpress'; then
	echo "El contenedor WordPress no está activo. Ejecuta: docker compose up -d" >&2
	exit 1
fi

echo "PHP lint de Akibara Search en Docker..."
docker compose -f "${repo_root}/compose.yaml" exec -T wordpress sh -lc \
	"test -f /var/www/html/wp-content/plugins/akibara-search/akibara-search.php \
		&& find /var/www/html/wp-content/plugins/akibara-search \
		-type f -name '*.php' ! -path '*/vendor/*' \
		-exec php -l {} \\; >/dev/null"
docker compose -f "${repo_root}/compose.yaml" exec -T wordpress php -l \
	/var/www/html/wp-content/themes/akibara-gp/inc/flyout-button.php >/dev/null

echo "Sintaxis JavaScript y tests de Search..."
for js_file in "${search_js_sources[@]}"; do
	node --check "${js_file}" >/dev/null
done

run_node_suite "Search" "${search_js_tests[@]}"
run_node_suite "Theme shop toolbar" "${theme_dir}/tests/shop-toolbar-layout.test.js"

echo "Regresiones PHP de Search en Docker aislado..."
run_phpunit_suite \
	"Search" \
	/workspace/tools/akibara-search \
	/workspace/tools/akibara-search/phpunit.xml.dist

echo "Contrato integrado Search -> Business con clases reales..."
run_phpunit_suite \
	"Search -> Business" \
	/workspace/tools/akibara-search-business \
	/workspace/tools/akibara-search-business/phpunit.xml.dist

echo "Integración MariaDB de rebuild/ledger sobre clones efímeros..."
mariadb_output=""
if ! mariadb_output="$(bash "${script_dir}/qa-search-mariadb.sh" 2>&1)"; then
	printf '%s\n' "${mariadb_output}" >&2
	die 'Falló la integración MariaDB de Search'
fi
printf '%s\n' "${mariadb_output}"
grep -Fqx \
	'PASS: control baseline y candidato v2 repetidos dos veces en clones efímeros; DB principal y estado de cron intactos' \
	<<< "${mariadb_output}" \
	|| die 'La integración MariaDB terminó sin su receipt exacto de éxito'

echo "Smoke HTTP del motor Search..."
suggest_payload="$(
	curl --silent --show-error \
		--connect-timeout 10 --max-time 30 \
		--retry 2 --retry-delay 1 --retry-all-errors \
		"${local_origin}/wp-json/akibara/v1/search/suggest?q=one%20piece"
)"
jq -e '
  type == "object"
  and .query == "one piece"
  and (.total | type == "number" and floor == . and . > 0)
  and (.items | type == "array" and length > 0 and length <= 7)
  and ([.items[]] | all(
    (.title | type == "string" and length > 0)
    and (.url | type == "string" and length > 0)
    and (.badge | type == "string" and length > 0)
    and (.badge_tone | type == "string" and length > 0)
  ))
' <<<"${suggest_payload}" >/dev/null

count_total() {
	curl --silent --show-error \
		--connect-timeout 10 --max-time 30 \
		--retry 2 --retry-delay 1 --retry-all-errors \
		--get --data-urlencode "$1=$2" \
		"${local_origin}/wp-json/akibara/v1/search/count" \
		| jq -er '.total | select(type == "number" and floor == .)'
}

opm_count="$(count_total '_buscar' 'one punch man')"
opm_alias_count="$(count_total '_buscar' 'opm')"
opm_series_count="$(count_total '_serie' 'one-punch-man')"
opm_volume_count="$(count_total '_buscar' 'one punch man 03')"
atelier_count="$(count_total '_buscar' 'atelier')"
atalier_count="$(count_total '_buscar' 'atalier')"
aot_count="$(count_total '_buscar' 'aot')"
aot_phrase_count="$(count_total '_buscar' 'attack on titan')"

[[ "${opm_count}" -gt 0 && "${opm_count}" == "${opm_alias_count}"
	&& "${opm_count}" == "${opm_series_count}" ]] \
	|| {
		echo "La búsqueda OPM no coincide con el universo real de la serie." >&2
		exit 1
	}
[[ "${opm_volume_count}" -gt 0 && "${opm_volume_count}" -lt "${opm_count}" ]] \
	|| {
		echo "El número de tomo dejó de acotar la búsqueda OPM." >&2
		exit 1
	}
[[ "${atelier_count}" -gt 0 && "${atelier_count}" == "${atalier_count}" ]] \
	|| {
		echo "La corrección fuzzy atalier→atelier perdió paridad." >&2
		exit 1
	}
[[ "${aot_count}" -gt 0 && "${aot_count}" == "${aot_phrase_count}" ]] \
	|| {
		echo "Los sinónimos AOT dejaron de conservar límites de frase." >&2
		exit 1
	}

opm_payload="$(
	curl --silent --show-error \
		--connect-timeout 10 --max-time 30 \
		--retry 2 --retry-delay 1 --retry-all-errors \
		--get --data-urlencode 'q=one punch man' \
		"${local_origin}/wp-json/akibara/v1/search/suggest"
)"
jq -e --argjson expected "${opm_count}" '
  .total == $expected
  and (.items | type == "array" and length > 0 and length <= 7)
  and ([.items[].title] | all(test("^One Punch-Man ")))
' <<<"${opm_payload}" >/dev/null

opm_shop="$(
	curl --silent --show-error \
		--connect-timeout 10 --max-time 30 \
		--retry 2 --retry-delay 1 --retry-all-errors \
		--get --data-urlencode '_buscar=one punch man' \
		"${local_origin}/tienda/"
)"
# CANDADO CADUCO RETIRADO (2026-09-01). Aquí se exigía
# `data-akb-unit="producto"` en el HTML servido, y ese atributo se BORRÓ a
# propósito junto con `data-akb-count`: no lo leía ningún JS y estaba clavado en
# singular (frontend/facets-render.php:678, y el candado del contrato lo fija en
# search-runtime-contract.test.js:159-163, que exige que NO vuelva). El gate
# seguía pidiendo el literal viejo, así que fallaba por una decisión de diseño
# ya tomada — la trampa de siempre: fijar el LITERAL en vez de la invariante.
# Lo que de verdad importa se sigue midiendo sobre la superficie servida: que el
# nodo estable del conteo (`.akb-result-count`, el target del swap PJAX) exista
# y anuncie la unidad en productos.
grep -Eq '<p class="akb-result-count">[0-9]+ productos?<' <<<"${opm_shop}" \
	|| {
		echo "El shop no conserva el nodo de conteo con la unidad producto en un resultado exclusivamente Manga." >&2
		exit 1
	}

pack_shop="$(
	curl --silent --show-error \
		--connect-timeout 10 --max-time 30 \
		--retry 2 --retry-delay 1 --retry-all-errors \
		--get --data-urlencode '_buscar=pack vinland saga' \
		"${local_origin}/tienda/"
)"
grep -Fq '>1 producto<' <<<"${pack_shop}" \
	|| {
		echo "La regla de catálogo pack=1 producto no está reflejada en el contador." >&2
		exit 1
	}

zero_shop="$(
	curl --silent --show-error \
		--connect-timeout 10 --max-time 30 \
		--retry 2 --retry-delay 1 --retry-all-errors \
		--get --data-urlencode '_buscar=zzzzzzzzzzzz' \
		"${local_origin}/tienda/"
)"
grep -Fq 'akb-shop-toolbar--empty' <<<"${zero_shop}" \
	&& grep -Fq 'class="akb-filter-toggle"' <<<"${zero_shop}" \
	|| {
		echo "La recuperación de cero resultados perdió toolbar o botón Filtrar." >&2
		exit 1
	}

echo "PASS: lint PHP/JS, tests y búsqueda HTTP real de Akibara Search"
