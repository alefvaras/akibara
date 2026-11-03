<?php
/**
 * DiscountCeiling — el tope máximo de descuento de la tienda, en UN solo lugar.
 *
 * Hasta 1.110.0 el 15% estaba clavado DOS veces —`TaxonomyDiscount\Settings::MAX_PCT`
 * (descuento editorial) y `SeriesBundles\VolumeTierResolver::PCT_MAX` (Arma tu Pack)—
 * y el dueño no tenía dónde cambiarlo. Son el mismo número de negocio: un techo de
 * margen. Vive acá, fuera de los dos módulos, para que ninguno sea dueño del otro.
 *
 * Por qué hay techo duro: un % se aplica sobre el precio regular y, sumado al envío
 * gratis, puede vender BAJO COSTO. El campo del panel acota 1..90 y este sanitizado
 * repite el clamp, porque una option escrita por WP-CLI no pasa por el formulario.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.1
 */
final class DiscountCeiling {

	/** Option única que edita el dueño en Akibara → Descuentos. */
	public const OPTION = 'akb_discount_max_pct';

	/**
	 * El literal que la tienda tenía clavado antes de existir la pantalla. Es el
	 * default del campo Y el fallback del lector: estrenar la configurabilidad no
	 * mueve ni un precio.
	 */
	public const DEFAULT_PCT = 15;

	/** Piso absoluto: un tope de 0 anularía el descuento en vez de acotarlo. */
	public const HARD_MIN = 1;

	/** Techo absoluto histórico del clamp de `Settings::max_pct()`. */
	public const HARD_MAX = 90;

	/**
	 * Tope efectivo configurado. Un valor ausente o ilegible cae al literal de
	 * siempre; uno fuera de rango se acota, nunca se ignora.
	 */
	public static function pct(): int {
		return self::sanitize( get_option( self::OPTION, self::DEFAULT_PCT ) );
	}

	/**
	 * Normaliza un candidato al dominio del tope.
	 *
	 * Se exige forma entera CANÓNICA antes de castear: `(int) '15%'` da 15 y
	 * `(int) 'nada'` da 0, así que castear a ciegas convierte basura en un tope
	 * plausible o en el piso. Lo que no es un entero cae al default.
	 *
	 * @param mixed $raw Candidato crudo (formulario, option o WP-CLI).
	 */
	public static function sanitize( $raw ): int {
		if ( is_int( $raw ) ) {
			return max( self::HARD_MIN, min( self::HARD_MAX, $raw ) );
		}

		if ( ! is_string( $raw ) && ! is_float( $raw ) ) {
			return self::DEFAULT_PCT;
		}

		$candidate = trim( (string) $raw );
		if ( 1 !== preg_match( '/^-?(0|[1-9][0-9]*)$/D', $candidate ) ) {
			return self::DEFAULT_PCT;
		}

		return max( self::HARD_MIN, min( self::HARD_MAX, (int) $candidate ) );
	}
}
