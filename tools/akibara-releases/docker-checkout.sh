#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
plugin_dir="${repo_root}/public_html/wp-content/plugins/akibara-cart-checkout-blocks"
business_dir="${repo_root}/public_html/wp-content/plugins/akibara-business"

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

for command_name in docker node rg; do
	if ! command -v "${command_name}" >/dev/null 2>&1; then
		echo "Falta el comando requerido para el gate local de Checkout Blocks: ${command_name}" >&2
		exit 1
	fi
done

if ! docker compose -f "${repo_root}/compose.yaml" ps --status running wordpress \
	| rg -q 'wordpress'; then
	echo "El contenedor WordPress no está activo. Ejecuta: docker compose up -d" >&2
	exit 1
fi

# Estas aserciones corrían "desnudas" bajo `set -e`: un literal de versión
# desactualizado abortaba el gate SIN IMPRIMIR NADA, y diagnosticarlo costaba
# más que arreglarlo (auditoría 2026-07-28). Ahora cada una dice qué falló.
#
# Y el literal en sí era la otra mitad de la trampa: el gate se quedó clavado
# en 2.2.6 y murió en su primera aserción durante once releases (hasta
# 2.2.17), así que las comprobaciones que SÓLO viven aquí —la frontera con
# Business, la integración Blocks vieja, los artefactos— no corrieron en el
# riel documentado y cada corte se firmó «con pasos equivalentes a mano». Lo
# que hay que fijar es la invariante (cabecera == constante, y con forma de
# versión), no el número: es lo mismo que ya hace
# PluginContractTest::test_version_and_dependencies_are_explicit. El `|| true`
# dentro de cada sustitución es a propósito: con `pipefail` un `rg` sin
# coincidencia mataría el script antes de llegar al `die` que explica.
plugin_main="${plugin_dir}/akibara-cart-checkout-blocks.php"
header_version="$(
	rg --no-line-number -o -r '$1' \
		'^[[:space:]]*\*[[:space:]]*Version:[[:space:]]+([0-9]+\.[0-9]+\.[0-9]+)[[:space:]]*$' \
		"${plugin_main}" | head -n 1 || true
)"
constant_version="$(
	rg --no-line-number -o -r '$1' \
		"define\\( 'AKIBARA_CART_CHECKOUT_BLOCKS_VERSION', '([0-9]+\\.[0-9]+\\.[0-9]+)' \\);" \
		"${plugin_main}" | head -n 1 || true
)"
[[ -n "${header_version}" ]] \
	|| die "la cabecera Version de Blocks no tiene forma X.Y.Z (o no está en ${plugin_main})"
[[ -n "${constant_version}" ]] \
	|| die "la constante AKIBARA_CART_CHECKOUT_BLOCKS_VERSION no tiene forma X.Y.Z (o no está en ${plugin_main})"
[[ "${header_version}" == "${constant_version}" ]] \
	|| die "la cabecera de Blocks dice ${header_version} y la constante ${constant_version}: tienen que ir juntas"
echo "Versión de Cart & Checkout Blocks: ${header_version} (cabecera y constante coinciden)"
rg -q '^[[:space:]]*\*[[:space:]]*Requires Plugins:[[:space:]]+woocommerce,[[:space:]]*akibara-business[[:space:]]*$' \
	"${plugin_dir}/akibara-cart-checkout-blocks.php" \
	|| die "el header Requires Plugins de Blocks perdió woocommerce o akibara-business"

if rg -n 'use[[:space:]]+Akibara\\Business\\(Modules|Services|Utilities)\\' \
	"${plugin_dir}/src"; then
	echo "Checkout Blocks depende de una implementación interna de Business." >&2
	exit 1
fi

if rg -n 'woocommerce_store_api_register_endpoint_data|Automattic\\WooCommerce\\StoreApi' \
	"${business_dir}/src"; then
	echo "Business todavía contiene una integración directa con Woo Blocks/Store API." >&2
	exit 1
fi

for removed_runtime in \
	"${business_dir}/src/Modules/OrderPreventa/BlocksIntegration.php" \
	"${business_dir}/assets/js/order-preventa-blocks.js" \
	"${business_dir}/assets/js/series-bundles-blocks.js"; do
	if [[ -e "${removed_runtime}" ]]; then
		echo "La integración Blocks antigua todavía existe: ${removed_runtime}" >&2
		exit 1
	fi
done

echo "PHP lint de Cart & Checkout Blocks..."
docker compose -f "${repo_root}/compose.yaml" exec -T wordpress sh -lc \
	"find /var/www/html/wp-content/plugins/akibara-cart-checkout-blocks \
		-type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null"

echo "Sintaxis JavaScript de Cart & Checkout Blocks..."
while IFS= read -r -d '' js_file; do
	node --check "${js_file}" >/dev/null
done < <(find "${plugin_dir}/assets" -type f -name '*.js' -print0)

source_artifacts="$(
	find "${plugin_dir}" \
		\( -path '*/tests' \) -prune -o \
		-type f \
		\( -name '*.bak' -o -name '*.bk' -o -name '*.orig' -o -name '*.rej' \
			-o -name '*.tmp' -o -name '*.temp' -o -name '*.dump' \
			-o -name '*.backup' -o -name '*.sql' -o -name '*.sqlite' \
			-o -name '*.tar' -o -name '*.tgz' -o -name '*.zip' -o -name '*~' \
			-o -name '.DS_Store' -o -name '.phpunit.result.cache' \
			-o -name 'coverage.xml' -o -name 'debug.log' -o -name 'error_log' \) \
		-print
)"
if [[ -n "${source_artifacts}" ]]; then
	echo "Checkout Blocks contiene respaldos o artefactos temporales:" >&2
	echo "${source_artifacts}" >&2
	exit 1
fi

checkout_tests=("${plugin_dir}"/tests/*.test.js)
if [[ ! -f "${checkout_tests[0]}" ]]; then
	echo "No se encontraron pruebas Node de Checkout Blocks." >&2
	exit 1
fi

echo "Pruebas Node de Cart & Checkout Blocks..."
node --test "${checkout_tests[@]}"

echo "Pruebas PHP de Business + Cart & Checkout Blocks..."
docker run --rm \
	--user "$(id -u):$(id -g)" \
	-v "${repo_root}:/workspace" \
	-w /workspace/tools/akibara-business \
	--entrypoint php \
	wordpress:php8.4-apache \
	/workspace/tools/akibara-business/vendor/bin/phpunit \
	--configuration /workspace/tools/akibara-business/phpunit.xml.dist

echo "Contrato del paquete Cart & Checkout Blocks..."
bash "${script_dir}/package-checkout.sh" --verify-only

echo "Gate local de Cart & Checkout Blocks completado."
