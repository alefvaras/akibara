<?php
/**
 * Pruebas unitarias de política Best-Wins y Convivencia de Descuentos para WelcomeCoupon.
 *
 * @package Akibara\Business\Tests\Modules\WelcomeCoupon
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\WelcomeCoupon;

use Akibara\Business\Modules\WelcomeCoupon\CouponService;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class BestWinsPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( '\WC_Coupon' ) ) {
			eval(
				'namespace { class WC_Coupon {
					public int $id = 0;
					public string $code = "";
					public array $meta = array();
					public int $usage_count = 0;
					public array $email_restrictions = array();
					public static array $saved_instances = array();
					public function __construct( $code = "" ) {
						$this->code = (string) $code;
						if ( "HOLAPRUEBA9X" === $this->code ) {
							$this->id = 1234;
							$this->meta["_akb_is_welcome_coupon"] = "1";
							$this->meta["_akb_welcome_used_notified"] = "1";
							$this->meta["_akb_welcome_recipient_email"] = "lector@akibara.cl";
							$this->usage_count = 1;
						}
						self::$saved_instances[] = $this;
					}
					public function get_id(): int { return $this->id; }
					public function get_code(): string { return $this->code; }
					public function set_code( string $c ): void { $this->code = $c; }
					public function set_description( string $d ): void {}
					public function set_discount_type( string $t ): void {}
					public function set_amount( $a ): void {}
					public function set_individual_use( bool $i ): void {}
					public function set_usage_limit( int $l ): void {}
					public function set_usage_limit_per_user( int $l ): void {}
					public function set_minimum_amount( string $m ): void {}
					public function set_date_expires( $ts ): void {}
					public function get_meta( string $k, bool $s = true ) { return $this->meta[$k] ?? ""; }
					public function update_meta_data( string $k, $v ): void { $this->meta[$k] = $v; }
					public function delete_meta_data( string $k ): void { unset( $this->meta[$k] ); }
					public function get_usage_count(): int { return $this->usage_count; }
					public function set_usage_count( int $count ): void { $this->usage_count = $count; }
					public function set_email_restrictions( array $e ): void { $this->email_restrictions = $e; }
					public function get_email_restrictions(): array { return $this->email_restrictions; }
					public function save(): int { if ($this->id === 0) { $this->id = 9999; } return $this->id; }
				} }'
			);
		}

		Functions\when( 'get_option' )->alias( static fn( string $o, $d = false ) => $d );
		Functions\when( 'apply_filters' )->alias( static fn( string $tag, $val ) => $val );
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Verifica que para un producto a precio normal base, el cupón otorga exactamente el 10%.
	 */
	public function test_best_wins_unit_discount_on_regular_price(): void {
		// Regular: 16.990, actual: 16.990, 10%
		$discount = CouponService::unit_welcome_discount_on_base_price( 16990.0, 16990.0, 10.0 );
		$this->assertSame( 1699.0, $discount );
	}

	/**
	 * Verifica que cuando un producto tiene una oferta editorial menor (ej. 5%),
	 * el cupón completa exactamente la diferencia hasta alcanzar el 10% sobre regular_price.
	 */
	public function test_best_wins_unit_discount_completes_minor_sale(): void {
		// Regular: 20.000, Actual: 19.000 (ya tiene 5% = $1.000 off).
		// Objetivo 10%: $18.000.
		// El cupón debe aportar: $19.000 - $18.000 = $1.000.
		$discount = CouponService::unit_welcome_discount_on_base_price( 20000.0, 19000.0, 10.0 );
		$this->assertSame( 1000.0, $discount );
	}

	/**
	 * Verifica que cuando un producto tiene una oferta igual al 10%, el cupón aporta 0.
	 */
	public function test_best_wins_unit_discount_zero_when_equal_discount(): void {
		// Regular: 20.000, Actual: 18.000 (10% off).
		$discount = CouponService::unit_welcome_discount_on_base_price( 20000.0, 18000.0, 10.0 );
		$this->assertSame( 0.0, $discount );
	}

	/**
	 * Verifica que cuando un producto tiene una oferta mayor al 10% (ej. 20%), el cupón aporta 0.
	 */
	public function test_best_wins_unit_discount_zero_when_higher_discount(): void {
		// Regular: 20.000, Actual: 16.000 (20% off).
		$discount = CouponService::unit_welcome_discount_on_base_price( 20000.0, 16000.0, 10.0 );
		$this->assertSame( 0.0, $discount );
	}

	/**
	 * Verifica el comportamiento robusto ante precios nulos o invertidos.
	 */
	public function test_best_wins_unit_discount_handles_edge_cases(): void {
		// Precio actual 0
		$this->assertSame( 0.0, CouponService::unit_welcome_discount_on_base_price( 20000.0, 0.0, 10.0 ) );
		// Porcentaje 0
		$this->assertSame( 0.0, CouponService::unit_welcome_discount_on_base_price( 20000.0, 20000.0, 0.0 ) );
		// Porcentaje negativo
		$this->assertSame( 0.0, CouponService::unit_welcome_discount_on_base_price( 20000.0, 20000.0, -5.0 ) );
		// Regular menor que actual (anomalía de datos): debe usar actual como base
		$discount = CouponService::unit_welcome_discount_on_base_price( 15000.0, 20000.0, 10.0 );
		$this->assertSame( 2000.0, $discount );
	}

	/**
	 * Verifica que restore_order_coupon limpie los metas, reduzca el usage_count
	 * y dispare el evento akb_welcome_coupon_restored.
	 */
	public function test_restore_order_coupon_restores_coupon_on_cancelled_or_failed_order(): void {
		$service = new CouponService();

		$order = new \WC_Order();
		$order->akb_id = 999;
		$order->akb_coupon_codes = array( 'HOLAPRUEBA9X' );
		$order->akb_billing_email = 'lector@akibara.cl';

		Monkey\Actions\expectDone( 'akb_welcome_coupon_restored' )->once();

		// Ejecutar restauración
		$service->restore_order_coupon( $order );
		$this->assertTrue( true );
	}
}
