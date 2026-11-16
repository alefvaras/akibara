#!/usr/bin/env node
/**
 * El buscador visto desde el navegador del cliente cuando el servidor cierra un
 * instante (search 0.1.64).
 *
 * El servidor responde 503 `index_unavailable` mientras re-sella una publicación
 * (unos 2 s tras guardar un ajuste de precios). Desde 0.1.64 el cliente lo
 * reintenta en vez de mostrar «No pudimos cargar sugerencias», y los filtros del
 * catálogo reintentan sólo cuando la página viene marcada como «facetas no
 * disponibles». Aquí se prueba el bundle SERVIDO (el `.min`) sobre la página
 * real del espejo, interceptando las respuestas para que cada caso sea
 * determinista y no dependa de pillar la ventana de verdad.
 *
 * Uso:  node tools/akibara-releases/business-qa/qa-buscador-cliente-reintento.mjs
 * No escribe nada en el espejo.
 */
import { createRequire } from 'node:module';

const require = createRequire('/Users/alefvaras/Documents/akibara/tools/akibara-releases/rewards-qa/');
const { chromium } = require('playwright');

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 AkibaraQA';

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 400)}`}`);
};

const browser = await chromium.launch();

/** Página nueva con el autocompletado listo; `interceptar` decide cada respuesta del suggest. */
const abrirTienda = async (interceptar) => {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA });
  const page = await ctx.newPage();
  const llamadas = [];
  if (interceptar) {
    // Patrón CORTO: con la ruta completa la interceptación se activaba de forma
    // intermitente (medido en producción el 2026-09-14).
    await page.route('**/search/suggest**', async (route) => {
      llamadas.push(Date.now());
      await interceptar(route, llamadas.length);
    });
  }
  await page.goto(`${BASE}/tienda/`, { waitUntil: 'load' });
  return { ctx, page, llamadas };
};

const buscar = async (page, texto) => {
  const input = page.locator('[data-akb-suggest] input, input[type=search]').first();
  await input.click();
  await input.fill(texto);
};

const cincoCerosTres = (route) => route.fulfill({
  status: 503,
  headers: { 'content-type': 'application/json', 'retry-after': '1', 'cache-control': 'no-store' },
  body: JSON.stringify({ items: [], code: 'index_unavailable' }),
});

try {
  console.log('C1 · con el servidor sano, buscar muestra resultados');
  {
    const { ctx, page } = await abrirTienda(null);
    await buscar(page, 'naruto');
    await page.locator('.akb-suggest-item').first().waitFor({ timeout: 8000 }).catch(() => {});
    ok(await page.locator('.akb-suggest-item').count() > 0, 'aparecen sugerencias');
    ok(0 === await page.locator('.akb-suggest-error').count(), 'y ningún aviso de error');
    await ctx.close();
  }

  console.log('C2 · dos 503 transitorios y luego sano: el cliente no ve el cierre');
  {
    const { ctx, page, llamadas } = await abrirTienda((route, n) => (n <= 2 ? cincoCerosTres(route) : route.continue()));
    await buscar(page, 'berserk');
    await page.locator('.akb-suggest-item').first().waitFor({ timeout: 15000 }).catch(() => {});
    ok(await page.locator('.akb-suggest-item').count() > 0, 'las sugerencias llegan tras reintentar');
    ok(0 === await page.locator('.akb-suggest-error').count(), 'sin mostrar el aviso de error en ningún momento');
    ok(3 === llamadas.length, 'con exactamente tres peticiones (dos 503 y la buena)', { llamadas: llamadas.length });
    if (llamadas.length >= 3) {
      const esperas = [llamadas[1] - llamadas[0], llamadas[2] - llamadas[1]];
      ok(esperas[0] >= 900 && esperas[1] >= 1800, 'respetando la espera de 1 s y 2 s entre intentos', { esperas });
    }
    await ctx.close();
  }

  console.log('C3 · un cierre que no se abre: tras cuatro intentos, el aviso');
  {
    const { ctx, page, llamadas } = await abrirTienda((route) => cincoCerosTres(route));
    await buscar(page, 'one piece');
    await page.locator('.akb-suggest-error').waitFor({ timeout: 15000 }).catch(() => {});
    ok(1 === await page.locator('.akb-suggest-error').count(), 'se muestra el aviso de error');
    ok(4 === llamadas.length, 'después de cuatro intentos, ni uno más', { llamadas: llamadas.length });
    await ctx.close();
  }

  console.log('C4 · un 429 no se reintenta');
  {
    const { ctx, page, llamadas } = await abrirTienda((route) => route.fulfill({
      status: 429, headers: { 'content-type': 'application/json' }, body: JSON.stringify({ items: [], code: 'rate_limited' }),
    }));
    await buscar(page, 'dragon ball');
    await page.locator('.akb-suggest-error').waitFor({ timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(2500);
    ok(1 === await page.locator('.akb-suggest-error').count(), 'se muestra el aviso');
    ok(1 === llamadas.length, 'con una sola petición', { llamadas: llamadas.length });
    await ctx.close();
  }

  // ── Filtros del catálogo (PJAX) ─────────────────────────────────────────
  const filtrar = async (transformar) => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA });
    const page = await ctx.newPage();
    const pjax = [];
    await page.route('**/tienda/**', async (route) => {
      const req = route.request();
      if ('akb-pjax' !== (req.headers()['x-requested-with'] || '')) { return route.continue(); }
      pjax.push(Date.now());
      const real = await route.fetch();
      const html = await real.text();
      return route.fulfill({ response: real, body: transformar(html, pjax.length) });
    });
    await page.goto(`${BASE}/tienda/`, { waitUntil: 'load' });
    const casilla = page.locator('form.akb-facets input[type=checkbox][value=instock]').first();
    await casilla.check({ force: true });
    const aplicar = page.locator('form.akb-facets button[type=submit]:visible').first();
    if (await aplicar.count()) { await aplicar.click(); }
    await page.waitForTimeout(9000);
    return { ctx, page, pjax };
  };
  const sinFacetas = (html) => html.replace(/<form[^>]*class="[^"]*akb-facets[\s\S]*?<\/form>/g, '');
  const marcar = (html) => sinFacetas(html).replace('</head>', '<meta name="akb-search-facets" content="indisponible"></head>');

  console.log('F1 · la página llega sin facetas y MARCADA como cierre: se reintenta');
  {
    const { ctx, page, pjax } = await filtrar((html, n) => (1 === n ? marcar(html) : html));
    ok(2 === pjax.length, 'una segunda petición tras la marca', { pjax: pjax.length });
    ok(await page.locator('form.akb-facets').count() > 0, 'y las facetas siguen pintadas');
    await ctx.close();
  }

  console.log('F2 · la página llega sin facetas y SIN marca (legítimo): no se reintenta');
  {
    const { ctx, pjax } = await filtrar((html) => sinFacetas(html));
    ok(1 === pjax.length, 'una sola petición', { pjax: pjax.length });
    await ctx.close();
  }
} catch (e) {
  fallos += 1;
  console.error('FAIL: excepción', e.message);
} finally {
  await browser.close();
}

console.log(fallos ? `\n${fallos} FALLO(S)` : '\nPASS');
process.exit(fallos ? 1 : 0);
