// @ts-check
/**
 * akibara-cart-checkout-blocks: correcciones de la QA integral del 2026-09-24,
 * medidas en un navegador de verdad.
 *
 * - Sellos de confianza del carrito y del checkout: iban en tres columnas fijas
 *   dentro de la barra lateral de Woo (233 a 458 px en tableta y escritorio).
 *   A 768 el texto salía en vertical, una letra por línea; a 1024 «cuota s»;
 *   hasta a 1440 «personalizad a». La rejilla ahora depende del ancho de su
 *   caja: ninguna palabra puede quedar más ancha que su sello.
 * - «Finalizar compra» en el carrito de escritorio: Woo 11 oculta su único
 *   contenedor (display: none) mientras el botón está bajo la ventana, y a
 *   1366x768 no existía ni para la vista ni para el Tab.
 * - Errores del checkout vacío: nombraban «ciudad» bajo «Comuna», «teléfono»
 *   bajo «Celular» y «rut» en minúscula.
 *
 * Todo escribe en el carrito: contra producción se salta entero.
 */

import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

/**
 * El primer producto simple, comprable y con stock que devuelve la Store API.
 *
 * @param {import('@playwright/test').APIRequestContext} request Cliente HTTP del contexto.
 * @returns {Promise<number|undefined>} ID.
 */
async function comprable( request ) {
	const r = await request.get( '/wp-json/wc/store/v1/products?per_page=20' );
	if ( ! r.ok() ) {
		return undefined;
	}
	const producto = ( await r.json() ).find( ( p ) => p.is_purchasable && p.is_in_stock && 'simple' === p.type );
	return producto?.id;
}

/**
 * Carrito con un producto, por la misma puerta que el botón de la tienda.
 *
 * @param {import('@playwright/test').Page}           page    Página.
 * @param {import('@playwright/test').BrowserContext} context Contexto.
 */
async function carritoConUnProducto( page, context ) {
	await context.clearCookies();
	const id = await comprable( page.request );
	test.skip( ! id, 'sin productos comprables en este entorno' );
	const r = await page.request.post( '/?wc-ajax=add_to_cart', { form: { product_id: String( id ), quantity: '1' } } );
	expect( r.ok(), `agregar ${ id }` ).toBeTruthy();
}

/**
 * Por cada sello: ancho de su texto y de su palabra más larga, ésta medida sin
 * cortes con la misma fuente. Si la palabra es más ancha que el texto, el
 * navegador la está partiendo.
 *
 * @param {import('@playwright/test').Page} page Página.
 * @returns {Promise<{texto: string, ancho: number, palabra: number}[]>} Medidas.
 */
function medirSellos( page ) {
	return page.locator( '.akb-blocks-trust__item span' ).evaluateAll( ( spans ) => spans.map( ( span ) => {
		const regla = document.createElement( 'span' );
		regla.style.cssText = `position:absolute;visibility:hidden;white-space:nowrap;font:${ getComputedStyle( span ).font }`;
		document.body.append( regla );
		const palabra = Math.max( ...span.textContent.trim().split( /\s+/ ).map( ( p ) => {
			regla.textContent = p;
			return regla.getBoundingClientRect().width;
		} ) );
		regla.remove();
		return { texto: span.textContent.trim(), ancho: span.getBoundingClientRect().width, palabra };
	} ) );
}

test.describe( 'bloques: correcciones de la QA integral', () => {
	test.describe.configure( { mode: 'serial' } );
	test.use( { contextOptions: { reducedMotion: 'reduce' } } );

	test( 'los sellos de confianza no parten palabras en ningún ancho de la barra lateral ni del checkout', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		test.skip( 'escritorio' !== test.info().project.name, 'fija su propio ancho de pantalla: con una vez basta' );
		await carritoConUnProducto( page, context );

		for ( const ruta of [ '/carrito/', '/checkout/' ] ) {
			const respuesta = await page.goto( ruta, { waitUntil: 'domcontentloaded' } );
			expect( respuesta?.status() ).toBe( 200 );
			await expect( page.locator( '.akb-blocks-trust__item' ).first() ).toBeVisible( { timeout: 20_000 } );
			await page.evaluate( () => document.fonts.ready );

			// 768 y 1024: donde la QA vio el texto en vertical y partido. 820,
			// 1280, 1366 y 1440: el resto de la barra lateral y del checkout.
			for ( const ancho of [ 390, 768, 820, 1024, 1280, 1366, 1440 ] ) {
				await page.setViewportSize( { width: ancho, height: 900 } );
				await page.evaluate( () => new Promise( ( listo ) => requestAnimationFrame( () => requestAnimationFrame( listo ) ) ) );

				const sellos = await medirSellos( page );
				expect( sellos.length, `${ ruta } a ${ ancho }: tres sellos` ).toBe( 3 );
				for ( const sello of sellos ) {
					expect( sello.ancho, `${ ruta } a ${ ancho }: «${ sello.texto }» tiene al menos 60 px` ).toBeGreaterThanOrEqual( 60 );
					expect( sello.ancho + 0.5, `${ ruta } a ${ ancho }: «${ sello.texto }» no parte su palabra más larga` ).toBeGreaterThanOrEqual( sello.palabra );
				}
				const desborde = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
				expect( desborde, `${ ruta } a ${ ancho }: sin scroll lateral` ).toBeLessThanOrEqual( 0 );
			}
		}
	} );

	test( 'en el carrito de escritorio «Finalizar compra» existe y se alcanza con Tab sin hacer scroll', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		test.skip( 'escritorio' !== test.info().project.name, 'fija su propio ancho de pantalla: con una vez basta' );
		await carritoConUnProducto( page, context );

		// 1280x720, 1366x768 y 1536x864: el botón quedaba bajo el pliegue y Woo
		// lo ocultaba. 1280x480: pantalla baja, el otro corte de Woo.
		for ( const [ ancho, alto ] of [ [ 1280, 720 ], [ 1366, 768 ], [ 1536, 864 ], [ 1280, 480 ] ] ) {
			await page.setViewportSize( { width: ancho, height: alto } );
			const respuesta = await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );
			expect( respuesta?.status() ).toBe( 200 );
			const boton = page.locator( '.wc-block-cart__submit-button' );
			await expect( boton, `${ ancho }x${ alto }: el botón existe` ).toHaveCount( 1, { timeout: 20_000 } );
			await expect( boton, `${ ancho }x${ alto }: tiene caja` ).toBeVisible();

			const estado = await page.evaluate( () => {
				const b = document.querySelector( '.wc-block-cart__submit-button' );
				const r = b.getBoundingClientRect();
				return { scrollY: window.scrollY, alto: r.height, fija: 'fixed' === getComputedStyle( b.closest( '.wc-block-cart__submit-container' ) ).position };
			} );
			expect( estado.scrollY, 'la página no se ha movido' ).toBe( 0 );
			expect( estado.alto ).toBeGreaterThan( 40 );
			expect( estado.fija, 'en escritorio el botón va en su sitio, no fijo' ).toBe( false );

			// Tab desde el control anterior del resumen llega al botón. Con la
			// tarjeta de puntos es «Crear mi cuenta»; sin ella, el último enlace o
			// botón del resumen antes de «Finalizar compra». La tarjeta se vuelve a
			// pintar cuando el carrito termina de cargar y reemplaza su nodo (el
			// foco caía al primer enlace de la página): se espera la red, se
			// comprueba el foco antes del Tab y, si la tarjeta se repintó en medio,
			// se repite.
			await page.waitForLoadState( 'networkidle' );
			await expect( async () => {
				const anterior = await page.evaluate( () => {
					document.querySelectorAll( '[data-akb-e2e-anterior]' ).forEach( ( el ) => el.removeAttribute( 'data-akb-e2e-anterior' ) );
					const boton = document.querySelector( '.wc-block-cart__submit-button' );
					const enfocables = [ ...document.querySelectorAll( '.wc-block-cart__sidebar a[href], .wc-block-cart__sidebar button:not([disabled]), .wc-block-cart__sidebar input:not([type=hidden])' ) ]
						.filter( ( el ) => el !== boton && ( el.compareDocumentPosition( boton ) & Node.DOCUMENT_POSITION_FOLLOWING ) && el.getClientRects().length );
					const ultimo = enfocables[ enfocables.length - 1 ];
					if ( ! ultimo ) {
						return null;
					}
					ultimo.setAttribute( 'data-akb-e2e-anterior', '' );
					return ultimo.textContent.trim().slice( 0, 40 );
				} );
				expect( anterior, 'hay un control antes del botón en el resumen' ).not.toBeNull();
				const origen = page.locator( '[data-akb-e2e-anterior]' );
				await origen.focus();
				await expect( origen ).toBeFocused( { timeout: 1_000 } );
				await page.keyboard.press( 'Tab' );
				const destino = await page.evaluate( () => {
					const a = document.activeElement;
					return a?.classList.contains( 'wc-block-cart__submit-button' )
						? 'Finalizar compra'
						: `${ a?.tagName }.${ a?.className } «${ ( a?.textContent || '' ).trim().slice( 0, 40 ) }»`;
				} );
				expect( destino, `${ ancho }x${ alto }: Tab desde «${ anterior }»` ).toBe( 'Finalizar compra' );
			} ).toPass( { timeout: 20_000 } );
		}
	} );

	test( 'de 783 a 1024 px la navegación inferior no desaparece; en el teléfono la barra fija la reemplaza como antes', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		test.skip( 'movil' !== test.info().project.name, 'la barra fija es de teléfono' );
		await carritoConUnProducto( page, context );

		const respuesta = await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status() ).toBe( 200 );
		const contenedor = page.locator( '.wc-block-cart__submit-container' );
		await expect( contenedor ).toHaveClass( /--sticky/, { timeout: 20_000 } );
		expect( await contenedor.evaluate( ( c ) => getComputedStyle( c ).position ), 'teléfono: barra fija' ).toBe( 'fixed' );
		await expect( page.locator( '.bottom-nav' ), 'teléfono: la barra reemplaza la navegación' ).toBeHidden();

		for ( const ancho of [ 900, 1024 ] ) {
			await page.setViewportSize( { width: ancho, height: 768 } );
			await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );
			await expect( contenedor ).toHaveClass( /--sticky/, { timeout: 20_000 } );
			expect( await contenedor.evaluate( ( c ) => getComputedStyle( c ).position ), `${ ancho }: en su sitio` ).toBe( 'static' );
			await expect( page.locator( '.bottom-nav' ), `${ ancho }: la navegación inferior sigue` ).toBeVisible();
		}
	} );

	test( 'los errores del checkout vacío nombran la etiqueta que se ve', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await carritoConUnProducto( page, context );

		const respuesta = await page.goto( '/checkout/', { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status() ).toBe( 200 );
		const pagar = page.locator( '.wc-block-components-checkout-place-order-button' );
		await expect( pagar ).toBeVisible( { timeout: 30_000 } );
		// Un Enter antes de que el checkout termine de hidratar se pierde. Con los
		// campos vacíos Woo no envía nada: repetirlo sólo vuelve a mostrar errores.
		await page.waitForLoadState( 'networkidle' );
		const errores = page.locator( '.wc-block-components-validation-error' );
		await expect( async () => {
			await pagar.focus();
			await page.keyboard.press( 'Enter' );
			await expect( errores.first() ).toBeVisible( { timeout: 3_000 } );
		} ).toPass( { timeout: 30_000 } );

		// Cada campo con error: su etiqueta visible tiene que estar en su mensaje.
		const campos = await page.locator( '.wc-block-checkout input[aria-invalid="true"]' ).evaluateAll( ( inputs ) => inputs.map( ( input ) => {
			const id = input.getAttribute( 'aria-errormessage' );
			return {
				etiqueta: input.labels?.[ 0 ]?.textContent.trim() || '',
				mensaje: ( id && document.getElementById( id )?.textContent.trim() ) || '',
			};
		} ) );
		const porEtiqueta = Object.fromEntries( campos.map( ( c ) => [ c.etiqueta, c.mensaje ] ) );

		expect( porEtiqueta.Comuna, 'Comuna' ).toBe( 'El campo comuna no es válido.' );
		expect( porEtiqueta.Celular, 'Celular' ).toBe( 'El campo celular no es válido.' );
		expect( porEtiqueta.RUT, 'RUT' ).toBe( 'El campo RUT no es válido.' );
		for ( const { etiqueta, mensaje } of campos ) {
			expect( mensaje, `«${ etiqueta }» tiene su error enlazado` ).not.toBe( '' );
			expect( mensaje.toLocaleLowerCase(), `el error de «${ etiqueta }» la nombra` ).toContain( etiqueta.toLocaleLowerCase() );
		}
		await expect( errores.filter( { hasText: /\bciudad\b|\btel[eé]fono\b/i } ), 'nada nombra un campo que no se ve' ).toHaveCount( 0 );
	} );
} );
