/** ¿Por qué no entra al espejo? Diagnóstico del login. */
import { chromium } from 'playwright';
const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
page.on('console', (m) => { if (m.type() === 'error') console.log('  consola:', m.text().slice(0, 120)); });
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load', timeout: 60000 });
console.log('· wp-login cargado:', await page.title());
await page.fill('#user_login', process.env.AKB_USER || 'qa.owner');
await page.fill('#user_pass', process.env.AKB_PASS || 'AkbQaOwner2026!');
await Promise.all([
  page.waitForLoadState('load').catch(() => {}),
  page.click('#wp-submit'),
]);
await page.waitForTimeout(4000);
console.log('· url tras enviar:', page.url());
console.log('· título:', await page.title());
const txt = await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').slice(0, 400));
console.log('· texto:', txt);
console.log('· hay #wpadminbar:', await page.locator('#wpadminbar').count());
await page.screenshot({ path: '/tmp/qa-login-espejo.png' });
console.log('· captura en /tmp/qa-login-espejo.png');
await nav.close();
