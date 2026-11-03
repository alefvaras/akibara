<?php
/**
 * Log — canal mínimo y uniforme de logging para akibara-business.
 *
 * Wrapper de wc_get_logger() con source por módulo (`akibara-{modulo}`),
 * mismo esquema que ya usa BackInStock ('akibara-bis'). Existe para que los
 * catch de rutas async dejen evidencia en WooCommerce → Estado → Registros
 * en vez de tragar la excepción en silencio.
 *
 * Los llamadores NO cambian su control de flujo: el fallback sigue siendo el
 * mismo; esto solo agrega la traza.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.98.0
 */
final class Log {

	/**
	 * Registra un warning en el log de WooCommerce.
	 *
	 * @param string               $module  Sufijo del source (ej. 'preventa' → 'akibara-preventa').
	 * @param string               $message Mensaje legible con el contexto esencial inline.
	 * @param array<string, mixed> $context Contexto extra opcional (lo serializa el handler de WC).
	 * @return void
	 */
	public static function warn( string $module, string $message, array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return; // WooCommerce ausente (tests unitarios / bootstrap parcial).
		}

		wc_get_logger()->warning(
			$message,
			array_merge( array( 'source' => 'akibara-' . $module ), $context )
		);
	}

	/**
	 * Registra un hecho (no un fallo) en el log de WooCommerce: lo que un
	 * cron hizo solo y el dueño querrá poder leer después.
	 *
	 * @since 1.116.5
	 *
	 * @param string               $module  Sufijo del source (ej. 'descuentos' → 'akibara-descuentos').
	 * @param string               $message Mensaje legible con el contexto esencial inline.
	 * @param array<string, mixed> $context Contexto extra opcional.
	 * @return void
	 */
	public static function info( string $module, string $message, array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->info(
			$message,
			array_merge( array( 'source' => 'akibara-' . $module ), $context )
		);
	}
}
