import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtempSync, mkdirSync, copyFileSync, writeFileSync, readFileSync, readdirSync, existsSync, rmSync, statSync } from 'node:fs';
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
    const temporary = path.join(directory, 'temporary');
    for (const child of [repo + '/ops', repo + '/tools/akibara-releases/rewards-qa', repo + '/dist', remote + '/wp-content/plugins/' + slug, bin, temporary]) mkdirSync(child, { recursive: true });
    for (const filename of ['ops/akibara-deploy-runtime.sh', 'tools/akibara-releases/package-runtime.sh', 'tools/akibara-releases/verify-runtime-package.py', 'tools/quality_source_policy.py']) copyFileSync(path.join(root, filename), path.join(repo, filename));
    writeFileSync(repo + '/ops/akibara-prod-mail-health.sh', '#!/usr/bin/env bash\nexit "${AKB_TEST_MAIL_EXIT:-0}"\n', { mode: 0o755 });
    writeFileSync(repo + '/tools/verify-devsecops-seal.py', 'import os,sys\nsys.exit(int(os.getenv("AKB_TEST_SEAL_EXIT", "0")))\n');
    writeFileSync(repo + '/tools/guardrail-ci.sh', '#!/usr/bin/env bash\n[[ "$*" == --full ]] || exit 33\necho guardrail-full >> "$AKB_TEST_COMMAND_LOG"\nif [[ "${AKB_TEST_SOURCE_DRIFT:-0}" == 1 ]]; then echo changed >> "$(dirname "$0")/drift.php"; fi\nexit "${AKB_TEST_GUARDRAIL_EXIT:-0}"\n');
    writeFileSync(repo + '/tools/qa-playwright-full-suite.mjs', `import fs from 'node:fs';
fs.appendFileSync(process.env.AKB_TEST_COMMAND_LOG, 'qa ' + process.env.AKB_BASE_URL + '\\n');
process.exit(Number(process.env.AKB_BASE_URL === 'http://localhost:8080' ? process.env.AKB_TEST_LOCAL_QA_EXIT || 0 : process.env.AKB_TEST_PROD_QA_EXIT || 0));
`);
    writeFileSync(repo + '/tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs', 'process.exit(Number(process.env.AKB_TEST_PARITY_EXIT || 0));\n');
    writeFileSync(repo + '/tools/sync-notebooklm.mjs', `import fs from 'node:fs'; fs.appendFileSync(process.env.AKB_TEST_COMMAND_LOG, 'sync\\n'); process.exit(Number(process.env.AKB_TEST_SYNC_EXIT || 0));\n`);
    writeFileSync(remote + '/wp-content/plugins/' + slug + '/akibara-search.php', '<?php\n// Version: 1.0.0\n// original runtime\n');
    writeFileSync(bin + '/ssh', [
      '#!/usr/bin/env bash', 'set -e', 'echo ssh >> "$AKB_TEST_COMMAND_LOG"', 'shift 3',
      'if [[ "${AKB_TEST_ROLLBACK_PURGE_DISCONNECT:-0}" == 1 && "$1" == *"wp litespeed-purge all"* && -e "$AKB_TEST_COMMAND_LOG.purge-count" ]]; then exit 255; fi',
      'if [[ "${AKB_TEST_LOST_RECEIPT:-0}" == 1 && "$1" == *"echo swap-ok"* ]]; then bash -c "$1" >/dev/null; exit 255; fi',
      'exec bash -c "$1"', ''
    ].join('\n'), { mode: 0o755 });
    writeFileSync(bin + '/scp', '#!/usr/bin/env bash\nset -e\n[[ "$1" == -q ]] && shift\nargs=("$@")\ndestination="${args[${#args[@]}-1]}"\ndestination="${destination#*:}"\nfor ((i=0; i<${#args[@]}-1; i++)); do cp "${args[$i]}" "$destination"; done\n', { mode: 0o755 });
    writeFileSync(bin + '/docker', '#!/usr/bin/env bash\ncat >/dev/null\nexit "${AKB_TEST_MIRROR_ARTIFACT_EXIT:-0}"\n', { mode: 0o755 });
    writeFileSync(bin + '/wp', [
      '#!/usr/bin/env bash', 'echo "wp $*" >> "$AKB_TEST_COMMAND_LOG"',
      'if [[ "$*" == *"access-check"* ]]; then if [[ "${AKB_TEST_FOREIGN_MAINTENANCE:-0}" == 1 ]]; then echo foreign-maintenance > "$AKB_WEBROOT/.maintenance"; fi; exit "${AKB_TEST_ACCESS_EXIT:-0}"; fi',
      'if [[ "$*" == "litespeed-purge all" ]]; then',
      '  count=0; [[ ! -e "$AKB_TEST_COMMAND_LOG.purge-count" ]] || read -r count < "$AKB_TEST_COMMAND_LOG.purge-count"',
      '  count=$((count + 1)); echo "$count" > "$AKB_TEST_COMMAND_LOG.purge-count"',
      '  if [[ "$count" -gt 1 ]]; then [[ -f "$AKB_WEBROOT/.maintenance" ]] || exit 88; echo rollback-maintenance-held >> "$AKB_TEST_COMMAND_LOG"; exit "${AKB_TEST_ROLLBACK_PURGE_EXIT:-0}"; fi',
      '  exit "${AKB_TEST_PURGE_EXIT:-0}"', 'fi',
      'if [[ "$*" == "eval-file -" ]]; then',
      '  cat >/dev/null; read -r count < "$AKB_TEST_COMMAND_LOG.purge-count"',
      '  if [[ "$count" -gt 1 ]]; then exit "${AKB_TEST_ROLLBACK_CLOUDFLARE_EXIT:-0}"; fi',
      '  exit "${AKB_TEST_CLOUDFLARE_EXIT:-0}"', 'fi',
      'if [[ "$*" == *"--field=version"* ]]; then echo "${AKB_TEST_REPORTED_VERSION:-9.9.9}"; fi', ''
    ].join('\n'), { mode: 0o755 });
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
files={slug+'/akibara-search.php':b"<?php\\n/**\\n * Version: 9.9.9\\n */\\ndefine( 'AKIBARA_SEARCH_VERSION', '9.9.9' );\\n",slug+'/src/new.php':b'<?php // new runtime\\n'}
for relative in ['assets/css/runtime.css','assets/js/runtime.js','frontend/facets-render.php','templates/admin/runtime.php','src/Search.php','src/Install.php','uninstall.php']:
 files[slug+'/'+relative]=b'/* Synthetic runtime fixture */\\n'
with zipfile.ZipFile(out/name,'w') as archive:
 for filename,data in files.items(): archive.writestr(filename,data)
(out/(slug+'-9.9.9.files.sha256')).write_text(''.join(hashlib.sha256(data).hexdigest()+'  '+filename+'\\n' for filename,data in files.items()))
(out/(name+'.sha256')).write_text(hashlib.sha256((out/name).read_bytes()).hexdigest()+'  '+name+'\\n')`, repo + '/dist'], { encoding: 'utf8' });
    assert.equal(build.status, 0, build.stderr);
    const source = repo + '/public_html/wp-content/plugins';
    mkdirSync(source, { recursive: true });
    const extract = spawnSync('python3', ['-c', 'import zipfile,sys; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])', repo + '/dist/akibara-search-9.9.9.zip', source], { encoding: 'utf8' });
    assert.equal(extract.status, 0, extract.stderr);
    const commandLog = path.join(directory, 'commands.log');
    const invoke = (args = [], env = {}) => spawnSync('bash', [repo + '/ops/akibara-deploy-runtime.sh', ...args, 'search', '9.9.9'], {
      cwd: repo, encoding: 'utf8', env: { ...process.env, PATH: bin + ':' + process.env.PATH, TMPDIR: temporary, AKB_TEST_COMMAND_LOG: commandLog, AKB_SSH_HOST: 'fake', AKB_WEBROOT: remote, ...env }
    });
    return run({ repo, remote, temporary, invoke, calls: () => existsSync(commandLog) ? readFileSync(commandLog, 'utf8') : '' });
  } finally { rmSync(directory, { recursive: true, force: true }); }
}
function cleanRemote(remote, maintenanceRetained = false) {
  assert.equal(existsSync(remote + '/wp-content/.akb-runtime-deploy.lock'), false, 'lock removed');
  assert.equal(existsSync(remote + '/.maintenance'), maintenanceRetained, 'maintenance ownership respected');
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
test('successful promotion retains the recovery archive only on the local machine', () => environment(({ repo, remote, invoke, calls }) => {
  const result = invoke();
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.match(readFileSync(remote + '/wp-content/plugins/' + slug + '/src/new.php', 'utf8'), /new runtime/);
  const backups = readdirSync(repo + '/.local-backups/runtime');
  assert.equal(backups.length, 1);
  const listing = spawnSync('tar', ['-tzf', repo + '/.local-backups/runtime/' + backups[0] + '/before.tar.gz'], { encoding: 'utf8' });
  assert.equal(listing.status, 0);
  assert.match(listing.stdout, /akibara-search.php/);
  const commands = calls();
  assert.ok(commands.indexOf('guardrail-full') < commands.indexOf('ssh'));
  assert.ok(commands.indexOf('qa http://localhost:8080') < commands.indexOf('ssh'));
  assert.ok(commands.indexOf('qa https://akibara.cl') > commands.indexOf('wp litespeed-purge all'));
  assert.ok(commands.indexOf('qa https://akibara.cl') < commands.indexOf('sync'));
  assert.ok(!commands.includes('db query'));
  const evidenceRoot = repo + '/.local-quality/runtime';
  const evidence = path.join(evidenceRoot, readdirSync(evidenceRoot)[0]);
  assert.ok(existsSync(evidence + '/validation.sha256'));
  assert.ok(existsSync(evidence + '/results.sha256'));
  cleanRemote(remote);
}));
for (const [name, env] of [
  ['the first rename fails after creating maintenance', { AKB_TEST_FIRST_MV_EXIT: '1' }],
  ['provider health fails', { AKB_TEST_ACCESS_EXIT: '1' }],
  ['mail health fails', { AKB_TEST_MAIL_EXIT: '1' }],
  ['production QA fails', { AKB_TEST_PROD_QA_EXIT: '1' }],
  ['LiteSpeed purge fails', { AKB_TEST_PURGE_EXIT: '1' }],
  ['Cloudflare purge fails', { AKB_TEST_CLOUDFLARE_EXIT: '1' }],
  ['post-deployment synchronization fails', { AKB_TEST_SYNC_EXIT: '1' }],
  ['WordPress reports the wrong version', { AKB_TEST_REPORTED_VERSION: '1.0.0' }],
  ['the swap receipt is lost after both renames', { AKB_TEST_LOST_RECEIPT: '1' }]
]) test(`rollback and cleanup when ${name}`, () => environment(({ remote, invoke, calls }) => {
  const result = invoke([], env);
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.match(result.stderr, /Restaurando el árbol anterior/, 'failure happened after swap, not in an unrelated preflight');
  assert.match(readFileSync(remote + '/wp-content/plugins/' + slug + '/akibara-search.php', 'utf8'), /original runtime/);
  assert.equal(existsSync(remote + '/wp-content/plugins/' + slug + '/src/new.php'), false);
  if (!env.AKB_TEST_FIRST_MV_EXIT) assert.ok(calls().includes('wp litespeed-purge all'), 'rollback invalidates served caches');
  cleanRemote(remote);
}));

for (const [name, env] of [
  ['LiteSpeed rollback purge fails', { AKB_TEST_ROLLBACK_PURGE_EXIT: '1' }],
  ['Cloudflare rollback purge fails', { AKB_TEST_ROLLBACK_CLOUDFLARE_EXIT: '1' }],
  ['the rollback purge connection is lost', { AKB_TEST_ROLLBACK_PURGE_DISCONNECT: '1' }]
]) test(`failed recovery stays under maintenance when ${name}`, () => environment(({ repo, remote, invoke, calls }) => {
  const result = invoke([], { AKB_TEST_CLOUDFLARE_EXIT: '1', ...env });
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.ok(result.stderr.includes('cachés de rollback pendientes; mantenimiento conservado'));
  assert.match(readFileSync(remote + '/wp-content/plugins/' + slug + '/akibara-search.php', 'utf8'), /original runtime/);
  assert.equal(existsSync(remote + '/wp-content/plugins/' + slug + '/src/new.php'), false);
  if (!env.AKB_TEST_ROLLBACK_PURGE_DISCONNECT) assert.ok(calls().includes('rollback-maintenance-held'));
  const backups = readdirSync(repo + '/.local-backups/runtime');
  assert.equal(backups.length, 1);
  assert.ok(existsSync(repo + '/.local-backups/runtime/' + backups[0] + '/before.tar.gz'));
  cleanRemote(remote, true);
  // Core's verified wp_is_maintenance_mode() contract expires fixed values at
  // 600s. Execute the generated PHP and require a live expression, without
  // depending on an ignored local WordPress installation in a clean checkout.
  assert.ok(readFileSync(remote + '/.maintenance', 'utf8').includes('$upgrading = time();'));
  const decision = 'require $argv[1]; echo (time() - $upgrading) < 600 ? "closed" : "open";';
  const runDecision = () => spawnSync('php', ['-r', decision, remote + '/.maintenance'], { encoding: 'utf8' });
  let checked = runDecision();
  assert.equal(checked.status, 0, checked.stderr);
  assert.equal(checked.stdout, 'closed');
  writeFileSync(remote + '/.maintenance', '<?php $upgrading = time() - 601;');
  checked = runDecision();
  assert.equal(checked.status, 0, checked.stderr);
  assert.equal(checked.stdout, 'open', 'negative control: merely keeping an expired timestamp would reopen the site');
}));

test('foreign maintenance appearing after swap survives a rollback with verified purges', () => environment(({ remote, invoke, calls }) => {
  const result = invoke([], { AKB_TEST_FOREIGN_MAINTENANCE: '1', AKB_TEST_ACCESS_EXIT: '1' });
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.equal(readFileSync(remote + '/.maintenance', 'utf8'), 'foreign-maintenance\n');
  assert.ok(calls().includes('rollback-maintenance-held'));
  assert.match(readFileSync(remote + '/wp-content/plugins/' + slug + '/akibara-search.php', 'utf8'), /original runtime/);
  cleanRemote(remote, true);
}));

for (const [name, env] of [
  ['seal failure', { AKB_TEST_SEAL_EXIT: '1' }],
  ['guardrail failure', { AKB_TEST_GUARDRAIL_EXIT: '1' }],
  ['source mutation during guardrail', { AKB_TEST_SOURCE_DRIFT: '1' }],
  ['local QA failure', { AKB_TEST_LOCAL_QA_EXIT: '1' }],
  ['mirror parity failure', { AKB_TEST_PARITY_EXIT: '1' }]
  , ['mirror artifact mismatch', { AKB_TEST_MIRROR_ARTIFACT_EXIT: '1' }]
]) test(`${name} blocks any deploy connection`, () => environment(({ remote, invoke, calls }) => {
  const result = invoke([], env);
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.ok(!calls().includes('ssh'), calls());
  cleanRemote(remote);
}));

for (const filename of ['tools/guardrail-ci.sh', 'tools/verify-devsecops-seal.py', 'tools/qa-playwright-full-suite.mjs', 'tools/akibara-releases/package-runtime.sh']) {
  test(`missing ${filename} fails before a remote connection`, () => environment(({ repo, remote, invoke, calls }) => {
    rmSync(path.join(repo, filename));
    const result = invoke();
    assert.equal(result.status, 1, result.stdout + result.stderr);
    assert.ok(result.stderr.includes('falta control obligatorio'));
    assert.ok(!calls().includes('ssh'));
    cleanRemote(remote);
  }));
}

test('a valid package different from the local candidate cannot be promoted', () => environment(({ repo, remote, invoke, calls }) => {
  writeFileSync(repo + '/public_html/wp-content/plugins/' + slug + '/src/new.php', '<?php // changed after packaging');
  const result = invoke();
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.ok(result.stderr.includes('El candidato difiere del espejo local'));
  assert.ok(!calls().includes('ssh'));
  cleanRemote(remote);
}));
for (const args of [[], ['--dry-run']]) {
  test(`a runtime file omitted from a valid ZIP is rejected${args.length ? ' in dry run' : ''}`, () => environment(({ repo, remote, temporary, invoke, calls }) => {
    writeFileSync(repo + '/public_html/wp-content/plugins/' + slug + '/src/OnlyOnMirror.php', '<?php // Required by the local runtime only.\n');
    const result = invoke(args);
    assert.equal(result.status, 1, result.stdout + result.stderr);
    assert.match(result.stderr, /solo_en_espejo.*OnlyOnMirror\.php/);
    assert.ok(!calls().includes('ssh'));
    assert.ok(!calls().includes('guardrail-full'), 'inventory comparison precedes expensive validation');
    assert.deepEqual(readdirSync(temporary), [], 'candidate and packager staging removed on rejection');
    cleanRemote(remote);
  }));
}
test('a file present in the ZIP but missing from the local runtime is rejected', () => environment(({ repo, remote, temporary, invoke, calls }) => {
  rmSync(repo + '/public_html/wp-content/plugins/' + slug + '/src/new.php');
  const result = invoke();
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.match(result.stderr, /solo_en_paquete.*src\/new\.php/);
  assert.ok(!calls().includes('ssh'));
  assert.deepEqual(readdirSync(temporary), []);
  cleanRemote(remote);
}));
test('official runtime selection excludes development tests without changing source metadata', () => environment(({ repo, remote, temporary, invoke, calls }) => {
  const source = repo + '/public_html/wp-content/plugins/' + slug;
  mkdirSync(source + '/tests');
  writeFileSync(source + '/tests/OnlyInDevelopment.php', '<?php // Never part of the runtime package.\n');
  const filename = source + '/src/new.php';
  const original = statSync(filename);
  const result = invoke(['--dry-run']);
  assert.equal(result.status, 0, result.stdout + result.stderr);
  const after = statSync(filename);
  assert.equal(after.mtimeMs, original.mtimeMs);
  assert.equal(after.mode, original.mode);
  assert.equal(readFileSync(filename, 'utf8'), '<?php // new runtime\n');
  assert.ok(!calls().includes('ssh'));
  assert.deepEqual(readdirSync(temporary), [], 'candidate and packager staging removed on success');
  assert.equal(existsSync(repo + '/.local-quality'), false);
  cleanRemote(remote);
}));
test('a new unreviewed top-level source cannot evade the official package selection', () => environment(({ repo, remote, temporary, invoke, calls }) => {
  writeFileSync(repo + '/public_html/wp-content/plugins/' + slug + '/unexpected.php', '<?php');
  const result = invoke();
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.match(result.stderr, /Ruta superior no revisada/);
  assert.ok(!calls().includes('ssh'));
  assert.deepEqual(readdirSync(temporary), []);
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
