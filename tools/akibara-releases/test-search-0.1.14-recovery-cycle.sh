#!/usr/bin/env bash

# Prueba estática/dry-run. Deliberadamente reemplaza `docker` por un centinela:
# si plan/help intentan tocar el daemon, el test falla. No arranca servicios.

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "${script_dir}/../.." && pwd -P)"
rail="${script_dir}/search-0.1.14-recovery-cycle.sh"
packager_library="${script_dir}/package-search-0.1.14-production.sh"
runtime_packager="${script_dir}/package-runtime.sh"
proof="${script_dir}/search-0.1.14-recovery-proof.php"
http_smoke="${script_dir}/search-0.1.14-http-smoke.php"
protected_sql="${script_dir}/search-0.1.14-protected-fingerprint.sql"
check_table_validator="${script_dir}/search-0.1.14-check-table.awk"
contract="${repo_root}/ops/contracts/akibara-search-0.1.14.json"
business_contract="${repo_root}/ops/contracts/akibara-business-1.99.16.json"
compose_file="${repo_root}/compose.yaml"
runbook="${repo_root}/ops/akibara-search-0.1.14-recovery-runbook.md"
local_index_guard="${repo_root}/public_html/wp-content/mu-plugins/akibara-local-external-indexing-guard.php"
local_index_guard_test="${repo_root}/tools/test-local-external-indexing-guard.php"
integration_php="${repo_root}/public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php"
theme_contract="${repo_root}/ops/contracts/akibara-gp-2.1.70.json"
theme_release_test="${script_dir}/test-theme-2.1.70-production-release.sh"
theme_phpunit_config="${repo_root}/tools/akibara-gp/phpunit.xml.dist"
ai_audit_engine="${repo_root}/tools/akibara-ai-discoverability-audit.mjs"
ai_audit_test="${repo_root}/tools/test-ai-discoverability-audit.mjs"
ai_audit_wrapper="${repo_root}/tools/akibara-ai-discoverability-audit.sh"
ai_audit_policy="${repo_root}/tools/lib/ai-discoverability-policy.mjs"
ai_audit_runbook="${repo_root}/ops/akibara-ai-discoverability-audit-runbook.md"

die() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

sha256_file() {
	shasum -a 256 "$1" | awk '{print $1}'
}

sha256_stdin() {
	shasum -a 256 | awk '{print $1}'
}

for command_name in awk bash chmod cmp grep jq mktemp node php rm shasum; do
	command -v "${command_name}" >/dev/null 2>&1 || die "falta ${command_name}"
done
for required in "${rail}" "${packager_library}" "${runtime_packager}" "${proof}" "${http_smoke}" "${protected_sql}" "${check_table_validator}" "${contract}" "${business_contract}" "${compose_file}" "${runbook}" "${local_index_guard}" "${local_index_guard_test}" "${integration_php}" "${theme_contract}" "${theme_release_test}" "${theme_phpunit_config}" "${ai_audit_engine}" "${ai_audit_test}" "${ai_audit_wrapper}" "${ai_audit_policy}" "${ai_audit_runbook}"; do
	[[ -f "${required}" && ! -L "${required}" && -s "${required}" ]] \
		|| die "falta archivo regular: ${required}"
done

bash -n "${rail}"
bash -n "${BASH_SOURCE[0]}"
php -l "${proof}" >/dev/null
php -l "${http_smoke}" >/dev/null
jq -e . "${contract}" >/dev/null
jq -e . "${business_contract}" >/dev/null
jq -e . "${theme_contract}" >/dev/null
bash -n "${ai_audit_wrapper}"
bash "${theme_release_test}" >/dev/null
node "${ai_audit_test}" >/dev/null

for hash_binding in \
	'.components.search.packager_sha256|runtime_packager' \
	'.production_invariants.recovery_rollback.compose_file_sha256|compose_file' \
	'.production_invariants.recovery_rollback.packager_library_sha256|packager_library' \
	'.production_invariants.recovery_rollback.rail_sha256|rail' \
	'.production_invariants.recovery_rollback.reviewed_recovery_php_sha256|proof' \
	'.production_invariants.recovery_rollback.http_smoke.script_sha256|http_smoke' \
	'.production_invariants.recovery_rollback.integrated_mariadb.script_sha256|integration_php' \
	'.production_invariants.recovery_rollback.protected_fingerprint_sql_sha256|protected_sql' \
	'.production_invariants.recovery_rollback.check_table_validator_sha256|check_table_validator' \
	'.production_invariants.recovery_rollback.test_sha256|self' \
	'.production_invariants.recovery_rollback.runbook_sha256|runbook' \
	'.production_invariants.recovery_rollback.local_external_indexing.guard_sha256|local_index_guard' \
	'.production_invariants.recovery_rollback.local_external_indexing.isolated_test_sha256|local_index_guard_test' \
	'.paired_release.business_contract_sha256|business_contract' \
	'.integrated_theme_binding.theme_contract_sha256|theme_contract' \
	'.integrated_ai_audit.engine_sha256|ai_audit_engine' \
	'.integrated_ai_audit.test_sha256|ai_audit_test' \
	'.integrated_ai_audit.wrapper_sha256|ai_audit_wrapper' \
	'.integrated_ai_audit.policy_sha256|ai_audit_policy' \
	'.integrated_ai_audit.runbook_sha256|ai_audit_runbook'; do
	hash_path="${hash_binding%%|*}"
	file_key="${hash_binding##*|}"
	case "${file_key}" in
		runtime_packager) bound_file="${runtime_packager}" ;;
		compose_file) bound_file="${compose_file}" ;;
		packager_library) bound_file="${packager_library}" ;;
		rail) bound_file="${rail}" ;;
		proof) bound_file="${proof}" ;;
		http_smoke) bound_file="${http_smoke}" ;;
		integration_php) bound_file="${integration_php}" ;;
		protected_sql) bound_file="${protected_sql}" ;;
		check_table_validator) bound_file="${check_table_validator}" ;;
		self) bound_file="${BASH_SOURCE[0]}" ;;
		runbook) bound_file="${runbook}" ;;
		local_index_guard) bound_file="${local_index_guard}" ;;
		local_index_guard_test) bound_file="${local_index_guard_test}" ;;
		business_contract) bound_file="${business_contract}" ;;
		theme_contract) bound_file="${theme_contract}" ;;
		ai_audit_engine) bound_file="${ai_audit_engine}" ;;
		ai_audit_test) bound_file="${ai_audit_test}" ;;
		ai_audit_wrapper) bound_file="${ai_audit_wrapper}" ;;
		ai_audit_policy) bound_file="${ai_audit_policy}" ;;
		ai_audit_runbook) bound_file="${ai_audit_runbook}" ;;
		*) die "binding hash desconocido: ${file_key}" ;;
	esac
	expected_bound_sha="$(jq -er "${hash_path}" "${contract}")"
	actual_bound_sha="$(shasum -a 256 "${bound_file}" | awk '{print $1}')"
	[[ "${expected_bound_sha}" =~ ^[a-f0-9]{64}$ && "${actual_bound_sha}" == "${expected_bound_sha}" ]] \
		|| die "hash contractual stale: ${file_key}"
done

guard_receipt="$(php "${local_index_guard_test}")" || die 'test aislado del guard IndexNow falló'
grep -q '^PASS:' <<< "${guard_receipt}" || die 'test aislado del guard no emitió receipt PASS:'

invalid_http_output=''
if invalid_http_output="$(AKB_HTTP_ORIGIN='https://example.invalid' AKB_HTTP_HOST='localhost:8080' php "${http_smoke}" 2>&1)"; then
	die 'smoke HTTP aceptó un origin externo'
fi
grep -q '^AKB_HTTP_SMOKE_FAIL=' <<< "${invalid_http_output}" \
	|| die 'smoke HTTP no falló de forma estructurada ante origin externo'
grep -Fq 'AKB_HTTP_ORIGIN no identifica el contenedor interno esperado.' <<< "${invalid_http_output}" \
	|| die 'smoke HTTP falló antes de validar el origin externo'

AKB_PROOF_PATH="${proof}" AKB_RECOVERY_LIBRARY_ONLY=1 php -d display_errors=1 <<'PHP'
<?php
define( 'ABSPATH', __DIR__ . '/' );
$proof_path = getenv( 'AKB_PROOF_PATH' );
if ( ! is_string( $proof_path ) || '' === $proof_path ) {
	exit( 1 );
}
require $proof_path;
$run_id = '8dde2d65-6029-4062-89cf-7fdd5af90c77';
$raw = '{"run_id":"8dde2d65-6029-4062-89cf-7fdd5af90c77","started_at":1785986256,"watermark":"2026-08-06 03:17:36","offset":8,"updated_at":1785988285,"protocol":2,"phase":"BLOCKED","error":"legacy_or_unreadable_state","topology":"ready_swap","heartbeat_at":1785988285}';
$sha = 'b243ddb58271e4dac334ff5df18ca863b352b6119721f3d562c547897838fb0d';
akb_recovery_validate_exact_state_bytes( $raw, $run_id, $sha );
$expected_projections = array(
	'docs'   => 'post_id,HEX(title),HEX(title_norm),HEX(serie_norm),HEX(autores_norm),HEX(sku),HEX(sku_norm),tomo,stock_rank,menu_order,price_effective,visible,HEX(url),HEX(thumb_url),HEX(price_html)',
	'tokens' => 'HEX(token),field,post_id',
	'facets' => 'HEX(facet),post_id,HEX(value),HEX(label),value_num,term_id,parent_id',
);
if ( $expected_projections !== akb_recovery_preimage_projections() ) {
	fwrite( STDERR, "proyección EXCEPT ya no es byte-exacta\n" );
	exit( 1 );
}
$price_rows = array(
	array( 'min' => null, 'max' => 10000, 'label' => 'Hasta $9.990' ),
	array( 'min' => 10000, 'max' => 12000, 'label' => '$10.000 – $11.990' ),
	array( 'min' => 12000, 'max' => 15000, 'label' => '$12.000 – $14.990' ),
	array( 'min' => 15000, 'max' => null, 'label' => '$15.000 o más' ),
);
$price_raw = serialize( $price_rows );
if ( AKB_RECOVERY_PRICE_BUCKETS_SHA256 !== hash( 'sha256', $price_raw ) ) {
	fwrite( STDERR, "fixture raw de buckets no conserva el SHA auditado\n" );
	exit( 1 );
}
$preimage_pair = array(
	'buckets' => array( 'present' => true, 'raw' => $price_raw, 'autoload' => 'off' ),
	'mode'    => array( 'present' => false, 'raw' => '', 'autoload' => '' ),
);
$normalized_pair = array(
	'buckets' => array( 'present' => true, 'raw' => $price_raw, 'autoload' => 'no' ),
	'mode'    => array( 'present' => true, 'raw' => 'auto', 'autoload' => 'no' ),
);
if ( ! akb_recovery_price_pair_matches( $preimage_pair, 'preimage' )
	|| ! akb_recovery_price_pair_matches( $normalized_pair, 'normalized' )
	|| akb_recovery_price_pair_matches( $preimage_pair, 'normalized' )
	|| akb_recovery_price_pair_matches( $normalized_pair, 'preimage' ) ) {
	fwrite( STDERR, "gate puro del par de precio confundió sus fases\n" );
	exit( 1 );
}
$invalid_price_pairs = array();
$invalid_price_pairs['bucket-autoload'] = $normalized_pair;
$invalid_price_pairs['bucket-autoload']['buckets']['autoload'] = 'auto-off';
$invalid_price_pairs['manual-mode'] = $normalized_pair;
$invalid_price_pairs['manual-mode']['mode']['raw'] = 'manual';
$invalid_price_pairs['raw-byte'] = $normalized_pair;
$invalid_price_pairs['raw-byte']['buckets']['raw'] .= 'x';
$invalid_price_pairs['extra-key'] = $normalized_pair;
$invalid_price_pairs['extra-key']['unexpected'] = true;
foreach ( $invalid_price_pairs as $mutation => $invalid_price_pair ) {
	if ( akb_recovery_price_pair_matches( $invalid_price_pair, 'normalized' ) ) {
		fwrite( STDERR, "gate puro aceptó mutación del par de precio: {$mutation}\n" );
		exit( 1 );
	}
}
$receipt_run_id = '5da1270b-63f9-41d6-ac80-e4f5f187aa9c';
$receipt_semantic = str_repeat( 'a', 64 );
$receipt = array(
	'run_id'                      => $receipt_run_id,
	'started_at'                  => 1786041000,
	'finished_at'                 => 1786042000,
	'docs'                        => 1503,
	'expected'                    => 1503,
	'result'                      => 'ok',
	'phase'                       => 'DONE',
	'publication_protocol'        => 1,
	'generation_target'           => 'akb-search-' . $receipt_run_id . '-' . $receipt_semantic,
	'publication_acked'           => true,
	'purge_ack_scope'             => 'local_dispatch_only',
	'semantic_fingerprint'        => $receipt_semantic,
	'semantic_fingerprint_acked'  => true,
	'editorial_wanted_seq'        => 1,
	'editorial_fingerprint'       => str_repeat( 'b', 64 ),
	'editorial_receipt_acked'     => true,
);
if ( ! akb_recovery_final_rebuild_receipt_matches( $receipt ) ) {
	fwrite( STDERR, "receipt histórico válido fue rechazado\n" );
	exit( 1 );
}
if ( $receipt !== akb_recovery_decode_canonical_array( serialize( $receipt ) )
	|| null !== akb_recovery_decode_canonical_array( 'O:8:"stdClass":0:{}' ) ) {
	fwrite( STDERR, "decoder canónico de receipts no es fail-closed\n" );
	exit( 1 );
}
$live_generation = 'akb-gen-5da1270b63f941d6ac80e4f5f187aa9c-61';
if ( hash_equals( $live_generation, $receipt['generation_target'] ) ) {
	fwrite( STDERR, "fixture no demuestra supersesión live\n" );
	exit( 1 );
}
foreach ( array( 'generation_target', 'docs', 'extra_key', 'finished_at' ) as $mutation ) {
	$invalid = $receipt;
	if ( 'generation_target' === $mutation ) {
		$invalid['generation_target'] = $live_generation;
	} elseif ( 'docs' === $mutation ) {
		$invalid['docs'] = 1502;
	} elseif ( 'extra_key' === $mutation ) {
		$invalid['unexpected'] = true;
	} else {
		$invalid['finished_at'] = $invalid['started_at'] - 1;
	}
	if ( akb_recovery_final_rebuild_receipt_matches( $invalid ) ) {
		fwrite( STDERR, "receipt histórico mutado fue aceptado: {$mutation}\n" );
		exit( 1 );
	}
}
$case_accent_fixture = array( 'Manga', 'manga', 'mángá' );
if ( count( $case_accent_fixture ) !== count( array_unique( array_map( 'bin2hex', $case_accent_fixture ) ) ) ) {
	fwrite( STDERR, "fixture case/acento colisionó tras HEX\n" );
	exit( 1 );
}
$mutated = str_replace( 'ready_swap', 'clean_pre', $raw );
$rejected = false;
try {
	akb_recovery_validate_exact_state_bytes( $mutated, $run_id, hash( 'sha256', $mutated ) );
} catch ( RuntimeException $exception ) {
	$rejected = true;
}
if ( ! $rejected ) {
	fwrite( STDERR, "mutación semántica aceptada\n" );
	exit( 1 );
}
$rejected = false;
try {
	akb_recovery_validate_exact_state_bytes( $raw, $run_id, str_repeat( '0', 64 ) );
} catch ( RuntimeException $exception ) {
	$rejected = true;
}
if ( ! $rejected ) {
	fwrite( STDERR, "hash raw divergente aceptado\n" );
	exit( 1 );
}
PHP

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akb-search-recovery-static.XXXXXX")"
scratch="$(cd -- "${scratch}" && pwd -P)"
cleanup() {
	case "${scratch:-}" in
		*/akb-search-recovery-static.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

# Ejecuta la función real del rail sobre un snapshot sintético. Debe aceptar
# exactamente una sustitución H32 -> H31 y rechazar fila ausente, duplicada,
# preexistencia H31 o cualquier mutación fuera de esa única fila completa.
derive_function="$(awk '/^derive_rollback_protected_snapshot\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
[[ -n "${derive_function}" ]] || die 'no se pudo extraer derive_rollback_protected_snapshot'
eval "${derive_function}"
candidate_row=$'OPTION\tH616B69626172615F627573696E6573735F7365617263685F666565645F636F6E74726163745F76657273696F6E\t1320696\tPRESENT\tH32\tH6F6666'
rollback_row=$'OPTION\tH616B69626172615F627573696E6573735F7365617263685F666565645F636F6E74726163745F76657273696F6E\t1320696\tPRESENT\tH31\tH6F6666'
printf 'AKB_SEARCH_PROTECTED_FINGERPRINT_V2\nSURFACE\tbusiness_search_feed_contract_option\t1\n%s\nEND\tAKB_SEARCH_PROTECTED_FINGERPRINT_V2\n' \
	"${candidate_row}" > "${scratch}/protected-candidate.tsv"
printf 'AKB_SEARCH_PROTECTED_FINGERPRINT_V2\nSURFACE\tbusiness_search_feed_contract_option\t1\n%s\nEND\tAKB_SEARCH_PROTECTED_FINGERPRINT_V2\n' \
	"${rollback_row}" > "${scratch}/protected-rollback-reference.tsv"
protected_candidate_snapshot_sha256="$(shasum -a 256 "${scratch}/protected-candidate.tsv" | awk '{print $1}')"
protected_rollback_snapshot_sha256="$(shasum -a 256 "${scratch}/protected-rollback-reference.tsv" | awk '{print $1}')"
derive_rollback_protected_snapshot "${scratch}/protected-candidate.tsv" "${scratch}/protected-rollback-derived.tsv"
cmp -s "${scratch}/protected-rollback-reference.tsv" "${scratch}/protected-rollback-derived.tsv" \
	|| die 'sustitución exacta del fingerprint no produjo el rollback esperado'

printf 'AKB_SEARCH_PROTECTED_FINGERPRINT_V2\nEND\tAKB_SEARCH_PROTECTED_FINGERPRINT_V2\n' > "${scratch}/protected-missing.tsv"
printf '%s\n%s\n' "${candidate_row}" "${candidate_row}" > "${scratch}/protected-duplicate.tsv"
printf '%s\n%s\n' "${candidate_row}" "${rollback_row}" > "${scratch}/protected-preexisting-rollback.tsv"
awk '{gsub(/AKB_SEARCH_PROTECTED_FINGERPRINT_V2/,"AKB_SEARCH_PROTECTED_FINGERPRINT_V9")} {print}' \
	"${scratch}/protected-candidate.tsv" > "${scratch}/protected-other-mutation.tsv"
for invalid_snapshot in protected-missing.tsv protected-duplicate.tsv protected-preexisting-rollback.tsv protected-other-mutation.tsv; do
	if (
		if [[ "${invalid_snapshot}" != protected-other-mutation.tsv ]]; then
			protected_candidate_snapshot_sha256="$(shasum -a 256 "${scratch}/${invalid_snapshot}" | awk '{print $1}')"
		fi
		protected_rollback_snapshot_sha256="$(shasum -a 256 "${scratch}/protected-rollback-reference.tsv" | awk '{print $1}')"
		derive_rollback_protected_snapshot "${scratch}/${invalid_snapshot}" "${scratch}/${invalid_snapshot}.out"
	) >/dev/null 2>&1; then
		die "sustitución exacta aceptó fixture inválido: ${invalid_snapshot}"
	fi
done

fake_bin="${scratch}/bin"
docker_marker="${scratch}/docker-called"
no_write_dir="${repo_root}/.local-backups/plan-must-not-create-${BASHPID}"
mkdir -p -- "${fake_bin}"

# Fixtures negativas ejecutables: una sola tabla OK, o una segunda tabla con
# warning/error, jamás acreditan CHECK TABLE completo.
printf 'TABLE\twp_akb_search_docs\nakibara.wp_akb_search_docs\tcheck\tstatus\tOK\nTABLE\twp_akb_search_tokens\nakibara.wp_akb_search_tokens\tcheck\tstatus\tOK\n' \
	> "${scratch}/check-all-ok.tsv"
printf 'TABLE\twp_akb_search_docs\nakibara.wp_akb_search_docs\tcheck\tstatus\tOK\nTABLE\twp_akb_search_tokens\n' \
	> "${scratch}/check-missing.tsv"
printf 'TABLE\twp_akb_search_docs\nakibara.wp_akb_search_docs\tcheck\tstatus\tOK\nTABLE\twp_akb_search_tokens\nakibara.wp_akb_search_tokens\tcheck\terror\tCorrupt\n' \
	> "${scratch}/check-error.tsv"
printf 'TABLE\twp_akb_search_docs\nakibara.wp_akb_search_docs\tcheck\tstatus\tOK\nTABLE\twp_akb_search_tokens\nakibara.wp_akb_search_docs\tcheck\tstatus\tOK\n' \
	> "${scratch}/check-duplicate.tsv"
printf 'TABLE\twp_akb_search_docs\nakibara.wp_akb_search_docs\tcheck\tstatus\tOK\nTABLE\twp_akb_search_tokens\nakibara.wp_akb_search_unknown\tcheck\tstatus\tOK\n' \
	> "${scratch}/check-unknown.tsv"
printf 'TABLE\twp_akb_search_docs\notra_db.wp_akb_search_docs\tcheck\tstatus\tOK\nTABLE\twp_akb_search_tokens\nakibara.wp_akb_search_tokens\tcheck\tstatus\tOK\n' \
	> "${scratch}/check-wrong-database.tsv"
awk -F '\t' -v expected=2 -v database=akibara -f "${check_table_validator}" "${scratch}/check-all-ok.tsv" \
	|| die 'validator CHECK rechazó fixture íntegro'
if awk -F '\t' -v expected=2 -v database=akibara -f "${check_table_validator}" "${scratch}/check-missing.tsv" >/dev/null 2>&1; then
	die 'validator CHECK aceptó tabla faltante'
fi
if awk -F '\t' -v expected=2 -v database=akibara -f "${check_table_validator}" "${scratch}/check-error.tsv" >/dev/null 2>&1; then
	die 'validator CHECK aceptó una tabla no-OK'
fi
if awk -F '\t' -v expected=2 -v database=akibara -f "${check_table_validator}" "${scratch}/check-duplicate.tsv" >/dev/null 2>&1; then
	die 'validator CHECK aceptó OK duplicado y tabla faltante'
fi
if awk -F '\t' -v expected=2 -v database=akibara -f "${check_table_validator}" "${scratch}/check-unknown.tsv" >/dev/null 2>&1; then
	die 'validator CHECK aceptó tabla desconocida'
fi
if awk -F '\t' -v expected=2 -v database=akibara -f "${check_table_validator}" "${scratch}/check-wrong-database.tsv" >/dev/null 2>&1; then
	die 'validator CHECK aceptó tabla bajo otro schema'
fi

printf '#!/usr/bin/env bash\nprintf called > %q\nexit 99\n' "${docker_marker}" > "${fake_bin}/docker"
chmod 700 "${fake_bin}/docker"

PATH="${fake_bin}:${PATH}" bash "${rail}" plan --backup-dir "${no_write_dir}" > "${scratch}/plan.txt"
[[ ! -e "${docker_marker}" ]] || die 'plan invocó Docker'
[[ ! -e "${no_write_dir}" ]] || die 'plan creó el directorio de backup'
grep -qx 'PRODUCTION_GATE=CLOSED' "${scratch}/plan.txt" || die 'plan no mantuvo gate cerrado'
grep -qx 'mode=plan-only-no-docker-no-writes' "${scratch}/plan.txt" || die 'plan no acredita modo seco'
grep -q '^business_1_99_16_seal=' "${scratch}/plan.txt" || die 'plan no reporta el sello Business 1.99.16'
grep -q '^theme_2_1_70_seal=' "${scratch}/plan.txt" || die 'plan no reporta el sello Theme 2.1.70'
grep -Fqx 'node_image=node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b' "${scratch}/plan.txt" \
	|| die 'plan no reporta la imagen Node pinneada'

PATH="${fake_bin}:${PATH}" bash "${rail}" --help > "${scratch}/help.txt"
[[ ! -e "${docker_marker}" ]] || die 'help invocó Docker'
grep -q -- '--expected-run-id UUID' "${scratch}/help.txt" || die 'help omite run_id explícito'
grep -q -- '--expected-state-sha256 SHA256' "${scratch}/help.txt" || die 'help omite hash raw explícito'

# Dump canónico, privacidad y restore hermético.
for required_text in \
	'umask 077' \
	'--single-transaction' \
	'--quick' \
	'--skip-lock-tables' \
	'--skip-comments' \
	'--hex-blob' \
	'--default-character-set=utf8mb4' \
	'--routines' \
	'--events' \
	'--triggers' \
	'--order-by-primary' \
	'gzip -n -9' \
	'gzip -t' \
	'mariadb@sha256:a794d9eb009e20de605858a11f32f63b4075cbd197c650436f0e3b457e4caed7' \
	'wordpress@sha256:909702ad806b574fafa50894808205724a2a78dc5a3e193c09c85f86322d89eb' \
	'wordpress@sha256:b65f6fb4d8b669974d4e8d6f204bc5a11d67f4877473ba5ee97af0efd39e3d19' \
	'node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b' \
	'docker network create --internal' \
	'--tmpfs /var/lib/mysql:rw,nosuid,nodev,noexec,size=4g' \
	"--health-cmd='healthcheck.sh --connect --innodb_initialized'" \
	'--health-interval=5s' \
	'--health-timeout=5s' \
	'--health-retries=20' \
	'[[ "${health}" == missing ]] && die '\''MariaDB efímera no expone Healthcheck'\''' \
	'--tmpfs /run/apache2:rw,nosuid,nodev,noexec,size=16m' \
	'--tmpfs /var/lock/apache2:rw,nosuid,nodev,noexec,size=8m' \
	'--tmpfs /var/lib/php/sessions:rw,nosuid,nodev,noexec,size=64m' \
	'--entrypoint apache2-foreground' \
	'--env AKB_HTTP_HOST=localhost:8080' \
	"defined( 'DISABLE_WP_CRON' )" \
	"defined( 'WP_HTTP_BLOCK_EXTERNAL' )" \
	"defined( 'WP_HOME' ) || define( 'WP_HOME', 'https://akibara.cl' )" \
	"\$_SERVER['HTTP_HOST'] = 'akibara.cl'" \
	'prepare_theme_tree candidate 2.1.70' \
	'prepare_theme_tree preimage 2.1.69' \
	'prepare_theme_test_tree "${artifact_root}/theme-test-harness"' \
	'validate_tree_inventory "${destination}" "${inventory}" akibara-gp' \
	'[[ "$(find "${runtime_tree}/tests" -type f | wc -l | tr -d '\''[:space:]'\'')" == 18 ]]' \
	'--volume "${theme_test_tree}:/workspace/public_html/wp-content/themes/akibara-gp:ro"' \
	'run_theme_quality_gate "${iteration}" "${iteration_dir}"' \
	'wp_assert_theme_state "${wp_user}" "${wp_password}"' \
	'theme-quality-receipt.json' \
	'ai-discoverability-audit.json' \
	'run_ai_discoverability_audit "${iteration}" "${http_container}"' \
	'--fetch-base-url "${fetch_base}"' \
	'--tmpfs /audit:rw,nosuid,nodev,noexec,size=256m' \
	'search-0.1.14-check-table.awk' \
	'canonical_redump_sha256' \
	'rollback-mixed-midpoint.log' \
	'rollback-ready-pair.log' \
	'forward-mixed-midpoint.log' \
	'forward-ready-pair.log' \
	'protected-after-forward-v2.tsv' \
	'protected-before-http.tsv' \
	'protected-after-http.tsv' \
	'derived-price-pair-after-recovery.tsv' \
	'derived-price-pair-after-first-v2.tsv' \
	'derived-price-pair-after-rollback-boot.tsv' \
	'derived-price-pair-after-v1.tsv' \
	'derived-price-pair-after-forward-migration.tsv' \
	'derived-price-pair-after-forward-v2.tsv' \
	'derived-price-pair-before-http.tsv' \
	'derived-price-pair-after-http.tsv' \
	'derived-price-pair-after-integrated-mariadb.tsv' \
	'integrated-mariadb-candidate.log' \
	'source-final-verification.json' \
	'final-receipt.json.sha256'; do
	grep -Fq -- "${required_text}" "${rail}" || die "rail omite: ${required_text}"
done

if grep -Fq -- '--volume "${theme_tests_root}:/workspace/public_html/wp-content/themes/akibara-gp/tests:ro"' "${rail}"; then
	die 'rail conserva un bind mount tests anidado bajo el ZIP candidato read-only'
fi
[[ "$(grep -Fc -- '--volume "${theme_test_tree}:/workspace/public_html/wp-content/themes/akibara-gp:ro"' "${rail}" || true)" == 2 ]] \
	|| die 'Node/PHPUnit no consumen exactamente dos veces el harness Theme aislado'

[[ "$(grep -Fc -- '--health-cmd=' "${rail}" || true)" == 1 ]] \
	|| die 'rail no configura exactamente un healthcheck MariaDB efímero'
[[ "$(grep -Fc -- '[[ "${health}" == missing ]] && die '\''MariaDB efímera no expone Healthcheck'\''' "${rail}" || true)" == 1 ]] \
	|| die 'rail no falla inmediatamente cuando Docker omite Health'

# capture_search_schema_root recorre una lista por stdin. Ningún docker exec que
# usa --execute puede conservar -i: el primer proceso consumiría las tablas
# restantes del while y el CHECK 1:1 quedaría truncado a una sola tabla.
capture_schema_body="$(awk '/^capture_search_schema_root\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
grep -Fq 'while IFS= read -r table' <<< "${capture_schema_body}" \
	|| die 'capture Search ya no itera el inventario por stdin'
grep -Fq 'docker exec -e MYSQL_PWD="${password}" "${container}"' <<< "${capture_schema_body}" \
	|| die 'capture Search no ejecuta consultas sin stdin interactivo'
if grep -Fq 'docker exec -i' <<< "${capture_schema_body}"; then
	die 'capture Search puede consumir el stdin del while y truncar CHECK TABLE'
fi

# MariaDB 11.4 carece de information_schema.ROUTINE_PRIVILEGES. El rail debe
# conservar las comprobaciones USER/SCHEMA/TABLE/COLUMN de ambos usuarios y
# auditar grants específicos de rutinas como root en mysql.procs_priv, acotando
# cada identidad al User/Host exacto creado por el recovery.
routine_privilege_helper_body="$(awk '/^ephemeral_routine_privilege_count\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
limited_wp_user_body="$(awk '/^create_limited_wp_user\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
integrated_mariadb_body="$(awk '/^run_integrated_mariadb_mode\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
for privilege_body in "${limited_wp_user_body}" "${integrated_mariadb_body}"; do
	for privilege_table in USER_PRIVILEGES SCHEMA_PRIVILEGES TABLE_PRIVILEGES COLUMN_PRIVILEGES; do
		grep -Fq "information_schema.${privilege_table}" <<< "${privilege_body}" \
			|| die "gate MariaDB perdió comprobación ${privilege_table} en uno de sus usuarios"
	done
done
if grep -Ev '^[[:space:]]*#' "${rail}" \
	| grep -Fq 'information_schema.ROUTINE_PRIVILEGES'; then
	die 'gate MariaDB usa ROUTINE_PRIVILEGES inexistente en MariaDB 11.4'
fi
[[ "$(grep -Fc 'FROM mysql.procs_priv' <<< "${routine_privilege_helper_body}" || true)" == 1 ]] \
	|| die 'helper MariaDB no audita mysql.procs_priv exactamente una vez'
grep -Fq 'ephemeral_root_query "${root_password}"' <<< "${routine_privilege_helper_body}" \
	|| die 'helper MariaDB no audita rutinas mediante la conexión root efímera'
grep -Fq "WHERE User='\${username}' AND Host='\${host}';" <<< "${routine_privilege_helper_body}" \
	|| die 'helper MariaDB no aísla grants de rutina por User/Host exactos'
grep -Fq '[[ "${username}" =~ ^[a-z0-9_]{1,32}$' <<< "${routine_privilege_helper_body}" \
	|| die 'helper MariaDB no valida el User antes de interpolarlo'
grep -Fq "&& \"\${host}\" == '%' ]]" <<< "${routine_privilege_helper_body}" \
	|| die 'helper MariaDB no restringe el Host al comodín creado'
grep -Fq "ephemeral_routine_privilege_count \"\${root_password}\" \"\${username}\" '%'" <<< "${limited_wp_user_body}" \
	|| die 'usuario WP no audita grants de rutina para su identidad exacta'
grep -Fq "ephemeral_routine_privilege_count \"\${root_password}\" \"\${fixture_user}\" '%'" <<< "${integrated_mariadb_body}" \
	|| die 'usuario integrado no audita grants de rutina para su identidad exacta'
grep -Fq '[[ "${wrong_schema_privileges}" == 0 && "${table_column_privileges}" == 0' <<< "${limited_wp_user_body}" \
	|| die 'usuario WP perdió aislamiento de schema/tabla/columna'
grep -Fq '&& "${routine_privileges}" == 0 ]]' <<< "${limited_wp_user_body}" \
	|| die 'usuario WP no exige cero grants específicos de rutina'
grep -Fq '&& "${wrong_schema_privileges}" == 0 && "${table_column_privileges}" == 0' <<< "${integrated_mariadb_body}" \
	|| die 'usuario integrado perdió aislamiento de schema/tabla/columna'
grep -Fq '&& "${routine_privileges}" == 0 ]]' <<< "${integrated_mariadb_body}" \
	|| die 'usuario integrado no exige cero grants específicos de rutina'

# El proof conserva declare(strict_types=1). WP-CLI eval-file antepone un
# wrapper eval y lo vuelve fatal; ambos modos deben usar el transporte oficial
# --use-include para que la declaración siga siendo la primera sentencia.
[[ "$(grep -Fc 'eval-file /tmp/akb-search-recovery-proof.php --use-include' "${rail}" || true)" == 2 ]] \
	|| die 'WP proof no usa --use-include exactamente en sus dos modos'
if grep -E 'eval-file /tmp/akb-search-recovery-proof\.php([[:space:]]|\))' "${rail}" \
	| grep -Fvq -- '--use-include'; then
	die 'WP proof conserva una ruta eval incompatible con strict_types'
fi

# El boot normal y el smoke HTTP comparten el active_plugins del restore. La DB
# efímera debe reducirlo byte-exacto a WooCommerce + el par, después del restore
# y antes de crear/usar el usuario WP; la DB fuente nunca se modifica.
runtime_isolation_body="$(awk '/^isolate_ephemeral_runtime_plugins\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
for allowed_plugin in \
	'woocommerce/woocommerce.php' \
	'akibara-business/akibara-business.php' \
	'akibara-search/akibara-search.php' \
	'seo-by-rank-math/rank-math.php'; do
	[[ "$(grep -Fc "${allowed_plugin}" <<< "${runtime_isolation_body}" || true)" == 1 ]] \
		|| die "allowlist efímera no contiene exactamente una vez ${allowed_plugin}"
done
if grep -Fq 'woocommerce-mercadopago' <<< "${runtime_isolation_body}"; then
	die 'allowlist efímera conserva la integración externa que rompió el boot'
fi
for required_isolation_text in \
	'ephemeral_root_query "${root_password}"' \
	"SELECT COUNT(*) FROM wp_options WHERE option_name='active_plugins';" \
	"UPDATE wp_options SET option_value=UNHEX('\${expected_hex}') WHERE option_name='active_plugins';" \
	"SELECT HEX(option_value) FROM wp_options WHERE option_name='active_plugins';" \
	'[[ "${actual_hex}" == "${expected_hex}" ]]'; do
	grep -Fq "${required_isolation_text}" <<< "${runtime_isolation_body}" \
		|| die "aislamiento active_plugins omite: ${required_isolation_text}"
done
run_iteration_body="$(awk '/^run_iteration\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
restore_line="$(grep -nF 'start_fresh_restore "${iteration}"' <<< "${run_iteration_body}" | cut -d: -f1)"
isolation_line="$(grep -nF 'isolate_ephemeral_runtime_plugins "${root_password}"' <<< "${run_iteration_body}" | cut -d: -f1)"
limited_user_line="$(grep -nF 'create_limited_wp_user "${root_password}"' <<< "${run_iteration_body}" | cut -d: -f1)"
theme_quality_line="$(grep -nF 'run_theme_quality_gate "${iteration}" "${iteration_dir}"' <<< "${run_iteration_body}" | cut -d: -f1)"
theme_initial_line="$(grep -nF 'wp_assert_theme_state "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/theme-candidate-initial.log"' <<< "${run_iteration_body}" | cut -d: -f1)"
recovery_start_line="$(grep -nF 'wp_proof recover-ready-swap' <<< "${run_iteration_body}" | cut -d: -f1)"
[[ "${restore_line}" =~ ^[0-9]+$ && "${isolation_line}" =~ ^[0-9]+$ && "${limited_user_line}" =~ ^[0-9]+$ \
	&& "${theme_quality_line}" =~ ^[0-9]+$ && "${theme_initial_line}" =~ ^[0-9]+$ && "${recovery_start_line}" =~ ^[0-9]+$ \
	&& "${restore_line}" -lt "${isolation_line}" && "${isolation_line}" -lt "${limited_user_line}" \
	&& "${limited_user_line}" -lt "${theme_quality_line}" && "${theme_quality_line}" -lt "${theme_initial_line}" \
	&& "${theme_initial_line}" -lt "${recovery_start_line}" ]] \
	|| die 'orden restore/aislamiento/Theme candidato/recovery no es fail-closed'

# El receipt de boot debe terminar en LF real. Un `\\n` doble dentro del PHP
# evaluado produce los dos bytes literales y hace imposible el grep de línea.
wp_boot_body="$(awk '/^wp_boot\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
grep -Fq 'fwrite(STDOUT, "AKB_BOOT_OK\n");' <<< "${wp_boot_body}" \
	|| die 'boot WP no emite receipt con LF real'
if grep -Fq 'AKB_BOOT_OK\\n' <<< "${wp_boot_body}"; then
	die 'boot WP vuelve a emitir backslash-n literal'
fi
grep -Fq 'grep -cx '\''AKB_BOOT_OK'\''' <<< "${wp_boot_body}" \
	|| die 'boot WP ya no exige un receipt de línea único'

if grep -Ev '^[[:space:]]*#' "${rail}" \
	| grep -Eq 'docker compose[^[:cntrl:]]+(up|start|restart|stop)|wp cron|action-scheduler run([^a-z-]|$)|--publish|-p[[:space:]][0-9]+:'; then
	die 'rail contiene start/stop main, cron, drain de grupo o puerto publicado'
fi
for required_text in \
	'AKB_HTTP_SMOKE_SCHEMA' \
	'CURLOPT_FOLLOWLOCATION => false' \
	"CURLOPT_PROXY          => ''" \
	"'Host: ' . \$host_header" \
	'AKB_HTTP_SMOKE_MAX_BODY_BYTES' \
	'AKB_HTTP_SMOKE_RECEIPT=' \
	'data-akb-unit="tomo"' \
	'>1 tomo<' \
	'akb-shop-toolbar--empty' \
	'class="akb-filter-toggle"'; do
	grep -Fq -- "${required_text}" "${http_smoke}" || die "smoke HTTP omite: ${required_text}"
done
if grep -Eq 'CURLOPT_FOLLOWLOCATION[[:space:]]*=>[[:space:]]*true|https?://(akibara\.cl|www\.akibara\.cl)' "${http_smoke}"; then
	die 'smoke HTTP puede seguir redirects o alcanzar producción'
fi
[[ "$(grep -Fc 'run_http_smoke "${iteration}"' "${rail}" || true)" == 1 ]] \
	|| die 'smoke HTTP no está exactamente una vez dentro de cada restore'
[[ "$(grep -Fc 'run_integrated_mariadb_gate "${iteration}"' "${rail}" || true)" == 1 ]] \
	|| die 'gate MariaDB integrado no está exactamente una vez dentro de cada restore'
[[ "$(grep -Fc 'run_theme_quality_gate "${iteration}" "${iteration_dir}"' "${rail}" || true)" == 1 ]] \
	|| die 'gate Theme no está exactamente una vez dentro de cada restore'
[[ "$(grep -Fc 'run_ai_discoverability_audit "${iteration}" "${http_container}" "${iteration_dir}"' "${rail}" || true)" == 1 ]] \
	|| die 'auditor IA no está exactamente una vez dentro de cada restore HTTP'

wp_base_body="$(awk '/^wp_base_args\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
http_body="$(awk '/^run_http_smoke\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
theme_quality_body="$(awk '/^run_theme_quality_gate\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
ai_http_body="$(awk '/^run_ai_discoverability_audit\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
for runtime_body in "${wp_base_body}" "${http_body}" "${integrated_mariadb_body}"; do
	grep -Fq '"${theme_candidate_tree}:/var/www/html/wp-content/themes/akibara-gp:ro"' <<< "${runtime_body}" \
		|| die 'un runtime WordPress integrado no monta el Theme candidato exacto'
done
for required_theme_gate in \
	'--network none' \
	'"${theme_test_tree}:/workspace/public_html/wp-content/themes/akibara-gp:ro"' \
	'[[ -n "${theme_test_tree}" && -d "${theme_test_tree}" && ! -L "${theme_test_tree}" ]]' \
	'grep -Eq '\''^# tests 31$'\''' \
	'grep -Fq '\''OK (18 tests, 52 assertions)'\''' \
	'node tools/test-ai-discoverability-audit.mjs' \
	'external_network_dependency_allowed:false'; do
	grep -Fq -- "${required_theme_gate}" <<< "${theme_quality_body}" \
		|| die "gate Theme/IA aislado omite: ${required_theme_gate}"
done
for required_ai_http in \
	'--network "${active_network}"' \
	'--read-only --tmpfs /audit:rw,nosuid,nodev,noexec,size=256m' \
	'"${ai_audit_engine}:/opt/akibara/akibara-ai-discoverability-audit.mjs:ro"' \
	'"${ai_audit_policy}:/opt/akibara/lib/ai-discoverability-policy.mjs:ro"' \
	'--fetch-base-url "${fetch_base}"' \
	'.configuration.redirect_policy == "manual_fail_closed"' \
	'.summary.exit_code == 0'; do
	grep -Fq -- "${required_ai_http}" <<< "${ai_http_body}" \
		|| die "auditor IA HTTP hermético omite: ${required_ai_http}"
done
if grep -Eq -- '--network[[:space:]]+(host|bridge)|--publish|-p[[:space:]][0-9]+:' <<< "${theme_quality_body}${ai_http_body}"; then
	die 'gate Theme/IA usa red o puertos no autorizados'
fi
[[ "$(grep -xc 'validate_contracts' "${rail}" || true)" == 7 ]] \
	|| die 'contratos no se validan al inicio y alrededor de ciclos/freeze/receipt/sidecar'
[[ "$(grep -Fc 'assert_release_binding_unchanged' "${rail}" || true)" == 12 ]] \
	|| die 'binding inmutable no cubre definición y once checkpoints textuales'
for release_binding_text in \
	'AKB_SEARCH_RELEASE_BINDING_V1' \
	'release-binding-initial.tsv' \
	'business_candidate_inventory' \
	'theme_preimage_archive' \
	'theme_preimage_inventory' \
	'theme_preimage_sidecar' \
	'theme_candidate_archive' \
	'theme_candidate_inventory' \
	'theme_candidate_sidecar' \
	'ai_audit_engine' \
	'ai_audit_policy' \
	'assert_release_binding_unchanged "before-iteration-${iteration}"' \
	'assert_release_binding_unchanged "after-iteration-${iteration}"' \
	'initial_snapshot_sha256:$evidence_snapshot_sha' \
	'assert_release_binding_unchanged after-final-sidecar-materialized' \
	'assert_release_binding_unchanged after-final-sidecar'; do
	grep -Fq -- "${release_binding_text}" "${rail}" \
		|| die "binding inmutable omite: ${release_binding_text}"
done
capture_binding_body="$(awk '/^capture_release_binding\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
for exact_binding_guard in \
	'grep -c $'"'"'^FILE\t'"'"' "${output}"' \
	'== 37 ]]' \
	"awk 'END { print NR }'" \
	'== 39 ]]' \
	'23 evidencias y 14 artefactos'; do
	grep -Fq -- "${exact_binding_guard}" <<< "${capture_binding_body}" \
		|| die "binding inmutable no fija cardinalidad exacta: ${exact_binding_guard}"
done
grep -Fq "'  PASS  proveedor Business ACTIVE conserva snapshot y presupuesto SQL <=11'" "${rail}" \
	|| die 'rail no exige provider Business ACTIVE'
grep -Fq "'  PASS  Reconciler real verifica 10 productos sin divergencia ni mutación lógica'" "${rail}" \
	|| die 'rail no exige Reconciler zero-divergence'
grep -Fq "chmod 600 \"\${run_dir}/final-receipt.json.sha256.partial\"" "${rail}" \
	|| die 'sidecar SHA final no queda 0600'
if grep -Fq 'information_schema.TABLE_ROWS' "${rail}" "${proof}" "${protected_sql}"; then
	die 'rail usa estimaciones TABLE_ROWS'
fi
if grep -Eiq '^[[:space:]]*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|CALL)[[:space:]]' "${protected_sql}"; then
	die 'fingerprint protegido no es SQL read-only'
fi
if grep -Ev '^[[:space:]]*#' "${rail}" | grep -Eq -- '--env[[:space:]]+MARIADB_(DATABASE|USER|PASSWORD)='; then
	die 'boot MariaDB efímera crea DB/usuario implícito'
fi
grep -Fq -- '--env WORDPRESS_DB_USER="${wp_user}"' "${rail}" || die 'WP-CLI no usa usuario limitado'
if grep -Fq -- '--env WORDPRESS_DB_USER=root' "${rail}"; then
	die 'WP-CLI usa root'
fi
grep -Fq 'action-scheduler action run "${ids[@]}"' "${rail}" || die 'drain no usa IDs explícitos'
grep -Fq "hook='akb_search_rebuild_batch' AND status='pending'" "${rail}" || die 'selector AS no es hook+pending exacto'
grep -Fq 'action-scheduler action run "${action_id}"' "${rail}" || die 'drain live no usa un ID explícito'
grep -Fq "BINARY a.hook='akb_search_pump_live' AND BINARY a.status='pending' AND a.claim_id=0 AND a.priority=10 AND a.extended_args IS NULL AND BINARY g.slug='akibara-search'" "${rail}" \
	|| die 'selector AS live no es hook+pending+grupo exacto'
grep -Fq 'ORDER BY a.attempts ASC, a.scheduled_date_gmt ASC, a.action_id ASC' "${rail}" \
	|| die 'selector AS live no conserva el orden fail-closed'
grep -Fq 'State/pending activo sin batch pending: no se invoca Reconciler' "${rail}" || die 'falta cierre sin batch'
pending_snapshot_body="$(awk '/^pending_rebuild_snapshot\(\) \{/{inside=1} /^drain_search_batches\(\) \{/{inside=0} inside{print}' "${rail}")"
batch_action_body="$(awk '/^batch_action_snapshot\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
drain_body="$(awk '/^drain_search_batches\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
live_args_body="$(awk '/^live_pump_args_snapshot\(\) \{/{inside=1} /^live_pump_action_snapshot\(\) \{/{inside=0} inside{print}' "${rail}")"
live_action_body="$(awk '/^live_pump_action_snapshot\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
live_work_body="$(awk '/^live_work_snapshot\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
live_active_body="$(awk '/^live_pump_active_snapshot\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
live_drain_body="$(awk '/^drain_live_work\(\) \{/{inside=1} inside{print} inside && /^}/{exit}' "${rail}")"
as_runtime_body="$(awk '/^action_scheduler_provider_expected_receipt\(\) \{/{inside=1} /^live_pump_args_snapshot\(\) \{/{inside=0} inside{print}' "${rail}")"
migration_body="$(awk '/^action_scheduler_migration_active_snapshot\(\) \{/{inside=1} /^live_work_snapshot\(\) \{/{inside=0} inside{print}' "${rail}")"
[[ -n "${as_runtime_body}" && -n "${migration_body}" ]] \
	|| die 'no se pudieron extraer runtime/migración Action Scheduler'
eval "${as_runtime_body}"
eval "${migration_body}"
as_provider_fixture="$(action_scheduler_provider_expected_receipt)"
jq -e '
  .schema == "akibara-action-scheduler-provider-inventory-v1"
  and .version == "3.9.3"
  and .mount_root == "/var/www/html"
  and (.files | type == "array" and length == 7)
  and ([.files[].path] | unique | length == 7)
  and all(.files[]; (.path | startswith("wp-content/plugins/woocommerce/packages/action-scheduler/")) and (.sha256 | test("^[a-f0-9]{64}$")))
' <<< "${as_provider_fixture}" >/dev/null || die 'inventario proveedor Action Scheduler no tiene forma sellable exacta'
while IFS=$'\t' read -r provider_path provider_sha; do
	provider_file="${repo_root}/public_html/${provider_path}"
	[[ -f "${provider_file}" && ! -L "${provider_file}" && "$(sha256_file "${provider_file}")" == "${provider_sha}" ]] \
		|| die "drift proveedor Action Scheduler: ${provider_path}"
done < <(jq -r '.files[] | [.path,.sha256] | @tsv' <<< "${as_provider_fixture}")
provider_runtime_fixture="$(jq -cnS --argjson provider "${as_provider_fixture}" '{schema:"akibara-action-scheduler-runtime-v1",version:"3.9.3",store_class:"ActionScheduler_DBStore",migration_status:{present:true,value:"complete"},runtime_error:"",provider:$provider,provider_error:""}')"
assert_action_scheduler_runtime_provider "${provider_runtime_fixture}"
for provider_drift_fixture in wrong_hash missing_file extra_file provider_error; do
	case "${provider_drift_fixture}" in
		wrong_hash) drifted_provider_runtime="$(jq -cS '.provider.files[0].sha256="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"' <<< "${provider_runtime_fixture}")" ;;
		missing_file) drifted_provider_runtime="$(jq -cS 'del(.provider.files[0])' <<< "${provider_runtime_fixture}")" ;;
		extra_file) drifted_provider_runtime="$(jq -cS '.provider.files += [{path:"wp-content/plugins/woocommerce/packages/action-scheduler/foreign.php",sha256:"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}]' <<< "${provider_runtime_fixture}")" ;;
		provider_error) drifted_provider_runtime="$(jq -cS '.provider_error="provider_file_unreadable:0"' <<< "${provider_runtime_fixture}")" ;;
	esac
	if (
		die() { exit 1; }
		assert_action_scheduler_runtime_provider "${drifted_provider_runtime}" >/dev/null 2>&1
	); then
		die "binding proveedor acepta drift: ${provider_drift_fixture}"
	fi
done
for required_pending_text in \
	'array("allowed_classes" => false)' \
	'count($requests) < 1 || count($requests) > 4' \
	'"schema" => "akibara-search-pending-snapshot-v1"' \
	'"raw_sha256" => hash("sha256", $raw)' \
	'"token_sha256" => hash("sha256", $token)' \
	'wait_until_epoch "${not_before}"'; do
	grep -Fq "${required_pending_text}" "${rail}" \
		|| die "tick pending fail-closed omite: ${required_pending_text}"
done
pending_fixture_token='5da1270b-63f9-41d6-ac80-e4f5f187aa9c'
pending_fixture_hex="$(php -r '
$value = array(
    "protocol" => 2,
    "requests" => array(array(
        "token" => "5da1270b-63f9-41d6-ac80-e4f5f187aa9c",
        "seq" => 3,
        "version" => "1.7.0",
        "reason" => "schema_migration",
        "requested_at" => 1786040624,
        "not_before" => 1786040634,
    )),
);
echo strtoupper(bin2hex(serialize($value)));
')"
pending_fixture_receipt="$(
	(
		AKB_TEST_PENDING_HEX="${pending_fixture_hex}"
		export AKB_TEST_PENDING_HEX
		die() { printf 'fixture parser rechazó pending observado: %s\n' "$*" >&2; exit 1; }
		ephemeral_wp_query() { printf '%s\n' "${AKB_TEST_PENDING_HEX}"; }
		eval "${pending_snapshot_body}"
		pending_rebuild_snapshot fixture_user fixture_password
	)
)"
jq -e '
  .schema == "akibara-search-pending-snapshot-v1"
  and .protocol == 2
  and .request_count == 1
  and .max_not_before == 1786040634
  and .requests[0].seq == 3
  and .requests[0].reason == "schema_migration"
  and .requests[0].requested_at == 1786040624
  and .requests[0].not_before == 1786040634
' <<< "${pending_fixture_receipt}" >/dev/null \
	|| die 'parser pending no reproduce la barrera móvil observada'
if grep -Fq "${pending_fixture_token}" <<< "${pending_fixture_receipt}"; then
	die 'receipt pending expone token raw en vez de su hash'
fi

batch_fixture_run='60bf9f66-6f65-4bea-b897-f645059564c0'
batch_v2_args_hex="$(php -r 'echo strtoupper(bin2hex(json_encode(array($argv[1],"baseline"))));' "${batch_fixture_run}")"
batch_v1_args_hex="$(php -r 'echo strtoupper(bin2hex(json_encode(array("offset"=>0))));')"
batch_v2_fixture="$(
	(
		die() { printf 'fixture batch v2 rechazada: %s\n' "$*" >&2; exit 1; }
		ephemeral_wp_query() { printf 'akb_search_rebuild_batch|pending|%s|1|akibara-search|0|0\n' "${batch_v2_args_hex}"; }
		eval "${batch_action_body}"
		batch_action_snapshot fixture_user fixture_password 383318 v2 "${batch_fixture_run}"
	)
)"
batch_v1_fixture="$(
	(
		die() { printf 'fixture batch legacy rechazada: %s\n' "$*" >&2; exit 1; }
		ephemeral_wp_query() {
			case "$3" in
				*'FROM wp_actionscheduler_actions'*) printf 'akb_search_rebuild_batch|pending|%s|1|akibara-search|0|0\n' "${batch_v1_args_hex}" ;;
				*'JSON_EXTRACT(option_value'*) printf '0\n' ;;
				*) return 1 ;;
			esac
		}
		eval "${batch_action_body}"
		batch_action_snapshot fixture_user fixture_password 383319 legacy-v1 "${batch_fixture_run}"
	)
)"
jq -e '.generation=="v2" and .action_id==383318 and .args.scope=="baseline" and (.args.run_id_sha256|test("^[a-f0-9]{64}$"))' \
	<<< "${batch_v2_fixture}" >/dev/null || die 'snapshot batch v2 no conserva run/scope exactos'
jq -e '.generation=="legacy-v1" and .action_id==383319 and .args=={offset:0} and (.state_run_id_sha256|test("^[a-f0-9]{64}$"))' \
	<<< "${batch_v1_fixture}" >/dev/null || die 'snapshot batch legacy no liga offset con state'
for unsafe_batch_fixture in legacy_extra_key legacy_offset_drift v2_with_legacy_args claimed extended_args; do
	if (
		die() { exit 1; }
		fixture_args="${batch_v1_args_hex}"; fixture_generation=legacy-v1; fixture_claim=0; fixture_extended=1; fixture_offset=0
		case "${unsafe_batch_fixture}" in
			legacy_extra_key) fixture_args="$(php -r 'echo strtoupper(bin2hex(json_encode(array("offset"=>0,"extra"=>true))));')" ;;
			legacy_offset_drift) fixture_offset=1 ;;
			v2_with_legacy_args) fixture_generation=v2 ;;
			claimed) fixture_claim=9 ;;
			extended_args) fixture_extended=0 ;;
		esac
		ephemeral_wp_query() {
			case "$3" in
				*'FROM wp_actionscheduler_actions'*) printf 'akb_search_rebuild_batch|pending|%s|%s|akibara-search|%s|0\n' "${fixture_args}" "${fixture_extended}" "${fixture_claim}" ;;
				*'JSON_EXTRACT(option_value'*) printf '%s\n' "${fixture_offset}" ;;
				*) return 1 ;;
			esac
		}
		eval "${batch_action_body}"
		batch_action_snapshot fixture_user fixture_password 383320 "${fixture_generation}" "${batch_fixture_run}" >/dev/null 2>&1
	); then
		die "snapshot batch acepta identidad insegura: ${unsafe_batch_fixture}"
	fi
done

empty_pump_args_hex='5B5D'
barrier_pump_args_hex="$(php -r 'echo strtoupper(bin2hex("{\"barrier\":\"editorial_discount\"}"));')"
extra_pump_args_hex="$(php -r 'echo strtoupper(bin2hex("{\"barrier\":\"editorial_discount\",\"extra\":true}"));')"
foreign_pump_args_hex="$(php -r 'echo strtoupper(bin2hex("{\"barrier\":\"source_write\"}"));')"
empty_pump_args_receipt="$(
	(
		die() { printf 'fixture args pump vacío rechazado: %s\n' "$*" >&2; exit 1; }
		eval "${live_args_body}"
		live_pump_args_snapshot "${empty_pump_args_hex}"
	)
)"
barrier_pump_args_receipt="$(
	(
		die() { printf 'fixture args pump barrier rechazado: %s\n' "$*" >&2; exit 1; }
		eval "${live_args_body}"
		live_pump_args_snapshot "${barrier_pump_args_hex}"
	)
)"
jq -e '.schema == "akibara-search-live-pump-args-v1" and .kind == "empty" and .barrier == ""' \
	<<< "${empty_pump_args_receipt}" >/dev/null || die 'parser pump no acepta args vacíos canónicos'
jq -e '.schema == "akibara-search-live-pump-args-v1" and .kind == "barrier" and .barrier == "editorial_discount"' \
	<<< "${barrier_pump_args_receipt}" >/dev/null || die 'parser pump no acepta barrier canónico'
if (
	die() { exit 1; }
	eval "${live_args_body}"
	live_pump_args_snapshot "${extra_pump_args_hex}" >/dev/null 2>&1
); then
	die 'parser pump acepta claves extra no autorizadas'
fi
if (
	die() { exit 1; }
	eval "${live_args_body}"
	live_pump_args_snapshot "${foreign_pump_args_hex}" >/dev/null 2>&1
); then
	die 'parser pump acepta un barrier fuera de la deuda editorial auditada'
fi

null_schedule_hex="$(php -r 'echo strtoupper(bin2hex("O:28:\"ActionScheduler_NullSchedule\":0:{}"));')"
simple_schedule_hex="$(php -r '$timestamp=1786042856; $parent="\0*\0scheduled_timestamp"; $child="\0ActionScheduler_SimpleSchedule\0timestamp"; echo strtoupper(bin2hex("O:30:\"ActionScheduler_SimpleSchedule\":2:{s:22:\"".$parent."\";i:".$timestamp.";s:41:\"".$child."\";i:".$timestamp.";}"));')"
wrong_time_simple_schedule_hex="$(php -r '$timestamp=1786042857; $parent="\0*\0scheduled_timestamp"; $child="\0ActionScheduler_SimpleSchedule\0timestamp"; echo strtoupper(bin2hex("O:30:\"ActionScheduler_SimpleSchedule\":2:{s:22:\"".$parent."\";i:".$timestamp.";s:41:\"".$child."\";i:".$timestamp.";}"));')"
recurring_schedule_hex="$(php -r 'echo strtoupper(bin2hex("O:32:\"ActionScheduler_IntervalSchedule\":0:{}"));')"
if (
	die() { exit 1; }
	eval "${live_args_body}"
	live_pump_schedule_snapshot "${recurring_schedule_hex}" >/dev/null 2>&1
); then
	die 'parser schedule pump acepta una acción recurrente'
fi
live_action_fixture="$(
	(
		die() { printf 'fixture action pump rechazada: %s\n' "$*" >&2; exit 1; }
		ephemeral_wp_query() { printf 'akb_search_pump_live\tpending\t%s\takibara-search\t1786042856\t0\t10\t0\t1\t%s\n' "${empty_pump_args_hex}" "${null_schedule_hex}"; }
		eval "${live_args_body}"
		eval "${live_action_body}"
		live_pump_action_snapshot fixture_user fixture_password 383268 pending
	)
)"
jq -e '
  .schema == "akibara-search-live-pump-action-v1"
  and .action_id == 383268
  and .hook == "akb_search_pump_live"
  and .status == "pending"
  and .group == "akibara-search"
  and .scheduled_epoch == 1786042856
  and .claim_id == 0
  and .priority == 10
  and .attempts == 0
  and .extended_args_null == true
  and .wake_kind == "async-empty"
  and .args.kind == "empty"
  and .schedule.kind == "async"
  and .schedule.timestamp == 0
' <<< "${live_action_fixture}" >/dev/null || die 'snapshot action pump no conserva identidad completa'

barrier_action_fixture="$(
	(
		die() { printf 'fixture action barrier rechazada: %s\n' "$*" >&2; exit 1; }
		ephemeral_wp_query() { printf 'akb_search_pump_live\tpending\t%s\takibara-search\t1786042856\t0\t10\t0\t1\t%s\n' "${barrier_pump_args_hex}" "${simple_schedule_hex}"; }
		eval "${live_args_body}"
		eval "${live_action_body}"
		live_pump_action_snapshot fixture_user fixture_password 383269 pending
	)
)"
jq -e '.wake_kind == "editorial-barrier" and .args.kind == "barrier" and .schedule.kind == "single" and .schedule.timestamp == 1786042856' \
	<<< "${barrier_action_fixture}" >/dev/null || die 'snapshot action pump no liga barrier con schedule single'

for mismatched_action_fixture in empty_simple barrier_null barrier_wrong_timestamp; do
	if (
		die() { exit 1; }
		case "${mismatched_action_fixture}" in
			empty_simple) fixture_args="${empty_pump_args_hex}"; fixture_schedule="${simple_schedule_hex}" ;;
			barrier_null) fixture_args="${barrier_pump_args_hex}"; fixture_schedule="${null_schedule_hex}" ;;
			barrier_wrong_timestamp) fixture_args="${barrier_pump_args_hex}"; fixture_schedule="${wrong_time_simple_schedule_hex}" ;;
		esac
		ephemeral_wp_query() { printf 'akb_search_pump_live\tpending\t%s\takibara-search\t1786042856\t0\t10\t0\t1\t%s\n' "${fixture_args}" "${fixture_schedule}"; }
		eval "${live_args_body}"
		eval "${live_action_body}"
		live_pump_action_snapshot fixture_user fixture_password 383270 pending >/dev/null 2>&1
	); then
		die "snapshot action acepta pareja args/schedule inválida: ${mismatched_action_fixture}"
	fi
done

for unsafe_action_fixture in claimed in_progress wrong_priority extended_args failed_after_cli; do
	if (
		die() { exit 1; }
		fixture_status='pending'; fixture_claim=0; fixture_priority=10; fixture_extended=1; expected_status='pending'
		case "${unsafe_action_fixture}" in
			claimed) fixture_claim=91 ;;
			in_progress) fixture_status='in-progress' ;;
			wrong_priority) fixture_priority=20 ;;
			extended_args) fixture_extended=0 ;;
			failed_after_cli) fixture_status='failed'; expected_status='complete' ;;
		esac
		ephemeral_wp_query() { printf 'akb_search_pump_live\t%s\t%s\takibara-search\t1786042856\t%s\t%s\t0\t%s\t%s\n' "${fixture_status}" "${empty_pump_args_hex}" "${fixture_claim}" "${fixture_priority}" "${fixture_extended}" "${null_schedule_hex}"; }
		eval "${live_args_body}"
		eval "${live_action_body}"
		live_pump_action_snapshot fixture_user fixture_password 383271 "${expected_status}" >/dev/null 2>&1
	); then
		die "snapshot action acepta identidad insegura: ${unsafe_action_fixture}"
	fi
done

migration_action_pending_fixture="$(
	(
		die() { printf 'fixture migration hook pending rechazada: %s\n' "$*" >&2; exit 1; }
		ephemeral_wp_query() { printf 'action_scheduler/migration_hook\tpending\t%s\taction-scheduler-migration\t1786042856\t0\t10\t0\t1\t%s\n' "${empty_pump_args_hex}" "${simple_schedule_hex}"; }
		eval "${live_args_body}"
		eval "${migration_body}"
		action_scheduler_migration_action_snapshot fixture_user fixture_password 7001 pending
	)
)"
migration_action_complete_fixture="$(
	(
		die() { printf 'fixture migration hook complete rechazada: %s\n' "$*" >&2; exit 1; }
		ephemeral_wp_query() { printf 'action_scheduler/migration_hook\tcomplete\t%s\taction-scheduler-migration\t1786042856\t0\t10\t1\t1\t%s\n' "${empty_pump_args_hex}" "${simple_schedule_hex}"; }
		eval "${live_args_body}"
		eval "${migration_body}"
		action_scheduler_migration_action_snapshot fixture_user fixture_password 7001 complete
	)
)"
jq -e '
  .schema == "akibara-action-scheduler-migration-action-v1"
  and .action_id == 7001
  and .hook == "action_scheduler/migration_hook"
  and .status == "pending"
  and .group == "action-scheduler-migration"
  and .scheduled_epoch == 1786042856
  and .claim_id == 0
  and .priority == 10
  and .attempts == 0
  and .extended_args_null == true
  and .args_hex == "5B5D"
  and .schedule.kind == "single"
  and .schedule.timestamp == .scheduled_epoch
' <<< "${migration_action_pending_fixture}" >/dev/null \
	|| die 'snapshot migration hook pending no conserva identidad completa'
jq -e '.status == "complete" and .attempts == 1 and (del(.status,.attempts) == ($pending | del(.status,.attempts)))' \
	--argjson pending "${migration_action_pending_fixture}" <<< "${migration_action_complete_fixture}" >/dev/null \
	|| die 'snapshot migration hook complete no conserva identidad/un intento exactos'

for unsafe_migration_fixture in wrong_hook wrong_group claimed wrong_priority extended_args wrong_args null_schedule recurring_schedule wrong_timestamp pending_attempt complete_attempt in_progress; do
	if (
		die() { exit 1; }
		fixture_hook='action_scheduler/migration_hook'; fixture_status='pending'; fixture_args="${empty_pump_args_hex}"
		fixture_group='action-scheduler-migration'; fixture_claim=0; fixture_priority=10; fixture_attempts=0
		fixture_extended=1; fixture_schedule="${simple_schedule_hex}"; expected_status='pending'
		case "${unsafe_migration_fixture}" in
			wrong_hook) fixture_hook='action_scheduler/foreign_hook' ;;
			wrong_group) fixture_group='foreign-migration' ;;
			claimed) fixture_claim=7 ;;
			wrong_priority) fixture_priority=20 ;;
			extended_args) fixture_extended=0 ;;
			wrong_args) fixture_args="${barrier_pump_args_hex}" ;;
			null_schedule) fixture_schedule="${null_schedule_hex}" ;;
			recurring_schedule) fixture_schedule="${recurring_schedule_hex}" ;;
			wrong_timestamp) fixture_schedule="${wrong_time_simple_schedule_hex}" ;;
			pending_attempt) fixture_attempts=1 ;;
			complete_attempt) fixture_status='complete'; fixture_attempts=2; expected_status='complete' ;;
			in_progress) fixture_status='in-progress' ;;
		esac
		ephemeral_wp_query() { printf '%s\t%s\t%s\t%s\t1786042856\t%s\t%s\t%s\t%s\t%s\n' "${fixture_hook}" "${fixture_status}" "${fixture_args}" "${fixture_group}" "${fixture_claim}" "${fixture_priority}" "${fixture_attempts}" "${fixture_extended}" "${fixture_schedule}"; }
		eval "${live_args_body}"
		eval "${migration_body}"
		action_scheduler_migration_action_snapshot fixture_user fixture_password 7002 "${expected_status}" >/dev/null 2>&1
	); then
		die "snapshot migration hook acepta identidad insegura: ${unsafe_migration_fixture}"
	fi
done

# Estado completo del estabilizador: HybridStore/option ausente ejecuta un único
# ID auditado y sólo un proceso WP nuevo puede acreditar DBStore/complete.
(
	executed=0
	runner_calls=0
	die() { printf 'fixture estabilizador Hybrid rechazada: %s\n' "$*" >&2; exit 1; }
	eval "${migration_body}"
	capture_action_scheduler_runtime() {
		local store present value receipt
		if (( executed == 0 )); then
			store='ActionScheduler_HybridStore'; present=false; value=''
		else
			store='ActionScheduler_DBStore'; present=true; value='complete'
		fi
		receipt="$(jq -cnS --arg store "${store}" --argjson present "${present}" --arg value "${value}" --argjson provider "${as_provider_fixture}" '{schema:"akibara-action-scheduler-runtime-v1",version:"3.9.3",store_class:$store,migration_status:{present:$present,value:$value},runtime_error:"",provider:$provider,provider_error:""}')"
		printf 'AKB_AS_RUNTIME_OBSERVED=%s\n' "${receipt}" > "$5"
		chmod 600 "$5"
		printf '%s\n' "${receipt}"
	}
	action_scheduler_migration_active_snapshot() {
		if (( executed == 0 )); then printf '1\t0\n'; else printf '0\t0\n'; fi
	}
	action_scheduler_migration_action_snapshot() {
		local expected="$4" status attempts
		if (( executed == 0 )); then status='pending'; attempts=0; else status='complete'; attempts=1; fi
		[[ "${expected}" == "${status}" ]] || return 1
		jq -cnS --arg status "${status}" --argjson attempts "${attempts}" \
			'{schema:"akibara-action-scheduler-migration-action-v1",action_id:7001,hook:"action_scheduler/migration_hook",status:$status,group:"action-scheduler-migration",scheduled_epoch:1786042856,claim_id:0,priority:10,attempts:$attempts,extended_args_null:true,args_hex:"5B5D",schedule:{kind:"single",timestamp:1786042856,raw_sha256:"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}'
	}
	ephemeral_wp_query() {
		case "$3" in
			*'a.scheduled_date_gmt<=UTC_TIMESTAMP()'*) printf '1\t0\t0\n' ;;
			*'SELECT a.action_id FROM wp_actionscheduler_actions'*) printf '7001\n' ;;
			*"option_value='complete'"*) printf '1\t1\t0\n' ;;
			*"option_name='action_scheduler_migration_status'"*) printf '0\t0\n' ;;
			*) return 1 ;;
		esac
	}
	wait_until_epoch() { [[ "$1" == 1786042856 ]]; }
	run_action_scheduler_action_id() {
		[[ "$5" == 7001 ]] || return 1
		(( runner_calls += 1 ))
		executed=1
	}
	stabilize_action_scheduler_db_runtime fixture_user fixture_password fixture_search fixture_business "${scratch}/migration-hybrid.log"
	[[ "${executed}" == 1 && "${runner_calls}" == 1 ]] \
		|| die 'estabilizador Hybrid no ejecutó exactamente un ID'
	grep -Fq $'result=PASS\tmode=hybrid-migration\tactions_executed=1\taction_id=7001' "${scratch}/migration-hybrid.log" \
		|| die 'estabilizador Hybrid no emitió receipt PASS exacto'
	hybrid_stabilization_receipt="$(action_scheduler_stabilization_receipt_from_log "${scratch}/migration-hybrid.log")"
	jq -e '
	  .mode == "hybrid-migration"
	  and .actions_executed == 1
	  and .action_id == 7001
	  and .execution_scope == "explicit-action-id-only"
	  and .final_observation == "fresh-post-action-process"
	  and .initial_runtime.store_class == "ActionScheduler_HybridStore"
	  and .final_runtime.store_class == "ActionScheduler_DBStore"
	' <<< "${hybrid_stabilization_receipt}" >/dev/null \
		|| die 'receipt Hybrid no deriva el modo/acción observados'
	initial_line="$(grep -n '^runtime-initial=' "${scratch}/migration-hybrid.log" | cut -d: -f1)"
	before_line="$(grep -n '^migration-action-before=' "${scratch}/migration-hybrid.log" | cut -d: -f1)"
	after_line="$(grep -n '^migration-action-after=' "${scratch}/migration-hybrid.log" | cut -d: -f1)"
	final_line="$(grep -n '^runtime-final=' "${scratch}/migration-hybrid.log" | cut -d: -f1)"
	[[ "${initial_line}" =~ ^[1-9][0-9]*$ && "${before_line}" =~ ^[1-9][0-9]*$ \
		&& "${after_line}" =~ ^[1-9][0-9]*$ && "${final_line}" =~ ^[1-9][0-9]*$ \
		&& "${initial_line}" -lt "${before_line}" && "${before_line}" -lt "${after_line}" \
		&& "${after_line}" -lt "${final_line}" ]] \
		|| die 'receipts de migración no quedaron observados en orden'
)

# DBStore/complete es fast path real: no consulta ni ejecuta una migration hook.
(
	runner_calls=0
	die() { printf 'fixture fast path rechazada: %s\n' "$*" >&2; exit 1; }
	eval "${migration_body}"
	capture_action_scheduler_runtime() {
		local receipt
		receipt="$(jq -cnS --argjson provider "${as_provider_fixture}" '{schema:"akibara-action-scheduler-runtime-v1",version:"3.9.3",store_class:"ActionScheduler_DBStore",migration_status:{present:true,value:"complete"},runtime_error:"",provider:$provider,provider_error:""}')"
		printf 'AKB_AS_RUNTIME_OBSERVED=%s\n' "${receipt}" > "$5"
		chmod 600 "$5"
		printf '%s\n' "${receipt}"
	}
	run_action_scheduler_action_id() { (( runner_calls += 1 )); return 1; }
	ephemeral_wp_query() { return 1; }
	stabilize_action_scheduler_db_runtime fixture_user fixture_password fixture_search fixture_business "${scratch}/migration-fast-path.log"
	[[ "${runner_calls}" == 0 ]] || die 'fast path DBStore ejecutó una migration hook'
	grep -Fq $'result=PASS\tmode=dbstore-fast-path\tactions_executed=0' "${scratch}/migration-fast-path.log" \
		|| die 'fast path DBStore no emitió receipt PASS sin ejecución'
	fast_stabilization_receipt="$(action_scheduler_stabilization_receipt_from_log "${scratch}/migration-fast-path.log")"
	jq -e '
	  .mode == "dbstore-fast-path"
	  and .actions_executed == 0
	  and .action_id == 0
	  and .execution_scope == "none"
	  and .final_observation == "initial-fast-path"
	  and .initial_runtime == .final_runtime
	' <<< "${fast_stabilization_receipt}" >/dev/null \
		|| die 'receipt fast path afirma una migración inexistente'
)

valid_hybrid_stabilization="$(action_scheduler_stabilization_receipt_from_log "${scratch}/migration-hybrid.log")"
valid_fast_stabilization="$(action_scheduler_stabilization_receipt_from_log "${scratch}/migration-fast-path.log")"
for false_receipt_fixture in hybrid_zero_actions hybrid_zero_id hybrid_fast_observation fast_one_action fast_hybrid_initial provider_hash_drift final_hybrid extra_key; do
	case "${false_receipt_fixture}" in
		hybrid_zero_actions) false_receipt="$(jq -cS '.actions_executed=0' <<< "${valid_hybrid_stabilization}")" ;;
		hybrid_zero_id) false_receipt="$(jq -cS '.action_id=0' <<< "${valid_hybrid_stabilization}")" ;;
		hybrid_fast_observation) false_receipt="$(jq -cS '.final_observation="initial-fast-path"' <<< "${valid_hybrid_stabilization}")" ;;
		fast_one_action) false_receipt="$(jq -cS '.actions_executed=1 | .action_id=7001' <<< "${valid_fast_stabilization}")" ;;
		fast_hybrid_initial) false_receipt="$(jq -cS '.initial_runtime={version:"3.9.3",store_class:"ActionScheduler_HybridStore",migration_status:{present:false,value:""}}' <<< "${valid_fast_stabilization}")" ;;
		provider_hash_drift) false_receipt="$(jq -cS '.provider_inventory_sha256="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"' <<< "${valid_hybrid_stabilization}")" ;;
		final_hybrid) false_receipt="$(jq -cS '.final_runtime.store_class="ActionScheduler_HybridStore"' <<< "${valid_hybrid_stabilization}")" ;;
		extra_key) false_receipt="$(jq -cS '.unobserved_claim=true' <<< "${valid_hybrid_stabilization}")" ;;
	esac
	printf 'AKB_AS_STABILIZATION_RECEIPT=%s\n' "${false_receipt}" > "${scratch}/false-stabilization-${false_receipt_fixture}.log"
	chmod 600 "${scratch}/false-stabilization-${false_receipt_fixture}.log"
	if (
		die() { exit 1; }
		action_scheduler_stabilization_receipt_from_log "${scratch}/false-stabilization-${false_receipt_fixture}.log" >/dev/null 2>&1
	); then
		die "parser acepta receipt de estabilización falso: ${false_receipt_fixture}"
	fi
done

for unsafe_stabilization in wrong_version status_present multiple_active unsafe_active legacy_posts missing_action final_option_missing final_hybrid; do
	if (
		executed=0
		die() { exit 1; }
		eval "${migration_body}"
		capture_action_scheduler_runtime() {
			local version='3.9.3' store='ActionScheduler_HybridStore' present=false value='' receipt
			[[ "${unsafe_stabilization}" == wrong_version ]] && version='3.9.2'
			if [[ "${unsafe_stabilization}" == status_present ]]; then present=true; value='complete'; fi
			if (( executed == 1 )) && [[ "${unsafe_stabilization}" != final_hybrid ]]; then
				store='ActionScheduler_DBStore'; present=true; value='complete'
			fi
			receipt="$(jq -cnS --arg version "${version}" --arg store "${store}" --argjson present "${present}" --arg value "${value}" --argjson provider "${as_provider_fixture}" '{schema:"akibara-action-scheduler-runtime-v1",version:$version,store_class:$store,migration_status:{present:$present,value:$value},runtime_error:"",provider:$provider,provider_error:""}')"
			printf 'AKB_AS_RUNTIME_OBSERVED=%s\n' "${receipt}" > "$5"
			chmod 600 "$5"
			printf '%s\n' "${receipt}"
		}
		action_scheduler_migration_active_snapshot() {
			if (( executed == 1 )); then printf '0\t0\n'; return; fi
			case "${unsafe_stabilization}" in
				multiple_active) printf '2\t0\n' ;;
				unsafe_active) printf '1\t1\n' ;;
				*) printf '1\t0\n' ;;
			esac
		}
		action_scheduler_migration_action_snapshot() {
			local status attempts
			if (( executed == 0 )); then status='pending'; attempts=0; else status='complete'; attempts=1; fi
			jq -cnS --arg status "${status}" --argjson attempts "${attempts}" \
				'{schema:"akibara-action-scheduler-migration-action-v1",action_id:7001,hook:"action_scheduler/migration_hook",status:$status,group:"action-scheduler-migration",scheduled_epoch:1786042856,claim_id:0,priority:10,attempts:$attempts,extended_args_null:true,args_hex:"5B5D",schedule:{kind:"single",timestamp:1786042856}}'
		}
		ephemeral_wp_query() {
			case "$3" in
				*'a.scheduled_date_gmt<=UTC_TIMESTAMP()'*) printf '1\t0\t0\n' ;;
				*'SELECT a.action_id FROM wp_actionscheduler_actions'*) [[ "${unsafe_stabilization}" == missing_action ]] || printf '7001\n' ;;
				*"option_value='complete'"*) [[ "${unsafe_stabilization}" == final_option_missing ]] && printf '0\t0\t0\n' || printf '1\t1\t0\n' ;;
				*"option_name='action_scheduler_migration_status'"*) [[ "${unsafe_stabilization}" == legacy_posts ]] && printf '0\t1\n' || printf '0\t0\n' ;;
				*) return 1 ;;
			esac
		}
		wait_until_epoch() { :; }
		run_action_scheduler_action_id() { executed=1; }
		stabilize_action_scheduler_db_runtime fixture_user fixture_password fixture_search fixture_business "${scratch}/migration-negative-${unsafe_stabilization}.log" >/dev/null 2>&1
	); then
		die "estabilizador acepta transición insegura: ${unsafe_stabilization}"
	fi
done
grep -q '^runtime-initial=' "${scratch}/migration-negative-wrong_version.log" \
	|| die 'mismatch inicial no preservó el receipt observado antes de validar'
grep -q '^runtime-final=' "${scratch}/migration-negative-final_hybrid.log" \
	|| die 'mismatch final no preservó el receipt observado antes de validar'

live_work_fixture="$(
	(
		die() { printf 'fixture work live rechazada: %s\n' "$*" >&2; exit 1; }
		ephemeral_wp_query() { printf '1503\t1503\t1503\t0\t0\t0\t0\t0\t1786042856\n'; }
		eval "${live_work_body}"
		live_work_snapshot fixture_user fixture_password
	)
)"
[[ "${live_work_fixture}" == $'1503\t1503\t1503\t0\t0\t0\t0\t0\t1786042856' ]] \
	|| die 'snapshot work live no reproduce la deuda observada'

live_active_fixture="$(
	(
		die() { printf 'fixture pumps activos rechazada: %s\n' "$*" >&2; exit 1; }
		ephemeral_wp_query() { printf '3\t0\n'; }
		eval "${live_active_body}"
		live_pump_active_snapshot fixture_user fixture_password
	)
)"
[[ "${live_active_fixture}" == $'3\t0' ]] || die 'snapshot pumps activos no acepta identidad segura'
unsafe_active_fixture="$(
	(
		die() { printf 'fixture pumps inseguros rechazada antes del receipt: %s\n' "$*" >&2; exit 1; }
		ephemeral_wp_query() { printf '3\t1\n'; }
		eval "${live_active_body}"
		live_pump_active_snapshot fixture_user fixture_password
	)
)"
[[ "${unsafe_active_fixture}" == $'3\t1' ]] || die 'snapshot pumps activos no conserva el conteo inseguro'
grep -Fq '(( 0 == unsafe_actions ))' <<< "${live_drain_body}" \
	|| die 'drain no rechaza pumps claimed/in-progress/inseguros'
grep -Fq '(( runtime_ticks < 4 ))' <<< "${drain_body}" \
	|| die 'ticks normales no están acotados a cuatro'
grep -Fq '[[ -z "${previous_orphan_sha}" || "${orphan_sha}" != "${previous_orphan_sha}" ]]' <<< "${drain_body}" \
	|| die 'tick normal no exige progreso durable'
grep -Fq 'PREPARING|BUILDING|CATCHING_UP|SWAP_INTENT|SWAPPED|PUBLISHING|CLEANUP|DONE)' <<< "${drain_body}" \
	|| die 'tick normal no enumera fases recuperables'
grep -Fq 'BLOCKED|ABORTED) die' <<< "${drain_body}" \
	|| die 'tick normal no falla inmediatamente en estados terminales'
grep -Fq 'wp_boot "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${tick_log}"' <<< "${drain_body}" \
	|| die 'tick no usa un init WP normal del par exacto'
for required_batch_identity in \
	'[[ "${state_rows}" == 1 ]] || die' \
	"JSON_EXTRACT(option_value,'\\\$.run_id')" \
	'batch_action_snapshot "${wp_user}" "${wp_password}" "${id}" "${generation}" "${active_run_id}"' \
	'Drain v2 no usa Search 0.1.14 exacto' \
	'Drain legacy no usa Search 0.1.12 exacto'; do
	grep -Fq "${required_batch_identity}" <<< "${drain_body}" \
		|| die "batch no queda ligado a state/args/grupo: ${required_batch_identity}"
done
for required_batch_snapshot in \
	"HEX(COALESCE(a.args,''))" \
	'IF(a.extended_args IS NULL,1,0)' \
	'"${action_group}" == akibara-search && "${claim_id}" == 0' \
	'type=="array" and length==2 and .[0]==$run_id' \
	'.[1]=="baseline" or .[1]=="repair"' \
	'type=="object" and (keys==["offset"])' \
	'.offset==$offset' \
	'Action ${id} no es el batch legacy {offset}=state.offset exacto'; do
	grep -Fq "${required_batch_snapshot}" <<< "${batch_action_body}" \
		|| die "snapshot batch no conserva identidad dual v2/legacy: ${required_batch_snapshot}"
done
for required_live_drain_text in \
	'for (( round = 1; round <= 128; ++round ))' \
	'assert_action_scheduler_db_runtime "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${log_file}"' \
	"option_name IN ('akb_search_rebuild_state','akb_search_pending_rebuild')" \
	'[[ "${option_rows}" == 0 ]]' \
	'(( 0 == unsafe_actions ))' \
	'wait_until_epoch "${target}"' \
	'[[ "${action_before}" == "${action_revalidated}" ]]' \
	"a.scheduled_date_gmt<=UTC_TIMESTAMP()" \
	"available_at<=UTC_TIMESTAMP()" \
	'live_pump_action_snapshot "${wp_user}" "${wp_password}" "${action_id}" complete' \
	'"${after_attempts}" -eq $(( before_attempts + 1 ))' \
	'delta=$(( outstanding - after_outstanding ))' \
	'delta >= 1 && delta <= 25 && after_total == total' \
	'after_pending == pending - delta && after_acked == acked + delta' \
	'[[ "${option_rows_after}" == 0 ]]' \
	'[[ "${after}" == "${before}" ]]' \
	'mode=noop-residual' \
	"BINARY status NOT IN ('pending','leased','blocked','acked')"; do
	grep -Fq "${required_live_drain_text}" <<< "${live_work_body}${live_active_body}${live_drain_body}" \
		|| die "drain live fail-closed omite: ${required_live_drain_text}"
done
for required_runtime_text in \
	'ActionScheduler_Versions::instance()->latest_version()' \
	'ActionScheduler::store()' \
	'AKB_AS_RUNTIME_OBSERVED=' \
	'AKB_AS_PROVIDER_EXPECTED_JSON=' \
	'$absolute = ABSPATH . $path;' \
	'is_file( $absolute ) && ! is_link( $absolute )' \
	'hash_file( "sha256", $absolute )' \
	'"schema"           => "akibara-action-scheduler-runtime-v1"' \
	'"migration_status" => array(' \
	'"provider"      => $provider' \
	'assert_action_scheduler_runtime_provider "${receipt}"' \
	'.store_class == "ActionScheduler_DBStore"' \
	'.migration_status == {present:true,value:"complete"}' \
	'printf '\''action-scheduler-runtime=%s\n'\'' "${receipt}" >> "${log_file}"'; do
	grep -Fq "${required_runtime_text}" <<< "${as_runtime_body}" \
		|| die "gate runtime Action Scheduler omite: ${required_runtime_text}"
done
for required_migration_text in \
	'ActionScheduler_HybridStore' \
	'.migration_status == {present:false,value:""}' \
	"hook='action_scheduler/migration_hook'" \
	"g.slug='action-scheduler-migration'" \
	"BINARY HEX(COALESCE(a.args,''))='5B5D'" \
	'action_scheduler_migration_action_snapshot "${wp_user}" "${wp_password}" "${action_id}" pending' \
	'[[ "${action_before}" == "${action_revalidated}" ]]' \
	'a.scheduled_date_gmt<=UTC_TIMESTAMP()' \
	'run_action_scheduler_action_id "${wp_user}" "${wp_password}" "${search_tree}" "${business_tree}" "${action_id}" "${log_file}"' \
	'action_scheduler_migration_action_snapshot "${wp_user}" "${wp_password}" "${action_id}" complete' \
	'[[ "${before_identity}" == "${after_identity}" ]]' \
	'AKB_AS_STABILIZATION_RECEIPT=%s' \
	'action_scheduler_stabilization_receipt_json dbstore-fast-path 0' \
	'action_scheduler_stabilization_receipt_json hybrid-migration "${action_id}"' \
	'.mode == "dbstore-fast-path" or .mode == "hybrid-migration"' \
	'fresh-process-receipt'; do
	if [[ "${required_migration_text}" == fresh-process-receipt ]]; then
		grep -Fq 'final_runtime="$(capture_action_scheduler_runtime' <<< "${migration_body}" \
			|| die 'estabilizador no exige un proceso WP nuevo al final'
		continue
	fi
	grep -Fq "${required_migration_text}" <<< "${migration_body}" \
		|| die "estabilizador Action Scheduler omite: ${required_migration_text}"
done
grep -Fq 'result=PASS\tmode=dbstore-fast-path\tactions_executed=0' <<< "${migration_body}" \
	|| die 'estabilizador no conserva fast path DBStore sin ejecución'
grep -Fq 'result=PASS\tmode=hybrid-migration\tactions_executed=1' <<< "${migration_body}" \
	|| die 'estabilizador no acredita transición Hybrid por un ID'
[[ "$(grep -Fc 'drain_live_work "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}"' "${rail}" || true)" == 2 ]] \
	|| die 'drain live no está exactamente después de ambos rebuild v2'
if grep -Eq 'Reconciler::|reconcile_now|wp cron|action-scheduler run([^a-z-]|$)|action-scheduler action run.*--(group|hook)' \
	<<< "${pending_snapshot_body}${drain_body}${live_args_body}${live_action_body}${live_work_body}${live_active_body}${live_drain_body}${as_runtime_body}${migration_body}"; then
	die 'tick pending introdujo Reconciler, cron o drain de grupo'
fi
if grep -Eq '(add|update|delete)_option|mark_migration_(complete|incomplete)|(^|[[:space:]])(INSERT|UPDATE|DELETE|REPLACE)[[:space:]]' \
	<<< "${migration_body}"; then
	die 'estabilizador altera manualmente option/estado en vez de ejecutar la hook exacta'
fi
[[ "$(grep -Fc 'WP_ARGS+=(wp --path=/var/www/html action-scheduler action run "${action_id}")' <<< "${migration_body}" || true)" == 1 ]] \
	|| die 'migration hook no se ejecuta por un único ID explícito'
[[ "$(grep -Fc $'\tstabilize_action_scheduler_db_runtime "${wp_user}" "${wp_password}"' "${rail}" || true)" == 2 ]] \
	|| die 'estabilización Action Scheduler no ocurre exactamente en rollback y forward'
if grep -Eq 'transient_store|explicit_migration_action_only|action_scheduler_stabilization\.final_runtime' "${rail}"; then
	die 'receipt de ciclo conserva afirmaciones hardcodeadas ajenas al modo observado'
fi
grep -Fq 'for iteration in 1 2' "${rail}" || die 'no exige dos restores frescos'

# Cambio de par exacto: Search se desactiva antes de montar cada par. Business
# arranca con Search inactivo para materializar H32 -> H31 -> H32; cada snapshot
# full queda acreditado antes de activar Search y nuevamente después de los gates.
for required_text in \
	'business_contract="${repo_root}/ops/contracts/akibara-business-1.99.16.json"' \
	'prepare_component_tree "${business_contract}" business candidate 1.99.16' \
	'prepare_component_tree "${business_contract}" business preimage 1.99.10' \
	'wp_assert_pair_state inactive 0.1.14 1.99.10' \
	'wp_assert_pair_state inactive 0.1.12 1.99.10' \
	'wp_assert_pair_state inactive 0.1.12 1.99.16' \
	'wp_assert_pair_state inactive 0.1.14 1.99.16' \
	'rollback-business-transition-boot.log' \
	'rollback-action-scheduler-stabilization.log' \
	'rollback_as_stabilization_receipt="$(action_scheduler_stabilization_receipt_from_log' \
	'protected-rollback-after-business-switch.tsv' \
	'protected-rollback-before-activation.tsv' \
	'forward-business-transition-boot.log' \
	'forward-action-scheduler-stabilization.log' \
	'forward_as_stabilization_receipt="$(action_scheduler_stabilization_receipt_from_log' \
	'protected-forward-before-activation.tsv' \
	'action_scheduler_stabilization:{provider_inventory:$as_provider,rollback:($rollback_as_stabilization+{log_sha256:$rollback_as_stabilization_sha}),forward:($forward_as_stabilization+{log_sha256:$forward_as_stabilization_sha})}' \
	'(.action_scheduler_stabilization|keys|sort)==["forward","provider_inventory","rollback"]' \
	'.action_scheduler_stabilization.provider_inventory == $as_provider' \
	'[.action_scheduler_stabilization.rollback,.action_scheduler_stabilization.forward]' \
	'(keys|sort) == ["action_id","actions_executed","execution_scope","final_observation","final_runtime","initial_runtime","log_sha256","mode","provider_files","provider_inventory_sha256","schema"]' \
	'.mode == "dbstore-fast-path" or .mode == "hybrid-migration"' \
	'.actions_executed == 0 and .action_id == 0 and .execution_scope == "none"' \
	'.actions_executed == 1 and (.action_id | type == "number" and floor == . and . > 0)' \
	'wp_set_search_activation deactivate' \
	'wp_set_search_activation activate' \
	'run_iteration "${iteration}" "${search_candidate_tree}" "${search_rollback_tree}" "${business_candidate_tree}" "${business_rollback_tree}"'; do
	grep -Fq -- "${required_text}" "${rail}" || die "rail pareado omite: ${required_text}"
done
[[ "$(grep -Fc 'wp_assert_pair_state inactive 0.1.14 1.99.10 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_rollback}"' "${rail}" || true)" == 2 ]] \
	|| die 'rollback Business no acredita Search inactive antes y después de su boot'
[[ "$(grep -Fc 'wp_assert_pair_state inactive 0.1.12 1.99.16 "${wp_user}" "${wp_password}" "${search_rollback}" "${business_candidate}"' "${rail}" || true)" == 1 ]] \
	|| die 'midpoint forward mixto no está acotado a una verificación inactive'
if grep -Eq '^[[:space:]]*(wp_proof|drain_search_batches|wp_set_search_activation activate).*"\$\{search_candidate\}" "\$\{business_rollback\}"' "${rail}"; then
	die 'par mixto rollback puede ejecutar Search o activarlo'
fi
if grep -Eq '^[[:space:]]*(wp_proof|drain_search_batches|wp_set_search_activation activate).*"\$\{search_rollback\}" "\$\{business_candidate\}"' "${rail}"; then
	die 'par mixto forward puede ejecutar Search o activarlo'
fi
grep -Fq "'1.99.16' === AKIBARA_BUSINESS_VERSION, 'Gate v2 exige Business 1.99.16.'" "${proof}" \
	|| die 'gate v2 no fija Business 1.99.16'
grep -Fq "'1.99.10' === AKIBARA_BUSINESS_VERSION, 'Gate v1 exige Business 1.99.10.'" "${proof}" \
	|| die 'gate v1 no fija Business 1.99.10'
if grep -Fq '1.99.15' "${rail}" "${proof}"; then
	die 'rail/proof conserva Business 1.99.15 rechazado'
fi

line_number() {
	awk -v needle="$2" 'index($0, needle) { print NR; exit }' "$1"
}
rollback_deactivate_line="$(line_number "${rail}" 'wp_set_search_activation deactivate "${wp_user}" "${wp_password}" "${search_candidate}"')"
rollback_mixed_line="$(line_number "${rail}" 'wp_assert_pair_state inactive 0.1.14 1.99.10')"
rollback_transition_boot_line="$(line_number "${rail}" 'rollback-business-transition-boot.log')"
rollback_as_stabilization_line="$(line_number "${rail}" 'stabilize_action_scheduler_db_runtime "${wp_user}" "${wp_password}" "${search_candidate}" "${business_rollback}"')"
rollback_as_receipt_line="$(line_number "${rail}" 'rollback_as_stabilization_receipt="$(action_scheduler_stabilization_receipt_from_log')"
rollback_transition_state_line="$(line_number "${rail}" 'rollback-after-business-transition-state.log')"
rollback_switch_capture_line="$(line_number "${rail}" 'protected-rollback-after-business-switch.tsv')"
rollback_ready_line="$(line_number "${rail}" 'wp_assert_pair_state inactive 0.1.12 1.99.10 "${wp_user}" "${wp_password}" "${search_rollback}" "${business_rollback}" "${iteration_dir}/rollback-ready-pair.log"')"
rollback_ready_capture_line="$(line_number "${rail}" 'protected-rollback-before-activation.tsv')"
rollback_activate_line="$(line_number "${rail}" 'wp_set_search_activation activate "${wp_user}" "${wp_password}" "${search_rollback}"')"
rollback_gate_line="$(line_number "${rail}" 'wp_proof gate-v1 0')"
rollback_v1_capture_line="$(line_number "${rail}" 'protected-after-v1.tsv')"
forward_deactivate_line="$(line_number "${rail}" 'wp_set_search_activation deactivate "${wp_user}" "${wp_password}" "${search_rollback}"')"
forward_mixed_line="$(line_number "${rail}" 'wp_assert_pair_state inactive 0.1.12 1.99.16')"
forward_transition_boot_line="$(line_number "${rail}" 'forward-business-transition-boot.log')"
forward_as_stabilization_line="$(line_number "${rail}" 'stabilize_action_scheduler_db_runtime "${wp_user}" "${wp_password}" "${search_rollback}" "${business_candidate}"')"
forward_as_receipt_line="$(line_number "${rail}" 'forward_as_stabilization_receipt="$(action_scheduler_stabilization_receipt_from_log')"
forward_ready_line="$(line_number "${rail}" 'wp_assert_pair_state inactive 0.1.14 1.99.16 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/forward-ready-pair.log"')"
forward_ready_capture_line="$(line_number "${rail}" 'protected-forward-before-activation.tsv')"
forward_activate_line="$(line_number "${rail}" 'wp_set_search_activation activate "${wp_user}" "${wp_password}" "${search_candidate}"')"
forward_migration_capture_line="$(line_number "${rail}" 'protected-after-forward-migration.tsv')"
forward_gate_line="$(line_number "${rail}" 'wp_proof gate-v2 0 "${wp_user}" "${wp_password}" "${search_candidate}" "${business_candidate}" "${iteration_dir}/forward-gate.log"')"
forward_v2_capture_line="$(line_number "${rail}" 'protected-after-forward-v2.tsv')"
for line in "${rollback_deactivate_line}" "${rollback_mixed_line}" "${rollback_transition_boot_line}" "${rollback_as_stabilization_line}" "${rollback_as_receipt_line}" "${rollback_transition_state_line}" "${rollback_switch_capture_line}" "${rollback_ready_line}" "${rollback_ready_capture_line}" "${rollback_activate_line}" "${rollback_gate_line}" "${rollback_v1_capture_line}" "${forward_deactivate_line}" "${forward_mixed_line}" "${forward_transition_boot_line}" "${forward_as_stabilization_line}" "${forward_as_receipt_line}" "${forward_ready_line}" "${forward_ready_capture_line}" "${forward_activate_line}" "${forward_migration_capture_line}" "${forward_gate_line}" "${forward_v2_capture_line}"; do
	[[ "${line}" =~ ^[1-9][0-9]*$ ]] || die 'no se pudo acreditar el orden del cambio pareado'
done
(( rollback_deactivate_line < rollback_mixed_line && rollback_mixed_line < rollback_transition_boot_line \
	&& rollback_transition_boot_line < rollback_as_stabilization_line && rollback_as_stabilization_line < rollback_as_receipt_line \
	&& rollback_as_receipt_line < rollback_transition_state_line \
	&& rollback_transition_state_line < rollback_switch_capture_line \
	&& rollback_switch_capture_line < rollback_ready_line && rollback_ready_line < rollback_ready_capture_line \
	&& rollback_ready_capture_line < rollback_activate_line && rollback_activate_line < rollback_gate_line \
	&& rollback_gate_line < rollback_v1_capture_line )) \
	|| die 'orden rollback no prueba dos snapshots H31 pre-activación y uno post-gate'
(( forward_deactivate_line < forward_mixed_line && forward_mixed_line < forward_transition_boot_line \
	&& forward_transition_boot_line < forward_as_stabilization_line && forward_as_stabilization_line < forward_as_receipt_line \
	&& forward_as_receipt_line < forward_ready_line \
	&& forward_ready_line < forward_ready_capture_line \
	&& forward_ready_capture_line < forward_activate_line && forward_activate_line < forward_migration_capture_line \
	&& forward_migration_capture_line < forward_gate_line && forward_gate_line < forward_v2_capture_line )) \
	|| die 'orden forward no prueba un snapshot H32 pre-activación y dos post-activación/gate'

http_before_line="$(line_number "${rail}" 'assert_protected_equal "${root_password}" "${iteration_dir}/protected-before-http.tsv"')"
http_run_line="$(line_number "${rail}" 'run_http_smoke "${iteration}"')"
theme_before_http_line="$(line_number "${rail}" 'theme-candidate-before-http.log')"
theme_after_http_line="$(line_number "${rail}" 'theme-candidate-after-http.log')"
http_after_line="$(line_number "${rail}" 'assert_protected_equal "${root_password}" "${iteration_dir}/protected-after-http.tsv"')"
integrated_run_line="$(line_number "${rail}" 'run_integrated_mariadb_gate "${iteration}"')"
iteration_cleanup_line="$(line_number "${rail}" 'cleanup_iteration 1')"
for line in "${forward_gate_line}" "${theme_before_http_line}" "${http_before_line}" "${http_run_line}" "${theme_after_http_line}" "${http_after_line}" "${integrated_run_line}" "${iteration_cleanup_line}"; do
	[[ "${line}" =~ ^[1-9][0-9]*$ ]] || die 'no se pudo acreditar el orden HTTP/MariaDB hermético'
done
(( forward_gate_line < theme_before_http_line && theme_before_http_line < http_before_line && http_before_line < http_run_line && http_run_line < theme_after_http_line && theme_after_http_line < http_after_line \
	&& http_after_line < integrated_run_line && integrated_run_line < iteration_cleanup_line )) \
	|| die 'orden HTTP/MariaDB no queda dentro del restore final antes de cleanup'

for required_text in \
	'verify_source_unchanged_final' \
	'source-protected-final.tsv' \
	'source-search-schema-final.tsv' \
	'[[ "${raw_state_sha}" == "${expected_state_sha256}" ]]' \
	'[[ "${final_compressed_sha}" == "${backup_compressed_sha}" ]]' \
	'[[ "${final_decompressed_sha}" == "${backup_decompressed_sha}" ]]' \
	'--arg release_id' \
	'--arg search_contract_sha' \
	'--arg business_contract_sha' \
	'--arg theme_contract_sha' \
	'--arg ai_engine_sha' \
	'--arg node_image' \
	'--arg http_smoke_sha' \
	'--arg integration_sha' \
	'--slurpfile source_final' \
	'--slurpfile theme' \
	'akibara-search-recovery-cycle-v2' \
	'archive_sha256:$search[0].components.search.preimage.archive_sha256' \
	'inventory_sha256:$search[0].components.search.preimage.inventory_sha256' \
	'archive_sha256:$search[0].components.search.candidate.archive_sha256' \
	'inventory_sha256:$search[0].components.search.candidate.inventory_sha256' \
	'archive_sha256:$business[0].components.business.preimage.archive_sha256' \
	'inventory_sha256:$business[0].components.business.preimage.inventory_sha256' \
	'archive_sha256:$business[0].components.business.candidate.archive_sha256' \
	'inventory_sha256:$business[0].components.business.candidate.inventory_sha256' \
	'.release_binding.artifacts.theme = {' \
	'archive_sha256:$theme[0].component.preimage.archive_sha256' \
	'inventory_sha256:$theme[0].component.preimage.inventory_sha256' \
	'checksum_sidecar_sha256:$theme[0].component.preimage.checksum_sidecar_sha256' \
	'archive_sha256:$theme[0].component.candidate.archive_sha256' \
	'inventory_sha256:$theme[0].component.candidate.inventory_sha256' \
	'checksum_sidecar_sha256:$theme[0].component.candidate.checksum_sidecar_sha256' \
	'theme_release_test_sha256:$theme_test_sha' \
	'ai_audit_engine_sha256:$ai_engine_sha' \
	'.release_binding.images.node = $node_image' \
	'.integrated_release = {' \
	'.release_binding.images == {mariadb:$mariadb,wp_cli:$wpcli,apache:$apache,node:$node}'; do
	grep -Fq -- "${required_text}" "${rail}" || die "receipt final no liga: ${required_text}"
done

# Recovery revisable: estado exacto, fence, doble EXCEPT y único DROP oficial.
for required_text in \
	'Bytes del estado preimage divergieron.' \
	'AKB_RECOVERY_AUDITED_STATE_BYTES' \
	'legacy_or_unreadable_state' \
	'akb_recovery_require_preimage_equivalence();' \
	'EXCEPT SELECT' \
	'HEX(title)' \
	'HEX(facet)' \
	"Install::drop_shadow_tables( array( \$fence, 'assert_owned' ) )" \
	'akb_search_rebuild_state' \
	'akb_search_pending_rebuild' \
	'akb_search_rebuild_ids' \
	'akb_search_rebuild_live_writes' \
	'300 === $not_before - $requested_at' \
	'$eligible === $docs' \
	"hash_equals( substr( 'akb-search-' . \$run_id . '-' . \$semantic, 0, 191 ), \$generation )" \
	"count( \$eligible ) === \$work['acked']" \
	'akb_recovery_final_rebuild_receipt_matches( $last' \
	'! hash_equals( $rebuild_generation, $generation )' \
	'$last === $history[0]' \
	'EditorialDiscountPublication::swap_receipt_exists(' \
	"'akb_search_editorial_run_receipt_' . hash(" \
	'hash_equals( $rebuild_generation' \
	'RestCache::has_purge_receipt' \
	'EditorialDiscountPublication::status' \
	'use Akibara\Search\PriceBuckets;' \
	'AKB_RECOVERY_PRICE_BUCKETS_SHA256' \
	'PriceBuckets::derive_from_index();' \
	'$stored === array_values( $derived )' \
	"akb_recovery_require_price_pair( 'normalized', true )" \
	"akb_recovery_require_price_pair( 'normalized', false )"; do
	grep -Fq -- "${required_text}" "${proof}" || die "proof omite: ${required_text}"
done
[[ "$(grep -Fc "akb_recovery_require_price_pair( 'preimage', false )" "${proof}" || true)" == 4 ]] \
	|| die 'recovery no revalida el par preimage en entrada, bajo fence y tras installer'
[[ "$(grep -Fc "akb_recovery_require_price_pair( 'normalized', true )" "${proof}" || true)" == 1 ]] \
	|| die 'gate v2 no deriva exactamente una vez el par normalizado'
[[ "$(grep -Fc "akb_recovery_require_price_pair( 'normalized', false )" "${proof}" || true)" == 1 ]] \
	|| die 'gate v1 no conserva exactamente una comprobación del par normalizado'
[[ "$(grep -Fc 'Install::drop_shadow_tables(' "${proof}")" == 1 ]] || die 'recovery tiene más de una ruta DROP shadow'
if grep -Eq 'run_sync|Cli::|Reconciler|wp cron|DROP TABLE IF EXISTS' "${proof}"; then
	die 'proof contiene rebuild explícito, Reconciler, cron o DROP alternativo'
fi

# Cada superficie irreversible debe aparecer en el fingerprint SQL puro.
for protected_name in \
	'wp_akb_search_qlog' \
	'_akb_onbackorder_at' \
	'_akb_first_instock_at' \
	'akb_search_synonyms' \
	'akb_search_stopwords' \
	'akb_search_fuzzy' \
	'akb_search_sku_fastpath' \
	'akb_search_facets_config' \
	'akb_search_novedad' \
	'akb_search_popular_enabled' \
	'default_product_cat' \
	'akb_search_indexing' \
	'akb_search_suggest' \
	'akb_search_facets' \
	'akb_search_rest_cache_ttl' \
	'akb_search_audit_log' \
	'akibara_taxonomy_discount_enabled' \
	'akibara_taxonomy_discount_from' \
	'akibara_taxonomy_discount_to' \
	'akibara_taxonomy_discount_apply_to' \
	'akibara_taxonomy_discount_sale_policy' \
	'akibara_taxonomy_discount_stacks_coupons' \
	'akibara_business_search_feed_contract_version' \
	'rank_math_indexnow_log' \
	'woocommerce_price_num_decimals' \
	'timezone_string' \
	'gmt_offset' \
	'_akb_taxonomy_discount_pct' \
		'wp_woocommerce_attribute_taxonomies' \
		'business_search_feed_contract_option' \
		'CAST(options_table.option_id AS CHAR)' \
		'HEX(options_table.option_value)' \
	'HEX(options_table.autoload)' \
	'ABSENT'; do
	grep -Fq -- "${protected_name}" "${protected_sql}" || die "fingerprint omite: ${protected_name}"
done
for derived_price_name in akb_search_precio_buckets akb_search_precio_mode; do
	if grep -Fq -- "${derived_price_name}" "${protected_sql}"; then
		die "fingerprint no-derivable incluyó el par derivado: ${derived_price_name}"
	fi
	grep -Fq -- "${derived_price_name}" "${rail}" || die "rail no acredita por separado: ${derived_price_name}"
	grep -Fq -- "${derived_price_name}" "${proof}" || die "proof no acredita por separado: ${derived_price_name}"
done
for price_gate_text in \
	'AKB_SEARCH_DERIVED_PRICE_PAIR_V1' \
	'SHA2(CAST(options_table.option_value AS BINARY), 256)' \
	'bbf68fbe0a982bd89e4bc0d1c2e011779029484e88f6a0d926b8f8b8fd4fb7c3' \
	'929260ad9b9ea9fe0f3553dd964f4ff3deb5792efd031a2b90f573fe91f012bb' \
	'0df1d76c5d89f28087d4595fa4e64117ce526c39a97ccba23de7581cc9a719fc' \
	'2c75951b6f1df45cd8e2eb7fd5e0ffae9fc8586df4b160406fbb6ee903825a05' \
	'assert_derived_price_pair_phase_root' \
	'and $source_price_pair_sha == $canonical_price_preimage_sha' \
	'and .derived_price_pair.normalized_sha256 == $canonical_price_normalized_sha' \
	'normalized_preserved_through_rollback_forward_http_mariadb'; do
	grep -Fq -- "${price_gate_text}" "${rail}" || die "rail omite gate del par derivado: ${price_gate_text}"
done

for transition_gate_text in \
	'2663d2144a094779535360d489a1f868f67d386a6a0d86e415637cd37472315a' \
	'5197e7307fcfe75676c76f26b6ef3bc3f9e7339975594216c2879c716f080c5c' \
	'OPTION\tH616B69626172615F627573696E6573735F7365617263685F666565645F636F6E74726163745F76657273696F6E\t1320696\tPRESENT\tH32\tH6F6666' \
	'OPTION\tH616B69626172615F627573696E6573735F7365617263685F666565645F636F6E74726163745F76657273696F6E\t1320696\tPRESENT\tH31\tH6F6666' \
	'derive_rollback_protected_snapshot' \
	'candidate_count != 1 || rollback_count != 0' \
	'protected_fingerprint_transition' \
	'transition_exact_before_search_activation' \
	'option_id_preserved' \
	'row_count_exactly_one'; do
	grep -Fq -- "${transition_gate_text}" "${rail}" || die "rail omite transición protegida exacta: ${transition_gate_text}"
done

jq -e '
	  .production_invariants.search_derived_data_fingerprint_may_change_after_verified_rebuild == true
	  and .components.search.packager == "tools/akibara-releases/package-runtime.sh"
	  and .production_invariants.search_protected_persistent_surfaces_must_match_phase_contract_exactly == true
  and .production_invariants.search_derived_price_pair_phase_transition_must_match_exactly == true
  and (.production_invariants | has("search_data_fingerprint_must_remain_identical") | not)
  and .production_invariants.recovery_rollback.fresh_restore_cycles == 2
  and .production_invariants.recovery_rollback.compose_file == "compose.yaml"
  and .production_invariants.recovery_rollback.packager_library == "tools/akibara-releases/package-search-0.1.14-production.sh"
	  and .production_invariants.recovery_rollback.protected_surfaces_phase_exact == true
  and .production_invariants.recovery_rollback.derived_price_pair_transition == {
    protected_fingerprint_excluded:true,
    options:["akb_search_precio_buckets","akb_search_precio_mode"],
    normalization_source:"Akibara\\Search\\PriceBuckets::maybe_refresh_auto(false)",
	    preimage:{buckets_sha256:"bbf68fbe0a982bd89e4bc0d1c2e011779029484e88f6a0d926b8f8b8fd4fb7c3",buckets_autoload:"off",mode:"absent-auto-semantics",snapshot_sha256:"0df1d76c5d89f28087d4595fa4e64117ce526c39a97ccba23de7581cc9a719fc"},
	    normalized:{buckets_sha256:"bbf68fbe0a982bd89e4bc0d1c2e011779029484e88f6a0d926b8f8b8fd4fb7c3",buckets_autoload:"no",mode:"auto",mode_autoload:"no",snapshot_sha256:"2c75951b6f1df45cd8e2eb7fd5e0ffae9fc8586df4b160406fbb6ee903825a05"},
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
	    candidate:{business_version:"1.99.16",value:"2",autoload:"off",full_snapshot_sha256:"2663d2144a094779535360d489a1f868f67d386a6a0d86e415637cd37472315a"},
	    rollback:{business_version:"1.99.10",value:"1",autoload:"off",full_snapshot_sha256:"5197e7307fcfe75676c76f26b6ef3bc3f9e7339975594216c2879c716f080c5c"},
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
  and .production_invariants.recovery_rollback.restore.network_internal == true
  and .production_invariants.recovery_rollback.restore.published_ports == 0
  and .production_invariants.recovery_rollback.restore.information_schema_table_rows_allowed == false
  and .production_invariants.recovery_rollback.local_external_indexing.network_external_dependency_allowed == false
  and .production_invariants.recovery_rollback.local_external_indexing.rank_math_indexnow_log_must_remain_byte_identical == true
  and .production_invariants.recovery_rollback.scheduler.allowed_hooks == ["akb_search_rebuild_batch","akb_search_pump_live"]
  and .production_invariants.recovery_rollback.scheduler.wp_cron_allowed == false
	and .production_invariants.recovery_rollback.scheduler.group_drain_allowed == false
	and .production_invariants.recovery_rollback.scheduler.explicit_rebuild_cli_allowed == false
	and .production_invariants.recovery_rollback.scheduler.reconciler_fallback_allowed == false
	and .production_invariants.recovery_rollback.scheduler.active_state_without_pending_batch == "fail-closed"
	and .production_invariants.recovery_rollback.scheduler.normal_runtime_tick_allowed == true
	and .production_invariants.recovery_rollback.scheduler.max_normal_runtime_ticks == 4
	and .production_invariants.recovery_rollback.scheduler.runtime_tick_requires_durable_progress == true
	and .production_invariants.recovery_rollback.scheduler.pending_barrier_from_validated_envelope == true
	and .production_invariants.recovery_rollback.scheduler.terminal_state_retry_allowed == false
	and .production_invariants.recovery_rollback.scheduler.action_scheduler_runtime == {version:"3.9.3",store_class:"ActionScheduler_DBStore"}
	and .production_invariants.recovery_rollback.scheduler.batch_identity_binding == ["state.run_id","action.args[0]","action.args[1]=baseline|repair","group=akibara-search","status=pending"]
	and .production_invariants.recovery_rollback.scheduler.rollback_legacy_batch_identity_binding == ["search_version=0.1.12-only","state.run_id-present","action.args={offset:int>=0}","action.args.offset=state.offset","group=akibara-search","status=pending","claim_id=0","extended_args=null"]
	and .production_invariants.recovery_rollback.scheduler.live_pump_identity_binding == ["action.hook=akb_search_pump_live","action.args=[]|{barrier:editorial_discount}","group=akibara-search","status=pending","claim_id=0","priority=10","extended_args=null","schedule=async|single-nonrecurring","scheduled_date_gmt<=validated_wait","work.available_at<=validated_wait","post.status=complete","post.claim_id=0","post.attempts=pre+1","post.state/pending=absent","post.work.delta=1..25-exact"]
	and .production_invariants.recovery_rollback.scheduler.live_args_schedule_pairs == ["[]=>async-null","{barrier:editorial_discount}=>single-simple"]
	and .production_invariants.recovery_rollback.scheduler.live_requires_state_pending_absent == true
	and .production_invariants.recovery_rollback.scheduler.live_progress_required == true
	and .production_invariants.recovery_rollback.scheduler.live_progress_max_rows_per_action == 25
	and .production_invariants.recovery_rollback.scheduler.live_residual_pumps == "validated-explicit-noop-drain"
	and .production_invariants.recovery_rollback.scheduler.max_live_pump_actions == 128
	and .paired_release.business_contract == "ops/contracts/akibara-business-1.99.16.json"
	and .paired_release.candidate == {business_version:"1.99.16",search_version:"0.1.14"}
	and .paired_release.rollback == {business_version:"1.99.10",search_version:"0.1.12"}
	and .paired_release.search_must_be_inactive_during_component_switch == true
	and .paired_release.production_transition.mixed_midpoints == {
	  rollback_after_business_deploy:{business_version:"1.99.10",search_version:"0.1.14",search_active:false},
	  forward_after_business_deploy:{business_version:"1.99.16",search_version:"0.1.12",search_active:false}
	}
	and .integrated_theme_binding == {
	  required:true,
	  theme_contract_path:"ops/contracts/akibara-gp-2.1.70.json",
	  theme_contract_sha256:"181229311dbd5863b4fdf4345461d2e3e0d8a04723e37cf108f0b2a29bf0ba59",
	  theme_release_id:"akibara-gp-2.1.70",
	  theme_version:"2.1.70",
	  theme_archive_sha256:"69c10a7c7842e0f76f91350f76733da957a53d5dc8560342cc37e5a3ccf7e69c",
	  theme_inventory_sha256:"5efeda6f76c556be5fb209fb922ab74565cb34389f7ae5649348c300bdb3142b",
	  theme_checksum_sidecar_sha256:"a69a2a1f805746671964f2e507581785f57f26f67c952163ff6d1d114ef78041",
	  theme_runtime_files:103,
	  theme_rollback_version:"2.1.69",
	  theme_rollback_archive_sha256:"dfbfd1717b5f9e4e6c126ff59cabe10c4d410563819515f9334631ecb63b641b",
	  theme_rollback_inventory_sha256:"f58dd384851468a6993783ca8da7b59ba7349906f556957d14ea40d4ca448eb2",
	  theme_rollback_checksum_sidecar_sha256:"17d8ea032896f31bf21603f035473195fb55708914c841524a59c3dba1b6858f",
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
	  engine_sha256:"72bdd1d5484146f7e817cf822cc626a80be3819224c76080f6b04b45a8983cf5",
	  test_path:"tools/test-ai-discoverability-audit.mjs",
	  test_sha256:"34e2583940bda366cfcc2287e57ac009da72cae0564b7088607936a5d513babc",
	  wrapper_path:"tools/akibara-ai-discoverability-audit.sh",
	  wrapper_sha256:"bc0d5a283576a7b35021ab0340137b52b8e63c8add021be9b2bf34851798affa",
	  policy_path:"tools/lib/ai-discoverability-policy.mjs",
	  policy_sha256:"f550ab4be251c35221909eb0c5e0524b4e426d61deabf8a34fac164fff798338",
	  runbook_path:"ops/akibara-ai-discoverability-audit-runbook.md",
	  runbook_sha256:"b6caa9490cf31f1ae7cfd7bcc912ba5c3ee3c49583d401438afde624081a64a0",
	  node_image:"node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b",
	  base_url:"https://akibara.cl/",
	  fetch_scope:"docker-internal-only",
	  redirect_policy:"manual_fail_closed",
	  network_internal:true,
	  published_ports:0,
	  external_network_allowed:false,
	  required_per_fresh_restore:true,
	  required_passes:2
	}
	and .production_invariants.recovery_rollback.images == {
	  mariadb:"mariadb@sha256:a794d9eb009e20de605858a11f32f63b4075cbd197c650436f0e3b457e4caed7",
	  wp_cli:"wordpress@sha256:909702ad806b574fafa50894808205724a2a78dc5a3e193c09c85f86322d89eb",
	  apache:"wordpress@sha256:b65f6fb4d8b669974d4e8d6f204bc5a11d67f4877473ba5ee97af0efd39e3d19",
	  node:"node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b"
	}
	and .quality_gates.theme_contract == "bash tools/akibara-releases/test-theme-2.1.70-production-release.sh"
	and .quality_gates.theme_package == "bash tools/akibara-releases/package-runtime.sh theme --verify-only"
	and .quality_gates.theme_node == "node --test public_html/wp-content/themes/akibara-gp/tests/*.test.js"
	and .quality_gates.theme_phpunit == "tools/akibara-business/vendor/bin/phpunit -c tools/akibara-gp/phpunit.xml.dist"
	and .quality_gates.ai_audit_unit == "node tools/test-ai-discoverability-audit.mjs"
	and .quality_gates.ai_audit_integrated == "node tools/akibara-ai-discoverability-audit.mjs --base-url https://akibara.cl/ --fetch-base-url docker-internal"
	and .quality_gates.required_passes == 2
	and .production_invariants.recovery_rollback.sequence == ["0.1.14+1.99.16-recover-and-converge","deactivate-search-before-rollback","0.1.14+1.99.10-rollback-mixed-midpoint-search-inactive","0.1.12+1.99.10-rollback-and-converge","deactivate-search-before-forward","0.1.12+1.99.16-forward-mixed-midpoint-search-inactive","0.1.14+1.99.16-forward-and-converge"]
	and .production_invariants.recovery_rollback.paired_component_switch.rollback_mixed_midpoint == {business_version:"1.99.10",search_version:"0.1.14",search_active:false}
	and .production_invariants.recovery_rollback.paired_component_switch.rollback_ready_pair == {business_version:"1.99.10",search_version:"0.1.12",search_active:false}
	and .production_invariants.recovery_rollback.paired_component_switch.forward_mixed_midpoint == {business_version:"1.99.16",search_version:"0.1.12",search_active:false}
	and .production_invariants.recovery_rollback.paired_component_switch.forward_ready_pair == {business_version:"1.99.16",search_version:"0.1.14",search_active:false}
	and .production_invariants.recovery_rollback.rollback_gate.business_version == "1.99.10"
	and .production_invariants.recovery_rollback.forward_gate.business_version == "1.99.16"
	and .production_invariants.recovery_rollback.forward_gate.work_acked_equals_eligible == true
	and .production_invariants.recovery_rollback.forward_gate.final_rebuild_receipt == {role:"historical",exact_shape:true,target_binding:"akb-search-<run_id>-<semantic_fingerprint>",history_exact_copy_required:true,immutable_editorial_run_receipt_required:true,current_live_generation_must_supersede:true}
' "${contract}" >/dev/null || die 'contrato recovery incompleto'

jq -e '
  .release_id == "akibara-business-1.99.16"
  and (.seal_state == "draft" or .seal_state == "sealed")
  and .components.business.preimage.version == "1.99.10"
  and .components.business.candidate.version == "1.99.16"
  and .paired_release.candidate == {business_version:"1.99.16",search_version:"0.1.14"}
  and .paired_release.rollback == {business_version:"1.99.10",search_version:"0.1.12"}
  and .paired_release.search_must_be_inactive_during_component_switch == true
  and .paired_release.production_transition.mixed_midpoints == {
    rollback_after_business_deploy:{business_version:"1.99.10",search_version:"0.1.14",search_active:false},
    forward_after_business_deploy:{business_version:"1.99.16",search_version:"0.1.12",search_active:false}
  }
' "${business_contract}" >/dev/null || die 'contrato Business pareado incompleto'

printf 'PASS: rail integrado Search+Business+Theme+IA estático/dry-run; Docker no invocado, 37 bindings/14 artefactos y receipts exactos\n'
