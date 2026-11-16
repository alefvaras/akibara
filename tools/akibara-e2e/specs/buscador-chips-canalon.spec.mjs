// @ts-check
/**
 * La fila de chips de filtros respeta el canalón del listado.
 *
 * QA integral, 2026-09-23 (QA-RV-6): de 360 a 768 px, en Chromium y WebKit, la
 * fila «Búsqueda: «gundam» × · Limpiar todo» arrancaba en x = 0, con el
 * redondeo del primer chip contra el borde de la pantalla, mientras el título,
 * «N productos» y los botones Filtrar/Ordenar respetaban sus 16 px. Desde
 * akibara-search 0.1.75 la fila lleva el mismo canalón que la barra de orden.
 * Las portadas siguen a sangre a propósito: eso es la retícula, no una fila de
 * controles.
 *
 * Se mide contra «N productos» (`.akb-result-count`), no contra un número fijo:
 * si el tema cambia su canalón, los chips tienen que seguirlo.
 *
 * Todo es de lectura: vale contra el espejo y contra producción.
 */

import { expect, test } from '@playwright/test';
import { erroresDePagina } from './soporte/tienda.mjs';

/** Varios filtros a la vez: los chips no caben en una línea a 390 px. */
const CON_VARIOS_FILTROS = '/tienda/?_buscar=one+piece&_editorial=ivrea-argentina&_pais=argentina&_genero=accion,aventura';

/**
 * Geometría de la fila de chips frente al canalón del listado.
 *
 * @param {import('@playwright/test').Page} page Página.
 */
function medir( page ) {
	return page.evaluate( () => {
		const fila = document.querySelector( '.akb-facet-chips' );
		const total = document.querySelector( '.akb-result-count' );
		const chips = fila && ! fila.hidden ? Array.from( fila.querySelectorAll( '.akb-chip' ) ) : [];
		const rejilla = Array.from( document.querySelectorAll( 'ul.products' ) ).find( ( u ) => ! u.closest( '[data-akb-empty]' ) );
		return {
			ancho: document.documentElement.clientWidth,
			desborde: document.documentElement.scrollWidth - document.documentElement.clientWidth,
			canalon: total ? Math.round( total.getBoundingClientRect().left ) : null,
			rejilla: rejilla ? Math.round( rejilla.getBoundingClientRect().left ) : null,
			chips: chips.map( ( c ) => {
				const r = c.getBoundingClientRect();
				return { izq: Math.round( r.left ), der: Math.round( r.right ), arriba: Math.round( r.top ) };
			} ),
		};
	} );
}

/**
 * En móvil cada chip queda dentro del canalón por los dos lados.
 *
 * @param {Awaited<ReturnType<typeof medir>>} m Medición.
 * @param {string} donde Contexto del mensaje.
 */
function dentroDelCanalon( m, donde ) {
	expect( m.chips.length, `${ donde }: hay chips que medir` ).toBeGreaterThan( 0 );
	expect( m.canalon, `${ donde }: el canalón de «N productos»` ).toBeGreaterThan( 0 );
	// Cada línea de chips (también la segunda, si la fila se parte) arranca
	// exactamente donde «N productos», no en x = 0.
	for ( const arriba of new Set( m.chips.map( ( c ) => c.arriba ) ) ) {
		const inicio = Math.min( ...m.chips.filter( ( c ) => c.arriba === arriba ).map( ( c ) => c.izq ) );
		expect( inicio, `${ donde }: la línea de chips a y=${ arriba } arranca donde «N productos»` ).toBe( m.canalon );
	}
	for ( const chip of m.chips ) {
		expect( chip.izq, `${ donde }: ningún chip toca el borde izquierdo` ).toBeGreaterThanOrEqual( m.canalon );
		expect( chip.der, `${ donde }: ningún chip toca el borde derecho` ).toBeLessThanOrEqual( m.ancho - m.canalon );
	}
	expect( m.desborde, `${ donde }: sin scroll horizontal` ).toBe( 0 );
}

test.describe( 'La fila de chips respeta el canalón del listado', () => {
	test( 'en móvil arranca donde «N productos», con y sin resultados, en una línea o en varias', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'el canalón móvil sólo existe a ≤ 768 px; el escritorio se mide aparte' );

		for ( const ruta of [ '/tienda/?_buscar=gundam', '/tienda/?_buscar=one+piece', CON_VARIOS_FILTROS ] ) {
			const respuesta = await page.goto( ruta, { waitUntil: 'domcontentloaded' } );
			expect( respuesta?.status(), ruta ).toBe( 200 );
			const m = await medir( page );
			dentroDelCanalon( m, ruta );
			if ( CON_VARIOS_FILTROS === ruta ) {
				const lineas = new Set( m.chips.map( ( c ) => c.arriba ) );
				expect( lineas.size, 'con cinco filtros los chips ocupan más de una línea: el canalón vale para todas' ).toBeGreaterThan( 1 );
			}
		}
	} );

	test( 'en móvil el canalón sobrevive al PJAX: los chips que vuelven por Atrás salen alineados', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'el canalón móvil sólo existe a ≤ 768 px' );
		const errores = erroresDePagina( page );

		await page.goto( '/tienda/?_buscar=gundam', { waitUntil: 'domcontentloaded' } );
		dentroDelCanalon( await medir( page ), 'carga directa' );

		// «Limpiar todo» por PJAX: la fila se vacía y se oculta.
		await Promise.all( [
			page.waitForURL( ( url ) => ! url.search.includes( '_buscar' ) ),
			page.locator( '.akb-facet-chips [data-akb-clear]' ).click(),
		] );
		await expect( page.locator( '.akb-facet-chips' ) ).toBeHidden();

		// Atrás: el PJAX reemplaza la fila con la que trae la respuesta.
		await page.goBack();
		await expect( page.locator( '.akb-facet-chips .akb-chip' ).first() ).toBeVisible();
		dentroDelCanalon( await medir( page ), 'tras el PJAX' );

		expect( errores, 'sin errores de JavaScript' ).toEqual( [] );
	} );

	test( 'en escritorio la fila sigue alineada con la rejilla, sin canalón añadido', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'a ≤ 768 px se mide arriba' );

		await page.goto( '/tienda/?_buscar=one+piece', { waitUntil: 'domcontentloaded' } );
		const m = await medir( page );
		expect( m.chips.length ).toBeGreaterThan( 0 );
		expect( m.rejilla, 'hay rejilla del catálogo' ).not.toBeNull();
		expect( m.chips[ 0 ].izq, 'el primer chip arranca donde la primera portada' ).toBe( m.rejilla );
	} );
} );
