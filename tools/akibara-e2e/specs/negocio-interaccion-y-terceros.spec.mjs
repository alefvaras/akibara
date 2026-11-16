// @ts-check
/**
 * Interacción y terceros (lote B5, auditoría del tema 2026-09-22).
 *
 *  - AI-2: el «Cargando…» del cajón sólo pulsa con el cajón ABIERTO. Cerrado, era
 *    una animación infinita en cada página: ~60 recálculos de estilo por segundo
 *    sin que el cliente viera nada.
 *  - Badge de stock de la ficha: quieto. Su pulso era una sombra hacia fuera que
 *    el clip-path del badge recortaba entera (invisible en WebKit, un borde de
 *    6/255 en Chromium). Se retiró (revisión B5-R5).
 *  - UM-9: el corazón de un invitado navega al acceso como siempre, salvo que el
 *    dueño encienda el aviso en el panel (apagado de fábrica, revisión B5-R2).
 *    Encendido: aviso en la página; «Seguir mirando» no anota nada; «Iniciar
 *    sesión» anota el tomo y lleva al acceso con vuelta a la ficha. Tocar junto
 *    al texto no lo cierra; tocar el fondo sí (B5-R3). El aviso se prueba
 *    forzándolo en ESTE navegador: la base no se toca.
 *  - UM-5: un mensaje de la barra sin enlace no se viste de enlace, y deslizar
 *    sobre un mensaje-enlace cambia de anuncio sin navegar (B5-R4).
 *  - PM-2: con «GA4 y Clarity después de la carga» apagado (de fábrica) las
 *    etiquetas salen como siempre; encendido, salen inertes con su cargador. El
 *    espejo no imprime ninguna de las dos (sólo akibara.cl): ahí se salta.
 *
 * Nada de esto escribe en el sitio: corre también contra producción.
 */

import { expect, test } from '@playwright/test';
import { productoConBadge } from './soporte/tienda.mjs';

test.describe( 'interacción y terceros (negocio)', () => {
	test( 'el «Cargando…» del cajón no anima con el cajón cerrado y sí al abrirlo', async ( { page } ) => {
		test.skip( 'reduce' === ( await page.evaluate( () => ( matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'reduce' : '' ) ) ), 'con movimiento reducido no hay pulso' );
		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		// La lectura del carrito tarda: así el «Cargando…» dura lo bastante para medirlo.
		await page.route( /akb_get_cart/, async ( route ) => {
			await new Promise( ( r ) => setTimeout( r, 2000 ) );
			await route.continue();
		} );
		const respuesta = await page.goto( String( url ), { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );
		await expect( page.locator( '#akb-native-cart-drawer .akb-cart-loading' ) ).toHaveCount( 1 );

		const pulsos = () => page.evaluate( () => document.getAnimations().filter( ( a ) => 'akb-cart-loading-pulse' === /** @type {any} */ ( a ).animationName && 'running' === a.playState ).length );
		expect( await pulsos(), 'cajón cerrado: nada que animar' ).toBe( 0 );

		await page.evaluate( () => /** @type {any} */ ( window ).akbCart.open() );
		await expect( page.locator( '.akb-cart-drawer.akb-open' ) ).toHaveCount( 1 );
		await expect.poll( pulsos, { message: 'abierto y cargando: el pulso se ve' } ).toBe( 1 );
	} );

	/**
	 * QA integral 2026-09-24 (QA-SE-2): esta prueba buscaba `.akb-badge-panel`, el
	 * panel verde «Disponibilidad · Despacha en 24–48 h» que el propio lote retiró
	 * de las fichas en stock (DS-9), así que salía SIEMPRE saltada con un motivo
	 * engañoso. Ahora mira lo que queda de cada ficha, elegida a propósito: el
	 * panel de la ficha «a pedido» (`.akb-badge-panel--preventa`) y la línea de
	 * despacho de la ficha disponible (`.akb-despacho`, UC-2). Si falta, FALLA:
	 * en una ficha elegida por su estado, no encontrarlo es el defecto.
	 */
	for ( const caso of [
		{ estado: 'Disponible', selector: '.akb-despacho', que: 'la línea de despacho' },
	] ) {
		test( `${ caso.que } de la ficha no anima: ni al cargar ni al llegar a él`, async ( { page } ) => {
			// Sin movimiento reducido: es donde el pulso existía.
			await page.emulateMedia( { reducedMotion: 'no-preference' } );
			const url = await productoConBadge( page, caso.estado );
			test.skip( ! url, `sin tomos «${ caso.estado }» en este entorno` );
			const respuesta = await page.goto( String( url ), { waitUntil: 'load' } );
			expect( respuesta?.status() ).toBe( 200 );
			const pieza = page.locator( caso.selector ).first();
			await expect( pieza, `una ficha «${ caso.estado }» pinta ${ caso.que }` ).toHaveCount( 1 );

			const animaciones = () => pieza.evaluate( ( b ) => b.getAnimations().map( ( a ) => /** @type {any} */ ( a ).animationName || 'otra' ) );
			expect( await animaciones(), 'al cargar' ).toEqual( [] );
			await pieza.scrollIntoViewIfNeeded();
			expect( await animaciones(), 'al llegar a él' ).toEqual( [] );
			expect( await pieza.evaluate( ( b ) => getComputedStyle( b ).animationName ) ).toBe( 'none' );
		} );
	}

	/**
	 * Aviso del corazón forzado en ESTE navegador: el `var akbWishlist = {…}`
	 * localizado pasa por un setter que enciende `guestPrompt`. Así se prueba el
	 * aviso con la opción como esté (apagada de fábrica) y también contra
	 * producción, sin escribir nada.
	 *
	 * @param {import('@playwright/test').BrowserContext} context
	 */
	async function forzarAviso( context ) {
		await context.addInitScript( () => {
			/** @type {any} */
			let v;
			Object.defineProperty( window, 'akbWishlist', {
				configurable: true,
				get: () => v,
				set: ( x ) => {
					v = x;
					if ( x && 'object' === typeof x ) {
						x.guestPrompt = true;
					}
				},
			} );
		} );
	}

	/**
	 * Toque de verdad: con pantalla táctil, `tap`; si no, clic.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {number} x
	 * @param {number} y
	 */
	async function tocar( page, x, y ) {
		if ( test.info().project.use.hasTouch ) {
			await page.touchscreen.tap( x, y );
		} else {
			await page.mouse.click( x, y );
		}
	}

	test( 'corazón de invitado: con el aviso apagado (de fábrica) navega al acceso con el tomo anotado', async ( { page, context } ) => {
		await context.clearCookies();
		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.goto( String( url ), { waitUntil: 'load' } );
		const corazon = page.locator( '.akb-wl-toggle--single' ).first();
		test.skip( 'dialog' === ( await corazon.getAttribute( 'aria-haspopup' ) ), 'el dueño encendió el aviso: lo cubre la prueba siguiente' );

		await Promise.all( [
			page.waitForURL( /\/mi-cuenta\/\?redirect_to=/ ),
			corazon.click(),
		] );
		expect( decodeURIComponent( new URL( page.url() ).searchParams.get( 'redirect_to' ) || '' ) ).toBe( String( url ) );
		const intencion = await page.evaluate( () => sessionStorage.getItem( 'akbWishlistPending' ) );
		expect( intencion, 'el tomo queda anotado para guardarse al volver' ).toContain( '"productId"' );
	} );

	test( 'corazón de invitado con el aviso encendido: aviso en la página, sin salir de la ficha', async ( { page, context } ) => {
		await context.clearCookies();
		await forzarAviso( context );
		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.goto( String( url ), { waitUntil: 'load' } );
		const corazon = page.locator( '.akb-wl-toggle--single' ).first();
		const nombre = await corazon.getAttribute( 'data-name' );

		await corazon.click();
		const aviso = page.getByRole( 'dialog', { name: 'Inicia sesión para guardar tus favoritos' } );
		await expect( aviso ).toBeVisible();
		expect( page.url(), 'el cliente sigue en la ficha' ).toBe( String( url ) );
		await expect( aviso ).toContainText( `«${ nombre }»` );
		const entrar = aviso.getByRole( 'link', { name: 'Iniciar sesión o crear cuenta' } );
		await expect( entrar ).toBeFocused();
		const caja = await entrar.boundingBox();
		expect( caja && caja.height ).toBeGreaterThanOrEqual( 44 );
		expect( await page.evaluate( () => sessionStorage.getItem( 'akbWishlistPending' ) ), 'abrir el aviso no anota nada' ).toBeNull();

		// Escape cierra y devuelve el foco al corazón.
		await page.keyboard.press( 'Escape' );
		await expect( aviso ).toBeHidden();
		await expect( corazon ).toBeFocused();

		// «Seguir mirando» cierra sin anotar.
		await corazon.click();
		await aviso.getByRole( 'button', { name: 'Seguir mirando' } ).click();
		await expect( aviso ).toBeHidden();
		expect( await page.evaluate( () => sessionStorage.getItem( 'akbWishlistPending' ) ) ).toBeNull();
		expect( page.url() ).toBe( String( url ) );

		// «Iniciar sesión» anota el tomo y lleva al acceso con vuelta a la ficha.
		await corazon.click();
		await Promise.all( [
			page.waitForURL( /\/mi-cuenta\/\?redirect_to=/ ),
			aviso.getByRole( 'link', { name: 'Iniciar sesión o crear cuenta' } ).click(),
		] );
		expect( decodeURIComponent( new URL( page.url() ).searchParams.get( 'redirect_to' ) || '' ) ).toBe( String( url ) );
		const intencion = await page.evaluate( () => sessionStorage.getItem( 'akbWishlistPending' ) );
		expect( intencion, 'el tomo queda anotado para guardarse al volver' ).toContain( '"productId"' );
	} );

	test( 'aviso del corazón: tocar junto al texto no lo cierra; tocar el fondo sí', async ( { page, context } ) => {
		await context.clearCookies();
		await forzarAviso( context );
		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.goto( String( url ), { waitUntil: 'load' } );
		const corazon = page.locator( '.akb-wl-toggle--single' ).first();
		await corazon.click();
		const aviso = page.locator( 'dialog.akb-wl-login' );
		await expect( aviso ).toBeVisible();

		// Puntos de la caja visible cuyo blanco es el propio <dialog> (su relleno),
		// no un hijo: ahí es donde el aviso se cerraba solo (B5-R3).
		const puntos = await aviso.evaluate( ( d ) => {
			const r = d.getBoundingClientRect();
			const p = /** @type {HTMLElement} */ ( d.querySelector( '.akb-wl-login__body' ) ).getBoundingClientRect();
			return [
				[ r.left + 8, p.top + p.height / 2 ],
				[ r.right - 8, p.top + p.height / 2 ],
				[ p.left + p.width / 2, p.top - 4 ],
			].map( ( [ x, y ] ) => ( { x: Math.round( x ), y: Math.round( y ), blanco: document.elementFromPoint( Math.round( x ), Math.round( y ) )?.tagName } ) );
		} );
		for ( const pt of puntos ) {
			expect( pt.blanco, `(${ pt.x }, ${ pt.y }) cae en el relleno del aviso` ).toBe( 'DIALOG' );
			await tocar( page, pt.x, pt.y );
			await page.waitForTimeout( 150 );
			await expect( aviso, `tocar en (${ pt.x }, ${ pt.y }), dentro de la caja, no lo cierra` ).toBeVisible();
		}

		// El fondo, arriba a la izquierda: fuera de la hoja en móvil y de la caja en escritorio.
		await tocar( page, 12, 40 );
		await expect( aviso ).toBeHidden();
		await expect( corazon ).toBeFocused();
		expect( page.url() ).toBe( String( url ) );
	} );

	test( 'barra de anuncios: un mensaje sin enlace no se viste de enlace', async ( { page } ) => {
		const respuesta = await page.goto( '/', { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );
		const mensajes = await page.evaluate( () => [ ...document.querySelectorAll( '#akb-ann-bar .akb-ann-msg' ) ].map( ( m ) => {
			const interior = /** @type {HTMLElement} */ ( m.querySelector( '.akb-ann-msg-inner' ) );
			return {
				enlace: m.classList.contains( 'akb-ann-msg--link' ),
				esA: 'A' === interior.tagName,
				href: interior.getAttribute( 'href' ),
				cursor: getComputedStyle( interior ).cursor,
				texto: interior.textContent || '',
			};
		} ) );
		test.skip( ! mensajes.length, 'la barra está apagada' );
		for ( const m of mensajes ) {
			expect( m.texto, 'la dirección del enlace nunca se ve' ).not.toMatch( /\|\s*(\/|https?:)/ );
			if ( m.enlace ) {
				expect( m.esA && !! m.href ).toBe( true );
				expect( m.cursor ).toBe( 'pointer' );
			} else if ( ! m.esA ) {
				expect( m.cursor, 'sin enlace no hay mano ni cursor de texto' ).toBe( 'default' );
			}
		}
	} );

	test( 'barra de anuncios: deslizar sobre un mensaje-enlace cambia de anuncio y no navega', async ( { page, browserName } ) => {
		test.skip( ! test.info().project.use.hasTouch, 'el deslizamiento es de pantallas táctiles' );
		test.skip( 'chromium' !== browserName, 'el gesto táctil real se envía por CDP' );
		// Sin rotación automática: el contador sólo se mueve con el gesto.
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		const respuesta = await page.goto( '/', { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );
		// El mensaje activo pasa a mensaje-enlace con el mismo marcado que imprime
		// render_bar() para «Texto | /url/»: la cinta entera es el <a>. Se hace en
		// ESTE navegador para no tocar la barra del sitio (la leen otras pruebas).
		const preparado = await page.evaluate( () => {
			const bar = document.getElementById( 'akb-ann-bar' );
			const m = bar && bar.querySelector( '.akb-ann-msg.akb-on' );
			const interior = m && m.querySelector( 'span.akb-ann-msg-inner' );
			if ( ! interior || bar.querySelectorAll( '.akb-ann-msg' ).length < 2 ) {
				return false;
			}
			const a = document.createElement( 'a' );
			a.className = 'akb-ann-msg-inner';
			a.href = '/envios/';
			a.innerHTML = interior.innerHTML;
			m.classList.add( 'akb-ann-msg--link' );
			interior.replaceWith( a );
			return true;
		} );
		test.skip( ! preparado, 'la barra está apagada o tiene un solo mensaje' );

		const contador = page.locator( '#akb-ann-bar [data-akb-ann-count]' );
		const antes = await contador.textContent();
		const enlace = page.locator( '#akb-ann-bar .akb-ann-msg.akb-on a.akb-ann-msg-inner' );
		const caja = await enlace.boundingBox();
		expect( caja ).not.toBeNull();
		const x = Math.round( /** @type {any} */ ( caja ).x + /** @type {any} */ ( caja ).width / 2 );
		const y = Math.round( /** @type {any} */ ( caja ).y + /** @type {any} */ ( caja ).height / 2 );
		expect( await page.evaluate( ( [ px, py ] ) => document.elementFromPoint( px, py )?.closest( 'a' )?.getAttribute( 'href' ), [ x, y ] ), 'el gesto empieza sobre el enlace' ).toBe( '/envios/' );

		const cdp = await page.context().newCDPSession( page );
		await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [ { x, y } ] } );
		for ( let i = 1; i <= 5; i++ ) {
			await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [ { x: x - 25 * i, y } ] } );
		}
		await cdp.send( 'Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] } );

		await expect( contador, 'la cinta es enlace de borde a borde: el gesto tiene que valer ahí' ).not.toHaveText( String( antes ) );
		await page.waitForTimeout( 500 );
		expect( new URL( page.url() ).pathname, 'deslizar no navega' ).toBe( '/' );
	} );

	test( 'GA4 y Clarity: como siempre con el interruptor apagado; inertes y con cargador encendido', async ( { page } ) => {
		const respuesta = await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status() ).toBe( 200 );
		const html = await respuesta?.text() || '';
		const gtag = html.match( /<script[^>]*id="google_gtagjs"[^>]*>/ );
		test.skip( ! gtag, 'este entorno no imprime GA4 (sólo akibara.cl)' );
		const etiqueta = String( gtag?.[ 0 ] );
		if ( /type="text\/plain"/.test( etiqueta ) ) {
			expect( etiqueta ).toMatch( /data-akb-after-load="https:\/\/www\.googletagmanager\.com\/gtag\/js/ );
			expect( etiqueta ).not.toMatch( /\ssrc=/ );
			expect( html ).toMatch( /id="akb-analytics-after-load"/ );
			expect( html ).toMatch( /id="akb-clarity-queue"/ );
			// Tras cargar, cada etiqueta llega una sola vez.
			await page.waitForLoadState( 'load' );
			await expect.poll( () => page.evaluate( () => document.querySelectorAll( 'script[src*="googletagmanager.com/gtag/js"]' ).length ), { timeout: 15_000 } ).toBe( 1 );
			expect( await page.evaluate( () => typeof /** @type {any} */ ( window ).clarity ) ).toBe( 'function' );
		} else {
			expect( etiqueta ).toMatch( /src="https:\/\/www\.googletagmanager\.com\/gtag\/js/ );
			expect( html ).not.toMatch( /id="akb-analytics-after-load"/ );
		}
	} );
} );
