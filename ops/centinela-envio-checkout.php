<?php
/**
 * Centinela de SÓLO LECTURA: ¿la caja cotiza con la dirección que ve el cliente?
 *
 * Nació de un reporte de cliente (2026-09-28): con `billing_only` la caja muestra
 * la tarjeta de facturación pero WooCommerce cotiza con el ENVÍO guardado; con el
 * envío vacío o distinto, Blue no aparecía o cotizaba otra zona. checkout-blocks
 * 2.2.30/2.2.31 alinea envío = facturación al cargar el carrito y antes de guardar
 * cualquier cliente. Este centinela comprueba que eso se sostiene en el tiempo.
 *
 * No escribe nada ni dispara hooks: lee user meta, sesiones y pedidos.
 * Uso (lo llama ops/centinela-envio-checkout.sh): wp eval-file <este> [desde_unix]
 *   desde_unix: despliegue de 2.2.31; las cuentas guardadas y las sesiones activas
 *   DESPUÉS de esa hora no pueden quedar desalineadas.
 *
 * Imprime JSON con conteos (sin datos personales) y una última línea
 * `VEREDICTO: OK|AVISO|ALERTA`.
 *
 * @package Akibara\Ops
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$akb_desde    = isset( $args[0] ) ? (int) $args[0] : 0;
$akb_lugar    = array( 'country', 'state', 'city', 'address_1', 'address_2' );
$akb_completa = array( 'country', 'state', 'city', 'address_1' );
$akb_informe  = array(
	'billing_only' => function_exists( 'wc_ship_to_billing_address_only' ) && wc_ship_to_billing_address_only(),
	'version'      => defined( 'AKIBARA_CART_CHECKOUT_BLOCKS_VERSION' ) ? AKIBARA_CART_CHECKOUT_BLOCKS_VERSION : 'sin plugin',
	'desde'        => $akb_desde ? gmdate( 'Y-m-d H:i', $akb_desde ) . ' UTC' : 'sin fecha',
);

/**
 * Compara dos direcciones en los campos que deciden la cotización y la entrega.
 *
 * @param array<string,string> $a Dirección A.
 * @param array<string,string> $b Dirección B.
 */
$akb_igual = static function ( array $a, array $b ) use ( $akb_lugar ): bool {
	foreach ( $akb_lugar as $campo ) {
		if ( trim( (string) ( $a[ $campo ] ?? '' ) ) !== trim( (string) ( $b[ $campo ] ?? '' ) ) ) {
			return false;
		}
	}
	return true;
};
$akb_llena = static function ( array $a ) use ( $akb_completa ): bool {
	foreach ( $akb_completa as $campo ) {
		if ( '' === trim( (string) ( $a[ $campo ] ?? '' ) ) ) {
			return false;
		}
	}
	return true;
};

// 1. Cuentas.
$akb_claves = array( 'last_update' );
foreach ( $akb_lugar as $campo ) {
	$akb_claves[] = 'billing_' . $campo;
	$akb_claves[] = 'shipping_' . $campo;
}
$akb_filas   = $wpdb->get_results(
	"SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('" . implode( "','", array_map( 'esc_sql', $akb_claves ) ) . "')",
	ARRAY_A
);
$akb_cuentas = array();
foreach ( $akb_filas as $fila ) {
	$uid = (int) $fila['user_id'];
	if ( 'last_update' === $fila['meta_key'] ) {
		$akb_cuentas[ $uid ]['last_update'] = (int) $fila['meta_value'];
		continue;
	}
	list( $tipo, $campo ) = explode( '_', $fila['meta_key'], 2 );
	$akb_cuentas[ $uid ][ $tipo ][ $campo ] = (string) $fila['meta_value'];
}
$akb_c = array( 'con_facturacion' => 0, 'envio_incompleto' => 0, 'envio_distinto' => 0, 'desalineadas_tras_despliegue' => 0 );
$akb_ids_nuevas = array();
foreach ( $akb_cuentas as $uid => $cuenta ) {
	$facturacion = $cuenta['billing'] ?? array();
	$envio       = $cuenta['shipping'] ?? array();
	if ( ! $akb_llena( $facturacion ) ) {
		continue;
	}
	++$akb_c['con_facturacion'];
	if ( $akb_igual( $facturacion, $envio ) ) {
		continue;
	}
	$akb_llena( $envio ) ? ++$akb_c['envio_distinto'] : ++$akb_c['envio_incompleto'];
	if ( $akb_desde && (int) ( $cuenta['last_update'] ?? 0 ) > $akb_desde ) {
		++$akb_c['desalineadas_tras_despliegue'];
		$akb_ids_nuevas[] = $uid;
	}
}
$akb_informe['cuentas']                     = $akb_c;
$akb_informe['cuentas_nuevas_desalineadas'] = array_slice( $akb_ids_nuevas, 0, 10 );

// 2. Sesiones vivas con carrito.
$akb_s     = array( 'con_carrito' => 0, 'facturacion_completa' => 0, 'desalineadas_activas' => 0, 'completas_sin_blue' => 0 );
$akb_desde_actividad = $akb_desde ? $akb_desde + 2 * DAY_IN_SECONDS : 0; // La expiración se renueva a +48 h en cada visita.
foreach ( (array) $wpdb->get_results( "SELECT session_key, session_value, session_expiry FROM {$wpdb->prefix}woocommerce_sessions WHERE session_expiry > UNIX_TIMESTAMP()", ARRAY_A ) as $fila ) {
	$datos   = maybe_unserialize( $fila['session_value'] );
	$carrito = is_array( $datos ) ? maybe_unserialize( $datos['cart'] ?? '' ) : array();
	if ( empty( $carrito ) || ! is_array( $carrito ) ) {
		continue;
	}
	++$akb_s['con_carrito'];
	$cliente = maybe_unserialize( $datos['customer'] ?? '' );
	if ( ! is_array( $cliente ) ) {
		continue;
	}
	// La sesión guarda la facturación sin prefijo y el envío con `shipping_`.
	$facturacion = array();
	$envio       = array();
	foreach ( $akb_lugar as $campo ) {
		$facturacion[ $campo ] = (string) ( $cliente[ $campo ] ?? '' );
		$envio[ $campo ]       = (string) ( $cliente[ 'shipping_' . $campo ] ?? '' );
	}
	if ( ! $akb_llena( $facturacion ) ) {
		continue;
	}
	++$akb_s['facturacion_completa'];
	if ( ! $akb_igual( $facturacion, $envio ) && (int) $fila['session_expiry'] > $akb_desde_actividad ) {
		++$akb_s['desalineadas_activas'];
	}
	$tarifas = maybe_unserialize( $datos['shipping_for_package_0'] ?? '' );
	if ( is_array( $tarifas ) && isset( $tarifas['rates'] ) && $akb_llena( $envio ) ) {
		$con_blue = false;
		foreach ( array_keys( (array) $tarifas['rates'] ) as $rate_id ) {
			$con_blue = $con_blue || 0 === strpos( (string) $rate_id, 'bluex' );
		}
		if ( ! $con_blue ) {
			++$akb_s['completas_sin_blue'];
		}
	}
}
$akb_informe['sesiones'] = $akb_s;

// 3. Pedidos a domicilio de los últimos 7 días con envío distinto de la facturación.
$akb_pedidos = $wpdb->get_results(
	"SELECT o.id,
		MAX(IF(a.address_type='billing', CONCAT_WS('|', a.country, a.state, a.city, a.address_1, a.address_2), NULL)) AS facturacion,
		MAX(IF(a.address_type='shipping', CONCAT_WS('|', a.country, a.state, a.city, a.address_1, a.address_2), NULL)) AS envio
	FROM {$wpdb->prefix}wc_orders o
	JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id
	WHERE o.type = 'shop_order' AND o.date_created_gmt > UTC_TIMESTAMP() - INTERVAL 7 DAY
	AND o.status NOT IN ('wc-cancelled','wc-failed','trash','wc-checkout-draft')
	AND EXISTS (
		SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items i
		JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = i.order_item_id AND m.meta_key = 'method_id' AND m.meta_value = 'bluex-ex'
		WHERE i.order_id = o.id AND i.order_item_type = 'shipping'
		AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->prefix}woocommerce_order_itemmeta p
			WHERE p.order_item_id = i.order_item_id AND ( ( p.meta_key = '_is_pudo' AND p.meta_value = 'yes' ) OR p.meta_key = '_bluex_original_method' )
		)
	)
	GROUP BY o.id",
	ARRAY_A
);
$akb_p = array( 'domicilio_7_dias' => 0, 'envio_distinto' => 0 );
foreach ( (array) $akb_pedidos as $pedido ) {
	++$akb_p['domicilio_7_dias'];
	if ( (string) $pedido['facturacion'] !== (string) $pedido['envio'] ) {
		++$akb_p['envio_distinto'];
	}
}
$akb_informe['pedidos'] = $akb_p;

$akb_alertas = array();
if ( ! $akb_informe['billing_only'] ) {
	$akb_alertas[] = 'la tienda ya no fuerza el envío a la facturación: revisar si la regla sigue aplicando';
}
if ( $akb_c['desalineadas_tras_despliegue'] > 0 ) {
	$akb_alertas[] = $akb_c['desalineadas_tras_despliegue'] . ' cuenta(s) guardadas después del despliegue con envío ≠ facturación';
}
if ( $akb_s['desalineadas_activas'] > 0 ) {
	$akb_alertas[] = $akb_s['desalineadas_activas'] . ' caja(s) activas cotizando con un envío ≠ tarjeta';
}
if ( $akb_p['envio_distinto'] > 0 ) {
	$akb_alertas[] = $akb_p['envio_distinto'] . ' pedido(s) a domicilio con envío ≠ facturación en 7 días';
}
$akb_avisos = array();
if ( $akb_s['completas_sin_blue'] > 0 ) {
	$akb_avisos[] = $akb_s['completas_sin_blue'] . ' caja(s) con dirección completa y SIN tarifa Blue (¿Blue caído o comuna sin cobertura? ver wp_bluex_logs)';
}
$akb_informe['alertas'] = $akb_alertas;
$akb_informe['avisos']  = $akb_avisos;

echo wp_json_encode( $akb_informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
echo 'VEREDICTO: ' . ( $akb_alertas ? 'ALERTA' : ( $akb_avisos ? 'AVISO' : 'OK' ) ) . "\n";
