#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
packager="${script_dir}/package-search-toolbar-production.sh"
contract="${repo_root}/ops/contracts/akibara-search-toolbar-0.1.10-theme-2.1.58.json"

die() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

for command_name in cmp docker find jq node shasum unzip; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| die "falta ${command_name}"
done
[[ -f "${packager}" && ! -L "${packager}" ]] || die "falta empaquetador regular"
[[ -f "${contract}" && ! -L "${contract}" ]] || die "falta contrato regular"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-search-toolbar-release-test.XXXXXX")"
cleanup() {
	case "${scratch:-}" in
		*/akibara-search-toolbar-release-test.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

first="${scratch}/first"
second="${scratch}/second"
extract="${scratch}/extract"
mkdir -p -- "${first}" "${second}" "${extract}"

search_preimage="${repo_root}/$(jq -r '.components.search.preimage.archive' "${contract}")"
theme_preimage="${repo_root}/$(jq -r '.components.theme.preimage.archive' "${contract}")"
search_preimage_before="$(shasum -a 256 "${search_preimage}" | awk '{print $1}')"
theme_preimage_before="$(shasum -a 256 "${theme_preimage}" | awk '{print $1}')"

TZ=Pacific/Kiritimati bash "${packager}" --output-dir "${first}" >/dev/null
TZ=Pacific/Pago_Pago bash "${packager}" --output-dir "${second}" >/dev/null

for artifact in \
	akibara-search-0.1.10.zip \
	akibara-search-0.1.10.files.sha256 \
	akibara-search-0.1.10.zip.sha256 \
	akibara-gp-2.1.58.zip \
	akibara-gp-2.1.58.files.sha256 \
	akibara-gp-2.1.58.zip.sha256; do
	[[ -f "${first}/${artifact}" && ! -L "${first}/${artifact}" ]] \
		|| die "primer build no produjo ${artifact}"
	[[ -f "${second}/${artifact}" && ! -L "${second}/${artifact}" ]] \
		|| die "segundo build no produjo ${artifact}"
	cmp -s "${first}/${artifact}" "${second}/${artifact}" \
		|| die "build no reproducible: ${artifact}"
done

[[ "$(shasum -a 256 "${search_preimage}" | awk '{print $1}')" == "${search_preimage_before}" ]] \
	|| die "el empaquetado alteró la preimagen Search 0.1.9"
[[ "$(shasum -a 256 "${theme_preimage}" | awk '{print $1}')" == "${theme_preimage_before}" ]] \
	|| die "el empaquetado alteró la preimagen theme 2.1.58"

(
	cd -- "${first}"
	shasum -a 256 -c akibara-search-0.1.10.zip.sha256 >/dev/null
	shasum -a 256 -c akibara-gp-2.1.58.zip.sha256 >/dev/null
)
unzip -q "${first}/akibara-search-0.1.10.zip" -d "${extract}"
unzip -q "${first}/akibara-gp-2.1.58.zip" -d "${extract}"
(
	cd -- "${extract}"
	shasum -a 256 -c "${first}/akibara-search-0.1.10.files.sha256" >/dev/null
	shasum -a 256 -c "${first}/akibara-gp-2.1.58.files.sha256" >/dev/null
)

for runtime_root in "${extract}/akibara-search" "${extract}/akibara-gp"; do
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

grep -Fq 'Version:           0.1.10' \
	"${extract}/akibara-search/akibara-search.php" \
	|| die "header Search no es 0.1.10"
grep -Fq "define( 'AKIBARA_SEARCH_VERSION', '0.1.10' );" \
	"${extract}/akibara-search/akibara-search.php" \
	|| die "constante Search no es 0.1.10"
grep -Fq 'Version:           2.1.58' "${extract}/akibara-gp/style.css" \
	|| die "header theme no es 2.1.58"

while IFS= read -r -d '' javascript_file; do
	node --check "${javascript_file}" >/dev/null
done < <(
	find "${extract}/akibara-search" "${extract}/akibara-gp" \
		-type f -name '*.js' -print0
)

docker run --rm --network none --read-only --tmpfs /tmp \
	--volume "${extract}/akibara-search:/runtime/akibara-search:ro" \
	--volume "${extract}/akibara-gp:/runtime/akibara-gp:ro" \
	wordpress:php8.4-apache sh -lc \
	'find /runtime/akibara-search /runtime/akibara-gp -type f -name "*.php" -print0 |
	 xargs -0 -n1 php -l >/dev/null'

node --test \
	"${repo_root}/public_html/wp-content/plugins/akibara-search/tests/"*.test.js \
	"${repo_root}/public_html/wp-content/themes/akibara-gp/tests/"*.test.js

printf 'PASS: release Search 0.1.10 + theme 2.1.58 reproducible, aislado, lint PHP/JS y tests Node\n'
