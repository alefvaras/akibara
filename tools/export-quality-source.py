#!/usr/bin/env python3
"""Export an explicit source allowlist, never the mirror database or its history."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
from quality_source_policy import private_path

ROOT = Path(__file__).resolve().parents[1]
DIRECTORIES = [
    'public_html/wp-content/plugins/' + name for name in
    ('akibara-business', 'akibara-search', 'akibara-rewards', 'akibara-cart-checkout-blocks')
] + ['public_html/wp-content/themes/akibara-gp', 'tools/ci-dependencies', 'docs/arquitectura']
FILES = [
    'package.json', 'package-lock.json', 'tools/build-runtime-assets.sh',
    'AGENTS.md', 'compose.yaml', 'ops/php-cli.ini', 'public_html/php.ini',
    '.agents/rules/catalogo.md', '.agents/rules/entrega.md',
    'tools/check-antigravity-policy.py', 'tools/test-antigravity-policy.py',
    'docs/REGLAS_DE_NEGOCIO_DINERO_TRANSACCIONAL.md',
    'tools/akibara-releases/build-search-assets.mjs', 'tools/test-source-export.py', 'tools/test-build-search-assets.mjs',
    '.gitignore', '.githooks/pre-push', '.github/workflows/runtime-quality.yml',
    'tools/guardrail-ci.sh', 'tools/guardrail-ast-hygiene.php', 'tools/quality_source_policy.py',
    'tools/guard-private-data.py', 'tools/export-quality-source.py', 'tools/install-quality-hooks.sh',
    'tools/test-private-data-guard.py', 'tools/test-runtime-delivery.mjs', 'tools/test-deploy-runtime.mjs',
    'tools/test-post-hygiene-verdict.mjs', 'tools/qa-post-hygiene-complete-verification.mjs',
    'tools/test-mail-health.mjs',
    'tools/akibara-releases/package-runtime.sh', 'tools/akibara-releases/verify-runtime-package.py',
    'tools/akibara-releases/build-theme-core-css.sh', 'tools/akibara-releases/build-theme-min-assets.mjs',
    'tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs', 'tools/akibara-releases/rewards-qa/test-qa-paridad-espejo.mjs',
    'ops/akibara-deploy-runtime.sh', 'ops/akibara-prod-mail-health.sh', 'ops/akibara-prod-muplugins-deploy.sh',
    'ops/guardrail-inventario-akibara.py', 'ops/test-guardrail-inventario-akibara.py',
    'ops/lib/akibara-mail-probe.php', 'tools/test-runtime-artifact-boundary.py',
    'docs/entregas/runtime-quality.md',
]
for name in ('akibara-business', 'akibara-search', 'akibara-rewards', 'akibara-gp'):
    FILES.append(f'tools/{name}/phpunit.xml.dist')
FILES += ['tools/akibara-business/' + name for name in ('composer.json', 'composer.lock', 'phpcs.xml.dist', 'patchwork.json')]


def checked_source(source):
    source.relative_to(ROOT)
    for part in [source, *source.parents]:
        if part == ROOT: break
        if part.is_symlink(): raise RuntimeError('No se exportan enlaces simbólicos ni sus descendientes')
    if not source.resolve(strict=True).is_relative_to(ROOT):
        raise RuntimeError('La fuente resuelta está fuera del proyecto')
    return source


def export(destination):
    destination = destination.resolve()
    if destination.exists(): raise RuntimeError('El destino debe ser nuevo para no conservar residuos')
    paths = {ROOT / file for file in FILES}
    for directory in DIRECTORIES:
        directory = checked_source(ROOT / directory)
        if not directory.is_dir(): raise RuntimeError('Falta directorio de fuentes requerido')
        paths.update(path for path in directory.rglob('*') if path.is_file())
    paths.update((ROOT / 'public_html/wp-content/mu-plugins').glob('akibara-*.php'))
    paths.update(path for path in (ROOT / 'public_html/wp-content/mu-plugins/assets').rglob('*') if path.is_file())
    # Delivery tests are maintained beside the other release instruments.
    paths.update((ROOT / 'tools').glob('test-mu*-deploy*.mjs'))
    paths.update((ROOT / 'tools').glob('test-deploy-mu*.mjs'))
    selected = []
    for source in sorted(paths):
        relative = source.relative_to(ROOT).as_posix()
        if private_path(relative) or '__pycache__' in source.parts or source.suffix in {'.pyc', '.pyo'} or source.name == '.DS_Store' or source.name.startswith('._'): continue
        checked_source(source)
        if not source.is_file(): raise RuntimeError('Falta fuente requerida: ' + relative)
        selected.append((source, relative))
    manifest = []
    for source, relative in selected:
        target = destination / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, target)
        manifest.append({'path': relative, 'sha256': hashlib.sha256(target.read_bytes()).hexdigest()})
    readme = destination / 'README.md'
    readme.write_text('# Akibara runtime\n\nFuentes y controles de calidad. No contiene datos de la tienda. Ver [entrega](docs/entregas/runtime-quality.md).\n')
    manifest.append({'path': 'README.md', 'sha256': hashlib.sha256(readme.read_bytes()).hexdigest()})
    print(json.dumps(manifest, ensure_ascii=False))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination', type=Path)
    try: export(parser.parse_args().destination)
    except (OSError, RuntimeError) as exc: parser.exit(1, str(exc) + '\n')
