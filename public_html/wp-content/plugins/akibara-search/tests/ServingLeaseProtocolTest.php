<?php
/**
 * Contrato production-path de la lease que autoriza superficies públicas.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\DocumentBuilder;
use Akibara\Search\FacetRegistry;
use Akibara\Search\Indexer;
use Akibara\Search\Install;
use Akibara\Search\ProductSourceAuthority;
use Akibara\Search\RebuildJob;
use Akibara\Search\SemanticPublication;
use Akibara\Search\Support\RestCache;
use Akibara\Search\WorkRepository;
use Akibara\Search\WriteFence;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** wpdb determinístico: cinco lecturas autoritativas por identidad. */
final class ServingLeaseWpdb {

	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $termmeta = 'wp_termmeta';
	public string $posts = 'wp_posts';
	public string $last_error = '';

	/** @var array<string, string> */
	public array $raw_options = array();

	/** @var array<int, object> */
	public array $database_rows = array();

	public int $docs_count = 3;
	public int $reads = 0;
	public int $throw_on_read = 0;
	/** @var array<int,string> */
	public array $queries = array();

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query = (string) preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}

	/** Igual que el $wpdb real: escapa los comodines de LIKE. */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * @param string               $table Tabla.
	 * @param array<string, mixed> $where Condiciones de igualdad.
	 * @return int
	 */
	public function delete( string $table, array $where ): int {
		$name = (string) ( $where['option_name'] ?? '' );
		if ( ! array_key_exists( $name, $this->raw_options ) ) {
			return 0;
		}
		unset( $this->raw_options[ $name ] );
		return 1;
	}

	/** @return array<int, object> */
	public function get_results( string $query ): array {
		$this->begin_read();
		if ( false !== stripos( $query, 'option_name, option_value' ) ) {
			$rows = array();
			$raw  = $this->raw_options;
			ksort( $raw, SORT_STRING );

			// Barrido por prefijo (la poda de receipts). El doble modela la
			// semántica de LIKE que importa acá: prefijo + comodín final.
			if ( 1 === preg_match( "/LIKE '((?:''|[^'])*)%'/", $query, $like ) ) {
				$prefix  = str_replace( array( "''", '\\_' ), array( "'", '_' ), $like[1] );
				$exclude = 1 === preg_match( "/option_name <> '((?:''|[^'])*)'/", $query, $skip )
					? str_replace( "''", "'", $skip[1] )
					: '';
				foreach ( $raw as $name => $value ) {
					if ( 0 !== strpos( (string) $name, $prefix ) || $name === $exclude ) {
						continue;
					}
					$rows[] = (object) array( 'option_name' => $name, 'option_value' => $value );
				}
				return $rows;
			}

			foreach ( $raw as $name => $value ) {
				if ( false === strpos( $query, "'" . str_replace( "'", "''", $name ) . "'" ) ) {
					continue;
				}
				$rows[] = (object) array( 'option_name' => $name, 'option_value' => $value );
			}
			return $rows;
		}
		if ( false !== stripos( $query, "SELECT 'meta' source_kind" ) ) {
			return $this->database_rows;
		}
		return array();
	}

	/** @return int|string */
	public function get_var( string $query ) {
		$this->begin_read();
		if ( false !== stripos( $query, 'GET_LOCK(' ) || false !== stripos( $query, 'RELEASE_LOCK(' ) ) {
			return '1';
		}
		if ( false !== stripos( $query, 'CONNECTION_ID()' ) || false !== stripos( $query, 'IS_USED_LOCK(' ) ) {
			return '37';
		}
		if ( false !== stripos( $query, 'COUNT(*)' ) && false !== stripos( $query, Install::docs_table() ) ) {
			return (string) $this->docs_count;
		}
		if ( 1 === preg_match( "/option_name = '((?:''|[^'])*)'/", $query, $match ) ) {
			$name = str_replace( "''", "'", $match[1] );
			return $this->raw_options[ $name ] ?? null;
		}
		return '0';
	}

	/** @return int|false */
	public function query( string $query ) {
		$this->queries[] = $query;
		$this->last_error = '';
		if ( 1 === preg_match( "/INSERT IGNORE INTO .* VALUES \('((?:''|[^'])*)', '((?:''|[^'])*)', 'no'\)/s", $query, $match ) ) {
			$name  = str_replace( "''", "'", $match[1] );
			$value = str_replace( "''", "'", $match[2] );
			if ( ! array_key_exists( $name, $this->raw_options ) ) {
				$this->raw_options[ $name ] = $value;
			}
			return 1;
		}
		return 0;
	}

	private function begin_read(): void {
		++$this->reads;
		$this->last_error = '';
		if ( $this->throw_on_read === $this->reads ) {
			throw new \RuntimeException( 'forced serving read throwable' );
		}
	}
}

/** @covers \Akibara\Search\EditorialDiscountPublication */
final class ServingLeaseProtocolTest extends TestCase {
	use \Akibara\Search\Tests\Support\MarcosEditorialesVigilados;


	private ServingLeaseWpdb $db;

	/** @var array<string, mixed> */
	private array $original_static = array();

	/** @var mixed */
	private $previous_wpdb;
	private bool $had_previous_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->had_previous_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb     = $GLOBALS['wpdb'] ?? null;
		$this->db                = new ServingLeaseWpdb();
		$GLOBALS['wpdb']         = $this->db;
		unset( $GLOBALS['wp_filter']['akb_search_effective_price'] );

		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value ) );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'maybe_serialize' )->alias( static fn( $value ): string => is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->db->raw_options[ $name ] = is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
				return true;
			}
		);

		foreach ( $this->publication_static_defaults() as $name => $value ) {
			$this->original_static[ $name ] = $this->static_value( $name );
			$this->set_static_value( $name, $value );
		}
		SemanticPublication::reset_authoritative_state_for_tests();
		FacetRegistry::reset_cache();
		$this->install_ready_bundle();
	}

	protected function tearDown(): void {
		// Primero, con los stubs montados: un marco abierto no puede cerrarse al apagar PHP.
		$this->soltar_publicaciones_vigiladas();
		foreach ( $this->original_static as $name => $value ) {
			$this->set_static_value( $name, $value );
		}
		SemanticPublication::reset_authoritative_state_for_tests();
		FacetRegistry::reset_cache();
		if ( $this->had_previous_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Captura y final usan 5+5 lecturas; helpers repetidos agregan cero SQL. */
	public function test_production_path_is_memoized_and_revalidated_with_ten_reads(): void {
		$token = EditorialDiscountPublication::capture_serving_token();

		self::assertIsString( $token );
		self::assertSame( 64, strlen( $token ) );
		self::assertSame( 5, $this->db->reads );
		for ( $i = 0; $i < 5; ++$i ) {
			self::assertSame( $token, EditorialDiscountPublication::capture_serving_token() );
			self::assertTrue( EditorialDiscountPublication::serving_ready() );
		}
		self::assertSame( 5, $this->db->reads );

		self::assertTrue( EditorialDiscountPublication::serving_token_matches( $token ) );
		self::assertSame( 10, $this->db->reads );
		for ( $i = 0; $i < 5; ++$i ) {
			self::assertTrue( EditorialDiscountPublication::serving_token_matches( $token ) );
		}
		self::assertSame( 10, $this->db->reads );
		self::assertLessThanOrEqual( 11, $this->db->reads );
	}

	/** @testdox Un writer entre captura y final cierra sticky la lease. */
	public function test_inflight_generation_change_fails_final_check_without_recovery(): void {
		$token = EditorialDiscountPublication::capture_serving_token();
		self::assertIsString( $token );

		$this->rewrite_generation( 'generation-b' );
		self::assertFalse( EditorialDiscountPublication::serving_token_matches( $token ) );
		self::assertSame( 10, $this->db->reads );

		$this->rewrite_generation( 'generation-a' );
		self::assertFalse( EditorialDiscountPublication::serving_token_matches( $token ) );
		self::assertSame( 10, $this->db->reads, 'El final fallido no revive ni relee.' );
	}

	/** @testdox El primer Throwable de captura queda cerrado aunque la DB se recupere. */
	public function test_first_capture_throwable_is_sticky(): void {
		$this->db->throw_on_read = 1;

		self::assertNull( EditorialDiscountPublication::capture_serving_token() );
		self::assertSame( 1, $this->db->reads );
		$this->db->throw_on_read = 0;
		self::assertNull( EditorialDiscountPublication::capture_serving_token() );
		self::assertFalse( EditorialDiscountPublication::serving_ready() );
		self::assertSame( 1, $this->db->reads );
	}

	/** @testdox State, receipt y coverage rechazan campos desconocidos. */
	public function test_state_and_receipt_shapes_are_closed(): void {
		$state = json_decode( $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ], true );
		$state['unexpected'] = true;
		$this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ] = (string) json_encode( $state );
		self::assertNull( EditorialDiscountPublication::capture_serving_token() );

		$this->reset_production_latches();
		$this->install_ready_bundle();
		$receipt = unserialize( $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], array( 'allowed_classes' => false ) );
		$receipt['coverage']['unexpected'] = 1;
		$this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] = serialize( $receipt );
		self::assertNull( EditorialDiscountPublication::capture_serving_token() );

		$this->reset_production_latches();
		$this->install_ready_bundle();
		$receipt = unserialize( $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], array( 'allowed_classes' => false ) );
		$receipt['unexpected'] = true;
		$this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] = serialize( $receipt );
		self::assertNull( EditorialDiscountPublication::capture_serving_token() );
	}

	/** @testdox Conteo físico cero no acredita un receipt de tres documentos. */
	public function test_physical_document_count_must_match_receipt(): void {
		$this->db->docs_count = 0;

		self::assertNull( EditorialDiscountPublication::capture_serving_token() );
		self::assertSame( 5, $this->db->reads );
	}

	/** @testdox Bytes serializados malformados cierran antes de servir. */
	public function test_malformed_semantic_option_bytes_fail_closed(): void {
		$this->db->raw_options['akb_search_synonyms'] = 'a:1:{broken';

		self::assertNull( EditorialDiscountPublication::capture_serving_token() );
		self::assertLessThanOrEqual( 5, $this->db->reads );
	}

	/** @testdox Una mutación tras purge no permite insertar el receipt inmutable del run. */
	public function test_swap_rechecks_combined_authority_immediately_before_run_receipt_insert(): void {
		$this->install_record_runtime( 'akb-swap-before-run' );
		$proof = $this->publication_proof();
		$state = json_decode( $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ], true );
		EditorialDiscountPublication::set_test_price_match_sequence( array( true, false ) );

		self::assertFalse( EditorialDiscountPublication::record_swap_receipt( 'run-temporal-a', 1, $state['wanted_fingerprint'], $proof ) );
		self::assertSame(
			array(),
			array_filter( array_keys( $this->db->raw_options ), static fn( string $key ): bool => 0 === strpos( $key, 'akb_search_editorial_run_receipt_' ) )
		);
	}

	/** @testdox Una mutación tras el receipt del run no puede mover el receipt corriente. */
	public function test_swap_rechecks_combined_authority_immediately_before_current_receipt(): void {
		$fence = $this->install_record_runtime( 'akb-swap-before-current' );
		$proof = $this->publication_proof();
		$state = json_decode( $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ], true );
		self::assertTrue( $fence->is_owned() );
		$physical = new ReflectionMethod( EditorialDiscountPublication::class, 'physical_publication_matches' );
		$physical->setAccessible( true );
		self::assertTrue(
			$physical->invoke( null, $proof['generation_target'], $proof['semantic_fingerprint'] ),
			(string) json_encode(
				array(
					'expected_semantic' => $proof['semantic_fingerprint'],
					'actual_semantic'   => SemanticPublication::request_semantic_fingerprint(),
					'generation'        => $this->db->raw_options[ Install::OPTION_INDEX_GEN ] ?? null,
					'purge'             => $this->db->raw_options[ RestCache::OPTION_PURGE_RECEIPT ] ?? null,
					'semantic'          => $this->db->raw_options[ SemanticPublication::OPTION_FINGERPRINT ] ?? null,
				)
			)
		);
		self::assertIsArray( EditorialDiscountPublication::capture_price_authority() );
		EditorialDiscountPublication::set_test_price_match_sequence( array( true, true, false ) );

		self::assertFalse( EditorialDiscountPublication::record_swap_receipt( 'run-temporal-b', 1, $state['wanted_fingerprint'], $proof ) );
		$current = unserialize( $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], array( 'allowed_classes' => false ) );
		self::assertSame( 'run-ready-1', $current['run_id'] ?? null );
		self::assertCount(
			1,
			array_filter( array_keys( $this->db->raw_options ), static fn( string $key ): bool => 0 === strpos( $key, 'akb_search_editorial_run_receipt_' ) ),
			(string) json_encode( array( 'queries' => $this->db->queries, 'sequence' => $this->static_value( 'test_price_match_sequence' ) ) )
		);
	}

	private function install_record_runtime( string $lock_name ): WriteFence {
		self::assertIsString( EditorialDiscountPublication::capture_serving_token() );
		$work    = new WorkRepository( $this->db, 'wp_akb_search_work' );
		$fence   = new WriteFence( $this->db, $lock_name );
		$builder = new DocumentBuilder();
		$source  = new ProductSourceAuthority( $this->db, static fn( array $ids ): array => $ids );
		$indexer = new Indexer( $builder, $work, $fence, $source );
		$this->vigilar_publicacion( new EditorialDiscountPublication( $work, $indexer, $fence ) );
		self::assertTrue( $fence->acquire() );
		return $fence;
	}

	/** @return array<string,mixed> */
	private function publication_proof(): array {
		$receipt = unserialize( $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], array( 'allowed_classes' => false ) );
		return array(
			'db_version'                   => Install::DB_VERSION,
			'schema_seal'                 => Install::schema_seal(),
			'generation_target'           => $receipt['generation_target'],
			'semantic_fingerprint'        => $receipt['semantic_fingerprint'],
			'generation_acked'            => true,
			'rest_purge_acked'            => true,
			'semantic_fingerprint_acked'  => true,
			'swapped_at'                  => 1700000001,
			'coverage'                    => array( 'eligible' => 3, 'docs' => 3, 'missing' => 0, 'extra' => 0, 'orphans' => 0 ),
		);
	}

	private function install_ready_bundle(): void {
		$generation = 'generation-a';
		$base       = array( Install::OPTION_INDEX_GEN => $generation );
		$semantic   = SemanticPublication::fingerprints_from_raw_bundle( $base );
		self::assertIsArray( $semantic );

		$options_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_options_from_bundle' );
		$options_method->setAccessible( true );
		$source_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_snapshot_from_options' );
		$source_method->setAccessible( true );
		$options = $options_method->invoke( null, $base );
		$source  = $source_method->invoke( null, $options, array( 'meta' => array(), 'attributes' => array() ) );
		self::assertIsArray( $source );

		$now   = 1700000000;
		$state = array(
			'protocol'           => 1,
			'contract'           => 'akb-editorial-materialization-v1',
			'wanted_seq'         => 1,
			'wanted_fingerprint' => $source['fingerprint'],
			'window_active'      => false,
			'next_boundary_at'   => null,
			'source_term_ids'    => array(),
			'debt_staged'        => true,
			'active_run_id'      => '',
			'purge_pre_seq'      => 1,
			'purge_post_seq'     => 1,
			'reason'             => 'test-ready',
			'updated_at'         => $now,
		);
		$receipt = array(
			'protocol'                => 1,
			'contract'                => 'akb-editorial-materialization-v1',
			'run_id'                  => 'run-ready-1',
			'wanted_seq'              => 1,
			'fingerprint'             => $source['fingerprint'],
			'swapped_at'              => $now,
			'published_at'            => $now,
			'db_version'              => Install::DB_VERSION,
			'schema_seal'             => Install::schema_seal(),
			'coverage'                => array( 'eligible' => 3, 'docs' => 3, 'missing' => 0, 'extra' => 0, 'orphans' => 0 ),
			'generation_target'       => $generation,
			'semantic_fingerprint'    => $semantic['semantic'],
			'publication_acked'       => true,
			'purge_all_dispatched_at' => $now,
		);

		$this->db->raw_options = array(
			Install::OPTION_INDEX_GEN                         => $generation,
			Install::OPTION_KEY                               => Install::DB_VERSION,
			Install::OPTION_SCHEMA_SEAL                       => Install::schema_seal(),
			EditorialDiscountPublication::OPTION_STATE        => (string) json_encode( $state ),
			EditorialDiscountPublication::OPTION_RECEIPT      => serialize( $receipt ),
			SemanticPublication::OPTION_FINGERPRINT            => serialize(
				array( 'protocol' => 1, 'generation' => $generation, 'fingerprint' => $semantic['semantic'], 'published_at' => $now )
			),
			RestCache::OPTION_PURGE_RECEIPT                    => serialize(
				array( 'protocol' => 1, 'generation_target' => $generation, 'tag' => RestCache::TAG, 'ack_scope' => 'local_dispatch_only', 'dispatched_at' => $now )
			),
		);
		unset( $this->db->raw_options[ RebuildJob::OPTION_STATE ] );
		$this->db->docs_count = 3;
		$this->db->reads      = 0;
		$this->db->throw_on_read = 0;
	}

	private function reset_production_latches(): void {
		foreach ( $this->publication_static_defaults() as $name => $value ) {
			$this->set_static_value( $name, $value );
		}
		SemanticPublication::reset_authoritative_state_for_tests();
		FacetRegistry::reset_cache();
	}

	private function rewrite_generation( string $generation ): void {
		$this->db->raw_options[ Install::OPTION_INDEX_GEN ] = $generation;

		$receipt = unserialize( $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], array( 'allowed_classes' => false ) );
		$receipt['generation_target'] = $generation;
		$this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] = serialize( $receipt );

		$semantic = unserialize( $this->db->raw_options[ SemanticPublication::OPTION_FINGERPRINT ], array( 'allowed_classes' => false ) );
		$semantic['generation'] = $generation;
		$this->db->raw_options[ SemanticPublication::OPTION_FINGERPRINT ] = serialize( $semantic );

		$purge = unserialize( $this->db->raw_options[ RestCache::OPTION_PURGE_RECEIPT ], array( 'allowed_classes' => false ) );
		$purge['generation_target'] = $generation;
		$this->db->raw_options[ RestCache::OPTION_PURGE_RECEIPT ] = serialize( $purge );
	}

	/** @return array<string, mixed> */
	private function publication_static_defaults(): array {
		return array(
			'registered'               => true,
			'runtime'                  => null,
			'state_read_failed'        => false,
			'test_serving_override'    => null,
			'test_price_match_sequence' => null,
			'term_meta_row_failed'     => false,
			'source_memo_set'          => false,
			'read_authority_available' => true,
			'read_authority_error'     => '',
			'serving_capture_done'     => false,
			'serving_capture_token'    => null,
			'serving_capture_window'   => null,
			'serving_final_checked'    => array(),
			'serving_final_matches_by' => array(),
			'serving_final_matches'    => false,
			'adopted_price_provider'   => null,
			'adopted_price_token'      => null,
			'adopted_price_snapshot'   => null,
			'adopted_provider_absent'  => false,
			'source_memo'              => null,
		);
	}

	/** @return mixed */
	private function static_value( string $name ) {
		$property = new ReflectionProperty( EditorialDiscountPublication::class, $name );
		$property->setAccessible( true );
		return $property->getValue();
	}

	/** @param mixed $value */
	private function set_static_value( string $name, $value ): void {
		$property = new ReflectionProperty( EditorialDiscountPublication::class, $name );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}
}
