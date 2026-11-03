<?php
/**
 * Regresión del re-sellado físico del receipt editorial (2026-09-01).
 *
 * El indexado live mueve el COUNT de docs y dejaba receipt.coverage congelado
 * en el último swap: cada alta/baja de producto cerraba el serving hasta un
 * rebuild completo, y el reconcile no lo reparaba porque `is_ready_from()`
 * no mira el conteo físico. Estos tests fijan el contrato de
 * `reprove_physical_receipt()` (fail-closed, tri-estado, CAS byte-exacto) y
 * el endurecimiento de `advance_generation_receipt()`.
 *
 * Arnés autocontenido: el bootstrap de la suite no carga AuditLog ni define
 * maybe_unserialize, y sin ellos ningún test EJECUTABA el reproof (la suite
 * daba verde sin cubrirlo — hallazgo del escéptico).
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\AuditLog;
use Akibara\Search\DocumentBuilder;
use Akibara\Search\EditorialDiscountPublication;
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

require_once dirname( __DIR__ ) . '/src/AuditLog.php';

/** Doble de wpdb: options crudas, docs vivos, universo elegible, claims live y CAS byte-exacto. */
final class ReproofWpdb {

	public string $prefix     = 'wp_';
	public string $options    = 'wp_options';
	public string $termmeta   = 'wp_termmeta';
	public string $posts      = 'wp_posts';
	public string $last_error = '';

	/** @var array<string, string> */
	public array $raw_options = array();

	/** @var array<int, object> */
	public array $database_rows = array();

	public int $docs_count    = 3;
	public int $reads         = 0;
	public int $throw_on_read = 0;

	/** @var array<int, string> */
	public array $queries = array();

	/** @var array<int, int> Universo fuente (SELECT ID FROM wp_posts …). */
	public array $eligible_ids = array( 11, 12, 13 );

	/** @var array<int, int> Filas vivas de docs (SELECT post_id FROM wp_akb_search_docs). */
	public array $doc_ids = array( 11, 12, 13 );

	/** @var array<int, string> product_id => status del claim live en vuelo. */
	public array $live_claims = array();

	/** @var callable|null Observador de cada get_var: modela writers concurrentes. */
	public $on_get_var = null;

	/** @var callable|null Observador de cada get_col. */
	public $on_get_col = null;

	/** @var callable|null Observador tras un CAS UPDATE exitoso. */
	public $on_cas_update = null;

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/** @param array<string, mixed> $where */
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

	/** Claim live de un producto (WorkRepository::find). @return object|null */
	public function get_row( string $query ) {
		$this->begin_read();
		if ( 1 !== preg_match( '/product_id = (\d+)/', $query, $match ) ) {
			return null;
		}
		$product_id = (int) $match[1];
		if ( ! isset( $this->live_claims[ $product_id ] ) ) {
			return null;
		}
		return (object) array(
			'id'           => $product_id,
			'scope'        => WorkRepository::SCOPE_LIVE,
			'run_id'       => '',
			'product_id'   => $product_id,
			'wanted_seq'   => 1,
			'acked_seq'    => 0,
			'outcome'      => '',
			'status'       => $this->live_claims[ $product_id ],
			'available_at' => '2026-09-01 00:00:00',
			'lease_token'  => '',
			'lease_seq'    => 0,
			'lease_until'  => null,
			'attempts'     => 0,
			'last_error'   => null,
			'created_at'   => '2026-09-01 00:00:00',
			'updated_at'   => '2026-09-01 00:00:00',
		);
	}

	/** @return int|string|null */
	public function get_var( string $query ) {
		$this->begin_read();
		if ( null !== $this->on_get_var ) {
			( $this->on_get_var )( $query, $this );
		}
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

	/** Universo fuente y docs vivos del reproof. @return array<int, string> */
	public function get_col( string $query ): array {
		$this->begin_read();
		if ( null !== $this->on_get_col ) {
			( $this->on_get_col )( $query, $this );
		}
		if ( false !== stripos( $query, 'FROM ' . Install::docs_table() ) ) {
			return array_map( 'strval', $this->doc_ids );
		}
		if ( false !== stripos( $query, 'FROM ' . $this->posts ) ) {
			return array_map( 'strval', $this->eligible_ids );
		}
		return array();
	}

	/** @return int|false */
	public function query( string $query ) {
		$this->queries[]  = $query;
		$this->last_error = '';
		if ( 1 === preg_match( "/INSERT IGNORE INTO .* VALUES \('((?:''|[^'])*)', '((?:''|[^'])*)', 'no'\)/s", $query, $match ) ) {
			$name  = str_replace( "''", "'", $match[1] );
			$value = str_replace( "''", "'", $match[2] );
			if ( ! array_key_exists( $name, $this->raw_options ) ) {
				$this->raw_options[ $name ] = $value;
			}
			return 1;
		}
		// CAS byte-exacto: 0 filas cuando los bytes esperados ya no están (carrera).
		if ( 1 === preg_match(
			"/UPDATE .*options SET option_value = '((?:''|[^'])*)'(?:, autoload = '[^']*')? WHERE option_name = '((?:''|[^'])*)' AND BINARY option_value = BINARY '((?:''|[^'])*)'/s",
			$query,
			$match
		) ) {
			$value    = str_replace( "''", "'", $match[1] );
			$name     = str_replace( "''", "'", $match[2] );
			$expected = str_replace( "''", "'", $match[3] );
			if ( ( $this->raw_options[ $name ] ?? null ) !== $expected ) {
				return 0;
			}
			$this->raw_options[ $name ] = $value;
			if ( null !== $this->on_cas_update ) {
				( $this->on_cas_update )( $name, $this );
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
final class ReproofRegressionTest extends TestCase {
	use \Akibara\Search\Tests\Support\MarcosEditorialesVigilados;


	private ReproofWpdb $db;

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
		$this->db                = new ReproofWpdb();
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

	/** @testdox Alta live: COUNT 4 vs receipt 3 → el reconcile re-sella coverage en vivo y reabre el gate sin rebuild. */
	public function test_live_insert_is_reproved_and_resealed_without_a_rebuild(): void {
		$publication = $this->reconcile_runtime();
		$before      = $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ];
		$this->db->eligible_ids = array( 11, 12, 13, 14 );
		$this->db->doc_ids      = array( 11, 12, 13, 14 );
		$this->db->docs_count   = 4;
		self::assertNull( EditorialDiscountPublication::capture_serving_token(), 'Precondición: el COUNT vivo cierra el gate.' );
		$this->reset_production_latches();

		self::assertTrue( $publication->reconcile_fingerprint() );

		$raw     = $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ];
		$receipt = unserialize( $raw, array( 'allowed_classes' => false ) );
		$prior   = unserialize( $before, array( 'allowed_classes' => false ) );
		self::assertNotSame( $before, $raw, 'El receipt se re-selló.' );
		self::assertSame( array( 'eligible' => 4, 'docs' => 4, 'missing' => 0, 'extra' => 0, 'orphans' => 0 ), $receipt['coverage'] );
		self::assertSame( 'generation-a', $receipt['generation_target'] );
		self::assertIsInt( $receipt['generation_advanced_at'] );
		foreach ( array( 'run_id', 'wanted_seq', 'fingerprint', 'swapped_at', 'published_at', 'db_version', 'schema_seal', 'purge_all_dispatched_at' ) as $immutable ) {
			self::assertSame( $prior[ $immutable ], $receipt[ $immutable ], "$immutable no puede moverse en un re-sellado." );
		}
		self::assertTrue( $this->invoke_static( 'valid_receipt', $receipt ), 'El receipt re-sellado sigue en el set cerrado de claves/tipos.' );
		self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options, 'Cero rebuild.' );
		self::assertSame( array( 'receipt_resellado' ), $this->audit_actions() );

		$this->reset_production_latches();
		self::assertIsString( EditorialDiscountPublication::capture_serving_token(), 'El gate público reabre con el receipt re-sellado.' );
	}

	/** @testdox Baja live: COUNT 2 vs receipt 3 → mismo re-sellado (2/2) sin rebuild. */
	public function test_live_delete_is_reproved_and_resealed_without_a_rebuild(): void {
		$publication = $this->reconcile_runtime();
		$this->db->eligible_ids = array( 11, 12 );
		$this->db->doc_ids      = array( 11, 12 );
		$this->db->docs_count   = 2;

		self::assertTrue( $publication->reconcile_fingerprint() );

		$receipt = unserialize( $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], array( 'allowed_classes' => false ) );
		self::assertSame( array( 'eligible' => 2, 'docs' => 2, 'missing' => 0, 'extra' => 0, 'orphans' => 0 ), $receipt['coverage'] );
		self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options );
		$this->reset_production_latches();
		self::assertIsString( EditorialDiscountPublication::capture_serving_token() );
	}

	/** @testdox Fila borrada a mano (1 faltante, sin claim en vuelo): el reproof NO sella, deja el receipt byte-idéntico, cae al rebuild y el reconcile devuelve false. */
	public function test_a_missing_document_is_never_sealed_and_falls_back_to_the_rebuild(): void {
		$publication = $this->reconcile_runtime();
		$before      = $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ];
		$this->db->eligible_ids = array( 11, 12, 13 );
		$this->db->doc_ids      = array( 11, 12 );
		$this->db->docs_count   = 2;

		self::assertFalse( $publication->reconcile_fingerprint(), 'Con el rebuild pedido y el gate cerrado, «listo» sería mentira.' );

		self::assertSame( $before, $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], 'Ni un byte del receipt.' );
		self::assertArrayHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options, 'El camino caro sigue vivo.' );
		self::assertSame( array( 'receipt_reproof_rechazado' ), $this->audit_actions() );
		self::assertStringContainsString( '1 faltantes, 0 sobrantes', $this->audit_details()[0] );
		$this->reset_production_latches();
		self::assertNull( EditorialDiscountPublication::capture_serving_token(), 'Fail-closed: el gate sigue cerrado.' );
	}

	/** @testdox Doc huérfano de un producto no elegible (1 sobrante): tampoco sella. */
	public function test_an_extra_document_is_never_sealed(): void {
		$publication = $this->reconcile_runtime();
		$before      = $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ];
		$this->db->eligible_ids = array( 11, 12, 13 );
		$this->db->doc_ids      = array( 11, 12, 13, 14 );
		$this->db->docs_count   = 4;

		$publication->reconcile_fingerprint();

		self::assertSame( $before, $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] );
		self::assertStringContainsString( '0 faltantes, 1 sobrantes', $this->audit_details()[0] ?? '' );
		$this->reset_production_latches();
		self::assertNull( EditorialDiscountPublication::capture_serving_token() );
	}

	/** @testdox Dos eventos en vuelo (A ya indexado, B con claim pendiente): el COUNT cierra el gate, pero el hueco es trabajo en vuelo → se difiere SIN pedir el rebuild. */
	public function test_a_missing_document_with_a_pending_live_claim_is_deferred_without_a_rebuild(): void {
		$publication = $this->reconcile_runtime();
		$before      = $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ];
		// A (14) ya entró al índice: COUNT 4 ≠ receipt 3 → gate cerrado.
		// B (15) es elegible pero su claim live aún está pendiente.
		$this->db->eligible_ids = array( 11, 12, 13, 14, 15 );
		$this->db->doc_ids      = array( 11, 12, 13, 14 );
		$this->db->docs_count   = 4;
		$this->db->live_claims  = array( 15 => WorkRepository::STATUS_PENDING );

		self::assertFalse( $publication->reconcile_fingerprint() );

		self::assertSame( $before, $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], 'No se sella con un claim en vuelo.' );
		self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options, 'Trabajo en vuelo no paga un rebuild.' );
		self::assertSame( array( 'receipt_reproof_diferido' ), $this->audit_actions() );
		self::assertStringContainsString( '#15', $this->audit_details()[0] );
	}

	/** @testdox CAS perdido: un writer que gana entre la lectura raw y el UPDATE nunca es pisado, y no se pide rebuild. */
	public function test_a_lost_cas_race_never_clobbers_the_concurrent_writer(): void {
		$publication = $this->reconcile_runtime();
		$this->db->eligible_ids = array( 11, 12, 13, 14 );
		$this->db->doc_ids      = array( 11, 12, 13, 14 );
		$this->db->docs_count   = 4;

		$concurrent = null;
		$this->db->on_get_col = function ( string $query, ReproofWpdb $db ) use ( &$concurrent ): void {
			if ( null !== $concurrent || false === stripos( $query, Install::docs_table() ) ) {
				return;
			}
			$receipt = unserialize( $db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], array( 'allowed_classes' => false ) );
			$receipt['generation_advanced_at'] = 1700000999;
			$concurrent = serialize( $receipt );
			$db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] = $concurrent;
		};

		self::assertFalse( $publication->reconcile_fingerprint() );

		self::assertIsString( $concurrent, 'La carrera se disparó.' );
		self::assertSame( $concurrent, $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], 'El writer concurrente sobrevive byte a byte.' );
		self::assertNotContains( 'receipt_resellado', $this->audit_actions(), 'Un CAS perdido jamás se registra como sellado.' );
		self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options, 'Un CAS perdido es transitorio: próximo ciclo, no rebuild.' );
		self::assertCount( 1, array_filter( $this->db->queries, static fn( string $q ): bool => 0 === strpos( $q, 'UPDATE' ) && false !== strpos( $q, EditorialDiscountPublication::OPTION_RECEIPT ) ), 'Un solo intento de CAS.' );
	}

	/** @testdox Carrera de generación durante el re-sellado: se registra y se espera al próximo ciclo, sin rebuild. */
	public function test_a_generation_race_during_the_reseal_waits_for_the_next_cycle(): void {
		$publication = $this->reconcile_runtime();
		$this->db->eligible_ids = array( 11, 12, 13, 14 );
		$this->db->doc_ids      = array( 11, 12, 13, 14 );
		$this->db->docs_count   = 4;
		$this->db->on_cas_update = static function ( string $name, ReproofWpdb $db ): void {
			if ( EditorialDiscountPublication::OPTION_RECEIPT === $name ) {
				$db->raw_options[ Install::OPTION_INDEX_GEN ] = 'generation-b';
			}
		};

		self::assertFalse( $publication->reconcile_fingerprint() );

		self::assertContains( 'receipt_reproof_carrera', $this->audit_actions() );
		self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options, 'La carrera no paga un rebuild en el mismo ciclo.' );
	}

	/** @testdox Gate cerrado por deuda editorial (purge_post sin ACK): el conteo no es la única causa → no sella ni registra «sin rebuild». */
	public function test_the_reproof_refuses_to_seal_when_the_gate_is_closed_for_another_reason(): void {
		$publication = $this->reconcile_runtime();
		$state       = json_decode( $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ], true );
		$state['purge_post_seq'] = 0;
		$this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ] = (string) json_encode( $state );
		$before = $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ];
		$this->db->eligible_ids = array( 11, 12, 13, 14 );
		$this->db->doc_ids      = array( 11, 12, 13, 14 );
		$this->db->docs_count   = 4;

		$publication->reconcile_fingerprint();

		self::assertSame( $before, $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], 'No se escribe con otra causa abierta.' );
		self::assertNotContains( 'receipt_resellado', $this->audit_actions() );
		self::assertContains( 'receipt_reproof_rechazado', $this->audit_actions() );
		self::assertStringContainsString( 'Deuda editorial', implode( ' ', $this->audit_details() ) );
	}

	/** @testdox Re-sellado idempotente: dos reconciles con el mismo mundo no reescriben el receipt. */
	public function test_the_reseal_is_idempotent_across_cycles(): void {
		$publication = $this->reconcile_runtime();
		$this->db->eligible_ids = array( 11, 12, 13, 14 );
		$this->db->doc_ids      = array( 11, 12, 13, 14 );
		$this->db->docs_count   = 4;

		self::assertTrue( $publication->reconcile_fingerprint() );
		$sealed  = $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ];
		$updates = count( array_filter( $this->db->queries, static fn( string $q ): bool => 0 === strpos( $q, 'UPDATE' ) ) );

		$this->reset_production_latches();
		self::assertTrue( $publication->reconcile_fingerprint() );

		self::assertSame( $sealed, $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], 'Mismo mundo, mismos bytes.' );
		self::assertSame( $updates, count( array_filter( $this->db->queries, static fn( string $q ): bool => 0 === strpos( $q, 'UPDATE' ) ) ), 'Ningún UPDATE adicional.' );
	}

	/** @testdox advance_generation_receipt aborta sin escribir si la generación se mueve entre las guardas y el write. */
	public function test_generation_advance_aborts_when_the_live_generation_moves_before_the_write(): void {
		$this->reconcile_runtime();
		$this->db->raw_options[ Install::OPTION_INDEX_GEN ] = 'generation-b';
		foreach ( array( SemanticPublication::OPTION_FINGERPRINT => 'generation', RestCache::OPTION_PURGE_RECEIPT => 'generation_target' ) as $option => $key ) {
			$row         = unserialize( $this->db->raw_options[ $option ], array( 'allowed_classes' => false ) );
			$row[ $key ] = 'generation-b';
			$this->db->raw_options[ $option ] = serialize( $row );
		}
		$before = $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ];

		$gen_reads = 0;
		$this->db->on_get_var = function ( string $query, ReproofWpdb $db ) use ( &$gen_reads ): void {
			if ( false === strpos( $query, "option_name = '" . Install::OPTION_INDEX_GEN . "'" ) ) {
				return;
			}
			++$gen_reads;
			if ( 2 === $gen_reads ) { // 1ª: physical_publication_matches; 2ª: relectura previa al write.
				$db->raw_options[ Install::OPTION_INDEX_GEN ] = 'generation-c';
			}
		};

		self::assertFalse( EditorialDiscountPublication::advance_generation_receipt( 'generation-b' ) );

		self::assertSame( 2, $gen_reads, 'La relectura de la generación viva ocurre justo antes del write.' );
		self::assertSame( $before, $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], 'Nada se escribió con un target obsoleto.' );
		self::assertSame( array( 'avance_generacion_rechazado' ), $this->audit_actions() );
		self::assertStringContainsString( 'cambió durante el avance', $this->audit_details()[0] );

		$this->db->on_get_var = null;
		$this->db->raw_options[ Install::OPTION_INDEX_GEN ] = 'generation-b';
		SemanticPublication::reset_authoritative_state_for_tests();
		self::assertTrue( EditorialDiscountPublication::advance_generation_receipt( 'generation-b' ) );
		$receipt = unserialize( $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], array( 'allowed_classes' => false ) );
		self::assertSame( 'generation-b', $receipt['generation_target'] );
	}

	// ------------------------------------ DEFECTO ABIERTO (QA 2026-09-05)
	//
	// Handoff del carril barra-de-anuncios al carril search. Reproduce, en
	// unidad, lo que el gate MariaDB (`qa-search-mariadb.sh`, modo candidate)
	// deja tras normalizar el clon: deuda staged, PRE cerrado, POST abierto y
	// NINGUNA fila de recibo. Medido en el clon el 2026-09-05:
	//   A) sin fila de recibo   → reprove_physical_receipt() = NULL  → purge_post_seq queda en 0
	//   B) fila de recibo ILEGIBLE → reprove_physical_receipt() = false → purge_post_seq = 1
	// El peor insumo (no hay recibo) se trataba con MÁS indulgencia que el menos
	// malo (recibo basura), porque `! is_string( $raw ) || '' === $raw` mezclaba
	// «la fila no existe» —un hecho— con «la lectura falló» —un desconocido—.
	// `$wpdb->last_error`, que la misma guarda ya consultaba, es la única señal
	// legítima de lectura fallida, y ahora es la que decide.
	//
	// CERRADO en 0.1.51 separando las dos condiciones. Estas tres pruebas se
	// quedan: fijan las DOS mitades del tri-estado, así que ni el arreglo puede
	// erosionar el caso transitorio ni una futura guarda puede volver a leer una
	// ausencia como indisponibilidad.

	/**
	 * @testdox Recibo AUSENTE: es un hecho, no una lectura fallida → el reproof debe caer al rebuild (false), no aplazar (null).
	 */
	public function test_an_absent_receipt_is_a_fact_and_never_a_transient_read_failure(): void {
		$publication = $this->reconcile_runtime();
		$state       = $this->gate_state_without_receipt();

		$reproof = new ReflectionMethod( EditorialDiscountPublication::class, 'reprove_physical_receipt' );
		$reproof->setAccessible( true );
		$source = $this->invoke_static( 'stable_source_snapshot' );
		self::assertIsArray( $source, 'Precondición: la foto de la fuente es autoritativa.' );
		self::assertSame( 0, (int) $state['purge_post_seq'], 'Precondición: la mitad POST está abierta.' );

		self::assertFalse(
			$reproof->invoke( $publication, $source, $state ),
			'Sin fila de recibo y sin error de lectura, la ausencia es conocible: eso lo paga el rebuild, '
			. 'igual que un recibo ilegible. Devolver null aplaza para siempre y el POST no cierra nunca.'
		);
	}

	/**
	 * @testdox Deuda staged sin recibo: dos ciclos de reconcile tienen que dejar purge_post_seq == wanted_seq.
	 */
	public function test_the_post_half_closes_when_there_is_staged_debt_and_no_receipt(): void {
		$publication = $this->reconcile_runtime();
		$this->gate_state_without_receipt();

		$publication->reconcile_fingerprint();
		$this->reset_production_latches();
		$publication->reconcile_fingerprint();

		$state = json_decode( $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ], true );
		self::assertSame(
			(int) $state['wanted_seq'],
			(int) $state['purge_post_seq'],
			'Con la mitad POST abierta el protocolo se cierra por dentro: capture_price_authority() devuelve null, '
			. 'los claims caen a blocked y serving_ready() apaga buscador y facetas (incidente 2026-09-05, 3 h 20 min).'
		);
	}

	/** @testdox Control: una lectura del recibo que SÍ falla sigue siendo transitoria (null) y no dispara un rebuild. */
	public function test_a_failed_receipt_read_is_still_transient_and_never_falls_to_the_rebuild(): void {
		$publication = $this->reconcile_runtime();
		$state       = $this->gate_state_without_receipt();
		// La fila existe, pero MariaDB devuelve error en ESA lectura.
		$this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] = 'da igual: la lectura falla';
		$this->db->on_get_var = static function ( string $query, ReproofWpdb $db ): void {
			if ( false !== strpos( $query, EditorialDiscountPublication::OPTION_RECEIPT ) ) {
				$db->last_error = 'Lost connection to MySQL server during query';
			}
		};

		$reproof = new ReflectionMethod( EditorialDiscountPublication::class, 'reprove_physical_receipt' );
		$reproof->setAccessible( true );
		$source = $this->invoke_static( 'stable_source_snapshot' );
		self::assertIsArray( $source );

		self::assertNull(
			$reproof->invoke( $publication, $source, $state ),
			'No conocer el recibo NO es lo mismo que saber que no hay recibo: el arreglo no puede erosionar esta mitad.'
		);
	}

	/**
	 * Estado exacto que deja el gate MariaDB tras normalizar el clon.
	 *
	 * @return array<string, mixed>
	 */
	private function gate_state_without_receipt(): array {
		$state = json_decode( $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ], true );
		self::assertIsArray( $state );
		$state['purge_post_seq'] = 0;
		unset( $state['purge_post_at'] );
		$this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ] = (string) json_encode( $state );
		unset( $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] );
		$this->reset_production_latches();
		return $state;
	}

	// ------------------------------------------------------------------ arnés

	/** Runtime editorial + stubs que el reconcile/reproof necesita y el setUp no da. */
	private function reconcile_runtime(): EditorialDiscountPublication {
		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				if ( ! array_key_exists( $name, $this->db->raw_options ) ) {
					return $default;
				}
				$raw = $this->db->raw_options[ $name ];
				$un  = @unserialize( $raw, array( 'allowed_classes' => false ) );
				return false === $un && 'b:0;' !== $raw ? $raw : $un;
			}
		);
		// maybe_unserialize() vive como stub REAL en tests/Support/wp-function-stubs.php:
		// mockearla con Brain Monkey aquí la dejaba «definida por Monkey» para el
		// resto de la suite y rompía 5 tests ajenos con MissingFunctionExpectations.
		Functions\when( 'wp_get_current_user' )->justReturn( null );
		Functions\when( 'wp_prime_option_caches' )->justReturn( null );
		Functions\when( 'sanitize_key' )->alias( static fn( string $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );

		$work    = new WorkRepository( $this->db, 'wp_akb_search_work' );
		$fence   = new WriteFence( $this->db, 'akb-reproof' );
		$builder = new DocumentBuilder();
		$source  = new ProductSourceAuthority( $this->db, static fn( array $ids ): array => $ids );
		$indexer = new Indexer( $builder, $work, $fence, $source );
		return $this->vigilar_publicacion( new EditorialDiscountPublication( $work, $indexer, $fence ) );
	}

	/** @return array<int, string> */
	private function audit_actions(): array {
		return array_column( $this->audit_entries(), 'action' );
	}

	/** @return array<int, string> */
	private function audit_details(): array {
		return array_column( $this->audit_entries(), 'detail' );
	}

	/** @return array<int, array<string, mixed>> */
	private function audit_entries(): array {
		$raw = $this->db->raw_options[ AuditLog::OPTION_KEY ] ?? '';
		return '' === $raw ? array() : (array) unserialize( $raw, array( 'allowed_classes' => false ) );
	}

	/** @param mixed ...$args @return mixed */
	private function invoke_static( string $method, ...$args ) {
		$reflection = new ReflectionMethod( EditorialDiscountPublication::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$args );
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
			Install::OPTION_INDEX_GEN                    => $generation,
			Install::OPTION_KEY                          => Install::DB_VERSION,
			Install::OPTION_SCHEMA_SEAL                  => Install::schema_seal(),
			EditorialDiscountPublication::OPTION_STATE   => (string) json_encode( $state ),
			EditorialDiscountPublication::OPTION_RECEIPT => serialize( $receipt ),
			SemanticPublication::OPTION_FINGERPRINT      => serialize(
				array( 'protocol' => 1, 'generation' => $generation, 'fingerprint' => $semantic['semantic'], 'published_at' => $now )
			),
			RestCache::OPTION_PURGE_RECEIPT              => serialize(
				array( 'protocol' => 1, 'generation_target' => $generation, 'tag' => RestCache::TAG, 'ack_scope' => 'local_dispatch_only', 'dispatched_at' => $now )
			),
		);
		unset( $this->db->raw_options[ RebuildJob::OPTION_STATE ] );
		$this->db->docs_count    = 3;
		$this->db->reads         = 0;
		$this->db->throw_on_read = 0;
	}

	private function reset_production_latches(): void {
		foreach ( $this->publication_static_defaults() as $name => $value ) {
			$this->set_static_value( $name, $value );
		}
		SemanticPublication::reset_authoritative_state_for_tests();
		FacetRegistry::reset_cache();
	}

	/** @return array<string, mixed> */
	private function publication_static_defaults(): array {
		return array(
			'registered'                => true,
			'runtime'                   => null,
			'state_read_failed'         => false,
			'test_serving_override'     => null,
			'test_price_match_sequence' => null,
			'term_meta_row_failed'      => false,
			'source_memo_set'           => false,
			'read_authority_available'  => true,
			'read_authority_error'      => '',
			'serving_capture_done'      => false,
			'serving_capture_token'     => null,
			'serving_capture_window'    => null,
			'serving_final_checked'     => array(),
			'serving_final_matches_by'  => array(),
			'serving_final_matches'     => false,
			'adopted_price_provider'    => null,
			'adopted_price_token'       => null,
			'adopted_price_snapshot'    => null,
			'adopted_provider_absent'   => false,
			'source_memo'               => null,
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
