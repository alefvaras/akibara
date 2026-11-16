#!/usr/bin/env python3
"""Focused integrity tests for tools/update-graphify.py."""

from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
from pathlib import Path


MODULE_PATH = Path(__file__).resolve().parents[1] / "graphify_integrity.py"
SPEC = importlib.util.spec_from_file_location("akibara_update_graphify", MODULE_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"No se pudo cargar {MODULE_PATH}")
UPDATE_GRAPHIFY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(UPDATE_GRAPHIFY)


class MetadataFallbackTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tempdir = tempfile.TemporaryDirectory()
        self.root = Path(self.tempdir.name)

    def tearDown(self) -> None:
        self.tempdir.cleanup()

    def test_valid_unrepresented_json_gets_metadata_only_node(self) -> None:
        path = self.root / "ops" / "policy.json"
        path.parent.mkdir(parents=True)
        path.write_text('{"schema_version": 1, "rules": []}', encoding="utf-8")

        extraction, records = UPDATE_GRAPHIFY._augment_metadata_only_files(
            {"nodes": [], "edges": []},
            [path],
            [],
            self.root,
        )

        self.assertEqual(1, len(extraction["nodes"]))
        node = extraction["nodes"][0]
        self.assertEqual("ops/policy.json", node["source_file"])
        self.assertEqual("declarative_file", node["type"])
        self.assertEqual("metadata_only", node["extraction_mode"])
        self.assertFalse(node["content_indexed"])
        self.assertEqual("object", node["declarative_root_type"])
        self.assertEqual(2, node["declarative_entry_count"])
        self.assertEqual("ops/policy.json", records[0]["path"])

    def test_json_with_existing_node_is_not_duplicated(self) -> None:
        path = self.root / "ops" / "represented.json"
        path.parent.mkdir(parents=True)
        path.write_text('{"$schema": "example"}', encoding="utf-8")
        original = {
            "id": "ops_represented",
            "label": "represented.json",
            "file_type": "code",
            "source_file": "ops/represented.json",
            "source_location": "L1",
        }

        extraction, records = UPDATE_GRAPHIFY._augment_metadata_only_files(
            {"nodes": [original], "edges": []},
            [path],
            [],
            self.root,
        )

        self.assertEqual([original], extraction["nodes"])
        self.assertEqual([], records)

    def test_invalid_json_blocks_instead_of_hiding_file(self) -> None:
        path = self.root / "ops" / "broken.json"
        path.parent.mkdir(parents=True)
        path.write_text('{"broken":', encoding="utf-8")

        with self.assertRaisesRegex(RuntimeError, "JSON declarativo inválido"):
            UPDATE_GRAPHIFY._augment_metadata_only_files(
                {"nodes": [], "edges": []},
                [path],
                [],
                self.root,
            )

    def test_unrepresented_non_declarative_source_blocks(self) -> None:
        path = self.root / "ops" / "empty.py"
        path.parent.mkdir(parents=True)
        path.write_text("# no structural nodes\n", encoding="utf-8")

        with self.assertRaisesRegex(RuntimeError, "fuentes no declarativas"):
            UPDATE_GRAPHIFY._augment_metadata_only_files(
                {"nodes": [], "edges": []},
                [path],
                [],
                self.root,
            )


class SensitiveExceptionTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tempdir = tempfile.TemporaryDirectory()
        self.root = Path(self.tempdir.name)
        relative = next(iter(UPDATE_GRAPHIFY.METADATA_ONLY_SOURCE_EXCEPTIONS))
        self.relative = relative
        self.path = self.root / relative
        self.path.parent.mkdir(parents=True)

    def tearDown(self) -> None:
        self.tempdir.cleanup()

    def test_exact_tokens_css_exception_is_metadata_only_and_audited(self) -> None:
        secret_like_content = ":root { --api-token: should-never-be-copied; }\n"
        self.path.write_text(secret_like_content, encoding="utf-8")
        detection = {
            "files": {"code": []},
            "skipped_sensitive": [str(self.path)],
        }

        metadata_paths, reviewed = (
            UPDATE_GRAPHIFY._review_sensitive_metadata_sources(
                detection,
                self.root,
            )
        )
        extraction, records = UPDATE_GRAPHIFY._augment_metadata_only_files(
            {"nodes": [], "edges": []},
            [],
            metadata_paths,
            self.root,
        )

        self.assertEqual([self.path], metadata_paths)
        self.assertTrue(reviewed[0]["detector_flagged"])
        self.assertFalse(reviewed[0]["content_indexed"])
        self.assertEqual("source_asset", extraction["nodes"][0]["type"])
        serialized = json.dumps(extraction, ensure_ascii=False)
        self.assertNotIn("should-never-be-copied", serialized)
        self.assertEqual(self.relative, records[0]["path"])

    def test_unknown_sensitive_skip_blocks_partial_graph(self) -> None:
        self.path.write_text(":root {}\n", encoding="utf-8")
        unknown = self.root / "credentials.json"
        unknown.write_text('{"token": "not-indexed"}', encoding="utf-8")
        detection = {
            "files": {"code": []},
            "skipped_sensitive": [str(self.path), str(unknown)],
        }

        with self.assertRaisesRegex(RuntimeError, "grafo parcial"):
            UPDATE_GRAPHIFY._review_sensitive_metadata_sources(
                detection,
                self.root,
            )

    def test_symlink_cannot_use_public_source_exception(self) -> None:
        target = self.root / "real.css"
        target.write_text(":root {}\n", encoding="utf-8")
        self.path.symlink_to(target)
        detection = {
            "files": {"code": []},
            "skipped_sensitive": [str(self.path)],
        }

        with self.assertRaisesRegex(RuntimeError, "enlace simbólico"):
            UPDATE_GRAPHIFY._review_sensitive_metadata_sources(
                detection,
                self.root,
            )


class EdgeIntegrityTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tempdir = tempfile.TemporaryDirectory()
        self.root = Path(self.tempdir.name)

    def tearDown(self) -> None:
        self.tempdir.cleanup()

    def test_internal_php_import_is_repaired_by_exact_fqn(self) -> None:
        test_path = self.root / "tests" / "VolumeTitleTest.php"
        class_path = self.root / "src" / "Services" / "VolumeTitle.php"
        test_path.parent.mkdir(parents=True)
        class_path.parent.mkdir(parents=True)
        test_path.write_text(
            "<?php\n"
            "namespace Akibara\\Tests;\n"
            "use Akibara\\Services\\VolumeTitle;\n",
            encoding="utf-8",
        )
        class_path.write_text(
            "<?php\n"
            "namespace Akibara\\Services;\n"
            "\n"
            "final class VolumeTitle {}\n",
            encoding="utf-8",
        )
        target_id = "src_services_volumetitle_volumetitle"
        extraction = {
            "nodes": [
                {
                    "id": "tests_volumetitletest",
                    "label": "VolumeTitleTest.php",
                    "file_type": "code",
                    "source_file": "tests/VolumeTitleTest.php",
                    "source_location": "L1",
                },
                {
                    "id": target_id,
                    "label": "VolumeTitle",
                    "file_type": "code",
                    "source_file": "src/Services/VolumeTitle.php",
                    "source_location": "L4",
                },
            ],
            "edges": [
                {
                    "source": "tests_volumetitletest",
                    "target": "volumetitle",
                    "relation": "imports",
                    "context": "import",
                    "confidence": "EXTRACTED",
                    "source_file": "tests/VolumeTitleTest.php",
                    "source_location": "L3",
                }
            ],
        }

        sanitized, audit = UPDATE_GRAPHIFY._sanitize_and_classify_edges(
            extraction,
            self.root,
        )

        self.assertEqual(target_id, sanitized["edges"][0]["target"])
        self.assertEqual("php_fqn", sanitized["edges"][0]["endpoint_repair"])
        self.assertEqual(1, audit["raw_dangling_total"])
        self.assertEqual(1, audit["repaired_internal_php_imports"])
        self.assertEqual(0, audit["post_sanitization_dangling_total"])

    def test_external_import_is_audited_and_removed_before_builder(self) -> None:
        test_path = self.root / "tests" / "ExternalTest.php"
        test_path.parent.mkdir(parents=True)
        test_path.write_text(
            "<?php\n"
            "namespace Akibara\\Tests;\n"
            "use PHPUnit\\Framework\\TestCase;\n",
            encoding="utf-8",
        )
        extraction = {
            "nodes": [
                {
                    "id": "tests_externaltest",
                    "label": "ExternalTest.php",
                    "file_type": "code",
                    "source_file": "tests/ExternalTest.php",
                    "source_location": "L1",
                }
            ],
            "edges": [
                {
                    "source": "tests_externaltest",
                    "target": "testcase",
                    "relation": "imports",
                    "context": "import",
                    "confidence": "EXTRACTED",
                    "source_file": "tests/ExternalTest.php",
                    "source_location": "L3",
                }
            ],
        }

        sanitized, audit = UPDATE_GRAPHIFY._sanitize_and_classify_edges(
            extraction,
            self.root,
        )

        self.assertEqual([], sanitized["edges"])
        self.assertEqual(1, audit["raw_dangling_total"])
        self.assertEqual(1, audit["expected_external_or_excluded_imports"])
        self.assertEqual(1, audit["discarded_external_or_excluded_imports"])
        self.assertEqual(0, audit["post_sanitization_dangling_total"])

    def test_local_python_module_import_is_repaired_by_real_path(self) -> None:
        entry_path = self.root / "tools" / "entry.py"
        module_path = self.root / "tools" / "integrity.py"
        entry_path.parent.mkdir(parents=True)
        entry_path.write_text(
            "from integrity import main\n",
            encoding="utf-8",
        )
        module_path.write_text(
            "def main():\n"
            "    return 0\n",
            encoding="utf-8",
        )
        extraction = {
            "nodes": [
                {
                    "id": "tools_entry",
                    "label": "entry.py",
                    "file_type": "code",
                    "source_file": "tools/entry.py",
                    "source_location": "L1",
                },
                {
                    "id": "tools_integrity",
                    "label": "integrity.py",
                    "file_type": "code",
                    "source_file": "tools/integrity.py",
                    "source_location": "L1",
                },
            ],
            "edges": [
                {
                    "source": "tools_entry",
                    "target": "integrity",
                    "relation": "imports_from",
                    "context": "import",
                    "confidence": "EXTRACTED",
                    "source_file": "tools/entry.py",
                    "source_location": "L1",
                }
            ],
        }

        sanitized, audit = UPDATE_GRAPHIFY._sanitize_and_classify_edges(
            extraction,
            self.root,
        )

        self.assertEqual("tools_integrity", sanitized["edges"][0]["target"])
        self.assertEqual(
            "python_module_path",
            sanitized["edges"][0]["endpoint_repair"],
        )
        self.assertEqual(1, audit["repaired_internal_python_imports"])
        self.assertEqual(0, audit["post_sanitization_dangling_total"])

    def test_known_absolute_file_id_artifact_is_removed_and_audited(self) -> None:
        source_path = self.root / "ops" / "runner.mjs"
        source_path.parent.mkdir(parents=True)
        source_path.write_text("target(binding);\n", encoding="utf-8")
        extraction = {
            "nodes": [
                {
                    "id": "ops_runner",
                    "label": "runner.mjs",
                    "file_type": "code",
                    "source_file": "ops/runner.mjs",
                    "source_location": "L1",
                },
                {
                    "id": "target",
                    "label": "target()",
                    "file_type": "code",
                    "source_file": "ops/target.mjs",
                    "source_location": "L1",
                },
            ],
            "edges": [
                {
                    "source": UPDATE_GRAPHIFY.make_id(str(source_path)),
                    "target": "target",
                    "relation": "indirect_call",
                    "context": "argument",
                    "confidence": "INFERRED",
                    "source_file": "ops/runner.mjs",
                    "source_location": "L1",
                }
            ],
        }

        sanitized, audit = UPDATE_GRAPHIFY._sanitize_and_classify_edges(
            extraction,
            self.root,
        )

        self.assertEqual([], sanitized["edges"])
        self.assertEqual(1, audit["raw_dangling_total"])
        self.assertEqual(1, audit["discarded_invalid_producer_edges"])

    def test_raw_builder_external_alias_misroute_is_counted(self) -> None:
        edge = {
            "source": "test_node",
            "target": "functions",
            "relation": "imports",
            "source_file": "tests/ExampleTest.php",
            "source_location": "L12",
        }
        raw_extraction = {
            "nodes": [
                {
                    "id": "test_node",
                    "label": "ExampleTest",
                    "file_type": "code",
                    "source_file": "tests/ExampleTest.php",
                    "source_location": "L10",
                },
                {
                    "id": "theme_functions",
                    "label": "functions.php",
                    "file_type": "code",
                    "source_file": "theme/functions.php",
                    "source_location": "L1",
                },
            ],
            "edges": [edge],
        }
        raw_graph = UPDATE_GRAPHIFY.nx.DiGraph()
        raw_graph.add_nodes_from(["test_node", "theme_functions"])
        raw_graph.add_edge(
            "test_node",
            "theme_functions",
            **{
                key: value
                for key, value in edge.items()
                if key not in {"source", "target"}
            },
        )

        audit = UPDATE_GRAPHIFY._raw_builder_alias_audit(
            raw_extraction,
            {"nodes": raw_extraction["nodes"], "edges": []},
            raw_graph,
        )

        self.assertEqual(1, audit["external_alias_misroutes_prevented"])
        self.assertEqual(0, audit["legacy_internal_alias_routes_replaced"])

    def test_unknown_missing_source_fails_closed(self) -> None:
        extraction = {
            "nodes": [
                {
                    "id": "target",
                    "label": "target()",
                    "file_type": "code",
                    "source_file": "target.py",
                    "source_location": "L1",
                }
            ],
            "edges": [
                {
                    "source": "missing_source",
                    "target": "target",
                    "relation": "calls",
                    "source_file": "caller.py",
                    "source_location": "L1",
                }
            ],
        }

        with self.assertRaisesRegex(RuntimeError, "source inexistente"):
            UPDATE_GRAPHIFY._sanitize_and_classify_edges(
                extraction,
                self.root,
            )

    def test_missing_endpoint_field_fails_closed(self) -> None:
        extraction = {
            "nodes": [
                {
                    "id": "source",
                    "label": "source()",
                    "file_type": "code",
                    "source_file": "source.py",
                    "source_location": "L1",
                }
            ],
            "edges": [
                {
                    "source": "source",
                    "relation": "calls",
                    "source_file": "source.py",
                    "source_location": "L2",
                }
            ],
        }

        with self.assertRaisesRegex(RuntimeError, "sin source/target"):
            UPDATE_GRAPHIFY._sanitize_and_classify_edges(
                extraction,
                self.root,
            )

    def test_exact_duplicate_edges_are_deduplicated_and_audited(self) -> None:
        edge = {
            "source": "source",
            "target": "target",
            "relation": "calls",
            "confidence": "EXTRACTED",
            "weight": 1.0,
            "source_file": "source.py",
            "source_location": "L2",
        }
        extraction = {
            "nodes": [
                {
                    "id": "source",
                    "label": "source()",
                    "file_type": "code",
                    "source_file": "source.py",
                    "source_location": "L1",
                },
                {
                    "id": "target",
                    "label": "target()",
                    "file_type": "code",
                    "source_file": "target.py",
                    "source_location": "L1",
                },
            ],
            "edges": [edge, dict(edge)],
        }

        sanitized, audit = UPDATE_GRAPHIFY._sanitize_and_classify_edges(
            extraction,
            self.root,
        )

        self.assertEqual([edge], sanitized["edges"])
        self.assertEqual(1, audit["deduplicated_exact_edges"])

    def test_self_loop_fails_closed(self) -> None:
        extraction = {
            "nodes": [
                {
                    "id": "same",
                    "label": "same()",
                    "file_type": "code",
                    "source_file": "same.py",
                    "source_location": "L1",
                }
            ],
            "edges": [
                {
                    "source": "same",
                    "target": "same",
                    "relation": "references",
                    "source_file": "same.py",
                    "source_location": "L1",
                }
            ],
        }

        with self.assertRaisesRegex(RuntimeError, "self-loop"):
            UPDATE_GRAPHIFY._sanitize_and_classify_edges(
                extraction,
                self.root,
            )

    def test_directed_graph_preserves_reciprocal_edges_with_pair_parity(self) -> None:
        extraction = {
            "nodes": [
                {
                    "id": "a",
                    "label": "a()",
                    "file_type": "code",
                    "source_file": "a.py",
                    "source_location": "L1",
                },
                {
                    "id": "b",
                    "label": "b()",
                    "file_type": "code",
                    "source_file": "b.py",
                    "source_location": "L1",
                },
            ],
            "edges": [
                {
                    "source": "a",
                    "target": "b",
                    "relation": "calls",
                    "confidence": "EXTRACTED",
                    "weight": 1.0,
                    "source_file": "a.py",
                    "source_location": "L2",
                },
                {
                    "source": "b",
                    "target": "a",
                    "relation": "calls",
                    "confidence": "EXTRACTED",
                    "weight": 1.0,
                    "source_file": "b.py",
                    "source_location": "L2",
                },
            ],
        }

        graph, audit = UPDATE_GRAPHIFY._build_graphs_and_direction_audit(
            extraction,
            self.root,
        )

        self.assertTrue(graph.is_directed())
        self.assertEqual(2, graph.number_of_edges())
        self.assertEqual(1, audit["additional_directional_edges"])
        self.assertEqual(1, audit["reciprocal_endpoint_pairs_preserved"])
        self.assertTrue(audit["compatible_node_set"])
        self.assertTrue(audit["compatible_undirected_endpoint_pairs"])

    def test_community_labels_drops_stale_orphan_names_and_uses_live_hub(self) -> None:
        graph = UPDATE_GRAPHIFY.nx.DiGraph()
        graph.add_node("hub_node", label="MenuInjector.php")
        graph.add_node("leaf_node", label=".inject()")
        graph.add_edge("hub_node", "leaf_node")

        communities = {0: ["hub_node", "leaf_node"]}
        stale_previous = {
            "hub_node": "SeriesEditions",
            "leaf_node": "SeriesEditions",
        }
        labels = UPDATE_GRAPHIFY._community_labels(
            graph,
            communities,
            stale_previous,
        )
        self.assertEqual({0: "MenuInjector.php"}, labels)

        valid_previous = {
            "hub_node": "MenuInjector.php",
            "leaf_node": "MenuInjector.php",
        }
        preserved = UPDATE_GRAPHIFY._community_labels(
            graph,
            communities,
            valid_previous,
        )
        self.assertEqual({0: "MenuInjector.php"}, preserved)


if __name__ == "__main__":
    unittest.main()
