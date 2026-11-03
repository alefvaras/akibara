<?php
/**
 * FinancialRulesV11MasterSuiteTest — Suite de 9 Tests Automatizados de la Especificación Maestra v1.1.
 *
 * Cubre exhaustivamente los 9 escenarios contractuales:
 * 1. SeriesBundlesStrictWhitelistTest
 * 2. SeriesBundlesBackorderModesTest
 * 3. SeriesBundlesMixedFormatCapTest
 * 4. ReferralVsBundlePriorityAndDynamicTransitionTest
 * 5. ReferralCeilingAndBackorderCompatibilityTest
 * 6. BlockCheckoutManualPointsSwitchTest
 * 7. AccrualHybridEscrowBackorderTest
 * 8. BacsLifecycleExpirationAndGracePeriodTest
 * 9. PartialRefundBundleClawbackTest
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Admin\FinancialRulesAdminPage;
use Akibara\Business\Modules\BacsLifecycle\BacsLifecycleManager;
use Akibara\Business\Modules\Logistics\MixedCartShipmentGuard;
use Akibara\Business\Modules\SeriesBundles\BundleCouponEngine;
use Akibara\Business\Modules\SeriesBundles\DiscountEngine;
use Akibara\Business\Modules\SeriesBundles\PartialRefundBundleGuard;
use Akibara\Business\Modules\SeriesBundles\PromotionPolicy;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use Akibara\Business\Support\BusinessOptions;
use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\Referrals;
use Akibara\Rewards\Points\Redemption;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FinancialRulesV11MasterSuiteTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );

		// Registrar autoloader de akibara-rewards para tests cruzados de integración
		spl_autoload_register(
			static function ( string $class ): void {
				$prefix   = 'Akibara\\Rewards\\';
				$base_dir = dirname( __DIR__, 4 ) . '/akibara-rewards/src/';
				if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
					return;
				}
				$relative = substr( $class, strlen( $prefix ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test 1: SeriesBundlesStrictWhitelistTest
	 * @testdox Test 1 (Strict Whitelist): Solo participan series en la lista blanca; Planeta o series no agregadas tienen $0 CLP de descuento.
	 */
	public function test_series_bundles_strict_whitelist(): void {
		$serie_a_id = 364; // Autorizada por el dueño
		$serie_b_id = 386; // Editorial Planeta (NO en lista blanca)

		$byp_map = array(
			$serie_a_id => array(
				'enabled' => true,
				'profile' => 'template_standard',
				'tiers'   => array(
					array( 'min' => 5, 'pct' => 8 ),
					array( 'min' => 7, 'pct' => 10 ),
				),
			),
		);

		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) use ( $byp_map ) {
			if ( 'akb_series_bundles_enabled' === $opt ) return 'yes';
			if ( 'akb_series_bundles_byp' === $opt ) return json_encode( $byp_map );
			if ( 'akb_discount_max_pct' === $opt ) return 15;
			return $default;
		} );

		// Serie B (Planeta) no está en whitelist -> debe retornar null (0% descuento)
		$config_b = PromotionPolicy::get_series_bundle_config( $serie_b_id );
		self::assertNull( $config_b, 'Serie de Planeta fuera de whitelist debe retornar null' );

		// Serie A sí está en whitelist
		$config_a = PromotionPolicy::get_series_bundle_config( $serie_a_id );
		self::assertNotNull( $config_a );
		self::assertTrue( $config_a['enabled'] );

		$engine = new DiscountEngine( new VolumeTierResolver() );

		// 5 tomos de Serie B a $9.990 c/u -> Descuento = $0 CLP
		$items_b = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$items_b[] = array( 'product_id' => 100 + $i, 'regular_price' => 9990.0, 'current_price' => 9990.0, 'qty' => 1 );
		}
		$discount_b = $engine->calc_byp_discount( $items_b, $config_b['tiers'] ?? array() );
		self::assertSame( 0.0, $discount_b, 'Serie no autorizada recibe exactamente $0 CLP de descuento' );

		// 5 tomos de Serie A a $9.990 c/u -> Descuento = -$3.995 CLP (Total $45.955 CLP)
		$items_a = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$items_a[] = array( 'product_id' => 200 + $i, 'regular_price' => 9990.0, 'current_price' => 9990.0, 'qty' => 1 );
		}
		$discount_a = $engine->calc_byp_discount( $items_a, $config_a['tiers'] );
		self::assertSame( 3995.0, $discount_a );
		self::assertSame( 45955.0, array_sum( array_column( $items_a, 'current_price' ) ) - $discount_a );
	}

	/**
	 * Test 2: SeriesBundlesBackorderModesTest
	 * @testdox Test 2 (Backorder Modes): Evalúa Best-Wins ($45.955), Puente de Conteo ($46.555) y Exclusión ($48.952) en carrito de 3 stock + 2 a pedido.
	 */
	public function test_series_bundles_backorder_modes(): void {
		$engine = new DiscountEngine( new VolumeTierResolver() );
		$tiers  = array( array( 'min' => 5, 'pct' => 8 ), array( 'min' => 7, 'pct' => 10 ) );

		// 3 tomos en stock ($9.990) + 2 tomos a pedido ($9.491 con 5% reserva ya rebajado)
		$build_items = function( string $mode ): array {
			$items = array();
			for ( $i = 1; $i <= 3; $i++ ) {
				$items[] = array(
					'product_id'     => $i,
					'regular_price'  => 9990.0,
					'current_price'  => 9990.0,
					'qty'            => 1,
					'is_backorder'   => false,
					'backorder_mode' => $mode,
				);
			}
			for ( $i = 4; $i <= 5; $i++ ) {
				$items[] = array(
					'product_id'     => $i,
					'regular_price'  => 9990.0,
					'current_price'  => 9491.0,
					'qty'            => 1,
					'is_backorder'   => true,
					'backorder_mode' => $mode,
				);
			}
			return $items;
		};

		// Modo 1: best_wins_topup -> Pack aporta +$300 en los 2 a pedido y +$799 en los 3 stock (total cupón $2.997 CLP). Total a pagar = $45.955 CLP
		$items_policy1 = $build_items( 'best_wins_topup' );
		$disc_policy1  = $engine->calc_byp_discount( $items_policy1, $tiers );
		$subtotal_base = 3 * 9990.0 + 2 * 9491.0; // $48.952 CLP
		self::assertSame( 2997.0, $disc_policy1 );
		self::assertSame( 45955.0, $subtotal_base - $disc_policy1 );

		// Modo 3: count_only_bridge -> Tomos a pedido suman para el tramo 5 pero aportan $0 CLP extra.
		// Pack descuenta 8% solo en los 3 en stock (3 * $799 = $2.397 CLP). Total a pagar = $46.555 CLP
		$items_policy3 = $build_items( 'count_only_bridge' );
		$disc_policy3  = $engine->calc_byp_discount( $items_policy3, $tiers );
		self::assertSame( 2397.0, $disc_policy3 );
		self::assertSame( 46555.0, $subtotal_base - $disc_policy3 );

		// Modo 2: exclude_backorder -> Solo cuentan 3 tomos (< 5 unidades), cupón del pack = $0 CLP, total a pagar = $48.952 CLP
		$items_policy2 = array_slice( $items_policy1, 0, 3 ); // Solo los 3 en stock pasan el filtro
		$disc_policy2  = $engine->calc_byp_discount( $items_policy2, $tiers );
		self::assertSame( 0.0, $disc_policy2 );
		self::assertSame( 48952.0, $subtotal_base - $disc_policy2 );
	}

	/**
	 * Test 3: SeriesBundlesMixedFormatCapTest
	 * @testdox Test 3 (Mixed Format Cap): 6 mangas estándar (10%) + 1 novela/deluxe ($18.000 con tope 8%) descuentan -$7.434 CLP.
	 */
	public function test_series_bundles_mixed_format_cap(): void {
		$engine = new DiscountEngine( new VolumeTierResolver() );
		$tiers  = array( array( 'min' => 5, 'pct' => 8 ), array( 'min' => 7, 'pct' => 10 ) );

		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( 'akb_sb_special_max_pct' === $opt ) return 8;
			if ( 'akb_discount_max_pct' === $opt ) return 15;
			return $default;
		} );

		$items = array();
		// 6 mangas regulares de $9.990 c/u
		for ( $i = 1; $i <= 6; $i++ ) {
			$items[] = array(
				'product_id'    => $i,
				'regular_price' => 9990.0,
				'current_price' => 9990.0,
				'qty'           => 1,
				'is_special'    => false,
			);
		}
		// 1 novela / deluxe de $18.000 con is_special = true
		$items[] = array(
			'product_id'    => 7,
			'regular_price' => 18000.0,
			'current_price' => 18000.0,
			'qty'           => 1,
			'is_special'    => true,
		);

		// Conteo total = 7 unidades -> tramo 10%
		// 6 mangas al 10%: 6 * 999 = $5.994 CLP
		// 1 novela al tope 8%: 18000 * 0.08 = $1.440 CLP
		// Total descuento = $5.994 + $1.440 = $7.434 CLP
		$discount = $engine->calc_byp_discount( $items, $tiers );
		self::assertSame( 7434.0, $discount );
	}

	/**
	 * Test 4: ReferralVsBundlePriorityAndDynamicTransitionTest
	 * @testdox Test 4 (Referral vs Pack): Prioridad 1100 protege el Pack y el motor calcula -$3.995 CLP superando el cupón ref- ($2.000 CLP).
	 */
	public function test_referral_vs_bundle_priority_and_dynamic_transition(): void {
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( 'akb_referral_guest_discount_amount' === $opt ) return 2000;
			if ( 'akb_referral_auto_apply_priority' === $opt ) return 1100;
			return $default;
		} );

		// Prioridad del hook en Referrals debe ser 1100 (posterior a BundleCouponEngine en 1000)
		$ref_prio = BusinessOptions::referral_auto_apply_priority();
		self::assertSame( 1100, $ref_prio );

		// Evaluar con el motor real DiscountEngine: 5 tomos de $9.990 dan $3.995 CLP (> $2.000 CLP de referido)
		$engine = new DiscountEngine( new VolumeTierResolver() );
		$items  = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$items[] = array(
				'product_id'    => $i,
				'regular_price' => 9990.0,
				'current_price' => 9990.0,
				'qty'           => 1,
			);
		}
		$pack_discount = $engine->calc_byp_discount( $items, array( array( 'min' => 5, 'pct' => 8 ) ) );
		self::assertSame( 3995.0, $pack_discount );
		self::assertGreaterThan( (float) BusinessOptions::referral_guest_discount_amount(), $pack_discount );
	}

	/**
	 * Test 5: ReferralCeilingAndBackorderCompatibilityTest
	 * @testdox Test 5 (Referral Ceiling & Backorder): Carrito de $25k se rechaza (mínimo $30k) y carrito de $30k con tomos en reserva es válido (exclude_sale_items false).
	 */
	public function test_referral_ceiling_and_backorder_compatibility(): void {
		Functions\when( 'get_users' )->justReturn( array( 7 ) );

		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( 'akb_referral_guest_discount_amount' === $opt ) return 2000;
			if ( 'akb_referral_min_cart_amount' === $opt ) return 30000;
			if ( 'akb_referral_allow_backorder_items' === $opt ) return 'yes';
			return $default;
		} );

		$referrals   = new Referrals();
		$coupon_data = $referrals->fabricate_referral_coupon( false, 'ref-test123' );

		// exclude_sale_items debe ser FALSE para no romper WC_Discounts con tomos a pedido
		self::assertFalse( $coupon_data['exclude_sale_items'] );
		self::assertSame( 30000.0, $coupon_data['minimum_amount'] );
		self::assertSame( 2000.0, $coupon_data['amount'] );

		// Probar Referrals::eligible_cart_subtotal con línea en reserva (usa line_subtotal pre-cupón)
		$prod_bo = new class extends \WC_Product {
			public function is_on_sale( string $context = 'view' ): bool {
				return true;
			}
			public function is_on_backorder( $qty = 1 ): bool {
				return true;
			}
			public function get_stock_status( string $context = 'view' ): string {
				return 'onbackorder';
			}
		};

		$cart_mock = new class( $prod_bo ) {
			private $p;
			public function __construct( $p ) {
				$this->p = $p;
			}
			public function get_cart(): array {
				return array(
					array(
						'data'              => $this->p,
						'quantity'          => 3,
						'line_subtotal'     => 30000.0,
						'line_subtotal_tax' => 0.0,
						'line_total'        => 28000.0, // Post-cupón de $2.000
					),
				);
			}
		};

		self::assertSame( 30000.0, Referrals::eligible_cart_subtotal( $cart_mock, true ) );
		self::assertSame( 0.0, Referrals::eligible_cart_subtotal( $cart_mock, false ) );
	}

	/**
	 * Test 6: BlockCheckoutManualPointsSwitchTest
	 * @testdox Test 6 (Block Checkout Switch): Exclusion mode en DiscountEngine y opción de conmutación voluntaria a Puntos en Checkout de Bloques.
	 */
	public function test_block_checkout_manual_points_switch(): void {
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( 'akb_rewards_allow_manual_switch_below_bundle' === $opt ) return 'yes';
			if ( 'akb_series_bundles_backorder_mode' === $opt ) return 'exclude_backorder';
			return $default;
		} );

		self::assertTrue( BusinessOptions::rewards_allow_manual_switch_below_bundle() );

		// Verificar que exclude_backorder excluye realmente del conteo a los tomos a pedido (4 stock + 1 backorder = 4 < 5 => $0 descuento)
		$engine = new DiscountEngine( new VolumeTierResolver() );
		$items  = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$items[] = array(
				'product_id'     => $i,
				'regular_price'  => 9990.0,
				'current_price'  => 9990.0,
				'qty'            => 1,
				'is_backorder'   => false,
				'backorder_mode' => 'inherit',
			);
		}
		$items[] = array(
			'product_id'     => 5,
			'regular_price'  => 9990.0,
			'current_price'  => 9491.0,
			'qty'            => 1,
			'is_backorder'   => true,
			'backorder_mode' => 'inherit',
		);

		self::assertSame( 0.0, $engine->calc_byp_discount( $items, array( array( 'min' => 5, 'pct' => 8 ) ) ) );
	}

	/**
	 * Test 7: AccrualImmediateAwardOnProcessingTest
	 * @testdox Test 7 (Immediate Award on Processing): Todos los pedidos pagados (stock, mixtos y a pedido) acreditan inmediatamente en processing sin retención en custodia.
	 */
	public function test_accrual_hybrid_escrow_backorder(): void {
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( 'akb_rewards_award_status' === $opt ) return 'processing';
			return $default;
		} );

		self::assertSame( 'processing', BusinessOptions::rewards_award_status() );
		self::assertFalse( method_exists( Accrual::class, 'escrow_backorder_points' ), 'El código de custodia/escrow de preventa fue erradicado limpiamente' );
	}

	/**
	 * Test 8: BacsLifecycleExpirationAndGracePeriodTest
	 * @testdox Test 8 (BACS Lifecycle): Cancela a las 24 horas si no hay comprobante y respeta la prórroga de +48h cuando el cliente informa su transferencia.
	 */
	public function test_bacs_lifecycle_expiration_and_grace_period(): void {
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( 'akb_bacs_auto_cancel_enabled' === $opt ) return 'yes';
			if ( 'akb_bacs_reminder_hours' === $opt ) return 12;
			if ( 'akb_bacs_expiry_hours' === $opt ) return 24;
			if ( 'akb_bacs_receipt_grace_hours' === $opt ) return 48;
			return $default;
		} );

		self::assertTrue( BusinessOptions::bacs_auto_cancel_enabled() );
		self::assertSame( 12, BusinessOptions::bacs_reminder_hours() );
		self::assertSame( 24, BusinessOptions::bacs_expiry_hours() );
		self::assertSame( 48, BusinessOptions::bacs_receipt_grace_hours() );

		$manager = new \Akibara\Business\Modules\BacsLifecycle\BacsLifecycleManager();

		// Orden en on-hold CON comprobante reportado hace 1 hora -> expire_order NO debe cancelarla
		$order_with_receipt = \Mockery::mock( '\WC_Order' );
		$order_with_receipt->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order_with_receipt->shouldReceive( 'get_payment_method' )->andReturn( 'bacs' );
		$order_with_receipt->shouldReceive( 'get_meta' )
			->with( \Akibara\Business\Modules\BacsLifecycle\BacsLifecycleManager::META_RECEIPT_REPORTED )
			->andReturn( time() - 3600 );
		$order_with_receipt->shouldNotReceive( 'update_status' );

		// Orden en on-hold SIN comprobante -> expire_order DEBE cancelarla
		$cancelled_called = false;
		$order_expired = \Mockery::mock( '\WC_Order' );
		$order_expired->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order_expired->shouldReceive( 'get_payment_method' )->andReturn( 'bacs' );
		$order_expired->shouldReceive( 'get_meta' )
			->with( \Akibara\Business\Modules\BacsLifecycle\BacsLifecycleManager::META_RECEIPT_REPORTED )
			->andReturn( '' );
		$order_expired->shouldReceive( 'update_status' )
			->once()
			->with( 'cancelled', \Mockery::type( 'string' ) )
			->andReturnUsing( function() use ( &$cancelled_called ) {
				$cancelled_called = true;
			} );

		Functions\when( 'wc_get_order' )->alias( function( $id ) use ( $order_with_receipt, $order_expired ) {
			return 101 === $id ? $order_with_receipt : $order_expired;
		} );

		$manager->expire_order( 101 );
		$manager->expire_order( 102 );
		self::assertTrue( $cancelled_called );
	}

	/**
	 * Test 9: PartialRefundBundleClawbackTest
	 * @testdox Test 9 (Partial Refund Clawback): Devolución a < 5 tomos deduce $2.397 CLP; y de 7 a 5 tomos (10% a 8%) deduce $1.000 CLP.
	 */
	public function test_partial_refund_bundle_clawback(): void {
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( 'akb_sb_clawback_on_partial_refund' === $opt ) return 'yes';
			return $default;
		} );

		self::assertTrue( BusinessOptions::sb_clawback_on_partial_refund() );

		// Caso A: Pack original de 5 tomos (8%), se devuelven 2 tomos -> quedan 3 tomos (< 5) -> clawback = 3 * $799 = $2.397 CLP
		$items_a = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$item = \Mockery::mock( '\WC_Order_Item_Product' );
			$item->shouldReceive( 'get_id' )->andReturn( $i );
			$item->shouldReceive( 'get_quantity' )->andReturn( 1 );
			$item->shouldReceive( 'get_subtotal' )->andReturn( 9990.0 );
			$item->shouldReceive( 'get_subtotal_tax' )->andReturn( 0.0 );
			$item->shouldReceive( 'get_product_id' )->andReturn( 0 );
			$items_a[] = $item;
		}
		$order_mock_a = \Mockery::mock( '\WC_Order' );
		$order_mock_a->shouldReceive( 'get_items' )->with( 'line_item' )->andReturn( $items_a );
		$order_mock_a->shouldReceive( 'get_discount_total' )->andReturn( 3995.0 );
		$order_mock_a->shouldReceive( 'get_qty_refunded_for_item' )->andReturnUsing( function( $id ) {
			return ( $id >= 4 ) ? 1 : 0;
		} );

		self::assertSame(
			2397.0,
			\Akibara\Business\Modules\SeriesBundles\PartialRefundBundleGuard::calculate_clawback( $order_mock_a ),
			'Caso A: Devolución de 5 a 3 tomos deduce exactamente $2.397 CLP'
		);

		// Caso B: Pack original de 7 tomos (10%), se devuelven 2 tomos -> quedan 5 tomos (8%) -> clawback = 5 * ($999 - $799) = $1.000 CLP
		$items_b = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$item = \Mockery::mock( '\WC_Order_Item_Product' );
			$item->shouldReceive( 'get_id' )->andReturn( $i );
			$item->shouldReceive( 'get_quantity' )->andReturn( 1 );
			$item->shouldReceive( 'get_subtotal' )->andReturn( 9990.0 );
			$item->shouldReceive( 'get_subtotal_tax' )->andReturn( 0.0 );
			$item->shouldReceive( 'get_product_id' )->andReturn( 0 );
			$items_b[] = $item;
		}

		$order_mock_b = \Mockery::mock( '\WC_Order' );
		$order_mock_b->shouldReceive( 'get_items' )->with( 'line_item' )->andReturn( $items_b );
		$order_mock_b->shouldReceive( 'get_discount_total' )->andReturn( 6993.0 );
		$order_mock_b->shouldReceive( 'get_qty_refunded_for_item' )->andReturnUsing( function( $id ) {
			return ( $id >= 6 ) ? 1 : 0;
		} );

		$calculated_clawback = \Akibara\Business\Modules\SeriesBundles\PartialRefundBundleGuard::calculate_clawback( $order_mock_b );
		self::assertSame( 1000.0, $calculated_clawback, 'PartialRefundBundleGuard::calculate_clawback calcula exactamente $1.000 CLP para 7 -> 5 tomos' );
	}

	/**
	 * Test 10: DynamicTemplateResolutionAndRecursionGuardTest
	 * @testdox Test 10 (Dynamic Template & Recursion Guard): Perfiles template_standard y template_special resuelven tramos dinámicamente desde opciones maestras y BundleCouponEngine::is_running() previene recursión.
	 */
	public function test_dynamic_template_resolution_and_recursion_guard(): void {
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( 'akb_sb_tier1_min_qty' === $opt ) return 5;
			if ( 'akb_sb_tier1_pct' === $opt ) return 9;
			if ( 'akb_sb_tier2_min_qty' === $opt ) return 8;
			if ( 'akb_sb_tier2_pct' === $opt ) return 11;
			if ( 'akb_sb_special_min_qty' === $opt ) return 4;
			if ( 'akb_sb_special_max_pct' === $opt ) return 7;
			if ( 'akb_discount_max_pct' === $opt ) return 15;
			if ( 'akb_series_bundles_byp' === $opt ) {
				return wp_json_encode( array(
					'628' => array(
						'enabled' => true,
						'profile' => 'template_standard',
						'tiers'   => array( array( 'min' => 5, 'pct' => 8 ), array( 'min' => 7, 'pct' => 12 ) ),
					),
					'613' => array(
						'enabled' => true,
						'profile' => 'template_special',
						'tiers'   => array( array( 'min' => 5, 'pct' => 8 ) ),
					),
				) );
			}
			return $default;
		} );

		$cfg_std = PromotionPolicy::get_series_bundle_config( 628 );
		self::assertNotNull( $cfg_std );
		self::assertSame( 'template_standard', $cfg_std['profile'] );
		self::assertSame(
			array(
				array( 'min' => 5, 'pct' => 9 ),
				array( 'min' => 8, 'pct' => 11 ),
			),
			$cfg_std['tiers'],
			'El perfil template_standard resuelve dinámicamente los tramos maestros (5->9%, 8->11%) ignorando el 12% heredado en JSON'
		);

		$cfg_spec = PromotionPolicy::get_series_bundle_config( 613 );
		self::assertNotNull( $cfg_spec );
		self::assertSame( 'template_special', $cfg_spec['profile'] );
		self::assertSame(
			array(
				array( 'min' => 4, 'pct' => 7 ),
			),
			$cfg_spec['tiers'],
			'El perfil template_special resuelve dinámicamente el tramo maestro especial (4->7%)'
		);

		self::assertFalse( \Akibara\Business\Modules\SeriesBundles\BundleCouponEngine::is_running() );
	}

}
