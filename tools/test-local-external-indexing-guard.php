<?php
/**
 * Test aislado del guard local de IndexNow; no carga WordPress ni abre red.
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

$akibara_test_environment = 'local';
$akibara_test_filters     = array();

/** @return string */
function wp_get_environment_type(): string {
	global $akibara_test_environment;
	return $akibara_test_environment;
}

/** @return mixed */
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

/** @param callable|string $callback */
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $akibara_test_filters;
	$akibara_test_filters[ $hook ] = array( $callback, $priority, $accepted_args );
}

final class WP_Error {
	public function __construct( public string $code, public string $message ) {}
}

/** @param mixed $actual */
function akibara_assert( bool $condition, string $message, $actual = null ): void {
	if ( $condition ) {
		return;
	}
	fwrite( STDERR, $message . ': ' . var_export( $actual, true ) . PHP_EOL );
	exit( 1 );
}

require dirname( __DIR__ ) . '/public_html/wp-content/mu-plugins/akibara-local-external-indexing-guard.php';

akibara_assert(
	array_keys( $akibara_test_filters ) === array(
		'rank_math/instant_indexing/publish_url',
		'rank_math/instant_indexing/submit_url',
		'pre_update_option_rank_math_indexnow_log',
		'pre_update_option',
		'pre_http_request',
	),
	'Los cinco filtros defensivos deben registrarse',
	array_keys( $akibara_test_filters )
);
akibara_assert(
	$akibara_test_filters['rank_math/instant_indexing/publish_url'][1] === PHP_INT_MAX,
	'El guard automático debe correr al final'
);
akibara_assert(
	$akibara_test_filters['pre_http_request'][2] === 3,
	'El guard HTTP debe recibir tres argumentos'
);
akibara_assert(
	$akibara_test_filters['pre_update_option_rank_math_indexnow_log'][1] === PHP_INT_MAX
		&& 2 === $akibara_test_filters['pre_update_option_rank_math_indexnow_log'][2],
	'El guard del log debe correr al final y recibir valor nuevo y anterior'
);
akibara_assert(
	$akibara_test_filters['pre_update_option'][1] === PHP_INT_MAX
		&& 3 === $akibara_test_filters['pre_update_option'][2],
	'El guard global posterior debe correr al final y recibir tres argumentos'
);

akibara_assert(
	false === akibara_external_indexing_url_guard( 'http://localhost:8080/manga/' ),
	'Local debe cancelar el envío automático/manual'
);
$old_log = array(
	'2026-08-05T00:00:00Z' => array( 'status' => 403 ),
);
$new_log = array(
	'2026-08-06T00:00:00Z' => array( 'status' => 0 ),
);
akibara_assert(
	$old_log === akibara_external_indexing_log_guard( $new_log, $old_log ),
	'Local debe conservar exactamente el log previo tras un bypass manual/directo'
);
akibara_assert(
	false === akibara_external_indexing_log_guard( $new_log, false ),
	'Local no debe crear la opción cuando todavía no existe'
);
akibara_assert(
	$old_log === akibara_external_indexing_global_option_guard(
		$new_log,
		'rank_math_indexnow_log',
		$old_log
	),
	'El filtro global posterior tampoco debe permitir la escritura local del log'
);
akibara_assert(
	$new_log === akibara_external_indexing_global_option_guard( $new_log, 'blogname', $old_log ),
	'Local no debe interferir con otras opciones'
);

$sentinel = new stdClass();
akibara_assert(
	$sentinel === akibara_external_indexing_http_guard( $sentinel, array(), 'https://example.com/' ),
	'Local no debe interferir con hosts ajenos'
);
$blocked = akibara_external_indexing_http_guard(
	$sentinel,
	array(),
	'https://api.indexnow.org/indexnow/'
);
akibara_assert(
	$blocked instanceof WP_Error && 'akibara_local_external_indexing_blocked' === $blocked->code,
	'Local debe bloquear un bypass directo a IndexNow',
	$blocked
);

$akibara_test_environment = 'production';
akibara_assert(
	'https://akibara.cl/manga/' === akibara_external_indexing_url_guard( 'https://akibara.cl/manga/' ),
	'Producción debe conservar la URL'
);
akibara_assert(
	$new_log === akibara_external_indexing_log_guard( $new_log, $old_log ),
	'Producción debe conservar sin cambios la escritura oficial del log'
);
akibara_assert(
	$new_log === akibara_external_indexing_global_option_guard(
		$new_log,
		'rank_math_indexnow_log',
		$old_log
	),
	'Producción debe conservar el valor nuevo tras el filtro global'
);
akibara_assert(
	$sentinel === akibara_external_indexing_http_guard(
		$sentinel,
		array(),
		'https://api.indexnow.org/indexnow/'
	),
	'Producción debe conservar el flujo HTTP oficial'
);

fwrite( STDOUT, "PASS: local external indexing guard\n" );
