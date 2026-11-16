// @ts-check
/**
 * Correcciones de la QA integral del tema (lote QF-gp «correcciones-qa», 2026-09-24,
 * segunda tanda: lentes «cliente móvil» y «escritorio y teclado»).
 *
 * Cada prueba fija un hallazgo medido en el espejo ANTES del arreglo:
 *
 *  - QAM-1: al cerrar el ☰ (X, Escape o fondo) la página subía 389-422 px en
 *    Chromium; lo mismo al cerrar el cajón del carrito abierto desde la cabecera,
 *    con Escape en el mega-menú (−437) y con ⌘K (−978). El foco caía en la
 *    cabecera fija, dentro de la franja de `scroll-padding-top`.
 *  - QAM-2: con la red lenta, un toque en ☰, «Buscar» o «Añadir al carrito» de una
 *    tarjeta antes de que cargara el JS diferido se perdía sin respuesta.
 *  - QAM-3: agregar desde «Más vendidos» del carrito vacío recargaba /carrito/
 *    entero (cart.js del carrito clásico).
 *  - QAM-5: esos botones salían con un contorno que el paralelogramo recortaba.
 *  - QAM-8: con la red caída, la ficha decía «Reintentar» sin explicar nada a la vista.
 *  - QA-ESC-TECLADO-2: con «reducir movimiento» el mega-menú se cerraba al bajar
 *    el ratón hacia su panel (4 px de hueco). Y en línea recta del enlace de
 *    «Editoriales» a su primer enlace (RQF2-1): el panel está centrado y es más
 *    ancho que el ítem, el camino sale por el costado y pisa «A pedido».
 *  - QAM-2 / RQF2-2: sólo se guardaba el último toque, y «Añadir» en un tomo
 *    seguido de otro tomo (o de ☰) tiraba la primera compra.
 *  - QA-ESC-TECLADO-4: el primer filtro de /tienda/ estaba en la parada 117; y el
 *    salto nuevo llevaba a una barra vacía en la búsqueda sin resultados (QF-gp-R3).
 *  - QA-ESC-TECLADO-5: el Tab de la tarjeta subía al ojo y volvía a bajar.
 *  - QA-ESC-TECLADO-6: el buscador se anunciaba «Buscar productos» y se veía
 *    «Buscar manga, series o autor…».
 *  - QA-ESC-TECLADO-9: al 200 % (683x384) las barras fijas tapaban el 52 % de la ficha.
 *  - QA-ESC-TECLADO-11: la flecha de la paginación no tenía nombre; y al dárselo
 *    (QF-gp-R1) el <span> de la flecha heredó la caja entera: 100 px en vez de 72.
 *  - Ronda final (F-T): el gemelo de un tomo tocado antes del JS quedaba marcado
 *    para siempre (RQF3-1); de «Editoriales» a «Series» y abajo, el clic caía en el
 *    panel (RQF3-2); y el candado del teléfono apaisado (QAM-9).
 *  - Micro-ronda M-T: con el POST de la ficha de camino, si el cliente detenía la
 *    carga el formulario quedaba inerte hasta recargar (REV-MT2-D1).
 *  - Lote Z-T, revisión 1: un `navigateerror` ajeno al envío soltaba el formulario
 *    con el POST de camino y el doble toque mandaba dos altas (REV-ZT1o-1), y el
 *    plazo de 12 s también, con el servidor lento (REV-ZT1o-2).
 *
 * Lo que escribe (carrito) sólo corre contra el espejo, y la sesión de
 * WooCommerce se borra al terminar (soporte/tema-espejo.mjs). También lo que toca
 * el «Añadir» real con la respuesta interceptada: si la intercepción fallara, el
 * alta saldría (E2E-2, verificación de producción del 2026-09-26).
 */

import { expect, test } from '@playwright/test';
import { productoConBadge, soloLectura } from './soporte/tienda.mjs';
import { borrarSesionWcAlTerminar } from './soporte/tema-espejo.mjs';

const FICHA = '/jujutsu-kaisen-24-panini-argentina/';

/** Toca el centro de un elemento (sin el desplazamiento previo de `locator.tap()`). */
async function tocar( page, selector ) {
	const caja = await page.locator( selector ).first().boundingBox();
	expect( caja, `${ selector } no está en pantalla` ).toBeTruthy();
	await page.touchscreen.tap( caja.x + caja.width / 2, caja.y + caja.height / 2 );
}

const scrollY = ( page ) => page.evaluate( () => Math.round( window.scrollY ) );

/** Corre en la página (addInitScript): anota en la consola los cambios de `inert` de la ficha. */
function sondaDeLaFicha() {
	document.addEventListener( 'DOMContentLoaded', () => {
		const form = document.querySelector( 'form.cart' );
		if ( ! form ) {
			return;
		}
		const boton = form.querySelector( '.single_add_to_cart_button' );
		const barra = document.getElementById( 'akb-sticky-atc-btn' );
		new MutationObserver( () => {
			const inerte = form.hasAttribute( 'inert' );
			console.log( 'akb-e2e inert=' + inerte );
			if ( inerte && boton ) {
				setTimeout( () => {
					console.log( 'akb-e2e opacidad=' + getComputedStyle( boton ).opacity );
					if ( barra ) {
						console.log( 'akb-e2e opacidad-barra=' + getComputedStyle( barra ).opacity );
					}
				}, 600 );
			}
		} ).observe( form, { attributes: true, attributeFilter: [ 'inert' ] } );
	} );
}

test.describe( 'QAM-1: cerrar el menú o el cajón no mueve la página', () => {
	borrarSesionWcAlTerminar();

	test( '☰ por X, Escape y fondo, y el cajón del carrito desde la cabecera', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'el ☰ y el cajón desde la cabecera son del teléfono' );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await page.waitForTimeout( 1500 );
		for ( const [ abrir, cerrar ] of [
			[ '#menu-toggle', '#mobile-close' ],
			[ '#menu-toggle', 'Escape' ],
			[ '#menu-toggle', 'fondo' ],
			[ '.header-icon--cart', '.akb-cart-close' ],
			[ '.header-icon--cart', 'Escape' ],
		] ) {
			await page.evaluate( () => window.scrollTo( 0, 1800 ) );
			await page.waitForTimeout( 500 );
			const antes = await scrollY( page );
			await tocar( page, abrir );
			await page.waitForTimeout( 800 );
			if ( 'Escape' === cerrar ) {
				await page.keyboard.press( 'Escape' );
			} else if ( 'fondo' === cerrar ) {
				await page.touchscreen.tap( 380, 420 );
			} else {
				await tocar( page, cerrar );
			}
			await page.waitForTimeout( 800 );
			expect( Math.abs( ( await scrollY( page ) ) - antes ), `${ abrir } → ${ cerrar }: la página se movió (antes −389 a −422 px)` ).toBeLessThanOrEqual( 1 );
		}
	} );

	test( 'Escape en el panel del mega-menú y ⌘K no mueven la página', async ( { page }, info ) => {
		test.skip( 'escritorio' !== info.project.name, 'mega-menú y atajo de escritorio' );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await page.evaluate( () => window.scrollTo( 0, 1800 ) );
		await page.waitForTimeout( 500 );
		const boton = page.locator( '.mainnav__item--has-sub > .mainnav__toggle' ).nth( 1 );
		await boton.focus();
		await page.keyboard.press( 'Enter' );
		await expect( boton ).toHaveAttribute( 'aria-expanded', 'true' );
		// El panel entra con una transición de 180 ms que arranca en `visibility:
		// hidden`: un Tab en ese mismo cuadro se lo salta y cae en «Cómics», y el
		// Escape ya no tiene panel que cerrar (QF-gp-R2: 4 de 4 rojos por eso).
		const panel = page.locator( `#${ await boton.getAttribute( 'aria-controls' ) }` );
		await expect( panel ).toBeVisible();
		await page.keyboard.press( 'Tab' );
		await expect( panel.locator( 'a[href]' ).first(), 'Tab entra al panel por su primer enlace' ).toBeFocused();
		await page.waitForTimeout( 300 );
		const antes = await scrollY( page );
		expect( antes, 'Tab al primer enlace del panel ya no sube la página' ).toBeGreaterThan( 1500 );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 500 );
		await expect( boton ).toBeFocused();
		expect( Math.abs( ( await scrollY( page ) ) - antes ), 'antes −437 px' ).toBeLessThanOrEqual( 1 );

		await page.evaluate( () => /** @type {HTMLElement} */ ( document.activeElement ).blur() );
		const antesAtajo = await scrollY( page );
		await page.keyboard.press( 'Control+k' );
		await page.waitForTimeout( 500 );
		await expect( page.locator( '#akb-search-input' ) ).toBeFocused();
		expect( Math.abs( ( await scrollY( page ) ) - antesAtajo ), 'antes −978 px' ).toBeLessThanOrEqual( 1 );
	} );
} );

test.describe( 'QAM-2: el toque antes de que cargue el JS no se pierde', () => {
	borrarSesionWcAlTerminar();

	/** Retrasa main.js y add-to-cart.js: la ventana entre ver la página y poder usarla. */
	async function redLenta( page ) {
		await page.route( /\/(main|add-to-cart)(\.min)?\.js(\?|$)/, async ( r ) => {
			await new Promise( ( fin ) => setTimeout( fin, 4000 ) );
			await r.continue();
		} );
	}

	/** Espera a que el control exista y se vea, sin esperar al JS. */
	async function aLaVista( page, selector ) {
		await expect.poll( () => page.evaluate( ( s ) => {
			const e = document.querySelector( s );
			if ( ! e ) {
				return false;
			}
			e.scrollIntoView( { block: 'center' } );
			return e.getBoundingClientRect().height > 0;
		}, selector ), { timeout: 15_000 } ).toBe( true );
	}

	test( '☰ y «Buscar» tocados antes de que cargue main.js se abren al cargar', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'controles del teléfono' );
		await redLenta( page );
		for ( const [ control, abierto ] of [
			[ '#menu-toggle', () => document.getElementById( 'mobile-drawer' )?.classList.contains( 'open' ) ],
			[ '#bottom-nav-search', () => document.getElementById( 'mobile-drawer' )?.classList.contains( 'mobile-drawer--search' ) ],
		] ) {
			await page.goto( '/', { waitUntil: 'commit' } );
			await aLaVista( page, control );
			expect( await page.evaluate( () => !! document.querySelector( '.mainnav--js' ) ), 'main.js aún no corrió' ).toBe( false );
			await tocar( page, control );
			await expect( page.locator( control ), 'se ve ocupado mientras espera' ).toHaveAttribute( 'aria-busy', 'true' );
			await expect.poll( () => page.evaluate( abierto ), { timeout: 15_000, message: `${ control }: el toque se perdía` } ).toBe( true );
			await expect( page.locator( control ) ).not.toHaveAttribute( 'aria-busy', 'true' );
		}
	} );

	test( '«Añadir al carrito» de una tarjeta tocado antes de que cargue WooCommerce agrega UNA unidad', async ( { page }, info ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		test.skip( 'movil' !== info.project.name, 'se mide con el dedo' );
		await redLenta( page );
		await page.goto( '/serie/dan-da-dan/', { waitUntil: 'commit' } );
		const boton = 'ul.products li.product .add_to_cart_button.ajax_add_to_cart';
		await aLaVista( page, boton );
		await tocar( page, boton );
		await tocar( page, boton ); // un segundo toque impaciente no agrega dos
		await expect.poll( async () => {
			const r = await page.request.post( '/?wc-ajax=get_refreshed_fragments' );
			const j = await r.json().catch( () => ( {} ) );
			const html = Object.values( j.fragments || {} ).join( ' ' );
			const m = html.match( /data-akb-cart-count="(\d+)"/ );
			return m ? Number( m[ 1 ] ) : 0;
		}, { timeout: 20_000, message: 'el toque temprano se perdía y el carrito quedaba vacío' } ).toBe( 1 );
	} );

	test( 'RQF2-2: «Añadir» en DOS tomos antes de que cargue WooCommerce agrega los dos', async ( { page }, info ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		test.skip( 'movil' !== info.project.name, 'se mide con el dedo' );
		await redLenta( page );
		const pedidos = [];
		page.on( 'request', ( r ) => {
			if ( /wc-ajax=add_to_cart/.test( r.url() ) ) {
				pedidos.push( ( r.postData() || '' ).match( /product_id=(\d+)/ )?.[ 1 ] );
			}
		} );
		await page.goto( '/serie/dan-da-dan/', { waitUntil: 'commit' } );
		const boton = 'ul.products li.product .add_to_cart_button.ajax_add_to_cart';
		await aLaVista( page, boton );
		const botones = page.locator( boton );
		const [ a, b ] = [ await botones.nth( 0 ).getAttribute( 'data-product_id' ), await botones.nth( 1 ).getAttribute( 'data-product_id' ) ];
		expect( a, 'dos tomos distintos' ).not.toBe( b );
		for ( const i of [ 0, 1 ] ) {
			const caja = await botones.nth( i ).boundingBox();
			await page.touchscreen.tap( caja.x + caja.width / 2, caja.y + caja.height / 2 );
		}
		expect( await page.evaluate( () => !! document.querySelector( '.mainnav--js' ) ), 'los dos toques llegaron antes del JS' ).toBe( false );
		await expect( botones.nth( 0 ), 'el primero sigue esperando (antes se desmarcaba y se perdía)' ).toHaveAttribute( 'aria-busy', 'true' );
		await expect( botones.nth( 1 ) ).toHaveAttribute( 'aria-busy', 'true' );
		await expect.poll( async () => {
			const r = await page.request.post( '/?wc-ajax=get_refreshed_fragments' );
			const j = await r.json().catch( () => ( {} ) );
			const html = Object.values( j.fragments || {} ).join( ' ' );
			const m = html.match( /data-akb-cart-count="(\d+)"/ );
			return m ? Number( m[ 1 ] ) : 0;
		}, { timeout: 20_000, message: 'el primer tomo se perdía' } ).toBe( 2 );
		expect( pedidos, 'uno por tomo, en el orden de los toques' ).toEqual( [ a, b ] );
	} );

	test( 'RQF2-2: un ☰ después de «Añadir» no tira la compra (sin escribir: la respuesta es de mentira)', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		test.skip( 'movil' !== info.project.name, 'controles del teléfono' );
		await redLenta( page );
		const pedidos = [];
		await page.route( /wc-ajax=add_to_cart/, async ( r ) => {
			pedidos.push( ( r.request().postData() || '' ).match( /product_id=(\d+)/ )?.[ 1 ] );
			await r.fulfill( { status: 200, contentType: 'application/json', body: '{"error":true}' } );
		} );
		await page.route( /akb_get_cart/, ( r ) => r.fulfill( { status: 200, contentType: 'application/json', body: '{}' } ) );
		await page.goto( '/tienda/', { waitUntil: 'commit' } );
		const boton = 'ul.products li.product .add_to_cart_button.ajax_add_to_cart';
		await aLaVista( page, boton );
		const id = await page.locator( boton ).first().getAttribute( 'data-product_id' );
		await tocar( page, boton );
		await aLaVista( page, '#menu-toggle' );
		await tocar( page, '#menu-toggle' );
		expect( await page.evaluate( () => !! document.querySelector( '.mainnav--js' ) ), 'los toques llegaron antes del JS' ).toBe( false );
		await expect( page.locator( boton ).first(), 'la compra sigue pendiente' ).toHaveAttribute( 'aria-busy', 'true' );
		await expect( page.locator( '#mobile-drawer' ) ).toHaveClass( /open/, { timeout: 15_000 } );
		await expect.poll( () => pedidos, { timeout: 15_000, message: 'antes el ☰ se llevaba la compra' } ).toEqual( [ id ] );
	} );

	/*
	 * RQF3-1: un segundo botón del MISMO tomo (en producción la portada repite tomos
	 * entre secciones) no entra en la cola —su compra la hace el primero— y nadie le
	 * quitaba la marca: quedaba atenuado y con aria-busy hasta recargar. La respuesta
	 * es de mentira: nada se escribe.
	 */
	test( 'RQF3-1: tocados antes del JS el botón y su gemelo, sale UN alta y ninguno queda marcado', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		test.skip( 'movil' !== info.project.name, 'se mide con el dedo' );
		await redLenta( page );
		const pedidos = [];
		await page.route( /wc-ajax=add_to_cart/, async ( r ) => {
			pedidos.push( ( r.request().postData() || '' ).match( /product_id=(\d+)/ )?.[ 1 ] );
			await r.fulfill( { status: 200, contentType: 'application/json', body: '{"error":true}' } );
		} );
		await page.route( /akb_get_cart/, ( r ) => r.fulfill( { status: 200, contentType: 'application/json', body: '{}' } ) );
		await page.goto( '/tienda/', { waitUntil: 'commit' } );
		const boton = 'ul.products li.product .add_to_cart_button.ajax_add_to_cart';
		await aLaVista( page, boton );
		const id = await page.evaluate( () => {
			const li = document.querySelector( 'ul.products li.product' );
			const gemelo = li.cloneNode( true );
			gemelo.setAttribute( 'data-akb-e2e', 'gemelo' );
			li.after( gemelo );
			return li.querySelector( '.ajax_add_to_cart' ).getAttribute( 'data-product_id' );
		} );
		const primero = page.locator( 'ul.products li.product:not([data-akb-e2e]) .ajax_add_to_cart' ).first();
		const gemelo = page.locator( 'li.product[data-akb-e2e="gemelo"] .ajax_add_to_cart' );
		for ( const l of [ primero, gemelo ] ) {
			const caja = await l.boundingBox();
			await page.touchscreen.tap( caja.x + caja.width / 2, caja.y + caja.height / 2 );
		}
		expect( await page.evaluate( () => !! document.querySelector( '.mainnav--js' ) ), 'los dos toques llegaron antes del JS' ).toBe( false );
		await expect( gemelo, 'el toque en el gemelo se ve recibido' ).toHaveAttribute( 'aria-busy', 'true' );
		await page.locator( '.mainnav--js' ).waitFor( { state: 'attached', timeout: 20_000 } );
		await expect.poll( () => pedidos, { timeout: 15_000, message: 'un alta por tomo' } ).toEqual( [ id ] );
		for ( const l of [ primero, gemelo ] ) {
			await expect( l, 'antes el gemelo quedaba con aria-busy hasta recargar' ).not.toHaveAttribute( 'aria-busy', /.*/ );
			await expect( l ).not.toHaveClass( /akb-toque-pendiente/ );
		}
		await expect( gemelo ).toHaveCSS( 'opacity', '1' );
	} );

	/*
	 * Deuda previa (verificación final del lote de mejoras del tema, 2026-09-25): antes
	 * de que pdp-ajax-atc.js enganche su envío por fetch, «Añadir al carrito» de la
	 * ficha envía el formulario en un POST normal, y cada envío es un alta. Un doble
	 * toque mandaba dos y el tomo podía quedar con cantidad 2 (2 de 34 dobles toques a
	 * ~400 ms, Chromium). El POST se contesta aquí: nada llega al servidor.
	 */
	test( 'ficha: dos toques en «Añadir al carrito» antes de que cargue el JS mandan UN formulario', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		await page.route( /\/pdp-ajax-atc(\.min)?\.js(\?|$)/, async ( r ) => {
			await new Promise( ( fin ) => setTimeout( fin, 4000 ) );
			await r.continue().catch( () => {} );
		} );
		const envios = [];
		await page.route( ( url ) => url.pathname === FICHA, async ( r ) => {
			if ( 'POST' !== r.request().method() ) {
				await r.continue();
				return;
			}
			envios.push( r.request().postData() || '' );
			await new Promise( ( fin ) => setTimeout( fin, 1500 ) ); // el POST tarda: el segundo toque llega con él de camino
			await r.fulfill( { contentType: 'text/html', body: '<!doctype html><title>e2e</title><p id="akb-e2e-post">enviado</p>' } ).catch( () => {} );
		} );
		await page.goto( FICHA, { waitUntil: 'commit' } );
		const boton = 'form.cart .single_add_to_cart_button';
		await aLaVista( page, boton );
		expect( await page.evaluate( () => !! document.querySelector( 'form.cart [role="status"]' ) ), 'pdp-ajax-atc.js aún no enganchó: el envío es un POST normal' ).toBe( false );
		const caja = await page.locator( boton ).boundingBox();
		for ( let i = 0; i < 2; i++ ) {
			if ( 'movil' === info.project.name ) {
				await page.touchscreen.tap( caja.x + caja.width / 2, caja.y + caja.height / 2 );
			} else {
				await page.mouse.click( caja.x + caja.width / 2, caja.y + caja.height / 2 );
			}
			await page.waitForTimeout( 400 );
		}
		await expect( page.locator( '#akb-e2e-post' ) ).toBeVisible( { timeout: 15_000 } );
		await page.waitForTimeout( 500 );
		expect( envios.length, 'el segundo toque mandaba otro POST: el tomo quedaba con cantidad 2' ).toBe( 1 );
		expect( envios[ 0 ], 'el que sale es la compra' ).toMatch( /name="add-to-cart"/ );
	} );

	/**
	 * La ficha antes de que enganche pdp-ajax-atc.js (se aborta): «Añadir» manda un
	 * POST normal. Cada POST se anota y se retiene hasta `soltar()`, y se contesta
	 * aquí: nada llega al servidor. `pulsar` toca el centro del botón (con el dedo en
	 * el móvil), sin desplazar nada.
	 *
	 * Con el POST de camino Chromium no deja mirar la página (los locators y
	 * `evaluate` esperan a que acabe la navegación): la página anota en la consola
	 * cada cambio de `inert` del formulario y, 600 ms después de ponerse, la
	 * opacidad del botón. `inertes()` da esos cambios en orden.
	 */
	async function fichaConPostRetenido( page, info ) {
		const sonda = [];
		page.on( 'console', ( m ) => {
			if ( m.text().startsWith( 'akb-e2e ' ) ) {
				sonda.push( m.text().slice( 8 ) );
			}
		} );
		await page.addInitScript( sondaDeLaFicha );
		await page.route( /\/pdp-ajax-atc(\.min)?\.js(\?|$)/, ( r ) => r.abort() );
		const envios = [];
		let soltar = () => {};
		const suelto = new Promise( ( fin ) => { soltar = fin; } );
		await page.route( ( url ) => url.pathname === FICHA, async ( r ) => {
			if ( 'POST' !== r.request().method() ) {
				await r.continue();
				return;
			}
			envios.push( r.request().postData() || '' );
			await suelto;
			await r.fulfill( { contentType: 'text/html', body: '<!doctype html><title>e2e</title><p id="akb-e2e-post">enviado</p>' } ).catch( () => {} );
		} );
		expect( ( await page.goto( FICHA, { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const boton = page.locator( 'form.cart .single_add_to_cart_button' );
		await boton.scrollIntoViewIfNeeded();
		expect( await page.evaluate( () => !! document.querySelector( 'form.cart [role="status"]' ) ), 'pdp-ajax-atc.js no enganchó: el envío es un POST normal' ).toBe( false );
		const caja = /** @type {{x:number,y:number,width:number,height:number}} */ ( await boton.boundingBox() );
		const pulsar = async () => {
			if ( 'movil' === info.project.name ) {
				await page.touchscreen.tap( caja.x + caja.width / 2, caja.y + caja.height / 2 );
			} else {
				await page.mouse.click( caja.x + caja.width / 2, caja.y + caja.height / 2 );
			}
		};
		const inertes = () => sonda.filter( ( x ) => x.startsWith( 'inert=' ) );
		return { envios, soltar, pulsar, sonda, inertes, boton, form: page.locator( 'form.cart' ) };
	}

	/*
	 * REV-MT2-D1: el formulario queda inerte con el POST de camino, pero si el cliente
	 * detiene la carga (Esc, «Detener» o la X de la barra) la página se queda y el
	 * formulario seguía inerte hasta recargar: «Añadir», la cantidad y el Tab muertos,
	 * sin aviso. Se detiene como lo hace el navegador: Page.stopLoading por CDP en
	 * Chromium (lo mismo que Esc), window.stop() en WebKit. El formulario vuelve al
	 * vencer el plazo de seguridad (30 s), en los dos motores: el `navigateerror` de
	 * Chromium no es de fiar (REV-ZT1o-1, abajo). El reloj de la página se adelanta.
	 */
	test( 'ficha: si el cliente detiene el envío, el formulario vuelve al vencer el plazo y el reintento sale (REV-MT2-D1)', async ( { page, browserName }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		await page.clock.install();
		const f = await fichaConPostRetenido( page, info );
		await f.pulsar();
		await expect.poll( () => f.envios.length ).toBe( 1 );
		await page.waitForTimeout( 400 );
		await f.pulsar(); // el doble toque con el POST de camino sigue sin salir
		await page.waitForTimeout( 300 );
		expect( f.envios.length, 'con el POST de camino el formulario sigue inerte' ).toBe( 1 );

		if ( 'chromium' === browserName ) {
			await ( await page.context().newCDPSession( page ) ).send( 'Page.stopLoading' );
		} else {
			await page.evaluate( () => window.stop() ).catch( () => {} );
		}
		f.soltar(); // el POST detenido ya no llega a la página
		await page.waitForTimeout( 500 );
		expect( new URL( page.url() ).pathname, 'la página se quedó: el cliente sigue en la ficha' ).toBe( FICHA );
		expect( f.inertes(), 'ningún aviso del navegador lo suelta antes del plazo' ).toEqual( [ 'inert=true' ] );
		// El reloj de la página sigue andando: ya van unos 2 s desde el envío.
		await page.clock.fastForward( 25_000 );
		await expect( f.form, 'antes del plazo sigue inerte' ).toHaveAttribute( 'inert', '' );
		await expect( f.boton, 'y se ve esperando' ).toHaveCSS( 'opacity', '0.6' );
		await page.clock.fastForward( 6_000 );
		// Hasta 35 s: si el reloj de mentira no llegara a la página (pasó una vez en 5
		// corridas con 4 workers), el plazo de verdad la suelta igual; nada más la suelta.
		await expect( f.form, 'detenido el envío, el formulario quedaba inerte hasta recargar' ).not.toHaveAttribute( 'inert', /.*/, { timeout: 35_000 } );
		await expect( f.boton, 'y el botón deja de verse esperando' ).toHaveCSS( 'opacity', '1' );
		expect( f.envios.length ).toBe( 1 );

		await f.pulsar();
		await expect( page.locator( '#akb-e2e-post' ), 'el reintento no mandaba nada' ).toBeVisible( { timeout: 10_000 } );
		expect( f.envios.length ).toBe( 2 );
		expect( f.envios[ 1 ], 'el que sale es la compra' ).toMatch( /name="add-to-cart"/ );
	} );

	/*
	 * REV-ZT1o-1 / REV-ZT1-D1: el formulario volvía con cualquier `navigateerror`, y
	 * Chromium lo dispara también con el POST de camino: por la navegación que el
	 * envío reemplaza (un enlace tocado con la red lenta), o por una navegación dentro
	 * del documento (un ancla, un replaceState), y el POST llega igual. El doble toque
	 * mandaba dos altas (medido en el espejo, Chromium, escritorio y móvil). Además,
	 * con el formulario inerte el botón se ve esperando (REV-ZT1o-2).
	 */
	for ( const caso of [ 'enlace lento antes', 'ancla después', 'replaceState después' ] ) {
		test( `ficha: con una navegación ajena (${ caso }), el doble toque manda UN formulario (REV-ZT1o-1)`, async ( { page }, info ) => {
			test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
			let soltarEnlace = () => {};
			const enlaceSuelto = new Promise( ( fin ) => { soltarEnlace = fin; } );
			await page.route( ( url ) => '/akb-e2e-lento/' === url.pathname, async ( r ) => {
				await enlaceSuelto;
				await r.fulfill( { contentType: 'text/html', body: '<p>lento</p>' } ).catch( () => {} );
			} );
			const f = await fichaConPostRetenido( page, info );
			try {
				if ( 'enlace lento antes' === caso ) {
					// Con la red lenta el enlace tocado no llega: el envío lo reemplaza.
					await page.evaluate( () => {
						const a = document.createElement( 'a' );
						a.href = '/akb-e2e-lento/';
						document.body.appendChild( a );
						a.click();
					} );
					await page.waitForTimeout( 300 );
					await f.pulsar();
				} else {
					// La hace la propia página medio segundo después del envío: con el POST
					// de camino, Chromium ya no deja correr `evaluate`.
					await page.evaluate( ( c ) => {
						document.addEventListener( 'submit', () => setTimeout( () => {
							if ( 'ancla después' === c ) {
								window.location.hash = '#akb-e2e-ancla'; // sin destino: la página no se mueve bajo el dedo
							} else {
								window.history.replaceState( window.history.state, '', window.location.pathname + '?akb-e2e=1' );
							}
							console.log( 'akb-e2e navegó en el documento' );
						}, 500 ), { once: true } );
					}, caso );
					await f.pulsar();
					await expect.poll( () => f.sonda, 'la navegación dentro del documento no llegó a hacerse' ).toContain( 'navegó en el documento' );
				}
				await expect.poll( () => f.envios.length ).toBe( 1 );
				await page.waitForTimeout( 400 );
				await f.pulsar().catch( () => {} );
				await page.waitForTimeout( 500 );
				expect( f.envios.length, 'el doble toque mandaba la segunda alta' ).toBe( 1 );
				expect( f.inertes(), 'el `inert` se quitaba con el POST de camino' ).toEqual( [ 'inert=true' ] );
				expect( f.sonda, 'sin ninguna señal, el cliente que esperaba volvía a tocar' ).toContain( 'opacidad=0.6' );
			} finally {
				soltarEnlace();
				f.soltar();
			}
			await expect( page.locator( '#akb-e2e-post' ) ).toBeVisible( { timeout: 10_000 } );
			expect( f.envios[ 0 ], 'el que sale es la compra' ).toMatch( /name="add-to-cart"/ );
		} );
	}

	/*
	 * Revisión de la corrección Z-T-c1: la barra fija (sticky-atc.js, que engancha antes
	 * que pdp-ajax-atc.js) reenvía con un click() por código, y eso salta el `inert`:
	 * dos toques en la barra, dos POST (medido en el espejo en Chromium y WebKit a 390).
	 */
	test( 'ficha: con el POST de camino, un segundo toque en la barra fija no manda otro formulario', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		const f = await fichaConPostRetenido( page, info );
		try {
			await page.evaluate( () => window.scrollTo( 0, document.body.scrollHeight * 0.6 ) );
			const barra = page.locator( '#akb-sticky-atc-btn' );
			await expect( page.locator( '#akb-sticky-atc' ), 'con el formulario fuera de la vista, la barra sale' ).toHaveClass( /\bis-visible\b/ );
			await page.waitForTimeout( 400 ); // lo que tarda en entrar
			const caja = /** @type {{x:number,y:number,width:number,height:number}} */ ( await barra.boundingBox() );
			const tocarBarra = async () => {
				if ( 'movil' === info.project.name ) {
					await page.touchscreen.tap( caja.x + caja.width / 2, caja.y + caja.height / 2 );
				} else {
					await page.mouse.click( caja.x + caja.width / 2, caja.y + caja.height / 2 );
				}
			};
			await tocarBarra();
			await expect.poll( () => f.envios.length ).toBe( 1 );
			await page.waitForTimeout( 400 );
			await tocarBarra();
			await page.waitForTimeout( 800 );
			expect( f.envios.length, 'cada toque en la barra mandaba otra alta' ).toBe( 1 );
			expect( f.inertes() ).toEqual( [ 'inert=true' ] );
			expect( f.sonda, 'el botón de la barra también se ve esperando' ).toContain( 'opacidad-barra=0.6' );
		} finally {
			f.soltar();
		}
		await expect( page.locator( '#akb-e2e-post' ) ).toBeVisible( { timeout: 10_000 } );
		expect( f.envios[ 0 ], 'el que sale es la compra' ).toMatch( /name="add-to-cart"/ );
	} );

	/*
	 * REV-ZT1o-2 / REV-ZT1-D2: el plazo de 12 s soltaba el formulario con el POST aún de
	 * camino si el servidor tardaba más (bajo carga tardó 7-8,5 s), y el reintento a los
	 * 13 s era la segunda alta, en Chromium y en WebKit. En tiempo real.
	 */
	test( 'ficha: con el servidor lento, un toque a los 13 s no manda otro formulario (REV-ZT1o-2)', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		const f = await fichaConPostRetenido( page, info );
		try {
			const inicio = Date.now();
			await f.pulsar();
			await expect.poll( () => f.envios.length ).toBe( 1 );
			await page.waitForTimeout( 400 );
			await f.pulsar();
			// Tiempo de verdad: con el POST de camino Chromium no deja adelantar el reloj de la página.
			await page.waitForTimeout( 13_000 - ( Date.now() - inicio ) );
			expect( f.inertes(), 'a los 12 s se soltaba con el POST de camino' ).toEqual( [ 'inert=true' ] );
			await f.pulsar();
			await page.waitForTimeout( 400 );
			await f.pulsar();
			await page.waitForTimeout( 500 );
			expect( f.envios.length, 'el reintento a los 13 s era la segunda alta (y la tercera con el doble toque)' ).toBe( 1 );
		} finally {
			f.soltar();
		}
		await expect( page.locator( '#akb-e2e-post' ) ).toBeVisible( { timeout: 10_000 } );
	} );

	test( 'con la página cargada, el toque va directo (el captador ya se retiró)', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'controles del teléfono' );
		await page.goto( '/', { waitUntil: 'load' } );
		await tocar( page, '#menu-toggle' );
		await expect( page.locator( '#mobile-drawer' ) ).toHaveClass( /open/, { timeout: 1000 } );
		await expect( page.locator( '#menu-toggle' ) ).not.toHaveAttribute( 'aria-busy', 'true' );
	} );
} );

test.describe( 'QAM-3 / QAM-5: «Más vendidos» del carrito vacío', () => {
	borrarSesionWcAlTerminar();

	test( 'el botón es el de compra unificado, sin contorno recortado', async ( { page, context } ) => {
		await context.clearCookies();
		expect( ( await page.goto( '/carrito/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const boton = page.locator( '.akb-cart-empty ul.products li.product .add_to_cart_button' ).first();
		test.skip( ! ( await boton.count() ), 'el carrito vacío no trae venta cruzada' );
		const estilo = await boton.evaluate( ( b ) => { const c = getComputedStyle( b ); return { fondo: c.backgroundColor, borde: c.borderTopColor }; } );
		expect( estilo.fondo, 'QAM-5: era transparente con un contorno de 2 px que el clip-path cortaba' ).toBe( 'rgb(204, 0, 16)' );
	} );

	test( 'agregar desde ahí llena el carrito sin recargar la página', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		expect( ( await page.goto( '/carrito/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		expect( await page.locator( 'script#wc-cart-js' ).count(), 'el carrito es de bloques: cart.js del clásico sobra' ).toBe( 0 );
		const boton = page.locator( '.akb-cart-empty ul.products li.product .add_to_cart_button' ).first();
		test.skip( ! ( await boton.count() ), 'el carrito vacío no trae venta cruzada' );
		const navegaciones = [];
		page.on( 'framenavigated', ( f ) => {
			if ( f === page.mainFrame() ) {
				navegaciones.push( f.url() );
			}
		} );
		await boton.scrollIntoViewIfNeeded();
		await boton.click();
		await expect( page.locator( '.wc-block-cart-items__row' ).first() ).toBeVisible( { timeout: 15_000 } );
		await page.waitForTimeout( 1500 );
		expect( navegaciones, 'QAM-3: cart.js recargaba /carrito/ entero' ).toEqual( [] );
	} );
} );

test.describe( 'QAM-8: la red se cae al agregar desde la ficha', () => {
	borrarSesionWcAlTerminar();

	test( 'el motivo se ve junto al botón que se tocó y se va a los 6 s', async ( { page }, info ) => {
		// Si pdp-ajax-atc.js no enganchara, «Añadir» sería un POST normal a la ficha,
		// que esta intercepción no toca: un alta de verdad en la tienda.
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		await page.route( /wc-ajax=add_to_cart/, ( r ) => r.abort() );
		expect( ( await page.goto( FICHA, { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const real = page.locator( 'form.cart .single_add_to_cart_button' );
		test.skip( ! ( await real.count() ), 'la ficha ya no se puede comprar' );
		await real.scrollIntoViewIfNeeded();
		if ( 'movil' === info.project.name ) {
			await real.tap();
		} else {
			await real.click();
		}
		const motivo = page.locator( 'form.cart .akb-atc-motivo' );
		await expect( motivo, 'antes sólo lo oía el lector de pantalla' ).toBeVisible();
		await expect( motivo ).toHaveText( /No se pudo conectar, reintenta/ );
		await expect( real ).toHaveText( /reintentar/i );
		await expect( motivo ).toBeHidden( { timeout: 8000 } );
		await expect( real ).not.toHaveText( /reintentar/i );
	} );
} );

test.describe( 'QA-ESC-TECLADO: escritorio y teclado', () => {
	test( '2: con «reducir movimiento» el panel sigue abierto al bajar el ratón hacia él', async ( { page }, info ) => {
		test.skip( 'escritorio' !== info.project.name, 'mega-menú de escritorio' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const items = await page.locator( '.mainnav__item--has-sub' ).count();
		for ( let i = 0; i < items; i++ ) {
			const enlace = await page.locator( '.mainnav__item--has-sub > .mainnav__link' ).nth( i ).boundingBox();
			await page.mouse.move( 5, 600 );
			await page.mouse.move( enlace.x + enlace.width / 2, enlace.y + enlace.height / 2 );
			for ( let y = enlace.y + enlace.height / 2; y < enlace.y + enlace.height + 30; y += 3 ) {
				await page.mouse.move( enlace.x + enlace.width / 2, y );
				const visible = await page.locator( '.mainnav__item--has-sub > .mainnav__sub' ).nth( i ).evaluate( ( s ) => 'visible' === getComputedStyle( s ).visibility );
				expect( visible, `ítem ${ i }: el panel se cerró en y=${ Math.round( y ) } (hueco de 4 px sin puente)` ).toBe( true );
			}
		}
		// Cerrado, el puente no abre nada: pasar justo por debajo de la fila no despliega.
		const fila = await page.locator( '.mainnav__item--has-sub > .mainnav__link' ).first().boundingBox();
		await page.mouse.move( 5, 600 );
		await page.mouse.move( fila.x + fila.width / 2, fila.y + fila.height + 2 );
		await expect( page.locator( '.mainnav__item--has-sub > .mainnav__sub' ).first() ).toHaveCSS( 'visibility', 'hidden' );
	} );

	test( '2 (RQF2-1): con «reducir movimiento», en línea recta del enlace al primer enlace de su panel, el panel no se cierra', async ( { page }, info ) => {
		test.skip( 'escritorio' !== info.project.name, 'mega-menú de escritorio' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const items = page.locator( '.mainnav__item--has-sub' );
		const n = await items.count();
		expect( n ).toBeGreaterThan( 0 );
		let centrados = 0;
		for ( let i = 0; i < n; i++ ) {
			const item = items.nth( i );
			const enlace = await item.locator( ':scope > .mainnav__link' ).boundingBox();
			const desde = { x: enlace.x + enlace.width / 2, y: enlace.y + enlace.height / 2 };
			await page.mouse.move( 5, 600 );
			await page.mouse.move( desde.x, desde.y );
			await expect( item.locator( ':scope > .mainnav__sub' ) ).toHaveCSS( 'visibility', 'visible' );
			const destino = await item.evaluate( ( el ) => {
				const sub = el.querySelector( ':scope > .mainnav__sub' );
				const primero = sub.querySelector( 'a.mainnav__sublink' ).getBoundingClientRect();
				if ( sub.getBoundingClientRect().left < el.getBoundingClientRect().left - 1 ) {
					el.dataset.akbCentrado = '1'; // el panel sobresale por la izquierda del ítem
				}
				// Cualquier cierre en el camino queda anotado, aunque el panel volviera a abrirse.
				el.dataset.akbCerrado = '';
				new MutationObserver( () => {
					if ( ! el.classList.contains( 'mainnav__flyout--open' ) && ! el.matches( ':hover' ) ) el.dataset.akbCerrado = '1';
				} ).observe( el, { attributes: true, attributeFilter: [ 'class' ] } );
				return { x: primero.left + primero.width / 2, y: primero.top + primero.height / 2 };
			} );
			if ( await item.getAttribute( 'data-akb-centrado' ) ) centrados++;
			await page.mouse.move( destino.x, destino.y, { steps: 40 } );
			const texto = ( await item.locator( ':scope > .mainnav__link' ).innerText() ).trim();
			await expect( item.locator( ':scope > .mainnav__sub' ), `${ texto }: el panel se cerró por el camino` ).toHaveCSS( 'visibility', 'visible' );
			expect( await item.getAttribute( 'data-akb-cerrado' ), `${ texto }: se cerró en algún punto del camino` ).toBe( '' );
		}
		expect( centrados, 'la prueba pasa por un panel centrado más ancho que su ítem (Editoriales)' ).toBeGreaterThan( 0 );
	} );

	test( '2 (RQF2-1): parado en el hueco bajo la fila, sobre el borde izquierdo del panel centrado, el panel sigue abierto', async ( { page }, info ) => {
		test.skip( 'escritorio' !== info.project.name, 'mega-menú de escritorio' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const item = page.locator( '.mainnav__item--has-sub:has(> .mainnav__sub--auto)' ).first();
		const sub = item.locator( ':scope > .mainnav__sub' );
		const enlace = await item.locator( ':scope > .mainnav__link' ).boundingBox();
		const fila = await item.boundingBox();
		await page.mouse.move( 5, 600 );
		await page.mouse.move( enlace.x + enlace.width / 2, enlace.y + enlace.height / 2 );
		const panel = await sub.boundingBox();
		expect( panel.x, 'el panel sobresale del ítem por la izquierda' ).toBeLessThan( fila.x - 100 );
		// Baja al hueco (4 px entre la fila y el panel) y lo recorre hacia la izquierda.
		const yHueco = fila.y + fila.height + 2;
		await page.mouse.move( enlace.x + enlace.width / 2, yHueco, { steps: 5 } );
		await page.mouse.move( panel.x + 12, yHueco, { steps: 30 } );
		await page.waitForTimeout( 700 ); // más que la gracia de cierre: aquí manda el puente
		await expect( sub, 'fuera del puente viejo (sólo el ancho del ítem) el panel se cerraba' ).toHaveCSS( 'visibility', 'visible' );
	} );

	test( '2 (RQF2-1): con movimiento normal, cruzando hacia otros ítems, el panel que se va no se vuelve a abrir encima', async ( { page }, info ) => {
		test.skip( 'escritorio' !== info.project.name, 'mega-menú de escritorio' );
		await page.emulateMedia( { reducedMotion: 'no-preference' } );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const items = page.locator( '.mainnav__item--has-sub' );
		test.skip( ( await items.count() ) < 3, 'hacen falta tres ítems con panel en la fila' );
		await page.evaluate( () => {
			window.__entradas = [];
			document.querySelectorAll( '.mainnav__item--has-sub' ).forEach( ( el, i ) => el.addEventListener( 'pointerenter', () => window.__entradas.push( i ) ) );
		} );
		// Del centro del primero a la esquina de arriba a la derecha de su panel (ya
		// abierto del todo): cruza un enlace suelto y los otros dos ítems.
		const enlace = await items.nth( 0 ).locator( ':scope > .mainnav__link' ).boundingBox();
		const desde = { x: enlace.x + enlace.width / 2, y: enlace.y + enlace.height / 2 };
		await page.mouse.move( desde.x, desde.y );
		await expect( items.nth( 0 ).locator( ':scope > .mainnav__sub' ) ).toHaveCSS( 'opacity', '1' );
		const panel = await items.nth( 0 ).locator( ':scope > .mainnav__sub' ).boundingBox();
		await page.mouse.move( panel.x + panel.width - 12, panel.y + 10, { steps: 40 } );
		await page.waitForTimeout( 300 );
		const entradas = await page.evaluate( () => window.__entradas );
		expect( entradas.slice( 1 ), 'sin volver al primero: su panel, fundiéndose, se reabría encima del segundo' ).not.toContain( 0 );
		expect( entradas.at( -1 ), 'el último ítem cruzado es el que queda abierto' ).toBe( 2 );
		await expect( items.nth( 2 ).locator( ':scope > .mainnav__sub' ) ).toHaveCSS( 'visibility', 'visible' );
	} );

	/*
	 * RQF3-2: la gracia de RQF2-1 también valía para salir por la fila. El panel de
	 * Editoriales (802 px, centrado) queda debajo de «Series»: de Editoriales a
	 * «Series» y abajo al contenido a velocidad humana, el panel seguía abierto
	 * bajo el puntero y el clic caía en él, no en la tarjeta.
	 */
	for ( const movimiento of [ 'reduce', 'no-preference' ] ) {
		test( `2 (RQF3-2): de «Editoriales» a «Series» por la fila y abajo al contenido, el clic llega a la tarjeta (movimiento: ${ movimiento })`, async ( { page }, info ) => {
			test.skip( 'escritorio' !== info.project.name, 'mega-menú de escritorio' );
			await page.emulateMedia( { reducedMotion: /** @type {'reduce'|'no-preference'} */ ( movimiento ) } );
			expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
			const item = page.locator( '.mainnav__item--has-sub:has(> .mainnav__sub--auto)' ).first();
			// El enlace sin panel que le sigue en la fila («Series»).
			const vecino = page.locator( '.mainnav__item--has-sub:has(> .mainnav__sub--auto) + a.mainnav__link' ).first();
			const enlace = await item.locator( ':scope > .mainnav__link' ).boundingBox();
			const serie = await vecino.boundingBox();
			const desde = { x: enlace.x + enlace.width / 2, y: enlace.y + enlace.height / 2 };
			const fila = { x: serie.x + serie.width / 2, y: desde.y };
			const abajo = { x: fila.x, y: fila.y + 250 };
			const mover = async ( a, b, pasos, ms ) => {
				for ( let i = 1; i <= pasos; i++ ) {
					await page.mouse.move( a.x + ( b.x - a.x ) * i / pasos, a.y + ( b.y - a.y ) * i / pasos );
					await page.waitForTimeout( ms / pasos );
				}
			};
			await page.mouse.move( desde.x, desde.y );
			await expect( item.locator( ':scope > .mainnav__sub' ) ).toHaveCSS( 'visibility', 'visible' );
			const panel = await item.locator( ':scope > .mainnav__sub' ).boundingBox();
			expect( panel.x + panel.width, 'el panel queda también debajo del vecino' ).toBeGreaterThan( abajo.x );
			await page.waitForTimeout( 250 );
			await mover( desde, fila, 10, 250 );
			await mover( fila, abajo, 10, 200 );
			const destino = await page.evaluate( ( p ) => {
				const el = document.elementFromPoint( p.x, p.y );
				return { panel: !! el?.closest( '.mainnav__sub' ), href: el?.closest( 'li.product' )?.querySelector( 'a' )?.getAttribute( 'href' ) || null };
			}, abajo );
			expect( destino.panel, 'el panel de Editoriales seguía abierto bajo el puntero' ).toBe( false );
			expect( destino.href, 'bajo el puntero hay una tarjeta' ).toBeTruthy();
			// La ficha tarda en cargar con el espejo ocupado: lo que se mide es que el clic navegue.
			await Promise.all( [
				page.waitForURL( /** @type {string} */ ( destino.href ), { timeout: 30_000, waitUntil: 'commit' } ),
				page.mouse.click( abajo.x, abajo.y ),
			] );
		} );
	}

	test( '4: «Saltar a los filtros» llega al primer filtro en pocas paradas', async ( { page }, info ) => {
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const salto = page.locator( '.akb-skip-filtros' );
		if ( 'movil' === info.project.name ) {
			await expect( salto, 'en el teléfono los filtros van en el cajón' ).toBeHidden();
			return;
		}
		expect( ( await salto.boundingBox() )?.width, 'invisible hasta que llega el foco' ).toBeLessThanOrEqual( 1 );
		let paradas = 0;
		while ( paradas < 40 && ! ( await salto.evaluate( ( e ) => e === document.activeElement ) ) ) {
			await page.keyboard.press( 'Tab' );
			paradas++;
		}
		expect( paradas, 'antes el primer filtro estaba en la parada 117' ).toBeLessThanOrEqual( 25 );
		await expect( salto ).toBeVisible();
		await page.keyboard.press( 'Enter' );
		await page.keyboard.press( 'Tab' );
		expect( await page.evaluate( () => !! document.activeElement?.closest( '.inside-left-sidebar' ) ), 'el siguiente Tab cae en la barra de filtros' ).toBe( true );
	} );

	test( '4: en una búsqueda sin resultados y sin filtros que mostrar no hay salto a una barra vacía', async ( { page }, info ) => {
		test.skip( 'escritorio' !== info.project.name, 'el salto sólo existe donde la barra se ve' );
		expect( ( await page.goto( '/tienda/?_buscar=zzqqxxnoexiste', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const barra = page.locator( '#akb-facets-drawer' );
		test.skip( ! ( await barra.count() ), 'sin buscador propio no hay barra' );
		const controles = await barra.evaluate( ( f ) => [ ...f.querySelectorAll( 'a, button, input:not([type=hidden]), select' ) ].filter( ( e ) => e.getClientRects().length ).length );
		expect( controles, 'la barra sale vacía: sólo conserva _buscar' ).toBe( 0 );
		// QF-gp-R3: el salto enfocaba un formulario de 0 px y el Tab siguiente caía en el logo del pie.
		await expect( page.locator( '.akb-skip-filtros' ) ).toHaveCount( 0 );

		// Al quitar la búsqueda (PJAX) vuelven el catálogo, la barra y el salto.
		await page.locator( '.akb-chip[data-facet="buscar"]' ).click();
		await page.waitForFunction( () => ! location.search.includes( '_buscar' ) && ! document.body.classList.contains( 'akb-pjax-loading' ) );
		await expect( page.locator( '.akb-skip-filtros' ) ).toHaveCount( 1 );
	} );

	test( '5: dentro de la tarjeta, el Tab baja siempre (enlace → corazón → ojo → Añadir)', async ( { page }, info ) => {
		test.skip( 'escritorio' !== info.project.name, 'la vista rápida sólo existe desde 769 px' );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const enlace = page.locator( 'ul.products li.product a.woocommerce-LoopProduct-link' ).first();
		await enlace.focus();
		const ys = [];
		for ( let i = 0; i < 4; i++ ) {
			ys.push( await page.evaluate( () => {
				const a = /** @type {HTMLElement} */ ( document.activeElement );
				const li = /** @type {HTMLElement} */ ( a.closest( 'li.product' ) );
				return li ? Math.round( a.getBoundingClientRect().top - li.getBoundingClientRect().top ) : -1;
			} ) );
			await page.keyboard.press( 'Tab' );
		}
		expect( ys.every( ( y ) => y >= 0 ), 'las cuatro paradas son de la misma tarjeta' ).toBe( true );
		expect( ys, 'el foco subía 310 px al ojo y volvía a bajar 513' ).toEqual( [ ...ys ].sort( ( a, b ) => a - b ) );
	} );

	test( '6: el nombre del buscador de la cabecera es lo que se ve', async ( { page }, info ) => {
		test.skip( 'escritorio' !== info.project.name, 'buscador de la cabecera de escritorio' );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const campo = page.locator( '#akb-search-input' );
		const pista = ( await campo.getAttribute( 'placeholder' ) || '' ).replace( /[\s.…]+$/u, '' );
		expect( pista.length ).toBeGreaterThan( 0 );
		// Con el buscador predictivo el campo es un combobox: se mira su nombre, no su rol.
		await expect( campo, 'decía «Buscar productos»' ).toHaveAccessibleName( pista );
	} );

	test( '9: al 200 % (683x384) las barras fijas tapan como mucho el 25 %', async ( { page }, info ) => {
		test.skip( 'escritorio' !== info.project.name, 'zoom de portátil' );
		for ( const vista of [ { width: 683, height: 384 }, { width: 720, height: 450 } ] ) {
			await page.setViewportSize( vista );
			for ( const ruta of [ FICHA, '/tienda/' ] ) {
				expect( ( await page.goto( ruta, { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
				await page.evaluate( () => window.scrollTo( 0, 1500 ) );
				await page.waitForTimeout( 800 );
				const tapado = await page.evaluate( () => {
					const filas = new Array( innerHeight ).fill( 0 );
					for ( const s of [ '.site-header', '.akb-sticky-atc', '.bottom-nav' ] ) {
						const e = document.querySelector( s );
						if ( ! e ) {
							continue;
						}
						const c = getComputedStyle( e );
						const r = e.getBoundingClientRect();
						if ( 'none' === c.display || 'hidden' === c.visibility || '0' === c.opacity ) {
							continue;
						}
						for ( let y = Math.max( 0, Math.floor( r.top ) ); y < Math.min( innerHeight, Math.ceil( r.bottom ) ); y++ ) {
							filas[ y ] = 1;
						}
					}
					return filas.reduce( ( a, b ) => a + b, 0 ) / innerHeight;
				} );
				expect( tapado, `${ vista.width }x${ vista.height } ${ ruta }: antes 52 % en la ficha y 36 % en la tienda` ).toBeLessThanOrEqual( 0.25 );
			}
		}
		// Una ventana normal no cambia: con 844 px de alto el bottom-nav sigue.
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await expect( page.locator( '.bottom-nav' ) ).toBeVisible();
	} );

	/*
	 * QAM-9 (tratado como defecto por el líder): con el teléfono en horizontal la
	 * línea «Llega {fecha}» subía la barra de compra de 68 a 85 px, y cabecera, barra
	 * y barra inferior dejaban 165 de 390 px al contenido. Ya no pasa: desde
	 * QA-ESC-TECLADO-9 (≤ 500 px de alto) la barra entera y la barra inferior se
	 * retiran, y con ellas la línea; el botón real y la fecha siguen en la ficha.
	 * Este es el candado del teléfono apaisado de verdad; en vertical la línea se
	 * queda (la cubre «la barra de compra de un tomo reservable dice cuándo llega»).
	 */
	test( 'QAM-9: con el teléfono en horizontal la barra de compra (y su «Llega …») no se come la ficha', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'teléfono apaisado' );
		test.setTimeout( 120_000 ); // buscar el tomo recorre dos listados
		const url = await productoConBadge( page, 'A pedido' );
		test.skip( ! url, 'no hay tomos a pedido en los listados' );
		for ( const vista of [ { width: 844, height: 390 }, { width: 932, height: 430 } ] ) {
			await page.setViewportSize( vista );
			expect( ( await page.goto( /** @type {string} */ ( url ), { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			test.skip( ! ( await page.locator( '.akb-sticky-atc__eta' ).count() ), 'este tomo no promete fecha (sin rango, o editorial en pausa)' );
			await page.evaluate( () => window.scrollTo( 0, 1500 ) );
			await page.waitForTimeout( 800 );
			const m = await page.evaluate( () => {
				const visto = ( s ) => {
					const e = document.querySelector( s );
					return !! e && 'none' !== getComputedStyle( e ).display && e.getBoundingClientRect().height > 0;
				};
				const cab = document.querySelector( '.site-header' ).getBoundingClientRect();
				return { barra: visto( '.akb-sticky-atc' ), llega: visto( '.akb-sticky-atc__eta' ), inferior: visto( '.bottom-nav' ), libre: innerHeight - Math.max( 0, cab.bottom ) };
			} );
			expect( m, `${ vista.width }x${ vista.height }` ).toEqual( expect.objectContaining( { barra: false, llega: false, inferior: false } ) );
			expect( m.libre, `${ vista.width }x${ vista.height }: antes 165 de 390 px para el contenido` ).toBeGreaterThanOrEqual( vista.height - 80 );
			await expect( page.locator( 'form.cart .single_add_to_cart_button' ), 'el botón real sigue en la ficha' ).toHaveCount( 1 );
		}
	} );

	test( '11: la flecha de la paginación se llama «Página siguiente» y el número actual no lleva aria-label', async ( { page } ) => {
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const nav = page.locator( 'nav.woocommerce-pagination' );
		test.skip( ! ( await nav.count() ), 'la tienda cabe en una página' );
		await expect( nav.getByRole( 'link', { name: 'Página siguiente', exact: true } ) ).toHaveCount( 1 );
		await expect( nav.locator( 'span.current' ) ).not.toHaveAttribute( 'aria-label', /.*/ );
		await expect( nav.locator( 'span.current' ) ).toHaveAttribute( 'aria-current', 'page' );
	} );

	test( '11: las flechas miden lo mismo que un número y lo que llevan dentro no es otra caja', async ( { page }, info ) => {
		expect( ( await page.goto( '/tienda/page/2/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const nav = page.locator( 'nav.woocommerce-pagination' );
		test.skip( ! ( await nav.count() ), 'la tienda cabe en una página' );
		const medir = () => nav.evaluate( ( n ) => {
			const ancho = ( s ) => Math.round( n.querySelector( s ).getBoundingClientRect().width );
			const caja = ( e ) => {
				const c = getComputedStyle( e );
				return { borde: c.borderTopWidth, fondo: c.backgroundColor, relleno: c.paddingLeft };
			};
			return {
				numero: ancho( 'li > a.page-numbers:not(.prev):not(.next)' ),
				prev: ancho( 'a.prev' ),
				next: ancho( 'a.next' ),
				interiores: [ ...n.querySelectorAll( 'li > a > span, li > span > span' ) ].map( caja ),
			};
		} );
		const m = await medir();
		expect( m.numero, 'el número conserva el objetivo táctil' ).toBeGreaterThanOrEqual( 44 );
		// QF-gp-R1: el <span> de la flecha heredaba la caja de `ul li span` → 100 px
		// en escritorio (72 el número) y 56 en el teléfono (44).
		expect( m.prev, `«←» mide ${ m.prev } px y un número ${ m.numero }` ).toBe( m.numero );
		expect( m.next, `«→» mide ${ m.next } px y un número ${ m.numero }` ).toBe( m.numero );
		expect( m.interiores.length, 'flecha + nombre oculto en cada flecha, «Página» oculto en cada número' ).toBeGreaterThanOrEqual( 4 );
		for ( const interior of m.interiores ) {
			expect( interior ).toEqual( { borde: '0px', fondo: 'rgba(0, 0, 0, 0)', relleno: '0px' } );
		}
		if ( 'movil' !== info.project.name ) {
			// Con el ratón encima el rojo cubre el enlace entero: antes la caja
			// interior seguía oscura y el rojo quedaba en dos franjas a los lados.
			// Se centra y se repite el hover: la cabecera compacta y la carga de la
			// rejilla pueden mover la paginación bajo el puntero justo después.
			const flecha = nav.locator( 'a.next' );
			await flecha.evaluate( ( e ) => e.scrollIntoView( { block: 'center' } ) );
			await page.waitForTimeout( 800 );
			await expect.poll( async () => {
				await flecha.hover();
				return flecha.evaluate( ( e ) => [ e, e.querySelector( 'span[aria-hidden="true"]' ) ].map( ( x ) => getComputedStyle( x ).backgroundColor ) );
			}, { timeout: 10_000, message: 'enlace rojo entero, flecha sin fondo propio' } ).toEqual( [ 'rgb(230, 0, 18)', 'rgba(0, 0, 0, 0)' ] );
		}
	} );
} );
