// QA del caso REAL del coleccionista: anotarse a varios tomos agotados de la
// misma serie en una sola visita. ESPEJO LOCAL (http://localhost:8080).
//
// Akibara vende series; quien llega a Claymore y encuentra media colección
// agotada quiere anotarse a TODOS los tomos que le faltan, no a uno. Este
// script hace exactamente eso, con clics reales, y reporta qué ve el cliente
// en cada intento.
//
//   node tools/akibara-releases/bis-qa/qa-bis-serie-varios-tomos-local.mjs
//
// Siembra y limpia por id (nunca por email) y deja el contador de rate-limit
// como lo encontró.

import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const REPO  = '/Users/alefvaras/Documents/akibara';
const BASE  = 'http://localhost:8080';
const UA_QA = 'AkibaraQA/1.0 (Playwright; QA serie multi-tomo BIS local, business 1.114.2)';
const EMAIL = `qa.bis.serie.${Date.now().toString(36)}@example.com`;

// Cuatro tomos agotados de la MISMA serie (Claymore, Panini Argentina).
const TOMOS = [
  ['Claymore 12', `${BASE}/product/claymore-12-panini-argentina/`],
  ['Claymore 13', `${BASE}/product/claymore-13-panini-argentina/`],
  ['Claymore 15', `${BASE}/product/claymore-15-panini-argentina/`],
  ['Claymore 17', `${BASE}/product/claymore-17-panini-argentina/`],
];

function wp(args) {
  return execFileSync('docker', ['compose', '--profile', 'tools', 'run', '--rm', '-T', 'cli', 'wp', ...args],
    { cwd: REPO, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 32 * 1024 * 1024 }).trim();
}
const sql = (q) => wp(['db', 'query', q]).split('\n').map((l) => l.trim()).filter(Boolean);

// El contador de rate-limit de la hora es artefacto de MIS sondas: se borra
// antes (para partir del mismo estado que un cliente recién llegado) y después.
const limpiarContador = () => wp(['db', 'query', "DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_akb\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_akb\\_rl\\_%'"]);

const idMaxInicial = parseInt(sql('SELECT COALESCE(MAX(id),0) FROM wp_akb_bis_subscribers')[1], 10);
const filasIniciales = parseInt(sql('SELECT COUNT(*) FROM wp_akb_bis_subscribers')[1], 10);
limpiarContador();

console.log('\n════════════════════════════════════════════════════════════════');
console.log(' El coleccionista: 4 tomos agotados de la misma serie, una visita');
console.log('════════════════════════════════════════════════════════════════');
console.log(` email    : ${EMAIL}`);
console.log(` baseline : ${filasIniciales} filas, max(id)=${idMaxInicial}\n`);

const browser = await chromium.launch();
const ctx = await browser.newContext({ userAgent: UA_QA, viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();

const vistos = [];
const idsSembrados = [];
try {
  for (const [nombre, url] of TOMOS) {
    const r = await page.goto(url, { waitUntil: 'domcontentloaded' });
    if (r.status() !== 200) { console.log(`  ${nombre}: HTTP ${r.status()} — se salta`); continue; }
    if (await page.locator('#akb-bis-form').count() !== 1) { console.log(`  ${nombre}: sin formulario (¿no está agotado?)`); continue; }

    await page.locator('#akb-bis-email').click();
    await page.locator('#akb-bis-email').type(EMAIL, { delay: 15 });
    await page.locator('.akb-bis__submit').click();
    await page.locator('#akb-bis-message').waitFor({ state: 'visible', timeout: 15000 });

    const msg = (await page.locator('#akb-bis-message').textContent() || '').trim();
    const clase = await page.locator('#akb-bis-message').getAttribute('class');
    const esError = /is-error/.test(clase || '');
    const formSigue = await page.locator('.akb-bis__form').isVisible();
    // Anotar el id EXACTO recién creado: el espejo lo comparten otros agentes y
    // un «DELETE WHERE id > baseline» se llevaría por delante lo que siembren
    // ellos mientras esto corre.
    const f = sql(`SELECT id FROM wp_akb_bis_subscribers WHERE email='${EMAIL}' ORDER BY id DESC LIMIT 1`);
    if (f.length > 1) { const id = parseInt(f[1], 10); if (!idsSembrados.includes(id)) idsSembrados.push(id); }
    vistos.push({ nombre, msg, esError, formSigue });
    console.log(`  ${vistos.length}. ${nombre}`);
    console.log(`     ve: «${msg}»  ${esError ? '(EN ROJO / error)' : '(en verde / ok)'}`);
  }

  const anotados = parseInt(sql(`SELECT COUNT(*) FROM wp_akb_bis_subscribers WHERE email='${EMAIL}'`)[1], 10);
  const rechazados = vistos.filter((v) => v.esError);
  console.log(`\n  Tomos en los que quedó anotado: ${anotados} de ${vistos.length} intentados`);
  if (rechazados.length) {
    console.log(`  Tomos rechazados: ${rechazados.map((v) => v.nombre).join(', ')}`);
    console.log(`  Frase textual del rechazo: «${rechazados[0].msg}»`);
  }
} finally {
  console.log('\n── Restauración');
  // SOLO los ids que sembró este script (nunca por email, nunca por rango).
  const nuevas = idsSembrados;
  for (const id of nuevas) wp(['db', 'query', `DELETE FROM wp_akb_bis_subscribers WHERE id=${id}`]);
  limpiarContador();
  const quedan = parseInt(sql(`SELECT COUNT(*) FROM wp_akb_bis_subscribers WHERE id IN (${nuevas.length ? nuevas.join(',') : 0})`)[1], 10);
  const filasFinales = parseInt(sql('SELECT COUNT(*) FROM wp_akb_bis_subscribers')[1], 10);
  console.log(`  filas borradas por id: [${nuevas.join(', ')}]`);
  console.log(`  restos de este script: ${quedan} → ${quedan === 0 ? 'RESTAURADO' : 'REVISAR'}`);
  console.log(`  tabla: ${filasFinales} filas (al empezar ${filasIniciales}; el espejo lo comparten otros agentes, el total puede diferir por lo suyo)`);
  await browser.close();
}
process.exit(0);
