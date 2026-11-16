// @ts-check
/**
 * R12-email-o-correo (decisión del dueño, 2026-09-20): la casa dice «correo».
 *
 * Veintitrés literales propios decían «email» frente a «correo» en la FAQ y
 * los términos (formularios, plantillas de correo, respuestas de la API,
 * placeholder). Aquí se fijan las dos superficies que se pueden leer sin
 * sembrar nada: sólo GET, así que corre también contra producción en sólo
 * lectura y en los dos viewports. Va en fichero propio porque
 * `vocabulario.spec.mjs` pertenece al proyecto `siembra` (sólo espejo, sólo
 * escritorio, y se salta entero si otro proyecto falla).
 */

import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

test.describe( 'la casa dice «correo», no «email»', () => {
	test( 'el formulario de contacto pide «Correo»', async ( { page } ) => {
		await page.goto( '/contacto/', { waitUntil: 'domcontentloaded' } );
		const etiqueta = page.locator( 'label[for="akb-c-email"]' );
		await expect( etiqueta ).toContainText( 'Correo' );
		await expect( etiqueta ).not.toContainText( /email/i );
	} );

	test( 'la descripción de /a-pedido/ avisa «por correo»', async ( { page } ) => {
		// La meta description la emite Rank Math. Si en este entorno no está
		// (el espejo puede tenerlo apagado a propósito), la mitad medible es la
		// de producción; el literal PHP lo fija `LaCasaDiceCorreoNoEmailTest` en
		// la suite de business.
		await page.goto( '/a-pedido/', { waitUntil: 'domcontentloaded' } );
		const meta = page.locator( 'meta[name="description"]' );
		test.skip( ! soloLectura && 0 === ( await meta.count() ), 'sin meta description en este entorno (Rank Math apagado)' );
		const contenido = await meta.first().getAttribute( 'content', { timeout: 5_000 } );
		expect( contenido ?? '' ).toContain( 'por correo' );
		expect( contenido ?? '' ).not.toMatch( /email/i );
	} );
} );
