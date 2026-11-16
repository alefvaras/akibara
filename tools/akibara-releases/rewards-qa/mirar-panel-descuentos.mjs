/** Mira el panel Descuentos como lo ve el dueño y saca foto. Sólo lectura. */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const TERM = process.env.AKB_TERM || '230';
const SALIDA = process.env.AKB_FOTO || 'panel-descuentos.png';

const navegador = await chromium.launch();
const page = await (await navegador.newContext({ viewport: { width: 1440, height: 1200 } })).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);

await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=taxonomy_discount`, { waitUntil: 'load' });
await page.evaluate(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); });
await page.waitForTimeout(500);

const lectura = await page.evaluate((term) => {
  const v = (id) => { const e = document.getElementById(id); return e ? (e.type === 'checkbox' ? e.checked : e.value) : '(no existe)'; };
  return {
    enabled: v('akibara_taxonomy_discount_enabled'),
    from: v('akibara_taxonomy_discount_from'),
    to: v('akibara_taxonomy_discount_to'),
    apply_to: v('akibara_taxonomy_discount_apply_to'),
    pct_term: v('akibara_taxonomy_discount_pct_' + term),
    avisos: [...document.querySelectorAll('.notice, .akb-notice, .akb-status-card')]
      .map((e) => e.textContent.replace(/\s+/g, ' ').trim().slice(0, 260)),
  };
}, TERM);
console.log(JSON.stringify(lectura, null, 2));
await page.screenshot({ path: SALIDA, fullPage: true });
console.log('foto:', SALIDA);
await navegador.close();
