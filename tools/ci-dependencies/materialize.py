#!/usr/bin/env python3
"""Materialize locked vendor test inputs. Never install/update a running WordPress site."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import tempfile
import urllib.parse
import urllib.request
import zipfile

HERE = Path(__file__).resolve().parent
DESTINATIONS = {
    'wordpress': 'public_html/wp-includes',
    'wordpress-admin': 'public_html/wp-admin',
    'woocommerce': 'public_html/wp-content/plugins/woocommerce',
    'woocommerce-mercadopago': 'public_html/wp-content/plugins/woocommerce-mercadopago',
    'bluex-for-woocommerce': 'public_html/wp-content/plugins/bluex-for-woocommerce',
}
OFFICIAL_HOSTS = {'wordpress.org', 'downloads.wordpress.org'}
MAX_UNPACKED = 512 * 1024 * 1024


def digest(data):
    return hashlib.sha256(data).hexdigest()


def safe_path(value):
    path = PurePosixPath(value)
    if (not value or value.startswith('/') or '\\' in value or '\x00' in value
            or any(part in ('', '.', '..') for part in value.split('/'))):
        raise ValueError('Unsafe relative dependency path')
    return path


def official_url(value):
    parsed = urllib.parse.urlsplit(value)
    if (parsed.scheme != 'https' or parsed.hostname not in OFFICIAL_HOSTS
            or parsed.username or parsed.password or parsed.port not in (None, 443)
            or parsed.query or parsed.fragment):
        raise ValueError('Dependency origin must be an explicit official HTTPS URL')
    return value


class OfficialRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        official_url(newurl)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def archive_for(entry, cache, offline=False):
    expected = entry['archive_sha256']
    if not re.fullmatch('[0-9a-f]{64}', expected):
        raise ValueError('Invalid archive SHA-256')
    official_url(entry['origin'])
    size = entry['archive_bytes']
    if not isinstance(size, int) or not 0 < size <= MAX_UNPACKED:
        raise ValueError('Invalid archive byte bound')
    cached = cache / (expected + '.zip')
    if cached.is_symlink():
        raise ValueError('Dependency cache cannot contain symlinks')
    if cached.exists():
        if cached.stat().st_size != size or digest(cached.read_bytes()) != expected:
            raise ValueError('Cached dependency digest/size mismatch; remove it explicitly')
        return cached
    if offline:
        raise ValueError('Locked archive unavailable in offline cache: ' + entry['name'])
    cache.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(dir=cache, suffix='.partial', delete=False) as stream:
        temporary = Path(stream.name)
        try:
            opener = urllib.request.build_opener(OfficialRedirect())
            with opener.open(entry['origin'], timeout=45) as response:
                official_url(response.url)
                count = 0
                while chunk := response.read(1024 * 1024):
                    count += len(chunk)
                    if count > size:
                        raise ValueError('Dependency exceeds its locked archive size')
                    stream.write(chunk)
            stream.flush()
            if count != size or digest(temporary.read_bytes()) != expected:
                raise ValueError('Downloaded dependency digest/size mismatch')
            temporary.replace(cached)
        finally:
            temporary.unlink(missing_ok=True)
    return cached


def tree_manifest(directory):
    result = {}
    for path in sorted(directory.rglob('*')):
        if path.is_symlink():
            raise ValueError('Dependency tree contains a symlink')
        if path.is_file():
            result[path.relative_to(directory).as_posix()] = digest(path.read_bytes())
        elif not path.is_dir():
            raise ValueError('Dependency tree contains a special file')
    return result


def unpack(entry, archive_path, destination):
    prefix = str(safe_path(entry['archive_prefix'])) + '/'
    seen = set()
    total = 0
    count = 0
    with zipfile.ZipFile(archive_path) as archive:
        for info in archive.infolist():
            name = info.filename.rstrip('/') if info.is_dir() else info.filename
            safe_path(name)
            if name in seen:
                raise ValueError('Duplicate archive member')
            seen.add(name)
            mode = info.external_attr >> 16
            if stat.S_IFMT(mode) not in (0, stat.S_IFREG, stat.S_IFDIR):
                raise ValueError('Special archive entry is prohibited')
            total += info.file_size
            if total > MAX_UNPACKED:
                raise ValueError('Dependency exceeds uncompressed size limit')
            if info.is_dir() or not name.startswith(prefix):
                continue
            relative = str(safe_path(name[len(prefix):]))
            target = destination / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(archive.read(info))
            target.chmod(0o644)
            count += 1
    if not count:
        raise ValueError('Archive does not contain the locked source prefix')


def load_lock(path):
    lock = json.loads(path.read_text())
    if lock.get('schema') != 1 or lock.get('purpose') != 'vendor-contract-test-inputs':
        raise ValueError('Unsupported dependency lock schema/purpose')
    entries = lock['dependencies']
    if len(entries) != len(DESTINATIONS) or {e['name'] for e in entries} != set(DESTINATIONS):
        raise ValueError('Dependency lock must name each required test input exactly once')
    for entry in entries:
        if entry['destination'] != DESTINATIONS[entry['name']]:
            raise ValueError('Destination differs from the test-input allowlist')
        if not re.fullmatch(r'\d+\.\d+\.\d+', entry['version']):
            raise ValueError('Invalid pinned version')
        if entry['kind'] not in {'official-archive', 'reviewed-source-snapshot'}:
            raise ValueError('Unsupported dependency kind')
        official_url(entry['origin'])
        for filename, value in entry['files'].items():
            safe_path(filename)
            if not re.fullmatch('[0-9a-f]{64}', value):
                raise ValueError('Invalid file SHA-256')
        if not entry['files']:
            raise ValueError('Dependency file manifest is empty')
    return entries


def reject_symlink_ancestors(path, root):
    for parent in [path, *path.parents]:
        if parent == root:
            break
        if parent.is_symlink():
            raise ValueError('Dependency destination traverses a symlink')


def materialize(root, lock_path, cache, offline=False, verify_only=False):
    root = root.resolve()
    # This command is a CI fixture tool. Refuse the conventional live-site marker.
    if (root / 'public_html/wp-config.php').exists():
        raise ValueError('Refusing a configured WordPress tree; use a clean source checkout')
    entries = load_lock(lock_path)
    root.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='akb-vendor-inputs-', dir=root) as temporary:
        pending = []
        for entry in entries:
            target = root / entry['destination']
            reject_symlink_ancestors(target, root)
            if target.exists():
                if not target.is_dir() or tree_manifest(target) != entry['files']:
                    raise ValueError('Existing vendor input differs; refusing overwrite: ' + entry['name'])
                continue
            if verify_only:
                raise ValueError('Missing vendor test input: ' + entry['name'])
            staged = Path(temporary) / entry['name']
            staged.mkdir()
            if entry['kind'] == 'official-archive':
                unpack(entry, archive_for(entry, cache, offline), staged)
            else:
                source = lock_path.parent / str(safe_path(entry['snapshot']))
                reject_symlink_ancestors(source, lock_path.parent)
                if tree_manifest(source) != entry['files']:
                    raise ValueError('Reviewed snapshot digest/tree mismatch')
                shutil.copytree(source, staged, dirs_exist_ok=True)
            if tree_manifest(staged) != entry['files']:
                raise ValueError('Materialized dependency differs from the locked file manifest')
            pending.append((staged, target))
        # All input hashes must pass before any destination appears. Existing trees
        # are never changed. A filesystem failure may leave verified inputs only.
        for staged, target in pending:
            reject_symlink_ancestors(target, root)
            if target.exists():
                raise ValueError('Destination appeared during materialization; aborting')
            target.parent.mkdir(parents=True, exist_ok=True)
            staged.rename(target)
    return [entry['name'] + '@' + entry['version'] for entry in entries]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=HERE.parents[1])
    parser.add_argument('--lock', type=Path, default=HERE / 'dependencies.lock.json')
    parser.add_argument('--cache-dir', type=Path, default=Path(tempfile.gettempdir()) / 'akibara-ci-dependencies')
    parser.add_argument('--offline', action='store_true')
    parser.add_argument('--verify-only', action='store_true')
    args = parser.parse_args()
    try:
        names = materialize(args.root, args.lock.resolve(), args.cache_dir.resolve(), args.offline, args.verify_only)
        print('Verified vendor test inputs: ' + ', '.join(names))
    except (ValueError, OSError, KeyError, TypeError, zipfile.BadZipFile) as error:
        parser.exit(1, 'Dependency materialization rejected: ' + str(error) + '\n')


if __name__ == '__main__':
    main()
