<?php
/**
 * Instrumentación de pedidos que usaron BYP.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\BundleCouponEngine;
use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\OrderInstrumentation;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use PHPUnit\Framework\TestCase;

final class OrderInstrumentationProduct extends \WC_Product {

	public function __construct( int $product_id ) {
		parent::__construct( 'instock' );
		$this->akb_id            = $product_id;
		$this->akb_regular_price = '10000';
	}

	public function get_price(): string {
		return $this->akb_regular_price;
	}
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class OrderInstrumentationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/fixtures/wordpress-functions.php';

		if ( ! class_exists( '\WC_Cart' ) ) {
			eval(
				'namespace { class WC_Cart {
					private array $items;
					private array $coupons;
					private array $discounts;
					public function __construct( array $items, array $coupons = array(), array $discounts = array() ) {
						$this->items = $items; $this->coupons = $coupons; $this->discounts = $discounts;
					}
					public function get_cart(): array { return $this->items; }
					public function get_applied_coupons(): array { return $this->coupons; }
					public function get_coupon_discount_amount( string $code, bool $ex_tax = true ): float {
						unset( $ex_tax ); return (float) ( $this->discounts[ $code ] ?? 0.0 );
					}
				} }'
			);
		}

		if ( ! function_exists( 'WC' ) ) {
			eval( 'namespace { function WC() { return $GLOBALS["akibara_order_wc"]; } }' );
		}

		if ( ! function_exists( 'get_the_terms' ) ) {
			eval(
				'namespace { function get_the_terms( int $product_id, string $taxonomy ) {
					if ( "pa_serie" !== $taxonomy ) { return array(); }
					$term_id = $GLOBALS["akibara_order_terms"][ $product_id ] ?? 0;
					if ( ! $term_id ) { return array(); }
					$term = new WP_Term( $term_id, "serie-" . $term_id, "Serie " . $term_id );
					$term->taxonomy = "pa_serie";
					return array( $term );
				} }'
			);
		}

		if ( ! function_exists( 'absint' ) ) {
			eval( 'namespace { function absint( $value ): int { return abs( (int) $value ); } }' );
		}

		if ( ! function_exists( 'wp_json_encode' ) ) {
			eval( 'namespace { function wp_json_encode( $value ): string { return (string) json_encode( $value ); } }' );
		}

		$GLOBALS['akibara_order_terms'] = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['akibara_test_options'],
			$GLOBALS['akibara_order_terms'],
			$GLOBALS['akibara_order_wc']
		);

		parent::tearDown();
	}

	/** @testdox Sólo instrumenta el hash BYP realmente aplicado, no todas las series del carrito */
	public function test_classic_checkout_stamps_only_actually_applied_series(): void {
		$first_code  = BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' );
		$second_code = BundleCouponEngine::COUPON_BYP_PREFIX . md5( '20' );
		$this->configure_cart(
			array(
				10 => array( 1, 2, 3, 4, 5 ),
				20 => array( 6, 7, 8, 9, 10 ),
			),
			array( $first_code ),
			array( $first_code => 4000.0, $second_code => 5000.0 )
		);
		$order = new \WC_Order();

		$this->sut()->stamp_order( $order );

		self::assertSame( 'yes', $order->akb_meta['_akb_byp_used'] );
		self::assertSame( '4000', $order->akb_meta['_akb_byp_discount_clp'] );
		self::assertSame( '10', $order->akb_meta['_akb_byp_series'] );
		self::assertSame( '5', $order->akb_meta['_akb_byp_distinct'] );
		self::assertSame(
			array(
				'10' => array( 'name' => 'Serie 10', 'distinct' => 5, 'pct' => 8 ),
			),
			json_decode( $order->akb_meta['_akb_byp_tiers'], true )
		);
		self::assertSame( 0, $order->akb_saves, 'Checkout clásico no debe hacer save() propio.' );
	}

	/** @testdox Dos hashes aplicados conservan el tier real de cada serie */
	public function test_two_applied_series_record_their_actual_tiers(): void {
		$first_code  = BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' );
		$second_code = BundleCouponEngine::COUPON_BYP_PREFIX . md5( '20' );
		$this->configure_cart(
			array(
				10 => array( 1, 2, 3, 4, 5 ),
				20 => array( 6, 7, 8, 9, 10, 11, 12 ),
			),
			array( $first_code, $second_code ),
			array( $first_code => 4000.0, $second_code => 7000.0 )
		);

		$summary = $this->sut()->summarize_cart( $GLOBALS['akibara_order_wc']->cart );

		self::assertSame( 8, $summary[10]['pct'] );
		self::assertSame( 5, $summary[10]['distinct'] );
		self::assertSame( 10, $summary[20]['pct'] );
		self::assertSame( 7, $summary[20]['distinct'] );
	}

	/** @testdox Un código malformado u obsoleto no estampa meta BYP */
	public function test_malformed_or_obsolete_coupon_does_not_stamp(): void {
		$this->configure_cart(
			array( 10 => array( 1, 2, 3, 4, 5 ) ),
			array( BundleCouponEngine::COUPON_BYP_PREFIX . 'truncado' ),
			array()
		);
		$order = new \WC_Order();

		$this->sut()->stamp_order( $order );

		self::assertSame( array(), $order->akb_meta );

		$obsolete = BundleCouponEngine::COUPON_BYP_PREFIX . str_repeat( '0', 32 );
		$this->configure_cart(
			array( 10 => array( 1, 2, 3, 4, 5 ) ),
			array( $obsolete ),
			array( $obsolete => 1000.0 )
		);
		$this->sut()->stamp_order( $order );

		self::assertSame( array(), $order->akb_meta );
	}

	/** @testdox Un hash aplicado con tier cero no se registra */
	public function test_applied_hash_with_non_positive_tier_does_not_stamp(): void {
		$code = BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' );
		$this->configure_cart(
			array( 10 => array( 1, 2, 3, 4 ) ),
			array( $code ),
			array( $code => 1000.0 )
		);
		$order = new \WC_Order();

		$this->sut()->stamp_order( $order );

		self::assertSame( array(), $order->akb_meta );
	}

	/** @testdox Store API guarda una vez sólo cuando estampó instrumentación */
	public function test_store_api_saves_once_only_when_instrumented(): void {
		$code = BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' );
		$this->configure_cart(
			array( 10 => array( 1, 2, 3, 4, 5 ) ),
			array( $code ),
			array( $code => 4000.0 )
		);
		$order = new \WC_Order();

		$this->sut()->stamp_order_store_api( $order );
		self::assertSame( 1, $order->akb_saves );

		$this->configure_cart( array( 10 => array( 1, 2, 3, 4, 5 ) ), array(), array() );
		$without_byp = new \WC_Order();
		$this->sut()->stamp_order_store_api( $without_byp );
		self::assertSame( 0, $without_byp->akb_saves );
	}

	private function sut(): OrderInstrumentation {
		$settings = new Settings();

		return new OrderInstrumentation(
			new VolumeTierResolver(),
			new CartSeriesCollector( $settings, new EligibilityGuard( $settings ) )
		);
	}

	/**
	 * @param array<int,array<int,int>> $series_products Mapa term_id => productos.
	 * @param array<int,string>         $coupons         Cupones aplicados.
	 * @param array<string,float>       $discounts       Monto por cupón.
	 */
	private function configure_cart( array $series_products, array $coupons, array $discounts ): void {
		$config = array();
		$items  = array();

		foreach ( $series_products as $term_id => $product_ids ) {
			$config[ $term_id ] = array(
				'enabled' => true,
				'tiers'   => array(
					array( 'min' => 5, 'pct' => 8 ),
					array( 'min' => 7, 'pct' => 10 ),
				),
			);
			foreach ( $product_ids as $product_id ) {
				$GLOBALS['akibara_order_terms'][ $product_id ] = $term_id;
				$items[] = array(
					'product_id' => $product_id,
					'quantity'   => 1,
					'data'       => new OrderInstrumentationProduct( $product_id ),
				);
			}
		}

		$GLOBALS['akibara_test_options'] = array(
			Settings::OPTION_ENABLED         => 'yes',
			Settings::OPTION_BYP             => json_encode( $config, JSON_THROW_ON_ERROR ),
			Settings::OPTION_EXCLUDE_CATS    => array(),
			Settings::OPTION_ALLOW_BACKORDER => 'no',
		);
		$GLOBALS['akibara_order_wc'] = (object) array(
			'cart' => new \WC_Cart( $items, $coupons, $discounts ),
		);
	}
}
