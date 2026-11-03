<?php
/**
 * Pruebas de la integración administrativa con Rank Math.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoIndexing;

use Akibara\Business\Modules\SeoIndexing\RankMathAdminUi;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RankMathAdminUiTest extends TestCase {

	/** @var array<string,mixed> */
	private array $original_get = array();

	/** @var array<string,mixed> */
	private array $original_post = array();

	/** @var array<string,mixed> */
	private array $original_server = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_get    = $_GET;
		$this->original_post   = $_POST;
		$this->original_server = $_SERVER;
		$_GET                  = array();
		$_POST                 = array();
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=rank-math-options-general';

		Functions\when( 'sanitize_key' )->alias(
			static fn( string $value ): string => strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ?? '' )
		);
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		$_GET    = $this->original_get;
		$_POST   = $this->original_post;
		$_SERVER = $this->original_server;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registers_only_official_rank_math_admin_filters(): void {
		$sut = new RankMathAdminUi();

		Monkey\Filters\expectAdded( 'rank_math/can_edit_file' )
			->once()
			->with( array( $sut, 'allow_virtual_robots_editor' ), PHP_INT_MAX );
		Monkey\Filters\expectAdded( 'rank_math/settings/general' )
			->once()
			->with( array( $sut, 'remove_htaccess_editor' ), PHP_INT_MAX );

		$sut->register();
		$this->addToAssertionCount( 2 );
	}

	public function test_allows_virtual_editor_only_on_rank_math_general_settings(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->alias(
			static fn( string $capability ): bool => 'manage_options' === $capability
		);
		$_GET['page'] = 'rank-math-options-general';

		self::assertTrue( ( new RankMathAdminUi() )->allow_virtual_robots_editor( false ) );
	}

	public function test_keeps_file_editing_disabled_outside_rank_math_settings(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET['page'] = 'plugins.php';

		self::assertFalse( ( new RankMathAdminUi() )->allow_virtual_robots_editor( false ) );
	}

	public function test_removes_only_htaccess_tab(): void {
		$sut = new RankMathAdminUi();
		$tabs = array(
			'robots'      => array( 'title' => 'Robots' ),
			'htaccess'    => array( 'title' => '.htaccess' ),
			'woocommerce' => array( 'title' => 'WooCommerce' ),
		);

		self::assertSame(
			array(
				'robots'      => array( 'title' => 'Robots' ),
				'woocommerce' => array( 'title' => 'WooCommerce' ),
			),
			$sut->remove_htaccess_editor( $tabs )
		);
	}

	public function test_blocks_legacy_htaccess_post_even_on_rank_math_general_settings(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET['page']                      = 'rank-math-options-general';
		$_POST['htaccess_accept_changes'] = '1';
		$_POST['htaccess_content']        = 'malicious';

		self::assertFalse( ( new RankMathAdminUi() )->allow_virtual_robots_editor( true ) );
	}

	public function test_blocks_form_encoded_react_htaccess_payload(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET['page']     = 'rank-math-options-general';
		$_POST['settings'] = array(
			'htaccess_allow_editing' => true,
			'htaccess_content'       => 'malicious',
		);

		self::assertFalse( ( new RankMathAdminUi() )->allow_virtual_robots_editor( true ) );
	}

	public function test_blocks_rank_math_rest_file_permission(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		$_SERVER['REQUEST_URI'] = '/wp-json/rankmath/v1/updateSettings';

		self::assertFalse( ( new RankMathAdminUi() )->allow_virtual_robots_editor( true ) );
	}

	public function test_keeps_official_virtual_robots_post_available(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET['page']                = 'rank-math-options-general';
		$_POST['robots_txt_content'] = "User-agent: *\nDisallow:";

		self::assertTrue( ( new RankMathAdminUi() )->allow_virtual_robots_editor( false ) );
	}
}
