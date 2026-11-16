// ¿QUIÉN provoca el scroll horizontal de la página? Recorre el DOM y lista
// todo lo que sobresale del ancho del viewport, con su cadena de ancestros.
import { chromium } from 'playwright';

const ancho = Number(process.argv[2] || 375);
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: ancho, height: 812 } });
const page = await ctx.newPage();
await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });
const sec = await page.$('.akb-rw-carousel');
if (sec) { await sec.scrollIntoViewIfNeeded(); await page.waitForTimeout(500); }

const out = await page.evaluate((vw) => {
  const res = { doc: document.documentElement.scrollWidth, vw, culpables: [] };
  for (const el of document.querySelectorAll('body *')) {
    const b = el.getBoundingClientRect();
    const der = Math.round(b.right + window.scrollX);
    if (der <= vw + 1) continue;
    // cadena de ancestros con su overflow-x
    const cadena = [];
    let p = el.parentElement;
    while (p && p !== document.body && cadena.length < 6) {
      cadena.push(`${p.tagName}.${(p.className || '').toString().split(' ')[0]}[ox=${getComputedStyle(p).overflowX}]`);
      p = p.parentElement;
    }
    res.culpables.push({
      der,
      tag: el.tagName,
      cls: (el.className || '').toString().slice(0, 70),
      w: Math.round(b.width),
      ox: getComputedStyle(el).overflowX,
      cadena: cadena.join(' < '),
    });
  }
  res.culpables.sort((a, b) => b.der - a.der);
  res.culpables = res.culpables.slice(0, 8);
  return res;
}, ancho);

console.log(JSON.stringify(out, null, 2));
await browser.close();
