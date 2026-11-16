#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
packager="${script_dir}/package-business-blocks-production.sh"
contract="${repo_root}/ops/contracts/akibara-business-1.98.2-blocks-2.1.6.json"
candidate_bootstrap="${script_dir}/phpunit-business-blocks-candidate-bootstrap.php"
source_delta_test="${script_dir}/test-business-blocks-source-delta.sh"

die() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

for command_name in cmp docker find jq node shasum unzip; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| die "falta ${command_name}"
done
for required in \
	"${packager}" "${contract}" "${candidate_bootstrap}" "${source_delta_test}"; do
	[[ -f "${required}" && ! -L "${required}" ]] \
		|| die "falta archivo regular: ${required}"
done
bash "${source_delta_test}"
grep -Fq 'python3 "${source_delta_verifier}"' "${packager}" \
	|| die "el empaquetador no ejecuta el gate completo del delta fuente"
[[ "$(jq -r '.seal_state' "${contract}")" == sealed ]] \
	|| die "el contrato debe estar sellado antes de ejecutar el gate"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-blocks-release-test.XXXXXX")"
cleanup() {
	case "${scratch:-}" in
		*/akibara-business-blocks-release-test.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

first="${scratch}/first"
second="${scratch}/second"
extracted="${scratch}/extracted"
candidate="${scratch}/candidate"
plugins="${candidate}/public_html/wp-content/plugins"
mkdir -p -- "${first}" "${second}" "${extracted}" "${plugins}"

business_preimage="${repo_root}/$(jq -r '.components.business.preimage.archive' "${contract}")"
blocks_preimage="${repo_root}/$(jq -r '.components.blocks.preimage.archive' "${contract}")"
business_preimage_before="$(shasum -a 256 "${business_preimage}" | awk '{print $1}')"
blocks_preimage_before="$(shasum -a 256 "${blocks_preimage}" | awk '{print $1}')"

TZ=Pacific/Kiritimati bash "${packager}" --output-dir "${first}" >/dev/null
TZ=Pacific/Pago_Pago bash "${packager}" --output-dir "${second}" >/dev/null

artifacts=(
	akibara-business-1.98.2.zip
	akibara-business-1.98.2.files.sha256
	akibara-business-1.98.2.zip.sha256
	akibara-cart-checkout-blocks-2.1.6.zip
	akibara-cart-checkout-blocks-2.1.6.files.sha256
	akibara-cart-checkout-blocks-2.1.6.zip.sha256
)
for artifact in "${artifacts[@]}"; do
	[[ -f "${first}/${artifact}" && ! -L "${first}/${artifact}" ]] \
		|| die "primer build no produjo ${artifact}"
	[[ -f "${second}/${artifact}" && ! -L "${second}/${artifact}" ]] \
		|| die "segundo build no produjo ${artifact}"
	cmp -s "${first}/${artifact}" "${second}/${artifact}" \
		|| die "build no reproducible: ${artifact}"
done

[[ "$(shasum -a 256 "${business_preimage}" | awk '{print $1}')" == "${business_preimage_before}" ]] \
	|| die "el empaquetado alteró Business 1.98.1"
[[ "$(shasum -a 256 "${blocks_preimage}" | awk '{print $1}')" == "${blocks_preimage_before}" ]] \
	|| die "el empaquetado alteró Blocks 2.1.5"

(
	cd -- "${first}"
	shasum -a 256 -c akibara-business-1.98.2.zip.sha256 >/dev/null
	shasum -a 256 -c akibara-cart-checkout-blocks-2.1.6.zip.sha256 >/dev/null
)
unzip -q "${first}/akibara-business-1.98.2.zip" -d "${extracted}"
unzip -q "${first}/akibara-cart-checkout-blocks-2.1.6.zip" -d "${extracted}"
(
	cd -- "${extracted}"
	shasum -a 256 -c "${first}/akibara-business-1.98.2.files.sha256" >/dev/null
	shasum -a 256 -c "${first}/akibara-cart-checkout-blocks-2.1.6.files.sha256" >/dev/null
)

for runtime_root in \
	"${extracted}/akibara-business" \
	"${extracted}/akibara-cart-checkout-blocks"; do
	[[ -d "${runtime_root}" && ! -L "${runtime_root}" ]] \
		|| die "raíz extraída ausente o insegura: ${runtime_root}"
	test -z "$(find "${runtime_root}" ! -type d ! -type f -print -quit)" \
		|| die "el ZIP extrajo enlaces o entradas especiales"
	forbidden="$(
		find "${runtime_root}" \
			\( -type d \( -name tests -o -name node_modules -o -name migrations \
				-o -name graphify-out -o -name coverage -o -name backups \) \
				-o -type f \( -name '*.bak' -o -name '*.orig' -o -name '*.tmp' \
				-o -name '*.temp' -o -name '*.dump' -o -name '*.backup' \
				-o -name '*.sql' -o -name '*.sqlite' -o -name '*.zip' \
				-o -name '._*' -o -name '.DS_Store' \) \) -print
	)"
	[[ -z "${forbidden}" ]] \
		|| die "runtime contiene artefactos prohibidos: ${forbidden}"
done

frontend_compatibility="${extracted}/akibara-business/src/Modules/FrontendCompatibility/FrontendCompatibility.php"
[[ "$(shasum -a 256 "${frontend_compatibility}" | awk '{print $1}')" \
	== "ccc00fb744a8dcd681ba436dda67362003e74386bf996e905c8d49a5b0561e49" ]] \
	|| die "FrontendCompatibility.php no preservó la preimagen productiva"

while IFS= read -r removed; do
	[[ ! -e "${extracted}/${removed}" && ! -L "${extracted}/${removed}" ]] \
		|| die "ruta eliminada persiste en candidato: ${removed}"
done < <(
	jq -r '
	  .components.business.removed_runtime_files[],
	  .components.blocks.removed_runtime_files[]
	' "${contract}"
)

grep -Fq 'Version:           1.98.2' \
	"${extracted}/akibara-business/akibara-business.php" \
	|| die "header Business no es 1.98.2"
grep -Fq "define( 'AKIBARA_BUSINESS_VERSION', '1.98.2' );" \
	"${extracted}/akibara-business/akibara-business.php" \
	|| die "constante Business no es 1.98.2"
grep -Fq 'Version:           2.1.6' \
	"${extracted}/akibara-cart-checkout-blocks/akibara-cart-checkout-blocks.php" \
	|| die "header Blocks no es 2.1.6"
grep -Fq "define( 'AKIBARA_CART_CHECKOUT_BLOCKS_VERSION', '2.1.6' );" \
	"${extracted}/akibara-cart-checkout-blocks/akibara-cart-checkout-blocks.php" \
	|| die "constante Blocks no es 2.1.6"

while IFS= read -r -d '' javascript_file; do
	node --check "${javascript_file}" >/dev/null
done < <(
	find \
		"${extracted}/akibara-business" \
		"${extracted}/akibara-cart-checkout-blocks" \
		-type f -name '*.js' -print0
)

docker run --rm --network none --read-only --tmpfs /tmp \
	--volume "${extracted}/akibara-business:/runtime/akibara-business:ro" \
	--volume "${extracted}/akibara-cart-checkout-blocks:/runtime/akibara-cart-checkout-blocks:ro" \
	wordpress:php8.4-apache sh -lc \
	'find /runtime/akibara-business /runtime/akibara-cart-checkout-blocks \
	   -type f -name "*.php" -print0 |
	 xargs -0 -n1 php -l >/dev/null'

cp -R -- "${extracted}/akibara-business" "${plugins}/akibara-business"
cp -R -- \
	"${extracted}/akibara-cart-checkout-blocks" \
	"${plugins}/akibara-cart-checkout-blocks"
cp -R -- \
	"${repo_root}/public_html/wp-content/plugins/akibara-business/tests" \
	"${plugins}/akibara-business/tests"
cp -R -- \
	"${repo_root}/public_html/wp-content/plugins/akibara-cart-checkout-blocks/tests" \
	"${plugins}/akibara-cart-checkout-blocks/tests"
mkdir -p -- "${candidate}/public_html/wp-content/themes"
cp -R -- \
	"${repo_root}/public_html/wp-content/themes/akibara-gp" \
	"${candidate}/public_html/wp-content/themes/akibara-gp"

node --test \
	"${plugins}/akibara-cart-checkout-blocks/tests/"*.test.js

cat > "${candidate}/phpunit.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
	bootstrap="/workspace/tools/akibara-releases/phpunit-business-blocks-candidate-bootstrap.php"
	cacheResult="false"
	colors="true"
	failOnRisky="true"
	failOnWarning="true"
>
	<testsuites>
		<testsuite name="Akibara Business candidate">
			<directory suffix="Test.php">/candidate/public_html/wp-content/plugins/akibara-business/tests</directory>
			<exclude>/candidate/public_html/wp-content/plugins/akibara-business/tests/Modules/FrontendCompatibility/FrontendCompatibilityTest.php</exclude>
		</testsuite>
		<testsuite name="Akibara Blocks candidate">
			<directory suffix="Test.php">/candidate/public_html/wp-content/plugins/akibara-cart-checkout-blocks/tests/php</directory>
		</testsuite>
	</testsuites>
</phpunit>
XML

docker run --rm --network none --read-only --tmpfs /tmp \
	--user "$(id -u):$(id -g)" \
	--env AKB_REPO_ROOT=/workspace \
	--env AKB_CANDIDATE_ROOT=/candidate \
	--volume "${repo_root}:/workspace:ro" \
	--volume "${candidate}:/candidate:ro" \
	--workdir /workspace/tools/akibara-business \
	--entrypoint php \
	wordpress:php8.4-apache \
	/workspace/tools/akibara-business/vendor/bin/phpunit \
	--configuration /candidate/phpunit.xml

printf 'PASS: release Business 1.98.2 + Blocks 2.1.6 reproducible, overlay preservado, lint PHP/JS y tests exactos\n'
