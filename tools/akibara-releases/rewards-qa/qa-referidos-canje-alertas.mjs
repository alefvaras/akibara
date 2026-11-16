// QA Playwright del ESPEJO — rewards 0.4.1 (defectos hallados el 2026-09-13
// al escribir el manual del dueño).
//
//   A. El enlace de invitación liga al invitado de verdad: la cookie la escribe
//      el navegador (Cloudflare quitaba el Set-Cookie de PHP), el registro la
//      sella, la compra completada paga al padrino y le llega su correo.
//   B. Canje con saldo que cambió mientras se compraba: la caja DETIENE la
//      compra (API y pantalla) y el segundo intento cobra lo que hay.
//   C. La alerta al dueño llega una sola vez.
//   D. La invitación a reseñar sale con disparador «Procesando» aunque el
//      pedido ya se haya completado.
//   E. El panel: casillas de correos reales y destinatarios validados.
//
// Uso: node qa-referidos-canje-alertas.mjs   (siembra y limpia solo)
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const SEMILLA = `${CWD}/tools/akibara-releases/rewards-qa/sembrar-referidos-canje.php`;
const UA = 'Mozilla/5.0 (AkibaraQA) Playwright';
const CARGA = { waitUntil: 'domcontentloaded', timeout: 90000 };
const MAILPIT = 'http://localhost:8025';
const INICIO = Date.now();

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 600)}`}`);
};
const limpio = (t) => String(t || '').replace(/\s+/g, ' ').trim();
const ruta = (u) => (u ? new URL(u).pathname : '/');

// La última línea con JSON: WP-CLI puede anteponer avisos de plugins.
const wp = (...args) => {
  const salida = execSync(`docker compose exec -T cron wp eval-file - ${args.map((a) => `'${String(a).replace(/'/g, '')}'`).join(' ')} < '${SEMILLA}'`, { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24 });
  const linea = salida.trim().split('\n').reverse().find((l) => l.startsWith('{'));
  return JSON.parse(linea || '{}');
};

const correos = async (para) => {
  const r = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${para}`)}&limit=50`);
  const d = await r.json();
  return (d.messages || []).filter((m) => new Date(m.Created).getTime() >= INICIO - 5000);
};
const cuerpo = async (id) => (await (await fetch(`${MAILPIT}/api/v1/message/${id}`)).json());

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

// Store API con la sesión del navegador: el nonce viaja en la cabecera.
async function tienda(ctx) {
  const r = await ctx.request.get(`${BASE}/wp-json/wc/store/v1/cart`);
  const nonce = r.headers().nonce;
  const post = async (camino, datos) => {
    const resp = await ctx.request.post(`${BASE}/wp-json/wc/store/v1/${camino}`, { headers: { Nonce: nonce, 'Content-Type': 'application/json' }, data: datos, timeout: 120000 });
    let json = {};
    try { json = await resp.json(); } catch { /* sin cuerpo */ }
    return { status: resp.status(), json };
  };
  return { nonce, post };
}

const puntosDelCarrito = (cart) => {
  const fee = (cart?.fees || []).find((f) => f.name === 'Puntos Akibara');
  if (!fee) return 0;
  return -(Number(fee.totals.total) + Number(fee.totals.total_tax)) / 10 ** Number(fee.totals.currency_minor_unit || 0);
};

const DIRECCION = {
  first_name: 'QA', last_name: 'Canje', company: '', address_1: 'Av. Providencia 1234', address_2: '',
  // ChileanPhone rechaza números que empiezan en 0 o 1 tras el 9.
  city: 'Santiago', state: 'CL-RM', postcode: '', country: 'CL', phone: '+56987654321',
};

console.log('Siembra');
const S = wp('sembrar');
ok(S.padrino?.codigo, 'padrino, cliente con saldo y administrador de QA creados', S);

const nav = await chromium.launch();
try {
  // ── A. Referidos de punta a punta ──────────────────────────────────────
  console.log('A · enlace de invitación');
  {
    const ctx = await nav.newContext({ viewport: { width: 390, height: 844 }, userAgent: UA });
    const page = await ctx.newPage();
    const erroresJs = [];
    page.on('pageerror', (e) => erroresJs.push(String(e).slice(0, 160)));

    await page.goto(`${BASE}/?ref=${S.padrino.codigo.toUpperCase()}`, CARGA);
    await page.waitForTimeout(1500);
    const cookie = (await ctx.cookies()).find((c) => c.name === 'akb_ref');
    ok(cookie && cookie.value === S.padrino.codigo, 'el navegador guarda la cookie con el código del padrino', cookie);
    ok(cookie && cookie.expires > Date.now() / 1000 + 29 * 86400, 'y la recuerda 30 días', cookie && cookie.expires);
    ok((await page.content()).includes('akb-rewards-ref-js-after'), 'el script de captura va en la página (sobrevive a la caché)');

    await page.goto(`${BASE}/mi-cuenta/`, { waitUntil: 'load', timeout: 90000 });
    const registro = page.locator('#reg_email');
    // El registro vive plegado bajo «Crear cuenta con correo y contraseña».
    await page.getByText('Crear cuenta con correo y contraseña').first().click();
    try {
      await registro.waitFor({ state: 'visible', timeout: 60000 });
    } catch (e) {
      await page.screenshot({ path: '/tmp/qa-registro-oculto.png', fullPage: true });
      throw e;
    }
    await registro.scrollIntoViewIfNeeded();
    await registro.fill(S.invitada);
    await Promise.all([page.waitForLoadState('load').catch(() => {}), page.locator('button[name="register"]').click()]);
    await page.waitForTimeout(2500);
    ok((await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in')), 'la invitada crea su cuenta', page.url());
    ok(erroresJs.length === 0, 'sin excepciones de JavaScript', erroresJs);

    const invitada = wp('usuario-por-correo', S.invitada);
    ok(invitada.referido_por === S.padrino.id, 'la cuenta nueva queda ligada a su padrino', invitada);

    const compra = wp('compra', invitada.id);
    ok(compra.saldo_despues - compra.saldo_antes === 500, 'su primera compra completada paga 500 puntos al padrino', compra);
    ok(!compra.notas.some((n) => /no se pagó el premio/.test(n)), 'y no deja nota de premio no pagado', compra.notas);

    await page.waitForTimeout(1500);
    const avisos = (await correos('qa.padrino@akibara.invalid')).filter((m) => /Tu invitación sumó 500/.test(m.Subject));
    ok(avisos.length === 1, 'al padrino le llega UN correo «Tu invitación sumó 500 Puntos Akibara»', avisos.map((m) => m.Subject));
    if (avisos[0]) {
      const m = await cuerpo(avisos[0].ID);
      ok(!String(m.HTML).includes(S.invitada), 'el correo no revela el correo de quien compró');
      ok(/mi-cuenta\/rewards/.test(String(m.HTML)), 'y lleva el botón a su saldo');
      ok(String(m.Text || '').length > 50, 'con parte de texto plano');
    }

    // Una dirección con algo que no puede ser un código no escribe nada.
    const ctx2 = await nav.newContext({ userAgent: UA });
    const p2 = await ctx2.newPage();
    await p2.goto(`${BASE}/?ref=abc%3Cscript%3E`, CARGA);
    await p2.waitForTimeout(1200);
    ok(!(await ctx2.cookies()).some((c) => c.name === 'akb_ref'), 'un ref con marcado no deja cookie');
    await ctx2.close();
    await ctx.close();
  }

  console.log('A · Mi cuenta del padrino');
  {
    const ctx = await nav.newContext({ viewport: { width: 1440, height: 950 }, userAgent: UA });
    const page = await ctx.newPage();
    ok(await entrar(ctx, page, S.padrino.login, S.padrino.clave), 'sesión del padrino');
    await page.goto(`${BASE}/mi-cuenta/rewards/`, CARGA);
    const texto = limpio(await page.evaluate(() => document.body.innerText));
    ok(texto.includes('(ya llevas 1)'), 'Mi cuenta cuenta su premio vigente', texto.match(/Invita a un amigo.{0,300}/)?.[0]);
    await ctx.close();
  }

  // ── B. Canje con saldo que cambió mientras se compraba ─────────────────
  console.log('B · la caja detiene la compra (Store API)');
  {
    const ctx = await nav.newContext({ viewport: { width: 1440, height: 950 }, userAgent: UA });
    const page = await ctx.newPage();
    ok(await entrar(ctx, page, S.canje.login, S.canje.clave), 'sesión del cliente con saldo');
    await vaciar(page);
    for (const tomo of S.sobre) await agregar(page, tomo);

    const api = await tienda(ctx);
    const cliente = await api.post('cart/update-customer', { billing_address: { ...DIRECCION, email: S.canje.email }, shipping_address: DIRECCION });
    const tarifas = cliente.json?.shipping_rates?.[0]?.shipping_rates || [];
    const retiro = tarifas.find((t) => /pickup/.test(t.method_id)) || tarifas[0];
    ok(retiro, 'hay una forma de entrega para la dirección de prueba', tarifas.map((t) => t.method_id));
    if (retiro) await api.post('cart/select-shipping-rate', { package_id: 0, rate_id: retiro.rate_id });

    const aplicado = await api.post('cart/extensions', { namespace: 'akibara-rewards', data: { points: 2000 } });
    ok(puntosDelCarrito(aplicado.json) === 2000, 'el carrito aplica el canje de 2.000 puntos', aplicado.json?.fees);

    const tras = wp('saldo', S.canje.id, 500);
    ok(tras.saldo === 500, 'otro equipo gasta el saldo: quedan 500', tras);

    // Sin carrera: la caja recalcula el carrito al pagar con el saldo real y el
    // pedido nace con el descuento que ese saldo respalda. La detención es
    // para la carrera de verdad (dos equipos a la vez), más abajo.
    const pago = { billing_address: { ...DIRECCION, email: S.canje.email }, shipping_address: DIRECCION, payment_method: 'bacs', additional_fields: { 'akibara/rut': '12.345.678-5' }, customer_note: '' };
    const compra = await api.post('checkout', pago);
    ok(compra.status === 200 && compra.json.order_id > 0, 'con el saldo ya gastado, la compra sigue con el descuento recalculado', { status: compra.status, code: compra.json.code, message: compra.json.message });
    if (compra.json.order_id) {
      const pedido = wp('pedido', compra.json.order_id);
      ok(pedido.canjeado === 500 && Math.round(pedido.valor) === 500, 'el pedido descuenta y cobra exactamente los 500 que había', pedido);
      ok(wp('estado', S.canje.id).saldo === 0, 'y el saldo queda en 0');
      ok(!pedido.notas.some((n) => /saldo solo cubrió/.test(n)), 'sin descuento regalado', pedido.notas);
    }
    await ctx.close();
  }

  console.log('B · la caja detiene la compra (pantalla)');
  {
    const ctx = await nav.newContext({ viewport: { width: 1440, height: 950 }, userAgent: UA });
    const page = await ctx.newPage();
    await entrar(ctx, page, S.canje.login, S.canje.clave);
    wp('saldo', S.canje.id, 3000);
    await vaciar(page);
    for (const tomo of S.sobre) await agregar(page, tomo);
    const api = await tienda(ctx);
    await api.post('cart/extensions', { namespace: 'akibara-rewards', data: { points: 2000 } });

    await page.goto(`${BASE}/checkout/`, CARGA);
    await page.waitForSelector('.wc-block-components-checkout-place-order-button', { timeout: 60000 });
    await page.waitForTimeout(3000);
    const rut = page.locator('input[id$="akibara-rut"]').first();
    if (await rut.count() && !(await rut.inputValue())) await rut.fill('12.345.678-5');
    const retiroUi = page.locator('input[value*="pickup"]').first();
    if (await retiroUi.count()) await retiroUi.check().catch(() => {});

    wp('saldo', S.canje.id, 500);
    await page.locator('.wc-block-components-checkout-place-order-button').click();
    const aviso = page.locator('.wc-block-components-notice-banner.is-error, .wc-block-store-notice.is-error').first();
    await aviso.waitFor({ timeout: 60000 }).catch(() => {});
    const textoAviso = limpio(await aviso.innerText().catch(() => ''));
    // Con el saldo cambiado ANTES de pulsar, el carrito se recalcula al pagar y
    // la guarda de WooCommerce («el total cambió») detiene la compra primero.
    // Lo que se exige es lo que protege a la tienda y al cliente: se detiene,
    // se explica, y el resumen queda con el descuento real.
    ok(/saldo de Puntos Akibara cambió|order total changed|total del pedido cambió/i.test(textoAviso), 'el cliente ve un aviso que detiene la compra', textoAviso);
    await page.waitForTimeout(2500);
    const totales = limpio(await page.locator('.wp-block-woocommerce-checkout-order-summary-block, .wc-block-components-order-summary').first().innerText().catch(() => ''));
    ok(/Puntos Akibara/.test(totales) && /-\s?\$\s?500\b/.test(totales), 'y el resumen ya muestra el descuento de 500', totales.slice(0, 400));
    ok(/checkout/.test(page.url()), 'sigue en la caja, sin pedido creado', page.url());
    await page.screenshot({ path: '/tmp/qa-canje-saldo-cambio.png', fullPage: false });
    ok(wp('estado', S.canje.id).saldo === 500, 'el saldo sigue intacto');
    await vaciar(page);
    await ctx.close();
  }

  // ── B'. La carrera de verdad: dos equipos pagan a la vez ────────────────
  console.log('B · dos equipos pagan a la vez con el mismo saldo');
  {
    wp('saldo', S.canje.id, 3000);
    const equipos = [];
    for (let i = 0; i < 2; i += 1) {
      const ctx = await nav.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA });
      const page = await ctx.newPage();
      await entrar(ctx, page, S.canje.login, S.canje.clave);
      equipos.push({ ctx, page });
    }
    // El carrito con sesión es persistente y lo comparten los dos equipos: se
    // arma una vez y cada sesión fija su propio canje de 2.000.
    await vaciar(equipos[0].page);
    for (const tomo of S.sobre) await agregar(equipos[0].page, tomo);
    const apis = [];
    for (const { ctx, page } of equipos) {
      await page.goto(`${BASE}/checkout/`, CARGA);
      const api = await tienda(ctx);
      await api.post('cart/update-customer', { billing_address: { ...DIRECCION, email: S.canje.email }, shipping_address: DIRECCION });
      const r = await api.post('cart/extensions', { namespace: 'akibara-rewards', data: { points: 2000 } });
      ok(puntosDelCarrito(r.json) === 2000, 'cada equipo ve su canje de 2.000', r.json?.fees);
      apis.push(api);
    }
    const pago = { billing_address: { ...DIRECCION, email: S.canje.email }, shipping_address: DIRECCION, payment_method: 'bacs', additional_fields: { 'akibara/rut': '12.345.678-5' }, customer_note: '' };
    const [a, b] = await Promise.all(apis.map((api) => api.post('checkout', pago)));
    const creados = [a, b].filter((r) => r.status === 200 && r.json.order_id);
    const detenidos = [a, b].filter((r) => r.json.code === 'akb_rewards_balance_changed');
    console.log(`    resultado: ${[a, b].map((r) => `${r.status} ${r.json.code || r.json.order_id}`).join(' · ')}`);
    ok(creados.length >= 1, 'al menos una compra se completa', [a, b].map((r) => r.json.message || r.json.order_id));
    let descontado = 0;
    for (const r of creados) {
      const pedido = wp('pedido', r.json.order_id);
      descontado += Math.round(pedido.valor);
      ok(pedido.canjeado === Math.round(pedido.valor), `el pedido #${r.json.order_id} cobró cada punto que descontó`, pedido);
    }
    const saldo = wp('estado', S.canje.id).saldo;
    ok(3000 - saldo === descontado, 'lo descontado en pedidos es exactamente lo que salió del saldo: la tienda no regala nada', { descontado, saldo });
    if (detenidos.length) ok(puntosDelCarrito(detenidos[0].json?.data?.cart) === saldo, 'el equipo detenido recibe el carrito con el saldo que queda', detenidos[0].json?.data?.cart?.fees);

    // Revisión independiente: el pedido detenido quedaba «pendiente» con el
    // descuento entero y se podía pagar después. Ninguno puede quedar así.
    const lista = wp('pedidos', S.canje.id).pedidos || [];
    const pagables = lista.filter((o) => o.fee > 0 && o.canjeado === 0 && ['pending', 'on-hold', 'failed', 'processing', 'completed'].includes(o.estado));
    ok(pagables.length === 0, 'no queda ningún pedido pagable con un descuento de puntos sin cobrar', lista);
    if (detenidos.length) ok(lista.some((o) => o.estado === 'checkout-draft' && o.canjeado === 0), 'el pedido detenido volvió a borrador', lista);
    for (const { page, ctx } of equipos) { await vaciar(page); await ctx.close(); }
  }

  // ── B''. Pagar desde Mi cuenta un pedido cuyo descuento ya no tiene saldo ──
  console.log('B · «pagar pedido» con un descuento que el saldo no cubre');
  {
    wp('saldo', S.canje.id, 0);
    const pendiente = wp('pendiente-con-canje', S.canje.id, 1000);
    const ctx = await nav.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA });
    const page = await ctx.newPage();
    await entrar(ctx, page, S.canje.login, S.canje.clave);
    await page.goto(pendiente.pagar, CARGA);
    await page.waitForSelector('#place_order', { timeout: 60000 });
    const bacs = page.locator('#payment_method_bacs');
    if (await bacs.count()) await bacs.check().catch(() => {});
    const terminos = page.locator('#terms');
    if (await terminos.count()) await terminos.check().catch(() => {});
    await Promise.all([page.waitForLoadState('load').catch(() => {}), page.locator('#place_order').click()]);
    await page.waitForTimeout(2500);
    const aviso = limpio(await page.locator('.woocommerce-error, .woocommerce-NoticeGroup, .wc-block-components-notice-banner.is-error').allInnerTexts().then((t) => t.join(' | ')).catch(() => ''));
    ok(/ya no cubre el descuento de este pedido/.test(aviso), 'el cliente ve por qué no se puede pagar', aviso);
    const estado = wp('pedido', pendiente.pedido);
    ok(estado.estado === 'pending', 'el pedido sigue pendiente: ni se cobra con el descuento regalado ni se da por pagado', estado);
    ok(!estado.notas.some((n) => /Pago completado|saldo solo cubrió/.test(n)), 'sin «Pago completado» ni cobro parcial', estado.notas);
    ok(wp('estado', S.canje.id).saldo === 0, 'y el saldo no se toca');
    await page.screenshot({ path: '/tmp/qa-pagar-pedido-sin-saldo.png' });
    await ctx.close();
  }

  // ── C. Alerta al dueño ─────────────────────────────────────────────────
  console.log('C · alerta al dueño');
  {
    const alerta = wp('alerta');
    await new Promise((r) => setTimeout(r, 1500));
    // El espejo desvía todo correo a una dirección real hacia capturado@akibara.test
    // y deja el destinatario original en el asunto: «[para …]».
    const recibidas = (await correos('capturado@akibara.test'))
      .filter((m) => m.Subject.includes(`[para ${alerta.para[0]}]`) && m.Subject.endsWith(`#${alerta.pedido}`));
    ok(recibidas.length === 1, 'la alerta de canje sin saldo llega UNA vez al correo de administración', { para: alerta.para, asuntos: recibidas.map((m) => m.Subject) });
    if (recibidas[0]) {
      const m = await cuerpo(recibidas[0].ID);
      ok(/600 puntos/.test(String(m.HTML)) && /post\.php\?post=|page=wc-orders/.test(String(m.HTML)), 'dice cuánto faltó y enlaza el pedido');
    }
  }

  // ── D. Invitación con disparador «Procesando» ─────────────────────────
  console.log('D · invitación a reseñar');
  {
    const inv = wp('invitacion');
    ok(inv.agendadas_procesando === 1, 'al entrar a Procesando se agenda la invitación', inv);
    ok(inv.agendadas_completado === 1, 'completarse no agenda otra', inv);
    ok(inv.enviada !== '', 'la invitación sale aunque el pedido ya esté completado', inv);
  }

  // ── E. Panel ───────────────────────────────────────────────────────────
  console.log('E · panel de Puntos y Reseñas');
  {
    const ctx = await nav.newContext({ viewport: { width: 1440, height: 950 }, userAgent: UA });
    const page = await ctx.newPage();
    ok(await entrar(ctx, page, S.dueno.login, S.dueno.clave), 'sesión del administrador de QA');
    await page.goto(`${BASE}/wp-admin/admin.php?page=akb-rewards-settings`, CARGA);
    const titulos = await page.locator('.wrap h2').allInnerTexts();
    ok(titulos.includes('Alertas'), 'el panel tiene la sección Alertas', titulos);
    const completado = page.locator('input[name="akb_rewards_nudge_email_ids[]"][value="customer_completed_order"]');
    ok((await completado.count()) === 1 && (await completado.isChecked()), '«Pedido completado» aparece marcado como casilla, no como código');
    const etiquetas = await page.locator('input[name="akb_rewards_nudge_email_ids[]"]').evaluateAll((ns) => ns.map((n) => n.parentElement.innerText.trim()));
    ok(etiquetas.length >= 5 && !etiquetas.some((t) => /^customer_/.test(t)), 'las casillas llevan los nombres de los correos', etiquetas.slice(0, 6));

    await page.locator('input[name="akb_rewards_nudge_email_ids[]"][value="customer_processing_order"]').check();
    await page.fill('#akb_rewards_alerts_email', 'contacto@akibara.cl, basura');
    await Promise.all([page.waitForLoadState('load').catch(() => {}), page.locator('#submit, input[type="submit"][value="Guardar ajustes"]').first().click()]);
    await page.waitForTimeout(1500);
    const avisos = limpio(await page.locator('.notice').allInnerTexts().then((t) => t.join(' | ')));
    ok(/se descartó lo que no es un correo: basura/.test(avisos), 'descartar un destinatario inválido se avisa en pantalla', avisos);
    ok((await page.inputValue('#akb_rewards_alerts_email')) === 'contacto@akibara.cl', 'y queda guardado sólo el correo válido');
    ok(await page.locator('input[name="akb_rewards_nudge_email_ids[]"][value="customer_processing_order"]').isChecked(), 'la casilla marcada queda guardada');
    await page.screenshot({ path: '/tmp/qa-panel-alertas.png', fullPage: true });
    await ctx.close();
  }
} finally {
  await nav.close();
  console.log('Limpieza');
  const fuera = wp('limpiar');
  console.log(`  ${JSON.stringify(fuera)}`);
}

if (fallos) {
  console.error(`FAIL: ${fallos} comprobación(es) rotas`);
  process.exit(1);
}
console.log('PASS: referidos, canje en la caja, alertas, invitación y panel');
