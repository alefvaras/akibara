<?php
/**
 * Reactivar «Enviar avisos de reposición» tiene que reencolar a TODOS.
 *
 * El bug: `on_dispatch_setting_saved()` reencolaba con `demand_summary()` sin
 * argumento, y ésa lleva `LIMIT 50` porque existe para pintar la tabla del
 * panel. Del producto 51 en adelante nadie volvía a la cola, sin un solo aviso.
 *
 * Lo que prueban las OTRAS sondas y aquí NO se repite: el techo de la consulta
 * en sí y los números de la pantalla van en `test-bis-panel.php`. Esto mide sólo
 * el efecto real sobre Action Scheduler, que es lo que aquella no puede ver.
 *
 *   cp tools/test-bis-reencolado.php public_html/_t.php
 *   docker compose --profile tools run --rm -T cli wp eval-file _t.php
 *   rm public_html/_t.php
 */

use Akibara\Business\Modules\BackInStock\Install;
use Akibara\Business\Modules\BackInStock\NotificationDispatcher;
use Akibara\Business\Modules\BackInStock\SubscriberRepository;

global $wpdb;
$tabla = Install::table_name();

$GLOBALS['akb_fallos']   = 0;
$GLOBALS['akb_corridas'] = 0;

function comprobar( string $caso, bool $ok, string $detalle = '' ): void {
	++$GLOBALS['akb_corridas'];
	if ( ! $ok ) {
		++$GLOBALS['akb_fallos'];
	}
	printf( "  %s %s%s\n", $ok ? 'OK  ' : 'FALLA', $caso, $detalle ? " — {$detalle}" : '' );
}

/** Los jobs pendientes del grupo del módulo. */
function pendientes(): array {
	return as_get_scheduled_actions(
		array(
			'group'    => 'akibara-bis',
			'status'   => 'pending',
			'per_page' => 500,
		)
	);
}

/** Vacía la cola del módulo para partir de cero. */
function vaciar_cola(): void {
	foreach ( pendientes() as $a ) {
		if ( method_exists( $a, 'get_hook' ) ) {
			as_unschedule_all_actions( $a->get_hook(), $a->get_args(), 'akibara-bis' );
		}
	}
}

// 60 productos con una persona esperando cada uno: por encima del techo de 50.
$ids       = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' LIMIT 60" ) );
$sembrados = array();
foreach ( $ids as $i => $pid ) {
	$wpdb->insert(
		$tabla,
		array(
			'product_id' => $pid,
			'email'      => "reencolado-qa-{$i}@akibara.cl",
			'user_id'    => null,
			'token'      => bin2hex( random_bytes( 32 ) ),
			'status'     => 'subscribed',
			'created_at' => current_time( 'mysql', true ),
		)
	);
	$sembrados[] = (int) $wpdb->insert_id;
}

$repo      = new SubscriberRepository( $wpdb, $tabla );
$con_espera = count( $repo->products_with_waiting() );
printf( "productos con gente esperando: %d\n\n", $con_espera );

echo "── al reactivar el envío, vuelven TODOS a la cola ──\n";
vaciar_cola();
$antes = count( pendientes() );

update_option( 'akb_bis_dispatch_enabled', 'yes' );
( new NotificationDispatcher( $repo ) )->on_dispatch_setting_saved( 'no', 'yes' );

$encolados = count( pendientes() ) - $antes;
comprobar( 'se encola uno por cada producto con espera', $encolados >= $con_espera, "encolados {$encolados}, con espera {$con_espera}" );
comprobar( 'ninguno se queda fuera por el techo de 50', $encolados > 50, "son {$encolados}" );

// ── Restaurar: la cola y las filas (por ID, nunca por email).
vaciar_cola();
$wpdb->query( 'DELETE FROM ' . $tabla . ' WHERE id IN (' . implode( ',', array_map( 'intval', $sembrados ) ) . ')' );

$residuo = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabla} WHERE email LIKE 'reencolado-qa-%'" );
comprobar( 'no queda residuo ni en la tabla ni en la cola', 0 === $residuo && 0 === count( pendientes() ), "filas {$residuo}, jobs " . count( pendientes() ) );

$fallos   = (int) $GLOBALS['akb_fallos'];
$corridas = (int) $GLOBALS['akb_corridas'];
printf( "\n%s  %d/%d comprobaciones\n", 0 === $fallos ? 'TODO EN VERDE' : "{$fallos} FALLOS", $corridas - $fallos, $corridas );
