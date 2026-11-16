// Vía 7 del gate: reversión del aviso de serie con el toggle de /mi-cuenta/coleccion/.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const OUT = process.env.GATE3_OUT;
const BASE = 'http://localhost:8080';
const U = 'qa_gate3_b';
const P = 'Gate3-QA-2026!';
const TERM = process.env.GATE3_TERM || '378';

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 375, height: 800 } });
const page = await ctx.newPage();
const informe = {};

await page.goto(BASE + '/mi-cuenta/', { waitUntil: 'load' });
await page.waitForTimeout(1200);
// El formulario vive tras un <details>: se abre haciendo clic en su summary,
// como haría el cliente (poner `.open = true` lo revierte el JS de la página).
const sum = await page.$('details.akb-account-disclosure > summary');
if (sum) { await sum.click(); }
await page.waitForTimeout(700);
await page.screenshot({ path: path.join(OUT, 'diag-micuenta.png'), fullPage: true });
console.log('DIAG', JSON.stringify(await page.evaluate(() => {
  const u = document.querySelector('#username');
  let e = u; const cad = [];
  while (e && e !== document.documentElement) {
    const c = getComputedStyle(e);
    cad.push(e.tagName + '.' + (e.className || '').toString().slice(0, 40) + ' d=' + c.display + ' v=' + c.visibility + ' o=' + c.opacity + ' open=' + (e.open ?? '-'));
    e = e.parentElement;
  }
  return { url: location.href, titulo: document.title, cadena: cad };
}), null, 1));
await page.waitForSelector('#username', { state: 'visible', timeout: 20000 });
await page.fill('#username', U);
await page.fill('#password', P);
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button.woocommerce-form-login__submit')]);
informe.urlTrasLogin = page.url();

await page.goto(BASE + '/mi-cuenta/coleccion/', { waitUntil: 'load' });
await page.waitForTimeout(1500);
informe.urlColeccion = page.url();
informe.tituloColeccion = await page.title();

informe.controles = await page.evaluate(() => {
  const out = [];
  for (const e of document.querySelectorAll('input[type=checkbox], button[data-term-id], [data-akb-follow], [class*=follow], [class*=avisar]')) {
    const r = e.getBoundingClientRect();
    out.push({
      tag: e.tagName, tipo: e.type || '',
      cls: (e.className || '').toString().slice(0, 70),
      term: e.getAttribute('data-term-id') || e.getAttribute('data-term') || e.value || '',
      checked: e.checked === undefined ? null : e.checked,
      aria: e.getAttribute('aria-checked') || e.getAttribute('aria-label') || '',
      w: Math.round(r.width), h: Math.round(r.height),
      texto: (e.closest('label')?.textContent || e.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 50),
    });
  }
  return out;
});

await page.screenshot({ path: path.join(OUT, 'coleccion-toggle-375.png'), fullPage: true });

// El toggle real: se acciona por la misma ruta REST que usa el JS de la página,
// con la sesión y el nonce del navegador (no un curl a ciegas).
informe.resultadoToggle = await page.evaluate(async (term) => {
  const nonce = window.wpApiSettings?.nonce
    || document.querySelector('[data-rest-nonce]')?.getAttribute('data-rest-nonce')
    || window.akbSeriesFollow?.nonce
    || (window.akbColeccion && window.akbColeccion.nonce)
    || null;
  const claves = Object.keys(window).filter((k) => /akb|nonce|rest/i.test(k));
  if (!nonce) return { error: 'sin nonce en window', claves };
  const r = await fetch('/wp-json/akibara/v1/series-follow', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body: JSON.stringify({ term_id: Number(term), follow: true }),
  });
  return { status: r.status, body: await r.text(), claves };
}, TERM);

fs.writeFileSync(path.join(OUT, 'informe-toggle.json'), JSON.stringify(informe, null, 2));
console.log(JSON.stringify(informe, null, 2));
await browser.close();
