<?php
/**
 * Pruebas unitarias para BacsLifecycleManager.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BacsLifecycle;

use Akibara\Business\Modules\BacsLifecycle\BacsLifecycleManager;
use Akibara\Business\Support\BusinessOptions;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class BacsLifecycleManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_register_hooks(): void {
		Actions\expectAdded( 'woocommerce_order_status_on-hold' )->once();
		Actions\expectAdded( 'woocommerce_order_status_changed' )->once();
		Actions\expectAdded( BacsLifecycleManager::ACTION_REMINDER )->once();
		Actions\expectAdded( BacsLifecycleManager::ACTION_EXPIRE )->once();
		Actions\expectAdded( 'wp_ajax_akb_submit_bacs_receipt' )->once();
		Actions\expectAdded( 'wp_ajax_nopriv_akb_submit_bacs_receipt' )->once();
		Actions\expectAdded( 'woocommerce_thankyou_bacs' )->once();
		Actions\expectAdded( 'woocommerce_view_order' )->once();
		Actions\expectAdded( 'woocommerce_email_before_order_table' )->once();

		$manager = new BacsLifecycleManager();
		$manager->register();

		self::assertTrue( true );
	}

	public function test_get_bank_details_fallback_defaults(): void {
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( 'woocommerce_bacs_accounts' === $opt ) {
				return array();
			}
			if ( 'admin_email' === $opt ) {
				return 'contacto@akibara.cl';
			}
			return $default;
		} );

		$bank = BacsLifecycleManager::get_bank_details();

		self::assertSame( 'Banco de Chile', $bank['bank_name'] );
		self::assertSame( 'Cuenta Vista', $bank['account_type'] );
		self::assertSame( '39625300', $bank['account_number'] );
		self::assertSame( '78.274.225-6', $bank['rut'] );
		self::assertSame( 'Akibara SpA', $bank['holder'] );
		self::assertSame( 'contacto@akibara.cl', $bank['email'] );
	}

	public function test_get_bank_details_from_woocommerce_options(): void {
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( 'woocommerce_bacs_accounts' === $opt ) {
				return array(
					array(
						'bank_name'      => 'Banco Estado',
						'sort_code'      => 'Cuenta RUT',
						'account_number' => '12345678',
						'iban'           => '12.345.678-9',
						'account_name'   => 'Akibara SpA Oficial',
						'bic'            => 'pagos@akibara.cl',
					),
				);
			}
			return $default;
		} );

		$bank = BacsLifecycleManager::get_bank_details();

		self::assertSame( 'Banco Estado', $bank['bank_name'] );
		self::assertSame( 'Cuenta RUT', $bank['account_type'] );
		self::assertSame( '12345678', $bank['account_number'] );
		self::assertSame( '12.345.678-9', $bank['rut'] );
		self::assertSame( 'Akibara SpA Oficial', $bank['holder'] );
		self::assertSame( 'pagos@akibara.cl', $bank['email'] );
	}

	public function test_on_status_changed_unschedules_actions_when_leaving_on_hold(): void {
		$unscheduled = array();
		Functions\when( 'as_unschedule_all_actions' )->alias( function( $hook, $args, $group ) use ( &$unscheduled ) {
			$unscheduled[] = array(
				'hook'  => $hook,
				'args'  => $args,
				'group' => $group,
			);
		} );

		$manager = new BacsLifecycleManager();
		$manager->on_status_changed( 555, 'on-hold', 'processing' );

		self::assertCount( 2, $unscheduled );
		self::assertSame( BacsLifecycleManager::ACTION_REMINDER, $unscheduled[0]['hook'] );
		self::assertSame( 555, $unscheduled[0]['args']['order_id'] );
		self::assertSame( BacsLifecycleManager::GROUP, $unscheduled[0]['group'] );
		self::assertSame( BacsLifecycleManager::ACTION_EXPIRE, $unscheduled[1]['hook'] );
	}

	public function test_on_order_hold_schedules_actions_when_bacs(): void {
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( BusinessOptions::OPT_BACS_AUTO_CANCEL_ENABLED === $opt ) {
				return 'yes';
			}
			if ( BusinessOptions::OPT_BACS_REMINDER_HOURS === $opt ) {
				return 12;
			}
			if ( BusinessOptions::OPT_BACS_EXPIRY_HOURS === $opt ) {
				return 24;
			}
			return $default;
		} );

		$scheduled = array();
		Functions\when( 'as_schedule_single_action' )->alias( function( $timestamp, $hook, $args, $group ) use ( &$scheduled ) {
			$scheduled[] = array(
				'timestamp' => $timestamp,
				'hook'      => $hook,
				'args'      => $args,
				'group'     => $group,
			);
		} );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'bacs' );
		$order->shouldReceive( 'update_meta_data' )->with( BacsLifecycleManager::META_ON_HOLD_SINCE, Mockery::type( 'int' ) )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

		$manager = new BacsLifecycleManager();
		$manager->on_order_hold( 777, $order );

		self::assertCount( 2, $scheduled );
		self::assertSame( BacsLifecycleManager::ACTION_REMINDER, $scheduled[0]['hook'] );
		self::assertSame( 777, $scheduled[0]['args']['order_id'] );
		self::assertSame( BacsLifecycleManager::ACTION_EXPIRE, $scheduled[1]['hook'] );
	}

	public function test_send_reminder_skips_if_receipt_already_reported(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'bacs' );
		$order->shouldReceive( 'get_meta' )
			->with( BacsLifecycleManager::META_RECEIPT_REPORTED )
			->andReturn( time() - 100 );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$sent = false;
		Functions\when( 'wp_mail' )->alias( function() use ( &$sent ) {
			$sent = true;
			return true;
		} );

		$manager = new BacsLifecycleManager();
		$manager->send_reminder( 888 );

		self::assertFalse( $sent );
	}

	public function test_expire_order_respects_grace_period(): void {
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( BusinessOptions::OPT_BACS_RECEIPT_GRACE_HOURS === $opt ) {
				return 48;
			}
			return $default;
		} );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'bacs' );
		// Reportado hace 1 hora (dentro de las 48 horas de gracia)
		$order->shouldReceive( 'get_meta' )
			->with( BacsLifecycleManager::META_RECEIPT_REPORTED )
			->andReturn( time() - 3600 );
		$order->shouldNotReceive( 'update_status' );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$manager = new BacsLifecycleManager();
		$manager->expire_order( 999 );

		self::assertTrue( true );
	}

	public function test_expire_order_cancels_when_no_receipt(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'bacs' );
		$order->shouldReceive( 'get_meta' )
			->with( BacsLifecycleManager::META_RECEIPT_REPORTED )
			->andReturn( '' );
		$order->shouldReceive( 'update_status' )
			->once()
			->with( 'cancelled', Mockery::type( 'string' ) );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$manager = new BacsLifecycleManager();
		$manager->expire_order( 1000 );

		self::assertTrue( true );
	}
}
