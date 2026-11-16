#!/usr/bin/env bash

# Rail local y fail-closed de backup, restore y ciclo pareado
# Search 0.1.14 + Business 1.99.16 -> Search 0.1.12 + Business 1.99.10
# -> Search 0.1.14 + Business 1.99.16. Nunca publica, no usa puertos, no
# ejecuta wp cron ni drena grupos.

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "${script_dir}/../.." && pwd -P)"
compose_file="${repo_root}/compose.yaml"
search_contract="${repo_root}/ops/contracts/akibara-search-0.1.14.json"
business_contract="${repo_root}/ops/contracts/akibara-business-1.99.16.json"
theme_contract="${repo_root}/ops/contracts/akibara-gp-2.1.70.json"
packager_library="${script_dir}/package-search-0.1.14-production.sh"
runtime_packager="${script_dir}/package-runtime.sh"
proof_php="${script_dir}/search-0.1.14-recovery-proof.php"
http_smoke_php="${script_dir}/search-0.1.14-http-smoke.php"
protected_sql="${script_dir}/search-0.1.14-protected-fingerprint.sql"
check_table_validator="${script_dir}/search-0.1.14-check-table.awk"
static_test="${script_dir}/test-search-0.1.14-recovery-cycle.sh"
theme_release_test="${script_dir}/test-theme-2.1.70-production-release.sh"
recovery_runbook="${repo_root}/ops/akibara-search-0.1.14-recovery-runbook.md"
theme_phpunit_config="${repo_root}/tools/akibara-gp/phpunit.xml.dist"
theme_tests_root="${repo_root}/public_html/wp-content/themes/akibara-gp/tests"
ai_audit_engine="${repo_root}/tools/akibara-ai-discoverability-audit.mjs"
ai_audit_test="${repo_root}/tools/test-ai-discoverability-audit.mjs"
ai_audit_wrapper="${repo_root}/tools/akibara-ai-discoverability-audit.sh"
ai_audit_policy="${repo_root}/tools/lib/ai-discoverability-policy.mjs"
ai_audit_runbook="${repo_root}/ops/akibara-ai-discoverability-audit-runbook.md"
local_index_guard="${repo_root}/public_html/wp-content/mu-plugins/akibara-local-external-indexing-guard.php"
local_index_guard_test="${repo_root}/tools/test-local-external-indexing-guard.php"
integration_php="${repo_root}/public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php"

mariadb_image='mariadb@sha256:a794d9eb009e20de605858a11f32f63b4075cbd197c650436f0e3b457e4caed7'
wpcli_image='wordpress@sha256:909702ad806b574fafa50894808205724a2a78dc5a3e193c09c85f86322d89eb'
apache_image='wordpress@sha256:b65f6fb4d8b669974d4e8d6f204bc5a11d67f4877473ba5ee97af0efd39e3d19'
node_image='node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b'
source_database='akibara'
source_root_password='root_local'
expected_source_tables=91
audited_run_id='8dde2d65-6029-4062-89cf-7fdd5af90c77'
audited_state_sha256='b243ddb58271e4dac334ff5df18ca863b352b6119721f3d562c547897838fb0d'
expected_write_lock='akb-search:54399a31d020081ea10e6377f582136fba8c19cfd087f035ffe5b'
expected_install_lock='akb_search_install_60f82fb2a124572b9c0a8cc17f5632b4'
price_buckets_sha256='bbf68fbe0a982bd89e4bc0d1c2e011779029484e88f6a0d926b8f8b8fd4fb7c3'
price_pair_preimage_snapshot_sha256='0df1d76c5d89f28087d4595fa4e64117ce526c39a97ccba23de7581cc9a719fc'
price_pair_normalized_snapshot_sha256='2c75951b6f1df45cd8e2eb7fd5e0ffae9fc8586df4b160406fbb6ee903825a05'
protected_candidate_snapshot_sha256='2663d2144a094779535360d489a1f868f67d386a6a0d86e415637cd37472315a'
protected_rollback_snapshot_sha256='5197e7307fcfe75676c76f26b6ef3bc3f9e7339975594216c2879c716f080c5c'

command_name='plan'
backup_dir="${repo_root}/.local-backups/search-0.1.14-recovery"
expected_run_id=''
expected_state_sha256=''
source_db_container=''
run_dir=''
run_label=''
run_nonce=''
backup_archive=''
backup_compressed_sha=''
backup_decompressed_sha=''
source_protected=''
source_price_pair=''
source_schema=''
rollback_protected_expected=''
active_network=''
active_db_container=''
lock_dir=''
source_final_verification=''
release_binding_initial=''
theme_candidate_tree=''
theme_rollback_tree=''
theme_test_tree=''
run_success=0
partial_files=()

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

note() {
	printf '%s\n' "$*"
}

usage() {
	cat <<'EOF'
Uso:
  bash tools/akibara-releases/search-0.1.14-recovery-cycle.sh plan
  bash tools/akibara-releases/search-0.1.14-recovery-cycle.sh run \
    --expected-run-id UUID \
    --expected-state-sha256 SHA256 \
    [--backup-dir RUTA_LOCAL]

plan no invoca Docker ni escribe archivos. run exige el par Search 0.1.14 +
Business 1.99.16 sellado, sus preimágenes Search 0.1.12 + Business 1.99.10,
WordPress/cron detenidos, DB local healthy, cero conexiones ajenas y ambos
named locks libres. Todo backup/evidencia queda sólo bajo .local-backups; la red
efímera es internal y no publica puertos.
EOF
}

sha256_file() {
	shasum -a 256 "$1" | awk '{print $1}'
}

sha256_stdin() {
	shasum -a 256 | awk '{print $1}'
}

assert_mode_600() {
	local path="$1" mode
	mode="$(python3 - "${path}" <<'PY'
import os
import stat
import sys

print(oct(stat.S_IMODE(os.stat(sys.argv[1]).st_mode))[2:])
PY
)"
	[[ "${mode}" == 600 ]] || die "Modo inseguro para ${path}: ${mode}"
}

assert_sha256() {
	[[ "$1" =~ ^[a-f0-9]{64}$ ]] || die "SHA-256 inválido: $1"
}

assert_uuid() {
	[[ "$1" =~ ^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$ ]] \
		|| die "UUID v4 inválido: $1"
}

assert_no_symlink_ancestors() {
	python3 - "$1" <<'PY'
import os
import pathlib
import stat
import sys

path = pathlib.Path(os.path.abspath(sys.argv[1]))
current = pathlib.Path(path.anchor)
missing = False
for part in path.parts[1:]:
    current /= part
    if missing:
        continue
    try:
        mode = os.lstat(current).st_mode
    except FileNotFoundError:
        missing = True
        continue
    if stat.S_ISLNK(mode):
        raise SystemExit(f"padre simbólico rechazado: {current}")
    if current != path and not stat.S_ISDIR(mode):
        raise SystemExit(f"padre no-directorio rechazado: {current}")
PY
}

repo_file() {
	local relative="$1" parent resolved
	[[ -n "${relative}" && "${relative}" != /* && "${relative}" != *'..'* \
		&& "${relative}" != *'//'* && "${relative}" != *'/./'* ]] \
		|| die "Ruta contractual insegura: ${relative}"
	parent="${repo_root}/$(dirname -- "${relative}")"
	assert_no_symlink_ancestors "${parent}" || die "Padre contractual simbólico: ${relative}"
	[[ -d "${parent}" && ! -L "${parent}" ]] || die "Padre contractual ausente: ${relative}"
	resolved="$(cd -- "${parent}" && printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${relative}")")"
	[[ "${resolved}" == "${repo_root}/"* ]] || die "Ruta contractual fuera del repo: ${relative}"
	printf '%s\n' "${resolved}"
}

release_binding_entry() {
	local label="$1" path="$2"
	[[ "${label}" =~ ^[a-z0-9_]+$ ]] || die "Label de binding inválido: ${label}"
	[[ -f "${path}" && ! -L "${path}" && -s "${path}" ]] \
		|| die "Archivo de binding inválido: ${label}"
	printf 'FILE\t%s\t%s\n' "${label}" "$(sha256_file "${path}")"
}

capture_release_binding() {
	local output="$1" relative binding_spec label remainder jq_path contract_kind
	{
		printf 'AKB_SEARCH_RELEASE_BINDING_V1\n'
		release_binding_entry rail "${BASH_SOURCE[0]}"
		release_binding_entry search_contract "${search_contract}"
		release_binding_entry business_contract "${business_contract}"
		release_binding_entry theme_contract "${theme_contract}"
		release_binding_entry compose "${compose_file}"
		release_binding_entry packager_library "${packager_library}"
		release_binding_entry runtime_packager "${runtime_packager}"
		release_binding_entry recovery_proof "${proof_php}"
		release_binding_entry http_smoke "${http_smoke_php}"
		release_binding_entry protected_fingerprint "${protected_sql}"
		release_binding_entry check_table_validator "${check_table_validator}"
		release_binding_entry static_test "${static_test}"
		release_binding_entry theme_release_test "${theme_release_test}"
		release_binding_entry theme_phpunit_config "${theme_phpunit_config}"
		release_binding_entry ai_audit_engine "${ai_audit_engine}"
		release_binding_entry ai_audit_test "${ai_audit_test}"
		release_binding_entry ai_audit_wrapper "${ai_audit_wrapper}"
		release_binding_entry ai_audit_policy "${ai_audit_policy}"
		release_binding_entry ai_audit_runbook "${ai_audit_runbook}"
		release_binding_entry runbook "${recovery_runbook}"
		release_binding_entry local_index_guard "${local_index_guard}"
		release_binding_entry local_index_guard_test "${local_index_guard_test}"
		release_binding_entry integrated_mariadb "${integration_php}"

		for binding_spec in \
			'search_preimage_archive|.components.search.preimage.archive|search' \
			'search_preimage_inventory|.components.search.preimage.inventory|search' \
			'search_candidate_archive|.components.search.candidate.archive|search' \
			'search_candidate_inventory|.components.search.candidate.inventory|search' \
			'business_preimage_archive|.components.business.preimage.archive|business' \
			'business_preimage_inventory|.components.business.preimage.inventory|business' \
			'business_candidate_archive|.components.business.candidate.archive|business' \
			'business_candidate_inventory|.components.business.candidate.inventory|business' \
			'theme_preimage_archive|.component.preimage.archive|theme' \
			'theme_preimage_inventory|.component.preimage.inventory|theme' \
			'theme_preimage_sidecar|.component.preimage.checksum_sidecar|theme' \
			'theme_candidate_archive|.component.candidate.archive|theme' \
			'theme_candidate_inventory|.component.candidate.inventory|theme' \
			'theme_candidate_sidecar|.component.candidate.checksum_sidecar|theme'; do
			label="${binding_spec%%|*}"
			remainder="${binding_spec#*|}"
			jq_path="${remainder%%|*}"
			contract_kind="${remainder##*|}"
			case "${contract_kind}" in
				search) relative="$(jq -er "${jq_path}" "${search_contract}")" ;;
				business) relative="$(jq -er "${jq_path}" "${business_contract}")" ;;
				theme) relative="$(jq -er "${jq_path}" "${theme_contract}")" ;;
				*) die "Contrato de binding desconocido: ${contract_kind}" ;;
			esac
			release_binding_entry "${label}" "$(repo_file "${relative}")"
		done
		printf 'END\tAKB_SEARCH_RELEASE_BINDING_V1\n'
	} > "${output}"
	chmod 600 "${output}"
	[[ "$(grep -c $'^FILE\t' "${output}" || true)" == 37 ]] \
		|| die 'Binding inmutable no contiene exactamente 23 evidencias y 14 artefactos'
	[[ "$(awk 'END { print NR }' "${output}")" == 39 ]] \
		|| die 'Binding inmutable no contiene exactamente header, 37 archivos y footer'
	grep -qx $'END\tAKB_SEARCH_RELEASE_BINDING_V1' "${output}" \
		|| die 'Binding inmutable de release incompleto'
}

assert_release_binding_unchanged() {
	local phase="$1" current
	[[ -n "${release_binding_initial}" && -f "${release_binding_initial}" ]] \
		|| die 'Falta binding inmutable inicial de release'
	current="$(mktemp "${run_dir}/release-binding-current.XXXXXX.partial")"
	partial_files+=("${current}")
	capture_release_binding "${current}"
	cmp -s "${release_binding_initial}" "${current}" \
		|| die "Contrato/evidencia/artefactos cambiaron durante el rail: ${phase}"
	rm -f -- "${current}"
}

assert_local_backup_dir() {
	local requested="$1" parent resolved_parent resolved local_root
	[[ "${requested}" == /* ]] || requested="${repo_root}/${requested}"
	parent="$(dirname -- "${requested}")"
	assert_no_symlink_ancestors "${parent}" || die 'Ruta local de backup cruza symlink'
	[[ -d "${parent}" && ! -L "${parent}" ]] || die 'Padre local de backup ausente o inseguro'
	resolved_parent="$(cd -- "${parent}" && pwd -P)"
	local_root="$(cd -- "${repo_root}/.local-backups" && pwd -P)"
	[[ "${resolved_parent}" == "${local_root}" || "${resolved_parent}" == "${local_root}/"* ]] \
		|| die 'Backup debe vivir bajo .local-backups del checkout'
	[[ ! -L "${requested}" ]] || die 'Directorio local de backup no puede ser symlink'
	mkdir -p -- "${requested}"
	chmod 700 "${requested}"
	resolved="$(cd -- "${requested}" && pwd -P)"
	[[ "${resolved}" == "${local_root}" || "${resolved}" == "${local_root}/"* ]] \
		|| die 'Backup debe vivir bajo .local-backups del checkout'
	[[ -d "${resolved}" && ! -L "${resolved}" ]] || die 'Directorio local de backup inseguro'
	backup_dir="${resolved}"
}

cleanup_iteration() {
	local strict="${1:-0}" container_id remaining network_listing cleanup_failed=0 network_to_check="${active_network}"
	set +e
	if [[ -n "${run_label}" && "${run_label}" =~ ^search-0\.1\.14-[0-9]+-[a-f0-9]{12}$ ]]; then
		while IFS= read -r container_id; do
			[[ "${container_id}" =~ ^[a-f0-9]{12,64}$ ]] || continue
			docker rm -f -- "${container_id}" >/dev/null 2>&1 || cleanup_failed=1
		done < <(docker ps -aq --filter "label=akibara.recovery.run=${run_label}" 2>/dev/null)
	fi
	if [[ -n "${active_db_container}" && "${active_db_container}" =~ ^akb-srch-0114-[a-z0-9-]+$ ]] \
		&& docker inspect "${active_db_container}" >/dev/null 2>&1; then
		docker rm -f -- "${active_db_container}" >/dev/null 2>&1 || cleanup_failed=1
	fi
	if [[ -n "${network_to_check}" && "${network_to_check}" =~ ^akb-srch-0114-[a-z0-9-]+-net$ ]] \
		&& docker network inspect "${network_to_check}" >/dev/null 2>&1; then
		docker network rm -- "${network_to_check}" >/dev/null 2>&1 || cleanup_failed=1
	fi
	if [[ "${strict}" == 1 ]]; then
		remaining="$(docker ps -aq --filter "label=akibara.recovery.run=${run_label}" 2>/dev/null)" \
			|| cleanup_failed=1
		[[ -z "${remaining}" ]] || cleanup_failed=1
		if [[ -n "${network_to_check}" ]]; then
			network_listing="$(docker network ls --filter "name=${network_to_check}" --format '{{.Name}}' 2>/dev/null)" \
				|| cleanup_failed=1
			if grep -Fxq -- "${network_to_check}" <<< "${network_listing}"; then
				cleanup_failed=1
			fi
		fi
	fi
	set -e
	if [[ "${strict}" == 1 && "${cleanup_failed}" != 0 ]]; then
		die 'No se pudieron retirar/verificar todos los artefactos Docker efímeros'
	fi
	active_db_container=''
	active_network=''
}

cleanup() {
	local partial
	trap - EXIT HUP INT TERM
	cleanup_iteration 0
	set +e
	for partial in "${partial_files[@]:-}"; do
		case "${partial}" in
			"${backup_dir}"/*.partial) rm -f -- "${partial}" ;;
		esac
	done
	if [[ "${run_success}" != 1 && -n "${run_dir}" && -n "${run_label}" \
		&& "${run_dir}" == "${backup_dir}/run-${run_label}."* \
		&& -d "${run_dir}" && ! -L "${run_dir}" ]]; then
		rm -f -- "${run_dir}/final-receipt.json" "${run_dir}/final-receipt.json.sha256"
	fi
	if [[ -n "${lock_dir}" && "${lock_dir}" == "${backup_dir}/.rail-lock" && -d "${lock_dir}" && ! -L "${lock_dir}" ]]; then
		rmdir -- "${lock_dir}" >/dev/null 2>&1
	fi
}

trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

while (($#)); do
	case "$1" in
		plan|run)
			command_name="$1"
			shift
			;;
		--backup-dir)
			(($# >= 2)) || die 'Falta valor para --backup-dir'
			backup_dir="$2"
			shift 2
			;;
		--expected-run-id)
			(($# >= 2)) || die 'Falta valor para --expected-run-id'
			expected_run_id="$2"
			shift 2
			;;
		--expected-state-sha256)
			(($# >= 2)) || die 'Falta valor para --expected-state-sha256'
			expected_state_sha256="$2"
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*) die "Argumento desconocido: $1" ;;
	esac
done

plan_release() {
	local search_seal='missing' business_seal='missing' theme_seal='missing' recovery_contract='missing' recovery_hashes='pending'
	if [[ -f "${search_contract}" && ! -L "${search_contract}" ]] && jq -e . "${search_contract}" >/dev/null 2>&1; then
		search_seal="$(jq -r '.seal_state // "missing"' "${search_contract}")"
		recovery_contract="$(jq -r 'if .production_invariants.recovery_rollback then "present" else "missing" end' "${search_contract}")"
		if jq -e '
          [
			.production_invariants.recovery_rollback.compose_file_sha256,
			.production_invariants.recovery_rollback.packager_library_sha256,
			.components.search.packager_sha256,
			.production_invariants.recovery_rollback.rail_sha256,
			.production_invariants.recovery_rollback.reviewed_recovery_php_sha256,
			.production_invariants.recovery_rollback.http_smoke.script_sha256,
			.production_invariants.recovery_rollback.integrated_mariadb.script_sha256,
			.production_invariants.recovery_rollback.protected_fingerprint_sql_sha256,
            .production_invariants.recovery_rollback.check_table_validator_sha256,
            .production_invariants.recovery_rollback.test_sha256,
            .production_invariants.recovery_rollback.runbook_sha256,
            .production_invariants.recovery_rollback.local_external_indexing.guard_sha256,
            .production_invariants.recovery_rollback.local_external_indexing.isolated_test_sha256
          ] | all(type == "string" and test("^[a-f0-9]{64}$"))
        ' "${search_contract}" >/dev/null 2>&1; then
			recovery_hashes='complete'
		fi
	fi
	if [[ -f "${business_contract}" && ! -L "${business_contract}" ]] && jq -e . "${business_contract}" >/dev/null 2>&1; then
		business_seal="$(jq -r '.seal_state // "missing"' "${business_contract}")"
	fi
	if [[ -f "${theme_contract}" && ! -L "${theme_contract}" ]] && jq -e . "${theme_contract}" >/dev/null 2>&1; then
		theme_seal="$(jq -r '.seal_state // "missing"' "${theme_contract}")"
	fi
	cat <<EOF
PRODUCTION_GATE=CLOSED
mode=plan-only-no-docker-no-writes
search_0_1_14_seal=${search_seal}
business_1_99_16_seal=${business_seal}
theme_2_1_70_seal=${theme_seal}
recovery_contract=${recovery_contract}
recovery_hashes=${recovery_hashes}
required_cycles=2
mariadb_image=${mariadb_image}
wpcli_image=${wpcli_image}
apache_image=${apache_image}
node_image=${node_image}
next=seal integrated Search 0.1.14 + Business 1.99.16 + Theme 2.1.70, then run with the audited run/state identifiers
EOF
}

if [[ "${command_name}" == plan ]]; then
	plan_release
	exit 0
fi

assert_uuid "${expected_run_id}"
assert_sha256 "${expected_state_sha256}"
[[ "${expected_run_id}" == "${audited_run_id}" ]] || die 'run_id no coincide con el preimage inmutable auditado'
[[ "${expected_state_sha256}" == "${audited_state_sha256}" ]] || die 'SHA raw no coincide con el preimage inmutable auditado'

for required_command in awk bash chmod cmp cp date docker find grep gzip jq mkdir mktemp mv node openssl php python3 rm rmdir sed seq shasum sleep tr unzip zipinfo; do
	command -v "${required_command}" >/dev/null 2>&1 || die "Falta comando requerido: ${required_command}"
done
for required_file in \
	"${compose_file}" \
	"${search_contract}" \
	"${business_contract}" \
	"${theme_contract}" \
	"${packager_library}" \
	"${runtime_packager}" \
	"${proof_php}" \
	"${http_smoke_php}" \
	"${protected_sql}" \
	"${check_table_validator}" \
	"${static_test}" \
	"${theme_release_test}" \
	"${theme_phpunit_config}" \
	"${recovery_runbook}" \
	"${ai_audit_engine}" \
	"${ai_audit_test}" \
	"${ai_audit_wrapper}" \
	"${ai_audit_policy}" \
	"${ai_audit_runbook}" \
	"${local_index_guard}" \
	"${local_index_guard_test}" \
	"${integration_php}"; do
	[[ -f "${required_file}" && ! -L "${required_file}" && -s "${required_file}" ]] \
		|| die "Falta archivo regular: ${required_file}"
done

assert_local_backup_dir "${backup_dir}"
lock_dir="${backup_dir}/.rail-lock"
mkdir -- "${lock_dir}" 2>/dev/null || die 'Otro rail recovery está activo'
chmod 700 "${lock_dir}"

validate_theme_quality_inputs() {
	local relative expected full
	while IFS=$'\t' read -r relative expected; do
		full="$(repo_file "${relative}")"
		[[ -f "${full}" && ! -L "${full}" && -s "${full}" ]] \
			|| die "Input de calidad Theme ausente o inseguro: ${relative}"
		[[ "$(sha256_file "${full}")" == "${expected}" ]] \
			|| die "Input de calidad Theme cambió tras el sello: ${relative}"
	done < <(jq -r '
	  [
	    [.quality_gates.static_contract.path,.quality_gates.static_contract.sha256],
	    [.quality_gates.phpunit_theme.config,.quality_gates.phpunit_theme.config_sha256],
	    (.quality_gates.node_theme.test_files[] | [.path,.sha256]),
	    (.quality_gates.phpunit_theme.test_files[] | [.path,.sha256])
	  ][] | @tsv
	' "${theme_contract}")
}

validate_contracts() {
	local compose_sha packager_sha runtime_packager_sha rail_sha proof_sha http_smoke_sha integration_sha protected_sha check_validator_sha test_sha runbook_sha guard_sha guard_test_sha business_contract_sha theme_contract_sha theme_test_sha theme_phpunit_config_sha
	local ai_engine_sha ai_test_sha ai_wrapper_sha ai_policy_sha ai_runbook_sha
	compose_sha="$(sha256_file "${compose_file}")"
	packager_sha="$(sha256_file "${packager_library}")"
	runtime_packager_sha="$(sha256_file "${runtime_packager}")"
	rail_sha="$(sha256_file "${BASH_SOURCE[0]}")"
	proof_sha="$(sha256_file "${proof_php}")"
	http_smoke_sha="$(sha256_file "${http_smoke_php}")"
	integration_sha="$(sha256_file "${integration_php}")"
	protected_sha="$(sha256_file "${protected_sql}")"
	check_validator_sha="$(sha256_file "${check_table_validator}")"
	test_sha="$(sha256_file "${static_test}")"
	runbook_sha="$(sha256_file "${recovery_runbook}")"
	guard_sha="$(sha256_file "${local_index_guard}")"
	guard_test_sha="$(sha256_file "${local_index_guard_test}")"
	business_contract_sha="$(sha256_file "${business_contract}")"
	theme_contract_sha="$(sha256_file "${theme_contract}")"
	theme_test_sha="$(sha256_file "${theme_release_test}")"
	theme_phpunit_config_sha="$(sha256_file "${theme_phpunit_config}")"
	ai_engine_sha="$(sha256_file "${ai_audit_engine}")"
	ai_test_sha="$(sha256_file "${ai_audit_test}")"
	ai_wrapper_sha="$(sha256_file "${ai_audit_wrapper}")"
	ai_policy_sha="$(sha256_file "${ai_audit_policy}")"
	ai_runbook_sha="$(sha256_file "${ai_audit_runbook}")"
	validate_theme_quality_inputs
	jq -e --arg run_id "${expected_run_id}" --arg state_sha "${expected_state_sha256}" \
		--arg mariadb "${mariadb_image}" --arg wpcli "${wpcli_image}" --arg apache "${apache_image}" --arg node "${node_image}" \
		--arg compose_sha "${compose_sha}" --arg packager_sha "${packager_sha}" --arg runtime_packager_sha "${runtime_packager_sha}" \
		--arg rail_sha "${rail_sha}" --arg proof_sha "${proof_sha}" \
		--arg http_smoke_sha "${http_smoke_sha}" --arg integration_sha "${integration_sha}" \
		--arg protected_sha "${protected_sha}" --arg test_sha "${test_sha}" \
		--arg check_validator_sha "${check_validator_sha}" \
		--arg runbook_sha "${runbook_sha}" --arg guard_sha "${guard_sha}" \
		--arg guard_test_sha "${guard_test_sha}" \
		--arg price_buckets_sha "${price_buckets_sha256}" \
		--arg price_pair_preimage_snapshot_sha "${price_pair_preimage_snapshot_sha256}" \
		--arg price_pair_normalized_snapshot_sha "${price_pair_normalized_snapshot_sha256}" \
		--arg protected_candidate_snapshot_sha "${protected_candidate_snapshot_sha256}" \
		--arg protected_rollback_snapshot_sha "${protected_rollback_snapshot_sha256}" \
		--arg business_contract_sha "${business_contract_sha}" \
		--arg theme_contract_sha "${theme_contract_sha}" \
		--arg ai_engine_sha "${ai_engine_sha}" --arg ai_test_sha "${ai_test_sha}" \
		--arg ai_wrapper_sha "${ai_wrapper_sha}" --arg ai_policy_sha "${ai_policy_sha}" \
		--arg ai_runbook_sha "${ai_runbook_sha}" \
		--slurpfile theme "${theme_contract}" '
      .seal_state == "sealed"
      and .release_id == "akibara-search-0.1.14"
      and .components.search.preimage.version == "0.1.12"
      and .components.search.candidate.version == "0.1.14"
      and .components.search.packager == "tools/akibara-releases/package-runtime.sh"
      and .components.search.packager_sha256 == $runtime_packager_sha
	  and .paired_release.required == true
	  and .paired_release.business_contract == "ops/contracts/akibara-business-1.99.16.json"
	  and .paired_release.business_contract_sha256 == $business_contract_sha
	  and .paired_release.candidate == {business_version:"1.99.16",search_version:"0.1.14"}
	  and .paired_release.rollback == {business_version:"1.99.10",search_version:"0.1.12"}
	  and .paired_release.compatibility_policy == "exact-pair-only"
	  and .paired_release.search_must_be_inactive_during_component_switch == true
	  and .paired_release.production_transition.forward == ["deactivate-search-in-wordpress-ui","deploy-business-1.99.16","deploy-search-0.1.14-while-inactive","verify-both-versions-while-search-inactive","activate-search-in-wordpress-ui"]
	  and .paired_release.production_transition.rollback == ["deactivate-search-in-wordpress-ui","deploy-business-1.99.10","deploy-search-0.1.12-while-inactive","verify-both-versions-while-search-inactive","activate-search-in-wordpress-ui"]
	  and .paired_release.production_transition.mixed_midpoints == {
	    rollback_after_business_deploy:{business_version:"1.99.10",search_version:"0.1.14",search_active:false},
	    forward_after_business_deploy:{business_version:"1.99.16",search_version:"0.1.12",search_active:false}
	  }
	  and .integrated_theme_binding == {
	    required:true,
	    theme_contract_path:"ops/contracts/akibara-gp-2.1.70.json",
	    theme_contract_sha256:$theme_contract_sha,
	    theme_release_id:"akibara-gp-2.1.70",
	    theme_version:"2.1.70",
	    theme_archive_sha256:$theme[0].component.candidate.archive_sha256,
	    theme_inventory_sha256:$theme[0].component.candidate.inventory_sha256,
	    theme_checksum_sidecar_sha256:$theme[0].component.candidate.checksum_sidecar_sha256,
	    theme_runtime_files:103,
	    theme_rollback_version:"2.1.69",
	    theme_rollback_archive_sha256:$theme[0].component.preimage.archive_sha256,
	    theme_rollback_inventory_sha256:$theme[0].component.preimage.inventory_sha256,
	    theme_rollback_checksum_sidecar_sha256:$theme[0].component.preimage.checksum_sidecar_sha256,
	    theme_rollback_runtime_files:103,
	    candidate_overlay_required:true,
	    http_smoke_active_theme_required:true,
	    node_tests_per_pass:31,
	    phpunit_tests_per_pass:18,
	    phpunit_assertions_per_pass:52,
	    required_passes:2
	  }
	  and .integrated_ai_audit == {
	    required:true,
	    schema_version:3,
	    engine_path:"tools/akibara-ai-discoverability-audit.mjs",
	    engine_sha256:$ai_engine_sha,
	    test_path:"tools/test-ai-discoverability-audit.mjs",
	    test_sha256:$ai_test_sha,
	    wrapper_path:"tools/akibara-ai-discoverability-audit.sh",
	    wrapper_sha256:$ai_wrapper_sha,
	    policy_path:"tools/lib/ai-discoverability-policy.mjs",
	    policy_sha256:$ai_policy_sha,
	    runbook_path:"ops/akibara-ai-discoverability-audit-runbook.md",
	    runbook_sha256:$ai_runbook_sha,
	    node_image:$node,
	    base_url:"https://akibara.cl/",
	    fetch_scope:"docker-internal-only",
	    redirect_policy:"manual_fail_closed",
	    network_internal:true,
	    published_ports:0,
	    external_network_allowed:false,
	    required_per_fresh_restore:true,
	    required_passes:2
	  }
      and .production_invariants.search_derived_data_fingerprint_may_change_after_verified_rebuild == true
	  and .production_invariants.search_protected_persistent_surfaces_must_match_phase_contract_exactly == true
      and .production_invariants.search_derived_price_pair_phase_transition_must_match_exactly == true
	  and .production_invariants.recovery_rollback.protected_surfaces_phase_exact == true
	  and .production_invariants.recovery_rollback.derived_price_pair_transition == {
	    protected_fingerprint_excluded:true,
	    options:["akb_search_precio_buckets","akb_search_precio_mode"],
	    normalization_source:"Akibara\\Search\\PriceBuckets::maybe_refresh_auto(false)",
		    preimage:{buckets_sha256:$price_buckets_sha,buckets_autoload:"off",mode:"absent-auto-semantics",snapshot_sha256:$price_pair_preimage_snapshot_sha},
		    normalized:{buckets_sha256:$price_buckets_sha,buckets_autoload:"no",mode:"auto",mode_autoload:"no",snapshot_sha256:$price_pair_normalized_snapshot_sha},
	    preimage_checkpoints:["source-before-dump","source-after-dump","source-final","restored","after-recovery"],
	    normalized_checkpoints:["first-v2","rollback-boot","rollback-v1","forward-migration","forward-v2","before-http","after-http","after-integrated-mariadb"],
	    v2_stored_equals_index_derivation:true,
		    rollback_v1_must_preserve_normalized_pair:true
		  }
		  and .production_invariants.recovery_rollback.business_search_feed_contract_transition == {
		    remains_in_protected_fingerprint:true,
		    option:"akibara_business_search_feed_contract_version",
		    option_id:1320696,
		    expected_row_count:1,
		    contract_source:"ops/contracts/akibara-business-1.99.16.json#search_feed_contract",
		    candidate:{business_version:"1.99.16",value:"2",autoload:"off",full_snapshot_sha256:$protected_candidate_snapshot_sha},
		    rollback:{business_version:"1.99.10",value:"1",autoload:"off",full_snapshot_sha256:$protected_rollback_snapshot_sha},
		    candidate_row:"OPTION\tH616B69626172615F627573696E6573735F7365617263685F666565645F636F6E74726163745F76657273696F6E\t1320696\tPRESENT\tH32\tH6F6666",
		    rollback_row:"OPTION\tH616B69626172615F627573696E6573735F7365617263685F666565645F636F6E74726163745F76657273696F6E\t1320696\tPRESENT\tH31\tH6F6666",
		    rollback_snapshot_derivation:"single-exact-row-substitution",
		    single_row_substitution_required:true,
		    preserved_columns:["option_name","option_id","presence","autoload"],
		    changed_column:"option_value",
		    source_checkpoints:["source-before-dump","source-after-dump","source-final"],
		    candidate_cycle_checkpoints:["restored","after-recovery","first-v2","forward-before-activation","forward-migration","forward-v2","before-http","after-http","after-integrated-mariadb"],
		    rollback_cycle_checkpoints:["rollback-after-business-switch","rollback-before-activation","rollback-v1"],
		    transition_exact_before_search_activation:true,
		    search_inactive_at_artifact_midpoints:true,
		    forward_restores_candidate_exactly:true
		  }
	  and .production_invariants.recovery_rollback.immutable_release_binding == {
	    initial_snapshot_required:true,
	    contracts_evidence_and_fourteen_artifacts_included:true,
	    check_before_and_after_each_cycle:true,
	    revalidate_contracts_after_cycles:true,
	    revalidate_contracts_after_source_freeze:true,
	    revalidate_contracts_before_final_receipt:true,
	    revalidate_contracts_after_final_sidecar:true,
	    final_receipt_must_bind_snapshot_sha256:true,
	    sidecar_rename_is_completion_marker:true,
	    incomplete_final_receipt_removed_on_failure:true
	  }
      and .production_invariants.recovery_rollback.rollback_forward_convergence == true
      and .production_invariants.recovery_rollback.fresh_restore_cycles == 2
	  and .production_invariants.recovery_rollback.sequence == ["0.1.14+1.99.16-recover-and-converge","deactivate-search-before-rollback","0.1.14+1.99.10-rollback-mixed-midpoint-search-inactive","0.1.12+1.99.10-rollback-and-converge","deactivate-search-before-forward","0.1.12+1.99.16-forward-mixed-midpoint-search-inactive","0.1.14+1.99.16-forward-and-converge"]
	  and .production_invariants.recovery_rollback.paired_component_switch == {
	    search_inactive_required:true,
	    deactivate_command:"wp plugin deactivate akibara-search",
	    activate_command:"wp plugin activate akibara-search",
	    midpoint_versions_must_be_verified_before_activation:true,
	    rollback_mixed_midpoint:{business_version:"1.99.10",search_version:"0.1.14",search_active:false},
	    rollback_ready_pair:{business_version:"1.99.10",search_version:"0.1.12",search_active:false},
	    forward_mixed_midpoint:{business_version:"1.99.16",search_version:"0.1.12",search_active:false},
	    forward_ready_pair:{business_version:"1.99.16",search_version:"0.1.14",search_active:false}
	  }
      and .production_invariants.recovery_rollback.compose_file == "compose.yaml"
      and .production_invariants.recovery_rollback.compose_file_sha256 == $compose_sha
      and .production_invariants.recovery_rollback.packager_library == "tools/akibara-releases/package-search-0.1.14-production.sh"
      and .production_invariants.recovery_rollback.packager_library_sha256 == $packager_sha
      and .production_invariants.recovery_rollback.rail == "tools/akibara-releases/search-0.1.14-recovery-cycle.sh"
      and .production_invariants.recovery_rollback.rail_sha256 == $rail_sha
	      and .production_invariants.recovery_rollback.reviewed_recovery_php == "tools/akibara-releases/search-0.1.14-recovery-proof.php"
	      and .production_invariants.recovery_rollback.reviewed_recovery_php_sha256 == $proof_sha
	      and .production_invariants.recovery_rollback.http_smoke == {
	        script:"tools/akibara-releases/search-0.1.14-http-smoke.php",
	        script_sha256:$http_smoke_sha,
	        receipt_schema:"akibara-search-http-smoke-v1",
	        network_internal:true,
	        published_ports:0,
	        host_header:"localhost:8080",
	        follow_redirects:false,
	        required_per_fresh_restore:true,
	        protected_surfaces_byte_identical:true
	      }
	      and .production_invariants.recovery_rollback.integrated_mariadb == {
	        script:"public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php",
	        script_sha256:$integration_sha,
	        modes:["baseline","candidate"],
	        required_per_fresh_restore:true,
	        provider_active_required:true,
	        reconciler_zero_divergence_required:true
	      }
      and .production_invariants.recovery_rollback.protected_fingerprint_sql == "tools/akibara-releases/search-0.1.14-protected-fingerprint.sql"
      and .production_invariants.recovery_rollback.protected_fingerprint_sql_sha256 == $protected_sha
      and .production_invariants.recovery_rollback.check_table_validator == "tools/akibara-releases/search-0.1.14-check-table.awk"
      and .production_invariants.recovery_rollback.check_table_validator_sha256 == $check_validator_sha
      and .production_invariants.recovery_rollback.test_script == "tools/akibara-releases/test-search-0.1.14-recovery-cycle.sh"
      and .production_invariants.recovery_rollback.test_sha256 == $test_sha
      and .production_invariants.recovery_rollback.runbook == "ops/akibara-search-0.1.14-recovery-runbook.md"
      and .production_invariants.recovery_rollback.runbook_sha256 == $runbook_sha
      and .production_invariants.recovery_rollback.preimage.run_id == $run_id
      and .production_invariants.recovery_rollback.preimage.raw_state_sha256 == $state_sha
      and .production_invariants.recovery_rollback.preimage.run_id == "8dde2d65-6029-4062-89cf-7fdd5af90c77"
      and .production_invariants.recovery_rollback.preimage.raw_state_sha256 == "b243ddb58271e4dac334ff5df18ca863b352b6119721f3d562c547897838fb0d"
      and .production_invariants.recovery_rollback.preimage.source_table_count == 91
      and .production_invariants.recovery_rollback.preimage.write_lock == "akb-search:54399a31d020081ea10e6377f582136fba8c19cfd087f035ffe5b"
      and .production_invariants.recovery_rollback.preimage.install_lock == "akb_search_install_60f82fb2a124572b9c0a8cc17f5632b4"
	      and .production_invariants.recovery_rollback.images.mariadb == $mariadb
	      and .production_invariants.recovery_rollback.images.wp_cli == $wpcli
	      and .production_invariants.recovery_rollback.images.apache == $apache
	      and .production_invariants.recovery_rollback.images.node == $node
      and .production_invariants.recovery_rollback.backup.location == "local-only"
      and .production_invariants.recovery_rollback.backup.remote_allowed == false
      and .production_invariants.recovery_rollback.backup.dump_flags == ["--single-transaction","--quick","--skip-lock-tables","--skip-comments","--hex-blob","--default-character-set=utf8mb4","--routines","--events","--triggers","--order-by-primary","--databases akibara"]
      and .production_invariants.recovery_rollback.restore.network_internal == true
      and .production_invariants.recovery_rollback.restore.published_ports == 0
      and .production_invariants.recovery_rollback.restore.boot_environment == ["MARIADB_ROOT_PASSWORD"]
      and .production_invariants.recovery_rollback.restore.boot_database_creation_allowed == false
      and .production_invariants.recovery_rollback.restore.canonical_redump_byte_identical == true
      and .production_invariants.recovery_rollback.local_external_indexing.environment == "local"
      and .production_invariants.recovery_rollback.local_external_indexing.network_external_dependency_allowed == false
      and .production_invariants.recovery_rollback.local_external_indexing.guard == "public_html/wp-content/mu-plugins/akibara-local-external-indexing-guard.php"
      and .production_invariants.recovery_rollback.local_external_indexing.guard_sha256 == $guard_sha
      and .production_invariants.recovery_rollback.local_external_indexing.isolated_test == "tools/test-local-external-indexing-guard.php"
      and .production_invariants.recovery_rollback.local_external_indexing.isolated_test_sha256 == $guard_test_sha
      and .production_invariants.recovery_rollback.local_external_indexing.rank_math_indexnow_log_must_remain_byte_identical == true
      and .production_invariants.recovery_rollback.recovery.expected_topology == "ready_swap"
      and .production_invariants.recovery_rollback.recovery.real_grace_seconds == 300
      and .production_invariants.recovery_rollback.scheduler.allowed_hooks == ["akb_search_rebuild_batch","akb_search_pump_live"]
      and .production_invariants.recovery_rollback.scheduler.wp_cron_allowed == false
      and .production_invariants.recovery_rollback.scheduler.group_drain_allowed == false
      and .production_invariants.recovery_rollback.scheduler.explicit_rebuild_cli_allowed == false
      and .production_invariants.recovery_rollback.scheduler.reconciler_fallback_allowed == false
      and .production_invariants.recovery_rollback.scheduler.active_state_without_pending_batch == "fail-closed"
	  and .production_invariants.recovery_rollback.scheduler.action_scheduler_runtime == {version:"3.9.3",store_class:"ActionScheduler_DBStore"}
	  and .production_invariants.recovery_rollback.scheduler.batch_identity_binding == ["state.run_id","action.args[0]","action.args[1]=baseline|repair","group=akibara-search","status=pending"]
	  and .production_invariants.recovery_rollback.scheduler.rollback_legacy_batch_identity_binding == ["search_version=0.1.12-only","state.run_id-present","action.args={offset:int>=0}","action.args.offset=state.offset","group=akibara-search","status=pending","claim_id=0","extended_args=null"]
	  and .production_invariants.recovery_rollback.scheduler.live_args_schedule_pairs == ["[]=>async-null","{barrier:editorial_discount}=>single-simple"]
	  and .production_invariants.recovery_rollback.scheduler.live_progress_max_rows_per_action == 25
	  and .production_invariants.recovery_rollback.scheduler.live_residual_pumps == "validated-explicit-noop-drain"
	  and .production_invariants.recovery_rollback.scheduler.max_live_pump_actions == 128
	  and .production_invariants.recovery_rollback.rollback_gate == {"search_version":"0.1.12","business_version":"1.99.10","db_version":"1.6.0","topology":"clean_pre","state_absent":true,"pending_absent":true,"docs_ids_equal_eligible_ids":true,"coverage_tolerance_allowed":false}
      and .production_invariants.recovery_rollback.forward_gate.search_version == "0.1.14"
	  and .production_invariants.recovery_rollback.forward_gate.business_version == "1.99.16"
      and .production_invariants.recovery_rollback.forward_gate.db_version == "1.7.0"
      and .production_invariants.recovery_rollback.forward_gate.schema_seal == "1.7.0-exact-1"
      and .production_invariants.recovery_rollback.forward_gate.topology == "clean_pre"
	  and .production_invariants.recovery_rollback.forward_gate.work_acked_equals_eligible == true
	  and .production_invariants.recovery_rollback.forward_gate.final_rebuild_receipt == {role:"historical",exact_shape:true,target_binding:"akb-search-<run_id>-<semantic_fingerprint>",history_exact_copy_required:true,immutable_editorial_run_receipt_required:true,current_live_generation_must_supersede:true}
    ' "${search_contract}" >/dev/null || die 'Contrato Search recovery no está sellado/exacto'

	jq -e '
      .seal_state == "sealed"
	  and .release_id == "akibara-business-1.99.16"
      and .components.business.slug == "akibara-business"
	  and .components.business.preimage.version == "1.99.10"
	  and .components.business.candidate.version == "1.99.16"
	  and .paired_release.search_contract == "ops/contracts/akibara-search-0.1.14.json"
	  and .paired_release.candidate == {business_version:"1.99.16",search_version:"0.1.14"}
	  and .paired_release.rollback == {business_version:"1.99.10",search_version:"0.1.12"}
	  and .paired_release.search_must_be_inactive_during_component_switch == true
	  and .paired_release.production_transition.forward == ["deactivate-search-in-wordpress-ui","deploy-business-1.99.16","deploy-search-0.1.14-while-inactive","verify-both-versions-while-search-inactive","activate-search-in-wordpress-ui"]
	  and .paired_release.production_transition.rollback == ["deactivate-search-in-wordpress-ui","deploy-business-1.99.10","deploy-search-0.1.12-while-inactive","verify-both-versions-while-search-inactive","activate-search-in-wordpress-ui"]
	  and .paired_release.production_transition.mixed_midpoints == {
	    rollback_after_business_deploy:{business_version:"1.99.10",search_version:"0.1.14",search_active:false},
	    forward_after_business_deploy:{business_version:"1.99.16",search_version:"0.1.12",search_active:false}
	  }
	      and .search_feed_contract.version == "2"
	      and .search_feed_contract.previous == "1"
	      and .search_feed_contract.forces_rebuild == true
		' "${business_contract}" >/dev/null || die 'Business 1.99.16 no está sellado con feed contract v2 y par exacto'

	jq -e --arg runtime_packager_sha "${runtime_packager_sha}" \
		--arg theme_test_sha "${theme_test_sha}" \
		--arg theme_phpunit_config_sha "${theme_phpunit_config_sha}" '
	  .schema_version == 1
	  and .contract == "akibara-gp-production-release"
	  and .release_id == "akibara-gp-2.1.70"
	  and .seal_state == "sealed"
	  and .promotion_sequence == ["docker-local-integrated","production-ui-integrated"]
	  and .exclusive_targets == ["public_html/wp-content/themes/akibara-gp"]
	  and .component.kind == "theme"
	  and .component.slug == "akibara-gp"
	  and .component.version == "2.1.70"
	  and .component.packager.path == "tools/akibara-releases/package-runtime.sh"
	  and .component.packager.sha256 == $runtime_packager_sha
	  and .component.packager.component == "theme"
	  and .component.preimage == {
	    version:"2.1.69",
	    archive:"dist/akibara-gp-2.1.69.zip",
	    archive_sha256:.component.preimage.archive_sha256,
	    checksum_sidecar:"dist/akibara-gp-2.1.69.zip.sha256",
	    checksum_sidecar_sha256:.component.preimage.checksum_sidecar_sha256,
	    inventory:"dist/akibara-gp-2.1.69.files.sha256",
	    inventory_sha256:.component.preimage.inventory_sha256,
	    runtime_files:103,
	    archive_policy:{root:"akibara-gp",entries:"regular-files-only",file_mode:"0644",compression:"stored",mtime_utc:"1980-01-01T00:00:00Z",order:"bytewise-ascending"}
	  }
	  and .component.candidate == {
	    version:"2.1.70",
	    archive:"dist/akibara-gp-2.1.70.zip",
	    archive_sha256:.component.candidate.archive_sha256,
	    checksum_sidecar:"dist/akibara-gp-2.1.70.zip.sha256",
	    checksum_sidecar_sha256:.component.candidate.checksum_sidecar_sha256,
	    inventory:"dist/akibara-gp-2.1.70.files.sha256",
	    inventory_sha256:.component.candidate.inventory_sha256,
	    runtime_files:103,
	    archive_policy:{root:"akibara-gp",entries:"regular-files-only",file_mode:"0644",compression:"stored",mtime_utc:"1980-01-01T00:00:00Z",order:"bytewise-ascending"}
	  }
	  and ([.component.preimage.archive_sha256,.component.preimage.inventory_sha256,
	        .component.preimage.checksum_sidecar_sha256,
	        .component.candidate.archive_sha256,.component.candidate.inventory_sha256,
	        .component.candidate.checksum_sidecar_sha256] |
	       all(type == "string" and test("^[a-f0-9]{64}$")))
	  and .quality_gates.static_contract == {
	    path:"tools/akibara-releases/test-theme-2.1.70-production-release.sh",
	    sha256:$theme_test_sha,
	    command:"bash tools/akibara-releases/test-theme-2.1.70-production-release.sh",
	    negative_mutations_required:10
	  }
	  and .quality_gates.node_theme.expected_tests == 31
	  and .quality_gates.node_theme.expected_failures == 0
	  and (.quality_gates.node_theme.test_files | length) == 12
	  and .quality_gates.phpunit_theme.config == "tools/akibara-gp/phpunit.xml.dist"
	  and .quality_gates.phpunit_theme.config_sha256 == $theme_phpunit_config_sha
	  and .quality_gates.phpunit_theme.expected_tests == 18
	  and .quality_gates.phpunit_theme.expected_assertions == 52
	  and (.quality_gates.phpunit_theme.test_files | length) == 6
	  and .integrated_release_binding.required == true
	  and .integrated_release_binding.theme_contract_path == "ops/contracts/akibara-gp-2.1.70.json"
	  and .integrated_release_binding.contract_reseal_required_after_any_pinned_input_change == true
	  and .production_invariants.deployment_transport == "mcp__hostinger_hosting__hosting_deployWordpressTheme"
	  and .production_invariants.remote_backups_allowed == false
	  and .production_invariants.rollback_artifacts_location == "local-only"
	  and .production_invariants.graphify_during_release_allowed == false
	' "${theme_contract}" >/dev/null || die 'Theme 2.1.70 no está sellado para el gate integrado'
}

validate_archive_inventory() {
	local archive="$1" inventory="$2" root="$3"
	(
		AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY=1
		export AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY
		# shellcheck source=tools/akibara-releases/package-search-0.1.14-production.sh
		source "${packager_library}"
		verify_archive_inventory "${archive}" "${inventory}" "${root}"
	)
}

validate_tree_inventory() {
	local parent="$1" inventory="$2" root="$3"
	(
		AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY=1
		export AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY
		# shellcheck source=tools/akibara-releases/package-search-0.1.14-production.sh
		source "${packager_library}"
		verify_exact_inventory_tree "${parent}" "${inventory}" "${root}"
	)
}

prepare_component_tree() {
	local contract="$1" component="$2" role="$3" version="$4" destination="$5"
	local archive_rel inventory_rel expected_archive_sha expected_inventory_sha archive inventory slug
	slug="$(jq -r --arg component "${component}" '.components[$component].slug' "${contract}")"
	archive_rel="$(jq -r --arg component "${component}" --arg role "${role}" '.components[$component][$role].archive' "${contract}")"
	inventory_rel="$(jq -r --arg component "${component}" --arg role "${role}" '.components[$component][$role].inventory' "${contract}")"
	expected_archive_sha="$(jq -r --arg component "${component}" --arg role "${role}" '.components[$component][$role].archive_sha256' "${contract}")"
	expected_inventory_sha="$(jq -r --arg component "${component}" --arg role "${role}" '.components[$component][$role].inventory_sha256' "${contract}")"
	[[ "$(jq -r --arg component "${component}" --arg role "${role}" '.components[$component][$role].version' "${contract}")" == "${version}" ]] \
		|| die "Versión contractual divergente para ${component}/${role}"
	assert_sha256 "${expected_archive_sha}"
	assert_sha256 "${expected_inventory_sha}"
	archive="$(repo_file "${archive_rel}")"
	inventory="$(repo_file "${inventory_rel}")"
	[[ -f "${archive}" && ! -L "${archive}" && -s "${archive}" ]] || die "ZIP ausente: ${archive_rel}"
	[[ -f "${inventory}" && ! -L "${inventory}" && -s "${inventory}" ]] || die "Inventario ausente: ${inventory_rel}"
	[[ "$(sha256_file "${archive}")" == "${expected_archive_sha}" ]] || die "Hash ZIP divergente: ${archive_rel}"
	[[ "$(sha256_file "${inventory}")" == "${expected_inventory_sha}" ]] || die "Hash inventario divergente: ${inventory_rel}"
	validate_archive_inventory "${archive}" "${inventory}" "${slug}"
	mkdir -p -- "${destination}"
	unzip -q "${archive}" -d "${destination}"
	validate_tree_inventory "${destination}" "${inventory}" "${slug}"
	# El run_dir es 0700, pero el subárbol montado debe ser legible/traversable
	# por el UID 33 del contenedor WP-CLI. Sigue siendo :ro dentro del runtime.
	find "${destination}/${slug}" -type d -exec chmod 755 {} +
	find "${destination}/${slug}" -type f -exec chmod 444 {} +
	validate_tree_inventory "${destination}" "${inventory}" "${slug}"
	case "${component}" in
		search)
			grep -Eq "^[[:space:]]*\\*[[:space:]]*Version:[[:space:]]*${version//./\\.}[[:space:]]*$" \
				"${destination}/${slug}/akibara-search.php" || die "Header Search ${version} inválido"
			;;
		business)
			grep -Eq "^[[:space:]]*\\*[[:space:]]*Version:[[:space:]]*${version//./\\.}[[:space:]]*$" \
				"${destination}/${slug}/akibara-business.php" || die "Header Business ${version} inválido"
			;;
	esac
	printf '%s\n' "${destination}/${slug}"
}

prepare_theme_tree() {
	local role="$1" version="$2" destination="$3"
	local archive_rel inventory_rel sidecar_rel expected_archive_sha expected_inventory_sha expected_sidecar_sha
	local archive inventory sidecar slug sidecar_line expected artifact
	[[ "${role}" == candidate || "${role}" == preimage ]] || die "Rol Theme inválido: ${role}"
	slug="$(jq -r '.component.slug' "${theme_contract}")"
	archive_rel="$(jq -r --arg role "${role}" '.component[$role].archive' "${theme_contract}")"
	inventory_rel="$(jq -r --arg role "${role}" '.component[$role].inventory' "${theme_contract}")"
	sidecar_rel="$(jq -r --arg role "${role}" '.component[$role].checksum_sidecar' "${theme_contract}")"
	expected_archive_sha="$(jq -r --arg role "${role}" '.component[$role].archive_sha256' "${theme_contract}")"
	expected_inventory_sha="$(jq -r --arg role "${role}" '.component[$role].inventory_sha256' "${theme_contract}")"
	expected_sidecar_sha="$(jq -r --arg role "${role}" '.component[$role].checksum_sidecar_sha256' "${theme_contract}")"
	[[ "$(jq -r --arg role "${role}" '.component[$role].version' "${theme_contract}")" == "${version}" ]] \
		|| die "Versión contractual divergente para Theme/${role}"
	for expected in "${expected_archive_sha}" "${expected_inventory_sha}" "${expected_sidecar_sha}"; do
		assert_sha256 "${expected}"
	done
	archive="$(repo_file "${archive_rel}")"
	inventory="$(repo_file "${inventory_rel}")"
	sidecar="$(repo_file "${sidecar_rel}")"
	for artifact in "${archive}" "${inventory}" "${sidecar}"; do
		[[ -f "${artifact}" && ! -L "${artifact}" && -s "${artifact}" ]] \
			|| die "Artefacto Theme ausente o inseguro: ${artifact}"
	done
	[[ "$(sha256_file "${archive}")" == "${expected_archive_sha}" ]] || die "Hash ZIP Theme divergente: ${role}"
	[[ "$(sha256_file "${inventory}")" == "${expected_inventory_sha}" ]] || die "Hash inventario Theme divergente: ${role}"
	[[ "$(sha256_file "${sidecar}")" == "${expected_sidecar_sha}" ]] || die "Hash sidecar Theme divergente: ${role}"
	sidecar_line="$(awk 'NF == 2 { print $1 " " $2 }' "${sidecar}")"
	[[ "${sidecar_line}" == "${expected_archive_sha} $(basename -- "${archive}")" ]] \
		|| die "Sidecar Theme no enlaza el ZIP exacto: ${role}"
	validate_archive_inventory "${archive}" "${inventory}" "${slug}"
	mkdir -p -- "${destination}"
	unzip -q "${archive}" -d "${destination}"
	validate_tree_inventory "${destination}" "${inventory}" "${slug}"
	find "${destination}/${slug}" -type d -exec chmod 755 {} +
	find "${destination}/${slug}" -type f -exec chmod 444 {} +
	validate_tree_inventory "${destination}" "${inventory}" "${slug}"
	grep -Eq "^[[:space:]]*Version:[[:space:]]*${version//./\\.}[[:space:]]*$" \
		"${destination}/${slug}/style.css" || die "Header Theme ${version} inválido"
	printf '%s\n' "${destination}/${slug}"
}

# El ZIP productivo excluye tests y debe permanecer byte-exacto. Docker no puede
# crear un segundo bind mount anidado bajo ese árbol :ro cuando no existe el
# directorio tests. Construimos por ello un harness local separado: primero
# copiamos y revalidamos los 103 archivos candidato, y recién después añadimos
# las 18 fuentes de prueba selladas. Este árbol jamás se monta en WordPress/HTTP.
prepare_theme_test_tree() {
	local destination="$1" runtime_tree inventory relative source copied
	[[ -n "${theme_candidate_tree}" && -d "${theme_candidate_tree}" && ! -L "${theme_candidate_tree}" ]] \
		|| die 'Árbol Theme candidato ausente para construir el harness'
	[[ ! -e "${destination}" && ! -L "${destination}" ]] \
		|| die 'Destino del harness Theme ya existe'
	mkdir -p -- "${destination}"
	cp -R -- "${theme_candidate_tree}" "${destination}/akibara-gp"
	runtime_tree="${destination}/akibara-gp"
	inventory="$(repo_file "$(jq -r '.component.candidate.inventory' "${theme_contract}")")"
	validate_tree_inventory "${destination}" "${inventory}" akibara-gp
	mkdir -p -- "${runtime_tree}/tests"
	cp -R -- "${theme_tests_root}/." "${runtime_tree}/tests/"
	[[ -z "$(find "${runtime_tree}" ! -type d ! -type f -print -quit)" ]] \
		|| die 'Harness Theme contiene enlaces o archivos especiales'
	while IFS= read -r relative; do
		[[ "${relative}" == public_html/wp-content/themes/akibara-gp/tests/* ]] \
			|| die "Test Theme fuera de la raíz contractual: ${relative}"
		source="${repo_root}/${relative}"
		copied="${runtime_tree}/tests/${relative##*/}"
		cmp -s "${source}" "${copied}" || die "Copia de test Theme divergente: ${relative}"
	done < <(jq -r '[.quality_gates.node_theme.test_files[].path,.quality_gates.phpunit_theme.test_files[].path] | sort | .[]' "${theme_contract}")
	[[ "$(find "${runtime_tree}/tests" -type f | wc -l | tr -d '[:space:]')" == 18 ]] \
		|| die 'Harness Theme no contiene exactamente 18 archivos de prueba'
	find "${runtime_tree}" -type d -exec chmod 755 {} +
	find "${runtime_tree}" -type f -exec chmod 444 {} +
	printf '%s\n' "${runtime_tree}"
}

main_root_query() {
	local sql="$1"
	docker exec -i -e MYSQL_PWD="${source_root_password}" "${source_db_container}" \
		mariadb --batch --raw --skip-column-names -uroot "${source_database}" --execute "${sql}"
}

ephemeral_root_query() {
	local root_password="$1" sql="$2"
	docker exec -i -e MYSQL_PWD="${root_password}" "${active_db_container}" \
		mariadb --batch --raw --skip-column-names -uroot "${source_database}" --execute "${sql}"
}

ephemeral_wp_query() {
	local wp_user="$1" wp_password="$2" sql="$3"
	docker exec -i -e MYSQL_PWD="${wp_password}" "${active_db_container}" \
		mariadb --batch --raw --skip-column-names -u"${wp_user}" "${source_database}" --execute "${sql}"
}

ephemeral_routine_privilege_count() {
	local root_password="$1" username="$2" host="$3"
	[[ "${username}" =~ ^[a-z0-9_]{1,32}$ && "${host}" == '%' ]] \
		|| die 'Identidad User/Host inválida para auditar rutinas'
	# MariaDB 11.4 no ofrece una vista I_S equivalente. mysql.procs_priv es
	# autoritativa para grants específicos de rutinas y se consulta como root.
	ephemeral_root_query "${root_password}" \
		"SELECT COUNT(*) FROM mysql.procs_priv WHERE User='${username}' AND Host='${host}';"
}

isolate_ephemeral_runtime_plugins() {
	local root_password="$1" expected_hex option_rows actual_hex
	# El restore conserva íntegra la fuente. Sólo en la DB efímera se reduce el
	# runtime a las dependencias del par bajo prueba, evitando que integraciones
	# de terceros ajenas al release conviertan el recovery en un boot no hermético.
	# Rank Math integra la allowlist porque el auditor IA integrado valida
	# superficies que ese plugin emite (grafo OnlineStore, sitemaps, llms.txt,
	# titles); sin él, el gate de descubribilidad fallaría por construcción.
	expected_hex="$(php -r 'echo bin2hex(serialize(array("akibara-business/akibara-business.php","akibara-search/akibara-search.php","seo-by-rank-math/rank-math.php","woocommerce/woocommerce.php")));')"
	[[ "${expected_hex}" =~ ^[a-f0-9]+$ ]] || die 'Allowlist activa efímera no se pudo serializar'
	option_rows="$(ephemeral_root_query "${root_password}" \
		"SELECT COUNT(*) FROM wp_options WHERE option_name='active_plugins';" | tr -d '[:space:]')"
	[[ "${option_rows}" == 1 ]] || die 'active_plugins efímero no es fila única'
	ephemeral_root_query "${root_password}" \
		"UPDATE wp_options SET option_value=UNHEX('${expected_hex}') WHERE option_name='active_plugins';" >/dev/null
	actual_hex="$(ephemeral_root_query "${root_password}" \
		"SELECT HEX(option_value) FROM wp_options WHERE option_name='active_plugins';" \
		| tr '[:upper:]' '[:lower:]' | tr -d '[:space:]')"
	[[ "${actual_hex}" == "${expected_hex}" ]] \
		|| die 'Allowlist activa efímera no persistió byte-exacta'
}

assert_source_freeze() {
	local health other_connections write_free install_free table_count non_innodb raw_state
	local wordpress_container cron_container service_container service_name service_status
	wordpress_container="$(docker compose -f "${compose_file}" ps -a -q wordpress)"
	cron_container="$(docker compose -f "${compose_file}" ps -a -q cron)"
	for service_name in wordpress cron; do
		if [[ "${service_name}" == wordpress ]]; then
			service_container="${wordpress_container}"
		else
			service_container="${cron_container}"
		fi
		[[ "${service_container}" =~ ^[a-f0-9]{12,64}$ ]] || die "Freeze inválido: no se resolvió ${service_name} único"
		[[ "$(docker inspect -f '{{index .Config.Labels "com.docker.compose.service"}}' "${service_container}")" == "${service_name}" ]] \
			|| die "Freeze inválido: container ${service_name} no corresponde al servicio"
		service_status="$(docker inspect -f '{{.State.Status}}' "${service_container}")"
		[[ "${service_status}" == exited ]] || die "Freeze inválido: ${service_name} está ${service_status}, no Exited"
	done
	source_db_container="$(docker compose -f "${compose_file}" ps -a -q db)"
	[[ "${source_db_container}" =~ ^[a-f0-9]{12,64}$ ]] || die 'No se resolvió el container DB principal'
	[[ "$(docker inspect -f '{{index .Config.Labels "com.docker.compose.service"}}' "${source_db_container}")" == db ]] \
		|| die 'Container fuente no pertenece al servicio db'
	health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "${source_db_container}")"
	[[ "$(docker inspect -f '{{.State.Status}}' "${source_db_container}")" == running ]] \
		|| die 'DB fuente no está running'
	[[ "${health}" == healthy ]] || die "DB fuente no está healthy: ${health}"
	other_connections="$(main_root_query "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB='${source_database}' AND ID <> CONNECTION_ID();" | tr -d '[:space:]')"
	[[ "${other_connections}" == 0 ]] || die "Hay ${other_connections} conexión(es) ajenas a ${source_database}"
	write_free="$(main_root_query "SELECT IS_FREE_LOCK('${expected_write_lock}');" | tr -d '[:space:]')"
	install_free="$(main_root_query "SELECT IS_FREE_LOCK('${expected_install_lock}');" | tr -d '[:space:]')"
	[[ "${write_free}" == 1 && "${install_free}" == 1 ]] || die 'Named lock de Search/install no está libre'
	table_count="$(main_root_query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${source_database}' AND TABLE_TYPE='BASE TABLE';" | tr -d '[:space:]')"
	non_innodb="$(main_root_query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${source_database}' AND TABLE_TYPE='BASE TABLE' AND UPPER(ENGINE) <> 'INNODB';" | tr -d '[:space:]')"
	[[ "${table_count}" == "${expected_source_tables}" ]] || die "Conteo exacto de tablas divergió: ${table_count}"
	[[ "${non_innodb}" == 0 ]] || die "Existen ${non_innodb} tablas no-InnoDB"
	raw_state="$(main_root_query "SELECT option_value FROM wp_options WHERE option_name='akb_search_rebuild_state' ORDER BY option_id;")"
	[[ -n "${raw_state}" && "$(printf '%s' "${raw_state}" | sha256_stdin)" == "${expected_state_sha256}" ]] \
		|| die 'Estado raw del preimage divergió'
	jq -e --arg run_id "${expected_run_id}" '
      .run_id == $run_id and .protocol == 2 and .phase == "BLOCKED"
      and .error == "legacy_or_unreadable_state" and .topology == "ready_swap"
    ' <<< "${raw_state}" >/dev/null || die 'Estado JSON del preimage divergió'
}

capture_protected_root() {
	local container="$1" password="$2" output="$3"
	docker exec -i -e MYSQL_PWD="${password}" "${container}" \
		mariadb --batch --raw --skip-column-names -uroot "${source_database}" \
		< "${protected_sql}" > "${output}"
	chmod 600 "${output}"
	grep -qx $'END\tAKB_SEARCH_PROTECTED_FINGERPRINT_V2' "${output}" \
		|| die 'Fingerprint protegido incompleto'
}

derive_rollback_protected_snapshot() {
	local candidate="$1" output="$2"
	local candidate_row rollback_row
	candidate_row=$'OPTION\tH616B69626172615F627573696E6573735F7365617263685F666565645F636F6E74726163745F76657273696F6E\t1320696\tPRESENT\tH32\tH6F6666'
	rollback_row=$'OPTION\tH616B69626172615F627573696E6573735F7365617263685F666565645F636F6E74726163745F76657273696F6E\t1320696\tPRESENT\tH31\tH6F6666'
	[[ "$(sha256_file "${candidate}")" == "${protected_candidate_snapshot_sha256}" ]] \
		|| die 'Fingerprint protegido candidato no coincide con el preimage auditado'
	awk -v candidate_row="${candidate_row}" -v rollback_row="${rollback_row}" '
	  $0 == candidate_row { candidate_count++; print rollback_row; next }
	  $0 == rollback_row { rollback_count++ }
	  { print }
	  END { if (candidate_count != 1 || rollback_count != 0) exit 1 }
	' "${candidate}" > "${output}" \
		|| die 'No se pudo derivar rollback mediante una única sustitución exacta 2 -> 1'
	chmod 600 "${output}"
	[[ "$(sha256_file "${output}")" == "${protected_rollback_snapshot_sha256}" ]] \
		|| die 'Fingerprint protegido rollback derivado no coincide con el auditado'
}

assert_protected_phase_file() {
	local input="$1" phase="$2" reference expected_sha
	case "${phase}" in
		candidate)
			reference="${source_protected}"
			expected_sha="${protected_candidate_snapshot_sha256}"
			;;
		rollback)
			reference="${rollback_protected_expected}"
			expected_sha="${protected_rollback_snapshot_sha256}"
			;;
		*) die "Fase del fingerprint protegido no permitida: ${phase}" ;;
	esac
	[[ -f "${reference}" && ! -L "${reference}" ]] \
		|| die "Referencia protegida ausente para fase ${phase}"
	[[ "$(sha256_file "${input}")" == "${expected_sha}" ]] \
		|| die "SHA protegido divergió en fase ${phase}: ${input}"
	cmp -s "${reference}" "${input}" \
		|| die "Superficies protegidas divergieron en fase ${phase}: ${input}"
}

capture_and_assert_protected_phase_root() {
	local container="$1" password="$2" phase="$3" output="$4"
	capture_protected_root "${container}" "${password}" "${output}"
	assert_protected_phase_file "${output}" "${phase}"
}

capture_derived_price_pair_root() {
	local container="$1" password="$2" output="$3"
	docker exec -e MYSQL_PWD="${password}" "${container}" \
		mariadb --batch --raw --skip-column-names -uroot "${source_database}" --execute "
SELECT 'AKB_SEARCH_DERIVED_PRICE_PAIR_V1';
WITH price_keys(option_name, sort_order) AS (
  SELECT 'akb_search_precio_buckets', 1 UNION ALL
  SELECT 'akb_search_precio_mode', 2
)
SELECT
  'PRICE_PAIR',
  CONCAT('H', HEX(price_keys.option_name)),
  IF(options_table.option_id IS NULL, 'ABSENT', CONCAT('H', HEX(options_table.option_name))),
  IF(options_table.option_id IS NULL, 'ABSENT', 'PRESENT'),
  IF(options_table.option_id IS NULL, 'ABSENT', SHA2(CAST(options_table.option_value AS BINARY), 256)),
  IF(options_table.option_id IS NULL, 'ABSENT', CONCAT('H', HEX(options_table.autoload)))
FROM price_keys
LEFT JOIN wp_options AS options_table
  ON options_table.option_name = price_keys.option_name
ORDER BY price_keys.sort_order, options_table.option_id;
SELECT 'END', 'AKB_SEARCH_DERIVED_PRICE_PAIR_V1';
" > "${output}"
	chmod 600 "${output}"
	grep -qx $'END\tAKB_SEARCH_DERIVED_PRICE_PAIR_V1' "${output}" \
		|| die 'Snapshot del par derivado de precio incompleto'
}

assert_derived_price_pair_file() {
	local input="$1" phase="$2" expected expected_sha actual
	actual="$(< "${input}")"
	case "${phase}" in
		preimage)
			expected=$'AKB_SEARCH_DERIVED_PRICE_PAIR_V1\nPRICE_PAIR\tH616B625F7365617263685F70726563696F5F6275636B657473\tH616B625F7365617263685F70726563696F5F6275636B657473\tPRESENT\tbbf68fbe0a982bd89e4bc0d1c2e011779029484e88f6a0d926b8f8b8fd4fb7c3\tH6F6666\nPRICE_PAIR\tH616B625F7365617263685F70726563696F5F6D6F6465\tABSENT\tABSENT\tABSENT\tABSENT\nEND\tAKB_SEARCH_DERIVED_PRICE_PAIR_V1'
			expected_sha="${price_pair_preimage_snapshot_sha256}"
			;;
		normalized)
			expected=$'AKB_SEARCH_DERIVED_PRICE_PAIR_V1\nPRICE_PAIR\tH616B625F7365617263685F70726563696F5F6275636B657473\tH616B625F7365617263685F70726563696F5F6275636B657473\tPRESENT\tbbf68fbe0a982bd89e4bc0d1c2e011779029484e88f6a0d926b8f8b8fd4fb7c3\tH6E6F\nPRICE_PAIR\tH616B625F7365617263685F70726563696F5F6D6F6465\tH616B625F7365617263685F70726563696F5F6D6F6465\tPRESENT\t929260ad9b9ea9fe0f3553dd964f4ff3deb5792efd031a2b90f573fe91f012bb\tH6E6F\nEND\tAKB_SEARCH_DERIVED_PRICE_PAIR_V1'
			expected_sha="${price_pair_normalized_snapshot_sha256}"
			;;
		*) die "Fase del par derivado no permitida: ${phase}" ;;
	esac
	[[ "${actual}" == "${expected}" ]] \
		|| die "Par derivado de precio divergió en fase ${phase}: ${input}"
	[[ "$(sha256_file "${input}")" == "${expected_sha}" ]] \
		|| die "SHA canónico del par derivado divergió en fase ${phase}: ${input}"
}

assert_derived_price_pair_phase_root() {
	local container="$1" password="$2" phase="$3" output="$4"
	capture_derived_price_pair_root "${container}" "${password}" "${output}"
	assert_derived_price_pair_file "${output}" "${phase}"
}

capture_search_schema_root() {
	local container="$1" password="$2" output="$3" check_tables="${4:-0}"
	local tables table
	{
		printf 'AKB_SEARCH_SCHEMA_INVENTORY_V1\n'
		docker exec -e MYSQL_PWD="${password}" "${container}" \
			mariadb --batch --raw --skip-column-names -uroot "${source_database}" --execute \
			"SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'wp\\_akb\\_search\\_%' ESCAPE '\\\\' ORDER BY BINARY TABLE_NAME;"
		tables="$(docker exec -e MYSQL_PWD="${password}" "${container}" \
			mariadb --batch --raw --skip-column-names -uroot "${source_database}" --execute \
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'wp\\_akb\\_search\\_%' ESCAPE '\\\\' ORDER BY BINARY TABLE_NAME;")"
		[[ -n "${tables}" ]] || die 'Inventario Search sin tablas'
		while IFS= read -r table; do
			[[ "${table}" =~ ^wp_akb_search_[A-Za-z0-9_]+$ ]] || die "Tabla Search inesperada: ${table}"
			printf 'TABLE\t%s\n' "${table}"
			docker exec -e MYSQL_PWD="${password}" "${container}" \
				mariadb --batch --raw --skip-column-names -uroot "${source_database}" \
				--execute "SHOW CREATE TABLE \`${table}\`; SELECT 'COUNT', COUNT(*) FROM \`${table}\`;"
			if [[ "${check_tables}" == 1 ]]; then
				docker exec -e MYSQL_PWD="${password}" "${container}" \
					mariadb --batch --raw --skip-column-names -uroot "${source_database}" \
					--execute "CHECK TABLE \`${table}\` EXTENDED;"
			fi
		done <<< "${tables}"
		printf 'END\tAKB_SEARCH_SCHEMA_INVENTORY_V1\n'
	} > "${output}"
	chmod 600 "${output}"
}

assert_check_table_inventory() {
	local report="$1" expected_tables="$2"
	[[ "${expected_tables}" =~ ^[1-9][0-9]*$ ]] || die 'Conteo Search para CHECK inválido'
	awk -F '\t' -v expected="${expected_tables}" -v database="${source_database}" \
		-f "${check_table_validator}" "${report}" \
		|| die 'CHECK TABLE no acreditó todas las tablas Search'
}

capture_checked_search_schema_root() {
	local root_password="$1" output="$2" search_table_count
	capture_search_schema_root "${active_db_container}" "${root_password}" "${output}" 1
	search_table_count="$(ephemeral_root_query "${root_password}" "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'wp\\_akb\\_search\\_%' ESCAPE '\\\\';" | tr -d '[:space:]')"
	assert_check_table_inventory "${output}" "${search_table_count}"
}

canonical_dump() {
	local container="$1" password="$2" output_partial="$3"
	docker exec -i -e MYSQL_PWD="${password}" "${container}" mariadb-dump -uroot \
		--single-transaction --quick --skip-lock-tables --skip-comments --hex-blob \
		--default-character-set=utf8mb4 --routines --events --triggers \
		--order-by-primary --databases "${source_database}" \
		| gzip -n -9 > "${output_partial}"
}

create_backup() {
	local stamp base partial final manifest_partial manifest_final protected_after price_pair_after
	stamp="$(date -u +%Y%m%dT%H%M%SZ)"
	base="akibara-search-0.1.14-preimage-${stamp}-${run_nonce}"
	partial="${backup_dir}/${base}.sql.gz.partial"
	final="${backup_dir}/${base}.sql.gz"
	manifest_partial="${backup_dir}/${base}.manifest.json.partial"
	manifest_final="${backup_dir}/${base}.manifest.json"
	partial_files+=("${partial}" "${manifest_partial}")
	[[ ! -e "${partial}" && ! -e "${final}" && ! -e "${manifest_final}" ]] || die 'Colisión de backup local'

	source_protected="${run_dir}/source-protected.tsv"
	source_price_pair="${run_dir}/source-derived-price-pair.tsv"
	source_schema="${run_dir}/source-search-schema.tsv"
	rollback_protected_expected="${run_dir}/protected-rollback-expected.tsv"
	protected_after="${run_dir}/source-protected-after-dump.tsv"
	price_pair_after="${run_dir}/source-derived-price-pair-after-dump.tsv"
	capture_protected_root "${source_db_container}" "${source_root_password}" "${source_protected}"
	assert_protected_phase_file "${source_protected}" candidate
	derive_rollback_protected_snapshot "${source_protected}" "${rollback_protected_expected}"
	assert_derived_price_pair_phase_root "${source_db_container}" "${source_root_password}" preimage "${source_price_pair}"
	capture_search_schema_root "${source_db_container}" "${source_root_password}" "${source_schema}" 0
	canonical_dump "${source_db_container}" "${source_root_password}" "${partial}"
	gzip -t "${partial}" || die 'gzip -t rechazó el backup'
	backup_compressed_sha="$(sha256_file "${partial}")"
	backup_decompressed_sha="$(gzip -cd "${partial}" | sha256_stdin)"
	assert_sha256 "${backup_compressed_sha}"
	assert_sha256 "${backup_decompressed_sha}"
	chmod 600 "${partial}"
	mv -- "${partial}" "${final}"
	backup_archive="${final}"

	assert_source_freeze
	capture_and_assert_protected_phase_root "${source_db_container}" "${source_root_password}" candidate "${protected_after}"
	cmp -s "${source_protected}" "${protected_after}" || die 'Superficies protegidas cambiaron durante el dump'
	assert_derived_price_pair_phase_root "${source_db_container}" "${source_root_password}" preimage "${price_pair_after}"
	cmp -s "${source_price_pair}" "${price_pair_after}" || die 'Par derivado de precio cambió durante el dump'

	jq -n \
		--arg schema 'akibara-search-local-backup-v1' \
		--arg created_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
		--arg archive "${backup_archive}" \
		--arg compressed "${backup_compressed_sha}" \
		--arg decompressed "${backup_decompressed_sha}" \
		--arg run_id "${expected_run_id}" \
		--arg state_sha "${expected_state_sha256}" \
		--arg protected_sha "$(sha256_file "${source_protected}")" \
		--arg price_pair_sha "$(sha256_file "${source_price_pair}")" \
		--arg schema_sha "$(sha256_file "${source_schema}")" \
		'{schema:$schema,created_at_utc:$created_at,location:"local-only",remote_allowed:false,archive:$archive,archive_sha256:$compressed,decompressed_sha256:$decompressed,preimage:{run_id:$run_id,raw_state_sha256:$state_sha},protected_fingerprint_sha256:$protected_sha,derived_price_pair_preimage_sha256:$price_pair_sha,search_schema_inventory_sha256:$schema_sha}' \
		> "${manifest_partial}"
	chmod 600 "${manifest_partial}"
	mv -- "${manifest_partial}" "${manifest_final}"
	note "PASS backup local-only: ${backup_archive}"
}

verify_source_unchanged_final() {
	local protected_final price_pair_final schema_final raw_state raw_state_sha final_dump receipt_partial
	local final_compressed_sha final_decompressed_sha
	assert_source_freeze

	protected_final="${run_dir}/source-protected-final.tsv"
	price_pair_final="${run_dir}/source-derived-price-pair-final.tsv"
	schema_final="${run_dir}/source-search-schema-final.tsv"
	final_dump="${run_dir}/source-final-canonical.sql.gz.partial"
	receipt_partial="${run_dir}/source-final-verification.json.partial"
	source_final_verification="${run_dir}/source-final-verification.json"
	partial_files+=("${final_dump}" "${receipt_partial}")

	capture_and_assert_protected_phase_root "${source_db_container}" "${source_root_password}" candidate "${protected_final}"
	cmp -s "${source_protected}" "${protected_final}" \
		|| die 'Superficies protegidas de la DB fuente cambiaron durante los ciclos'
	assert_derived_price_pair_phase_root "${source_db_container}" "${source_root_password}" preimage "${price_pair_final}"
	cmp -s "${source_price_pair}" "${price_pair_final}" \
		|| die 'Par derivado de precio de la DB fuente cambió durante los ciclos'
	capture_search_schema_root "${source_db_container}" "${source_root_password}" "${schema_final}" 0
	cmp -s "${source_schema}" "${schema_final}" \
		|| die 'Schema/conteos Search de la DB fuente cambiaron durante los ciclos'

	raw_state="$(main_root_query "SELECT option_value FROM wp_options WHERE option_name='akb_search_rebuild_state' ORDER BY option_id;")"
	raw_state_sha="$(printf '%s' "${raw_state}" | sha256_stdin)"
	[[ "${raw_state_sha}" == "${expected_state_sha256}" ]] \
		|| die 'Estado raw de la DB fuente cambió durante los ciclos'

	canonical_dump "${source_db_container}" "${source_root_password}" "${final_dump}"
	gzip -t "${final_dump}" || die 'Dump canónico final de la DB fuente es inválido'
	final_compressed_sha="$(sha256_file "${final_dump}")"
	final_decompressed_sha="$(gzip -cd "${final_dump}" | sha256_stdin)"
	[[ "${final_compressed_sha}" == "${backup_compressed_sha}" ]] \
		|| die 'Dump gzip final de la DB fuente divergió del preimage'
	[[ "${final_decompressed_sha}" == "${backup_decompressed_sha}" ]] \
		|| die 'SQL final de la DB fuente divergió del preimage'
	rm -f -- "${final_dump}"

	jq -n \
		--arg protected_sha "$(sha256_file "${protected_final}")" \
		--arg price_pair_sha "$(sha256_file "${price_pair_final}")" \
		--arg schema_sha "$(sha256_file "${schema_final}")" \
		--arg raw_state_sha "${raw_state_sha}" \
		--arg canonical_archive_sha "${final_compressed_sha}" \
		--arg canonical_sql_sha "${final_decompressed_sha}" \
		--arg expected_run_id "${expected_run_id}" \
		--argjson expected_tables "${expected_source_tables}" \
		'{result:"PASS",freeze_rechecked:true,wordpress_main_state:"exited",cron_main_state:"exited",foreign_connections:0,named_locks_free:true,source_table_count:$expected_tables,expected_run_id:$expected_run_id,protected_sha256:$protected_sha,derived_price_pair_preimage_sha256:$price_pair_sha,search_schema_sha256:$schema_sha,raw_state_sha256:$raw_state_sha,canonical_archive_sha256:$canonical_archive_sha,canonical_sql_sha256:$canonical_sql_sha,matches_initial_backup:true}' \
		> "${receipt_partial}"
	chmod 600 "${receipt_partial}"
	mv -- "${receipt_partial}" "${source_final_verification}"
	assert_mode_600 "${source_final_verification}"
}

wait_for_db_health() {
	local attempts health
	for attempts in $(seq 1 90); do
		health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "${active_db_container}" 2>/dev/null || true)"
		[[ "${health}" == healthy ]] && return 0
		[[ "${health}" == missing ]] && die 'MariaDB efímera no expone Healthcheck'
		[[ "${health}" == unhealthy ]] && die 'MariaDB efímera quedó unhealthy'
		sleep 2
	done
	die 'Timeout esperando MariaDB efímera healthy'
}

start_fresh_restore() {
	local iteration="$1" root_password="$2" iteration_dir="$3" redump_partial redump_final db_contract
	active_db_container="akb-srch-0114-${BASHPID}-${run_nonce}-${iteration}"
	active_network="${active_db_container}-net"
	[[ "${active_db_container}" =~ ^akb-srch-0114-[a-z0-9-]+$ ]] || die 'Nombre efímero inseguro'
	docker network create --internal --label "akibara.recovery.run=${run_label}" "${active_network}" >/dev/null
	docker run --detach --name "${active_db_container}" --network "${active_network}" \
		--label "akibara.recovery.run=${run_label}" \
		--tmpfs /var/lib/mysql:rw,nosuid,nodev,noexec,size=4g \
		--health-cmd='healthcheck.sh --connect --innodb_initialized' \
		--health-interval=5s \
		--health-timeout=5s \
		--health-retries=20 \
		--env MARIADB_ROOT_PASSWORD="${root_password}" \
		"${mariadb_image}" >/dev/null
	wait_for_db_health

	gzip -cd "${backup_archive}" \
		| docker exec -i -e MYSQL_PWD="${root_password}" "${active_db_container}" mariadb -uroot
	db_contract="$(ephemeral_root_query "${root_password}" "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${source_database}';")"
	[[ "${db_contract}" == $'utf8mb4\tutf8mb4_uca1400_ai_ci' ]] || die "Contrato charset/collation divergente: ${db_contract}"

	redump_partial="${iteration_dir}/restored-canonical.sql.gz.partial"
	redump_final="${iteration_dir}/restored-canonical.sql.gz"
	partial_files+=("${redump_partial}")
	canonical_dump "${active_db_container}" "${root_password}" "${redump_partial}"
	gzip -t "${redump_partial}" || die 'Redump restaurado inválido'
	[[ "$(sha256_file "${redump_partial}")" == "${backup_compressed_sha}" ]] || die 'Redump gzip no es byte-idéntico'
	[[ "$(gzip -cd "${redump_partial}" | sha256_stdin)" == "${backup_decompressed_sha}" ]] || die 'Redump SQL no es byte-idéntico'
	chmod 600 "${redump_partial}"
	mv -- "${redump_partial}" "${redump_final}"

	capture_search_schema_root "${active_db_container}" "${root_password}" "${iteration_dir}/restored-search-schema.tsv" 0
	cmp -s "${source_schema}" "${iteration_dir}/restored-search-schema.tsv" || die 'SHOW CREATE/conteos Search divergieron tras restore'
	capture_checked_search_schema_root "${root_password}" "${iteration_dir}/restored-check-table.tsv"
	capture_and_assert_protected_phase_root "${active_db_container}" "${root_password}" candidate "${iteration_dir}/protected-restored.tsv"
	assert_derived_price_pair_phase_root "${active_db_container}" "${root_password}" preimage "${iteration_dir}/derived-price-pair-restored.tsv"
	cmp -s "${source_price_pair}" "${iteration_dir}/derived-price-pair-restored.tsv" || die 'Par derivado de precio divergió tras restore'
}

create_limited_wp_user() {
	local root_password="$1" iteration="$2" user_var="$3" password_var="$4" username password global_privileges
	local schema_privileges wrong_schema_privileges table_column_privileges routine_privileges
	username="akbwp_${run_nonce:0:12}_${iteration}"
	password="$(openssl rand -hex 24)"
	[[ "${username}" =~ ^[a-z0-9_]{1,32}$ && "${password}" =~ ^[a-f0-9]{48}$ ]] || die 'Credencial efímera inválida'
	ephemeral_root_query "${root_password}" \
		"CREATE USER '${username}'@'%' IDENTIFIED BY '${password}'; GRANT ALL PRIVILEGES ON \`${source_database}\`.* TO '${username}'@'%';" >/dev/null
	global_privileges="$(ephemeral_wp_query "${username}" "${password}" \
		"SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1))) AND PRIVILEGE_TYPE <> 'USAGE';" | tr -d '[:space:]')"
	[[ "${global_privileges}" == 0 ]] || die 'Usuario WP recibió privilegios globales'
	schema_privileges="$(ephemeral_wp_query "${username}" "${password}" \
		"SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1))) AND TABLE_SCHEMA='${source_database}';" | tr -d '[:space:]')"
	wrong_schema_privileges="$(ephemeral_wp_query "${username}" "${password}" \
		"SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1))) AND TABLE_SCHEMA<>'${source_database}';" | tr -d '[:space:]')"
	table_column_privileges="$(ephemeral_wp_query "${username}" "${password}" \
		"SELECT (SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1)))) + (SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1))));" | tr -d '[:space:]')"
	routine_privileges="$(ephemeral_routine_privilege_count "${root_password}" "${username}" '%' | tr -d '[:space:]')"
	[[ "${schema_privileges}" =~ ^[1-9][0-9]*$ ]] || die 'Usuario WP no recibió privilegios de schema'
	[[ "${wrong_schema_privileges}" == 0 && "${table_column_privileges}" == 0 \
		&& "${routine_privileges}" == 0 ]] \
		|| die 'Usuario WP recibió privilegios fuera del schema exacto'
	printf -v "${user_var}" '%s' "${username}"
	printf -v "${password_var}" '%s' "${password}"
}

wp_base_args() {
	local wp_user="$1" wp_password="$2" search_tree="$3" business_tree="$4" container_name="$5"
	[[ -n "${theme_candidate_tree}" && -d "${theme_candidate_tree}" && ! -L "${theme_candidate_tree}" ]] \
		|| die 'Árbol Theme candidato no preparado para WP-CLI'
	WP_ARGS=(
		docker run --rm --name "${container_name}" --network "${active_network}"
		--label "akibara.recovery.run=${run_label}"
		--read-only --tmpfs /tmp:rw,nosuid,nodev,noexec,size=256m
		--user 33:33 --workdir /var/www/html
		--env WORDPRESS_DB_HOST="${active_db_container}"
		--env WORDPRESS_DB_NAME="${source_database}"
		--env WORDPRESS_DB_USER="${wp_user}"
		--env WORDPRESS_DB_PASSWORD="${wp_password}"
		--env WP_ENVIRONMENT_TYPE=local
		--env WP_CLI_CACHE_DIR=/tmp/wp-cli-cache
		--volume "${repo_root}/public_html:/var/www/html:ro"
		--volume "${search_tree}:/var/www/html/wp-content/plugins/akibara-search:ro"
		--volume "${business_tree}:/var/www/html/wp-content/plugins/akibara-business:ro"
		--volume "${theme_candidate_tree}:/var/www/html/wp-content/themes/akibara-gp:ro"
		--volume "${proof_php}:/tmp/akb-search-recovery-proof.php:ro"
	)
}

wp_proof() {
	local mode="$1" isolated="$2" wp_user="$3" wp_password="$4" search_tree="$5" business_tree="$6" output="$7"
	local container_name receipt_count
	container_name="akb-srch-0114-wp-${BASHPID}-${run_nonce}-$(openssl rand -hex 3)"
	wp_base_args "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${container_name}"
	WP_ARGS+=(
		--env AKB_RECOVERY_MODE="${mode}"
		--env AKB_RECOVERY_EXPECTED_RUN_ID="${expected_run_id}"
		--env AKB_RECOVERY_EXPECTED_STATE_SHA256="${expected_state_sha256}"
		--env AKB_RECOVERY_EXPECTED_WRITE_LOCK="${expected_write_lock}"
		--env AKB_RECOVERY_EXPECTED_INSTALL_LOCK="${expected_install_lock}"
	)
	WP_ARGS+=("${wpcli_image}")
	if [[ "${isolated}" == 1 ]]; then
		WP_ARGS+=(wp --path=/var/www/html --skip-plugins --skip-themes eval-file /tmp/akb-search-recovery-proof.php --use-include)
	else
		WP_ARGS+=(wp --path=/var/www/html eval-file /tmp/akb-search-recovery-proof.php --use-include)
	fi
	"${WP_ARGS[@]}" > "${output}" 2>&1 || {
		awk '/AKB_RECOVERY_(FAIL|RECEIPT)=/{print}' "${output}" >&2
		die "WP proof falló: ${mode}"
	}
	receipt_count="$(grep -c '^AKB_RECOVERY_RECEIPT=' "${output}" || true)"
	[[ "${receipt_count}" == 1 ]] || die "Receipt ambiguo/ausente: ${mode}"
	sed -n 's/^AKB_RECOVERY_RECEIPT=//p' "${output}" | jq -e --arg mode "${mode}" '.result == "ok" and .mode == $mode' >/dev/null \
		|| die "Receipt JSON inválido: ${mode}"
}

wp_boot() {
	local wp_user="$1" wp_password="$2" search_tree="$3" business_tree="$4" output="$5" container_name
	container_name="akb-srch-0114-wp-${BASHPID}-${run_nonce}-$(openssl rand -hex 3)"
	wp_base_args "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${container_name}"
	WP_ARGS+=("${wpcli_image}")
	WP_ARGS+=(wp --path=/var/www/html eval 'fwrite(STDOUT, "AKB_BOOT_OK\n");')
	"${WP_ARGS[@]}" > "${output}" 2>&1 || die 'Boot WP efímero falló'
	[[ "$(grep -cx 'AKB_BOOT_OK' "${output}" || true)" == 1 ]] || die 'Boot WP efímero sin receipt único'
}

wp_set_search_activation() {
	local action="$1" wp_user="$2" wp_password="$3" search_tree="$4" business_tree="$5" output="$6" container_name
	[[ "${action}" == activate || "${action}" == deactivate ]] \
		|| die "Transición Search inválida: ${action}"
	container_name="akb-srch-0114-wp-${BASHPID}-${run_nonce}-$(openssl rand -hex 3)"
	wp_base_args "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${container_name}"
	WP_ARGS+=("${wpcli_image}")
	WP_ARGS+=(wp --path=/var/www/html plugin "${action}" akibara-search)
	"${WP_ARGS[@]}" > "${output}" 2>&1 \
		|| die "No se pudo ${action} Search con el par montado"
}

wp_assert_pair_state() {
	local expected_state="$1" expected_search="$2" expected_business="$3"
	local wp_user="$4" wp_password="$5" search_tree="$6" business_tree="$7" output="$8"
	local container_name expected_active receipt receipt_count
	case "${expected_state}" in
		active) expected_active=true ;;
		inactive) expected_active=false ;;
		*) die "Estado Search esperado inválido: ${expected_state}" ;;
	esac
	container_name="akb-srch-0114-wp-${BASHPID}-${run_nonce}-$(openssl rand -hex 3)"
	wp_base_args "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${container_name}"
	WP_ARGS+=(
		--env AKB_EXPECTED_SEARCH_VERSION="${expected_search}"
		--env AKB_EXPECTED_BUSINESS_VERSION="${expected_business}"
		--env AKB_EXPECTED_SEARCH_ACTIVE="${expected_active}"
		"${wpcli_image}"
		wp --path=/var/www/html --skip-plugins --skip-themes eval '
require_once ABSPATH . "wp-admin/includes/plugin.php";
$search_file   = "akibara-search/akibara-search.php";
$business_file = "akibara-business/akibara-business.php";
$search        = get_plugin_data( WP_PLUGIN_DIR . "/" . $search_file, false, false );
$business      = get_plugin_data( WP_PLUGIN_DIR . "/" . $business_file, false, false );
$active        = is_plugin_active( $search_file );
$expected      = "true" === getenv( "AKB_EXPECTED_SEARCH_ACTIVE" );
if ( (string) ( $search["Version"] ?? "" ) !== getenv( "AKB_EXPECTED_SEARCH_VERSION" ) ) {
    throw new RuntimeException( "Search midpoint version mismatch" );
}
if ( (string) ( $business["Version"] ?? "" ) !== getenv( "AKB_EXPECTED_BUSINESS_VERSION" ) ) {
    throw new RuntimeException( "Business midpoint version mismatch" );
}
if ( $active !== $expected ) {
    throw new RuntimeException( "Search activation state mismatch" );
}
fwrite( STDOUT, "AKB_PAIR_STATE=" . wp_json_encode( array(
    "search_version"   => (string) $search["Version"],
    "business_version" => (string) $business["Version"],
    "search_active"    => $active,
) ) . "\n" );
'
	)
	"${WP_ARGS[@]}" > "${output}" 2>&1 || {
		grep '^AKB_PAIR_STATE=' "${output}" >&2 || true
		die "Estado del par no coincide: Search ${expected_search}, Business ${expected_business}, Search ${expected_state}"
	}
	receipt_count="$(grep -c '^AKB_PAIR_STATE=' "${output}" || true)"
	[[ "${receipt_count}" == 1 ]] || die 'Receipt de estado pareado ambiguo/ausente'
	receipt="$(sed -n 's/^AKB_PAIR_STATE=//p' "${output}")"
	jq -e \
		--arg search "${expected_search}" \
		--arg business "${expected_business}" \
		--argjson active "${expected_active}" '
      .search_version == $search
      and .business_version == $business
      and .search_active == $active
	    ' <<< "${receipt}" >/dev/null || die 'Receipt de estado pareado inválido'
}

wp_assert_theme_state() {
	local wp_user="$1" wp_password="$2" search_tree="$3" business_tree="$4" output="$5"
	local container_name receipt receipt_count expected_style_sha
	expected_style_sha="$(jq -r '.headers.style_css.sha256' "${theme_contract}")"
	assert_sha256 "${expected_style_sha}"
	container_name="akb-srch-0114-wp-${BASHPID}-${run_nonce}-$(openssl rand -hex 3)"
	wp_base_args "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${container_name}"
	WP_ARGS+=(
		--env AKB_EXPECTED_THEME_STYLE_SHA256="${expected_style_sha}"
		"${wpcli_image}"
		wp --path=/var/www/html --skip-plugins --skip-themes eval '
$slug       = "akibara-gp";
$stylesheet = (string) get_option( "stylesheet", "" );
$template   = (string) get_option( "template", "" );
$theme      = wp_get_theme( $slug );
$version    = (string) $theme->get( "Version" );
$style_file = get_theme_root() . "/" . $slug . "/style.css";
$style_sha  = is_readable( $style_file ) ? hash_file( "sha256", $style_file ) : "";
if ( "akibara-gp" !== $stylesheet || "generatepress" !== $template || "2.1.70" !== $version
    || ! is_string( $style_sha ) || $style_sha !== getenv( "AKB_EXPECTED_THEME_STYLE_SHA256" ) ) {
    throw new RuntimeException( "Theme candidate state mismatch" );
}
fwrite( STDOUT, "AKB_THEME_STATE=" . wp_json_encode( array(
    "slug"         => $slug,
    "version"      => $version,
    "stylesheet"   => $stylesheet,
    "template"     => $template,
    "active"       => true,
    "style_sha256" => $style_sha,
) ) . "\n" );
'
	)
	"${WP_ARGS[@]}" > "${output}" 2>&1 || {
		grep '^AKB_THEME_STATE=' "${output}" >&2 || true
		die 'Theme 2.1.70 no está activo desde el árbol candidato exacto'
	}
	chmod 600 "${output}"
	receipt_count="$(grep -c '^AKB_THEME_STATE=' "${output}" || true)"
	[[ "${receipt_count}" == 1 ]] || die 'Receipt Theme ambiguo/ausente'
	receipt="$(sed -n 's/^AKB_THEME_STATE=//p' "${output}")"
	jq -e --arg style_sha "${expected_style_sha}" '
	  . == {slug:"akibara-gp",version:"2.1.70",stylesheet:"akibara-gp",template:"generatepress",active:true,style_sha256:$style_sha}
	' <<< "${receipt}" >/dev/null || die 'Receipt Theme candidato inválido'
}

run_theme_quality_gate() {
	local iteration="$1" iteration_dir="$2"
	local static_log package_log node_log phpunit_log ai_test_log receipt_partial receipt_file
	local node_container php_container ai_test_container candidate_inventory candidate_inventory_sha rollback_inventory rollback_inventory_sha
	local relative
	local -a node_tests=()
	static_log="${iteration_dir}/theme-static-contract.log"
	package_log="${iteration_dir}/theme-package-verify.log"
	node_log="${iteration_dir}/theme-node.log"
	phpunit_log="${iteration_dir}/theme-phpunit.log"
	ai_test_log="${iteration_dir}/ai-audit-unit.log"
	receipt_partial="${iteration_dir}/theme-quality-receipt.json.partial"
	receipt_file="${iteration_dir}/theme-quality-receipt.json"
	partial_files+=("${receipt_partial}")
	[[ -n "${theme_test_tree}" && -d "${theme_test_tree}" && ! -L "${theme_test_tree}" ]] \
		|| die 'Harness Theme aislado no está preparado'

	bash "${theme_release_test}" > "${static_log}" 2>&1 \
		|| die "Contrato estricto Theme falló en pasada ${iteration}"
	grep -Fqx 'PASS contrato Theme 2.1.70: 6 gates positivos y 10 mutaciones negativas' "${static_log}" \
		|| die 'Contrato Theme no emitió el receipt positivo exacto'
	bash "${runtime_packager}" theme --verify-only > "${package_log}" 2>&1 \
		|| die "Package Theme falló en pasada ${iteration}"
	grep -Fqx 'Archivos runtime: 103' "${package_log}" || die 'Package Theme no acreditó 103 archivos'
	grep -Fqx 'SHA-256 ZIP: 69c10a7c7842e0f76f91350f76733da957a53d5dc8560342cc37e5a3ccf7e69c' "${package_log}" \
		|| die 'Package Theme no acreditó el ZIP candidato exacto'

	while IFS= read -r relative; do
		node_tests+=("/workspace/${relative}")
	done < <(jq -r '.quality_gates.node_theme.test_files[].path' "${theme_contract}")
	[[ "${#node_tests[@]}" == 12 ]] || die 'Contrato Theme no fijó 12 archivos Node'
	node_container="akb-srch-0114-theme-node-${BASHPID}-${run_nonce}-${iteration}"
	docker run --rm --name "${node_container}" --network none \
		--label "akibara.recovery.run=${run_label}" \
		--read-only --tmpfs /tmp:rw,nosuid,nodev,noexec,size=128m \
		--user 65534:65534 --workdir /workspace \
		--volume "${repo_root}:/workspace:ro" \
		--volume "${theme_test_tree}:/workspace/public_html/wp-content/themes/akibara-gp:ro" \
		"${node_image}" node --test "${node_tests[@]}" > "${node_log}" 2>&1 \
		|| die "Suite Node Theme falló en pasada ${iteration}"
	grep -Eq '^# tests 31$' "${node_log}" || die 'Suite Node Theme no reportó 31 tests'
	grep -Eq '^# pass 31$' "${node_log}" || die 'Suite Node Theme no reportó 31 passes'
	grep -Eq '^# fail 0$' "${node_log}" || die 'Suite Node Theme reportó fallos'

	php_container="akb-srch-0114-theme-php-${BASHPID}-${run_nonce}-${iteration}"
	docker run --rm --name "${php_container}" --network none \
		--label "akibara.recovery.run=${run_label}" \
		--read-only --tmpfs /tmp:rw,nosuid,nodev,noexec,size=256m \
		--user 33:33 --workdir /workspace \
		--volume "${repo_root}:/workspace:ro" \
		--volume "${theme_test_tree}:/workspace/public_html/wp-content/themes/akibara-gp:ro" \
		--entrypoint php "${apache_image}" tools/akibara-business/vendor/bin/phpunit \
		--do-not-cache-result --colors=never -c tools/akibara-gp/phpunit.xml.dist > "${phpunit_log}" 2>&1 \
		|| die "Suite PHPUnit Theme falló en pasada ${iteration}"
	grep -Fq 'OK (18 tests, 52 assertions)' "${phpunit_log}" \
		|| die 'Suite PHPUnit Theme no acreditó 18 tests y 52 assertions'

	ai_test_container="akb-srch-0114-ai-unit-${BASHPID}-${run_nonce}-${iteration}"
	docker run --rm --name "${ai_test_container}" --network none \
		--label "akibara.recovery.run=${run_label}" \
		--read-only --tmpfs /tmp:rw,nosuid,nodev,noexec,size=256m \
		--user 65534:65534 --workdir /workspace \
		--volume "${repo_root}:/workspace:ro" \
		"${node_image}" node tools/test-ai-discoverability-audit.mjs > "${ai_test_log}" 2>&1 \
		|| die "Suite del auditor IA falló en pasada ${iteration}"
	grep -Fqx 'PASS: ai discoverability auditor enforces online-store truth and identifies failing user agent' "${ai_test_log}" \
		|| die 'Suite del auditor IA no emitió el receipt exacto'

	candidate_inventory="$(repo_file "$(jq -r '.component.candidate.inventory' "${theme_contract}")")"
	rollback_inventory="$(repo_file "$(jq -r '.component.preimage.inventory' "${theme_contract}")")"
	candidate_inventory_sha="$(sha256_file "${candidate_inventory}")"
	rollback_inventory_sha="$(sha256_file "${rollback_inventory}")"
	validate_tree_inventory "$(dirname -- "${theme_candidate_tree}")" "${candidate_inventory}" akibara-gp
	validate_tree_inventory "$(dirname -- "${theme_rollback_tree}")" "${rollback_inventory}" akibara-gp
	chmod 600 "${static_log}" "${package_log}" "${node_log}" "${phpunit_log}" "${ai_test_log}"

	jq -n \
		--argjson pass "${iteration}" \
		--arg node_image "${node_image}" --arg php_image "${apache_image}" \
		--arg candidate_inventory_sha "${candidate_inventory_sha}" \
		--arg rollback_inventory_sha "${rollback_inventory_sha}" \
		--arg static_sha "$(sha256_file "${static_log}")" \
		--arg package_sha "$(sha256_file "${package_log}")" \
		--arg node_sha "$(sha256_file "${node_log}")" \
		--arg php_sha "$(sha256_file "${phpunit_log}")" \
		--arg ai_test_sha "$(sha256_file "${ai_test_log}")" \
		'{result:"PASS",pass:$pass,theme:{candidate_version:"2.1.70",rollback_version:"2.1.69",candidate_inventory_sha256:$candidate_inventory_sha,rollback_inventory_sha256:$rollback_inventory_sha,candidate_overlay_exact:true},static_contract:{passes:1,log_sha256:$static_sha},package_verify:{passes:1,runtime_files:103,log_sha256:$package_sha},node:{image:$node_image,tests:31,passes:31,failures:0,network:"none",log_sha256:$node_sha},phpunit:{image:$php_image,tests:18,assertions:52,failures:0,network:"none",log_sha256:$php_sha},ai_audit_unit:{image:$node_image,result:"PASS",network:"none",log_sha256:$ai_test_sha},external_network_dependency_allowed:false}' \
		> "${receipt_partial}"
	chmod 600 "${receipt_partial}"
	mv -- "${receipt_partial}" "${receipt_file}"
}

wait_until_epoch() {
	local target="$1" now remaining sleep_for
	[[ "${target}" =~ ^[0-9]{10}$ ]] || die "not_before inválido: ${target}"
	now="$(date -u +%s)"
	(( target <= now + 300 )) || die 'not_before excede la gracia auditada máxima de 300s'
	while :; do
		now="$(date -u +%s)"
		(( now >= target )) && break
		remaining=$((target - now))
		sleep_for=5
		(( remaining < sleep_for )) && sleep_for="${remaining}"
		sleep "${sleep_for}"
	done
}

pending_rebuild_snapshot() {
	local wp_user="$1" wp_password="$2" raw_hex snapshot
	raw_hex="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
		"SELECT HEX(option_value) FROM wp_options WHERE option_name='akb_search_pending_rebuild';" \
		| tr '[:lower:]' '[:upper:]' | tr -d '[:space:]')"
	[[ "${raw_hex}" =~ ^([A-F0-9]{2})+$ ]] || die 'Pending rebuild no tiene bytes hex válidos'
	if ! snapshot="$(AKB_PENDING_HEX="${raw_hex}" php -r '
$hex = getenv("AKB_PENDING_HEX");
if (!is_string($hex) || 1 !== preg_match("/\\A(?:[A-F0-9]{2})+\\z/D", $hex)) {
    exit(2);
}
$raw = hex2bin($hex);
if (!is_string($raw)) {
    exit(3);
}
set_error_handler(static function (int $severity, string $message): bool {
    throw new RuntimeException($message, $severity);
});
try {
    $value = unserialize($raw, array("allowed_classes" => false));
} catch (Throwable $throwable) {
    exit(4);
} finally {
    restore_error_handler();
}
if (!is_array($value) || 2 !== ($value["protocol"] ?? null) || !is_array($value["requests"] ?? null)) {
    exit(5);
}
$requests = array_values($value["requests"]);
if (count($requests) < 1 || count($requests) > 4) {
    exit(6);
}
$maxNotBefore = 0;
$safe = array();
foreach ($requests as $request) {
    if (!is_array($request)) {
        exit(7);
    }
    $token       = (string) ($request["token"] ?? "");
    $seq         = (int) ($request["seq"] ?? 0);
    $reason      = (string) ($request["reason"] ?? "");
    $version     = (string) ($request["version"] ?? "");
    $requestedAt = (int) ($request["requested_at"] ?? 0);
    $notBefore   = (int) ($request["not_before"] ?? 0);
    if (1 !== preg_match("/\\A[a-f0-9-]{16,64}\\z/D", $token)
        || $seq < 1
        || 1 !== preg_match("/\\A[a-z0-9_]{1,64}\\z/D", $reason)
        || strlen($version) > 32
        || $requestedAt < 1
        || $notBefore < $requestedAt) {
        exit(8);
    }
    $maxNotBefore = max($maxNotBefore, $notBefore);
    $safe[] = array(
        "token_sha256" => hash("sha256", $token),
        "seq" => $seq,
        "reason" => $reason,
        "version" => $version,
        "requested_at" => $requestedAt,
        "not_before" => $notBefore,
    );
}
echo json_encode(array(
    "schema" => "akibara-search-pending-snapshot-v1",
    "protocol" => 2,
    "request_count" => count($safe),
    "max_not_before" => $maxNotBefore,
    "raw_sha256" => hash("sha256", $raw),
    "requests" => $safe,
), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
')"; then
		die 'Pending rebuild no se pudo decodificar fail-closed'
	fi
	jq -e '
      .schema == "akibara-search-pending-snapshot-v1"
      and .protocol == 2
      and (.request_count >= 1 and .request_count <= 4)
      and ((.requests | length) == .request_count)
      and (.max_not_before | type == "number" and floor == . and . >= 1000000000)
      and (.raw_sha256 | test("^[a-f0-9]{64}$"))
      and all(.requests[];
        (.token_sha256 | test("^[a-f0-9]{64}$"))
        and (.seq | type == "number" and floor == . and . >= 1)
        and (.reason | test("^[a-z0-9_]{1,64}$"))
        and (.version | type == "string" and length <= 32)
        and (.requested_at | type == "number" and floor == . and . >= 1)
        and (.not_before | type == "number" and floor == .)
        and (.not_before >= .requested_at)
      )
    ' <<< "${snapshot}" >/dev/null || die 'Receipt pending rebuild inválido'
	printf '%s\n' "${snapshot}"
}

# Valida la identidad del wake-up antes de entregárselo a Action Scheduler.
# 0.1.14 liga el action al run/scope. El rollback 0.1.12, por diseño histórico,
# sólo guarda {offset}; en ese tramo el fence equivalente es la igualdad exacta
# con state.offset, además de hook/grupo/status/claim/args cerrados.
batch_action_snapshot() {
	local wp_user="$1" wp_password="$2" id="$3" generation="$4" active_run_id="$5"
	local row action_hook action_status args_hex extended_null action_group claim_id attempts
	local args_json state_offset scope run_sha
	row="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
		"SELECT CONCAT(COALESCE(a.hook,''),'|',COALESCE(a.status,''),'|',HEX(COALESCE(a.args,'')),'|',IF(a.extended_args IS NULL,1,0),'|',COALESCE(g.slug,''),'|',a.claim_id,'|',a.attempts) FROM wp_actionscheduler_actions a LEFT JOIN wp_actionscheduler_groups g ON g.group_id=a.group_id WHERE a.action_id=${id};")"
	IFS='|' read -r action_hook action_status args_hex extended_null action_group claim_id attempts <<< "${row}"
	[[ "${action_hook}" == akb_search_rebuild_batch && "${action_status}" == pending \
		&& "${action_group}" == akibara-search && "${claim_id}" == 0 \
		&& "${attempts}" =~ ^[0-9]+$ && "${extended_null}" == 1 \
		&& "${args_hex}" =~ ^[A-F0-9]+$ ]] \
		|| die "Action ${id} no conserva hook/status/grupo/claim/args exactos"
	if ! args_json="$(php -r '$value=hex2bin($argv[1]); if (false === $value) { exit(1); } echo $value;' "${args_hex}")"; then
		die "Action ${id} contiene args que no decodifican desde HEX"
	fi
	case "${generation}" in
		v2)
			jq -e --arg run_id "${active_run_id}" '
			  type=="array" and length==2 and .[0]==$run_id
			  and (.[1]=="baseline" or .[1]=="repair")
			' <<< "${args_json}" >/dev/null \
				|| die "Action ${id} no es el batch v2 exacto del run/scope"
			scope="$(jq -r '.[1]' <<< "${args_json}")"
			run_sha="$(printf '%s' "${active_run_id}" | sha256_stdin)"
			jq -cn --argjson id "${id}" --arg scope "${scope}" --arg run_sha "${run_sha}" \
				--argjson attempts "${attempts}" \
				'{generation:"v2",action_id:$id,hook:"akb_search_rebuild_batch",status:"pending",group:"akibara-search",claim_id:0,attempts:$attempts,extended_args_null:true,args:{run_id_sha256:$run_sha,scope:$scope}}'
			;;
		legacy-v1)
			state_offset="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
				"SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(option_value,'\$.offset')),'') FROM wp_options WHERE option_name='akb_search_rebuild_state';" | tr -d '[:space:]')"
			[[ "${state_offset}" =~ ^[0-9]+$ ]] || die 'State legacy no expone offset entero'
			jq -e --argjson offset "${state_offset}" '
			  type=="object" and (keys==["offset"])
			  and (.offset|type)=="number" and (.offset|floor)==.offset and .offset==$offset
			' <<< "${args_json}" >/dev/null \
				|| die "Action ${id} no es el batch legacy {offset}=state.offset exacto"
			jq -cn --argjson id "${id}" --argjson offset "${state_offset}" \
				--arg run_sha "$(printf '%s' "${active_run_id}" | sha256_stdin)" \
				--argjson attempts "${attempts}" \
				'{generation:"legacy-v1",action_id:$id,hook:"akb_search_rebuild_batch",status:"pending",group:"akibara-search",claim_id:0,attempts:$attempts,extended_args_null:true,state_run_id_sha256:$run_sha,args:{offset:$offset}}'
			;;
		*) die "Generación de batch desconocida: ${generation}" ;;
	esac
}

drain_search_batches() {
	local wp_user="$1" wp_password="$2" search_tree="$3" business_tree="$4" log_file="$5" generation="$6"
	local round=0 total=0 runtime_ticks=0 ids_raw state_rows pending_rows id proof_row container_name
	local option_snapshot action_snapshot work_snapshot orphan_sha previous_orphan_sha=''
	local pending_snapshot not_before state_raw state_phase state_error tick_log tick_sha
	local active_run_id
	local -a ids=()
	case "${generation}" in
		v2)
			grep -Eq '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*0\.1\.14[[:space:]]*$' \
				"${search_tree}/akibara-search.php" || die 'Drain v2 no usa Search 0.1.14 exacto'
			;;
		legacy-v1)
			grep -Eq '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*0\.1\.12[[:space:]]*$' \
				"${search_tree}/akibara-search.php" || die 'Drain legacy no usa Search 0.1.12 exacto'
			;;
		*) die "Generación de drain inválida: ${generation}" ;;
	esac
	: > "${log_file}"
	chmod 600 "${log_file}"
	while (( round < 64 )); do
		ids_raw="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
			"SELECT action_id FROM wp_actionscheduler_actions WHERE hook='akb_search_rebuild_batch' AND status='pending' ORDER BY action_id ASC LIMIT 32;")"
		state_rows="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
			"SELECT COUNT(*) FROM wp_options WHERE option_name='akb_search_rebuild_state';" | tr -d '[:space:]')"
		pending_rows="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
			"SELECT COUNT(*) FROM wp_options WHERE option_name='akb_search_pending_rebuild';" | tr -d '[:space:]')"
		[[ "${state_rows}" =~ ^[01]$ && "${pending_rows}" =~ ^[01]$ ]] || die 'Conteo state/pending inválido durante drain'
		if [[ -z "${ids_raw}" ]]; then
			if [[ "${state_rows}" != 0 || "${pending_rows}" != 0 ]]; then
				# El rail nunca repara mediante Reconciler. Conserva, en cambio, una
				# foto 0600 suficiente para distinguir señal no consumida, estado sin
				# wake-up, acción fallida/en-progreso o deuda durable huérfana.
				option_snapshot="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
					"SELECT option_name, HEX(option_value) FROM wp_options WHERE option_name IN ('akb_search_rebuild_state','akb_search_pending_rebuild') ORDER BY BINARY option_name;")"
				action_snapshot="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
					"SELECT a.action_id, a.hook, a.status, a.attempts, a.claim_id, HEX(COALESCE(a.args,'')), COALESCE(g.slug,'') FROM wp_actionscheduler_actions a LEFT JOIN wp_actionscheduler_groups g ON g.group_id=a.group_id WHERE a.hook IN ('akb_search_rebuild_batch','akb_search_rebuild_watchdog') OR g.slug='akibara-search' ORDER BY a.action_id DESC LIMIT 64;")"
				work_snapshot="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
					"SELECT scope, status, COUNT(*), MIN(run_id), MAX(run_id) FROM wp_akb_search_work GROUP BY scope, status ORDER BY BINARY scope, BINARY status;")"
				orphan_sha="$(printf '%s\n%s\n%s\n' "${option_snapshot}" "${action_snapshot}" "${work_snapshot}" | sha256_stdin)"
				printf 'orphan-snapshot\tstate_rows=%s\tpending_rows=%s\n' "${state_rows}" "${pending_rows}" >> "${log_file}"
				printf '%s\n' "${option_snapshot}" "${action_snapshot}" "${work_snapshot}" >> "${log_file}"
				printf 'orphan-sha256\t%s\n' "${orphan_sha}" >> "${log_file}"
				[[ -z "${previous_orphan_sha}" || "${orphan_sha}" != "${previous_orphan_sha}" ]] \
					|| die 'Tick normal no produjo progreso durable: no se invoca Reconciler'
				(( runtime_ticks < 4 )) \
					|| die 'State/pending activo sin batch pending: no se invoca Reconciler tras 4 ticks'
				previous_orphan_sha="${orphan_sha}"

				if [[ "${state_rows}" == 1 ]]; then
					state_raw="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
						"SELECT option_value FROM wp_options WHERE option_name='akb_search_rebuild_state';")"
					if ! state_phase="$(jq -er '.phase | strings' <<< "${state_raw}")"; then
						die 'State activo no es JSON con fase legible'
					fi
					state_error="$(jq -r '.error // "" | strings' <<< "${state_raw}")"
					case "${state_phase}" in
						PREPARING|BUILDING|CATCHING_UP|SWAP_INTENT|SWAPPED|PUBLISHING|CLEANUP|DONE) ;;
						BLOCKED|ABORTED) die "State terminal sin batch: phase=${state_phase}, error=${state_error}" ;;
						*) die "State activo con fase no permitida: ${state_phase}" ;;
					esac
					printf 'runtime-tick-pre\tphase=%s\terror=%s\n' "${state_phase}" "${state_error}" >> "${log_file}"
				elif [[ "${pending_rows}" == 1 ]]; then
					pending_snapshot="$(pending_rebuild_snapshot "${wp_user}" "${wp_password}")"
					printf 'pending-snapshot\t%s\n' "${pending_snapshot}" >> "${log_file}"
					not_before="$(jq -r '.max_not_before' <<< "${pending_snapshot}")"
					wait_until_epoch "${not_before}"
				else
					die 'Combinación state/pending imposible durante tick'
				fi

				(( runtime_ticks += 1 ))
				tick_log="${log_file%.log}.runtime-tick-${runtime_ticks}.log"
				wp_boot "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${tick_log}"
				chmod 600 "${tick_log}"
				tick_sha="$(sha256_file "${tick_log}")"
				printf 'runtime-tick-post\ttick=%d\tboot_sha256=%s\n' "${runtime_ticks}" "${tick_sha}" >> "${log_file}"
				continue
			fi
			return 0
		fi
		[[ "${state_rows}" == 1 ]] || die 'Batch pending existe sin state durable'
		active_run_id="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
			"SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(option_value,'\$.run_id')),'') FROM wp_options WHERE option_name='akb_search_rebuild_state';" \
			| tr -d '[:space:]')"
		[[ "${active_run_id}" =~ ^[a-f0-9-]{16,64}$ ]] || die 'run_id activo inválido durante drain'
		ids=()
		while IFS= read -r id; do
			[[ "${id}" =~ ^[1-9][0-9]*$ ]] || die 'Action ID inválido'
			proof_row="$(batch_action_snapshot "${wp_user}" "${wp_password}" "${id}" "${generation}" "${active_run_id}")"
			jq -e --arg generation "${generation}" '.generation==$generation and .action_id>0' \
				<<< "${proof_row}" >/dev/null || die "Receipt batch ${id} inválido"
			printf 'batch-identity\t%s\n' "${proof_row}" >> "${log_file}"
			ids+=("${id}")
		done <<< "${ids_raw}"
		(( total += ${#ids[@]} ))
		(( total <= 512 )) || die 'Drenaje excedió 512 IDs acotados'
		(( round += 1 ))
		printf 'round=%d\thook=akb_search_rebuild_batch\tids=%s\n' "${round}" "$(IFS=,; printf '%s' "${ids[*]}")" >> "${log_file}"
		container_name="akb-srch-0114-wp-${BASHPID}-${run_nonce}-$(openssl rand -hex 3)"
		wp_base_args "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${container_name}"
		WP_ARGS+=("${wpcli_image}")
		WP_ARGS+=(wp --path=/var/www/html action-scheduler action run "${ids[@]}")
		"${WP_ARGS[@]}" >> "${log_file}" 2>&1 || die 'Action Scheduler falló al correr IDs explícitos'
	done
	die 'Drenaje excedió 64 rondas'
}

action_scheduler_provider_expected_receipt() {
	jq -cnS '
	  {
	    schema:"akibara-action-scheduler-provider-inventory-v1",
	    version:"3.9.3",
	    mount_root:"/var/www/html",
	    files:[
	      {path:"wp-content/plugins/woocommerce/packages/action-scheduler/action-scheduler.php",sha256:"dad4196bd9696616495533b336ccd11e47ec33fab9be932d8742ba009f1a6710"},
	      {path:"wp-content/plugins/woocommerce/packages/action-scheduler/classes/ActionScheduler_DataController.php",sha256:"8ddab785a2b9594fa28564810082acd207a44c349a48e20f4a331b4f985789c6"},
	      {path:"wp-content/plugins/woocommerce/packages/action-scheduler/classes/abstracts/ActionScheduler_Abstract_QueueRunner.php",sha256:"63fc24b718339dd6baa3c718b1b2ef684fc423633a5604c68c53107271c5b95d"},
	      {path:"wp-content/plugins/woocommerce/packages/action-scheduler/classes/data-stores/ActionScheduler_HybridStore.php",sha256:"ee5d9e25c6cbbad1031423432d3bb380345baa13a1b7ea661edc5e35330827e9"},
	      {path:"wp-content/plugins/woocommerce/packages/action-scheduler/classes/migration/Runner.php",sha256:"f045a96129ba0936feed265a5284e1b5252f249629611c0074acc4924e7a92f6"},
	      {path:"wp-content/plugins/woocommerce/packages/action-scheduler/classes/migration/Scheduler.php",sha256:"3b7af0dd1d18edc7278a0b74bfd5c06a3230690956d8785fd0286bc4fcf3ebec"},
	      {path:"wp-content/plugins/woocommerce/packages/action-scheduler/classes/WP_CLI/Action/Run_Command.php",sha256:"81b104f6603ae276244bf2e0b1708d30b1f5a786bdaf123cac10ea30899cb502"}
	    ]
	  }
	'
}

assert_action_scheduler_runtime_provider() {
	local runtime_receipt="$1" expected_provider
	expected_provider="$(action_scheduler_provider_expected_receipt)"
	jq -e --argjson expected_provider "${expected_provider}" '
	  .provider_error == ""
	  and .provider == $expected_provider
	' <<< "${runtime_receipt}" >/dev/null \
		|| die 'Inventario proveedor Action Scheduler 3.9.3 divergente dentro del runtime montado'
}

capture_action_scheduler_runtime() {
	local wp_user="$1" wp_password="$2" search_tree="$3" business_tree="$4" runtime_log="$5"
	local container_name receipt receipt_count provider_expected
	provider_expected="$(action_scheduler_provider_expected_receipt)"
	container_name="akb-srch-0114-wp-${BASHPID}-${run_nonce}-$(openssl rand -hex 3)"
	wp_base_args "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${container_name}"
	WP_ARGS+=(--env AKB_AS_PROVIDER_EXPECTED_JSON="${provider_expected}" "${wpcli_image}")
	WP_ARGS+=(wp --path=/var/www/html eval '
$version       = "";
$store_class   = "";
$runtime_error = "";
$provider_error = "";
$provider = array(
    "schema"     => "",
    "version"    => "",
    "mount_root" => "/var/www/html",
    "files"      => array(),
);
$provider_raw = getenv( "AKB_AS_PROVIDER_EXPECTED_JSON" );
try {
    $provider_expected = json_decode( is_string( $provider_raw ) ? $provider_raw : "", true, 16, JSON_THROW_ON_ERROR );
    if ( ! is_array( $provider_expected )
        || "akibara-action-scheduler-provider-inventory-v1" !== ( $provider_expected["schema"] ?? null )
        || "3.9.3" !== ( $provider_expected["version"] ?? null )
        || "/var/www/html" !== ( $provider_expected["mount_root"] ?? null )
        || ! is_array( $provider_expected["files"] ?? null )
        || 7 !== count( $provider_expected["files"] ) ) {
        throw new RuntimeException( "provider_manifest_shape" );
    }
    $provider["schema"]  = $provider_expected["schema"];
    $provider["version"] = $provider_expected["version"];
    foreach ( $provider_expected["files"] as $index => $file ) {
        $path = is_array( $file ) && is_string( $file["path"] ?? null ) ? $file["path"] : "";
        $expected_sha = is_array( $file ) && is_string( $file["sha256"] ?? null ) ? $file["sha256"] : "";
        if ( ! is_array( $file ) || 2 !== count( $file )
            || 1 !== preg_match( "/\\Awp-content\\/plugins\\/woocommerce\\/packages\\/action-scheduler\\/[A-Za-z0-9_.\\/-]+\\.php\\z/D", $path )
            || false !== strpos( $path, ".." )
            || 1 !== preg_match( "/\\A[a-f0-9]{64}\\z/D", $expected_sha ) ) {
            throw new RuntimeException( "provider_manifest_entry:" . (string) $index );
        }
        $absolute = ABSPATH . $path;
        $actual_sha = is_file( $absolute ) && ! is_link( $absolute ) ? hash_file( "sha256", $absolute ) : false;
        $provider["files"][] = array(
            "path"   => $path,
            "sha256" => is_string( $actual_sha ) ? $actual_sha : "",
        );
        if ( ! is_string( $actual_sha ) && "" === $provider_error ) {
            $provider_error = "provider_file_unreadable:" . (string) $index;
        }
    }
} catch ( Throwable $throwable ) {
    $provider_error = "provider_exception:" . get_class( $throwable );
}
$sentinel      = new stdClass();
$migration     = get_option( "action_scheduler_migration_status", $sentinel );
$present       = $sentinel !== $migration;
$status_value  = $present && is_scalar( $migration ) ? (string) $migration : "";
try {
    if ( ! class_exists( "ActionScheduler_Versions", false )
        || ! class_exists( "ActionScheduler", false )
        || ! method_exists( "ActionScheduler", "store" ) ) {
        $runtime_error = "runtime_unavailable";
    } else {
        $version = (string) ActionScheduler_Versions::instance()->latest_version();
        $store   = ActionScheduler::store();
        $store_class = is_object( $store ) ? get_class( $store ) : "";
    }
} catch ( Throwable $throwable ) {
    $runtime_error = "runtime_exception:" . get_class( $throwable );
}
fwrite( STDOUT, "AKB_AS_RUNTIME_OBSERVED=" . wp_json_encode( array(
    "schema"           => "akibara-action-scheduler-runtime-v1",
    "version"          => $version,
    "store_class"      => $store_class,
    "migration_status" => array(
        "present" => $present,
        "value"   => $status_value,
    ),
    "runtime_error" => $runtime_error,
    "provider"      => $provider,
    "provider_error" => $provider_error,
) ) . "\n" );
')
	"${WP_ARGS[@]}" > "${runtime_log}" 2>&1 || die 'No se pudo observar el runtime Action Scheduler'
	chmod 600 "${runtime_log}"
	receipt_count="$(grep -c '^AKB_AS_RUNTIME_OBSERVED=' "${runtime_log}" || true)"
	[[ "${receipt_count}" == 1 ]] || die 'Receipt observado de Action Scheduler ausente o ambiguo'
	receipt="$(sed -n 's/^AKB_AS_RUNTIME_OBSERVED=//p' "${runtime_log}")"
	jq -e '
	  .schema == "akibara-action-scheduler-runtime-v1"
	  and (.version | type == "string")
	  and (.store_class | type == "string")
	  and (.migration_status | type == "object")
	  and (.migration_status.present | type == "boolean")
	  and (.migration_status.value | type == "string")
	  and (.runtime_error | type == "string")
	  and (.provider | type == "object")
	  and (.provider_error | type == "string")
	' <<< "${receipt}" >/dev/null || die 'Receipt observado de Action Scheduler no tiene forma segura'
	printf '%s\n' "${receipt}"
}

assert_action_scheduler_db_runtime() {
	local wp_user="$1" wp_password="$2" search_tree="$3" business_tree="$4" log_file="$5"
	local runtime_log receipt
	runtime_log="${log_file%.log}.action-scheduler-runtime.log"
	receipt="$(capture_action_scheduler_runtime "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${runtime_log}")"
	# El receipt observado se persiste antes de validar el estado esperado. Así un
	# mismatch conserva evidencia concreta en vez de fallar antes de imprimirla.
	printf 'action-scheduler-runtime=%s\n' "${receipt}" >> "${log_file}"
	assert_action_scheduler_runtime_provider "${receipt}"
	jq -e '
	  .schema == "akibara-action-scheduler-runtime-v1"
	  and .version == "3.9.3"
	  and .store_class == "ActionScheduler_DBStore"
	  and .migration_status == {present:true,value:"complete"}
	  and .runtime_error == ""
	' <<< "${receipt}" >/dev/null || die 'Runtime Action Scheduler DBStore/complete divergente'
}

live_pump_args_snapshot() {
	local args_hex="$1" snapshot
	[[ "${args_hex}" =~ ^([A-F0-9]{2})+$ ]] || die 'Args del pump live no tienen bytes hex válidos'
	if ! snapshot="$(AKB_LIVE_PUMP_ARGS_HEX="${args_hex}" php -r '
$hex = getenv("AKB_LIVE_PUMP_ARGS_HEX");
if (!is_string($hex) || 1 !== preg_match("/\\A(?:[A-F0-9]{2})+\\z/D", $hex)) {
    exit(2);
}
$raw = hex2bin($hex);
if (!is_string($raw)) {
    exit(3);
}
try {
    $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
} catch (Throwable $throwable) {
    exit(4);
}
$kind = "";
$barrier = "";
if ([] === $value && "[]" === $raw) {
    $kind = "empty";
} elseif (is_array($value)
    && ["barrier"] === array_keys($value)
    && is_string($value["barrier"])
    && "editorial_discount" === $value["barrier"]
    && $raw === json_encode(["barrier" => $value["barrier"]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) {
    $kind = "barrier";
    $barrier = $value["barrier"];
} else {
    exit(5);
}
echo json_encode([
    "schema" => "akibara-search-live-pump-args-v1",
    "kind" => $kind,
    "barrier" => $barrier,
    "raw_sha256" => hash("sha256", $raw),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
')"; then
		die 'Args del pump live rechazados fail-closed'
	fi
	jq -e '
      .schema == "akibara-search-live-pump-args-v1"
      and (.kind == "empty" or .kind == "barrier")
      and (.barrier | type == "string")
      and (if .kind == "empty" then .barrier == "" else .barrier == "editorial_discount" end)
      and (.raw_sha256 | test("^[a-f0-9]{64}$"))
    ' <<< "${snapshot}" >/dev/null || die 'Receipt de args del pump live inválido'
	printf '%s\n' "${snapshot}"
}

live_pump_schedule_snapshot() {
	local schedule_hex="$1" snapshot
	[[ "${schedule_hex}" =~ ^([A-F0-9]{2})+$ ]] || die 'Schedule del pump live no tiene bytes hex válidos'
	if ! snapshot="$(AKB_LIVE_PUMP_SCHEDULE_HEX="${schedule_hex}" php -r '
$hex = getenv("AKB_LIVE_PUMP_SCHEDULE_HEX");
if (!is_string($hex) || 1 !== preg_match("/\\A(?:[A-F0-9]{2})+\\z/D", $hex)) {
    exit(2);
}
$raw = hex2bin($hex);
if (!is_string($raw)) {
    exit(3);
}
$value = @unserialize($raw, ["allowed_classes" => false]);
if (!is_object($value)) {
    exit(4);
}
$shape = (array) $value;
$class = $shape["__PHP_Incomplete_Class_Name"] ?? "";
$kind = "";
$timestamp = 0;
if ("ActionScheduler_NullSchedule" === $class
    && ["__PHP_Incomplete_Class_Name"] === array_keys($shape)
    && "O:28:\"ActionScheduler_NullSchedule\":0:{}" === $raw) {
    $kind = "async";
} elseif ("ActionScheduler_SimpleSchedule" === $class) {
    $parent_key = "\0*\0scheduled_timestamp";
    $child_key = "\0ActionScheduler_SimpleSchedule\0timestamp";
    if (["__PHP_Incomplete_Class_Name", $parent_key, $child_key] !== array_keys($shape)
        || !is_int($shape[$parent_key])
        || !is_int($shape[$child_key])
        || $shape[$parent_key] < 1000000000
        || $shape[$parent_key] !== $shape[$child_key]) {
        exit(5);
    }
    $timestamp = $shape[$parent_key];
    $expected = "O:30:\"ActionScheduler_SimpleSchedule\":2:{s:22:\"" . $parent_key
        . "\";i:" . $timestamp . ";s:41:\"" . $child_key . "\";i:" . $timestamp . ";}";
    if ($raw !== $expected) {
        exit(6);
    }
    $kind = "single";
} else {
    exit(7);
}
echo json_encode([
    "schema" => "akibara-search-live-pump-schedule-v1",
    "kind" => $kind,
    "timestamp" => $timestamp,
    "raw_sha256" => hash("sha256", $raw),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
')"; then
		die 'Schedule del pump live rechazado fail-closed'
	fi
	jq -e '
      .schema == "akibara-search-live-pump-schedule-v1"
      and (.kind == "async" or .kind == "single")
      and (.timestamp | type == "number")
      and (if .kind == "async" then .timestamp == 0 else .timestamp >= 1000000000 end)
      and (.raw_sha256 | test("^[a-f0-9]{64}$"))
    ' <<< "${snapshot}" >/dev/null || die 'Receipt de schedule del pump live inválido'
	printf '%s\n' "${snapshot}"
}

live_pump_action_snapshot() {
	local wp_user="$1" wp_password="$2" action_id="$3" expected_status="$4" row
	local action_hook action_status args_hex action_group scheduled_epoch claim_id priority attempts extended_is_null schedule_hex
	local args_snapshot schedule_snapshot args_kind schedule_kind schedule_timestamp wake_kind
	[[ "${action_id}" =~ ^[1-9][0-9]*$ ]] || die 'Action ID del pump live inválido'
	[[ "${expected_status}" == pending || "${expected_status}" == complete ]] || die 'Status esperado del pump live inválido'
	row="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
		"SELECT a.hook, a.status, HEX(a.args), COALESCE(g.slug,''), COALESCE(UNIX_TIMESTAMP(a.scheduled_date_gmt),0), a.claim_id, a.priority, a.attempts, IF(a.extended_args IS NULL,1,0), HEX(a.schedule) FROM wp_actionscheduler_actions a LEFT JOIN wp_actionscheduler_groups g ON g.group_id=a.group_id WHERE a.action_id=${action_id};")"
	[[ "$(printf '%s\n' "${row}" | grep -c . || true)" == 1 ]] || die 'Action del pump live ausente o ambigua'
	IFS=$'\t' read -r action_hook action_status args_hex action_group scheduled_epoch claim_id priority attempts extended_is_null schedule_hex <<< "${row}"
	[[ "${action_hook}" == akb_search_pump_live \
		&& "${action_status}" == "${expected_status}" \
		&& "${action_group}" == akibara-search \
		&& "${scheduled_epoch}" =~ ^[0-9]{10}$ \
		&& "${claim_id}" == 0 \
		&& "${priority}" == 10 \
		&& "${attempts}" =~ ^[0-9]+$ \
		&& "${extended_is_null}" == 1 ]] \
		|| die "Action ${action_id} no es un pump live ${expected_status} exacto del grupo"
	args_snapshot="$(live_pump_args_snapshot "${args_hex}")"
	schedule_snapshot="$(live_pump_schedule_snapshot "${schedule_hex}")"
	args_kind="$(jq -r '.kind' <<< "${args_snapshot}")"
	schedule_kind="$(jq -r '.kind' <<< "${schedule_snapshot}")"
	schedule_timestamp="$(jq -r '.timestamp' <<< "${schedule_snapshot}")"
	if [[ "${args_kind}" == empty && "${schedule_kind}" == async ]]; then
		wake_kind='async-empty'
	elif [[ "${args_kind}" == barrier && "${schedule_kind}" == single \
		&& "${schedule_timestamp}" == "${scheduled_epoch}" ]]; then
		wake_kind='editorial-barrier'
	else
		die 'Args y schedule del pump live no forman una pareja autorizada'
	fi
	jq -cnS \
		--argjson action_id "${action_id}" \
		--argjson scheduled_epoch "${scheduled_epoch}" \
		--arg status "${action_status}" \
		--argjson attempts "${attempts}" \
		--arg wake_kind "${wake_kind}" \
		--argjson args "${args_snapshot}" \
		--argjson schedule "${schedule_snapshot}" \
		'{schema:"akibara-search-live-pump-action-v1",action_id:$action_id,hook:"akb_search_pump_live",status:$status,group:"akibara-search",scheduled_epoch:$scheduled_epoch,claim_id:0,priority:10,attempts:$attempts,extended_args_null:true,wake_kind:$wake_kind,args:$args,schedule:$schedule}'
}

action_scheduler_migration_active_snapshot() {
	local wp_user="$1" wp_password="$2" row active unsafe
	row="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
		"SELECT COUNT(*), COALESCE(SUM(BINARY a.hook<>'action_scheduler/migration_hook' OR BINARY COALESCE(g.slug,'')<>'action-scheduler-migration' OR BINARY a.status<>'pending' OR a.claim_id<>0 OR a.priority<>10 OR a.attempts<>0 OR a.extended_args IS NOT NULL OR BINARY HEX(COALESCE(a.args,''))<>'5B5D'),0) FROM wp_actionscheduler_actions a LEFT JOIN wp_actionscheduler_groups g ON g.group_id=a.group_id WHERE a.status IN ('pending','in-progress') AND (BINARY a.hook='action_scheduler/migration_hook' OR BINARY COALESCE(g.slug,'')='action-scheduler-migration');")"
	[[ "$(printf '%s\n' "${row}" | grep -c . || true)" == 1 ]] \
		|| die 'Snapshot de migration hooks activos ausente o ambiguo'
	IFS=$'\t' read -r active unsafe <<< "${row}"
	[[ "${active}" =~ ^[0-9]+$ && "${unsafe}" =~ ^[0-9]+$ && "${unsafe}" -le "${active}" ]] \
		|| die 'Snapshot de migration hooks activos inválido'
	printf '%s\t%s\n' "${active}" "${unsafe}"
}

action_scheduler_migration_action_snapshot() {
	local wp_user="$1" wp_password="$2" action_id="$3" expected_status="$4" row
	local action_hook action_status args_hex action_group scheduled_epoch claim_id priority attempts extended_is_null schedule_hex
	local schedule_snapshot schedule_kind schedule_timestamp
	[[ "${action_id}" =~ ^[1-9][0-9]*$ ]] || die 'Action ID de migración Action Scheduler inválido'
	[[ "${expected_status}" == pending || "${expected_status}" == complete ]] \
		|| die 'Status esperado de migración Action Scheduler inválido'
	row="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
		"SELECT a.hook, a.status, HEX(a.args), COALESCE(g.slug,''), COALESCE(UNIX_TIMESTAMP(a.scheduled_date_gmt),0), a.claim_id, a.priority, a.attempts, IF(a.extended_args IS NULL,1,0), HEX(a.schedule) FROM wp_actionscheduler_actions a LEFT JOIN wp_actionscheduler_groups g ON g.group_id=a.group_id WHERE a.action_id=${action_id};")"
	[[ "$(printf '%s\n' "${row}" | grep -c . || true)" == 1 ]] \
		|| die 'Action de migración Action Scheduler ausente o ambigua'
	IFS=$'\t' read -r action_hook action_status args_hex action_group scheduled_epoch claim_id priority attempts extended_is_null schedule_hex <<< "${row}"
	[[ "${action_hook}" == action_scheduler/migration_hook \
		&& "${action_status}" == "${expected_status}" \
		&& "${args_hex}" == 5B5D \
		&& "${action_group}" == action-scheduler-migration \
		&& "${scheduled_epoch}" =~ ^[0-9]{10}$ \
		&& "${claim_id}" == 0 \
		&& "${priority}" == 10 \
		&& "${attempts}" =~ ^[0-9]+$ \
		&& "${extended_is_null}" == 1 ]] \
		|| die "Action ${action_id} no es la migration hook ${expected_status} exacta"
	if [[ "${expected_status}" == pending ]]; then
		[[ "${attempts}" == 0 ]] || die 'Migration hook pending ya tiene intentos'
	else
		[[ "${attempts}" == 1 ]] || die 'Migration hook complete no tiene un intento exacto'
	fi
	schedule_snapshot="$(live_pump_schedule_snapshot "${schedule_hex}")"
	schedule_kind="$(jq -r '.kind' <<< "${schedule_snapshot}")"
	schedule_timestamp="$(jq -r '.timestamp' <<< "${schedule_snapshot}")"
	[[ "${schedule_kind}" == single && "${schedule_timestamp}" == "${scheduled_epoch}" ]] \
		|| die 'Migration hook no conserva un schedule single ligado a scheduled_date_gmt'
	jq -cnS \
		--argjson action_id "${action_id}" \
		--arg status "${action_status}" \
		--argjson scheduled_epoch "${scheduled_epoch}" \
		--argjson attempts "${attempts}" \
		--argjson schedule "${schedule_snapshot}" \
		'{schema:"akibara-action-scheduler-migration-action-v1",action_id:$action_id,hook:"action_scheduler/migration_hook",status:$status,group:"action-scheduler-migration",scheduled_epoch:$scheduled_epoch,claim_id:0,priority:10,attempts:$attempts,extended_args_null:true,args_hex:"5B5D",schedule:$schedule}'
}

run_action_scheduler_action_id() {
	local wp_user="$1" wp_password="$2" search_tree="$3" business_tree="$4" action_id="$5" log_file="$6"
	local container_name
	[[ "${action_id}" =~ ^[1-9][0-9]*$ ]] || die 'Action ID explícito de Action Scheduler inválido'
	container_name="akb-srch-0114-wp-${BASHPID}-${run_nonce}-$(openssl rand -hex 3)"
	wp_base_args "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${container_name}"
	WP_ARGS+=("${wpcli_image}")
	WP_ARGS+=(wp --path=/var/www/html action-scheduler action run "${action_id}")
	"${WP_ARGS[@]}" >> "${log_file}" 2>&1 \
		|| die 'Action Scheduler falló al correr la migration hook por ID explícito'
}

action_scheduler_stabilization_receipt_json() {
	local mode="$1" action_id="$2" initial_runtime="$3" final_runtime="$4"
	local actions_executed execution_scope final_observation initial_summary final_summary
	local initial_provider final_provider provider_inventory_sha256
	case "${mode}" in
		dbstore-fast-path)
			actions_executed=0
			execution_scope='none'
			final_observation='initial-fast-path'
			[[ "${action_id}" == 0 ]] || die 'Fast path no puede acreditar action_id'
			;;
		hybrid-migration)
			actions_executed=1
			execution_scope='explicit-action-id-only'
			final_observation='fresh-post-action-process'
			[[ "${action_id}" =~ ^[1-9][0-9]*$ ]] || die 'Migración Hybrid requiere action_id positivo'
			;;
		*) die 'Modo de estabilización Action Scheduler inválido' ;;
	esac
	initial_summary="$(jq -cS '{version,store_class,migration_status}' <<< "${initial_runtime}")"
	final_summary="$(jq -cS '{version,store_class,migration_status}' <<< "${final_runtime}")"
	initial_provider="$(jq -cS '.provider' <<< "${initial_runtime}")"
	final_provider="$(jq -cS '.provider' <<< "${final_runtime}")"
	[[ "${initial_provider}" == "${final_provider}" ]] \
		|| die 'Inventario proveedor cambió durante la estabilización Action Scheduler'
	provider_inventory_sha256="$(printf '%s' "${initial_provider}" | sha256_stdin)"
	[[ "${provider_inventory_sha256}" =~ ^[a-f0-9]{64}$ ]] || die 'Hash de inventario proveedor inválido'
	jq -e --arg mode "${mode}" '
	  if $mode == "dbstore-fast-path" then
	    .version == "3.9.3"
	    and .store_class == "ActionScheduler_DBStore"
	    and .migration_status == {present:true,value:"complete"}
	  else
	    .version == "3.9.3"
	    and .store_class == "ActionScheduler_HybridStore"
	    and .migration_status == {present:false,value:""}
	  end
	' <<< "${initial_summary}" >/dev/null || die 'Runtime inicial no corresponde al modo acreditado'
	jq -e '
	  .version == "3.9.3"
	  and .store_class == "ActionScheduler_DBStore"
	  and .migration_status == {present:true,value:"complete"}
	' <<< "${final_summary}" >/dev/null || die 'Runtime final no acredita DBStore/complete'
	jq -cnS \
		--arg mode "${mode}" \
		--arg execution_scope "${execution_scope}" \
		--arg final_observation "${final_observation}" \
		--argjson actions_executed "${actions_executed}" \
		--argjson action_id "${action_id}" \
		--argjson initial_runtime "${initial_summary}" \
		--argjson final_runtime "${final_summary}" \
		--arg provider_inventory_sha256 "${provider_inventory_sha256}" \
		'{schema:"akibara-action-scheduler-stabilization-v1",mode:$mode,actions_executed:$actions_executed,action_id:$action_id,execution_scope:$execution_scope,initial_runtime:$initial_runtime,final_runtime:$final_runtime,final_observation:$final_observation,provider_inventory_sha256:$provider_inventory_sha256,provider_files:7}'
}

stabilize_action_scheduler_db_runtime() {
	local wp_user="$1" wp_password="$2" search_tree="$3" business_tree="$4" log_file="$5"
	local log_stem initial_runtime_log final_runtime_log initial_runtime final_runtime transition_row
	local option_rows legacy_posts active_snapshot active_actions unsafe_actions action_ids action_id
	local action_before action_revalidated action_after scheduled_epoch due_row action_due final_db_row complete_rows
	local before_identity after_identity active_after_snapshot active_after unsafe_after stabilization_receipt
	log_stem="${log_file%.log}"
	initial_runtime_log="${log_stem}.initial-runtime.log"
	final_runtime_log="${log_stem}.final-runtime.log"
	: > "${log_file}"
	chmod 600 "${log_file}"

	initial_runtime="$(capture_action_scheduler_runtime "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${initial_runtime_log}")"
	printf 'runtime-initial=%s\n' "${initial_runtime}" >> "${log_file}"
	assert_action_scheduler_runtime_provider "${initial_runtime}"
	if jq -e '
	  .schema == "akibara-action-scheduler-runtime-v1"
	  and .version == "3.9.3"
	  and .store_class == "ActionScheduler_DBStore"
	  and .migration_status == {present:true,value:"complete"}
	  and .runtime_error == ""
	' <<< "${initial_runtime}" >/dev/null; then
		stabilization_receipt="$(action_scheduler_stabilization_receipt_json dbstore-fast-path 0 "${initial_runtime}" "${initial_runtime}")"
		printf 'AKB_AS_STABILIZATION_RECEIPT=%s\n' "${stabilization_receipt}" >> "${log_file}"
		printf 'result=PASS\tmode=dbstore-fast-path\tactions_executed=0\n' >> "${log_file}"
		return 0
	fi
	jq -e '
	  .schema == "akibara-action-scheduler-runtime-v1"
	  and .version == "3.9.3"
	  and .store_class == "ActionScheduler_HybridStore"
	  and .migration_status == {present:false,value:""}
	  and .runtime_error == ""
	' <<< "${initial_runtime}" >/dev/null \
		|| die 'Transición Action Scheduler no es DBStore/complete ni HybridStore/option-ausente exacta'

	transition_row="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
		"SELECT (SELECT COUNT(*) FROM wp_options WHERE BINARY option_name='action_scheduler_migration_status'), (SELECT COUNT(*) FROM wp_posts WHERE BINARY post_type='scheduled-action');")"
	printf 'transition-db=%s\n' "${transition_row}" >> "${log_file}"
	[[ "$(printf '%s\n' "${transition_row}" | grep -c . || true)" == 1 ]] \
		|| die 'Prueba DB de transición Action Scheduler ausente o ambigua'
	IFS=$'\t' read -r option_rows legacy_posts <<< "${transition_row}"
	[[ "${option_rows}" == 0 && "${legacy_posts}" == 0 ]] \
		|| die 'Transición HybridStore no conserva option ausente y cero scheduled-action legacy'

	active_snapshot="$(action_scheduler_migration_active_snapshot "${wp_user}" "${wp_password}")"
	printf 'migration-active-before=%s\n' "${active_snapshot}" >> "${log_file}"
	IFS=$'\t' read -r active_actions unsafe_actions <<< "${active_snapshot}"
	[[ "${active_actions}" == 1 && "${unsafe_actions}" == 0 ]] \
		|| die 'Transición HybridStore no expone una única migration hook pending segura'
	action_ids="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
		"SELECT a.action_id FROM wp_actionscheduler_actions a INNER JOIN wp_actionscheduler_groups g ON g.group_id=a.group_id WHERE BINARY a.hook='action_scheduler/migration_hook' AND BINARY a.status='pending' AND a.claim_id=0 AND a.priority=10 AND a.attempts=0 AND a.extended_args IS NULL AND BINARY HEX(COALESCE(a.args,''))='5B5D' AND BINARY g.slug='action-scheduler-migration' ORDER BY a.action_id ASC;")"
	[[ "$(printf '%s\n' "${action_ids}" | grep -c . || true)" == 1 ]] \
		|| die 'Selector exacto no resolvió una única migration hook por ID'
	action_id="$(printf '%s' "${action_ids}" | tr -d '[:space:]')"
	[[ "${action_id}" =~ ^[1-9][0-9]*$ ]] || die 'Selector devolvió un ID de migración inválido'
	action_before="$(action_scheduler_migration_action_snapshot "${wp_user}" "${wp_password}" "${action_id}" pending)"
	printf 'migration-action-before=%s\n' "${action_before}" >> "${log_file}"
	scheduled_epoch="$(jq -r '.scheduled_epoch' <<< "${action_before}")"
	wait_until_epoch "${scheduled_epoch}"
	action_revalidated="$(action_scheduler_migration_action_snapshot "${wp_user}" "${wp_password}" "${action_id}" pending)"
	[[ "${action_before}" == "${action_revalidated}" ]] \
		|| die 'Migration hook cambió entre validación y ejecución'
	due_row="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
		"SELECT (SELECT COUNT(*) FROM wp_actionscheduler_actions a INNER JOIN wp_actionscheduler_groups g ON g.group_id=a.group_id WHERE a.action_id=${action_id} AND BINARY a.hook='action_scheduler/migration_hook' AND BINARY a.status='pending' AND a.claim_id=0 AND a.priority=10 AND a.attempts=0 AND a.extended_args IS NULL AND BINARY HEX(COALESCE(a.args,''))='5B5D' AND a.scheduled_date_gmt<=UTC_TIMESTAMP() AND BINARY g.slug='action-scheduler-migration'), (SELECT COUNT(*) FROM wp_options WHERE BINARY option_name='action_scheduler_migration_status'), (SELECT COUNT(*) FROM wp_posts WHERE BINARY post_type='scheduled-action');")"
	printf 'migration-due-db=%s\n' "${due_row}" >> "${log_file}"
	[[ "$(printf '%s\n' "${due_row}" | grep -c . || true)" == 1 ]] \
		|| die 'Prueba DB de vencimiento de migración ausente o ambigua'
	IFS=$'\t' read -r action_due option_rows legacy_posts <<< "${due_row}"
	[[ "${action_due}" == 1 && "${option_rows}" == 0 && "${legacy_posts}" == 0 ]] \
		|| die 'Migration hook no está due o cambió la precondición option/legacy'

	run_action_scheduler_action_id "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${action_id}" "${log_file}"
	action_after="$(action_scheduler_migration_action_snapshot "${wp_user}" "${wp_password}" "${action_id}" complete)"
	printf 'migration-action-after=%s\n' "${action_after}" >> "${log_file}"
	before_identity="$(jq -cS 'del(.status,.attempts)' <<< "${action_before}")"
	after_identity="$(jq -cS 'del(.status,.attempts)' <<< "${action_after}")"
	[[ "${before_identity}" == "${after_identity}" ]] \
		|| die 'Migration hook no terminó complete con identidad exacta'
	active_after_snapshot="$(action_scheduler_migration_active_snapshot "${wp_user}" "${wp_password}")"
	printf 'migration-active-after=%s\n' "${active_after_snapshot}" >> "${log_file}"
	IFS=$'\t' read -r active_after unsafe_after <<< "${active_after_snapshot}"
	[[ "${active_after}" == 0 && "${unsafe_after}" == 0 ]] \
		|| die 'Persisten migration hooks activas tras ejecutar el ID exacto'
	final_db_row="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
		"SELECT (SELECT COUNT(*) FROM wp_options WHERE BINARY option_name='action_scheduler_migration_status'), (SELECT COUNT(*) FROM wp_options WHERE BINARY option_name='action_scheduler_migration_status' AND BINARY option_value='complete'), (SELECT COUNT(*) FROM wp_posts WHERE BINARY post_type='scheduled-action');")"
	printf 'migration-final-db=%s\n' "${final_db_row}" >> "${log_file}"
	[[ "$(printf '%s\n' "${final_db_row}" | grep -c . || true)" == 1 ]] \
		|| die 'Prueba DB final de migración ausente o ambigua'
	IFS=$'\t' read -r option_rows complete_rows legacy_posts <<< "${final_db_row}"
	[[ "${option_rows}" == 1 && "${complete_rows}" == 1 && "${legacy_posts}" == 0 ]] \
		|| die 'Migration hook no persistió una option complete exacta'

	# La instancia HybridStore del proceso ejecutor no puede acreditar el destino.
	# Un boot WP-CLI nuevo debe observar DBStore/complete antes de continuar.
	final_runtime="$(capture_action_scheduler_runtime "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${final_runtime_log}")"
	printf 'runtime-final=%s\n' "${final_runtime}" >> "${log_file}"
	assert_action_scheduler_runtime_provider "${final_runtime}"
	jq -e '
	  .schema == "akibara-action-scheduler-runtime-v1"
	  and .version == "3.9.3"
	  and .store_class == "ActionScheduler_DBStore"
	  and .migration_status == {present:true,value:"complete"}
	  and .runtime_error == ""
	' <<< "${final_runtime}" >/dev/null \
		|| die 'Proceso nuevo no observó Action Scheduler DBStore/complete'
	stabilization_receipt="$(action_scheduler_stabilization_receipt_json hybrid-migration "${action_id}" "${initial_runtime}" "${final_runtime}")"
	printf 'AKB_AS_STABILIZATION_RECEIPT=%s\n' "${stabilization_receipt}" >> "${log_file}"
	printf 'result=PASS\tmode=hybrid-migration\tactions_executed=1\taction_id=%s\n' "${action_id}" >> "${log_file}"
}

action_scheduler_stabilization_receipt_from_log() {
	local log_file="$1" receipt_count receipt expected_provider provider_inventory_sha256
	[[ -f "${log_file}" && ! -L "${log_file}" ]] || die 'Log de estabilización Action Scheduler inválido'
	receipt_count="$(grep -c '^AKB_AS_STABILIZATION_RECEIPT=' "${log_file}" || true)"
	[[ "${receipt_count}" == 1 ]] || die 'Receipt de estabilización Action Scheduler ausente o ambiguo'
	receipt="$(sed -n 's/^AKB_AS_STABILIZATION_RECEIPT=//p' "${log_file}")"
	expected_provider="$(action_scheduler_provider_expected_receipt)"
	provider_inventory_sha256="$(printf '%s' "${expected_provider}" | sha256_stdin)"
	jq -e --arg provider_inventory_sha256 "${provider_inventory_sha256}" '
	  (keys|sort) == ["action_id","actions_executed","execution_scope","final_observation","final_runtime","initial_runtime","mode","provider_files","provider_inventory_sha256","schema"]
	  and .schema == "akibara-action-scheduler-stabilization-v1"
	  and (.mode == "dbstore-fast-path" or .mode == "hybrid-migration")
	  and .provider_inventory_sha256 == $provider_inventory_sha256
	  and .provider_files == 7
	  and .final_runtime == {version:"3.9.3",store_class:"ActionScheduler_DBStore",migration_status:{present:true,value:"complete"}}
	  and (
	    if .mode == "dbstore-fast-path" then
	      .actions_executed == 0
	      and .action_id == 0
	      and .execution_scope == "none"
	      and .final_observation == "initial-fast-path"
	      and .initial_runtime == .final_runtime
	    else
	      .actions_executed == 1
	      and (.action_id | type == "number" and floor == . and . > 0)
	      and .execution_scope == "explicit-action-id-only"
	      and .final_observation == "fresh-post-action-process"
	      and .initial_runtime == {version:"3.9.3",store_class:"ActionScheduler_HybridStore",migration_status:{present:false,value:""}}
	    end
	  )
	' <<< "${receipt}" >/dev/null || die 'Receipt de estabilización Action Scheduler falso o divergente'
	printf '%s\n' "${receipt}"
}

live_work_snapshot() {
	local wp_user="$1" wp_password="$2" row
	local total outstanding pending leased blocked acked unknown errors available_epoch
	row="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
		"SELECT COUNT(*), COALESCE(SUM(BINARY status<>'acked'),0), COALESCE(SUM(BINARY status='pending'),0), COALESCE(SUM(BINARY status='leased'),0), COALESCE(SUM(BINARY status='blocked'),0), COALESCE(SUM(BINARY status='acked'),0), COALESCE(SUM(BINARY status NOT IN ('pending','leased','blocked','acked')),0), COALESCE(SUM(COALESCE(last_error,'')<>''),0), COALESCE(UNIX_TIMESTAMP(MIN(CASE WHEN BINARY status='pending' THEN available_at END)),0) FROM wp_akb_search_work WHERE BINARY scope='live' AND BINARY run_id='';")"
	[[ "$(printf '%s\n' "${row}" | grep -c . || true)" == 1 ]] || die 'Snapshot work live ausente o ambiguo'
	IFS=$'\t' read -r total outstanding pending leased blocked acked unknown errors available_epoch <<< "${row}"
	for value in "${total}" "${outstanding}" "${pending}" "${leased}" "${blocked}" "${acked}" "${unknown}" "${errors}" "${available_epoch}"; do
		[[ "${value}" =~ ^[0-9]+$ ]] || die 'Snapshot work live contiene un conteo inválido'
	done
	(( total == pending + leased + blocked + acked + unknown )) || die 'Snapshot work live no reconcilia estados'
	(( outstanding == pending + leased + blocked + unknown )) || die 'Snapshot work live no reconcilia deuda'
	printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
		"${total}" "${outstanding}" "${pending}" "${leased}" "${blocked}" "${acked}" "${unknown}" "${errors}" "${available_epoch}"
}

live_pump_active_snapshot() {
	local wp_user="$1" wp_password="$2" row active unsafe
	row="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
		"SELECT COUNT(*), COALESCE(SUM(BINARY a.hook<>'akb_search_pump_live' OR BINARY g.slug<>'akibara-search' OR BINARY a.status NOT IN ('pending','in-progress') OR BINARY a.status='in-progress' OR a.claim_id<>0 OR a.priority<>10 OR a.extended_args IS NOT NULL),0) FROM wp_actionscheduler_actions a INNER JOIN wp_actionscheduler_groups g ON g.group_id=a.group_id WHERE a.hook='akb_search_pump_live' AND a.status IN ('pending','in-progress') AND g.slug='akibara-search';")"
	[[ "$(printf '%s\n' "${row}" | grep -c . || true)" == 1 ]] || die 'Snapshot de pumps activos ausente o ambiguo'
	IFS=$'\t' read -r active unsafe <<< "${row}"
	[[ "${active}" =~ ^[0-9]+$ && "${unsafe}" =~ ^[0-9]+$ && "${unsafe}" -le "${active}" ]] \
		|| die 'Snapshot de pumps activos inválido'
	printf '%s\t%s\n' "${active}" "${unsafe}"
}

drain_live_work() {
	local wp_user="$1" wp_password="$2" search_tree="$3" business_tree="$4" log_file="$5"
	local round before after total outstanding pending leased blocked acked unknown errors available_epoch
	local after_total after_outstanding after_pending after_leased after_blocked after_acked after_unknown after_errors after_available
	local option_rows option_rows_after active_snapshot active_actions unsafe_actions expect_progress delta
	local action_id action_before action_revalidated action_after scheduled_epoch target container_name
	local due_row action_due work_due before_attempts after_attempts before_identity after_identity
	: > "${log_file}"
	chmod 600 "${log_file}"
	assert_action_scheduler_db_runtime "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${log_file}"
	for (( round = 1; round <= 128; ++round )); do
		option_rows="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
			"SELECT COUNT(*) FROM wp_options WHERE option_name IN ('akb_search_rebuild_state','akb_search_pending_rebuild');" | tr -d '[:space:]')"
		[[ "${option_rows}" == 0 ]] || die 'Work live no se drena mientras state/pending esté activo'
		before="$(live_work_snapshot "${wp_user}" "${wp_password}")"
		IFS=$'\t' read -r total outstanding pending leased blocked acked unknown errors available_epoch <<< "${before}"
		printf 'round=%d\twork-before=%s\n' "${round}" "${before}" >> "${log_file}"
		(( 0 == leased && 0 == blocked && 0 == unknown && 0 == errors )) \
			|| die 'Work live contiene lease, bloqueo, status desconocido o error antes del pump'
		active_snapshot="$(live_pump_active_snapshot "${wp_user}" "${wp_password}")"
		IFS=$'\t' read -r active_actions unsafe_actions <<< "${active_snapshot}"
		(( 0 == unsafe_actions )) || die 'Existe un pump live claimed, in-progress o con identidad insegura'
		if (( 0 == outstanding )); then
			(( 0 == pending )) || die 'Work live sin deuda conserva pending'
			if (( 0 == active_actions )); then
				printf 'result=PASS\trounds=%d\tacked=%d\n' "$(( round - 1 ))" "${acked}" >> "${log_file}"
				return 0
			fi
			expect_progress=0
		else
			expect_progress=1
			(( outstanding == pending && available_epoch >= 1000000000 )) \
				|| die 'Deuda live no es pending o no tiene available_at válido'
		fi

		action_id="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
			"SELECT a.action_id FROM wp_actionscheduler_actions a INNER JOIN wp_actionscheduler_groups g ON g.group_id=a.group_id WHERE BINARY a.hook='akb_search_pump_live' AND BINARY a.status='pending' AND a.claim_id=0 AND a.priority=10 AND a.extended_args IS NULL AND BINARY g.slug='akibara-search' ORDER BY a.attempts ASC, a.scheduled_date_gmt ASC, a.action_id ASC LIMIT 1;" | tr -d '[:space:]')"
		[[ "${action_id}" =~ ^[1-9][0-9]*$ ]] || die 'Deuda live sin action pump pending exacta'
		action_before="$(live_pump_action_snapshot "${wp_user}" "${wp_password}" "${action_id}" pending)"
		scheduled_epoch="$(jq -r '.scheduled_epoch' <<< "${action_before}")"
		target="${scheduled_epoch}"
		(( 1 == expect_progress && available_epoch > target )) && target="${available_epoch}"
		wait_until_epoch "${target}"
		action_revalidated="$(live_pump_action_snapshot "${wp_user}" "${wp_password}" "${action_id}" pending)"
		[[ "${action_before}" == "${action_revalidated}" ]] || die 'Action pump cambió entre validación y ejecución'
		due_row="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
			"SELECT (SELECT COUNT(*) FROM wp_actionscheduler_actions a INNER JOIN wp_actionscheduler_groups g ON g.group_id=a.group_id WHERE a.action_id=${action_id} AND BINARY a.hook='akb_search_pump_live' AND BINARY a.status='pending' AND a.claim_id=0 AND a.priority=10 AND a.extended_args IS NULL AND a.scheduled_date_gmt<=UTC_TIMESTAMP() AND BINARY g.slug='akibara-search'), (SELECT COUNT(*) FROM wp_akb_search_work WHERE BINARY scope='live' AND BINARY run_id='' AND BINARY status='pending' AND available_at<=UTC_TIMESTAMP());")"
		IFS=$'\t' read -r action_due work_due <<< "${due_row}"
		[[ "${action_due}" == 1 && "${work_due}" =~ ^[0-9]+$ ]] || die 'Prueba DB de vencimiento live inválida'
		if (( 1 == expect_progress )); then
			(( work_due > 0 )) || die 'Work live aún no está due en la DB'
		else
			(( 0 == work_due )) || die 'Cleanup no-op observó work live due inesperado'
		fi
		printf 'action=%s\n' "${action_before}" >> "${log_file}"

		container_name="akb-srch-0114-wp-${BASHPID}-${run_nonce}-$(openssl rand -hex 3)"
		wp_base_args "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${container_name}"
		WP_ARGS+=("${wpcli_image}")
		WP_ARGS+=(wp --path=/var/www/html action-scheduler action run "${action_id}")
		"${WP_ARGS[@]}" >> "${log_file}" 2>&1 || die 'Action Scheduler falló al correr el pump live validado'
		action_after="$(live_pump_action_snapshot "${wp_user}" "${wp_password}" "${action_id}" complete)"
		before_attempts="$(jq -r '.attempts' <<< "${action_before}")"
		after_attempts="$(jq -r '.attempts' <<< "${action_after}")"
		before_identity="$(jq -cS 'del(.status,.attempts)' <<< "${action_before}")"
		after_identity="$(jq -cS 'del(.status,.attempts)' <<< "${action_after}")"
		[[ "${before_identity}" == "${after_identity}" && "${after_attempts}" -eq $(( before_attempts + 1 )) ]] \
			|| die 'Action pump no terminó complete con identidad/attempts exactos'
		printf 'action-result=%s\n' "${action_after}" >> "${log_file}"

		after="$(live_work_snapshot "${wp_user}" "${wp_password}")"
		IFS=$'\t' read -r after_total after_outstanding after_pending after_leased after_blocked after_acked after_unknown after_errors after_available <<< "${after}"
		printf 'round=%d\twork-after=%s\n' "${round}" "${after}" >> "${log_file}"
		option_rows_after="$(ephemeral_wp_query "${wp_user}" "${wp_password}" \
			"SELECT COUNT(*) FROM wp_options WHERE option_name IN ('akb_search_rebuild_state','akb_search_pending_rebuild');" | tr -d '[:space:]')"
		[[ "${option_rows_after}" == 0 ]] || die 'Pump live reabrió state/pending durante la ejecución'
		if (( 1 == expect_progress )); then
			delta=$(( outstanding - after_outstanding ))
			(( 0 == after_leased && 0 == after_blocked && 0 == after_unknown && 0 == after_errors \
				&& delta >= 1 && delta <= 25 && after_total == total \
				&& after_pending == pending - delta && after_acked == acked + delta )) \
				|| die 'Pump live no produjo progreso durable exacto'
			printf 'round=%d\tmode=progress\tdelta=%d\n' "${round}" "${delta}" >> "${log_file}"
		else
			[[ "${after}" == "${before}" ]] || die 'Cleanup de pump residual mutó el work ya convergido'
			printf 'round=%d\tmode=noop-residual\n' "${round}" >> "${log_file}"
		fi
	done
	die 'Drenaje live excedió 128 acciones explícitas'
}

run_ai_discoverability_audit() {
	local iteration="$1" http_container="$2" iteration_dir="$3"
	local runner_container report stderr_log fetch_base
	runner_container="akb-srch-0114-ai-http-${BASHPID}-${run_nonce}-${iteration}"
	report="${iteration_dir}/ai-discoverability-audit.json"
	stderr_log="${iteration_dir}/ai-discoverability-audit.stderr"
	fetch_base="http://${http_container}/"
	if ! docker run --rm --name "${runner_container}" --network "${active_network}" \
		--label "akibara.recovery.run=${run_label}" \
		--read-only --tmpfs /audit:rw,nosuid,nodev,noexec,size=256m \
		--user 65534:65534 \
		--volume "${ai_audit_engine}:/opt/akibara/akibara-ai-discoverability-audit.mjs:ro" \
		--volume "${ai_audit_policy}:/opt/akibara/lib/ai-discoverability-policy.mjs:ro" \
		--entrypoint node "${node_image}" /opt/akibara/akibara-ai-discoverability-audit.mjs \
		--work-dir /audit \
		--base-url https://akibara.cl/ \
		--fetch-base-url "${fetch_base}" \
		--expected-pickup-locality 'San Miguel' \
		--expected-ai-train yes \
		--timeout-ms 20000 --concurrency 5 --max-sitemaps 200 --sitemap-ua-scope all \
		> "${report}" 2> "${stderr_log}"; then
		chmod 600 "${report}" "${stderr_log}"
		docker logs "${http_container}" > "${iteration_dir}/http-server-ai-audit-failure.log" 2>&1 || true
		chmod 600 "${iteration_dir}/http-server-ai-audit-failure.log"
		die "Auditor IA integrado falló en fresh restore ${iteration}"
	fi
	chmod 600 "${report}" "${stderr_log}"
	jq -e --arg fetch_base "${fetch_base}" '
	  .schema_version == 3
	  and .tool == "akibara-ai-discoverability-audit"
	  and .base_url == "https://akibara.cl/"
	  and .fetch_base_url == $fetch_base
	  and .read_only == true
	  and .ranking_guarantee == false
	  and .configuration.business_model_contract == "online_store_without_public_storefront_v1"
	  and .configuration.expected_pickup_locality == "San Miguel"
	  and .configuration.redirect_policy == "manual_fail_closed"
	  and .summary.status == "pass"
	  and .summary.exit_code == 0
	  and .summary.fail == 0
	  and (.summary.checks | type == "number" and floor == . and . > 0)
	  and (.summary.critical_http_requests | type == "number" and floor == . and . > 0)
	  and all(.checks[]; .status == "pass" or .status == "skip")
	' "${report}" >/dev/null || die 'Reporte del auditor IA integrado no acredita PASS estricto'
}

run_http_smoke() {
	local iteration="$1" wp_user="$2" wp_password="$3" search_tree="$4" business_tree="$5" iteration_dir="$6"
	local http_container runner_container prepend_partial prepend_php ini_partial ini_file
	local smoke_log server_log receipt_partial receipt_file receipt_count receipt_json
	local port_bindings network_mode network_internal
	http_container="akb-srch-0114-http-${BASHPID}-${run_nonce}-${iteration}"
	runner_container="akb-srch-0114-httpcheck-${BASHPID}-${run_nonce}-${iteration}"
	[[ "${http_container}" =~ ^akb-srch-0114-http-[0-9]+-[a-f0-9]{12}-[12]$ ]] \
		|| die 'Nombre de WordPress HTTP efímero inválido'
	[[ "${runner_container}" =~ ^akb-srch-0114-httpcheck-[0-9]+-[a-f0-9]{12}-[12]$ ]] \
		|| die 'Nombre de runner HTTP efímero inválido'

	prepend_partial="${iteration_dir}/http-smoke-prepend.php.partial"
	prepend_php="${iteration_dir}/http-smoke-prepend.php"
	ini_partial="${iteration_dir}/http-smoke.ini.partial"
	ini_file="${iteration_dir}/http-smoke.ini"
	partial_files+=("${prepend_partial}" "${ini_partial}")
		printf '%s\n' \
			'<?php' \
			"defined( 'WP_HOME' ) || define( 'WP_HOME', 'https://akibara.cl' );" \
			"defined( 'WP_SITEURL' ) || define( 'WP_SITEURL', 'https://akibara.cl' );" \
			"\$_SERVER['HTTP_HOST'] = 'akibara.cl';" \
			"\$_SERVER['HTTPS'] = 'on';" \
			"defined( 'DISABLE_WP_CRON' ) || define( 'DISABLE_WP_CRON', true );" \
		"defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || define( 'WP_HTTP_BLOCK_EXTERNAL', true );" \
		> "${prepend_partial}"
	printf '%s\n' 'auto_prepend_file=/tmp/akb-http-smoke-prepend.php' > "${ini_partial}"
	chmod 644 "${prepend_partial}" "${ini_partial}"
	mv -- "${prepend_partial}" "${prepend_php}"
	mv -- "${ini_partial}" "${ini_file}"

	docker run --detach --name "${http_container}" --network "${active_network}" \
		--label "akibara.recovery.run=${run_label}" \
		--read-only \
		--tmpfs /tmp:rw,nosuid,nodev,noexec,size=256m \
		--tmpfs /run/apache2:rw,nosuid,nodev,noexec,size=16m \
		--tmpfs /var/lock/apache2:rw,nosuid,nodev,noexec,size=8m \
		--tmpfs /var/lib/php/sessions:rw,nosuid,nodev,noexec,size=64m \
		--env WORDPRESS_DB_HOST="${active_db_container}" \
		--env WORDPRESS_DB_NAME="${source_database}" \
		--env WORDPRESS_DB_USER="${wp_user}" \
		--env WORDPRESS_DB_PASSWORD="${wp_password}" \
		--env WP_ENVIRONMENT_TYPE=local \
		--volume "${repo_root}/public_html:/var/www/html:ro" \
			--volume "${search_tree}:/var/www/html/wp-content/plugins/akibara-search:ro" \
			--volume "${business_tree}:/var/www/html/wp-content/plugins/akibara-business:ro" \
			--volume "${theme_candidate_tree}:/var/www/html/wp-content/themes/akibara-gp:ro" \
		--volume "${prepend_php}:/tmp/akb-http-smoke-prepend.php:ro" \
		--volume "${ini_file}:/usr/local/etc/php/conf.d/zz-akb-http-smoke.ini:ro" \
		--entrypoint apache2-foreground \
		"${apache_image}" >/dev/null

	network_internal="$(docker network inspect -f '{{.Internal}}' "${active_network}")"
	network_mode="$(docker inspect -f '{{.HostConfig.NetworkMode}}' "${http_container}")"
	port_bindings="$(docker inspect -f '{{json .HostConfig.PortBindings}}' "${http_container}")"
	[[ "${network_internal}" == true && "${network_mode}" == "${active_network}" ]] \
		|| die 'WordPress HTTP no quedó exclusivamente en la red internal'
	[[ "${port_bindings}" == null || "${port_bindings}" == '{}' ]] \
		|| die "WordPress HTTP publicó puertos: ${port_bindings}"

	smoke_log="${iteration_dir}/http-smoke.log"
	server_log="${iteration_dir}/http-server.log"
		if ! docker run --rm --name "${runner_container}" --network "${active_network}" \
		--label "akibara.recovery.run=${run_label}" \
		--read-only --tmpfs /tmp:rw,nosuid,nodev,noexec,size=64m \
		--user 33:33 \
		--env AKB_HTTP_ORIGIN="http://${http_container}" \
		--env AKB_HTTP_HOST=localhost:8080 \
		--volume "${http_smoke_php}:/opt/akibara/search-http-smoke.php:ro" \
		--entrypoint php \
		"${apache_image}" /opt/akibara/search-http-smoke.php > "${smoke_log}" 2>&1; then
		docker logs "${http_container}" > "${server_log}" 2>&1 || true
		chmod 600 "${smoke_log}" "${server_log}"
		awk '/^AKB_HTTP_SMOKE_(FAIL|RECEIPT)=/{print}' "${smoke_log}" >&2
			die 'Smoke HTTP hermético falló'
		fi
		run_ai_discoverability_audit "${iteration}" "${http_container}" "${iteration_dir}"
		docker logs "${http_container}" > "${server_log}" 2>&1 || true
	chmod 600 "${smoke_log}" "${server_log}"
	docker rm -f -- "${http_container}" >/dev/null

	receipt_count="$(grep -c '^AKB_HTTP_SMOKE_RECEIPT=' "${smoke_log}" || true)"
	[[ "${receipt_count}" == 1 ]] || die 'Receipt HTTP ambiguo o ausente'
	[[ "$(grep -c '^AKB_HTTP_SMOKE_FAIL=' "${smoke_log}" || true)" == 0 ]] || die 'Smoke HTTP emitió FAIL'
	receipt_json="$(sed -n 's/^AKB_HTTP_SMOKE_RECEIPT=//p' "${smoke_log}")"
	jq -e --arg script_sha "$(sha256_file "${http_smoke_php}")" '
	  .schema == "akibara-search-http-smoke-v1"
	  and .result == "PASS"
	  and .origin_scope == "docker-internal"
	  and .host_header == "localhost:8080"
	  and .follow_redirects == false
	  and .published_ports == 0
	  and .script_sha256 == $script_sha
	  and (.readiness.attempts | type == "number" and floor == . and . >= 1 and . <= 45)
	  and .readiness.final_status == 200
	  and (.readiness.response_sha256 | test("^[a-f0-9]{64}$"))
	  and .counts.opm > 0
	  and .counts.opm == .counts.opm_alias
	  and .counts.opm == .counts.opm_series
	  and .counts.opm_volume > 0
	  and .counts.opm_volume < .counts.opm
	  and .counts.atelier > 0
	  and .counts.atelier == .counts.atalier
	  and .counts.aot > 0
	  and .counts.aot == .counts.aot_phrase
	  and ([.checks[]] | length == 9 and all(. == true))
	  and ([.http_status[]] | length == 13 and all(. == 200))
	  and ([.response_bytes[]] | length == 13 and all(type == "number" and floor == . and . > 0))
	  and ([.response_sha256[]] | length == 13 and all(test("^[a-f0-9]{64}$")))
	' <<< "${receipt_json}" >/dev/null || die 'Receipt HTTP no acredita todas las aserciones'

	receipt_partial="${iteration_dir}/http-smoke-receipt.json.partial"
	receipt_file="${iteration_dir}/http-smoke-receipt.json"
	partial_files+=("${receipt_partial}")
	jq -S . <<< "${receipt_json}" > "${receipt_partial}"
	chmod 600 "${receipt_partial}"
	mv -- "${receipt_partial}" "${receipt_file}"
}

valid_integration_fixture() {
	local fixture="$1" mode="$2" iteration="$3"
	[[ "${mode}" == baseline || "${mode}" == candidate ]] || return 1
	[[ "${iteration}" == 1 || "${iteration}" == 2 ]] || return 1
	[[ "${fixture}" =~ ^akb_search_itest_[0-9]+_[a-f0-9]{12}_${mode}_${iteration}$ ]]
}

run_integrated_mariadb_mode() {
	local mode="$1" iteration="$2" root_password="$3" search_tree="$4" business_tree="$5" output="$6"
	local fixture fixture_user fixture_password suffix container_name success_count candidate_integration
	local global_privileges schema_privileges wrong_schema_privileges table_column_privileges routine_privileges
	fixture="akb_search_itest_${BASHPID}_${run_nonce}_${mode}_${iteration}"
	valid_integration_fixture "${fixture}" "${mode}" "${iteration}" || die 'Nombre de fixture MariaDB integrado inválido'
	case "${mode}" in
		baseline) suffix='b' ;;
		candidate) suffix='c' ;;
		*) die 'Modo MariaDB integrado inválido' ;;
	esac
	fixture_user="akbit_${run_nonce}_${iteration}_${suffix}"
	fixture_password="$(openssl rand -hex 24)"
	[[ "${fixture_user}" =~ ^[a-z0-9_]{1,32}$ && "${fixture_password}" =~ ^[a-f0-9]{48}$ ]] \
		|| die 'Credencial fixture MariaDB inválida'
	# El ZIP candidato excluye tests por diseño (artefacto de producción). El
	# gate ejecuta el script de integración del source, cuyo SHA-256 está
	# sellado en el contrato y se re-verifica aquí antes de montarlo.
	candidate_integration="${integration_php}"
	[[ -f "${candidate_integration}" && ! -L "${candidate_integration}" ]] \
		|| die 'Gate MariaDB integrado ausente en el source'
	[[ "$(sha256_file "${candidate_integration}")" == "$(jq -er '.production_invariants.recovery_rollback.integrated_mariadb.script_sha256' "${search_contract}")" ]] \
		|| die 'Gate MariaDB del source no coincide con el SHA sellado en el contrato'

	ephemeral_root_query "${root_password}" \
		"DROP DATABASE IF EXISTS \`${fixture}\`; DROP USER IF EXISTS '${fixture_user}'@'%'; CREATE DATABASE \`${fixture}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci; CREATE USER '${fixture_user}'@'%' IDENTIFIED BY '${fixture_password}'; GRANT ALL PRIVILEGES ON \`${fixture}\`.* TO '${fixture_user}'@'%';" >/dev/null
	global_privileges="$(docker exec -i -e MYSQL_PWD="${fixture_password}" "${active_db_container}" \
		mariadb --batch --raw --skip-column-names -u"${fixture_user}" "${fixture}" --execute \
		"SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1))) AND PRIVILEGE_TYPE <> 'USAGE';" | tr -d '[:space:]')"
	schema_privileges="$(docker exec -i -e MYSQL_PWD="${fixture_password}" "${active_db_container}" \
		mariadb --batch --raw --skip-column-names -u"${fixture_user}" "${fixture}" --execute \
		"SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1))) AND TABLE_SCHEMA='${fixture}';" | tr -d '[:space:]')"
	wrong_schema_privileges="$(docker exec -i -e MYSQL_PWD="${fixture_password}" "${active_db_container}" \
		mariadb --batch --raw --skip-column-names -u"${fixture_user}" "${fixture}" --execute \
		"SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1))) AND TABLE_SCHEMA<>'${fixture}';" | tr -d '[:space:]')"
	table_column_privileges="$(docker exec -i -e MYSQL_PWD="${fixture_password}" "${active_db_container}" \
		mariadb --batch --raw --skip-column-names -u"${fixture_user}" "${fixture}" --execute \
		"SELECT (SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1)))) + (SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1))));" | tr -d '[:space:]')"
	routine_privileges="$(ephemeral_routine_privilege_count "${root_password}" "${fixture_user}" '%' | tr -d '[:space:]')"
	[[ "${global_privileges}" == 0 && "${schema_privileges}" =~ ^[1-9][0-9]*$ \
		&& "${wrong_schema_privileges}" == 0 && "${table_column_privileges}" == 0 \
		&& "${routine_privileges}" == 0 ]] \
		|| die 'Usuario fixture recibió privilegios fuera de su schema exacto'
	docker exec -i -e MYSQL_PWD="${root_password}" "${active_db_container}" mariadb-dump -uroot \
		--single-transaction --quick --skip-lock-tables --skip-comments --hex-blob \
		--default-character-set=utf8mb4 --order-by-primary \
		"${source_database}" \
		| docker exec -i -e MYSQL_PWD="${root_password}" "${active_db_container}" mariadb -uroot "${fixture}"

	container_name="akb-srch-0114-itest-${BASHPID}-${run_nonce}-${suffix}-${iteration}"
	if ! docker run --rm --name "${container_name}" --network "${active_network}" \
		--label "akibara.recovery.run=${run_label}" \
		--read-only --tmpfs /tmp:rw,nosuid,nodev,noexec,size=256m \
		--user 33:33 --workdir /var/www/html \
		--env WORDPRESS_DB_HOST="${active_db_container}" \
		--env WORDPRESS_DB_NAME="${fixture}" \
		--env WORDPRESS_DB_USER="${fixture_user}" \
		--env WORDPRESS_DB_PASSWORD="${fixture_password}" \
		--env WP_ENVIRONMENT_TYPE=local \
		--env WP_CLI_CACHE_DIR=/tmp/wp-cli-cache \
		--env AKB_ITEST_EPHEMERAL_DB=1 \
		--env AKB_ITEST_MODE="${mode}" \
		--volume "${repo_root}/public_html:/var/www/html:ro" \
			--volume "${search_tree}:/var/www/html/wp-content/plugins/akibara-search:ro" \
			--volume "${business_tree}:/var/www/html/wp-content/plugins/akibara-business:ro" \
			--volume "${theme_candidate_tree}:/var/www/html/wp-content/themes/akibara-gp:ro" \
		--volume "${candidate_integration}:/tmp/live-write-ledger-integration.php:ro" \
		"${wpcli_image}" wp --path=/var/www/html eval-file /tmp/live-write-ledger-integration.php > "${output}" 2>&1; then
		chmod 600 "${output}"
		awk '/^(Error:|  FAIL  |Success:)/{print}' "${output}" >&2
		die "Gate MariaDB integrado falló: ${mode}/${iteration}"
	fi
	chmod 600 "${output}"
	success_count="$(grep -Fc "Success: Gate MariaDB Search v2 OK (${mode})." "${output}" || true)"
	[[ "${success_count}" == 1 ]] || die "Receipt MariaDB integrado ambiguo: ${mode}/${iteration}"
	if [[ "${mode}" == candidate ]]; then
		[[ "$(grep -Fxc '  PASS  proveedor Business ACTIVE conserva snapshot y presupuesto SQL <=11' "${output}" || true)" == 1 ]] \
			|| die 'Gate MariaDB no acreditó provider Business ACTIVE'
		[[ "$(grep -Fxc '  PASS  Reconciler real verifica 10 productos sin divergencia ni mutación lógica' "${output}" || true)" == 1 ]] \
			|| die 'Gate MariaDB no acreditó Reconciler zero-divergence'
	fi

	ephemeral_root_query "${root_password}" \
		"DROP DATABASE \`${fixture}\`; DROP USER '${fixture_user}'@'%';" >/dev/null
	[[ "$(ephemeral_root_query "${root_password}" "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${fixture}';" | tr -d '[:space:]')" == 0 ]] \
		|| die 'La fixture MariaDB integrada no fue retirada'
}

run_integrated_mariadb_gate() {
	local iteration="$1" root_password="$2" search_tree="$3" business_tree="$4" iteration_dir="$5"
	local baseline_log candidate_log receipt_partial receipt_file
	baseline_log="${iteration_dir}/integrated-mariadb-baseline.log"
	candidate_log="${iteration_dir}/integrated-mariadb-candidate.log"
	run_integrated_mariadb_mode baseline "${iteration}" "${root_password}" "${search_tree}" "${business_tree}" "${baseline_log}"
	run_integrated_mariadb_mode candidate "${iteration}" "${root_password}" "${search_tree}" "${business_tree}" "${candidate_log}"
	receipt_partial="${iteration_dir}/integrated-mariadb-receipt.json.partial"
	receipt_file="${iteration_dir}/integrated-mariadb-receipt.json"
	partial_files+=("${receipt_partial}")
	jq -n \
		--arg script_sha "$(sha256_file "${integration_php}")" \
		--arg baseline_sha "$(sha256_file "${baseline_log}")" \
		--arg candidate_sha "$(sha256_file "${candidate_log}")" \
		'{result:"PASS",script_sha256:$script_sha,modes:["baseline","candidate"],baseline_log_sha256:$baseline_sha,candidate_log_sha256:$candidate_sha,fixture_user_schema_limited:true,provider_active:true,reconciler_zero_divergence:true}' \
		> "${receipt_partial}"
	chmod 600 "${receipt_partial}"
	mv -- "${receipt_partial}" "${receipt_file}"
}

assert_protected_equal() {
	local root_password="$1" output="$2" protected_phase="$3" price_phase="$4" price_output="$5"
	capture_and_assert_protected_phase_root "${active_db_container}" "${root_password}" "${protected_phase}" "${output}"
	assert_derived_price_pair_phase_root "${active_db_container}" "${root_password}" "${price_phase}" "${price_output}"
}

run_iteration() {
	local iteration="$1" search_candidate="$2" search_rollback="$3" business_candidate="$4" business_rollback="$5"
	local iteration_dir root_password wp_user wp_password recovery_receipt pending_receipt not_before
	local rollback_as_stabilization_receipt forward_as_stabilization_receipt as_provider_receipt
	iteration_dir="${run_dir}/iteration-${iteration}"
	mkdir -p -- "${iteration_dir}"
	chmod 700 "${iteration_dir}"
	root_password="$(openssl rand -hex 24)"
	[[ "${root_password}" =~ ^[a-f0-9]{48}$ ]] || die 'Password root efímero inválido'
	start_fresh_restore "${iteration}" "${root_password}" "${iteration_dir}"
	isolate_ephemeral_runtime_plugins "${root_password}"
	wp_user=''
	wp_password=''
	create_limited_wp_user "${root_password}" "${iteration}" wp_user wp_password
	run_theme_quality_gate "${iteration}" "${iteration_dir}"
	wp_assert_theme_state "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/theme-candidate-initial.log"

	wp_proof recover-ready-swap 1 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/recover-ready-swap.log"
	assert_protected_equal "${root_password}" "${iteration_dir}/protected-after-recovery.tsv" candidate preimage "${iteration_dir}/derived-price-pair-after-recovery.tsv"
	cmp -s "${source_price_pair}" "${iteration_dir}/derived-price-pair-after-recovery.tsv" \
		|| die 'Recovery cambió el par derivado antes del rebuild'
	recovery_receipt="$(sed -n 's/^AKB_RECOVERY_RECEIPT=//p' "${iteration_dir}/recover-ready-swap.log")"
	not_before="$(jq -r '.pending.max_not_before' <<< "${recovery_receipt}")"
	wait_until_epoch "${not_before}"
	wp_boot "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/candidate-first-boot.log"
	drain_search_batches "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/candidate-first-drain.log" v2
	drain_live_work "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/candidate-first-live-drain.log"
	wp_proof gate-v2 0 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/candidate-first-gate.log"
	assert_protected_equal "${root_password}" "${iteration_dir}/protected-after-first-v2.tsv" candidate normalized "${iteration_dir}/derived-price-pair-after-first-v2.tsv"
	capture_checked_search_schema_root "${root_password}" "${iteration_dir}/schema-after-first-v2.tsv"

	wp_assert_pair_state active 0.1.14 1.99.16 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/rollback-before-deactivate-state.log"
	wp_set_search_activation deactivate "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/rollback-deactivate.log"
	wp_assert_pair_state inactive 0.1.14 1.99.16 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/rollback-after-deactivate-state.log"
	wp_assert_pair_state inactive 0.1.14 1.99.10 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_rollback}" "${iteration_dir}/rollback-mixed-midpoint.log"
	wp_boot "${wp_user}" "${wp_password}" "${search_candidate}" "${business_rollback}" "${iteration_dir}/rollback-business-transition-boot.log"
	stabilize_action_scheduler_db_runtime "${wp_user}" "${wp_password}" "${search_candidate}" "${business_rollback}" "${iteration_dir}/rollback-action-scheduler-stabilization.log"
	rollback_as_stabilization_receipt="$(action_scheduler_stabilization_receipt_from_log "${iteration_dir}/rollback-action-scheduler-stabilization.log")"
	wp_assert_pair_state inactive 0.1.14 1.99.10 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_rollback}" "${iteration_dir}/rollback-after-business-transition-state.log"
	capture_and_assert_protected_phase_root "${active_db_container}" "${root_password}" rollback "${iteration_dir}/protected-rollback-after-business-switch.tsv"
	wp_assert_pair_state inactive 0.1.12 1.99.10 "${wp_user}" "${wp_password}" "${search_rollback}" "${business_rollback}" "${iteration_dir}/rollback-ready-pair.log"
	capture_and_assert_protected_phase_root "${active_db_container}" "${root_password}" rollback "${iteration_dir}/protected-rollback-before-activation.tsv"
	wp_set_search_activation activate "${wp_user}" "${wp_password}" "${search_rollback}" "${business_rollback}" "${iteration_dir}/rollback-activate.log"
	wp_assert_pair_state active 0.1.12 1.99.10 "${wp_user}" "${wp_password}" "${search_rollback}" "${business_rollback}" "${iteration_dir}/rollback-after-activate-state.log"
	wp_boot "${wp_user}" "${wp_password}" "${search_rollback}" "${business_rollback}" "${iteration_dir}/rollback-boot.log"
	assert_derived_price_pair_phase_root "${active_db_container}" "${root_password}" normalized "${iteration_dir}/derived-price-pair-after-rollback-boot.tsv"
	drain_search_batches "${wp_user}" "${wp_password}" "${search_rollback}" "${business_rollback}" "${iteration_dir}/rollback-drain.log" legacy-v1
	wp_proof gate-v1 0 "${wp_user}" "${wp_password}" "${search_rollback}" "${business_rollback}" "${iteration_dir}/rollback-gate.log"
	assert_protected_equal "${root_password}" "${iteration_dir}/protected-after-v1.tsv" rollback normalized "${iteration_dir}/derived-price-pair-after-v1.tsv"
	capture_checked_search_schema_root "${root_password}" "${iteration_dir}/schema-after-v1.tsv"

	wp_assert_pair_state active 0.1.12 1.99.10 "${wp_user}" "${wp_password}" "${search_rollback}" "${business_rollback}" "${iteration_dir}/forward-before-deactivate-state.log"
	wp_set_search_activation deactivate "${wp_user}" "${wp_password}" "${search_rollback}" "${business_rollback}" "${iteration_dir}/forward-deactivate.log"
	wp_assert_pair_state inactive 0.1.12 1.99.10 "${wp_user}" "${wp_password}" "${search_rollback}" "${business_rollback}" "${iteration_dir}/forward-after-deactivate-state.log"
	wp_assert_pair_state inactive 0.1.12 1.99.16 "${wp_user}" "${wp_password}" "${search_rollback}" "${business_candidate}" "${iteration_dir}/forward-mixed-midpoint.log"
	wp_boot "${wp_user}" "${wp_password}" "${search_rollback}" "${business_candidate}" "${iteration_dir}/forward-business-transition-boot.log"
	stabilize_action_scheduler_db_runtime "${wp_user}" "${wp_password}" "${search_rollback}" "${business_candidate}" "${iteration_dir}/forward-action-scheduler-stabilization.log"
	forward_as_stabilization_receipt="$(action_scheduler_stabilization_receipt_from_log "${iteration_dir}/forward-action-scheduler-stabilization.log")"
	wp_assert_pair_state inactive 0.1.14 1.99.16 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/forward-ready-pair.log"
	capture_and_assert_protected_phase_root "${active_db_container}" "${root_password}" candidate "${iteration_dir}/protected-forward-before-activation.tsv"
	wp_set_search_activation activate "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/forward-activate.log"
	wp_assert_pair_state active 0.1.14 1.99.16 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/forward-after-activate-state.log"
	wp_boot "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/forward-migration-boot.log"
	assert_protected_equal "${root_password}" "${iteration_dir}/protected-after-forward-migration.tsv" candidate normalized "${iteration_dir}/derived-price-pair-after-forward-migration.tsv"
	wp_proof pending 1 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/forward-pending.log"
	pending_receipt="$(sed -n 's/^AKB_RECOVERY_RECEIPT=//p' "${iteration_dir}/forward-pending.log")"
	not_before="$(jq -r '.pending.max_not_before' <<< "${pending_receipt}")"
	wait_until_epoch "${not_before}"
	wp_boot "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/forward-consume-boot.log"
	drain_search_batches "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/forward-drain.log" v2
	drain_live_work "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/forward-live-drain.log"
	wp_proof gate-v2 0 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/forward-gate.log"
	assert_protected_equal "${root_password}" "${iteration_dir}/protected-after-forward-v2.tsv" candidate normalized "${iteration_dir}/derived-price-pair-after-forward-v2.tsv"
	capture_checked_search_schema_root "${root_password}" "${iteration_dir}/schema-after-forward-v2.tsv"
	wp_assert_theme_state "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/theme-candidate-before-http.log"
	assert_protected_equal "${root_password}" "${iteration_dir}/protected-before-http.tsv" candidate normalized "${iteration_dir}/derived-price-pair-before-http.tsv"
	run_http_smoke "${iteration}" "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}"
	wp_assert_theme_state "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/theme-candidate-after-http.log"
	assert_protected_equal "${root_password}" "${iteration_dir}/protected-after-http.tsv" candidate normalized "${iteration_dir}/derived-price-pair-after-http.tsv"
	cmp -s "${iteration_dir}/protected-before-http.tsv" "${iteration_dir}/protected-after-http.tsv" \
		|| die 'Smoke HTTP cambió superficies protegidas'
	cmp -s "${iteration_dir}/derived-price-pair-before-http.tsv" "${iteration_dir}/derived-price-pair-after-http.tsv" \
		|| die 'Smoke HTTP cambió el par derivado de precio'
	run_integrated_mariadb_gate "${iteration}" "${root_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}"
	assert_protected_equal "${root_password}" "${iteration_dir}/protected-after-integrated-mariadb.tsv" candidate normalized "${iteration_dir}/derived-price-pair-after-integrated-mariadb.tsv"
	cmp -s "${iteration_dir}/protected-after-http.tsv" "${iteration_dir}/protected-after-integrated-mariadb.tsv" \
		|| die 'Gate MariaDB integrado cambió superficies protegidas del restore base'
	cmp -s "${iteration_dir}/derived-price-pair-after-http.tsv" "${iteration_dir}/derived-price-pair-after-integrated-mariadb.tsv" \
		|| die 'Gate MariaDB integrado cambió el par derivado de precio'
	cleanup_iteration 1
	as_provider_receipt="$(action_scheduler_provider_expected_receipt)"

	partial_files+=("${iteration_dir}/receipt.json.partial")
	jq -n \
		--argjson iteration "${iteration}" \
		--arg restored_gzip_sha "$(sha256_file "${iteration_dir}/restored-canonical.sql.gz")" \
		--arg first_v2_sha "$(sha256_file "${iteration_dir}/candidate-first-gate.log")" \
		--arg first_live_drain_sha "$(sha256_file "${iteration_dir}/candidate-first-live-drain.log")" \
		--arg rollback_sha "$(sha256_file "${iteration_dir}/rollback-gate.log")" \
		--arg forward_sha "$(sha256_file "${iteration_dir}/forward-gate.log")" \
		--arg forward_live_drain_sha "$(sha256_file "${iteration_dir}/forward-live-drain.log")" \
		--arg rollback_as_stabilization_sha "$(sha256_file "${iteration_dir}/rollback-action-scheduler-stabilization.log")" \
		--arg forward_as_stabilization_sha "$(sha256_file "${iteration_dir}/forward-action-scheduler-stabilization.log")" \
		--argjson rollback_as_stabilization "${rollback_as_stabilization_receipt}" \
		--argjson forward_as_stabilization "${forward_as_stabilization_receipt}" \
		--argjson as_provider "${as_provider_receipt}" \
		--arg rollback_mixed_midpoint_sha "$(sha256_file "${iteration_dir}/rollback-mixed-midpoint.log")" \
		--arg rollback_ready_pair_sha "$(sha256_file "${iteration_dir}/rollback-ready-pair.log")" \
		--arg forward_mixed_midpoint_sha "$(sha256_file "${iteration_dir}/forward-mixed-midpoint.log")" \
		--arg forward_ready_pair_sha "$(sha256_file "${iteration_dir}/forward-ready-pair.log")" \
		--arg protected_sha "$(sha256_file "${iteration_dir}/protected-after-forward-v2.tsv")" \
		--arg protected_candidate_sha "${protected_candidate_snapshot_sha256}" \
		--arg protected_rollback_sha "${protected_rollback_snapshot_sha256}" \
		--arg protected_restored_sha "$(sha256_file "${iteration_dir}/protected-restored.tsv")" \
		--arg protected_after_recovery_sha "$(sha256_file "${iteration_dir}/protected-after-recovery.tsv")" \
		--arg protected_after_first_v2_sha "$(sha256_file "${iteration_dir}/protected-after-first-v2.tsv")" \
		--arg protected_rollback_after_business_switch_sha "$(sha256_file "${iteration_dir}/protected-rollback-after-business-switch.tsv")" \
		--arg protected_rollback_before_activation_sha "$(sha256_file "${iteration_dir}/protected-rollback-before-activation.tsv")" \
		--arg protected_after_v1_sha "$(sha256_file "${iteration_dir}/protected-after-v1.tsv")" \
		--arg protected_forward_before_activation_sha "$(sha256_file "${iteration_dir}/protected-forward-before-activation.tsv")" \
		--arg protected_after_forward_migration_sha "$(sha256_file "${iteration_dir}/protected-after-forward-migration.tsv")" \
		--arg protected_after_forward_v2_sha "$(sha256_file "${iteration_dir}/protected-after-forward-v2.tsv")" \
		--arg protected_before_http_checkpoint_sha "$(sha256_file "${iteration_dir}/protected-before-http.tsv")" \
		--arg protected_after_http_checkpoint_sha "$(sha256_file "${iteration_dir}/protected-after-http.tsv")" \
		--arg protected_after_integrated_checkpoint_sha "$(sha256_file "${iteration_dir}/protected-after-integrated-mariadb.tsv")" \
		--arg price_pair_preimage_sha "$(sha256_file "${source_price_pair}")" \
		--arg price_pair_normalized_sha "$(sha256_file "${iteration_dir}/derived-price-pair-after-forward-v2.tsv")" \
		--arg price_pair_restored_sha "$(sha256_file "${iteration_dir}/derived-price-pair-restored.tsv")" \
		--arg price_pair_after_recovery_sha "$(sha256_file "${iteration_dir}/derived-price-pair-after-recovery.tsv")" \
		--arg price_pair_after_first_v2_sha "$(sha256_file "${iteration_dir}/derived-price-pair-after-first-v2.tsv")" \
		--arg price_pair_after_rollback_boot_sha "$(sha256_file "${iteration_dir}/derived-price-pair-after-rollback-boot.tsv")" \
		--arg price_pair_after_v1_sha "$(sha256_file "${iteration_dir}/derived-price-pair-after-v1.tsv")" \
		--arg price_pair_after_forward_migration_sha "$(sha256_file "${iteration_dir}/derived-price-pair-after-forward-migration.tsv")" \
		--arg price_pair_before_http_sha "$(sha256_file "${iteration_dir}/derived-price-pair-before-http.tsv")" \
		--arg price_pair_after_http_sha "$(sha256_file "${iteration_dir}/derived-price-pair-after-http.tsv")" \
		--arg price_pair_after_integrated_sha "$(sha256_file "${iteration_dir}/derived-price-pair-after-integrated-mariadb.tsv")" \
		--arg http_receipt_sha "$(sha256_file "${iteration_dir}/http-smoke-receipt.json")" \
		--arg http_log_sha "$(sha256_file "${iteration_dir}/http-smoke.log")" \
		--arg http_server_log_sha "$(sha256_file "${iteration_dir}/http-server.log")" \
		--arg http_script_sha "$(sha256_file "${http_smoke_php}")" \
		--arg http_image "${apache_image}" \
		--arg protected_before_http_sha "$(sha256_file "${iteration_dir}/protected-before-http.tsv")" \
		--arg protected_after_http_sha "$(sha256_file "${iteration_dir}/protected-after-http.tsv")" \
		--arg integrated_receipt_sha "$(sha256_file "${iteration_dir}/integrated-mariadb-receipt.json")" \
		--arg protected_after_integrated_sha "$(sha256_file "${iteration_dir}/protected-after-integrated-mariadb.tsv")" \
		--slurpfile http_receipt "${iteration_dir}/http-smoke-receipt.json" \
		--slurpfile integrated_receipt "${iteration_dir}/integrated-mariadb-receipt.json" \
		'{iteration:$iteration,result:"PASS",fresh_restore:true,ephemeral_docker_cleanup_verified:true,exact_pair_cycle:true,search_inactive_at_mixed_and_ready_midpoints:true,canonical_redump_sha256:$restored_gzip_sha,gate_v2_initial_sha256:$first_v2_sha,live_drain_initial_sha256:$first_live_drain_sha,gate_v1_rollback_sha256:$rollback_sha,gate_v2_forward_sha256:$forward_sha,live_drain_forward_sha256:$forward_live_drain_sha,rollback_mixed_midpoint_sha256:$rollback_mixed_midpoint_sha,rollback_ready_pair_sha256:$rollback_ready_pair_sha,forward_mixed_midpoint_sha256:$forward_mixed_midpoint_sha,forward_ready_pair_sha256:$forward_ready_pair_sha,action_scheduler_stabilization:{provider_inventory:$as_provider,rollback:($rollback_as_stabilization+{log_sha256:$rollback_as_stabilization_sha}),forward:($forward_as_stabilization+{log_sha256:$forward_as_stabilization_sha})},protected_fingerprint_sha256:$protected_sha,protected_fingerprint_transition:{candidate_sha256:$protected_candidate_sha,rollback_sha256:$protected_rollback_sha,checkpoint_sha256:{candidate:[$protected_restored_sha,$protected_after_recovery_sha,$protected_after_first_v2_sha,$protected_forward_before_activation_sha,$protected_after_forward_migration_sha,$protected_after_forward_v2_sha,$protected_before_http_checkpoint_sha,$protected_after_http_checkpoint_sha,$protected_after_integrated_checkpoint_sha],rollback:[$protected_rollback_after_business_switch_sha,$protected_rollback_before_activation_sha,$protected_after_v1_sha]},single_exact_feed_contract_row_transition:true,changed_column_only_option_value:true,option_id_preserved:true,row_count_exactly_one:true,transition_exact_before_search_activation:true,search_inactive_at_artifact_midpoints:true,forward_restores_candidate_exactly:true},derived_price_pair:{preimage_sha256:$price_pair_preimage_sha,normalized_sha256:$price_pair_normalized_sha,checkpoint_sha256:{preimage:[$price_pair_restored_sha,$price_pair_after_recovery_sha],normalized:[$price_pair_after_first_v2_sha,$price_pair_after_rollback_boot_sha,$price_pair_after_v1_sha,$price_pair_after_forward_migration_sha,$price_pair_normalized_sha,$price_pair_before_http_sha,$price_pair_after_http_sha,$price_pair_after_integrated_sha]},preimage_preserved_until_rebuild:true,normalized_preserved_through_rollback_forward_http_mariadb:true,v2_stored_equals_index_derivation:true},http_smoke:($http_receipt[0] + {receipt_schema:$http_receipt[0].schema,receipt_sha256:$http_receipt_sha,log_sha256:$http_log_sha,server_log_sha256:$http_server_log_sha,script_sha256:$http_script_sha,image:$http_image,network_internal:true,published_ports:0,protected_before_sha256:$protected_before_http_sha,protected_after_sha256:$protected_after_http_sha}),integrated_mariadb:($integrated_receipt[0] + {receipt_sha256:$integrated_receipt_sha,protected_after_sha256:$protected_after_integrated_sha})}' \
		> "${iteration_dir}/receipt-base.json.partial"
	partial_files+=("${iteration_dir}/receipt-base.json.partial")
	jq \
		--arg theme_quality_receipt_sha "$(sha256_file "${iteration_dir}/theme-quality-receipt.json")" \
		--arg theme_initial_sha "$(sha256_file "${iteration_dir}/theme-candidate-initial.log")" \
		--arg theme_before_http_sha "$(sha256_file "${iteration_dir}/theme-candidate-before-http.log")" \
		--arg theme_after_http_sha "$(sha256_file "${iteration_dir}/theme-candidate-after-http.log")" \
		--arg ai_report_sha "$(sha256_file "${iteration_dir}/ai-discoverability-audit.json")" \
		--arg ai_stderr_sha "$(sha256_file "${iteration_dir}/ai-discoverability-audit.stderr")" \
		--arg ai_engine_sha "$(sha256_file "${ai_audit_engine}")" \
		--arg ai_policy_sha "$(sha256_file "${ai_audit_policy}")" \
		--arg node_image "${node_image}" \
		--slurpfile theme_quality "${iteration_dir}/theme-quality-receipt.json" \
		--slurpfile ai_report "${iteration_dir}/ai-discoverability-audit.json" '
	  . + {
	    theme_quality: ($theme_quality[0] + {
	      receipt_sha256:$theme_quality_receipt_sha,
	      active_theme_state:{
	        initial_log_sha256:$theme_initial_sha,
	        before_http_log_sha256:$theme_before_http_sha,
	        after_http_log_sha256:$theme_after_http_sha,
	        slug:"akibara-gp",version:"2.1.70",active:true
	      }
	    }),
	    ai_discoverability:{
	      result:"PASS",schema_version:3,
	      report_sha256:$ai_report_sha,stderr_sha256:$ai_stderr_sha,
	      engine_sha256:$ai_engine_sha,policy_sha256:$ai_policy_sha,
	      image:$node_image,network_internal:true,published_ports:0,
	      external_network_dependency_allowed:false,
	      base_url:$ai_report[0].base_url,
	      fetch_scope:"docker-internal-only",
	      redirect_policy:$ai_report[0].configuration.redirect_policy,
	      summary:$ai_report[0].summary
	    }
	  }
	' "${iteration_dir}/receipt-base.json.partial" > "${iteration_dir}/receipt.json.partial"
	rm -f -- "${iteration_dir}/receipt-base.json.partial"
	chmod 600 "${iteration_dir}/receipt.json.partial"
	mv -- "${iteration_dir}/receipt.json.partial" "${iteration_dir}/receipt.json"
	jq -e \
		--arg source_protected_sha "$(sha256_file "${source_protected}")" \
		--arg source_price_pair_sha "$(sha256_file "${source_price_pair}")" \
		--arg canonical_protected_candidate_sha "${protected_candidate_snapshot_sha256}" \
		--arg canonical_protected_rollback_sha "${protected_rollback_snapshot_sha256}" \
		--arg canonical_price_preimage_sha "${price_pair_preimage_snapshot_sha256}" \
		--arg canonical_price_normalized_sha "${price_pair_normalized_snapshot_sha256}" \
		--arg http_script_sha "$(sha256_file "${http_smoke_php}")" \
		--arg integration_sha "$(sha256_file "${integration_php}")" \
		--arg apache "${apache_image}" \
		--arg node "${node_image}" \
		--arg theme_candidate_inventory "$(jq -r '.component.candidate.inventory_sha256' "${theme_contract}")" \
		--arg theme_rollback_inventory "$(jq -r '.component.preimage.inventory_sha256' "${theme_contract}")" \
		--arg theme_quality_receipt_sha "$(sha256_file "${iteration_dir}/theme-quality-receipt.json")" \
		--arg theme_initial_sha "$(sha256_file "${iteration_dir}/theme-candidate-initial.log")" \
		--arg theme_before_http_sha "$(sha256_file "${iteration_dir}/theme-candidate-before-http.log")" \
		--arg theme_after_http_sha "$(sha256_file "${iteration_dir}/theme-candidate-after-http.log")" \
		--arg ai_report_sha "$(sha256_file "${iteration_dir}/ai-discoverability-audit.json")" \
		--arg ai_stderr_sha "$(sha256_file "${iteration_dir}/ai-discoverability-audit.stderr")" \
		--arg ai_engine_sha "$(sha256_file "${ai_audit_engine}")" \
		--arg ai_policy_sha "$(sha256_file "${ai_audit_policy}")" \
		--argjson as_provider "${as_provider_receipt}" \
		--arg as_provider_sha "$(printf '%s' "${as_provider_receipt}" | sha256_stdin)" \
		--slurpfile ai_report "${iteration_dir}/ai-discoverability-audit.json" '
	  .result == "PASS"
	  and .fresh_restore == true
	  and .ephemeral_docker_cleanup_verified == true
	  and .exact_pair_cycle == true
		  and .search_inactive_at_mixed_and_ready_midpoints == true
		  and (.action_scheduler_stabilization|keys|sort)==["forward","provider_inventory","rollback"]
		  and .action_scheduler_stabilization.provider_inventory == $as_provider
		  and ([.action_scheduler_stabilization.rollback,.action_scheduler_stabilization.forward] | all(
		    (keys|sort) == ["action_id","actions_executed","execution_scope","final_observation","final_runtime","initial_runtime","log_sha256","mode","provider_files","provider_inventory_sha256","schema"]
		    and .schema == "akibara-action-scheduler-stabilization-v1"
		    and (.mode == "dbstore-fast-path" or .mode == "hybrid-migration")
		    and .provider_inventory_sha256 == $as_provider_sha
		    and .provider_files == 7
		    and (.log_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
		    and .final_runtime == {version:"3.9.3",store_class:"ActionScheduler_DBStore",migration_status:{present:true,value:"complete"}}
		    and (if .mode == "dbstore-fast-path" then
		      .actions_executed == 0 and .action_id == 0 and .execution_scope == "none"
		      and .final_observation == "initial-fast-path" and .initial_runtime == .final_runtime
		    else
		      .actions_executed == 1 and (.action_id | type == "number" and floor == . and . > 0)
		      and .execution_scope == "explicit-action-id-only" and .final_observation == "fresh-post-action-process"
		      and .initial_runtime == {version:"3.9.3",store_class:"ActionScheduler_HybridStore",migration_status:{present:false,value:""}}
		    end)
		  ))
		  and .protected_fingerprint_sha256 == $source_protected_sha
		  and $source_protected_sha == $canonical_protected_candidate_sha
		  and (.protected_fingerprint_transition|keys|sort)==["candidate_sha256","changed_column_only_option_value","checkpoint_sha256","forward_restores_candidate_exactly","option_id_preserved","rollback_sha256","row_count_exactly_one","search_inactive_at_artifact_midpoints","single_exact_feed_contract_row_transition","transition_exact_before_search_activation"]
		  and .protected_fingerprint_transition.candidate_sha256 == $canonical_protected_candidate_sha
		  and .protected_fingerprint_transition.rollback_sha256 == $canonical_protected_rollback_sha
		  and (.protected_fingerprint_transition.checkpoint_sha256|keys|sort)==["candidate","rollback"]
		  and (.protected_fingerprint_transition.checkpoint_sha256.candidate | type=="array" and length==9 and all(.==$canonical_protected_candidate_sha))
		  and (.protected_fingerprint_transition.checkpoint_sha256.rollback | type=="array" and length==3 and all(.==$canonical_protected_rollback_sha))
		  and .protected_fingerprint_transition.single_exact_feed_contract_row_transition == true
		  and .protected_fingerprint_transition.changed_column_only_option_value == true
		  and .protected_fingerprint_transition.option_id_preserved == true
		  and .protected_fingerprint_transition.row_count_exactly_one == true
		  and .protected_fingerprint_transition.transition_exact_before_search_activation == true
		  and .protected_fingerprint_transition.search_inactive_at_artifact_midpoints == true
		  and .protected_fingerprint_transition.forward_restores_candidate_exactly == true
		  and $source_price_pair_sha == $canonical_price_preimage_sha
		  and .derived_price_pair.preimage_sha256 == $canonical_price_preimage_sha
		  and .derived_price_pair.normalized_sha256 == $canonical_price_normalized_sha
	  and (.derived_price_pair.checkpoint_sha256.preimage | length == 2 and all(. == $source_price_pair_sha))
	  and (.derived_price_pair.normalized_sha256 as $normalized_sha | .derived_price_pair.checkpoint_sha256.normalized | length == 8 and all(. == $normalized_sha))
	  and .derived_price_pair.preimage_preserved_until_rebuild == true
	  and .derived_price_pair.normalized_preserved_through_rollback_forward_http_mariadb == true
	  and .derived_price_pair.v2_stored_equals_index_derivation == true
	  and .theme_quality.result == "PASS"
	  and .theme_quality.pass == .iteration
	  and .theme_quality.theme == {candidate_version:"2.1.70",rollback_version:"2.1.69",candidate_inventory_sha256:$theme_candidate_inventory,rollback_inventory_sha256:$theme_rollback_inventory,candidate_overlay_exact:true}
	  and .theme_quality.receipt_sha256 == $theme_quality_receipt_sha
	  and .theme_quality.static_contract.passes == 1
	  and .theme_quality.package_verify == {passes:1,runtime_files:103,log_sha256:.theme_quality.package_verify.log_sha256}
	  and .theme_quality.node.tests == 31
	  and .theme_quality.node.passes == 31
	  and .theme_quality.node.failures == 0
	  and .theme_quality.node.image == $node
	  and .theme_quality.node.network == "none"
	  and .theme_quality.phpunit.tests == 18
	  and .theme_quality.phpunit.assertions == 52
	  and .theme_quality.phpunit.failures == 0
	  and .theme_quality.phpunit.image == $apache
	  and .theme_quality.phpunit.network == "none"
	  and .theme_quality.ai_audit_unit.result == "PASS"
	  and .theme_quality.ai_audit_unit.image == $node
	  and .theme_quality.ai_audit_unit.network == "none"
	  and .theme_quality.external_network_dependency_allowed == false
	  and .theme_quality.active_theme_state == {initial_log_sha256:$theme_initial_sha,before_http_log_sha256:$theme_before_http_sha,after_http_log_sha256:$theme_after_http_sha,slug:"akibara-gp",version:"2.1.70",active:true}
	  and ([.theme_quality.static_contract.log_sha256,.theme_quality.package_verify.log_sha256,.theme_quality.node.log_sha256,.theme_quality.phpunit.log_sha256,.theme_quality.ai_audit_unit.log_sha256] | all(type == "string" and test("^[a-f0-9]{64}$")))
	  and .ai_discoverability.result == "PASS"
	  and .ai_discoverability.schema_version == 3
	  and .ai_discoverability.report_sha256 == $ai_report_sha
	  and .ai_discoverability.stderr_sha256 == $ai_stderr_sha
	  and .ai_discoverability.engine_sha256 == $ai_engine_sha
	  and .ai_discoverability.policy_sha256 == $ai_policy_sha
	  and .ai_discoverability.image == $node
	  and .ai_discoverability.network_internal == true
	  and .ai_discoverability.published_ports == 0
	  and .ai_discoverability.external_network_dependency_allowed == false
	  and .ai_discoverability.base_url == "https://akibara.cl/"
	  and .ai_discoverability.fetch_scope == "docker-internal-only"
	  and .ai_discoverability.redirect_policy == "manual_fail_closed"
	  and .ai_discoverability.summary == $ai_report[0].summary
	  and .ai_discoverability.summary.status == "pass"
	  and .ai_discoverability.summary.exit_code == 0
	  and .ai_discoverability.summary.fail == 0
	  and .http_smoke.result == "PASS"
	  and .http_smoke.receipt_schema == "akibara-search-http-smoke-v1"
	  and .http_smoke.script_sha256 == $http_script_sha
	  and .http_smoke.image == $apache
	  and .http_smoke.origin_scope == "docker-internal"
	  and .http_smoke.network_internal == true
	  and .http_smoke.published_ports == 0
	  and .http_smoke.host_header == "localhost:8080"
	  and .http_smoke.follow_redirects == false
	  and .http_smoke.protected_before_sha256 == $source_protected_sha
	  and .http_smoke.protected_after_sha256 == $source_protected_sha
	  and ([.http_smoke.checks[]] | length == 9 and all(. == true))
	  and ([.http_smoke.http_status[]] | length == 13 and all(. == 200))
	  and ([.http_smoke.response_sha256[]] | length == 13 and all(test("^[a-f0-9]{64}$")))
	  and .integrated_mariadb.result == "PASS"
	  and .integrated_mariadb.script_sha256 == $integration_sha
	  and .integrated_mariadb.modes == ["baseline","candidate"]
	  and .integrated_mariadb.fixture_user_schema_limited == true
	  and .integrated_mariadb.provider_active == true
	  and .integrated_mariadb.reconciler_zero_divergence == true
	  and .integrated_mariadb.protected_after_sha256 == $source_protected_sha
	  and ([.canonical_redump_sha256,.gate_v2_initial_sha256,.live_drain_initial_sha256,.gate_v1_rollback_sha256,.gate_v2_forward_sha256,.live_drain_forward_sha256,.rollback_mixed_midpoint_sha256,.rollback_ready_pair_sha256,.forward_mixed_midpoint_sha256,.forward_ready_pair_sha256,.action_scheduler_stabilization.rollback.log_sha256,.action_scheduler_stabilization.forward.log_sha256,.http_smoke.receipt_sha256,.http_smoke.log_sha256,.http_smoke.server_log_sha256,.integrated_mariadb.receipt_sha256,.integrated_mariadb.baseline_log_sha256,.integrated_mariadb.candidate_log_sha256,.theme_quality.receipt_sha256,.ai_discoverability.report_sha256,.ai_discoverability.stderr_sha256] | all(type == "string" and test("^[a-f0-9]{64}$")))
	' "${iteration_dir}/receipt.json" >/dev/null || die 'Receipt del ciclo no acredita HTTP/MariaDB/cleanup exactos'
	assert_mode_600 "${iteration_dir}/receipt.json"
	note "PASS ciclo fresh-restore ${iteration}: 0.1.14+1.99.16 -> 0.1.12+1.99.10 -> 0.1.14+1.99.16"
}

validate_contracts
docker image inspect "${mariadb_image}" >/dev/null 2>&1 || die 'Imagen MariaDB pinneada no está local'
docker image inspect "${wpcli_image}" >/dev/null 2>&1 || die 'Imagen WP-CLI pinneada no está local'
docker image inspect "${apache_image}" >/dev/null 2>&1 || die 'Imagen Apache pinneada no está local'
docker image inspect "${node_image}" >/dev/null 2>&1 || die 'Imagen Node pinneada no está local'

run_nonce="$(openssl rand -hex 6)"
run_label="search-0.1.14-${BASHPID}-${run_nonce}"
run_dir="$(mktemp -d "${backup_dir}/run-${run_label}.XXXXXX")"
run_dir="$(cd -- "${run_dir}" && pwd -P)"
chmod 700 "${run_dir}"

release_binding_initial="${run_dir}/release-binding-initial.tsv"
capture_release_binding "${release_binding_initial}"
validate_contracts
assert_release_binding_unchanged initial-validated

php "${local_index_guard_test}" > "${run_dir}/local-external-indexing-guard.log" 2>&1 \
	|| die 'Guard local de IndexNow no superó su test aislado'
grep -q '^PASS:' "${run_dir}/local-external-indexing-guard.log" \
	|| die 'Test aislado del guard local no emitió receipt PASS'
chmod 600 "${run_dir}/local-external-indexing-guard.log"

artifact_root="${run_dir}/artifacts"
mkdir -p -- "${artifact_root}"
search_candidate_tree="$(prepare_component_tree "${search_contract}" search candidate 0.1.14 "${artifact_root}/search-candidate")"
search_rollback_tree="$(prepare_component_tree "${search_contract}" search preimage 0.1.12 "${artifact_root}/search-rollback")"
business_candidate_tree="$(prepare_component_tree "${business_contract}" business candidate 1.99.16 "${artifact_root}/business-candidate")"
business_rollback_tree="$(prepare_component_tree "${business_contract}" business preimage 1.99.10 "${artifact_root}/business-rollback")"
theme_candidate_tree="$(prepare_theme_tree candidate 2.1.70 "${artifact_root}/theme-candidate")"
theme_rollback_tree="$(prepare_theme_tree preimage 2.1.69 "${artifact_root}/theme-rollback")"
theme_test_tree="$(prepare_theme_test_tree "${artifact_root}/theme-test-harness")"

assert_source_freeze
create_backup

for iteration in 1 2; do
	assert_release_binding_unchanged "before-iteration-${iteration}"
	run_iteration "${iteration}" "${search_candidate_tree}" "${search_rollback_tree}" "${business_candidate_tree}" "${business_rollback_tree}"
	assert_release_binding_unchanged "after-iteration-${iteration}"
done

# Revalida contratos y bytes de todos los archivos sellados después del trabajo:
# un cambio concurrente nunca puede convertirse en un receipt PASS.
assert_release_binding_unchanged after-cycles-before-contract-validation
validate_contracts
assert_release_binding_unchanged after-cycles-after-contract-validation
verify_source_unchanged_final
assert_release_binding_unchanged after-source-final-before-contract-validation
validate_contracts
assert_release_binding_unchanged after-source-final-after-contract-validation

partial_files+=("${run_dir}/final-receipt.json.partial")
jq -s \
	--arg schema 'akibara-search-recovery-cycle-v2' \
	--arg release_id 'akibara-search-0.1.14' \
	--arg completed_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
	--arg backup "${backup_archive}" \
	--arg backup_sha "${backup_compressed_sha}" \
	--arg sql_sha "${backup_decompressed_sha}" \
	--arg expected_run_id "${expected_run_id}" \
	--arg expected_state_sha "${expected_state_sha256}" \
	--arg search_contract_path 'ops/contracts/akibara-search-0.1.14.json' \
	--arg search_contract_sha "$(sha256_file "${search_contract}")" \
	--arg business_contract_path 'ops/contracts/akibara-business-1.99.16.json' \
	--arg business_contract_sha "$(sha256_file "${business_contract}")" \
	--arg rail_sha "$(sha256_file "${BASH_SOURCE[0]}")" \
	--arg compose_sha "$(sha256_file "${compose_file}")" \
	--arg packager_sha "$(sha256_file "${packager_library}")" \
	--arg runtime_packager_sha "$(sha256_file "${runtime_packager}")" \
	--arg proof_sha "$(sha256_file "${proof_php}")" \
	--arg runbook_sha "$(sha256_file "${recovery_runbook}")" \
	--arg static_test_sha "$(sha256_file "${static_test}")" \
	--arg http_smoke_sha "$(sha256_file "${http_smoke_php}")" \
	--arg integration_sha "$(sha256_file "${integration_php}")" \
	--arg protected_sql_sha "$(sha256_file "${protected_sql}")" \
	--arg check_validator_sha "$(sha256_file "${check_table_validator}")" \
	--arg index_guard_sha "$(sha256_file "${local_index_guard}")" \
	--arg index_guard_test_sha "$(sha256_file "${local_index_guard_test}")" \
	--arg mariadb_image "${mariadb_image}" \
	--arg wpcli_image "${wpcli_image}" \
		--arg apache_image "${apache_image}" \
	--arg evidence_snapshot_sha "$(sha256_file "${release_binding_initial}")" \
	--slurpfile search "${search_contract}" \
	--slurpfile business "${business_contract}" \
	--slurpfile source_final "${source_final_verification}" \
	'{schema:$schema,release_id:$release_id,result:"PASS",completed_at_utc:$completed_at,production_mutated:false,main_database_mutated:false,source_database_verification:$source_final[0],release_binding:{initial_snapshot_sha256:$evidence_snapshot_sha,unchanged_through_final_sidecar:true,preimage:{run_id:$expected_run_id,raw_state_sha256:$expected_state_sha},search_contract:{path:$search_contract_path,release_id:$search[0].release_id,sha256:$search_contract_sha},business_contract:{path:$business_contract_path,release_id:$business[0].release_id,sha256:$business_contract_sha},artifacts:{search:{preimage:{archive:$search[0].components.search.preimage.archive,archive_sha256:$search[0].components.search.preimage.archive_sha256,inventory:$search[0].components.search.preimage.inventory,inventory_sha256:$search[0].components.search.preimage.inventory_sha256},candidate:{archive:$search[0].components.search.candidate.archive,archive_sha256:$search[0].components.search.candidate.archive_sha256,inventory:$search[0].components.search.candidate.inventory,inventory_sha256:$search[0].components.search.candidate.inventory_sha256}},business:{preimage:{archive:$business[0].components.business.preimage.archive,archive_sha256:$business[0].components.business.preimage.archive_sha256,inventory:$business[0].components.business.preimage.inventory,inventory_sha256:$business[0].components.business.preimage.inventory_sha256},candidate:{archive:$business[0].components.business.candidate.archive,archive_sha256:$business[0].components.business.candidate.archive_sha256,inventory:$business[0].components.business.candidate.inventory,inventory_sha256:$business[0].components.business.candidate.inventory_sha256}}},evidence_code:{compose_sha256:$compose_sha,packager_library_sha256:$packager_sha,runtime_packager_sha256:$runtime_packager_sha,rail_sha256:$rail_sha,recovery_proof_sha256:$proof_sha,runbook_sha256:$runbook_sha,static_test_sha256:$static_test_sha,http_smoke_sha256:$http_smoke_sha,integrated_mariadb_sha256:$integration_sha,protected_fingerprint_sql_sha256:$protected_sql_sha,check_table_validator_sha256:$check_validator_sha,local_index_guard_sha256:$index_guard_sha,local_index_guard_test_sha256:$index_guard_test_sha},images:{mariadb:$mariadb_image,wp_cli:$wpcli_image,apache:$apache_image}},backup:{path:$backup,archive_sha256:$backup_sha,decompressed_sha256:$sql_sha,location:"local-only"},cycles:.}' \
	"${run_dir}/iteration-1/receipt.json" "${run_dir}/iteration-2/receipt.json" \
		> "${run_dir}/final-receipt-base.json.partial"
partial_files+=("${run_dir}/final-receipt-base.json.partial")
jq \
	--arg theme_contract_sha "$(sha256_file "${theme_contract}")" \
	--arg theme_test_sha "$(sha256_file "${theme_release_test}")" \
	--arg theme_phpunit_config_sha "$(sha256_file "${theme_phpunit_config}")" \
	--arg ai_engine_sha "$(sha256_file "${ai_audit_engine}")" \
	--arg ai_test_sha "$(sha256_file "${ai_audit_test}")" \
	--arg ai_wrapper_sha "$(sha256_file "${ai_audit_wrapper}")" \
	--arg ai_policy_sha "$(sha256_file "${ai_audit_policy}")" \
	--arg ai_runbook_sha "$(sha256_file "${ai_audit_runbook}")" \
	--arg node_image "${node_image}" \
	--slurpfile theme "${theme_contract}" '
  .release_binding.theme_contract = {
    path:"ops/contracts/akibara-gp-2.1.70.json",
    release_id:$theme[0].release_id,
    sha256:$theme_contract_sha
  }
  | .release_binding.artifacts.theme = {
    preimage:{
      version:$theme[0].component.preimage.version,
      archive:$theme[0].component.preimage.archive,
      archive_sha256:$theme[0].component.preimage.archive_sha256,
      inventory:$theme[0].component.preimage.inventory,
      inventory_sha256:$theme[0].component.preimage.inventory_sha256,
      checksum_sidecar:$theme[0].component.preimage.checksum_sidecar,
      checksum_sidecar_sha256:$theme[0].component.preimage.checksum_sidecar_sha256
    },
    candidate:{
      version:$theme[0].component.candidate.version,
      archive:$theme[0].component.candidate.archive,
      archive_sha256:$theme[0].component.candidate.archive_sha256,
      inventory:$theme[0].component.candidate.inventory,
      inventory_sha256:$theme[0].component.candidate.inventory_sha256,
      checksum_sidecar:$theme[0].component.candidate.checksum_sidecar,
      checksum_sidecar_sha256:$theme[0].component.candidate.checksum_sidecar_sha256
    }
  }
  | .release_binding.evidence_code += {
    theme_release_test_sha256:$theme_test_sha,
    theme_phpunit_config_sha256:$theme_phpunit_config_sha,
    ai_audit_engine_sha256:$ai_engine_sha,
    ai_audit_test_sha256:$ai_test_sha,
    ai_audit_wrapper_sha256:$ai_wrapper_sha,
    ai_audit_policy_sha256:$ai_policy_sha,
    ai_audit_runbook_sha256:$ai_runbook_sha
  }
  | .release_binding.images.node = $node_image
  | .integrated_release = {
    theme:{candidate_version:"2.1.70",rollback_version:"2.1.69",active_candidate_verified_per_cycle:true,quality_passes:2},
    ai_discoverability:{schema_version:3,passes:2,base_url:"https://akibara.cl/",fetch_scope:"docker-internal-only",redirect_policy:"manual_fail_closed",external_network_dependency_allowed:false}
  }
' "${run_dir}/final-receipt-base.json.partial" > "${run_dir}/final-receipt.json.partial"
rm -f -- "${run_dir}/final-receipt-base.json.partial"
chmod 600 "${run_dir}/final-receipt.json.partial"
assert_mode_600 "${run_dir}/final-receipt.json.partial"
assert_release_binding_unchanged before-final-receipt-contract-validation
validate_contracts
assert_release_binding_unchanged after-final-receipt-contract-validation
final_as_provider_receipt="$(action_scheduler_provider_expected_receipt)"
jq -e \
	--arg search_contract_sha "$(sha256_file "${search_contract}")" \
	--arg business_contract_sha "$(sha256_file "${business_contract}")" \
	--arg rail_sha "$(sha256_file "${BASH_SOURCE[0]}")" \
	--arg compose_sha "$(sha256_file "${compose_file}")" \
	--arg packager_sha "$(sha256_file "${packager_library}")" \
	--arg runtime_packager_sha "$(sha256_file "${runtime_packager}")" \
	--arg proof_sha "$(sha256_file "${proof_php}")" \
	--arg runbook_sha "$(sha256_file "${recovery_runbook}")" \
	--arg static_test_sha "$(sha256_file "${static_test}")" \
	--arg http_smoke_sha "$(sha256_file "${http_smoke_php}")" \
	--arg integration_sha "$(sha256_file "${integration_php}")" \
	--arg protected_sql_sha "$(sha256_file "${protected_sql}")" \
	--arg check_validator_sha "$(sha256_file "${check_table_validator}")" \
	--arg index_guard_sha "$(sha256_file "${local_index_guard}")" \
	--arg index_guard_test_sha "$(sha256_file "${local_index_guard_test}")" \
	--arg evidence_snapshot_sha "$(sha256_file "${release_binding_initial}")" \
	--arg protected_sha "$(sha256_file "${source_protected}")" \
	--arg canonical_protected_candidate_sha "${protected_candidate_snapshot_sha256}" \
	--arg canonical_protected_rollback_sha "${protected_rollback_snapshot_sha256}" \
	--arg price_pair_preimage_sha "$(sha256_file "${source_price_pair}")" \
	--arg canonical_price_preimage_sha "${price_pair_preimage_snapshot_sha256}" \
	--arg canonical_price_normalized_sha "${price_pair_normalized_snapshot_sha256}" \
	--arg search_schema_sha "$(sha256_file "${source_schema}")" \
	--arg raw_state_sha "${expected_state_sha256}" \
	--arg expected_run_id "${expected_run_id}" \
	--arg backup_sha "${backup_compressed_sha}" \
	--arg sql_sha "${backup_decompressed_sha}" \
	--arg mariadb "${mariadb_image}" --arg wpcli "${wpcli_image}" --arg apache "${apache_image}" --arg node "${node_image}" \
	--arg theme_contract_sha "$(sha256_file "${theme_contract}")" \
	--arg theme_test_sha "$(sha256_file "${theme_release_test}")" \
	--arg theme_phpunit_config_sha "$(sha256_file "${theme_phpunit_config}")" \
	--arg ai_engine_sha "$(sha256_file "${ai_audit_engine}")" \
	--arg ai_test_sha "$(sha256_file "${ai_audit_test}")" \
	--arg ai_wrapper_sha "$(sha256_file "${ai_audit_wrapper}")" \
	--arg ai_policy_sha "$(sha256_file "${ai_audit_policy}")" \
	--arg ai_runbook_sha "$(sha256_file "${ai_audit_runbook}")" \
	--argjson as_provider "${final_as_provider_receipt}" \
	--arg as_provider_sha "$(printf '%s' "${final_as_provider_receipt}" | sha256_stdin)" \
	--slurpfile theme "${theme_contract}" '
  .schema == "akibara-search-recovery-cycle-v2"
  and .release_id == "akibara-search-0.1.14"
  and .result == "PASS"
  and .production_mutated == false
  and .main_database_mutated == false
  and .source_database_verification.result == "PASS"
  and .source_database_verification.freeze_rechecked == true
	  and .source_database_verification.matches_initial_backup == true
	  and .source_database_verification.protected_sha256 == $protected_sha
	  and $protected_sha == $canonical_protected_candidate_sha
	  and $price_pair_preimage_sha == $canonical_price_preimage_sha
	  and .source_database_verification.derived_price_pair_preimage_sha256 == $canonical_price_preimage_sha
  and .source_database_verification.search_schema_sha256 == $search_schema_sha
  and .source_database_verification.raw_state_sha256 == $raw_state_sha
  and .source_database_verification.canonical_archive_sha256 == $backup_sha
  and .source_database_verification.canonical_sql_sha256 == $sql_sha
  and .backup.archive_sha256 == $backup_sha
  and .backup.decompressed_sha256 == $sql_sha
  and .backup.location == "local-only"
  and .release_binding.initial_snapshot_sha256 == $evidence_snapshot_sha
  and .release_binding.unchanged_through_final_sidecar == true
  and .release_binding.preimage == {run_id:$expected_run_id,raw_state_sha256:$raw_state_sha}
  and .release_binding.search_contract == {path:"ops/contracts/akibara-search-0.1.14.json",release_id:"akibara-search-0.1.14",sha256:$search_contract_sha}
	  and .release_binding.business_contract == {path:"ops/contracts/akibara-business-1.99.16.json",release_id:"akibara-business-1.99.16",sha256:$business_contract_sha}
	  and .release_binding.theme_contract == {path:"ops/contracts/akibara-gp-2.1.70.json",release_id:"akibara-gp-2.1.70",sha256:$theme_contract_sha}
  and .release_binding.evidence_code.compose_sha256 == $compose_sha
  and .release_binding.evidence_code.packager_library_sha256 == $packager_sha
  and .release_binding.evidence_code.runtime_packager_sha256 == $runtime_packager_sha
  and .release_binding.evidence_code.rail_sha256 == $rail_sha
  and .release_binding.evidence_code.recovery_proof_sha256 == $proof_sha
  and .release_binding.evidence_code.runbook_sha256 == $runbook_sha
  and .release_binding.evidence_code.static_test_sha256 == $static_test_sha
  and .release_binding.evidence_code.http_smoke_sha256 == $http_smoke_sha
  and .release_binding.evidence_code.integrated_mariadb_sha256 == $integration_sha
  and .release_binding.evidence_code.protected_fingerprint_sql_sha256 == $protected_sql_sha
  and .release_binding.evidence_code.check_table_validator_sha256 == $check_validator_sha
  and .release_binding.evidence_code.local_index_guard_sha256 == $index_guard_sha
	  and .release_binding.evidence_code.local_index_guard_test_sha256 == $index_guard_test_sha
	  and .release_binding.evidence_code.theme_release_test_sha256 == $theme_test_sha
	  and .release_binding.evidence_code.theme_phpunit_config_sha256 == $theme_phpunit_config_sha
	  and .release_binding.evidence_code.ai_audit_engine_sha256 == $ai_engine_sha
	  and .release_binding.evidence_code.ai_audit_test_sha256 == $ai_test_sha
	  and .release_binding.evidence_code.ai_audit_wrapper_sha256 == $ai_wrapper_sha
	  and .release_binding.evidence_code.ai_audit_policy_sha256 == $ai_policy_sha
	  and .release_binding.evidence_code.ai_audit_runbook_sha256 == $ai_runbook_sha
	  and .release_binding.images == {mariadb:$mariadb,wp_cli:$wpcli,apache:$apache,node:$node}
	  and .release_binding.artifacts.theme == {
	    preimage:{version:"2.1.69",archive:$theme[0].component.preimage.archive,archive_sha256:$theme[0].component.preimage.archive_sha256,inventory:$theme[0].component.preimage.inventory,inventory_sha256:$theme[0].component.preimage.inventory_sha256,checksum_sidecar:$theme[0].component.preimage.checksum_sidecar,checksum_sidecar_sha256:$theme[0].component.preimage.checksum_sidecar_sha256},
	    candidate:{version:"2.1.70",archive:$theme[0].component.candidate.archive,archive_sha256:$theme[0].component.candidate.archive_sha256,inventory:$theme[0].component.candidate.inventory,inventory_sha256:$theme[0].component.candidate.inventory_sha256,checksum_sidecar:$theme[0].component.candidate.checksum_sidecar,checksum_sidecar_sha256:$theme[0].component.candidate.checksum_sidecar_sha256}
	  }
	  and ([
    .release_binding.artifacts.search.preimage.archive_sha256,
    .release_binding.artifacts.search.preimage.inventory_sha256,
    .release_binding.artifacts.search.candidate.archive_sha256,
    .release_binding.artifacts.search.candidate.inventory_sha256,
    .release_binding.artifacts.business.preimage.archive_sha256,
    .release_binding.artifacts.business.preimage.inventory_sha256,
    .release_binding.artifacts.business.candidate.archive_sha256,
	    .release_binding.artifacts.business.candidate.inventory_sha256,
	    .release_binding.artifacts.theme.preimage.archive_sha256,
	    .release_binding.artifacts.theme.preimage.inventory_sha256,
	    .release_binding.artifacts.theme.preimage.checksum_sidecar_sha256,
	    .release_binding.artifacts.theme.candidate.archive_sha256,
	    .release_binding.artifacts.theme.candidate.inventory_sha256,
	    .release_binding.artifacts.theme.candidate.checksum_sidecar_sha256
	  ] | all(type == "string" and test("^[a-f0-9]{64}$")))
	  and .integrated_release == {theme:{candidate_version:"2.1.70",rollback_version:"2.1.69",active_candidate_verified_per_cycle:true,quality_passes:2},ai_discoverability:{schema_version:3,passes:2,base_url:"https://akibara.cl/",fetch_scope:"docker-internal-only",redirect_policy:"manual_fail_closed",external_network_dependency_allowed:false}}
  and (.cycles | length == 2 and map(.iteration) == [1,2] and all(
	    .result == "PASS"
	    and .fresh_restore == true
		    and .ephemeral_docker_cleanup_verified == true
		    and .action_scheduler_stabilization.provider_inventory == $as_provider
		    and ([.action_scheduler_stabilization.rollback,.action_scheduler_stabilization.forward] | all(
		      (keys|sort) == ["action_id","actions_executed","execution_scope","final_observation","final_runtime","initial_runtime","log_sha256","mode","provider_files","provider_inventory_sha256","schema"]
		      and .schema == "akibara-action-scheduler-stabilization-v1"
		      and (.mode == "dbstore-fast-path" or .mode == "hybrid-migration")
		      and .provider_inventory_sha256 == $as_provider_sha
		      and .provider_files == 7
		      and (.log_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
		      and .final_runtime == {version:"3.9.3",store_class:"ActionScheduler_DBStore",migration_status:{present:true,value:"complete"}}
		      and (if .mode == "dbstore-fast-path" then
		        .actions_executed == 0 and .action_id == 0 and .execution_scope == "none"
		        and .final_observation == "initial-fast-path" and .initial_runtime == .final_runtime
		      else
		        .actions_executed == 1 and (.action_id | type == "number" and floor == . and . > 0)
		        and .execution_scope == "explicit-action-id-only" and .final_observation == "fresh-post-action-process"
		        and .initial_runtime == {version:"3.9.3",store_class:"ActionScheduler_HybridStore",migration_status:{present:false,value:""}}
		      end)
		    ))
		    and .protected_fingerprint_sha256 == $protected_sha
	    and (.protected_fingerprint_transition|keys|sort)==["candidate_sha256","changed_column_only_option_value","checkpoint_sha256","forward_restores_candidate_exactly","option_id_preserved","rollback_sha256","row_count_exactly_one","search_inactive_at_artifact_midpoints","single_exact_feed_contract_row_transition","transition_exact_before_search_activation"]
	    and .protected_fingerprint_transition.candidate_sha256 == $canonical_protected_candidate_sha
	    and .protected_fingerprint_transition.rollback_sha256 == $canonical_protected_rollback_sha
	    and (.protected_fingerprint_transition.checkpoint_sha256|keys|sort)==["candidate","rollback"]
	    and (.protected_fingerprint_transition.checkpoint_sha256.candidate | length == 9 and all(. == $canonical_protected_candidate_sha))
	    and (.protected_fingerprint_transition.checkpoint_sha256.rollback | length == 3 and all(. == $canonical_protected_rollback_sha))
	    and .protected_fingerprint_transition.single_exact_feed_contract_row_transition == true
	    and .protected_fingerprint_transition.changed_column_only_option_value == true
	    and .protected_fingerprint_transition.option_id_preserved == true
	    and .protected_fingerprint_transition.row_count_exactly_one == true
	    and .protected_fingerprint_transition.transition_exact_before_search_activation == true
	    and .protected_fingerprint_transition.search_inactive_at_artifact_midpoints == true
	    and .protected_fingerprint_transition.forward_restores_candidate_exactly == true
	    and .derived_price_pair.preimage_sha256 == $canonical_price_preimage_sha
	    and .derived_price_pair.normalized_sha256 == $canonical_price_normalized_sha
	    and (.derived_price_pair.checkpoint_sha256.preimage | length == 2 and all(. == $canonical_price_preimage_sha))
	    and (.derived_price_pair.checkpoint_sha256.normalized | length == 8 and all(. == $canonical_price_normalized_sha))
    and .derived_price_pair.preimage_preserved_until_rebuild == true
    and .derived_price_pair.normalized_preserved_through_rollback_forward_http_mariadb == true
	    and .derived_price_pair.v2_stored_equals_index_derivation == true
	    and .theme_quality.result == "PASS"
	    and .theme_quality.theme.candidate_version == "2.1.70"
	    and .theme_quality.theme.rollback_version == "2.1.69"
	    and .theme_quality.theme.candidate_overlay_exact == true
	    and .theme_quality.node == {image:$node,tests:31,passes:31,failures:0,network:"none",log_sha256:.theme_quality.node.log_sha256}
	    and .theme_quality.phpunit.tests == 18
	    and .theme_quality.phpunit.assertions == 52
	    and .theme_quality.phpunit.failures == 0
	    and .theme_quality.phpunit.image == $apache
	    and .theme_quality.active_theme_state.slug == "akibara-gp"
	    and .theme_quality.active_theme_state.version == "2.1.70"
	    and .theme_quality.active_theme_state.active == true
	    and .ai_discoverability.result == "PASS"
	    and .ai_discoverability.schema_version == 3
	    and .ai_discoverability.image == $node
	    and .ai_discoverability.network_internal == true
	    and .ai_discoverability.published_ports == 0
	    and .ai_discoverability.external_network_dependency_allowed == false
	    and .ai_discoverability.base_url == "https://akibara.cl/"
	    and .ai_discoverability.fetch_scope == "docker-internal-only"
	    and .ai_discoverability.redirect_policy == "manual_fail_closed"
	    and .ai_discoverability.summary.status == "pass"
	    and .ai_discoverability.summary.exit_code == 0
	    and .ai_discoverability.summary.fail == 0
    and .http_smoke.result == "PASS"
    and .http_smoke.protected_before_sha256 == $protected_sha
    and .http_smoke.protected_after_sha256 == $protected_sha
	    and .integrated_mariadb.result == "PASS"
	    and .integrated_mariadb.fixture_user_schema_limited == true
	    and .integrated_mariadb.provider_active == true
    and .integrated_mariadb.reconciler_zero_divergence == true
    and .integrated_mariadb.protected_after_sha256 == $protected_sha
  ))
' "${run_dir}/final-receipt.json.partial" >/dev/null || die 'Receipt final no acredita ligadura, dos ciclos y freeze end-to-end'

final_receipt_sha="$(sha256_file "${run_dir}/final-receipt.json.partial")"
assert_sha256 "${final_receipt_sha}"
partial_files+=("${run_dir}/final-receipt.json.sha256.partial")
printf '%s  %s\n' "${final_receipt_sha}" 'final-receipt.json' \
	> "${run_dir}/final-receipt.json.sha256.partial"
chmod 600 "${run_dir}/final-receipt.json.sha256.partial"
assert_mode_600 "${run_dir}/final-receipt.json.sha256.partial"
[[ "$(awk 'NF == 2 { print $1 " " $2 }' "${run_dir}/final-receipt.json.sha256.partial")" == "${final_receipt_sha} final-receipt.json" ]] \
	|| die 'Sidecar SHA-256 final inválido'

validate_contracts
assert_release_binding_unchanged after-final-sidecar-materialized

mv -- "${run_dir}/final-receipt.json.partial" "${run_dir}/final-receipt.json"
mv -- "${run_dir}/final-receipt.json.sha256.partial" "${run_dir}/final-receipt.json.sha256"
assert_mode_600 "${run_dir}/final-receipt.json"
assert_mode_600 "${run_dir}/final-receipt.json.sha256"
[[ "$(sha256_file "${run_dir}/final-receipt.json")" == "${final_receipt_sha}" ]] \
	|| die 'Receipt final cambió durante el rename atómico'
[[ "$(awk 'NF == 2 { print $1 " " $2 }' "${run_dir}/final-receipt.json.sha256")" == "${final_receipt_sha} final-receipt.json" ]] \
	|| die 'Completion marker final inválido'
validate_contracts
assert_release_binding_unchanged after-final-sidecar
run_success=1

note "PASS: backup/restore canónico y dos ciclos fresh del par 0.1.14+1.99.16 -> 0.1.12+1.99.10 -> 0.1.14+1.99.16"
note "Evidencia local: ${run_dir}/final-receipt.json"
note "SHA-256 local: ${run_dir}/final-receipt.json.sha256"
