// @ts-check
/**
 * El buscador: lo que el cliente teclea y lo que le devuelve la tienda.
 *
 * Tres superficies distintas que tienen que contar lo mismo: el desplegable de
 * sugerencias, la página de resultados y los chips de disponibilidad. Se
 * desincronizaron dos veces, y las dos se notaron sólo en producción.
 */

import { expect, test } from '@playwright/test';

test.describe( 'buscador', { tag: '@prod' }, () => {

	test( 'el formulario del header manda al motor propio', async ( { page, isMobile } ) => {
		await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		// En móvil el buscador vive en el cajón; en escritorio, en el header.
		const formulario = page.locator( isMobile ? 'form.mobile-drawer__search' : 'form.akibara-search' ).first();
		await expect( formulario ).toHaveAttribute( 'action', /\/tienda\// );
		await expect( formulario.locator( 'input[type="search"]' ) ).toHaveAttribute( 'name', '_buscar' );
	} );

	test( 'una búsqueda con resultados los muestra', async ( { page } ) => {
		const respuesta = await page.goto( '/tienda/?_buscar=one', { waitUntil: 'domcontentloaded' } );

		expect( respuesta?.status() ).toBe( 200 );
		await expect( page.locator( 'li.product' ).first() ).toBeVisible();
	} );

	test( 'una búsqueda sin resultados lo dice y ofrece salida', async ( { page } ) => {
		const respuesta = await page.goto( '/tienda/?_buscar=zzzqwertynoexiste', { waitUntil: 'domcontentloaded' } );

		expect( respuesta?.status(), 'cero resultados NO es un error: sigue siendo una página' ).toBe( 200 );
		// Ningún resultado en la rejilla del catálogo. Las tarjetas que SÍ salen
		// son la salida: «Lo más vendido» del tema, DENTRO del aviso (UM-8, lote de
		// integración 2026-09-23; ver tema-integracion.spec.mjs).
		expect( await page.locator( 'li.product' ).evaluateAll( ( lis ) => lis.filter( ( li ) => ! li.closest( '[data-akb-empty]' ) ).length ) ).toBe( 0 );

		const cuerpo = await page.locator( 'body' ).innerText();
		expect( cuerpo ).toMatch( /no encontramos|sin resultados|prueba con/i );
		// Y nada de voseo rioplatense en una tienda chilena (hallazgo S-5313bf96).
		expect( cuerpo ).not.toMatch( /Probá|explorá/ );
	} );

	test( 'el endpoint de sugerencias responde JSON, no una página', async ( { page, request } ) => {
		const respuesta = await request.get( '/wp-json/akibara/v1/search/suggest?q=na' );

		expect( respuesta.status(), 'el desplegable del buscador se apaga en silencio si esto no es 200' ).toBe( 200 );
		expect( respuesta.headers()[ 'content-type' ] || '' ).toContain( 'json' );

		const datos = await respuesta.json();
		expect( Array.isArray( datos ) || 'object' === typeof datos ).toBeTruthy();
	} );

	test( 'los chips de disponibilidad usan el vocabulario de la tienda', async ( { page } ) => {
		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );

		const cuerpo = await page.locator( 'body' ).innerText();
		// La palabra sale de Akibara → Textos desde business 1.116.20; lo que se
		// fija aquí es que NO aparezca el vocabulario crudo de WooCommerce.
		expect( cuerpo ).not.toContain( 'onbackorder' );
		expect( cuerpo ).not.toContain( 'instock' );
	} );
} );
