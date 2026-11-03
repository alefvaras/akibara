import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtempSync, mkdirSync, copyFileSync, writeFileSync, readFileSync, readdirSync, existsSync, rmSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const smtp = 'akibara-correo-smtp.php';
const watch = 'akibara-mail-transport-watch.php';
function fixture(run) {
  const directory = mkdtempSync(path.join(tmpdir(), 'akb-mu-test-'));
  try {
    const repo = directory + '/repo'; const domain = directory + '/domain';
    const webroot = domain + '/public_html'; const rescue = domain + '/akb-runtime';
    const local = repo + '/public_html/wp-content/mu-plugins'; const live = webroot + '/wp-content/mu-plugins';
    const bin = directory + '/bin';
    for (const item of [repo + '/ops', local, live, rescue, bin]) mkdirSync(item, { recursive: true });
    copyFileSync(root + '/ops/akibara-prod-muplugins-deploy.sh', repo + '/ops/akibara-prod-muplugins-deploy.sh');
    writeFileSync(repo + '/ops/akibara-prod-mail-health.sh', '#!/usr/bin/env bash\nexit "${AKB_TEST_MAIL_EXIT:-0}"\n', { mode: 0o755 });
    for (const name of [smtp, watch]) {
      writeFileSync(local + '/' + name, '<?php // new ' + name + '\n');
      writeFileSync(live + '/' + name, '<?php // old ' + name + '\n');
    }
    writeFileSync(rescue + '/' + smtp, '<?php // original rescue\n');
    writeFileSync(bin + '/ssh', '#!/usr/bin/env bash\nset -e\nshift 3\nif [[ "${AKB_TEST_CONCURRENT_CHANGE:-0}" == 1 && "$1" == *"touch "* ]]; then printf "<?php // concurrent authoritative change\\n" > "${AKB_WEBROOT}/wp-content/mu-plugins/akibara-mail-transport-watch.php"; fi\nif [[ "${AKB_TEST_CONCURRENT_RESCUE:-0}" == 1 && "$1" == *"touch "* ]]; then printf "<?php // concurrent rescue change\\n" > "${AKB_BACKUP_DIR}/akibara-correo-smtp.php"; fi\nif [[ "${AKB_TEST_LOST_RECEIPT:-0}" == 1 && "$1" == *"for f in "* && "$1" == *"touch "* ]]; then bash -c "$1" >/dev/null; exit 255; fi\nexec bash -c "$1"\n', { mode: 0o755 });
    writeFileSync(bin + '/scp', '#!/usr/bin/env bash\nset -e\n[[ "$1" == -q ]] && shift\nargs=("$@")\ndest="${args[${#args[@]}-1]}"; dest="${dest#*:}"\nfor ((i=0;i<${#args[@]}-1;i++)); do cp "${args[$i]}" "$dest"; done\nif [[ "${AKB_TEST_CORRUPT_STAGE:-0}" == 1 ]]; then echo corrupt > "${dest}/new/akibara-correo-smtp.php"; fi\n', { mode: 0o755 });
    writeFileSync(bin + '/wp', '#!/usr/bin/env bash\nexit "${AKB_TEST_ACCESS_EXIT:-0}"\n', { mode: 0o755 });
    writeFileSync(bin + '/mv', '#!/usr/bin/env bash\nlast="${!#}"\nif [[ -n "${AKB_TEST_FAIL_TARGET:-}" && "$*" == *"/new/"* && "$last" == *"/${AKB_TEST_FAIL_TARGET}" ]]; then exit 1; fi\nexec /bin/mv "$@"\n', { mode: 0o755 });
    writeFileSync(bin + '/stat', '#!/usr/bin/env python3\nimport os,sys\ns=os.stat(sys.argv[-1]);print(s.st_dev if sys.argv[2]=="%d" else int(s.st_mtime))\n', { mode: 0o755 });
    writeFileSync(bin + '/sha256sum', `#!/usr/bin/env python3
import pathlib,hashlib,sys
if len(sys.argv)>1 and sys.argv[1]=='-c':
 failed=False
 for line in pathlib.Path(sys.argv[2]).read_text().splitlines():
  checksum,filename=line.split('  ',1)
  p=pathlib.Path(filename);good=p.is_file() and hashlib.sha256(p.read_bytes()).hexdigest()==checksum
  print(filename+(': OK' if good else ': FAILED'));failed=failed or not good
 sys.exit(1 if failed else 0)
for filename in sys.argv[1:]: print(hashlib.sha256(pathlib.Path(filename).read_bytes()).hexdigest()+'  '+filename)
`, { mode: 0o755 });
    const invoke = (args = [], env = {}) => spawnSync('/bin/bash', [repo + '/ops/akibara-prod-muplugins-deploy.sh', ...args], { cwd: repo, encoding: 'utf8',
      env: { ...process.env, PATH: bin + ':' + process.env.PATH, AKB_SSH_HOST: 'fake', AKB_WEBROOT: webroot, AKB_BACKUP_DIR: rescue, ...env } });
    return run({ repo, domain, webroot, rescue, local, live, invoke });
  } finally { rmSync(directory, { recursive: true, force: true }); }
}
function clean(f, foreign = false) {
  assert.equal(existsSync(f.webroot + '/wp-content/.akb-runtime-deploy.lock'), false);
  assert.equal(existsSync(f.webroot + '/.maintenance'), foreign);
  assert.equal(readdirSync(f.domain).some(name => name.startsWith('.akb-mu-')), false, 'staging removed outside docroot');
}
test('MU dry-run supports macOS Bash and uses no remote or backup writes', () => fixture(f => {
  const result = f.invoke(['--dry-run']); assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.equal(existsSync(f.repo + '/.local-backups'), false); clean(f);
}));
test('explicit quarantine and path traversal cannot be deployed', () => fixture(f => {
  for (const name of ['akibara-local-cron-spawn.php', 'akibara-debug-log-rotation.php', '../wp-config.php', 'akibara-fixture-reviews.php']) {
    writeFileSync(f.local + '/' + path.basename(name), '<?php');
    const result = f.invoke(['--dry-run', name]); assert.equal(result.status, 1, name + result.stdout + result.stderr);
  }
  clean(f);
}));
test('--all excludes third-party and quarantined MU sources', () => fixture(f => {
  for (const name of ['hostinger-preview-domain.php', 'akibara-local-cron-spawn.php', 'akibara-debug-log-rotation.php']) writeFileSync(f.local + '/' + name, '<?php');
  const result = f.invoke(['--dry-run', '--all']); assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.doesNotMatch(result.stdout, /hostinger|akibara-local-|debug-log-rotation/); clean(f);
}));
test('a local symlink cannot supply a MU runtime file', () => fixture(f => {
  const name='akibara-linked.php';symlinkSync(f.local + '/' + smtp, f.local + '/' + name);
  const result=f.invoke(['--dry-run',name]);assert.equal(result.status,1);assert.match(result.stderr,/origen no regular/);clean(f);
}));
test('MU batch and SMTP rescue are promoted together with backup only local', () => fixture(f => {
  const result=f.invoke();assert.equal(result.status,0,result.stdout+result.stderr);
  for(const name of [smtp,watch])assert.equal(readFileSync(f.live+'/'+name,'utf8'),readFileSync(f.local+'/'+name,'utf8'));
  assert.equal(readFileSync(f.rescue+'/'+smtp,'utf8'),readFileSync(f.local+'/'+smtp,'utf8'));
  const backups=readdirSync(f.repo+'/.local-backups/mu-deploy');assert.equal(backups.length,1);
  assert.match(readFileSync(f.repo+'/.local-backups/mu-deploy/'+backups[0]+'/live/'+smtp,'utf8'),/old /);
  assert.equal(readFileSync(f.repo+'/.local-backups/mu-deploy/'+backups[0]+'/rescue/'+smtp,'utf8'),'<?php // original rescue\n');clean(f);
}));
for(const [name,env] of [['mail health',{AKB_TEST_MAIL_EXIT:'1'}],['provider health',{AKB_TEST_ACCESS_EXIT:'1'}],['first live rename',{AKB_TEST_FAIL_TARGET:smtp}],['second file rename',{AKB_TEST_FAIL_TARGET:watch}],['lost mutation receipt',{AKB_TEST_LOST_RECEIPT:'1'}]]) {
  test(`MU rollback restores live and rescue when ${name} fails`,()=>fixture(f=>{
    const result=f.invoke([],env);assert.equal(result.status,1,result.stdout+result.stderr);assert.match(result.stdout,/Restaurando preimagen/,'failure exercised mutation/rollback');
    for(const name of[smtp,watch])assert.equal(readFileSync(f.live+'/'+name,'utf8'),'<?php // old '+name+'\n');
    assert.equal(readFileSync(f.rescue+'/'+smtp,'utf8'),'<?php // original rescue\n');clean(f);
  }));
}
test('rollback removes newly added MU files absent from the preimage',()=>fixture(f=>{
  const name='akibara-new-feature.php';writeFileSync(f.local+'/'+name,'<?php // new feature\n');
  const result=f.invoke([smtp,watch,name],{AKB_TEST_MAIL_EXIT:'1'});assert.equal(result.status,1,result.stdout+result.stderr);
  assert.equal(existsSync(f.live+'/'+name),false);assert.match(readFileSync(f.live+'/'+smtp,'utf8'),/old /);clean(f);
}));
test('corrupt staging aborts without replacing any live MU file',()=>fixture(f=>{
  const result=f.invoke([],{AKB_TEST_CORRUPT_STAGE:'1'});assert.equal(result.status,1,result.stdout+result.stderr);
  assert.doesNotMatch(result.stdout,/Restaurando preimagen/);assert.match(readFileSync(f.live+'/'+smtp,'utf8'),/old /);clean(f);
}));
test('existing maintenance survives a rejected batch and rollback',()=>fixture(f=>{
  writeFileSync(f.webroot+'/.maintenance','foreign-maintenance');
  const result=f.invoke();assert.equal(result.status,1,result.stdout+result.stderr);
  assert.equal(readFileSync(f.webroot+'/.maintenance','utf8'),'foreign-maintenance');assert.doesNotMatch(result.stdout,/Restaurando preimagen/);clean(f,true);
}));
test('another deployment lock is preserved without backup or staging',()=>fixture(f=>{
  mkdirSync(f.webroot+'/wp-content/.akb-runtime-deploy.lock');
  const result=f.invoke();assert.equal(result.status,1);assert.equal(existsSync(f.repo+'/.local-backups'),false);
  assert.equal(existsSync(f.webroot+'/wp-content/.akb-runtime-deploy.lock'),true);
  assert.equal(readdirSync(f.domain).some(n=>n.startsWith('.akb-mu-')),false);
}));

for (const [target, env, contents] of [
  [watch, {AKB_TEST_CONCURRENT_CHANGE:'1'}, '<?php // concurrent authoritative change\n'],
  ['rescue', {AKB_TEST_CONCURRENT_RESCUE:'1'}, '<?php // concurrent rescue change\n'],
]) {
  test(`pre-mutation CAS preserves a concurrent ${target} change without rollback`,()=>fixture(f=>{
    const result=f.invoke([],env);assert.equal(result.status,1,result.stdout+result.stderr);
    assert.doesNotMatch(result.stdout,/Restaurando preimagen/);
    assert.equal(readFileSync(target==='rescue'?f.rescue+'/'+smtp:f.live+'/'+target,'utf8'),contents);
    assert.equal(readFileSync(f.live+'/'+smtp,'utf8'),'<?php // old '+smtp+'\n');clean(f);
  }));
}
