// @ts-check
/**
 * Plantillas y navegación del tema (lote T7 de la auditoría 2026-09-22).
 *
 *   - UM-13 + UC-10: «Vista rápida» deja de ser un segundo botón apilado en cada
 *     tarjeta y pasa a un icono sobre la portada; en escritorio el H1 del
 *     catálogo comparte fila con «N productos · Ordenar por». A 1440×900 la
 *     primera fila del catálogo entra entera, con su botón (antes: 0 tarjetas).
 *   - UM-10: en el teléfono cada vitrina de la portada es una fila deslizable
 *     (la portada medía 12.600 px a 390; «A pedido» empezaba en y=8.346), y las
 *     portadas que quedan de lado no se piden hasta deslizar (revisión T7-1, D2).
 *   - Revisión T7-1: con un mensaje en la fila de orden (aviso de WooCommerce,
 *     corrección o «No tenemos X de <editorial>» del buscador) la cabecera del
 *     catálogo se apila (D1); en el teléfono no hay vista rápida (D5, V[UM-13]).
 *   - UC-16: el buscador de la cabecera invita a escribir la serie o el autor.
 *   - UC-12 = UM-17: la guía de una serie la muestra antes de su primer H2 y su
 *     cierre lleva a los tomos de esa serie, no a /tienda/.
 *   - Revisión T7-2: con teclado, la fila de la portada sigue al foco hasta la
 *     tarjeta que asoma (D1; Chromium la dejaba al 16 %) sin mover la fila bajo
 *     un clic de ratón; y la tarjeta de la guía cuenta los tomos por estado con
 *     la regla de la ficha de la landing (D3), sin las filas en cero, que la
 *     guía calla desde QA-MOV-7 (akibara-business, negocio-correcciones-qa). Y, con «reducir
 *     movimiento», el panel de «Editoriales» ya no ensancha la página (se
 *     desplazaba de lado 74-281 px en escritorio, también en producción).
 *
 * Sólo lectura: no agrega nada al carrito ni toca opciones (el aviso de
 * WooCommerce se simula en la página). Vale contra el espejo y contra producción.
 */

import { expect, test } from '@playwright/test';

/** Guía ligada a su serie en el término `pa_serie` (existe en espejo y producción). */
const GUIA = '/the-climber-manga-cuantos-tomos/';
/** Entrada sin serie ligada. */
const ENTRADA_SIN_SERIE = '/que-es-el-manga-guia-para-principiantes-en-chile/';
/** Guía cuya serie tiene 0 disponibles y 0 a pedido (revisión T7-2, D3; la guía calla esas filas desde QA-MOV-7). */
const GUIA_CON_CEROS = '/el-chico-que-me-gusta-no-es-un-chico-guia-de-tomos/';

/** Las dos clases de vitrina deslizable de la portada. */
const FILAS = '.akb-section .woocommerce ul.products, .akb-section .akb-home-series__grid';

/**
 * Recorre con el teclado la fila `n` de la portada y devuelve, por cada parada
 * dentro de la fila, qué porcentaje del elemento enfocado queda dentro de ella.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number}                           n     Índice de la fila.
 * @param {'Tab'|'Shift+Tab'}                tecla Sentido.
 */
async function recorrerFila( page, n, tecla ) {
	await page.evaluate( ( { sel, n, tecla } ) => {
		const ul = /** @type {HTMLElement} */ ( document.querySelectorAll( sel )[ n ] );
		ul.scrollLeft = 'Tab' === tecla ? 0 : ul.scrollWidth;
		ul.scrollIntoView( { block: 'center' } );
		// Un punto de partida enfocable justo antes (o después) de la fila.
		const ancla = document.createElement( 'button' );
		ancla.id = 'akb-e2e-ancla';
		ancla.style.cssText = 'position:absolute;opacity:0;width:1px;height:1px';
		ul.parentNode?.insertBefore( ancla, 'Tab' === tecla ? ul : ul.nextSibling );
		ancla.focus( { preventScroll: true } );
	}, { sel: FILAS, n, tecla } );

	/** @type {{ parada: string, visible: number }[]} */
	const paradas = [];
	for ( let i = 0; i < 60; i++ ) {
		await page.keyboard.press( tecla );
		const medida = await page.evaluate( ( { sel, n } ) => {
			const ul = /** @type {HTMLElement} */ ( document.querySelectorAll( sel )[ n ] );
			const a = /** @type {HTMLElement} */ ( document.activeElement );
			if ( ! ul.contains( a ) ) {
				return null;
			}
			const r = a.getBoundingClientRect();
			const f = ul.getBoundingClientRect();
			const item = a.closest( 'li, .akb-home-series__item' );
			return {
				parada: `${ item ? [ ...( item.parentElement?.children || [] ) ].indexOf( item ) : '?' }:${ a.tagName }.${ a.classList[ 0 ] || '' }`,
				visible: r.width ? Math.round( ( Math.max( 0, Math.min( r.right, f.right, innerWidth ) - Math.max( r.left, f.left, 0 ) ) / r.width ) * 100 ) : 100,
			};
		}, { sel: FILAS, n } );
		if ( ! medida ) {
			break;
		}
		paradas.push( medida );
	}
	await page.evaluate( () => document.getElementById( 'akb-e2e-ancla' )?.remove() );
	return paradas;
}

test.describe( 'catálogo: vista rápida sobre la portada', () => {
	test( 'en el teléfono no se pinta: la tarjeta entera ya abre la ficha', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'sólo el teléfono' );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		// El botón sigue en el HTML (el mismo para todos los tamaños): sólo no se ve ni recibe foco.
		expect( await page.locator( 'ul.products li.product > .akb-qv-trigger' ).count() ).toBeGreaterThan( 0 );
		await expect( page.locator( 'ul.products li.product > .akb-qv-trigger:visible' ) ).toHaveCount( 0 );
	} );

	test( 'es un icono de 44 px sobre la portada, con nombre, y abre el diálogo', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'en el teléfono no hay vista rápida' );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );

		const tarjeta = page.locator( 'ul.products li.product:has(.akb-qv-trigger:visible)' ).first();
		await expect( tarjeta ).toBeVisible();
		const trigger = tarjeta.locator( '.akb-qv-trigger' );
		await expect( trigger ).toHaveAttribute( 'aria-label', /^Vista rápida: .+/ );

		const geo = await tarjeta.evaluate( ( li ) => {
			const r = ( el ) => el.getBoundingClientRect();
			const q = r( /** @type {Element} */ ( li.querySelector( '.akb-qv-trigger' ) ) );
			const portada = r( /** @type {Element} */ ( li.querySelector( '.akb-product-cover-wrap' ) ) );
			const enlace = r( /** @type {Element} */ ( li.querySelector( 'a.woocommerce-LoopProduct-link' ) ) );
			const cta = r( /** @type {Element} */ ( li.querySelector( '.button' ) ) );
			return { q: { w: q.width, h: q.height, x: q.x, y: q.y, r: q.right, b: q.bottom }, portada: { x: portada.x, y: portada.y, r: portada.right, b: portada.bottom }, hueco: cta.top - enlace.bottom };
		} );
		expect( geo.q.w ).toBeGreaterThanOrEqual( 44 );
		expect( geo.q.h ).toBeGreaterThanOrEqual( 44 );
		// Dentro de la portada: no ocupa una fila propia de la tarjeta.
		expect( geo.q.x ).toBeGreaterThanOrEqual( geo.portada.x );
		expect( geo.q.r ).toBeLessThanOrEqual( geo.portada.r + 0.5 );
		expect( geo.q.y ).toBeGreaterThanOrEqual( geo.portada.y );
		expect( geo.q.b ).toBeLessThanOrEqual( geo.portada.b + 0.5 );
		// Entre el texto de la tarjeta y su botón ya no cabe otro botón de 44 px.
		expect( geo.hueco, 'la vista rápida no vuelve a apilarse sobre «Añadir»' ).toBeLessThan( 30 );

		await trigger.click();
		await expect( page.locator( 'dialog.akb-qv[open]' ) ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( 'dialog.akb-qv[open]' ) ).toHaveCount( 0 );
	} );

	test( 'con teclado se llega al icono y muestra su rótulo', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'el rótulo es para teclado y ratón' );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const trigger = page.locator( 'ul.products li.product .akb-qv-trigger:visible' ).first();
		// Una tecla antes del foco: así el navegador marca :focus-visible como con Tab.
		await page.keyboard.press( 'Shift' );
		await trigger.focus();
		const estado = await trigger.evaluate( ( el ) => ( {
			visible: el.matches( ':focus-visible' ),
			rotulo: getComputedStyle( el, '::after' ).content,
		} ) );
		expect( estado.visible ).toBe( true );
		expect( estado.rotulo ).toBe( '"Vista rápida"' );
		// El rótulo entra con una transición corta: se espera a que termine.
		await expect.poll( () => trigger.evaluate( ( el ) => Number( getComputedStyle( el, '::after' ).opacity ) ) ).toBe( 1 );
	} );
} );

test.describe( 'catálogo en escritorio: primer pantallazo', () => {
	test.beforeEach( ( {}, info ) => {
		test.skip( 'movil' === info.project.name, 'la cabecera en una fila es de escritorio (≥1025 px)' );
	} );

	test( 'a 1440×900 la primera fila entra entera, con su botón', async ( { page } ) => {
		await page.setViewportSize( { width: 1440, height: 900 } );
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );

		const medida = await page.evaluate( () => {
			const tarjetas = [ ...document.querySelectorAll( 'ul.products li.product' ) ];
			const h1 = /** @type {Element} */ ( document.querySelector( '.woocommerce-products-header .page-title' ) ).getBoundingClientRect();
			const total = /** @type {Element} */ ( document.querySelector( '.akb-shop-toolbar .akb-result-count' ) ).getBoundingClientRect();
			const miga = /** @type {Element} */ ( document.querySelector( '.woocommerce-breadcrumb' ) ).getBoundingClientRect();
			return {
				conBoton: tarjetas.filter( ( t ) => {
					const b = t.querySelector( '.button' );
					return b && b.getBoundingClientRect().bottom <= innerHeight;
				} ).length,
				columnas: getComputedStyle( /** @type {Element} */ ( document.querySelector( 'ul.products' ) ) ).gridTemplateColumns.split( ' ' ).length,
				mismaFila: Math.abs( ( h1.top + h1.bottom ) / 2 - ( total.top + total.bottom ) / 2 ),
				totalTrasH1: total.left - h1.right,
				migaIzq: Math.abs( miga.left - h1.left ),
				// Contra la caja del contenido y no contra la ventana: el menú de
				// editoriales, oculto, asoma de lado un instante tras la carga.
				desborde: /** @type {Element} */ ( document.querySelector( 'ul.products' ) ).getBoundingClientRect().right
					- /** @type {Element} */ ( document.querySelector( '.woocommerce-archive-wrapper .entry-content' ) ).getBoundingClientRect().right,
			};
		} );
		expect( medida.conBoton, 'toda la primera fila, con «Añadir», sobre el pliegue' ).toBeGreaterThanOrEqual( medida.columnas );
		expect( medida.mismaFila, 'el total va en la fila del H1' ).toBeLessThan( 16 );
		expect( medida.totalTrasH1 ).toBeGreaterThan( 0 );
		expect( medida.migaIzq, 'la miga sigue alineada a la izquierda con el título' ).toBeLessThan( 24 );
		expect( medida.desborde ).toBeLessThanOrEqual( 0 );
	} );

	test( 'sin resultados, el aviso no se sale del contenido y el total va junto al título', async ( { page } ) => {
		expect( ( await page.goto( '/tienda/?_buscar=zzqxnoexiste', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const m = await page.evaluate( () => {
			const aviso = /** @type {Element} */ ( document.querySelector( '.akb-empty-state' ) ).getBoundingClientRect();
			const caja = /** @type {Element} */ ( document.querySelector( '.woocommerce-archive-wrapper .entry-content' ) ).getBoundingClientRect();
			return { derecha: aviso.right - caja.right, izquierda: aviso.left - caja.left, alto: aviso.height };
		} );
		expect( m.alto ).toBeGreaterThan( 0 );
		expect( m.derecha, 'con width:100% y su margen lateral se salía 32 px' ).toBeLessThanOrEqual( 1 );
		expect( m.izquierda ).toBeGreaterThanOrEqual( -1 );
	} );

	/**
	 * Cabecera apilada: el mensaje ocupa el ancho de la cabecera bajo el título,
	 * y el total y el orden van debajo del mensaje (antes de la revisión T7-1 el
	 * mensaje quedaba en la columna derecha, junto al H1).
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {string} selector Mensaje que se mide.
	 */
	const medirApilada = ( page, selector ) => page.evaluate( ( sel ) => {
		const r = ( s ) => /** @type {Element} */ ( document.querySelector( s ) ).getBoundingClientRect();
		const h1 = r( '.woocommerce-products-header .page-title' );
		const msg = r( sel );
		const total = r( '.akb-shop-toolbar .akb-result-count' );
		const caja = r( '.woocommerce-archive-wrapper .entry-content' );
		return {
			display: getComputedStyle( /** @type {Element} */ ( document.querySelector( '.woocommerce-archive-wrapper .entry-content' ) ) ).display,
			bajoElTitulo: msg.top - h1.bottom,
			izquierda: Math.abs( msg.left - h1.left ),
			ancho: msg.width / caja.width,
			totalBajoElMensaje: total.top - msg.bottom,
		};
	}, selector );

	test( 'con la corrección del buscador la cabecera se apila y la corrección va a todo el ancho', async ( { page } ) => {
		expect( ( await page.goto( '/tienda/?_buscar=berzerk', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const correccion = page.locator( '.akb-search-correction' );
		test.skip( ! ( await correccion.isVisible() ), 'el buscador ya no corrige «berzerk»: elegir otra falta de ortografía' );
		const m = await medirApilada( page, '.akb-search-correction' );
		expect( m.display, 'con mensaje, la cabecera no va en una fila' ).not.toBe( 'flex' );
		expect( m.bajoElTitulo ).toBeGreaterThanOrEqual( 0 );
		expect( m.izquierda, 'alineada con el título, no en la columna derecha' ).toBeLessThan( 24 );
		expect( m.ancho, 'a todo el ancho (antes: 602 de 1.056 px)' ).toBeGreaterThan( 0.9 );
		expect( m.totalBajoElMensaje ).toBeGreaterThanOrEqual( 0 );
	} );

	test( 'con «No tenemos X de <editorial>» la cabecera se apila', async ( { page } ) => {
		expect( ( await page.goto( '/tienda/?_buscar=one%20piece%20panini', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const aviso = page.locator( '.akb-relaxed' );
		test.skip( ! ( await aviso.isVisible() ), 'el catálogo ya trae One Piece de Panini: elegir otra serie/editorial' );
		const m = await medirApilada( page, '.akb-relaxed' );
		expect( m.display ).not.toBe( 'flex' );
		expect( m.bajoElTitulo ).toBeGreaterThanOrEqual( 0 );
		expect( m.izquierda ).toBeLessThan( 24 );
		expect( m.ancho ).toBeGreaterThan( 0.9 );
	} );

	test( 'un aviso de WooCommerce en la fila de orden apila la cabecera, y al irse vuelve a una fila', async ( { page } ) => {
		// Sólo lectura: el aviso se simula en la página (lo que hace el JS de
		// WooCommerce al pintar uno) en vez de agregar al carrito. `:has()` se
		// reevalúa solo; es lo mismo que pasa tras un PJAX o un error de stock.
		expect( ( await page.goto( '/tienda/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const display = () => page.locator( '.woocommerce-archive-wrapper .entry-content' ).evaluate( ( el ) => getComputedStyle( el ).display );
		expect( await display(), 'sin mensaje, en una fila' ).toBe( 'flex' );

		await page.evaluate( () => {
			const aviso = document.createElement( 'div' );
			aviso.className = 'woocommerce-message';
			aviso.setAttribute( 'role', 'alert' );
			aviso.textContent = '«Tomo de prueba» se ha añadido a tu carrito.';
			/** @type {Element} */ ( document.querySelector( '.akb-shop-toolbar .woocommerce-notices-wrapper' ) ).append( aviso );
		} );
		expect( await display() ).not.toBe( 'flex' );
		const m = await medirApilada( page, '.woocommerce-notices-wrapper > .woocommerce-message' );
		expect( m.bajoElTitulo ).toBeGreaterThanOrEqual( 0 );
		expect( m.izquierda ).toBeLessThan( 24 );
		expect( m.ancho ).toBeGreaterThan( 0.9 );

		await page.evaluate( () => document.querySelector( '.woocommerce-notices-wrapper > .woocommerce-message' )?.remove() );
		expect( await display(), 'sin el aviso, la cabecera vuelve a una fila' ).toBe( 'flex' );
	} );

	test( 'con la descripción del distrito la cabecera sigue apilada', async ( { page } ) => {
		expect( ( await page.goto( '/manga/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const apilada = await page.evaluate( () => {
			const cabecera = /** @type {Element} */ ( document.querySelector( '.woocommerce-products-header' ) ).getBoundingClientRect();
			const fila = /** @type {Element} */ ( document.querySelector( '.akb-shop-toolbar' ) ).getBoundingClientRect();
			return fila.top >= cabecera.bottom - 1;
		} );
		expect( apilada ).toBe( true );
	} );
} );

test.describe( 'portada: vitrinas', () => {
	test( 'en el teléfono cada vitrina es una fila deslizable y la página no se sale de lado', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'la fila deslizable es sólo del teléfono' );
		expect( ( await page.goto( '/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );

		const medida = await page.evaluate( () => {
			const filas = [ ...document.querySelectorAll( '.akb-section .woocommerce ul.products, .akb-section .akb-home-series__grid' ) ];
			const aPedido = [ ...document.querySelectorAll( 'main h2' ) ].find( ( h ) => /a pedido/i.test( h.textContent || '' ) );
			return {
				filas: filas.map( ( f ) => {
					const tercera = f.children[ 2 ]?.getBoundingClientRect();
					return { desliza: f.scrollWidth > f.clientWidth + 20, filasDeTarjetas: new Set( [ ...f.children ].map( ( c ) => Math.round( c.getBoundingClientRect().top ) ) ).size, asoma: tercera ? tercera.left < innerWidth : true };
				} ),
				desborde: document.documentElement.scrollWidth - innerWidth,
				alto: document.documentElement.scrollHeight,
				aPedido: aPedido ? aPedido.getBoundingClientRect().top + scrollY : Infinity,
			};
		} );

		expect( medida.filas.length ).toBeGreaterThanOrEqual( 4 );
		for ( const fila of medida.filas ) {
			expect( fila.desliza ).toBe( true );
			expect( fila.filasDeTarjetas, 'una sola fila por vitrina' ).toBe( 1 );
			expect( fila.asoma, 'la tercera tarjeta asoma: se nota que hay más' ).toBe( true );
		}
		expect( medida.desborde, 'la fila a sangre no ensancha la página' ).toBeLessThanOrEqual( 0 );
		expect( medida.alto, 'antes 12.600 px' ).toBeLessThan( 8000 );
		expect( medida.aPedido, 'antes y=8.346' ).toBeLessThan( 4500 );

		// Se desliza de verdad.
		const fila = page.locator( '.akb-section .woocommerce ul.products' ).first();
		await fila.scrollIntoViewIfNeeded();
		await fila.evaluate( ( el ) => el.scrollBy( { left: 400, behavior: 'instant' } ) );
		await expect.poll( () => fila.evaluate( ( el ) => el.scrollLeft ) ).toBeGreaterThan( 100 );
	} );

	test( 'en el teléfono las portadas de la 4.ª tarjeta en adelante esperan al gesto, y al deslizar llegan sin mover la fila', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'la fila deslizable es sólo del teléfono' );
		expect( ( await page.goto( '/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const fila = page.locator( '.akb-section .woocommerce ul.products' ).first();
		await fila.scrollIntoViewIfNeeded();
		await page.waitForTimeout( 800 );

		/** Portadas pedidas por tarjeta (1 = la red ya la trajo) y alto de la fila. */
		const estado = () => fila.evaluate( ( ul ) => {
			const pedidas = new Set( performance.getEntriesByType( 'resource' ).map( ( e ) => e.name ) );
			return {
				alto: ul.getBoundingClientRect().height,
				pedidas: [ ...ul.children ].map( ( li ) => {
					const img = li.querySelector( '.akb-product-cover-wrap img' );
					if ( ! img ) {
						return -1;
					}
					const urls = [ img.getAttribute( 'src' ), ...( img.getAttribute( 'srcset' ) || '' ).split( ',' ).map( ( c ) => c.trim().split( ' ' )[ 0 ] ) ].filter( Boolean );
					return urls.some( ( u ) => pedidas.has( new URL( /** @type {string} */ ( u ), location.href ).href ) ) ? 1 : 0;
				} ),
			};
		} );

		const antes = await estado();
		expect( antes.pedidas.slice( 0, 3 ), 'las tres que se ven (la 3.ª asoma) llegan con la página' ).toEqual( [ 1, 1, 1 ] );
		expect( antes.pedidas.slice( 3 ).filter( ( x ) => 1 === x ), 'las de más allá del borde no se piden sin gesto' ).toHaveLength( 0 );

		await fila.evaluate( ( el ) => el.scrollTo( { left: el.scrollWidth, behavior: 'instant' } ) );
		// Pedida y no «cargada»: en el espejo faltan algunas portadas (404) y eso no es de esta prueba.
		await expect.poll( async () => ( await estado() ).pedidas.at( -1 ), { message: 'la última portada se pide al deslizar' } ).toBe( 1 );
		expect( Math.abs( ( await estado() ).alto - antes.alto ), 'la fila mide lo mismo con las portadas pintadas' ).toBeLessThan( 0.5 );
	} );

	test( 'con teclado cada parada de la fila queda entera a la vista, hacia delante y hacia atrás', async ( { page }, info ) => {
		test.skip( 'movil' !== info.project.name, 'la fila deslizable es sólo del teléfono' );
		expect( ( await page.goto( '/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		// La 1.ª vitrina de productos y «Series más buscadas»: las dos clases de fila.
		const filas = await page.evaluate( ( sel ) => [ ...document.querySelectorAll( sel ) ].map( ( f ) => f.classList.contains( 'akb-home-series__grid' ) ), FILAS );
		const indices = [ filas.indexOf( false ), filas.indexOf( true ) ].filter( ( i ) => i >= 0 );
		expect( indices ).toHaveLength( 2 );

		for ( const n of indices ) {
			for ( const tecla of /** @type {const} */ ( [ 'Tab', 'Shift+Tab' ] ) ) {
				const paradas = await recorrerFila( page, n, tecla );
				expect( paradas.length, `fila ${ n }: el teclado recorre sus tarjetas` ).toBeGreaterThan( 4 );
				// Antes (Chromium, 390 px): el enlace de la 3.ª, 5.ª y 7.ª tarjeta quedaba al 16 %.
				const cortadas = paradas.filter( ( p ) => p.visible < 90 ).map( ( p ) => `${ p.parada }@${ p.visible }%` );
				expect( cortadas, `fila ${ n } con ${ tecla }: paradas que quedan fuera de la vista` ).toEqual( [] );
			}
		}
	} );

	test( 'un toque o un clic en la tarjeta que asoma abre esa ficha: la fila no se mueve bajo el dedo', async ( { page }, info ) => {
		// En escritorio la fila sólo desliza con la ventana estrecha (zoom alto, tableta con ratón).
		if ( 'movil' !== info.project.name ) {
			await page.setViewportSize( { width: 600, height: 900 } );
		}
		expect( ( await page.goto( '/', { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const fila = page.locator( '.akb-section .woocommerce ul.products' ).first();
		await fila.scrollIntoViewIfNeeded();
		await fila.evaluate( ( ul ) => {
			ul.scrollLeft = 0;
			// El scrollLeft en cada fase del gesto: si la fila se moviera entre la
			// pulsación y el clic, el clic caería en otra tarjeta.
			// @ts-ignore
			window.__fases = [];
			for ( const tipo of [ 'pointerdown', 'mousedown', 'focusin', 'mouseup', 'click' ] ) {
				// @ts-ignore
				ul.addEventListener( tipo, () => window.__fases.push( `${ tipo }:${ Math.round( ul.scrollLeft ) }` ), { capture: true } );
			}
			// La navegación se frena para leer las fases; el destino queda anotado.
			ul.addEventListener( 'click', ( e ) => {
				const a = /** @type {HTMLElement} */ ( e.target ).closest( 'a' );
				// @ts-ignore
				window.__destino = a ? a.getAttribute( 'href' ) : null;
				e.preventDefault();
			} );
		} );
		await page.waitForTimeout( 300 );

		const objetivo = await fila.evaluate( ( ul ) => {
			const f = ul.getBoundingClientRect();
			const enlaces = [ ...ul.querySelectorAll( 'a.woocommerce-LoopProduct-link' ) ];
			// El primer enlace que asoma: empieza dentro de la fila y termina fuera.
			const a = enlaces.find( ( e ) => {
				const r = e.getBoundingClientRect();
				return r.left < f.right - 12 && r.right > f.right + 12;
			} );
			if ( ! a ) {
				return null;
			}
			const r = a.getBoundingClientRect();
			return { x: Math.round( ( r.left + f.right ) / 2 ), y: Math.round( r.top + r.height / 2 ), href: a.getAttribute( 'href' ) };
		} );
		expect( objetivo, 'hay una tarjeta asomando' ).not.toBeNull();
		const { x, y, href } = /** @type {{ x: number, y: number, href: string }} */ ( objetivo );

		if ( 'movil' === info.project.name ) {
			await page.touchscreen.tap( x, y );
		} else {
			await page.mouse.click( x, y );
		}
		await expect.poll( () => page.evaluate( () => /** @type {any} */ ( window ).__destino ) ).toBe( href );
		const fases = /** @type {string[]} */ ( await page.evaluate( () => /** @type {any} */ ( window ).__fases ) );
		expect( new Set( fases.map( ( f ) => f.split( ':' )[ 1 ] ) ), `la fila no se movió durante el gesto: ${ fases.join( ' ' ) }` ).toEqual( new Set( [ '0' ] ) );
	} );

	test( 'en escritorio las vitrinas siguen siendo rejillas', async ( { page }, info ) => {
		test.skip( 'movil' === info.project.name, 'escritorio' );
		await page.goto( '/', { waitUntil: 'load' } );
		const desliza = await page.locator( '.akb-section .woocommerce ul.products' ).first().evaluate( ( el ) => el.scrollWidth > el.clientWidth + 1 );
		expect( desliza ).toBe( false );
	} );
} );

test( 'cabecera: el buscador invita a buscar series y autores, y el texto cabe', async ( { page }, info ) => {
	test.skip( 'movil' === info.project.name, 'en el teléfono el buscador es la hoja con su texto corto' );
	for ( const ancho of [ 1025, 1440 ] ) {
		await page.setViewportSize( { width: ancho, height: 900 } );
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		const r = await page.locator( '#akb-search-input' ).evaluate( ( el ) => {
			const i = /** @type {HTMLInputElement} */ ( el );
			const cs = getComputedStyle( i );
			const c = /** @type {CanvasRenderingContext2D} */ ( document.createElement( 'canvas' ).getContext( '2d' ) );
			c.font = `${ cs.fontStyle } ${ cs.fontWeight } ${ cs.fontSize } ${ cs.fontFamily }`;
			return { ph: i.placeholder, texto: c.measureText( i.placeholder ).width, caja: i.clientWidth - parseFloat( cs.paddingLeft ) - parseFloat( cs.paddingRight ) };
		} );
		expect( r.ph ).toMatch( /serie/i );
		expect( r.texto, `a ${ ancho } px el placeholder no se corta` ).toBeLessThanOrEqual( r.caja );
	}
} );

test( 'cabecera: con «reducir movimiento» el panel de Editoriales no ensancha la página y abre centrado', async ( { page }, info ) => {
	test.skip( 'movil' === info.project.name, 'el menú con paneles es de escritorio' );
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	for ( const [ ancho, alto ] of [ [ 1366, 768 ], [ 1440, 900 ] ] ) {
		await page.setViewportSize( { width: ancho, height: alto } );
		expect( ( await page.goto( GUIA, { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		const lateral = await page.evaluate( () => {
			window.scrollTo( 500, 0 );
			return { sobra: document.documentElement.scrollWidth - document.documentElement.clientWidth, x: window.scrollX };
		} );
		expect( lateral, `a ${ ancho } px la página no se desplaza de lado (antes: 111 y 74 px)` ).toEqual( { sobra: 0, x: 0 } );

		const item = page.locator( '.mainnav__item--has-sub:has(> .mainnav__sub--auto)' ).first();
		await item.hover();
		const panel = page.locator( '.mainnav__sub--auto' ).first();
		await expect( panel ).toBeVisible();
		const pos = await panel.evaluate( ( s ) => {
			const r = s.getBoundingClientRect();
			const i = /** @type {Element} */ ( s.closest( '.mainnav__item' ) ).getBoundingClientRect();
			return { desvio: Math.abs( ( r.left + r.right ) / 2 - ( i.left + i.right ) / 2 ), dentro: r.left >= 0 && r.right <= document.documentElement.clientWidth };
		} );
		expect( pos.desvio, 'abierto, centrado bajo su enlace' ).toBeLessThanOrEqual( 1 );
		expect( pos.dentro, 'y dentro de la ventana' ).toBe( true );
		await page.mouse.move( 5, alto - 5 );
	}
} );

test.describe( 'blog: la guía lleva a su serie', () => {
	test( 'tarjeta de la serie antes del primer H2 y cierre a sus tomos, sin saltos', async ( { page } ) => {
		await page.addInitScript( () => {
			// @ts-ignore
			window.__cls = 0;
			new PerformanceObserver( ( l ) => {
				for ( const e of l.getEntries() ) {
					// @ts-ignore
					if ( ! e.hadRecentInput ) window.__cls += e.value;
				}
			} ).observe( { type: 'layout-shift', buffered: true } );
		} );
		expect( ( await page.goto( GUIA, { waitUntil: 'load' } ) )?.status() ).toBe( 200 );

		const tarjeta = page.locator( '.akb-post__content .akb-guide-series' );
		await expect( tarjeta ).toHaveCount( 1 );
		const enlace = tarjeta.locator( 'a' );
		await expect( enlace ).toHaveCount( 1 );
		const destino = /** @type {string} */ ( await enlace.getAttribute( 'href' ) );
		expect( destino ).toMatch( /\/serie\/[^/]+\/$/ );

		const antesDelH2 = await page.evaluate( () => {
			const t = /** @type {Element} */ ( document.querySelector( '.akb-guide-series' ) );
			const h2 = /** @type {Element} */ ( document.querySelector( '.akb-post__content h2' ) );
			return !! ( t.compareDocumentPosition( h2 ) & Node.DOCUMENT_POSITION_FOLLOWING );
		} );
		expect( antesDelH2 ).toBe( true );
		await expect( tarjeta.locator( 'img' ) ).toHaveAttribute( 'height', /\d+/ );

		const cierre = page.locator( '.akb-post__cta a' );
		await expect( cierre ).toHaveAttribute( 'href', destino );
		await expect( cierre ).not.toHaveText( /Explorar la tienda/i );

		await tarjeta.scrollIntoViewIfNeeded();
		await page.waitForTimeout( 500 );
		// @ts-ignore
		expect( await page.evaluate( () => window.__cls ), 'la tarjeta no desplaza el texto' ).toBeLessThan( 0.1 );

		// El destino existe.
		const respuesta = await page.request.get( destino );
		expect( respuesta.status() ).toBe( 200 );
	} );

	test( 'la tarjeta cuenta los tomos por estado igual que la ficha de la landing, sin las filas en cero', async ( { page } ) => {
		for ( const guia of [ GUIA_CON_CEROS, GUIA ] ) {
			expect( ( await page.goto( guia, { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			const tarjeta = await page.locator( '.akb-guide-series' ).evaluate( ( t ) => ( {
				destino: t.querySelector( 'a' )?.getAttribute( 'href' ) || '',
				filas: [ ...t.querySelectorAll( '.akb-guide-series__stock li' ) ].map( ( li ) => ( li.textContent || '' ).replace( /\s+/g, ' ' ).trim() ),
			} ) );

			expect( ( await page.goto( tarjeta.destino, { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
			const ficha = await page.evaluate( () => [ ...document.querySelectorAll( '.akb-series-facts .akb-series-facts__item' ) ]
				.map( ( i ) => ( {
					estado: ( i.querySelector( 'dt' )?.textContent || '' ).trim(),
					tomos: ( ( i.querySelector( 'dd' )?.textContent || '' ).match( /^[\d.]+ tomos?/ ) || [ '' ] )[ 0 ],
				} ) )
				.filter( ( f ) => /^(Disponible|A pedido|Agotado)$/.test( f.estado ) || /^Preventa$/.test( f.estado ) )
				.map( ( f ) => `${ f.estado } ${ f.tomos }` ) );

			expect( ficha.length, `${ guia }: la landing pinta sus conteos` ).toBeGreaterThanOrEqual( 2 );
			// La landing es una hoja de datos y pinta sus ceros; la guía, un resumen de
			// lectura, los calla (QA-MOV-7). Lo demás, con la misma regla y las mismas palabras.
			const sinCeros = ficha.filter( ( f ) => ! / 0 tomos$/.test( f ) );
			expect( sinCeros.length, `${ guia }: la serie tiene algún tomo contado` ).toBeGreaterThanOrEqual( 1 );
			expect( tarjeta.filas, `${ guia }: la guía y su landing dicen lo mismo del mismo dato` ).toEqual( sinCeros );
		}
	} );

	test( 'una entrada sin serie ligada no pinta tarjeta y su cierre sigue yendo a la tienda', async ( { page } ) => {
		expect( ( await page.goto( ENTRADA_SIN_SERIE, { waitUntil: 'load' } ) )?.status() ).toBe( 200 );
		await expect( page.locator( '.akb-guide-series' ) ).toHaveCount( 0 );
		await expect( page.locator( '.akb-post__cta a' ) ).toHaveAttribute( 'href', /\/tienda\/$/ );
	} );
} );
