<?php
/**
 * Siembra (y retira) un cliente con saldo para probar el canje en el ESPEJO.
 *
 * Uso (sin copiar nada al docroot: WP-CLI lee el código por STDIN):
 *   docker compose exec -T cron wp eval-file - sembrar < tools/akibara-releases/rewards-qa/sembrar-cliente-puntos.php
 *   docker compose exec -T cron wp eval-file - limpiar < tools/akibara-releases/rewards-qa/sembrar-cliente-puntos.php
 *
 * Devuelve por stdout el JSON que consume `qa-puntos-primera-compra.mjs`: el
 * cliente, su clave, su saldo, un producto barato y una LISTA de tomos con los
 * que superar el mínimo de canje sumando unidades distintas (los tomos suelen
 * tener stock 1, así que no se puede pedir tres veces el mismo).
 *
 * @package Akibara\Rewards\QA
 */

$modo   = $args[0] ?? 'sembrar';
$option = 'akb_qa_cliente_puntos';
$login  = 'qa.puntos';
$clave  = 'AkbQaPuntos2026!';

if ( 'limpiar' === $modo ) {
	$datos = (array) get_option( $option, array() );
	$user  = get_user_by( 'login', $login );
	if ( $user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user->ID );
	}
	delete_option( $option );
	echo wp_json_encode( array( 'retirado' => (bool) $user, 'era' => $datos['user_id'] ?? 0 ) ) . PHP_EOL;
	return;
}

$user = get_user_by( 'login', $login );
if ( ! $user ) {
	$user_id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => $clave,
			'user_email' => 'qa.puntos@akibara.invalid',
			'role'       => 'customer',
			'first_name' => 'QA',
			'last_name'  => 'Puntos',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		WP_CLI::error( $user_id->get_error_message() );
	}
} else {
	$user_id = $user->ID;
}

// La clave se fija SIEMPRE y aparte: creando el usuario con `user_pass` el
// espejo se quedó con una clave que no era ésta y Playwright no podía entrar
// (medido el 2026-09-13).
wp_set_password( $clave, (int) $user_id );

// Saldo suficiente para canjear el tope de un pedido, por la vía real (ledger).
$saldo = \Akibara\Rewards\Points\Ledger::balance( (int) $user_id );
if ( $saldo < 3000 ) {
	\Akibara\Rewards\Points\Ledger::credit(
		(int) $user_id,
		3000 - $saldo,
		'admin_adjust',
		'user',
		(int) $user_id,
		'Saldo de QA para probar el carrito mínimo de canje',
		true,
		'qa_puntos_' . $user_id . '_' . time()
	);
}

// Un producto barato (para quedar bajo el mínimo) y los que hagan falta para
// superarlo sumando tomos distintos.
$minimo  = \Akibara\Rewards\Support\Options::redeem_min_cart();
$barato  = null;
$sobre   = array();
$suma    = 0.0;
foreach ( wc_get_products( array( 'limit' => 300, 'status' => 'publish', 'orderby' => 'ID', 'order' => 'DESC' ) ) as $producto ) {
	if ( ! $producto->is_purchasable() || ! $producto->is_in_stock() ) {
		continue;
	}
	$precio = (float) $producto->get_price();
	if ( $precio <= 0 ) {
		continue;
	}
	if ( null === $barato && $precio < $minimo / 2 ) {
		$barato = $producto;
		continue;
	}
	if ( $suma < $minimo * 1.15 ) {
		$sobre[] = array(
			'id'     => $producto->get_id(),
			'precio' => $precio,
			'url'    => get_permalink( $producto->get_id() ),
		);
		$suma   += $precio;
	}
	if ( $barato && $suma >= $minimo * 1.15 ) {
		break;
	}
}

if ( ! $barato || $suma < $minimo ) {
	WP_CLI::error( 'El espejo no tiene catálogo suficiente para armar los dos carritos.' );
}

$datos = array(
	'user_id'     => (int) $user_id,
	'login'       => $login,
	'clave'       => $clave,
	'saldo'       => \Akibara\Rewards\Points\Ledger::balance( (int) $user_id ),
	'min_cart'    => \Akibara\Rewards\Support\Options::redeem_min_cart(),
	'barato'      => array(
		'id'     => $barato->get_id(),
		'precio' => (float) $barato->get_price(),
		'url'    => get_permalink( $barato->get_id() ),
	),
	'sobre'       => $sobre,
	'suma_sobre'  => $suma,
	'programa'    => \Akibara\Rewards\Points\ProgramPage::url(),
	'mi_cuenta'   => wc_get_account_endpoint_url( 'rewards' ),
);

update_option( $option, $datos, false );

echo wp_json_encode( $datos ) . PHP_EOL;
