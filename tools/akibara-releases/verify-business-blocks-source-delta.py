#!/usr/bin/env python3
"""Fail closed when Business/Blocks source diverges outside its release allowlist."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat


ALLOWED_PRESERVED_DRIFT = {
    "akibara-business/src/Modules/FrontendCompatibility/FrontendCompatibility.php"
}
INVENTORY_ROW = re.compile(r"^([a-f0-9]{64})  ([A-Za-z0-9._/-]+)$")


def fail(component: str, message: str) -> None:
    raise SystemExit(f"{component}: {message}")


def load_inventory(component: str, path: Path) -> dict[str, str]:
    rows: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        match = INVENTORY_ROW.fullmatch(line)
        if match is None:
            fail(component, f"fila inválida en inventario preimagen: {line!r}")
        digest, name = match.groups()
        if name in rows:
            fail(component, f"ruta duplicada en inventario preimagen: {name}")
        rows[name] = digest
    if not rows:
        fail(component, "inventario preimagen vacío")
    return rows


def load_source(component: str, slug: str, source: Path) -> dict[str, str]:
    if not source.is_dir() or source.is_symlink():
        fail(component, f"raíz fuente ausente o insegura: {source}")

    rows: dict[str, str] = {}
    for current_dir, directories, files in os.walk(source, followlinks=False):
        directory = Path(current_dir)
        relative_dir = directory.relative_to(source)
        if relative_dir.parts and relative_dir.parts[0] == "tests":
            directories[:] = []
            continue

        kept_directories: list[str] = []
        for name in directories:
            path = directory / name
            mode = path.lstat().st_mode
            if stat.S_ISLNK(mode):
                fail(
                    component,
                    f"enlace simbólico fuera de tests: {path.relative_to(source)}",
                )
            if not stat.S_ISDIR(mode):
                fail(
                    component,
                    f"entrada especial fuera de tests: {path.relative_to(source)}",
                )
            if not relative_dir.parts and name == "tests":
                continue
            kept_directories.append(name)
        directories[:] = kept_directories

        for name in files:
            path = directory / name
            relative = path.relative_to(source)
            if not relative_dir.parts and name in {
                ".distignore",
                ".graphifyignore",
            }:
                continue
            mode = path.lstat().st_mode
            if not stat.S_ISREG(mode):
                fail(component, f"entrada runtime no regular: {relative}")
            logical = f"{slug}/{relative.as_posix()}"
            if logical in rows:
                fail(component, f"ruta runtime duplicada: {logical}")
            rows[logical] = hashlib.sha256(path.read_bytes()).hexdigest()
    return rows


def verify(
    contract_path: Path,
    component: str,
    slug: str,
    source: Path,
    inventory_path: Path,
) -> None:
    contract = json.loads(contract_path.read_text(encoding="utf-8"))
    try:
        spec = contract["components"][component]
    except (KeyError, TypeError) as error:
        fail(component, f"contrato sin especificación válida: {error}")

    if spec.get("slug") != slug:
        fail(component, "slug no coincide con el contrato")

    base = load_inventory(component, inventory_path)
    current = load_source(component, slug, source)
    base_paths = set(base)
    current_paths = set(current)
    actual_added = current_paths - base_paths
    actual_removed = base_paths - current_paths
    actual_modified = {
        path for path in base_paths & current_paths if base[path] != current[path]
    }
    expected_added = set(spec["added_runtime_files"])
    expected_removed = set(spec["removed_runtime_files"])
    expected_modified = set(spec["modified_runtime_files"])
    preserved = {row["path"] for row in spec["preserved_preimage_files"]}

    if actual_added != expected_added:
        fail(
            component,
            "delta fuente added fuera de allowlist; "
            f"faltan={sorted(expected_added - actual_added)} "
            f"sobran={sorted(actual_added - expected_added)}",
        )
    if actual_removed != expected_removed:
        fail(
            component,
            "delta fuente removed fuera de allowlist; "
            f"faltan={sorted(expected_removed - actual_removed)} "
            f"sobran={sorted(actual_removed - expected_removed)}",
        )

    missing_modified = expected_modified - actual_modified
    unexpected_modified = actual_modified - expected_modified - preserved
    if missing_modified or unexpected_modified:
        fail(
            component,
            "delta fuente modified fuera de allowlist; "
            f"faltan={sorted(missing_modified)} "
            f"sobran={sorted(unexpected_modified)}",
        )

    preserved_drift = actual_modified & preserved
    unexpected_preserved = preserved_drift - ALLOWED_PRESERVED_DRIFT
    if unexpected_preserved:
        fail(
            component,
            f"divergencia preservada no autorizada: {sorted(unexpected_preserved)}",
        )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--contract", required=True, type=Path)
    parser.add_argument("--component", required=True, choices=("business", "blocks"))
    parser.add_argument("--slug", required=True)
    parser.add_argument("--source-root", required=True, type=Path)
    parser.add_argument("--preimage-inventory", required=True, type=Path)
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    verify(
        args.contract,
        args.component,
        args.slug,
        args.source_root,
        args.preimage_inventory,
    )


if __name__ == "__main__":
    main()
