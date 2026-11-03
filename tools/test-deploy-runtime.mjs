import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtempSync, mkdirSync, copyFileSync, writeFileSync, readFileSync, readdirSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const slug = 'akibara-search';
function environment(run) {
  const directory = mkdtempSync(path.join(tmpdir(), 'akb-runtime-test-'));
  try {
    const repo = path.join(directory, 'repo');
    const remote = path.join(directory, 'remote');
    const bin = path.join(directory, 'bin');
    for (const child of [repo + '/ops', repo + '/tools/akibara-releases', repo + '/dist', remote + '/wp-content/plugins/' + slug, bin]) mkdirSync(child, { recursive: true });
    for (const filename of ['ops/akibara-deploy-runtime.sh', 'tools/akibara-releases/verify-runtime-package.py', 'tools/quality_source_policy.py']) copyFileSync(path.join(root, filename), path.join(repo, filename));
    writeFileSync(repo + '/ops/akibara-prod-mail-health.sh', '#!/usr/bin/env bash\nexit "${AKB_TEST_MAIL_EXIT:-0}"\n', { mode: 0o755 });
    writeFileSync(remote + '/wp-content/plugins/' + slug + '/akibara-search.php', '<?php\n// Version: 1.0.0\n// original runtime\n');
    writeFileSync(bin + '/ssh', '#!/usr/bin/env bash\nset -e\nshift 3\nif [[ "${AKB_TEST_LOST_RECEIPT:-0}" == 1 && "$1" == *"echo swap-ok"* ]]; then bash -c "$1" >/dev/null; exit 255; fi\nexec bash -c "$1"\n', { mode: 0o755 });
    writeFileSync(bin + '/scp', '#!/usr/bin/env bash\nset -e\n[[ "$1" == -q ]] && shift\nargs=("$@")\ndestination="${args[${#args[@]}-1]}"\ndestination="${destination#*:}"\nfor ((i=0; i<${#args[@]}-1; i++)); do cp "${args[$i]}" "$destination"; done\n', { mode: 0o755 });
    writeFileSync(bin + '/wp', '#!/usr/bin/env bash\nif [[ "$*" == *"access-check"* ]]; then exit "${AKB_TEST_ACCESS_EXIT:-0}"; fi\nif [[ "$*" == *"--field=version"* ]]; then echo "${AKB_TEST_REPORTED_VERSION:-9.9.9}"; fi\n', { mode: 0o755 });
    writeFileSync(bin + '/mv', '#!/usr/bin/env bash\nif [[ "${AKB_TEST_FIRST_MV_EXIT:-0}" == 1 && "$1" == wp-content/plugins/akibara-search && "$2" == *.prev ]]; then exit 1; fi\nexec /bin/mv "$@"\n', { mode: 0o755 });
    writeFileSync(bin + '/stat', '#!/usr/bin/env python3\nimport os,sys\nprint(int(os.stat(sys.argv[-1]).st_mtime))\n', { mode: 0o755 });
    writeFileSync(bin + '/sha256sum', `#!/usr/bin/env python3
import hashlib,pathlib,sys
lines=sys.stdin.read() if len(sys.argv)==2 else pathlib.Path(sys.argv[2]).read_text()
failed=False
for line in lines.splitlines():
 checksum,filename=line.split('  ',1)
 good=pathlib.Path(filename).is_file() and hashlib.sha256(pathlib.Path(filename).read_bytes()).hexdigest()==checksum
 print(filename+(': OK' if good else ': FAILED'))
 failed=failed or not good
sys.exit(1 if failed else 0)
`, { mode: 0o755 });
    const build = spawnSync('python3', ['-c', `import pathlib,hashlib,zipfile,sys
out=pathlib.Path(sys.argv[1]);slug='akibara-search';name=slug+'-9.9.9.zip'
files={slug+'/akibara-search.php':b"<?php\\n/**\\n * Version: 9.9.9\\n */\\n",slug+'/src/new.php':b'<?php // new runtime\\n'}
with zipfile.ZipFile(out/name,'w') as archive:
 for filename,data in files.items(): archive.writestr(filename,data)
(out/(slug+'-9.9.9.files.sha256')).write_text(''.join(hashlib.sha256(data).hexdigest()+'  '+filename+'\\n' for filename,data in files.items()))
(out/(name+'.sha256')).write_text(hashlib.sha256((out/name).read_bytes()).hexdigest()+'  '+name+'\\n')`, repo + '/dist'], { encoding: 'utf8' });
    assert.equal(build.status, 0, build.stderr);
    const invoke = (args = [], env = {}) => spawnSync('bash', [repo + '/ops/akibara-deploy-runtime.sh', ...args, 'search', '9.9.9'], {
      cwd: repo, encoding: 'utf8', env: { ...process.env, PATH: bin + ':' + process.env.PATH, AKB_SSH_HOST: 'fake', AKB_WEBROOT: remote, ...env }
    });
    return run({ repo, remote, invoke });
  } finally { rmSync(directory, { recursive: true, force: true }); }
}
function cleanRemote(remote) {
  assert.equal(existsSync(remote + '/wp-content/.akb-runtime-deploy.lock'), false, 'lock removed');
  assert.equal(existsSync(remote + '/.maintenance'), false, 'maintenance removed');
  const stage = remote + '/wp-content/.akb-deploy-stage';
  assert.deepEqual(existsSync(stage) ? readdirSync(stage) : [], [], 'no staged, previous or failed runtime remains');
}
test('dry run verifies the sealed package without creating remote or backup state', () => environment(({ repo, remote, invoke }) => {
  const result = invoke(['--dry-run']);
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.equal(existsSync(repo + '/.local-backups'), false);
  cleanRemote(remote);
}));
test('a corrupt local package is rejected before contacting the remote host', () => environment(({ repo, remote, invoke }) => {
  writeFileSync(repo + '/dist/akibara-search-9.9.9.zip', 'corrupt');
  const result = invoke();
  assert.equal(result.status, 1);
  assert.match(result.stderr, /SHA-256 local/);
  assert.equal(existsSync(repo + '/.local-backups'), false);
  cleanRemote(remote);
}));
test('successful promotion retains the recovery archive only on the local machine', () => environment(({ repo, remote, invoke }) => {
  const result = invoke();
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.match(readFileSync(remote + '/wp-content/plugins/' + slug + '/src/new.php', 'utf8'), /new runtime/);
  const backups = readdirSync(repo + '/.local-backups/runtime');
  assert.equal(backups.length, 1);
  const listing = spawnSync('tar', ['-tzf', repo + '/.local-backups/runtime/' + backups[0] + '/before.tar.gz'], { encoding: 'utf8' });
  assert.equal(listing.status, 0);
  assert.match(listing.stdout, /akibara-search.php/);
  cleanRemote(remote);
}));
for (const [name, env] of [
  ['the first rename fails after creating maintenance', { AKB_TEST_FIRST_MV_EXIT: '1' }],
  ['provider health fails', { AKB_TEST_ACCESS_EXIT: '1' }],
  ['mail health fails', { AKB_TEST_MAIL_EXIT: '1' }],
  ['WordPress reports the wrong version', { AKB_TEST_REPORTED_VERSION: '1.0.0' }],
  ['the swap receipt is lost after both renames', { AKB_TEST_LOST_RECEIPT: '1' }]
]) test(`rollback and cleanup when ${name}`, () => environment(({ remote, invoke }) => {
  const result = invoke([], env);
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.match(result.stderr, /Restaurando el árbol anterior/, 'failure happened after swap, not in an unrelated preflight');
  assert.match(readFileSync(remote + '/wp-content/plugins/' + slug + '/akibara-search.php', 'utf8'), /original runtime/);
  assert.equal(existsSync(remote + '/wp-content/plugins/' + slug + '/src/new.php'), false);
  cleanRemote(remote);
}));
test('another deployment lock prevents any backup, upload or swap', () => environment(({ repo, remote, invoke }) => {
  mkdirSync(remote + '/wp-content/.akb-runtime-deploy.lock');
  const result = invoke();
  assert.equal(result.status, 1);
  assert.match(result.stderr, /otro despliegue/);
  assert.equal(existsSync(repo + '/.local-backups'), false);
  assert.equal(existsSync(remote + '/wp-content/.akb-runtime-deploy.lock'), true, 'foreign lock preserved');
}));
test('preexisting maintenance from another operation is preserved', () => environment(({ remote, invoke }) => {
  writeFileSync(remote + '/.maintenance', 'foreign maintenance');
  const result = invoke();
  assert.equal(result.status, 1);
  assert.equal(readFileSync(remote + '/.maintenance', 'utf8'), 'foreign maintenance');
  assert.match(readFileSync(remote + '/wp-content/plugins/' + slug + '/akibara-search.php', 'utf8'), /original runtime/);
  assert.equal(existsSync(remote + '/wp-content/.akb-runtime-deploy.lock'), false);
}));
for (const [kind, expected] of [
  ['extra', /no coinciden exactamente/],
  ['traversal', /Ruta fuera del componente/],
  ['symlink', /Entrada especial prohibida/]
]) test(`a correctly checksummed ZIP still rejects ${kind} content`, () => environment(({ repo, remote, invoke }) => {
  const mutate = spawnSync('python3', ['-c', `import pathlib,zipfile,hashlib,stat,sys
out=pathlib.Path(sys.argv[1]);kind=sys.argv[2];archive=out/'akibara-search-9.9.9.zip'
with zipfile.ZipFile(archive,'a') as z:
 if kind=='extra': z.writestr('akibara-search/uninventoried.php','<?php')
 elif kind=='traversal': z.writestr('akibara-search/../outside.php','<?php')
 else:
  entry=zipfile.ZipInfo('akibara-search/link.php');entry.create_system=3;entry.external_attr=(stat.S_IFLNK|0o777)<<16
  z.writestr(entry,'/etc/passwd')
(out/(archive.name+'.sha256')).write_text(hashlib.sha256(archive.read_bytes()).hexdigest()+'  '+archive.name+'\\n')`, repo + '/dist', kind], { encoding: 'utf8' });
  assert.equal(mutate.status, 0, mutate.stderr);
  const result = invoke(['--dry-run']);
  assert.equal(result.status, 1);
  assert.match(result.stderr, expected);
  assert.equal(existsSync(repo + '/.local-backups'), false);
  cleanRemote(remote);
}));
