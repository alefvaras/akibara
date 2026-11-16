#!/usr/bin/env python3
"""Verify that a Business source tree differs from its sealed preimage exactly."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat


INVENTORY_ROW = re.compile(r"^([a-f0-9]{64})  ([A-Za-z0-9._/-]+)$")
SAFE_RUNTIME_PATH = re.compile(r"^[A-Za-z0-9._/-]+$")


def fail(message: str) -> None:
    raise SystemExit(f"business: {message}")


def load_inventory(path: Path) -> dict[str, str]:
    rows: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        match = INVENTORY_ROW.fullmatch(line)
        if match is None:
            fail(f"fila inválida en inventario preimagen: {line!r}")
        digest, name = match.groups()
        if name in rows:
            fail(f"ruta duplicada en inventario preimagen: {name}")
        rows[name] = digest
    if not rows:
        fail("inventario preimagen vacío")
    return rows


def load_source(slug: str, source: Path) -> dict[str, str]:
    if not source.is_dir() or source.is_symlink():
        fail(f"raíz fuente ausente o insegura: {source}")

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
                fail(f"enlace simbólico runtime: {path.relative_to(source)}")
            if not stat.S_ISDIR(mode):
                fail(f"entrada especial runtime: {path.relative_to(source)}")
            if not relative_dir.parts and name == "tests":
                continue
            kept_directories.append(name)
        directories[:] = kept_directories

        for name in files:
            path = directory / name
            relative = path.relative_to(source)
            if not relative_dir.parts and name in {".distignore", ".graphifyignore"}:
                continue
            mode = path.lstat().st_mode
            if not stat.S_ISREG(mode):
                fail(f"entrada runtime no regular: {relative}")
            logical = f"{slug}/{relative.as_posix()}"
            if logical in rows:
                fail(f"ruta runtime duplicada: {logical}")
            rows[logical] = hashlib.sha256(path.read_bytes()).hexdigest()
    if not rows:
        fail("árbol fuente runtime vacío")
    return rows


def load_expected(contract_path: Path, slug: str) -> tuple[dict[str, set[str]], dict[str, str]]:
    contract = json.loads(contract_path.read_text(encoding="utf-8"))
    try:
        spec = contract["components"]["business"]
    except (KeyError, TypeError) as error:
        fail(f"contrato sin Business válido: {error}")
    if spec.get("slug") != slug:
        fail("slug no coincide con el contrato")

    expected = {
        "added": set(spec.get("added_runtime_files", [])),
        "removed": set(spec.get("removed_runtime_files", [])),
        "modified": set(spec.get("modified_runtime_files", [])),
    }
    all_expected: set[str] = set()
    for category, paths in expected.items():
        for path in paths:
            if (
                not isinstance(path, str)
                or SAFE_RUNTIME_PATH.fullmatch(path) is None
                or not path.startswith(f"{slug}/")
                or ".." in path
                or "//" in path
                or "/./" in path
            ):
                fail(f"ruta insegura en {category}: {path!r}")
            if path in all_expected:
                fail(f"ruta repetida entre categorías: {path}")
            all_expected.add(path)

    source_inputs: dict[str, str] = {}
    for row in spec.get("source_inputs", []):
        path = row.get("path")
        digest = row.get("sha256")
        if (
            not isinstance(path, str)
            or not isinstance(digest, str)
            or re.fullmatch(r"[a-f0-9]{64}", digest) is None
            or path in source_inputs
        ):
            fail("source_inputs inválidos o duplicados")
        source_inputs[path] = digest
    if set(source_inputs) != expected["added"] | expected["modified"]:
        fail("source_inputs no cubre exactamente added + modified")
    return expected, source_inputs


def verify(
    contract_path: Path,
    slug: str,
    source: Path,
    inventory_path: Path,
    expected_counts: dict[str, int],
) -> None:
    expected, source_inputs = load_expected(contract_path, slug)
    base = load_inventory(inventory_path)
    current = load_source(slug, source)
    base_paths = set(base)
    current_paths = set(current)
    actual = {
        "added": current_paths - base_paths,
        "removed": base_paths - current_paths,
        "modified": {
            path for path in base_paths & current_paths if base[path] != current[path]
        },
    }

    for category, expected_count in expected_counts.items():
        actual_count = len(actual[category])
        if actual_count != expected_count:
            fail(
                f"cardinalidad {category} inesperada; "
                f"esperada={expected_count} actual={actual_count}"
            )

    for category in ("added", "removed", "modified"):
        if actual[category] != expected[category]:
            fail(
                f"delta {category} fuera del contrato; "
                f"faltan={sorted(expected[category] - actual[category])} "
                f"sobran={sorted(actual[category] - expected[category])}"
            )
    for path, expected_digest in source_inputs.items():
        if current.get(path) != expected_digest:
            fail(f"input fuente cambió tras el sello: {path}")

    print(
        "PASS: delta Business exacto "
        f"modified={len(actual['modified'])} "
        f"added={len(actual['added'])} removed={len(actual['removed'])}"
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--contract", required=True, type=Path)
    parser.add_argument("--slug", default="akibara-business")
    parser.add_argument("--source-root", required=True, type=Path)
    parser.add_argument("--preimage-inventory", required=True, type=Path)
    parser.add_argument("--expected-modified", type=int)
    parser.add_argument("--expected-added", type=int)
    parser.add_argument("--expected-removed", type=int)
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    expected_counts = {
        category: value
        for category, value in {
            "modified": args.expected_modified,
            "added": args.expected_added,
            "removed": args.expected_removed,
        }.items()
        if value is not None
    }
    if any(value < 0 for value in expected_counts.values()):
        fail("las cardinalidades esperadas no pueden ser negativas")
    verify(
        args.contract,
        args.slug,
        args.source_root,
        args.preimage_inventory,
        expected_counts,
    )


if __name__ == "__main__":
    main()
