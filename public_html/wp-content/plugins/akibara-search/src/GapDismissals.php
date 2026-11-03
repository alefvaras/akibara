<?php
/**
 * GapDismissals — búsquedas sin resultado descartadas en administración.
 *
 * Permite ocultar términos del radar de demanda insatisfecha cuando el operador
 * ha tomado una decisión comercial (ej. obra no importable o discontinuada).
 *
 * Características del mecanismo:
 *   - Preserva el historial en `akb_search_qlog` (no borra registros históricos).
 *   - Persiste en `akb_search_gap_dismissals` con tope circular de 200 entradas (`CAP = 200`).
 *   - Permite restauración individual conservando la marca temporal original.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.39
 */
final class GapDismissals {

	public const OPTION = 'akb_search_gap_descartadas';

	/** Tope de descartes vivos: el mecanismo no puede crecer sin límite. */
	public const CAP = 200;

	/** Namespace propio del candado corto entre procesos (ver OptionLock). */
	private const LOCK_NAMESPACE = 'akb-search-gap-dismissals';

	/**
	 * Mapa de descartes: [query_norm => timestamp del descarte].
	 *
	 * @return array<string,int>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();
		foreach ( $stored as $query => $ts ) {
			$out[ (string) $query ] = (int) $ts;
		}

		return $out;
	}

	/**
	 * Descarta una búsqueda. Con el tope lleno, cae la MÁS ANTIGUA.
	 *
	 * @param string $query_norm Búsqueda normalizada.
	 * @return void
	 */
	public static function dismiss( string $query_norm ): void {
		$query_norm = trim( $query_norm );
		if ( '' === $query_norm ) {
			return;
		}

		// Candado corto de base de datos e invalidación de caché para evitar
		// condiciones de carrera entre operadores concurrentes en administración.
		$candado = OptionLock::acquire( self::LOCK_NAMESPACE );
		try {
			wp_cache_delete( self::OPTION, 'options' );
			$all                = self::all();
			$all[ $query_norm ] = time();

			if ( count( $all ) > self::CAP ) {
				asort( $all ); // Más antigua primero.
				$all = array_slice( $all, count( $all ) - self::CAP, null, true );
			}

			update_option( self::OPTION, $all, false );
		} finally {
			OptionLock::release( $candado );
		}
	}

	/**
	 * Restaura una búsqueda descartada.
	 *
	 * @param string $query_norm Búsqueda normalizada.
	 * @return void
	 */
	public static function restore( string $query_norm ): void {
		// Mismo candado + misma relectura que dismiss(): una restauración y un
		// descarte concurrentes (de dos filas distintas) no pueden pisarse.
		$candado = OptionLock::acquire( self::LOCK_NAMESPACE );
		try {
			wp_cache_delete( self::OPTION, 'options' );
			$all = self::all();
			if ( ! isset( $all[ $query_norm ] ) ) {
				return;
			}

			unset( $all[ $query_norm ] );
			update_option( self::OPTION, $all, false );
		} finally {
			OptionLock::release( $candado );
		}
	}

}
