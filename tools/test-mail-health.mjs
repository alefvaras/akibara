import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
function probe(overrides = {}) {
  const temp = mkdtempSync(path.join(tmpdir(), 'akb-mail-health-'));
  try {
    const hash = createHash('md5').update(readFileSync(path.join(root, 'public_html/wp-content/mu-plugins/akibara-correo-smtp.php'))).digest('hex');
    writeFileSync(path.join(temp, 'ssh'), `#!/usr/bin/env python3
import os,sys
command=sys.argv[-1]
if command.startswith('ls '):
 print('akibara-correo-smtp.php\\nakibara-mail-transport-watch.php')
elif command.startswith('md5sum '):
 print('${hash}  /public_html/wp-content/mu-plugins/akibara-correo-smtp.php')
 print('${hash}  /akb-runtime/akibara-correo-smtp.php')
elif 'wp eval-file -' in command:
 source=sys.stdin.read()
 if not source.startswith('<?php') or '/tmp/' in command: sys.exit(90)
 print('env=production enabled=true hook=true\\ntransport=smtp')
 if not os.getenv('TEST_NO_AUTH'): print('smtp=auth-ok')
 sys.exit(int(os.getenv('TEST_PROBE_EXIT','0')))
elif 'transactional-emails-' in command:
 sys.exit(int(os.getenv('TEST_LOG_EXIT','0')))
else: sys.exit(91)
`, {mode: 0o755});
    return spawnSync('bash', [path.join(root, 'ops/akibara-prod-mail-health.sh')], {
      cwd: root, encoding: 'utf8', env: {...process.env, PATH: temp + path.delimiter + process.env.PATH, ...overrides}
    });
  } finally { rmSync(temp, {recursive: true, force: true}); }
}
test('mail health streams the PHP probe through stdin without a remote file', () => {
  const result = probe();
  assert.equal(result.status, 0, result.stdout + result.stderr);
});
test('an unsuccessful probe cannot pass using earlier successful-looking markers', () => {
  assert.equal(probe({TEST_PROBE_EXIT: '81'}).status, 1);
});
test('missing SMTP authentication blocks the gate', () => {
  assert.equal(probe({TEST_NO_AUTH: '1'}).status, 1);
});
test('unreadable logs cannot be reported as zero mail failures', () => {
  assert.equal(probe({TEST_LOG_EXIT: '255'}).status, 1);
});
