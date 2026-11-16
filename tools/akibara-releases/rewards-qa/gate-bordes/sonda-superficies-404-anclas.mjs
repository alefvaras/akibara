/**
 * sonda-superficies-404-anclas.mjs — CASO 7 del gate de SUPERFICIES Y CACHÉ.
 *
 * 7.1 Una URL inexistente devuelve 404 DE VERDAD (status, no sólo una página
 *     bonita con 200: un 200 en un no-existe envenena el edge y el índice) y
 *     además es DIGNA: título propio, salidas al catálogo, buscador operativo
 *     y sugerencias de producto que enlazan a fichas reales.
 * 7.2 El buscador del 404 lleva a resultados de verdad (cards contadas).
 * 7.3 El enlace con ancla del header —el «Saltar al contenido» a `#main`, el
 *     único ancla que la cabecera publica— aterriza con el TÍTULO VISIBLE:
 *     el destino no puede quedar tapado por el header sticky. Se mide en
 *     móvil y en escritorio, porque la cabecera cambia de alto (73 / 104 px)
 *     y `scroll-padding-top` se calcula con `--header-height`.
 *
 * Sólo lee: no siembra ni toca términos.
 *
 * Uso: node sonda-superficies-404-anclas.mjs   (exit != 0 si algo falla)
 */

import { BASE, MOVIL, ESCRITORIO, abrir, crearMarcador } from './lib-bordes.mjs';

const m = crearMarcador( 'superficies-404-anclas' );
const URL_FANTASMA = '/esta-url-no-existe-qa-superficies-20260901/';

let navegador = null;

try {
	const sesion = await abrir( MOVIL );
	navegador = sesion.navegador;
	const { contexto, pagina } = sesion;

	/* ------------------------------------------------------------------
	 * 7.1 El 404.
	 * --------------------------------------------------------------- */
	const respuesta = await pagina.goto( BASE + URL_FANTASMA, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForTimeout( 900 );

	const cuatro = await pagina.evaluate( () => {
		const main = document.querySelector( 'main' ) || document.body;
		const visto = ( n ) => !! ( n && ( n.offsetWidth || n.offsetHeight || n.getClientRects().length ) );
		const enlaces = [ ...main.querySelectorAll( 'a[href]' ) ]
			.filter( visto )
			.map( ( a ) => ( { texto: ( a.textContent || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 40 ), href: a.getAttribute( 'href' ) } ) )
			.filter( ( a ) => a.texto );
		const h1 = main.querySelector( 'h1' );
		return {
			body: document.body.className,
			titulo: document.title,
			h1: h1 ? ( h1.textContent || '' ).replace( /\s+/g, ' ' ).trim() : '',
			h1Visible: visto( h1 ),
			enlaces,
			aCatalogo: enlaces.filter( ( a ) => /\/tienda\/?$/.test( a.href ) || /^\/$|localhost:8080\/$/.test( a.href ) ).length,
			aProducto: enlaces.filter( ( a ) => /\/product\//.test( a.href ) ).length,
			buscador: !! document.querySelector( 'main input[type="search"], main [name="_buscar"]' ),
			cards: main.querySelectorAll( 'ul.products li.product, .akb-404 .product' ).length,
		};
	} );

	m.check(
		'url-inexistente-responde-404-de-verdad',
		respuesta.status() === 404 && /error404/.test( cuatro.body ),
		`HTTP ${ respuesta.status() } · body="${ cuatro.body.split( ' ' ).filter( ( c ) => /error|404/.test( c ) ).join( ' ' ) }" · <title>="${ cuatro.titulo }"`
	);

	m.check(
		'404-digno-con-titulo-y-salidas',
		cuatro.h1Visible && cuatro.h1.length > 0 && cuatro.aCatalogo > 0 && cuatro.aProducto > 0,
		`h1="${ cuatro.h1 }" (visible=${ cuatro.h1Visible }) · ${ cuatro.enlaces.length } enlaces visibles: ${ cuatro.aCatalogo } al catálogo/inicio y ${ cuatro.aProducto } a fichas · buscador propio=${ cuatro.buscador } · muestra=[${ cuatro.enlaces.slice( 0, 5 ).map( ( a ) => a.texto ).join( ' | ' ) }]`
	);

	/* ------------------------------------------------------------------
	 * 7.2 Las salidas del 404 llevan a algún sitio con producto real.
	 * --------------------------------------------------------------- */
	const primeraFicha = cuatro.enlaces.find( ( a ) => /\/product\//.test( a.href ) );
	if ( primeraFicha ) {
		const r = await pagina.goto( new URL( primeraFicha.href, BASE ).href, { waitUntil: 'domcontentloaded' } );
		const ficha = await pagina.evaluate( () => ( {
			titulo: ( document.querySelector( 'h1' )?.textContent || '' ).trim(),
			atc: document.querySelectorAll( 'button[name="add-to-cart"], .single_add_to_cart_button, form.cart button' ).length,
		} ) );
		m.check(
			'la-ficha-sugerida-por-el-404-existe',
			r.status() === 200 && ficha.titulo.length > 0,
			`${ primeraFicha.href } -> HTTP ${ r.status() } · h1="${ ficha.titulo }" · botones de compra=${ ficha.atc }`
		);
	} else {
		m.check( 'la-ficha-sugerida-por-el-404-existe', false, 'el 404 no ofreció ninguna ficha de producto' );
	}

	await pagina.goto( BASE + URL_FANTASMA, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForTimeout( 600 );
	const buscadorDel404 = await pagina.locator( 'main input[type="search"], main [name="_buscar"]' ).first();
	const hayBuscador = await buscadorDel404.count();
	if ( hayBuscador ) {
		await buscadorDel404.fill( 'frieren' );
		await buscadorDel404.press( 'Enter' );
		await pagina.waitForTimeout( 2500 );
		const resultado = await pagina.evaluate( () => ( {
			url: location.pathname + location.search,
			cards: document.querySelectorAll( 'ul.products li.product' ).length,
		} ) );
		m.check(
			'el-buscador-del-404-devuelve-cards',
			resultado.cards > 0,
			`«frieren» desde el 404 -> ${ resultado.url } con ${ resultado.cards } cards`
		);
	} else {
		m.check( 'el-buscador-del-404-devuelve-cards', false, 'el 404 no trae buscador dentro de <main>' );
	}

	/* ------------------------------------------------------------------
	 * 7.3 El ancla del header: «Saltar al contenido» -> #main.
	 *
	 * El fallo caro documentado es el destino tapado: `scroll-padding-top`
	 * y el `margin` del ancla se SUMAN, y el header sticky mide 73 px en
	 * móvil y 104 en escritorio. Se mide en los dos.
	 * --------------------------------------------------------------- */
	for ( const [ etiqueta, viewport ] of [ [ 'movil', MOVIL ], [ 'escritorio', ESCRITORIO ] ] ) {
		const p = await contexto.newPage();
		await p.setViewportSize( viewport );
		await p.goto( `${ BASE }/faq/`, { waitUntil: 'domcontentloaded' } );
		await p.waitForTimeout( 1000 );

		// Bajar primero: si la página ya está arriba, el salto no prueba nada.
		await p.evaluate( () => window.scrollTo( 0, 1200 ) );
		await p.waitForTimeout( 400 );

		const enlace = await p.evaluate( () => {
			const a = document.querySelector( 'a.skip-link[href^="#"], header a[href^="#"]' );
			return a ? { href: a.getAttribute( 'href' ), texto: ( a.textContent || '' ).trim() } : null;
		} );

		if ( ! enlace ) {
			m.check( `ancla-del-header-${ etiqueta }`, false, 'la cabecera no publica ningún enlace con ancla' );
			await p.close();
			continue;
		}

		// Tab revela el skip-link y Enter lo activa: el camino REAL del cliente
		// de teclado, no un `location.hash = …` que se salta el foco.
		await p.keyboard.press( 'Tab' );
		await p.waitForTimeout( 200 );
		await p.keyboard.press( 'Enter' );
		await p.waitForTimeout( 1400 );

		const aterrizaje = await p.evaluate( ( selector ) => {
			const destino = document.querySelector( selector );
			const header = document.querySelector( '.site-header, header.site-header, header' );
			const hs = header ? getComputedStyle( header ) : null;
			const hr = header ? header.getBoundingClientRect() : null;
			const titulo = destino ? destino.querySelector( 'h1, h2' ) : null;
			const tr = titulo ? titulo.getBoundingClientRect() : null;
			return {
				hash: location.hash,
				scrollY: Math.round( window.scrollY ),
				headerFijo: hs ? [ 'fixed', 'sticky' ].includes( hs.position ) : false,
				headerAlto: hr ? Math.round( hr.height ) : 0,
				headerBottom: hr ? Math.round( hr.bottom ) : 0,
				destinoTop: destino ? Math.round( destino.getBoundingClientRect().top ) : null,
				tituloTexto: titulo ? ( titulo.textContent || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 60 ) : '',
				tituloTop: tr ? Math.round( tr.top ) : null,
				tituloBottom: tr ? Math.round( tr.bottom ) : null,
				scrollPadding: getComputedStyle( document.documentElement ).scrollPaddingTop,
			};
		}, enlace.href );

		const headerTapa = aterrizaje.headerFijo ? aterrizaje.headerBottom : 0;
		m.check(
			`ancla-del-header-aterriza-con-el-titulo-visible-${ etiqueta }`,
			aterrizaje.hash === enlace.href &&
				aterrizaje.tituloTop !== null &&
				aterrizaje.tituloTop >= headerTapa &&
				aterrizaje.tituloBottom <= viewport.height,
			`«${ enlace.texto }» -> ${ enlace.href } · hash=${ aterrizaje.hash } · scrollY=${ aterrizaje.scrollY } · header ${ aterrizaje.headerFijo ? 'FIJO' : 'no fijo' } alto=${ aterrizaje.headerAlto }px baja hasta y=${ aterrizaje.headerBottom } · scroll-padding-top=${ aterrizaje.scrollPadding } · título "${ aterrizaje.tituloTexto }" en y=${ aterrizaje.tituloTop }..${ aterrizaje.tituloBottom } (viewport ${ viewport.height }px) · holgura bajo el header = ${ aterrizaje.tituloTop - headerTapa }px`
		);

		await p.close();
	}
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, `EXCEPCIÓN: ${ error.message }` );
} finally {
	if ( navegador ) {
		await navegador.close();
	}
}

process.exit( m.cerrar() );
