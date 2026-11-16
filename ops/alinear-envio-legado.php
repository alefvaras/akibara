<?php
/**
 * Alinea envío = facturación en las cuentas anteriores a checkout-blocks 2.2.31.
 *
 * Con `billing_only` el envío de la cuenta nunca se usa para despachar (el pedido
 * sale a la tarjeta; el punto Blue vive en el pedido), pero una copia vieja sí se
 * ve: «Cargar dirección de envío» en un pedido manual del panel, exportaciones y el
 * centinela. 2.2.31 alinea cada cuenta al guardarse; esto alinea las que no se han
 * vuelto a guardar.
 *
 * Escribe user meta DIRECTO a propósito: sin `WC_Customer::save()` no se disparan
 * `woocommerce_update_customer`, webhooks ni sincronizaciones con terceros.
 *
 * Lo llama ops/alinear-envio-legado.sh:
 *   wp eval-file <este> listar
 *   wp eval-file <este> respaldo            → JSON {uid: {shipping_x: valor|null}}
 *   wp eval-file <este> aplicar
 *   wp eval-file <este> restaurar <json>    → deja cada clave como estaba (null = no existía)
 *
 * @package Akibara\Ops
 */

defined( 'ABSPATH' ) || exit;

$akb_modo   = isset( $args[0] ) ? (string) $args[0] : 'listar';
$akb_partes = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );
$akb_lugar  = array( 'country', 'state', 'city', 'address_1', 'address_2' );

if ( ! function_exists( 'wc_ship_to_billing_address_only' ) || ! wc_ship_to_billing_address_only() ) {
	WP_CLI::error( 'la tienda no fuerza el envío a la facturación: no hay nada que alinear' );
}

/**
 * Cuentas con facturación completa cuyo envío difiere en el lugar de entrega.
 *
 * @return int[]
 */
$akb_pendientes = static function () use ( $akb_lugar ): array {
	global $wpdb;
	$claves = array();
	foreach ( $akb_lugar as $parte ) {
		$claves[] = 'billing_' . $parte;
		$claves[] = 'shipping_' . $parte;
	}
	$filas   = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('" . implode( "','", $claves ) . "')", ARRAY_A );
	$cuentas = array();
	foreach ( $filas as $fila ) {
		$cuentas[ (int) $fila['user_id'] ][ $fila['meta_key'] ] = trim( (string) $fila['meta_value'] );
	}
	$ids = array();
	foreach ( $cuentas as $uid => $meta ) {
		foreach ( array( 'country', 'state', 'city', 'address_1' ) as $parte ) {
			if ( '' === ( $meta[ 'billing_' . $parte ] ?? '' ) ) {
				continue 2;
			}
		}
		foreach ( $akb_lugar as $parte ) {
			if ( ( $meta[ 'billing_' . $parte ] ?? '' ) !== ( $meta[ 'shipping_' . $parte ] ?? '' ) ) {
				$ids[] = (int) $uid;
				continue 2;
			}
		}
	}
	sort( $ids );
	return $ids;
};

switch ( $akb_modo ) {
	case 'listar':
		$ids = $akb_pendientes();
		echo wp_json_encode( array( 'pendientes' => count( $ids ), 'ids' => $ids ) ) . "\n";
		break;

	case 'respaldo':
		$copia = array();
		foreach ( $akb_pendientes() as $uid ) {
			foreach ( $akb_partes as $parte ) {
				$valor = metadata_exists( 'user', $uid, 'shipping_' . $parte ) ? (string) get_user_meta( $uid, 'shipping_' . $parte, true ) : null;
				$copia[ $uid ][ 'shipping_' . $parte ] = $valor;
			}
		}
		echo wp_json_encode( $copia, JSON_UNESCAPED_UNICODE ) . "\n";
		break;

	case 'aplicar':
		$hechas = 0;
		foreach ( $akb_pendientes() as $uid ) {
			foreach ( $akb_partes as $parte ) {
				update_user_meta( $uid, 'shipping_' . $parte, (string) get_user_meta( $uid, 'billing_' . $parte, true ) );
			}
			++$hechas;
		}
		echo wp_json_encode( array( 'alineadas' => $hechas, 'quedan' => count( $akb_pendientes() ) ) ) . "\n";
		break;

	case 'restaurar':
		$archivo = isset( $args[1] ) ? (string) $args[1] : '';
		$copia   = json_decode( (string) file_get_contents( $archivo ), true );
		if ( ! is_array( $copia ) ) {
			WP_CLI::error( 'respaldo ilegible: ' . $archivo );
		}
		$restauradas = 0;
		foreach ( $copia as $uid => $claves ) {
			foreach ( (array) $claves as $clave => $valor ) {
				if ( 0 !== strpos( (string) $clave, 'shipping_' ) ) {
					continue;
				}
				null === $valor ? delete_user_meta( (int) $uid, $clave ) : update_user_meta( (int) $uid, $clave, (string) $valor );
			}
			++$restauradas;
		}
		echo wp_json_encode( array( 'restauradas' => $restauradas ) ) . "\n";
		break;

	default:
		WP_CLI::error( 'modo desconocido: ' . $akb_modo . ' (listar|respaldo|aplicar|restaurar)' );
}
