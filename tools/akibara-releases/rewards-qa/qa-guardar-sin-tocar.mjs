/**
 * «Guardar sin tocar nada no debe cambiar nada» — borde del panel que pidió dev.
 *
 * Para cada pestaña: foto de `wp_options` (nombre + valor) ANTES, un guardado
 * sin modificar ningún control, foto DESPUÉS, y diferencia. Lo esperable es
 * CERO cambios: ni options nuevas, ni valores distintos, ni options borradas.
 *
 * AKB_TABS=copys,announcement   limita las pestañas
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const TABS = (process.env.AKB_TABS || 'address,announcement,copys,home_series,free_shipping_progress,native_cart,back_in_stock,series_follow,salud_seo,brand_editions,home_hero,series_bundles,preventa').split(',');

const foto = () => {
  const salida = execSync(
    `docker compose run --rm -T cli db query "SELECT option_name, MD5(option_value) h FROM wp_options WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site_transient%' AND option_name NOT LIKE 'litespeed%' ORDER BY option_name" --path=/var/www/html 2>/dev/null`,
    { cwd: CWD, encoding: 'utf8', maxBuffer: 40 * 1024 * 1024 }
  );
  const m = new Map();
  for (const linea of salida.split('\n').slice(1)) {
    const [n, h] = linea.split('\t');
    if (n) m.set(n, h);
  }
  return m;
};

const diff = (a, b) => {
  const nuevas = [], idas = [], cambiadas = [];
  for (const k of b.keys()) if (!a.has(k)) nuevas.push(k);
  for (const k of a.keys()) if (!b.has(k)) idas.push(k);
  for (const [k, v] of a) if (b.has(k) && b.get(k) !== v) cambiadas.push(k);
  return { nuevas, idas, cambiadas };
};

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1440, height: 1400 } })).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', process.env.AKB_USER || 'qa.owner');
await page.fill('#user_pass', process.env.AKB_PASS || 'AkbQaOwner2026!');
await Promise.all([page.waitForLoadState('load').catch(() => {}), page.click('#wp-submit')]);
await page.waitForTimeout(2500);
await page.waitForSelector('#wpadminbar', { timeout: 180000 });

// Options que cambian solas y no son culpa del guardado.
// `_checkout_payments_methods` es caché del plugin de Mercado Pago y se
// refresca sola; verificado leyéndola sin guardar nada.
const RUIDO = /^(cron|_?akb_.*_(cache|lock|heartbeat)|akb_search_(qlog|rebuild_state)|.*_last_run|action_scheduler.*|wc_.*_lookup.*|woocommerce_(scheduled|queue).*|akb_cortes_backup|.*_notice_.*|_?akb_.*_at|_checkout_payments_methods|_?mp_.*|.*_mercadopago.*)$/i;

const problemas = [];
for (const tab of TABS) {
  const url = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${tab}`;
  // El guardado anterior redirige; sin reintento el `goto` siguiente se aborta
  // con «interrupted by another navigation» y parecía que la pestaña no cargaba.
  let cargada = false;
  for (let i = 0; i < 3 && !cargada; i++) {
    try { await page.goto(url, { waitUntil: 'load' }); cargada = true; }
    catch { await page.waitForTimeout(2000); }
  }
  if (!cargada) { console.log(`── ${tab}: no carga`); continue; }
  await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
  const hayForm = await page.evaluate(() => !!document.querySelector('form button[type=submit], form input[type=submit]'));
  if (!hayForm) { console.log(`── ${tab}: sin formulario que guardar`); continue; }

  const antes = foto();
  await page.evaluate(() => {
    const f = document.querySelector('form button[type=submit], form input[type=submit]').closest('form');
    const b = f.querySelector('button[name="akibara_panel_save"]') || f.querySelector('button[type=submit]') || f.querySelector('input[type=submit]');
    b.click();
  });
  await page.waitForLoadState('load').catch(() => {});
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(2000);
  const despues = foto();

  const d = diff(antes, despues);
  const limpio = (xs) => xs.filter((k) => !RUIDO.test(k));
  const n = limpio(d.nuevas), i = limpio(d.idas), c = limpio(d.cambiadas);
  const total = n.length + i.length + c.length;
  console.log(`── ${tab}: ${total === 0 ? 'no cambió nada ✔' : `${total} options tocadas`}`);
  if (total) {
    if (n.length) console.log(`     nuevas:    ${n.slice(0, 8).join(', ')}${n.length > 8 ? ` … (+${n.length - 8})` : ''}`);
    if (i.length) console.log(`     borradas:  ${i.slice(0, 8).join(', ')}${i.length > 8 ? ` … (+${i.length - 8})` : ''}`);
    if (c.length) console.log(`     cambiadas: ${c.slice(0, 8).join(', ')}${c.length > 8 ? ` … (+${c.length - 8})` : ''}`);
    problemas.push({ tab, n: n.length, i: i.length, c: c.length });
  }
}

console.log(`\n═══ RESUMEN ═══  pestañas con cambios: ${problemas.length}`);
for (const p of problemas) console.log(`  ${p.tab}: +${p.n} nuevas, -${p.i} borradas, ~${p.c} cambiadas`);
await nav.close();
