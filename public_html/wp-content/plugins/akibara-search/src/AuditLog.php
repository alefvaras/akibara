<?php
/**
 * AuditLog — rastro de cambios operativos del buscador (milestone Operable).
 *
 * Ring acotado en una option (autoload no): quién cambió qué y cuándo, para
 * que "qué toqué la semana pasada" no dependa de la memoria del operador.
 * Solo operadores (manage_woocommerce); sin PII de clientes.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.59.0
 */
final class AuditLog {

	/** Option del ring (autoload no; sin colisión akb_search_* — gate DISEÑO). */
	public const OPTION_KEY = 'akb_search_audit_log';

	/** Entradas máximas. */
	public const MAX = 50;

	/** Namespace propio del candado corto entre procesos (ver OptionLock). */
	private const LOCK_NAMESPACE = 'akb-search-audit';

	/** Cuántas filas recientes se miran para plegar una repetición. */
	private const FOLD_WINDOW = 8;

	/**
	 * Registra una acción del operador.
	 *
	 * @param string $action Código corto (facets_saved, rebuild_forced, reconcile_run…).
	 * @param string $detail Detalle legible (diff resumido, ids, resultado).
	 * @return void
	 */
	public static function record( string $action, string $detail = '' ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		// Exclusión mutua entre procesos mediante OptionLock. Espacio de nombres
		// propio: previene interferencias concurrentes con otros componentes.
		$candado = OptionLock::acquire( self::LOCK_NAMESPACE );
		try {
			self::write( $action, $detail );
		} finally {
			OptionLock::release( $candado );
		}
	}

	/**
	 * La lectura-modificación-escritura del cuaderno, ya dentro del candado.
	 *
	 * @param string $action Código corto.
	 * @param string $detail Detalle legible.
	 * @return void
	 */
	private static function write( string $action, string $detail ): void {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;

		$quien = $user && $user->exists() ? (string) $user->user_login : 'cli';

		// Invalidación de caché local de opciones para forzar lectura fresca
		// desde base de datos, evitando que runners de larga duración pisen
		// cambios concurrentes guardados desde el panel administrativo.
		wp_cache_delete( self::OPTION_KEY, 'options' );
		$entries = self::entries();

		// Plegado de entradas idénticas consecutivas o alternadas dentro de
		// FOLD_WINDOW: previene saturación del límite de 50 registros por avisos
		// repetitivos de tareas en segundo plano, preservando el historial editorial.
		foreach ( array_slice( $entries, 0, self::FOLD_WINDOW, true ) as $i => $previa ) {
			if ( ( $previa['action'] ?? null ) === $action
				&& ( $previa['detail'] ?? null ) === $detail
				&& ( $previa['user'] ?? null ) === $quien ) {
				unset( $entries[ $i ] );
				$previa['n']  = (int) ( $previa['n'] ?? 1 ) + 1;
				$previa['t0'] = (int) ( $previa['t0'] ?? $previa['t'] ?? time() );
				$previa['t']  = time();
				array_unshift( $entries, $previa );

				update_option( self::OPTION_KEY, array_slice( array_values( $entries ), 0, self::MAX ), false );
				return;
			}
		}

		array_unshift(
			$entries,
			array(
				't'      => time(),
				'user'   => $quien,
				'action' => $action,
				'detail' => $detail,
			)
		);

		update_option( self::OPTION_KEY, array_slice( $entries, 0, self::MAX ), false );
	}

	/**
	 * Entradas del log (más reciente primero).
	 *
	 * @return array<int, array{t: int, user: string, action: string, detail: string}>
	 */
	public static function entries(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}
}
