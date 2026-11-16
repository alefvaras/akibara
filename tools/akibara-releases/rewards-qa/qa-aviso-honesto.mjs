/**
 * ¿El panel NOMBRA la option que no llegó a guardarse?
 *
 * En producción nunca pude verlo porque mis guardados no movían precios y por
 * tanto no dejaban deuda: sin deuda no hay rechazo y el «guardado correctamente»
 * era verdad. La receta la dio bis-avenida y aquí se aplica EN EL ESPEJO, como
 * manda la regla del dueño del 2026-09-06:
 *
 *   1. escribir UNA option de precio por CLI  → mueve la huella, deja deuda
 *   2. guardar el panel con DOS options de precio cambiadas, sin esperar
 *   3. la segunda debería rechazarse Y el panel debería NOMBRARLA
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
const BASE='http://localhost:8080', CWD='/Users/alefvaras/Documents/akibara';
const cli=(c)=>execSync(`docker compose run --rm --no-deps -T cli ${c}`,{cwd:CWD,encoding:'utf8'}).split('\n').filter(l=>!/Container /.test(l)).join('\n').trim();

const cola = Number(cli(`wp db query "SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE status='pending';" --skip-column-names`).split('\n').pop());
console.log('cola pendiente del espejo:', cola, cola > 200 ? '  ⚠ ATASCADO: límpialo antes de medir' : '  (en condiciones)');

const orig = { from: cli('wp option get akibara_taxonomy_discount_from'), to: cli('wp option get akibara_taxonomy_discount_to') };
console.log('estado original:', JSON.stringify(orig));

const nav = await chromium.launch();
const page = await (await nav.newContext({ viewport:{width:1440,height:1100} })).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil:'load' });
await page.fill('#user_login','qa.owner'); await page.fill('#user_pass','AkbQaOwner2026!');
await page.click('#wp-submit'); await page.waitForSelector('#wpadminbar', { timeout: 90000 });

const panel = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=taxonomy_discount`;
try {
  // 1) deuda en vuelo. Si el buscador YA tiene deuda sin saldar, no hace falta
  //    sembrarla: es el estado que queremos. Se comprueba antes de tocar nada.
  const est = cli(`wp eval '$s=json_decode(get_option("akb_search_editorial_discount_state"),true); $r=get_option("akb_search_editorial_discount_receipt"); echo (($s["wanted_fingerprint"]??1)===(is_array($r)?($r["fingerprint"]??2):3)) ? "SALDADA" : "CON DEUDA";'`);
  console.log('· publicación editorial:', est);
  if (est === 'SALDADA') {
    try { cli(`wp option update akibara_taxonomy_discount_from 2026-09-01`); console.log('· deuda sembrada: from -> 2026-09-01'); }
    catch { console.log('· la siembra fue RECHAZADA — ya hay deuda, seguimos'); }
  }

  // 2) el dueño guarda DOS fechas a la vez, sin esperar
  await page.goto(panel, { waitUntil:'load' });
  await page.evaluate(() => document.querySelectorAll('details').forEach(d => { d.open = true; }));
  await page.locator('[name="akibara_taxonomy_discount_from"]').first().fill('2026-09-02');
  await page.locator('[name="akibara_taxonomy_discount_to"]').first().fill('2026-09-30');
  // La barra pegajosa de «cambios sin guardar» TAPA el botón del formulario:
  // Playwright reintenta 47 veces y muere. Hay que pulsar el de la barra.
  const sticky = page.locator('#akb-sticky-save-bar button[name="akibara_panel_save"]').first();
  if (await sticky.count()) await sticky.click({ timeout: 15000 });
  else await page.locator('form button[name="akibara_panel_save"]').last().click({ timeout: 15000 });
  await page.waitForURL(/saved=1|akibara-panel/, { timeout: 60000 }).catch(()=>{});
  await page.waitForTimeout(2500);

  // 3) ¿qué dice el panel, y qué dice la base?
  const avisos = await page.evaluate(() => [...document.querySelectorAll('.notice')].map(n => n.innerText.replace(/\s+/g,' ').trim()).filter(Boolean));
  console.log('\nAVISOS DEL PANEL:');
  for (const a of avisos) console.log('  ·', a.slice(0, 210));
  const real = { from: cli('wp option get akibara_taxonomy_discount_from'), to: cli('wp option get akibara_taxonomy_discount_to') };
  console.log('\nEN LA BASE:', JSON.stringify(real), ' (yo guardé from=2026-09-02 to=2026-09-30)');

  const entraron = (real.from === '2026-09-02') + (real.to === '2026-09-30');
  // OJO: el aviso nombra los campos por su ETIQUETA humana («Vigencia desde»),
  // no por el nombre de la option, y lo dice en positivo («Se guardó todo menos
  // esto»). Mi primer matcher buscaba «no se guardó» y dio un falso negativo
  // sobre un aviso que era correcto. Se comprueba que nombre los CAMPOS.
  const nombra = avisos.some(a => /se guard[óo] todo menos|no se guard|siguen con su valor anterior/i.test(a)
    && /vigencia desde|vigencia hasta|akibara_taxonomy_discount/i.test(a));
  console.log('\n═══');
  if (entraron === 2) console.log('· entraron las DOS: no se dio el rechazo, el aviso verde es VERDAD (nada que probar en esta corrida)');
  else if (entraron < 2 && nombra) console.log('OK  se rechazó una y el panel LA NOMBRA — el aviso honesto funciona');
  else if (entraron < 2 && !nombra) console.log('✗✗ se rechazó una y el panel NO lo dice — sigue mintiendo');
} finally {
  cli(`wp option update akibara_taxonomy_discount_from '${orig.from}'`);
  cli(`wp option update akibara_taxonomy_discount_to '${orig.to}'`);
  console.log('· restaurado:', JSON.stringify(orig));
  await nav.close();
}
