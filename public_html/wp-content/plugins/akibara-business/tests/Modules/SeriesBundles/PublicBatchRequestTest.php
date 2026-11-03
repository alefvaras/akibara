<?php
/**
 * Límites y origen compartidos de los endpoints de compra múltiple.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\BuilderWidget;
use Akibara\Business\Modules\SeriesBundles\PublicBatchRequest;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PublicBatchRequestTest extends TestCase {

	/** @var array<string,mixed> */
	private array $original_post = array();

	/** @var array<string,mixed> */
	private array $original_server = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->original_post   = $_POST;
		$this->original_server = $_SERVER;
		$_POST                 = array();
		$_SERVER               = array();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $value ): string => (string) $value );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl/' );
	}

	protected function tearDown(): void {
		$_POST   = $this->original_post;
		$_SERVER = $this->original_server;
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox A 107-volume series is never truncated by the batch ceiling. */
	public function test_one_hundred_and_seven_ids_fit(): void {
		$_POST['product_ids'] = range( 1, 107 );

		$request = PublicBatchRequest::posted_product_ids( BuilderWidget::MAX_ADD_PER_REQUEST );

		self::assertCount( 107, $request['ids'] );
		self::assertSame( range( 1, 107 ), $request['ids'] );
		self::assertFalse( $request['truncated'] );
		self::assertSame( array( 'ids', 'truncated' ), array_keys( $request ) );
	}

	/** @testdox More than 300 submitted IDs are marked for fail-closed rejection before cart writes. */
	public function test_work_is_bounded_before_cart_writes(): void {
		$_POST['product_ids'] = range( 1, 400 );

		$request = PublicBatchRequest::posted_product_ids( BuilderWidget::MAX_ADD_PER_REQUEST );

		self::assertCount( BuilderWidget::MAX_ADD_PER_REQUEST, $request['ids'] );
		self::assertTrue( $request['truncated'] );
	}

	/** @testdox Same-origin is accepted and a foreign declared origin is rejected. */
	public function test_declared_origin_must_match_the_store(): void {
		$_SERVER['HTTP_ORIGIN'] = 'https://akibara.cl';
		self::assertTrue( PublicBatchRequest::origin_allowed() );

		$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
		self::assertFalse( PublicBatchRequest::origin_allowed() );
	}

	/** @testdox Scheme, effective port and signal presence are part of the exact origin. */
	public function test_scheme_port_and_absence_fail_closed(): void {
		$_SERVER['HTTP_ORIGIN'] = 'http://akibara.cl';
		self::assertFalse( PublicBatchRequest::origin_allowed(), 'HTTP no equivale a HTTPS.' );

		$_SERVER['HTTP_ORIGIN'] = 'https://akibara.cl:443';
		self::assertTrue( PublicBatchRequest::origin_allowed(), 'El puerto HTTPS implícito y explícito son equivalentes.' );

		$_SERVER['HTTP_ORIGIN'] = 'https://akibara.cl:8443';
		self::assertFalse( PublicBatchRequest::origin_allowed(), 'Un puerto alternativo es otro origen.' );

		unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
		self::assertFalse( PublicBatchRequest::origin_allowed(), 'Sin Origin ni Referer el POST público falla cerrado.' );
	}
}
