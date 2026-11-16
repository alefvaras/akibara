// @ts-check
/**
 * El título de las reseñas de la ficha (akibara-rewards + tema).
 *
 * El tema titula la sección de reseñas de TODAS las fichas con la option de
 * akibara-rewards `akb_rewards_carousel_title` (antes era un texto fijo en CSS).
 * Hasta rewards 0.4.17 ese campo se llamaba «Título de la sección», vivía en
 * «Carrusel del home», no tenía ayuda ni tope: 465 caracteres se guardaban
 * enteros y salían como encabezado de reseñas de cada ficha (QA-PANEL-5), y el
 * dueño no tenía forma de saber que «el título del carrusel» cambiaba las fichas
 * (REV-T6-2-4, QA-DIFF-TEMA-2).
 *
 * Lo que se fija aquí:
 *
 *  - En la ficha (lectura, también contra producción): el encabezado de las
 *    reseñas existe, no está vacío, tiene como mucho 60 caracteres y cabe en dos
 *    líneas a 390 px sin desbordar. Si el tema llega a producción antes que el
 *    rewards con el tope, y alguien guarda un título largo, esto lo dice.
 *    Mientras producción sirva el tema anterior (el que escondía el <h2> y
 *    pintaba el título por CSS) la prueba se salta sola, diciendo por qué: tras
 *    desplegar el tema tiene que salir VERDE, no saltada.
 *  - En el panel (sólo el espejo, con una sesión de administrador de pruebas):
 *    el rótulo nombra la portada Y las fichas, la ayuda dice «cada ficha de
 *    producto» y el tope, y el navegador no deja escribir más de 60. Contra
 *    rewards 0.4.17 las tres cosas fallan. No se guarda nada: el recorte en el
 *    guardado, con su aviso, lo prueban las pruebas PHP de SettingsPage. Un
 *    espejo sin administrador de pruebas (login qa… o test…) se salta con el
 *    motivo, no sale rojo.
 */

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

/** El tope del panel (SettingsPage::FIELDS['akb_rewards_carousel_title']['largo']). */
const TOPE = 60;

const RAIZ = new URL( '../../../', import.meta.url ).pathname;

/**
 * PHP por STDIN dentro del espejo: nada se escribe en el docroot.
 *
 * @param {string} php Código PHP (con su `<?php`).
 * @returns {string} Lo que imprimió.
 */
function wpEval( php ) {
	return execFileSync(
		'docker',
		[ 'compose', '--profile', 'tools', 'run', '--rm', '-T', 'cli', 'wp', 'eval-file', '-' ],
		{ cwd: RAIZ, input: php, timeout: 180_000, encoding: 'utf8' }
	);
}

/**
 * Ficha de un tomo real, por la Store API (sin slugs clavados).
 *
 * @param {import('@playwright/test').APIRequestContext} request Cliente HTTP.
 * @returns {Promise<string|null>} Ruta de la ficha, o null si no hay.
 */
async function unaFicha( request ) {
	const respuesta = await request.get( '/wp-json/wc/store/v1/products?type=simple&per_page=20' );
	if ( ! respuesta.ok() ) {
		return null;
	}
	/** @type {Array<{permalink:string}>} */
	const productos = await respuesta.json();
	// Los tomos de prueba que siembran otros carriles (qa-…) no son el catálogo.
	const ficha = productos.find( ( p ) => ! /\/qa-/.test( p.permalink ) );
	return ficha ? new URL( ficha.permalink ).pathname : null;
}

test( 'la ficha titula sus reseñas con un encabezado corto que cabe', async ( { page, request } ) => {
	const ruta = await unaFicha( request );
	test.skip( null === ruta, 'la Store API no devolvió ningún tomo' );

	const respuesta = await page.goto( String( ruta ), { waitUntil: 'domcontentloaded' } );
	expect( respuesta?.status() ).toBe( 200 );

	// En escritorio las reseñas son una pestaña; en móvil los paneles van apilados.
	const pestana = page.locator( '#tab-title-reviews a' );
	if ( await pestana.isVisible() ) {
		await pestana.click();
	}

	const titulo = page.locator( 'h2.woocommerce-Reviews-title' );
	await titulo.waitFor( { state: 'attached' } );
	// El tema de antes del 2026-09-22 (producción hasta desplegar el lote)
	// escondía este <h2>, con el «Valoraciones» de WooCommerce dentro, y pintaba
	// el título con un ::after fijo: ahí la option no llega a la ficha y no hay
	// nada que medir. Oculto con CUALQUIER otro texto, sí es un fallo.
	const texto = String( await titulo.textContent() ).trim();
	test.skip(
		! ( await titulo.isVisible() ) && /valoraci/i.test( texto ),
		`El tema servido todavía esconde el título de WooCommerce («${ texto }») y pinta el suyo por CSS: la option no llega a la ficha.`
	);
	await expect( titulo ).toBeVisible();
	const medida = await titulo.evaluate( ( el ) => {
		const rango = document.createRange();
		rango.selectNodeContents( el );
		return {
			texto: String( el.textContent ).trim(),
			lineas: new Set( [ ...rango.getClientRects() ].map( ( r ) => Math.round( r.top ) ) ).size,
			desborda: el.scrollWidth > el.clientWidth + 1,
		};
	} );

	expect( medida.texto.length, 'un encabezado vacío deja la barra roja huérfana' ).toBeGreaterThan( 0 );
	expect( [ ...medida.texto ].length, `«${ medida.texto }» pasa del tope del panel` ).toBeLessThanOrEqual( TOPE );
	expect( medida.lineas, 'a 390 px, 60 caracteres en Bebas Neue son dos líneas' ).toBeLessThanOrEqual( 2 );
	expect( medida.desborda ).toBe( false );
} );

test.describe( 'panel Puntos y Reseñas: el título de las reseñas', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip( soloLectura, 'necesita una sesión de administrador: sólo el espejo' );

	/** @type {{uid:number,token:string,sin_escenario?:boolean,error?:string,cookies:Array<{name:string,value:string}>}} */
	let sesion;

	test.beforeAll( ( {}, info ) => {
		// UNA sesión por corrida: los proyectos corren en workers paralelos, y
		// dos `WP_Session_Tokens::create()` a la vez sobre el mismo usuario se
		// pisan el meta `session_tokens` (leer-modificar-escribir): el móvil
		// acababa en «Por favor, accede de nuevo» (medido el 2026-09-24). El
		// panel se mira en escritorio; a 390 px se midió aparte y es la misma fila.
		test.skip( 'escritorio' !== info.project.name, 'una sola sesión de administrador por corrida' );

		const salida = wpEval( readFileSync( new URL( './soporte/rewards-sesion-admin.php', import.meta.url ), 'utf8' ) );
		const linea = salida.split( '\n' ).find( ( l ) => l.startsWith( '{' ) );
		// Sin ninguna línea JSON, wp-cli murió antes que el ayudante: eso es el
		// entorno roto, y se dice con lo que imprimió (no con un SyntaxError).
		expect( linea, `wp eval-file no devolvió JSON: «${ salida.trim().slice( 0, 300 ) }»` ).toBeTruthy();
		sesion = JSON.parse( String( linea ) );
		// Un espejo sin administrador de pruebas (re-clonado de producción, por
		// ejemplo) no tiene el escenario: se salta diciendo por qué.
		test.skip( true === sesion.sin_escenario, String( sesion.error ) );
		expect( sesion.token, sesion.error ).toBeTruthy();
	} );

	test.afterAll( () => {
		// Lo sembrado se retira: el token de sesión, y nada más se escribió.
		if ( sesion?.token ) {
			wpEval( `<?php WP_Session_Tokens::get_instance( ${ Number( sesion.uid ) } )->destroy( ${ JSON.stringify( sesion.token ) } ); echo "ok\\n";` );
		}
	} );

	test( 'el rótulo dice portada y fichas, la ayuda dice el tope y el campo no admite más', async ( { context, page, baseURL } ) => {
		const dominio = new URL( String( baseURL ) ).hostname;
		await context.addCookies( sesion.cookies.map( ( c ) => ( { ...c, domain: dominio, path: '/' } ) ) );

		const respuesta = await page.goto( '/wp-admin/admin.php?page=akb-rewards-settings', { waitUntil: 'domcontentloaded' } );
		expect( respuesta?.status() ).toBe( 200 );
		expect( page.url(), 'la sesión sembrada no entró: acabó en el login' ).not.toContain( 'wp-login.php' );

		const campo = page.locator( '#akb_rewards_carousel_title' );
		const fila = page.locator( 'tr', { has: campo } );
		const rotulo = fila.locator( 'label[for="akb_rewards_carousel_title"]' );

		await expect( rotulo ).toContainText( 'portada' );
		await expect( rotulo ).toContainText( 'fichas' );
		await expect( fila.locator( 'p.description' ).first() ).toContainText( 'cada ficha de producto' );
		await expect( fila.locator( 'p.description' ).first() ).toContainText( `Hasta ${ TOPE } caracteres.` );
		await expect( campo ).toHaveAttribute( 'maxlength', String( TOPE ) );

		// El rótulo lleva al campo, y el teclado no pasa del tope. Sin guardar.
		await rotulo.click();
		await expect( campo ).toBeFocused();
		await page.keyboard.press( 'ControlOrMeta+a' );
		await page.keyboard.type( 'x'.repeat( TOPE + 20 ) );
		expect( ( await campo.inputValue() ).length ).toBe( TOPE );
	} );
} );
