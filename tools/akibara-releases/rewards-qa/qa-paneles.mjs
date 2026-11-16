// Barrido manual-equivalente de TODA la interfaz de administración de nuestros
// plugins en el espejo: abre cada pestaña como el dueño y caza errores PHP,
// errores de consola y controles que no rendericen.
import { chromium } from 'playwright';
const BASE = process.env.AKB_BASE || 'http://localhost:8080';
if (!/localhost|127\.0\.0\.1/.test(BASE)) throw new Error('solo contra el espejo');

const PANEL = (t) => `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${t}`;
const TABS = ['dashboard','preventa','preventas','back_in_stock','series_follow','free_shipping_progress',
  'native_cart','upsells_autofill','catalog_order','taxonomy_discount','series_bundles','home_hero',
  'home_series','announcement','address','brand_editions','copys','salud_seo'];
const OTRAS = [
  ['Buscador',         `${BASE}/wp-admin/admin.php?page=akibara-search`],
  ['Claves y accesos', `${BASE}/wp-admin/admin.php?page=akibara-provider-access`],
  ['Productos',        `${BASE}/wp-admin/edit.php?post_type=product`],
  ['Valoraciones',     `${BASE}/wp-admin/edit.php?post_type=product&page=product-reviews`],
];

const nb = await chromium.launch();
const ctx = await nb.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await ctx.newPage();
const consola = [];
page.on('console', (m) => { if (m.type() === 'error') consola.push(m.text().slice(0, 120)); });
page.on('pageerror', (e) => consola.push('pageerror: ' + String(e).slice(0, 120)));

await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', process.env.AKB_USER || 'qa.owner');
await page.fill('#user_pass', process.env.AKB_PASS || 'AkbQaOwner2026!');
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);

let fallos = 0;
async function revisar(nombre, url) {
  consola.length = 0;
  const resp = await page.goto(url, { waitUntil: 'load' }).catch((e) => ({ status: () => 0, err: e }));
  const status = resp ? resp.status() : 0;
  const cuerpo = await page.evaluate(() => document.body ? document.body.innerText : '');
  const html = await page.content();
  const phpError = /Fatal error|Parse error|Warning:|Notice:|Deprecated:|Ha habido un error crítico/i.exec(cuerpo)
    || /Fatal error|Parse error/i.exec(html);
  const campos = await page.$$eval('#wpbody-content input,#wpbody-content select,#wpbody-content textarea,#wpbody-content button', (e) => e.length);
  const titulo = await page.$eval('#wpbody-content h1, #wpbody-content h2', (e) => e.textContent.trim().slice(0, 40)).catch(() => '—');
  const mal = status !== 200 || !!phpError || campos === 0;
  if (mal) fallos += 1;
  console.log(`  ${mal ? 'FALLA' : 'OK   '} ${nombre.padEnd(26)} http=${status} controles=${String(campos).padStart(3)} «${titulo}»`
    + (phpError ? `  ERROR PHP: ${phpError[0]}` : '')
    + (consola.length ? `  consola: ${consola.length}` : ''));
  const relevantes = consola.filter((c) => !/cropped-akibara_modern_vector/.test(c));
  if (relevantes.length) relevantes.slice(0, 2).forEach((c) => console.log(`         · ${c}`));
}

console.log('\n== Pestañas del panel Akibara (akibara-business)');
for (const t of TABS) await revisar(t, PANEL(t));
console.log('\n== Otras pantallas de nuestros plugins');
for (const [n, u] of OTRAS) await revisar(n, u);

console.log(`\n${fallos === 0 ? 'TODO OK' : fallos + ' PANTALLAS CON PROBLEMAS'}`);
await nb.close();
process.exit(fallos === 0 ? 0 : 1);
