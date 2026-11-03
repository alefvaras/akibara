<?php
/**
 * OptionLock — candado corto de MySQL para el ciclo lectura-modificación-
 * escritura de una option compartida entre procesos concurrentes PHP-FPM.
 *
 * Utilizado por `AuditLog` y `GapDismissals` mediante namespaces desacoplados
 * para coordinar escrituras sobre options de estado sin colisiones.
 *
 * Operación no bloqueante de mejor esfuerzo: ante indisponibilidad de candado
 * o ausencia de conexión SQL, retorna cadena vacía permitiendo que el llamador
 * continúe sin abortar la operación principal.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.70
 */
final class OptionLock {

	/** Cuánto espera un llamador a que termine el de otro proceso. */
	private const WAIT_SECONDS = 2;

	/**
	 * Intenta tomar un candado de sesión de MySQL. El nombre depende del
	 * namespace del llamador MÁS la base de datos y el prefijo de tablas, para
	 * no cruzarse ni con otro llamador ni con la misma instalación duplicada
	 * (staging apuntando a otra DB, etc).
	 *
	 * @param string $namespace Identifica al llamador (p. ej. 'akb-search-audit').
	 * @return string Nombre del candado tomado; '' si no se tomó.
	 */
	public static function acquire( string $namespace ): string {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return '';
		}

		$base   = ( defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '' ) . '|' . (string) ( get_object_vars( $wpdb )['prefix'] ?? '' );
		$nombre = $namespace . ':' . substr( hash( 'sha256', $base ), 0, 40 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- un candado de sesión de MySQL no tiene API de WordPress ni admite caché.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $nombre, self::WAIT_SECONDS ) ) ? $nombre : '';
	}

	/**
	 * @param string $nombre Lo que devolvió acquire(); '' es un no-op.
	 * @return void
	 */
	public static function release( string $nombre ): void {
		global $wpdb;

		if ( '' === $nombre ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ídem acquire().
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $nombre ) );
	}
}
