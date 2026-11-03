#!/usr/bin/env node
/**
 * Build the four Search bundles served by Search::enqueue_assets.
 * Flags and source-SHA banners are the byte contract in search-runtime-contract.test.js.
 * npm ci supplies esbuild; builds never download a compiler implicitly.
 */
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { chmodSync, existsSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const ESBUILD_VERSION = '0.28.2';
const REPO = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
export const SEARCH_BUNDLES = [
  ['assets/js/search-suggest.js', 'assets/js/search-suggest.min.js', ['--minify', '--target=es2017']],
  ['assets/js/akb-search.js', 'assets/js/akb-search.min.js', ['--minify']],
  ['assets/css/search-suggest.css', 'assets/css/search-suggest.min.css', ['--minify']],
  ['assets/css/akb-search.css', 'assets/css/akb-search.min.css', ['--minify']],
];

export function buildSearchAssets({ repo = REPO, esbuild = process.env.AKB_ESBUILD || join(repo, 'node_modules/.bin/esbuild') } = {}) {
  const version = execFileSync(esbuild, ['--version'], { encoding: 'utf8' }).trim();
  if (version !== ESBUILD_VERSION) throw new Error(`esbuild ${ESBUILD_VERSION} requerido; recibido ${version}`);
  const plugin = join(repo, 'public_html/wp-content/plugins/akibara-search');
  // Compile the complete batch before writing; a compiler/source failure preserves every old bundle.
  const compiled = SEARCH_BUNDLES.map(([source, target, flags]) => {
    const input = join(plugin, source);
    const sha = createHash('sha256').update(readFileSync(input)).digest('hex');
    const type = source.endsWith('.css') ? 'css' : 'js';
    const output = execFileSync(esbuild, [input, ...flags, `--banner:${type}=/*! akb-src-sha256: ${sha} */`], { maxBuffer: 16 * 1024 * 1024 });
    return { source, target: join(plugin, target), output };
  });
  for (const { target, output } of compiled) {
    if (existsSync(target) && readFileSync(target).equals(output)) continue;
    const temporary = `${target}.akb-build-${process.pid}.tmp`;
    try {
      writeFileSync(temporary, output, { mode: 0o644 });
      chmodSync(temporary, 0o644);
      renameSync(temporary, target);
    } finally {
      rmSync(temporary, { force: true });
    }
  }
  return compiled.map(({ source, output }) => ({ source, bytes: output.length }));
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const outputs = buildSearchAssets();
    for (const { source, bytes } of outputs) console.log(`${source}: ${bytes} bytes, esbuild ${ESBUILD_VERSION}`);
  } catch (error) {
    console.error(`Search build falló: ${error.message}`);
    process.exitCode = 1;
  }
}
