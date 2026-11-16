#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
contract_file="${script_dir}/contracts/akibara-business-1.98.2-blocks-2.1.6.json"
config_library="${script_dir}/lib/akibara-promotion-config.sh"
commerce_fingerprint_php="${script_dir}/akibara-protected-commerce-fingerprint.php"
search_fingerprint_php="${script_dir}/akibara-search-state-fingerprint.php"
packager="${repo_root}/tools/akibara-releases/package-business-blocks-production.sh"
docker_gate="${repo_root}/tools/akibara-releases/docker-business-blocks-production.sh"
promotion_test="${script_dir}/test-akibara-business-blocks-production-promotion.sh"
gate_root="${repo_root}/.local-backups/business-blocks-production/gates"

release_id=""
release_digest=""
business_preimage_version=""
business_candidate_version=""
business_preimage_inventory=""
business_candidate_inventory=""
business_candidate_archive=""
business_candidate_archive_sha=""
blocks_preimage_version=""
blocks_candidate_version=""
blocks_preimage_inventory=""
blocks_candidate_inventory=""
blocks_candidate_archive=""
blocks_candidate_archive_sha=""
search_version=""
search_inventory=""
theme_version=""
theme_inventory=""
bluex_version=""

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
  ops/akibara-business-blocks-production-promotion.sh verify-artifacts
  ops/akibara-business-blocks-production-promotion.sh local-gate
  ops/akibara-business-blocks-production-promotion.sh plan --config FILE
  ops/akibara-business-blocks-production-promotion.sh promote \
    --config FILE --apply \
    --confirm akibara-business-1.98.2_blocks-2.1.6

Seguridad:
  - El flujo soportado es únicamente Docker local -> producción.
  - Solo instala Akibara Business 1.98.2 y Akibara Cart and Checkout Blocks 2.1.6.
  - Exige baseline remoto Business 1.98.1 y Blocks 2.1.5 (build hacia 1.98.2/2.1.6).
  - Search 0.1.8, theme 2.1.58, BlueX 3.2.2 y todo runtime ajeno son invariantes.
  - El rollback y la evidencia viven únicamente en local.
  - No modifica terceros, SEO, contenido, opciones UI ni Graphify.
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
	[[ -f "$1" && ! -L "$1" && -s "$1" ]] \
		|| die "Archivo ausente, vacío o inseguro: $1"
}

repo_path() {
	local relative="$1"
	[[ -n "${relative}" && "${relative}" != /* && "${relative}" != *'..'*
		&& "${relative}" != *'//'*
		&& "${relative}" != *'/./'* ]] \
		|| die "Ruta contractual insegura: ${relative}"
	printf '%s/%s\n' "${repo_root}" "${relative}"
}

load_contract() {
	require_command jq
	verify_regular_file "${contract_file}"
	jq -e '
	  .schema_version == 1
	  and .contract == "akibara-business-blocks-production-release"
	  and .release_id == "akibara-business-1.98.2_blocks-2.1.6"
	  and .seal_state == "sealed"
	  and .promotion_sequence == ["docker-local", "production"]
	  and .components.business.preimage.version == "1.98.1"
  and .components.business.remote_baseline.version == "1.98.1"
	  and .components.business.candidate.version == "1.98.2"
	  and .components.blocks.preimage.version == "2.1.5"
  and .components.blocks.remote_baseline.version == "2.1.5"
	  and .components.blocks.candidate.version == "2.1.6"
	  and ([.components.business.candidate.archive_sha256,
	        .components.business.candidate.inventory_sha256,
	        .components.blocks.candidate.archive_sha256,
	        .components.blocks.candidate.inventory_sha256]
	    | all(test("^[a-f0-9]{64}$")))
	  and .production_invariants.search.version == "0.1.8"
	  and .production_invariants.theme.version == "2.1.58"
	  and .production_invariants.bluex.version == "3.2.2"
	  and .production_invariants.bluex.must_remain_byte_identical == true
	  and .production_invariants.remote_backups_allowed == false
	  and .production_invariants.graphify_during_release_allowed == false
	  and .exclusive_targets == [
	    "public_html/wp-content/plugins/akibara-business",
	    "public_html/wp-content/plugins/akibara-cart-checkout-blocks"
	  ]
	' "${contract_file}" >/dev/null \
		|| die "Contrato no sellado o divergente"

	release_id="$(jq -r '.release_id' "${contract_file}")"
	business_preimage_version="$(jq -r '.components.business.remote_baseline.version // .components.business.preimage.version' "${contract_file}")"
	business_candidate_version="$(jq -r '.components.business.candidate.version' "${contract_file}")"
	business_preimage_inventory="$(repo_path "$(jq -r '.components.business.remote_baseline.inventory // .components.business.preimage.inventory' "${contract_file}")")"
	business_candidate_inventory="$(repo_path "$(jq -r '.components.business.candidate.inventory' "${contract_file}")")"
	business_candidate_archive="$(repo_path "$(jq -r '.components.business.candidate.archive' "${contract_file}")")"
	business_candidate_archive_sha="$(jq -r '.components.business.candidate.archive_sha256' "${contract_file}")"
	blocks_preimage_version="$(jq -r '.components.blocks.remote_baseline.version // .components.blocks.preimage.version' "${contract_file}")"
	blocks_candidate_version="$(jq -r '.components.blocks.candidate.version' "${contract_file}")"
	blocks_preimage_inventory="$(repo_path "$(jq -r '.components.blocks.remote_baseline.inventory // .components.blocks.preimage.inventory' "${contract_file}")")"
	blocks_candidate_inventory="$(repo_path "$(jq -r '.components.blocks.candidate.inventory' "${contract_file}")")"
	blocks_candidate_archive="$(repo_path "$(jq -r '.components.blocks.candidate.archive' "${contract_file}")")"
	blocks_candidate_archive_sha="$(jq -r '.components.blocks.candidate.archive_sha256' "${contract_file}")"
	search_version="$(jq -r '.production_invariants.search.version' "${contract_file}")"
	search_inventory="${repo_root}/dist/akibara-search-${search_version}.files.sha256"
	theme_version="$(jq -r '.production_invariants.theme.version' "${contract_file}")"
	theme_inventory="${repo_root}/dist/akibara-gp-${theme_version}.files.sha256"
	bluex_version="$(jq -r '.production_invariants.bluex.version' "${contract_file}")"
	release_digest="$(
		{
			printf 'release\t%s\n' "${release_id}"
			printf 'business\t%s\n' "${business_candidate_archive_sha}"
			printf 'blocks\t%s\n' "${blocks_candidate_archive_sha}"
			printf 'contract\t%s\n' "$(sha256_file "${contract_file}")"
		} | shasum -a 256 | awk '{print $1}'
	)"
}

verify_invariant_artifacts() {
	local search_inventory_sha theme_inventory_sha
	verify_regular_file "${search_inventory}"
	verify_regular_file "${theme_inventory}"
	search_inventory_sha="$(jq -r '.production_invariants.search.inventory_sha256' "${contract_file}")"
	theme_inventory_sha="$(jq -r '.production_invariants.theme.inventory_sha256' "${contract_file}")"
	[[ "$(sha256_file "${search_inventory}")" == "${search_inventory_sha}" ]] \
		|| die "Inventario Search divergente respecto del invariante del contrato"
	[[ "$(sha256_file "${theme_inventory}")" == "${theme_inventory_sha}" ]] \
		|| die "Inventario theme divergente respecto del invariante del contrato"
}

verify_artifacts() {
	local expected actual
	for command_name in awk cmp find jq shasum unzip zipinfo; do
		require_command "${command_name}"
	done
	bash "${packager}" --verify-only
	for component in business blocks; do
		case "${component}" in
			business)
				archive="${business_candidate_archive}"
				inventory="${business_candidate_inventory}"
				expected="${business_candidate_archive_sha}"
				;;
			blocks)
				archive="${blocks_candidate_archive}"
				inventory="${blocks_candidate_inventory}"
				expected="${blocks_candidate_archive_sha}"
				;;
		esac
		verify_regular_file "${archive}"
		verify_regular_file "${inventory}"
		verify_regular_file "${archive}.sha256"
		actual="$(sha256_file "${archive}")"
		[[ "${actual}" == "${expected}" ]] \
			|| die "SHA candidato divergente: ${component}"
		(
			cd -- "$(dirname -- "${archive}")"
			shasum -a 256 -c "$(basename -- "${archive}.sha256")" >/dev/null
		) || die "Sidecar candidato inválido: ${component}"
	done
	verify_invariant_artifacts
	note "PASS: artefactos exactos Business 1.98.2 + Blocks 2.1.6 e invariantes locales"
}

compute_suite_sha256() {
	local relative absolute file
	local -a files=(
		"ops/akibara-business-blocks-production-promotion.sh"
		"ops/contracts/akibara-business-1.98.2-blocks-2.1.6.json"
		"ops/lib/akibara-promotion-config.sh"
		"ops/akibara-protected-commerce-fingerprint.php"
		"ops/akibara-search-state-fingerprint.php"
		"ops/akibara-business-blocks-pudo-docker-gate.php"
		"ops/test-akibara-business-blocks-production-promotion.sh"
		"ops/test-akibara-business-blocks-promotion-guards.sh"
		"tools/akibara-releases/package-business-blocks-production.sh"
		"tools/akibara-releases/verify-business-blocks-source-delta.py"
		"tools/akibara-releases/test-business-blocks-source-delta.sh"
		"tools/akibara-releases/test-business-blocks-production-release.sh"
		"tools/akibara-releases/docker-business-blocks-production.sh"
		"tools/akibara-releases/phpunit-business-blocks-candidate-bootstrap.php"
		"tools/akibara-releases/docker-checkout.sh"
		"tools/akibara-releases/package-checkout.sh"
		"tools/akibara-releases/package-runtime.sh"
		"tools/akibara-business/composer.json"
		"tools/akibara-business/composer.lock"
		"tools/akibara-business/phpunit.xml.dist"
	)
	{
		printf '@contract\takibara-business-blocks-suite-v1\n'
		for relative in "${files[@]}"; do
			absolute="${repo_root}/${relative}"
			[[ -f "${absolute}" && ! -L "${absolute}" ]] \
				|| die "Falta archivo regular de suite: ${relative}"
			printf 'file\t%s\t%s\n' "${relative}" "$(sha256_file "${absolute}")"
		done
		for test_root in \
			"${repo_root}/public_html/wp-content/plugins/akibara-business/tests" \
			"${repo_root}/public_html/wp-content/plugins/akibara-cart-checkout-blocks/tests"; do
			while IFS= read -r file; do
				relative="${file#"${repo_root}/"}"
				printf 'file\t%s\t%s\n' "${relative}" "$(sha256_file "${file}")"
			done < <(find "${test_root}" -type f -print | LC_ALL=C sort)
		done
	} | LC_ALL=C sort | shasum -a 256 | awk '{print $1}'
}

receipt_path() {
	printf '%s/%s-passed-%s.json' "${gate_root}" "$1" "${release_digest}"
}

write_receipt() {
	local stage="$1" target="$2" receipt partial
	local pipeline_sha contract_sha suite_sha
	case "${stage}" in docker|production) ;; *) die "Etapa de recibo inválida" ;; esac
	pipeline_sha="$(sha256_file "${BASH_SOURCE[0]}")"
	contract_sha="$(sha256_file "${contract_file}")"
	suite_sha="$(compute_suite_sha256)"
	mkdir -p -- "${gate_root}"
	receipt="$(receipt_path "${stage}")"
	partial="${receipt}.partial"
	jq -n \
		--arg stage "${stage}" \
		--arg release_id "${release_id}" \
		--arg release_digest "${release_digest}" \
		--arg business_sha256 "${business_candidate_archive_sha}" \
		--arg blocks_sha256 "${blocks_candidate_archive_sha}" \
		--arg pipeline_sha256 "${pipeline_sha}" \
		--arg contract_sha256 "${contract_sha}" \
		--arg suite_sha256 "${suite_sha}" \
		--arg target "${target}" \
		--arg completed_at_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
		'{
		  schema_version: 1,
		  stage: $stage,
		  status: "PASS",
		  release_id: $release_id,
		  release_digest: $release_digest,
		  business_sha256: $business_sha256,
		  blocks_sha256: $blocks_sha256,
		  pipeline_sha256: $pipeline_sha256,
		  contract_sha256: $contract_sha256,
		  suite_sha256: $suite_sha256,
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
	local completed_epoch now_epoch
	receipt="$(receipt_path "${stage}")"
	sidecar="${receipt}.sha256"
	verify_regular_file "${receipt}"
	verify_regular_file "${sidecar}"
	(
		cd -- "$(dirname -- "${receipt}")"
		shasum -a 256 -c "$(basename -- "${sidecar}")" >/dev/null
	) || die "Checksum de recibo ${stage} inválido"
	jq -e \
		--arg stage "${stage}" \
		--arg release_id "${release_id}" \
		--arg release_digest "${release_digest}" \
		--arg business_sha256 "${business_candidate_archive_sha}" \
		--arg blocks_sha256 "${blocks_candidate_archive_sha}" \
		--arg pipeline_sha256 "$(sha256_file "${BASH_SOURCE[0]}")" \
		--arg contract_sha256 "$(sha256_file "${contract_file}")" \
		--arg suite_sha256 "$(compute_suite_sha256)" \
		--arg target "${expected_target}" \
		'
		  type == "object"
		  and ((keys | sort) == [
		    "blocks_sha256", "business_sha256", "completed_at_utc",
		    "contract_sha256", "pipeline_sha256", "release_digest",
		    "release_id", "schema_version", "stage", "status",
		    "suite_sha256", "target"
		  ])
		  and .schema_version == 1
		  and .stage == $stage
		  and .status == "PASS"
		  and .release_id == $release_id
		  and .release_digest == $release_digest
		  and .business_sha256 == $business_sha256
		  and .blocks_sha256 == $blocks_sha256
		  and .pipeline_sha256 == $pipeline_sha256
		  and .contract_sha256 == $contract_sha256
		  and .suite_sha256 == $suite_sha256
		  and ($target == "" or .target == $target)
		  and (.completed_at_utc | test(
		    "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"
		  ))
		' "${receipt}" >/dev/null || die "Contrato de recibo ${stage} rechazado"
	completed_epoch="$(jq -er '.completed_at_utc | fromdateiso8601' "${receipt}")"
	now_epoch="$(date -u +%s)"
	((completed_epoch <= now_epoch + 300)) \
		|| die "Recibo ${stage} fechado en el futuro"
	((now_epoch - completed_epoch <= 86400)) \
		|| die "Recibo ${stage} vencido"
}

run_local_gate() {
	verify_artifacts
	bash "${docker_gate}"
	bash "${promotion_test}"
	write_receipt docker local-compose
	note "PASS: gate Docker/local Business 1.98.2 + Blocks 2.1.6"
}

load_environment() {
	local config="$1"
	verify_regular_file "${config_library}"
	# shellcheck source=ops/lib/akibara-promotion-config.sh
	source "${config_library}"
	akb_load_promotion_config "${config}" \
		|| die "Configuración productiva rechazada"
	akb_select_promotion_environment production \
		|| die "Destino debe ser producción"
	[[ "${AKB_BASE_URL}" == https://akibara.cl
		&& "${AKB_WEBROOT}" == */public_html ]] \
		|| die "Destino productivo inesperado"
}

ssh_read() {
	local remote_script="$1" command
	printf -v command 'AKB_WEBROOT=%q bash -s' "${AKB_WEBROOT}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" <<< "${remote_script}"
}

ssh_logs_read() {
	local remote_script="$1" command
	printf -v command 'AKB_LOG_PATHS=%q bash -s' "${AKB_LOG_PATHS}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" <<< "${remote_script}"
}

remote_inventory() {
	local kind="$1" slug="$2" output="$3" remote_script
	case "${kind}" in
		plugin) runtime_root='${AKB_WEBROOT}/wp-content/plugins' ;;
		theme) runtime_root='${AKB_WEBROOT}/wp-content/themes' ;;
		*) die "Tipo de inventario inválido: ${kind}" ;;
	esac
	printf -v remote_script '
set -Eeuo pipefail
root=%s
slug=%q
target="${root}/${slug}"
[[ -d "${target}" && ! -L "${target}" ]]
test -z "$(find "${target}" ! -type d ! -type f -print -quit)"
find "${target}" -type f -print | LC_ALL=C sort | while IFS= read -r file; do
  relative="${file#"${root}/"}"
  printf "%%s  %%s\n" "$(sha256sum "${file}" | awk "{print \\$1}")" "${relative}"
done
' "${runtime_root}" "${slug}"
	ssh_read "${remote_script}" > "${output}.partial"
	mv -- "${output}.partial" "${output}"
	[[ -s "${output}" ]] || die "Inventario remoto vacío: ${slug}"
}

verify_remote_pair_state() {
	local business_version="$1" business_inventory="$2"
	local blocks_version="$3" blocks_inventory="$4" prefix="$5"
	local remote_script
	printf -v remote_script '
set -Eeuo pipefail
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-business --field=version)" == %q ]]
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-cart-checkout-blocks --field=version)" == %q ]]
wp --path="${AKB_WEBROOT}" plugin is-active akibara-business
wp --path="${AKB_WEBROOT}" plugin is-active akibara-cart-checkout-blocks
' "${business_version}" "${blocks_version}"
	ssh_read "${remote_script}"
	remote_inventory plugin akibara-business "${prefix}.business.files.sha256"
	remote_inventory plugin akibara-cart-checkout-blocks "${prefix}.blocks.files.sha256"
	cmp -s "${business_inventory}" "${prefix}.business.files.sha256" \
		|| die "Business remoto ${business_version} difiere del inventario exacto"
	cmp -s "${blocks_inventory}" "${prefix}.blocks.files.sha256" \
		|| die "Blocks remoto ${blocks_version} difiere del inventario exacto"
}

remote_preflight() {
	local remote_script
	printf -v remote_script '
set -Eeuo pipefail
for command_name in bash cmp find mktemp sha256sum tar wp; do
  command -v "${command_name}" >/dev/null
done
plugins="${AKB_WEBROOT}/wp-content/plugins"
themes="${AKB_WEBROOT}/wp-content/themes"
for target in \
  "${plugins}/akibara-business" \
  "${plugins}/akibara-cart-checkout-blocks" \
  "${plugins}/akibara-search" \
  "${plugins}/bluex-for-woocommerce" \
  "${themes}/akibara-gp"; do
  [[ -d "${target}" && ! -L "${target}" ]]
  test -z "$(find "${target}" ! -type d ! -type f -print -quit)"
done
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-business --field=version)" == %q ]]
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-cart-checkout-blocks --field=version)" == %q ]]
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-search --field=version)" == %q ]]
[[ "$(wp --path="${AKB_WEBROOT}" theme get akibara-gp --field=version)" == %q ]]
[[ "$(wp --path="${AKB_WEBROOT}" plugin get bluex-for-woocommerce --field=version)" == %q ]]
for plugin in \
  akibara-business akibara-cart-checkout-blocks akibara-search \
  bluex-for-woocommerce seo-by-rank-math woocommerce; do
  wp --path="${AKB_WEBROOT}" plugin is-active "${plugin}"
done
wp --path="${AKB_WEBROOT}" theme is-active akibara-gp
' \
		"${business_preimage_version}" "${blocks_preimage_version}" \
		"${search_version}" "${theme_version}" "${bluex_version}"
	ssh_read "${remote_script}"
}

capture_runtime_invariants() {
	local output="$1" remote_script
	remote_script='
set -Eeuo pipefail
plugins="${AKB_WEBROOT}/wp-content/plugins"
themes="${AKB_WEBROOT}/wp-content/themes"
wp --path="${AKB_WEBROOT}" plugin list \
  --fields=name,status,version --format=csv \
  | awk -F, "NR == 1 || (\$1 != \"akibara-business\" && \$1 != \"akibara-cart-checkout-blocks\")"
wp --path="${AKB_WEBROOT}" theme list \
  --fields=name,status,version --format=csv
for scope in plugins themes; do
  root="${AKB_WEBROOT}/wp-content/${scope}"
  count=0
  digest="$(
    find "${root}" -type f -print | LC_ALL=C sort | while IFS= read -r file; do
      case "${file}" in
        "${plugins}/akibara-business/"*|"${plugins}/akibara-cart-checkout-blocks/"*)
          continue
          ;;
      esac
      count=$((count + 1))
      relative="${file#"${AKB_WEBROOT}/wp-content/"}"
      printf "%s  %s\n" "$(sha256sum "${file}" | awk "{print \$1}")" "${relative}"
    done | sha256sum | awk "{print \$1}"
  )"
  printf "@tree\t%s\t%s\n" "${scope}" "${digest}"
done
'
	ssh_read "${remote_script}" | LC_ALL=C sort > "${output}.partial"
	mv -- "${output}.partial" "${output}"
	[[ -s "${output}" ]] || die "Fingerprint de runtime invariante vacío"
	shasum -a 256 "${output}" > "${output}.sha256"
}

remote_artifact_gate() {
	local state="$1" remote_script
	case "${state}" in active|absent) ;; *) die "Estado de mantenimiento inválido" ;; esac
	printf -v remote_script '
set -Eeuo pipefail
plugins="${AKB_WEBROOT}/wp-content/plugins"
themes="${AKB_WEBROOT}/wp-content/themes"
for target in \
  "${plugins}/akibara-business" \
  "${plugins}/akibara-cart-checkout-blocks"; do
  [[ -d "${target}" && ! -L "${target}" ]]
  test -z "$(find "${target}" ! -type d ! -type f -print -quit)"
done
for scope in \
  "${plugins}/akibara-business" \
  "${plugins}/akibara-cart-checkout-blocks"; do
  test -z "$(find "${scope}" -type f \( \
    -name "*.bak" -o -name "*.bk" -o -name "*.orig" -o -name "*.rej" -o \
    -name "*.tmp" -o -name "*.temp" -o -name "*.dump" -o -name "*.backup" -o \
    -name "*.sql" -o -name "*.sqlite" -o -name "*.tar" -o -name "*.tgz" -o \
    -name "*.zip" -o -name "*~" -o -name "._*" -o -name ".DS_Store" \
  \) -print -quit)"
done
test -z "$(find "${plugins}" "${themes}" -maxdepth 1 -type f \( \
  -name "*.bak" -o -name "*.bk" -o -name "*.orig" -o -name "*.rej" -o \
  -name "*.tmp" -o -name "*.temp" -o -name "*.dump" -o -name "*.backup" -o \
  -name "*.sql" -o -name "*.sqlite" -o -name "*.tar" -o -name "*.tgz" -o \
  -name "*.zip" -o -name "*~" -o -name "._*" -o -name ".DS_Store" \
\) -print -quit)"
test -z "$(find "${plugins}" -maxdepth 1 \( \
  -name "akibara-business.previous" -o -name "akibara-business.backup" -o \
  -name "akibara-cart-checkout-blocks.previous" -o \
  -name "akibara-cart-checkout-blocks.backup" \
\) -print -quit)"
for upgrade in \
  "${AKB_WEBROOT}/wp-content/upgrade" \
  "${AKB_WEBROOT}/wp-content/upgrade-temp-backup"; do
  if [[ -d "${upgrade}" ]]; then
    test -z "$(find "${upgrade}" -mindepth 1 -print -quit)"
  fi
done
test -z "$(find /tmp -maxdepth 1 -name "akibara-business-blocks.*" -print -quit)"
test -z "$(find "${AKB_WEBROOT}" -maxdepth 1 -name ".maintenance.akb.*" -print -quit)"
test -z "$(find "${AKB_WEBROOT}" -maxdepth 1 -type f \( \
  -name "*.sql" -o -name "*.zip" -o -name "*.bak" -o -name "*.tmp" \
\) -print -quit)"
if [[ %q == active ]]; then
  [[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
  printf "%%s\n" "<?php \$upgrading = time(); ?>" |
    cmp -s - "${AKB_WEBROOT}/.maintenance"
else
  [[ ! -e "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
fi
' "${state}"
	ssh_read "${remote_script}"
}

cleanup_empty_upgrade_dirs() {
	ssh_read '
set -Eeuo pipefail
for root in \
  "${AKB_WEBROOT}/wp-content/upgrade" \
  "${AKB_WEBROOT}/wp-content/upgrade-temp-backup"; do
  if [[ -d "${root}" && ! -L "${root}" ]]; then
    find "${root}" -depth -type d -empty -delete
  fi
done
echo UPGRADE_DIRS_EMPTY
'
}

stream_code_backup() {
	local output="$1" remote_script entry
	remote_script='
set -Eeuo pipefail
content="${AKB_WEBROOT}/wp-content"
for target in \
  "${content}/plugins/akibara-business" \
  "${content}/plugins/akibara-cart-checkout-blocks"; do
  [[ -d "${target}" && ! -L "${target}" ]]
  test -z "$(find "${target}" ! -type d ! -type f -print -quit)"
done
tar -C "${content}" -czf - \
  plugins/akibara-business \
  plugins/akibara-cart-checkout-blocks
'
	ssh_read "${remote_script}" > "${output}.partial"
	mv -- "${output}.partial" "${output}"
	verify_regular_file "${output}"
	tar -tzf "${output}" >/dev/null
	while IFS= read -r entry; do
		case "${entry}" in
			plugins/akibara-business|plugins/akibara-business/*|\
			plugins/akibara-cart-checkout-blocks|plugins/akibara-cart-checkout-blocks/*)
				;;
			*) die "Respaldo local contiene ruta inesperada: ${entry}" ;;
		esac
	done < <(tar -tzf "${output}")
	shasum -a 256 "${output}" > "${output}.sha256"
}

activate_maintenance() {
	ssh_read '
set -Eeuo pipefail
file="${AKB_WEBROOT}/.maintenance"
[[ ! -e "${file}" && ! -L "${file}" ]]
tmp="$(mktemp "${AKB_WEBROOT}/.maintenance.akb.XXXXXX")"
cleanup() { [[ -z "${tmp:-}" ]] || rm -f -- "${tmp}"; }
trap "cleanup" EXIT HUP INT TERM
chmod 0644 "${tmp}"
printf "%s\n" "<?php \$upgrading = time(); ?>" > "${tmp}"
ln -- "${tmp}" "${file}"
rm -f -- "${tmp}"
tmp=""
trap - EXIT HUP INT TERM
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${file}"
echo MAINTENANCE_ACTIVE
'
}

ensure_maintenance() {
	ssh_read '
set -Eeuo pipefail
file="${AKB_WEBROOT}/.maintenance"
if [[ -e "${file}" || -L "${file}" ]]; then
  [[ -f "${file}" && ! -L "${file}" ]]
  printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${file}"
else
  tmp="$(mktemp "${AKB_WEBROOT}/.maintenance.akb.XXXXXX")"
  cleanup() { [[ -z "${tmp:-}" ]] || rm -f -- "${tmp}"; }
  trap "cleanup" EXIT
  chmod 0644 "${tmp}"
  printf "%s\n" "<?php \$upgrading = time(); ?>" > "${tmp}"
  ln -- "${tmp}" "${file}"
  rm -f -- "${tmp}"
  tmp=""
  trap - EXIT
fi
echo MAINTENANCE_ENSURED
'
}

verify_maintenance() {
	ssh_read '
set -Eeuo pipefail
[[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" |
  cmp -s - "${AKB_WEBROOT}/.maintenance"
echo MAINTENANCE_VERIFIED
'
}

deactivate_maintenance() {
	ssh_read '
set -Eeuo pipefail
file="${AKB_WEBROOT}/.maintenance"
[[ -f "${file}" && ! -L "${file}" ]]
printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${file}"
rm -f -- "${file}"
[[ ! -e "${file}" && ! -L "${file}" ]]
echo MAINTENANCE_INACTIVE
'
}

purge_caches_under_maintenance() {
	ssh_read '
set -Eeuo pipefail
[[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
wp --path="${AKB_WEBROOT}" cache flush >/dev/null
if wp --path="${AKB_WEBROOT}" plugin is-active litespeed-cache; then
  wp --path="${AKB_WEBROOT}" eval "if (! class_exists(\"LiteSpeed\\\\Purge\")) { throw new RuntimeException(\"LiteSpeed Purge unavailable\"); } \\LiteSpeed\\Purge::purge_all(\"Business Blocks promotion\");" >/dev/null
fi
echo CACHES_PURGED_UNDER_MAINTENANCE
'
}

purge_caches_live() {
	ssh_read '
set -Eeuo pipefail
[[ ! -e "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
wp --path="${AKB_WEBROOT}" cache flush >/dev/null
if wp --path="${AKB_WEBROOT}" plugin is-active litespeed-cache; then
  wp --path="${AKB_WEBROOT}" litespeed-purge all >/dev/null
fi
echo CACHES_PURGED_LIVE
'
}

maintenance_http_gate() {
	local headers http_code probe
	headers="$(mktemp "${TMPDIR:-/tmp}/akibara-business-blocks-maintenance.XXXXXX")"
	trap 'case "${headers:-}" in */akibara-business-blocks-maintenance.*) rm -f -- "${headers}" ;; esac' RETURN
	probe="/?akb-business-blocks-maintenance=$(date -u +%s)-$$"
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
		|| die "Mantenimiento esperaba 503; recibió ${http_code}"
	! grep -Eiq '^cf-cache-status:[[:space:]]*(HIT|STALE|UPDATING)([[:space:]]|$)' \
		"${headers}" || die "Cloudflare sirvió la prueba de mantenimiento desde caché"
	trap - RETURN
	rm -f -- "${headers}"
	note MAINTENANCE_HTTP_503_PASS
}

validate_fingerprint() {
	local kind="$1" snapshot="$2"
	verify_regular_file "${snapshot}"
	LC_ALL=C sort -c "${snapshot}" >/dev/null 2>&1 \
		|| die "Fingerprint ${kind} no está ordenado"
	case "${kind}" in
		commerce)
			for marker in \
				$'@dataset\tlegacy_orders\t' \
				$'@dataset\thpos_orders\t' \
				$'@dataset\twordpress_users\t' \
				$'@dataset\twoocommerce_order_items\t'; do
				grep -Fq "${marker}" "${snapshot}" \
					|| die "Fingerprint comercial incompleto: ${marker}"
			done
			;;
		search)
			for marker in \
				$'@table\takb_search_docs\t' \
				$'@table\takb_search_tokens\t' \
				$'@dataset\tsearch_options\tpresent'; do
				grep -Fq "${marker}" "${snapshot}" \
					|| die "Fingerprint Search incompleto: ${marker}"
			done
			;;
	esac
}

capture_fingerprint() {
	local kind="$1" output="$2" php_file php_code remote_script
	case "${kind}" in
		commerce) php_file="${commerce_fingerprint_php}" ;;
		search) php_file="${search_fingerprint_php}" ;;
		*) die "Fingerprint inválido: ${kind}" ;;
	esac
	php_code="$(sed '1{/^<?php$/d;}' "${php_file}")"
	printf -v remote_script 'set -Eeuo pipefail; wp --path="${AKB_WEBROOT}" eval %q' \
		"${php_code}"
	ssh_read "${remote_script}" > "${output}.partial"
	LC_ALL=C sort "${output}.partial" > "${output}"
	rm -f -- "${output}.partial"
	validate_fingerprint "${kind}" "${output}"
	shasum -a 256 "${output}" > "${output}.sha256"
}

capture_log_baseline() {
	local output="$1"
	ssh_logs_read '
set -Eeuo pipefail
IFS=";" read -r -a paths <<< "${AKB_LOG_PATHS}"
index=0
for path in "${paths[@]}"; do
  index=$((index + 1))
  if [[ -f "${path}" && ! -L "${path}" ]]; then
    printf "%02d\tpresent\t%s\t%s\t%s\n" \
      "${index}" \
      "$(stat -c %i "${path}")" \
      "$(stat -c %s "${path}")" \
      "$(sha256sum "${path}" | awk "{print \$1}")"
  elif [[ ! -e "${path}" && ! -L "${path}" ]]; then
    printf "%02d\tabsent\t0\t0\t-\n" "${index}"
  else
    exit 1
  fi
done
' > "${output}.partial"
	LC_ALL=C sort "${output}.partial" > "${output}"
	rm -f -- "${output}.partial"
	[[ -s "${output}" ]] || die "Baseline de logs vacío"
	awk -F '\t' '
	  NF != 5 || $1 !~ /^[0-9][0-9]$/ ||
	  ($2 != "present" && $2 != "absent") ||
	  $3 !~ /^[0-9]+$/ || $4 !~ /^[0-9]+$/ ||
	  ($2 == "present" && (length($5) != 64 || $5 !~ /^[a-f0-9]+$/)) ||
	  ($2 == "absent" && $5 != "-") { exit 1 }
	' "${output}" || die "Baseline de logs inválido"
}

verify_log_delta() {
	local baseline="$1" output="$2" baseline_data remote_script
	baseline_data="$(<"${baseline}")"
	printf -v remote_script '
set -Eeuo pipefail
baseline=%q
IFS=";" read -r -a paths <<< "${AKB_LOG_PATHS}"
index=0
for path in "${paths[@]}"; do
  index=$((index + 1))
  key="$(printf "%%02d" "${index}")"
  row="$(awk -F "\t" -v key="${key}" "\$1 == key { print; exit }" <<< "${baseline}")"
  [[ -n "${row}" ]]
  IFS=$'"'"'\t'"'"' read -r _ status old_inode old_size old_prefix_sha <<< "${row}"
  if [[ -f "${path}" && ! -L "${path}" ]]; then
    inode="$(stat -c %%i "${path}")"
    size="$(stat -c %%s "${path}")"
    if [[ "${status}" == present ]]; then
      [[ "${inode}" == "${old_inode}" && "${size}" -ge "${old_size}" ]]
      prefix_sha="$(
        head -c "${old_size}" "${path}" |
          sha256sum | awk "{print \$1}"
      )"
      [[ "${prefix_sha}" == "${old_prefix_sha}" ]]
    else
      old_size=0
    fi
    start=$((old_size + 1))
    if ((size >= start)); then
      if tail -c +"${start}" "${path}" |
        grep -Eiq "PHP (Fatal|Parse|Warning)|Uncaught|Allowed memory|Maximum execution|timed out"; then
        printf "log-%%02d\tblocked\n" "${index}"
        exit 1
      fi
    fi
    printf "log-%%02d\tclean\n" "${index}"
  elif [[ "${status}" == absent && ! -e "${path}" && ! -L "${path}" ]]; then
    printf "log-%%02d\tclean-absent\n" "${index}"
  else
    exit 1
  fi
done
' "${baseline_data}"
	ssh_logs_read "${remote_script}" > "${output}.partial"
	mv -- "${output}.partial" "${output}"
	[[ -s "${output}" ]] || die "Gate de logs vacío"
	shasum -a 256 "${output}" > "${output}.sha256"
}

install_component() {
	local slug="$1" version="$2" archive_sha="$3" archive="$4"
	local command remote_script
	case "${slug}" in
		akibara-business|akibara-cart-checkout-blocks) ;;
		*) die "Componente instalable fuera de allowlist: ${slug}" ;;
	esac
	remote_script='
set -Eeuo pipefail
remote_zip="$(mktemp --suffix=.zip /tmp/akibara-business-blocks.XXXXXX)"
cleanup() { rm -f -- "${remote_zip}"; }
trap "cleanup || true" EXIT
trap "exit 129" HUP
trap "exit 130" INT
trap "exit 143" TERM
cat > "${remote_zip}"
[[ "$(sha256sum "${remote_zip}" | awk "{print \$1}")" == "${AKB_SHA}" ]]
wp --path="${AKB_WEBROOT}" plugin install "${remote_zip}" --force
[[ "$(wp --path="${AKB_WEBROOT}" plugin get "${AKB_SLUG}" --field=version)" == "${AKB_VERSION}" ]]
wp --path="${AKB_WEBROOT}" plugin is-active "${AKB_SLUG}"
cleanup
trap - EXIT HUP INT TERM
[[ ! -e "${remote_zip}" ]]
'
	printf -v command \
		'AKB_WEBROOT=%q AKB_SLUG=%q AKB_VERSION=%q AKB_SHA=%q bash -c %q' \
		"${AKB_WEBROOT}" "${slug}" "${version}" "${archive_sha}" "${remote_script}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" < "${archive}"
}

restore_code() {
	local backup="$1" command remote_script
	verify_regular_file "${backup}"
	verify_regular_file "${backup}.sha256"
	shasum -a 256 -c "${backup}.sha256" >/dev/null \
		|| die "Checksum de respaldo local inválido"
	tar -tzf "${backup}" >/dev/null
	remote_script='
set -Eeuo pipefail
plugins="${AKB_WEBROOT}/wp-content/plugins"
rm -rf -- \
  "${plugins}/akibara-business" \
  "${plugins}/akibara-cart-checkout-blocks"
tar -C "${AKB_WEBROOT}/wp-content" -xzf -
for target in \
  "${plugins}/akibara-business" \
  "${plugins}/akibara-cart-checkout-blocks"; do
  [[ -d "${target}" && ! -L "${target}" ]]
  test -z "$(find "${target}" ! -type d ! -type f -print -quit)"
done
wp --path="${AKB_WEBROOT}" option update \
  akibara_business_version_installed "${AKB_BUSINESS_VERSION}" >/dev/null
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-business --field=version)" == "${AKB_BUSINESS_VERSION}" ]]
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-cart-checkout-blocks --field=version)" == "${AKB_BLOCKS_VERSION}" ]]
wp --path="${AKB_WEBROOT}" plugin is-active akibara-business
wp --path="${AKB_WEBROOT}" plugin is-active akibara-cart-checkout-blocks
'
	printf -v command \
		'AKB_WEBROOT=%q AKB_BUSINESS_VERSION=%q AKB_BLOCKS_VERSION=%q bash -c %q' \
		"${AKB_WEBROOT}" "${business_preimage_version}" \
		"${blocks_preimage_version}" "${remote_script}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" < "${backup}"
}

verify_installed_option() {
	local remote_script
	printf -v remote_script '
set -Eeuo pipefail
[[ "$(wp --path="${AKB_WEBROOT}" option get akibara_business_version_installed)" == %q ]]
' "${business_candidate_version}"
	ssh_read "${remote_script}"
}

verify_http_semantics() {
	local evidence_dir="$1" scratch http_code remote_script
	scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-blocks-http.XXXXXX")"
	trap 'case "${scratch:-}" in */akibara-business-blocks-http.*) rm -rf -- "${scratch}" ;; esac' RETURN
	remote_script='
set -Eeuo pipefail
wp --path="${AKB_WEBROOT}" eval "
\$page_id = (int) wc_get_page_id(\"checkout\");
\$content = \$page_id > 0 ? (string) get_post_field(\"post_content\", \$page_id) : \"\";
if (\$page_id <= 0 || ! has_block(\"woocommerce/checkout\", \$content)) {
    throw new RuntimeException(\"Checkout page is not owned by WooCommerce Blocks\");
}
echo \"CHECKOUT_BLOCK_PAGE_PASS\\n\";
"
'
	ssh_read "${remote_script}" > "${evidence_dir}/checkout-block-page.txt"
	for path in / /tienda/ /carrito/ /checkout/; do
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

	curl --silent --show-error \
		--proto '=https' \
		--connect-timeout 15 --max-time 45 \
		--cookie-jar "${scratch}/cookies" \
		"${AKB_BASE_URL}/wp-json/wc/store/v1/cart" \
		> "${scratch}/cart.json"
	jq -e '
	  type == "object"
	  and (.extensions.akibara_cart_checkout_blocks | type == "object")
	  and (.extensions.akibara_cart_checkout_blocks.schema_version == 2)
	' "${scratch}/cart.json" >/dev/null \
		|| die "Store API no expone el contrato Akibara v2"
	jq -n \
		--arg checked_at_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
		'{status:"PASS",checked_at_utc:$checked_at_utc,
		  paths:["/","/tienda/","/carrito/","/checkout/"],
		  store_api_namespace:"akibara_cart_checkout_blocks",
		  schema_version:2}' \
		> "${evidence_dir}/http-semantics.json"
	shasum -a 256 "${evidence_dir}/http-semantics.json" \
		> "${evidence_dir}/http-semantics.json.sha256"
	trap - RETURN
	rm -rf -- "${scratch}"
}

cleanup_on_failure() {
	local exit_status="$1" rollback_safe=1
	set +e
	((exit_status != 0)) || return 0
	if ((mutation_started)); then
		note "Fallo tras mutación: restaurando Business + Blocks desde respaldo local."
		if ! ensure_maintenance; then
			rollback_safe=0
		else
			maintenance_active=1
		fi
		if ((rollback_safe)) && ! restore_code "${code_backup}"; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && ! cleanup_empty_upgrade_dirs; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && ! purge_caches_under_maintenance; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && ! verify_remote_pair_state \
			"${business_preimage_version}" "${business_preimage_inventory}" \
			"${blocks_preimage_version}" "${blocks_preimage_inventory}" \
			"${active_evidence_dir}/remote-after-rollback"; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && [[ -f "${active_evidence_dir}/commerce-before.tsv" ]]; then
			capture_fingerprint commerce "${active_evidence_dir}/commerce-after-rollback.tsv" \
				|| rollback_safe=0
			cmp -s "${active_evidence_dir}/commerce-before.tsv" \
				"${active_evidence_dir}/commerce-after-rollback.tsv" \
				|| rollback_safe=0
		fi
		if ((rollback_safe)) && [[ -f "${active_evidence_dir}/search-before.tsv" ]]; then
			capture_fingerprint search "${active_evidence_dir}/search-after-rollback.tsv" \
				|| rollback_safe=0
			cmp -s "${active_evidence_dir}/search-before.tsv" \
				"${active_evidence_dir}/search-after-rollback.tsv" \
				|| rollback_safe=0
		fi
		if ((rollback_safe)) && [[ -f "${active_evidence_dir}/runtime-invariants-before.tsv" ]]; then
			capture_runtime_invariants \
				"${active_evidence_dir}/runtime-invariants-after-rollback.tsv" \
				|| rollback_safe=0
			cmp -s "${active_evidence_dir}/runtime-invariants-before.tsv" \
				"${active_evidence_dir}/runtime-invariants-after-rollback.tsv" \
				|| rollback_safe=0
		fi
	fi
	if ((maintenance_active)); then
		if ((rollback_safe)); then
			remote_artifact_gate active || rollback_safe=0
			if ((rollback_safe)); then
				deactivate_maintenance || rollback_safe=0
				maintenance_active=0
				purge_caches_live || rollback_safe=0
				remote_artifact_gate absent || rollback_safe=0
			fi
		else
			note "Fail-closed: mantenimiento permanece activo; rollback no demostrable."
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
  1. Exigir contrato sellado, artefactos reproducibles y recibo Docker vigente.
  2. Exigir baseline remoto Business 1.98.1 y Blocks 2.1.5 exactas.
  3. Exigir Search 0.1.8, theme 2.1.58, BlueX 3.2.2 y Rank Math activos.
  4. Capturar runtime ajeno, logs, comercio y Search; respaldar solo ambos
     árboles por streaming a local.
  5. Activar mantenimiento y demostrar HTTP 503 sin cache hit.
  6. Instalar Business 1.98.2 y luego Blocks 2.1.6 con ZIP mktemp + trap.
  7. Exigir inventarios candidatos, runtime ajeno y fingerprints intactos.
  8. Reabrir, purgar cachés y validar checkout Blocks + Store API schema v2.
  9. Exigir logs limpios y cero ZIP, respaldos o temporales en producción.

No se instala Search, theme, BlueX ni ningún tercero. No se modifica SEO,
contenido, configuración UI o Graphify.
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
	active_backup_dir="${repo_root}/.local-backups/business-blocks-production/production/${stamp}-${release_digest}"
	active_evidence_dir="${active_backup_dir}/evidence"
	mkdir -p -- "${active_backup_dir}/code" "${active_evidence_dir}"
	chmod 0700 "${active_backup_dir}"
	code_backup="${active_backup_dir}/code/business-blocks-before.tar.gz"
	preimage_prefix="${active_evidence_dir}/remote-preimage"
	final_prefix="${active_evidence_dir}/remote-final"

	trap promotion_exit_handler EXIT
	trap 'promotion_signal_handler 129' HUP
	trap 'promotion_signal_handler 130' INT
	trap 'promotion_signal_handler 143' TERM

	remote_preflight | tee "${active_evidence_dir}/remote-preflight.txt"
	verify_remote_pair_state \
		"${business_preimage_version}" "${business_preimage_inventory}" \
		"${blocks_preimage_version}" "${blocks_preimage_inventory}" \
		"${preimage_prefix}"
	remote_inventory plugin akibara-search \
		"${active_evidence_dir}/search-before.files.sha256"
	cmp -s "${search_inventory}" "${active_evidence_dir}/search-before.files.sha256" \
		|| die "Search productivo no coincide con 0.1.8 sellado"
	remote_inventory theme akibara-gp \
		"${active_evidence_dir}/theme-before.files.sha256"
	cmp -s "${theme_inventory}" "${active_evidence_dir}/theme-before.files.sha256" \
		|| die "Theme productivo no coincide con 2.1.58 sellado"
	capture_runtime_invariants "${active_evidence_dir}/runtime-invariants-before.tsv"
	# WordPress deja `wp-content/upgrade-temp-backup/plugins` vacío después de
	# cualquier actualización suya, y el gate de artefactos exige ese árbol sin
	# una sola entrada. Barrer los directorios vacíos ANTES del gate —y no solo
	# después de instalar, como se hacía— evita abortar el despliegue por un
	# resto inocuo que el propio riel ya sabe limpiar.
	cleanup_empty_upgrade_dirs \
		| tee "${active_evidence_dir}/upgrade-cleanup-before-gate.txt" \
		|| die "No se pudo limpiar los directorios de upgrade vacíos"
	remote_artifact_gate absent \
		| tee "${active_evidence_dir}/remote-artifact-gate-before.txt" \
		|| die "Gate remoto de artefactos falló con mantenimiento ausente"
	stream_code_backup "${code_backup}"
	capture_log_baseline "${active_evidence_dir}/logs-before.tsv"

	maintenance_active=1
	activate_maintenance | tee "${active_evidence_dir}/maintenance-active.txt"
	purge_caches_under_maintenance \
		| tee "${active_evidence_dir}/caches-under-maintenance-before.txt"
	maintenance_http_gate \
		| tee "${active_evidence_dir}/maintenance-http-before.txt"
	capture_fingerprint commerce "${active_evidence_dir}/commerce-before.tsv"
	capture_fingerprint search "${active_evidence_dir}/search-before.tsv"

	mutation_started=1
	install_component akibara-business \
		"${business_candidate_version}" \
		"${business_candidate_archive_sha}" "${business_candidate_archive}"
	verify_maintenance | tee "${active_evidence_dir}/maintenance-after-business.txt"
	install_component akibara-cart-checkout-blocks \
		"${blocks_candidate_version}" \
		"${blocks_candidate_archive_sha}" "${blocks_candidate_archive}"
	verify_maintenance | tee "${active_evidence_dir}/maintenance-after-blocks.txt"
	cleanup_empty_upgrade_dirs \
		| tee "${active_evidence_dir}/upgrade-cleanup-after-install.txt"
	verify_remote_pair_state \
		"${business_candidate_version}" "${business_candidate_inventory}" \
		"${blocks_candidate_version}" "${blocks_candidate_inventory}" \
		"${final_prefix}"
	verify_installed_option

	remote_inventory plugin akibara-search \
		"${active_evidence_dir}/search-after.files.sha256"
	cmp -s "${search_inventory}" "${active_evidence_dir}/search-after.files.sha256" \
		|| die "Search cambió durante el release"
	remote_inventory theme akibara-gp \
		"${active_evidence_dir}/theme-after.files.sha256"
	cmp -s "${theme_inventory}" "${active_evidence_dir}/theme-after.files.sha256" \
		|| die "Theme cambió durante el release"
	capture_runtime_invariants "${active_evidence_dir}/runtime-invariants-after.tsv"
	cmp -s \
		"${active_evidence_dir}/runtime-invariants-before.tsv" \
		"${active_evidence_dir}/runtime-invariants-after.tsv" \
		|| die "Runtime ajeno, incluido BlueX, cambió durante el release"
	capture_fingerprint commerce "${active_evidence_dir}/commerce-after.tsv"
	capture_fingerprint search "${active_evidence_dir}/search-after.tsv"
	cmp -s "${active_evidence_dir}/commerce-before.tsv" \
		"${active_evidence_dir}/commerce-after.tsv" \
		|| die "Estado comercial cambió durante el release de código"
	cmp -s "${active_evidence_dir}/search-before.tsv" \
		"${active_evidence_dir}/search-after.tsv" \
		|| die "Search cambió durante el release de código"
	verify_log_delta \
		"${active_evidence_dir}/logs-before.tsv" \
		"${active_evidence_dir}/logs-under-maintenance.tsv"
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
	verify_log_delta \
		"${active_evidence_dir}/logs-before.tsv" \
		"${active_evidence_dir}/logs-final.tsv"
	remote_artifact_gate absent \
		| tee "${active_evidence_dir}/remote-artifact-gate-final.txt"
	shasum -a 256 "${active_evidence_dir}/remote-artifact-gate-final.txt" \
		> "${active_evidence_dir}/remote-artifact-gate-final.txt.sha256"

	mutation_started=0
	trap - EXIT HUP INT TERM
	write_receipt production "${AKB_HOST}:${AKB_WEBROOT}"
	note "PASS: Business 1.98.2 + Blocks 2.1.6 en producción; evidencia local: ${active_backup_dir}"
}

if [[ "${AKB_BUSINESS_BLOCKS_PROMOTION_LIBRARY_ONLY:-0}" == 1 ]]; then
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
load_contract
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
		load_environment "${config_file}"
		render_plan
		;;
	promote)
		[[ -n "${config_file}" && "${apply}" == 1 ]] \
			|| die "promote requiere --config FILE y --apply"
		[[ "${confirm}" == "${release_id}" ]] \
			|| die "Confirmación exacta requerida: ${release_id}"
		load_environment "${config_file}"
		promote_production
		;;
	*)
		die "Comando desconocido: ${command_name}"
		;;
esac
