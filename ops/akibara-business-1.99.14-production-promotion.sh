#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

# Reutiliza únicamente las primitivas endurecidas y probadas del carril más
# reciente (SSH read-only, mantenimiento, fingerprints y logs). Todas las
# decisiones de alcance, artefactos, instalación, rollback y caché se
# redefinen abajo como Business-only.
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
legacy_library="${script_dir}/akibara-business-blocks-production-promotion.sh"
[[ -f "${legacy_library}" && ! -L "${legacy_library}" ]] || {
	printf 'ERROR: falta el carril endurecido base: %s\n' "${legacy_library}" >&2
	exit 1
}
AKB_BUSINESS_BLOCKS_PROMOTION_LIBRARY_ONLY=1
export AKB_BUSINESS_BLOCKS_PROMOTION_LIBRARY_ONLY
# shellcheck source=ops/akibara-business-blocks-production-promotion.sh
source "${legacy_library}"
unset AKB_BUSINESS_BLOCKS_PROMOTION_LIBRARY_ONLY

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
contract_file="${script_dir}/contracts/akibara-business-1.99.14.json"
config_library="${script_dir}/lib/akibara-promotion-config.sh"
commerce_fingerprint_php="${script_dir}/akibara-protected-commerce-fingerprint.php"
search_fingerprint_php="${script_dir}/akibara-search-state-fingerprint.php"
packager="${repo_root}/tools/akibara-releases/package-business-1.99.14-production.sh"
docker_gate="${repo_root}/tools/akibara-releases/docker-business-1.99.14-production.sh"
source_delta_test="${repo_root}/tools/akibara-releases/test-business-production-source-delta.sh"
promotion_test="${script_dir}/test-akibara-business-1.99.14-production-promotion.sh"
gate_root="${repo_root}/.local-backups/business-1.99.14-production/gates"
handoff_root="${repo_root}/.local-backups/business-1.99.14-production/handoff"

release_id=""
release_digest=""
business_preimage_version=""
business_candidate_version=""
business_preimage_inventory=""
business_candidate_inventory=""
business_candidate_archive=""
business_candidate_archive_sha=""
cloudflare_zone_name=""
cloudflare_zone_id=""
cloudflare_witness_max_age_seconds=0
handoff_state_file=""

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
active_operation=""
active_phase=""
active_ready_at_utc=""

usage() {
	cat <<'EOF'
Uso:
  ops/akibara-business-1.99.14-production-promotion.sh verify-artifacts
  ops/akibara-business-1.99.14-production-promotion.sh local-gate
  ops/akibara-business-1.99.14-production-promotion.sh plan --config FILE
  ops/akibara-business-1.99.14-production-promotion.sh prepare \
    --config FILE --apply --confirm akibara-business-1.99.14
  ops/akibara-business-1.99.14-production-promotion.sh finalize \
    --config FILE --cloudflare-witness FILE --apply \
    --confirm akibara-business-1.99.14
  ops/akibara-business-1.99.14-production-promotion.sh rollback \
    --config FILE --apply --confirm akibara-business-1.99.14
  ops/akibara-business-1.99.14-production-promotion.sh finalize-rollback \
    --config FILE --cloudflare-witness FILE --apply \
    --confirm akibara-business-1.99.14

`prepare` deja WordPress en mantenimiento después de instalar y validar el
candidato. El operador purga Cloudflare con el MCP oficial y entrega a
`finalize` un testigo JSON local, exacto, fresco y sin credenciales.

Seguridad:
  - Flujo único Docker local -> producción; no existe etapa QA/staging.
  - Único target mutable: wp-content/plugins/akibara-business 1.99.10 -> 1.99.14.
  - El ZIP remoto vive en mktemp con trap y el rollback se transmite desde local.
  - Nunca se pasa un token Cloudflare al shell ni se llama su API desde este script.
  - Ningún finalize abre el sitio sin un testigo MCP válido para esa fase.
  - Todo runtime ajeno, comercio y Search deben permanecer idénticos.
  - No modifica contenido, opciones UI, terceros ni Graphify.
EOF
}

load_contract() {
	require_command jq
	verify_regular_file "${contract_file}"
	jq -e '
    .schema_version == 1
    and .contract == "akibara-business-production-release"
    and .release_id == "akibara-business-1.99.14"
    and .seal_state == "sealed"
    and .promotion_sequence == ["docker-local", "production"]
    and (.components | keys) == ["business"]
    and .components.business.kind == "plugin"
    and .components.business.slug == "akibara-business"
    and .components.business.preimage.version == "1.99.10"
    and .components.business.preimage.runtime_files == 221
    and .components.business.remote_baseline.version == "1.99.10"
    and .components.business.remote_baseline.runtime_files == 221
    and .components.business.candidate.version == "1.99.14"
    and .components.business.candidate.runtime_files == 223
    and .components.business.modified_runtime_files == [
      "akibara-business/akibara-business.php",
      "akibara-business/src/Modules/OrderPreventa/ReadyToShipEmail.php",
      "akibara-business/src/Modules/TaxonomyDiscount/DiscountResolver.php",
      "akibara-business/src/Modules/TaxonomyDiscount/Settings.php",
      "akibara-business/src/Plugin.php"
    ]
    and .components.business.added_runtime_files == [
    "akibara-business/src/Modules/TaxonomyDiscount/PriceAuthorityUnavailable.php",
    "akibara-business/src/Modules/TaxonomyDiscount/SearchPriceProvider.php"
  ]
    and .components.business.removed_runtime_files == []
    and (.components.business.source_inputs | length) == 7
    and ([
      .components.business.preimage.archive_sha256,
      .components.business.preimage.inventory_sha256,
      .components.business.remote_baseline.inventory_sha256,
      .components.business.candidate.archive_sha256,
      .components.business.candidate.inventory_sha256,
      .components.business.source_inputs[].sha256
    ] | all(test("^[a-f0-9]{64}$")))
    and .production_invariants.protected_components == [
      {"kind":"plugin","slug":"akibara-cart-checkout-blocks"},
      {"kind":"plugin","slug":"akibara-search"},
      {"kind":"plugin","slug":"bluex-for-woocommerce"},
      {"kind":"theme","slug":"akibara-gp"}
    ]
    and .production_invariants.required_active_plugins == [
      "akibara-business",
      "akibara-cart-checkout-blocks",
      "akibara-search",
      "bluex-for-woocommerce",
      "litespeed-cache",
      "seo-by-rank-math",
      "woocommerce"
    ]
    and .production_invariants.required_active_theme == "akibara-gp"
    and .production_invariants.runtime_outside_business_must_remain_byte_identical == true
    and .production_invariants.commerce_fingerprint_must_remain_identical == true
    and .production_invariants.search_fingerprint_must_remain_identical == true
    and .production_invariants.remote_backups_allowed == false
    and .production_invariants.graphify_during_release_allowed == false
    and .production_invariants.cloudflare_zone_name == "akibara.cl"
    and .production_invariants.cloudflare_zone_id ==
      "b298201b3a38d017bc8413d9fd7414cd"
    and .production_invariants.cloudflare_purge_required == true
    and .production_invariants.cloudflare_purge_transport ==
      "official-mcp-handoff"
    and .production_invariants.cloudflare_witness_max_age_seconds == 900
    and .production_invariants.cloudflare_purge_payload == {
      "purge_everything": true
    }
    and .production_invariants.live_cache_purge_order == ["litespeed", "cloudflare"]
    and .production_invariants.manga_archive_copy == {
      "source": "woocommerce_product_category_description",
      "canonical_clean_first_page": "first_paragraph_above_rest_after_main_content",
      "query_or_page_2": "suppressed",
      "allowed_html": ["p", "a", "strong", "em", "br", "ul", "ol", "li"],
      "faq_schema_added": false,
      "required_viewports": ["375x812", "1440x1000"]
    }
    and .exclusive_targets == [
      "public_html/wp-content/plugins/akibara-business"
    ]
  ' "${contract_file}" >/dev/null || die "Contrato Business 1.99.14 no sellado o divergente"

	release_id="$(jq -r '.release_id' "${contract_file}")"
	business_preimage_version="$(jq -r '.components.business.remote_baseline.version' "${contract_file}")"
	business_candidate_version="$(jq -r '.components.business.candidate.version' "${contract_file}")"
	business_preimage_inventory="$(repo_path "$(jq -r '.components.business.remote_baseline.inventory' "${contract_file}")")"
	business_candidate_inventory="$(repo_path "$(jq -r '.components.business.candidate.inventory' "${contract_file}")")"
	business_candidate_archive="$(repo_path "$(jq -r '.components.business.candidate.archive' "${contract_file}")")"
	business_candidate_archive_sha="$(jq -r '.components.business.candidate.archive_sha256' "${contract_file}")"
	cloudflare_zone_name="$(jq -r '.production_invariants.cloudflare_zone_name' "${contract_file}")"
	cloudflare_zone_id="$(jq -r '.production_invariants.cloudflare_zone_id' "${contract_file}")"
	cloudflare_witness_max_age_seconds="$(jq -r '.production_invariants.cloudflare_witness_max_age_seconds' "${contract_file}")"
	release_digest="$({
		printf 'release\t%s\n' "${release_id}"
		printf 'business\t%s\n' "${business_candidate_archive_sha}"
		printf 'contract\t%s\n' "$(sha256_file "${contract_file}")"
		} | shasum -a 256 | awk '{print $1}')"
	handoff_state_file="${handoff_root}/${release_digest}.json"
}

verify_artifacts() {
	local preimage_archive preimage_archive_sha preimage_inventory_sha
	local candidate_inventory_sha
	for command_name in awk cmp find jq shasum unzip zipinfo; do
		require_command "${command_name}"
	done
	bash "${packager}" --verify-only
	preimage_archive="$(repo_path "$(jq -r '.components.business.preimage.archive' "${contract_file}")")"
	preimage_archive_sha="$(jq -r '.components.business.preimage.archive_sha256' "${contract_file}")"
	preimage_inventory_sha="$(jq -r '.components.business.preimage.inventory_sha256' "${contract_file}")"
	candidate_inventory_sha="$(jq -r '.components.business.candidate.inventory_sha256' "${contract_file}")"

	for file in \
		"${preimage_archive}" "${preimage_archive}.sha256" \
		"${business_preimage_inventory}" \
		"${business_candidate_archive}" "${business_candidate_archive}.sha256" \
		"${business_candidate_inventory}"; do
		verify_regular_file "${file}"
	done
	[[ "$(sha256_file "${preimage_archive}")" == "${preimage_archive_sha}" ]] \
		|| die "ZIP preimagen Business 1.99.10 divergente"
	[[ "$(sha256_file "${business_preimage_inventory}")" == "${preimage_inventory_sha}" ]] \
		|| die "Inventario preimagen Business 1.99.10 divergente"
	[[ "$(sha256_file "${business_candidate_archive}")" == "${business_candidate_archive_sha}" ]] \
		|| die "ZIP candidato Business 1.99.14 divergente"
	[[ "$(sha256_file "${business_candidate_inventory}")" == "${candidate_inventory_sha}" ]] \
		|| die "Inventario candidato Business 1.99.14 divergente"
	(
		cd -- "$(dirname -- "${preimage_archive}")"
		shasum -a 256 -c "$(basename -- "${preimage_archive}.sha256")" >/dev/null
	)
	(
		cd -- "$(dirname -- "${business_candidate_archive}")"
		shasum -a 256 -c "$(basename -- "${business_candidate_archive}.sha256")" >/dev/null
	)
	[[ "$(wc -l < "${business_preimage_inventory}" | tr -d ' ')" == 221 ]] \
		|| die "Preimagen Business no contiene 221 archivos runtime"
	[[ "$(wc -l < "${business_candidate_inventory}" | tr -d ' ')" == 223 ]] \
		|| die "Candidato Business no contiene 223 archivos runtime"
	note "PASS: artefactos exactos Business 1.99.10 -> 1.99.14, cinco modificaciones y dos altas runtime"
}

compute_suite_sha256() {
	local relative absolute file playwright_cli
	local -a files=(
		"ops/akibara-business-1.99.14-production-promotion.sh"
		"ops/akibara-business-blocks-production-promotion.sh"
		"ops/contracts/akibara-business-1.99.14.json"
		"ops/lib/akibara-promotion-config.sh"
		"ops/akibara-protected-commerce-fingerprint.php"
		"ops/akibara-search-state-fingerprint.php"
		"ops/test-akibara-business-1.99.14-production-promotion.sh"
		"tools/akibara-releases/package-business-1.99.14-production.sh"
		"tools/akibara-releases/verify-business-production-source-delta.py"
		"tools/akibara-releases/test-business-production-source-delta.sh"
		"tools/akibara-releases/docker-business-1.99.14-production.sh"
		"tools/akibara-releases/package-runtime.sh"
		"tools/akibara-business/qa.sh"
		"tools/akibara-business/composer.json"
		"tools/akibara-business/composer.lock"
		"tools/akibara-business/phpunit.xml.dist"
	)
	playwright_cli="${AKB_PLAYWRIGHT_CLI:-${HOME}/.codex/skills/playwright/scripts/playwright_cli.sh}"
	{
		printf '@contract\takibara-business-1.99.14-suite-v1\n'
		for relative in "${files[@]}"; do
			absolute="${repo_root}/${relative}"
			[[ -f "${absolute}" && ! -L "${absolute}" ]] \
				|| die "Falta archivo regular de suite: ${relative}"
			printf 'file\t%s\t%s\n' "${relative}" "$(sha256_file "${absolute}")"
		done
		[[ -f "${playwright_cli}" && ! -L "${playwright_cli}" ]] \
			|| die "Falta wrapper Playwright de la suite"
		printf 'external\tplaywright_cli\t%s\n' "$(sha256_file "${playwright_cli}")"
		while IFS= read -r file; do
			relative="${file#"${repo_root}/"}"
			printf 'file\t%s\t%s\n' "${relative}" "$(sha256_file "${file}")"
		done < <(find "${repo_root}/public_html/wp-content/plugins/akibara-business/tests" \
			-type f -print | LC_ALL=C sort)
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
		--arg pipeline_sha256 "${pipeline_sha}" \
		--arg contract_sha256 "${contract_sha}" \
		--arg suite_sha256 "${suite_sha}" \
		--arg target "${target}" \
		--arg completed_at_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '
      {
        schema_version: 1,
        stage: $stage,
        status: "PASS",
        release_id: $release_id,
        release_digest: $release_digest,
        business_sha256: $business_sha256,
        pipeline_sha256: $pipeline_sha256,
        contract_sha256: $contract_sha256,
        suite_sha256: $suite_sha256,
        target: $target,
        completed_at_utc: $completed_at_utc
      }
    ' > "${partial}"
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
		--arg pipeline_sha256 "$(sha256_file "${BASH_SOURCE[0]}")" \
		--arg contract_sha256 "$(sha256_file "${contract_file}")" \
		--arg suite_sha256 "$(compute_suite_sha256)" \
		--arg target "${expected_target}" '
      type == "object"
      and ((keys | sort) == [
        "business_sha256", "completed_at_utc", "contract_sha256",
        "pipeline_sha256", "release_digest", "release_id",
        "schema_version", "stage", "status", "suite_sha256", "target"
      ])
      and .schema_version == 1
      and .stage == $stage
      and .status == "PASS"
      and .release_id == $release_id
      and .release_digest == $release_digest
      and .business_sha256 == $business_sha256
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
	((completed_epoch <= now_epoch + 300)) || die "Recibo ${stage} fechado en el futuro"
	((now_epoch - completed_epoch <= 86400)) || die "Recibo ${stage} vencido"
}

revoke_production_receipt() {
	local receipt sidecar receipt_sha revoked
	receipt="$(receipt_path production)"
	sidecar="${receipt}.sha256"
	if [[ ! -e "${receipt}" && ! -L "${receipt}"
		&& ! -e "${sidecar}" && ! -L "${sidecar}" ]]; then
		return 0
	fi
	[[ -f "${receipt}" && ! -L "${receipt}"
		&& -f "${sidecar}" && ! -L "${sidecar}" ]] \
		|| die "Recibo productivo parcial o inseguro; producción permanece cerrada"
	receipt_sha="$(sha256_file "${receipt}")"
	revoked="${active_evidence_dir}/production-receipt-revoked-${receipt_sha}.json"
	if [[ -e "${revoked}" || -L "${revoked}" ]]; then
		[[ -f "${revoked}" && ! -L "${revoked}" ]] \
			|| die "Evidencia de recibo revocado insegura"
		cmp -s "${receipt}" "${revoked}" \
			|| die "Evidencia de recibo revocado divergente"
	else
		install -m 0600 -- "${receipt}" "${revoked}"
	fi
	shasum -a 256 "${revoked}" > "${revoked}.sha256"
	chmod 0600 "${revoked}.sha256"
	rm -f -- "${receipt}" "${sidecar}"
	[[ ! -e "${receipt}" && ! -L "${receipt}"
		&& ! -e "${sidecar}" && ! -L "${sidecar}" ]] \
		|| die "No fue posible revocar el recibo productivo"
	note "PRODUCTION_RECEIPT_REVOKED=${revoked}"
}

local_file_mode() {
	local path="$1" mode
	if mode="$(stat -f '%Lp' "${path}" 2>/dev/null)"; then
		printf '%s' "${mode}"
	else
		stat -c '%a' "${path}"
	fi
}

validate_release_local_paths() {
	local production_root="${repo_root}/.local-backups/business-1.99.14-production/production"
	case "${active_backup_dir}" in
		"${production_root}/"*) ;;
		*) die "Estado apunta fuera del directorio local de rollback" ;;
	esac
	[[ "${active_backup_dir}" != *'..'* && ! -L "${active_backup_dir}" ]] \
		|| die "Directorio local de rollback inseguro"
	[[ "${active_evidence_dir}" == "${active_backup_dir}/evidence" \
		&& "${code_backup}" == "${active_backup_dir}/code/business-before.tar.gz" ]] \
		|| die "Rutas locales del estado no respetan el contrato"
}

write_handoff_state() {
	local phase="$1" ready_at="${2:-}" partial sidecar
	case "${phase}" in
		preparing|awaiting-cloudflare-candidate|finalizing-candidate|\
		awaiting-cloudflare-rollback|finalizing-rollback|completed|rolled-back) ;;
		*) die "Fase de handoff inválida: ${phase}" ;;
	esac
	[[ -n "${ready_at}" ]] || ready_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	validate_release_local_paths
	mkdir -p -- "${handoff_root}"
	chmod 0700 "${handoff_root}"
	partial="${handoff_state_file}.partial"
	sidecar="${handoff_state_file}.sha256"
	jq -n \
		--arg kind akibara-business-cloudflare-mcp-handoff \
		--arg release_id "${release_id}" \
		--arg release_digest "${release_digest}" \
		--arg phase "${phase}" \
		--arg business_sha256 "${business_candidate_archive_sha}" \
		--arg pipeline_sha256 "$(sha256_file "${BASH_SOURCE[0]}")" \
		--arg contract_sha256 "$(sha256_file "${contract_file}")" \
		--arg suite_sha256 "$(compute_suite_sha256)" \
		--arg host "${AKB_HOST}" \
		--arg webroot "${AKB_WEBROOT}" \
		--arg base_url "${AKB_BASE_URL}" \
		--arg backup_dir "${active_backup_dir}" \
		--arg evidence_dir "${active_evidence_dir}" \
		--arg code_backup "${code_backup}" \
		--arg ready_at_utc "${ready_at}" \
		--arg updated_at_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '
      {
        schema_version: 1,
        kind: $kind,
        release_id: $release_id,
        release_digest: $release_digest,
        phase: $phase,
        business_sha256: $business_sha256,
        pipeline_sha256: $pipeline_sha256,
        contract_sha256: $contract_sha256,
        suite_sha256: $suite_sha256,
        host: $host,
        webroot: $webroot,
        base_url: $base_url,
        backup_dir: $backup_dir,
        evidence_dir: $evidence_dir,
        code_backup: $code_backup,
        ready_at_utc: $ready_at_utc,
        updated_at_utc: $updated_at_utc
      }
    ' > "${partial}"
	chmod 0600 "${partial}"
	mv -- "${partial}" "${handoff_state_file}"
	(
		cd -- "${handoff_root}"
		shasum -a 256 "$(basename -- "${handoff_state_file}")" \
			> "$(basename -- "${sidecar}")"
	)
	chmod 0600 "${sidecar}"
	active_phase="${phase}"
	active_ready_at_utc="${ready_at}"
	note "HANDOFF_STATE=${handoff_state_file}"
}

load_handoff_state() {
	local sidecar="${handoff_state_file}.sha256"
	verify_regular_file "${handoff_state_file}"
	verify_regular_file "${sidecar}"
	[[ "$(local_file_mode "${handoff_state_file}")" == 600 \
		&& "$(local_file_mode "${sidecar}")" == 600 ]] \
		|| die "Estado MCP y sidecar deben tener modo 0600"
	(
		cd -- "${handoff_root}"
		shasum -a 256 -c "$(basename -- "${sidecar}")" >/dev/null
	) || die "Checksum del estado MCP inválido"
	jq -e \
		--arg release_id "${release_id}" \
		--arg release_digest "${release_digest}" \
		--arg business_sha256 "${business_candidate_archive_sha}" \
		--arg pipeline_sha256 "$(sha256_file "${BASH_SOURCE[0]}")" \
		--arg contract_sha256 "$(sha256_file "${contract_file}")" \
		--arg suite_sha256 "$(compute_suite_sha256)" \
		--arg host "${AKB_HOST}" \
		--arg webroot "${AKB_WEBROOT}" \
		--arg base_url "${AKB_BASE_URL}" '
      type == "object"
      and ((keys | sort) == [
        "backup_dir", "base_url", "business_sha256", "code_backup",
        "contract_sha256", "evidence_dir", "host", "kind", "phase",
        "pipeline_sha256", "ready_at_utc", "release_digest", "release_id",
        "schema_version", "suite_sha256", "updated_at_utc", "webroot"
      ])
      and .schema_version == 1
      and .kind == "akibara-business-cloudflare-mcp-handoff"
      and .release_id == $release_id
      and .release_digest == $release_digest
      and .business_sha256 == $business_sha256
      and .pipeline_sha256 == $pipeline_sha256
      and .contract_sha256 == $contract_sha256
      and .suite_sha256 == $suite_sha256
      and .host == $host and .webroot == $webroot and .base_url == $base_url
      and (.phase | IN(
        "preparing", "awaiting-cloudflare-candidate", "finalizing-candidate",
        "awaiting-cloudflare-rollback", "finalizing-rollback",
        "completed", "rolled-back"
      ))
      and (.ready_at_utc | test(
        "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"
      ))
      and (.updated_at_utc | test(
        "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"
      ))
    ' "${handoff_state_file}" >/dev/null || die "Estado MCP rechazado"
	active_phase="$(jq -r '.phase' "${handoff_state_file}")"
	active_ready_at_utc="$(jq -r '.ready_at_utc' "${handoff_state_file}")"
	active_backup_dir="$(jq -r '.backup_dir' "${handoff_state_file}")"
	active_evidence_dir="$(jq -r '.evidence_dir' "${handoff_state_file}")"
	code_backup="$(jq -r '.code_backup' "${handoff_state_file}")"
	validate_release_local_paths
	[[ -d "${active_backup_dir}" && ! -L "${active_backup_dir}" \
		&& -d "${active_evidence_dir}" && ! -L "${active_evidence_dir}" ]] \
		|| die "Directorio local del handoff ausente o inseguro"
	verify_regular_file "${code_backup}"
	verify_regular_file "${code_backup}.sha256"
}

validate_cloudflare_witness() {
	local witness="$1" purpose="$2" allow_stale="${3:-0}"
	local witness_epoch ready_epoch now_epoch
	verify_regular_file "${witness}"
	[[ ! -L "${witness}" ]] || die "Testigo MCP no puede ser enlace"
	jq -e \
		--arg release_id "${release_id}" \
		--arg release_digest "${release_digest}" \
		--arg purpose "${purpose}" \
		--arg zone_id "${cloudflare_zone_id}" \
		--arg zone_name "${cloudflare_zone_name}" '
      type == "object"
      and ((keys | sort) == [
        "completed_at_utc", "kind", "purge", "purpose", "release_digest",
        "release_id", "schema_version", "transport", "zone"
      ])
      and .schema_version == 1
      and .kind == "akibara-cloudflare-purge-witness"
      and .transport == "mcp__cloudflare__execute"
      and .release_id == $release_id
      and .release_digest == $release_digest
      and .purpose == $purpose
      and .zone == {id:$zone_id,name:$zone_name,status:"active"}
      and .purge == {
        request:{purge_everything:true},
        success:true,
        result:{id:$zone_id},
        errors:[]
      }
      and (.completed_at_utc | test(
        "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"
      ))
    ' "${witness}" >/dev/null || die "Testigo MCP ${purpose} rechazado"
	witness_epoch="$(jq -er '.completed_at_utc | fromdateiso8601' "${witness}")"
	ready_epoch="$(date -u -j -f '%Y-%m-%dT%H:%M:%SZ' "${active_ready_at_utc}" +%s 2>/dev/null \
		|| date -u -d "${active_ready_at_utc}" +%s)"
	now_epoch="$(date -u +%s)"
	((witness_epoch >= ready_epoch)) \
		|| die "Testigo MCP anterior a la fase ${active_phase}"
	((witness_epoch <= now_epoch + 300)) || die "Testigo MCP fechado en el futuro"
	if (( ! allow_stale )); then
		((now_epoch - witness_epoch <= cloudflare_witness_max_age_seconds)) \
			|| die "Testigo MCP vencido"
	fi
}

record_cloudflare_witness() {
	local witness="$1" purpose="$2" target
	target="${active_evidence_dir}/cloudflare-mcp-${purpose}.json"
	if [[ -e "${target}" ]]; then
		[[ -f "${target}" && ! -L "${target}" ]] \
			|| die "Evidencia MCP existente insegura"
		cmp -s "${witness}" "${target}" \
			|| die "Se rechaza sustituir evidencia MCP divergente"
	else
		install -m 0600 -- "${witness}" "${target}"
	fi
	shasum -a 256 "${target}" > "${target}.sha256"
}

run_local_gate() {
	verify_artifacts
	bash "${source_delta_test}"
	bash "${docker_gate}"
	bash "${promotion_test}"
	write_receipt docker local-compose
	note "PASS: gate Docker/local Business 1.99.14"
}

verify_remote_business_state() {
	local version="$1" inventory="$2" prefix="$3" remote_script
	printf -v remote_script '
set -Eeuo pipefail
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-business --field=version)" == %q ]]
wp --path="${AKB_WEBROOT}" plugin is-active akibara-business
' "${version}"
	ssh_read "${remote_script}"
	remote_inventory plugin akibara-business "${prefix}.business.files.sha256"
	cmp -s "${inventory}" "${prefix}.business.files.sha256" \
		|| die "Business remoto ${version} difiere del inventario exacto"
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
for plugin in \
  akibara-business akibara-cart-checkout-blocks akibara-search \
  bluex-for-woocommerce litespeed-cache seo-by-rank-math woocommerce; do
  wp --path="${AKB_WEBROOT}" plugin is-active "${plugin}"
done
wp --path="${AKB_WEBROOT}" theme is-active akibara-gp
' "${business_preimage_version}"
	ssh_read "${remote_script}"
}

capture_runtime_invariants() {
	local output="$1" remote_script
	remote_script='
set -Eeuo pipefail
plugins="${AKB_WEBROOT}/wp-content/plugins"
wp --path="${AKB_WEBROOT}" plugin list \
  --fields=name,status,version --format=csv \
  | awk -F, "NR == 1 || \$1 != \"akibara-business\""
wp --path="${AKB_WEBROOT}" theme list \
  --fields=name,status,version --format=csv
for scope in plugins themes; do
  root="${AKB_WEBROOT}/wp-content/${scope}"
  digest="$(
    find "${root}" -type f -print | LC_ALL=C sort | while IFS= read -r file; do
      case "${file}" in
        "${plugins}/akibara-business/"*) continue ;;
      esac
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
target="${plugins}/akibara-business"
[[ -d "${target}" && ! -L "${target}" ]]
test -z "$(find "${target}" ! -type d ! -type f -print -quit)"
test -z "$(find "${target}" -type f \( \
  -name "*.bak" -o -name "*.bk" -o -name "*.orig" -o -name "*.rej" -o \
  -name "*.tmp" -o -name "*.temp" -o -name "*.dump" -o -name "*.backup" -o \
  -name "*.sql" -o -name "*.sqlite" -o -name "*.tar" -o -name "*.tgz" -o \
  -name "*.zip" -o -name "*~" -o -name "._*" -o -name ".DS_Store" \
\) -print -quit)"
test -z "$(find "${plugins}" "${themes}" -maxdepth 1 -type f \( \
  -name "*.bak" -o -name "*.bk" -o -name "*.orig" -o -name "*.rej" -o \
  -name "*.tmp" -o -name "*.temp" -o -name "*.dump" -o -name "*.backup" -o \
  -name "*.sql" -o -name "*.sqlite" -o -name "*.tar" -o -name "*.tgz" -o \
  -name "*.zip" -o -name "*~" -o -name "._*" -o -name ".DS_Store" \
\) -print -quit)"
test -z "$(find "${plugins}" -maxdepth 1 \( \
  -name "akibara-business.previous" -o -name "akibara-business.backup" \
\) -print -quit)"
for upgrade in \
  "${AKB_WEBROOT}/wp-content/upgrade" \
  "${AKB_WEBROOT}/wp-content/upgrade-temp-backup"; do
  if [[ -d "${upgrade}" ]]; then
    test -z "$(find "${upgrade}" -mindepth 1 -print -quit)"
  fi
done
test -z "$(find /tmp -maxdepth 1 -name "akibara-business-1.99.14.*" -print -quit)"
test -z "$(find "${AKB_WEBROOT}" -maxdepth 1 -name ".maintenance.akb.*" -print -quit)"
test -z "$(find "${AKB_WEBROOT}" -maxdepth 1 -type f \( \
  -name "*.sql" -o -name "*.zip" -o -name "*.bak" -o -name "*.tmp" \
\) -print -quit)"
if [[ %q == active ]]; then
  [[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
  printf "%%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${AKB_WEBROOT}/.maintenance"
else
  [[ ! -e "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
fi
echo REMOTE_ARTIFACT_GATE_PASS
' "${state}"
	ssh_read "${remote_script}"
}

stream_code_backup() {
	local output="$1" remote_script entry
	remote_script='
set -Eeuo pipefail
content="${AKB_WEBROOT}/wp-content"
target="${content}/plugins/akibara-business"
[[ -d "${target}" && ! -L "${target}" ]]
test -z "$(find "${target}" ! -type d ! -type f -print -quit)"
tar -C "${content}" -czf - plugins/akibara-business
'
	ssh_read "${remote_script}" > "${output}.partial"
	mv -- "${output}.partial" "${output}"
	verify_regular_file "${output}"
	tar -tzf "${output}" >/dev/null
	while IFS= read -r entry; do
		case "${entry}" in
			plugins/akibara-business|plugins/akibara-business/*) ;;
			*) die "Respaldo local contiene ruta inesperada: ${entry}" ;;
		esac
	done < <(tar -tzf "${output}")
	shasum -a 256 "${output}" > "${output}.sha256"
}

purge_caches_under_maintenance() {
	ssh_read '
set -Eeuo pipefail
[[ -f "${AKB_WEBROOT}/.maintenance" && ! -L "${AKB_WEBROOT}/.maintenance" ]]
wp --path="${AKB_WEBROOT}" cache flush >/dev/null
if wp --path="${AKB_WEBROOT}" plugin is-active litespeed-cache; then
  wp --path="${AKB_WEBROOT}" eval "if (! class_exists(\"LiteSpeed\\\\Purge\")) { throw new RuntimeException(\"LiteSpeed Purge unavailable\"); } \\LiteSpeed\\Purge::purge_all(\"Business 1.99.14 promotion\");" >/dev/null
fi
echo LITESPEED_PURGED_UNDER_MAINTENANCE
'
}

remote_maintenance_status() {
	ssh_read '
set -Eeuo pipefail
file="${AKB_WEBROOT}/.maintenance"
if [[ -f "${file}" && ! -L "${file}" ]] \
  && printf "%s\n" "<?php \$upgrading = time(); ?>" | cmp -s - "${file}"; then
  echo active
elif [[ ! -e "${file}" && ! -L "${file}" ]]; then
  echo absent
else
  exit 1
fi
'
}

remote_business_version() {
	ssh_read 'wp --path="${AKB_WEBROOT}" plugin get akibara-business --field=version'
}

install_component() {
	local slug="$1" version="$2" archive_sha="$3" archive="$4"
	local command remote_script
	[[ "${slug}" == akibara-business ]] \
		|| die "Componente instalable fuera del único allowlist: ${slug}"
	remote_script='
set -Eeuo pipefail
remote_zip="$(mktemp --suffix=.zip /tmp/akibara-business-1.99.14.XXXXXX)"
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
target="${AKB_WEBROOT}/wp-content/plugins/akibara-business"
[[ -d "${target}" && ! -L "${target}" ]]
rm -rf -- "${target}"
tar -C "${AKB_WEBROOT}/wp-content" -xzf -
[[ -d "${target}" && ! -L "${target}" ]]
test -z "$(find "${target}" ! -type d ! -type f -print -quit)"
wp --path="${AKB_WEBROOT}" option update \
  akibara_business_version_installed "${AKB_BUSINESS_VERSION}" >/dev/null
wp --path="${AKB_WEBROOT}" option delete \
  akibara_business_upgrade_tasks_pending >/dev/null 2>&1 || true
[[ "$(wp --path="${AKB_WEBROOT}" plugin get akibara-business --field=version)" == "${AKB_BUSINESS_VERSION}" ]]
wp --path="${AKB_WEBROOT}" plugin is-active akibara-business
'
	printf -v command \
		'AKB_WEBROOT=%q AKB_BUSINESS_VERSION=%q bash -c %q' \
		"${AKB_WEBROOT}" "${business_preimage_version}" "${remote_script}"
	ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 \
		"${AKB_HOST}" "${command}" < "${backup}"
}

verify_http_semantics() {
	local evidence_dir="$1" scratch http_code remote_script route
	scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-1.99.14-http.XXXXXX")"
	trap 'case "${scratch:-}" in */akibara-business-1.99.14-http.*) rm -rf -- "${scratch}" ;; esac' RETURN
	remote_script='
set -Eeuo pipefail
wp --path="${AKB_WEBROOT}" eval "
if (! class_exists(\"\\Akibara\\Business\\Modules\\OrderPreventa\\ReadyToShipEmail\")
    || ! class_exists(\"\\Akibara\\Business\\Modules\\TaxonomyDiscount\\SearchPriceProvider\")) {
    throw new RuntimeException(\"Business 1.99.14 classes unavailable\");
}
echo \"BUSINESS_1_99_10_CLASSES_PASS\\n\";
"
'
	ssh_read "${remote_script}" > "${evidence_dir}/business-classes.txt"
	for route in / /tienda/ /devoluciones/ /manga/ /manga/page/2/; do
		http_code="$(curl --silent --show-error --location \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 45 \
			--retry 2 --retry-delay 2 --retry-all-errors \
			--header 'Cache-Control: no-cache, no-store, max-age=0' \
			--output /dev/null --write-out '%{http_code}' \
			"${AKB_BASE_URL}${route}")"
		[[ "${http_code}" == 200 ]] \
			|| die "HTTP esperaba 200 para ${route}; recibió ${http_code}"
	done
	for request in home returns manga manga-query manga-page-2; do
		case "${request}" in
			home) url="${AKB_BASE_URL}/" ;;
			returns) url="${AKB_BASE_URL}/devoluciones/" ;;
			manga) url="${AKB_BASE_URL}/manga/" ;;
			manga-query) url="${AKB_BASE_URL}/manga/?orderby=price" ;;
			manga-page-2) url="${AKB_BASE_URL}/manga/page/2/" ;;
		esac
		curl --silent --show-error --location \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 45 \
			--header 'Cache-Control: no-cache, no-store, max-age=0' \
			"${url}" > "${scratch}/${request}.html"
	done
	node - "${scratch}" <<'JS'
const fs = require('fs');
const root = process.argv[2];
const read = (name) => fs.readFileSync(`${root}/${name}.html`, 'utf8');
const count = (html, token) => html.split(token).length - 1;
function schemaNodes(html) {
  return [...html.matchAll(/<script[^>]+type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)]
    .flatMap((match) => {
      const value = JSON.parse(match[1]);
      return Array.isArray(value['@graph']) ? value['@graph'] : [value];
    });
}
const owners = schemaNodes(read('home')).filter((node) =>
  node && typeof node === 'object' && 'hasMerchantReturnPolicy' in node
);
if (owners.length !== 1) throw new Error(`home policy owners=${owners.length}`);
const policy = owners[0].hasMerchantReturnPolicy;
if (policy['@type'] !== 'MerchantReturnPolicy'
    || policy.merchantReturnLink !== 'https://akibara.cl/devoluciones/') {
  throw new Error('MerchantReturnPolicy productivo divergente');
}
if (schemaNodes(read('returns')).some((node) =>
  node && typeof node === 'object' && 'hasMerchantReturnPolicy' in node
)) throw new Error('devoluciones duplica MerchantReturnPolicy');
const manga = read('manga');
if (count(manga, 'akb-archive-desc__lead--manga') !== 1
    || count(manga, 'akb-archive-desc--manga') !== 1
    || /FAQPage/i.test(manga)) {
  throw new Error('contrato Manga canónico divergente');
}
for (const name of ['manga-query', 'manga-page-2']) {
  const html = read(name);
  if (count(html, 'akb-archive-desc__lead--manga')
      || count(html, 'akb-archive-desc--manga')
      || /<div class="[^"]*term-description/i.test(html)) {
    throw new Error(`${name} duplica el split canónico`);
  }
}
process.stdout.write('BUSINESS_PRODUCTION_HTTP_SCHEMA_PASS\n');
JS
	jq -n \
		--arg checked_at_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
		'{status:"PASS",checked_at_utc:$checked_at_utc,
      paths:["/","/tienda/","/devoluciones/","/manga/","/manga/page/2/"],
      merchant_return_policy_owners:1,manga_canonical_split:true,
      manga_query_split:false,manga_page_2_split:false,faq_schema_added:false}' \
		> "${evidence_dir}/http-semantics.json"
	shasum -a 256 "${evidence_dir}/http-semantics.json" \
		> "${evidence_dir}/http-semantics.json.sha256"
	trap - RETURN
	rm -rf -- "${scratch}"
}

arm_promotion_traps() {
	trap promotion_exit_handler EXIT
	trap 'promotion_signal_handler 129' HUP
	trap 'promotion_signal_handler 130' INT
	trap 'promotion_signal_handler 143' TERM
}

disarm_promotion_traps() {
	trap - EXIT HUP INT TERM
}

verify_preimage_installed_option() {
	local remote_script
	printf -v remote_script '
set -Eeuo pipefail
[[ "$(wp --path="${AKB_WEBROOT}" option get akibara_business_version_installed)" == %q ]]
' "${business_preimage_version}"
	ssh_read "${remote_script}"
}

verify_release_invariants() {
	local suffix="$1"
	[[ "${suffix}" =~ ^[a-z0-9-]+$ ]] || die "Sufijo de evidencia inseguro"
	capture_runtime_invariants "${active_evidence_dir}/runtime-invariants-${suffix}.tsv"
	cmp -s "${active_evidence_dir}/runtime-invariants-before.tsv" \
		"${active_evidence_dir}/runtime-invariants-${suffix}.tsv" \
		|| die "Runtime ajeno, incluidos Blocks/Search/BlueX/theme, cambió"
	capture_fingerprint commerce "${active_evidence_dir}/commerce-${suffix}.tsv"
	capture_fingerprint search "${active_evidence_dir}/search-${suffix}.tsv"
	cmp -s "${active_evidence_dir}/commerce-before.tsv" \
		"${active_evidence_dir}/commerce-${suffix}.tsv" \
		|| die "Estado comercial cambió durante el release"
	cmp -s "${active_evidence_dir}/search-before.tsv" \
		"${active_evidence_dir}/search-${suffix}.tsv" \
		|| die "Search cambió durante el release"
}

verify_candidate_under_maintenance() {
	local suffix="$1"
	verify_maintenance | tee "${active_evidence_dir}/maintenance-${suffix}.txt"
	cleanup_empty_upgrade_dirs | tee "${active_evidence_dir}/upgrade-cleanup-${suffix}.txt"
	verify_remote_business_state \
		"${business_candidate_version}" "${business_candidate_inventory}" \
		"${active_evidence_dir}/remote-${suffix}"
	verify_installed_option
	verify_release_invariants "${suffix}"
	verify_log_delta "${active_evidence_dir}/logs-before.tsv" \
		"${active_evidence_dir}/logs-${suffix}.tsv"
	remote_artifact_gate active | tee "${active_evidence_dir}/remote-artifact-${suffix}.txt"
}

verify_preimage_under_maintenance() {
	local suffix="$1"
	verify_maintenance | tee "${active_evidence_dir}/maintenance-${suffix}.txt"
	cleanup_empty_upgrade_dirs | tee "${active_evidence_dir}/upgrade-cleanup-${suffix}.txt"
	verify_remote_business_state \
		"${business_preimage_version}" "${business_preimage_inventory}" \
		"${active_evidence_dir}/remote-${suffix}"
	verify_preimage_installed_option
	verify_release_invariants "${suffix}"
	remote_artifact_gate active | tee "${active_evidence_dir}/remote-artifact-${suffix}.txt"
}

verify_rollback_http() {
	local evidence_dir="$1" route http_code
	for route in / /tienda/ /devoluciones/ /manga/ /manga/page/2/; do
		http_code="$(curl --silent --show-error --location \
			--proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time 45 \
			--retry 2 --retry-delay 2 --retry-all-errors \
			--header 'Cache-Control: no-cache, no-store, max-age=0' \
			--output /dev/null --write-out '%{http_code}' \
			"${AKB_BASE_URL}${route}")"
		[[ "${http_code}" == 200 ]] \
			|| die "Rollback esperaba HTTP 200 para ${route}; recibió ${http_code}"
	done
	jq -n --arg checked_at_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
		'{status:"PASS",version:"1.99.10",checked_at_utc:$checked_at_utc,
      paths:["/","/tienda/","/devoluciones/","/manga/","/manga/page/2/"]}' \
		> "${evidence_dir}/rollback-http.json"
	shasum -a 256 "${evidence_dir}/rollback-http.json" \
		> "${evidence_dir}/rollback-http.json.sha256"
}

restore_preimage_pending_cloudflare() {
	local reason="$1" restore_status had_errexit=0
	[[ "${reason}" =~ ^[a-z0-9-]+$ ]] || die "Motivo de rollback inseguro"
	# La restauración puede ejecutarse desde el trap, cuyo caller usa `set +e`
	# para conservar el status original. Se ejecuta el subshell como comando
	# standalone: poner una función en `if`/`||` desactiva errexit dentro de toda
	# su pila en Bash y podría enmascarar un fallo intermedio.
	[[ $- == *e* ]] && had_errexit=1
	maintenance_active=1
	set +e
	(
		set -Eeuo pipefail
		ensure_maintenance
		restore_code "${code_backup}"
		cleanup_empty_upgrade_dirs \
			| tee "${active_evidence_dir}/upgrade-cleanup-rollback-${reason}.txt"
		purge_caches_under_maintenance \
			| tee "${active_evidence_dir}/litespeed-rollback-${reason}.txt"
		verify_preimage_under_maintenance "rollback-${reason}"
		maintenance_http_gate \
			| tee "${active_evidence_dir}/maintenance-http-rollback-${reason}.txt"
		capture_log_baseline "${active_evidence_dir}/logs-rollback-ready.tsv"
		revoke_production_receipt
		write_handoff_state awaiting-cloudflare-rollback
	)
	restore_status=$?
	if ((had_errexit)); then
		set -e
	else
		set +e
	fi
	((restore_status == 0)) || return "${restore_status}"
	active_phase=awaiting-cloudflare-rollback
	active_ready_at_utc="$(jq -er '.ready_at_utc' "${handoff_state_file}")"
	mutation_started=0
	note "ROLLBACK_PENDING_CLOUDFLARE_MCP=1"
}

cleanup_on_failure() {
	local exit_status="$1" rollback_safe=1 restore_status
	set +e
	((exit_status != 0)) || return 0
	[[ -n "${active_operation}" ]] || return 0
	note "Fallo durante operación sellada: restaurando Business y manteniendo producción cerrada."
	# Toda operación armada ya dispone de backup/estado local. Restaurar siempre
	# elimina el borde ambiguo entre "solo mantenimiento" y una mutación parcial,
	# y obliga al segundo purge/testigo MCP antes de cualquier reapertura.
	restore_preimage_pending_cloudflare failure
	restore_status=$?
	((restore_status == 0)) || rollback_safe=0
	if ((!rollback_safe)); then
		note "FAIL-CLOSED: mantenimiento debe permanecer activo; no existe autorización para reabrir."
	fi
	set -e
}

continue_prepare_from_state() {
	local status version
	for snapshot in \
		"${active_evidence_dir}/runtime-invariants-before.tsv" \
		"${active_evidence_dir}/commerce-before.tsv" \
		"${active_evidence_dir}/search-before.tsv" \
		"${active_evidence_dir}/logs-before.tsv"; do
		verify_regular_file "${snapshot}"
	done
	active_operation=prepare
	arm_promotion_traps
	# `preparing` es un estado durable. Al reanudar tras SIGKILL no podemos saber
	# si WP_Upgrader alcanzó a mover archivos, por lo que asumimos mutación hasta
	# demostrar una de las dos imágenes exactas y mantenemos rollback fail-closed.
	mutation_started=1
	status="$(remote_maintenance_status)"
	version="$(remote_business_version)"
	case "${status}" in
		active) maintenance_active=1 ;;
		absent) maintenance_active=0 ;;
		*) die "Estado de mantenimiento remoto inesperado" ;;
	esac
	case "${version}" in
		"${business_preimage_version}")
			verify_remote_business_state \
				"${business_preimage_version}" "${business_preimage_inventory}" \
				"${active_evidence_dir}/remote-preimage-resume"
			if ((!maintenance_active)); then
				maintenance_active=1
				activate_maintenance | tee "${active_evidence_dir}/maintenance-active.txt"
			else
				ensure_maintenance
			fi
			purge_caches_under_maintenance \
				| tee "${active_evidence_dir}/litespeed-before-install.txt"
			maintenance_http_gate \
				| tee "${active_evidence_dir}/maintenance-http-before-install.txt"
			mutation_started=1
			install_component akibara-business \
				"${business_candidate_version}" \
				"${business_candidate_archive_sha}" "${business_candidate_archive}"
			;;
		"${business_candidate_version}")
			mutation_started=1
			if ((!maintenance_active)); then
				maintenance_active=1
				ensure_maintenance
			fi
			;;
		*) die "Versión Business remota fuera del estado reanudable: ${version}" ;;
	esac
	verify_candidate_under_maintenance candidate-ready
	purge_caches_under_maintenance | tee "${active_evidence_dir}/litespeed-candidate-ready.txt"
	maintenance_http_gate | tee "${active_evidence_dir}/maintenance-http-candidate-ready.txt"
	write_handoff_state awaiting-cloudflare-candidate
	mutation_started=0
	active_operation=""
	disarm_promotion_traps
	note "PASS: candidato validado; mantenimiento sigue activo. Purga Cloudflare con MCP oficial."
}

prepare_production() {
	local stamp preimage_prefix
	verify_artifacts
	verify_receipt docker local-compose
	for command_name in cmp curl find grep install jq node sed shasum ssh stat tar; do
		require_command "${command_name}"
	done
	if [[ -e "${handoff_state_file}" || -L "${handoff_state_file}" ]]; then
		load_handoff_state
		case "${active_phase}" in
			preparing) continue_prepare_from_state ;;
			awaiting-cloudflare-candidate)
				verify_candidate_under_maintenance candidate-resume
				note "PASS: prepare idempotente; mantenimiento activo y purge MCP pendiente."
				;;
			finalizing-candidate)
				die "Finalize quedó en curso; reanude con finalize y el mismo testigo MCP"
				;;
			awaiting-cloudflare-rollback|finalizing-rollback)
				die "Rollback pendiente; use finalize-rollback con un testigo MCP nuevo"
				;;
			completed)
				verify_receipt production "${AKB_HOST}:${AKB_WEBROOT}"
				note "PASS: release ya completado"
				;;
			rolled-back) die "Este handoff ya fue revertido" ;;
		esac
		return
	fi
	[[ ! -e "${handoff_state_file}.sha256" && ! -L "${handoff_state_file}.sha256" ]] \
		|| die "Sidecar huérfano del handoff; revisión manual requerida"

	stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"
	active_backup_dir="${repo_root}/.local-backups/business-1.99.14-production/production/${stamp}-${release_digest}"
	active_evidence_dir="${active_backup_dir}/evidence"
	code_backup="${active_backup_dir}/code/business-before.tar.gz"
	mkdir -p -- "${active_backup_dir}/code" "${active_evidence_dir}"
	chmod 0700 "${active_backup_dir}"
	preimage_prefix="${active_evidence_dir}/remote-preimage"

	remote_preflight | tee "${active_evidence_dir}/remote-preflight.txt"
	verify_remote_business_state \
		"${business_preimage_version}" "${business_preimage_inventory}" \
		"${preimage_prefix}"
	capture_runtime_invariants "${active_evidence_dir}/runtime-invariants-before.tsv"
	capture_fingerprint commerce "${active_evidence_dir}/commerce-before.tsv"
	capture_fingerprint search "${active_evidence_dir}/search-before.tsv"
	capture_log_baseline "${active_evidence_dir}/logs-before.tsv"
	cleanup_empty_upgrade_dirs | tee "${active_evidence_dir}/upgrade-cleanup-before-gate.txt"
	remote_artifact_gate absent | tee "${active_evidence_dir}/remote-artifact-gate-before.txt"
	stream_code_backup "${code_backup}"
	write_handoff_state preparing
	continue_prepare_from_state
}

finalize_production() {
	local witness="$1" recorded status ready_at
	verify_artifacts
	verify_receipt docker local-compose
	load_handoff_state
	case "${active_phase}" in
		completed)
			[[ "$(remote_maintenance_status)" == absent ]] \
				|| die "Release completo volvió a mantenimiento"
			verify_remote_business_state \
				"${business_candidate_version}" "${business_candidate_inventory}" \
				"${active_evidence_dir}/remote-completed-resume"
			if [[ -f "$(receipt_path production)" && -f "$(receipt_path production).sha256" ]]; then
				verify_receipt production "${AKB_HOST}:${AKB_WEBROOT}"
			else
				verify_http_semantics "${active_evidence_dir}"
				verify_release_invariants completed-resume
				remote_artifact_gate absent
				write_receipt production "${AKB_HOST}:${AKB_WEBROOT}"
			fi
			note "PASS: finalize idempotente; release ya completado"
			return
			;;
		awaiting-cloudflare-candidate)
			validate_cloudflare_witness "${witness}" candidate
			verify_candidate_under_maintenance pre-finalize
			record_cloudflare_witness "${witness}" candidate
			ready_at="${active_ready_at_utc}"
			write_handoff_state finalizing-candidate "${ready_at}"
			;;
		finalizing-candidate)
			recorded="${active_evidence_dir}/cloudflare-mcp-candidate.json"
			verify_regular_file "${recorded}"
			cmp -s "${witness}" "${recorded}" \
				|| die "Resume finalize exige el mismo testigo MCP registrado"
			validate_cloudflare_witness "${witness}" candidate 1
			;;
		*) die "Fase ${active_phase} no admite finalize" ;;
	esac

	active_operation=finalize
	mutation_started=1
	arm_promotion_traps
	status="$(remote_maintenance_status)"
	verify_remote_business_state \
		"${business_candidate_version}" "${business_candidate_inventory}" \
		"${active_evidence_dir}/remote-finalize"
	case "${status}" in
		active)
			maintenance_active=1
			maintenance_http_gate \
				| tee "${active_evidence_dir}/maintenance-http-after-mcp.txt"
			deactivate_maintenance | tee "${active_evidence_dir}/maintenance-inactive.txt"
			maintenance_active=0
			;;
		absent) maintenance_active=0 ;;
		*) die "Estado de mantenimiento inválido durante finalize" ;;
	esac
	verify_http_semantics "${active_evidence_dir}"
	verify_release_invariants final
	verify_log_delta "${active_evidence_dir}/logs-before.tsv" \
		"${active_evidence_dir}/logs-final.tsv"
	remote_artifact_gate absent | tee "${active_evidence_dir}/remote-artifact-gate-final.txt"
	shasum -a 256 "${active_evidence_dir}/remote-artifact-gate-final.txt" \
		> "${active_evidence_dir}/remote-artifact-gate-final.txt.sha256"
	write_handoff_state completed "${active_ready_at_utc}"
	write_receipt production "${AKB_HOST}:${AKB_WEBROOT}"
	mutation_started=0
	active_operation=""
	disarm_promotion_traps
	note "PASS: Business 1.99.14 en producción; evidencia local: ${active_backup_dir}"
}

rollback_prepared_release() {
	local status
	verify_artifacts
	load_handoff_state
	case "${active_phase}" in
		awaiting-cloudflare-rollback|finalizing-rollback)
			verify_preimage_under_maintenance rollback-resume
			note "PASS: rollback idempotente; purge MCP de rollback pendiente"
			return
			;;
		rolled-back)
			note "PASS: rollback ya completado"
			return
			;;
		preparing|awaiting-cloudflare-candidate|finalizing-candidate|completed) ;;
		*) die "Fase ${active_phase} no admite rollback" ;;
	esac
	active_operation=rollback
	mutation_started=1
	arm_promotion_traps
	status="$(remote_maintenance_status)"
	if [[ "${status}" == absent ]]; then
		maintenance_active=1
		ensure_maintenance
	elif [[ "${status}" == active ]]; then
		maintenance_active=1
	else
		die "Estado de mantenimiento inválido antes del rollback"
	fi
	restore_preimage_pending_cloudflare explicit
	active_operation=""
	disarm_promotion_traps
	note "PASS: preimagen restaurada; mantenimiento activo y purge MCP de rollback pendiente"
}

finalize_rollback() {
	local witness="$1" recorded status ready_at
	verify_artifacts
	load_handoff_state
	case "${active_phase}" in
		rolled-back)
			[[ "$(remote_maintenance_status)" == absent ]] \
				|| die "Rollback completo volvió a mantenimiento"
			verify_remote_business_state \
				"${business_preimage_version}" "${business_preimage_inventory}" \
				"${active_evidence_dir}/remote-rolled-back-resume"
			note "PASS: finalize-rollback idempotente"
			return
			;;
		awaiting-cloudflare-rollback)
			validate_cloudflare_witness "${witness}" rollback
			verify_preimage_under_maintenance pre-finalize-rollback
			record_cloudflare_witness "${witness}" rollback
			ready_at="${active_ready_at_utc}"
			write_handoff_state finalizing-rollback "${ready_at}"
			;;
		finalizing-rollback)
			recorded="${active_evidence_dir}/cloudflare-mcp-rollback.json"
			verify_regular_file "${recorded}"
			cmp -s "${witness}" "${recorded}" \
				|| die "Resume finalize-rollback exige el mismo testigo MCP"
			validate_cloudflare_witness "${witness}" rollback 1
			;;
		*) die "Fase ${active_phase} no admite finalize-rollback" ;;
	esac

	active_operation=finalize-rollback
	mutation_started=1
	arm_promotion_traps
	status="$(remote_maintenance_status)"
	verify_remote_business_state \
		"${business_preimage_version}" "${business_preimage_inventory}" \
		"${active_evidence_dir}/remote-finalize-rollback"
	case "${status}" in
		active)
			maintenance_active=1
			maintenance_http_gate \
				| tee "${active_evidence_dir}/maintenance-http-after-mcp-rollback.txt"
			deactivate_maintenance | tee "${active_evidence_dir}/maintenance-inactive-rollback.txt"
			maintenance_active=0
			;;
		absent) maintenance_active=0 ;;
		*) die "Estado de mantenimiento inválido durante finalize-rollback" ;;
	esac
	verify_rollback_http "${active_evidence_dir}"
	verify_release_invariants rollback-final
	verify_log_delta "${active_evidence_dir}/logs-rollback-ready.tsv" \
		"${active_evidence_dir}/logs-rollback-final.tsv"
	remote_artifact_gate absent \
		| tee "${active_evidence_dir}/remote-artifact-gate-rollback-final.txt"
	write_handoff_state rolled-back "${active_ready_at_utc}"
	mutation_started=0
	active_operation=""
	disarm_promotion_traps
	note "PASS: rollback 1.99.10 reabierto solo después del segundo purge MCP"
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
Cloudflare: MCP oficial, zona ${cloudflare_zone_id}; cero token shell.

Secuencia fail-closed:
  1. prepare exige recibo Docker <24h, preimagen 1.99.10 exacta y backup local.
  2. Activa mantenimiento 503, instala solo Business 1.99.14, valida todo y
     purga LiteSpeed; el sitio permanece cerrado en awaiting-cloudflare-candidate.
  3. El operador purga Cloudflare con mcp__cloudflare__execute y guarda un
     testigo local exacto/fresco, sin token ni secreto.
  4. finalize valida ese testigo antes de abrir, comprueba HTTP/schema/logs y
     cero artefactos, y recién entonces emite el recibo productivo.
  5. Todo rollback restaura desde local pero permanece cerrado hasta un segundo
     purge MCP y finalize-rollback; ningún fallo de caché puede reabrir solo.

No se instala Search, Blocks, theme, BlueX ni terceros. No se modifica contenido,
configuración UI ni Graphify. Toda evidencia y rollback permanecen solo en local.
EOF
}

if [[ "${AKB_BUSINESS_19910_PROMOTION_LIBRARY_ONLY:-0}" == 1 ]]; then
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
cloudflare_witness=""
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
		--cloudflare-witness)
			(($# >= 2)) || die "Falta valor para --cloudflare-witness"
			cloudflare_witness="$2"
			shift 2
			;;
		--confirm)
			(($# >= 2)) || die "Falta valor para --confirm"
			confirm="$2"
			shift 2
			;;
		*) die "Opción desconocida: $1" ;;
	esac
done

case "${command_name}" in
	verify-artifacts)
		[[ -z "${config_file}${cloudflare_witness}${confirm}" && "${apply}" == 0 ]] \
			|| die "verify-artifacts no acepta opciones"
		verify_artifacts
		;;
	local-gate)
		[[ -z "${config_file}${cloudflare_witness}${confirm}" && "${apply}" == 0 ]] \
			|| die "local-gate no acepta opciones"
		run_local_gate
		;;
	plan)
		[[ -n "${config_file}" && "${apply}" == 0 \
			&& -z "${cloudflare_witness}${confirm}" ]] \
			|| die "plan requiere solo --config FILE"
		load_environment "${config_file}"
		render_plan
		;;
	prepare|rollback)
		[[ -n "${config_file}" && -z "${cloudflare_witness}" && "${apply}" == 1 ]] \
			|| die "${command_name} requiere --config FILE y --apply"
		[[ "${confirm}" == "${release_id}" ]] \
			|| die "Confirmación exacta requerida: ${release_id}"
		load_environment "${config_file}"
		if [[ "${command_name}" == prepare ]]; then
			prepare_production
		else
			rollback_prepared_release
		fi
		;;
	finalize|finalize-rollback)
		[[ -n "${config_file}" && -n "${cloudflare_witness}" && "${apply}" == 1 ]] \
			|| die "${command_name} requiere config, testigo MCP y --apply"
		[[ "${confirm}" == "${release_id}" ]] \
			|| die "Confirmación exacta requerida: ${release_id}"
		load_environment "${config_file}"
		if [[ "${command_name}" == finalize ]]; then
			finalize_production "${cloudflare_witness}"
		else
			finalize_rollback "${cloudflare_witness}"
		fi
		;;
	*) die "Comando desconocido: ${command_name}" ;;
esac
