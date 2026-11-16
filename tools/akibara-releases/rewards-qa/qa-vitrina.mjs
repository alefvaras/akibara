/**
 * Vitrina (catalog_order): pantalla de ACCIÓN, no de ajustes.
 *
 * Como la usaría el dueño: leer la fecha de la última corrida, pulsar
 * «Recalcular la vitrina ahora», comprobar que el aviso dice la verdad y que la
 * corrida ocurre de verdad (cambia `akb_catalog_order_last_run`) y que el orden
 * del catálogo se reescribe.
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
const BASE='http://localhost:8080', CWD='/Users/alefvaras/Documents/akibara';
const cli=(c)=>execSync(`docker compose run --rm --no-deps -T cli ${c}`,{cwd:CWD,encoding:'utf8'}).split('\n').filter(l=>!/Container /.test(l)).join('\n').trim();

const antes = cli(`wp option get akb_catalog_order_last_run --format=json`);
console.log('última corrida ANTES:', antes.slice(0, 160) || '(nunca)');
const ordenAntes = cli(`wp db query "SELECT ID, menu_order FROM wp_posts WHERE post_type='product' AND post_status='publish' ORDER BY ID LIMIT 8;" --skip-column-names`);

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport:{width:1440,height:1000} })).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil:'load' });
await page.fill('#user_login', 'qa.owner'); await page.fill('#user_pass', 'AkbQaOwner2026!');
await page.click('#wp-submit'); await page.waitForSelector('#wpadminbar', { timeout: 60000 });

await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=catalog_order`, { waitUntil:'load' });
const textoAntes = await page.evaluate(() => document.querySelector('.akb-panel-content')?.innerText.replace(/\s+/g,' ').trim().slice(0,320) || document.body.innerText.slice(0,320));
console.log('\nPANTALLA ANTES:\n ', textoAntes, '\n');

const boton = page.locator('button:has-text("Recalcular la vitrina ahora")').first();
if (!(await boton.count())) { console.log('✗✗ no existe el botón de recálculo'); await nav.close(); process.exit(1); }
await boton.click();
await page.waitForURL(/akb_vitrina=/, { timeout: 30000 }).catch(()=>console.log('  (la URL no trajo akb_vitrina)'));
console.log('URL tras pulsar:', page.url().replace(BASE,''));
const aviso = await page.evaluate(() => [...document.querySelectorAll('.notice')].map(n=>n.innerText.replace(/\s+/g,' ').trim()).filter(t=>/recálculo|recalcul|vitrina/i.test(t))[0] || '(sin aviso de vitrina)');
console.log('AVISO:', aviso);

// ¿ocurre de verdad? el cron del espejo empuja Action Scheduler cada 60 s
for (let i = 1; i <= 8; i++) {
  await new Promise(r => setTimeout(r, 20000));
  const ahora = cli(`wp option get akb_catalog_order_last_run --format=json`);
  if (ahora !== antes) { console.log(`\n✔ la corrida OCURRIÓ a los ~${i*20}s:`, ahora.slice(0,200)); break; }
  console.log(`  t+${i*20}s  sin cambio todavía`);
  if (i === 8) console.log('\n✗✗ 160 s después la corrida NO ha ocurrido');
}
const ordenDespues = cli(`wp db query "SELECT ID, menu_order FROM wp_posts WHERE post_type='product' AND post_status='publish' ORDER BY ID LIMIT 8;" --skip-column-names`);
console.log('\n¿cambió el menu_order?', ordenAntes === ordenDespues ? 'NO (mismos 8 primeros)' : 'SÍ');
await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=catalog_order`, { waitUntil:'load' });
console.log('\nPANTALLA DESPUÉS:\n ', (await page.evaluate(() => document.querySelector('.akb-panel-content')?.innerText.replace(/\s+/g,' ').trim().slice(0,320) || '')));
await nav.close();
