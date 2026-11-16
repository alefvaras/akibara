// @ts-check
/**
 * La dirección guardada llega a la caja: el cliente con cuenta no tiene que
 * volver a escribirla para ver el envío.
 *
 * Nació de un reporte de cliente (2026-09-28): «ya tenía la dirección en la
 * cuenta pero no le aparecía el paso del envío de Blue y tuvo que volver a
 * agregarla». La tienda envía siempre a la facturación (`billing_only`), la caja
 * muestra sólo esa tarjeta, pero WooCommerce cotiza con el ENVÍO guardado: si
 * estaba vacío, el paso decía «Introduce una dirección de envío…»; si era otro
 * (un punto Blue de un pedido anterior, otra comuna), cotizaba otra zona.
 *
 * Aquí se mide lo que ve el cliente y lo que la caja tiene cargado, con un
 * control de invitado que prueba que el detector sí ve el aviso. El espejo no
 * tiene credenciales de Blue, así que no se esperan tarifas de Blue: se mide que
 * la dirección con la que se cotiza sea la de la tarjeta.
 *
 * Siembra clientes y escribe carritos y la cuenta: sólo contra el espejo.
 */

import { execFile } from 'node:child_process';
import { copyFileSync, rmSync } from 'node:fs';
import { promisify } from 'node:util';
import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

const ejecutar = promisify( execFile );
const RAIZ = new URL( '../../../', import.meta.url ).pathname;
const AVISO_SIN_DIRECCION = /Introduce una dirección de envío/i;

/**
 * Ejecuta WP-CLI en el espejo.
 *
 * @param {string[]} argumentos Argumentos de wp.
 */
async function wp( argumentos ) {
	const { stdout } = await ejecutar(
		'docker',
		[ 'compose', '--profile', 'tools', 'run', '--rm', '-T', 'cli', 'wp', ...argumentos ],
		{ cwd: RAIZ, timeout: 180_000 }
	);
	return stdout;
}

/**
 * Siembra el cliente en el escenario pedido y devuelve su cookie de sesión.
 *
 * Cada proyecto de Playwright siembra su propio cliente: corren en paralelo.
 *
 * @param {'vacio'|'distinto'} escenario Estado del envío guardado.
 * @returns {Promise<{uid:number,nombre:string,valor:string}>}
 */
async function sembrar( escenario ) {
	const proyecto = test.info().project.name;
	// Un fichero por proyecto: si fuera el mismo, el `finally` de uno lo borra mientras el otro lo ejecuta.
	const fichero = `akb-e2e-sembrar-direccion-${ proyecto }.php`;
	const destino = `${ RAIZ }public_html/${ fichero }`;
	copyFileSync( new URL( './soporte/sembrar-direccion.php', import.meta.url ).pathname, destino );
	try {
		const salida = await wp( [ 'eval-file', fichero, escenario, proyecto ] );
		return JSON.parse( String( salida.split( '\n' ).find( ( l ) => l.startsWith( '{' ) ) ) );
	} finally {
		// Nunca se deja un fichero de pruebas servible en el docroot.
		rmSync( destino, { force: true } );
	}
}

/**
 * Deja un tomo disponible en el carrito de la sesión actual.
 *
 * @param {import('@playwright/test').Page} page Página.
 */
async function conUnTomo( page ) {
	const carrito = await page.request.get( '/wp-json/wc/store/v1/cart' );
	const nonce = carrito.headers().nonce;
	await page.request.delete( '/wp-json/wc/store/v1/cart/items', { headers: { Nonce: nonce } } );
	const lista = await ( await page.request.get( '/wp-json/wc/store/v1/products?stock_status=instock&per_page=20' ) ).json();
	const tomo = lista.find( ( p ) => p.is_purchasable && p.is_in_stock );
	test.skip( ! tomo, 'sin tomos disponibles en el espejo' );
	const r = await page.request.post( '/wp-json/wc/store/v1/cart/add-item', { headers: { Nonce: nonce }, data: { id: tomo.id, quantity: 1 } } );
	expect( r.ok(), 'el tomo entra al carrito' ).toBeTruthy();
}

/**
 * Abre la caja y devuelve la dirección con la que cotiza y el texto del paso de envío.
 *
 * @param {import('@playwright/test').Page} page Página.
 */
async function abrirCaja( page ) {
	await page.goto( '/checkout/', { waitUntil: 'domcontentloaded' } );
	const paso = page.locator( '.wp-block-woocommerce-checkout-shipping-methods-block' ).first();
	await expect( paso ).toBeVisible( { timeout: 30_000 } );
	await page.waitForFunction( () => {
		const w = /** @type {any} */ ( window );
		const datos = w.wp?.data?.select( 'wc/store/cart' )?.getCartData();
		return !! datos && datos.items.length > 0;
	}, null, { timeout: 30_000 } );
	await page.waitForLoadState( 'networkidle' );
	const direcciones = await page.evaluate( () => {
		const d = /** @type {any} */ ( window ).wp.data.select( 'wc/store/cart' ).getCartData();
		const parte = ( a ) => ( { calle: a.address_1, comuna: a.city, region: a.state } );
		return { envio: parte( d.shippingAddress ), facturacion: parte( d.billingAddress ) };
	} );
	return { paso, ...direcciones };
}

test.describe( 'la dirección guardada llega a la caja', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip( soloLectura, 'siembra clientes y escribe carritos: sólo contra el espejo' );

	test.afterAll( async ( {}, testInfo ) => {
		// Sin residuos: una cuenta sembrada desalineada haría saltar el centinela en el espejo.
		await wp( [ 'user', 'delete', `qa_e2e_direccion_${ testInfo.project.name }`, '--yes' ] ).catch( () => {} );
	} );

	test( 'control: un invitado sin dirección ve el aviso (el detector funciona)', async ( { page, context } ) => {
		await context.clearCookies();
		await conUnTomo( page );
		const { paso } = await abrirCaja( page );
		await expect( paso ).toContainText( AVISO_SIN_DIRECCION );
	} );

	test( 'cuenta con facturación guardada y envío vacío: cotiza con la tarjeta, sin pedir la dirección', async ( { page, context } ) => {
		const sesion = await sembrar( 'vacio' );
		await context.clearCookies();
		await context.addCookies( [ { name: sesion.nombre, value: sesion.valor, domain: 'localhost', path: '/' } ] );
		await conUnTomo( page );

		const caja = await abrirCaja( page );
		await expect( caja.paso ).not.toContainText( AVISO_SIN_DIRECCION );
		expect( caja.envio, 'la caja cotiza con la dirección de la tarjeta' ).toEqual( caja.facturacion );
		expect( caja.envio.comuna ).toBe( 'Curicó' );
	} );

	test( 'cuenta con otro envío guardado (punto viejo, otra región): cotiza con la tarjeta, no con el envío viejo', async ( { page, context } ) => {
		const sesion = await sembrar( 'distinto' );
		await context.clearCookies();
		await context.addCookies( [ { name: sesion.nombre, value: sesion.valor, domain: 'localhost', path: '/' } ] );
		await conUnTomo( page );

		const caja = await abrirCaja( page );
		expect( caja.envio, 'la caja cotiza con la dirección de la tarjeta' ).toEqual( caja.facturacion );
		expect( caja.envio.region, 'no con la región del envío viejo' ).toBe( 'CL-ML' );
	} );

	test( 'guardar la dirección en Mi cuenta deja el envío de la cuenta igual a la facturación', async ( { page, context } ) => {
		const sesion = await sembrar( 'distinto' );
		await context.clearCookies();
		await context.addCookies( [ { name: sesion.nombre, value: sesion.valor, domain: 'localhost', path: '/' } ] );

		await page.goto( '/mi-cuenta/edit-address/', { waitUntil: 'domcontentloaded' } );
		// Con `billing_only` Mi cuenta sólo ofrece la dirección de facturación.
		await page.getByRole( 'link', { name: 'Editar dirección' } ).first().click();
		const calle = page.locator( '#billing_address_1' );
		await expect( calle ).toBeVisible();
		await calle.fill( 'Volcán Osorno 321' );
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'domcontentloaded' } ), page.locator( 'button[name="save_address"]' ).click() ] );
		await expect( page.locator( '.woocommerce-message, .wc-block-components-notice-banner.is-success' ).first() ).toBeVisible();

		const envio = ( await wp( [ 'user', 'meta', 'get', String( sesion.uid ), 'shipping_address_1' ] ) ).trim();
		const region = ( await wp( [ 'user', 'meta', 'get', String( sesion.uid ), 'shipping_state' ] ) ).trim();
		expect( envio, 'el envío de la cuenta sigue a la facturación recién guardada' ).toBe( 'Volcán Osorno 321' );
		expect( region ).toBe( 'CL-ML' );
	} );

	test( 'invitado que sólo manda la facturación: la caja cotiza con ella', async ( { page, context } ) => {
		await context.clearCookies();
		await conUnTomo( page );
		const nonce = ( await page.request.get( '/wp-json/wc/store/v1/cart' ) ).headers().nonce;
		const r = await page.request.post( '/wp-json/wc/store/v1/cart/update-customer', {
			headers: { Nonce: nonce },
			data: { billing_address: { first_name: 'QA', last_name: 'Invitado', address_1: 'Volcán Llaima 715', city: 'Curicó', state: 'CL-ML', country: 'CL', postcode: '', phone: '87654321', email: 'qa-invitado@akibara.test' } },
		} );
		expect( r.ok(), 'el Store API acepta la facturación' ).toBeTruthy();

		const caja = await abrirCaja( page );
		await expect( caja.paso ).not.toContainText( AVISO_SIN_DIRECCION );
		expect( caja.envio, 'la caja cotiza con la dirección de la tarjeta' ).toEqual( caja.facturacion );
	} );

	test( 'con el envío libre (sin billing_only) la regla se apaga y respeta el envío guardado', async ( { page, context } ) => {
		const antes = ( await wp( [ 'option', 'get', 'woocommerce_ship_to_destination' ] ) ).trim();
		await wp( [ 'option', 'update', 'woocommerce_ship_to_destination', 'shipping' ] );
		try {
			const sesion = await sembrar( 'distinto' );
			await context.clearCookies();
			await context.addCookies( [ { name: sesion.nombre, value: sesion.valor, domain: 'localhost', path: '/' } ] );
			await conUnTomo( page );

			const caja = await abrirCaja( page );
			expect( caja.envio.comuna, 'con envío libre el envío guardado es del cliente' ).toBe( 'Providencia' );
			expect( caja.envio.region ).toBe( 'CL-RM' );
		} finally {
			// El interruptor vuelve a su estado aunque la prueba falle.
			await wp( [ 'option', 'update', 'woocommerce_ship_to_destination', antes || 'billing_only' ] );
		}
	} );
} );
