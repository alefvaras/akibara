/**
 * ¿Cuánto tarda en llegar a /llms.txt lo que el dueño escribe en «Salud SEO»?
 *
 * La corrida anterior falló porque yo restauraba el valor a los 150 s: si el
 * refresco tardaba más, el manifiesto se regeneraba ya con el texto viejo y mi
 * prueba culpaba al producto de un defecto que era mío. Aquí NO se restaura
 * hasta haberlo visto (o hasta agotar el plazo), y se mide el retardo.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
if (!/localhost|127\.0\.0\.1/.test(BASE)) throw new Error('Sólo contra el espejo.');
const MINUTOS = Number(process.env.AKB_MINUTOS || 12);
const MARCA = 'QALLMS' + Math.floor(Math.random() * 900 + 100);

const nav = await chromium.launch();
const page = await (await nav.newContext()).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', process.env.AKB_USER || 'qa.owner');
await page.fill('#user_pass', process.env.AKB_PASS || 'AkbQaOwner2026!');
await page.click('#wp-submit');
await page.waitForSelector('#wpadminbar', { timeout: 60000 });

const panel = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=salud_seo`;
const sel = '[name="akibara_llms_identity"]';
await page.goto(panel, { waitUntil: 'load' });
const original = await page.locator(sel).first().inputValue();
console.log('original guardado (', original.length, 'chars )');

await page.locator(sel).first().fill(`${MARCA} — ${original}`);
await page.locator('form button[name="akibara_panel_save"]').first().click();
await page.waitForLoadState('load').catch(() => {});
const t0 = Date.now();
console.log('· guardado a las', new Date().toISOString(), 'marca', MARCA);

let visto = false;
while (!visto && Date.now() - t0 < MINUTOS * 60000) {
  const p = await (await nav.newContext()).newPage();
  const txt = await p.goto(`${BASE}/llms.txt`).then((r) => r.text()).catch(() => '');
  await p.context().close();
  visto = txt.includes(MARCA);
  const seg = Math.round((Date.now() - t0) / 1000);
  console.log(`  t+${String(seg).padStart(4)}s  ${visto ? 'YA APARECE' : 'todavía no'}`);
  if (!visto) await new Promise((r) => setTimeout(r, 20000));
}

console.log(visto
  ? `\nRESULTADO: el cambio llegó a /llms.txt en ~${Math.round((Date.now() - t0) / 1000)} s.`
  : `\nRESULTADO: ${MINUTOS} minutos después el cambio NO ha llegado a /llms.txt.`);

await page.goto(panel, { waitUntil: 'load' });
await page.locator(sel).first().fill(original);
await page.locator('form button[name="akibara_panel_save"]').first().click();
await page.waitForLoadState('load').catch(() => {});
console.log('· restaurado');
await nav.close();
