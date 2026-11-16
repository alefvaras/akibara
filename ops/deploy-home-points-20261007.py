#!/usr/bin/env python3
"""Seven-file, manifest-pinned promotion. Default mode only reads production."""
import argparse
import hashlib
import io
import json
import shlex
import subprocess
import tarfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BACKUP = ROOT / '.local-backups/home-points-20261007'
OUT = ROOT / 'output/home-css-20261006'
SSH = ['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
       '-o', 'ConnectTimeout=15', 'akibara']
WEBROOT = '~/domains/akibara.cl/public_html'
HOME_MANIFEST = ROOT / 'output/home-css-20261006/canonical-markup-candidate-allowlist.json'
REWARDS_MANIFEST = ROOT / 'output/rewards-ux2-20261006/candidate-manifest.json'
REWARDS_PREFIX = 'wp-content/plugins/akibara-rewards/'
EXPECTED = {
    'wp-content/themes/akibara-gp/inc/trust-badges.php',
    'wp-content/plugins/akibara-business/src/Modules/HomeHero/Renderer.php',
    'wp-content/plugins/akibara-business/src/Modules/NativeCart/NativeCart.php',
    REWARDS_PREFIX + 'src/Points/Expiry.php',
    REWARDS_PREFIX + 'src/Points/AccountRewards.php',
    REWARDS_PREFIX + 'assets/js/account-rewards.js',
    REWARDS_PREFIX + 'assets/css/account-rewards.css',
}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def exclusive(path, data):
    """Never replace an existing recovery point or candidate package."""
    if path.exists():
        if path.read_bytes() != data:
            raise RuntimeError(f'Existing artifact differs; refusing overwrite: {path}')
        return
    with path.open('xb') as stream:
        stream.write(data)


def ssh(script, data=None, check=True):
    return subprocess.run(SSH + [script], input=data, capture_output=True, check=check)


def scope():
    rows = []
    for entry in json.loads(HOME_MANIFEST.read_text())['files']:
        rows.append({'path': entry['path'], 'before': entry['production_before_sha256'],
                     'candidate': entry['candidate_sha256'], 'asset': False})
    for path, entry in json.loads(REWARDS_MANIFEST.read_text()).items():
        if entry['runtime'] is True:
            rows.append({'path': REWARDS_PREFIX + path, 'before': entry['before_production'],
                         'candidate': entry['candidate'], 'asset': path.startswith('assets/'),
                         'mtime': entry['mtime']})
    if len(rows) != 7 or {row['path'] for row in rows} != EXPECTED:
        raise RuntimeError('Manifests do not describe exactly the approved seven files')
    for row in rows:
        local = ROOT / 'public_html' / row['path']
        if not local.is_file() or local.is_symlink() or digest(local.read_bytes()) != row['candidate']:
            raise RuntimeError(f'Local candidate SHA/type mismatch: {row["path"]}')
        if row['asset']:
            if row['before'] is not None or int(local.stat().st_mtime) != row['mtime']:
                raise RuntimeError(f'New asset baseline/mtime mismatch: {row["path"]}')
        elif not row['path'].endswith('.php') or not row['before']:
            raise RuntimeError('Every existing runtime file must be a pinned PHP source')
    return rows


def remote_guard(rows, candidate=False):
    lines = []
    for row in rows:
        name = shlex.quote(row['path'])
        expected = row['candidate'] if candidate else row['before']
        if expected is None:
            lines.append(f'test ! -e {name} && test ! -L {name}')
        else:
            lines.extend([f'test -f {name} && test ! -L {name}',
                          f'printf "%s  %s\\n" {shlex.quote(expected)} {name} | sha256sum -c -'])
    return '\n'.join(lines)


def archive_members(data):
    with tarfile.open(fileobj=io.BytesIO(data), mode='r:gz') as archive:
        result = {}
        for member in archive.getmembers():
            name = member.name.removeprefix('./')
            if name not in EXPECTED or not member.isfile() or name in result:
                raise RuntimeError('Unsafe or duplicate archive member')
            result[name] = {'sha256': digest(archive.extractfile(member).read()),
                            'mode': member.mode, 'mtime': member.mtime, 'size': member.size}
        return result


def verify_archive(data, rows, field):
    actual = archive_members(data)
    expected = {row['path']: row[field] for row in rows if row[field] is not None}
    if set(actual) != set(expected) or any(actual[path]['sha256'] != sha for path, sha in expected.items()):
        raise RuntimeError(f'Archive SHA/membership mismatch for {field}')
    return actual


def candidate_archive(rows):
    # Stable gzip/tar bytes let subsequent preflights verify without overwriting.
    import gzip
    raw = io.BytesIO()
    with tarfile.open(fileobj=raw, mode='w', format=tarfile.PAX_FORMAT) as archive:
        for row in sorted(rows, key=lambda item: item['path']):
            local = ROOT / 'public_html' / row['path']
            body = local.read_bytes()
            info = tarfile.TarInfo(row['path'])
            info.size = len(body)
            info.mode = 0o644
            info.mtime = row.get('mtime', int(local.stat().st_mtime))
            archive.addfile(info, io.BytesIO(body))
    return gzip.compress(raw.getvalue(), mtime=0)


def preflight(rows):
    BACKUP.mkdir(parents=True, exist_ok=True)
    BACKUP.chmod(0o700)
    guard = 'set -eu\ncd ' + WEBROOT + '\n' + remote_guard(rows)
    checked = ssh(guard)
    existing = [row for row in rows if row['before'] is not None]
    names = ' '.join(shlex.quote(row['path']) for row in existing)
    archive_path = BACKUP / 'runtime-before.tar.gz'
    if archive_path.exists():
        recovery = archive_path.read_bytes()
    else:
        recovery = ssh(f'set -eu\ncd {WEBROOT}\ntar -czf - -- {names}').stdout
        verify_archive(recovery, rows, 'before')
        exclusive(archive_path, recovery)
    members = verify_archive(recovery, rows, 'before')
    ssh(guard)  # Detect concurrent change across the streamed backup.
    package = candidate_archive(rows)
    verify_archive(package, rows, 'candidate')
    exclusive(BACKUP / 'runtime-candidate.tar.gz', package)
    recovery_record = {'files': members, 'archive_sha256': digest(recovery)}
    exclusive(BACKUP / 'runtime-before-manifest.json',
              (json.dumps(recovery_record, indent=2, sort_keys=True) + '\n').encode())
    linted = []
    for row in existing:
        path = '/var/www/html/' + row['path']
        subprocess.run(['docker', 'compose', 'exec', '-T', 'wordpress', 'php', '-l', path],
                       cwd=ROOT, capture_output=True, check=True)
        linted.append(row['path'])
    subprocess.run(['node', '--check', str(ROOT / 'public_html' /
                    (REWARDS_PREFIX + 'assets/js/account-rewards.js'))],
                   cwd=ROOT, capture_output=True, check=True)
    report = {'mode': 'read-only preflight', 'deployment_authorized': False,
              'existing_sha_matched': len(existing), 'new_assets_absent': 2,
              'candidate_sha_matched': len(rows), 'php_lint_passed': linted,
              'javascript_syntax_passed': True, 'recovery_file_count': len(members),
              'recovery_archive_sha256': digest(recovery), 'candidate_archive_sha256': digest(package),
              'files': rows, 'remote_artifacts_created': False,
              'existing_options_and_editorial_backups_preserved': True}
    (OUT / 'runtime-seven-preflight.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({key: value for key, value in report.items()
                      if key not in ('files', 'php_lint_passed')}, indent=2))
    return package, recovery, members


def install_order(rows, rollback=False):
    assets = [row for row in rows if row['asset']]
    home = [row for row in rows if not row['path'].startswith(REWARDS_PREFIX)]
    expiry = next(row for row in rows if row['path'] == REWARDS_PREFIX + 'src/Points/Expiry.php')
    account = next(row for row in rows if row['path'] == REWARDS_PREFIX + 'src/Points/AccountRewards.php')
    # Forward: new assets, new helper, then its consumer. Reverse: old consumer first.
    return ([account, expiry] + home) if rollback else (assets + [expiry, account] + home)


def mutation_script(rows, package, rollback=False, members=None):
    order = install_order(rows, rollback)
    temporary = [str(Path(row['path']).with_name('.' + Path(row['path']).name + '.akb-hp20261007-new'))
                 for row in order]
    cleanup_names = ' '.join(shlex.quote(path) for path in temporary)
    script = ['set -eu', f'cd {WEBROOT}', 'akb_stage=$(mktemp -d /tmp/akb-home-points-20261007.XXXXXX)',
              "trap 'rm -f -- " + cleanup_names + '; rm -rf -- "$akb_stage"\' EXIT',
              "trap 'exit 130' INT TERM HUP", 'cat > "$akb_stage/runtime.tar.gz"',
              f'printf "%s  %s\\n" {shlex.quote(digest(package))} "$akb_stage/runtime.tar.gz" | sha256sum -c -',
              'tar -xzf "$akb_stage/runtime.tar.gz" -C "$akb_stage"',
              remote_guard(rows, candidate=rollback)]
    # Verify every staged file and lint PHP before the first productive write.
    for row in rows:
        sha = row['before'] if rollback else row['candidate']
        if sha is None:
            continue
        name = '"$akb_stage"/' + shlex.quote(row['path'])
        script.append(f'printf "%s  %s\\n" {shlex.quote(sha)} {name} | sha256sum -c -')
        if row['path'].endswith('.php'):
            script.append(f'php -l {name}')
    script.append('printf "AKB_MUTATION_STARTED\\n"')
    if rollback:
        script.append('sleep 2')  # Ensure a distinct PHP mtime for OPcache on immediate rollback.
    for row, temporary_path in zip(order, temporary):
        name = shlex.quote(row['path'])
        temp = shlex.quote(temporary_path)
        stage = '"$akb_stage"/' + name
        mode = format(members[row['path']]['mode'], 'o') if rollback else '644'
        script.extend([f'test ! -e {temp} && test ! -L {temp}',
                       f'cp -- {stage} {temp}', f'chmod {mode} {temp}'])
        if row['asset']:
            script.append(f'touch -d @{row["mtime"]} {temp}')
        else:
            script.append(f'touch {temp}')
        script.extend([f'mv -f -- {temp} {name}',
                       f'printf "%s  %s\\n" {shlex.quote(row["before"] if rollback else row["candidate"])} {name} | sha256sum -c -'])
        if row['asset']:
            script.append(f'test "$(stat -c %Y {name})" = {row["mtime"]}')
    if rollback:
        for row in rows:
            if row['asset']:
                script.append(f'rm -- {shlex.quote(row["path"])}')
    script.extend([remote_guard(rows, candidate=not rollback),
                   'wp cache flush',
                   "wp eval '\\LiteSpeed\\Purge::purge_all_lscache();'",
                   'rm -f -- ' + cleanup_names, 'rm -rf -- "$akb_stage"',
                   'test ! -e "$akb_stage"', 'trap - EXIT INT TERM HUP',
                   'printf "AKB_RUNTIME_COMPLETE\\n"'])
    return '\n'.join(script) + '\n'


def mutate(rows, package, recovery, members, rollback=False):
    blob = recovery if rollback else package
    completed = ssh(mutation_script(rows, blob, rollback, members), blob, check=False)
    log = completed.stdout.decode(errors='replace') + completed.stderr.decode(errors='replace')
    mode = 'rollback' if rollback else 'deploy'
    (OUT / f'runtime-seven-{mode}.log').write_text(log)
    if completed.returncode:
        if not rollback and 'AKB_MUTATION_STARTED' in log:
            restored = ssh(mutation_script(rows, recovery, True, members), recovery, check=False)
            rollback_log = restored.stdout.decode(errors='replace') + restored.stderr.decode(errors='replace')
            (OUT / 'runtime-seven-automatic-rollback.log').write_text(rollback_log)
            if restored.returncode:
                raise RuntimeError('Deployment failed and automatic rollback failed; inspect both logs immediately')
            raise RuntimeError('Deployment failed; local recovery archive restored all five PHP and removed both new assets')
        raise RuntimeError(f'{mode} failed before completion; inspect runtime-seven-{mode}.log')
    if 'AKB_RUNTIME_COMPLETE' not in log:
        raise RuntimeError('Missing remote completion marker')
    print(f'{mode}: seven-file SHA gate passed; remote temporary artifacts removed; HTML LSCache only purged')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument('--preflight', action='store_true', help='Read production, keep recovery only locally (default)')
    modes.add_argument('--deploy', action='store_true', help='Explicitly authorized promotion; writes exactly seven files')
    modes.add_argument('--rollback', action='store_true', help='Restore local five-file recovery and remove two new assets')
    args = parser.parse_args()
    rows = scope()
    if args.rollback:
        recovery = (BACKUP / 'runtime-before.tar.gz').read_bytes()
        members = verify_archive(recovery, rows, 'before')
        package = (BACKUP / 'runtime-candidate.tar.gz').read_bytes()
        verify_archive(package, rows, 'candidate')
        mutate(rows, package, recovery, members, rollback=True)
    else:
        package, recovery, members = preflight(rows)
        if args.deploy:
            mutate(rows, package, recovery, members)


if __name__ == '__main__':
    main()
