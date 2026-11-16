#!/usr/bin/env node
/**
 * Lo que le cuesta al cliente ver las portadas: cuántas imágenes baja de
 * entrada, cuántos KB, cuánto salta el diseño y qué ve mientras cargan.
 *
 * Contra producción es SÓLO LECTURA (visitas normales con user-agent AkibaraQA).
 * Contra el espejo sirve para medir antes y después de un cambio.
 *
 * Uso:
 *   node tools/akibara-releases/business-qa/qa-imagenes-catalogo.mjs            # producción
 *   AKB_BASE=http://localhost:8080 node …/qa-imagenes-catalogo.mjs              # espejo
 *   AKB_CAPTURAS=1 …                                                            # guarda capturas
 *
 * Medido el 2026-09-14 en producción, antes de arreglar nada: en `/tienda/` sólo
 * 1 de 26 imágenes se difería —LiteSpeed apaga el lazy de WordPress y no hace el
 * suyo— así que 23 portadas bajo el pliegue se bajaban de entrada: 56 imágenes y
 * 1.143 KB de imagen (2.818 KB en total). El diseño NO salta (CLS 0,000-0,002)
 * porque todas declaran `width`/`height`: eso hay que conservarlo.
 *
 * 2026-09-14, qué archivo baja cada pantalla: el `sizes` del catálogo pasó a
 * declarar el ancho exacto de la portada (tema 2.1.126). Este QA comprueba en cada
 * portada que lo declarado coincide con lo pintado y que el navegador baja el
 * candidato justo: ni uno más ancho del necesario ni uno más chico. NO exige el
 * peldaño de 560 px: las portadas subidas antes de registrarlo (2026-08-17) no lo tienen a
 * propósito (su archivo de ~700 px pesa menos que un 560 hecho hoy).
 */
import { createRequire } from 'node:module';
import { mkdirSync, readdirSync } from 'node:fs';

const require = createRequire('/Users/alefvaras/Documents/akibara/tools/akibara-releases/rewards-qa/');
const { chromium, webkit } = require('playwright');

const BASE = (process.env.AKB_BASE || 'https://akibara.cl').replace(/\/$/, '');
const CAPTURAS = '1' === process.env.AKB_CAPTURAS;
const SALIDA = process.env.AKB_SALIDA || '/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/8c4c125d-9a65-4e3c-85b0-896ede88c011/scratchpad/capturas-imagenes';
const UA_MOVIL = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 AkibaraQA';

// Umbrales: lo que se considera aceptable para un cliente en móvil. Salen de la
// medición de hoy, no de una cifra de manual: la portada ya cumple, el catálogo no.
const TOPE_KB_IMAGEN = 700;
const TOPE_CLS = 0.05;

// El total de KB se INFORMA y no se cuenta como fallo: depende de cuántas
// novedades haya en la primera página. Lo que sí falla es elegir mal el archivo.
const aviso = (msg) => console.log(`  --  ${msg}`);

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 400)}`}`);
};

if (CAPTURAS) { mkdirSync(SALIDA, { recursive: true }); }
const browser = await chromium.launch();

const medir = async (nombre, ruta) => {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, userAgent: UA_MOVIL, isMobile: true, hasTouch: true, deviceScaleFactor: 3 });
  const page = await ctx.newPage();
  // Red lenta para ver lo que ve un cliente de verdad en la calle, no en fibra.
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', {
    offline: false, latency: 150,
    downloadThroughput: Math.round((900 * 1024) / 8), uploadThroughput: Math.round((300 * 1024) / 8),
    connectionType: 'cellular3g',
  });
  await page.addInitScript(() => {
    window.__cls = 0;
    new PerformanceObserver((l) => { for (const e of l.getEntries()) { if (! e.hadRecentInput) { window.__cls += e.value; } } })
      .observe({ type: 'layout-shift', buffered: true });
  });

  let bytesImagen = 0, imagenes = 0, bytesTotal = 0;
  page.on('response', (r) => {
    const h = r.headers();
    const largo = Number(h['content-length'] || 0);
    bytesTotal += largo;
    if (/image\//.test(h['content-type'] || '')) { bytesImagen += largo; imagenes += 1; }
  });

  const t0 = Date.now();
  await page.goto(`${BASE}${ruta}?nc=${Math.random()}`, { waitUntil: 'commit' });
  if (CAPTURAS) {
    for (const ms of [800, 2000, 4000]) {
      const falta = ms - (Date.now() - t0);
      if (falta > 0) { await page.waitForTimeout(falta); }
      await page.screenshot({ path: `${SALIDA}/${nombre}-${ms}ms.png` });
    }
  }
  await page.waitForLoadState('load').catch(() => {});
  await page.waitForTimeout(2500);

  const dom = await page.evaluate(() => {
    const alto = window.innerHeight;
    const imgs = Array.from(document.images);
    const portadas = imgs.filter((i) => /\/uploads\//.test(i.currentSrc || i.src));
    const bajoPliegueSinDiferir = portadas.filter((i) => i.getBoundingClientRect().top >= alto && 'lazy' !== i.loading);
    return {
      total: imgs.length,
      diferidas: imgs.filter((i) => 'lazy' === i.loading).length,
      sinMedidas: imgs.filter((i) => ! i.getAttribute('width') || ! i.getAttribute('height')).length,
      portadasSobrePliegue: portadas.filter((i) => i.getBoundingClientRect().top < alto).length,
      bajoPliegueSinDiferir: bajoPliegueSinDiferir.length,
      ejemploSinDiferir: bajoPliegueSinDiferir.slice(0, 2).map((i) => (i.currentSrc || '').split('/').pop().slice(-34)),
      cls: Number(window.__cls || 0),
      lazyEnLaPrimera: portadas.length > 0 && 'lazy' === portadas[0].loading,
    };
  });

  await ctx.close();
  return { ...dom, imagenes, kbImagen: Math.round(bytesImagen / 1024), kbTotal: Math.round(bytesTotal / 1024) };
};

try {
  // Las landings de serie y editorial importan aparte: su cabecera empuja la
  // rejilla bajo el pliegue, así que las primeras portadas pueden no verse de
  // entrada (lo advirtió el autor del arreglo, 2026-09-14).
  for (const [nombre, ruta] of [['catálogo', '/tienda/'], ['portada', '/'], ['serie', '/serie/the-climber/'], ['editorial', '/ivrea/']]) {
    console.log(`${nombre} (${BASE}${ruta}) · móvil, red lenta`);
    const m = await medir(nombre, ruta);
    console.log(`     ${m.imagenes} imágenes bajadas · ${m.kbImagen} KB de imagen · ${m.kbTotal} KB en total · CLS ${m.cls.toFixed(4)}`);
    console.log(`     ${m.total} <img> en la página · ${m.diferidas} diferidas · ${m.portadasSobrePliegue} portadas visibles de entrada`);
    ok(0 === m.bajoPliegueSinDiferir, 'ninguna portada fuera de pantalla se baja de entrada', { cuantas: m.bajoPliegueSinDiferir, ejemplo: m.ejemploSinDiferir });
    if (m.kbImagen > TOPE_KB_IMAGEN) { aviso(`primera carga de ${m.kbImagen} KB de imagen, por encima de ${TOPE_KB_IMAGEN}`); }
    ok(m.cls <= TOPE_CLS, 'el diseño no salta mientras cargan las imágenes', { cls: m.cls });
    ok(0 === m.sinMedidas, 'todas las imágenes declaran su tamaño (es lo que evita los saltos)', { sinMedidas: m.sinMedidas });
    ok(! m.lazyEnLaPrimera, 'la primera portada NO se difiere (es la que mide el tiempo de carga)');
  }

  // Qué archivo baja cada pantalla. /tienda/page/12/ trae portadas antiguas y lleva
  // barra de filtros; la landing de serie, no. Cada ruta con `?nc=` para medir el
  // HTML recién generado y no una copia de caché anterior al despliegue.
  const RUTAS_ELECCION = (process.env.AKB_RUTAS_ELECCION || '/tienda/page/12/,/serie/the-climber/').split(',');
  const UA_ESCRITORIO = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36 AkibaraQA';
  const PANTALLAS = [
    ['Android 360@3', 360, 3, true, 'chromium'], ['iPhone 390@3', 390, 3, true, 'chromium'], ['Samsung 412@2.625', 412, 2.625, true, 'chromium'],
    ['Tablet 820@2', 820, 2, true, 'chromium'], ['Laptop 1280@1.5', 1280, 1.5, false, 'chromium'], ['Retina 1440@2', 1440, 2, false, 'chromium'],
    // Safari de verdad: WebKit. Si la versión instalada no calza con Playwright, se usa la última descargada.
    ['iPhone 393@3 (WebKit)', 393, 3, true, 'webkit'],
  ];
  const lanzarWebkit = async () => {
    try { return await webkit.launch(); } catch (e) {
      const cache = `${process.env.HOME}/Library/Caches/ms-playwright`;
      const ultima = readdirSync(cache).filter((d) => /^webkit-\d+$/.test(d)).sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]))[0];
      if (!ultima) { throw e; }
      return webkit.launch({ executablePath: `${cache}/${ultima}/pw_run.sh` });
    }
  };
  const navegadorWebkit = await lanzarWebkit();
  for (const RUTA of RUTAS_ELECCION) for (const [nombre, ancho, dpr, movil, motor] of PANTALLAS) {
    console.log(`elección de archivo · ${nombre} · ${RUTA}`);
    const nav = 'webkit' === motor ? navegadorWebkit : browser;
    const ctx = await nav.newContext({ viewport: { width: ancho, height: 860 }, deviceScaleFactor: dpr, isMobile: movil, hasTouch: movil, userAgent: movil ? UA_MOVIL : UA_ESCRITORIO });
    const page = await ctx.newPage();
    await page.goto(`${BASE}${RUTA}?nc=${Math.random()}`, { waitUntil: 'load' });
    const alto = await page.evaluate(() => document.body.scrollHeight);
    for (let y = 0; y < alto; y += 500) { await page.evaluate((yy) => window.scrollTo(0, yy), y); await page.waitForTimeout(120); }
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(800);
    const e = await page.evaluate((dprPagina) => {
      const r = { portadas: 0, entrada: 0, rotas: [], sinCandidato: [], declaradoMal: [], sobrecarga: [], blandas: [] };
      // Resuelve el `sizes` como el navegador: primer tramo cuya condición se cumple,
      // medido en una caja real (así `calc()` y `vw` salen exactos).
      const sonda = document.createElement('div');
      sonda.style.cssText = 'position:absolute;left:0;top:0;height:1px;visibility:hidden';
      document.body.appendChild(sonda);
      const declarado = (sizes) => {
        for (const tramo of sizes.split(/,\s*(?![^()]*\))/)) {
          const t = tramo.trim();
          if ('auto' === t.toLowerCase()) { continue; }
          // La condición no lleva paréntesis anidados; el valor sí (`calc((100vw - …) / 3 - 4px)`).
          const m = /^(\([^()]*\))\s+(\S.*)$/.exec(t);
          if (m && !matchMedia(m[1]).matches) { continue; }
          sonda.style.width = m ? m[2] : t;
          return sonda.getBoundingClientRect().width;
        }
        return NaN;
      };
      for (const img of document.querySelectorAll('ul.products li.product img')) {
        const src = img.currentSrc || '';
        if (!/\/uploads\/portadas\//.test(src) || !img.srcset) { continue; }
        r.portadas += 1;
        const archivo = src.split('/').pop();
        if ('lazy' !== img.loading) { r.entrada += 1; }
        if (!img.complete || img.naturalWidth === 0) { r.rotas.push(archivo); continue; }
        const pintado = img.getBoundingClientRect().width;
        const dicho = declarado(img.getAttribute('sizes') || '');
        if (!(Math.abs(dicho - pintado) <= 1)) { r.declaradoMal.push(`${archivo}: sizes dice ${Math.round(dicho * 10) / 10} y mide ${pintado}`); }
        const cands = img.srcset.split(',').map((c) => c.trim().split(/\s+/)).map(([u, w]) => ({ u: new URL(u, location.href).href, w: parseInt(w, 10) })).filter((c) => c.w > 0).sort((a, b) => a.w - b.w);
        const elegido = cands.find((c) => c.u === src);
        if (!elegido) { r.sinCandidato.push(archivo); continue; }
        // Chromium redondea hacia arriba el hueco de `sizes=auto` (186,5 → 187); se le da ese
        // margen a las diferidas para no marcar como defecto de la tienda una decisión del navegador.
        const pide = ('lazy' === img.loading ? Math.ceil(pintado) : pintado) * dprPagina;
        const justo = (cands.find((c) => c.w >= pide - 1) || cands[cands.length - 1]).w;
        const mayor = cands[cands.length - 1].w;
        if (elegido.w > justo) { r.sobrecarga.push(`${archivo} pide ${Math.round(pide)} y bajó ${elegido.w} (bastaba ${justo})${'lazy' !== img.loading ? ' [de entrada]' : ''}`); }
        if (elegido.w < pide * 0.9 && mayor > elegido.w) { r.blandas.push(`${archivo} pide ${Math.round(pide)} y bajó ${elegido.w}`); }
      }
      sonda.remove();
      return r;
    }, dpr);
    console.log(`     ${e.portadas} portadas (${e.entrada} de entrada)`);
    ok(e.portadas >= 8, 'la página trae portadas reales que medir', e.portadas);
    ok(0 === e.rotas.length, 'ninguna portada rota', e.rotas.slice(0, 3));
    ok(0 === e.declaradoMal.length, 'el sizes declarado coincide con el ancho pintado (±1 px)', { cuantas: e.declaradoMal.length, ejemplo: e.declaradoMal.slice(0, 2) });
    ok(0 === e.sinCandidato.length, 'cada portada bajó uno de los candidatos de su srcset', { cuantas: e.sinCandidato.length, ejemplo: e.sinCandidato.slice(0, 2) });
    ok(0 === e.sobrecarga.length, 'ninguna baja un archivo más ancho del que necesita', { cuantas: e.sobrecarga.length, ejemplo: e.sobrecarga.slice(0, 2) });
    ok(0 === e.blandas.length, 'ninguna se ve blanda por bajar uno más chico de lo que pide', { cuantas: e.blandas.length, ejemplo: e.blandas.slice(0, 2) });
    await ctx.close();
  }
  await navegadorWebkit.close();

  // La ficha: su imagen principal es la que mide el tiempo de carga percibido,
  // así que no puede diferirse y tiene que pedirse con prioridad.
  console.log('ficha de producto · móvil');
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, userAgent: UA_MOVIL, isMobile: true, hasTouch: true, deviceScaleFactor: 3 });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/tienda/`, { waitUntil: 'load' });
    // Una ficha CON portada real: en el espejo el primer producto del catálogo
    // puede ser uno sembrado para QA sin imagen, y sin imagen no hay nada que medir.
    const url = await page.evaluate(() => {
      // Portada REAL del catálogo (`/uploads/portadas/`) y nunca un producto sembrado
      // para QA (`qaesp…`): ésos traen imagen de relleno, sin adjunto, y la ficha
      // no tiene nada que precargar (medido 2026-09-14: falso rojo dos veces).
      const enlace = Array.from(document.querySelectorAll('a.woocommerce-LoopProduct-link'))
        .find((a) => {
          const img = a.querySelector('img');
          return img && /\/uploads\/portadas\//.test(img.currentSrc || img.src) && ! /qaesp/.test(a.href);
        });
      return enlace ? enlace.href : '';
    });
    ok('' !== url, 'hay una ficha a la que entrar desde el catálogo');
    if (url) {
      await page.goto(url, { waitUntil: 'load' });
      const f = await page.evaluate(() => {
        const img = document.querySelector('.woocommerce-product-gallery img, .product .images img');
        const pre = document.querySelector('link[rel="preload"][as="image"][fetchpriority="high"]');
        return { hay: !! img, loading: img ? img.loading : null, prioridad: img ? img.getAttribute('fetchpriority') : null, preload: !! pre };
      });
      ok(f.hay, 'la ficha trae su imagen principal');
      ok('lazy' !== f.loading, 'la imagen principal de la ficha no se difiere', f);
      ok(f.preload || 'high' === f.prioridad, 'y se pide con prioridad (preload o fetchpriority)', f);
    }
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
