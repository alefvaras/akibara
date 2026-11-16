<?php
/** Restock capture assets remain shared across supported product surfaces. */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BackInStock;

use Akibara\Business\Modules\BackInStock\BackInStock;
use Akibara\Business\Modules\BackInStock\CaptureForm;
use Akibara\Business\Modules\Wishlist\Endpoint as WishlistEndpoint;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CaptureFormAssetsTest extends TestCase {
	private CaptureForm $sut;
	private string $endpoint = '';
	private bool $enabled = true;
	private bool $admin = false;
	private bool $search = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->sut = new CaptureForm();
		Functions\when( 'get_option' )->alias( fn ( $key, $default = false ) => BackInStock::OPTION_CAPTURE === $key ? ( $this->enabled ? 'yes' : 'no' ) : $default );
		Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );
		Functions\when( 'is_admin' )->alias( fn () => $this->admin );
		foreach ( array( 'is_product', 'is_shop', 'is_product_taxonomy', 'is_front_page', 'is_home', 'is_archive' ) as $function ) {
			Functions\when( $function )->justReturn( false );
		}
		Functions\when( 'is_search' )->alias( fn () => $this->search );
		Functions\when( 'is_wc_endpoint_url' )->alias( fn ( $endpoint ) => $endpoint === $this->endpoint );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Wishlist endpoint receives the shared restock modal assets. */
	public function test_wishlist_receives_shared_restock_assets(): void {
		$this->endpoint = WishlistEndpoint::SLUG;
		self::assertTrue( $this->sut->should_enqueue() );
	}

	/** @testdox Disabling capture removes the capability from wishlist. */
	public function test_disabled_capture_does_not_enqueue_wishlist_assets(): void {
		$this->endpoint = WishlistEndpoint::SLUG;
		$this->enabled = false;
		self::assertFalse( $this->sut->should_enqueue() );
	}

	/** @testdox Administrative requests do not receive storefront capture assets. */
	public function test_admin_request_is_excluded(): void {
		$this->endpoint = WishlistEndpoint::SLUG;
		$this->admin = true;
		self::assertFalse( $this->sut->should_enqueue() );
	}

	/** @testdox Other account endpoints do not acquire unused restock assets. */
	public function test_unrelated_account_endpoint_is_excluded(): void {
		$this->endpoint = 'edit-address';
		self::assertFalse( $this->sut->should_enqueue() );
	}

	/** @testdox The wishlist retains its existing restock modal. */
	public function test_wishlist_retains_shared_restock_assets(): void {
		$this->endpoint = \Akibara\Business\Modules\Wishlist\Endpoint::SLUG;
		self::assertTrue( $this->sut->should_enqueue() );
	}

	/** @testdox Product search retains its existing restock modal. */
	public function test_search_retains_shared_restock_assets(): void {
		$this->search = true;
		self::assertTrue( $this->sut->should_enqueue() );
	}
}
