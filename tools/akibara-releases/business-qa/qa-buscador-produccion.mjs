#!/usr/bin/env node
/**
 * El buscador de akibara.cl visto desde el navegador de un cliente REAL.
 *
 * Complementa a las pruebas del espejo: acá se ejercita el bundle que producción
 * sirve de verdad (`.min` con su `?ver`, pasando por LiteSpeed y Cloudflare), en
 * escritorio y en móvil, y se comprueba que el reintento del 503 transitorio que
 * trajo 0.1.64 está VIVO en producción, no sólo en el espejo.
 *
 * SÓLO LECTURA: no envía formularios, no toca el carrito, no guarda nada. Los
 * casos de error se provocan interceptando la respuesta EN EL NAVEGADOR, así que
 * producción nunca ve un estado inventado. El user-agent lleva «AkibaraQA».
 *
 * Uso:  node tools/akibara-releases/business-qa/qa-buscador-produccion.mjs
 */
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

const require = createRequire('/Users/alefvaras/Documents/akibara/tools/akibara-releases/rewards-qa/');
const { chromium } = require('playwright');

const BASE = process.env.AKB_BASE || 'https://akibara.cl';
const REPO = '/Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-search';
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 AkibaraQA';
const UA_MOVIL = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 AkibaraQA';

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 400)}`}`);
};

const browser = await chromium.launch();

/**
 * Contexto nuevo con la consola vigilada. Un error de JS no roto a la vista
 * igual rompe el autocompletado del siguiente cliente, así que cuenta como rojo.
 */
const abrir = async ({ movil = false, interceptarSuggest = null } = {}) => {
  const ctx = await browser.newContext(movil
    ? { viewport: { width: 390, height: 844 }, userAgent: UA_MOVIL, isMobile: true, hasTouch: true, deviceScaleFactor: 3 }
    : { viewport: { width: 1280, height: 900 }, userAgent: UA });
  const page = await ctx.newPage();
  const errores = [];
  const llamadas = [];
  // Producción sirve los JS COMBINADOS por LiteSpeed: en el DOM no hay ningún
  // `<script src=…akb-search…>`, aunque el bundle sí se pide por red. Por eso lo
  // servido se comprueba mirando las peticiones, no las etiquetas.
  const pedidos = [];
  page.on('request', (r) => { if (/akb-search|search-suggest/.test(r.url())) { pedidos.push(r.url()); } });
  const respuestas = [];
  page.on('response', (r) => { if (/search\/(suggest|popular)/.test(r.url())) { respuestas.push(r.status()); } });
  page.on('pageerror', (e) => errores.push(String(e.message).slice(0, 200)));
  // El navegador anota en consola los 503/429 que ESTA prueba provoca a
  // propósito; eso no es un defecto del sitio. Se filtra sólo esa forma exacta:
  // cualquier otro error de consola, y todo error de JavaScript, sigue contando.
  const ruidoSimulado = /Failed to load resource.*(503|429)|the server responded with a status of (503|429)/i;
  page.on('console', (m) => { if ('error' === m.type() && ! ruidoSimulado.test(m.text())) { errores.push(m.text().slice(0, 200)); } });
  if (interceptarSuggest) {
    // Patrón CORTO a propósito: con la ruta completa la interceptación se
    // activaba de forma intermitente en producción (medido 2026-09-14) y la
    // prueba parecía verde sin haber ejercitado nada.
    await page.route('**/search/suggest**', async (route) => {
      llamadas.push(Date.now());
      await interceptarSuggest(route, llamadas.length);
    });
  }
  return { ctx, page, errores, llamadas, pedidos, respuestas };
};

/** Espera a que se cumpla una condición, en vez de adivinar un tiempo. */
const esperarHasta = async (cond, ms = 20000) => {
  const hasta = Date.now() + ms;
  while (Date.now() < hasta) {
    if (await cond()) { return true; }
    await new Promise((r) => setTimeout(r, 250));
  }
  return false;
};

const cerrado503 = (route) => route.fulfill({
  status: 503,
  headers: { 'content-type': 'application/json', 'retry-after': '1', 'cache-control': 'no-store' },
  body: JSON.stringify({ items: [], code: 'index_unavailable' }),
});

/**
 * Abre el buscador (en móvil la lupa despliega una hoja a pantalla completa) y
 * TECLEA. `fill()` no vale: el autocompletado escucha el tecleo, así que rellenar
 * el campo por código deja la pantalla en blanco y parece un defecto del sitio.
 */
const buscar = async (page, texto) => {
  // El disparador NO es el mismo en los dos tamaños: en escritorio es la lupa de
  // la cabecera y en móvil el botón «Buscar» de la barra inferior, que despliega
  // la hoja a pantalla completa (medido en producción el 2026-09-14).
  for (const sel of ['#bottom-nav-search', '.akibara-search__lens', 'button[aria-label="Buscar"]']) {
    const boton = page.locator(sel).first();
    if (await boton.count() && await boton.isVisible().catch(() => false)) {
      await boton.click();
      await page.waitForTimeout(600);
      break;
    }
  }
  const input = page.locator('[data-akb-suggest]:visible').first();
  await input.waitFor({ state: 'visible', timeout: 10000 });
  await input.click();
  await page.keyboard.type(texto, { delay: 90 });
};

try {
  console.log('P0 · lo que producción sirve es lo que hay en el repo');
  {
    const { ctx, page, errores } = await abrir();
    const resp = await page.goto(`${BASE}/tienda/`, { waitUntil: 'load' });
    ok(200 === resp.status(), 'la tienda responde', { estado: resp.status() });
    // LiteSpeed COMBINA los JS en producción: en una página cacheada no hay
    // petición propia del bundle, va dentro del fichero combinado. Así que lo
    // servido se comprueba pidiendo cada fichero, y que el código esté VIVO en la
    // página se comprueba con el objeto que publica y con el reintento de P3.
    ok('object' === await page.evaluate(() => typeof window.AKB_SEARCH), 'el buscador está cableado en la página (AKB_SEARCH)');
    const bundles = [
      ['assets/js/search-suggest.js', 'assets/js/search-suggest.min.js'],
      ['assets/js/akb-search.js', 'assets/js/akb-search.min.js'],
      ['assets/css/search-suggest.css', 'assets/css/search-suggest.min.css'],
      ['assets/css/akb-search.css', 'assets/css/akb-search.min.css'],
    ];
    for (const [fuente, min] of bundles) {
      const r = await page.request.get(`${BASE}/wp-content/plugins/akibara-search/${min}`);
      const cuerpo = await r.text();
      const banner = (cuerpo.slice(0, 200).match(/[0-9a-f]{64}/) || [])[0];
      const real = createHash('sha256').update(readFileSync(`${REPO}/${fuente}`)).digest('hex');
      ok(200 === r.status() && banner === real, `${min.split('/').pop()} servido corresponde al fuente del repo`, { estado: r.status(), banner, real });
    }
    ok(0 === errores.length, 'sin errores de JavaScript en la consola', errores);
    await ctx.close();
  }

  console.log('P1 · escritorio: buscar muestra resultados de verdad');
  {
    const { ctx, page, errores } = await abrir();
    await page.goto(`${BASE}/`, { waitUntil: 'load' });
    await buscar(page, 'berserk');
    await page.locator('.akb-suggest-item').first().waitFor({ timeout: 12000 }).catch(() => {});
    const n = await page.locator('.akb-suggest-item').count();
    ok(n > 0, `aparecen sugerencias (${n})`);
    ok(0 === await page.locator('.akb-suggest-error').count(), 'sin aviso de error');
    const titulos = await page.locator('.akb-suggest-item').allInnerTexts();
    ok(titulos.some((t) => /berserk/i.test(t)), 'y hablan de lo buscado', titulos.slice(0, 3));
    ok(0 === errores.length, 'sin errores de JavaScript', errores);
    await ctx.close();
  }

  console.log('P2 · móvil: la hoja de búsqueda funciona y el resultado abre su ficha');
  {
    const { ctx, page, errores, respuestas } = await abrir({ movil: true });
    await page.goto(`${BASE}/`, { waitUntil: 'load' });
    await buscar(page, 'naruto');
    // Cada sugerencia ES el enlace (`<a class="akb-suggest-item">`), no lo lleva dentro.
    const primera = page.locator('a.akb-suggest-item:visible').first();
    await primera.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
    ok(await page.locator('.akb-suggest-item').count() > 0, 'aparecen sugerencias en móvil', { respuestas });
    if (await primera.count()) {
      const [nav] = await Promise.all([
        page.waitForNavigation({ waitUntil: 'load', timeout: 20000 }).catch(() => null),
        primera.click(),
      ]);
      ok(nav && 200 === nav.status(), 'y al tocar la primera se abre su ficha', { estado: nav && nav.status() });
      ok(await page.locator('.single-product, .product, article').first().count() > 0, 'la ficha trae producto');
    } else {
      ok(false, 'la primera sugerencia es pulsable', { respuestas, items: await page.locator('.akb-suggest-item').count() });
    }
    ok(0 === errores.length, 'sin errores de JavaScript en móvil', errores);
    await ctx.close();
  }

  console.log('P3 · con el servidor cerrado un instante, el cliente NO ve el error');
  {
    const { ctx, page, llamadas, errores, respuestas } = await abrir({ interceptarSuggest: (route, n) => (n <= 2 ? cerrado503(route) : route.continue()) });
    await page.goto(`${BASE}/`, { waitUntil: 'load' });
    await buscar(page, 'chainsaw');
    // El cliente reintenta a 1 s y 2 s: se ESPERA al tercer intento en vez de
    // medir a ciegas. Y `.akb-suggest-item` también lo pintan los populares, así
    // que no sirve como señal de que llegó la respuesta buena.
    await esperarHasta(async () => llamadas.length >= 3, 20000);
    await esperarHasta(async () => (await page.locator('a.akb-suggest-item:visible').count()) > 0, 10000);
    ok(await page.locator('a.akb-suggest-item:visible').count() > 0, 'las sugerencias llegan tras reintentar', { llamadas: llamadas.length, respuestas });
    ok(0 === await page.locator('.akb-suggest-error').count(), 'y nunca se muestra el aviso');
    ok(3 === llamadas.length, 'con tres peticiones: dos cerradas y la buena', { llamadas: llamadas.length, respuestas });
    ok(0 === errores.length, 'sin errores de JavaScript', errores);
    await ctx.close();
  }

  console.log('P4 · si el cierre no se abre, el aviso llega (y el 429 no se reintenta)');
  {
    const { ctx, page, llamadas } = await abrir({ interceptarSuggest: (route) => cerrado503(route) });
    await page.goto(`${BASE}/`, { waitUntil: 'load' });
    await buscar(page, 'dragon ball');
    await esperarHasta(async () => llamadas.length >= 4, 25000);
    await page.locator('.akb-suggest-error').waitFor({ timeout: 10000 }).catch(() => {});
    ok(1 === await page.locator('.akb-suggest-error').count(), 'se muestra el aviso');
    ok(4 === llamadas.length, 'tras cuatro intentos, ni uno más', { llamadas: llamadas.length });
    await ctx.close();
  }
  {
    const { ctx, page, llamadas } = await abrir({ interceptarSuggest: (route) => route.fulfill({
      status: 429, headers: { 'content-type': 'application/json' }, body: JSON.stringify({ items: [], code: 'rate_limited' }),
    }) });
    await page.goto(`${BASE}/`, { waitUntil: 'load' });
    await buscar(page, 'jujutsu');
    await page.locator('.akb-suggest-error').waitFor({ timeout: 12000 }).catch(() => {});
    ok(1 === await page.locator('.akb-suggest-error').count(), 'el 429 avisa de inmediato');
    ok(1 === llamadas.length, 'con una sola petición', { llamadas: llamadas.length });
    await ctx.close();
  }

  console.log('P5 · filtrar el catálogo sigue funcionando (PJAX)');
  {
    const { ctx, page, errores } = await abrir();
    await page.goto(`${BASE}/tienda/`, { waitUntil: 'load' });
    const antes = await page.locator('ul.products li, li.product').count();
    ok(await page.locator('form.akb-facets').count() > 0, 'la tienda trae sus filtros');
    const casilla = page.locator('form.akb-facets input[type=checkbox]').first();
    await casilla.check({ force: true });
    const aplicar = page.locator('form.akb-facets button[type=submit]:visible').first();
    if (await aplicar.count()) { await aplicar.click(); }
    await page.waitForTimeout(6000);
    const despues = await page.locator('ul.products li, li.product').count();
    ok(await page.locator('form.akb-facets').count() > 0, 'los filtros siguen pintados tras filtrar');
    ok(despues > 0, `y el catálogo sigue mostrando productos (${antes} → ${despues})`);
    ok(/[?&]_/.test(page.url()), 'la dirección refleja el filtro', page.url().slice(-80));
    ok(0 === errores.length, 'sin errores de JavaScript al filtrar', errores);
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
