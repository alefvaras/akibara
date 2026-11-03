import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { buildSearchAssets, SEARCH_BUNDLES } from './akibara-releases/build-search-assets.mjs';

function fixture(run) {
  const repo = mkdtempSync(join(tmpdir(), 'akb-search-build-'));
  const plugin = join(repo, 'public_html/wp-content/plugins/akibara-search');
  const bin = join(repo, 'compiler');
  for (const [source, target] of SEARCH_BUNDLES) {
    mkdirSync(dirname(join(plugin, source)), { recursive: true });
    writeFileSync(join(plugin, source), `source: ${source}\n`);
    writeFileSync(join(plugin, target), `old: ${target}\n`);
  }
  const compiler = (version, fail = false) => writeFileSync(bin, `#!/usr/bin/env node
if (process.argv[2] === '--version') console.log('${version}');
else if (${fail} && process.argv[2].endsWith('akb-search.css')) process.exit(1);
else console.log(JSON.stringify(process.argv.slice(2)));
`, { mode: 0o755 });
  try { return run({ repo, plugin, bin, compiler }); } finally { rmSync(repo, { recursive: true, force: true }); }
}

test('Search compiler mismatch and missing binary preserve all old bundles', () => fixture(f => {
  f.compiler('0.99.0');
  assert.throws(() => buildSearchAssets({ repo: f.repo, esbuild: f.bin }), /0\.28\.2 requerido/);
  assert.throws(() => buildSearchAssets({ repo: f.repo, esbuild: f.bin + '-missing' }), /ENOENT/);
  for (const [, target] of SEARCH_BUNDLES) assert.equal(readFileSync(join(f.plugin, target), 'utf8'), `old: ${target}\n`);
}));

test('Search builder preserves exact flags, source hashes and the four-file output contract', () => fixture(f => {
  f.compiler('0.28.2');
  const outputs = buildSearchAssets({ repo: f.repo, esbuild: f.bin });
  assert.equal(outputs.length, 4);
  for (const [source, target, flags] of SEARCH_BUNDLES) {
    const sha = createHash('sha256').update(readFileSync(join(f.plugin, source))).digest('hex');
    const type = source.endsWith('.css') ? 'css' : 'js';
    const args = JSON.parse(readFileSync(join(f.plugin, target), 'utf8'));
    assert.deepEqual(args, [join(f.plugin, source), ...flags, `--banner:${type}=/*! akb-src-sha256: ${sha} */`]);
  }
  for (const dir of ['assets/css', 'assets/js']) assert.equal(readdirSync(join(f.plugin, dir)).some(name => name.endsWith('.tmp')), false);
}));

test('Search compiler failure on the last source preserves every previous served bundle', () => fixture(f => {
  f.compiler('0.28.2', true);
  assert.throws(() => buildSearchAssets({ repo: f.repo, esbuild: f.bin }));
  for (const [, target] of SEARCH_BUNDLES) {
    assert.equal(readFileSync(join(f.plugin, target), 'utf8'), `old: ${target}\n`);
    assert.equal(existsSync(join(f.plugin, target) + `.akb-build-${process.pid}.tmp`), false);
  }
}));
