#!/usr/bin/env bash

# Carril local/MCP exclusivo de la fase 2 Theme 2.1.69 -> 2.1.70.
# No contiene transporte remoto: revalida la fase 1, prepara arboles exactos,
# congela argumentos para UI/MCP oficiales y valida evidencia material.

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

theme_entrypoint="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)/$(basename -- "${BASH_SOURCE[0]}")"
theme_ops_dir="$(dirname -- "${theme_entrypoint}")"
pair_rail="${theme_ops_dir}/akibara-search-0.1.14-production-promotion.sh"

[[ -f "${pair_rail}" && ! -L "${pair_rail}" ]] || {
	printf 'ERROR: falta la libreria pareada regular: %s\n' "${pair_rail}" >&2
	exit 1
}

AKB_SEARCH_0114_PROMOTION_LIBRARY_ONLY=1
# shellcheck source=ops/akibara-search-0.1.14-production-promotion.sh
source "${pair_rail}"
unset AKB_SEARCH_0114_PROMOTION_LIBRARY_ONLY

theme_phase2_test="${theme_ops_dir}/test-akibara-theme-2.1.70-production-promotion.sh"
theme_handoff_root="${local_state_root}/theme-handoffs"
theme_evidence_root="${local_state_root}/theme-evidence"
theme_handoff_kind='akibara-theme-phase2-mcp-handoff'
theme_preflight_kind='akibara-theme-phase2-production-preflight-evidence'
theme_operation_kind='akibara-theme-phase2-production-operation-evidence'
theme_preflight_receipt_kind='akibara-theme-phase2-verified-preflight-receipt'
theme_operation_receipt_kind='akibara-theme-phase2-verified-operation-receipt'
theme_close_kind='akibara-ai-discoverability-integrated-production-close-receipt'
theme_close_verified_kind='akibara-ai-discoverability-integrated-production-verified-receipt'

theme_usage() {
	cat <<'EOF'
Uso:
  ops/akibara-theme-2.1.70-production-promotion.sh plan --config ARCHIVO_LOCAL
  ops/akibara-theme-2.1.70-production-promotion.sh handoff --config ARCHIVO_LOCAL
  ops/akibara-theme-2.1.70-production-promotion.sh verify-handoff --handoff JSON
  ops/akibara-theme-2.1.70-production-promotion.sh verify-preflight \
    --handoff JSON --evidence-dir DIRECTORIO
  ops/akibara-theme-2.1.70-production-promotion.sh verify-witnesses \
    --handoff JSON --evidence-dir DIRECTORIO --purpose candidate|rollback
  ops/akibara-theme-2.1.70-production-promotion.sh verify-close \
    --handoff JSON --evidence-dir DIRECTORIO

Este shell nunca despliega, activa mantenimiento ni purga. El handoff conserva
argumentos exactos para WordPress UI, Hostinger UI/MCP y Cloudflare MCP. Graphify
permanece prohibido hasta que verify-close emita su receipt verificado.
EOF
}

theme_candidate_sequence_json() {
	jq -cn '["active-theme-baseline","maintenance-on","theme-deployed",
      "active-theme-target-verified","protected-pair-health-verified",
      "litespeed-ui-purged","hostinger-cache-purged","maintenance-off",
      "cloudflare-purged","public-ai-audit-passed",
      "final-evidence-cleanup-verified"]'
}

theme_rollback_sequence_json() {
	jq -cn '["maintenance-held-or-reactivated","rollback-theme-deployed",
      "active-theme-rollback-verified","protected-pair-health-verified",
      "litespeed-ui-purged","hostinger-cache-purged","maintenance-off",
      "cloudflare-purged","public-truth-health-verified",
      "final-evidence-cleanup-verified"]'
}

theme_deploy_policy_json() {
	jq -c '.production_invariants.deployment_receipt_policy' "${theme_contract_file}"
}

theme_pair_handoff_path() {
	printf '%s/handoffs/%s/handoff.json\n' "${local_state_root}" "${release_digest}"
}

theme_pair_evidence_path() {
	printf '%s/evidence/%s\n' "${local_state_root}" "${release_digest}"
}

theme_phase2_handoff_path() {
	printf '%s/%s/handoff.json\n' "${theme_handoff_root}" "${release_digest}"
}

theme_phase2_evidence_path() {
	printf '%s/%s\n' "${theme_evidence_root}" "${release_digest}"
}

theme_require_contract_extension() {
	local rail_sha test_sha library_sha
	rail_sha="$(sha256_file "${theme_entrypoint}")"
	test_sha="$(sha256_file "${theme_phase2_test}")"
	library_sha="$(sha256_file "${pair_rail}")"
	jq -e \
		--arg rail_sha "${rail_sha}" --arg test_sha "${test_sha}" \
		--arg library_sha "${library_sha}" '
      .quality_gates.production_handoff == {
        rail:{path:"ops/akibara-theme-2.1.70-production-promotion.sh",sha256:$rail_sha},
        test:{path:"ops/test-akibara-theme-2.1.70-production-promotion.sh",sha256:$test_sha,
          command:"bash ops/test-akibara-theme-2.1.70-production-promotion.sh"},
        library:{path:"ops/akibara-search-0.1.14-production-promotion.sh",sha256:$library_sha},
        required_passes:2
      }
      and .promotion_requirements == {
        phase1_candidate_receipt:{schema_version:3,
          kind:"akibara-business-search-verified-operation-receipt",purpose:"candidate",
          max_age_seconds:1800,sidecar_required:true,revalidate_material_bundle:true,
          same_release_digest:true},
        handoff:{schema_version:1,kind:"akibara-theme-phase2-mcp-handoff",
          exclusive_theme_only:true},
        preflight_receipt:{schema_version:1,
          kind:"akibara-theme-phase2-verified-preflight-receipt",max_age_seconds:900},
        operation_receipt:{schema_version:1,
          kind:"akibara-theme-phase2-verified-operation-receipt",
          purposes:["candidate","rollback"]},
        integrated_close_receipt:{schema_version:1,
          kind:"akibara-ai-discoverability-integrated-production-verified-receipt",
          verify_close_required:true},
        cache_order:["litespeed-ui","hostinger-mcp","cloudflare-mcp"],
        maintenance_off_before_cloudflare:true,
        graphify_before_verify_close_allowed:false
      }
      and .production_invariants.deployment_transport ==
        "mcp__hostinger_hosting__hosting_deployWordpressTheme"
      and .production_invariants.deployment_args == {
        activate:true,domain:"akibara.cl",slug:"akibara-gp",
        candidate_version:"2.1.70",rollback_version:"2.1.69"}
      and .production_invariants.protected_pair == {
        business:{slug:"akibara-business",version:"1.99.16",active:true},
        search:{slug:"akibara-search",version:"0.1.14",active:true}}
      and .production_invariants.deployment_receipt_policy == {
        transport_response_is_release_proof:false,
        allowed_ack_loss_result:{Err:"tool call error: tool call failed for `hostinger-hosting/hosting_deployWordpressTheme`\n\nCaused by:\n    timed out awaiting tools/call after 120s"},
        explicit_failure_rejected:true,
        postconditions_always_required:["wordpress-ui-active-theme-version",
          "theme-runtime-inventory","business-runtime-inventory",
          "search-runtime-inventory","complete-theme-witness-bundle"]}
      and .production_invariants.public_ai_audit == {
        schema_version:3,base_url:"https://akibara.cl/",
        fetch_base_url:"https://akibara.cl/",strict:true,
        expected_pickup_locality:"San Miguel",expected_ai_train:"yes",
        require_return_policy:true,require_llms_manga:true,
        redirect_policy:"manual_fail_closed",online_only:true,
        public_storefront:false}
      and .production_invariants.remote_cleanup_paths == ["public_html",
        "public_html/wp-content/plugins","public_html/wp-content/themes"]
      and .production_invariants.graphify_during_release_allowed == false
      and .production_invariants.graphify_after_confirmed_public_verification == true
    ' "${theme_contract_file}" >/dev/null \
		|| die 'Contrato Theme no contiene el protocolo productivo fase 2 exacto'
}

theme_load_context_from_config() {
	local config="$1"
	load_contract
	require_sealed_contract
	load_config "${config}"
	verify_artifacts
	verify_local_receipt
	verify_regular_file "${theme_entrypoint}"
	verify_regular_file "${theme_phase2_test}"
	theme_require_contract_extension
}

theme_load_context_from_handoff() {
	local handoff="$1"
	load_contract
	require_sealed_contract
	verify_regular_file "${handoff}"
	hostinger_username="$(jq -er '.operations.candidate.hostinger_cache.args.username' "${handoff}")" \
		|| die 'Username Hostinger ausente del handoff Theme'
	cloudflare_account_id="$(jq -er '.operations.preflight.cloudflare_binding.args.account_id' "${handoff}")" \
		|| die 'Account ID Cloudflare ausente del handoff Theme'
	verify_artifacts
	verify_local_receipt
	verify_regular_file "${theme_entrypoint}"
	verify_regular_file "${theme_phase2_test}"
	theme_require_contract_extension
}

theme_verify_pair_candidate_receipt() {
	local decision_at="${1:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}"
	local pair_handoff pair_evidence receipt manifest operation_completed verified_at
	pair_handoff="$(theme_pair_handoff_path)"
	pair_evidence="$(theme_pair_evidence_path)"
	receipt="${pair_evidence}/candidate-verified-receipt.json"
	manifest="${pair_evidence}/candidate-evidence.json"
	verify_handoff_file "${pair_handoff}" "${decision_at}" \
		|| die 'Handoff pareado no se pudo revalidar para Theme'
	validate_evidence_bundle "${pair_handoff}" "${pair_evidence}" candidate \
		|| die 'Witness candidato pareado no se pudo revalidar para Theme'
	for path in "${receipt}" "${receipt}.sha256" "${manifest}"; do
		verify_regular_file "${path}"
		[[ "$(local_file_mode "${path}")" == 600 ]] || die "Evidencia pareada no está 0600: ${path}"
	done
	verify_sidecar "${receipt}"
	operation_completed="$(jq -er '.completed_at_utc | select(type=="string")' "${manifest}")" \
		|| die 'Timestamp final del witness pareado inválido'
	verified_at="$(jq -er '.verified_at_utc | select(type=="string")' "${receipt}")" \
		|| die 'Timestamp del receipt candidato pareado inválido'
	jq -e \
		--arg release_id "${release_id}" --arg digest "${release_digest}" \
		--arg handoff_sha "$(sha256_file "${pair_handoff}")" \
		--arg manifest_sha "$(sha256_file "${manifest}")" '
      (keys|sort)==["handoff_sha256","kind","manifest_sha256","purpose",
        "release_digest","release_id","schema_version","verified_at_utc"]
      and .schema_version==3
      and .kind=="akibara-business-search-verified-operation-receipt"
      and .release_id==$release_id and .release_digest==$digest
      and .handoff_sha256==$handoff_sha and .manifest_sha256==$manifest_sha
      and .purpose=="candidate" and (.verified_at_utc|fromdateiso8601)>0
    ' "${receipt}" >/dev/null || die 'Receipt candidato pareado no es el bridge exacto'
	assert_elapsed_within "${operation_completed}" "${verified_at}" "${witness_max_age}" \
		'witness pareado hasta su verificación'
	assert_elapsed_within "${verified_at}" "${decision_at}" "${witness_max_age}" \
		'witness pareado hasta fase Theme'
}

theme_tree_digest() {
	tree_digest "$1"
}

theme_verify_handoff_file() {
	local handoff="$1" accept_at="${2:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}"
	local handoff_dir candidate rollback expected_path pair_handoff pair_evidence pair_receipt
	local binding_code purge_code expected_candidate expected_rollback
	verify_regular_file "${handoff}"
	verify_regular_file "${handoff}.sha256"
	assert_no_symlink_ancestors "${handoff}" || die 'Handoff Theme bajo symlink'
	verify_sidecar "${handoff}"
	expected_path="$(theme_phase2_handoff_path)"
	[[ "${handoff}" == "${expected_path}" ]] || die 'Handoff Theme fuera de su ruta canónica'
	handoff_dir="$(cd -- "$(dirname -- "${handoff}")" && pwd -P)"
	python3 - "${handoff_dir}" <<'PY' \
		|| die 'Handoff Theme contiene extras o entradas inseguras'
import pathlib, stat, sys
root=pathlib.Path(sys.argv[1])
if root.is_symlink() or {p.name for p in root.iterdir()} != {"candidate","rollback","handoff.json","handoff.json.sha256"}:
    raise SystemExit("handoff Theme contiene extras o temporales")
for purpose in ("candidate","rollback"):
    child=root/purpose
    if child.is_symlink() or {p.name for p in child.iterdir()} != {"akibara-gp"}:
        raise SystemExit("handoff Theme no es exclusivo")
    for item in child.rglob("*"):
        mode=item.lstat().st_mode
        if item.is_symlink() or not (stat.S_ISDIR(mode) or stat.S_ISREG(mode)):
            raise SystemExit("entrada Theme insegura")
PY
	candidate="${handoff_dir}/candidate/akibara-gp"
	rollback="${handoff_dir}/rollback/akibara-gp"
	verify_exact_inventory_tree "${handoff_dir}/candidate" "${theme_candidate_inventory}" akibara-gp \
		|| die 'Árbol Theme candidato no coincide con inventario exacto'
	verify_exact_inventory_tree "${handoff_dir}/rollback" "${theme_preimage_inventory}" akibara-gp \
		|| die 'Árbol Theme rollback no coincide con inventario exacto'
	pair_handoff="$(theme_pair_handoff_path)"
	pair_evidence="$(theme_pair_evidence_path)"
	pair_receipt="${pair_evidence}/candidate-verified-receipt.json"
	theme_verify_pair_candidate_receipt "${accept_at}" \
		|| die 'Bridge candidato pareado inválido para Theme'
	binding_code="async () => cloudflare.request({method:\"GET\",path:\"/zones/${cloudflare_zone_id}\"})"
	purge_code="async () => cloudflare.request({method:\"POST\",path:\"/zones/${cloudflare_zone_id}/purge_cache\",body:{purge_everything:true}})"
	expected_candidate="$(theme_candidate_sequence_json)"
	expected_rollback="$(theme_rollback_sequence_json)"
	jq -e \
		--arg release_id "${release_id}" --arg theme_release_id "akibara-gp-2.1.70" \
		--arg digest "${release_digest}" --arg search_sha "${contract_sha}" \
		--arg business_sha "${business_contract_sha}" --arg theme_sha "${theme_contract_sha}" \
		--arg pair_handoff "${pair_handoff}" --arg pair_handoff_sha "$(sha256_file "${pair_handoff}")" \
		--arg pair_manifest "${pair_evidence}/candidate-evidence.json" \
		--arg pair_manifest_sha "$(sha256_file "${pair_evidence}/candidate-evidence.json")" \
		--arg pair_receipt "${pair_receipt}" --arg pair_receipt_sha "$(sha256_file "${pair_receipt}")" \
		--arg gate "$(receipt_path)" --arg gate_sha "$(sha256_file "$(receipt_path)")" \
		--arg candidate "${candidate}" --arg rollback "${rollback}" \
		--arg candidate_tree "$(theme_tree_digest "${candidate}")" \
		--arg rollback_tree "$(theme_tree_digest "${rollback}")" \
		--arg candidate_archive "${theme_candidate_archive_sha}" \
		--arg candidate_inventory "${theme_candidate_inventory_sha}" \
		--arg candidate_sidecar "${theme_candidate_sidecar_sha}" \
		--arg rollback_archive "${theme_preimage_archive_sha}" \
		--arg rollback_inventory "${theme_preimage_inventory_sha}" \
		--arg rollback_sidecar "${theme_preimage_sidecar_sha}" \
		--arg business_inventory "${business_candidate_inventory_sha}" \
		--arg search_inventory "${candidate_inventory_sha}" \
		--arg domain "${production_domain}" --arg username "${hostinger_username}" \
		--arg account "${cloudflare_account_id}" --arg zone_id "${cloudflare_zone_id}" \
		--arg zone_name "${cloudflare_zone_name}" --arg binding_code "${binding_code}" \
		--arg purge_code "${purge_code}" --arg evidence_root "$(theme_phase2_evidence_path)" \
		--arg ai_engine_sha "$(sha256_file "${ai_audit_engine}")" \
		--arg ai_wrapper_sha "$(sha256_file "${ai_audit_wrapper}")" \
		--arg ai_policy_sha "$(sha256_file "${ai_audit_policy}")" \
		--argjson candidate_sequence "${expected_candidate}" \
		--argjson rollback_sequence "${expected_rollback}" \
		--argjson deploy_policy "$(theme_deploy_policy_json)" \
		--argjson cache_policy "$(hostinger_cache_policy_json)" '
      (keys|sort)==["component","contracts","evidence","graphify_allowed","kind",
        "local_gate","operations","phase1","ready_at_utc","release_digest",
        "release_id","schema_version","target","theme_release_id"]
      and .schema_version==1 and .kind=="akibara-theme-phase2-mcp-handoff"
      and .release_id==$release_id and .theme_release_id==$theme_release_id
      and .release_digest==$digest
      and .contracts=={
        search:{path:"ops/contracts/akibara-search-0.1.14.json",sha256:$search_sha},
        business:{path:"ops/contracts/akibara-business-1.99.16.json",sha256:$business_sha},
        theme:{path:"ops/contracts/akibara-gp-2.1.70.json",sha256:$theme_sha}}
      and .phase1=={
        pair_handoff:{path:$pair_handoff,sha256:$pair_handoff_sha},
        candidate_manifest:{path:$pair_manifest,sha256:$pair_manifest_sha},
        candidate_receipt:{path:$pair_receipt,sha256:$pair_receipt_sha}}
      and .local_gate=={path:$gate,sha256:$gate_sha}
      and .target=={kind:"theme",slug:"akibara-gp",domain:$domain,
        exclusive_path:"public_html/wp-content/themes/akibara-gp"}
      and .component=={
        baseline:{version:"2.1.69",local_path:$rollback,tree_sha256:$rollback_tree,
          archive_sha256:$rollback_archive,inventory_sha256:$rollback_inventory,
          sidecar_sha256:$rollback_sidecar,runtime_files:103},
        candidate:{version:"2.1.70",local_path:$candidate,tree_sha256:$candidate_tree,
          archive_sha256:$candidate_archive,inventory_sha256:$candidate_inventory,
          sidecar_sha256:$candidate_sidecar,runtime_files:103},
        rollback:{version:"2.1.69",local_path:$rollback,tree_sha256:$rollback_tree,
          archive_sha256:$rollback_archive,inventory_sha256:$rollback_inventory,
          sidecar_sha256:$rollback_sidecar,runtime_files:103}}
      and (.operations|keys|sort)==["candidate","preflight","rollback"]
      and .operations.preflight.cloudflare_binding=={tool:"mcp__cloudflare__execute",
        args:{account_id:$account,code:$binding_code},
        binding:{account_id:$account,zone:{id:$zone_id,name:$zone_name}}}
      and ([.operations.candidate,.operations.rollback]|all(
        (keys|sort)==["cloudflare","deploy_theme","hostinger_cache","litespeed_cache",
          "maintenance_ui","public_ai_audit","sequence","wordpress_ui"]
        and .wordpress_ui=={tool:"wordpress-theme-ui",slug:"akibara-gp",
          protected_plugins:["akibara-business","akibara-search"],
          raw_text_and_image_per_checkpoint:true}
        and .maintenance_ui=={tool:"hostinger-control-panel-ui",
          raw_text_and_image_per_checkpoint:true}
        and .litespeed_cache=={tool:"wordpress-litespeed-cache-ui",
          args:{action:"purge-all"},receipt_policy:{persisted_ui_evidence_required:true,
          self_asserted_receipt_allowed:false}}
        and .hostinger_cache=={tool:"mcp__hostinger_hosting__hosting_clearWebsiteCacheV1",
          args:{domain:$domain,username:$username},receipt_policy:$cache_policy}
        and .cloudflare=={tool:"mcp__cloudflare__execute",
          args:{account_id:$account,code:$purge_code},
          binding:{account_id:$account,zone:{id:$zone_id,name:$zone_name}},
          request:{method:"POST",path:("/zones/"+$zone_id+"/purge_cache"),
            body:{purge_everything:true}}}
        and .public_ai_audit=={tool:"local-read-only-public-audit",
          executable:"tools/akibara-ai-discoverability-audit.sh",
          args:["--base-url","https://akibara.cl/","--expected-pickup-locality",
            "San Miguel","--require-return-policy","--require-llms-manga",
            "--expected-ai-train","yes","--strict","--timeout-ms","20000",
            "--concurrency","5","--max-sitemaps","200","--sitemap-ua-scope","all"],
          engine_sha256:$ai_engine_sha,wrapper_sha256:$ai_wrapper_sha,
          policy_sha256:$ai_policy_sha,
          receipt_policy:{schema_version:3,base_url:"https://akibara.cl/",
            fetch_base_url:"https://akibara.cl/",redirect_policy:"manual_fail_closed",
            public_storefront:false}}))
      and .operations.candidate.sequence==$candidate_sequence
      and .operations.rollback.sequence==$rollback_sequence
      and .operations.candidate.deploy_theme=={
        tool:"mcp__hostinger_hosting__hosting_deployWordpressTheme",
        args:{activate:true,domain:$domain,slug:"akibara-gp",themePath:$candidate},
        tree_sha256:$candidate_tree,receipt_policy:$deploy_policy}
      and .operations.rollback.deploy_theme=={
        tool:"mcp__hostinger_hosting__hosting_deployWordpressTheme",
        args:{activate:true,domain:$domain,slug:"akibara-gp",themePath:$rollback},
        tree_sha256:$rollback_tree,receipt_policy:$deploy_policy}
      and .evidence=={
        root:$evidence_root,
        baseline:{theme:{version:"2.1.69",inventory_sha256:$rollback_inventory,runtime_files:103}},
        candidate:{theme:{version:"2.1.70",inventory_sha256:$candidate_inventory,runtime_files:103}},
        rollback:{theme:{version:"2.1.69",inventory_sha256:$rollback_inventory,runtime_files:103}},
        protected_pair:{business:{version:"1.99.16",inventory_sha256:.evidence.protected_pair.business.inventory_sha256,
          runtime_files:.evidence.protected_pair.business.runtime_files,active:true},
          search:{version:"0.1.14",inventory_sha256:.evidence.protected_pair.search.inventory_sha256,
          runtime_files:.evidence.protected_pair.search.runtime_files,active:true}},
        other_protected_runtime:["akibara-cart-checkout-blocks","bluex-for-woocommerce"],
        cache_order:["litespeed-ui","hostinger-mcp","cloudflare-mcp"],
        maintenance_off_before_cloudflare:true,
        remote_cleanup_paths:["public_html","public_html/wp-content/plugins",
          "public_html/wp-content/themes"],checkpoint_raw_text_and_image_required:true,
        self_asserted_boolean_receipts_allowed:false}
      and .evidence.protected_pair.business.inventory_sha256==
		$business_inventory
      and .evidence.protected_pair.search.inventory_sha256==
		$search_inventory
      and .graphify_allowed==false and (.ready_at_utc|fromdateiso8601)>0
    ' "${handoff}" >/dev/null \
		|| die 'Handoff Theme fase 2 rechazado'
}

theme_prepare_handoff() (
	local config="$1" temp='' handoff_dir candidate rollback partial
	local pair_handoff pair_evidence pair_receipt binding_code purge_code
	local candidate_sequence rollback_sequence evidence_root
	cleanup() { if [[ -n "${temp}" ]]; then rm -rf -- "${temp}"; fi; }
	trap cleanup EXIT; trap 'exit 129' HUP; trap 'exit 130' INT; trap 'exit 143' TERM
	theme_load_context_from_config "${config}"
	theme_verify_pair_candidate_receipt \
		|| die 'Bridge candidato pareado inválido antes de preparar Theme'
	handoff_dir="${theme_handoff_root}/${release_digest}"
	if [[ -e "${handoff_dir}/handoff.json" || -L "${handoff_dir}/handoff.json" ]]; then
		theme_verify_handoff_file "${handoff_dir}/handoff.json" \
			|| die 'Handoff Theme canónico existente no pudo revalidarse'
		note "PASS: handoff Theme ya existe: ${handoff_dir}/handoff.json"
		return
	fi
	assert_no_symlink_ancestors "${theme_handoff_root}" || die 'Raíz de handoff Theme insegura'
	mkdir -p -- "${theme_handoff_root}"
	chmod 0700 "${local_state_root}" "${theme_handoff_root}"
	temp="$(mktemp -d "${theme_handoff_root}/.${release_digest}.XXXXXX")"
	mkdir -p -- "${temp}/candidate" "${temp}/rollback"
	extract_component "${theme_candidate_archive}" "${theme_candidate_inventory}" akibara-gp "${temp}/candidate"
	extract_component "${theme_preimage_archive}" "${theme_preimage_inventory}" akibara-gp "${temp}/rollback"
	find "${temp}/candidate" "${temp}/rollback" -type d -exec chmod 0755 {} +
	find "${temp}/candidate" "${temp}/rollback" -type f -exec chmod 0644 {} +
	candidate="${handoff_dir}/candidate/akibara-gp"
	rollback="${handoff_dir}/rollback/akibara-gp"
	pair_handoff="$(theme_pair_handoff_path)"
	pair_evidence="$(theme_pair_evidence_path)"
	pair_receipt="${pair_evidence}/candidate-verified-receipt.json"
	binding_code="async () => cloudflare.request({method:\"GET\",path:\"/zones/${cloudflare_zone_id}\"})"
	purge_code="async () => cloudflare.request({method:\"POST\",path:\"/zones/${cloudflare_zone_id}/purge_cache\",body:{purge_everything:true}})"
	candidate_sequence="$(theme_candidate_sequence_json)"
	rollback_sequence="$(theme_rollback_sequence_json)"
	evidence_root="$(theme_phase2_evidence_path)"
	partial="${temp}/handoff.json"
	jq -n \
		--arg release_id "${release_id}" --arg theme_release_id "akibara-gp-2.1.70" \
		--arg digest "${release_digest}" --arg search_sha "${contract_sha}" \
		--arg business_sha "${business_contract_sha}" --arg theme_sha "${theme_contract_sha}" \
		--arg pair_handoff "${pair_handoff}" --arg pair_handoff_sha "$(sha256_file "${pair_handoff}")" \
		--arg pair_manifest "${pair_evidence}/candidate-evidence.json" \
		--arg pair_manifest_sha "$(sha256_file "${pair_evidence}/candidate-evidence.json")" \
		--arg pair_receipt "${pair_receipt}" --arg pair_receipt_sha "$(sha256_file "${pair_receipt}")" \
		--arg gate "$(receipt_path)" --arg gate_sha "$(sha256_file "$(receipt_path)")" \
		--arg candidate "${candidate}" --arg rollback "${rollback}" \
		--arg candidate_tree "$(theme_tree_digest "${temp}/candidate/akibara-gp")" \
		--arg rollback_tree "$(theme_tree_digest "${temp}/rollback/akibara-gp")" \
		--arg candidate_archive "${theme_candidate_archive_sha}" \
		--arg candidate_inventory "${theme_candidate_inventory_sha}" \
		--arg candidate_sidecar "${theme_candidate_sidecar_sha}" \
		--arg rollback_archive "${theme_preimage_archive_sha}" \
		--arg rollback_inventory "${theme_preimage_inventory_sha}" \
		--arg rollback_sidecar "${theme_preimage_sidecar_sha}" \
		--arg business_inventory "${business_candidate_inventory_sha}" \
		--arg search_inventory "${candidate_inventory_sha}" \
		--argjson business_files "$(wc -l < "${business_candidate_inventory}" | tr -d ' ')" \
		--argjson search_files "$(wc -l < "${candidate_inventory}" | tr -d ' ')" \
		--arg domain "${production_domain}" --arg username "${hostinger_username}" \
		--arg account "${cloudflare_account_id}" --arg zone_id "${cloudflare_zone_id}" \
		--arg zone_name "${cloudflare_zone_name}" --arg binding_code "${binding_code}" \
		--arg purge_code "${purge_code}" --arg evidence_root "${evidence_root}" \
		--arg ai_engine_sha "$(sha256_file "${ai_audit_engine}")" \
		--arg ai_wrapper_sha "$(sha256_file "${ai_audit_wrapper}")" \
		--arg ai_policy_sha "$(sha256_file "${ai_audit_policy}")" \
		--argjson candidate_sequence "${candidate_sequence}" \
		--argjson rollback_sequence "${rollback_sequence}" \
		--argjson deploy_policy "$(theme_deploy_policy_json)" \
		--argjson cache_policy "$(hostinger_cache_policy_json)" \
		--arg ready "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '
      def shared($sequence;$path;$tree):{
        sequence:$sequence,
        wordpress_ui:{tool:"wordpress-theme-ui",slug:"akibara-gp",
          protected_plugins:["akibara-business","akibara-search"],
          raw_text_and_image_per_checkpoint:true},
        maintenance_ui:{tool:"hostinger-control-panel-ui",raw_text_and_image_per_checkpoint:true},
        deploy_theme:{tool:"mcp__hostinger_hosting__hosting_deployWordpressTheme",
          args:{activate:true,domain:$domain,slug:"akibara-gp",themePath:$path},
          tree_sha256:$tree,receipt_policy:$deploy_policy},
        litespeed_cache:{tool:"wordpress-litespeed-cache-ui",args:{action:"purge-all"},
          receipt_policy:{persisted_ui_evidence_required:true,self_asserted_receipt_allowed:false}},
        hostinger_cache:{tool:"mcp__hostinger_hosting__hosting_clearWebsiteCacheV1",
          args:{domain:$domain,username:$username},receipt_policy:$cache_policy},
        cloudflare:{tool:"mcp__cloudflare__execute",args:{account_id:$account,code:$purge_code},
          binding:{account_id:$account,zone:{id:$zone_id,name:$zone_name}},
          request:{method:"POST",path:("/zones/"+$zone_id+"/purge_cache"),body:{purge_everything:true}}},
        public_ai_audit:{tool:"local-read-only-public-audit",
          executable:"tools/akibara-ai-discoverability-audit.sh",
          args:["--base-url","https://akibara.cl/","--expected-pickup-locality","San Miguel",
            "--require-return-policy","--require-llms-manga","--expected-ai-train","yes",
            "--strict","--timeout-ms","20000","--concurrency","5","--max-sitemaps","200",
            "--sitemap-ua-scope","all"],engine_sha256:$ai_engine_sha,
          wrapper_sha256:$ai_wrapper_sha,policy_sha256:$ai_policy_sha,
          receipt_policy:{schema_version:3,base_url:"https://akibara.cl/",
            fetch_base_url:"https://akibara.cl/",redirect_policy:"manual_fail_closed",
            public_storefront:false}}
      };
      {schema_version:1,kind:"akibara-theme-phase2-mcp-handoff",
       release_id:$release_id,theme_release_id:$theme_release_id,release_digest:$digest,
       contracts:{search:{path:"ops/contracts/akibara-search-0.1.14.json",sha256:$search_sha},
         business:{path:"ops/contracts/akibara-business-1.99.16.json",sha256:$business_sha},
         theme:{path:"ops/contracts/akibara-gp-2.1.70.json",sha256:$theme_sha}},
       phase1:{pair_handoff:{path:$pair_handoff,sha256:$pair_handoff_sha},
         candidate_manifest:{path:$pair_manifest,sha256:$pair_manifest_sha},
         candidate_receipt:{path:$pair_receipt,sha256:$pair_receipt_sha}},
       local_gate:{path:$gate,sha256:$gate_sha},
       target:{kind:"theme",slug:"akibara-gp",domain:$domain,
         exclusive_path:"public_html/wp-content/themes/akibara-gp"},
       component:{
         baseline:{version:"2.1.69",local_path:$rollback,tree_sha256:$rollback_tree,
           archive_sha256:$rollback_archive,inventory_sha256:$rollback_inventory,
           sidecar_sha256:$rollback_sidecar,runtime_files:103},
         candidate:{version:"2.1.70",local_path:$candidate,tree_sha256:$candidate_tree,
           archive_sha256:$candidate_archive,inventory_sha256:$candidate_inventory,
           sidecar_sha256:$candidate_sidecar,runtime_files:103},
         rollback:{version:"2.1.69",local_path:$rollback,tree_sha256:$rollback_tree,
           archive_sha256:$rollback_archive,inventory_sha256:$rollback_inventory,
           sidecar_sha256:$rollback_sidecar,runtime_files:103}},
       operations:{preflight:{cloudflare_binding:{tool:"mcp__cloudflare__execute",
         args:{account_id:$account,code:$binding_code},
         binding:{account_id:$account,zone:{id:$zone_id,name:$zone_name}}}},
         candidate:shared($candidate_sequence;$candidate;$candidate_tree),
         rollback:shared($rollback_sequence;$rollback;$rollback_tree)},
       evidence:{root:$evidence_root,
         baseline:{theme:{version:"2.1.69",inventory_sha256:$rollback_inventory,runtime_files:103}},
         candidate:{theme:{version:"2.1.70",inventory_sha256:$candidate_inventory,runtime_files:103}},
         rollback:{theme:{version:"2.1.69",inventory_sha256:$rollback_inventory,runtime_files:103}},
         protected_pair:{business:{version:"1.99.16",inventory_sha256:$business_inventory,
           runtime_files:$business_files,active:true},search:{version:"0.1.14",
           inventory_sha256:$search_inventory,runtime_files:$search_files,active:true}},
         other_protected_runtime:["akibara-cart-checkout-blocks","bluex-for-woocommerce"],
         cache_order:["litespeed-ui","hostinger-mcp","cloudflare-mcp"],
         maintenance_off_before_cloudflare:true,
         remote_cleanup_paths:["public_html","public_html/wp-content/plugins",
           "public_html/wp-content/themes"],checkpoint_raw_text_and_image_required:true,
         self_asserted_boolean_receipts_allowed:false},
       graphify_allowed:false,ready_at_utc:$ready}
    ' > "${partial}"
	chmod 0600 "${partial}"
	( cd -- "${temp}" && shasum -a 256 handoff.json > handoff.json.sha256 )
	chmod 0600 "${temp}/handoff.json.sha256"
	mv -- "${temp}" "${handoff_dir}"
	temp=''
	assert_no_symlink_ancestors "${evidence_root}" || die 'Raíz de evidencia Theme insegura'
	mkdir -p -- "${evidence_root}"
	chmod 0700 "${theme_evidence_root}" "${evidence_root}"
	theme_verify_handoff_file "${handoff_dir}/handoff.json" \
		|| die 'Handoff Theme recién preparado no superó su revalidación'
	note "PASS: handoff Theme fase 2 preparado en ${handoff_dir}/handoff.json"
)

theme_validate_evidence_bundle() {
	python3 - "$1" "$2" "$3" "${preflight_max_age}" "${witness_max_age}" <<'PY'
import datetime as dt
import hashlib
import json
import os
import pathlib
import re
import stat
import sys

handoff_path = pathlib.Path(sys.argv[1])
evidence_dir = pathlib.Path(sys.argv[2])
mode = sys.argv[3]
preflight_age = int(sys.argv[4])
witness_age = int(sys.argv[5])
handoff = json.loads(handoff_path.read_text(encoding="utf-8"))
now = dt.datetime.now(dt.timezone.utc)
sha_re = re.compile(r"^[a-f0-9]{64}$")


def fail(message):
    raise SystemExit(message)


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def when(value, label):
    if not isinstance(value, str):
        fail(f"timestamp ausente: {label}")
    try:
        parsed = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        fail(f"timestamp invalido: {label}")
    if parsed.tzinfo is None:
        fail(f"timestamp sin zona: {label}")
    return parsed.astimezone(dt.timezone.utc)


def regular(path, label, mode_0600=True, allow_empty=False):
    try:
        st = path.lstat()
    except FileNotFoundError:
        fail(f"archivo ausente: {label}")
    if (path.is_symlink() or not stat.S_ISREG(st.st_mode)
            or (st.st_size == 0 and not allow_empty)):
        fail(f"archivo inseguro/vacio: {label}")
    if mode_0600 and stat.S_IMODE(st.st_mode) != 0o600:
        fail(f"archivo no 0600: {label}")
    return path


def sidecar(path):
    check = regular(path.with_name(path.name + ".sha256"), path.name + ".sha256")
    expected = f"{sha(path)}  {path.name}\n".encode("ascii")
    if check.read_bytes() != expected:
        fail(f"sidecar divergente: {path.name}")


def descriptor(value, label, root=None, allow_empty=False):
    if not isinstance(value, dict) or set(value) != {"path", "sha256"}:
        fail(f"descriptor invalido: {label}")
    if not isinstance(value["path"], str) or not value["path"] or "/" in value["path"] or "\\" in value["path"]:
        fail(f"ruta descriptor invalida: {label}")
    if not sha_re.fullmatch(str(value["sha256"])):
        fail(f"hash descriptor invalido: {label}")
    base = evidence_dir if root is None else root
    path = regular(base / value["path"], label, allow_empty=allow_empty)
    if path.parent.resolve() != base.resolve() or sha(path) != value["sha256"]:
        fail(f"descriptor divergente: {label}")
    return path


def image(path):
    payload = path.read_bytes()
    if len(payload) < 32 or not (payload.startswith(b"\x89PNG\r\n\x1a\n") or payload.startswith(b"\xff\xd8\xff")):
        fail("imagen invalida")


def walk(value):
    yield value
    if isinstance(value, dict):
        for child in value.values():
            yield from walk(child)
    elif isinstance(value, list):
        for child in value:
            yield from walk(child)


def no_secrets(value):
    secret = re.compile(r"(?i)(authorization|cookie|password|secret|token|api[_-]?key|_wpnonce)")
    for child in walk(value):
        if isinstance(child, dict) and any(secret.search(str(key)) for key in child):
            fail("secreto en evidencia")


def json_capture(manifest, key):
    value = json.loads(descriptor(manifest["captures"].get(key), key).read_text(encoding="utf-8"))
    no_secrets(value)
    return value


def manifest(name, kind, purpose=None, age=None):
    path = regular(evidence_dir / name, name)
    sidecar(path)
    value = json.loads(path.read_text(encoding="utf-8"))
    no_secrets(value)
    expected_keys = {"schema_version", "kind", "release_id", "theme_release_id",
                     "release_digest", "handoff_sha256", "completed_at_utc", "captures"}
    if purpose is not None:
        expected_keys |= {"purpose", "started_at_utc"}
    if set(value) != expected_keys or value.get("schema_version") != 1 or value.get("kind") != kind:
        fail(f"manifest invalido: {name}")
    if value.get("release_id") != handoff["release_id"] or value.get("theme_release_id") != handoff["theme_release_id"]:
        fail(f"identidad de release divergente: {name}")
    if value.get("release_digest") != handoff["release_digest"] or value.get("handoff_sha256") != sha(handoff_path):
        fail(f"binding de handoff divergente: {name}")
    completed = when(value["completed_at_utc"], name + " completed")
    if completed > now + dt.timedelta(minutes=5):
        fail(f"manifest futuro: {name}")
    if age is not None and now - completed > dt.timedelta(seconds=age):
        fail(f"manifest fuera de ventana: {name}")
    if purpose is not None:
        started = when(value["started_at_utc"], name + " started")
        if value.get("purpose") != purpose or started > completed:
            fail(f"fase invalida: {name}")
    return value, path


def inventory(path, spec, slug, label):
    rows = path.read_text(encoding="utf-8").splitlines()
    pattern = re.compile(r"^[a-f0-9]{64}  " + re.escape(slug) + r"/[A-Za-z0-9._/-]+$")
    if (len(rows) != spec["runtime_files"] or len(rows) != len(set(rows))
            or any(not pattern.fullmatch(row) for row in rows)
            or rows != sorted(rows, key=lambda row: row.split("  ", 1)[1])
            or sha(path) != spec["inventory_sha256"]):
        fail(f"inventario divergente: {label}")


def protected_other(path):
    rows = path.read_text(encoding="utf-8").splitlines()
    prefixes = ("public_html/wp-content/plugins/akibara-cart-checkout-blocks/",
                "public_html/wp-content/plugins/bluex-for-woocommerce/")
    pattern = re.compile(r"^[a-f0-9]{64}  public_html/wp-content/plugins/[A-Za-z0-9._/-]+$")
    if (not rows or len(rows) != len(set(rows))
            or rows != sorted(rows, key=lambda row: row.split("  ", 1)[1])
            or any(not pattern.fullmatch(row) or not any(row.split("  ", 1)[1].startswith(prefix) for prefix in prefixes) for row in rows)
            or any(not any(row.split("  ", 1)[1].startswith(prefix) for row in rows) for prefix in prefixes)):
        fail("runtime protegido no objetivo invalido")


def fingerprint(path, label):
    rows = path.read_text(encoding="utf-8").splitlines()
    pattern = re.compile(r"^[A-Za-z0-9_@.-]+\t[^\t\r\n]+\t(?:[a-f0-9]{64}|present|absent)$")
    if not rows or len(rows) != len(set(rows)) or any(not pattern.fullmatch(row) for row in rows):
        fail(f"fingerprint invalido: {label}")


def durable_status(value):
    for child in walk(value):
        if not isinstance(child, dict):
            continue
        work = child.get("work")
        if (child.get("topology") == "clean_pre" and child.get("rebuild") is None
                and isinstance(work, dict) and work.get("pending") == 0
                and work.get("leased") == 0 and work.get("blocked") == 0
                and work.get("error") == "" and isinstance(child.get("docs"), int)
                and child["docs"] > 0 and child.get("eligible") == child["docs"]):
            return
    fail("convergencia durable Search ausente")


def listing(manifest_value, key, expected_path, lower, upper):
    value = json_capture(manifest_value, key)
    if (not isinstance(value, dict) or set(value) != {"source", "path", "captured_at_utc", "entries"}
            or value["source"] != "hostinger-file-manager-ui" or value["path"] != expected_path):
        fail(f"listing invalido: {key}")
    captured = when(value["captured_at_utc"], key)
    if captured < lower or captured > upper:
        fail(f"listing fuera de ventana: {key}")
    banned = re.compile(r"(?i)(\.zip|\.tar|\.tar\.gz|\.tgz|\.7z|\.gz|\.sql|\.bak|\.tmp|\.old|\.partial|\.backup|~)$")
    names = []
    for row in value["entries"]:
        if (not isinstance(row, dict) or set(row) != {"name", "type"}
                or row["type"] not in ("file", "directory") or not isinstance(row["name"], str)
                or not row["name"] or "/" in row["name"] or "\\" in row["name"]
                or row["name"] in names or banned.search(row["name"])):
            fail(f"residuo/entrada remota invalida: {key}")
        names.append(row["name"])
    if not names:
        fail(f"listing vacio: {key}")
    return value["entries"]


def cloudflare(value, binding, require_account):
    for child in walk(value):
        if not isinstance(child, dict) or child.get("success") is not True or child.get("status") not in (200, 201) or child.get("errors") != []:
            continue
        result = child.get("result")
        if isinstance(result, dict) and result.get("id") == binding["zone"]["id"]:
            if not require_account or (result.get("name") == binding["zone"]["name"]
                    and isinstance(result.get("account"), dict)
                    and result["account"].get("id") == binding["account_id"]):
                return
    fail("Cloudflare no prueba zona/binding")


def envelope(manifest_value, key, expected):
    value = json_capture(manifest_value, key)
    if (not isinstance(value, dict) or set(value) != {"tool", "request", "response"}
            or value["tool"] != expected["tool"] or value["request"] != expected["args"]
            or not isinstance(value["response"], dict) or not value["response"]):
        fail(f"envelope invalido: {key}")
    return value["response"]


def reject_failure(value, label):
    for child in walk(value):
        if not isinstance(child, dict):
            continue
        if (child.get("isError") is True
                or ("success" in child and child["success"] is not True)
                or ("ok" in child and child["ok"] is not True)):
            fail(f"fallo explicito: {label}")
        if any(key in child and child[key] not in (None, "", [], {}) for key in ("error", "errors", "Err")):
            fail(f"error material: {label}")
        status_value = child.get("status")
        if (status_value is False
                or isinstance(status_value, int) and not isinstance(status_value, bool) and not 200 <= status_value < 300
                or isinstance(status_value, str) and status_value.lower() in {"error", "failed", "timeout", "timed_out", "denied"}):
            fail(f"status fallido: {label}")


def deploy_response(value, policy):
    if value == policy["allowed_ack_loss_result"]:
        return "ack-loss"
    reject_failure(value, "deploy Theme")
    return "acknowledged"


def pair_capture(key):
    pair_manifest_path = pathlib.Path(handoff["phase1"]["candidate_manifest"]["path"])
    regular(pair_manifest_path, "manifest pareado")
    if sha(pair_manifest_path) != handoff["phase1"]["candidate_manifest"]["sha256"]:
        fail("manifest pareado mutado")
    value = json.loads(pair_manifest_path.read_text(encoding="utf-8"))
    return descriptor(value["captures"].get(key), "pair " + key, pair_manifest_path.parent)


if evidence_dir.resolve() != pathlib.Path(handoff["evidence"]["root"]).resolve() or not evidence_dir.is_dir() or evidence_dir.is_symlink():
    fail("raiz de evidencia Theme invalida")
ready = when(handoff["ready_at_utc"], "handoff ready")

pre_keys = {"cloudflare_binding", "pair_ui_text", "pair_ui_image", "theme_runtime_baseline",
            "business_runtime", "search_runtime", "other_protected_runtime", "commerce",
            "search_protected", "admin_status", "remote_webroot_listing",
            "remote_plugins_listing", "remote_themes_listing", "remote_cleanup_image"}
pre, pre_path = manifest("theme-preflight-evidence.json", "akibara-theme-phase2-production-preflight-evidence")
if set(pre["captures"]) != pre_keys:
    fail("capturas preflight Theme incompletas/extra")
pre_completed = when(pre["completed_at_utc"], "preflight completed")
binding = handoff["operations"]["preflight"]["cloudflare_binding"]["binding"]
cloudflare(json_capture(pre, "cloudflare_binding"), binding, True)
ui_text = descriptor(pre["captures"]["pair_ui_text"], "pair_ui_text").read_text(encoding="utf-8")
if re.search(r"(?i)(_wpnonce|authorization|cookie|token=|password=)", ui_text):
    fail("texto UI preflight contiene secreto")
required_ui = ("akibara.cl", "akibara-business", "1.99.16", "akibara-search", "0.1.14",
               "search_active=true", "akibara-gp", "2.1.69", "theme_active=true",
               "maintenance_mode=false")
if not all(item in ui_text for item in required_ui):
    fail("UI preflight no prueba pair candidate + Theme 2.1.69")
image(descriptor(pre["captures"]["pair_ui_image"], "pair_ui_image"))
inventory(descriptor(pre["captures"]["theme_runtime_baseline"], "theme_runtime_baseline"),
          handoff["evidence"]["baseline"]["theme"], "akibara-gp", "theme baseline")
inventory(descriptor(pre["captures"]["business_runtime"], "business_runtime"),
          handoff["evidence"]["protected_pair"]["business"], "akibara-business", "business")
inventory(descriptor(pre["captures"]["search_runtime"], "search_runtime"),
          handoff["evidence"]["protected_pair"]["search"], "akibara-search", "search")
protected_other(descriptor(pre["captures"]["other_protected_runtime"], "other_protected_runtime"))
fingerprint(descriptor(pre["captures"]["commerce"], "commerce"), "commerce")
fingerprint(descriptor(pre["captures"]["search_protected"], "search_protected"), "search")
durable_status(json_capture(pre, "admin_status"))
if descriptor(pre["captures"]["business_runtime"], "business_runtime").read_bytes() != pair_capture("business_runtime").read_bytes():
    fail("Business preflight Theme no coincide con witness fase 1")
if descriptor(pre["captures"]["search_runtime"], "search_runtime").read_bytes() != pair_capture("search_runtime").read_bytes():
    fail("Search preflight Theme no coincide con witness fase 1")
if descriptor(pre["captures"]["commerce"], "commerce").read_bytes() != pair_capture("commerce").read_bytes():
    fail("commerce preflight Theme no coincide con witness fase 1")
if descriptor(pre["captures"]["search_protected"], "search_protected").read_bytes() != pair_capture("search_protected").read_bytes():
    fail("Search protegido preflight Theme no coincide con witness fase 1")
listing(pre, "remote_webroot_listing", "public_html", ready, pre_completed)
plugin_rows = listing(pre, "remote_plugins_listing", "public_html/wp-content/plugins", ready, pre_completed)
theme_rows = listing(pre, "remote_themes_listing", "public_html/wp-content/themes", ready, pre_completed)
image(descriptor(pre["captures"]["remote_cleanup_image"], "remote_cleanup_image"))
if not all({"name": name, "type": "directory"} in plugin_rows for name in ("akibara-business", "akibara-search")):
    fail("listing plugins preflight no prueba pair")
if {"name": "akibara-gp", "type": "directory"} not in theme_rows:
    fail("listing themes preflight no prueba akibara-gp")

if mode == "preflight":
    raise SystemExit(0)
if mode not in ("candidate", "rollback"):
    fail("purpose Theme invalido")

pre_receipt = regular(evidence_dir / "theme-preflight-verified-receipt.json", "receipt preflight Theme")
sidecar(pre_receipt)
pre_verified = json.loads(pre_receipt.read_text(encoding="utf-8"))
if (set(pre_verified) != {"schema_version", "kind", "release_id", "theme_release_id",
                          "release_digest", "handoff_sha256", "manifest_sha256", "verified_at_utc"}
        or pre_verified.get("schema_version") != 1
        or pre_verified.get("kind") != "akibara-theme-phase2-verified-preflight-receipt"
        or pre_verified.get("release_id") != handoff["release_id"]
        or pre_verified.get("theme_release_id") != handoff["theme_release_id"]
        or pre_verified.get("release_digest") != handoff["release_digest"]
        or pre_verified.get("handoff_sha256") != sha(handoff_path)
        or pre_verified.get("manifest_sha256") != sha(pre_path)):
    fail("receipt preflight Theme invalido")
pre_verified_at = when(pre_verified["verified_at_utc"], "preflight verified")
if pre_verified_at < pre_completed or pre_verified_at - pre_completed > dt.timedelta(seconds=preflight_age):
    fail("receipt preflight Theme fuera de ventana")

op_keys = {"transition_timeline", "theme_deploy_response", "hostinger_cache_response",
           "cloudflare_response", "theme_runtime", "business_runtime", "search_runtime",
           "other_protected_runtime", "commerce", "search_protected", "admin_status",
           "public_har", "ai_audit_report", "ai_audit_stderr", "remote_webroot_listing",
           "remote_plugins_listing", "remote_themes_listing", "remote_cleanup_image"}
op, op_path = manifest(f"theme-{mode}-evidence.json",
                       "akibara-theme-phase2-production-operation-evidence",
                       purpose=mode, age=witness_age)
if set(op["captures"]) != op_keys:
    fail("capturas operación Theme incompletas/extra")
started = when(op["started_at_utc"], "operation started")
completed = when(op["completed_at_utc"], "operation completed")
if started < pre_verified_at or started - pre_verified_at > dt.timedelta(seconds=preflight_age):
    fail("operación Theme fuera de ventana preflight")

expected = handoff["operations"][mode]
timeline = json_capture(op, "transition_timeline")
if (not isinstance(timeline, dict) or set(timeline) != {"purpose", "checkpoints"}
        or timeline.get("purpose") != mode or not isinstance(timeline.get("checkpoints"), list)
        or [checkpoint.get("id") for checkpoint in timeline["checkpoints"]] != expected["sequence"]):
    fail("timeline Theme faltante/extra/reordenado")

if mode == "candidate":
    theme_versions = ["2.1.69", "2.1.69"] + ["2.1.70"] * 9
    maintenance = [False, True, True, True, True, True, True, False, False, False, False]
else:
    theme_versions = [None] + ["2.1.69"] * 9
    maintenance = [True, True, True, True, True, True, False, False, False, False]

last = None
used = set()
cloudflare_time = None
for index, checkpoint in enumerate(timeline["checkpoints"]):
    if set(checkpoint) != {"id", "captured_at_utc", "state", "text", "image"}:
        fail("shape de checkpoint Theme invalida")
    captured = when(checkpoint["captured_at_utc"], checkpoint["id"])
    if captured < started or captured > completed or (last is not None and captured <= last):
        fail("orden temporal checkpoint Theme invalido")
    last = captured
    if checkpoint["id"] == "cloudflare-purged":
        cloudflare_time = captured
    state = checkpoint["state"]
    expected_keys = {"business_version", "search_version", "search_active", "theme_version",
                     "theme_active", "maintenance_mode"}
    if not isinstance(state, dict) or set(state) != expected_keys:
        fail("estado checkpoint Theme invalido")
    if (state["business_version"] != "1.99.16" or state["search_version"] != "0.1.14"
            or state["search_active"] is not True or state["theme_active"] is not True
            or state["maintenance_mode"] is not maintenance[index]):
        fail("pair/estado protegido divergente durante Theme")
    if theme_versions[index] is None:
        if state["theme_version"] not in ("2.1.69", "2.1.70", "unverified"):
            fail("estado inicial rollback Theme invalido")
    elif state["theme_version"] != theme_versions[index]:
        fail("versión Theme divergente en timeline")
    text_path = descriptor(checkpoint["text"], checkpoint["id"] + " text")
    image_path = descriptor(checkpoint["image"], checkpoint["id"] + " image")
    image(image_path)
    if text_path.name in used or image_path.name in used:
        fail("captura reutilizada entre checkpoints Theme")
    used |= {text_path.name, image_path.name}
    raw = text_path.read_text(encoding="utf-8")
    if re.search(r"(?i)(_wpnonce|authorization|cookie|token=|password=)", raw):
        fail("checkpoint Theme contiene secreto")
    required = (checkpoint["id"], "akibara.cl", "1.99.16", "0.1.14",
                f"search_active={str(state['search_active']).lower()}", state["theme_version"],
                f"theme_active={str(state['theme_active']).lower()}",
                f"maintenance_mode={str(state['maintenance_mode']).lower()}")
    if not all(item in raw for item in required):
        fail("texto checkpoint Theme no prueba estado")
    if checkpoint["id"] == "litespeed-ui-purged" and not re.search(r"(?is)litespeed.*(purg|purge).*(persist|guard|success|complet|done)", raw):
        fail("LiteSpeed no prueba purga UI persistida")

response = envelope(op, "theme_deploy_response", expected["deploy_theme"])
deploy_response(response, expected["deploy_theme"]["receipt_policy"])
cache_response = envelope(op, "hostinger_cache_response", expected["hostinger_cache"])
if cache_response != expected["hostinger_cache"]["receipt_policy"]["exact_result"]:
    fail("ACK Hostinger cache divergente")
cf_response = envelope(op, "cloudflare_response", expected["cloudflare"])
reject_failure(cf_response, "Cloudflare")
cloudflare(cf_response, expected["cloudflare"]["binding"], False)

target_spec = handoff["evidence"][mode]["theme"]
inventory(descriptor(op["captures"]["theme_runtime"], "theme_runtime"), target_spec, "akibara-gp", "theme target")
for key, component, slug in (("business_runtime", "business", "akibara-business"),
                             ("search_runtime", "search", "akibara-search")):
    inventory(descriptor(op["captures"][key], key), handoff["evidence"]["protected_pair"][component], slug, key)
    if descriptor(op["captures"][key], key).read_bytes() != descriptor(pre["captures"][key], "pre " + key).read_bytes():
        fail(f"{component} cambió durante Theme")
for key in ("other_protected_runtime", "commerce", "search_protected"):
    if descriptor(op["captures"][key], key).read_bytes() != descriptor(pre["captures"][key], "pre " + key).read_bytes():
        fail(f"superficie protegida cambió: {key}")
protected_other(descriptor(op["captures"]["other_protected_runtime"], "other_protected_runtime"))
fingerprint(descriptor(op["captures"]["commerce"], "commerce"), "commerce after")
fingerprint(descriptor(op["captures"]["search_protected"], "search_protected"), "search after")
durable_status(json_capture(op, "admin_status"))

har = json_capture(op, "public_har")
entries = har.get("log", {}).get("entries", []) if isinstance(har, dict) else []
urls = [entry.get("request", {}).get("url") for entry in entries
        if isinstance(entry, dict) and entry.get("response", {}).get("status") == 200]
if (not any(url == "https://akibara.cl/" for url in urls)
        or not any(isinstance(url, str) and re.fullmatch(r"https://akibara\.cl/manga/?(?:\?.*)?", url) for url in urls)
        or not any(isinstance(url, str) and url.startswith("https://akibara.cl/search/") for url in urls)):
    fail("HAR público Theme incompleto")

audit_path = descriptor(op["captures"]["ai_audit_report"], "ai_audit_report")
audit = json.loads(audit_path.read_text(encoding="utf-8"))
no_secrets(audit)
stderr_path = descriptor(op["captures"]["ai_audit_stderr"], "ai_audit_stderr", allow_empty=True)
if stderr_path.stat().st_size > 65536:
    fail("stderr auditor IA excesivo")
if (audit.get("schema_version") != 3 or audit.get("tool") != "akibara-ai-discoverability-audit"
        or audit.get("base_url") != "https://akibara.cl/"
        or audit.get("fetch_base_url") != "https://akibara.cl/"
        or audit.get("read_only") is not True or audit.get("ranking_guarantee") is not False):
    fail("auditor IA no es público/canónico v3")
configuration = audit.get("configuration", {})
if (configuration.get("business_model_contract") != "online_store_without_public_storefront_v1"
        or configuration.get("expected_pickup_locality") != "San Miguel"
        or configuration.get("strict") is not True
        or configuration.get("expected_ai_train") != "yes"
        or configuration.get("require_return_policy") is not True
        or configuration.get("require_llms_manga") is not True
        or configuration.get("redirect_policy") != "manual_fail_closed"
        or "expected_address" in configuration):
    fail("contrato público IA divergente")
audited_at = when(audit.get("audited_at"), "ai audited_at")
if cloudflare_time is None or audited_at < cloudflare_time or audited_at > completed:
    fail("auditor IA no ocurrió después de Cloudflare")
checks = {item.get("id"): item.get("status") for item in audit.get("checks", []) if isinstance(item, dict)}
truth_checks = ("schema.canonical_online_store", "schema.online_store_without_physical_location",
                "content.about_online_store_truth", "content.public_surfaces_without_physical_location")
if any(checks.get(check_id) != "pass" for check_id in truth_checks):
    fail("auditor IA no prueba tienda online sin ubicación física")
if mode == "candidate":
    summary = audit.get("summary", {})
    if (summary.get("status") != "pass" or summary.get("exit_code") != 0
            or summary.get("fail") != 0 or summary.get("warn") != 0):
        fail("auditor IA candidato no está en PASS estricto")

listing(op, "remote_webroot_listing", "public_html", started, completed)
plugin_rows = listing(op, "remote_plugins_listing", "public_html/wp-content/plugins", started, completed)
theme_rows = listing(op, "remote_themes_listing", "public_html/wp-content/themes", started, completed)
image(descriptor(op["captures"]["remote_cleanup_image"], "remote_cleanup_image"))
if not all({"name": name, "type": "directory"} in plugin_rows for name in ("akibara-business", "akibara-search")):
    fail("listing final plugins no prueba pair protegido")
if {"name": "akibara-gp", "type": "directory"} not in theme_rows:
    fail("listing final themes no prueba akibara-gp")
PY
}

theme_write_sidecar() {
	local path="$1"
	( cd -- "$(dirname -- "${path}")" && shasum -a 256 "$(basename -- "${path}")" > "$(basename -- "${path}").sha256" )
	chmod 0600 "${path}" "${path}.sha256"
}

theme_write_verified_receipt() (
	local handoff="$1" evidence_dir="$2" mode="$3" verified_at="${4:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}"
	local manifest receipt kind partial
	jq -en --arg value "${verified_at}" '$value|fromdateiso8601' >/dev/null \
		|| die 'Timestamp de receipt Theme inválido'
	if [[ "${mode}" == preflight ]]; then
		manifest="${evidence_dir}/theme-preflight-evidence.json"
		receipt="${evidence_dir}/theme-preflight-verified-receipt.json"
		kind="${theme_preflight_receipt_kind}"
	else
		manifest="${evidence_dir}/theme-${mode}-evidence.json"
		receipt="${evidence_dir}/theme-${mode}-verified-receipt.json"
		kind="${theme_operation_receipt_kind}"
	fi
	partial="${receipt}.partial.$$"
	trap 'rm -f -- "${partial}"' EXIT
	jq -n \
		--arg kind "${kind}" --arg release_id "${release_id}" \
		--arg theme_release_id "akibara-gp-2.1.70" --arg digest "${release_digest}" \
		--arg handoff_sha "$(sha256_file "${handoff}")" \
		--arg manifest_sha "$(sha256_file "${manifest}")" --arg time "${verified_at}" \
		--arg mode "${mode}" '
      {schema_version:1,kind:$kind,release_id:$release_id,
       theme_release_id:$theme_release_id,release_digest:$digest,
       handoff_sha256:$handoff_sha,manifest_sha256:$manifest_sha,
       verified_at_utc:$time} +
       (if $mode=="preflight" then {} else {purpose:$mode} end)
    ' > "${partial}"
	chmod 0600 "${partial}"
	mv -- "${partial}" "${receipt}"
	theme_write_sidecar "${receipt}"
)

theme_write_close_candidate() (
	local handoff="$1" evidence_dir="$2" candidate_receipt pair_receipt manifest audit_path close partial
	candidate_receipt="${evidence_dir}/theme-candidate-verified-receipt.json"
	pair_receipt="$(theme_pair_evidence_path)/candidate-verified-receipt.json"
	manifest="${evidence_dir}/theme-candidate-evidence.json"
	audit_path="${evidence_dir}/$(jq -r '.captures.ai_audit_report.path' "${manifest}")"
	close="${evidence_dir}/integrated-close-receipt.json"
	partial="${close}.partial.$$"
	trap 'rm -f -- "${partial}"' EXIT
	jq -n \
		--arg release_id "${release_id}" --arg theme_release_id "akibara-gp-2.1.70" \
		--arg digest "${release_digest}" --arg handoff_path "${handoff}" \
		--arg handoff_sha "$(sha256_file "${handoff}")" \
		--arg pair_path "${pair_receipt}" --arg pair_sha "$(sha256_file "${pair_receipt}")" \
		--arg theme_path "${candidate_receipt}" --arg theme_sha "$(sha256_file "${candidate_receipt}")" \
		--arg audit_path "${audit_path}" --arg audit_sha "$(sha256_file "${audit_path}")" \
		--arg completed "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '
      {schema_version:1,kind:"akibara-ai-discoverability-integrated-production-close-receipt",
       release_id:$release_id,theme_release_id:$theme_release_id,release_digest:$digest,
       handoff:{path:$handoff_path,sha256:$handoff_sha},
       pair_candidate_receipt:{path:$pair_path,sha256:$pair_sha},
       theme_candidate_receipt:{path:$theme_path,sha256:$theme_sha},
       public_ai_audit:{path:$audit_path,sha256:$audit_sha},
       completed_at_utc:$completed,graphify_allowed:false}
    ' > "${partial}"
	chmod 0600 "${partial}"
	mv -- "${partial}" "${close}"
	theme_write_sidecar "${close}"
)

theme_write_close_revocation() (
	local evidence_dir="$1" rollback_receipt revocation partial
	rollback_receipt="${evidence_dir}/theme-rollback-verified-receipt.json"
	revocation="${evidence_dir}/integrated-close-revocation-receipt.json"
	partial="${revocation}.partial.$$"
	trap 'rm -f -- "${partial}"' EXIT
	jq -n --arg release_id "${release_id}" --arg digest "${release_digest}" \
		--arg theme_release_id "akibara-gp-2.1.70" \
		--arg receipt_path "${rollback_receipt}" --arg receipt_sha "$(sha256_file "${rollback_receipt}")" \
		--arg revoked "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '
      {schema_version:1,kind:"akibara-ai-discoverability-integrated-production-close-revocation",
       release_id:$release_id,theme_release_id:$theme_release_id,release_digest:$digest,
       rollback_receipt:{path:$receipt_path,sha256:$receipt_sha},
       revoked_at_utc:$revoked,graphify_allowed:false}
    ' > "${partial}"
	chmod 0600 "${partial}"
	mv -- "${partial}" "${revocation}"
	theme_write_sidecar "${revocation}"
)

theme_verify_preflight_bundle() {
	local handoff="$1" evidence_dir="$2" decision_at preflight_completed
	[[ -d "${evidence_dir}" && ! -L "${evidence_dir}" ]] || die 'Directorio de evidencia Theme inseguro'
	assert_no_symlink_ancestors "${evidence_dir}" || die 'Evidencia Theme bajo symlink'
	theme_verify_handoff_file "${handoff}" \
		|| die 'Handoff Theme inválido al verificar preflight'
	theme_validate_evidence_bundle "${handoff}" "${evidence_dir}" preflight \
		|| die 'Evidencia preflight Theme rechazada'
	decision_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	preflight_completed="$(jq -er '.completed_at_utc | select(type=="string")' "${evidence_dir}/theme-preflight-evidence.json")" \
		|| die 'Timestamp final preflight Theme inválido'
	assert_local_receipt_fresh_at "${decision_at}"
	assert_elapsed_within "${preflight_completed}" "${decision_at}" "${preflight_max_age}" \
		'preflight Theme hasta aceptación'
	theme_write_verified_receipt "${handoff}" "${evidence_dir}" preflight "${decision_at}"
	note 'PASS: preflight material Theme fase 2 validado'
}

theme_verify_witness_bundle() {
	local handoff="$1" evidence_dir="$2" purpose="$3" operation_started preflight_verified
	[[ "${purpose}" == candidate || "${purpose}" == rollback ]] || die 'Purpose Theme inválido'
	[[ -d "${evidence_dir}" && ! -L "${evidence_dir}" ]] || die 'Directorio de evidencia Theme inseguro'
	assert_no_symlink_ancestors "${evidence_dir}" || die 'Evidencia Theme bajo symlink'
	theme_verify_handoff_file "${handoff}" \
		|| die 'Handoff Theme inválido al verificar witnesses'
	theme_validate_evidence_bundle "${handoff}" "${evidence_dir}" "${purpose}" \
		|| die "Evidencia Theme ${purpose} rechazada"
	preflight_verified="$(jq -er '.verified_at_utc | select(type=="string")' "${evidence_dir}/theme-preflight-verified-receipt.json")" \
		|| die 'Timestamp receipt preflight Theme inválido'
	operation_started="$(jq -er '.started_at_utc | select(type=="string")' "${evidence_dir}/theme-${purpose}-evidence.json")" \
		|| die 'Timestamp inicio operación Theme inválido'
	assert_local_receipt_fresh_at "${preflight_verified}"
	assert_local_receipt_fresh_at "${operation_started}"
	theme_write_verified_receipt "${handoff}" "${evidence_dir}" "${purpose}"
	if [[ "${purpose}" == candidate ]]; then
		theme_write_close_candidate "${handoff}" "${evidence_dir}"
		note 'PASS: witness Theme candidato validado; cierre pendiente de verify-close'
	else
		theme_write_close_revocation "${evidence_dir}"
		note 'PASS: witness rollback Theme validado; Graphify permanece prohibido'
	fi
}

theme_verify_close() (
	local handoff="$1" evidence_dir="$2" candidate_receipt close verified partial
	local pair_receipt audit_path candidate_verified close_completed rollback_verified revoked_at
	local rollback_receipt revocation path
	[[ -d "${evidence_dir}" && ! -L "${evidence_dir}" ]] || die 'Directorio de evidencia Theme inseguro'
	theme_verify_handoff_file "${handoff}" \
		|| die 'Handoff Theme inválido al cerrar release integrado'
	theme_validate_evidence_bundle "${handoff}" "${evidence_dir}" candidate \
		|| die 'Evidencia candidata Theme inválida al cerrar release integrado'
	candidate_receipt="${evidence_dir}/theme-candidate-verified-receipt.json"
	close="${evidence_dir}/integrated-close-receipt.json"
	for path in "${candidate_receipt}" "${candidate_receipt}.sha256" "${close}" "${close}.sha256"; do
		verify_regular_file "${path}"
		[[ "$(local_file_mode "${path}")" == 600 ]] || die 'Receipt cierre Theme no está 0600'
	done
	verify_sidecar "${candidate_receipt}"
	verify_sidecar "${close}"
	pair_receipt="$(theme_pair_evidence_path)/candidate-verified-receipt.json"
	audit_path="${evidence_dir}/$(jq -r '.captures.ai_audit_report.path' "${evidence_dir}/theme-candidate-evidence.json")"
	jq -e \
		--arg release_id "${release_id}" --arg digest "${release_digest}" \
		--arg handoff_sha "$(sha256_file "${handoff}")" \
		--arg manifest_sha "$(sha256_file "${evidence_dir}/theme-candidate-evidence.json")" '
      (keys|sort)==["handoff_sha256","kind","manifest_sha256","purpose",
        "release_digest","release_id","schema_version","theme_release_id",
        "verified_at_utc"]
      and .schema_version==1
      and .kind=="akibara-theme-phase2-verified-operation-receipt"
      and .release_id==$release_id and .theme_release_id=="akibara-gp-2.1.70"
      and .release_digest==$digest and .purpose=="candidate"
      and .handoff_sha256==$handoff_sha and .manifest_sha256==$manifest_sha
      and (.verified_at_utc|fromdateiso8601)>0
    ' "${candidate_receipt}" >/dev/null || die 'Receipt candidato Theme divergente'
	jq -e \
		--arg release_id "${release_id}" --arg digest "${release_digest}" \
		--arg handoff "${handoff}" --arg handoff_sha "$(sha256_file "${handoff}")" \
		--arg pair "${pair_receipt}" --arg pair_sha "$(sha256_file "${pair_receipt}")" \
		--arg candidate "${candidate_receipt}" --arg candidate_sha "$(sha256_file "${candidate_receipt}")" \
		--arg audit "${audit_path}" --arg audit_sha "$(sha256_file "${audit_path}")" '
      (keys|sort)==["completed_at_utc","graphify_allowed","handoff","kind",
        "pair_candidate_receipt","public_ai_audit","release_digest","release_id",
        "schema_version","theme_candidate_receipt","theme_release_id"]
      and .schema_version==1
      and .kind=="akibara-ai-discoverability-integrated-production-close-receipt"
      and .release_id==$release_id and .theme_release_id=="akibara-gp-2.1.70"
      and .release_digest==$digest
      and .handoff=={path:$handoff,sha256:$handoff_sha}
      and .pair_candidate_receipt=={path:$pair,sha256:$pair_sha}
      and .theme_candidate_receipt=={path:$candidate,sha256:$candidate_sha}
      and .public_ai_audit=={path:$audit,sha256:$audit_sha}
      and .graphify_allowed==false and (.completed_at_utc|fromdateiso8601)>0
    ' "${close}" >/dev/null || die 'Cierre integrado pendiente divergente'
	candidate_verified="$(jq -er '.verified_at_utc|fromdateiso8601' "${candidate_receipt}")" \
		|| die 'Timestamp del receipt candidato Theme inválido'
	close_completed="$(jq -er '.completed_at_utc|fromdateiso8601' "${close}")" \
		|| die 'Timestamp del cierre integrado pendiente inválido'
	(( close_completed >= candidate_verified )) \
		|| die 'Cierre integrado pendiente anterior al receipt candidato Theme'
	rollback_receipt="${evidence_dir}/theme-rollback-verified-receipt.json"
	revocation="${evidence_dir}/integrated-close-revocation-receipt.json"
	if [[ -e "${rollback_receipt}" || -L "${rollback_receipt}" \
		|| -e "${rollback_receipt}.sha256" || -L "${rollback_receipt}.sha256" ]]; then
		for path in "${rollback_receipt}" "${rollback_receipt}.sha256"; do
			verify_regular_file "${path}"
			[[ "$(local_file_mode "${path}")" == 600 ]] \
				|| die 'Receipt rollback Theme no está 0600'
		done
		verify_sidecar "${rollback_receipt}"
		jq -e \
			--arg release_id "${release_id}" --arg digest "${release_digest}" \
			--arg handoff_sha "$(sha256_file "${handoff}")" \
			--arg manifest_sha "$(sha256_file "${evidence_dir}/theme-rollback-evidence.json")" '
          (keys|sort)==["handoff_sha256","kind","manifest_sha256","purpose",
            "release_digest","release_id","schema_version","theme_release_id",
            "verified_at_utc"]
          and .schema_version==1
          and .kind=="akibara-theme-phase2-verified-operation-receipt"
          and .release_id==$release_id and .theme_release_id=="akibara-gp-2.1.70"
          and .release_digest==$digest and .purpose=="rollback"
          and .handoff_sha256==$handoff_sha and .manifest_sha256==$manifest_sha
          and (.verified_at_utc|fromdateiso8601)>0
        ' "${rollback_receipt}" >/dev/null || die 'Receipt rollback Theme divergente'
		rollback_verified="$(jq -er '.verified_at_utc|fromdateiso8601' "${rollback_receipt}")" \
			|| die 'Timestamp rollback Theme inválido'
		(( rollback_verified < candidate_verified )) \
			|| die 'Un rollback Theme posterior revoca el cierre integrado'
	fi
	if [[ -e "${revocation}" || -L "${revocation}" \
		|| -e "${revocation}.sha256" || -L "${revocation}.sha256" ]]; then
		for path in "${revocation}" "${revocation}.sha256"; do
			verify_regular_file "${path}"
			[[ "$(local_file_mode "${path}")" == 600 ]] \
				|| die 'Revocación de cierre Theme no está 0600'
		done
		verify_sidecar "${revocation}"
		verify_regular_file "${rollback_receipt}"
		jq -e \
			--arg release_id "${release_id}" --arg digest "${release_digest}" \
			--arg receipt_path "${rollback_receipt}" \
			--arg receipt_sha "$(sha256_file "${rollback_receipt}")" '
          (keys|sort)==["graphify_allowed","kind","release_digest","release_id",
            "revoked_at_utc","rollback_receipt","schema_version","theme_release_id"]
          and .schema_version==1
          and .kind=="akibara-ai-discoverability-integrated-production-close-revocation"
          and .release_id==$release_id and .theme_release_id=="akibara-gp-2.1.70"
          and .release_digest==$digest
          and .rollback_receipt=={path:$receipt_path,sha256:$receipt_sha}
          and .graphify_allowed==false and (.revoked_at_utc|fromdateiso8601)>0
        ' "${revocation}" >/dev/null || die 'Revocación de cierre Theme divergente'
		revoked_at="$(jq -er '.revoked_at_utc|fromdateiso8601' "${revocation}")" \
			|| die 'Timestamp de revocación Theme inválido'
		(( revoked_at < candidate_verified )) \
			|| die 'Existe revocación posterior del cierre integrado'
	fi
	verified="${evidence_dir}/integrated-close-verified-receipt.json"
	partial="${verified}.partial.$$"
	trap 'rm -f -- "${partial}"' EXIT
	jq -n --arg release_id "${release_id}" --arg digest "${release_digest}" \
		--arg close_path "${close}" --arg close_sha "$(sha256_file "${close}")" \
		--arg verified_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '
      {schema_version:1,
       kind:"akibara-ai-discoverability-integrated-production-verified-receipt",
       release_id:$release_id,theme_release_id:"akibara-gp-2.1.70",
       release_digest:$digest,close_receipt:{path:$close_path,sha256:$close_sha},
       verified_at_utc:$verified_at,graphify_allowed:true}
    ' > "${partial}"
	chmod 0600 "${partial}"
	mv -- "${partial}" "${verified}"
	theme_write_sidecar "${verified}"
	note "PASS: cierre integrado revalidado; receipt Graphify: ${verified}"
)

theme_show_plan() {
	local config="$1"
	theme_load_context_from_config "${config}"
	cat <<EOF
Release: ${release_id} + akibara-gp-2.1.70
Fase 1 requerida: Business 1.99.16 + Search 0.1.14 candidate-verified
Target exclusivo: public_html/wp-content/themes/akibara-gp
Theme: 2.1.69 (rollback) -> 2.1.70 (candidate), 103 archivos exactos
Transporte: mcp__hostinger_hosting__hosting_deployWordpressTheme
Orden cache: LiteSpeed UI -> Hostinger MCP -> mantenimiento OFF -> Cloudflare MCP
Auditoria final: pública, estricta, tienda online sin ubicación física
Graphify: PROHIBIDO hasta verify-close
Este plan no muta producción.
EOF
}

theme_parse_options() {
	local need_purpose="$1"; shift
	parsed_handoff=''; parsed_evidence=''; parsed_purpose=''
	while (($#)); do
		case "$1" in
			--handoff) (($# >= 2)) || die 'Falta valor --handoff'; parsed_handoff="$2"; shift 2 ;;
			--evidence-dir) (($# >= 2)) || die 'Falta valor --evidence-dir'; parsed_evidence="$2"; shift 2 ;;
			--purpose) (($# >= 2)) || die 'Falta valor --purpose'; parsed_purpose="$2"; shift 2 ;;
			*) die "Opción Theme desconocida: $1" ;;
		esac
	done
	[[ -n "${parsed_handoff}" && -n "${parsed_evidence}" ]] || die 'Faltan handoff/evidence-dir Theme'
	if [[ "${need_purpose}" == yes ]]; then
		[[ -n "${parsed_purpose}" ]] || die 'Falta purpose Theme'
	else
		[[ -z "${parsed_purpose}" ]] || die 'Purpose no aplica'
	fi
}

theme_main() {
	local command="${1:-}" config handoff
	[[ -n "${command}" ]] || { theme_usage >&2; exit 2; }
	shift
	for tool in awk cp find jq python3 shasum stat unzip; do
		command -v "${tool}" >/dev/null || die "Falta ${tool}"
	done
	case "${command}" in
		plan)
			config="$(parse_single_option --config "$@")"
			theme_show_plan "${config}"
			;;
		handoff)
			config="$(parse_single_option --config "$@")"
			theme_prepare_handoff "${config}"
			;;
		verify-handoff)
			handoff="$(parse_single_option --handoff "$@")"
			theme_load_context_from_handoff "${handoff}"
			theme_verify_handoff_file "${handoff}"
			note 'PASS: handoff Theme fase 2 validado'
			;;
		verify-preflight)
			theme_parse_options no "$@"
			theme_load_context_from_handoff "${parsed_handoff}"
			theme_verify_preflight_bundle "${parsed_handoff}" "${parsed_evidence}"
			;;
		verify-witnesses)
			theme_parse_options yes "$@"
			theme_load_context_from_handoff "${parsed_handoff}"
			theme_verify_witness_bundle "${parsed_handoff}" "${parsed_evidence}" "${parsed_purpose}"
			;;
		verify-close)
			theme_parse_options no "$@"
			theme_load_context_from_handoff "${parsed_handoff}"
			theme_verify_close "${parsed_handoff}" "${parsed_evidence}"
			;;
		-h|--help|help) theme_usage ;;
		*) die "Comando Theme desconocido: ${command}" ;;
	esac
}

if [[ "${AKB_THEME_2170_PROMOTION_LIBRARY_ONLY:-0}" != 1 ]]; then
	theme_main "$@"
fi
