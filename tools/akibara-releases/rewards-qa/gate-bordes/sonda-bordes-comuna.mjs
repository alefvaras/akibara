/**
 * sonda-bordes-comuna.mjs — CASO 1: COMUNA HOSTIL en el checkout.
 *
 * Mide DOS capas, porque cada una puede mentir por separado:
 *
 *   A) CLIENTE (móvil 390x844, DOM servido): al salir del campo comuna, ¿sale
 *      un mensaje VISIBLE anclado al campo, o el checkout traga en silencio?
 *   B) SERVIDOR (Store API, el POST de verdad capturado del formulario): ¿el
 *      pedido NACE o no? Un aviso bonito que igual crea el pedido no sirve.
 *
 * Valores probados:
 *   - «San Miguel» (espacio duro NBSP): DEBE aceptarse y canonizarse a
 *     «San Miguel» con espacio normal. Es la trampa de paridad PHP/JS.
 *   - «santiago» en minúsculas: DEBE aceptarse y canonizarse a «Santiago».
 *   - «santiago centro» en minúsculas: no existe → DEBE rechazar.
 *   - «Renaca» con región Valparaíso: NO puede proponer «Renca» (Santiago).
 *   - «Xyzlandia»: DEBE rechazar con mensaje visible.
 *
 * Restaura: borra todo pedido/borrador creado por encima del id de referencia.
 *
 * Salida != 0 si algún check falla.
 */

import {
	abrir, BASE, MOVIL, agregarAlCarrito, vaciarCarrito, contarCarrito,
	contarPedidos, maxPedidoId, pedidosDesde, borrarPedido, borrarBorradores,
	enviarCheckout, prepararEnvio, crearMarcador, sqlUno, evalPhp,
	fotoStock, restaurarStock, leerErrorCampo,
	fotoOpcionesPudo, limpiarOpcionesPudo, opcionesPudoNuevas,
} from './lib-bordes.mjs';

const PRODUCTO_INSTOCK = 17083; // Parasyte 04 — instock, stock 4, $15.500.
const NBSP = ' ';
const m = crearMarcador( 'bordes-comuna' );

const idRef = maxPedidoId();
const pedidosAntes = contarPedidos();
const pudoAntes = fotoOpcionesPudo();
const stockAntes = fotoStock( [ PRODUCTO_INSTOCK ] );
const creados = [];

const { navegador, pagina } = await abrir( MOVIL );

async function irAlCheckout() {
	await pagina.goto( `${ BASE }/checkout/`, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForSelector( '#billing-city', { timeout: 30000 } );
	await pagina.waitForTimeout( 2500 );
}

/** Rellena el mínimo para que el campo comuna sea evaluable y sale de él. */
async function escribirComuna( region, valor ) {
	await pagina.selectOption( '#billing-state', region );
	await pagina.waitForTimeout( 800 );
	await pagina.fill( '#billing-city', '' );
	await pagina.waitForTimeout( 200 );
	// `fill` no dispara la secuencia de teclado del combobox; se escribe el
	// valor y se sale con Escape+blur, que es lo que hace una persona.
	await pagina.fill( '#billing-city', valor );
	await pagina.waitForTimeout( 500 );
	await pagina.keyboard.press( 'Escape' );
	await pagina.locator( '#billing-address_1' ).click();
	await pagina.waitForTimeout( 1200 );
}

/** Lee el mensaje de error VISIBLE anclado al campo comuna. */
async function errorComunaVisible() {
	return leerErrorCampo( pagina, 'billing-city' );
}

try {
	/* ------------------------------------------------------------------
	 * A) CAPA CLIENTE — móvil 390x844 sobre el DOM servido.
	 * --------------------------------------------------------------- */
	await vaciarCarrito( pagina );
	const carrito = await agregarAlCarrito( pagina, PRODUCTO_INSTOCK );
	m.check( 'carrito-listo', carrito.lineas === 1, `lineas=${ carrito.lineas } total=${ carrito.total }` );

	await irAlCheckout();
	const estadoInicial = await errorComunaVisible();
	m.check(
		'combobox-comuna-servido',
		estadoInicial.mejorada === true,
		`data-akb-comuna-enhanced=${ estadoInicial.mejorada }`
	);

	// A1 — NBSP. Tiene que pasar SIN aviso: el gemelo JS colapsa el espacio duro.
	await escribirComuna( 'CL-RM', `San${ NBSP }Miguel` );
	const nbsp = await errorComunaVisible();
	m.check(
		'ui-nbsp-san-miguel-sin-aviso',
		nbsp.texto === '',
		`valor="San<NBSP>Miguel" aria-invalid=${ nbsp.ariaInvalid } mensaje="${ nbsp.texto }"`
	);

	// A2 — minúsculas exactas: «santiago» existe, no puede avisar.
	await escribirComuna( 'CL-RM', 'santiago' );
	const minus = await errorComunaVisible();
	m.check(
		'ui-minusculas-santiago-sin-aviso',
		minus.texto === '',
		`valor="santiago" aria-invalid=${ minus.ariaInvalid } mensaje="${ minus.texto }"`
	);

	// A3 — «santiago centro»: no es comuna. Debe avisar y verse.
	await escribirComuna( 'CL-RM', 'santiago centro' );
	const centro = await errorComunaVisible();
	m.check(
		'ui-santiago-centro-avisa-visible',
		centro.texto !== '' && centro.visible === true,
		`mensaje="${ centro.texto }" visible=${ centro.visible } aria-invalid=${ centro.ariaInvalid }`
	);

	// A4 — «Renaca» en Valparaíso: avisa, pero JAMÁS proponiendo «Renca».
	await escribirComuna( 'CL-VS', 'Renaca' );
	const renaca = await errorComunaVisible();
	m.check(
		'ui-renaca-avisa-visible',
		renaca.texto !== '' && renaca.visible === true,
		`mensaje="${ renaca.texto }" visible=${ renaca.visible }`
	);
	m.check(
		'ui-renaca-no-autocorrige-a-renca',
		! /renca/i.test( renaca.texto ),
		`mensaje="${ renaca.texto }" contiene-renca=${ /renca/i.test( renaca.texto ) }`
	);

	// A5 — comuna inexistente.
	await escribirComuna( 'CL-RM', 'Xyzlandia' );
	const xyz = await errorComunaVisible();
	m.check(
		'ui-xyzlandia-avisa-visible',
		xyz.texto !== '' && xyz.visible === true,
		`mensaje="${ xyz.texto }" visible=${ xyz.visible }`
	);

	/* ------------------------------------------------------------------
	 * B) CAPA SERVIDOR — el POST real. ¿Nace pedido o no?
	 * --------------------------------------------------------------- */
	const hostiles = [
		{ id: 'santiago-centro', city: 'santiago centro', state: 'CL-RM' },
		{ id: 'renaca-vs', city: 'Renaca', state: 'CL-VS' },
		{ id: 'xyzlandia', city: 'Xyzlandia', state: 'CL-RM' },
	];

	for ( const caso of hostiles ) {
		const antes = contarPedidos();
		const c = await contarCarrito( pagina );
		if ( c.lineas === 0 ) {
			await agregarAlCarrito( pagina, PRODUCTO_INSTOCK );
		}
		const envio = await prepararEnvio( pagina, { city: caso.city, state: caso.state } );
		m.info( `api-${ caso.id }-envio`, `tarifas=${ envio.tarifas } elegida=${ envio.elegida }` );
		const res = await enviarCheckout( pagina, {
			direccion: { city: caso.city, state: caso.state },
		} );
		const despues = contarPedidos();
		m.check(
			`api-${ caso.id }-rechaza`,
			res.status >= 400 && res.orderId === 0,
			`HTTP ${ res.status } code=${ res.code } order_id=${ res.orderId } msg="${ res.message }"`
		);
		m.check(
			`api-${ caso.id }-no-crea-pedido`,
			despues === antes,
			`pedidos ${ antes } -> ${ despues }`
		);
		if ( caso.id === 'renaca-vs' ) {
			m.check(
				'api-renaca-no-autocorrige-a-renca',
				! /renca/i.test( res.message + res.detalles ),
				`msg="${ res.message }" detalles="${ res.detalles }"`
			);
		}
	}

	/* ------------------------------------------------------------------
	 * B-bis) «Renaca» en el SERVIDOR, sin pasar por el Store API.
	 *
	 * En el espejo NINGUNA zona de envío cubre Valparaíso (medido arriba:
	 * tarifas=0), así que el POST muere antes en «necesita una opción de
	 * envío» y el rechazo por comuna no llega a evaluarse. La trampa
	 * documentada —proponer «Renca», de Santiago— vive en
	 * ComunaDirectory::suggest(), y ahí sí se puede interrogar directamente.
	 * Se prueba CON región (el caso real del checkout, donde el select es
	 * obligatorio) y SIN región (el peor caso).
	 * --------------------------------------------------------------- */
	{
		const sugVS = evalPhp(
			"$d = new \\Akibara\\CartCheckoutBlocks\\ComunaDirectory(); echo '[' . (string) $d->suggest( 'Renaca', 'CL-VS' ) . ']';"
		);
		m.check(
			'php-suggest-renaca-vs-no-propone-renca',
			! /renca/i.test( sugVS ),
			`suggest("Renaca","CL-VS") = ${ sugVS }`
		);

		// SIN región `suggest()` SÍ devuelve «Renca» (Santiago). Que eso llegue
		// o no al cliente depende de si el checkout deja pasar una región
		// vacía; se comprueba abajo contra el Store API en vez de suponerlo.
		const sugSin = evalPhp(
			"$d = new \\Akibara\\CartCheckoutBlocks\\ComunaDirectory(); echo '[' . (string) $d->suggest( 'Renaca', '' ) . ']';"
		);
		m.info( 'php-suggest-renaca-sin-region', `suggest("Renaca","") = ${ sugSin }` );

		const reqEstado = evalPhp(
			"$l = WC()->countries->get_country_locale(); echo '[' . ( isset( $l['CL']['state']['required'] ) ? var_export( $l['CL']['state']['required'], true ) : 'no-declarado(=obligatorio)' ) . ']';"
		);
		m.info( 'php-region-obligatoria', `locale CL.state.required = ${ reqEstado }` );

		const c = await contarCarrito( pagina );
		if ( c.lineas === 0 ) {
			await agregarAlCarrito( pagina, PRODUCTO_INSTOCK );
		}
		const envioSin = await prepararEnvio( pagina, { city: 'Renaca', state: '' } );
		m.info( 'api-renaca-sin-region-envio', `tarifas=${ envioSin.tarifas }` );
		const antesSin = contarPedidos();
		const resSin = await enviarCheckout( pagina, { direccion: { city: 'Renaca', state: '' } } );
		const despuesSin = contarPedidos();
		m.check(
			'api-renaca-sin-region-no-crea-pedido',
			despuesSin === antesSin && resSin.orderId === 0,
			`HTTP ${ resSin.status } order_id=${ resSin.orderId } pedidos ${ antesSin } -> ${ despuesSin } msg="${ resSin.message }"`
		);
		m.check(
			'api-renaca-sin-region-no-propone-renca',
			! /renca/i.test( resSin.message + resSin.detalles ),
			`msg="${ resSin.message }" detalles="${ resSin.detalles }"`
		);

		const matchRenaca = evalPhp(
			"$d = new \\Akibara\\CartCheckoutBlocks\\ComunaDirectory(); $m = $d->match( 'Renaca' ); echo '[' . ( null === $m ? 'null' : $m['name'] ) . ']';"
		);
		m.check(
			'php-match-renaca-no-existe',
			/\[null\]/.test( matchRenaca ),
			`match("Renaca") = ${ matchRenaca }`
		);
	}

	// B4 — NBSP: DEBE nacer el pedido y la comuna quedar canonizada.
	{
		const c = await contarCarrito( pagina );
		if ( c.lineas === 0 ) {
			await agregarAlCarrito( pagina, PRODUCTO_INSTOCK );
		}
		const antes = contarPedidos();
		const envio = await prepararEnvio( pagina, { city: `San${ NBSP }Miguel`, state: 'CL-RM' } );
		m.info( 'api-nbsp-envio', `tarifas=${ envio.tarifas } elegida=${ envio.elegida }` );
		const res = await enviarCheckout( pagina, {
			direccion: { city: `San${ NBSP }Miguel`, state: 'CL-RM' },
		} );
		const despues = contarPedidos();
		m.check(
			'api-nbsp-san-miguel-acepta',
			res.status === 200 && res.orderId > 0,
			`HTTP ${ res.status } order_id=${ res.orderId } code=${ res.code } msg="${ res.message }"`
		);
		m.check( 'api-nbsp-crea-un-pedido', despues === antes + 1, `pedidos ${ antes } -> ${ despues }` );

		if ( res.orderId > 0 ) {
			creados.push( res.orderId );
			const ciudad = sqlUno(
				`SELECT city FROM wp_wc_order_addresses WHERE order_id=${ res.orderId } AND address_type='billing'`
			);
			m.check(
				'api-nbsp-canoniza-a-san-miguel',
				ciudad === 'San Miguel',
				`guardado="${ ciudad }" bytes=[${ Array.from( ciudad ).map( ( ch ) => ch.charCodeAt( 0 ) ).join( ',' ) }]`
			);
		}
	}
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, String( error && error.message ? error.message : error ) );
} finally {
	await navegador.close();

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
