#!/usr/bin/env python3
"""Verify a sealed runtime archive without extracting or executing its content."""
import hashlib
import pathlib
import re
import stat
import sys
import zipfile

# Resolve from the installed source tree, independently of the caller cwd.
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
from quality_source_policy import runtime_artifact_path


def verify(archive_path, inventory_path, checksum_path, slug, version):
    archive_path = pathlib.Path(archive_path)
    checksum = pathlib.Path(checksum_path).read_text().strip()
    match = re.fullmatch(r'([0-9a-f]{64}) [ *](.+)', checksum)
    if not match or match[2] != archive_path.name:
        raise ValueError('Sidecar SHA-256 inválido o no corresponde al ZIP.')
    if hashlib.sha256(archive_path.read_bytes()).hexdigest() != match[1]:
        raise ValueError('El SHA-256 local del ZIP no coincide con su sidecar.')
    inventory = {}
    for line in pathlib.Path(inventory_path).read_text().splitlines():
        entry = re.fullmatch(r'([0-9a-f]{64}) [ *](.+)', line)
        if not entry or entry[2] in inventory:
            raise ValueError('Inventario inválido o con rutas duplicadas.')
        inventory[entry[2]] = entry[1]
    if not inventory:
        raise ValueError('El inventario está vacío.')
    with zipfile.ZipFile(archive_path) as archive:
        actual = {}
        for entry in archive.infolist():
            name = entry.filename
            parts = pathlib.PurePosixPath(name).parts
            if not parts or parts[0] != slug or '..' in parts or '\\' in name or name.startswith('/'):
                raise ValueError(f'Ruta fuera del componente: {name}')
            if runtime_artifact_path(name.rstrip('/') + ('/artifact' if entry.is_dir() else '')):
                raise ValueError(f'Artefacto privado o ajeno al runtime: {name}')
            mode = entry.external_attr >> 16
            if stat.S_ISLNK(mode) or (stat.S_IFMT(mode) not in (0, stat.S_IFREG, stat.S_IFDIR)):
                raise ValueError(f'Entrada especial prohibida: {name}')
            if entry.is_dir():
                continue
            if name in actual:
                raise ValueError(f'Archivo duplicado en el ZIP: {name}')
            actual[name] = hashlib.sha256(archive.read(entry)).hexdigest()
        if actual != inventory:
            raise ValueError('El árbol o los hashes del ZIP no coinciden exactamente con el inventario.')
        header = 'style.css' if slug == 'akibara-gp' else slug + '.php'
        text = archive.read(slug + '/' + header).decode('utf-8')
        match = re.search(r'^\s*(?:\*\s*)?Version:\s*(\S+)', text, re.MULTILINE)
        if not match or match[1] != version:
            raise ValueError('La versión dentro del ZIP no coincide con la versión solicitada.')
    print(f'Paquete local verificado: {slug} {version}, {len(actual)} archivos.')


if __name__ == '__main__':
    try:
        if len(sys.argv) != 6:
            raise ValueError('Uso: verify-runtime-package.py ZIP INVENTARIO SHA256 SLUG VERSION')
        verify(*sys.argv[1:])
    except (ValueError, OSError, KeyError, zipfile.BadZipFile) as error:
        print(f'Paquete rechazado: {error}', file=sys.stderr)
        sys.exit(1)
