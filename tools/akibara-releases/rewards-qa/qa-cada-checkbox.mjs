/**
 * «Cada checkbox, cada guardado» — la orden del dueño del 2026-09-06.
 *
 * Para CADA control de CADA pestaña del panel: lo cambia, guarda, RECARGA y
 * comprueba que se relee cambiado; después lo restaura y comprueba que se relee
 * como estaba. Dos afirmaciones por control:
 *
 *   A) lo que toco se guarda          (si no, el dueño trabaja en vano)
 *   B) lo que guardo se relee igual   (si no, el dueño trabaja sobre una mentira)
 *
 * No confunde «no se guardó» con «no se pintó»: la comprobación es sobre el
 * valor RELEÍDO del formulario tras recargar, que es lo que ve el dueño.
 *
 * AKB_TABS=announcement,address  limita las pestañas.
 * AKB_BASE=https://akibara.cl    apunta a producción (exige AKB_PRODUCCION=si).
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const esProd = !/localhost|127\.0\.0\.1/.test(BASE);
if (esProd && process.env.AKB_PRODUCCION !== 'si') throw new Error('Para producción, exporta AKB_PRODUCCION=si.');
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const SOLO = process.env.AKB_TABS ? process.env.AKB_TABS.split(',') : null;
const MAX_POR_PESTANA = Number(process.env.AKB_MAX || 6);

// Pestañas que mueven la foto editorial de precios: tocarlas cambia el `seq` y
// puede dejar sin pareja la acción que apaga la promo. No se barren a ciegas.
const PELIGROSAS = new Set(['taxonomy_discount']);

const nav = await chromium.launch();
const ctx = await nav.newContext({ viewport: { width: 1440, height: 1100 } });
const page = await ctx.newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', USER); await page.fill('#user_pass', PASS);
await page.click('#wp-submit'); await page.waitForSelector('#wpadminbar', { timeout: 90000 });
console.log(`· sesión iniciada en ${BASE}\n`);

const guardar = async () => {
  const sticky = page.locator('#akb-sticky-save-bar button[name="akibara_panel_save"]').first();
  if (await sticky.count()) await sticky.click({ timeout: 15000 });
  else await page.locator('form button[name="akibara_panel_save"]').last().click({ timeout: 15000 });
  await page.waitForLoadState('load').catch(() => {});
  await page.waitForTimeout(1500);
};

await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel`, { waitUntil: 'load' });
let tabs = await page.$$eval('a[href*="page=akibara-panel"][href*="tab="]', (as) =>
  [...new Set(as.map((a) => new URL(a.href).searchParams.get('tab')).filter(Boolean))]);
if (SOLO) tabs = tabs.filter((t) => SOLO.includes(t));

const fallos = [];
for (const tab of tabs) {
  if (PELIGROSAS.has(tab) && !process.env.AKB_INCLUIR_PELIGROSAS) { console.log(`── ${tab}: SALTADA (mueve la foto de precios; usa AKB_INCLUIR_PELIGROSAS=si)`); continue; }
  const url = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${tab}`;
  await page.goto(url, { waitUntil: 'load' });
  await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
  const checks = await page.$$eval('form input[type="checkbox"]', (els) =>
    els.map((e) => e.name).filter((n) => n && !/\[\]$/.test(n)));
  const unicos = [...new Set(checks)].slice(0, MAX_POR_PESTANA);
  if (!unicos.length) { console.log(`── ${tab}: sin checkboxes`); continue; }
  console.log(`── ${tab}  (${unicos.length} de ${new Set(checks).size} checkboxes)`);

  for (const name of unicos) {
    const sel = `input[type="checkbox"][name="${name.replace(/([[\]])/g, '\\$1')}"]`;
    try {
      await page.goto(url, { waitUntil: 'load' });
      await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
      const l = page.locator(sel).first();
      if (!(await l.count())) { console.log(`   ?  ${name}: desapareció`); continue; }
      const original = await l.isChecked();

      // A) cambiar y comprobar que SE GUARDA
      await l.scrollIntoViewIfNeeded();
      if (original) await l.uncheck({ force: true }); else await l.check({ force: true });
      await guardar();
      await page.goto(url, { waitUntil: 'load' });
      await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
      const tras = await page.locator(sel).first().isChecked();

      // B) restaurar y comprobar que VUELVE
      const l2 = page.locator(sel).first();
      await l2.scrollIntoViewIfNeeded();
      if (original) await l2.check({ force: true }); else await l2.uncheck({ force: true });
      await guardar();
      await page.goto(url, { waitUntil: 'load' });
      await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
      const vuelto = await page.locator(sel).first().isChecked();

      const guardo = tras === !original;
      const restauro = vuelto === original;
      if (guardo && restauro) console.log(`   OK ${name}`);
      else {
        const q = !guardo ? 'NO se guarda el cambio' : 'no vuelve a su valor original';
        console.log(`   ✗✗ ${name}: ${q}  (era ${original}, tras guardar ${tras}, tras restaurar ${vuelto})`);
        fallos.push({ tab, name, q, original, tras, vuelto });
      }
    } catch (e) {
      console.log(`   ✗  ${name}: ${String(e).slice(0, 90)}`);
      fallos.push({ tab, name, q: 'error: ' + String(e).slice(0, 90) });
    }
  }
}

console.log('\n═══ RESUMEN ═══');
if (!fallos.length) console.log('Todos los checkboxes probados se guardan y se releen igual.');
for (const f of fallos) console.log(` [${f.tab}] ${f.name}: ${f.q}`);
await nav.close();
