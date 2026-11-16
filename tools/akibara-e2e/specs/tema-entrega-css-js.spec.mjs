// @ts-check
/**
 * Lo que el tema SIRVE (lote T4 «entrega-css-js», auditoría 2026-09-22).
 *
 * - PC-1 = PM-4 + PC-7: LiteSpeed no optimiza una respuesta que no se puede
 *   cachear, así que carrito, checkout, Mi cuenta y la búsqueda recibían las hojas
 *   del tema con sus comentarios (~67 KB br bloqueantes de más), y el JS del tema,
 *   excluido del optimizador a propósito, iba siempre crudo. Ahora el tema sirve
 *   copias `.min` generadas en el build.
 * - DS-10 = PC-5 = PM-13: woocommerce.css (168 KB de fuente, usada al 2-16 %) se
 *   sirve partida: cada contexto baja sólo las reglas que pueden casar en él.
 * - PC-15: empty-states.css sólo donde hay un estado vacío que la use (no en el
 *   aviso de akibara-search, rev-T4-3-D2). Lo que llega vacío por
 *   PJAX a un listado cargado CON resultados (sin esa hoja) tiene que salir con
 *   su estilo igual (revisión T4-R3).
 *
 * Se mira en las páginas NO cacheables, que son las únicas donde la URL del tema
 * llega sin reescribir (en las cacheables LiteSpeed la sustituye por la suya), y
 * el JS en todas (va con data-no-optimize). Todo es lectura salvo el último caso,
 * que agrega un tomo al carrito de la sesión de la prueba; esa sesión se borra del
 * espejo al terminar, pase o falle (revisión T4-R4, soporte/tema-espejo.mjs).
 */

import { expect, test } from '@playwright/test';
import { borrarSesionWcAlTerminar } from './soporte/tema-espejo.mjs';
import { erroresDePagina, productoConBadge, soloLectura } from './soporte/tienda.mjs';

borrarSesionWcAlTerminar();

const TEMA = '/wp-content/themes/akibara-gp/assets/';

/** Hojas y guiones propios del tema en el HTML (sin vendor/ ni fuentes). */
async function assetsDelTema( page ) {
	return page.evaluate( ( tema ) => {
		const urls = [
			...[ ...document.querySelectorAll( 'link[rel="stylesheet"], noscript' ) ].flatMap( ( n ) => ( 'NOSCRIPT' === n.tagName ? [ ...n.textContent.matchAll( /href='([^']+)'/g ) ].map( ( m ) => m[ 1 ] ) : [ n.href ] ) ),
			...[ ...document.querySelectorAll( 'script[src]' ) ].map( ( s ) => s.src ),
		];
		return [ ...new Set( urls.filter( ( u ) => u.includes( tema ) && ! u.includes( '/vendor/' ) ) ) ];
	}, TEMA );
}

const fichero = ( u ) => u.split( '?' )[ 0 ].split( '/' ).pop();

for ( const [ ruta, contexto ] of [
	[ '/carrito/', 'carrito' ],
	[ '/mi-cuenta/', 'cuenta' ],
	[ '/tienda/?_buscar=one+piece', 'listado' ],
] ) {
	test( `${ ruta }: CSS y JS del tema minificados y woocommerce.css en su parte «${ contexto }»`, async ( { page, request } ) => {
		const errores = erroresDePagina( page );
		const r = await page.goto( ruta, { waitUntil: 'load' } );
		expect( r?.status() ).toBe( 200 );

		const assets = await assetsDelTema( page );
		expect( assets.length, 'la página carga hojas y guiones del tema' ).toBeGreaterThan( 3 );
		for ( const u of assets ) {
			expect( fichero( u ), `${ fichero( u ) } se sirve sin minificar` ).toMatch( /\.min\.(css|js)$/ );
		}
		const wc = assets.filter( ( u ) => /\/woocommerce[-.]/.test( u ) ).map( fichero );
		expect( wc ).toEqual( [ `woocommerce-${ contexto }.min.css` ] );

		// Lo servido existe y responde con su tipo (una .min que falte en el ZIP
		// sería una hoja rota, no una más pesada).
		for ( const u of assets ) {
			const res = await request.get( u );
			expect( res.status(), u ).toBe( 200 );
			expect( res.headers()[ 'content-type' ] || '' ).toMatch( /\.css/.test( fichero( u ) ) ? /text\/css/ : /javascript/ );
		}
		expect( errores ).toEqual( [] );
	} );
}

test( 'el JS del tema llega minificado y fuera del optimizador también en páginas cacheables', async ( { page } ) => {
	for ( const ruta of [ '/', '/tienda/' ] ) {
		await page.goto( ruta, { waitUntil: 'domcontentloaded' } );
		const guiones = await page.evaluate( ( tema ) => [ ...document.querySelectorAll( 'script[src]' ) ]
			.filter( ( s ) => s.src.includes( tema + 'js/' ) && ! s.src.includes( '/vendor/' ) )
			.map( ( s ) => ( { src: s.src, noOptimize: s.getAttribute( 'data-no-optimize' ) } ) ), TEMA );
		expect( guiones.length, ruta ).toBeGreaterThan( 1 );
		for ( const g of guiones ) {
			expect( fichero( g.src ), `${ ruta }: ${ fichero( g.src ) }` ).toMatch( /\.min\.js$/ );
			expect( g.noOptimize, `${ fichero( g.src ) } conserva data-no-optimize` ).toBe( '1' );
		}
	}
} );

test( 'empty-states.css sólo donde hay un estado vacío (PC-15)', async ( { page } ) => {
	const tieneHoja = () => page.evaluate( () => !! document.querySelector( 'link#akibara-empty-states-css' ) );

	await page.goto( '/tienda/?_buscar=one+piece', { waitUntil: 'domcontentloaded' } );
	expect( await page.locator( 'li.product' ).count(), 'la búsqueda trae resultados' ).toBeGreaterThan( 0 );
	expect( await tieneHoja(), 'con resultados la hoja no aplicaba ni una regla y bloqueaba' ).toBe( false );

	await page.goto( '/tienda/?_buscar=zzqqxxnohay', { waitUntil: 'domcontentloaded' } );
	// Cero resultados en la rejilla; las tarjetas que salen son «Lo más vendido»
	// del tema DENTRO del aviso (UM-8, lote de integración).
	expect( await page.locator( 'li.product' ).evaluateAll( ( lis ) => lis.filter( ( li ) => ! li.closest( '[data-akb-empty]' ) ).length ) ).toBe( 0 );
	// El aviso vacío con el filtrado activo es el `.akb-empty-state` de
	// akibara-search (lo estiliza woocommerce.css) y el buscador retira
	// no-products-found.php: la hoja no casaba ni una de sus 31 reglas
	// (revisión rev-T4-3-D2). La lleva sólo el listado vacío en fail-open.
	expect( await tieneHoja(), 'el aviso es el del buscador: la hoja no aplicaba ni una regla' ).toBe( false );
	const aviso = page.locator( '.akb-empty-state' ).first();
	await expect( aviso ).toBeVisible();
	expect( await aviso.evaluate( ( el ) => getComputedStyle( el ).borderTopStyle ), 'y el aviso sigue con su estilo sin esa hoja' ).toBe( 'dashed' );

	const r404 = await page.goto( '/esta-pagina-no-existe-akbqa/', { waitUntil: 'load' } );
	expect( r404?.status() ).toBe( 404 );
	expect( await tieneHoja(), 'el 404 pinta su estado vacío' ).toBe( true );
	// Y el estado vacío del 404 está pintado, no crudo: el título va en tamaño de
	// cartel (`--text-5xl` de empty-states.css: 80 px en escritorio, ~30 en móvil).
	// Sin la hoja cae a tamaño de texto (17 / 16 px). No sirve mirar `text-transform`:
	// el título sale en mayúsculas también SIN la hoja (revisión T4-R2, 2026-09-23).
	const titulo = page.locator( '.akb-404 .akb-empty__title, .akb-empty__title' ).first();
	await expect( titulo ).toBeVisible();
	const proporcion = () => titulo.evaluate( ( el ) => parseFloat( getComputedStyle( el ).fontSize ) / parseFloat( getComputedStyle( document.body ).fontSize ) );
	expect( await proporcion(), 'el título del 404 lleva el tamaño de empty-states.css' ).toBeGreaterThan( 1.5 );

	// Control: la misma medida con la hoja bloqueada tiene que dar rojo, o esta
	// prueba no está mirando lo que dice mirar. Se bloquea la URL SERVIDA (en una
	// página cacheable LiteSpeed la reescribe a /litespeed/css/<hash>.css).
	const servida = await page.evaluate( () => document.querySelector( 'link#akibara-empty-states-css' )?.href || '' );
	expect( servida ).not.toBe( '' );
	await page.route( ( url ) => url.href === servida, ( ruta ) => ruta.abort() );
	await page.reload( { waitUntil: 'load' } );
	await expect( titulo ).toBeVisible();
	expect( await proporcion(), 'sin empty-states.css el título cae a tamaño de texto' ).toBeLessThan( 1.5 );
} );

test( 'la venta cruzada que llega por PJAX a un listado cargado con resultados sale con su estilo (T4-R3)', async ( { page } ) => {
	// El tema pinta sus «Más vendidos» DENTRO del aviso `[data-akb-empty]` del
	// buscador (`akibara_search_empty_after`), y el aviso entra por PJAX al filtrar
	// a cero. Un listado cargado CON resultados no encola empty-states.css (PC-15):
	// si la venta cruzada se estilizara ahí, llegaría con el título a tamaño de
	// texto y sin filete ni aire (medido: 17 px y 0 de relleno en vez de 28 y 48).
	const errores = erroresDePagina( page );

	// La venta cruzada REAL del tema: la misma parte que pinta el 404.
	await page.goto( '/esta-pagina-no-existe-akbqa/', { waitUntil: 'domcontentloaded' } );
	const ventaCruzada = await page.locator( 'section.akb-empty__cross-sell' ).first().evaluate( ( s ) => s.outerHTML );

	// Como la pinta el hook: dentro del aviso, en el documento Y en el fetch del
	// PJAX. Si el servidor ya la trae (el hook conectado), no se inyecta otra.
	await page.route( /\/tienda\//, async ( ruta ) => {
		const tipo = ruta.request().resourceType();
		if ( 'document' !== tipo && 'fetch' !== tipo ) {
			return ruta.continue();
		}
		const original = await ruta.fetch();
		const html = await original.text();
		// El cuerpo llega descomprimido: sin quitar estas cabeceras el navegador lo
		// intentaría descomprimir otra vez.
		const headers = Object.fromEntries( Object.entries( original.headers() ).filter( ( [ k ] ) => ! [ 'content-encoding', 'content-length' ].includes( k.toLowerCase() ) ) );
		const cuerpo = html.includes( 'akb-empty__cross-sell' ) ? html : html.replace( '<div class="akb-empty-state" data-akb-empty>', '<div class="akb-empty-state" data-akb-empty>' + ventaCruzada );
		return ruta.fulfill( { status: original.status(), headers, body: cuerpo } );
	} );

	/** Carga la tienda con resultados y va a cero por PJAX; mide la venta cruzada. */
	const aCeroPorPjax = async () => {
		await page.goto( '/tienda/', { waitUntil: 'load' } );
		expect( await page.locator( 'ul.products li.product' ).count(), 'la tienda carga con resultados' ).toBeGreaterThan( 0 );
		expect( await page.evaluate( () => !! document.querySelector( 'link#akibara-empty-states-css' ) ), 'con resultados no va empty-states.css (PC-15)' ).toBe( false );
		// Un enlace de paginación, que akb-search.js intercepta y resuelve por PJAX.
		await page.evaluate( () => { window.akbQaSinRecarga = true; } );
		const pagina = page.locator( '.woocommerce-pagination a' ).first();
		await expect( pagina, 'la tienda pagina' ).toBeAttached();
		await pagina.evaluate( ( a ) => { a.href = '/tienda/?_buscar=zzqqxxnohay'; } );
		await pagina.dispatchEvent( 'click' );
		const seccion = page.locator( '[data-akb-empty] section.akb-empty__cross-sell' ).first();
		await expect( seccion ).toBeVisible( { timeout: 20_000 } );
		expect( await page.evaluate( () => window.akbQaSinRecarga ), 'llegó por PJAX, sin recargar' ).toBe( true );
		return seccion.evaluate( ( s ) => {
			const h = s.querySelector( '.akb-empty__cross-sell-heading' );
			const cuerpo = parseFloat( getComputedStyle( document.body ).fontSize );
			return {
				titulo: parseFloat( getComputedStyle( h ).fontSize ) / cuerpo,
				filete: parseFloat( getComputedStyle( h ).borderLeftWidth ),
				relleno: parseFloat( getComputedStyle( s ).paddingTop ),
				borde: parseFloat( getComputedStyle( s ).borderTopWidth ),
			};
		} );
	};

	// Estilizada: título de cartel (28 px en escritorio, 23,5 en móvil: 1,65 y 1,45
	// veces el cuerpo), con su filete rojo, y la sección separada por su raya y aire.
	const conEstilo = await aCeroPorPjax();
	expect( conEstilo.titulo, 'el título de la venta cruzada lleva su tamaño' ).toBeGreaterThan( 1.3 );
	expect( conEstilo.filete, 'el título lleva su filete rojo' ).toBeGreaterThan( 0 );
	expect( conEstilo.relleno, 'la sección lleva su aire' ).toBeGreaterThan( 0 );
	expect( conEstilo.borde, 'la sección lleva su raya' ).toBeGreaterThan( 0 );

	// Control: con la hoja que la estiliza bloqueada, la misma medida da rojo; si no,
	// esta prueba no mira lo que dice mirar. Se bloquea la URL SERVIDA (en una página
	// cacheable LiteSpeed la reescribe a /litespeed/css/<hash>.css).
	const servida = await page.evaluate( () => document.querySelector( 'link#akibara-loop-css' )?.href || '' );
	expect( servida, 'los listados cargan loop.css' ).not.toBe( '' );
	await page.route( ( url ) => url.href === servida, ( ruta ) => ruta.abort() );
	const sinHoja = await aCeroPorPjax();
	expect( sinHoja.titulo, 'sin loop.css el título cae a tamaño de texto' ).toBeLessThan( 1.3 );
	expect( sinHoja.relleno, 'sin loop.css la sección pierde su aire' ).toBe( 0 );

	expect( errores ).toEqual( [] );
} );

test( 'los guiones minificados siguen haciendo su trabajo', async ( { page, isMobile } ) => {
	const errores = erroresDePagina( page );

	await page.goto( '/', { waitUntil: 'load' } );
	if ( isMobile ) {
		// main.min.js: el menú abre y cierra con su estado accesible.
		await page.locator( '#menu-toggle' ).tap();
		await expect( page.locator( '#menu-toggle' ) ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( page.locator( '#mobile-drawer' ) ).toHaveAttribute( 'aria-hidden', 'false' );
		await page.keyboard.press( 'Escape' );
		await expect( page.locator( '#menu-toggle' ) ).toHaveAttribute( 'aria-expanded', 'false' );
	}

	const ficha = await productoConBadge( page, 'Disponible' );
	test.skip( ! ficha, 'no hay un tomo disponible en el catálogo' );
	await page.goto( /** @type {string} */ ( ficha ), { waitUntil: 'load' } );
	// franja-inferior.min.js escribe la medida en vivo de las barras fijas.
	await expect.poll( () => page.evaluate( () => document.documentElement.style.getPropertyValue( '--akb-franja-inferior' ) ) ).not.toBe( '' );
	// sticky-atc.min.js: la barra de compra aparece al dejar atrás el formulario.
	await page.evaluate( () => window.scrollTo( 0, document.querySelector( 'form.cart' ).getBoundingClientRect().bottom + window.scrollY + 400 ) );
	await expect( page.locator( '#akb-sticky-atc' ) ).toHaveClass( /is-visible/ );

	if ( ! soloLectura ) {
		// pdp-ajax-atc.min.js + wc-event-bridge.min.js: agregar sin recargar.
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		const url = page.url();
		await page.locator( 'form.cart .single_add_to_cart_button' ).first().click();
		await expect.poll( () => page.evaluate( () => document.querySelector( 'form.cart .single_add_to_cart_button' )?.classList.contains( 'is-added' ) || !! document.querySelector( '.akb-cart-drawer.akb-open, .akb-cart-drawer[aria-hidden="false"]' ) ), { timeout: 15_000 } ).toBe( true );
		expect( page.url(), 'agregar no recarga la ficha' ).toBe( url );
	}
	expect( errores ).toEqual( [] );
} );
