<?php
/**
 * Pruebas unitarias para BacsLifecycleManager.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BacsLifecycle;

use Akibara\Business\Modules\BacsLifecycle\BacsLifecycleManager;
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
			if ( BacsLifecycleManager::OPT_AUTO_CANCEL_ENABLED === $opt ) {
				return 'yes';
			}
			if ( BacsLifecycleManager::OPT_REMINDER_HOURS === $opt ) {
				return 12;
			}
			if ( BacsLifecycleManager::OPT_EXPIRY_HOURS === $opt ) {
				return 24;
			}
			return $default;
		} );

		$unscheduled = array();
		Functions\when( 'as_unschedule_all_actions' )->alias( function( $hook, $args, $group ) use ( &$unscheduled ) {
			$unscheduled[] = array(
				'hook'  => $hook,
				'args'  => $args,
				'group' => $group,
			);
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
		$order->shouldReceive( 'update_meta_data' )->with(
			BacsLifecycleManager::META_ON_HOLD_SINCE,
			Mockery::on( static fn( $val ): bool => is_numeric( $val ) && (int) $val > 0 )
		)->once();
		$order->shouldReceive( 'save_meta_data' )->once();

		$manager = new BacsLifecycleManager();
		$manager->on_order_hold( 777, $order );

		self::assertCount( 2, $unscheduled );
		self::assertSame( BacsLifecycleManager::ACTION_REMINDER, $unscheduled[0]['hook'] );
		self::assertSame( BacsLifecycleManager::ACTION_EXPIRE, $unscheduled[1]['hook'] );
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
			if ( BacsLifecycleManager::OPT_RECEIPT_GRACE_HOURS === $opt ) {
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
		Functions\when( 'get_option' )->justReturn( 24 );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'bacs' );
		$order->shouldReceive( 'get_meta' )
			->with( BacsLifecycleManager::META_RECEIPT_REPORTED )
			->andReturn( '' );
		$order->shouldReceive( 'update_status' )
			->once()
			->with( 'cancelled', Mockery::on( static fn( string $note ): bool => str_contains( $note, '24h sin comprobante verificado' ) ) );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$manager = new BacsLifecycleManager();
		$manager->expire_order( 1000 );

		self::assertTrue( true );
	}

	/**
	 * @testdox [P0-2] Expira pedido cuya prórroga posterior al comprobante ya venció registrando nota de prórroga.
	 */
	public function test_expire_order_cancels_when_grace_period_elapsed(): void {
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( BacsLifecycleManager::OPT_RECEIPT_GRACE_HOURS === $opt ) {
				return 48;
			}
			return $default;
		} );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'bacs' );
		$order->shouldReceive( 'get_meta' )
			->with( BacsLifecycleManager::META_RECEIPT_REPORTED )
			->andReturn( time() - ( 49 * 3600 ) );
		$order->shouldReceive( 'update_status' )
			->once()
			->with( 'cancelled', Mockery::on( static fn( string $note ): bool => str_contains( $note, '+48h' ) ) );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		( new BacsLifecycleManager() )->expire_order( 1001 );

		self::assertTrue( true );
	}

	/**
	 * @testdox El portal BACS on-hold sin comprobante encola el guion externo, usa wc_price y no filtra anotaciones ni estilos/JS inline.
	 */
	public function test_render_bacs_portal_uses_external_script_storefront_classes_and_zero_leaks(): void {
		if ( ! defined( 'AKIBARA_BUSINESS_URL' ) ) {
			define( 'AKIBARA_BUSINESS_URL', 'https://akibara.cl/wp-content/plugins/akibara-business/' );
		}
		if ( ! defined( 'AKIBARA_BUSINESS_DIR' ) ) {
			define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );
		}

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 456 );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'bacs' );
		$order->shouldReceive( 'get_total' )->andReturn( 19990.0 );
		$order->shouldReceive( 'get_currency' )->andReturn( 'CLP' );
		$order->shouldReceive( 'get_order_key' )->andReturn( 'wc_order_test_key' );
		$order->shouldReceive( 'get_meta' )->with( BacsLifecycleManager::META_RECEIPT_REPORTED )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( BacsLifecycleManager::META_RECEIPT_REFERENCE )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( BacsLifecycleManager::META_ON_HOLD_SINCE )->andReturn( time() - 3600 );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_option' )->alias( function( $opt, $default = false ) {
			if ( 'woocommerce_bacs_accounts' === $opt ) {
				return array();
			}
			if ( 'admin_email' === $opt ) {
				return 'contacto@akibara.cl';
			}
			return $default;
		} );
		Functions\when( 'wc_price' )->alias( function( $price, $args = array() ) {
			return '<span class="woocommerce-Price-amount amount">' . esc_html( (string) $price ) . ' ' . esc_html( (string) ( $args['currency'] ?? '' ) ) . '</span>';
		} );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce_test_123' );
		Functions\when( 'admin_url' )->justReturn( 'https://akibara.cl/wp-admin/admin-ajax.php' );
		Functions\when( 'trailingslashit' )->alias( static fn( string $value ): string => rtrim( $value, '/' ) . '/' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();

		$enqueued  = array();
		$localized = array();
		Functions\when( 'wp_enqueue_script' )->alias( function( string $handle, string $src, array $deps, $ver, bool $in_footer ) use ( &$enqueued ) {
			$enqueued[] = compact( 'handle', 'src', 'deps', 'ver', 'in_footer' );
		} );
		Functions\when( 'wp_localize_script' )->alias( function( string $handle, string $object_name, array $data ) use ( &$localized ) {
			$localized[] = compact( 'handle', 'object_name', 'data' );
		} );

		ob_start();
		( new BacsLifecycleManager() )->render_bacs_portal( 456 );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="akb-bacs-portal"', $html );
		self::assertStringContainsString( 'woocommerce-Price-amount', $html );
		self::assertStringContainsString( '<label class="akb-bacs-portal__label" for="akb_bacs_ref_input">', $html );
		self::assertStringContainsString( 'class="button alt akb-bacs-portal__submit"', $html );
		self::assertStringContainsString( 'role="status" aria-live="polite" hidden', $html );

		self::assertStringNotContainsString( '@phpstan', $html );
		self::assertStringNotContainsString( '@psalm', $html );
		self::assertStringNotContainsString( 'phpcs:', $html );
		self::assertStringNotContainsString( 'button-primary', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( 'style=', $html );

		self::assertCount( 1, $enqueued );
		self::assertSame( 'akb-bacs-receipt', $enqueued[0]['handle'] );
		self::assertStringContainsString( 'assets/js/bacs-receipt.js', $enqueued[0]['src'] );
		self::assertTrue( $enqueued[0]['in_footer'] );

		self::assertCount( 1, $localized );
		self::assertSame( 'akbBacsReceiptConfig', $localized[0]['object_name'] );
		self::assertSame( 'https://akibara.cl/wp-admin/admin-ajax.php', $localized[0]['data']['ajaxUrl'] );
	}

	/**
	 * @testdox El portal BACS ya informado muestra el estado protegido y no encola ni renderiza formulario.
	 */
	public function test_render_bacs_portal_when_receipt_already_reported_skips_form_and_script(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 789 );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'bacs' );
		$order->shouldReceive( 'get_total' )->andReturn( 25000.0 );
		$order->shouldReceive( 'get_currency' )->andReturn( 'CLP' );
		$order->shouldReceive( 'get_meta' )->with( BacsLifecycleManager::META_RECEIPT_REPORTED )->andReturn( time() - 120 );
		$order->shouldReceive( 'get_meta' )->with( BacsLifecycleManager::META_RECEIPT_REFERENCE )->andReturn( 'OP-998877' );
		$order->shouldReceive( 'get_meta' )->with( BacsLifecycleManager::META_ON_HOLD_SINCE )->andReturn( time() - 3600 );

		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wc_price' )->justReturn( '<span>$25.000</span>' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce_reported' );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\expect( 'wp_enqueue_script' )->never();

		ob_start();
		( new BacsLifecycleManager() )->render_bacs_portal( 789 );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'akb-bacs-portal__status--reported', $html );
		self::assertStringContainsString( 'OP-998877', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	/**
	 * @testdox El endpoint AJAX de comprobante autoriza a invitados mediante order_key sin comprobaciones redundantes.
	 */
	public function test_ajax_submit_receipt_authorizes_guest_with_valid_order_key(): void {
		$_POST = array(
			'order_id'  => '901',
			'reference' => 'TR-554433',
			'order_key' => 'wc_order_valid_guest_key',
		);

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_customer_id' )->andReturn( 0 );
		$order->shouldReceive( 'get_order_key' )->andReturn( 'wc_order_valid_guest_key' );
		$order->shouldReceive( 'get_status' )->andReturn( 'on-hold' );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'bacs' );
		$order->shouldReceive( 'get_meta' )->with( BacsLifecycleManager::META_RECEIPT_REPORTED )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with(
			BacsLifecycleManager::META_RECEIPT_REPORTED,
			Mockery::on( static fn( $val ): bool => is_numeric( $val ) && (int) $val > 0 )
		)->once();
		$order->shouldReceive( 'update_meta_data' )->with( BacsLifecycleManager::META_RECEIPT_REFERENCE, 'TR-554433' )->once();
		$order->shouldReceive( 'add_order_note' )->once();
		$order->shouldReceive( 'save_meta_data' )->once();

		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 48 );
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );

		$sent = null;
		Functions\when( 'wp_send_json_success' )->alias( function( $data ) use ( &$sent ) {
			$sent = $data;
			throw new \RuntimeException( 'wp_send_json_success_invoked' );
		} );

		try {
			( new BacsLifecycleManager() )->ajax_submit_receipt();
			self::fail( 'Debió responder JSON de éxito' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'wp_send_json_success_invoked', $e->getMessage() );
		} finally {
			$_POST = array();
		}

		self::assertIsArray( $sent );
		self::assertStringContainsString( 'Comprobante informado', $sent['message'] );
	}

	/**
	 * @testdox [NEG] Rechaza envío AJAX con referencia vacía, ID inválido o pedido inexistente.
	 * @dataProvider provider_ajax_invalid_requests
	 *
	 * @param array<string, string> $post_data
	 */
	public function test_ajax_submit_receipt_rejects_invalid_input_or_missing_order(
		array $post_data,
		$order_lookup,
		int $expected_status,
		string $expected_substr
	): void {
		$_POST = $post_data;

		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wc_get_order' )->justReturn( $order_lookup );

		$err_payload = null;
		$err_status  = null;
		Functions\when( 'wp_send_json_error' )->alias( function( $data, $status = null ) use ( &$err_payload, &$err_status ) {
			$err_payload = $data;
			$err_status  = $status;
			throw new \RuntimeException( 'wp_send_json_error_invoked' );
		} );

		try {
			( new BacsLifecycleManager() )->ajax_submit_receipt();
			self::fail( 'Debió rechazar la solicitud inválida' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'wp_send_json_error_invoked', $e->getMessage() );
		} finally {
			$_POST = array();
		}

		self::assertSame( $expected_status, $err_status );
		self::assertIsArray( $err_payload );
		self::assertStringContainsString( $expected_substr, (string) ( $err_payload['message'] ?? '' ) );
	}

	/** @return array<string, array{0: array<string, string>, 1: mixed, 2: int, 3: string}> */
	public static function provider_ajax_invalid_requests(): array {
		return array(
			'referencia vacía'   => array( array( 'order_id' => '901', 'reference' => '' ), null, 400, 'requerido' ),
			'order_id inválido'  => array( array( 'order_id' => '0', 'reference' => 'TR-1' ), null, 400, 'requerido' ),
			'pedido inexistente' => array( array( 'order_id' => '9999', 'reference' => 'TR-1' ), false, 404, 'no encontrado' ),
		);
	}

	/**
	 * @testdox [NEG] Rechaza envío AJAX cuando no está autorizado, el pedido no está en espera/BACS o ya informó comprobante.
	 * @dataProvider provider_ajax_forbidden_or_conflict
	 */
	public function test_ajax_submit_receipt_rejects_unauthorized_wrong_state_or_duplicate(
		int $current_user_id,
		int $order_customer_id,
		string $posted_key,
		string $order_status,
		string $payment_method,
		$reported_at,
		int $expected_status,
		string $expected_substr
	): void {
		$_POST = array(
			'order_id'  => '902',
			'reference' => 'TR-DUP',
			'order_key' => $posted_key,
		);

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_customer_id' )->andReturn( $order_customer_id );
		$order->shouldReceive( 'get_order_key' )->andReturn( 'wc_order_real_key' );
		$order->shouldReceive( 'get_status' )->andReturn( $order_status );
		$order->shouldReceive( 'get_payment_method' )->andReturn( $payment_method );
		$order->shouldReceive( 'get_meta' )->with( BacsLifecycleManager::META_RECEIPT_REPORTED )->andReturn( $reported_at );
		$order->shouldReceive( 'get_meta' )->with( BacsLifecycleManager::META_RECEIPT_REFERENCE )->andReturn( 'OP-EXISTENTE' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'add_order_note' );
		$order->shouldNotReceive( 'save_meta_data' );

		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_current_user_id' )->justReturn( $current_user_id );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'as_schedule_single_action' )->never();

		$err_payload = null;
		$err_status  = null;
		Functions\when( 'wp_send_json_error' )->alias( function( $data, $status = null ) use ( &$err_payload, &$err_status ) {
			$err_payload = $data;
			$err_status  = $status;
			throw new \RuntimeException( 'wp_send_json_error_invoked' );
		} );

		try {
			( new BacsLifecycleManager() )->ajax_submit_receipt();
			self::fail( 'Debió rechazar la operación no permitida' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'wp_send_json_error_invoked', $e->getMessage() );
		} finally {
			$_POST = array();
		}

		self::assertSame( $expected_status, $err_status );
		self::assertIsArray( $err_payload );
		self::assertStringContainsString( $expected_substr, (string) ( $err_payload['message'] ?? '' ) );
	}

	/** @return array<string, array{0: int, 1: int, 2: string, 3: string, 4: string, 5: mixed, 6: int, 7: string}> */
	public static function provider_ajax_forbidden_or_conflict(): array {
		return array(
			'invitado sin key válida'  => array( 0, 10, 'wrong_key', 'on-hold', 'bacs', '', 403, 'No autorizado' ),
			'pedido ya pagado'         => array( 10, 10, '', 'processing', 'bacs', '', 409, 'estado actual' ),
			'pedido cancelado'         => array( 10, 10, '', 'cancelled', 'bacs', '', 409, 'estado actual' ),
			'pasarela distinta a bacs' => array( 10, 10, '', 'on-hold', 'woo-mercado-pago-basic', '', 409, 'estado actual' ),
			'comprobante ya informado' => array( 10, 10, '', 'on-hold', 'bacs', 1700000000, 409, 'Ya informaste un comprobante (OP-EXISTENTE)' ),
		);
	}
}
