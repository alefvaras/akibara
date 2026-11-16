// @ts-check
/**
 * El carrito no salta mientras hidrata (PC-3 / PM-8 / UM-11, lote T2).
 *
 * Medido en el espejo el 2026-09-22 con CPU x4 y 1,6 Mbps / 150 ms: al montar
 * el bloque, React pinta el esqueleto del carrito LLENO sin `.is-loading`; el
 * pie entraba en pantalla y volvía a salir (CLS 0,28-0,44 en el carrito vacío y
 * 0,36-0,48 en el lleno; en campo, `footer.site-footer` 0,548). Tras el arreglo,
 * 0,0004 o menos en los cuatro casos.
 *
 * El estrangulado usa CDP: sólo Chromium. El carrito lleno escribe (una línea
 * en la sesión de esta prueba): sólo contra el espejo.
 */

import { expect, test } from '@playwright/test';
import { productoConBadge, soloLectura } from './soporte/tienda.mjs';
import { borrarSesionWcAlTerminar } from './soporte/tema-espejo.mjs';

const OBSERVADOR = `
window.__cls = [];
new PerformanceObserver( ( l ) => {
	for ( const e of l.getEntries() ) {
		if ( ! e.hadRecentInput ) {
			window.__cls.push( { v: e.value, src: ( e.sources || [] ).map( ( s ) => s.node && s.node.nodeName ) } );
		}
	}
} ).observe( { type: 'layout-shift', buffered: true } );
`;

async function medirCarrito( page ) {
	await page.addInitScript( OBSERVADOR );
	const cdp = await page.context().newCDPSession( page );
	await cdp.send( 'Network.enable' );
	await cdp.send( 'Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: 209715, uploadThroughput: 96000 } );
	await cdp.send( 'Emulation.setCPUThrottlingRate', { rate: 4 } );
	const r = await page.goto( '/carrito/', { waitUntil: 'load', timeout: 110_000 } );
	// Se mide hasta el FINAL de la hidratación, no hasta `networkidle`: con la
	// red estrangulada el Store API puede llegar después, y el salto también.
	await page.waitForFunction( () => {
		const c = document.querySelector( '.wp-block-woocommerce-cart' );
		return !! c && ! c.classList.contains( 'is-loading' )
			&& ! document.querySelector( '.wc-block-cart-items .wc-block-components-skeleton__element' )
			&& !! document.querySelector( '.wc-block-cart-items__row:not([aria-label]), .wp-block-woocommerce-empty-cart-block' );
	}, null, { timeout: 110_000, polling: 250 } );
	await page.waitForTimeout( 1500 );
	await cdp.send( 'Emulation.setCPUThrottlingRate', { rate: 1 } );
	const cambios = await page.evaluate( () => window.__cls );
	return { status: r?.status(), cls: cambios.reduce( ( a, e ) => a + e.v, 0 ), pie: cambios.filter( ( e ) => e.src.includes( 'FOOTER' ) ) };
}

/** Tras hidratar no queda ni reserva ni resumen oculto: nada de hueco muerto. */
async function hidratadoSinReserva( page ) {
	return page.evaluate( () => {
		const c = document.querySelector( '.wp-block-woocommerce-cart' );
		const s = document.querySelector( '.wc-block-cart__sidebar' );
		return {
			esqueleto: document.querySelectorAll( '.wc-block-cart-items .wc-block-components-skeleton__element' ).length,
			minHeight: getComputedStyle( c ).minHeight,
			vacio: !! document.querySelector( '.wp-block-woocommerce-empty-cart-block' ),
			resumen: s ? getComputedStyle( s ).visibility : null,
		};
	} );
}

test.describe( 'carrito estable al hidratar', () => {
	// «Carrito lleno» agrega un tomo y no lo quitaba: su sesión de invitado quedaba
	// en el espejo, una por proyecto y corrida (medido el 2026-09-25). Se borra al
	// terminar, pase o falle.
	borrarSesionWcAlTerminar();

	test( 'carrito vacío: el pie no entra y sale (CLS < 0,1)', async ( { page, context, browserName } ) => {
		test.skip( 'chromium' !== browserName, 'el estrangulado va por CDP' );
		test.setTimeout( 180_000 );
		await context.clearCookies();
		const m = await medirCarrito( page );
		expect( m.status ).toBe( 200 );
		expect( m.pie, 'el pie no puede moverse mientras se hidrata el carrito vacío' ).toEqual( [] );
		expect( m.cls ).toBeLessThan( 0.1 );
	} );

	test( 'carrito lleno: ni salta el pie ni queda hueco tras hidratar', async ( { page, context, browserName } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		test.skip( 'chromium' !== browserName, 'el estrangulado va por CDP' );
		test.setTimeout( 180_000 );
		await context.clearCookies();

		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.goto( String( url ), { waitUntil: 'domcontentloaded' } );
		const boton = page.locator( '.single_add_to_cart_button' ).first();
		await boton.click();
		await expect( boton ).toHaveClass( /is-added/, { timeout: 20_000 } );

		const m = await medirCarrito( page );
		expect( m.status ).toBe( 200 );
		expect( m.pie ).toEqual( [] );
		expect( m.cls, 'PC-3: el carrito lleno también saltaba (0,36-0,48)' ).toBeLessThan( 0.1 );

		// La reserva vive SÓLO mientras dura el esqueleto: el carrito lleno ya
		// hidratado no puede heredar los 86rem (el hueco de 739 px del 2026-07-29).
		const h = await hidratadoSinReserva( page );
		expect( h.esqueleto ).toBe( 0 );
		expect( h.vacio ).toBe( false );
		expect( h.minHeight ).not.toBe( '1376px' );
		expect( h.resumen, 'el resumen del pedido vuelve a verse al hidratar' ).toBe( 'visible' );
	} );
} );
