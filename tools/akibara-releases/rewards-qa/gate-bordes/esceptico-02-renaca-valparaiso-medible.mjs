/**
 * esceptico-02-renaca-valparaiso-medible.mjs — REFUTA el «NO-MEDIBLE-LOCAL»
 * del caso «Renaca rechazado por el Store API con dirección real de Valparaíso».
 *
 * La fase 2 lo cerró diciendo que «NINGUNA zona cubre Valparaíso». Eso no es
 * exacto: la zona 9 («Valparaíso - Blue Express») existe y cubre CL-VS; lo que
 * falta es una tarifa VIVA, porque los métodos `bluex-*` necesitan la API del
 * tercero y el `free_shipping` de esa zona (instancia 94) está APAGADO.
 *
 * Encender esa instancia es un `is_enabled` 0→1 en UNA fila que YA existe
 * (`wp_woocommerce_shipping_zone_methods`). No crea filas, no toca términos de
 * taxonomía, y se devuelve exactamente al valor leído antes. Con la tarifa
 * viva, el POST del Store API deja de morir en
 * `woocommerce_rest_invalid_shipping_option` y llega hasta
 * `validate_chilean_comuna`, que es justo lo que se quería medir.
 *
 * `free_shipping` 94 exige `min_amount = 55.000`, así que el carrito se arma
 * con 3 × Parasyte 04 + 1 × Jujutsu Kaisen 17 (61.490) para que la tarifa
 * aparezca de verdad. Si no apareciera, el primer check lo dice y la sonda
 * para: no se mide el rechazo «por el motivo equivocado».
 *
 * Qué se comprueba:
 *   1. Con la tarifa viva, Valparaíso ofrece >= 1 tarifa (premisa, no supuesto).
 *   2. Una comuna REAL de Valparaíso («Viña del Mar») es ACEPTADA: nace pedido.
 *      Control positivo — sin él, un rechazo universal parecería un acierto.
 *   3. «Renaca» es RECHAZADO con HTTP 400 y el mensaje NO nombra «Renca».
 *   4. «Xyzlandia» también es rechazado (la validación no depende del parecido).
 *
 * RESTAURA: `is_enabled` de la instancia 94, pedidos + borradores, stock,
 * filas `akb_pudo_state`. Sale != 0 ante cualquier falla.
 *
 * Uso (cwd = tools/akibara-releases/rewards-qa/):
 *   node gate-bordes/esceptico-02-renaca-valparaiso-medible.mjs
 */

import { execFileSync } from 'node:child_process';
import {
	abrir, BASE, ESCRITORIO, vaciarCarrito, contarPedidos, maxPedidoId,
	pedidosDesde, pedidosRealesDesde, borrarPedido, borrarBorradores,
	crearMarcador, fotoStock, restaurarStock, sql, sqlUno, evalPhp,
	fotoOpcionesPudo, limpiarOpcionesPudo, opcionesPudoNuevas, prepararEnvio, enviarCheckout,
} from './lib-bordes.mjs';

const INSTANCIA_FREE_VS = 94;   // free_shipping de la zona 9 (Valparaíso).
const PRODUCTOS = [ [ 17083, 3 ], [ 16065, 1 ] ]; // 3×15.500 + 1×14.990 = 61.490.
const IDS = PRODUCTOS.map( ( p ) => p[ 0 ] );

const MAILPIT = 'http://localhost:8025';
const m = crearMarcador( 'esceptico-renaca-valparaiso' );

/**
 * Mailpit por ID. Un pedido sembrado manda 4 correos (confirmación al cliente,
 * aviso al dueño y dos «Producto con pocas existencias»): si no se recogen, la
 * bandeja del espejo NO vuelve a como estaba. Se borra por ID, nunca por
 * patrón de asunto — borrar por patrón se llevó correos ajenos el 2026-09-01.
 */
function mailpitIds() {
	const datos = JSON.parse(
		execFileSync( 'curl', [ '-s', `${ MAILPIT }/api/v1/messages?limit=300` ], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 } )
	);
	return ( datos.messages || [] ).map( ( x ) => x.ID );
}

const correosAntes = mailpitIds();
const idsCorreoAntes = new Set( correosAntes );

const idRef = maxPedidoId();
const pedidosAntes = contarPedidos();
const pudoAntes = fotoOpcionesPudo();
const stockAntes = fotoStock( IDS );
const enabledAntes = sqlUno(
	`SELECT is_enabled FROM wp_woocommerce_shipping_zone_methods WHERE instance_id=${ INSTANCIA_FREE_VS }`
);

let navegador = null;

const VALPARAISO = ( city ) => ( { city, state: 'CL-VS', address_1: 'Av Borgoño 16000', postcode: '' } );

try {
	m.check(
		'premisa-la-instancia-existe-y-estaba-apagada',
		enabledAntes === '0',
		`wp_woocommerce_shipping_zone_methods.instance_id=${ INSTANCIA_FREE_VS } is_enabled=${ enabledAntes } (esperado '0')`
	);

	sql( `UPDATE wp_woocommerce_shipping_zone_methods SET is_enabled=1 WHERE instance_id=${ INSTANCIA_FREE_VS }` );
	evalPhp( 'if ( function_exists( "wc_delete_shop_order_transients" ) ) { wc_delete_shop_order_transients(); } delete_transient( "wc_shipping_method_count" ); echo "ok";' );
	const enabledAhora = sqlUno(
		`SELECT is_enabled FROM wp_woocommerce_shipping_zone_methods WHERE instance_id=${ INSTANCIA_FREE_VS }`
	);
	m.check( 'tarifa-de-valparaiso-encendida-para-la-medicion', enabledAhora === '1', `is_enabled ${ enabledAntes } -> ${ enabledAhora }` );

	const sesion = await abrir( ESCRITORIO );
	navegador = sesion.navegador;
	const pagina = sesion.pagina;

	await vaciarCarrito( pagina );
	for ( const [ id, cantidad ] of PRODUCTOS ) {
		for ( let i = 0; i < cantidad; i++ ) {
			await pagina.goto( `${ BASE }/?add-to-cart=${ id }`, { waitUntil: 'domcontentloaded' } );
		}
	}
	const carrito = await pagina.evaluate( async () => {
		const r = await fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } );
		const c = await r.json();
		return { lineas: ( c.items || [] ).length, cantidad: c.items_count, total: c.totals ? c.totals.total_price : null };
	} );
	// El Store API devuelve el total en la unidad MÍNIMA de la divisa y el CLP
	// tiene 0 decimales: `total_price` ya viene en pesos (61490 = $61.490).
	// Compararlo contra 5.500.000 daba un rojo falso (medido el 2026-09-01).
	m.check(
		'carrito-supera-el-minimo-de-envio-gratis',
		carrito.cantidad === 4 && Number( carrito.total ) >= 55000,
		`lineas=${ carrito.lineas } unidades=${ carrito.cantidad } total=${ carrito.total } (CLP, minor_unit 0) · min_amount de free_shipping 94 = 55.000`
	);

	// --- 1. PREMISA: ahora Valparaíso SÍ ofrece tarifa. ---
	const envio = await prepararEnvio( pagina, VALPARAISO( 'Viña del Mar' ) );
	m.check(
		'valparaiso-ofrece-al-menos-una-tarifa',
		envio.tarifas > 0,
		`tarifas=${ envio.tarifas } elegida=${ envio.elegida } paquetes=${ envio.paquetes } (con la instancia 94 encendida)`
	);

	if ( envio.tarifas === 0 ) {
		throw new Error( 'sin tarifa viva no se puede medir la comuna: se aborta antes de concluir nada' );
	}

	// --- 3+4. RECHAZOS: se prueban ANTES del control positivo para que el
	//     control positivo no dependa de un pedido ya creado. ---
	const pedidosPrevRechazo = contarPedidos();
	const renaca = await enviarCheckout( pagina, { direccion: VALPARAISO( 'Renaca' ) } );
	const trasRenaca = contarPedidos();
	m.check(
		'renaca-en-valparaiso-lo-rechaza-el-SERVIDOR',
		renaca.status === 400 && renaca.orderId === 0 && trasRenaca === pedidosPrevRechazo,
		`HTTP ${ renaca.status } code=${ renaca.code } order_id=${ renaca.orderId } · pedidos ${ pedidosPrevRechazo }->${ trasRenaca } · mensaje="${ renaca.message.replace( /<[^>]*>/g, '' ).slice( 0, 200 ) }" · detalles=${ renaca.detalles.slice( 0, 200 ) }`
	);
	m.check(
		'el-mensaje-de-renaca-no-propone-renca',
		! /\bRenca\b/i.test( renaca.message + renaca.detalles ),
		`¿contiene «Renca»?=${ /\bRenca\b/i.test( renaca.message + renaca.detalles ) } · mensaje="${ renaca.message.replace( /<[^>]*>/g, '' ).slice( 0, 200 ) }"`
	);

	const inventada = await enviarCheckout( pagina, { direccion: VALPARAISO( 'Xyzlandia' ) } );
	const trasInventada = contarPedidos();
	m.check(
		'comuna-inventada-en-valparaiso-tambien-se-rechaza',
		inventada.status === 400 && inventada.orderId === 0 && trasInventada === pedidosPrevRechazo,
		`HTTP ${ inventada.status } code=${ inventada.code } order_id=${ inventada.orderId } · pedidos ${ pedidosPrevRechazo }->${ trasInventada } · mensaje="${ inventada.message.replace( /<[^>]*>/g, '' ).slice( 0, 160 ) }"`
	);

	// --- 2. CONTROL POSITIVO: una comuna REAL de Valparaíso pasa. ---
	await prepararEnvio( pagina, VALPARAISO( 'Viña del Mar' ) );
	const buena = await enviarCheckout( pagina, { direccion: VALPARAISO( 'Viña del Mar' ) } );
	const trasBuena = contarPedidos();
	const nuevos = pedidosRealesDesde( idRef );
	m.check(
		'control-positivo-vina-del-mar-SI-crea-pedido',
		buena.status === 200 && buena.orderId > 0 && trasBuena === pedidosPrevRechazo + 1,
		`HTTP ${ buena.status } order_id=${ buena.orderId } code=${ buena.code } · pedidos ${ pedidosPrevRechazo }->${ trasBuena } · nuevos=[${ nuevos.map( ( p ) => `${ p.id }:${ p.status }` ).join( ',' ) }] · mensaje="${ buena.message.replace( /<[^>]*>/g, '' ).slice( 0, 160 ) }"`
	);

	const ciudadGuardada = buena.orderId
		? sqlUno( `SELECT city FROM wp_wc_order_addresses WHERE order_id=${ buena.orderId } AND address_type='billing'` )
		: '(sin pedido)';
	m.check(
		'la-comuna-valida-se-guarda-canonizada',
		ciudadGuardada === 'Viña del Mar',
		`city guardada = "${ ciudadGuardada }"`
	);

	await vaciarCarrito( pagina );
	await navegador.close();
	navegador = null;
} catch ( error ) {
	m.check( 'sonda-sin-excepciones', false, `EXCEPCIÓN: ${ error && error.message ? error.message : error }` );
} finally {
	if ( navegador ) {
		await navegador.close();
	}

	// ---- RESTAURACIÓN ----
	sql( `UPDATE wp_woocommerce_shipping_zone_methods SET is_enabled=${ enabledAntes } WHERE instance_id=${ INSTANCIA_FREE_VS }` );
	const enabledDespues = sqlUno(
		`SELECT is_enabled FROM wp_woocommerce_shipping_zone_methods WHERE instance_id=${ INSTANCIA_FREE_VS }`
	);
	m.check(
		'restauracion-instancia-94-antes-igual-despues',
		enabledDespues === enabledAntes,
		`is_enabled antes=${ enabledAntes } despues=${ enabledDespues }`
	);

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
	const stockDespues = fotoStock( IDS );
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
		`akb_pudo_state antes=${ pudoAntes.length } creadas=${ pudoNuevas.length } residuales=${ pudoResidual.length } [${ pudoResidual.join( ',' ) }] despues=${ fotoOpcionesPudo().length }`
	);

	const finales = mailpitIds();
	const aBorrar = finales.filter( ( id ) => ! idsCorreoAntes.has( id ) );
	if ( aBorrar.length ) {
		execFileSync( 'curl', [
			'-s', '-X', 'DELETE', `${ MAILPIT }/api/v1/messages`,
			'-H', 'Content-Type: application/json',
			'-d', JSON.stringify( { IDs: aBorrar } ),
		], { encoding: 'utf8' } );
	}
	const tras = mailpitIds();
	m.check(
		'restauracion-mailpit-por-ID-antes-igual-despues',
		tras.length === correosAntes.length && tras.every( ( id ) => idsCorreoAntes.has( id ) ),
		`antes=${ correosAntes.length } creados=${ aBorrar.length } despues=${ tras.length } · ajenos borrados=${ correosAntes.filter( ( id ) => ! tras.includes( id ) ).length }`
	);
}

process.exit( m.cerrar() > 0 ? 1 : 0 );
