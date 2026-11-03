<?php
/**
 * Structural tests for authoritative PUDO persistence.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Integrations\Bluex\Pudo;

use Akibara\Business\Integrations\Bluex\Pudo\PudoMutationRepository;
use Akibara\Business\Integrations\Bluex\Pudo\WpOptionsPudoMutationRepository;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class WpOptionsPudoMutationRepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// El repositorio purga el object cache al auto-repararse. Sin declarar la
		// dependencia acá, el test sólo pasaba si OTRO la había definido antes
		// [ronda 7.1].
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// El recorte quirúrgico de `notoptions` lee y reescribe el índice.
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should use non-autoload HMAC rows and exact SQL compare-and-swap.
	 */
	public function test_repository_uses_exact_direct_sql_contract(): void {
		$source = file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Integrations/Bluex/Pudo/WpOptionsPudoMutationRepository.php'
		);

		self::assertIsString( $source );
		// El namespace de las claves vive en el CONTRATO, no en cada extremo: el
		// guard construye la clave y el repositorio la valida, y con dos copias
		// privadas cambiar una dejaba a la otra rechazando toda clave legítima.
		self::assertSame( 'akb_pudo_state_', PudoMutationRepository::OPTION_PREFIX );
		self::assertStringNotContainsString(
			"const OPTION_PREFIX = 'akb_pudo_state_'",
			$source,
			'El repositorio consume el prefijo del contrato, no lo redeclara.'
		);
		self::assertStringContainsString( 'INSERT IGNORE INTO', $source );
		self::assertStringContainsString( "autoload = 'off'", $source );
		self::assertStringContainsString(
			'WHERE option_name = %s AND option_value = %s',
			$source
		);
		self::assertStringContainsString( 'delete_malformed_if_unchanged( $key, $value )', $source );
		self::assertStringContainsString( 'Unable to repair malformed PUDO state.', $source );
		self::assertStringContainsString( 'CAST(LEFT(option_value, %d) AS UNSIGNED) <= %d', $source );
		self::assertStringNotContainsString( 'get_option(', $source );
		self::assertStringNotContainsString( 'update_option(', $source );
		self::assertStringNotContainsString( 'set_transient(', $source );
		self::assertStringNotContainsString( 'woocommerce_sessions', $source );
		self::assertStringNotContainsString( 'GET_LOCK', $source );
	}

	/**
	 * @testdox Should keep attempt IDs as HMAC correlation only, never as ordering authority.
	 */
	public function test_guard_never_orders_by_client_attempt_id(): void {
		$source = file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Integrations/Bluex/Pudo/PudoMutationGuard.php'
		);

		self::assertIsString( $source );
		self::assertStringContainsString( "hash_hmac( 'sha256', \$attempt_id", $source );
		self::assertStringNotContainsString( 'strcmp( $attempt_id', $source );
		self::assertStringNotContainsString( 'issued_at_ms', $source );
		self::assertStringNotContainsString( 'Date.now', $source );
	}

	/**
	 * @testdox Should heal an exact malformed row before a new generation is inserted.
	 */
	public function test_self_heals_malformed_row_before_insert(): void {
		$key = 'akb_pudo_state_' . str_repeat( 'a', 64 );
		$wpdb = new PudoWpdbStub();
		$wpdb->values[ $key ] = 'malformed-without-expiry-delimiter';

		$this->with_wpdb(
			$wpdb,
			static function () use ( $key, $wpdb ): void {
				$sut = new WpOptionsPudoMutationRepository();
				self::assertNull( $sut->get( $key ) );
				self::assertArrayNotHasKey( $key, $wpdb->values );

				$record = array( 'phase' => 'pending', 'expires_at' => 500 );
				self::assertTrue( $sut->create_if_absent( $key, $record ) );
				self::assertSame( $record, $sut->get( $key ) );
			}
		);
	}

	/**
	 * @testdox Should never delete a valid generation renewed after a malformed read.
	 */
	public function test_stale_malformed_repair_preserves_renewed_row(): void {
		$key = 'akb_pudo_state_' . str_repeat( 'b', 64 );
		$renewed = array( 'phase' => 'cleared', 'expires_at' => 900 );
		$renewed_raw = str_pad( '900', 12, '0', STR_PAD_LEFT ) . '|' . json_encode( $renewed );
		$wpdb = new PudoWpdbStub();
		$wpdb->values[ $key ] = 'stale-malformed';
		$wpdb->renew_after_read[ $key ] = $renewed_raw;

		$this->with_wpdb(
			$wpdb,
			static function () use ( $key, $renewed, $renewed_raw, $wpdb ): void {
				$sut = new WpOptionsPudoMutationRepository();
				self::assertNull( $sut->get( $key ) );
				self::assertSame(
					$renewed_raw,
					$wpdb->values[ $key ],
					'The exact raw predicate must make a stale repair lose to the renewed row.'
				);

				unset( $wpdb->renew_after_read[ $key ] );
				self::assertSame( $renewed, $sut->get( $key ) );
			}
		);
	}

	/**
	 * Escribir el estado PUDO no puede tirar el índice `notoptions` entero.
	 *
	 * Este código corre en el camino caliente del checkout. Borrar `notoptions`
	 * completo obliga al resto de la petición a volver a la base por CADA option
	 * ausente que consulte, un gasto que la casa ya midió y selló como caro. Sólo
	 * la clave recién escrita sale del índice.
	 */
	public function test_writing_state_evicts_only_the_touched_key_from_notoptions(): void {
		$key   = PudoMutationRepository::OPTION_PREFIX . str_repeat( 'c', 64 );
		$cache = array(
			'notoptions' => array(
				$key                     => true,
				'woocommerce_otra_cosa'  => true,
				'akb_bis_capture_enabled' => true,
			),
		);
		$borradas = array();

		Functions\when( 'wp_cache_delete' )->alias(
			static function ( string $clave, string $grupo ) use ( &$cache, &$borradas ): bool {
				$borradas[] = $clave;
				unset( $cache[ $clave ] );
				unset( $grupo );

				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			static fn( string $clave ) => $cache[ $clave ] ?? false
		);
		Functions\when( 'wp_cache_set' )->alias(
			static function ( string $clave, $valor ) use ( &$cache ): bool {
				$cache[ $clave ] = $valor;

				return true;
			}
		);

		$wpdb = new PudoWpdbStub();
		$this->with_wpdb(
			$wpdb,
			static function () use ( $key, &$cache, &$borradas ): void {
				$sut = new WpOptionsPudoMutationRepository();
				self::assertTrue(
					$sut->create_if_absent( $key, array( 'phase' => 'pending', 'expires_at' => 500 ) )
				);

				self::assertNotContains(
					'notoptions',
					$borradas,
					'El índice completo de opciones ausentes no puede borrarse.'
				);
				self::assertSame(
					array( 'woocommerce_otra_cosa' => true, 'akb_bis_capture_enabled' => true ),
					$cache['notoptions'],
					'Sólo la clave escrita sale del índice; el resto sobrevive.'
				);
			}
		);
	}

	/**
	 * Runs one repository assertion against an isolated wpdb double.
	 *
	 * @param PudoWpdbStub $wpdb     Database double.
	 * @param callable     $callback Assertion callback.
	 */
	private function with_wpdb( PudoWpdbStub $wpdb, callable $callback ): void {
		$had_previous = array_key_exists( 'wpdb', $GLOBALS );
		$previous     = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $wpdb;

		try {
			$callback();
		} finally {
			if ( $had_previous ) {
				$GLOBALS['wpdb'] = $previous;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}
}

final class PudoWpdbStub {

	public string $options = 'wp_options';

	/** @var array<string,string> */
	public array $values = array();

	/** @var array<string,string> */
	public array $renew_after_read = array();

	/**
	 * @return array{query:string,args:array<int,mixed>}
	 */
	public function prepare( string $query, ...$args ): array {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	/**
	 * @param array{query:string,args:array<int,mixed>} $prepared Prepared query.
	 * @return string|null
	 */
	public function get_var( array $prepared ): ?string {
		$key   = (string) ( $prepared['args'][0] ?? '' );
		$value = $this->values[ $key ] ?? null;
		if ( is_string( $value ) && isset( $this->renew_after_read[ $key ] ) ) {
			$this->values[ $key ] = $this->renew_after_read[ $key ];
		}

		return $value;
	}

	/**
	 * @param array{query:string,args:array<int,mixed>} $prepared Prepared query.
	 * @return int
	 */
	public function query( array $prepared ): int {
		$query = $prepared['query'];
		$args  = $prepared['args'];
		if ( false !== strpos( $query, 'INSERT IGNORE INTO' ) ) {
			$key = (string) $args[0];
			if ( isset( $this->values[ $key ] ) ) {
				return 0;
			}

			$this->values[ $key ] = (string) $args[1];
			return 1;
		}

		if ( false !== strpos( $query, 'WHERE option_name = %s AND option_value = %s' ) ) {
			$key = (string) $args[0];
			$raw = (string) $args[1];
			if ( ( $this->values[ $key ] ?? null ) !== $raw ) {
				return 0;
			}

			unset( $this->values[ $key ] );
			return 1;
		}

		throw new \RuntimeException( 'Unexpected wpdb query in PUDO repository test.' );
	}
}
