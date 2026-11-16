// @ts-check
/**
 * Hilo principal e INP (lote T3, auditoría del tema 2026-09-22).
 *
 *  - PC-8/PM-11: fuera de la portada, GSAP + ScrollTrigger + animations.js ya no
 *    viajan en el HTML; los inyecta animations-loader.js tras `load` o a la
 *    primera interacción. Medido en el espejo (móvil, CPU x4, 1,6 Mbps/150 ms):
 *    LCP de la ficha 5.636 → 5.016 ms. El «vuela al carrito» y el cajón tienen
 *    que seguir funcionando en el PRIMER clic, incluso antes de que llegue GSAP.
 *  - AI-1/PC-9: la marquesina de editoriales no anima fuera de pantalla.
 *  - AI-3: tiene un botón de pausa (WCAG 2.2.2) que aguanta salir y volver, y
 *    que en colores forzados (alto contraste de Windows) sigue mostrando su icono
 *    y su foco (T3-R1: era una caja vacía).
 *  - PC-11: el cajón del menú aplica/suelta el `inert` después del pintado; el
 *    foco sigue llegando a su sitio.
 *
 * Lo que agrega al carrito escribe (una línea en la sesión de la prueba): sólo
 * contra el espejo. El resto es lectura y corre también contra producción.
 */

import { expect, test } from '@playwright/test';
import { productoConBadge, soloLectura } from './soporte/tienda.mjs';

const GSAP_EN_HTML = /<script[^>]+(vendor\/gsap\.min\.js|vendor\/ScrollTrigger\.min\.js|\/assets\/js\/animations\.js)/;

test.describe( 'hilo principal (tema)', () => {
	test( 'la ficha no trae GSAP en el HTML y lo carga después, una sola vez', async ( { page } ) => {
		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		const respuesta = await page.goto( String( url ), { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );
		const html = await respuesta?.text() || '';
		expect( html, 'GSAP compite con el LCP de la ficha' ).not.toMatch( GSAP_EN_HTML );
		expect( html ).toMatch( /id="akibara-animations-loader-js"/ );
		expect( html ).toMatch( /data-akb-diferidos="/ );

		await page.waitForFunction( () => !! ( window.akbAnimations && window.gsap && window.ScrollTrigger ), null, { timeout: 15_000 } );
		const copias = await page.evaluate( () => [ ...document.scripts ].filter( ( s ) => /vendor\/gsap\.min\.js/.test( s.src ) ).length );
		expect( copias, 'dos GSAP se pisarían window.gsap' ).toBe( 1 );
	} );

	test( 'la portada sí trae GSAP en el HTML (el intro del hero corre al cargar)', async ( { page } ) => {
		const respuesta = await page.goto( '/', { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status() ).toBe( 200 );
		expect( await respuesta?.text() || '' ).toMatch( GSAP_EN_HTML );
	} );

	test( 'agregar desde la ficha antes de que llegue GSAP: vuela y abre el cajón en el primer clic', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const url = await productoConBadge( page, 'Disponible', true );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );

		// GSAP llega 400 ms tarde: el clic tiene que ganarle a la librería.
		await page.route( /vendor\/gsap\.min\.js/, async ( route ) => {
			await new Promise( ( r ) => setTimeout( r, 400 ) );
			await route.continue();
		} );
		await page.goto( String( url ), { waitUntil: 'domcontentloaded' } );
		await page.evaluate( () => {
			window.__vuelos = 0;
			new MutationObserver( ( ms ) => {
				for ( const m of ms ) {
					for ( const n of m.addedNodes ) {
						if ( n instanceof Element && n.classList.contains( 'akb-fly-clone' ) ) {
							window.__vuelos++;
						}
					}
				}
			} ).observe( document.body, { childList: true } );
		} );
		expect( await page.evaluate( () => typeof window.gsap ) ).toBe( 'undefined' );

		await page.locator( 'form.cart .single_add_to_cart_button' ).first().click();
		await expect( page.locator( '.akb-cart-drawer.akb-open' ) ).toBeVisible( { timeout: 15_000 } );
		expect( await page.evaluate( () => window.__vuelos ), 'la portada vuela al carrito' ).toBeGreaterThanOrEqual( 1 );
	} );

	test( 'si GSAP no llega, el cajón abre igual', async ( { page, context } ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const url = await productoConBadge( page, 'Disponible', true );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.route( /vendor\/gsap\.min\.js/, ( route ) => route.abort() );
		await page.goto( String( url ), { waitUntil: 'load' } );
		await page.locator( 'form.cart .single_add_to_cart_button' ).first().click();
		await expect( page.locator( '.akb-cart-drawer.akb-open' ) ).toBeVisible( { timeout: 15_000 } );
	} );

	test( 'la marquesina: quieta fuera de pantalla, anda a la vista y se pausa con su botón', async ( { page } ) => {
		await page.goto( '/', { waitUntil: 'load' } );
		const pista = page.locator( '.akb-brands-home__track.is-marquee' );
		await expect( pista ).toHaveCount( 1, { timeout: 15_000 } );
		const x = () => page.evaluate( () => new DOMMatrix( getComputedStyle( document.querySelector( '.akb-brands-home__track' ) ).transform ).m41 );

		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await page.waitForTimeout( 400 );
		const a = await x();
		await page.waitForTimeout( 1500 );
		expect( Math.abs( ( await x() ) - a ), 'fuera de pantalla no anima' ).toBeLessThan( 0.5 );

		await page.locator( '.akb-brands-home' ).scrollIntoViewIfNeeded();
		await page.waitForTimeout( 300 );
		const b = await x();
		await page.waitForTimeout( 1500 );
		expect( Math.abs( ( await x() ) - b ), 'a la vista anda' ).toBeGreaterThan( 20 );

		const boton = page.getByRole( 'button', { name: 'Pausar el carrusel de editoriales' } );
		await expect( boton ).toHaveAttribute( 'aria-pressed', 'false' );
		const caja = await boton.boundingBox();
		expect( caja && Math.min( caja.width, caja.height ) ).toBeGreaterThanOrEqual( 44 );
		await boton.click();
		await expect( boton ).toHaveAttribute( 'aria-pressed', 'true' );
		const c = await x();
		await page.waitForTimeout( 2000 );
		expect( Math.abs( ( await x() ) - c ), 'pausada no se mueve' ).toBeLessThan( 0.5 );

		// Salir y volver no la reanuda: sólo el botón la reanuda.
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		await page.waitForTimeout( 400 );
		await page.locator( '.akb-brands-home' ).scrollIntoViewIfNeeded();
		const d = await x();
		await page.waitForTimeout( 1200 );
		expect( Math.abs( ( await x() ) - d ) ).toBeLessThan( 0.5 );

		await boton.focus();
		await page.keyboard.press( 'Enter' );
		await expect( boton ).toHaveAttribute( 'aria-pressed', 'false' );
	} );

	test( 'con reducir movimiento la sección es una grilla quieta y sin botón', async ( { browser } ) => {
		const contexto = await browser.newContext( { reducedMotion: 'reduce' } );
		const pagina = await contexto.newPage();
		await pagina.goto( '/', { waitUntil: 'load' } );
		await pagina.waitForTimeout( 1000 );
		await expect( pagina.locator( '.akb-brands-home__track.is-marquee' ) ).toHaveCount( 0 );
		await expect( pagina.locator( '.akb-brands-home__pause' ) ).toHaveCount( 0 );
		await contexto.close();
	} );

	test( 'el cajón del menú: inerte detrás, foco dentro, y al cerrar todo vuelve', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'el menú hamburguesa es móvil' );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.locator( '#menu-toggle' ).tap();
		await expect( page.locator( '#main-content' ) ).toHaveAttribute( 'inert', '' );
		await expect( page.locator( '#mobile-close' ) ).toBeFocused();

		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '#main-content' ) ).not.toHaveAttribute( 'inert', '' );
		await expect( page.locator( '#menu-toggle' ) ).toBeFocused();

		// La hoja de búsqueda: el campo recibe el foco y «Cancelar» lo devuelve al tab.
		await page.locator( '#bottom-nav-search' ).tap();
		await expect( page.locator( '#akb-mobile-search-input' ) ).toBeFocused();
		await page.locator( '#akb-search-cancel' ).tap();
		await expect( page.locator( '#bottom-nav-search' ) ).toBeFocused();
		await expect( page.locator( '.bottom-nav' ) ).not.toHaveAttribute( 'inert', '' );
	} );

	test( 'la barra de progreso avanza con transform, no con el ancho', async ( { page } ) => {
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.evaluate( () => window.scrollTo( 0, ( document.documentElement.scrollHeight - innerHeight ) / 2 ) );
		await page.waitForTimeout( 400 );
		const barra = await page.evaluate( () => {
			const b = document.getElementById( 'aki-scroll-progress' );
			return { transform: b.style.transform, ancho: b.getBoundingClientRect().width, vw: innerWidth };
		} );
		expect( barra.transform ).toMatch( /^scaleX\(0\.\d+\)$/ );
		expect( barra.ancho / barra.vw ).toBeGreaterThan( 0.3 );
		expect( barra.ancho / barra.vw ).toBeLessThan( 0.7 );
	} );
} );

/**
 * Decodifica un recorte de pantalla en una pestaña en blanco del mismo contexto
 * (sin la CSP de la tienda) y devuelve la luminancia de cada píxel.
 */
async function luminancias( page, clip ) {
	const png = await page.screenshot( { clip } );
	const hoja = await page.context().newPage();
	const res = await hoja.evaluate( async ( b64 ) => {
		const bin = atob( b64 );
		const u8 = new Uint8Array( bin.length );
		for ( let i = 0; i < bin.length; i++ ) {
			u8[ i ] = bin.charCodeAt( i );
		}
		const bmp = await createImageBitmap( new Blob( [ u8 ], { type: 'image/png' } ) );
		const lienzo = new OffscreenCanvas( bmp.width, bmp.height );
		const g = lienzo.getContext( '2d' );
		g.drawImage( bmp, 0, 0 );
		const d = g.getImageData( 0, 0, bmp.width, bmp.height ).data;
		const l = [];
		for ( let i = 0; i < d.length; i += 4 ) {
			l.push( 0.2126 * d[ i ] + 0.7152 * d[ i + 1 ] + 0.0722 * d[ i + 2 ] );
		}
		return { w: bmp.width, h: bmp.height, l };
	}, png.toString( 'base64' ) );
	await hoja.close();
	return res;
}

test.describe( 'hilo principal (tema) — colores forzados', () => {
	// Alto contraste de Windows: el navegador quita degradados y reemplaza fondos.
	// El icono del botón está hecho de fondos, así que se mide por PÍXELES, no por
	// estilos: antes del arreglo el recorte del icono era de un solo color.
	// Van por `contextOptions`: esta versión de Playwright no los expone como
	// opciones sueltas de `test.use` (medido: ahí se ignoraban sin avisar).
	test.use( { contextOptions: { forcedColors: 'active', reducedMotion: 'no-preference' } } );

	test( 'el botón de pausa muestra pausar, reanudar y su foco', async ( { page } ) => {
		await page.goto( '/', { waitUntil: 'load' } );
		// Sin esto la prueba pasaría también con colores normales, donde el icono ya se ve.
		expect( await page.evaluate( () => matchMedia( '(forced-colors: active)' ).matches ), 'colores forzados activos' ).toBe( true );
		await expect( page.locator( '.akb-brands-home__track.is-marquee' ) ).toHaveCount( 1, { timeout: 15_000 } );
		await page.locator( '.akb-brands-home' ).scrollIntoViewIfNeeded();
		const boton = page.getByRole( 'button', { name: 'Pausar el carrusel de editoriales' } );
		const icono = page.locator( '.akb-brands-home__pause-icon' );
		await page.mouse.move( 0, 0 );

		const medir = async () => {
			await page.waitForTimeout( 400 );
			const caja = await icono.boundingBox();
			const { w, h, l } = await luminancias( page, {
				x: Math.floor( caja.x ), y: Math.floor( caja.y ), width: Math.ceil( caja.width ), height: Math.ceil( caja.height ),
			} );
			const fila = Math.floor( h / 2 );
			return {
				rango: Math.max( ...l ) - Math.min( ...l ),
				izquierda: l[ fila * w + 1 ],
				centro: l[ fila * w + Math.floor( w / 2 ) ],
			};
		};

		// Andando: dos barras, con el hueco claro/oscuro en medio.
		const pausar = await medir();
		expect( pausar.rango, 'el icono de pausar se ve (no es una caja vacía)' ).toBeGreaterThan( 100 );
		expect( Math.abs( pausar.izquierda - pausar.centro ), 'barra a la izquierda, hueco en el centro' ).toBeGreaterThan( 100 );

		// Pausada: el triángulo llena el centro a media altura.
		await boton.click();
		await expect( boton ).toHaveAttribute( 'aria-pressed', 'true' );
		await page.mouse.move( 0, 0 );
		await page.evaluate( () => document.activeElement && document.activeElement.blur() );
		const reanudar = await medir();
		expect( reanudar.rango, 'el icono de reanudar se ve' ).toBeGreaterThan( 100 );
		expect( Math.abs( reanudar.izquierda - reanudar.centro ), 'triángulo: izquierda y centro pintados' ).toBeLessThan( 60 );

		// Foco de teclado: el amarillo no llega en colores forzados; tiene que haber contorno.
		await page.locator( '.akb-brands-home__more' ).focus();
		await page.keyboard.press( 'Shift+Tab' );
		await expect( boton ).toBeFocused();
		const contorno = await boton.evaluate( ( el ) => {
			const s = getComputedStyle( el );
			return { estilo: s.outlineStyle, ancho: parseFloat( s.outlineWidth ) };
		} );
		expect( contorno.estilo ).not.toBe( 'none' );
		expect( contorno.ancho ).toBeGreaterThanOrEqual( 2 );
		await page.keyboard.press( 'Enter' );
		await expect( boton ).toHaveAttribute( 'aria-pressed', 'false' );
	} );
} );
