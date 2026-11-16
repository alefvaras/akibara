// @ts-check
/**
 * Vista rápida deslizada: la cabecera (título y «×») queda ENCIMA de lo que
 * pasa por debajo.
 *
 * VERIF-Z-3 (verificación del lote de UX del tema, 2026-09-26). La cabecera del
 * diálogo es `position: sticky` y no tenía `z-index`; la portada y la fila del
 * precio (`position: relative`) y el aviso de red caída (z-index 1) vienen
 * después en el DOM y se pintaban encima al deslizar el diálogo. Medido en el
 * espejo antes del arreglo: hasta 36 de 36 puntos de la cabecera tapados, y a
 * 932x430 la portada caía sobre la «×» y el toque no cerraba.
 *
 * El diálogo sólo existe desde 769 px de ancho y sólo se desliza si no cabe en
 * la ventana: en el teléfono se mide girado (932x430, táctil) y en escritorio
 * con una ventana baja (1280x560). En Chromium y en WebKit.
 *
 * Sólo mira: la red del «Añadir» se corta en el navegador (el aviso aparece y
 * nada llega al servidor), así que vale también contra producción.
 */

import { existsSync } from 'node:fs';
import { expect, test, webkit } from '@playwright/test';

/** WebKit ya instalado en esta máquina (ver negocio-correcciones-qa-2). `AKB_WEBKIT` cambia la ruta. */
const WEBKIT = process.env.AKB_WEBKIT || `${ process.env.HOME }/Library/Caches/ms-playwright/webkit-2342/pw_run.sh`;

/** Terceros que no tienen nada que ver con el diálogo y sólo meten ruido. */
const TERCEROS = /google-analytics|googletagmanager|clarity\.ms|brevo|sendinblue|cloudflareinsights/;

/**
 * Recorre el diálogo de arriba abajo y, en cada parada, pregunta qué hay en 24
 * puntos de la cabecera. Devuelve cuántos no eran la cabecera y qué había.
 *
 * @param {import('@playwright/test').Page} page
 */
function sondearCabecera( page ) {
	return page.evaluate( async () => {
		const d = /** @type {HTMLDialogElement} */ ( document.querySelector( 'dialog.akb-qv[open]' ) );
		const cabecera = /** @type {HTMLElement} */ ( d.querySelector( '.akb-qv__head' ) );
		const max = d.scrollHeight - d.clientHeight;
		const paso = Math.max( 10, Math.round( max / 12 ) );
		let tapados = 0;
		const quien = new Set();
		for ( let y = 0; ; y += paso ) {
			d.scrollTop = Math.min( y, max );
			await new Promise( ( listo ) => requestAnimationFrame( () => requestAnimationFrame( listo ) ) );
			const c = cabecera.getBoundingClientRect();
			for ( let i = 0; i < 12; i++ ) {
				for ( const fy of [ 0.15, 0.5, 0.85 ] ) {
					const el = document.elementFromPoint( c.left + c.width * ( 0.04 + i * 0.08 ), c.top + c.height * fy );
					if ( ! el || ! cabecera.contains( el ) ) {
						tapados++;
						quien.add( el ? `${ el.tagName }.${ String( el.className ).split( ' ' )[ 0 ] }` : 'nada' );
					}
				}
			}
			if ( d.scrollTop >= max ) {
				break;
			}
		}

		// Al fondo del recorrido, el centro de la «×» tiene que ser la «×».
		const x = /** @type {HTMLElement} */ ( d.querySelector( '.akb-qv__close' ) ).getBoundingClientRect();
		const enLaX = document.elementFromPoint( x.left + x.width / 2, x.top + x.height / 2 );

		return { recorrido: max, tapados, quien: [ ...quien ], laXRecibe: enLaX ? `${ enLaX.tagName }.${ String( enLaX.className ).split( ' ' )[ 0 ] }` : 'nada', laXEsLaX: !! enLaX?.closest( '.akb-qv__close' ) };
	} );
}

test.describe( 'vista rápida deslizada (negocio)', () => {
	/** @type {import('@playwright/test').Browser|null} */
	let navegador = null;

	test.afterEach( async () => {
		await navegador?.close().catch( () => {} );
		navegador = null;
	} );

	test( 'la cabecera queda encima de la portada, el precio y el aviso, y la «×» cierra', async ( { page, baseURL } ) => {
		test.setTimeout( 240_000 );
		expect( existsSync( WEBKIT ), `WebKit en ${ WEBKIT } (o la ruta en AKB_WEBKIT)` ).toBe( true );

		const { use } = test.info().project;
		const tactil = !! use.hasTouch;
		// Teléfono girado o ventana baja de escritorio: las dos deslizan el diálogo.
		const ventana = tactil ? { width: 932, height: 430 } : { width: 1280, height: 560 };

		navegador = await webkit.launch( { executablePath: WEBKIT } );
		const contexto = await navegador.newContext( {
			baseURL,
			viewport: ventana,
			hasTouch: tactil,
			userAgent: use.userAgent,
			storageState: use.storageState,
		} );
		await page.setViewportSize( ventana );
		const paginas = { chromium: page, webkit: await contexto.newPage() };

		for ( const [ motor, p ] of Object.entries( paginas ) ) {
			p.setDefaultTimeout( 30_000 );
			const errores = /** @type {string[]} */ ( [] );
			p.on( 'pageerror', ( e ) => errores.push( e.message ) );
			await p.route( TERCEROS, ( r ) => r.abort() );
			await p.route( '**/?wc-ajax=add_to_cart', ( r ) => r.abort( 'failed' ) );

			await test.step( `${ motor }: abrir la vista rápida en /tienda/`, async () => {
				await p.goto( '/tienda/', { waitUntil: 'load', timeout: 90_000 } );
				const tarjeta = p.locator( 'li.product:has(> .akb-qv-trigger:not([hidden]))' ).first();
				await tarjeta.scrollIntoViewIfNeeded();
				if ( tactil ) {
					await tarjeta.locator( '.akb-qv-trigger' ).tap();
				} else {
					await tarjeta.hover();
					await tarjeta.locator( '.akb-qv-trigger' ).click();
				}
				await expect( p.locator( 'dialog.akb-qv[open] .akb-qv__actions .add_to_cart_button' ) ).toBeVisible();
			} );

			const limpio = await sondearCabecera( p );
			expect( limpio.recorrido, `${ motor }: a ${ ventana.width }x${ ventana.height } el diálogo tiene que deslizarse, o la prueba no mide nada` ).toBeGreaterThan( 20 );
			expect( limpio.tapados, `${ motor }: puntos de la cabecera tapados al deslizar (${ limpio.quien.join( ', ' ) })` ).toBe( 0 );
			expect( limpio.laXEsLaX, `${ motor }: al fondo, el centro de la «×» es ${ limpio.laXRecibe }` ).toBe( true );

			await test.step( `${ motor }: con la red caída, el aviso tampoco pasa por encima`, async () => {
				const clon = p.locator( 'dialog.akb-qv[open] .akb-qv__actions .add_to_cart_button' );
				await clon.scrollIntoViewIfNeeded();
				if ( tactil ) {
					await clon.tap();
				} else {
					await clon.click();
				}
				await expect( p.locator( 'dialog.akb-qv[open] .akb-qv__actions .akb-tarjeta-motivo' ) ).toBeVisible();
				const conAviso = await sondearCabecera( p );
				expect( conAviso.tapados, `${ motor }: con el aviso, puntos tapados (${ conAviso.quien.join( ', ' ) })` ).toBe( 0 );
			} );

			await test.step( `${ motor }: la «×» cierra con el diálogo al fondo`, async () => {
				const cerrar = p.locator( 'dialog.akb-qv[open] .akb-qv__close' );
				if ( tactil ) {
					await cerrar.tap();
				} else {
					await cerrar.click();
				}
				await expect( p.locator( 'dialog.akb-qv[open]' ) ).toHaveCount( 0 );
			} );

			expect( errores, `${ motor }: sin errores de JavaScript` ).toEqual( [] );
		}
	} );
} );
