#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
contract_path="${repo_root}/ops/contracts/akibara-gp-2.1.70.json"

command -v python3 >/dev/null 2>&1 || {
	echo "Falta el comando requerido: python3" >&2
	exit 1
}

python3 - "${repo_root}" "${contract_path}" <<'PY'
from __future__ import annotations

import copy
import hashlib
import json
import os
import re
import stat
import sys
import zipfile
from pathlib import Path, PurePosixPath
from typing import Any, Callable, Iterable


class ContractError(RuntimeError):
    pass


def fail(message: str) -> None:
    raise ContractError(message)


def require(condition: bool, message: str) -> None:
    if not condition:
        fail(message)


def exact_keys(value: Any, expected: Iterable[str], label: str) -> None:
    require(isinstance(value, dict), f"{label}: se esperaba un objeto")
    actual = set(value)
    wanted = set(expected)
    missing = sorted(wanted - actual)
    extra = sorted(actual - wanted)
    require(not missing and not extra, f"{label}: claves ausentes={missing}, extra={extra}")


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


repo_root = Path(sys.argv[1]).resolve(strict=True)
contract_path = Path(sys.argv[2]).resolve(strict=True)
hash_re = re.compile(r"^[a-f0-9]{64}$")


def safe_repo_path(relative: str, label: str) -> Path:
    require(isinstance(relative, str) and relative, f"{label}: ruta vacía")
    require("\\" not in relative and "\x00" not in relative, f"{label}: ruta no POSIX")
    pure = PurePosixPath(relative)
    require(not pure.is_absolute(), f"{label}: ruta absoluta")
    require(all(part not in ("", ".", "..") for part in pure.parts), f"{label}: traversal")
    raw = repo_root.joinpath(*pure.parts)
    resolved = raw.resolve(strict=False)
    require(os.path.commonpath((str(repo_root), str(resolved))) == str(repo_root), f"{label}: escapa del repo")
    return raw


def pinned_regular_file(relative: str, expected_hash: str, label: str) -> Path:
    require(bool(hash_re.fullmatch(expected_hash)), f"{label}: SHA-256 inválido")
    path = safe_repo_path(relative, label)
    require(path.exists(), f"{label}: archivo ausente: {relative}")
    mode = path.lstat().st_mode
    require(stat.S_ISREG(mode) and not path.is_symlink(), f"{label}: no es archivo regular")
    actual_hash = sha256_file(path)
    require(actual_hash == expected_hash, f"{label}: SHA-256 esperado {expected_hash}, obtenido {actual_hash}")
    return path


def load_contract() -> tuple[dict[str, Any], bytes]:
    raw = contract_path.read_bytes()
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError as exc:
        fail(f"contrato JSON inválido: {exc}")
    require(isinstance(parsed, dict), "contrato: raíz no es objeto")
    return parsed, raw


contract, contract_raw = load_contract()


def validate_shape(value: dict[str, Any]) -> None:
    exact_keys(
        value,
        (
            "schema_version", "contract", "release_id", "seal_state", "sealed_at_utc",
            "promotion_sequence", "exclusive_targets", "component", "headers",
            "forbidden_runtime", "residential_truth_policy", "quality_gates",
            "promotion_requirements", "integrated_release_binding",
            "production_invariants",
        ),
        "contrato",
    )
    component = value["component"]
    exact_keys(
        component,
        (
            "kind", "slug", "version", "source_root", "source_exact", "packager",
            "preimage", "candidate", "required_runtime_paths",
        ),
        "component",
    )
    exact_keys(
        component["source_exact"],
        (
            "mode", "inventory", "inventory_sha256", "runtime_files",
            "included_top_level", "excluded_top_level",
        ),
        "component.source_exact",
    )
    exact_keys(
        component["packager"],
        ("path", "sha256", "component", "command", "verify_command"),
        "component.packager",
    )
    for role in ("preimage", "candidate"):
        exact_keys(
            component[role],
            (
                "version", "archive", "archive_sha256", "checksum_sidecar",
                "checksum_sidecar_sha256", "inventory", "inventory_sha256",
                "runtime_files", "archive_policy",
            ),
            f"component.{role}",
        )
        exact_keys(
            component[role]["archive_policy"],
            ("root", "entries", "file_mode", "compression", "mtime_utc", "order"),
            f"component.{role}.archive_policy",
        )
    exact_keys(value["headers"], ("style_css", "functions_php", "theme_json"), "headers")
    exact_keys(
        value["headers"]["style_css"],
        ("path", "sha256", "required_fields"),
        "headers.style_css",
    )
    exact_keys(value["headers"]["functions_php"], ("path", "sha256"), "headers.functions_php")
    exact_keys(
        value["headers"]["theme_json"],
        ("path", "sha256", "schema", "schema_version", "title"),
        "headers.theme_json",
    )
    exact_keys(
        value["forbidden_runtime"],
        ("path_segments", "file_names", "file_prefixes", "file_suffixes", "special_files_allowed"),
        "forbidden_runtime",
    )
    truth = value["residential_truth_policy"]
    exact_keys(
        truth,
        (
            "operating_model", "public_storefront", "pickup_policy", "sii_tax_domicile",
            "exact_residential_address_must_not_appear", "public_schema_types_forbidden",
            "public_location_properties_forbidden", "text_extensions",
            "forbidden_content_patterns",
        ),
        "residential_truth_policy",
    )
    exact_keys(
        truth["sii_tax_domicile"],
        ("acknowledged", "is_public_storefront_evidence", "sufficient_as_sole_legal_domicile_evidence"),
        "residential_truth_policy.sii_tax_domicile",
    )
    for index, pattern in enumerate(truth["forbidden_content_patterns"]):
        exact_keys(pattern, ("id", "regex", "flags"), f"forbidden_content_patterns[{index}]")
    promotion = value["promotion_requirements"]
    exact_keys(
        promotion,
        (
            "phase1_candidate_receipt", "handoff", "preflight_receipt",
            "operation_receipt", "integrated_close_receipt", "cache_order",
            "maintenance_off_before_cloudflare", "graphify_before_verify_close_allowed",
        ),
        "promotion_requirements",
    )
    exact_keys(
        promotion["phase1_candidate_receipt"],
        (
            "schema_version", "kind", "purpose", "max_age_seconds",
            "sidecar_required", "revalidate_material_bundle", "same_release_digest",
        ),
        "promotion_requirements.phase1_candidate_receipt",
    )
    exact_keys(promotion["handoff"], ("schema_version", "kind", "exclusive_theme_only"), "promotion_requirements.handoff")
    exact_keys(promotion["preflight_receipt"], ("schema_version", "kind", "max_age_seconds"), "promotion_requirements.preflight_receipt")
    exact_keys(promotion["operation_receipt"], ("schema_version", "kind", "purposes"), "promotion_requirements.operation_receipt")
    exact_keys(promotion["integrated_close_receipt"], ("schema_version", "kind", "verify_close_required"), "promotion_requirements.integrated_close_receipt")
    gates = value["quality_gates"]
    exact_keys(gates, ("static_contract", "node_theme", "phpunit_theme", "package_verify", "production_handoff"), "quality_gates")
    exact_keys(
        gates["static_contract"],
        ("path", "sha256", "command", "negative_mutations_required"),
        "quality_gates.static_contract",
    )
    exact_keys(
        gates["node_theme"],
        ("command", "expected_tests", "expected_failures", "test_files"),
        "quality_gates.node_theme",
    )
    exact_keys(
        gates["phpunit_theme"],
        ("command", "config", "config_sha256", "expected_tests", "expected_assertions", "test_files"),
        "quality_gates.phpunit_theme",
    )
    exact_keys(
        gates["package_verify"],
        ("command", "expected_runtime_files", "expected_archive_sha256"),
        "quality_gates.package_verify",
    )
    exact_keys(gates["production_handoff"], ("rail", "test", "library", "required_passes"), "quality_gates.production_handoff")
    exact_keys(gates["production_handoff"]["rail"], ("path", "sha256"), "quality_gates.production_handoff.rail")
    exact_keys(gates["production_handoff"]["test"], ("path", "sha256", "command"), "quality_gates.production_handoff.test")
    exact_keys(gates["production_handoff"]["library"], ("path", "sha256"), "quality_gates.production_handoff.library")
    for suite_name in ("node_theme", "phpunit_theme"):
        for index, item in enumerate(gates[suite_name]["test_files"]):
            exact_keys(item, ("path", "sha256"), f"quality_gates.{suite_name}.test_files[{index}]")
    exact_keys(
        value["integrated_release_binding"],
        (
            "required", "required_external_binding_fields", "theme_contract_path", "binding_policy",
            "mixed_theme_version_allowed", "contract_reseal_required_after_any_pinned_input_change",
        ),
        "integrated_release_binding",
    )
    exact_keys(
        value["production_invariants"],
        (
            "deployment_transport", "deployment_args", "protected_pair",
            "deployment_receipt_policy", "public_ai_audit", "remote_cleanup_paths",
            "remote_backups_allowed", "rollback_artifacts_location",
            "graphify_during_release_allowed", "graphify_after_confirmed_public_verification",
            "production_domain",
        ),
        "production_invariants",
    )


def require_unique_strings(values: Any, label: str) -> list[str]:
    require(isinstance(values, list) and values, f"{label}: lista vacía o inválida")
    require(all(isinstance(item, str) and item for item in values), f"{label}: valor no textual")
    require(len(values) == len(set(values)), f"{label}: duplicados")
    return values


def validate_semantics(value: dict[str, Any]) -> None:
    require(value["schema_version"] == 1, "schema_version inesperada")
    require(value["contract"] == "akibara-gp-production-release", "identidad de contrato inesperada")
    require(value["release_id"] == "akibara-gp-2.1.70", "release_id inesperado")
    require(value["seal_state"] == "sealed", "contrato no sellado")
    require(bool(re.fullmatch(r"2026-08-(?:06|07)T\d{2}:\d{2}:\d{2}Z", value["sealed_at_utc"])), "sello UTC inválido")
    require(value["promotion_sequence"] == ["docker-local-integrated", "production-ui-integrated"], "secuencia de promoción inválida")
    require(value["exclusive_targets"] == ["public_html/wp-content/themes/akibara-gp"], "targets inesperados")

    component = value["component"]
    require((component["kind"], component["slug"], component["version"]) == ("theme", "akibara-gp", "2.1.70"), "identidad del theme inválida")
    require(component["source_root"] == "public_html/wp-content/themes/akibara-gp", "source_root inesperado")
    source = component["source_exact"]
    preimage = component["preimage"]
    candidate = component["candidate"]
    require(source["mode"] == "byte-exact-runtime-inventory", "modo de fuente no fail-closed")
    require(source["runtime_files"] == candidate["runtime_files"] == 103, "cantidad runtime distinta de 103")
    require(source["inventory"] == candidate["inventory"] == "dist/akibara-gp-2.1.70.files.sha256", "inventario fuente/candidato divergente")
    require(source["inventory_sha256"] == candidate["inventory_sha256"], "SHA de inventario fuente/candidato divergente")
    require_unique_strings(source["included_top_level"], "included_top_level")
    require(source["excluded_top_level"] == ["tests"], "exclusiones del theme inesperadas")
    require_unique_strings(component["required_runtime_paths"], "required_runtime_paths")
    for item in source["included_top_level"] + source["excluded_top_level"] + component["required_runtime_paths"]:
        require(not PurePosixPath(item).is_absolute() and ".." not in PurePosixPath(item).parts, f"ruta fuente insegura: {item}")

    packager = component["packager"]
    require(packager["path"] == "tools/akibara-releases/package-runtime.sh", "packager inesperado")
    require(packager["component"] == "theme", "selector de packager inesperado")
    require(packager["command"] == "bash tools/akibara-releases/package-runtime.sh theme", "comando de packager inesperado")
    require(packager["verify_command"] == "bash tools/akibara-releases/package-runtime.sh theme --verify-only", "verify del packager inesperado")
    require(candidate["version"] == component["version"], "versión de candidato divergente")
    require(candidate["archive"] == "dist/akibara-gp-2.1.70.zip", "archivo candidato inesperado")
    require(candidate["checksum_sidecar"] == "dist/akibara-gp-2.1.70.zip.sha256", "sidecar inesperado")
    archive_policy = candidate["archive_policy"]
    require(archive_policy == {
        "root": "akibara-gp",
        "entries": "regular-files-only",
        "file_mode": "0644",
        "compression": "stored",
        "mtime_utc": "1980-01-01T00:00:00Z",
        "order": "bytewise-ascending",
    }, "política ZIP inesperada")
    require(preimage == {
        "version": "2.1.69",
        "archive": "dist/akibara-gp-2.1.69.zip",
        "archive_sha256": "dfbfd1717b5f9e4e6c126ff59cabe10c4d410563819515f9334631ecb63b641b",
        "checksum_sidecar": "dist/akibara-gp-2.1.69.zip.sha256",
        "checksum_sidecar_sha256": "17d8ea032896f31bf21603f035473195fb55708914c841524a59c3dba1b6858f",
        "inventory": "dist/akibara-gp-2.1.69.files.sha256",
        "inventory_sha256": "f58dd384851468a6993783ca8da7b59ba7349906f556957d14ea40d4ca448eb2",
        "runtime_files": 103,
        "archive_policy": archive_policy,
    }, "preimagen Theme 2.1.69 inesperado")

    forbidden = value["forbidden_runtime"]
    for key in ("path_segments", "file_names", "file_prefixes", "file_suffixes"):
        require_unique_strings(forbidden[key], f"forbidden_runtime.{key}")
    require(forbidden["special_files_allowed"] is False, "entradas especiales habilitadas")
    require("tests" in forbidden["path_segments"] and ".zip" in forbidden["file_suffixes"], "guardas runtime incompletas")

    truth = value["residential_truth_policy"]
    require(truth["operating_model"] == "online-only-no-public-storefront", "modelo comercial inesperado")
    require(truth["public_storefront"] is False, "el contrato habilita storefront")
    require(truth["pickup_policy"] == "coordinated-only-exact-address-after-confirmed-order", "política de retiro inesperada")
    tax = truth["sii_tax_domicile"]
    require(tax == {
        "acknowledged": True,
        "is_public_storefront_evidence": False,
        "sufficient_as_sole_legal_domicile_evidence": False,
    }, "el domicilio tributario SII se está usando como evidencia indebida")
    require(truth["exact_residential_address_must_not_appear"] is True, "dirección residencial no protegida")
    require(truth["public_schema_types_forbidden"] == ["LocalBusiness", "Place"], "tipos físicos no bloqueados")
    require(set(truth["public_location_properties_forbidden"]) == {"streetAddress", "postalCode", "addressLocality", "hasMap", "geo", "location"}, "propiedades físicas no bloqueadas")
    require_unique_strings(truth["text_extensions"], "text_extensions")
    require(len(truth["forbidden_content_patterns"]) >= 4, "patrones de contenido insuficientes")
    pattern_ids = [item["id"] for item in truth["forbidden_content_patterns"]]
    require(len(pattern_ids) == len(set(pattern_ids)), "IDs de patrón duplicados")

    promotion = value["promotion_requirements"]
    require(promotion == {
        "phase1_candidate_receipt": {
            "schema_version": 3,
            "kind": "akibara-business-search-verified-operation-receipt",
            "purpose": "candidate",
            "max_age_seconds": 1800,
            "sidecar_required": True,
            "revalidate_material_bundle": True,
            "same_release_digest": True,
        },
        "handoff": {
            "schema_version": 1,
            "kind": "akibara-theme-phase2-mcp-handoff",
            "exclusive_theme_only": True,
        },
        "preflight_receipt": {
            "schema_version": 1,
            "kind": "akibara-theme-phase2-verified-preflight-receipt",
            "max_age_seconds": 900,
        },
        "operation_receipt": {
            "schema_version": 1,
            "kind": "akibara-theme-phase2-verified-operation-receipt",
            "purposes": ["candidate", "rollback"],
        },
        "integrated_close_receipt": {
            "schema_version": 1,
            "kind": "akibara-ai-discoverability-integrated-production-verified-receipt",
            "verify_close_required": True,
        },
        "cache_order": ["litespeed-ui", "hostinger-mcp", "cloudflare-mcp"],
        "maintenance_off_before_cloudflare": True,
        "graphify_before_verify_close_allowed": False,
    }, "protocolo de promoción Theme inesperado")

    gates = value["quality_gates"]
    require(gates["static_contract"]["path"] == "tools/akibara-releases/test-theme-2.1.70-production-release.sh", "test estático inesperado")
    require(gates["static_contract"]["command"] == "bash tools/akibara-releases/test-theme-2.1.70-production-release.sh", "comando estático inesperado")
    require(gates["static_contract"]["negative_mutations_required"] == 10, "presupuesto de mutaciones negativas inesperado")
    require(gates["node_theme"]["command"] == "node --test public_html/wp-content/themes/akibara-gp/tests/*.test.js", "suite Node inesperada")
    require((gates["node_theme"]["expected_tests"], gates["node_theme"]["expected_failures"]) == (31, 0), "expectativa Node inesperada")
    require(gates["phpunit_theme"]["command"] == "tools/akibara-business/vendor/bin/phpunit -c tools/akibara-gp/phpunit.xml.dist", "suite PHPUnit inesperada")
    require(gates["phpunit_theme"]["config"] == "tools/akibara-gp/phpunit.xml.dist", "config PHPUnit inesperado")
    require((gates["phpunit_theme"]["expected_tests"], gates["phpunit_theme"]["expected_assertions"]) == (18, 52), "expectativa PHPUnit inesperada")
    require(gates["package_verify"] == {
        "command": packager["verify_command"],
        "expected_runtime_files": 103,
        "expected_archive_sha256": candidate["archive_sha256"],
    }, "gate de package divergente")
    require(gates["production_handoff"]["rail"]["path"] == "ops/akibara-theme-2.1.70-production-promotion.sh", "rail Theme inesperado")
    require(gates["production_handoff"]["test"]["path"] == "ops/test-akibara-theme-2.1.70-production-promotion.sh", "test del rail Theme inesperado")
    require(gates["production_handoff"]["test"]["command"] == "bash ops/test-akibara-theme-2.1.70-production-promotion.sh", "comando del rail Theme inesperado")
    require(gates["production_handoff"]["library"]["path"] == "ops/akibara-search-0.1.14-production-promotion.sh", "librería pareada inesperada")
    require(gates["production_handoff"]["required_passes"] == 2, "el rail Theme debe pasar dos veces")

    binding = value["integrated_release_binding"]
    require(binding["required"] is True, "binding integrado no requerido")
    require(binding["required_external_binding_fields"] == [
        "theme_contract_path",
        "theme_contract_sha256",
        "theme_version",
        "theme_archive_sha256",
        "theme_inventory_sha256",
        "theme_rollback_version",
        "theme_rollback_archive_sha256",
        "theme_rollback_inventory_sha256",
    ], "campos de binding integrado inesperados")
    require(binding["theme_contract_path"] == "ops/contracts/akibara-gp-2.1.70.json", "ruta de binding inesperada")
    require(binding["mixed_theme_version_allowed"] is False, "versiones mixtas habilitadas")
    require(binding["contract_reseal_required_after_any_pinned_input_change"] is True, "reseal no requerido")
    invariants = value["production_invariants"]
    require(invariants == {
        "deployment_transport": "mcp__hostinger_hosting__hosting_deployWordpressTheme",
        "deployment_args": {
            "activate": True,
            "domain": "akibara.cl",
            "slug": "akibara-gp",
            "candidate_version": "2.1.70",
            "rollback_version": "2.1.69",
        },
        "protected_pair": {
            "business": {"slug": "akibara-business", "version": "1.99.16", "active": True},
            "search": {"slug": "akibara-search", "version": "0.1.14", "active": True},
        },
        "deployment_receipt_policy": {
            "transport_response_is_release_proof": False,
            "allowed_ack_loss_result": {
                "Err": "tool call error: tool call failed for `hostinger-hosting/hosting_deployWordpressTheme`\n\nCaused by:\n    timed out awaiting tools/call after 120s",
            },
            "explicit_failure_rejected": True,
            "postconditions_always_required": [
                "wordpress-ui-active-theme-version",
                "theme-runtime-inventory",
                "business-runtime-inventory",
                "search-runtime-inventory",
                "complete-theme-witness-bundle",
            ],
        },
        "public_ai_audit": {
            "schema_version": 3,
            "base_url": "https://akibara.cl/",
            "fetch_base_url": "https://akibara.cl/",
            "strict": True,
            "expected_pickup_locality": "San Miguel",
            "expected_ai_train": "yes",
            "require_return_policy": True,
            "require_llms_manga": True,
            "redirect_policy": "manual_fail_closed",
            "online_only": True,
            "public_storefront": False,
        },
        "remote_cleanup_paths": [
            "public_html",
            "public_html/wp-content/plugins",
            "public_html/wp-content/themes",
        ],
        "remote_backups_allowed": False,
        "rollback_artifacts_location": "local-only",
        "graphify_during_release_allowed": False,
        "graphify_after_confirmed_public_verification": True,
        "production_domain": "akibara.cl",
    }, "invariantes de producción inesperadas")


def assert_all_pinned_files(value: dict[str, Any]) -> None:
    component = value["component"]
    for role in ("preimage", "candidate"):
        artifact = component[role]
        pinned_regular_file(artifact["archive"], artifact["archive_sha256"], f"{role}.archive")
        pinned_regular_file(artifact["checksum_sidecar"], artifact["checksum_sidecar_sha256"], f"{role}.sidecar")
        pinned_regular_file(artifact["inventory"], artifact["inventory_sha256"], f"{role}.inventory")
    pinned_regular_file(component["packager"]["path"], component["packager"]["sha256"], "packager")
    for name in ("style_css", "functions_php", "theme_json"):
        item = value["headers"][name]
        pinned_regular_file(item["path"], item["sha256"], f"headers.{name}")
    gates = value["quality_gates"]
    pinned_regular_file(gates["static_contract"]["path"], gates["static_contract"]["sha256"], "static_contract")
    for role in ("rail", "test", "library"):
        item = gates["production_handoff"][role]
        pinned_regular_file(item["path"], item["sha256"], f"production_handoff.{role}")
    pinned_regular_file(gates["phpunit_theme"]["config"], gates["phpunit_theme"]["config_sha256"], "phpunit.config")
    for suite_name in ("node_theme", "phpunit_theme"):
        for index, item in enumerate(gates[suite_name]["test_files"]):
            pinned_regular_file(item["path"], item["sha256"], f"{suite_name}.test_files[{index}]")


def safe_posix_runtime_path(relative: str, value: dict[str, Any], label: str) -> PurePosixPath:
    require(isinstance(relative, str) and relative, f"{label}: ruta vacía")
    require("\\" not in relative and "\x00" not in relative, f"{label}: ruta no POSIX")
    pure = PurePosixPath(relative)
    require(not pure.is_absolute(), f"{label}: ruta absoluta")
    require(all(part not in ("", ".", "..") for part in pure.parts), f"{label}: traversal")
    root = value["component"]["candidate"]["archive_policy"]["root"]
    require(len(pure.parts) >= 2 and pure.parts[0] == root, f"{label}: raíz ZIP inesperada")
    forbidden = value["forbidden_runtime"]
    lowered_parts = [part.casefold() for part in pure.parts]
    forbidden_segments = {part.casefold() for part in forbidden["path_segments"]}
    require(not forbidden_segments.intersection(lowered_parts), f"{label}: segmento runtime prohibido")
    basename = pure.name
    basename_folded = basename.casefold()
    require(basename_folded not in {item.casefold() for item in forbidden["file_names"]}, f"{label}: nombre runtime prohibido")
    require(not any(basename.startswith(prefix) for prefix in forbidden["file_prefixes"]), f"{label}: prefijo runtime prohibido")
    require(not any(basename_folded.endswith(suffix.casefold()) for suffix in forbidden["file_suffixes"]), f"{label}: sufijo runtime prohibido")
    return pure


def parse_inventory(raw: bytes, value: dict[str, Any]) -> list[tuple[str, str]]:
    require(raw.endswith(b"\n"), "inventario sin newline final")
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError as exc:
        fail(f"inventario no UTF-8: {exc}")
    entries: list[tuple[str, str]] = []
    for number, line in enumerate(text.splitlines(), 1):
        match = re.fullmatch(r"([a-f0-9]{64})  ([^\r\n]+)", line)
        require(match is not None, f"inventario línea {number}: formato inválido")
        digest, relative = match.groups()
        safe_posix_runtime_path(relative, value, f"inventario línea {number}")
        entries.append((digest, relative))
    require(entries, "inventario vacío")
    paths = [relative for _, relative in entries]
    require(len(paths) == len(set(paths)), "inventario con rutas duplicadas")
    require(paths == sorted(paths, key=lambda item: item.encode("utf-8")), "inventario fuera de orden bytewise")
    return entries


def validate_inventory_count(entries: list[tuple[str, str]], value: dict[str, Any], role: str) -> None:
    expected = value["component"][role]["runtime_files"]
    require(len(entries) == expected, f"inventario: {len(entries)} archivos; se esperaban {expected}")


def validate_sidecar(value: dict[str, Any], role: str) -> None:
    artifact = value["component"][role]
    sidecar = safe_repo_path(artifact["checksum_sidecar"], f"{role}.sidecar").read_bytes()
    expected = f"{artifact['archive_sha256']}  {PurePosixPath(artifact['archive']).name}\n".encode("ascii")
    require(sidecar == expected, f"{role}: sidecar no coincide exactamente con el ZIP sellado")


def validate_zip(entries: list[tuple[str, str]], value: dict[str, Any], role: str) -> None:
    artifact = value["component"][role]
    archive = safe_repo_path(artifact["archive"], f"{role}.archive")
    expected_paths = [relative for _, relative in entries]
    expected_hashes = {relative: digest for digest, relative in entries}
    policy = artifact["archive_policy"]
    with zipfile.ZipFile(archive) as bundle:
        infos = bundle.infolist()
        names = [info.filename for info in infos]
        require(names == expected_paths, "ZIP: orden o inventario no coincide con el sidecar de archivos")
        require(bundle.testzip() is None, "ZIP: CRC inválido")
        for info in infos:
            safe_posix_runtime_path(info.filename, value, f"ZIP {info.filename}")
            require(not info.is_dir(), f"ZIP: entrada directorio inesperada: {info.filename}")
            require(info.create_system == 3, f"ZIP: plataforma no Unix: {info.filename}")
            unix_mode = info.external_attr >> 16
            require(stat.S_ISREG(unix_mode), f"ZIP: entrada no regular: {info.filename}")
            require(stat.S_IMODE(unix_mode) == int(policy["file_mode"], 8), f"ZIP: modo inesperado: {info.filename}")
            require(info.compress_type == zipfile.ZIP_STORED, f"ZIP: compresión inesperada: {info.filename}")
            require(info.date_time == (1980, 1, 1, 0, 0, 0), f"ZIP: mtime no determinista: {info.filename}")
            require(info.flag_bits & 0x1 == 0, f"ZIP: entrada cifrada: {info.filename}")
            payload = bundle.read(info)
            require(sha256_bytes(payload) == expected_hashes[info.filename], f"ZIP: hash de contenido inválido: {info.filename}")


def walk_regular_files(root: Path) -> list[Path]:
    found: list[Path] = []
    for child in sorted(root.iterdir(), key=lambda item: item.name.encode("utf-8")):
        mode = child.lstat().st_mode
        require(not child.is_symlink(), f"fuente: symlink prohibido: {child.relative_to(repo_root)}")
        if stat.S_ISDIR(mode):
            found.extend(walk_regular_files(child))
        elif stat.S_ISREG(mode):
            found.append(child)
        else:
            fail(f"fuente: entrada especial prohibida: {child.relative_to(repo_root)}")
    return found


def validate_source(entries: list[tuple[str, str]], value: dict[str, Any]) -> list[tuple[str, bytes]]:
    component = value["component"]
    source_policy = component["source_exact"]
    source_root = safe_repo_path(component["source_root"], "source_root")
    require(source_root.is_dir() and not source_root.is_symlink(), "source_root no es directorio regular")
    expected_top = sorted(source_policy["included_top_level"] + source_policy["excluded_top_level"], key=lambda item: item.encode("utf-8"))
    actual_top = sorted((item.name for item in source_root.iterdir()), key=lambda item: item.encode("utf-8"))
    require(actual_top == expected_top, f"fuente: rutas top-level no revisadas: esperado={expected_top}, obtenido={actual_top}")

    all_source_files = walk_regular_files(source_root)
    forbidden = value["forbidden_runtime"]
    forbidden_dirs = {item.casefold() for item in forbidden["path_segments"] if item != "tests"}
    forbidden_names = {item.casefold() for item in forbidden["file_names"]}
    for source_file in all_source_files:
        relative = source_file.relative_to(source_root)
        folded_parts = {part.casefold() for part in relative.parts[:-1]}
        require(not forbidden_dirs.intersection(folded_parts), f"fuente: directorio de desarrollo prohibido: {relative}")
        basename = relative.name
        require(basename.casefold() not in forbidden_names, f"fuente: artefacto prohibido: {relative}")
        require(not any(basename.startswith(prefix) for prefix in forbidden["file_prefixes"]), f"fuente: prefijo prohibido: {relative}")
        require(not any(basename.casefold().endswith(suffix.casefold()) for suffix in forbidden["file_suffixes"]), f"fuente: sufijo prohibido: {relative}")

    runtime_files: list[Path] = []
    for top_name in source_policy["included_top_level"]:
        top = source_root / top_name
        require(top.exists(), f"fuente: top-level incluido ausente: {top_name}")
        mode = top.lstat().st_mode
        require(not top.is_symlink(), f"fuente: top-level symlink: {top_name}")
        if stat.S_ISDIR(mode):
            runtime_files.extend(walk_regular_files(top))
        elif stat.S_ISREG(mode):
            runtime_files.append(top)
        else:
            fail(f"fuente: top-level especial: {top_name}")

    runtime_paths = [
        f"{component['slug']}/{path.relative_to(source_root).as_posix()}"
        for path in runtime_files
    ]
    runtime_paths.sort(key=lambda item: item.encode("utf-8"))
    expected_paths = [relative for _, relative in entries]
    require(runtime_paths == expected_paths, "fuente exacta: el set runtime no coincide con inventario")
    expected_hashes = {relative: digest for digest, relative in entries}
    documents: list[tuple[str, bytes]] = []
    for relative in runtime_paths:
        source_file = source_root / PurePosixPath(relative).relative_to(component["slug"])
        actual_hash = sha256_file(source_file)
        require(actual_hash == expected_hashes[relative], f"fuente exacta: hash distinto: {relative}")
        documents.append((relative, source_file.read_bytes()))
    for relative in component["required_runtime_paths"]:
        require(f"{component['slug']}/{relative}" in expected_hashes, f"ruta runtime requerida ausente: {relative}")
    return documents


def parse_theme_headers(text: str, required_fields: dict[str, str]) -> None:
    for key, expected in required_fields.items():
        match = re.search(rf"^[ \t]*(?:\*[ \t]*)?{re.escape(key)}:[ \t]*(.*?)[ \t]*$", text, re.MULTILINE)
        require(match is not None, f"style.css: falta header {key}")
        require(match.group(1) == expected, f"style.css: {key}={match.group(1)!r}; esperado={expected!r}")


def validate_documents(policy: dict[str, Any], documents: list[tuple[str, bytes]]) -> None:
    extensions = {item.casefold() for item in policy["text_extensions"]}
    compiled: list[tuple[str, re.Pattern[str]]] = []
    for pattern in policy["forbidden_content_patterns"]:
        require(pattern["flags"] == "iu", f"patrón {pattern['id']}: flags no permitidos")
        try:
            compiled.append((pattern["id"], re.compile(pattern["regex"], re.IGNORECASE | re.UNICODE)))
        except re.error as exc:
            fail(f"patrón {pattern['id']}: regex inválida: {exc}")
    for relative, payload in documents:
        suffix = PurePosixPath(relative).suffix.casefold()
        if suffix not in extensions:
            continue
        try:
            text = payload.decode("utf-8")
        except UnicodeDecodeError as exc:
            fail(f"contenido textual no UTF-8: {relative}: {exc}")
        for pattern_id, regex in compiled:
            require(regex.search(text) is None, f"contenido residencial/físico prohibido ({pattern_id}): {relative}")


def validate_headers_and_truth(value: dict[str, Any], documents: list[tuple[str, bytes]], style_override: str | None = None) -> None:
    headers = value["headers"]
    style_path = safe_repo_path(headers["style_css"]["path"], "style.css")
    style_text = style_override if style_override is not None else style_path.read_text(encoding="utf-8")
    parse_theme_headers(style_text, headers["style_css"]["required_fields"])
    theme_json_path = safe_repo_path(headers["theme_json"]["path"], "theme.json")
    try:
        theme_json = json.loads(theme_json_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        fail(f"theme.json inválido: {exc}")
    theme_contract = headers["theme_json"]
    require(theme_json.get("$schema") == theme_contract["schema"], "theme.json: $schema divergente")
    require(theme_json.get("version") == theme_contract["schema_version"], "theme.json: schema version divergente")
    require(theme_json.get("title") == theme_contract["title"], "theme.json: title divergente")
    validate_documents(value["residential_truth_policy"], documents)
    validate_documents(
        value["residential_truth_policy"],
        [("akibara-gp/truth-fixture.php", "No contamos con local físico abierto al público.".encode("utf-8"))],
    )


def suite_paths(value: dict[str, Any], suite_name: str) -> list[str]:
    return [item["path"] for item in value["quality_gates"][suite_name]["test_files"]]


def validate_suite_inventory(value: dict[str, Any]) -> None:
    tests_root = safe_repo_path("public_html/wp-content/themes/akibara-gp/tests", "tests_root")
    expected_node = sorted(suite_paths(value, "node_theme"), key=lambda item: item.encode("utf-8"))
    actual_node = sorted(
        (path.relative_to(repo_root).as_posix() for path in tests_root.glob("*.test.js")),
        key=lambda item: item.encode("utf-8"),
    )
    require(actual_node == expected_node, "suite Node: inventario de pruebas divergente")
    expected_php = sorted(suite_paths(value, "phpunit_theme"), key=lambda item: item.encode("utf-8"))
    actual_php = sorted(
        (path.relative_to(repo_root).as_posix() for path in tests_root.glob("*.php")),
        key=lambda item: item.encode("utf-8"),
    )
    require(actual_php == expected_php, "suite PHPUnit: inventario de fuentes divergente")


def expect_rejected(name: str, mutation: Callable[[], None]) -> None:
    try:
        mutation()
    except ContractError:
        print(f"PASS mutación rechazada: {name}")
        return
    fail(f"mutación aceptada: {name}")


try:
    validate_shape(contract)
    validate_semantics(contract)
    print("PASS contrato estricto y binding integrado")

    assert_all_pinned_files(contract)
    artifact_entries: dict[str, list[tuple[str, str]]] = {}
    for role in ("preimage", "candidate"):
        validate_sidecar(contract, role)
        inventory_raw = safe_repo_path(contract["component"][role]["inventory"], f"{role}.inventory").read_bytes()
        artifact_entries[role] = parse_inventory(inventory_raw, contract)
        validate_inventory_count(artifact_entries[role], contract, role)
        validate_zip(artifact_entries[role], contract, role)
    inventory_entries = artifact_entries["candidate"]
    print("PASS preimagen 2.1.69 y candidato 2.1.70 exactos (103 archivos cada uno)")

    source_documents = validate_source(inventory_entries, contract)
    print("PASS fuente runtime exacta byte a byte")

    validate_headers_and_truth(contract, source_documents)
    print("PASS headers, versión y política sin datos residenciales")

    validate_suite_inventory(contract)
    print("PASS packager, suite Node y PHPUnit fijados por hash")

    mutations: list[tuple[str, Callable[[], None]]] = []

    def unknown_contract_key() -> None:
        mutant = copy.deepcopy(contract)
        mutant["credential"] = "forbidden"
        validate_shape(mutant)

    mutations.append(("clave contractual desconocida", unknown_contract_key))

    def archive_hash_change() -> None:
        mutant = copy.deepcopy(contract)
        mutant["component"]["candidate"]["archive_sha256"] = "0" * 64
        assert_all_pinned_files(mutant)

    mutations.append(("SHA del ZIP", archive_hash_change))

    def preimage_archive_hash_change() -> None:
        mutant = copy.deepcopy(contract)
        mutant["component"]["preimage"]["archive_sha256"] = "0" * 64
        assert_all_pinned_files(mutant)

    mutations.append(("SHA del ZIP de rollback", preimage_archive_hash_change))

    def runtime_count_change() -> None:
        mutant = copy.deepcopy(contract)
        mutant["component"]["candidate"]["runtime_files"] = 104
        validate_semantics(mutant)

    mutations.append(("cantidad runtime", runtime_count_change))

    mutations.append((
        "traversal de inventario",
        lambda: safe_posix_runtime_path("akibara-gp/../escape.php", contract, "fixture traversal"),
    ))
    mutations.append((
        "ruta tests en runtime",
        lambda: safe_posix_runtime_path("akibara-gp/tests/leak.php", contract, "fixture tests"),
    ))

    def header_version_change() -> None:
        style = safe_repo_path(contract["headers"]["style_css"]["path"], "style fixture").read_text(encoding="utf-8")
        mutated = re.sub(r"(?m)^Version:\s*2\.1\.70\s*$", "Version: 9.9.9", style, count=1)
        require(mutated != style, "fixture: no se pudo mutar Version")
        validate_headers_and_truth(contract, source_documents, style_override=mutated)

    mutations.append(("header Version", header_version_change))

    mutations.append((
        "schema físico",
        lambda: validate_documents(
            contract["residential_truth_policy"],
            [("akibara-gp/fixture.php", b"<?php $schema = ['@type' => 'LocalBusiness'];")],
        ),
    ))

    mutations.append((
        "afirmación positiva de retiro presencial",
        lambda: validate_documents(
            contract["residential_truth_policy"],
            [("akibara-gp/fixture.php", "Ofrecemos retiro presencial todos los días.".encode("utf-8"))],
        ),
    ))

    def node_test_hash_change() -> None:
        mutant = copy.deepcopy(contract)
        mutant["quality_gates"]["node_theme"]["test_files"][0]["sha256"] = "f" * 64
        assert_all_pinned_files(mutant)

    mutations.append(("SHA de suite Node", node_test_hash_change))

    required_mutations = contract["quality_gates"]["static_contract"]["negative_mutations_required"]
    require(len(mutations) == required_mutations, f"se declararon {required_mutations} mutaciones y existen {len(mutations)}")
    for mutation_name, mutation in mutations:
        expect_rejected(mutation_name, mutation)

    print(f"PASS contrato Theme 2.1.70: 6 gates positivos y {len(mutations)} mutaciones negativas")
except ContractError as exc:
    print(f"FAIL contrato Theme 2.1.70: {exc}", file=sys.stderr)
    raise SystemExit(1)
PY
