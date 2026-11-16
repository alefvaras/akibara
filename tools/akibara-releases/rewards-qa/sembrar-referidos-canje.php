<?php
/**
 * Siembra del ESPEJO para qa-referidos-canje-alertas.mjs (rewards 0.4.1).
 *
 * Uso (WP-CLI lee el código por STDIN; nada se copia al docroot):
 *   docker compose exec -T cron wp eval-file - <modo> [args] < tools/akibara-releases/rewards-qa/sembrar-referidos-canje.php
 *
 * Modos: sembrar · usuario-por-correo <correo> · compra <user_id> · saldo <user_id> <puntos>
 *        estado <user_id> · pedido <id> · alerta · invitacion · limpiar
 *
 * Todo lo que crea se reconoce por login fijo o por el dominio @akibara.invalid,
 * y `limpiar` lo retira contando: usuarios, pedidos, filas del libro de puntos,
 * options tocadas y el stock de los tomos usados.
 *
 * @package Akibara\Rewards\QA
 */

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Referrals;
use Akibara\Rewards\Reviews\Invitations;
use Akibara\Rewards\Support\Options;

$modo    = $args[0] ?? 'sembrar';
$memoria = 'akb_qa_ref_canje';
$marca   = '_akb_qa_ref_canje';

$salir = static function ( array $datos ): void {
	echo wp_json_encode( $datos, JSON_UNESCAPED_UNICODE ) . PHP_EOL;
};

$rut = static function ( int $cuerpo ): string {
	$suma = 0;
	$mult = 2;
	for ( $c = $cuerpo; $c > 0; $c = intdiv( $c, 10 ) ) {
		$suma += ( $c % 10 ) * $mult;
		$mult  = 7 === $mult ? 2 : $mult + 1;
	}
	$dv = 11 - ( $suma % 11 );
	$dv = 11 === $dv ? '0' : ( 10 === $dv ? 'K' : (string) $dv );

	return number_format( $cuerpo, 0, '', '.' ) . '-' . $dv;
};

$usuario = static function ( string $login, string $clave, string $rol, array $extra = array() ): int {
	$user = get_user_by( 'login', $login );
	$id   = $user ? (int) $user->ID : (int) wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => $clave,
			'user_email' => $login . '@akibara.invalid',
			'role'       => $rol,
			'first_name' => 'QA',
			'last_name'  => ucfirst( substr( $login, 3 ) ),
		)
	);
	wp_set_password( $clave, $id );
	foreach ( $extra as $clave_meta => $valor ) {
		update_user_meta( $id, $clave_meta, $valor );
	}
	return $id;
};

$fijar_saldo = static function ( int $user_id, int $puntos ): int {
	$saldo = Ledger::balance( $user_id );
	if ( $saldo > $puntos ) {
		Ledger::debit( $user_id, $saldo - $puntos, 'admin_adjust', 'user', $user_id, 'QA: otro equipo gastó el saldo', false, 'qa_saldo_' . $user_id . '_' . microtime( true ) );
	} elseif ( $saldo < $puntos ) {
		Ledger::credit( $user_id, $puntos - $saldo, 'admin_adjust', 'user', $user_id, 'QA: saldo de prueba', true, 'qa_saldo_' . $user_id . '_' . microtime( true ) );
	}
	return Ledger::balance( $user_id );
};

$pedido_qa = static function ( int $customer_id, string $email, string $telefono, string $rut_pedido, \WC_Product $producto ) use ( $marca ): \WC_Order {
	$order = wc_create_order( array( 'customer_id' => $customer_id ) );
	$order->add_product( $producto, 1 );
	$order->set_address(
		array(
			'first_name' => 'QA',
			'last_name'  => 'Referidos',
			'email'      => $email,
			'phone'      => $telefono,
			'address_1'  => 'Av. QA 123',
			'city'       => 'Santiago',
			'state'      => 'CL-RM',
			'country'    => 'CL',
		),
		'billing'
	);
	$order->update_meta_data( '_billing_rut', $rut_pedido );
	$order->update_meta_data( $marca, 1 );
	$order->set_payment_method( 'bacs' );
	$order->calculate_totals();
	$order->save();
	return $order;
};

switch ( $modo ) {
	case 'sembrar':
		$memo = (array) get_option( $memoria, array() );
		if ( ! isset( $memo['options'] ) ) {
			$memo['options'] = array();
			foreach ( array( 'akb_rewards_invite_status', 'akb_rewards_nudge_email_ids', 'akb_rewards_alerts_email', 'akb_rewards_alerts_enabled' ) as $opt ) {
				$memo['options'][ $opt ] = get_option( $opt, null );
			}
		}

		$dueno   = $usuario( 'qa.dueno', 'AkbQaDueno2026!', 'administrator' );
		$padrino = $usuario( 'qa.padrino', 'AkbQaPadrino2026!', 'customer', array( 'billing_phone' => '+56911110000', 'billing_rut' => $rut( 11111111 ) ) );
		$canje   = $usuario( 'qa.canje', 'AkbQaCanje2026!', 'customer' );
		$fijar_saldo( $canje, 3000 );

		// Tomos para superar el carrito mínimo de canje, con su stock anotado
		// para devolverlo al limpiar.
		$minimo = Options::redeem_min_cart();
		$sobre  = array();
		$suma   = 0.0;
		$memo['stock'] = $memo['stock'] ?? array();
		foreach ( wc_get_products( array( 'limit' => 300, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'DESC', 'type' => 'simple' ) ) as $producto ) {
			if ( ! $producto->is_purchasable() || ! $producto->is_in_stock() || (float) $producto->get_price() <= 0 ) {
				continue;
			}
			if ( ! isset( $memo['stock'][ $producto->get_id() ] ) ) {
				$memo['stock'][ $producto->get_id() ] = array( $producto->get_manage_stock(), $producto->get_stock_quantity(), $producto->get_stock_status() );
			}
			// Dos compras simultáneas del mismo carrito necesitan dos unidades:
			// mientras dura la prueba el tomo no lleva stock (se devuelve al limpiar).
			$producto->set_manage_stock( false );
			$producto->set_stock_status( 'instock' );
			$producto->save();
			$sobre[] = array( 'id' => $producto->get_id(), 'precio' => (float) $producto->get_price(), 'url' => get_permalink( $producto->get_id() ) );
			$suma   += (float) $producto->get_price();
			if ( $suma >= $minimo * 1.2 ) {
				break;
			}
		}
		update_option( $memoria, $memo, false );

		$salir(
			array(
				'dueno'    => array( 'id' => $dueno, 'login' => 'qa.dueno', 'clave' => 'AkbQaDueno2026!' ),
				'padrino'  => array( 'id' => $padrino, 'login' => 'qa.padrino', 'clave' => 'AkbQaPadrino2026!', 'codigo' => Referrals::code_for( $padrino ), 'saldo' => Ledger::balance( $padrino ) ),
				'canje'    => array( 'id' => $canje, 'login' => 'qa.canje', 'clave' => 'AkbQaCanje2026!', 'email' => 'qa.canje@akibara.invalid', 'saldo' => Ledger::balance( $canje ) ),
				'invitada' => 'qa.invitada.' . time() . '@akibara.invalid',
				'sobre'    => $sobre,
				'suma'     => $suma,
				'min_cart' => $minimo,
				'admin'    => get_option( 'admin_email' ),
			)
		);
		break;

	case 'usuario-por-correo':
		$user = get_user_by( 'email', (string) ( $args[1] ?? '' ) );
		$salir(
			array(
				'id'           => $user ? (int) $user->ID : 0,
				'referido_por' => $user ? (int) get_user_meta( $user->ID, Referrals::META_REFERRED_BY, true ) : 0,
			)
		);
		break;

	case 'compra':
		$uid      = (int) ( $args[1] ?? 0 );
		$user     = get_user_by( 'id', $uid );
		$memo     = (array) get_option( $memoria, array() );
		$producto = wc_get_product( (int) array_key_first( (array) ( $memo['stock'] ?? array() ) ) );
		$order    = $pedido_qa( $uid, (string) $user->user_email, '+5699' . wp_rand( 1000000, 9999999 ), $rut( wp_rand( 20000000, 24999999 ) ), $producto );
		$padrino  = (int) get_user_meta( $uid, Referrals::META_REFERRED_BY, true );
		$antes    = Ledger::balance( $padrino );
		$order->update_status( 'processing', 'QA referidos. ' );
		$order->update_status( 'completed', 'QA referidos. ' );
		$salir(
			array(
				'pedido'        => (int) $order->get_id(),
				'padrino'       => $padrino,
				'saldo_antes'   => $antes,
				'saldo_despues' => Ledger::balance( $padrino ),
				'premio_vivo'   => (int) get_user_meta( $uid, Referrals::META_REFERRAL_ORDER, true ),
				'notas'         => array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ),
			)
		);
		break;

	case 'saldo':
		$salir( array( 'saldo' => $fijar_saldo( (int) $args[1], (int) $args[2] ) ) );
		break;

	case 'estado':
		$uid = (int) $args[1];
		wp_cache_delete( $uid, 'user_meta' );
		$salir( array( 'saldo' => Ledger::balance( $uid ) ) );
		break;

	case 'pedido':
		$order = wc_get_order( (int) $args[1] );
		$fees  = array();
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$fees[] = array( $fee->get_name(), (float) $fee->get_total() + (float) $fee->get_total_tax() );
		}
		$salir(
			array(
				'estado'   => $order->get_status(),
				'total'    => (float) $order->get_total(),
				'canjeado' => (int) $order->get_meta( '_akb_rewards_redeemed_points' ),
				'valor'    => (float) $order->get_meta( '_akb_rewards_redeemed_value' ),
				'devuelto' => (int) $order->get_meta( '_akb_rewards_redeemed_restored' ),
				'deuda'    => (int) $order->get_meta( '_akb_rewards_redeemed_revival_debt' ),
				'pagar'    => $order->get_checkout_payment_url(),
				'reemplaza' => array_values( array_filter( array_map( 'intval', (array) $order->get_meta( '_akb_rewards_supersedes' ) ) ) ),
				'rechazado' => (int) $order->get_meta( '_akb_rewards_payment_rejected' ) > 0,
				'ventana'   => (int) $order->get_meta( '_akb_rewards_pay_window_until' ),
				'fees'     => $fees,
				'notas'    => array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ),
			)
		);
		break;

	case 'estado-pedido':
		// Lo que haría un webhook de la pasarela: cambio de estado con sus ganchos.
		$order = wc_get_order( (int) $args[1] );
		$order->update_status( (string) $args[2], 'QA: cambio de estado simulado.' );
		wp_cache_delete( (int) $order->get_customer_id(), 'user_meta' );
		$salir( array( 'estado' => $order->get_status(), 'saldo' => Ledger::balance( (int) $order->get_customer_id() ) ) );
		break;

	case 'opcion':
		// Palancas de la herramienta de QA: 0 o vacío la retira.
		if ( in_array( (string) $args[1], array( 'akb_qa_recargo', 'akb_qa_fallar_tras_crear' ), true ) ) {
			empty( $args[2] ) ? delete_option( (string) $args[1] ) : update_option( (string) $args[1], (string) $args[2], false );
		}
		$salir( array( 'valor' => get_option( (string) $args[1], '' ) ) );
		break;

	case 'reintento':
		// La acción agendada del canje, sin esperar la cola. Con motivo
		// («impago»), la revisión de la ventana de pago.
		if ( ! empty( $args[2] ) ) {
			do_action( 'akb_rewards_redemption_retry', (int) $args[1], (string) $args[2] );
		} else {
			do_action( 'akb_rewards_redemption_retry', (int) $args[1] );
		}
		$order = wc_get_order( (int) $args[1] );
		wp_cache_delete( (int) $order->get_customer_id(), 'user_meta' );
		$salir( array( 'saldo' => Ledger::balance( (int) $order->get_customer_id() ) ) );
		break;

	case 'pedidos':
		$lista = array();
		foreach ( wc_get_orders( array( 'customer_id' => (int) $args[1], 'limit' => -1, 'status' => array_keys( wc_get_order_statuses() ) ) ) as $o ) {
			$fee = 0.0;
			foreach ( $o->get_items( 'fee' ) as $item ) {
				if ( 'Puntos Akibara' === $item->get_name() ) {
					$fee += -( (float) $item->get_total() + (float) $item->get_total_tax() );
				}
			}
			$lista[] = array(
				'id'       => (int) $o->get_id(),
				'estado'   => $o->get_status(),
				'fee'      => (int) round( $fee ),
				'canjeado' => (int) $o->get_meta( '_akb_rewards_redeemed_points' ),
			);
		}
		$salir( array( 'pedidos' => $lista ) );
		break;

	case 'pendiente-con-canje':
		// Un pedido ya creado, pendiente de pago, con descuento de puntos SIN
		// cobrar: lo que la caja dejaba antes de la 0.4.1 y que se podía pagar
		// desde Mi cuenta con el descuento regalado.
		$uid      = (int) $args[1];
		$puntos   = (int) $args[2];
		$memo     = (array) get_option( $memoria, array() );
		$producto = wc_get_product( (int) array_key_first( (array) ( $memo['stock'] ?? array() ) ) );
		$user     = get_user_by( 'id', $uid );
		$order    = $pedido_qa( $uid, (string) $user->user_email, '+56933334444', $rut( 14141414 ), $producto );
		$fee      = new WC_Order_Item_Fee();
		$fee->set_name( 'Puntos Akibara' );
		$fee->set_tax_status( 'none' );
		$fee->set_total( -1 * $puntos );
		$order->add_item( $fee );
		$order->calculate_totals( false );
		$order->set_status( 'pending' );
		$order->save();
		$salir( array( 'pedido' => (int) $order->get_id(), 'pagar' => $order->get_checkout_payment_url() ) );
		break;

	case 'alerta':
		$memo     = (array) get_option( $memoria, array() );
		$producto = wc_get_product( (int) array_key_first( (array) ( $memo['stock'] ?? array() ) ) );
		$order    = $pedido_qa( 0, 'qa.alerta@akibara.invalid', '+56900000000', $rut( 9999999 ), $producto );
		do_action( 'akb_rewards_redemption_shortfall', $order, 1000, 400, 600 );
		do_action( 'akb_rewards_redemption_shortfall', $order, 1000, 400, 600 );
		$salir( array( 'pedido' => (int) $order->get_id(), 'para' => Options::alerts_recipients() ) );
		break;

	case 'invitacion':
		$memo     = (array) get_option( $memoria, array() );
		$producto = wc_get_product( (int) array_key_first( (array) ( $memo['stock'] ?? array() ) ) );
		update_option( 'akb_rewards_invite_status', 'processing' );
		$canje = get_user_by( 'login', 'qa.canje' );
		$order = $pedido_qa( (int) $canje->ID, 'qa.canje@akibara.invalid', '+56922223333', $rut( 13131313 ), $producto );
		$order->update_status( 'processing', 'QA invitación. ' );
		$agendadas_procesando = count( as_get_scheduled_actions( array( 'hook' => Invitations::HOOK, 'args' => array( (int) $order->get_id() ), 'status' => 'pending' ), 'ids' ) );
		$order->update_status( 'completed', 'QA invitación. ' );
		$agendadas_completado = count( as_get_scheduled_actions( array( 'hook' => Invitations::HOOK, 'args' => array( (int) $order->get_id() ), 'status' => 'pending' ), 'ids' ) );
		( new Invitations() )->send_invite( (int) $order->get_id() );
		$order = wc_get_order( $order->get_id() );
		as_unschedule_all_actions( Invitations::HOOK, array( (int) $order->get_id() ) );
		$salir(
			array(
				'pedido'               => (int) $order->get_id(),
				'agendadas_procesando' => $agendadas_procesando,
				'agendadas_completado' => $agendadas_completado,
				'enviada'              => (string) $order->get_meta( Invitations::META_SENT ),
			)
		);
		break;

	case 'limpiar':
		global $wpdb;
		$memo    = (array) get_option( $memoria, array() );
		$cuentas = array();
		foreach ( array( 'qa.dueno', 'qa.padrino', 'qa.canje' ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) {
				$cuentas[] = (int) $user->ID;
			}
		}
		foreach ( get_users( array( 'search' => 'qa.invitada.*@akibara.invalid', 'search_columns' => array( 'user_email' ), 'fields' => 'ID' ) ) as $id ) {
			$cuentas[] = (int) $id;
		}

		$pedidos = array();
		foreach ( $cuentas as $id ) {
			foreach ( wc_get_orders( array( 'customer_id' => $id, 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) + array( 'checkout-draft' ) ) ) as $oid ) {
				$pedidos[] = (int) $oid;
			}
		}
		foreach ( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'meta_key' => $marca, 'meta_value' => 1 ) ) as $oid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$pedidos[] = (int) $oid;
		}
		$pedidos = array_values( array_unique( $pedidos ) );
		foreach ( $pedidos as $oid ) {
			foreach ( array( Invitations::HOOK, 'akb_rewards_redemption_retry', 'akb_rewards_order_email_retry' ) as $hook ) {
				as_unschedule_all_actions( $hook, array( $oid ) );
			}
			$o = wc_get_order( $oid );
			if ( $o ) {
				$o->delete( true );
			}
		}

		$filas = 0;
		if ( array() !== $cuentas ) {
			$tabla = $wpdb->prefix . 'akb_rewards_ledger';
			$filas = (int) $wpdb->query( "DELETE FROM {$tabla} WHERE user_id IN (" . implode( ',', array_map( 'intval', $cuentas ) ) . ')' ); // phpcs:ignore WordPress.DB
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $cuentas as $id ) {
			wp_delete_user( $id );
		}

		foreach ( (array) ( $memo['options'] ?? array() ) as $opt => $valor ) {
			null === $valor ? delete_option( $opt ) : update_option( $opt, $valor );
		}
		foreach ( (array) ( $memo['stock'] ?? array() ) as $pid => $stock ) {
			$producto = wc_get_product( (int) $pid );
			if ( $producto ) {
				$producto->set_manage_stock( (bool) $stock[0] );
				if ( $stock[0] ) {
					$producto->set_stock_quantity( $stock[1] );
				}
				$producto->set_stock_status( (string) $stock[2] );
				$producto->save();
			}
		}
		delete_option( 'akb_qa_recargo' );
		delete_option( 'akb_qa_fallar_tras_crear' );
		$alertas = (int) $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%akb\\_rw\\_alerta\\_%'" ); // phpcs:ignore WordPress.DB
		delete_option( $memoria );

		$salir(
			array(
				'cuentas'  => count( $cuentas ),
				'pedidos'  => count( $pedidos ),
				'filas'    => $filas,
				'options'  => count( (array) ( $memo['options'] ?? array() ) ),
				'stock'    => count( (array) ( $memo['stock'] ?? array() ) ),
				'alertas'  => $alertas,
			)
		);
		break;
}
