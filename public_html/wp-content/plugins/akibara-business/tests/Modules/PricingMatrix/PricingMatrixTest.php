<?php
/**
 * Pruebas unitarias para el módulo PricingMatrix (Motor Matemático CLP, Guardias y Aliases).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PricingMatrix;

use Akibara\Business\Modules\PricingMatrix\BatchGuard;
use Akibara\Business\Modules\PricingMatrix\OrderLockGuard;
use Akibara\Business\Modules\PricingMatrix\PricingMath;
use Akibara\Business\Modules\PricingMatrix\ReactiveStockPriceHooks;
use PHPUnit\Framework\TestCase;

final class PricingMatrixTest extends TestCase {

	/**
	 * @testdox El redondeo comercial a la centena (round_100) con 5% de descuento coincide exactamente con la tabla maestra CLP
	 */
	public function test_canonical_clp_scales_with_round_100(): void {
		$cases = array(
			10600 => 10100,
			10900 => 10400,
			11500 => 10900,
			13500 => 12800,
			14500 => 13800,
			21300 => 20200,
			27500 => 26100,
			29500 => 28000,
		);

		foreach ( $cases as $regular => $expected_sale ) {
			self::assertSame(
				$expected_sale,
				PricingMath::calculate_sale_price( $regular, 5.0, PricingMath::MODE_ROUND_100 ),
				sprintf( 'Falló escala CLP $%d con round_100', $regular )
			);
		}
	}

	/**
	 * @testdox Soporta modos alternativos de redondeo CLP (floor_100, round_50, exact_1 y manual) sin decimales
	 */
	public function test_alternative_rounding_modes_never_produce_decimals(): void {
		self::assertSame( 10000, PricingMath::calculate_sale_price( 10600, 5.0, PricingMath::MODE_FLOOR_100 ) );
		self::assertSame( 10050, PricingMath::calculate_sale_price( 10600, 5.0, PricingMath::MODE_ROUND_50 ) );
		self::assertSame( 10070, PricingMath::calculate_sale_price( 10600, 5.0, PricingMath::MODE_EXACT_1 ) );
		self::assertSame( 9990, PricingMath::calculate_sale_price( 10600, 5.0, PricingMath::MODE_MANUAL, 9990 ) );
		self::assertSame( PricingMath::MODE_ROUND_100, PricingMath::normalize_mode( 'modo-invalido' ) );
	}

	/**
	 * @testdox Expone los aliases globales AKB_Batch_Guard y AKB_Order_Lock_Guard y protege las claves singleton contra duplicación
	 */
	public function test_guards_and_singleton_meta_keys_contract(): void {
		self::assertTrue( class_exists( BatchGuard::class ) );
		self::assertTrue( class_exists( OrderLockGuard::class ) );
		self::assertTrue( class_exists( '\\AKB_Batch_Guard' ) );
		self::assertTrue( class_exists( '\\AKB_Order_Lock_Guard' ) );

		$hooks    = new ReactiveStockPriceHooks();
		$excluded = $hooks->exclude_duplicate_meta( array( '_custom_existing' ) );

		self::assertContains( '_akb_onbackorder_at', $excluded );
		self::assertContains( '_akb_pdp_specs_generation', $excluded );
		self::assertContains( 'rank_math_internal_links_processed', $excluded );
		self::assertContains( '_akb_tramo_prev_regular', $excluded );
		self::assertContains( '_akb_tramo_prev_sale', $excluded );
	}
}
