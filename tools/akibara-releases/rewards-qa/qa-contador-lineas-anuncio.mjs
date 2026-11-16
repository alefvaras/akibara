import { chromium } from 'playwright';
const BASE = 'http://localhost:8080';

function log(step, data) { console.log('##', step, JSON.stringify(data)); }

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();

await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', 'ale.fvaras');
await page.fill('#user_pass', 'QaAdmin!2026');
await Promise.all([page.waitForLoadState('load'), page.click('#wp-submit')]);
log('login', { url: page.url() });

await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=announcement`, { waitUntil: 'load' });
log('tab-cargado', { url: page.url(), title: await page.title() });

const textarea = page.locator('textarea[data-akb-line-max]').first();
const idAttr = await textarea.getAttribute('id');
log('primer-textarea', { id: idAttr });

// Guardar valor original para restaurar
const original = await textarea.inputValue();

const linea60 = 'A'.repeat(60);
const texto3lineas = `Primera línea corta\n${linea60}\nOtra línea corta`;
await textarea.fill(texto3lineas);
await page.waitForTimeout(200);
const counter = page.locator('.akb-line-counter').first();
const counterText = (await counter.innerText()).trim();
const isOver = (await counter.getAttribute('class')) || '';
log('tres-lineas-una-larga', { counterText, classes: isOver });

// Ahora con líneas cortas -> sin is-over
await textarea.fill('Envíos gratis sobre $60.000\nNuevo tomo cada semana');
await page.waitForTimeout(200);
const counterText2 = (await counter.innerText()).trim();
const isOver2 = (await counter.getAttribute('class')) || '';
log('lineas-cortas', { counterText: counterText2, classes: isOver2 });

// Restaurar valor original y guardar
await textarea.fill(original);
await page.waitForTimeout(200);

// Probar que Guardar sigue funcionando: click en submit del formulario de este tab
const submit = page.locator('form#mainform button[type=submit], form#mainform input[type=submit]').first();
const submitExists = await submit.count();
log('submit-existe', { submitExists });
if (submitExists) {
  await Promise.all([page.waitForLoadState('load'), submit.click()]);
  const notice = (await page.locator('.notice, .updated').allInnerTexts().catch(() => [])).join(' | ');
  log('tras-guardar', { url: page.url(), notice });
}

await browser.close();
