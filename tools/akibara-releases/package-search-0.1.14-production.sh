#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "${script_dir}/../.." && pwd -P)"
contract_file="${repo_root}/ops/contracts/akibara-search-0.1.14.json"
business_contract="${repo_root}/ops/contracts/akibara-business-1.99.16.json"
theme_contract="${repo_root}/ops/contracts/akibara-gp-2.1.70.json"
compose_file="${repo_root}/compose.yaml"
packager_library="${script_dir}/package-search-0.1.14-production.sh"
runtime_packager="${script_dir}/package-runtime.sh"
theme_release_test="${script_dir}/test-theme-2.1.70-production-release.sh"
theme_phpunit_config="${repo_root}/tools/akibara-gp/phpunit.xml.dist"
ai_audit_engine="${repo_root}/tools/akibara-ai-discoverability-audit.mjs"
ai_audit_test="${repo_root}/tools/test-ai-discoverability-audit.mjs"
ai_audit_wrapper="${repo_root}/tools/akibara-ai-discoverability-audit.sh"
ai_audit_policy="${repo_root}/tools/lib/ai-discoverability-policy.mjs"
ai_audit_runbook="${repo_root}/ops/akibara-ai-discoverability-audit-runbook.md"
output_dir="${repo_root}/dist"
verify_only=0
derive_only=0
http_smoke_script='tools/akibara-releases/search-0.1.14-http-smoke.php'
integrated_mariadb_script='public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php'
apache_image='wordpress@sha256:b65f6fb4d8b669974d4e8d6f204bc5a11d67f4877473ba5ee97af0efd39e3d19'
node_image='node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b'

usage() {
	cat <<'EOF'
Uso:
  bash tools/akibara-releases/package-search-0.1.14-production.sh [opciones]

Opciones:
  --output-dir RUTA  Directorio local para ZIP, inventario y sidecar.
  --verify-only      Construye dos veces y valida contra un contrato sellado.
  --derive-only      Imprime la propuesta reproducible; no sella ni publica.

Mientras el contrato esté draft, sólo --derive-only está permitido. El sello
se incorpora mediante revisión explícita del JSON; este script nunca lo edita.
Business 1.99.16 debe sellarse primero y su SHA-256 debe quedar ligado en el
contrato Search antes de verificar cualquiera de los dos componentes.
EOF
}

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

sha256_file() {
	shasum -a 256 "$1" | awk '{print $1}'
}

verify_regular_file() {
	[[ -f "$1" && ! -L "$1" && -s "$1" ]] \
		|| die "Archivo ausente, vacío o inseguro: $1"
}

repo_path() {
	local relative="$1" parent resolved
	[[ -n "${relative}" && "${relative}" != /* && "${relative}" != *'..'*
		&& "${relative}" != *'//'*
		&& "${relative}" != *'/./'* ]] \
		|| die "Ruta contractual insegura: ${relative}"
	parent="${repo_root}/$(dirname -- "${relative}")"
	assert_no_symlink_ancestors "${parent}" || die "Padre contractual simbólico: ${relative}"
	[[ -d "${parent}" && ! -L "${parent}" ]] \
		|| die "Padre contractual ausente o inseguro: ${relative}"
	resolved="$(cd -- "${parent}" && printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${relative}")")"
	[[ "${resolved}" == "${repo_root}/"* ]] \
		|| die "Ruta contractual fuera del repositorio: ${relative}"
	printf '%s\n' "${resolved}"
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

verify_archive_inventory() {
	local archive="$1" inventory="$2" expected_root="$3"
	python3 - "${archive}" "${inventory}" "${expected_root}" <<'PY'
import hashlib
import pathlib
import re
import stat
import sys
import zipfile

archive = pathlib.Path(sys.argv[1])
inventory = pathlib.Path(sys.argv[2])
root = sys.argv[3]
row = re.compile(r"^([a-f0-9]{64})  (" + re.escape(root) + r"/[A-Za-z0-9._/-]+)$")
expected = {}
ordered = []
for line in inventory.read_text(encoding="utf-8").splitlines():
    match = row.fullmatch(line)
    if match is None:
        raise SystemExit(f"línea de inventario inválida: {line!r}")
    name = match.group(2)
    parts = pathlib.PurePosixPath(name).parts
    if not parts or parts[0] != root or any(part in ("", ".", "..") for part in parts):
        raise SystemExit(f"ruta de inventario insegura: {name}")
    if name in expected:
        raise SystemExit(f"ruta de inventario duplicada: {name}")
    expected[name] = match.group(1)
    ordered.append(name)
if not expected or ordered != sorted(ordered):
    raise SystemExit("inventario vacío o fuera de orden canónico")
expected_dirs = {root}
for name in expected:
    parent = pathlib.PurePosixPath(name).parent
    while parent.parts:
        expected_dirs.add(parent.as_posix())
        if parent.as_posix() == root:
            break
        parent = parent.parent

seen = set()
files = {}
with zipfile.ZipFile(archive) as bundle:
    for info in bundle.infolist():
        name = info.filename
        if not name or "\\" in name or name.startswith("/") or "\x00" in name:
            raise SystemExit(f"entrada ZIP insegura: {name!r}")
        parts = pathlib.PurePosixPath(name.rstrip("/")).parts
        if not parts or parts[0] != root or any(part in ("", ".", "..") for part in parts):
            raise SystemExit(f"entrada ZIP fuera del slug: {name!r}")
        if name in seen:
            raise SystemExit(f"entrada ZIP duplicada: {name}")
        seen.add(name)
        mode = (info.external_attr >> 16) & 0xFFFF
        kind = stat.S_IFMT(mode)
        if info.is_dir():
            if kind not in (0, stat.S_IFDIR):
                raise SystemExit(f"directorio ZIP con tipo especial: {name}")
            if name.rstrip("/") not in expected_dirs:
                raise SystemExit(f"directorio ZIP extra fuera del inventario: {name}")
            continue
        if kind not in (0, stat.S_IFREG):
            raise SystemExit(f"entrada ZIP no regular: {name}")
        files[name] = hashlib.sha256(bundle.read(info)).hexdigest()
if set(files) != set(expected):
    extra = sorted(set(files) - set(expected))
    missing = sorted(set(expected) - set(files))
    raise SystemExit(f"ZIP e inventario no son exactos; extra={extra}, missing={missing}")
for name, digest in expected.items():
    if files[name] != digest:
        raise SystemExit(f"hash ZIP divergente: {name}")
PY
}

verify_exact_inventory_tree() {
	local parent="$1" inventory="$2" expected_root="$3"
	python3 - "${parent}" "${inventory}" "${expected_root}" <<'PY'
import hashlib
import os
import pathlib
import re
import stat
import sys

parent = pathlib.Path(sys.argv[1])
inventory = pathlib.Path(sys.argv[2])
root_name = sys.argv[3]
row = re.compile(r"^([a-f0-9]{64})  (" + re.escape(root_name) + r"/[A-Za-z0-9._/-]+)$")
expected = {}
for line in inventory.read_text(encoding="utf-8").splitlines():
    match = row.fullmatch(line)
    if match is None or match.group(2) in expected:
        raise SystemExit("inventario inválido o duplicado")
    expected[match.group(2)] = match.group(1)
actual = {}
expected_dirs = {root_name}
for name in expected:
    parent_path = pathlib.PurePosixPath(name).parent
    while parent_path.parts:
        expected_dirs.add(parent_path.as_posix())
        if parent_path.as_posix() == root_name:
            break
        parent_path = parent_path.parent
actual_dirs = {root_name}
root = parent / root_name
if not root.is_dir() or root.is_symlink():
    raise SystemExit("raíz extraída ausente o simbólica")
for current, dirs, files in os.walk(root, followlinks=False):
    current_path = pathlib.Path(current)
    for name in list(dirs) + list(files):
        entry = current_path / name
        mode = os.lstat(entry).st_mode
        if stat.S_ISLNK(mode) or not (stat.S_ISDIR(mode) or stat.S_ISREG(mode)):
            raise SystemExit(f"entrada extraída especial: {entry}")
    for name in dirs:
        actual_dirs.add((current_path / name).relative_to(parent).as_posix())
    for name in files:
        entry = current_path / name
        relative = entry.relative_to(parent).as_posix()
        actual[relative] = hashlib.sha256(entry.read_bytes()).hexdigest()
if set(actual) != set(expected):
    extra = sorted(set(actual) - set(expected))
    missing = sorted(set(expected) - set(actual))
    raise SystemExit(f"árbol e inventario no son exactos; extra={extra}, missing={missing}")
if actual_dirs != expected_dirs:
    extra = sorted(actual_dirs - expected_dirs)
    missing = sorted(expected_dirs - actual_dirs)
    raise SystemExit(f"directorios extraídos no son exactos; extra={extra}, missing={missing}")
for name, digest in expected.items():
    if actual[name] != digest:
        raise SystemExit(f"hash extraído divergente: {name}")
PY
}

# Permite que el test del rail ejercite directamente los parsers ZIP/inventario
# sin ejecutar packaging ni tocar dist. No se usa en la promoción normal.
if [[ "${AKB_SEARCH_0114_PACKAGER_LIBRARY_ONLY:-0}" == 1 ]]; then
	return 0 2>/dev/null || exit 0
fi

validate_contract_base() {
	jq -e \
		--arg http_smoke_script "${http_smoke_script}" \
		--arg integrated_mariadb_script "${integrated_mariadb_script}" \
		--arg compose_sha "$(sha256_file "${compose_file}")" \
		--arg packager_sha "$(sha256_file "${packager_library}")" \
		--arg runtime_packager_sha "$(sha256_file "${runtime_packager}")" \
		--arg protected_candidate_sha "2663d2144a094779535360d489a1f868f67d386a6a0d86e415637cd37472315a" \
		--arg protected_rollback_sha "5197e7307fcfe75676c76f26b6ef3bc3f9e7339975594216c2879c716f080c5c" \
		--arg apache_image "${apache_image}" --arg node_image "${node_image}" \
		--arg theme_contract_sha "$(sha256_file "${theme_contract}")" \
		--arg ai_engine_sha "$(sha256_file "${ai_audit_engine}")" \
		--arg ai_test_sha "$(sha256_file "${ai_audit_test}")" \
		--arg ai_wrapper_sha "$(sha256_file "${ai_audit_wrapper}")" \
		--arg ai_policy_sha "$(sha256_file "${ai_audit_policy}")" \
		--arg ai_runbook_sha "$(sha256_file "${ai_audit_runbook}")" \
		--slurpfile theme "${theme_contract}" '
      .schema_version == 2
      and .contract == "akibara-search-production-release"
      and .release_id == "akibara-search-0.1.14"
      and (.seal_state == "draft" or .seal_state == "sealed")
	      and .promotion_sequence == ["docker-local-paired", "production-ui-paired"]
	      and .exclusive_targets == [
	        "public_html/wp-content/plugins/akibara-business",
	        "public_html/wp-content/plugins/akibara-search"
	      ]
      and (.components | keys) == ["search"]
      and .components.search.kind == "plugin"
      and .components.search.slug == "akibara-search"
      and .components.search.source_root ==
        "public_html/wp-content/plugins/akibara-search"
      and .components.search.packager == "tools/akibara-releases/package-runtime.sh"
      and .components.search.packager_sha256 == $runtime_packager_sha
      and .components.search.preimage == {
        version:"0.1.12",
        archive:"dist/akibara-search-0.1.12.zip",
        archive_sha256:"f895860899137189f997f75e0e1ca5b0d3939ca76d290ad4e6a078c30230a702",
        inventory:"dist/akibara-search-0.1.12.files.sha256",
        inventory_sha256:"71b4193c9156cbe8c5b6e4e85ad194c4923ee3f3e376cc2c34037e91025f86ef",
        runtime_files:45
      }
      and .components.search.remote_baseline == {
        version:"0.1.12",
        inventory_sha256:"71b4193c9156cbe8c5b6e4e85ad194c4923ee3f3e376cc2c34037e91025f86ef",
        runtime_files:45
      }
      and .components.search.candidate.version == "0.1.14"
      and .components.search.candidate.archive ==
        "dist/akibara-search-0.1.14.zip"
	      and .components.search.candidate.inventory ==
	        "dist/akibara-search-0.1.14.files.sha256"
	      and .paired_release.required == true
	      and .paired_release.business_contract == "ops/contracts/akibara-business-1.99.16.json"
	      and .paired_release.candidate == {business_version:"1.99.16",search_version:"0.1.14"}
	      and .paired_release.rollback == {business_version:"1.99.10",search_version:"0.1.12"}
	      and .paired_release.compatibility_policy == "exact-pair-only"
	      and .paired_release.search_must_be_inactive_during_component_switch == true
	      and .paired_release.production_transition.transport == "wordpress-plugin-ui-with-official-plugin-deploy"
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
	        node_image:$node_image,
	        base_url:"https://akibara.cl/",
	        fetch_scope:"docker-internal-only",
	        redirect_policy:"manual_fail_closed",
	        network_internal:true,
	        published_ports:0,
	        external_network_allowed:false,
	        required_per_fresh_restore:true,
	        required_passes:2
	      }
	      and .production_invariants.remote_backups_allowed == false
	      and .production_invariants.rollback_artifacts_location == "local-only"
	      and .production_invariants.graphify_during_release_allowed == false
	      and .production_invariants.runtime_outside_pair_must_remain_byte_identical == true
	      and .production_invariants.search_derived_data_fingerprint_may_change_after_verified_rebuild == true
		      and .production_invariants.search_protected_persistent_surfaces_must_match_phase_contract_exactly == true
	      and .production_invariants.search_derived_price_pair_phase_transition_must_match_exactly == true
	      and (.production_invariants | has("search_data_fingerprint_must_remain_identical") | not)
	      and .production_invariants.search_durable_convergence_must_pass == true
	      and .production_invariants.recovery_rollback.rail ==
	        "tools/akibara-releases/search-0.1.14-recovery-cycle.sh"
	      and .production_invariants.recovery_rollback.compose_file == "compose.yaml"
	      and .production_invariants.recovery_rollback.compose_file_sha256 == $compose_sha
	      and .production_invariants.recovery_rollback.packager_library ==
	        "tools/akibara-releases/package-search-0.1.14-production.sh"
	      and .production_invariants.recovery_rollback.packager_library_sha256 == $packager_sha
	      and .production_invariants.recovery_rollback.reviewed_recovery_php ==
	        "tools/akibara-releases/search-0.1.14-recovery-proof.php"
	      and .production_invariants.recovery_rollback.protected_fingerprint_sql ==
	        "tools/akibara-releases/search-0.1.14-protected-fingerprint.sql"
	      and .production_invariants.recovery_rollback.check_table_validator ==
	        "tools/akibara-releases/search-0.1.14-check-table.awk"
	      and .production_invariants.recovery_rollback.test_script ==
	        "tools/akibara-releases/test-search-0.1.14-recovery-cycle.sh"
	      and .production_invariants.recovery_rollback.runbook ==
	        "ops/akibara-search-0.1.14-recovery-runbook.md"
	      and (.production_invariants.recovery_rollback.images | keys) ==
	        ["apache", "mariadb", "node", "wp_cli"]
	      and .production_invariants.recovery_rollback.images.apache == $apache_image
	      and .production_invariants.recovery_rollback.images.node == $node_image
	      and (
	        .production_invariants.recovery_rollback.http_smoke as $http
	        | $http == {
	            script:$http_smoke_script,
	            script_sha256:$http.script_sha256,
	            receipt_schema:"akibara-search-http-smoke-v1",
	            network_internal:true,
	            published_ports:0,
	            host_header:"localhost:8080",
	            follow_redirects:false,
	            required_per_fresh_restore:true,
	            protected_surfaces_byte_identical:true
	          }
	      )
	      and (
	        .production_invariants.recovery_rollback.integrated_mariadb as $mariadb
	        | $mariadb == {
	            script:$integrated_mariadb_script,
	            script_sha256:$mariadb.script_sha256,
	            modes:["baseline","candidate"],
	            required_per_fresh_restore:true,
	            provider_active_required:true,
	            reconciler_zero_divergence_required:true
	          }
	      )
	      and .production_invariants.recovery_rollback.fresh_restore_cycles == 2
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
		        candidate:{business_version:"1.99.16",value:"2",autoload:"off",full_snapshot_sha256:$protected_candidate_sha},
		        rollback:{business_version:"1.99.10",value:"1",autoload:"off",full_snapshot_sha256:$protected_rollback_sha},
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
	      and .production_invariants.recovery_rollback.paired_component_switch.search_inactive_required == true
	      and .production_invariants.recovery_rollback.paired_component_switch.rollback_mixed_midpoint == {business_version:"1.99.10",search_version:"0.1.14",search_active:false}
	      and .production_invariants.recovery_rollback.paired_component_switch.rollback_ready_pair == {business_version:"1.99.10",search_version:"0.1.12",search_active:false}
	      and .production_invariants.recovery_rollback.paired_component_switch.forward_mixed_midpoint == {business_version:"1.99.16",search_version:"0.1.12",search_active:false}
	      and .production_invariants.recovery_rollback.paired_component_switch.forward_ready_pair == {business_version:"1.99.16",search_version:"0.1.14",search_active:false}
	      and .production_invariants.recovery_rollback.local_external_indexing == {
	        environment:"local",
	        network_external_dependency_allowed:false,
	        guard:"public_html/wp-content/mu-plugins/akibara-local-external-indexing-guard.php",
	        guard_sha256:.production_invariants.recovery_rollback.local_external_indexing.guard_sha256,
	        isolated_test:"tools/test-local-external-indexing-guard.php",
	        isolated_test_sha256:.production_invariants.recovery_rollback.local_external_indexing.isolated_test_sha256,
	        rank_math_indexnow_log_must_remain_byte_identical:true
	      }
	      and .quality_gates.recovery_rollback ==
	        "bash tools/akibara-releases/test-search-0.1.14-recovery-cycle.sh"
	      and .quality_gates.theme_contract == "bash tools/akibara-releases/test-theme-2.1.70-production-release.sh"
	      and .quality_gates.theme_package == "bash tools/akibara-releases/package-runtime.sh theme --verify-only"
	      and .quality_gates.theme_node == "node --test public_html/wp-content/themes/akibara-gp/tests/*.test.js"
	      and .quality_gates.theme_phpunit == "tools/akibara-business/vendor/bin/phpunit -c tools/akibara-gp/phpunit.xml.dist"
	      and .quality_gates.ai_audit_unit == "node tools/test-ai-discoverability-audit.mjs"
	      and .quality_gates.ai_audit_integrated == "node tools/akibara-ai-discoverability-audit.mjs --base-url https://akibara.cl/ --fetch-base-url docker-internal"
	      and .quality_gates.required_passes == 2
	      and .production_invariants.deployment.transport ==
	        "mcp__hostinger_hosting__hosting_deployWordpressPlugin"
	      and .production_invariants.deployment.activation_transport == "wordpress-plugin-ui"
	      and .production_invariants.deployment.search_inactive_at_every_mixed_version_midpoint == true
	      and .production_invariants.deployment.components == {
	        business:{slug:"akibara-business",candidate_version:"1.99.16",rollback_version:"1.99.10"},
	        search:{slug:"akibara-search",candidate_version:"0.1.14",rollback_version:"0.1.12"}
	      }
	      and .production_invariants.deployment.candidate_and_rollback_use_same_transport == true
      and .production_invariants.deployment.receipt_policy == {
        confirmed_success_profile:null,
        allowed_ack_loss_result:{
          Err:"tool call error: tool call failed for `hostinger-hosting/hosting_deployWordpressPlugin`\n\nCaused by:\n    timed out awaiting tools/call after 120s"
	        },
	        accept_only_with_exact_postconditions:[
	          "wordpress-ui-search-inactive-midpoint",
	          "wordpress-active-pair-versions",
	          "business-runtime-inventory",
	          "search-runtime-inventory",
          "complete-witness-bundle"
        ]
      }
      and .production_invariants.hostinger_cache == {
        required:true,
        transport:"mcp__hostinger_hosting__hosting_clearWebsiteCacheV1",
        receipt_policy:{exact_result:{content:[{
          type:"text",text:"{\"message\":\"Request accepted\"}"
        }]}}
      }
    ' "${contract_file}" >/dev/null \
		|| die 'Contrato base Search 0.1.14 rechazado'

	jq -e '
	  .schema_version == 2
	  and .release_id == "akibara-business-1.99.16"
	  and (.seal_state == "draft" or .seal_state == "sealed")
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
	' "${business_contract}" >/dev/null \
		|| die 'Contrato Business 1.99.16 no declara el mismo par exacto'

	jq -e \
		--arg runtime_packager_sha "$(sha256_file "${runtime_packager}")" \
		--arg theme_test_sha "$(sha256_file "${theme_release_test}")" \
		--arg phpunit_config_sha "$(sha256_file "${theme_phpunit_config}")" '
	  .schema_version == 1
	  and .contract == "akibara-gp-production-release"
	  and .release_id == "akibara-gp-2.1.70"
	  and .seal_state == "sealed"
	  and .promotion_sequence == ["docker-local-integrated","production-ui-integrated"]
	  and .component.kind == "theme"
	  and .component.slug == "akibara-gp"
	  and .component.version == "2.1.70"
	  and .component.packager.path == "tools/akibara-releases/package-runtime.sh"
	  and .component.packager.sha256 == $runtime_packager_sha
	  and .component.packager.component == "theme"
	  and .component.preimage.version == "2.1.69"
	  and .component.preimage.runtime_files == 103
	  and .component.candidate.version == "2.1.70"
	  and .component.candidate.runtime_files == 103
	  and ([.component.preimage.archive_sha256,.component.preimage.inventory_sha256,
	        .component.preimage.checksum_sidecar_sha256,.component.candidate.archive_sha256,
	        .component.candidate.inventory_sha256,.component.candidate.checksum_sidecar_sha256] |
	       all(type == "string" and test("^[a-f0-9]{64}$")))
	  and .quality_gates.static_contract.path == "tools/akibara-releases/test-theme-2.1.70-production-release.sh"
	  and .quality_gates.static_contract.sha256 == $theme_test_sha
	  and .quality_gates.static_contract.negative_mutations_required == 10
	  and .quality_gates.node_theme.expected_tests == 31
	  and .quality_gates.phpunit_theme.config == "tools/akibara-gp/phpunit.xml.dist"
	  and .quality_gates.phpunit_theme.config_sha256 == $phpunit_config_sha
	  and .quality_gates.phpunit_theme.expected_tests == 18
	  and .quality_gates.phpunit_theme.expected_assertions == 52
	  and .production_invariants.deployment_transport == "mcp__hostinger_hosting__hosting_deployWordpressTheme"
	  and .production_invariants.remote_backups_allowed == false
	  and .production_invariants.rollback_artifacts_location == "local-only"
	  and .production_invariants.graphify_during_release_allowed == false
	' "${theme_contract}" >/dev/null \
		|| die 'Contrato Theme 2.1.70 integrado no está sellado/exacto'
}

verify_recovery_artifacts() {
	local relative expected actual full
	while IFS=$'\t' read -r relative expected; do
		[[ -n "${relative}" ]] || die 'Ruta recovery vacía'
		full="$(repo_path "${relative}")"
		verify_regular_file "${full}"
		if [[ -n "${expected}" ]]; then
			[[ "${expected}" =~ ^[a-f0-9]{64}$ ]] \
				|| die "Hash recovery contractual inválido: ${relative}"
			actual="$(sha256_file "${full}")"
			[[ "${actual}" == "${expected}" ]] \
				|| die "Artefacto recovery divergente: ${relative}"
		elif [[ "$(jq -r '.seal_state' "${contract_file}")" == sealed ]]; then
			die "Contrato sellado sin hash recovery: ${relative}"
		fi
	done < <(
		jq -r '
		  .production_invariants.recovery_rollback as $r
		  | .integrated_theme_binding as $theme
		  | .integrated_ai_audit as $ai
		  | [
		      [$theme.theme_contract_path,$theme.theme_contract_sha256],
		      [$ai.engine_path,$ai.engine_sha256],
		      [$ai.test_path,$ai.test_sha256],
		      [$ai.wrapper_path,$ai.wrapper_sha256],
		      [$ai.policy_path,$ai.policy_sha256],
		      [$ai.runbook_path,$ai.runbook_sha256],
		      [$r.compose_file,$r.compose_file_sha256],
		      [$r.packager_library,$r.packager_library_sha256],
		      [$r.rail,$r.rail_sha256],
		      [$r.reviewed_recovery_php,$r.reviewed_recovery_php_sha256],
		      [$r.http_smoke.script,$r.http_smoke.script_sha256],
		      [$r.integrated_mariadb.script,$r.integrated_mariadb.script_sha256],
		      [$r.protected_fingerprint_sql,$r.protected_fingerprint_sql_sha256],
		      [$r.check_table_validator,$r.check_table_validator_sha256],
		      [$r.test_script,$r.test_sha256],
		      [$r.runbook,$r.runbook_sha256],
		      [$r.local_external_indexing.guard,$r.local_external_indexing.guard_sha256],
		      [$r.local_external_indexing.isolated_test,$r.local_external_indexing.isolated_test_sha256]
		    ][] | [.[0], (.[1] // "")] | @tsv
		' "${contract_file}"
	)

	while IFS=$'\t' read -r relative expected; do
		[[ -n "${relative}" && "${expected}" =~ ^[a-f0-9]{64}$ ]] \
			|| die 'Input Theme integrado inválido'
		full="$(repo_path "${relative}")"
		verify_regular_file "${full}"
		[[ "$(sha256_file "${full}")" == "${expected}" ]] \
			|| die "Input Theme integrado divergente: ${relative}"
	done < <(
		jq -r '
		  [
		    [.component.preimage.archive,.component.preimage.archive_sha256],
		    [.component.preimage.inventory,.component.preimage.inventory_sha256],
		    [.component.preimage.checksum_sidecar,.component.preimage.checksum_sidecar_sha256],
		    [.component.candidate.archive,.component.candidate.archive_sha256],
		    [.component.candidate.inventory,.component.candidate.inventory_sha256],
		    [.component.candidate.checksum_sidecar,.component.candidate.checksum_sidecar_sha256],
		    [.quality_gates.static_contract.path,.quality_gates.static_contract.sha256],
		    [.quality_gates.phpunit_theme.config,.quality_gates.phpunit_theme.config_sha256],
		    (.quality_gates.node_theme.test_files[] | [.path,.sha256]),
		    (.quality_gates.phpunit_theme.test_files[] | [.path,.sha256])
		  ][] | @tsv
		' "${theme_contract}"
	)
}

require_sealed_contract() {
	jq -e '
      .seal_state == "sealed"
      and (.sealed_at_utc | type == "string" and
        test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
      and (.components.search.candidate.archive_sha256 |
        type == "string" and test("^[a-f0-9]{64}$"))
      and (.components.search.candidate.inventory_sha256 |
        type == "string" and test("^[a-f0-9]{64}$"))
      and (.components.search.candidate.runtime_files |
        type == "number" and floor == . and . > 0)
      and (.components.search.modified_runtime_files | type == "array")
      and (.components.search.added_runtime_files | type == "array")
      and (.components.search.removed_runtime_files | type == "array")
	      and (.components.search.source_inputs | type == "array")
	      and (.components.search.packager_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
	      and (.paired_release.business_contract_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
      and ([
        .components.search.modified_runtime_files[],
        .components.search.added_runtime_files[],
        .components.search.removed_runtime_files[]
      ] | all(type == "string" and test("^akibara-search/[A-Za-z0-9._/-]+$")
          and (contains("..") | not)))
	      and ([.components.search.source_inputs[]] | all(
        (.path | type == "string" and
          test("^akibara-search/[A-Za-z0-9._/-]+$") and
          (contains("..") | not))
        and (.sha256 | type == "string" and test("^[a-f0-9]{64}$"))
	        and ((keys | sort) == ["path", "sha256"])
	      ))
	      and ([
	        .production_invariants.recovery_rollback.compose_file_sha256,
	        .production_invariants.recovery_rollback.packager_library_sha256,
	        .production_invariants.recovery_rollback.rail_sha256,
	        .production_invariants.recovery_rollback.reviewed_recovery_php_sha256,
	        .production_invariants.recovery_rollback.http_smoke.script_sha256,
	        .production_invariants.recovery_rollback.integrated_mariadb.script_sha256,
	        .production_invariants.recovery_rollback.protected_fingerprint_sql_sha256,
	        .production_invariants.recovery_rollback.check_table_validator_sha256,
	        .production_invariants.recovery_rollback.test_sha256,
	        .production_invariants.recovery_rollback.runbook_sha256,
	        .production_invariants.recovery_rollback.local_external_indexing.guard_sha256,
	        .production_invariants.recovery_rollback.local_external_indexing.isolated_test_sha256,
	        .integrated_theme_binding.theme_contract_sha256,
	        .integrated_theme_binding.theme_archive_sha256,
	        .integrated_theme_binding.theme_inventory_sha256,
	        .integrated_theme_binding.theme_checksum_sidecar_sha256,
	        .integrated_theme_binding.theme_rollback_archive_sha256,
	        .integrated_theme_binding.theme_rollback_inventory_sha256,
	        .integrated_theme_binding.theme_rollback_checksum_sidecar_sha256,
	        .integrated_ai_audit.engine_sha256,
	        .integrated_ai_audit.test_sha256,
	        .integrated_ai_audit.wrapper_sha256,
	        .integrated_ai_audit.policy_sha256,
	        .integrated_ai_audit.runbook_sha256
	      ] | all(type == "string" and test("^[a-f0-9]{64}$")))
	      and .integrated_theme_binding.required == true
	      and .integrated_ai_audit.required == true
	      and .production_invariants.recovery_rollback.images.node ==
	        "node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b"
    ' "${contract_file}" >/dev/null \
		|| die 'Contrato draft o incompleto: se prohíbe verificar/publicar candidato'
}

while (($#)); do
	case "$1" in
		--output-dir)
			(($# >= 2)) || die 'Falta ruta para --output-dir'
			output_dir="$2"
			shift 2
			;;
		--verify-only)
			verify_only=1
			shift
			;;
		--derive-only)
			derive_only=1
			shift
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			die "Opción desconocida: $1"
			;;
	esac
done

((verify_only + derive_only <= 1)) \
	|| die '--verify-only y --derive-only son mutuamente excluyentes'

for command_name in awk bash cmp find grep install jq mkdir mktemp python3 rm shasum tr unzip wc zipinfo; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| die "Falta el comando requerido: ${command_name}"
done
verify_regular_file "${contract_file}"
verify_regular_file "${business_contract}"
verify_regular_file "${theme_contract}"
verify_regular_file "${compose_file}"
verify_regular_file "${packager_library}"
verify_regular_file "${runtime_packager}"
verify_regular_file "${theme_release_test}"
verify_regular_file "${theme_phpunit_config}"
verify_regular_file "${ai_audit_engine}"
verify_regular_file "${ai_audit_test}"
verify_regular_file "${ai_audit_wrapper}"
verify_regular_file "${ai_audit_policy}"
verify_regular_file "${ai_audit_runbook}"
validate_contract_base
verify_recovery_artifacts

seal_state="$(jq -r '.seal_state' "${contract_file}")"
if ((derive_only)); then
	[[ "${seal_state}" == draft ]] \
		|| die '--derive-only exige un contrato draft; un sello no se redescubre'
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
    ' "${contract_file}" >/dev/null \
		|| die 'El draft mezcla placeholders con valores candidatos no sellados'
else
	require_sealed_contract
	jq -e '
	  .seal_state == "sealed"
	  and .components.business.candidate.version == "1.99.16"
	  and (.components.business.candidate.archive_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
	  and (.components.business.candidate.inventory_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
	' "${business_contract}" >/dev/null || die 'Contrato Business pareado no está sellado'
	[[ "$(sha256_file "${business_contract}")" == "$(jq -r '.paired_release.business_contract_sha256' "${contract_file}")" ]] \
		|| die 'Contrato Business pareado cambió después del sello Search'
fi

source_root="$(repo_path "$(jq -r '.components.search.source_root' "${contract_file}")")"
preimage_archive="$(repo_path "$(jq -r '.components.search.preimage.archive' "${contract_file}")")"
preimage_inventory="$(repo_path "$(jq -r '.components.search.preimage.inventory' "${contract_file}")")"
preimage_sidecar="${preimage_archive}.sha256"

[[ -d "${source_root}" && ! -L "${source_root}" ]] \
	|| die 'Árbol fuente Search ausente o inseguro'
verify_regular_file "${preimage_archive}"
verify_regular_file "${preimage_inventory}"
verify_regular_file "${preimage_sidecar}"
[[ "$(sha256_file "${preimage_archive}")" == \
	"$(jq -r '.components.search.preimage.archive_sha256' "${contract_file}")" ]] \
	|| die 'ZIP preimagen Search 0.1.12 cambió'
[[ "$(sha256_file "${preimage_inventory}")" == \
	"$(jq -r '.components.search.preimage.inventory_sha256' "${contract_file}")" ]] \
	|| die 'Inventario preimagen Search 0.1.12 cambió'
(
	cd -- "$(dirname -- "${preimage_archive}")"
	shasum -a 256 -c "$(basename -- "${preimage_sidecar}")" >/dev/null
) || die 'Sidecar preimagen Search 0.1.12 inválido'
verify_archive_inventory "${preimage_archive}" "${preimage_inventory}" akibara-search \
	|| die 'ZIP preimagen inseguro o no exacto'
unzip -tq "${preimage_archive}" >/dev/null
[[ "$(wc -l < "${preimage_inventory}" | tr -d ' ')" == 45 ]] \
	|| die 'Inventario preimagen no contiene 45 archivos'
[[ "$(zipinfo -1 "${preimage_archive}" | grep -vc '/$')" == 45 ]] \
	|| die 'ZIP preimagen no contiene 45 archivos runtime'

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-search-0.1.14-package.XXXXXX")"
scratch="$(cd -- "$(dirname -- "${scratch}")" && printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${scratch}")")"
assert_no_symlink_ancestors "${scratch}" || die 'Scratch de packaging inseguro'
cleanup_done=0
cleanup() {
	if ((cleanup_done)); then
		return
	fi
	cleanup_done=1
	case "${scratch:-}" in
		*/akibara-search-0.1.14-package.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

first_output="${scratch}/first"
second_output="${scratch}/second"
extract_root="${scratch}/extract"
mkdir -p -- "${first_output}" "${second_output}" "${extract_root}"

AKIBARA_EXPECTED_VERSION=0.1.14 TZ=Pacific/Kiritimati \
	bash "${runtime_packager}" search --output-dir "${first_output}" >/dev/null
AKIBARA_EXPECTED_VERSION=0.1.14 TZ=Pacific/Pago_Pago \
	bash "${runtime_packager}" search --output-dir "${second_output}" >/dev/null

candidate_archive_name='akibara-search-0.1.14.zip'
candidate_inventory_name='akibara-search-0.1.14.files.sha256'
candidate_sidecar_name='akibara-search-0.1.14.zip.sha256'
for artifact_name in \
	"${candidate_archive_name}" \
	"${candidate_inventory_name}" \
	"${candidate_sidecar_name}"; do
	verify_regular_file "${first_output}/${artifact_name}"
	verify_regular_file "${second_output}/${artifact_name}"
	cmp -s "${first_output}/${artifact_name}" "${second_output}/${artifact_name}" \
		|| die "Artefacto no reproducible: ${artifact_name}"
done

candidate_archive="${first_output}/${candidate_archive_name}"
candidate_inventory="${first_output}/${candidate_inventory_name}"
candidate_sidecar="${first_output}/${candidate_sidecar_name}"
(
	cd -- "${first_output}"
	shasum -a 256 -c "${candidate_sidecar_name}" >/dev/null
) || die 'Sidecar candidato inválido'
verify_archive_inventory "${candidate_archive}" "${candidate_inventory}" akibara-search \
	|| die 'ZIP candidato inseguro o no exacto'
unzip -tq "${candidate_archive}" >/dev/null
unzip -q "${candidate_archive}" -d "${extract_root}"
[[ -d "${extract_root}/akibara-search" && ! -L "${extract_root}/akibara-search" ]] \
	|| die 'ZIP candidato no contiene la raíz akibara-search'
[[ -z "$(find "${extract_root}/akibara-search" ! -type d ! -type f -print -quit)" ]] \
	|| die 'ZIP candidato contiene enlaces o entradas especiales'
verify_exact_inventory_tree "${extract_root}" "${candidate_inventory}" akibara-search \
	|| die 'Inventario candidato no valida exactamente el ZIP extraído'

runtime_files="$(wc -l < "${candidate_inventory}" | tr -d ' ')"
[[ "${runtime_files}" =~ ^[0-9]+$ && "${runtime_files}" -gt 0 ]] \
	|| die 'Conteo runtime candidato inválido'
[[ "$(zipinfo -1 "${candidate_archive}" | grep -vc '/$')" == "${runtime_files}" ]] \
	|| die 'ZIP e inventario candidato difieren en conteo'

delta_file="${scratch}/delta.json"
python3 - "${preimage_inventory}" "${candidate_inventory}" > "${delta_file}" <<'PY'
import json
import pathlib
import re
import sys

row = re.compile(r"^([a-f0-9]{64})  (akibara-search/[A-Za-z0-9._/-]+)$")

def load(path):
    result = {}
    for line in pathlib.Path(path).read_text(encoding="utf-8").splitlines():
        match = row.fullmatch(line)
        if match is None or match.group(2) in result or ".." in match.group(2):
            raise SystemExit(f"inventario inválido: {path}")
        result[match.group(2)] = match.group(1)
    return result

old = load(sys.argv[1])
new = load(sys.argv[2])
added = sorted(set(new) - set(old))
removed = sorted(set(old) - set(new))
modified = sorted(path for path in set(old) & set(new) if old[path] != new[path])
inputs = [{"path": path, "sha256": new[path]} for path in sorted(modified + added)]
print(json.dumps({
    "modified_runtime_files": modified,
    "added_runtime_files": added,
    "removed_runtime_files": removed,
    "source_inputs": inputs,
}, separators=(",", ":"), sort_keys=True))
PY

archive_sha="$(sha256_file "${candidate_archive}")"
inventory_sha="$(sha256_file "${candidate_inventory}")"
http_smoke_sha="$(sha256_file "$(repo_path "${http_smoke_script}")")"
integrated_mariadb_sha="$(sha256_file "$(repo_path "${integrated_mariadb_script}")")"

if ((derive_only)); then
	jq -n \
		--arg archive_sha256 "${archive_sha}" \
		--arg inventory_sha256 "${inventory_sha}" \
		--arg http_smoke_script "${http_smoke_script}" \
		--arg http_smoke_sha256 "${http_smoke_sha}" \
		--arg integrated_mariadb_script "${integrated_mariadb_script}" \
		--arg integrated_mariadb_sha256 "${integrated_mariadb_sha}" \
		--arg apache_image "${apache_image}" \
		--argjson runtime_files "${runtime_files}" \
		--slurpfile delta "${delta_file}" '
      {
        seal_state:"draft-proposal",
        candidate:{
          version:"0.1.14",
          archive:"dist/akibara-search-0.1.14.zip",
          archive_sha256:$archive_sha256,
          inventory:"dist/akibara-search-0.1.14.files.sha256",
          inventory_sha256:$inventory_sha256,
          runtime_files:$runtime_files
	        },
	        recovery_rollback:{
	          images:{apache:$apache_image},
	          http_smoke:{
	            script:$http_smoke_script,
	            script_sha256:$http_smoke_sha256,
	            receipt_schema:"akibara-search-http-smoke-v1",
	            network_internal:true,
	            published_ports:0,
	            host_header:"localhost:8080",
	            follow_redirects:false,
	            required_per_fresh_restore:true,
	            protected_surfaces_byte_identical:true
	          },
	          integrated_mariadb:{
	            script:$integrated_mariadb_script,
	            script_sha256:$integrated_mariadb_sha256,
	            modes:["baseline","candidate"],
	            required_per_fresh_restore:true,
	            provider_active_required:true,
	            reconciler_zero_divergence_required:true
	          }
	        }
      } + $delta[0]
    '
	exit 0
fi

python3 - "${contract_file}" "${delta_file}" "${archive_sha}" \
	"${inventory_sha}" "${runtime_files}" "${source_root}" <<'PY'
import hashlib
import json
import pathlib
import sys

contract = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
actual = json.loads(pathlib.Path(sys.argv[2]).read_text(encoding="utf-8"))
spec = contract["components"]["search"]
candidate = spec["candidate"]
expected_candidate = {
    "archive_sha256": sys.argv[3],
    "inventory_sha256": sys.argv[4],
    "runtime_files": int(sys.argv[5]),
}
for key, value in expected_candidate.items():
    if candidate[key] != value:
        raise SystemExit(f"candidato sellado divergente: {key}")
for key in (
    "modified_runtime_files",
    "added_runtime_files",
    "removed_runtime_files",
    "source_inputs",
):
    if spec[key] != actual[key]:
        raise SystemExit(f"delta/source_inputs sellado divergente: {key}")

source_root = pathlib.Path(sys.argv[6])
for item in spec["source_inputs"]:
    relative = item["path"].removeprefix("akibara-search/")
    if relative == item["path"] or ".." in pathlib.PurePosixPath(relative).parts:
        raise SystemExit(f"input fuera del slug: {item['path']}")
    source = source_root / relative
    if not source.is_file() or source.is_symlink():
        raise SystemExit(f"input fuente ausente/inseguro: {item['path']}")
    digest = hashlib.sha256(source.read_bytes()).hexdigest()
    if digest != item["sha256"]:
        raise SystemExit(f"input fuente cambió tras el sello: {item['path']}")
PY

if ((verify_only)); then
	printf 'PASS: Search 0.1.14 reproducible y coincide con el contrato sellado\n'
	exit 0
fi

assert_no_symlink_ancestors "${output_dir}" || die 'Directorio de salida con padre simbólico'
mkdir -p -- "${output_dir}"
[[ -d "${output_dir}" && ! -L "${output_dir}" ]] \
	|| die "Directorio de salida inseguro: ${output_dir}"
for artifact_name in \
	"${candidate_archive_name}" \
	"${candidate_inventory_name}" \
	"${candidate_sidecar_name}"; do
	source_artifact="${first_output}/${artifact_name}"
	target_artifact="${output_dir}/${artifact_name}"
	if [[ -e "${target_artifact}" || -L "${target_artifact}" ]]; then
		[[ -f "${target_artifact}" && ! -L "${target_artifact}" ]] \
			|| die "Destino existente inseguro: ${target_artifact}"
		cmp -s "${source_artifact}" "${target_artifact}" \
			|| die "Se rechaza sobrescribir artefacto divergente: ${target_artifact}"
	else
		install -m 0644 -- "${source_artifact}" "${target_artifact}"
	fi
done

printf 'PASS: artefactos Search 0.1.14 publicados localmente en %s\n' "${output_dir}"
