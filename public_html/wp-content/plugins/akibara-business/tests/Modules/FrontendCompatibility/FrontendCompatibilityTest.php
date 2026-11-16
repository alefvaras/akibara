<?php
/**
 * Tests for frontend/CDN compatibility.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\FrontendCompatibility;

use Akibara\Business\Modules\FrontendCompatibility\FrontendCompatibility;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );
defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://example.test/wp-content/plugins/akibara-business/' );
defined( 'AKIBARA_BUSINESS_VERSION' ) || define( 'AKIBARA_BUSINESS_VERSION', 'test-version' );

final class FrontendCompatibilityTest extends TestCase {
	/** @var array<string,mixed> Original request restored after each case. */
	private array $original_get;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->original_get = $_GET;
		unset( $_GET['key'] );
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		unset( $_SERVER['HTTP_HOST'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_merges_checkout_exclusions_without_duplicates(): void {
		$result = ( new FrontendCompatibility() )->add_litespeed_checkout_script_exclusions(
			array(
				'custom-checkout.js',
				'sdk.mercadopago.com/js/v2',
			)
		);

		self::assertContains( 'custom-checkout.js', $result );
		self::assertContains( 'woocommerce-mercadopago/assets/js/checkouts/', $result );
		self::assertSame( 1, array_count_values( $result )['sdk.mercadopago.com/js/v2'] );
	}

	public function test_accepts_newline_separated_litespeed_exclusions(): void {
		$result = ( new FrontendCompatibility() )->add_litespeed_checkout_script_exclusions(
			"first.js\nsecond.js"
		);

		self::assertContains( 'first.js', $result );
		self::assertContains( 'second.js', $result );
		self::assertContains( 'mp-checkout', $result );
	}

	public function test_adds_cloudinary_skip_lazy_bypass_once(): void {
		$result = ( new FrontendCompatibility() )->add_cloudinary_lazy_bypass_classes(
			array( 'cld-bypass-lazy', 'skip-lazy' )
		);

		self::assertSame( array( 'cld-bypass-lazy', 'skip-lazy' ), $result );
	}

	/**
	 * @testdox Raises the debug detector stack depth before WooCommerce installs its proxy.
	 */
	public function test_increases_dependency_detection_stack_depth(): void {
		Functions\expect( 'wp_print_inline_script_tag' )
			->once()
			->with(
				'if(typeof Error.stackTraceLimit==="number"&&Error.stackTraceLimit<50){Error.stackTraceLimit=50;}',
				array( 'id' => 'akibara-wc-dependency-detection-stack' )
			);

		( new FrontendCompatibility() )->increase_dependency_detection_stack_depth();
		self::addToAssertionCount( 1 );
	}

	public function test_disables_google_tracking_on_nonproduction_clone_even_if_environment_type_is_production(): void {
		$_SERVER['HTTP_HOST'] = 'clone.akibara.invalid';
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$module = new FrontendCompatibility();
		$rank   = $module->filter_rank_math_analytics_options(
			array(
				'install_code' => true,
				'property_id'  => 'G-TEST',
			)
		);
		$woo    = $module->filter_woocommerce_analytics_options(
			array( 'ga_id' => 'G-TEST' )
		);

		self::assertFalse( $rank['install_code'] );
		self::assertSame( 'G-TEST', $rank['property_id'] );
		self::assertSame( '', $woo['ga_id'] );
	}

	public function test_keeps_google_tracking_configuration_on_canonical_production_host(): void {
		$_SERVER['HTTP_HOST'] = 'www.akibara.cl';
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$module = new FrontendCompatibility();
		$rank   = array( 'install_code' => true );
		$woo    = array( 'ga_id' => 'G-TEST' );

		self::assertSame( $rank, $module->filter_rank_math_analytics_options( $rank ) );
		self::assertSame( $woo, $module->filter_woocommerce_analytics_options( $woo ) );
	}

}
