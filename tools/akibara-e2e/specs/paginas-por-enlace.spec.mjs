// @ts-check
/**
 * Las páginas a las que se llega por un enlace de correo: el formulario de
 * reseña y las preferencias de invitaciones (rewards 0.4.9).
 *
 * Se prueba con enlaces INVENTADOS. Lo que se fija es lo que un enlace malo no
 * puede hacer —ni un 500, ni un 200 engañoso, ni quedar en la caché del borde— y
 * que la ruta antigua de los correos ya enviados sigue viva. Todo es GET salvo el
 * POST de baja de un clic, que se salta en sólo lectura (E2E-2).
 */

import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

const FIRMA_FALSA = '0'.repeat( 32 );

test.describe( 'páginas por enlace', () => {

	test( 'un enlace de baja que no se reconoce responde 404, nunca 200', { tag: '@prod' }, async ( { request } ) => {
		const get = await request.get( `/mi-cuenta/?akb_rw_baja=1-${ FIRMA_FALSA }` );
		expect( get.status() ).toBe( 404 );
		expect( await get.text() ).toContain( 'Ese enlace ya no sirve' );
	} );

	test( 'el POST de un clic con una firma que no se reconoce también responde 404', async ( { request } ) => {
		// El «Cancelar suscripción» de Gmail es un POST sin pantalla: lee
		// cualquier 2xx como «hecho» y se lo dice al cliente.
		test.skip( soloLectura, 'es un POST (List-Unsubscribe): sólo contra el espejo' );
		const post = await request.post( `/mi-cuenta/?akb_rw_baja=1-${ FIRMA_FALSA }`, { form: { 'List-Unsubscribe': 'One-Click' } } );
		expect( post.status(), 'un POST de un clic con firma falsa' ).toBe( 404 );
	} );

	test( 'la página de baja no sale de la caché del borde', { tag: '@prod' }, async ( { request, baseURL } ) => {
		test.skip( ! String( baseURL ).includes( 'akibara.cl' ), 'el espejo no tiene Cloudflare delante' );

		const url = `/mi-cuenta/?akb_rw_baja=2-${ FIRMA_FALSA }`;
		await request.get( url );
		const segunda = await request.get( url );

		expect( segunda.headers()[ 'cf-cache-status' ], 'la regla del borde pisa el no-store: la defensa es de RUTA' ).toBe( 'DYNAMIC' );
	} );

	test( 'el enlace antiguo de reseña redirige a la ruta que no se cachea', async ( { request } ) => {
		const token = 'QaTokenInventadoE2E0123456789';
		const r = await request.get( `/resena/${ token }/`, { maxRedirects: 0 } );

		expect( r.status() ).toBe( 302 );
		expect( r.headers().location ).toContain( `/mi-cuenta/?akb_review_token=${ token }` );
	} );

	test( 'un token de reseña inventado lo dice con buenas maneras', async ( { page } ) => {
		const r = await page.goto( '/mi-cuenta/?akb_review_token=QaTokenInventadoE2E0123456789', { waitUntil: 'domcontentloaded' } );

		expect( r?.status() ).toBeLessThan( 500 );
		expect( await page.locator( 'body' ).innerText() ).toMatch( /caduc|ya se usó|no sirve|no es válido/i );
		// Y no es el muro de acceso de Mi cuenta: la página del enlace se pinta antes.
		await expect( page.getByText( /Continuar con Google/i ) ).toHaveCount( 0 );
	} );
} );
