<?php
/**
 * Gestión de alertas y notificaciones por correo ante caídas prolongadas del índice de búsqueda.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search\Rebuild;

use Akibara\Search\AuditLog;

defined( 'ABSPATH' ) || exit;

/**
 * Alarma de disponibilidad de búsqueda y servido de facetas.
 *
 * @since 0.1.85
 */
final class ServingAlarm {

	/** Cuánto aguanta el serving caído antes de que salga el aviso (15 minutos). */
	public const ALARM_AFTER_SECONDS = 900;

	/** Estado del aviso: desde cuándo está caído y si ya se avisó. */
	public const OPTION_ALARM = 'akb_search_serving_alarm';

	/**
	 * Registra la persistencia de una caída en el servido y despacha alerta al administrador si supera el umbral.
	 *
	 * @return void
	 */
	public static function note_serving_down(): void {
		$estado  = get_option( self::OPTION_ALARM, array() );
		$estado  = is_array( $estado ) ? $estado : array();
		$desde   = (int) ( $estado['down_since'] ?? 0 );
		$avisado = (int) ( $estado['notified_at'] ?? 0 );
		$ahora   = time();

		if ( $desde <= 0 ) {
			update_option( self::OPTION_ALARM, array( 'down_since' => $ahora, 'notified_at' => 0 ), false );
			return;
		}
		if ( $avisado > 0 || ( $ahora - $desde ) < self::ALARM_AFTER_SECONDS ) {
			return;
		}

		update_option( self::OPTION_ALARM, array( 'down_since' => $desde, 'notified_at' => $ahora ), false );
		self::send_alarm(
			'Akibara: el buscador lleva rato caído',
			"El buscador y los filtros del catálogo llevan " . (int) floor( ( $ahora - $desde ) / 60 ) . " minutos sin servir.\n\n"
			. "El cliente ve el catálogo completo SIN filtros y la búsqueda no responde. El sistema ya intentó\n"
			. "recuperarse solo varias veces y no lo ha conseguido.\n\n"
			. "Qué mirar, por orden:\n"
			. "  wp akb-search status\n"
			. "  wp akb-search rebuild\n\n"
			. "Si el rebuild dice que hay uno en curso, comprueba en el estado que «done» avanza y que\n"
			. "«work_blocked» está en cero: si avanza, es un rebuild normal y sólo hay que esperar."
		);
		AuditLog::record( 'serving_alarma', 'Serving caído más de ' . self::ALARM_AFTER_SECONDS . ' s: aviso enviado' );
	}

	/**
	 * Limpia el estado del aviso y avisa de la vuelta si se había avisado de la caída.
	 *
	 * @return void
	 */
	public static function note_serving_up(): void {
		if ( self::close_alarm_episode() ) {
			self::send_alarm(
				'Akibara: el buscador volvió',
				"El buscador y los filtros del catálogo vuelven a servir con normalidad."
			);
			AuditLog::record( 'serving_alarma', 'Serving recuperado: aviso de vuelta enviado' );
		}
	}

	/**
	 * Cierra el episodio de alarma cuando el carril de autocompletado es desactivado intencionalmente.
	 *
	 * @return void
	 */
	public static function note_lane_off(): void {
		if ( self::close_alarm_episode() ) {
			AuditLog::record( 'serving_alarma', 'Autocompletado apagado con un aviso de caída enviado: episodio cerrado sin aviso de vuelta' );
		}
	}

	/**
	 * Borra el estado del aviso y retorna si ya se había emitido alerta.
	 *
	 * @return bool True si el episodio que se cierra ya había mandado el correo de caída.
	 */
	public static function close_alarm_episode(): bool {
		$estado = get_option( self::OPTION_ALARM, array() );
		if ( ! is_array( $estado ) || array() === $estado ) {
			return false;
		}
		delete_option( self::OPTION_ALARM );

		return (int) ( $estado['notified_at'] ?? 0 ) > 0;
	}

	/**
	 * Manda el aviso al correo del sitio (sólo en producción).
	 *
	 * @param string $asunto Asunto del correo.
	 * @param string $cuerpo Cuerpo en texto plano.
	 * @return void
	 */
	public static function send_alarm( string $asunto, string $cuerpo ): void {
		if ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
			return;
		}
		$destino = (string) get_option( 'admin_email', '' );
		if ( '' === $destino || ! function_exists( 'wp_mail' ) ) {
			return;
		}
		wp_mail( $destino, $asunto, $cuerpo );
	}
}
