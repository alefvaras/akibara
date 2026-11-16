// Sonda de PRODUCCIÓN (sin pedidos): la caja muestra «Retiro en Punto Blue
// Express» a un invitado con dirección de la RM. Añade un tomo por la Store API
// dentro del mismo navegador, abre la caja, rellena la dirección y lee las
// opciones de envío. Al terminar vacía el carrito de la sonda.
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'https://akibara.cl';
const PRODUCTO = Number(process.env.AKB_PRODUCTO || 27914);
const UA = 'Mozilla/5.0 (AkibaraQA) Playwright';
let fallos = 0;
const ok = (c, m, d) => { if (c) { console.log(`  ok  ${m}`); } else { fallos += 1; console.error(`FAIL: ${m}${d === undefined ? '' : ` → ${JSON.stringify(d).slice(0, 400)}`}`); } };

const nav = await chromium.launch();
const ctx = await nav.newContext({ viewport: { width: 390, height: 844 }, userAgent: UA });
const page = await ctx.newPage();
try {
  await page.goto(`${BASE}/carrito/`, { waitUntil: 'domcontentloaded', timeout: 90000 });
  const nonce = (await ctx.request.get(`${BASE}/wp-json/wc/store/v1/cart`)).headers().nonce;
  const add = await ctx.request.post(`${BASE}/wp-json/wc/store/v1/cart/add-item`, { headers: { Nonce: nonce }, data: { id: PRODUCTO, quantity: 1 } });
  ok(add.status() === 201, 'tomo en el carrito de la sonda', add.status());
  const dir = { first_name: 'QA', last_name: 'Sonda', address_1: 'Gran Avenida 1234', city: 'San Miguel', state: 'CL-RM', postcode: '', country: 'CL' };
  await ctx.request.post(`${BASE}/wp-json/wc/store/v1/cart/update-customer`, { headers: { Nonce: nonce }, data: { shipping_address: dir, billing_address: { ...dir, email: 'qa.sonda@akibara.invalid' } } });

  const resp = await page.goto(`${BASE}/checkout/?nocache=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 90000 });
  console.log(`    caja: ${resp && resp.status()} ${page.url()} · cookies: ${(await ctx.cookies()).map((c) => c.name).filter((n) => /woocommerce|wp_woo/.test(n)).join(',')}`);
  const hayCaja = await page.waitForSelector('.wc-block-checkout, .wp-block-woocommerce-checkout', { timeout: 60000 }).then(() => true).catch(() => false);
  if (!hayCaja) { await page.screenshot({ path: '/tmp/qa-pudo-produccion-sin-caja.png', fullPage: true }); }
  ok(hayCaja, 'la caja de bloques carga', page.url());
  await page.waitForTimeout(5000);
  const texto = (await page.locator('.wc-block-checkout, .wp-block-woocommerce-checkout').first().innerText().catch(() => '')).replace(/\s+/g, ' ');
  ok(/Retiro en Punto Blue Express/i.test(texto), 'la caja ofrece «Retiro en Punto Blue Express»', texto.match(/(Blue Express.{0,160})/i)?.[1]);
  ok(/Despacho a domicilio/i.test(texto), 'y sigue el despacho a domicilio');
  await page.screenshot({ path: '/tmp/qa-pudo-produccion.png', fullPage: true });
} finally {
  const nonce = (await ctx.request.get(`${BASE}/wp-json/wc/store/v1/cart`)).headers().nonce;
  await ctx.request.delete(`${BASE}/wp-json/wc/store/v1/cart/items`, { headers: { Nonce: nonce } }).catch(() => {});
  await nav.close();
}
if (fallos) { console.error(`FAIL: ${fallos}`); process.exit(1); }
console.log('PASS: el retiro en punto se ofrece en la caja de producción');
