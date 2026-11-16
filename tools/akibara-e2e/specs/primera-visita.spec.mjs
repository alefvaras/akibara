// @ts-check
/**
 * La primera visita de un cliente nuevo.
 *
 * Un contexto sin galletas, que es lo que trae quien llega desde Google por
 * primera vez. Mientras LiteSpeed Guest Mode estuvo encendido, la suite entraba
 * como visitante que vuelve para esquivar su recarga; desde que se apagó (el
 * 2026-09-19) todo contexto de la suite es una primera visita, y esta prueba es
 * la que avisa si alguien lo vuelve a encender (`soporte/destino.mjs`).
 */
import { expect, test } from '@playwright/test';

test.describe( 'primera visita', { tag: '@prod' }, () => {
	test( 'la página no se recarga sola al llegar', async ( { browser, baseURL, isMobile } ) => {
		test.skip( isMobile, 'el comportamiento no depende del tamaño de pantalla' );
		// HALLAZGO N-guest-mode-recarga (medido en producción el 2026-09-19, 4 de
		// 4): LiteSpeed Guest Mode contestaba `{"reload":"yes"}` y la página se
		// cargaba DOS veces para todo cliente nuevo — lo que tecleó o desplazó en
		// ese primer segundo se perdía. El dueño autorizó apagarlo ese mismo día
		// (LiteSpeed Cache → General → Guest Mode → OFF). Esta prueba ya no es
		// `fixme`: si alguien vuelve a encenderlo —un preset de LiteSpeed lo
		// hace—, sale en rojo aquí.

		const contexto = await browser.newContext( { storageState: { cookies: [], origins: [] }, userAgent: 'AkibaraQA/e2e (+playwright)' } );
		const page = await contexto.newPage();
		let cargas = 0;
		page.on( 'framenavigated', ( frame ) => {
			if ( frame === page.mainFrame() ) {
				cargas++;
			}
		} );

		await page.goto( `${ baseURL }/tienda/`, { waitUntil: 'load' } );
		await page.waitForTimeout( 4_000 );

		expect( cargas, 'un cliente nuevo ve la tienda UNA vez, no dos' ).toBe( 1 );
		await contexto.close();
	} );
} );
