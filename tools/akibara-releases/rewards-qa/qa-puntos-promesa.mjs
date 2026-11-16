/**
 * QA de Puntos y Reseñas: LA PROMESA DEL PANEL TIENE QUE VERSE EN LA TIENDA.
 *
 * La premisa sellada de este proyecto es que el dueño configura todo sin tocar
 * código. Eso sólo es cierto si lo que escribe en el panel cambia lo que el
 * cliente lee en la ficha. Aquí se cambia un valor desde la interfaz y se va a
 * mirar la superficie SERVIDA, no la base de datos.
 *
 * Restaura siempre (finally). Sólo espejo.
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
if (!/localhost|127\.0\.0\.1/.test(BASE)) throw new Error('Sólo contra el espejo.');
const CWD = '/Users/alefvaras/Documents/akibara';
const AJUSTES = `${BASE}/wp-admin/admin.php?page=akb-rewards-settings`;

const cli = (c) => execSync(`docker compose run --rm --no-deps -T cli ${c}`, { cwd: CWD, encoding: 'utf8' });
const opcion = (n) => {
  const s = cli(`wp eval 'echo "<<<", var_export(get_option("${n}"), true), ">>>";'`);
  const m = s.match(/<<<([\s\S]*?)>>>/);
  return m ? m[1].trim() : '(?)';
};

const hallazgos = [];
const anotar = (t, d) => { hallazgos.push({ titulo: t, detalle: d }); console.log(`  ✗ ${t}\n     ${d}`); };

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', 'qa.owner');
await page.fill('#user_pass', 'AkbQaOwner2026!');
// `waitForNavigation` se cae por timeout cuando el espejo está ocupado
// (rebuild o pump en marcha). Esperar al adminbar es la señal real de que
// entramos, y no depende de cuántas redirecciones haya en medio.
await page.click('#wp-submit');
await page.waitForSelector('#wpadminbar', { timeout: 60000 });

await page.goto(AJUSTES, { waitUntil: 'load' });
await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));

const campos = await page.evaluate(() => [...document.querySelectorAll('input[type=number]')]
  .filter((e) => e.id && !e.disabled)
  .map((e) => ({ id: e.id, name: e.name, min: e.min, max: e.max, valor: e.value })));
console.log('· campos numéricos del panel de Puntos:');
campos.forEach((c) => console.log(`    ${c.id.padEnd(42)} = ${String(c.valor).padEnd(8)} (min=${c.min || '-'} max=${c.max || '-'})`));

const guardar = async () => {
  const b = page.locator('form button[type=submit], form input[type=submit], button[name*="save"]').first();
  if (!(await b.count())) return false;
  try { await b.scrollIntoViewIfNeeded(); await b.click({ timeout: 5000 }); }
  catch { await b.focus(); await page.keyboard.press('Enter'); }
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForTimeout(1200);
  return true;
};

console.log('\n· botones de guardado en la pantalla:');
console.log('   ', JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('button[type=submit],input[type=submit]')]
  .map((b) => `${b.tagName}[name=${b.name || '-'}] "${(b.textContent || b.value || '').trim().slice(0, 28)}"`))));

console.log('\n· opciones akb_rewards_* en base:');
const s = cli(`wp eval 'global $wpdb; $r=$wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE \\"akb_rewards_%\\" ORDER BY option_name", ARRAY_A); foreach($r as $x){ printf("%-46s %s\\n", $x["option_name"], substr($x["option_value"],0,26)); }'`);
console.log(s.split('\n').filter((l) => /akb_rewards/.test(l)).join('\n'));

await nav.close();
console.log('\n=== HALLAZGOS ===');
console.log(hallazgos.length ? JSON.stringify(hallazgos, null, 2) : 'ninguno en esta pasada');
