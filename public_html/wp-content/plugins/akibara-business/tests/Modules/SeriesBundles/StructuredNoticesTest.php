<?php
/**
 * Tests for structured Series Bundles notices.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\BuilderWidget;

use Akibara\Business\Modules\Stock\Availability;

use Akibara\Business\Modules\SeriesBundles\BypCartNoticeProvider;
use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\PromotionPolicy;
use Akibara\Business\Modules\SeriesBundles\SeriesDepth;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class StructuredNoticesTest extends TestCase {

	/**
	 * Loads isolated cart and WordPress fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/fixtures/wordpress-functions.php';

		if ( ! class_exists( '\WC_Cart' ) ) {
			eval(
				'namespace { class WC_Cart {
					private array $items;
					private array $coupons;
					public function __construct( array $items, array $coupons = array() ) {
						$this->items = $items;
						$this->coupons = $coupons;
					}
					public function get_cart(): array { return $this->items; }
					public function is_empty(): bool { return empty( $this->items ); }
					public function get_applied_coupons(): array { return $this->coupons; }
				} }'
			);
		}

		if ( ! function_exists( 'WC' ) ) {
			eval(
				'namespace { function WC() {
					return $GLOBALS["akibara_test_woocommerce"];
				} }'
			);
		}

		if ( ! function_exists( 'get_the_terms' ) ) {
			eval(
				'namespace { function get_the_terms( int $product_id, string $taxonomy ) {
					return $GLOBALS["akibara_test_terms"][ $taxonomy ][ $product_id ] ?? array();
				} }'
			);
		}

		if ( ! function_exists( 'absint' ) ) {
			eval(
				'namespace { function absint( $value ): int {
					return abs( (int) $value );
				} }'
			);
		}
	}

	/**
	 * Clears isolated cart fixtures.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['akibara_test_options'],
			$GLOBALS['akibara_test_terms'],
			$GLOBALS['akibara_test_woocommerce']
		);

		parent::tearDown();
	}

	/**
	 * @testdox Should return a plain structured next-tier notice.
	 */
	public function test_returns_plain_next_tier_notice(): void {
		$this->configure_series( '<b>Gannibal</b>' );
		$this->set_cart(
			array(
				$this->cart_item( 10, 'instock' ),
				$this->cart_item( 11, 'instock' ),
			)
		);

		$notices = $this->sut()->notices();

		self::assertCount( 1, $notices );
		self::assertSame( 'next_tier', $notices[0]['kind'] );
		self::assertSame( 'Gannibal', $notices[0]['series_name'] );
		self::assertSame( 1, $notices[0]['missing_count'] );
		self::assertSame( 10, $notices[0]['discount_percent'] );
		self::assertStringContainsString( 'en los tomos elegibles del pack', $notices[0]['message'] );
		self::assertStringNotContainsString( '<', json_encode( $notices, JSON_THROW_ON_ERROR ) );
	}

	/**
	 * @testdox Should replace next-tier persuasion with coupon transparency.
	 */
	public function test_external_coupon_suppresses_next_tier_notice(): void {
		$this->configure_series( 'Gannibal' );
		$this->set_cart(
			array(
				$this->cart_item( 10, 'instock' ),
				$this->cart_item( 11, 'instock' ),
				$this->cart_item( 12, 'instock' ),
			),
			array( 'campana-verano' )
		);

		$notices = $this->sut()->notices();

		self::assertCount( 1, $notices );
		self::assertSame( 'external_coupon', $notices[0]['kind'] );
		self::assertSame( 10, $notices[0]['discount_percent'] );
		self::assertStringContainsString( 'en los tomos elegibles del pack', $notices[0]['message'] );
	}

	/**
	 * @testdox Should explain every reached pack disabled by one external coupon.
	 */
	public function test_external_coupon_names_every_reached_series(): void {
		$this->configure_two_series();
		$this->set_cart(
			array(
				$this->cart_item( 10, 'instock' ),
				$this->cart_item( 11, 'instock' ),
				$this->cart_item( 12, 'instock' ),
				$this->cart_item( 20, 'instock' ),
				$this->cart_item( 21, 'instock' ),
				$this->cart_item( 22, 'instock' ),
			),
			array( 'campana-verano' )
		);

		$notices = $this->sut()->notices();

		self::assertSame( array( 'external_coupon', 'external_coupon' ), array_column( $notices, 'kind' ) );
		self::assertSame( array( 'Gannibal', 'Chainsaw Man' ), array_column( $notices, 'series_name' ) );
	}

	/**
	 * @testdox La nota del widget de serie deriva la palabra de la misma etiqueta que cada tomo.
	 *
	 * El widget entero no se renderiza en la suite (WP_Query + carrito); la nota
	 * y las banderas que la deciden están extraídas y se prueban directas. El
	 * checkout se prueba por conducta en CheckoutDataServiceTest.
	 */
	public function test_the_widget_and_the_checkout_derive_the_word_from_availability(): void {
		if ( ! function_exists( 'esc_html' ) ) {
			// Proceso aislado (@runTestsInSeparateProcesses): no contagia a la suite.
			eval( 'namespace { function esc_html( $t ) { return $t; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		self::assertStringContainsString( '<strong>' . mb_strtolower( Availability::label_for_state( Availability::A_PEDIDO ) ) . '</strong>', BuilderWidget::pack_notice( false ) );
		self::assertStringNotContainsString( 'preventa', BuilderWidget::pack_notice( false ) );
		self::assertStringContainsString( 'en <strong>preventa</strong>', BuilderWidget::pack_notice( true ) );
		self::assertSame(
			array( true, false, true ),
			BuilderWidget::pack_flags(
				array(
					array( 'is_purchasable' => true, 'is_preventa' => true, 'is_preventa_real' => false, 'regular_price' => 10, 'price' => 8 ),
					array( 'is_purchasable' => false, 'is_preventa' => true, 'is_preventa_real' => true ), // no comprable: no cuenta
				)
			),
			'La palabra la decide is_preventa_real, no is_preventa.'
		);

	}

	/**
	 * @testdox Un pack con tomos a pedido usa la MISMA palabra que la etiqueta de cada tomo, nunca «por encargo».
	 */
	public function test_mixed_pack_with_a_pedido_uses_the_same_word_as_the_tomo(): void {
		$this->configure_series( 'Gannibal', true );
		$this->set_cart(
			array(
				$this->cart_item( 10, 'instock' ),
				$this->cart_item( 11, 'onbackorder' ), // sin fecha de lanzamiento: a pedido
			)
		);

		$notices = $this->sut()->notices();

		self::assertSame( array( 'mixed_preventa', 'next_tier' ), array_column( $notices, 'kind' ), 'El `kind` no cambia: su enum vive en el otro plugin.' );
		self::assertSame( 'warning', $notices[0]['tone'] );
		self::assertSame( '', $notices[0]['badge'], 'Reservable no es preventa: el aviso prometía «preventa» sobre tomos a pedido.' );
		self::assertStringContainsString( 'tomos ' . mb_strtolower( Availability::label_for_state( Availability::A_PEDIDO ) ), $notices[0]['message'], 'La misma palabra que la etiqueta de cada tomo.' );
		self::assertStringNotContainsString( 'preventa', $notices[0]['message'] );
		self::assertStringNotContainsString( 'por encargo', $notices[0]['message'] );
	}

	/**
	 * @testdox Un pack mixto con una PREVENTA de verdad sí lleva el badge y la palabra.
	 */
	public function test_mixed_pack_with_real_preventa_keeps_the_badge(): void {
		$this->configure_series( 'Gannibal', true );
		$GLOBALS['akibara_test_postmeta'][11] = array(
			'_akb_eta_type'         => 'release_date',
			'_akb_eta_release_date' => gmdate( 'Y-m-d', time() + 60 * DAY_IN_SECONDS ),
		);
		$this->set_cart(
			array(
				$this->cart_item( 10, 'instock' ),
				$this->cart_item( 11, 'onbackorder' ),
			)
		);

		$notices = $this->sut()->notices();

		self::assertSame( 'mixed_preventa', $notices[0]['kind'] );
		self::assertSame( 'PRE', $notices[0]['badge'] );
		self::assertStringContainsString( 'en preventa', $notices[0]['message'] );
		unset( $GLOBALS['akibara_test_postmeta'][11] );
	}

	private function sut(): BypCartNoticeProvider {
		$settings  = new Settings();
		$tiers     = new VolumeTierResolver();
		$guard     = new EligibilityGuard( $settings );
		$collector = new CartSeriesCollector( $settings, $guard );

		$policy = new PromotionPolicy( $settings, new SeriesDepth( $settings ), $collector, static fn (): int => 99 );

		return new BypCartNoticeProvider( $settings, $tiers, $collector, $policy );
	}

	private function configure_series( string $name, bool $allow_backorder = false ): void {
		$GLOBALS['akibara_test_options'] = array(
			Settings::OPTION_ENABLED         => 'yes',
			Settings::OPTION_ALLOW_BACKORDER => $allow_backorder ? 'yes' : 'no',
			Settings::OPTION_BYP             => json_encode(
				array(
					77 => array(
						'enabled' => true,
						'tiers'   => array(
							array(
								'min' => 3,
								'pct' => 10,
							),
						),
					),
				),
				JSON_THROW_ON_ERROR
			),
		);

		$term = (object) array(
			'term_id' => 77,
			'name'    => $name,
		);

		$GLOBALS['akibara_test_terms'] = array(
			'pa_serie' => array(
				10 => array( $term ),
				11 => array( $term ),
				12 => array( $term ),
			),
		);
	}

	private function configure_two_series(): void {
		$GLOBALS['akibara_test_options'] = array(
			Settings::OPTION_ENABLED         => 'yes',
			Settings::OPTION_ALLOW_BACKORDER => 'no',
			Settings::OPTION_BYP             => json_encode(
				array(
					77 => array( 'enabled' => true, 'tiers' => array( array( 'min' => 3, 'pct' => 10 ) ) ),
					88 => array( 'enabled' => true, 'tiers' => array( array( 'min' => 3, 'pct' => 10 ) ) ),
				),
				JSON_THROW_ON_ERROR
			),
		);

		$first  = (object) array( 'term_id' => 77, 'name' => 'Gannibal' );
		$second = (object) array( 'term_id' => 88, 'name' => 'Chainsaw Man' );
		$GLOBALS['akibara_test_terms'] = array(
			'pa_serie' => array(
				10 => array( $first ),
				11 => array( $first ),
				12 => array( $first ),
				20 => array( $second ),
				21 => array( $second ),
				22 => array( $second ),
			),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $items Cart items.
	 * @param array<int,string>              $coupons Applied coupons.
	 */
	private function set_cart( array $items, array $coupons = array() ): void {
		$GLOBALS['akibara_test_woocommerce'] = (object) array(
			'cart' => new \WC_Cart( $items, $coupons ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cart_item( int $product_id, string $stock_status ): array {
		return array(
			'product_id' => $product_id,
			'quantity'   => 1,
			'data'       => new StructuredNoticeProduct( $product_id, $stock_status ),
		);
	}
}

final class StructuredNoticeProduct extends \WC_Product {

	private int $product_id;

	public function __construct( int $product_id, string $stock_status ) {
		parent::__construct( $stock_status );
		$this->product_id = $product_id;
	}

	public function get_id(): int {
		return $this->product_id;
	}

	public function is_type( string $type ): bool {
		return 'simple' === $type;
	}

	public function get_regular_price( string $context = 'view' ): string {
		unset( $context );

		return '9990';
	}

	public function get_price(): string {
		return '9990';
	}
}
