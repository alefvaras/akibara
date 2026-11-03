<?php
/**
 * Invalidación de la colección con object cache persistente.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CustomerCollectionOrder extends \WC_Order {
	public int $user_id = 42;
	public int $persisted_user_id = 42;

	public function get_user_id( string $context = 'view' ): int {
		unset( $context );
		return $this->user_id;
	}

	public function get_type(): string {
		return 'shop_order';
	}

	/** @return array{customer_id:int} */
	public function get_data(): array {
		return array( 'customer_id' => $this->persisted_user_id );
	}
}

final class CustomerCollectionLineItem extends \WC_Order_Item_Product {
	public int $order_id = 0;
	public int $persisted_order_id = 0;

	public function get_order_id(): int {
		return $this->order_id;
	}

	/** @return array{order_id:int} */
	public function get_data(): array {
		return array( 'order_id' => $this->persisted_order_id );
	}
}

final class CustomerCollectionRefund {
	public int $id = 0;
	public int $parent_id = 0;
	public string $refund_type = '';

	/** @var array<int,mixed> */
	public array $items = array();

	public function get_parent_id(): int {
		return $this->parent_id;
	}

	public function get_id(): int {
		return $this->id;
	}

	/** @return array<int,mixed> */
	public function get_items( string $type = 'line_item' ): array {
		unset( $type );
		return $this->items;
	}

	public function get_meta( string $key, bool $single = true ): string {
		unset( $single );
		return '_refund_type' === $key ? $this->refund_type : '';
	}
}

final class CustomerCollectionOptionsWpdb {
	public string $options = 'wp_options';

	/** @var array<int,string> */
	public array $option_names = array();

	public string $last_sql = '';

	/** @var array<int,mixed> */
	public array $prepare_args = array();

	public function esc_like( string $value ): string {
		return $value;
	}

	public function prepare( string $sql, ...$args ): string {
		$this->last_sql     = $sql;
		$this->prepare_args = $args;
		return $sql;
	}

	/** @return array<int,string> */
	public function get_col( string $sql ): array {
		unset( $sql );
		return $this->option_names;
	}
}

final class CustomerCollectionLookupWpdb {
	public string $prefix = 'wp_';

	public bool $ready = true;

	public string $last_error = '';

	public string $last_query = '';

	/** @var array<int,mixed> */
	public array $rows = array();

	/** @var array<int,mixed> */
	public array $prepare_args = array();

	public string $prepared_sql = '';

	public function prepare( string $sql, ...$args ): string {
		$this->prepared_sql = $sql;
		$this->prepare_args = $args;

		return $sql;
	}

	/** @return array<int,mixed> */
	public function get_col( string $sql ): array {
		$this->last_query = $sql;

		return $this->rows;
	}
}

final class CustomerCollectionRelinkedRefundWpdb {
	public string $prefix = 'wp_';

	public bool $ready = true;

	public string $last_error = '';

	public string $last_query = '';

	/** @var array<int,mixed> */
	public array $prepare_args = array();

	public string $prepared_sql = '';

	public function prepare( string $sql, ...$args ): string {
		$this->prepared_sql = $sql;
		$this->prepare_args = $args;

		return $sql;
	}

	/** @return array<int,int> */
	public function get_col( string $sql ): array {
		$this->last_query = $sql;

		$uses_parent_identity = false !== strpos( $sql, 'customers.customer_id = parent_orders.customer_id' )
			&& false === strpos( $sql, 'customers.customer_id = products.customer_id' );

		return $uses_parent_identity ? array() : array( 10 );
	}
}

final class CustomerCollectionAuthoritativeLookupWpdb {
	public string $prefix = 'wp_';

	public bool $ready = true;

	public string $last_error = '';

	public string $last_query = '';

	public string $prepared_sql = '';

	public int $query_count = 0;

	/** @var array<int,mixed> */
	public array $prepare_args = array();

	private string $storage;

	/** @var array<int,array{type:string,parent_id:int}> */
	private array $live_orders;

	public function __construct( string $storage, bool $refund_is_live ) {
		$this->storage = $storage;
		$this->live_orders = array(
			501 => array(
				'type'      => 'shop_order',
				'parent_id' => 0,
			),
		);
		if ( $refund_is_live ) {
			$this->live_orders[601] = array(
				'type'      => 'shop_order_refund',
				'parent_id' => 501,
			);
		}
	}

	public function prepare( string $sql, ...$args ): string {
		$this->prepared_sql = $sql;
		$this->prepare_args = $args;

		return $sql;
	}

	/** @return array<int,int> */
	public function get_col( string $sql ): array {
		$this->last_query = $sql;
		++$this->query_count;

		$has_authoritative_joins = 'hpos' === $this->storage
			? $this->has_hpos_joins( $sql )
			: $this->has_cpt_joins( $sql );
		$analytics_rows = array(
			array(
				'product_id' => 10,
				'order_id'   => 501,
				'parent_id'  => 0,
				'quantity'   => 1,
			),
			array(
				'product_id' => 10,
				'order_id'   => 601,
				'parent_id'  => 501,
				'quantity'   => -1,
			),
		);
		$net_quantities = array();

		foreach ( $analytics_rows as $row ) {
			if ( $has_authoritative_joins && ! $this->row_has_live_authority( $row ) ) {
				continue;
			}
			$product_id = $row['product_id'];
			$net_quantities[ $product_id ] = ( $net_quantities[ $product_id ] ?? 0 ) + $row['quantity'];
		}

		return array_values(
			array_keys(
				array_filter(
					$net_quantities,
					static fn ( int $quantity ): bool => $quantity > 0
				)
			)
		);
	}

	private function has_hpos_joins( string $sql ): bool {
		return false !== strpos( $sql, 'INNER JOIN wp_wc_orders live_activity' )
			&& false !== strpos( $sql, 'live_activity.id = activity.order_id' )
			&& false !== strpos( $sql, "live_activity.type IN ('shop_order', 'shop_order_refund')" )
			&& false !== strpos( $sql, 'INNER JOIN wp_wc_orders live_parent' )
			&& false !== strpos( $sql, 'live_activity.parent_order_id' )
			&& false !== strpos( $sql, "live_parent.type = 'shop_order'" );
	}

	private function has_cpt_joins( string $sql ): bool {
		return false !== strpos( $sql, 'INNER JOIN wp_posts live_activity' )
			&& false !== strpos( $sql, 'live_activity.ID = activity.order_id' )
			&& false !== strpos( $sql, "live_activity.post_type IN ('shop_order', 'shop_order_refund')" )
			&& false !== strpos( $sql, 'INNER JOIN wp_posts live_parent' )
			&& false !== strpos( $sql, 'live_activity.post_parent' )
			&& false !== strpos( $sql, "live_parent.post_type = 'shop_order'" );
	}

	/** @param array{product_id:int,order_id:int,parent_id:int,quantity:int} $row */
	private function row_has_live_authority( array $row ): bool {
		$activity = $this->live_orders[ $row['order_id'] ] ?? null;
		if ( null === $activity || ! in_array( $activity['type'], array( 'shop_order', 'shop_order_refund' ), true ) ) {
			return false;
		}

		$parent_id = $activity['parent_id'] > 0 ? $activity['parent_id'] : $row['order_id'];
		$analytics_parent_id = $row['parent_id'] > 0 ? $row['parent_id'] : $row['order_id'];
		$parent = $this->live_orders[ $parent_id ] ?? null;

		return $parent_id === $analytics_parent_id
			&& null !== $parent
			&& 'shop_order' === $parent['type'];
	}
}

final class CustomerCollectionTest extends TestCase {
	private int $uuid_counter = 0;

	private bool $had_wpdb = false;

	/** @var mixed */
	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->uuid_counter = 0;
		Functions\when( 'wp_generate_uuid4' )->alias(
			function (): string {
				return 'test-token-' . ++$this->uuid_counter;
			}
		);
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Registration covers status, product-line CRUD and product refunds with canonical Woo hook arity. */
	public function test_registers_all_collection_invalidation_boundaries(): void {
		$actions = array();
		$filters = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$actions ): void {
				$actions[ $hook ] = array( $callback, $priority, $accepted_args );
			}
		);
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$filters ): void {
				$filters[ $hook ] = array( $callback, $priority, $accepted_args );
			}
		);

		$collection = new CustomerCollection();
		$collection->register();

		self::assertSame( array( array( $collection, 'flush_for_order' ), 10, 1 ), $actions['woocommerce_order_status_changed'] );
		self::assertSame( array( array( $collection, 'capture_order_customer_before_save' ), 999, 1 ), $actions['woocommerce_before_order_object_save'] );
		self::assertSame( array( array( $collection, 'handle_order_customer_updated' ), 20, 2 ), $actions['woocommerce_update_order'] );
		self::assertSame( array( array( $collection, 'handle_order_deleted' ), 5, 2 ), $actions['woocommerce_before_delete_order'] );
		self::assertSame( array( array( $collection, 'handle_order_delete_completed' ), 10, 1 ), $actions['woocommerce_delete_order'] );
		self::assertSame( array( array( $collection, 'handle_refund_before_delete' ), 10, 3 ), $filters['woocommerce_pre_delete_order_refund'] );
		self::assertSame( array( array( $collection, 'handle_refund_delete_completed' ), 10, 1 ), $actions['woocommerce_delete_order_refund'] );
		self::assertSame( array( array( $collection, 'handle_refund_deleted' ), 10, 2 ), $actions['woocommerce_refund_deleted'] );
		self::assertSame( array( array( $collection, 'capture_order_item_before_save' ), 10, 2 ), $actions['woocommerce_before_order_item_object_save'] );
		self::assertSame( array( array( $collection, 'handle_order_item_change' ), 10, 3 ), $actions['woocommerce_new_order_item'] );
		self::assertSame( array( array( $collection, 'handle_order_item_change' ), 10, 3 ), $actions['woocommerce_update_order_item'] );
		self::assertSame( array( array( $collection, 'handle_order_item_change' ), 10, 1 ), $actions['woocommerce_before_delete_order_item'] );
		self::assertSame( array( array( $collection, 'capture_bulk_product_removal' ), 10, 2 ), $actions['woocommerce_remove_order_items'] );
		self::assertSame( array( array( $collection, 'handle_bulk_product_removal' ), 10, 2 ), $actions['woocommerce_removed_order_items'] );
		self::assertSame( array( array( $collection, 'handle_order_refunded' ), 10, 2 ), $actions['woocommerce_order_refunded'] );
		self::assertSame( array( array( $collection, 'handle_analytics_import' ), 10, 1 ), $actions['woocommerce_order_scheduler_after_import_order'] );
		self::assertSame( array( array( $collection, 'handle_owner_fence_cleanup' ), 10, 1 ), $actions['akibara_business_cleanup_collection_owner_fence'] );
		self::assertSame( array( array( $collection, 'handle_shutdown' ), PHP_INT_MAX, 0 ), $actions['shutdown'] );
	}

	/** @testdox Product-line edits invalidate the item's owner, never the reported or current foreign order. */
	public function test_product_item_change_uses_the_items_canonical_order_owner(): void {
		$generations = array( 42 => 0, 77 => 9 );
		$owner_order = new CustomerCollectionOrder();
		$owner_order->user_id = 42;
		$foreign_order = new CustomerCollectionOrder();
		$foreign_order->user_id = 77;
		$orders = array( 501 => $owner_order, 777 => $foreign_order );
		$item = new CustomerCollectionLineItem();
		$item->order_id = 501;

		Functions\when( 'wc_get_order' )->alias( static fn( int $id ) => $orders[ $id ] ?? null );
		Functions\when( 'get_user_meta' )->alias(
			static fn( int $user_id, string $key, bool $single = true ): int => $generations[ $user_id ] ?? 0
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$generations ): int {
				self::assertSame( '_akb_byp_owned_generation', $key );
				$generations[ $user_id ] = $value;
				return 1;
			}
		);

		( new CustomerCollection() )->handle_order_item_change( 91, $item, 777 );

		self::assertSame( 'test-token-1', $generations[42] );
		self::assertSame( 9, $generations[77], 'El order ID informativo nunca debe invalidar un pedido ajeno.' );
	}

	/** @testdox Direct CRUD on a refund product item resolves and invalidates the parent order owner. */
	public function test_refund_item_change_resolves_the_parent_order_owner(): void {
		$generation = 0;
		$parent = new CustomerCollectionOrder();
		$parent->user_id = 42;
		$refund = new CustomerCollectionRefund();
		$refund->parent_id = 501;
		$item = new CustomerCollectionLineItem();
		$item->order_id = 601;
		$orders = array( 501 => $parent, 601 => $refund );

		Functions\when( 'wc_get_order' )->alias( static fn( int $id ) => $orders[ $id ] ?? null );
		Functions\when( 'get_user_meta' )->alias(
			static function () use ( &$generation ): int {
				return $generation;
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$generation ): int {
				self::assertSame( 42, $user_id );
				self::assertSame( '_akb_byp_owned_generation', $key );
				$generation = $value;
				return 1;
			}
		);

		( new CustomerCollection() )->handle_order_item_change( 91, $item, 601 );

		self::assertSame( 'test-token-1', $generation );
	}

	/** @testdox Reparenting a product line invalidates both its persisted and new order owners. */
	public function test_product_item_reparent_invalidates_old_and_new_orders(): void {
		$writes = array();
		$old_order = new CustomerCollectionOrder();
		$old_order->user_id = 42;
		$new_order = new CustomerCollectionOrder();
		$new_order->user_id = 77;
		$orders = array( 501 => $old_order, 777 => $new_order );
		$item = new CustomerCollectionLineItem();
		$item->akb_id = 91;
		$item->persisted_order_id = 501;
		$item->order_id = 777;

		Functions\when( 'wc_get_order' )->alias( static fn( int $id ) => $orders[ $id ] ?? null );
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$writes ): int {
				self::assertSame( '_akb_byp_owned_generation', $key );
				$writes[] = array( $user_id, $value );
				return 1;
			}
		);

		$collection = new CustomerCollection();
		$collection->capture_order_item_before_save( $item );
		$collection->handle_order_item_change( 91, $item, 777 );

		self::assertSame(
			array( array( 42, 'test-token-1' ), array( 77, 'test-token-2' ) ),
			$writes
		);
	}

	/**
	 * @testdox Before-delete resolves the still-existing product item by ID and ignores non-product items.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_before_delete_resolves_product_item_and_ignores_non_product_items(): void {
		if ( ! class_exists( '\\WC_Order_Factory' ) ) {
			eval(
				'namespace { class WC_Order_Factory {
					public static function get_order_item( $item_id ) {
						return $GLOBALS["akb_customer_collection_items"][ (int) $item_id ] ?? false;
					}
				} }'
			);
		}

		$generation = 0;
		$order = new CustomerCollectionOrder();
		$order->user_id = 42;
		$product_item = new CustomerCollectionLineItem();
		$product_item->order_id = 501;
		$GLOBALS['akb_customer_collection_items'] = array(
			91 => $product_item,
			92 => (object) array( 'type' => 'shipping' ),
		);

		Functions\when( 'wc_get_order' )->alias( static fn( int $id ) => 501 === $id ? $order : null );
		Functions\when( 'get_user_meta' )->alias( static fn() => $generation );
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$generation ): int {
				self::assertSame( 42, $user_id );
				self::assertSame( '_akb_byp_owned_generation', $key );
				$generation = $value;
				return 1;
			}
		);

		$collection = new CustomerCollection();
		$collection->handle_order_item_change( 91 );
		$collection->handle_order_item_change( 92 );

		self::assertSame( 'test-token-1', $generation, 'La línea no-product no debe ejecutar un segundo bump.' );
		unset( $GLOBALS['akb_customer_collection_items'] );
	}

	/** @testdox A partial product refund invalidates its owner without trusting a foreign parent or shipping-only refund. */
	public function test_product_refund_invalidates_only_the_matching_order_owner(): void {
		$generations = array( 42 => 2, 77 => 8 );
		$owner_order = new CustomerCollectionOrder();
		$owner_order->user_id = 42;
		$foreign_order = new CustomerCollectionOrder();
		$foreign_order->user_id = 77;

		$product_refund = new CustomerCollectionRefund();
		$product_refund->parent_id = 501;
		$product_refund->items = array( new \WC_Order_Item_Product() );
		$shipping_refund = new CustomerCollectionRefund();
		$shipping_refund->parent_id = 501;
		$shipping_refund->items = array( (object) array( 'type' => 'shipping' ) );
		$orders = array(
			501 => $owner_order,
			777 => $foreign_order,
			601 => $product_refund,
			602 => $shipping_refund,
		);

		Functions\when( 'wc_get_order' )->alias( static fn( int $id ) => $orders[ $id ] ?? null );
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id ) use ( &$generations ): int {
				return $generations[ $user_id ] ?? 0;
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$generations ): int {
				self::assertSame( '_akb_byp_owned_generation', $key );
				$generations[ $user_id ] = $value;
				return 1;
			}
		);

		$collection = new CustomerCollection();
		$collection->handle_order_refunded( 501, 601 );
		$collection->handle_order_refunded( 777, 601 );
		$collection->handle_order_refunded( 501, 602 );

		self::assertSame( 'test-token-1', $generations[42] );
		self::assertSame( 8, $generations[77] );
	}

	/** @testdox A line-less full refund invalidates ownership, while a line-less partial or shipping refund does not. */
	public function test_full_refund_without_line_items_still_invalidates_products(): void {
		$generation = 0;
		$order = new CustomerCollectionOrder();
		$order->user_id = 42;
		$full_refund = new CustomerCollectionRefund();
		$full_refund->parent_id = 501;
		$full_refund->refund_type = 'full';
		$partial_refund = new CustomerCollectionRefund();
		$partial_refund->parent_id = 501;
		$orders = array( 501 => $order, 601 => $full_refund, 602 => $partial_refund );

		Functions\when( 'wc_get_order' )->alias( static fn( int $id ) => $orders[ $id ] ?? null );
		Functions\when( 'get_user_meta' )->alias(
			static function () use ( &$generation ): int {
				return $generation;
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$generation ): int {
				self::assertSame( 42, $user_id );
				self::assertSame( '_akb_byp_owned_generation', $key );
				$generation = $value;
				return 1;
			}
		);

		$collection = new CustomerCollection();
		$collection->handle_order_refunded( 501, 601 );
		$collection->handle_order_refunded( 501, 602 );

		self::assertSame( 'test-token-1', $generation );
	}

	/** @testdox Reassigning a paid order invalidates old and new owners, and direct deletion invalidates the current owner. */
	public function test_customer_reassignment_and_order_deletion_invalidate_all_affected_owners(): void {
		$generations = array( 42 => 3, 77 => 8 );
		$order = new CustomerCollectionOrder();
		$order->akb_id = 501;
		$order->persisted_user_id = 42;
		$order->user_id = 77;

		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id ) use ( &$generations ): int {
				return $generations[ $user_id ] ?? 0;
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$generations ): int {
				self::assertSame( '_akb_byp_owned_generation', $key );
				$generations[ $user_id ] = $value;
				return 1;
			}
		);

		$collection = new CustomerCollection();
		$collection->capture_order_customer_before_save( $order );
		$collection->handle_order_customer_updated( 501, $order );
		$collection->handle_order_deleted( 501, $order );

		self::assertSame( 'test-token-2', $generations[42] );
		self::assertSame( 'test-token-5', $generations[77] );
	}

	/** @testdox The append-only fence exists before synchronous Analytics and rotates old and current owners across requests. */
	public function test_customer_reassignment_fence_precedes_synchronous_analytics_import(): void {
		$options   = array();
		$scheduled = array();
		$deleted   = array();
		$autoloads = array();
		$writes    = array();
		$wpdb      = $this->configure_owner_fence_ledger( $options, $scheduled, $deleted, $autoloads );
		$order     = new CustomerCollectionOrder();
		$order->akb_id = 501;
		$order->persisted_user_id = 42;
		$order->user_id = 77;

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$writes ): int {
				self::assertSame( '_akb_byp_owned_generation', $key );
				$writes[] = array( $user_id, $value );
				return 1;
			}
		);

		$started = time();
		$save_request = new CustomerCollection();
		$save_request->capture_order_customer_before_save( $order );
		$fence_name = (string) array_key_first( $options );
		$payload    = $options[ $fence_name ];

		self::assertMatchesRegularExpression( '/^akb_byp_owner_fence_501_[a-f0-9]{32}$/', $fence_name );
		self::assertSame( array( 'version', 'order_id', 'user_id', 'expires_at', 'purge_at' ), array_keys( $payload ) );
		self::assertSame( array( 1, 501, 42 ), array_slice( array_values( $payload ), 0, 3 ) );
		self::assertGreaterThanOrEqual( $started + ( 2 * DAY_IN_SECONDS ), $payload['expires_at'] );
		self::assertLessThanOrEqual( time() + ( 2 * DAY_IN_SECONDS ), $payload['expires_at'] );
		self::assertSame( false, $autoloads[ $fence_name ] );
		self::assertSame( 'akibara_business_cleanup_collection_owner_fence', $scheduled[0][1] );
		self::assertSame( array( $fence_name ), $scheduled[0][2] );
		self::assertTrue( $scheduled[0][3] );

		( new CustomerCollection() )->handle_analytics_import( 501 );
		$save_request->handle_order_customer_updated( 501, $order );

		self::assertSame( array( 42, 77, 42, 77 ), array_column( $writes, 0 ) );
		self::assertArrayNotHasKey( $fence_name, $options );
		self::assertContains( $fence_name, $deleted );
		self::assertStringContainsString( 'ORDER BY option_id ASC LIMIT %d', $wpdb->last_sql );
		self::assertSame( array( 'wp_options', 'akb_byp_owner_fence_501_%', 64 ), $wpdb->prepare_args );
	}

	/** @testdox Multiple reassignments append independent owner rows and Analytics rotates A, B and current C. */
	public function test_multiple_customer_reassignments_preserve_every_previous_owner(): void {
		$options   = array();
		$scheduled = array();
		$deleted   = array();
		$autoloads = array();
		$writes    = array();
		$this->configure_owner_fence_ledger( $options, $scheduled, $deleted, $autoloads );
		$order = new CustomerCollectionOrder();
		$order->akb_id = 501;

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id ) use ( &$writes ): int {
				$writes[] = $user_id;
				return 1;
			}
		);

		$order->persisted_user_id = 42;
		$order->user_id = 77;
		$first = new CustomerCollection();
		$first->capture_order_customer_before_save( $order );
		$first->handle_order_customer_updated( 501, $order );

		$order->persisted_user_id = 77;
		$order->user_id = 99;
		$second = new CustomerCollection();
		$second->capture_order_customer_before_save( $order );
		$second->handle_order_customer_updated( 501, $order );
		( new CustomerCollection() )->handle_analytics_import( 501 );

		self::assertSame( array( 42, 77, 77, 99, 42, 77, 99 ), $writes );
		self::assertSame( array(), $options );
		self::assertCount( 2, $deleted );
	}

	/** @testdox Hard deletion consumes the former owner after Woo lookups and clears the request-local mapping. */
	public function test_order_deletion_consumes_fence_on_real_post_delete_hook(): void {
		$options   = array();
		$scheduled = array();
		$deleted   = array();
		$autoloads = array();
		$writes    = array();
		$this->configure_owner_fence_ledger( $options, $scheduled, $deleted, $autoloads );
		$order = new CustomerCollectionOrder();
		$order->akb_id = 501;
		$order->user_id = 42;

		Functions\when( 'wc_get_order' )->justReturn( null );
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$writes ): int {
				$writes[] = array( $user_id, $value );
				return 1;
			}
		);

		$collection = new CustomerCollection();
		$collection->handle_order_deleted( 501, $order );
		$collection->handle_order_delete_completed( 501 );
		$collection->handle_order_delete_completed( 501 );

		self::assertSame( array( 42, 42 ), array_column( $writes, 0 ) );
		self::assertSame( array(), $options );
		self::assertCount( 1, $deleted );
	}

	/** @testdox A failed post-Analytics generation rotation keeps every durable row for retry. */
	public function test_analytics_failure_keeps_owner_fence(): void {
		$options   = array();
		$scheduled = array();
		$deleted   = array();
		$autoloads = array();
		$wpdb      = $this->configure_owner_fence_ledger( $options, $scheduled, $deleted, $autoloads );
		$fence_name = 'akb_byp_owner_fence_501_' . str_repeat( 'a', 32 );
		$options[ $fence_name ] = $this->owner_fence_payload( 501, 42 );
		$wpdb->option_names[] = $fence_name;
		$order = new CustomerCollectionOrder();
		$order->user_id = 77;

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'update_user_meta' )->alias(
			static fn( int $user_id ): bool => 42 !== $user_id
		);

		( new CustomerCollection() )->handle_analytics_import( 501 );

		self::assertSame( array(), $deleted );
		self::assertArrayHasKey( $fence_name, $options );
	}

	/** @testdox A concurrent append uses another option and survives deletion of the consumer snapshot. */
	public function test_analytics_import_preserves_a_concurrently_appended_fence(): void {
		$options   = array();
		$scheduled = array();
		$deleted   = array();
		$autoloads = array();
		$wpdb      = $this->configure_owner_fence_ledger( $options, $scheduled, $deleted, $autoloads );
		$old_name  = 'akb_byp_owner_fence_501_' . str_repeat( 'a', 32 );
		$new_name  = 'akb_byp_owner_fence_501_' . str_repeat( 'b', 32 );
		$options[ $old_name ] = $this->owner_fence_payload( 501, 42 );
		$wpdb->option_names[] = $old_name;
		$order = new CustomerCollectionOrder();
		$order->user_id = 77;
		$bumps = 0;

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'update_user_meta' )->alias(
			static function () use ( &$bumps, &$options, $wpdb, $new_name ): int {
				if ( 0 === $bumps++ ) {
					$options[ $new_name ] = array(
						'version'    => 1,
						'order_id'   => 501,
						'user_id'    => 77,
						'expires_at' => time() + DAY_IN_SECONDS,
						'purge_at'   => time() + ( 7 * DAY_IN_SECONDS ),
					);
					$wpdb->option_names[] = $new_name;
				}
				return 1;
			}
		);

		( new CustomerCollection() )->handle_analytics_import( 501 );

		self::assertArrayNotHasKey( $old_name, $options );
		self::assertArrayHasKey( $new_name, $options );
		self::assertSame( array( $old_name ), $deleted );
	}

	/** @testdox Soft-expiry cleanup bumps old and current owners but retains the row until import or hard cap. */
	public function test_soft_expiry_cleanup_revalidates_and_retains_fence(): void {
		$options   = array();
		$scheduled = array();
		$deleted   = array();
		$autoloads = array();
		$this->configure_owner_fence_ledger( $options, $scheduled, $deleted, $autoloads );
		$fence_name = 'akb_byp_owner_fence_501_' . str_repeat( 'c', 32 );
		$options[ $fence_name ] = array_merge(
			$this->owner_fence_payload( 501, 42 ),
			array( 'expires_at' => time() - 1 )
		);
		$order = new CustomerCollectionOrder();
		$order->user_id = 77;
		$writes = array();

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id ) use ( &$writes ): int {
				$writes[] = $user_id;
				return 1;
			}
		);

		( new CustomerCollection() )->handle_owner_fence_cleanup( $fence_name );

		self::assertSame( array( 42, 77 ), $writes );
		self::assertArrayHasKey( $fence_name, $options );
		self::assertSame( array(), $deleted );
		self::assertSame( 'akibara_business_cleanup_collection_owner_fence', $scheduled[0][1] );
		self::assertTrue( $scheduled[0][3] );
		self::assertGreaterThanOrEqual( time() + ( 12 * HOUR_IN_SECONDS ) - 1, $scheduled[0][0] );
	}

	/**
	 * @testdox Soft cleanup can schedule its successor while the current Action Scheduler job is running.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_soft_cleanup_reschedules_non_unique_action_scheduler_job(): void {
		if ( ! function_exists( '\\as_schedule_single_action' ) ) {
			eval(
				'namespace { function as_schedule_single_action( $timestamp, $hook, $args, $group, $unique ) {
					$GLOBALS["akb_owner_fence_as_calls"][] = array( $timestamp, $hook, $args, $group, $unique );
					return 123;
				} }'
			);
		}

		$options   = array();
		$scheduled = array();
		$deleted   = array();
		$autoloads = array();
		$this->configure_owner_fence_ledger( $options, $scheduled, $deleted, $autoloads );
		$fence_name = 'akb_byp_owner_fence_501_' . str_repeat( 'e', 32 );
		$options[ $fence_name ] = array_merge(
			$this->owner_fence_payload( 501, 42 ),
			array( 'expires_at' => time() - 1 )
		);
		$GLOBALS['akb_owner_fence_as_calls'] = array();

		Functions\when( 'wc_get_order' )->justReturn( null );
		Functions\when( 'update_user_meta' )->justReturn( 1 );
		( new CustomerCollection() )->handle_owner_fence_cleanup( $fence_name );

		self::assertCount( 1, $GLOBALS['akb_owner_fence_as_calls'] );
		self::assertSame( 'akibara_business_cleanup_collection_owner_fence', $GLOBALS['akb_owner_fence_as_calls'][0][1] );
		self::assertSame( 'akibara-business', $GLOBALS['akb_owner_fence_as_calls'][0][3] );
		self::assertFalse( $GLOBALS['akb_owner_fence_as_calls'][0][4] );
		self::assertSame( array(), $scheduled, 'Action Scheduler exitoso no debe duplicarse en WP-Cron.' );
		unset( $GLOBALS['akb_owner_fence_as_calls'] );
	}

	/** @testdox Hard-cap cleanup attempts one last bump and deletes the row even if storage invalidation fails. */
	public function test_hard_cap_cleanup_bounds_owner_fence_retention(): void {
		$options   = array();
		$scheduled = array();
		$deleted   = array();
		$autoloads = array();
		$this->configure_owner_fence_ledger( $options, $scheduled, $deleted, $autoloads );
		$fence_name = 'akb_byp_owner_fence_501_' . str_repeat( 'd', 32 );
		$options[ $fence_name ] = array_merge(
			$this->owner_fence_payload( 501, 42 ),
			array(
				'expires_at' => time() - DAY_IN_SECONDS,
				'purge_at'   => time() - 1,
			)
		);
		$attempts = 0;

		Functions\when( 'wc_get_order' )->justReturn( null );
		Functions\when( 'update_user_meta' )->alias(
			static function () use ( &$attempts ): bool {
				++$attempts;
				return false;
			}
		);

		( new CustomerCollection() )->handle_owner_fence_cleanup( $fence_name );

		self::assertSame( 1, $attempts );
		self::assertArrayNotHasKey( $fence_name, $options );
		self::assertSame( array( $fence_name ), $deleted );
	}

	/** @testdox A hard-cap option deletion failure remains durable and schedules another bounded cleanup attempt. */
	public function test_hard_cap_delete_failure_schedules_retry(): void {
		$options   = array();
		$scheduled = array();
		$deleted   = array();
		$autoloads = array();
		$this->configure_owner_fence_ledger( $options, $scheduled, $deleted, $autoloads );
		$fence_name = 'akb_byp_owner_fence_501_' . str_repeat( 'f', 32 );
		$options[ $fence_name ] = array_merge(
			$this->owner_fence_payload( 501, 42 ),
			array(
				'expires_at' => time() - DAY_IN_SECONDS,
				'purge_at'   => time() - 1,
			)
		);

		Functions\when( 'wc_get_order' )->justReturn( null );
		Functions\when( 'update_user_meta' )->justReturn( false );
		Functions\when( 'delete_option' )->justReturn( false );
		( new CustomerCollection() )->handle_owner_fence_cleanup( $fence_name );

		self::assertArrayHasKey( $fence_name, $options );
		self::assertSame( array(), $deleted );
		self::assertCount( 1, $scheduled );
		self::assertSame( array( $fence_name ), $scheduled[0][2] );
		self::assertTrue( $scheduled[0][3] );
	}

	/** @testdox A ledger write exception never breaks the order save and immediate rotations still execute. */
	public function test_owner_fence_storage_failure_degrades_safely(): void {
		$order = new CustomerCollectionOrder();
		$order->akb_id = 501;
		$order->persisted_user_id = 42;
		$order->user_id = 77;
		$writes = array();

		Functions\when( 'add_option' )->alias(
			static function (): void {
				throw new \RuntimeException( 'options unavailable' );
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id ) use ( &$writes ): int {
				$writes[] = $user_id;
				return 1;
			}
		);

		$collection = new CustomerCollection();
		$collection->capture_order_customer_before_save( $order );
		$collection->handle_order_customer_updated( 501, $order );

		self::assertSame( array( 42, 77 ), $writes );
	}

	/** @testdox Refund deletion invalidates before and after the real data-store delete, clears its mapping, and keeps the AJAX boundary. */
	public function test_refund_deletion_resolves_the_parent_owner(): void {
		$writes = array();
		$order = new CustomerCollectionOrder();
		$order->user_id = 42;
		$refund = new CustomerCollectionRefund();
		$refund->id = 601;
		$refund->parent_id = 501;
		$refund->items = array( new \WC_Order_Item_Product() );
		$orders = array( 501 => $order );

		Functions\when( 'wc_get_order' )->alias( static fn( int $id ) => $orders[ $id ] ?? null );
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$writes ): int {
				self::assertSame( '_akb_byp_owned_generation', $key );
				$writes[] = array( $user_id, $value );
				return 1;
			}
		);

		$collection = new CustomerCollection();
		self::assertNull( $collection->handle_refund_before_delete( null, $refund, true ) );
		$collection->handle_refund_delete_completed( 601 );
		$collection->handle_refund_delete_completed( 601 );
		$collection->handle_refund_deleted( 601, 501 );
		$collection->handle_shutdown();

		self::assertSame(
			array(
				array( 42, 'test-token-1' ),
				array( 42, 'test-token-2' ),
				array( 42, 'test-token-3' ),
			),
			$writes
		);
	}

	/** @testdox A programmatic refund delete without post-delete hooks gets one idempotent shutdown invalidation. */
	public function test_refund_delete_without_post_delete_hook_reinvalidates_at_shutdown(): void {
		$writes = array();
		$order = new CustomerCollectionOrder();
		$order->user_id = 42;
		$refund = new CustomerCollectionRefund();
		$refund->id = 601;
		$refund->parent_id = 501;
		$refund->items = array( new \WC_Order_Item_Product() );

		Functions\when( 'wc_get_order' )->alias( static fn( int $id ) => 501 === $id ? $order : null );
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$writes ): int {
				self::assertSame( 42, $user_id );
				self::assertSame( '_akb_byp_owned_generation', $key );
				$writes[] = $value;
				return 1;
			}
		);

		$collection = new CustomerCollection();
		self::assertNull( $collection->handle_refund_before_delete( null, $refund, true ) );
		$collection->handle_shutdown();
		$collection->handle_shutdown();

		self::assertSame( array( 'test-token-1', 'test-token-2' ), $writes );
	}

	/** @testdox A short-circuited refund delete neither invalidates nor leaves a post-delete mapping. */
	public function test_short_circuited_refund_delete_is_ignored(): void {
		$writes = 0;
		$order = new CustomerCollectionOrder();
		$refund = new CustomerCollectionRefund();
		$refund->id = 601;
		$refund->parent_id = 501;
		$refund->items = array( new \WC_Order_Item_Product() );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'update_user_meta' )->alias(
			static function () use ( &$writes ): int {
				++$writes;
				return 1;
			}
		);

		$collection = new CustomerCollection();
		$sentinel   = (object) array( 'blocked' => true );
		self::assertSame( $sentinel, $collection->handle_refund_before_delete( $sentinel, $refund, true ) );
		$collection->handle_refund_delete_completed( 601 );

		self::assertSame( 0, $writes );
	}

	/** @testdox Woo 11 bulk deletion covers line_item and null while ignoring shipping and an all-items order without products. */
	public function test_bulk_item_removal_invalidates_only_when_products_were_affected(): void {
		$generation = 0;
		$order = new CustomerCollectionOrder();
		$order->akb_id = 501;
		$order->user_id = 42;
		$order->akb_items = array( new \WC_Order_Item_Product() );

		Functions\when( 'get_user_meta' )->alias(
			static function () use ( &$generation ): int {
				return $generation;
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$generation ): int {
				self::assertSame( 42, $user_id );
				self::assertSame( '_akb_byp_owned_generation', $key );
				$generation = $value;
				return 1;
			}
		);

		$collection = new CustomerCollection();
		$collection->capture_bulk_product_removal( $order, null );
		$order->akb_items = array(); // Estado posterior observado por `woocommerce_removed_order_items`.
		$collection->handle_bulk_product_removal( $order, null );
		$collection->handle_bulk_product_removal( $order, 'shipping' );
		$collection->capture_bulk_product_removal( $order, null );
		$collection->handle_bulk_product_removal( $order, null );
		$collection->handle_bulk_product_removal( $order, 'line_item' );

		self::assertSame( 'test-token-2', $generation );
	}

	/** @testdox Bulk removal of refund product lines resolves the parent, while refund shipping removal is ignored. */
	public function test_bulk_refund_item_removal_invalidates_the_parent_owner(): void {
		$writes = array();
		$order = new CustomerCollectionOrder();
		$order->user_id = 42;
		$refund = new CustomerCollectionRefund();
		$refund->id = 601;
		$refund->parent_id = 501;
		$refund->items = array( new \WC_Order_Item_Product() );
		$orders = array( 501 => $order );

		Functions\when( 'wc_get_order' )->alias( static fn( int $id ) => $orders[ $id ] ?? null );
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$writes ): int {
				self::assertSame( '_akb_byp_owned_generation', $key );
				$writes[] = array( $user_id, $value );
				return 1;
			}
		);

		$collection = new CustomerCollection();
		$collection->capture_bulk_product_removal( $refund, null );
		$refund->items = array();
		$collection->handle_bulk_product_removal( $refund, null );
		$collection->handle_bulk_product_removal( $refund, 'shipping' );

		self::assertSame( array( array( 42, 'test-token-1' ) ), $writes );
	}

	/** @testdox Analytics completion bumps the canonical owner after lookup sync and ignores shipping-only refunds. */
	public function test_analytics_import_reinvalidates_after_lookup_sync(): void {
		$generation = 4;
		$order = new CustomerCollectionOrder();
		$order->user_id = 42;
		$product_refund = new CustomerCollectionRefund();
		$product_refund->parent_id = 501;
		$product_refund->items = array( new \WC_Order_Item_Product() );
		$shipping_refund = new CustomerCollectionRefund();
		$shipping_refund->parent_id = 501;
		$shipping_refund->items = array( (object) array( 'type' => 'shipping' ) );
		$orders = array( 501 => $order, 601 => $product_refund, 602 => $shipping_refund );

		Functions\when( 'wc_get_order' )->alias( static fn( int $id ) => $orders[ $id ] ?? null );
		Functions\when( 'get_user_meta' )->alias(
			static function () use ( &$generation ): int {
				return $generation;
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$generation ): int {
				self::assertSame( 42, $user_id );
				self::assertSame( '_akb_byp_owned_generation', $key );
				$generation = $value;
				return 1;
			}
		);

		$collection = new CustomerCollection();
		$collection->handle_analytics_import( 501 );
		$collection->handle_analytics_import( 601 );
		$collection->handle_analytics_import( 602 );

		self::assertSame( 'test-token-2', $generation );
	}

	/**
	 * @testdox Ownership nets product quantities across paid parent orders and refund rows.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_query_nets_refunded_product_quantities(): void {
		if ( ! class_exists( '\\wpdb' ) ) {
			eval(
				'namespace { class wpdb {
					public string $prefix = "wp_";
					public string $owned_sql = "";
					public function prepare( string $sql, ...$args ): string { unset( $args ); return $sql; }
					public function get_col( string $sql ): array { $this->owned_sql = $sql; return array( 10 ); }
				} }'
			);
		}

		$original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \wpdb();
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		try {
			self::assertSame(
				array(
					'status'      => CustomerCollection::RESULT_OK,
					'product_ids' => array( 10 ),
				),
				( new CustomerCollection() )->owned_product_ids_result( 42 )
			);
			self::assertStringContainsString( 'wc_customer_lookup customers', $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( 'customers.customer_id = parent_orders.customer_id', $GLOBALS['wpdb']->owned_sql );
			self::assertStringNotContainsString( 'customers.customer_id = products.customer_id', $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( 'customers.user_id = %d', $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( 'activity.parent_id', $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( 'parent_orders.status IN', $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( 'INNER JOIN wp_posts live_activity', $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( 'live_activity.ID = activity.order_id', $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( "live_activity.post_type IN ('shop_order', 'shop_order_refund')", $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( 'INNER JOIN wp_posts live_parent', $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( 'live_activity.post_parent', $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( "live_parent.post_type = 'shop_order'", $GLOBALS['wpdb']->owned_sql );
			self::assertStringNotContainsString( 'INNER JOIN wp_wc_orders live_activity', $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( 'GROUP BY products.product_id', $GLOBALS['wpdb']->owned_sql );
			self::assertStringContainsString( 'HAVING SUM(products.product_qty) > 0', $GLOBALS['wpdb']->owned_sql );
		} finally {
			if ( null === $original_wpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $original_wpdb;
			}
		}
	}

	/** @testdox A linked guest order nets child refund rows through the canonical parent customer. */
	public function test_query_derives_refund_ownership_from_the_parent_order_customer(): void {
		$database = new CustomerCollectionRelinkedRefundWpdb();
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$result = ( new CustomerCollection( $database ) )->owned_product_ids_result( 42 );

		self::assertSame( CustomerCollection::RESULT_EMPTY, $result['status'] );
		self::assertSame( array(), $result['product_ids'], 'El refund guest debe netear la compra ya vinculada al usuario.' );
		self::assertStringContainsString( 'customers.customer_id = parent_orders.customer_id', $database->prepared_sql );
		self::assertStringNotContainsString( 'customers.customer_id = products.customer_id', $database->prepared_sql );
	}

	/**
	 * @testdox CPT ignores an orphaned negative refund lookup row after the refund post is deleted.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cpt_query_excludes_deleted_refund_lookup_rows(): void {
		$live_refund_database = new CustomerCollectionAuthoritativeLookupWpdb( 'cpt', true );
		$deleted_refund_database = new CustomerCollectionAuthoritativeLookupWpdb( 'cpt', false );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$before_delete = ( new CustomerCollection( $live_refund_database ) )->owned_product_ids_result( 42 );
		$after_delete  = ( new CustomerCollection( $deleted_refund_database ) )->owned_product_ids_result( 42 );

		self::assertSame( CustomerCollection::RESULT_EMPTY, $before_delete['status'] );
		self::assertSame( array(), $before_delete['product_ids'] );
		self::assertSame( CustomerCollection::RESULT_OK, $after_delete['status'] );
		self::assertSame( array( 10 ), $after_delete['product_ids'] );
		self::assertSame( 1, $live_refund_database->query_count );
		self::assertSame( 1, $deleted_refund_database->query_count );
		self::assertStringContainsString( 'INNER JOIN wp_posts live_activity', $deleted_refund_database->prepared_sql );
		self::assertStringContainsString( 'INNER JOIN wp_posts live_parent', $deleted_refund_database->prepared_sql );
	}

	/**
	 * @testdox HPOS ignores an orphaned negative refund lookup row using the live orders table in one query.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_hpos_query_excludes_deleted_refund_lookup_rows(): void {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil', false ) ) {
			eval(
				'namespace Automattic\\WooCommerce\\Utilities {
					final class OrderUtil {
						public static function custom_orders_table_usage_is_enabled(): bool { return true; }
					}
				}'
			);
		}

		$live_refund_database = new CustomerCollectionAuthoritativeLookupWpdb( 'hpos', true );
		$deleted_refund_database = new CustomerCollectionAuthoritativeLookupWpdb( 'hpos', false );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$before_delete = ( new CustomerCollection( $live_refund_database ) )->owned_product_ids_result( 42 );
		$after_delete  = ( new CustomerCollection( $deleted_refund_database ) )->owned_product_ids_result( 42 );

		self::assertSame( CustomerCollection::RESULT_EMPTY, $before_delete['status'] );
		self::assertSame( array(), $before_delete['product_ids'] );
		self::assertSame( CustomerCollection::RESULT_OK, $after_delete['status'] );
		self::assertSame( array( 10 ), $after_delete['product_ids'] );
		self::assertSame( 1, $live_refund_database->query_count );
		self::assertSame( 1, $deleted_refund_database->query_count );
		self::assertStringContainsString( 'INNER JOIN wp_wc_orders live_activity', $deleted_refund_database->prepared_sql );
		self::assertStringContainsString( 'live_activity.id = activity.order_id', $deleted_refund_database->prepared_sql );
		self::assertStringContainsString( "live_activity.type IN ('shop_order', 'shop_order_refund')", $deleted_refund_database->prepared_sql );
		self::assertStringContainsString( 'INNER JOIN wp_wc_orders live_parent', $deleted_refund_database->prepared_sql );
		self::assertStringContainsString( 'live_activity.parent_order_id', $deleted_refund_database->prepared_sql );
		self::assertStringContainsString( "live_parent.type = 'shop_order'", $deleted_refund_database->prepared_sql );
		self::assertStringNotContainsString( 'INNER JOIN wp_posts live_activity', $deleted_refund_database->prepared_sql );
	}

	/** @testdox Generation invalidation bypasses stale persistent transients without direct SQL deletion. */
	public function test_generation_invalidation_works_with_external_object_cache(): void {
		$generation = 'old-token';
		$candidates = array( 10, 20 );
		$old_key    = 'akb_byp_owned_all_v3_42_g' . md5( 'old-token' );
		$new_key    = 'akb_byp_owned_all_v3_42_g' . md5( 'test-token-1' );
		$cache      = array(
			$old_key => array(
				'version'     => 3,
				'status'      => CustomerCollection::RESULT_OK,
				'product_ids' => array( 10 ),
			),
			$new_key => array(
				'version'     => 3,
				'status'      => CustomerCollection::RESULT_OK,
				'product_ids' => array( 20 ),
			),
		);
		$read_keys = array();

		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'get_user_meta' )->alias( static function () use ( &$generation ): string { return $generation; } );
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, string $value ) use ( &$generation ): int {
				self::assertSame( 42, $user_id );
				self::assertSame( '_akb_byp_owned_generation', $key );
				$generation = $value;
				return 1;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( &$read_keys, &$cache ) {
				$read_keys[] = $key;
				return $cache[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wc_get_order' )->justReturn( new CustomerCollectionOrder() );

		$collection = new CustomerCollection();
		self::assertTrue( wp_using_ext_object_cache() );
		self::assertSame( array( 10 ), $collection->owned_among( 42, $candidates ) );

		$collection->flush_for_order( 999 );

		self::assertSame( 'test-token-1', $generation );
		self::assertSame( array( 20 ), $collection->owned_among( 42, $candidates ) );
		self::assertSame( array( $old_key, $new_key ), $read_keys );
		self::assertSame( array( 10 ), $cache[ $old_key ]['product_ids'], 'El valor viejo puede seguir en Redis, pero ya no es direccionable.' );

		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/SeriesBundles/CustomerCollection.php' );
		self::assertStringNotContainsString( 'DELETE FROM', $source );
		self::assertStringNotContainsString( '_transient_timeout_', $source );
		self::assertStringContainsString( 'update_user_meta(', $source );
	}

	/**
	 * @testdox A lookup exception degrades to empty for this request without caching the false result.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_lookup_exception_is_not_cached_for_a_day(): void {
		if ( ! class_exists( '\wpdb' ) ) {
			eval(
				'namespace { class wpdb {
					public string $prefix = "wp_";
					public function prepare( string $sql, ...$args ): string { unset( $args ); return $sql; }
					public function get_col( string $sql ) { unset( $sql ); throw new \\RuntimeException( "db down" ); }
				} }'
			);
		}

		$original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \wpdb();
		$writes = 0;
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'set_transient' )->alias(
			static function () use ( &$writes ): bool {
				++$writes;
				return true;
			}
		);

		try {
			self::assertSame(
				array(
					'status'      => CustomerCollection::RESULT_UNAVAILABLE,
					'product_ids' => array(),
				),
				( new CustomerCollection() )->owned_product_ids_result( 42 )
			);
			self::assertSame( 0, $writes, 'Una excepción no debe fijar [] durante DAY_IN_SECONDS.' );
		} finally {
			if ( null === $original_wpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $original_wpdb;
			}
		}
	}

	/** @testdox Reliable global ownership normalizes IDs and caches the versioned result. */
	public function test_global_result_normalizes_and_caches_only_reliable_rows(): void {
		$database       = new CustomerCollectionLookupWpdb();
		$database->rows = array( '20', 10, '20' );
		$writes         = array();
		Functions\when( 'get_user_meta' )->justReturn( 'generation-a' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, array $value, int $ttl ) use ( &$writes ): bool {
				$writes[] = array( $key, $value, $ttl );
				return true;
			}
		);

		$result = ( new CustomerCollection( $database ) )->owned_product_ids_result( 42 );

		self::assertSame(
			array(
				'status'      => CustomerCollection::RESULT_OK,
				'product_ids' => array( 10, 20 ),
			),
			$result
		);
		self::assertSame( array( 42, 'wc-processing', 'wc-completed', 'wc-shipping-progress' ), $database->prepare_args );
		self::assertCount( 1, $writes );
		self::assertSame( 'akb_byp_owned_all_v3_42_g' . md5( 'generation-a' ), $writes[0][0] );
		self::assertSame(
			array(
				'version'     => 3,
				'status'      => CustomerCollection::RESULT_OK,
				'product_ids' => array( 10, 20 ),
			),
			$writes[0][1]
		);
		self::assertSame( DAY_IN_SECONDS, $writes[0][2] );
	}

	/** @testdox Non-positive legacy product IDs are quarantined while valid ownership remains available. */
	public function test_non_positive_product_ids_are_skipped_without_poisoning_valid_rows(): void {
		$database       = new CustomerCollectionLookupWpdb();
		$database->rows = array( 0, '0', -4, 33 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'set_transient' )->justReturn( true );

		$result = ( new CustomerCollection( $database ) )->owned_product_ids_result( 42 );

		self::assertSame( CustomerCollection::RESULT_OK, $result['status'] );
		self::assertSame( array( 33 ), $result['product_ids'] );
		self::assertStringContainsString( 'products.product_id > 0', $database->prepared_sql );
	}

	/** @testdox A reliable zero-row lookup is distinct from unavailable and may be cached. */
	public function test_reliable_empty_result_is_cacheable(): void {
		$database = new CustomerCollectionLookupWpdb();
		$writes   = array();
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, array $value ) use ( &$writes ): bool {
				unset( $key );
				$writes[] = $value;
				return true;
			}
		);

		$result = ( new CustomerCollection( $database ) )->owned_product_ids_result( 42 );

		self::assertSame(
			array(
				'status'      => CustomerCollection::RESULT_EMPTY,
				'product_ids' => array(),
			),
			$result
		);
		self::assertSame( CustomerCollection::RESULT_EMPTY, $writes[0]['status'] );
		self::assertSame( array(), $writes[0]['product_ids'] );
	}

	/** @testdox A silent wpdb error is unavailable and is never cached as an empty collection. */
	public function test_silent_database_error_is_unavailable_and_not_cached(): void {
		$database             = new CustomerCollectionLookupWpdb();
		$database->last_error = 'server has gone away';
		$writes               = 0;
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'set_transient' )->alias(
			static function () use ( &$writes ): bool {
				++$writes;
				return true;
			}
		);

		$result = ( new CustomerCollection( $database ) )->owned_product_ids_result( 42 );

		self::assertSame( CustomerCollection::RESULT_UNAVAILABLE, $result['status'] );
		self::assertSame( array(), $result['product_ids'] );
		self::assertSame( 0, $writes );
	}

	/** @testdox Malformed cache entries cannot masquerade as reliable empty ownership. */
	public function test_malformed_cached_result_is_ignored(): void {
		$database       = new CustomerCollectionLookupWpdb();
		$database->rows = array( 33 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn(
			array(
				'version'     => 3,
				'status'      => CustomerCollection::RESULT_EMPTY,
				'product_ids' => array( 10 ),
			)
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'set_transient' )->justReturn( true );

		$result = ( new CustomerCollection( $database ) )->owned_product_ids_result( 42 );

		self::assertSame( CustomerCollection::RESULT_OK, $result['status'] );
		self::assertSame( array( 33 ), $result['product_ids'] );
	}

	/**
	 * @param array<string,array<string,int>>              $options   Options persistidas.
	 * @param array<int,array{0:int,1:string,2:array<int,string>,3:bool}> $scheduled Eventos de cleanup.
	 * @param array<int,string>                            $deleted   Options eliminadas.
	 * @param array<string,mixed>                          $autoloads Flags de autoload.
	 */
	private function configure_owner_fence_ledger(
		array &$options,
		array &$scheduled,
		array &$deleted,
		array &$autoloads
	): CustomerCollectionOptionsWpdb {
		$wpdb = new CustomerCollectionOptionsWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'add_option' )->alias(
			static function ( string $name, array $value, string $deprecated, $autoload ) use ( &$options, &$autoloads, $wpdb ): bool {
				unset( $deprecated );
				if ( isset( $options[ $name ] ) ) {
					return false;
				}
				$options[ $name ]   = $value;
				$autoloads[ $name ] = $autoload;
				$wpdb->option_names[] = $name;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( &$options ) {
				return $options[ $name ] ?? $default;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) use ( &$options, &$deleted, $wpdb ): bool {
				$existed = isset( $options[ $name ] );
				unset( $options[ $name ] );
				$wpdb->option_names = array_values( array_diff( $wpdb->option_names, array( $name ) ) );
				if ( $existed ) {
					$deleted[] = $name;
				}
				return $existed;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( int $timestamp, string $hook, array $args, bool $wp_error ) use ( &$scheduled ): bool {
				$scheduled[] = array( $timestamp, $hook, $args, $wp_error );
				return true;
			}
		);

		return $wpdb;
	}

	/** @return array{version:int,order_id:int,user_id:int,expires_at:int,purge_at:int} */
	private function owner_fence_payload( int $order_id, int $user_id ): array {
		return array(
			'version'    => 1,
			'order_id'   => $order_id,
			'user_id'    => $user_id,
			'expires_at' => time() + ( 2 * DAY_IN_SECONDS ),
			'purge_at'   => time() + ( 7 * DAY_IN_SECONDS ),
		);
	}
}
