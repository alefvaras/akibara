#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "${script_dir}/.." && pwd -P)"
pipeline="${script_dir}/akibara-search-0.1.14-production-promotion.sh"
search_contract="${script_dir}/contracts/akibara-search-0.1.14.json"
business_contract="${script_dir}/contracts/akibara-business-1.99.16.json"
theme_contract="${script_dir}/contracts/akibara-gp-2.1.70.json"
theme_promotion_rail="${script_dir}/akibara-theme-2.1.70-production-promotion.sh"
theme_promotion_contract="${script_dir}/test-akibara-theme-2.1.70-production-promotion.sh"
runbook="${script_dir}/akibara-search-0.1.14-production-runbook.md"

die() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
sha256_file() { shasum -a 256 "$1" | awk '{print $1}'; }
write_sidecar() {
	( cd -- "$(dirname -- "$1")" && shasum -a 256 "$(basename -- "$1")" > "$(basename -- "$1").sha256" )
	chmod 0600 "$1" "${1}.sha256"
}
descriptor() { jq -n --arg path "$(basename -- "$1")" --arg sha "$(sha256_file "$1")" '{path:$path,sha256:$sha}'; }
fake_image() { printf '\211PNG\r\n\032\n0123456789012345678901234567890123456789' > "$1"; }
iso_add() {
	python3 - "$1" "$2" <<'PY'
import datetime as dt, sys
value=dt.datetime.fromisoformat(sys.argv[1].replace("Z","+00:00"))+dt.timedelta(seconds=int(sys.argv[2]))
print(value.strftime("%Y-%m-%dT%H:%M:%SZ"))
PY
}

for tool in bash cp grep gzip jq ln python3 shasum unzip zip; do command -v "${tool}" >/dev/null || die "falta ${tool}"; done
for file in "${pipeline}" "${search_contract}" "${business_contract}" "${theme_contract}" \
	"${theme_promotion_rail}" "${theme_promotion_contract}" "${runbook}"; do
	[[ -f "${file}" && ! -L "${file}" && -s "${file}" ]] || die "falta ${file}"
done
bash -n "${pipeline}"
bash -n "${theme_promotion_rail}"
bash -n "${theme_promotion_contract}"

jq -e '
  .promotion_sequence==["docker-local-paired","production-ui-paired"]
  and .exclusive_targets==["public_html/wp-content/plugins/akibara-business","public_html/wp-content/plugins/akibara-search"]
  and .paired_release.required==true and .paired_release.compatibility_policy=="exact-pair-only"
  and .paired_release.search_must_be_inactive_during_component_switch==true
  and .production_invariants.protected_components==[
    {kind:"plugin",slug:"akibara-cart-checkout-blocks"},
    {kind:"plugin",slug:"bluex-for-woocommerce"},{kind:"theme",slug:"akibara-gp"}]
  and .production_invariants.deployment.receipt_policy.accept_only_with_exact_postconditions==[
    "wordpress-ui-search-inactive-midpoint","wordpress-active-pair-versions",
    "business-runtime-inventory","search-runtime-inventory","complete-witness-bundle"]
  and .production_invariants.evidence.production_baseline_exact_pair_inventories_required==true
  and .production_invariants.evidence.production_candidate_exact_pair_inventories_required==true
	and .integrated_theme_binding.required==true
	and .integrated_theme_binding.theme_version=="2.1.70"
	and .integrated_theme_binding.theme_rollback_version=="2.1.69"
	and .integrated_ai_audit.required==true
	and .integrated_ai_audit.schema_version==3
	and .integrated_ai_audit.fetch_scope=="docker-internal-only"
	and .production_invariants.recovery_rollback.immutable_release_binding.contracts_evidence_and_fourteen_artifacts_included==true
' "${search_contract}" >/dev/null || die 'Search no declara el par exacto'
jq -e '
  .promotion_sequence==["docker-local-paired","production-ui-paired"]
  and .paired_release.required==true and .paired_release.compatibility_policy=="exact-pair-only"
  and .components.business.preimage.version=="1.99.10"
  and .components.business.candidate.version=="1.99.16"
  and ([.production_invariants.protected_components[].slug]|index("akibara-business")|not)
' "${business_contract}" >/dev/null || die 'Business no declara el par exacto'
for paired_contract in "${search_contract}" "${business_contract}"; do
	jq -e '
      .seal_requirements=={candidate_artifact_hashes:true,candidate_inventory_hashes:true,
        candidate_runtime_file_counts:true,runtime_delta_arrays:true,source_inputs:true,
        paired_release_definition:true,recovery_code_hashes:true,pinned_recovery_images:true}
      and .promotion_requirements=={contracts_sealed:true,paired_contract_sha256_match:true,
        local_gate_receipt:{schema_version:3,kind:"akibara-business-search-paired-local-gate-receipt",
          max_age_seconds:1800,sidecar_required:true,file_mode:"0600"},
        recovery_receipt:{schema:"akibara-search-recovery-cycle-v2",result:"PASS",
          fresh_restore_cycles:2,http_smoke_cycles:2,integrated_mariadb_cycles:2,
          sidecar_required:true,file_mode:"0600"},
        production_preflight_verified_receipt_required:true,
        paired_handoff:{schema_version:3,kind:"akibara-business-search-paired-mcp-handoff",
          exact_pair_only:true,search_inactive_at_mixed_midpoints:true},
        cache_order:["litespeed-ui","hostinger-mcp","cloudflare-mcp"],
        candidate_or_rollback_verified_receipt_required:true,
        graphify_after_verified_promotion_only:true}
    ' "${paired_contract}" >/dev/null || die 'seal_requirements y promotion_requirements no están separadas/exactas'
done
jq -e '
  .quality_gates.production_handoff.required_passes==2
  and .quality_gates.production_handoff.rail.path=="ops/akibara-theme-2.1.70-production-promotion.sh"
  and .quality_gates.production_handoff.test.path=="ops/test-akibara-theme-2.1.70-production-promotion.sh"
  and .quality_gates.production_handoff.library.path=="ops/akibara-search-0.1.14-production-promotion.sh"
  and .promotion_requirements.graphify_before_verify_close_allowed==false
  and .promotion_requirements.maintenance_off_before_cloudflare==true
' "${theme_contract}" >/dev/null || die 'Theme no declara su handoff fase 2 exacto'

# El gate jamás puede tocar el stack/DB principal ni reutilizar qa-search.
if grep -Fq 'docker compose' "${pipeline}" || grep -Fq 'qa-search.sh' "${pipeline}" || grep -Fq 'qa-search-mariadb.sh' "${pipeline}"; then
	die 'local-gate conserva dependencia del WordPress/DB principal'
fi
grep -Fq -- '--network none --read-only' "${pipeline}" || die 'PHPUnit no queda hermético'
grep -Fq 'for pass in 1 2' "${pipeline}" || die 'faltan dos pasadas'
[[ "$(grep -Fc 'bash "${recovery_rail}" run' "${pipeline}")" == 1 ]] || die 'recovery debe invocarse exactamente una vez'
grep -Fq 'fresh_restore_cycles:2,http_smoke_cycles:2' "${pipeline}" || die 'receipt local no exige dos restores/HTTP'
grep -Fq '["litespeed-ui","hostinger-mcp","cloudflare-mcp"]' "${pipeline}" || die 'orden de caché no es exacto'
grep -Fq 'business_deploy_response' "${pipeline}" && grep -Fq 'search_deploy_response' "${pipeline}" \
	|| die 'faltan envelopes separados'
grep -Fq 'verify_recovery_receipt "${recovery_path}"' "${pipeline}" \
	|| die 'verify_local_receipt no revalida recovery material'
grep -Fq '.schema == "akibara-search-recovery-cycle-v2"' "${pipeline}" \
	|| die 'consumer no exige recovery receipt v2'
for required_consumer_field in \
	'derived_price_pair_preimage_sha256' \
	'initial_snapshot_sha256' \
	'live_drain_initial_sha256' \
	'live_drain_forward_sha256' \
	'compose_sha256' \
	'packager_library_sha256' \
	'runtime_packager_sha256' \
	'theme_contract_sha256' \
	'theme_release_test_sha256' \
	'ai_audit_engine_sha256' \
	'ai_discoverability' \
	'theme_quality' \
	'release-binding-initial.tsv'; do
	grep -Fq "${required_consumer_field}" "${pipeline}" \
		|| die "consumer recovery omite ${required_consumer_field}"
done
grep -Fq 'derived_price_pair_transition == {' "${pipeline}" \
	|| die 'consumer contractual omite transición exacta del par de precio'
grep -Fq 'immutable_release_binding == {' "${pipeline}" \
	|| die 'consumer contractual omite binding inmutable exacto'
grep -Fq 'files != 37' "${pipeline}" \
	|| die 'consumer recovery no exige 37 entradas FILE'
grep -Fq '"ops/akibara-theme-2.1.70-production-promotion.sh"' "${pipeline}" \
	|| die 'suite_digest no liga el rail Theme fase 2'
grep -Fq '"ops/test-akibara-theme-2.1.70-production-promotion.sh"' "${pipeline}" \
	|| die 'suite_digest no liga el test Theme fase 2'
[[ "$(grep -Fc 'bash "${theme_promotion_contract}"' "${pipeline}")" == 1 ]] \
	|| die 'gate pareado no ejecuta una vez por pasada el test Theme fase 2'
grep -Fq 'theme_promotion_contract:{passes:2}' "${pipeline}" \
	|| die 'receipt local no registra dos pasadas Theme fase 2'

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-paired-promotion-test.XXXXXX")"
scratch="$(cd -- "${scratch}" && pwd -P)"
cleanup() { case "${scratch:-}" in */akibara-paired-promotion-test.*) rm -rf -- "${scratch}";; esac; }
trap cleanup EXIT; trap 'exit 129' HUP; trap 'exit 130' INT; trap 'exit 143' TERM

# Plan draft: no Docker/red y gate cerrado.
fixture_repo="${scratch}/draft"
mkdir -p -- "${fixture_repo}/ops/contracts" "${fixture_repo}/dist"
cp -- "${pipeline}" "${fixture_repo}/ops/akibara-search-0.1.14-production-promotion.sh"
cp -- "${theme_promotion_rail}" "${fixture_repo}/ops/akibara-theme-2.1.70-production-promotion.sh"
cp -- "${theme_promotion_contract}" "${fixture_repo}/ops/test-akibara-theme-2.1.70-production-promotion.sh"
cp -- "${search_contract}" "${fixture_repo}/ops/contracts/akibara-search-0.1.14.json"
cp -- "${business_contract}" "${fixture_repo}/ops/contracts/akibara-business-1.99.16.json"
cp -- "${theme_contract}" "${fixture_repo}/ops/contracts/akibara-gp-2.1.70.json"
for integrated_relative in \
	'tools/akibara-releases/package-runtime.sh' \
	'tools/akibara-releases/test-theme-2.1.70-production-release.sh' \
	'tools/akibara-gp/phpunit.xml.dist' \
	'tools/akibara-ai-discoverability-audit.mjs' \
	'tools/test-ai-discoverability-audit.mjs' \
	'tools/akibara-ai-discoverability-audit.sh' \
	'tools/lib/ai-discoverability-policy.mjs' \
	'ops/akibara-ai-discoverability-audit-runbook.md'; do
	mkdir -p -- "${fixture_repo}/$(dirname -- "${integrated_relative}")"
	cp -- "${repo_root}/${integrated_relative}" "${fixture_repo}/${integrated_relative}"
done
jq '
  .seal_state="draft"
  | .sealed_at_utc=null
  | .components.search.candidate.archive_sha256=null
  | .components.search.candidate.inventory_sha256=null
  | .components.search.candidate.runtime_files=null
  | .components.search.modified_runtime_files=null
  | .components.search.added_runtime_files=null
  | .components.search.removed_runtime_files=null
  | .components.search.source_inputs=null
  | .paired_release.business_contract_sha256=null
  | .production_invariants.recovery_rollback.http_smoke.script_sha256=null
  | .production_invariants.recovery_rollback.integrated_mariadb.script_sha256=null
' "${fixture_repo}/ops/contracts/akibara-search-0.1.14.json" > "${scratch}/search-draft.json"
mv -- "${scratch}/search-draft.json" "${fixture_repo}/ops/contracts/akibara-search-0.1.14.json"
jq '
  .seal_state="draft"
  | .sealed_at_utc=null
  | .components.business.candidate.archive_sha256=null
  | .components.business.candidate.inventory_sha256=null
  | .components.business.candidate.runtime_files=null
  | .components.business.modified_runtime_files=null
  | .components.business.added_runtime_files=null
  | .components.business.removed_runtime_files=null
  | .components.business.source_inputs=null
' "${fixture_repo}/ops/contracts/akibara-business-1.99.16.json" > "${scratch}/business-draft.json"
mv -- "${scratch}/business-draft.json" "${fixture_repo}/ops/contracts/akibara-business-1.99.16.json"
config="${scratch}/promotion.conf"
printf '%s\n' 'HOSTINGER_DOMAIN=akibara.cl' 'HOSTINGER_USERNAME=u_fixture' \
	'CLOUDFLARE_ACCOUNT_ID=11111111111111111111111111111111' > "${config}"
chmod 0600 "${config}"
fake_bin="${scratch}/fake-bin"; sentinel="${scratch}/transport-called"; mkdir -p -- "${fake_bin}"
for tool in docker ssh scp curl wget; do
	printf '#!/usr/bin/env bash\nprintf "%%s\\n" "%s" >> "%s"\nexit 97\n' "${tool}" "${sentinel}" > "${fake_bin}/${tool}"
	chmod 0700 "${fake_bin}/${tool}"
done
PATH="${fake_bin}:${PATH}" bash "${fixture_repo}/ops/akibara-search-0.1.14-production-promotion.sh" plan --config "${config}" > "${scratch}/plan.txt"
[[ ! -e "${sentinel}" ]] || die 'plan tocó Docker/red'
grep -Fq 'PRODUCTION_GATE=CLOSED' "${scratch}/plan.txt" || die 'plan draft no cerró producción'
grep -Fq 'Targets exclusivos: akibara-business y akibara-search' "${scratch}/plan.txt" || die 'plan no expone par'
jq 'del(.promotion_requirements)' "${fixture_repo}/ops/contracts/akibara-business-1.99.16.json" > "${scratch}/business-without-promotion.json"
mv -- "${scratch}/business-without-promotion.json" "${fixture_repo}/ops/contracts/akibara-business-1.99.16.json"
if PATH="${fake_bin}:${PATH}" bash "${fixture_repo}/ops/akibara-search-0.1.14-production-promotion.sh" plan --config "${config}" >/dev/null 2>&1; then
	die 'rail aceptó contrato sin promotion_requirements separadas'
fi
cp -- "${business_contract}" "${fixture_repo}/ops/contracts/akibara-business-1.99.16.json"
[[ ! -e "${sentinel}" ]] || die 'rechazo contractual alcanzó Docker/red'
bad_config="${scratch}/bad.conf"; printf '%s\n' 'HOSTINGER_DOMAIN=akibara.cl' 'HOSTINGER_USERNAME=$(unsafe)' 'CLOUDFLARE_ACCOUNT_ID=11111111111111111111111111111111' > "${bad_config}"; chmod 0600 "${bad_config}"
if bash "${pipeline}" plan --config "${bad_config}" >/dev/null 2>&1; then die 'config aceptó sintaxis ejecutable'; fi

# Carga como librería. Los artefactos/recibos siguientes son fixtures locales;
# nunca sustituyen contratos ni acceden a producción.
AKB_SEARCH_0114_PROMOTION_LIBRARY_ONLY=1; export AKB_SEARCH_0114_PROMOTION_LIBRARY_ONLY
# shellcheck source=ops/akibara-search-0.1.14-production-promotion.sh
source "${pipeline}"
unset AKB_SEARCH_0114_PROMOTION_LIBRARY_ONLY

# Ejecuta el mismo digest que cerrara el recibo real. Esto detecta entradas
# inexistentes o inseguras antes de invertir tiempo en el gate completo.
suite_digest_value="$(suite_digest)"
[[ "${suite_digest_value}" =~ ^[0-9a-f]{64}$ ]] \
	|| die 'suite_digest no produjo un SHA-256 valido'

release_id='akibara-search-0.1.14'
release_digest='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
contract_sha="$(sha256_file "${search_contract}")"
business_contract_sha="$(sha256_file "${business_contract}")"
theme_contract_sha="$(sha256_file "${theme_contract}")"
production_domain='akibara.cl'; cloudflare_zone_id='b298201b3a38d017bc8413d9fd7414cd'; cloudflare_zone_name='akibara.cl'
cloudflare_account_id='11111111111111111111111111111111'; hostinger_username='u_fixture'
preflight_max_age=900; witness_max_age=1800; local_state_root="${scratch}/state"
recovery_state_root="${scratch}/recovery"
isolated_php_image='wordpress@sha256:b65f6fb4d8b669974d4e8d6f204bc5a11d67f4877473ba5ee97af0efd39e3d19'
recovery_expected_run_id='8dde2d65-6029-4062-89cf-7fdd5af90c77'
recovery_expected_state_sha='b243ddb58271e4dac334ff5df18ca863b352b6119721f3d562c547897838fb0d'
mkdir -p -- "${local_state_root}"

make_component() {
	local purpose="$1" slug="$2" content="$3" inventory="$4" archive="$5" parent
	parent="${scratch}/source-${purpose}-${slug}"
	mkdir -p -- "${parent}/${slug}"
	printf '%s\n' "${content}" > "${parent}/${slug}/main.php"
	printf '%s  %s\n' "$(sha256_file "${parent}/${slug}/main.php")" "${slug}/main.php" > "${inventory}"
	( cd -- "${parent}" && zip -q -X "${archive}" "${slug}/main.php" )
}
candidate_inventory="${scratch}/search-candidate.sha256"; candidate_archive="${scratch}/search-candidate.zip"
preimage_inventory="${scratch}/search-rollback.sha256"; preimage_archive="${scratch}/search-rollback.zip"
business_candidate_inventory="${scratch}/business-candidate.sha256"; business_candidate_archive="${scratch}/business-candidate.zip"
business_preimage_inventory="${scratch}/business-rollback.sha256"; business_preimage_archive="${scratch}/business-rollback.zip"
theme_candidate_inventory="${scratch}/theme-candidate.sha256"; theme_candidate_archive="${scratch}/theme-candidate.zip"
theme_preimage_inventory="${scratch}/theme-rollback.sha256"; theme_preimage_archive="${scratch}/theme-rollback.zip"
make_component sc akibara-search '<?php // Search candidate' "${candidate_inventory}" "${candidate_archive}"
make_component sr akibara-search '<?php // Search rollback' "${preimage_inventory}" "${preimage_archive}"
make_component bc akibara-business '<?php // Business candidate' "${business_candidate_inventory}" "${business_candidate_archive}"
make_component br akibara-business '<?php // Business rollback' "${business_preimage_inventory}" "${business_preimage_archive}"
make_component tc akibara-gp '<?php // Theme candidate' "${theme_candidate_inventory}" "${theme_candidate_archive}"
make_component tr akibara-gp '<?php // Theme rollback' "${theme_preimage_inventory}" "${theme_preimage_archive}"
candidate_inventory_sha="$(sha256_file "${candidate_inventory}")"; preimage_inventory_sha="$(sha256_file "${preimage_inventory}")"
business_candidate_inventory_sha="$(sha256_file "${business_candidate_inventory}")"; business_preimage_inventory_sha="$(sha256_file "${business_preimage_inventory}")"
theme_candidate_inventory_sha="$(sha256_file "${theme_candidate_inventory}")"; theme_preimage_inventory_sha="$(sha256_file "${theme_preimage_inventory}")"
candidate_archive_sha="$(sha256_file "${candidate_archive}")"; preimage_archive_sha="$(sha256_file "${preimage_archive}")"
business_candidate_archive_sha="$(sha256_file "${business_candidate_archive}")"; business_preimage_archive_sha="$(sha256_file "${business_preimage_archive}")"
theme_candidate_archive_sha="$(sha256_file "${theme_candidate_archive}")"; theme_preimage_archive_sha="$(sha256_file "${theme_preimage_archive}")"
theme_candidate_sidecar="${theme_candidate_archive}.sha256"; theme_preimage_sidecar="${theme_preimage_archive}.sha256"
printf '%s  %s\n' "${theme_candidate_archive_sha}" "$(basename -- "${theme_candidate_archive}")" > "${theme_candidate_sidecar}"
printf '%s  %s\n' "${theme_preimage_archive_sha}" "$(basename -- "${theme_preimage_archive}")" > "${theme_preimage_sidecar}"
theme_candidate_sidecar_sha="$(sha256_file "${theme_candidate_sidecar}")"; theme_preimage_sidecar_sha="$(sha256_file "${theme_preimage_sidecar}")"

bad_recovery_dir="${recovery_state_root}/run-fixture"; mkdir -p -- "${bad_recovery_dir}"
printf '%s\n' '{"schema":"akibara-search-recovery-cycle-v1","result":"PASS"}' > "${bad_recovery_dir}/final-receipt.json"
write_sidecar "${bad_recovery_dir}/final-receipt.json"
if (verify_recovery_receipt "${bad_recovery_dir}/final-receipt.json") >/dev/null 2>&1; then
	die 'consumer aceptó recovery receipt v1/falso'
fi

# Receipt recovery v2 positivo completo: prueba que el consumidor no sólo
# rechaza basura, sino que acepta exactamente la forma material emitida por el
# rail y vuelve a rechazar cada ligadura nueva si se adultera.
recovery_fixture_dir="${recovery_state_root}/run-positive-fixture"
mkdir -p -- "${recovery_fixture_dir}"
recovery_binding="${recovery_fixture_dir}/release-binding-initial.tsv"
binding_fixture_entry() { printf 'FILE\t%s\t%s\n' "$1" "$(sha256_file "$2")"; }
{
	printf 'AKB_SEARCH_RELEASE_BINDING_V1\n'
	binding_fixture_entry rail "${recovery_rail}"
	binding_fixture_entry search_contract "${contract_file}"
	binding_fixture_entry business_contract "${business_contract_file}"
	binding_fixture_entry theme_contract "${theme_contract_file}"
	binding_fixture_entry compose "${repo_root}/compose.yaml"
	binding_fixture_entry packager_library "${repo_root}/tools/akibara-releases/package-search-0.1.14-production.sh"
	binding_fixture_entry runtime_packager "${repo_root}/tools/akibara-releases/package-runtime.sh"
	binding_fixture_entry recovery_proof "${repo_root}/tools/akibara-releases/search-0.1.14-recovery-proof.php"
	binding_fixture_entry http_smoke "${repo_root}/tools/akibara-releases/search-0.1.14-http-smoke.php"
	binding_fixture_entry protected_fingerprint "${repo_root}/tools/akibara-releases/search-0.1.14-protected-fingerprint.sql"
	binding_fixture_entry check_table_validator "${repo_root}/tools/akibara-releases/search-0.1.14-check-table.awk"
	binding_fixture_entry static_test "${recovery_static_test}"
	binding_fixture_entry theme_release_test "${theme_release_test}"
	binding_fixture_entry theme_phpunit_config "${theme_phpunit_config}"
	binding_fixture_entry ai_audit_engine "${ai_audit_engine}"
	binding_fixture_entry ai_audit_test "${ai_audit_test}"
	binding_fixture_entry ai_audit_wrapper "${ai_audit_wrapper}"
	binding_fixture_entry ai_audit_policy "${ai_audit_policy}"
	binding_fixture_entry ai_audit_runbook "${ai_audit_runbook}"
	binding_fixture_entry runbook "${repo_root}/ops/akibara-search-0.1.14-recovery-runbook.md"
	binding_fixture_entry local_index_guard "${repo_root}/public_html/wp-content/mu-plugins/akibara-local-external-indexing-guard.php"
	binding_fixture_entry local_index_guard_test "${repo_root}/tools/test-local-external-indexing-guard.php"
	binding_fixture_entry integrated_mariadb "${repo_root}/public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php"
	binding_fixture_entry search_preimage_archive "${preimage_archive}"
	binding_fixture_entry search_preimage_inventory "${preimage_inventory}"
	binding_fixture_entry search_candidate_archive "${candidate_archive}"
	binding_fixture_entry search_candidate_inventory "${candidate_inventory}"
	binding_fixture_entry business_preimage_archive "${business_preimage_archive}"
	binding_fixture_entry business_preimage_inventory "${business_preimage_inventory}"
	binding_fixture_entry business_candidate_archive "${business_candidate_archive}"
	binding_fixture_entry business_candidate_inventory "${business_candidate_inventory}"
	binding_fixture_entry theme_preimage_archive "${theme_preimage_archive}"
	binding_fixture_entry theme_preimage_inventory "${theme_preimage_inventory}"
	binding_fixture_entry theme_preimage_sidecar "${theme_preimage_sidecar}"
	binding_fixture_entry theme_candidate_archive "${theme_candidate_archive}"
	binding_fixture_entry theme_candidate_inventory "${theme_candidate_inventory}"
	binding_fixture_entry theme_candidate_sidecar "${theme_candidate_sidecar}"
	printf 'END\tAKB_SEARCH_RELEASE_BINDING_V1\n'
} > "${recovery_binding}"
chmod 0600 "${recovery_binding}"

recovery_backup="${recovery_state_root}/akibara-search-0.1.14-preimage-fixture.sql.gz"
printf '%s\n' 'CREATE TABLE fixture (id bigint);' | gzip -n > "${recovery_backup}"
chmod 0600 "${recovery_backup}"
backup_archive_sha="$(sha256_file "${recovery_backup}")"
backup_sql_sha="$(gzip -cd "${recovery_backup}" | shasum -a 256 | awk '{print $1}')"
fixture_sha='1111111111111111111111111111111111111111111111111111111111111111'
protected_sha="$(jq -er '.production_invariants.recovery_rollback.business_search_feed_contract_transition.candidate.full_snapshot_sha256' "${contract_file}")"
protected_rollback_sha="$(jq -er '.production_invariants.recovery_rollback.business_search_feed_contract_transition.rollback.full_snapshot_sha256' "${contract_file}")"
price_preimage_sha='0df1d76c5d89f28087d4595fa4e64117ce526c39a97ccba23de7581cc9a719fc'
price_normalized_sha='2c75951b6f1df45cd8e2eb7fd5e0ffae9fc8586df4b160406fbb6ee903825a05'
http_script_sha="$(sha256_file "${repo_root}/tools/akibara-releases/search-0.1.14-http-smoke.php")"
integration_script_sha="$(sha256_file "${repo_root}/public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php")"

make_recovery_cycle_fixture() {
	local iteration="$1" output="$2"
	jq -n --argjson iteration "${iteration}" --arg sha "${fixture_sha}" \
		--arg protected "${protected_sha}" --arg preimage "${price_preimage_sha}" \
		--arg protected_rollback "${protected_rollback_sha}" \
		--arg normalized "${price_normalized_sha}" --arg apache "${isolated_php_image}" \
		--arg node "$(jq -r '.production_invariants.recovery_rollback.images.node' "${search_contract}")" \
		--arg theme_candidate_inventory "${theme_candidate_inventory_sha}" \
		--arg theme_rollback_inventory "${theme_preimage_inventory_sha}" \
		--arg ai_engine "$(sha256_file "${ai_audit_engine}")" \
		--arg ai_policy "$(sha256_file "${ai_audit_policy}")" \
		--arg http_script "${http_script_sha}" --arg integration_script "${integration_script_sha}" '
	  def statuses: {
	    count_aot:200,count_aot_phrase:200,count_atalier:200,count_atelier:200,
	    count_opm:200,count_opm_alias:200,count_opm_series:200,count_opm_volume:200,
	    shop_opm:200,shop_pack:200,shop_zero:200,suggest_one_piece:200,suggest_opm:200};
	  def response_bytes: statuses | with_entries(.value=128);
	  def response_hashes: statuses | with_entries(.value=$sha);
	  {
	    iteration:$iteration,result:"PASS",fresh_restore:true,
	    action_scheduler_stabilization:{
	      provider_inventory:{
	        schema:"akibara-action-scheduler-provider-inventory-v1",
	        version:"3.9.3",mount_root:"/var/www/html",
	        files:[
	          {path:"as/1.php",sha256:$sha},{path:"as/2.php",sha256:$sha},
	          {path:"as/3.php",sha256:$sha},{path:"as/4.php",sha256:$sha},
	          {path:"as/5.php",sha256:$sha},{path:"as/6.php",sha256:$sha},
	          {path:"as/7.php",sha256:$sha}]},
	      rollback:{
	        schema:"akibara-action-scheduler-stabilization-v1",
	        execution_scope:"explicit-action-id-only",provider_files:7,
	        provider_inventory_sha256:"360dd589a38e34689af7e340b680c2c209240e052d3227b0e897e21b5d50d853",
	        mode:"hybrid-migration",actions_executed:1,action_id:383404,
	        initial_runtime:{version:"3.9.3",store_class:"ActionScheduler_HybridStore",migration_status:{present:false,value:""}},
	        final_runtime:{version:"3.9.3",store_class:"ActionScheduler_DBStore",migration_status:{present:true,value:"complete"}},
	        final_observation:"fresh-post-action-process",log_sha256:$sha},
	      forward:{
	        schema:"akibara-action-scheduler-stabilization-v1",
	        execution_scope:"explicit-action-id-only",provider_files:7,
	        provider_inventory_sha256:"360dd589a38e34689af7e340b680c2c209240e052d3227b0e897e21b5d50d853",
	        mode:"hybrid-migration",actions_executed:1,action_id:383413,
	        initial_runtime:{version:"3.9.3",store_class:"ActionScheduler_HybridStore",migration_status:{present:false,value:""}},
	        final_runtime:{version:"3.9.3",store_class:"ActionScheduler_DBStore",migration_status:{present:true,value:"complete"}},
	        final_observation:"fresh-post-action-process",log_sha256:$sha}},
	    ephemeral_docker_cleanup_verified:true,exact_pair_cycle:true,
	    search_inactive_at_mixed_and_ready_midpoints:true,
	    canonical_redump_sha256:$sha,gate_v2_initial_sha256:$sha,
	    live_drain_initial_sha256:$sha,gate_v1_rollback_sha256:$sha,
	    gate_v2_forward_sha256:$sha,live_drain_forward_sha256:$sha,
	    rollback_mixed_midpoint_sha256:$sha,rollback_ready_pair_sha256:$sha,
		    forward_mixed_midpoint_sha256:$sha,forward_ready_pair_sha256:$sha,
		    protected_fingerprint_sha256:$protected,
		    protected_fingerprint_transition:{
		      candidate_sha256:$protected,rollback_sha256:$protected_rollback,
		      checkpoint_sha256:{candidate:[$protected,$protected,$protected,$protected,$protected,$protected,$protected,$protected,$protected],rollback:[$protected_rollback,$protected_rollback,$protected_rollback]},
		      single_exact_feed_contract_row_transition:true,
		      changed_column_only_option_value:true,option_id_preserved:true,
		      row_count_exactly_one:true,transition_exact_before_search_activation:true,
		      search_inactive_at_artifact_midpoints:true,forward_restores_candidate_exactly:true},
		    derived_price_pair:{
	      preimage_sha256:$preimage,normalized_sha256:$normalized,
	      checkpoint_sha256:{preimage:[$preimage,$preimage],
	        normalized:[$normalized,$normalized,$normalized,$normalized,
	          $normalized,$normalized,$normalized,$normalized]},
	      preimage_preserved_until_rebuild:true,
	      normalized_preserved_through_rollback_forward_http_mariadb:true,
	      v2_stored_equals_index_derivation:true},
		theme_quality:{
		  result:"PASS",pass:$iteration,
		  theme:{candidate_version:"2.1.70",rollback_version:"2.1.69",
		    candidate_inventory_sha256:$theme_candidate_inventory,
		    rollback_inventory_sha256:$theme_rollback_inventory,candidate_overlay_exact:true},
		  static_contract:{passes:1,log_sha256:$sha},
		  package_verify:{passes:1,runtime_files:103,log_sha256:$sha},
		  node:{image:$node,tests:31,passes:31,failures:0,network:"none",log_sha256:$sha},
		  phpunit:{image:$apache,tests:18,assertions:52,failures:0,network:"none",log_sha256:$sha},
		  ai_audit_unit:{image:$node,result:"PASS",network:"none",log_sha256:$sha},
		  external_network_dependency_allowed:false,receipt_sha256:$sha,
		  active_theme_state:{initial_log_sha256:$sha,before_http_log_sha256:$sha,
		    after_http_log_sha256:$sha,slug:"akibara-gp",version:"2.1.70",active:true}},
		ai_discoverability:{
		  result:"PASS",schema_version:3,report_sha256:$sha,stderr_sha256:$sha,
		  engine_sha256:$ai_engine,policy_sha256:$ai_policy,image:$node,
		  network_internal:true,published_ports:0,external_network_dependency_allowed:false,
		  base_url:"https://akibara.cl/",fetch_scope:"docker-internal-only",
		  redirect_policy:"manual_fail_closed",summary:{status:"pass",exit_code:0,fail:0}},
	    http_smoke:{
	      schema:"akibara-search-http-smoke-v1",result:"PASS",
	      checks:{aot_phrase_parity:true,atelier_fuzzy_parity:true,
	        opm_alias_series_parity:true,opm_titles:true,opm_volume_narrows:true,
	        pack_one_tomo:true,shop_manga_unit:true,suggest_one_piece:true,
	        zero_toolbar_filter:true},
	      counts:{aot:3,aot_phrase:3,atalier:4,atelier:4,opm:7,opm_alias:7,
	        opm_series:7,opm_volume:2},
	      follow_redirects:false,host_header:"localhost:8080",http_status:statuses,
	      image:$apache,log_sha256:$sha,network_internal:true,
	      origin_scope:"docker-internal",protected_after_sha256:$protected,
	      protected_before_sha256:$protected,published_ports:0,
	      readiness:{attempts:1,final_status:200,response_sha256:$sha},
	      receipt_schema:"akibara-search-http-smoke-v1",receipt_sha256:$sha,
	      response_bytes:response_bytes,response_sha256:response_hashes,
	      script_sha256:$http_script,server_log_sha256:$sha},
	    integrated_mariadb:{
	      baseline_log_sha256:$sha,candidate_log_sha256:$sha,
	      fixture_user_schema_limited:true,modes:["baseline","candidate"],
	      protected_after_sha256:$protected,provider_active:true,receipt_sha256:$sha,
	      reconciler_zero_divergence:true,result:"PASS",script_sha256:$integration_script}
	  }' > "${output}"
}

cycle_one="${recovery_fixture_dir}/cycle-one.json"
cycle_two="${recovery_fixture_dir}/cycle-two.json"
make_recovery_cycle_fixture 1 "${cycle_one}"
make_recovery_cycle_fixture 2 "${cycle_two}"
recovery_good="${recovery_fixture_dir}/final-receipt.json"
jq -n \
	--arg release_id "${release_id}" --arg completed "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
	--arg expected_run_id "${recovery_expected_run_id}" --arg expected_state_sha "${recovery_expected_state_sha}" \
	--arg protected "${protected_sha}" --arg price_preimage "${price_preimage_sha}" \
	--arg search_schema "${fixture_sha}" --arg backup "${recovery_backup}" \
	--arg backup_sha "${backup_archive_sha}" --arg backup_sql_sha "${backup_sql_sha}" \
	--arg binding_sha "$(sha256_file "${recovery_binding}")" \
	--arg search_contract_sha "${contract_sha}" --arg business_contract_sha "${business_contract_sha}" \
	--arg theme_contract_sha "${theme_contract_sha}" \
	--arg search_candidate_archive "${candidate_archive_sha}" --arg search_candidate_inventory "${candidate_inventory_sha}" \
	--arg search_rollback_archive "${preimage_archive_sha}" --arg search_rollback_inventory "${preimage_inventory_sha}" \
	--arg business_candidate_archive "${business_candidate_archive_sha}" --arg business_candidate_inventory "${business_candidate_inventory_sha}" \
	--arg business_rollback_archive "${business_preimage_archive_sha}" --arg business_rollback_inventory "${business_preimage_inventory_sha}" \
	--arg theme_candidate_archive "${theme_candidate_archive_sha}" --arg theme_candidate_inventory "${theme_candidate_inventory_sha}" --arg theme_candidate_sidecar "${theme_candidate_sidecar_sha}" \
	--arg theme_rollback_archive "${theme_preimage_archive_sha}" --arg theme_rollback_inventory "${theme_preimage_inventory_sha}" --arg theme_rollback_sidecar "${theme_preimage_sidecar_sha}" \
	--arg compose_sha "$(sha256_file "${repo_root}/compose.yaml")" \
	--arg packager_sha "$(sha256_file "${repo_root}/tools/akibara-releases/package-search-0.1.14-production.sh")" \
	--arg runtime_packager_sha "$(sha256_file "${repo_root}/tools/akibara-releases/package-runtime.sh")" \
	--arg rail_sha "$(sha256_file "${recovery_rail}")" \
	--arg proof_sha "$(sha256_file "${repo_root}/tools/akibara-releases/search-0.1.14-recovery-proof.php")" \
	--arg runbook_sha "$(sha256_file "${repo_root}/ops/akibara-search-0.1.14-recovery-runbook.md")" \
	--arg static_test_sha "$(sha256_file "${recovery_static_test}")" \
	--arg http_smoke_sha "${http_script_sha}" --arg integration_sha "${integration_script_sha}" \
	--arg protected_sql_sha "$(sha256_file "${repo_root}/tools/akibara-releases/search-0.1.14-protected-fingerprint.sql")" \
	--arg check_validator_sha "$(sha256_file "${repo_root}/tools/akibara-releases/search-0.1.14-check-table.awk")" \
	--arg index_guard_sha "$(sha256_file "${repo_root}/public_html/wp-content/mu-plugins/akibara-local-external-indexing-guard.php")" \
	--arg index_guard_test_sha "$(sha256_file "${repo_root}/tools/test-local-external-indexing-guard.php")" \
	--arg theme_test_sha "$(sha256_file "${theme_release_test}")" \
	--arg theme_phpunit_config_sha "$(sha256_file "${theme_phpunit_config}")" \
	--arg ai_engine_sha "$(sha256_file "${ai_audit_engine}")" \
	--arg ai_test_sha "$(sha256_file "${ai_audit_test}")" \
	--arg ai_wrapper_sha "$(sha256_file "${ai_audit_wrapper}")" \
	--arg ai_policy_sha "$(sha256_file "${ai_audit_policy}")" \
	--arg ai_runbook_sha "$(sha256_file "${ai_audit_runbook}")" \
	--arg mariadb "$(jq -r '.production_invariants.recovery_rollback.images.mariadb' "${search_contract}")" \
	--arg wpcli "$(jq -r '.production_invariants.recovery_rollback.images.wp_cli' "${search_contract}")" \
	--arg apache "${isolated_php_image}" \
	--arg node "$(jq -r '.production_invariants.recovery_rollback.images.node' "${search_contract}")" \
	--slurpfile search "${search_contract}" --slurpfile business "${business_contract}" --slurpfile theme "${theme_contract}" \
	--slurpfile one "${cycle_one}" --slurpfile two "${cycle_two}" '
	{
	  schema:"akibara-search-recovery-cycle-v2",release_id:$release_id,result:"PASS",
	  completed_at_utc:$completed,production_mutated:false,main_database_mutated:false,
	  source_database_verification:{
	    result:"PASS",freeze_rechecked:true,wordpress_main_state:"exited",cron_main_state:"exited",
	    foreign_connections:0,named_locks_free:true,source_table_count:91,
	    expected_run_id:$expected_run_id,protected_sha256:$protected,
	    derived_price_pair_preimage_sha256:$price_preimage,search_schema_sha256:$search_schema,
	    raw_state_sha256:$expected_state_sha,canonical_archive_sha256:$backup_sha,
	    canonical_sql_sha256:$backup_sql_sha,matches_initial_backup:true},
	  release_binding:{
	    initial_snapshot_sha256:$binding_sha,unchanged_through_final_sidecar:true,
	    preimage:{run_id:$expected_run_id,raw_state_sha256:$expected_state_sha},
	    search_contract:{path:"ops/contracts/akibara-search-0.1.14.json",
	      release_id:"akibara-search-0.1.14",sha256:$search_contract_sha},
	    business_contract:{path:"ops/contracts/akibara-business-1.99.16.json",
	      release_id:"akibara-business-1.99.16",sha256:$business_contract_sha},
	    theme_contract:{path:"ops/contracts/akibara-gp-2.1.70.json",
	      release_id:"akibara-gp-2.1.70",sha256:$theme_contract_sha},
	    artifacts:{
	      search:{
	        preimage:{archive:$search[0].components.search.preimage.archive,
	          archive_sha256:$search_rollback_archive,inventory:$search[0].components.search.preimage.inventory,
	          inventory_sha256:$search_rollback_inventory},
	        candidate:{archive:$search[0].components.search.candidate.archive,
	          archive_sha256:$search_candidate_archive,inventory:$search[0].components.search.candidate.inventory,
	          inventory_sha256:$search_candidate_inventory}},
	      business:{
	        preimage:{archive:$business[0].components.business.preimage.archive,
	          archive_sha256:$business_rollback_archive,inventory:$business[0].components.business.preimage.inventory,
	          inventory_sha256:$business_rollback_inventory},
	        candidate:{archive:$business[0].components.business.candidate.archive,
	          archive_sha256:$business_candidate_archive,inventory:$business[0].components.business.candidate.inventory,
	          inventory_sha256:$business_candidate_inventory}},
	      theme:{
	        preimage:{version:"2.1.69",archive:$theme[0].component.preimage.archive,
	          archive_sha256:$theme_rollback_archive,inventory:$theme[0].component.preimage.inventory,
	          inventory_sha256:$theme_rollback_inventory,
	          checksum_sidecar:$theme[0].component.preimage.checksum_sidecar,
	          checksum_sidecar_sha256:$theme_rollback_sidecar},
	        candidate:{version:"2.1.70",archive:$theme[0].component.candidate.archive,
	          archive_sha256:$theme_candidate_archive,inventory:$theme[0].component.candidate.inventory,
	          inventory_sha256:$theme_candidate_inventory,
	          checksum_sidecar:$theme[0].component.candidate.checksum_sidecar,
	          checksum_sidecar_sha256:$theme_candidate_sidecar}}},
	    evidence_code:{compose_sha256:$compose_sha,packager_library_sha256:$packager_sha,
	      runtime_packager_sha256:$runtime_packager_sha,rail_sha256:$rail_sha,
	      recovery_proof_sha256:$proof_sha,runbook_sha256:$runbook_sha,
	      static_test_sha256:$static_test_sha,http_smoke_sha256:$http_smoke_sha,
	      integrated_mariadb_sha256:$integration_sha,
	      protected_fingerprint_sql_sha256:$protected_sql_sha,
	      check_table_validator_sha256:$check_validator_sha,
	      local_index_guard_sha256:$index_guard_sha,
	      local_index_guard_test_sha256:$index_guard_test_sha,
	      theme_release_test_sha256:$theme_test_sha,
	      theme_phpunit_config_sha256:$theme_phpunit_config_sha,
	      ai_audit_engine_sha256:$ai_engine_sha,ai_audit_test_sha256:$ai_test_sha,
	      ai_audit_wrapper_sha256:$ai_wrapper_sha,ai_audit_policy_sha256:$ai_policy_sha,
	      ai_audit_runbook_sha256:$ai_runbook_sha},
	    images:{mariadb:$mariadb,wp_cli:$wpcli,apache:$apache,node:$node}},
	  integrated_release:{
	    theme:{candidate_version:"2.1.70",rollback_version:"2.1.69",
	      active_candidate_verified_per_cycle:true,quality_passes:2},
	    ai_discoverability:{schema_version:3,passes:2,base_url:"https://akibara.cl/",
	      fetch_scope:"docker-internal-only",redirect_policy:"manual_fail_closed",
	      external_network_dependency_allowed:false}},
	  backup:{path:$backup,archive_sha256:$backup_sha,decompressed_sha256:$backup_sql_sha,
	    location:"local-only"},
	  cycles:[$one[0],$two[0]]
	}' > "${recovery_good}"
chmod 0600 "${recovery_good}"
write_sidecar "${recovery_good}"
verify_recovery_receipt "${recovery_good}" >/dev/null \
	|| die 'consumer rechazó recovery receipt v2 positivo/material'

traversal_receipt="${recovery_fixture_dir}/../run-positive-fixture/final-receipt.json"
if (verify_recovery_receipt "${traversal_receipt}") >/dev/null 2>&1; then
	die 'consumer aceptó traversal .. en ruta de recovery receipt'
fi
nested_recovery_dir="${recovery_fixture_dir}/nested"
mkdir -p -- "${nested_recovery_dir}"
cp -- "${recovery_good}" "${nested_recovery_dir}/final-receipt.json"
write_sidecar "${nested_recovery_dir}/final-receipt.json"
if (verify_recovery_receipt "${nested_recovery_dir}/final-receipt.json") >/dev/null 2>&1; then
	die 'consumer aceptó recovery receipt en subdirectorio no canónico'
fi

recovery_good_copy="${scratch}/recovery-good.json"
cp -- "${recovery_good}" "${recovery_good_copy}"
reject_recovery_mutation() {
	local filter="$1" reason="$2"
	jq "${filter}" "${recovery_good_copy}" > "${recovery_good}"
	write_sidecar "${recovery_good}"
	if (verify_recovery_receipt "${recovery_good}") >/dev/null 2>&1; then
		die "consumer aceptó recovery adulterado: ${reason}"
	fi
}
reject_recovery_mutation 'del(.release_binding.initial_snapshot_sha256)' 'snapshot ausente'
reject_recovery_mutation '.release_binding.initial_snapshot_sha256="5555555555555555555555555555555555555555555555555555555555555555"' 'snapshot divergente'
reject_recovery_mutation '.cycles[0].action_scheduler_stabilization.forward.actions_executed=2' 'estabilizacion con conteo de acciones inexacto'
reject_recovery_mutation '.cycles[1].action_scheduler_stabilization.rollback.provider_inventory_sha256="5555555555555555555555555555555555555555555555555555555555555555"' 'inventario proveedor Action Scheduler divergente'
reject_recovery_mutation '.cycles[0].derived_price_pair.preimage_sha256="5555555555555555555555555555555555555555555555555555555555555555"' 'preimage de precio divergente'
reject_recovery_mutation '.cycles[1].derived_price_pair.normalized_sha256="5555555555555555555555555555555555555555555555555555555555555555" | .cycles[1].derived_price_pair.checkpoint_sha256.normalized |= map("5555555555555555555555555555555555555555555555555555555555555555")' 'normalización distinta entre ciclos'
reject_recovery_mutation '.cycles[0].derived_price_pair.checkpoint_sha256.preimage |= .[0:1]' 'menos de dos checkpoints preimage'
reject_recovery_mutation '.cycles[0].derived_price_pair.checkpoint_sha256.normalized |= .[0:7]' 'menos de ocho checkpoints normalizados'
reject_recovery_mutation '.cycles[0].derived_price_pair.v2_stored_equals_index_derivation=false' 'derivación v2 falsa'
reject_recovery_mutation '.cycles[0].protected_fingerprint_transition.rollback_sha256="5555555555555555555555555555555555555555555555555555555555555555"' 'SHA rollback protegido divergente'
reject_recovery_mutation '.cycles[0].protected_fingerprint_transition.checkpoint_sha256.candidate |= .[0:8]' 'menos de nueve checkpoints protegidos candidato'
reject_recovery_mutation '.cycles[1].protected_fingerprint_transition.checkpoint_sha256.rollback |= .[0:2]' 'menos de tres checkpoints protegidos rollback'
reject_recovery_mutation '.cycles[0].protected_fingerprint_transition.option_id_preserved=false' 'option_id no preservado'
reject_recovery_mutation '.cycles[0].protected_fingerprint_transition.transition_exact_before_search_activation=false' 'transición protegida después de activar Search'
reject_recovery_mutation '.cycles[0].live_drain_initial_sha256="bad"' 'live drain sin SHA'
reject_recovery_mutation '.release_binding.evidence_code.compose_sha256="5555555555555555555555555555555555555555555555555555555555555555"' 'compose divergente'
reject_recovery_mutation '.release_binding.theme_contract.sha256="5555555555555555555555555555555555555555555555555555555555555555"' 'contrato Theme divergente'
reject_recovery_mutation '.release_binding.artifacts.theme.candidate.checksum_sidecar_sha256="5555555555555555555555555555555555555555555555555555555555555555"' 'sidecar Theme candidato divergente'
reject_recovery_mutation '.cycles[0].theme_quality.node.tests=30' 'suite Theme Node incompleta'
reject_recovery_mutation '.cycles[1].theme_quality.active_theme_state.version="2.1.69"' 'Theme candidato no activo'
reject_recovery_mutation '.cycles[0].ai_discoverability.summary.fail=1' 'auditor IA con fallo'
reject_recovery_mutation '.cycles[1].ai_discoverability.redirect_policy="follow"' 'auditor IA permite redirects'
reject_recovery_mutation 'del(.integrated_release.ai_discoverability)' 'resumen integrado IA ausente'
jq --arg path "${recovery_fixture_dir}/../akibara-search-0.1.14-preimage-fixture.sql.gz" \
	'.backup.path=$path' "${recovery_good_copy}" > "${recovery_good}"
write_sidecar "${recovery_good}"
if (verify_recovery_receipt "${recovery_good}") >/dev/null 2>&1; then
	die 'consumer aceptó traversal .. en ruta del backup recovery'
fi
cp -- "${recovery_good_copy}" "${recovery_good}"
write_sidecar "${recovery_good}"
mv -- "${recovery_binding}" "${recovery_binding}.missing"
if (verify_recovery_receipt "${recovery_good}") >/dev/null 2>&1; then
	die 'consumer aceptó recovery sin snapshot material'
fi
mv -- "${recovery_binding}.missing" "${recovery_binding}"
chmod 0600 "${recovery_binding}"
verify_recovery_receipt "${recovery_good}" >/dev/null \
	|| die 'consumer no volvió a aceptar recovery positivo restaurado'

gate_receipt="$(receipt_path)"; recovery_fixture="${scratch}/final-receipt.json"
gate_completed_at="$(iso_add "$(date -u +%Y-%m-%dT%H:%M:%SZ)" -30)"
mkdir -p -- "$(dirname -- "${gate_receipt}")"; printf '%s\n' '{"fixture":true}' > "${recovery_fixture}"
jq -n --arg path "${recovery_fixture}" --arg completed "${gate_completed_at}" \
	'{completed_at_utc:$completed,recovery:{receipt:{path:$path}}}' > "${gate_receipt}"
chmod 0600 "${gate_receipt}" "${recovery_fixture}"

# Overrides acotados para ejercitar el constructor con artefactos fixture.
load_contract() { :; }
require_sealed_contract() { :; }
load_config() { hostinger_username='u_fixture'; cloudflare_account_id='11111111111111111111111111111111'; }
verify_artifacts() { :; }
verify_local_receipt_integrity() { :; }
local_gate_max_age=1800

prepare_handoff "${config}" >/dev/null
handoff="${local_state_root}/handoffs/${release_digest}/handoff.json"
evidence_dir="${local_state_root}/evidence/${release_digest}"
verify_handoff_file "${handoff}"
good_handoff="${scratch}/handoff-good.json"; cp -- "${handoff}" "${good_handoff}"

reject_handoff_mutation() {
	local filter="$1" reason="$2"
	jq "${filter}" "${good_handoff}" > "${handoff}"; write_sidecar "${handoff}"
	if (verify_handoff_file "${handoff}") >/dev/null 2>&1; then die "handoff aceptó ${reason}"; fi
	cp -- "${good_handoff}" "${handoff}"; write_sidecar "${handoff}"
}
reject_handoff_mutation '.targets=[.targets[1]]' 'promoción Search-only'
reject_handoff_mutation '.evidence.protected_runtime += ["akibara-business"]' 'Business como runtime protegido'
reject_handoff_mutation '.evidence.search_protected_scope="all-protected-surfaces"' 'scope Search protegido que mezcla la opción de fase'
reject_handoff_mutation '.operations.candidate.sequence[4:6] |= reverse' 'checkpoints reordenados'
reject_handoff_mutation 'del(.operations.candidate.deploy_business)' 'deploy Business ausente'
reject_handoff_mutation '.components.business.candidate.inventory_sha256="dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd"' 'inventario Business divergente'

# Preflight material del par.
cf_binding="${evidence_dir}/preflight-cf.json"
jq -n --arg id "${cloudflare_zone_id}" --arg name "${cloudflare_zone_name}" --arg account "${cloudflare_account_id}" '{success:true,status:200,result:{id:$id,name:$name,account:{id:$account}},errors:[]}' > "${cf_binding}"
baseline_text="${evidence_dir}/baseline-ui.txt"; printf '%s\n' 'akibara.cl akibara-business 1.99.10 akibara-search 0.1.12 search_active=true' > "${baseline_text}"
baseline_image="${evidence_dir}/baseline-ui.png"; fake_image "${baseline_image}"
business_baseline="${evidence_dir}/business-baseline.sha256"; cp -- "${business_preimage_inventory}" "${business_baseline}"
search_baseline="${evidence_dir}/search-baseline.sha256"; cp -- "${preimage_inventory}" "${search_baseline}"
protected_before="${evidence_dir}/protected-before.sha256"; fixture_sha='dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd'
printf '%s  %s\n' "${fixture_sha}" 'public_html/wp-content/plugins/akibara-cart-checkout-blocks/main.php' "${fixture_sha}" 'public_html/wp-content/plugins/bluex-for-woocommerce/main.php' "${fixture_sha}" 'public_html/wp-content/themes/akibara-gp/style.css' > "${protected_before}"
commerce_before="${evidence_dir}/commerce-before.tsv"; printf '@dataset\torders\tpresent\norders\t1\t%s\n' "${fixture_sha}" > "${commerce_before}"
search_protected_before="${evidence_dir}/search-protected-before.tsv"; printf '@table\takb_search_ledger\t%s\n' "${fixture_sha}" > "${search_protected_before}"
ready="$(jq -r '.ready_at_utc' "${handoff}")"
webroot_before="${evidence_dir}/webroot-before.json"; jq -n --arg t "${ready}" '{source:"hostinger-file-manager-ui",path:"public_html",captured_at_utc:$t,entries:[{name:"index.php",type:"file"},{name:"wp-content",type:"directory"}]}' > "${webroot_before}"
plugins_before="${evidence_dir}/plugins-before.json"; jq -n --arg t "${ready}" '{source:"hostinger-file-manager-ui",path:"public_html/wp-content/plugins",captured_at_utc:$t,entries:[{name:"akibara-business",type:"directory"},{name:"akibara-search",type:"directory"}]}' > "${plugins_before}"
cleanup_before="${evidence_dir}/cleanup-before.png"; fake_image "${cleanup_before}"
preflight_manifest="${evidence_dir}/preflight-evidence.json"
jq -n --arg release_id "${release_id}" --arg digest "${release_digest}" --arg handoff_sha "$(sha256_file "${handoff}")" --arg t "${ready}" \
	--argjson cf "$(descriptor "${cf_binding}")" --argjson text "$(descriptor "${baseline_text}")" --argjson image "$(descriptor "${baseline_image}")" \
	--argjson business "$(descriptor "${business_baseline}")" --argjson search "$(descriptor "${search_baseline}")" --argjson protected "$(descriptor "${protected_before}")" \
	--argjson commerce "$(descriptor "${commerce_before}")" --argjson searchp "$(descriptor "${search_protected_before}")" --argjson webroot "$(descriptor "${webroot_before}")" \
	--argjson plugins "$(descriptor "${plugins_before}")" --argjson cleanup "$(descriptor "${cleanup_before}")" \
	'{schema_version:3,kind:"akibara-business-search-production-preflight-evidence",release_id:$release_id,release_digest:$digest,handoff_sha256:$handoff_sha,completed_at_utc:$t,captures:{cloudflare_binding:$cf,baseline_ui_text:$text,baseline_ui_image:$image,business_runtime_baseline:$business,search_runtime_baseline:$search,protected_runtime:$protected,commerce:$commerce,search_protected:$searchp,remote_webroot_listing:$webroot,remote_plugins_listing:$plugins,remote_cleanup_image:$cleanup}}' > "${preflight_manifest}"
write_sidecar "${preflight_manifest}"
verify_preflight_bundle "${handoff}" "${evidence_dir}" >/dev/null

make_envelope() {
	local purpose="$1" operation="$2" output="$3" response="$4"
	jq -n --arg tool "$(jq -r ".operations.${purpose}.${operation}.tool" "${handoff}")" \
		--argjson request "$(jq ".operations.${purpose}.${operation}.args" "${handoff}")" --argjson response "${response}" \
		'{tool:$tool,request:$request,response:$response}' > "${output}"
}

make_timeline() {
	local purpose="$1" output="$2" index=0 id bp sp active maintenance text image raw cp checkpoint_time
	jq -n --arg purpose "${purpose}" '{purpose:$purpose,checkpoints:[]}' > "${output}"
	while IFS= read -r id; do
		if [[ "${purpose}" == candidate ]]; then
			if ((index < 4)); then bp=1.99.10; sp=0.1.12; elif ((index < 6)); then bp=1.99.16; sp=0.1.12; else bp=1.99.16; sp=0.1.14; fi
		else
			if ((index < 4)); then bp=1.99.16; sp=0.1.14; elif ((index < 6)); then bp=1.99.10; sp=0.1.14; else bp=1.99.10; sp=0.1.12; fi
		fi
		if ((index < 2 || index >= 8)); then active=true; else active=false; fi
		if ((index == 0 || index >= 12)); then maintenance=false; else maintenance=true; fi
		text="${evidence_dir}/${purpose}-checkpoint-${index}.txt"; image="${evidence_dir}/${purpose}-checkpoint-${index}.png"
		raw="${id} akibara.cl akibara-business ${bp} akibara-search ${sp} search_active=${active} maintenance_mode=${maintenance}"
		[[ "${id}" == litespeed-ui-purged ]] && raw="${raw} LiteSpeed purge success persisted"
		printf '%s\n' "${raw}" > "${text}"; fake_image "${image}"
		checkpoint_time="$(iso_add "${operation_started}" "${index}")"
		cp="$(jq -n --arg id "${id}" --arg t "${checkpoint_time}" --arg bp "${bp}" --arg sp "${sp}" --argjson active "${active}" --argjson maintenance "${maintenance}" --argjson text "$(descriptor "${text}")" --argjson image "$(descriptor "${image}")" '{id:$id,captured_at_utc:$t,state:{business_version:$bp,search_version:$sp,search_active:$active,maintenance_mode:$maintenance},text:$text,image:$image}')"
		jq --argjson cp "${cp}" '.checkpoints += [$cp]' "${output}" > "${output}.next"; mv -- "${output}.next" "${output}"
		((index+=1))
	done < <(jq -r '.operations.candidate.sequence[]' "${handoff}")
}

make_operation() {
	local purpose="$1" business_inventory="$2" search_inventory="$3" timeline deploy_b deploy_s cache cf har runtime_b runtime_s protected_after commerce_after search_after admin webroot plugins cleanup manifest started
	timeline="${evidence_dir}/${purpose}-timeline.json"; make_timeline "${purpose}" "${timeline}"
	deploy_b="${evidence_dir}/${purpose}-business-deploy.json"; deploy_s="${evidence_dir}/${purpose}-search-deploy.json"
	timeout_response="$(jq -n --arg e 'tool call error: tool call failed for `hostinger-hosting/hosting_deployWordpressPlugin`

Caused by:
    timed out awaiting tools/call after 120s' '{Err:$e}')"
	make_envelope "${purpose}" deploy_business "${deploy_b}" "${timeout_response}"
	make_envelope "${purpose}" deploy_search "${deploy_s}" "${timeout_response}"
	cache="${evidence_dir}/${purpose}-hostinger-cache.json"; make_envelope "${purpose}" hostinger_cache "${cache}" '{"content":[{"type":"text","text":"{\"message\":\"Request accepted\"}"}]}'
	cf="${evidence_dir}/${purpose}-cloudflare.json"; make_envelope "${purpose}" cloudflare "${cf}" "$(jq -n --arg id "${cloudflare_zone_id}" '{success:true,status:200,result:{id:$id},errors:[]}')"
	har="${evidence_dir}/${purpose}-public.har.json"; jq -n '{log:{entries:[{request:{url:"https://akibara.cl/wp-json/akibara/v1/search/suggest?q=manga"},response:{status:200}},{request:{url:"https://akibara.cl/manga/"},response:{status:200}}]}}' > "${har}"
	runtime_b="${evidence_dir}/${purpose}-business.sha256"; cp -- "${business_inventory}" "${runtime_b}"
	runtime_s="${evidence_dir}/${purpose}-search.sha256"; cp -- "${search_inventory}" "${runtime_s}"
	protected_after="${evidence_dir}/${purpose}-protected.sha256"; cp -- "${protected_before}" "${protected_after}"
	commerce_after="${evidence_dir}/${purpose}-commerce.tsv"; cp -- "${commerce_before}" "${commerce_after}"
	search_after="${evidence_dir}/${purpose}-search-protected.tsv"; cp -- "${search_protected_before}" "${search_after}"
	admin="${evidence_dir}/${purpose}-admin.json"; jq -n '{topology:"clean_pre",rebuild:null,docs:1503,eligible:1503,work:{pending:0,leased:0,blocked:0,error:""}}' > "${admin}"
	webroot="${evidence_dir}/${purpose}-webroot.json"; jq -n --arg t "${operation_completed}" '{source:"hostinger-file-manager-ui",path:"public_html",captured_at_utc:$t,entries:[{name:"index.php",type:"file"},{name:"wp-content",type:"directory"}]}' > "${webroot}"
	plugins="${evidence_dir}/${purpose}-plugins.json"; jq -n --arg t "${operation_completed}" '{source:"hostinger-file-manager-ui",path:"public_html/wp-content/plugins",captured_at_utc:$t,entries:[{name:"akibara-business",type:"directory"},{name:"akibara-search",type:"directory"}]}' > "${plugins}"
	cleanup="${evidence_dir}/${purpose}-cleanup.png"; fake_image "${cleanup}"
	manifest="${evidence_dir}/${purpose}-evidence.json"; started="${operation_started}"
	jq -n --arg release_id "${release_id}" --arg digest "${release_digest}" --arg handoff_sha "$(sha256_file "${handoff}")" --arg purpose "${purpose}" --arg started "${started}" --arg completed "${operation_completed}" \
		--argjson timeline "$(descriptor "${timeline}")" --argjson db "$(descriptor "${deploy_b}")" --argjson ds "$(descriptor "${deploy_s}")" --argjson cache "$(descriptor "${cache}")" --argjson cf "$(descriptor "${cf}")" \
		--argjson har "$(descriptor "${har}")" --argjson rb "$(descriptor "${runtime_b}")" --argjson rs "$(descriptor "${runtime_s}")" --argjson protected "$(descriptor "${protected_after}")" \
		--argjson commerce "$(descriptor "${commerce_after}")" --argjson search "$(descriptor "${search_after}")" --argjson admin "$(descriptor "${admin}")" --argjson webroot "$(descriptor "${webroot}")" \
		--argjson plugins "$(descriptor "${plugins}")" --argjson cleanup "$(descriptor "${cleanup}")" \
		'{schema_version:3,kind:"akibara-business-search-production-operation-evidence",release_id:$release_id,release_digest:$digest,handoff_sha256:$handoff_sha,purpose:$purpose,started_at_utc:$started,completed_at_utc:$completed,captures:{transition_timeline:$timeline,business_deploy_response:$db,search_deploy_response:$ds,hostinger_cache_response:$cache,cloudflare_response:$cf,public_har:$har,business_runtime:$rb,search_runtime:$rs,protected_runtime:$protected,commerce:$commerce,search_protected:$search,admin_status:$admin,remote_webroot_listing:$webroot,remote_plugins_listing:$plugins,remote_cleanup_image:$cleanup}}' > "${manifest}"
	write_sidecar "${manifest}"
}

operation_started="$(jq -r '.verified_at_utc' "${evidence_dir}/preflight-verified-receipt.json")"
operation_completed="$(iso_add "${operation_started}" 14)"
make_operation candidate "${business_candidate_inventory}" "${candidate_inventory}"
verify_witness_bundle "${handoff}" "${evidence_dir}" candidate >/dev/null
candidate_manifest="${evidence_dir}/candidate-evidence.json"; candidate_timeline="${evidence_dir}/candidate-timeline.json"
cp -- "${candidate_timeline}" "${scratch}/candidate-timeline-good.json"
jq '.checkpoints[5].state.search_active=true' "${candidate_timeline}" > "${candidate_timeline}.bad"; mv -- "${candidate_timeline}.bad" "${candidate_timeline}"
jq --argjson d "$(descriptor "${candidate_timeline}")" '.captures.transition_timeline=$d' "${candidate_manifest}" > "${candidate_manifest}.bad"; mv -- "${candidate_manifest}.bad" "${candidate_manifest}"; write_sidecar "${candidate_manifest}"
if (verify_witness_bundle "${handoff}" "${evidence_dir}" candidate) >/dev/null 2>&1; then die 'evidencia aceptó midpoint mixto activo'; fi
cp -- "${scratch}/candidate-timeline-good.json" "${candidate_timeline}"
jq --argjson d "$(descriptor "${candidate_timeline}")" '.captures.transition_timeline=$d' "${candidate_manifest}" > "${candidate_manifest}.good"; mv -- "${candidate_manifest}.good" "${candidate_manifest}"; write_sidecar "${candidate_manifest}"

jq 'del(.checkpoints[10])' "${candidate_timeline}" > "${candidate_timeline}.bad"; mv -- "${candidate_timeline}.bad" "${candidate_timeline}"
jq --argjson d "$(descriptor "${candidate_timeline}")" '.captures.transition_timeline=$d' "${candidate_manifest}" > "${candidate_manifest}.bad"; mv -- "${candidate_manifest}.bad" "${candidate_manifest}"; write_sidecar "${candidate_manifest}"
if (verify_witness_bundle "${handoff}" "${evidence_dir}" candidate) >/dev/null 2>&1; then die 'evidencia aceptó LiteSpeed ausente'; fi
cp -- "${scratch}/candidate-timeline-good.json" "${candidate_timeline}"
jq --argjson d "$(descriptor "${candidate_timeline}")" '.captures.transition_timeline=$d' "${candidate_manifest}" > "${candidate_manifest}.good"; mv -- "${candidate_manifest}.good" "${candidate_manifest}"; write_sidecar "${candidate_manifest}"

runtime_b="${evidence_dir}/candidate-business.sha256"; cp -- "${runtime_b}" "${scratch}/business-runtime-good"
printf '%s  %s\n' "${fixture_sha}" 'akibara-business/extra.php' >> "${runtime_b}"
jq --argjson d "$(descriptor "${runtime_b}")" '.captures.business_runtime=$d' "${candidate_manifest}" > "${candidate_manifest}.bad"; mv -- "${candidate_manifest}.bad" "${candidate_manifest}"; write_sidecar "${candidate_manifest}"
if (verify_witness_bundle "${handoff}" "${evidence_dir}" candidate) >/dev/null 2>&1; then die 'evidencia aceptó inventario Business divergente'; fi
cp -- "${scratch}/business-runtime-good" "${runtime_b}"
jq --argjson d "$(descriptor "${runtime_b}")" '.captures.business_runtime=$d' "${candidate_manifest}" > "${candidate_manifest}.good"; mv -- "${candidate_manifest}.good" "${candidate_manifest}"; write_sidecar "${candidate_manifest}"
verify_witness_bundle "${handoff}" "${evidence_dir}" candidate >/dev/null

# La frescura se decide históricamente: un witness puede durar más de 1800 s si
# gate y preflight estaban frescos cuando empezó. Refecha el mismo bundle a una
# operación de una hora sin relajar el máximo de gate (1800) ni preflight (900).
long_completed="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
long_started="$(iso_add "${long_completed}" -3600)"
long_preflight_verified="$(iso_add "${long_started}" -10)"
long_preflight_completed="$(iso_add "${long_preflight_verified}" -1)"
long_ready="$(iso_add "${long_preflight_completed}" -10)"
long_gate_completed="$(iso_add "${long_ready}" -10)"

jq --arg t "${long_gate_completed}" '.completed_at_utc=$t' "${gate_receipt}" > "${gate_receipt}.next"
mv -- "${gate_receipt}.next" "${gate_receipt}"
jq --arg ready "${long_ready}" --arg gate_sha "$(sha256_file "${gate_receipt}")" \
	'.ready_at_utc=$ready | .local_gate.receipt.sha256=$gate_sha' "${handoff}" > "${handoff}.next"
mv -- "${handoff}.next" "${handoff}"; write_sidecar "${handoff}"
long_handoff_sha="$(sha256_file "${handoff}")"

for listing_file in "${webroot_before}" "${plugins_before}"; do
	jq --arg t "${long_preflight_completed}" '.captured_at_utc=$t' "${listing_file}" > "${listing_file}.next"
	mv -- "${listing_file}.next" "${listing_file}"
done
jq --arg handoff_sha "${long_handoff_sha}" --arg completed "${long_preflight_completed}" \
	--argjson webroot "$(descriptor "${webroot_before}")" --argjson plugins "$(descriptor "${plugins_before}")" \
	'.handoff_sha256=$handoff_sha | .completed_at_utc=$completed
	 | .captures.remote_webroot_listing=$webroot | .captures.remote_plugins_listing=$plugins' \
	"${preflight_manifest}" > "${preflight_manifest}.next"
mv -- "${preflight_manifest}.next" "${preflight_manifest}"; write_sidecar "${preflight_manifest}"
preflight_verified_receipt="${evidence_dir}/preflight-verified-receipt.json"
jq --arg handoff_sha "${long_handoff_sha}" --arg manifest_sha "$(sha256_file "${preflight_manifest}")" \
	--arg verified "${long_preflight_verified}" \
	'.handoff_sha256=$handoff_sha | .manifest_sha256=$manifest_sha | .verified_at_utc=$verified' \
	"${preflight_verified_receipt}" > "${preflight_verified_receipt}.next"
mv -- "${preflight_verified_receipt}.next" "${preflight_verified_receipt}"
write_sidecar "${preflight_verified_receipt}"

operation_started="${long_started}"; operation_completed="${long_completed}"
make_operation candidate "${business_candidate_inventory}" "${candidate_inventory}"
verify_witness_bundle "${handoff}" "${evidence_dir}" candidate >/dev/null

# 901 s desde el receipt preflight es fuera de contrato aunque el gate aún tenga
# sólo 922 s: el rechazo debe atribuirse al límite preflight, no al gate.
operation_started="$(iso_add "${long_preflight_verified}" 901)"
operation_completed="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
make_operation candidate "${business_candidate_inventory}" "${candidate_inventory}"
if (verify_witness_bundle "${handoff}" "${evidence_dir}" candidate) >/dev/null 2>&1; then
	die 'evidencia aceptó operación iniciada 901 s después del preflight'
fi

# Deja un witness candidato válido, largo y ligado al handoff final del fixture.
operation_started="${long_started}"; operation_completed="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
make_operation candidate "${business_candidate_inventory}" "${candidate_inventory}"
verify_witness_bundle "${handoff}" "${evidence_dir}" candidate >/dev/null

make_operation rollback "${business_preimage_inventory}" "${preimage_inventory}"
verify_witness_bundle "${handoff}" "${evidence_dir}" rollback >/dev/null

[[ -f "${evidence_dir}/candidate-verified-receipt.json" && -f "${evidence_dir}/rollback-verified-receipt.json" ]] || die 'faltan receipts verificados'
printf 'PASS: rail v3 integrado; recovery Theme/IA y 37 bindings exactos, par con midpoints inactivos, caches, evidencia y rollback\n'
