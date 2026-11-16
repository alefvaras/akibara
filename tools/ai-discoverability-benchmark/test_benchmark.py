#!/usr/bin/env python3
"""Hermetic tests for benchmark.py; no browser, network or production access."""

from __future__ import annotations

import base64
import json
import os
import stat
import tempfile
import unittest
from pathlib import Path

import benchmark


PNG_1X1 = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk"
    "/x8AAusB9Wl2nkwAAAAASUVORK5CYII="
)


class BenchmarkHarnessTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name) / "benchmark"
        self.protocol = benchmark.DEFAULT_PROTOCOL
        benchmark.initialize(
            root=self.root,
            phase="B0",
            batch_id="20260806-1200",
            protocol_path=self.protocol,
        )

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def run_dirs(self) -> list[Path]:
        return sorted((self.root / "B0" / "runs").iterdir())

    def populate(
        self,
        run_dir: Path,
        *,
        conversation_isolation: str = "temporary",
        personalization_state: str = "disabled",
        run_status: str = "completed",
        started_at_chile: str = "2026-08-06T12:00:00-04:00",
        completed_at_chile: str = "2026-08-06T12:00:30-04:00",
    ) -> None:
        run_path = run_dir / "run.yaml"
        run = json.loads(run_path.read_text(encoding="utf-8"))
        run["model_or_mode"] = "modo visible de prueba"
        run["conversation_isolation"] = conversation_isolation
        run["personalization_state"] = personalization_state
        run["comparison_stratum"] = benchmark.expected_comparison_stratum(
            conversation_isolation, personalization_state
        )
        run["search_toggle_state"] = "on"
        run["account_locale"] = "es-CL"
        run["location_context"] = "Chile/Santiago"
        run["completion_signal"] = "send_enabled"
        run["run_status"] = run_status
        run["started_at_chile"] = started_at_chile
        run["completed_at_chile"] = completed_at_chile
        benchmark.write_json(run_path, run)
        benchmark.atomic_write(
            run_dir / "response.md", b"Akibara no fue mencionada.\n"
        )
        benchmark.atomic_write(run_dir / "cited-urls.txt", b"")
        benchmark.atomic_write(run_dir / "screenshot.png", PNG_1X1)

    def seal_all(self) -> None:
        for run_dir in self.run_dirs():
            self.populate(run_dir)
            benchmark.seal_run(run_dir=run_dir, protocol_path=self.protocol)

    def test_init_creates_exact_matrix_and_private_pending_files(self) -> None:
        run_dirs = self.run_dirs()
        self.assertEqual(benchmark.EXPECTED_RUNS, 216)
        self.assertEqual(len(run_dirs), 216)
        coordinates: set[tuple[str, str, int]] = set()
        for run_dir in run_dirs:
            self.assertEqual(stat.S_IMODE(run_dir.stat().st_mode), 0o700)
            self.assertEqual(
                {path.name for path in run_dir.iterdir()},
                set(benchmark.REQUIRED_RUN_FILES),
            )
            for filename in benchmark.REQUIRED_RUN_FILES:
                self.assertEqual(
                    stat.S_IMODE((run_dir / filename).stat().st_mode), 0o600
                )
            run = json.loads((run_dir / "run.yaml").read_text(encoding="utf-8"))
            verification = json.loads(
                (run_dir / "verification.json").read_text(encoding="utf-8")
            )
            coordinates.add((run["engine"], run["query_id"], run["repetition"]))
            self.assertEqual(run["run_status"], "prepared")
            self.assertEqual(run["conversation_isolation"], "unknown")
            self.assertEqual(run["personalization_state"], "unknown")
            self.assertEqual(run["comparison_stratum"], "invalid")
            self.assertEqual(run["completed_at_chile"], "")
            self.assertEqual(verification["status"], "pending")
        self.assertEqual(len(coordinates), 216)
        self.assertFalse((self.root / "B0" / benchmark.MANIFEST_NAME).exists())

    def test_verified_matrix_manifest_is_byte_reproducible(self) -> None:
        self.seal_all()
        manifest_path = benchmark.finalize(
            root=self.root, phase="B0", protocol_path=self.protocol
        )
        first = manifest_path.read_bytes()
        self.assertEqual(stat.S_IMODE(manifest_path.stat().st_mode), 0o600)
        manifest = json.loads(first)
        self.assertEqual(manifest["cardinality"]["runs"], 216)
        self.assertEqual(len(manifest["runs"]), 216)
        self.assertTrue(
            all(run["verification"]["status"] == "verified" for run in manifest["runs"])
        )
        benchmark.finalize(root=self.root, phase="B0", protocol_path=self.protocol)
        self.assertEqual(first, manifest_path.read_bytes())
        benchmark.validate_manifest(
            root=self.root, phase="B0", protocol_path=self.protocol
        )

    def test_hash_tampering_and_mode_drift_are_rejected(self) -> None:
        run_dir = self.run_dirs()[0]
        self.populate(run_dir)
        benchmark.seal_run(run_dir=run_dir, protocol_path=self.protocol)
        queries = benchmark.parse_protocol(self.protocol)

        benchmark.atomic_write(run_dir / "response.md", b"respuesta alterada\n")
        with self.assertRaisesRegex(benchmark.HarnessError, "hash"):
            benchmark.validate_run(run_dir=run_dir, queries=queries)

        benchmark.atomic_write(run_dir / "response.md", b"Akibara no fue mencionada.\n")
        os.chmod(run_dir / "response.md", 0o644)
        with self.assertRaisesRegex(benchmark.HarnessError, "0600"):
            benchmark.validate_run(run_dir=run_dir, queries=queries)

    def test_seal_atomically_promotes_completed_to_verified(self) -> None:
        run_dir = self.run_dirs()[0]
        self.populate(run_dir)
        before = json.loads((run_dir / "run.yaml").read_text(encoding="utf-8"))
        self.assertEqual(before["run_status"], "completed")
        verification = benchmark.seal_run(
            run_dir=run_dir, protocol_path=self.protocol
        )
        after = json.loads((run_dir / "run.yaml").read_text(encoding="utf-8"))
        self.assertEqual(after["run_status"], "verified")
        self.assertEqual(verification["status"], "verified")
        self.assertEqual(
            verification["artifacts"]["run.yaml"]["sha256"],
            benchmark.sha256_file(run_dir / "run.yaml"),
        )
        self.assertEqual(
            verification,
            benchmark.seal_run(run_dir=run_dir, protocol_path=self.protocol),
        )

    def test_account_stratified_is_sealable(self) -> None:
        run_dir = self.run_dirs()[0]
        self.populate(
            run_dir,
            conversation_isolation="incognito",
            personalization_state="enabled",
        )
        benchmark.seal_run(run_dir=run_dir, protocol_path=self.protocol)
        run = json.loads((run_dir / "run.yaml").read_text(encoding="utf-8"))
        self.assertEqual(run["comparison_stratum"], "account_stratified")
        self.assertEqual(run["run_status"], "verified")

    def test_unknown_or_fresh_only_is_invalid_and_cannot_be_sealed(self) -> None:
        fresh_run = self.run_dirs()[0]
        self.populate(
            fresh_run,
            conversation_isolation="fresh_only",
            personalization_state="disabled",
            run_status="invalid",
        )
        with self.assertRaisesRegex(benchmark.HarnessError, "invalid"):
            benchmark.seal_run(run_dir=fresh_run, protocol_path=self.protocol)
        with self.assertRaisesRegex(benchmark.HarnessError, "verified"):
            benchmark.collect_manifest(
                root=self.root, phase="B0", protocol_path=self.protocol
            )

        unknown_run = self.run_dirs()[1]
        self.populate(
            unknown_run,
            conversation_isolation="temporary",
            personalization_state="unknown",
        )
        with self.assertRaisesRegex(benchmark.HarnessError, "run_status=invalid"):
            benchmark.seal_run(run_dir=unknown_run, protocol_path=self.protocol)

    def test_prepared_or_submitted_cannot_be_sealed(self) -> None:
        prepared_run = self.run_dirs()[0]
        with self.assertRaisesRegex(benchmark.HarnessError, "completed"):
            benchmark.seal_run(run_dir=prepared_run, protocol_path=self.protocol)

        submitted_run = self.run_dirs()[1]
        self.populate(submitted_run, run_status="submitted")
        with self.assertRaisesRegex(benchmark.HarnessError, "completed"):
            benchmark.seal_run(run_dir=submitted_run, protocol_path=self.protocol)

    def test_completion_time_and_chile_offsets_are_enforced(self) -> None:
        reversed_run = self.run_dirs()[0]
        self.populate(
            reversed_run,
            started_at_chile="2026-08-06T12:00:30-04:00",
            completed_at_chile="2026-08-06T12:00:00-04:00",
        )
        with self.assertRaisesRegex(benchmark.HarnessError, "igual o posterior"):
            benchmark.seal_run(run_dir=reversed_run, protocol_path=self.protocol)

        wrong_offset_run = self.run_dirs()[1]
        self.populate(
            wrong_offset_run,
            started_at_chile="2026-08-06T12:00:00+00:00",
            completed_at_chile="2026-08-06T12:00:30+00:00",
        )
        with self.assertRaisesRegex(benchmark.HarnessError, "offset Chile"):
            benchmark.seal_run(run_dir=wrong_offset_run, protocol_path=self.protocol)

    def test_invalid_engine_query_and_png_are_rejected_before_seal(self) -> None:
        run_dir = self.run_dirs()[0]
        self.populate(run_dir)
        run_path = run_dir / "run.yaml"
        run = json.loads(run_path.read_text(encoding="utf-8"))
        run["engine"] = "otro"
        benchmark.write_json(run_path, run)
        with self.assertRaisesRegex(benchmark.HarnessError, "engine"):
            benchmark.seal_run(run_dir=run_dir, protocol_path=self.protocol)

        run["engine"] = "chatgpt"
        run["query_exact"] = "consulta manipulada"
        benchmark.write_json(run_path, run)
        with self.assertRaisesRegex(benchmark.HarnessError, "query_exact"):
            benchmark.seal_run(run_dir=run_dir, protocol_path=self.protocol)

        run["query_exact"] = benchmark.parse_protocol(self.protocol)[run["query_id"]]
        benchmark.write_json(run_path, run)
        benchmark.atomic_write(run_dir / "screenshot.png", b"no es png")
        with self.assertRaisesRegex(benchmark.HarnessError, "PNG"):
            benchmark.seal_run(run_dir=run_dir, protocol_path=self.protocol)

    def test_finalize_rejects_pending_or_incomplete_cardinality(self) -> None:
        for run_dir in self.run_dirs():
            self.populate(run_dir)
        with self.assertRaisesRegex(benchmark.HarnessError, "run_status debe ser verified"):
            benchmark.collect_manifest(
                root=self.root, phase="B0", protocol_path=self.protocol
            )
        missing = self.run_dirs()[0]
        for child in missing.iterdir():
            child.unlink()
        missing.rmdir()
        with self.assertRaisesRegex(benchmark.HarnessError, "exactamente 216"):
            benchmark.collect_manifest(
                root=self.root, phase="B0", protocol_path=self.protocol
            )

    def test_protocol_rejects_an_extra_canonical_id(self) -> None:
        protocol_copy = Path(self.temporary.name) / "protocol.md"
        protocol_copy.write_text(
            self.protocol.read_text(encoding="utf-8")
            + "\n| C09 | Extra | Consulta fuera del contrato |\n",
            encoding="utf-8",
        )
        with self.assertRaisesRegex(benchmark.HarnessError, "extras"):
            benchmark.parse_protocol(protocol_copy)


if __name__ == "__main__":
    unittest.main(verbosity=2)
