// @ts-check
/**
 * Ficha y recomendaciones (lote B2, 2026-09-22; auditoría del tema UC-2, UC-6,
 * UC-7 y UC-13). Lo que fija, medido antes del cambio:
 *
 *  - UC-2: un tomo EN STOCK no decía su plazo junto al botón; «Despacha en
 *    24–48 h» sólo estaba en la pestaña Descripción, 1.700 px más abajo.
 *  - UC-6: «Productos relacionados» repetía serie (Black Clover 03, 02 y 01 en
 *    la misma fila) y casi nunca ofrecía un tomo 01.
 *  - UC-7: «También te recomendamos…» salía barajado («27, 26, 25») y con lo
 *    agotado delante de lo comprable.
 *  - UC-13: los «Más vendidos» del carrito vacío eran 2-3 de 4 de la misma
 *    serie.
 *  - Remanentes (2026-09-23): los relacionados cambiaban en cada render (RAND()
 *    y el `orderby` 'rand' de WooCommerce), y «otra serie» podía ser otra
 *    edición de la misma obra.
 *
 * Todo es de LECTURA, así que vale también contra producción, en la suite entera
 * (`npm run test:prod:completa`, sólo con permiso del dueño): no lleva la
 * etiqueta `@prod` de la prueba de humo. Los interruptores de la pestaña
 * «Recomendaciones» se prueban aparte, en el espejo, porque cambiarlos es una
 * option global.
 */

import { expect, test } from '@playwright/test';
import { productoConBadge } from './soporte/tienda.mjs';

/** Serie a partir del título de una tarjeta: sin sufijo entre paréntesis ni número final. */
function serie( titulo ) {
	return titulo
		.replace( /\s+/g, ' ' )
		.trim()
		.replace( /\s*[([][^()[\]]*[)\]]\s*$/u, '' )
		.replace( /\s*(?:n[º°]|#|vol\.?)?\s*0*\d{1,4}\s*$/iu, '' )
		.trim()
		.toLocaleLowerCase( 'es' );
}

/** Número de tomo del título, o null. */
function numero( titulo ) {
	const m = titulo.replace( /\s*[([][^()[\]]*[)\]]\s*$/u, '' ).match( /(\d{1,4})\s*$/u );
	return m ? Number( m[ 1 ] ) : null;
}

/** Tarjetas de un bloque de la ficha: título (textContent: sin mayúsculas del CSS) y si está agotada. */
function tarjetas( page, bloque ) {
	return page.locator( `${ bloque } li.product` ).evaluateAll( ( lis ) => lis.map( ( li ) => ( {
		titulo: ( li.querySelector( '.woocommerce-loop-product__title' )?.textContent || '' ).trim(),
		agotado: li.classList.contains( 'outofstock' ),
	} ) ) );
}

/**
 * Fichas disponibles (de /disponibles/ y /tienda/) cuyo bloque tenga al menos
 * `minimo` tarjetas, hasta `cuantas`. Recorre hasta 25 fichas: la primera del
 * listado puede no tener upsells, y saltarse la prueba por eso es perder la
 * prueba.
 *
 * @returns {Promise<string[]>}
 */
async function fichasDisponiblesCon( page, bloque, minimo, cuantas = 1 ) {
	const urls = [];
	for ( const ruta of [ '/disponibles/', '/tienda/' ] ) {
		const r = await page.goto( ruta, { waitUntil: 'domcontentloaded' } ).catch( () => null );
		if ( ! r || r.status() >= 400 ) {
			continue;
		}
		urls.push( ...await page.locator( 'li.product.instock a.woocommerce-LoopProduct-link' ).evaluateAll(
			( els ) => els.map( ( e ) => e.getAttribute( 'href' ) || '' ).filter( Boolean )
		) );
	}
	const halladas = [];
	for ( const url of [ ...new Set( urls ) ].slice( 0, 25 ) ) {
		const r = await page.goto( url, { waitUntil: 'load' } ).catch( () => null );
		if ( r && r.status() < 400 && await page.locator( `${ bloque } li.product` ).count() >= minimo ) {
			halladas.push( url );
			if ( halladas.length >= cuantas ) {
				break;
			}
		}
	}
	return halladas;
}

/**
 * Fichas disponibles con al menos tres upsells, de series distintas.
 *
 * Se buscan en las landings de serie (abren en «Tomo 1 → N») y no en
 * /disponibles/: ahí van primero las novedades, que son el último tomo y casi
 * nunca tienen siguientes — medido en producción el 2026-09-22, 2 de 25.
 *
 * @returns {Promise<string[]>}
 */
async function fichasConUpsells( page, cuantas ) {
	await page.goto( '/series/', { waitUntil: 'domcontentloaded' } );
	const series = [ ...new Set( await page.locator( 'a[href*="/serie/"]' ).evaluateAll(
		( els ) => els.map( ( e ) => e.getAttribute( 'href' ) || '' ).filter( Boolean )
	) ) ];
	const halladas = [];
	// Repartidas por el directorio, no las primeras del alfabeto.
	const paso = Math.max( 1, Math.floor( series.length / 12 ) );
	for ( let i = 0; i < series.length && halladas.length < cuantas; i += paso ) {
		const r = await page.goto( series[ i ], { waitUntil: 'domcontentloaded' } ).catch( () => null );
		if ( ! r || r.status() >= 400 ) {
			continue;
		}
		const tomos = await page.locator( 'li.product.instock a.woocommerce-LoopProduct-link' ).evaluateAll(
			( els ) => els.map( ( e ) => e.getAttribute( 'href' ) || '' ).filter( Boolean )
		);
		for ( const url of tomos.slice( 0, 3 ) ) {
			const f = await page.goto( url, { waitUntil: 'load' } ).catch( () => null );
			if ( f && f.status() < 400 && await page.locator( '.up-sells li.product' ).count() >= 3 ) {
				halladas.push( url );
				break;
			}
		}
	}
	return halladas;
}

test.describe( 'Ficha de un tomo disponible', () => {
	test( 'dice su plazo de despacho justo bajo el botón, antes de los sellos', async ( { page }, info ) => {
		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'No hay tomos disponibles en el catálogo.' );

		const respuesta = await page.goto( url, { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );

		const linea = page.locator( '.summary .akb-despacho' );
		await expect( linea ).toHaveCount( 1 );
		await expect( linea ).toBeVisible();
		await expect( linea ).toHaveText( /\S/ );

		const orden = await page.evaluate( () => {
			const form = document.querySelector( '.summary form.cart' );
			const lineaEl = document.querySelector( '.summary .akb-despacho' );
			const sellos = document.querySelector( '.summary .akb-product-trust-badges' );
			const antesDeSellos = ! sellos || !! ( lineaEl.compareDocumentPosition( sellos ) & Node.DOCUMENT_POSITION_FOLLOWING );
			return {
				trasElFormulario: !! form && !! ( form.compareDocumentPosition( lineaEl ) & Node.DOCUMENT_POSITION_FOLLOWING ),
				antesDeSellos,
				botonAbajo: form.getBoundingClientRect().bottom,
				lineaArriba: lineaEl.getBoundingClientRect().top,
				lineaAbajo: lineaEl.getBoundingClientRect().bottom,
			};
		} );
		expect( orden.trasElFormulario, 'Después del formulario: la promesa no baja el botón.' ).toBe( true );
		expect( orden.antesDeSellos, 'Pegada al botón, no bajo los sellos de confianza.' ).toBe( true );
		expect( orden.lineaArriba - orden.botonAbajo ).toBeLessThan( 48 );

		if ( 'escritorio' === info.project.name ) {
			expect( orden.lineaAbajo, 'En escritorio la promesa se lee sin hacer scroll.' ).toBeLessThan( 900 );
		}
	} );

	test( '«Productos relacionados»: una serie por hueco y ninguna la de la ficha', async ( { page } ) => {
		const [ url ] = await fichasDisponiblesCon( page, '.related', 2 );
		test.skip( ! url, 'Ninguna de 25 fichas disponibles tiene relacionados.' );

		const relacionados = await tarjetas( page, '.related' );

		const series = relacionados.map( ( t ) => serie( t.titulo ) );
		expect( new Set( series ).size, `Series repetidas: ${ series.join( ' · ' ) }` ).toBe( series.length );

		const titulo = ( await page.locator( '.product_title' ).first().textContent() ) || '';
		expect( series ).not.toContain( serie( titulo ) );

		const unos = relacionados.filter( ( t ) => 1 === numero( t.titulo ) ).length;
		expect( unos, 'La mayoría de los huecos son tomos de entrada.' ).toBeGreaterThanOrEqual( Math.ceil( relacionados.length / 2 ) );
	} );

	test( '«Productos relacionados»: la misma ficha enseña los mismos, en el mismo orden, en cada render', async ( { page } ) => {
		// Sugerencia SEO aceptada (2026-09-23): con ORDER BY RAND() en el pool y
		// el `orderby` 'rand' de WooCommerce al pintar, cada render —cada copia
		// nueva de LiteSpeed o del borde— enlazaba otras fichas u otro orden. Tres
		// URL distintas son tres renders distintos de la misma ficha.
		const [ url ] = await fichasDisponiblesCon( page, '.related', 2 );
		test.skip( ! url, 'Ninguna de 25 fichas disponibles tiene relacionados.' );

		const vistas = [];
		for ( const n of [ 1, 2, 3 ] ) {
			const r = await page.goto( `${ url }${ url.includes( '?' ) ? '&' : '?' }akbqa-render=${ Date.now() }-${ n }`, { waitUntil: 'domcontentloaded' } );
			expect( r?.status() ).toBe( 200 );
			vistas.push( ( await page.locator( '.related li.product a.woocommerce-LoopProduct-link' ).evaluateAll(
				( els ) => els.map( ( e ) => new URL( e.getAttribute( 'href' ) || '', location.href ).pathname )
			) ).join( ' ' ) );
		}
		expect( new Set( vistas ).size, `Tres renders: ${ vistas.join( ' | ' ) }` ).toBe( 1 );
	} );

	test( '«Productos relacionados»: otra edición de la misma obra tampoco cuenta como otra serie', async ( { page } ) => {
		// Revisión B2-2-R2: el dueño declaró estas parejas como la misma obra
		// (campo «Ediciones relacionadas» de la serie). Antes, One Piece nº 01 (3
		// en 1) ofrecía «One Piece 001» y Berserk Master Edition 01, «Maximum
		// Berserk 01».
		for ( const [ ficha, hermana ] of [
			[ '/one-piece-no-1-3-en-1-planeta-espana/', /^one piece(?! n[º°])/iu ],
			[ '/berserk-master-edition-01-panini-espana/', /^maximum berserk/iu ],
		] ) {
			const r = await page.goto( ficha, { waitUntil: 'domcontentloaded' } );
			if ( ! r || 200 !== r.status() ) {
				test.info().annotations.push( { type: 'omitido', description: `${ ficha } no existe` } );
				continue;
			}
			const titulos = ( await tarjetas( page, '.related' ) ).map( ( t ) => t.titulo );
			expect( titulos.filter( ( t ) => hermana.test( t ) ), `${ ficha }: ${ titulos.join( ' · ' ) }` ).toEqual( [] );
		}
	} );

	test( '«También te recomendamos…»: comprables primero y cada grupo en orden de lectura', async ( { page } ) => {
		// Cinco fichas y no una: WooCommerce baraja, y una sola lista de tres
		// sale ordenada por azar una vez de cada seis.
		const urls = await fichasConUpsells( page, 5 );
		test.skip( 0 === urls.length, 'Ninguna serie mirada tiene una ficha disponible con tres upsells.' );

		for ( const url of urls ) {
			await page.goto( url, { waitUntil: 'load' } );
			const upsells = await tarjetas( page, '.up-sells' );
			const lista = upsells.map( ( t ) => t.titulo + ( t.agotado ? ' [agotado]' : '' ) ).join( ' · ' );

			const primerAgotado = upsells.findIndex( ( t ) => t.agotado );
			if ( -1 !== primerAgotado ) {
				expect( upsells.slice( primerAgotado ).every( ( t ) => t.agotado ), `Comprable detrás de un agotado en ${ url }: ${ lista }` ).toBe( true );
			}
			for ( const grupo of [ upsells.filter( ( t ) => ! t.agotado ), upsells.filter( ( t ) => t.agotado ) ] ) {
				const numeros = grupo.map( ( t ) => numero( t.titulo ) ).filter( ( n ) => null !== n );
				expect( numeros, `Fuera de orden en ${ url }: ${ lista }` ).toEqual( [ ...numeros ].sort( ( a, b ) => a - b ) );
			}
		}
	} );
} );

test( 'Ficha «a pedido»: sin la línea de despacho (tiene su fecha de llegada)', async ( { page } ) => {
	const url = await productoConBadge( page, 'A pedido' );
	test.skip( ! url, 'No hay tomos a pedido en el catálogo.' );
	await page.goto( url, { waitUntil: 'load' } );

	await expect( page.locator( '.akb-despacho' ) ).toHaveCount( 0 );
} );

test( 'Carrito vacío: los «Más vendidos» son de series distintas', async ( { browser } ) => {
	// Contexto propio, sin la sesión del visitante que vuelve: carrito vacío seguro.
	const contexto = await browser.newContext();
	const page = await contexto.newPage();
	try {
		const respuesta = await page.goto( '/carrito/', { waitUntil: 'load' } );
		expect( respuesta?.status() ).toBe( 200 );

		const titulos = await page.locator( '.akb-empty__cross-sell-grid li.product .woocommerce-loop-product__title' ).allTextContents();
		test.skip( titulos.length < 2, 'Sin venta cruzada en el carrito vacío.' );

		const series = titulos.map( serie );
		expect( new Set( series ).size, `Series repetidas: ${ series.join( ' · ' ) }` ).toBe( series.length );
	} finally {
		await contexto.close();
	}
} );
