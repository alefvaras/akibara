// @ts-check
/**
 * La compra de un invitado, hasta la puerta del pago.
 *
 * Es el flujo que más clientes hacen y el que menos se miraba entero: agregar,
 * cambiar cantidades, probar un cupón malo, y llegar a una caja que pida lo que
 * tiene que pedir. Aquí NO se paga ni se escribe ningún RUT ni dato de pago: se
 * comprueba que la caja esté completa y que un envío a medias se detenga solo.
 *
 * Todo escribe en el carrito, así que corre sólo contra el espejo.
 */

import { expect, test } from '@playwright/test';
import { productoConBadge, soloLectura } from './soporte/tienda.mjs';

test.describe( 'compra de invitado', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip( soloLectura, 'escribe en el carrito: sólo contra el espejo' );

	/**
	 * Un tomo disponible que admita al menos dos unidades: con una sola el «+» del
	 * carrito queda deshabilitado y subir la cantidad no se puede medir (medido en
	 * el espejo el 2026-09-28: el primer «Disponible» tenía 1 unidad).
	 *
	 * @param {import('@playwright/test').Page} page
	 */
	async function tomoQueAdmiteDos( page ) {
		const lista = await ( await page.request.get( '/wp-json/wc/store/v1/products?stock_status=instock&type=simple&per_page=50' ) ).json();
		const tomo = lista.find( ( p ) => p.is_purchasable && p.is_in_stock && ( p.add_to_cart?.maximum ?? 0 ) >= 2 );
		return tomo ? tomo.permalink : null;
	}

	/**
	 * @param {import('@playwright/test').Page} page
	 * @param {{ admiteDos?: boolean }} [opciones]
	 */
	async function conUnTomoEnElCarrito( page, { admiteDos = false } = {} ) {
		const url = admiteDos ? await tomoQueAdmiteDos( page ) : await productoConBadge( page, 'Disponible' );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.goto( String( url ), { waitUntil: 'domcontentloaded' } );
		const boton = page.locator( '.single_add_to_cart_button' ).first();
		await boton.click();
		await expect( boton ).toHaveClass( /is-added/, { timeout: 20_000 } );
	}

	test( 'subir la cantidad sube el total, y quitar el tomo deja el carrito vacío y explicado', async ( { page, context } ) => {
		await context.clearCookies();
		await conUnTomoEnElCarrito( page, { admiteDos: true } );
		await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );
		await page.waitForLoadState( 'networkidle' );

		const total = page.locator( '.wc-block-components-totals-footer-item .wc-block-components-totals-item__value' ).first();
		await expect( total ).toBeVisible();
		const antes = await total.innerText();

		await page.locator( '.wc-block-components-quantity-selector__button--plus' ).first().click();
		await expect( total, 'con dos tomos el total no puede ser el de uno' ).not.toHaveText( antes, { timeout: 15_000 } );

		await page.locator( '.wc-block-cart-item__remove-link' ).first().click();
		await expect( page.locator( '.wc-block-cart__empty-cart__title, .akb-empty__title, .wp-block-woocommerce-empty-cart-block' ).first() ).toBeVisible( { timeout: 15_000 } );
	} );

	test( 'un cupón que no existe lo dice, y el total no cambia', async ( { page, context } ) => {
		await context.clearCookies();
		await conUnTomoEnElCarrito( page );
		await page.goto( '/carrito/', { waitUntil: 'domcontentloaded' } );
		await page.waitForLoadState( 'networkidle' );

		const total = page.locator( '.wc-block-components-totals-footer-item .wc-block-components-totals-item__value' ).first();
		const antes = await total.innerText();

		const panel = page.locator( '.wc-block-components-totals-coupon' ).first();
		const campo = panel.locator( 'input' ).first();
		if ( ! await campo.isVisible().catch( () => false ) ) {
			await panel.locator( 'button, [role="button"]' ).first().click();
		}
		await campo.fill( 'CUPON-QUE-NO-EXISTE-QA' );
		await panel.locator( 'button[type="submit"], .wc-block-components-totals-coupon__button' ).first().click();

		await expect( page.locator( '.wc-block-components-validation-error, .wc-block-components-notice-banner.is-error' ).first(), 'un cupón malo se dice, no se traga' ).toBeVisible( { timeout: 15_000 } );
		await expect( total ).toHaveText( antes );
	} );

	test( 'la caja pide lo chileno —RUT, región, comuna— y enseña el pedido antes de cobrar', async ( { page, context } ) => {
		await context.clearCookies();
		await conUnTomoEnElCarrito( page );
		const r = await page.goto( '/checkout/', { waitUntil: 'domcontentloaded' } );
		expect( r?.status() ).toBe( 200 );
		await page.waitForLoadState( 'networkidle' );

		await expect( page.locator( 'h1' ).first() ).toBeVisible();
		await expect( page.locator( 'input[type="email"]' ).first(), 'el correo del invitado' ).toBeVisible();
		await expect( page.locator( 'input[id*="akibara-rut"], input[id*="akibara/rut"]' ).first(), 'el campo RUT existe (no se escribe nada en él)' ).toBeVisible();
		await expect( page.locator( '[id*="state"]' ).first(), 'la región' ).toBeVisible();
		await expect( page.locator( '.wc-block-components-order-summary, .wc-block-components-order-summary-item' ).first(), 'el resumen del pedido' ).toBeVisible();
		await expect( page.locator( '.wc-block-components-checkout-place-order-button' ).first(), 'el botón de pagar' ).toBeVisible();

		const cuerpo = await page.locator( 'body' ).innerText();
		expect( cuerpo, 'ninguna clave de traducción ni texto en inglés de fábrica' ).not.toMatch( /Place order|Billing address|Shipping address|Contact information/ );
	} );

	test( 'pagar con la caja vacía se detiene y señala lo que falta, sin crear nada', async ( { page, context } ) => {
		await context.clearCookies();
		await conUnTomoEnElCarrito( page );
		await page.goto( '/checkout/', { waitUntil: 'domcontentloaded' } );
		await page.waitForLoadState( 'networkidle' );

		let pedidoEnviado = false;
		page.on( 'request', ( req ) => {
			if ( 'POST' === req.method() && /\/wc\/store\/v1\/checkout(\?|$)/.test( req.url() ) ) {
				pedidoEnviado = true;
			}
		} );

		await page.locator( '.wc-block-components-checkout-place-order-button' ).first().click();

		await expect( page.locator( '.wc-block-components-validation-error' ).first(), 'los campos vacíos se señalan' ).toBeVisible( { timeout: 15_000 } );
		expect( pedidoEnviado, 'una caja incompleta no llega al servidor' ).toBe( false );
		expect( page.url() ).toContain( '/checkout' );
	} );

	/**
	 * R6-cantidad-sin-techo (2026-09-19): la ficha aceptaba 999999 unidades
	 * de un tomo sin stock gestionado, el cajón y el ícono mostraban 999.999 y
	 * un total de diez mil millones, y /carrito/ lo recortaba a 9.999 sin
	 * avisar. El techo es el de WooCommerce (Store API): 9.999 por línea.
	 */
	test( 'la ficha no deja pedir más de 9.999 unidades, ni por el campo ni por el servidor', async ( { page, context } ) => {
		await context.clearCookies();
		// Con 1 unidad WooCommerce oculta el campo cantidad: hace falta un tomo que admita dos.
		const url = await tomoQueAdmiteDos( page );
		test.skip( ! url, 'sin tomos disponibles en este entorno' );
		await page.goto( String( url ), { waitUntil: 'domcontentloaded' } );

		const cantidad = page.locator( 'form.cart input.qty, form.cart input[name="quantity"]' ).first();
		await expect( cantidad, 'el campo cantidad existe' ).toBeVisible();
		const max = Number( await cantidad.getAttribute( 'max' ) );
		expect( max, 'el campo lleva un techo' ).toBeGreaterThan( 0 );
		expect( max, 'nunca por encima del techo del Store API' ).toBeLessThanOrEqual( 9999 );

		const id = await page.locator( '[name="add-to-cart"], button.single_add_to_cart_button[value]' ).first().getAttribute( 'value' );
		const respuesta = await page.evaluate( async ( producto ) => {
			const cuerpo = new URLSearchParams( { product_id: String( producto ), quantity: '999999' } );
			const r = await fetch( '/?wc-ajax=add_to_cart', { method: 'POST', body: cuerpo, credentials: 'same-origin' } );
			return r.json();
		}, id );
		expect( respuesta.error, 'el servidor rechaza 999999 unidades' ).toBe( true );

		// Y lo que hay en el carrito después sigue siendo nada.
		const carrito = await page.request.get( '/wp-json/wc/store/v1/cart' );
		expect( ( await carrito.json() ).items_count ).toBe( 0 );
	} );
} );
