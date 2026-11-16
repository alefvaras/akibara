import { chromium } from 'playwright';
const BASE = 'http://localhost:8080';
const UNSUB_ALL_URL = process.argv[2];
if (!UNSUB_ALL_URL) { console.error('falta URL de baja "todas"'); process.exit(2); }

function log(step, data) { console.log('##', step, JSON.stringify(data)); }

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();

// Login primero (así el paso final "Mi cuenta" no exige credenciales)
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', 'qa.cliente2');
await page.fill('#user_pass', 'QaPlaywright!2026');
await Promise.all([page.waitForLoadState('load'), page.click('#wp-submit')]);

// GET del enlace de baja "todas" -> página de confirmación
await page.goto(UNSUB_ALL_URL, { waitUntil: 'load' });
const bodyGet = await page.locator('body').innerText();
log('confirmacion-GET', { url: page.url(), h1: (await page.locator('h1').innerText().catch(()=> '')), snippet: bodyGet.slice(0, 200) });

// POST (Confirmar baja)
await Promise.all([page.waitForLoadState('load'), page.click('button[type=submit]')]);
const bodyPost = await page.locator('body').innerText();
log('listo-POST', { url: page.url(), h1: (await page.locator('h1').innerText().catch(()=> '')), snippet: bodyPost.slice(0, 300) });

// Click en el enlace a Mi cuenta -> Colección
const link = page.locator('a', { hasText: 'Mi cuenta' });
const href = await link.getAttribute('href');
log('enlace-mi-cuenta', { href });
await page.goto(href.startsWith('http') ? href : BASE + href, { waitUntil: 'load' });
log('coleccion-tras-baja', { url: page.url() });

async function estado() {
  const section = page.locator('.akb-account-collection__follow-all');
  const enabled = await section.getAttribute('data-enabled');
  const buttonText = (await section.locator('button[type=submit]').innerText().catch(() => '')).trim();
  return { enabled, buttonText };
}
log('estado-tras-baja-por-correo', await estado());

// Encender de nuevo desde Colección
await page.locator('.akb-account-collection__follow-all-form button[type=submit]').click();
await page.waitForLoadState('load');
log('tras-encender', await estado());

await browser.close();
