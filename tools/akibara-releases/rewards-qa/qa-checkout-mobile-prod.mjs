import { chromium } from 'playwright';
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 AkibaraQA';
function log(step, data) { console.log('##', step, JSON.stringify(data)); }

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 375, height: 812 }, userAgent: UA, isMobile: true });
const page = await ctx.newPage();

// Añadir un tomo al carrito desde su ficha
await page.goto('https://akibara.cl/chainsaw-man-1-ivrea-argentina/', { waitUntil: 'load' });
const addBtn = page.locator('button.single_add_to_cart_button, button[name=add-to-cart]').first();
await addBtn.click();
await page.waitForTimeout(2500);
log('agregado-al-carrito', { url: page.url() });

await page.goto('https://akibara.cl/checkout/', { waitUntil: 'load' });
await page.waitForTimeout(2000);
log('checkout-cargado', { url: page.url() });

const bodyText = await page.locator('body').innerText();
const tieneAvisoColeccion = bodyText.includes('Con tu cuenta Akibara te avisamos');
log('aviso-coleccion', { tieneAvisoColeccion, snippet: (bodyText.match(/.{0,40}Con tu cuenta Akibara te avisamos.{0,120}/) || [''])[0] });

const casillaBrevo = page.locator('label:has-text("Esperando el próximo tomo")').first();
const casillaCount = await casillaBrevo.count();
let casillaChecked = null;
if (casillaCount) {
  const input = page.locator('input[type=checkbox]').filter({ has: page.locator('xpath=..') });
  // localizar el checkbox asociado por 'for'
  const forAttr = await casillaBrevo.getAttribute('for').catch(() => null);
  if (forAttr) {
    casillaChecked = await page.locator('#' + forAttr).isChecked().catch(() => null);
  } else {
    const nearbyCheckbox = casillaBrevo.locator('xpath=preceding::input[@type="checkbox"][1]');
    casillaChecked = await nearbyCheckbox.isChecked().catch(() => null);
  }
}
log('casilla-brevo', { casillaCount, casillaChecked, texto: (await casillaBrevo.innerText().catch(() => '')).slice(0,150) });

const transferMatch = bodyText.match(/.{0,20}referencia de pago\.?\s*Puedes.{0,60}/);
log('texto-transferencia', { encontrado: !!transferMatch, snippet: transferMatch ? transferMatch[0] : null });

// Vaciar el carrito (Store API, misma sesión/cookies de este contexto) antes de cerrar.
const vaciado = await page.evaluate(async () => {
  const r1 = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' });
  const nonce = r1.headers.get('Nonce') || '';
  const r2 = await fetch('/wp-json/wc/store/v1/cart/items', {
    method: 'DELETE',
    credentials: 'same-origin',
    headers: { Nonce: nonce },
  });
  const body = await r2.json().catch(() => null);
  return { status: r2.status, items: Array.isArray(body?.items) ? body.items.length : null };
});
log('carrito-vaciado', vaciado);

const verificacion = await page.evaluate(async () => {
  const r = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' });
  const body = await r.json().catch(() => null);
  return { status: r.status, items: Array.isArray(body?.items) ? body.items.length : body };
});
log('verificacion-carrito-vacio', verificacion);

await browser.close();
