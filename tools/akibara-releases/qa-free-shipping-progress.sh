#!/usr/bin/env bash

# Gate visual y de interacción, sin mutaciones, del progreso de envío gratis.

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
compose_file="${repo_root}/compose.yaml"
probe_file="${script_dir}/free-shipping-progress/browser-probe.mjs"
playwright_cli="${AKB_PLAYWRIGHT_CLI:-${script_dir}/playwright-cli-0.1.18.sh}"
base_url="${AKB_FSP_BASE_URL:-http://localhost:8080}"
stylesheet_path="/wp-content/plugins/akibara-business/assets/css/free-shipping-progress.css"

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

[[ "${base_url}" =~ ^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$ ]] \
	|| die 'El gate integrado sólo acepta un origen HTTP loopback local sin credenciales ni pathname.'

for command_name in curl docker jq node npx openssl rg shasum; do
	command -v "${command_name}" >/dev/null 2>&1 || die "Falta ${command_name}."
done
[[ -f "${probe_file}" && ! -L "${probe_file}" ]] || die "Falta probe regular: ${probe_file}"
[[ -x "${playwright_cli}" && ! -L "${playwright_cli}" ]] || die "Falta Playwright CLI: ${playwright_cli}"
docker compose -f "${compose_file}" ps --status running wordpress | rg -q 'wordpress' \
	|| die 'El contenedor WordPress no está activo.'

plugin_version="$(awk -F "'" '/AKIBARA_BUSINESS_VERSION/ { print $4; exit }' \
	"${repo_root}/public_html/wp-content/plugins/akibara-business/akibara-business.php")"
[[ "${plugin_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die 'No se pudo resolver la versión de Business.'
stylesheet_url="${base_url}${stylesheet_path}?akb-fsp-gate=${plugin_version}"
stylesheet_source="$(curl -fsS --max-redirs 0 --max-time 30 "${stylesheet_url}")"
rg -q '^\.akb-fsp-truck[[:space:]]*\{' <<< "${stylesheet_source}" || die 'El CSS local no contiene el camión.'
rg -q '^\.akb-fsp-almost-there \.akb-fsp-track[[:space:]]*\{' <<< "${stylesheet_source}" || die 'El CSS local no contiene el estado casi-lo-logras.'
rg -q '^\.akb-cart-progress-slot \.akb-fsp-wrap\.akb-fsp-qualified[[:space:]]*\{' <<< "${stylesheet_source}" || die 'El CSS local no contiene la banda calificada del drawer.'

source_digest() {
	{
		shasum -a 256 \
			"${repo_root}/public_html/wp-content/plugins/akibara-business/akibara-business.php" \
			"${repo_root}/public_html/wp-content/plugins/akibara-business/src/Modules/FreeShippingProgress/FreeShippingProgress.php" \
			"${repo_root}/public_html/wp-content/plugins/akibara-business/assets/css/free-shipping-progress.css" \
			"${repo_root}/tools/akibara-business/qa.sh" \
			"${probe_file}" \
			"${playwright_cli}" \
			"${BASH_SOURCE[0]}"
	} | shasum -a 256 | awk '{print $1}'
}
source_digest_before="$(source_digest)"

run_id="akb-fsp-$(openssl rand -hex 5)"
evidence_dir="${repo_root}/output/playwright/free-shipping-progress/${run_id}"
mkdir -p -- "${evidence_dir}"
sessions=()
cleanup_done=0

cleanup() {
	local exit_status=$? session record_dir
	if ((cleanup_done)); then
		return "${exit_status}"
	fi
	cleanup_done=1
	set +e
	if ((${#sessions[@]} > 0)); then
		for session in "${sessions[@]}"; do
			record_dir="${evidence_dir}/${session#${run_id}-}"
			(
				cd -- "${record_dir}" || exit 0
				"${playwright_cli}" -s="${session}" delete-data >/dev/null 2>&1 || true
				"${playwright_cli}" -s="${session}" close >/dev/null 2>&1 || true
			)
		done
	fi
	return "${exit_status}"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

run_cell() {
	local engine="$1" width="$2" height="$3" mobile="$4" session cell_dir expected probe_code
	session="${run_id}-${engine}-${width}x${height}"
	cell_dir="${evidence_dir}/${engine}-${width}x${height}"
	sessions+=("${session}")
	mkdir -- "${cell_dir}"
	expected="$(jq -cn \
		--arg base_url "${base_url}" \
		--arg stylesheet_url "${stylesheet_url}" \
		--arg engine "${engine}" \
		--argjson mobile "${mobile}" \
		--argjson width "${width}" \
		--argjson height "${height}" \
		'{baseUrl:$base_url,stylesheetUrl:$stylesheet_url,engine:$engine,mobile:$mobile,viewport:{width:$width,height:$height}}')"
	probe_code="$(jq -nr --rawfile source "${probe_file}" --argjson expected "${expected}" \
		'$source | split("__AKB_FSP_EXPECTED__") | join($expected | tojson)')"
	if rg -q '__AKB_FSP_[A-Z_]+__' <<< "${probe_code}"; then
		die 'Quedó un placeholder sin renderizar en el probe.'
	fi
	printf '(%s)\n' "${probe_code}" | node --input-type=module --check >/dev/null \
		|| die 'El probe renderizado no es JavaScript válido.'

	(
		cd -- "${cell_dir}"
		if [[ "${engine}" == webkit && "${mobile}" == true ]]; then
			"${playwright_cli}" -s="${session}" open about:blank --browser webkit --mobile > open.log 2>&1
		elif [[ "${engine}" == webkit ]]; then
			"${playwright_cli}" -s="${session}" open about:blank --browser webkit > open.log 2>&1
		elif [[ "${mobile}" == true ]]; then
			"${playwright_cli}" -s="${session}" open about:blank --mobile > open.log 2>&1
		else
			"${playwright_cli}" -s="${session}" open about:blank > open.log 2>&1
		fi
		"${playwright_cli}" -s="${session}" resize "${width}" "${height}" > resize.log 2>&1
		if ! "${playwright_cli}" -s="${session}" --raw run-code "${probe_code}" \
			> report.json 2> probe.stderr.log; then
			tail -n 100 probe.stderr.log >&2 || true
			exit 1
		fi
		if ! jq -e '.failures == [] and .result == "PASS"' report.json >/dev/null; then
			jq '.' report.json >&2
			exit 1
		fi
		"${playwright_cli}" -s="${session}" screenshot > screenshot.log 2>&1
		"${playwright_cli}" -s="${session}" delete-data > delete-data.log 2>&1
		"${playwright_cli}" -s="${session}" close > close.log 2>&1
	)
	printf 'PASS: progreso de envío gratis %s %sx%s\n' "${engine}" "${width}" "${height}"
}

for engine in chromium webkit; do
	run_cell "${engine}" 375 812 true
	run_cell "${engine}" 844 390 true
	run_cell "${engine}" 1440 900 false
done

source_digest_after="$(source_digest)"
[[ "${source_digest_after}" == "${source_digest_before}" ]] \
	|| die 'El código o el gate cambió durante la matriz; el receipt queda invalidado.'
playwright_version="$("${playwright_cli}" --version | awk '{print $NF}')"
jq -s \
	--arg base_url "${base_url}" \
	--arg stylesheet_url "${stylesheet_url}" \
	--arg plugin_version "${plugin_version}" \
	--arg source_digest "${source_digest_before}" \
	--arg playwright_version "${playwright_version}" \
	'{result:"PASS",baseUrl:$base_url,stylesheetUrl:$stylesheet_url,pluginVersion:$plugin_version,sourceDigest:$source_digest,playwrightVersion:$playwright_version,cells:.}' \
	"${evidence_dir}"/*/report.json > "${evidence_dir}/summary.json"
summary_sha256="$(shasum -a 256 "${evidence_dir}/summary.json" | awk '{print $1}')"
printf 'Gate E2E del progreso completado: %s\n' "${evidence_dir}/summary.json"
printf 'SHA-256 summary: %s\n' "${summary_sha256}"
