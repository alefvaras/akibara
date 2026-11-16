// Gate de correos — vía 7 (reversión por el toggle) y vía 8 (visual de las
// pantallas del flujo de baja a 375px).
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const OUT = process.env.GATE3_OUT;
const BASE = 'http://localhost:8080';
const U = 'qa_gate3_b';
const P = 'Gate3-QA-2026!';

const midePantalla = (page, ancho) => page.evaluate((ancho) => {
  const de = document.documentElement;
  const desbordes = [];
  for (const el of document.querySelectorAll('*')) {
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    if (r.right > ancho + 1) desbordes.push(el.tagName + '.' + (el.className || '') + ' right=' + Math.round(r.right));
  }
  const btn = document.querySelector('button[type=submit], .akb-oneclick__btn, form button');
  const h1 = document.querySelector('h1');
  const cs = (e) => e ? getComputedStyle(e) : null;
  const b = cs(btn); const r = btn ? btn.getBoundingClientRect() : null;
  return {
    scrollWidth: de.scrollWidth,
    overflowH: de.scrollWidth - ancho,
    nDesbordes: desbordes.length,
    desbordes: desbordes.slice(0, 6),
    h1: h1 ? h1.textContent.trim() : null,
    h1Font: h1 ? cs(h1).fontFamily : null,
    h1Size: h1 ? cs(h1).fontSize : null,
    h1Color: h1 ? cs(h1).color : null,
    bodyBg: getComputedStyle(document.body).backgroundColor,
    boton: btn ? {
      texto: btn.textContent.trim(),
      w: Math.round(r.width), h: Math.round(r.height),
      bg: b.backgroundColor, color: b.color, fontSize: b.fontSize,
      tactil: r.height >= 44,
    } : null,
    tieneCssPropio: document.styleSheets.length,
    titulo: document.title,
  };
}, ancho);

const browser = await chromium.launch();
const informe = { pantallas: {}, toggle: {} };

// ---- vía 8: las tres pantallas del flujo, a 375 px ----
const ctx0 = await browser.newContext({ viewport: { width: 375, height: 800 } });
const p0 = await ctx0.newPage();

const urlSerie = process.env.GATE3_URL_SERIE;   // token válido, serie
const urlMala = process.env.GATE3_URL_MALA;     // token corrupto

for (const [nombre, url, metodo] of [
  ['confirmacion', urlSerie, 'GET'],
  ['listo', urlSerie, 'POST'],
  ['neutra-token-corrupto', urlMala, 'POST'],
]) {
  if (metodo === 'GET') {
    await p0.goto(url, { waitUntil: 'load' });
  } else {
    await p0.goto(url, { waitUntil: 'load' });
    const tieneForm = await p0.$('form button[type=submit]');
    if (tieneForm) { await Promise.all([p0.waitForNavigation({ waitUntil: 'load' }), p0.click('form button[type=submit]')]); }
  }
  await p0.waitForTimeout(250);
  informe.pantallas[nombre] = await midePantalla(p0, 375);
  await p0.screenshot({ path: path.join(OUT, `baja-${nombre}-375.png`), fullPage: true });
}
await ctx0.close();

// ---- vía 7: reversión por el toggle de /mi-cuenta/coleccion/ ----
const ctx = await browser.newContext({ viewport: { width: 375, height: 800 } });
const page = await ctx.newPage();
await page.goto(BASE + '/mi-cuenta/', { waitUntil: 'load' });
await page.waitForTimeout(600);
// El formulario vive dentro de un <details>: abrirlo si está plegado.
await page.evaluate(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); });
await page.waitForTimeout(300);
await page.fill('#username', U);
await page.fill('#password', P);
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[name=login]')]);
await page.goto(BASE + '/mi-cuenta/coleccion/', { waitUntil: 'load' });
await page.waitForTimeout(800);

informe.toggle.urlFinal = page.url();
informe.toggle.logueado = !/customer-login|\/mi-cuenta\/$/.test(page.url());

const toggles = await page.evaluate(() => [...document.querySelectorAll('[data-term-id], input[type=checkbox]')].map((e) => ({
  tag: e.tagName, tipo: e.type || '', term: e.getAttribute('data-term-id') || e.getAttribute('data-term') || '',
  checked: e.checked === undefined ? null : e.checked,
  cls: (e.className || '').toString().slice(0, 60),
  aria: e.getAttribute('aria-label') || e.getAttribute('aria-checked') || '',
  txt: (e.closest('label') ? e.closest('label').textContent : '').trim().slice(0, 40),
})));
informe.toggle.encontrados = toggles;
await page.screenshot({ path: path.join(OUT, 'coleccion-toggle-375.png'), fullPage: true });

fs.writeFileSync(path.join(OUT, 'informe-baja.json'), JSON.stringify(informe, null, 2));
console.log(JSON.stringify(informe, null, 2));
await browser.close();
