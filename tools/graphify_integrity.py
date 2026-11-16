#!/usr/bin/env python3
"""Integrity engine for the local Akibara graph without LLM extraction.

The stock ``graphify update <path>`` command writes ``graphify-out`` below the
scan path. Akibara scans from the repository root instead, where
``.graphifyignore`` excludes WordPress core, third-party plugins, uploads and
other mutable content. Outputs therefore remain local and outside
``public_html``.

Graphify deliberately skips data-shaped JSON in its structural pass. This
updater keeps valid JSON in the corpus as metadata-only file nodes so relevant
declarative inputs remain discoverable without expanding every JSON key or
using an LLM. Exact, reviewed public-source exceptions use the same
metadata-only representation and are recorded in a corpus audit sidecar.
"""

from __future__ import annotations

import argparse
import inspect
import json
import os
import re
from collections import Counter, defaultdict
from copy import deepcopy
from pathlib import Path
from typing import Sequence

# El grafo de Akibara superó hace rato el límite por defecto de la visualización
# HTML de Graphify (5.000 nodos): el 2026-08-10 son 10.286 nodos y 20.760
# aristas. `tools/update-graphify.sh` ya lleva esta defensa desde el 2026-07-31,
# pero este script quedó fuera y por eso reventaba con
# `ValueError: Graph has 10286 nodes - too large for HTML viz` DESPUÉS de haber
# hecho todo el análisis — o sea, tiraba a la basura una corrida de minutos y
# parecía un fallo de integridad cuando era sólo el exportador.
#
# `setdefault` y no una asignación: si el operador exporta su propio valor
# (para bajarlo a propósito, o para pasar de 20.000 el día que el grafo crezca),
# manda el suyo.
os.environ.setdefault("GRAPHIFY_VIZ_NODE_LIMIT", "100000")

import networkx as nx  # noqa: E402  — después del setdefault: graphify lee el límite al importar.
from graphify.analyze import god_nodes, suggest_questions, surprising_connections
from graphify.build import build_from_json
from graphify.cluster import score_all
from graphify.detect import detect, save_manifest
from graphify.diagnostics import diagnose_extraction
from graphify.export import to_html, to_json
from graphify.extract import extract
from graphify.ids import make_id, normalize_id
from graphify.report import generate
from networkx.readwrite import json_graph


PROJECT_ROOT = Path(__file__).resolve().parents[1]
OUTPUT_DIR = PROJECT_ROOT / "graphify-out"
GRAPH_PATH = OUTPUT_DIR / "graph.json"
CORPUS_AUDIT_PATH = OUTPUT_DIR / ".graphify_corpus_audit.json"
MAX_DECLARATIVE_JSON_BYTES = 2 * 1024 * 1024
DIRECTED_GRAPH = True

_SOURCE_LINE_RE = re.compile(r"^L(?P<line>\d+)")
_PHP_NAMESPACE_RE = re.compile(
    r"^\s*namespace\s+(?P<namespace>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\]*)\s*;",
    re.MULTILINE,
)
_PHP_USE_RE = re.compile(
    r"^\s*use\s+"
    r"(?P<fqn>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\]*)"
    r"(?:\s+as\s+(?P<alias>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*))?"
    r"\s*;\s*$",
)
_PYTHON_FROM_IMPORT_RE = re.compile(
    r"^\s*from\s+(?P<module>[.\w]+)\s+import\s+",
)
_PYTHON_IMPORT_RE = re.compile(
    r"^\s*import\s+(?P<module>[.\w]+)"
    r"(?:\s+as\s+\w+)?(?:\s*,|\s*$)",
)

# Graphify's generic ``token`` filename heuristic flags this project-owned
# stylesheet even though CSS is not a supported Graphify file type. Keep the
# exception exact: its contents are never copied into graph.json, only the path
# and file-level metadata are represented.
METADATA_ONLY_SOURCE_EXCEPTIONS = {
    "public_html/wp-content/themes/akibara-gp/assets/css/tokens.css": (
        "exact_public_theme_stylesheet_name_match"
    ),
}


def _relative_source_file(value: object, root: Path) -> str | None:
    """Normalize a node/detector source path to a project-relative POSIX path."""
    if not value:
        return None

    path = Path(str(value))
    try:
        if path.is_absolute():
            path = path.resolve(strict=False).relative_to(root.resolve())
        else:
            path = (root / path).resolve(strict=False).relative_to(root.resolve())
    except (OSError, ValueError):
        return None

    return path.as_posix()


def _represented_source_files(extraction: dict, root: Path) -> set[str]:
    """Return corpus files that produced at least one extraction node."""
    represented: set[str] = set()
    for node in extraction.get("nodes", []):
        if not isinstance(node, dict):
            continue
        relative = _relative_source_file(node.get("source_file"), root)
        if relative:
            represented.add(relative)
    return represented


def _source_line(
    source_file: object,
    source_location: object,
    root: Path,
    text_cache: dict[str, str],
) -> str | None:
    """Return one project source line for a canonical ``L123`` location."""
    relative = _relative_source_file(source_file, root)
    match = _SOURCE_LINE_RE.match(str(source_location or ""))
    if relative is None or match is None:
        return None

    if relative not in text_cache:
        path = root / relative
        try:
            text_cache[relative] = path.read_text(encoding="utf-8")
        except (OSError, UnicodeError):
            return None

    lines = text_cache[relative].splitlines()
    line_number = int(match.group("line"))
    if line_number < 1 or line_number > len(lines):
        return None
    return lines[line_number - 1]


def _php_declaration_index(
    extraction: dict,
    root: Path,
    text_cache: dict[str, str],
) -> dict[str, list[str]]:
    """Index real PHP declarations by fully qualified class-like name."""
    index: dict[str, list[str]] = defaultdict(list)
    for node in extraction.get("nodes", []):
        if not isinstance(node, dict):
            continue
        source_file = _relative_source_file(node.get("source_file"), root)
        label = str(node.get("label") or "")
        if (
            not source_file
            or Path(source_file).suffix.casefold() != ".php"
            or not label
            or label.endswith("()")
            or not node.get("source_location")
        ):
            continue

        declaration_line = _source_line(
            source_file,
            node.get("source_location"),
            root,
            text_cache,
        )
        if declaration_line is None or re.search(
            rf"\b(?:class|interface|trait|enum)\s+{re.escape(label)}\b",
            declaration_line,
        ) is None:
            continue

        text = text_cache.get(source_file, "")
        namespace_match = _PHP_NAMESPACE_RE.search(text)
        namespace = (
            namespace_match.group("namespace") if namespace_match else ""
        )
        fully_qualified = f"{namespace}\\{label}" if namespace else label
        index[fully_qualified.casefold()].append(str(node["id"]))

    return index


def _php_import_fqn(
    edge: dict,
    root: Path,
    text_cache: dict[str, str],
) -> str | None:
    """Return the FQN for a simple PHP ``use`` edge when it matches its binding."""
    source_file = _relative_source_file(edge.get("source_file"), root)
    if (
        source_file is None
        or Path(source_file).suffix.casefold() != ".php"
        or edge.get("relation") != "imports"
        or edge.get("context") != "import"
        or edge.get("confidence") != "EXTRACTED"
    ):
        return None

    line = _source_line(
        source_file,
        edge.get("source_location"),
        root,
        text_cache,
    )
    if line is None:
        return None
    match = _PHP_USE_RE.match(line)
    if match is None:
        return None

    fully_qualified = match.group("fqn")
    binding = match.group("alias") or fully_qualified.rsplit("\\", 1)[-1]
    if normalize_id(binding) != normalize_id(str(edge.get("target") or "")):
        return None
    return fully_qualified


def _python_file_node_index(
    extraction: dict,
    root: Path,
) -> dict[str, str]:
    """Map represented Python files to their canonical file-level node."""
    index: dict[str, str] = {}
    ambiguous: set[str] = set()
    for node in extraction.get("nodes", []):
        if not isinstance(node, dict):
            continue
        source_file = _relative_source_file(node.get("source_file"), root)
        if (
            source_file is None
            or Path(source_file).suffix.casefold() != ".py"
            or node.get("label") != Path(source_file).name
        ):
            continue
        if source_file in index and index[source_file] != str(node["id"]):
            ambiguous.add(source_file)
            continue
        index[source_file] = str(node["id"])
    for source_file in ambiguous:
        index.pop(source_file, None)
    return index


def _python_import_target(
    edge: dict,
    root: Path,
    text_cache: dict[str, str],
    file_nodes: dict[str, str],
) -> tuple[str, str] | None:
    """Resolve a Python import only when its module maps to one local file."""
    source_file = _relative_source_file(edge.get("source_file"), root)
    if (
        source_file is None
        or Path(source_file).suffix.casefold() != ".py"
        or edge.get("relation") not in {"imports", "imports_from"}
        or edge.get("context") != "import"
        or edge.get("confidence") != "EXTRACTED"
    ):
        return None

    line = _source_line(
        source_file,
        edge.get("source_location"),
        root,
        text_cache,
    )
    if line is None:
        return None
    match = _PYTHON_FROM_IMPORT_RE.match(line) or _PYTHON_IMPORT_RE.match(line)
    if match is None:
        return None
    module = match.group("module")
    normalized_target = normalize_id(str(edge.get("target") or ""))
    module_names = {
        normalize_id(module.lstrip(".")),
        normalize_id(module.lstrip(".").rsplit(".", 1)[-1]),
    }
    if normalized_target not in module_names:
        return None

    leading_dots = len(module) - len(module.lstrip("."))
    module_parts = [
        part for part in module.lstrip(".").split(".") if part
    ]
    if not module_parts:
        return None

    source_path = root / source_file
    bases: list[Path]
    if leading_dots:
        base = source_path.parent
        for _ in range(max(leading_dots - 1, 0)):
            base = base.parent
        bases = [base]
    else:
        bases = [source_path.parent, root]

    candidates: set[str] = set()
    for base in bases:
        module_base = base.joinpath(*module_parts)
        for candidate in (
            module_base.with_suffix(".py"),
            module_base / "__init__.py",
        ):
            try:
                relative = candidate.resolve(strict=True).relative_to(
                    root.resolve()
                ).as_posix()
            except (OSError, ValueError):
                continue
            if relative in file_nodes:
                candidates.add(file_nodes[relative])

    if len(candidates) != 1:
        return None
    return next(iter(candidates)), module


def _known_absolute_file_id_artifact(
    edge: dict,
    node_ids: set[str],
    root: Path,
) -> bool:
    """Recognize Graphify's top-level inferred-call absolute file-id artifact.

    The producer emits these with context "argument" (call arguments) and,
    since the 2026-08-07 corpus, also "collection" (array literals — seen in
    tools/test-ai-discoverability-audit.mjs L715). Every other condition stays
    pinned so only the producer's own artifact is ever discarded.
    """
    source_file = _relative_source_file(edge.get("source_file"), root)
    if source_file is None:
        return False
    expected_source = make_id(str(root / source_file))
    return (
        edge.get("source") == expected_source
        and edge.get("target") in node_ids
        and edge.get("relation") == "indirect_call"
        and edge.get("confidence") == "INFERRED"
        and edge.get("context") in {"argument", "collection"}
    )


def _sanitize_and_classify_edges(
    extraction: dict,
    root: Path,
) -> tuple[dict, dict[str, object]]:
    """Repair safe internal imports and classify every raw dangling edge.

    Missing endpoint fields, self-loops, malformed nodes/edges, and unknown
    missing source nodes are unequivocal corruption and fail closed. Missing
    import targets are retained for Graphify to drop as external/excluded
    references. A precise producer artifact is removed and recorded.
    """
    node_ids: set[str] = set()
    for node in extraction.get("nodes", []):
        if not isinstance(node, dict) or not node.get("id"):
            raise RuntimeError(
                "Extracción Graphify inválida: nodo sin objeto/id canónico."
            )
        try:
            hash(node["id"])
        except TypeError as exc:
            raise RuntimeError(
                "Extracción Graphify inválida: id de nodo no hashable."
            ) from exc
        node_ids.add(str(node["id"]))

    raw_edges = extraction.get("edges", [])
    if not isinstance(raw_edges, list):
        raise RuntimeError("Extracción Graphify inválida: edges no es una lista.")

    text_cache: dict[str, str] = {}
    php_declarations = _php_declaration_index(extraction, root, text_cache)
    python_file_nodes = _python_file_node_index(extraction, root)
    raw_dangling_by_relation: Counter[str] = Counter()
    expected_external_by_relation: Counter[str] = Counter()
    repaired_examples: list[dict[str, object]] = []
    discarded_examples: list[dict[str, object]] = []
    external_examples: list[dict[str, object]] = []
    unknown_missing_sources: list[dict[str, object]] = []
    unclassified_dangling: list[dict[str, object]] = []
    sanitized_edges: list[dict] = []
    seen_sanitized_edges: set[str] = set()
    raw_dangling_total = 0
    repaired_php_imports = 0
    repaired_python_imports = 0
    discarded_invalid_producer_edges = 0
    deduplicated_exact_edges = 0

    for raw_edge in raw_edges:
        if not isinstance(raw_edge, dict):
            raise RuntimeError(
                "Extracción Graphify inválida: arista que no es un objeto."
            )
        edge = dict(raw_edge)
        if "source" not in edge and "from" in edge:
            edge["source"] = edge["from"]
        if "target" not in edge and "to" in edge:
            edge["target"] = edge["to"]
        source = edge.get("source")
        target = edge.get("target")
        if source in (None, "") or target in (None, ""):
            raise RuntimeError(
                "Extracción Graphify corrupta: arista sin source/target."
            )
        try:
            hash(source)
            hash(target)
        except TypeError as exc:
            raise RuntimeError(
                "Extracción Graphify corrupta: endpoint no hashable."
            ) from exc

        missing_source = str(source) not in node_ids
        missing_target = str(target) not in node_ids
        if missing_source or missing_target:
            raw_dangling_total += 1
            raw_dangling_by_relation[str(edge.get("relation") or "")] += 1

        if not missing_source and missing_target:
            fully_qualified = _php_import_fqn(edge, root, text_cache)
            candidates = (
                php_declarations.get(fully_qualified.casefold(), [])
                if fully_qualified
                else []
            )
            if len(candidates) == 1:
                previous_target = str(target)
                edge["target"] = candidates[0]
                edge["endpoint_repair"] = "php_fqn"
                target = candidates[0]
                missing_target = False
                repaired_php_imports += 1
                if len(repaired_examples) < 10:
                    repaired_examples.append(
                        {
                            "source_file": edge.get("source_file"),
                            "source_location": edge.get("source_location"),
                            "from_target": previous_target,
                            "to_target": candidates[0],
                            "fqn": fully_qualified,
                        }
                    )

        if not missing_source and missing_target:
            python_target = _python_import_target(
                edge,
                root,
                text_cache,
                python_file_nodes,
            )
            if python_target is not None:
                candidate, module = python_target
                previous_target = str(target)
                edge["target"] = candidate
                edge["endpoint_repair"] = "python_module_path"
                target = candidate
                missing_target = False
                repaired_python_imports += 1
                if len(repaired_examples) < 10:
                    repaired_examples.append(
                        {
                            "source_file": edge.get("source_file"),
                            "source_location": edge.get("source_location"),
                            "from_target": previous_target,
                            "to_target": candidate,
                            "module": module,
                        }
                    )

        if missing_source:
            if _known_absolute_file_id_artifact(edge, node_ids, root):
                discarded_invalid_producer_edges += 1
                if len(discarded_examples) < 10:
                    discarded_examples.append(
                        {
                            "target": target,
                            "relation": edge.get("relation"),
                            "source_file": edge.get("source_file"),
                            "source_location": edge.get("source_location"),
                            "reason": "absolute_file_id_indirect_call_artifact",
                        }
                    )
                continue
            unknown_missing_sources.append(
                {
                    "source": source,
                    "target": target,
                    "relation": edge.get("relation"),
                    "source_file": edge.get("source_file"),
                    "source_location": edge.get("source_location"),
                }
            )
            continue

        if missing_target:
            if edge.get("relation") in {"imports", "imports_from"}:
                relation = str(edge.get("relation"))
                expected_external_by_relation[relation] += 1
                if len(external_examples) < 10:
                    external_examples.append(
                        {
                            "source": source,
                            "target": target,
                            "relation": relation,
                            "source_file": edge.get("source_file"),
                            "source_location": edge.get("source_location"),
                        }
                    )
                # Do not hand a known-external/excluded endpoint to the builder:
                # its legacy alias fallback can bind a short external name such
                # as Brain\Monkey\Functions to an unrelated local functions.php.
                continue
            else:
                unclassified_dangling.append(
                    {
                        "source": source,
                        "target": target,
                        "relation": edge.get("relation"),
                        "source_file": edge.get("source_file"),
                        "source_location": edge.get("source_location"),
                    }
                )
                # The builder would drop this endpoint if it stayed unresolved,
                # but could also alias it to an unrelated local node. Keep the
                # ambiguity visible in the audit and omit the unsafe edge.
                continue

        if edge.get("source") == edge.get("target"):
            raise RuntimeError(
                "Extracción Graphify corrupta: self-loop previo al builder "
                f"en {edge.get('source_file')}:{edge.get('source_location')}."
            )
        edge_signature = json.dumps(
            edge,
            sort_keys=True,
            ensure_ascii=False,
            default=str,
            separators=(",", ":"),
        )
        if edge_signature in seen_sanitized_edges:
            deduplicated_exact_edges += 1
            continue
        seen_sanitized_edges.add(edge_signature)
        sanitized_edges.append(edge)

    if unknown_missing_sources:
        rendered = ", ".join(
            f"{item['source_file']}:{item['source_location']}"
            for item in unknown_missing_sources[:5]
        )
        raise RuntimeError(
            "Extracción Graphify corrupta: aristas con source inexistente "
            f"sin reparación segura: {rendered}"
        )

    edge_audit: dict[str, object] = {
        "raw_edge_count": len(raw_edges),
        "raw_dangling_total": raw_dangling_total,
        "raw_dangling_by_relation": dict(
            sorted(raw_dangling_by_relation.items())
        ),
        "repaired_internal_php_imports": repaired_php_imports,
        "repaired_internal_python_imports": repaired_python_imports,
        "repaired_internal_imports": (
            repaired_php_imports + repaired_python_imports
        ),
        "repaired_examples": repaired_examples,
        "discarded_invalid_producer_edges": (
            discarded_invalid_producer_edges
        ),
        "discarded_examples": discarded_examples,
        "deduplicated_exact_edges": deduplicated_exact_edges,
        "expected_external_or_excluded_imports": sum(
            expected_external_by_relation.values()
        ),
        "discarded_external_or_excluded_imports": sum(
            expected_external_by_relation.values()
        ),
        "expected_external_by_relation": dict(
            sorted(expected_external_by_relation.items())
        ),
        "external_examples": external_examples,
        "unclassified_dangling_edges": len(unclassified_dangling),
        "discarded_unclassified_dangling_edges": len(
            unclassified_dangling
        ),
        "unclassified_examples": unclassified_dangling[:10],
        "post_sanitization_dangling_total": 0,
    }
    return {**extraction, "edges": sanitized_edges}, edge_audit


def _edge_site(edge: dict) -> tuple[str, str, str]:
    """Return the stable producer site used to compare raw-builder aliases."""
    return (
        str(edge.get("source_file") or ""),
        str(edge.get("source_location") or ""),
        str(edge.get("relation") or ""),
    )


def _raw_builder_alias_audit(
    raw_extraction: dict,
    sanitized_extraction: dict,
    raw_graph: nx.Graph,
) -> dict[str, object]:
    """Count raw dangling imports that the legacy builder aliases locally."""
    node_ids = {
        str(node["id"])
        for node in raw_extraction.get("nodes", [])
        if isinstance(node, dict) and node.get("id") is not None
    }
    repaired_sites = {
        _edge_site(edge)
        for edge in sanitized_extraction.get("edges", [])
        if isinstance(edge, dict) and edge.get("endpoint_repair")
    }
    external_sites = {
        _edge_site(edge)
        for edge in raw_extraction.get("edges", [])
        if (
            isinstance(edge, dict)
            and str(edge.get("source")) in node_ids
            and str(edge.get("target")) not in node_ids
            and edge.get("relation") in {"imports", "imports_from"}
            and _edge_site(edge) not in repaired_sites
        )
    }

    internal_alias_examples: list[dict[str, object]] = []
    external_alias_examples: list[dict[str, object]] = []
    internal_alias_routes = 0
    external_alias_misroutes = 0

    for source, target, data in raw_graph.edges(data=True):
        site = _edge_site(data)
        item = {
            "source": str(source),
            "aliased_target": str(target),
            "source_file": data.get("source_file"),
            "source_location": data.get("source_location"),
            "relation": data.get("relation"),
        }
        if site in repaired_sites:
            internal_alias_routes += 1
            if len(internal_alias_examples) < 10:
                internal_alias_examples.append(item)
        elif site in external_sites:
            external_alias_misroutes += 1
            if len(external_alias_examples) < 10:
                external_alias_examples.append(item)

    return {
        "legacy_internal_alias_routes_replaced": internal_alias_routes,
        "legacy_internal_alias_examples": internal_alias_examples,
        "external_alias_misroutes_prevented": external_alias_misroutes,
        "external_alias_misroute_examples": external_alias_examples,
    }


def _build_graphs_and_direction_audit(
    extraction: dict,
    root: Path,
) -> tuple[nx.Graph, dict[str, object]]:
    """Compare Graph/DiGraph and select the directed graph after parity gates."""
    undirected = build_from_json(
        deepcopy(extraction),
        root=root,
        directed=False,
    )
    directed = build_from_json(
        deepcopy(extraction),
        root=root,
        directed=True,
    )
    if set(undirected.nodes()) != set(directed.nodes()):
        raise RuntimeError(
            "Graphify directed=True cambió el conjunto de nodos; "
            "se aborta por incompatibilidad."
        )

    undirected_pairs = {
        frozenset((str(source), str(target)))
        for source, target in undirected.edges()
    }
    directed_pairs = {
        frozenset((str(source), str(target)))
        for source, target in directed.edges()
    }
    if undirected_pairs != directed_pairs:
        raise RuntimeError(
            "Graphify directed=True cambió la conectividad base; "
            "se aborta por incompatibilidad."
        )
    if directed.number_of_edges() < undirected.number_of_edges():
        raise RuntimeError(
            "Graphify directed=True perdió aristas frente al grafo no dirigido."
        )

    reciprocal_pairs = {
        tuple(sorted((str(source), str(target))))
        for source, target in directed.edges()
        if source != target and directed.has_edge(target, source)
    }
    direction_audit = {
        "selected": "directed",
        "compatible_node_set": True,
        "compatible_undirected_endpoint_pairs": True,
        "undirected_edges": undirected.number_of_edges(),
        "directed_edges": directed.number_of_edges(),
        "additional_directional_edges": (
            directed.number_of_edges() - undirected.number_of_edges()
        ),
        "reciprocal_endpoint_pairs_preserved": len(reciprocal_pairs),
        "reciprocal_examples": [
            {"source": source, "target": target}
            for source, target in sorted(reciprocal_pairs)[:10]
        ],
    }
    return directed if DIRECTED_GRAPH else undirected, direction_audit


def _graph_health_audit(
    extraction: dict,
    graph: nx.Graph,
    root: Path,
) -> dict[str, object]:
    """Run Graphify's read-only diagnostic and fail on unequivocal corruption."""
    summary = diagnose_extraction(
        extraction,
        directed=DIRECTED_GRAPH,
        root=root,
        max_examples=5,
    )
    blocking_metrics = {
        "non_object_edges": summary.get("non_object_edges", 0),
        "missing_endpoint_edges": summary.get("missing_endpoint_edges", 0),
        "self_loop_edges": summary.get("self_loop_edges", 0),
    }
    blocking_metrics = {
        key: value for key, value in blocking_metrics.items() if value
    }
    if summary.get("post_build_error"):
        blocking_metrics["post_build_error"] = summary["post_build_error"]
    if summary.get("post_build_node_count") != graph.number_of_nodes():
        blocking_metrics["post_build_node_mismatch"] = {
            "diagnostic": summary.get("post_build_node_count"),
            "selected": graph.number_of_nodes(),
        }
    if summary.get("post_build_edge_count") != graph.number_of_edges():
        blocking_metrics["post_build_edge_mismatch"] = {
            "diagnostic": summary.get("post_build_edge_count"),
            "selected": graph.number_of_edges(),
        }
    if blocking_metrics:
        raise RuntimeError(
            "Diagnóstico Graphify detectó corrupción bloqueante: "
            f"{blocking_metrics}"
        )

    metric_names = (
        "node_count",
        "raw_edge_count",
        "valid_candidate_edges",
        "non_object_edges",
        "missing_endpoint_edges",
        "dangling_endpoint_edges",
        "self_loop_edges",
        "exact_duplicate_edges",
        "directed_same_endpoint_collapsed_edges",
        "undirected_same_endpoint_collapsed_edges",
        "same_endpoint_group_count",
        "relation_variant_groups",
        "source_location_variant_groups",
        "context_variant_groups",
        "post_build_node_count",
        "post_build_edge_count",
    )
    metrics = {name: summary.get(name, 0) for name in metric_names}
    warning_metrics = {
        key: metrics[key]
        for key in (
            "dangling_endpoint_edges",
            "exact_duplicate_edges",
            "directed_same_endpoint_collapsed_edges",
            "undirected_same_endpoint_collapsed_edges",
        )
        if metrics.get(key, 0)
    }
    return {
        "status": "warning" if warning_metrics else "ok",
        "blocking_corruption": False,
        "warning_metrics": warning_metrics,
        "metrics": metrics,
        "collapse_examples": summary.get("examples", []),
    }


def _metadata_only_node(
    path: Path,
    root: Path,
    *,
    kind: str,
    reason: str,
    extra: dict[str, object] | None = None,
) -> dict[str, object]:
    """Build a deterministic file anchor that contains no source-file content."""
    relative = path.relative_to(root).as_posix()
    node: dict[str, object] = {
        "id": make_id("akibara_metadata_file", relative),
        "label": path.name,
        "file_type": "code",
        "type": kind,
        "source_file": relative,
        "source_location": "L1",
        "extraction_mode": "metadata_only",
        "metadata_reason": reason,
        "content_indexed": False,
    }
    if extra:
        node.update(extra)
    return node


def _json_root_metadata(path: Path) -> dict[str, object]:
    """Validate a bounded JSON file and return only non-content shape metadata."""
    size = path.stat().st_size
    if size > MAX_DECLARATIVE_JSON_BYTES:
        raise RuntimeError(
            "JSON declarativo demasiado grande para validación segura "
            f"({size} bytes): {path}"
        )

    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"JSON declarativo inválido: {path}: {exc}") from exc

    if isinstance(value, dict):
        root_type = "object"
        entry_count = len(value)
    elif isinstance(value, list):
        root_type = "array"
        entry_count = len(value)
    elif value is None:
        root_type = "null"
        entry_count = 1
    elif isinstance(value, bool):
        root_type = "boolean"
        entry_count = 1
    elif isinstance(value, (int, float)):
        root_type = "number"
        entry_count = 1
    else:
        root_type = "string"
        entry_count = 1

    return {
        "declarative_root_type": root_type,
        "declarative_entry_count": entry_count,
        "source_bytes": size,
    }


def _review_sensitive_metadata_sources(
    detection: dict,
    root: Path,
) -> tuple[list[Path], list[dict[str, object]]]:
    """Resolve exact metadata-only exceptions and block every unknown skip.

    Unknown detector skips are never silently accepted because that would
    produce a partial graph. Exact exceptions remain safe because only file
    metadata is indexed; no source content bypasses Graphify's secret filter.
    """
    detected_files = {
        relative
        for paths in detection.get("files", {}).values()
        for value in paths
        if (relative := _relative_source_file(value, root))
    }
    skipped_by_path: dict[str, list[str]] = {}
    unresolved_entries: list[str] = []

    for raw_entry in detection.get("skipped_sensitive", []):
        entry = str(raw_entry)
        path_text = entry.split(" [", 1)[0]
        relative = _relative_source_file(path_text, root)
        if relative is None:
            unresolved_entries.append(entry)
            continue
        skipped_by_path.setdefault(relative, []).append(entry)

    metadata_paths: list[Path] = []
    reviewed: list[dict[str, object]] = []

    for relative, reason in sorted(METADATA_ONLY_SOURCE_EXCEPTIONS.items()):
        path = root / relative
        if not path.exists():
            continue
        if path.is_symlink():
            raise RuntimeError(
                "La excepción de fuente pública no puede ser un enlace simbólico: "
                f"{relative}"
            )
        try:
            resolved = path.resolve(strict=True)
            resolved.relative_to(root.resolve())
        except (OSError, ValueError) as exc:
            raise RuntimeError(
                f"La excepción de fuente pública sale del proyecto: {relative}"
            ) from exc
        if not resolved.is_file():
            raise RuntimeError(
                f"La excepción de fuente pública no es un archivo regular: {relative}"
            )

        detector_entries = skipped_by_path.pop(relative, [])
        if relative not in detected_files:
            metadata_paths.append(path)
        if detector_entries or relative not in detected_files:
            reviewed.append(
                {
                    "path": relative,
                    "reason": reason,
                    "detector_flagged": bool(detector_entries),
                    "representation": "metadata_only",
                    "content_indexed": False,
                }
            )

    unresolved_entries.extend(
        entry
        for entries in skipped_by_path.values()
        for entry in entries
    )
    if unresolved_entries:
        rendered = ", ".join(sorted(unresolved_entries))
        raise RuntimeError(
            "Graphify omitió archivos sensibles sin una excepción local revisada; "
            f"se aborta para no publicar un grafo parcial: {rendered}"
        )

    return sorted(metadata_paths), reviewed


def _augment_metadata_only_files(
    extraction: dict,
    code_files: list[Path],
    extra_metadata_files: list[Path],
    root: Path,
) -> tuple[dict, list[dict[str, object]]]:
    """Add file anchors for valid declarative JSON and reviewed public sources."""
    represented = _represented_source_files(extraction, root)
    metadata_nodes: list[dict[str, object]] = []
    metadata_records: list[dict[str, object]] = []
    missing_non_declarative: list[str] = []

    for path in code_files:
        relative = path.relative_to(root).as_posix()
        if relative in represented:
            continue
        if path.suffix.casefold() != ".json":
            missing_non_declarative.append(relative)
            continue

        shape = _json_root_metadata(path)
        reason = "valid_json_without_structural_nodes"
        metadata_nodes.append(
            _metadata_only_node(
                path,
                root,
                kind="declarative_file",
                reason=reason,
                extra=shape,
            )
        )
        metadata_records.append(
            {
                "path": relative,
                "kind": "declarative_json",
                "reason": reason,
                "representation": "metadata_only",
                "content_indexed": False,
                **shape,
            }
        )
        represented.add(relative)

    if missing_non_declarative:
        rendered = ", ".join(sorted(missing_non_declarative))
        raise RuntimeError(
            "Graphify no produjo nodos para fuentes no declarativas; "
            f"se aborta para no ocultarlas: {rendered}"
        )

    for path in extra_metadata_files:
        relative = path.relative_to(root).as_posix()
        if relative in represented:
            continue
        reason = METADATA_ONLY_SOURCE_EXCEPTIONS[relative]
        metadata_nodes.append(
            _metadata_only_node(
                path,
                root,
                kind="source_asset",
                reason=reason,
                extra={"source_bytes": path.stat().st_size},
            )
        )
        metadata_records.append(
            {
                "path": relative,
                "kind": "public_source_asset",
                "reason": reason,
                "representation": "metadata_only",
                "content_indexed": False,
                "source_bytes": path.stat().st_size,
            }
        )
        represented.add(relative)

    augmented = {
        **extraction,
        "nodes": list(extraction.get("nodes", [])) + metadata_nodes,
    }
    corpus_paths = {
        path.relative_to(root).as_posix()
        for path in [*code_files, *extra_metadata_files]
    }
    missing_after_fallback = corpus_paths - _represented_source_files(augmented, root)
    if missing_after_fallback:
        rendered = ", ".join(sorted(missing_after_fallback))
        raise RuntimeError(
            "La cobertura del corpus sigue incompleta después del fallback: "
            f"{rendered}"
        )

    return augmented, sorted(metadata_records, key=lambda item: str(item["path"]))


def _prepare_graph() -> dict[str, object]:
    """Detect, extract and validate the graph entirely in memory."""
    detection = detect(PROJECT_ROOT)
    extra_metadata_files, reviewed_exceptions = (
        _review_sensitive_metadata_sources(detection, PROJECT_ROOT)
    )
    code_files = sorted(
        Path(path)
        for path in detection.get("files", {}).get("code", [])
        if OUTPUT_DIR not in Path(path).parents
    )
    corpus_files = sorted({*code_files, *extra_metadata_files})
    if not corpus_files:
        raise RuntimeError("Graphify no detectó archivos de código de Akibara.")

    extraction = extract(
        code_files,
        cache_root=PROJECT_ROOT,
        root=PROJECT_ROOT,
        parallel=False,
    )
    extraction, metadata_records = _augment_metadata_only_files(
        extraction,
        code_files,
        extra_metadata_files,
        PROJECT_ROOT,
    )
    if not extraction.get("nodes"):
        raise RuntimeError("Graphify produjo una extracción vacía de Akibara.")

    raw_extraction = extraction
    raw_graph = build_from_json(
        deepcopy(raw_extraction),
        root=PROJECT_ROOT,
        directed=True,
    )
    extraction, edge_audit = _sanitize_and_classify_edges(
        raw_extraction,
        PROJECT_ROOT,
    )
    edge_audit.update(
        _raw_builder_alias_audit(
            raw_extraction,
            extraction,
            raw_graph,
        )
    )
    graph, direction_audit = _build_graphs_and_direction_audit(
        extraction,
        PROJECT_ROOT,
    )
    if graph.number_of_nodes() == 0:
        raise RuntimeError("Graphify produjo un grafo vacío de Akibara.")
    graph_health = _graph_health_audit(extraction, graph, PROJECT_ROOT)

    corpus_audit = {
        "schema_version": 1,
        "corpus_files": len(corpus_files),
        "detected_code_files": len(code_files),
        "structurally_represented_files": len(corpus_files)
        - len(metadata_records),
        "metadata_only_files": metadata_records,
        "reviewed_detector_exceptions": sorted(
            reviewed_exceptions,
            key=lambda item: str(item["path"]),
        ),
        "unresolved_sensitive_files": [],
        "edge_classification": edge_audit,
        "direction": direction_audit,
        "graph_health": graph_health,
        "graph_nodes": graph.number_of_nodes(),
        "graph_edges": graph.number_of_edges(),
    }
    return {
        "detection": detection,
        "code_files": code_files,
        "corpus_files": corpus_files,
        "extraction": extraction,
        "graph": graph,
        "corpus_audit": corpus_audit,
    }


def _print_metadata_summary(corpus_audit: dict[str, object]) -> None:
    """Print the metadata-only audit in a compact, explicit form."""
    metadata_files = corpus_audit.get("metadata_only_files", [])
    if metadata_files:
        print(
            "Representación solo metadatos "
            f"({len(metadata_files)} archivos; contenido no indexado):"
        )
        for item in metadata_files:
            print(f"  - {item['path']} [{item['reason']}]")

    reviewed = corpus_audit.get("reviewed_detector_exceptions", [])
    if reviewed:
        print("Coincidencias sensibles revisadas:")
        for item in reviewed:
            print(
                f"  - {item['path']} "
                f"[{item['reason']}; contenido no indexado]"
            )

    edge_audit = corpus_audit.get("edge_classification", {})
    print(
        "Endpoints dangling del extractor: "
        f"{edge_audit.get('raw_dangling_total', 0)} total; "
        f"{edge_audit.get('repaired_internal_imports', 0)} internos reparados "
        f"({edge_audit.get('repaired_internal_php_imports', 0)} PHP, "
        f"{edge_audit.get('repaired_internal_python_imports', 0)} Python); "
        f"{edge_audit.get('expected_external_or_excluded_imports', 0)} "
        "imports externos/excluidos descartados; "
        f"{edge_audit.get('discarded_invalid_producer_edges', 0)} "
        "artefactos inválidos descartados; "
        f"{edge_audit.get('deduplicated_exact_edges', 0)} duplicados exactos "
        "eliminados; "
        f"{edge_audit.get('unclassified_dangling_edges', 0)} sin clasificar."
    )
    print(
        "Alias inseguros del builder evitados: "
        f"{edge_audit.get('legacy_internal_alias_routes_replaced', 0)} "
        "imports internos redirigidos a su clase FQN; "
        f"{edge_audit.get('external_alias_misroutes_prevented', 0)} "
        "imports externos que habrían apuntado a nodos locales."
    )

    direction = corpus_audit.get("direction", {})
    print(
        "Dirección del grafo: "
        f"{direction.get('selected', 'unknown')}; "
        f"{direction.get('additional_directional_edges', 0)} aristas "
        "direccionales adicionales; "
        f"{direction.get('reciprocal_endpoint_pairs_preserved', 0)} "
        "pares recíprocos preservados."
    )

    graph_health = corpus_audit.get("graph_health", {})
    warning_metrics = graph_health.get("warning_metrics", {})
    if warning_metrics:
        rendered = ", ".join(
            f"{key}={value}"
            for key, value in sorted(warning_metrics.items())
        )
        print(f"Advertencia de salud Graphify (read-only): {rendered}.")
    else:
        print("Salud Graphify: OK (sin corrupción ni pérdida detectada).")


def _previous_labels() -> dict[str, str]:
    """Return stable labels for nodes that survived the rebuild."""
    if not GRAPH_PATH.exists():
        return {}

    data = json.loads(GRAPH_PATH.read_text(encoding="utf-8"))
    graph = json_graph.node_link_graph(data, edges="links")

    return {
        str(node): str(attributes.get("community_name", ""))
        for node, attributes in graph.nodes(data=True)
        if attributes.get("community_name")
    }


def _community_labels(
    graph,
    communities: dict[int, list[str]],
    previous: dict[str, str],
) -> dict[int, str]:
    """Preserve established names only when backed by a live node in the community."""
    labels: dict[int, str] = {}

    for community_id, nodes in communities.items():
        live_labels = {
            str(graph.nodes[node].get("label", ""))[:80]
            for node in nodes
        }
        prior = Counter(
            previous.get(str(node), "")
            for node in nodes
            if previous.get(str(node), "") in live_labels
        )
        prior.pop("", None)

        if prior:
            labels[community_id] = prior.most_common(1)[0][0]
            continue

        hub = max(nodes, key=lambda node: graph.degree(node))
        labels[community_id] = str(
            graph.nodes[hub].get("label", f"Community {community_id}")
        )[:80]

    return labels


def _stable_communities(graph) -> dict[int, list[str]]:
    """Cluster with a stable node/edge order and a fixed random seed."""
    stable = nx.Graph()
    stable.add_nodes_from(sorted(graph.nodes(), key=str))
    stable.add_edges_from(
        sorted(
            (
                (str(source), str(target))
                for source, target in graph.edges()
            ),
            key=lambda edge: (edge[0], edge[1]),
        )
    )
    if stable.number_of_edges() == 0:
        return {
            community_id: [node]
            for community_id, node in enumerate(stable.nodes())
        }

    options = {
        "seed": 42,
        "threshold": 1e-4,
        "resolution": 1.0,
    }
    if (
        "max_level"
        in inspect.signature(nx.community.louvain_communities).parameters
    ):
        options["max_level"] = 10

    groups = nx.community.louvain_communities(stable, **options)
    ordered = sorted(
        (sorted(group, key=str) for group in groups),
        key=lambda group: (-len(group), tuple(map(str, group))),
    )

    return {
        community_id: nodes
        for community_id, nodes in enumerate(ordered)
    }


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Actualiza o verifica el grafo local de código de Akibara."
    )
    parser.add_argument(
        "--check",
        action="store_true",
        help="valida cobertura y esquema sin escribir artefactos Graphify",
    )
    args = parser.parse_args(argv)

    prepared = _prepare_graph()
    corpus_files = prepared["corpus_files"]
    graph = prepared["graph"]
    corpus_audit = prepared["corpus_audit"]

    if args.check:
        print(
            "Graphify verificado sin escritura: "
            f"{len(corpus_files)} archivos, "
            f"{graph.number_of_nodes()} nodos, "
            f"{graph.number_of_edges()} relaciones."
        )
        _print_metadata_summary(corpus_audit)
        return 0

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    previous = _previous_labels()
    communities = _stable_communities(graph)
    cohesion = score_all(graph, communities)
    labels = _community_labels(graph, communities, previous)
    gods = god_nodes(graph)
    surprises = surprising_connections(graph, communities)
    questions = suggest_questions(graph, communities, labels)
    report_detection = {
        "total_files": len(corpus_files),
        "total_words": 0,
        "files": {"code": [str(path) for path in corpus_files]},
        # Unknown sensitive skips abort in _review_sensitive_metadata_sources.
        # Exact reviewed exceptions are represented metadata-only and preserved
        # in .graphify_corpus_audit.json instead of being misreported as omitted.
        "skipped_sensitive": [],
    }

    if not to_json(
        graph,
        communities,
        str(GRAPH_PATH),
        force=True,
        community_labels=labels,
    ):
        raise RuntimeError("Graphify refused to write graph.json.")

    (OUTPUT_DIR / "GRAPH_REPORT.md").write_text(
        generate(
            graph,
            communities,
            cohesion,
            labels,
            gods,
            surprises,
            report_detection,
            {"input": 0, "output": 0},
            str(PROJECT_ROOT),
            suggested_questions=questions,
        ),
        encoding="utf-8",
    )
    (OUTPUT_DIR / ".graphify_labels.json").write_text(
        json.dumps(
            {str(key): value for key, value in labels.items()},
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )
    (OUTPUT_DIR / ".graphify_analysis.json").write_text(
        json.dumps(
            {
                "communities": {
                    str(key): value for key, value in communities.items()
                },
                "cohesion": {str(key): value for key, value in cohesion.items()},
                "gods": gods,
                "surprises": surprises,
                "questions": questions,
            },
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )
    to_html(
        graph,
        communities,
        str(OUTPUT_DIR / "graph.html"),
        community_labels=labels,
    )

    manifest_path = OUTPUT_DIR / "manifest.json"
    manifest_path.unlink(missing_ok=True)
    save_manifest(
        {"code": [str(path) for path in corpus_files]},
        manifest_path=str(manifest_path),
        kind="ast",
        root=PROJECT_ROOT,
    )
    (OUTPUT_DIR / ".graphify_root").write_text(
        str(PROJECT_ROOT),
        encoding="utf-8",
    )
    CORPUS_AUDIT_PATH.write_text(
        json.dumps(
            corpus_audit,
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    print(
        "Graphify actualizado: "
        f"{len(corpus_files)} archivos, "
        f"{graph.number_of_nodes()} nodos, "
        f"{graph.number_of_edges()} relaciones, "
        f"{len(communities)} comunidades."
    )
    _print_metadata_summary(corpus_audit)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
