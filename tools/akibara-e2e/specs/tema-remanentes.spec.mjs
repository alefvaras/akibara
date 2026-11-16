// @ts-check
/**
 * Remanentes del tema (lote R-T, 2026-09-23): los defectos que las revisiones de
 * T1-T7 e I1 dejaron abiertos, cada uno con una prueba que fallaba antes del
 * arreglo.
 *
 * - Paginación: el «1» y el «←» de la página 2 enlazaban a /page/1/, que redirige
 *   (301) a la base. 76 archivos en producción.
 * - Colores forzados: las preguntas frecuentes de la ficha no mostraban foco
 *   (REV-T6-3-1), el salto al contenido enmarcaba el <main> entero (REV-T6-3-2) y
 *   el foco del botón de pausa de Editoriales perdía su lado de arriba entre 753
 *   y 1.233 px (T3-R2).
 * - Menú: con el ratón sobre Manga, abrir Cómics con el teclado dejaba los dos
 *   paneles pintados (R2-T5-C1).
 * - Portada: entre 560 y 650 px la fila deslizable no seguía al foco (el encaje
 *   de `scroll-snap` la devolvía a su sitio, REV-T7-3-D1).
 * - Marquesina de Editoriales: con foco de teclado y el ratón encima, el lado de
 *   arriba del contorno de la placa desaparecía (RT1-D1); y la pista tiene que ir
 *   dentro de su ventana o la portada se sale de lado (RT1-D2, comprobación de
 *   después del despliegue contra la caché de página).
 * - Ficha: «No se pudo agregar» salía en el rojo de comprar en el botón real
 *   (RV-I1-2-2), y el motivo de un rechazo seguía a la vista con el carrito ya
 *   cambiado (RV-I1-2-1).
 * - Listado móvil: el botón de WhatsApp volvía EN REPOSO sobre «Añadir al
 *   carrito» si hubo un scroll con la petición en vuelo (RV2-T2-1). Y lo mismo
 *   en la ficha, que quedaba tapada también tras «Reintentar» o el rechazo
 *   (RT-REV1-D1).
 * - Tarjeta sin respuesta (red caída): el botón se quedaba girando para siempre,
 *   sin aviso y sin reintento posible (RT-REV1-D2). Y, con eso arreglado, si el
 *   reintento salía, el aviso «reintenta» seguía sobre el botón ya agregado y otro
 *   toque sumaba una segunda unidad (RT-REV2-D1).
 * - Listado móvil: con la petición del corazón de favoritos en vuelo, un scroll
 *   dejaba WhatsApp en reposo encima del corazón (RT-REV2-D2).
 * - Alta de la tarjeta con OTRO botón del mismo tomo (vista rápida o una tarjeta
 *   gemela): la tarjeta del intento anterior quedaba en `.loading` hasta recargar
 *   (RT-REV3-D1) y su «reintenta» seguía con el tomo ya agregado (RT-REV3-D2). Y
 *   un estrés con la red lenta, caídas, rechazos y el teclado (ronda final F-T).
 * - Vista rápida con la red caída: ningún aviso en el diálogo ni anuncio, y el
 *   «reintenta» de la tarjeta desaparecía (REV-FT1-D1); y abierta con la tarjeta en
 *   vuelo, su «Añadir» se quedaba atenuado para siempre (REV-FT1-D3). El aviso del
 *   diálogo se iba solo a los 6 s y el diálogo entero bajaba 19 px sin que el
 *   cliente hiciera nada (REV-FT2-D2).
 * - Mismo botón pulsado otra vez con su primera alta en vuelo: cuando la primera
 *   salía bien, el botón mostraba la ✓, sin giro y con puntero, con la segunda aún
 *   en vuelo, y el candado del tomo se soltaba (REV-FT2-D3). Una pulsación que el
 *   candado bloqueaba también le quitaba el giro y el puntero, y el clic de después
 *   mandaba otra alta (REV-FT3-D2).
 * - Con un fallo que tarda (un 5xx, un timeout), el aviso del diálogo lo subía 19 px
 *   al SALIR, el de la tarjeta nacía en el sitio equivocado y el clon, al dejar de
 *   girar, cambiaba de caja: desplazamientos de diseño sin interacción (REV-FT3-D1).
 * - Vista rápida con scroll (teléfono en horizontal): el aviso de la red caída, debajo
 *   del botón, nacía fuera de la parte visible del diálogo (REV-MT2-D2).
 *
 * Todo es lectura salvo los dos casos del carrito que cambia, que agregan al
 * carrito de la sesión de la prueba: sólo contra el espejo, y la sesión se borra
 * al terminar (soporte/tema-espejo.mjs). Los de RT-REV1 interceptan el alta en el
 * navegador (nunca llega al servidor), pero también van sólo contra el espejo:
 * si la intercepción fallara, el toque agregaría de verdad.
 */

import { expect, test } from '@playwright/test';
import { erroresDePagina, soloLectura } from './soporte/tienda.mjs';
import { borrarSesionWcAlTerminar } from './soporte/tema-espejo.mjs';

/** Enlaces de página de la paginación del catálogo en un HTML. */
function enlacesDePagina( html ) {
	return [ ...html.matchAll( /<a\b[^>]*class="[^"]*page-numbers[^"]*"[^>]*>/g ) ]
		.map( ( m ) => ( /href="([^"]+)"/.exec( m[ 0 ] ) || [] )[ 1 ] )
		.filter( Boolean )
		.map( ( h ) => h.replace( /&#038;|&amp;/g, '&' ) );
}

test.describe( 'paginación del catálogo', () => {
	test( 'la página 1 enlaza a la URL base, no a /page/1/ (que redirige)', async ( { request }, info ) => {
		test.skip( 'movil' === info.project.name, 'el HTML es el mismo en los dos tamaños' );
		// La búsqueda va con %20, como la escriben los enlaces de la paginación: con «+»
		// producción contesta un 301 de WordPress a la forma %20 y el espejo un 200 (E2E-5).
		for ( const ruta of [ '/tienda/page/2/', '/manga/page/2/', '/serie/one-piece/page/2/', '/tienda/page/3/?_buscar=one%20piece' ] ) {
			const r = await request.get( ruta, { maxRedirects: 0 } );
			expect( r.status(), ruta ).toBe( 200 );
			const hrefs = enlacesDePagina( await r.text() );
			expect( hrefs.length, `${ ruta }: tiene paginación` ).toBeGreaterThan( 1 );
			expect( hrefs.filter( ( h ) => /\/page\/1\/?(\?|#|$)/.test( h ) ), `${ ruta }: ningún enlace a /page/1/` ).toEqual( [] );

			// El «1» lleva a la base con los mismos filtros, y responde 200 sin redirigir.
			const base = ruta.replace( /page\/\d+\//, '' );
			const uno = hrefs.find( ( h ) => new URL( h ).pathname === new URL( base, 'http://x' ).pathname );
			expect( uno, `${ ruta }: enlaza a la base` ).toBeTruthy();
			expect( new URL( /** @type {string} */ ( uno ) ).search, `${ ruta }: con los mismos parámetros` ).toBe( new URL( base, 'http://x' ).search );
			const destino = await request.get( /** @type {string} */ ( uno ), { maxRedirects: 0 } );
			expect( destino.status(), `${ uno } responde sin redirigir` ).toBe( 200 );
		}
	} );
} );

test.describe( 'colores forzados (alto contraste de Windows)', () => {
	test( 'las preguntas frecuentes de la ficha muestran el foco (no lo recorta su caja)', async ( { page } ) => {
		await page.emulateMedia( { forcedColors: 'active', reducedMotion: 'reduce' } );
		await page.goto( '/haikyuu-35-ivrea-argentina/', { waitUntil: 'load' } );
		const preguntas = page.locator( 'summary.product-faq__q' );
		test.skip( 0 === ( await preguntas.count() ), 'la ficha no tiene preguntas frecuentes' );
		const pregunta = preguntas.nth( 1 );
		await pregunta.scrollIntoViewIfNeeded();
		// Foco de TECLADO: desde la anterior, Tab.
		await preguntas.nth( 0 ).focus();
		await page.keyboard.press( 'Tab' );
		await expect( pregunta ).toBeFocused();
		const caja = /** @type {{x:number,y:number,width:number,height:number}} */ ( await pregunta.boundingBox() );
		const recorte = { x: Math.max( 0, caja.x - 8 ), y: Math.max( 0, caja.y - 8 ), width: caja.width + 16, height: caja.height + 16 };
		const enfocada = await page.screenshot( { clip: recorte } );
		await pregunta.evaluate( ( el ) => /** @type {HTMLElement} */ ( el ).blur() );
		const sinFoco = await page.screenshot( { clip: recorte } );
		expect( enfocada.equals( sinFoco ), 'el foco no pintaba ni un píxel: el overflow de .product-faq lo recortaba' ).toBe( false );
	} );

	test( 'el salto al contenido no enmarca el <main> entero', async ( { page } ) => {
		await page.emulateMedia( { forcedColors: 'active', reducedMotion: 'reduce' } );
		await page.goto( '/manga/', { waitUntil: 'load' } );
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( 'a.skip-link' ) ).toBeFocused();
		const enlace = await page.locator( 'a.skip-link' ).evaluate( ( el ) => getComputedStyle( el ).outlineStyle );
		expect( enlace, 'el enlace de salto sí lleva contorno' ).not.toBe( 'none' );
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( 'main#main' ) ).toBeFocused();
		expect( await page.locator( 'main#main' ).evaluate( ( el ) => getComputedStyle( el ).outlineStyle ), 'base.css: el destino no lleva recuadro' ).toBe( 'none' );
	} );

	test( 'el foco del botón de pausa de Editoriales conserva su lado de arriba a 900 px', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'el recorte pasaba entre 753 y 1.233 px' );
		await page.setViewportSize( { width: 900, height: 900 } );
		await page.emulateMedia( { forcedColors: 'active', reducedMotion: 'no-preference' } );
		await page.goto( '/', { waitUntil: 'load' } );
		await expect( page.locator( '.akb-brands-home__track.is-marquee' ) ).toHaveCount( 1, { timeout: 15_000 } );
		await page.locator( '.akb-brands-home' ).scrollIntoViewIfNeeded();
		const boton = page.locator( '.akb-brands-home__pause' );
		await page.mouse.move( 0, 0 );
		await page.locator( '.akb-brands-home__more' ).focus();
		await page.keyboard.press( 'Shift+Tab' );
		await expect( boton ).toBeFocused();
		await page.waitForTimeout( 300 );
		const caja = /** @type {{x:number,y:number,width:number,height:number}} */ ( await boton.boundingBox() );
		// Franja de 6 px justo ENCIMA del botón: ahí va el contorno (2 px a 2 px).
		const franja = { x: Math.round( caja.x + caja.width / 2 ) - 5, y: Math.round( caja.y ) - 6, width: 10, height: 6 };
		const conFoco = await page.screenshot( { clip: franja } );
		await boton.evaluate( ( el ) => /** @type {HTMLElement} */ ( el ).blur() );
		await page.waitForTimeout( 300 );
		const sinFoco = await page.screenshot( { clip: franja } );
		expect( conFoco.equals( sinFoco ), 'el lado de arriba del contorno quedaba fuera del recorte de la marquesina' ).toBe( false );
	} );
} );

test.describe( 'portada: la marquesina de Editoriales', () => {
	// RT1-D1: la ventana que recorta la marquesina empezaba en el borde de arriba
	// de la pista (8 px de relleno). Con foco de teclado Y el ratón encima, la
	// placa sube 4 px y su contorno ocupa 7 (6 en colores forzados): el lado de
	// arriba desaparecía entero. Cada estado por separado sí cabía.
	for ( const forzados of [ 'none', 'active' ] ) {
		test( `placa con foco de teclado y el ratón encima: el lado de arriba del contorno se ve entero (colores forzados: ${ forzados })`, async ( { page }, info ) => {
			test.skip( 'movil' === info.project.name, 'el salto de la placa es del :hover de un puntero' );
			await page.emulateMedia( { forcedColors: /** @type {'none'|'active'} */ ( forzados ), reducedMotion: 'no-preference' } );
			await page.goto( '/', { waitUntil: 'load' } );
			await expect( page.locator( '.akb-brands-home__track.is-marquee' ) ).toHaveCount( 1, { timeout: 15_000 } );
			await page.locator( '.akb-brands-home' ).scrollIntoViewIfNeeded();
			await page.mouse.move( 0, 0 );
			await page.locator( '.akb-brands-home__more' ).focus();
			// Foco de TECLADO en una placa que quede fuera del degradado de los bordes
			// (el foco pausa la marquesina, así que no se mueve mientras se mide).
			const placa = page.locator( '.akb-brands-home__item:focus' );
			let dentro = false;
			for ( let i = 0; i < 4 && ! dentro; i++ ) {
				await page.keyboard.press( 'Tab' );
				await expect( placa ).toHaveCount( 1 );
				dentro = await placa.evaluate( ( el ) => {
					const a = el.getBoundingClientRect();
					const v = /** @type {Element} */ ( el.closest( '.akb-brands-home__viewport' ) ).getBoundingClientRect();
					const x = a.left + a.width / 2;
					return x > v.left + v.width * 0.1 && x < v.right - v.width * 0.1;
				} );
			}
			expect( dentro, 'ninguna placa enfocada quedaba fuera del degradado' ).toBe( true );
			await placa.hover();
			await page.waitForTimeout( 600 );
			const m = await placa.evaluate( ( el ) => {
				const s = getComputedStyle( el );
				const a = el.getBoundingClientRect();
				const v = /** @type {Element} */ ( el.closest( '.akb-brands-home__viewport' ) ).getBoundingClientRect();
				return {
					sube: new DOMMatrix( s.transform ).m42,
					margen: a.top - v.top,
					trazo: parseFloat( s.outlineWidth ),
					separacion: parseFloat( s.outlineOffset ),
					x: Math.round( a.left + a.width / 2 ),
					top: a.top,
				};
			} );
			expect( m.sube, 'la placa sube con el ratón encima: si no, la prueba no mide nada' ).toBe( -4 );
			expect.soft( m.margen, `hueco sobre la placa (${ m.margen } px) frente al contorno (${ m.trazo } + ${ m.separacion } px)` ).toBeGreaterThanOrEqual( m.trazo + m.separacion );
			// Y en píxeles: cada fila del lado de arriba, con y sin contorno (sin quitar
			// el foco, que reanudaría la marquesina; `!important` en línea para ganar
			// al de colores forzados de tokens.css). Una fila de más a cada lado por si
			// la placa cae en un y fraccionario (otro contenido en producción): con el
			// recorte, lo de arriba del borde no pinta y lo de abajo es la separación.
			const filas = [];
			for ( let y = Math.floor( m.top - m.trazo - m.separacion ) - 1; y < Math.ceil( m.top - m.separacion ) + 1; y++ ) {
				filas.push( { x: m.x - 5, y, width: 10, height: 1 } );
			}
			const con = [];
			for ( const clip of filas ) {
				con.push( await page.screenshot( { clip } ) );
			}
			await placa.evaluate( ( el ) => /** @type {HTMLElement} */ ( el ).style.setProperty( 'outline-style', 'none', 'important' ) );
			let visibles = 0;
			for ( const [ k, clip ] of filas.entries() ) {
				visibles += ( await page.screenshot( { clip } ) ).equals( con[ k ] ) ? 0 : 1;
			}
			expect( visibles, `filas del lado de arriba del contorno que se pintan (trazo de ${ m.trazo } px)` ).toBeGreaterThanOrEqual( Math.round( m.trazo ) );
		} );
	}

	// RT1-D2: el recorte depende del <div class="akb-brands-home__viewport"> que
	// trae el HTML. Una portada guardada en la caché de LiteSpeed de antes de ese
	// div, servida con la hoja nueva, deja la pista (miles de px) sin recortar y la
	// página entera se desplaza de lado. En producción (`npm run test:prod`) esta
	// prueba es la comprobación de después del despliegue: si falla, purgar
	// LiteSpeed y repetir. Por eso lleva `@prod` (REV-SE-3: sin la etiqueta, la
	// prueba de humo la dejaba fuera sin avisar): una carga de la portada por
	// proyecto.
	test( 'con la marquesina en marcha, la pista va dentro de su ventana y la página no se sale de lado', { tag: '@prod' }, async ( { page } ) => {
		await page.emulateMedia( { reducedMotion: 'no-preference' } );
		expect( ( await page.goto( '/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await expect( page.locator( '.akb-brands-home__track.is-marquee' ) ).toHaveCount( 1, { timeout: 15_000 } );
		await page.locator( '.akb-brands-home' ).scrollIntoViewIfNeeded();
		await page.waitForTimeout( 1_000 );
		const m = await page.evaluate( () => ( {
			ventana: !! document.querySelector( '.akb-brands-home__viewport > .akb-brands-home__track.is-marquee' ),
			sobra: document.documentElement.scrollWidth - document.documentElement.clientWidth,
		} ) );
		expect.soft( m.ventana, 'la pista no va dentro de .akb-brands-home__viewport: HTML de antes del div (¿caché de página sin purgar?)' ).toBe( true );
		expect( m.sobra, 'la página se desplaza de lado: la pista de la marquesina quedó sin recortar' ).toBeLessThanOrEqual( 0 );
	} );
} );

test.describe( 'menú de escritorio', () => {
	test( 'con el ratón sobre Manga, abrir Cómics con el teclado no deja dos paneles a la vista', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'menú de escritorio' );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const item = ( nombre ) => page.locator( '.mainnav__item--has-sub', { has: page.locator( ':scope > .mainnav__link', { hasText: nombre } ) } ).first();
		const manga = item( /^manga$/i );
		const comics = item( /^c[oó]mics$/i );
		await manga.locator( ':scope > .mainnav__link' ).hover();
		await expect( manga.locator( ':scope > .mainnav__sub' ) ).toBeVisible();

		await comics.locator( ':scope > .mainnav__toggle' ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( comics.locator( ':scope > .mainnav__sub' ) ).toBeVisible();
		await expect( comics.locator( ':scope > .mainnav__toggle' ) ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( manga.locator( ':scope > .mainnav__sub' ), 'el de Manga seguía pintado por el :hover' ).toBeHidden();
		await expect( manga.locator( ':scope > .mainnav__toggle' ) ).toHaveAttribute( 'aria-expanded', 'false' );

		// El ratón sale y vuelve: Manga se abre otra vez.
		await page.mouse.move( 5, 600 );
		await manga.locator( ':scope > .mainnav__link' ).hover();
		await expect( manga.locator( ':scope > .mainnav__sub' ) ).toBeVisible();
	} );
} );

test.describe( 'portada: la fila deslizable sigue al foco de teclado', () => {
	for ( const ancho of [ 568, 600 ] ) {
		test( `a ${ ancho } px cada parada queda entera dentro de la fila`, async ( { page }, info ) => {
			test.skip( 'movil' === info.project.name, 'se fija el ancho a mano; sin táctil, como un teclado con zoom' );
			await page.setViewportSize( { width: ancho, height: 900 } );
			await page.emulateMedia( { reducedMotion: 'reduce' } );
			await page.goto( '/', { waitUntil: 'load' } );
			const fila = page.locator( '.akb-section .woocommerce ul.products' ).first();
			await fila.scrollIntoViewIfNeeded();
			expect( await fila.evaluate( ( el ) => el.scrollWidth > el.clientWidth + 20 ), 'a este ancho la fila desliza' ).toBe( true );
			await fila.evaluate( ( ul ) => {
				const ancla = document.createElement( 'button' );
				ancla.id = 'akb-e2e-ancla';
				ancla.style.cssText = 'position:absolute;opacity:0;width:1px;height:1px';
				ul.parentNode?.insertBefore( ancla, ul );
				ancla.focus( { preventScroll: true } );
			} );
			const cortadas = [];
			for ( let i = 0; i < 20; i++ ) {
				await page.keyboard.press( 'Tab' );
				await page.waitForTimeout( 80 );
				const m = await page.evaluate( () => {
					const a = /** @type {HTMLElement} */ ( document.activeElement );
					const ul = a.closest( 'ul.products' );
					if ( ! ul ) {
						return null;
					}
					const r = a.getBoundingClientRect();
					const f = ul.getBoundingClientRect();
					return { parada: a.className.toString().slice( 0, 30 ), visible: r.width ? Math.round( 100 * Math.max( 0, Math.min( r.right, f.right ) - Math.max( r.left, f.left ) ) / r.width ) : 100 };
				} );
				if ( ! m ) {
					break;
				}
				if ( m.visible < 90 ) {
					cortadas.push( m );
				}
			}
			expect( cortadas, 'paradas cortadas por el borde de la fila (antes: 62 % a 568, 80 % a 600)' ).toEqual( [] );
		} );
	}
} );

test.describe( 'ficha: estado de error', () => {
	test( '«No se pudo agregar» es negro con borde rojo en el botón real, también con el puntero encima', async ( { page }, info ) => {
		// Sin transición: se mide el color final, no el de mitad del cambio.
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await page.goto( '/dan-da-dan-1-ivrea-argentina/', { waitUntil: 'load' } );
		const real = page.locator( 'form.cart .single_add_to_cart_button' );
		await real.scrollIntoViewIfNeeded();
		await real.evaluate( ( b ) => b.classList.add( 'is-error' ) );
		if ( 'escritorio' === info.project.name ) {
			await real.hover();
		}
		await page.waitForTimeout( 300 );
		const estilo = await real.evaluate( ( b ) => {
			const s = getComputedStyle( b );
			return [ s.backgroundColor, s.borderTopColor ];
		} );
		expect( estilo[ 0 ], 'antes salía en el rojo de comprar (o el de su hover)' ).toBe( 'rgb(18, 18, 18)' );
		expect( estilo[ 1 ] ).toBe( 'rgb(230, 0, 18)' );
	} );
} );

test.describe( 'carrito que cambia y botón de WhatsApp (escriben en el carrito del espejo)', () => {
	borrarSesionWcAlTerminar();

	test( 'ficha: al quitar el tomo, el motivo de un rechazo sin «Ver carrito» se retira', async ( { page, context, request }, info ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		await context.clearCookies();
		const lista = await ( await request.get( '/wp-json/wc/store/v1/products?per_page=100&stock_status=instock' ) ).json();
		const p = lista.find( ( x ) => 'simple' === x.type && x.is_purchasable && x.add_to_cart && 2 === x.add_to_cart.maximum && 2 === x.low_stock_remaining );
		test.skip( ! p, 'no hay un tomo simple con exactamente dos unidades en la Store API' );
		const ruta = new URL( p.permalink ).pathname;
		const tocar = ( l ) => ( 'movil' === info.project.name ? l.tap() : l.click() );

		await page.goto( ruta, { waitUntil: 'load' } );
		const real = page.locator( 'form.cart .single_add_to_cart_button' );
		await real.scrollIntoViewIfNeeded();
		await tocar( real );
		await expect( page.locator( '#akb-native-cart-drawer.akb-open' ) ).toHaveCount( 1 );
		await tocar( page.locator( '#akb-native-cart-drawer .akb-cart-close' ) );
		await expect( page.locator( '#akb-native-cart-drawer.akb-open' ) ).toHaveCount( 0 );
		await page.waitForTimeout( 400 );

		// Pedir 2 con 1 ya en el carrito: rechazo SIN «Ver carrito» (queda una).
		await page.locator( 'form.cart input.qty' ).fill( '2' );
		await tocar( real );
		const motivo = page.locator( 'form.cart .akb-atc-motivo' );
		await expect( motivo ).toBeVisible( { timeout: 15_000 } );
		await expect( real ).toHaveClass( /is-error/ );

		// Se quita el tomo desde el cajón antes de los 6 s del motivo.
		await tocar( page.locator( '.akb-cart-trigger:visible' ).first() );
		const quitar = page.locator( '#akb-native-cart-drawer .akb-cart-item-remove' ).first();
		await expect( quitar ).toBeVisible();
		await tocar( quitar );
		await expect( page.locator( '#akb-native-cart-drawer .akb-cart-item-remove' ) ).toHaveCount( 0 );
		await tocar( page.locator( '#akb-native-cart-drawer .akb-cart-close' ) );
		await expect( page.locator( '#akb-native-cart-drawer.akb-open' ) ).toHaveCount( 0 );

		await expect( motivo, 'el motivo decía «has añadido 1 en tu carrito» con el carrito vacío' ).toBeHidden( { timeout: 2_000 } );
		await expect( real ).not.toHaveClass( /is-error/ );
		await expect( real ).toHaveText( /Añadir|Reservar/i );
	} );

	test( 'listado móvil: tras un alta con scroll en vuelo y el cajón cerrado, WhatsApp no queda sobre el botón', async ( { page, context }, info ) => {
		test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );
		test.skip( 'movil' !== info.project.name, 'el botón flotante sólo tapa tarjetas en el teléfono' );
		await context.clearCookies();
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		// Joinchat (tercero) muestra su botón con retraso.
		await page.waitForSelector( '.joinchat.joinchat--show', { state: 'attached', timeout: 20_000 } );
		const id = await page.evaluate( () => {
			const bs = [ ...document.querySelectorAll( 'li.product .add_to_cart_button.ajax_add_to_cart[data-product_id]' ) ]
				.filter( ( b ) => /** @type {HTMLElement} */ ( b.closest( 'li.product' ) ).getBoundingClientRect().right > innerWidth - 40 && b.getBoundingClientRect().top > 1200 );
			return bs[ 0 ] ? bs[ 0 ].getAttribute( 'data-product_id' ) : null;
		} );
		test.skip( ! id, 'no hay una tarjeta de la columna derecha con «Añadir al carrito»' );
		const boton = page.locator( `li.product .add_to_cart_button[data-product_id="${ id }"]` );

		// El botón de la tarjeta, justo bajo el de WhatsApp.
		const fab = /** @type {{x:number,y:number,width:number,height:number}} */ ( await page.locator( '.joinchat__button' ).boundingBox() );
		await boton.evaluate( ( b, y ) => window.scrollTo( 0, b.getBoundingClientRect().top + scrollY - y ), fab.y + 14 );
		await expect( page.locator( 'body' ) ).toHaveClass( /akb-fab-cede/, { timeout: 3_000 } );

		// Petición lenta y un scroll corto con ella en vuelo (el dedo que sigue).
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			await new Promise( ( r ) => setTimeout( r, 600 ) );
			await ruta.continue();
		} );
		const caja = /** @type {{x:number,y:number,width:number,height:number}} */ ( await boton.boundingBox() );
		await page.touchscreen.tap( caja.x + 20, caja.y + caja.height / 2 );
		await page.waitForTimeout( 120 );
		await page.evaluate( () => window.scrollBy( 0, 12 ) );
		await expect( page.locator( '#akb-native-cart-drawer.akb-open' ) ).toHaveCount( 1, { timeout: 10_000 } );
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '#akb-native-cart-drawer.akb-open' ) ).toHaveCount( 0 );
		await page.waitForTimeout( 600 );

		const bajoWhatsApp = await boton.evaluate( ( b ) => {
			const r = b.getBoundingClientRect();
			let n = 0;
			for ( let x = r.left + 1; x < r.right; x += 3 ) {
				for ( let y = r.top + 1; y < r.bottom; y += 3 ) {
					const el = document.elementFromPoint( x, y );
					if ( el && el.closest( '.joinchat' ) ) {
						n++;
					}
				}
			}
			return n;
		} );
		expect( bajoWhatsApp, 'puntos del botón bajo WhatsApp en reposo (antes: 177 de 810)' ).toBe( 0 );
		await expect( page.locator( 'body' ) ).toHaveClass( /akb-fab-cede/ );

		// Y lejos de cualquier compra (el pie) vuelve: no desaparece para siempre.
		await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
		await expect( page.locator( 'body' ) ).not.toHaveClass( /akb-fab-cede/, { timeout: 3_000 } );
	} );
} );

test.describe( 'listado móvil: el corazón de favoritos en vuelo (RT-REV2-D2)', () => {
	/*
	 * El corazón cuenta como compra (QA-MOV-6). Con su petición en vuelo, wishlist.js
	 * (lockButtons, akibara-business) le pone `.is-loading` y wishlist.css le quita el
	 * puntero: elementsFromPoint no lo ve. Un scroll a mitad de la petición dejaba
	 * WhatsApp en reposo encima del corazón (368 de 484 puntos en Chromium) y seguía ahí
	 * al terminar: el siguiente toque, para quitar el favorito, abría el chat. Como
	 * invitado el corazón abre el muro de acceso, así que el vuelo se reproduce con la
	 * misma clase que pone el plugin. Sólo mira: no escribe nada.
	 */
	test( 'con un scroll a mitad de la petición, WhatsApp no vuelve encima del corazón', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'el botón flotante sólo tapa tarjetas en el teléfono' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.waitForSelector( '.joinchat.joinchat--show', { state: 'attached', timeout: 20_000 } );
		const n = await page.evaluate( () => [ ...document.querySelectorAll( 'li.product .akb-wl-toggle' ) ]
			.findIndex( ( h ) => h.getBoundingClientRect().left > innerWidth - 80 && h.getBoundingClientRect().top + scrollY > 1200 ) );
		test.skip( n < 0, 'no hay un corazón en la columna derecha' );
		const corazon = page.locator( 'li.product .akb-wl-toggle' ).nth( n );
		const fab = /** @type {{x:number,y:number,width:number,height:number}} */ ( await page.locator( '.joinchat__button' ).boundingBox() );
		await corazon.evaluate( ( h, y ) => window.scrollTo( 0, h.getBoundingClientRect().top + scrollY - y ), fab.y );
		await expect( page.locator( 'body' ) ).toHaveClass( /akb-fab-cede/, { timeout: 3_000 } );

		// Lo que hace lockButtons() mientras vuela la petición, y el dedo que sigue.
		await corazon.evaluate( ( h ) => { h.classList.add( 'is-loading' ); h.setAttribute( 'aria-disabled', 'true' ); } );
		await page.evaluate( () => window.scrollBy( 0, 3 ) );
		await page.waitForTimeout( 600 );
		expect( await puntosBajoWhatsApp( corazon ), 'con la petición en vuelo' ).toBe( 0 );
		await expect( page.locator( 'body' ) ).toHaveClass( /akb-fab-cede/ );

		// Fin de la petición: wishlist.js no avisa a nadie; el corazón sigue debajo.
		await corazon.evaluate( ( h ) => { h.classList.remove( 'is-loading' ); h.removeAttribute( 'aria-disabled' ); } );
		await page.waitForTimeout( 900 );
		expect( await puntosBajoWhatsApp( corazon ), 'al terminar (antes: 368 de 484 en rejilla de 2 px)' ).toBe( 0 );
		await expect( page.locator( 'body' ) ).toHaveClass( /akb-fab-cede/ );
	} );
} );

/** Puntos de un botón que quedan bajo el de WhatsApp (rejilla de 3 px). */
function puntosBajoWhatsApp( boton ) {
	return boton.evaluate( ( b ) => {
		const r = b.getBoundingClientRect();
		let n = 0;
		for ( let x = r.left + 1; x < r.right; x += 3 ) {
			for ( let y = r.top + 1; y < r.bottom; y += 3 ) {
				const el = document.elementFromPoint( x, y );
				if ( el && el.closest( '.joinchat' ) ) {
					n++;
				}
			}
		}
		return n;
	} );
}

test.describe( 'altas que fallan (revisión RT-REV1; el alta se intercepta en el navegador)', () => {
	/*
	 * RT-REV1-D1: el botón real de la ficha lleva `.is-adding` (sin puntero) con la
	 * petición en vuelo, y main.js no lo veía. Un scroll a mitad de la petición dejaba
	 * el botón de WhatsApp en reposo sobre «Añadir al carrito», y seguía encima de
	 * «Reintentar» y del botón devuelto (medido: 139 de 885 puntos en Chromium).
	 */
	for ( const desenlace of [ 'red caída', 'rechazo' ] ) {
		test( `ficha móvil: tras un scroll con la petición en vuelo (${ desenlace }), WhatsApp no queda sobre el botón`, async ( { page }, info ) => {
			test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
			test.skip( 'movil' !== info.project.name, 'el botón flotante sólo tapa la compra en el teléfono' );
			await page.emulateMedia( { reducedMotion: 'reduce' } );
			await page.goto( '/dan-da-dan-1-ivrea-argentina/', { waitUntil: 'load' } );
			await page.waitForSelector( '.joinchat.joinchat--show', { state: 'attached', timeout: 20_000 } );
			const real = page.locator( 'form.cart .single_add_to_cart_button' );
			const fab = /** @type {{x:number,y:number,width:number,height:number}} */ ( await page.locator( '.joinchat__button' ).boundingBox() );
			await real.evaluate( ( b, y ) => window.scrollTo( 0, b.getBoundingClientRect().top + scrollY - y ), fab.y + 10 );
			await expect( page.locator( 'body' ) ).toHaveClass( /akb-fab-cede/, { timeout: 3_000 } );

			let altas = 0;
			await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
				altas++;
				await new Promise( ( r ) => setTimeout( r, 700 ) );
				if ( 'red caída' === desenlace ) {
					await ruta.abort( 'failed' );
					return;
				}
				await ruta.fulfill( { json: { error: true, product_url: '' } } );
			} );
			await page.route( '**/?wc-ajax=akb_get_cart*', ( ruta ) => ruta.fulfill( {
				json: { success: true, data: { errors: [ 'No puedes añadir esa cantidad al carrito.' ], cart_url: '/carrito/', motivo: 'stock' } },
			} ) );

			const caja = /** @type {{x:number,y:number,width:number,height:number}} */ ( await real.boundingBox() );
			await page.touchscreen.tap( caja.x + 30, caja.y + caja.height / 2 );
			await page.waitForTimeout( 150 );
			await page.evaluate( () => window.scrollBy( 0, 12 ) );

			await expect( real ).toHaveText( 'red caída' === desenlace ? /Reintentar/ : /No se pudo agregar/, { timeout: 5_000 } );
			expect( await puntosBajoWhatsApp( real ), 'con el botón en error' ).toBe( 0 );

			// El botón vuelve a comprar (3 s tras la red caída; 6 s tras el motivo del rechazo).
			await expect( page.locator( 'form.cart .akb-atc-motivo:visible' ) ).toHaveCount( 0, { timeout: 9_000 } );
			await expect( real ).toHaveText( /Añadir|Reservar/i, { timeout: 9_000 } );
			await page.waitForTimeout( 300 );
			expect( await puntosBajoWhatsApp( real ), 'en reposo, ya devuelto el botón (antes: 139 de 885)' ).toBe( 0 );
			await expect( page.locator( 'body' ) ).toHaveClass( /akb-fab-cede/ );
			expect( altas ).toBe( 1 );
		} );
	}

	/*
	 * RT-REV1-D2 (defecto de antes, base 2.1.142): WooCommerce no maneja el error del
	 * alta de la tarjeta. Con la red caída el botón se quedaba en `.loading` —sin
	 * puntero— para siempre: ni aviso ni reintento sin recargar.
	 */
	test( 'tarjeta: con la red caída el botón deja de girar, explica sin taparse y deja reintentar', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const id = await page.locator( 'li.product .add_to_cart_button.ajax_add_to_cart[data-product_id]' ).first().getAttribute( 'data-product_id' );
		const boton = page.locator( `li.product .add_to_cart_button[data-product_id="${ id }"]` );
		const tarjeta = page.locator( 'li.product', { has: page.locator( `.add_to_cart_button[data-product_id="${ id }"]` ) } );
		await boton.scrollIntoViewIfNeeded();
		const tocar = ( l ) => ( 'movil' === info.project.name ? l.tap() : l.click() );

		let altas = 0;
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			altas++;
			await new Promise( ( r ) => setTimeout( r, 600 ) );
			await ruta.abort( 'failed' );
		} );
		await tocar( boton );
		await expect( boton ).toHaveClass( /\bloading\b/ );
		await expect( boton, 'antes se quedaba en .loading para siempre' ).not.toHaveClass( /\bloading\b/, { timeout: 5_000 } );

		const motivo = tarjeta.locator( '.akb-tarjeta-motivo' );
		await expect( motivo ).toBeVisible();
		await expect( motivo ).toHaveText( 'No se pudo conectar, reintenta' );
		const libre = await boton.evaluate( ( b ) => {
			const r = b.getBoundingClientRect();
			const el = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
			return !! el && b.contains( el );
		} );
		expect( libre, 'el aviso termina encima del botón, no lo tapa' ).toBe( true );

		await tocar( boton );
		await expect.poll( () => altas, { message: 'el segundo toque vuelve a intentarlo' } ).toBe( 2 );
		await expect( boton ).not.toHaveClass( /\bloading\b/, { timeout: 5_000 } );
	} );

	/*
	 * RT-REV2-D1: con el aviso de la red caída a la vista, el reintento SALE. Nada
	 * retiraba el aviso: seguía 6 s encima del botón ya marcado `added` (✓), también
	 * con el cajón cerrado, pidiendo reintentar; y otro toque sumaba una segunda unidad.
	 * El segundo alta se contesta en el navegador: nada llega al servidor.
	 */
	test( 'tarjeta: tras la red caída, el reintento que sale retira el aviso «reintenta»', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		let altas = 0;
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			altas++;
			if ( 1 === altas ) {
				await new Promise( ( r ) => setTimeout( r, 400 ) );
				await ruta.abort( 'failed' );
				return;
			}
			await ruta.fulfill( { json: { fragments: { 'div.akb-e2e-no-existe': '<div></div>' }, cart_hash: 'e2e' } } );
		} );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const id = await page.locator( 'li.product .add_to_cart_button.ajax_add_to_cart[data-product_id]' ).first().getAttribute( 'data-product_id' );
		const boton = page.locator( `li.product .add_to_cart_button[data-product_id="${ id }"]` );
		const motivo = page.locator( 'li.product', { has: page.locator( `.add_to_cart_button[data-product_id="${ id }"]` ) } ).locator( '.akb-tarjeta-motivo' );
		await boton.scrollIntoViewIfNeeded();
		const tocar = ( l ) => ( 'movil' === info.project.name ? l.tap() : l.click() );

		await tocar( boton );
		await expect( motivo ).toHaveText( 'No se pudo conectar, reintenta', { timeout: 5_000 } );
		await page.waitForTimeout( 300 );
		await tocar( boton );
		await expect( boton, 'el reintento salió' ).toHaveClass( /\badded\b/, { timeout: 5_000 } );
		// Antes del temporizador de 6 s del aviso: lo retira el reintento, no el reloj.
		await expect( motivo, 'antes seguía pidiendo reintentar sobre el botón ya agregado' ).toBeHidden( { timeout: 1_500 } );

		// Se cierra el cajón que abre el alta: el botón queda a la vista, sin nada encima.
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '#akb-native-cart-drawer.akb-open' ) ).toHaveCount( 0 );
		await boton.scrollIntoViewIfNeeded();
		await expect( motivo ).toBeHidden();
		const libre = await boton.evaluate( ( b ) => {
			const r = b.getBoundingClientRect();
			const el = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
			return !! el && b.contains( el );
		} );
		expect( libre, 'al centro del botón está el botón' ).toBe( true );
		await page.waitForTimeout( 500 );
		expect( altas, 'nada pidió un tercer alta' ).toBe( 2 );
	} );
} );

/*
 * Revisión 3 del lote R-T (RT-REV3) y ronda final F-T. El alta de la tarjeta decidía
 * «quién habla» por TOMO y no por botón. Si el intento más nuevo del mismo tomo salía
 * de otro botón —el clon de la vista rápida, que el ratón alcanza aunque la tarjeta
 * esté en `.loading`, u otra tarjeta del mismo tomo, como en la portada de
 * producción—, la tarjeta del intento anterior quedaba en `.loading` y sin puntero
 * hasta recargar si ese intento fallaba o lo rechazaban (RT-REV3-D1). Y su
 * «reintenta» seguía a la vista con el tomo ya agregado desde el otro botón
 * (RT-REV3-D2). Todo se contesta en el navegador: nada llega al servidor.
 */
test.describe( 'alta de la tarjeta: cada botón vuelve, habla el último intento del tomo (RT-REV3)', () => {
	borrarSesionWcAlTerminar();

	const OK = { json: { fragments: { 'div.akb-e2e-no-existe': '<div></div>' }, cart_hash: 'e2e' } };

	/** El estado del botón y del aviso de su tarjeta. */
	const estado = ( boton ) => boton.evaluate( ( b ) => {
		const caja = b.closest( 'li.product' )?.querySelector( '.akb-tarjeta-motivo' );
		return { loading: b.classList.contains( 'loading' ), punteros: getComputedStyle( b ).pointerEvents, aviso: caja && ! caja.hidden ? caja.textContent : null };
	} );

	/** Clic del ratón o toque del dedo en el centro, sin esperar a que el botón «acepte» (con `.loading` no lo hace). */
	async function tocar( page, boton, info ) {
		const b = await boton.boundingBox();
		expect( b, 'el botón está en pantalla' ).toBeTruthy();
		if ( 'movil' === info.project.name ) {
			await page.touchscreen.tap( b.x + b.width / 2, b.y + b.height / 2 );
		} else {
			await page.mouse.click( b.x + b.width / 2, b.y + b.height / 2 );
		}
	}

	/** Pone al lado de la primera tarjeta con vista rápida otra del MISMO tomo (en producción la portada repite tomos entre secciones). */
	async function conGemelo( page ) {
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
		await page.evaluate( () => {
			const li = document.querySelector( 'li.product:has(> .akb-qv-trigger)' );
			li.setAttribute( 'data-akb-e2e', 'a' );
			const g = li.cloneNode( true );
			g.setAttribute( 'data-akb-e2e', 'gemelo' );
			li.after( g );
		} );
		const a = page.locator( 'li.product[data-akb-e2e="a"] .add_to_cart_button' );
		const g = page.locator( 'li.product[data-akb-e2e="gemelo"] .add_to_cart_button' );
		await a.scrollIntoViewIfNeeded();
		return { a, g };
	}

	/**
	 * Abre la vista rápida de la tarjeta y pulsa su «Añadir» (el clon, fuera de
	 * `li.product`). Con `tactil`, con el dedo; y si el diálogo tiene scroll (teléfono
	 * en horizontal) lo desliza, como el cliente, hasta ver el botón entero.
	 */
	async function anadirDesdeLaVistaRapida( page, tarjeta, tactil = false ) {
		if ( tactil ) {
			await tarjeta.locator( '.akb-qv-trigger' ).tap();
		} else {
			await tarjeta.hover();
			await tarjeta.locator( '.akb-qv-trigger' ).click();
		}
		const clon = page.locator( 'dialog .akb-qv__actions .add_to_cart_button' );
		await clon.waitFor( { state: 'visible' } );
		await clon.evaluate( ( boton ) => {
			const d = /** @type {HTMLElement} */ ( boton.closest( 'dialog' ) );
			const fondo = d.getBoundingClientRect().top + d.clientTop + d.clientHeight;
			d.scrollTop += Math.max( 0, Math.ceil( boton.getBoundingClientRect().bottom - fondo ) );
		} );
		// El clon copia las clases de la tarjeta, `loading` incluido: se pulsa donde está.
		const b = /** @type {{x:number,y:number,width:number,height:number}} */ ( await clon.boundingBox() );
		if ( tactil ) {
			await page.touchscreen.tap( b.x + b.width / 2, b.y + b.height / 2 );
		} else {
			await page.mouse.click( b.x + b.width / 2, b.y + b.height / 2 );
		}
	}

	for ( const desenlace of [ 'sin respuesta', 'rechazo' ] ) {
		test( `vista rápida (${ desenlace }): la tarjeta cuyo intento quedó atrás vuelve a poder pulsarse`, async ( { page }, info ) => {
			test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
			test.skip( 'escritorio' !== info.project.name, 'la vista rápida es de escritorio' );
			await page.emulateMedia( { reducedMotion: 'reduce' } );
			let altas = 0;
			await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
				if ( 1 === ++altas ) {
					await new Promise( ( r ) => setTimeout( r, 4000 ) );
					await ( 'rechazo' === desenlace ? ruta.fulfill( { json: { error: true, product_url: '/x/' } } ) : ruta.abort( 'failed' ) );
					return;
				}
				await ruta.fulfill( OK );
			} );
			await page.route( '**/?wc-ajax=akb_get_cart*', ( r ) => r.fulfill( { json: { data: { errors: [ 'Sin stock.' ], cart_url: '/carrito/' } } } ) );
			await page.goto( '/tienda/', { waitUntil: 'load' } );
			await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
			const tarjeta = page.locator( 'li.product:has(> .akb-qv-trigger)' ).first();
			const boton = tarjeta.locator( '.add_to_cart_button' );
			await boton.scrollIntoViewIfNeeded();
			await tocar( page, boton, info );
			await page.waitForTimeout( 2800 ); // pasada la red de seguridad de 2,5 s
			await anadirDesdeLaVistaRapida( page, tarjeta );
			await expect.poll( () => altas, { message: 'la vista rápida sale cuando termina la tarjeta (la cola de WooCommerce)', timeout: 8000 } ).toBe( 2 );
			await page.keyboard.press( 'Escape' );
			await expect.poll( async () => ( await estado( boton ) ).loading, { message: 'la tarjeta se quedaba en .loading hasta recargar', timeout: 5000 } ).toBe( false );
			const fin = await estado( boton );
			expect( fin.punteros ).toBe( 'auto' );
			expect( fin.aviso, 'sin aviso: habló el intento de la vista rápida, que agregó' ).toBeNull();
			await expect( page ).toHaveURL( /\/tienda\/$/ );
			await boton.click( { trial: true, timeout: 2000 } );
		} );
	}

	test( 'vista rápida: el tomo agregado desde ahí retira el «reintenta» de su tarjeta (RT-REV3-D2)', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		test.skip( 'escritorio' !== info.project.name, 'la vista rápida es de escritorio' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		let altas = 0;
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			if ( 1 === ++altas ) {
				await new Promise( ( r ) => setTimeout( r, 300 ) );
				await ruta.abort( 'failed' );
				return;
			}
			await ruta.fulfill( OK );
		} );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
		const tarjeta = page.locator( 'li.product:has(> .akb-qv-trigger)' ).first();
		const boton = tarjeta.locator( '.add_to_cart_button' );
		const motivo = tarjeta.locator( '.akb-tarjeta-motivo' );
		await boton.scrollIntoViewIfNeeded();
		await tocar( page, boton, info );
		await expect( motivo ).toHaveText( 'No se pudo conectar, reintenta', { timeout: 5000 } );
		await anadirDesdeLaVistaRapida( page, tarjeta );
		await expect.poll( () => altas ).toBe( 2 );
		await page.keyboard.press( 'Escape' );
		// Antes de los 6 s del aviso: lo retira el alta, no el reloj.
		await expect( motivo, 'seguía pidiendo reintentar un tomo ya agregado' ).toBeHidden( { timeout: 1500 } );
	} );

	test( 'otra tarjeta del mismo tomo: la primera vuelve y su «reintenta» se va cuando el gemelo agrega', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		let altas = 0;
		let primera = 4000;
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			if ( 1 === ++altas ) {
				await new Promise( ( r ) => setTimeout( r, primera ) );
				await ruta.abort( 'failed' );
				return;
			}
			await ruta.fulfill( OK );
		} );

		// RT-REV3-D1: A tarda y falla; el gemelo, pulsado mientras, sale después y agrega.
		let { a, g } = await conGemelo( page );
		await tocar( page, a, info );
		await page.waitForTimeout( 2800 );
		await tocar( page, g, info );
		await expect.poll( () => altas, { timeout: 8000 } ).toBe( 2 );
		await expect.poll( async () => ( await estado( a ) ).loading, { message: 'A se quedaba en .loading', timeout: 5000 } ).toBe( false );
		expect( ( await estado( a ) ).punteros ).toBe( 'auto' );
		expect( ( await estado( a ) ).aviso ).toBeNull();

		// RT-REV3-D2: A falla rápido («reintenta»), el gemelo agrega.
		altas = 0;
		primera = 300;
		( { a, g } = await conGemelo( page ) );
		await tocar( page, a, info );
		await expect.poll( async () => ( await estado( a ) ).aviso, { timeout: 5000 } ).toBe( 'No se pudo conectar, reintenta' );
		await tocar( page, g, info );
		await expect( g ).toHaveClass( /\badded\b/, { timeout: 5000 } );
		expect( ( await estado( a ) ).aviso, 'el tomo está en el carrito: «reintenta» ya no es verdad' ).toBeNull();
	} );

	/*
	 * REV-FT1-D1 (revisión 1 de la ronda final F-T): con la red caída al agregar desde
	 * la vista rápida el cliente no recibía ningún aviso, ni en el diálogo ni en la
	 * región de estado; y el «reintenta» que ya tuviera la tarjeta lo retiraba ese
	 * mismo intento al nacer. Ahora el aviso va junto al clon (como `alert`: bajo el
	 * diálogo modal la región del body queda inerte) y vuelve a la tarjeta del tomo.
	 */
	/*
	 * REV-FT4-D3: encima del botón, con fondo opaco, el aviso tapaba la última línea
	 * de la sinopsis y, si era de una sola (Blue Lock 02, «Isagi busca su arma
	 * definitiva.»), la sinopsis entera mientras duraba el error.
	 *
	 * REV-MT2-D2: debajo del botón, con el diálogo con scroll (≥ 769 px y poca altura:
	 * el teléfono en horizontal) y deslizado hasta ver el botón entero, el aviso nacía
	 * fuera de la parte visible: 0 px a la vista a 932x430 y a 844x390, en Chromium y
	 * WebKit, y el toque parecía no hacer nada. Deslizar el diálogo hasta él movía el
	 * botón (REV-ZT2o-1, la prueba de abajo). Ahora va a la izquierda del botón y a su
	 * altura, sobre la columna de la portada: se ve siempre que se ve el botón, y la
	 * sinopsis, el botón y «Ver ficha completa» quedan a la vista. En el móvil, a
	 * 932 x 430 táctil.
	 */
	for ( const [ caso, ruta ] of [ [ 'tras la caída de la tarjeta', '/tienda/' ], [ 'sola, sinopsis de una línea', '/serie/blue-lock/' ] ] ) {
		test( `vista rápida sin respuesta (${ caso }): el aviso sale en el diálogo sin tapar nada, vuelve a la tarjeta y se anuncia`, async ( { page }, info ) => {
			test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
			const tactil = 'movil' === info.project.name;
			if ( tactil ) {
				await page.setViewportSize( { width: 932, height: 430 } ); // la vista rápida es de ≥ 769 px
			}
			await page.emulateMedia( { reducedMotion: 'reduce' } );
			let altas = 0;
			await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
				altas++;
				await new Promise( ( r ) => setTimeout( r, 300 ) );
				await ruta.abort( 'failed' );
			} );
			await page.goto( ruta, { waitUntil: 'load' } );
			await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
			// La tarjeta con la sinopsis más corta: en /serie/blue-lock/, una línea.
			const indice = await page.evaluate( () => {
				const largos = [ ...document.querySelectorAll( 'li.product:has(> .akb-qv-trigger)' ) ].map( ( li ) => {
					try {
						return ( JSON.parse( li.querySelector( '.akb-qv-trigger' ).getAttribute( 'data-akb-qv' ) || '{}' ).synopsis || '' ).length || Infinity;
					} catch ( e ) {
						return Infinity;
					}
				} );
				return largos.indexOf( Math.min( ...largos ) );
			} );
			const tarjeta = page.locator( 'li.product:has(> .akb-qv-trigger)' ).nth( '/tienda/' === ruta ? 0 : indice );
			const boton = tarjeta.locator( '.add_to_cart_button' );
			const enTarjeta = tarjeta.locator( '.akb-tarjeta-motivo' );
			await boton.scrollIntoViewIfNeeded();
			if ( '/tienda/' === ruta ) {
				await tocar( page, boton, info );
				await expect( enTarjeta ).toHaveText( 'No se pudo conectar, reintenta', { timeout: 5000 } );
			}
			// Lo que reciben desde aquí las regiones de estado del body.
			await page.evaluate( () => {
				window.akbE2eAnuncios = [];
				new MutationObserver( ( cambios ) => cambios.forEach( ( c ) => {
					const n = 1 === c.target.nodeType ? c.target : c.target.parentElement;
					const region = n && n.closest( '[role="status"]' );
					if ( region && region.textContent ) {
						window.akbE2eAnuncios.push( region.textContent );
					}
				} ) ).observe( document.body, { subtree: true, childList: true, characterData: true } );
			} );
			const antes = altas;
			await anadirDesdeLaVistaRapida( page, tarjeta, tactil );
			await expect.poll( () => altas ).toBe( antes + 1 );

			const clon = page.locator( 'dialog[open] .akb-qv__actions .add_to_cart_button' );
			const enDialogo = page.locator( 'dialog[open] .akb-qv__actions .akb-tarjeta-motivo' );
			await expect( enDialogo, 'el cliente mira el diálogo: ahí no salía nada' ).toHaveText( 'No se pudo conectar, reintenta', { timeout: 5000 } );
			await expect( enDialogo ).toBeVisible();
			await expect( enDialogo, 'la región del body es inerte bajo el diálogo modal: el aviso se anuncia solo' ).toHaveAttribute( 'role', 'alert' );
			await expect( clon ).not.toHaveClass( /\bloading\b/ );
			const sitio = await page.evaluate( () => {
				const q = ( s ) => document.querySelector( 'dialog[open] ' + s );
				// La parte visible del diálogo, que tiene scroll: no su caja entera.
				const dialogo = document.querySelector( 'dialog[open]' );
				const caja = dialogo.getBoundingClientRect();
				const d = { left: caja.left, right: caja.right, top: caja.top + dialogo.clientTop };
				d.bottom = d.top + dialogo.clientHeight;
				const aviso = q( '.akb-qv__actions .akb-tarjeta-motivo' );
				const a = aviso.getBoundingClientRect();
				const b = q( '.akb-qv__actions .add_to_cart_button' ).getBoundingClientRect();
				const corta = ( r ) => r.left < a.right && a.left < r.right && r.top < a.bottom && a.top < r.bottom;
				const aLaVista = ( n ) => {
					const r = n.getBoundingClientRect();
					const e = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
					return !! e && n.contains( e ) && ! corta( r );
				};
				const lineas = document.createRange();
				lineas.selectNodeContents( q( '.akb-qv__synopsis' ) );
				const renglones = [ ...lineas.getClientRects() ];
				const centro = document.elementFromPoint( a.left + a.width / 2, a.top + a.height / 2 );
				return {
					posicion: getComputedStyle( aviso ).position,
					// A la izquierda del botón y dentro de su franja: se ve cuando se ve él.
					alLado: a.right <= b.left && a.top >= b.top - 0.5 && a.bottom <= b.bottom + 0.5,
					dentro: a.left >= d.left && a.right <= d.right && a.top >= d.top && a.bottom <= d.bottom,
					avisoALaVista: a.height > 0 && 'visible' === getComputedStyle( aviso ).visibility && !! centro && aviso.contains( centro ),
					botonALaVista: aLaVista( q( '.akb-qv__actions .add_to_cart_button' ) ),
					// Con el diálogo deslizado sólo hasta el botón, el enlace puede quedar más abajo: basta con no taparlo.
					enlaceSinTapar: ! corta( q( '.akb-qv__link' ).getBoundingClientRect() ),
					sinopsisTapada: renglones.filter( corta ).length,
					renglones: new Set( renglones.map( ( r ) => Math.round( r.top ) ) ).size,
				};
			} );
			// Posicionado (REV-FT3-D1): en el flujo alargaba el diálogo y lo movía.
			expect( sitio.renglones, 'la sinopsis tiene texto' ).toBeGreaterThan( 0 );
			if ( '/tienda/' !== ruta ) {
				expect( sitio.renglones, 'el caso que tapaba entera: una sola línea' ).toBe( 1 );
			}
			expect( { ...sitio, renglones: undefined }, 'a la izquierda del botón, a la vista dentro del diálogo, sin tapar la sinopsis, el botón ni «Ver ficha completa»' ).toEqual( { posicion: 'absolute', alLado: true, dentro: true, avisoALaVista: true, botonALaVista: true, enlaceSinTapar: true, sinopsisTapada: 0, renglones: undefined } );

			await page.keyboard.press( 'Escape' );
			await expect( page.locator( 'dialog[open]' ) ).toHaveCount( 0 );
			await expect( enTarjeta, 'al cerrar, la tarjeta ya no decía que el tomo no se agregó' ).toBeVisible();
			await expect( enTarjeta ).toHaveText( 'No se pudo conectar, reintenta' );
			expect( await page.evaluate( () => window.akbE2eAnuncios ), 'la segunda caída no se anunciaba' ).toContain( 'No se pudo conectar, reintenta' );
			// El candado del tomo se soltó: la tarjeta reintenta.
			await boton.scrollIntoViewIfNeeded();
			await tocar( page, boton, info );
			await expect.poll( () => altas ).toBe( antes + 2 );
		} );
	}

	/*
	 * REV-ZT2o-1 (revisión 2 del lote Z-T): para enseñar el aviso, que iba debajo del
	 * botón, el diálogo se deslizaba 56 px (a 932x430 y a 844x390, donde tiene scroll).
	 * De golpe o suave, ningún reintento humano llegaba antes de que terminara: bajo el
	 * tercio izquierdo de donde estaba el botón quedaba «Ver ficha completa» y el toque
	 * sacaba al cliente del catálogo; bajo el centro, el aviso, y el toque no hacía nada.
	 * Ahora el aviso va a la izquierda del botón y a su altura: se ve siempre que se ve
	 * el botón, y el diálogo no se mueve. El cliente vuelve a tocar en el mismo punto 1 s
	 * después del aviso, primero en el tercio izquierdo y después en el centro. Ventana
	 * de teléfono en horizontal en los dos proyectos (táctil en el móvil).
	 */
	for ( const [ ancho, alto ] of [ [ 932, 430 ], [ 844, 390 ] ] ) {
		test( `vista rápida sin respuesta a ${ ancho }x${ alto }: el aviso se ve sin mover el diálogo y el reintento en el mismo punto vuelve a agregar`, async ( { page }, info ) => {
			test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
			const tactil = 'movil' === info.project.name;
			await page.setViewportSize( { width: ancho, height: alto } );
			let altas = 0;
			await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
				altas++;
				await new Promise( ( r ) => setTimeout( r, 300 ) );
				await ruta.abort( 'failed' );
			} );
			await page.goto( '/tienda/', { waitUntil: 'load' } );
			await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
			const tarjeta = page.locator( 'li.product:has(> .akb-qv-trigger)' ).first();
			await tarjeta.scrollIntoViewIfNeeded();
			if ( tactil ) {
				await tarjeta.locator( '.akb-qv-trigger' ).tap();
			} else {
				await tarjeta.hover();
				await tarjeta.locator( '.akb-qv-trigger' ).click();
			}
			const clon = page.locator( 'dialog[open] .akb-qv__actions .add_to_cart_button' );
			await clon.waitFor( { state: 'visible' } );
			// Como el cliente: desliza hasta ver el botón entero.
			const deslizado = await clon.evaluate( ( boton ) => {
				const d = /** @type {HTMLElement} */ ( boton.closest( 'dialog' ) );
				const fondo = d.getBoundingClientRect().top + d.clientTop + d.clientHeight;
				d.scrollTop += Math.max( 0, Math.ceil( boton.getBoundingClientRect().bottom - fondo ) );
				return d.scrollTop;
			} );
			expect( deslizado, 'a esta altura el diálogo tiene scroll: es el caso del defecto' ).toBeGreaterThan( 0 );
			await page.waitForTimeout( 300 );
			const b = /** @type {{x:number,y:number,width:number,height:number}} */ ( await clon.boundingBox() );
			const tocarEn = ( fx ) => ( tactil
				? page.touchscreen.tap( b.x + b.width * fx, b.y + b.height / 2 )
				: page.mouse.click( b.x + b.width * fx, b.y + b.height / 2 ) );
			const aviso = page.locator( 'dialog[open] .akb-qv__actions .akb-tarjeta-motivo' );
			await tocarEn( 0.2 );
			await expect.poll( () => altas ).toBe( 1 );
			for ( const [ punto, fx ] of [ [ 'el tercio izquierdo', 0.2 ], [ 'el centro', 0.5 ] ] ) {
				await expect( aviso ).toHaveText( 'No se pudo conectar, reintenta', { timeout: 5000 } );
				await expect( aviso ).toBeVisible();
				await page.waitForTimeout( 1000 ); // el cliente lee el aviso y vuelve a tocar donde estaba
				const vista = await aviso.evaluate( ( n ) => {
					const d = /** @type {HTMLElement} */ ( n.closest( 'dialog' ) );
					const arriba = d.getBoundingClientRect().top + d.clientTop;
					const a = n.getBoundingClientRect();
					const centro = document.elementFromPoint( a.left + a.width / 2, a.top + a.height / 2 );
					return {
						alaVista: a.height > 0 && a.top >= arriba - 0.5 && a.bottom <= arriba + d.clientHeight + 0.5 && !! centro && n.contains( centro ),
						scrollTop: d.scrollTop,
					};
				} );
				expect( vista, 'el aviso se ve y el diálogo sigue donde el cliente lo dejó' ).toEqual( { alaVista: true, scrollTop: deslizado } );
				const antes = altas;
				await tocarEn( fx );
				await expect.poll( () => altas, { message: `el reintento en ${ punto } del botón no agregaba` } ).toBe( antes + 1 );
				expect( new URL( page.url() ).pathname, 'el cliente sigue en el catálogo' ).toBe( '/tienda/' );
			}
			await page.keyboard.press( 'Escape' );
		} );
	}

	/*
	 * REV-FT1-D3: abierta con la petición de la tarjeta en vuelo, la vista rápida clona
	 * el botón con su `loading` (quick-view.js de akibara-business). Ningún intento lo
	 * quitaba: la tarjeta terminaba y volvía, y el «Añadir» del diálogo seguía atenuado.
	 */
	test( 'vista rápida abierta con la tarjeta en vuelo: su «Añadir» deja de estar atenuado cuando la tarjeta termina', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		test.skip( 'escritorio' !== info.project.name, 'la vista rápida es de escritorio' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		let altas = 0;
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			if ( 1 === ++altas ) {
				await new Promise( ( r ) => setTimeout( r, 3000 ) );
				await ruta.abort( 'failed' );
				return;
			}
			await ruta.fulfill( OK );
		} );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
		const tarjeta = page.locator( 'li.product:has(> .akb-qv-trigger)' ).first();
		const boton = tarjeta.locator( '.add_to_cart_button' );
		await boton.scrollIntoViewIfNeeded();
		await tocar( page, boton, info );
		await expect( boton ).toHaveClass( /\bloading\b/ );
		await tarjeta.hover();
		await tarjeta.locator( '.akb-qv-trigger' ).click();
		const clon = page.locator( 'dialog[open] .akb-qv__actions .add_to_cart_button' );
		await clon.waitFor( { state: 'visible' } );
		await expect( clon, 'el clon nace con el `loading` de la tarjeta en vuelo' ).toHaveClass( /\bloading\b/ );
		await expect( clon, 'el «Añadir» del diálogo seguía atenuado como si procesara' ).not.toHaveClass( /\bloading\b/, { timeout: 6000 } );
		expect( altas, 'nada más salió: sólo terminó la tarjeta' ).toBe( 1 );
		expect( await clon.evaluate( ( b ) => getComputedStyle( b ).opacity ) ).toBe( '1' );
		// Y agrega: el diálogo se cierra con el alta (quick-view.js).
		await clon.click();
		await expect.poll( () => altas ).toBe( 2 );
		await expect( page.locator( 'dialog[open]' ) ).toHaveCount( 0 );
	} );

	/*
	 * REV-FT4-D1 (revisión 4 de la ronda final F-T): el clon de la vista rápida sigue
	 * pulsable en vuelo (con la TARJETA en vuelo es el camino para volver a pedir el
	 * tomo). Con SU alta en vuelo, un segundo clic pasada la red de 2,5 s mandaba
	 * otra: la primera cerraba el diálogo y la segunda sumaba otra unidad sin avisar
	 * (medido en el espejo con altas de 4 s: 2 altas en Chromium y WebKit, a 1440 y a
	 * 820 táctil). En el móvil, a 820 x 1180 táctil (tableta).
	 */
	test( 'vista rápida: el clon con su alta en vuelo no manda otra con un clic a los 2,7 s (REV-FT4-D1)', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		const tactil = 'movil' === info.project.name;
		if ( tactil ) {
			await page.setViewportSize( { width: 820, height: 1180 } ); // la vista rápida es de ≥ 769 px
		}
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		let altas = 0;
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			altas++;
			await new Promise( ( r ) => setTimeout( r, 4000 ) );
			await ruta.fulfill( OK );
		} );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
		const tarjeta = page.locator( 'li.product:has(> .akb-qv-trigger):has(.ajax_add_to_cart)' ).first();
		await tarjeta.scrollIntoViewIfNeeded();
		if ( tactil ) {
			await tarjeta.locator( '.akb-qv-trigger' ).tap();
		} else {
			await tarjeta.hover();
			await tarjeta.locator( '.akb-qv-trigger' ).click();
		}
		const clon = page.locator( 'dialog[open] .akb-qv__actions .add_to_cart_button' );
		await clon.waitFor( { state: 'visible' } );
		await tocar( page, clon, info );
		await expect.poll( () => altas ).toBe( 1 );
		await page.waitForTimeout( 2700 ); // pasada la red de seguridad de 2,5 s
		await tocar( page, clon, info );
		await page.waitForTimeout( 300 );
		expect( await clon.evaluate( ( b ) => b.classList.contains( 'loading' ) ), 'sigue girando: su alta vuela' ).toBe( true );
		await expect( page.locator( 'dialog[open]' ), 'la primera alta cierra el diálogo' ).toHaveCount( 0, { timeout: 6000 } );
		await page.waitForTimeout( 800 );
		expect( altas, 'la segunda alta sumaba otra unidad sin avisar' ).toBe( 1 );
	} );

	/*
	 * REV-FT4-D2: abierta la vista rápida con la tarjeta en vuelo, el clon copia su
	 * giro (REV-FT1-D3). Un clic sobre él dentro del candado del tomo no sale, y
	 * WooCommerce (ajax_request_not_sent) le quitaba el `loading`: el clon quedaba en
	 * reposo, con el rótulo, mientras el alta del tomo seguía en vuelo (medido en el
	 * espejo a 1440, Chromium y WebKit).
	 */
	for ( const desenlace of [ 'sin respuesta', 'alta' ] ) {
		test( `vista rápida abierta con la tarjeta en vuelo: un clic bloqueado no le quita el giro al clon, y vuelve cuando el tomo termina (${ desenlace }) (REV-FT4-D2)`, async ( { page }, info ) => {
			test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
			test.skip( 'escritorio' !== info.project.name, 'la vista rápida es de escritorio' );
			await page.emulateMedia( { reducedMotion: 'reduce' } );
			let altas = 0;
			await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
				altas++;
				await new Promise( ( r ) => setTimeout( r, 4000 ) );
				await ( 'alta' === desenlace ? ruta.fulfill( OK ) : ruta.abort( 'failed' ) );
			} );
			await page.goto( '/tienda/', { waitUntil: 'load' } );
			await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
			const tarjeta = page.locator( 'li.product:has(> .akb-qv-trigger):has(.ajax_add_to_cart)' ).first();
			const boton = tarjeta.locator( '.add_to_cart_button' );
			await boton.scrollIntoViewIfNeeded();
			await tocar( page, boton, info );
			await expect( boton ).toHaveClass( /\bloading\b/ );
			await tarjeta.hover();
			await tarjeta.locator( '.akb-qv-trigger' ).click();
			const clon = page.locator( 'dialog.akb-qv .akb-qv__actions .add_to_cart_button' );
			await clon.waitFor( { state: 'visible' } );
			await expect( clon, 'el clon nace con el giro de la tarjeta en vuelo' ).toHaveClass( /\bloading\b/ );
			await tocar( page, clon, info ); // dentro del candado de 2,5 s: no sale
			await page.waitForTimeout( 300 );
			const estado = await clon.evaluate( ( b ) => ( { loading: b.classList.contains( 'loading' ), rotuloOculto: /^rgba\(.*,\s*0\)$/.test( getComputedStyle( b ).color ), giro: getComputedStyle( b, '::before' ).content } ) );
			expect( estado, 'el clon quedaba en reposo, con el rótulo, con el alta del tomo en vuelo' ).toEqual( { loading: true, rotuloOculto: true, giro: '""' } );
			expect( altas ).toBe( 1 );
			if ( 'alta' === desenlace ) {
				// Con alta el diálogo se cierra, pero el clon sigue en él: no queda girando.
				await expect( page.locator( 'dialog[open]' ) ).toHaveCount( 0, { timeout: 6000 } );
				await expect( clon, 'quedaba un «Añadir» en .loading en el diálogo cerrado' ).not.toHaveClass( /\bloading\b/ );
			} else {
				// El tomo termina sin alta: el clon vuelve (REV-FT1-D3).
				await expect( clon ).not.toHaveClass( /\bloading\b/, { timeout: 6000 } );
			}
			expect( altas ).toBe( 1 );
		} );
	}

	/*
	 * REV-FT2-D2 y REV-FT3-D1 (revisiones 2 y 3 de la ronda final F-T): el aviso de la
	 * red caída iba en el diálogo, en el flujo, y lo alargaba 38 px; el diálogo va
	 * centrado y subía 19 px, «Añadir» bajo el puntero y la «×» incluidos. Primero a
	 * los 6 s, cuando el aviso se iba solo; y quitado el reloj, al APARECER, si el
	 * fallo llega pasado medio segundo del clic (un 5xx, un timeout): en Chromium, un
	 * desplazamiento de diseño sin interacción reciente (0,0134 a 1440, 0,0208 a 820).
	 * Con 300 ms no se veía: cae dentro de la ventana de 500 ms de `hadRecentInput`.
	 * Ahora el aviso va posicionado encima del botón. Y el clon en vuelo gira como la
	 * tarjeta: el giro de WooCommerce iba en su ::after, que al terminar cambiaba de
	 * caja (otro desplazamiento, 0,0003). En el móvil, a 820 x 1180 táctil (tableta).
	 */
	test( 'vista rápida con la red caída tarde: el aviso no mueve el diálogo al salir ni se va solo (REV-FT2-D2, REV-FT3-D1)', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		const tactil = 'movil' === info.project.name;
		if ( tactil ) {
			await page.setViewportSize( { width: 820, height: 1180 } ); // la vista rápida es de ≥ 769 px
		}
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		let altas = 0;
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			altas++;
			await new Promise( ( r ) => setTimeout( r, 1500 ) );
			await ruta.abort( 'failed' );
		} );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
		// Los desplazamientos de diseño sin interacción reciente (Chromium; WebKit no los expone).
		const mide = await page.evaluate( () => {
			window.akbE2eSaltos = [];
			if ( ! ( PerformanceObserver.supportedEntryTypes || [] ).includes( 'layout-shift' ) ) {
				return false;
			}
			new PerformanceObserver( ( l ) => l.getEntries().forEach( ( e ) => {
				if ( ! e.hadRecentInput ) {
					window.akbE2eSaltos.push( +e.value.toFixed( 5 ) );
				}
			} ) ).observe( { type: 'layout-shift' } );
			return true;
		} );
		const tarjeta = page.locator( 'li.product:has(> .akb-qv-trigger)' ).first();
		await tarjeta.locator( '.add_to_cart_button' ).scrollIntoViewIfNeeded();
		const pulsar = async ( loc ) => {
			if ( tactil ) {
				await loc.tap();
			} else {
				await loc.click();
			}
		};
		if ( ! tactil ) {
			await tarjeta.hover();
		}
		await pulsar( tarjeta.locator( '.akb-qv-trigger' ) );
		const clon = page.locator( 'dialog[open] .akb-qv__actions .add_to_cart_button' );
		const enDialogo = page.locator( 'dialog[open] .akb-qv__actions .akb-tarjeta-motivo' );
		await clon.waitFor( { state: 'visible' } );
		const cajas = () => page.evaluate( () => {
			const arriba = ( s ) => Math.round( document.querySelector( s ).getBoundingClientRect().top );
			return { anadir: arriba( 'dialog[open] .akb-qv__actions .add_to_cart_button' ), cerrar: arriba( 'dialog[open] .akb-qv__close' ), alto: Math.round( document.querySelector( 'dialog[open]' ).getBoundingClientRect().height ) };
		} );
		const antes = await cajas();
		await pulsar( clon ); // y el puntero se queda encima

		await expect( clon ).toHaveClass( /\bloading\b/ );
		const enVuelo = await clon.evaluate( ( b ) => {
			const c = getComputedStyle( b );
			return { rotuloOculto: /^rgba\(.*,\s*0\)$/.test( c.color ), opacidad: c.opacity, giro: getComputedStyle( b, '::before' ).content, despues: getComputedStyle( b, '::after' ).display };
		} );
		expect( enVuelo, 'en vuelo gira como la tarjeta: el giro en ::before, sin rótulo, sin atenuar y sin el ::after de WooCommerce' ).toEqual( { rotuloOculto: true, opacidad: '1', giro: '""', despues: 'none' } );

		await expect( enDialogo ).toHaveText( 'No se pudo conectar, reintenta', { timeout: 5000 } );
		expect( await cajas(), 'al salir el aviso, el diálogo subía con «Añadir» y la «×»' ).toEqual( antes );
		if ( ! tactil ) {
			await page.mouse.move( 5, 450 );
		}
		await page.waitForTimeout( 6600 ); // pasados los 6 s del aviso de las tarjetas
		await expect( enDialogo, 'el aviso del diálogo se iba solo a los 6 s' ).toBeVisible();
		expect( await cajas(), '«Añadir» volvía a bajar sin que el cliente hiciera nada' ).toEqual( antes );

		// Lo retira el siguiente intento del tomo, y vuelve si también cae: sin mover nada.
		const previas = altas;
		await pulsar( clon );
		await expect.poll( () => altas ).toBe( previas + 1 );
		await expect( enDialogo ).toBeHidden();
		await expect( enDialogo ).toBeVisible( { timeout: 5000 } );
		expect( await cajas() ).toEqual( antes );
		if ( mide ) {
			expect( await page.evaluate( () => window.akbE2eSaltos ), 'desplazamientos de diseño sin interacción reciente' ).toEqual( [] );
		}
	} );

	/*
	 * El mismo fallo tardío desde la tarjeta: su aviso se medía ya metido en ella,
	 * nacía con el `bottom: 0` de loop.css y pasaba al suyo en el cuadro siguiente.
	 * Con movimiento reducido (transiciones de 0,01 ms), un desplazamiento de diseño
	 * sin interacción (lo que quedaba de REV-FT3-D1 con la tarjeta detrás del diálogo).
	 */
	test( 'tarjeta con la red caída tarde: el aviso nace encima del botón, sin desplazar nada (REV-FT3-D1)', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			await new Promise( ( r ) => setTimeout( r, 1500 ) );
			await ruta.abort( 'failed' );
		} );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
		const mide = await page.evaluate( () => {
			window.akbE2eSaltos = [];
			if ( ! ( PerformanceObserver.supportedEntryTypes || [] ).includes( 'layout-shift' ) ) {
				return false;
			}
			new PerformanceObserver( ( l ) => l.getEntries().forEach( ( e ) => {
				if ( ! e.hadRecentInput ) {
					window.akbE2eSaltos.push( { valor: +e.value.toFixed( 5 ), de: ( e.sources || [] ).map( ( s ) => s.node && ( s.node.className || s.node.nodeName ) ) } );
				}
			} ) ).observe( { type: 'layout-shift' } );
			return true;
		} );
		test.skip( ! mide, 'sólo Chromium expone los desplazamientos de diseño' );
		const tarjeta = page.locator( 'li.product:has(.add_to_cart_button.ajax_add_to_cart)' ).first();
		const boton = tarjeta.locator( '.add_to_cart_button' );
		await boton.scrollIntoViewIfNeeded();
		await tocar( page, boton, info );
		await expect( tarjeta.locator( '.akb-tarjeta-motivo' ) ).toHaveText( 'No se pudo conectar, reintenta', { timeout: 5000 } );
		await page.waitForTimeout( 300 );
		expect( await page.evaluate( () => window.akbE2eSaltos ), 'desplazamientos de diseño sin interacción reciente' ).toEqual( [] );
	} );

	/*
	 * REV-FT2-D3: el mismo botón, pulsado otra vez con el teclado pasada la red de
	 * seguridad, dejaba su segunda alta en la cola detrás de la primera. Cuando la
	 * primera salía bien, WooCommerce (updateButton) le quitaba `loading` y le ponía
	 * `added`: ✓, sin giro y con puntero con la segunda en vuelo, y el candado del
	 * tomo se soltaba: otra pulsación mandaba una tercera. Y una pulsación que ese
	 * candado bloquea también le quitaba el giro y el puntero (REV-FT3-D2). Desde
	 * REV-FT4-D1 un botón con su alta pendiente no manda otra: la segunda no llega a
	 * la cola, y la ✓ es de la única alta.
	 */
	test( 'mismo botón con su alta en vuelo: pasada la red de seguridad, ni Enter ni un clic mandan otra (REV-FT2-D3, REV-FT3-D2, REV-FT4-D1)', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		let altas = 0;
		let soltar;
		const turno = new Promise( ( r ) => { soltar = r; } );
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			if ( 0 === altas++ ) {
				await turno;
			}
			await ruta.fulfill( OK );
		} );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
		const boton = page.locator( 'li.product .add_to_cart_button.ajax_add_to_cart' ).first();
		await boton.scrollIntoViewIfNeeded();
		const girando = () => boton.evaluate( ( b ) => ( { loading: b.classList.contains( 'loading' ), added: b.classList.contains( 'added' ), punteros: getComputedStyle( b ).pointerEvents } ) );
		await tocar( page, boton, info );
		await expect.poll( () => altas ).toBe( 1 );
		await page.waitForTimeout( 2700 ); // pasada la red de seguridad de 2,5 s
		await boton.focus();
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 300 );
		expect( await girando(), 'la pulsación que no sale no le quita el giro ni el puntero' ).toEqual( { loading: true, added: false, punteros: 'none' } );
		await boton.evaluate( ( b ) => b.click() ); // lo que hace Enter en el <button>, otra vez
		await page.waitForTimeout( 300 );
		soltar();
		await expect( boton ).toHaveClass( /\badded\b/, { timeout: 5000 } );
		await page.waitForTimeout( 800 );
		expect( altas, 'la segunda esperaba en la cola y salía al terminar la primera: dos unidades' ).toBe( 1 );
		expect( await girando(), 'la ✓ es de la única alta' ).toEqual( { loading: false, added: true, punteros: 'auto' } );
	} );

	/*
	 * En vuelo, la tarjeta enseña sólo el giro. La regla del hover pesa lo mismo que
	 * la de `.loading` y va después: le devolvía el rótulo blanco, y en el móvil el
	 * toque deja el hover puesto, así que «Añadir al carrito» salía encima del giro
	 * (medido en el espejo en /tienda/ a 390 táctil, Chromium y WebKit).
	 */
	test( 'tarjeta en vuelo tras un toque: sólo el giro, sin el rótulo encima', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		let soltar;
		const turno = new Promise( ( r ) => { soltar = r; } );
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			await turno;
			await ruta.fulfill( OK );
		} );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
		const boton = page.locator( 'li.product .add_to_cart_button.ajax_add_to_cart' ).first();
		await boton.scrollIntoViewIfNeeded();
		await tocar( page, boton, info );
		await expect( boton ).toHaveClass( /\bloading\b/ );
		// Con poll: el color del botón tiene su transición.
		const aspecto = () => boton.evaluate( ( b ) => ( { rotuloOculto: /^rgba\(.*,\s*0\)$/.test( getComputedStyle( b ).color ), giro: getComputedStyle( b, '::before' ).content } ) );
		await expect.poll( aspecto, { message: 'el rótulo del hover salía encima del giro', timeout: 2000 } ).toEqual( { rotuloOculto: true, giro: '""' } );
		soltar();
		await expect( boton ).toHaveClass( /\badded\b/, { timeout: 5000 } );
	} );

	/*
	 * REV-FT3-D2: una pulsación que el candado del tomo bloquea no envía, pero
	 * WooCommerce (ajax_request_not_sent → updateButton) le quitaba el `loading` al
	 * botón aunque su alta siguiera en vuelo: sin giro y con puntero. Pasada la red de
	 * seguridad de 2,5 s, un clic o un toque mandaba otra alta del mismo tomo.
	 */
	test( 'una pulsación bloqueada a mitad del vuelo no le quita el giro al botón, y el clic de después no manda otra alta (REV-FT3-D2)', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		let altas = 0;
		let soltar;
		const turno = new Promise( ( r ) => { soltar = r; } );
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			altas++;
			await turno;
			await ruta.fulfill( OK );
		} );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		await page.locator( '.mainnav--js' ).waitFor( { state: 'attached' } );
		const boton = page.locator( 'li.product .add_to_cart_button.ajax_add_to_cart' ).first();
		await boton.scrollIntoViewIfNeeded();
		const girando = () => boton.evaluate( ( b ) => ( { loading: b.classList.contains( 'loading' ), added: b.classList.contains( 'added' ), punteros: getComputedStyle( b ).pointerEvents } ) );
		await tocar( page, boton, info );
		const candado = Date.now();
		await expect.poll( () => altas ).toBe( 1 );
		await page.waitForTimeout( 800 );
		await boton.focus();
		await page.keyboard.press( 'Enter' ); // con el candado puesto: no sale
		await page.waitForTimeout( 150 );
		expect( await girando(), 'la pulsación bloqueada le quitaba el giro y el puntero con el alta en vuelo' ).toEqual( { loading: true, added: false, punteros: 'none' } );
		await page.waitForTimeout( Math.max( 0, 2700 - ( Date.now() - candado ) ) ); // pasada la red de seguridad
		expect( await girando() ).toEqual( { loading: true, added: false, punteros: 'none' } );
		await tocar( page, boton, info );
		await page.waitForTimeout( 300 );
		expect( altas, 'el clic o el toque de después mandaba una segunda alta' ).toBe( 1 );
		soltar();
		await expect( boton ).toHaveClass( /\badded\b/, { timeout: 5000 } );
		expect( await girando(), 'la devuelve su alta, con la ✓' ).toEqual( { loading: false, added: true, punteros: 'auto' } );
		expect( altas ).toBe( 1 );
	} );

	/*
	 * Estrés: dos tarjetas del mismo tomo, otro tomo y (en escritorio) la vista rápida,
	 * con la red lenta, caídas y rechazos intercalados, y el teclado sobre un botón en
	 * vuelo. Pase lo que pase, al vaciarse la cola ningún botón queda girando, nadie
	 * se va a la ficha, cada alta salió de un toque y ningún tomo cuya última alta
	 * salió bien conserva un aviso.
	 */
	test( 'estrés: ningún botón queda girando, nadie va a la ficha y ningún aviso contradice la última alta del tomo', async ( { page }, info ) => {
		test.skip( soloLectura, 'si la intercepción fallara, agregaría al carrito: sólo contra el espejo' );
		test.setTimeout( 120_000 );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		const errores = erroresDePagina( page );
		// Por orden de llegada: [ms, desenlace].
		const guion = [ [ 1500, 'ok' ], [ 200, 'cae' ], [ 3000, 'cae' ], [ 400, 'rechazo' ], [ 100, 'ok' ], [ 2600, 'rechazo' ], [ 300, 'cae' ], [ 100, 'ok' ] ];
		const altas = [];
		let enVuelo = 0;
		await page.route( '**/?wc-ajax=add_to_cart', async ( ruta ) => {
			const [ ms, desenlace ] = guion[ altas.length % guion.length ];
			altas.push( { id: ( ruta.request().postData() || '' ).match( /product_id=(\d+)/ )?.[ 1 ], desenlace } );
			enVuelo++;
			await new Promise( ( r ) => setTimeout( r, ms ) );
			enVuelo--;
			if ( 'cae' === desenlace ) {
				await ruta.abort( 'failed' );
			} else if ( 'rechazo' === desenlace ) {
				await ruta.fulfill( { json: { error: true, product_url: '/x/' } } );
			} else {
				await ruta.fulfill( OK );
			}
		} );
		await page.route( '**/?wc-ajax=akb_get_cart*', ( r ) => r.fulfill( { json: { data: { errors: [ 'Lo sentimos, no hay stock.' ], cart_url: '/carrito/', motivo: 'sin_stock' } } } ) );

		const { a, g } = await conGemelo( page );
		const b = page.locator( 'li.product:not([data-akb-e2e]) .add_to_cart_button.ajax_add_to_cart' ).first();
		const otroId = await b.getAttribute( 'data-product_id' );
		expect( otroId ).not.toBe( await a.getAttribute( 'data-product_id' ) );
		let toques = 0;
		const enLaTienda = () => expect( page, 'nadie sale del listado: ni a la ficha (un rechazo sin envolver navegaba) ni al carrito (el toque caía en el «Ver carrito» de un aviso que tenía que haberse ido)' ).toHaveURL( /\/tienda\/$/, { timeout: 100 } );
		const pulsar = async ( boton ) => { await enLaTienda(); toques++; await boton.scrollIntoViewIfNeeded(); await tocar( page, boton, info ); };
		const teclado = async ( boton ) => { await enLaTienda(); toques++; await boton.focus(); await page.keyboard.press( 'Enter' ); };
		const cerrarCajon = () => page.keyboard.press( 'Escape' );

		await pulsar( a );
		await page.waitForTimeout( 300 );
		await pulsar( b );
		await page.waitForTimeout( 2700 );
		// El alta de A salió bien y abrió el cajón: sin cerrarlo, el toque en el gemelo
		// caía en el cajón, A volvía a salir con el teclado y el segundo Enter lo
		// pillaba con su propia alta en vuelo (que ya no sale, REV-FT4-D1).
		await cerrarCajon();
		await pulsar( g );
		await page.waitForTimeout( 200 );
		await teclado( a ); // con el candado del tomo puesto: no sale
		await page.waitForTimeout( 2600 );
		await teclado( a ); // pasada la red de seguridad: espera en la cola detrás del gemelo
		await page.waitForTimeout( 1200 );
		await cerrarCajon();
		if ( 'escritorio' === info.project.name ) {
			toques++;
			await anadirDesdeLaVistaRapida( page, page.locator( 'li.product[data-akb-e2e="a"]' ) );
		} else {
			await pulsar( g );
		}
		await page.waitForTimeout( 600 );
		await cerrarCajon();
		await pulsar( b );
		await page.waitForTimeout( 300 );
		await pulsar( a );
		await page.waitForTimeout( 3200 );
		await cerrarCajon();
		await pulsar( g );

		// La cola se vacía: nada en vuelo y ninguna alta nueva en 1,5 s.
		let vistas = -1;
		await expect.poll( async () => {
			const listo = 0 === enVuelo && vistas === altas.length;
			vistas = altas.length;
			return listo;
		}, { timeout: 30_000, intervals: [ 1500 ] } ).toBe( true );
		await page.waitForTimeout( 300 );
		await cerrarCajon();

		const fin = await page.evaluate( () => [ ...document.querySelectorAll( '.ajax_add_to_cart' ) ].map( ( b ) => {
			const caja = b.closest( 'li.product' )?.querySelector( '.akb-tarjeta-motivo' );
			return { id: b.getAttribute( 'data-product_id' ), loading: b.classList.contains( 'loading' ), aviso: !! caja && ! caja.hidden };
		} ) );
		expect( fin.filter( ( x ) => x.loading ), `botones girando al final (altas: ${ JSON.stringify( altas ) })` ).toEqual( [] );
		await enLaTienda();
		expect( altas.length, 'cada alta sale de un toque: el candado no deja pasar dobles' ).toBeLessThanOrEqual( toques );
		const ultima = {};
		altas.forEach( ( x ) => { ultima[ x.id ] = x.desenlace; } );
		for ( const x of fin.filter( ( f ) => f.aviso ) ) {
			expect( ultima[ x.id ], `aviso a la vista en el tomo ${ x.id }, cuya última alta salió bien` ).not.toBe( 'ok' );
		}
		expect( errores ).toEqual( [] );
	} );
} );
