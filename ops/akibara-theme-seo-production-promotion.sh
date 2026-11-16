#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
contract_file="${script_dir}/contracts/akibara-gp-2.1.50-seo-overlay.json"
config_library="${script_dir}/lib/akibara-promotion-config.sh"
packager="${repo_root}/tools/akibara-releases/package-theme-seo-production.sh"
verifier="${repo_root}/tools/akibara-releases/verify-theme-seo-production-overlay.sh"
release_test="${repo_root}/tools/akibara-releases/test-theme-seo-production-release.sh"
docker_quality_gate="${repo_root}/tools/akibara-releases/qa-theme-seo-production.sh"
lane_test="${script_dir}/test-akibara-theme-seo-production-promotion.sh"
runbook="${script_dir}/akibara-theme-seo-2.1.50-release-runbook.md"
gate_root="${repo_root}/.local-backups/theme-seo-production-promotion/gates"

release_id=""
theme_slug="akibara-gp"
candidate_version=""
candidate_files=""
candidate_archive_sha=""
candidate_inventory_sha=""
preimage_version=""
preimage_files=""
preimage_archive_sha=""
preimage_inventory_sha=""
archive_path=""
inventory_path=""
sidecar_path=""
preimage_archive_path=""
preimage_inventory_path=""
preimage_sidecar_path=""

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
theme_backup=""
mutation_started=0
maintenance_active=0

usage() {
	cat <<'EOF'
Uso:
  ops/akibara-theme-seo-production-promotion.sh verify-artifact
  ops/akibara-theme-seo-production-promotion.sh local-gate
  ops/akibara-theme-seo-production-promotion.sh plan --config FILE
  ops/akibara-theme-seo-production-promotion.sh promote --config FILE \
    --apply --confirm akibara-gp-seo-production-2.1.50

Seguridad:
  - El único destino escribible es el theme productivo akibara-gp.
  - La preimagen remota debe ser exactamente 2.1.49, 102/102 hashes.
  - El candidato debe ser exactamente 2.1.50, 103/103 hashes.
  - El respaldo y el rollback existen solo en local; nunca en el servidor.
  - El ZIP remoto usa mktemp y trap, y debe desaparecer antes de cerrar.
  - Mantenimiento, Rank Math activo, cachés y cero artefactos son gates.
  - No se escriben plugins, base de datos, robots, Rank Math UI ni Graphify.
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

verify_regular_artifact() {
	local path="$1"
	[[ -f "${path}" && ! -L "${path}" && -s "${path}" ]] \
		|| die "Artefacto ausente, vacío o inseguro: ${path}"
}

load_contract() {
	require_command jq
	[[ -f "${contract_file}" && ! -L "${contract_file}" ]] \
		|| die "Falta el contrato regular del theme SEO"

	jq -e '
	  .schema_version == 2
	  and .contract == "akibara-gp-production-seo-overlay"
	  and .release.release_set == "akibara-gp-seo-production-2.1.50"
	  and .release.environment_flow == ["docker-local", "production"]
	  and .release.filtered_urls_owner == "akibara-business"
	  and .production_preimage.version == "2.1.49"
	  and .production_preimage.archive == "dist/akibara-gp-2.1.49.zip"
	  and .production_preimage.inventory == "dist/akibara-gp-2.1.49.files.sha256"
	  and .production_preimage.files == 102
	  and (.production_preimage.archive_sha256 | test("^[a-f0-9]{64}$"))
	  and (.production_preimage.inventory_sha256 | test("^[a-f0-9]{64}$"))
	  and .production_candidate.version == "2.1.50"
	  and .production_candidate.archive == "dist/akibara-gp-2.1.50.zip"
	  and .production_candidate.inventory == "dist/akibara-gp-2.1.50.files.sha256"
	  and .production_candidate.files == 103
	  and .production_candidate.preserved_files == 97
	  and .production_candidate.modified_files == 4
	  and .production_candidate.added_files == 2
	  and .production_candidate.removed_files == 1
	  and (.production_candidate.archive_sha256 | test("^[a-f0-9]{64}$"))
	  and (.production_candidate.inventory_sha256 | test("^[a-f0-9]{64}$"))
	  and .removed_files == [{
	    "path": "inc/seo-filtered-urls.php",
	    "mode": "ownership_transfer",
	    "preimage_sha256": "3e7e50bf3b357304a2314d1bc9ff8cdfbf0ce6fe14428b86db221f591b2ddc2a",
	    "owner": "akibara-business"
	  }]
	' "${contract_file}" >/dev/null \
		|| die "El contrato no coincide con el release theme-only revisado"

	release_id="$(jq -r '.release.release_set' "${contract_file}")"
	candidate_version="$(jq -r '.production_candidate.version' "${contract_file}")"
	candidate_files="$(jq -r '.production_candidate.files' "${contract_file}")"
	candidate_archive_sha="$(jq -r '.production_candidate.archive_sha256' "${contract_file}")"
	candidate_inventory_sha="$(jq -r '.production_candidate.inventory_sha256' "${contract_file}")"
	preimage_version="$(jq -r '.production_preimage.version' "${contract_file}")"
	preimage_files="$(jq -r '.production_preimage.files' "${contract_file}")"
	preimage_archive_sha="$(jq -r '.production_preimage.archive_sha256' "${contract_file}")"
	preimage_inventory_sha="$(jq -r '.production_preimage.inventory_sha256' "${contract_file}")"

	archive_path="${repo_root}/$(jq -r '.production_candidate.archive' "${contract_file}")"
	inventory_path="${repo_root}/$(jq -r '.production_candidate.inventory' "${contract_file}")"
	sidecar_path="${archive_path}.sha256"
	preimage_archive_path="${repo_root}/$(jq -r '.production_preimage.archive' "${contract_file}")"
	preimage_inventory_path="${repo_root}/$(jq -r '.production_preimage.inventory' "${contract_file}")"
	preimage_sidecar_path="${preimage_archive_path}.sha256"
}

verify_archive_inventory() {
	local archive="$1" inventory="$2" sidecar="$3" expected_version="$4"
	local expected_files="$5" expected_archive_sha="$6" expected_inventory_sha="$7"
	local legacy_state="$8" verify_root entry count=0 forbidden version

	verify_regular_artifact "${archive}"
	verify_regular_artifact "${inventory}"
	verify_regular_artifact "${sidecar}"
	[[ "$(sha256_file "${archive}")" == "${expected_archive_sha}" ]] \
		|| die "SHA-256 inesperado para ${archive}"
	[[ "$(sha256_file "${inventory}")" == "${expected_inventory_sha}" ]] \
		|| die "SHA-256 inesperado para ${inventory}"
	(
		cd -- "$(dirname -- "${archive}")"
		shasum -a 256 -c "$(basename -- "${sidecar}")" >/dev/null
	) || die "El sidecar rechazó ${archive}"

	while IFS= read -r entry; do
		[[ -n "${entry}" ]] || continue
		[[ "${entry}" != /* && "${entry}" != '..'* && "${entry}" != *'/../'*
			&& "${entry}" != *\\* && "${entry}" == "${theme_slug}/"* ]] \
			|| die "Entrada ZIP insegura o fuera del theme: ${entry}"
		case "${entry}" in
			*/) ;;
			*) count=$((count + 1)) ;;
		esac
	done < <(zipinfo -1 "${archive}")
	[[ "${count}" == "${expected_files}" ]] \
		|| die "ZIP ${expected_version}: ${count} archivos; se esperaban ${expected_files}"

	forbidden="$(
		zipinfo -1 "${archive}" | grep -E \
			'(^|/)(tests|node_modules|migrations|graphify-out|coverage|backups|\.git|\.github|\.idea|\.vscode)(/|$)|(^|/)\._[^/]+$|(\.bak|\.bk|\.orig|\.rej|\.tmp|\.temp|\.dump|\.backup|\.sql|\.sqlite|\.tar|\.tgz|\.zip|~|\.DS_Store|\.phpunit\.result\.cache)$' \
			|| true
	)"
	[[ -z "${forbidden}" ]] || die "El ZIP contiene rutas prohibidas: ${forbidden}"

	verify_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-theme-seo-promotion-verify.XXXXXX")"
	trap 'case "${verify_root:-}" in */akibara-theme-seo-promotion-verify.*) rm -rf -- "${verify_root}" ;; esac' RETURN
	unzip -tq "${archive}" >/dev/null
	unzip -q "${archive}" -d "${verify_root}"
	[[ -z "$(find "${verify_root}" ! -type d ! -type f -print -quit)" ]] \
		|| die "El ZIP extrajo enlaces o entradas especiales"
	(
		cd "${verify_root}"
		shasum -a 256 -c "${inventory}" >/dev/null
	) || die "El inventario exacto rechazó ${archive}"

	version="$(
		sed -nE 's/^[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' \
			"${verify_root}/${theme_slug}/style.css" | head -n 1
	)"
	[[ "${version}" == "${expected_version}" ]] \
		|| die "El theme declara ${version:-sin versión}; se esperaba ${expected_version}"
	grep -Eq '^[[:space:]]*Template:[[:space:]]+generatepress[[:space:]]*$' \
		"${verify_root}/${theme_slug}/style.css" \
		|| die "El candidato perdió el parent GeneratePress"

	case "${legacy_state}" in
		present)
			[[ -f "${verify_root}/${theme_slug}/inc/seo-filtered-urls.php" ]] \
				|| die "La preimagen 2.1.49 no contiene el módulo legacy esperado"
			grep -Fq "require_once \$akb_inc . 'seo-filtered-urls.php';" \
				"${verify_root}/${theme_slug}/functions.php" \
				|| die "La preimagen 2.1.49 no contiene el require legacy"
			;;
		absent)
			[[ ! -e "${verify_root}/${theme_slug}/inc/seo-filtered-urls.php" ]] \
				|| die "El candidato reintrodujo el módulo legacy"
			if grep -Fq "seo-filtered-urls.php" \
				"${verify_root}/${theme_slug}/functions.php"; then
				die "El candidato reintrodujo el require legacy"
			fi
			grep -Fq "require_once \$akb_inc . 'rank-math.php';" \
				"${verify_root}/${theme_slug}/functions.php" \
				|| die "El candidato no carga la integración Rank Math"
			[[ -f "${verify_root}/${theme_slug}/inc/rank-math.php"
				&& -f "${verify_root}/${theme_slug}/assets/js/rank-math-content-analysis.js" ]] \
				|| die "Faltan archivos de integración Rank Math"
			bash "${verifier}" --source-dir "${verify_root}/${theme_slug}" >/dev/null
			;;
		*) die "Estado legacy inválido: ${legacy_state}" ;;
	esac

	trap - RETURN
	rm -rf -- "${verify_root}"
}

verify_artifact() {
	local command_name

	for command_name in awk find grep jq sed shasum unzip zipinfo; do
		require_command "${command_name}"
	done
	verify_archive_inventory \
		"${preimage_archive_path}" "${preimage_inventory_path}" "${preimage_sidecar_path}" \
		"${preimage_version}" "${preimage_files}" \
		"${preimage_archive_sha}" "${preimage_inventory_sha}" present
	verify_archive_inventory \
		"${archive_path}" "${inventory_path}" "${sidecar_path}" \
		"${candidate_version}" "${candidate_files}" \
		"${candidate_archive_sha}" "${candidate_inventory_sha}" absent
	note "PASS: theme ${preimage_version} 102/102 → ${candidate_version} 103/103; legacy ausente"
}

compute_suite_sha256() {
	local relative absolute
	local -a files=(
		"ops/akibara-theme-seo-production-promotion.sh"
		"ops/test-akibara-theme-seo-production-promotion.sh"
		"ops/akibara-theme-seo-2.1.50-release-runbook.md"
		"ops/contracts/akibara-gp-2.1.50-seo-overlay.json"
		"ops/lib/akibara-promotion-config.sh"
		"tools/akibara-releases/package-theme-seo-production.sh"
		"tools/akibara-releases/verify-theme-seo-production-overlay.sh"
		"tools/akibara-releases/test-theme-seo-production-release.sh"
		"tools/akibara-releases/qa-theme-seo-production.sh"
		"public_html/wp-content/themes/akibara-gp/tests/rankmath-featured-image.test.js"
		"public_html/wp-content/themes/akibara-gp/tests/rankmath-toc.test.js"
	)

	{
		printf '@contract\takibara-theme-seo-production-promotion-suite-v1\n'
		for relative in "${files[@]}"; do
			absolute="${repo_root}/${relative}"
			[[ -f "${absolute}" && ! -L "${absolute}" ]] \
				|| die "Falta archivo regular del gate: ${relative}"
			printf 'file\t%s\t%s\n' "${relative}" "$(sha256_file "${absolute}")"
		done
	} | LC_ALL=C sort | shasum -a 256 | awk '{print $1}'
}

receipt_path() {
	local stage="$1"
	printf '%s/%s-passed-%s.json' "${gate_root}" "${stage}" "${candidate_archive_sha}"
}

write_receipt() {
	local stage="$1" target="$2" receipt tmp
	local suite_sha pipeline_sha contract_sha

	case "${stage}" in
		docker|production) ;;
		*) die "Etapa de recibo no soportada: ${stage}" ;;
	esac
	suite_sha="$(compute_suite_sha256)"
	pipeline_sha="$(sha256_file "${BASH_SOURCE[0]}")"
	contract_sha="$(sha256_file "${contract_file}")"
	mkdir -p "${gate_root}"
	receipt="$(receipt_path "${stage}")"
	tmp="${receipt}.partial"
	jq -n \
		--arg stage "${stage}" \
		--arg release_id "${release_id}" \
		--arg archive_sha256 "${candidate_archive_sha}" \
		--arg inventory_sha256 "${candidate_inventory_sha}" \
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
		  archive_sha256: $archive_sha256,
		  inventory_sha256: $inventory_sha256,
		  pipeline_sha256: $pipeline_sha256,
		  suite_sha256: $suite_sha256,
		  contract_sha256: $contract_sha256,
		  target: $target,
		  completed_at_utc: $completed_at_utc
		}' > "${tmp}"
	mv -- "${tmp}" "${receipt}"
	(
		cd -- "$(dirname -- "${receipt}")"
		shasum -a 256 "$(basename -- "${receipt}")" \
			> "$(basename -- "${receipt}").sha256"
	)
	note "Recibo: ${receipt}"
}

verify_receipt() {
	local stage="$1" expected_target="$2" receipt sidecar
	local completed_epoch now_epoch

	receipt="$(receipt_path "${stage}")"
	sidecar="${receipt}.sha256"
	verify_regular_artifact "${receipt}"
	verify_regular_artifact "${sidecar}"
	(
		cd -- "$(dirname -- "${receipt}")"
		shasum -a 256 -c "$(basename -- "${sidecar}")" >/dev/null
	) || die "Checksum inválido para recibo ${stage}"
	jq -e \
		--arg stage "${stage}" \
		--arg release_id "${release_id}" \
		--arg archive_sha256 "${candidate_archive_sha}" \
		--arg inventory_sha256 "${candidate_inventory_sha}" \
		--arg pipeline_sha256 "$(sha256_file "${BASH_SOURCE[0]}")" \
		--arg suite_sha256 "$(compute_suite_sha256)" \
		--arg contract_sha256 "$(sha256_file "${contract_file}")" \
		--arg target "${expected_target}" \
		'
		  type == "object"
		  and ((keys | sort) == [
		    "archive_sha256", "completed_at_utc", "contract_sha256",
		    "inventory_sha256", "pipeline_sha256", "release_id",
		    "schema_version", "stage", "status", "suite_sha256", "target"
		  ])
		  and .schema_version == 1
		  and .stage == $stage
		  and .status == "PASS"
		  and .release_id == $release_id
		  and .archive_sha256 == $archive_sha256
		  and .inventory_sha256 == $inventory_sha256
		  and .pipeline_sha256 == $pipeline_sha256
		  and .suite_sha256 == $suite_sha256
		  and .contract_sha256 == $contract_sha256
		  and .target == $target
		  and (.completed_at_utc
		    | type == "string"
		    and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
		' "${receipt}" >/dev/null || die "Contrato de recibo rechazado: ${stage}"
	completed_epoch="$(jq -er '.completed_at_utc | fromdateiso8601' "${receipt}")" \
		|| die "Timestamp inválido en recibo ${stage}"
	now_epoch="$(date -u +%s)"
	((completed_epoch <= now_epoch + 300)) || die "Recibo ${stage} fechado en el futuro"
	((now_epoch - completed_epoch <= 86400)) \
		|| die "Recibo ${stage} vencido; repite el gate local"
}

run_local_gate() {
	verify_artifact
	bash "${packager}" --verify-only
	bash "${release_test}"
	bash "${docker_quality_gate}"
	bash "${lane_test}"
	write_receipt docker local-compose
	note "PASS: gate Docker/local theme SEO ${candidate_version}"
}

load_production_environment() {
	local config="$1"

	[[ -f "${config_library}" && ! -L "${config_library}" ]] \
		|| die "Falta la librería regular de configuración"
	# shellcheck source=ops/lib/akibara-promotion-config.sh
	source "${config_library}"
	akb_load_promotion_config "${config}" || die "Configuración de promoción rechazada"
	akb_select_promotion_environment production \
		|| die "No se pudo seleccionar producción"
	[[ "${AKB_BASE_URL}" == "https://akibara.cl"
		&& "${AKB_WEBROOT}" =~ /public_html$ ]] \
		|| die "El carril solo admite el destino productivo exacto"
}

ssh_read() {
	local script="$1" command

	printf -v command 'AKB_WEBROOT=%q bash -s' "${AKB_WEBROOT}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" <<< "${script}"
}

remote_preflight() {
	local script

	script='
set -Eeuo pipefail
command -v awk >/dev/null
command -v cmp >/dev/null
command -v find >/dev/null
command -v mktemp >/dev/null
command -v sha256sum >/dev/null
command -v tar >/dev/null
command -v wp >/dev/null
[[ "${AKB_WEBROOT}" =~ /public_html$ ]]
themes_root="${AKB_WEBROOT}/wp-content/themes"
target="${themes_root}/akibara-gp"
[[ -d "${themes_root}" && ! -L "${themes_root}" ]]
[[ -d "${target}" && ! -L "${target}" ]]
test -z "$(find "${target}" ! -type d ! -type f -print -quit)"
wp --path="${AKB_WEBROOT}" core is-installed
wp --path="${AKB_WEBROOT}" theme is-active akibara-gp
wp --path="${AKB_WEBROOT}" plugin is-active seo-by-rank-math
echo "THEME_SEO_PRODUCTION_PREFLIGHT_PASS"'
	ssh_read "${script}"
}

remote_inventory() {
	local output="$1" script

	script='
set -Eeuo pipefail
themes_root="${AKB_WEBROOT}/wp-content/themes"
slug="akibara-gp"
[[ -d "${themes_root}/${slug}" && ! -L "${themes_root}/${slug}" ]]
test -z "$(find "${themes_root}/${slug}" ! -type d ! -type f -print -quit)"
cd "${themes_root}"
find "${slug}" -type f -print | LC_ALL=C sort |
while IFS= read -r file; do sha256sum "${file}"; done'
	ssh_read "${script}" > "${output}.partial"
	[[ -s "${output}.partial" ]] || die "El inventario remoto quedó vacío"
	mv -- "${output}.partial" "${output}"
}

verify_remote_state() {
	local version="$1" expected_inventory="$2" evidence_path="$3"
	local legacy_state="$4" script

	case "${legacy_state}" in
		present|absent) ;;
		*) die "Estado legacy remoto inválido: ${legacy_state}" ;;
	esac
	printf -v script '
set -Eeuo pipefail
root="${AKB_WEBROOT}/wp-content/themes/akibara-gp"
wp --path="${AKB_WEBROOT}" core is-installed
[[ "$(wp --path="${AKB_WEBROOT}" theme get akibara-gp --field=version)" == %q ]]
wp --path="${AKB_WEBROOT}" theme is-active akibara-gp
wp --path="${AKB_WEBROOT}" plugin is-active seo-by-rank-math
grep -Eq "^[[:space:]]*Template:[[:space:]]+generatepress[[:space:]]*$" "${root}/style.css"
if [[ %q == present ]]; then
  [[ -f "${root}/inc/seo-filtered-urls.php" && ! -L "${root}/inc/seo-filtered-urls.php" ]]
  grep -Fq "seo-filtered-urls.php" "${root}/functions.php"
else
  [[ ! -e "${root}/inc/seo-filtered-urls.php" && ! -L "${root}/inc/seo-filtered-urls.php" ]]
  if grep -Fq "seo-filtered-urls.php" "${root}/functions.php"; then exit 1; fi
  grep -Fq "rank-math.php" "${root}/functions.php"
fi
' "${version}" "${legacy_state}"
	ssh_read "${script}"
	remote_inventory "${evidence_path}"
	cmp -s "${expected_inventory}" "${evidence_path}" \
		|| die "El theme remoto ${version} no coincide 100% con su inventario"
}

capture_plugin_state() {
	local output="$1" script

	script='
set -Eeuo pipefail
wp --path="${AKB_WEBROOT}" plugin list \
  --fields=name,status,version --format=csv | LC_ALL=C sort'
	ssh_read "${script}" > "${output}.partial"
	[[ -s "${output}.partial" ]] || die "El inventario de plugins quedó vacío"
	mv -- "${output}.partial" "${output}"
	shasum -a 256 "${output}" > "${output}.sha256"
}

remote_artifact_gate() {
	local maintenance_state="$1" script

	case "${maintenance_state}" in
		absent|active) ;;
		*) die "Estado de mantenimiento inválido: ${maintenance_state}" ;;
	esac
	printf -v script '
set -Eeuo pipefail
theme_root="${AKB_WEBROOT}/wp-content/themes/akibara-gp"
themes_root="${AKB_WEBROOT}/wp-content/themes"
[[ -d "${theme_root}" && ! -L "${theme_root}" ]]
test -z "$(find "${theme_root}" ! -type d ! -type f -print -quit)"
test -z "$(find "${theme_root}" -type f \( \
  -name "*.bak" -o -name "*.bk" -o -name "*.orig" -o -name "*.rej" -o \
  -name "*.tmp" -o -name "*.temp" -o -name "*.dump" -o -name "*.backup" -o \
  -name "*.sql" -o -name "*.sqlite" -o -name "*.tar" -o -name "*.tgz" -o \
  -name "*.zip" -o -name "*~" -o -name ".DS_Store" -o -name "._*" \
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
test -z "$(find /tmp -maxdepth 1 -name "akibara-theme-seo.*" -print -quit 2>/dev/null)"
test -z "$(find "${AKB_WEBROOT}" -maxdepth 1 -name ".maintenance.akb.*" -print -quit)"
wp --path="${AKB_WEBROOT}" plugin is-active seo-by-rank-math
if [[ %q == active ]]; then
  [[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
  printf "%%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${AKB_WEBROOT}/.maintenance"
else
  [[ ! -e "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
fi
echo "THEME_SEO_ZERO_REMOTE_ARTIFACTS_PASS"
' "${maintenance_state}"
	ssh_read "${script}"
}

cleanup_empty_upgrade_dirs() {
	local script

	script='
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
	ssh_read "${script}"
}

stream_theme_backup() {
	local output="$1" script

	script='
set -Eeuo pipefail
root="${AKB_WEBROOT}/wp-content/themes"
[[ -d "${root}/akibara-gp" && ! -L "${root}/akibara-gp" ]]
test -z "$(find "${root}/akibara-gp" ! -type d ! -type f -print -quit)"
tar -C "${root}" -czf - akibara-gp'
	ssh_read "${script}" > "${output}.partial"
	tar -tzf "${output}.partial" >/dev/null
	mv -- "${output}.partial" "${output}"
	shasum -a 256 "${output}" > "${output}.sha256"
}

activate_maintenance() {
	local script

	script='
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
	ssh_read "${script}"
}

ensure_maintenance() {
	local script

	script='
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
	ssh_read "${script}"
}

verify_maintenance() {
	local script

	script='
set -Eeuo pipefail
[[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${AKB_WEBROOT}/.maintenance"
echo "MAINTENANCE_MODE_VERIFIED"'
	ssh_read "${script}"
}

deactivate_maintenance() {
	local script

	script='
set -Eeuo pipefail
maintenance_file="${AKB_WEBROOT}/.maintenance"
[[ -f "${maintenance_file}" && ! -L "${maintenance_file}" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${maintenance_file}"
rm -f -- "${maintenance_file}"
[[ ! -e "${maintenance_file}" && ! -L "${maintenance_file}" ]]
echo "MAINTENANCE_MODE_INACTIVE"'
	ssh_read "${script}"
}

purge_caches_under_maintenance() {
	local script

	script='
set -Eeuo pipefail
[[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
wp --path="${AKB_WEBROOT}" cache flush >/dev/null
if wp --path="${AKB_WEBROOT}" plugin is-active litespeed-cache; then
  wp --path="${AKB_WEBROOT}" eval "if (! class_exists(\"LiteSpeed\\\\Purge\")) { throw new RuntimeException(\"LiteSpeed Purge unavailable\"); } \\LiteSpeed\\Purge::purge_all(\"Theme SEO promotion\");" >/dev/null
fi
[[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
echo "RUNTIME_CACHES_PURGED_UNDER_MAINTENANCE"'
	ssh_read "${script}"
}

purge_caches_live() {
	local script

	script='
set -Eeuo pipefail
[[ ! -e "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
wp --path="${AKB_WEBROOT}" cache flush >/dev/null
if wp --path="${AKB_WEBROOT}" plugin is-active litespeed-cache; then
  wp --path="${AKB_WEBROOT}" litespeed-purge all >/dev/null
fi
echo "RUNTIME_CACHES_PURGED_LIVE"'
	ssh_read "${script}"
}

maintenance_http_gate() {
	local probe http_code headers

	probe="/?akb-theme-seo-maintenance=$(date -u +%s)-$$"
	headers="$(mktemp "${TMPDIR:-/tmp}/akibara-theme-seo-maintenance.XXXXXX")"
	trap 'case "${headers:-}" in */akibara-theme-seo-maintenance.*) rm -f -- "${headers}" ;; esac' RETURN
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

verify_live_http() {
	local path http_code

	for path in / /tienda/; do
		http_code="$(
			curl --silent --show-error --location \
				--proto '=https' --proto-redir '=https' \
				--connect-timeout 15 --max-time 45 \
				--retry 2 --retry-delay 2 --retry-all-errors \
				--header 'Cache-Control: no-cache' \
				--output /dev/null --write-out '%{http_code}' \
				"${AKB_BASE_URL}${path}"
		)"
		[[ "${http_code}" == 200 ]] \
			|| die "HTTP esperaba 200 para ${path}; recibió ${http_code}"
	done
	note "LIVE_HTTP_200_PASS"
}

install_theme() {
	local command script

	script='
set -Eeuo pipefail
remote_zip="$(mktemp --suffix=.zip /tmp/akibara-theme-seo.XXXXXX)"
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
	printf -v command 'AKB_WEBROOT=%q AKB_VERSION=%q AKB_SHA=%q bash -c %q' \
		"${AKB_WEBROOT}" "${candidate_version}" "${candidate_archive_sha}" "${script}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" < "${archive_path}"
}

restore_theme() {
	local backup="$1" command script

	verify_regular_artifact "${backup}"
	verify_regular_artifact "${backup}.sha256"
	shasum -a 256 -c "${backup}.sha256" >/dev/null \
		|| die "Checksum del respaldo local inválido"
	tar -tzf "${backup}" >/dev/null
	script='
set -Eeuo pipefail
themes_root="${AKB_WEBROOT}/wp-content/themes"
target="${themes_root}/akibara-gp"
[[ -d "${themes_root}" && ! -L "${themes_root}" ]]
rm -rf -- "${target}"
tar -C "${themes_root}" -xzf -
[[ -d "${target}" && ! -L "${target}" ]]
test -z "$(find "${target}" ! -type d ! -type f -print -quit)"
[[ "$(wp --path="${AKB_WEBROOT}" theme get akibara-gp --field=version)" == "${AKB_PREIMAGE_VERSION}" ]]
wp --path="${AKB_WEBROOT}" theme is-active akibara-gp
wp --path="${AKB_WEBROOT}" plugin is-active seo-by-rank-math'
	printf -v command 'AKB_WEBROOT=%q AKB_PREIMAGE_VERSION=%q bash -c %q' \
		"${AKB_WEBROOT}" "${preimage_version}" "${script}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" < "${backup}"
}

cleanup_on_failure() {
	local exit_status="$1" rollback_safe=1
	local rollback_inventory="${active_evidence_dir}/remote-after-rollback.files.sha256"

	((exit_status != 0)) || return 0
	set +e
	if ((mutation_started)); then
		note "Fallo tras mutar el theme: restaurando 2.1.49 desde el respaldo local."
		if ! ensure_maintenance; then
			rollback_safe=0
		else
			maintenance_active=1
		fi
		if ((rollback_safe)) && ! restore_theme "${theme_backup}"; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && ! purge_caches_under_maintenance; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && ! verify_remote_state \
			"${preimage_version}" "${preimage_inventory_path}" \
			"${rollback_inventory}" present; then
			rollback_safe=0
		fi
	fi
	if ((maintenance_active)); then
		if ((rollback_safe)); then
			cleanup_empty_upgrade_dirs || rollback_safe=0
			deactivate_maintenance || rollback_safe=0
			maintenance_active=0
			purge_caches_live || rollback_safe=0
			remote_artifact_gate absent || rollback_safe=0
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
	verify_artifact
	cat <<EOF
DRY RUN — no se ejecutó ssh, curl ni escritura remota.

Release: ${release_id}
SHA ZIP: ${candidate_archive_sha}
Destino: production-only
Host:    ${AKB_HOST}
Webroot: ${AKB_WEBROOT}
URL:     ${AKB_BASE_URL}

Secuencia fail-closed:
  1. Verificar 2.1.49 102/102 y 2.1.50 103/103 sin reescribir artefactos.
  2. Exigir recibo Docker/local ligado a contrato, pipeline, suite y ZIP.
  3. Exigir preimagen remota productiva 2.1.49, inventario exacto y Rank Math.
  4. Respaldar solo akibara-gp por streaming a almacenamiento local.
  5. Activar mantenimiento y comprobar HTTP 503 sin depender de caché.
  6. Instalar solo el ZIP 2.1.50 mediante mktemp remoto con trap de limpieza.
  7. Exigir 103/103, ausencia del legacy y estado de plugins intacto.
  8. Purgar objeto/LiteSpeed, reabrir, validar HTTP 200 y cero artefactos.

No se escribe Business, Search, Checkout, base de datos, robots, Rank Math UI
ni Graphify. El rollback 2.1.49 se transmite desde el respaldo local.
EOF
}

promote_production() {
	local stamp preimage_inventory plugins_before plugins_after

	verify_artifact
	verify_receipt docker local-compose
	for command_name in cmp curl find grep jq sed shasum ssh tar; do
		require_command "${command_name}"
	done

	stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"
	active_backup_dir="${repo_root}/.local-backups/theme-seo-production-promotion/production/${stamp}-${candidate_archive_sha}"
	active_evidence_dir="${active_backup_dir}/evidence"
	mkdir -p "${active_backup_dir}/code" "${active_evidence_dir}"
	chmod 0700 "${active_backup_dir}"
	theme_backup="${active_backup_dir}/code/akibara-gp-2.1.49-before.tar.gz"
	preimage_inventory="${active_evidence_dir}/remote-preimage.files.sha256"
	plugins_before="${active_evidence_dir}/plugins-before.csv"
	plugins_after="${active_evidence_dir}/plugins-after.csv"

	trap promotion_exit_handler EXIT
	trap 'promotion_signal_handler 129' HUP
	trap 'promotion_signal_handler 130' INT
	trap 'promotion_signal_handler 143' TERM

	remote_preflight | tee "${active_evidence_dir}/remote-preflight.txt"
	verify_remote_state \
		"${preimage_version}" "${preimage_inventory_path}" \
		"${preimage_inventory}" present
	remote_artifact_gate absent \
		| tee "${active_evidence_dir}/remote-artifact-gate-before.txt"
	capture_plugin_state "${plugins_before}"
	stream_theme_backup "${theme_backup}"
	activate_maintenance | tee "${active_evidence_dir}/maintenance-active.txt"
	maintenance_active=1
	purge_caches_under_maintenance \
		| tee "${active_evidence_dir}/caches-under-maintenance-before.txt"
	maintenance_http_gate | tee "${active_evidence_dir}/maintenance-http-before.txt"

	mutation_started=1
	install_theme
	verify_maintenance | tee "${active_evidence_dir}/maintenance-after-install.txt"
	cleanup_empty_upgrade_dirs \
		| tee "${active_evidence_dir}/upgrade-cleanup-after-install.txt"
	verify_remote_state \
		"${candidate_version}" "${inventory_path}" \
		"${active_evidence_dir}/remote-final.files.sha256" absent
	capture_plugin_state "${plugins_after}"
	cmp -s "${plugins_before}" "${plugins_after}" \
		|| die "El inventario o estado de plugins cambió durante el release theme-only"
	remote_artifact_gate active \
		| tee "${active_evidence_dir}/remote-artifact-gate-under-maintenance.txt"
	purge_caches_under_maintenance \
		| tee "${active_evidence_dir}/caches-under-maintenance-after.txt"
	maintenance_http_gate | tee "${active_evidence_dir}/maintenance-http-after.txt"
	deactivate_maintenance | tee "${active_evidence_dir}/maintenance-inactive.txt"
	maintenance_active=0
	purge_caches_live | tee "${active_evidence_dir}/caches-live-after.txt"
	verify_live_http | tee "${active_evidence_dir}/live-http-after.txt"
	remote_artifact_gate absent \
		| tee "${active_evidence_dir}/remote-artifact-gate-final.txt"
	shasum -a 256 "${active_evidence_dir}/remote-artifact-gate-final.txt" \
		> "${active_evidence_dir}/remote-artifact-gate-final.txt.sha256"

	mutation_started=0
	trap - EXIT HUP INT TERM
	write_receipt production "${AKB_HOST}:${AKB_WEBROOT}"
	note "PASS: promoción production-only theme ${candidate_version}; evidencia local: ${active_backup_dir}"
}

load_contract

if [[ "${AKB_THEME_SEO_PROMOTION_LIBRARY_ONLY:-0}" == 1 ]]; then
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
	verify-artifact)
		[[ -z "${config_file}${confirm}" && "${apply}" == 0 ]] \
			|| die "verify-artifact no acepta opciones"
		verify_artifact
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
		usage >&2
		exit 2
		;;
esac
