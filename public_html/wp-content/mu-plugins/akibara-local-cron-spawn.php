<?php
/**
 * Plugin Name: Akibara — WP-Cron resoluble en el entorno local
 * Description: En Docker, WP_SITEURL es http://localhost:8080, pero ese host:puerto solo existe en la máquina anfitriona: dentro de los contenedores `localhost:8080` no resuelve y `spawn_cron()` muere con cURL error 7. Sin spawn, `action_scheduler_run_queue` nunca corre y las colas de Akibara Search y de preventa se congelan. Este mu-plugin reescribe SOLO la URL del auto-disparo de cron hacia el hostname interno del servicio web, dejando intacta la URL pública del sitio. Inerte fuera de `local`.
 * Version: 1.0.0
 * Author: Akibara
 *
 * @package Akibara\MuPlugins
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Host interno del servicio web de compose, resoluble desde cualquier
 * contenedor de la red (incluido el propio `wordpress` y el `cli`).
 */
const AKIBARA_LOCAL_CRON_INTERNAL_HOST = 'wordpress';

/**
 * Reescribe el destino del auto-disparo de WP-Cron hacia el host interno.
 *
 * `spawn_cron()` arma la petición contra `site_url( 'wp-cron.php' )`. Se
 * conserva la cabecera `Host` original para que WordPress siga viendo la
 * petición como dirigida al sitio público y no altere ninguna otra decisión.
 *
 * @param array<string,mixed> $cron_request Petición de cron de WordPress.
 * @return array<string,mixed>
 */
function akibara_local_cron_spawn_request( array $cron_request ): array {
	if ( 'local' !== wp_get_environment_type() ) {
		return $cron_request;
	}

	if ( ! isset( $cron_request['url'] ) || ! is_string( $cron_request['url'] ) ) {
		return $cron_request;
	}

	$parts = wp_parse_url( $cron_request['url'] );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return $cron_request;
	}

	// Solo se reescribe el loopback inalcanzable; cualquier otro host se respeta.
	if ( ! in_array( strtolower( (string) $parts['host'] ), array( 'localhost', '127.0.0.1' ), true ) ) {
		return $cron_request;
	}

	$original_host = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

	$rewritten = 'http://' . AKIBARA_LOCAL_CRON_INTERNAL_HOST . ( $parts['path'] ?? '/wp-cron.php' );
	if ( ! empty( $parts['query'] ) ) {
		$rewritten .= '?' . $parts['query'];
	}

	$cron_request['url'] = $rewritten;

	$args = isset( $cron_request['args'] ) && is_array( $cron_request['args'] )
		? $cron_request['args']
		: array();

	$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
	$headers['Host'] = $original_host;

	$args['headers']     = $headers;
	$args['sslverify']   = false;
	$cron_request['args'] = $args;

	return $cron_request;
}
add_filter( 'cron_request', 'akibara_local_cron_spawn_request' );
