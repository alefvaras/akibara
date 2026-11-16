<?php
/**
 * Candado del programador de retención de logs de Bluex; no carga WordPress ni
 * abre la base de datos.
 *
 * INVARIANTE QUE FIJA: la comprobación de "¿ya está programada la limpieza?"
 * NO consulta Action Scheduler en peticiones de visitante. `action_scheduler_init`
 * se dispara en `init` prioridad 1 de TODAS las peticiones, así que antes de
 * 2026-08-23 cada visita pagaba 3 consultas por un cron diario (medido con
 * testigo en Docker: portada 2,25 ms, /carrito/ 1,42 ms, /checkout/ 1,85 ms,
 * Store API cart/add-item 1,52 ms). Tras el arreglo: 0 consultas en front,
 * admin-ajax y REST; 1 consulta en WP-Cron.
 *
 * Uso: php tools/test-bluex-log-retention.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', __DIR__ );
}

$akibara_test_actions = array();
$akibara_test_filters = array();
$akibara_test_context = array(
	'admin' => false,
	'cron'  => false,
	'ajax'  => false,
);
$akibara_test_as_calls = array(
	'has'       => 0,
	'next'      => 0,
	'scheduled' => 0,
);
$akibara_test_as_exists = true;

/** @param callable|string $callback */
function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $akibara_test_actions;
	$akibara_test_actions[ $hook ][] = array( $callback, $priority, $accepted_args );
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

function is_admin(): bool {
	global $akibara_test_context;
	return $akibara_test_context['admin'];
}

function wp_doing_cron(): bool {
	global $akibara_test_context;
	return $akibara_test_context['cron'];
}

function wp_doing_ajax(): bool {
	global $akibara_test_context;
	return $akibara_test_context['ajax'];
}

/**
 * Cada llamada a estas dos cuenta como acceso a la tabla de Action Scheduler.
 *
 * @param array<int, mixed> $args Argumentos de la acción.
 */
function as_has_scheduled_action( string $hook, array $args = array(), string $group = '' ): bool {
	global $akibara_test_as_calls, $akibara_test_as_exists;
	unset( $hook, $args, $group );
	++$akibara_test_as_calls['has'];
	return $akibara_test_as_exists;
}

/**
 * @param array<int, mixed> $args Argumentos de la acción.
 * @return int|false
 */
function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ) {
	global $akibara_test_as_calls, $akibara_test_as_exists;
	unset( $hook, $args, $group );
	++$akibara_test_as_calls['next'];
	return $akibara_test_as_exists ? 123 : false;
}

/**
 * @param array<int, mixed> $args Argumentos de la acción.
 */
function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '', bool $unique = false ): int {
	global $akibara_test_as_calls, $akibara_test_as_exists;
	unset( $timestamp, $interval, $hook, $args, $group, $unique );
	++$akibara_test_as_calls['scheduled'];
	$akibara_test_as_exists = true;
	return 1;
}

/** @param mixed $actual */
function akibara_assert( bool $condition, string $message, $actual = null ): void {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, 'FAIL: ' . $message . ': ' . var_export( $actual, true ) . PHP_EOL );
	exit( 1 );
}

function akibara_test_reset( bool $admin, bool $cron, bool $ajax, bool $exists ): void {
	global $akibara_test_context, $akibara_test_as_calls, $akibara_test_as_exists;
	$akibara_test_context   = array(
		'admin' => $admin,
		'cron'  => $cron,
		'ajax'  => $ajax,
	);
	$akibara_test_as_calls  = array(
		'has'       => 0,
		'next'      => 0,
		'scheduled' => 0,
	);
	$akibara_test_as_exists = $exists;
}

function akibara_test_as_touches(): int {
	global $akibara_test_as_calls;
	return $akibara_test_as_calls['has'] + $akibara_test_as_calls['next'] + $akibara_test_as_calls['scheduled'];
}

require __DIR__ . '/../public_html/wp-content/mu-plugins/akibara-bluex-suite.php';

akibara_assert(
	isset( $akibara_test_actions['action_scheduler_init'] ),
	'la programación debe seguir colgada de action_scheduler_init',
	array_keys( $akibara_test_actions )
);

// Contextos de visitante: front, REST y admin-ajax. Ninguno puede tocar AS.
$akibara_visitor_contexts = array(
	'front'       => array( false, false, false ),
	'rest'        => array( false, false, false ),
	'admin-ajax'  => array( true, false, true ),
);

foreach ( $akibara_visitor_contexts as $label => $context ) {
	akibara_test_reset( $context[0], $context[1], $context[2], false );
	akibara_bluex_schedule_log_retention();
	akibara_assert(
		0 === akibara_test_as_touches(),
		'en contexto "' . $label . '" no puede consultarse Action Scheduler',
		$GLOBALS['akibara_test_as_calls']
	);
}

// WP-Cron sí repara la programación, y con la consulta barata.
akibara_test_reset( false, true, false, false );
akibara_bluex_schedule_log_retention();
akibara_assert( 1 === $akibara_test_as_calls['has'], 'cron debe comprobar con as_has_scheduled_action', $akibara_test_as_calls );
akibara_assert( 0 === $akibara_test_as_calls['next'], 'as_next_scheduled_action cuesta 3 consultas frente a 1: no debe usarse', $akibara_test_as_calls );
akibara_assert( 1 === $akibara_test_as_calls['scheduled'], 'cron debe reprogramar la acción ausente', $akibara_test_as_calls );

// Escritorio real (no ajax) también repara.
akibara_test_reset( true, false, false, false );
akibara_bluex_schedule_log_retention();
akibara_assert( 1 === $akibara_test_as_calls['scheduled'], 'el escritorio debe reprogramar la acción ausente', $akibara_test_as_calls );

// WP-CLI también repara: en producción DISABLE_WP_CRON es true y el disparo
// externo por WP-CLI deja `wp_doing_cron()` en false (medido el 2026-08-23).
// Sin esta rama la red de reparación no existiría en el entorno real.
define( 'WP_CLI', true );
akibara_test_reset( false, false, false, false );
akibara_bluex_schedule_log_retention();
akibara_assert( 1 === $akibara_test_as_calls['scheduled'], 'WP-CLI debe reprogramar la acción ausente', $akibara_test_as_calls );

// Si ya existe, no se duplica.
akibara_test_reset( false, true, false, true );
akibara_bluex_schedule_log_retention();
akibara_assert( 0 === $akibara_test_as_calls['scheduled'], 'no debe duplicar una acción que ya existe', $akibara_test_as_calls );

echo 'PASS: retención Bluex — 0 consultas en visitante, 1 en cron, sin duplicar la acción' . PHP_EOL;
