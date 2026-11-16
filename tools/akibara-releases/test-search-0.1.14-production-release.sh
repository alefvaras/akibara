#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
packager="${script_dir}/package-search-0.1.14-production.sh"
runtime_packager="${script_dir}/package-runtime.sh"
contract="${repo_root}/ops/contracts/akibara-search-0.1.14.json"
business_contract="${repo_root}/ops/contracts/akibara-business-1.99.16.json"
theme_contract="${repo_root}/ops/contracts/akibara-gp-2.1.70.json"
source_root="${repo_root}/public_html/wp-content/plugins/akibara-search"
recovery_rail="${script_dir}/search-0.1.14-recovery-cycle.sh"
recovery_proof="${script_dir}/search-0.1.14-recovery-proof.php"
http_smoke="${script_dir}/search-0.1.14-http-smoke.php"
integrated_mariadb="${source_root}/tests/live-write-ledger-integration.php"
protected_fingerprint="${script_dir}/search-0.1.14-protected-fingerprint.sql"
check_table_validator="${script_dir}/search-0.1.14-check-table.awk"
recovery_test="${script_dir}/test-search-0.1.14-recovery-cycle.sh"
recovery_runbook="${repo_root}/ops/akibara-search-0.1.14-recovery-runbook.md"
local_index_guard="${repo_root}/public_html/wp-content/mu-plugins/akibara-local-external-indexing-guard.php"
local_index_guard_test="${repo_root}/tools/test-local-external-indexing-guard.php"
theme_release_test="${script_dir}/test-theme-2.1.70-production-release.sh"
theme_phpunit_config="${repo_root}/tools/akibara-gp/phpunit.xml.dist"
ai_audit_engine="${repo_root}/tools/akibara-ai-discoverability-audit.mjs"
ai_audit_test="${repo_root}/tools/test-ai-discoverability-audit.mjs"
ai_audit_wrapper="${repo_root}/tools/akibara-ai-discoverability-audit.sh"
ai_audit_policy="${repo_root}/tools/lib/ai-discoverability-policy.mjs"
ai_audit_runbook="${repo_root}/ops/akibara-ai-discoverability-audit-runbook.md"
apache_image='wordpress@sha256:b65f6fb4d8b669974d4e8d6f204bc5a11d67f4877473ba5ee97af0efd39e3d19'
node_image='node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b'

die() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

sha256_file() {
	shasum -a 256 "$1" | awk '{print $1}'
}

tree_digest() {
	local root="$1"
	[[ -d "${root}" && ! -L "${root}" ]] || die "árbol protegido ausente: ${root}"
	(
		cd -- "${root}"
		find . -type f -print0 \
			| LC_ALL=C sort -z \
			| xargs -0 shasum -a 256 \
			| shasum -a 256 \
			| awk '{print $1}'
	)
}

for command_name in bash cmp find jq ln node php rsync shasum unlink unzip; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| die "falta ${command_name}"
done
for required in \
	"${packager}" \
	"${runtime_packager}" \
	"${contract}" \
	"${business_contract}" \
	"${theme_contract}" \
	"${recovery_rail}" \
	"${recovery_proof}" \
	"${http_smoke}" \
	"${integrated_mariadb}" \
	"${protected_fingerprint}" \
	"${check_table_validator}" \
	"${recovery_test}" \
	"${recovery_runbook}" \
	"${local_index_guard}" \
	"${local_index_guard_test}" \
	"${theme_release_test}" \
	"${theme_phpunit_config}" \
	"${ai_audit_engine}" \
	"${ai_audit_test}" \
	"${ai_audit_wrapper}" \
	"${ai_audit_policy}" \
	"${ai_audit_runbook}"; do
	[[ -f "${required}" && ! -L "${required}" && -s "${required}" ]] \
		|| die "falta archivo regular: ${required}"
done
bash -n "${packager}"
jq -e . "${contract}" >/dev/null || die 'JSON contractual inválido'
jq -e '
  .production_invariants.deployment.receipt_policy == {
    confirmed_success_profile:null,
    allowed_ack_loss_result:{
      Err:"tool call error: tool call failed for `hostinger-hosting/hosting_deployWordpressPlugin`\n\nCaused by:\n    timed out awaiting tools/call after 120s"
	    },
	    accept_only_with_exact_postconditions:[
	      "wordpress-ui-search-inactive-midpoint","wordpress-active-pair-versions",
	      "business-runtime-inventory","search-runtime-inventory","complete-witness-bundle"
	    ]
  }
  and .production_invariants.hostinger_cache.receipt_policy == {
    exact_result:{content:[{type:"text",text:"{\"message\":\"Request accepted\"}"}]}
  }
	and .integrated_theme_binding.required == true
	and .integrated_theme_binding.theme_contract_path == "ops/contracts/akibara-gp-2.1.70.json"
	and .integrated_theme_binding.theme_version == "2.1.70"
	and .integrated_theme_binding.theme_rollback_version == "2.1.69"
	and .integrated_theme_binding.required_passes == 2
	and .integrated_ai_audit.required == true
	and .integrated_ai_audit.schema_version == 3
	and .integrated_ai_audit.base_url == "https://akibara.cl/"
	and .integrated_ai_audit.fetch_scope == "docker-internal-only"
	and .integrated_ai_audit.redirect_policy == "manual_fail_closed"
	and .integrated_ai_audit.external_network_allowed == false
	and .production_invariants.recovery_rollback.images.node ==
	  "node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b"
	and .production_invariants.recovery_rollback.immutable_release_binding.contracts_evidence_and_fourteen_artifacts_included == true
' "${contract}" >/dev/null || die 'perfiles Hostinger contractuales incompletos'

contract_before="$(sha256_file "${contract}")"
declare -A protected_before=()
for protected in \
	"public_html/wp-content/plugins/akibara-business" \
	"public_html/wp-content/plugins/akibara-cart-checkout-blocks" \
	"public_html/wp-content/plugins/bluex-for-woocommerce" \
	"public_html/wp-content/themes/akibara-gp"; do
	protected_before["${protected}"]="$(tree_digest "${repo_root}/${protected}")"
done

seal_state="$(jq -r '.seal_state' "${contract}")"
case "${seal_state}" in
	draft)
		jq -e '
          .sealed_at_utc == null
          and .components.search.candidate.archive_sha256 == null
          and .components.search.candidate.inventory_sha256 == null
          and .components.search.candidate.runtime_files == null
          and .components.search.modified_runtime_files == null
          and .components.search.added_runtime_files == null
	          and .components.search.removed_runtime_files == null
	          and .components.search.source_inputs == null
	          and .production_invariants.recovery_rollback.http_smoke.script_sha256 == null
	          and .production_invariants.recovery_rollback.integrated_mariadb.script_sha256 == null
	          and .paired_release.business_contract_sha256 == null
        ' "${contract}" >/dev/null \
			|| die 'draft contiene valores candidatos parciales'
		if bash "${packager}" --verify-only >/dev/null 2>&1; then
			die 'verify-only aceptó contrato draft'
		fi
		if bash "${packager}" >/dev/null 2>&1; then
			die 'publicación aceptó contrato draft'
		fi
		;;
	sealed) ;;
	*) die "seal_state inesperado: ${seal_state}" ;;
esac

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-search-0.1.14-release-test.XXXXXX")"
scratch="$(cd -- "$(dirname -- "${scratch}")" && printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${scratch}")")"
cleanup_done=0
cleanup() {
	if ((cleanup_done)); then
		return
	fi
	cleanup_done=1
	case "${scratch:-}" in
		*/akibara-search-0.1.14-release-test.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

fixture_root="${scratch}/fixture-repo"
fixture_tools="${fixture_root}/tools/akibara-releases"
fixture_contract_dir="${fixture_root}/ops/contracts"
fixture_dist="${fixture_root}/dist"
fixture_source="${fixture_root}/public_html/wp-content/plugins/akibara-search"
fixture_contract="${fixture_contract_dir}/akibara-search-0.1.14.json"
fixture_business_contract="${fixture_contract_dir}/akibara-business-1.99.16.json"
fixture_theme_contract="${fixture_contract_dir}/akibara-gp-2.1.70.json"
fixture_guard_dir="${fixture_root}/public_html/wp-content/mu-plugins"
fixture_http_smoke="${fixture_tools}/search-0.1.14-http-smoke.php"
fixture_integrated_mariadb="${fixture_source}/tests/live-write-ledger-integration.php"
mkdir -p -- "${fixture_tools}" "${fixture_contract_dir}" "${fixture_dist}" "${fixture_source}" "${fixture_guard_dir}"
cp -- "${packager}" "${fixture_tools}/package-search-0.1.14-production.sh"
cp -- "${runtime_packager}" "${fixture_tools}/package-runtime.sh"
cp -- "${repo_root}/compose.yaml" "${fixture_root}/compose.yaml"
cp -- "${contract}" "${fixture_contract}"
cp -- "${business_contract}" "${fixture_business_contract}"
cp -- "${theme_contract}" "${fixture_theme_contract}"
cp -- "${recovery_rail}" "${fixture_tools}/search-0.1.14-recovery-cycle.sh"
cp -- "${recovery_proof}" "${fixture_tools}/search-0.1.14-recovery-proof.php"
cp -- "${http_smoke}" "${fixture_http_smoke}"
cp -- "${protected_fingerprint}" "${fixture_tools}/search-0.1.14-protected-fingerprint.sql"
cp -- "${check_table_validator}" "${fixture_tools}/search-0.1.14-check-table.awk"
cp -- "${recovery_test}" "${fixture_tools}/test-search-0.1.14-recovery-cycle.sh"
cp -- "${recovery_runbook}" "${fixture_root}/ops/akibara-search-0.1.14-recovery-runbook.md"
cp -- "${local_index_guard}" "${fixture_guard_dir}/akibara-local-external-indexing-guard.php"
cp -- "${local_index_guard_test}" "${fixture_root}/tools/test-local-external-indexing-guard.php"
while IFS= read -r integrated_relative; do
	[[ -n "${integrated_relative}" && "${integrated_relative}" != /* && "${integrated_relative}" != *'..'* ]] \
		|| die "ruta fixture integrada insegura: ${integrated_relative}"
	mkdir -p -- "${fixture_root}/$(dirname -- "${integrated_relative}")"
	cp -- "${repo_root}/${integrated_relative}" "${fixture_root}/${integrated_relative}"
done < <(
	{
		jq -r '[.integrated_ai_audit.engine_path,.integrated_ai_audit.test_path,.integrated_ai_audit.wrapper_path,.integrated_ai_audit.policy_path,.integrated_ai_audit.runbook_path][]' "${contract}"
		jq -r '[.component.preimage.archive,.component.preimage.inventory,.component.preimage.checksum_sidecar,.component.candidate.archive,.component.candidate.inventory,.component.candidate.checksum_sidecar,.quality_gates.static_contract.path,.quality_gates.phpunit_theme.config,(.quality_gates.node_theme.test_files[].path),(.quality_gates.phpunit_theme.test_files[].path)][]' "${theme_contract}"
	} | LC_ALL=C sort -u
)
jq --arg apache_image "${apache_image}" --arg node_image "${node_image}" '
  .seal_state="draft"
  | .sealed_at_utc=null
  | .components.search.candidate.archive_sha256=null
  | .components.search.candidate.inventory_sha256=null
  | .components.search.candidate.runtime_files=null
  | .components.search.modified_runtime_files=null
  | .components.search.added_runtime_files=null
	  | .components.search.removed_runtime_files=null
	  | .components.search.source_inputs=null
	  | .production_invariants.recovery_rollback.images.apache=$apache_image
	  | .production_invariants.recovery_rollback.images.node=$node_image
	  | .production_invariants.recovery_rollback.http_smoke={
	      script:"tools/akibara-releases/search-0.1.14-http-smoke.php",
	      script_sha256:null,
	      receipt_schema:"akibara-search-http-smoke-v1",
	      network_internal:true,
	      published_ports:0,
	      host_header:"localhost:8080",
	      follow_redirects:false,
	      required_per_fresh_restore:true,
	      protected_surfaces_byte_identical:true
	    }
	  | .production_invariants.recovery_rollback.integrated_mariadb={
	      script:"public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php",
	      script_sha256:null,
	      modes:["baseline","candidate"],
	      required_per_fresh_restore:true,
	      provider_active_required:true,
	      reconciler_zero_divergence_required:true
	    }
	  | .paired_release.business_contract_sha256=null
' "${fixture_contract}" > "${fixture_contract}.draft"
mv -- "${fixture_contract}.draft" "${fixture_contract}"
cp -- \
	"${repo_root}/dist/akibara-search-0.1.12.zip" \
	"${repo_root}/dist/akibara-search-0.1.12.zip.sha256" \
	"${repo_root}/dist/akibara-search-0.1.12.files.sha256" \
	"${fixture_dist}/"
rsync -a --delete "${source_root}/" "${fixture_source}/"
for fixture_recovery_artifact in "${fixture_http_smoke}" "${fixture_integrated_mariadb}"; do
	[[ -f "${fixture_recovery_artifact}" && ! -L "${fixture_recovery_artifact}" && -s "${fixture_recovery_artifact}" ]] \
		|| die "fixture recovery ausente o inseguro: ${fixture_recovery_artifact}"
done

# El parser inspecciona rutas/tipos/conjunto exacto ANTES de cualquier unzip.
zip_attack_dir="${scratch}/zip-attacks"
mkdir -p -- "${zip_attack_dir}"
zip_safe_payload="${zip_attack_dir}/safe.php"
printf '<?php // safe\n' > "${zip_safe_payload}"
zip_safe_sha="$(sha256_file "${zip_safe_payload}")"
zip_inventory="${zip_attack_dir}/fixture.files.sha256"
printf '%s  %s\n' "${zip_safe_sha}" 'akibara-search/akibara-search.php' > "${zip_inventory}"

python3 - "${zip_attack_dir}/zip-slip.zip" "${zip_safe_payload}" <<'PY'
import pathlib
import sys
import zipfile

archive = pathlib.Path(sys.argv[1])
payload = pathlib.Path(sys.argv[2]).read_bytes()
with zipfile.ZipFile(archive, "w") as bundle:
    bundle.writestr("akibara-search/akibara-search.php", payload)
    bundle.writestr("akibara-search/../../escaped.php", b"escape")
PY
if (
	AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY=1
	export AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY
	# shellcheck source=tools/akibara-releases/package-search-0.1.14-production.sh
	source "${packager}"
	verify_archive_inventory "${zip_attack_dir}/zip-slip.zip" "${zip_inventory}" akibara-search
) >/dev/null 2>&1; then
	die 'parser aceptó ZIP-slip'
fi
[[ ! -e "${zip_attack_dir}/escaped.php" ]] || die 'ataque ZIP-slip escribió antes del gate'

python3 - "${zip_attack_dir}/extra.zip" "${zip_safe_payload}" <<'PY'
import pathlib
import sys
import zipfile

archive = pathlib.Path(sys.argv[1])
payload = pathlib.Path(sys.argv[2]).read_bytes()
with zipfile.ZipFile(archive, "w") as bundle:
    bundle.writestr("akibara-search/akibara-search.php", payload)
    bundle.writestr("akibara-search/extra.php", b"extra")
PY
if (
	AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY=1
	export AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY
	# shellcheck source=tools/akibara-releases/package-search-0.1.14-production.sh
	source "${packager}"
	verify_archive_inventory "${zip_attack_dir}/extra.zip" "${zip_inventory}" akibara-search
) >/dev/null 2>&1; then
	die 'parser aceptó archivo ZIP extra fuera del inventario'
fi

exact_tree="${zip_attack_dir}/tree"
mkdir -p -- "${exact_tree}/akibara-search"
cp -- "${zip_safe_payload}" "${exact_tree}/akibara-search/akibara-search.php"
printf 'extra\n' > "${exact_tree}/akibara-search/extra.php"
if (
	AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY=1
	export AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY
	# shellcheck source=tools/akibara-releases/package-search-0.1.14-production.sh
	source "${packager}"
	verify_exact_inventory_tree "${exact_tree}" "${zip_inventory}" akibara-search
) >/dev/null 2>&1; then
	die 'árbol extraído aceptó archivo extra fuera del inventario'
fi

fixture_packager="${fixture_tools}/package-search-0.1.14-production.sh"
proposal="${scratch}/proposal.json"
TZ=UTC bash "${fixture_packager}" --derive-only > "${proposal}"
jq -e --arg apache_image "${apache_image}" '
  .seal_state == "draft-proposal"
  and .candidate.version == "0.1.14"
  and (.candidate.archive_sha256 | test("^[a-f0-9]{64}$"))
  and (.candidate.inventory_sha256 | test("^[a-f0-9]{64}$"))
  and (.candidate.runtime_files | type == "number" and floor == . and . > 0)
  and (.modified_runtime_files | type == "array")
  and (.added_runtime_files | type == "array")
  and (.removed_runtime_files | type == "array")
  and (.source_inputs | type == "array")
  and ((.source_inputs | map(.path) | sort) ==
    ((.modified_runtime_files + .added_runtime_files) | sort))
	and .recovery_rollback.images == {apache:$apache_image}
	and (
	  .recovery_rollback.http_smoke as $http
	  | $http == {
	      script:"tools/akibara-releases/search-0.1.14-http-smoke.php",
	      script_sha256:$http.script_sha256,
	      receipt_schema:"akibara-search-http-smoke-v1",
	      network_internal:true,
	      published_ports:0,
	      host_header:"localhost:8080",
	      follow_redirects:false,
	      required_per_fresh_restore:true,
	      protected_surfaces_byte_identical:true
	    }
	  and ($http.script_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
	)
	and (
	  .recovery_rollback.integrated_mariadb as $mariadb
	  | $mariadb == {
	      script:"public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php",
	      script_sha256:$mariadb.script_sha256,
	      modes:["baseline","candidate"],
	      required_per_fresh_restore:true,
	      provider_active_required:true,
	      reconciler_zero_divergence_required:true
	    }
	  and ($mariadb.script_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
	)
' "${proposal}" >/dev/null || die 'propuesta derivada inválida'
[[ "$(jq -r '.recovery_rollback.http_smoke.script_sha256' "${proposal}")" == \
	"$(sha256_file "${fixture_http_smoke}")" ]] \
	|| die 'propuesta no selló el hash real del smoke HTTP'
[[ "$(jq -r '.recovery_rollback.integrated_mariadb.script_sha256' "${proposal}")" == \
	"$(sha256_file "${fixture_integrated_mariadb}")" ]] \
	|| die 'propuesta no selló el hash real de la integración MariaDB'

jq --slurpfile proposal "${proposal}" \
	--arg rail_sha "$(sha256_file "${fixture_tools}/search-0.1.14-recovery-cycle.sh")" \
	--arg proof_sha "$(sha256_file "${fixture_tools}/search-0.1.14-recovery-proof.php")" \
	--arg protected_sha "$(sha256_file "${fixture_tools}/search-0.1.14-protected-fingerprint.sql")" \
	--arg check_sha "$(sha256_file "${fixture_tools}/search-0.1.14-check-table.awk")" \
	--arg recovery_test_sha "$(sha256_file "${fixture_tools}/test-search-0.1.14-recovery-cycle.sh")" \
	--arg runbook_sha "$(sha256_file "${fixture_root}/ops/akibara-search-0.1.14-recovery-runbook.md")" \
	--arg guard_sha "$(sha256_file "${fixture_guard_dir}/akibara-local-external-indexing-guard.php")" \
	--arg guard_test_sha "$(sha256_file "${fixture_root}/tools/test-local-external-indexing-guard.php")" '
  .seal_state = "sealed"
  | .sealed_at_utc = "2026-08-06T00:00:00Z"
  | .components.search.candidate = $proposal[0].candidate
  | .components.search.modified_runtime_files = $proposal[0].modified_runtime_files
  | .components.search.added_runtime_files = $proposal[0].added_runtime_files
	  | .components.search.removed_runtime_files = $proposal[0].removed_runtime_files
	  | .components.search.source_inputs = $proposal[0].source_inputs
	  | .production_invariants.recovery_rollback.images.apache = $proposal[0].recovery_rollback.images.apache
	  | .production_invariants.recovery_rollback.http_smoke = $proposal[0].recovery_rollback.http_smoke
	  | .production_invariants.recovery_rollback.integrated_mariadb = $proposal[0].recovery_rollback.integrated_mariadb
	  | .production_invariants.recovery_rollback.rail_sha256 = $rail_sha
	  | .production_invariants.recovery_rollback.reviewed_recovery_php_sha256 = $proof_sha
	  | .production_invariants.recovery_rollback.protected_fingerprint_sql_sha256 = $protected_sha
	  | .production_invariants.recovery_rollback.check_table_validator_sha256 = $check_sha
	  | .production_invariants.recovery_rollback.test_sha256 = $recovery_test_sha
	  | .production_invariants.recovery_rollback.runbook_sha256 = $runbook_sha
	  | .production_invariants.recovery_rollback.local_external_indexing.guard_sha256 = $guard_sha
	  | .production_invariants.recovery_rollback.local_external_indexing.isolated_test_sha256 = $guard_test_sha
	' "${fixture_contract}" > "${fixture_contract}.sealed"

jq '
  .seal_state = "sealed"
  | .sealed_at_utc = "2026-08-06T00:00:00Z"
  | .components.business.candidate.archive_sha256 = ("a" * 64)
  | .components.business.candidate.inventory_sha256 = ("b" * 64)
  | .components.business.candidate.runtime_files = 226
  | .components.business.modified_runtime_files = [
      "akibara-business/akibara-business.php",
      "akibara-business/src/Modules/OrderPreventa/ReadyToShipEmail.php",
      "akibara-business/src/Modules/SchemaProductRankMath/SchemaProductRankMath.php",
      "akibara-business/src/Modules/TaxonomyDiscount/DiscountResolver.php",
      "akibara-business/src/Modules/TaxonomyDiscount/Settings.php",
      "akibara-business/src/Plugin.php"
    ]
  | .components.business.added_runtime_files = [
      "akibara-business/src/Modules/TaxonomyDiscount/AdoptedSnapshot.php",
      "akibara-business/src/Modules/TaxonomyDiscount/AuthoritativeReader.php",
      "akibara-business/src/Modules/TaxonomyDiscount/PriceAuthority.php",
      "akibara-business/src/Modules/TaxonomyDiscount/PriceAuthorityUnavailable.php",
      "akibara-business/src/Modules/TaxonomyDiscount/SearchPriceProvider.php"
    ]
  | .components.business.removed_runtime_files = []
  | .components.business.source_inputs = (
      (.components.business.modified_runtime_files + .components.business.added_runtime_files)
      | sort
      | map({path:.,sha256:("c" * 64)})
    )
' "${fixture_business_contract}" > "${fixture_business_contract}.sealed"
mv -- "${fixture_business_contract}.sealed" "${fixture_business_contract}"

jq --arg business_sha "$(sha256_file "${fixture_business_contract}")" \
	'.paired_release.business_contract_sha256 = $business_sha' \
	"${fixture_contract}.sealed" > "${fixture_contract}.paired"
mv -- "${fixture_contract}.paired" "${fixture_contract}.sealed"
mv -- "${fixture_contract}.sealed" "${fixture_contract}"

bash "${fixture_packager}" --verify-only >/dev/null

sealed_fixture_contract="${scratch}/fixture-contract-sealed-good.json"
cp -- "${fixture_contract}" "${sealed_fixture_contract}"

jq '.production_invariants.recovery_rollback.http_smoke.script_sha256 = ("0" * 64)' \
	"${fixture_contract}" > "${fixture_contract}.bad"
mv -- "${fixture_contract}.bad" "${fixture_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó hash divergente del smoke HTTP'
fi
cp -- "${sealed_fixture_contract}" "${fixture_contract}"

jq '.production_invariants.recovery_rollback.integrated_mariadb.script =
      "tools/akibara-releases/search-0.1.14-recovery-proof.php"' \
	"${fixture_contract}" > "${fixture_contract}.bad"
mv -- "${fixture_contract}.bad" "${fixture_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó ruta divergente de la integración MariaDB'
fi
cp -- "${sealed_fixture_contract}" "${fixture_contract}"

jq '.production_invariants.recovery_rollback.integrated_mariadb.script_sha256 = ("0" * 64)' \
	"${fixture_contract}" > "${fixture_contract}.bad"
mv -- "${fixture_contract}.bad" "${fixture_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó hash divergente de la integración MariaDB'
fi
cp -- "${sealed_fixture_contract}" "${fixture_contract}"

jq 'del(.production_invariants.recovery_rollback.integrated_mariadb.script_sha256)' \
	"${fixture_contract}" > "${fixture_contract}.bad"
mv -- "${fixture_contract}.bad" "${fixture_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó integración MariaDB sellada sin hash'
fi
cp -- "${sealed_fixture_contract}" "${fixture_contract}"

jq 'del(.production_invariants.recovery_rollback.images.apache)' \
	"${fixture_contract}" > "${fixture_contract}.bad"
mv -- "${fixture_contract}.bad" "${fixture_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó contrato sellado sin imagen Apache'
fi
cp -- "${sealed_fixture_contract}" "${fixture_contract}"

jq '.production_invariants.recovery_rollback.images.apache =
      "wordpress@sha256:0000000000000000000000000000000000000000000000000000000000000000"' \
	"${fixture_contract}" > "${fixture_contract}.bad"
mv -- "${fixture_contract}.bad" "${fixture_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó una imagen Apache no contractual'
fi
cp -- "${sealed_fixture_contract}" "${fixture_contract}"

jq 'del(.production_invariants.recovery_rollback.images.node)' \
	"${fixture_contract}" > "${fixture_contract}.bad"
mv -- "${fixture_contract}.bad" "${fixture_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó contrato integrado sin imagen Node'
fi
cp -- "${sealed_fixture_contract}" "${fixture_contract}"

jq '.integrated_theme_binding.theme_contract_sha256 = ("0" * 64)' \
	"${fixture_contract}" > "${fixture_contract}.bad"
mv -- "${fixture_contract}.bad" "${fixture_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó drift del contrato Theme integrado'
fi
cp -- "${sealed_fixture_contract}" "${fixture_contract}"

jq '.integrated_ai_audit.engine_sha256 = ("0" * 64)' \
	"${fixture_contract}" > "${fixture_contract}.bad"
mv -- "${fixture_contract}.bad" "${fixture_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó drift del motor del auditor IA'
fi
cp -- "${sealed_fixture_contract}" "${fixture_contract}"

jq 'del(.production_invariants.recovery_rollback.immutable_release_binding.contracts_evidence_and_fourteen_artifacts_included)' \
	"${fixture_contract}" > "${fixture_contract}.bad"
mv -- "${fixture_contract}.bad" "${fixture_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó binding sin los 14 artefactos integrados'
fi
cp -- "${sealed_fixture_contract}" "${fixture_contract}"

cp -- "${fixture_business_contract}" "${scratch}/fixture-business-contract-good.json"
jq '.change_summary.scope += " tampered"' \
	"${fixture_business_contract}" > "${fixture_business_contract}.tampered"
mv -- "${fixture_business_contract}.tampered" "${fixture_business_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó drift del contrato Business ligado por hash'
fi
cp -- "${scratch}/fixture-business-contract-good.json" "${fixture_business_contract}"

cp -- "${fixture_theme_contract}" "${scratch}/fixture-theme-contract-good.json"
jq '.release_id = "akibara-gp-2.1.70-tampered"' \
	"${fixture_theme_contract}" > "${fixture_theme_contract}.tampered"
mv -- "${fixture_theme_contract}.tampered" "${fixture_theme_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó drift material del contrato Theme ligado por hash'
fi
cp -- "${scratch}/fixture-theme-contract-good.json" "${fixture_theme_contract}"

first="${scratch}/first"
second="${scratch}/second"
mkdir -p -- "${first}" "${second}"
TZ=Pacific/Honolulu bash "${fixture_packager}" --output-dir "${first}" >/dev/null
TZ=Asia/Tokyo bash "${fixture_packager}" --output-dir "${second}" >/dev/null

real_link_target="${scratch}/real-linked-output"
linked_output="${scratch}/linked-output"
mkdir -p -- "${real_link_target}"
ln -s "${real_link_target}" "${linked_output}"
if (
	AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY=1
	export AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY
	# shellcheck source=tools/akibara-releases/package-search-0.1.14-production.sh
	source "${packager}"
	assert_no_symlink_ancestors "${linked_output}"
) >/dev/null 2>&1; then
	die 'empaquetador aceptó directorio de salida simbólico'
fi
unlink "${linked_output}"
grep -Fq 'assert_no_symlink_ancestors "${output_dir}"' "${packager}" \
	|| die 'output-dir no usa el guard de padres simbólicos'

for artifact in \
	akibara-search-0.1.14.zip \
	akibara-search-0.1.14.files.sha256 \
	akibara-search-0.1.14.zip.sha256; do
	[[ -f "${first}/${artifact}" && ! -L "${first}/${artifact}" ]] \
		|| die "primer build no produjo ${artifact}"
	[[ -f "${second}/${artifact}" && ! -L "${second}/${artifact}" ]] \
		|| die "segundo build no produjo ${artifact}"
	cmp -s "${first}/${artifact}" "${second}/${artifact}" \
		|| die "build no reproducible: ${artifact}"
done

extract="${scratch}/extract"
mkdir -p -- "${extract}"
unzip -q "${first}/akibara-search-0.1.14.zip" -d "${extract}"
(
	cd -- "${first}"
	shasum -a 256 -c akibara-search-0.1.14.zip.sha256 >/dev/null
)
(
	cd -- "${extract}"
	shasum -a 256 -c "${first}/akibara-search-0.1.14.files.sha256" >/dev/null
)
grep -Eq '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*0\.1\.14[[:space:]]*$' \
	"${extract}/akibara-search/akibara-search.php" \
	|| die 'header candidato no es 0.1.14'
grep -Eq "define\([[:space:]]*'AKIBARA_SEARCH_VERSION'[[:space:]]*,[[:space:]]*'0\.1\.14'" \
	"${extract}/akibara-search/akibara-search.php" \
	|| die 'constante candidato no es 0.1.14'
find "${extract}/akibara-search" -type f -name '*.php' -print0 \
	| xargs -0 -n1 php -l >/dev/null
while IFS= read -r -d '' javascript_file; do
	node --check "${javascript_file}" >/dev/null
done < <(find "${extract}/akibara-search" -type f -name '*.js' -print0)

# Un artefacto local divergente jamás se sobrescribe.
printf 'tampered\n' >> "${first}/akibara-search-0.1.14.zip"
if bash "${fixture_packager}" --output-dir "${first}" >/dev/null 2>&1; then
	die 'empaquetador sobrescribió una colisión divergente'
fi

# El par exclusivo Business + Search es una frontera, no una sugerencia.
cp -- "${fixture_contract}" "${scratch}/fixture-contract-good.json"
jq '.exclusive_targets=["public_html/wp-content/plugins/akibara-search"]' \
	"${fixture_contract}" > "${fixture_contract}.bad"
mv -- "${fixture_contract}.bad" "${fixture_contract}"
if bash "${fixture_packager}" --verify-only >/dev/null 2>&1; then
	die 'empaquetador aceptó omitir Business del par'
fi
cp -- "${scratch}/fixture-contract-good.json" "${fixture_contract}"

# Ningún temporal o backup puede entrar ni siquiera al modo de derivación.
printf 'unsafe\n' > "${fixture_source}/src/unsafe-runtime.tmp"
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
	  | .production_invariants.recovery_rollback.http_smoke.script_sha256=null
	  | .production_invariants.recovery_rollback.integrated_mariadb.script_sha256=null
	  | .paired_release.business_contract_sha256=null
' "${fixture_contract}" > "${fixture_contract}.draft"
mv -- "${fixture_contract}.draft" "${fixture_contract}"
if bash "${fixture_packager}" --derive-only >/dev/null 2>&1; then
	die 'derive-only aceptó un temporal runtime'
fi

if [[ "${seal_state}" == sealed ]]; then
	bash "${packager}" --verify-only >/dev/null
	sealed_output="${scratch}/sealed-output"
	mkdir -p -- "${sealed_output}"
	bash "${packager}" --output-dir "${sealed_output}" >/dev/null
	for artifact in \
		akibara-search-0.1.14.zip \
		akibara-search-0.1.14.files.sha256 \
		akibara-search-0.1.14.zip.sha256; do
		cmp -s "${sealed_output}/${artifact}" "${repo_root}/dist/${artifact}" \
			|| die "dist no coincide con el sello: ${artifact}"
	done
fi

[[ "$(sha256_file "${contract}")" == "${contract_before}" ]] \
	|| die 'la prueba modificó el contrato real'
for protected in "${!protected_before[@]}"; do
	[[ "$(tree_digest "${repo_root}/${protected}")" == "${protected_before[${protected}]}" ]] \
		|| die "runtime no-target cambió: ${protected}"
done

printf 'PASS: paquete Search 0.1.14 reproducible; Theme/IA/Node y 14 artefactos ligados, ZIP-slip/extras/symlinks rechazados y no-target protegidos\n'
