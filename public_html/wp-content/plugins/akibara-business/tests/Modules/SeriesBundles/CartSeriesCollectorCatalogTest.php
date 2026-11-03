<?php
/**
 * Contratos de compra múltiple de catálogo (independientes de BYP).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CartCatalogProduct extends \WC_Product {
	public bool $visible = true;
	public string $status = 'publish';
	public string $current_price = '10000';

	public function is_visible(): bool {
		return $this->visible;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_price(): string {
		return $this->current_price;
	}
}

final class CartSeriesCollectorCatalogTest extends TestCase {

	private const TERM_ID = 378;

	/** @var array<string,mixed> */
	private array $options = array();

	/** @var array<int,array<int,\WP_Term>> */
	private array $series_terms = array();

	/** @var array<int,CartCatalogProduct> */
	private array $products = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array(
			'akb_series_bundles_enabled'         => 'yes',
			'akb_series_bundles_byp'             => (string) json_encode(
				array(
					(string) self::TERM_ID => array(
						'enabled' => true,
						'tiers'   => array( array( 'min' => 3, 'pct' => 10 ) ),
					),
				)
			),
			'akb_series_bundles_exclude_cats'    => array(),
			'akb_series_bundles_allow_backorder' => 'no',
		);

		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'get_the_terms' )->alias(
			function ( int $product_id, string $taxonomy ): array {
				return 'pa_serie' === $taxonomy ? ( $this->series_terms[ $product_id ] ?? array() ) : array();
			}
		);
		Functions\when( 'wp_list_pluck' )->alias(
			static fn( array $items, string $field ): array => array_map(
				static fn( $item ) => $item->{$field} ?? null,
				$items
			)
		);
		Functions\when( 'wc_get_product' )->alias( fn( int $id ) => $this->products[ $id ] ?? null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Ordinary multi-buy accepts an exact-series preorder even when BYP excludes preorders. */
	public function test_preorder_is_purchasable_without_inflating_byp(): void {
		$product = $this->product( 107, 'onbackorder' );
		$domain  = $this->collector();

		self::assertNotNull( $domain->resolve_catalog_series( $product, self::TERM_ID ) );
		self::assertNull( $domain->resolve_eligible_series_for_term( $product, self::TERM_ID ) );
	}

	/** @testdox Enabling BYP preorders changes discount eligibility, not catalogue membership. */
	public function test_preorder_discount_eligibility_reuses_settings(): void {
		$product = $this->product( 108, 'onbackorder' );
		$this->options['akb_series_bundles_allow_backorder'] = 'yes';

		self::assertNotNull( $this->collector()->resolve_eligible_series_for_term( $product, self::TERM_ID ) );
	}

	/** @testdox Batch purchase rejects out-of-stock and variable products. */
	public function test_general_batch_rejects_unsafe_product_shapes(): void {
		$out = $this->product( 109, 'outofstock' );
		$variable = $this->product( 110, 'instock' );
		$variable->akb_type = 'variable';
		$hidden = $this->product( 112, 'instock' );
		$hidden->visible = false;
		$draft = $this->product( 113, 'instock' );
		$draft->status = 'draft';
		$not_purchasable = $this->product( 114, 'instock', self::TERM_ID, false );
		$domain = $this->collector();

		self::assertNull( $domain->resolve_catalog_series( $out, self::TERM_ID ) );
		self::assertNull( $domain->resolve_catalog_series( $variable, self::TERM_ID ) );
		self::assertNull( $domain->resolve_catalog_series( $hidden, self::TERM_ID ) );
		self::assertNull( $domain->resolve_catalog_series( $draft, self::TERM_ID ) );
		self::assertNull( $domain->resolve_catalog_series( $not_purchasable, self::TERM_ID ) );
	}

	/** @testdox Canonical batch IDs expose only publish visible simple purchasable stock and preorder products. */
	public function test_batch_catalog_resolver_uses_the_exact_safe_universe(): void {
		$instock = $this->product( 201, 'instock' );
		$preorder = $this->product( 202, 'onbackorder' );
		$hidden = $this->product( 203, 'instock' );
		$hidden->visible = false;
		$draft = $this->product( 204, 'instock' );
		$draft->status = 'draft';
		$variable = $this->product( 205, 'instock' );
		$variable->akb_type = 'variable';
		$this->product( 206, 'outofstock' );
		$this->product( 207, 'instock', self::TERM_ID, false );

		self::assertSame(
			array( $instock->get_id(), $preorder->get_id() ),
			$this->collector()->resolve_catalog_product_ids( self::TERM_ID, range( 201, 207 ), 999 )
		);
	}

	/** @testdox Exact term validation never borrows membership from another series. */
	public function test_general_batch_requires_exact_series_membership(): void {
		$product = $this->product( 111, 'instock', 999 );

		self::assertNull( $this->collector()->resolve_catalog_series( $product, self::TERM_ID ) );
	}

	/** @testdox Two BYP-enabled series fail closed for both cart tiers and landing discount copy, while ordinary exact-term multi-buy remains valid. */
	public function test_multiple_byp_terms_never_choose_the_first_discount_series(): void {
		$product = $this->product( 301, 'instock' );
		$other = new \WP_Term( 999, 'serie-999', 'Otra serie' );
		$other->taxonomy = 'pa_serie';
		$this->series_terms[301][] = $other;
		$this->options['akb_series_bundles_byp'] = (string) json_encode(
			array(
				(string) self::TERM_ID => array(
					'enabled' => true,
					'tiers'   => array( array( 'min' => 3, 'pct' => 10 ) ),
				),
				'999' => array(
					'enabled' => true,
					'tiers'   => array( array( 'min' => 2, 'pct' => 5 ) ),
				),
			)
		);

		$domain = $this->collector();
		self::assertNull( $domain->resolve_enabled_series( $product->get_id() ) );
		self::assertNull( $domain->resolve_eligible_series( $product ), 'El colector del carrito no puede formar un tramo ambiguo.' );
		self::assertNull( $domain->resolve_eligible_series_for_term( $product, self::TERM_ID ), 'La landing no debe prometer un tramo que el carrito omite.' );
		self::assertNotNull( $domain->resolve_catalog_series( $product, self::TERM_ID ), 'La compra múltiple sin descuento conserva el término exacto.' );
	}

	/** @testdox A multi-term product with one enabled BYP resolves that sole series even when it is not first. */
	public function test_single_enabled_byp_term_remains_unambiguous(): void {
		$product = $this->product( 302, 'instock' );
		$other = new \WP_Term( 999, 'serie-999', 'Sin promo' );
		$other->taxonomy = 'pa_serie';
		$this->series_terms[302] = array( $other, $this->series_terms[302][0] );

		$domain   = $this->collector();
		$resolved = $domain->resolve_enabled_series( $product->get_id() );

		self::assertNotNull( $resolved );
		self::assertSame( self::TERM_ID, $resolved['term_id'] );
		self::assertNotNull( $domain->resolve_eligible_series( $product ) );
		self::assertNotNull( $domain->resolve_eligible_series_for_term( $product, self::TERM_ID ) );
	}

	/** @testdox Precio actual cero no es elegible para BYP en carrito ni landing */
	public function test_zero_current_price_is_not_discount_eligible_on_any_surface(): void {
		$product = $this->product( 303, 'instock' );
		$product->akb_regular_price = '10000';
		$product->akb_sale_price    = '0';
		$product->akb_on_sale       = true;
		$product->current_price     = '0';
		$domain = $this->collector();

		self::assertNull( $domain->resolve_eligible_series( $product ) );
		self::assertNull( $domain->resolve_eligible_series_for_term( $product, self::TERM_ID ) );
		self::assertNotNull(
			$domain->resolve_catalog_series( $product, self::TERM_ID ),
			'La compra ordinaria conserva su universo; sólo BYP rechaza precio cero.'
		);
	}

	/** @testdox El contador de política usa la misma frontera exacta que el carrito. */
	public function test_promotion_candidate_rejects_zero_variable_excluded_and_preorder_products(): void {
		$valid = $this->product( 401, 'instock' );
		$zero = $this->product( 402, 'instock' );
		$zero->current_price = '0';
		$variable = $this->product( 403, 'instock' );
		$variable->akb_type = 'variable';
		$preorder = $this->product( 404, 'onbackorder' );
		$outside = $this->product( 405, 'instock', 999 );
		$domain = $this->collector();

		self::assertTrue( $domain->is_promotion_candidate_for_term( $valid, self::TERM_ID ) );
		self::assertFalse( $domain->is_promotion_candidate_for_term( $zero, self::TERM_ID ) );
		self::assertFalse( $domain->is_promotion_candidate_for_term( $variable, self::TERM_ID ) );
		self::assertFalse( $domain->is_promotion_candidate_for_term( $preorder, self::TERM_ID ) );
		self::assertFalse( $domain->is_promotion_candidate_for_term( $outside, self::TERM_ID ) );
	}

	private function collector(): CartSeriesCollector {
		$settings = new Settings();
		return new CartSeriesCollector( $settings, new EligibilityGuard( $settings ) );
	}

	private function product( int $id, string $stock, int $term_id = self::TERM_ID, bool $purchasable = true ): CartCatalogProduct {
		$product         = new CartCatalogProduct( $stock, array(), $purchasable );
		$product->akb_id = $id;
		$term            = new \WP_Term( $term_id, 'serie-' . $term_id, 'Serie ' . $term_id );
		$term->taxonomy  = 'pa_serie';
		$this->series_terms[ $id ] = array( $term );
		$this->products[ $id ]     = $product;

		return $product;
	}
}
