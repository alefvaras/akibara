/**
 * QA FUNCIONAL de los módulos, como lo haría el dueño:
 *
 *   configuro algo en el panel → guardo → LO BUSCO EN LA TIENDA → lo restauro.
 *
 * La diferencia con `qa-panel-invariantes.mjs` es toda: aquél comprueba que
 * guardar en blanco no escriba nada; éste comprueba que lo que el dueño
 * configura LLEGUE al cliente. Un módulo puede guardar perfectamente y no
 * pintar nada, y hasta hoy nadie lo estaba mirando.
 *
 * Cada caso deja la base como estaba, incluso si falla (restauración en
 * `finally`). Sólo contra el espejo.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
if (!/localhost|127\.0\.0\.1/.test(BASE)) throw new Error('Sólo contra el espejo.');
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const SOLO = process.env.AKB_SOLO ? process.env.AKB_SOLO.split(',') : null;

const MARCA = 'QAMARCA' + Math.floor(Math.random() * 900 + 100);

/** @type {{id:string,tab:string,campo:string,valor:string,url:string,espera:RegExp,que:string}[]} */
const CASOS = [
  { id: 'announcement', tab: 'announcement', campo: 'akibara_announcement_messages',
    valor: `${MARCA} anuncio de prueba`, url: '/', espera: new RegExp(MARCA),
    que: 'El mensaje de la barra de anuncios llega a la portada' },

  { id: 'home_series', tab: 'home_series', campo: 'akibara_home_series_title',
    valor: `${MARCA} series`, url: '/', espera: new RegExp(MARCA),
    que: 'El título del bloque de series se pinta en la portada' },

  { id: 'copy_despacho', tab: 'copys', campo: 'akb_copy_promise_dispatch',
    valor: `${MARCA} despacha ya`, url: null, espera: new RegExp(MARCA),
    que: 'La promesa de disponibilidad se pinta en la ficha de un tomo en stock',
    urlDinamica: 'producto_instock' },

  { id: 'copy_pedido', tab: 'copys', campo: 'akb_copy_promise_pedido_transit',
    valor: `${MARCA} lo pedimos`, url: null, espera: new RegExp(MARCA),
    que: 'La promesa de «a pedido» se pinta en la ficha de un tomo a pedido',
    urlDinamica: 'producto_onbackorder' },

  { id: 'salud_seo', tab: 'salud_seo', campo: 'akibara_llms_identity',
    valor: `${MARCA} tienda de manga`, url: '/llms.txt', espera: new RegExp(MARCA),
    que: 'La descripción de la tienda llega a /llms.txt',
    // El manifiesto se regenera en un evento programado (`wp_schedule_single_event`),
    // no en el mismo request. Hay que esperar al cron del espejo.
    esperarSegundos: 150, sinQuery: true },

  { id: 'fsp_mensaje', tab: 'free_shipping_progress', campo: 'akibara_fsp_title',
    valor: `${MARCA} envío gratis sobre {free_shipping_threshold}`, url: '/carrito/',
    espera: new RegExp(MARCA), que: 'El título de la barra de envío gratis llega al carrito' },

  { id: 'carrito_vacio', tab: 'native_cart', campo: 'akibara_native_cart_empty_copy',
    valor: `${MARCA} carrito vacio`, url: '/', espera: new RegExp(MARCA),
    que: 'El texto de carrito vacío viaja en el payload del carrito nativo' },

  // OJO: `is_clean_intro_request()` exige `empty($_GET)`. Un cache-buster en la
  // URL apaga la introducción — me costó un falso positivo. Sin query.
  { id: 'preventas_intro', tab: 'copys', campo: 'akb_copy_preventas_intro',
    valor: `${MARCA} intro de a pedido`, url: '/a-pedido/', espera: new RegExp(MARCA),
    que: 'La introducción de «a pedido» se pinta en su página', sinQuery: true },
];

const nav = await chromium.launch();
const ctx = await nav.newContext({ viewport: { width: 1440, height: 1100 } });
const page = await ctx.newPage();

await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await page.click('#wp-submit');
await page.waitForSelector('#wpadminbar', { timeout: 60000 });
console.log('· sesión iniciada · marca de esta corrida:', MARCA, '\n');

// --- dos productos reales del catálogo del espejo, uno por estado ---
const urlsProducto = {};
for (const [clave, estado] of [['producto_instock', 'instock'], ['producto_onbackorder', 'onbackorder']]) {
  await page.goto(`${BASE}/wp-admin/edit.php?post_type=product&post_status=publish&stock_status=${estado}`, { waitUntil: 'load' }).catch(() => {});
  // El permalink de verdad, no `?p=ID`: con `?p=` el tema devolvió 404 y me
  // apuntó dos defectos que no existían.
  const href = await page.evaluate(() => {
    const a = document.querySelector('#the-list .row-actions .view a, #the-list a[href*="/producto/"], #the-list .row-actions a[href^="http"]:not([href*="wp-admin"])');
    return a ? a.href : null;
  });
  if (href) urlsProducto[clave] = href.split('?')[0];
}
console.log('· fichas de prueba:', JSON.stringify(urlsProducto), '\n');

const hallazgos = [];
const casos = SOLO ? CASOS.filter((c) => SOLO.includes(c.id)) : CASOS;

for (const caso of casos) {
  const url = caso.urlDinamica ? urlsProducto[caso.urlDinamica] : BASE + caso.url;
  process.stdout.write(`── ${caso.id.padEnd(18)} `);
  if (!url) { console.log('SALTADO (sin ficha de prueba)'); continue; }

  const panel = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${caso.tab}`;
  let original = null;
  try {
    await page.goto(panel, { waitUntil: 'load' });
    await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
    const sel = `[name="${caso.campo}"]`;
    if (!(await page.locator(sel).count())) {
      console.log(`✗ el campo ${caso.campo} no existe en la pestaña ${caso.tab}`);
      hallazgos.push({ caso: caso.id, grav: 'alta', txt: `El campo ${caso.campo} no está en ${caso.tab}` });
      continue;
    }
    original = await page.locator(sel).first().inputValue();
    await page.locator(sel).first().fill(caso.valor);
    await page.locator('form button[name="akibara_panel_save"]').first().click({ timeout: 8000 });
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(1200);

    const p2 = await ctx.newPage();
    const destino = caso.sinQuery ? url : url + (url.includes('?') ? '&' : '?') + 'cb=' + Date.now();
    const limite = Date.now() + (caso.esperarSegundos || 0) * 1000;
    let visto = false, http = 0, intentos = 0;
    do {
      const r = await p2.goto(destino, { waitUntil: 'load' });
      http = r ? r.status() : 0;
      const texto = await p2.evaluate(() => document.documentElement.outerHTML);
      visto = caso.espera.test(texto);
      intentos++;
      if (!visto && Date.now() < limite) await p2.waitForTimeout(15000);
    } while (!visto && Date.now() < limite);
    if (caso.esperarSegundos) process.stdout.write(`(${intentos} intento(s)) `);
    await p2.close();

    if (visto) console.log(`OK   ${caso.que}`);
    else {
      console.log(`✗✗   NO llega — ${caso.que}  (HTTP ${http}, ${url})`);
      hallazgos.push({ caso: caso.id, grav: 'alta', txt: `${caso.que}: configuré «${caso.valor}» y no aparece en ${url} (HTTP ${http})` });
    }
  } catch (e) {
    console.log('✗  error:', String(e).slice(0, 120));
    hallazgos.push({ caso: caso.id, grav: 'media', txt: 'La prueba no pudo completarse: ' + String(e).slice(0, 120) });
  } finally {
    if (original !== null) {
      try {
        await page.goto(panel, { waitUntil: 'load' });
        await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
        await page.locator(`[name="${caso.campo}"]`).first().fill(original);
        await page.locator('form button[name="akibara_panel_save"]').first().click({ timeout: 8000 });
        await page.waitForLoadState('load').catch(() => {});
        await page.waitForTimeout(800);
      } catch { console.log('   ⚠ NO PUDE RESTAURAR', caso.campo, '→ valor original:', JSON.stringify(original).slice(0, 80)); }
    }
  }
}

console.log('\n═══ RESUMEN ═══');
if (!hallazgos.length) console.log('Todo lo configurado llegó a la tienda.');
for (const h of hallazgos) console.log(` [${h.grav}] ${h.caso}: ${h.txt}`);
await nav.close();
