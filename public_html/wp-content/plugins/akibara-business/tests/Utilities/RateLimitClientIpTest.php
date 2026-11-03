<?php
/**
 * Tests de la resolución CF-aware de la IP del cliente para el rate-limit.
 *
 * Regresión del hallazgo medium de la auditoría de seguridad 2026-07-28:
 * aceptar `CF-Connecting-IP` sin verificar que el peer sea Cloudflare hacía
 * evadible el rate-limit de todos los endpoints públicos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Utilities\RateLimit;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RateLimitClientIpTest extends TestCase {

	/** @var array<string, mixed> */
	private array $original_server;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->original_server = $_SERVER;

		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
	}

	protected function tearDown(): void {
		$_SERVER = $this->original_server;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should ignore CF-Connecting-IP when the peer is not Cloudflare.
	 */
	public function test_spoofed_header_from_untrusted_peer_is_ignored(): void {
		$_SERVER['REMOTE_ADDR']              = '203.0.113.9'; // Atacante directo al origen.
		$_SERVER['HTTP_CF_CONNECTING_IP']    = '198.51.100.77';

		self::assertSame( '203.0.113.9', RateLimit::client_ip() );
	}

	/**
	 * @testdox Should trust CF-Connecting-IP when the peer is a Cloudflare edge.
	 */
	public function test_header_from_cloudflare_edge_is_trusted(): void {
		$_SERVER['REMOTE_ADDR']           = '104.16.0.1'; // 104.16.0.0/13.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';

		self::assertSame( '198.51.100.77', RateLimit::client_ip() );
	}

	/**
	 * @testdox Should fall back to the peer when no proxy header is present.
	 */
	public function test_without_header_uses_remote_addr(): void {
		$_SERVER['REMOTE_ADDR'] = '104.16.0.1';
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );

		self::assertSame( '104.16.0.1', RateLimit::client_ip() );
	}

	/**
	 * @testdox Should never trust X-Forwarded-For or X-Real-IP.
	 */
	public function test_forwarded_headers_are_never_trusted(): void {
		$_SERVER['REMOTE_ADDR']          = '104.16.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';
		$_SERVER['HTTP_X_REAL_IP']       = '198.51.100.78';

		self::assertSame( '104.16.0.1', RateLimit::client_ip() );
	}

	/**
	 * @testdox Should reject a malformed value even from a trusted peer.
	 */
	public function test_malformed_header_falls_back_to_peer(): void {
		$_SERVER['REMOTE_ADDR']           = '104.16.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';

		self::assertSame( '104.16.0.1', RateLimit::client_ip() );
	}

	/**
	 * @testdox Should recognise Cloudflare IPv6 edges.
	 */
	public function test_ipv6_cloudflare_edge_is_trusted(): void {
		$_SERVER['REMOTE_ADDR']           = '2606:4700::1'; // 2606:4700::/32.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';

		self::assertSame( '198.51.100.77', RateLimit::client_ip() );
	}

	/**
	 * @testdox Should not treat a near-miss range as Cloudflare.
	 */
	public function test_adjacent_range_is_not_trusted(): void {
		// 104.32.0.0 queda fuera de 104.16.0.0/13 y de 104.24.0.0/14.
		$_SERVER['REMOTE_ADDR']           = '104.32.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';

		self::assertSame( '104.32.0.1', RateLimit::client_ip() );
	}

	/**
	 * @testdox Should match a range whose prefix is not byte-aligned.
	 */
	public function test_non_byte_aligned_prefix_matches(): void {
		// 2a06:98c0::/29 — prefijo de 29 bits (no múltiplo de 8).
		self::assertTrue( RateLimit::is_trusted_proxy( '2a06:98c0::5' ) );
		self::assertFalse( RateLimit::is_trusted_proxy( '2a06:98c8::5' ) );
	}

	/**
	 * @testdox Should degrade safely when the peer address is missing.
	 */
	public function test_missing_remote_addr_is_neutral(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';

		self::assertSame( '0.0.0.0', RateLimit::client_ip() );
	}

	/**
	 * @testdox Should preserve the exact limit when an external cache cannot increment.
	 */
	public function test_external_cache_without_increment_uses_atomic_slots(): void {
		defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$cache                  = array();
		$add_calls              = 0;

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
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

		self::assertFalse( RateLimit::per_minute( 'cache-fallback', 3 ) );
		self::assertFalse( RateLimit::per_minute( 'cache-fallback', 3 ) );
		self::assertFalse( RateLimit::per_minute( 'cache-fallback', 3 ) );
		self::assertTrue( RateLimit::per_minute( 'cache-fallback', 3 ) );
		$adds_after_block = $add_calls;
		self::assertTrue( RateLimit::per_minute( 'cache-fallback', 3 ) );
		self::assertSame( $adds_after_block, $add_calls, 'El cupo bloqueante debe ser un fast-path sin nuevos add().' );
	}

	/**
	 * @testdox Should count an increment that finishes after another request switches to slots.
	 */
	public function test_inflight_increment_is_reconciled_with_slot_mode(): void {
		defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$cache                  = array();
		$increment_calls        = 0;

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
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

				// Otro request fijó baseline=0 mientras este incr seguía en vuelo.
				$cache[ $group . '|' . $key . ':slots' ] = 0;
				return (int) $cache[ $cache_key ];
			}
		);

		self::assertFalse( RateLimit::per_minute( 'cache-race', 3 ) );
		self::assertFalse( RateLimit::per_minute( 'cache-race', 3 ) );
		self::assertFalse( RateLimit::per_minute( 'cache-race', 3 ) );
		self::assertTrue( RateLimit::per_minute( 'cache-race', 3 ) );
		self::assertSame( 1, $increment_calls, 'El marcador debe impedir volver a mezclar incr y cupos.' );
	}

	/**
	 * @testdox Should publish the switching barrier before snapshotting the baseline.
	 */
	public function test_switching_barrier_closes_snapshot_publish_race(): void {
		defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
		$cache                  = array();
		$increment_calls        = 0;
		$nested_result          = null;
		$running_nested         = false;

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
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
		Functions\when( 'wp_cache_incr' )->alias(
			static function ( string $key, int $offset, string $group ) use ( &$cache, &$increment_calls ) {
				++$increment_calls;
				if ( 2 === $increment_calls ) {
					return false;
				}

				$cache_key           = $group . '|' . $key;
				$cache[ $cache_key ] = (int) ( $cache[ $cache_key ] ?? 0 ) + $offset;
				return (int) $cache[ $cache_key ];
			}
		);
		Functions\when( 'wp_cache_add' )->alias(
			static function ( string $key, $value, string $group, int $ttl ) use ( &$cache, &$nested_result, &$running_nested ): bool {
				unset( $ttl );
				$cache_key = $group . '|' . $key;
				if ( array_key_exists( $cache_key, $cache ) ) {
					return false;
				}

				$cache[ $cache_key ] = $value;
				if ( 'switching' === $value && ! $running_nested ) {
					$running_nested = true;
					$nested_result  = RateLimit::per_minute( 'cache-publish-race', 3 );
					$running_nested = false;
				}

				return true;
			}
		);

		self::assertFalse( RateLimit::per_minute( 'cache-publish-race', 3 ) );
		self::assertFalse( RateLimit::per_minute( 'cache-publish-race', 3 ) );
		self::assertTrue( $nested_result, 'Un request que observa switching debe fallar cerrado.' );
		self::assertFalse( RateLimit::per_minute( 'cache-publish-race', 3 ) );
		self::assertTrue( RateLimit::per_minute( 'cache-publish-race', 3 ) );
	}
}
