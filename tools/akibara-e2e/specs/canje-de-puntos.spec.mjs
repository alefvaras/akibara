// @ts-check
/**
 * El canje de Puntos Akibara, de Mi cuenta a la caja: el camino del dinero con
 * sesión iniciada.
 *
 * El resto de la suite mira la tienda como invitado. Aquí entra un cliente de
 * pruebas con 5.000 puntos (sembrado en el espejo, con sesión por cookie: no se
 * teclea ninguna contraseña) y se comprueba lo que de verdad importa: que el
 * descuento que se promete en Mi cuenta es el que llega al carrito y a la caja,
 * que el total lo refleja, y que los bordes —carrito bajo el mínimo, quitar el
 * canje— dicen lo que pasa.
 *
 * Todo escribe (sesión, carrito, intención de canje): sólo contra el espejo.
 */

import { execFile } from 'node:child_process';
import { copyFileSync, rmSync } from 'node:fs';
import { promisify } from 'node:util';
import { expect, test } from '@playwright/test';
import { soloLectura } from './soporte/tienda.mjs';

const ejecutar = promisify( execFile );
const RAIZ = new URL( '../../../', import.meta.url ).pathname;

/** Siembra el cliente y devuelve su cookie de sesión. */
async function sembrarSesion() {
	const destino = `${ RAIZ }public_html/akb-e2e-sembrar-sesion.php`;
	copyFileSync( new URL( './soporte/sembrar-sesion.php', import.meta.url ).pathname, destino );
	try {
		const { stdout } = await ejecutar(
			'docker',
			[ 'compose', '--profile', 'tools', 'run', '--rm', '-T', 'cli', 'wp', 'eval-file', 'akb-e2e-sembrar-sesion.php' ],
			{ cwd: RAIZ, timeout: 180_000 }
		);
		const linea = stdout.split( '\n' ).find( ( l ) => l.startsWith( '{' ) );
		return JSON.parse( String( linea ) );
	} finally {
		// Nunca se deja un fichero de pruebas servible en el docroot.
		rmSync( destino, { force: true } );
	}
}

test.describe( 'canje de puntos (cliente con sesión)', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip( soloLectura, 'siembra un cliente y escribe en su carrito: sólo contra el espejo' );

	/** @type {{nombre:string,valor:string,saldo:number}} */
	let sesion;
	/** @type {string} */
	let nonce;

	test.beforeAll( async () => {
		sesion = await sembrarSesion();
		expect( sesion.saldo, 'la siembra deja el saldo en 5.000' ).toBe( 5000 );
	} );

	test.beforeEach( async ( { context, page } ) => {
		await context.addCookies( [ { name: sesion.nombre, value: sesion.valor, domain: 'localhost', path: '/' } ] );
		const carrito = await page.request.get( '/wp-json/wc/store/v1/cart' );
		nonce = carrito.headers().nonce;
		await page.request.delete( '/wp-json/wc/store/v1/cart/items', { headers: { Nonce: nonce } } );
	} );

	/**
	 * Un tomo disponible dentro de un rango de precio, por la Store API.
	 *
	 * @param {import('@playwright/test').Page} page Página.
	 * @param {number} min Precio mínimo.
	 * @param {number} max Precio máximo.
	 */
	async function productoEntre( page, min, max ) {
		const r = await page.request.get( `/wp-json/wc/store/v1/products?stock_status=instock&per_page=20&min_price=${ min }&max_price=${ max }` );
		const lista = await r.json();
		return lista.find( ( p ) => p.is_purchasable && p.is_in_stock ) || null;
	}

	test( 'Mi cuenta dice el saldo con su equivalencia, y el campo de canje nace dentro de sus límites', async ( { page } ) => {
		await page.goto( '/mi-cuenta/', { waitUntil: 'domcontentloaded' } );
		await expect( page.locator( '.akb-dashboard__card', { hasText: 'Recompensas' } ) ).toContainText( /5\.000 puntos · \$5\.000/ );

		await page.goto( '/mi-cuenta/rewards/', { waitUntil: 'domcontentloaded' } );
		const campo = page.locator( 'input[name="akb_rw_points"]' );
		const { min, max, value } = await campo.evaluate( ( e ) => ( { min: Number( e.min ), max: Number( e.max ), value: Number( e.value ) } ) );

		// Con `value` bajo `min` el navegador bloqueaba el envío sin decir por qué.
		expect( value ).toBeGreaterThanOrEqual( min );
		expect( value ).toBeLessThanOrEqual( max );
		expect( max ).toBe( 5000 );
	} );

	test( 'el canje guardado en Mi cuenta llega al carrito y a la caja, y el total lo descuenta entero', async ( { page } ) => {
		await page.goto( '/mi-cuenta/rewards/', { waitUntil: 'domcontentloaded' } );
		await page.fill( 'input[name="akb_rw_points"]', '1000' );
		await page.getByRole( 'button', { name: /canjear en mi próxima compra/i } ).click();
		await expect( page.locator( '.woocommerce-message' ).first() ).toContainText( /1\.000 puntos \(\$1\.000 de descuento\)/ );

		const tomo = await productoEntre( page, 50000, 500000 );
		test.skip( ! tomo, 'el espejo no tiene un tomo disponible sobre $50.000' );
		const precio = Number( tomo.prices.price );

		const alta = await page.request.post( '/wp-json/wc/store/v1/cart/add-item', { headers: { Nonce: nonce }, data: { id: tomo.id, quantity: 1 } } );
		expect( alta.status() ).toBe( 201 );
		const carrito = await alta.json();

		const fee = ( carrito.fees || [] ).find( ( f ) => /puntos akibara/i.test( f.name ) );
		expect( fee, 'el descuento viaja como una línea propia del pedido' ).toBeTruthy();
		// El fee llega neto y su impuesto aparte: juntos son los $1.000 prometidos.
		expect( Math.abs( Number( fee.totals.total ) ) + Math.abs( Number( fee.totals.total_tax ) ) ).toBe( 1000 );
		expect( Number( carrito.totals.total_price ), 'el total es el precio menos los $1.000' ).toBe( precio - 1000 );

		for ( const ruta of [ '/carrito/', '/checkout/' ] ) {
			await page.goto( ruta, { waitUntil: 'domcontentloaded' } );
			await page.waitForLoadState( 'networkidle' );
			await expect( page.getByText( /Canje activo: 1\.000 puntos/ ).first(), `${ ruta } dice el canje activo` ).toBeVisible();
			// Lo pagado con puntos no vuelve a acumular: se gana sobre lo que se PAGA,
			// con la regla de `Calculator::points_for_base()`: redondeo estándar de
			// base ÷ $100 × 2 puntos ($56.990 → 1.139,8 → 1.140).
			const esperado = new Intl.NumberFormat( 'es-CL' ).format( Math.round( ( precio - 1000 ) / 100 * 2 ) );
			await expect( page.getByText( new RegExp( `Este pedido suma ${ esperado.replace( '.', '\\.' ) } puntos` ) ).first() ).toBeVisible();
		}
	} );

	test( 'canjear DESDE el carrito descuenta al instante, y «Quitar canje» devuelve el total entero', async ( { page } ) => {
		// Vaciar el carrito borra el canje guardado (regla del producto), y el
		// `beforeEach` lo vacía: aquí el canje se pide desde el propio carrito,
		// que es la otra puerta por la que entra el cliente.
		const tomo = await productoEntre( page, 50000, 500000 );
		test.skip( ! tomo, 'el espejo no tiene un tomo disponible sobre $50.000' );
		const precio = Number( tomo.prices.price );
		await page.request.post( '/wp-json/wc/store/v1/cart/add-item', { headers: { Nonce: nonce }, data: { id: tomo.id, quantity: 1 } } );

		await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );
		await page.waitForLoadState( 'networkidle' );
		await page.getByRole( 'spinbutton', { name: 'Puntos a canjear' } ).first().fill( '1000' );
		await page.getByRole( 'button', { name: 'Canjear', exact: true } ).first().click();
		await expect( page.getByText( /Canje activo: 1\.000 puntos/ ).first() ).toBeVisible( { timeout: 15_000 } );

		const conCanje = await ( await page.request.get( '/wp-json/wc/store/v1/cart' ) ).json();
		expect( Number( conCanje.totals.total_price ) ).toBe( precio - 1000 );

		await page.getByRole( 'button', { name: 'Quitar canje' } ).first().click();
		await expect( page.getByText( /Canje activo/ ) ).toHaveCount( 0, { timeout: 15_000 } );

		const sinCanje = await ( await page.request.get( '/wp-json/wc/store/v1/cart' ) ).json();
		expect( ( sinCanje.fees || [] ).filter( ( f ) => /puntos akibara/i.test( f.name ) ) ).toHaveLength( 0 );
		expect( Number( sinCanje.totals.total_price ) ).toBe( precio );
	} );

	test( 'con menos de $50.000 en productos el canje no descuenta nada, y la caja lo explica', async ( { page } ) => {
		await page.goto( '/mi-cuenta/rewards/', { waitUntil: 'domcontentloaded' } );
		await page.fill( 'input[name="akb_rw_points"]', '1000' );
		await page.getByRole( 'button', { name: /canjear en mi próxima compra/i } ).click();

		const tomo = await productoEntre( page, 5000, 30000 );
		test.skip( ! tomo, 'el espejo no tiene un tomo disponible bajo $30.000' );
		const alta = await page.request.post( '/wp-json/wc/store/v1/cart/add-item', { headers: { Nonce: nonce }, data: { id: tomo.id, quantity: 1 } } );
		const carrito = await alta.json();

		expect( ( carrito.fees || [] ).filter( ( f ) => /puntos akibara/i.test( f.name ) ), 'bajo el mínimo no hay descuento' ).toHaveLength( 0 );
		expect( Number( carrito.totals.total_price ) ).toBe( Number( tomo.prices.price ) );

		await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );
		await page.waitForLoadState( 'networkidle' );
		await expect( page.getByText( /compras desde \$50\.000 en productos/ ).first() ).toBeVisible();
	} );

	test.afterAll( async ( { browser } ) => {
		// Se deja como se encontró: carrito vacío y sin canje guardado.
		const ctx = await browser.newContext( { baseURL: process.env.AKB_BASE_URL || 'http://localhost:8080' } );
		await ctx.addCookies( [ { name: sesion.nombre, value: sesion.valor, domain: 'localhost', path: '/' } ] );
		const p = await ctx.newPage();
		const c = await p.request.get( '/wp-json/wc/store/v1/cart' );
		await p.request.delete( '/wp-json/wc/store/v1/cart/items', { headers: { Nonce: c.headers().nonce } } );
		await p.goto( '/mi-cuenta/rewards/', { waitUntil: 'domcontentloaded' } );
		const quitar = p.getByText( 'Quitar canje' ).first();
		if ( await quitar.isVisible().catch( () => false ) ) {
			await quitar.click();
		}
		await ctx.close();
	} );
} );
