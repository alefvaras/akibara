// QA Playwright del ESPEJO — rewards 0.4.1, canje y pago rechazado
// (revisión final 2026-09-13).
//
//   R1. Pago rechazado sin cambio de estado (como MercadoPago con tarjeta): el
//       reintento con el mismo carrito ve el MISMO descuento, reutiliza el
//       pedido y no cobra dos veces; pagado después, cobra una sola vez.
//   R2. Rechazo y carrito cambiado: el pedido nuevo usa los puntos, el rechazado
//       los suelta; si el rechazado se pagara igual, se re-cobra y se avisa.
//   R3. Pedido fallido pagado desde «pagar pedido»: se re-cobra antes de cobrar;
//       si vuelve a fallar sin cambio de estado, el reintento devuelve.
//   R4. «Quitar canje» bajo el mínimo del carrito y en Mi cuenta sin saldo.
//   R5. Pedido reutilizado con otro canje y el mismo total: cobra la diferencia.
//   R6. La caja falla tras crear el pedido nuevo: el reintento libera igual.
//   R7. Pasarela que REDIRIGE (Flow, MercadoPago con redirección): mientras el
//       pedido espera pago allí, sus puntos NO se prestan a otra compra; pagado
//       tarde, no hay alarma de saldo corto (revisión independiente, ALTA).
//   R8. Rechazo y compra nueva SIN canje: los puntos del rechazado vuelven al
//       saldo al instante, no a la hora (MEDIA).
//
// La pasarela que rechaza se copia a mu-plugins sólo mientras corre y se borra
// al terminar, también si algo falla. Uso: node qa-canje-pago-rechazado.mjs
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const SEMILLA = `${CWD}/tools/akibara-releases/rewards-qa/sembrar-referidos-canje.php`;
const PASARELA = `${CWD}/tools/akibara-releases/rewards-qa/qa-pasarela-rechazo.php`;
const PASARELA_VIVA = `${CWD}/public_html/wp-content/mu-plugins/zz-qa-pasarela-rechazo.php`;
const UA = 'Mozilla/5.0 (AkibaraQA) Playwright';
const CARGA = { waitUntil: 'domcontentloaded', timeout: 90000 };

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 700)}`}`);
};
const limpio = (t) => String(t || '').replace(/\s+/g, ' ').trim();
const ruta = (u) => (u ? new URL(u).pathname + new URL(u).search : '/');

const wp = (...args) => {
  const salida = execSync(`docker compose exec -T cron wp eval-file - ${args.map((a) => `'${String(a).replace(/'/g, '')}'`).join(' ')} < '${SEMILLA}'`, { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24 });
  const linea = salida.trim().split('\n').reverse().find((l) => l.startsWith('{'));
  return JSON.parse(linea || '{}');
};

const retirarPasarela = () => { try { fs.rmSync(PASARELA_VIVA, { force: true }); } catch { /* ya no está */ } };
process.on('exit', retirarPasarela);

async function entrar(ctx, page, login, clave) {
  for (let intento = 1; intento <= 3; intento += 1) {
    await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load', timeout: 90000 });
    await page.waitForSelector('#wp-submit', { timeout: 30000 });
    await page.fill('#user_login', login);
    await page.fill('#user_pass', clave);
    await page.press('#user_pass', 'Enter');
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(1500);
    if ((await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in'))) return true;
  }
  return false;
}

async function agregar(page, producto) {
  await page.goto(`${BASE}${ruta(producto.url)}`, CARGA);
  await page.locator('button[name="add-to-cart"], .single_add_to_cart_button').first().click();
  await page.waitForTimeout(2500);
}

async function vaciar(page) {
  await page.goto(`${BASE}/carrito/`, CARGA);
  await page.evaluate(async () => {
    const items = wp.data.select('wc/store/cart').getCartData().items || [];
    for (const item of items) await wp.data.dispatch('wc/store/cart').removeItemFromCart(item.key);
  }).catch(() => {});
  await page.waitForTimeout(1500);
}

async function tienda(ctx) {
  const r = await ctx.request.get(`${BASE}/wp-json/wc/store/v1/cart`);
  const nonce = r.headers().nonce;
  const post = async (camino, datos) => {
    const resp = await ctx.request.post(`${BASE}/wp-json/wc/store/v1/${camino}`, { headers: { Nonce: nonce, 'Content-Type': 'application/json' }, data: datos, timeout: 120000 });
    let json = {};
    try { json = await resp.json(); } catch { /* sin cuerpo */ }
    return { status: resp.status(), json };
  };
  const carrito = async () => (await (await ctx.request.get(`${BASE}/wp-json/wc/store/v1/cart`, { headers: { Nonce: nonce } })).json());
  return { nonce, post, carrito };
}

const puntosDelCarrito = (cart) => {
  const fee = (cart?.fees || []).find((f) => f.name === 'Puntos Akibara');
  if (!fee) return 0;
  return -(Number(fee.totals.total) + Number(fee.totals.total_tax)) / 10 ** Number(fee.totals.currency_minor_unit || 0);
};
const datosCaja = (cart) => cart?.extensions?.['akibara-rewards'] || {};

const DIRECCION = {
  first_name: 'QA', last_name: 'Canje', company: '', address_1: 'Av. Providencia 1234', address_2: '',
  city: 'Santiago', state: 'CL-RM', postcode: '', country: 'CL', phone: '+56987654321',
};

let S;
const pago = (metodo) => ({ billing_address: { ...DIRECCION, email: S.canje.email }, shipping_address: DIRECCION, payment_method: metodo, additional_fields: { 'akibara/rut': '12.345.678-5' }, customer_note: '' });

async function prepararCarrito(ctx, page, puntos, tomos) {
  await vaciar(page);
  for (const tomo of tomos) await agregar(page, tomo);
  const api = await tienda(ctx);
  const cliente = await api.post('cart/update-customer', { billing_address: { ...DIRECCION, email: S.canje.email }, shipping_address: DIRECCION });
  const tarifas = cliente.json?.shipping_rates?.[0]?.shipping_rates || [];
  const retiro = tarifas.find((t) => /pickup/.test(t.method_id)) || tarifas[0];
  if (retiro) await api.post('cart/select-shipping-rate', { package_id: 0, rate_id: retiro.rate_id });
  const aplicado = await api.post('cart/extensions', { namespace: 'akibara-rewards', data: { points: puntos } });
  return { api, aplicado: aplicado.json };
}

const pendientes = (antes) => (wp('pedidos', S.canje.id).pedidos || []).filter((p) => !antes.has(p.id));
const idsDe = () => new Set((wp('pedidos', S.canje.id).pedidos || []).map((p) => p.id));

console.log('Preparación');
wp('limpiar');
fs.copyFileSync(PASARELA, PASARELA_VIVA);
S = wp('sembrar');
ok(S.canje?.id > 0 && S.sobre?.length > 0, 'cliente de canje y tomos sobre el mínimo', { canje: S.canje, suma: S.suma, min: S.min_cart });

const nav = await chromium.launch();
try {
  const ctx = await nav.newContext({ viewport: { width: 1440, height: 950 }, userAgent: UA });
  const page = await ctx.newPage();
  const erroresJs = [];
  page.on('pageerror', (e) => erroresJs.push(String(e).slice(0, 160)));
  ok(await entrar(ctx, page, S.canje.login, S.canje.clave), 'sesión del cliente');

  // ── R1 ────────────────────────────────────────────────────────────────
  console.log('R1 · pago rechazado y reintento con el mismo carrito');
  {
    wp('saldo', S.canje.id, 1000);
    const antes = idsDe();
    const { api, aplicado } = await prepararCarrito(ctx, page, 1000, S.sobre);
    ok(puntosDelCarrito(aplicado) === 1000, 'el carrito aplica los 1.000 puntos (todo el saldo)', aplicado?.fees);

    const r1 = await api.post('checkout', pago('akb_qa_rechazo'));
    ok(r1.status === 400 && /rechazada/i.test(r1.json?.message || ''), 'la pasarela rechaza el pago', { status: r1.status, code: r1.json?.code, message: r1.json?.message });
    const tras1 = pendientes(antes);
    ok(tras1.length === 1 && tras1[0].estado === 'pending' && tras1[0].canjeado === 1000, 'queda UN pedido pendiente con el canje cobrado', tras1);
    ok(wp('estado', S.canje.id).saldo === 0, 'el saldo queda en 0');

    const carro = await api.carrito();
    ok(puntosDelCarrito(carro) === 1000, 'al volver, el carrito muestra el MISMO descuento de 1.000', carro?.fees);
    ok(datosCaja(carro).balance === 1000 && datosCaja(carro).intent === 1000, 'y la caja cuenta esos 1.000 como disponibles para esta compra', datosCaja(carro));

    await page.goto(`${BASE}/checkout/`, CARGA);
    await page.waitForSelector('.wc-block-components-checkout-place-order-button', { timeout: 60000 });
    await page.waitForTimeout(3000);
    const resumen = limpio(await page.locator('.wp-block-woocommerce-checkout-order-summary-block, .wc-block-components-order-summary').first().innerText().catch(() => ''));
    ok(/Puntos Akibara/.test(resumen) && /-\s?\$\s?1\.000\b/.test(resumen), 'la pantalla de la caja muestra el descuento de $1.000', resumen.slice(0, 400));
    const caja = limpio(await page.locator('.akb-rw-checkout-box').first().innerText().catch(() => ''));
    ok(/Canje activo: 1\.000 puntos/.test(caja) && /Quitar canje/.test(caja), 'y la caja dice «Canje activo: 1.000 puntos» con «Quitar canje»', caja);
    await page.screenshot({ path: '/tmp/qa-canje-tras-rechazo.png' });

    await page.goto(`${BASE}/mi-cuenta/rewards/`, CARGA);
    const cuenta = limpio(await page.evaluate(() => document.body.innerText));
    ok(/Además tienes 1\.000 puntos apartados en un pedido sin pagar\. Su pago no se completó: puedes usarlos al volver a intentar esa compra/.test(cuenta), 'Mi cuenta explica los 1.000 apartados en vez de mostrar sólo saldo 0', cuenta.match(/Tu saldo.{0,300}/)?.[0]);
    ok(/name="akb_rw_points"[^>]*max="1000"/.test(await page.content()), 'y el formulario deja canjear esos 1.000 (antes no había formulario con saldo 0)');

    const r2 = await api.post('checkout', pago('akb_qa_rechazo'));
    ok(r2.status === 400, 'segundo rechazo', { status: r2.status, message: r2.json?.message });
    const tras2 = pendientes(antes);
    ok(tras2.length === 1 && tras2[0].id === tras1[0]?.id && tras2[0].canjeado === 1000, 'WooCommerce reutiliza el MISMO pedido: sin pedidos nuevos ni cobro doble', tras2);
    ok(datosCaja(await api.carrito()).intent === 1000, 'la intención sigue viva tras el segundo intento');

    const r3 = await api.post('checkout', pago('bacs'));
    ok(r3.status === 200 && r3.json?.order_id === tras1[0]?.id, 'pagado por transferencia, es el mismo pedido', { status: r3.status, order: r3.json?.order_id, message: r3.json?.message });
    const pedido = wp('pedido', tras1[0]?.id);
    ok(pedido.estado === 'on-hold' && pedido.canjeado === 1000 && Math.round(pedido.valor) === 1000, 'el pedido queda en espera con 1.000 cobrados una sola vez', pedido);
    ok(!pedido.notas.some((n) => /saldo solo cubrió|se detuvo un pago/.test(n)), 'sin descuento regalado ni pago detenido', pedido.notas);
    ok(wp('estado', S.canje.id).saldo === 0, 'el saldo sigue en 0');
    ok(datosCaja(await api.carrito()).intent === 0, 'con la compra hecha, la intención ya no existe');
  }

  // ── R2 ────────────────────────────────────────────────────────────────
  console.log('R2 · rechazo y carrito cambiado');
  {
    wp('saldo', S.canje.id, 1000);
    const antes = idsDe();
    const { api } = await prepararCarrito(ctx, page, 1000, S.sobre);
    const r1 = await api.post('checkout', pago('akb_qa_rechazo'));
    ok(r1.status === 400, 'la pasarela rechaza el pago');
    const rechazado = pendientes(antes)[0];
    ok(rechazado?.canjeado === 1000 && wp('estado', S.canje.id).saldo === 0, 'pedido rechazado con 1.000 cobrados', rechazado);

    await agregar(page, S.sobre[0]); // Otra unidad: el carrito cambia.
    const carro = await api.carrito();
    ok(puntosDelCarrito(carro) === 1000, 'con el carrito cambiado el descuento sigue en 1.000', carro?.fees);

    const r2 = await api.post('checkout', pago('bacs'));
    ok(r2.status === 200 && r2.json?.order_id > 0 && r2.json.order_id !== rechazado?.id, 'la compra nueva sale en otro pedido', { status: r2.status, order: r2.json?.order_id, message: r2.json?.message });
    const nuevo = wp('pedido', r2.json?.order_id);
    ok(nuevo.canjeado === 1000 && Math.round(nuevo.valor) === 1000, 'el pedido nuevo cobra los 1.000', nuevo);
    const viejo = wp('pedido', rechazado?.id);
    ok(viejo.devuelto === 1000 && viejo.deuda === 1000, 'el rechazado los suelta y queda con deuda por si se paga', viejo);
    ok(viejo.notas.some((n) => n.includes(`otra compra (pedido #${r2.json?.order_id})`)), 'con nota que lo explica', viejo.notas);
    ok(wp('estado', S.canje.id).saldo === 0, 'los mismos 1.000 no se gastan dos veces');

    const tarde = wp('estado-pedido', rechazado?.id, 'processing');
    const viejoPagado = wp('pedido', rechazado?.id);
    ok(tarde.saldo === 0 && viejoPagado.notas.some((n) => /saldo solo cubrió 0/.test(n)), 'si el rechazado se paga igual (webhook tardío), queda anotado y avisado', { tarde, notas: viejoPagado.notas });
  }

  // ── R3 ────────────────────────────────────────────────────────────────
  console.log('R3 · pedido fallido pagado desde «pagar pedido»');
  {
    wp('saldo', S.canje.id, 1500);
    const antes = idsDe();
    const { api } = await prepararCarrito(ctx, page, 1000, S.sobre);
    await api.post('checkout', pago('akb_qa_rechazo'));
    const pedidoId = pendientes(antes)[0]?.id;
    ok(wp('estado', S.canje.id).saldo === 500, 'rechazado: quedan 500');
    const fallido = wp('estado-pedido', pedidoId, 'failed');
    ok(fallido.saldo === 1500, 'al fallar vuelven los 1.000', fallido);
    await vaciar(page);

    const pagarUrl = wp('pedido', pedidoId).pagar;
    const pagarCon = async (metodo) => {
      await page.goto(`${BASE}${ruta(pagarUrl)}`, { waitUntil: 'load', timeout: 90000 });
      await page.locator(`#payment_method_${metodo}`).check({ timeout: 30000 });
      const terminos = page.locator('#terms');
      if (await terminos.count()) await terminos.check().catch(() => {});
      await Promise.all([page.waitForLoadState('load').catch(() => {}), page.locator('#place_order').click()]);
      await page.waitForTimeout(3000);
    };

    await pagarCon('akb_qa_rechazo');
    let p = wp('pedido', pedidoId);
    ok(p.notas.some((n) => /re-cobrado al revivir/.test(n)), 'al pagar se re-cobran los 1.000 antes de ir a la pasarela', p.notas);
    ok(p.estado === 'failed' && p.deuda === 1000 && p.ventana === 0 && wp('estado', S.canje.id).saldo === 1500, 'la pasarela rechazó sin salir de la tienda: los 1.000 vuelven AL INSTANTE, sin esperar la revisión de una hora', { p, saldo: wp('estado', S.canje.id).saldo });
    const revision = wp('reintento', pedidoId, 'impago');
    ok(revision.saldo === 1500, 'la revisión agendada, al correr, no devuelve dos veces', revision);

    await pagarCon('bacs');
    p = wp('pedido', pedidoId);
    ok(p.estado === 'on-hold' && p.deuda === 0 && wp('estado', S.canje.id).saldo === 500, 'pagado por transferencia: re-cobrado una vez, en espera', { p, saldo: wp('estado', S.canje.id).saldo });
    ok(!p.notas.some((n) => /saldo solo cubrió/.test(n)), 'sin descuento regalado', p.notas);
  }

  // ── R5 ────────────────────────────────────────────────────────────────
  console.log('R5 · pedido reutilizado con otro canje y el mismo total');
  {
    wp('saldo', S.canje.id, 6000);
    const antes = idsDe();
    const { api } = await prepararCarrito(ctx, page, 1000, S.sobre);
    const total0 = Number((await api.carrito())?.totals?.total_price);
    const r1 = await api.post('checkout', pago('akb_qa_rechazo'));
    ok(r1.status === 400, 'la pasarela rechaza el pago');
    const pedidoA = pendientes(antes)[0];
    ok(pedidoA?.canjeado === 1000 && wp('estado', S.canje.id).saldo === 5000, 'rechazado con 1.000 cobrados');

    // Un recargo (como un despacho más caro) y el canje que lo compensa: se
    // mide cuánto subió el total y se busca el canje que lo deja igual.
    wp('opcion', 'akb_qa_recargo', 1700);
    const menor = 10 ** Number((await api.carrito())?.totals?.currency_minor_unit || 0);
    const subida = (Number((await api.carrito())?.totals?.total_price) - total0) / menor;
    let canje = 1000 + Math.round(subida);
    let ajustado = await api.post('cart/extensions', { namespace: 'akibara-rewards', data: { points: canje } });
    for (let i = 0; i < 4 && Number(ajustado.json?.totals?.total_price) !== total0; i += 1) {
      canje += Number(ajustado.json?.totals?.total_price) > total0 ? 1 : -1;
      ajustado = await api.post('cart/extensions', { namespace: 'akibara-rewards', data: { points: canje } });
    }
    const total1 = Number(ajustado.json?.totals?.total_price);
    ok(total1 === total0 && canje > 1000, `recargo y canje de ${canje}: el total queda igual`, { total0, total1, canje, fee: puntosDelCarrito(ajustado.json) });
    const r2 = await api.post('checkout', pago('bacs'));
    ok(r2.status === 200 && r2.json?.order_id === pedidoA?.id, 'WooCommerce reutiliza el mismo pedido', { status: r2.status, order: r2.json?.order_id, a: pedidoA?.id, message: r2.json?.message });
    const p = wp('pedido', pedidoA?.id);
    const cobrado = p.canjeado;
    ok(cobrado > 1000 && Math.abs(cobrado - Math.round(p.valor)) <= 1, 'el pedido cobra la diferencia: puntos y descuento cuadran', p);
    ok(p.notas.some((n) => new RegExp(`cambió de 1000 a ${cobrado} puntos`).test(n)), 'con nota del ajuste', p.notas);
    ok(wp('estado', S.canje.id).saldo === 6000 - cobrado, 'y el saldo baja exactamente lo cobrado', { saldo: wp('estado', S.canje.id).saldo, cobrado });
    wp('opcion', 'akb_qa_recargo', 0);
    await vaciar(page);
  }

  // ── R6 ────────────────────────────────────────────────────────────────
  console.log('R6 · la caja falla después de crear el pedido nuevo');
  {
    wp('saldo', S.canje.id, 2000);
    const antes = idsDe();
    const { api } = await prepararCarrito(ctx, page, 1000, S.sobre);
    await api.post('checkout', pago('akb_qa_rechazo'));
    const pedidoA = pendientes(antes)[0];
    ok(pedidoA?.canjeado === 1000 && wp('estado', S.canje.id).saldo === 1000, 'rechazado con 1.000 apartados');

    await agregar(page, S.sobre[0]);
    await api.post('cart/extensions', { namespace: 'akibara-rewards', data: { points: 2000 } });
    wp('opcion', 'akb_qa_fallar_tras_crear', 1);
    const r1 = await api.post('checkout', pago('bacs'));
    ok(r1.status === 400 && r1.json?.code === 'akb_qa_fallo', 'la caja falla después de crear el pedido', { status: r1.status, code: r1.json?.code });
    const borrador = pendientes(antes).find((x) => x.id !== pedidoA?.id);
    const b = borrador ? wp('pedido', borrador.id) : {};
    ok(borrador && b.reemplaza?.includes(pedidoA?.id), 'el pedido nuevo quedó creado y guarda a cuál reemplaza', { borrador, b });
    const carro = await api.carrito();
    ok(datosCaja(carro).balance === 2000 && puntosDelCarrito(carro) === 2000, 'al reintentar, la caja sigue contando los puntos del rechazado', { caja: datosCaja(carro), fee: puntosDelCarrito(carro) });
    const r2 = await api.post('checkout', pago('bacs'));
    ok(r2.status === 200 && r2.json?.order_id === borrador?.id, 'el reintento usa ese mismo pedido', { status: r2.status, order: r2.json?.order_id, message: r2.json?.message });
    const nuevo = wp('pedido', borrador?.id);
    const viejo = wp('pedido', pedidoA?.id);
    ok(nuevo.canjeado === 2000 && viejo.devuelto === 1000 && wp('estado', S.canje.id).saldo === 0, 'cobra 2.000: 1.000 del saldo y 1.000 liberados del rechazado', { nuevo, viejo });
    wp('opcion', 'akb_qa_fallar_tras_crear', 0);
    await vaciar(page);
  }

  // ── R7 ────────────────────────────────────────────────────────────────
  console.log('R7 · pasarela que redirige: sus puntos no se prestan');
  {
    wp('saldo', S.canje.id, 1000);
    const antes = idsDe();
    const { api } = await prepararCarrito(ctx, page, 1000, S.sobre);
    const r1 = await api.post('checkout', pago('akb_qa_redirige'));
    ok(r1.status === 200 && r1.json?.payment_result?.payment_status === 'success' && /order-pay|pagar/.test(r1.json?.payment_result?.redirect_url || ''), 'la pasarela acepta y redirige', { status: r1.status, pr: r1.json?.payment_result });
    const enPasarela = pendientes(antes)[0];
    const a = wp('pedido', enPasarela?.id);
    ok(a.estado === 'pending' && a.canjeado === 1000 && !a.rechazado, 'pedido pendiente en la pasarela, con 1.000 cobrados y sin marca de rechazo', a);
    ok(wp('estado', S.canje.id).saldo === 0, 'saldo 0');

    await agregar(page, S.sobre[0]); // Otra pestaña: el carrito cambia.
    await api.post('cart/extensions', { namespace: 'akibara-rewards', data: { points: 1000 } });
    const carro = await api.carrito();
    ok(datosCaja(carro).balance === 0 && puntosDelCarrito(carro) === 0, 'la compra nueva NO ve los 1.000 del pedido que se está pagando', { caja: datosCaja(carro), fee: puntosDelCarrito(carro) });

    await page.goto(`${BASE}/mi-cuenta/rewards/`, CARGA);
    const cuenta = limpio(await page.evaluate(() => document.body.innerText));
    ok(/Además tienes 1\.000 puntos apartados en un pedido sin pagar\. Si pagas ese pedido se usan ahí; si no, vuelven solos a tu saldo en unos \d+ minutos\./.test(cuenta), 'Mi cuenta dice dónde están y cuándo vuelven', cuenta.match(/Tu saldo.{0,400}/)?.[0]);

    const r2 = await api.post('checkout', pago('bacs'));
    const nuevo = wp('pedido', r2.json?.order_id);
    ok(r2.status === 200 && r2.json?.order_id !== enPasarela?.id && nuevo.canjeado === 0, 'la compra nueva sale sin descuento de puntos', { status: r2.status, nuevo, message: r2.json?.message });
    ok(wp('pedido', enPasarela?.id).devuelto === 0, 'el pedido en la pasarela conserva sus puntos');

    const tarde = wp('estado-pedido', enPasarela?.id, 'processing');
    const pagado = wp('pedido', enPasarela?.id);
    ok(tarde.saldo === 0 && !pagado.notas.some((n) => /saldo solo cubrió/.test(n)), 'pagado en la pasarela: un solo descuento con esos puntos y sin alarma', { tarde, notas: pagado.notas });
    await vaciar(page);
  }

  // ── R8 ────────────────────────────────────────────────────────────────
  console.log('R8 · rechazo y compra nueva sin canje');
  {
    wp('saldo', S.canje.id, 1000);
    const antes = idsDe();
    const { api } = await prepararCarrito(ctx, page, 500, S.sobre);
    const r1 = await api.post('checkout', pago('akb_qa_rechazo'));
    const rechazado = pendientes(antes)[0];
    ok(r1.status === 400 && wp('pedido', rechazado?.id).rechazado && wp('estado', S.canje.id).saldo === 500, 'rechazado con 500 apartados y marcado', wp('pedido', rechazado?.id));

    await api.post('cart/extensions', { namespace: 'akibara-rewards', data: { points: 0 } }); // «Quitar canje».
    await agregar(page, S.sobre[0]);
    const r2 = await api.post('checkout', pago('bacs'));
    const nuevo = wp('pedido', r2.json?.order_id);
    const viejo = wp('pedido', rechazado?.id);
    ok(r2.status === 200 && nuevo.canjeado === 0, 'la compra nueva sale sin canje', { status: r2.status, nuevo });
    ok(viejo.devuelto === 500 && viejo.deuda === 500 && wp('estado', S.canje.id).saldo === 1000, 'los 500 del rechazado vuelven al saldo al crear la compra nueva', { viejo, saldo: wp('estado', S.canje.id).saldo });
    await vaciar(page);
  }

  // ── R4 ────────────────────────────────────────────────────────────────
  console.log('R4 · «Quitar canje» bajo el mínimo y en Mi cuenta');
  {
    wp('saldo', S.canje.id, 1000);
    const barato = [...S.sobre].sort((a, b) => a.precio - b.precio)[0];
    const { api } = await prepararCarrito(ctx, page, 1000, [barato]);
    const carro = await api.carrito();
    if (!(datosCaja(carro).missing > 0)) {
      console.log(`  --  omitido: el tomo más barato ($${barato.precio}) ya llega al mínimo ($${S.min_cart})`);
    } else {
      ok(datosCaja(carro).intent === 1000 && puntosDelCarrito(carro) === 0, 'bajo el mínimo: canje guardado sin descuento', datosCaja(carro));
      await page.goto(`${BASE}/checkout/`, CARGA);
      const caja = page.locator('.akb-rw-checkout-box').first();
      await caja.waitFor({ timeout: 60000 });
      await page.waitForTimeout(2000);
      ok(/se aplicará solo al llegar/.test(limpio(await caja.innerText())), 'la caja avisa que se aplicará al llegar al mínimo', limpio(await caja.innerText()));
      const quitar = caja.getByRole('button', { name: 'Quitar canje' });
      ok(await quitar.count() === 1, 'y ofrece «Quitar canje»');
      if (await quitar.count()) {
        await quitar.click();
        await page.waitForTimeout(3000);
        ok(datosCaja(await api.carrito()).intent === 0, 'al pulsarlo, el canje se quita');
      }
    }

    await api.post('cart/extensions', { namespace: 'akibara-rewards', data: { points: 1000 } });
    wp('saldo', S.canje.id, 0);

    await agregar(page, S.sobre[0]);
    await page.goto(`${BASE}/checkout/`, CARGA);
    const cajaSinSaldo = page.locator('.akb-rw-checkout-box').first();
    await cajaSinSaldo.waitFor({ timeout: 60000 }).catch(() => {});
    await page.waitForTimeout(2000);
    const textoCaja = limpio(await cajaSinSaldo.innerText().catch(() => ''));
    ok(/no se aplica: hoy no tienes saldo/.test(textoCaja) && /Quitar canje/.test(textoCaja), 'sin saldo, la caja del checkout sigue mostrando el canje guardado con «Quitar canje»', textoCaja);

    await page.goto(`${BASE}/mi-cuenta/rewards/`, CARGA);
    const texto = limpio(await page.evaluate(() => document.body.innerText));
    ok(/Tienes un canje de 1\.000 puntos listo \(hoy no tienes saldo para aplicarlo\)/.test(texto), 'Mi cuenta sin saldo explica el canje guardado', texto.match(/Tu saldo.{0,400}/)?.[0]);
    const boton = page.locator('form button', { hasText: 'Quitar canje' });
    ok(await boton.count() === 1, 'y deja quitarlo');
    if (await boton.count()) {
      await Promise.all([page.waitForLoadState('load').catch(() => {}), boton.click()]);
      await page.waitForTimeout(2000);
      ok(!/Tienes un canje de/.test(limpio(await page.evaluate(() => document.body.innerText))), 'quitado desde Mi cuenta');
    }
    await vaciar(page);
  }

  ok(erroresJs.length === 0, 'sin excepciones de JavaScript', erroresJs);
  await ctx.close();
} catch (e) {
  fallos += 1;
  console.error('FAIL: excepción', e);
} finally {
  await nav.close();
  retirarPasarela();
  const fuera = wp('limpiar');
  console.log('Limpieza', JSON.stringify(fuera));
  ok(!fs.existsSync(PASARELA_VIVA), 'la pasarela de QA ya no está en mu-plugins');
}

console.log(fallos ? `\n${fallos} FALLO(S)` : '\nPASS');
process.exit(fallos ? 1 : 0);
