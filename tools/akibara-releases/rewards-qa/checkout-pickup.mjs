/**
 * ¿El cliente con envío gratis todavía puede elegir el retiro por error?
 *
 * Monta un carrito sobre el umbral, va al checkout con dirección de la RM y
 * comprueba lo único que importa: que «Retiro…» NO esté entre los radios de
 * envío, y que exista la pestaña para quien sí quiere retirar.
 */
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const ETIQUETA = process.argv[2] || 'sin-etiqueta';

const b = await chromium.launch();
const ctx = await b.newContext({
  userAgent: 'Mozilla/5.0 AkibaraQA/1.0 checkout-pickup',
  viewport: { width: 375, height: 812 },
});
const p = await ctx.newPage();

// Carrito sobre el umbral: se añade el mismo tomo hasta pasar de $60.000.
// Cada tomo tiene stock 1, así que el carrito se llena con títulos DISTINTOS.
const IDS = (process.env.AKB_IDS || '27347,27345,27337,27333').split(',').map(Number);
for (const id of IDS) {
  await p.goto(`${BASE}/?add-to-cart=${id}&quantity=1`, { waitUntil: 'domcontentloaded' });
}
await p.goto(`${BASE}/carrito/`, { waitUntil: 'networkidle' });
const totalCarrito = await p.locator('.wc-block-components-totals-item__value, .order-total .amount').first().innerText().catch(() => '?');
console.log(`carrito: ${totalCarrito}`);

await p.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' }).catch(() => {});
if (!p.url().includes('checkout')) {
  await p.goto(`${BASE}/?page_id=9083`, { waitUntil: 'networkidle' });
}
await p.waitForTimeout(2500);

// Dirección en la RM para que cotice la zona correcta.
const rellenar = async (sel, valor) => {
  const el = p.locator(sel).first();
  if (await el.count()) { await el.fill(valor).catch(() => {}); }
};
await rellenar('#shipping-first_name, #billing-first_name', 'Ale');
await rellenar('#shipping-last_name, #billing-last_name', 'QA');
await rellenar('#shipping-address_1, #billing-address_1', 'Gran Avenida 5000');
const CIUDAD = process.env.AKB_CIUDAD || 'San Miguel';
const CP     = process.env.AKB_CP     || '8920000';
await rellenar('#shipping-city, #billing-city', CIUDAD);
await rellenar('#shipping-postcode, #billing-postcode', CP);
// La REGIÓN va primero: el combobox de comuna es first-party y sólo acepta
// comunas de la región elegida («Sin coincidencias en esta región» si no).
if (process.env.AKB_ESTADO) {
  const selEstado = p.locator('select#shipping-state, select#billing-state, select[id$="-state"]').first();
  if (await selEstado.count()) {
    const opciones = await selEstado.locator('option').allInnerTexts();
    const match = opciones.find(o => o.toLowerCase().includes(process.env.AKB_ESTADO.toLowerCase()));
    if (match) {
      await selEstado.selectOption({ label: match });
      console.log(`región seleccionada: ${match}`);
      await p.waitForTimeout(1500);
      await rellenar('#shipping-city, #billing-city', CIUDAD);
      await p.waitForTimeout(2500);
    } else {
      console.log(`REGIÓN NO ENCONTRADA. Opciones: ${opciones.slice(0,20).join(' | ')}`);
    }
  } else {
    console.log('no hay select de región');
  }
}
console.log(`destino: ${CIUDAD} ${CP} ${process.env.AKB_ESTADO || ''}`);
await p.waitForTimeout(3500);

const texto = await p.locator('body').innerText();

// ¿Pestañas Envío / Retiro?
const pestanas = await p.locator('.wc-block-checkout__shipping-method-container, [class*="shipping-method-container"] button, [role="radiogroup"] [role="radio"]').allInnerTexts().catch(() => []);
console.log('\n--- controles de método (pestañas) ---');
pestanas.slice(0, 6).forEach(t => console.log('   ', JSON.stringify(t.replace(/\s+/g, ' ').slice(0, 80))));

// ¿Qué hay en la LISTA DE ENVÍO?
const opciones = await p.locator('.wc-block-components-shipping-rates-control__package .wc-block-components-radio-control__option, .wc-block-components-shipping-rates-control label').allInnerTexts().catch(() => []);
console.log('\n--- opciones en la lista de envío ---');
opciones.forEach(t => console.log('   ', JSON.stringify(t.replace(/\s+/g, ' ').slice(0, 90))));

const retiroEnLista = opciones.some(t => /retiro/i.test(t));
console.log('\n=== VEREDICTO ===');
console.log(`  «Retiro» dentro de la lista de ENVÍO : ${retiroEnLista ? 'SÍ (el error sigue siendo posible)' : 'NO'}`);
console.log(`  la página menciona «Retiro en Metro San Miguel»: ${/Retiro en Metro San Miguel/i.test(texto) ? 'sí' : 'no'}`);
console.log(`  la página menciona «Retiro en persona»        : ${/Retiro en persona/i.test(texto) ? 'sí' : 'no'}`);
console.log(`  la página menciona «Blue Express Despacho»    : ${/Blue Express Despacho/i.test(texto) ? 'sí' : 'no'}`);

await p.screenshot({ path: `checkout-pickup-${ETIQUETA}.png`, fullPage: true });
console.log(`\ncaptura: checkout-pickup-${ETIQUETA}.png`);
await b.close();
