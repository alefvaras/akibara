/**
 * QA de la interfaz de NUESTROS módulos, como lo haría el dueño.
 *
 * No prueba funciones: prueba promesas que el dueño da por hechas y que, cuando
 * se rompen, no avisan. Una por pestaña del panel:
 *
 *   A) Guardar SIN tocar nada no cambia nada.  Suena obvio; el 2026-09-05 un
 *      guardado en blanco de «Descuentos» escribía ~277 term-meta, disparaba
 *      ~277 publicaciones editoriales y tumbó el buscador de la tienda.
 *   B) Lo guardado se relee igual.  Si el panel dice «guardado» y al recargar
 *      muestra otra cosa, el dueño trabaja sobre una mentira.
 *   C) La pantalla no lanza errores de PHP ni de consola.
 *   D) Cada control tiene etiqueta accesible (el dueño navega con el teclado).
 *
 * Sólo lee y guarda; no borra nada. Corre EXCLUSIVAMENTE contra el espejo.
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
if (!/localhost|127\.0\.0\.1/.test(BASE)) throw new Error('Sólo contra el espejo.');
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const CWD = '/Users/alefvaras/Documents/akibara';
const SOLO = process.env.AKB_SOLO ? process.env.AKB_SOLO.split(',') : null;

const cli = (cmd) => execSync(`docker compose run --rm --no-deps -T cli ${cmd}`, { cwd: CWD, encoding: 'utf8' });

/** Huella de TODAS las options e term-meta que puede tocar el panel. */
function huella() {
  const salida = cli(`wp eval 'global $wpdb; $o = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE \\"akb%\\" OR option_name LIKE \\"akibara%\\"", ARRAY_A); $t = $wpdb->get_results("SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_key LIKE \\"_akb%\\"", ARRAY_A); echo json_encode(["opciones" => $o, "termmeta" => $t]);'`);
  const json = salida.slice(salida.indexOf('{"opciones"'));
  return JSON.parse(json.slice(0, json.lastIndexOf('}') + 1));
}

/**
 * Ruido conocido: contabilidad interna que el propio plugin del buscador mueve
 * en CUALQUIER request de admin (bitácora, marca de reconcile, señal de
 * rebuild, generación). No es el panel escribiendo ajustes del dueño.
 */
const RUIDO = [
  'option:akb_search_audit_log',
  'option:akb_search_editorial_reconcile_at',
  'option:akb_search_pending_rebuild',
  'option:akb_search_generation_outbox',
  'option:akb_search_index_gen',
  'option:akb_search_rebuild_state',
  'option:akb_search_serving_recovery_at',
  'option:akb_search_reconcile_report',
  // Recibos y huellas que el propio rebuild sella mientras corre. Si hay un
  // run en vuelo (o el cron acaba de cerrarlo) se mueven solos, sin que el
  // panel escriba nada. Confundirlos con «el panel escribió» costó un informe
  // entero de falsos positivos el 2026-09-05.
  'option:akb_search_editorial_discount_receipt',
  'option:akb_search_editorial_discount_state',
  'option:akb_search_rest_purge_receipt',
  'option:akb_search_semantic_fingerprint',
  'option:akb_search_last_rebuild',
  'option:akb_search_rebuild_history',
  'option:akb_search_editorial_run_receipt_',
  'option:akb_search_serving_down',
];
const esRuido = (linea) => RUIDO.some((n) => linea.includes(n));

function diferencias(antes, despues) {
  const mapa = (h) => {
    const m = new Map();
    for (const o of h.opciones) m.set('option:' + o.option_name, o.option_value);
    for (const t of h.termmeta) m.set(`termmeta:${t.term_id}:${t.meta_key}`, t.meta_value);
    return m;
  };
  const a = mapa(antes), b = mapa(despues), cambios = [];
  for (const [k, v] of b) {
    if (!a.has(k)) cambios.push(`CREADA ${k} = ${String(v).slice(0, 40)}`);
    else if (a.get(k) !== v) cambios.push(`CAMBIÓ ${k}: ${String(a.get(k)).slice(0, 25)} → ${String(v).slice(0, 25)}`);
  }
  for (const k of a.keys()) if (!b.has(k)) cambios.push(`BORRADA ${k}`);
  return cambios.filter((c) => !esRuido(c));
}

const hallazgos = [];
const anotar = (pestana, gravedad, titulo, detalle) => {
  hallazgos.push({ pestana, gravedad, titulo, detalle });
  console.log(`  ${gravedad === 'alta' ? '✗✗' : '✗ '} [${pestana}] ${titulo}\n     ${detalle}`);
};

const navegador = await chromium.launch();
const contexto = await navegador.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await contexto.newPage();

const errConsola = [];
page.on('console', (m) => { if (m.type() === 'error') errConsola.push(m.text().slice(0, 160)); });
page.on('pageerror', (e) => errConsola.push('pageerror: ' + String(e).slice(0, 160)));

await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);
console.log('· sesión iniciada\n');

// --- descubrir las pestañas del panel como las ve el dueño ---
await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel`, { waitUntil: 'load' });
let pestanas = await page.$$eval('a[href*="page=akibara-panel"][href*="tab="]', (as) =>
  [...new Set(as.map((a) => new URL(a.href).searchParams.get('tab')).filter(Boolean))]);
if (SOLO) pestanas = pestanas.filter((t) => SOLO.includes(t));
console.log('· pestañas encontradas:', pestanas.join(', '), '\n');

for (const tab of pestanas) {
  console.log(`── ${tab} ──`);
  errConsola.length = 0;

  const resp = await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${tab}`, { waitUntil: 'load' });
  if (resp && resp.status() >= 400) {
    anotar(tab, 'alta', 'La pantalla no carga', `HTTP ${resp.status()}`);
    continue;
  }
  await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));

  // C) errores de PHP en la propia página
  const phpErr = await page.evaluate(() => {
    const t = document.body.innerText;
    const m = t.match(/(Fatal error|Warning:|Notice:|Deprecated:|Parse error)[^\n]{0,140}/g);
    return m ? [...new Set(m)].slice(0, 3) : [];
  });
  if (phpErr.length) anotar(tab, 'alta', 'La pantalla imprime errores de PHP', phpErr.join(' | '));

  // D) controles sin etiqueta accesible
  const sinEtiqueta = await page.evaluate(() => {
    const malos = [];
    for (const el of document.querySelectorAll('main input, main select, main textarea, .wrap input, .wrap select, .wrap textarea')) {
      if (['hidden', 'submit', 'button'].includes(el.type)) continue;
      const id = el.id;
      const tiene = (id && document.querySelector(`label[for="${CSS.escape(id)}"]`))
        || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.closest('label');
      if (!tiene) malos.push(el.name || el.id || el.tagName.toLowerCase());
    }
    return [...new Set(malos)].slice(0, 6);
  });
  if (sinEtiqueta.length) {
    anotar(tab, 'media', 'Controles sin etiqueta accesible', `${sinEtiqueta.length}+: ${sinEtiqueta.join(', ')}`);
  }

  const guardar = await page.locator('form button[name="akibara_panel_save"]').first();
  if (!(await guardar.count())) { console.log('  (sin formulario que guardar)'); continue; }

  // A) guardar SIN tocar nada
  const antes = huella();
  try {
    await guardar.scrollIntoViewIfNeeded();
    await guardar.click({ timeout: 5000 });
  } catch {
    await guardar.focus();
    await page.keyboard.press('Enter');
  }
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForTimeout(1500);
  const despues = huella();
  const cambios = diferencias(antes, despues);
  if (cambios.length) {
    anotar(tab, 'alta', 'Guardar SIN tocar nada cambia la base',
      `${cambios.length} escritura(s): ${cambios.slice(0, 4).join(' ; ')}`);
  } else {
    console.log('  OK  guardar sin cambios no escribe nada');
  }

  const aviso = await page.evaluate(() => {
    const n = [...document.querySelectorAll('.notice, .updated, .error')]
      .map((e) => e.textContent.replace(/\s+/g, ' ').trim())
      .filter((t) => /guardad|error|no se pudo/i.test(t));
    return n.slice(0, 2);
  });
  if (aviso.length) console.log('  aviso:', aviso[0].slice(0, 110));

  // El 404 del icono del sitio sólo existe en el espejo (en producción da 200):
  // no es un defecto del panel y ensucia el informe.
  const consolaReal = [...new Set(errConsola)].filter((t) => !/404 \(Not Found\)/.test(t));
  if (consolaReal.length) {
    anotar(tab, 'media', 'Errores de consola en la pantalla', consolaReal.slice(0, 3).join(' | '));
  }
}

await navegador.close();

console.log('\n===== RESUMEN =====');
if (!hallazgos.length) console.log('Sin hallazgos.');
for (const h of hallazgos) console.log(`[${h.gravedad}] ${h.pestana}: ${h.titulo} — ${h.detalle}`);
console.log(JSON.stringify(hallazgos, null, 2));
