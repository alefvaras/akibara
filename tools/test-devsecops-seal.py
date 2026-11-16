#!/usr/bin/env python3
"""Pruebas negativas del candado criptográfico e inmutable DevSecOps (verify-devsecops-seal.py)."""
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SEAL_SCRIPT = ROOT / "tools" / "verify-devsecops-seal.py"
SEALED_FILES = (
    "phpstan.neon.dist",
    "phpstan-bootstrap.php",
    "phpstan-dba-bootstrap.php",
    "psalm.xml",
    "phpcs.xml.dist",
    "tools/akibara-business/phpcs.xml.dist",
    ".eslintrc.js",
    ".eslintignore",
    ".stylelintrc.json",
    ".sqlfluff",
    ".sqlfluffignore",
    ".semgrep/wordpress-security.yml",
    ".semgrepignore",
    "rector.php",
    "knip.json",
    "purgecss.config.js",
    "package.json",
    "tools/guardrail-ci.sh",
    "tools/guardrail-ast-hygiene.php",
    "ops/akibara-deploy-runtime.sh",
    "ops/db-cleanup-brevo-welcome-coupon.sql",
    "tools/akibara-releases/package-runtime.sh",
    "tools/akibara-releases/verify-runtime-package.py",
    "tools/qa-playwright-full-suite.mjs",
    ".githooks/pre-commit",
    ".githooks/pre-push",
)


class DevSecOpsSealTests(unittest.TestCase):
    def make_tree(self) -> Path:
        temp_dir = Path(tempfile.mkdtemp(prefix="akb-seal-test-"))
        self.addCleanup(lambda: shutil.rmtree(temp_dir, ignore_errors=True))
        (temp_dir / "tools" / "akibara-business").mkdir(parents=True, exist_ok=True)
        (temp_dir / ".semgrep").mkdir(parents=True, exist_ok=True)
        for rel in SEALED_FILES:
            (temp_dir / rel).parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / rel, temp_dir / rel)
        return temp_dir

    def run_seal(self, root: Path) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(SEAL_SCRIPT), "--root", str(root)],
            text=True,
            capture_output=True,
            check=False,
        )

    def test_clean_repository_passes(self) -> None:
        result = self.run_seal(ROOT)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_real_files_are_kernel_immutable(self) -> None:
        for rel in SEALED_FILES:
            st = os.stat(ROOT / rel)
            self.assertTrue(
                bool(st.st_flags & stat.UF_IMMUTABLE),
                f"{rel} debe tener el flag inmutable uchg activo en macOS",
            )

    def test_lowering_phpstan_level_is_rejected(self) -> None:
        tree = self.make_tree()
        phpstan = tree / "phpstan.neon.dist"
        phpstan.write_text(phpstan.read_text().replace("level: 6", "level: 1"))
        result = self.run_seal(tree)
        self.assertEqual(result.returncode, 1)
        self.assertIn("phpstan.neon.dist", result.stderr)

    def test_creating_baseline_file_is_rejected(self) -> None:
        tree = self.make_tree()
        (tree / "phpstan-baseline.neon").write_text("parameters:\n\tignoreErrors: []\n")
        result = self.run_seal(tree)
        self.assertEqual(result.returncode, 1)
        self.assertIn("baseline prohibido", result.stderr)

    def test_weakening_psalm_is_rejected(self) -> None:
        tree = self.make_tree()
        psalm = tree / "psalm.xml"
        psalm.write_text(psalm.read_text().replace('errorLevel="4"', 'errorLevel="7"'))
        result = self.run_seal(tree)
        self.assertEqual(result.returncode, 1)
        self.assertIn("psalm.xml", result.stderr)


if __name__ == "__main__":
    unittest.main()
