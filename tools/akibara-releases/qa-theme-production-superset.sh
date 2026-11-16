#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
archive="${repo_root}/dist/akibara-gp-2.1.49.zip"
expected_archive_sha="e8c5ce31a5cbf1e0e49acba4dd5ba5873fdd0314d70b55c75930e40efe0f983e"
checkout_archive="${repo_root}/dist/akibara-cart-checkout-blocks-2.0.1.zip"
expected_checkout_sha="bf9ebceb16964952a309fffeb21ea372032b7a2c5444d217f0c183f65d0b4999"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-theme-production-artifact-gate.XXXXXX")"

cleanup() {
	case "${test_root}" in
		*/akibara-theme-production-artifact-gate.*) rm -rf -- "${test_root}" ;;
	esac
}
trap cleanup EXIT

for command_name in php shasum unzip; do
	command -v "${command_name}" >/dev/null 2>&1 || {
		echo "Falta el comando requerido para validar localmente el superset: ${command_name}" >&2
		exit 1
	}
done

[[ -f "${archive}" && ! -L "${archive}" ]] || {
	echo "Falta el ZIP regular del superset productivo: ${archive}" >&2
	exit 1
}
actual_archive_sha="$(shasum -a 256 "${archive}" | awk '{print $1}')"
[[ "${actual_archive_sha}" == "${expected_archive_sha}" ]] || {
	echo "El ZIP del superset productivo cambió: ${actual_archive_sha}" >&2
	exit 1
}
[[ -f "${checkout_archive}" && ! -L "${checkout_archive}" ]] || {
	echo "Falta el ZIP regular congelado de Checkout Blocks." >&2
	exit 1
}
actual_checkout_sha="$(shasum -a 256 "${checkout_archive}" | awk '{print $1}')"
[[ "${actual_checkout_sha}" == "${expected_checkout_sha}" ]] || {
	echo "El ZIP congelado de Checkout Blocks cambió: ${actual_checkout_sha}" >&2
	exit 1
}

bash "${script_dir}/verify-theme-production-superset.sh"

mkdir -p "${test_root}/wp-content/themes" "${test_root}/wp-content/plugins"
unzip -q "${archive}" -d "${test_root}/wp-content/themes"
unzip -q "${checkout_archive}" -d "${test_root}/wp-content/plugins"
candidate="${test_root}/wp-content/themes/akibara-gp"
[[ -d "${candidate}" && ! -L "${candidate}" ]] || {
	echo "El ZIP productivo no contiene la raíz esperada del theme." >&2
	exit 1
}
[[ -d "${test_root}/wp-content/plugins/akibara-cart-checkout-blocks"
	&& ! -L "${test_root}/wp-content/plugins/akibara-cart-checkout-blocks" ]] || {
	echo "El ZIP de Checkout Blocks no contiene la raíz esperada." >&2
	exit 1
}
# Desde el 2026-09-11 este gate ya NO corre las pruebas del árbol actual sobre
# el artefacto congelado: sesenta versiones después describen otro tema y lo
# dejaban en rojo. Aquí se validan bytes, sintaxis e invariantes del ZIP 2.1.49;
# el comportamiento se prueba en `qa-theme.sh` sobre el ZIP de la versión viva.
php -l "${candidate}/functions.php" >/dev/null
php -l "${candidate}/inc/woocommerce.php" >/dev/null
php -l "${candidate}/woocommerce/cart/cart-empty.php" >/dev/null
bash "${script_dir}/package-runtime.sh" theme-production \
	--source-dir "${candidate}" \
	--verify-only

echo "PASS: superset productivo 2.1.49 (ZIP congelado) validado por bytes, sintaxis e invariantes de empaquetado"
