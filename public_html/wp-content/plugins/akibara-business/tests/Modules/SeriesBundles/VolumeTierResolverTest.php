<?php
/**
 * Pruebas de validación de tramos de descuento por serie.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use PHPUnit\Framework\TestCase;

final class VolumeTierResolverTest extends TestCase {

	/**
	 * @testdox Una serie habilitada no puede quedar sin tramos
	 */
	public function test_rejects_empty_tier_configuration(): void {
		$result = ( new VolumeTierResolver() )->normalize_tiers( array() );

		self::assertFalse( $result->is_ok() );
		self::assertSame( 'Una serie habilitada debe tener al menos un tramo de descuento', $result->message() );
	}

	/**
	 * @testdox Los descuentos deben crecer junto con la cantidad
	 */
	public function test_rejects_non_increasing_discounts(): void {
		$resolver = new VolumeTierResolver();

		self::assertFalse(
			$resolver->normalize_tiers(
				array(
					array( 'min' => 5, 'pct' => 10 ),
					array( 'min' => 7, 'pct' => 8 ),
				)
			)->is_ok()
		);
	}

	/**
	 * @testdox Acepta una progresión válida de tramos
	 */
	public function test_accepts_valid_tier_progression(): void {
		$result = ( new VolumeTierResolver() )->normalize_tiers(
			array(
				array( 'min' => 7, 'pct' => 10 ),
				array( 'min' => 5, 'pct' => 8 ),
			)
		);

		self::assertTrue( $result->is_ok() );
	}

	/**
	 * @testdox Colapsa tramos heredados con igual porcentaje sin cambiar el descuento efectivo
	 */
	public function test_coalesces_legacy_duplicate_percentages(): void {
		$resolver = new VolumeTierResolver();
		$tiers    = $resolver->coalesce_equal_pct_tiers(
			$resolver->sort_tiers(
				array(
					array( 'min' => 10, 'pct' => 15 ),
					array( 'min' => 7, 'pct' => 10 ),
					array( 'min' => 5, 'pct' => 10 ),
				)
			)
		);

		self::assertSame(
			array(
				array( 'min' => 5, 'pct' => 10 ),
				array( 'min' => 10, 'pct' => 15 ),
			),
			$tiers
		);
	}
}
