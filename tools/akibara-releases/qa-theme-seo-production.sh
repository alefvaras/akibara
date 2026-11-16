#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
contract="${repo_root}/ops/contracts/akibara-gp-2.1.50-seo-overlay.json"
archive="${repo_root}/dist/akibara-gp-2.1.50.zip"
inventory="${repo_root}/dist/akibara-gp-2.1.50.files.sha256"
sidecar="${repo_root}/dist/akibara-gp-2.1.50.zip.sha256"
checkout_archive="${repo_root}/dist/akibara-cart-checkout-blocks-2.0.1.zip"
docker_image="wordpress:php8.4-apache"

for command_name in cmp docker jq node php shasum unzip; do
	command -v "${command_name}" >/dev/null 2>&1 || {
		echo "Falta el comando requerido para el gate local SEO: ${command_name}" >&2
		exit 1
	}
done
for path in "${contract}" "${archive}" "${inventory}" "${sidecar}" "${checkout_archive}"; do
	[[ -f "${path}" && ! -L "${path}" ]] || {
		echo "Falta un artefacto regular del release SEO: ${path}" >&2
		exit 1
	}
done

expected_archive_sha="$(jq -r '.production_candidate.archive_sha256' "${contract}")"
expected_inventory_sha="$(jq -r '.production_candidate.inventory_sha256' "${contract}")"
expected_checkout_sha="$(jq -r '.quality_gate.checkout_dependency_sha256' "${contract}")"
actual_archive_sha="$(shasum -a 256 "${archive}" | awk '{print $1}')"
actual_inventory_sha="$(shasum -a 256 "${inventory}" | awk '{print $1}')"
[[ "${actual_archive_sha}" == "${expected_archive_sha}" ]] || {
	echo "El ZIP SEO 2.1.50 no coincide con el contrato." >&2
	exit 1
}
[[ "${actual_inventory_sha}" == "${expected_inventory_sha}" ]] || {
	echo "El inventario SEO 2.1.50 no coincide con el contrato." >&2
	exit 1
}
[[ "$(shasum -a 256 "${checkout_archive}" | awk '{print $1}')" \
	== "${expected_checkout_sha}" ]] || {
	echo "La dependencia Checkout del gate local SEO no coincide con el contrato." >&2
	exit 1
}
(
	cd "${repo_root}/dist"
	shasum -a 256 -c "$(basename -- "${sidecar}")" >/dev/null
) || {
	echo "El sidecar SHA-256 rechazó el ZIP SEO 2.1.50." >&2
	exit 1
}

test_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-theme-seo-gate.XXXXXX")"
cleanup() {
	case "${test_root}" in
		*/akibara-theme-seo-gate.*) rm -rf -- "${test_root}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

mkdir -p "${test_root}/wp-content/themes" "${test_root}/wp-content/plugins"
unzip -q "${archive}" -d "${test_root}/wp-content/themes"
unzip -q "${checkout_archive}" -d "${test_root}/wp-content/plugins"
candidate="${test_root}/wp-content/themes/akibara-gp"
[[ -d "${candidate}" && ! -L "${candidate}" ]] || {
	echo "El ZIP SEO no contiene akibara-gp/." >&2
	exit 1
}
bash "${script_dir}/verify-theme-seo-production-overlay.sh" \
	--source-dir "${candidate}"

actual_inventory="${test_root}/actual.files.sha256"
(
	cd "${test_root}/wp-content/themes"
	find akibara-gp -type f -print | LC_ALL=C sort |
		while IFS= read -r relative; do
			file_sha="$(shasum -a 256 "${relative}" | awk '{print $1}')"
			printf '%s  %s\n' "${file_sha}" "${relative}"
		done
) > "${actual_inventory}"
cmp -s "${inventory}" "${actual_inventory}" || {
	echo "El contenido extraído no coincide con el inventario sellado." >&2
	exit 1
}

while IFS= read -r php_file; do
	php -l "${php_file}" >/dev/null
done < <(find "${candidate}" -type f -name '*.php' | LC_ALL=C sort)
while IFS= read -r js_file; do
	node --check "${js_file}" >/dev/null
done < <(find "${candidate}" -type f -name '*.js' | LC_ALL=C sort)

# Desde el 2026-09-11 este gate ya NO corre las pruebas del árbol actual sobre
# el artefacto congelado (describen otro tema y lo dejaban en rojo): valida
# bytes, inventario, sintaxis en host y en Docker. El comportamiento se prueba
# en `qa-theme.sh` sobre el ZIP de la versión viva.
docker image inspect "${docker_image}" >/dev/null 2>&1 || {
	echo "Falta la imagen Docker local requerida: ${docker_image}" >&2
	exit 1
}
docker run --rm \
	--network none \
	--read-only \
	--tmpfs /tmp:rw,noexec,nosuid,size=16m \
	--entrypoint /bin/sh \
	--volume "${candidate}:/candidate:ro" \
	"${docker_image}" \
	-lc '
		set -eu
		find /candidate -type f -name "*.php" -print |
			LC_ALL=C sort |
			while IFS= read -r file; do php -l "$file" >/dev/null; done
	'

echo "PASS: Akibara GP SEO 2.1.50 validado en host y Docker PHP 8.4"
