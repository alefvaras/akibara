<?php
/**
 * Pruebas unitarias para MixedCartShipmentGuard (Store API + Classic + Generic Save).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Logistics;

use Akibara\Business\Modules\Logistics\MixedCartShipmentGuard;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Support\BusinessOptions;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

final class MixedCartShipmentGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_register_hooks_classic_store_api_and_generic_save(): void {
		Actions\expectAdded( 'woocommerce_review_order_before_submit' )->once();
		Actions\expectAdded( 'woocommerce_checkout_process' )->once();
		Actions\expectAdded( 'woocommerce_checkout_create_order' )->once();
		Actions\expectAdded( 'woocommerce_store_api_checkout_update_order_meta' )->once();
		Actions\expectAdded( 'woocommerce_store_api_checkout_order_processed' )->once();
		Actions\expectAdded( 'woocommerce_before_order_object_save' )->once();

		$guard = new MixedCartShipmentGuard();
		$guard->register();

		self::assertTrue( true );
	}

	public function test_order_is_mixed_detects_combination_of_instock_and_backorder(): void {
		$instock_product = Mockery::mock( WC_Product::class );
		$instock_product->shouldReceive( 'is_on_backorder' )->andReturn( false );
		$instock_product->shouldReceive( 'get_stock_status' )->andReturn( 'instock' );
		$instock_product->shouldReceive( 'get_id' )->andReturn( 101 );

		$backorder_product = Mockery::mock( WC_Product::class );
		$backorder_product->shouldReceive( 'is_on_backorder' )->andReturn( true );
		$backorder_product->shouldReceive( 'get_stock_status' )->andReturn( 'onbackorder' );
		$backorder_product->shouldReceive( 'get_id' )->andReturn( 102 );

		$item1 = Mockery::mock( WC_Order_Item_Product::class );
		$item1->shouldReceive( 'get_product' )->andReturn( $instock_product );

		$item2 = Mockery::mock( WC_Order_Item_Product::class );
		$item2->shouldReceive( 'get_product' )->andReturn( $backorder_product );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item1, $item2 ) );

		self::assertTrue( MixedCartShipmentGuard::order_is_mixed( $order ) );
		self::assertTrue( MixedCartShipmentGuard::order_has_backorder( $order ) );
	}

	public function test_order_is_mixed_returns_false_when_only_instock(): void {
		$instock_product = Mockery::mock( WC_Product::class );
		$instock_product->shouldReceive( 'is_on_backorder' )->andReturn( false );
		$instock_product->shouldReceive( 'get_stock_status' )->andReturn( 'instock' );
		$instock_product->shouldReceive( 'get_id' )->andReturn( 201 );

		$item1 = Mockery::mock( WC_Order_Item_Product::class );
		$item1->shouldReceive( 'get_product' )->andReturn( $instock_product );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item1 ) );

		self::assertFalse( MixedCartShipmentGuard::order_is_mixed( $order ) );
		self::assertFalse( MixedCartShipmentGuard::order_has_backorder( $order ) );
	}

	public function test_seal_order_acceptance_updates_metadata_on_mixed_order(): void {
		$instock_product = Mockery::mock( WC_Product::class );
		$instock_product->shouldReceive( 'is_on_backorder' )->andReturn( false );
		$instock_product->shouldReceive( 'get_stock_status' )->andReturn( 'instock' );
		$instock_product->shouldReceive( 'get_id' )->andReturn( 301 );

		$backorder_product = Mockery::mock( WC_Product::class );
		$backorder_product->shouldReceive( 'is_on_backorder' )->andReturn( true );
		$backorder_product->shouldReceive( 'get_stock_status' )->andReturn( 'onbackorder' );
		$backorder_product->shouldReceive( 'get_id' )->andReturn( 302 );

		$item1 = Mockery::mock( WC_Order_Item_Product::class );
		$item1->shouldReceive( 'get_product' )->andReturn( $instock_product );

		$item2 = Mockery::mock( WC_Order_Item_Product::class );
		$item2->shouldReceive( 'get_product' )->andReturn( $backorder_product );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item1, $item2 ) );
		$order->shouldReceive( 'get_meta' )->with( MixedCartShipmentGuard::META_KEY )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_akb_order_has_backorder' )->andReturn( '' );

		$order->shouldReceive( 'update_meta_data' )
			->once()
			->with( MixedCartShipmentGuard::META_KEY, 'yes' );

		$order->shouldReceive( 'update_meta_data' )
			->once()
			->with( '_akb_order_has_backorder', 'yes' );

		$guard = new MixedCartShipmentGuard();
		$guard->seal_order_acceptance( $order );

		self::assertTrue( true );
	}

	public function test_seal_store_api_order_delegates_to_seal_order_acceptance(): void {
		$instock_product = Mockery::mock( WC_Product::class );
		$instock_product->shouldReceive( 'is_on_backorder' )->andReturn( false );
		$instock_product->shouldReceive( 'get_stock_status' )->andReturn( 'instock' );
		$instock_product->shouldReceive( 'get_id' )->andReturn( 401 );

		$backorder_product = Mockery::mock( WC_Product::class );
		$backorder_product->shouldReceive( 'is_on_backorder' )->andReturn( true );
		$backorder_product->shouldReceive( 'get_stock_status' )->andReturn( 'onbackorder' );
		$backorder_product->shouldReceive( 'get_id' )->andReturn( 402 );

		$item1 = Mockery::mock( WC_Order_Item_Product::class );
		$item1->shouldReceive( 'get_product' )->andReturn( $instock_product );

		$item2 = Mockery::mock( WC_Order_Item_Product::class );
		$item2->shouldReceive( 'get_product' )->andReturn( $backorder_product );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item1, $item2 ) );
		$order->shouldReceive( 'get_meta' )->with( MixedCartShipmentGuard::META_KEY )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_akb_order_has_backorder' )->andReturn( '' );

		$order->shouldReceive( 'update_meta_data' )
			->once()
			->with( MixedCartShipmentGuard::META_KEY, 'yes' );

		$order->shouldReceive( 'update_meta_data' )
			->once()
			->with( '_akb_order_has_backorder', 'yes' );

		$guard = new MixedCartShipmentGuard();
		$guard->seal_store_api_order( $order );

		self::assertTrue( true );
	}

	public function test_maybe_seal_order_on_save_safely_ignores_non_orders(): void {
		$guard = new MixedCartShipmentGuard();
		$guard->maybe_seal_order_on_save( 'not_an_order' );
		$guard->maybe_seal_order_on_save( null );

		self::assertTrue( true );
	}
}
