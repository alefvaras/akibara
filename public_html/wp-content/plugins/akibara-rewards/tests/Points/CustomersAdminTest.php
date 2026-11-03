<?php
/** Customers report contracts: account identity, filtering and bounded reads.
 *
 * @package Akibara\Rewards\Tests
 */
declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\CustomersAdmin;
use Akibara\Rewards\Points\CustomerPointsPage;
use Akibara\Rewards\Points\AdminAdjust;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/** Integration boundaries for the Woo Customers extension. */
final class CustomersAdminTest extends RewardsTestCase {
	/** The System Under Test. */
	private CustomersAdmin $sut;

	/** Set up manager context. */
	protected function setUp(): void {
		parent::setUp();
		$this->sut = new CustomersAdmin();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path );
	}

	/** @testdox Settings hooks must not pass WordPress's empty-string placeholder as an account ID. */
	public function test_legacy_settings_hook_has_no_account_argument(): void {
		$adjust = new AdminAdjust();
		\Brain\Monkey\Actions\expectAdded( 'akb_rewards_settings_after' )->once()->with( array( $adjust, 'render_box' ), 10, 0 );
		$adjust->register();
		$this->assertTrue( has_action( 'akb_rewards_settings_after', array( $adjust, 'render_box' ) ) !== false );
	}

	/** @testdox Hidden details register their actual admin_page hook without orphaning a WooCommerce submenu. */
	public function test_hidden_detail_registers_without_an_inaccessible_parent(): void {
		$page = new CustomerPointsPage();
		Functions\expect( 'add_submenu_page' )->once()->with( '', 'Gestionar puntos', 'Gestionar puntos', 'manage_woocommerce', CustomerPointsPage::SLUG, array( $page, 'render' ) );
		Functions\expect( 'remove_submenu_page' )->never();
		$page->add_page();
		$this->assertStringContainsString( 'admin.php?page=akb-customer-points', CustomerPointsPage::url( 197 ) );
	}

	/** @testdox Report data maps user_id rather than the unrelated Analytics id, and guests have no balance or action. */
	public function test_identity_mapping_and_batch_meta_read(): void {
		$this->wpdb->user_meta[197][ Ledger::META_BALANCE ] = 800;
		$this->wpdb->user_meta[999][ Ledger::META_BALANCE ] = 9999;
		$rows = array( array( 'id' => 999, 'user_id' => 197 ), array( 'id' => 197, 'user_id' => 0 ), array( 'id' => 17, 'user_id' => 45 ) );
		Functions\expect( 'get_users' )->once()->with( array( 'include' => array( 197, 45 ), 'fields' => 'ID', 'number' => 2, 'count_total' => false ) )->andReturn( array( 197 ) );
		Functions\expect( 'update_meta_cache' )->once()->with( 'user', array( 197 ) );
		$this->sut->prime_accounts( (object) array( 'data' => $rows ) );
		foreach ( $rows as $index => $row ) {
			$response = $this->response();
			$actual = $this->sut->customer_response( $response, $row, null )->get_data()['akb_rewards'];
			$this->assertSame( 0 === $index, $actual['has_account'] );
			$this->assertSame( 0 === $index ? 800 : null, $actual['balance'] );
			$this->assertSame( 0 === $index ? CustomerPointsPage::url( 197 ) : '', $actual['manage_url'] );
		}
	}

	/** @testdox Numeric filters and positive/zero/guest selectors are applied before pagination and scoped to Customers. */
	public function test_sql_filters_account_balances_without_multiplying_rows(): void {
		$base = $this->wpdb;
		$GLOBALS['wpdb'] = new class( $base ) {
			public string $prefix = 'wp_';
			public string $users = 'wp_users';
			public string $usermeta = 'wp_usermeta';
			private $base;
			public function __construct( $base ) { $this->base = $base; }
			public function prepare( string $sql, ...$args ): string { return $this->base->prepare( $sql, ...$args ); }
		};
		foreach ( array( 'positive' => ' > 0', 'zero' => ' = 0', 'guest' => 'AND NOT EXISTS' ) as $mode => $needle ) {
			$request = $this->request( array( 'akb_points' => $mode ) );
			$this->sut->begin_request( null, array(), $request );
			$sql = implode( ' ', $this->sut->where( array( '1 = 1' ) ) );
			$this->assertStringContainsString( $needle, $sql );
			$this->assertStringContainsString( 'wp_wc_customer_lookup.user_id', $sql );
			$this->assertStringNotContainsString( 'wp_wc_customer_lookup.customer_id', $sql );
			$this->assertFalse( $this->sut->use_cache( true, 'customers' ) );
			$this->assertFalse( $this->sut->use_cache( true, 'customers_stats' ) );
			$this->assertTrue( $this->sut->use_cache( true, 'orders' ) );
			$this->sut->end_request( null, array(), $request );
			$this->assertSame( array( '1 = 1' ), $this->sut->where( array( '1 = 1' ) ) );
		}
		$request = $this->request( array( 'akb_balance_between' => array( 500, 0 ) ) );
		$this->sut->begin_request( null, array(), $request );
		$sql = implode( ' ', $this->sut->where( array() ) );
		$this->assertStringContainsString( 'BETWEEN 0 AND 500', $sql );
		$this->assertStringContainsString( 'COALESCE', $sql, 'Missing meta is zero for an existing account.' );
		$this->assertStringContainsString( 'ORDER BY akb_points.umeta_id ASC LIMIT 1', $sql );
		$this->assertSame( array( 'akb_balance_between' => array( 0, 500 ) ), $this->sut->query_args( array() ) );
		$GLOBALS['wpdb'] = $base;
	}

	/** @testdox Unrelated or unauthorized REST requests cannot install balance filters. */
	public function test_context_is_restored_after_nested_requests_and_denied_for_customers_without_permission(): void {
		$outer = $this->request( array( 'akb_points' => 'positive' ) );
		$inner = $this->request( array( 'akb_points' => 'zero' ), '/wc/v3/orders' );
		$this->sut->begin_request( null, array(), $outer );
		$this->sut->begin_request( null, array(), $inner );
		$this->assertSame( array(), $this->sut->query_args( array() ) );
		$this->sut->end_request( null, array(), $inner );
		$this->assertSame( array( 'akb_points' => 'positive' ), $this->sut->query_args( array() ) );
		$this->sut->end_request( null, array(), $outer );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->sut->begin_request( null, array(), $outer );
		$this->assertSame( array(), $this->sut->query_args( array() ) );
		$this->assertSame( array(), $this->sut->customer_response( $this->response(), array( 'user_id' => 197 ), null )->get_data() );
	}

	/** @testdox Ledger history is bounded, ordered consistently and clamps an out-of-range page. */
	public function test_history_paginates_by_account_without_loading_every_movement(): void {
		$this->wpdb->var_answers['SELECT COUNT(*) FROM wp_akb_rewards_ledger WHERE user_id = 197'] = 45;
		$this->wpdb->results_answers['LIMIT 20 OFFSET 40'] = array( array( 'id' => 1, 'delta' => 5 ) );
		$page = ( new CustomerPointsPage() )->history( 197, 999 );
		$this->assertSame( 3, $page['page'] );
		$this->assertSame( 45, $page['total'] );
		$this->assertCount( 1, $page['rows'] );
		$this->assertStringContainsString( 'WHERE user_id = 197 ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET 40', end( $this->wpdb->results_log ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertSame( 0, ( new CustomerPointsPage() )->history( 197, 1 )['total'] );
	}

	/** @testdox Account adjustment ignores a tampered email and returns to the same account detail. */
	public function test_adjustment_uses_selected_account_and_reuses_ledger_audit(): void {
		$this->wpdb->users[197] = array( 'user_email' => 'owner@example.test', 'user_login' => 'owner' );
		$this->wpdb->users[999] = array( 'user_email' => 'other@example.test', 'user_login' => 'other' );
		$_POST = array( 'akb_adj_user_id' => 197, 'akb_adj_email' => 'other@example.test', 'akb_adj_points' => 20, 'akb_adj_signo' => 'mas', 'akb_adj_motivo' => 'Compensación de prueba' );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ): string => trim( strip_tags( $s ) ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$admin = new \WP_User( 1 );
		$admin->user_login = 'admin';
		Functions\when( 'wp_get_current_user' )->justReturn( $admin );
		Functions\when( 'wp_safe_redirect' )->alias( static function ( string $url ): void { throw new \RuntimeException( $url ); } );
		try {
			( new AdminAdjust() )->handle();
			$this->fail( 'Expected redirect after adjustment.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( CustomerPointsPage::url( 197 ), $error->getMessage() );
		} finally {
			$_POST = array();
		}
		$this->assertSame( 20, Ledger::balance( 197 ) );
		$this->assertSame( 0, Ledger::balance( 999 ) );
		$this->assertStringContainsString( 'Compensación de prueba', $this->wpdb->inserts[0][1]['note'] );
	}

	/** Provide params without expanding global WordPress test stubs. */
	private function request( array $params, string $route = '/wc-analytics/reports/customers' ): \WP_REST_Request {
		return new class( $params, $route ) extends \WP_REST_Request {
			private array $params;
			public function __construct( array $params, string $route ) { parent::__construct( 'GET', $route ); $this->params = $params; }
			public function get_params(): array { return $this->params; }
		};
	}

	/** Mutable response for testing the official response filter. */
	private function response(): \WP_REST_Response {
		return new class() extends \WP_REST_Response {
			private array $payload = array();
			public function get_data(): array { return $this->payload; }
			public function set_data( array $data ): void { $this->payload = $data; }
		};
	}
}
