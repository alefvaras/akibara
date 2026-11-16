// Verificación PROD post-fix Blue: el checkout hidrata, los iframes de
// Mercado Pago MONTAN y ACEPTAN entrada (la evidencia del cliente era que
// no podía escribir). Sin pagar: solo montaje + editabilidad + captura.
import { chromium } from 'playwright';

const OUT = '/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/d5a6502d-96ec-4b2f-bfd8-ffb8f9cd378c/scratchpad/mp-prod';
import { mkdirSync } from 'node:fs';
mkdirSync( OUT, { recursive: true } );

let fallas = 0;
const check = ( n, ok, extra = '' ) => {
  console.log( `${n}: ${ok ? 'OK' : 'FALLA'}${extra ? ' [' + extra + ']' : ''}` );
  if ( ! ok ) fallas++;
};

const browser = await chromium.launch();
const ctx = await browser.newContext( { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } );
const p = await ctx.newPage();

// 1. Producto al carrito (mismo tomo que el fatal: Prison School 02).
await p.goto( 'https://akibara.cl/product/prison-school-02-ivrea-espana/', { waitUntil: 'domcontentloaded', timeout: 60000 } );
await p.locator( 'button[name="add-to-cart"], .single_add_to_cart_button' ).first().click();
await p.waitForTimeout( 3500 );

// 2. Checkout directo.
await p.goto( 'https://akibara.cl/checkout/', { waitUntil: 'domcontentloaded', timeout: 60000 } );
await p.waitForTimeout( 6000 );

// 3. ¿Error crítico visible? (el síntoma del cliente)
const critico = await p.locator( 'text=error crítico' ).count();
check( 'sin-error-critico', 0 === critico );

// 4. El checkout hidrató (bloques con formulario de contacto).
const email = await p.locator( '#email, input[id*="email"]' ).count();
check( 'checkout-hidratado', email > 0 );

// 4b. Completar contacto y dirección (MP monta tras la dirección).
try {
  await p.locator( '#email' ).fill( 'qa-fable@akibara.cl' );
  const rut = p.locator( 'input[id*="rut"], input[name*="rut"]' ).first();
  if ( await rut.count() ) { await rut.fill( '11111111-1' ); }
  await p.locator( '#shipping-first_name, input[id*="first_name"]' ).first().fill( 'QA' );
  await p.locator( '#shipping-last_name, input[id*="last_name"]' ).first().fill( 'Fable' );
  await p.locator( '#shipping-address_1, input[id*="address_1"]' ).first().fill( 'Av Providencia 1234' );
  const comuna = p.locator( 'input[id*="comuna"], input[role="combobox"]' ).first();
  if ( await comuna.count() ) {
    await comuna.click(); await comuna.type( 'Providencia', { delay: 40 } );
    await p.waitForTimeout( 1200 );
    const opt = p.locator( '[role="option"]', { hasText: 'Providencia' } ).first();
    if ( await opt.count() ) { await opt.click(); }
  }
  const city = p.locator( '#shipping-city' ).first();
  if ( await city.count() && ! ( await city.inputValue() ) ) { await city.fill( 'Providencia' ); }
  await p.waitForTimeout( 6000 );
} catch ( e ) { console.log( 'form: ' + String( e ).slice( 0, 90 ) ); }

// 5. Iframes de Mercado Pago montados.
await p.waitForTimeout( 5000 );
const frames = p.frames().filter( ( f ) => /mercadopago|mercadolibre|mlstatic/.test( f.url() ) );
const iframeEls = await p.locator( 'iframe[src*="mercado"], iframe[src*="mlstatic"], .mp-checkout-custom-card-form iframe, [id*="cardNumber"] iframe' ).count();
check( 'mp-iframes-montados', frames.length > 0 || iframeEls > 0, `frames=${frames.length} iframes=${iframeEls}` );

// 6. Seleccionar «Tarjeta (Mercado Pago)» y escribir en el NÚMERO —
// exactamente lo que el cliente no podía hacer.
const radioTarjeta = p.locator( 'label:has-text("Tarjeta de crédito o débito")' ).first();
if ( await radioTarjeta.count() ) {
  await radioTarjeta.scrollIntoViewIfNeeded();
  await radioTarjeta.click();
  await p.waitForTimeout( 6000 );
}
let editable = false, valorLeido = '', frameInfo = '';
const framesMp = p.frames().filter( ( f ) => /mercadopago|mercadolibre|mlstatic/.test( f.url() ) );
for ( const f of framesMp ) {
  try {
    const candidatos = f.locator( 'input[name*="card" i], input[id*="card" i], input[placeholder*="mero" i], input[aria-label*="tarjeta" i], input' );
    const n = await candidatos.count();
    for ( let i = 0; i < n; i++ ) {
      const input = candidatos.nth( i );
      const name = ( await input.getAttribute( 'name' ) ) || ( await input.getAttribute( 'id' ) ) || '';
      try {
        await input.click( { timeout: 3000 } );
        await input.type( '4111111111111111', { delay: 30 } );
        valorLeido = await input.inputValue();
        if ( valorLeido.replace( /\D/g, '' ).length >= 8 ) {
          editable = true;
          frameInfo = name + '@' + f.url().slice( 0, 60 );
          await input.fill( '' );
          break;
        }
        await input.fill( '' ).catch( () => {} );
      } catch ( e2 ) { /* siguiente input */ }
    }
    if ( editable ) break;
  } catch ( e ) { /* siguiente frame */ }
}
check( 'mp-campo-tarjeta-editable', editable, editable ? frameInfo : `frames=${framesMp.length} leido="${valorLeido}"` );
await p.screenshot( { path: `${OUT}/prod-mp-tarjeta.png` } );

// 7. Métodos de envío visibles (incluido el retiro restaurado, si la dirección ya está).
const envio = await p.locator( 'text=/Despacho a domicilio|Retiro/' ).count();
console.log( `metodos-envio-visibles: ${envio} (informativo: sin dirección completa pueden no listar)` );

await p.screenshot( { path: `${OUT}/prod-checkout-mp.png`, fullPage: false } );
const mpSection = p.locator( '.wc-block-checkout__payment-method, [id*="payment"], .wp-block-woocommerce-checkout-payment-block' ).first();
if ( await mpSection.count() ) {
  await mpSection.scrollIntoViewIfNeeded();
  await p.waitForTimeout( 1000 );
  await p.screenshot( { path: `${OUT}/prod-mp-seccion.png` } );
}

// 8. Vaciar el carrito QA (restaurar).
await p.goto( 'https://akibara.cl/carrito/', { waitUntil: 'domcontentloaded' } );
await p.waitForTimeout( 3000 );
const quitar = p.locator( '.wc-block-cart-item__remove-link, a.remove, button:has-text("Eliminar")' ).first();
if ( await quitar.count() ) {
  await quitar.click();
  await p.waitForTimeout( 2500 );
}
const restante = await p.locator( '.wc-block-cart-items__row, .cart_item' ).count();
check( 'carrito-qa-vaciado', 0 === restante, `filas=${restante}` );

await browser.close();
console.log( `MP-PROD-FALLAS=${fallas}` );
process.exit( fallas > 0 ? 1 : 0 );
