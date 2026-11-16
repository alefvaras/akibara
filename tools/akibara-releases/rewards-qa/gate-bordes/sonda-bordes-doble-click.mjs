/**
 * sonda-bordes-doble-click.mjs — CASO 3: doble click en «Realizar el pedido».
 *
 * Carrito real + checkout con transferencia (bacs). Se pulsa DOS veces
 * seguidas y tiene que nacer UN pedido, no dos. El conteo se hace sobre
 * `wp_wc_orders` (excluyendo borradores) antes y después, y además se listan
 * los pedidos nuevos por id: «uno» se demuestra enseñando el id, no fiándose
 * de una resta.
 *
 * Dos variantes, porque una sola no es una carrera:
 *
 *   V1 «dedo rápido»: dos `click()` SÍNCRONOS sobre el botón en el mismo tick
 *       de JS. El segundo corre antes de que React re-renderice el botón
 *       deshabilitado. Es el peor caso del lado del navegador.
 *
 *   V2 «conexión lenta»: el POST del checkout se retrasa 3 s con
 *       `page.route`, y se pulsa dos veces separadas 400 ms — el escenario
 *       real del cliente que no ve respuesta y vuelve a pulsar. Sin el
 *       retraso, la primera respuesta ya volvió y no hay carrera que medir.
 *
 * Se cuentan también los POST que SALEN al Store API: si salieran dos y el
 * servidor sólo creara uno, hay que saberlo (lo resuelve Woo por idempotencia
 * del borrador, no el botón), y si sale uno solo, el botón hizo su trabajo.
 *
 * Restaura pedidos, borradores y stock. Salida != 0 si algún check falla.
 */

import {
	abrir, BASE, MOVIL, ESCRITORIO, agregarAlCarrito, vaciarCarrito,
	contarPedidos, maxPedidoId, pedidosDesde, pedidosRealesDesde, borrarPedido,
	borrarBorradores, crearMarcador, fotoStock, restaurarStock, DORMIR,
	fotoOpcionesPudo, limpiarOpcionesPudo, opcionesPudoNuevas,
} from './lib-bordes.mjs';

const PRODUCTO_INSTOCK = 17083; // Parasyte 04 — instock, stock 4.
const m = crearMarcador( 'bordes-doble-click' );
const idRef = maxPedidoId();
const pedidosAntes = contarPedidos();
const pudoAntes = fotoOpcionesPudo();
const stockAntes = fotoStock( [ PRODUCTO_INSTOCK ] );

/** Rellena el checkout entero y lo deja listo para pulsar. */
async function prepararCheckout( pagina, email ) {
	await pagina.goto( `${ BASE }/checkout/`, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForSelector( '#billing-city', { timeout: 30000 } );
	await pagina.waitForTimeout( 2500 );

	await pagina.fill( '#email', email );
	await pagina.fill( '#contact-akibara-rut', '12.345.678-5' );
	await pagina.locator( '#contact-akibara-rut' ).press( 'Tab' );
	await pagina.fill( '#billing-first_name', 'QA' );
	await pagina.fill( '#billing-last_name', 'DobleClick' );
	await pagina.fill( '#billing-address_1', 'Av Siempre Viva 742' );
	await pagina.selectOption( '#billing-state', 'CL-RM' );
	await pagina.waitForTimeout( 900 );
	await pagina.fill( '#billing-city', 'Providencia' );
	await pagina.keyboard.press( 'Escape' );
	await pagina.waitForTimeout( 400 );
	await pagina.fill( '#billing-phone', '987654321' );
	await pagina.waitForTimeout( 2500 );

	await pagina.check( '#radio-control-wc-payment-method-options-bacs' );
	await pagina.waitForTimeout( 2500 );

	const metodo = await pagina.evaluate(
		() => ( document.getElementById( 'radio-control-wc-payment-method-options-bacs' ) || {} ).checked
	);
	return metodo === true;
}

let navegadorActual = null;

async function variante( etiqueta, viewport, email, retrasoMs, disparar ) {
	const { navegador, pagina } = await abrir( viewport );
	navegadorActual = navegador;
	const posts = [];
	try {
		await vaciarCarrito( pagina );
		const carrito = await agregarAlCarrito( pagina, PRODUCTO_INSTOCK );
		m.check( `${ etiqueta }-carrito-real`, carrito.lineas === 1, `lineas=${ carrito.lineas } total=${ carrito.total }` );

		// Contador de POST reales al endpoint de checkout (el PUT del borrador
		// lleva x-http-method-override y NO cuenta como intento de pago).
		await pagina.route( '**/wp-json/wc/store/v1/checkout**', async ( route ) => {
			const req = route.request();
			const cabeceras = await req.allHeaders();
			const esPago = req.method() === 'POST' && ! cabeceras[ 'x-http-method-override' ];
			if ( esPago ) {
				posts.push( Date.now() );
				if ( retrasoMs > 0 ) {
					await DORMIR( retrasoMs );
				}
			}
			await route.continue();
		} );

		const metodoOk = await prepararCheckout( pagina, email );
		m.check( `${ etiqueta }-transferencia-elegida`, metodoOk, `radio bacs checked=${ metodoOk }` );

		const idAntes = maxPedidoId();
		const cuentaAntes = contarPedidos();

		await disparar( pagina );

		// Espera generosa: con el retraso inyectado la respuesta tarda.
		await pagina.waitForTimeout( retrasoMs + 12000 );

		const cuentaDespues = contarPedidos();
		const nuevos = pedidosRealesDesde( idAntes );

		m.check(
			`${ etiqueta }-nace-exactamente-un-pedido`,
			cuentaDespues === cuentaAntes + 1 && nuevos.length === 1,
			`pedidos ${ cuentaAntes } -> ${ cuentaDespues }; nuevos=[${ nuevos
				.map( ( p ) => `${ p.id }:${ p.status }:${ p.total }` )
				.join( ' , ' ) }]`
		);

		m.info( `${ etiqueta }-posts-al-store-api`, `${ posts.length } POST de pago (deltas ms: ${ posts
			.map( ( t, i ) => ( i ? t - posts[ i - 1 ] : 0 ) )
			.join( ',' ) })` );

		const url = pagina.url();
		m.check(
			`${ etiqueta }-termina-en-pedido-recibido`,
			/pedido-recibido|order-received/.test( url ),
			`url final = ${ url }`
		);

		// Un pedido duplicado se vería también como dos filas con el mismo
		// email: se comprueba explícitamente, no sólo por el conteo global.
		const mismoEmail = nuevos.filter( ( p ) => p.email === email );
		m.check(
			`${ etiqueta }-sin-duplicado-por-email`,
			mismoEmail.length === 1,
			`pedidos con billing_email=${ email }: ${ mismoEmail.length } -> [${ mismoEmail.map( ( p ) => p.id ).join( ',' ) }]`
		);
	} finally {
		await navegador.close();
		navegadorActual = null;
	}
}

try {
	// --- V1: dos clicks síncronos, escritorio 1440x900 ---
	await variante( 'v1-sincrono', ESCRITORIO, 'qa.doble1@example.test', 0, async ( pagina ) => {
		const disparos = await pagina.evaluate( () => {
			const boton = document.querySelector( 'button.wc-block-components-checkout-place-order-button' );
			if ( ! boton ) {
				return { encontrado: false, deshabilitadoAntes: null, deshabilitadoEntre: null };
			}
			const deshabilitadoAntes = boton.disabled || boton.getAttribute( 'aria-disabled' ) === 'true';
			boton.click();
			const deshabilitadoEntre = boton.disabled || boton.getAttribute( 'aria-disabled' ) === 'true';
			boton.click();
			return { encontrado: true, deshabilitadoAntes, deshabilitadoEntre };
		} );
		m.check(
			'v1-sincrono-boton-encontrado-y-habilitado',
			disparos.encontrado === true && disparos.deshabilitadoAntes === false,
			`encontrado=${ disparos.encontrado } deshabilitado-antes=${ disparos.deshabilitadoAntes } deshabilitado-entre-clicks=${ disparos.deshabilitadoEntre }`
		);
	} );

	// --- V2: dos clicks reales con el POST retrasado 3 s, móvil 390x844 ---
	await variante( 'v2-lento-movil', MOVIL, 'qa.doble2@example.test', 3000, async ( pagina ) => {
		const boton = pagina.locator( 'button.wc-block-components-checkout-place-order-button' );
		await boton.click();
		await pagina.waitForTimeout( 400 );
		const estado = await pagina.evaluate( () => {
			const b = document.querySelector( 'button.wc-block-components-checkout-place-order-button' );
			return b ? { disabled: b.disabled, aria: b.getAttribute( 'aria-disabled' ), texto: ( b.textContent || '' ).trim() } : null;
		} );
		m.info(
			'v2-lento-movil-estado-boton-tras-primer-click',
			`disabled=${ estado && estado.disabled } aria-disabled=${ estado && estado.aria } texto="${ estado && estado.texto }"`
		);
		// Segundo click "a la fuerza": aunque React lo haya deshabilitado, se
		// dispara el evento igual. Si el guard vive sólo en el atributo, aquí
		// se cae; si vive en el estado del checkout, aguanta.
		await pagina.evaluate( () => {
			const b = document.querySelector( 'button.wc-block-components-checkout-place-order-button' );
			if ( b ) {
				b.click();
				b.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
			}
		} );
	} );
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, String( error && error.message ? error.message : error ) );
} finally {
	if ( navegadorActual ) {
		await navegadorActual.close();
	}

	// ---- RESTAURACIÓN ----
	const sobrantes = pedidosDesde( idRef );
	sobrantes.forEach( ( p ) => borrarPedido( p.id ) );
	borrarBorradores( idRef );
	const pedidosDespues = contarPedidos();
	m.check(
		'restauracion-pedidos-antes-igual-despues',
		pedidosDespues === pedidosAntes,
		`antes=${ pedidosAntes } despues=${ pedidosDespues } borrados=[${ sobrantes.map( ( p ) => p.id ).join( ',' ) }]`
	);

	const arreglos = restaurarStock( stockAntes );
	const stockDespues = fotoStock( [ PRODUCTO_INSTOCK ] );
	m.check(
		'restauracion-stock-antes-igual-despues',
		JSON.stringify( stockDespues ) === JSON.stringify( stockAntes ),
		`antes=${ JSON.stringify( stockAntes ) } despues=${ JSON.stringify( stockDespues ) } reparado=[${ arreglos.join( '; ' ) }]`
	);

	// Lo que se exige es NO DEJAR filas nuevas. Comparar el CONTEO contra el
	// de antes daba rojo cuando una fila PREVIA vencía sola durante la sonda
	// (TTL de 1 h): una expiración ajena no es un residuo de esta corrida.
	const pudoNuevas = limpiarOpcionesPudo( pudoAntes );
	const pudoResidual = opcionesPudoNuevas( pudoAntes );
	m.check(
		'restauracion-sin-filas-akb-pudo-state-nuevas',
		pudoResidual.length === 0,
		`akb_pudo_state antes=${ pudoAntes.length } creadas=${ pudoNuevas.length } [${ pudoNuevas.join( ',' ) }] residuales=${ pudoResidual.length } [${ pudoResidual.join( ',' ) }] despues=${ fotoOpcionesPudo().length }`
	);
}

process.exit( m.cerrar() > 0 ? 1 : 0 );
