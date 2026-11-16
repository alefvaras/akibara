// QA del FLUJO COMPLETO del cliente de «Avísame cuando vuelva» — ESPEJO LOCAL.
//
// Recorre el carril entero como una persona real, con clics de verdad, contra
// http://localhost:8080 (Docker) y Mailpit (http://localhost:8025):
//
//   1. Ficha de un producto agotado → ¿se ofrece el formulario?
//   2. Rellenar y PULSAR el botón «Avisarme».
//   3. Mensaje de confirmación que ve el cliente (texto exacto).
//   4. Correo de alta en Mailpit: asunto, nombre del producto, enlace de baja.
//   5. Segunda alta con el mismo correo: ni duplica ni da error feo.
//   6. Reposición → aviso de vuelta a stock (nombre, precio, botón, baja).
//   7. PULSAR el enlace de baja del correo: ¿funciona? ¿dice de qué producto?
//   8. Volver a la ficha: ¿el formulario vuelve a ofrecerse?
//
// SOLO LOCAL. No toca producción. Todo lo que siembra lo borra POR ID (nunca
// por email) y devuelve el stock a su valor original.
//
//   node tools/akibara-releases/bis-qa/qa-bis-flujo-cliente-local.mjs
//
// Peticiones marcadas con AkibaraQA (regla de la casa: el qlog es la voz del
// cliente y no se contamina, ni siquiera en el espejo).

import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const REPO      = '/Users/alefvaras/Documents/akibara';
const BASE      = 'http://localhost:8080';
const MAILPIT   = 'http://localhost:8025';
const UA_QA     = 'AkibaraQA/1.0 (Playwright; QA flujo cliente BIS local, business 1.114.2)';
const PRODUCT   = 24702;                       // Claymore 27 — agotado en el espejo.
const PDP       = `${BASE}/product/claymore-27-panini-argentina/`;
const SELLO     = Date.now().toString(36);
const EMAIL     = `qa.bis.flujo.${SELLO}@example.com`;
const SHOTS     = '/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/d5a6502d-96ec-4b2f-bfd8-ffb8f9cd378c/scratchpad/bis-shots';

mkdirSync(SHOTS, { recursive: true });

// ── Registro de resultados ───────────────────────────────────────────────────
const filas = [];
let fallos = 0;
const marcar = (paso, caso, ok, evidencia = '') => {
  if (!ok) fallos++;
  filas.push({ paso, caso, ok, evidencia });
  console.log(`  ${ok ? 'OK   ' : 'FALLA'} [${paso}] ${caso}${evidencia ? `\n         → ${evidencia}` : ''}`);
};
const nota = (txt) => console.log(`  ·     ${txt}`);

// ── WP-CLI del espejo (solo preparar / observar / limpiar) ───────────────────
function wp(args) {
  const out = execFileSync(
    'docker',
    ['compose', '--profile', 'tools', 'run', '--rm', '-T', 'cli', 'wp', ...args],
    { cwd: REPO, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 32 * 1024 * 1024 }
  );
  return out.trim();
}
const sql = (q) => wp(['db', 'query', q]).split('\n').map((l) => l.trim()).filter(Boolean);

// ── Mailpit ─────────────────────────────────────────────────────────────────
async function mailpitBuscar(destinatario, desdeIso) {
  const r = await fetch(`${MAILPIT}/api/v1/messages?limit=200`);
  const j = await r.json();
  return (j.messages || []).filter(
    (m) =>
      (m.To || []).some((t) => (t.Address || '').toLowerCase() === destinatario.toLowerCase()) &&
      new Date(m.Created) >= new Date(desdeIso)
  );
}
async function esperarCorreo(destinatario, desdeIso, predicado, segundos = 60) {
  const hasta = Date.now() + segundos * 1000;
  while (Date.now() < hasta) {
    const msgs = await mailpitBuscar(destinatario, desdeIso);
    const hit = msgs.find((m) => predicado(m));
    if (hit) {
      const r = await fetch(`${MAILPIT}/api/v1/message/${hit.ID}`);
      return await r.json();
    }
    await new Promise((res) => setTimeout(res, 1500));
  }
  return null;
}

// ── Estado inicial (para restaurar) ─────────────────────────────────────────
const T0 = new Date(Date.now() - 5000).toISOString();
const estadoInicial = sql(`SELECT meta_value FROM wp_postmeta WHERE post_id=${PRODUCT} AND meta_key='_stock_status'`)[1];
const idMaxInicial = parseInt(sql('SELECT COALESCE(MAX(id),0) FROM wp_akb_bis_subscribers')[1], 10);
const filasIniciales = parseInt(sql('SELECT COUNT(*) FROM wp_akb_bis_subscribers')[1], 10);

console.log('\n════════════════════════════════════════════════════════════════');
console.log(' QA flujo cliente BIS — espejo local (business 1.114.2)');
console.log('════════════════════════════════════════════════════════════════');
console.log(` producto        : #${PRODUCT} (stock inicial: ${estadoInicial})`);
console.log(` email de prueba : ${EMAIL}`);
console.log(` baseline tabla  : ${filasIniciales} filas, max(id)=${idMaxInicial}`);

const browser = await chromium.launch();
const ctx = await browser.newContext({ userAgent: UA_QA, viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
const erroresJs = [];
page.on('console', (m) => { if (m.type() === 'error') erroresJs.push(m.text()); });
page.on('pageerror', (e) => erroresJs.push(`pageerror: ${e.message}`));

let unsubDesdeAlta = '';
let unsubDesdeRestock = '';
let idsSembrados = [];

try {
  // ═══ 1 · La ficha agotada ofrece el formulario ════════════════════════════
  console.log('\n── 1 · Ficha del producto agotado');
  const resp = await page.goto(PDP, { waitUntil: 'domcontentloaded' });
  marcar(1, 'la ficha carga', resp.status() === 200, `HTTP ${resp.status()}`);

  const form = page.locator('#akb-bis-form');
  marcar(1, 'se ofrece el formulario «Avísame cuando vuelva»', await form.count() === 1);

  const titulo = (await page.locator('.akb-bis__title').textContent() || '').trim();
  const lede   = (await page.locator('.akb-bis__lede').textContent() || '').trim();
  const label  = (await page.locator('.akb-bis__label').textContent() || '').trim();
  const boton  = (await page.locator('.akb-bis__submit').textContent() || '').trim();
  const ph     = await page.locator('.akb-bis__email').getAttribute('placeholder');
  nota(`titular    : «${titulo}»`);
  nota(`bajada     : «${lede}»`);
  nota(`etiqueta   : «${label}» · placeholder «${ph}» · botón «${boton}»`);
  marcar(1, 'el formulario explica qué pasa al enviarlo', /agotado/i.test(lede) && /vuelva a stock/i.test(lede), lede);

  // ¿Hay algo que compita con el formulario? (add to cart no debería existir)
  const addToCart = await page.locator('form.cart button[type="submit"], .single_add_to_cart_button').count();
  marcar(1, 'no hay botón de compra en la ficha agotada', addToCart === 0, `botones de compra: ${addToCart}`);

  await page.locator('#akb-bis-form').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${SHOTS}/01-form-agotado.png`, clip: await page.locator('#akb-bis-form').boundingBox() });

  // ═══ 2-3 · Rellenar y PULSAR el botón ═════════════════════════════════════
  console.log('\n── 2/3 · Alta: escribir el email y pulsar «Avisarme»');
  const tAlta = new Date(Date.now() - 2000).toISOString();
  await page.locator('#akb-bis-email').click();
  await page.locator('#akb-bis-email').type(EMAIL, { delay: 25 });
  await page.locator('.akb-bis__submit').click();          // clic real, no submit programático.

  await page.locator('#akb-bis-message').waitFor({ state: 'visible', timeout: 15000 });
  const msgAlta = (await page.locator('#akb-bis-message').textContent() || '').trim();
  const claseAlta = await page.locator('#akb-bis-message').getAttribute('class');
  const formOculto = await page.locator('.akb-bis__form').isHidden();
  nota(`mensaje visto: «${msgAlta}»  (clase: ${claseAlta})`);
  marcar(3, 'el cliente ve una confirmación positiva', /is-ok/.test(claseAlta || ''), `clase «${claseAlta}»`);
  marcar(3, 'el mensaje dice que le avisarán', /te avisaremos/i.test(msgAlta), `«${msgAlta}»`);
  marcar(3, 'el formulario se retira tras el alta (no invita a reenviar)', formOculto);
  await page.screenshot({ path: `${SHOTS}/03-confirmacion.png`, clip: await page.locator('#akb-bis-form').boundingBox() });

  // ¿quedó persistido?
  const fila = sql(`SELECT id,status,token FROM wp_akb_bis_subscribers WHERE product_id=${PRODUCT} AND email='${EMAIL}'`);
  const persistida = fila.length > 1;
  marcar(3, 'la suscripción quedó persistida', persistida, fila.slice(1).join(' | '));
  if (persistida) {
    idsSembrados = [parseInt(fila[1].split('\t')[0], 10)];
    nota(`fila sembrada: id=${idsSembrados[0]}, estado=${fila[1].split('\t')[1]}`);
  }

  // ═══ 4 · Correo de alta en Mailpit ════════════════════════════════════════
  console.log('\n── 4 · Correo de alta (Mailpit)');
  const alta = await esperarCorreo(EMAIL, tAlta, (m) => /lista de espera/i.test(m.Subject || ''), 45);
  marcar(4, 'llega el correo de alta', !!alta, alta ? `asunto: «${alta.Subject}»` : 'no llegó en 45 s');
  if (alta) {
    const html = alta.HTML || '';
    const texto = (alta.Text || '').replace(/\s+/g, ' ');
    nota(`asunto: «${alta.Subject}»`);
    nota(`de    : ${(alta.From && alta.From.Address) || '?'}`);
    marcar(4, 'el asunto nombra el producto', /Claymore 27/.test(alta.Subject || ''), `«${alta.Subject}»`);
    marcar(4, 'el cuerpo nombra el producto', /Claymore 27/.test(html), '');
    const m = html.match(/href="([^"]*akb_bis_unsub=[a-f0-9]{64}[^"]*)"/i);
    unsubDesdeAlta = m ? m[1].replace(/&amp;/g, '&') : '';
    marcar(4, 'el correo trae enlace de baja', !!unsubDesdeAlta, unsubDesdeAlta || 'sin enlace akb_bis_unsub');
    marcar(4, 'trae cabecera List-Unsubscribe (baja de un clic del inbox)',
      !!(alta.ListUnsubscribe && alta.ListUnsubscribe.Header),
      (alta.ListUnsubscribe && alta.ListUnsubscribe.Header) || 'ausente');
    const explicaPorQue = /No fuiste tú|no fuiste tu/i.test(html);
    marcar(4, 'explica qué hacer si no fue el titular quien se anotó', explicaPorQue, '');
    writeFileSync(`${SHOTS}/04-correo-alta.html`, html);
    nota(`plain: ${texto.slice(0, 160) || '(sin versión de texto)'}`);
  }

  // ═══ 5 · Segunda alta con el mismo correo ═════════════════════════════════
  console.log('\n── 5 · Segunda alta con el MISMO correo');
  const tDup = new Date(Date.now() - 1000).toISOString();
  await page.goto(PDP, { waitUntil: 'domcontentloaded' });
  await page.locator('#akb-bis-email').click();
  await page.locator('#akb-bis-email').type(EMAIL, { delay: 20 });
  await page.locator('.akb-bis__submit').click();
  await page.locator('#akb-bis-message').waitFor({ state: 'visible', timeout: 15000 });
  const msgDup = (await page.locator('#akb-bis-message').textContent() || '').trim();
  const claseDup = await page.locator('#akb-bis-message').getAttribute('class');
  nota(`mensaje visto: «${msgDup}»  (clase: ${claseDup})`);
  marcar(5, 'el segundo intento NO se presenta como error', /is-ok/.test(claseDup || ''), `clase «${claseDup}»`);
  marcar(5, 'el mensaje dice que ya estaba anotado', /ya está en la lista de espera/i.test(msgDup), `«${msgDup}»`);
  await page.screenshot({ path: `${SHOTS}/05-duplicado.png`, clip: await page.locator('#akb-bis-form').boundingBox() });

  const cuenta = parseInt(sql(`SELECT COUNT(*) FROM wp_akb_bis_subscribers WHERE product_id=${PRODUCT} AND email='${EMAIL}'`)[1], 10);
  marcar(5, 'no se duplicó la fila', cuenta === 1, `filas para ese par producto/email: ${cuenta}`);
  const dupMails = await mailpitBuscar(EMAIL, tDup);
  marcar(5, 'el intento repetido no reenvía otro correo de alta', dupMails.length === 0, `correos nuevos: ${dupMails.length}`);

  // ═══ 6 · Reposición y aviso de vuelta a stock ═════════════════════════════
  console.log('\n── 6 · Reposición del producto y aviso de vuelta a stock');
  const tRestock = new Date(Date.now() - 1000).toISOString();
  wp(['eval', `$p=wc_get_product(${PRODUCT}); $p->set_stock_status('instock'); $p->save(); echo $p->get_stock_status();`]);
  const pendientes = sql(`SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE hook='akb_bis_notify_product' AND status='pending'`)[1];
  marcar(6, 'la reposición encola el job de aviso', parseInt(pendientes, 10) >= 1, `acciones akb_bis_notify_product pendientes: ${pendientes}`);
  const runOut = wp(['action-scheduler', 'run', '--group=akibara-bis', '--batch-size=20']);
  nota(`cola: ${runOut.split('\n').filter((l) => /action|Executed|Completed/i.test(l)).slice(-3).join(' / ')}`);

  const restock = await esperarCorreo(EMAIL, tRestock, (m) => /de vuelta|stock/i.test(m.Subject || ''), 60);
  marcar(6, 'llega el aviso de vuelta a stock', !!restock, restock ? `asunto: «${restock.Subject}»` : 'no llegó en 60 s');
  if (restock) {
    const html = restock.HTML || '';
    nota(`asunto: «${restock.Subject}»`);
    marcar(6, 'el asunto nombra el producto correcto', /Claymore 27/.test(restock.Subject || ''), `«${restock.Subject}»`);
    marcar(6, 'el cuerpo trae el precio', /12\.000/.test(html), (html.match(/\$[\d.]+/) || ['sin precio'])[0]);
    marcar(6, 'el cuerpo trae el botón de compra', /Comprar ahora/.test(html), '');
    const enlaceProducto = /href="[^"]*claymore-27-panini-argentina[^"]*"/i.test(html);
    marcar(6, 'el botón apunta a la ficha del producto', enlaceProducto, '');
    const m2 = html.match(/href="([^"]*akb_bis_unsub=[a-f0-9]{64}[^"]*)"/i);
    unsubDesdeRestock = m2 ? m2[1].replace(/&amp;/g, '&') : '';
    marcar(6, 'trae la baja de un clic', !!unsubDesdeRestock, unsubDesdeRestock || 'sin enlace akb_bis_unsub');
    marcar(6, 'trae cabecera List-Unsubscribe',
      !!(restock.ListUnsubscribe && restock.ListUnsubscribe.Header),
      (restock.ListUnsubscribe && restock.ListUnsubscribe.Header) || 'ausente');
    const imagen = /<img[^>]+src="[^"]+"/i.test(html);
    marcar(6, 'el aviso muestra la portada del tomo', imagen, imagen ? '' : 'sin <img> de producto');
    writeFileSync(`${SHOTS}/06-correo-restock.html`, html);
  }
  const estadoTrasAviso = sql(`SELECT status,notified_at FROM wp_akb_bis_subscribers WHERE id=${idsSembrados[0]}`)[1];
  marcar(6, 'la suscripción queda marcada como avisada', /notified/.test(estadoTrasAviso || ''), estadoTrasAviso);

  // Con el producto repuesto, la ficha ya no debe ofrecer la lista de espera.
  await page.goto(PDP, { waitUntil: 'domcontentloaded' });
  const formEnStock = await page.locator('#akb-bis-form').count();
  const comprarEnStock = await page.locator('.single_add_to_cart_button').count();
  marcar(6, 'repuesto: la ficha deja de ofrecer la lista de espera', formEnStock === 0, `formularios: ${formEnStock}`);
  marcar(6, 'repuesto: la ficha ofrece comprar', comprarEnStock >= 1, `botones de compra: ${comprarEnStock}`);

  // ═══ 7 · PULSAR el enlace de baja del correo ══════════════════════════════
  console.log('\n── 7 · Baja desde el correo (clic de verdad)');
  const url = unsubDesdeRestock || unsubDesdeAlta;
  if (!url) {
    marcar(7, 'hay un enlace de baja que pulsar', false, 'ningún correo trajo enlace');
  } else {
    const r1 = await page.goto(url, { waitUntil: 'domcontentloaded' });
    marcar(7, 'el enlace de baja abre', r1.status() === 200, `HTTP ${r1.status()} · ${url}`);
    const h1 = (await page.locator('h1').first().textContent() || '').trim();
    const cuerpoGet = (await page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
    nota(`pantalla GET: «${h1}» / «${cuerpoGet}»`);
    marcar(7, 'el GET solo confirma (no da de baja al escanear el correo)',
      await page.locator('form[method="post"] button[type="submit"]').count() === 1, `h1: «${h1}»`);
    const nombraProductoGet = /Claymore 27/i.test(cuerpoGet);
    marcar(7, 'la pantalla de confirmación dice DE QUÉ producto se dará de baja', nombraProductoGet, `texto: «${cuerpoGet}»`);
    await page.screenshot({ path: `${SHOTS}/07a-baja-confirmar.png`, fullPage: true });

    await page.locator('form[method="post"] button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    const h1b = (await page.locator('h1').first().textContent() || '').trim();
    const cuerpoPost = (await page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
    nota(`pantalla POST: «${h1b}» / «${cuerpoPost}»`);
    marcar(7, 'la baja se confirma al cliente', /listo/i.test(h1b), `h1: «${h1b}»`);
    const nombraProductoPost = /Claymore 27/i.test(cuerpoPost);
    marcar(7, 'la pantalla de baja dice DE QUÉ producto se dio de baja', nombraProductoPost, `texto: «${cuerpoPost}»`);
    marcar(7, 'ofrece una salida de vuelta a la tienda',
      await page.locator('a[href]').count() >= 1, '');
    await page.screenshot({ path: `${SHOTS}/07b-baja-hecha.png`, fullPage: true });

    const estadoBaja = sql(`SELECT status FROM wp_akb_bis_subscribers WHERE id=${idsSembrados[0]}`)[1];
    marcar(7, 'la baja quedó registrada en la base', /unsubscribed/.test(estadoBaja || ''), `estado: ${estadoBaja}`);
  }

  // ═══ 8 · Volver a la ficha ════════════════════════════════════════════════
  console.log('\n── 8 · De vuelta a la ficha (producto agotado otra vez)');
  wp(['eval', `$p=wc_get_product(${PRODUCT}); $p->set_stock_status('outofstock'); $p->save(); echo $p->get_stock_status();`]);
  await page.goto(PDP, { waitUntil: 'domcontentloaded' });
  const vuelve = await page.locator('#akb-bis-form').count();
  marcar(8, 'el formulario vuelve a ofrecerse cuando el tomo se agota otra vez', vuelve === 1, `formularios: ${vuelve}`);
  const inputVacio = await page.locator('#akb-bis-email').inputValue();
  marcar(8, 'el formulario llega limpio (sin rastro de la sesión anterior)', inputVacio === '', `valor: «${inputVacio}»`);

  // Y quien se dio de baja puede volver a anotarse (re-alta).
  const tRealta = new Date(Date.now() - 1000).toISOString();
  await page.locator('#akb-bis-email').click();
  await page.locator('#akb-bis-email').type(EMAIL, { delay: 20 });
  await page.locator('.akb-bis__submit').click();
  await page.locator('#akb-bis-message').waitFor({ state: 'visible', timeout: 15000 });
  const msgRealta = (await page.locator('#akb-bis-message').textContent() || '').trim();
  const claseRealta = await page.locator('#akb-bis-message').getAttribute('class');
  nota(`mensaje visto: «${msgRealta}»  (clase: ${claseRealta})`);
  marcar(8, 'quien se dio de baja puede volver a anotarse', /is-ok/.test(claseRealta || '') && /te avisaremos/i.test(msgRealta), `«${msgRealta}»`);
  const estadoRealta = sql(`SELECT status FROM wp_akb_bis_subscribers WHERE id=${idsSembrados[0]}`)[1];
  marcar(8, 'la re-alta reactiva la MISMA fila (no crea otra)', /subscribed/.test(estadoRealta || ''), `estado: ${estadoRealta}`);
  const totalPar = parseInt(sql(`SELECT COUNT(*) FROM wp_akb_bis_subscribers WHERE product_id=${PRODUCT} AND email='${EMAIL}'`)[1], 10);
  marcar(8, 'sigue habiendo una sola fila para ese par', totalPar === 1, `filas: ${totalPar}`);
  const realtaMail = await esperarCorreo(EMAIL, tRealta, () => true, 25);
  marcar(8, 'la re-alta avisa por correo al titular', !!realtaMail, realtaMail ? `asunto: «${realtaMail.Subject}»` : 'no llegó');

  console.log(`\n── Errores de consola en todo el recorrido: ${erroresJs.length}`);
  erroresJs.slice(0, 8).forEach((e) => nota(e));
  marcar(0, 'la consola del navegador queda limpia', erroresJs.length === 0, erroresJs.slice(0, 3).join(' | '));
} finally {
  // ═══ Restauración ═══════════════════════════════════════════════════════
  console.log('\n── Restauración del espejo');
  try {
    wp(['eval', `$p=wc_get_product(${PRODUCT}); $p->set_stock_status('${estadoInicial}'); $p->save(); echo $p->get_stock_status();`]);
    const stockFinal = sql(`SELECT meta_value FROM wp_postmeta WHERE post_id=${PRODUCT} AND meta_key='_stock_status'`)[1];
    console.log(`  stock de #${PRODUCT}: ${stockFinal} (era ${estadoInicial}) → ${stockFinal === estadoInicial ? 'RESTAURADO' : 'REVISAR'}`);

    // Borrado POR ID —jamás por email y jamás por rango—: el espejo lo comparten
    // otros agentes y un «WHERE id > baseline» se lleva por delante lo que ellos
    // siembren mientras esto corre.
    const nuevas = idsSembrados;
    for (const id of nuevas) wp(['db', 'query', `DELETE FROM wp_akb_bis_subscribers WHERE id=${id}`]);
    console.log(`  filas borradas por id: [${nuevas.join(', ')}]`);

    const quedan = parseInt(sql(`SELECT COUNT(*) FROM wp_akb_bis_subscribers WHERE id IN (${nuevas.length ? nuevas.join(',') : 0})`)[1], 10);
    const restosEmail = parseInt(sql(`SELECT COUNT(*) FROM wp_akb_bis_subscribers WHERE email='${EMAIL}'`)[1], 10);
    const filasFinales = parseInt(sql('SELECT COUNT(*) FROM wp_akb_bis_subscribers')[1], 10);
    console.log(`  restos de este script: ${quedan} por id, ${restosEmail} por email → ${quedan + restosEmail === 0 ? 'RESTAURADO' : 'REVISAR'}`);
    console.log(`  tabla: ${filasFinales} filas (al empezar ${filasIniciales}; el total puede diferir por lo que siembren otros agentes)`);
    console.log(`  baseline max(id) al empezar: ${idMaxInicial}`);
  } catch (e) {
    console.log(`  ERROR restaurando: ${e.message}`);
  }
  await browser.close();
}

console.log('\n════════════════════════════════════════════════════════════════');
console.log(` Resultado: ${filas.length - fallos}/${filas.length} comprobaciones OK · ${fallos} fallos`);
console.log('════════════════════════════════════════════════════════════════\n');
if (fallos) {
  console.log('FALLOS:');
  filas.filter((f) => !f.ok).forEach((f) => console.log(`  [paso ${f.paso}] ${f.caso}${f.evidencia ? ` — ${f.evidencia}` : ''}`));
}
process.exit(0);
