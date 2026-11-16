/** Captura el POST real que envía el panel Descuentos: qué campos viajan y cuáles no. */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const USER = process.env.AKB_USER || 'qa.owner';
const PASS = process.env.AKB_PASS || 'AkbQaOwner2026!';
const TERM = process.env.AKB_TERM || '230';
const PCT = process.env.AKB_PCT || '12';

const navegador = await chromium.launch();
const page = await (await navegador.newContext()).newPage();

await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);

await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=taxonomy_discount`, { waitUntil: 'load' });
await page.evaluate(() => { document.querySelectorAll('details').forEach((d) => { d.open = true; }); });

const forma = await page.evaluate((term) => {
  const campo = document.getElementById('akibara_taxonomy_discount_pct_' + term);
  const f = campo ? campo.closest('form') : null;
  const forms = [...document.querySelectorAll('form')].map((x, i) => ({
    i, id: x.id || '', action: (x.action || '').slice(-60), campos: x.elements.length,
    tieneBotonGuardar: !!x.querySelector('button[name="akibara_panel_save"]'),
  }));
  return {
    campoExiste: !!campo,
    campoName: campo ? campo.name : null,
    campoDisabled: campo ? campo.disabled : null,
    campoEnForm: f ? (f.id || '(sin id)') : '(FUERA DE TODO FORM)',
    formTieneBoton: f ? !!f.querySelector('button[name="akibara_panel_save"]') : null,
    forms,
  };
}, TERM);
console.log('· estructura:', JSON.stringify(forma, null, 2));

await page.fill(`#akibara_taxonomy_discount_pct_${TERM}`, PCT);

let cuerpo = null;
page.on('request', (r) => {
  if (r.method() === 'POST' && /admin\.php/.test(r.url())) cuerpo = r.postData();
});

const btn = page.locator('form button[name="akibara_panel_save"]').first();
try { await btn.scrollIntoViewIfNeeded(); await btn.click({ timeout: 4000 }); }
catch { await btn.focus(); await page.keyboard.press('Enter'); }
await page.waitForLoadState('domcontentloaded').catch(() => {});
await page.waitForTimeout(800);

if (!cuerpo) {
  console.log('· NO SE CAPTURÓ NINGÚN POST');
} else {
  const pares = cuerpo.split('&').map((p) => decodeURIComponent(p.replace(/\+/g, ' ')));
  const relevantes = pares.filter((p) => /discount|akibara_panel|pct/.test(p));
  console.log('· campos del POST (' + pares.length + ' en total), los relevantes:');
  relevantes.forEach((p) => console.log('   ', p.slice(0, 120)));
  console.log('· ¿viaja el porcentaje del término?',
    pares.some((p) => p.startsWith(`akibara_taxonomy_discount_pct_${TERM}=`)));
}
await navegador.close();
