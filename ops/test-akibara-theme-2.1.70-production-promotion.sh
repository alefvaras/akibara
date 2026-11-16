#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "${script_dir}/.." && pwd -P)"
pipeline="${script_dir}/akibara-theme-2.1.70-production-promotion.sh"
pair_pipeline="${script_dir}/akibara-search-0.1.14-production-promotion.sh"
theme_contract="${script_dir}/contracts/akibara-gp-2.1.70.json"

die() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
sha256_file() { shasum -a 256 "$1" | awk '{print $1}'; }
write_sidecar() {
	( cd -- "$(dirname -- "$1")" && shasum -a 256 "$(basename -- "$1")" > "$(basename -- "$1").sha256" )
	chmod 0600 "$1" "${1}.sha256"
}

for tool in bash cp jq python3 shasum; do
	command -v "${tool}" >/dev/null 2>&1 || die "falta ${tool}"
done
for file in "${pipeline}" "${pair_pipeline}" "${theme_contract}"; do
	[[ -f "${file}" && ! -L "${file}" && -s "${file}" ]] || die "falta ${file}"
done
bash -n "${pipeline}"

grep -Fq 'mcp__hostinger_hosting__hosting_deployWordpressTheme' "${pipeline}" \
	|| die 'falta MCP oficial de Theme'
grep -Fq 'args:{activate:true,domain:$domain,slug:"akibara-gp",themePath:$candidate}' "${pipeline}" \
	|| die 'args MCP candidato no quedan exactos'
grep -Fq 'args:{activate:true,domain:$domain,slug:"akibara-gp",themePath:$rollback}' "${pipeline}" \
	|| die 'args MCP rollback no quedan exactos'
grep -Fq '["litespeed-ui","hostinger-mcp","cloudflare-mcp"]' "${pipeline}" \
	|| die 'orden de cache no queda exacto'
grep -Fq 'maintenance_off_before_cloudflare:true' "${pipeline}" \
	|| die 'mantenimiento OFF no precede contractualmente a Cloudflare'
grep -Fq 'graphify_allowed:false' "${pipeline}" \
	|| die 'Graphify no queda cerrado durante promoción'
grep -Fq 'graphify_allowed:true' "${pipeline}" \
	|| die 'verify-close no emite autorización explícita de Graphify'
grep -Fq 'public_html/wp-content/themes' "${pipeline}" \
	|| die 'limpieza remota no incluye themes'
grep -Fq 'online_store_without_public_storefront_v1' "${pipeline}" \
	|| die 'auditor público no exige tienda sólo online'
if grep -Eq '(^|[[:space:]])(ssh|scp|rsync|curl|wget)[[:space:]]' "${pipeline}"; then
	die 'el rail local contiene transporte remoto manual'
fi

jq -e '
  .promotion_requirements.phase1_candidate_receipt=={
    schema_version:3,kind:"akibara-business-search-verified-operation-receipt",
    purpose:"candidate",max_age_seconds:1800,sidecar_required:true,
    revalidate_material_bundle:true,same_release_digest:true}
  and .promotion_requirements.integrated_close_receipt=={
    schema_version:1,
    kind:"akibara-ai-discoverability-integrated-production-verified-receipt",
    verify_close_required:true}
  and .production_invariants.deployment_args=={
    activate:true,domain:"akibara.cl",slug:"akibara-gp",
    candidate_version:"2.1.70",rollback_version:"2.1.69"}
  and .production_invariants.public_ai_audit.online_only==true
  and .production_invariants.public_ai_audit.public_storefront==false
  and .production_invariants.remote_cleanup_paths==[
    "public_html","public_html/wp-content/plugins","public_html/wp-content/themes"]
  and .production_invariants.graphify_during_release_allowed==false
' "${theme_contract}" >/dev/null || die 'contrato Theme fase 2 divergente'

AKB_THEME_2170_PROMOTION_LIBRARY_ONLY=1
export AKB_THEME_2170_PROMOTION_LIBRARY_ONLY
# shellcheck source=ops/akibara-theme-2.1.70-production-promotion.sh
source "${pipeline}"
unset AKB_THEME_2170_PROMOTION_LIBRARY_ONLY

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-theme-promotion-test.XXXXXX")"
scratch="$(cd -- "${scratch}" && pwd -P)"
cleanup() { case "${scratch:-}" in */akibara-theme-promotion-test.*) rm -rf -- "${scratch}";; esac; }
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

release_id='akibara-search-0.1.14'
release_digest='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
production_domain='akibara.cl'
cloudflare_zone_id='b298201b3a38d017bc8413d9fd7414cd'
cloudflare_zone_name='akibara.cl'
cloudflare_account_id='11111111111111111111111111111111'
hostinger_username='fixture_user'
preflight_max_age=900
witness_max_age=1800
local_state_root="${scratch}/state"
theme_handoff_root="${local_state_root}/theme-handoffs"
theme_evidence_root="${local_state_root}/theme-evidence"
evidence_dir="${theme_evidence_root}/${release_digest}"
pair_evidence="${local_state_root}/evidence/${release_digest}"
handoff="${scratch}/handoff.json"
mkdir -p -- "${evidence_dir}" "${pair_evidence}"
chmod 0700 "${scratch}" "${local_state_root}" "${theme_evidence_root}" "${evidence_dir}" "${pair_evidence}"

python3 - "${scratch}" "${repo_root}" "${handoff}" "${evidence_dir}" "${pair_evidence}" \
	"${release_id}" "${release_digest}" "${cloudflare_account_id}" "${cloudflare_zone_id}" <<'PY'
import datetime as dt
import hashlib
import json
import pathlib
import shutil
import stat
import sys

scratch, repo, handoff_path, evidence, pair = map(pathlib.Path, sys.argv[1:6])
release_id, digest, account_id, zone_id = sys.argv[6:10]
now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)


def iso(offset):
    return (now + dt.timedelta(seconds=offset)).isoformat().replace("+00:00", "Z")


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def write(path, value):
    if isinstance(value, (dict, list)):
        payload = (json.dumps(value, ensure_ascii=False, separators=(",", ":")) + "\n").encode()
    elif isinstance(value, str):
        payload = value.encode()
    else:
        payload = value
    path.write_bytes(payload)
    path.chmod(0o600)
    return path


def copy(source, target):
    shutil.copyfile(source, target)
    target.chmod(0o600)
    return target


def desc(path):
    return {"path": path.name, "sha256": sha(path)}


def sidecar(path):
    write(path.with_name(path.name + ".sha256"), f"{sha(path)}  {path.name}\n")


def image(path):
    return write(path, b"\x89PNG\r\n\x1a\n" + b"0" * 48)


def listing(path, captured, names):
    return write(path, {"source": "hostinger-file-manager-ui", "path": captured[0],
                        "captured_at_utc": captured[1],
                        "entries": [{"name": name, "type": kind} for name, kind in names]})


theme_contract = json.loads((repo / "ops/contracts/akibara-gp-2.1.70.json").read_text())
search_contract = json.loads((repo / "ops/contracts/akibara-search-0.1.14.json").read_text())
business_contract = json.loads((repo / "ops/contracts/akibara-business-1.99.16.json").read_text())
theme_pre = repo / theme_contract["component"]["preimage"]["inventory"]
theme_candidate = repo / theme_contract["component"]["candidate"]["inventory"]
business_candidate = repo / business_contract["components"]["business"]["candidate"]["inventory"]
search_candidate = repo / search_contract["components"]["search"]["candidate"]["inventory"]

pair_business = copy(business_candidate, pair / "business-runtime.sha256")
pair_search = copy(search_candidate, pair / "search-runtime.sha256")
pair_commerce = write(pair / "commerce.tsv", "orders\tstable\t" + "1" * 64 + "\n")
pair_search_protected = write(pair / "search-protected.tsv", "index\tstable\t" + "2" * 64 + "\n")
pair_manifest = write(pair / "candidate-evidence.json", {
    "captures": {"business_runtime": desc(pair_business), "search_runtime": desc(pair_search),
                 "commerce": desc(pair_commerce), "search_protected": desc(pair_search_protected)}
})
write(pair / "candidate-verified-receipt.json", {"fixture": "phase1-candidate"})

ready = iso(-180)
pre_completed = iso(-150)
pre_verified = iso(-145)
started = iso(-140)
completed = iso(-120)
candidate_verified = iso(-115)
rollback_verified = iso(-110)
write(scratch / "times.json", {"pre_verified": pre_verified,
                                "candidate_verified": candidate_verified,
                                "rollback_verified": rollback_verified})

deploy_policy = theme_contract["production_invariants"]["deployment_receipt_policy"]
cache_result = search_contract["production_invariants"]["hostinger_cache"]["receipt_policy"]["exact_result"]
candidate_sequence = ["active-theme-baseline", "maintenance-on", "theme-deployed",
                      "active-theme-target-verified", "protected-pair-health-verified",
                      "litespeed-ui-purged", "hostinger-cache-purged", "maintenance-off",
                      "cloudflare-purged", "public-ai-audit-passed",
                      "final-evidence-cleanup-verified"]
rollback_sequence = ["maintenance-held-or-reactivated", "rollback-theme-deployed",
                     "active-theme-rollback-verified", "protected-pair-health-verified",
                     "litespeed-ui-purged", "hostinger-cache-purged", "maintenance-off",
                     "cloudflare-purged", "public-truth-health-verified",
                     "final-evidence-cleanup-verified"]
purge_code = f'async () => cloudflare.request({{method:"POST",path:"/zones/{zone_id}/purge_cache",body:{{purge_everything:true}}}})'


def operation(sequence, tree):
    return {
        "sequence": sequence,
        "deploy_theme": {"tool": "mcp__hostinger_hosting__hosting_deployWordpressTheme",
                         "args": {"activate": True, "domain": "akibara.cl", "slug": "akibara-gp",
                                  "themePath": str(tree)}, "receipt_policy": deploy_policy},
        "hostinger_cache": {"tool": "mcp__hostinger_hosting__hosting_clearWebsiteCacheV1",
                            "args": {"domain": "akibara.cl", "username": "fixture_user"},
                            "receipt_policy": {"exact_result": cache_result}},
        "cloudflare": {"tool": "mcp__cloudflare__execute",
                       "args": {"account_id": account_id, "code": purge_code},
                       "binding": {"account_id": account_id,
                                   "zone": {"id": zone_id, "name": "akibara.cl"}}},
    }


handoff = {
    "release_id": release_id, "theme_release_id": "akibara-gp-2.1.70",
    "release_digest": digest, "ready_at_utc": ready,
    "phase1": {"candidate_manifest": {"path": str(pair_manifest), "sha256": sha(pair_manifest)}},
    "operations": {
        "preflight": {"cloudflare_binding": {"binding": {"account_id": account_id,
                                                            "zone": {"id": zone_id, "name": "akibara.cl"}}}},
        "candidate": operation(candidate_sequence, scratch / "candidate/akibara-gp"),
        "rollback": operation(rollback_sequence, scratch / "rollback/akibara-gp"),
    },
    "evidence": {
        "root": str(evidence),
        "baseline": {"theme": {"version": "2.1.69", "inventory_sha256": sha(theme_pre),
                                  "runtime_files": len(theme_pre.read_text().splitlines())}},
        "candidate": {"theme": {"version": "2.1.70", "inventory_sha256": sha(theme_candidate),
                                   "runtime_files": len(theme_candidate.read_text().splitlines())}},
        "rollback": {"theme": {"version": "2.1.69", "inventory_sha256": sha(theme_pre),
                                  "runtime_files": len(theme_pre.read_text().splitlines())}},
        "protected_pair": {
            "business": {"version": "1.99.16", "inventory_sha256": sha(business_candidate),
                         "runtime_files": len(business_candidate.read_text().splitlines()), "active": True},
            "search": {"version": "0.1.14", "inventory_sha256": sha(search_candidate),
                       "runtime_files": len(search_candidate.read_text().splitlines()), "active": True},
        },
    },
}
write(handoff_path, handoff)
handoff_sha = sha(handoff_path)

pre_files = {}
pre_files["cloudflare_binding"] = write(evidence / "pre-cloudflare.json", {
    "success": True, "status": 200, "errors": [],
    "result": {"id": zone_id, "name": "akibara.cl", "account": {"id": account_id}}})
pre_files["pair_ui_text"] = write(evidence / "pre-ui.txt",
    "akibara.cl akibara-business 1.99.16 akibara-search 0.1.14 search_active=true "
    "akibara-gp 2.1.69 theme_active=true maintenance_mode=false\n")
pre_files["pair_ui_image"] = image(evidence / "pre-ui.png")
pre_files["theme_runtime_baseline"] = copy(theme_pre, evidence / "pre-theme.sha256")
pre_files["business_runtime"] = copy(business_candidate, evidence / "pre-business.sha256")
pre_files["search_runtime"] = copy(search_candidate, evidence / "pre-search.sha256")
pre_files["other_protected_runtime"] = write(evidence / "pre-other.sha256",
    "3" * 64 + "  public_html/wp-content/plugins/akibara-cart-checkout-blocks/a.php\n" +
    "4" * 64 + "  public_html/wp-content/plugins/bluex-for-woocommerce/b.php\n")
pre_files["commerce"] = write(evidence / "pre-commerce.tsv", pair_commerce.read_bytes())
pre_files["search_protected"] = write(evidence / "pre-search-protected.tsv", pair_search_protected.read_bytes())
pre_files["admin_status"] = write(evidence / "pre-admin.json", {
    "status": {"topology": "clean_pre", "rebuild": None,
               "work": {"pending": 0, "leased": 0, "blocked": 0, "error": ""},
               "docs": 25, "eligible": 25}})
pre_files["remote_webroot_listing"] = listing(evidence / "pre-webroot.json", ("public_html", iso(-160)), [("wp-content", "directory")])
pre_files["remote_plugins_listing"] = listing(evidence / "pre-plugins.json", ("public_html/wp-content/plugins", iso(-159)),
    [("akibara-business", "directory"), ("akibara-search", "directory")])
pre_files["remote_themes_listing"] = listing(evidence / "pre-themes.json", ("public_html/wp-content/themes", iso(-158)),
    [("akibara-gp", "directory"), ("generatepress", "directory")])
pre_files["remote_cleanup_image"] = image(evidence / "pre-cleanup.png")
pre_manifest = write(evidence / "theme-preflight-evidence.json", {
    "schema_version": 1, "kind": "akibara-theme-phase2-production-preflight-evidence",
    "release_id": release_id, "theme_release_id": "akibara-gp-2.1.70",
    "release_digest": digest, "handoff_sha256": handoff_sha,
    "completed_at_utc": pre_completed,
    "captures": {key: desc(path) for key, path in pre_files.items()},
})
sidecar(pre_manifest)


def build_operation(mode):
    sequence = candidate_sequence if mode == "candidate" else rollback_sequence
    maintenance = ([False, True, True, True, True, True, True, False, False, False, False]
                   if mode == "candidate" else [True, True, True, True, True, True, False, False, False, False])
    versions = (["2.1.69", "2.1.69"] + ["2.1.70"] * 9
                if mode == "candidate" else ["unverified"] + ["2.1.69"] * 9)
    checkpoints = []
    for index, checkpoint_id in enumerate(sequence):
        state = {"business_version": "1.99.16", "search_version": "0.1.14",
                 "search_active": True, "theme_version": versions[index],
                 "theme_active": True, "maintenance_mode": maintenance[index]}
        text = (f"{checkpoint_id} akibara.cl 1.99.16 0.1.14 search_active=true "
                f"{versions[index]} theme_active=true maintenance_mode={str(maintenance[index]).lower()}")
        if checkpoint_id == "litespeed-ui-purged":
            text += " LiteSpeed purge persisted success"
        text_path = write(evidence / f"{mode}-{index:02d}.txt", text + "\n")
        image_path = image(evidence / f"{mode}-{index:02d}.png")
        checkpoints.append({"id": checkpoint_id, "captured_at_utc": iso(-139 + index),
                            "state": state, "text": desc(text_path), "image": desc(image_path)})
    timeline = write(evidence / f"{mode}-timeline.json", {"purpose": mode, "checkpoints": checkpoints})
    expected = handoff["operations"][mode]
    deploy = write(evidence / f"{mode}-deploy.json", {
        "tool": expected["deploy_theme"]["tool"], "request": expected["deploy_theme"]["args"],
        "response": deploy_policy["allowed_ack_loss_result"]})
    cache = write(evidence / f"{mode}-hostinger-cache.json", {
        "tool": expected["hostinger_cache"]["tool"], "request": expected["hostinger_cache"]["args"],
        "response": cache_result})
    cloudflare = write(evidence / f"{mode}-cloudflare.json", {
        "tool": expected["cloudflare"]["tool"], "request": expected["cloudflare"]["args"],
        "response": {"success": True, "status": 200, "errors": [], "result": {"id": zone_id}}})
    target_inventory = theme_candidate if mode == "candidate" else theme_pre
    files = {
        "transition_timeline": timeline,
        "theme_deploy_response": deploy,
        "hostinger_cache_response": cache,
        "cloudflare_response": cloudflare,
        "theme_runtime": copy(target_inventory, evidence / f"{mode}-theme.sha256"),
        "business_runtime": copy(business_candidate, evidence / f"{mode}-business.sha256"),
        "search_runtime": copy(search_candidate, evidence / f"{mode}-search.sha256"),
        "other_protected_runtime": write(evidence / f"{mode}-other.sha256", pre_files["other_protected_runtime"].read_bytes()),
        "commerce": write(evidence / f"{mode}-commerce.tsv", pre_files["commerce"].read_bytes()),
        "search_protected": write(evidence / f"{mode}-search-protected.tsv", pre_files["search_protected"].read_bytes()),
        "admin_status": write(evidence / f"{mode}-admin.json", json.loads(pre_files["admin_status"].read_text())),
        "public_har": write(evidence / f"{mode}-public.har", {"log": {"entries": [
            {"request": {"url": "https://akibara.cl/"}, "response": {"status": 200}},
            {"request": {"url": "https://akibara.cl/manga/"}, "response": {"status": 200}},
            {"request": {"url": "https://akibara.cl/search/one-piece"}, "response": {"status": 200}},
        ]}}),
        "ai_audit_report": write(evidence / f"{mode}-ai-audit.json", {
            "schema_version": 3, "tool": "akibara-ai-discoverability-audit",
            "base_url": "https://akibara.cl/", "fetch_base_url": "https://akibara.cl/",
            "read_only": True, "ranking_guarantee": False, "audited_at": iso(-130),
            "configuration": {"business_model_contract": "online_store_without_public_storefront_v1",
                              "expected_pickup_locality": "San Miguel", "strict": True,
                              "expected_ai_train": "yes", "require_return_policy": True,
                              "require_llms_manga": True, "redirect_policy": "manual_fail_closed"},
            "checks": [{"id": check, "status": "pass"} for check in (
                "schema.canonical_online_store", "schema.online_store_without_physical_location",
                "content.about_online_store_truth", "content.public_surfaces_without_physical_location")],
            "summary": {"status": "pass", "exit_code": 0, "fail": 0, "warn": 0},
        }),
        "ai_audit_stderr": write(evidence / f"{mode}-ai-audit.stderr", b""),
        "remote_webroot_listing": listing(evidence / f"{mode}-webroot.json", ("public_html", iso(-124)), [("wp-content", "directory")]),
        "remote_plugins_listing": listing(evidence / f"{mode}-plugins.json", ("public_html/wp-content/plugins", iso(-123)),
            [("akibara-business", "directory"), ("akibara-search", "directory")]),
        "remote_themes_listing": listing(evidence / f"{mode}-themes.json", ("public_html/wp-content/themes", iso(-122)),
            [("akibara-gp", "directory"), ("generatepress", "directory")]),
        "remote_cleanup_image": image(evidence / f"{mode}-cleanup.png"),
    }
    manifest = write(evidence / f"theme-{mode}-evidence.json", {
        "schema_version": 1, "kind": "akibara-theme-phase2-production-operation-evidence",
        "release_id": release_id, "theme_release_id": "akibara-gp-2.1.70",
        "release_digest": digest, "handoff_sha256": handoff_sha,
        "purpose": mode, "started_at_utc": started, "completed_at_utc": completed,
        "captures": {key: desc(path) for key, path in files.items()},
    })
    sidecar(manifest)


build_operation("candidate")
build_operation("rollback")
PY

pre_verified="$(jq -er '.pre_verified' "${scratch}/times.json")"
candidate_verified="$(jq -er '.candidate_verified' "${scratch}/times.json")"
rollback_verified="$(jq -er '.rollback_verified' "${scratch}/times.json")"

theme_validate_evidence_bundle "${handoff}" "${evidence_dir}" preflight
theme_write_verified_receipt "${handoff}" "${evidence_dir}" preflight "${pre_verified}"
theme_validate_evidence_bundle "${handoff}" "${evidence_dir}" candidate
printf 'PASS evidencia preflight/candidato Theme material\n'

reject_json_capture_mutation() {
	local name="$1" key="$2" filter="$3" capture manifest backup_capture backup_manifest fresh_sha
	manifest="${evidence_dir}/theme-candidate-evidence.json"
	capture="${evidence_dir}/$(jq -er --arg key "${key}" '.captures[$key].path' "${manifest}")"
	backup_capture="${scratch}/backup-${key}"
	backup_manifest="${scratch}/backup-manifest-${key}.json"
	cp -p -- "${capture}" "${backup_capture}"
	cp -p -- "${manifest}" "${backup_manifest}"
	jq "${filter}" "${capture}" > "${capture}.mutated"
	chmod 0600 "${capture}.mutated"
	mv -- "${capture}.mutated" "${capture}"
	fresh_sha="$(sha256_file "${capture}")"
	jq --arg key "${key}" --arg sha "${fresh_sha}" '.captures[$key].sha256=$sha' "${manifest}" > "${manifest}.mutated"
	chmod 0600 "${manifest}.mutated"
	mv -- "${manifest}.mutated" "${manifest}"
	write_sidecar "${manifest}"
	if theme_validate_evidence_bundle "${handoff}" "${evidence_dir}" candidate >/dev/null 2>&1; then
		die "mutación aceptada: ${name}"
	fi
	cp -p -- "${backup_capture}" "${capture}"
	cp -p -- "${backup_manifest}" "${manifest}"
	write_sidecar "${manifest}"
	printf 'PASS mutación rechazada: %s\n' "${name}"
}

reject_json_capture_mutation 'fallo explícito del deploy' theme_deploy_response '.response={success:false}'
reject_json_capture_mutation 'timeline reordenada' transition_timeline '.checkpoints=([.checkpoints[1],.checkpoints[0]]+.checkpoints[2:])'
reject_json_capture_mutation 'dirección pública en auditor IA' ai_audit_report '.configuration.expected_address="private-address-forbidden"'
reject_json_capture_mutation 'residuo ZIP remoto' remote_themes_listing '.entries += [{name:"release.zip",type:"file"}]'

theme_write_verified_receipt "${handoff}" "${evidence_dir}" candidate "${candidate_verified}"
theme_write_close_candidate "${handoff}" "${evidence_dir}"
jq -e '.graphify_allowed==false and .kind=="akibara-ai-discoverability-integrated-production-close-receipt"' \
	"${evidence_dir}/integrated-close-receipt.json" >/dev/null \
	|| die 'cierre candidato autorizó Graphify antes de verify-close'

# El fixture ya probó el validador material. Sólo dentro de este subshell se
# sustituye la validación estructural del handoff, que exige el árbol canónico.
( theme_verify_handoff_file() { :; }; theme_verify_close "${handoff}" "${evidence_dir}" )
jq -e '.graphify_allowed==true and .kind=="akibara-ai-discoverability-integrated-production-verified-receipt"' \
	"${evidence_dir}/integrated-close-verified-receipt.json" >/dev/null \
	|| die 'verify-close no emitió el receipt final exacto'

theme_validate_evidence_bundle "${handoff}" "${evidence_dir}" rollback
theme_write_verified_receipt "${handoff}" "${evidence_dir}" rollback "${rollback_verified}"
theme_write_close_revocation "${evidence_dir}"
jq -e '.graphify_allowed==false and .kind=="akibara-ai-discoverability-integrated-production-close-revocation"' \
	"${evidence_dir}/integrated-close-revocation-receipt.json" >/dev/null \
	|| die 'rollback no revocó el cierre integrado'
if ( theme_verify_handoff_file() { :; }; theme_verify_close "${handoff}" "${evidence_dir}" ) >/dev/null 2>&1; then
	die 'verify-close aceptó un rollback posterior'
fi

# Handoff estructural real: usa los ZIP/inventarios sellados, pero sólo escribe
# árboles bajo el scratch y sustituye el bridge de fase 1 por archivos locales.
release_digest='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
contract_sha="$(sha256_file "${repo_root}/ops/contracts/akibara-search-0.1.14.json")"
business_contract_sha="$(sha256_file "${repo_root}/ops/contracts/akibara-business-1.99.16.json")"
theme_contract_sha="$(sha256_file "${theme_contract}")"
theme_candidate_archive="${repo_root}/$(jq -er '.component.candidate.archive' "${theme_contract}")"
theme_candidate_inventory="${repo_root}/$(jq -er '.component.candidate.inventory' "${theme_contract}")"
theme_candidate_sidecar="${repo_root}/$(jq -er '.component.candidate.checksum_sidecar' "${theme_contract}")"
theme_candidate_archive_sha="$(jq -er '.component.candidate.archive_sha256' "${theme_contract}")"
theme_candidate_inventory_sha="$(jq -er '.component.candidate.inventory_sha256' "${theme_contract}")"
theme_candidate_sidecar_sha="$(jq -er '.component.candidate.checksum_sidecar_sha256' "${theme_contract}")"
theme_preimage_archive="${repo_root}/$(jq -er '.component.preimage.archive' "${theme_contract}")"
theme_preimage_inventory="${repo_root}/$(jq -er '.component.preimage.inventory' "${theme_contract}")"
theme_preimage_sidecar="${repo_root}/$(jq -er '.component.preimage.checksum_sidecar' "${theme_contract}")"
theme_preimage_archive_sha="$(jq -er '.component.preimage.archive_sha256' "${theme_contract}")"
theme_preimage_inventory_sha="$(jq -er '.component.preimage.inventory_sha256' "${theme_contract}")"
theme_preimage_sidecar_sha="$(jq -er '.component.preimage.checksum_sidecar_sha256' "${theme_contract}")"
candidate_inventory="${repo_root}/$(jq -er '.components.search.candidate.inventory' "${repo_root}/ops/contracts/akibara-search-0.1.14.json")"
candidate_inventory_sha="$(jq -er '.components.search.candidate.inventory_sha256' "${repo_root}/ops/contracts/akibara-search-0.1.14.json")"
business_candidate_inventory="${repo_root}/$(jq -er '.components.business.candidate.inventory' "${repo_root}/ops/contracts/akibara-business-1.99.16.json")"
business_candidate_inventory_sha="$(jq -er '.components.business.candidate.inventory_sha256' "${repo_root}/ops/contracts/akibara-business-1.99.16.json")"
local_state_root="${scratch}/exact-state"
theme_handoff_root="${local_state_root}/theme-handoffs"
theme_evidence_root="${local_state_root}/theme-evidence"
mkdir -p -- "${local_state_root}/gates" "${local_state_root}/handoffs/${release_digest}" \
	"${local_state_root}/evidence/${release_digest}"
chmod 0700 "${local_state_root}" "${local_state_root}/gates" \
	"${local_state_root}/handoffs" "${local_state_root}/handoffs/${release_digest}" \
	"${local_state_root}/evidence" "${local_state_root}/evidence/${release_digest}"
for bridge in \
	"${local_state_root}/gates/${release_digest}.json" \
	"${local_state_root}/handoffs/${release_digest}/handoff.json" \
	"${local_state_root}/evidence/${release_digest}/candidate-evidence.json" \
	"${local_state_root}/evidence/${release_digest}/candidate-verified-receipt.json"; do
	printf '{"fixture":true}\n' > "${bridge}"
	chmod 0600 "${bridge}"
done
theme_load_context_from_config() { :; }
theme_verify_pair_candidate_receipt() { :; }
theme_prepare_handoff "${scratch}/unused.conf" >/dev/null
exact_handoff="$(theme_phase2_handoff_path)"
theme_verify_handoff_file "${exact_handoff}"
cp -p -- "${exact_handoff}" "${scratch}/exact-handoff-good.json"

reject_handoff_mutation() {
	local name="$1" filter="$2"
	jq "${filter}" "${scratch}/exact-handoff-good.json" > "${exact_handoff}.mutated"
	chmod 0600 "${exact_handoff}.mutated"
	mv -- "${exact_handoff}.mutated" "${exact_handoff}"
	write_sidecar "${exact_handoff}"
	if ( theme_verify_handoff_file "${exact_handoff}" ) >/dev/null 2>&1; then
		die "handoff aceptó: ${name}"
	fi
	cp -p -- "${scratch}/exact-handoff-good.json" "${exact_handoff}"
	write_sidecar "${exact_handoff}"
	printf 'PASS mutación handoff rechazada: %s\n' "${name}"
}

reject_handoff_mutation 'activate=false' '.operations.candidate.deploy_theme.args.activate=false'
reject_handoff_mutation 'Graphify prematuro' '.graphify_allowed=true'
reject_handoff_mutation 'orden cache alterado' '.evidence.cache_order|=reverse'
printf 'residuo\n' > "$(dirname -- "${exact_handoff}")/unexpected.tmp"
if ( theme_verify_handoff_file "${exact_handoff}" ) >/dev/null 2>&1; then
	die 'handoff aceptó archivo extra'
fi
rm -f -- "$(dirname -- "${exact_handoff}")/unexpected.tmp"
theme_verify_handoff_file "${exact_handoff}"

printf 'PASS Theme fase 2: handoff exacto, evidencia material, 7 mutaciones, cierre y revocación\n'
