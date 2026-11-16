/**
 * Descubre, pestaña por pestaña, TODOS los controles que el dueño puede tocar:
 * nombre de la option, tipo, valor actual y etiqueta visible.
 *
 * No guarda nada. Sólo mira. Sirve para diseñar la QA funcional («cambio esto
 * y lo compruebo en la tienda») sin adivinar los nombres desde el código.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
if (!/localhost|127\.0\.0\.1/.test(BASE)) throw new Error('Sólo contra el espejo.');
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();

await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await page.click('#wp-submit');
await page.waitForSelector('#wpadminbar', { timeout: 60000 });

await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel`, { waitUntil: 'load' });
const pestanas = await page.$$eval('a[href*="page=akibara-panel"][href*="tab="]', (as) =>
  [...new Set(as.map((a) => new URL(a.href).searchParams.get('tab')).filter(Boolean))]);

const mapa = {};
for (const tab of pestanas) {
  await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${tab}`, { waitUntil: 'load' });
  await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
  const campos = await page.evaluate(() => {
    const out = [];
    for (const el of document.querySelectorAll('form input, form select, form textarea')) {
      if (['hidden', 'submit', 'button'].includes(el.type)) continue;
      if (!el.name) continue;
      const lab = el.closest('tr')?.querySelector('th')?.innerText?.trim()
        || el.closest('label')?.innerText?.trim()
        || (el.id && document.querySelector(`label[for="${el.id.replace(/([^\w-])/g, '\\$1')}"]`)?.innerText?.trim())
        || '';
      let valor = el.type === 'checkbox' || el.type === 'radio' ? (el.checked ? el.value || 'on' : '') : el.value;
      out.push({ name: el.name, tipo: el.type || el.tagName.toLowerCase(), etiqueta: String(lab).replace(/\s+/g, ' ').slice(0, 70), valor: String(valor).slice(0, 40) });
    }
    return out;
  });
  mapa[tab] = campos;
  console.log(`\n══ ${tab}  (${campos.length} controles)`);
  const vistos = new Set();
  for (const c of campos) {
    const k = c.name + c.tipo;
    if (vistos.has(k)) continue;
    vistos.add(k);
    console.log(`   ${c.tipo.padEnd(9)} ${c.name.padEnd(46)} = ${JSON.stringify(c.valor).padEnd(22)} ${c.etiqueta}`);
  }
}
await nav.close();
