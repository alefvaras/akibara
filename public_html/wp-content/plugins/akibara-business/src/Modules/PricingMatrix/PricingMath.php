<?php
/**
 * Motor matemático de precios y redondeo CLP sin decimales para Akibara.
 *
 * @package Akibara\Business\Modules\PricingMatrix
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PricingMatrix;

defined( 'ABSPATH' ) || exit;

final class PricingMath {

	public const MODE_ROUND_100 = 'round_100';
	public const MODE_FLOOR_100 = 'floor_100';
	public const MODE_ROUND_50  = 'round_50';
	public const MODE_EXACT_1   = 'exact_1';
	public const MODE_MANUAL    = 'manual';

	public const DEFAULT_DISCOUNT_PCT = 5.0;
	public const DEFAULT_MODE         = self::MODE_ROUND_100;

	/**
	 * Modos de redondeo soportados.
	 *
	 * @return list<string>
	 */
	public static function supported_modes(): array {
		return array(
			self::MODE_ROUND_100,
			self::MODE_FLOOR_100,
			self::MODE_ROUND_50,
			self::MODE_EXACT_1,
			self::MODE_MANUAL,
		);
	}

	/**
	 * Normaliza un modo de redondeo al contrato soportado.
	 *
	 * @param string $mode Modo candidato.
	 * @return string
	 */
	public static function normalize_mode( string $mode ): string {
		$trimmed = strtolower( trim( $mode ) );
		return in_array( $trimmed, self::supported_modes(), true ) ? $trimmed : self::DEFAULT_MODE;
	}

	/**
	 * Calcula el precio de oferta A Pedido / Reserva en CLP sin decimales.
	 *
	 * @param int      $regular_price Precio regular en CLP.
	 * @param float    $discount_pct  Porcentaje de descuento (ej. 5.0 para 5%).
	 * @param string   $rounding_mode Modo de redondeo (round_100|floor_100|round_50|exact_1|manual).
	 * @param int|null $manual_price  Monto manual fijo cuando el modo es manual.
	 * @return int
	 */
	public static function calculate_sale_price(
		int $regular_price,
		float $discount_pct = self::DEFAULT_DISCOUNT_PCT,
		string $rounding_mode = self::DEFAULT_MODE,
		?int $manual_price = null
	): int {
		if ( $regular_price <= 0 ) {
			return 0;
		}

		if ( self::MODE_MANUAL === $rounding_mode && null !== $manual_price && $manual_price > 0 && $manual_price < $regular_price ) {
			return $manual_price;
		}

		$clamped_pct = max( 0.0, min( 95.0, $discount_pct ) );
		$raw         = (float) $regular_price * ( ( 100.0 - $clamped_pct ) / 100.0 );

		switch ( $rounding_mode ) {
			case self::MODE_FLOOR_100:
				$result = (int) ( floor( $raw / 100.0 ) * 100.0 );
				break;

			case self::MODE_ROUND_50:
				$result = (int) ( round( $raw / 50.0, 0, PHP_ROUND_HALF_UP ) * 50.0 );
				break;

			case self::MODE_EXACT_1:
				$result = (int) round( $raw, 0, PHP_ROUND_HALF_UP );
				break;

			case self::MODE_ROUND_100:
			default:
				$result = (int) ( round( $raw / 100.0, 0, PHP_ROUND_HALF_UP ) * 100.0 );
				break;
		}

		return max( 0, min( $regular_price, $result ) );
	}
}
