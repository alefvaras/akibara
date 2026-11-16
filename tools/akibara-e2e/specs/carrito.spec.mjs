// @ts-check
/**
 * Carrito y pago: el camino del dinero.
 *
 * Todo lo que escribe (agregar, cupones) se salta contra producción: ahí sólo
 * se mira. Contra el espejo se ejercita entero, porque es donde los defectos
 * de este carril se han visto siempre.
 */

import { expect, test } from '@playwright/test';
import { productoConBadge, soloLectura } from './soporte/tienda.mjs';

test.describe( 'carrito', () => {
	// En serie: cada prueba monta su propio carrito y el espejo es lento. En
	// paralelo, cuatro navegadores agregando a la vez convertían un carrito
	// legítimo en un fallo sin causa —verde al correr el fichero solo, rojo en
	// la suite entera—, que es el tipo de rojo que hace desconfiar de todo lo
	// demás (medido el 2026-09-18).
	test.describe.configure( { mode: 'serial' } );


	test( 'el carrito vacío explica qué hacer, sin parecer un error', { tag: '@prod' }, async ( { page } ) => {
		const respuesta = await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );

		expect( respuesta?.status() ).toBe( 200 );
		await expect( page.locator( 'h1, .akb-empty__title' ).first() ).toBeVisible();
	} );

	test( 'agregar desde la ficha no recarga, y el botón acepta el clic siguiente', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();

		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.goto( String( url ), { waitUntil: 'domcontentloaded' } );

		const boton = page.locator( '.single_add_to_cart_button' ).first();
		await expect( boton ).toBeVisible();

		const urlAntes = page.url();
		await boton.click();

		// Hallazgo S-7295000a: tras el alta, `busy` bloqueaba el botón dos
		// segundos y el segundo clic era un no-op silencioso.
		await expect( boton ).toHaveClass( /is-added/, { timeout: 15_000 } );
		expect( page.url(), 'el alta es por AJAX: no puede recargar la ficha' ).toBe( urlAntes );
		await expect( boton ).toBeEnabled();

		// El cajón nativo se abre al agregar y tapa el botón: el cliente lo cierra
		// antes de volver a pulsar. Sin esto el segundo clic caía sobre el velo del
		// cajón y la prueba salía roja sólo bajo carga (medido el 2026-09-19 en la
		// corrida completa; sola pasaba).
		//
		// Se ESPERA al cajón en vez de mirar si ya está (lote T3, 2026-09-23):
		// desde que GSAP se carga diferido en la ficha, animations-loader.js
		// abre el cajón SIEMPRE, pero tras el vuelo, y si el clic llega antes
		// que GSAP espera hasta 800 ms a la librería. Con `isVisible()` la
		// prueba miraba antes de tiempo, no cerraba nada y el segundo clic caía
		// sobre el velo que se abría detrás (rojo medido con 3 workers).
		const cerrarCajon = page.locator( '.akb-cart-close' ).first();
		await expect( page.locator( '.akb-cart-drawer.akb-open' ) ).toBeVisible( { timeout: 10_000 } );
		await cerrarCajon.click();
		await expect( cerrarCajon ).toBeHidden( { timeout: 5_000 } );

		await boton.click();
		await expect( boton ).toHaveClass( /is-adding|is-added/, { timeout: 15_000 } );
	} );

	test( 'el carrito con un tomo muestra línea, subtotal y camino a pagar', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();

		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.goto( String( url ), { waitUntil: 'domcontentloaded' } );

		const boton = page.locator( '.single_add_to_cart_button' ).first();
		await boton.click();
		// Se espera la SEÑAL del propio botón, no un reloj: con un `waitForTimeout`
		// el carrito llegaba a veces vacío y el fallo no decía por qué.
		await expect( boton ).toHaveClass( /is-added/, { timeout: 20_000 } );

		await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );

		const cuerpo = await page.locator( 'body' ).innerText();
		expect( cuerpo ).toContain( '$' );

		// El botón de pagar lo pinta React al hidratar: el HTML servido no lo
		// trae, y sin esperar a que la red se calme el carrito se mira a medias
		// —con el producto ya en la tabla pero sin el bloque de totales—.
		await page.waitForLoadState( 'networkidle' );

		// Se afirma sobre el DESTINO, no sobre el rótulo ni sobre la clase: lo
		// que tiene que existir es un camino a pagar. El nombre accesible del
		// botón de WooCommerce no coincide con su texto visible —medido: cero
		// coincidencias por rol— y la clase cambia con cada versión suya.
		// Se exige que EXISTA, no que esté en pantalla: hay dos enlaces a
		// /checkout/ —el del cajón lateral, cerrado, y el del pie del carrito,
		// que entra al hidratar— y cuál de los dos considera «visible» el
		// navegador depende del scroll y de una animación. Lo que no puede
		// faltar nunca es el camino a pagar.
		await expect( page.locator( 'a[href*="/checkout/"]' ) ).not.toHaveCount( 0, { timeout: 20_000 } );
	} );

	test( 'el campo de cupón se ve entero al enfocarlo', async ( { page, context } ) => {
		test.skip( soloLectura, 'necesita un carrito con algo dentro' );
		await context.clearCookies();

		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.goto( String( url ), { waitUntil: 'domcontentloaded' } );

		const agregar = page.locator( '.single_add_to_cart_button' ).first();
		await agregar.click();
		await expect( agregar ).toHaveClass( /is-added/, { timeout: 20_000 } );
		await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );

		const acordeon = page.locator( '.wc-block-components-panel__button' ).first();
		test.skip( ! ( await acordeon.count() ), 'este carrito no ofrece cupón' );
		await acordeon.click();

		const campo = page.locator( '.wc-block-components-totals-coupon input[type="text"]' ).first();
		await expect( campo ).toBeVisible();
		await campo.focus();

		// El defecto que esto fija: con el foco puesto, el anillo y el propio
		// campo se salían de su caja y se veían cortados.
		const caja = await campo.boundingBox();
		const panel = await page.locator( '.wc-block-components-totals-coupon' ).first().boundingBox();
		expect( caja, 'el campo tiene que existir y ser visible' ).not.toBeNull();
		expect( panel ).not.toBeNull();
		if ( caja && panel ) {
			expect( caja.height, 'un campo de 0 px de alto es un campo cortado' ).toBeGreaterThan( 20 );
			expect( caja.y + caja.height ).toBeLessThanOrEqual( panel.y + panel.height + 2 );
		}
	} );
} );
