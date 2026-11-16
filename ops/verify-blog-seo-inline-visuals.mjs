#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..');
const OUTPUT = join(ROOT, 'output', 'blog-seo-inline-visuals-20260726');
const MANIFEST_PATH = join(OUTPUT, 'manifest.json');
const EDITORIAL_SOURCE = join(ROOT, '.local-reports', 'seo-blog-score-audit', 'all-posts-desired.json');
const MAX_BYTES = 150 * 1024;

const expectedRoles = new Map([
  [22353, ['sistema_de_magia', 'cronologia_manga_anime']],
  [22610, ['comparacion_de_ediciones', 'mapa_de_lectura']],
  [22638, ['mapa_anime_a_capitulo_tomo']],
  [22639, ['mapa_anime_pelicula_a_capitulo_tomo', 'cronologia_anime_pelicula_manga']],
  [22642, ['matriz_seinen_por_tono', 'demografia_seinen']],
  [23323, ['anime_a_42_tomos']],
  [23324, ['cronologia_de_arcos']],
  [23326, ['continuidad_anime_manga', 'razones_para_leer_el_manga']],
  [23332, ['mapa_historico_cultural', 'guia_de_arcos_sin_spoilers']],
  [23333, ['comparacion_deluxe_estandar', 'cronologia_manga_pelicula_edicion']],
  [23334, ['flujo_de_preventa', 'linea_temporal_de_preventa']],
  [23335, ['serie_precuela_secuela', 'formatos_y_orden_de_lectura']],
  [23336, ['matriz_regalo_presupuesto_edad', 'selector_de_regalo']],
  [24975, ['tabla_panini_ivrea', 'guia_de_formatos']],
  [24976, ['matriz_tono_extension_advertencias', 'selector_de_seinen']],
  [24977, ['conviene_no_conviene_preventa']],
  [24978, ['matriz_romance_por_tono', 'selector_de_romance']],
  [25045, ['tabla_japon_espanol_fecha_corte', 'cronologia_editorial']],
]);

const sourceChecks = new Map([
  [22353, [/16 tomos/i, /abril de 2026/i, /desde 2016/i]],
  [22610, [/dos tomos japoneses/i, /43 tomos/i, /tomos 1-3/i, /tomos 3-14/i, /tomos 14-21/i, /tomos 22-35/i, /tomos 35-42/i]],
  [22638, [/tomo 9/i, /tomo 17/i, /capítulo 181/i, /tomo 21/i, /30 tomos/i]],
  [22639, [/capítulos 1 al 38/i, /capítulo 39/i, /tomo 5/i, /capítulo 52/i, /capítulo 53/i, /tomo 7/i]],
  [22642, [/público objetivo/i, /edad recomendada/i, /no existe una frontera temática absoluta/i]],
  [23323, [/42 tomos/i, /430 capítulos/i, /diciembre de 2025/i, /epílogo de 38 páginas/i]],
  [23324, [/Primera Selección/i, /tomos 1-5/i, /Segunda Selección/i, /tomos 5-8/i, /Tercera Selección/i, /tomos 8-12/i, /U-20/i, /tomos 12-17/i, /Neo Egoist League/i, /tomos 17-30/i]],
  [23326, [/148 episodios/i, /capítulo 340/i, /tomo 33/i, /39 tomos/i, /Continente Oscuro/i, /Sucesión/i]],
  [23332, [/31 tomos/i, /Hokkaido/i, /Ainu/i, /Guerra Ruso-Japonesa/i]],
  [23333, [/20 tomos/i, /edición tradicional tuvo 31/i, /película/i, /no reemplaza/i]],
  [23334, [/fecha estimada/i, /cuando todos los artículos han ingresado/i]],
  [23335, [/tankoubon/i, /omnibus/i, /deluxe/i, /precuela/i, /secuela/i, /spin-off/i]],
  [23336, [/edad orientativa/i, /presupuesto/i, /Deluxe/i, /omnibus/i, /siguiente volumen exacto/i]],
  [24975, [/país de edición/i, /traducción/i, /formato/i, /ISBN/i, /capítulos incluidos/i]],
  [24976, [/Monster/i, /Vinland Saga/i, /Vagabond/i, /Golden Kamuy/i, /Berserk/i, /Oyasumi Punpun/i, /Homunculus/i]],
  [24977, [/fecha logística estimada/i, /regalo con fecha cercana/i, /envío inmediato/i, /edición específica/i]],
  [24978, [/Kaguya-sama/i, /Ao Haru Ride/i, /Nana/i, /Wotakoi/i, /Kimi ni Todoke/i]],
  [25045, [/26 de julio de 2026/i, /volumen 9/i, /ocho volúmenes/i, /4 de junio de 2026/i]],
]);

function sha256(path) {
  return createHash('sha256').update(readFileSync(path)).digest('hex');
}

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function safeAssetPath(relativePath) {
  assert(typeof relativePath === 'string' && relativePath.length > 0, 'Invalid empty path');
  const resolved = resolve(OUTPUT, relativePath);
  assert(resolved.startsWith(`${OUTPUT}/`), `Path escapes output directory: ${relativePath}`);
  return resolved;
}

function main() {
  const manifest = JSON.parse(readFileSync(MANIFEST_PATH, 'utf8'));
  const editorial = JSON.parse(readFileSync(EDITORIAL_SOURCE, 'utf8'));
  assert(manifest.asset_count === 32, `manifest.asset_count must be 32, got ${manifest.asset_count}`);
  assert(manifest.assets.length === 32, `manifest assets must contain 32 entries, got ${manifest.assets.length}`);
  assert(manifest.post_count === 18, `manifest.post_count must be 18, got ${manifest.post_count}`);

  const seenKeys = new Set();
  const byPost = new Map();
  let totalBytes = 0;
  let largest = null;

  for (const asset of manifest.assets) {
    const key = `${asset.post_id}:${asset.role}`;
    assert(!seenKeys.has(key), `Duplicate asset key: ${key}`);
    seenKeys.add(key);
    if (!byPost.has(asset.post_id)) byPost.set(asset.post_id, []);
    byPost.get(asset.post_id).push(asset.role);

    assert(asset.alt.length >= 40, `Alt is too short: ${key}`);
    assert(asset.caption.length >= 40, `Caption is too short: ${key}`);
    assert(asset.dimensions === '1200x675', `Manifest dimensions mismatch: ${key}`);

    const webpPath = safeAssetPath(asset.file);
    const svgPath = safeAssetPath(asset.source_svg);
    const webp = readFileSync(webpPath);
    const stats = statSync(webpPath);
    totalBytes += stats.size;

    assert(webp.subarray(0, 4).toString('ascii') === 'RIFF', `Missing RIFF header: ${key}`);
    assert(webp.subarray(8, 12).toString('ascii') === 'WEBP', `Missing WEBP signature: ${key}`);
    assert(stats.size === asset.bytes, `Byte count mismatch: ${key}`);
    assert(stats.size < MAX_BYTES, `Asset is not under 150 KB: ${key}`);
    assert(sha256(webpPath) === asset.sha256, `WebP SHA-256 mismatch: ${key}`);
    assert(sha256(svgPath) === asset.source_svg_sha256, `SVG SHA-256 mismatch: ${key}`);

    const dimensions = execFileSync('magick', ['identify', '-format', '%wx%h', webpPath], { encoding: 'utf8' }).trim();
    assert(dimensions === '1200x675', `Rendered dimensions mismatch: ${key} (${dimensions})`);

    const svg = readFileSync(svgPath, 'utf8');
    assert(svg.includes('<title id="title">'), `SVG title missing: ${key}`);
    assert(svg.includes('<desc id="description">'), `SVG description missing: ${key}`);
    assert(svg.includes('#0b0b0c') && svg.includes('#e50914'), `Brand colors missing: ${key}`);
    assert(!/(#ffff00|#ffd700|#facc15|#ffcc00|#ffbf00|#f5c400)/i.test(svg), `Forbidden yellow found: ${key}`);

    const coordinates = [...svg.matchAll(/\b(?:x|y|cx|cy)="(-?\d+(?:\.\d+)?)"/g)].map((match) => Number(match[1]));
    assert(coordinates.every((value) => value >= 0 && value <= 1200), `Out-of-bounds coordinate: ${key}`);

    if (!largest || stats.size > largest.bytes) largest = { file: asset.file, bytes: stats.size };
  }

  assert(byPost.size === expectedRoles.size, `Expected ${expectedRoles.size} posts, found ${byPost.size}`);
  for (const [postId, roles] of expectedRoles) {
    const actual = [...(byPost.get(postId) || [])].sort();
    const expected = [...roles].sort();
    assert(JSON.stringify(actual) === JSON.stringify(expected), `Role mismatch for post ${postId}: ${actual.join(', ')}`);
  }

  const editorialByPost = new Map(editorial.posts.map((post) => [
    post.production_post_id,
    post.fields.post_content
      .replace(/<!--[\s\S]*?-->/g, ' ')
      .replace(/<[^>]+>/g, ' ')
      .replace(/&nbsp;/g, ' ')
      .replace(/&amp;/g, '&')
      .replace(/\s+/g, ' ')
      .trim(),
  ]));
  for (const [postId, checks] of sourceChecks) {
    const content = editorialByPost.get(postId);
    assert(typeof content === 'string', `Editorial source missing for post ${postId}`);
    for (const pattern of checks) {
      assert(pattern.test(content), `Editorial fact basis missing for post ${postId}: ${pattern}`);
    }
  }

  process.stdout.write(`PASS: ${manifest.assets.length} assets, ${byPost.size} posts, exact role coverage.\n`);
  process.stdout.write(`PASS: all WebP files are 1200x675 and under 150 KB.\n`);
  process.stdout.write(`PASS: hashes, SVG accessibility text, brand colors and no-yellow rule.\n`);
  process.stdout.write(`PASS: temporal and editorial claims are grounded in the reviewed post source.\n`);
  process.stdout.write(`Total WebP payload: ${totalBytes} bytes; largest: ${largest.file} (${largest.bytes} bytes).\n`);
}

main();
