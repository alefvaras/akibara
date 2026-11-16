// QA Playwright — reglas nuevas de Akibara Puntos (decisiones del dueño
// 2026-09-13): bono de bienvenida en la primera compra pagada con dinero y sin
// monto mínimo, uno por persona, premio al padrino al entregarse, y canje sólo
// en carritos desde $50.000.
//
// Mide lo que ve el CLIENTE, en escritorio y móvil: la ficha, la página del
// programa, Mi cuenta y la caja de canje del checkout por debajo y por encima
// del mínimo.
//
// Uso:
//   AKB_SIEMBRA='<json de sembrar-cliente-puntos.php>' node qa-puntos-primera-compra.mjs
// Variables: AKB_BASE (defecto http://localhost:8080), AKB_SOLO_PUBLICO=1 para
// no iniciar sesión (producción: nunca se escriben contraseñas allá).
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const SIEMBRA = JSON.parse(process.env.AKB_SIEMBRA || '{}');
const SOLO_PUBLICO = process.env.AKB_SOLO_PUBLICO === '1';
const UA = 'Mozilla/5.0 (AkibaraQA) Playwright';
// En producción `networkidle` no llega nunca (Clarity y el chat mantienen
// conexiones abiertas): se espera al DOM y se deja asentar el JS.
const CARGA = { waitUntil: 'domcontentloaded', timeout: 90000 };
const MIN_CART = Number(SIEMBRA.min_cart || 50000);

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato)}`}`);
};
const ruta = (u) => (u ? new URL(u).pathname : '/');
const limpio = (t) => t.replace(/\s+/g, ' ').trim();

// Texto COMPLETO, incluido lo que vive dentro de un <details> plegado: con
// innerText, «¿Cómo funcionan mis puntos?» no existe para la prueba y da un
// falso negativo.
const textoCompleto = (page) => page.evaluate(() => {
  document.querySelectorAll('details').forEach((d) => { d.open = true; });
  return document.body.innerText.replace(/\s+/g, ' ').trim();
});

/**
 * Añade al carrito por la UI real. El parámetro `?add-to-cart=` no sirve en el
 * espejo: LiteSpeed sirve la portada cacheada y la petición no llega a
 * WooCommerce (carrito vacío y el checkout redirige al carrito).
 */
async function agregar(page, producto) {
  await page.goto(`${BASE}${ruta(producto.url)}`, CARGA);
  // La cantidad no se toca: los tomos tienen stock 1 y el tema esconde el campo
  // cuando no hay nada que elegir. Para superar el mínimo se suman tomos
  // distintos, que es lo que hace un cliente de verdad.
  const boton = page.locator('button[name="add-to-cart"], .single_add_to_cart_button').first();
  await boton.click();
  await page.waitForTimeout(2500);
}

/**
 * Vacía el carrito por la UI. WooCommerce guarda el carrito de un cliente con
 * sesión entre visitas (carrito persistente), así que sin esto el escenario
 * «bajo el mínimo» arrastra los tomos del escenario anterior y mide otra cosa.
 */
async function vaciar(page) {
  // Por la tienda de datos de los bloques, no por los enlaces «quitar»: el
  // carrito de bloques los pinta con marcado propio y una corrida anterior
  // dejaba tomos dentro, midiendo el escenario equivocado. La acción se llama
  // `removeItemFromCart` (no `removeItem`), y la que no existe falla en
  // silencio: el carrito seguía lleno y la caja de canje parecía rota.
  await page.goto(`${BASE}/carrito/`, CARGA);
  await page.evaluate(async () => {
    const tienda = wp.data.select('wc/store/cart');
    const acciones = wp.data.dispatch('wc/store/cart');
    const items = tienda.getCartData().items || [];
    for (const item of items) {
      await acciones.removeItemFromCart(item.key);
    }
  }).catch(() => {});
  await page.waitForTimeout(2000);
}

const nav = await chromium.launch();

// ── Página del programa: las reglas que el cliente lee ────────────────────
{
  console.log('Página del programa');
  const ctx = await nav.newContext({ viewport: { width: 1440, height: 950 }, userAgent: UA });
  const page = await ctx.newPage();
  const erroresJs = [];
  page.on('pageerror', (e) => erroresJs.push(String(e).slice(0, 160)));

  const resp = await page.goto(`${BASE}${ruta(SIEMBRA.programa)}`, CARGA);
  ok(resp && resp.status() === 200, 'la página del programa responde 200', resp && resp.status());

  const texto = limpio(await page.evaluate(() => document.body.innerText));
  ok(texto.includes(`$${MIN_CART.toLocaleString('es-CL')}`), `dice el carrito mínimo de canje ($${MIN_CART.toLocaleString('es-CL')})`, texto.slice(0, 200));
  ok(texto.includes('uno por persona'), 'dice que el bono es uno por persona');
  ok(texto.includes('recibe su primera compra') || texto.includes('reciba su primera compra'), 'dice que al padrino se le paga cuando el invitado RECIBE su compra');
  ok(!/tu primer pedido elegible/i.test(texto), 'ya no queda el texto viejo «tu primer pedido elegible»');
  ok(!texto.includes('[akb_puntos_resumen'), 'ningún shortcode quedó sin resolver', texto.match(/\[akb_puntos_resumen[^\]]*\]/g));
  ok(erroresJs.length === 0, 'sin excepciones de JavaScript', erroresJs);
  await ctx.close();
}

// ── Ficha del tomo, como invitado ─────────────────────────────────────────
for (const [nombre, vp] of [['escritorio', { width: 1440, height: 950 }], ['movil', { width: 375, height: 812 }]]) {
  console.log(`Ficha · ${nombre}`);
  const ctx = await nav.newContext({ viewport: vp, userAgent: UA });
  const page = await ctx.newPage();
  await page.goto(`${BASE}${ruta(SIEMBRA.barato?.url)}`, CARGA);

  const aviso = await page.evaluate(() => {
    const n = document.querySelector('.akb-rw-pdp-points');
    if (!n) return null;
    const caja = n.getBoundingClientRect();
    return { texto: n.innerText.replace(/\s+/g, ' ').trim(), ancho: Math.round(caja.width), sobresale: Math.round(caja.right - document.documentElement.clientWidth) };
  });

  ok(aviso !== null, 'la ficha promete puntos', aviso);
  if (aviso) {
    ok(/Puntos Akibara/.test(aviso.texto), 'el aviso nombra los Puntos Akibara', aviso.texto);
    ok(/bienvenida/.test(aviso.texto), 'y el bono de bienvenida de la primera compra', aviso.texto);
    ok(/uno por persona/.test(aviso.texto), 'y que el bono es uno por persona', aviso.texto);
    ok(aviso.sobresale <= 0, 'el aviso no se sale de la pantalla', aviso.sobresale);
  }
  await ctx.close();
}

if (SOLO_PUBLICO) {
  await nav.close();
  if (fallos) { console.error(`FAIL: ${fallos} comprobación(es) rotas`); process.exit(1); }
  console.log('PASS: superficies públicas al día con las reglas nuevas');
  process.exit(0);
}

// ── Con sesión: Mi cuenta y la caja de canje del checkout ─────────────────
const ctx = await nav.newContext({ viewport: { width: 1440, height: 950 }, userAgent: UA });
const page = await ctx.newPage();
const erroresJs = [];
page.on('pageerror', (e) => erroresJs.push(String(e).slice(0, 160)));

// Con reintentos y comprobando la COOKIE, no la URL: el primer envío se
// perdía a veces (la navegación del login pisaba el clic) y la prueba seguía
// midiendo páginas de invitado, que es un falso negativo carísimo de leer.
let entro = false;
for (let intento = 1; intento <= 3 && !entro; intento += 1) {
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load', timeout: 90000 });
  await page.waitForSelector('#wp-submit', { timeout: 30000 });
  await page.fill('#user_login', SIEMBRA.login);
  await page.fill('#user_pass', SIEMBRA.clave);
  await page.press('#user_pass', 'Enter');
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1500);
  entro = (await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in'));
}
ok(entro, 'sesión del cliente de QA abierta', page.url());

console.log('Mi cuenta · Puntos Akibara');
await page.goto(`${BASE}${ruta(SIEMBRA.mi_cuenta)}`, CARGA);
const cuenta = await textoCompleto(page);
ok(cuenta.includes(`$${MIN_CART.toLocaleString('es-CL')}`), 'Mi cuenta dice desde qué monto se canjea', cuenta.slice(0, 300));
ok(/uno por persona|Ganas/.test(cuenta), 'Mi cuenta explica cómo se gana');

// El aviso que el cliente lee JUSTO después de decidir el canje tiene que
// nombrar el piso, no sólo el techo.
console.log('Mi cuenta · aviso al dejar un canje listo');
{
  const campo = page.locator('input[name="akb_rw_points"]').last();
  if (await campo.count()) {
    await campo.fill('200');
    await Promise.all([page.waitForLoadState('load').catch(() => {}), page.locator('button:has-text("Canjear en mi próxima compra")').first().click()]);
    await page.waitForTimeout(2000);
    const aviso = limpio(await page.evaluate(() => document.querySelector('.woocommerce-message, .wc-block-components-notice-banner')?.innerText || ''));
    ok(aviso.includes(`$${MIN_CART.toLocaleString('es-CL')}`), 'el aviso del canje dice desde qué monto se aplica', aviso);
  } else {
    ok(false, 'el formulario de canje de Mi cuenta está donde se espera');
  }
}

console.log('Checkout · carrito BAJO el mínimo');
await vaciar(page);
await agregar(page, SIEMBRA.barato);
await page.goto(`${BASE}/checkout/`, CARGA);
await page.waitForTimeout(3000);
ok(/checkout/.test(page.url()), 'el checkout carga con el carrito bajo el mínimo', page.url());

const bajo = await page.evaluate(() => {
  const caja = document.querySelector('.akb-rw-checkout-box');
  return caja ? { texto: caja.innerText.replace(/\s+/g, ' ').trim(), input: !!caja.querySelector('input#akb-rw-canje') } : null;
});
ok(bajo !== null, 'la caja de puntos aparece en el checkout', bajo);
if (bajo) {
  ok(/faltan/.test(bajo.texto), 'dice cuánto falta para poder canjear', bajo.texto);
  ok(bajo.texto.includes(`$${MIN_CART.toLocaleString('es-CL')}`), 'y desde qué monto se canjea', bajo.texto);
  ok(!bajo.input, 'no ofrece canjear bajo el mínimo', bajo.texto);
}

console.log('Checkout · carrito SOBRE el mínimo');
await vaciar(page);
for (const tomo of SIEMBRA.sobre || []) {
  await agregar(page, tomo);
}
await page.goto(`${BASE}/checkout/`, CARGA);
await page.waitForTimeout(3000);

const sobre = await page.evaluate(() => {
  const caja = document.querySelector('.akb-rw-checkout-box');
  return caja ? { texto: caja.innerText.replace(/\s+/g, ' ').trim(), input: !!caja.querySelector('input#akb-rw-canje') } : null;
});
ok(sobre !== null && sobre.input, 'sobre el mínimo sí se puede canjear', sobre);
if (sobre) {
  ok(!/faltan/.test(sobre.texto), 'y ya no dice que falte nada', sobre.texto);
}
ok(erroresJs.length === 0, 'sin excepciones de JavaScript con sesión', erroresJs);

await page.screenshot({ path: '/tmp/qa-puntos-checkout.png' });
// El carrito del cliente de QA no se deja armado para la próxima corrida.
await vaciar(page);
await ctx.close();
await nav.close();

if (fallos) {
  console.error(`FAIL: ${fallos} comprobación(es) rotas`);
  process.exit(1);
}
console.log('PASS: las reglas nuevas se ven donde el cliente decide');
