<?php
/**
 * Log — la bitácora del programa, en el sitio donde el dueño puede leerla.
 *
 * Los siete eventos críticos del carril de DINERO (candado no disponible,
 * INSERT del ledger caído, configuración de vigencia rota, invitación no
 * emitida, migración de esquema fallida) iban a `error_log()`: un fichero que
 * hay que abrir por SSH, que rota sin avisar y que mezcla el ruido de todos
 * los plugins. WooCommerce ya trae la bitácora con pantalla propia
 * (WooCommerce → Estado → Registros), retención y filtro por origen, y es el
 * estándar del plugin hermano.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.1
 */
final class Log {

	/**
	 * Origen del registro: el desplegable de la pantalla de Woo filtra por él.
	 */
	public const SOURCE = 'akibara-rewards';

	/**
	 * Registra un evento crítico de dinero.
	 *
	 * @param string $message Mensaje ya formateado.
	 * @return void
	 */
	public static function error( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => self::SOURCE ) );
			return;
		}

		// Sin el logger de Woo (activación a medias, arranque roto) el evento
		// NO puede quedar mudo: es la única traza de un movimiento de puntos
		// rechazado.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- último recurso; ver arriba.
		error_log( '[' . self::SOURCE . '] ' . $message );
	}
}
