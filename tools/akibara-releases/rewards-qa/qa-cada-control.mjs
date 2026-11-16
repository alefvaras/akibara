/**
 * «Cada control, cada guardado» — generaliza qa-cada-checkbox.mjs a TODOS los
 * tipos de control del panel: checkbox, select, number, text, textarea, radio,
 * date, color y url.
 *
 * Para cada control: lo cambia a un valor distinto y VÁLIDO para su tipo,
 * guarda, RECARGA, comprueba que se relee cambiado; después restaura el valor
 * original, guarda, recarga y comprueba que vuelve. Dos afirmaciones por
 * control:
 *
 *   A) lo que toco se guarda          (si no, el dueño trabaja en vano)
 *   B) lo que restauro vuelve         (si no, el panel deja basura)
 *
 * Variables:
 *   AKB_BASE=http://localhost:8080     (por defecto el espejo)
 *   AKB_PRODUCCION=si                  obligatorio si BASE no es local
 *   AKB_TABS=preventa,copys            limita pestañas
 *   AKB_MAX=999                        controles por pestaña
 *   AKB_INCLUIR_PELIGROSAS=si          incluye taxonomy_discount
 *   AKB_SOLO_LEER=si                   inventario sin tocar nada
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const esProd = !/localhost|127\.0\.0\.1/.test(BASE);
if (esProd && process.env.AKB_PRODUCCION !== 'si') throw new Error('Para producción, exporta AKB_PRODUCCION=si.');
const USER = process.env.AKB_USER || (esProd ? '' : 'qa.owner');
const PASS = process.env.AKB_PASS || (esProd ? '' : 'AkbQaOwner2026!');
const SOLO = process.env.AKB_TABS ? process.env.AKB_TABS.split(',') : null;
const MAX = Number(process.env.AKB_MAX || 999);
const SOLO_LEER = process.env.AKB_SOLO_LEER === 'si';

// Mueven la foto editorial de precios: cambian el `seq` y pueden dejar sin
// pareja la acción que apaga la promo.
const PELIGROSAS = new Set(['taxonomy_discount']);
// Controles que NO se tocan aunque aparezcan: mandan correo o borran datos.
// `confirm_fill` y compañía son confirmaciones de UNA ACCIÓN, no ajustes: no
// se persisten a propósito y darlas por «no guarda» es un falso positivo.
const NO_TOCAR = /(_test_|_enviar_|_purgar|_borrar|_reset|_reindex|^confirm_)/i;

const nav = await chromium.launch();
const ctx = await nav.newContext({ viewport: { width: 1440, height: 1200 } });
const page = await ctx.newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
// El login se espera por NAVEGACIÓN, no por un selector: encadenar
// `click` y luego `waitForSelector` deja la espera colgada del documento
// viejo cuando la máquina va cargada (visto el 2026-09-06 con load 8).
await Promise.all([ page.waitForLoadState('load').catch(() => {}), page.click('#wp-submit') ]);
await page.waitForTimeout(2500);
await page.waitForSelector('#wpadminbar', { timeout: 120000 });
console.log(`· sesión iniciada en ${BASE}${SOLO_LEER ? '  (SOLO LECTURA)' : ''}\n`);

/**
 * Guarda enviando el formulario AL QUE PERTENECE el control, no un botón
 * global: el panel no es uniforme — `announcement` y `copys` traen
 * `button[name=akibara_panel_save]`, `preventa` un botón sin name («Guardar
 * cortes»), `home_hero` otro («Guardar configuración») y `series_bundles` LOS
 * DOS. Buscar un único selector dejaba pestañas sin poder guardarse.
 */
const guardar = async (name) => {
  const ok = await page.evaluate((n) => {
    const el = document.getElementsByName(n)[0];
    const form = el && el.closest('form');
    if (!form) return false;
    // OJO: `querySelector` con lista separada por comas devuelve el primer
    // elemento en ORDEN DE DOCUMENTO que case con CUALQUIERA de los selectores,
    // no el primer selector que case. Con eso pulsaba otro submit de la página y
    // el POST salía sin `akibara_panel_save`, que es lo único que hace guardar
    // (AdminPanel.php:438 sale sin tocar nada si no viene). Resultado: «no
    // guarda» falso en tabs que sí guardan. Hay que probar los selectores EN
    // ORDEN, uno a uno.
    const enOrden = ['button[name="akibara_panel_save"]', 'input[name="akibara_panel_save"]', 'button[type=submit]:not([name=open_after_save])', 'input[type=submit]'];
    for (const sel of enOrden) {
      const btn = form.querySelector(sel);
      if (btn) { btn.click(); return true; }
    }
    form.requestSubmit(); return true;
  }, name);
  if (!ok) throw new Error('sin formulario para ' + name);
  // El guardado navega y REDIRIGE. Si el siguiente `goto` sale antes de que la
  // redirección aterrice, o bien se aborta con «interrupted by another
  // navigation», o bien se relee la página VIEJA y parece que no se guardó.
  // Los tres «NO restaura» del 2026-09-06 en `announcement` eran esto: la base
  // tenía el valor correcto y la sonda leía el documento anterior.
  await page.waitForLoadState('load').catch(() => {});
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(1800);
};

/** `goto` con un reintento: la redirección del guardado puede pisar al primero. */
const ir = async (url) => {
  for (let i = 0; i < 3; i++) {
    try { await page.goto(url, { waitUntil: 'load' }); return; }
    catch (e) { if (i === 2) throw e; await page.waitForTimeout(1500); }
  }
};

/** Lo que el panel DICE tras guardar. Un rechazo con motivo no es un fallo. */
const aviso = () => page.evaluate(() => {
  const n = [...document.querySelectorAll('.notice, .notice-warning, .notice-error, .notice-success')]
    .map((e) => e.textContent.trim().replace(/\s+/g, ' '))
    .filter((t) => t && !/litespeed|cloudflare|transporte de correo/i.test(t));
  return n.join(' | ').slice(0, 300);
});

const abrirTodo = () => page.evaluate(() => {
  document.querySelectorAll('details').forEach((d) => { d.open = true; });
});

/** Inventario de controles de la pestaña abierta. */
const inventario = () => page.$$eval('form input, form select, form textarea', (els) =>
  els.map((e) => {
    const tipo = e.tagName === 'SELECT' ? 'select' : e.tagName === 'TEXTAREA' ? 'textarea' : (e.type || 'text');
    let opciones = e.tagName === 'SELECT' ? [...e.options].map((o) => o.value) : [];
    if (e.type === 'radio') {
      opciones = [...document.getElementsByName(e.name)].map((r) => r.value);
    }
    const valorRadio = e.type === 'radio' ? (([...document.getElementsByName(e.name)].find((r) => r.checked) || {}).value ?? '') : null;
    return { name: e.name || '', tipo, valor: e.type === 'checkbox' ? String(e.checked) : (e.type === 'radio' ? String(valorRadio) : String(e.value ?? '')), opciones, deshabilitado: e.disabled, readOnly: !!e.readOnly, min: e.getAttribute('min'), max: e.getAttribute('max'), step: e.getAttribute('step'), patron: e.getAttribute('pattern') };
  }).filter((c) => c.name && !/\[\]$/.test(c.name) && !['hidden', 'submit', 'button', 'file', 'search'].includes(c.tipo) && !c.deshabilitado && !c.readOnly));

/** Propone un valor distinto y válido. Devuelve null si no sabe. */
const proponer = (c) => {
  switch (c.tipo) {
    case 'checkbox': return c.valor === 'true' ? 'false' : 'true';
    case 'select': case 'radio': {
      const otra = c.opciones.find((o) => o !== c.valor);
      return otra === undefined ? null : otra;
    }
    case 'number': {
      // Respeta min/max/step del propio campo. Proponer un valor que el
      // navegador rechazaría fabrica fallos falsos: `akibara_fsp_threshold`
      // lleva step=1000, y meterle un 1 por JS salta la validación del
      // navegador y el servidor lo redondea — parecía «no guarda» y era el
      // instrumento saltándose la barrera que el usuario sí ve (2026-09-06).
      const paso = Math.abs(Number(c.step)) || 1;
      const min = c.min === null || c.min === '' ? null : Number(c.min);
      const max = c.max === null || c.max === '' ? null : Number(c.max);
      const n = Number(c.valor);
      const base = Number.isFinite(n) ? n : (min ?? 0);
      let cand = base + paso;
      if (max !== null && cand > max) cand = base - paso;
      if (min !== null && cand < min) cand = min + paso;
      if (max !== null && cand > max) return null;      // no cabe un valor válido distinto
      if (!Number.isFinite(cand) || cand === base) return null;
      return String(Number.isInteger(paso) ? Math.round(cand) : cand);
    }
    case 'date': return c.valor ? '' : '';
    case 'color': return c.valor && c.valor.toLowerCase() !== '#123456' ? '#123456' : '#654321';
    case 'email': return c.valor === 'qa@akibara.cl' ? 'qa2@akibara.cl' : 'qa@akibara.cl';
    case 'url': return c.valor === 'https://akibara.cl/qa' ? 'https://akibara.cl/qa2' : 'https://akibara.cl/qa';
    // Sin espacio delante cuando el campo está vacío: los paneles recortan
    // espacios al guardar y « QA» volvía como «QA» — un fallo inventado.
    case 'text': case 'textarea': {
      // Un campo que contiene una URL o una ruta se sanea con esc_url_raw y
      // convierte el espacio en %20: añadir « QA» fabricaba un fallo falso.
      // Un campo de COLOR pintado como texto sólo acepta un hex: meterle «QA»
      // lo deja vacío y parece que no guarda.
      const esColor = /color|colour|_bg$|fondo/i.test(c.name) || /^#[0-9a-f]{3,8}$/i.test(c.valor);
      if (esColor) return c.valor.toLowerCase() === '#123456' ? '#654321' : '#123456';
      const esUrl = /^(https?:\/\/|\/|#)/.test(c.valor) || /url|enlace|link|href/i.test(c.name);
      if (!c.valor) return esUrl ? '/qa' : 'QA';
      return esUrl ? c.valor + '-qa' : c.valor + ' QA';
    }
    default: return null;
  }
};

/** Oculta secretos en los informes: una clave de API no tiene por qué acabar
 *  en un log de QA ni en el tablón de coordinación. */
const SECRETO = /(api_key|apikey|token|secret|password|clave)/i;
const seguro = (name, valor) => SECRETO.test(name)
  ? (String(valor).length ? `«${String(valor).slice(0, 4)}…${String(valor).length} caracteres»` : '«vacío»')
  : `«${valor}»`;

const leer = async (name, tipo) => page.evaluate(([n, t]) => {
  // OJO: una casilla puede venir con un `hidden` de compañía con el mismo
  // `name` (patrón `<input type=hidden value=0><input type=checkbox value=1>`).
  // `getElementsByName(n)[0]` devuelve el hidden, cuyo `.checked` es SIEMPRE
  // false: leía «apagado» con la casilla marcada. Pasa en slides[N][is_active].
  const todos = [...document.getElementsByName(n)];
  const e = t === 'checkbox' ? (todos.find((x) => x.type === 'checkbox') || todos[0]) : todos[0];
  if (!e) return '(ausente)';
  if (t === 'radio') { const m = [...document.getElementsByName(n)].find((r) => r.checked); return m ? String(m.value) : ''; }
  return t === 'checkbox' ? String(e.checked) : String(e.value ?? '');
}, [name, tipo]);

const poner = async (name, tipo, valor) => page.evaluate(([n, t, v]) => {
  const todos = [...document.getElementsByName(n)];
  const e = t === 'checkbox' ? (todos.find((x) => x.type === 'checkbox') || todos[0]) : todos[0];
  if (!e) return false;
  if (t === 'radio') { const m = [...document.getElementsByName(n)].find((r) => r.value === v); if (!m) return false; m.checked = true; m.dispatchEvent(new Event('input', { bubbles: true })); m.dispatchEvent(new Event('change', { bubbles: true })); return true; }
  if (t === 'checkbox') { e.checked = (v === 'true'); }
  else if (t === 'select') { e.value = v; }
  else {
    const proto = e.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(e, v);
  }
  e.dispatchEvent(new Event('input', { bubbles: true }));
  e.dispatchEvent(new Event('change', { bubbles: true }));
  return true;
}, [name, tipo, valor]);

await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel`, { waitUntil: 'load' });
let tabs = await page.$$eval('a[href*="page=akibara-panel"][href*="tab="]', (as) =>
  [...new Set(as.map((a) => new URL(a.href).searchParams.get('tab')).filter(Boolean))]);
if (SOLO) tabs = tabs.filter((t) => SOLO.includes(t));
console.log(`· ${tabs.length} pestañas: ${tabs.join(', ')}\n`);

const fallos = []; let probados = 0; let saltados = 0;

for (const tab of tabs) {
  if (PELIGROSAS.has(tab) && !process.env.AKB_INCLUIR_PELIGROSAS) { console.log(`── ${tab}: SALTADA (mueve la foto de precios)`); continue; }
  const url = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${tab}`;
  await ir(url);
  await abrirTodo();
  const todos = await inventario();
  // RED DE SEGURIDAD: foto de la pestaña entera antes de tocar nada. Un barrido
  // control a control sobre filas ACOPLADAS (p. ej. `akb_cortes[ID][modo]` y sus
  // números) destruye configuración: poner `modo=heredado` borra las metas de la
  // fila, y volver a `tanda` ya no valida porque los números se fueron con ella.
  // Aprendido rompiendo los cortes de dos series en el espejo el 2026-09-06.
  const fotoTab = Object.fromEntries(todos.map((c) => [c.name, { tipo: c.tipo, valor: c.valor }]));
  const unicos = [...new Map(todos.map((c) => [c.name, c])).values()];
  // Filas acopladas `campo[ID][sub]`: no se barren de una en una.
  const ACOPLADO = /^[a-z_]+\[\d+\]\[[a-z_]+\]$/i;
  const acoplados = unicos.filter((c) => ACOPLADO.test(c.name)).length;
  if (acoplados && !process.env.AKB_INCLUIR_ACOPLADOS) console.log(`   · ${acoplados} controles de filas acopladas: NO se barren de uno en uno (AKB_INCLUIR_ACOPLADOS=si para forzar)`);
  const lista = unicos
    .filter((c) => !NO_TOCAR.test(c.name))
    .filter((c) => process.env.AKB_INCLUIR_ACOPLADOS ? true : !ACOPLADO.test(c.name))
    .slice(0, MAX);
  const porTipo = lista.reduce((a, c) => { a[c.tipo] = (a[c.tipo] || 0) + 1; return a; }, {});
  console.log(`── ${tab}  (${lista.length} de ${unicos.length} controles: ${Object.entries(porTipo).map(([k, v]) => k + '×' + v).join(' ')})`);
  if (SOLO_LEER) { continue; }

  for (const c of lista) {
    const nuevo = proponer(c);
    if (nuevo === null || nuevo === c.valor) { saltados++; continue; }
    try {
      await ir(url); await abrirTodo();
      const original = await leer(c.name, c.tipo);
      if (original === '(ausente)') { saltados++; continue; }

      await poner(c.name, c.tipo, nuevo);
      await guardar(c.name);
      await ir(url); await abrirTodo();
      const tras = await leer(c.name, c.tipo);

      await poner(c.name, c.tipo, original);
      await guardar(c.name);
      await ir(url); await abrirTodo();
      const vuelto = await leer(c.name, c.tipo);

      probados++;
      const guardo = tras === nuevo;
      const restauro = vuelto === original;
      if (guardo && restauro) console.log(`   OK ${c.name} [${c.tipo}]`);
      else {
        const dijo = await aviso();
        const q = (!guardo ? `NO guarda: puse ${seguro(c.name, nuevo)} y releí ${seguro(c.name, tras)}` : `NO restaura: era ${seguro(c.name, original)} y quedó ${seguro(c.name, vuelto)}`)
          + (dijo ? `  ← el panel dijo: ${dijo}` : '  ← el panel NO dijo nada');
        console.log(`   ✗✗ ${c.name} [${c.tipo}]: ${q}`);
        fallos.push({ tab, name: c.name, tipo: c.tipo, q });
      }
    } catch (e) {
      console.log(`   ✗  ${c.name} [${c.tipo}]: ${String(e).slice(0, 90)}`);
      fallos.push({ tab, name: c.name, tipo: c.tipo, q: 'error: ' + String(e).slice(0, 90) });
    }
  }

  // Reposición final de la pestaña completa, pase lo que pase.
  try {
    await ir(url); await abrirTodo();
    let repuestos = 0;
    for (const [name, v] of Object.entries(fotoTab)) {
      const ahora = await leer(name, v.tipo);
      if (ahora !== '(ausente)' && ahora !== v.valor) { await poner(name, v.tipo, v.valor); repuestos++; }
    }
    if (repuestos) { await guardar(Object.keys(fotoTab)[0]); console.log(`   · repuestos ${repuestos} controles a su valor inicial`); }
  } catch (e) { console.log(`   ! no pude reponer ${tab}: ${String(e).slice(0, 80)}`); }
}

console.log(`\n═══ RESUMEN ═══  probados: ${probados}   saltados: ${saltados}   fallos: ${fallos.length}`);
for (const f of fallos) console.log(` [${f.tab}] ${f.name} (${f.tipo}): ${f.q}`);
await nav.close();
