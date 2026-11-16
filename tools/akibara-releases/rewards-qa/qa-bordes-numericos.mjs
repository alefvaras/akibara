/**
 * QA de límites numéricos en TODOS los paneles propios, como los prueba una
 * persona: meter un número imposible y ver qué contesta la tienda.
 *
 * Dos promesas que el dueño da por hechas:
 *   A) un valor fuera del rango declarado (`min`/`max` del propio campo) NO se
 *      guarda tal cual;
 *   B) si el sistema lo recorta, LO DICE. Recortar en silencio es peor que
 *      rechazar: el dueño cree que puso 99 y puso 15.
 *
 * Un campo por pasada, y la lectura SIEMPRE en una carga posterior de la
 * página: leer en el mismo request en el que se escribe arrastra memos y da
 * falsos positivos (lección cara del 2026-09-05).
 * Restaura cada campo a su valor original antes de pasar al siguiente.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
if (!/localhost|127\.0\.0\.1/.test(BASE)) throw new Error('Sólo contra el espejo.');
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const TABS = (process.env.AKB_TABS || 'free_shipping_progress,native_cart,series_bundles,back_in_stock,series_follow,taxonomy_discount').split(',');
/** `CSS.escape` sólo existe en el navegador; aquí basta con escapar lo que un id de WordPress puede traer. */
const sel = (id) => '#' + String(id).replace(/([^\w-])/g, '\\$1');
const TAB = (t) => `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${t}`;

const hallazgos = [];
const anotar = (tab, campo, titulo, detalle) => {
  hallazgos.push({ tab, campo, titulo, detalle });
  console.log(`  ✗ [${tab}] ${campo}: ${titulo}\n     ${detalle}`);
};

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1440, height: 1100 } })).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);

const abrir = async (tab) => {
  await page.goto(TAB(tab), { waitUntil: 'load' });
  await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
};
const guardar = async () => {
  const b = page.locator('form button[name="akibara_panel_save"]').first();
  if (!(await b.count())) return false;
  try { await b.scrollIntoViewIfNeeded(); await b.click({ timeout: 5000 }); }
  catch { await b.focus(); await page.keyboard.press('Enter'); }
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForTimeout(900);
  return true;
};
const avisos = () => page.evaluate(() => [...document.querySelectorAll('.notice, .updated, .error')]
  .map((e) => e.textContent.replace(/\s+/g, ' ').trim()).join(' | ').slice(0, 400));

for (const tab of TABS) {
  console.log(`\n── ${tab} ──`);
  await abrir(tab);
  const numericos = await page.evaluate(() => [...document.querySelectorAll('input[type=number]')]
    .filter((e) => e.id && !e.disabled)
    .map((e) => ({ id: e.id, min: e.min, max: e.max, valor: e.value }))
    .slice(0, 4));
  if (!numericos.length) { console.log('  (sin campos numéricos)'); continue; }

  for (const campo of numericos) {
    const tope = campo.max === '' ? null : Number(campo.max);
    const suelo = campo.min === '' ? null : Number(campo.min);
    const excesivo = tope !== null ? String(tope + 10) : '999999';
    await abrir(tab);
    const original = await page.locator(sel(campo.id)).inputValue().catch(() => '');
    await page.locator(sel(campo.id)).fill(excesivo);

    // ANTES de concluir nada: ¿lo para el navegador con su validación nativa?
    // Un input[type=number] con `max` impide el envío y enseña su propio globo
    // («Value must be less than or equal to N»). Eso NO es un defecto: es el
    // panel funcionando. Sin esta comprobación, este script reportó ocho
    // falsos positivos el 2026-09-05, y el dueño había pedido justo lo
    // contrario. La validación del servidor sólo se puede juzgar si el
    // formulario llegó a enviarse.
    const nativo = await page.evaluate((id) => {
      const e = document.getElementById(id);
      return e ? { valido: e.checkValidity(), mensaje: e.validationMessage } : null;
    }, campo.id);
    if (nativo && ! nativo.valido) {
      console.log(`  OK  ${campo.id}: el navegador lo frena — «${nativo.mensaje}»`);
      await abrir(tab);
      await page.locator(sel(campo.id)).fill(String(original));
      await guardar();
      continue;
    }

    if (!(await guardar())) { console.log(`  (${campo.id}: sin botón de guardar)`); continue; }

    // Lectura en una carga NUEVA: nunca en el request del guardado.
    await abrir(tab);
    const quedo = await page.locator(sel(campo.id)).inputValue().catch(() => '');
    const av = await avisos();
    const mencionaRecorte = /tope|máxim|recort|ajust|corrig|no válid|inválid|fuera de/i.test(av);

    if (quedo === excesivo && tope !== null) {
      anotar(tab, campo.id, `guarda ${excesivo}, por encima de su propio máximo (${tope})`,
        `El campo declara max=${tope} en el HTML pero el servidor lo acepta igual. Avisos: ${av.slice(0, 120) || '(ninguno)'}`);
    } else if (quedo !== excesivo && !mencionaRecorte) {
      anotar(tab, campo.id, 'recorta en silencio',
        `Se escribió ${excesivo} (max=${tope ?? 'sin max'}), quedó ${quedo || '(vacío)'}, y ningún aviso lo menciona. Avisos: ${av.slice(0, 120) || '(ninguno)'}`);
    } else {
      console.log(`  OK  ${campo.id}: ${excesivo} → ${quedo || '(vacío)'} y el panel lo dice`);
    }

    // restaurar
    await abrir(tab);
    await page.locator(sel(campo.id)).fill(String(original));
    await guardar();
  }
}
await nav.close();
console.log('\n===== HALLAZGOS =====');
console.log(hallazgos.length ? JSON.stringify(hallazgos, null, 2) : 'Sin hallazgos.');
