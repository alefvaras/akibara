// @ts-check
/**
 * Lo que la tienda promete en sus CABECERAS, que es lo que leen Google, el
 * borde de Cloudflare y el navegador cuando decide si vuelve a pedir la página.
 *
 * Aquí viven dos incidentes reales: fichas que respondían 500 con la página
 * entera pintada (el estado no se mira si sólo se mira el HTML) y precios
 * viejos servidos desde el borde durante horas.
 */

import { expect, test } from '@playwright/test';

test.describe( 'cabeceras y estados', { tag: '@prod' }, () => {

	test( 'las rutas vivas responden 200 y las inexistentes 404', async ( { request } ) => {
		for ( const ruta of [ '/', '/tienda/', '/carrito/' ] ) {
			const respuesta = await request.get( ruta );
			expect( respuesta.status(), `«${ ruta }» tiene que ser 200` ).toBe( 200 );
		}

		const inexistente = await request.get( '/esta-url-no-existe-akibara-qa/' );
		expect( inexistente.status(), 'un 404 que responde 200 envenena el índice de Google' ).toBe( 404 );
	} );

	test( 'ninguna página pública se sirve con un 5xx escondido', async ( { request } ) => {
		// El 2026-09-18 las fichas respondían 500 con el HTML completo: sin mirar
		// el estado, la página «se veía bien».
		const listado = await request.get( '/tienda/' );
		const html = await listado.text();

		const enlaces = [ ...html.matchAll( /href="(https?:\/\/[^"]*?)"/g ) ]
			.map( ( m ) => m[ 1 ] )
			.filter( ( href ) => /\/[a-z0-9-]+\/$/.test( href ) )
			// Cuatro basta para cazar un 5xx generalizado —el del 2026-09-18 salía
			// en TODAS las fichas— sin convertir la suite en un rastreo.
			.slice( 0, 4 );

		expect( enlaces.length ).toBeGreaterThan( 0 );

		for ( const enlace of enlaces ) {
			const respuesta = await request.get( enlace );
			expect( respuesta.status(), `«${ enlace }» respondió ${ respuesta.status() }` ).toBeLessThan( 400 );
		}
	} );

	test( 'el carrito y el pago NO se guardan en caché compartida', async ( { request } ) => {
		for ( const ruta of [ '/carrito/', '/checkout/' ] ) {
			const respuesta = await request.get( ruta, { maxRedirects: 0 } ).catch( () => null );
			if ( ! respuesta || respuesta.status() >= 400 ) {
				continue;
			}
			const cache = ( respuesta.headers()[ 'cache-control' ] || '' ).toLowerCase();
			expect( cache, `«${ ruta }» sin no-store puede servirle a un cliente el carrito de otro` ).toMatch( /no-store|no-cache|private/ );
		}
	} );

	test( 'la ficha declara su canónica', async ( { request } ) => {
		const listado = await request.get( '/tienda/' );
		const html = await listado.text();
		const ficha = [ ...html.matchAll( /href="(https?:\/\/[^"]*?\/[a-z0-9-]+\/)"/g ) ].map( ( m ) => m[ 1 ] )[ 0 ];
		test.skip( ! ficha, 'no se encontró ninguna ficha en el listado' );

		const respuesta = await request.get( String( ficha ) );
		const html2 = await respuesta.text();

		expect( html2, 'sin canónica, las variantes con parámetros compiten entre sí en Google' ).toMatch( /rel=["']canonical["']/ );
	} );
} );
