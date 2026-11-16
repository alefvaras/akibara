// @ts-check
/**
 * Rechazo al agregar: el cliente se queda donde estaba (UM-4 y UM-3, lote T2).
 *
 * UM-4. Desde una TARJETA, un tomo de una unidad que ya está en el carrito se
 * rechaza y WooCommerce (add-to-cart.js) hacía `window.location = product_url`:
 * el cliente salía del listado a la ficha (medido en el espejo en /serie/, móvil
 * y escritorio; en Clarity, 9 s de carga). atc-guard.js envuelve el `success`
 * de esa petición con un `$.ajaxPrefilter`. Este spec es el CANDADO de esa
 * dependencia: si WooCommerce cambia la forma de la llamada, aquí se ve.
 *
 * UM-3. Desde la BARRA de la ficha, el motivo se pintaba junto al botón real,
 * fuera de pantalla (-2.439 px). Ahora va dentro de la barra.
 *
 * R-T2-1 y R-T2-2 (revisión del lote T2). El botón de WhatsApp (Joinchat) caía
 * encima del motivo —«…tenemo[●]» en 390x844— y, con los dos botones en rojo,
 * al subir desde la barra hasta el botón real éste quedaba en «No se pudo
 * agregar» sin explicación (y al revés, la barra aparecía en rojo sin motivo).
 *
 * UM-3, segunda mitad (lote de integración, 2026-09-23). El servidor devuelve
 * además el CÓDIGO del rechazo (`motivo`), y con `ya_en_carrito` —el caso de
 * todas estas pruebas: un tomo de una unidad que ya está en el carrito— el
 * botón deja de ser «Añadir» y pasa a «Ver carrito»: en la tarjeta es un
 * enlace al carrito y en la ficha los DOS botones (el real y el de la barra)
 * llevan al carrito. Ya no quedan en rojo invitando a repetir el rechazo, y el
 * motivo va sin su propio «Ver carrito» (el botón ya lo es).
 *
 * Escribe en el carrito de la sesión de la prueba: sólo contra el espejo, y la
 * sesión se borra al terminar (soporte/tema-espejo.mjs).
 */

import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';
import { borrarSesionWcAlTerminar } from './soporte/tema-espejo.mjs';

/**
 * Un tomo de UNA unidad visible en /tienda/, ya puesto en el carrito: el
 * segundo alta se rechaza. Se busca en vez de clavar un slug.
 */
async function tomoDeUnaUnidadEnElCarrito( page ) {
	await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
	const ids = await page.locator( 'li.product .add_to_cart_button.ajax_add_to_cart[data-product_id]' ).evaluateAll(
		( els ) => els.map( ( e ) => e.getAttribute( 'data-product_id' ) )
	);
	for ( const id of ids.slice( 0, 16 ) ) {
		const primero = await ( await page.request.post( '/?wc-ajax=add_to_cart', { form: { product_id: String( id ), quantity: '1' } } ) ).json().catch( () => ( {} ) );
		if ( primero.error ) {
			continue;
		}
		const segundo = await ( await page.request.post( '/?wc-ajax=add_to_cart', { form: { product_id: String( id ), quantity: '1' } } ) ).json().catch( () => ( {} ) );
		// Los avisos del rechazo quedan en la sesión: se drenan para que la
		// prueba mida el SUYO y no éste.
		await page.request.get( '/?wc-ajax=akb_get_cart&drain_notices=1' ).catch( () => {} );
		if ( segundo.error ) {
			return String( id );
		}
	}
	return null;
}

/**
 * Joinchat sale a los 8 s de cargar. Se espera a que esté a la vista ANTES de
 * provocar el rechazo: el motivo dura 6 s y, si no, se mediría sin el botón.
 * Sin Joinchat en la página no hay nada que tapar.
 */
async function conWhatsAppALaVista( page ) {
	if ( ! ( await page.locator( '.joinchat' ).count() ) ) {
		return false;
	}
	await page.locator( '.joinchat.joinchat--show' ).waitFor( { state: 'attached', timeout: 15_000 } );
	return true;
}

/** Puntos de la caja del motivo que tienen encima algo de Joinchat que se ve. */
async function puntosTapadosPorWhatsApp( motivo ) {
	return motivo.evaluate( ( m ) => {
		const r = m.getBoundingClientRect();
		let tapados = 0;
		for ( let x = r.left + 2; x < r.right; x += 6 ) {
			for ( let y = r.top + 2; y < r.bottom; y += 4 ) {
				// `elementsFromPoint` sólo devuelve lo que recibe el toque: un
				// Joinchat retirado (visibility hidden, pointer-events none) no sale.
				if ( document.elementsFromPoint( x, y ).some( ( e ) => e.closest( '.joinchat' ) ) ) {
					tapados++;
				}
			}
		}
		return tapados;
	} );
}

test.describe( 'rechazo al agregar', () => {
	test.describe.configure( { mode: 'serial' } );
	borrarSesionWcAlTerminar();

	test( 'desde una tarjeta: no expulsa a la ficha y pinta el motivo en la tarjeta', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const id = await tomoDeUnaUnidadEnElCarrito( page );
		test.skip( ! id, 'no hay un tomo de una unidad en la primera página de /tienda/' );

		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const boton = page.locator( `li.product .add_to_cart_button[data-product_id="${ id }"]` ).first();
		test.skip( ! ( await boton.count() ), 'el tomo cambió de página mientras corría la prueba' );
		await boton.scrollIntoViewIfNeeded();
		// La cabecera se compacta al pasar de 120 px de scroll y el navegador
		// reajusta el scroll: se deja asentar antes de tomar la referencia.
		await page.waitForTimeout( 800 );
		const antes = page.url();
		const yAntes = await page.evaluate( () => scrollY );
		// La tarjeta se ubica por el enlace a su ficha: tras el rechazo el botón
		// deja de ser `.add_to_cart_button` (pasa a «Ver carrito», UM-3).
		const ficha = await page.locator( 'li.product', { has: page.locator( `.add_to_cart_button[data-product_id="${ id }"]` ) } ).first().locator( 'a.woocommerce-LoopProduct-link' ).first().getAttribute( 'href' );
		await boton.click();

		const tarjeta = page.locator( 'li.product', { has: page.locator( `a.woocommerce-LoopProduct-link[href="${ ficha }"]` ) } ).first();
		const motivo = tarjeta.locator( '.akb-tarjeta-motivo' );
		await expect( motivo, 'UM-4: el motivo se pinta en la tarjeta' ).toBeVisible( { timeout: 15_000 } );
		expect( page.url(), 'UM-4: el rechazo expulsaba del listado a la ficha' ).toBe( antes );
		expect( Math.abs( ( await page.evaluate( () => scrollY ) ) - yAntes ), 'y sin mover el scroll' ).toBeLessThan( 2 );
		// UM-3: con el código `ya_en_carrito` el botón de la tarjeta es ahora el
		// enlace al carrito; el motivo va sin el suyo.
		const verCarrito = tarjeta.locator( 'a.akb-ver-carrito' );
		await expect( verCarrito ).toHaveText( /Ver carrito/i );
		await expect( verCarrito ).toHaveAttribute( 'href', /carrito/ );
		await expect( verCarrito ).not.toHaveClass( /ajax_add_to_cart|loading/ );
		await expect( motivo.locator( 'a' ) ).toHaveCount( 0 );
		// El cajón del carrito NO se abre: no hubo alta.
		await expect( page.locator( '.akb-cart-drawer.akb-open, #akb-native-cart-drawer.akb-open' ) ).toHaveCount( 0 );
		// Y el enlace lleva al carrito, sin volver a intentar agregar.
		await Promise.all( [ page.waitForURL( /carrito/ ), verCarrito.click() ] );
	} );

	test( 'desde la barra de la ficha: el motivo se ve dentro de la barra', async ( { page, context }, info ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const id = await tomoDeUnaUnidadEnElCarrito( page );
		test.skip( ! id, 'no hay un tomo de una unidad en la primera página de /tienda/' );

		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
		const href = await page.locator( 'li.product', { has: page.locator( `.add_to_cart_button[data-product_id="${ id }"]` ) } )
			.first().locator( 'a.woocommerce-LoopProduct-link' ).getAttribute( 'href' );
		await page.goto( String( href ), { waitUntil: 'load' } );
		const hayWhatsApp = await conWhatsAppALaVista( page );

		// Bajar hasta tener la barra a la vista (el botón real fuera).
		const yForm = await page.evaluate( () => Math.round( document.querySelector( 'form.cart' ).getBoundingClientRect().bottom + scrollY ) );
		await page.evaluate( ( y ) => window.scrollTo( 0, y ), yForm + 200 );
		const barra = page.locator( '#akb-sticky-atc.is-visible' );
		await expect( barra ).toHaveCount( 1 );

		await page.locator( '#akb-sticky-atc-btn' ).click();
		const motivo = page.locator( '#akb-sticky-atc .akb-sticky-atc__motivo' );
		await expect( motivo, 'UM-3: el motivo tiene que verse dentro de la barra' ).toBeVisible( { timeout: 15_000 } );
		const caja = await motivo.boundingBox();
		const vista = page.viewportSize();
		expect( caja && vista && caja.y >= 0 && caja.y + caja.height <= vista.height, `en pantalla (${ info.project.name })` ).toBe( true );
		await expect( page.locator( 'form.cart .akb-atc-motivo:visible' ) ).toHaveCount( 0 );

		if ( hayWhatsApp ) {
			// R-T2-1: en 390x844 el botón de WhatsApp tapaba el final de la primera
			// línea; en escritorio, el extremo derecho de la franja del motivo.
			await expect.poll( () => puntosTapadosPorWhatsApp( motivo ), { message: 'R-T2-1: WhatsApp encima del motivo', timeout: 2_000 } ).toBe( 0 );
		}

		// R-T2-2: el cliente sube hasta el botón real antes de que el motivo se vaya.
		await page.evaluate( () => document.querySelector( 'form.cart' ).scrollIntoView( { block: 'center' } ) );
		await expect( page.locator( '#akb-sticky-atc.is-visible' ), 'la barra se retira con el form a la vista' ).toHaveCount( 0 );
		// Lectura en el acto, sin reintentos: un `not.toHaveClass` esperaría al
		// reinicio de los 6 s y pasaría por casualidad.
		const tras = await page.evaluate( () => {
			const real = document.querySelector( 'form.cart .single_add_to_cart_button' );
			const proxy = document.getElementById( 'akb-sticky-atc-btn' );
			return { realError: real.classList.contains( 'is-error' ), realTexto: real.textContent.trim(), realVer: real.classList.contains( 'is-ver-carrito' ), barraVer: proxy.classList.contains( 'is-ver-carrito' ), barraError: proxy.classList.contains( 'is-error' ) };
		} );
		// UM-3: el tomo ya está entero en el carrito → los dos botones llevan
		// al carrito. Ninguno en rojo, ninguno con «No se pudo agregar».
		expect( tras.barraVer, 'la barra pasó a «Ver carrito»' ).toBe( true );
		expect( tras.realVer, 'y el botón real también: los dos repetirían el rechazo' ).toBe( true );
		expect( tras.realError || tras.barraError, 'R-T2-2: ningún botón en rojo sin su motivo' ).toBe( false );
		expect( tras.realTexto ).toMatch( /Ver carrito/i );
		await Promise.all( [ page.waitForURL( /carrito/ ), page.locator( 'form.cart .single_add_to_cart_button' ).click() ] );
	} );

	test( 'desde el botón real: el motivo va junto a él y la barra no aparece en rojo sin motivo', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const id = await tomoDeUnaUnidadEnElCarrito( page );
		test.skip( ! id, 'no hay un tomo de una unidad en la primera página de /tienda/' );

		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
		const href = await page.locator( 'li.product', { has: page.locator( `.add_to_cart_button[data-product_id="${ id }"]` ) } )
			.first().locator( 'a.woocommerce-LoopProduct-link' ).getAttribute( 'href' );
		await page.goto( String( href ), { waitUntil: 'load' } );
		const hayWhatsApp = await conWhatsAppALaVista( page );

		const real = page.locator( 'form.cart .single_add_to_cart_button' );
		await page.evaluate( () => document.querySelector( 'form.cart' ).scrollIntoView( { block: 'center' } ) );
		await expect( page.locator( '#akb-sticky-atc.is-visible' ) ).toHaveCount( 0 );
		await real.click();

		const motivo = page.locator( 'form.cart .akb-atc-motivo' );
		await expect( motivo, 'el motivo junto al botón real' ).toBeVisible( { timeout: 15_000 } );
		// UM-3: `ya_en_carrito` → «Ver carrito», secundario y no rojo.
		await expect( real ).toHaveClass( /is-ver-carrito/ );
		await expect( real ).toHaveText( /Ver carrito/i );
		expect( await real.evaluate( ( b ) => getComputedStyle( b ).backgroundColor ), 'fuera del rojo de comprar' ).not.toMatch( /rgb\((2[0-9]{2}), 0, (1[0-9]|[0-9])\)/ );
		if ( hayWhatsApp ) {
			// R-T2-1: según el scroll, el botón de WhatsApp caía sobre «Ver carrito».
			// Mientras el motivo está a la vista, se retira sea cual sea la posición.
			await expect( page.locator( '.joinchat' ), 'R-T2-1: WhatsApp cede mientras se lee el motivo' ).toHaveCSS( 'visibility', 'hidden' );
		}

		// Baja: aparece la barra. Su botón no dice «No se pudo agregar» sin motivo.
		const yForm = await page.evaluate( () => Math.round( document.querySelector( 'form.cart' ).getBoundingClientRect().bottom + scrollY ) );
		await page.evaluate( ( y ) => window.scrollTo( 0, y ), yForm + 600 );
		await expect( page.locator( '#akb-sticky-atc.is-visible' ) ).toHaveCount( 1 );
		// Lectura en el acto (ver el caso de la barra).
		const tras = await page.evaluate( () => {
			const proxy = document.getElementById( 'akb-sticky-atc-btn' );
			const enBarra = document.querySelector( '#akb-sticky-atc .akb-sticky-atc__motivo' );
			return {
				realVer: document.querySelector( 'form.cart .single_add_to_cart_button' ).classList.contains( 'is-ver-carrito' ),
				proxyError: proxy.classList.contains( 'is-error' ),
				proxyTexto: proxy.textContent.trim(),
				motivoEnBarra: !! enBarra && ! enBarra.hidden,
			};
		} );
		expect( tras.realVer, 'el botón real sigue en «Ver carrito»' ).toBe( true );
		expect( tras.proxyError, 'R-T2-2: la barra no aparece en rojo sin motivo' ).toBe( false );
		expect( tras.proxyTexto, 'la barra dice lo mismo que el botón real' ).toMatch( /Ver carrito/i );
		expect( tras.motivoEnBarra ).toBe( false );
	} );
} );
