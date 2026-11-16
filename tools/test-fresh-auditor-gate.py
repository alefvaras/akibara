#!/usr/bin/env python3
"""Pruebas positivas y controles negativos de la compuerta de auditoría fresca y su bucle."""

from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
GATE = ROOT / "tools" / "qa-fresh-auditor-gate.py"
LOOP = ROOT / "tools" / "akibara-predeploy-audit-loop.py"


def _git(repo: Path, *args: str) -> str:
    res = subprocess.run(
        ["git", "-C", str(repo), *args],
        text=True,
        capture_output=True,
        check=True,
    )
    return res.stdout


class FreshAuditorGateTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp(prefix="akb-fresh-audit-test-"))
        self.repo = self.tmp / "repo"
        self.bin = self.tmp / "bin"
        self.repo.mkdir()
        self.bin.mkdir()

        (self.repo / ".agents/skills/akibara-deploy-auditor").mkdir(parents=True)
        (self.repo / ".agents/skills/akibara-deploy-auditor/SKILL.md").write_text("# Checklist\n", encoding="utf-8")
        (self.repo / "public_html/wp-content/plugins/akibara-business/src").mkdir(parents=True)
        (self.repo / "public_html/wp-content/plugins/akibara-business/tests").mkdir(parents=True)
        (self.repo / "ops").mkdir(parents=True)
        (self.repo / "tools").mkdir(parents=True)
        shutil.copy2(GATE, self.repo / "tools/qa-fresh-auditor-gate.py")
        shutil.copy2(LOOP, self.repo / "tools/akibara-predeploy-audit-loop.py")
        shutil.copy2(ROOT / "tools/quality_source_policy.py", self.repo / "tools/quality_source_policy.py")

        self.target_php = self.repo / "public_html/wp-content/plugins/akibara-business/src/Demo.php"
        self.target_php.write_text("<?php\nfunction demo_sum(int $a, int $b): int {\n    return $a + $b;\n}\n", encoding="utf-8")

        _git(self.repo, "init", "--quiet")
        _git(self.repo, "config", "user.email", "qa@akibara.test")
        _git(self.repo, "config", "user.name", "QA Test")
        _git(self.repo, "add", ".")
        _git(self.repo, "commit", "-q", "-m", "init")

    def tearDown(self) -> None:
        shutil.rmtree(self.tmp, ignore_errors=True)

    def _install_codex_stub(self, script_body: str) -> None:
        stub = self.bin / "codex"
        stub.write_text("#!/usr/bin/env python3\n" + script_body, encoding="utf-8")
        stub.chmod(0o755)

    def _install_agy_stub(self, script_body: str) -> None:
        stub = self.bin / "agy"
        stub.write_text("#!/usr/bin/env python3\n" + script_body, encoding="utf-8")
        stub.chmod(0o755)

    def _run_gate(self, *extra: str, env_extra: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
        env = dict(os.environ)
        env["PATH"] = f"{self.bin}:{env['PATH']}"
        agy_stub = self.bin / "agy"
        env.setdefault("AKB_AUDITOR_AGY_BIN", str(agy_stub) if agy_stub.exists() else "")
        if env_extra:
            env.update(env_extra)
        return subprocess.run(
            [sys.executable, str(self.repo / "tools/qa-fresh-auditor-gate.py"), "--root", str(self.repo), *extra],
            text=True,
            capture_output=True,
            env=env,
            check=False,
        )

    def _run_loop(self, *extra: str, env_extra: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
        env = dict(os.environ)
        env["PATH"] = f"{self.bin}:{env['PATH']}"
        agy_stub = self.bin / "agy"
        env.setdefault("AKB_AUDITOR_AGY_BIN", str(agy_stub) if agy_stub.exists() else "")
        if env_extra:
            env.update(env_extra)
        return subprocess.run(
            [sys.executable, str(self.repo / "tools/akibara-predeploy-audit-loop.py"), "--root", str(self.repo), *extra],
            text=True,
            capture_output=True,
            env=env,
            check=False,
        )

    def test_stage1_rejects_inline_suppressions_in_diff(self) -> None:
        token = "@" + "phpstan-ignore-next-line"
        self.target_php.write_text(
            f"<?php\nfunction demo_sum(int $a, int $b): int {{\n    // {token}\n    return $a + $b;\n}}\n",
            encoding="utf-8",
        )
        res = self._run_gate("--engine", "codex")
        self.assertEqual(res.returncode, 1, res.stdout + res.stderr)
        self.assertIn("SUPPRESSION_PHPSTAN", res.stderr)

    def test_stage1_rejects_skipped_test_and_regex_dom_fake(self) -> None:
        php_test = self.repo / "public_html/wp-content/plugins/akibara-business/tests/DemoTest.php"
        skip_call = "markTest" + "Skipped"
        php_test.write_text(
            f"<?php\nclass DemoTest {{ public function test_x(): void {{ $this->{skip_call}('omit'); }} }}\n",
            encoding="utf-8",
        )
        js_test = self.repo / "public_html/wp-content/plugins/akibara-business/tests/ui.test.mjs"
        assert_call = "assert." + "match"
        read_call = "readFile" + "Sync"
        js_test.write_text(f"{assert_call}({read_call}('x.js', 'utf8'), /ok/);\n", encoding="utf-8")

        res = self._run_gate("--engine", "codex")
        self.assertEqual(res.returncode, 1)
        self.assertIn("TEST_SKIP_PHPUNIT", res.stderr)
        self.assertIn("TEST_REGEX_DOM_FAKE", res.stderr)

    def test_stage1_rejects_empty_catch_and_shell_masking(self) -> None:
        self.target_php.write_text(
            "<?php\nfunction demo_sum(int $a, int $b): int {\n    try { return $a + $b; } catch (\\Throwable $e) { }\n    return 0;\n}\n",
            encoding="utf-8",
        )
        sh = self.repo / "ops/sample.sh"
        sh.write_text("#!/usr/bin/env bash\nset -e\nphpunit --testdox || " + "true\n", encoding="utf-8")

        res = self._run_gate("--engine", "codex")
        self.assertEqual(res.returncode, 1)
        self.assertIn("SILENT_EMPTY_CATCH", res.stderr)
        self.assertIn("SHELL_MASKED_FAILURE", res.stderr)

    def test_stage1_rejects_seal_violation_without_escape(self) -> None:
        seal = self.repo / "tools/verify-devsecops-seal.py"
        seal.write_text("import sys\nprint('TAMPERED', file=sys.stderr)\nsys.exit(1)\n", encoding="utf-8")
        res = self._run_gate("--engine", "codex")
        self.assertEqual(res.returncode, 1)
        self.assertIn("DEVSECOPS_SEAL_VIOLATION", res.stderr)

    def test_stage2_approves_clean_diff_with_full_review_and_binds_digest(self) -> None:
        self.target_php.write_text(
            "<?php\nfunction demo_sum(int $a, int $b): int {\n    return $a + $b + 1;\n}\n",
            encoding="utf-8",
        )
        log_file = self.tmp / "codex_calls.log"
        self._install_codex_stub(f"""
import json, pathlib, sys
args = sys.argv[1:]
pathlib.Path({str(log_file)!r}).write_text(json.dumps(args), encoding='utf-8')
prompt = sys.stdin.read()
assert '--ephemeral' in args and 'read-only' in args
out_path = pathlib.Path(args[args.index('-o') + 1])
out_path.write_text(json.dumps({{
  'verdict': 'APROBADO',
  'audited_scope': 'akibara-business',
  'reviewed_files': ['public_html/wp-content/plugins/akibara-business/src/Demo.php'],
  'checks_summary': {{
    'error_omission_check': 'PASS',
    'suppressions_and_skips': 'PASS',
    'tests_and_edge_cases': 'PASS',
    'akibara_contracts': 'PASS',
  }},
  'blocking_findings': [],
  'non_blocking_notes': [],
  'summary': 'Cambios completos sin omisiones.'
}}), encoding='utf-8')
""")
        receipt = self.tmp / "receipt.json"
        res = self._run_gate("--component", "business", "--engine", "codex", "--evidence-out", str(receipt))
        self.assertEqual(res.returncode, 0, res.stdout + res.stderr)
        verify_ok = self._run_gate("--verify-receipt", str(receipt))
        self.assertEqual(verify_ok.returncode, 0, verify_ok.stdout + verify_ok.stderr)

        # Control negativo: si el código cambia después de emitir el recibo, --verify-receipt debe rechazarlo.
        self.target_php.write_text("<?php // mutated after audit\n", encoding="utf-8")
        digest_res = subprocess.run(
            [
                sys.executable,
                "-c",
                "import sys; sys.path.insert(0, sys.argv[1]); import importlib.util; "
                "s=importlib.util.spec_from_file_location('g', sys.argv[2]); m=importlib.util.module_from_spec(s); "
                "sys.modules[s.name]=m; s.loader.exec_module(m); "
                "print(m.compute_source_digest(__import__('pathlib').Path(sys.argv[3])))",
                str(self.repo / "tools"),
                str(self.repo / "tools/qa-fresh-auditor-gate.py"),
                str(self.repo),
            ],
            text=True,
            capture_output=True,
            check=True,
        )
        new_digest = digest_res.stdout.strip()
        stale_verify = self._run_gate("--verify-receipt", str(receipt), "--source-digest", new_digest)
        self.assertEqual(stale_verify.returncode, 1)
        self.assertIn("desalineado", stale_verify.stderr)

    def test_stage2_rejects_incomplete_file_coverage_anti_cherry_picking(self) -> None:
        self.target_php.write_text("<?php\nfunction demo_sum(int $a, int $b): int { return $a + $b + 2; }\n", encoding="utf-8")
        other = self.repo / "public_html/wp-content/plugins/akibara-business/src/Extra.php"
        other.write_text("<?php\nfunction demo_extra(): int { return 7; }\n", encoding="utf-8")

        self._install_codex_stub("""
import json, pathlib, sys
args = sys.argv[1:]
out_path = pathlib.Path(args[args.index('-o') + 1])
out_path.write_text(json.dumps({
  'verdict': 'APROBADO',
  'audited_scope': 'akibara-business',
  'reviewed_files': ['public_html/wp-content/plugins/akibara-business/src/Demo.php'],
  'checks_summary': {
    'error_omission_check': 'PASS',
    'suppressions_and_skips': 'PASS',
    'tests_and_edge_cases': 'PASS',
    'akibara_contracts': 'PASS',
  },
  'blocking_findings': [],
  'non_blocking_notes': [],
  'summary': 'Solo revise Demo.php.'
}), encoding='utf-8')
""")
        res = self._run_gate("--component", "business", "--engine", "codex")
        self.assertEqual(res.returncode, 1)
        self.assertIn("Extra.php", res.stderr)
        self.assertIn("AUDITOR_CONTRACT_ERROR", res.stderr)

    def test_loop_returns_failure_iterates_with_fresh_auditor_until_approved(self) -> None:
        # Iteración 1 tendrá un bloque catch vacío detectado; el fixer lo arregla y el auditor fresco aprueba en iteración 2.
        self.target_php.write_text(
            "<?php\nfunction demo_sum(int $a, int $b): int {\n    try { return $a + $b; } catch (\\Throwable $e) { }\n    return 0;\n}\n",
            encoding="utf-8",
        )
        invocations_log = self.tmp / "auditor_invocations.jsonl"
        self._install_codex_stub(f"""
import json, pathlib, sys
args = sys.argv[1:]
with open({str(invocations_log)!r}, 'a', encoding='utf-8') as fh:
    fh.write(json.dumps({{'args': args}}) + '\\n')
out_path = pathlib.Path(args[args.index('-o') + 1])
out_path.write_text(json.dumps({{
  'verdict': 'APROBADO',
  'audited_scope': 'akibara-business',
  'reviewed_files': ['public_html/wp-content/plugins/akibara-business/src/Demo.php'],
  'checks_summary': {{
    'error_omission_check': 'PASS',
    'suppressions_and_skips': 'PASS',
    'tests_and_edge_cases': 'PASS',
    'akibara_contracts': 'PASS',
  }},
  'blocking_findings': [],
  'non_blocking_notes': [],
  'summary': 'Aprobado tras corrección.'
}}), encoding='utf-8')
""")
        approved_marker = self.tmp / "deploy_executed.txt"
        fixer_script = (
            f"python3 -c \"import pathlib; pathlib.Path({str(self.target_php)!r}).write_text("
            "'<?php\\nfunction demo_sum(int $a, int $b): int {\\n    return $a + $b;\\n}\\n', encoding='utf-8')\""
        )
        res = self._run_loop(
            "--component",
            "business",
            "--engine",
            "codex",
            "--max-iterations",
            "3",
            "--fixer-cmd",
            fixer_script,
            "--on-approved-cmd",
            f"echo deployed > {approved_marker}",
        )
        self.assertEqual(res.returncode, 0, res.stdout + res.stderr)
        self.assertTrue(approved_marker.is_file(), "Sólo tras APROBADO debe ejecutarse --on-approved-cmd")
        calls = [json.loads(line) for line in invocations_log.read_text(encoding="utf-8").splitlines() if line.strip()]
        self.assertEqual(len(calls), 1, "Iteración 1 falló en Etapa 1; Iteración 2 ejecutó auditoría fresca")
        self.assertIn("--ephemeral", calls[0]["args"])
        self.assertNotIn("--resume", calls[0]["args"])

    def test_loop_aborts_at_max_iterations_when_auditor_keeps_rejecting(self) -> None:
        self.target_php.write_text("<?php\nfunction demo_sum(int $a, int $b): int { return $a + $b + 9; }\n", encoding="utf-8")
        count_file = self.tmp / "count.txt"
        self._install_codex_stub(f"""
import json, pathlib, sys
p = pathlib.Path({str(count_file)!r})
n = int(p.read_text()) + 1 if p.is_file() else 1
p.write_text(str(n))
args = sys.argv[1:]
out_path = pathlib.Path(args[args.index('-o') + 1])
out_path.write_text(json.dumps({{
  'verdict': 'RECHAZADO',
  'audited_scope': 'akibara-business',
  'reviewed_files': ['public_html/wp-content/plugins/akibara-business/src/Demo.php'],
  'checks_summary': {{
    'error_omission_check': 'FAIL',
    'suppressions_and_skips': 'PASS',
    'tests_and_edge_cases': 'PASS',
    'akibara_contracts': 'PASS',
  }},
  'blocking_findings': [{{
    'severity': 'high',
    'file': 'public_html/wp-content/plugins/akibara-business/src/Demo.php',
    'line': 2,
    'category': 'MISSING_ERROR_HANDLING',
    'description': 'Falta validación explícita.',
    'required_fix': 'Agregar validación y test negativo.'
  }}],
  'non_blocking_notes': [],
  'summary': 'Rechazado.'
}}), encoding='utf-8')
""")
        approved_marker = self.tmp / "should_not_deploy.txt"
        res = self._run_loop(
            "--component",
            "business",
            "--engine",
            "codex",
            "--max-iterations",
            "2",
            "--fixer-cmd",
            "true",
            "--on-approved-cmd",
            f"echo bad > {approved_marker}",
        )
        self.assertEqual(res.returncode, 1)
        self.assertEqual(int(count_file.read_text()), 2)
        self.assertFalse(approved_marker.is_file())

    def test_deterministic_only_receipt_cannot_pass_verify_receipt(self) -> None:
        self.target_php.write_text("<?php\nfunction demo_sum(int $a, int $b): int { return $a + $b + 3; }\n", encoding="utf-8")
        receipt = self.tmp / "det-only.json"
        res = self._run_gate("--component", "business", "--engine", "deterministic-only", "--evidence-out", str(receipt))
        self.assertEqual(res.returncode, 0, res.stdout + res.stderr)
        verify_det = self._run_gate("--verify-receipt", str(receipt))
        self.assertEqual(verify_det.returncode, 1)

    def test_stage1_rejects_multiline_manage_stock_with_reserved_status(self) -> None:
        bad_status = "on" + "backorder"
        self.target_php.write_text(
            f"<?php\nfunction bad_stock(): void {{\n    update_post_meta(1, '_manage_stock', 'yes');\n    $_manage_stock = 'yes';\n    $_stock_status = '{bad_status}';\n}}\n",
            encoding="utf-8",
        )
        res = self._run_gate("--engine", "codex")
        self.assertEqual(res.returncode, 1)
        self.assertIn("FORBIDDEN_BACKORDER_MANAGE_STOCK", res.stderr)

    def test_fallback_runs_on_primary_api_error_but_never_after_real_rechazado(self) -> None:
        self.target_php.write_text("<?php\nfunction demo_sum(int $a, int $b): int { return $a + $b + 4; }\n", encoding="utf-8")
        claude_calls = self.tmp / "claude_calls.txt"
        # Caso 1: claude devuelve RECHAZADO real -> NO debe caer al motor secundario (codex).
        claude_stub = self.bin / "claude"
        claude_stub.write_text(f"""#!/usr/bin/env python3
import json, pathlib, sys
p = pathlib.Path({str(claude_calls)!r})
p.write_text(str((int(p.read_text()) if p.is_file() else 0) + 1))
print(json.dumps({{
  'is_error': False,
  'structured_output': {{
    'verdict': 'RECHAZADO',
    'audited_scope': 'akibara-business',
    'reviewed_files': ['public_html/wp-content/plugins/akibara-business/src/Demo.php'],
    'checks_summary': {{
      'error_omission_check': 'FAIL',
      'suppressions_and_skips': 'PASS',
      'tests_and_edge_cases': 'PASS',
      'akibara_contracts': 'PASS',
    }},
    'blocking_findings': [{{
      'severity': 'high',
      'file': 'public_html/wp-content/plugins/akibara-business/src/Demo.php',
      'line': 2,
      'category': 'REAL_REJECTION',
      'description': 'Bloqueo real.',
      'required_fix': 'Corregir.'
    }}],
    'non_blocking_notes': [],
    'summary': 'Rechazado.'
  }}
}}))
""", encoding="utf-8")
        claude_stub.chmod(0o755)
        codex_called = self.tmp / "codex_called.txt"
        self._install_codex_stub(f"import pathlib; pathlib.Path({str(codex_called)!r}).write_text('YES')\n")

        res = self._run_gate("--engine", "claude", "--allow-fallback-engine")
        self.assertEqual(res.returncode, 1)
        self.assertEqual(int(claude_calls.read_text()), 1)
        self.assertFalse(codex_called.exists(), "Nunca hacer fallback buscando APROBADO tras RECHAZADO")

    def test_theme_core_modules_require_core_min_not_per_file_min(self) -> None:
        css_dir = self.repo / "public_html/wp-content/themes/akibara-gp/assets/css"
        css_dir.mkdir(parents=True, exist_ok=True)
        (css_dir / "header.css").write_text(".h{color:#000}\n", encoding="utf-8")
        (css_dir / "footer.css").write_text(".f{color:#111}\n", encoding="utf-8")

        self._install_codex_stub(
            "import json, pathlib, sys\n"
            "out = sys.argv[sys.argv.index('-o') + 1]\n"
            "pathlib.Path(out).write_text(json.dumps({\n"
            "  'verdict': 'APROBADO', 'audited_scope': 'theme', 'reviewed_files': [\n"
            "    'public_html/wp-content/themes/akibara-gp/assets/css/core.css',\n"
            "    'public_html/wp-content/themes/akibara-gp/assets/css/footer.css',\n"
            "    'public_html/wp-content/themes/akibara-gp/assets/css/header.css'\n"
            "  ], 'checks_summary': {'error_omission_check':'PASS','suppressions_and_skips':'PASS','tests_and_edge_cases':'PASS','akibara_contracts':'PASS'},\n"
            "  'blocking_findings': [], 'non_blocking_notes': [], 'summary': 'ok'\n"
            "}))\n"
        )
        # Sin core.css / core.min.css debe fallar Etapa 1.
        stale = self._run_gate("--component", "theme", "--engine", "codex")
        self.assertEqual(stale.returncode, 1)
        self.assertIn("STALE_BUILD_THEME_ASSET", stale.stderr)

        # Con core.css + core.min.css actualizados debe aprobar sin pedir header.min.css ni footer.min.css.
        (css_dir / "core.css").write_text(".h{color:#000}.f{color:#111}\n", encoding="utf-8")
        (css_dir / "core.min.css").write_text(".h{color:#000}.f{color:#111}\n", encoding="utf-8")
        ok = self._run_gate("--component", "theme", "--engine", "codex")
        self.assertEqual(ok.returncode, 0, ok.stdout + ok.stderr)

    def test_stage1_catches_private_path_without_silent_skip(self) -> None:
        dump = self.repo / "public_html/wp-content/plugins/akibara-business/dump.sql"
        dump.write_text("INSERT INTO wp_users VALUES (1);\n", encoding="utf-8")
        res = self._run_gate("--engine", "codex")
        self.assertEqual(res.returncode, 1)
        self.assertIn("PRIVATE_DATA_PATH", res.stderr)

    def test_stage1_catches_neutral_return_catch_and_body_deletion(self) -> None:
        svc = self.repo / "public_html/wp-content/plugins/akibara-business/src/Service.php"
        svc.write_text(
            "<?php\nfunction svc_run(): array {\n    try {\n        return [1];\n    } catch (\\Throwable $e) {\n        throw $e;\n    }\n}\n",
            encoding="utf-8",
        )
        _git(self.repo, "add", str(svc.relative_to(self.repo)))
        _git(self.repo, "commit", "-q", "-m", "add safe catch")

        # Borrar únicamente throw $e; y sustituir por return []; sin tocar la línea del catch
        svc.write_text(
            "<?php\nfunction svc_run(): array {\n    try {\n        return [1];\n    } catch (\\Throwable $e) {\n        return [];\n    }\n}\n",
            encoding="utf-8",
        )
        res = self._run_gate("--component", "business", "--engine", "codex")
        self.assertEqual(res.returncode, 1)
        self.assertIn("SILENT_EMPTY_CATCH", res.stderr)

    def test_verify_receipt_rejects_auditor_policy_drift_after_approval(self) -> None:
        self.target_php.write_text("<?php\nfunction demo_sum(int $a, int $b): int { return $a + $b + 9; }\n", encoding="utf-8")
        self._install_codex_stub(
            "import json, pathlib, sys\n"
            "out = sys.argv[sys.argv.index('-o') + 1]\n"
            "pathlib.Path(out).write_text(json.dumps({\n"
            "  'verdict': 'APROBADO', 'audited_scope': 'business',\n"
            "  'reviewed_files': ['public_html/wp-content/plugins/akibara-business/src/Demo.php'],\n"
            "  'checks_summary': {'error_omission_check':'PASS','suppressions_and_skips':'PASS','tests_and_edge_cases':'PASS','akibara_contracts':'PASS'},\n"
            "  'blocking_findings': [], 'non_blocking_notes': [], 'summary': 'ok'\n"
            "}))\n"
        )
        receipt = self.tmp / "policy-receipt.json"
        res = self._run_gate("--component", "business", "--engine", "codex", "--evidence-out", str(receipt))
        self.assertEqual(res.returncode, 0, res.stdout + res.stderr)

        (self.repo / ".agents/skills/akibara-deploy-auditor/SKILL.md").write_text("# Debilitado\n", encoding="utf-8")
        verify = self._run_gate("--verify-receipt", str(receipt))
        self.assertEqual(verify.returncode, 1)
        self.assertIn("AUDITOR_POLICY_DRIFT", verify.stderr)


    def test_four_tier_fallback_escalates_codex_to_claude_to_argon_to_hydrogen(self) -> None:
        self.target_php.write_text("<?php\nfunction demo_sum(int $a, int $b): int { return $a + $b + 11; }\n", encoding="utf-8")
        trace_log = self.tmp / "cascade.jsonl"
        self._install_codex_stub(f"""
import json, pathlib, sys
with open({str(trace_log)!r}, "a", encoding="utf-8") as fh:
    fh.write(json.dumps({{"engine": "codex", "args": sys.argv[1:]}}) + chr(10))
sys.stderr.write("You've hit your usage limit for Codex." + chr(10))
sys.exit(1)
""")
        claude_stub = self.bin / "claude"
        claude_stub.write_text(f"""#!/usr/bin/env python3
import json, pathlib, sys
with open({str(trace_log)!r}, "a", encoding="utf-8") as fh:
    fh.write(json.dumps({{"engine": "claude", "args": sys.argv[1:]}}) + chr(10))
print(json.dumps({{"is_error": True, "result": "Credit balance is too low"}}))
""", encoding="utf-8")
        claude_stub.chmod(0o755)
        self._install_agy_stub(f"""
import json, pathlib, sys
args = sys.argv[1:]
model = args[args.index("--model") + 1]
settings = json.loads(args[args.index("--settings") + 1])
assert "--mode" in args and args[args.index("--mode") + 1] == "plan"
assert "--sandbox" in args
assert "write_file(*)" in settings["permissions"]["deny"]
assert "command(*)" in settings["permissions"]["deny"]
assert "read_file(*)" in settings["permissions"]["allow"]
with open({str(trace_log)!r}, "a", encoding="utf-8") as fh:
    fh.write(json.dumps({{"engine": "agy", "model": model}}) + chr(10))
if model == "gemini-4-argon-high":
    print("error: Individual quota reached.")
    print(json.dumps({{"status": "ERROR", "error": "RESOURCE_EXHAUSTED"}}))
    sys.exit(0)
assert model == "gemini-4-hydrogen-xhigh"
print(json.dumps({{
    "status": "SUCCESS",
    "structured_output": {{
        "verdict": "APROBADO",
        "audited_scope": "business",
        "reviewed_files": ["public_html/wp-content/plugins/akibara-business/src/Demo.php"],
        "checks_summary": {{
            "error_omission_check": "PASS",
            "suppressions_and_skips": "PASS",
            "tests_and_edge_cases": "PASS",
            "akibara_contracts": "PASS",
        }},
        "blocking_findings": [],
        "non_blocking_notes": [],
        "summary": "Aprobado por Hydrogen XHigh en solo lectura."
    }}
}}))
""")
        receipt = self.tmp / "cascade-receipt.json"
        res = self._run_gate("--component", "business", "--engine", "auto", "--allow-fallback-engine", "--evidence-out", str(receipt))
        self.assertEqual(res.returncode, 0, res.stdout + res.stderr)
        calls = [json.loads(line) for line in trace_log.read_text(encoding="utf-8").splitlines() if line.strip()]
        self.assertEqual(
            [c.get("model") or c["engine"] for c in calls],
            ["codex", "claude", "gemini-4-argon-high", "gemini-4-hydrogen-xhigh"],
        )
        payload = json.loads(receipt.read_text(encoding="utf-8"))
        self.assertEqual(payload["engine"], "hydrogen")
        self.assertEqual(payload["model"], "gemini-4-hydrogen-xhigh")
        self.assertEqual(len(payload["engine_attempts"]), 3)
        verify_ok = self._run_gate("--verify-receipt", str(receipt))
        self.assertEqual(verify_ok.returncode, 0, verify_ok.stdout + verify_ok.stderr)

    def test_argon_real_rejection_never_falls_back_to_hydrogen(self) -> None:
        self.target_php.write_text("<?php\nfunction demo_sum(int $a, int $b): int { return $a + $b + 12; }\n", encoding="utf-8")
        models_called = self.tmp / "agy_models.txt"
        self._install_agy_stub(f"""
import json, pathlib, sys
model = sys.argv[sys.argv.index("--model") + 1]
with open({str(models_called)!r}, "a", encoding="utf-8") as fh:
    fh.write(model + chr(10))
print(json.dumps({{
    "status": "SUCCESS",
    "structured_output": {{
        "verdict": "RECHAZADO",
        "audited_scope": "business",
        "reviewed_files": ["public_html/wp-content/plugins/akibara-business/src/Demo.php"],
        "checks_summary": {{
            "error_omission_check": "FAIL",
            "suppressions_and_skips": "PASS",
            "tests_and_edge_cases": "PASS",
            "akibara_contracts": "PASS",
        }},
        "blocking_findings": [{{
            "severity": "high",
            "file": "public_html/wp-content/plugins/akibara-business/src/Demo.php",
            "line": 2,
            "category": "CONTRACT_VIOLATION",
            "description": "Rechazo real de Argon.",
            "required_fix": "Corregir."
        }}],
        "non_blocking_notes": [],
        "summary": "Rechazado por Argon."
    }}
}}))
""")
        res = self._run_gate("--component", "business", "--engine", "argon", "--allow-fallback-engine")
        self.assertEqual(res.returncode, 1)
        self.assertEqual(models_called.read_text(encoding="utf-8").splitlines(), ["gemini-4-argon-high"])

    def test_agy_denied_actions_without_structured_output_fails_closed(self) -> None:
        self.target_php.write_text("<?php\nfunction demo_sum(int $a, int $b): int { return $a + $b + 13; }\n", encoding="utf-8")
        self._install_agy_stub("""
import json
print(json.dumps({
    "status": "SUCCESS",
    "response": "",
    "denied_actions": [{"action": "command", "display_name": "Bash"}]
}))
""")
        res = self._run_gate("--component", "business", "--engine", "hydrogen")
        self.assertEqual(res.returncode, 1)
        self.assertIn("hydrogen", res.stderr)


if __name__ == "__main__":
    unittest.main()
