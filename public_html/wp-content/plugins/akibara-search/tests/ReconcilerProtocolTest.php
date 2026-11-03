<?php
/**
 * Contrato focal del reconciliador durable y fail-closed.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Arrival;
use Akibara\Search\DocumentBuilder;
use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\Indexer;
use Akibara\Search\Install;
use Akibara\Search\ProductSourceAuthority;
use Akibara\Search\Reconciler;
use Akibara\Search\WorkRepository;
use Akibara\Search\WriteFence;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/Arrival.php';
require_once dirname( __DIR__ ) . '/src/Reconciler.php';

defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

/** Producto mínimo del spot-check cuando este archivo corre aislado. */
class ReconcilerProtocolProductStub {

	private int $id;

	public function __construct( int $id ) {
		$this->id = $id;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_status(): string {
		return 'publish';
	}
}

/**
 * Doble dirigido por operación para verificar errores SQL y batching durable.
 */
final class ReconcilerProtocolWpdb {

	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $options = 'wp_options';
	public string $last_error = '';

	/** @var array<string, array<int, int>> */
	public array $id_sets = array(
		'missing'       => array(),
		'orphaned'      => array(),
		'stale'         => array(),
		'orphan_facets' => array(),
	);

	/** @var array<int, object> */
	public array $sample = array();

	/** @var array<int, string> */
	public array $queries = array();

	/** @var array<int, int> */
	public array $durable_ids = array();

	public int $qlog_rows = 0;
	public int $work_insert_count = 0;
	public int $fail_work_insert_at = 0;
	public string $fail_operation = '';
	public string $prepare_failure = '';

	/**
	 * @param string $query  Sentencia con placeholders.
	 * @param mixed  ...$args Valores.
	 * @return string
	 */
	public function prepare( string $query, ...$args ) {
		if ( 'throw' === $this->prepare_failure ) {
			throw new \RuntimeException( 'forced prepare throwable' );
		}
		if ( 'wp_error' === $this->prepare_failure ) {
			return new \WP_Error();
		}
		if ( 'false' === $this->prepare_failure ) {
			return false;
		}
		if ( 'last_error' === $this->prepare_failure ) {
			$this->last_error = 'forced prepare last_error';
			return $query;
		}
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$query   = str_replace( array( '%d', '%f' ), '%s', $query );
		$escaped = array_map(
			static fn( $value ): string => null === $value ? 'NULL' : "'" . str_replace( "'", "''", (string) $value ) . "'",
			$args
		);

		return vsprintf( $query, $escaped );
	}

	/**
	 * @param string $query Consulta de IDs.
	 * @return array<int, int>|null
	 */
	public function get_col( string $query ): ?array {
		$this->queries[] = $query;
		$operation       = $this->id_operation( $query );
		if ( $operation === $this->fail_operation ) {
			$this->last_error = 'forced ' . $operation . ' read failure';
			return null;
		}

		return $this->id_sets[ $operation ] ?? array();
	}

	/**
	 * @param string $query Consulta de muestra.
	 * @return array<int, object>|null
	 */
	public function get_results( string $query ): ?array {
		$this->queries[] = $query;
		if ( 'sample' === $this->fail_operation ) {
			$this->last_error = 'forced sample read failure';
			return null;
		}

		return $this->sample;
	}

	/**
	 * @param string $query Consulta escalar.
	 * @return string|null
	 */
	public function get_var( string $query ): ?string {
		$this->queries[] = $query;
		if ( false !== strpos( $query, $this->options ) ) {
			return null;
		}
		if ( 'qlog_count' === $this->fail_operation ) {
			$this->last_error = 'forced qlog count failure';
			return null;
		}

		return (string) $this->qlog_rows;
	}

	/**
	 * @param string $query Sentencia de escritura.
	 * @return int|false
	 */
	public function query( string $query ) {
		$this->queries[] = $query;

		if ( false !== strpos( $query, 'INSERT INTO `wp_akb_search_work`' ) ) {
			++$this->work_insert_count;
			if ( $this->fail_work_insert_at === $this->work_insert_count ) {
				$this->last_error = 'forced durable batch failure';
				return false;
			}

			preg_match_all( "/\\('live', '', '([0-9]+)'/", $query, $matches );
			$this->durable_ids = array_merge( $this->durable_ids, array_map( 'intval', $matches[1] ?? array() ) );
			return 1;
		}
		if ( false !== strpos( $query, $this->options ) ) {
			return 1;
		}

		$operation = false !== strpos( $query, 'ORDER BY day ASC' ) ? 'qlog_cap' : 'qlog_retention';
		if ( $operation === $this->fail_operation ) {
			$this->last_error = 'forced ' . $operation . ' failure';
			return false;
		}

		return 0;
	}

	/**
	 * @param string $query Consulta de IDs.
	 * @return string
	 */
	private function id_operation( string $query ): string {
		if ( false !== strpos( $query, 'd.post_id IS NULL' ) ) {
			return 'missing';
		}
		if ( false !== strpos( $query, 'p.ID IS NULL' ) ) {
			return 'orphaned';
		}
		if ( false !== strpos( $query, 'post_modified_gmt >' ) ) {
			return 'stale';
		}

		return 'orphan_facets';
	}
}

/**
 * @covers \Akibara\Search\Reconciler
 */
final class ReconcilerProtocolTest extends TestCase {

	/** ¿El dueño apagó la indexación a propósito? Lo decide cada prueba. */
	private bool $indexacion_apagada = false;

	private ReconcilerProtocolWpdb $wpdb;
	private Reconciler $sut;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	private int $wake_count = 0;
	private bool $persist_summary = true;

	/** @var mixed Resultado controlado de wc_get_product en el spot-check. */
	private $sample_product = null;

	private bool $sample_product_throws = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! class_exists( 'WC_Product', false ) ) {
			class_alias( ReconcilerProtocolProductStub::class, 'WC_Product' );
		}

		$this->wpdb            = new ReconcilerProtocolWpdb();
		$this->options         = array(
			Install::OPTION_KEY         => Install::DB_VERSION,
			Install::OPTION_SCHEMA_SEAL => Install::schema_seal(),
		);
		$this->transients      = array();
		$this->wake_count      = 0;
		$this->persist_summary = true;
		$this->sample_product = null;
		$this->sample_product_throws = false;
		$GLOBALS['wpdb']       = $this->wpdb;
		EditorialDiscountPublication::set_test_price_match_sequence( null );

		$work          = new WorkRepository( $this->wpdb, 'wp_akb_search_work' );
		$builder       = new DocumentBuilder();
		$source_authority = new ProductSourceAuthority( $this->wpdb, static fn( array $ids ): array => $ids );
		$indexer       = new Indexer( $builder, $work, new WriteFence( $this->wpdb, 'akb-reconciler-test' ), $source_authority );
		$this->sut     = new Reconciler( $indexer, $builder, $source_authority );
		$options       = &$this->options;
		$transients    = &$this->transients;

		// El protocolo se mide con la indexación ENCENDIDA, que es como vive en
		// producción. Con ella apagada a propósito la corrida sólo poda el
		// registro de búsquedas (S-d62426cd): ese camino tiene sus pruebas al final.
		$this->indexacion_apagada = false;
		\Patchwork\redefine(
			\Akibara\Search\Search::class . '::indexing_switched_off',
			fn(): bool => $this->indexacion_apagada
		);

		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				if ( ! $this->persist_summary ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static function ( string $name ) use ( &$transients ) {
				return $transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $name, $value ) use ( &$transients ): bool {
				$transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $name ) use ( &$transients ): bool {
				$existed = array_key_exists( $name, $transients );
				unset( $transients[ $name ] );
				return $existed;
			}
		);
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wc_get_product' )->alias(
			function () {
				if ( $this->sample_product_throws ) {
					throw new \RuntimeException( 'forced product hydration throwable' );
				}
				return $this->sample_product;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_generate_uuid4' )->justReturn( '00000000-0000-4000-8000-000000000001' );
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_enqueue_async_action' )->alias(
			function (): int {
				++$this->wake_count;
				return 1;
			}
		);
	}

	protected function tearDown(): void {
		\Patchwork\restoreAll();
		EditorialDiscountPublication::set_test_price_match_sequence( null );
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Gate de schema detiene la corrida antes de cualquier consulta del reconciliador.
	 */
	public function test_schema_gate_fails_before_database_work(): void {
		unset( $this->options[ Install::OPTION_SCHEMA_SEAL ] );

		$summary = $this->sut->run();

		self::assertFalse( $summary['ok'] );
		self::assertSame( 'runtime_schema_not_ready', $summary['error'] );
		self::assertSame( array(), $this->wpdb->queries );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options );
		self::assertSame( 'runtime_schema_not_ready', $this->options[ Reconciler::OPTION_LAST_ERROR ]['error'] ?? null );
	}

	/**
	 * @testdox Action Scheduler ID cero no crea un memo positivo que suprima la reparación mientras dure.
	 */
	public function test_schedule_failure_never_seals_scheduled_memo(): void {
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_recurring_action' )->justReturn( 0 );

		$this->sut->ensure_scheduled();

		self::assertArrayNotHasKey( 'akb_search_reconciler_scheduled', $this->transients );
	}

	/**
	 * @testdox Una action recurrente verificada sí habilita el memo barato.
	 */
	public function test_verified_schedule_seals_scheduled_memo(): void {
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_recurring_action' )->justReturn( 91 );

		$this->sut->ensure_scheduled();

		self::assertSame( 'yes', $this->transients['akb_search_reconciler_scheduled'] ?? null );
	}

	/**
	 * @testdox Todas las fuentes se deduplican, ordenan y materializan por lotes con un solo wake-up.
	 */
	public function test_deduplicates_and_batches_all_repair_sources_with_one_wake(): void {
		$this->wpdb->id_sets['missing']       = range( 1, 100 );
		$this->wpdb->id_sets['orphaned']      = range( 51, 150 );
		$this->wpdb->id_sets['stale']         = range( 101, 200 );
		$this->wpdb->id_sets['orphan_facets'] = array( 200, 1, 200 );

		$summary = $this->sut->run();

		self::assertTrue( $summary['ok'] );
		self::assertSame( 200, $summary['repair_queued'] );
		self::assertSame( range( 1, 200 ), $this->wpdb->durable_ids );
		self::assertSame( 2, $this->wpdb->work_insert_count );
		self::assertSame( 1, $this->wake_count );
		self::assertSame( $summary, $this->options[ Reconciler::OPTION_LAST ] ?? null );
	}

	/**
	 * @testdox Cada lectura SQL fallida corta la corrida y nunca sella un resumen parcial.
	 *
	 * @dataProvider read_failure_provider
	 */
	public function test_read_failures_never_publish_partial_success( string $operation, string $expected_error ): void {
		$this->wpdb->fail_operation = $operation;

		$summary = $this->sut->run();

		self::assertFalse( $summary['ok'] );
		self::assertSame( $expected_error, $summary['error'] );
		self::assertSame( 0, $this->wpdb->work_insert_count );
		self::assertSame( 0, $this->wake_count );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function read_failure_provider(): array {
		return array(
			'missing'       => array( 'missing', 'missing_read_failed' ),
			'orphaned'      => array( 'orphaned', 'orphaned_read_failed' ),
			'stale'         => array( 'stale', 'stale_read_failed' ),
			'orphan facets' => array( 'orphan_facets', 'orphan_facets_read_failed' ),
			'sample'        => array( 'sample', 'sample_read_failed' ),
			'qlog count'    => array( 'qlog_count', 'qlog_count_failed' ),
		);
	}

	/**
	 * @testdox Throwable, WP_Error, false y last_error de prepare fallan antes de ejecutar SQL o sellar éxito.
	 * @dataProvider prepare_failure_provider
	 */
	public function test_prepare_failures_are_observable_and_fail_closed( string $failure ): void {
		$this->wpdb->prepare_failure = $failure;

		$summary = $this->sut->run();

		self::assertFalse( $summary['ok'], $failure );
		self::assertSame( 'missing_read_failed', $summary['error'], $failure );
		self::assertSame( array(), $this->wpdb->queries, $failure );
		self::assertSame( 0, $this->wpdb->work_insert_count, $failure );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options, $failure );
	}

	/** @return array<string, array{string}> */
	public function prepare_failure_provider(): array {
		return array(
			'throwable'  => array( 'throw' ),
			'WP_Error'   => array( 'wp_error' ),
			'false'      => array( 'false' ),
			'last_error' => array( 'last_error' ),
		);
	}

	/** @testdox IDs con sufijo nunca se convierten en otra deuda ni muestra válida. */
	public function test_malformed_database_ids_fail_instead_of_being_coerced(): void {
		$this->wpdb->id_sets['missing'] = array( '7x' );
		$summary = $this->sut->run();
		self::assertFalse( $summary['ok'] );
		self::assertSame( 'missing_read_failed', $summary['error'] );
		self::assertSame( array(), $this->wpdb->durable_ids );

		$this->wpdb->id_sets['missing'] = array();
		$this->wpdb->sample = array( (object) array( 'post_id' => '7x' ) );
		$summary = $this->sut->run();
		self::assertFalse( $summary['ok'] );
		self::assertSame( 'sample_row_invalid', $summary['error'] );
		self::assertSame( array(), $this->wpdb->durable_ids );
	}

	/** @testdox Una mutación posterior al primer match corta justo antes del enqueue durable. */
	public function test_price_authority_is_rechecked_immediately_before_repair_enqueue(): void {
		$this->wpdb->id_sets['missing'] = array( 17 );
		EditorialDiscountPublication::set_test_price_match_sequence( array( true, false ) );

		$summary = $this->sut->run();

		self::assertFalse( $summary['ok'] );
		self::assertSame( 'price_authority_changed', $summary['error'] );
		self::assertSame( array(), $this->wpdb->durable_ids );
		self::assertSame( 0, $this->wpdb->work_insert_count );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options );
	}

	/** @testdox Mutaciones justo antes de alerta u OPTION_LAST no publican resumen final. */
	public function test_price_authority_is_rechecked_before_alert_and_summary_receipts(): void {
		EditorialDiscountPublication::set_test_price_match_sequence( array( true, false ) );
		$summary = $this->sut->run();
		self::assertFalse( $summary['ok'], 'alert' );
		self::assertSame( 'price_authority_changed', $summary['error'], 'alert' );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options, 'alert' );

		EditorialDiscountPublication::set_test_price_match_sequence( array( true, true, false ) );
		$summary = $this->sut->run();
		self::assertFalse( $summary['ok'], 'summary' );
		self::assertSame( 'price_authority_changed', $summary['error'], 'summary' );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options, 'summary' );
	}

	/**
	 * @testdox Fallos de retención y cap del qlog son explícitos y no publican éxito.
	 *
	 * @dataProvider qlog_write_failure_provider
	 */
	public function test_qlog_write_failures_are_observable( string $operation, string $expected_error, int $rows ): void {
		$this->wpdb->fail_operation = $operation;
		$this->wpdb->qlog_rows      = $rows;

		$summary = $this->sut->run();

		self::assertFalse( $summary['ok'] );
		self::assertSame( $expected_error, $summary['error'] );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options );
	}

	/**
	 * @return array<string, array{string, string, int}>
	 */
	public function qlog_write_failure_provider(): array {
		return array(
			'retention' => array( 'qlog_retention', 'qlog_retention_failed', 0 ),
			'hard cap'  => array( 'qlog_cap', 'qlog_cap_failed', 50001 ),
		);
	}

	/**
	 * @testdox Un batch durable parcial despierta el worker pero no se reporta ni persiste como éxito.
	 */
	public function test_partial_durable_batch_is_not_false_success(): void {
		$this->wpdb->id_sets['missing'] = range( 1, 150 );
		$this->wpdb->fail_work_insert_at = 2;

		$summary = $this->sut->run();

		self::assertFalse( $summary['ok'] );
		self::assertSame( 'repair_enqueue_failed', $summary['error'] );
		self::assertSame( range( 1, 100 ), $this->wpdb->durable_ids );
		self::assertSame( 1, $this->wake_count, 'El lote durable parcial igual debe tener un worker que lo drene.' );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options );
	}

	/**
	 * @testdox Un fallo al persistir el resumen final nunca retorna ok.
	 */
	public function test_summary_persistence_is_verified(): void {
		$this->persist_summary = false;

		$summary = $this->sut->run();

		self::assertFalse( $summary['ok'] );
		self::assertSame( 'summary_persist_failed', $summary['error'] );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options );
	}

	/** @testdox Un producto publish no hidratable en la muestra falla; no se degrada a reparación exitosa. */
	public function test_invalid_published_sample_product_is_a_source_failure(): void {
		$this->wpdb->sample = array( (object) array( 'post_id' => 71 ) );
		$this->sample_product = null;

		$summary = $this->sut->run();

		self::assertFalse( $summary['ok'] );
		self::assertSame( 'sample_product_read_failed', $summary['error'] );
		self::assertSame( 0, $this->wpdb->work_insert_count );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options );
	}

	/** @testdox Throwable al hidratar la muestra queda explícito y no publica éxito parcial. */
	public function test_published_sample_product_throwable_is_a_source_failure(): void {
		$this->wpdb->sample = array( (object) array( 'post_id' => 72 ) );
		$this->sample_product_throws = true;

		$summary = $this->sut->run();

		self::assertFalse( $summary['ok'] );
		self::assertSame( 'sample_product_read_failed', $summary['error'] );
		self::assertStringContainsString( 'forced product hydration', (string) ( $summary['error_detail'] ?? '' ) );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options );
	}

	/** @testdox Un objeto publish de otro ID no puede reparar ni sellar la muestra solicitada. */
	public function test_published_sample_wrong_product_identity_fails_closed(): void {
		$this->wpdb->sample = array( (object) array( 'post_id' => 73 ) );
		$this->sample_product = new \WC_Product( 999 );

		$summary = $this->sut->run();

		self::assertFalse( $summary['ok'] );
		self::assertSame( 'sample_product_read_failed', $summary['error'] );
		self::assertSame( 0, $this->wpdb->work_insert_count );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options );
	}

	/** @testdox Un status WC draft tras publish crudo es fuente incompatible, no repair normal. */
	public function test_published_sample_draft_product_fails_closed(): void {
		$this->wpdb->sample = array( (object) array( 'post_id' => 74 ) );
		$this->sample_product = new class( 74 ) extends \WC_Product {
			public function get_status(): string {
				return 'draft';
			}
		};

		$summary = $this->sut->run();

		self::assertFalse( $summary['ok'] );
		self::assertSame( 'sample_product_read_failed', $summary['error'] );
		self::assertSame( 0, $this->wpdb->work_insert_count );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options );
	}

	/**
	 * La acción diaria seguía agendada de cuando la indexación estaba encendida,
	 * y con ella apagada reparaba el índice igual cada noche: justo lo que el
	 * dueño había pedido que dejara de pasar (hallazgo S-d62426cd, 2026-09-19).
	 *
	 * @testdox Con la indexación apagada a propósito, la revisión diaria NO repara el índice.
	 */
	public function test_con_la_indexacion_apagada_no_se_repara_el_indice(): void {
		$this->indexacion_apagada       = true;
		$this->wpdb->id_sets['missing'] = range( 1, 100 );

		$summary = $this->sut->run();

		self::assertTrue( $summary['ok'] );
		self::assertSame( 'indexing_off', $summary['skipped'] ?? null );
		self::assertSame( 0, $summary['repair_queued'] );
		self::assertSame( array(), $this->wpdb->durable_ids, 'Ninguna reparación encolada.' );
		self::assertSame( 0, $this->wake_count );
		self::assertArrayNotHasKey( Reconciler::OPTION_LAST, $this->options, 'El último resumen sigue contando la última revisión REAL del índice.' );
	}

	/**
	 * La poda del registro de búsquedas vivía dentro de la revisión del índice, y
	 * apagar la indexación —otra palanca del panel— la apagaba también: la tabla
	 * crecía sin nadie que la recortara (hallazgo S-d7206eef).
	 *
	 * @testdox Con la indexación apagada, el registro de búsquedas se sigue podando.
	 */
	public function test_con_la_indexacion_apagada_el_registro_se_sigue_podando(): void {
		$this->indexacion_apagada = true;

		$this->sut->run();

		$sql = implode( ' | ', $this->wpdb->queries );
		self::assertStringContainsString( 'DELETE FROM', $sql );
		self::assertStringContainsString( 'akb_search_qlog', $sql, 'La retención del registro corre igual.' );
		self::assertStringNotContainsString( 'LEFT JOIN', $sql, 'Y no se lee el catálogo contra el índice.' );
	}
}
