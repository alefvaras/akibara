#!/usr/bin/env python3
"""Candado criptográfico e invariantes semánticos de la configuración y pipeline DevSecOps de Akibara.

Impide que cualquier agente rebaje niveles, desactive reglas, cree archivos baseline,
inyecte salidas tempranas en los bootstraps o modifique los scripts de CI, empaquetado,
despliegue, hooks de Git o QA para saltarse controles.
"""
import argparse
import hashlib
from pathlib import Path
import re
import sys

SEALED_HASHES = {
    "phpstan.neon.dist": "fc9fbea82720e84c228f1acd161e147b160dee98a0187fdbf42a66e44a1bc804",
    "phpstan-bootstrap.php": "e18938fef6a9197914faf94c591b2af2043435fc1d1b5148d184af2b75203ff1",
    "phpstan-dba-bootstrap.php": "effb37cfc4509c5eb8e9921d27b3e85ff20c0e64df715fe5169e42265420a8df",
    "psalm.xml": "89ca2cc918ae3887a9645abcffd0756d2c0ae47877f568762df4db6854de8c89",
    "tools/psalm/woocommerce/hooks.json": "c6ca9ffa7f3336415c6b0cd08b6d3b198cd4e9889130e8af95c17818b13cac86",
    "phpcs.xml.dist": "9cebbd389045d97a847c1a3e0a3e9bed62dea36d3e9d00e7fd6cb83f2ed9f2b4",
    "tools/akibara-business/phpcs.xml.dist": "af149cc75c666f691d36ff54130bb0432d35179c2c51354a3da5c1380a3bf3c1",
    ".eslintrc.js": "f1bcc96dd1c31803b6070448e0f1e0525e7aae2d626ddcd4240a9e6cfa05c2f2",
    ".eslintignore": "c7ddfcf1a4a7e48563d9d27536e8435f39e1995ef6fe2482d53c5f954a60d8ec",
    ".stylelintrc.json": "2c7a62c37812390828d1ece6e44b871d596e7cf3534e9de3dc7eb80a94919edc",
    ".sqlfluff": "2378576d4e7b5182f8ccede7bafaebc82ec189f6febf3e68333d6d7fe5e88870",
    ".sqlfluffignore": "0ba5467035449b9925a1189215c4dd6353b4f25d71ffdf146b17e9e8de9ed6ff",
    ".semgrep/wordpress-security.yml": "098c62df4a31e776fe8f3b4a30c93b4a77ec179f976a0daba4ee60f1a839207d",
    ".semgrepignore": "019f2f900ccc0231e44733ab8502ed2dce962fcc4575e721e83bf7cae3580a69",
    "rector.php": "1922e4a21f50bc493603f3d9b594c2dbf4eba89f6a39c5ed862fa1941e88fb8b",
    "knip.json": "37ac4f6b8a888affe7392b5173023bd7342a014a7ff20e995916be59da7e2930",
    "purgecss.config.js": "d51443aca33f8d86b82fd0383b6e35aaba8428be458f684e51b77d03cec652cc",
    "package.json": "f6d632631551dfffdb849abd5429e3100f49ad0fba72c98f41d24988e33ec4fa",
    "tools/guardrail-ci.sh": "fa77a0565585ecc8a290550c8e0b6140f2369d3cb73e005b2aa178e394c4f4b9",
    "tools/guardrail-ast-hygiene.php": "ddcef3d4d6759a3a9c499fd10e8f789c71edd0396a4763f296ac28732275cda5",
    "ops/akibara-deploy-runtime.sh": "e7dea4cf0c48f01cc2584b6a2f52884187f6b454f21ae0327bc90c624d94db97",
    "ops/db-cleanup-brevo-welcome-coupon.sql": "c00f0a4269bbfe776ad6efc4504c3e2b96c09467499fbe278399e76738388370",
    "tools/akibara-releases/package-runtime.sh": "47890e6c50481bfb632aa664073a6d0d5204c7e993d7b7123b86b80e66263249",
    "tools/akibara-releases/verify-runtime-package.py": "aa7cdd6053519b93b796915b6f2e3b82cf4480b2b6df3a67f22623ff940481ec",
    "tools/qa-playwright-full-suite.mjs": "36b6c7d9300377558c67e5002254c5a47dd2a9520bb8de6438485ba8b393cb57",
    ".githooks/pre-commit": "8d87517313f7fa201499298f01ef1d99713edabb1542344c83d332e92fd8b016",
    ".githooks/pre-push": "a1426af3d1760a3c9ee0d43ee2e75cd3260c35a7deac73dbbb91951010f8af8d",
}

FORBIDDEN_BASELINE_GLOBS = (
    "*baseline*.neon",
    "*baseline*.xml",
    "*baseline*.json",
    "*baseline*.php",
)


def verify(root: Path) -> list[str]:
    issues: list[str] = []

    for pattern in FORBIDDEN_BASELINE_GLOBS:
        for match in root.glob(pattern):
            issues.append(f"VIOLACIÓN DE CANDADO: archivo baseline prohibido detectado: {match.name}")

    for rel_path, expected_sha in SEALED_HASHES.items():
        path = root / rel_path
        if not path.is_file() or path.is_symlink():
            issues.append(f"VIOLACIÓN DE CANDADO: falta archivo crítico sellado: {rel_path}")
            continue
        actual_sha = hashlib.sha256(path.read_bytes()).hexdigest()
        if actual_sha != expected_sha:
            issues.append(
                f"VIOLACIÓN DE CANDADO: {rel_path} fue modificado sin autorización "
                f"(SHA-256 actual {actual_sha[:12]}… != sellado {expected_sha[:12]}…)."
            )

    stubs_path = root / "phpstan-stubs.php"
    if stubs_path.is_file():
        stubs_text = stubs_path.read_text(encoding="utf-8", errors="replace")
        if re.search(r"\b(exit|die)\s*\(", stubs_text):
            issues.append("VIOLACIÓN DE CANDADO: phpstan-stubs.php contiene exit/die para abortar el análisis.")

    phpstan_path = root / "phpstan.neon.dist"
    if phpstan_path.is_file():
        text = phpstan_path.read_text(encoding="utf-8", errors="replace")
        if not re.search(r"^\s*level:\s*6\s*$", text, re.M):
            issues.append("VIOLACIÓN DE CANDADO: phpstan.neon.dist debe mantener level: 6.")
        if not re.search(r"^\s*deadMethods:\s*true\s*$", text, re.M):
            issues.append("VIOLACIÓN DE CANDADO: phpstan.neon.dist debe mantener deadMethods: true.")
        if not re.search(r"^\s*class:\s*80\s*$", text, re.M) or not re.search(r"^\s*function:\s*25\s*$", text, re.M):
            issues.append("VIOLACIÓN DE CANDADO: phpstan.neon.dist debe mantener cognitive_complexity 80/25.")
        if "baseline" in text.lower():
            issues.append("VIOLACIÓN DE CANDADO: phpstan.neon.dist no puede incluir archivos baseline.")

    psalm_path = root / "psalm.xml"
    if psalm_path.is_file():
        text = psalm_path.read_text(encoding="utf-8", errors="replace")
        if 'errorLevel="4"' not in text:
            issues.append('VIOLACIÓN DE CANDADO: psalm.xml debe mantener errorLevel="4".')
        if 'findUnusedCode="true"' not in text:
            issues.append('VIOLACIÓN DE CANDADO: psalm.xml debe mantener findUnusedCode="true".')
        if "errorBaseline" in text:
            issues.append("VIOLACIÓN DE CANDADO: psalm.xml no puede usar errorBaseline.")

    ci_path = root / "tools/guardrail-ci.sh"
    if ci_path.is_file():
        ci_text = ci_path.read_text(encoding="utf-8", errors="replace")
        for required_cmd in ("verify-devsecops-seal.py", "guardrail-ast-hygiene.php", "$phpstan", "$psalm", "$rector", "npx eslint", "npx stylelint", "npx knip"):
            if required_cmd not in ci_text:
                issues.append(f"VIOLACIÓN DE CANDADO: tools/guardrail-ci.sh omitió la etapa obligatoria {required_cmd}.")

    return issues


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    args = parser.parse_args()
    found = verify(args.root)
    if found:
        print("\n".join(found), file=sys.stderr)
        sys.exit(1)
    print(f"PASS: sello criptográfico DevSecOps íntegro ({len(SEALED_HASHES)} archivos verificados, 0 baselines).")
