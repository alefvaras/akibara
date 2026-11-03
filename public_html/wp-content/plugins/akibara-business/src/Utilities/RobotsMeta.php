<?php
/**
 * Parser único para las directivas robots persistidas por Rank Math.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/** Evita que distintas superficies SEO interpreten la misma meta de otro modo. */
final class RobotsMeta {

	/**
	 * Un valor ausente es indexable; una forma corrupta falla cerrada.
	 *
	 * Rank Math normalmente devuelve una lista ya deserializada, pero se acepta
	 * también el string plano o serializado que dejan algunas migraciones. Los
	 * arrays mixtos se consideran ilegibles: nunca deben convertir una URL
	 * incierta en enlace rastreable.
	 *
	 * @param mixed $robots Valor de `rank_math_robots` leído con `single=true`.
	 */
	public static function is_indexable( $robots ): bool {
		if ( is_string( $robots ) ) {
			return '' === trim( $robots ) || false === stripos( $robots, 'noindex' );
		}

		if ( ! is_array( $robots ) ) {
			return false;
		}

		foreach ( $robots as $directive ) {
			if ( ! is_string( $directive ) || ! self::is_indexable( $directive ) ) {
				return false;
			}
		}

		return true;
	}
}
