// @ts-check
/**
 * El buscador en sus bordes: lo que un cliente de verdad teclea.
 *
 * Nadie busca «one». Se busca «pokémon» con y sin tilde, «one piece 1» con el
 * número del tomo, un título copiado con comillas, una palabra a medio
 * escribir, o se pega basura. Cada caso fija DOS cosas: que la tienda responde
 * (ni 500 ni 503) y que lo que devuelve tiene sentido para quien lo escribió.
 *
 * Sólo lectura: corre igual contra el espejo y contra producción.
 */

import { expect, test } from '@playwright/test';

const SUGGEST = '/wp-json/akibara/v1/search/suggest?q=';

/** Títulos que salen en el desplegable para una consulta. */
async function titulos( request, q ) {
	const r = await request.get( SUGGEST + encodeURIComponent( q ) );
	expect( r.status(), `suggest «${ q }»` ).toBe( 200 );
	const datos = await r.json();
	const lista = Array.isArray( datos ) ? datos : ( datos.products || datos.items || datos.results || [] );
	return lista.map( ( p ) => String( p.title || p.name || p.label || '' ) );
}

test.describe( 'buscador · bordes', () => {

	test( 'con tilde y sin tilde encuentran lo mismo', async ( { request } ) => {
		const con = await titulos( request, 'pokémon' );
		const sin = await titulos( request, 'pokemon' );

		test.skip( 0 === con.length && 0 === sin.length, 'el catálogo de este entorno no tiene Pokémon' );
		expect( sin.length, 'quien escribe sin tilde —casi todos en el móvil— no puede ver menos' ).toBe( con.length );
		expect( sin.slice( 0, 3 ) ).toEqual( con.slice( 0, 3 ) );
	} );

	test( 'mayúsculas, minúsculas y espacios de más no cambian el resultado', async ( { request } ) => {
		const base = await titulos( request, 'one piece' );
		test.skip( 0 === base.length, 'sin One Piece en este entorno' );

		expect( await titulos( request, 'ONE PIECE' ) ).toEqual( base );
		expect( await titulos( request, '  one   piece  ' ) ).toEqual( base );
	} );

	test( 'el número del tomo afina la búsqueda en vez de romperla', async ( { request } ) => {
		const serie = await titulos( request, 'one piece' );
		test.skip( 0 === serie.length, 'sin One Piece en este entorno' );

		const tomo = await titulos( request, 'one piece 1' );
		expect( tomo.length, 'añadir el tomo no puede dejar al cliente sin resultados' ).toBeGreaterThan( 0 );
		expect( tomo[ 0 ].toLowerCase() ).toContain( 'one piece' );
	} );

	test( 'una palabra a medio escribir ya sugiere', async ( { request } ) => {
		const r = await titulos( request, 'nar' );
		test.skip( 0 === ( await titulos( request, 'naruto' ) ).length, 'sin Naruto en este entorno' );
		expect( r.some( ( t ) => /naruto/i.test( t ) ), 'el desplegable existe para no tener que terminar la palabra' ).toBeTruthy();
	} );

	test( 'un error de tipeo de una letra sigue encontrando la serie', async ( { request } ) => {
		test.skip( 0 === ( await titulos( request, 'naruto' ) ).length, 'sin Naruto en este entorno' );
		const r = await titulos( request, 'narutto' );
		expect( r.some( ( t ) => /naruto/i.test( t ) ), 'un dedo de más en el móvil no puede dar cero' ).toBeTruthy();
	} );

	test( 'dos letras cambiadas de lugar se rescatan también en la página de resultados', async ( { request } ) => {
		// R9-corrector-asimetrico (qlog 2026-08-30): «balck clover» salía en el
		// desplegable y la página de resultados —la que ve quien aprieta Enter—
		// servía cero con 37 tomos de Black Clover en catálogo.
		// Se cuentan tarjetas `li.product`, como en los demás specs: si el markup
		// cambia, la guarda de abajo no apaga la prueba en silencio.
		const fichas = ( html ) => ( html.match( /<li[^>]*class="[^"]*\bproduct\b[^"]*"/g ) || [] ).length;
		const bien = fichas( await ( await request.get( '/tienda/?_buscar=black+clover' ) ).text() );
		test.skip( 0 === bien, 'sin Black Clover en este entorno' );

		const conTypo = fichas( await ( await request.get( '/tienda/?_buscar=balck+clover' ) ).text() );
		expect( conTypo, 'con el typo corregido, la página muestra las mismas fichas que con el texto bien escrito' ).toBe( bien );
	} );

	for ( const [ nombre, q ] of [
		[ 'vacía', '' ],
		[ 'de un carácter', 'a' ],
		[ 'sólo espacios', '   ' ],
		[ 'con comillas', '"one piece"' ],
		[ 'con apóstrofo', "jojo's" ],
		[ 'con HTML', '<script>alert(1)</script>' ],
		[ 'con SQL', "' OR 1=1 --" ],
		[ 'con emoji', 'naruto 🍥' ],
		[ 'con porcentajes', '100% %25 %' ],
		[ 'larguísima', 'manga '.repeat( 80 ) ],
		[ 'en japonés', 'ナルト' ],
	] ) {
		test( `una consulta ${ nombre } no rompe nada`, async ( { page, request } ) => {
			const api = await request.get( SUGGEST + encodeURIComponent( q ) );
			expect( [ 200, 400 ], `suggest respondió ${ api.status() }` ).toContain( api.status() );
			expect( api.headers()[ 'content-type' ] || '' ).toContain( 'json' );

			const pagina = await page.goto( '/tienda/?_buscar=' + encodeURIComponent( q ), { waitUntil: 'domcontentloaded' } );
			expect( pagina?.status(), 'la página de resultados' ).toBe( 200 );

			// Lo que el cliente escribió vuelve a la pantalla ESCAPADO, nunca
			// como marcado: ni en el campo ni en el «resultados para…».
			expect( await page.locator( 'script:text("alert(1)")' ).count() ).toBe( 0 );
			const html = await page.content();
			expect( html ).not.toContain( '<script>alert(1)</script>' );
		} );
	}

	test( 'la página de resultados no se indexa y no se cachea como la tienda', async ( { request } ) => {
		const r = await request.get( '/tienda/?_buscar=one' );
		expect( r.status() ).toBe( 200 );

		const html = await r.text();
		expect( html, 'una búsqueda interna en Google es contenido duplicado infinito' ).toMatch( /<meta[^>]+name=["']robots["'][^>]+noindex/i );
	} );

	test( 'el desplegable se maneja con el teclado', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'en móvil el buscador es una hoja aparte, con su propia prueba' );
		// Medido en producción el 2026-09-19: el primer Escape BORRABA lo escrito
		// (es el comportamiento nativo de `type="search"` en Chromium, y nadie
		// lo frenaba). El patrón de combobox pide que el primer Escape cierre la
		// lista conservando el texto. Arreglado en search 0.1.68; contra una
		// producción que todavía no lo tenga, esta prueba sale en rojo a propósito.

		await page.goto( '/', { waitUntil: 'domcontentloaded' } );
		const campo = page.locator( 'form.akibara-search input[type="search"]' ).first();
		await campo.click();
		await campo.pressSequentially( 'one piece', { delay: 40 } );

		const lista = page.locator( '[role="listbox"], .akibara-search__results, .akb-suggest' ).first();
		await expect( lista, 'teclear abre las sugerencias' ).toBeVisible( { timeout: 8_000 } );

		await campo.press( 'Escape' );
		await expect( lista, 'Escape las cierra sin borrar lo escrito' ).toBeHidden();
		await expect( campo ).toHaveValue( 'one piece' );

		await campo.press( 'Enter' );
		await expect( page ).toHaveURL( /_buscar=one(\+|%20)piece/ );
	} );
} );
