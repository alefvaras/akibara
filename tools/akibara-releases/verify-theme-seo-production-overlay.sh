#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
contract="${repo_root}/ops/contracts/akibara-gp-2.1.50-seo-overlay.json"
source_dir=""

usage() {
	cat <<'EOF'
Uso:
  bash tools/akibara-releases/verify-theme-seo-production-overlay.sh \
    --source-dir RUTA

Verifica que un candidato Akibara GP 2.1.50 sea exactamente el superset
productivo 2.1.49 más las siete rutas SEO allowlisted: cuatro modificaciones,
dos adiciones y la eliminación del módulo legacy de URLs filtradas.
EOF
}

while (($#)); do
	case "$1" in
		--source-dir)
			(($# >= 2)) || {
				echo "Falta la ruta para --source-dir." >&2
				exit 2
			}
			source_dir="$2"
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			echo "Opción desconocida: $1" >&2
			usage >&2
			exit 2
			;;
	esac
done

[[ -n "${source_dir}" ]] || {
	usage >&2
	exit 2
}
[[ -d "${source_dir}" && ! -L "${source_dir}" ]] || {
	echo "El candidato debe ser un directorio regular." >&2
	exit 1
}
[[ -f "${contract}" && ! -L "${contract}" ]] || {
	echo "Falta el contrato SEO 2.1.50." >&2
	exit 1
}
command -v python3 >/dev/null 2>&1 || {
	echo "Falta python3." >&2
	exit 1
}

python3 - "${repo_root}" "${contract}" "${source_dir}" <<'PY'
from __future__ import annotations

import hashlib
import json
import os
import pathlib
import re
import stat
import sys
import zipfile

repo = pathlib.Path(sys.argv[1]).resolve()
contract_path = pathlib.Path(sys.argv[2]).resolve()
candidate_root = pathlib.Path(sys.argv[3]).resolve()
contract = json.loads(contract_path.read_text(encoding="utf-8"))


def fail(message: str) -> None:
    raise SystemExit(f"SEO overlay verification failed: {message}")


def check(condition: bool, message: str) -> None:
    if not condition:
        fail(message)


def sha256_bytes(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def sha256_file(path: pathlib.Path) -> str:
    return sha256_bytes(path.read_bytes())


def safe_repo_path(relative: str) -> pathlib.Path:
    check(isinstance(relative, str) and relative, "invalid contract path")
    pure = pathlib.PurePosixPath(relative)
    check(
        not pure.is_absolute()
        and "\\" not in relative
        and all(part not in {"", ".", ".."} for part in pure.parts),
        f"unsafe contract path: {relative}",
    )
    resolved = (repo / pathlib.Path(*pure.parts)).resolve()
    check(resolved == repo or repo in resolved.parents, f"path escaped repository: {relative}")
    return resolved


def tree_sha(files: dict[str, bytes]) -> str:
    manifest = "".join(
        f"{sha256_bytes(files[path])}\t{path}\n" for path in sorted(files)
    )
    return sha256_bytes(manifest.encode("utf-8"))


def candidate_files() -> dict[str, bytes]:
    result: dict[str, bytes] = {}
    forbidden_suffixes = {
        ".bak", ".bk", ".orig", ".rej", ".tmp", ".temp", ".dump",
        ".backup", ".sql", ".sqlite", ".tar", ".tgz", ".zip",
    }
    forbidden_names = {
        ".DS_Store", ".phpunit.result.cache", "coverage.xml", "debug.log",
        "error_log", "composer.json", "composer.lock", "package.json",
        "package-lock.json", "yarn.lock", "pnpm-lock.yaml", "phpunit.xml",
        "phpunit.xml.dist",
    }
    forbidden_directories = {
        "tests", "node_modules", "migrations", "graphify-out", "coverage",
        "backups", ".git", ".github", ".idea", ".vscode",
    }

    for current, directories, files in os.walk(candidate_root, followlinks=False):
        current_path = pathlib.Path(current)
        for name in directories:
            path = current_path / name
            check(not path.is_symlink(), f"symlink directory: {path}")
            check(name not in forbidden_directories, f"forbidden directory: {path}")
        for name in files:
            path = current_path / name
            check(not path.is_symlink(), f"symlink file: {path}")
            check(path.is_file(), f"special filesystem entry: {path}")
            check(
                name not in forbidden_names
                and not name.startswith("._")
                and not name.endswith("~")
                and path.suffix not in forbidden_suffixes,
                f"forbidden artifact: {path}",
            )
            relative = path.relative_to(candidate_root).as_posix()
            check(relative not in result, f"duplicate candidate path: {relative}")
            result[relative] = path.read_bytes()
    return result


check(contract.get("schema_version") == 2, "unexpected schema version")
check(
    contract.get("contract") == "akibara-gp-production-seo-overlay",
    "unexpected contract identity",
)
release = contract.get("release", {})
source_theme = contract.get("source_theme", {})
preimage = contract.get("production_preimage", {})
candidate = contract.get("production_candidate", {})
modified_rows = contract.get("modified_files", [])
added_rows = contract.get("added_files", [])
removed_rows = contract.get("removed_files", [])
production_only_rows = contract.get("required_production_only_files", [])
quality_gate = contract.get("quality_gate", {})

check(release.get("release_set") == "akibara-gp-seo-production-2.1.50", "release set")
check(release.get("environment_flow") == ["docker-local", "production"], "environment flow")
check(release.get("filtered_urls_owner") == "akibara-business", "filtered URL owner")
check(source_theme.get("version") == "2.1.48", "source theme version")
check(preimage.get("version") == "2.1.49", "preimage version")
check(preimage.get("files") == 102, "preimage file count contract")
check(candidate.get("version") == "2.1.50", "candidate version")
check(candidate.get("files") == 103, "candidate file count contract")
check(candidate.get("preserved_files") == 97, "preserved count contract")
check(candidate.get("modified_files") == 4, "modified count contract")
check(candidate.get("added_files") == 2, "added count contract")
check(candidate.get("removed_files") == 1, "removed count contract")
check(len(modified_rows) == 4, "modified allowlist length")
check(len(added_rows) == 2, "addition allowlist length")
check(len(removed_rows) == 1, "removal allowlist length")
check(len(production_only_rows) == 6, "production-only allowlist length")

base_archive = safe_repo_path(preimage.get("archive", ""))
base_inventory = safe_repo_path(preimage.get("inventory", ""))
check(base_archive.is_file() and not base_archive.is_symlink(), "missing base archive")
check(base_inventory.is_file() and not base_inventory.is_symlink(), "missing base inventory")
check(sha256_file(base_archive) == preimage.get("archive_sha256"), "base archive hash")
check(sha256_file(base_inventory) == preimage.get("inventory_sha256"), "base inventory hash")

base_files: dict[str, bytes] = {}
with zipfile.ZipFile(base_archive) as bundle:
    for info in bundle.infolist():
        raw = info.filename
        check(
            raw.startswith("akibara-gp/")
            and not raw.startswith("/")
            and "\\" not in raw
            and all(part not in {"", ".", ".."} for part in raw.split("/")),
            f"unsafe base ZIP path: {raw}",
        )
        check(not info.is_dir(), f"unexpected directory entry in base ZIP: {raw}")
        mode = (info.external_attr >> 16) & 0xFFFF
        check(not stat.S_ISLNK(mode), f"symlink in base ZIP: {raw}")
        relative = raw.removeprefix("akibara-gp/")
        check(relative not in base_files, f"duplicate base ZIP path: {relative}")
        base_files[relative] = bundle.read(info)

expected_base_inventory = "".join(
    f"{sha256_bytes(base_files[path])}  akibara-gp/{path}\n"
    for path in sorted(base_files)
).encode("utf-8")
check(base_inventory.read_bytes() == expected_base_inventory, "base inventory content")
check(len(base_files) == preimage.get("files"), "base file count")
check(tree_sha(base_files) == preimage.get("normal_tree_sha256"), "base tree hash")

files = candidate_files()
check(len(files) == candidate.get("files"), "candidate file count")
check(tree_sha(files) == candidate.get("normal_tree_sha256"), "candidate tree hash")

base_paths = set(base_files)
candidate_paths = set(files)
removed = base_paths - candidate_paths
added = candidate_paths - base_paths
modified = {
    path
    for path in base_paths & candidate_paths
    if base_files[path] != files[path]
}
preserved = {
    path
    for path in base_paths & candidate_paths
    if base_files[path] == files[path]
}
expected_modified = {row["path"] for row in modified_rows}
expected_added = {row["path"] for row in added_rows}
expected_removed = {row["path"] for row in removed_rows}
check(len(expected_modified) == len(modified_rows), "duplicate modified path")
check(len(expected_added) == len(added_rows), "duplicate added path")
check(len(expected_removed) == len(removed_rows), "duplicate removed path")
check(
    expected_removed == {"inc/seo-filtered-urls.php"},
    "legacy filtered URL removal allowlist",
)
check(
    removed == expected_removed,
    f"unexpected removals: {sorted(removed ^ expected_removed)}",
)
check(added == expected_added, f"unexpected additions: {sorted(added ^ expected_added)}")
check(
    modified == expected_modified,
    f"unexpected modifications: {sorted(modified ^ expected_modified)}",
)
check(len(preserved) == candidate.get("preserved_files"), "preserved file count")

for row in modified_rows:
    path = row["path"]
    check(sha256_bytes(base_files[path]) == row["preimage_sha256"], f"preimage hash: {path}")
    check(sha256_bytes(files[path]) == row["candidate_sha256"], f"candidate hash: {path}")
for row in added_rows:
    path = row["path"]
    check(sha256_bytes(files[path]) == row["candidate_sha256"], f"addition hash: {path}")
for row in removed_rows:
    path = row["path"]
    check(path in base_files and path not in files, f"removal path: {path}")
    check(sha256_bytes(base_files[path]) == row["preimage_sha256"], f"removal hash: {path}")
    check(row.get("mode") == "ownership_transfer", f"removal mode: {path}")
    check(row.get("owner") == "akibara-business", f"removal owner: {path}")
for row in production_only_rows:
    path = row["path"]
    check(path in files, f"missing production-only file: {path}")
    check(sha256_bytes(files[path]) == row["sha256"], f"production-only hash: {path}")

style = files["style.css"].decode("utf-8")
check(re.search(r"^Version:\s+2\.1\.50$", style, re.MULTILINE) is not None, "theme version header")
check(re.search(r"^Template:\s+generatepress$", style, re.MULTILINE) is not None, "GeneratePress parent")

functions = files["functions.php"].decode("utf-8")
seo_require = "require_once $akb_inc . 'seo-filtered-urls.php';"
rank_require = "require_once $akb_inc . 'rank-math.php';"
blog_require = "require_once $akb_inc . 'blog.php';"
base_functions = base_files["functions.php"].decode("utf-8")
check(base_functions.count(seo_require) == 1, "preimage SEO-filtered include")
check(functions.count(seo_require) == 0, "legacy SEO-filtered include")
check(functions.count(rank_require) == 1, "Rank Math include")
check(functions.count(blog_require) == 1, "blog include")
check(functions.index(blog_require) < functions.index(rank_require), "include ordering")

single = files["single.php"].decode("utf-8")
check(
    re.search(
        r"'alt'\s*=>\s*akibara_blog_image_alt\(\s*\$akb_thumb_id,\s*get_the_title\(\)\s*\)",
        single,
    )
    is not None,
    "single featured-image alt integration",
)
rank_php = files["inc/rank-math.php"].decode("utf-8")
check("enqueue_block_editor_assets" in rank_php, "editor-only enqueue hook")
check(
    re.search(r"add_filter\(\s*['\"]rank_math_content['\"]", rank_php) is None,
    "PHP must not emit a second content filter",
)
rank_js = files["assets/js/rank-math-content-analysis.js"].decode("utf-8")
check("'rank_math_content'" in rank_js, "official Rank Math analysis filter")
check("data-akibara-rank-math-featured" in rank_js, "featured image analysis marker")
check(
    re.search(r"\b(?:editPost|savePost|dispatch)\b", rank_js) is None,
    "editor mutation primitive",
)

rank_math_tests = quality_gate.get("rank_math_tests", [])
check(len(rank_math_tests) == 2, "Rank Math test allowlist")
for row in rank_math_tests:
    test_path = safe_repo_path(row.get("path", ""))
    check(test_path.is_file() and not test_path.is_symlink(), "missing Rank Math test")
    check(sha256_file(test_path) == row.get("sha256"), f"Rank Math test hash: {test_path}")
checkout_dependency = safe_repo_path(quality_gate.get("checkout_dependency", ""))
check(
    checkout_dependency.is_file() and not checkout_dependency.is_symlink(),
    "missing Checkout quality-gate dependency",
)
check(
    sha256_file(checkout_dependency)
    == quality_gate.get("checkout_dependency_sha256"),
    "Checkout quality-gate dependency hash",
)
check(quality_gate.get("tests_packaged") is False, "tests packaging contract")

print(
    "PASS: Akibara GP 2.1.50 preserves 97/102 production files, "
    "modifies 4, adds 2, removes 1 legacy file, and keeps all 6 production-only files"
)
PY
