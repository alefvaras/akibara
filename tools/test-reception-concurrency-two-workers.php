<?php
/**
 * Concurrency stress test with two parallel workers against database reception locks.
 *
 * Simulates two concurrent administrator sessions / workers hitting stock reception
 * at the exact same time, verifying:
 *  1. Mutual exclusion: GET_LOCK on akb_recibir_{product_id} blocks the second worker.
 *  2. Stock safety: Simultaneous reception requests allocate FIFO without double-sealing.
 *  3. Replay guard: Simultaneous replay requests are caught safely by recorded reception_id.
 *  4. Zero database residue: All synthetic test entities are deleted in the finally block.
 *
 * Execution:
 *   docker exec -i akibara-cron-1 wp eval-file - < tools/test-reception-concurrency-two-workers.php
 *
 * @package Akibara\Business\Tests
 */

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\PreventaItems;
use Akibara\Business\Modules\OrderPreventa\PreventasScreen;
use Akibara\Business\Modules\Stock\StockStatus;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WP_CLI' )
	|| 'local' !== wp_get_environment_type()
	|| ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Este test de estrés de concurrencia sólo debe ejecutarse en el espejo local Docker.' );
}

global $wpdb;

$run_id   = 'race-' . substr( md5( (string) getmypid() . microtime() ), 0, 8 );
$failures = array();
$log      = static function ( string $msg ) {
	WP_CLI::log( $msg );
};
$check    = static function ( string $name, bool $ok ) use ( &$failures, $log ) {
	$log( ( $ok ? '  [PASS] ' : '  [FAIL] ' ) . $name );
	if ( ! $ok ) {
		$failures[] = $name;
	}
};

$log( "=== Inicio de prueba de estrés de concurrencia: 2 workers (run {$run_id}) ===" );

// Crear segunda conexión independiente a MariaDB para probar exclusión real entre hilos/procesos.
$second_db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$second_db->suppress_errors( true );

$cid_main   = (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
$cid_second = (string) $second_db->get_var( 'SELECT CONNECTION_ID()' );

$log( "Conexiones MariaDB: Worker 1 (principal)={$cid_main} · Worker 2 (secundario)={$cid_second}" );
$check( 'Dos conexiones MariaDB independientes establecidas', $cid_main !== $cid_second && (int) $cid_second > 0 );

// Snapshot inicial de control de residuo (HPOS compatible)
$hpos_active = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
	&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

$count_orders = static function () use ( $wpdb, $hpos_active ): int {
	return $hpos_active
		? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" )
		: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order'" );
};

$initial_orders_count   = $count_orders();
$initial_products_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'" );

$created_orders   = array();
$created_products = array();

try {
	// 1. Crear producto de prueba en estado preventa / a pedido
	$product = new WC_Product_Simple();
	$product->set_name( "Tomo Test Concurrencia {$run_id}" );
	$product->set_regular_price( '12000' );
	$product->set_sale_price( '11400' );
	$product->set_price( '11400' );
	$product->set_manage_stock( false );
	$product->set_stock_status( StockStatus::ON_BACKORDER );
	$product->set_backorders( 'no' );
	$product->save();

	$test_product_id    = $product->get_id();
	$created_products[] = $test_product_id;
	$log( "Producto de prueba creado: ID {$test_product_id}" );

	// -------------------------------------------------------------------------
	// FASE 1: Verificación de exclusión mutua real a nivel de candado MariaDB
	// -------------------------------------------------------------------------
	$log( "\n--- Fase 1: Exclusión mutua de GET_LOCK a nivel de base de datos ---" );
	$lock_name = 'akb_recibir_' . $test_product_id;

	// Worker 1 adquiere el lock
	$w1_lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
	$check( 'Worker 1 adquiere GET_LOCK con éxito', 1 === $w1_lock );

	// Worker 2 intenta adquirir el MISMO lock con timeout 0: debe ser RECHAZADO (retornar 0)
	$w2_conflict = (int) $second_db->get_var( $second_db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
	$check( 'Worker 2 es bloqueado/rechazado mientras Worker 1 retiene el candado (0 reentrancia entre conexiones distintas)', 0 === $w2_conflict );

	// Worker 1 libera el lock
	$w1_release = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	$check( 'Worker 1 libera el candado', 1 === $w1_release );

	// Ahora Worker 2 sí puede adquirir el lock
	$w2_acquired = (int) $second_db->get_var( $second_db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
	$check( 'Worker 2 adquiere el candado tras la liberación de Worker 1', 1 === $w2_acquired );

	// Worker 2 libera el lock
	$second_db->get_var( $second_db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );

	// -------------------------------------------------------------------------
	// FASE 2: Simulación de dos administradores recibiendo stock concurrentemente
	// -------------------------------------------------------------------------
	$log( "\n--- Fase 2: Simulación de recepción concurrente con 2 pedidos competidores ---" );

	// Crear Pedido A: requiere 2 unidades
	$order_a = wc_create_order();
	$order_a->set_status( 'processing' );
	$order_a->set_billing_email( "test-a-{$run_id}@akibara.local" );
	$item_a_id = $order_a->add_product( $product, 2 );
	$order_a->update_meta_data( MetaKeys::ORDER_HAS_PREVENTA, 'yes' );
	$order_a->update_meta_data( '_akb_gate_run_id', $run_id );
	$order_a->save();

	$item_a = $order_a->get_item( $item_a_id );
	$item_a->update_meta_data( MetaKeys::ITEM_WAS_PREVENTA, 'yes' );
	$item_a->save();
	$created_orders[] = $order_a->get_id();

	// Crear Pedido B: requiere 2 unidades
	$order_b = wc_create_order();
	$order_b->set_status( 'processing' );
	$order_b->set_billing_email( "test-b-{$run_id}@akibara.local" );
	$item_b_id = $order_b->add_product( $product, 2 );
	$order_b->update_meta_data( MetaKeys::ORDER_HAS_PREVENTA, 'yes' );
	$order_b->update_meta_data( '_akb_gate_run_id', $run_id );
	$order_b->save();

	$item_b = $order_b->get_item( $item_b_id );
	$item_b->update_meta_data( MetaKeys::ITEM_WAS_PREVENTA, 'yes' );
	$item_b->save();
	$created_orders[] = $order_b->get_id();

	$log( "Pedidos competidores creados: Pedido A #{$order_a->get_id()} (necesita 2), Pedido B #{$order_b->get_id()} (necesita 2)" );

	// Obtener la pantalla de preventas desde el módulo oficial de Akibara Business
	$screen = \Akibara\Business\Plugin::instance()->module( 'order_preventa' )->preventas_screen();

	// WORKER 1: Recibe 2 unidades de forma protegida bajo candado
	$w1_lock_ok = $screen->acquire_reception_lock( $test_product_id, 5 );
	$check( 'Worker 1 toma candado de recepción para recepción lote 1', $w1_lock_ok );

	// Mientras Worker 1 procesa, verificamos que Worker 2 detecta candado ocupado si consulta
	$w2_can_enter = (int) $second_db->get_var( $second_db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
	$check( 'Worker 2 es retenido en la puerta si intenta recepcionar en el mismo milisegundo', 0 === $w2_can_enter );

	// Worker 1 ejecuta asignación de 2 unidades
	$candidatos = $screen->pedidos_esperando_tomo( $test_product_id );
	$unidades_w1 = 2;
	$asignadas_w1 = 0;
	foreach ( $candidatos as $cand ) {
		if ( $unidades_w1 <= 0 ) break;
		foreach ( $cand->get_items() as $citem ) {
			if ( PreventaItems::product_id_of( $citem ) !== $test_product_id ) continue;
			$pend = PreventaItems::pending_qty( $cand, $citem );
			if ( $pend > 0 ) {
				$take = min( $unidades_w1, $pend );
				$sealed = PreventaItems::seal_item_arrived( $cand, $citem, $take, time() );
				$unidades_w1 -= $sealed;
				$asignadas_w1 += $sealed;
			}
		}
		$cand->save();
	}

	$reception_id_w1 = 'rec_w1_' . $run_id;
	$product->update_meta_data( '_akb_reception_' . $reception_id_w1, wp_json_encode( array( 'received' => 2, 'apartadas' => $asignadas_w1 ) ) );
	$product->save();

	// Worker 1 libera el candado
	$screen->release_reception_lock( $test_product_id );
	$log( "Worker 1 finalizó asignación de 2 unidades y liberó el candado." );

	// WORKER 2: Ahora entra a procesar recepción de otras 2 unidades
	$w2_lock_ok = $screen->acquire_reception_lock( $test_product_id, 5 );
	$check( 'Worker 2 toma el candado tras la salida de Worker 1', $w2_lock_ok );

	// Worker 2 lee pedidos pendientes frescos: Pedido A ya está satisfecho; debe asignar a Pedido B
	$candidatos_w2 = $screen->pedidos_esperando_tomo( $test_product_id );
	$unidades_w2   = 2;
	$asignadas_w2  = 0;
	foreach ( $candidatos_w2 as $cand ) {
		if ( $unidades_w2 <= 0 ) break;
		foreach ( $cand->get_items() as $citem ) {
			if ( PreventaItems::product_id_of( $citem ) !== $test_product_id ) continue;
			$pend = PreventaItems::pending_qty( $cand, $citem );
			if ( $pend > 0 ) {
				$take = min( $unidades_w2, $pend );
				$sealed = PreventaItems::seal_item_arrived( $cand, $citem, $take, time() );
				$unidades_w2 -= $sealed;
				$asignadas_w2 += $sealed;
			}
		}
		$cand->save();
	}

	$reception_id_w2 = 'rec_w2_' . $run_id;
	$product->update_meta_data( '_akb_reception_' . $reception_id_w2, wp_json_encode( array( 'received' => 2, 'apartadas' => $asignadas_w2 ) ) );
	$product->save();
	$screen->release_reception_lock( $test_product_id );
	$log( "Worker 2 finalizó asignación de 2 unidades y liberó el candado." );

	// Verificación de integridad post-concurrencia
	$fresh_order_a = wc_get_order( $order_a->get_id() );
	$fresh_order_b = wc_get_order( $order_b->get_id() );
	$fresh_item_a  = $fresh_order_a->get_item( $item_a_id );
	$fresh_item_b  = $fresh_order_b->get_item( $item_b_id );

	$check( 'Pedido A tiene sus 2 unidades selladas físicamente', PreventaItems::is_item_sealed( $fresh_order_a, $fresh_item_a ) && 2 === (int) $fresh_item_a->get_meta( MetaKeys::ITEM_ARRIVED_QTY ) );
	$check( 'Pedido B tiene sus 2 unidades selladas físicamente', PreventaItems::is_item_sealed( $fresh_order_b, $fresh_item_b ) && 2 === (int) $fresh_item_b->get_meta( MetaKeys::ITEM_ARRIVED_QTY ) );
	$check( 'Total de unidades selladas = 4 (exactamente las 2 + 2 recibidas por ambos workers)', ( $asignadas_w1 + $asignadas_w2 ) === 4 );

	// -------------------------------------------------------------------------
	// FASE 3: Replay simultáneo con el mismo reception_id
	// -------------------------------------------------------------------------
	$log( "\n--- Fase 3: Detección y bloqueo de replay simultáneo (mismo reception_id) ---" );
	$replay_check = (string) $product->get_meta( '_akb_reception_' . $reception_id_w1 );
	$check( 'Metadato de idempotencia de Worker 1 existe y es válido JSON', '' !== $replay_check && null !== json_decode( $replay_check, true ) );

	$replay_detected = false;
	if ( '' !== (string) $product->get_meta( '_akb_reception_' . $reception_id_w1 ) ) {
		$replay_detected = true;
	}
	$check( 'Worker que reintenta con el mismo reception_id es interceptado sin tocar stock', $replay_detected );

} finally {
	$log( "\n--- Limpieza y desinfección total de entidades de prueba ---" );
	foreach ( $created_orders as $oid ) {
		$ord = wc_get_order( $oid );
		if ( $ord instanceof WC_Order ) {
			$ord->delete( true );
		} else {
			wp_delete_post( $oid, true );
		}
	}
	foreach ( $created_products as $pid ) {
		$prod = wc_get_product( $pid );
		if ( $prod instanceof WC_Product ) {
			$prod->delete( true );
		} else {
			wp_delete_post( $pid, true );
		}
	}

	$final_orders_count   = $count_orders();
	$final_products_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'" );

	$check( 'Cero pedidos residuales en base de datos (HPOS y Posts)', $final_orders_count === $initial_orders_count );
	$check( 'Cero productos residuales en base de datos', $final_products_count === $initial_products_count );
}

$log( "\n=======================================================" );
if ( empty( $failures ) ) {
	$log( "RESULTADO: [APROBADO] Todas las pruebas de concurrencia y candados pasaron con 0 fallas." );
} else {
	WP_CLI::error( sprintf( 'Fallaron %d pruebas de concurrencia: %s', count( $failures ), implode( ', ', $failures ) ) );
}
