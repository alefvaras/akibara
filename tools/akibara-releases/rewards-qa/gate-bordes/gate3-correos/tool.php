<?php
/**
 * Caja de herramientas del gate de correos. Todo reversible.
 * Uso: wp eval-file tool.php <subcomando> [args...]
 */

use Akibara\Business\Modules\SeriesFollow\Install as SfInstall;
use Akibara\Business\Modules\SeriesFollow\FollowRegistry;

global $wpdb;

$cmd = $args[0] ?? 'help';
$tab = SfInstall::table_name();

/** Marca de todo lo sembrado por el gate. */
const GATE_TAG = 'akb-gate3';

function gate_user( string $login, string $email ): int {
	$u = get_user_by( 'login', $login );
	if ( $u instanceof WP_User ) {
		return (int) $u->ID;
	}
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $email,
			'user_pass'  => wp_generate_password( 24 ),
			'first_name' => 'QA',
			'last_name'  => 'Gate3',
			'role'       => 'customer',
		)
	);
	if ( is_wp_error( $id ) ) {
		echo 'ERROR user: ' . $id->get_error_message() . "\n";
		return 0;
	}
	update_user_meta( (int) $id, '_akb_gate3', 1 );
	return (int) $id;
}

switch ( $cmd ) {

	case 'users':
		$a = gate_user( 'qa_gate3_a', 'qa-gate3-a@akibara.local' );
		$b = gate_user( 'qa_gate3_b', 'qa-gate3-b@akibara.local' );
		$c = gate_user( 'qa_gate3_c', 'qa-gate3-c@akibara.local' );
		echo "A={$a} B={$b} C={$c}\n";
		break;

	case 'encolar':
		// args: encolar <user_id> <term_id:product_id:volume> [...]
		$uid  = (int) ( $args[1] ?? 0 );
		$rows = array();
		for ( $i = 2; $i < count( $args ); $i++ ) {
			[$t, $p, $v] = array_map( 'intval', explode( ':', $args[ $i ] ) );
			$rows[]      = array(
				'user_id'    => $uid,
				'product_id' => $p,
				'term_id'    => $t,
				'volume'     => $v,
			);
		}
		$q = new \Akibara\Business\Modules\SeriesFollow\DigestQueue( $wpdb, $tab );
		echo 'insertadas=' . $q->queue( $rows ) . "\n";
		echo 'pendientes=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tab} WHERE status='pending'" ) . "\n";
		break;

	case 'reset-cap':
		$uid = (int) ( $args[1] ?? 0 );
		delete_user_meta( $uid, FollowRegistry::META_LAST_SENT );
		echo "cap reseteado para {$uid}\n";
		break;

	case 'digest':
		do_action( \Akibara\Business\Modules\SeriesFollow\SeriesFollow::HOOK_DIGEST );
		echo 'pendientes-post=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tab} WHERE status='pending'" ) . "\n";
		echo 'enviadas=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tab} WHERE status='sent'" ) . "\n";
		break;

	case 'cola':
		$r = $wpdb->get_results( "SELECT id,user_id,product_id,term_id,volume,status,drop_reason FROM {$tab} ORDER BY id", ARRAY_A );
		foreach ( $r as $x ) {
			echo implode( ' | ', $x ) . "\n";
		}
		echo 'filas=' . count( $r ) . "\n";
		break;

	case 'optout-estado':
		$uid = (int) ( $args[1] ?? 0 );
		echo 'optout_terms=' . wp_json_encode( FollowRegistry::opted_out_terms( $uid ) ) . "\n";
		echo 'optout_all=' . var_export( FollowRegistry::opted_out_of_everything( $uid ), true ) . "\n";
		echo 'last_sent=' . FollowRegistry::last_sent_at( $uid ) . "\n";
		break;

	case 'unfollow-url':
		$uid = (int) ( $args[1] ?? 0 );
		$tid = (int) ( $args[2] ?? 0 );
		echo \Akibara\Business\Modules\SeriesFollow\UnfollowHandler::url_for( $uid, $tid ) . "\n";
		break;

	case 'limpiar-cola':
		$n = $wpdb->query( "DELETE FROM {$tab}" );
		echo "borradas={$n}\n";
		break;

	case 'borrar-usuarios':
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( array( 'qa_gate3_a', 'qa_gate3_b', 'qa_gate3_c' ) as $login ) {
			$u = get_user_by( 'login', $login );
			if ( $u instanceof WP_User ) {
				wp_delete_user( (int) $u->ID );
				echo "borrado {$login}\n";
			}
		}
		break;

	default:
		echo "subcomandos: users encolar reset-cap digest cola optout-estado unfollow-url limpiar-cola borrar-usuarios\n";
}
