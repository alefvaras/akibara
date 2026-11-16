// @ts-check
/**
 * Lo que las pruebas del tema escriben en el espejo se deshace SIEMPRE, pasen o
 * fallen.
 *
 * Revisión T4-R4 (2026-09-23): las specs del tema llenaban el carrito y lo
 * vaciaban en línea, al final de la prueba. Cualquier `expect` que fallara entre
 * llenar y vaciar dejaba en `wp_woocommerce_sessions` una sesión con un tomo, y la
 * suite no reintenta (medido con un fallo forzado justo después de agregar: dos
 * filas con `cart` de un artículo, una por spec). Vaciar tampoco devolvía la base
 * a como estaba: la fila de la sesión quedaba, vacía.
 *
 * Aquí la limpieza va en un `afterEach`, que Playwright corre también cuando la
 * prueba falla o agota su tiempo, y BORRA la sesión que abrió la prueba.
 */

import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { expect, test } from '@playwright/test';
import { soloLectura } from './tienda.mjs';

const ejecutar = promisify( execFile );

/** Raíz del repositorio (donde vive compose.yaml). */
export const RAIZ = new URL( '../../../../', import.meta.url ).pathname;

/**
 * Ejecuta PHP en el WordPress del espejo, sin dejar ficheros en el docroot.
 *
 * @param {string} codigo
 * @returns {Promise<string>} Lo que imprimió.
 */
export async function wpEspejo( codigo ) {
	return ( await ejecutar( 'docker', [ 'compose', 'exec', '-T', 'cron', 'wp', 'eval', codigo ], { cwd: RAIZ, timeout: 120_000 } ) ).stdout;
}

/**
 * Registra, en el bloque donde se llama, un `afterEach` que borra del espejo la
 * sesión de WooCommerce que abrió la prueba.
 *
 * Sólo una sesión de INVITADO (`t_…`): la crea el contexto de la propia prueba,
 * que empieza sin galletas, así que la fila es suya. La de un cliente con sesión
 * lleva su ID de usuario y la comparte con cualquiera que use esa cuenta: ésa no
 * se toca. Sin galleta no hay nada que borrar (la prueba no llegó a escribir).
 *
 * Se borra con `WC_Session_Handler::delete_session()`, que quita la fila Y su copia
 * en la caché de objetos, y se comprueba contando.
 */
export function borrarSesionWcAlTerminar() {
	test.afterEach( async ( { context, baseURL } ) => {
		if ( soloLectura ) {
			return;
		}
		const galleta = ( await context.cookies() ).find( ( c ) => c.name.startsWith( 'wp_woocommerce_session_' ) );
		if ( ! galleta ) {
			return;
		}
		const clave = decodeURIComponent( galleta.value ).split( /\|+/ )[ 0 ];
		if ( /^\d+$/.test( clave ) ) {
			return;
		}
		expect( clave, 'formato de sesión de invitado desconocido: no se puede limpiar a ciegas' ).toMatch( /^t_[0-9a-f]+$/ );
		expect( new URL( /** @type {string} */ ( baseURL ) ).hostname, 'se borra en la base del espejo local; contra otro sitio la prueba no puede escribir' ).toMatch( /^(localhost|127\.0\.0\.1)$/ );

		const quedan = await wpEspejo( `
			$k = '${ clave }';
			( new WC_Session_Handler() )->delete_session( $k );
			global $wpdb;
			echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", $k ) );
		` );
		expect( quedan.trim().split( '\n' ).pop(), `la sesión ${ clave } sigue en el espejo` ).toBe( '0' );
		test.info().annotations.push( { type: 'sesion-wc-borrada', description: clave } );
	} );
}
