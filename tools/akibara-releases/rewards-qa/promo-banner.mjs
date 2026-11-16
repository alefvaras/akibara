// Prueba del carril promocional de la barra de anuncios, actuando como el DUEÑO
// en el espejo. Comprueba lo que un test unitario no puede: que los campos se
// pinten y GUARDEN por la API de ajustes de WooCommerce, que la línea de estado
// diga la verdad, y que la barra del storefront cambie de verdad.
//
// Uso:
//   node tools/akibara-releases/rewards-qa/promo-banner.mjs           las 9 y restaura
//   node tools/akibara-releases/rewards-qa/promo-banner.mjs dejar     las 8 y DEJA la promo
//                                                                     puesta, para mirarla
//   node tools/akibara-releases/rewards-qa/promo-banner.mjs limpiar   sólo restaura
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
// Espejo SIEMPRE: esta prueba escribe mensajes visibles en toda la tienda.
if (!/localhost|127\.0\.0\.1/.test(BASE)) { throw new Error('esta prueba solo corre contra el espejo'); }
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const TERM = process.env.AKB_TERM || '230'; // Ivrea Argentina
const MODO = process.argv[2] || 'todo';   // todo | dejar | limpiar

const TAB = (t) => `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${t}`;
const log = (...a) => console.log(...a);

const hoy = () => new Date().toISOString().slice(0, 10);
const dia = (delta) => {
  const d = new Date();
  d.setDate(d.getDate() + delta);
  return d.toISOString().slice(0, 10);
};

let fallos = 0;
function comprobar(nombre, condicion, detalle = '') {
  if (condicion) { log(`  OK   ${nombre}`); return; }
  fallos += 1;
  log(`  FALLA ${nombre}${detalle ? ' — ' + detalle : ''}`);
}

async function entrar(page) {
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass', PASS);
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);
  if (!(await page.locator('#wpadminbar').count())) throw new Error('no se pudo entrar al admin');
  log('· sesión iniciada como', USER);
}

async function guardar(page) {
  const btn = page.locator('form button[name="akibara_panel_save"]').first();
  try {
    await btn.scrollIntoViewIfNeeded();
    await btn.click({ timeout: 4000 });
  } catch {
    // Un `.click()` por JavaScript NO sirve: el panel necesita eventos reales.
    // Enfocar el botón y pulsar Enter sí envía el formulario — es el mismo
    // camino que funciona a mano en producción.
    await btn.focus();
    await page.keyboard.press('Enter');
  }
  await page.waitForLoadState('domcontentloaded').catch(() => {});
}

/**
 * Abre TODOS los acordeones del panel.
 *
 * La configuración general de Descuentos y cada taxonomía viven dentro de un
 * <details> que nace cerrado (`TaxonomyDiscount::render_status_card` enlaza a
 * `#akb-accordion-general` justo para eso). Con el <details> cerrado, sus
 * inputs miden 0×0: no se pueden rellenar, ni enfocar, ni pulsar, y Playwright
 * los reporta como «no visible» sin decir por qué. Se abre por propiedad y no
 * con un clic porque es un control de divulgación, no un valor del formulario:
 * no interviene en el seguimiento de cambios del panel.
 */
async function abrirAcordeones(page) {
  await page.evaluate(() => {
    document.querySelectorAll('details').forEach((d) => { d.open = true; });
  });
}

/** Las líneas visibles de la barra, leídas del storefront como un visitante. */
async function lineasDeLaBarra(contexto) {
  const p = await contexto.newPage();
  // Parámetro único por lectura: LiteSpeed cachea por URL completa, así que sin
  // esto se puede leer la barra ANTERIOR y creer que el cambio no se aplicó.
  await p.goto(`${BASE}/?akbqa=${Date.now()}${Math.random().toString(36).slice(2)}`, { waitUntil: 'load' });
  const lineas = await p.$$eval('#akb-ann-bar .akb-ann-msg-inner', (els) =>
    els.map((e) => e.textContent.trim()));
  await p.close();
  return lineas;
}

/** Escribe el carril promocional y guarda. */
async function configurarPromo(page, { texto, desde = '', hasta = '', seguir = false }) {
  await page.goto(TAB('announcement'), { waitUntil: 'load' });
  await abrirAcordeones(page);
  await page.fill('#akibara_announcement_promo_text', texto);
  const casilla = page.locator('#akibara_announcement_promo_follow_discount');
  if ((await casilla.isChecked()) !== seguir) {
    await casilla.focus();
    await page.keyboard.press('Space');
    await page.waitForTimeout(150);
    if ((await casilla.isChecked()) !== seguir) {
      throw new Error(`No se pudo dejar «seguir la vigencia» en ${seguir}`);
    }
  }
  await page.fill('#akibara_announcement_promo_from', desde);
  await page.fill('#akibara_announcement_promo_to', hasta);
  await guardar(page);
}

async function estadoEnPanel(page) {
  await page.goto(TAB('announcement'), { waitUntil: 'load' });
  await abrirAcordeones(page);
  const fila = page.locator('tr').filter({ has: page.locator('#akibara_announcement_promo_text') });
  return (await fila.innerText()).replace(/\s+/g, ' ');
}

/**
 * Pone el interruptor maestro en el estado pedido.
 *
 * No usa `setChecked` sobre el input: tras un guardado el input real puede
 * quedar fuera de vista y Playwright lo rechaza por no visible. El control que
 * un humano pulsa es el <label for=…>, que arrastra el estado del input con él.
 */
async function ponerInterruptor(page, activo) {
  const box = page.locator('#akibara_taxonomy_discount_enabled');
  // `visible`, no `attached`: con la hoja del admin todavía sin aplicar el
  // input mide 0×0 y no se puede ni enfocar ni pulsar. Era una CARRERA — por
  // eso fallaba unas veces sí y otras no.
  await box.waitFor({ state: 'visible', timeout: 15000 });
  if ((await box.isChecked()) === activo) return;

  // TECLADO, no ratón. La barra flotante «Tienes cambios sin guardar» es
  // position:fixed y se come los clics sobre los controles del formulario:
  // Playwright los rechaza por «no visible» o los tira al overlay. Enfocar y
  // pulsar Espacio es lo que hace un humano que navega con Tab, genera eventos
  // reales (que es lo que el seguimiento de cambios del panel necesita) y
  // ningún overlay lo intercepta.
  await box.focus();
  await page.keyboard.press('Space');
  await page.waitForTimeout(150);
  if ((await box.isChecked()) !== activo) {
    const diag = await page.evaluate(() => {
      const els = [...document.querySelectorAll('#akibara_taxonomy_discount_enabled')];
      return {
        url: location.href.replace(location.origin, ''),
        cuantos: els.length,
        estado: els.map((e) => {
          const cs = getComputedStyle(e);
          const r = e.getBoundingClientRect();
          return {
            checked: e.checked, disabled: e.disabled, readOnly: e.readOnly,
            display: cs.display, visibility: cs.visibility, pointerEvents: cs.pointerEvents,
            w: r.width, h: r.height, enPantalla: !!e.offsetParent,
            activo: document.activeElement === e,
          };
        }),
        activeElement: document.activeElement
          ? document.activeElement.tagName + '#' + (document.activeElement.id || '')
          : null,
      };
    });
    throw new Error(`No se pudo dejar el interruptor maestro en ${activo}. `
      + JSON.stringify(diag));
  }
}

async function configurarDescuento(page, { activo, desde, hasta, pct }) {
  // SIEMPRE se escribe con el interruptor ENCENDIDO. El panel oculta la
  // vigencia y los porcentajes mientras está apagado, y un input oculto ni se
  // puede rellenar ni viaja en el POST: por eso apagar y limpiar en el mismo
  // guardado deja el porcentaje viejo en la base. Apagar es una segunda pasada.
  await page.goto(TAB('taxonomy_discount'), { waitUntil: 'load' });
  await abrirAcordeones(page);
  await ponerInterruptor(page, true);

  const desdeInput = page.locator('#akibara_taxonomy_discount_from');
  await desdeInput.waitFor({ state: 'visible', timeout: 8000 });
  await desdeInput.fill(desde);
  await page.locator('#akibara_taxonomy_discount_to').fill(hasta);

  const campo = page.locator(`#akibara_taxonomy_discount_pct_${TERM}`);
  if (!(await campo.count())) {
    throw new Error(`No existe el campo del término ${TERM} en el espejo. `
      + 'Pasa AKB_TERM=<id> con el término que tenga esta copia de la base.');
  }
  await campo.fill(String(pct));
  await guardar(page);

  if (!activo) {
    await page.goto(TAB('taxonomy_discount'), { waitUntil: 'load' });
    await abrirAcordeones(page);
    await ponerInterruptor(page, false);
    await guardar(page);
  }
}

const navegador = await chromium.launch();
const contexto = await navegador.newContext();
const page = await contexto.newPage();

try {
  await entrar(page);

  if (MODO === 'limpiar') {
    await configurarPromo(page, { texto: '', desde: '', hasta: '', seguir: false });
    await configurarDescuento(page, { activo: false, desde: '', hasta: '', pct: 0 });
    log('Espejo restaurado:', JSON.stringify(await lineasDeLaBarra(contexto)));
    await navegador.close();
    process.exit(0);
  }

  log('\n1) Los campos nuevos existen en el panel');
  await page.goto(TAB('announcement'), { waitUntil: 'load' });
  for (const id of ['promo_text', 'promo_from', 'promo_to', 'promo_follow_discount']) {
    comprobar(`campo ${id}`, (await page.locator(`#akibara_announcement_promo_${id.replace('promo_', '')}`).count()) > 0);
  }

  const fijas = await lineasDeLaBarra(contexto);
  log('   barra sin promo:', JSON.stringify(fijas));

  log('\n2) Promo con ventana que cubre hoy → se ve, y va PRIMERA');
  await configurarPromo(page, { texto: 'PRUEBA vigente hoy', desde: dia(-1), hasta: dia(1) });
  let lineas = await lineasDeLaBarra(contexto);
  comprobar('aparece', lineas.includes('PRUEBA vigente hoy'), JSON.stringify(lineas));
  comprobar('va primera', lineas[0] === 'PRUEBA vigente hoy', JSON.stringify(lineas));
  comprobar('conserva las fijas', fijas.every((f) => lineas.includes(f)), JSON.stringify(lineas));
  comprobar('el panel dice que se ve', (await estadoEnPanel(page)).includes('se está viendo en la tienda'));

  log('\n3) Último día de la ventana → todavía se ve (inclusiva)');
  await configurarPromo(page, { texto: 'PRUEBA ultimo dia', desde: dia(-3), hasta: hoy() });
  lineas = await lineasDeLaBarra(contexto);
  comprobar('el último día cuenta', lineas.includes('PRUEBA ultimo dia'), JSON.stringify(lineas));

  log('\n4) Ventana terminada ayer → desaparece sola, sin tocar nada más');
  await configurarPromo(page, { texto: 'PRUEBA vencida', desde: dia(-5), hasta: dia(-1) });
  lineas = await lineasDeLaBarra(contexto);
  comprobar('no aparece', !lineas.includes('PRUEBA vencida'), JSON.stringify(lineas));
  comprobar('las fijas siguen', fijas.every((f) => lineas.includes(f)), JSON.stringify(lineas));
  comprobar('el panel explica por qué', (await estadoEnPanel(page)).includes('fuera de las fechas'));

  log('\n5) Atada al descuento, con la campaña APAGADA → oculta');
  await configurarDescuento(page, { activo: false, desde: '', hasta: '', pct: 10 });
  await configurarPromo(page, {
    texto: 'Solo este finde: [akb_descuento_pct] en [akb_descuento_editorial]',
    desde: dia(-30), hasta: dia(30), seguir: true,
  });
  lineas = await lineasDeLaBarra(contexto);
  comprobar('oculta pese a las fechas propias', !lineas.some((l) => l.startsWith('Solo este finde')), JSON.stringify(lineas));
  comprobar('el panel explica por qué', (await estadoEnPanel(page)).includes('no hay ningún descuento'));

  log('\n6) Atada al descuento, con la campaña ENCENDIDA → se ve con los números vivos');
  await configurarDescuento(page, { activo: true, desde: dia(-1), hasta: dia(1), pct: 10 });
  lineas = await lineasDeLaBarra(contexto);
  const promo = lineas.find((l) => l.startsWith('Solo este finde')) || '';
  comprobar('aparece', promo !== '', JSON.stringify(lineas));
  comprobar('resuelve el porcentaje vivo', /10%/.test(promo), promo);
  comprobar('resuelve la editorial viva', /Ivrea/i.test(promo), promo);

  log('\n7) Bajo el porcentaje a 6 → el banner se corrige SOLO');
  await configurarDescuento(page, { activo: true, desde: dia(-1), hasta: dia(1), pct: 6 });
  lineas = await lineasDeLaBarra(contexto);
  const promo6 = lineas.find((l) => l.startsWith('Solo este finde')) || '';
  comprobar('el banner dice 6%', /6%/.test(promo6), promo6);
  comprobar('ya no dice 10%', !/10%/.test(promo6), promo6);

  log('\n8) La campaña vence → el mensaje se retira solo, sin entrar al panel');
  await configurarDescuento(page, { activo: true, desde: dia(-5), hasta: dia(-1), pct: 10 });
  lineas = await lineasDeLaBarra(contexto);
  comprobar('se retiró solo', !lineas.some((l) => l.startsWith('Solo este finde')), JSON.stringify(lineas));
  comprobar('las fijas siguen intactas', fijas.every((f) => lineas.includes(f)), JSON.stringify(lineas));

  if (MODO === 'dejar') {
    log('\n9) MODO «dejar»: se repone la campaña al 10% y se DEJA puesta para mirarla.');
    await configurarDescuento(page, { activo: true, desde: dia(-1), hasta: dia(1), pct: 10 });
    log('   barra ahora:', JSON.stringify(await lineasDeLaBarra(contexto)));
    log('   para restaurar:  node promo-banner.mjs limpiar');
    await navegador.close();
    log(fallos === 0 ? '\nTODO OK' : `\n${fallos} COMPROBACIONES FALLARON`);
    process.exit(fallos === 0 ? 0 : 1);
  }

  log('\n9) Limpieza: se vacía el carril y se apaga la campaña de prueba');
  await configurarPromo(page, { texto: '', desde: '', hasta: '', seguir: false });
  await configurarDescuento(page, { activo: false, desde: '', hasta: '', pct: 0 });
  lineas = await lineasDeLaBarra(contexto);
  comprobar('la barra vuelve a su estado inicial', JSON.stringify(lineas) === JSON.stringify(fijas), JSON.stringify(lineas));
} finally {
  await navegador.close();
}

log(fallos === 0 ? '\nTODO OK' : `\n${fallos} COMPROBACIONES FALLARON`);
process.exit(fallos === 0 ? 0 : 1);
