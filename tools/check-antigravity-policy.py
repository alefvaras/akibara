#!/usr/bin/env python3
"""Validate the project's native Antigravity policy files and documented byte limit."""
import argparse
from pathlib import Path
import re
import sys

RULES = ('.agents/rules/catalogo.md', '.agents/rules/entrega.md')
MAX_BYTES = 24000


def check(root):
    issues = []
    contents = {}
    for name in ('AGENTS.md', *RULES):
        path = root / name
        if not path.is_file() or path.is_symlink():
            issues.append('Falta una política regular: ' + name)
            continue
        data = path.read_bytes()
        if len(data) > MAX_BYTES: issues.append('Antigravity truncaría la política: ' + name)
        contents[name] = data.decode('utf-8')
    for name in RULES:
        text = contents.get(name, '')
        frontmatter = re.match(r'\A---\n(.*?)\n---\n', text, re.S)
        if not frontmatter or not re.search(r'^trigger:\s*always_on\s*$', frontmatter[1], re.M):
            issues.append('La política debe estar activa siempre: ' + name)
        if name not in contents.get('AGENTS.md', ''):
            issues.append('AGENTS.md debe remitir a la política compartida: ' + name)
    return issues


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    try:
        issues = check(parser.parse_args().root)
        if issues:
            print('\n'.join(issues), file=sys.stderr)
            sys.exit(1)
        print('PASS: AGENTS.md y dos reglas nativas always_on, cada archivo dentro de 24.000 bytes.')
    except (OSError, UnicodeError) as error:
        parser.exit(1, 'Políticas no verificadas: ' + str(error) + '\n')
