<?php
/**
 * Candado del puente REST de seguimiento de Blue Express; no carga WordPress ni
 * abre red.
 *
 * INVARIANTE QUE FIJA: un código de seguimiento que llega por la API NUNCA se
 * pierde en silencio. El tercero (`wc_correios_updateTrackingCode()`) es el
 * único que sabe persistirlo; si desaparece —se desactiva, se actualiza, lo
 * renombra— WordPress ignora el `false` del `update_callback`, la API responde
 * 200 igual y el cliente se queda sin número. Desde 2026-08-31 ese descarte
 * deja una entrada en WooCommerce → Estado → Registros con el pedido y el
 * código, que es lo que permite reponerlo a mano.
 *
 * El caso «tercero ausente» va PRIMERO a propósito: una función declarada no se
 * puede retirar, así que el orden es el que hace posible probar los dos mundos
 * en un proceso.
 *
 * Uso: php tools/test-bluex-rest-tracking.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', __DIR__ );
}

$akibara_test_actions = array();
$akibara_test_filters = array();
$akibara_test_logs    = array();
$akibara_test_stored  = array();

/** @param callable|string $callback */
function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $akibara_test_actions;
	$akibara_test_actions[ $hook ] = array( $callback, $priority, $accepted_args );
}

/** @param callable|string $callback */
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $akibara_test_filters;
	$akibara_test_filters[ $hook ][] = array( $callback, $priority, $accepted_args );
}

function esc_url( string $url ): string {
	return $url;
}

function esc_html( string $text ): string {
	return $text;
}

/** @param mixed ...$args */
function add_query_arg( ...$args ): string {
	return '';
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

final class WC_Order {

	public function __construct( private int $id ) {}

	public function get_id(): int {
		return $this->id;
	}
}

final class WP_REST_Request {

	/** @param array<string, mixed> $params Parámetros de la petición. */
	public function __construct( private array $params = array() ) {}

	/** @return mixed */
	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}
}

/** Espía del canal de logs de WooCommerce. */
final class Akibara_Test_Logger {

	/** @param array<string, mixed> $context Contexto del registro. */
	public function error( string $message, array $context = array() ): void {
		global $akibara_test_logs;
		$akibara_test_logs[] = array( $message, $context );
	}
}

function wc_get_logger(): Akibara_Test_Logger {
	return new Akibara_Test_Logger();
}

/** @param mixed $actual */
function akibara_assert( bool $condition, string $message, $actual = null ): void {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, 'FAIL: ' . $message . ': ' . var_export( $actual, true ) . PHP_EOL );
	exit( 1 );
}

require dirname( __DIR__ ) . '/public_html/wp-content/mu-plugins/akibara-bluex-suite.php';

akibara_assert(
	isset( $akibara_test_actions['woocommerce_rest_insert_shop_order_object'] )
		&& 3 === $akibara_test_actions['woocommerce_rest_insert_shop_order_object'][2],
	'el respaldo REST debe registrarse con los tres argumentos del hook',
	$akibara_test_actions
);

$pedido = new WC_Order( 27720 );

// ─── El tercero NO está: se descarta, pero deja rastro ──────────────────────
akibara_assert(
	false === akibara_bluex_store_tracking_codes( $pedido, 'BX123456789' ),
	'sin el tercero no se puede persistir'
);
akibara_assert(
	1 === count( $akibara_test_logs ),
	'el descarte debe dejar exactamente una entrada de log',
	$akibara_test_logs
);
akibara_assert(
	str_contains( $akibara_test_logs[0][0], 'BX123456789' )
		&& str_contains( $akibara_test_logs[0][0], '27720' ),
	'el log debe nombrar el código perdido y el pedido, o no sirve para reponerlo',
	$akibara_test_logs[0][0]
);
akibara_assert(
	'akibara-bluex' === ( $akibara_test_logs[0][1]['source'] ?? '' ),
	'el log debe ir al source del puente',
	$akibara_test_logs[0][1]
);

// Sin código no se ha perdido nada: gritar aquí sería fatiga de alarma.
$akibara_test_logs = array();
akibara_assert(
	false === akibara_bluex_store_tracking_codes( $pedido, '   ' ),
	'un código vacío no es un descarte'
);
akibara_assert(
	false === akibara_bluex_store_tracking_codes( 'no-es-un-pedido', 'BX999' ),
	'sin pedido no hay nada que actualizar'
);
akibara_assert(
	array() === $akibara_test_logs,
	'no debe registrarse nada cuando no había código que perder',
	$akibara_test_logs
);

// ─── El tercero SÍ está: se persisten todos los códigos, sin log ────────────
if ( ! function_exists( 'wc_correios_updateTrackingCode' ) ) {
	/**
	 * Doble del tercero: apunta lo que recibiría Blue Express.
	 *
	 * @param WC_Order $order         Pedido.
	 * @param string   $tracking_code Código individual.
	 */
	function wc_correios_updateTrackingCode( $order, $tracking_code ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.NotSnakeCase -- firma del tercero.
		global $akibara_test_stored;
		$akibara_test_stored[] = array( $order->get_id(), $tracking_code );

		return true;
	}
}

akibara_assert(
	true === akibara_bluex_store_tracking_codes( $pedido, 'BX1 , BX2,BX3' ),
	'con el tercero presente debe persistir'
);
akibara_assert(
	array(
		array( 27720, 'BX1' ),
		array( 27720, 'BX2' ),
		array( 27720, 'BX3' ),
	) === $akibara_test_stored,
	'los códigos separados por coma se persisten uno a uno y sin espacios',
	$akibara_test_stored
);
akibara_assert(
	array() === $akibara_test_logs,
	'con el tercero presente no hay descarte que registrar',
	$akibara_test_logs
);

// El callback REST invierte los argumentos del tercero; el respaldo lee el
// parámetro de la petición. Los dos terminan en la misma función.
$akibara_test_stored = array();
akibara_assert(
	true === akibara_bluex_update_rest_tracking_field( 'BX4', $pedido ),
	'el callback REST recibe (código, pedido) y no al revés'
);
akibara_assert(
	array( array( 27720, 'BX4' ) ) === $akibara_test_stored,
	'el callback REST debe persistir el código recibido',
	$akibara_test_stored
);

$akibara_test_stored = array();
akibara_bluex_sync_rest_tracking_code( $pedido, new WP_REST_Request( array( 'correios_tracking_code' => 'BX5' ) ), false );
akibara_assert(
	array( array( 27720, 'BX5' ) ) === $akibara_test_stored,
	'el respaldo debe leer correios_tracking_code de la petición',
	$akibara_test_stored
);

fwrite( STDOUT, 'PASS: puente REST de Blue Express — ningún seguimiento se pierde en silencio' . PHP_EOL );
