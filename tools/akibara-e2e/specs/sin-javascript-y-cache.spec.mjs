// @ts-check
/**
 * Lo que tiene que funcionar aunque el JavaScript no corra, y lo que el
 * JavaScript tiene que corregir sobre una página servida desde caché.
 *
 * Nace de la ronda 3 de QA (2026-09-19). Ninguna prueba unitaria renderiza una
 * página ni la sirve «como la sirve la caché»: aquí sí.
 *
 *  - R3-0d42d2e2: en el teléfono, sin JS, las columnas del pie quedaban
 *    plegadas para siempre (Devoluciones, FAQ, Contacto… inalcanzables).
 *  - R3-dc2c02a9: sin JS, el formulario de contacto mandaba nombre, correo y
 *    mensaje EN LA URL y el mensaje se perdía.
 *  - R3-2c3c4720: el contador del carrito de la cabecera sale del HTML, y el
 *    HTML sale de caché con el carrito de otro. Lo corrige `wc-cart-fragments`,
 *    que WooCommerce ya no encola solo: lo encola el tema.
 */
import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

const UA = 'AkibaraQA/e2e (+playwright)';

test.describe( 'sin JavaScript', () => {
	test( 'en el teléfono, los enlaces del pie se alcanzan', async ( { browser, baseURL } ) => {
		const contexto = await browser.newContext( { javaScriptEnabled: false, viewport: { width: 390, height: 844 }, userAgent: UA } );
		const page = await contexto.newPage();
		await page.goto( `${ baseURL }/contacto/`, { waitUntil: 'load' } );

		const enlaces = page.locator( '.site-footer .footer-column__list a' );
		expect( await enlaces.count(), 'el pie trae sus columnas' ).toBeGreaterThan( 3 );
		await expect( enlaces.first(), 'sin JS las columnas quedan ABIERTAS' ).toBeVisible();
		// El botón no puede decir «plegado» de un panel que está a la vista.
		await expect( page.locator( '.footer-column__toggle' ).first() ).toHaveAttribute( 'aria-expanded', 'true' );
		await contexto.close();
	} );

	test( 'el formulario de contacto no manda los datos por la URL', async ( { browser, baseURL } ) => {
		const contexto = await browser.newContext( { javaScriptEnabled: false, userAgent: UA } );
		const page = await contexto.newPage();
		await page.goto( `${ baseURL }/contacto/`, { waitUntil: 'load' } );

		const form = page.locator( 'form[data-akb-form="contacto"]' );
		await expect( form ).toHaveAttribute( 'method', 'post' );
		expect( await form.getAttribute( 'action' ), 'va al manejador del servidor' ).toContain( '/wp-admin/admin-post.php' );
		await expect( form.locator( 'input[name="action"]' ) ).toHaveValue( 'akb_contacto' );
		// Sin JS manda la validación nativa: `novalidate` lo pone el script.
		expect( await form.getAttribute( 'novalidate' ) ).toBeNull();
		await contexto.close();
	} );

	test( 'el formulario de contacto se envía y vuelve con «Recibido»', async ( { browser, baseURL } ) => {
		test.skip( soloLectura, 'envía un correo: sólo contra el espejo (Mailpit)' );

		const contexto = await browser.newContext( { javaScriptEnabled: false, userAgent: UA } );
		const page = await contexto.newPage();
		await page.goto( `${ baseURL }/contacto/`, { waitUntil: 'load' } );
		await page.fill( '#akb-c-nombre', 'Prueba e2e sin JS' );
		await page.fill( '#akb-c-email', 'ale.fvaras@gmail.com' );
		await page.fill( '#akb-c-mensaje', 'Mensaje de la prueba e2e sin JavaScript.' );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'load' } ),
			page.click( 'form[data-akb-form="contacto"] button[type="submit"]' ),
		] );

		const url = new URL( page.url() );
		expect( url.pathname ).toBe( '/contacto/' );
		expect( [ 'ok', 'error' ], 'vuelve con un estado, nunca en blanco' ).toContain( url.searchParams.get( 'akb_contacto' ) );
		for ( const dato of [ 'nombre', 'email', 'mensaje' ] ) {
			expect( url.searchParams.has( dato ), `«${ dato }» no viaja en la URL` ).toBe( false );
		}
		// `error` sólo es legítimo por el límite de envíos por hora de la propia IP.
		if ( 'ok' === url.searchParams.get( 'akb_contacto' ) ) {
			await expect( page.locator( '.akb-form__feedback' ).first() ).toContainText( 'Recibido' );
		}
		await contexto.close();
	} );
} );

test.describe( 'página servida desde caché', () => {
	test( 'el contador del carrito se corrige aunque el HTML traiga el de otro', async ( { browser, baseURL, isMobile } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		test.skip( isMobile, 'el mecanismo no depende del tamaño de pantalla' );

		const contexto = await browser.newContext( { storageState: { cookies: [], origins: [] }, userAgent: UA } );
		const page = await contexto.newPage();
		await page.goto( `${ baseURL }/tienda/`, { waitUntil: 'load' } );
		const id = await page.evaluate( () => document.querySelector( '[data-product_id]' )?.getAttribute( 'data-product_id' ) );
		test.skip( ! id, 'sin productos en este entorno' );
		await page.evaluate( async ( producto ) => {
			const cuerpo = new URLSearchParams( { product_id: String( producto ), quantity: '1' } );
			await fetch( '/?wc-ajax=add_to_cart', { method: 'POST', body: cuerpo, credentials: 'same-origin' } );
		}, id );

		for ( const ruta of [ '/contacto/', '/faq/' ] ) {
			// El espejo no tiene caché de página: se sirve el HTML tal como lo
			// entregaría la copia pública, con el carrito de otro visitante (0).
			await page.route( `${ baseURL }${ ruta }`, async ( route ) => {
				const respuesta = await route.fetch();
				const html = ( await respuesta.text() ).replace( /data-akb-cart-count="\d+"/g, 'data-akb-cart-count="0"' );
				await route.fulfill( { response: respuesta, body: html } );
			} );
			await page.goto( `${ baseURL }${ ruta }`, { waitUntil: 'load' } );
			await expect
				.poll( () => page.evaluate( () => document.querySelector( '[data-akb-cart-count]' )?.getAttribute( 'data-akb-cart-count' ) ), { timeout: 8_000, message: `${ ruta }: el contador vuelve al carrito real` } )
				.toBe( '1' );
			await page.unroute( `${ baseURL }${ ruta }` );
		}
		await contexto.close();
	} );

	test( 'toda página cacheable carga el script que corrige el contador', { tag: '@prod' }, async ( { page, baseURL } ) => {
		for ( const ruta of [ '/', '/tienda/', '/contacto/', '/faq/' ] ) {
			await page.goto( `${ baseURL }${ ruta }`, { waitUntil: 'domcontentloaded' } );
			// Por `id`, no por `src`: LiteSpeed minifica y reescribe la URL del
			// script a `/wp-content/litespeed/js/<hash>.js`, sin el nombre.
			const cargado = await page.evaluate( () => null !== document.getElementById( 'wc-cart-fragments-js' ) );
			expect( cargado, `${ ruta } carga wc-cart-fragments` ).toBe( true );
		}
	} );
} );

test.describe( 'contenido protegido', () => {
	test( 'un 404 pedido en Markdown apunta a direcciones de ESTE sitio', async ( { request, baseURL } ) => {
		const respuesta = await request.get( `${ baseURL }/no-existe-prueba-e2e/`, { headers: { Accept: 'text/markdown', 'User-Agent': UA } } );
		expect( respuesta.status() ).toBe( 404 );
		const cuerpo = await respuesta.text();
		const origen = new URL( String( baseURL ) ).origin;
		expect( cuerpo, 'el directorio de series sale resuelto, no escrito a mano' ).toContain( `${ origen }/series/` );
		expect( cuerpo ).toContain( `${ origen }/llms.txt` );
	} );
} );
