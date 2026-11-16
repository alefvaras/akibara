// @ts-check
/**
 * Accesibilidad de lo que se toca todos los días.
 *
 * No es una auditoría completa: son los tres defectos que esta tienda ya tuvo
 * —roles impuestos que borran el del elemento, imágenes sin alternativa y saltos
 * de encabezado— comprobados donde ocurrieron.
 */

import { expect, test } from '@playwright/test';

test.describe( 'accesibilidad', () => {

	test( 'hay exactamente un H1 por página', { tag: '@prod' }, async ( { page } ) => {
		for ( const ruta of [ '/', '/tienda/', '/carrito/' ] ) {
			await page.goto( ruta, { waitUntil: 'domcontentloaded' } );
			const h1 = await page.locator( 'h1' ).count();
			expect( h1, `«${ ruta }» tiene ${ h1 } H1` ).toBe( 1 );
		}
	} );

	test( 'los chips de «sin resultados» se anuncian como enlaces', async ( { page } ) => {
		// Hallazgo S-32be0859: `role="listitem"` sustituía al rol de enlace.
		await page.goto( '/tienda/?_buscar=zzzqwertynoexiste', { waitUntil: 'domcontentloaded' } );

		const chips = page.locator( '.akb-empty__chip' );
		test.skip( ! ( await chips.count() ), 'este entorno no pinta chips de categoría' );

		const total = await chips.count();
		for ( let i = 0; i < total; i++ ) {
			await expect( chips.nth( i ) ).not.toHaveAttribute( 'role', 'listitem' );
		}
		await expect( chips.first() ).toHaveRole( 'link' );
	} );

	test( 'las portadas del catálogo llevan texto alternativo', async ( { page } ) => {
		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );

		const imagenes = page.locator( 'li.product img' );
		const total = Math.min( await imagenes.count(), 12 );
		expect( total ).toBeGreaterThan( 0 );

		for ( let i = 0; i < total; i++ ) {
			const alt = await imagenes.nth( i ).getAttribute( 'alt' );
			expect( alt, 'una portada sin alt es un producto invisible para quien no ve' ).not.toBeNull();
		}
	} );

	test( 'el foco se ve al tabular por el header', async ( { page } ) => {
		await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		await page.keyboard.press( 'Tab' );
		const enfocado = page.locator( ':focus' );
		await expect( enfocado ).toBeVisible();

		const contorno = await enfocado.evaluate( ( el ) => {
			const estilo = window.getComputedStyle( el );
			return `${ estilo.outlineStyle }|${ estilo.outlineWidth }|${ estilo.boxShadow }`;
		} );
		expect( contorno, 'sin anillo de foco, navegar con teclado es a ciegas' ).not.toBe( 'none|0px|none' );
	} );
} );
