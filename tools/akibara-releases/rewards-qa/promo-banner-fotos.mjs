// Recorrido visual del carril promocional, para mirarlo con ojos de dueño.
// Deja PNG en /tmp/akb-fotos/. Lo monta y lo desmonta el wrapper .sh.
import { chromium } from 'playwright';
const BASE = process.env.AKB_BASE || 'http://localhost:8080';
if (!/localhost|127\.0\.0\.1/.test(BASE)) throw new Error('solo contra el espejo');
const DIR = '/tmp/akb-fotos';
const TAB = (t) => `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${t}`;
const nb = await chromium.launch();

async function entrar(page) {
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
  await page.fill('#user_login', process.env.AKB_USER || 'qa.owner');
  await page.fill('#user_pass', process.env.AKB_PASS || 'AkbQaOwner2026!');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);
}

// 1) el panel, con la línea de estado
const ctxAdmin = await nb.newContext({ viewport: { width: 1440, height: 1000 } });
const admin = await ctxAdmin.newPage();
await entrar(admin);
await admin.goto(TAB('announcement'), { waitUntil: 'load' });
await admin.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
const fila = admin.locator('tr').filter({ has: admin.locator('#akibara_announcement_promo_text') });
await fila.scrollIntoViewIfNeeded();
await admin.screenshot({ path: `${DIR}/${process.env.AKB_FASE}-1-panel.png`, fullPage: false });
console.log('estado en el panel:', (await fila.innerText()).replace(/\s+/g, ' ').match(/Ahora mismo:[^.]*\./)?.[0] || '(sin línea de estado)');

// 2) la portada, escritorio
const ctx = await nb.newContext({ viewport: { width: 1440, height: 900 } });
const p = await ctx.newPage();
await p.goto(`${BASE}/?f=${Date.now()}`, { waitUntil: 'load' });
await p.locator('#akb-ann-bar').screenshot({ path: `${DIR}/${process.env.AKB_FASE}-2-barra-escritorio.png` });
console.log('barra:', JSON.stringify(await p.$$eval('#akb-ann-bar .akb-ann-msg-inner', (e) => e.map((x) => x.textContent.trim()))));

// 3) la portada en un móvil de 360px — el ancho donde el mensaje se corta
const mob = await (await nb.newContext({ viewport: { width: 360, height: 740 }, isMobile: true, deviceScaleFactor: 2 })).newPage();
await mob.goto(`${BASE}/?f=${Date.now()}`, { waitUntil: 'load' });
const barra = mob.locator('#akb-ann-bar');
await barra.screenshot({ path: `${DIR}/${process.env.AKB_FASE}-3-barra-movil-360.png` });
const cortado = await mob.$eval('#akb-ann-bar .akb-ann-msg.akb-on .akb-ann-msg-inner',
  (e) => ({ texto: e.textContent.trim(), scroll: e.scrollWidth, visible: e.clientWidth, cortado: e.scrollWidth > e.clientWidth + 1 }));
console.log('móvil 360px:', JSON.stringify(cortado));

// 4) una ficha de Ivrea Argentina en stock
if (process.env.AKB_FICHA) {
  await p.goto(`${BASE}/?p=${process.env.AKB_FICHA}&f=${Date.now()}`, { waitUntil: 'load' });
  await p.screenshot({ path: `${DIR}/${process.env.AKB_FASE}-4-ficha.png`, fullPage: false });
  const precio = await p.$eval('.summary .price, p.price', (e) => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => '(sin precio)');
  console.log('ficha:', precio);
}
await nb.close();
