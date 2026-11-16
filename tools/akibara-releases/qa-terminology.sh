#!/usr/bin/env bash

# Gate E2E, sin mutaciones, del vocabulario de catálogo de Akibara.

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
compose_file="${repo_root}/compose.yaml"
probe_file="${script_dir}/terminology/browser-probe.mjs"
playwright_cli="${AKB_PLAYWRIGHT_CLI:-${script_dir}/playwright-cli-0.1.18.sh}"
base_url="${AKB_TERMINOLOGY_BASE_URL:-http://localhost:8080}"

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

[[ "${base_url}" =~ ^http://(localhost|127\.0\.0\.1)(:[0-9]{1,5})?$ ]] \
	|| die 'El gate integrado sólo acepta un origen HTTP loopback local sin credenciales ni pathname.'

for command_name in curl docker jq node openssl rg shasum; do
	command -v "${command_name}" >/dev/null 2>&1 || die "Falta ${command_name}."
done
[[ -f "${probe_file}" && ! -L "${probe_file}" ]] || die "Falta probe regular: ${probe_file}"
[[ -x "${playwright_cli}" && ! -L "${playwright_cli}" ]] || die "Falta Playwright CLI: ${playwright_cli}"
docker compose -f "${compose_file}" ps --status running wordpress | rg -q 'wordpress' \
	|| die 'El contenedor WordPress no está activo.'

manga_html="$(curl -fsS --max-redirs 0 --max-time 30 "${base_url}/manga/")"
editoriales_html="$(curl -fsS --max-redirs 0 --max-time 30 "${base_url}/editoriales/")"
faq_html="$(curl -fsS --max-redirs 0 --max-time 30 "${base_url}/invencible-2-planeta-espana/")"
rg -q 'class="akb-result-count[^"]*"[^>]*>(<span[^>]*>)?[0-9.]+ productos?' <<< "${manga_html}" || die '/manga/ no anuncia productos.'
rg -q 'Serie o edición' <<< "${manga_html}" || die '/manga/ no expone la faceta Serie o edición.'
rg -q 'productos en catálogo' <<< "${editoriales_html}" || die '/editoriales/ no cuenta productos.'
rg -q 'es un cómic' <<< "${faq_html}" || die 'La FAQ de cómic no reconoce el tipo editorial.'

business_version="$(awk -F "'" '/AKIBARA_BUSINESS_VERSION/ { print $4; exit }' \
	"${repo_root}/public_html/wp-content/plugins/akibara-business/akibara-business.php")"
search_version="$(awk -F "'" '/AKIBARA_SEARCH_VERSION/ { print $4; exit }' \
	"${repo_root}/public_html/wp-content/plugins/akibara-search/akibara-search.php")"
theme_version="$(awk '/^Version:/ { print $2; exit }' \
	"${repo_root}/public_html/wp-content/themes/akibara-gp/style.css")"
for version in "${business_version}" "${search_version}" "${theme_version}"; do
	[[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die 'No se pudo resolver una versión del runtime.'
done

source_digest() {
	{
		shasum -a 256 \
			"${repo_root}/public_html/wp-content/plugins/akibara-business/src/Modules/ProductFaq/ProductFaq.php" \
			"${repo_root}/public_html/wp-content/plugins/akibara-business/src/Modules/PdpEnhancer/PdpSeriesEnhancer.php" \
			"${repo_root}/public_html/wp-content/plugins/akibara-business/src/Modules/SeriesFacts/SeriesFacts.php" \
			"${repo_root}/public_html/wp-content/plugins/akibara-search/frontend/facets-render.php" \
			"${repo_root}/public_html/wp-content/plugins/akibara-search/src/FacetRegistry.php" \
			"${repo_root}/public_html/wp-content/plugins/akibara-search/assets/js/search-suggest.js" \
			"${repo_root}/public_html/wp-content/themes/akibara-gp/template-parts/brands/grid.php" \
			"${probe_file}" \
			"${playwright_cli}" \
			"${BASH_SOURCE[0]}"
	} | shasum -a 256 | awk '{print $1}'
}
source_digest_before="$(source_digest)"

run_id="akb-terminology-$(openssl rand -hex 5)"
evidence_dir="${repo_root}/output/playwright/terminology/${run_id}"
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
		--arg engine "${engine}" \
		--argjson mobile "${mobile}" \
		--argjson width "${width}" \
		--argjson height "${height}" \
		'{baseUrl:$base_url,engine:$engine,mobile:$mobile,viewport:{width:$width,height:$height}}')"
	probe_code="$(jq -nr --rawfile source "${probe_file}" --argjson expected "${expected}" \
		'$source | split("__AKB_TERMINOLOGY_EXPECTED__") | join($expected | tojson)')"
	rg -q '__AKB_TERMINOLOGY_[A-Z_]+' <<< "${probe_code}" \
		&& die 'Quedó un placeholder sin renderizar en el probe.'
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
			tail -n 80 probe.stderr.log >&2 || true
			exit 1
		fi
		jq -e '.failures == []' report.json >/dev/null || {
			jq '.' report.json >&2
			exit 1
		}
		"${playwright_cli}" -s="${session}" screenshot > screenshot.log 2>&1
		"${playwright_cli}" -s="${session}" delete-data > delete-data.log 2>&1
		"${playwright_cli}" -s="${session}" close > close.log 2>&1
	)
	printf 'PASS: terminología %s %sx%s\n' "${engine}" "${width}" "${height}"
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
	--arg business_version "${business_version}" \
	--arg search_version "${search_version}" \
	--arg theme_version "${theme_version}" \
	--arg source_digest "${source_digest_before}" \
	--arg playwright_version "${playwright_version}" \
	'{result:"PASS",baseUrl:$base_url,versions:{business:$business_version,search:$search_version,theme:$theme_version},sourceDigest:$source_digest,playwrightVersion:$playwright_version,cells:.}' \
	"${evidence_dir}"/*/report.json > "${evidence_dir}/summary.json"
printf 'Gate E2E de terminología completado: %s\n' "${evidence_dir}/summary.json"
