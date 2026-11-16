/**
 * ¿El panel pinta la vigencia VIEJA tras guardar, o la mide mal el instrumento?
 *
 * Contraste dentro del MISMO guardado, para que no haya dos guardados que
 * comparar: se lee el bloque ESTADO dos veces seguidas.
 *   A = como lo lee el QA hoy   (waitForLoadState + 400 ms, sin esperar navegación)
 *   B = tras esperar de verdad a que llegue la página con `saved=1`
 * Si A discrepa y B coincide, lo que falla es el instrumento, no el panel.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const TAB = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=taxonomy_discount`;
const log = (...a) => console.log(...a);

const VENTANAS = [
  ['2026-09-04', '2026-09-07'],
  ['2026-09-05', '2026-09-06'],
  ['2026-09-03', '2026-09-08'],
];

const abrirAcordeones = (page) =>
  page.evaluate(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); });

async function encender(page) {
  const box = page.locator('#akibara_taxonomy_discount_enabled');
  await box.waitFor({ state: 'visible', timeout: 15000 });
  if (await box.isChecked()) return;
  await box.focus();
  await page.keyboard.press('Space');
  await page.waitForTimeout(150);
}

async function vigenciaPintada(page) {
  try {
    const t = await page.locator('.akb-status__meta span', { hasText: 'Vigencia:' })
      .first().innerText({ timeout: 3000 });
    return t.replace(/\s+/g, ' ').replace('Vigencia:', '').trim();
  } catch (e) {
    return `<ilegible: ${String(e.message).split('\n')[0].slice(0, 40)}>`;
  }
}

async function pulsarGuardar(page) {
  const btn = page.locator('form button[name="akibara_panel_save"]').first();
  try {
    await btn.scrollIntoViewIfNeeded();
    await btn.click({ timeout: 4000, noWaitAfter: true });
  } catch {
    await btn.focus();
    await page.keyboard.press('Enter');
  }
}

const navegador = await chromium.launch();
const contexto = await navegador.newContext();
const page = await contexto.newPage();
let discrepaA = 0;
let discrepaB = 0;

try {
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass', PASS);
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);
  if (!(await page.locator('#wpadminbar').count())) throw new Error('no se pudo entrar al admin: ' + page.url());
  log('· sesión iniciada en', BASE);

  for (const [desde, hasta] of VENTANAS) {
    await page.goto(TAB, { waitUntil: 'load' });
    await abrirAcordeones(page);
    await encender(page);

    const antes = await vigenciaPintada(page);
    await page.locator('#akibara_taxonomy_discount_from').fill(desde);
    await page.locator('#akibara_taxonomy_discount_to').fill(hasta);
    const esperado = `${desde} → ${hasta}`;
    log(`\n— guardo ${esperado}   (el panel venía pintando «${antes}»)`);

    await pulsarGuardar(page);

    // A — la espera que usa el QA
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    await page.waitForTimeout(400);
    const urlA = page.url().includes('saved=1') ? 'ya en la página nueva' : 'AÚN en la página vieja';
    const pintadoA = await vigenciaPintada(page);
    const okA = pintadoA === esperado;
    if (!okA) discrepaA += 1;
    log(`   A  ${okA ? ' OK ' : 'DISCREPA'}  pintó «${pintadoA}»   [${urlA}]`);

    // B — esperar a que llegue de verdad la página del guardado
    await page.waitForURL(/saved=1/, { timeout: 60000 }).catch(() => log('   B  no llegó saved=1 en 60 s'));
    await abrirAcordeones(page).catch(() => {});
    const pintadoB = await vigenciaPintada(page);
    const okB = pintadoB === esperado;
    if (!okB) discrepaB += 1;
    log(`   B  ${okB ? ' OK ' : 'DISCREPA'}  pintó «${pintadoB}»`);

    const avisos = await page.$$eval('.notice, .akb-notice', (els) =>
      els.map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 200))).catch(() => []);
    avisos.forEach((a) => log(`      aviso del panel: ${a}`));
    const campos = {
      desde: await page.locator('#akibara_taxonomy_discount_from').inputValue().catch(() => '?'),
      hasta: await page.locator('#akibara_taxonomy_discount_to').inputValue().catch(() => '?'),
    };
    log(`      los campos del formulario releen: ${campos.desde} → ${campos.hasta}`);
  }
} finally {
  await navegador.close();
}

log(`\nRESUMEN  A(instrumento del QA): ${discrepaA}/${VENTANAS.length} discrepan   ·   B(espera correcta): ${discrepaB}/${VENTANAS.length} discrepan`);
