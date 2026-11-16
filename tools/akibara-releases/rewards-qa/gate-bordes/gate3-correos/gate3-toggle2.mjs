// Vía 7: reversión REAL con clic en el toggle «Avisarme» de /mi-cuenta/coleccion/.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const OUT = process.env.GATE3_OUT;
const BASE = 'http://localhost:8080';
const U = process.env.GATE3_USER;
const P = 'Gate3-QA-2026!';

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 375, height: 800 } });
const page = await ctx.newPage();
const inf = {};

await page.goto(BASE + '/mi-cuenta/', { waitUntil: 'load' });
await page.waitForTimeout(900);
const sum = await page.$('details.akb-account-disclosure > summary');
if (sum) await sum.click();
await page.waitForSelector('#username', { state: 'visible', timeout: 20000 });
await page.fill('#username', U);
await page.fill('#password', P);
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button.woocommerce-form-login__submit')]);

await page.goto(BASE + '/mi-cuenta/coleccion/', { waitUntil: 'load' });
await page.waitForTimeout(1500);
inf.url = page.url();

const inventario = async () => page.evaluate(() => {
  const out = [];
  for (const e of document.querySelectorAll('input[type=checkbox], button, [role=switch]')) {
    const t = (e.closest('label')?.textContent || e.getAttribute('aria-label') || e.textContent || '').replace(/\s+/g, ' ').trim();
    if (!/avis/i.test(t) && !/avis/i.test((e.className || '').toString())) continue;
    const r = e.getBoundingClientRect();
    out.push({
      tag: e.tagName, cls: (e.className || '').toString().slice(0, 70),
      term: e.getAttribute('data-term-id') || e.value || '',
      checked: e.checked ?? null, aria: e.getAttribute('aria-checked'),
      w: Math.round(r.width), h: Math.round(r.height), texto: t.slice(0, 60),
      visible: r.width > 0 && r.height > 0,
    });
  }
  return out;
});

inf.antes = await inventario();
await page.screenshot({ path: path.join(OUT, 'coleccion-toggle-antes-375.png'), fullPage: true });

// Clic real en el primer control de aviso.
const sel = '.akb-series-follow__toggle, button[data-term-id], input[type=checkbox][data-term-id]';
const candidatos = await page.$$(sel);
inf.nCheckboxes = candidatos.length;
let clicado = null;
for (const c of candidatos) {
  const t = await c.evaluate((e) => e.textContent.trim() + ' | term=' + (e.getAttribute('data-term-id') || ''));
  await c.scrollIntoViewIfNeeded();
  await c.click();
  clicado = t;
  break;
}
inf.clicado = clicado;
await page.waitForTimeout(1500);
inf.despues = await inventario();
await page.screenshot({ path: path.join(OUT, 'coleccion-toggle-despues-375.png'), fullPage: true });

// Desborde horizontal de la propia pantalla de colección a 375.
inf.visual = await page.evaluate(() => {
  const de = document.documentElement;
  let n = 0; const l = [];
  for (const el of document.querySelectorAll('*')) {
    const r = el.getBoundingClientRect();
    if ((r.width || r.height) && r.right > 376) { n++; if (l.length < 5) l.push(el.tagName + '.' + (el.className || '').toString().slice(0, 30)); }
  }
  return { scrollWidth: de.scrollWidth, overflowH: de.scrollWidth - 375, nDesbordes: n, ejemplos: l };
});

fs.writeFileSync(path.join(OUT, 'informe-toggle2.json'), JSON.stringify(inf, null, 2));
console.log(JSON.stringify(inf, null, 2));
await browser.close();
