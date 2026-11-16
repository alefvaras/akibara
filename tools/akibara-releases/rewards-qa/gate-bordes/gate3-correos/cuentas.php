<?php
/**
 * Registro y reset de contraseña (correos 7 y 12).
 */

$cmd = $args[0] ?? 'help';

switch ( $cmd ) {

	case 'registrar':
		$id = wc_create_new_customer( 'qa-gate3-d@akibara.local', 'qa_gate3_d', wp_generate_password( 24 ) );
		if ( is_wp_error( $id ) ) {
			echo 'ERROR: ' . $id->get_error_message() . "\n";
			break;
		}
		update_user_meta( (int) $id, '_akb_gate3', 1 );
		echo "usuario={$id}\n";
		break;

	case 'reset':
		$user = get_user_by( 'login', (string) $args[1] );
		$key  = get_password_reset_key( $user );
		WC()->mailer();
		do_action( 'woocommerce_reset_password_notification', $user->user_login, $key );
		echo "reset disparado para {$user->user_login}\n";
		break;

	case 'borrar-d':
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$u = get_user_by( 'login', 'qa_gate3_d' );
		if ( $u ) {
			wp_delete_user( (int) $u->ID );
			echo "borrado qa_gate3_d\n";
		}
		break;

	default:
		echo "subcomandos: registrar reset borrar-d\n";
}
