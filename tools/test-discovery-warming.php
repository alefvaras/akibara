<?php
/**
 * Test aislado de la política de caché de descubrimiento; no carga WordPress
 * ni abre red.
 *
 * INVARIANTE QUE FIJA: este mu-plugin sólo DECLARA la política de caché de los
 * sitemaps. No ejecuta trabajo en el arranque de la petición: ni warming, ni
 * cron, ni limpiezas.
 *
 * Por qué cambió (2026-08-23): hasta hoy el test exigía lo contrario — «debe
 * registrar la limpieza del cron legado» — y con eso clavaba un callback de
 * `init` prioridad 1 que corría en TODAS las peticiones para un trabajo que su
 * propio comentario declaraba de una sola vez. Medido en producción: 47 µs y 0
 * consultas por petición, y `wp_next_scheduled('akibara_discovery_warming')`
 * devuelve false (también en Docker), o sea el trabajo ya había terminado. Se
 * fija la propiedad que importa (arranque sin trabajo) en vez del mecanismo
 * concreto de limpieza, que era transitorio por definición.
 *
 * Uso: php tools/test-discovery-warming.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

$akibara_test_filters = array();
$akibara_test_actions = array();
$akibara_test_event   = true;
$akibara_test_cleared = array();
$akibara_test_cron_reads = 0;

/** @param callable|string $callback */
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $akibara_test_filters;
	$akibara_test_filters[ $hook ][] = array( $callback, $priority, $accepted_args );
}

/** @param callable|string $callback */
function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $akibara_test_actions;
	$akibara_test_actions[ $hook ][] = array( $callback, $priority, $accepted_args );
}

/** @return int|false */
function wp_next_scheduled( string $hook ) {
	global $akibara_test_event, $akibara_test_cron_reads;
	unset( $hook );
	++$akibara_test_cron_reads;
	return $akibara_test_event ? 123 : false;
}

function wp_clear_scheduled_hook( string $hook ): void {
	global $akibara_test_event, $akibara_test_cleared;
	$akibara_test_cleared[] = $hook;
	$akibara_test_event     = false;
}

/** @param mixed $actual */
function akibara_assert( bool $condition, string $message, $actual = null ): void {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, 'FAIL: ' . $message . ': ' . var_export( $actual, true ) . PHP_EOL );
	exit( 1 );
}

require __DIR__ . '/../public_html/wp-content/mu-plugins/akibara-discovery-warming.php';

akibara_assert(
	isset( $akibara_test_filters['rank_math/sitemap/http_headers'] ),
	'debe registrar los headers del sitemap',
	array_keys( $akibara_test_filters )
);
akibara_assert( array() === $akibara_test_actions, 'no debe registrar ninguna acción: el arranque de la petición no hace trabajo', array_keys( $akibara_test_actions ) );
akibara_assert( ! isset( $akibara_test_filters['cron_schedules'] ), 'no debe volver a crear el schedule de warming', array_keys( $akibara_test_filters ) );
akibara_assert( array( 'rank_math/sitemap/http_headers' ) === array_keys( $akibara_test_filters ), 'el único filtro debe ser el de headers del sitemap', array_keys( $akibara_test_filters ) );

$headers = call_user_func(
	$akibara_test_filters['rank_math/sitemap/http_headers'][0][0],
	array( 'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0' )
);
$shared = 'public, max-age=14400';
akibara_assert( 'no-store, must-revalidate, max-age=0' === $headers['Cache-Control'], 'el navegador no conserva XML', $headers );
akibara_assert( $shared === $headers['Cloudflare-CDN-Cache-Control'], 'Cloudflare recibe un TTL acotado', $headers );
akibara_assert( $shared === $headers['CDN-Cache-Control'], 'otros CDN reciben la misma política', $headers );

// Cargar el fichero no puede consultar ni tocar WP-Cron: los stubs siguen aquí
// justamente para poder afirmarlo.
akibara_assert( 0 === $akibara_test_cron_reads, 'cargar el mu-plugin no debe consultar WP-Cron', $akibara_test_cron_reads );
akibara_assert( array() === $akibara_test_cleared, 'cargar el mu-plugin no debe borrar eventos', $akibara_test_cleared );

echo 'PASS: discovery cache policy — sólo headers acotados, sin warming ni trabajo en el arranque' . PHP_EOL;
