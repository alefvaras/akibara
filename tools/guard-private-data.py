#!/usr/bin/env python3
"""Reject private paths/secrets in a revision or in all commits about to be pushed."""
import argparse
import re
import subprocess
import sys
from quality_source_policy import private_path, SECRET_PATTERN


def git(*args, allow_absent=False):
    result = subprocess.run(['git', *args], capture_output=True)
    if result.returncode and not (allow_absent and result.returncode == 1):
        raise RuntimeError('No se pudo verificar el historial de Git')
    return result.stdout


def peel_tags(revision, problems):
    current = revision
    seen = set()
    while True:
        kind = git('cat-file', '-t', current).strip()
        if kind == b'commit': return current
        if kind != b'tag' or current in seen:
            raise RuntimeError('Sólo se publican commits y etiquetas que resuelven a commits')
        seen.add(current)
        metadata = git('cat-file', '-p', current).decode('utf-8', errors='replace')
        if re.search(SECRET_PATTERN, metadata): problems.add('git tag metadata:' + current[:12])
        match = re.match(r'object ([0-9a-f]{40,64})\n', metadata)
        if not match: raise RuntimeError('No se pudo verificar el objeto de una etiqueta')
        current = match[1]


def inspect(revisions):
    problems = set()
    for revision in dict.fromkeys(revisions):
        revision = peel_tags(revision, problems)
        metadata = git('cat-file', '-p', revision).decode('utf-8', errors='replace')
        if re.search(SECRET_PATTERN, metadata):
            problems.add('git metadata:' + revision[:12])
        private = set()
        for raw in git('ls-tree', '-rz', '--name-only', revision).split(b'\0'):
            if raw:
                path = raw.decode('utf-8', errors='strict')
                if private_path(path): private.add(path)
        problems.update(private)
        if private:
            continue  # Already rejected; do not read private blobs to search their contents.
        # -l prints filenames, never secret-bearing lines. Errors fail closed.
        matches = git('grep', '-I', '-l', '-z', '-E', '-e', SECRET_PATTERN, revision, '--', allow_absent=True)
        for raw in matches.split(b'\0'):
            if raw: problems.add(raw.decode('utf-8', errors='strict'))
    if problems:
        print('BLOQUEADO: el historial propuesto incluye datos privados o secretos.', file=sys.stderr)
        for path in sorted(problems)[:12]: print('  ' + path, file=sys.stderr)
        print('Publica una rama de fuentes limpias; no subas respaldos ni el historial local contaminado.', file=sys.stderr)
        return 1
    print(f'PASS: {len(set(revisions))} revisión(es) sin rutas privadas ni firmas de secretos detectadas.')
    return 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--revision')
    parser.add_argument('--pre-push', metavar='REMOTE')
    args = parser.parse_args()
    if bool(args.revision) == bool(args.pre_push): parser.error('Elige --revision o --pre-push')
    revisions = []
    if args.revision:
        revisions = [args.revision]
    else:
        for line in sys.stdin:
            fields = line.split()
            if len(fields) != 4: raise RuntimeError('Entrada pre-push incompleta')
            _, local_oid, _, remote_oid = fields
            if set(local_oid) == {'0'}: continue  # Deleting a reference exports no data.
            # Only the old OID announced by the receiving server is evidence.
            # Local remote-tracking refs can be stale, including deleted branches.
            excluded = ['^' + remote_oid] if set(remote_oid) != {'0'} else []
            revisions.append(local_oid)  # Include annotated-tag metadata as well.
            revisions.extend(git('rev-list', local_oid, *excluded).decode().splitlines())
    return inspect(revisions)


if __name__ == '__main__':
    try: sys.exit(main())
    except (OSError, RuntimeError, UnicodeError) as exc:
        print('NO MEDIDO: ' + str(exc), file=sys.stderr)
        sys.exit(2)
