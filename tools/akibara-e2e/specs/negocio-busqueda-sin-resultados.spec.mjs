// @ts-check
/**
 * La búsqueda sin resultados tiene salida.
 *
 * Auditoría del tema, 2026-09-22 (UC-1 = UM-8): /tienda/?_buscar=inuyasha
 * servía sólo «Sin resultados para…» y una pista; la única salida era el menú.
 * Medido en producción el 2026-09-23: 50 de 173 búsquedas enviadas en 30 días
 * terminaron en cero. Desde akibara-search 0.1.75:
 *
 *  - el motor ofrece la serie que más se parece a lo escrito («frieren
 *    novela» → Frieren) y lo más buscado, DENTRO del aviso `[data-akb-empty]`;
 *    nunca otra obra: «monster high» no ofrece Monster (QA-SRCH-1);
 *  - si el texto sí trae productos y un filtro marcado deja el listado en cero,
 *    el aviso habla del filtro y no ofrece la misma serie (QA-SRCH-4); en un
 *    archivo, productos de ese archivo (REV-FS1-1), y en uno sin faceta dueña
 *    («Sin categorizar»), donde no se sabe, habla de la búsqueda (REV-FS-1-1);
 *  - el tema pinta su venta cruzada en `akibara_search_empty_after`, también
 *    dentro del aviso. Esa venta cruzada es una `ul.products`, y el PJAX la
 *    tomaba por la rejilla del catálogo: al pasar de cero a N resultados la
 *    tienda se quedaba SIN productos. El intercambio se prueba con la venta
 *    cruzada que sirve el tema de verdad (hasta el lote de integración se
 *    inyectaba la del 404 en las respuestas; con el tema actual quedaba
 *    duplicada).
 *
 * Todo es de lectura: vale contra el espejo y contra producción.
 */

import { expect, test } from '@playwright/test';
import { erroresDePagina } from './soporte/tienda.mjs';

/**
 * Serie del catálogo que casa con el principio de la búsqueda, con una cola de
 * formato. Hasta QA-SRCH-1 era «frieren preludio»: una serie de una palabra
 * salía ante cualquier cola, y así también «monster high» → Monster.
 */
const BUSQUEDA_CON_SERIE = '/tienda/?_buscar=frieren+novela';

/** Búsqueda que no casa con nada del catálogo. */
const BUSQUEDA_SIN_NADA = '/tienda/?_buscar=gundam';

/**
 * Foto del catálogo tal como lo ve el cliente: rejillas fuera del aviso, el
 * aviso y la venta cruzada.
 *
 * @param {import('@playwright/test').Page} page Página.
 */
function foto( page ) {
	return page.evaluate( () => {
		const aviso = document.querySelector( '[data-akb-empty]' );
		const fuera = Array.from( document.querySelectorAll( 'ul.products' ) ).filter( ( u ) => ! u.closest( '[data-akb-empty]' ) );
		const orden = ( sel ) => {
			const el = document.querySelector( sel );
			return el ? Array.from( document.querySelectorAll( '*' ) ).indexOf( el ) : -1;
		};
		return {
			rejillas: fuera.map( ( u ) => u.querySelectorAll( 'li.product' ).length ),
			avisoVisible: !! aviso && ! aviso.hidden && aviso.getBoundingClientRect().height > 0,
			ventaCruzadaEnAviso: aviso ? aviso.querySelectorAll( '.akb-empty__cross-sell li.product' ).length : 0,
			ventaCruzadaFuera: Array.from( document.querySelectorAll( '.akb-empty__cross-sell' ) ).filter( ( s ) => ! s.closest( '[data-akb-empty]' ) ).length,
			barraAntesDeRejilla: fuera.length > 0 && orden( '.akb-shop-toolbar' ) >= 0 && orden( '.akb-shop-toolbar' ) < Array.from( document.querySelectorAll( '*' ) ).indexOf( fuera[ 0 ] ),
		};
	} );
}

/**
 * Series que ofrece «Quizá buscabas» para cada búsqueda, contra lo esperado.
 * Una búsqueda que ya trae tomos no mide nada: se anota y se salta.
 *
 * @param {import('@playwright/test').Page} page  Página.
 * @param {Array<[string, Array<string|RegExp>]>} casos Búsqueda y rutas esperadas, en orden.
 */
async function comprobarSeries( page, casos ) {
	for ( const [ busqueda, esperadas ] of casos ) {
		const respuesta = await page.goto( `/tienda/?_buscar=${ busqueda }`, { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status(), busqueda ).toBe( 200 );

		const aviso = page.locator( '[data-akb-empty]' );
		if ( await aviso.isHidden() ) {
			// El catálogo cambió y la búsqueda ya trae tomos: este caso ya no mide nada.
			test.info().annotations.push( { type: 'omitido', description: `«${ busqueda }» ya tiene resultados` } );
			continue;
		}
		const series = await aviso.locator( 'a.akb-empty-suggest__item--series' ).evaluateAll(
			( enlaces ) => enlaces.map( ( a ) => new URL( a.href ).pathname )
		);
		expect( series.length, `cuántas series ofrece «${ busqueda }»: ${ series.join( ', ' ) }` ).toBe( esperadas.length );
		esperadas.forEach( ( esperada, i ) => {
			if ( esperada instanceof RegExp ) {
				expect( series[ i ], `serie ${ i + 1 } de «${ busqueda }»` ).toMatch( esperada );
			} else {
				expect( series[ i ], `serie ${ i + 1 } de «${ busqueda }»` ).toBe( esperada );
			}
		} );
		// Con o sin serie, el aviso sigue teniendo salida: lo más buscado.
		expect( await aviso.locator( '.akb-empty-suggest__item:not(.akb-empty-suggest__item--series)' ).count(), busqueda ).toBeGreaterThan( 0 );
	}
}

test.describe( 'búsqueda sin resultados', () => {

	test( 'ofrece la serie parecida y lo más buscado, y las dos salidas llevan a algo', async ( { page, isMobile } ) => {
		const respuesta = await page.goto( BUSQUEDA_CON_SERIE, { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status(), 'cero resultados sigue siendo una página' ).toBe( 200 );

		const aviso = page.locator( '[data-akb-empty]' );
		await expect( aviso ).toBeVisible();
		await expect( aviso ).toContainText( 'Sin resultados para «frieren novela».' );

		// La serie: un enlace a su landing, dentro del aviso.
		const serie = aviso.locator( 'a.akb-empty-suggest__item--series' );
		await expect( serie ).toHaveCount( 1 );
		await expect( serie ).toHaveAttribute( 'href', /\/serie\/frieren\/$/ );
		await expect( serie ).toContainText( 'Frieren' );

		// Lo más buscado: búsquedas de la tienda ENTERA, que no se siguen.
		const populares = aviso.locator( '.akb-empty-suggest__item:not(.akb-empty-suggest__item--series)' );
		expect( await populares.count(), 'el top de búsquedas no puede salir vacío en esta tienda' ).toBeGreaterThan( 0 );
		for ( const enlace of await populares.all() ) {
			await expect( enlace ).toHaveAttribute( 'href', /\/tienda\/\?_buscar=/ );
			await expect( enlace ).toHaveAttribute( 'rel', 'nofollow' );
		}

		// Blancos táctiles: 44 px en el móvil, como los chips de los filtros.
		const minimo = isMobile ? 44 : 32;
		for ( const enlace of await aviso.locator( '.akb-empty-suggest__item' ).all() ) {
			const caja = await enlace.boundingBox();
			expect( caja?.height ?? 0, await enlace.innerText() ).toBeGreaterThanOrEqual( minimo );
		}

		// Nada se sale del ancho de la pantalla.
		const desborde = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
		expect( desborde ).toBeLessThanOrEqual( 0 );

		// La serie lleva a una landing con tomos.
		await Promise.all( [ page.waitForURL( /\/serie\/frieren\/$/ ), serie.click() ] );
		await expect( page.locator( 'ul.products li.product' ).first() ).toBeVisible();

		// Y lo más buscado, a resultados.
		await page.goto( BUSQUEDA_CON_SERIE, { waitUntil: 'domcontentloaded' } );
		const primera = page.locator( '[data-akb-empty] .akb-empty-suggest__item:not(.akb-empty-suggest__item--series)' ).first();
		await Promise.all( [ page.waitForURL( /_buscar=/ ), primera.click() ] );
		await expect( page.locator( 'ul.products li.product' ).first() ).toBeVisible();
		await expect( page.locator( '[data-akb-empty]' ) ).toBeHidden();
	} );

	test( 'no ofrece otra obra que sólo comparte la primera palabra', async ( { page } ) => {
		// Revisión B4-R1, medido en el espejo con las búsquedas en cero del qlog:
		// al recortar la cola, «tokyo ghoul» ofrecía Tokyo Revengers y «black
		// butler» Black Clover bajo «Quizá buscabas». La serie tiene que estar
		// ENTERA al principio de lo escrito.
		await comprobarSeries( page, [
			[ 'tokyo+ghoul', [] ],
			[ 'black+butler', [] ],
			[ 'blue+exorcist', [] ],
			[ 'king+the+land', [] ],
			// Naruto tiene una sola palabra: lo que sigue sólo puede ser cola (QA-SRCH-1).
			[ 'naruto+shippuden+72', [] ],
			// La Kanzenban encabeza lo escrito: gana a las que sólo continúan «saint seiya».
			[ 'saint+seiya+edicion+kanzenban+22', [ '/serie/saint-seiya-ed-kanzenban/' ] ],
			// One Piece encabeza: gana a One Piece 3 en 1, que sólo continúa «one piece».
			[ 'one+piece+film+red', [ '/serie/one-piece/' ] ],
		] );
	} );

	test( 'ofrece la serie con subtítulo cuando lo escrito la nombra con dos palabras', async ( { page } ) => {
		// Revisión B4-2-R1 (2026-09-23): exigir que la serie entera encabezara lo
		// escrito dejó fuera a toda la familia de series con subtítulo o edición en
		// el nombre —«kaguya sama novela», «re zero novela»—, que salían en cero y
		// sin «Quizá buscabas» aunque tienen landing.
		await comprobarSeries( page, [
			[ 'kaguya+sama+novela', [ '/serie/kaguya-sama-love-is-war/' ] ],
			[ 're+zero+novela', [ /^\/serie\/rezero-chapter-[a-z]+\/$/, /^\/serie\/rezero-chapter-[a-z]+\/$/ ] ],
			[ 'demon+slayer+kimetsu+novela', [ '/serie/demon-slayer-kimetsu-no-yaiba/' ] ],
			// Números y formato también son cola.
			[ 'kaguya+sama+tomo+3', [ '/serie/kaguya-sama-love-is-war/' ] ],
			// Y las palabras ignoradas y las coletillas en castellano (revisión
			// RN1-D1): con la cola sólo de formato, las tres quedaban sin serie.
			[ 'kaguya+sama+en+espa%C3%B1ol', [ '/serie/kaguya-sama-love-is-war/' ] ],
			[ 'slam+dunk+edicion+de+lujo', [ '/serie/slam-dunk-deluxe/' ] ],
			[ 're+zero+la+novela+ligera', [ /^\/serie\/rezero-chapter-[a-z]+\/$/, /^\/serie\/rezero-chapter-[a-z]+\/$/ ] ],
			// Y cómo se compra y la temporada en ordinal (revisión RN-REV1-1): el
			// vocabulario de la propia tienda dejaba la búsqueda sin serie.
			[ 'kaguya+sama+a+pedido', [ '/serie/kaguya-sama-love-is-war/' ] ],
			[ 'demon+slayer+preventa', [ '/serie/demon-slayer-kimetsu-no-yaiba/' ] ],
			[ 'kaguya+sama+3ra+temporada', [ '/serie/kaguya-sama-love-is-war/' ] ],
		] );

		// Y la serie lleva a su landing, con tomos.
		await page.goto( '/tienda/?_buscar=kaguya+sama+novela', { waitUntil: 'domcontentloaded' } );
		const serie = page.locator( '[data-akb-empty] a.akb-empty-suggest__item--series' );
		test.skip( ( await serie.count() ) === 0, '«kaguya sama novela» ya tiene resultados' );
		// Basta con que el documento llegue: los tomos se esperan en la línea
		// siguiente, y el «load» de una landing con portadas se pasaba del
		// tiempo de la prueba con el espejo cargado (medido 2026-09-24).
		await Promise.all( [ page.waitForURL( /\/serie\/kaguya-sama-love-is-war\/$/, { waitUntil: 'domcontentloaded' } ), serie.click() ] );
		await expect( page.locator( 'ul.products li.product' ).first() ).toBeVisible();
	} );

	test( 'ofrece la serie aunque lo que sigue vaya en plural, en el otro género o nombre un tomo alto', async ( { page } ) => {
		// Revisión RN-REV2-1 (2026-09-24): la lista de palabras de formato
		// nombraba «ligera» pero no «ligeras», «nuevo» pero no «nueva», y los
		// ordinales paraban en «quinto»; «3era» no contaba como ordinal. Con el
		// motor anterior al filtro de cola, todas ofrecían su serie; con el
		// filtro quedaban en cero y sin «Quizá buscabas».
		await comprobarSeries( page, [
			[ 're+zero+novelas+ligeras', [ /^\/serie\/rezero-chapter-[a-z]+\/$/, /^\/serie\/rezero-chapter-[a-z]+\/$/ ] ],
			[ 'slam+dunk+nueva+edicion', [ '/serie/slam-dunk-deluxe/' ] ],
			[ 'demon+slayer+3era+temporada', [ '/serie/demon-slayer-kimetsu-no-yaiba/' ] ],
			[ 'kaguya+sama+sexto+tomo', [ '/serie/kaguya-sama-love-is-war/' ] ],
			[ 'attack+on+titan+disponibles', [ /^\/serie\/attack-on-titan-[a-z-]+\/$/, /^\/serie\/attack-on-titan-[a-z-]+\/$/ ] ],
			[ 'kaguya+sama+tomo+xii', [ '/serie/kaguya-sama-love-is-war/' ] ],
			[ 're+zero+en+ingles', [ /^\/serie\/rezero-chapter-[a-z]+\/$/, /^\/serie\/rezero-chapter-[a-z]+\/$/ ] ],
			// Y el femenino del idioma y el gentilicio del país (RN-REV3-1): la
			// pareja en masculino ofrecía la serie y ésta no.
			[ 'kaguya+sama+edicion+espanola', [ '/serie/kaguya-sama-love-is-war/' ] ],
			[ 'kaguya+sama+edici%C3%B3n+espa%C3%B1ola', [ '/serie/kaguya-sama-love-is-war/' ] ],
			[ 'slam+dunk+edicion+mexicana', [ '/serie/slam-dunk-deluxe/' ] ],
			[ 're+zero+version+inglesa', [ /^\/serie\/rezero-chapter-[a-z]+\/$/, /^\/serie\/rezero-chapter-[a-z]+\/$/ ] ],
		] );
	} );

	test( 'una serie de una palabra no se ofrece ante otra obra, ni el nombre a medias', async ( { page } ) => {
		// QA-SRCH-1 (2026-09-25), medido en el espejo con curl, Chromium y WebKit:
		// el nombre entero de una palabra encabezaba y la cola no se miraba, y la
		// última palabra del nombre bastaba como comienzo de la escrita.
		await comprobarSeries( page, [
			[ 'monster+high', [] ],
			[ 'kingdom+rush', [] ],
			[ 'akira+kurosawa', [] ],
			[ 'love+hinamatsuri', [] ],
			// Con cola de formato, la serie de una palabra sí.
			[ 'frieren+novela', [ '/serie/frieren/' ] ],
			[ 'monster+edicion+deluxe', [ '/serie/monster/' ] ],
		] );
	} );

	test( 'si el cero lo causa un filtro, el aviso habla del filtro y quitarlo trae los tomos', async ( { page } ) => {
		test.setTimeout( 120_000 );
		// QA-SRCH-4 (2026-09-25): «frieren» tiene tomos, pero con el filtro de
		// Cómics daba «Sin resultados para «frieren».» y ofrecía Frieren en
		// «Quizá buscabas», como si hubiera una errata. La salida es el chip.
		const errores = erroresDePagina( page );
		const respuesta = await page.goto( '/tienda/?_buscar=frieren&_categoria=comics', { waitUntil: 'domcontentloaded' } );
		// Un listado FILTRADO vacío responde 404 con la plantilla del catálogo y
		// su aviso (akibara-business, `FilteredUrlsPolicy`); la búsqueda sola, 200.
		expect( [ 200, 404 ] ).toContain( respuesta?.status() );
		const aviso = page.locator( '[data-akb-empty]' );
		test.skip( await aviso.isHidden(), 'el catálogo ya tiene «frieren» en Cómics: este caso no mide nada' );

		await expect( aviso.locator( '.akb-empty-title' ) ).toHaveText( 'Ningún producto coincide con estos filtros.' );
		await expect( aviso ).not.toContainText( 'Sin resultados para' );
		await expect( aviso.locator( '.akb-empty-suggest' ) ).toHaveCount( 0 );
		await expect( aviso.locator( '.akb-empty__cross-sell' ) ).toHaveCount( 0 );
		await expect( aviso.locator( '[data-akb-clear]' ) ).toBeVisible();

		// Quitar el chip del filtro deja la búsqueda y trae sus tomos, por PJAX.
		const chip = page.locator( '[data-akb-chips] .akb-chip[data-facet="categoria"]' );
		await expect( chip ).toBeVisible();
		await Promise.all( [
			page.waitForURL( ( url ) => ! url.search.includes( '_categoria' ) && url.search.includes( '_buscar=frieren' ) ),
			chip.click(),
		] );
		await expect( page.locator( 'ul.products li.product' ).first() ).toBeVisible();
		await expect( aviso ).toBeHidden();
		expect( errores ).toEqual( [] );
	} );

	test( 'si el cero lo causa un filtro, el botón del aviso quita los filtros y conserva la búsqueda, también con Atrás y Adelante', async ( { page, hasTouch } ) => {
		test.setTimeout( 120_000 );
		// F-S-1 (revisión del 2026-09-25): el aviso culpaba a los filtros, pero su
		// único botón era el «limpia todo» de la búsqueda sin resultados, que
		// también quitaba `_buscar`: el cliente aterrizaba en /tienda/ con todo
		// el catálogo y sin «frieren», que sí tiene tomos. El texto del botón lo
		// escribe el dueño (Buscador → Ajustes): se mide el comportamiento.
		const errores = erroresDePagina( page );
		const DESDE = '/tienda/?_buscar=frieren&_categoria=comics';
		await page.goto( DESDE, { waitUntil: 'domcontentloaded' } );
		const aviso = page.locator( '[data-akb-empty]' );
		test.skip( await aviso.isHidden(), 'el catálogo ya tiene «frieren» en Cómics: este caso no mide nada' );
		await expect( aviso.locator( '.akb-empty-title' ) ).toHaveText( 'Ningún producto coincide con estos filtros.' );

		const boton = aviso.locator( '[data-akb-clear]' );
		await expect( boton ).toHaveCount( 1 );
		await expect( boton ).toBeVisible();
		// En el móvil, con el dedo: el mismo botón tiene que responder al toque.
		await Promise.all( [
			page.waitForURL( ( url ) => url.pathname === '/tienda/' && ! url.search.includes( '_categoria' ) ),
			hasTouch ? boton.tap() : boton.click(),
		] );
		const trasElBoton = new URL( page.url() );
		expect( trasElBoton.searchParams.get( '_buscar' ), 'la búsqueda se perdía: /tienda/ con todo el catálogo' ).toBe( 'frieren' );
		await expect( page.locator( 'ul.products li.product' ).first() ).toBeVisible();
		await expect( aviso ).toBeHidden();
		await expect( page.locator( '[data-akb-chips] .akb-chip[data-facet="buscar"]' ) ).toBeVisible();
		await expect( page.locator( '[data-akb-chips] .akb-chip[data-facet="categoria"]' ) ).toHaveCount( 0 );

		// Atrás: vuelve el cero con su aviso y su botón, por PJAX.
		await page.goBack();
		await expect( page ).toHaveURL( ( url ) => url.search.includes( '_categoria=comics' ) && url.searchParams.get( '_buscar' ) === 'frieren' );
		await expect( aviso.locator( '.akb-empty-title' ) ).toHaveText( 'Ningún producto coincide con estos filtros.' );
		await expect( aviso.locator( '[data-akb-clear]' ) ).toBeVisible();
		await expect( page.locator( '[data-akb-chips] .akb-chip[data-facet="categoria"]' ) ).toBeVisible();

		// Adelante: la búsqueda sigue ahí, con sus tomos.
		await page.goForward();
		await expect( page ).toHaveURL( ( url ) => ! url.search.includes( '_categoria' ) && url.searchParams.get( '_buscar' ) === 'frieren' );
		await expect( aviso ).toBeHidden();
		await expect( page.locator( 'ul.products li.product' ).first() ).toBeVisible();
		await expect( page.locator( '[data-akb-chips] .akb-chip[data-facet="buscar"]' ) ).toBeVisible();

		expect( errores ).toEqual( [] );
	} );

	test( 'en un archivo, el aviso culpa al filtro sólo si el texto encuentra algo en ese archivo', async ( { page } ) => {
		test.setTimeout( 120_000 );
		// REV-FS1-1 (2026-09-25): en /comics/ «frieren» no encuentra nada —es
		// manga—, pero se contaba lo que encuentra en TODA la tienda y el aviso
		// decía «Ningún producto coincide con estos filtros.», sin «Quizá
		// buscabas»; quitar el chip llevaba a otro cero. El archivo no es un
		// chip: cuenta lo que el texto encuentra ahí. El buscador de la cabecera
		// siempre manda a /tienda/; a esto se llega con URLs viejas o armadas.
		const errores = erroresDePagina( page );
		const aviso = page.locator( '[data-akb-empty]' );

		const respuesta = await page.goto( '/comics/?_buscar=frieren&_disponibilidad=instock', { waitUntil: 'domcontentloaded' } );
		expect( [ 200, 404 ] ).toContain( respuesta?.status() );
		test.skip( await aviso.isHidden(), 'el catálogo ya tiene «frieren» en Cómics: este caso no mide nada' );
		await expect( aviso.locator( '.akb-empty-title' ) ).toHaveText( 'Sin resultados para «frieren».' );
		await expect( aviso ).not.toContainText( 'Ningún producto coincide con estos filtros.' );
		const series = await aviso.locator( 'a.akb-empty-suggest__item--series' ).evaluateAll(
			( enlaces ) => enlaces.map( ( a ) => new URL( a.href ).pathname )
		);
		expect( series ).toEqual( [ '/serie/frieren/' ] );

		// Donde el texto sí encuentra tomos, el cero es del filtro y quitarlo los trae.
		await page.goto( '/shonen/?_buscar=frieren&_editorial=panini-argentina', { waitUntil: 'domcontentloaded' } );
		test.skip( await aviso.isHidden(), 'Panini Argentina ya tiene «frieren» en Shonen: este caso no mide nada' );
		await expect( aviso.locator( '.akb-empty-title' ) ).toHaveText( 'Ningún producto coincide con estos filtros.' );
		await expect( aviso.locator( '.akb-empty-suggest' ) ).toHaveCount( 0 );
		const chip = page.locator( '[data-akb-chips] .akb-chip[data-facet="editorial"]' );
		await expect( chip ).toBeVisible();
		await Promise.all( [
			page.waitForURL( ( url ) => url.pathname.startsWith( '/shonen/' ) && ! url.search.includes( '_editorial' ) && url.search.includes( '_buscar=frieren' ) ),
			chip.click(),
		] );
		await expect( page.locator( 'ul.products li.product' ).first() ).toBeVisible();
		await expect( aviso ).toBeHidden();
		expect( errores ).toEqual( [] );
	} );

	test( 'en un archivo sin faceta dueña, el aviso habla de la búsqueda y no culpa al filtro', async ( { page } ) => {
		test.setTimeout( 120_000 );
		// REV-FS-1-1 (2026-09-25): «Sin categorizar» (/uncategorized/), la
		// categoría por defecto, no tiene faceta dueña, así que el buscador no
		// sabe qué encuentra el texto ahí. Contaba lo de toda la tienda y decía
		// «Ningún producto coincide con estos filtros.», sin «Quizá buscabas»;
		// quitar el chip llevaba a otro cero. Antes de QA-SRCH-4 decía «Sin
		// resultados para «frieren».» y ofrecía Frieren, que era lo correcto.
		const errores = erroresDePagina( page );
		const aviso = page.locator( '[data-akb-empty]' );

		// Sin el filtro: si el archivo ya tiene tomos de Frieren, o no existe, no hay nada que medir.
		await page.goto( '/uncategorized/?_buscar=frieren', { waitUntil: 'domcontentloaded' } );
		test.skip( await aviso.isHidden(), '«Sin categorizar» ya tiene «frieren», o no existe: este caso no mide nada' );

		const respuesta = await page.goto( '/uncategorized/?_buscar=frieren&_disponibilidad=instock', { waitUntil: 'domcontentloaded' } );
		expect( [ 200, 404 ] ).toContain( respuesta?.status() );
		await expect( aviso.locator( '.akb-empty-title' ) ).toHaveText( 'Sin resultados para «frieren».' );
		await expect( aviso ).not.toContainText( 'Ningún producto coincide con estos filtros.' );
		const series = await aviso.locator( 'a.akb-empty-suggest__item--series' ).evaluateAll(
			( enlaces ) => enlaces.map( ( a ) => new URL( a.href ).pathname )
		);
		expect( series ).toEqual( [ '/serie/frieren/' ] );

		// Y es verdad: quitar el chip deja el mismo cero, no otro distinto.
		const chip = page.locator( '[data-akb-chips] .akb-chip[data-facet="disponibilidad"]' );
		await expect( chip ).toBeVisible();
		await Promise.all( [
			page.waitForURL( ( url ) => url.pathname.startsWith( '/uncategorized/' ) && ! url.search.includes( '_disponibilidad' ) && url.search.includes( '_buscar=frieren' ) ),
			chip.click(),
		] );
		// El PJAX cambia la URL después del intercambio: esto ya es la página nueva.
		await expect( chip ).toHaveCount( 0 );
		await expect( aviso.locator( '.akb-empty-title' ) ).toHaveText( 'Sin resultados para «frieren».' );
		expect( errores ).toEqual( [] );
	} );

	test( 'no ofrece otra obra que comparte el principio si lo que sigue nombra otra', async ( { page } ) => {
		// Revisión B4-3-R1 (2026-09-23), medido en Chromium y WebKit: con dos
		// palabras que cuentan bastaba para ofrecer cualquier serie que empezara
		// igual, aunque la palabra siguiente nombrara OTRA obra. Ahora lo que
		// sobra sólo puede ser números, palabras de formato (Buscador → Ajustes)
		// o palabras ignoradas.
		await comprobarSeries( page, [
			[ 'biblioteca+vertigo+sandman', [] ],
			[ 'biblioteca+vertigo+preacher', [] ],
			[ 'el+chico+que+dibujaba+constelaciones', [] ],
			[ 'el+verano+que+nos+conocimos', [] ],
			[ 'kaiju+no+hanayome', [] ],
			[ 'hajime+no+kanojo', [] ],
			// Una palabra ignorada no rescata la cola que nombra otra obra (RN1-D1).
			[ 'kaiju+no+de+hanayome', [] ],
			// Un spin-off tampoco trae la serie madre: el coste asumido.
			[ 'demon+slayer+kimetsu+gakuen', [] ],
			// Ni una errata en la cola, que no se distingue de otra obra (RN-REV1-1).
			[ 'kaguya+sama+novla', [] ],
			// Ni el plural de una palabra de formato detrás de otra obra (RN-REV2-1).
			[ 'demon+slayer+kimetsu+gakuen+mangas', [] ],
			// Con cola de formato, la misma colección sí.
			[ 'biblioteca+vertigo+tomo+3', [ '/serie/biblioteca-vertigo-john-constantine-hellblazer/' ] ],
		] );
	} );

	test( 'con resultados no hay aviso ni sugerencias', async ( { page } ) => {
		await page.goto( '/tienda/?_buscar=one+piece', { waitUntil: 'domcontentloaded' } );

		await expect( page.locator( 'ul.products li.product' ).first() ).toBeVisible();
		await expect( page.locator( '[data-akb-empty]' ) ).toBeHidden();
		await expect( page.locator( '.akb-empty-suggest' ) ).toHaveCount( 0 );
	} );

	test( 'el PJAX de cero a N y de vuelta no confunde la venta cruzada con la rejilla', async ( { page } ) => {
		const errores = erroresDePagina( page );

		// La venta cruzada la pinta el tema en el aviso (`akibara_search_empty_after`,
		// lote de integración). Contra un tema anterior —producción antes de
		// desplegarlo— no hay intercambio que probar.
		await page.goto( BUSQUEDA_SIN_NADA, { waitUntil: 'domcontentloaded' } );
		test.skip( ( await foto( page ) ).ventaCruzadaEnAviso === 0, 'el tema servido no pinta la venta cruzada dentro del aviso' );

		expect( await foto( page ) ).toMatchObject( { rejillas: [], avisoVisible: true, ventaCruzadaFuera: 0 } );
		// UNA venta cruzada: la del servidor, sin copias.
		expect( await page.locator( '[data-akb-empty] .akb-empty__cross-sell' ).count() ).toBe( 1 );

		// 0 → N: «limpia todo» quita la búsqueda por PJAX.
		await Promise.all( [
			page.waitForURL( ( url ) => ! url.search.includes( '_buscar' ) ),
			page.locator( '[data-akb-empty] [data-akb-clear]' ).click(),
		] );
		await expect.poll( async () => ( await foto( page ) ).rejillas.length, { message: 'la rejilla del catálogo tiene que aparecer' } ).toBe( 1 );
		const conResultados = await foto( page );
		expect( conResultados.rejillas[ 0 ], 'la tienda no puede quedarse sin productos teniendo resultados' ).toBeGreaterThan( 0 );
		expect( conResultados ).toMatchObject( { avisoVisible: false, ventaCruzadaEnAviso: 0, ventaCruzadaFuera: 0, barraAntesDeRejilla: true } );

		// N → 0: Atrás vuelve a la búsqueda sin resultados, también por PJAX.
		await page.goBack();
		await expect.poll( async () => ( await foto( page ) ).avisoVisible ).toBe( true );
		const sinResultados = await foto( page );
		expect( sinResultados ).toMatchObject( { rejillas: [], ventaCruzadaFuera: 0 } );
		expect( sinResultados.ventaCruzadaEnAviso, 'la venta cruzada vuelve entera, dentro del aviso' ).toBeGreaterThan( 0 );
		expect( await page.locator( '[data-akb-empty] .akb-empty__cross-sell' ).count(), 'y una sola vez' ).toBe( 1 );
		await expect( page.locator( '[data-akb-empty] .akb-empty-suggest' ) ).toBeVisible();

		expect( errores, 'sin errores de JavaScript en el intercambio' ).toEqual( [] );
	} );
} );
