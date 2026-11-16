// @ts-check
/**
 * Utilidades compartidas: encontrar productos REALES del catálogo en vez de
 * clavar URLs que mañana cambian de slug.
 *
 * Todo lo de aquí es de LECTURA. Lo que siembra datos vive en su propio spec y
 * se salta cuando `AKB_SOLO_LECTURA` está puesto.
 */

import { expect, test } from '@playwright/test';

export const soloLectura = '1' === process.env.AKB_SOLO_LECTURA;

/** Fichas de /tienda/ que se miran buscando un armador antes de rendirse. */
const FICHAS_A_MIRAR = 16;

/** Por proceso y sitio: la ficha con armador ya encontrada (cada búsqueda son cargas de la tienda). */
const fichaConArmadorHallada = new Map();

/**
 * Deja la página en la primera ficha válida de /tienda/.
 *
 * @param {import('@playwright/test').Page} page Página.
 * @returns {Promise<{url: string, estado: number}|null>} La ficha y su código, o null.
 */
export async function fichaConProducto( page ) {
	const sitio = process.env.AKB_BASE_URL || 'http://localhost:8080';
	const hallada = fichaConArmadorHallada.get( sitio );
	if ( hallada ) {
		const r = await page.goto( hallada, { waitUntil: 'load' } );
		expect( r?.status(), `${ hallada } no puede responder 5xx` ).toBeLessThan( 500 );
		if ( r && r.status() < 400 && ( await page.locator( '.product_title' ).count() ) > 0 ) {
			return { url: hallada, estado: r.status() };
		}
		fichaConArmadorHallada.delete( sitio );
	}

	const listado = await page.goto( '/tienda/', { waitUntil: 'domcontentloaded' } );
	expect( listado?.status(), '/tienda/' ).toBe( 200 );
	const enlaces = await page.locator( 'li.product a.woocommerce-LoopProduct-link' ).evaluateAll(
		( els ) => els.map( ( e ) => e.getAttribute( 'href' ) || '' ).filter( Boolean )
	);
	for ( const url of enlaces.slice( 0, FICHAS_A_MIRAR ) ) {
		const r = await page.goto( url, { waitUntil: 'load' } );
		expect( r?.status(), `${ url }: respuesta válida de ficha` ).toBeLessThan( 500 );
		if ( r && r.status() < 400 && ( await page.locator( '.product_title' ).count() ) > 0 ) {
			fichaConArmadorHallada.set( sitio, url );
			return { url, estado: r.status() };
		}
	}
	return null;
}

export const fichaConArmador = fichaConProducto;

/**
 * El formulario de contacto no llega al servidor desde esta página, pase lo que pase.
 *
 * Sale por dos caminos: el REST de akibara-forms.js (`/wp-json/akibara/v1/contacto`)
 * y, si ese JS no corrió, el envío nativo a `admin-post.php`, su `action`. Las
 * pruebas lo envían vacío, inválido o con el REST contestado de mentira: si la
 * validación o esa intercepción fallaran, el mensaje le llegaría al dueño (E2E-2).
 * Con los dos caminos cortados, la prueba sale en rojo sin enviar nada. Va ANTES
 * que las rutas de la propia prueba, que así mandan sobre ésta.
 *
 * @param {import('@playwright/test').Page} page Página.
 * @returns {Promise<string[]>} Los envíos cortados («MÉTODO url»).
 */
export async function cortarEnviosDeContacto( page ) {
	/** @type {string[]} */
	const cortados = [];
	await page.route( /\/wp-json\/akibara\/v1\/contacto|\/wp-admin\/admin-post\.php/, ( r ) => {
		cortados.push( `${ r.request().method() } ${ r.request().url() }` );
		return r.abort();
	} );
	return cortados;
}

/** Terceros que sólo corren en producción: sus fallos no son de la tienda (E2E-6). */
const DE_TERCEROS = /^https?:\/\/([a-z0-9-]+\.)*(googletagmanager\.com|clarity\.ms)\//i;

/**
 * Errores de JavaScript de la página que son de la tienda.
 *
 * gtag (googletagmanager.com) y Clarity (clarity.ms) corren sólo en producción y
 * a veces tiran un error propio —«Cannot read properties of null (reading
 * 'sequence')», 1 de 5 corridas el 2026-09-26— que ponía en rojo una prueba sin
 * que la tienda tuviera nada roto. Se ignoran los que NACEN en esos dominios (el
 * primer marco de la pila); uno de la tienda llamado desde ellos sigue contando.
 * Cada error, ignorado o no, queda anotado en la prueba con su pila entera, para
 * poder atribuirlo después (el informe HTML y el JSON la muestran).
 *
 * @param {import('@playwright/test').Page} page Página.
 * @returns {string[]} Los errores propios, «mensaje — dónde nació».
 */
export function erroresDePagina( page ) {
	const info = test.info();
	/** @type {string[]} */
	const propios = [];
	page.on( 'pageerror', ( e ) => {
		const pila = e.stack || '';
		// El primer marco con URL, sin mirar el mensaje (que puede llevar una URL suya).
		const marcos = pila.includes( e.message ) ? pila.slice( pila.indexOf( e.message ) + e.message.length ) : pila;
		const origen = ( marcos.match( /https?:\/\/[^\s)]+/ ) || [ '' ] )[ 0 ];
		const deTerceros = DE_TERCEROS.test( origen );
		info.annotations.push( { type: deTerceros ? 'pageerror de terceros (ignorado)' : 'pageerror', description: pila || e.message } );
		if ( ! deTerceros ) {
			propios.push( `${ e.message } — ${ origen || 'sin pila' }` );
		}
	} );
	return propios;
}

/**
 * Primer producto del catálogo cuya tarjeta diga la palabra pedida.
 *
 * @param {import('@playwright/test').Page} page Página.
 * @param {string} palabra Texto del badge («Disponible», «A pedido»…).
 * @param {boolean} [desdeElFinal] Empezar por la última tarjeta (para specs que compran).
 * @returns {Promise<string|null>} URL del producto, o null si no hay ninguno.
 */
export async function productoConBadge( page, palabra, desdeElFinal = false ) {
	const rutas = [ '/tienda/', '/catalogo/' ];

	for ( const ruta of rutas ) {
		const respuesta = await page.goto( ruta, { waitUntil: 'domcontentloaded' } ).catch( () => null );
		if ( ! respuesta || respuesta.status() >= 400 ) {
			continue;
		}

		const tarjetas = page.locator( 'li.product' );
		// En móvil las tarjetas entran con lazy-load: sin esperar a la primera,
		// `innerText()` devuelve vacío y la búsqueda no encuentra nada, con lo
		// que la prueba se salta sola — la peor forma de pasar.
		await tarjetas.first().waitFor( { state: 'visible', timeout: 15_000 } ).catch( () => {} );
		const total = Math.min( await tarjetas.count(), 60 );
		// `innerText()` devuelve el texto TRANSFORMADO: el badge se escribe «A
		// pedido» y llega como «A PEDIDO» porque el CSS lo pone en mayúsculas.
		// Comparar tal cual no encontraba una sola tarjeta y las pruebas se
		// saltaban solas, que es la peor forma de pasar (medido el 2026-09-18).
		const buscada = palabra.toLocaleLowerCase( 'es' );

		// `desdeElFinal`: los specs que COMPRAN (y agotan un tomo con stock 1)
		// empiezan por la última tarjeta para no quitarle el primer tomo a los
		// specs que corren en paralelo y sólo lo miran (medido el 2026-09-19:
		// el carrito móvil falló mientras el de consentimiento compraba).
		for ( let paso = 0; paso < total; paso++ ) {
			const i = desdeElFinal ? total - 1 - paso : paso;
			const tarjeta = tarjetas.nth( i );
			const texto = ( await tarjeta.innerText().catch( () => '' ) ) || '';
			if ( ! texto.toLocaleLowerCase( 'es' ).includes( buscada ) ) {
				continue;
			}
			// El enlace del PRODUCTO, no el primero de la tarjeta: ésta también
			// lleva enlaces a la serie y a la editorial, y con `first()` el
			// test acababa mirando una landing en vez de una ficha (medido el
			// 2026-09-18: el spec del vocabulario fallaba con el texto de la
			// portada en la mano).
			const candidatos = await tarjeta.locator( 'a[href]' ).evaluateAll(
				( els ) => els.map( ( e ) => e.getAttribute( 'href' ) || '' )
			);
			const href = candidatos.find(
				( url ) => url && ! /\/(serie|editorial|tienda|a-pedido|disponibles|categoria|product-category)\//.test( url ) && ! url.includes( '?' ) && ! url.includes( '#' )
			);
			if ( href ) {
				return href;
			}
		}
	}

	return null;
}
