// @ts-check
/**
 * Higiene SEO que sólo se ve en el HTML SERVIDO (ronda 8, 2026-09-20).
 *
 * Cuatro cosas que la suite unitaria no puede medir porque ninguna renderiza
 * una plantilla: las medidas del og:image cuando se sirve la hermana JPG, que
 * el BreadcrumbList diga lo mismo que la miga visible, que el `style.css`
 * vacío del child no viaje al cliente, y que el índice de sitemaps no le
 * ponga a cada página la fecha de otra. Todo en sólo lectura: corre igual
 * contra el espejo y contra akibara.cl. Lo que depende de Rank Math (og,
 * sitemaps) se salta donde Rank Math no está (el espejo, a propósito).
 */

import { expect, test } from '@playwright/test';

/** Primeras fichas del listado, por el markup de la rejilla. */
async function primerasFichas( request, cuantas = 3 ) {
	const html = await ( await request.get( '/tienda/' ) ).text();
	const fichas = [ ...html.matchAll( /<li[^>]*class="[^"]*\bproduct\b[^"]*"[\s\S]*?href="(https?:\/\/[^"]+)"/g ) ]
		.map( ( m ) => m[ 1 ] );
	return [ ...new Set( fichas ) ].slice( 0, cuantas );
}

function meta( html, propiedad ) {
	const m = html.match( new RegExp( `<meta[^>]+property="${ propiedad }"[^>]+content="([^"]*)"` ) )
		|| html.match( new RegExp( `<meta[^>]+content="([^"]*)"[^>]+property="${ propiedad }"` ) );
	return m ? m[ 1 ] : null;
}

/**
 * Texto visible de un nodo de la miga, sin entidades: la plantilla escapa
 * (`&#039;`, `&amp;`) y el JSON-LD lleva el nombre crudo. Sin esto, una serie
 * como «JoJo's…» daba un rojo falso al comparar los dos.
 */
function textoPlano( html ) {
	return html
		.replace( /&#0?39;|&#x27;|&apos;/g, "'" )
		.replace( /&quot;/g, '"' )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&#8211;/g, '–' )
		.replace( /&amp;/g, '&' )
		.trim();
}

/** Nodos BreadcrumbList de todos los bloques ld+json de la página. */
function migasDelSchema( html ) {
	const nodos = [];
	for ( const bloque of html.matchAll( /<script[^>]+type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g ) ) {
		let json;
		try {
			json = JSON.parse( bloque[ 1 ] );
		} catch {
			continue;
		}
		const candidatos = Array.isArray( json ) ? json : [ json, ...( json[ '@graph' ] || [] ) ];
		for ( const nodo of candidatos ) {
			if ( nodo && 'BreadcrumbList' === nodo[ '@type' ] && Array.isArray( nodo.itemListElement ) ) {
				nodos.push( nodo );
			}
		}
	}
	return nodos;
}

test.describe( 'higiene SEO en el HTML servido', () => {

	test( 'una ficha que sirve la hermana JPG como og:image declara sus medidas', async ( { request } ) => {
		const fichas = await primerasFichas( request );
		expect( fichas.length ).toBeGreaterThan( 0 );

		let medidas = 0;
		for ( const ficha of fichas ) {
			const html = await ( await request.get( ficha ) ).text();
			const imagen = meta( html, 'og:image' );
			if ( null === imagen ) {
				test.skip( true, 'Sin og:image: Rank Math no está en este entorno.' );
			}
			if ( ! imagen.endsWith( '-og.jpg' ) ) {
				continue;
			}
			const ancho = Number( meta( html, 'og:image:width' ) );
			const alto = Number( meta( html, 'og:image:height' ) );
			expect( ancho, `«${ ficha }» sirve ${ imagen } sin og:image:width` ).toBeGreaterThan( 0 );
			expect( alto, `«${ ficha }» sirve ${ imagen } sin og:image:height` ).toBeGreaterThan( 0 );
			medidas++;
		}
		test.info().annotations.push( { type: 'fichas con hermana JPG medidas', description: String( medidas ) } );
	} );

	test( 'el BreadcrumbList de una ficha tiene los mismos niveles que la miga visible', async ( { request } ) => {
		const fichas = await primerasFichas( request, 2 );
		expect( fichas.length ).toBeGreaterThan( 0 );

		for ( const ficha of fichas ) {
			const html = await ( await request.get( ficha ) ).text();
			const miga = html.match( /<nav[^>]+class="[^"]*woocommerce-breadcrumb[^"]*"[^>]*>([\s\S]*?)<\/nav>/ );
			expect( miga, `«${ ficha }» no pinta la miga` ).not.toBeNull();
			const visibles = [ ...miga[ 1 ].matchAll( /<a[^>]*>([^<]+)<\/a>/g ) ].map( ( m ) => textoPlano( m[ 1 ] ) );
			// El último nivel (la ficha) va sin enlace.
			const nivelesVisibles = visibles.length + 1;

			for ( const nodo of migasDelSchema( html ) ) {
				const nombres = nodo.itemListElement.map( ( item ) => ( item.item && item.item.name ) || item.name );
				expect( nombres.length, `«${ ficha }»: miga visible ${ nivelesVisibles } niveles, schema ${ nombres.length } (${ nombres.join( ' / ' ) })` ).toBe( nivelesVisibles );
				expect( nombres.slice( 0, visibles.length ) ).toEqual( visibles );
			}
		}
	} );

	test( 'la miga de la ficha sube a su serie y la barra la nombra', { tag: '@prod' }, async ( { request } ) => {
		// Carril SEO 2026-09-26: la landing /serie/<slug>/ es la URL que debe
		// ganar «<serie> manga». La ficha la declara como su nivel superior en
		// la miga (visible y BreadcrumbList) y el enlace de la barra dice su
		// nombre. La plantilla de WooCommerce pinta el último peldaño (la ficha)
		// SIN enlace: la serie es el ÚLTIMO <a> de la miga, y en el schema el
		// penúltimo nivel. Una serie en noindex no entra en la miga (a
		// propósito); la barra sí la enlaza, porque es navegación.
		const ruta = ( url ) => new URL( url, 'https://akibara.cl' ).pathname.replace( /\/$/, '' );
		const fichas = await primerasFichas( request, 2 );
		expect( fichas.length ).toBeGreaterThan( 0 );

		let medidas = 0;
		for ( const ficha of fichas ) {
			const html = await ( await request.get( ficha ) ).text();
			// El <a> de la serie con sus atributos en cualquier orden.
			// La etiqueta lleva el nombre en su propio <span> (bloque en línea).
			const barra = html.match( /<a\b([^>]*\bakb-series-nav__link--series\b[^>]*)>\s*<span class="akb-series-nav__series-label">([\s\S]*?)<\/span>\s*(?:<span class="akb-series-nav__series-estado"|<\/a>)/ );
			if ( ! barra ) {
				continue;
			}
			const atributos = barra[ 1 ];
			const rotulo = barra[ 2 ].replace( /<[^>]+>/g, '' );
			const serie = ( atributos.match( /\bhref="([^"]+)"/ ) || [] )[ 1 ];
			expect( serie, `«${ ficha }»: el enlace de serie no tiene href` ).toBeTruthy();
			expect( atributos, `«${ ficha }»: el enlace de serie volvió a llevar aria-label` ).not.toMatch( /aria-label/ );

			const miga = html.match( /<nav[^>]+class="[^"]*woocommerce-breadcrumb[^"]*"[^>]*>([\s\S]*?)<\/nav>/ );
			expect( miga, `«${ ficha }» no pinta la miga` ).not.toBeNull();
			const enlaces = [ ...miga[ 1 ].matchAll( /<a[^>]*href="([^"]+)"[^>]*>([^<]+)<\/a>/g ) ];
			expect( enlaces.length, `«${ ficha }»: la miga no tiene enlaces` ).toBeGreaterThan( 0 );
			const ultimo = enlaces.at( -1 );

			if ( ruta( ultimo[ 1 ] ) !== ruta( serie ) ) {
				// Sólo se admite si la landing de la serie está en noindex.
				const landing = await ( await request.get( serie ) ).text();
				expect( landing, `«${ ficha }»: el último enlace de la miga no es su serie (${ serie }) y la serie no está en noindex` )
					.toMatch( /<meta[^>]+name="robots"[^>]+content="[^"]*noindex/ );
				continue;
			}
			expect( textoPlano( rotulo ), `«${ ficha }»: la barra no nombra la serie` ).toContain( textoPlano( ultimo[ 2 ] ) );

			for ( const nodo of migasDelSchema( html ) ) {
				const penultimo = nodo.itemListElement.at( -2 );
				const id = ( penultimo.item && ( penultimo.item[ '@id' ] || penultimo.item.url ) ) || penultimo.item;
				expect( ruta( String( id ) ), `«${ ficha }»: el BreadcrumbList no sube a la serie` ).toBe( ruta( serie ) );
			}
			medidas++;
		}
		expect( medidas, 'ninguna ficha de la muestra tenía barra de serie con su serie en la miga' ).toBeGreaterThan( 0 );
	} );

	test( 'el style.css vacío del child no viaja al cliente', async ( { request } ) => {
		for ( const ruta of [ '/', '/tienda/' ] ) {
			const html = await ( await request.get( ruta ) ).text();
			expect( html, `«${ ruta }» encola el style.css del child (id generate-child-css)` ).not.toMatch( /id="generate-child-css"/ );
			expect( html, `«${ ruta }» enlaza akibara-gp/style.css` ).not.toMatch( /themes\/akibara-gp\/style\.css/ );
		}
	} );

	test( 'una serie sin tomos lo dice y enlaza al hub de series', async ( { request } ) => {
		const slug = process.env.AKB_SERIE_VACIA;
		test.skip( ! slug, 'Se indica con AKB_SERIE_VACIA=<slug> una serie que hoy no tiene tomos.' );

		const respuesta = await request.get( `/serie/${ slug }/` );
		const html = await respuesta.text();
		expect( respuesta.status() ).toBe( 200 );
		expect( html ).toMatch( /Hoy no tenemos tomos de/ );
		expect( html, 'la landing culpaba a unos filtros que el cliente no puso' ).not.toMatch( /Ningún producto coincide con estos filtros/ );
		expect( html ).toMatch( /akb-empty-hint"><a href="[^"]+\/series\/">Ver todas las series<\/a>/ );
	} );

	test( 'cada página del sitemap de producto lleva en el índice la fecha de SUS tomos', async ( { request } ) => {
		const indice = await request.get( '/sitemap_index.xml' );
		test.skip( 200 !== indice.status(), 'Sin índice de sitemaps: Rank Math no está en este entorno.' );

		const xml = await indice.text();
		const entradas = [ ...xml.matchAll( /<sitemap>\s*<loc>([^<]*product-sitemap(\d*)\.xml)<\/loc>\s*<lastmod>([^<]+)<\/lastmod>/g ) ]
			.map( ( m ) => ( { loc: m[ 1 ], pagina: m[ 2 ] || '1', lastmod: m[ 3 ] } ) );
		expect( entradas.length, 'el índice no lista sitemaps de producto' ).toBeGreaterThan( 0 );

		// Rank Math ponía a TODAS la fecha de los productos más nuevos de la
		// tienda; con más de una página, dos iguales al segundo es la firma
		// del defecto. Se contrasta la última y la penúltima contra su hijo.
		const muestra = entradas.slice( -2 );
		for ( const entrada of muestra ) {
			const hijo = await ( await request.get( entrada.loc ) ).text();
			const fechas = [ ...hijo.matchAll( /<lastmod>([^<]+)<\/lastmod>/g ) ].map( ( m ) => Date.parse( m[ 1 ] ) );
			expect( fechas.length, `${ entrada.loc } sin lastmod` ).toBeGreaterThan( 0 );
			const masNueva = Math.max( ...fechas );
			expect( Date.parse( entrada.lastmod ), `índice dice ${ entrada.lastmod } para la página ${ entrada.pagina }; su tomo más nuevo es ${ new Date( masNueva ).toISOString() }` ).toBe( masNueva );
		}
	} );
} );
