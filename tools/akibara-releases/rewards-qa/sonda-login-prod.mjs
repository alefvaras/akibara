/** Diagnóstico del login de producción: qué pantalla sale y por qué. Sólo lectura. */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'https://akibara.cl';
const USER = process.env.AKB_USER;
const PASS = process.env.AKB_PASS;

const navegador = await chromium.launch();
const page = await (await navegador.newContext({ viewport: { width: 1280, height: 900 } })).newPage();

await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
console.log('· login url:', page.url());
console.log('· título   :', await page.title());
console.log('· campos   :', await page.evaluate(() => ({
  user: !!document.getElementById('user_login'),
  pass: !!document.getElementById('user_pass'),
  submit: !!document.getElementById('wp-submit'),
  forms: [...document.querySelectorAll('form')].map((f) => f.id || f.name || '(sin id)'),
})));
await page.screenshot({ path: 'login-prod-1.png' });

await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await Promise.all([
  page.waitForNavigation({ waitUntil: 'load' }).catch(() => {}),
  page.click('#wp-submit'),
]);
await page.waitForTimeout(1500);
console.log('· tras enviar url:', page.url());
console.log('· título         :', await page.title());
const texto = await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').slice(0, 600));
console.log('· cuerpo         :', texto);
console.log('· adminbar       :', await page.locator('#wpadminbar').count());
await page.screenshot({ path: 'login-prod-2.png', fullPage: true });
await navegador.close();
