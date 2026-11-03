<?php
/**
 * Pruebas del cálculo de ahorro de packs por serie.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\DiscountEngine;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class DiscountEngineTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// El motor redondea con `wc_get_price_decimals()`. Sin declararlo acá, el
		// test sólo pasaba cuando OTRO test lo había definido antes: verde
		// dependiente del orden [ronda 7.1].
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Cinco tomos de 9.990 al 10% ahorran 4.995 y totalizan 44.955
	 */
	public function test_calculates_displayed_savings_and_total(): void {
		$items    = $this->items( 5, 9990.0, 9990.0 );
		$discount = $this->engine()->calc_byp_discount(
			$items,
			array( array( 'min' => 5, 'pct' => 10 ) )
		);

		self::assertSame( 4995.0, $discount );
		self::assertSame( 44955.0, array_sum( array_column( $items, 'current_price' ) ) - $discount );
	}

	/**
	 * @testdox Una oferta mejor conserva su precio y el pack solo aporta la diferencia restante
	 */
	public function test_existing_better_offer_wins(): void {
		$items                         = $this->items( 5, 9990.0, 9990.0 );
		$items[0]['current_price']     = 8000.0;

		self::assertSame(
			3996.0,
			$this->engine()->calc_byp_discount(
				$items,
				array( array( 'min' => 5, 'pct' => 10 ) )
			)
		);
	}

	/**
	 * @testdox La cantidad repetida de cuatro productos no simula cinco tomos distintos
	 */
	public function test_tier_counts_distinct_items_not_units(): void {
		$items = $this->items( 4, 9990.0, 9990.0, 2 );

		self::assertSame(
			0.0,
			$this->engine()->calc_byp_discount(
				$items,
				array( array( 'min' => 5, 'pct' => 10 ) )
			)
		);
	}

	/**
	 * @testdox Una cantidad repetida recibe el beneficio una sola vez por tomo distinto
	 */
	public function test_discount_is_capped_to_one_unit_per_distinct_product(): void {
		$items = $this->items( 5, 9990.0, 9990.0, 10 );

		self::assertSame(
			4995.0,
			$this->engine()->calc_byp_discount(
				$items,
				array( array( 'min' => 5, 'pct' => 10 ) )
			)
		);
	}

	/**
	 * @testdox Un DTO de cantidad cero no cuenta para alcanzar el tramo
	 */
	public function test_non_positive_quantity_does_not_count_towards_tier(): void {
		$items           = $this->items( 5, 9990.0, 9990.0 );
		$items[4]['qty'] = 0;

		self::assertSame(
			0.0,
			$this->engine()->calc_byp_discount(
				$items,
				array( array( 'min' => 5, 'pct' => 10 ) )
			)
		);
	}

	/**
	 * @testdox Un product_id duplicado no cuenta ni recibe descuento dos veces
	 */
	public function test_duplicate_product_id_is_deduplicated_inside_engine(): void {
		$items                  = $this->items( 5, 9990.0, 9990.0 );
		$items[4]['product_id'] = 4;

		self::assertSame(
			0.0,
			$this->engine()->calc_byp_discount(
				$items,
				array( array( 'min' => 5, 'pct' => 10 ) )
			)
		);
	}

	/**
	 * @testdox Best-wins se conserva aunque una línea tenga múltiples unidades
	 */
	public function test_existing_better_offer_still_wins_with_repeated_quantity(): void {
		$items                     = $this->items( 5, 9990.0, 9990.0, 3 );
		$items[0]['current_price'] = 8000.0;

		self::assertSame(
			3996.0,
			$this->engine()->calc_byp_discount(
				$items,
				array( array( 'min' => 5, 'pct' => 10 ) )
			)
		);
	}

	/**
	 * @testdox El aporte por producto coincide con cada precio heterogéneo y suma el total
	 */
	public function test_per_product_contributions_match_total_for_heterogeneous_prices(): void {
		$items = array(
			array( 'regular_price' => 10000.0, 'current_price' => 10000.0, 'qty' => 2, 'product_id' => 1 ),
			array( 'regular_price' => 12000.0, 'current_price' => 12000.0, 'qty' => 1, 'product_id' => 2 ),
			array( 'regular_price' => 14000.0, 'current_price' => 14000.0, 'qty' => 1, 'product_id' => 3 ),
			array( 'regular_price' => 16000.0, 'current_price' => 16000.0, 'qty' => 1, 'product_id' => 4 ),
			array( 'regular_price' => 18000.0, 'current_price' => 18000.0, 'qty' => 1, 'product_id' => 5 ),
		);
		$tiers = array( array( 'min' => 5, 'pct' => 10 ) );
		$engine = $this->engine();
		$lines  = array();

		foreach ( array( 1, 2, 3, 4, 5 ) as $product_id ) {
			$lines[] = $engine->contribution_for_product( $product_id, $items, $tiers );
		}

		self::assertSame( array( 1000.0, 1200.0, 1400.0, 1600.0, 1800.0 ), $lines );
		self::assertSame( $engine->calc_byp_discount( $items, $tiers ), array_sum( $lines ) );
	}

	/**
	 * @testdox El aporte por SKU preserva best-wins y falla cerrado fuera del universo
	 */
	public function test_per_product_contribution_preserves_best_wins_and_membership(): void {
		$items = $this->items( 5, 10000.0, 10000.0 );
		$items[0]['current_price'] = 8500.0;
		$items[1]['current_price'] = 9500.0;
		$engine = $this->engine();
		$tiers  = array( array( 'min' => 5, 'pct' => 10 ) );

		self::assertSame( 0.0, $engine->contribution_for_product( 1, $items, $tiers ) );
		self::assertSame( 500.0, $engine->contribution_for_product( 2, $items, $tiers ) );
		self::assertSame( 0.0, $engine->contribution_for_product( 99, $items, $tiers ) );
	}

	/**
	 * @testdox Regular cero cae al precio actual y no convierte el aporte en 100%
	 */
	public function test_zero_regular_price_falls_back_to_current_price(): void {
		$items = $this->items( 5, 10000.0, 10000.0 );
		$items[0]['regular_price'] = 0.0;
		$engine = $this->engine();
		$tiers  = array( array( 'min' => 5, 'pct' => 10 ) );

		self::assertSame( 1000.0, $engine->contribution_for_product( 1, $items, $tiers ) );
		self::assertSame( 5000.0, $engine->calc_byp_discount( $items, $tiers ) );
	}

	/**
	 * @testdox Precio actual cero no cuenta para desbloquear el tramo
	 */
	public function test_zero_current_price_does_not_unlock_tier(): void {
		$items = $this->items( 5, 10000.0, 10000.0 );
		$items[4]['current_price'] = 0.0;

		self::assertSame(
			0.0,
			$this->engine()->calc_byp_discount(
				$items,
				array( array( 'min' => 5, 'pct' => 10 ) )
			)
		);
	}

	/**
	 * @testdox El aporte se escala y acota a la base de impuesto entregada por WC
	 */
	public function test_contribution_scales_to_discounting_tax_base(): void {
		$items  = $this->items( 5, 11900.0, 11900.0 );
		$engine = $this->engine();
		$tiers  = array( array( 'min' => 5, 'pct' => 10 ) );

		self::assertSame( 1000.0, $engine->contribution_for_base( 1, $items, $tiers, 10000.0 ) );
		self::assertSame( 10.0, $engine->contribution_for_base( 1, $items, $tiers, 100.0 ) );
		self::assertSame( 0.0, $engine->contribution_for_base( 1, $items, $tiers, 0.0 ) );
	}

	/**
	 * @testdox La base tributaria se redondea una sola vez desde el aporte crudo
	 */
	public function test_tax_base_scaling_does_not_double_round_contribution(): void {
		$items  = $this->items( 5, 1005.0, 1005.0 );
		$engine = $this->engine();
		$tiers  = array( array( 'min' => 5, 'pct' => 10 ) );

		self::assertSame(
			84.0,
			$engine->contribution_for_base(
				1,
				$items,
				$tiers,
				845.0,
				1005.0 / 1.19
			),
			'El techo CLP redondeado no puede reemplazar la base fiscal precisa.'
		);
		self::assertSame(
			101.0,
			$engine->contribution_for_base( 1, $items, $tiers, 1005.0, 1005.0 ),
			'La misma corrección no debe truncar el aporte bruto gravado.'
		);
	}

	private function engine(): DiscountEngine {
		return new DiscountEngine( new VolumeTierResolver() );
	}

	/**
	 * @return array<int, array{regular_price:float,current_price:float,qty:int,product_id:int}>
	 */
	private function items( int $count, float $regular, float $current, int $qty = 1 ): array {
		$items = array();
		for ( $id = 1; $id <= $count; $id++ ) {
			$items[] = array(
				'regular_price' => $regular,
				'current_price' => $current,
				'qty'           => $qty,
				'product_id'    => $id,
			);
		}

		return $items;
	}
}
