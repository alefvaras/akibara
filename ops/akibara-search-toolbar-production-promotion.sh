#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
dist_dir="${repo_root}/dist"
contract_file="${script_dir}/contracts/akibara-search-toolbar-0.1.10-theme-2.1.58.json"
config_library="${script_dir}/lib/akibara-promotion-config.sh"
commerce_fingerprint_php="${script_dir}/akibara-protected-commerce-fingerprint.php"
search_fingerprint_php="${script_dir}/akibara-search-state-fingerprint.php"
packager="${repo_root}/tools/akibara-releases/package-search-toolbar-production.sh"
release_test="${repo_root}/tools/akibara-releases/test-search-toolbar-production-release.sh"
pipeline_test="${script_dir}/test-akibara-search-toolbar-production-promotion.sh"
gate_root="${repo_root}/.local-backups/search-toolbar-production/gates"

release_id=""
release_digest=""
search_preimage_version=""
search_candidate_version=""
search_preimage_archive=""
search_preimage_inventory=""
search_candidate_archive=""
search_candidate_inventory=""
search_candidate_archive_sha=""
search_candidate_inventory_sha=""
theme_preimage_version=""
theme_candidate_version=""
theme_preimage_archive=""
theme_preimage_inventory=""
theme_candidate_archive=""
theme_candidate_inventory=""
theme_candidate_archive_sha=""
theme_candidate_inventory_sha=""
business_version=""
checkout_version=""

PRODUCTION_HOST=""
PRODUCTION_WEBROOT=""
PRODUCTION_BASE_URL=""
PRODUCTION_LOG_PATHS=""
AKB_HOST=""
AKB_WEBROOT=""
AKB_BASE_URL=""
AKB_LOG_PATHS=""

active_backup_dir=""
active_evidence_dir=""
code_backup=""
mutation_started=0
maintenance_active=0

usage() {
	cat <<'EOF'
Uso:
  ops/akibara-search-toolbar-production-promotion.sh verify-artifacts
  ops/akibara-search-toolbar-production-promotion.sh local-gate
  ops/akibara-search-toolbar-production-promotion.sh plan --config FILE
  ops/akibara-search-toolbar-production-promotion.sh promote --config FILE \
    --apply --confirm akibara-search-0.1.10_theme-production-2.1.58

Seguridad:
  - El flujo es exclusivamente Docker local -> producción; no tiene operación QA.
  - Producción exige Search 0.1.9 y theme 2.1.58 con inventarios exactos.
  - Solo instala Search 0.1.10 y theme 2.1.58, en ese orden.
  - El único respaldo de ambos árboles se transmite directamente a local.
  - Comercio y el índice Search deben conservar fingerprints byte a byte.
  - Los ZIP remotos usan mktemp + trap; el cierre exige cero artefactos.
  - Cualquier fallo tras mutar restaura ambos árboles o mantiene mantenimiento.
EOF
}

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

note() {
	printf '%s\n' "$*"
}

require_command() {
	command -v "$1" >/dev/null 2>&1 || die "Falta el comando requerido: $1"
}

sha256_file() {
	shasum -a 256 "$1" | awk '{print $1}'
}

verify_regular_file() {
	local candidate="$1"
	[[ -f "${candidate}" && ! -L "${candidate}" && -s "${candidate}" ]] \
		|| die "Archivo ausente, vacío o inseguro: ${candidate}"
}

load_contract() {
	local contract_keys component_keys

	require_command jq
	verify_regular_file "${contract_file}"
	contract_keys="$(jq -r 'keys | sort | join(",")' "${contract_file}")"
	component_keys="$(jq -r '.components | keys | sort | join(",")' "${contract_file}")"
	[[ "${contract_keys}" == "components,contract,production_invariants,promotion_sequence,release_id,schema_version"
		&& "${component_keys}" == "search,theme" ]] \
		|| die "El contrato del hotfix contiene claves inesperadas"
	jq -e '
	  .schema_version == 1
	  and .contract == "akibara-search-toolbar-production-hotfix"
	  and .release_id == "akibara-search-0.1.10_theme-production-2.1.58"
	  and .promotion_sequence == ["docker-local", "production"]
	  and .components.search.slug == "akibara-search"
	  and .components.search.preimage.version == "0.1.9"
	  and .components.search.candidate.version == "0.1.10"
	  and .components.theme.slug == "akibara-gp"
	  and .components.theme.preimage.version == "2.1.58"
	  and .components.theme.candidate.version == "2.1.58"
	  and .production_invariants.business.version == "1.98.2"
	  and .production_invariants.blocks.version == "2.1.6"
	  and .production_invariants.bluex.version == "3.2.2"
	  and .production_invariants.rank_math_active == true
	  and .production_invariants.remote_backups_allowed == false
	  and .production_invariants.graphify_during_release_allowed == false
	  and ([.components.search.preimage.archive_sha256,
	        .components.search.preimage.inventory_sha256,
	        .components.search.candidate.archive_sha256,
	        .components.search.candidate.inventory_sha256,
	        .components.theme.preimage.archive_sha256,
	        .components.theme.preimage.inventory_sha256,
	        .components.theme.candidate.archive_sha256,
	        .components.theme.candidate.inventory_sha256]
	    | all(test("^[a-f0-9]{64}$") and . != ("0" * 64)))
	' "${contract_file}" >/dev/null \
		|| die "El contrato no coincide con el release productivo revisado"

	release_id="$(jq -r '.release_id' "${contract_file}")"
	search_preimage_version="$(jq -r '.components.search.preimage.version' "${contract_file}")"
	search_candidate_version="$(jq -r '.components.search.candidate.version' "${contract_file}")"
	search_preimage_archive="${repo_root}/$(jq -r '.components.search.preimage.archive' "${contract_file}")"
	search_preimage_inventory="${repo_root}/$(jq -r '.components.search.preimage.inventory' "${contract_file}")"
	search_candidate_archive="${repo_root}/$(jq -r '.components.search.candidate.archive' "${contract_file}")"
	search_candidate_inventory="${repo_root}/$(jq -r '.components.search.candidate.inventory' "${contract_file}")"
	search_candidate_archive_sha="$(jq -r '.components.search.candidate.archive_sha256' "${contract_file}")"
	search_candidate_inventory_sha="$(jq -r '.components.search.candidate.inventory_sha256' "${contract_file}")"
	theme_preimage_version="$(jq -r '.components.theme.preimage.version' "${contract_file}")"
	theme_candidate_version="$(jq -r '.components.theme.candidate.version' "${contract_file}")"
	theme_preimage_archive="${repo_root}/$(jq -r '.components.theme.preimage.archive' "${contract_file}")"
	theme_preimage_inventory="${repo_root}/$(jq -r '.components.theme.preimage.inventory' "${contract_file}")"
	theme_candidate_archive="${repo_root}/$(jq -r '.components.theme.candidate.archive' "${contract_file}")"
	theme_candidate_inventory="${repo_root}/$(jq -r '.components.theme.candidate.inventory' "${contract_file}")"
	theme_candidate_archive_sha="$(jq -r '.components.theme.candidate.archive_sha256' "${contract_file}")"
	theme_candidate_inventory_sha="$(jq -r '.components.theme.candidate.inventory_sha256' "${contract_file}")"
	business_version="$(jq -r '.production_invariants.business.version' "${contract_file}")"
	checkout_version="$(jq -r '.production_invariants.blocks.version' "${contract_file}")"
	release_digest="$(
		{
			printf '@contract\takibara-search-toolbar-production-v1\n'
			printf 'search\t%s\t%s\n' \
				"${search_candidate_archive_sha}" "${search_candidate_inventory_sha}"
			printf 'theme\t%s\t%s\n' \
				"${theme_candidate_archive_sha}" "${theme_candidate_inventory_sha}"
		} | shasum -a 256 | awk '{print $1}'
	)"
}

verify_artifact_file() {
	local archive="$1" inventory="$2" expected_archive_sha="$3"
	local expected_inventory_sha="$4"

	verify_regular_file "${archive}"
	verify_regular_file "${inventory}"
	verify_regular_file "${archive}.sha256"
	[[ "$(sha256_file "${archive}")" == "${expected_archive_sha}" ]] \
		|| die "SHA-256 inesperado para ${archive}"
	[[ "$(sha256_file "${inventory}")" == "${expected_inventory_sha}" ]] \
		|| die "SHA-256 inesperado para ${inventory}"
	(
		cd -- "$(dirname -- "${archive}")"
		shasum -a 256 -c "$(basename -- "${archive}.sha256")" >/dev/null
	) || die "El sidecar rechazó ${archive}"
	unzip -tq "${archive}" >/dev/null
}

verify_artifacts() {
	local search_preimage_sha search_preimage_inventory_sha
	local theme_preimage_sha theme_preimage_inventory_sha

	for command_name in awk cmp find grep jq node python3 sed shasum unzip zip zipinfo; do
		require_command "${command_name}"
	done
	verify_regular_file "${packager}"
	search_preimage_sha="$(jq -r '.components.search.preimage.archive_sha256' "${contract_file}")"
	search_preimage_inventory_sha="$(jq -r '.components.search.preimage.inventory_sha256' "${contract_file}")"
	theme_preimage_sha="$(jq -r '.components.theme.preimage.archive_sha256' "${contract_file}")"
	theme_preimage_inventory_sha="$(jq -r '.components.theme.preimage.inventory_sha256' "${contract_file}")"
	verify_artifact_file \
		"${search_preimage_archive}" "${search_preimage_inventory}" \
		"${search_preimage_sha}" "${search_preimage_inventory_sha}"
	verify_artifact_file \
		"${theme_preimage_archive}" "${theme_preimage_inventory}" \
		"${theme_preimage_sha}" "${theme_preimage_inventory_sha}"
	verify_artifact_file \
		"${search_candidate_archive}" "${search_candidate_inventory}" \
		"${search_candidate_archive_sha}" "${search_candidate_inventory_sha}"
	verify_artifact_file \
		"${theme_candidate_archive}" "${theme_candidate_inventory}" \
		"${theme_candidate_archive_sha}" "${theme_candidate_inventory_sha}"
	bash "${packager}" --verify-only
	note "PASS: preimágenes y candidatos exactos Search ${search_candidate_version} + theme ${theme_candidate_version}"
}

compute_suite_sha256() {
	local relative absolute suite_file
	local -a fixed_files=(
		"ops/akibara-search-toolbar-production-promotion.sh"
		"ops/contracts/akibara-search-toolbar-0.1.10-theme-2.1.58.json"
		"ops/lib/akibara-promotion-config.sh"
		"ops/akibara-protected-commerce-fingerprint.php"
		"ops/akibara-search-state-fingerprint.php"
		"ops/test-akibara-search-toolbar-production-promotion.sh"
		"tools/akibara-releases/package-search-toolbar-production.sh"
		"tools/akibara-releases/test-search-toolbar-production-release.sh"
		"tools/akibara-releases/qa-search.sh"
	)
	local -a test_roots=(
		"public_html/wp-content/plugins/akibara-search/tests"
		"public_html/wp-content/themes/akibara-gp/tests"
	)

	{
		printf '@contract\takibara-search-toolbar-production-suite-v1\n'
		for relative in "${fixed_files[@]}"; do
			absolute="${repo_root}/${relative}"
			[[ -f "${absolute}" && ! -L "${absolute}" ]] \
				|| die "Falta archivo regular del gate: ${relative}"
			printf 'file\t%s\t%s\n' "${relative}" "$(sha256_file "${absolute}")"
		done
		for relative in "${test_roots[@]}"; do
			absolute="${repo_root}/${relative}"
			[[ -d "${absolute}" && ! -L "${absolute}" ]] \
				|| die "Falta raíz de tests: ${relative}"
			while IFS= read -r suite_file; do
				[[ "${suite_file}" == "${absolute}/"* ]] \
					|| die "Un test escapó de ${relative}"
				printf 'file\t%s\t%s\n' \
					"${suite_file#"${repo_root}/"}" "$(sha256_file "${suite_file}")"
			done < <(find "${absolute}" -type f -print | LC_ALL=C sort)
		done
	} | LC_ALL=C sort | shasum -a 256 | awk '{print $1}'
}

receipt_path() {
	local stage="$1"
	printf '%s/%s-passed-%s.json' "${gate_root}" "${stage}" "${release_digest}"
}

write_receipt() {
	local stage="$1" target="$2" receipt partial
	local suite_sha pipeline_sha contract_sha

	case "${stage}" in
		docker|production) ;;
		*) die "Etapa de recibo no soportada: ${stage}" ;;
	esac
	suite_sha="$(compute_suite_sha256)"
	pipeline_sha="$(sha256_file "${BASH_SOURCE[0]}")"
	contract_sha="$(sha256_file "${contract_file}")"
	mkdir -p -- "${gate_root}"
	receipt="$(receipt_path "${stage}")"
	partial="${receipt}.partial"
	jq -n \
		--arg stage "${stage}" \
		--arg release_id "${release_id}" \
		--arg release_digest "${release_digest}" \
		--arg search_archive_sha256 "${search_candidate_archive_sha}" \
		--arg theme_archive_sha256 "${theme_candidate_archive_sha}" \
		--arg pipeline_sha256 "${pipeline_sha}" \
		--arg suite_sha256 "${suite_sha}" \
		--arg contract_sha256 "${contract_sha}" \
		--arg target "${target}" \
		--arg completed_at_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
		'{
		  schema_version: 1,
		  stage: $stage,
		  status: "PASS",
		  release_id: $release_id,
		  release_digest: $release_digest,
		  search_archive_sha256: $search_archive_sha256,
		  theme_archive_sha256: $theme_archive_sha256,
		  pipeline_sha256: $pipeline_sha256,
		  suite_sha256: $suite_sha256,
		  contract_sha256: $contract_sha256,
		  target: $target,
		  completed_at_utc: $completed_at_utc
		}' > "${partial}"
	mv -- "${partial}" "${receipt}"
	(
		cd -- "$(dirname -- "${receipt}")"
		shasum -a 256 "$(basename -- "${receipt}")" \
			> "$(basename -- "${receipt}").sha256"
	)
	note "Recibo: ${receipt}"
}

verify_receipt() {
	local stage="$1" expected_target="${2:-}" receipt sidecar
	local suite_sha pipeline_sha contract_sha completed_epoch now_epoch

	receipt="$(receipt_path "${stage}")"
	sidecar="${receipt}.sha256"
	verify_regular_file "${receipt}"
	verify_regular_file "${sidecar}"
	(
		cd -- "$(dirname -- "${receipt}")"
		shasum -a 256 -c "$(basename -- "${sidecar}")" >/dev/null
	) || die "Checksum inválido para recibo ${stage}"
	suite_sha="$(compute_suite_sha256)"
	pipeline_sha="$(sha256_file "${BASH_SOURCE[0]}")"
	contract_sha="$(sha256_file "${contract_file}")"
	jq -e \
		--arg stage "${stage}" \
		--arg release_id "${release_id}" \
		--arg release_digest "${release_digest}" \
		--arg search_archive_sha256 "${search_candidate_archive_sha}" \
		--arg theme_archive_sha256 "${theme_candidate_archive_sha}" \
		--arg pipeline_sha256 "${pipeline_sha}" \
		--arg suite_sha256 "${suite_sha}" \
		--arg contract_sha256 "${contract_sha}" \
		--arg expected_target "${expected_target}" \
		'
		  type == "object"
		  and ((keys | sort) == [
		    "completed_at_utc", "contract_sha256", "pipeline_sha256",
		    "release_digest", "release_id", "schema_version",
		    "search_archive_sha256", "stage", "status", "suite_sha256",
		    "target", "theme_archive_sha256"
		  ])
		  and .schema_version == 1
		  and .stage == $stage
		  and .status == "PASS"
		  and .release_id == $release_id
		  and .release_digest == $release_digest
		  and .search_archive_sha256 == $search_archive_sha256
		  and .theme_archive_sha256 == $theme_archive_sha256
		  and .pipeline_sha256 == $pipeline_sha256
		  and .suite_sha256 == $suite_sha256
		  and .contract_sha256 == $contract_sha256
		  and ($expected_target == "" or .target == $expected_target)
		  and (.completed_at_utc | type == "string"
		    and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
		' "${receipt}" >/dev/null || die "Contrato de recibo rechazado: ${stage}"
	completed_epoch="$(jq -er '.completed_at_utc | fromdateiso8601' "${receipt}")" \
		|| die "Timestamp inválido en recibo ${stage}"
	now_epoch="$(date -u +%s)"
	((completed_epoch <= now_epoch + 300)) \
		|| die "Recibo ${stage} fechado en el futuro"
	((now_epoch - completed_epoch <= 86400)) \
		|| die "Recibo ${stage} vencido; repite el gate Docker"
}

run_local_gate() {
	verify_artifacts
	require_command docker
	note "Ejecutando release reproducible y PHP/JS exacto..."
	bash "${release_test}"
	note "Ejecutando Search real en Docker local..."
	bash "${repo_root}/tools/akibara-releases/qa-search.sh"
	note "Ejecutando regresión dry-run del carril productivo..."
	bash "${pipeline_test}"
	write_receipt docker local-compose
	note "PASS: gate Docker/local Search ${search_candidate_version} + theme ${theme_candidate_version}"
}

load_production_environment() {
	local config="$1"

	verify_regular_file "${config_library}"
	# shellcheck source=ops/lib/akibara-promotion-config.sh
	source "${config_library}"
	akb_load_promotion_config "${config}" \
		|| die "Configuración de promoción rechazada"
	akb_select_promotion_environment production \
		|| die "No se pudo seleccionar producción"
	[[ "${AKB_BASE_URL}" == "https://akibara.cl"
		&& "${AKB_WEBROOT}" =~ /public_html$ ]] \
		|| die "El carril solo admite el destino productivo exacto"
}

ssh_read() {
	local remote_script="$1" remote_command

	printf -v remote_command 'AKB_WEBROOT=%q bash -s' "${AKB_WEBROOT}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${remote_command}" <<< "${remote_script}"
}

ssh_logs_read() {
	local remote_script="$1" remote_command

	printf -v remote_command 'AKB_LOG_PATHS=%q bash -s' "${AKB_LOG_PATHS}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${remote_command}" <<< "${remote_script}"
}

remote_preflight() {
	local remote_script

	printf -v remote_script '
set -Eeuo pipefail
for required in awk cmp find grep head mktemp sha256sum stat tail tar tr wc wp; do
  command -v "${required}" >/dev/null
done
[[ "${AKB_WEBROOT}" =~ /public_html$ ]]
plugins_root="${AKB_WEBROOT}/wp-content/plugins"
themes_root="${AKB_WEBROOT}/wp-content/themes"
[[ -d "${plugins_root}/akibara-search" && ! -L "${plugins_root}/akibara-search" ]]
[[ -d "${themes_root}/akibara-gp" && ! -L "${themes_root}/akibara-gp" ]]
test -z "$(find "${plugins_root}/akibara-search" ! -type d ! -type f -print -quit)"
test -z "$(find "${themes_root}/akibara-gp" ! -type d ! -type f -print -quit)"
wp --path="${AKB_WEBROOT}" core is-installed
wp --path="${AKB_WEBROOT}" plugin is-active akibara-search
wp --path="${AKB_WEBROOT}" theme is-active akibara-gp
wp --path="${AKB_WEBROOT}" plugin is-active akibara-business
wp --path="${AKB_WEBROOT}" plugin is-active akibara-cart-checkout-blocks
wp --path="${AKB_WEBROOT}" plugin is-active seo-by-rank-math
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-business --field=version)" == %q ]]
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-cart-checkout-blocks --field=version)" == %q ]]
echo "SEARCH_TOOLBAR_PRODUCTION_PREFLIGHT_PASS"
' "${business_version}" "${checkout_version}"
	ssh_read "${remote_script}"
}

remote_inventory() {
	local kind="$1" slug="$2" output="$3" remote_script

	case "${kind}:${slug}" in
		plugin:akibara-search)
			remote_script='
set -Eeuo pipefail
runtime_root="${AKB_WEBROOT}/wp-content/plugins"
slug="akibara-search"
[[ -d "${runtime_root}/${slug}" && ! -L "${runtime_root}/${slug}" ]]
test -z "$(find "${runtime_root}/${slug}" ! -type d ! -type f -print -quit)"
cd "${runtime_root}"
find "${slug}" -type f -print | LC_ALL=C sort |
while IFS= read -r runtime_file; do sha256sum "${runtime_file}"; done'
			;;
		theme:akibara-gp)
			remote_script='
set -Eeuo pipefail
runtime_root="${AKB_WEBROOT}/wp-content/themes"
slug="akibara-gp"
[[ -d "${runtime_root}/${slug}" && ! -L "${runtime_root}/${slug}" ]]
test -z "$(find "${runtime_root}/${slug}" ! -type d ! -type f -print -quit)"
cd "${runtime_root}"
find "${slug}" -type f -print | LC_ALL=C sort |
while IFS= read -r runtime_file; do sha256sum "${runtime_file}"; done'
			;;
		*)
			die "Componente remoto no soportado: ${kind}:${slug}"
			;;
	esac
	ssh_read "${remote_script}" > "${output}.partial"
	[[ -s "${output}.partial" ]] \
		|| die "El inventario remoto quedó vacío: ${kind}:${slug}"
	mv -- "${output}.partial" "${output}"
}

verify_remote_state() {
	local search_version="$1" search_inventory="$2"
	local theme_version="$3" theme_inventory="$4" evidence_prefix="$5"
	local remote_script

	printf -v remote_script '
set -Eeuo pipefail
wp --path="${AKB_WEBROOT}" core is-installed
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-search --field=version)" == %q ]]
wp --path="${AKB_WEBROOT}" plugin is-active akibara-search
[[ "$(wp --path="${AKB_WEBROOT}" theme get akibara-gp --field=version)" == %q ]]
wp --path="${AKB_WEBROOT}" theme is-active akibara-gp
wp --path="${AKB_WEBROOT}" plugin is-active seo-by-rank-math
grep -Eq "^[[:space:]]*Template:[[:space:]]+generatepress[[:space:]]*$" \
  "${AKB_WEBROOT}/wp-content/themes/akibara-gp/style.css"
' "${search_version}" "${theme_version}"
	ssh_read "${remote_script}"
	remote_inventory plugin akibara-search "${evidence_prefix}.search.files.sha256"
	remote_inventory theme akibara-gp "${evidence_prefix}.theme.files.sha256"
	cmp -s "${search_inventory}" "${evidence_prefix}.search.files.sha256" \
		|| die "Search remoto ${search_version} difiere de su inventario exacto"
	cmp -s "${theme_inventory}" "${evidence_prefix}.theme.files.sha256" \
		|| die "Theme remoto ${theme_version} difiere de su inventario exacto"
}

capture_unchanged_runtime() {
	local output="$1" remote_script

	remote_script='
set -Eeuo pipefail
{
  wp --path="${AKB_WEBROOT}" plugin list \
    --fields=name,status,version --format=csv |
    awk -F, "NR == 1 || \$1 != \"akibara-search\"" |
    sed "1s/^/plugins:/"
  wp --path="${AKB_WEBROOT}" theme list \
    --fields=name,status,version --format=csv |
    awk -F, "NR == 1 || \$1 != \"akibara-gp\"" |
    sed "1s/^/themes:/"
} | LC_ALL=C sort'
	ssh_read "${remote_script}" > "${output}.partial"
	[[ -s "${output}.partial" ]] \
		|| die "El inventario de runtime invariante quedó vacío"
	mv -- "${output}.partial" "${output}"
	shasum -a 256 "${output}" > "${output}.sha256"
}

remote_artifact_gate() {
	local maintenance_state="$1" remote_script

	case "${maintenance_state}" in
		absent|active) ;;
		*) die "Estado de mantenimiento inválido: ${maintenance_state}" ;;
	esac
	printf -v remote_script '
set -Eeuo pipefail
plugin_root="${AKB_WEBROOT}/wp-content/plugins/akibara-search"
theme_root="${AKB_WEBROOT}/wp-content/themes/akibara-gp"
plugins_root="${AKB_WEBROOT}/wp-content/plugins"
themes_root="${AKB_WEBROOT}/wp-content/themes"
[[ -d "${plugin_root}" && ! -L "${plugin_root}" ]]
[[ -d "${theme_root}" && ! -L "${theme_root}" ]]
test -z "$(find "${plugin_root}" ! -type d ! -type f -print -quit)"
test -z "$(find "${theme_root}" ! -type d ! -type f -print -quit)"
test -z "$(find "${plugin_root}" "${theme_root}" -type f \( \
  -name "*.bak" -o -name "*.bk" -o -name "*.orig" -o -name "*.rej" -o \
  -name "*.tmp" -o -name "*.temp" -o -name "*.dump" -o -name "*.backup" -o \
  -name "*.sql" -o -name "*.sqlite" -o -name "*.tar" -o -name "*.tgz" -o \
  -name "*.zip" -o -name "*~" -o -name ".DS_Store" -o -name "._*" \
\) -print -quit)"
test -z "$(find "${plugins_root}" -maxdepth 1 \( \
  -name "akibara-search.new" -o -name "akibara-search.old" -o \
  -name "akibara-search.previous" -o -name "akibara-search.backup" -o \
  -name "akibara-search.bak" -o -name "akibara-search.tmp" -o \
  -name "akibara-search.temp" \
\) -print -quit)"
test -z "$(find "${themes_root}" -maxdepth 1 \( \
  -name "akibara-gp.new" -o -name "akibara-gp.old" -o \
  -name "akibara-gp.previous" -o -name "akibara-gp.backup" -o \
  -name "akibara-gp.bak" -o -name "akibara-gp.tmp" -o \
  -name "akibara-gp.temp" \
\) -print -quit)"
for upgrade_dir in \
  "${AKB_WEBROOT}/wp-content/upgrade" \
  "${AKB_WEBROOT}/wp-content/upgrade-temp-backup"; do
  if [[ -e "${upgrade_dir}" ]]; then
    [[ -d "${upgrade_dir}" && ! -L "${upgrade_dir}" ]]
    test -z "$(find "${upgrade_dir}" -mindepth 1 -print -quit)"
  fi
done
test -z "$(find /tmp -maxdepth 1 -name "akibara-search-toolbar.*" -print -quit 2>/dev/null)"
test -z "$(find "${AKB_WEBROOT}" -maxdepth 1 -name ".maintenance.akb.*" -print -quit)"
test -z "$(find "${AKB_WEBROOT}" -maxdepth 1 -type f \( \
  -name "*.bak" -o -name "*.bk" -o -name "*.orig" -o -name "*.rej" -o \
  -name "*.tmp" -o -name "*.temp" -o -name "*.dump" -o -name "*.backup" -o \
  -name "*.sql" -o -name "*.sqlite" -o -name "*.tar" -o -name "*.tgz" -o \
  -name "*.zip" -o -name "*~" -o -name ".DS_Store" -o -name "._*" \
\) -print -quit)"
if [[ %q == active ]]; then
  [[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
  printf "%%s\n" "<?php \$upgrading = time(); ?>" |
    cmp -s - "${AKB_WEBROOT}/.maintenance"
else
  [[ ! -e "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
fi
echo "SEARCH_TOOLBAR_ZERO_REMOTE_ARTIFACTS_PASS"
' "${maintenance_state}"
	ssh_read "${remote_script}"
}

cleanup_empty_upgrade_dirs() {
	local remote_script

	remote_script='
set -Eeuo pipefail
for upgrade_dir in \
  "${AKB_WEBROOT}/wp-content/upgrade" \
  "${AKB_WEBROOT}/wp-content/upgrade-temp-backup"; do
  if [[ -e "${upgrade_dir}" ]]; then
    [[ -d "${upgrade_dir}" && ! -L "${upgrade_dir}" ]]
    test -z "$(find "${upgrade_dir}" -mindepth 1 ! -type d -print -quit)"
    find "${upgrade_dir}" -depth -type d -empty -delete
  fi
done
echo "EMPTY_UPGRADE_DIRS_REMOVED"'
	ssh_read "${remote_script}"
}

stream_code_backup() {
	local output="$1" remote_script entry

	remote_script='
set -Eeuo pipefail
content_root="${AKB_WEBROOT}/wp-content"
[[ -d "${content_root}/plugins/akibara-search" && ! -L "${content_root}/plugins/akibara-search" ]]
[[ -d "${content_root}/themes/akibara-gp" && ! -L "${content_root}/themes/akibara-gp" ]]
test -z "$(find "${content_root}/plugins/akibara-search" ! -type d ! -type f -print -quit)"
test -z "$(find "${content_root}/themes/akibara-gp" ! -type d ! -type f -print -quit)"
tar -C "${content_root}" -czf - plugins/akibara-search themes/akibara-gp'
	ssh_read "${remote_script}" > "${output}.partial"
	tar -tzf "${output}.partial" >/dev/null
	while IFS= read -r entry; do
		case "${entry}" in
			plugins/akibara-search|plugins/akibara-search/*|themes/akibara-gp|themes/akibara-gp/*) ;;
			*) die "El respaldo contiene una ruta inesperada: ${entry}" ;;
		esac
		[[ "${entry}" != /* && "${entry}" != *'..'* && "${entry}" != *\\* ]] \
			|| die "El respaldo contiene una ruta insegura: ${entry}"
	done < <(tar -tzf "${output}.partial")
	mv -- "${output}.partial" "${output}"
	shasum -a 256 "${output}" > "${output}.sha256"
}

capture_log_baseline() {
	local output="$1" remote_script line_count index=0
	local state identity size prefix_sha log_label path_sha
	local expected_label expected_path_sha
	local -a configured_paths

	remote_script='
set -Eeuo pipefail
IFS=";" read -r -a log_paths <<< "${AKB_LOG_PATHS}"
((${#log_paths[@]} > 0))
log_index=0
for log_path in "${log_paths[@]}"; do
  log_index=$((log_index + 1))
  printf -v log_label "log-%02d" "${log_index}"
  path_sha="$(printf "%s" "${log_path}" | sha256sum | awk "{print \$1}")"
  [[ "${path_sha}" =~ ^[a-f0-9]{64}$ ]]
  if [[ -e "${log_path}" || -L "${log_path}" ]]; then
    [[ -f "${log_path}" && ! -L "${log_path}" ]]
    identity="$(stat -c "%d:%i" -- "${log_path}")"
    size="$(wc -c < "${log_path}" | tr -d "[:space:]")"
    [[ "${identity}" =~ ^[0-9]+:[0-9]+$ && "${size}" =~ ^[0-9]+$ ]]
    prefix_sha="$(head -c "${size}" -- "${log_path}" | sha256sum | awk "{print \$1}")"
    [[ "${prefix_sha}" =~ ^[a-f0-9]{64}$ ]]
    [[ "$(stat -c "%d:%i" -- "${log_path}")" == "${identity}" ]]
    final_size="$(wc -c < "${log_path}" | tr -d "[:space:]")"
    ((final_size >= size))
    [[ "$(
      head -c "${size}" -- "${log_path}" | sha256sum | awk "{print \$1}"
    )" == "${prefix_sha}" ]]
    printf "present\t%s\t%s\t%s\t%s\t%s\n" \
      "${identity}" "${size}" "${prefix_sha}" "${log_label}" "${path_sha}"
  else
    printf "absent\t-\t0\t-\t%s\t%s\n" "${log_label}" "${path_sha}"
  fi
done'
	if ! ssh_logs_read "${remote_script}" \
		> "${output}.partial" 2> "${output}.stderr.partial"; then
		redact_log_gate_output \
			"${output}.partial" "${output}.stdout.failed"
		redact_log_gate_output \
			"${output}.stderr.partial" "${output}.stderr.failed"
		mv -- "${output}.partial" "${output}.stdout.redacted"
		mv -- "${output}.stderr.partial" "${output}.stderr.redacted"
		die "No se pudo capturar el snapshot inicial de logs"
	fi
	if [[ -s "${output}.stderr.partial" ]]; then
		redact_log_gate_output \
			"${output}.partial" "${output}.stdout.failed"
		redact_log_gate_output \
			"${output}.stderr.partial" "${output}.stderr.failed"
		mv -- "${output}.partial" "${output}.stdout.redacted"
		mv -- "${output}.stderr.partial" "${output}.stderr.redacted"
		die "El snapshot inicial de logs produjo salida de error"
	fi
	rm -- "${output}.stderr.partial"
	[[ -s "${output}.partial" ]] \
		|| die "El snapshot inicial de logs quedó vacío"

	IFS=';' read -r -a configured_paths <<< "${AKB_LOG_PATHS}"
	line_count="$(wc -l < "${output}.partial" | tr -d '[:space:]')"
	if [[ "${line_count}" != "${#configured_paths[@]}" ]]; then
		redact_log_gate_output \
			"${output}.partial" "${output}.failed"
		mv -- "${output}.partial" "${output}.redacted"
		die "El snapshot de logs no cubrió todas las rutas allowlisted"
	fi
	while IFS=$'\t' read -r \
		state identity size prefix_sha log_label path_sha; do
		[[ "${index}" -lt "${#configured_paths[@]}" ]] \
			|| {
				redact_log_gate_output \
					"${output}.partial" "${output}.failed"
				mv -- "${output}.partial" "${output}.redacted"
				die "El snapshot devolvió más logs de los permitidos"
			}
		printf -v expected_label 'log-%02d' "$((index + 1))"
		expected_path_sha="$(
			printf '%s' "${configured_paths[${index}]}" \
				| shasum -a 256 | awk '{print $1}'
		)"
		[[ "${log_label}" == "${expected_label}"
			&& "${path_sha}" == "${expected_path_sha}"
			&& "${path_sha}" =~ ^[a-f0-9]{64}$ ]] \
			|| {
				redact_log_gate_output \
					"${output}.partial" "${output}.failed"
				mv -- "${output}.partial" "${output}.redacted"
				die "El snapshot devolvió identidad opaca de log inesperada"
			}
		case "${state}" in
			present)
				[[ "${identity}" =~ ^[0-9]+:[0-9]+$
					&& "${size}" =~ ^[0-9]+$
					&& "${prefix_sha}" =~ ^[a-f0-9]{64}$ ]] \
					|| {
						redact_log_gate_output \
							"${output}.partial" "${output}.failed"
						mv -- "${output}.partial" "${output}.redacted"
						die "Metadatos inválidos para ${expected_label} presente"
					}
				;;
			absent)
				[[ "${identity}" == "-" && "${size}" == 0
					&& "${prefix_sha}" == "-" ]] \
					|| {
						redact_log_gate_output \
							"${output}.partial" "${output}.failed"
						mv -- "${output}.partial" "${output}.redacted"
						die "Metadatos inválidos para ${expected_label} ausente"
					}
				;;
			*)
				redact_log_gate_output \
					"${output}.partial" "${output}.failed"
				mv -- "${output}.partial" "${output}.redacted"
				die "Estado inicial inválido para ${expected_label}"
				;;
		esac
		index=$((index + 1))
	done < "${output}.partial"
	[[ "${index}" == "${#configured_paths[@]}" ]] \
		|| die "El snapshot de logs quedó incompleto"
	mv -- "${output}.partial" "${output}"
	shasum -a 256 "${output}" > "${output}.sha256"
}

redact_log_gate_output() {
	local unsafe_output="$1" redacted_output="$2" digest

	[[ -f "${unsafe_output}" && ! -L "${unsafe_output}" ]] \
		|| die "No se pudo aislar la salida insegura del gate de logs"
	digest="$(sha256_file "${unsafe_output}")"
	printf '@akb-log-gate-output-redacted\t%s\n' "${digest}" > "${redacted_output}"
	: > "${unsafe_output}"
}

capture_log_delta() {
	local baseline="$1" output_dir="$2" remote_script remote_command
	local state baseline_identity baseline_size baseline_prefix_sha
	local log_label path_sha log_path expected_label expected_path_sha index=0
	local metadata marker event current_identity current_size delta_size
	local delta_sha scan_status marker_label marker_path_sha line_count
	local -a configured_paths

	verify_regular_file "${baseline}"
	[[ ! -e "${output_dir}" && ! -L "${output_dir}" ]] \
		|| die "El destino local de deltas de log ya existe"
	mkdir -p -- "${output_dir}"
	: > "${output_dir}/manifest.tsv"

	remote_script='
set -Eeuo pipefail
log_path="${AKB_LOG_PATH}"
log_label="${AKB_LOG_LABEL}"
path_sha="${AKB_LOG_PATH_SHA}"
baseline_state="${AKB_LOG_STATE}"
baseline_identity="${AKB_LOG_IDENTITY}"
baseline_size="${AKB_LOG_SIZE}"
baseline_prefix_sha="${AKB_LOG_PREFIX_SHA}"
[[ "${log_label}" =~ ^log-[0-9]{2}$ ]]
[[ "${path_sha}" =~ ^[a-f0-9]{64}$ ]]
[[ "${baseline_state}" == "present" || "${baseline_state}" == "absent" ]]
[[ "${baseline_size}" =~ ^[0-9]+$ ]]
log_pattern="PHP[[:space:]]+(Fatal error|Parse error|Warning|Recoverable fatal error|Catchable fatal error)|Uncaught([[:space:]]|:)|(^|[][[:space:]:])(FATAL|ERROR|WARNING)([][[:space:]:]|$)|WordPress database error|Allowed memory size|Maximum execution time"
empty_sha="$(printf "" | sha256sum | awk "{print \$1}")"

if [[ "${baseline_state}" == "absent" ]]; then
  if [[ -e "${log_path}" || -L "${log_path}" ]]; then
    [[ -f "${log_path}" && ! -L "${log_path}" ]]
    event="created"
    start_byte=1
  else
    [[ ! -e "${log_path}" && ! -L "${log_path}" ]]
    printf "@akb-log-delta\tabsent\t-\t0\t0\t%s\tclean\t%s\t%s\n" \
      "${empty_sha}" "${log_label}" "${path_sha}"
    exit 0
  fi
else
  [[ -f "${log_path}" && ! -L "${log_path}" ]]
  event="append"
  start_byte=$((baseline_size + 1))
fi

current_identity="$(stat -c "%d:%i" -- "${log_path}")"
current_size="$(wc -c < "${log_path}" | tr -d "[:space:]")"
[[ "${current_identity}" =~ ^[0-9]+:[0-9]+$ && "${current_size}" =~ ^[0-9]+$ ]]
if [[ "${baseline_state}" == "present" ]]; then
  [[ "${current_identity}" == "${baseline_identity}" ]] || {
    printf "Log rotado durante el release: %s\n" "${log_label}" >&2
    exit 71
  }
  ((current_size >= baseline_size)) || {
    printf "Log truncado durante el release: %s\n" "${log_label}" >&2
    exit 72
  }
  current_prefix_sha="$(
    head -c "${baseline_size}" -- "${log_path}" | sha256sum | awk "{print \$1}"
  )"
  [[ "${current_prefix_sha}" == "${baseline_prefix_sha}" ]] || {
    printf "Prefijo de log reescrito durante el release: %s\n" "${log_label}" >&2
    exit 74
  }
fi
delta_size=$((current_size - baseline_size))
delta_stream() {
  if ((delta_size > 0)); then
    tail -c "+${start_byte}" -- "${log_path}" | head -c "${delta_size}"
  fi
}
delta_sha="$(delta_stream | sha256sum | awk "{print \$1}")"
[[ "${delta_sha}" =~ ^[a-f0-9]{64}$ ]]
scan_status="clean"
if ((delta_size > 0)); then
  if delta_stream | grep -Ei "${log_pattern}" >/dev/null; then
    scan_status="blocked"
  else
    grep_status=$?
    [[ "${grep_status}" == 1 ]] || {
      printf "No se pudo analizar el delta de log: %s\n" "${log_label}" >&2
      exit 76
    }
  fi
fi
final_identity="$(stat -c "%d:%i" -- "${log_path}")"
final_size="$(wc -c < "${log_path}" | tr -d "[:space:]")"
[[ "${final_identity}" == "${current_identity}" ]] || {
  printf "Log rotado durante su captura: %s\n" "${log_label}" >&2
  exit 73
}
[[ "${final_size}" == "${current_size}" ]] || {
  printf "Tamaño de log cambió durante su captura: %s\n" "${log_label}" >&2
  exit 77
}
if [[ "${baseline_state}" == "present" ]]; then
  [[ "$(
    head -c "${baseline_size}" -- "${log_path}" | sha256sum | awk "{print \$1}"
  )" == "${baseline_prefix_sha}" ]] || {
    printf "Prefijo de log cambió durante su captura: %s\n" "${log_label}" >&2
    exit 75
  }
fi
final_delta_sha="$(delta_stream | sha256sum | awk "{print \$1}")"
[[ "${final_delta_sha}" == "${delta_sha}" ]] || {
  printf "Delta de log cambió durante su captura: %s\n" "${log_label}" >&2
  exit 78
}
printf "@akb-log-delta\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n" \
  "${event}" "${current_identity}" "${current_size}" "${delta_size}" \
  "${delta_sha}" "${scan_status}" "${log_label}" "${path_sha}"'

	IFS=';' read -r -a configured_paths <<< "${AKB_LOG_PATHS}"
	while IFS=$'\t' read -r \
		state baseline_identity baseline_size baseline_prefix_sha \
		log_label path_sha; do
		index=$((index + 1))
		[[ "${index}" -le "${#configured_paths[@]}" ]] \
			|| die "El baseline contiene más logs de los permitidos"
		printf -v expected_label 'log-%02d' "${index}"
		log_path="${configured_paths[$((index - 1))]}"
		expected_path_sha="$(
			printf '%s' "${log_path}" | shasum -a 256 | awk '{print $1}'
		)"
		[[ "${log_label}" == "${expected_label}"
			&& "${path_sha}" == "${expected_path_sha}" ]] \
			|| die "El baseline no coincide con ${expected_label}"
		metadata="${output_dir}/${expected_label}.tsv"
		printf -v remote_command \
			'AKB_LOG_PATH=%q AKB_LOG_LABEL=%q AKB_LOG_PATH_SHA=%q AKB_LOG_STATE=%q AKB_LOG_IDENTITY=%q AKB_LOG_SIZE=%q AKB_LOG_PREFIX_SHA=%q bash -s' \
			"${log_path}" "${expected_label}" "${expected_path_sha}" \
			"${state}" "${baseline_identity}" "${baseline_size}" \
			"${baseline_prefix_sha}"
		if ! ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
			"${AKB_HOST}" "${remote_command}" <<< "${remote_script}" \
			> "${metadata}.partial" 2> "${metadata}.stderr.partial"; then
			redact_log_gate_output \
				"${metadata}.partial" "${metadata}.failed"
			redact_log_gate_output \
				"${metadata}.stderr.partial" "${metadata}.stderr.failed"
			mv -- "${metadata}.partial" "${metadata}.redacted"
			mv -- "${metadata}.stderr.partial" "${metadata}.stderr.redacted"
			die "No se pudo demostrar continuidad de ${expected_label}"
		fi
		if [[ -s "${metadata}.stderr.partial" ]]; then
			redact_log_gate_output \
				"${metadata}.partial" "${metadata}.failed"
			redact_log_gate_output \
				"${metadata}.stderr.partial" "${metadata}.stderr.failed"
			mv -- "${metadata}.partial" "${metadata}.redacted"
			mv -- "${metadata}.stderr.partial" "${metadata}.stderr.redacted"
			die "El gate remoto produjo salida de error para ${expected_label}"
		fi
		rm -- "${metadata}.stderr.partial"
		line_count="$(wc -l < "${metadata}.partial" | tr -d '[:space:]')"
		if [[ "${line_count}" != 1 ]]; then
			redact_log_gate_output \
				"${metadata}.partial" "${metadata}.failed"
			mv -- "${metadata}.partial" "${metadata}.redacted"
			die "El gate remoto expuso contenido no permitido del log"
		fi
		IFS=$'\t' read -r \
			marker event current_identity current_size delta_size \
			delta_sha scan_status marker_label marker_path_sha \
			< "${metadata}.partial"
		if ! [[ "${marker}" == "@akb-log-delta"
			&& "${marker_label}" == "${expected_label}"
			&& "${marker_path_sha}" == "${expected_path_sha}"
			&& "${current_size}" =~ ^[0-9]+$
			&& "${delta_size}" =~ ^[0-9]+$
			&& "${delta_sha}" =~ ^[a-f0-9]{64}$
			&& ( "${scan_status}" == "clean" || "${scan_status}" == "blocked" ) ]]; then
			redact_log_gate_output \
				"${metadata}.partial" "${metadata}.failed"
			mv -- "${metadata}.partial" "${metadata}.redacted"
			die "Metadatos inválidos en delta de ${expected_label}"
		fi
		case "${event}" in
			absent)
				if ! [[ "${state}" == "absent" && "${current_identity}" == "-"
					&& "${current_size}" == 0 && "${delta_size}" == 0
					&& "${scan_status}" == "clean" ]]; then
					redact_log_gate_output \
						"${metadata}.partial" "${metadata}.failed"
					mv -- "${metadata}.partial" "${metadata}.redacted"
					die "Delta ausente inválido para ${expected_label}"
				fi
				;;
			created)
				if ! [[ "${state}" == "absent"
					&& "${current_identity}" =~ ^[0-9]+:[0-9]+$
					&& "${current_size}" == "${delta_size}" ]]; then
					redact_log_gate_output \
						"${metadata}.partial" "${metadata}.failed"
					mv -- "${metadata}.partial" "${metadata}.redacted"
					die "Creación de log inválida para ${expected_label}"
				fi
				;;
			append)
				if ! [[ "${state}" == "present"
					&& "${current_identity}" == "${baseline_identity}"
					&& $((current_size - baseline_size)) == "${delta_size}" ]]; then
					redact_log_gate_output \
						"${metadata}.partial" "${metadata}.failed"
					mv -- "${metadata}.partial" "${metadata}.redacted"
					die "Continuidad o tamaño de append inválido para ${expected_label}"
				fi
				;;
			*)
				redact_log_gate_output \
					"${metadata}.partial" "${metadata}.failed"
				mv -- "${metadata}.partial" "${metadata}.redacted"
				die "Evento de log inválido: ${event}"
				;;
		esac
		mv -- "${metadata}.partial" "${metadata}"
		cat "${metadata}" >> "${output_dir}/manifest.tsv"
		shasum -a 256 "${metadata}" > "${metadata}.sha256"
		if [[ "${scan_status}" == "blocked" ]]; then
			die "El smoke generó errores o warnings nuevos en logs productivos"
		fi
	done < "${baseline}"
	((index > 0)) || die "No se analizaron rutas de log productivas"
	shasum -a 256 "${output_dir}/manifest.tsv" \
		> "${output_dir}/manifest.tsv.sha256"
	note "PRODUCTION_LOG_DELTA_PASS"
}

activate_maintenance() {
	local remote_script

	remote_script='
set -Eeuo pipefail
maintenance_file="${AKB_WEBROOT}/.maintenance"
[[ ! -e "${maintenance_file}" && ! -L "${maintenance_file}" ]]
maintenance_tmp="$(mktemp "${AKB_WEBROOT}/.maintenance.akb.XXXXXX")"
cleanup() { [[ -z "${maintenance_tmp:-}" ]] || rm -f -- "${maintenance_tmp}"; }
trap "cleanup" EXIT
trap "exit 129" HUP
trap "exit 130" INT
trap "exit 143" TERM
chmod 0644 "${maintenance_tmp}"
printf "%s\n" "<?php \$upgrading = time(); ?>" > "${maintenance_tmp}"
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_tmp}"
ln -- "${maintenance_tmp}" "${maintenance_file}"
rm -f -- "${maintenance_tmp}"
maintenance_tmp=""
trap - EXIT HUP INT TERM
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
echo "MAINTENANCE_MODE_ACTIVE"'
	ssh_read "${remote_script}"
}

ensure_maintenance() {
	local remote_script

	remote_script='
set -Eeuo pipefail
maintenance_file="${AKB_WEBROOT}/.maintenance"
if [[ -e "${maintenance_file}" || -L "${maintenance_file}" ]]; then
  [[ -f "${maintenance_file}" && ! -L "${maintenance_file}" ]]
  printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
else
  maintenance_tmp="$(mktemp "${AKB_WEBROOT}/.maintenance.akb.XXXXXX")"
  cleanup() { [[ -z "${maintenance_tmp:-}" ]] || rm -f -- "${maintenance_tmp}"; }
  trap "cleanup" EXIT
  chmod 0644 "${maintenance_tmp}"
  printf "%s\n" "<?php \$upgrading = time(); ?>" > "${maintenance_tmp}"
  ln -- "${maintenance_tmp}" "${maintenance_file}"
  rm -f -- "${maintenance_tmp}"
  maintenance_tmp=""
  trap - EXIT
fi
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
echo "MAINTENANCE_MODE_ENSURED"'
	ssh_read "${remote_script}"
}

verify_maintenance() {
	local remote_script
	remote_script='
set -Eeuo pipefail
[[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${AKB_WEBROOT}/.maintenance"
echo "MAINTENANCE_MODE_VERIFIED"'
	ssh_read "${remote_script}"
}

deactivate_maintenance() {
	local remote_script
	remote_script='
set -Eeuo pipefail
maintenance_file="${AKB_WEBROOT}/.maintenance"
[[ -f "${maintenance_file}" && ! -L "${maintenance_file}" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
rm -f -- "${maintenance_file}"
[[ ! -e "${maintenance_file}" && ! -L "${maintenance_file}" ]]
echo "MAINTENANCE_MODE_INACTIVE"'
	ssh_read "${remote_script}"
}

purge_caches_under_maintenance() {
	local remote_script
	remote_script='
set -Eeuo pipefail
[[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
wp --path="${AKB_WEBROOT}" cache flush >/dev/null
if wp --path="${AKB_WEBROOT}" plugin is-active litespeed-cache; then
  wp --path="${AKB_WEBROOT}" eval "if (! class_exists(\"LiteSpeed\\\\Purge\")) { throw new RuntimeException(\"LiteSpeed Purge unavailable\"); } \\LiteSpeed\\Purge::purge_all(\"Search toolbar promotion\");" >/dev/null
fi
[[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
echo "RUNTIME_CACHES_PURGED_UNDER_MAINTENANCE"'
	ssh_read "${remote_script}"
}

purge_caches_live() {
	local remote_script
	remote_script='
set -Eeuo pipefail
[[ ! -e "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
wp --path="${AKB_WEBROOT}" cache flush >/dev/null
if wp --path="${AKB_WEBROOT}" plugin is-active litespeed-cache; then
  wp --path="${AKB_WEBROOT}" litespeed-purge all >/dev/null
fi
echo "RUNTIME_CACHES_PURGED_LIVE"'
	ssh_read "${remote_script}"
}

maintenance_http_gate() {
	local probe http_code headers

	probe="/?akb-search-toolbar-maintenance=$(date -u +%s)-$$"
	headers="$(mktemp "${TMPDIR:-/tmp}/akibara-search-toolbar-maintenance.XXXXXX")"
	trap 'case "${headers:-}" in */akibara-search-toolbar-maintenance.*) rm -f -- "${headers}" ;; esac' RETURN
	http_code="$(
		curl --silent --show-error \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 45 \
			--header 'Cache-Control: no-cache, no-store, max-age=0' \
			--header 'Pragma: no-cache' \
			--dump-header "${headers}" \
			--output /dev/null --write-out '%{http_code}' \
			"${AKB_BASE_URL}${probe}"
	)"
	[[ "${http_code}" == 503 ]] \
		|| die "Mantenimiento esperaba 503 para ${probe}; recibió ${http_code}"
	if grep -Eiq '^cf-cache-status:[[:space:]]*(HIT|STALE|UPDATING)([[:space:]]|$)' "${headers}"; then
		die "Cloudflare sirvió desde caché la prueba de mantenimiento"
	fi
	trap - RETURN
	rm -f -- "${headers}"
	note "MAINTENANCE_HTTP_503_PASS"
}

validate_commerce_fingerprint() {
	local snapshot="$1"
	[[ -f "${snapshot}" && ! -L "${snapshot}" && -s "${snapshot}" ]] \
		|| die "Fingerprint comercial ausente"
	LC_ALL=C sort -c "${snapshot}" >/dev/null 2>&1 \
		|| die "Fingerprint comercial no está ordenado"
	grep -Fq $'@dataset\tlegacy_orders\t' "${snapshot}" \
		|| die "Fingerprint comercial incompleto: legacy_orders"
	grep -Fq $'@dataset\thpos_orders\t' "${snapshot}" \
		|| die "Fingerprint comercial incompleto: hpos_orders"
	grep -Fq $'@dataset\twordpress_users\t' "${snapshot}" \
		|| die "Fingerprint comercial incompleto: wordpress_users"
	grep -Fq $'@dataset\twoocommerce_order_items\t' "${snapshot}" \
		|| die "Fingerprint comercial incompleto: order_items"
}

validate_search_fingerprint() {
	local snapshot="$1" marker
	[[ -f "${snapshot}" && ! -L "${snapshot}" && -s "${snapshot}" ]] \
		|| die "Fingerprint Search ausente"
	LC_ALL=C sort -c "${snapshot}" >/dev/null 2>&1 \
		|| die "Fingerprint Search no está ordenado"
	for marker in \
		$'@table\takb_search_docs\t' \
		$'@table\takb_search_tokens\t' \
		$'@table\takb_search_facet_index\t' \
		$'@table\takb_search_qlog\t' \
		$'@dataset\tsearch_options\tpresent' \
		$'@dataset\tsearch_arrival_postmeta\tpresent'; do
		grep -Fq "${marker}" "${snapshot}" \
			|| die "Fingerprint Search incompleto: ${marker}"
	done
}

capture_fingerprint() {
	local kind="$1" output="$2" php_file php_code remote_script

	case "${kind}" in
		commerce) php_file="${commerce_fingerprint_php}" ;;
		search) php_file="${search_fingerprint_php}" ;;
		*) die "Fingerprint desconocido: ${kind}" ;;
	esac
	verify_regular_file "${php_file}"
	php_code="$(sed '1{/^<?php$/d;}' "${php_file}")"
	printf -v remote_script 'set -Eeuo pipefail; wp --path="${AKB_WEBROOT}" eval %q' \
		"${php_code}"
	ssh_read "${remote_script}" > "${output}.partial"
	LC_ALL=C sort "${output}.partial" > "${output}"
	rm -f -- "${output}.partial"
	if [[ "${kind}" == commerce ]]; then
		validate_commerce_fingerprint "${output}"
	else
		validate_search_fingerprint "${output}"
	fi
	shasum -a 256 "${output}" > "${output}.sha256"
}

install_component() {
	local kind="$1" slug="$2" version="$3" archive_sha="$4" archive="$5"
	local remote_command remote_script

	case "${kind}:${slug}" in
		plugin:akibara-search)
			remote_script='
set -Eeuo pipefail
remote_zip="$(mktemp --suffix=.zip /tmp/akibara-search-toolbar.search.XXXXXX)"
cleanup() { rm -f -- "${remote_zip}"; }
trap "cleanup || true" EXIT
trap "exit 129" HUP
trap "exit 130" INT
trap "exit 143" TERM
cat > "${remote_zip}"
[[ "$(sha256sum "${remote_zip}" | awk "{print \$1}")" == "${AKB_SHA}" ]]
wp --path="${AKB_WEBROOT}" plugin install "${remote_zip}" --force
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-search --field=version)" == "${AKB_VERSION}" ]]
wp --path="${AKB_WEBROOT}" plugin is-active akibara-search
cleanup
trap - EXIT HUP INT TERM
[[ ! -e "${remote_zip}" ]]'
			;;
		theme:akibara-gp)
			remote_script='
set -Eeuo pipefail
remote_zip="$(mktemp --suffix=.zip /tmp/akibara-search-toolbar.theme.XXXXXX)"
cleanup() { rm -f -- "${remote_zip}"; }
trap "cleanup || true" EXIT
trap "exit 129" HUP
trap "exit 130" INT
trap "exit 143" TERM
cat > "${remote_zip}"
[[ "$(sha256sum "${remote_zip}" | awk "{print \$1}")" == "${AKB_SHA}" ]]
wp --path="${AKB_WEBROOT}" theme install "${remote_zip}" --force
[[ "$(wp --path="${AKB_WEBROOT}" theme get akibara-gp --field=version)" == "${AKB_VERSION}" ]]
wp --path="${AKB_WEBROOT}" theme is-active akibara-gp
wp --path="${AKB_WEBROOT}" plugin is-active seo-by-rank-math
cleanup
trap - EXIT HUP INT TERM
[[ ! -e "${remote_zip}" ]]'
			;;
		*)
			die "Instalación no soportada: ${kind}:${slug}"
			;;
	esac
	printf -v remote_command \
		'AKB_WEBROOT=%q AKB_VERSION=%q AKB_SHA=%q bash -c %q' \
		"${AKB_WEBROOT}" "${version}" "${archive_sha}" "${remote_script}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${remote_command}" < "${archive}"
}

restore_code() {
	local backup="$1" remote_command remote_script

	verify_regular_file "${backup}"
	verify_regular_file "${backup}.sha256"
	(
		cd -- "$(dirname -- "${backup}")"
		shasum -a 256 -c "$(basename -- "${backup}.sha256")" >/dev/null
	) || die "Checksum del respaldo local inválido"
	tar -tzf "${backup}" >/dev/null
	remote_script='
set -Eeuo pipefail
content_root="${AKB_WEBROOT}/wp-content"
plugin_target="${content_root}/plugins/akibara-search"
theme_target="${content_root}/themes/akibara-gp"
[[ -d "${content_root}/plugins" && ! -L "${content_root}/plugins" ]]
[[ -d "${content_root}/themes" && ! -L "${content_root}/themes" ]]
rm -rf -- "${plugin_target}" "${theme_target}"
tar -C "${content_root}" -xzf -
[[ -d "${plugin_target}" && ! -L "${plugin_target}" ]]
[[ -d "${theme_target}" && ! -L "${theme_target}" ]]
test -z "$(find "${plugin_target}" ! -type d ! -type f -print -quit)"
test -z "$(find "${theme_target}" ! -type d ! -type f -print -quit)"
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-search --field=version)" == "${AKB_SEARCH_VERSION}" ]]
wp --path="${AKB_WEBROOT}" plugin is-active akibara-search
[[ "$(wp --path="${AKB_WEBROOT}" theme get akibara-gp --field=version)" == "${AKB_THEME_VERSION}" ]]
wp --path="${AKB_WEBROOT}" theme is-active akibara-gp'
	printf -v remote_command \
		'AKB_WEBROOT=%q AKB_SEARCH_VERSION=%q AKB_THEME_VERSION=%q bash -c %q' \
		"${AKB_WEBROOT}" "${search_preimage_version}" \
		"${theme_preimage_version}" "${remote_script}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${remote_command}" < "${backup}"
}

verify_http_semantics() {
	local evidence_dir="$1" count_endpoint suggest_endpoint
	local opm_count opm_alias_count opm_series_count gannibal_count
	local atelier_count atalier_count suggest_payload opm_shop gannibal_shop
	local home_code shop_code

	[[ -d "${evidence_dir}" && ! -L "${evidence_dir}" ]] \
		|| die "Directorio de evidencia HTTP ausente o inseguro"
	count_endpoint="${AKB_BASE_URL}/wp-json/akibara/v1/search/count"
	suggest_endpoint="${AKB_BASE_URL}/wp-json/akibara/v1/search/suggest"
	count_total() {
		curl --silent --show-error \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 45 \
			--retry 2 --retry-delay 2 --retry-all-errors \
			--get --data-urlencode "$1=$2" "${count_endpoint}" \
			| jq -er '.total | select(type == "number" and floor == .)'
	}
	opm_count="$(count_total _buscar 'one punch man')"
	opm_alias_count="$(count_total _buscar opm)"
	opm_series_count="$(count_total _serie one-punch-man)"
	gannibal_count="$(count_total _buscar Gannibal)"
	atelier_count="$(count_total _buscar atelier)"
	atalier_count="$(count_total _buscar atalier)"
	[[ "${opm_count}" -gt 0 && "${opm_count}" -lt 200
		&& "${opm_count}" == "${opm_alias_count}"
		&& "${opm_count}" == "${opm_series_count}" ]] \
		|| die "OPM no coincide con el universo semántico real de la serie"
	[[ "${gannibal_count}" == 12 ]] \
		|| die "Gannibal dejó de conservar sus 12 tomos"
	[[ "${atelier_count}" -gt 0 && "${atelier_count}" == "${atalier_count}" ]] \
		|| die "La recuperación atalier→atelier perdió paridad"

	suggest_payload="$(
		curl --silent --show-error \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 45 \
			--retry 2 --retry-delay 2 --retry-all-errors \
			--get --data-urlencode 'q=one punch man' "${suggest_endpoint}"
	)"
	jq -e --argjson expected "${opm_count}" '
	  .total == $expected
	  and (.items | type == "array" and length > 0 and length <= 7)
	  and ([.items[].title] | all(test("^One Punch-Man ")))
	' <<< "${suggest_payload}" >/dev/null \
		|| die "Suggest OPM no coincide con el total o títulos esperados"

	opm_shop="$(
		curl --silent --show-error --location \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 60 \
			--get --data-urlencode '_buscar=one punch man' \
			"${AKB_BASE_URL}/tienda/"
	)"
	gannibal_shop="$(
		curl --silent --show-error --location \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 60 \
			--get --data-urlencode '_buscar=Gannibal' \
			"${AKB_BASE_URL}/tienda/"
	)"
	grep -Eq 'data-akb-count[^>]+data-akb-unit="tomo"[^>]*>[^<]*[0-9.]+ tomos?<' \
		<<< "${opm_shop}" \
		|| die "El shop OPM no etiqueta el total como tomos"
	grep -Eq 'data-akb-count[^>]+data-akb-unit="tomo"[^>]*>12 tomos<' \
		<<< "${gannibal_shop}" \
		|| die "El shop Gannibal no muestra 12 tomos"
	grep -Fq 'woocommerce-ordering' <<< "${gannibal_shop}" \
		|| die "El ordenamiento desapareció del shop Gannibal"
	grep -Fq 'akb-shop-toolbar' <<< "${gannibal_shop}" \
		|| die "La toolbar estable desapareció del shop Gannibal"

	home_code="$(
		curl --silent --show-error --location \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 45 \
			--output /dev/null --write-out '%{http_code}' "${AKB_BASE_URL}/"
	)"
	shop_code="$(
		curl --silent --show-error --location \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 45 \
			--output /dev/null --write-out '%{http_code}' "${AKB_BASE_URL}/tienda/"
	)"
	[[ "${home_code}" == 200 && "${shop_code}" == 200 ]] \
		|| die "Frontend canónico no respondió 200"
	jq -n \
		--argjson one_punch_man "${opm_count}" \
		--argjson opm "${opm_alias_count}" \
		--argjson series "${opm_series_count}" \
		--argjson gannibal "${gannibal_count}" \
		--argjson atelier "${atelier_count}" \
		--argjson atalier "${atalier_count}" \
		'{
		  status: "PASS",
		  counts: {
		    one_punch_man: $one_punch_man,
		    opm: $opm,
		    series: $series,
		    gannibal: $gannibal,
		    atelier: $atelier,
		    atalier: $atalier
		  },
		  units: {one_punch_man: "tomo", gannibal: "tomo"},
		  ordering: "present"
		}' > "${evidence_dir}/search-toolbar-http.json"
	shasum -a 256 "${evidence_dir}/search-toolbar-http.json" \
		> "${evidence_dir}/search-toolbar-http.json.sha256"
	note "SEARCH_TOOLBAR_HTTP_SEMANTICS_PASS"
}

cleanup_on_failure() {
	local exit_status="$1" rollback_safe=1

	((exit_status != 0)) || return 0
	set +e
	if ((mutation_started)); then
		note "Fallo tras iniciar mutación: restaurando Search y theme desde el único respaldo local."
		if ! ensure_maintenance; then
			rollback_safe=0
		else
			maintenance_active=1
		fi
		if ((rollback_safe)) && ! restore_code "${code_backup}"; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && ! purge_caches_under_maintenance; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && ! verify_remote_state \
			"${search_preimage_version}" "${search_preimage_inventory}" \
			"${theme_preimage_version}" "${theme_preimage_inventory}" \
			"${active_evidence_dir}/remote-after-rollback"; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && [[ -f "${active_evidence_dir}/commerce-before.tsv" ]]; then
			capture_fingerprint commerce \
				"${active_evidence_dir}/commerce-after-rollback.tsv" \
				|| rollback_safe=0
			cmp -s \
				"${active_evidence_dir}/commerce-before.tsv" \
				"${active_evidence_dir}/commerce-after-rollback.tsv" \
				|| rollback_safe=0
		fi
		if ((rollback_safe)) && [[ -f "${active_evidence_dir}/search-before.tsv" ]]; then
			capture_fingerprint search \
				"${active_evidence_dir}/search-after-rollback.tsv" \
				|| rollback_safe=0
			cmp -s \
				"${active_evidence_dir}/search-before.tsv" \
				"${active_evidence_dir}/search-after-rollback.tsv" \
				|| rollback_safe=0
		fi
	fi
	if ((maintenance_active)); then
		if ((!mutation_started)) && ! ensure_maintenance; then
			rollback_safe=0
		fi
		if ((rollback_safe)); then
			cleanup_empty_upgrade_dirs || rollback_safe=0
			remote_artifact_gate active || rollback_safe=0
			if ((rollback_safe)); then
				deactivate_maintenance || rollback_safe=0
				maintenance_active=0
				purge_caches_live || rollback_safe=0
				remote_artifact_gate absent || rollback_safe=0
			fi
		else
			note "Fail-closed: mantenimiento queda activo porque el rollback no pudo probarse."
		fi
	fi
	set -e
}

promotion_exit_handler() {
	local exit_status=$?
	trap - EXIT HUP INT TERM
	cleanup_on_failure "${exit_status}"
	exit "${exit_status}"
}

promotion_signal_handler() {
	local exit_status="$1"
	trap - EXIT HUP INT TERM
	cleanup_on_failure "${exit_status}"
	exit "${exit_status}"
}

render_plan() {
	verify_artifacts
	cat <<EOF
DRY RUN — no se ejecutó ssh, curl ni escritura remota.

Release: ${release_id}
Digest:  ${release_digest}
Destino: production
Host:    ${AKB_HOST}
Webroot: ${AKB_WEBROOT}
URL:     ${AKB_BASE_URL}

Secuencia fail-closed:
  1. Verificar preimágenes selladas Search 0.1.9 y theme 2.1.58.
  2. Verificar candidatos reproducibles Search 0.1.10 y theme 2.1.58.
  3. Exigir recibo Docker/local de 24 horas ligado a artefactos, contrato,
     pipeline y suite completa.
  4. Exigir en producción las dos preimágenes e inventarios exactos.
  5. Exigir Business 1.98.2, Blocks 2.1.6 y Rank Math activos e invariantes.
  6. Rechazar respaldos, ZIP, temporales, AppleDouble y dirs upgrade no vacíos.
  7. Transmitir ambos árboles en un único tar hacia respaldo exclusivamente local.
  8. Activar mantenimiento y demostrar HTTP 503 no servido desde caché.
  9. Capturar fingerprints completos y offsets/inodos de logs allowlisted.
 10. Instalar Search 0.1.10 y luego theme 2.1.58 mediante mktemp remoto con trap.
 11. Exigir inventarios finales exactos, runtime ajeno y fingerprints intactos.
 12. Purgar, validar OPM/Gannibal/Atelier/unidades/ordering y rechazar errores
     o warnings nuevos; rotación o truncado de logs falla de forma cerrada.
 13. Exigir cero artefactos remotos y emitir recibo productivo local.

No existe etapa, conexión, inspección ni mutación QA en este carril.
EOF
}

promote_production() {
	local stamp preimage_prefix final_prefix

	verify_artifacts
	verify_receipt docker local-compose
	for command_name in cmp curl find grep jq sed shasum ssh tar; do
		require_command "${command_name}"
	done
	stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"
	active_backup_dir="${repo_root}/.local-backups/search-toolbar-production/production/${stamp}-${release_digest}"
	active_evidence_dir="${active_backup_dir}/evidence"
	mkdir -p -- "${active_backup_dir}/code" "${active_evidence_dir}"
	chmod 0700 "${active_backup_dir}"
	code_backup="${active_backup_dir}/code/search-theme-before.tar.gz"
	preimage_prefix="${active_evidence_dir}/remote-preimage"
	final_prefix="${active_evidence_dir}/remote-final"

	trap promotion_exit_handler EXIT
	trap 'promotion_signal_handler 129' HUP
	trap 'promotion_signal_handler 130' INT
	trap 'promotion_signal_handler 143' TERM

	remote_preflight | tee "${active_evidence_dir}/remote-preflight.txt"
	verify_remote_state \
		"${search_preimage_version}" "${search_preimage_inventory}" \
		"${theme_preimage_version}" "${theme_preimage_inventory}" \
		"${preimage_prefix}"
	capture_unchanged_runtime "${active_evidence_dir}/runtime-invariants-before.csv"
	remote_artifact_gate absent \
		| tee "${active_evidence_dir}/remote-artifact-gate-before.txt"
	stream_code_backup "${code_backup}"
	capture_log_baseline "${active_evidence_dir}/production-logs-before.tsv"

	# From this point the remote state is uncertain until the SSH call proves
	# activation. The failure handler must therefore treat maintenance as active.
	maintenance_active=1
	activate_maintenance | tee "${active_evidence_dir}/maintenance-active.txt"
	purge_caches_under_maintenance \
		| tee "${active_evidence_dir}/caches-under-maintenance-before.txt"
	maintenance_http_gate \
		| tee "${active_evidence_dir}/maintenance-http-before.txt"
	capture_fingerprint commerce "${active_evidence_dir}/commerce-before.tsv"
	capture_fingerprint search "${active_evidence_dir}/search-before.tsv"

	mutation_started=1
	install_component \
		plugin akibara-search "${search_candidate_version}" \
		"${search_candidate_archive_sha}" "${search_candidate_archive}"
	verify_maintenance \
		| tee "${active_evidence_dir}/maintenance-after-search.txt"
	cleanup_empty_upgrade_dirs \
		| tee "${active_evidence_dir}/upgrade-cleanup-after-search.txt"
	remote_artifact_gate active \
		| tee "${active_evidence_dir}/remote-artifact-gate-after-search.txt"

	install_component \
		theme akibara-gp "${theme_candidate_version}" \
		"${theme_candidate_archive_sha}" "${theme_candidate_archive}"
	verify_maintenance \
		| tee "${active_evidence_dir}/maintenance-after-theme.txt"
	cleanup_empty_upgrade_dirs \
		| tee "${active_evidence_dir}/upgrade-cleanup-after-theme.txt"
	verify_remote_state \
		"${search_candidate_version}" "${search_candidate_inventory}" \
		"${theme_candidate_version}" "${theme_candidate_inventory}" \
		"${final_prefix}"
	capture_unchanged_runtime "${active_evidence_dir}/runtime-invariants-after.csv"
	cmp -s \
		"${active_evidence_dir}/runtime-invariants-before.csv" \
		"${active_evidence_dir}/runtime-invariants-after.csv" \
		|| die "Un plugin o theme fuera del hotfix cambió durante la promoción"
	capture_fingerprint commerce "${active_evidence_dir}/commerce-after.tsv"
	capture_fingerprint search "${active_evidence_dir}/search-after.tsv"
	cmp -s \
		"${active_evidence_dir}/commerce-before.tsv" \
		"${active_evidence_dir}/commerce-after.tsv" \
		|| die "El estado comercial cambió durante el hotfix"
	cmp -s \
		"${active_evidence_dir}/search-before.tsv" \
		"${active_evidence_dir}/search-after.tsv" \
		|| die "El índice u opciones Search cambiaron durante el hotfix query-time"
	remote_artifact_gate active \
		| tee "${active_evidence_dir}/remote-artifact-gate-under-maintenance.txt"
	purge_caches_under_maintenance \
		| tee "${active_evidence_dir}/caches-under-maintenance-after.txt"
	maintenance_http_gate \
		| tee "${active_evidence_dir}/maintenance-http-after.txt"
	deactivate_maintenance \
		| tee "${active_evidence_dir}/maintenance-inactive.txt"
	maintenance_active=0
	purge_caches_live | tee "${active_evidence_dir}/caches-live-after.txt"
	verify_http_semantics "${active_evidence_dir}"
	capture_log_delta \
		"${active_evidence_dir}/production-logs-before.tsv" \
		"${active_evidence_dir}/production-log-deltas"
	remote_artifact_gate absent \
		| tee "${active_evidence_dir}/remote-artifact-gate-final.txt"
	shasum -a 256 "${active_evidence_dir}/remote-artifact-gate-final.txt" \
		> "${active_evidence_dir}/remote-artifact-gate-final.txt.sha256"

	mutation_started=0
	trap - EXIT HUP INT TERM
	write_receipt production "${AKB_HOST}:${AKB_WEBROOT}"
	note "PASS: producción Search ${search_candidate_version} + theme ${theme_candidate_version}; evidencia local: ${active_backup_dir}"
}

load_contract

if [[ "${AKB_SEARCH_TOOLBAR_PROMOTION_LIBRARY_ONLY:-0}" == 1 ]]; then
	return 0 2>/dev/null || exit 0
fi

command_name="${1:-}"
[[ -n "${command_name}" ]] || {
	usage
	exit 2
}
if [[ "${command_name}" == -h || "${command_name}" == --help ]]; then
	usage
	exit 0
fi
shift || true

config_file=""
apply=0
confirm=""
while (($#)); do
	case "$1" in
		--config)
			(($# >= 2)) || die "Falta valor para --config"
			config_file="$2"
			shift 2
			;;
		--apply)
			apply=1
			shift
			;;
		--confirm)
			(($# >= 2)) || die "Falta valor para --confirm"
			confirm="$2"
			shift 2
			;;
		*)
			die "Opción desconocida: $1"
			;;
	esac
done

case "${command_name}" in
	verify-artifacts)
		[[ -z "${config_file}${confirm}" && "${apply}" == 0 ]] \
			|| die "verify-artifacts no acepta opciones"
		verify_artifacts
		;;
	local-gate)
		[[ -z "${config_file}${confirm}" && "${apply}" == 0 ]] \
			|| die "local-gate no acepta opciones"
		run_local_gate
		;;
	plan)
		[[ -n "${config_file}" && "${apply}" == 0 && -z "${confirm}" ]] \
			|| die "plan requiere solo --config FILE"
		load_production_environment "${config_file}"
		render_plan
		;;
	promote)
		[[ -n "${config_file}" && "${apply}" == 1 ]] \
			|| die "promote requiere --config FILE y --apply"
		[[ "${confirm}" == "${release_id}" ]] \
			|| die "Confirmación exacta requerida: ${release_id}"
		load_production_environment "${config_file}"
		promote_production
		;;
	*)
		die "Comando desconocido: ${command_name}"
		;;
esac
