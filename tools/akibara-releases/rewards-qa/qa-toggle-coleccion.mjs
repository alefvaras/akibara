/**
 * qa-toggle-coleccion.mjs — QA del interruptor «Avisos de mi colección».
 * Espejo, cliente qa.cliente2 (tiene la serie "Atelier Of Witch Hat").
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const USER = process.env.AKB_USER || 'qa.cliente2';
const PASS = process.env.AKB_PASS || 'QaPlaywright!2026';
const width = Number(process.argv[2] || 1280);
const height = Number(process.argv[3] || 900);

const out = { width, height, steps: [] };

function log(step, data) {
  out.steps.push({ step, ...data });
  console.log('##', step, JSON.stringify(data));
}

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width, height } });
const page = await ctx.newPage();

// Login
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await Promise.all([page.waitForLoadState('load'), page.click('#wp-submit')]);
log('login', { url: page.url() });

// Go to Colección
await page.goto(`${BASE}/mi-cuenta/coleccion/`, { waitUntil: 'load' });
log('coleccion-cargada', { url: page.url(), status: 'ok?' });

async function estadoActual() {
  const section = page.locator('.akb-account-collection__follow-all');
  const enabled = await section.getAttribute('data-enabled');
  const stateText = (await section.locator('.akb-account-collection__follow-all-state').innerText().catch(() => '')).trim();
  const partialText = await section.locator('.akb-account-collection__follow-all-partial').innerText().catch(() => null);
  const buttonText = (await section.locator('button[type=submit]').innerText().catch(() => '')).trim();
  const notice = (await page.locator('.woocommerce-message, .woocommerce-error, .woocommerce-notice').allInnerTexts().catch(() => [])).join(' | ');
  const cardLocked = await page.locator('.akb-series-follow__locked').count();
  const cardButton = await page.locator('[data-akb-series-follow]').count();
  return { enabled, stateText, partialText, buttonText, notice, cardLocked, cardButton };
}

log('estado-inicial', await estadoActual());

// APAGAR
await page.locator('.akb-account-collection__follow-all-form button[type=submit]').click();
await page.waitForLoadState('load');
log('tras-apagar', { url: page.url(), ...(await estadoActual()) });

// Recargar para comprobar persistencia
await page.reload({ waitUntil: 'load' });
log('tras-apagar-reload', await estadoActual());

// ENCENDER
await page.locator('.akb-account-collection__follow-all-form button[type=submit]').click();
await page.waitForLoadState('load');
log('tras-encender', { url: page.url(), ...(await estadoActual()) });

await browser.close();
console.log(JSON.stringify(out, null, 2));
