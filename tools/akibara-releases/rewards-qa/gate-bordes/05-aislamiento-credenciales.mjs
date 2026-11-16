/**
 * BORDE 5 — el espejo NO debe disparar NADA hacia producción.
 *
 * Solo LECTURA. Lección sellada del refresco de DB: el dump trae Cloudflare y
 * los webhooks ARMADOS, y el checklist post-import es obligatorio.
 *
 * Se comprueban las tres patas del aislamiento:
 *   1. LiteSpeed → Cloudflare neutralizado (flag en 0 y token VACÍO).
 *   2. Webhooks de WooCommerce (BrandAgent) sin ninguno `active`.
 *   3. Pasarelas de pago: NINGUNA pasarela habilitada puede resolver a
 *      credenciales de PRODUCCIÓN.
 *
 * POR QUÉ CAMBIÓ EL CENSO DE PASARELAS (gate del cutover, 2026-09-01):
 *   La versión anterior sólo miraba las tres options de MercadoPago escritas a
 *   mano y con eso afirmaba «ninguna pasarela habilitada resuelve a
 *   credenciales de PRODUCCIÓN». Era una afirmación sobre TODAS sostenida por
 *   la medición de UNA: Flow (`woocommerce_flowpayment_settings`, con su propio
 *   `mode` = PROD/SANDBOX y su `api_key`) no entraba en el censo, y cualquier
 *   pasarela que se instalara mañana tampoco. Ahora se enumeran TODAS las
 *   pasarelas registradas (`WC()->payment_gateways()->payment_gateways()`) y
 *   además se barren las options `woocommerce_*_settings` armadas que no
 *   correspondan a ninguna pasarela ni método de envío registrado — la
 *   pasarela dormida cuyo plugin está desactivado pero cuyas credenciales
 *   siguen cargadas.
 *
 * Uso: node tools/akibara-releases/rewards-qa/gate-bordes/05-aislamiento-credenciales.mjs
 */

import { Gate, wpEval, field } from './_lib.mjs';

const g = new Gate( 'BORDE 5 · aislamiento del espejo (webhooks y credenciales)' );

const LEER = `<?php
global $wpdb;
echo 'ENV=' . wp_get_environment_type() . "\\n";
echo 'HOME=' . home_url() . "\\n";

echo 'CF_FLAG=' . var_export( get_option( 'litespeed.conf.cdn-cloudflare', '__ABSENT__' ), true ) . "\\n";
echo 'CF_KEY_LEN=' . strlen( (string) get_option( 'litespeed.conf.cdn-cloudflare_key', '' ) ) . "\\n";

$hooks   = $wpdb->get_results( "SELECT webhook_id, status, name, delivery_url FROM {$wpdb->prefix}wc_webhooks", ARRAY_A );
$activos = array();
foreach ( $hooks as $h ) { if ( 'active' === $h['status'] ) { $activos[] = $h['name']; } }
echo 'WEBHOOKS_TOTAL=' . count( $hooks ) . "\\n";
echo 'WEBHOOKS_ACTIVOS=' . wp_json_encode( $activos ) . "\\n";
echo 'WEBHOOKS_DETALLE=' . wp_json_encode( array_map( static fn( $h ) => $h['status'] . ' ' . $h['name'], $hooks ) ) . "\\n";

/* ---------------------------------------------------------------------------
 * Pasarelas de pago — censo COMPLETO.
 * ------------------------------------------------------------------------ */

// Una clave es «credencial» si su NOMBRE promete un secreto. No se imprime
// ningún valor: sólo su longitud y su prefijo, que es lo que hace falta para
// distinguir «vacío» de «cargado».
$es_credencial = static function ( $clave ) {
	return (bool) preg_match(
		'/(token|secret|signature|credential|password|passwd|client_id|merchant|api[_-]?key|public[_-]?key|private[_-]?key|^key$|access)/i',
		(string) $clave
	);
};

// Modo efectivo declarado por los ajustes del propio plugin. Cada pasarela lo
// nombra distinto: Flow usa 'mode' (PROD/SANDBOX), el estándar de Woo usa
// 'testmode' (yes/no), otras usan 'sandbox'.
$modo_de = static function ( array $a ) {
	if ( isset( $a['mode'] ) && '' !== (string) $a['mode'] ) {
		return ( 0 === strcasecmp( (string) $a['mode'], 'PROD' ) || 0 === strcasecmp( (string) $a['mode'], 'PRODUCTION' ) || 0 === strcasecmp( (string) $a['mode'], 'live' ) )
			? 'PRODUCCION' : 'TEST';
	}
	if ( isset( $a['testmode'] ) ) { return ( 'yes' === $a['testmode'] ) ? 'TEST' : 'PRODUCCION'; }
	if ( isset( $a['sandbox'] ) )  { return ( 'yes' === $a['sandbox'] )  ? 'TEST' : 'PRODUCCION'; }
	return 'DESCONOCIDO';
};

$credenciales_de = static function ( array $a ) use ( $es_credencial ) {
	$out = array();
	foreach ( $a as $k => $v ) {
		if ( ! is_scalar( $v ) || ! $es_credencial( $k ) ) { continue; }
		$v = trim( (string) $v );
		if ( '' === $v || 'no' === $v || 'yes' === $v ) { continue; }
		$out[ $k ] = array( 'len' => strlen( $v ), 'pre' => substr( $v, 0, 6 ) );
	}
	return $out;
};

// MercadoPago guarda las credenciales FUERA de los settings de la pasarela
// (options globales del plugin) y el modo en 'checkbox_checkout_test_mode'.
$mp_test  = get_option( 'checkbox_checkout_test_mode', '__ABSENT__' );
$mp_extra = array();
foreach ( array( '_mp_access_token_prod', '_mp_public_key_prod' ) as $k ) {
	$v = trim( (string) get_option( $k, '' ) );
	if ( '' !== $v ) { $mp_extra[ $k ] = array( 'len' => strlen( $v ), 'pre' => substr( $v, 0, 6 ) ); }
}

$pasarelas = array();
$ids_pasarela = array();
if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
	foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) {
		$ids_pasarela[] = $id;
		$ajustes = (array) get_option( 'woocommerce_' . $id . '_settings', array() );
		$modo    = $modo_de( $ajustes );
		$creds   = $credenciales_de( $ajustes );

		// Las dos pasarelas de MercadoPago heredan modo y credenciales del
		// plugin, no de sus propios settings.
		if ( 0 === strpos( $id, 'woo-mercado-pago-' ) ) {
			$modo  = ( '__ABSENT__' === $mp_test || 'yes' === $mp_test ) ? 'TEST' : 'PRODUCCION';
			$creds = array_merge( $creds, $mp_extra );
		}

		$pasarelas[ $id ] = array(
			'clase'       => get_class( $gw ),
			'option'      => 'woocommerce_' . $id . '_settings',
			'habilitada'  => ( 'yes' === $gw->enabled ),
			'modo'        => $modo,
			'credenciales'=> $creds,
		);
	}
}
echo 'PASARELAS=' . wp_json_encode( $pasarelas ) . "\\n";

/* Barrido de options woocommerce_*_settings ARMADAS que no pertenecen a
 * ninguna pasarela NI a ningún método de envío registrado: la pasarela cuyo
 * plugin está desactivado pero cuyas credenciales siguen en la base. */
$ids_envio = array();
if ( function_exists( 'WC' ) && WC()->shipping() ) {
	foreach ( WC()->shipping()->get_shipping_methods() as $sid => $sm ) { $ids_envio[] = $sid; }
}
$huerfanas = array();
$nombres = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\\_%\\_settings'" );
foreach ( $nombres as $nombre ) {
	$id = preg_replace( '/^woocommerce_(.*)_settings$/', '$1', $nombre );
	if ( in_array( $id, $ids_pasarela, true ) ) { continue; }
	// Los métodos de envío por zona añaden un sufijo de instancia (bluex-ex_17).
	$base = preg_replace( '/_\\d+$/', '', $id );
	if ( in_array( $id, $ids_envio, true ) || in_array( $base, $ids_envio, true ) ) { continue; }
	$a = (array) get_option( $nombre, array() );
	if ( 'yes' !== ( $a['enabled'] ?? 'no' ) ) { continue; }
	$creds = $credenciales_de( $a );
	if ( ! $creds ) { continue; }
	$huerfanas[ $nombre ] = array( 'modo' => $modo_de( $a ), 'credenciales' => $creds );
}
echo 'HUERFANAS=' . wp_json_encode( $huerfanas ) . "\\n";

echo 'MP_TEST_MODE=' . var_export( $mp_test, true ) . "\\n";
echo 'MP_PLUGIN_ACTIVO=' . var_export( in_array( 'woocommerce-mercadopago/woocommerce-mercadopago.php', (array) get_option( 'active_plugins', array() ), true ), true ) . "\\n";
`;

const out = wpEval( 'b5-leer.php', LEER );

g.check( field( out, 'ENV' ) === 'local', 'el espejo se declara entorno local', `WP_ENVIRONMENT_TYPE=${ field( out, 'ENV' ) }` );
g.check( field( out, 'HOME' ).includes( 'localhost' ), 'home_url apunta a localhost', field( out, 'HOME' ) );

// --- 1. LiteSpeed → Cloudflare ---
const cfFlag = field( out, 'CF_FLAG' );
const cfKeyLen = Number( field( out, 'CF_KEY_LEN' ) );
g.check( cfFlag === "'0'" || cfFlag === "''", 'el CDN de Cloudflare en LiteSpeed está APAGADO', `litespeed.conf.cdn-cloudflare=${ cfFlag }` );
g.check( cfKeyLen === 0, 'el token de Cloudflare está VACÍO', `longitud=${ cfKeyLen }` );

// --- 2. Webhooks ---
const activos = JSON.parse( field( out, 'WEBHOOKS_ACTIVOS' ) );
g.check( activos.length === 0, 'ningún webhook de WooCommerce está activo', `${ field( out, 'WEBHOOKS_TOTAL' ) } webhooks, activos=${ JSON.stringify( activos ) }` );
console.log( `      detalle: ${ field( out, 'WEBHOOKS_DETALLE' ) }` );

// --- 3. Pasarelas de pago ---
const pasarelas = JSON.parse( field( out, 'PASARELAS' ) );
const ids = Object.keys( pasarelas );
const resumen = ( id ) => {
	const p = pasarelas[ id ];
	const cred = Object.entries( p.credenciales )
		.map( ( [ k, v ] ) => `${ k }=${ v.pre }…(${ v.len })` )
		.join( ' ' ) || 'sin credenciales cargadas';
	return `${ id } [${ p.habilitada ? 'HABILITADA' : 'apagada' }] modo=${ p.modo } ${ cred }`;
};

// El censo tiene que existir: cero pasarelas significa que WC no cargó, y
// entonces todo lo que venga después sería «verde» por no haber mirado nada.
g.check( ids.length > 0, 'el censo enumera las pasarelas registradas', `${ ids.length } pasarelas: ${ ids.join( ', ' ) }` );
for ( const id of ids ) {
	console.log( `      ${ resumen( id ) }` );
}

const peligrosas = ids.filter( ( id ) => {
	const p = pasarelas[ id ];
	return p.habilitada && p.modo === 'PRODUCCION' && Object.keys( p.credenciales ).length > 0;
} );
g.check(
	peligrosas.length === 0,
	'ninguna pasarela HABILITADA resuelve a credenciales de PRODUCCIÓN',
	peligrosas.length ? peligrosas.map( resumen ).join( ' || ' ) : `${ ids.length } pasarelas censadas, habilitadas=${ JSON.stringify( ids.filter( ( i ) => pasarelas[ i ].habilitada ) ) }`
);

// Una pasarela habilitada con credenciales y modo DESCONOCIDO no se puede dar
// por buena: se dice, y falla, porque el que no sabe en qué modo está es el
// gate, no la pasarela.
const opacas = ids.filter( ( id ) => pasarelas[ id ].habilitada && pasarelas[ id ].modo === 'DESCONOCIDO' && Object.keys( pasarelas[ id ].credenciales ).length > 0 );
g.check(
	opacas.length === 0,
	'ninguna pasarela habilitada con credenciales tiene modo indeterminable',
	opacas.length ? opacas.map( resumen ).join( ' || ' ) : 'ninguna'
);

// Flow, nombrada EXPLÍCITAMENTE: es la que faltaba en el censo viejo.
const flow = pasarelas.flowpayment;
g.check(
	!! flow,
	'Flow (woocommerce_flowpayment_settings) entra en el censo',
	flow ? resumen( 'flowpayment' ) : 'la pasarela flowpayment NO aparece en payment_gateways()'
);
if ( flow ) {
	g.check(
		! ( flow.habilitada && Object.keys( flow.credenciales ).length > 0 ),
		'Flow no está habilitada con credenciales cargadas',
		`habilitada=${ flow.habilitada } modo=${ flow.modo } credenciales=${ JSON.stringify( Object.keys( flow.credenciales ) ) } (mode=PROD con api_key vacía NO dispara nada)`
	);
}

// MercadoPago, también por su nombre: fue la pata que costó la lección.
const mp = ids.filter( ( id ) => id.startsWith( 'woo-mercado-pago-' ) );
g.check(
	mp.every( ( id ) => ! pasarelas[ id ].habilitada ),
	'ninguna pasarela de MercadoPago está habilitada',
	`${ JSON.stringify( mp ) } test_mode=${ field( out, 'MP_TEST_MODE' ) } plugin_activo=${ field( out, 'MP_PLUGIN_ACTIVO' ) }`
);

// --- 4. Settings armadas de una pasarela NO registrada (plugin apagado) ---
const huerfanas = JSON.parse( field( out, 'HUERFANAS' ) );
const nombresHuerfanas = Object.keys( huerfanas );
g.check(
	nombresHuerfanas.length === 0,
	'ninguna option woocommerce_*_settings ajena al censo queda armada con credenciales',
	nombresHuerfanas.length
		? nombresHuerfanas.map( ( n ) => `${ n } modo=${ huerfanas[ n ].modo } claves=${ JSON.stringify( Object.keys( huerfanas[ n ].credenciales ) ) }` ).join( ' || ' )
		: 'ninguna'
);

g.fin();
