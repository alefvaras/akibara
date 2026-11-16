// @ts-check
/**
 * Seguimiento del lote de mejoras del tema desplegado el 2026-09-26 (lote S-T).
 *
 * Cada prueba fija un hallazgo medido ANTES del arreglo:
 *
 *  - PROD-COMP-2 (verificación de producción): por encima de 768 px el small de
 *    los sellos de la portada medía 11,48 px, en Chromium y WebKit. UM-6 sólo había
 *    subido el móvil, y tema-sistema-de-diseno sólo lo mide allí.
 *  - VERIF-Z-2 (verificación previa al despliegue): con la vista rápida abierta a
 *    ≥ 769 px y la ventana pasando por debajo antes de que falle la red (girar la
 *    tableta o el teléfono, media pantalla), el aviso «No se pudo conectar» quedaba
 *    en x −199…−3: 0 px a la vista.
 *  - VERIF-Z-1 (ídem, sólo WebKit): detenida la carga con el POST de la ficha de
 *    camino, WebKit deja de avisar a los oyentes registrados antes del stop; el
 *    formulario volvía al vencer el plazo, pero sin nadie que lo pusiera inerte, y
 *    el reintento en doble toque mandaba dos altas (9 de 9). En Chromium el oyente
 *    sigue vivo: ahí la prueba guarda que el arreglo no rompa nada; el rojo se ve
 *    corriéndola en WebKit.
 *  - REV-ST1o-1 (revisión del lote, corrección 1): con los 12 px de PROD-COMP-2, «1 punto
 *    = $1 en tu próxima compra» pasaba a dos líneas entre 1076 y 1109 px (Chromium) y
 *    1090 y 1123 (WebKit), con «compra» sola: la tira crecía de 85 a 104 px. Ningún
 *    ancho medido caía en esa franja.
 *  - REV-ST-1f-D1 (ídem): en el flujo (< 769 px), el aviso de la vista rápida quedaba a
 *    2-3 px de «Ver ficha completa».
 *
 * Lo que intenta escribir (el alta al carrito) se intercepta y nunca llega al
 * servidor, y aun así sólo corre contra el espejo: si la intercepción fallara,
 * agregaría al carrito. La sesión de WooCommerce se borra al terminar.
 */

import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';
import { borrarSesionWcAlTerminar } from './soporte/tema-espejo.mjs';

const FICHA = '/jujutsu-kaisen-24-panini-argentina/';

/**
 * Anchos a medir en cada proyecto. En escritorio, desde 800: WebKit resta su barra
 * de desplazamiento (14 px) y a 769 ya aplica las reglas de ≤ 768. 1100 cae en la
 * franja de REV-ST1o-1.
 */
const ANCHOS = { escritorio: [ 800, 1024, 1100, 1440, 1920 ], movil: [ 360, 390, 768 ] };

/**
 * La franja de REV-ST1o-1, desde donde el small ya cabía en una línea con los 11,48 px
 * en los dos motores (1076 en Chromium, 1090 en WebKit) hasta pasado el corte de la
 * regla que le da ancho (1140).
 */
const FRANJA = [ 1090, 1100, 1110, 1120, 1140, 1141 ];

/**
 * En la página: alto de la tira de sellos y las líneas (strong o small) que se parten,
 * palabra a palabra por dónde cae cada una. null si la portada no trae sellos.
 */
const medirTira = () => {
	const tira = document.querySelector( '.akb-trust-section' );
	if ( ! tira ) {
		return null;
	}
	const r = document.createRange();
	const partidas = [];
	for ( const el of tira.querySelectorAll( '.akb-trust-item__label strong, .akb-trust-item__label small' ) ) {
		const t = el.firstChild;
		if ( ! t || 3 !== t.nodeType ) {
			continue;
		}
		/** @type {number[]} */
		const tops = [];
		/** @type {string[][]} */
		const lineas = [];
		for ( const p of /** @type {Text} */ ( t ).data.matchAll( /\S+/g ) ) {
			r.setStart( t, /** @type {number} */ ( p.index ) );
			r.setEnd( t, /** @type {number} */ ( p.index ) + p[ 0 ].length );
			const top = r.getClientRects()[ 0 ].top;
			let i = tops.findIndex( ( y ) => Math.abs( y - top ) < 4 );
			if ( i < 0 ) {
				tops.push( top );
				lineas.push( [] );
				i = lineas.length - 1;
			}
			lineas[ i ].push( p[ 0 ] );
		}
		if ( lineas.length > 1 ) {
			partidas.push( lineas.map( ( l ) => l.join( ' ' ) ) );
		}
	}
	return { alto: Math.round( tira.getBoundingClientRect().height * 10 ) / 10, partidas };
};

test.describe( 'PROD-COMP-2: los sellos de la portada se leen a 12 px en todo ancho', () => {
	test( 'las dos líneas de cada sello miden ≥ 12 px, también por encima de 768 px', async ( { page }, info ) => {
		expect( ( await page.goto( '/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const alto = page.viewportSize()?.height || 900;
		for ( const ancho of ANCHOS[ /** @type {'escritorio'|'movil'} */ ( info.project.name ) ] || [ 1440 ] ) {
			await page.setViewportSize( { width: ancho, height: alto } );
			await page.waitForTimeout( 150 );
			const sellos = await page.evaluate( () => [ ...document.querySelectorAll( '.akb-trust-item' ) ].map( ( e ) => ( {
				texto: ( /** @type {HTMLElement} */ ( e ) ).innerText.replace( /\s+/g, ' ' ).trim(),
				letras: [ ...e.querySelectorAll( '.akb-trust-item__label strong, .akb-trust-item__label small' ) ].map( ( x ) => ( {
					linea: x.tagName.toLowerCase(),
					px: parseFloat( getComputedStyle( x ).fontSize ),
				} ) ),
			} ) ) );
			test.skip( ! sellos.length, 'la portada de este entorno no trae sellos' );
			for ( const s of sellos ) {
				expect( s.letras.map( ( l ) => l.linea ).sort(), `${ ancho } px, «${ s.texto }»: sin sus dos líneas` ).toEqual( [ 'small', 'strong' ] );
				for ( const l of s.letras ) {
					expect( l.px, `${ ancho } px, «${ s.texto }», ${ l.linea }: ${ l.px } px (antes 11,48 en escritorio)` ).toBeGreaterThanOrEqual( 12 );
				}
			}
		}
	} );
} );

test.describe( 'REV-ST1o-1: la tira de sellos no crece con los 12 px', () => {
	test( 'en la franja de 1090 a 1141 px cada línea de cada sello cabe en una y la tira mide lo mismo que a 1440', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'la franja es de escritorio' );
		expect( ( await page.goto( '/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await page.evaluate( () => document.fonts.ready );
		const alto = page.viewportSize()?.height || 900;
		await page.setViewportSize( { width: 1440, height: alto } );
		await page.waitForTimeout( 150 );
		const ref = await page.evaluate( medirTira );
		test.skip( ! ref, 'la portada de este entorno no trae sellos' );
		expect( ref?.partidas, 'la premisa: a 1440 px cada línea de cada sello cabe en una' ).toEqual( [] );
		for ( const ancho of FRANJA ) {
			await page.setViewportSize( { width: ancho, height: alto } );
			await page.waitForTimeout( 150 );
			const m = /** @type {{alto:number,partidas:string[][]}} */ ( await page.evaluate( medirTira ) );
			expect( m.partidas, `${ ancho } px: se parten (antes del arreglo, «1 punto = $1 en tu próxima» / «compra»)` ).toEqual( [] );
			expect( m.alto, `${ ancho } px: la tira mide ${ m.alto } px y a 1440 ${ ref?.alto } (antes del arreglo, 104 contra 85)` ).toBe( ref?.alto );
		}
	} );

	test( 'donde una línea de un sello no cabe, se parte sin dejar una palabra sola', async ( { page }, info ) => {
		expect( ( await page.goto( '/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await page.evaluate( () => document.fonts.ready );
		const alto = page.viewportSize()?.height || 900;
		const [ desde, hasta, paso ] = 'movil' === info.project.name ? [ 320, 768, 8 ] : [ 769, 1140, 7 ];
		let revisadas = 0;
		for ( let ancho = desde; ancho <= hasta; ancho += paso ) {
			await page.setViewportSize( { width: ancho, height: alto } );
			await page.waitForTimeout( 60 );
			const m = await page.evaluate( medirTira );
			test.skip( ! m, 'la portada de este entorno no trae sellos' );
			// Con dos palabras la segunda va sola por fuerza («Atención» / «personalizada»).
			for ( const lineas of ( m?.partidas || [] ).filter( ( l ) => l.join( ' ' ).split( ' ' ).length > 2 ) ) {
				revisadas++;
				expect( lineas.at( -1 )?.split( ' ' ).length, `${ ancho } px: «${ lineas.join( ' / ' ) }» deja una palabra sola` ).toBeGreaterThan( 1 );
			}
		}
		expect( revisadas, 'en este recorrido alguna línea de más de dos palabras tiene que partirse, o la prueba no mide nada' ).toBeGreaterThan( 0 );
	} );
} );

test.describe( 'VERIF-Z-2: la vista rápida que cruza los 769 px', () => {
	borrarSesionWcAlTerminar();

	test( 'con la ventana ya por debajo de 769 px, el aviso de la red caída se ve junto al botón', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		const movil = 'movil' === info.project.name;
		// El teléfono en horizontal que gira a vertical; el escritorio a media pantalla.
		const [ abre, luego ] = movil
			? [ { width: 932, height: 430 }, { width: 430, height: 932 } ]
			: [ { width: 1440, height: 900 }, { width: 720, height: 900 } ];
		await page.setViewportSize( abre );
		let altas = 0;
		await page.route( /[?&]wc-ajax=add_to_cart\b/, async ( r ) => {
			altas++;
			await new Promise( ( fin ) => setTimeout( fin, 300 ) );
			await r.abort( 'failed' );
		} );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const tarjeta = page.locator( 'li.product:has(> .akb-qv-trigger)' ).first();
		await tarjeta.scrollIntoViewIfNeeded();
		const abrir = tarjeta.locator( '.akb-qv-trigger' );
		await expect( abrir, 'la vista rápida existe desde 769 px' ).toBeVisible();
		if ( movil ) {
			await abrir.tap();
		} else {
			await tarjeta.hover();
			await abrir.click();
		}
		const clon = page.locator( 'dialog[open] .akb-qv__actions .add_to_cart_button' );
		await expect( clon ).toBeVisible();

		await page.setViewportSize( luego );
		await page.waitForTimeout( 400 );
		await clon.scrollIntoViewIfNeeded();
		const caja = /** @type {{x:number,y:number,width:number,height:number}} */ ( await clon.boundingBox() );
		if ( movil ) {
			await page.touchscreen.tap( caja.x + caja.width / 2, caja.y + caja.height / 2 );
		} else {
			await page.mouse.click( caja.x + caja.width / 2, caja.y + caja.height / 2 );
			await page.mouse.move( 5, 5 );
		}
		const aviso = page.locator( 'dialog[open] .akb-qv__actions .akb-tarjeta-motivo:not([hidden])' );
		await expect( aviso ).toHaveText( /\S/ );
		expect( altas ).toBe( 1 );
		// Que esté en la pantalla a lo ancho no depende de dónde quedó deslizado el
		// diálogo; a lo alto sí, así que se lleva a la vista (antes, en x −199…−3, ni
		// deslizando se llegaba a él).
		await aviso.scrollIntoViewIfNeeded();
		const m = await page.evaluate( () => {
			const a = /** @type {HTMLElement} */ ( document.querySelector( 'dialog[open] .akb-qv__actions .akb-tarjeta-motivo:not([hidden])' ) );
			const b = /** @type {HTMLElement} */ ( document.querySelector( 'dialog[open] .akb-qv__actions .add_to_cart_button' ) );
			const r = a.getBoundingClientRect();
			const rb = b.getBoundingClientRect();
			const encima = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
			const enBoton = document.elementFromPoint( rb.left + rb.width / 2, rb.top + rb.height / 2 );
			const enlace = document.querySelector( 'dialog[open] .akb-qv__link' );
			return {
				hastaElEnlace: enlace ? Math.round( ( enlace.getBoundingClientRect().top - r.bottom ) * 10 ) / 10 : null,
				ancho: innerWidth,
				izquierda: Math.round( r.left ),
				derecha: Math.round( r.right ),
				centroEsAviso: !! encima && a.contains( encima ),
				botonTocable: !! enBoton && b.contains( enBoton ),
				debajoDelBoton: r.top >= rb.bottom - 1,
			};
		} );
		expect( m.izquierda, `el aviso empieza en x ${ m.izquierda } (antes −199): fuera de la pantalla` ).toBeGreaterThanOrEqual( 0 );
		expect( m.derecha, `el aviso termina en x ${ m.derecha }, la ventana mide ${ m.ancho }` ).toBeLessThanOrEqual( m.ancho );
		expect( m.centroEsAviso, 'algo tapa el aviso' ).toBe( true );
		expect( m.debajoDelBoton, 'sin la hoja de la vista rápida va en el flujo, debajo del botón' ).toBe( true );
		expect( m.botonTocable, 'el reintento sigue a mano' ).toBe( true );
		// REV-ST-1f-D1: el aire bajo las acciones es de quick-view.css, que aquí no se aplica.
		expect( m.hastaElEnlace, 'la vista rápida trae «Ver ficha completa»' ).not.toBeNull();
		expect( m.hastaElEnlace, `el aviso termina a ${ m.hastaElEnlace } px de «Ver ficha completa» (antes 2-3)` ).toBeGreaterThanOrEqual( 8 );
	} );
} );

test.describe( 'VERIF-Z-1: detenida la carga, la ficha vuelve protegida', () => {
	borrarSesionWcAlTerminar();

	/*
	 * Las condiciones del hallazgo, no otras: el toque llega con la página aún
	 * cargando (los guiones diferidos retenidos, así que pdp-ajax-atc.js no engancha
	 * y «Añadir» manda el formulario en un POST normal) y el cliente detiene la carga
	 * con ese POST de camino. Con la página ya cargada WebKit no pierde los oyentes y
	 * la prueba pasaría también sin el arreglo (medido: 4 de 4 en verde contra la
	 * instantánea).
	 */
	for ( const pausa of [ 150, 600 ] ) {
		test( `el reintento en doble toque (${ pausa } ms) tras detener la carga con el envío de camino manda UN alta`, async ( { page, browserName }, info ) => {
			test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
			test.slow();
			await page.clock.install();
			// Los <script> síncronos del <head> pasan; los diferidos, retenidos hasta el final.
			const html = await ( await page.request.get( FICHA ) ).text();
			const sincronos = new Set( [ ...html.split( '</head>' )[ 0 ].matchAll( /<script[^>]*\ssrc="([^"]+)"[^>]*>/g ) ]
				.filter( ( m ) => ! /\s(defer|async)[\s>=]/.test( m[ 0 ] ) )
				.map( ( m ) => m[ 1 ].replace( /&amp;/g, '&' ).split( '?' )[ 0 ] ) );
			let soltarGuiones = () => {};
			const guionesSueltos = new Promise( ( fin ) => { soltarGuiones = () => fin( null ); } );
			await page.route( /\.js(\?|$)/, async ( r ) => {
				if ( 'script' === r.request().resourceType() && ! sincronos.has( r.request().url().split( '?' )[ 0 ] ) ) {
					await guionesSueltos;
				}
				await r.fallback().catch( () => {} );
			} );
			/** @type {string[]} */
			const envios = [];
			let soltarPrimero = () => {};
			const primeroSuelto = new Promise( ( fin ) => { soltarPrimero = () => fin( null ); } );
			let soltarReintento = () => {};
			const reintentoSuelto = new Promise( ( fin ) => { soltarReintento = () => fin( null ); } );
			await page.route( ( url ) => url.pathname === FICHA, async ( r ) => {
				if ( 'POST' !== r.request().method() ) {
					await r.fallback();
					return;
				}
				envios.push( r.request().postData() || '' );
				if ( 1 === envios.length ) {
					// El que el cliente detiene: nunca llega.
					await primeroSuelto;
					await r.abort( 'aborted' ).catch( () => {} );
					return;
				}
				// Los del reintento, retenidos hasta después del doble toque: con la
				// respuesta ya en la página el segundo toque caería en otra y no
				// probaría nada.
				await reintentoSuelto;
				await r.fulfill( { contentType: 'text/html', body: '<!doctype html><title>e2e</title><p id="akb-e2e-post">enviado</p>' } ).catch( () => {} );
			} );
			try {
				expect( ( await page.goto( FICHA, { waitUntil: 'commit' } ) )?.status() ).toBe( 200 );
				const boton = page.locator( 'form.cart .single_add_to_cart_button' );
				await boton.waitFor( { state: 'visible', timeout: 15_000 } );
				await boton.scrollIntoViewIfNeeded();
				expect( await page.evaluate( () => document.readyState ), 'el toque tiene que llegar con la página cargando' ).toBe( 'loading' );
				const caja = /** @type {{x:number,y:number,width:number,height:number}} */ ( await boton.boundingBox() );
				const pulsar = async () => {
					if ( 'movil' === info.project.name ) {
						await page.touchscreen.tap( caja.x + caja.width / 2, caja.y + caja.height / 2 );
					} else {
						await page.mouse.click( caja.x + caja.width / 2, caja.y + caja.height / 2 );
					}
				};

				await pulsar();
				await expect.poll( () => envios.length ).toBe( 1 );
				await page.waitForTimeout( 400 );
				// Se detiene como lo hace el navegador: Page.stopLoading por CDP en
				// Chromium (lo mismo que Esc), window.stop() en WebKit.
				if ( 'chromium' === browserName ) {
					await ( await page.context().newCDPSession( page ) ).send( 'Page.stopLoading' );
				} else {
					await page.evaluate( () => window.stop() ).catch( () => {} );
				}
				soltarPrimero();
				await page.waitForTimeout( 500 );
				expect( new URL( page.url() ).pathname, 'la página se quedó: el cliente sigue en la ficha' ).toBe( FICHA );

				// Vence el plazo (30 s). Si el reloj de mentira no llegara a la página,
				// el de verdad la suelta igual.
				await page.clock.fastForward( 31_000 );
				const form = page.locator( 'form.cart' );
				await expect( form, 'el formulario vuelve al vencer el plazo' ).not.toHaveAttribute( 'inert', /.*/, { timeout: 35_000 } );
				expect( envios.length ).toBe( 1 );

				await pulsar();
				await page.waitForTimeout( pausa );
				await pulsar();
				// Con la máquina cargada el POST tarda en llegar a la intercepción: se
				// espera el primero y, después, lo bastante para que se viera un segundo.
				await expect.poll( () => envios.length, { message: 'el reintento no mandaba nada', timeout: 15_000 } ).toBeGreaterThan( 1 );
				await page.waitForTimeout( 1500 );
				expect( envios.length - 1, 'el segundo toque era la segunda alta (WebKit: 9 de 9, medido en el espejo)' ).toBe( 1 );
				expect( envios[ 1 ], 'el que sale es la compra' ).toMatch( /name="add-to-cart"/ );
			} finally {
				soltarPrimero();
				soltarReintento();
				soltarGuiones();
			}
			await expect( page.locator( '#akb-e2e-post' ), 'el reintento llega' ).toBeVisible( { timeout: 10_000 } );
		} );
	}
} );
