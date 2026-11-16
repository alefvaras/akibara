// Prueba de la promoción por editorial, actuando como el DUEÑO en el espejo.
// Uso:  node ops/qa/promo-ivrea.mjs [descubrir|aplicar|limpiar]
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
// Espejo SIEMPRE: esta prueba enciende una promoción real.
if (!/localhost|127\.0\.0\.1/.test(BASE)) { throw new Error('esta prueba solo corre contra el espejo'); }
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const MODO = process.argv[2] || 'descubrir';
const PCT  = process.env.AKB_PCT || '8';
const TERM = process.env.AKB_TERM || '230';           // Ivrea Argentina
const DESDE = process.env.AKB_DESDE || '2026-09-05';
const HASTA = process.env.AKB_HASTA || '2026-09-07';
const SHOT = (n) => new URL(`promo-${n}.png`, import.meta.url).pathname;

const log = (...a) => console.log(...a);

async function entrar(page) {
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass', PASS);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('#wp-submit')]);
  if (!(await page.locator('#wpadminbar').count())) throw new Error('no se pudo entrar al admin');
  log('· sesión iniciada como', USER);
}

// El JS del módulo inyecta una barra flotante «Tienes cambios sin guardar» que
// tapa el botón del final del formulario. Se pulsa el de la barra si está.
async function guardar(page) {
  // Se intenta el clic real; si la barra flotante tapa el botón (o ella misma
  // está fuera de vista), se dispara el click del DOM sobre el mismo botón:
  // sigue siendo el submit del formulario, no un atajo por HTTP.
  const btn = page.locator('form button[name="akibara_panel_save"]').first();
  try {
    await btn.scrollIntoViewIfNeeded();
    await btn.click({ timeout: 4000 });
  } catch {
    // El clic pudo dispararse igual y la navegación destruye el contexto:
    // se ignora y la verdad la da la RELECTURA del formulario, más abajo.
    await page.$eval('form button[name="akibara_panel_save"]', (b) => b.click()).catch(() => {});
  }
  await page.waitForLoadState('domcontentloaded').catch(() => {});
}

const TAB = (t) => `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${t}`;

async function descubrir(page) {
  await page.goto(TAB('taxonomy_discount'), { waitUntil: 'domcontentloaded' });
  await page.screenshot({ path: SHOT('descubrir'), fullPage: true });
  const campos = await page.$$eval('form input, form select, form textarea', els =>
    els.filter(e => e.name && /akibara|akb/.test(e.name))
       .map(e => ({ name: e.name, tipo: e.type || e.tagName.toLowerCase(), valor: (e.value||'').slice(0,40),
                    opciones: e.tagName === 'SELECT' ? [...e.options].map(o=>o.value).join('|') : '' })));
  log(JSON.stringify(campos, null, 1));
  log('captura →', SHOT('descubrir'));
}

async function aplicar(page) {
  await page.goto(TAB('taxonomy_discount'), { waitUntil: 'domcontentloaded' });
  for (const d of await page.$$('details')) { await d.evaluate(el => el.open = true); }

  await page.locator('#akibara_taxonomy_discount_enabled').check({ force: true });
  await page.locator('#akibara_taxonomy_discount_from').fill(DESDE);
  await page.locator('#akibara_taxonomy_discount_to').fill(HASTA);
  // apply_to y sale_policy son RADIOS, no selects.
  await page.locator('input[name="akibara_taxonomy_discount_apply_to"][value="instock_and_onbackorder"]').check({ force: true });
  await page.locator('input[name="akibara_taxonomy_discount_sale_policy"][value="greatest_wins"]').check({ force: true });
  await page.locator(`#akibara_taxonomy_discount_pct_${TERM}`).fill(PCT);
  // El badge «¡Oferta!» tiene su propio umbral: con 8% no saldría si el mínimo es 10.
  const umbral = page.locator('#akb_sale_badge_min_pct');
  if (await umbral.count()) {
    const v = await umbral.inputValue();
    log('  · umbral del badge de oferta:', v, Number(v) > Number(PCT) ? `→ con ${PCT}% NO se vería el badge` : '→ el badge sí se verá');
    if (Number(v) > Number(PCT)) { await umbral.fill(PCT); log(`  · bajado a ${PCT} para que la promo se vea`); }
  }
  await page.screenshot({ path: SHOT('form'), fullPage: true });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    guardar(page),
  ]);
  await page.screenshot({ path: SHOT('guardado'), fullPage: true });
  const aviso = await page.locator('.notice, .updated').first().innerText().catch(() => '');
  log('· guardado:', (aviso || '(sin aviso)').replace(/\s+/g, ' ').slice(0, 200));
  await page.goto(TAB('taxonomy_discount'), { waitUntil: 'domcontentloaded' });
  for (const d of await page.$$('details')) { await d.evaluate(el => el.open = true); }
  const leido = {
    activo: await page.locator('#akibara_taxonomy_discount_enabled').isChecked(),
    desde: await page.locator('#akibara_taxonomy_discount_from').inputValue(),
    hasta: await page.locator('#akibara_taxonomy_discount_to').inputValue(),
    pct: await page.locator(`#akibara_taxonomy_discount_pct_${TERM}`).inputValue(),
  };
  log('· releído del formulario:', JSON.stringify(leido));
}

async function banner(page, texto) {
  await page.goto(TAB('announcement'), { waitUntil: 'domcontentloaded' });
  if (!(await page.locator('#akibara_announcement_messages').count())) {
    // La pestaña puede llamarse distinto: buscarla por el campo en todas.
    for (const t of ['announcement_bar', 'announcementbar', 'general', 'home']) {
      await page.goto(TAB(t), { waitUntil: 'domcontentloaded' });
      if (await page.locator('#akibara_announcement_messages').count()) { log('· banner en la pestaña', t); break; }
    }
  }
  const campo = page.locator('#akibara_announcement_messages');
  if (!(await campo.count())) { log('  ! no encontré el campo del banner'); return; }
  const previo = await campo.inputValue();
  log('· mensajes actuales:', previo.replace(/\n/g, ' | '));
  await campo.fill(texto + '\n' + previo);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    guardar(page),
  ]);
  log('· banner guardado');
  return previo;
}

async function comprobarTienda(page) {
  const r = { };
  // producto Ivrea Argentina en stock
  const url = process.env.AKB_PROD || `${BASE}/kingdom-35-ivrea-argentina/`;
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  const precio = await page.locator('.summary .price').first().innerText().catch(()=> 'sin precio');
  r.producto = { url, precio: precio.replace(/\s+/g,' ').trim() };
  await page.screenshot({ path: SHOT('ficha'), fullPage: false });
  // banner superior
  const barra = page.locator('#akb-ann-bar');
  r.banner = (await barra.count()) ? (await barra.innerText()).replace(/\s+/g,' ').trim().slice(0,240) : 'NO aparece la barra';
  if (await barra.count()) await barra.screenshot({ path: SHOT('banner') }).catch(()=>{});
  // Lo que de verdad se cobra: se añade al carrito y se lee el carrito REAL
  // por la Store API de WooCommerce (la tienda usa drawer, no página).
  const pid = Number(process.env.AKB_PID || 24567);
  const cobro = await page.evaluate(async (id) => {
    await fetch('/?add-to-cart=' + id, { credentials: 'same-origin' });
    const r = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' });
    const c = await r.json();
    const it = (c.items || []).find(x => x.id === id) || (c.items || [])[0];
    return it ? {
      nombre: it.name,
      regular: it.prices.regular_price,
      venta: it.prices.sale_price,
      linea: it.totals.line_total,
      decimales: it.prices.currency_minor_unit,
    } : { error: 'carrito vacío', items: (c.items || []).length };
  }, pid);
  r.carrito = cobro;
  await page.screenshot({ path: SHOT('carrito'), fullPage: false });
  log(JSON.stringify(r, null, 1));
}

async function limpiar(page) {
  await page.goto(TAB('taxonomy_discount'), { waitUntil: 'domcontentloaded' });
  for (const d of await page.$$('details')) { await d.evaluate(el => el.open = true); }
  // DOS guardados a propósito: al apagar el interruptor maestro el JS del
  // módulo deja de enviar los porcentajes por término, así que el 8% quedaba
  // guardado esperando el próximo encendido. Primero se pone en 0 con el
  // módulo aún encendido; después se apaga.
  await page.locator(`#akibara_taxonomy_discount_pct_${TERM}`).fill('0');
  await page.locator('#akibara_taxonomy_discount_from').fill('');
  await page.locator('#akibara_taxonomy_discount_to').fill('');
  const umbral = page.locator('#akb_sale_badge_min_pct');
  if (await umbral.count()) await umbral.fill('10');
  await guardar(page);

  await page.goto(TAB('taxonomy_discount'), { waitUntil: 'domcontentloaded' });
  for (const d of await page.$$('details')) { await d.evaluate(el => el.open = true); }
  const c = page.locator('#akibara_taxonomy_discount_enabled');
  if (await c.isChecked()) await c.uncheck({ force: true });
  await guardar(page);
  await page.goto(TAB('taxonomy_discount'), { waitUntil: 'domcontentloaded' });
  for (const d of await page.$$('details')) { await d.evaluate(el => el.open = true); }
  log('· tras limpiar:', JSON.stringify({
    activo: await page.locator('#akibara_taxonomy_discount_enabled').isChecked(),
    pct: await page.locator(`#akibara_taxonomy_discount_pct_${TERM}`).inputValue(),
    desde: await page.locator('#akibara_taxonomy_discount_from').inputValue(),
    badge: (await page.locator('#akb_sale_badge_min_pct').count()) ? await page.locator('#akb_sale_badge_min_pct').inputValue() : null,
  }));
  await page.goto(process.env.AKB_PROD || `${BASE}/kingdom-35-ivrea-argentina/`, { waitUntil: 'domcontentloaded' });
  log('· precio en la ficha:', (await page.locator('.summary .price').first().innerText()).replace(/\s+/g, ' ').trim());
}

const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'es-CL' });
const page = await ctx.newPage();
try {
  await entrar(page);
  if (MODO === 'descubrir') await descubrir(page);
  else if (MODO === 'aplicar') { await aplicar(page); await banner(page, process.env.AKB_BANNER || 'Solo por este finde: todo Ivrea a un 8%'); await comprobarTienda(page); }
  else if (MODO === 'tienda') await comprobarTienda(page);
  else if (MODO === 'limpiar') await limpiar(page);
} finally { await b.close(); }
