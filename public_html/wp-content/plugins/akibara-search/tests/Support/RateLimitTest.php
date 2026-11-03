<?php
/**
 * Regresiones del contador autosuficiente de Akibara Search.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests\Support;

use Akibara\Search\Support\RateLimit;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Support/RateLimit.php';

final class RateLimitTest extends TestCase {

	/** @var array<string, mixed> */
	private array $original_server;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
		$this->original_server       = $_SERVER;
		$_SERVER['REMOTE_ADDR']      = '203.0.113.20';

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
	}

	protected function tearDown(): void {
		$_SERVER = $this->original_server;
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Should enforce the threshold when the cache cannot increment. */
	public function test_cache_without_increment_uses_atomic_slots(): void {
		$cache     = array();
		$add_calls = 0;

		Functions\when( 'wp_cache_incr' )->justReturn( false );
		Functions\when( 'wp_cache_get' )->alias(
			static function ( string $key, string $group ) use ( &$cache ) {
				return $cache[ $group . '|' . $key ] ?? false;
			}
		);
		Functions\when( 'wp_cache_set' )->alias(
			static function ( string $key, $value, string $group, int $ttl ) use ( &$cache ): bool {
				unset( $ttl );
				$cache[ $group . '|' . $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_add' )->alias(
			static function ( string $key, $value, string $group, int $ttl ) use ( &$cache, &$add_calls ): bool {
				unset( $ttl );
				++$add_calls;
				$cache_key = $group . '|' . $key;
				if ( array_key_exists( $cache_key, $cache ) ) {
					return false;
				}

				$cache[ $cache_key ] = $value;
				return true;
			}
		);

		self::assertFalse( RateLimit::per_minute( 'suggest-fallback', 3 ) );
		self::assertFalse( RateLimit::per_minute( 'suggest-fallback', 3 ) );
		self::assertFalse( RateLimit::per_minute( 'suggest-fallback', 3 ) );
		self::assertTrue( RateLimit::per_minute( 'suggest-fallback', 3 ) );
		$adds_after_block = $add_calls;
		self::assertTrue( RateLimit::per_minute( 'suggest-fallback', 3 ) );
		self::assertSame( $adds_after_block, $add_calls );
	}

	/** @testdox Should reconcile an in-flight increment with an irreversible slot switch. */
	public function test_inflight_increment_does_not_double_the_allowance(): void {
		$cache           = array();
		$increment_calls = 0;

		Functions\when( 'wp_cache_get' )->alias(
			static function ( string $key, string $group ) use ( &$cache ) {
				return $cache[ $group . '|' . $key ] ?? false;
			}
		);
		Functions\when( 'wp_cache_add' )->alias(
			static function ( string $key, $value, string $group, int $ttl ) use ( &$cache ): bool {
				unset( $ttl );
				$cache_key = $group . '|' . $key;
				if ( array_key_exists( $cache_key, $cache ) ) {
					return false;
				}

				$cache[ $cache_key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_incr' )->alias(
			static function ( string $key, int $offset, string $group ) use ( &$cache, &$increment_calls ): int {
				++$increment_calls;
				$cache_key           = $group . '|' . $key;
				$cache[ $cache_key ] = (int) ( $cache[ $cache_key ] ?? 0 ) + $offset;
				$cache[ $group . '|' . $key . ':slots' ] = 0;

				return (int) $cache[ $cache_key ];
			}
		);

		self::assertFalse( RateLimit::per_minute( 'count-race', 3 ) );
		self::assertFalse( RateLimit::per_minute( 'count-race', 3 ) );
		self::assertFalse( RateLimit::per_minute( 'count-race', 3 ) );
		self::assertTrue( RateLimit::per_minute( 'count-race', 3 ) );
		self::assertSame( 1, $increment_calls );
	}
}
