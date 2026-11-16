/**
 * ¿Por qué no se guarda el valor fuera de rango? Dos causas posibles y muy
 * distintas:
 *   (a) el NAVEGADOR lo rechaza con su validación nativa (max=6) y ni siquiera
 *       envía el formulario → es lo correcto, el usuario ve el globo del
 *       navegador y no hay defecto;
 *   (b) se envía y el servidor lo descarta sin decir nada → defecto.
 * Se distingue mirando `checkValidity()` y si la página llegó a navegar.
 */
import { chromium } from 'playwright';

const BASE = 'http://localhost:8080';
const TAB = 'native_cart';
const CAMPO = 'akibara_native_cart_recommendation_limit';

const nav = await chromium.launch();
const page = await (await nav.newContext()).newPage();
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', 'qa.owner');
await page.fill('#user_pass', 'AkbQaOwner2026!');
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);

await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${TAB}`, { waitUntil: 'load' });
await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
await page.locator(`#${CAMPO}`).fill('16');

const antes = await page.evaluate((id) => {
  const e = document.getElementById(id);
  return {
    valor: e.value, min: e.min, max: e.max,
    valido: e.checkValidity(),
    mensajeNavegador: e.validationMessage,
    formValido: e.closest('form') ? e.closest('form').checkValidity() : null,
  };
}, CAMPO);
console.log('estado del campo con 16:', JSON.stringify(antes, null, 2));

let navego = false;
page.on('framenavigated', (f) => { if (f === page.mainFrame()) navego = true; });

const b = page.locator('form button[name="akibara_panel_save"]').first();
try { await b.scrollIntoViewIfNeeded(); await b.click({ timeout: 5000 }); } catch { await b.focus(); await page.keyboard.press('Enter'); }
await page.waitForTimeout(1800);
console.log('¿el formulario llegó a enviarse? (hubo navegación):', navego);
console.log('valor en pantalla ahora:', await page.locator(`#${CAMPO}`).inputValue().catch(() => '(?)'));

console.log('\n=== VEREDICTO ===');
if (!antes.valido && !navego) {
  console.log('LO PARA EL NAVEGADOR con su validación nativa (max=' + antes.max + '): «' + antes.mensajeNavegador + '».');
  console.log('NO es un defecto: el dueño ve el aviso del navegador. Mi informe anterior era un falso positivo.');
} else if (navego) {
  console.log('El formulario SÍ se envió: si el valor no quedó, lo descarta el servidor en silencio. Eso sí es un defecto.');
} else {
  console.log('No concluyente: revisar a mano.');
}
await page.screenshot({ path: 'borde-native-cart.png' });
await nav.close();
