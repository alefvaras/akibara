<?php
/**
 * Visibilidad de fuente ante resultados ambiguos del par mode+buckets.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\FacetRegistry;
use Akibara\Search\Install;
use Akibara\Search\PriceBuckets;
use Akibara\Search\SemanticPublication;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Doble del protocolo completo: option CAS, fence y par transaccional sobre un
 * store compartido con get_option(). Permite separar COMMIT aplicado de su ACK.
 */
final class AmbiguousPricePairWpdb {

	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $last_error = '';

	public bool $lose_commit_ack = false;
	public bool $fail_release = false;

	/** @var array<int, string> */
	public array $queries = array();

	/** @var array<string, mixed> */
	private array $option_store;

	/** @var array<string, mixed> */
	private array $snapshot = array();

	private bool $in_transaction = false;

	/** @param array<string, mixed> $option_store Options compartidas con los mocks WP. */
	public function __construct( array &$option_store ) {
		$this->option_store = &$option_store;
	}

	/** @param mixed ...$args Valores SQL. */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg )
				? (string) $arg
				: "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace_callback(
				'/%[sd]/',
				static fn(): string => $replacement,
				$query,
				1
			);
		}
		return $query;
	}

	/** @return string|null */
	public function get_var( string $query ): ?string {
		$this->queries[]  = $query;
		$this->last_error = '';

		if ( false !== stripos( $query, 'RELEASE_LOCK(' ) ) {
			return $this->fail_release ? '0' : '1';
		}
		if ( false !== stripos( $query, 'GET_LOCK(' ) ) {
			return '1';
		}
		if ( false !== stripos( $query, 'CONNECTION_ID()' ) || false !== stripos( $query, 'IS_USED_LOCK(' ) ) {
			return '37';
		}
		if ( preg_match( "/option_name = '((?:''|[^'])+)'/", $query, $matches ) ) {
			$name = str_replace( "''", "'", $matches[1] );
			return array_key_exists( $name, $this->option_store )
				? self::serialise( $this->option_store[ $name ] )
				: null;
		}

		return null;
	}

	/** @return object|null */
	public function get_row( string $query ): ?object {
		$this->queries[]  = $query;
		$this->last_error = '';
		if ( ! preg_match( "/option_name = '((?:''|[^'])+)'/", $query, $matches ) ) {
			return null;
		}
		$name = str_replace( "''", "'", $matches[1] );
		if ( ! array_key_exists( $name, $this->option_store ) ) {
			return null;
		}
		return (object) array(
			'option_value' => self::serialise( $this->option_store[ $name ] ),
			'autoload'     => 'no',
		);
	}

	/** @return int|false */
	public function query( string $query ) {
		$this->queries[] = $query;
		$verb            = strtoupper( (string) strtok( ltrim( $query ), " \n\t" ) );

		if ( 'START' === $verb ) {
			$this->last_error     = '';
			$this->snapshot       = $this->option_store;
			$this->in_transaction = true;
			return 0;
		}
		if ( 'ROLLBACK' === $verb ) {
			$this->last_error = '';
			if ( $this->in_transaction ) {
				$this->option_store = $this->snapshot;
			}
			$this->in_transaction = false;
			return 0;
		}
		if ( 'COMMIT' === $verb ) {
			// El servidor aplicó COMMIT antes de que se perdiera su respuesta. Un
			// ROLLBACK defensivo posterior ya no puede deshacer ese estado.
			$this->in_transaction = false;
			if ( $this->lose_commit_ack ) {
				$this->last_error = 'simulated lost commit acknowledgement';
				return false;
			}
			$this->last_error = '';
			return 0;
		}

		$values = self::quoted_values( $query );
		if ( 'INSERT' === $verb && false !== stripos( $query, 'INSERT IGNORE' ) ) {
			if ( 3 > count( $values ) ) {
				$this->last_error = 'malformed option insert';
				return false;
			}
			$name = $values[0];
			if ( array_key_exists( $name, $this->option_store ) ) {
				$this->last_error = '';
				return 0;
			}
			$this->option_store[ $name ] = self::unserialise( $values[1] );
			$this->last_error            = '';
			return 1;
		}
		if ( 'INSERT' === $verb ) {
			if ( 6 > count( $values ) ) {
				$this->last_error = 'malformed pair upsert';
				return false;
			}
			$this->option_store[ $values[0] ] = self::unserialise( $values[1] );
			$this->option_store[ $values[3] ] = self::unserialise( $values[4] );
			$this->last_error                  = '';
			return 2;
		}

		$this->last_error = '';
		return 0;
	}

	/** @param mixed $value Option value. */
	private static function serialise( $value ): string {
		return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
	}

	/** @return mixed */
	private static function unserialise( string $raw ) {
		$decoded = @unserialize( $raw, array( 'allowed_classes' => false ) );
		return false !== $decoded || 'b:0;' === $raw ? $decoded : $raw;
	}

	/** @return array<int, string> */
	private static function quoted_values( string $query ): array {
		preg_match_all( "/'((?:''|[^'])*)'/", $query, $matches );
		return array_map( static fn( string $value ): string => str_replace( "''", "'", $value ), $matches[1] ?? array() );
	}
}

/** @covers \Akibara\Search\PriceBuckets */
final class PriceBucketsAmbiguousOutcomeTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array{0: string, 1: string}> */
	private array $cache_deletes = array();

	/** @var mixed */
	private $previous_wpdb;

	private bool $had_previous_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		SemanticPublication::reset_authoritative_state_for_tests();
		$this->had_previous_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb     = $GLOBALS['wpdb'] ?? null;
		$this->options           = array();
		$this->cache_deletes     = array();
		FacetRegistry::reset_cache();

		$options       = &$this->options;
		$cache_deletes = &$this->cache_deletes;
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( '22222222-2222-4222-8222-222222222222' );
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( string $key, string $group = '' ) use ( &$cache_deletes ): bool {
				$cache_deletes[] = array( $key, $group );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		FacetRegistry::reset_cache();
		if ( $this->had_previous_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox COMMIT aplicado sin ACK devuelve false, conserva deuda e invalida todo cache del par. */
	public function test_lost_commit_ack_invalidates_source_caches_and_memo(): void {
		$db                  = $this->prime_old_pair();
		$db->lose_commit_ack = true;

		self::assertFalse( PriceBuckets::save_manual( $this->rows( 25000 ) ) );

		$this->assert_ambiguous_commit_is_visible_and_repairable( 25000 );
		self::assertStringContainsString( 'COMMIT', implode( "\n", $db->queries ) );
		self::assertStringContainsString( 'ROLLBACK', implode( "\n", $db->queries ) );
	}

	/** @testdox Release ambiguo tras COMMIT devuelve false, conserva deuda e invalida todo cache del par. */
	public function test_failed_release_invalidates_source_caches_and_memo(): void {
		$db               = $this->prime_old_pair();
		$db->fail_release = true;

		self::assertFalse( PriceBuckets::save_manual( $this->rows( 35000 ) ) );

		$this->assert_ambiguous_commit_is_visible_and_repairable( 35000 );
		self::assertStringContainsString( 'RELEASE_LOCK', implode( "\n", $db->queries ) );
	}

	private function prime_old_pair(): AmbiguousPricePairWpdb {
		$old = $this->rows( 10000 );
		$this->options[ PriceBuckets::OPTION_MODE ]            = 'auto';
		$this->options[ FacetRegistry::OPTION_PRECIO_BUCKETS ] = $old;
		self::assertSame( $old, FacetRegistry::precio_buckets(), 'Precondición: memo viejo quedó primado.' );

		$db              = new AmbiguousPricePairWpdb( $this->options );
		$GLOBALS['wpdb'] = $db;
		return $db;
	}

	private function assert_ambiguous_commit_is_visible_and_repairable( int $cut ): void {
		$expected = $this->rows( $cut );
		self::assertSame( 'manual', $this->options[ PriceBuckets::OPTION_MODE ] );
		self::assertSame( $expected, $this->options[ FacetRegistry::OPTION_PRECIO_BUCKETS ] );
		self::assertSame(
			$expected,
			FacetRegistry::precio_buckets(),
			'El reset del memo debe hacer visible el par que pudo haber sido confirmado.'
		);

		$outbox = $this->options[ Install::OPTION_GENERATION_OUTBOX ] ?? null;
		self::assertIsArray( $outbox, 'La intención PRE debe sobrevivir para reconciliación.' );
		self::assertGreaterThan( (int) $outbox['acked_seq'], (int) $outbox['wanted_seq'] );
		self::assertNotEmpty( $outbox['barriers'] );

		foreach ( array( PriceBuckets::OPTION_MODE, FacetRegistry::OPTION_PRECIO_BUCKETS, 'alloptions', 'notoptions' ) as $key ) {
			self::assertTrue(
				in_array( array( $key, 'options' ), $this->cache_deletes, true ),
				'Faltó invalidar la entrada de object cache: ' . $key
			);
		}
	}

	/** @return array<int, array{min: int|null, max: int|null, label: string}> */
	private function rows( int $cut ): array {
		return array(
			array( 'min' => null, 'max' => $cut, 'label' => PriceBuckets::derive_label( null, $cut ) ),
			array( 'min' => $cut, 'max' => null, 'label' => PriceBuckets::derive_label( $cut, null ) ),
		);
	}
}
