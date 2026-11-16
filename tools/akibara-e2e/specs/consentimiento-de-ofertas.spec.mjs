// @ts-check
/**
 * La prueba del consentimiento de ofertas y novedades, por la ruta REAL: el
 * checkout de bloques, con la casilla que registra el proveedor de campañas.
 *
 * Nace de la ronda 6 de QA (2026-09-19), la primera con evidencia de
 * producción: ningún pedido real llevó jamás `_akb_marketing_consent`.
 *
 *  - R6-consentimiento-sin-tocar: el bloque sólo envía en `additional_fields`
 *    los campos que el cliente tocó. La casilla sin marcar no viajaba,
 *    WooCommerce no disparaba el hook del campo y el pedido cerraba sin
 *    prueba, con un aviso diario que culpaba al proveedor.
 *  - R6-consentimiento-sin-nota: con la casilla marcada, WooCommerce copia
 *    la sesión al pedido ANTES de su primer save(): la prueba quedaba, la
 *    nota privada no.
 *
 * Sólo contra el espejo: crea pedidos, y necesita la casilla de Brevo, que el
 * espejo no tiene — se registra con un doble (mu-plugin copiado y borrado
 * aquí mismo). El RUT es sintético (cuerpo no trivial y dígito verificador
 * válido) y el pedido se borra al terminar devolviendo el stock.
 */
import { execFile } from 'node:child_process';
import { copyFileSync, rmSync } from 'node:fs';
import { promisify } from 'node:util';
import { expect, test } from '@playwright/test';
import { productoConBadge, soloLectura } from './soporte/tienda.mjs';

const ejecutar = promisify( execFile );
const RAIZ = new URL( '../../../', import.meta.url ).pathname;
const CASILLA = `${ RAIZ }public_html/wp-content/mu-plugins/akb-e2e-casilla-de-ofertas.php`;
const UA = 'AkibaraQA/e2e (+playwright)';
const CASILLA_ID = '#order-SendinblueWoocommerce-newsletter_opt_in';

/**
 * Corre el soporte PHP dentro del espejo (Docker sólo monta public_html).
 *
 * @param {'leer'|'borrar'} accion Qué hacer.
 * @param {number} id Pedido.
 * @returns {Promise<any>} La línea JSON que devuelve el soporte.
 */
async function soporte( accion, id ) {
	const destino = `${ RAIZ }public_html/akb-e2e-consentimiento.php`;
	copyFileSync( new URL( './soporte/consentimiento.php', import.meta.url ).pathname, destino );
	try {
		const { stdout } = await ejecutar(
			'docker',
			[ 'compose', '--profile', 'tools', 'run', '--rm', '-T', 'cli', 'wp', 'eval-file', 'akb-e2e-consentimiento.php', accion, String( id ) ],
			{ cwd: RAIZ, timeout: 180_000 }
		);
		const linea = stdout.split( '\n' ).find( ( l ) => l.startsWith( '{' ) );
		return JSON.parse( String( linea ) );
	} finally {
		// Nunca se deja un fichero de pruebas servible en el docroot.
		rmSync( destino, { force: true } );
	}
}

test.describe( 'consentimiento de ofertas y novedades (prueba en el pedido)', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip( soloLectura, 'crea pedidos y registra una casilla de pruebas: sólo contra el espejo' );
	// Una compra entera con el bloque (cálculos, reintento del clic y WP-CLI) no cabe en el minuto por defecto.
	test.beforeEach( () => test.setTimeout( 150_000 ) );

	/** @type {number[]} */
	const pedidos = [];

	test.beforeAll( () => {
		copyFileSync( new URL( './soporte/casilla-de-ofertas.php', import.meta.url ).pathname, CASILLA );
	} );

	test.afterAll( async () => {
		rmSync( CASILLA, { force: true } );
		for ( const id of pedidos ) {
			await soporte( 'borrar', id );
		}
	} );

	/**
	 * Una compra de invitado con el bloque, tal como la hace el cliente.
	 *
	 * @param {import('@playwright/test').Browser} browser Navegador.
	 * @param {string} baseURL Raíz del sitio.
	 * @param {boolean} marcar ¿Marcar la casilla de ofertas?
	 * @returns {Promise<number>} El id del pedido creado.
	 */
	async function comprar( browser, baseURL, marcar ) {
		const contexto = await browser.newContext( { storageState: { cookies: [], origins: [] }, userAgent: UA } );
		const page = await contexto.newPage();
		// Desde el final: esta compra agota un tomo (stock 1 en el espejo) y los
		// specs que corren en paralelo miran el primero.
		const url = await productoConBadge( page, 'Disponible', true );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.goto( String( url ), { waitUntil: 'domcontentloaded' } );
		const boton = page.locator( '.single_add_to_cart_button' ).first();
		await boton.click();
		await expect( boton ).toHaveClass( /is-added/, { timeout: 20_000 } );

		await page.goto( `${ baseURL }/checkout/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForLoadState( 'networkidle' );
		await expect( page.locator( CASILLA_ID ), 'la casilla de ofertas se pinta en «Información adicional»' ).toBeVisible();

		for ( const [ campo, valor ] of [
			[ '#email', 'ale.fvaras@gmail.com' ],
			[ '#contact-akibara-rut', '12.345.678-5' ],
			[ '#billing-first_name', 'QA' ],
			[ '#billing-last_name', 'Consentimiento' ],
			[ '#billing-address_1', 'Av. Prueba 123' ],
			[ '#billing-city', 'Providencia' ],
			[ '#billing-phone', '+56987654321' ],
		] ) {
			await page.fill( campo, valor );
			await page.keyboard.press( 'Tab' );
			await page.waitForTimeout( 300 );
		}
		await page.check( '#radio-control-wc-payment-method-options-bacs' );
		if ( marcar ) {
			await page.check( CASILLA_ID );
		}

		// El bloque recalcula (POST con `__experimental_calc_totals`) un momento
		// después de cada cambio, y un clic que cae dentro de ese cálculo se
		// pierde sin error ni aviso. Se pulsa en reposo y, si no navega, se
		// vuelve a pulsar: lo que se mide es lo que queda en el pedido, no el
		// número de clics.
		for ( let intento = 0; intento < 3; intento++ ) {
			await page.waitForLoadState( 'networkidle' );
			await page.waitForTimeout( 2000 );
			await page.click( '.wc-block-components-checkout-place-order-button' );
			const llego = await page.waitForURL( /order-received\/(\d+)/, { timeout: 20_000 } ).then( () => true, () => false );
			if ( llego ) {
				break;
			}
		}
		expect( page.url(), 'la compra termina en la página de pedido recibido' ).toMatch( /order-received\/(\d+)/ );

		const id = Number( page.url().match( /order-received\/(\d+)/ )?.[ 1 ] );
		pedidos.push( id );
		await contexto.close();
		return id;
	}

	/**
	 * Lee la prueba y devuelve el pedido (y su stock) en el acto, no al final:
	 * cuanto menos dure el tomo agotado, menos molesta a los demás specs.
	 *
	 * @param {number} id Pedido.
	 * @returns {Promise<any>} La foto del consentimiento.
	 */
	async function leerYDevolver( id ) {
		const foto = await soporte( 'leer', id );
		await soporte( 'borrar', id );
		pedidos.splice( pedidos.indexOf( id ), 1 );
		return foto;
	}

	test( 'sin tocar la casilla, el pedido guarda la prueba «NO» con el texto que vio el cliente', async ( { browser, baseURL, isMobile } ) => {
		test.skip( isMobile, 'el mecanismo no depende del tamaño de pantalla' );
		const id = await comprar( browser, String( baseURL ), false );
		const foto = await leerYDevolver( id );

		expect( foto.prueba, 'la prueba existe aunque la casilla no viajara en el POST' ).not.toBeNull();
		expect( foto.prueba.value ).toBe( false );
		expect( foto.prueba.label ).toContain( '(opcional)' );
		expect( foto.prueba.source ).toBe( 'checkout-blocks' );
		expect( foto.notas ).toHaveLength( 1 );
		expect( foto.notas[ 0 ] ).toContain( 'NO — casilla del checkout sin marcar' );
		expect( foto.meta_woo, 'el bloque no envió la casilla: WooCommerce no escribió su meta' ).toBe( '' );
	} );

	test( 'marcando la casilla, la prueba dice «SÍ» y la nota privada queda escrita', async ( { browser, baseURL, isMobile } ) => {
		test.skip( isMobile, 'el mecanismo no depende del tamaño de pantalla' );
		const id = await comprar( browser, String( baseURL ), true );
		const foto = await leerYDevolver( id );

		expect( foto.prueba?.value ).toBe( true );
		expect( foto.meta_woo, 'la casilla marcada sí viaja y WooCommerce la guarda' ).toBe( '1' );
		expect( foto.notas, 'una sola nota, aunque el campo se persista dos veces (sesión → pedido, y POST)' ).toHaveLength( 1 );
		expect( foto.notas[ 0 ] ).toContain( 'SÍ — casilla del checkout marcada' );
		expect( foto.notas[ 0 ] ).toContain( 'Política vigente del' );
	} );
} );
