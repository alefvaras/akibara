/**
 * Pone (o quita) la promoción por editorial DESDE LA INTERFAZ OFICIAL.
 *
 * No es un script de configuración: es el mismo camino que recorre el dueño con
 * el ratón y el teclado. Si algo del panel está roto, esto se entera; un
 * `wp option update` no.
 *
 * Uso:
 *   node poner-promo-editorial.mjs poner   # enciende la promo de la ventana dada
 *   node poner-promo-editorial.mjs quitar  # la apaga (segunda pasada, ver nota)
 *
 * Entorno:
 *   AKB_BASE   http://localhost:8080 por defecto. Para producción hace falta
 *              además AKB_PERMITIR_PRODUCCION=si — la barrera es a propósito.
 *   AKB_USER / AKB_PASS   credenciales (en producción vienen del archivo 0600
 *              que crea ops/akibara-ui-temp-admin.sh; nunca se escriben aquí).
 *   AKB_TERM   id del término editorial. 230 = Ivrea Argentina.
 *   AKB_DESDE / AKB_HASTA   AAAA-MM-DD, ambos extremos incluidos.
 *   AKB_PCT    porcentaje entero.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const ES_ESPEJO = /localhost|127\.0\.0\.1/.test(BASE);
if (!ES_ESPEJO && process.env.AKB_PERMITIR_PRODUCCION !== 'si') {
  throw new Error('Vas contra producción. Ensaya primero en el espejo y luego pasa AKB_PERMITIR_PRODUCCION=si.');
}
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const TERM = process.env.AKB_TERM || '230';
const DESDE = process.env.AKB_DESDE || '';
const HASTA = process.env.AKB_HASTA || '';
const PCT = process.env.AKB_PCT || '10';
const MODO = process.argv[2] || 'poner';

const TAB = (t) => `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${t}`;
const log = (...a) => console.log(...a);
let fallos = 0;
const comprobar = (nombre, ok, detalle = '') => {
  log(`${ok ? '  OK  ' : ' FALLA'} ${nombre}${detalle ? ' — ' + detalle : ''}`);
  if (!ok) fallos += 1;
};

async function entrar(page) {
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass', PASS);
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);
  if (!(await page.locator('#wpadminbar').count())) throw new Error('no se pudo entrar al admin');
  log('· sesión iniciada como', USER, 'en', BASE);
}

/** Los ajustes viven dentro de <details> cerrados: con el acordeón cerrado sus inputs miden 0×0. */
async function abrirAcordeones(page) {
  await page.evaluate(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); });
}

/** La barra flotante «cambios sin guardar» intercepta los clics: teclado como plan B. */
async function guardar(page) {
  const btn = page.locator('form button[name="akibara_panel_save"]').first();
  try {
    await btn.scrollIntoViewIfNeeded();
    await btn.click({ timeout: 4000 });
  } catch {
    await btn.focus();
    await page.keyboard.press('Enter');
  }
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForTimeout(400);
}

/** El interruptor se mueve con Espacio, no con el ratón: la barra flotante se come los clics. */
async function ponerInterruptor(page, activo) {
  const box = page.locator('#akibara_taxonomy_discount_enabled');
  await box.waitFor({ state: 'visible', timeout: 15000 });
  if ((await box.isChecked()) === activo) return;
  await box.focus();
  await page.keyboard.press('Space');
  await page.waitForTimeout(150);
  if ((await box.isChecked()) !== activo) throw new Error(`No se pudo dejar el interruptor en ${activo}`);
}

async function leerBarra(contexto) {
  const p = await contexto.newPage();
  // LiteSpeed cachea por URL completa: sin parámetro único se lee la barra anterior.
  await p.goto(`${BASE}/?akbqa=${Date.now()}${Math.random().toString(36).slice(2)}`, { waitUntil: 'load' });
  const lineas = await p.$$eval('#akb-ann-bar .akb-ann-msg-inner', (els) => els.map((e) => e.textContent.trim()));
  await p.close();
  return lineas;
}

const navegador = await chromium.launch();
const contexto = await navegador.newContext();
const page = await contexto.newPage();

try {
  await entrar(page);

  if (MODO === 'poner') {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(DESDE) || !/^\d{4}-\d{2}-\d{2}$/.test(HASTA)) {
      throw new Error('AKB_DESDE y AKB_HASTA son obligatorias en formato AAAA-MM-DD');
    }
    await page.goto(TAB('taxonomy_discount'), { waitUntil: 'load' });
    await abrirAcordeones(page);
    // SIEMPRE con el interruptor encendido: el panel oculta vigencia y
    // porcentajes mientras está apagado, y un input oculto no viaja en el POST.
    await ponerInterruptor(page, true);

    const desde = page.locator('#akibara_taxonomy_discount_from');
    await desde.waitFor({ state: 'visible', timeout: 8000 });
    await desde.fill(DESDE);
    await page.locator('#akibara_taxonomy_discount_to').fill(HASTA);

    const aplicar = page.locator('#akibara_taxonomy_discount_apply_to');
    if (await aplicar.count()) await aplicar.selectOption('instock_only').catch(() => {});

    const campo = page.locator(`#akibara_taxonomy_discount_pct_${TERM}`);
    if (!(await campo.count())) throw new Error(`No existe el campo del término ${TERM} en esta base.`);
    await campo.fill(String(PCT));

    await guardar(page);
  } else {
    await page.goto(TAB('taxonomy_discount'), { waitUntil: 'load' });
    await abrirAcordeones(page);
    await ponerInterruptor(page, false);
    await guardar(page);
  }

  // --- lo que ve el dueño al volver a entrar, no lo que creemos haber escrito ---
  await page.goto(TAB('taxonomy_discount'), { waitUntil: 'load' });
  await abrirAcordeones(page);
  const leido = {
    activo: await page.locator('#akibara_taxonomy_discount_enabled').isChecked(),
    desde: await page.locator('#akibara_taxonomy_discount_from').inputValue().catch(() => ''),
    hasta: await page.locator('#akibara_taxonomy_discount_to').inputValue().catch(() => ''),
    pct: await page.locator(`#akibara_taxonomy_discount_pct_${TERM}`).inputValue().catch(() => ''),
    aplicar: await page.locator('#akibara_taxonomy_discount_apply_to').inputValue().catch(() => ''),
  };
  log('· el panel relee:', JSON.stringify(leido));

  const aviso = await page.locator('.notice, .akb-notice').first().innerText().catch(() => '');
  if (aviso) log('· aviso del panel:', aviso.replace(/\s+/g, ' ').slice(0, 200));

  if (MODO === 'poner') {
    comprobar('el interruptor quedó encendido', leido.activo === true);
    comprobar('la vigencia quedó guardada', leido.desde === DESDE && leido.hasta === HASTA, `${leido.desde} → ${leido.hasta}`);
    comprobar('el porcentaje del término quedó guardado', String(leido.pct) === String(PCT), `${leido.pct}%`);
    comprobar('aplica sólo a lo disponible', leido.aplicar === 'instock_only', leido.aplicar);
  } else {
    comprobar('el interruptor quedó apagado', leido.activo === false);
  }

  // --- estado en vivo que publica el panel de la barra (1.115.0) ---
  await page.goto(TAB('announcement'), { waitUntil: 'load' });
  await abrirAcordeones(page);
  const filaPromo = await page.locator('tr').filter({ has: page.locator('#akibara_announcement_promo_text') })
    .innerText().catch(() => '');
  log('· estado en vivo de la barra:', filaPromo.replace(/\s+/g, ' ').slice(0, 300));

  // --- lo que ve el cliente ---
  const lineas = await leerBarra(contexto);
  log('· líneas visibles de la barra:', JSON.stringify(lineas));
  if (MODO === 'poner') {
    comprobar('la barra muestra la línea de promo', lineas.some((l) => /finde/i.test(l)), JSON.stringify(lineas));
    comprobar('la línea trae el porcentaje resuelto', lineas.some((l) => l.includes(`${PCT}%`)), JSON.stringify(lineas));
    comprobar('no queda ningún shortcode sin resolver', !lineas.some((l) => l.includes('[akb_')), JSON.stringify(lineas));
  }
} finally {
  await navegador.close();
}

log(fallos === 0 ? '\nTODO OK' : `\n${fallos} FALLO(S)`);
process.exit(fallos === 0 ? 0 : 1);
