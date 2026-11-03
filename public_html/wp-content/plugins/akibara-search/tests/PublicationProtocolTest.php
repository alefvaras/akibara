<?php
/**
 * Contratos focales de publicación durable, buckets y purge REST.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\DocumentBuilder;
use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\FacetRegistry;
use Akibara\Search\WriteFence;
use Akibara\Search\WorkRepository;
use Akibara\Search\ProductSourceAuthority;
use Akibara\Search\Indexer;
use Akibara\Search\Install;
use Akibara\Search\PriceBuckets;
use Akibara\Search\SemanticPublication;
use Akibara\Search\Synonyms;
use Akibara\Search\Support\RestCache;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/FacetRegistry.php';
require_once dirname( __DIR__ ) . '/src/Support/RestCache.php';
require_once dirname( __DIR__ ) . '/src/PriceBuckets.php';

/** Doble mínimo para SELECT de precios. */
final class PriceBucketsWpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';

	/** @var array<int, mixed> */
	private array $prices;

	private string $error;

	/** @param array<int, mixed> $prices Precios. */
	public function __construct( array $prices, string $error = '' ) {
		$this->prices = $prices;
		$this->error  = $error;
	}

	/** @return array<int, mixed> */
	public function get_col( string $query ): array {
		unset( $query );
		$this->last_error = $this->error;
		return $this->prices;
	}
}

/** Doble con option SQL directa y advisory lock para consumir el outbox. */
final class PublicationWpdb {

	public string $prefix = 'wp_';

	public string $options = 'wp_options';

	public string $last_error = '';

	public bool $in_transaction = false;

	/** @var array<int, string> */
	public array $queries = array();

	/** @var array<string, mixed> */
	private $option_store;

	/** @param array<string, mixed> $option_store Options compartidas con los mocks WP. */
	public function __construct( array &$option_store ) {
		$this->option_store = &$option_store;
	}

	/** @param mixed ...$args Argumentos SQL. */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}

	/** @return string|null */
	public function get_var( string $query ): ?string {
		$this->queries[]  = $query;
		$this->last_error = '';
		if ( false !== stripos( $query, '@@in_transaction' ) ) {
			return $this->in_transaction ? '1' : '0';
		}
		if ( false !== stripos( $query, 'GET_LOCK(' ) ) {
			return '1';
		}
		if ( false !== stripos( $query, 'CONNECTION_ID()' ) || false !== stripos( $query, 'IS_USED_LOCK(' ) ) {
			return '37';
		}
		if ( false !== stripos( $query, 'RELEASE_LOCK(' ) ) {
			return '1';
		}
		if ( preg_match( "/option_name = '([^']+)'/", $query, $matches ) ) {
			$name = str_replace( "''", "'", $matches[1] );
			return array_key_exists( $name, $this->option_store )
				? serialize( $this->option_store[ $name ] )
				: null;
		}

		return null;
	}

	/** @return array<int, object> */
	public function get_results( string $query ): array {
		unset( $query );
		$this->last_error = '';
		$rows             = array();
		foreach ( SemanticPublication::options() as $name ) {
			if ( ! array_key_exists( $name, $this->option_store ) ) {
				continue;
			}
			$value  = $this->option_store[ $name ];
			$rows[] = (object) array(
				'option_name'  => $name,
				'option_value' => is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value,
			);
		}
		return $rows;
	}
}

/** Simula un save MANUAL que gana después del SELECT de derivación. */
final class PriceBucketsRaceWpdb {

	public string $prefix = 'wp_';

	public string $options = 'wp_options';

	public string $last_error = '';

	/** @var array<int, string> */
	public array $queries = array();

	/** @var array<string, mixed> */
	private $option_store;

	/** @param array<string, mixed> $option_store Options compartidas. */
	public function __construct( array &$option_store ) {
		$this->option_store = &$option_store;
	}

	/** @return array<int, int> */
	public function get_col( string $query ): array {
		$this->queries[] = $query;
		$this->option_store[ PriceBuckets::OPTION_MODE ] = 'manual';
		$this->option_store[ FacetRegistry::OPTION_PRECIO_BUCKETS ] = array(
			array( 'min' => null, 'max' => 15000, 'label' => 'Manual' ),
			array( 'min' => 15000, 'max' => null, 'label' => 'Manual alto' ),
		);
		return array( 1000, 10000, 20000, 40000 );
	}

	/** @param mixed ...$args Argumentos SQL. */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace( '/%s/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function query( string $query ): int {
		$this->queries[]  = $query;
		$this->last_error = '';
		return 0;
	}

	/** @return object|null */
	public function get_row( string $query ): ?object {
		$this->queries[]  = $query;
		$this->last_error = '';
		if ( ! preg_match( "/option_name = '([^']+)'/", $query, $matches ) ) {
			return null;
		}
		$name = str_replace( "''", "'", $matches[1] );
		if ( ! array_key_exists( $name, $this->option_store ) ) {
			return null;
		}
		$value = $this->option_store[ $name ];
		return (object) array(
			'option_value' => is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value,
			'autoload'     => 'no',
		);
	}

	/** @return string|null */
	public function get_var( string $query ): ?string {
		$this->queries[]  = $query;
		$this->last_error = '';
		if ( preg_match( "/option_name = '([^']+)'/", $query, $matches ) ) {
			$name = str_replace( "''", "'", $matches[1] );
			if ( ! array_key_exists( $name, $this->option_store ) ) {
				return null;
			}
			$value = $this->option_store[ $name ];
			return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
		}
		return null;
	}
}

/**
 * @covers \Akibara\Search\Install
 * @covers \Akibara\Search\PriceBuckets
 * @covers \Akibara\Search\Support\RestCache
 */
final class PublicationProtocolTest extends TestCase {
	use \Akibara\Search\Tests\Support\MarcosEditorialesVigilados;


	/** Runtime estático previo, restaurado en tearDown. */
	private ?EditorialDiscountPublication $runtime_original = null;

	/** @var array<string, mixed> */
	private array $options = array();

	private int $purges = 0;

	private int $deletes = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$runtime = new \ReflectionProperty( EditorialDiscountPublication::class, 'runtime' );
		$runtime->setAccessible( true );
		$this->runtime_original = $runtime->getValue();

		$this->options = array();
		$this->purges  = 0;
		$this->deletes = 0;

		$options = &$this->options;
		$purges  = &$this->purges;
		$deletes = &$this->deletes;
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( string $name, $value ) use ( &$options ): bool {
				if ( array_key_exists( $name, $options ) ) {
					return false;
				}
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$options ): bool {
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) use ( &$options, &$deletes ): bool {
				++$deletes;
				if ( ! array_key_exists( $name, $options ) ) {
					return false;
				}
				unset( $options[ $name ] );
				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			static function ( string $hook ) use ( &$purges ): void {
				if ( 'litespeed_purge' === $hook ) {
					++$purges;
				}
			}
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( '11111111-1111-4111-8111-111111111111' );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		// Primero, con los stubs montados: un marco abierto no puede cerrarse al apagar PHP.
		$this->soltar_publicaciones_vigiladas();
		// Construir `EditorialDiscountPublication` fija `self::$runtime`, un
		// estático que sobrevive al test y que los archivos siguientes leen como
		// si fuera suyo —con un `wpdb` ya desmontado—. Se restaura lo que había.
		$runtime = new \ReflectionProperty( EditorialDiscountPublication::class, 'runtime' );
		$runtime->setAccessible( true );
		$runtime->setValue( null, $this->runtime_original );

		unset( $GLOBALS['wpdb'] );
		FacetRegistry::reset_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Init sin outbox confirma ausencia con una lectura y omite TX y named lock.
	 */
	public function test_absent_generation_outbox_hot_path_skips_transaction_and_fence(): void {
		$wpdb            = new PublicationWpdb( $this->options );
		$GLOBALS['wpdb'] = $wpdb;

		self::assertTrue( Install::consume_generation_publications() );

		$queries = implode( "\n", $wpdb->queries );
		self::assertCount( 1, $wpdb->queries );
		self::assertStringContainsString( 'wp_options', $queries );
		self::assertStringNotContainsString( '@@in_transaction', $queries );
		self::assertStringNotContainsString( 'GET_LOCK(', $queries );
	}

	/**
	 * @testdox SELECT fallido no se convierte en buckets vacíos ni borra curaduría.
	 */
	public function test_price_bucket_select_failure_never_deletes_option(): void {
		$GLOBALS['wpdb'] = new PriceBucketsWpdb( array(), 'facets select failed' );
		$this->options[ FacetRegistry::OPTION_PRECIO_BUCKETS ] = array( array( 'min' => null, 'max' => 10000, 'label' => 'curado' ) );

		self::assertFalse( PriceBuckets::refresh() );
		self::assertSame( 0, $this->deletes );
		self::assertSame( 'curado', $this->options[ FacetRegistry::OPTION_PRECIO_BUCKETS ][0]['label'] );
	}

	/**
	 * @testdox El write AUTO condicional pierde ante modo MANUAL aparecido tras derivar.
	 */
	public function test_auto_price_bucket_write_never_overwrites_concurrent_manual_save(): void {
		$this->options[ PriceBuckets::OPTION_MODE ] = 'auto';
		$GLOBALS['wpdb'] = new PriceBucketsRaceWpdb( $this->options );

		self::assertSame( 0, PriceBuckets::refresh( true, false ) );
		self::assertSame( 'manual', $this->options[ PriceBuckets::OPTION_MODE ] );
		self::assertSame( 'Manual', $this->options[ FacetRegistry::OPTION_PRECIO_BUCKETS ][0]['label'] );
		$queries = implode( "\n", $GLOBALS['wpdb']->queries );
		self::assertStringContainsString( 'FOR UPDATE', $queries, 'La decisión AUTO/MANUAL debe usar la foto bloqueada.' );
		self::assertStringNotContainsString( 'INSERT INTO wp_options', $queries, 'MANUAL ganador impide escribir cualquiera de las dos filas.' );
	}

	/**
	 * @testdox PriceBuckets no muta si no puede persistir primero su intención de publicación.
	 */
	public function test_price_buckets_fail_closed_before_mutation_without_outbox(): void {
		$this->options[ Install::OPTION_GENERATION_OUTBOX ] = array( 'protocol' => 999, 'unknown' => 'debt' );
		$GLOBALS['wpdb'] = new PriceBucketsRaceWpdb( $this->options );

		self::assertFalse( PriceBuckets::refresh( true, true ) );
		self::assertStringNotContainsString( 'START TRANSACTION', implode( "\n", $GLOBALS['wpdb']->queries ) );
		self::assertFalse( PriceBuckets::manages_publication_context(), 'El guard debe liberarse también al abortar.' );
	}

	/**
	 * @testdox Purge persiste receipt local por target y su replay no duplica dispatch.
	 */
	public function test_rest_purge_receipt_is_verified_and_idempotent(): void {
		self::assertTrue( RestCache::purge( 'generation-7' ) );
		self::assertTrue( RestCache::has_purge_receipt( 'generation-7' ) );
		self::assertSame( 1, $this->purges );

		self::assertTrue( RestCache::purge( 'generation-7' ) );
		self::assertSame( 1, $this->purges );
		self::assertSame( 'local_dispatch_only', $this->options[ RestCache::OPTION_PURGE_RECEIPT ]['ack_scope'] );
	}

	/**
	 * @testdox Outbox semántico incrementa wanted_seq y conserva deuda hasta ACK durable.
	 */
	public function test_generation_publication_outbox_coalesces_by_sequence(): void {
		$first  = Install::request_generation_publication( 'config_synonyms', 'generation-a' );
		$second = Install::request_generation_publication( 'config_facets', 'generation-b' );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( $first['token'], $second['token'] );
		self::assertSame( $first['seq'] + 1, $second['seq'] );
		self::assertSame( 'generation-b', $second['target'] );
		self::assertFalse( Install::generation_publication_satisfied( $first ) );
		self::assertFalse( Install::consume_generation_publications(), 'Sin conexión/fence la deuda debe conservarse.' );
		self::assertSame( 2, $this->options[ Install::OPTION_GENERATION_OUTBOX ]['wanted_seq'] );
		self::assertSame( 0, $this->options[ Install::OPTION_GENERATION_OUTBOX ]['acked_seq'] );
	}

	/**
	 * @testdox Barreras pre-write concurrentes sólo se adelantan al confirmar su mismo target.
	 */
	public function test_delayed_publication_barriers_are_correlated_by_target(): void {
		$pre_a = Install::request_generation_publication( 'pre_config_a', null, 10 );
		$pre_b = Install::request_generation_publication( 'pre_config_b', null, 20 );
		self::assertIsArray( $pre_a );
		self::assertIsArray( $pre_b );
		self::assertCount( 2, $this->options[ Install::OPTION_GENERATION_OUTBOX ]['barriers'] );
		self::assertGreaterThan( time(), $this->options[ Install::OPTION_GENERATION_OUTBOX ]['not_before'] );

		$post_a = Install::request_generation_publication( 'post_config_a', $pre_a['target'], 0 );
		self::assertIsArray( $post_a );
		self::assertArrayNotHasKey( $pre_a['target'], $this->options[ Install::OPTION_GENERATION_OUTBOX ]['barriers'] );
		self::assertArrayHasKey( $pre_b['target'], $this->options[ Install::OPTION_GENERATION_OUTBOX ]['barriers'] );
		self::assertGreaterThan( time(), $post_a['not_before'], 'Confirmar A no puede adelantar el pre-write B.' );

		$post_b = Install::request_generation_publication( 'post_config_b', $pre_b['target'], 0 );
		self::assertIsArray( $post_b );
		self::assertSame( array(), $this->options[ Install::OPTION_GENERATION_OUTBOX ]['barriers'] );
		self::assertSame( 0, $post_b['not_before'] );
		self::assertSame( $pre_b['seq'] + 2, $post_b['seq'] );
	}

	/**
	 * @testdox Un ACK lógico repara generación o receipt perdidos antes de converger.
	 */
	public function test_acked_generation_fast_path_republishes_missing_physical_effect(): void {
		$target = 'generation-durable-9';
		$this->options[ Install::OPTION_GENERATION_OUTBOX ] = array(
			'protocol'     => 1,
			'token'        => 'publication-stream',
			'wanted_seq'   => 9,
			'acked_seq'    => 9,
			'target'       => $target,
			'reason'       => 'live_index',
			'requested_at' => 123,
			'ack_target'   => $target,
			'acked_at'     => 124,
		);
		$this->options[ Install::OPTION_INDEX_GEN ] = 'overwritten-generation';
		$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );

		self::assertTrue( Install::consume_generation_publications() );
		self::assertSame( $target, $this->options[ Install::OPTION_INDEX_GEN ] );
		self::assertTrue( RestCache::has_purge_receipt( $target ) );
		self::assertSame( 1, $this->purges );

		unset( $this->options[ RestCache::OPTION_PURGE_RECEIPT ] );
		self::assertTrue( Install::consume_generation_publications() );
		self::assertSame( 2, $this->purges, 'Receipt perdido debe re-despachar purge para el mismo target.' );
	}

	/**
	 * @testdox Un outbox ya convergido omite TX y named lock en el hot path de init.
	 *
	 * Se registró en `init:22` de TODA petición: 11 consultas y 2,42 ms medidos
	 * el 2026-08-23 en Docker con SAVEQUERIES pre-boot (outbox, @@in_transaction,
	 * GET_LOCK, CONNECTION_ID ×2, IS_USED_LOCK ×2, RELEASE_LOCK, relectura del
	 * outbox, index_gen y purge_receipt) con `wanted_seq === acked_seq` en prod y
	 * en local — el fence se tomaba para nada. La invariante que fija este test
	 * NO es el número de consultas sino que un estado convergido no toque el
	 * fence, y que la convergencia se verifique contra el efecto FÍSICO: el
	 * hermano `test_acked_generation_fast_path_republishes_missing_physical_effect`
	 * fija que un receipt o una option perdidos siguen cayendo a la reparación.
	 */
	public function test_converged_generation_outbox_hot_path_skips_transaction_and_fence(): void {
		$target = 'generation-durable-11';
		$this->options[ Install::OPTION_GENERATION_OUTBOX ] = array(
			'protocol'     => 1,
			'token'        => 'publication-stream',
			'wanted_seq'   => 11,
			'acked_seq'    => 11,
			'target'       => $target,
			'reason'       => 'live_index',
			'requested_at' => 123,
			'ack_target'   => $target,
			'acked_at'     => 124,
		);
		$wpdb            = new PublicationWpdb( $this->options );
		$GLOBALS['wpdb'] = $wpdb;

		// Primera pasada: aún falta el efecto físico, así que repara bajo fence.
		self::assertTrue( Install::consume_generation_publications() );
		self::assertSame( $target, $this->options[ Install::OPTION_INDEX_GEN ] );
		self::assertTrue( RestCache::has_purge_receipt( $target ) );
		$fenced = implode( "\n", $wpdb->queries );
		self::assertStringContainsString( 'GET_LOCK(', $fenced, 'La reparación sí necesita el fence.' );

		// Segunda pasada: el mismo estado, ya convergido y verificado.
		$purges_tras_reparar = $this->purges;
		$wpdb->queries       = array();
		self::assertTrue( Install::consume_generation_publications() );
		$queries = implode( "\n", $wpdb->queries );
		self::assertStringNotContainsString( '@@in_transaction', $queries );
		self::assertStringNotContainsString( 'GET_LOCK(', $queries );
		self::assertStringNotContainsString( 'RELEASE_LOCK(', $queries );
		self::assertSame( $purges_tras_reparar, $this->purges, 'Converger no puede volver a purgar.' );
	}

	/**
	 * @testdox Una barrera vigente nunca se salta el fence aunque el seq cuadre.
	 */
	public function test_pending_barrier_never_takes_the_converged_shortcut(): void {
		$target = 'generation-durable-12';
		$this->options[ Install::OPTION_GENERATION_OUTBOX ] = array(
			'protocol'     => 1,
			'token'        => 'publication-stream',
			'wanted_seq'   => 12,
			'acked_seq'    => 12,
			'target'       => $target,
			'reason'       => 'live_index',
			'requested_at' => 123,
			'ack_target'   => $target,
			'acked_at'     => 124,
			'barriers'     => array( $target => time() + 300 ),
		);
		$this->options[ Install::OPTION_INDEX_GEN ] = $target;
		$wpdb            = new PublicationWpdb( $this->options );
		$GLOBALS['wpdb'] = $wpdb;

		self::assertFalse( Install::consume_generation_publications(), 'La barrera conserva la deuda.' );
		self::assertStringContainsString( 'GET_LOCK(', implode( "\n", $wpdb->queries ) );
	}

	/**
	 * @testdox Outbox corrupto falla cerrado y no se interpreta como ausencia.
	 */
	public function test_corrupt_generation_outbox_is_not_overwritten(): void {
		$this->options[ Install::OPTION_GENERATION_OUTBOX ] = array( 'protocol' => 999, 'unknown' => 'debt' );

		self::assertFalse( Install::request_generation_publication( 'config_change' ) );
		self::assertSame( 999, $this->options[ Install::OPTION_GENERATION_OUTBOX ]['protocol'] );
	}

	/**
	 * @testdox Un cambio semántico crea deuda pre-write, confirma el mismo target y sella fingerprint sólo tras purge.
	 */
	public function test_semantic_update_is_published_and_fingerprinted_after_source_write(): void {
		$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );
		$protocol        = new SemanticPublication();
		$old             = array( array( 'opm', 'one punch man' ) );
		$new             = array( array( 'opm', 'one punch-man' ) );

		self::assertSame( $new, $protocol->filter_pre_update( $new, Synonyms::OPTION, $old ) );
		self::assertGreaterThan( time(), $this->options[ Install::OPTION_GENERATION_OUTBOX ]['not_before'] );

		$this->options[ Synonyms::OPTION ] = $new; // Source write de Core.
		$protocol->after_update( Synonyms::OPTION, $old, $new );

		$outbox = $this->options[ Install::OPTION_GENERATION_OUTBOX ];
		self::assertSame( $outbox['wanted_seq'], $outbox['acked_seq'] );
		self::assertSame( array(), $outbox['barriers'] );
		self::assertSame( $outbox['target'], $this->options[ Install::OPTION_INDEX_GEN ] );
		self::assertSame(
			SemanticPublication::current_fingerprint(),
			$this->options[ SemanticPublication::OPTION_FINGERPRINT ]['fingerprint'] ?? null
		);
		self::assertTrue( RestCache::has_purge_receipt( (string) $outbox['target'] ) );
	}

	/**
	 * @testdox Update sin cambio no crea deuda y outbox corrupto impide el source write.
	 */
	public function test_semantic_update_is_noop_when_equal_and_fails_closed_without_pre_intent(): void {
		$protocol = new SemanticPublication();
		$value    = array( array( 'aot', 'attack on titan' ) );

		self::assertSame( $value, $protocol->filter_pre_update( $value, Synonyms::OPTION, $value ) );
		self::assertArrayNotHasKey( Install::OPTION_GENERATION_OUTBOX, $this->options );

		$this->options[ Install::OPTION_GENERATION_OUTBOX ] = array( 'protocol' => 999 );
		self::assertSame(
			$value,
			$protocol->filter_pre_update( array( array( 'snk', 'shingeki' ) ), Synonyms::OPTION, $value ),
			'El filtro debe devolver el valor previo para que Core omita el UPDATE.'
		);
	}

	/**
	 * `add_option` y `delete_option` son acciones: no se pueden vetar, y la única
	 * respuesta a una intención que no se pudo persistir era una excepción dentro
	 * de un `do_action` — la petición entera del dueño al suelo por un hipo de la
	 * base. El lado editorial lo cerró en septiembre; éste se quedó atrás
	 * (hallazgo S-caa820de, 2026-09-19).
	 *
	 * @testdox Un alta o una baja que no se puede cercar NO tumba la petición: se anota y el reconcile monta la deuda.
	 */
	public function test_an_unfenced_add_or_delete_does_not_throw(): void {
		$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );
		$protocol        = new SemanticPublication();

		// El outbox ilegible hace fallar `stage()`: es el mismo doble que usa la
		// prueba del veto del update, tres métodos más arriba.
		$this->options[ Install::OPTION_GENERATION_OUTBOX ] = array( 'protocol' => 999 );

		$protocol->before_add( Synonyms::OPTION, array( array( 'jjk', 'jujutsu kaisen' ) ) );
		$protocol->before_delete( Synonyms::OPTION );

		$cuaderno = wp_json_encode( $this->options[ \Akibara\Search\AuditLog::OPTION_KEY ] ?? array() );
		self::assertStringContainsString( 'option_semantica_sin_cercar', (string) $cuaderno, 'Sin excepción, pero no en silencio.' );
		self::assertSame( 2, substr_count( (string) $cuaderno, 'option_semantica_sin_cercar' ), 'Una entrada por el alta y otra por la baja.' );
	}

	/**
	 * Revisión independiente 2026-09-19 (MEDIA): la publicación posterior y el
	 * reconcile de la huella sólo invalidan cachés. El espejo de filtros lo
	 * reconstruye un rebuild, y ése sólo lo pedía `stage()`: si `stage()`
	 * fallaba, el alta pasaba y el índice seguía con la definición vieja.
	 *
	 * @testdox Un alta de la definición de filtros que no se pudo cercar pide igual la reconstrucción del espejo.
	 */
	public function test_an_unfenced_facet_registry_write_still_requests_the_rebuild(): void {
		$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );
		$protocol        = new SemanticPublication();
		$definicion      = array( 'autor' => array( 'enabled' => true ) );

		$this->options[ Install::OPTION_GENERATION_OUTBOX ] = array( 'protocol' => 999 );
		$protocol->before_add( \Akibara\Search\FacetRegistry::OPTION_KEY, $definicion );
		self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->options, 'Precondición: `stage()` falló antes de pedir nada.' );

		// WordPress escribe la option y dispara el gancho posterior.
		$this->options[ \Akibara\Search\FacetRegistry::OPTION_KEY ] = $definicion;
		$protocol->after_add( \Akibara\Search\FacetRegistry::OPTION_KEY, $definicion );

		self::assertStringContainsString(
			'facet_registry_changed',
			(string) wp_json_encode( $this->options[ Install::OPTION_PENDING_REBUILD ] ?? null ),
			'Sin esto el espejo de filtros seguía armado con la definición anterior hasta que algo ajeno lo reconstruyera.'
		);
	}

	/** @testdox Un ajuste que no define el espejo (sinónimos) no pide reconstruirlo. */
	public function test_an_unfenced_synonyms_write_does_not_request_a_rebuild(): void {
		$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );
		$protocol        = new SemanticPublication();
		$valor           = array( array( 'jjk', 'jujutsu kaisen' ) );

		$this->options[ Install::OPTION_GENERATION_OUTBOX ] = array( 'protocol' => 999 );
		$protocol->before_add( Synonyms::OPTION, $valor );
		$this->options[ Synonyms::OPTION ] = $valor;
		$protocol->after_add( Synonyms::OPTION, $valor );

		self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->options );
	}

	/**
	 * @testdox El fallback update→add reutiliza una sola barrera pre-write.
	 */
	public function test_update_to_add_fallback_reuses_correlated_barrier(): void {
		$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );
		$protocol        = new SemanticPublication();
		$value           = array( array( 'jjk', 'jujutsu kaisen' ) );

		$protocol->filter_pre_update( $value, Synonyms::OPTION, false );
		$protocol->before_add( Synonyms::OPTION, $value );
		self::assertSame( 1, $this->options[ Install::OPTION_GENERATION_OUTBOX ]['wanted_seq'] );

		$this->options[ Synonyms::OPTION ] = $value;
		$protocol->after_add( Synonyms::OPTION, $value );
		self::assertSame( 2, $this->options[ Install::OPTION_GENERATION_OUTBOX ]['wanted_seq'] );
		self::assertSame( 2, $this->options[ Install::OPTION_GENERATION_OUTBOX ]['acked_seq'] );
	}

	/**
	 * @testdox Updates anidados de la misma option confirman targets LIFO sin adelantar la barrera exterior.
	 */
	public function test_nested_semantic_updates_use_lifo_targets(): void {
		$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );
		$protocol        = new SemanticPublication();
		$outer           = array( array( 'outer', 'a' ) );
		$inner           = array( array( 'inner', 'b' ) );

		$protocol->filter_pre_update( $outer, Synonyms::OPTION, array() );
		$protocol->filter_pre_update( $inner, Synonyms::OPTION, $outer );
		$targets      = array_keys( $this->options[ Install::OPTION_GENERATION_OUTBOX ]['barriers'] );
		$outer_target = $targets[0];

		$this->options[ Synonyms::OPTION ] = $inner;
		$protocol->after_update( Synonyms::OPTION, $outer, $inner );
		self::assertArrayHasKey( $outer_target, $this->options[ Install::OPTION_GENERATION_OUTBOX ]['barriers'] );

		$this->options[ Synonyms::OPTION ] = $outer;
		$protocol->after_update( Synonyms::OPTION, array(), $outer );
		self::assertSame( array(), $this->options[ Install::OPTION_GENERATION_OUTBOX ]['barriers'] );
		self::assertSame( $outer_target, $this->options[ Install::OPTION_INDEX_GEN ] );
	}

	/**
	 * @testdox Un post dentro de transacción conserva la deuda; init la publica luego del COMMIT.
	 */
	public function test_semantic_post_inside_transaction_remains_durable_until_reconcile(): void {
		$wpdb             = new PublicationWpdb( $this->options );
		$GLOBALS['wpdb']  = $wpdb;
		$protocol         = new SemanticPublication();
		$new              = array( array( 'hxh', 'hunter x hunter' ) );

		$protocol->filter_pre_update( $new, Synonyms::OPTION, array() );
		$this->options[ Synonyms::OPTION ] = $new;
		$wpdb->in_transaction = true;
		$protocol->after_update( Synonyms::OPTION, array(), $new );

		self::assertLessThan(
			$this->options[ Install::OPTION_GENERATION_OUTBOX ]['wanted_seq'],
			$this->options[ Install::OPTION_GENERATION_OUTBOX ]['acked_seq']
		);
		self::assertArrayNotHasKey( SemanticPublication::OPTION_FINGERPRINT, $this->options );

		$wpdb->in_transaction = false;
		self::assertTrue( $protocol->reconcile_fingerprint() );
		self::assertSame(
			SemanticPublication::current_fingerprint(),
			$this->options[ SemanticPublication::OPTION_FINGERPRINT ]['fingerprint'] ?? null
		);
	}

	/**
	 * @testdox Fingerprint detecta SQL directo y vuelve a publicar antes de sellar la nueva foto.
	 */
	public function test_fingerprint_reconciles_direct_sql_bypass(): void {
		$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );
		$protocol        = new SemanticPublication();
		$this->options[ Synonyms::OPTION ] = array( array( 'opm', 'one punch man' ) );

		self::assertTrue( $protocol->reconcile_fingerprint() );
		$first_fingerprint = $this->options[ SemanticPublication::OPTION_FINGERPRINT ]['fingerprint'];
		$first_generation  = $this->options[ Install::OPTION_INDEX_GEN ];

		// Bypass intencional de update_option/hooks: representa wp db query o restore.
		$this->options[ Synonyms::OPTION ] = array( array( 'opm', 'one punch-man' ) );
		self::assertTrue( $protocol->reconcile_fingerprint() );

		self::assertNotSame( $first_fingerprint, $this->options[ SemanticPublication::OPTION_FINGERPRINT ]['fingerprint'] );
		self::assertNotSame( $first_generation, $this->options[ Install::OPTION_INDEX_GEN ] );
		self::assertTrue( $protocol->reconcile_fingerprint(), 'Foto ya sellada debe ser fast-path sin nueva deuda.' );
	}

	/**
	 * @testdox Delete crash sin post converge por fingerprint cuando vence su barrera pre-write.
	 */
	public function test_delete_crash_is_recovered_after_prewrite_barrier_expires(): void {
		$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );
		$protocol        = new SemanticPublication();
		$this->options[ Synonyms::OPTION ] = array( array( 'aot', 'attack on titan' ) );

		$protocol->before_delete( Synonyms::OPTION );
		unset( $this->options[ Synonyms::OPTION ] ); // Crash: Core borró, post no corrió.
		$this->options[ Install::OPTION_GENERATION_OUTBOX ]['not_before'] = time() - 1;
		foreach ( $this->options[ Install::OPTION_GENERATION_OUTBOX ]['barriers'] as $target => $deadline ) {
			$this->options[ Install::OPTION_GENERATION_OUTBOX ]['barriers'][ $target ] = time() - 1;
		}

		self::assertTrue( $protocol->reconcile_fingerprint() );
		self::assertSame(
			SemanticPublication::current_fingerprint(),
			$this->options[ SemanticPublication::OPTION_FINGERPRINT ]['fingerprint'] ?? null
		);
	}

	/**
	 * @testdox Publicación externa sólo sella la foto tomada antes si target, purge y fingerprint siguen exactos.
	 */
	public function test_external_semantic_publication_seal_revalidates_physical_effect_and_fingerprint(): void {
		$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );
		$this->options[ Synonyms::OPTION ] = array( array( 'aot', 'attack on titan' ) );
		$fingerprint = SemanticPublication::current_fingerprint();

		self::assertIsString( $fingerprint );
		self::assertTrue( Install::publish_index_generation( 'external-generation-1' ) );
		self::assertTrue( SemanticPublication::seal_external_fingerprint( $fingerprint, 'external-generation-1' ) );

		$this->options[ Synonyms::OPTION ] = array( array( 'snk', 'shingeki no kyojin' ) );
		self::assertFalse(
			SemanticPublication::seal_external_fingerprint( $fingerprint, 'external-generation-1' ),
			'Una mutación posterior a la publicación no puede quedar acreditada por el receipt anterior.'
		);
	}

	/**
	 * @testdox Receipt semántico avanza con generaciones live verificadas y detecta restore parcial desalineado.
	 */
	public function test_semantic_receipt_tracks_current_physical_generation_and_rejects_partial_restore(): void {
		$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );
		$protocol        = new SemanticPublication();
		$this->options[ Synonyms::OPTION ] = array( array( 'jjk', 'jujutsu kaisen' ) );

		self::assertTrue( $protocol->reconcile_fingerprint() );
		$previous = (string) $this->options[ Install::OPTION_INDEX_GEN ];
		self::assertTrue( Install::publish_index_generation( 'live-generation-newer' ) );
		$protocol->after_update( Install::OPTION_INDEX_GEN, $previous, 'live-generation-newer' );
		self::assertSame(
			'live-generation-newer',
			$this->options[ SemanticPublication::OPTION_FINGERPRINT ]['generation'] ?? null,
			'Una invalidación live posterior debe encadenar el receipt sin otra publicación semántica.'
		);

		$wanted = $this->options[ Install::OPTION_GENERATION_OUTBOX ]['wanted_seq'];
		self::assertTrue( $protocol->reconcile_fingerprint() );
		self::assertSame( $wanted, $this->options[ Install::OPTION_GENERATION_OUTBOX ]['wanted_seq'] );

		// Restore parcial: config+fingerprint viejos, pero coordinación física de
		// generación/purge distinta. El fingerprint solo no puede dar fast-path.
		$this->options[ Install::OPTION_INDEX_GEN ] = 'restored-unrelated-generation';
		self::assertTrue( RestCache::purge( 'restored-unrelated-generation' ) );
		self::assertTrue( $protocol->reconcile_fingerprint() );
		self::assertNotSame( 'restored-unrelated-generation', $this->options[ Install::OPTION_INDEX_GEN ] );
		self::assertSame(
			$this->options[ Install::OPTION_INDEX_GEN ],
			$this->options[ SemanticPublication::OPTION_FINGERPRINT ]['generation'] ?? null
		);
	}
	/**
	 * Módulo real con sus tres colaboradores (la clase es `final`).
	 */
	private function publication_module(): EditorialDiscountPublication {
		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb'] = new PublicationWpdb( $this->options );
		}

		$fence   = new WriteFence( $GLOBALS['wpdb'] );
		$work    = new WorkRepository( $GLOBALS['wpdb'] );
		$builder = new DocumentBuilder();
		$source  = new ProductSourceAuthority( $GLOBALS['wpdb'], static fn( array $ids ): array => $ids );
		$indexer = new Indexer( $builder, $work, $fence, $source );

		return $this->vigilar_publicacion( new EditorialDiscountPublication( $work, $indexer, $fence ) );
	}

	/** Consultas acumuladas por el doble de wpdb. */
	private function query_count(): int {
		return count( (array) ( $GLOBALS['wpdb']->queries ?? array() ) );
	}

	/**
	 * @testdox La reconciliación de `init` se salta dentro de la ventana y vuelve fuera de ella.
	 *
	 * `reconcile_fingerprint()` cuesta 21 consultas —medido el 2026-08-23 sobre
	 * el carrito, la tienda y la portada con el object cache vacío— y las pagaba
	 * CADA petición del front sólo para confirmar, casi siempre, que no había
	 * nada que reconciliar. Lo que detecta es deriva por SQL directo o por una
	 * transición cortada, no operación normal: los caminos normales avisan por
	 * los hooks de termmeta y de option, que no pasan por aquí.
	 *
	 * Se mide el TRABAJO contra la base, que es lo que se ahorra. Se fija la
	 * propiedad —hay una cota y es configurable—, no el número de segundos.
	 */
	public function test_init_reconciliation_is_throttled_within_its_window(): void {
		$modulo = $this->publication_module();

		$antes = $this->query_count();
		$modulo->reconcile_on_init();
		$primera = $this->query_count() - $antes;
		self::assertGreaterThan( 0, $primera, 'La primera pasada sí va a la base.' );
		self::assertArrayHasKey( 'akb_search_editorial_reconcile_at', $this->options, 'Y deja la marca puesta.' );

		$antes = $this->query_count();
		$modulo->reconcile_on_init();
		self::assertSame( 0, $this->query_count() - $antes, 'Dentro de la ventana no se toca la base.' );

		$this->options['akb_search_editorial_reconcile_at'] = time() - 3600;
		$antes = $this->query_count();
		$modulo->reconcile_on_init();
		self::assertGreaterThan( 0, $this->query_count() - $antes, 'Pasada la ventana la deriva se vuelve a buscar.' );
	}

	/**
	 * El estrangulador sella la marca ANTES de reconciliar —correcto contra la
	 * estampida—, pero así cobraba la ventana entera aunque el intento no
	 * hubiera podido hacer nada. Y el caso en que más falla es el peor:
	 * `reconcile_fingerprint()` se rinde en cuanto no consigue el fence, y quien
	 * lo tiene es casi siempre el rebuild que disparó el propio cambio de
	 * configuración. Resultado medido en el espejo el 2026-09-02: el buscador se
	 * quedaba en 503 hasta el múltiplo siguiente de la ventana en vez de
	 * recuperarse cuando el fence quedaba libre.
	 *
	 * El criterio para distinguir es el ESTADO DEL SERVICIO, no el motivo del
	 * fallo: sirviendo bien, un false significa «no había nada que hacer» y la
	 * ventana se respeta entera; caído, se reintenta pronto.
	 *
	 * @testdox Con el buscador caído, un reconcile que no repara nada no se cobra la ventana entera.
	 */
	public function test_a_failed_reconcile_does_not_burn_the_window_while_serving_is_down(): void {
		$modulo = $this->publication_module();

		// Serving CAÍDO: el receipt se queda atrás del state, que es exactamente
		// lo que deja una escritura de configuración sin materializar.
		$state = json_decode( (string) ( $this->options[ EditorialDiscountPublication::OPTION_STATE ] ?? '' ), true );
		if ( is_array( $state ) ) {
			$state['wanted_seq'] = (int) ( $state['wanted_seq'] ?? 0 ) + 1;
			$this->options[ EditorialDiscountPublication::OPTION_STATE ] = wp_json_encode( $state );
		}
		self::assertFalse( EditorialDiscountPublication::serving_ready(), 'El arnés tiene que dejar el serving caído.' );

		$modulo->reconcile_on_init();
		$marca = (int) ( $this->options['akb_search_editorial_reconcile_at'] ?? 0 );

		// Lo que decide cuánto dura el 503 no es la marca en sí, sino cuánto
		// falta para que la ventana la deje pasar otra vez.
		$falta = ( $marca + 60 ) - time();

		self::assertLessThan(
			30,
			$falta,
			'Con el serving caído el próximo intento no puede quedar a un minuto: eso es el 503 que sufre el cliente.'
		);
		self::assertGreaterThan(
			0,
			$falta,
			'Pero tampoco cero: reconciliar en CADA petición cuesta CPU en hosting compartido.'
		);
	}

	/**
	 * @testdox Una marca con fecha futura no puede congelar la reconciliación.
	 */
	public function test_a_future_marker_never_freezes_reconciliation(): void {
		$modulo = $this->publication_module();

		// Un reloj movido hacia atrás dejaría una marca en el futuro; sin el
		// guard, la reconciliación quedaría apagada hasta alcanzarla.
		$this->options['akb_search_editorial_reconcile_at'] = time() + 86400;

		$antes = $this->query_count();
		$modulo->reconcile_on_init();
		self::assertGreaterThan( 0, $this->query_count() - $antes, 'Una marca imposible no puede apagar la vigilancia.' );
	}
	/**
	 * @testdox La huella de un termmeta acepta CUALQUIER tipo que WordPress sepa guardar.
	 *
	 * `maybe_serialize()` no serializa los escalares: devuelve el int, el bool o
	 * el null tal cual. Con `strict_types=1` eso no se coacciona al llegar a
	 * `hash()`, lanza un `TypeError` y tumba la petición.
	 *
	 * No era teórico: `TaxonomyDiscount\Settings::set_term_pct()` guarda el
	 * porcentaje con un `int` por firma, así que **guardar cualquier descuento
	 * mayor que cero en Akibara → Descuentos fataleaba en producción**, y el
	 * respaldo de la 0.1.23 ya traía el defecto. Reproducido el 2026-08-23 con
	 * `wp eval 'update_term_meta( 306, "_akb_taxonomy_discount_pct", 5 );'`.
	 */
	public function test_the_termmeta_hash_survives_every_storable_type(): void {
		$metodo = new \ReflectionMethod( EditorialDiscountPublication::class, 'value_hash' );
		$metodo->setAccessible( true );

		$valores = array(
			'entero'  => 5,
			'cero'    => 0,
			'flotante' => 5.5,
			'cadena'  => '5',
			'vacia'   => '',
			'nulo'    => null,
			'falso'   => false,
			'cierto'  => true,
			'array'   => array( 'a' => 1 ),
		);

		$huellas = array();
		foreach ( $valores as $nombre => $valor ) {
			$huella = $metodo->invoke( null, $valor );
			self::assertIsString( $huella, "El tipo «{$nombre}» no puede tumbar la petición." );
			self::assertSame( 64, strlen( $huella ) );
			$huellas[ $nombre ] = $huella;
		}

		// Una huella que colapse tipos distintos hace invisible un cambio real:
		// un `(string)` a secas convierte null, false y '' en la misma cadena.
		self::assertNotSame( $huellas['nulo'], $huellas['falso'], 'null y false son cambios distintos.' );
		self::assertNotSame( $huellas['nulo'], $huellas['vacia'], 'null y cadena vacía son cambios distintos.' );
		self::assertNotSame( $huellas['falso'], $huellas['vacia'], 'false y cadena vacía son cambios distintos.' );
		self::assertNotSame( $huellas['cero'], $huellas['vacia'], '0 y cadena vacía son cambios distintos.' );

		// Las cadenas y los arrays ya funcionaban: su huella NO puede cambiar, o
		// las comparaciones en vuelo verían una diferencia que no existe.
		self::assertSame( hash( 'sha256', '5' ), $huellas['cadena'] );
		self::assertSame( hash( 'sha256', serialize( array( 'a' => 1 ) ) ), $huellas['array'] );
	}
}
