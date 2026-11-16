// Sonda de PRODUCCIÓN, sólo lectura: el enlace de invitación deja la cookie
// del padrino en un navegador real, detrás de Cloudflare.
//
// Es la única superficie que el espejo no puede medir: allí no hay Cloudflare,
// y fue Cloudflare quien borraba el Set-Cookie de PHP (2026-09-13). No inicia
// sesión, no crea cuentas ni pedidos: visita la portada y una ficha con
// `?ref=` y lee las cookies del propio navegador de la prueba.
//
// Uso: node qa-referido-produccion.mjs   (AKB_BASE para otra URL)
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'https://akibara.cl';
const UA = 'Mozilla/5.0 (AkibaraQA) Playwright';
const CARGA = { waitUntil: 'domcontentloaded', timeout: 90000 };
const CODIGO = 'qa0sonda00';

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 400)}`}`);
};

const nav = await chromium.launch();
try {
  for (const [nombre, camino, vp] of [
    ['portada · móvil', '/', { width: 390, height: 844 }],
    ['portada · escritorio', '/', { width: 1440, height: 950 }],
    ['ficha · móvil', '/20th-century-boys-1-ivrea-argentina/', { width: 390, height: 844 }],
  ]) {
    console.log(nombre);
    // Dos visitas por superficie: la primera suele ser MISS del borde y la
    // segunda HIT. El defecto de PHP perdía la cookie en las dos.
    for (const vuelta of [1, 2]) {
      const ctx = await nav.newContext({ viewport: vp, userAgent: UA });
      const page = await ctx.newPage();
      const resp = await page.goto(`${BASE}${camino}?ref=${CODIGO}`, CARGA);
      await page.waitForTimeout(2500);
      const cache = resp ? (resp.headers()['cf-cache-status'] || '—') : '—';
      const cookie = (await ctx.cookies()).find((c) => c.name === 'akb_ref');
      ok(cookie && cookie.value === CODIGO, `vuelta ${vuelta} (${cache}): el navegador guarda la cookie del enlace`, cookie || null);
      if (vuelta === 1) {
        ok((await page.content()).includes('akb-rewards-ref-js-after'), 'la página servida lleva el script de captura');
      }
      await ctx.close();
    }
  }
} finally {
  await nav.close();
}

if (fallos) {
  console.error(`FAIL: ${fallos} comprobación(es) rotas`);
  process.exit(1);
}
console.log('PASS: el enlace de invitación funciona detrás de Cloudflare');
