#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
contract="${repo_root}/ops/contracts/akibara-gp-2.1.50-seo-overlay.json"
packager="${script_dir}/package-theme-seo-production.sh"
verifier="${script_dir}/verify-theme-seo-production-overlay.sh"
source_theme="${repo_root}/public_html/wp-content/themes/akibara-gp"

for command_name in cmp jq shasum unzip zipinfo; do
	command -v "${command_name}" >/dev/null 2>&1 || {
		echo "Falta el comando requerido para la prueba de release SEO: ${command_name}" >&2
		exit 1
	}
done

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-theme-seo-release-test.XXXXXX")"
cleanup() {
	case "${scratch}" in
		*/akibara-theme-seo-release-test.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

sealed_paths=(
	"${repo_root}/ops/lib/akibara-release-contract.sh"
	"${repo_root}/ops/contracts/akibara-gp-2.1.49-production-superset.json"
	"${repo_root}/dist/akibara-gp-2.1.49.zip"
	"${repo_root}/dist/akibara-gp-2.1.49.files.sha256"
	"${repo_root}/dist/akibara-gp-2.1.49.zip.sha256"
)
sealed_before="${scratch}/sealed-before.sha256"
sealed_after="${scratch}/sealed-after.sha256"
shasum -a 256 "${sealed_paths[@]}" > "${sealed_before}"

source_before="${scratch}/source-before.sha256"
source_after="${scratch}/source-after.sha256"
{
	shasum -a 256 "${source_theme}/style.css"
	while IFS=$'\t' read -r relative _expected_sha; do
		shasum -a 256 "${source_theme}/${relative}"
	done < <(
		jq -r '.source_theme.required_input_files[] | [.path, .sha256] | @tsv' \
			"${contract}"
	)
} > "${source_before}"

first="${scratch}/first"
second="${scratch}/second"
TZ=Pacific/Kiritimati bash "${packager}" --output-dir "${first}"
TZ=America/Adak bash "${packager}" --output-dir "${second}"

for artifact in \
	akibara-gp-2.1.50.zip \
	akibara-gp-2.1.50.files.sha256 \
	akibara-gp-2.1.50.zip.sha256; do
	cmp -s "${first}/${artifact}" "${second}/${artifact}" || {
		echo "La construcción no es reproducible: ${artifact}" >&2
		exit 1
	}
done

expected_zip_sha="$(jq -r '.production_candidate.archive_sha256' "${contract}")"
expected_inventory_sha="$(jq -r '.production_candidate.inventory_sha256' "${contract}")"
[[ "$(shasum -a 256 "${first}/akibara-gp-2.1.50.zip" | awk '{print $1}')" \
	== "${expected_zip_sha}" ]] || {
	echo "El ZIP reproducible no coincide con el contrato." >&2
	exit 1
}
[[ "$(shasum -a 256 "${first}/akibara-gp-2.1.50.files.sha256" | awk '{print $1}')" \
	== "${expected_inventory_sha}" ]] || {
	echo "El inventario reproducible no coincide con el contrato." >&2
	exit 1
}
[[ "$(zipinfo -1 "${first}/akibara-gp-2.1.50.zip" | wc -l | tr -d ' ')" == "103" ]] || {
	echo "El ZIP reproducible no contiene 103 archivos." >&2
	exit 1
}
if zipinfo -1 "${first}/akibara-gp-2.1.50.zip" |
	grep -E '(^|/)tests(/|$)|(^|/)\._|(^|/)\.DS_Store$' >/dev/null; then
	echo "El ZIP SEO contiene tests o metadatos prohibidos." >&2
	exit 1
fi

expect_verifier_failure() {
	local fixture="$1" label="$2"
	if bash "${verifier}" --source-dir "${fixture}" \
		> "${scratch}/${label}.out" 2> "${scratch}/${label}.err"; then
		echo "El verificador aceptó el caso negativo: ${label}" >&2
		exit 1
	fi
}

tampered="${scratch}/tampered"
missing="${scratch}/missing"
unexpected="${scratch}/unexpected"
legacy_restored="${scratch}/legacy-restored"
unzip -q "${first}/akibara-gp-2.1.50.zip" -d "${tampered}"
unzip -q "${first}/akibara-gp-2.1.50.zip" -d "${missing}"
unzip -q "${first}/akibara-gp-2.1.50.zip" -d "${unexpected}"
unzip -q "${first}/akibara-gp-2.1.50.zip" -d "${legacy_restored}"
printf '\n/* forbidden non-SEO drift */\n' \
	>> "${tampered}/akibara-gp/inc/page-header.php"
rm -- "${missing}/akibara-gp/inc/rank-math.php"
printf "'use strict';\n" \
	> "${unexpected}/akibara-gp/assets/js/unexpected-seo.js"
unzip -p "${repo_root}/dist/akibara-gp-2.1.49.zip" \
	akibara-gp/inc/seo-filtered-urls.php \
	> "${legacy_restored}/akibara-gp/inc/seo-filtered-urls.php"
expect_verifier_failure "${tampered}/akibara-gp" "non-seo-drift"
expect_verifier_failure "${missing}/akibara-gp" "required-seo-removal"
expect_verifier_failure "${unexpected}/akibara-gp" "unexpected-addition"
expect_verifier_failure "${legacy_restored}/akibara-gp" "legacy-owner-reintroduction"

shasum -a 256 "${sealed_paths[@]}" > "${sealed_after}"
cmp -s "${sealed_before}" "${sealed_after}" || {
	echo "La prueba alteró el release c460 sellado." >&2
	exit 1
}
{
	shasum -a 256 "${source_theme}/style.css"
	while IFS=$'\t' read -r relative _expected_sha; do
		shasum -a 256 "${source_theme}/${relative}"
	done < <(
		jq -r '.source_theme.required_input_files[] | [.path, .sha256] | @tsv' \
			"${contract}"
	)
} > "${source_after}"
cmp -s "${source_before}" "${source_after}" || {
	echo "El empaquetador modificó el árbol fuente 2.1.48." >&2
	exit 1
}

echo "PASS: release SEO 2.1.50 reproducible, fail-closed y aislado de c460"
