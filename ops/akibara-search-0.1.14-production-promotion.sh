#!/usr/bin/env bash

# Carril local/MCP del par indivisible Business 1.99.16 + Search 0.1.14.
# Este shell no contiene transporte remoto: valida contratos y evidencia, corre
# gates aislados y prepara argumentos exactos para conectores/UI oficiales.

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "${script_dir}/.." && pwd -P)"
contract_file="${script_dir}/contracts/akibara-search-0.1.14.json"
business_contract_file="${script_dir}/contracts/akibara-business-1.99.16.json"
theme_contract_file="${script_dir}/contracts/akibara-gp-2.1.70.json"
search_packager="${repo_root}/tools/akibara-releases/package-search-0.1.14-production.sh"
business_packager="${repo_root}/tools/akibara-releases/package-business-1.99.16-production.sh"
package_test="${repo_root}/tools/akibara-releases/test-search-0.1.14-production-release.sh"
business_package_test="${repo_root}/tools/akibara-releases/test-business-1.99.16-production-release.sh"
promotion_contract="${script_dir}/test-akibara-search-0.1.14-production-promotion.sh"
theme_promotion_rail="${script_dir}/akibara-theme-2.1.70-production-promotion.sh"
theme_promotion_contract="${script_dir}/test-akibara-theme-2.1.70-production-promotion.sh"
recovery_rail="${repo_root}/tools/akibara-releases/search-0.1.14-recovery-cycle.sh"
recovery_static_test="${repo_root}/tools/akibara-releases/test-search-0.1.14-recovery-cycle.sh"
theme_release_test="${repo_root}/tools/akibara-releases/test-theme-2.1.70-production-release.sh"
runtime_packager="${repo_root}/tools/akibara-releases/package-runtime.sh"
theme_phpunit_config="${repo_root}/tools/akibara-gp/phpunit.xml.dist"
ai_audit_engine="${repo_root}/tools/akibara-ai-discoverability-audit.mjs"
ai_audit_test="${repo_root}/tools/test-ai-discoverability-audit.mjs"
ai_audit_wrapper="${repo_root}/tools/akibara-ai-discoverability-audit.sh"
ai_audit_policy="${repo_root}/tools/lib/ai-discoverability-policy.mjs"
ai_audit_runbook="${script_dir}/akibara-ai-discoverability-audit-runbook.md"
business_phpunit="${repo_root}/tools/akibara-business/vendor/bin/phpunit"
search_phpunit_config="${repo_root}/tools/akibara-search/phpunit.xml.dist"
pair_phpunit_config="${repo_root}/tools/akibara-search-business/phpunit.xml.dist"
business_phpunit_config="${repo_root}/tools/akibara-business/phpunit.xml.dist"
local_state_root="${repo_root}/.local-backups/search-0.1.14-production"
recovery_state_root="${repo_root}/.local-backups/search-0.1.14-recovery"

release_id='akibara-search-0.1.14'
release_digest=''
contract_sha=''
business_contract_sha=''
theme_contract_sha=''
production_domain=''
cloudflare_zone_id=''
cloudflare_zone_name=''
cloudflare_account_id=''
hostinger_username=''
local_gate_max_age=''
preflight_max_age=''
witness_max_age=''
recovery_expected_run_id=''
recovery_expected_state_sha=''
isolated_php_image=''

candidate_archive=''
candidate_inventory=''
candidate_archive_sha=''
candidate_inventory_sha=''
preimage_archive=''
preimage_inventory=''
preimage_archive_sha=''
preimage_inventory_sha=''
business_candidate_archive=''
business_candidate_inventory=''
business_candidate_archive_sha=''
business_candidate_inventory_sha=''
business_preimage_archive=''
business_preimage_inventory=''
business_preimage_archive_sha=''
business_preimage_inventory_sha=''

theme_candidate_archive=''
theme_candidate_inventory=''
theme_candidate_sidecar=''
theme_candidate_archive_sha=''
theme_candidate_inventory_sha=''
theme_candidate_sidecar_sha=''
theme_preimage_archive=''
theme_preimage_inventory=''
theme_preimage_sidecar=''
theme_preimage_archive_sha=''
theme_preimage_inventory_sha=''
theme_preimage_sidecar_sha=''

usage() {
	cat <<'EOF'
Uso:
  ops/akibara-search-0.1.14-production-promotion.sh verify-artifacts
  ops/akibara-search-0.1.14-production-promotion.sh local-gate
  ops/akibara-search-0.1.14-production-promotion.sh plan --config ARCHIVO_LOCAL
  ops/akibara-search-0.1.14-production-promotion.sh handoff --config ARCHIVO_LOCAL
  ops/akibara-search-0.1.14-production-promotion.sh verify-handoff --handoff JSON
  ops/akibara-search-0.1.14-production-promotion.sh verify-preflight \
    --handoff JSON --evidence-dir DIRECTORIO
  ops/akibara-search-0.1.14-production-promotion.sh verify-witnesses \
    --handoff JSON --evidence-dir DIRECTORIO --purpose candidate|rollback

La configuración privada contiene sólo HOSTINGER_DOMAIN, HOSTINGER_USERNAME y
CLOUDFLARE_ACCOUNT_ID y debe tener modo 0600. El shell nunca despliega, cambia
plugins, activa mantenimiento ni purga: entrega argumentos exactos para UI/MCP.
EOF
}

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
note() { printf '%s\n' "$*"; }
sha256_file() { shasum -a 256 "$1" | awk '{print $1}'; }

local_file_mode() {
	if stat -f '%Lp' "$1" >/dev/null 2>&1; then stat -f '%Lp' "$1"; else stat -c '%a' "$1"; fi
}

assert_no_symlink_ancestors() {
	python3 - "$1" <<'PY'
import os, pathlib, stat, sys
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

verify_regular_file() {
	[[ -f "$1" && ! -L "$1" && -s "$1" ]] || die "Archivo ausente, vacío o inseguro: $1"
}

repo_path() {
	local relative="$1" parent resolved
	[[ -n "${relative}" && "${relative}" != /* && "${relative}" != *'..'* \
		&& "${relative}" != *'//'* && "${relative}" != *'/./'* ]] \
		|| die "Ruta contractual insegura: ${relative}"
	parent="${repo_root}/$(dirname -- "${relative}")"
	assert_no_symlink_ancestors "${parent}" || die "Padre contractual simbólico: ${relative}"
	[[ -d "${parent}" && ! -L "${parent}" ]] || die "Padre contractual ausente: ${relative}"
	resolved="$(cd -- "${parent}" && printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${relative}")")"
	[[ "${resolved}" == "${repo_root}/"* ]] || die "Ruta fuera del repositorio: ${relative}"
	printf '%s\n' "${resolved}"
}

verify_sidecar() {
	local payload="$1" expected_name="${2:-$(basename -- "$1")}" sidecar="${1}.sha256" expected
	verify_regular_file "${sidecar}"
	[[ "$(local_file_mode "${payload}")" == 600 && "$(local_file_mode "${sidecar}")" == 600 ]] \
		|| die "Payload y sidecar deben tener modo 0600: ${payload}"
	expected="$(sha256_file "${payload}")  ${expected_name}"
	[[ "$(wc -l < "${sidecar}" | tr -d ' ')" == 1 && "$(sed -n '1p' "${sidecar}")" == "${expected}" ]] \
		|| die "Sidecar divergente: ${sidecar}"
}

verify_zip_sidecar() {
	local archive="$1" expected_sha="$2" sidecar="${archive}.sha256"
	verify_regular_file "${sidecar}"
	[[ "$(wc -l < "${sidecar}" | tr -d ' ')" == 1 \
		&& "$(sed -n '1p' "${sidecar}")" == "${expected_sha}  $(basename -- "${archive}")" ]] \
		|| die "Sidecar ZIP divergente: ${sidecar}"
	( cd -- "$(dirname -- "${archive}")" && shasum -a 256 -c "$(basename -- "${sidecar}")" >/dev/null ) \
		|| die "Sidecar ZIP inválido: ${sidecar}"
}

verify_archive_inventory() {
	python3 - "$1" "$2" "$3" <<'PY'
import hashlib, pathlib, re, stat, sys, zipfile
archive, inventory, root = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]), sys.argv[3]
row = re.compile(r"^([a-f0-9]{64})  (" + re.escape(root) + r"/[A-Za-z0-9._/-]+)$")
expected, ordered = {}, []
for line in inventory.read_text(encoding="utf-8").splitlines():
    match = row.fullmatch(line)
    if match is None or match.group(2) in expected:
        raise SystemExit("inventario inválido o duplicado")
    name = match.group(2)
    if any(part in ("", ".", "..") for part in pathlib.PurePosixPath(name).parts):
        raise SystemExit("ruta de inventario insegura")
    expected[name], ordered = match.group(1), ordered + [name]
if not expected or ordered != sorted(ordered):
    raise SystemExit("inventario vacío o no canónico")
files, seen = {}, set()
with zipfile.ZipFile(archive) as bundle:
    for info in bundle.infolist():
        name = info.filename
        if not name or "\\" in name or name.startswith("/") or "\x00" in name:
            raise SystemExit("entrada ZIP insegura")
        parts = pathlib.PurePosixPath(name.rstrip("/")).parts
        if not parts or parts[0] != root or any(part in ("", ".", "..") for part in parts):
            raise SystemExit("entrada ZIP fuera del slug")
        if name in seen:
            raise SystemExit("entrada ZIP duplicada")
        seen.add(name)
        kind = stat.S_IFMT((info.external_attr >> 16) & 0xFFFF)
        if info.is_dir():
            if kind not in (0, stat.S_IFDIR): raise SystemExit("directorio ZIP especial")
            continue
        if kind not in (0, stat.S_IFREG): raise SystemExit("entrada ZIP no regular")
        files[name] = hashlib.sha256(bundle.read(info)).hexdigest()
if files != expected:
    raise SystemExit("ZIP e inventario no son idénticos")
PY
}

verify_exact_inventory_tree() {
	python3 - "$1" "$2" "$3" <<'PY'
import hashlib, os, pathlib, re, stat, sys
parent, inventory, root_name = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]), sys.argv[3]
row = re.compile(r"^([a-f0-9]{64})  (" + re.escape(root_name) + r"/[A-Za-z0-9._/-]+)$")
expected = {}
for line in inventory.read_text(encoding="utf-8").splitlines():
    match = row.fullmatch(line)
    if match is None or match.group(2) in expected: raise SystemExit("inventario inválido")
    expected[match.group(2)] = match.group(1)
root = parent / root_name
if not root.is_dir() or root.is_symlink(): raise SystemExit("raíz extraída insegura")
actual = {}
for current, dirs, files in os.walk(root, followlinks=False):
    base = pathlib.Path(current)
    for name in dirs + files:
        mode = os.lstat(base / name).st_mode
        if stat.S_ISLNK(mode) or not (stat.S_ISDIR(mode) or stat.S_ISREG(mode)):
            raise SystemExit("entrada extraída especial")
    for name in files:
        path = base / name
        actual[path.relative_to(parent).as_posix()] = hashlib.sha256(path.read_bytes()).hexdigest()
if actual != expected: raise SystemExit("árbol extraído e inventario divergen")
PY
}

validate_contract_base() {
	verify_regular_file "${contract_file}"
	verify_regular_file "${business_contract_file}"
	verify_regular_file "${theme_contract_file}"
	verify_regular_file "${theme_promotion_rail}"
	verify_regular_file "${theme_promotion_contract}"
	for integrated_file in "${ai_audit_engine}" "${ai_audit_test}" "${ai_audit_wrapper}" "${ai_audit_policy}" "${ai_audit_runbook}"; do
		verify_regular_file "${integrated_file}"
	done
	jq -e \
		--arg theme_contract_sha "$(sha256_file "${theme_contract_file}")" \
		--arg ai_engine_sha "$(sha256_file "${ai_audit_engine}")" \
		--arg ai_test_sha "$(sha256_file "${ai_audit_test}")" \
		--arg ai_wrapper_sha "$(sha256_file "${ai_audit_wrapper}")" \
		--arg ai_policy_sha "$(sha256_file "${ai_audit_policy}")" \
		--arg ai_runbook_sha "$(sha256_file "${ai_audit_runbook}")" \
		--slurpfile theme "${theme_contract_file}" '
      .schema_version == 2 and .contract == "akibara-search-production-release"
      and .release_id == "akibara-search-0.1.14"
      and (.seal_state == "draft" or .seal_state == "sealed")
      and .promotion_sequence == ["docker-local-paired","production-ui-paired"]
      and .seal_requirements == {
        candidate_artifact_hashes:true,candidate_inventory_hashes:true,
        candidate_runtime_file_counts:true,runtime_delta_arrays:true,source_inputs:true,
        paired_release_definition:true,recovery_code_hashes:true,pinned_recovery_images:true}
      and .promotion_requirements == {
        contracts_sealed:true,paired_contract_sha256_match:true,
        local_gate_receipt:{schema_version:3,
          kind:"akibara-business-search-paired-local-gate-receipt",max_age_seconds:1800,
          sidecar_required:true,file_mode:"0600"},
        recovery_receipt:{schema:"akibara-search-recovery-cycle-v2",result:"PASS",
          fresh_restore_cycles:2,http_smoke_cycles:2,integrated_mariadb_cycles:2,
          sidecar_required:true,file_mode:"0600"},
        production_preflight_verified_receipt_required:true,
        paired_handoff:{schema_version:3,kind:"akibara-business-search-paired-mcp-handoff",
          exact_pair_only:true,search_inactive_at_mixed_midpoints:true},
        cache_order:["litespeed-ui","hostinger-mcp","cloudflare-mcp"],
        candidate_or_rollback_verified_receipt_required:true,
        graphify_after_verified_promotion_only:true}
      and .exclusive_targets == [
        "public_html/wp-content/plugins/akibara-business",
        "public_html/wp-content/plugins/akibara-search"]
      and (.components | keys) == ["search"]
      and .components.search.slug == "akibara-search"
      and .components.search.preimage.version == "0.1.12"
      and .components.search.candidate.version == "0.1.14"
      and .paired_release.required == true
      and .paired_release.business_contract == "ops/contracts/akibara-business-1.99.16.json"
      and .paired_release.candidate == {business_version:"1.99.16",search_version:"0.1.14"}
      and .paired_release.rollback == {business_version:"1.99.10",search_version:"0.1.12"}
      and .paired_release.compatibility_policy == "exact-pair-only"
      and .paired_release.search_must_be_inactive_during_component_switch == true
	  and .integrated_theme_binding == {
	    required:true,theme_contract_path:"ops/contracts/akibara-gp-2.1.70.json",
	    theme_contract_sha256:$theme_contract_sha,theme_release_id:"akibara-gp-2.1.70",
	    theme_version:"2.1.70",theme_archive_sha256:$theme[0].component.candidate.archive_sha256,
	    theme_inventory_sha256:$theme[0].component.candidate.inventory_sha256,
	    theme_checksum_sidecar_sha256:$theme[0].component.candidate.checksum_sidecar_sha256,
	    theme_runtime_files:103,theme_rollback_version:"2.1.69",
	    theme_rollback_archive_sha256:$theme[0].component.preimage.archive_sha256,
	    theme_rollback_inventory_sha256:$theme[0].component.preimage.inventory_sha256,
	    theme_rollback_checksum_sidecar_sha256:$theme[0].component.preimage.checksum_sidecar_sha256,
	    theme_rollback_runtime_files:103,candidate_overlay_required:true,
	    http_smoke_active_theme_required:true,node_tests_per_pass:31,
	    phpunit_tests_per_pass:18,phpunit_assertions_per_pass:52,required_passes:2}
	  and .integrated_ai_audit == {
	    required:true,schema_version:3,engine_path:"tools/akibara-ai-discoverability-audit.mjs",
	    engine_sha256:$ai_engine_sha,test_path:"tools/test-ai-discoverability-audit.mjs",
	    test_sha256:$ai_test_sha,wrapper_path:"tools/akibara-ai-discoverability-audit.sh",
	    wrapper_sha256:$ai_wrapper_sha,policy_path:"tools/lib/ai-discoverability-policy.mjs",
	    policy_sha256:$ai_policy_sha,runbook_path:"ops/akibara-ai-discoverability-audit-runbook.md",
	    runbook_sha256:$ai_runbook_sha,
	    node_image:"node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b",
	    base_url:"https://akibara.cl/",fetch_scope:"docker-internal-only",
	    redirect_policy:"manual_fail_closed",network_internal:true,published_ports:0,
	    external_network_allowed:false,required_per_fresh_restore:true,required_passes:2}
      and .production_invariants.protected_components == [
        {kind:"plugin",slug:"akibara-cart-checkout-blocks"},
        {kind:"plugin",slug:"bluex-for-woocommerce"},
        {kind:"theme",slug:"akibara-gp"}]
	      and .production_invariants.runtime_outside_pair_must_remain_byte_identical == true
	      and .production_invariants.search_protected_persistent_surfaces_must_match_phase_contract_exactly == true
	      and .production_invariants.search_derived_price_pair_phase_transition_must_match_exactly == true
	      and .production_invariants.recovery_rollback.protected_surfaces_phase_exact == true
	      and .production_invariants.recovery_rollback.derived_price_pair_transition == {
        protected_fingerprint_excluded:true,
        options:["akb_search_precio_buckets","akb_search_precio_mode"],
	        normalization_source:"Akibara\\Search\\PriceBuckets::maybe_refresh_auto(false)",
	        preimage:{buckets_sha256:"bbf68fbe0a982bd89e4bc0d1c2e011779029484e88f6a0d926b8f8b8fd4fb7c3",
	          buckets_autoload:"off",mode:"absent-auto-semantics",
	          snapshot_sha256:"0df1d76c5d89f28087d4595fa4e64117ce526c39a97ccba23de7581cc9a719fc"},
	        normalized:{buckets_sha256:"bbf68fbe0a982bd89e4bc0d1c2e011779029484e88f6a0d926b8f8b8fd4fb7c3",
	          buckets_autoload:"no",mode:"auto",mode_autoload:"no",
	          snapshot_sha256:"2c75951b6f1df45cd8e2eb7fd5e0ffae9fc8586df4b160406fbb6ee903825a05"},
        preimage_checkpoints:["source-before-dump","source-after-dump","source-final","restored","after-recovery"],
        normalized_checkpoints:["first-v2","rollback-boot","rollback-v1","forward-migration",
          "forward-v2","before-http","after-http","after-integrated-mariadb"],
        v2_stored_equals_index_derivation:true,
	        rollback_v1_must_preserve_normalized_pair:true}
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
	        forward_restores_candidate_exactly:true}
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
        incomplete_final_receipt_removed_on_failure:true}
      and .components.search.packager == "tools/akibara-releases/package-runtime.sh"
      and .production_invariants.remote_backups_allowed == false
      and .production_invariants.rollback_artifacts_location == "local-only"
      and .production_invariants.graphify_during_release_allowed == false
      and .production_invariants.production_domain == "akibara.cl"
      and .production_invariants.deployment.transport ==
        "mcp__hostinger_hosting__hosting_deployWordpressPlugin"
      and .production_invariants.deployment.activation_transport == "wordpress-plugin-ui"
      and .production_invariants.deployment.search_inactive_at_every_mixed_version_midpoint == true
      and .production_invariants.deployment.components == {
        business:{slug:"akibara-business",candidate_version:"1.99.16",rollback_version:"1.99.10"},
        search:{slug:"akibara-search",candidate_version:"0.1.14",rollback_version:"0.1.12"}}
      and .production_invariants.deployment.receipt_policy.confirmed_success_profile == null
      and .production_invariants.deployment.receipt_policy.accept_only_with_exact_postconditions == [
        "wordpress-ui-search-inactive-midpoint","wordpress-active-pair-versions",
        "business-runtime-inventory","search-runtime-inventory","complete-witness-bundle"]
      and .production_invariants.hostinger_cache.transport ==
        "mcp__hostinger_hosting__hosting_clearWebsiteCacheV1"
      and .production_invariants.cloudflare.transport == "mcp__cloudflare__execute"
      and .production_invariants.cloudflare.account_id_source ==
        "private_config:CLOUDFLARE_ACCOUNT_ID"
      and .production_invariants.evidence.production_baseline_exact_pair_inventories_required == true
      and .production_invariants.evidence.production_candidate_exact_pair_inventories_required == true
      and .production_invariants.evidence.self_asserted_boolean_receipts_allowed == false
      and .quality_gates.required_passes == 2
    ' "${contract_file}" >/dev/null || die 'Contrato base Search 0.1.14 rechazado'
	jq -e '
      .schema_version == 2 and .contract == "akibara-business-production-release"
      and .release_id == "akibara-business-1.99.16"
      and (.seal_state == "draft" or .seal_state == "sealed")
      and .promotion_sequence == ["docker-local-paired","production-ui-paired"]
      and .seal_requirements == {
        candidate_artifact_hashes:true,candidate_inventory_hashes:true,
        candidate_runtime_file_counts:true,runtime_delta_arrays:true,source_inputs:true,
        paired_release_definition:true,recovery_code_hashes:true,pinned_recovery_images:true}
      and .promotion_requirements == {
        contracts_sealed:true,paired_contract_sha256_match:true,
        local_gate_receipt:{schema_version:3,
          kind:"akibara-business-search-paired-local-gate-receipt",max_age_seconds:1800,
          sidecar_required:true,file_mode:"0600"},
        recovery_receipt:{schema:"akibara-search-recovery-cycle-v2",result:"PASS",
          fresh_restore_cycles:2,http_smoke_cycles:2,integrated_mariadb_cycles:2,
          sidecar_required:true,file_mode:"0600"},
        production_preflight_verified_receipt_required:true,
        paired_handoff:{schema_version:3,kind:"akibara-business-search-paired-mcp-handoff",
          exact_pair_only:true,search_inactive_at_mixed_midpoints:true},
        cache_order:["litespeed-ui","hostinger-mcp","cloudflare-mcp"],
        candidate_or_rollback_verified_receipt_required:true,
        graphify_after_verified_promotion_only:true}
      and (.components | keys) == ["business"]
      and .components.business.slug == "akibara-business"
      and .components.business.preimage.version == "1.99.10"
      and .components.business.candidate.version == "1.99.16"
      and .paired_release.required == true
      and .paired_release.search_contract == "ops/contracts/akibara-search-0.1.14.json"
      and .paired_release.candidate == {business_version:"1.99.16",search_version:"0.1.14"}
      and .paired_release.rollback == {business_version:"1.99.10",search_version:"0.1.12"}
      and .paired_release.compatibility_policy == "exact-pair-only"
      and .paired_release.search_must_be_inactive_during_component_switch == true
      and .production_invariants.protected_components == [
        {kind:"plugin",slug:"akibara-cart-checkout-blocks"},
        {kind:"plugin",slug:"bluex-for-woocommerce"},
        {kind:"theme",slug:"akibara-gp"}]
      and .production_invariants.runtime_outside_pair_must_remain_byte_identical == true
      and .production_invariants.remote_backups_allowed == false
      and .production_invariants.rollback_artifacts_location == "local-only"
      and .production_invariants.graphify_during_release_allowed == false
      and .quality_gates.required_passes == 2
	' "${business_contract_file}" >/dev/null || die 'Contrato base Business 1.99.16 rechazado'
	jq -e \
		--arg runtime_packager_sha "$(sha256_file "${runtime_packager}")" \
		--arg theme_test_sha "$(sha256_file "${theme_release_test}")" \
		--arg theme_promotion_rail_sha "$(sha256_file "${theme_promotion_rail}")" \
		--arg theme_promotion_test_sha "$(sha256_file "${theme_promotion_contract}")" \
		--arg promotion_library_sha "$(sha256_file "${script_dir}/akibara-search-0.1.14-production-promotion.sh")" \
		--arg phpunit_config_sha "$(sha256_file "${theme_phpunit_config}")" '
	  .schema_version == 1 and .contract == "akibara-gp-production-release"
	  and .release_id == "akibara-gp-2.1.70" and .seal_state == "sealed"
	  and .promotion_sequence == ["docker-local-integrated","production-ui-integrated"]
	  and .exclusive_targets == ["public_html/wp-content/themes/akibara-gp"]
	  and .component.kind == "theme" and .component.slug == "akibara-gp"
	  and .component.version == "2.1.70"
	  and .component.preimage.version == "2.1.69" and .component.preimage.runtime_files == 103
	  and .component.candidate.version == "2.1.70" and .component.candidate.runtime_files == 103
	  and .component.packager.path == "tools/akibara-releases/package-runtime.sh"
	  and .component.packager.sha256 == $runtime_packager_sha
	  and .quality_gates.static_contract.sha256 == $theme_test_sha
	  and .quality_gates.static_contract.negative_mutations_required == 10
	  and .quality_gates.node_theme.expected_tests == 31
	  and .quality_gates.phpunit_theme.config_sha256 == $phpunit_config_sha
	  and .quality_gates.phpunit_theme.expected_tests == 18
	  and .quality_gates.phpunit_theme.expected_assertions == 52
	  and .quality_gates.production_handoff == {
	    rail:{path:"ops/akibara-theme-2.1.70-production-promotion.sh",sha256:$theme_promotion_rail_sha},
	    test:{path:"ops/test-akibara-theme-2.1.70-production-promotion.sh",
	      sha256:$theme_promotion_test_sha,
	      command:"bash ops/test-akibara-theme-2.1.70-production-promotion.sh"},
	    library:{path:"ops/akibara-search-0.1.14-production-promotion.sh",
	      sha256:$promotion_library_sha},required_passes:2}
	  and .integrated_release_binding.required == true
	  and .production_invariants.deployment_transport == "mcp__hostinger_hosting__hosting_deployWordpressTheme"
	  and .production_invariants.remote_backups_allowed == false
	  and .production_invariants.rollback_artifacts_location == "local-only"
	  and .production_invariants.graphify_during_release_allowed == false
	' "${theme_contract_file}" >/dev/null || die 'Contrato base Theme 2.1.70 rechazado'
}

load_contract() {
	validate_contract_base
	contract_sha="$(sha256_file "${contract_file}")"
	business_contract_sha="$(sha256_file "${business_contract_file}")"
	theme_contract_sha="$(sha256_file "${theme_contract_file}")"
	release_digest="$(printf '%s\n%s\n%s\n' "${contract_sha}" "${business_contract_sha}" "${theme_contract_sha}" | shasum -a 256 | awk '{print $1}')"
	candidate_archive="$(repo_path "$(jq -r '.components.search.candidate.archive' "${contract_file}")")"
	candidate_inventory="$(repo_path "$(jq -r '.components.search.candidate.inventory' "${contract_file}")")"
	preimage_archive="$(repo_path "$(jq -r '.components.search.preimage.archive' "${contract_file}")")"
	preimage_inventory="$(repo_path "$(jq -r '.components.search.preimage.inventory' "${contract_file}")")"
	candidate_archive_sha="$(jq -r '.components.search.candidate.archive_sha256 // ""' "${contract_file}")"
	candidate_inventory_sha="$(jq -r '.components.search.candidate.inventory_sha256 // ""' "${contract_file}")"
	preimage_archive_sha="$(jq -r '.components.search.preimage.archive_sha256' "${contract_file}")"
	preimage_inventory_sha="$(jq -r '.components.search.preimage.inventory_sha256' "${contract_file}")"
	business_candidate_archive="$(repo_path "$(jq -r '.components.business.candidate.archive' "${business_contract_file}")")"
	business_candidate_inventory="$(repo_path "$(jq -r '.components.business.candidate.inventory' "${business_contract_file}")")"
	business_preimage_archive="$(repo_path "$(jq -r '.components.business.preimage.archive' "${business_contract_file}")")"
	business_preimage_inventory="$(repo_path "$(jq -r '.components.business.preimage.inventory' "${business_contract_file}")")"
	business_candidate_archive_sha="$(jq -r '.components.business.candidate.archive_sha256 // ""' "${business_contract_file}")"
	business_candidate_inventory_sha="$(jq -r '.components.business.candidate.inventory_sha256 // ""' "${business_contract_file}")"
	business_preimage_archive_sha="$(jq -r '.components.business.preimage.archive_sha256' "${business_contract_file}")"
	business_preimage_inventory_sha="$(jq -r '.components.business.preimage.inventory_sha256' "${business_contract_file}")"
	theme_candidate_archive="$(repo_path "$(jq -r '.component.candidate.archive' "${theme_contract_file}")")"
	theme_candidate_inventory="$(repo_path "$(jq -r '.component.candidate.inventory' "${theme_contract_file}")")"
	theme_candidate_sidecar="$(repo_path "$(jq -r '.component.candidate.checksum_sidecar' "${theme_contract_file}")")"
	theme_candidate_archive_sha="$(jq -r '.component.candidate.archive_sha256' "${theme_contract_file}")"
	theme_candidate_inventory_sha="$(jq -r '.component.candidate.inventory_sha256' "${theme_contract_file}")"
	theme_candidate_sidecar_sha="$(jq -r '.component.candidate.checksum_sidecar_sha256' "${theme_contract_file}")"
	theme_preimage_archive="$(repo_path "$(jq -r '.component.preimage.archive' "${theme_contract_file}")")"
	theme_preimage_inventory="$(repo_path "$(jq -r '.component.preimage.inventory' "${theme_contract_file}")")"
	theme_preimage_sidecar="$(repo_path "$(jq -r '.component.preimage.checksum_sidecar' "${theme_contract_file}")")"
	theme_preimage_archive_sha="$(jq -r '.component.preimage.archive_sha256' "${theme_contract_file}")"
	theme_preimage_inventory_sha="$(jq -r '.component.preimage.inventory_sha256' "${theme_contract_file}")"
	theme_preimage_sidecar_sha="$(jq -r '.component.preimage.checksum_sidecar_sha256' "${theme_contract_file}")"
	production_domain="$(jq -r '.production_invariants.production_domain' "${contract_file}")"
	cloudflare_zone_id="$(jq -r '.production_invariants.cloudflare.zone_id' "${contract_file}")"
	cloudflare_zone_name="$(jq -r '.production_invariants.cloudflare.zone_name' "${contract_file}")"
	local_gate_max_age="$(jq -r '.production_invariants.local_gate_max_age_seconds' "${contract_file}")"
	preflight_max_age="$(jq -r '.production_invariants.production_preflight_max_age_seconds' "${contract_file}")"
	witness_max_age="$(jq -r '.production_invariants.witness_max_age_seconds' "${contract_file}")"
	recovery_expected_run_id="$(jq -r '.production_invariants.recovery_rollback.preimage.run_id' "${contract_file}")"
	recovery_expected_state_sha="$(jq -r '.production_invariants.recovery_rollback.preimage.raw_state_sha256' "${contract_file}")"
	isolated_php_image="$(jq -r '.production_invariants.recovery_rollback.images.apache // ""' "${contract_file}")"
}

require_sealed_contract() {
	jq -e --arg business_sha "${business_contract_sha}" --arg theme_sha "${theme_contract_sha}" '
      .seal_state == "sealed"
      and (.sealed_at_utc | type == "string" and fromdateiso8601 > 0)
      and .paired_release.business_contract_sha256 == $business_sha
	  and .integrated_theme_binding.theme_contract_sha256 == $theme_sha
	  and .integrated_theme_binding.required == true
	  and .integrated_ai_audit.required == true
      and ([.components.search.candidate.archive_sha256,
        .components.search.candidate.inventory_sha256] |
        all(type == "string" and test("^[a-f0-9]{64}$")))
      and (.components.search.candidate.runtime_files | type == "number" and . > 0 and floor == .)
      and ([.components.search.modified_runtime_files,.components.search.added_runtime_files,
        .components.search.removed_runtime_files,.components.search.source_inputs] | all(type == "array"))
      and .production_invariants.live_cache_purge_order ==
        ["litespeed-ui","hostinger-mcp","cloudflare-mcp"]
      and .production_invariants.litespeed_cache == {
        required:true,transport:"wordpress-litespeed-cache-ui",action:"purge-all",
        persisted_ui_evidence_required:true,self_asserted_receipt_allowed:false}
      and .production_invariants.recovery_rollback.images.apache ==
        "wordpress@sha256:b65f6fb4d8b669974d4e8d6f204bc5a11d67f4877473ba5ee97af0efd39e3d19"
	  and .production_invariants.recovery_rollback.images.node ==
	    "node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b"
      and ([.production_invariants.recovery_rollback.rail_sha256,
        .components.search.packager_sha256,
        .production_invariants.recovery_rollback.compose_file_sha256,
        .production_invariants.recovery_rollback.packager_library_sha256,
        .production_invariants.recovery_rollback.reviewed_recovery_php_sha256,
        .production_invariants.recovery_rollback.protected_fingerprint_sql_sha256,
        .production_invariants.recovery_rollback.check_table_validator_sha256,
        .production_invariants.recovery_rollback.test_sha256,
        .production_invariants.recovery_rollback.runbook_sha256,
        .production_invariants.recovery_rollback.http_smoke.script_sha256,
        .production_invariants.recovery_rollback.integrated_mariadb.script_sha256,
        .production_invariants.recovery_rollback.local_external_indexing.guard_sha256,
		.production_invariants.recovery_rollback.local_external_indexing.isolated_test_sha256,
		.integrated_theme_binding.theme_contract_sha256,
		.integrated_theme_binding.theme_archive_sha256,
		.integrated_theme_binding.theme_inventory_sha256,
		.integrated_theme_binding.theme_checksum_sidecar_sha256,
		.integrated_theme_binding.theme_rollback_archive_sha256,
		.integrated_theme_binding.theme_rollback_inventory_sha256,
		.integrated_theme_binding.theme_rollback_checksum_sidecar_sha256,
		.integrated_ai_audit.engine_sha256,.integrated_ai_audit.test_sha256,
		.integrated_ai_audit.wrapper_sha256,.integrated_ai_audit.policy_sha256,
		.integrated_ai_audit.runbook_sha256] |
        all(type == "string" and test("^[a-f0-9]{64}$")))
    ' "${contract_file}" >/dev/null || die 'PRODUCTION_GATE=CLOSED: contrato Search no está sellado/alineado'
	jq -e '
      .seal_state == "sealed"
      and (.sealed_at_utc | type == "string" and fromdateiso8601 > 0)
      and ([.components.business.candidate.archive_sha256,
        .components.business.candidate.inventory_sha256] |
        all(type == "string" and test("^[a-f0-9]{64}$")))
      and (.components.business.candidate.runtime_files | type == "number" and . > 0 and floor == .)
      and ([.components.business.modified_runtime_files,.components.business.added_runtime_files,
        .components.business.removed_runtime_files,.components.business.source_inputs] | all(type == "array"))
      and .production_invariants.live_cache_purge_order ==
        ["litespeed-ui","hostinger-mcp","cloudflare-mcp"]
      and .production_invariants.litespeed_cache == {
        required:true,transport:"wordpress-litespeed-cache-ui",action:"purge-all",
        persisted_ui_evidence_required:true,self_asserted_receipt_allowed:false}
    ' "${business_contract_file}" >/dev/null || die 'PRODUCTION_GATE=CLOSED: contrato Business no está sellado/alineado'
}

load_config() {
	local config="$1" line key value seen_domain=0 seen_username=0 seen_account=0
	verify_regular_file "${config}"
	assert_no_symlink_ancestors "${config}" || die 'Configuración bajo padre simbólico'
	[[ "$(local_file_mode "${config}")" == 600 ]] || die 'Configuración debe tener modo 0600'
	hostinger_username=''; cloudflare_account_id=''
	while IFS= read -r line || [[ -n "${line}" ]]; do
		[[ -z "${line}" || "${line}" == \#* ]] && continue
		[[ "${line}" =~ ^([A-Z_]+)=([A-Za-z0-9._-]+)$ ]] || die 'Configuración inválida/ejecutable'
		key="${BASH_REMATCH[1]}"; value="${BASH_REMATCH[2]}"
		case "${key}" in
			HOSTINGER_DOMAIN) ((seen_domain++ == 0)) || die 'HOSTINGER_DOMAIN duplicado'; [[ "${value}" == "${production_domain}" ]] || die 'Dominio divergente' ;;
			HOSTINGER_USERNAME) ((seen_username++ == 0)) || die 'HOSTINGER_USERNAME duplicado'; hostinger_username="${value}" ;;
			CLOUDFLARE_ACCOUNT_ID) ((seen_account++ == 0)) || die 'CLOUDFLARE_ACCOUNT_ID duplicado'; [[ "${value}" =~ ^[a-f0-9]{32}$ ]] || die 'Account ID inválido'; cloudflare_account_id="${value}" ;;
			*) die "Clave no permitida: ${key}" ;;
		esac
	done < "${config}"
	((seen_domain == 1 && seen_username == 1 && seen_account == 1)) || die 'Configuración incompleta'
}

suite_digest() {
	python3 - "${repo_root}" <<'PY'
import hashlib, pathlib, sys
root = pathlib.Path(sys.argv[1]).resolve()
fixed = [
 "ops/contracts/akibara-search-0.1.14.json", "ops/contracts/akibara-business-1.99.16.json",
	"ops/contracts/akibara-gp-2.1.70.json",
 "ops/akibara-search-0.1.14-production-promotion.sh",
 "ops/test-akibara-search-0.1.14-production-promotion.sh",
 "ops/akibara-theme-2.1.70-production-promotion.sh",
 "ops/test-akibara-theme-2.1.70-production-promotion.sh",
 "ops/akibara-search-0.1.14-production-runbook.md",
 "ops/akibara-search-0.1.14-recovery-runbook.md",
	"ops/akibara-ai-discoverability-audit-runbook.md",
 "tools/akibara-releases/package-search-0.1.14-production.sh",
 "tools/akibara-releases/package-business-1.99.16-production.sh",
 "tools/akibara-releases/test-search-0.1.14-production-release.sh",
 "tools/akibara-releases/test-business-1.99.16-production-release.sh",
 "tools/akibara-releases/package-runtime.sh", "tools/akibara-releases/test-packages.sh",
 "tools/akibara-releases/search-0.1.14-recovery-cycle.sh",
 "tools/akibara-releases/test-search-0.1.14-recovery-cycle.sh",
	"tools/akibara-releases/test-theme-2.1.70-production-release.sh",
 "tools/akibara-releases/search-0.1.14-recovery-proof.php",
 "tools/akibara-releases/search-0.1.14-protected-fingerprint.sql",
 "tools/akibara-releases/search-0.1.14-check-table.awk",
 "tools/akibara-releases/search-0.1.14-http-smoke.php",
 "tools/akibara-search/phpunit.xml.dist", "tools/akibara-search-business/phpunit.xml.dist",
 "tools/akibara-business/phpunit.xml.dist", "tools/akibara-business/composer.lock",
 "public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php",
 "public_html/wp-content/mu-plugins/akibara-local-external-indexing-guard.php",
 "tools/test-local-external-indexing-guard.php",
	"tools/akibara-gp/phpunit.xml.dist",
	"tools/akibara-ai-discoverability-audit.mjs",
	"tools/test-ai-discoverability-audit.mjs",
	"tools/akibara-ai-discoverability-audit.sh",
	"tools/lib/ai-discoverability-policy.mjs",
]
paths = [root / item for item in fixed]
for pattern in ("tools/akibara-releases/test-business-1.99.16*.sh",):
    paths.extend(root.glob(pattern))
for tree in ("public_html/wp-content/plugins/akibara-search/tests",
             "public_html/wp-content/plugins/akibara-business/tests",
			 "public_html/wp-content/themes/akibara-gp/tests",
             "tools/akibara-search-business", "tools/akibara-business/tests",
             "tools/akibara-business/vendor"):
    paths.extend(p for p in (root / tree).rglob("*") if p.is_file())
paths = sorted(set(paths), key=lambda p: p.relative_to(root).as_posix())
digest = hashlib.sha256()
for path in paths:
    if not path.is_file() or path.is_symlink(): raise SystemExit(f"input gate inseguro: {path}")
    rel, data = path.relative_to(root).as_posix().encode(), path.read_bytes()
    digest.update(len(rel).to_bytes(8,"big") + rel + len(data).to_bytes(8,"big") + data)
print(digest.hexdigest())
PY
}

receipt_path() { printf '%s/gates/%s.json\n' "${local_state_root}" "${release_digest}"; }

verify_contract_artifact_hashes() {
	local key path relative expected actual
	while IFS=$'\t' read -r key path expected; do
		verify_regular_file "${path}"
		actual="$(sha256_file "${path}")"
		[[ "${actual}" == "${expected}" ]] || die "Hash contractual recovery divergente: ${key}"
	done < <(jq -r --arg root "${repo_root}" '
      .production_invariants.recovery_rollback as $r |
	      [
	       ["theme_contract",($root+"/"+.integrated_theme_binding.theme_contract_path),.integrated_theme_binding.theme_contract_sha256],
	       ["ai_audit_engine",($root+"/"+.integrated_ai_audit.engine_path),.integrated_ai_audit.engine_sha256],
	       ["ai_audit_test",($root+"/"+.integrated_ai_audit.test_path),.integrated_ai_audit.test_sha256],
	       ["ai_audit_wrapper",($root+"/"+.integrated_ai_audit.wrapper_path),.integrated_ai_audit.wrapper_sha256],
	       ["ai_audit_policy",($root+"/"+.integrated_ai_audit.policy_path),.integrated_ai_audit.policy_sha256],
	       ["ai_audit_runbook",($root+"/"+.integrated_ai_audit.runbook_path),.integrated_ai_audit.runbook_sha256],
	       ["compose",($root+"/"+$r.compose_file),$r.compose_file_sha256],
	       ["packager_library",($root+"/"+$r.packager_library),$r.packager_library_sha256],
	       ["runtime_packager",($root+"/"+.components.search.packager),.components.search.packager_sha256],
	       ["rail",($root+"/"+$r.rail),$r.rail_sha256],
       ["proof",($root+"/"+$r.reviewed_recovery_php),$r.reviewed_recovery_php_sha256],
       ["protected_sql",($root+"/"+$r.protected_fingerprint_sql),$r.protected_fingerprint_sql_sha256],
       ["check_table",($root+"/"+$r.check_table_validator),$r.check_table_validator_sha256],
       ["static_test",($root+"/"+$r.test_script),$r.test_sha256],
       ["runbook",($root+"/"+$r.runbook),$r.runbook_sha256],
       ["http_smoke",($root+"/"+$r.http_smoke.script),$r.http_smoke.script_sha256],
       ["integrated_mariadb",($root+"/"+$r.integrated_mariadb.script),$r.integrated_mariadb.script_sha256],
       ["local_index_guard",($root+"/"+$r.local_external_indexing.guard),$r.local_external_indexing.guard_sha256],
       ["local_index_guard_test",($root+"/"+$r.local_external_indexing.isolated_test),$r.local_external_indexing.isolated_test_sha256]
      ][] | @tsv' "${contract_file}")
	while IFS=$'\t' read -r key relative expected; do
		path="$(repo_path "${relative}")"
		verify_regular_file "${path}"
		[[ "$(sha256_file "${path}")" == "${expected}" ]] \
			|| die "Hash input Theme divergente: ${key}"
	done < <(jq -r '
	  [
	    ["theme_static",.quality_gates.static_contract.path,.quality_gates.static_contract.sha256],
	    ["theme_promotion_rail",.quality_gates.production_handoff.rail.path,.quality_gates.production_handoff.rail.sha256],
	    ["theme_promotion_test",.quality_gates.production_handoff.test.path,.quality_gates.production_handoff.test.sha256],
	    ["theme_promotion_library",.quality_gates.production_handoff.library.path,.quality_gates.production_handoff.library.sha256],
	    ["theme_phpunit_config",.quality_gates.phpunit_theme.config,.quality_gates.phpunit_theme.config_sha256],
	    (.quality_gates.node_theme.test_files[] | ["theme_node_test",.path,.sha256]),
	    (.quality_gates.phpunit_theme.test_files[] | ["theme_phpunit_test",.path,.sha256])
	  ][] | @tsv
	' "${theme_contract_file}")
}

verify_component_artifacts() {
	local slug="$1" archive="$2" inventory="$3" archive_sha="$4" inventory_sha="$5" expected_files="$6"
	for artifact in "${archive}" "${inventory}" "${archive}.sha256"; do verify_regular_file "${artifact}"; done
	[[ "$(sha256_file "${archive}")" == "${archive_sha}" ]] || die "ZIP ${slug} divergente"
	[[ "$(sha256_file "${inventory}")" == "${inventory_sha}" ]] || die "Inventario ${slug} divergente"
	[[ "$(wc -l < "${inventory}" | tr -d ' ')" == "${expected_files}" ]] || die "Conteo ${slug} divergente"
	verify_zip_sidecar "${archive}" "${archive_sha}"
	verify_archive_inventory "${archive}" "${inventory}" "${slug}" || die "ZIP/inventario ${slug} no exactos"
}

verify_theme_component_artifacts() {
	local role="$1" archive="$2" inventory="$3" sidecar="$4" archive_sha="$5" inventory_sha="$6" sidecar_sha="$7"
	[[ "${sidecar}" == "${archive}.sha256" ]] || die "Sidecar Theme ${role} usa ruta no canónica"
	verify_component_artifacts akibara-gp "${archive}" "${inventory}" "${archive_sha}" "${inventory_sha}" 103
	[[ "$(sha256_file "${sidecar}")" == "${sidecar_sha}" ]] \
		|| die "Hash sidecar Theme ${role} divergente"
}

verify_artifacts() {
	load_contract
	require_sealed_contract
	verify_regular_file "${search_packager}"; verify_regular_file "${business_packager}"
	bash "${search_packager}" --verify-only
	bash "${business_packager}" --verify-only
	verify_component_artifacts akibara-search "${candidate_archive}" "${candidate_inventory}" \
		"${candidate_archive_sha}" "${candidate_inventory_sha}" "$(jq -r '.components.search.candidate.runtime_files' "${contract_file}")"
	verify_component_artifacts akibara-search "${preimage_archive}" "${preimage_inventory}" \
		"${preimage_archive_sha}" "${preimage_inventory_sha}" "$(jq -r '.components.search.preimage.runtime_files' "${contract_file}")"
	verify_component_artifacts akibara-business "${business_candidate_archive}" "${business_candidate_inventory}" \
		"${business_candidate_archive_sha}" "${business_candidate_inventory_sha}" "$(jq -r '.components.business.candidate.runtime_files' "${business_contract_file}")"
	verify_component_artifacts akibara-business "${business_preimage_archive}" "${business_preimage_inventory}" \
		"${business_preimage_archive_sha}" "${business_preimage_inventory_sha}" "$(jq -r '.components.business.preimage.runtime_files' "${business_contract_file}")"
	verify_theme_component_artifacts candidate "${theme_candidate_archive}" "${theme_candidate_inventory}" "${theme_candidate_sidecar}" \
		"${theme_candidate_archive_sha}" "${theme_candidate_inventory_sha}" "${theme_candidate_sidecar_sha}"
	verify_theme_component_artifacts preimage "${theme_preimage_archive}" "${theme_preimage_inventory}" "${theme_preimage_sidecar}" \
		"${theme_preimage_archive_sha}" "${theme_preimage_inventory_sha}" "${theme_preimage_sidecar_sha}"
	verify_contract_artifact_hashes
	note 'PASS: artefactos exactos Search+Business y Theme 2.1.69/2.1.70 integrados'
}

run_isolated_phpunit() {
	local config="$1"
	docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,noexec,size=256m \
		--volume "${repo_root}:/workspace:ro" --workdir /workspace \
		"${isolated_php_image}" php tools/akibara-business/vendor/bin/phpunit \
		--do-not-cache-result -c "${config}"
}

verify_recovery_receipt() {
	local receipt="$1" sidecar="${1}.sha256" binding_path binding_sha backup_path backup_sha backup_sql_sha
	local binding_label binding_file
	local recovery_root_real receipt_dir receipt_dir_real receipt_run_name backup_dir backup_dir_real backup_name
	[[ "${receipt}" == /* ]] || die 'Recovery receipt no es ruta absoluta'
	assert_no_symlink_ancestors "${recovery_state_root}" || die 'Raíz recovery bajo symlink'
	[[ -d "${recovery_state_root}" && ! -L "${recovery_state_root}" ]] || die 'Raíz recovery ausente o insegura'
	recovery_root_real="$(cd -- "${recovery_state_root}" && pwd -P)"
	receipt_dir="$(dirname -- "${receipt}")"
	[[ -d "${receipt_dir}" && ! -L "${receipt_dir}" ]] || die 'Directorio de recovery receipt ausente o inseguro'
	receipt_dir_real="$(cd -- "${receipt_dir}" && pwd -P)"
	receipt_run_name="$(basename -- "${receipt_dir_real}")"
	[[ "$(basename -- "${receipt}")" == 'final-receipt.json' \
		&& "$(dirname -- "${receipt_dir_real}")" == "${recovery_root_real}" \
		&& "${receipt_run_name}" == run-* \
		&& "${receipt}" == "${receipt_dir_real}/final-receipt.json" ]] \
		|| die 'Recovery receipt fuera de hijo directo/canónico de su raíz exacta'
	assert_no_symlink_ancestors "${receipt}" || die 'Recovery receipt bajo symlink'
	verify_regular_file "${receipt}"; verify_regular_file "${sidecar}"
	verify_sidecar "${receipt}" final-receipt.json
	jq -e \
		--arg release_id "${release_id}" --arg search_contract_sha "${contract_sha}" \
		--arg business_contract_sha "${business_contract_sha}" \
		--arg theme_contract_sha "${theme_contract_sha}" \
		--arg search_candidate_archive "${candidate_archive_sha}" \
		--arg search_candidate_inventory "${candidate_inventory_sha}" \
		--arg search_rollback_archive "${preimage_archive_sha}" \
		--arg search_rollback_inventory "${preimage_inventory_sha}" \
		--arg business_candidate_archive "${business_candidate_archive_sha}" \
		--arg business_candidate_inventory "${business_candidate_inventory_sha}" \
		--arg business_rollback_archive "${business_preimage_archive_sha}" \
		--arg business_rollback_inventory "${business_preimage_inventory_sha}" \
		--arg theme_candidate_archive "${theme_candidate_archive_sha}" \
		--arg theme_candidate_inventory "${theme_candidate_inventory_sha}" \
		--arg theme_candidate_sidecar "${theme_candidate_sidecar_sha}" \
		--arg theme_rollback_archive "${theme_preimage_archive_sha}" \
		--arg theme_rollback_inventory "${theme_preimage_inventory_sha}" \
		--arg theme_rollback_sidecar "${theme_preimage_sidecar_sha}" \
		--arg expected_run_id "${recovery_expected_run_id}" \
		--arg expected_state_sha "${recovery_expected_state_sha}" \
		--arg compose_sha "$(sha256_file "${repo_root}/compose.yaml")" \
		--arg packager_sha "$(sha256_file "${repo_root}/tools/akibara-releases/package-search-0.1.14-production.sh")" \
		--arg runtime_packager_sha "$(sha256_file "${repo_root}/tools/akibara-releases/package-runtime.sh")" \
		--arg rail_sha "$(sha256_file "${recovery_rail}")" \
		--arg proof_sha "$(sha256_file "${repo_root}/tools/akibara-releases/search-0.1.14-recovery-proof.php")" \
		--arg runbook_sha "$(sha256_file "${repo_root}/ops/akibara-search-0.1.14-recovery-runbook.md")" \
		--arg static_test_sha "$(sha256_file "${recovery_static_test}")" \
		--arg http_smoke_sha "$(sha256_file "${repo_root}/tools/akibara-releases/search-0.1.14-http-smoke.php")" \
		--arg integration_sha "$(sha256_file "${repo_root}/public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php")" \
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
		--arg mariadb "$(jq -r '.production_invariants.recovery_rollback.images.mariadb' "${contract_file}")" \
		--arg wpcli "$(jq -r '.production_invariants.recovery_rollback.images.wp_cli' "${contract_file}")" \
		--arg apache "${isolated_php_image}" \
		--arg node "$(jq -r '.production_invariants.recovery_rollback.images.node' "${contract_file}")" \
		--slurpfile search "${contract_file}" --slurpfile business "${business_contract_file}" \
		--slurpfile theme "${theme_contract_file}" '
      . as $root |
	  (keys|sort)==["backup","completed_at_utc","cycles","integrated_release","main_database_mutated",
        "production_mutated","release_binding","release_id","result","schema",
        "source_database_verification"]
      and .schema == "akibara-search-recovery-cycle-v2" and .release_id == $release_id
      and .result == "PASS"
      and .production_mutated == false and .main_database_mutated == false
      and (.completed_at_utc | fromdateiso8601 > 0)
      and (.backup|keys|sort)==["archive_sha256","decompressed_sha256","location","path"]
      and .backup.location == "local-only"
      and ([.backup.archive_sha256,.backup.decompressed_sha256] |
        all(type == "string" and test("^[a-f0-9]{64}$")))
      and (.source_database_verification|keys|sort)==[
        "canonical_archive_sha256","canonical_sql_sha256","cron_main_state",
        "derived_price_pair_preimage_sha256","expected_run_id","foreign_connections","freeze_rechecked",
        "matches_initial_backup","named_locks_free","protected_sha256",
        "raw_state_sha256","result","search_schema_sha256","source_table_count",
        "wordpress_main_state"]
      and .source_database_verification.result == "PASS"
      and .source_database_verification.freeze_rechecked == true
      and .source_database_verification.wordpress_main_state == "exited"
      and .source_database_verification.cron_main_state == "exited"
      and .source_database_verification.foreign_connections == 0
      and .source_database_verification.named_locks_free == true
      and .source_database_verification.source_table_count == 91
      and .source_database_verification.expected_run_id == $expected_run_id
      and .source_database_verification.raw_state_sha256 == $expected_state_sha
      and .source_database_verification.matches_initial_backup == true
      and .source_database_verification.canonical_archive_sha256 == .backup.archive_sha256
      and .source_database_verification.canonical_sql_sha256 == .backup.decompressed_sha256
	      and ([.source_database_verification.protected_sha256,
	        .source_database_verification.derived_price_pair_preimage_sha256,
        .source_database_verification.search_schema_sha256,
        .source_database_verification.raw_state_sha256,
        .source_database_verification.canonical_archive_sha256,
	        .source_database_verification.canonical_sql_sha256] |
	        all(type == "string" and test("^[a-f0-9]{64}$")))
		      and .source_database_verification.derived_price_pair_preimage_sha256 ==
		        $search[0].production_invariants.recovery_rollback.derived_price_pair_transition.preimage.snapshot_sha256
		      and .source_database_verification.protected_sha256 ==
		        $search[0].production_invariants.recovery_rollback.business_search_feed_contract_transition.candidate.full_snapshot_sha256
      and .release_binding == {
        initial_snapshot_sha256:$root.release_binding.initial_snapshot_sha256,
        unchanged_through_final_sidecar:true,
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
              archive_sha256:$search_rollback_archive,
              inventory:$search[0].components.search.preimage.inventory,
              inventory_sha256:$search_rollback_inventory},
            candidate:{archive:$search[0].components.search.candidate.archive,
              archive_sha256:$search_candidate_archive,
              inventory:$search[0].components.search.candidate.inventory,
              inventory_sha256:$search_candidate_inventory}},
          business:{
            preimage:{archive:$business[0].components.business.preimage.archive,
              archive_sha256:$business_rollback_archive,
              inventory:$business[0].components.business.preimage.inventory,
              inventory_sha256:$business_rollback_inventory},
            candidate:{archive:$business[0].components.business.candidate.archive,
              archive_sha256:$business_candidate_archive,
              inventory:$business[0].components.business.candidate.inventory,
			  inventory_sha256:$business_candidate_inventory}},
		  theme:{
			preimage:{version:"2.1.69",archive:$theme[0].component.preimage.archive,
			  archive_sha256:$theme_rollback_archive,
			  inventory:$theme[0].component.preimage.inventory,
			  inventory_sha256:$theme_rollback_inventory,
			  checksum_sidecar:$theme[0].component.preimage.checksum_sidecar,
			  checksum_sidecar_sha256:$theme_rollback_sidecar},
			candidate:{version:"2.1.70",archive:$theme[0].component.candidate.archive,
			  archive_sha256:$theme_candidate_archive,
			  inventory:$theme[0].component.candidate.inventory,
			  inventory_sha256:$theme_candidate_inventory,
			  checksum_sidecar:$theme[0].component.candidate.checksum_sidecar,
			  checksum_sidecar_sha256:$theme_candidate_sidecar}}},
        evidence_code:{compose_sha256:$compose_sha,
          packager_library_sha256:$packager_sha,
          runtime_packager_sha256:$runtime_packager_sha,
          rail_sha256:$rail_sha,recovery_proof_sha256:$proof_sha,
          runbook_sha256:$runbook_sha,static_test_sha256:$static_test_sha,
          http_smoke_sha256:$http_smoke_sha,integrated_mariadb_sha256:$integration_sha,
          protected_fingerprint_sql_sha256:$protected_sql_sha,
          check_table_validator_sha256:$check_validator_sha,
          local_index_guard_sha256:$index_guard_sha,
		  local_index_guard_test_sha256:$index_guard_test_sha,
		  theme_release_test_sha256:$theme_test_sha,
		  theme_phpunit_config_sha256:$theme_phpunit_config_sha,
		  ai_audit_engine_sha256:$ai_engine_sha,
		  ai_audit_test_sha256:$ai_test_sha,
		  ai_audit_wrapper_sha256:$ai_wrapper_sha,
		  ai_audit_policy_sha256:$ai_policy_sha,
		  ai_audit_runbook_sha256:$ai_runbook_sha},
		images:{mariadb:$mariadb,wp_cli:$wpcli,apache:$apache,node:$node}}
      and (.release_binding.initial_snapshot_sha256 |
        type == "string" and test("^[a-f0-9]{64}$"))
	  and .integrated_release == {
		theme:{candidate_version:"2.1.70",rollback_version:"2.1.69",
		  active_candidate_verified_per_cycle:true,quality_passes:2},
		ai_discoverability:{schema_version:3,passes:2,base_url:"https://akibara.cl/",
		  fetch_scope:"docker-internal-only",redirect_policy:"manual_fail_closed",
		  external_network_dependency_allowed:false}}
      and (.cycles | type == "array" and length == 2)
      and ([.cycles[].iteration] == [1,2])
	      and ([.cycles[].derived_price_pair.normalized_sha256] | unique | length == 1)
	      and ([.cycles[]] | all(
	        (keys|sort)==["action_scheduler_stabilization","ai_discoverability","canonical_redump_sha256","derived_price_pair","ephemeral_docker_cleanup_verified",
	          "exact_pair_cycle","forward_mixed_midpoint_sha256","forward_ready_pair_sha256",
          "fresh_restore",
          "gate_v1_rollback_sha256","gate_v2_forward_sha256","gate_v2_initial_sha256",
          "http_smoke","integrated_mariadb","iteration","live_drain_forward_sha256",
	          "live_drain_initial_sha256","protected_fingerprint_sha256","protected_fingerprint_transition",
          "result","rollback_mixed_midpoint_sha256","rollback_ready_pair_sha256",
		  "search_inactive_at_mixed_and_ready_midpoints","theme_quality"]
        and .result == "PASS" and .fresh_restore == true
        and (.action_scheduler_stabilization |
          (keys|sort)==["forward","provider_inventory","rollback"]
          and (.provider_inventory |
            .schema=="akibara-action-scheduler-provider-inventory-v1"
            and .version=="3.9.3" and (.files|length)==7
            and .mount_root=="/var/www/html")
          and ([.rollback,.forward] | all(
            .schema=="akibara-action-scheduler-stabilization-v1"
            and .execution_scope=="explicit-action-id-only"
            and .provider_files==7
            and .provider_inventory_sha256=="360dd589a38e34689af7e340b680c2c209240e052d3227b0e897e21b5d50d853"
            and .final_runtime.version=="3.9.3"
            and .final_runtime.store_class=="ActionScheduler_DBStore"
            and .final_runtime.migration_status.present==true
            and .final_runtime.migration_status.value=="complete"
            and ((.actions_executed==0 and .action_id==0)
              or (.mode=="hybrid-migration" and .actions_executed==1
                and (.action_id|type=="number" and .>0)
                and .initial_runtime.store_class=="ActionScheduler_HybridStore"))
            and (.log_sha256|type=="string" and test("^[a-f0-9]{64}$")))))
        and .ephemeral_docker_cleanup_verified == true and .exact_pair_cycle == true
	        and .search_inactive_at_mixed_and_ready_midpoints == true
	        and .protected_fingerprint_sha256 == $root.source_database_verification.protected_sha256
	        and (.protected_fingerprint_transition|keys|sort)==["candidate_sha256","changed_column_only_option_value","checkpoint_sha256","forward_restores_candidate_exactly","option_id_preserved","rollback_sha256","row_count_exactly_one","search_inactive_at_artifact_midpoints","single_exact_feed_contract_row_transition","transition_exact_before_search_activation"]
	        and .protected_fingerprint_transition.candidate_sha256 ==
	          $search[0].production_invariants.recovery_rollback.business_search_feed_contract_transition.candidate.full_snapshot_sha256
	        and .protected_fingerprint_transition.rollback_sha256 ==
	          $search[0].production_invariants.recovery_rollback.business_search_feed_contract_transition.rollback.full_snapshot_sha256
	        and (.protected_fingerprint_transition.checkpoint_sha256|keys|sort)==["candidate","rollback"]
	        and (.protected_fingerprint_transition.candidate_sha256 as $candidate_protected |
	          .protected_fingerprint_transition.checkpoint_sha256.candidate | type=="array" and length==9 and all(.==$candidate_protected))
	        and (.protected_fingerprint_transition.rollback_sha256 as $rollback_protected |
	          .protected_fingerprint_transition.checkpoint_sha256.rollback | type=="array" and length==3 and all(.==$rollback_protected))
	        and .protected_fingerprint_transition.single_exact_feed_contract_row_transition == true
	        and .protected_fingerprint_transition.changed_column_only_option_value == true
	        and .protected_fingerprint_transition.option_id_preserved == true
	        and .protected_fingerprint_transition.row_count_exactly_one == true
	        and .protected_fingerprint_transition.transition_exact_before_search_activation == true
	        and .protected_fingerprint_transition.search_inactive_at_artifact_midpoints == true
	        and .protected_fingerprint_transition.forward_restores_candidate_exactly == true
        and ([.canonical_redump_sha256,.gate_v2_initial_sha256,.live_drain_initial_sha256,
          .gate_v1_rollback_sha256,.live_drain_forward_sha256,
          .gate_v2_forward_sha256,.rollback_mixed_midpoint_sha256,
          .rollback_ready_pair_sha256,.forward_mixed_midpoint_sha256,
          .forward_ready_pair_sha256] | all(type=="string" and test("^[a-f0-9]{64}$")))
        and (.derived_price_pair|keys|sort)==["checkpoint_sha256","normalized_preserved_through_rollback_forward_http_mariadb",
          "normalized_sha256","preimage_preserved_until_rebuild","preimage_sha256",
          "v2_stored_equals_index_derivation"]
	        and .derived_price_pair.preimage_sha256 ==
	          $search[0].production_invariants.recovery_rollback.derived_price_pair_transition.preimage.snapshot_sha256
	        and .derived_price_pair.normalized_sha256 ==
	          $search[0].production_invariants.recovery_rollback.derived_price_pair_transition.normalized.snapshot_sha256
        and (.derived_price_pair.checkpoint_sha256|keys|sort)==["normalized","preimage"]
	        and (.derived_price_pair.checkpoint_sha256.preimage | type=="array" and length==2 and
	          all(.==$root.source_database_verification.derived_price_pair_preimage_sha256))
	        and (.derived_price_pair.normalized_sha256 as $normalized |
	          .derived_price_pair.checkpoint_sha256.normalized | type=="array" and length==8 and all(.==$normalized))
        and .derived_price_pair.preimage_preserved_until_rebuild == true
        and .derived_price_pair.normalized_preserved_through_rollback_forward_http_mariadb == true
        and .derived_price_pair.v2_stored_equals_index_derivation == true
		and (.theme_quality|keys|sort)==["active_theme_state","ai_audit_unit",
		  "external_network_dependency_allowed","node","package_verify","pass","phpunit",
		  "receipt_sha256","result","static_contract","theme"]
		and .theme_quality.result == "PASS"
		and .theme_quality.pass == .iteration
		and .theme_quality.theme == {candidate_version:"2.1.70",rollback_version:"2.1.69",
		  candidate_inventory_sha256:$theme_candidate_inventory,
		  rollback_inventory_sha256:$theme_rollback_inventory,candidate_overlay_exact:true}
		and .theme_quality.static_contract.passes == 1
		and .theme_quality.package_verify.passes == 1
		and .theme_quality.package_verify.runtime_files == 103
		and .theme_quality.node.tests == 31 and .theme_quality.node.passes == 31
		and .theme_quality.node.failures == 0 and .theme_quality.node.image == $node
		and .theme_quality.node.network == "none"
		and .theme_quality.phpunit.tests == 18 and .theme_quality.phpunit.assertions == 52
		and .theme_quality.phpunit.failures == 0 and .theme_quality.phpunit.image == $apache
		and .theme_quality.phpunit.network == "none"
		and .theme_quality.ai_audit_unit.result == "PASS"
		and .theme_quality.ai_audit_unit.image == $node
		and .theme_quality.ai_audit_unit.network == "none"
		and .theme_quality.external_network_dependency_allowed == false
		and .theme_quality.active_theme_state == {
		  initial_log_sha256:.theme_quality.active_theme_state.initial_log_sha256,
		  before_http_log_sha256:.theme_quality.active_theme_state.before_http_log_sha256,
		  after_http_log_sha256:.theme_quality.active_theme_state.after_http_log_sha256,
		  slug:"akibara-gp",version:"2.1.70",active:true}
		and ([.theme_quality.receipt_sha256,.theme_quality.static_contract.log_sha256,
		  .theme_quality.package_verify.log_sha256,.theme_quality.node.log_sha256,
		  .theme_quality.phpunit.log_sha256,.theme_quality.ai_audit_unit.log_sha256,
		  .theme_quality.active_theme_state.initial_log_sha256,
		  .theme_quality.active_theme_state.before_http_log_sha256,
		  .theme_quality.active_theme_state.after_http_log_sha256] |
		  all(type=="string" and test("^[a-f0-9]{64}$")))
		and (.ai_discoverability|keys|sort)==["base_url","engine_sha256",
		  "external_network_dependency_allowed","fetch_scope","image","network_internal",
		  "policy_sha256","published_ports","redirect_policy","report_sha256","result",
		  "schema_version","stderr_sha256","summary"]
		and .ai_discoverability.result == "PASS" and .ai_discoverability.schema_version == 3
		and .ai_discoverability.engine_sha256 == $ai_engine_sha
		and .ai_discoverability.policy_sha256 == $ai_policy_sha
		and .ai_discoverability.image == $node and .ai_discoverability.network_internal == true
		and .ai_discoverability.published_ports == 0
		and .ai_discoverability.external_network_dependency_allowed == false
		and .ai_discoverability.base_url == "https://akibara.cl/"
		and .ai_discoverability.fetch_scope == "docker-internal-only"
		and .ai_discoverability.redirect_policy == "manual_fail_closed"
		and .ai_discoverability.summary.status == "pass"
		and .ai_discoverability.summary.exit_code == 0
		and .ai_discoverability.summary.fail == 0
		and ([.ai_discoverability.report_sha256,.ai_discoverability.stderr_sha256] |
		  all(type=="string" and test("^[a-f0-9]{64}$")))
        and (.http_smoke|keys|sort)==["checks","counts","follow_redirects","host_header",
          "http_status","image","log_sha256","network_internal","origin_scope",
          "protected_after_sha256","protected_before_sha256","published_ports",
          "readiness","receipt_schema","receipt_sha256","response_bytes",
          "response_sha256","result","schema","script_sha256","server_log_sha256"]
        and .http_smoke.schema == "akibara-search-http-smoke-v1"
        and .http_smoke.result == "PASS"
        and .http_smoke.receipt_schema == "akibara-search-http-smoke-v1"
        and .http_smoke.origin_scope == "docker-internal"
        and .http_smoke.image == $apache and .http_smoke.network_internal == true
        and .http_smoke.published_ports == 0 and .http_smoke.host_header == "localhost:8080"
        and .http_smoke.follow_redirects == false
        and (.http_smoke.readiness|keys|sort)==["attempts","final_status","response_sha256"]
        and (.http_smoke.readiness.attempts|type=="number" and .>0 and floor==.)
        and .http_smoke.readiness.final_status == 200
        and (.http_smoke.readiness.response_sha256|test("^[a-f0-9]{64}$"))
        and (.http_smoke.counts|keys|sort)==["aot","aot_phrase","atalier","atelier",
          "opm","opm_alias","opm_series","opm_volume"]
        and .http_smoke.counts.opm > 0
        and .http_smoke.counts.opm == .http_smoke.counts.opm_alias
        and .http_smoke.counts.opm == .http_smoke.counts.opm_series
        and .http_smoke.counts.opm_volume > 0
        and .http_smoke.counts.opm_volume < .http_smoke.counts.opm
        and .http_smoke.counts.atelier > 0
        and .http_smoke.counts.atelier == .http_smoke.counts.atalier
        and .http_smoke.counts.aot > 0
        and .http_smoke.counts.aot == .http_smoke.counts.aot_phrase
        and (.http_smoke.checks|keys|sort)==["aot_phrase_parity","atelier_fuzzy_parity",
          "opm_alias_series_parity","opm_titles","opm_volume_narrows","pack_one_tomo",
          "shop_manga_unit","suggest_one_piece","zero_toolbar_filter"]
        and ([.http_smoke.checks[]]|all(.==true))
        and (.http_smoke.http_status|keys|sort)==["count_aot","count_aot_phrase",
          "count_atalier","count_atelier","count_opm","count_opm_alias",
          "count_opm_series","count_opm_volume","shop_opm","shop_pack","shop_zero",
          "suggest_one_piece","suggest_opm"]
        and (.http_smoke.response_bytes|keys)==(.http_smoke.http_status|keys)
        and (.http_smoke.response_sha256|keys)==(.http_smoke.http_status|keys)
        and ([.http_smoke.http_status[]]|all(.==200))
        and ([.http_smoke.response_bytes[]]|all(type=="number" and .>0 and floor==.))
        and ([.http_smoke.response_sha256[]]|all(test("^[a-f0-9]{64}$")))
        and .http_smoke.script_sha256 == $http_smoke_sha
        and ([.http_smoke.receipt_sha256,.http_smoke.log_sha256,
          .http_smoke.server_log_sha256,.http_smoke.script_sha256,
          .http_smoke.protected_before_sha256,.http_smoke.protected_after_sha256] |
          all(type == "string" and test("^[a-f0-9]{64}$")))
        and .http_smoke.protected_before_sha256 == .http_smoke.protected_after_sha256
        and .http_smoke.protected_after_sha256 == $root.source_database_verification.protected_sha256
        and (.integrated_mariadb|keys|sort)==["baseline_log_sha256","candidate_log_sha256",
          "fixture_user_schema_limited","modes","protected_after_sha256","provider_active",
          "receipt_sha256","reconciler_zero_divergence","result","script_sha256"]
        and .integrated_mariadb.result == "PASS"
        and .integrated_mariadb.script_sha256 == $integration_sha
        and .integrated_mariadb.modes == ["baseline","candidate"]
        and .integrated_mariadb.fixture_user_schema_limited == true
        and .integrated_mariadb.provider_active == true
        and .integrated_mariadb.reconciler_zero_divergence == true
        and .integrated_mariadb.protected_after_sha256 == $root.source_database_verification.protected_sha256
        and ([.integrated_mariadb.script_sha256,.integrated_mariadb.receipt_sha256,
          .integrated_mariadb.baseline_log_sha256,.integrated_mariadb.candidate_log_sha256,
          .integrated_mariadb.protected_after_sha256] |
		all(type == "string" and test("^[a-f0-9]{64}$")))))
	' "${receipt}" >/dev/null || die 'Recovery receipt no prueba dos ciclos integrados Search+Business+Theme+IA'
	binding_path="$(dirname -- "${receipt}")/release-binding-initial.tsv"
	assert_no_symlink_ancestors "${binding_path}" || die 'Binding recovery bajo symlink'
	verify_regular_file "${binding_path}"
	[[ "$(local_file_mode "${binding_path}")" == 600 ]] || die 'Binding recovery no está 0600'
	[[ "$(sed -n '1p' "${binding_path}")" == 'AKB_SEARCH_RELEASE_BINDING_V1' ]] \
		|| die 'Binding recovery sin cabecera exacta'
	grep -qx $'END\tAKB_SEARCH_RELEASE_BINDING_V1' "${binding_path}" \
		|| die 'Binding recovery material incompleto'
	awk -F '\t' '
	  NR == 1 { if ($0 != "AKB_SEARCH_RELEASE_BINDING_V1") exit 1; next }
	  $1 == "END" { if (NR != 39 || $2 != "AKB_SEARCH_RELEASE_BINDING_V1" || NF != 2) exit 1; end = 1; next }
	  { if (end || $1 != "FILE" || NF != 3 || $2 !~ /^[a-z0-9_]+$/ ||
	        $3 !~ /^[a-f0-9]{64}$/ || seen[$2]++) exit 1; files++ }
	  END { if (NR != 39 || files != 37 || !end) exit 1 }
	' "${binding_path}" || die 'Binding recovery no contiene 37 entradas FILE únicas/exactas'
	while IFS='|' read -r binding_label binding_file; do
		grep -Fqx $'FILE\t'"${binding_label}"$'\t'"$(sha256_file "${binding_file}")" "${binding_path}" \
			|| die "Binding recovery no enlaza bytes locales: ${binding_label}"
	done <<EOF
rail|${recovery_rail}
search_contract|${contract_file}
business_contract|${business_contract_file}
theme_contract|${theme_contract_file}
compose|${repo_root}/compose.yaml
packager_library|${repo_root}/tools/akibara-releases/package-search-0.1.14-production.sh
runtime_packager|${repo_root}/tools/akibara-releases/package-runtime.sh
recovery_proof|${repo_root}/tools/akibara-releases/search-0.1.14-recovery-proof.php
http_smoke|${repo_root}/tools/akibara-releases/search-0.1.14-http-smoke.php
protected_fingerprint|${repo_root}/tools/akibara-releases/search-0.1.14-protected-fingerprint.sql
check_table_validator|${repo_root}/tools/akibara-releases/search-0.1.14-check-table.awk
static_test|${recovery_static_test}
theme_release_test|${theme_release_test}
theme_phpunit_config|${theme_phpunit_config}
ai_audit_engine|${ai_audit_engine}
ai_audit_test|${ai_audit_test}
ai_audit_wrapper|${ai_audit_wrapper}
ai_audit_policy|${ai_audit_policy}
ai_audit_runbook|${ai_audit_runbook}
runbook|${repo_root}/ops/akibara-search-0.1.14-recovery-runbook.md
local_index_guard|${repo_root}/public_html/wp-content/mu-plugins/akibara-local-external-indexing-guard.php
local_index_guard_test|${repo_root}/tools/test-local-external-indexing-guard.php
integrated_mariadb|${repo_root}/public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php
search_preimage_archive|${preimage_archive}
search_preimage_inventory|${preimage_inventory}
search_candidate_archive|${candidate_archive}
search_candidate_inventory|${candidate_inventory}
business_preimage_archive|${business_preimage_archive}
business_preimage_inventory|${business_preimage_inventory}
business_candidate_archive|${business_candidate_archive}
business_candidate_inventory|${business_candidate_inventory}
theme_preimage_archive|${theme_preimage_archive}
theme_preimage_inventory|${theme_preimage_inventory}
theme_preimage_sidecar|${theme_preimage_sidecar}
theme_candidate_archive|${theme_candidate_archive}
theme_candidate_inventory|${theme_candidate_inventory}
theme_candidate_sidecar|${theme_candidate_sidecar}
EOF
	binding_sha="$(jq -r '.release_binding.initial_snapshot_sha256' "${receipt}")"
	[[ "$(sha256_file "${binding_path}")" == "${binding_sha}" ]] \
		|| die 'Binding recovery material divergente'
	backup_path="$(jq -r '.backup.path' "${receipt}")"
	[[ "${backup_path}" == /* ]] || die 'Backup recovery no es ruta absoluta'
	backup_dir="$(dirname -- "${backup_path}")"
	[[ -d "${backup_dir}" && ! -L "${backup_dir}" ]] || die 'Directorio de backup recovery ausente o inseguro'
	backup_dir_real="$(cd -- "${backup_dir}" && pwd -P)"
	backup_name="$(basename -- "${backup_path}")"
	[[ "${backup_dir_real}" == "${recovery_root_real}" \
		&& "${backup_name}" == akibara-search-0.1.14-preimage-*.sql.gz \
		&& "${backup_path}" == "${backup_dir_real}/${backup_name}" ]] \
		|| die 'Backup enlazado por recovery fuera de raíz/nombre canónicos exactos'
	assert_no_symlink_ancestors "${backup_path}" || die 'Backup recovery bajo symlink'
	verify_regular_file "${backup_path}"
	[[ "$(local_file_mode "${backup_path}")" == 600 ]] || die 'Backup recovery no está 0600'
	backup_sha="$(jq -r '.backup.archive_sha256' "${receipt}")"
	backup_sql_sha="$(jq -r '.backup.decompressed_sha256' "${receipt}")"
	[[ "$(sha256_file "${backup_path}")" == "${backup_sha}" ]] || die 'Backup recovery comprimido divergente'
	gzip -t "${backup_path}" || die 'Backup recovery gzip inválido'
	[[ "$(gzip -cd "${backup_path}" | shasum -a 256 | awk '{print $1}')" == "${backup_sql_sha}" ]] \
		|| die 'Backup recovery SQL divergente'
}

run_local_gate() (
	local receipt sidecar partial='' gate_started pass recovery_log recovery_receipt evidence_count suite_sha
	cleanup() { if [[ -n "${partial}" ]]; then rm -f -- "${partial}"; fi; }
	trap cleanup EXIT; trap 'exit 129' HUP; trap 'exit 130' INT; trap 'exit 143' TERM
	verify_artifacts
	for tool in docker jq node python3 shasum; do command -v "${tool}" >/dev/null || die "Falta ${tool}"; done
	for path in "${package_test}" "${business_package_test}" "${promotion_contract}" "${recovery_rail}" "${recovery_static_test}" \
		"${theme_release_test}" "${theme_promotion_rail}" "${theme_promotion_contract}" \
		"${runtime_packager}" "${ai_audit_test}" \
		"${business_phpunit}" "${search_phpunit_config}" "${pair_phpunit_config}" "${business_phpunit_config}"; do
		verify_regular_file "${path}"
	done
	[[ "${isolated_php_image}" == *@sha256:* ]] || die 'Imagen PHP aislada no está pinneada por digest'
	receipt="$(receipt_path)"; sidecar="${receipt}.sha256"
	assert_no_symlink_ancestors "${local_state_root}" || die 'Raíz local insegura'
	mkdir -p -- "$(dirname -- "${receipt}")"
	chmod 0700 "${local_state_root}" "$(dirname -- "${receipt}")"
	rm -f -- "${receipt}" "${sidecar}" "${receipt}.partial"
	gate_started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	for pass in 1 2; do
		note "Gate pareado aislado — pasada ${pass}/2"
		bash "${promotion_contract}"
		bash "${package_test}"
		bash "${business_package_test}"
		bash "${search_packager}" --verify-only
		bash "${business_packager}" --verify-only
		bash "${recovery_static_test}"
		bash "${theme_release_test}"
		bash "${theme_promotion_contract}"
		bash "${runtime_packager}" theme --verify-only
		node "${ai_audit_test}"
		run_isolated_phpunit tools/akibara-search/phpunit.xml.dist
		run_isolated_phpunit tools/akibara-search-business/phpunit.xml.dist
		run_isolated_phpunit tools/akibara-business/phpunit.xml.dist
	done
	recovery_log="$(dirname -- "${receipt}")/${release_digest}.recovery.stdout"
	rm -f -- "${recovery_log}"
	bash "${recovery_rail}" run --expected-run-id "${recovery_expected_run_id}" \
		--expected-state-sha256 "${recovery_expected_state_sha}" | tee "${recovery_log}"
	chmod 0600 "${recovery_log}"
	evidence_count="$(grep -c '^Evidencia local: /' "${recovery_log}" || true)"
	[[ "${evidence_count}" == 1 ]] || die 'Recovery rail debe emitir una sola línea Evidencia local'
	recovery_receipt="$(sed -n 's/^Evidencia local: //p' "${recovery_log}")"
	verify_recovery_receipt "${recovery_receipt}"
	suite_sha="$(suite_digest)"
	partial="${receipt}.partial"
	jq -n \
		--arg release_id "${release_id}" --arg release_digest "${release_digest}" \
		--arg search_contract "${contract_sha}" --arg business_contract "${business_contract_sha}" \
		--arg theme_contract "${theme_contract_sha}" \
		--arg suite "${suite_sha}" \
		--arg sca "${candidate_archive_sha}" --arg sci "${candidate_inventory_sha}" \
		--arg sra "${preimage_archive_sha}" --arg sri "${preimage_inventory_sha}" \
		--arg bca "${business_candidate_archive_sha}" --arg bci "${business_candidate_inventory_sha}" \
		--arg bra "${business_preimage_archive_sha}" --arg bri "${business_preimage_inventory_sha}" \
		--arg tca "${theme_candidate_archive_sha}" --arg tci "${theme_candidate_inventory_sha}" --arg tcs "${theme_candidate_sidecar_sha}" \
		--arg tra "${theme_preimage_archive_sha}" --arg tri "${theme_preimage_inventory_sha}" --arg trs "${theme_preimage_sidecar_sha}" \
		--arg recovery_path "${recovery_receipt}" --arg recovery_sha "$(sha256_file "${recovery_receipt}")" \
		--arg recovery_sidecar_sha "$(sha256_file "${recovery_receipt}.sha256")" \
		--arg recovery_log_path "${recovery_log}" --arg recovery_log_sha "$(sha256_file "${recovery_log}")" \
		--arg image "${isolated_php_image}" --arg started "${gate_started}" \
		--arg completed "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '
      {schema_version:3,kind:"akibara-business-search-paired-local-gate-receipt",
       release_id:$release_id,release_digest:$release_digest,
	   contracts:{search_sha256:$search_contract,business_sha256:$business_contract,theme_sha256:$theme_contract},
       suite_sha256:$suite,
       artifacts:{
        search:{candidate:{archive_sha256:$sca,inventory_sha256:$sci},
          rollback:{archive_sha256:$sra,inventory_sha256:$sri}},
        business:{candidate:{archive_sha256:$bca,inventory_sha256:$bci},
		  rollback:{archive_sha256:$bra,inventory_sha256:$bri}},
		theme:{candidate:{archive_sha256:$tca,inventory_sha256:$tci,sidecar_sha256:$tcs},
		  rollback:{archive_sha256:$tra,inventory_sha256:$tri,sidecar_sha256:$trs}}},
       recovery:{receipt:{path:$recovery_path,sha256:$recovery_sha,
          sidecar_sha256:$recovery_sidecar_sha},stdout:{path:$recovery_log_path,sha256:$recovery_log_sha},
          isolated_php_image:$image},
       gates:{promotion_contract:{passes:2},package_release:{passes:2},business_package_release:{passes:2},
        search_component:{passes:2},business_component:{passes:2},
        search_unit:{passes:2},paired_integration:{passes:2},business_unit:{passes:2},
		theme_contract:{passes:2},theme_promotion_contract:{passes:2},
		theme_package:{passes:2},ai_audit_unit:{passes:2},
		recovery_static:{passes:2},recovery_cycle:{runs:1,fresh_restore_cycles:2,http_smoke_cycles:2,
		  theme_quality_cycles:2,ai_audit_cycles:2}},
       started_at_utc:$started,completed_at_utc:$completed}
    ' > "${partial}"
	chmod 0600 "${partial}"; mv -- "${partial}" "${receipt}"; partial=''
	( cd -- "$(dirname -- "${receipt}")" && shasum -a 256 "$(basename -- "${receipt}")" > "$(basename -- "${sidecar}")" )
	chmod 0600 "${receipt}" "${sidecar}"
	note "PASS: gate pareado local recibido en ${receipt}"
)

# La frescura se consume en el instante de cada decisión. Los validadores
# posteriores vuelven a probar esa relación histórica sin exigir que un witness
# largo mantenga frescos gate/preflight respecto del reloj de cierre.
assert_elapsed_within() {
	local earlier="$1" later="$2" max_age="$3" label="$4"
	jq -en --arg earlier "${earlier}" --arg later "${later}" --argjson max_age "${max_age}" '
      ($earlier|fromdateiso8601) as $earlier_epoch
      | ($later|fromdateiso8601) as $later_epoch
      | $earlier_epoch <= $later_epoch
        and ($later_epoch-$earlier_epoch) <= $max_age
    ' >/dev/null || die "Ventana temporal inválida: ${label}"
}

verify_local_receipt_integrity() {
	local receipt sidecar expected_suite recovery_path
	receipt="$(receipt_path)"; sidecar="${receipt}.sha256"
	assert_no_symlink_ancestors "${receipt}" || die 'Recibo local bajo symlink'
	verify_regular_file "${receipt}"; verify_regular_file "${sidecar}"; verify_sidecar "${receipt}"
	expected_suite="$(suite_digest)"
	jq -e \
		--arg release_id "${release_id}" --arg release_digest "${release_digest}" \
		--arg search_contract "${contract_sha}" --arg business_contract "${business_contract_sha}" \
		--arg theme_contract "${theme_contract_sha}" \
		--arg suite "${expected_suite}" --arg sca "${candidate_archive_sha}" --arg sci "${candidate_inventory_sha}" \
		--arg sra "${preimage_archive_sha}" --arg sri "${preimage_inventory_sha}" \
		--arg bca "${business_candidate_archive_sha}" --arg bci "${business_candidate_inventory_sha}" \
		--arg bra "${business_preimage_archive_sha}" --arg bri "${business_preimage_inventory_sha}" \
		--arg tca "${theme_candidate_archive_sha}" --arg tci "${theme_candidate_inventory_sha}" --arg tcs "${theme_candidate_sidecar_sha}" \
		--arg tra "${theme_preimage_archive_sha}" --arg tri "${theme_preimage_inventory_sha}" --arg trs "${theme_preimage_sidecar_sha}" \
		--arg image "${isolated_php_image}" '
      .schema_version == 3 and .kind == "akibara-business-search-paired-local-gate-receipt"
      and (keys|sort)==["artifacts","completed_at_utc","contracts","gates","kind",
        "recovery","release_digest","release_id","schema_version","started_at_utc",
        "suite_sha256"]
      and .release_id == $release_id and .release_digest == $release_digest
	  and .contracts == {search_sha256:$search_contract,business_sha256:$business_contract,theme_sha256:$theme_contract}
      and .suite_sha256 == $suite
      and .artifacts == {
        search:{candidate:{archive_sha256:$sca,inventory_sha256:$sci},rollback:{archive_sha256:$sra,inventory_sha256:$sri}},
		business:{candidate:{archive_sha256:$bca,inventory_sha256:$bci},rollback:{archive_sha256:$bra,inventory_sha256:$bri}},
		theme:{candidate:{archive_sha256:$tca,inventory_sha256:$tci,sidecar_sha256:$tcs},
		  rollback:{archive_sha256:$tra,inventory_sha256:$tri,sidecar_sha256:$trs}}}
      and .recovery.isolated_php_image == $image
      and (.recovery|keys|sort)==["isolated_php_image","receipt","stdout"]
      and (.recovery.receipt|keys|sort)==["path","sha256","sidecar_sha256"]
      and (.recovery.stdout|keys|sort)==["path","sha256"]
      and ([.recovery.receipt.sha256,.recovery.receipt.sidecar_sha256,.recovery.stdout.sha256] |
        all(type == "string" and test("^[a-f0-9]{64}$")))
      and .gates == {promotion_contract:{passes:2},package_release:{passes:2},business_package_release:{passes:2},
        search_component:{passes:2},business_component:{passes:2},search_unit:{passes:2},
		paired_integration:{passes:2},business_unit:{passes:2},theme_contract:{passes:2},
		theme_promotion_contract:{passes:2},theme_package:{passes:2},
		ai_audit_unit:{passes:2},recovery_static:{passes:2},
		recovery_cycle:{runs:1,fresh_restore_cycles:2,http_smoke_cycles:2,theme_quality_cycles:2,ai_audit_cycles:2}}
      and (.started_at_utc|fromdateiso8601) <= (.completed_at_utc|fromdateiso8601)
    ' "${receipt}" >/dev/null || die 'Recibo local incompleto/divergente'
	recovery_path="$(jq -r '.recovery.receipt.path' "${receipt}")"
	verify_recovery_receipt "${recovery_path}"
	[[ "$(sha256_file "${recovery_path}")" == "$(jq -r '.recovery.receipt.sha256' "${receipt}")" \
		&& "$(sha256_file "${recovery_path}.sha256")" == "$(jq -r '.recovery.receipt.sidecar_sha256' "${receipt}")" ]] \
		|| die 'Descriptor recovery del gate local divergente'
	verify_regular_file "$(jq -r '.recovery.stdout.path' "${receipt}")"
	[[ "$(local_file_mode "$(jq -r '.recovery.stdout.path' "${receipt}")")" == 600 ]] \
		|| die 'Stdout recovery no está 0600'
	[[ "$(sha256_file "$(jq -r '.recovery.stdout.path' "${receipt}")")" == "$(jq -r '.recovery.stdout.sha256' "${receipt}")" ]] \
		|| die 'Stdout recovery divergente'
}

assert_local_receipt_fresh_at() {
	local decision_at="$1" receipt completed_at
	receipt="$(receipt_path)"
	completed_at="$(jq -er '.completed_at_utc | select(type=="string")' "${receipt}")" \
		|| die 'Timestamp final del gate local inválido'
	assert_elapsed_within "${completed_at}" "${decision_at}" "${local_gate_max_age}" \
		'gate local en el límite de decisión'
}

verify_local_receipt_at() {
	verify_local_receipt_integrity
	assert_local_receipt_fresh_at "$1"
}

verify_local_receipt() {
	verify_local_receipt_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}

show_plan() {
	local config="$1" search_state business_state
	load_contract; load_config "${config}"
	search_state="$(jq -r '.seal_state' "${contract_file}")"; business_state="$(jq -r '.seal_state' "${business_contract_file}")"
	cat <<EOF
PLAN pareado Business 1.99.10+Search 0.1.12 -> Business 1.99.16+Search 0.1.14
(contratos: Business ${business_state}; Search ${search_state})

1. Gate local aislado: dos pasadas de paquetes/unit/integración y un recovery que
   ejecuta dos restores frescos con HTTP real; nunca usa el WordPress/DB principal.
2. Preflight productivo material: par baseline activo, inventarios exactos de
   ambos plugins, superficies protegidas, fingerprints y binding Cloudflare.
3. Secuencia cerrada: mantenimiento ON; desactivar Search por UI; desplegar
   Business; demostrar midpoint mixto con Search inactivo; desplegar Search;
   demostrar par listo aún inactivo; activar Search por UI; salud del par activo.
4. Cachés en orden estricto: LiteSpeed por UI con persistencia observable,
   Hostinger por MCP y Cloudflare por MCP. Mantenimiento sale antes de Cloudflare.
5. Cerrar con inventarios exactos del par, fingerprints, HAR, convergencia,
   listados sin ZIP/temporales y limpieza. Rollback es la secuencia simétrica.
6. Graphify sólo después de promoción validada. No hay backup remoto.

Targets exclusivos: akibara-business y akibara-search
Dominio: ${production_domain}
Username Hostinger resuelto localmente: ${hostinger_username}
Account ID Cloudflare: cargado sólo desde configuración privada (no se imprime).
DRY RUN: no se ejecutó transporte, UI, API, Docker ni mutación remota.
EOF
	if [[ "${search_state}" != sealed || "${business_state}" != sealed ]]; then
		note 'PRODUCTION_GATE=CLOSED: el par aún no está sellado y enlazado.'
	fi
}

tree_digest() {
	( cd -- "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 shasum -a 256 | shasum -a 256 | awk '{print $1}' )
}

pair_sequence_json() {
	jq -cn '["active-baseline","maintenance-on","search-deactivated-ui",
      "search-inactive-verified","business-deployed","mixed-midpoint-search-inactive-verified",
      "search-deployed","ready-pair-search-inactive-verified","search-activated-ui",
      "active-pair-health-verified","litespeed-ui-purged","hostinger-cache-purged",
      "maintenance-off","cloudflare-purged","final-evidence-cleanup-verified"]'
}

deploy_policy_json() { jq -c '.production_invariants.deployment.receipt_policy' "${contract_file}"; }
hostinger_cache_policy_json() { jq -c '.production_invariants.hostinger_cache.receipt_policy' "${contract_file}"; }

verify_handoff_file() {
	local handoff="$1" accept_at="${2:-}" handoff_dir evidence_root pair_receipt pair_recovery expected_sequence
	local bc_candidate bc_rollback sc_candidate sc_rollback account binding_code purge_code handoff_ready
	verify_regular_file "${handoff}"; verify_regular_file "${handoff}.sha256"
	assert_no_symlink_ancestors "${handoff}" || die 'Handoff bajo symlink'
	verify_sidecar "${handoff}"
	verify_local_receipt_integrity
	handoff_ready="$(jq -er '.ready_at_utc | select(type=="string")' "${handoff}")" \
		|| die 'Timestamp ready_at_utc del handoff inválido'
	assert_local_receipt_fresh_at "${handoff_ready}"
	if [[ -n "${accept_at}" ]]; then
		assert_local_receipt_fresh_at "${accept_at}"
	fi
	handoff_dir="$(cd -- "$(dirname -- "${handoff}")" && pwd -P)"
	[[ "${handoff_dir}" == "${local_state_root}/handoffs/${release_digest}" ]] || die 'Handoff fuera de raíz autorizada'
	python3 - "${handoff_dir}" <<'PY'
import pathlib, sys
root=pathlib.Path(sys.argv[1])
if {p.name for p in root.iterdir()} != {"candidate","rollback","handoff.json","handoff.json.sha256"}:
    raise SystemExit("handoff contiene extras/temporales")
for purpose in ("candidate","rollback"):
    child=root/purpose
    if child.is_symlink() or {p.name for p in child.iterdir()} != {"akibara-business","akibara-search"}:
        raise SystemExit("par incompleto/extra en handoff")
PY
	bc_candidate="${handoff_dir}/candidate/akibara-business"; sc_candidate="${handoff_dir}/candidate/akibara-search"
	bc_rollback="${handoff_dir}/rollback/akibara-business"; sc_rollback="${handoff_dir}/rollback/akibara-search"
	verify_exact_inventory_tree "${handoff_dir}/candidate" "${business_candidate_inventory}" akibara-business
	verify_exact_inventory_tree "${handoff_dir}/candidate" "${candidate_inventory}" akibara-search
	verify_exact_inventory_tree "${handoff_dir}/rollback" "${business_preimage_inventory}" akibara-business
	verify_exact_inventory_tree "${handoff_dir}/rollback" "${preimage_inventory}" akibara-search
	account="$(jq -r '.operations.preflight.cloudflare_binding.args.account_id // ""' "${handoff}")"
	[[ "${account}" =~ ^[a-f0-9]{32}$ ]] || die 'Account ID handoff inválido'
	[[ -z "${cloudflare_account_id}" || "${account}" == "${cloudflare_account_id}" ]] || die 'Account ID divergente'
	cloudflare_account_id="${account}"
	binding_code="async () => cloudflare.request({method:\"GET\",path:\"/zones/${cloudflare_zone_id}\"})"
	purge_code="async () => cloudflare.request({method:\"POST\",path:\"/zones/${cloudflare_zone_id}/purge_cache\",body:{purge_everything:true}})"
	evidence_root="${local_state_root}/evidence/${release_digest}"
	pair_receipt="$(receipt_path)"; pair_recovery="$(jq -r '.recovery.receipt.path' "${pair_receipt}")"
	expected_sequence="$(pair_sequence_json)"
	jq -e \
		--arg release_id "${release_id}" --arg digest "${release_digest}" \
		--arg search_contract "${contract_sha}" --arg business_contract "${business_contract_sha}" \
		--arg domain "${production_domain}" --arg username "${hostinger_username}" \
		--arg bcc "${bc_candidate}" --arg bcr "${bc_rollback}" --arg scc "${sc_candidate}" --arg scr "${sc_rollback}" \
		--arg bcc_tree "$(tree_digest "${bc_candidate}")" --arg bcr_tree "$(tree_digest "${bc_rollback}")" \
		--arg scc_tree "$(tree_digest "${sc_candidate}")" --arg scr_tree "$(tree_digest "${sc_rollback}")" \
		--arg bci "${business_candidate_inventory_sha}" --arg bri "${business_preimage_inventory_sha}" \
		--arg sci "${candidate_inventory_sha}" --arg sri "${preimage_inventory_sha}" \
		--argjson bccount "$(wc -l < "${business_candidate_inventory}" | tr -d ' ')" \
		--argjson brcount "$(wc -l < "${business_preimage_inventory}" | tr -d ' ')" \
		--argjson sccount "$(wc -l < "${candidate_inventory}" | tr -d ' ')" \
		--argjson srcount "$(wc -l < "${preimage_inventory}" | tr -d ' ')" \
		--arg gate_path "${pair_receipt}" --arg gate_sha "$(sha256_file "${pair_receipt}")" \
		--arg recovery_path "${pair_recovery}" --arg recovery_sha "$(sha256_file "${pair_recovery}")" \
		--arg account "${account}" --arg zone_id "${cloudflare_zone_id}" --arg zone_name "${cloudflare_zone_name}" \
		--arg binding_code "${binding_code}" --arg purge_code "${purge_code}" \
		--arg evidence_root "${evidence_root}" --argjson sequence "${expected_sequence}" \
		--argjson deploy_policy "$(deploy_policy_json)" --argjson cache_policy "$(hostinger_cache_policy_json)" '
      (keys|sort)==["components","contracts","evidence","graphify_allowed","kind","local_gate",
        "operations","ready_at_utc","release_digest","release_id","schema_version","targets"]
      and .schema_version==3 and .kind=="akibara-business-search-paired-mcp-handoff"
      and .release_id==$release_id and .release_digest==$digest
      and .contracts=={search:{path:"ops/contracts/akibara-search-0.1.14.json",sha256:$search_contract},
        business:{path:"ops/contracts/akibara-business-1.99.16.json",sha256:$business_contract}}
      and .targets==[
        {kind:"plugin",slug:"akibara-business",domain:$domain,exclusive_path:"public_html/wp-content/plugins/akibara-business"},
        {kind:"plugin",slug:"akibara-search",domain:$domain,exclusive_path:"public_html/wp-content/plugins/akibara-search"}]
      and .components=={
        business:{candidate:{version:"1.99.16",local_path:$bcc,tree_sha256:$bcc_tree,inventory_sha256:$bci,runtime_files:$bccount},
          rollback:{version:"1.99.10",local_path:$bcr,tree_sha256:$bcr_tree,inventory_sha256:$bri,runtime_files:$brcount}},
        search:{candidate:{version:"0.1.14",local_path:$scc,tree_sha256:$scc_tree,inventory_sha256:$sci,runtime_files:$sccount},
          rollback:{version:"0.1.12",local_path:$scr,tree_sha256:$scr_tree,inventory_sha256:$sri,runtime_files:$srcount}}}
      and .local_gate=={receipt:{path:$gate_path,sha256:$gate_sha},recovery_receipt:{path:$recovery_path,sha256:$recovery_sha}}
      and (.operations|keys|sort)==["candidate","preflight","rollback"]
      and .operations.preflight.cloudflare_binding=={tool:"mcp__cloudflare__execute",
        args:{account_id:$account,code:$binding_code},binding:{account_id:$account,zone:{id:$zone_id,name:$zone_name}}}
      and ([.operations.candidate,.operations.rollback]|all(
        (keys|sort)==["cloudflare","deploy_business","deploy_search","hostinger_cache",
          "litespeed_cache","maintenance_ui","sequence","wordpress_ui"]
        and .sequence==$sequence and .wordpress_ui=={tool:"wordpress-plugin-ui",search_slug:"akibara-search",raw_text_and_image_per_checkpoint:true}
        and .maintenance_ui=={tool:"hostinger-control-panel-ui",raw_text_and_image_per_checkpoint:true}
        and .litespeed_cache=={tool:"wordpress-litespeed-cache-ui",args:{action:"purge-all"},
          receipt_policy:{persisted_ui_evidence_required:true,self_asserted_receipt_allowed:false}}
        and .hostinger_cache=={tool:"mcp__hostinger_hosting__hosting_clearWebsiteCacheV1",
          args:{domain:$domain,username:$username},receipt_policy:$cache_policy}
        and .cloudflare=={tool:"mcp__cloudflare__execute",args:{account_id:$account,code:$purge_code},
          binding:{account_id:$account,zone:{id:$zone_id,name:$zone_name}},
          request:{method:"POST",path:("/zones/"+$zone_id+"/purge_cache"),body:{purge_everything:true}}}))
      and .operations.candidate.deploy_business=={tool:"mcp__hostinger_hosting__hosting_deployWordpressPlugin",
        args:{domain:$domain,pluginPath:$bcc,slug:"akibara-business"},tree_sha256:$bcc_tree,receipt_policy:$deploy_policy}
      and .operations.candidate.deploy_search=={tool:"mcp__hostinger_hosting__hosting_deployWordpressPlugin",
        args:{domain:$domain,pluginPath:$scc,slug:"akibara-search"},tree_sha256:$scc_tree,receipt_policy:$deploy_policy}
      and .operations.rollback.deploy_business=={tool:"mcp__hostinger_hosting__hosting_deployWordpressPlugin",
        args:{domain:$domain,pluginPath:$bcr,slug:"akibara-business"},tree_sha256:$bcr_tree,receipt_policy:$deploy_policy}
      and .operations.rollback.deploy_search=={tool:"mcp__hostinger_hosting__hosting_deployWordpressPlugin",
        args:{domain:$domain,pluginPath:$scr,slug:"akibara-search"},tree_sha256:$scr_tree,receipt_policy:$deploy_policy}
      and .evidence=={root:$evidence_root,
        baseline:{business:{version:"1.99.10",inventory_sha256:$bri,runtime_files:$brcount},search:{version:"0.1.12",inventory_sha256:$sri,runtime_files:$srcount}},
        candidate:{business:{version:"1.99.16",inventory_sha256:$bci,runtime_files:$bccount},search:{version:"0.1.14",inventory_sha256:$sci,runtime_files:$sccount}},
        rollback:{business:{version:"1.99.10",inventory_sha256:$bri,runtime_files:$brcount},search:{version:"0.1.12",inventory_sha256:$sri,runtime_files:$srcount}},
        protected_runtime:["akibara-cart-checkout-blocks","bluex-for-woocommerce","akibara-gp"],
	        commerce_must_match:true,search_protected_non_phase_surfaces_must_match:true,
	        search_protected_scope:"excludes-controlled-business-feed-contract-option",
	        controlled_phase_option:"akibara_business_search_feed_contract_version",
	        local_recovery_full_phase_transition_required:true,durable_convergence_required:true,
        remote_cleanup_paths:["public_html","public_html/wp-content/plugins"],
        checkpoint_raw_text_and_image_required:true,self_asserted_boolean_receipts_allowed:false}
      and .graphify_allowed==false and (.ready_at_utc|fromdateiso8601>0)
    ' "${handoff}" >/dev/null || die 'Handoff pareado rechazado'
}

extract_component() {
	local archive="$1" inventory="$2" slug="$3" parent="$4" copy
	copy="${parent}/.${slug}.zip"
	cp -- "${archive}" "${copy}"; chmod 0600 "${copy}"
	verify_archive_inventory "${copy}" "${inventory}" "${slug}"
	unzip -q "${copy}" -d "${parent}"; rm -f -- "${copy}"
	verify_exact_inventory_tree "${parent}" "${inventory}" "${slug}"
}

prepare_handoff() (
	local config="$1" handoff_dir handoff temp='' candidate rollback evidence_root binding_code purge_code sequence
	local gate_receipt recovery_receipt partial
	cleanup() { if [[ -n "${temp}" ]]; then rm -rf -- "${temp}"; fi; }
	trap cleanup EXIT; trap 'exit 129' HUP; trap 'exit 130' INT; trap 'exit 143' TERM
	load_contract; require_sealed_contract; load_config "${config}"; verify_artifacts; verify_local_receipt
	handoff_dir="${local_state_root}/handoffs/${release_digest}"; handoff="${handoff_dir}/handoff.json"
	if [[ -e "${handoff}" || -L "${handoff}" ]]; then verify_handoff_file "${handoff}"; note "PASS: handoff ya existe: ${handoff}"; return; fi
	assert_no_symlink_ancestors "${local_state_root}" || die 'Raíz handoff insegura'
	mkdir -p -- "${local_state_root}/handoffs"; chmod 0700 "${local_state_root}" "${local_state_root}/handoffs"
	temp="$(mktemp -d "${local_state_root}/handoffs/.${release_digest}.XXXXXX")"
	candidate="${temp}/candidate"; rollback="${temp}/rollback"; mkdir -p -- "${candidate}" "${rollback}"
	extract_component "${business_candidate_archive}" "${business_candidate_inventory}" akibara-business "${candidate}"
	extract_component "${candidate_archive}" "${candidate_inventory}" akibara-search "${candidate}"
	extract_component "${business_preimage_archive}" "${business_preimage_inventory}" akibara-business "${rollback}"
	extract_component "${preimage_archive}" "${preimage_inventory}" akibara-search "${rollback}"
	find "${candidate}" "${rollback}" -type d -exec chmod 0755 {} +
	find "${candidate}" "${rollback}" -type f -exec chmod 0644 {} +
	evidence_root="${local_state_root}/evidence/${release_digest}"
	binding_code="async () => cloudflare.request({method:\"GET\",path:\"/zones/${cloudflare_zone_id}\"})"
	purge_code="async () => cloudflare.request({method:\"POST\",path:\"/zones/${cloudflare_zone_id}/purge_cache\",body:{purge_everything:true}})"
	sequence="$(pair_sequence_json)"; gate_receipt="$(receipt_path)"; recovery_receipt="$(jq -r '.recovery.receipt.path' "${gate_receipt}")"
	partial="${temp}/handoff.json"
	jq -n \
		--arg release_id "${release_id}" --arg digest "${release_digest}" --arg search_contract "${contract_sha}" --arg business_contract "${business_contract_sha}" \
		--arg domain "${production_domain}" --arg username "${hostinger_username}" --arg account "${cloudflare_account_id}" \
		--arg zone_id "${cloudflare_zone_id}" --arg zone_name "${cloudflare_zone_name}" --arg binding_code "${binding_code}" --arg purge_code "${purge_code}" \
		--arg bcc "${handoff_dir}/candidate/akibara-business" --arg bcr "${handoff_dir}/rollback/akibara-business" \
		--arg scc "${handoff_dir}/candidate/akibara-search" --arg scr "${handoff_dir}/rollback/akibara-search" \
		--arg bcc_tree "$(tree_digest "${candidate}/akibara-business")" --arg bcr_tree "$(tree_digest "${rollback}/akibara-business")" \
		--arg scc_tree "$(tree_digest "${candidate}/akibara-search")" --arg scr_tree "$(tree_digest "${rollback}/akibara-search")" \
		--arg bci "${business_candidate_inventory_sha}" --arg bri "${business_preimage_inventory_sha}" --arg sci "${candidate_inventory_sha}" --arg sri "${preimage_inventory_sha}" \
		--argjson bccount "$(wc -l < "${business_candidate_inventory}" | tr -d ' ')" --argjson brcount "$(wc -l < "${business_preimage_inventory}" | tr -d ' ')" \
		--argjson sccount "$(wc -l < "${candidate_inventory}" | tr -d ' ')" --argjson srcount "$(wc -l < "${preimage_inventory}" | tr -d ' ')" \
		--arg gate_path "${gate_receipt}" --arg gate_sha "$(sha256_file "${gate_receipt}")" --arg recovery_path "${recovery_receipt}" --arg recovery_sha "$(sha256_file "${recovery_receipt}")" \
		--arg evidence_root "${evidence_root}" --argjson sequence "${sequence}" --argjson deploy_policy "$(deploy_policy_json)" --argjson cache_policy "$(hostinger_cache_policy_json)" \
		--arg ready "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '
      def phase($bp;$sp):{
        sequence:$sequence,
        wordpress_ui:{tool:"wordpress-plugin-ui",search_slug:"akibara-search",raw_text_and_image_per_checkpoint:true},
        maintenance_ui:{tool:"hostinger-control-panel-ui",raw_text_and_image_per_checkpoint:true},
        deploy_business:{tool:"mcp__hostinger_hosting__hosting_deployWordpressPlugin",args:{domain:$domain,pluginPath:$bp.path,slug:"akibara-business"},tree_sha256:$bp.tree,receipt_policy:$deploy_policy},
        deploy_search:{tool:"mcp__hostinger_hosting__hosting_deployWordpressPlugin",args:{domain:$domain,pluginPath:$sp.path,slug:"akibara-search"},tree_sha256:$sp.tree,receipt_policy:$deploy_policy},
        litespeed_cache:{tool:"wordpress-litespeed-cache-ui",args:{action:"purge-all"},receipt_policy:{persisted_ui_evidence_required:true,self_asserted_receipt_allowed:false}},
        hostinger_cache:{tool:"mcp__hostinger_hosting__hosting_clearWebsiteCacheV1",args:{domain:$domain,username:$username},receipt_policy:$cache_policy},
        cloudflare:{tool:"mcp__cloudflare__execute",args:{account_id:$account,code:$purge_code},binding:{account_id:$account,zone:{id:$zone_id,name:$zone_name}},request:{method:"POST",path:("/zones/"+$zone_id+"/purge_cache"),body:{purge_everything:true}}}
      };
      {schema_version:3,kind:"akibara-business-search-paired-mcp-handoff",release_id:$release_id,release_digest:$digest,
       contracts:{search:{path:"ops/contracts/akibara-search-0.1.14.json",sha256:$search_contract},business:{path:"ops/contracts/akibara-business-1.99.16.json",sha256:$business_contract}},
       targets:[{kind:"plugin",slug:"akibara-business",domain:$domain,exclusive_path:"public_html/wp-content/plugins/akibara-business"},{kind:"plugin",slug:"akibara-search",domain:$domain,exclusive_path:"public_html/wp-content/plugins/akibara-search"}],
       components:{business:{candidate:{version:"1.99.16",local_path:$bcc,tree_sha256:$bcc_tree,inventory_sha256:$bci,runtime_files:$bccount},rollback:{version:"1.99.10",local_path:$bcr,tree_sha256:$bcr_tree,inventory_sha256:$bri,runtime_files:$brcount}},search:{candidate:{version:"0.1.14",local_path:$scc,tree_sha256:$scc_tree,inventory_sha256:$sci,runtime_files:$sccount},rollback:{version:"0.1.12",local_path:$scr,tree_sha256:$scr_tree,inventory_sha256:$sri,runtime_files:$srcount}}},
       local_gate:{receipt:{path:$gate_path,sha256:$gate_sha},recovery_receipt:{path:$recovery_path,sha256:$recovery_sha}},
       operations:{preflight:{cloudflare_binding:{tool:"mcp__cloudflare__execute",args:{account_id:$account,code:$binding_code},binding:{account_id:$account,zone:{id:$zone_id,name:$zone_name}}}},candidate:phase({path:$bcc,tree:$bcc_tree};{path:$scc,tree:$scc_tree}),rollback:phase({path:$bcr,tree:$bcr_tree};{path:$scr,tree:$scr_tree})},
	       evidence:{root:$evidence_root,baseline:{business:{version:"1.99.10",inventory_sha256:$bri,runtime_files:$brcount},search:{version:"0.1.12",inventory_sha256:$sri,runtime_files:$srcount}},candidate:{business:{version:"1.99.16",inventory_sha256:$bci,runtime_files:$bccount},search:{version:"0.1.14",inventory_sha256:$sci,runtime_files:$sccount}},rollback:{business:{version:"1.99.10",inventory_sha256:$bri,runtime_files:$brcount},search:{version:"0.1.12",inventory_sha256:$sri,runtime_files:$srcount}},protected_runtime:["akibara-cart-checkout-blocks","bluex-for-woocommerce","akibara-gp"],commerce_must_match:true,search_protected_non_phase_surfaces_must_match:true,search_protected_scope:"excludes-controlled-business-feed-contract-option",controlled_phase_option:"akibara_business_search_feed_contract_version",local_recovery_full_phase_transition_required:true,durable_convergence_required:true,remote_cleanup_paths:["public_html","public_html/wp-content/plugins"],checkpoint_raw_text_and_image_required:true,self_asserted_boolean_receipts_allowed:false},
       graphify_allowed:false,ready_at_utc:$ready}
    ' > "${partial}"
	chmod 0600 "${partial}"; (cd -- "${temp}" && shasum -a 256 handoff.json > handoff.json.sha256); chmod 0600 "${temp}/handoff.json.sha256"
	mv -- "${temp}" "${handoff_dir}"; temp=''
	assert_no_symlink_ancestors "${evidence_root}" || die 'Raíz evidencia insegura'; mkdir -p -- "${evidence_root}"; chmod 0700 "${local_state_root}/evidence" "${evidence_root}"
	verify_handoff_file "${handoff}"; note "PASS: handoff pareado preparado en ${handoff}"
)

# El validador Python consume sólo capturas materiales enlazadas por SHA-256.
# Rechaza checkpoints faltantes/extra/reordenados, cualquier par mixto activo,
# recibos de transporte inventados y evidencia anterior al preflight verificado.
validate_evidence_bundle() {
	python3 - "$1" "$2" "$3" "${preflight_max_age}" "${witness_max_age}" <<'PY'
import datetime as dt, hashlib, json, os, pathlib, re, stat, sys
handoff_path, evidence_dir, mode = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]), sys.argv[3]
preflight_age, witness_age = int(sys.argv[4]), int(sys.argv[5])
handoff=json.loads(handoff_path.read_text()); now=dt.datetime.now(dt.timezone.utc)
ready=dt.datetime.fromisoformat(handoff["ready_at_utc"].replace("Z","+00:00"))
handoff_sha=hashlib.sha256(handoff_path.read_bytes()).hexdigest(); sha_re=re.compile(r"^[a-f0-9]{64}$")
if evidence_dir.resolve()!=pathlib.Path(handoff["evidence"]["root"]).resolve() or not evidence_dir.is_dir() or evidence_dir.is_symlink(): raise SystemExit("raíz evidencia inválida")
def when(v,label):
    if not isinstance(v,str) or not re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z",v): raise SystemExit(f"timestamp inválido {label}")
    return dt.datetime.fromisoformat(v.replace("Z","+00:00"))
def file(name):
    if not isinstance(name,str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*",name): raise SystemExit("nombre captura inseguro")
    p=evidence_dir/name
    try: bits=os.lstat(p).st_mode
    except FileNotFoundError: raise SystemExit(f"captura ausente {name}")
    if not stat.S_ISREG(bits) or stat.S_ISLNK(bits) or p.stat().st_size==0: raise SystemExit("captura insegura")
    return p
def sidecar(p):
    s=file(p.name+".sha256"); expected=f"{hashlib.sha256(p.read_bytes()).hexdigest()}  {p.name}\n"
    if s.read_text()!=expected: raise SystemExit("sidecar captura inválido")
def desc(d,label):
    if not isinstance(d,dict) or set(d)!={"path","sha256"}: raise SystemExit(f"descriptor inválido {label}")
    p=file(d["path"])
    if not sha_re.fullmatch(str(d["sha256"])) or hashlib.sha256(p.read_bytes()).hexdigest()!=d["sha256"]: raise SystemExit(f"SHA captura divergente {label}")
    return p
def manifest(name,kind,purpose=None,age=0):
    p=file(name); sidecar(p); v=json.loads(p.read_text())
    keys={"schema_version","kind","release_id","release_digest","handoff_sha256","completed_at_utc","captures"}
    if purpose is not None: keys|={"purpose","started_at_utc"}
    if set(v)!=keys or v.get("schema_version")!=3 or v.get("kind")!=kind or v.get("release_id")!=handoff["release_id"] or v.get("release_digest")!=handoff["release_digest"] or v.get("handoff_sha256")!=handoff_sha: raise SystemExit("manifiesto inválido")
    completed=when(v["completed_at_utc"],"completed")
    if completed<ready or completed>now+dt.timedelta(minutes=5) or (age and now-completed>dt.timedelta(seconds=age)): raise SystemExit("manifiesto fuera de ventana")
    if purpose is not None:
        started=when(v["started_at_utc"],"started")
        if v["purpose"]!=purpose or started<ready or started>completed: raise SystemExit("fase operación inválida")
    return v,p
def cap(m,key): return desc(m["captures"].get(key),key)
def image(p):
    b=p.read_bytes()
    if len(b)<32 or not (b.startswith(b"\x89PNG\r\n\x1a\n") or b.startswith(b"\xff\xd8\xff")): raise SystemExit("imagen inválida")
def jsoncap(m,key):
    value=json.loads(cap(m,key).read_text()); no_secrets(value); return value
def walk(v):
    yield v
    if isinstance(v,dict):
        for x in v.values(): yield from walk(x)
    elif isinstance(v,list):
        for x in v: yield from walk(x)
def no_secrets(v):
    for x in walk(v):
        if isinstance(x,dict) and any(re.search(r"(?i)(authorization|cookie|password|secret|token|api[_-]?key)",str(k)) for k in x): raise SystemExit("secreto en evidencia")
def envelope(m,key,expected):
    v=jsoncap(m,key); no_secrets(v)
    if not isinstance(v,dict) or set(v)!={"tool","request","response"} or v["tool"]!=expected["tool"] or v["request"]!=expected["args"] or not isinstance(v["response"],dict) or not v["response"]: raise SystemExit(f"envelope inválido {key}")
    return v["response"]
def reject_failure(v,label):
    for x in walk(v):
        if not isinstance(x,dict): continue
        if x.get("isError") is True or ("success" in x and x["success"] is not True) or ("ok" in x and x["ok"] is not True): raise SystemExit(f"fallo explícito {label}")
        if any(k in x and x[k] not in (None,"",[],{}) for k in ("error","errors")): raise SystemExit(f"error material {label}")
        s=x.get("status")
        if s is False or (isinstance(s,int) and not isinstance(s,bool) and not 200<=s<300) or (isinstance(s,str) and s.lower() in {"error","failed","timeout","timed_out","denied"}): raise SystemExit(f"status fallido {label}")
def classify_deploy(v,policy):
    if set(policy)!={"confirmed_success_profile","allowed_ack_loss_result","accept_only_with_exact_postconditions"}: raise SystemExit("policy deploy inválida")
    if policy["confirmed_success_profile"] is not None and v==policy["confirmed_success_profile"]: reject_failure(v,"deploy"); return "confirmed"
    if v==policy["allowed_ack_loss_result"]: return "ack-loss"
    raise SystemExit("receipt deploy no contractual")
def cf(v,binding,require_account):
    for x in walk(v):
        if not isinstance(x,dict) or x.get("success") is not True or x.get("status") not in (200,201) or x.get("errors")!=[]: continue
        r=x.get("result")
        if isinstance(r,dict) and r.get("id")==binding["zone"]["id"]:
            if not require_account or (r.get("name")==binding["zone"]["name"] and isinstance(r.get("account"),dict) and r["account"].get("id")==binding["account_id"]): return
    raise SystemExit("Cloudflare no prueba zona/binding")
def component_inventory(p,component,purpose):
    spec=handoff["evidence"][purpose][component]; slug="akibara-"+component
    rows=p.read_text().splitlines(); pat=re.compile(r"^[a-f0-9]{64}  "+re.escape(slug)+r"/[A-Za-z0-9._/-]+$")
    if len(rows)!=spec["runtime_files"] or any(not pat.fullmatch(x) for x in rows) or len(set(rows))!=len(rows) or rows!=sorted(rows,key=lambda x:x.split("  ",1)[1]) or hashlib.sha256(p.read_bytes()).hexdigest()!=spec["inventory_sha256"]: raise SystemExit(f"inventario {component}/{purpose} divergente")
def protected(p):
    rows=p.read_text().splitlines(); prefixes=("public_html/wp-content/plugins/akibara-cart-checkout-blocks/","public_html/wp-content/plugins/bluex-for-woocommerce/","public_html/wp-content/themes/akibara-gp/")
    pat=re.compile(r"^[a-f0-9]{64}  public_html/wp-content/(?:plugins|themes)/[A-Za-z0-9._/-]+$")
    if not rows or rows!=sorted(rows,key=lambda x:x.split("  ",1)[1]) or len(set(rows))!=len(rows) or any(not pat.fullmatch(x) or not any(x.split("  ",1)[1].startswith(pre) for pre in prefixes) for x in rows) or any(not any(x.split("  ",1)[1].startswith(pre) for x in rows) for pre in prefixes): raise SystemExit("runtime protegido inválido")
def fingerprint(p,label):
    rows=p.read_text().splitlines(); pat=re.compile(r"^[A-Za-z0-9_@.-]+\t[^\t\r\n]+\t(?:[a-f0-9]{64}|present|absent)$")
    if not rows or len(set(rows))!=len(rows) or any(not pat.fullmatch(x) for x in rows): raise SystemExit(f"fingerprint inválido {label}")
def listing(m,key,path,lower,upper):
    v=jsoncap(m,key)
    if not isinstance(v,dict) or set(v)!={"source","path","captured_at_utc","entries"} or v["source"]!="hostinger-file-manager-ui" or v["path"]!=path: raise SystemExit("listing inválido")
    captured=when(v["captured_at_utc"],key)
    if captured<lower or captured>upper: raise SystemExit("listing fuera de ventana")
    rows=v["entries"]; banned=re.compile(r"(?i)(\.zip|\.tar|\.tar\.gz|\.tgz|\.7z|\.gz|\.sql|\.bak|\.tmp|\.old|\.partial|~)$")
    if not isinstance(rows,list) or not rows: raise SystemExit("listing vacío")
    names=[]
    for row in rows:
        if not isinstance(row,dict) or set(row)!={"name","type"} or row["type"] not in ("file","directory") or not isinstance(row["name"],str) or not row["name"] or "/" in row["name"] or "\\" in row["name"] or row["name"] in names or banned.search(row["name"]): raise SystemExit("temporal/entrada inválida remota")
        names.append(row["name"])
    return rows
pre_keys={"cloudflare_binding","baseline_ui_text","baseline_ui_image","business_runtime_baseline","search_runtime_baseline","protected_runtime","commerce","search_protected","remote_webroot_listing","remote_plugins_listing","remote_cleanup_image"}
pre,pre_path=manifest("preflight-evidence.json","akibara-business-search-production-preflight-evidence")
if set(pre["captures"])!=pre_keys: raise SystemExit("capturas preflight incompletas/extra")
binding=handoff["operations"]["preflight"]["cloudflare_binding"]["binding"]; cf(jsoncap(pre,"cloudflare_binding"),binding,True)
text=cap(pre,"baseline_ui_text").read_text()
if re.search(r"(?i)(_wpnonce|authorization|cookie|token=|password=)",text): raise SystemExit("texto UI baseline contiene secreto")
if not all(x in text for x in ("akibara.cl","akibara-business","1.99.10","akibara-search","0.1.12","search_active=true")): raise SystemExit("UI baseline no prueba par activo")
image(cap(pre,"baseline_ui_image")); component_inventory(cap(pre,"business_runtime_baseline"),"business","baseline"); component_inventory(cap(pre,"search_runtime_baseline"),"search","baseline")
protected(cap(pre,"protected_runtime")); fingerprint(cap(pre,"commerce"),"commerce"); fingerprint(cap(pre,"search_protected"),"search")
listing(pre,"remote_webroot_listing","public_html",ready,now+dt.timedelta(minutes=5)); rows=listing(pre,"remote_plugins_listing","public_html/wp-content/plugins",ready,now+dt.timedelta(minutes=5)); image(cap(pre,"remote_cleanup_image"))
if not all({"name":x,"type":"directory"} in rows for x in ("akibara-business","akibara-search")): raise SystemExit("listing preflight no prueba par")
if mode=="preflight": raise SystemExit(0)
if mode not in ("candidate","rollback"): raise SystemExit("purpose inválido")
verified=file("preflight-verified-receipt.json"); sidecar(verified); vr=json.loads(verified.read_text())
if set(vr)!={"schema_version","kind","release_id","release_digest","handoff_sha256","manifest_sha256","verified_at_utc"} or vr.get("schema_version")!=3 or vr.get("kind")!="akibara-business-search-verified-preflight-receipt" or vr.get("release_id")!=handoff["release_id"] or vr.get("release_digest")!=handoff["release_digest"] or vr.get("handoff_sha256")!=handoff_sha or vr.get("manifest_sha256")!=hashlib.sha256(pre_path.read_bytes()).hexdigest(): raise SystemExit("receipt preflight inválido")
verified_at=when(vr["verified_at_utc"],"verified")
preflight_completed=when(pre["completed_at_utc"],"preflight completed")
if verified_at<preflight_completed or verified_at>now+dt.timedelta(minutes=5) or verified_at-preflight_completed>dt.timedelta(seconds=preflight_age): raise SystemExit("aceptación preflight fuera de ventana")
op_keys={"transition_timeline","business_deploy_response","search_deploy_response","hostinger_cache_response","cloudflare_response","public_har","business_runtime","search_runtime","protected_runtime","commerce","search_protected","admin_status","remote_webroot_listing","remote_plugins_listing","remote_cleanup_image"}
op,op_path=manifest(f"{mode}-evidence.json","akibara-business-search-production-operation-evidence",purpose=mode,age=witness_age)
if set(op["captures"])!=op_keys: raise SystemExit("capturas operación incompletas/extra")
started=when(op["started_at_utc"],"started"); completed=when(op["completed_at_utc"],"completed")
if started<verified_at or started-verified_at>dt.timedelta(seconds=preflight_age): raise SystemExit("inicio de operación fuera de ventana preflight")
expected=handoff["operations"][mode]
timeline=jsoncap(op,"transition_timeline")
if not isinstance(timeline,dict) or set(timeline)!={"purpose","checkpoints"} or timeline["purpose"]!=mode or not isinstance(timeline["checkpoints"],list): raise SystemExit("timeline inválido")
if [x.get("id") for x in timeline["checkpoints"]]!=expected["sequence"]: raise SystemExit("checkpoints faltantes/extra/reordenados")
rollback_pair=("1.99.10","0.1.12"); candidate_pair=("1.99.16","0.1.14")
baseline=rollback_pair if mode=="candidate" else candidate_pair
target=candidate_pair if mode=="candidate" else rollback_pair
mid_business="1.99.16" if mode=="candidate" else "1.99.10"; mid_search="0.1.12" if mode=="candidate" else "0.1.14"
states=[(*baseline,True,False),(*baseline,True,True),(*baseline,False,True),(*baseline,False,True),(mid_business,mid_search,False,True),(mid_business,mid_search,False,True),(*target,False,True),(*target,False,True),(*target,True,True),(*target,True,True),(*target,True,True),(*target,True,True),(*target,True,False),(*target,True,False),(*target,True,False)]
last=None; used=set()
for cp,state in zip(timeline["checkpoints"],states):
    if set(cp)!={"id","captured_at_utc","state","text","image"}: raise SystemExit("checkpoint shape inválida")
    t=when(cp["captured_at_utc"],cp["id"])
    if t<started or t>completed or (last is not None and t<=last): raise SystemExit("orden temporal checkpoint inválido")
    last=t
    expected_state={"business_version":state[0],"search_version":state[1],"search_active":state[2],"maintenance_mode":state[3]}
    if cp["state"]!=expected_state or (state[0],state[1]) not in (baseline,target,(mid_business,mid_search)) or (state[0],state[1])==(mid_business,mid_search) and state[2]: raise SystemExit("estado mixto activo/divergente")
    tp,ip=desc(cp["text"],cp["id"]+" text"),desc(cp["image"],cp["id"]+" image"); image(ip)
    if tp.name in used or ip.name in used: raise SystemExit("captura reutilizada entre checkpoints")
    used|={tp.name,ip.name}; raw=tp.read_text()
    if re.search(r"(?i)(_wpnonce|authorization|cookie|token=|password=)",raw): raise SystemExit("texto checkpoint contiene secreto")
    if not all(x in raw for x in (cp["id"],"akibara.cl",state[0],state[1],
            f"search_active={str(state[2]).lower()}",
            f"maintenance_mode={str(state[3]).lower()}")): raise SystemExit("texto checkpoint no prueba estado UI")
    if cp["id"]=="litespeed-ui-purged" and not re.search(r"(?is)litespeed.*(purg|purge).*(persist|guard|success|complet|done)",raw): raise SystemExit("LiteSpeed no prueba purga UI persistida")
business_state=classify_deploy(envelope(op,"business_deploy_response",expected["deploy_business"]),expected["deploy_business"]["receipt_policy"])
search_state=classify_deploy(envelope(op,"search_deploy_response",expected["deploy_search"]),expected["deploy_search"]["receipt_policy"])
cache=envelope(op,"hostinger_cache_response",expected["hostinger_cache"])
if cache!=expected["hostinger_cache"]["receipt_policy"]["exact_result"]: raise SystemExit("ACK Hostinger cache divergente")
cf_response=envelope(op,"cloudflare_response",expected["cloudflare"]); reject_failure(cf_response,"cloudflare"); cf(cf_response,expected["cloudflare"]["binding"],False)
component_inventory(cap(op,"business_runtime"),"business",mode); component_inventory(cap(op,"search_runtime"),"search",mode)
if cap(op,"protected_runtime").read_bytes()!=cap(pre,"protected_runtime").read_bytes(): raise SystemExit("runtime protegido cambió")
protected(cap(op,"protected_runtime"))
if cap(op,"commerce").read_bytes()!=cap(pre,"commerce").read_bytes(): raise SystemExit("commerce cambió")
if cap(op,"search_protected").read_bytes()!=cap(pre,"search_protected").read_bytes(): raise SystemExit("Search protegido fuera de la opción de fase cambió")
fingerprint(cap(op,"commerce"),"commerce-after"); fingerprint(cap(op,"search_protected"),"search-after")
status=jsoncap(op,"admin_status"); durable=None
for x in walk(status):
    if isinstance(x,dict) and x.get("topology")=="clean_pre" and x.get("rebuild") is None and isinstance(x.get("work"),dict) and x["work"].get("pending")==0 and x["work"].get("leased")==0 and x["work"].get("blocked")==0 and x["work"].get("error")=="" and isinstance(x.get("docs"),int) and x["docs"]>0 and x.get("eligible")==x["docs"]: durable=x
if durable is None: raise SystemExit("convergencia durable ausente")
har=jsoncap(op,"public_har"); entries=har.get("log",{}).get("entries",[]) if isinstance(har,dict) else []
urls=[x.get("request",{}).get("url") for x in entries if isinstance(x,dict) and x.get("response",{}).get("status")==200]
if not any(isinstance(x,str) and "/search/" in x and x.startswith("https://akibara.cl/") for x in urls) or not any(isinstance(x,str) and re.fullmatch(r"https://akibara\.cl/manga/?(?:\?.*)?",x) for x in urls): raise SystemExit("HAR Search/manga incompleto")
listing(op,"remote_webroot_listing","public_html",started,completed); rows=listing(op,"remote_plugins_listing","public_html/wp-content/plugins",started,completed); image(cap(op,"remote_cleanup_image"))
if not all({"name":x,"type":"directory"} in rows for x in ("akibara-business","akibara-search")): raise SystemExit("listing final no prueba par")
required=["wordpress-ui-search-inactive-midpoint","wordpress-active-pair-versions","business-runtime-inventory","search-runtime-inventory","complete-witness-bundle"]
if business_state=="ack-loss" or search_state=="ack-loss":
    if expected["deploy_business"]["receipt_policy"]["accept_only_with_exact_postconditions"]!=required or expected["deploy_search"]["receipt_policy"]["accept_only_with_exact_postconditions"]!=required: raise SystemExit("ACK-loss sin postcondiciones exactas")
PY
}

write_verified_receipt() (
	local handoff="$1" evidence_dir="$2" mode="$3" verified_at="${4:-}" manifest receipt kind partial
	[[ -n "${verified_at}" ]] || verified_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	jq -en --arg value "${verified_at}" '$value|fromdateiso8601' >/dev/null \
		|| die 'Timestamp de receipt verificado inválido'
	if [[ "${mode}" == preflight ]]; then manifest="${evidence_dir}/preflight-evidence.json"; receipt="${evidence_dir}/preflight-verified-receipt.json"; kind='akibara-business-search-verified-preflight-receipt'; else manifest="${evidence_dir}/${mode}-evidence.json"; receipt="${evidence_dir}/${mode}-verified-receipt.json"; kind='akibara-business-search-verified-operation-receipt'; fi
	partial="${receipt}.partial.$$"; trap 'rm -f -- "${partial}"' EXIT
	jq -n --arg kind "${kind}" --arg release_id "${release_id}" --arg digest "${release_digest}" --arg handoff_sha "$(sha256_file "${handoff}")" --arg manifest_sha "$(sha256_file "${manifest}")" --arg time "${verified_at}" --arg mode "${mode}" '{schema_version:3,kind:$kind,release_id:$release_id,release_digest:$digest,handoff_sha256:$handoff_sha,manifest_sha256:$manifest_sha,verified_at_utc:$time}+(if $mode=="preflight" then {} else {purpose:$mode} end)' > "${partial}"
	chmod 0600 "${partial}"; mv -- "${partial}" "${receipt}"; (cd -- "${evidence_dir}" && shasum -a 256 "$(basename -- "${receipt}")" > "$(basename -- "${receipt}").sha256"); chmod 0600 "${receipt}" "${receipt}.sha256"
)

verify_preflight_bundle() {
	local decision_at preflight_completed
	[[ -d "$2" && ! -L "$2" ]] || die 'Directorio de evidencia inseguro'
	assert_no_symlink_ancestors "$2" || die 'Evidencia bajo padre simbólico'
	verify_handoff_file "$1" || return 1
	validate_evidence_bundle "$1" "$2" preflight || return 1
	decision_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	preflight_completed="$(jq -er '.completed_at_utc | select(type=="string")' "$2/preflight-evidence.json")" \
		|| die 'Timestamp final del preflight inválido'
	assert_local_receipt_fresh_at "${decision_at}"
	assert_elapsed_within "${preflight_completed}" "${decision_at}" "${preflight_max_age}" \
		'preflight en su aceptación'
	write_verified_receipt "$1" "$2" preflight "${decision_at}" || return 1
	note 'PASS: preflight material del par validado'
}
verify_witness_bundle() {
	local operation_started preflight_verified
	[[ "$3" == candidate || "$3" == rollback ]] || die 'Purpose inválido'
	[[ -d "$2" && ! -L "$2" ]] || die 'Directorio de evidencia inseguro'
	assert_no_symlink_ancestors "$2" || die 'Evidencia bajo padre simbólico'
	verify_handoff_file "$1" || return 1
	validate_evidence_bundle "$1" "$2" "$3" || return 1
	preflight_verified="$(jq -er '.verified_at_utc | select(type=="string")' "$2/preflight-verified-receipt.json")" \
		|| die 'Timestamp del preflight verificado inválido'
	operation_started="$(jq -er '.started_at_utc | select(type=="string")' "$2/$3-evidence.json")" \
		|| die 'Timestamp de inicio de operación inválido'
	assert_local_receipt_fresh_at "${preflight_verified}"
	assert_local_receipt_fresh_at "${operation_started}"
	write_verified_receipt "$1" "$2" "$3" || return 1
	note "PASS: evidencia pareada $3 validada"
}

parse_single_option() { local expected="$1"; shift; [[ "$#" == 2 && "$1" == "${expected}" ]] || die "Se esperaba ${expected} VALOR"; printf '%s\n' "$2"; }

main() {
	local command="${1:-}" config handoff evidence_dir purpose
	[[ -n "${command}" ]] || { usage >&2; exit 2; }; shift
	for tool in awk cp find gzip jq python3 shasum stat unzip; do command -v "${tool}" >/dev/null || die "Falta ${tool}"; done
	case "${command}" in
		verify-artifacts) (($#==0)) || die 'Opciones inesperadas'; verify_artifacts ;;
		local-gate) (($#==0)) || die 'Opciones inesperadas'; run_local_gate ;;
		plan) config="$(parse_single_option --config "$@")"; show_plan "${config}" ;;
		handoff) config="$(parse_single_option --config "$@")"; prepare_handoff "${config}" ;;
		verify-handoff)
			handoff="$(parse_single_option --handoff "$@")"; load_contract; require_sealed_contract
			hostinger_username="$(jq -r '.operations.candidate.hostinger_cache.args.username' "${handoff}")"; cloudflare_account_id="$(jq -r '.operations.preflight.cloudflare_binding.args.account_id' "${handoff}")"; verify_handoff_file "${handoff}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"; note 'PASS: handoff pareado validado' ;;
		verify-preflight|verify-witnesses)
			handoff=''; evidence_dir=''; purpose=''
			while (($#)); do case "$1" in --handoff) handoff="$2"; shift 2;; --evidence-dir) evidence_dir="$2"; shift 2;; --purpose) purpose="$2"; shift 2;; *) die "Opción desconocida $1";; esac; done
			[[ -n "${handoff}" && -n "${evidence_dir}" ]] || die 'Faltan handoff/evidence-dir'
			load_contract; require_sealed_contract; hostinger_username="$(jq -r '.operations.candidate.hostinger_cache.args.username' "${handoff}")"; cloudflare_account_id="$(jq -r '.operations.preflight.cloudflare_binding.args.account_id' "${handoff}")"
			if [[ "${command}" == verify-preflight ]]; then [[ -z "${purpose}" ]] || die 'Purpose no aplica'; verify_preflight_bundle "${handoff}" "${evidence_dir}"; else [[ -n "${purpose}" ]] || die 'Falta purpose'; verify_witness_bundle "${handoff}" "${evidence_dir}" "${purpose}"; fi ;;
		-h|--help|help) usage ;;
		*) die "Comando desconocido: ${command}" ;;
	esac
}

if [[ "${AKB_SEARCH_0114_PROMOTION_LIBRARY_ONLY:-0}" != 1 ]]; then main "$@"; fi
