#!/usr/bin/env python3
"""Bucle iterativo pre-despliegue de Akibara con auditor en contexto fresco.

Flujo cerrado:
1. Ejecuta verificación técnica previa (`--verify-cmd`, ej. `bash tools/guardrail-ci.sh`).
2. Lanza `tools/qa-fresh-auditor-gate.py` en una sesión limpia efímera (solo lectura).
3. Si el auditor emite `RECHAZADO`:
   - Guarda los hallazgos bloqueantes en `.local-quality/audit-loop/`.
   - Devuelve el trabajo al fixer (`--fixer-cmd` o `--auto-fix-engine`) en sesión separada,
     re-valida y vuelve a invocar una NUEVA instancia limpia del auditor hasta alcanzar
     `APROBADO` o agotar `--max-iterations` (acotado para evitar bucles infinitos).
4. Solo tras verificar el recibo `APROBADO` ejecuta `--on-approved-cmd` (ej. despliegue).
"""

from __future__ import annotations

import argparse
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time
from typing import Any

SCRIPT_DIR = Path(__file__).resolve().parent
GATE_SCRIPT = SCRIPT_DIR / "qa-fresh-auditor-gate.py"


def _load_gate_module() -> Any:
    spec = importlib.util.spec_from_file_location("akb_fresh_auditor_gate", GATE_SCRIPT)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"No se pudo cargar {GATE_SCRIPT}")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def _format_rejection_markdown(iteration: int, payload: dict[str, Any], verify_error: str = "") -> str:
    lines = [
        f"# Rechazo de Auditoría Pre-Despliegue (Iteración {iteration})",
        "",
        f"- **Componente:** `{payload.get('component', 'all')}`",
        f"- **Veredicto:** `{payload.get('verdict', 'RECHAZADO')}`",
        f"- **Source Digest:** `{payload.get('source_digest', '')}`",
        "",
    ]
    if verify_error:
        lines.extend(["## Fallo en Comando de Verificación (`--verify-cmd`)", "", "```", verify_error[-4000:], "```", ""])

    stage1_findings = ((payload.get("stage1") or {}).get("findings") or [])
    stage2_findings = ((payload.get("stage2") or {}).get("blocking_findings") or [])
    findings = stage1_findings if stage1_findings else stage2_findings

    lines.append("## Hallazgos Bloqueantes a Corregir")
    if not findings and not verify_error:
        err = (payload.get("stage2") or {}).get("error") or payload.get("error") or "Error desconocido."
        lines.append(f"- Fallo fail-closed del auditor: {err}")
    for idx, item in enumerate(findings, start=1):
        lines.append(
            f"{idx}. **[{str(item.get('severity', 'high')).upper()}] `{item.get('file')}:{item.get('line', 0)}` "
            f"(`{item.get('category', 'GENERAL')}`)** — {item.get('description', '')}\n"
            f"   - **Corrección requerida:** {item.get('required_fix', '')}"
        )
    lines.append("")
    return "\n".join(lines)


def _invoke_shell(cmd: str, root: Path, env: dict[str, str], timeout_s: int) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", "-c", cmd],
        cwd=root,
        env=env,
        text=True,
        capture_output=True,
        encoding="utf-8",
        errors="replace",
        timeout=timeout_s,
        check=False,
    )


def _invoke_auto_fixer(
    engine: str,
    root: Path,
    rejection_md: str,
    model: str,
    timeout_s: int,
) -> subprocess.CompletedProcess[str]:
    prompt = f"""Eres el agente implementador encargado de corregir los hallazgos bloqueantes reportados por `it-deploy-auditor`.

REGLAS OBLIGATORIAS:
1. Corrige TODOS los hallazgos en el código fuente y pruebas reales; PROHIBIDO añadir supresiones inline (PHPStan, Psalm, PHPCS, ESLint, Stylelint, Opengrep), saltar tests o envolver fallos en bloques catch vacíos o comandos con masking.
2. Cumple estrictamente `AGENTS.md`, `.agents/rules/catalogo.md` y `.agents/rules/entrega.md`.
3. Tras editar, verifica que tus cambios pasen sintaxis/tests antes de finalizar.

DIAGNÓSTICO DEL AUDITOR A RESOLVER:
{rejection_md}
"""
    if engine == "codex":
        binary = shutil.which("codex")
        if not binary:
            raise FileNotFoundError("No se encontró `codex` para auto-fix.")
        selected_model = model or os.getenv("AKB_AUDITOR_CODEX_MODEL", "gpt-5.6-sol")
        return subprocess.run(
            [
                binary,
                "exec",
                "--ignore-user-config",
                "--ephemeral",
                "-s",
                "workspace-write",
                "-C",
                str(root),
                "-m",
                selected_model,
                "-",
            ],
            input=prompt,
            text=True,
            capture_output=True,
            encoding="utf-8",
            errors="replace",
            timeout=timeout_s,
            check=False,
        )

    binary = shutil.which("claude")
    if not binary:
        raise FileNotFoundError("No se encontró `claude` para auto-fix.")
    cmd = [
        binary,
        "-p",
        "--no-session-persistence",
        "--permission-mode",
        "acceptEdits",
        "--tools",
        "Read,Edit,Write,Grep,Glob,Bash",
        "-",
    ]
    if model:
        cmd.extend(["--model", model])
    return subprocess.run(
        cmd,
        cwd=root,
        input=prompt,
        text=True,
        capture_output=True,
        encoding="utf-8",
        errors="replace",
        timeout=timeout_s,
        check=False,
    )


def run_loop(args: argparse.Namespace) -> int:
    """Ejecuta el bucle acotado auditoría fresca -> corrección -> re-auditoría."""
    if args.max_iterations < 1 or args.max_iterations > 15:
        print("--max-iterations debe estar entre 1 y 15.", file=sys.stderr)
        return 2

    root = args.root.resolve()
    evidence_dir = (args.evidence_dir or (root / ".local-quality/audit-loop")).resolve()
    evidence_dir.mkdir(parents=True, exist_ok=True)
    gate = _load_gate_module()

    for iteration in range(1, args.max_iterations + 1):
        print(f"\n══ Bucle Pre-Despliegue Akibara: Iteración {iteration}/{args.max_iterations} ══")
        receipt_path = evidence_dir / f"iteration-{iteration}-receipt.json"
        rejection_md_path = evidence_dir / f"iteration-{iteration}-rejection.md"
        latest_rejection_md = evidence_dir / "latest-rejection.md"
        latest_approved = evidence_dir / "latest-approved.json"

        verify_error = ""
        if args.verify_cmd:
            print(f"  [1/3] Ejecutando verificación técnica: {args.verify_cmd}")
            verify_res = _invoke_shell(args.verify_cmd, root, dict(os.environ), args.timeout)
            if verify_res.returncode != 0:
                verify_error = (verify_res.stdout + "\n" + verify_res.stderr).strip()

        gate_passed = False
        receipt_payload: dict[str, Any] = {
            "verdict": "RECHAZADO",
            "component": args.component,
            "source_digest": gate.compute_source_digest(root),
            "stage1": {"passed": False, "findings": []},
            "stage2": {"executed": False, "blocking_findings": []},
        }

        if not verify_error:
            print(f"  [2/3] Invocando agente auditor en contexto fresco ({args.engine})...")
            gate_cmd = [
                sys.executable,
                str(GATE_SCRIPT),
                "--root",
                str(root),
                "--component",
                args.component,
                "--base",
                args.base,
                "--engine",
                args.engine,
                "--reasoning",
                args.reasoning,
                "--timeout",
                str(args.timeout),
                "--evidence-out",
                str(receipt_path),
            ]
            if args.model:
                gate_cmd.extend(["--model", args.model])
            if args.allow_fallback_engine:
                gate_cmd.append("--allow-fallback-engine")
            if args.strict_whole_component:
                gate_cmd.append("--strict-whole-component")

            gate_res = subprocess.run(
                gate_cmd,
                text=True,
                capture_output=True,
                encoding="utf-8",
                errors="replace",
                check=False,
            )
            if gate_res.stdout:
                print(gate_res.stdout.rstrip())
            if gate_res.stderr:
                print(gate_res.stderr.rstrip(), file=sys.stderr)

            if receipt_path.is_file():
                try:
                    receipt_payload = json.loads(receipt_path.read_text(encoding="utf-8"))
                except json.JSONDecodeError:
                    pass

            current_digest = gate.compute_source_digest(root)
            verified_ok, verify_msg = gate.verify_receipt(receipt_path, current_digest, root=root)
            gate_passed = gate_res.returncode == 0 and verified_ok
            if not verified_ok and gate_res.returncode == 0:
                print(f"  ✗ Recibo rechazado tras doble chequeo: {verify_msg}", file=sys.stderr)

        if gate_passed:
            latest_approved.write_text(json.dumps(receipt_payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
            print(f"  [3/3] ✓ Veredicto APROBADO en iteración {iteration}. Evidencia: {latest_approved}")
            if args.on_approved_cmd:
                print(f"  Ejecutando promoción/acción autorizada: {args.on_approved_cmd}")
                on_ok = _invoke_shell(args.on_approved_cmd, root, dict(os.environ), args.timeout)
                if on_ok.stdout:
                    print(on_ok.stdout.rstrip())
                if on_ok.stderr:
                    print(on_ok.stderr.rstrip(), file=sys.stderr)
                if on_ok.returncode != 0:
                    print(f"✗ --on-approved-cmd falló con código {on_ok.returncode}.", file=sys.stderr)
                    return on_ok.returncode
            return 0

        md_text = _format_rejection_markdown(iteration, receipt_payload, verify_error=verify_error)
        rejection_md_path.write_text(md_text, encoding="utf-8")
        latest_rejection_md.write_text(md_text, encoding="utf-8")
        (evidence_dir / "latest-rejection.json").write_text(
            json.dumps(receipt_payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
        )

        has_fixer = bool(args.fixer_cmd) or args.auto_fix_engine != "none"
        if not has_fixer:
            print(
                f"\n✗ Auditoría RECHAZADA en iteración {iteration}. Sin fixer automático; "
                f"corrige los hallazgos en {latest_rejection_md} y re-ejecuta.",
                file=sys.stderr,
            )
            return 1

        if iteration >= args.max_iterations:
            print(
                f"\n✗ Bucle abortado tras alcanzar el máximo de {args.max_iterations} iteración(es) sin APROBADO. "
                f"Último diagnóstico: {latest_rejection_md}",
                file=sys.stderr,
            )
            return 1

        print(f"  [3/3] ↺ Devolviendo hallazgos al implementador para iteración {iteration + 1}...")
        fixer_env = dict(os.environ)
        fixer_env.update(
            {
                "AKB_AUDIT_ITERATION": str(iteration),
                "AKB_AUDIT_COMPONENT": args.component,
                "AKB_AUDIT_REJECTION_JSON": str(receipt_path),
                "AKB_AUDIT_REJECTION_MD": str(rejection_md_path),
            }
        )
        if args.fixer_cmd:
            fix_res = _invoke_shell(args.fixer_cmd, root, fixer_env, args.timeout)
        else:
            fix_res = _invoke_auto_fixer(args.auto_fix_engine, root, md_text, args.model, args.timeout)

        if fix_res.returncode != 0:
            print(
                f"✗ El agente/comando de corrección falló (exit={fix_res.returncode}): "
                f"{(fix_res.stderr or fix_res.stdout)[-1000:]}",
                file=sys.stderr,
            )
            return 1

        time.sleep(0.05)

    return 1


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=SCRIPT_DIR.parent)
    parser.add_argument("--component", default="all", choices=("all", "business", "search", "checkout", "rewards", "theme", "mu-plugins"))
    parser.add_argument("--base", default="auto")
    parser.add_argument("--engine", default="auto", choices=("auto", "codex", "claude", "argon", "hydrogen"))
    parser.add_argument("--allow-fallback-engine", action="store_true")
    parser.add_argument("--model", default="")
    parser.add_argument("--reasoning", default="high", choices=("low", "medium", "high"))
    parser.add_argument("--max-iterations", type=int, default=5)
    parser.add_argument("--timeout", type=int, default=600)
    parser.add_argument("--verify-cmd", default="")
    parser.add_argument("--fixer-cmd", default="")
    parser.add_argument("--auto-fix-engine", default="none", choices=("none", "codex", "claude"))
    parser.add_argument("--on-approved-cmd", default="")
    parser.add_argument("--evidence-dir", type=Path, default=None)
    parser.add_argument("--strict-whole-component", action="store_true")
    args = parser.parse_args(argv)
    return run_loop(args)


if __name__ == "__main__":
    sys.exit(main())
