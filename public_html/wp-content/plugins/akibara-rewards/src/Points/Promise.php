<?php
/**
 * Lo que la tienda puede PROMETER hoy sobre acumular puntos.
 *
 * La acreditación posee una compuerta de vigencia (`Accrual::in_program()`):
 * si la fecha de inicio del programa está en el futuro o no es válida, no se
 * acreditan puntos.
 *
 * Esta clase es la puerta unificada por la que cualquier superficie pública consulta
 * qué puntos pueden prometerse actualmente, garantizando que nunca se prometan
 * puntos que el motor de acreditación rechazaría.
 *
 * Esta clase es la ÚNICA puerta por la que una superficie pregunta «¿cuánto
 * prometo?». La regla de vigencia sigue viviendo en `Accrual`; aquí sólo se
 * decide que, fuera de vigencia, la respuesta es cero.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 */
final class Promise {

	/**
	 * Puntos que se pueden prometer HOY por una base en pesos.
	 *
	 * @param float $base    Base en pesos (IVA incluido).
	 * @param int   $user_id ID opcional del cliente para aplicar multiplicador de nivel.
	 * @return int 0 si el programa no rige ahora o el carril está apagado.
	 */
	public static function points_for( float $base, int $user_id = 0 ): int {
		return Accrual::program_open_now() ? Calculator::points_for( $base, $user_id ) : 0;
	}

	/**
	 * Promesa del carrito calculado, con el mismo canje y base que la acreditación.
	 *
	 * No calcula ni modifica totales: el consumidor entrega el carrito de WooCommerce.
	 */
	public static function for_cart( ?\WC_Cart $cart, int $user_id = 0 ): int {
		if ( null === $cart ) {
			return 0;
		}
		return self::points_for( Calculator::award_base_for_cart( $cart, self::cart_redeemed_gross( $cart ) ), $user_id );
	}

	/** El fee efectivo incluye impuestos; la intención de canje todavía no es un descuento. */
	private static function cart_redeemed_gross( \WC_Cart $cart ): float {
		foreach ( $cart->get_fees() as $fee ) {
			if ( ! is_object( $fee ) || Redemption::FEE_NAME !== ( $fee->name ?? '' ) ) {
				continue;
			}
			$total = (float) ( $fee->total ?? 0 ) + (float) ( $fee->tax ?? 0 );
			if ( $total < 0 ) {
				return -$total;
			}
		}
		return 0.0;
	}

	/**
	 * El tramo que se puede anunciar HOY («2 puntos por cada $100»).
	 *
	 * @return array{points:int,amount:int} Ceros si no hay nada que prometer.
	 */
	public static function rate(): array {
		if ( ! Accrual::program_open_now() ) {
			return array(
				'points' => 0,
				'amount' => 0,
			);
		}

		return array(
			'points' => Options::earn_points(),
			'amount' => Options::earn_amount(),
		);
	}

	/**
	 * El bono de bienvenida que se puede anunciar HOY.
	 *
	 * El bono de bienvenida se acredita tras la primera compra elegible.
	 * Si el programa no se encuentra activo o vigente, retorna 0 para no
	 * prometer beneficios no aplicables.
	 *
	 * @return int 0 si el programa no rige ahora o el carril está apagado.
	 */
	public static function welcome_bonus(): int {
		return Accrual::program_open_now() ? Options::welcome_bonus() : 0;
	}

	/**
	 * El premio al padrino que se puede anunciar HOY.
	 *
	 * Misma compuerta: `Referrals::reason_not_to_pay()` rechaza el pedido del
	 * invitado que no está `in_program()`.
	 *
	 * @return int 0 si el programa no rige ahora o el carril está apagado.
	 */
	public static function referral_points(): int {
		return Accrual::program_open_now() ? Options::referral_points() : 0;
	}
}
