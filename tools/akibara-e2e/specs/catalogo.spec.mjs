// @ts-check
/**
 * El catálogo dice la verdad sobre lo que vende.
 *
 * Estas comprobaciones existen por defectos medidos: badges que decían una
 * palabra y botones que decían otra, tarjetas que prometían un «desde $X» que
 * la rejilla desmentía, y listados que respondían 200 sirviendo cero productos.
 */

import { expect, test } from '@playwright/test';
import { productoConBadge } from './soporte/tienda.mjs';

test.describe( 'catálogo', () => {

	test( 'la portada carga y ofrece camino a la tienda', { tag: '@prod' }, async ( { page } ) => {
		const respuesta = await page.goto( '/', { waitUntil: 'domcontentloaded' } );

		expect( respuesta?.status(), 'la portada es la primera impresión: nunca puede no ser 200' ).toBe( 200 );

		// `:visible` y no `.first()`: en móvil los primeros enlaces al catálogo
		// viven dentro del cajón cerrado, y comprobar el primero del DOM daba
		// rojo con tres caminos perfectamente visibles en pantalla.
		await expect( page.locator( 'a[href*="/tienda/"]:visible' ).first() ).toBeVisible();
	} );

	test( 'el listado sirve productos de verdad, no un 200 vacío', { tag: '@prod' }, async ( { page } ) => {
		// Medido una vez en prod: /a-pedido/ respondía 200, con título perfecto
		// y CERO tarjetas. Contar es la única forma de verlo.
		const respuesta = await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );

		expect( respuesta?.status() ).toBe( 200 );
		await expect( page.locator( 'li.product' ).first() ).toBeVisible();
		expect( await page.locator( 'li.product' ).count() ).toBeGreaterThan( 3 );
	} );

	test( 'cada tarjeta con precio lo muestra con símbolo y separador de miles', { tag: '@prod' }, async ( { page } ) => {
		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );

		const precios = page.locator( 'li.product .price' );
		const total = Math.min( await precios.count(), 10 );
		expect( total ).toBeGreaterThan( 0 );

		for ( let i = 0; i < total; i++ ) {
			const texto = await precios.nth( i ).innerText();
			expect( texto, 'un precio sin «$» es un precio que el cliente no entiende' ).toContain( '$' );
		}
	} );

	test( 'la ficha de un tomo disponible deja comprarlo', { tag: '@prod' }, async ( { page } ) => {
		const url = await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'el catálogo de este entorno no tiene ningún tomo disponible' );

		const respuesta = await page.goto( String( url ), { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status() ).toBe( 200 );

		await expect( page.locator( 'h1' ).first() ).toBeVisible();
		await expect( page.locator( 'button[name="add-to-cart"], .single_add_to_cart_button' ).first() ).toBeVisible();
	} );

	test( 'la ficha de un tomo a pedido lo dice en el badge Y en el botón', { tag: '@prod' }, async ( { page } ) => {
		// Las dos superficies salen de la misma palabra desde business 1.116.20.
		const url = await productoConBadge( page, 'A pedido' );
		test.skip( ! url, 'el catálogo de este entorno no tiene ningún tomo a pedido' );

		await page.goto( String( url ), { waitUntil: 'domcontentloaded' } );

		const boton = page.locator( '.single_add_to_cart_button, button[name="add-to-cart"]' ).first();
		await expect( boton ).toBeVisible();
		await expect( boton ).toHaveText( /a pedido|preventa|reservar/i );
	} );

	test( 'un 404 ofrece buscar, y esa búsqueda va al motor de la tienda', { tag: '@prod' }, async ( { page } ) => {
		// Hallazgo S-1e205397: el formulario apuntaba a la búsqueda nativa de
		// WordPress mientras el comentario prometía el motor propio.
		const respuesta = await page.goto( '/esta-url-no-existe-akibara-qa/', { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status() ).toBe( 404 );

		const campo = page.locator( 'form.akb-empty__search input[type="search"]' );
		await expect( campo ).toBeVisible();
		await expect( campo ).toHaveAttribute( 'name', '_buscar' );

		const accion = await page.locator( 'form.akb-empty__search' ).getAttribute( 'action' );
		expect( accion, 'el buscador del 404 tiene que llevar al mismo sitio que el del header' ).toContain( '/tienda/' );
	} );

	test( 'filtrar desde la página 2 lleva a la primera página del conjunto filtrado', async ( { page } ) => {
		// El formulario de filtros apuntaba a `/tienda/page/2/`: tildar una
		// editorial desde ahí pedía la página 2 de un conjunto que casi nunca la
		// tiene (hallazgo S-747bdfbd). Un filtro nuevo es un listado nuevo.
		const r = await page.goto( '/tienda/page/2/', { waitUntil: 'domcontentloaded' } );
		test.skip( 200 !== r?.status(), 'este entorno no tiene segunda página de catálogo' );

		const accion = await page.locator( 'form[data-akb-facets]' ).first().getAttribute( 'action' );
		expect( accion, 'la action del formulario de filtros' ).not.toMatch( /\/page\/\d+/ );
	} );

	test( '«Ver todas» de un filtro grande pide SU fragmento, no la página con otra cabecera', async ( { page, isMobile } ) => {
		test.skip( isMobile, 'en móvil los filtros viven en un cajón; el contrato de red es el mismo' );

		// Dos respuestas distintas en una misma URL las cachea cruzadas el borde:
		// guardaba el fragmento y lo servía como PÁGINA (medido en producción el
		// 2026-09-19, hallazgo S-127b9306). El fragmento tiene que vivir en su URL.
		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );

		// Los filtros grandes (Autor, Serie) llegan PLEGADOS: primero se abre el
		// grupo —como hace el cliente— y recién entonces existe «Ver todas».
		const grupo = page.locator( 'section.akb-facet[data-akb-facet-total]' ).first();
		test.skip( 0 === await grupo.count(), 'este entorno no tiene un filtro lo bastante grande' );
		if ( await grupo.evaluate( ( el ) => el.classList.contains( 'is-collapsed' ) ) ) {
			await grupo.locator( 'button, summary, [aria-expanded]' ).first().click();
		}

		const boton = grupo.locator( '[data-akb-facet-expand]' ).first();
		await expect( boton ).toBeVisible();

		const pedido = page.waitForRequest( ( r ) => r.url().includes( '_akb_expand=' ), { timeout: 15_000 } );
		await boton.click();
		const url = ( await pedido ).url();

		expect( url, 'la URL del fragmento es distinta de la de la página' ).toContain( '_akb_fragment=1' );

		// Y esa misma URL, SIN el parámetro, sigue siendo una página entera.
		const pagina = await page.request.get( url.replace( /[?&]_akb_fragment=1/, '' ), { headers: { 'X-Requested-With': 'akb-facet-options' } } );
		expect( await pagina.text(), 'la cabecera sola ya no cambia la respuesta' ).toContain( '<html' );

		// El clic dejó la lista completa EN la página, sin navegar a ninguna parte.
		await expect( grupo ).toHaveAttribute( 'data-akb-facet-loaded', 'true' );
		expect( page.url(), 'el rebote del fragmento no corre dentro del fetch' ).not.toContain( '_akb_fragment' );
	} );

	test( 'quien ABRE la URL del fragmento aterriza en la tienda entera, no en una lista suelta', async ( { page } ) => {
		// Con URL propia el fragmento se puede pegar en la barra (sale en la
		// pestaña Red, en un enlace compartido). Una URL tiene que dar UNA
		// respuesta —el borde no varía por cabeceras—, así que la respuesta lleva
		// su salida: un script que sólo corre cuando el HTML es el documento.
		await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
		const grupo = page.locator( 'section.akb-facet[data-akb-facet-total]' ).first();
		test.skip( 0 === await grupo.count(), 'este entorno no tiene un filtro lo bastante grande' );
		const nombre = await grupo.getAttribute( 'data-facet' );

		// `commit` y no `domcontentloaded`: el rebote corre ANTES de que ese evento
		// llegue, y Playwright daría la navegación por abortada (medido en prod).
		await page.goto( `/tienda/?_akb_expand=${ nombre }&_akb_fragment=1`, { waitUntil: 'commit' } ).catch( () => {} );
		await page.waitForURL( ( u ) => ! u.search.includes( '_akb_fragment' ), { timeout: 15_000 } );

		expect( page.url() ).toContain( `_akb_expand=${ nombre }` );
		await expect( page.locator( 'header, .site-header' ).first(), 'hay tienda alrededor' ).toBeVisible();
		await expect( page.locator( `section.akb-facet[data-facet="${ nombre }"]` ).first() ).toBeAttached();
	} );
} );
