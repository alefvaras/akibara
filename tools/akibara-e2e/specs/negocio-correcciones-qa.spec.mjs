// @ts-check
/**
 * Correcciones de la QA integral del 2026-09-24 sobre akibara-business (lote
 * QF-business). Cada prueba nace de un hallazgo medido y falla sin su arreglo:
 *
 *  - QA-RV-2: en el teléfono los chips de disponibilidad del riel «Completa tu
 *    colección» compartían fila con «Elegir tomos» y el tercero no se veía (0 %
 *    a 320 px, 41 % a 390). Ahora van en su propia fila, y el chip que recibe el
 *    foco se ve entero aunque la lista se desplace (Chromium no la movía).
 *  - QA-MOV-3: las instrucciones de «Elegir tomos» mandaban a usar la vista
 *    rápida en el teléfono, donde no existe su botón.
 *  - QA-MOV-7: la tarjeta de la serie en una guía pintaba «A pedido 0 tomos».
 *  - QA-RV-7: la cinta de anuncios cortaba «… SOBRE $…» justo en el monto.
 *  - QA-RV-3: entre 901 y 1199 px la cabecera y el título del hero quedaban
 *    fuera de la franja del slide.
 *  - QA-MOV-1 = BIZ-QA-1: la página 2 de una serie que ya cabe en una llegaba con
 *    `fbclid`, `utm_*` o `srsltid` y caía en el 404.
 *  - BIZ-QA-5: el anillo de foco de «Ver ficha completa ↓» y «Último tomo» salía
 *    con un token que no existe (#ffd400) y no con el amarillo de la casa.
 *  - QA-RC-4: la hoja de la ficha de serie viajaba en la hoja global de fechas
 *    de entrega; ahora sólo la carga la landing.
 *
 * Nada de esto escribe en el sitio: corre también contra producción.
 */

import { expect, test } from '@playwright/test';

/** Serie con tres chips (Todos, Disponibles, A pedido): la del hallazgo. */
const SERIE = '/serie/blue-lock/';

/** Serie con guía de lectura y tomos en más de un estado. */
const SERIE_CON_ESTADOS = '/serie/hunter-x-hunter/';

/** Guía ligada a una serie con un solo estado con tomos (Berserk Master: 1 disponible). */
const GUIA = '/berserk-cuantos-tomos-tiene-y-que-edicion-comprar/';

/**
 * Porcentaje visible de cada chip dentro de su lista.
 *
 * @param {import('@playwright/test').Page} page
 */
function chipsVisibles( page ) {
	return page.evaluate( () => {
		const lista = /** @type {Element} */ ( document.querySelector( '.akb-sb-landing__filter-list' ) );
		const lr = lista.getBoundingClientRect();
		return [ ...lista.querySelectorAll( '.akb-sb-landing__filter' ) ].map( ( c ) => {
			const r = c.getBoundingClientRect();
			return Math.round( ( 100 * Math.max( 0, Math.min( r.right, lr.right ) - Math.max( r.left, lr.left ) ) ) / r.width );
		} );
	} );
}

test.describe( 'correcciones de la QA integral (negocio)', () => {
	test( 'en el teléfono los chips de disponibilidad se ven enteros en su propia fila', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'sólo el teléfono comparte fila con el botón' );
		const r = await page.goto( SERIE, { waitUntil: 'load' } );
		expect( r?.status() ).toBe( 200 );
		const oferta = page.locator( '#completar-coleccion' );
		test.skip( 0 === ( await oferta.count() ), 'el pack está apagado en este entorno' );

		const visibles = await chipsVisibles( page );
		expect( visibles.length, 'la serie tiene sus tres chips' ).toBeGreaterThanOrEqual( 3 );
		expect( visibles.slice( 0, 3 ), 'a 390 px los tres primeros chips se ven enteros' ).toEqual( [ 100, 100, 100 ] );

		// Los chips van en su fila, bajo el botón «Elegir tomos».
		const filas = await page.evaluate( () => ( {
			boton: /** @type {Element} */ ( document.querySelector( '.akb-sb-landing__cta' ) ).getBoundingClientRect().bottom,
			chips: /** @type {Element} */ ( document.querySelector( '.akb-sb-landing__filter-list' ) ).getBoundingClientRect().top,
		} ) );
		expect( filas.chips ).toBeGreaterThanOrEqual( filas.boton - 1 );
	} );

	test( 'a 320 px, el chip que recibe el foco del teclado se ve entero', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'la lista sólo se desplaza en el teléfono' );
		await page.setViewportSize( { width: 320, height: 780 } );
		await page.goto( SERIE, { waitUntil: 'load' } );
		test.skip( 0 === ( await page.locator( '#completar-coleccion' ).count() ), 'el pack está apagado en este entorno' );

		const antes = await chipsVisibles( page );
		test.skip( antes[ 2 ] >= 100, 'en este entorno los chips caben enteros: no hay nada que desplazar' );

		await page.locator( '.akb-sb-landing__filter' ).nth( 1 ).focus();
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '.akb-sb-landing__filter' ).nth( 2 ) ).toBeFocused();
		await expect.poll( async () => ( await chipsVisibles( page ) )[ 2 ], { message: 'el chip enfocado entra entero en la lista' } ).toBe( 100 );
	} );

	test( 'las instrucciones de «Elegir tomos» sólo nombran la vista rápida donde existe su botón', async ( { page, isMobile } ) => {
		await page.goto( SERIE_CON_ESTADOS, { waitUntil: 'load' } );
		test.skip( 0 === ( await page.locator( '#completar-coleccion' ).count() ), 'el pack está apagado en este entorno' );
		await page.locator( '[data-akb-sb-landing-start]' ).click();
		const instrucciones = page.locator( '.akb-sb-landing__instructions' );
		await expect( instrucciones ).toBeVisible();

		const botonesVisibles = await page.locator( '.akb-qv-trigger' ).evaluateAll( ( els ) => els.filter( ( e ) => e.getBoundingClientRect().width > 0 ).length );
		if ( isMobile ) {
			expect( botonesVisibles, 'en el teléfono no hay botón de vista rápida' ).toBe( 0 );
			// Lo que se VE (innerText): la frase sigue en el HTML, oculta por la hoja.
			await expect( instrucciones ).not.toContainText( /vista r[aá]pida/i, { useInnerText: true } );
		} else {
			expect( botonesVisibles ).toBeGreaterThan( 0 );
			const rotulo = ( await page.locator( '.akb-qv-trigger' ).first().innerText() ).trim();
			await expect( instrucciones ).toContainText( `Usa «${ rotulo }» para ver sus detalles.`, { ignoreCase: true, useInnerText: true } );
		}
		await expect( instrucciones ).toContainText( 'Tu selección se conserva al cambiar de página.' );
	} );

	test( 'la tarjeta de la serie en una guía no pinta estados en cero', async ( { page } ) => {
		const r = await page.goto( GUIA, { waitUntil: 'load' } );
		expect( r?.status() ).toBe( 200 );
		const filas = await page.locator( '.akb-guide-series__stock li' ).allInnerTexts();
		test.skip( 0 === filas.length, 'esta guía no pinta conteos en este entorno' );
		for ( const fila of filas ) {
			expect( fila, 'ningún «0 tomos»' ).not.toMatch( /(^|\s)0 tomos/ );
		}
	} );

	test( 'la cinta de anuncios del teléfono no corta el mensaje: hasta dos renglones, sin crecer', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'en escritorio el mensaje cabe en un renglón' );
		await page.setViewportSize( { width: 360, height: 780 } );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const barra = page.locator( '#akb-ann-bar' );
		test.skip( 0 === ( await barra.count() ), 'la barra está apagada en este entorno' );

		const medida = await page.evaluate( () => {
			const bar = /** @type {HTMLElement} */ ( document.getElementById( 'akb-ann-bar' ) );
			return {
				alto: Math.round( bar.getBoundingClientRect().height ),
				mensajes: [ ...bar.querySelectorAll( '.akb-ann-msg-inner' ) ].map( ( s ) => ( {
					texto: ( s.textContent || '' ).trim(),
					corta: s.scrollHeight > s.clientHeight + 1 || s.scrollWidth > s.clientWidth + 1,
					renglones: Math.round( s.getBoundingClientRect().height / parseFloat( getComputedStyle( s ).lineHeight ) ),
				} ) ),
			};
		} );
		expect( medida.alto, 'la barra mide lo mismo que con un renglón' ).toBe( 44 );
		for ( const m of medida.mensajes.filter( ( x ) => x.texto.length <= 80 ) ) {
			expect( m.corta, `«${ m.texto }» se lee entero` ).toBe( false );
			expect( m.renglones ).toBeLessThanOrEqual( 2 );
		}
	} );

	for ( const [ ancho, alto ] of [ [ 1024, 768 ], [ 1100, 800 ], [ 901, 700 ] ] ) {
		test( `a ${ ancho }x${ alto } la cabecera, el título y el botón del hero caben en su slide`, async ( { page, isMobile } ) => {
			test.skip( isMobile, 'banda de tableta horizontal y portátil pequeño' );
			await page.setViewportSize( { width: ancho, height: alto } );
			await page.goto( '/', { waitUntil: 'load' } );
			const hero = page.locator( '.akb-hero__slide' ).first();
			test.skip( 0 === ( await hero.count() ), 'sin hero en este entorno' );

			const m = await page.evaluate( () => {
				const track = /** @type {Element} */ ( document.querySelector( '.akb-hero__track' ) ).getBoundingClientRect();
				const slide = /** @type {Element} */ ( document.querySelector( '.akb-hero__slide' ) );
				const caja = ( s ) => { const e = slide.querySelector( s ); return e ? e.getBoundingClientRect() : null; };
				const dentro = ( r ) => ! r || ( r.top >= track.top - 0.5 && r.bottom <= track.bottom + 0.5 );
				return { cabecera: dentro( caja( '.akb-hero__eyebrow' ) ), titulo: dentro( caja( '.akb-hero__title' ) ), botones: dentro( caja( '.akb-hero__actions' ) ) };
			} );
			expect( m ).toEqual( { cabecera: true, titulo: true, botones: true } );
		} );
	}

	test( 'la página de serie que ya no existe redirige también con parámetros de seguimiento, y los conserva', async ( { request } ) => {
		const base = '/serie/jujutsu-kaisen/page/9/';
		const limpia = await request.get( base, { maxRedirects: 0 } );
		test.skip( 301 !== limpia.status(), 'en este entorno esa página existe o no redirige' );
		const destino = String( limpia.headers().location || '' );

		for ( const consulta of [ 'fbclid=IwAR123', 'srsltid=AfmBOoqTest', 'gclid=abc', 'utm_source=instagram&utm_medium=social' ] ) {
			const r = await request.get( `${ base }?${ consulta }`, { maxRedirects: 0 } );
			expect( r.status(), consulta ).toBe( 301 );
			const location = String( r.headers().location || '' );
			expect( location, 'va a la misma página que sin parámetros' ).toContain( new URL( destino, 'http://x' ).pathname );
			expect( location, 'la atribución viaja' ).toContain( consulta );
			expect( location, 'una cabecera, no HTML' ).not.toContain( '&#038;' );
		}

		const faceta = await request.get( `${ base }?_disponibilidad=instock&utm_source=x`, { maxRedirects: 0 } );
		expect( faceta.status(), 'una faceta sigue en 404' ).toBe( 404 );
	} );

	test( 'el anillo de foco de «Ver ficha completa» y de «Último tomo» es el blanco editorial de la casa (DS-5)', async ( { page } ) => {
		await page.goto( SERIE_CON_ESTADOS, { waitUntil: 'load' } );
		for ( const selector of [ '.akb-series-summary__more', 'a.akb-series-latest' ] ) {
			const enlace = page.locator( selector ).first();
			test.skip( 0 === ( await enlace.count() ), `${ selector } no sale en este entorno` );
			// Foco de teclado de verdad: el anterior en el orden de tabulación y Tab.
			await enlace.evaluate( ( el ) => {
				const todos = [ ...document.querySelectorAll( 'a[href], button, input, select, [tabindex]' ) ].filter( ( e ) => /** @type {HTMLElement} */ ( e ).tabIndex >= 0 && /** @type {HTMLElement} */ ( e ).offsetParent );
				/** @type {HTMLElement} */ ( todos[ todos.indexOf( el ) - 1 ] ).focus();
			} );
			await page.keyboard.press( 'Tab' );
			await expect( enlace ).toBeFocused();
			await expect.poll( () => enlace.evaluate( ( e ) => getComputedStyle( e ).outlineColor ), { message: selector } ).toBe( 'rgb(255, 255, 255)' );
		}
	} );

	test( 'la hoja de la ficha de serie sólo la carga la landing', async ( { page } ) => {
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await expect( page.locator( 'link#akb-series-facts-css' ) ).toHaveCount( 0 );
		await page.goto( SERIE_CON_ESTADOS, { waitUntil: 'load' } );
		await expect( page.locator( 'link#akb-series-facts-css' ) ).toHaveCount( 1 );
		// Y la ficha sigue con su estilo: la línea superior que la separa de la rejilla.
		await expect.poll( () => page.locator( '.akb-series-facts' ).evaluate( ( e ) => getComputedStyle( e ).borderTopStyle ) ).toBe( 'solid' );
	} );
} );
