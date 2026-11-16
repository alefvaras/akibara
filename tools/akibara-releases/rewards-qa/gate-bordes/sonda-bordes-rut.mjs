/**
 * sonda-bordes-rut.mjs — CASO 2: RUT en el checkout de bloques.
 *
 * Tres preguntas, tres capas:
 *   A) Al SALIR del campo (focusout), ¿sale un aviso VISIBLE con un dígito
 *      verificador malo, y NO sale con los dos formatos válidos?
 *      Se mide en móvil 390x844 y en escritorio 1440x900: el aviso vive en el
 *      store `wc/store/validation`, que no depende del ancho, pero el que se
 *      VEA sí (el error se pinta bajo el campo).
 *   B) Pulsando «Realizar el pedido» con RUT malo por la UI real, ¿se queda
 *      el cliente en el checkout sin pedido?
 *   C) El POST del Store API con RUT malo —saltándose la validación en
 *      cliente— NO puede crear pedido. Y con RUT sin puntos SÍ debe crearlo,
 *      guardándolo canonizado en `_billing_rut`.
 *
 * RUT usado: 12.345.678-5 (DV correcto, comprobado con RutValidator).
 * RUT malo:  12.345.678-9 (mismo cuerpo, DV equivocado).
 *
 * Restaura pedidos, borradores y stock. Salida != 0 si algún check falla.
 */

import {
	abrir, BASE, MOVIL, ESCRITORIO, agregarAlCarrito, vaciarCarrito, contarCarrito,
	contarPedidos, maxPedidoId, pedidosDesde, borrarPedido, borrarBorradores,
	enviarCheckout, prepararEnvio, crearMarcador, sqlUno, evalPhp,
	fotoStock, restaurarStock, leerErrorCampo,
	fotoOpcionesPudo, limpiarOpcionesPudo, opcionesPudoNuevas,
} from './lib-bordes.mjs';

const PRODUCTO_INSTOCK = 17083; // Parasyte 04 — instock, stock 4.
const RUT_OK_PUNTOS = '12.345.678-5';
const RUT_OK_SIN_PUNTOS = '12345678-5';
const RUT_MALO = '12.345.678-9';

const m = crearMarcador( 'bordes-rut' );
const idRef = maxPedidoId();
const pedidosAntes = contarPedidos();
const pudoAntes = fotoOpcionesPudo();
const stockAntes = fotoStock( [ PRODUCTO_INSTOCK ] );

/* --------------------------------------------------------------------------
 * Cordura del algoritmo antes de mirar la UI: si el validador no distinguiera
 * estos tres valores, cualquier check de la UI pasaría por casualidad.
 * ----------------------------------------------------------------------- */
const cordura = evalPhp(
	`echo implode( ',', array_map( static function ( $r ) { return ( \\Akibara\\Business\\Utilities\\RutValidator::is_valid( $r ) ? '1' : '0' ); }, array( '${ RUT_OK_PUNTOS }', '${ RUT_OK_SIN_PUNTOS }', '${ RUT_MALO }' ) ) );`
);
m.check(
	'php-validador-distingue-los-tres',
	cordura === '1,1,0',
	`is_valid(${ RUT_OK_PUNTOS }, ${ RUT_OK_SIN_PUNTOS }, ${ RUT_MALO }) = ${ cordura }`
);

async function correrUI( viewport, etiqueta ) {
	const { navegador, pagina } = await abrir( viewport );
	try {
		await vaciarCarrito( pagina );
		const carrito = await agregarAlCarrito( pagina, PRODUCTO_INSTOCK );
		m.check( `${ etiqueta }-carrito-listo`, carrito.lineas === 1, `lineas=${ carrito.lineas }` );

		await pagina.goto( `${ BASE }/checkout/`, { waitUntil: 'domcontentloaded' } );
		await pagina.waitForSelector( '#contact-akibara-rut', { timeout: 30000 } );
		await pagina.waitForTimeout( 2500 );

		const servido = await pagina.evaluate( () => typeof window.akbCheckoutRut === 'object' && window.akbCheckoutRut !== null );
		m.check( `${ etiqueta }-helper-rut-servido`, servido === true, `window.akbCheckoutRut = ${ servido }` );

		const leerError = () => leerErrorCampo( pagina, 'contact-akibara-rut' );

		const escribirYSalir = async ( valor ) => {
			await pagina.fill( '#contact-akibara-rut', '' );
			await pagina.waitForTimeout( 150 );
			await pagina.fill( '#contact-akibara-rut', valor );
			// Salir con Tab, no clickeando otro campo: el <label for="email">
			// se come el click y Playwright se queda esperando 30 s.
			await pagina.locator( '#contact-akibara-rut' ).press( 'Tab' );
			await pagina.waitForTimeout( 900 );
			return leerError();
		};

		// El orden importa: primero el malo, para que los válidos tengan que
		// LIMPIAR un error ya puesto. Si sólo se probaran en frío, el check
		// «sin aviso» pasaría por no haber avisado nunca — un check que no
		// puede fallar.
		const malo = await escribirYSalir( RUT_MALO );
		m.check(
			`${ etiqueta }-rut-dv-malo-avisa-visible`,
			malo.texto !== '' && malo.visible === true && /d[ií]gito verificador/i.test( malo.texto ),
			`valor=${ RUT_MALO } aria-invalid=${ malo.ariaInvalid } has-error=${ malo.conClaseError } visible=${ malo.visible } mensaje="${ malo.texto }"`
		);

		const conPuntos = await escribirYSalir( RUT_OK_PUNTOS );
		m.check(
			`${ etiqueta }-rut-con-puntos-limpia-el-aviso`,
			conPuntos.texto === '' && conPuntos.ariaInvalid === 'false',
			`valor=${ RUT_OK_PUNTOS } aria-invalid=${ conPuntos.ariaInvalid } has-error=${ conPuntos.conClaseError } mensaje="${ conPuntos.texto }"`
		);

		await escribirYSalir( RUT_MALO );
		const sinPuntos = await escribirYSalir( RUT_OK_SIN_PUNTOS );
		m.check(
			`${ etiqueta }-rut-sin-puntos-limpia-el-aviso`,
			sinPuntos.texto === '' && sinPuntos.ariaInvalid === 'false',
			`valor=${ RUT_OK_SIN_PUNTOS } aria-invalid=${ sinPuntos.ariaInvalid } has-error=${ sinPuntos.conClaseError } mensaje="${ sinPuntos.texto }"`
		);

		return { navegador, pagina };
	} catch ( error ) {
		await navegador.close();
		throw error;
	}
}

let sesion = null;
try {
	// ---- A) móvil ----
	sesion = await correrUI( MOVIL, 'movil' );
	await sesion.navegador.close();
	sesion = null;

	// ---- A) escritorio + B) envío real por la UI ----
	sesion = await correrUI( ESCRITORIO, 'escritorio' );
	const { pagina } = sesion;

	// El campo quedó con el RUT malo. Se completa el resto y se pulsa el
	// botón REAL. El pedido no puede nacer y el cliente no puede salir del
	// checkout.
	await pagina.fill( '#email', 'qa.bordes.rut@example.test' );
	await pagina.fill( '#billing-first_name', 'QA' );
	await pagina.fill( '#billing-last_name', 'Rut' );
	await pagina.fill( '#billing-address_1', 'Av Siempre Viva 742' );
	await pagina.selectOption( '#billing-state', 'CL-RM' );
	await pagina.waitForTimeout( 800 );
	await pagina.fill( '#billing-city', 'Providencia' );
	await pagina.keyboard.press( 'Escape' );
	await pagina.waitForTimeout( 400 );
	await pagina.fill( '#billing-phone', '987654321' );
	await pagina.fill( '#contact-akibara-rut', RUT_MALO );
	await pagina.locator( '#contact-akibara-rut' ).press( 'Tab' );
	await pagina.waitForTimeout( 1500 );
	// El `.catch( () => {} )` que había aquí se tragaba el fallo de marcar el
	// método de pago: si el radio no llegaba a marcarse, «pulsar Realizar el
	// pedido no crea pedido» pasaba igual — pero por FALTA DE MÉTODO, no por el
	// RUT malo, que es lo único que esta sonda dice medir. Se asierta, con el
	// mismo patrón que 'transferencia-elegida' en sonda-bordes-doble-click.mjs.
	await pagina.check( '#radio-control-wc-payment-method-options-bacs' );
	await pagina.waitForTimeout( 2500 );
	const metodoOk = await pagina.evaluate(
		() => ( document.getElementById( 'radio-control-wc-payment-method-options-bacs' ) || {} ).checked
	);
	m.check( 'ui-transferencia-elegida', metodoOk === true, `radio bacs checked=${ metodoOk }` );

	const antesUI = contarPedidos();
	await pagina.locator( 'button.wc-block-components-checkout-place-order-button' ).click();
	await pagina.waitForTimeout( 8000 );
	const despuesUI = contarPedidos();
	const urlTrasEnviar = pagina.url();
	const avisoTrasEnviar = await pagina.evaluate( () => {
		const nodos = Array.from(
			document.querySelectorAll( '.wc-block-components-validation-error, .wc-block-components-notice-banner' )
		);
		return nodos
			.filter( ( n ) => n.offsetWidth || n.offsetHeight || n.getClientRects().length )
			.map( ( n ) => ( n.textContent || '' ).trim() )
			.join( ' | ' );
	} );

	m.check(
		'ui-enviar-con-rut-malo-no-crea-pedido',
		despuesUI === antesUI,
		`pedidos ${ antesUI } -> ${ despuesUI }`
	);
	m.check(
		'ui-enviar-con-rut-malo-se-queda-en-checkout',
		/\/checkout\/?$/.test( urlTrasEnviar ),
		`url tras pulsar = ${ urlTrasEnviar }`
	);
	m.check(
		'ui-enviar-con-rut-malo-muestra-aviso',
		/d[ií]gito verificador/i.test( avisoTrasEnviar ),
		`avisos visibles = "${ avisoTrasEnviar }"`
	);

	/* ------------------------------------------------------------------
	 * C) CAPA SERVIDOR — el POST directo, sin la validación en cliente.
	 * --------------------------------------------------------------- */
	{
		const c = await contarCarrito( pagina );
		if ( c.lineas === 0 ) {
			await agregarAlCarrito( pagina, PRODUCTO_INSTOCK );
		}
		await prepararEnvio( pagina );
		const antes = contarPedidos();
		const res = await enviarCheckout( pagina, { rut: RUT_MALO } );
		const despues = contarPedidos();
		m.check(
			'api-rut-malo-rechaza',
			res.status >= 400 && res.orderId === 0,
			`HTTP ${ res.status } code=${ res.code } order_id=${ res.orderId } msg="${ res.message }" detalles=${ res.detalles }`
		);
		m.check( 'api-rut-malo-no-crea-pedido', despues === antes, `pedidos ${ antes } -> ${ despues }` );
	}

	// C2 — RUT sin puntos: acepta y guarda canonizado.
	{
		const c = await contarCarrito( pagina );
		if ( c.lineas === 0 ) {
			await agregarAlCarrito( pagina, PRODUCTO_INSTOCK );
		}
		await prepararEnvio( pagina );
		const antes = contarPedidos();
		const res = await enviarCheckout( pagina, { rut: RUT_OK_SIN_PUNTOS } );
		const despues = contarPedidos();
		m.check(
			'api-rut-sin-puntos-acepta',
			res.status === 200 && res.orderId > 0,
			`HTTP ${ res.status } order_id=${ res.orderId } msg="${ res.message }"`
		);
		m.check( 'api-rut-sin-puntos-crea-un-pedido', despues === antes + 1, `pedidos ${ antes } -> ${ despues }` );

		if ( res.orderId > 0 ) {
			const guardado = sqlUno(
				`SELECT meta_value FROM wp_wc_orders_meta WHERE order_id=${ res.orderId } AND meta_key='_billing_rut'`
			);
			m.check(
				'api-rut-sin-puntos-se-guarda-canonizado',
				guardado === RUT_OK_PUNTOS,
				`enviado="${ RUT_OK_SIN_PUNTOS }" guardado en _billing_rut="${ guardado }"`
			);
		}
	}
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, String( error && error.message ? error.message : error ) );
} finally {
	if ( sesion ) {
		await sesion.navegador.close();
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
