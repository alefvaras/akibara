/**
 * Control honesto antes de reportar nada: ¿el panel DESCARTA el valor fuera de
 * rango, o es mi script el que no guarda?
 *
 * Se prueba el MISMO campo dos veces: un valor legal (control) y uno ilegal.
 * Si el legal se guarda y el ilegal no, el descarte es del panel. Si no se
 * guarda ninguno, el que falla soy yo y no hay hallazgo que reportar.
 * La verdad la dice la base de datos, no lo que pinte el formulario.
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const TAB = process.env.AKB_TAB || 'native_cart';
const CAMPO = process.env.AKB_CAMPO || 'akibara_native_cart_recommendation_limit';
const LEGAL = process.env.AKB_LEGAL || '5';
const ILEGAL = process.env.AKB_ILEGAL || '16';

const cli = (c) => execSync(`docker compose run --rm --no-deps -T cli ${c}`, { cwd: CWD, encoding: 'utf8' });
const enBase = () => {
  if (process.env.AKB_TERMMETA) {
    const s = cli(`wp eval 'echo "<<<", var_export(get_option("${CAMPO}"), true), ">>>";'`);
    const m = s.match(/<<<([\s\S]*?)>>>/); return m ? m[1].trim() : '(?)';
  }
  const s = cli(`wp eval 'echo "<<<", var_export(get_option("${CAMPO}"), true), ">>>";'`);
  const m = s.match(/<<<([\s\S]*?)>>>/);
  return m ? m[1].trim() : '(?)';
};

const nav = await chromium.launch();
const page = await (await nav.newContext()).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', 'qa.owner');
await page.fill('#user_pass', 'AkbQaOwner2026!');
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);

const escribir = async (valor) => {
  await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${TAB}`, { waitUntil: 'load' });
  await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
  await page.locator('#' + CAMPO.replace(/([^\w-])/g, '\\$1')).fill(valor);
  const b = page.locator('form button[name="akibara_panel_save"]').first();
  try { await b.scrollIntoViewIfNeeded(); await b.click({ timeout: 5000 }); }
  catch { await b.focus(); await page.keyboard.press('Enter'); }
  await page.waitForLoadState('domcontentloaded').catch(() => {});
  await page.waitForTimeout(1000);
  const aviso = await page.evaluate(() => [...document.querySelectorAll('.notice, .updated, .error')]
    .map((e) => e.textContent.replace(/\s+/g, ' ').trim())
    .filter((t) => /guardad|error|no se|corrig|tope|máxim/i.test(t)).join(' | ').slice(0, 160));
  return aviso;
};

const original = enBase();
console.log('en base al empezar        :', original);

const a1 = await escribir(LEGAL);
const v1 = enBase();
console.log(`escribo ${LEGAL} (LEGAL)         : base=${v1}   aviso="${a1}"`);

const a2 = await escribir(ILEGAL);
const v2 = enBase();
console.log(`escribo ${ILEGAL} (ILEGAL, max=6): base=${v2}   aviso="${a2}"`);

await escribir(original === 'false' ? '3' : original.replace(/'/g, ''));
console.log('restaurado                :', enBase());
await nav.close();

console.log('\n=== VEREDICTO ===');
if (v1 !== LEGAL) {
  console.log('MI SCRIPT NO GUARDA: ni el valor legal llegó a la base. No hay hallazgo que reportar.');
} else if (v2 === ILEGAL) {
  console.log('EL PANEL ACEPTA el valor fuera de rango: eso sí es un defecto.');
} else if (/corrig|tope|máxim|no válid/i.test(a2)) {
  console.log('El panel lo corrige Y LO DICE: correcto.');
} else {
  console.log(`El panel DESCARTA el valor en silencio (quedó ${v2}) y dice «guardado». Defecto de honestidad.`);
}
