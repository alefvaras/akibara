// Vía 8: las tres pantallas del flujo de baja, medidas a 375 px.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const OUT = process.env.GATE3_OUT;
const hex = (c) => { const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/.exec(c || ''); if (!m) return c; if (m[4] !== undefined && +m[4] === 0) return 'transparente'; return '#' + [1, 2, 3].map((i) => (+m[i]).toString(16).padStart(2, '0')).join('').toUpperCase(); };

const mide = (page, ancho) => page.evaluate((ancho) => {
  const de = document.documentElement;
  let n = 0; const ej = [];
  for (const el of document.querySelectorAll('*')) {
    const r = el.getBoundingClientRect();
    if ((r.width || r.height) && r.right > ancho + 1) { n++; if (ej.length < 5) ej.push(el.tagName + '.' + (el.className || '').toString().slice(0, 30) + ' right=' + Math.round(r.right)); }
  }
  const h1 = document.querySelector('h1');
  const btn = document.querySelector('form button[type=submit]');
  const a = document.querySelector('a');
  const cs = (e) => e ? getComputedStyle(e) : null;
  const rb = btn ? btn.getBoundingClientRect() : null;
  return {
    titulo: document.title,
    scrollWidth: de.scrollWidth, overflowH: de.scrollWidth - ancho, nDesbordes: n, ejemplos: ej,
    hojasCss: document.styleSheets.length,
    bodyBg: getComputedStyle(document.body).backgroundColor,
    bodyColor: getComputedStyle(document.body).color,
    bodyFont: getComputedStyle(document.body).fontFamily,
    h1: h1 ? { txt: h1.textContent.trim(), size: cs(h1).fontSize, color: cs(h1).color, font: cs(h1).fontFamily } : null,
    boton: btn ? { txt: btn.textContent.trim(), w: Math.round(rb.width), h: Math.round(rb.height), bg: cs(btn).backgroundColor, color: cs(btn).color, size: cs(btn).fontSize, radio: cs(btn).borderRadius } : null,
    enlace: a ? { txt: a.textContent.trim(), color: cs(a).color, href: a.getAttribute('href') } : null,
    textoPagina: document.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 200),
    logo: !!document.querySelector('img,svg'),
  };
}, ancho);

const URLSERIE = process.env.GATE3_URL_SERIE;
const URLMALA = process.env.GATE3_URL_MALA;

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 375, height: 800 } });
const p = await ctx.newPage();
const inf = {};

await p.goto(URLSERIE, { waitUntil: 'load' }); await p.waitForTimeout(300);
inf.confirmacion = await mide(p, 375);
await p.screenshot({ path: path.join(OUT, 'baja-01-confirmacion-375.png'), fullPage: true });

await p.click('form button[type=submit]');
await p.waitForLoadState('load'); await p.waitForTimeout(300);
inf.listo = await mide(p, 375);
await p.screenshot({ path: path.join(OUT, 'baja-02-listo-375.png'), fullPage: true });

await p.goto(URLMALA, { waitUntil: 'load' }); await p.waitForTimeout(200);
await p.click('form button[type=submit]');
await p.waitForLoadState('load'); await p.waitForTimeout(300);
inf.neutraTokenCorrupto = await mide(p, 375);
await p.screenshot({ path: path.join(OUT, 'baja-03-neutra-375.png'), fullPage: true });

for (const k of Object.keys(inf)) {
  const x = inf[k];
  x.bodyBgHex = hex(x.bodyBg); x.bodyColorHex = hex(x.bodyColor);
  if (x.boton) { x.boton.bgHex = hex(x.boton.bg); x.boton.colorHex = hex(x.boton.color); }
  if (x.h1) x.h1.colorHex = hex(x.h1.color);
  if (x.enlace) x.enlace.colorHex = hex(x.enlace.color);
}

fs.writeFileSync(path.join(OUT, 'informe-pantallas-baja.json'), JSON.stringify(inf, null, 2));
console.log(JSON.stringify(inf, null, 2));
await b.close();
