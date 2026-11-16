#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
dist_dir="${repo_root}/dist"
contract_file="${script_dir}/contracts/akibara-business-1.95.3-mailpoet.json"
config_library="${script_dir}/lib/akibara-promotion-config.sh"
commerce_fingerprint_php="${script_dir}/akibara-protected-commerce-fingerprint.php"
search_fingerprint_php="${script_dir}/akibara-search-state-fingerprint.php"
gate_root="${repo_root}/.local-backups/business-seo-promotion/gates"

release_id=""
component_slug=""
component_version=""
component_files=""
component_archive_sha=""
component_inventory_sha=""
preimage_version=""
preimage_files=""
preimage_archive_sha=""
preimage_inventory_sha=""
archive_path=""
inventory_path=""
archive_sidecar_path=""
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
code_backup=""
mutation_started=0
maintenance_active=0

usage() {
	cat <<'EOF'
Uso:
  ops/akibara-business-seo-promotion.sh verify-artifact
  ops/akibara-business-seo-promotion.sh local-gate
  ops/akibara-business-seo-promotion.sh plan --config FILE
  ops/akibara-business-seo-promotion.sh promote --config FILE --apply \
    --confirm akibara-business-1.95.3-mailpoet-noindex

Seguridad:
  - El único destino instalable es el plugin akibara-business 1.95.3.
  - Producción requiere un recibo Docker válido y actual.
  - Producción debe tener la preimagen exacta Business 1.95.2.
  - El respaldo del plugin y toda evidencia quedan únicamente en local.
  - El ZIP remoto usa mktemp y trap; el cierre exige ausencia de temporales.
  - Search, Checkout, themes, robots y opciones de Rank Math no se modifican.
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

absolute_path() {
	local input="$1"
	(
		cd -- "$(dirname -- "${input}")"
		printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${input}")"
	)
}

load_contract() {
	local contract_keys component_keys preimage_keys

	require_command jq
	[[ -f "${contract_file}" && ! -L "${contract_file}" ]] \
		|| die "Falta el contrato regular Business SEO: ${contract_file}"

	contract_keys="$(
		jq -r 'keys | sort | join(",")' "${contract_file}"
	)"
	component_keys="$(
		jq -r '.component | keys | sort | join(",")' "${contract_file}"
	)"
	preimage_keys="$(
		jq -r '.preimage | keys | sort | join(",")' "${contract_file}"
	)"
	[[ "${contract_keys}" == "allowed_runtime_changes,component,exclusive_target,preimage,promotion_sequence,release_id,schema_version" ]] \
		|| die "El contrato Business SEO tiene claves superiores inesperadas"
	[[ "${component_keys}" == "archive_sha256,inventory_sha256,kind,runtime_files,slug,version" ]] \
		|| die "El contrato Business SEO tiene claves de componente inesperadas"
	[[ "${preimage_keys}" == "archive_sha256,inventory_sha256,runtime_files,version" ]] \
		|| die "El contrato Business SEO tiene claves de preimagen inesperadas"

	jq -e '
	  .schema_version == 1
	  and .release_id == "akibara-business-1.95.3-mailpoet-noindex"
	  and .component.kind == "plugin"
	  and .component.slug == "akibara-business"
	  and .component.version == "1.95.3"
	  and .component.runtime_files == 210
	  and (.component.archive_sha256 | test("^[a-f0-9]{64}$"))
	  and (.component.inventory_sha256 | test("^[a-f0-9]{64}$"))
	  and .preimage.version == "1.95.2"
	  and .preimage.runtime_files == 210
	  and (.preimage.archive_sha256 | test("^[a-f0-9]{64}$"))
	  and (.preimage.inventory_sha256 | test("^[a-f0-9]{64}$"))
	  and .allowed_runtime_changes == [
	    "akibara-business/akibara-business.php",
	    "akibara-business/src/Modules/SeoIndexing/FilteredUrlsPolicy.php"
	  ]
	  and .promotion_sequence == ["docker", "production"]
	  and .exclusive_target == "public_html/wp-content/plugins/akibara-business"
	' "${contract_file}" >/dev/null \
		|| die "El contrato Business SEO no coincide con el release revisado"

	release_id="$(jq -r '.release_id' "${contract_file}")"
	component_slug="$(jq -r '.component.slug' "${contract_file}")"
	component_version="$(jq -r '.component.version' "${contract_file}")"
	component_files="$(jq -r '.component.runtime_files' "${contract_file}")"
	component_archive_sha="$(jq -r '.component.archive_sha256' "${contract_file}")"
	component_inventory_sha="$(jq -r '.component.inventory_sha256' "${contract_file}")"
	preimage_version="$(jq -r '.preimage.version' "${contract_file}")"
	preimage_files="$(jq -r '.preimage.runtime_files' "${contract_file}")"
	preimage_archive_sha="$(jq -r '.preimage.archive_sha256' "${contract_file}")"
	preimage_inventory_sha="$(jq -r '.preimage.inventory_sha256' "${contract_file}")"

	archive_path="${dist_dir}/${component_slug}-${component_version}.zip"
	inventory_path="${dist_dir}/${component_slug}-${component_version}.files.sha256"
	archive_sidecar_path="${archive_path}.sha256"
	preimage_archive_path="${dist_dir}/${component_slug}-${preimage_version}.zip"
	preimage_inventory_path="${dist_dir}/${component_slug}-${preimage_version}.files.sha256"
	preimage_sidecar_path="${preimage_archive_path}.sha256"
}

verify_regular_artifact() {
	local path="$1"
	[[ -f "${path}" && ! -L "${path}" && -s "${path}" ]] \
		|| die "Artefacto ausente, vacío o inseguro: ${path}"
}

verify_archive_inventory() {
	local archive="$1" inventory="$2" sidecar="$3" version="$4" expected_files="$5"
	local expected_archive_sha="$6" expected_inventory_sha="$7"
	local verify_root entry count=0 header_version constant_version forbidden

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
			&& "${entry}" != *\\* && "${entry}" == "${component_slug}/"* ]] \
			|| die "Entrada ZIP insegura o fuera de raíz: ${entry}"
		case "${entry}" in
			*/) ;;
			*) count=$((count + 1)) ;;
		esac
	done < <(zipinfo -1 "${archive}")
	[[ "${count}" == "${expected_files}" ]] \
		|| die "Inventario ZIP inesperado: ${count}; se esperaban ${expected_files}"

	forbidden="$(
		zipinfo -1 "${archive}" | grep -E \
			'(^|/)(tests|node_modules|migrations|graphify-out|coverage|backups|\.git|\.github|\.idea|\.vscode)(/|$)|(^|/)\._[^/]+$|(\.bak|\.bk|\.orig|\.rej|\.tmp|\.temp|\.dump|\.backup|\.sql|\.sqlite|\.tar|\.tgz|\.zip|~|\.DS_Store|\.phpunit\.result\.cache)$|(^|/)(composer\.(json|lock)|package(-lock)?\.json|yarn\.lock|pnpm-lock\.yaml|phpunit\.xml(\.dist)?|coverage\.xml|debug\.log|error_log)$' \
			|| true
	)"
	[[ -z "${forbidden}" ]] || die "El ZIP contiene rutas prohibidas: ${forbidden}"

	verify_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-seo-verify.XXXXXX")"
	trap 'case "${verify_root:-}" in */akibara-business-seo-verify.*) rm -rf -- "${verify_root}" ;; esac' RETURN
	unzip -tq "${archive}" >/dev/null
	unzip -q "${archive}" -d "${verify_root}"
	[[ -z "$(find "${verify_root}" ! -type d ! -type f -print -quit)" ]] \
		|| die "El ZIP extrajo enlaces o entradas especiales"
	(
		cd "${verify_root}"
		shasum -a 256 -c "${inventory}" >/dev/null
	) || die "El inventario por archivo rechazó ${archive}"

	header_version="$(
		sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' \
			"${verify_root}/${component_slug}/akibara-business.php" | head -n 1
	)"
	constant_version="$(
		sed -nE \
			"s/^[[:space:]]*define\\([[:space:]]*'AKIBARA_BUSINESS_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\\)[[:space:]]*;.*/\\1/p" \
			"${verify_root}/${component_slug}/akibara-business.php" | head -n 1
	)"
	[[ "${header_version}" == "${version}" && "${constant_version}" == "${version}" ]] \
		|| die "Header o constante no coincide con Business ${version}"
	trap - RETURN
	rm -rf -- "${verify_root}"
}

verify_release_delta() {
	local delta_root old_paths new_paths changes expected_changes

	delta_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-seo-delta.XXXXXX")"
	trap 'case "${delta_root:-}" in */akibara-business-seo-delta.*) rm -rf -- "${delta_root}" ;; esac' RETURN
	old_paths="${delta_root}/old.paths"
	new_paths="${delta_root}/new.paths"
	changes="${delta_root}/changes"
	expected_changes="${delta_root}/expected"

	awk '{ hash=$1; $1=""; sub(/^[[:space:]]+/, ""); print }' \
		"${preimage_inventory_path}" > "${old_paths}"
	awk '{ hash=$1; $1=""; sub(/^[[:space:]]+/, ""); print }' \
		"${inventory_path}" > "${new_paths}"
	cmp -s "${old_paths}" "${new_paths}" \
		|| die "El release agrega o elimina rutas runtime fuera del hotfix"

	awk '
	  NR == FNR {
	    old[$2] = $1
	    next
	  }
	  old[$2] != $1 {
	    print $2
	  }
	' "${preimage_inventory_path}" "${inventory_path}" \
		| LC_ALL=C sort > "${changes}"
	jq -r '.allowed_runtime_changes[]' "${contract_file}" \
		| LC_ALL=C sort > "${expected_changes}"
	cmp -s "${changes}" "${expected_changes}" \
		|| die "El delta runtime excede la allowlist del hotfix MailPoet"

	trap - RETURN
	rm -rf -- "${delta_root}"
}

verify_artifact() {
	for command_name in awk cmp find grep jq sed shasum unzip zipinfo; do
		require_command "${command_name}"
	done
	verify_archive_inventory \
		"${preimage_archive_path}" "${preimage_inventory_path}" "${preimage_sidecar_path}" \
		"${preimage_version}" "${preimage_files}" \
		"${preimage_archive_sha}" "${preimage_inventory_sha}"
	verify_archive_inventory \
		"${archive_path}" "${inventory_path}" "${archive_sidecar_path}" \
		"${component_version}" "${component_files}" \
		"${component_archive_sha}" "${component_inventory_sha}"
	verify_release_delta
	note "PASS: Business ${preimage_version} → ${component_version}, 210 archivos y delta exacto de dos rutas"
}

compute_suite_sha256() {
	local relative absolute file
	local -a files=(
		"ops/akibara-business-seo-promotion.sh"
		"ops/contracts/akibara-business-1.95.3-mailpoet.json"
		"ops/lib/akibara-promotion-config.sh"
		"ops/akibara-protected-commerce-fingerprint.php"
		"ops/akibara-search-state-fingerprint.php"
		"ops/test-akibara-business-seo-promotion.sh"
		"tools/akibara-business/composer.json"
		"tools/akibara-business/composer.lock"
		"tools/akibara-business/package.sh"
		"tools/akibara-business/phpunit.xml.dist"
		"tools/akibara-business/qa.sh"
		"tools/akibara-business/test-package.sh"
		"tools/akibara-releases/package-business.sh"
		"tools/akibara-releases/package-business-seo.sh"
		"tools/akibara-releases/package-runtime.sh"
	)

	{
		printf '@contract\takibara-business-seo-suite-v1\n'
		for relative in "${files[@]}"; do
			absolute="${repo_root}/${relative}"
			[[ -f "${absolute}" && ! -L "${absolute}" ]] \
				|| die "Falta archivo regular del gate: ${relative}"
			printf 'file\t%s\t%s\n' "${relative}" "$(sha256_file "${absolute}")"
		done
		while IFS= read -r file; do
			[[ "${file}" == "${repo_root}/public_html/wp-content/plugins/akibara-business/tests/"* ]] \
				|| die "Un test escapó de su raíz"
			relative="${file#"${repo_root}/"}"
			printf 'file\t%s\t%s\n' "${relative}" "$(sha256_file "${file}")"
		done < <(
			find "${repo_root}/public_html/wp-content/plugins/akibara-business/tests" \
				-type f -print | LC_ALL=C sort
		)
	} | LC_ALL=C sort | shasum -a 256 | awk '{print $1}'
}

receipt_path() {
	local stage="$1"
	printf '%s/%s-passed-%s.json' "${gate_root}" "${stage}" "${component_archive_sha}"
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
		--arg archive_sha256 "${component_archive_sha}" \
		--arg inventory_sha256 "${component_inventory_sha}" \
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
	local stage="$1" expected_target="${2:-}" receipt sidecar
	local suite_sha pipeline_sha contract_sha
	local completed_epoch now_epoch

	receipt="$(receipt_path "${stage}")"
	sidecar="${receipt}.sha256"
	verify_regular_artifact "${receipt}"
	verify_regular_artifact "${sidecar}"
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
		--arg archive_sha256 "${component_archive_sha}" \
		--arg inventory_sha256 "${component_inventory_sha}" \
		--arg pipeline_sha256 "${pipeline_sha}" \
		--arg suite_sha256 "${suite_sha}" \
		--arg contract_sha256 "${contract_sha}" \
		--arg expected_target "${expected_target}" \
		'
		  type == "object"
		  and ((keys | sort) == [
		    "archive_sha256",
		    "completed_at_utc",
		    "contract_sha256",
		    "inventory_sha256",
		    "pipeline_sha256",
		    "release_id",
		    "schema_version",
		    "stage",
		    "status",
		    "suite_sha256",
		    "target"
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
		  and ($expected_target == "" or .target == $expected_target)
		  and (.completed_at_utc
		    | type == "string"
		    and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
		' "${receipt}" >/dev/null || die "Contrato de recibo rechazado: ${stage}"
	completed_epoch="$(jq -er '.completed_at_utc | fromdateiso8601' "${receipt}")" \
		|| die "Timestamp inválido en recibo ${stage}"
	now_epoch="$(date -u +%s)"
	((completed_epoch <= now_epoch + 300)) \
		|| die "Recibo ${stage} fechado en el futuro"
	((now_epoch - completed_epoch <= 86400)) \
		|| die "Recibo ${stage} vencido; repite su gate"
}

run_local_gate() {
	verify_artifact
	note "Ejecutando QA Docker/local de Akibara Business..."
	bash "${repo_root}/tools/akibara-business/qa.sh"
	note "Ejecutando regresión del carril Business-only..."
	bash "${repo_root}/ops/test-akibara-business-seo-promotion.sh"
	write_receipt "docker" "local-compose"
	note "PASS: gate Docker Business ${component_version}"
}

load_environment() {
	local config="$1"

	[[ -f "${config_library}" && ! -L "${config_library}" ]] \
		|| die "Falta la librería regular de configuración"
	# shellcheck source=ops/lib/akibara-promotion-config.sh
	source "${config_library}"
	akb_load_promotion_config "${config}" || die "Configuración de promoción rechazada"
	akb_select_promotion_environment production || die "Entorno productivo rechazado"
}

ssh_read() {
	local script="$1" command

	printf -v command 'AKB_WEBROOT=%q bash -s' "${AKB_WEBROOT}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" <<< "${script}"
}

remote_inventory() {
	local output="$1" script

	script='
set -Eeuo pipefail
plugins_root="${AKB_WEBROOT}/wp-content/plugins"
slug="akibara-business"
[[ -d "${plugins_root}/${slug}" && ! -L "${plugins_root}/${slug}" ]]
test -z "$(find "${plugins_root}/${slug}" ! -type d ! -type f -print -quit)"
cd "${plugins_root}"
find "${slug}" -type f -print | LC_ALL=C sort |
while IFS= read -r file; do sha256sum "${file}"; done'
	ssh_read "${script}" > "${output}.partial"
	[[ -s "${output}.partial" ]] || die "El inventario remoto quedó vacío"
	mv -- "${output}.partial" "${output}"
}

verify_remote_state() {
	local version="$1" expected_inventory="$2" evidence_path="$3"
	local expected_option="$4" script

	printf -v script '
set -Eeuo pipefail
wp --path="${AKB_WEBROOT}" core is-installed
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-business --field=version)" == %q ]]
wp --path="${AKB_WEBROOT}" plugin is-active akibara-business
[[ "$(wp --path="${AKB_WEBROOT}" option get akibara_business_version_installed)" == %q ]]
' "${version}" "${expected_option}"
	ssh_read "${script}"
	remote_inventory "${evidence_path}"
	cmp -s "${expected_inventory}" "${evidence_path}" \
		|| die "La instalación remota Business ${version} no coincide con su inventario exacto"
}

remote_artifact_gate() {
	local maintenance_state="$1" script

	case "${maintenance_state}" in
		absent|active) ;;
		*) die "Estado de mantenimiento inválido: ${maintenance_state}" ;;
	esac
	printf -v script '
set -Eeuo pipefail
plugin_root="${AKB_WEBROOT}/wp-content/plugins/akibara-business"
[[ -d "${plugin_root}" && ! -L "${plugin_root}" ]]
test -z "$(find "${plugin_root}" ! -type d ! -type f -print -quit)"
test -z "$(find "${plugin_root}" -type f \( \
  -name "*.bak" -o -name "*.bk" -o -name "*.orig" -o -name "*.rej" -o \
  -name "*.tmp" -o -name "*.temp" -o -name "*.dump" -o -name "*.backup" -o \
  -name "*.sql" -o -name "*.sqlite" -o -name "*.tar" -o -name "*.tgz" -o \
  -name "*.zip" -o -name "*~" -o -name ".DS_Store" -o -name "._*" \
\) -print -quit)"
for upgrade_dir in \
  "${AKB_WEBROOT}/wp-content/upgrade" \
  "${AKB_WEBROOT}/wp-content/upgrade-temp-backup"; do
  if [[ -e "${upgrade_dir}" ]]; then
    [[ -d "${upgrade_dir}" && ! -L "${upgrade_dir}" ]]
    test -z "$(find "${upgrade_dir}" -mindepth 1 -print -quit)"
  fi
done
test -z "$(find /tmp -maxdepth 1 -name "akibara-business-seo.*" -print -quit 2>/dev/null)"
if [[ %q == active ]]; then
  [[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
  printf "%%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${AKB_WEBROOT}/.maintenance"
else
  [[ ! -e "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
fi
echo "BUSINESS_SEO_REMOTE_ARTIFACT_GATE_PASS"
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

stream_code_backup() {
	local output="$1" script

	script='
set -Eeuo pipefail
root="${AKB_WEBROOT}/wp-content/plugins"
[[ -d "${root}/akibara-business" && ! -L "${root}/akibara-business" ]]
test -z "$(find "${root}/akibara-business" ! -type d ! -type f -print -quit)"
tar -C "${root}" -czf - akibara-business'
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
  wp --path="${AKB_WEBROOT}" eval "if (! class_exists(\"LiteSpeed\\\\Purge\")) { throw new RuntimeException(\"LiteSpeed Purge unavailable\"); } \\LiteSpeed\\Purge::purge_all(\"Business SEO promotion\");" >/dev/null
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

	probe="/?akb-business-seo-maintenance=$(date -u +%s)-$$"
	headers="$(mktemp "${TMPDIR:-/tmp}/akibara-business-seo-maintenance.XXXXXX")"
	trap 'case "${headers:-}" in */akibara-business-seo-maintenance.*) rm -f -- "${headers}" ;; esac' RETURN
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
	local kind="$1" output="$2" php_file php_code script

	case "${kind}" in
		commerce) php_file="${commerce_fingerprint_php}" ;;
		search) php_file="${search_fingerprint_php}" ;;
		*) die "Fingerprint desconocido: ${kind}" ;;
	esac
	php_code="$(sed '1{/^<?php$/d;}' "${php_file}")"
	printf -v script 'set -Eeuo pipefail; wp --path="${AKB_WEBROOT}" eval %q' "${php_code}"
	ssh_read "${script}" > "${output}.partial"
	LC_ALL=C sort "${output}.partial" > "${output}"
	rm -f -- "${output}.partial"
	if [[ "${kind}" == "commerce" ]]; then
		validate_commerce_fingerprint "${output}"
	else
		validate_search_fingerprint "${output}"
	fi
	shasum -a 256 "${output}" > "${output}.sha256"
}

install_business() {
	local command script

	script='
set -Eeuo pipefail
remote_zip="$(mktemp --suffix=.zip /tmp/akibara-business-seo.XXXXXX)"
cleanup() { rm -f -- "${remote_zip}"; }
trap "cleanup || true" EXIT
trap "exit 129" HUP
trap "exit 130" INT
trap "exit 143" TERM
cat > "${remote_zip}"
[[ "$(sha256sum "${remote_zip}" | awk "{print \$1}")" == "${AKB_SHA}" ]]
wp --path="${AKB_WEBROOT}" plugin install "${remote_zip}" --force
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-business --field=version)" == "${AKB_VERSION}" ]]
wp --path="${AKB_WEBROOT}" plugin is-active akibara-business
cleanup
trap - EXIT HUP INT TERM
[[ ! -e "${remote_zip}" ]]'
	printf -v command 'AKB_WEBROOT=%q AKB_VERSION=%q AKB_SHA=%q bash -c %q' \
		"${AKB_WEBROOT}" "${component_version}" "${component_archive_sha}" "${script}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" < "${archive_path}"
}

restore_business() {
	local backup="$1" command script

	verify_regular_artifact "${backup}"
	verify_regular_artifact "${backup}.sha256"
	shasum -a 256 -c "${backup}.sha256" >/dev/null \
		|| die "Checksum del respaldo local inválido"
	tar -tzf "${backup}" >/dev/null
	script='
set -Eeuo pipefail
plugins_root="${AKB_WEBROOT}/wp-content/plugins"
target="${plugins_root}/akibara-business"
[[ -d "${plugins_root}" && ! -L "${plugins_root}" ]]
rm -rf -- "${target}"
tar -C "${plugins_root}" -xzf -
[[ -d "${target}" && ! -L "${target}" ]]
test -z "$(find "${target}" ! -type d ! -type f -print -quit)"
wp --path="${AKB_WEBROOT}" option update akibara_business_version_installed "${AKB_PREIMAGE_VERSION}" >/dev/null
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-business --field=version)" == "${AKB_PREIMAGE_VERSION}" ]]
wp --path="${AKB_WEBROOT}" plugin is-active akibara-business'
	printf -v command 'AKB_WEBROOT=%q AKB_PREIMAGE_VERSION=%q bash -c %q' \
		"${AKB_WEBROOT}" "${preimage_version}" "${script}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" < "${backup}"
}

robots_meta_tag() {
	local body="$1"
	grep -Eio '<meta[^>]+name=["'"'"']robots["'"'"'][^>]*>' "${body}" \
		| head -n 1 || true
}

robots_meta_has_directive() {
	local meta="$1" wanted="$2" content_pattern content directive

	content_pattern='content=["'\'']([^"'\'']*)["'\'']'
	[[ "${meta}" =~ ${content_pattern} ]] || return 1
	content="${BASH_REMATCH[1],,}"
	while IFS= read -r directive; do
		directive="${directive#"${directive%%[![:space:]]*}"}"
		directive="${directive%"${directive##*[![:space:]]}"}"
		[[ "${directive}" == "${wanted}" ]] && return 0
	done < <(tr ',' '\n' <<< "${content}")
	return 1
}

verify_production_robots_contract() {
	local meta="$1" request_kind="$2"

	case "${request_kind}" in
		mailpoet)
			robots_meta_has_directive "${meta}" noindex \
				&& robots_meta_has_directive "${meta}" follow \
				&& ! robots_meta_has_directive "${meta}" nofollow
			;;
		unrelated)
			robots_meta_has_directive "${meta}" index \
				&& ! robots_meta_has_directive "${meta}" noindex
			;;
		*)
			return 1
			;;
	esac
}

verify_http_semantics() {
	local evidence_dir="${1:-${active_evidence_dir}}"
	local probe_root body meta http_code

	[[ -d "${evidence_dir}" && ! -L "${evidence_dir}" ]] \
		|| die "Directorio local de evidencia HTTP ausente o inseguro"

	probe_root="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-seo-http.XXXXXX")"
	trap 'case "${probe_root:-}" in */akibara-business-seo-http.*) rm -rf -- "${probe_root}" ;; esac' RETURN
	[[ "${AKB_BASE_URL}" == "${PRODUCTION_BASE_URL}" ]] \
		|| die "La URL activa no corresponde a producción"

	for path in / /tienda/; do
		http_code="$(
			curl --silent --show-error --location \
				--proto '=https' --proto-redir '=https' \
				--connect-timeout 15 --max-time 45 \
				--retry 2 --retry-delay 2 --retry-all-errors \
				--output /dev/null --write-out '%{http_code}' \
				"${AKB_BASE_URL}${path}"
		)"
		[[ "${http_code}" == 200 ]] \
			|| die "HTTP esperaba 200 para ${path}; recibió ${http_code}"
	done

	body="${probe_root}/mailpoet.html"
	http_code="$(
		curl --silent --show-error --location \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 45 \
			--header 'Cache-Control: no-cache' \
			--output "${body}" --write-out '%{http_code}' \
			"${AKB_BASE_URL}/?mailpoet_page=subscriptions&akb_seo_probe=$(date -u +%s)-$$"
	)"
	[[ "${http_code}" == 200 ]] \
		|| die "MailPoet utility URL debe responder 200; recibió ${http_code}"
	meta="$(robots_meta_tag "${body}")"
	verify_production_robots_contract "${meta}" mailpoet \
		|| die "MailPoet utility URL incumple robots en producción"
	printf 'mailpoet\t200\t%s\n' "${meta}" \
		> "${evidence_dir}/http-semantics.tsv"

	body="${probe_root}/unrelated.html"
	http_code="$(
		curl --silent --show-error --location \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 45 \
			--header 'Cache-Control: no-cache' \
			--output "${body}" --write-out '%{http_code}' \
			"${AKB_BASE_URL}/?utm_source=akb-seo-release-probe"
	)"
	[[ "${http_code}" == 200 ]] \
		|| die "Query pública no relacionada debe responder 200; recibió ${http_code}"
	meta="$(robots_meta_tag "${body}")"
	verify_production_robots_contract "${meta}" unrelated \
		|| die "La query pública no relacionada incumple robots en producción"
	printf 'unrelated\t200\t%s\n' "${meta}" \
		>> "${evidence_dir}/http-semantics.tsv"
	shasum -a 256 "${evidence_dir}/http-semantics.tsv" \
		> "${evidence_dir}/http-semantics.tsv.sha256"

	trap - RETURN
	rm -rf -- "${probe_root}"
}

verify_installed_option() {
	local script

	printf -v script '
set -Eeuo pipefail
[[ "$(wp --path="${AKB_WEBROOT}" option get akibara_business_version_installed)" == %q ]]
' "${component_version}"
	ssh_read "${script}"
}

cleanup_on_failure() {
	local exit_status="$1" rollback_safe=1
	local rollback_inventory="${active_evidence_dir}/remote-after-rollback.files.sha256"

	((exit_status != 0)) || return 0
	set +e
	if ((mutation_started)); then
		note "Fallo tras iniciar mutación: restaurando solo Akibara Business desde el respaldo local."
		if ! ensure_maintenance; then
			rollback_safe=0
		else
			maintenance_active=1
		fi
		if ((rollback_safe)) && ! restore_business "${code_backup}"; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && ! purge_caches_under_maintenance; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && ! verify_remote_state \
			"${preimage_version}" "${preimage_inventory_path}" \
			"${rollback_inventory}" "${preimage_version}"; then
			rollback_safe=0
		fi
		if ((rollback_safe)) && [[ -f "${active_evidence_dir}/commerce-before.tsv" ]]; then
			capture_fingerprint commerce "${active_evidence_dir}/commerce-after-rollback.tsv" \
				|| rollback_safe=0
			cmp -s \
				"${active_evidence_dir}/commerce-before.tsv" \
				"${active_evidence_dir}/commerce-after-rollback.tsv" \
				|| rollback_safe=0
		fi
		if ((rollback_safe)) && [[ -f "${active_evidence_dir}/search-before.tsv" ]]; then
			capture_fingerprint search "${active_evidence_dir}/search-after-rollback.tsv" \
				|| rollback_safe=0
			cmp -s \
				"${active_evidence_dir}/search-before.tsv" \
				"${active_evidence_dir}/search-after-rollback.tsv" \
				|| rollback_safe=0
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
SHA ZIP: ${component_archive_sha}
Destino: production
Host:    ${AKB_HOST}
Webroot: ${AKB_WEBROOT}
URL:     ${AKB_BASE_URL}

Secuencia fail-closed:
  1. Verificar ZIP, sidecars, 210 rutas y delta exacto de dos archivos.
  2. Exigir recibo Docker ligado a artefacto, contrato, pipeline y suite.
  3. Exigir preimagen remota Business 1.95.2 e inventario exacto.
  4. Respaldar únicamente el árbol Business por streaming a local.
  5. Activar mantenimiento y comprobar HTTP 503.
  6. Capturar fingerprints de comercio y Search.
  7. Instalar únicamente akibara-business 1.95.3 con ZIP mktemp remoto.
  8. Exigir inventario final, fingerprints intactos y cero temporales.
  9. Reabrir, purgar cachés y validar MailPoet 200 + noindex,follow.

No se empaqueta ni instala Search, Checkout o themes. No se leen ni escriben
robots u opciones de Rank Math.
EOF
}

promote_environment() {
	local stamp target_inventory

	verify_artifact
	verify_receipt docker "local-compose"
	for command_name in cmp curl find grep jq sed shasum ssh tar; do
		require_command "${command_name}"
	done

	stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"
	active_backup_dir="${repo_root}/.local-backups/business-seo-promotion/production/${stamp}-${component_archive_sha}"
	active_evidence_dir="${active_backup_dir}/evidence"
	mkdir -p "${active_backup_dir}/code" "${active_evidence_dir}"
	chmod 0700 "${active_backup_dir}"
	code_backup="${active_backup_dir}/code/akibara-business-before.tar.gz"
	target_inventory="${active_evidence_dir}/remote-preimage.files.sha256"

	trap promotion_exit_handler EXIT
	trap 'promotion_signal_handler 129' HUP
	trap 'promotion_signal_handler 130' INT
	trap 'promotion_signal_handler 143' TERM

	verify_remote_state \
		"${preimage_version}" "${preimage_inventory_path}" \
		"${target_inventory}" "${preimage_version}"
	remote_artifact_gate absent \
		| tee "${active_evidence_dir}/remote-artifact-gate-before.txt"
	stream_code_backup "${code_backup}"
	activate_maintenance \
		| tee "${active_evidence_dir}/maintenance-active.txt"
	maintenance_active=1
	purge_caches_under_maintenance \
		| tee "${active_evidence_dir}/caches-under-maintenance-before.txt"
	maintenance_http_gate \
		| tee "${active_evidence_dir}/maintenance-http-before.txt"
	capture_fingerprint commerce "${active_evidence_dir}/commerce-before.tsv"
	capture_fingerprint search "${active_evidence_dir}/search-before.tsv"

	mutation_started=1
	install_business
	verify_maintenance \
		| tee "${active_evidence_dir}/maintenance-after-install.txt"
	cleanup_empty_upgrade_dirs \
		| tee "${active_evidence_dir}/upgrade-cleanup-after-install.txt"
	verify_remote_state \
		"${component_version}" "${inventory_path}" \
		"${active_evidence_dir}/remote-final.files.sha256" "${component_version}"
	capture_fingerprint commerce "${active_evidence_dir}/commerce-after.tsv"
	capture_fingerprint search "${active_evidence_dir}/search-after.tsv"
	cmp -s \
		"${active_evidence_dir}/commerce-before.tsv" \
		"${active_evidence_dir}/commerce-after.tsv" \
		|| die "El estado comercial cambió durante el release Business-only"
	cmp -s \
		"${active_evidence_dir}/search-before.tsv" \
		"${active_evidence_dir}/search-after.tsv" \
		|| die "El estado o índice Search cambió durante el release Business-only"
	remote_artifact_gate active \
		| tee "${active_evidence_dir}/remote-artifact-gate-under-maintenance.txt"
	purge_caches_under_maintenance \
		| tee "${active_evidence_dir}/caches-under-maintenance-after.txt"
	maintenance_http_gate \
		| tee "${active_evidence_dir}/maintenance-http-after.txt"
	deactivate_maintenance \
		| tee "${active_evidence_dir}/maintenance-inactive.txt"
	maintenance_active=0
	purge_caches_live \
		| tee "${active_evidence_dir}/caches-live-after.txt"
	verify_http_semantics
	verify_installed_option
	remote_artifact_gate absent \
		| tee "${active_evidence_dir}/remote-artifact-gate-final.txt"
	shasum -a 256 "${active_evidence_dir}/remote-artifact-gate-final.txt" \
		> "${active_evidence_dir}/remote-artifact-gate-final.txt.sha256"

	mutation_started=0
	trap - EXIT HUP INT TERM
	write_receipt production "${AKB_HOST}:${AKB_WEBROOT}"
	note "PASS: promoción production Business ${component_version}; evidencia local: ${active_backup_dir}"
}

load_contract

if [[ "${AKB_BUSINESS_SEO_PROMOTION_LIBRARY_ONLY:-0}" == 1 ]]; then
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
		load_environment "${config_file}"
		render_plan
		;;
	promote)
		[[ -n "${config_file}" && "${apply}" == 1 ]] \
			|| die "promote requiere --config FILE y --apply"
		[[ "${confirm}" == "${release_id}" ]] \
			|| die "Confirmación exacta requerida: ${release_id}"
		load_environment "${config_file}"
		promote_environment
		;;
	*)
		die "Comando desconocido: ${command_name}"
		;;
esac
