<?php
/**
 * Bitácora de los fallos del selector de Puntos Blue Express.
 *
 * Las seis rutas de PUDO capturaban `\Throwable` y lo descartaban con
 * `unset( $throwable )`. Degradar sin romper el checkout es lo correcto —el
 * cliente cae a envío a domicilio en vez de ver una pantalla rota—, pero el
 * dueño se quedaba sin saber que Blue Express estaba fallando: no había ni una
 * línea de registro en todo el plugin. Una caída del proveedor se veía sólo como
 * «bajaron los retiros en punto», semanas después y sin explicación.
 *
 * Escribe en el logger de WooCommerce, que ya tiene su visor en
 * WooCommerce → Estado → Registros. No se inventa una superficie nueva.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

defined( 'ABSPATH' ) || exit;

/**
 * @since 2.2.9
 */
final class PudoLog {

	public const SOURCE = 'akibara-pudo';

	/**
	 * Ventana de silencio por contexto.
	 *
	 * Una caída del proveedor afecta a TODAS las peticiones a la vez. Sin techo,
	 * un incidente de una hora deja decenas de miles de líneas idénticas y el
	 * registro se vuelve inservible justo cuando hace falta leerlo.
	 */
	private const THROTTLE_SECONDS = 60;

	/**
	 * Registra un fallo capturado, como mucho una vez por minuto y contexto.
	 *
	 * @param string     $context   Ruta donde ocurrió (p. ej. 'filter_package_rates').
	 * @param \Throwable $throwable Excepción capturada.
	 * @return void
	 */
	public static function failure( string $context, \Throwable $throwable ): void {
		if ( ! self::should_log( $context ) ) {
			return;
		}

		$message = sprintf(
			'%s: %s (%s en %s:%d)',
			$context,
			$throwable->getMessage(),
			get_class( $throwable ),
			basename( $throwable->getFile() ),
			$throwable->getLine()
		);

		// Enriquecimiento de log con código de error y detalle estructurado si están disponibles.
		// Aplica duck typing para desacoplar de clases de excepción específicas de akibara-business.
		if ( is_callable( array( $throwable, 'error_code' ) ) ) {
			$message .= ' · código=' . (string) $throwable->error_code();
		}
		if ( is_callable( array( $throwable, 'details' ) ) ) {
			$details = $throwable->details();
			if ( is_array( $details ) && array() !== $details ) {
				$message .= ' · detalle=' . (string) wp_json_encode( $details, JSON_UNESCAPED_UNICODE );
			}
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			if ( is_object( $logger ) && method_exists( $logger, 'error' ) ) {
				$logger->error( $message, array( 'source' => self::SOURCE ) );
				return;
			}
		}

		// Sin WooCommerce cargado (CLI temprano, tests) queda el registro de PHP,
		// y sólo si el sitio lo tiene activado.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[' . self::SOURCE . '] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- fallback sin WooCommerce, sólo con WP_DEBUG.
		}
	}

	/**
	 * Cuota por contexto, con degradación abierta.
	 *
	 * Si el almacén de transients no está disponible se registra igual: perder
	 * la señal de un incidente es peor que repetirla.
	 */
	private static function should_log( string $context ): bool {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return true;
		}

		$key = 'akb_pudo_log_' . md5( $context );
		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, self::THROTTLE_SECONDS );

		return true;
	}
}
