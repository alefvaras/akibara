<?php
/**
 * Siembra de pedidos/eventos del gate de correos. Todo reversible.
 * Uso: wp eval-file pedidos.php <subcomando> [args...]
 */

use Akibara\Business\Modules\OrderPreventa\MetaKeys;

global $wpdb;

$cmd = $args[0] ?? 'help';

/**
 * Crea un pedido bacs con los productos dados, para el usuario dado.
 *
 * @param int   $user_id  Cliente.
 * @param array $pids     Productos.
 * @return WC_Order
 */
function gate_order( int $user_id, array $pids ): WC_Order {
	$user  = get_user_by( 'id', $user_id );
	$order = wc_create_order( array( 'customer_id' => $user_id ) );

	foreach ( $pids as $pid ) {
		$product = wc_get_product( (int) $pid );
		$order->add_product( $product, 1 );
	}

	// Mismos hooks que dispara el checkout clásico de WooCommerce: el sello de
	// preventa lo pone el módulo, no este script.
	foreach ( $order->get_items() as $item ) {
		do_action( 'woocommerce_checkout_create_order_line_item', $item, '', array(), $order );
	}

	$order->set_address(
		array(
			'first_name' => 'QA',
			'last_name'  => 'Gate3',
			'email'      => $user->user_email,
			'phone'      => '+56900000000',
			'address_1'  => 'Av. Prueba 123',
			'city'       => 'Santiago',
			'state'      => 'RM',
			'postcode'   => '8320000',
			'country'    => 'CL',
		),
		'billing'
	);
	$order->set_address(
		array(
			'first_name' => 'QA',
			'last_name'  => 'Gate3',
			'address_1'  => 'Av. Prueba 123',
			'city'       => 'Santiago',
			'state'      => 'RM',
			'postcode'   => '8320000',
			'country'    => 'CL',
		),
		'shipping'
	);
	$order->set_payment_method( 'bacs' );
	$order->set_payment_method_title( 'Transferencia bancaria directa' );
	$order->update_meta_data( '_akb_gate3', 1 );
	$order->calculate_totals();

	// Hook del checkout clásico: acá OrderTagging pinta has_preventa y resumen.
	do_action( 'woocommerce_checkout_create_order', $order, array() );

	$order->save();

	return $order;
}

switch ( $cmd ) {

	case 'crear':
		// crear <user_id> <pid,pid> [estado]
		$uid    = (int) $args[1];
		$pids   = array_map( 'intval', explode( ',', (string) $args[2] ) );
		$order  = gate_order( $uid, $pids );
		echo 'order=' . $order->get_id() . ' total=' . $order->get_total()
			. ' has_preventa=' . var_export( $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ), true )
			. ' status=' . $order->get_status() . "\n";
		break;

	case 'estado':
		// estado <order_id> <nuevo>
		$o = wc_get_order( (int) $args[1] );
		$o->update_status( (string) $args[2], 'Gate3 QA: ' );
		echo 'status=' . $o->get_status() . "\n";
		break;

	case 'meta':
		$o = wc_get_order( (int) $args[1] );
		foreach ( $o->get_meta_data() as $m ) {
			$d = $m->get_data();
			if ( str_starts_with( (string) $d['key'], '_akb' ) ) {
				echo $d['key'] . ' = ' . ( is_scalar( $d['value'] ) ? $d['value'] : wp_json_encode( $d['value'] ) ) . "\n";
			}
		}
		echo 'status=' . $o->get_status() . "\n";
		break;

	case 'preventa-t7':
		do_action( 'akb_order_preventa_t7_reminder_notification', (int) $args[1] );
		echo "t7 disparado\n";
		break;

	case 'preventa-cambio-fecha':
		// preventa-cambio-fecha <order_id> <product_id> <old_ymd> <new_ymd>
		do_action( 'akb_order_preventa_date_changed_notification', (int) $args[1], (int) $args[2], (string) $args[3], (string) $args[4] );
		echo "cambio de fecha disparado\n";
		break;

	case 'preventa-ready':
		$o = wc_get_order( (int) $args[1] );
		$o->update_meta_data( MetaKeys::ORDER_READY_TO_SHIP, 'yes' );
		$o->update_meta_data( MetaKeys::ORDER_READY_DATE, gmdate( 'Y-m-d' ) );
		$o->save();
		// Hook propio del módulo: ReadyEmailDispatcher agenda el correo.
		do_action( 'akb_order_preventa_marked_ready', (int) $args[1] );
		echo "ready disparado (marked_ready)\n";
		break;

	case 'bis-suscribir':
		// bis-suscribir <product_id> <email> [user_id]
		$repo  = new \Akibara\Business\Modules\BackInStock\SubscriberRepository( $wpdb, \Akibara\Business\Modules\BackInStock\Install::table_name() );
		$token = wp_generate_password( 64, false, false );
		$id    = $repo->insert( (int) $args[1], (string) $args[2], isset( $args[3] ) ? (int) $args[3] : null, $token );
		echo "sub_id={$id} token={$token}\n";
		break;

	case 'bis-flip':
		// bis-flip <product_id> <instock|outofstock>
		$p   = wc_get_product( (int) $args[1] );
		$old = $p->get_stock_status();
		$p->set_stock_status( (string) $args[2] );
		$p->save();
		echo "producto={$args[1]} antes={$old} ahora=" . wc_get_product( (int) $args[1] )->get_stock_status() . "\n";
		break;

	case 'cumple':
		// cumple <user_id>
		$uid = (int) $args[1];
		update_user_meta( $uid, \Akibara\Rewards\Points\Birthday::META_MD, wp_date( 'm-d' ) );
		delete_user_meta( $uid, \Akibara\Rewards\Points\Birthday::META_PAID_YEAR );
		delete_user_meta( $uid, '_akb_rewards_birthday_email_year' );
		echo 'md=' . get_user_meta( $uid, \Akibara\Rewards\Points\Birthday::META_MD, true ) . "\n";
		break;

	case 'cumple-run':
		do_action( \Akibara\Rewards\Points\Birthday::HOOK );
		echo "corrida cumpleaños hecha\n";
		break;

	case 'vencimiento':
		// vencimiento <user_id> <puntos> <dias>
		do_action( 'akb_rewards_expiry_warning', (int) $args[1], (int) $args[2], (int) $args[3] );
		echo "aviso de vencimiento disparado\n";
		break;

	case 'ledger':
		// ledger <user_id> <puntos> <dias_atras>
		$uid = (int) $args[1];
		$wpdb->insert(
			$wpdb->prefix . 'akb_rewards_ledger',
			array(
				'user_id'    => $uid,
				'points'     => (int) $args[2],
				'type'       => 'gate3',
				'order_id'   => 0,
				'note'       => 'akb-gate3',
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( (int) $args[3] ) * DAY_IN_SECONDS ),
			)
		);
		echo 'ledger_id=' . $wpdb->insert_id . ' saldo=' . \Akibara\Rewards\Points\Ledger::balance( $uid ) . "\n";
		break;

	case 'invitacion-ya':
		// invitacion-ya <order_id> — ejecuta el job de invitación ahora
		do_action( \Akibara\Rewards\Reviews\Invitations::HOOK, (int) $args[1] );
		echo "invitación disparada\n";
		break;

	case 'resena':
		// resena <product_id> <user_id> — crea reseña con foto, sin aprobar.
		$pid  = (int) $args[1];
		$uid  = (int) $args[2];
		$user = get_user_by( 'id', $uid );
		$cid  = wp_insert_comment(
			array(
				'comment_post_ID'      => $pid,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_content'      => 'Reseña de prueba del gate de correos: la edición se ve impecable y llegó en dos días. Recomendado para quien parte la serie.',
				'comment_type'         => 'review',
				'user_id'              => $uid,
				'comment_approved'     => 0,
			)
		);
		update_comment_meta( (int) $cid, 'rating', 5 );
		update_comment_meta( (int) $cid, 'verified', 1 );
		update_comment_meta( (int) $cid, 'akb_gate3', 1 );
		// Foto real: se reutiliza la imagen destacada del propio producto.
		$att = (int) get_post_thumbnail_id( $pid );
		update_comment_meta( (int) $cid, \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE, $att );
		echo "comment={$cid} attachment={$att} foto=" . var_export( \Akibara\Rewards\Reviews\Media::has_real_photo( (int) $cid ), true ) . "\n";
		break;

	case 'aprobar-resena':
		wp_set_comment_status( (int) $args[1], 'approve' );
		echo 'estado=' . wp_get_comment_status( (int) $args[1] ) . "\n";
		break;

	case 'as-pendientes':
		$rows = $wpdb->get_results(
			"SELECT a.action_id, g.slug AS grupo, a.hook, a.status, a.scheduled_date_gmt, a.args
			 FROM {$wpdb->prefix}actionscheduler_actions a
			 LEFT JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id
			 WHERE a.status='pending' AND (a.hook LIKE '%akb%' OR a.hook LIKE '%rewards%')
			 ORDER BY a.action_id DESC LIMIT 25",
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			echo implode( ' | ', array_map( 'strval', $r ) ) . "\n";
		}
		echo 'total=' . count( $rows ) . "\n";
		break;

	default:
		echo "subcomandos: crear estado meta preventa-t7 preventa-cambio-fecha preventa-ready bis-suscribir bis-flip cumple cumple-run vencimiento ledger invitacion-ya as-pendientes\n";
}
