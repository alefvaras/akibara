// @ts-check
/**
 * Filtros del catálogo y buscador: correcciones de la QA integral del
 * 2026-09-24 (lote QF-search «correcciones-qa», akibara-search).
 *
 *   - QA-ESC-TECLADO-3: marcar un filtro con Espacio deja el foco en la casilla
 *     (antes saltaba a la rejilla, y el siguiente Tab recorría las tarjetas).
 *   - QA-ESC-TECLADO-8: con movimiento reducido la página salta al total; con
 *     movimiento permitido se desliza y LLEGA (el refresh de ScrollTrigger del
 *     tema la anulaba).
 *   - QA-RVC-2: las listas de facetas no desbordan en horizontal (6 px de más
 *     pintaban una barra de 14 px en WebKit con ratón y en Windows). Se miden
 *     TODAS, también Autor y Serie, que cargan plegadas: la primera versión de
 *     esta prueba sólo veía las abiertas y dejó pasar 2 px en las alfabéticas
 *     (REV-QFS1-2).
 *   - REV-QFS1-1/2: Autor y Serie dejan 4 px entre la etiqueta y el borde útil
 *     de su lista (la barra, si la hay), en escritorio y en el cajón móvil.
 *   - REV2-QFS-1: en el cajón, la casilla queda a 4 px o más del borde de
 *     recorte de su lista, y el anillo de foco y el glow caben enteros.
 *   - QAM-7: el botón del cajón dice «Ver 1 producto», no «Ver 1 productos».
 *   - QA-ESC-TECLADO-12: el combobox apunta a un listbox que existe, sin el
 *     `aria-haspopup` que dejaba a axe sin comprobarlo.
 *
 * Todo es de lectura (filtros por GET): vale contra el espejo y producción.
 */

import { expect, test } from '@playwright/test';

/** Espera a que termine el PJAX del catálogo. */
async function trasElPjax( page, parametro ) {
	await page.waitForFunction( ( p ) => location.search.includes( p ) && ! document.body.classList.contains( 'akb-pjax-loading' ), parametro );
	await page.waitForTimeout( 400 );
}

/**
 * Despliega todas las facetas plegadas, en la barra lateral o en el cajón móvil.
 * Autor y Serie cargan plegadas, y una lista que no se ve no se puede medir.
 */
async function abrirFacetas( page, isMobile ) {
	if ( isMobile ) {
		await page.locator( '[data-akb-drawer-open]' ).first().click();
	}
	const plegadas = page.locator( '.akb-facets .akb-facet.is-collapsed .akb-facet-title' );
	for ( let n = await plegadas.count(); n > 0; n-- ) {
		await plegadas.first().click();
	}
	await expect( plegadas ).toHaveCount( 0 );
}

/** Registra scrollY en cada cuadro hasta que se le pida el resultado. */
function registrarScroll( page ) {
	return page.evaluate( () => {
		window.__akbYs = [];
		const tick = () => {
			const y = Math.round( scrollY );
			if ( window.__akbYs[ window.__akbYs.length - 1 ] !== y ) {
				window.__akbYs.push( y );
			}
			window.__akbRaf = requestAnimationFrame( tick );
		};
		tick();
	} );
}

/** Marca con el ratón la última casilla visible de la barra lateral, bajando antes. */
async function marcarDesdeAbajo( page ) {
	await page.goto( '/tienda/' );
	// Sólo las de facetas abiertas: las plegadas no se ven.
	const etiquetas = page.locator( '.akb-facets .akb-facet-list label:visible' );
	const ultima = etiquetas.nth( ( await etiquetas.count() ) - 1 );
	await ultima.scrollIntoViewIfNeeded();
	await page.evaluate( () => window.scrollBy( 0, 250 ) );
	await page.waitForTimeout( 200 );
	const caja = await ultima.boundingBox();
	test.skip( ! caja || caja.y < 0, 'la última casilla no queda a la vista en este entorno' );
	await registrarScroll( page );
	const inicio = await page.evaluate( () => Math.round( scrollY ) );
	await page.mouse.click( caja.x + 12, caja.y + caja.height / 2 );
	await trasElPjax( page, '_' );
	await page.waitForTimeout( 1200 );
	const ys = await page.evaluate( () => {
		cancelAnimationFrame( window.__akbRaf );
		return window.__akbYs;
	} );
	// Adonde lleva `scrollIntoView({ block: 'start' })`: el borde del total menos
	// su scroll-margin y el scroll-padding del documento (la cabecera fija).
	const destino = await page.evaluate( () => {
		const total = document.querySelector( '.akb-result-count' );
		const px = ( v ) => parseFloat( v ) || 0;
		const margen = px( getComputedStyle( total ).scrollMarginTop ) + px( getComputedStyle( document.documentElement ).scrollPaddingTop );
		return Math.max( 0, Math.round( total.getBoundingClientRect().top + scrollY - margen ) );
	} );
	// Con tolerancia: al llegar, la rejilla todavía puede empujar unos píxeles
	// (medido: 0 → 5 px tras la revelación de tarjetas del tema).
	const cerca = ( a, b ) => Math.abs( a - b ) <= 12;
	return {
		inicio,
		destino,
		final: ys[ ys.length - 1 ],
		llega: cerca( ys[ ys.length - 1 ], destino ),
		intermedios: ys.filter( ( y ) => ! cerca( y, inicio ) && ! cerca( y, destino ) ).length,
		ys,
	};
}

test.describe( 'buscador · correcciones QA 2026-09-24', () => {
	// Dos cargas del catálogo y un PJAX por prueba: con el espejo cargado por
	// otras corridas pasaban de los 60 s por defecto (medido el 2026-09-24).
	test.describe.configure( { timeout: 120_000 } );

	test( 'Espacio en un filtro deja el foco en esa casilla y el Tab sigue en los filtros', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'en móvil los filtros van en el cajón, por lotes' );
		await page.goto( '/tienda/' );
		const casilla = page.locator( '.akb-facets .akb-facet-list input[type="checkbox"]:not([disabled])' ).first();
		const nombre = await casilla.getAttribute( 'name' );
		const valor = await casilla.getAttribute( 'value' );
		await casilla.focus();
		await page.keyboard.press( 'Space' );
		await trasElPjax( page, String( nombre ).replace( /\[\]$/, '' ) );

		const foco = await page.evaluate( () => ( {
			name: document.activeElement.getAttribute( 'name' ),
			value: document.activeElement.getAttribute( 'value' ),
			checked: !! document.activeElement.checked,
		} ) );
		expect( foco ).toEqual( { name: nombre, value: valor, checked: true } );

		await page.keyboard.press( 'Tab' );
		const siguiente = await page.evaluate( () => ( {
			enTarjeta: !! document.activeElement.closest( 'li.product' ),
			enFiltros: !! document.activeElement.closest( '.akb-facets' ),
		} ) );
		expect( siguiente ).toEqual( { enTarjeta: false, enFiltros: true } );
	} );

	test( 'con movimiento reducido la página salta al total, sin pasos intermedios', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'la barra lateral es de escritorio' );
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		const m = await marcarDesdeAbajo( page );
		test.skip( m.inicio - m.destino < 100, 'el total ya estaba casi a la vista: nada que desplazar' );
		expect( m.llega, JSON.stringify( m ) ).toBe( true );
		expect( m.intermedios, `un salto, no una animación: ${ JSON.stringify( m.ys ) }` ).toBe( 0 );
	} );

	test( 'con movimiento permitido la página se desliza y LLEGA al total', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'la barra lateral es de escritorio' );
		await page.emulateMedia( { reducedMotion: 'no-preference' } );
		const m = await marcarDesdeAbajo( page );
		test.skip( m.inicio - m.destino < 100, 'el total ya estaba casi a la vista: nada que desplazar' );
		// Lo que se prueba es que LLEGA. Cuántos cuadros intermedios se ven
		// depende de la carga de la máquina, así que no se exige un número.
		expect( m.llega, `el refresh de ScrollTrigger no puede anular la subida: ${ JSON.stringify( m ) }` ).toBe( true );
	} );

	test( 'las listas de facetas no desbordan en horizontal', async ( { page, isMobile } ) => {
		await page.goto( '/tienda/?_disponibilidad=instock' );
		await abrirFacetas( page, isMobile );
		const listas = await page.evaluate( () => Array.from( document.querySelectorAll( '.akb-facets .akb-facet-list' ) )
			.filter( ( l ) => l.getClientRects().length )
			.map( ( l ) => {
				l.scrollLeft = 20;
				const r = { faceta: l.closest( '.akb-facet' )?.getAttribute( 'data-facet' ), sobra: l.scrollWidth - l.clientWidth, scrollLeft: l.scrollLeft };
				l.scrollLeft = 0;
				return r;
			} ) );
		expect( listas.length ).toBeGreaterThan( 0 );
		for ( const l of listas ) {
			expect( l, JSON.stringify( l ) ).toMatchObject( { sobra: 0, scrollLeft: 0 } );
		}
	} );

	test( 'Autor y Serie dejan 4 px entre la etiqueta y el borde útil de su lista', async ( { page, isMobile } ) => {
		await page.goto( '/tienda/?_disponibilidad=instock' );
		await abrirFacetas( page, isMobile );
		// El borde útil es donde acaba el área visible de la lista: la barra de
		// desplazamiento si el sistema la pinta, o el borde de la caja. Entre él
		// y la etiqueta (su caja de hover) quedan los 4 px de respiro del
		// contador. Medido el 2026-09-24: escritorio −2 (la etiqueta salía y el
		// hover se cortaba), cajón móvil 0 (el contador pegado a la barra).
		const alfabeticas = await page.evaluate( () => Array.from( document.querySelectorAll( '.akb-facets .akb-facet-list--scroll' ) )
			.filter( ( l ) => l.getClientRects().length )
			.map( ( l ) => {
				const borde = l.getBoundingClientRect().left + l.clientLeft + l.clientWidth;
				const etiquetas = Array.from( l.querySelectorAll( '.akb-facet-item label' ) ).filter( ( e ) => e.getClientRects().length );
				return {
					faceta: l.closest( '.akb-facet' )?.getAttribute( 'data-facet' ),
					etiquetas: etiquetas.length,
					respiro: Math.round( Math.min( ...etiquetas.map( ( e ) => borde - e.getBoundingClientRect().right ) ) ),
				};
			} ) );
		expect( alfabeticas.length ).toBeGreaterThan( 0 );
		for ( const a of alfabeticas ) {
			expect( a.etiquetas, JSON.stringify( a ) ).toBeGreaterThan( 0 );
			expect( a.respiro, JSON.stringify( a ) ).toBeGreaterThanOrEqual( 4 );
		}
	} );

	test( 'en el cajón el anillo de foco y el glow de la casilla caben enteros en su lista', async ( { page, isMobile } ) => {
		// REV2-QFS-1 (2026-09-25): en el cajón la casilla quedaba a 1 px del
		// borde de recorte de su lista (overflow), y el anillo amarillo —2 px de
		// separación y 2 de grosor— y el glow rojo de la marcada —3 px— salían
		// cortados por la izquierda. Lo que asoma de la casilla necesita 4 px.
		test.skip( ! isMobile, 'en escritorio la etiqueta ya deja 9 px' );
		await page.goto( '/tienda/?_disponibilidad=instock' );
		await abrirFacetas( page, isMobile );
		const listas = await page.evaluate( () => Array.from( document.querySelectorAll( '.akb-facets .akb-facet-list' ) )
			.filter( ( l ) => l.getClientRects().length )
			.map( ( l ) => {
				const borde = l.getBoundingClientRect().left + l.clientLeft;
				const casillas = Array.from( l.querySelectorAll( 'input' ) ).filter( ( i ) => i.getClientRects().length );
				return {
					faceta: l.closest( '.akb-facet' )?.getAttribute( 'data-facet' ),
					casillas: casillas.length,
					holgura: Math.floor( Math.min( ...casillas.map( ( i ) => i.getBoundingClientRect().left - borde ) ) ),
				};
			} ) );
		expect( listas.length ).toBeGreaterThan( 0 );
		for ( const l of listas ) {
			expect( l.casillas, JSON.stringify( l ) ).toBeGreaterThan( 0 );
			expect( l.holgura, JSON.stringify( l ) ).toBeGreaterThanOrEqual( 4 );
		}
	} );

	test( 'el botón del cajón concuerda en número: «Ver 1 producto»', async ( { page, isMobile } ) => {
		test.skip( ! isMobile, 'el botón sólo se ve en el cajón móvil' );
		await page.goto( '/tienda/?_buscar=frieren' );
		await page.locator( '[data-akb-drawer-open]' ).first().click();
		const deUno = page.locator( '.akb-facet-item:not(.is-ghost) label' ).filter( { has: page.locator( '.akb-facet-count', { hasText: /^1$/ } ) } ).first();
		test.skip( 0 === await deUno.count(), 'ninguna opción con un solo producto en este entorno' );
		// El conteo reintenta un 503 transitorio del índice (1 s, 2 s, 4 s): con
		// otro carril guardando ajustes, el número puede tardar más de 10 s.
		const espera = { timeout: 20_000 };
		await deUno.click();
		const boton = page.locator( '.akb-facets-apply' );
		await expect( boton.locator( '[data-akb-apply-count]' ) ).toHaveText( '1', espera );
		await expect( boton ).not.toHaveClass( /is-loading/, espera );
		await expect( boton.locator( '[data-akb-apply-noun]' ) ).toHaveText( 'producto' );
		await deUno.click();
		await expect( boton.locator( '[data-akb-apply-count]' ) ).not.toHaveText( '1', espera );
		await expect( boton.locator( '[data-akb-apply-noun]' ) ).toHaveText( 'productos' );
	} );

	test( 'el combobox del buscador apunta a un listbox que existe, sin aria-haspopup explícito', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'en móvil el buscador es una hoja aparte, con su propia prueba' );
		await page.goto( '/' );
		const input = page.locator( '#akb-search-input' );
		await expect( input ).toHaveAttribute( 'role', 'combobox' );
		await expect( input ).not.toHaveAttribute( 'aria-haspopup', /.*/ );
		const id = await input.getAttribute( 'aria-controls' );
		await expect( page.locator( `#${ id }[role="listbox"]` ) ).toHaveCount( 1 );
	} );
} );
