<?php
/**
 * Pruebas del resolver seguro de envío gratis.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Services\FreeShippingThreshold;
use PHPUnit\Framework\TestCase;

final class FreeShippingThresholdTest extends TestCase {

	public function test_minimum_amount_uses_post_discount_total_by_default(): void {
		$result = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'min_amount', 55000, 'no' ),
				),
			),
			60000,
			10000,
			0,
			false,
			false
		);

		self::assertSame( FreeShippingThreshold::STATE_PROGRESS, $result['state'] );
		self::assertSame( 50000.0, $result['total'] );
		self::assertSame( 55000.0, $result['threshold'] );
		self::assertSame( 5000.0, $result['remaining'] );
	}

	public function test_ignore_discounts_matches_woocommerce_pre_discount_rule(): void {
		$result = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'min_amount', 55000, 'yes' ),
				),
			),
			60000,
			10000,
			0,
			false,
			false
		);

		self::assertSame( FreeShippingThreshold::STATE_QUALIFIED, $result['state'] );
		self::assertSame( 'order_amount', $result['reason'] );
		self::assertSame( 55000.0, $result['threshold'] );
	}

	public function test_discount_tax_is_subtracted_only_when_prices_include_tax(): void {
		$including_tax = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'min_amount', 55000, 'no' ),
				),
			),
			60000,
			3000,
			2500,
			true,
			false
		);
		$excluding_tax = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'min_amount', 55000, 'no' ),
				),
			),
			60000,
			3000,
			2500,
			false,
			false
		);

		self::assertSame( 54500.0, $including_tax['total'] );
		self::assertSame( 500.0, $including_tax['remaining'] );
		self::assertSame( FreeShippingThreshold::STATE_QUALIFIED, $excluding_tax['state'] );
	}

	public function test_coupon_only_method_does_not_promise_progress_without_coupon(): void {
		$without_coupon = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'coupon', 0, 'no' ),
				),
			),
			100000,
			0,
			0,
			false,
			false
		);
		$with_coupon = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'coupon', 0, 'no' ),
				),
			),
			1000,
			0,
			0,
			false,
			true
		);

		self::assertSame( FreeShippingThreshold::STATE_UNAVAILABLE, $without_coupon['state'] );
		self::assertSame( FreeShippingThreshold::STATE_QUALIFIED, $with_coupon['state'] );
		self::assertSame( 'coupon', $with_coupon['reason'] );
	}

	public function test_either_supports_coupon_or_amount_paths(): void {
		$by_amount = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'either', 55000, 'no' ),
				),
			),
			50000,
			0,
			0,
			false,
			false
		);
		$by_coupon = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'either', 55000, 'no' ),
				),
			),
			1000,
			0,
			0,
			false,
			true
		);

		self::assertSame( FreeShippingThreshold::STATE_PROGRESS, $by_amount['state'] );
		self::assertSame( 5000.0, $by_amount['remaining'] );
		self::assertSame( FreeShippingThreshold::STATE_QUALIFIED, $by_coupon['state'] );
		self::assertSame( 'coupon', $by_coupon['reason'] );
	}

	public function test_both_hides_progress_until_required_coupon_exists(): void {
		$without_coupon = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'both', 55000, 'no' ),
				),
			),
			50000,
			0,
			0,
			false,
			false
		);
		$with_coupon = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'both', 55000, 'no' ),
				),
			),
			50000,
			0,
			0,
			false,
			true
		);

		self::assertSame( FreeShippingThreshold::STATE_UNAVAILABLE, $without_coupon['state'] );
		self::assertSame( FreeShippingThreshold::STATE_PROGRESS, $with_coupon['state'] );
		self::assertSame( 5000.0, $with_coupon['remaining'] );
	}

	public function test_multiple_packages_use_the_largest_remaining_amount(): void {
		$result = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'min_amount', 55000, 'no' ),
				),
				array(
					$this->method( 'min_amount', 70000, 'no' ),
				),
			),
			50000,
			0,
			0,
			false,
			false
		);

		self::assertSame( FreeShippingThreshold::STATE_PROGRESS, $result['state'] );
		self::assertSame( 70000.0, $result['threshold'] );
		self::assertSame( 20000.0, $result['remaining'] );
	}

	public function test_any_package_without_reachable_free_shipping_hides_global_bar(): void {
		$result = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'min_amount', 55000, 'no' ),
				),
				array(
					$this->method( 'coupon', 0, 'no' ),
				),
			),
			50000,
			0,
			0,
			false,
			false
		);

		self::assertSame( FreeShippingThreshold::STATE_UNAVAILABLE, $result['state'] );
	}

	public function test_lower_override_cannot_reduce_real_woocommerce_threshold(): void {
		$result = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( 'min_amount', 55000, 'no' ),
				),
			),
			40000,
			0,
			0,
			false,
			false,
			30000
		);

		self::assertSame( 55000.0, $result['threshold'] );
		self::assertSame( 15000.0, $result['remaining'] );
	}

	public function test_external_availability_veto_wins_once_core_requirement_is_met(): void {
		$method                      = $this->method( 'min_amount', 55000, 'no' );
		$method['current_available'] = false;

		$result = FreeShippingThreshold::evaluate_packages(
			array( array( $method ) ),
			60000,
			0,
			0,
			false,
			false
		);

		self::assertSame( FreeShippingThreshold::STATE_UNAVAILABLE, $result['state'] );
	}

	public function test_actual_woocommerce_availability_is_authoritative(): void {
		$method                      = $this->method( 'both', 55000, 'no' );
		$method['current_available'] = true;

		$result = FreeShippingThreshold::evaluate_packages(
			array( array( $method ) ),
			1000,
			0,
			0,
			false,
			false
		);

		self::assertSame( FreeShippingThreshold::STATE_QUALIFIED, $result['state'] );
	}

	public function test_no_requirement_is_immediately_qualified(): void {
		$result = FreeShippingThreshold::evaluate_packages(
			array(
				array(
					$this->method( '', 0, 'no' ),
				),
			),
			0,
			0,
			0,
			false,
			false
		);

		self::assertSame( FreeShippingThreshold::STATE_QUALIFIED, $result['state'] );
	}

	public function test_carrier_fallback_is_off_without_a_declared_threshold(): void {
		self::assertSame(
			array(),
			FreeShippingThreshold::carrier_fallback_for_enabled_methods( array( 'bluex-ex' ), 0.0 )
		);
	}

	public function test_carrier_fallback_needs_a_home_delivery_method_in_the_zone(): void {
		// Una zona que sólo retira no puede sostener la promesa: en Akibara el
		// retiro en Punto Blue se cotiza aparte y nunca queda gratis por monto.
		self::assertSame(
			array(),
			FreeShippingThreshold::carrier_fallback_for_enabled_methods(
				array( 'local_pickup', 'bluex-pudo', 'pickup_location' ),
				55000.0
			)
		);
	}

	public function test_carrier_fallback_declares_a_min_amount_method_of_unknown_availability(): void {
		$methods = FreeShippingThreshold::carrier_fallback_for_enabled_methods(
			array( 'bluex-pudo', 'local_pickup', 'bluex-ex' ),
			55000.0
		);

		self::assertCount( 1, $methods );
		self::assertSame( 'min_amount', $methods[0]['requires'] );
		self::assertSame( 55000.0, $methods[0]['min_amount'] );
		self::assertSame( 'no', $methods[0]['ignore_discounts'] );
		// La tarifa real la cotiza el transportista en el checkout: afirmar
		// disponibilidad aquí sería inventar una autoridad que no tenemos.
		self::assertNull( $methods[0]['current_available'] );
	}

	public function test_carrier_fallback_feeds_the_same_progress_semantics_as_a_core_method(): void {
		$methods = FreeShippingThreshold::carrier_fallback_for_enabled_methods(
			array( 'bluex-ex' ),
			55000.0
		);

		$progress = FreeShippingThreshold::evaluate_packages(
			array( $methods ),
			49950,
			0,
			0,
			false,
			false
		);

		self::assertSame( FreeShippingThreshold::STATE_PROGRESS, $progress['state'] );
		self::assertSame( 55000.0, $progress['threshold'] );
		self::assertSame( 5050.0, $progress['remaining'] );

		$qualified = FreeShippingThreshold::evaluate_packages(
			array( $methods ),
			59940,
			0,
			0,
			false,
			false
		);

		self::assertSame( FreeShippingThreshold::STATE_QUALIFIED, $qualified['state'] );
		self::assertSame( 'order_amount', $qualified['reason'] );
	}

	/**
	 * @return array{
	 *   requires: string,
	 *   min_amount: float,
	 *   ignore_discounts: string,
	 *   current_available: null
	 * }
	 */
	private function method( string $requires, float $min_amount, string $ignore_discounts ): array {
		return array(
			'requires'          => $requires,
			'min_amount'        => $min_amount,
			'ignore_discounts'  => $ignore_discounts,
			'current_available' => null,
		);
	}
}
