/**
 * qa-toggle-coleccion-serie.mjs — opt-out de UNA serie, ciclo del global, y
 * envío con nonce inválido. Espejo, qa.cliente2.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const USER = process.env.AKB_USER || 'qa.cliente2';
const PASS = process.env.AKB_PASS || 'QaPlaywright!2026';
const width = Number(process.argv[2] || 1280);
const height = Number(process.argv[3] || 812);

function log(step, data) { console.log('##', step, JSON.stringify(data)); }

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width, height } });
const page = await ctx.newPage();

await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await Promise.all([page.waitForLoadState('load'), page.click('#wp-submit')]);

async function estado() {
  const section = page.locator('.akb-account-collection__follow-all');
  const enabled = await section.getAttribute('data-enabled');
  const partial = await section.locator('.akb-account-collection__follow-all-partial').innerText().catch(() => null);
  const cardLocked = await page.locator('.akb-series-follow__locked').count();
  const btn = page.locator('[data-akb-series-follow]');
  const btnCount = await btn.count();
  const pressed = btnCount ? await btn.first().getAttribute('aria-pressed') : null;
  const label = btnCount ? (await btn.first().locator('.akb-series-follow__label').innerText()).trim() : null;
  return { enabled, partial, cardLocked, btnCount, pressed, label };
}

await page.goto(`${BASE}/mi-cuenta/coleccion/`, { waitUntil: 'load' });
log('estado-inicial', await estado());

// Asegurar global ON al empezar (por si quedó apagado de una corrida anterior)
let e = await estado();
if (e.enabled === 'false') {
  await page.locator('.akb-account-collection__follow-all-form button[type=submit]').click();
  await page.waitForLoadState('load');
  log('forzado-global-on', await estado());
}

// 1) Apagar UNA serie a mano (opt-out per-series) via su botón (REST + JS)
await page.locator('[data-akb-series-follow]').first().click();
await page.waitForTimeout(1200); // fetch async
log('tras-click-serie-off', await estado());
const msg = (await page.locator('.akb-series-follow__msg').first().innerText().catch(() => '')).trim();
log('mensaje-serie-off', { msg });

// Verificar contra WP-CLI que el término quedó en el opt-out
log('nota', { nota: 'ver usermeta con wp-cli aparte' });

// 2) Apagar el GLOBAL
await page.locator('.akb-account-collection__follow-all-form button[type=submit]').click();
await page.waitForLoadState('load');
log('tras-global-off', await estado());

// 3) Encender el GLOBAL de nuevo -> debe verse la frase "Salvo ... que apagaste una a una"
await page.locator('.akb-account-collection__follow-all-form button[type=submit]').click();
await page.waitForLoadState('load');
log('tras-global-on-de-nuevo', await estado());

// 4) Nonce inválido: modificar el hidden del form del GLOBAL por JS antes de enviar
await page.evaluate(() => {
  const form = document.querySelector('.akb-account-collection__follow-all-form');
  const nonceField = form.querySelector('input[name="_akb_sf_nonce"]');
  nonceField.value = 'nonce-invalido-de-prueba';
});
const before = await estado();
await page.locator('.akb-account-collection__follow-all-form button[type=submit]').click();
await page.waitForLoadState('load');
const after = await estado();
const notice = (await page.locator('.woocommerce-error, .woocommerce-message, .woocommerce-notice').allInnerTexts().catch(() => [])).join(' | ');
log('tras-nonce-invalido', { url: page.url(), before, after, notice });

await browser.close();
