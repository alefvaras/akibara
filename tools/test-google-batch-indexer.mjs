import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { loadState, notificationReport, run } from './google-batch-indexer.mjs';

const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'akb-retired-indexer-'));
const stateFile = path.join(tempDir, 'history.json');
const script = fileURLToPath(new URL('./google-batch-indexer.mjs', import.meta.url));
let checks = 0;
try {
  assert.deepEqual(loadState(stateFile), { submitted: {}, last_run: null });
  checks++;

  const historical = { submitted: { 'https://akibara.cl/serie/frieren/': '2026-10-25T12:00:00Z' }, last_run: '2026-10-25T12:00:00Z' };
  fs.writeFileSync(stateFile, JSON.stringify(historical));
  const before = fs.readFileSync(stateFile, 'utf8');
  const report = notificationReport(loadState(stateFile));
  assert.equal(report.historical_notification_records, 1);
  assert.equal(report.index_status, 'not_measured');
  assert.equal(report.sender_status, 'retired_for_catalogue');
  assert.equal(fs.readFileSync(stateFile, 'utf8'), before);
  checks++;

  for (const args of [[], ['--limit=50'], ['--dry-run'], ['--status', '--limit=50']]) {
    assert.throws(() => run(args, '/nonexistent-history-must-not-be-read'), /Envío de catálogo deshabilitado/);
    checks++;
  }

  const blocked = spawnSync(process.execPath, [script, '--limit=50'], { encoding: 'utf8' });
  assert.equal(blocked.status, 1);
  assert.match(blocked.stderr, /Indexing API no admite productos/);
  assert.equal(blocked.stdout, '');
  checks++;

  for (let batch = 1; batch <= 25; batch++) {
    const legacyBatch = fileURLToPath(new URL(`../scratchpad/apply_batch${batch}.mjs`, import.meta.url));
    const obsolete = spawnSync(process.execPath, [legacyBatch], { encoding: 'utf8' });
    assert.equal(obsolete.status, 1, `batch ${batch} must refuse execution`);
    assert.match(obsolete.stderr, /Despliegue histórico retirado/);
    assert.equal(obsolete.stdout, '');
    checks++;
  }

  for (const invalid of ['not JSON', '[]', '{"submitted":[]}']) {
    fs.writeFileSync(stateFile, invalid);
    assert.throws(() => loadState(stateFile));
    assert.equal(fs.readFileSync(stateFile, 'utf8'), invalid);
    checks++;
  }
  console.log(`PASS: ${checks} pruebas del retiro, historial de sólo lectura y gates bloqueados; sin API ni catálogo.`);
} finally {
  fs.rmSync(tempDir, { recursive: true, force: true });
}
