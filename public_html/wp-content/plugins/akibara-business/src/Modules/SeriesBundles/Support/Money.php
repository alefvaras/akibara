<?php
/**
 * Series Bundles — Support\Money.
 *
 * Helpers de dinero para la lógica pura del módulo:
 *   - `round()` redondea al número de decimales configurado por
 *     `wc_get_price_decimals()` (Req 3.6), con fallback a 0 si la función no
 *     existe (mismo patrón que `TaxonomyDiscount\DiscountResolver::round`).
 *   - `clamp_non_negative()` acota un monto a un mínimo de 0 (Req 3.4, 4.3).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles\Support;

defined( 'ABSPATH' ) || exit;

final class Money {

	/**
	 * Redondea al número de decimales de precio de WooCommerce.
	 * Fallback a 0 decimales si `wc_get_price_decimals()` no está disponible.
	 */
	public static function round( float $amount ): float {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 0;
		return round( $amount, $decimals );
	}

	/**
	 * Acota un monto a un mínimo de 0 (nunca negativo).
	 */
	public static function clamp_non_negative( float $amount ): float {
		return $amount < 0.0 ? 0.0 : $amount;
	}
}
