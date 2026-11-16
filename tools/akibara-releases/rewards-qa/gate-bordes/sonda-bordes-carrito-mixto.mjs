/**
 * sonda-bordes-carrito-mixto.mjs — CASO 4: instock + a pedido en el MISMO
 * carrito.
 *
 * Productos:
 *   17083 Parasyte 04   — instock, stock gestionado (4).
 *   13629 Dan Da Dan 11 — onbackorder («A pedido»), sin gestión de stock.
 *
 * Qué se exige:
 *   1. El carrito y el checkout SERVIDOS muestran el aviso de plazos con la
 *      redacción de pedido MIXTO (no la de «todo a pedido»), y con una fecha.
 *   2. Cada línea declara su estado: «Disponible» / «A pedido» + llegada.
 *   3. El pedido nace con LOS DOS artículos y con estados coherentes:
 *      el a pedido queda marcado `_akb_eta_was_preventa=yes` con su ventana
 *      de fechas, y el disponible NO.
 *   4. El stock se mueve sólo donde corresponde: −1 en el gestionado, y el
 *      onbackorder sigue onbackorder (no se convierte en agotado).
 *
 * Restaura pedidos, borradores y stock. Salida != 0 si algún check falla.
 */

import {
	abrir, BASE, MOVIL, agregarAlCarrito, vaciarCarrito, contarCarrito,
	contarPedidos, maxPedidoId, pedidosDesde, pedidosRealesDesde, borrarPedido,
	borrarBorradores, enviarCheckout, prepararEnvio, crearMarcador, sql, sqlUno,
	fotoStock, restaurarStock,
	fotoOpcionesPudo, limpiarOpcionesPudo, opcionesPudoNuevas,
} from './lib-bordes.mjs';

const INSTOCK = 17083;
const A_PEDIDO = 13629;
const m = crearMarcador( 'bordes-carrito-mixto' );
const idRef = maxPedidoId();
const pedidosAntes = contarPedidos();
const pudoAntes = fotoOpcionesPudo();
const stockAntes = fotoStock( [ INSTOCK, A_PEDIDO ] );

/**
 * Texto y visibilidad del aviso de plazos.
 *
 * DOS clases distintas para el MISMO aviso: el carrito lo pinta como
 * `.akb-order-preventa-blocks-notice` (ExperimentalOrderMeta) y el checkout
 * como `.akb-checkout-preventa` (bloque interno del checkout). Buscar sólo la
 * del carrito daba «no existe» en el checkout con el aviso a la vista
 * (medido el 2026-09-01): el defecto era del selector, no del producto.
 */
const SELECTOR_AVISO = '.akb-order-preventa-blocks-notice, .akb-checkout-preventa';

async function avisoPlazos( pagina ) {
	return pagina.evaluate( ( sel ) => {
		const nodo = document.querySelector( sel );
		if ( ! nodo ) {
			return { existe: false, texto: '', visible: false, tba: false, clase: '' };
		}
		return {
			existe: true,
			texto: ( nodo.textContent || '' ).replace( /\s+/g, ' ' ).trim(),
			visible: !! ( nodo.offsetWidth || nodo.offsetHeight || nodo.getClientRects().length ),
			tba: /--tba/.test( nodo.className ),
			clase: nodo.className,
		};
	}, SELECTOR_AVISO );
}

/**
 * Espera acotada a que la hidratación de Blocks termine, informando CUÁNTO
 * tardó. Un `waitForTimeout` fijo daba falsos negativos (las clases por fila
 * aparecen a los ~3,1 s en móvil) y un `waitFor` sin tope escondería una
 * ausencia real: aquí el tiempo se imprime y el tope es de 20 s.
 */
async function esperarHidratacion( pagina, comprobar, etiqueta, topeMs = 20000 ) {
	const t0 = Date.now();
	while ( Date.now() - t0 < topeMs ) {
		if ( await pagina.evaluate( comprobar, SELECTOR_AVISO ) ) {
			m.info( etiqueta, `hidratado a los ${ Date.now() - t0 } ms` );
			return Date.now() - t0;
		}
		await pagina.waitForTimeout( 400 );
	}
	m.info( etiqueta, `NO hidratado tras ${ Date.now() - t0 } ms (tope)` );
	return -1;
}

const { navegador, pagina } = await abrir( MOVIL );

try {
	await vaciarCarrito( pagina );
	await agregarAlCarrito( pagina, INSTOCK );
	const carrito = await agregarAlCarrito( pagina, A_PEDIDO );
	m.check(
		'carrito-mixto-dos-lineas',
		carrito.lineas === 2 && carrito.ids.includes( INSTOCK ) && carrito.ids.includes( A_PEDIDO ),
		`lineas=${ carrito.lineas } ids=[${ carrito.ids.join( ',' ) }] total=${ carrito.total }`
	);

	// --- Estados por línea, leídos del Store API servido ---
	const estados = await pagina.evaluate( async () => {
		const r = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
		const c = await r.json();
		return ( c.items || [] ).map( ( i ) => ( {
			id: i.id,
			estado: ( i.extensions.akibara_cart_checkout_blocks || {} ).stock_status,
			etiqueta: ( i.extensions.akibara_cart_checkout_blocks || {} ).stock_label,
			eta: ( i.extensions.akibara_cart_checkout_blocks || {} ).eta,
			datos: ( i.item_data || [] ).map( ( d ) => `${ d.key }: ${ d.display }` ).join( ' / ' ),
		} ) );
	} );
	const eIn = estados.find( ( e ) => e.id === INSTOCK ) || {};
	const eAp = estados.find( ( e ) => e.id === A_PEDIDO ) || {};
	m.check(
		'linea-instock-declara-disponible',
		eIn.estado === 'instock' && /disponible/i.test( eIn.datos || '' ),
		`id=${ INSTOCK } stock_status=${ eIn.estado } item_data="${ eIn.datos }"`
	);
	m.check(
		'linea-a-pedido-declara-a-pedido-y-llegada',
		eAp.estado === 'onbackorder' && /a pedido/i.test( eAp.datos || '' ) && /llegada estimada/i.test( eAp.datos || '' ),
		`id=${ A_PEDIDO } stock_status=${ eAp.estado } eta="${ eAp.eta }" item_data="${ eAp.datos }"`
	);

	// --- Aviso de plazos en /carrito/ ---
	await pagina.goto( `${ BASE }/carrito/`, { waitUntil: 'domcontentloaded' } );
	await esperarHidratacion(
		pagina,
		( sel ) =>
			!! document.querySelector( sel ) &&
			Array.from( document.querySelectorAll( '.wc-block-cart-items__row' ) ).some( ( f ) =>
				/akb-stock--/.test( f.className )
			),
		'carrito-tiempo-de-hidratacion'
	);
	const avisoCarrito = await avisoPlazos( pagina );
	m.check(
		'carrito-muestra-aviso-de-plazos',
		avisoCarrito.existe && avisoCarrito.visible,
		`existe=${ avisoCarrito.existe } visible=${ avisoCarrito.visible } texto="${ avisoCarrito.texto }"`
	);
	m.check(
		'carrito-aviso-redactado-como-mixto',
		/combina productos disponibles y por encargo/i.test( avisoCarrito.texto ),
		`texto="${ avisoCarrito.texto }"`
	);

	// Clases por fila: la parte visual del estado.
	const filas = await pagina.evaluate( () =>
		Array.from( document.querySelectorAll( '.wc-block-cart-items__row' ) ).map( ( f ) => f.className )
	);
	m.check(
		'carrito-filas-marcadas-por-estado',
		filas.some( ( c ) => /akb-stock--instock/.test( c ) ) && filas.some( ( c ) => /akb-stock--onbackorder/.test( c ) ),
		`clases de fila = ${ JSON.stringify( filas ) }`
	);

	// --- Aviso de plazos en /checkout/ ---
	await pagina.goto( `${ BASE }/checkout/`, { waitUntil: 'domcontentloaded' } );
	await pagina.waitForSelector( '#billing-city', { timeout: 30000 } );
	await esperarHidratacion(
		pagina,
		( sel ) =>
			!! document.querySelector( sel ) &&
			Array.from( document.querySelectorAll( '.wc-block-components-order-summary-item' ) ).some( ( f ) =>
				/akb-stock--/.test( f.className )
			),
		'checkout-tiempo-de-hidratacion'
	);
	const avisoCheckout = await avisoPlazos( pagina );
	m.check(
		'checkout-muestra-aviso-de-plazos',
		avisoCheckout.existe && avisoCheckout.visible,
		`clase="${ avisoCheckout.clase }" visible=${ avisoCheckout.visible } texto="${ avisoCheckout.texto }"`
	);
	m.check(
		'checkout-aviso-redactado-como-mixto',
		/combina productos disponibles y por encargo/i.test( avisoCheckout.texto ),
		`texto="${ avisoCheckout.texto }"`
	);

	// En móvil el resumen se renderiza DOS veces (la copia plegada queda
	// oculta). Lo que se le exige al producto es que TODA línea que el
	// cliente ve lleve su estado, no que haya «alguna» marcada.
	const resumenCheckout = await pagina.evaluate( () =>
		Array.from( document.querySelectorAll( '.wc-block-components-order-summary-item' ) ).map( ( f ) => ( {
			cls: f.className,
			visible: !! ( f.offsetWidth || f.offsetHeight || f.getClientRects().length ),
		} ) )
	);
	const visibles = resumenCheckout.filter( ( f ) => f.visible );
	m.check(
		'checkout-resumen-marca-los-dos-estados',
		visibles.length === 2 &&
			visibles.every( ( f ) => /akb-stock--/.test( f.cls ) ) &&
			visibles.some( ( f ) => /akb-stock--instock/.test( f.cls ) ) &&
			visibles.some( ( f ) => /akb-stock--onbackorder/.test( f.cls ) ),
		`${ resumenCheckout.length } líneas (${ visibles.length } visibles) = ${ JSON.stringify( resumenCheckout ) }`
	);
	m.check(
		'checkout-aviso-trae-fecha-no-tba',
		/\d/.test( avisoCheckout.texto ) && avisoCheckout.tba === false,
		`tba=${ avisoCheckout.tba } texto="${ avisoCheckout.texto }"`
	);

	// --- Nace el pedido ---
	await prepararEnvio( pagina );
	const antes = contarPedidos();
	const res = await enviarCheckout( pagina, { direccion: { email: 'qa.mixto@example.test' } } );
	const despues = contarPedidos();
	m.check(
		'pedido-mixto-nace',
		res.status === 200 && res.orderId > 0 && despues === antes + 1,
		`HTTP ${ res.status } order_id=${ res.orderId } pedidos ${ antes } -> ${ despues } msg="${ res.message }"`
	);

	if ( res.orderId > 0 ) {
		const items = sql(
			`SELECT oi.order_item_id, oi.order_item_name, MAX(CASE WHEN oim.meta_key='_product_id' THEN oim.meta_value END) pid, MAX(CASE WHEN oim.meta_key='_qty' THEN oim.meta_value END) qty FROM wp_woocommerce_order_items oi JOIN wp_woocommerce_order_itemmeta oim ON oim.order_item_id=oi.order_item_id WHERE oi.order_id=${ res.orderId } AND oi.order_item_type='line_item' GROUP BY oi.order_item_id`
		)
			.split( '\n' )
			.map( ( l ) => l.trim() )
			.filter( Boolean )
			.map( ( l ) => {
				const [ itemId, nombre, pid, qty ] = l.split( '\t' );
				return { itemId: parseInt( itemId, 10 ), nombre, pid: parseInt( pid, 10 ), qty: parseInt( qty, 10 ) };
			} );

		m.check(
			'pedido-trae-los-dos-articulos',
			items.length === 2 &&
				items.some( ( i ) => i.pid === INSTOCK && i.qty === 1 ) &&
				items.some( ( i ) => i.pid === A_PEDIDO && i.qty === 1 ),
			`items=${ JSON.stringify( items ) }`
		);

		const itemIn = items.find( ( i ) => i.pid === INSTOCK );
		const itemAp = items.find( ( i ) => i.pid === A_PEDIDO );

		const marcaAp = itemAp
			? sqlUno(
					`SELECT IFNULL(MAX(CASE WHEN meta_key='_akb_eta_was_preventa' THEN meta_value END),'(sin marca)') FROM wp_woocommerce_order_itemmeta WHERE order_item_id=${ itemAp.itemId }`
			  )
			: '(sin item)';
		m.check(
			'articulo-a-pedido-marcado-was-preventa',
			marcaAp === 'yes',
			`_akb_eta_was_preventa del item ${ A_PEDIDO } = "${ marcaAp }"`
		);

		const ventana = itemAp
			? sqlUno(
					`SELECT CONCAT(IFNULL(MAX(CASE WHEN meta_key='_akb_eta_min_at_purchase' THEN meta_value END),'-'),'..',IFNULL(MAX(CASE WHEN meta_key='_akb_eta_max_at_purchase' THEN meta_value END),'-'),' tba=',IFNULL(MAX(CASE WHEN meta_key='_akb_eta_is_tba_at_purchase' THEN meta_value END),'-')) FROM wp_woocommerce_order_itemmeta WHERE order_item_id=${ itemAp.itemId }`
			  )
			: '(sin item)';
		m.check(
			'articulo-a-pedido-congela-su-ventana-de-fechas',
			/^\d+\.\.\d+ tba=no$/.test( ventana ),
			`min..max del item ${ A_PEDIDO } = ${ ventana }`
		);

		const marcaIn = itemIn
			? sqlUno(
					`SELECT IFNULL(MAX(CASE WHEN meta_key='_akb_eta_was_preventa' THEN meta_value END),'(sin marca)') FROM wp_woocommerce_order_itemmeta WHERE order_item_id=${ itemIn.itemId }`
			  )
			: '(sin item)';
		m.check(
			'articulo-disponible-no-marcado-como-preventa',
			marcaIn !== 'yes',
			`_akb_eta_was_preventa del item ${ INSTOCK } = "${ marcaIn }"`
		);

		const estadoPedido = sqlUno( `SELECT status FROM wp_wc_orders WHERE id=${ res.orderId }` );
		m.check(
			'pedido-en-estado-de-transferencia-pendiente',
			estadoPedido === 'wc-on-hold',
			`status = ${ estadoPedido } (bacs deja el pedido en espera de la transferencia)`
		);

		// Stock: el gestionado baja 1; el onbackorder sigue onbackorder.
		const stockTras = fotoStock( [ INSTOCK, A_PEDIDO ] );
		const esperadoIn = `${ parseInt( stockAntes[ INSTOCK ].split( '|' )[ 0 ], 10 ) - 1 }|instock`;
		m.check(
			'stock-baja-solo-en-el-gestionado',
			stockTras[ INSTOCK ] === esperadoIn,
			`${ INSTOCK }: ${ stockAntes[ INSTOCK ] } -> ${ stockTras[ INSTOCK ] } (esperado ${ esperadoIn })`
		);
		m.check(
			'a-pedido-sigue-onbackorder-tras-vender',
			/onbackorder$/.test( stockTras[ A_PEDIDO ] ),
			`${ A_PEDIDO }: ${ stockAntes[ A_PEDIDO ] } -> ${ stockTras[ A_PEDIDO ] }`
		);
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
	const stockDespues = fotoStock( [ INSTOCK, A_PEDIDO ] );
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
