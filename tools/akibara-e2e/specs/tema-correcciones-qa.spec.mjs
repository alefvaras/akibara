// @ts-check
/**
 * Correcciones de la QA integral del tema (lote QF-gp, 2026-09-24).
 *
 * Cada prueba fija un hallazgo medido en el espejo ANTES del arreglo:
 *
 *  - QA-RV-1: a 375, 390 y 393 px, el foco de «Tomo anterior» quedaba entero
 *    bajo la barra de compra. El desplazamiento del propio foco hacía aparecer
 *    la barra, y la barra caía encima del enlace recién enfocado (390x844:
 *    enlace en y=726-770, barra en 716-778).
 *  - QA-RV-4: el buscador del 404 y el de facetas no llevaban el anillo
 *    amarillo: un `box-shadow: none !important` se lo quitaba.
 *  - QA-RV-5: en el carrito vacío, los «Añadir al carrito» de «Más vendidos»
 *    eran los únicos botones de compra de una tarjeta sin anillo, y con otro rojo.
 *  - QA-MOV-2: con «Ver carrito», el motivo del rechazo tapaba el botón durante
 *    6 s y el toque caía en el párrafo.
 *  - QA-MOV-6: el botón de WhatsApp, en reposo, tapaba el corazón de favoritos
 *    de las tarjetas de la columna derecha.
 *  - QA-ESC-1: en colores forzados, el ojo de «Vista rápida» desaparecía.
 *  - QA-ESC-2: al añadir con Enter en la ficha, el foco caía al <body> y,
 *    al cerrar el cajón con Escape, volvía al principio de la página.
 *  - QA-ESC-5: entre 636 y 649 px, la tarjeta enfocada con Tab en las vitrinas
 *    de la portada quedaba cortada hasta 13 px por la derecha.
 *  - QA-ESC-6: los errores que sólo detecta el servidor (un correo sin punto en
 *    el dominio, un campo con sólo espacios) salían como aviso general, sin
 *    marcar el campo, y tras cualquier envío el foco caía al <body>.
 *
 * Lo que escribe (carrito, formulario) sólo corre contra el espejo, y la sesión
 * de WooCommerce se borra al terminar (soporte/tema-espejo.mjs). El formulario
 * de contacto nunca manda un mensaje de verdad: el correo que el servidor
 * rechaza se corta antes de enviar nada, y el resto se simula con `page.route`.
 */

import { expect, test } from '@playwright/test';
import { cortarEnviosDeContacto, soloLectura } from './soporte/tienda.mjs';
import { borrarSesionWcAlTerminar } from './soporte/tema-espejo.mjs';

/** Ficha con «Tomo anterior» y barra de compra (existe en espejo y producción). */
const FICHA_CON_ANTERIOR = '/jujutsu-kaisen-24-panini-argentina/';

/** El amarillo del anillo de la casa, como lo serializa el navegador. */
const ANILLO = /rgba?\(255, 215, 0(, 1)?\)/;

/** Enfoca el control ANTERIOR en el orden de tabulación y pulsa Tab: foco de teclado de verdad (:focus-visible). */
async function tabularHasta( page, control ) {
	await control.evaluate( ( e ) => {
		const todos = [ ...document.querySelectorAll( 'a[href],button,input,select,textarea,summary,[tabindex]' ) ]
			.filter( ( x ) => ! x.disabled && x.tabIndex >= 0 && ( null !== x.offsetParent || 'fixed' === getComputedStyle( x ).position ) );
		const i = todos.indexOf( e );
		( todos[ i - 1 ] || document.body ).focus();
	} );
	await page.keyboard.press( 'Tab' );
	await page.waitForTimeout( 250 );
}

/** Sombra de foco y fondo del elemento activo. */
const estiloDelFoco = ( page ) => page.evaluate( () => {
	const e = document.activeElement;
	const c = getComputedStyle( /** @type {Element} */ ( e ) );
	return { id: e ? e.id : '', clase: e ? String( e.className ) : '', sombra: c.boxShadow, fondo: c.backgroundColor, teclado: !! e && e.matches( ':focus-visible' ) };
} );

test.describe( 'QA-RV-1: la barra de compra no tapa el control recién enfocado', () => {
	test( 'a 375, 390 y 393 px, «Tomo anterior» enfocado con Tab queda a la vista', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'la barra apilada sobre el bottom-nav es del teléfono' );
		for ( const vista of [ { width: 375, height: 812 }, { width: 390, height: 844 }, { width: 393, height: 852 } ] ) {
			await page.setViewportSize( vista );
			expect( ( await page.goto( FICHA_CON_ANTERIOR, { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			test.skip( ! ( await page.locator( 'a[aria-label^="Tomo anterior"]' ).count() ), 'la ficha ya no tiene tomo anterior' );

			let llego = false;
			for ( let i = 0; i < 60 && ! llego; i++ ) {
				await page.keyboard.press( 'Tab' );
				llego = await page.evaluate( () => /^Tomo anterior/.test( document.activeElement?.getAttribute( 'aria-label' ) || '' ) );
			}
			expect( llego, 'el teclado llega a «Tomo anterior»' ).toBe( true );
			// La barra aparece un cuadro después del desplazamiento y entra con su transición.
			await page.waitForTimeout( 600 );
			const medida = await page.evaluate( () => {
				const a = /** @type {HTMLElement} */ ( document.activeElement );
				const r = a.getBoundingClientRect();
				const encima = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
				const barra = document.getElementById( 'akb-sticky-atc' );
				return {
					libre: !! encima && ( encima === a || a.contains( encima ) ),
					encima: encima ? `${ encima.tagName }#${ encima.id }.${ String( encima.className ).split( ' ' )[ 0 ] }` : '',
					barra: !! barra && barra.classList.contains( 'is-visible' ),
					y: Math.round( r.top ),
				};
			} );
			expect( medida.libre, `${ vista.width }x${ vista.height }: «Tomo anterior» (y=${ medida.y }) quedaba bajo ${ medida.encima }` ).toBe( true );
		}
	} );
} );

test.describe( 'QA-RV-4 / QA-RV-5: anillo amarillo en los buscadores y en la venta cruzada del carrito', () => {
	test( 'el buscador del 404 lleva el anillo de la casa', async ( { page } ) => {
		expect( ( await page.goto( '/esta-pagina-no-existe-akbqa/', { waitUntil: 'load' } ) )?.status() ).toBe( 404 );
		const campo = page.locator( '#akb-404-search' );
		test.skip( ! ( await campo.count() ), 'el 404 ya no trae buscador' );
		await tabularHasta( page, campo );
		const foco = await estiloDelFoco( page );
		expect( foco.id ).toBe( 'akb-404-search' );
		expect( foco.sombra, 'QA-RV-4: el foco sólo aclaraba el borde' ).toMatch( ANILLO );
	} );

	test( 'el buscador de facetas lleva el anillo de la casa', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'las facetas del teléfono van en un cajón; el campo es el mismo' );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const campo = page.locator( '.akb-facet-search' ).first();
		test.skip( ! ( await campo.count() ), 'no hay buscador de facetas en /tienda/' );
		// El campo vive en una faceta plegada: se despliega en la página, sin pedir nada.
		await campo.evaluate( ( e ) => {
			for ( let n = e.parentElement; n && n !== document.body; n = n.parentElement ) {
				if ( n.hidden ) {
					n.hidden = false;
				}
			}
		} );
		await tabularHasta( page, campo );
		const foco = await estiloDelFoco( page );
		expect( foco.clase ).toContain( 'akb-facet-search' );
		expect( foco.sombra ).toMatch( ANILLO );
	} );

	test( 'carrito vacío: «Añadir al carrito» de «Más vendidos» lleva anillo y el rojo de compra', async ( { page, context } ) => {
		await context.clearCookies();
		expect( ( await page.goto( '/carrito/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const boton = page.locator( '.woocommerce-cart ul.products li.product .add_to_cart_button' ).first();
		test.skip( ! ( await boton.count() ), 'el carrito vacío no trae venta cruzada' );
		await tabularHasta( page, boton );
		expect( ( await estiloDelFoco( page ) ).clase ).toContain( 'add_to_cart_button' );
		// El botón transiciona fondo y sombra: se lee cuando termina.
		await expect.poll( async () => ( await estiloDelFoco( page ) ).sombra, { message: 'QA-RV-5: el foco sólo rellenaba de rojo, igual que el hover' } ).toMatch( /^rgba?\(255, 215, 0(, 1)?\) 0px 0px 0px 3px/ );
		await expect.poll( async () => ( await estiloDelFoco( page ) ).fondo, { message: 'el rojo de compra unificado (--aki-buy-bg), no #E60012' } ).toBe( 'rgb(204, 0, 16)' );
	} );
} );

/**
 * Un tomo de UNA unidad visible en /tienda/, ya puesto en el carrito: el
 * segundo alta se rechaza con `ya_en_carrito`. Se busca en vez de clavar un slug.
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
		await page.request.get( '/?wc-ajax=akb_get_cart&drain_notices=1' ).catch( () => {} );
		if ( segundo.error ) {
			return String( id );
		}
	}
	return null;
}

test.describe( 'QA-MOV-2 / QA-ESC-2: comprar desde la tarjeta y desde la ficha', () => {
	test.describe.configure( { mode: 'serial' } );
	borrarSesionWcAlTerminar();

	test( 'con «Ver carrito», el motivo queda encima del botón y el toque lleva al carrito sin esperar', async ( { page, context }, info ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const id = await tomoDeUnaUnidadEnElCarrito( page );
		test.skip( ! id, 'no hay un tomo de una unidad en la primera página de /tienda/' );

		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const boton = page.locator( `li.product .add_to_cart_button[data-product_id="${ id }"]` ).first();
		test.skip( ! ( await boton.count() ), 'el tomo cambió de página mientras corría la prueba' );
		// La tarjeta se marca en la página: tras el rechazo su botón deja de ser `.add_to_cart_button`.
		await boton.evaluate( ( b ) => b.closest( 'li.product' )?.setAttribute( 'data-akb-e2e', 'tarjeta' ) );
		await boton.scrollIntoViewIfNeeded();
		await page.waitForTimeout( 800 );
		await boton.click();

		const tarjeta = page.locator( 'li.product[data-akb-e2e="tarjeta"]' );
		const motivo = tarjeta.locator( '.akb-tarjeta-motivo' );
		await expect( motivo ).toBeVisible( { timeout: 15_000 } );
		const verCarrito = tarjeta.locator( 'a.akb-ver-carrito' );
		await expect( verCarrito ).toBeVisible();

		// Con el motivo todavía a la vista (dura 6 s): el centro del botón es el botón.
		const punto = await verCarrito.evaluate( ( a ) => {
			const r = a.getBoundingClientRect();
			const x = r.left + r.width / 2;
			const y = r.top + r.height / 2;
			const e = document.elementFromPoint( x, y );
			const m = /** @type {HTMLElement} */ ( a.closest( 'li.product' )?.querySelector( '.akb-tarjeta-motivo' ) );
			return { x, y, libre: !! e && ( e === a || a.contains( e ) ), encima: e ? String( e.className ) : '', motivoVisible: !! m && ! m.hidden };
		} );
		expect( punto.motivoVisible, 'se mide con el motivo a la vista' ).toBe( true );
		expect( punto.libre, `QA-MOV-2: el centro de «Ver carrito» era ${ punto.encima }` ).toBe( true );
		// Un toque (o clic) en ese punto, sin esperar a que el motivo se vaya.
		if ( 'movil' === info.project.name ) {
			await page.touchscreen.tap( punto.x, punto.y );
		} else {
			await page.mouse.click( punto.x, punto.y );
		}
		// Basta con que la navegación ARRANQUE: si el toque hubiera caído en el
		// motivo no habría navegación ninguna, ni siquiera cuando el motivo se va.
		await page.waitForURL( /carrito/, { waitUntil: 'commit', timeout: 15_000 } );
	} );

	test( 'añadir con Enter en la ficha y cerrar el cajón con Escape devuelve el foco al botón', async ( { page, context }, info ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		test.skip( 'movil' === info.project.name, 'recorrido de teclado de escritorio' );
		await context.clearCookies();
		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
		// Una ficha que se puede agregar (con el botón de agregar de su tarjeta).
		const href = await page.locator( 'li.product:has(.add_to_cart_button.ajax_add_to_cart) a.woocommerce-LoopProduct-link' ).first().getAttribute( 'href' );
		expect( href ).toBeTruthy();
		await page.goto( String( href ), { waitUntil: 'load' } );

		const real = page.locator( 'form.cart .single_add_to_cart_button' );
		await tabularHasta( page, real );
		await expect( real ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '.akb-cart-close' ).first(), 'el cajón se abre con el foco dentro' ).toBeFocused( { timeout: 15_000 } );
		await page.keyboard.press( 'Escape' );
		await expect( real, 'QA-ESC-2: el foco caía al <body> y el siguiente Tab iba a «Saltar al contenido»' ).toBeFocused();
	} );
} );

test.describe( 'QA-MOV-6: el botón de WhatsApp cede ante el corazón de favoritos', () => {
	test( 'en reposo sobre el corazón de la columna derecha, el toque llega al corazón', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'el botón cae sobre las tarjetas en el teléfono' );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		test.skip( ! ( await page.locator( '.joinchat' ).count() ), 'sin Joinchat no hay nada que tapar' );
		await page.locator( '.joinchat.joinchat--show' ).waitFor( { state: 'attached', timeout: 15_000 } );

		// Se lleva un corazón de la columna derecha justo bajo el botón de WhatsApp:
		// uno que esté más abajo que el botón, para que baste con bajar.
		const llevar = () => page.evaluate( () => {
			const fab = /** @type {Element} */ ( document.querySelector( '.joinchat__button' ) || document.querySelector( '.joinchat' ) ).getBoundingClientRect();
			const centro = fab.top + fab.height / 2;
			let corazon = document.querySelector( '[data-akb-e2e="corazon"]' );
			if ( ! corazon ) {
				corazon = [ ...document.querySelectorAll( 'li.product .akb-wl-toggle' ) ].find( ( c ) => {
					const r = c.getBoundingClientRect();
					return r.left > innerWidth / 2 && r.top + r.height / 2 > centro + 100;
				} ) || null;
				corazon?.setAttribute( 'data-akb-e2e', 'corazon' );
			}
			if ( ! corazon ) {
				return false;
			}
			const r = corazon.getBoundingClientRect();
			window.scrollBy( { top: r.top + r.height / 2 - centro, behavior: 'instant' } );
			return true;
		} );
		const ok = await llevar();
		test.skip( ! ok, 'no hay corazones en la columna derecha' );
		// La cabecera se compacta al pasar de 120 px de scroll y mueve la página: se corrige una vez.
		await page.waitForTimeout( 300 );
		await llevar();
		// main.js mira lo que hay debajo cuando el scroll se detiene (150 ms).
		await page.waitForTimeout( 600 );
		const punto = await page.evaluate( () => {
			const fab = /** @type {Element} */ ( document.querySelector( '.joinchat__button' ) || document.querySelector( '.joinchat' ) ).getBoundingClientRect();
			const c = /** @type {Element} */ ( document.querySelector( '[data-akb-e2e="corazon"]' ) ).getBoundingClientRect();
			// Centro de la zona en que se cruzan los dos.
			const x = ( Math.max( fab.left, c.left ) + Math.min( fab.right, c.right ) ) / 2;
			const y = ( Math.max( fab.top, c.top ) + Math.min( fab.bottom, c.bottom ) ) / 2;
			const e = document.elementFromPoint( x, y );
			return { cruzan: fab.left < c.right && c.left < fab.right && fab.top < c.bottom && c.top < fab.bottom, corazon: !! e && !! e.closest( '[data-akb-e2e="corazon"]' ), encima: e ? String( e.className ).slice( 0, 60 ) : '' };
		} );
		test.skip( ! punto.cruzan, 'a este ancho el botón de WhatsApp no pasa por la columna de los corazones' );
		expect( punto.corazon, `QA-MOV-6: el toque caía en ${ punto.encima }` ).toBe( true );
	} );
} );

test.describe( 'QA-ESC-1: el ojo de «Vista rápida» se ve en colores forzados', () => {
	for ( const esquema of /** @type {const} */ ( [ 'dark', 'light' ] ) ) {
		test( `alto contraste ${ esquema === 'dark' ? 'oscuro' : 'claro' }`, async ( { page }, info ) => {
			test.skip( 'movil' === info.project.name, 'en el teléfono la vista rápida no se pinta' );
			await page.emulateMedia( { forcedColors: 'active', colorScheme: esquema } );
			expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			const ojo = page.locator( 'ul.products li.product > .akb-qv-trigger:visible' ).first();
			await expect( ojo ).toBeVisible();
			await ojo.scrollIntoViewIfNeeded();
			// Sin la portada debajo: se mide sólo el glifo contra el fondo del botón.
			await ojo.evaluate( ( b ) => b.closest( 'li.product' )?.querySelectorAll( 'img' ).forEach( ( i ) => { i.style.visibility = 'hidden'; } ) );
			const r = /** @type {{x:number,y:number,width:number,height:number}} */ ( await ojo.boundingBox() );
			const png = await page.screenshot( { clip: { x: r.x + r.width / 2 - 11, y: r.y + r.height / 2 - 11, width: 22, height: 22 } } );
			// getComputedStyle no lo ve (el color forzado se aplica al pintar): se cuentan píxeles.
			const minoria = await page.evaluate( async ( b64 ) => {
				const img = new Image();
				img.src = `data:image/png;base64,${ b64 }`;
				await img.decode();
				const c = document.createElement( 'canvas' );
				c.width = img.width;
				c.height = img.height;
				const g = /** @type {CanvasRenderingContext2D} */ ( c.getContext( '2d' ) );
				g.drawImage( img, 0, 0 );
				const d = g.getImageData( 0, 0, c.width, c.height ).data;
				const cuenta = new Map();
				for ( let i = 0; i < d.length; i += 4 ) {
					const k = `${ d[ i ] },${ d[ i + 1 ] },${ d[ i + 2 ] }`;
					cuenta.set( k, ( cuenta.get( k ) || 0 ) + 1 );
				}
				const orden = [ ...cuenta.values() ].sort( ( a, b ) => b - a );
				return d.length / 4 - orden[ 0 ];
			}, png.toString( 'base64' ) );
			expect( minoria, 'QA-ESC-1: el centro del botón salía de un solo color (484 de 484 px)' ).toBeGreaterThan( 30 );
		} );
	}
} );

test.describe( 'QA-ESC-5: la vitrina sigue al foco también entre 636 y 649 px', () => {
	test( 'cada tarjeta enfocada con Tab queda entera dentro de la fila', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'se mide con la ventana de un portátil de 1280 al 200 %' );
		for ( const ancho of [ 636, 640, 645 ] ) {
			await page.setViewportSize( { width: ancho, height: 800 } );
			expect( ( await page.goto( '/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			// Primera vitrina de productos, desde un ancla enfocable justo antes.
			const hay = await page.evaluate( () => {
				const ul = document.querySelector( '.akb-section .woocommerce ul.products' );
				if ( ! ul || ul.scrollWidth <= ul.clientWidth ) {
					return false;
				}
				ul.scrollIntoView( { block: 'center' } );
				const ancla = document.createElement( 'button' );
				ancla.id = 'akb-e2e-ancla';
				ancla.style.cssText = 'position:absolute;opacity:0;width:1px;height:1px';
				ul.parentNode?.insertBefore( ancla, ul );
				ancla.focus( { preventScroll: true } );
				return true;
			} );
			test.skip( ! hay, 'la portada no tiene una vitrina deslizable a este ancho' );
			const cortadas = [];
			for ( let i = 0; i < 40; i++ ) {
				await page.keyboard.press( 'Tab' );
				const m = await page.evaluate( () => {
					const ul = /** @type {HTMLElement} */ ( document.querySelector( '.akb-section .woocommerce ul.products' ) );
					const a = document.activeElement;
					if ( ! a || ! ul.contains( a ) ) {
						return null;
					}
					const t = ( a.closest( 'li.product' ) || a ).getBoundingClientRect();
					const f = ul.getBoundingClientRect();
					return { corte: Math.round( Math.max( 0, f.left - t.left, t.right - f.right ) ), quien: ( a.getAttribute( 'aria-label' ) || a.textContent || '' ).trim().slice( 0, 30 ) };
				} );
				if ( ! m ) {
					break;
				}
				if ( m.corte > 0 ) {
					cortadas.push( `${ m.quien } (${ m.corte } px)` );
				}
			}
			expect( cortadas, `${ ancho } px: tarjetas enfocadas que la fila deja cortadas` ).toEqual( [] );
		}
	} );
} );

test.describe( 'QA-ESC-6: formulario de contacto, errores del servidor y foco', () => {
	/** @param {import('@playwright/test').Page} page */
	async function enviar( page, { nombre = 'Prueba QA', email = 'juan@correo.cl', mensaje = 'Prueba automática del tema: no responder.' } = {} ) {
		await page.fill( '#akb-c-nombre', nombre );
		await page.fill( '#akb-c-email', email );
		await page.fill( '#akb-c-mensaje', mensaje );
		await page.focus( '.akb-form__submit' );
		await page.keyboard.press( 'Enter' );
	}

	test( 'un correo que sólo rechaza el servidor marca SU campo, con el texto del panel, y lo enfoca', async ( { page }, info ) => {
		test.skip( soloLectura, 'envía el formulario: sólo contra el espejo' );
		test.skip( 'movil' === info.project.name, 'recorrido de teclado de escritorio' );
		expect( ( await page.goto( '/contacto/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const panel = await page.evaluate( () => /** @type {any} */ ( window ).AkibaraFormsConfig?.messages?.typeMismatch || '' );
		// «juan@correo» vale para type=email, pero el servidor exige punto en el
		// dominio: responde `invalid_email` antes de enviar nada.
		const respuesta = page.waitForResponse( ( r ) => /akibara\/v1\/contacto/.test( r.url() ) );
		await enviar( page, { email: 'juan@correo' } );
		expect( ( await respuesta ).status() ).toBe( 400 );
		const email = page.locator( '#akb-c-email' );
		await expect( email ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( email, 'el foco va al campo que hay que corregir' ).toBeFocused();
		await expect( page.locator( '#akb-c-email-error' ) ).toHaveText( panel || /.+/ );
		await expect( page.locator( '.akb-form__feedback' ), 'sin el aviso general «Email inválido.»' ).toHaveText( '' );
	} );

	test( 'con la red caída o con éxito, el foco se queda en el botón', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'recorrido de teclado de escritorio' );
		expect( ( await page.goto( '/contacto/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const boton = page.locator( '.akb-form__submit' );
		// Se envía con datos válidos: si akibara-forms.js no enganchara, el envío nativo
		// a admin-post.php mandaría el correo de verdad. Cortado aquí, no sale nada.
		const cortados = await cortarEnviosDeContacto( page );

		// Red caída: nada sale del navegador.
		await page.route( '**/wp-json/akibara/v1/contacto*', ( r ) => r.abort() );
		await enviar( page );
		await expect( page.locator( '.akb-form__feedback' ) ).toHaveAttribute( 'data-state', 'error' );
		await expect( boton, 'QA-ESC-6: el botón deshabilitado soltaba el foco al <body>' ).toBeFocused();
		await expect( boton ).not.toHaveAttribute( 'aria-disabled', 'true' );
		await page.unroute( '**/wp-json/akibara/v1/contacto*' );

		// Éxito simulado (no se envía ningún correo), con respuesta lenta: un
		// segundo Enter durante el envío no manda otra petición.
		let peticiones = 0;
		await page.route( '**/wp-json/akibara/v1/contacto*', async ( r ) => {
			peticiones++;
			await new Promise( ( z ) => setTimeout( z, 800 ) );
			await r.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { code: 'ok', message: 'Recibido.' } ) } );
		} );
		await enviar( page );
		await expect( boton, 'en vuelo se anuncia como no disponible' ).toHaveAttribute( 'aria-disabled', 'true' );
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '.akb-form__feedback' ) ).toHaveAttribute( 'data-state', 'success' );
		await expect( boton ).toBeFocused();
		expect( peticiones, 'un solo envío' ).toBe( 1 );
		expect( cortados, 'el envío salió por un camino que la prueba no contestaba' ).toEqual( [] );
	} );
} );
