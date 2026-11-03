<?php
/**
 * Frontera de asignación de cupones BYP sobre WC_Discounts.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\BundleCouponEngine;
use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\DiscountEngine;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use PHPUnit\Framework\TestCase;

final class BundleCouponProduct extends \WC_Product {
	private string $current_price;
	public bool $taxable = true;

	public function __construct( int $product_id, float $regular_price = 9990.0, ?float $current_price = null ) {
		parent::__construct( 'instock' );
		$this->akb_id            = $product_id;
		$this->akb_regular_price = (string) $regular_price;
		$this->current_price      = (string) ( $current_price ?? $regular_price );
	}

	public function get_price(): string {
		return $this->current_price;
	}

	public function is_taxable(): bool {
		return $this->taxable;
	}
}

final class BundleCouponCustomer {
	public function __construct( private bool $vat_exempt ) {}

	public function get_is_vat_exempt(): bool {
		return $this->vat_exempt;
	}
}

final class BundleCouponOrderLine extends \WC_Order_Item_Product {
	public function __construct(
		private int $product_id,
		private float $subtotal,
		private float $total,
		private float $subtotal_tax = 0.0,
		private float $total_tax = 0.0,
		private int $quantity = 1
	) {
	}

	public function get_product_id(): int { return $this->product_id; }
	public function get_variation_id(): int { return 0; }
	public function get_id(): int { return $this->akb_id; }
	public function get_subtotal(): float { return $this->subtotal; }
	public function get_total(): float { return $this->total; }
	public function get_subtotal_tax(): float { return $this->subtotal_tax; }
	public function get_total_tax(): float { return $this->total_tax; }
	public function get_quantity(): int { return $this->quantity; }
	public function add_meta_data( string $key, $value, bool $unique = false ): void {
		unset( $unique );
		$this->akb_meta[ $key ] = (string) $value;
	}
}

final class BundleCouponOrder extends \WC_Order {
	/**
	 * @param array<int,BundleCouponOrderLine> $lines
	 * @param array<int,\WC_Order_Item_Coupon> $coupons
	 */
	public function __construct(
		private array $lines,
		private bool $prices_include_tax,
		private array $coupons = array(),
		private float $discount_total = 0.0,
		private float $discount_tax = 0.0
	) {
		parent::__construct();
	}

	public function get_items( string $types = 'line_item' ): array {
		return 'line_item' === $types ? $this->lines : ( 'coupon' === $types ? $this->coupons : array() );
	}

	public function get_prices_include_tax(): bool { return $this->prices_include_tax; }
	public function get_discount_total(): float { return $this->discount_total; }
	public function get_discount_tax(): float { return $this->discount_tax; }
	public function set_discount_total( float $amount ): void { $this->discount_total = $amount; }
	public function set_discount_tax( float $amount ): void { $this->discount_tax = $amount; }
}

final class BundleCouponDiscountsContext {
	public function __construct( private object $object ) {}
	public function get_object(): object { return $this->object; }
}

/**
 * Cada prueba declara dobles globales WC_Cart/WC_Coupon/WC() en un proceso
 * aislado para no contaminar el resto de la suite compartida.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BundleCouponEngineTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/fixtures/wordpress-functions.php';

		if ( ! class_exists( '\WC_Cart' ) ) {
			eval(
				'namespace { class WC_Cart {
					private array $items;
					public array $applied_coupons = array();
					public array $coupon_discounts = array();
					public array $coupon_discount_taxes = array();
					public function __construct( array $items ) { $this->items = $items; }
					public function get_cart(): array { return $this->items; }
					public function get_applied_coupons(): array { return $this->applied_coupons; }
					public function get_coupon_discount_amount( string $code ): float { return (float) ( $this->coupon_discounts[ $code ] ?? 0 ); }
					public function get_coupon_discount_tax_amount( string $code ): float { return (float) ( $this->coupon_discount_taxes[ $code ] ?? 0 ); }
				} }'
			);
		}

		if ( ! class_exists( '\WC_Coupon' ) ) {
			eval(
				'namespace { class WC_Coupon {
					private string $code;
					private string $type;
					private float $amount = 0.0;
					private array $product_ids = array();
					public function __construct( string $code, string $type = "akb_byp_exact" ) { $this->code = $code; $this->type = $type; }
					public function get_code(): string { return $this->code; }
					public function get_discount_type(): string { return $this->type; }
					public function set_discount_type( string $type ): void { $this->type = $type; }
					public function get_amount(): float { return $this->amount; }
					public function set_amount( float $amount ): void { $this->amount = $amount; }
					public function set_product_ids( array $ids ): void { $this->product_ids = $ids; }
					public function get_product_ids(): array { return $this->product_ids; }
				} }'
			);
		}

		if ( ! class_exists( '\WC_Order_Item_Coupon' ) ) {
			eval(
				'namespace { class WC_Order_Item_Coupon {
					private array $meta = array();
					public int $saves = 0;
					public string $code = "";
					public float $discount = 0.0;
					public float $discount_tax = 0.0;
					public function add_meta_data( string $key, $value, bool $unique = false ): void { unset( $unique ); $this->meta[ $key ] = $value; }
					public function update_meta_data( string $key, $value ): void { $this->meta[ $key ] = $value; }
					public function get_meta( string $key, bool $single = true ) { unset( $single ); return $this->meta[ $key ] ?? ""; }
					public function get_discount(): float { return $this->discount; }
					public function get_discount_tax(): float { return $this->discount_tax; }
					public function get_code(): string { return $this->code; }
					public function set_discount( float $amount ): void { $this->discount = $amount; }
					public function set_discount_tax( float $amount ): void { $this->discount_tax = $amount; }
					public function save(): int { return ++$this->saves; }
				} }'
			);
		}

		if ( ! function_exists( 'WC' ) ) {
			eval( 'namespace { function WC() { return $GLOBALS["akibara_byp_wc"]; } }' );
		}

		if ( ! function_exists( 'get_the_terms' ) ) {
			eval(
				'namespace { function get_the_terms( int $product_id, string $taxonomy ) {
					if ( "pa_serie" !== $taxonomy ) { return array(); }
					$term_id = $GLOBALS["akibara_byp_terms"][ $product_id ] ?? 0;
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

		if ( ! function_exists( 'wc_prices_include_tax' ) ) {
			eval( 'namespace { function wc_prices_include_tax(): bool { return ! empty( $GLOBALS["akibara_byp_prices_include_tax"] ); } }' );
		}

		if ( ! function_exists( 'wc_get_price_excluding_tax' ) ) {
			eval(
				'namespace { function wc_get_price_excluding_tax( $product, array $args = array() ): float {
					$price = isset( $args["price"] ) ? (float) $args["price"] : (float) $product->get_price();
					$divisor = (float) ( $GLOBALS["akibara_byp_tax_divisor"] ?? 1.0 );
					return $divisor > 0 ? $price / $divisor : $price;
				} }'
			);
		}

		if ( ! function_exists( 'add_filter' ) ) {
			eval(
				'namespace { function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
					$GLOBALS["akibara_byp_filters"][] = array( $hook, $callback, $priority, $accepted_args );
					return true;
				} }'
			);
		}

		if ( ! function_exists( 'add_action' ) ) {
			eval(
				'namespace { function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
					$GLOBALS["akibara_byp_actions"][] = array( $hook, $callback, $priority, $accepted_args );
					return true;
				} }'
			);
		}

		$GLOBALS['akibara_byp_terms']   = array();
		$GLOBALS['akibara_byp_filters'] = array();
		$GLOBALS['akibara_byp_actions'] = array();
		$GLOBALS['akibara_byp_prices_include_tax'] = false;
		$GLOBALS['akibara_byp_tax_divisor'] = 1.19;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['akibara_test_options'],
			$GLOBALS['akibara_byp_terms'],
			$GLOBALS['akibara_byp_filters'],
			$GLOBALS['akibara_byp_actions'],
			$GLOBALS['akibara_byp_wc'],
			$GLOBALS['akibara_byp_prices_include_tax'],
			$GLOBALS['akibara_byp_tax_divisor']
		);

		parent::tearDown();
	}

	/** @testdox El cupón exacto publica exclusivamente los product_ids elegibles */
	public function test_virtual_coupon_contains_eligible_product_ids(): void {
		$cart = $this->cart_for_series( array( 10 => array( 1, 2, 3, 4, 5 ) ), array( 99 ) );
		$hash = md5( '10' );

		$data = $this->sut()->fabricate_virtual_coupon( false, BundleCouponEngine::COUPON_BYP_PREFIX . $hash );

		self::assertIsArray( $data );
		self::assertSame( BundleCouponEngine::COUPON_TYPE, $data['discount_type'] );
		self::assertSame( 4995.0, $data['amount'] );
		self::assertSame( array( 1, 2, 3, 4, 5 ), $data['product_ids'] );
		self::assertSame( $cart, $GLOBALS['akibara_byp_wc']->cart );
	}

	/** @testdox Cada SKU heterogéneo recibe exactamente su contribución porcentual */
	public function test_exact_contribution_is_calculated_per_heterogeneous_sku(): void {
		$prices = array(
			1 => array( 10000.0, 10000.0 ),
			2 => array( 12000.0, 12000.0 ),
			3 => array( 14000.0, 14000.0 ),
			4 => array( 16000.0, 16000.0 ),
			5 => array( 18000.0, 18000.0 ),
		);
		$cart   = $this->cart_for_series( array( 10 => array( 1, 2, 3, 4, 5 ) ), array(), 0, 1, $prices );
		$sut    = $this->sut();
		$coupon = new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ) );
		$actual = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			$actual[] = $sut->handle_woocommerce_coupon_get_discount_amount(
				999999.0,
				(float) $cart_item['data']->get_price(),
				$cart_item,
				true,
				$coupon
			);
		}

		self::assertSame( array( 1000.0, 1200.0, 1400.0, 1600.0, 1800.0 ), $actual );
		self::assertSame( 7000.0, array_sum( $actual ) );
		self::assertSame(
			7000.0,
			$this->sut()->fabricate_virtual_coupon( false, $coupon->get_code() )['amount'],
			'El monto nominal debe ser la suma de las asignaciones redondeadas por línea.'
		);
	}

	/** @testdox Base IVA precisa da $84 exento y conserva $101 gravado para precio $1.005 */
	public function test_live_wc_rounded_tax_base_uses_precise_exempt_reference(): void {
		$prices = array_fill( 1, 5, array( 1005.0, 1005.0 ) );
		$cart   = $this->cart_for_series( array( 10 => array( 1, 2, 3, 4, 5 ) ), array(), 0, 1, $prices );
		$coupon = new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ) );
		$item   = $cart->get_cart()[0];
		$sut    = $this->sut();

		$GLOBALS['akibara_byp_prices_include_tax'] = true;
		$GLOBALS['akibara_byp_wc']->customer = new BundleCouponCustomer( true );
		self::assertSame(
			84.0,
			$sut->handle_woocommerce_coupon_get_discount_amount( 999.0, 845.0, $item, true, $coupon ),
			'WC redondea el neto a 845, pero el 10% debe salir de 1.005/1,19.'
		);

		$GLOBALS['akibara_byp_wc']->customer = new BundleCouponCustomer( false );
		self::assertSame(
			101.0,
			$sut->handle_woocommerce_coupon_get_discount_amount( 999.0, 1005.0, $item, true, $coupon ),
			'Un cliente gravado conserva el aporte bruto CLP redondeado.'
		);
	}

	/** @testdox Best-wins exacto descuenta sólo la diferencia restante de una oferta */
	public function test_exact_contribution_preserves_best_wins_for_sale_price(): void {
		$prices = array(
			1 => array( 10000.0, 8500.0 ), // Mejor que el target 9.000: aporte 0.
			2 => array( 10000.0, 9500.0 ), // Aporte restante 500.
			3 => array( 10000.0, 10000.0 ),
			4 => array( 10000.0, 10000.0 ),
			5 => array( 10000.0, 10000.0 ),
		);
		$cart   = $this->cart_for_series( array( 10 => array( 1, 2, 3, 4, 5 ) ), array(), 0, 1, $prices );
		$sut    = $this->sut();
		$coupon = new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ) );
		$items  = $cart->get_cart();

		self::assertSame(
			0.0,
			$sut->handle_woocommerce_coupon_get_discount_amount( 999.0, 8500.0, $items[0], true, $coupon )
		);
		self::assertSame(
			500.0,
			$sut->handle_woocommerce_coupon_get_discount_amount( 999.0, 9500.0, $items[1], true, $coupon )
		);
	}

	/** @testdox Qty extra, SKU ajeno y contextos de orden reciben cero beneficio adicional */
	public function test_exact_contribution_caps_quantity_and_fails_closed_outside_cart_line(): void {
		$cart        = $this->cart_for_series( array( 10 => array( 1, 2, 3, 4, 5 ) ), array( 99 ), 1, 2 );
		$sut         = $this->sut();
		$coupon      = new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ) );
		$cart_items  = $cart->get_cart();
		$eligible    = $cart_items[0];
		$unrelated   = $cart_items[5];

		self::assertSame(
			999.0,
			$sut->handle_woocommerce_coupon_get_discount_amount( 0.0, 9990.0, $eligible, true, $coupon ),
			'Una línea qty=2 recibe el aporte de una sola unidad; WC usa el clone qty=1.'
		);
		self::assertSame(
			0.0,
			$sut->handle_woocommerce_coupon_get_discount_amount( 999.0, 9990.0, $unrelated, true, $coupon )
		);
		self::assertSame(
			0.0,
			$sut->handle_woocommerce_coupon_get_discount_amount( 999.0, 9990.0, new \stdClass(), true, $coupon ),
			'Una re-aplicación sobre WC_Order_Item no es un cart_item array.'
		);
		self::assertSame(
			0.0,
			$sut->handle_woocommerce_coupon_get_discount_amount(
				999.0,
				9990.0,
				$eligible,
				true,
				new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ), 'fixed_cart' )
			)
		);
	}

	/** @testdox El filtro exacto no modifica cupones externos */
	public function test_exact_contribution_does_not_modify_external_coupon(): void {
		$cart      = $this->cart_for_series( array( 10 => array( 1, 2, 3, 4, 5 ) ) );
		$cart_item = $cart->get_cart()[0];

		self::assertSame(
			321.0,
			$this->sut()->handle_woocommerce_coupon_get_discount_amount(
				321.0,
				9990.0,
				$cart_item,
				true,
				new \WC_Coupon( 'campana-verano', 'fixed_cart' )
			)
		);
	}

	/** @testdox La asignación excluye productos ajenos y limita la línea repetida a una unidad */
	public function test_filter_isolates_series_and_caps_repeated_line_to_one_unit(): void {
		$cart     = $this->cart_for_series( array( 10 => array( 1, 2, 3, 4, 5 ) ), array( 99 ), 1, 3 );
		$items    = $this->discount_items( $cart );
		$items[]  = clone $items[0]; // Segunda línea artificial del mismo SKU.
		$original = $items[0];
		$coupon   = new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ) );

		$filtered = $this->sut()->handle_woocommerce_coupon_get_items_to_apply( $items, $coupon, new \stdClass() );

		self::assertSame( array( 1, 2, 3, 4, 5 ), $this->item_product_ids( $filtered ) );
		self::assertSame( array( 1, 1, 1, 1, 1 ), array_map( static fn( object $item ): int => $item->quantity, $filtered ) );
		self::assertSame( 9990.0, $filtered[0]->price );
		self::assertSame( 3, $original->quantity, 'El filtro debe clonar y no mutar el input de WC_Discounts.' );
		self::assertSame( 29970.0, $original->price );
	}

	/** @testdox Dos cupones BYP sólo pueden asignarse a las líneas de su propia serie */
	public function test_two_series_are_isolated_by_coupon_hash(): void {
		$cart  = $this->cart_for_series(
			array(
				10 => array( 1, 2, 3, 4, 5 ),
				20 => array( 6, 7, 8, 9, 10 ),
			),
			array( 99 )
		);
		$items = $this->discount_items( $cart );

		$first = $this->sut()->handle_woocommerce_coupon_get_items_to_apply(
			$items,
			new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ) ),
			new \stdClass()
		);
		$second = $this->sut()->handle_woocommerce_coupon_get_items_to_apply(
			$items,
			new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '20' ) ),
			new \stdClass()
		);

		self::assertSame( array( 1, 2, 3, 4, 5 ), $this->item_product_ids( $first ) );
		self::assertSame( array( 6, 7, 8, 9, 10 ), $this->item_product_ids( $second ) );
	}

	/** @testdox Códigos internos desconocidos u obsoletos fallan cerrado */
	public function test_unknown_internal_code_or_hash_fails_closed(): void {
		$cart  = $this->cart_for_series( array( 10 => array( 1, 2, 3, 4, 5 ) ) );
		$items = $this->discount_items( $cart );
		$sut   = $this->sut();

		self::assertSame(
			array(),
			$sut->handle_woocommerce_coupon_get_items_to_apply(
				$items,
				new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . str_repeat( '0', 32 ) ),
				new \stdClass()
			)
		);
		self::assertSame(
			0.0,
			$sut->handle_woocommerce_coupon_get_discount_amount(
				999.0,
				9990.0,
				$cart->get_cart()[0],
				true,
				new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . str_repeat( '0', 32 ) )
			)
		);
		self::assertSame(
			array(),
			$sut->handle_woocommerce_coupon_get_items_to_apply(
				$items,
				new \WC_Coupon( BundleCouponEngine::COUPON_PREFIX . 'unknown' ),
				new \stdClass()
			)
		);
	}

	/** @testdox Cupones externos conservan intacto el universo de líneas de WooCommerce */
	public function test_external_coupon_is_not_modified(): void {
		$cart  = $this->cart_for_series( array( 10 => array( 1, 2, 3, 4, 5 ) ), array( 99 ) );
		$items = $this->discount_items( $cart );

		self::assertSame(
			$items,
			$this->sut()->handle_woocommerce_coupon_get_items_to_apply(
				$items,
				new \WC_Coupon( 'campana-verano' ),
				new \stdClass()
			)
		);
	}

	/** @testdox El tipo custom y ambos filtros exactos quedan registrados con su aridad WC */
	public function test_registers_exact_coupon_type_and_allocation_filters(): void {
		$this->cart_for_series( array( 10 => array( 1, 2, 3, 4, 5 ) ) );
		$sut = $this->sut();
		$sut->register();

		$by_hook = array();
		foreach ( $GLOBALS['akibara_byp_filters'] as $filter ) {
			$by_hook[ $filter[0] ] = $filter;
		}
		$actions_by_hook = array();
		foreach ( $GLOBALS['akibara_byp_actions'] as $action ) {
			$actions_by_hook[ $action[0] ] = $action;
		}

		self::assertArrayHasKey( 'woocommerce_coupon_discount_types', $by_hook );
		self::assertArrayHasKey( 'woocommerce_product_coupon_types', $by_hook );
		self::assertSame( 3, $by_hook['woocommerce_coupon_get_items_to_apply'][3] );
		self::assertSame( 5, $by_hook['woocommerce_coupon_get_discount_amount'][3] );
		self::assertArrayHasKey(
			BundleCouponEngine::COUPON_TYPE,
			$sut->register_coupon_type( array( 'fixed_cart' => 'Fixed' ) )
		);
		self::assertContains(
			BundleCouponEngine::COUPON_TYPE,
			$sut->register_product_coupon_type( array( 'percent' ) )
		);
		self::assertArrayNotHasKey(
			'woocommerce_order_after_calculate_totals',
			$actions_by_hook,
			'BYP no congela el split neto/IVA: WooCommerce debe recalcularlo con la fiscalidad vigente.'
		);
		self::assertSame(
			array( $sut, 'reconcile_store_api_order_snapshot' ),
			$actions_by_hook['woocommerce_store_api_checkout_order_processed'][1],
			'Store API debe reconciliar reintentos aunque el código de cupón no cambie.'
		);
	}

	/** @testdox Orden con snapshot hidrata el tipo exacto y asigna por ID de línea */
	public function test_order_snapshot_hydrates_exact_contributions_by_line_id(): void {
		$sut       = $this->sut();
		$line      = new BundleCouponOrderLine( 1, 10000.0, 9000.0, 1900.0, 1710.0 );
		$line->akb_id = 501;
		$order     = new BundleCouponOrder( array( 501 => $line ), true );
		$coupon_item = new \WC_Order_Item_Coupon();
		$coupon_item->discount = 1000.0;
		$coupon_item->discount_tax = 190.0;
		$coupon_item->add_meta_data(
			BundleCouponEngine::ORDER_SNAPSHOT_META,
			json_encode(
				array(
					'version'                    => 1,
					'prices_include_tax'         => true,
					'gross_discount'             => 1190.0,
					'line_contributions'         => array(),
					'order_line_contributions'   => array(
						'501' => array( 'product_id' => 1, 'amount' => 1190.0 ),
					),
				),
				JSON_THROW_ON_ERROR
			)
		);
		$coupon = new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ), 'fixed_cart' );

		$hydrated = $sut->handle_order_recalculate_coupon_object( $coupon, $coupon->get_code(), $coupon_item, $order );

		self::assertSame( BundleCouponEngine::COUPON_TYPE, $hydrated->get_discount_type() );
		self::assertSame( 1190.0, $hydrated->get_amount() );
		self::assertSame( array( 1 ), $hydrated->get_product_ids() );
		self::assertSame(
			1190.0,
			$sut->handle_woocommerce_coupon_get_discount_amount( 0.0, 11900.0, $line, true, $hydrated )
		);

		$item           = new \stdClass();
		$item->object   = $line;
		$item->quantity = 2;
		$item->price    = 23800.0;
		$item->product  = new BundleCouponProduct( 1, 11900.0 );
		$filtered = $sut->handle_woocommerce_coupon_get_items_to_apply(
			array( $item ),
			$hydrated,
			new BundleCouponDiscountsContext( $order )
		);
		self::assertCount( 1, $filtered );
		self::assertSame( 1, $filtered[0]->quantity );
		self::assertSame( 11900.0, $filtered[0]->price );
	}

	/** @testdox Snapshot ausente o corrupto vuelve a fixed_cart histórico, nunca a cero */
	public function test_legacy_or_corrupt_order_coupon_falls_back_to_historical_fixed_cart(): void {
		$sut       = $this->sut();
		$order     = new BundleCouponOrder( array(), true );
		$item      = new \WC_Order_Item_Coupon();
		$item->discount = 1000.0;
		$item->discount_tax = 190.0;
		$item->add_meta_data( BundleCouponEngine::ORDER_SNAPSHOT_META, '{corrupto' );
		$coupon = new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ) );

		$hydrated = $sut->handle_order_recalculate_coupon_object( $coupon, $coupon->get_code(), $item, $order );

		self::assertSame( 'fixed_cart', $hydrated->get_discount_type() );
		self::assertSame( 1190.0, $hydrated->get_amount() );
		self::assertSame(
			321.0,
			$sut->handle_woocommerce_coupon_get_discount_amount(
				321.0,
				1000.0,
				new BundleCouponOrderLine( 1, 1000.0, 1000.0 ),
				true,
				$hydrated
			)
		);
	}

	/** @testdox Snapshot autoconsistente pero distinto al cupón histórico cae al fallback */
	public function test_snapshot_gross_must_match_historical_coupon_amount(): void {
		$sut       = $this->sut();
		$order     = new BundleCouponOrder( array(), true );
		$item      = new \WC_Order_Item_Coupon();
		$item->discount = 1000.0;
		$item->discount_tax = 190.0;
		$item->add_meta_data(
			BundleCouponEngine::ORDER_SNAPSHOT_META,
			json_encode(
				array(
					'version'                  => 1,
					'prices_include_tax'       => true,
					'gross_discount'           => 900.0,
					'line_contributions'       => array(),
					'order_line_contributions' => array(
						'501' => array( 'product_id' => 1, 'amount' => 900.0 ),
					),
				),
				JSON_THROW_ON_ERROR
			)
		);
		$coupon = new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ) );

		$hydrated = $sut->handle_order_recalculate_coupon_object( $coupon, $coupon->get_code(), $item, $order );

		self::assertSame( 'fixed_cart', $hydrated->get_discount_type() );
		self::assertSame( 1190.0, $hydrated->get_amount() );
	}

	/** @testdox Snapshot con line ID reemplazado cae al importe histórico */
	public function test_stale_line_id_snapshot_falls_back_to_historical_fixed_cart(): void {
		$sut  = $this->sut();
		$line = new BundleCouponOrderLine( 1, 10000.0, 9000.0, 1900.0, 1710.0 );
		$line->akb_id = 777; // El snapshot aún apunta a la línea eliminada 501.
		$order = new BundleCouponOrder( array( 777 => $line ), true );
		$item  = new \WC_Order_Item_Coupon();
		$item->discount = 1000.0;
		$item->discount_tax = 190.0;
		$item->add_meta_data(
			BundleCouponEngine::ORDER_SNAPSHOT_META,
			json_encode(
				array(
					'version'                  => 1,
					'prices_include_tax'       => true,
					'gross_discount'           => 1190.0,
					'line_contributions'       => array(),
					'order_line_contributions' => array(
						'501' => array( 'product_id' => 1, 'amount' => 1190.0 ),
					),
				),
				JSON_THROW_ON_ERROR
			)
		);
		$coupon = new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ) );

		$hydrated = $sut->handle_order_recalculate_coupon_object( $coupon, $coupon->get_code(), $item, $order );

		self::assertSame( 'fixed_cart', $hydrated->get_discount_type() );
		self::assertSame( 1190.0, $hydrated->get_amount() );
	}

	/** @testdox Snapshot con SKU distinto o aporte mayor a la base cae al histórico */
	public function test_snapshot_product_or_amount_mismatch_falls_back(): void {
		$sut  = $this->sut();
		$line = new BundleCouponOrderLine( 2, 100.0, 100.0 );
		$line->akb_id = 501;
		$order = new BundleCouponOrder( array( 501 => $line ), false );
		$item  = new \WC_Order_Item_Coupon();
		$item->discount = 1190.0;
		$item->add_meta_data(
			BundleCouponEngine::ORDER_SNAPSHOT_META,
			json_encode(
				array(
					'version'                  => 1,
					'prices_include_tax'       => false,
					'gross_discount'           => 1190.0,
					'order_line_contributions' => array(
						'501' => array( 'product_id' => 1, 'amount' => 1190.0 ),
					),
				),
				JSON_THROW_ON_ERROR
			)
		);
		$coupon = new \WC_Coupon( BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' ) );

		$hydrated = $sut->handle_order_recalculate_coupon_object( $coupon, $coupon->get_code(), $item, $order );

		self::assertSame( 'fixed_cart', $hydrated->get_discount_type() );
		self::assertSame( 1190.0, $hydrated->get_amount() );
	}

	/** @testdox Store API regenera IDs y montos cuando reintenta el mismo código con carrito cambiado */
	public function test_store_api_retry_rebuilds_same_code_snapshot_from_live_cart(): void {
		$prices = array(
			1 => array( 10000.0, 10000.0 ),
			2 => array( 12000.0, 12000.0 ),
			3 => array( 14000.0, 14000.0 ),
			4 => array( 16000.0, 16000.0 ),
			5 => array( 18000.0, 18000.0 ),
		);
		$cart = $this->cart_for_series( array( 10 => array( 1, 2, 3, 4, 5 ) ), array(), 0, 1, $prices );
		$code = BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' );
		$cart->applied_coupons = array( $code );
		$cart->coupon_discounts[ $code ] = 7000.0;
		$cart->coupon_discount_taxes[ $code ] = 0.0;

		$lines = array();
		foreach ( array( 1 => 10000.0, 2 => 12000.0, 3 => 14000.0, 4 => 16000.0, 5 => 18000.0 ) as $product_id => $subtotal ) {
			$line = new BundleCouponOrderLine( $product_id, $subtotal, $subtotal );
			$line->akb_id = 600 + $product_id;
			$lines[ $line->akb_id ] = $line;
		}

		$coupon_item = new \WC_Order_Item_Coupon();
		$coupon_item->code = $code;
		$coupon_item->discount = 4995.0;
		$coupon_item->add_meta_data(
			BundleCouponEngine::ORDER_SNAPSHOT_META,
			json_encode(
				array(
					'version'                  => 1,
					'gross_discount'           => 4995.0,
					'order_line_contributions' => array(
						'501' => array( 'product_id' => 1, 'amount' => 999.0 ),
					),
				),
				JSON_THROW_ON_ERROR
			)
		);
		$order = new BundleCouponOrder( $lines, false, array( $coupon_item ) );

		$this->sut()->reconcile_store_api_order_snapshot( $order );

		$snapshot = json_decode(
			(string) $coupon_item->get_meta( BundleCouponEngine::ORDER_SNAPSHOT_META, true ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		self::assertSame( 7000.0, $coupon_item->get_discount() );
		self::assertSame( 0.0, $coupon_item->get_discount_tax() );
		self::assertEquals( 7000.0, $snapshot['gross_discount'] );
		self::assertSame( array( 601, 602, 603, 604, 605 ), array_keys( $snapshot['order_line_contributions'] ) );
		self::assertSame(
			array( 1000.0, 1200.0, 1400.0, 1600.0, 1800.0 ),
			array_map( 'floatval', array_column( $snapshot['order_line_contributions'], 'amount' ) )
		);
		self::assertArrayNotHasKey( 501, $snapshot['order_line_contributions'] );
		self::assertSame( 1, $coupon_item->saves );
	}

	/** @testdox Hidratar BYP no modifica cupón externo ni agregados de la orden */
	public function test_byp_hydration_does_not_overwrite_external_coupon_or_order_aggregates(): void {
		$sut      = $this->sut();
		$line     = new BundleCouponOrderLine( 1, 10000.0, 9000.0, 1900.0, 1710.0 );
		$line->akb_id = 501;
		$byp_item = new \WC_Order_Item_Coupon();
		$byp_item->code = BundleCouponEngine::COUPON_BYP_PREFIX . md5( '10' );
		$byp_item->discount = 1000.0;
		$byp_item->discount_tax = 190.0;
		$byp_item->add_meta_data(
			BundleCouponEngine::ORDER_SNAPSHOT_META,
			json_encode(
				array(
					'version'                  => 1,
					'prices_include_tax'       => true,
					'gross_discount'           => 1190.0,
					'order_line_contributions' => array(
						'501' => array( 'product_id' => 1, 'amount' => 1190.0 ),
					),
				),
				JSON_THROW_ON_ERROR
			)
		);
		$external = new \WC_Order_Item_Coupon();
		$external->code = 'campana-verano';
		$external->discount = 321.0;
		$external->discount_tax = 61.0;
		$order = new BundleCouponOrder(
			array( 501 => $line ),
			true,
			array( $byp_item, $external ),
			1321.0,
			251.0
		);
		$coupon = new \WC_Coupon( $byp_item->code, 'fixed_cart' );

		$sut->handle_order_recalculate_coupon_object( $coupon, $byp_item->code, $byp_item, $order );

		self::assertSame( 321.0, $external->get_discount() );
		self::assertSame( 61.0, $external->get_discount_tax() );
		self::assertSame( 1321.0, $order->get_discount_total() );
		self::assertSame( 251.0, $order->get_discount_tax() );
	}

	private function sut(): BundleCouponEngine {
		$settings  = new Settings();
		$tiers     = new VolumeTierResolver();
		$collector = new CartSeriesCollector( $settings, new EligibilityGuard( $settings ) );

		return new BundleCouponEngine( $settings, new DiscountEngine( $tiers ), $collector );
	}

	/**
	 * @param array<int,array<int,int>> $series_products Mapa term_id => product_ids.
	 * @param array<int,int>            $unrelated_ids   Productos sin config BYP.
	 * @param int                       $repeated_id      Producto cuya línea trae qty extra.
	 * @param int                       $repeated_qty     Cantidad de esa línea.
	 * @param array<int,array{0:float,1:float}> $prices  Precios regular/actual por producto.
	 */
	private function cart_for_series(
		array $series_products,
		array $unrelated_ids = array(),
		int $repeated_id = 0,
		int $repeated_qty = 1,
		array $prices = array()
	): \WC_Cart {
		$config = array();
		$items  = array();

		foreach ( $series_products as $term_id => $product_ids ) {
			$config[ $term_id ] = array(
				'enabled' => true,
				'tiers'   => array( array( 'min' => 5, 'pct' => 10 ) ),
			);
			foreach ( $product_ids as $product_id ) {
				$GLOBALS['akibara_byp_terms'][ $product_id ] = $term_id;
				$quantity = $product_id === $repeated_id ? $repeated_qty : 1;
				$regular = isset( $prices[ $product_id ][0] ) ? (float) $prices[ $product_id ][0] : 9990.0;
				$current = isset( $prices[ $product_id ][1] ) ? (float) $prices[ $product_id ][1] : $regular;
				$items[]  = $this->cart_item( $product_id, $quantity, $regular, $current );
			}
		}

		foreach ( $unrelated_ids as $product_id ) {
			$items[] = $this->cart_item( $product_id );
		}

		$GLOBALS['akibara_test_options'] = array(
			Settings::OPTION_ENABLED         => 'yes',
			Settings::OPTION_BYP             => json_encode( $config, JSON_THROW_ON_ERROR ),
			Settings::OPTION_EXCLUDE_CATS    => array(),
			Settings::OPTION_ALLOW_BACKORDER => 'no',
		);

		$cart = new \WC_Cart( $items );
		$GLOBALS['akibara_byp_wc'] = (object) array( 'cart' => $cart );

		return $cart;
	}

	/** @return array<string,mixed> */
	private function cart_item(
		int $product_id,
		int $quantity = 1,
		float $regular_price = 9990.0,
		?float $current_price = null
	): array {
		return array(
			'product_id' => $product_id,
			'quantity'   => $quantity,
			'data'       => new BundleCouponProduct( $product_id, $regular_price, $current_price ),
		);
	}

	/** @return array<int,object> */
	private function discount_items( \WC_Cart $cart ): array {
		$items = array();
		foreach ( $cart->get_cart() as $key => $cart_item ) {
			$item           = new \stdClass();
			$item->key      = (string) $key;
			$item->object   = $cart_item;
			$item->product  = $cart_item['data'];
			$item->quantity = $cart_item['quantity'];
			$item->price    = (float) $item->product->get_price() * $item->quantity;
			$items[]        = $item;
		}

		return $items;
	}

	/**
	 * @param array<int,object> $items Líneas filtradas.
	 * @return array<int,int>
	 */
	private function item_product_ids( array $items ): array {
		return array_map( static fn( object $item ): int => (int) $item->product->get_id(), $items );
	}
}
