<?php
/**
 * Tests for the sole Akibara Store API adapter.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\Business\Contracts\CheckoutDataProvider;
use Akibara\CartCheckoutBlocks\BlocksPage;
use Akibara\CartCheckoutBlocks\StoreApiIntegration;
use PHPUnit\Framework\TestCase;
use WC_Product;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class StoreApiIntegrationTest extends TestCase {

	/**
	 * @testdox Should publish cart and item schemas under one Store API namespace.
	 */
	public function test_uses_one_namespace_for_complete_store_api_schema(): void {
		$sut         = new StoreApiIntegration( new CheckoutDataProviderStub(), new BlocksPage() );
		$cart_schema = $sut->extend_cart_schema();
		$item_schema = $sut->extend_cart_item_schema();
		$source      = file_get_contents( dirname( __DIR__, 2 ) . '/src/StoreApiIntegration.php' );

		self::assertSame( 'akibara_cart_checkout_blocks', StoreApiIntegration::NAMESPACE );
		self::assertSame(
			array(
				'schema_version',
				'bundle',
				'free_shipping',
				'preventa',
				'trust_signals',
			),
			array_keys( $cart_schema ),
			'The cart schema should expose only the structured versioned read model.'
		);
		self::assertSame(
			array( 'stock_status', 'reservation_price_label', 'reservation_price_note', 'stock_label', 'is_preventa', 'eta', 'eta_timestamp' ),
			array_keys( $item_schema ),
			'The item schema should expose canonical stock and preventa data together.'
		);
		self::assertSame(
			array( 'instock', 'onbackorder', 'outofstock' ),
			$item_schema['stock_status']['enum'],
			'Store API should accept only canonical WooCommerce stock states.'
		);
		self::assertIsString( $source, 'The Store API adapter source should be readable.' );
		self::assertSame(
			2,
			substr_count( $source, "'namespace'       => self::NAMESPACE" ),
			'Exactly the Cart and Cart Item endpoints should use the single namespace.'
		);
		self::assertStringNotContainsString(
			'akibara_order_preventa',
			$source,
			'The retired Store API namespace must not survive the cut.'
		);
		self::assertStringNotContainsString(
			'_html',
			$source,
			'Store API must not transport server-rendered HTML.'
		);
	}

	/**
	 * @testdox Should serialize bundle coupon labels as a JSON object even when empty.
	 */
	public function test_serializes_bundle_coupon_labels_as_json_object(): void {
		$provider                         = new CheckoutDataProviderStub();
		$provider->structured_data        = $this->structured_data();
		$provider->structured_data['bundle']['coupon_labels'] = array();
		$sut                              = new StoreApiIntegration( $provider, new BlocksPage() );
		$empty_labels                     = $sut->extend_cart_data()['bundle']['coupon_labels'];

		self::assertInstanceOf(
			\stdClass::class,
			$empty_labels,
			'The Store API runtime value must match its object schema when no labels exist.'
		);
		self::assertSame( '{}', json_encode( $empty_labels ), 'Empty labels should never become a JSON array.' );

		$provider->structured_data['bundle']['coupon_labels'] = array(
			'akb_sb_byp_abc' => 'Pack Chainsaw Man',
		);
		$labels = $sut->extend_cart_data()['bundle']['coupon_labels'];

		self::assertInstanceOf(
			\stdClass::class,
			$labels,
			'Populated labels must preserve the same Store API object type.'
		);
		self::assertSame(
			'{"akb_sb_byp_abc":"Pack Chainsaw Man"}',
			json_encode( $labels ),
			'Coupon codes and commercial labels should survive object normalization.'
		);
	}

	/**
	 * @testdox Should fail closed when Business does not provide schema version 2.
	 */
	public function test_rejects_stale_structured_contract(): void {
		$provider                  = new CheckoutDataProviderStub();
		$provider->structured_data = array(
			'schema_version' => 1,
			'bundle'         => array( 'coupon_labels' => array( 'unsafe' => '<b>HTML</b>' ) ),
		);
		$sut                       = new StoreApiIntegration( $provider, new BlocksPage() );

		self::assertSame(
			array( 'schema_version' => 0 ),
			$sut->extend_cart_data()
		);
	}

	/**
	 * @testdox Should append Estado and ETA metadata during Store API REST requests.
	 */
	public function test_appends_stock_metadata_for_store_api_rest_request(): void {
		$provider = new CheckoutDataProviderStub();
		$sut      = $this->integration( $provider, true, false, false );
		$result   = $sut->append_stock_item_data( $this->item_data(), array( 'data' => $this->product() ) );

		self::assertSame( 1, $provider->availability_calls );
		self::assertSame(
			array( 'key' => 'Estado', 'display' => 'Preventa' ),
			$result[1],
			'Store API REST responses should include the canonical visible stock vocabulary.'
		);
		self::assertSame(
			array( 'key' => 'Llegada estimada a Akibara', 'display' => 'Te avisamos en cuanto tengamos fecha' ),
			$result[2],
			'The dedicated Blocks adapter must own native per-item ETA presentation.'
		);
	}

	public function test_price_future_note_is_present_only_when_the_provider_supplies_it(): void {
		$provider = new CheckoutDataProviderStub();
		$provider->price_note = 'Al llegar el stock sube a $11.500';
		$sut = $this->integration( $provider, true, false, false );
		$result = $sut->append_stock_item_data( array(), array( 'data' => $this->product() ) );
		self::assertSame( array( 'key' => 'Precio futuro', 'display' => $provider->price_note ), $result[2] );
		$provider->price_note = '';
		$sut = $this->integration( $provider, true, false, false );
		self::assertCount( 2, $sut->append_stock_item_data( array(), array( 'data' => $this->product() ) ) );
	}

	/**
	 * @testdox Should append Estado while Woo preloads a $page Cart or Checkout Block.
	 *
	 * @dataProvider blocks_page_contexts
	 */
	public function test_appends_stock_metadata_during_blocks_page_preload( string $page ): void {
		$provider = new CheckoutDataProviderStub();
		$sut      = $this->integration(
			$provider,
			false,
			'cart' === $page,
			'checkout' === $page
		);
		$result   = $sut->append_stock_item_data( $this->item_data(), array( 'data' => $this->product() ) );

		self::assertSame( 1, $provider->availability_calls );
		self::assertSame(
			array( 'key' => 'Estado', 'display' => 'Preventa' ),
			$result[1],
			'Initial Block preload should match the later Store API refresh.'
		);
		self::assertSame(
			array( 'key' => 'Llegada estimada a Akibara', 'display' => 'Te avisamos en cuanto tengamos fecha' ),
			$result[2]
		);
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function blocks_page_contexts(): array {
		return array(
			'Cart Block'     => array( 'cart' ),
			'Checkout Block' => array( 'checkout' ),
		);
	}

	/**
	 * @testdox Should not append Estado when both Store API and Blocks page contexts are false.
	 */
	public function test_does_not_append_stock_metadata_to_classic_cart(): void {
		$provider = new CheckoutDataProviderStub();
		$sut      = $this->integration( $provider, false, false, false );
		$original = $this->item_data();
		$result   = $sut->append_stock_item_data( $original, array( 'data' => $this->product() ) );

		self::assertSame( $original, $result, 'Classic cart metadata must remain untouched.' );
		self::assertSame( 0, $provider->availability_calls, 'Classic requests should not query adapter presentation data.' );
	}

	/**
	 * Candado por invariante: la disponibilidad de un mismo ítem se resuelve UNA
	 * vez por respuesta, y el contrato servido no cambia por ello.
	 *
	 * Las dos superficies del mismo ítem (el data_callback del CartItemSchema y
	 * el filtro `woocommerce_get_item_data`, que el propio CartItemSchema
	 * dispara) preguntaban lo mismo dos veces. Medido en Docker el 2026-08-23
	 * con un GET /wc/store/v1/cart de 2 ítems: 4 llamadas reales antes, 2
	 * después. El candado fija «una llamada por ítem», no un número de ms.
	 *
	 * @testdox Should resolve availability once per product within a response.
	 */
	public function test_resolves_availability_once_per_product_in_one_response(): void {
		$provider = new CheckoutDataProviderStub();
		$sut      = $this->integration( $provider, true, false, false );
		$product  = $this->product();
		$product->akb_id = 4321;

		$schema_payload = $sut->extend_cart_item_data( array( 'data' => $product ) );
		$item_data      = $sut->append_stock_item_data( $this->item_data(), array( 'data' => $product ) );

		self::assertSame(
			1,
			$provider->availability_calls,
			'Las dos superficies del mismo ítem deben compartir una única resolución por respuesta.'
		);
		self::assertSame(
			$schema_payload['stock_label'],
			$item_data[1]['display'],
			'Memoizar no puede desalinear el schema del Store API y los metadatos nativos.'
		);
		self::assertSame( 'Preventa', $schema_payload['stock_label'] );
		self::assertSame(
			array( 'key' => 'Llegada estimada a Akibara', 'display' => 'Te avisamos en cuanto tengamos fecha' ),
			$item_data[2],
			'La ETA servida debe ser la misma que antes de memoizar.'
		);
	}

	/**
	 * Candado por invariante: el memo NUNCA sirve datos de otro producto ni de
	 * un estado de stock ya superado. La clave lleva el estado justamente para
	 * que una reducción de stock dentro de la misma petición falle el memo.
	 *
	 * @testdox Should never reuse availability across products or stock changes.
	 */
	public function test_memo_never_crosses_products_or_stock_changes(): void {
		$provider = new CheckoutDataProviderStub();
		$sut      = $this->integration( $provider, true, false, false );

		$first        = $this->product();
		$first->akb_id = 111;
		$second        = $this->product();
		$second->akb_id = 222;

		$sut->extend_cart_item_data( array( 'data' => $first ) );
		$sut->extend_cart_item_data( array( 'data' => $second ) );
		self::assertSame( 2, $provider->availability_calls, 'Dos productos distintos son dos resoluciones.' );

		$restocked         = new WC_Product( 'instock' );
		$restocked->akb_id = 111;
		$sut->extend_cart_item_data( array( 'data' => $restocked ) );
		self::assertSame(
			3,
			$provider->availability_calls,
			'Un cambio de estado de stock dentro de la petición debe fallar el memo, no servir el dato viejo.'
		);
	}

	/**
	 * Un producto sin ID persistido no tiene clave fiable: se resuelve siempre,
	 * que es exactamente el comportamiento previo al memo.
	 *
	 * @testdox Should keep recomputing availability for products without an ID.
	 */
	public function test_products_without_id_are_never_memoized(): void {
		$provider = new CheckoutDataProviderStub();
		$sut      = $this->integration( $provider, true, false, false );
		$product  = $this->product();

		$sut->extend_cart_item_data( array( 'data' => $product ) );
		$sut->extend_cart_item_data( array( 'data' => $product ) );

		self::assertSame( 2, $provider->availability_calls );
	}

	/**
	 * @testdox Should fail closed when a Store API cart item has no product.
	 */
	public function test_missing_product_returns_conservative_availability(): void {
		$sut = new StoreApiIntegration( new CheckoutDataProviderStub(), new BlocksPage() );

		self::assertSame(
			array(
				'stock_status' => 'outofstock',
				'stock_label'  => 'Agotado',
				'is_preventa'  => false,
				'eta'           => null,
				'eta_timestamp' => null,
			),
			$sut->extend_cart_item_data( array() ),
			'Invalid cart data must never promise availability.'
		);
	}

	private function integration(
		CheckoutDataProviderStub $provider,
		bool $store_api_request,
		bool $cart_block,
		bool $checkout_block
	): StoreApiIntegration {
		require_once __DIR__ . '/fixtures/woocommerce-functions.php';

		$GLOBALS['akibara_test_woocommerce'] = new StoreApiRuntimeStub( $store_api_request );
		$GLOBALS['akibara_test_blocks_page'] = array(
			'cart'     => $cart_block,
			'checkout' => $checkout_block,
		);

		return new StoreApiIntegration( $provider, new BlocksPage() );
	}

	/**
	 * @return array<int,array{key:string,display:string}>
	 */
	private function item_data(): array {
		return array( array( 'key' => 'Formato', 'display' => 'Manga' ) );
	}

	private function product(): WC_Product {
		return new WC_Product( 'onbackorder' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function structured_data(): array {
		return array(
			'schema_version' => 2,
			'bundle'         => array(
				'coupon_prefix'        => 'akb_sb_',
				'coupon_labels'        => array(),
				'generic_coupon_label' => 'Descuento de pack',
				'notices'              => array(),
			),
			'free_shipping'  => array(),
			'preventa'       => array(),
			'trust_signals'  => array(),
		);
	}
}

final class CheckoutDataProviderStub implements CheckoutDataProvider {

	public int $availability_calls = 0;

	public string $price_note = '';

	/**
	 * @var array<string,mixed>
	 */
	public array $structured_data = array();

	public function structured_cart_data(): array {
		return $this->structured_data;
	}

	public function product_availability( WC_Product $product ): array {
		unset( $product );
		++$this->availability_calls;

		return array(
			'stock_status' => 'onbackorder',
			'stock_label'  => 'Preventa',
			'is_preventa'  => true,
			'eta'           => 'Te avisamos en cuanto tengamos fecha',
			'eta_timestamp' => null,
			'reservation_price_note' => $this->price_note,
		);
	}

	public function is_valid_rut( string $rut ): bool {
		unset( $rut );

		return true;
	}

	public function format_rut( string $rut ): string {
		return $rut;
	}

	public function phone_validation_error( string $phone ): ?array {
		unset( $phone );

		return null;
	}

	public function address_autocomplete_config(): array {
		return array(
			'enabled'    => false,
			'api_key'    => '',
			'region_map' => array(),
		);
	}
}

final class StoreApiRuntimeStub {

	public bool $store_api_request;

	public function __construct( bool $store_api_request ) {
		$this->store_api_request = $store_api_request;
	}

	public function is_store_api_request(): bool {
		return $this->store_api_request;
	}
}
