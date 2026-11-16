#!/usr/bin/env python3
"""One CSS asset, exact SHA/mtime guards, local-only recovery, atomic rename."""
import argparse
import hashlib
import json
import shlex
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'output/rewards-ux2-20261006'
BACKUP = ROOT / '.local-backups/rewards-history-mobile-20261008'
ASSET = 'wp-content/plugins/akibara-rewards/assets/css/account-rewards.css'
SSH = ['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
       '-o', 'ConnectTimeout=15', 'akibara']


def sha(data):
    return hashlib.sha256(data).hexdigest()


def remote(script, data=None):
    return subprocess.run(SSH + [script], input=data, capture_output=True, check=True)


def guard(expected, mtime):
    lines = []
    for parent in reversed(Path(ASSET).parents):
        if str(parent) == '.':
            continue
        name = shlex.quote(str(parent))
        lines += [f'test -d {name} || exit 1', f'test ! -L {name} || exit 1']
    name = shlex.quote(ASSET)
    lines += [f'test -f {name} || exit 1', f'test ! -L {name} || exit 1',
              f'printf "%s  %s\\n" {shlex.quote(expected)} {name} | sha256sum -c -',
              f'test "$(stat -c %Y {name})" = {int(mtime)} || exit 1',
              f'test "$(stat -c %a {name})" = 644 || exit 1']
    return '\n'.join(lines)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--deploy', action='store_true')
    parser.add_argument('--rollback', action='store_true')
    args = parser.parse_args()
    if args.deploy and args.rollback:
        raise RuntimeError('Choose deploy or rollback')
    manifest = json.loads((OUT / 'history-mobile-manifest.json').read_text())
    if manifest['path'] != ASSET:
        raise RuntimeError('Scope must be this single CSS asset')
    before = (BACKUP / 'account-rewards.before.css').read_bytes()
    candidate = (BACKUP / 'account-rewards.candidate.css').read_bytes()
    if sha(before) != manifest['before'] or sha(candidate) != manifest['candidate']:
        raise RuntimeError('Local recovery/candidate hashes differ')
    gate = json.loads((OUT / 'qa-history-mobile-approved.json').read_text())
    if gate['approved'] is not True or any(gate[k] != manifest[k] for k in
                                         ('path', 'before', 'candidate', 'mtime')):
        raise RuntimeError('Independent CSS approval mismatch')
    if gate['deploy_script_sha256'] != sha(Path(__file__).read_bytes()):
        raise RuntimeError('Independent deployment script approval mismatch')
    local = ROOT / 'public_html' / ASSET
    if not args.rollback and (local.is_symlink() or not local.is_file() or
                             sha(local.read_bytes()) != manifest['candidate'] or
                             int(local.stat().st_mtime) != manifest['mtime']):
        raise RuntimeError('Local CSS candidate changed after approval')
    old_sha = manifest['candidate'] if args.rollback else manifest['before']
    old_mtime = manifest['mtime'] if args.rollback else manifest['before_mtime']
    new_sha = manifest['before'] if args.rollback else manifest['candidate']
    new_mtime = manifest['before_mtime'] if args.rollback else manifest['mtime']
    data = before if args.rollback else candidate
    prefix = 'set -eu\ncd ~/domains/akibara.cl/public_html\n'
    remote(prefix + guard(old_sha, old_mtime))
    if not args.deploy and not args.rollback:
        print(json.dumps({'preflight': True, 'path': ASSET, 'remote_sha': old_sha,
                          'remote_mtime': old_mtime, 'remote_mutated': False}))
        return
    name = shlex.quote(ASSET)
    script = prefix + guard(old_sha, old_mtime) + '\n' + '\n'.join([
        'akb_css_temp=""',
        'trap \'if test -n "$akb_css_temp"; then rm -f -- "$akb_css_temp"; fi\' EXIT',
        'trap \'exit 130\' INT',
        'trap \'exit 143\' TERM',
        'trap \'exit 129\' HUP',
        f'akb_css_temp=$(mktemp {name}.akb-history-oct8.XXXXXX)',
        'cat > "$akb_css_temp"',
        f'printf "%s  %s\\n" {shlex.quote(new_sha)} "$akb_css_temp" | sha256sum -c -',
        'chmod 644 "$akb_css_temp"',
        f'touch -m -d @{int(new_mtime)} "$akb_css_temp"',
        guard(old_sha, old_mtime),
        f'mv -f -- "$akb_css_temp" {name}',
        'akb_css_temp=""',
        guard(new_sha, new_mtime),
        f'for akb_css_left in {name}.akb-history-oct8.*; do',
        'test ! -e "$akb_css_left" && test ! -L "$akb_css_left" || exit 1',
        'done',
    ])
    result = remote(script, data)
    receipt = {'mode': 'rollback' if args.rollback else 'deploy', 'path': ASSET,
               'sha256': new_sha, 'mtime': new_mtime, 'cleanup_verified': True,
               'local_recovery': str(BACKUP), 'remote_output': result.stdout.decode(),
               'deploy_script_sha256': sha(Path(__file__).read_bytes())}
    (OUT / ('history-mobile-' + receipt['mode'] + '-receipt.json')).write_text(
        json.dumps(receipt, indent=2) + '\n')
    print(json.dumps({k: v for k, v in receipt.items() if k != 'remote_output'}))


if __name__ == '__main__':
    main()
