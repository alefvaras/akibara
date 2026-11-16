// @ts-check
/**
 * «Ver carrito» (UM-3) después de la revisión del lote de integración (I1).
 *
 * R-I1-3. En el teléfono, en el camino más común —agregar, cerrar el cajón y
 * volver a tocar—, el «Ver carrito» del botón real salía en el rojo oscuro de
 * comprar (el :hover pegado tras el toque le ganaba a la regla del estado) y
 * con la ✓ de WooCommerce (`.added::after`). Se prueba con `tap()`, que es lo
 * que deja el hover pegado; con `click()` no se ve.
 *
 * R-I1-1. Al quitar el tomo desde el cajón, los botones volvían a decir
 * «Añadir al carrito» pero seguían negros y secundarios hasta recargar.
 *
 * R-I1-4. En la tarjeta, el cambio a «Ver carrito» no se deshacía: quitado el
 * tomo, seguía llevando a un carrito que ya no lo tenía.
 *
 * R-I1-2. Con 2 en stock y 1 en el carrito, pedir 2 se rechaza, pero la segunda
 * cabe: la ficha pasaba a «Ver carrito» y, aunque se bajara la cantidad a 1, el
 * envío navegaba al carrito en vez de agregar.
 *
 * Escribe en el carrito de la sesión de la prueba: sólo contra el espejo, y la
 * sesión se borra al terminar (soporte/tema-espejo.mjs).
 */

import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';
import { borrarSesionWcAlTerminar } from './soporte/tema-espejo.mjs';

/** Rojo de comprar (--aki-buy-bg) y su hover (--aki-buy-bg-hover). */
const ROJOS = [ 'rgb(204, 0, 16)', 'rgb(153, 0, 12)' ];

/**
 * Un tomo simple, comprable, con exactamente `unidades` en stock gestionado.
 * Lo dice la Store API de WooCommerce: `add_to_cart.maximum` es el stock y
 * `low_stock_remaining` lo confirma. Se busca en vez de clavar un slug.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {number} unidades
 */
async function tomoConStock( request, unidades ) {
	const r = await request.get( '/wp-json/wc/store/v1/products?per_page=100&stock_status=instock' );
	if ( ! r.ok() ) {
		return null;
	}
	const lista = await r.json();
	const p = lista.find( ( x ) => 'simple' === x.type && x.is_purchasable && x.add_to_cart && unidades === x.add_to_cart.maximum && unidades === x.low_stock_remaining );
	return p ? { id: String( p.id ), ruta: new URL( p.permalink ).pathname } : null;
}

/** Toca en móvil (deja el :hover pegado, como un dedo) y hace clic en escritorio. */
function tocador( info ) {
	return ( l ) => ( 'movil' === info.project.name ? l.tap() : l.click() );
}

/** Espera el cajón abierto y lo cierra con su botón. */
async function cerrarCajon( page, tocar ) {
	const cajon = page.locator( '#akb-native-cart-drawer.akb-open' );
	await expect( cajon ).toHaveCount( 1 );
	await tocar( page.locator( '#akb-native-cart-drawer .akb-cart-close' ) );
	await expect( cajon ).toHaveCount( 0 );
	await page.waitForTimeout( 400 ); // el cierre anima; el overlay suelta los toques al terminar
}

/** Abre el cajón desde la cabecera, quita la primera línea y lo cierra. */
async function quitarDelCajon( page, tocar ) {
	await tocar( page.locator( '.akb-cart-trigger:visible' ).first() );
	const quitar = page.locator( '#akb-native-cart-drawer .akb-cart-item-remove' ).first();
	await expect( quitar ).toBeVisible();
	await tocar( quitar );
	await expect( page.locator( '#akb-native-cart-drawer .akb-cart-item-remove' ) ).toHaveCount( 0 );
	await cerrarCajon( page, tocar );
}

async function estadoDe( boton ) {
	return boton.evaluate( ( b ) => ( {
		clases: b.className,
		fondo: getComputedStyle( b ).backgroundColor,
		marca: getComputedStyle( b, '::after' ).content,
	} ) );
}

test.describe( '«Ver carrito» tras la revisión I1', () => {
	borrarSesionWcAlTerminar();

	test( 'ficha: «Ver carrito» secundario también con el dedo, y vuelve a comprar al quitar el tomo', async ( { page, context, request }, info ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const tomo = await tomoConStock( request, 1 );
		test.skip( ! tomo, 'no hay un tomo simple con una sola unidad en la Store API' );
		const tocar = tocador( info );

		await page.goto( tomo.ruta, { waitUntil: 'load' } );
		const real = page.locator( 'form.cart .single_add_to_cart_button' );
		const barra = page.locator( '#akb-sticky-atc-btn' );
		await real.scrollIntoViewIfNeeded();
		await tocar( real );
		await cerrarCajon( page, tocar );
		await tocar( real );

		await expect( real ).toHaveClass( /is-ver-carrito/, { timeout: 15_000 } );
		await expect( real ).toHaveText( /Ver carrito/i );
		const enVer = await estadoDe( real );
		expect( ROJOS, `R-I1-3: «Ver carrito» salía en el rojo de comprar (${ enVer.fondo })` ).not.toContain( enVer.fondo );
		expect( enVer.clases, 'R-I1-3: sin la clase `added` de WooCommerce' ).not.toMatch( /(^|\s)added(\s|$)/ );
		expect( enVer.marca, 'R-I1-3: sin la ✓ de WooCommerce en ::after' ).toBe( 'none' );

		await quitarDelCajon( page, tocar );
		// R-I1-1: los DOS botones vuelven a comprar, limpios y en rojo.
		for ( const [ nombre, boton ] of [ [ 'real', real ], [ 'barra', barra ] ] ) {
			await expect( boton, `R-I1-1: ${ nombre } sin «Ver carrito» pegado` ).not.toHaveClass( /is-ver-carrito/ );
			await expect( boton ).toHaveText( /Añadir|Reservar/i );
		}
		if ( 'escritorio' === info.project.name ) {
			await page.mouse.move( 1, 1 );
		}
		expect( ROJOS, 'R-I1-1: el botón real vuelve al rojo de comprar' ).toContain( ( await estadoDe( real ) ).fondo );

		// Y el siguiente toque agrega de verdad (POST), no navega al carrito.
		const alta = page.waitForRequest( ( r ) => r.url().includes( 'wc-ajax=add_to_cart' ) && 'POST' === r.method() );
		await tocar( real );
		await alta;
		await expect( real ).toHaveClass( /is-added/ );
		expect( ( await estadoDe( real ) ).clases, '«Agregado ✓» con una sola ✓' ).not.toMatch( /(^|\s)(added|is-ver-carrito)(\s|$)/ );
		expect( new URL( page.url() ).pathname ).toBe( tomo.ruta );
	} );

	test( 'tarjeta: al quitar el tomo, «Ver carrito» vuelve a ser el botón de agregar', async ( { page, context, request }, info ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const tocar = tocador( info );

		// Un tomo de una unidad visible en la primera página de /tienda/.
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const ids = await page.locator( 'li.product .add_to_cart_button.ajax_add_to_cart[data-product_id]' ).evaluateAll(
			( els ) => els.map( ( e ) => e.getAttribute( 'data-product_id' ) )
		);
		const lista = await ( await request.get( '/wp-json/wc/store/v1/products?per_page=100&stock_status=instock' ) ).json();
		const deUna = new Set( lista.filter( ( x ) => x.add_to_cart && 1 === x.add_to_cart.maximum && 1 === x.low_stock_remaining ).map( ( x ) => String( x.id ) ) );
		const id = ids.find( ( x ) => deUna.has( String( x ) ) );
		test.skip( ! id, 'no hay un tomo de una unidad en la primera página de /tienda/' );

		const tarjeta = page.locator( 'li.product', { has: page.locator( `[data-product_id="${ id }"]` ) } ).first();
		const ficha = await tarjeta.locator( 'a.woocommerce-LoopProduct-link' ).first().getAttribute( 'href' );
		const laTarjeta = page.locator( 'li.product', { has: page.locator( `a.woocommerce-LoopProduct-link[href="${ ficha }"]` ) } ).first();
		const boton = laTarjeta.locator( `.add_to_cart_button[data-product_id="${ id }"]` );
		await boton.scrollIntoViewIfNeeded();
		await tocar( boton );
		await cerrarCajon( page, tocar );
		await laTarjeta.scrollIntoViewIfNeeded();
		await tocar( boton );
		await expect( laTarjeta.locator( 'a.akb-ver-carrito' ), 'primero pasa a «Ver carrito»' ).toBeVisible( { timeout: 15_000 } );

		await quitarDelCajon( page, tocar );
		await expect( laTarjeta.locator( 'a.akb-ver-carrito' ), 'R-I1-4: el enlace se va' ).toHaveCount( 0 );
		await expect( boton, 'R-I1-4: vuelve el botón de agregar' ).toHaveCount( 1 );
		await expect( boton ).not.toHaveClass( /(^|\s)(added|loading)(\s|$)/ );

		// Y agrega: el clic delegado de WooCommerce funciona otra vez.
		const alta = page.waitForRequest( ( r ) => r.url().includes( 'wc-ajax=add_to_cart' ) && 'POST' === r.method() );
		await laTarjeta.scrollIntoViewIfNeeded();
		await tocar( boton );
		await alta;
		await expect( page.locator( '#akb-native-cart-drawer.akb-open' ), 'el alta abre el cajón' ).toHaveCount( 1 );
	} );

	test( 'ficha con 2 en stock y 1 en el carrito: pedir 2 no bloquea la segunda unidad', async ( { page, context, request }, info ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const tomo = await tomoConStock( request, 2 );
		test.skip( ! tomo, 'no hay un tomo simple con exactamente dos unidades en la Store API' );
		const tocar = tocador( info );

		await page.goto( tomo.ruta, { waitUntil: 'load' } );
		const real = page.locator( 'form.cart .single_add_to_cart_button' );
		const cantidad = page.locator( 'form.cart input.qty' );
		await real.scrollIntoViewIfNeeded();
		await tocar( real );
		await cerrarCajon( page, tocar );

		await cantidad.fill( '2' );
		await tocar( real );
		await expect( page.locator( 'form.cart .akb-atc-motivo' ), 'el motivo dice cuántas quedan' ).toBeVisible( { timeout: 15_000 } );
		await expect( real, 'R-I1-2: queda una unidad por agregar: no es «Ver carrito»' ).not.toHaveClass( /is-ver-carrito/ );

		await cantidad.fill( '1' );
		const alta = page.waitForRequest( ( r ) => r.url().includes( 'wc-ajax=add_to_cart' ) && 'POST' === r.method() );
		await tocar( real );
		await alta;
		await expect( real ).toHaveClass( /is-added/ );
		expect( new URL( page.url() ).pathname, 'no navega al carrito' ).toBe( tomo.ruta );
		const carrito = await page.evaluate( async () => ( await ( await fetch( '/?wc-ajax=akb_get_cart' ) ).json() ).data.items.map( ( i ) => [ String( i.id ), i.quantity ] ) );
		expect( carrito ).toContainEqual( [ tomo.id, 2 ] );
	} );
} );
