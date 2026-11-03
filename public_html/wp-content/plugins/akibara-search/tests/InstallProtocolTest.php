<?php
/**
 * Contrato focal del schema durable y la topología crash-safe del protocolo v2.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Install;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Doble mínimo para las sondas de information_schema y DDL de Install.
 */
final class InstallProtocolWpdb {

	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $last_error = '';

	/** @var array<int, string> */
	public array $queries = array();

	/** @var array<int, array<int, string>> */
	private array $snapshots;

	/** @var int|false */
	private $query_result;

	/** @var array<int, mixed> */
	private array $var_results;

	/** @var array<int, array<int, object>|null> */
	private array $row_results;

	/** @var array<int, string> */
	private array $snapshot_errors;

	/** @var array<int, string> */
	private array $var_errors;

	/** @var array<int, string> */
	private array $row_errors;

	/**
	 * @param array<int, array<int, string>> $snapshots   Tablas presentes por sonda.
	 * @param int|false                      $query_result Resultado de DDL.
	 * @param array<int, mixed>              $var_results Respuestas de get_var().
	 * @param array<int, array<int, object>> $row_results Respuestas de get_results().
	 * @param array<int, string>             $snapshot_errors Error asociado a cada get_col().
	 * @param array<int, string>             $var_errors Error asociado a cada get_var().
	 * @param array<int, string>             $row_errors Error asociado a cada get_results().
	 */
	public function __construct( array $snapshots = array(), $query_result = 0, array $var_results = array(), array $row_results = array(), array $snapshot_errors = array(), array $var_errors = array(), array $row_errors = array() ) {
		$this->snapshots       = $snapshots;
		$this->query_result    = $query_result;
		$this->var_results     = $var_results;
		$this->row_results     = $row_results;
		$this->snapshot_errors = $snapshot_errors;
		$this->var_errors      = $var_errors;
		$this->row_errors      = $row_errors;
	}

	/** @return string */
	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/**
	 * @param string $query Sentencia con placeholders.
	 * @param mixed  ...$args Valores.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$escaped = array_map(
			static fn( $value ): string => "'" . str_replace( "'", "''", (string) $value ) . "'",
			$args
		);

		return vsprintf( str_replace( '%s', '%s', $query ), $escaped );
	}

	/**
	 * @param string $query Consulta de metadata.
	 * @return array<int, string>
	 */
	public function get_col( string $query ): array {
		$this->queries[]  = $query;
		$this->last_error = array_shift( $this->snapshot_errors ) ?? '';
		return array_shift( $this->snapshots ) ?? array();
	}

	/**
	 * @param string $query Consulta escalar.
	 * @return mixed
	 */
	public function get_var( string $query ) {
		$this->queries[]  = $query;
		$this->last_error = array_shift( $this->var_errors ) ?? '';
		return array_shift( $this->var_results );
	}

	/**
	 * @param string $query Consulta de filas.
	 * @return array<int, object>|null
	 */
	public function get_results( string $query ): ?array {
		$this->queries[]  = $query;
		$this->last_error = array_shift( $this->row_errors ) ?? '';
		return array_shift( $this->row_results );
	}

	/**
	 * @param string $query DDL.
	 * @return int|false
	 */
	public function query( string $query ) {
		$this->queries[] = $query;
		return $this->query_result;
	}
}

/** Emula la collation CI/PAD SPACE de wp_options para el CAS raw. */
final class InstallOptionCollationWpdb {

	public string $options = 'wp_options';

	public string $last_error = '';

	/** @var array<int, string> */
	public array $queries = array();

	private string $raw;

	public function __construct( string $raw ) {
		$this->raw = $raw;
	}

	/** @param mixed ...$args Valores SQL. */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace( '/%s/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function query( string $query ): int {
		$this->queries[] = $query;
		preg_match_all( "/'((?:''|[^'])*)'/", $query, $matches );
		$values   = array_map( static fn( string $value ): string => str_replace( "''", "'", $value ), $matches[1] ?? array() );
		$expected = (string) end( $values );
		$equal    = false !== strpos( $query, 'BINARY option_value = BINARY' )
			? $this->raw === $expected
			: strtolower( rtrim( $this->raw ) ) === strtolower( rtrim( $expected ) );
		if ( ! $equal ) {
			return 0;
		}
		if ( 0 === stripos( ltrim( $query ), 'UPDATE' ) ) {
			$this->raw = (string) ( $values[0] ?? '' );
		}
		return 1;
	}

	/** @return string */
	public function get_var( string $query ): string {
		$this->queries[] = $query;
		return $this->raw;
	}
}

/**
 * @covers \Akibara\Search\Install
 */
final class InstallProtocolTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	private bool $persist_options = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options         = array();
		$this->persist_options = true;
		$options                = &$this->options;
		$persist                = &$this->persist_options;

		// `runtime_schema_ready()` ceba las dos claves del esquema UNA vez por
		// proceso. Este archivo daba por hecho que otra prueba ya lo había hecho:
		// en cuanto corría antes que esas, fallaba con «wp_prime_option_caches is
		// not defined» — un rojo del arnés que sólo salía en orden aleatorio.
		Functions\when( 'wp_prime_option_caches' )->justReturn( null );

		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$options, &$persist ): bool {
				if ( ! $persist ) {
					return false;
				}

				$options[ $name ] = $value;
				return true;
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
		Functions\when( 'wp_generate_uuid4' )->alias(
			static function (): string {
				static $seq = 0;
				++$seq;
				return '00000000-0000-4000-8000-' . str_pad( (string) $seq, 12, '0', STR_PAD_LEFT );
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) use ( &$options ): bool {
				if ( ! array_key_exists( $name, $options ) ) {
					return false;
				}

				unset( $options[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Generation target es idempotente y reporta false si no queda persistido.
	 */
	public function test_generation_target_is_verified_and_replayable(): void {
		self::assertTrue( Install::bump_index_generation( 'generation-run-7' ) );
		self::assertSame( 'generation-run-7', $this->options[ Install::OPTION_INDEX_GEN ] ?? null );
		self::assertTrue( Install::bump_index_generation( 'generation-run-7' ) );

		$this->persist_options = false;
		self::assertFalse( Install::bump_index_generation( 'generation-run-8' ) );
		self::assertSame( 'generation-run-7', $this->options[ Install::OPTION_INDEX_GEN ] ?? null );
	}

	/**
	 * @testdox Claim de versión ausente usa INSERT IGNORE y no pisa una versión ganadora concurrente.
	 */
	public function test_fresh_version_claim_is_true_insert_only_cas(): void {
		$winner = new InstallProtocolWpdb( array(), 1, array( Install::DB_VERSION ) );
		$GLOBALS['wpdb'] = $winner;
		self::assertTrue( (bool) $this->invoke_install_static( 'claim_version', false ) );
		self::assertStringContainsString( 'INSERT IGNORE INTO wp_options', $winner->queries[0] ?? '' );

		$loser = new InstallProtocolWpdb( array(), 0, array( '9.9.9-foreign' ) );
		$GLOBALS['wpdb'] = $loser;
		self::assertFalse( (bool) $this->invoke_install_static( 'claim_version', false ) );
		self::assertStringContainsString( 'INSERT IGNORE INTO wp_options', $loser->queries[0] ?? '' );
		self::assertStringNotContainsString( 'UPDATE wp_options', implode( "\n", $loser->queries ) );
	}

	/**
	 * @testdox CAS raw usa comparación binaria y pierde ante diferencias sólo de case o padding.
	 */
	public function test_option_cas_is_binary_not_ci_or_pad_space(): void {
		foreach (
			array(
				array( 'alpha', 'Alpha' ),
				array( 'value', 'value ' ),
			) as $case
		) {
			$GLOBALS['wpdb'] = new InstallOptionCollationWpdb( $case[0] );
			self::assertFalse(
				(bool) $this->invoke_install_static( 'replace_option_cas', 'akb_cas_test', $case[1], 'replacement', false )
			);
			self::assertStringContainsString(
				'BINARY option_value = BINARY',
				implode( "\n", $GLOBALS['wpdb']->queries )
			);
		}
	}

	/**
	 * @testdox Defaults equivalentes de MariaDB y MySQL sellan el mismo contrato físico.
	 */
	public function test_mariadb_column_default_representation_is_normalised(): void {
		self::assertSame( '', $this->invoke_install_static( 'normalise_column_default', "''" ) );
		self::assertSame( 'pending', $this->invoke_install_static( 'normalise_column_default', "'pending'" ) );
		self::assertNull( $this->invoke_install_static( 'normalise_column_default', 'NULL' ) );
		self::assertSame( 'current_timestamp', $this->invoke_install_static( 'normalise_column_default', 'current_timestamp()' ) );
	}

	/**
	 * @testdox El rebuild de una migración espera la gracia rolling y conserva la señal durable.
	 */
	public function test_pending_rebuild_waits_for_rolling_deployment_grace(): void {
		$calls  = 0;
		$signal = array(
			'version'    => Install::DB_VERSION,
			'not_before' => time() + 120,
		);
		$this->options[ Install::OPTION_PENDING_REBUILD ] = $signal;

		Install::consume_pending_rebuild(
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);

		self::assertSame( 0, $calls );
		self::assertSame( $signal, $this->options[ Install::OPTION_PENDING_REBUILD ] ?? null );
	}

	/**
	 * @testdox Tras la gracia sólo un consumidor ejecuta y un wake fallido rearma la misma señal.
	 */
	public function test_pending_rebuild_consumes_after_grace_and_rearms_on_failure(): void {
		$this->mark_runtime_ready();
		$signal = array(
			'version'    => Install::DB_VERSION,
			'not_before' => time() - 1,
		);
		$this->options[ Install::OPTION_PENDING_REBUILD ] = $signal;

		Install::consume_pending_rebuild( static fn(): bool => false );
		self::assertSame( $signal, $this->options[ Install::OPTION_PENDING_REBUILD ] ?? null );

		Install::consume_pending_rebuild(
			static fn( string $token, int $seq ): array => array(
				'run_id'        => 'run-durable-1',
				'request_token' => $token,
				'request_seq'   => $seq,
				'durable'       => true,
			)
		);
		self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->options );
	}

	/**
	 * @testdox Coalescing cambia seq, conserva el token y nunca acorta not_before.
	 */
	public function test_coalesced_request_changes_sequence_and_preserves_max_grace(): void {
		self::assertTrue( Install::request_rebuild( 'manual', time() + 10 ) );
		$first = Install::pending_rebuild_request();

		self::assertTrue( Install::request_rebuild( 'config_change', time() + 120 ) );
		$second = Install::pending_rebuild_request();

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( $first['token'], $second['token'] );
		self::assertSame( $first['seq'] + 1, $second['seq'] );
		self::assertGreaterThanOrEqual( time() + 119, $second['not_before'] );
	}

	/**
	 * @testdox Una migración acuña requested_at y la gracia rolling desde el mismo instante.
	 */
	public function test_schema_migration_grace_is_exactly_three_hundred_seconds(): void {
		self::assertTrue( Install::request_rebuild( 'schema_migration', null, Install::DB_VERSION ) );
		$request = Install::pending_rebuild_request();

		self::assertIsArray( $request );
		self::assertSame(
			Install::REBUILD_GRACE_SECONDS,
			(int) $request['not_before'] - (int) $request['requested_at']
		);
	}

	/**
	 * @testdox Una señal funcional coalescida no recorta el reloj de una migración.
	 */
	public function test_functional_coalescing_preserves_schema_migration_clock(): void {
		$requests = array(
			array(
				'token'        => 'migration-token',
				'seq'          => 1,
				'version'      => Install::DB_VERSION,
				'reason'       => 'schema_migration',
				'requested_at' => 1000,
				'not_before'   => 1000 + Install::REBUILD_GRACE_SECONDS,
			),
		);
		$merged   = $this->invoke_install_static(
			'merge_rebuild_request',
			$requests,
			'runtime_schema_unready',
			Install::DB_VERSION,
			1001,
			null,
			1001
		);

		self::assertCount( 1, $merged );
		self::assertSame( 2, $merged[0]['seq'] );
		self::assertSame( 'schema_migration', $merged[0]['reason'] );
		self::assertSame( 1000, $merged[0]['requested_at'] );
		self::assertSame( 1000 + Install::REBUILD_GRACE_SECONDS, $merged[0]['not_before'] );
		self::assertSame(
			Install::REBUILD_GRACE_SECONDS,
			(int) $merged[0]['not_before'] - (int) $merged[0]['requested_at']
		);
	}

	/**
	 * @testdox Una migración con cola llena reemplaza follow-up y conserva su gracia.
	 */
	public function test_full_queue_never_drops_schema_migration(): void {
		$requests = array();
		for ( $i = 1; $i <= 4; ++$i ) {
			$requests[] = array(
				'token'        => 'token-' . $i,
				'seq'          => 1,
				'version'      => Install::DB_VERSION,
				'reason'       => 'manual',
				'requested_at' => 1,
				'not_before'   => 1,
			);
		}
		$merged = $this->invoke_install_static(
			'merge_rebuild_request',
			$requests,
			'schema_migration',
			Install::DB_VERSION,
			9999,
			array( 'token' => 'token-1', 'seq' => 1 )
		);

		self::assertCount( 4, $merged );
		self::assertSame( 'schema_migration', $merged[3]['reason'] );
		self::assertSame( 9999, $merged[3]['not_before'] );
		self::assertSame( 'token-4', $merged[3]['token'] );
		self::assertSame( 2, $merged[3]['seq'] );
	}

	/**
	 * @testdox El schema 1.7.0 crea work InnoDB sólo en live con su PK e índices de claim.
	 */
	public function test_work_schema_is_live_only_and_innodb(): void {
		$GLOBALS['wpdb'] = new InstallProtocolWpdb();

		$method = new \ReflectionMethod( Install::class, 'schema_sql' );
		$method->setAccessible( true );

		$live_sql   = $method->invoke( null, '' );
		$shadow_sql = $method->invoke( null, Install::SHADOW_SUFFIX );
		$work_sql   = implode( "\n", array_filter( $live_sql, static fn( string $sql ): bool => false !== strpos( $sql, Install::work_table() ) ) );

		// El candado era el literal de la versión, que caduca en cada migración y
		// no protege nada. Lo que importa es que la versión SEA una versión: el
		// guard de `maybe_install()` compara la option contra esta constante, y
		// una cadena vacía o no-semver deja la migración sin poder decidir.
		self::assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', Install::DB_VERSION );
		self::assertStringContainsString( 'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', $work_sql );
		self::assertStringContainsString( 'PRIMARY KEY  (id)', $work_sql );
		self::assertStringContainsString( 'UNIQUE KEY uq_scope_run_product (scope, run_id, product_id)', $work_sql );
		self::assertStringContainsString( 'KEY claim (status, available_at, lease_until)', $work_sql );
		self::assertStringContainsString( 'KEY run_outstanding (run_id, scope, status)', $work_sql );
		self::assertStringContainsString( 'ENGINE=InnoDB', $work_sql );
		self::assertStringNotContainsString( Install::work_table(), implode( "\n", $shadow_sql ) );
		self::assertCount( 3, $shadow_sql );
	}

	/**
	 * @testdox El sello físico de work exige engine, columnas e índices exactos.
	 */
	public function test_work_schema_verifier_checks_real_index_contract(): void {
		$column_names = array(
			'id', 'scope', 'run_id', 'product_id', 'wanted_seq', 'acked_seq',
			'outcome', 'status', 'available_at', 'lease_token', 'lease_seq',
			'lease_until', 'attempts', 'last_error', 'created_at', 'updated_at',
		);
		$columns      = $this->column_rows( 'work' );
		$indexes      = $this->index_rows();

		$GLOBALS['wpdb'] = new InstallProtocolWpdb( array(), 0, array( 'InnoDB' ), array( $columns, $indexes ) );
		self::assertTrue( Install::work_schema_ready() );

		// Mismo nombre de KEY, pero orden incorrecto: no puede sellar 1.7.0.
		$indexes[4]->column_name = 'lease_until';
		$GLOBALS['wpdb']         = new InstallProtocolWpdb( array(), 0, array( 'InnoDB' ), array( $columns, $indexes ) );
		self::assertFalse( Install::work_schema_ready() );
	}

	/**
	 * @testdox Un único blip de metadata es retry y la lectura siguiente puede probar READY; un engine real incompatible es invalid.
	 */
	public function test_ready_swap_schema_inspection_is_tristate_across_one_metadata_blip(): void {
		$this->mark_runtime_ready();
		$live   = $this->schema_metadata( array( '' ) );
		$shadow = $this->schema_metadata( array( Install::SHADOW_SUFFIX ) );
		$vars   = array_merge( array( 'InnoDB' ), $live['vars'], array( 'InnoDB' ), $shadow['vars'] );
		$rows   = array_merge(
			$live['rows'],
			array( $this->column_rows( 'work' ), $this->index_rows() ),
			$shadow['rows']
		);
		$GLOBALS['wpdb'] = new InstallProtocolWpdb(
			array(),
			0,
			$vars,
			$rows,
			array(),
			array_merge( array( 'metadata blip' ), array_fill( 0, 7, '' ) )
		);

		self::assertSame( Install::SCHEMA_CHECK_RETRY, Install::inspect_ready_swap_schema() );
		self::assertSame( Install::SCHEMA_CHECK_READY, Install::inspect_ready_swap_schema() );

		$GLOBALS['wpdb'] = new InstallProtocolWpdb( array(), 0, array( 'MyISAM' ) );
		self::assertSame( Install::SCHEMA_CHECK_INVALID, Install::inspect_ready_swap_schema() );

		unset( $this->options[ Install::OPTION_SCHEMA_SEAL ] );
		$GLOBALS['wpdb'] = new InstallProtocolWpdb();
		self::assertSame( Install::SCHEMA_CHECK_RETRY, Install::inspect_ready_swap_schema() );
		self::assertSame( array(), $GLOBALS['wpdb']->queries, 'Un sello runtime en transición no prueba incompatibilidad física.' );
	}

	/**
	 * @testdox El backfill sku_norm fallido aborta install; cero filas sigue siendo éxito idempotente.
	 */
	public function test_sku_norm_backfill_failure_cannot_be_sealed_as_installed(): void {
		$GLOBALS['wpdb'] = new InstallProtocolWpdb( array(), false );
		self::assertFalse( (bool) $this->invoke_install_static( 'backfill_sku_norm' ) );

		$GLOBALS['wpdb'] = new InstallProtocolWpdb( array(), 0 );
		self::assertTrue( (bool) $this->invoke_install_static( 'backfill_sku_norm' ) );

		$source       = (string) file_get_contents( dirname( __DIR__ ) . '/src/Install.php' );
		$maybe        = strpos( $source, 'public static function maybe_install()' );
		$install_call = strpos( $source, '! self::install()', (int) $maybe );
		$seal         = strpos( $source, 'update_option( self::OPTION_SCHEMA_SEAL', (int) $maybe );
		$install      = strpos( $source, 'public static function install()' );
		$backfill     = strpos( $source, 'if ( ! self::backfill_sku_norm() )', (int) $install );
		$return       = strpos( $source, 'return false;', (int) $backfill );

		self::assertIsInt( $install_call );
		self::assertIsInt( $backfill );
		self::assertIsInt( $return );
		self::assertIsInt( $seal );
		self::assertTrue( $maybe < $install_call && $install_call < $seal );
		self::assertTrue( $install < $backfill && $backfill < $return );
	}

	/**
	 * @testdox La topología reconoce live, pre-swap y post-swap exactos; todo set parcial queda ambiguo.
	 */
	public function test_topology_classifier_is_fail_closed(): void {
		$live = $this->tables();
		$new  = $this->tables( Install::SHADOW_SUFFIX );
		$old  = $this->tables( Install::OLD_SUFFIX );

		$GLOBALS['wpdb'] = new InstallProtocolWpdb(
			array(
				$live,
				array_merge( $live, $new ),
				array_merge( $live, $old ),
				array_merge( $live, array( $new[0] ) ),
			)
		);

		self::assertSame( Install::TOPOLOGY_CLEAN_PRE, Install::classify_topology() );
		self::assertSame( Install::TOPOLOGY_READY_SWAP, Install::classify_topology() );
		self::assertSame( Install::TOPOLOGY_SWAPPED, Install::classify_topology() );
		self::assertSame( Install::TOPOLOGY_AMBIGUOUS, Install::classify_topology() );
	}

	/**
	 * @testdox RENAME conserva old y no ejecuta ningún DROP implícito.
	 */
	public function test_rename_keep_old_preserves_rollback_set(): void {
		$live = $this->tables();
		$new  = $this->tables( Install::SHADOW_SUFFIX );
		$old  = $this->tables( Install::OLD_SUFFIX );
		$metadata = $this->schema_metadata( array( '', Install::SHADOW_SUFFIX, '', Install::OLD_SUFFIX ) );
		$wpdb     = new InstallProtocolWpdb(
			array( array_merge( $live, $new ), array_merge( $live, $old ) ),
			0,
			$metadata['vars'],
			$metadata['rows']
		);
		$GLOBALS['wpdb'] = $wpdb;

		self::assertSame(
			Install::RENAME_RESULT_SWAPPED,
			Install::rename_keep_old_result( null, static function (): void {} )
		);

		$ddl = implode( "\n", $wpdb->queries );
		self::assertStringContainsString( 'RENAME TABLE', $ddl );
		self::assertStringNotContainsString( 'DROP TABLE', $ddl );
		self::assertSame( 1, substr_count( $ddl, ' TO ' . '`wp_akb_search_docs_old`' ) );
	}

	/**
	 * @testdox Cleanup old opera sólo desde swapped y verifica clean_pre.
	 */
	public function test_cleanup_old_requires_exact_swapped_topology(): void {
		$live = $this->tables();
		$old  = $this->tables( Install::OLD_SUFFIX );
		$metadata = $this->schema_metadata( array( '', Install::OLD_SUFFIX ) );
		$wpdb     = new InstallProtocolWpdb( array( array_merge( $live, $old ), $live ), 0, $metadata['vars'], $metadata['rows'] );
		$GLOBALS['wpdb'] = $wpdb;

		self::assertTrue( Install::cleanup_old( static function (): void {} ) );

		$ddl = implode( "\n", $wpdb->queries );
		self::assertStringContainsString( 'DROP TABLE `wp_akb_search_docs_old`', $ddl );
		self::assertStringNotContainsString( 'IF EXISTS', $ddl );
	}

	/**
	 * @testdox Una topología ambigua no dispara RENAME ni DROP.
	 */
	public function test_ambiguous_topology_is_never_mutated(): void {
		$live = $this->tables();
		$new  = $this->tables( Install::SHADOW_SUFFIX );
		$wpdb = new InstallProtocolWpdb( array( array_merge( $live, array( $new[0] ) ) ) );
		$GLOBALS['wpdb'] = $wpdb;

		self::assertSame(
			Install::RENAME_RESULT_INVALID,
			Install::rename_keep_old_result( null, static function (): void {} )
		);

		$ddl = array_filter(
			$wpdb->queries,
			static fn( string $query ): bool => 0 === strpos( $query, 'RENAME ' ) || 0 === strpos( $query, 'DROP ' )
		);
		self::assertSame( array(), array_values( $ddl ) );
	}

	/**
	 * @testdox Crash tras crear 1 de 3 shadows converge a clean sólo con owner PREPARING.
	 */
	public function test_owned_partial_shadow_can_be_retired_idempotently(): void {
		$live = $this->tables();
		$new  = $this->tables( Install::SHADOW_SUFFIX );
		$wpdb = new InstallProtocolWpdb( array( array_merge( $live, array( $new[0] ) ), $live ), 0 );
		$GLOBALS['wpdb'] = $wpdb;

		self::assertTrue( Install::recover_owned_partial_shadow( static function (): void {} ) );
		$ddl = implode( "\n", $wpdb->queries );
		self::assertStringContainsString( 'DROP TABLE `wp_akb_search_docs_new`', $ddl );
		self::assertStringNotContainsString( '`wp_akb_search_tokens_new`', $ddl );
	}

	/**
	 * @testdox Crash tras borrar 1 de 3 old completa sólo las tablas old presentes.
	 */
	public function test_acked_cleanup_can_finish_partial_old_drop(): void {
		$live = $this->tables();
		$old  = $this->tables( Install::OLD_SUFFIX );
		$wpdb = new InstallProtocolWpdb( array( array_merge( $live, array( $old[1], $old[2] ) ), $live ), 0 );
		$GLOBALS['wpdb'] = $wpdb;

		self::assertTrue( Install::recover_owned_partial_old( static function (): void {} ) );
		$ddl = implode( "\n", $wpdb->queries );
		self::assertStringNotContainsString( '`wp_akb_search_docs_old`', $ddl );
		self::assertStringContainsString( '`wp_akb_search_tokens_old`', $ddl );
		self::assertStringContainsString( '`wp_akb_search_facet_index_old`', $ddl );
	}

	/**
	 * @testdox Un blip al reinspeccionar un DROP parcial queda retryable y nunca se declara inválido.
	 */
	public function test_partial_family_post_drop_metadata_failure_is_retryable(): void {
		$live = $this->tables();
		$new  = $this->tables( Install::SHADOW_SUFFIX );
		$old  = $this->tables( Install::OLD_SUFFIX );

		foreach (
			array(
				'new' => array( array_merge( $live, array( $new[0] ) ), 'recover_owned_partial_shadow_result' ),
				'old' => array( array_merge( $live, array( $old[1], $old[2] ) ), 'recover_owned_partial_old_result' ),
			) as $case
		) {
			$wpdb = new InstallProtocolWpdb(
				array( $case[0], $live ),
				0,
				array(),
				array(),
				array( '', 'metadata blip' )
			);
			$GLOBALS['wpdb'] = $wpdb;

			self::assertSame(
				Install::PARTIAL_RECOVERY_RETRY,
				Install::{ $case[1] }( null, static function (): void {} )
			);
			self::assertStringContainsString( 'DROP TABLE', implode( "\n", $wpdb->queries ) );
		}
	}

	/**
	 * @testdox El resultado SQL false no vence a una reinspección que prueba DROP completo.
	 */
	public function test_partial_family_reinspection_is_authoritative_after_lost_ddl_ack(): void {
		$live = $this->tables();
		$new  = $this->tables( Install::SHADOW_SUFFIX );
		$wpdb = new InstallProtocolWpdb( array( array_merge( $live, array( $new[0] ) ), $live ), false );
		$GLOBALS['wpdb'] = $wpdb;

		self::assertSame(
			Install::PARTIAL_RECOVERY_RECOVERED,
			Install::recover_owned_partial_shadow_result( null, static function (): void {} )
		);
		self::assertStringContainsString( 'DROP TABLE', implode( "\n", $wpdb->queries ) );
	}

	/**
	 * @testdox RENAME con ACK perdido y metadata post fallida conserva SWAP_INTENT retryable.
	 */
	public function test_rename_post_ddl_metadata_failure_is_retryable(): void {
		$live     = $this->tables();
		$new      = $this->tables( Install::SHADOW_SUFFIX );
		$old      = $this->tables( Install::OLD_SUFFIX );
		$metadata = $this->schema_metadata( array( '', Install::SHADOW_SUFFIX ) );
		$wpdb     = new InstallProtocolWpdb(
			array( array_merge( $live, $new ), array_merge( $live, $old ) ),
			false,
			$metadata['vars'],
			$metadata['rows'],
			array( '', 'metadata blip' )
		);
		$GLOBALS['wpdb'] = $wpdb;

		$inspection = Install::inspect_table_topology();
		self::assertTrue( $inspection['valid'] );
		self::assertSame(
			Install::RENAME_RESULT_RETRY,
			Install::rename_keep_old_result( $inspection['topology'], static function (): void {} )
		);
		self::assertStringContainsString( 'RENAME TABLE', implode( "\n", $wpdb->queries ) );
	}

	/**
	 * @testdox Una reconexión detectada justo antes de RENAME impide ejecutar el DDL.
	 */
	public function test_lost_fence_immediately_before_rename_prevents_ddl(): void {
		$live     = $this->tables();
		$new      = $this->tables( Install::SHADOW_SUFFIX );
		$metadata = $this->schema_metadata( array( '', Install::SHADOW_SUFFIX ) );
		$wpdb     = new InstallProtocolWpdb(
			array( array_merge( $live, $new ) ),
			0,
			$metadata['vars'],
			$metadata['rows']
		);
		$GLOBALS['wpdb'] = $wpdb;

		self::assertSame(
			Install::RENAME_RESULT_RETRY,
			Install::rename_keep_old_result(
				null,
				static function (): void {
					throw new \RuntimeException( 'connection id changed' );
				}
			)
		);
		self::assertStringNotContainsString( 'RENAME TABLE', implode( "\n", $wpdb->queries ) );
	}

	/**
	 * @testdox Perder el fence durante RENAME no afirma SWAPPED; el watchdog debe reinspeccionar.
	 */
	public function test_lost_fence_immediately_after_rename_is_retryable(): void {
		$live     = $this->tables();
		$new      = $this->tables( Install::SHADOW_SUFFIX );
		$metadata = $this->schema_metadata( array( '', Install::SHADOW_SUFFIX ) );
		$wpdb     = new InstallProtocolWpdb(
			array( array_merge( $live, $new ) ),
			0,
			$metadata['vars'],
			$metadata['rows']
		);
		$GLOBALS['wpdb'] = $wpdb;
		$checks         = 0;

		self::assertSame(
			Install::RENAME_RESULT_RETRY,
			Install::rename_keep_old_result(
				null,
				static function () use ( &$checks ): void {
					++$checks;
					if ( 2 === $checks ) {
						throw new \RuntimeException( 'connection id changed' );
					}
				}
			)
		);
		self::assertSame( 2, $checks );
		self::assertStringContainsString( 'RENAME TABLE', implode( "\n", $wpdb->queries ) );
	}

	/**
	 * @testdox Una reconexión detectada justo antes de DROP impide retirar cualquier tabla.
	 */
	public function test_lost_fence_immediately_before_partial_drop_prevents_ddl(): void {
		$live = $this->tables();
		$new  = $this->tables( Install::SHADOW_SUFFIX );
		$wpdb = new InstallProtocolWpdb(
			array(
				array_merge( $live, array( $new[0] ) ),
				array_merge( $live, array( $new[0] ) ),
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		self::assertSame(
			Install::PARTIAL_RECOVERY_RETRY,
			Install::recover_owned_partial_shadow_result(
				null,
				static function (): void {
					throw new \RuntimeException( 'connection id changed' );
				}
			)
		);
		self::assertStringNotContainsString( 'DROP TABLE', implode( "\n", $wpdb->queries ) );
	}

	/**
	 * @testdox Estado legacy clean se retira; legacy con shadow se bloquea sin adivinar.
	 */
	public function test_legacy_migration_decision_distinguishes_clean_and_ready_swap(): void {
		$legacy = array( 'run_id' => 'legacy-1', 'phase' => 'BUILDING' );
		$v2     = array( 'protocol' => 2, 'run_id' => 'v2-1', 'phase' => 'PREPARING' );

		self::assertSame( 'retire', $this->invoke_install_static( 'legacy_migration_decision', $legacy, Install::TOPOLOGY_CLEAN_PRE ) );
		self::assertSame( 'block', $this->invoke_install_static( 'legacy_migration_decision', $legacy, Install::TOPOLOGY_READY_SWAP ) );
		self::assertSame( 'wait', $this->invoke_install_static( 'legacy_migration_decision', $v2, Install::TOPOLOGY_CLEAN_PRE ) );
	}

	/**
	 * @param string $suffix Sufijo opcional.
	 * @return array<int, string>
	 */
	private function tables( string $suffix = '' ): array {
		return array(
			'wp_akb_search_docs' . $suffix,
			'wp_akb_search_tokens' . $suffix,
			'wp_akb_search_facet_index' . $suffix,
		);
	}

	/**
	 * @return array<int, object>
	 */
	private function index_rows(): array {
		$contracts = array(
			'PRIMARY'              => array( 0, array( 'id' ) ),
			'claim'                => array( 1, array( 'status', 'available_at', 'lease_until' ) ),
			'run_outstanding'      => array( 1, array( 'run_id', 'scope', 'status' ) ),
			'uq_scope_run_product' => array( 0, array( 'scope', 'run_id', 'product_id' ) ),
		);
		$rows      = array();
		foreach ( $contracts as $name => $contract ) {
			foreach ( $contract[1] as $offset => $column ) {
				$rows[] = (object) array(
					'index_name'   => $name,
					'non_unique'  => $contract[0],
					'seq_in_index' => $offset + 1,
					'column_name'  => $column,
					'sub_part'     => null,
					'index_type'   => 'BTREE',
				);
			}
		}

		return $rows;
	}

	/** @return void */
	private function mark_runtime_ready(): void {
		$this->options[ Install::OPTION_KEY ]         = Install::DB_VERSION;
		$this->options[ Install::OPTION_SCHEMA_SEAL ] = '1.7.0-exact-1';
	}

	/**
	 * @param string $logical docs|tokens|facets|work.
	 * @return array<int, object>
	 */
	private function column_rows( string $logical ): array {
		$contracts = array(
			'docs' => array(
				array( 'post_id', 'bigint(20) unsigned', 'NO', null, '', null ),
				array( 'title', 'text', 'NO', null, '', 'utf8mb4_unicode_ci' ),
				array( 'title_norm', 'varchar(255)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'serie_norm', 'varchar(255)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'autores_norm', 'varchar(255)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'sku', 'varchar(64)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'sku_norm', 'varchar(64)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'tomo', 'smallint(5) unsigned', 'YES', null, '', null ),
				array( 'stock_rank', 'tinyint(3) unsigned', 'NO', '2', '', null ),
				array( 'menu_order', 'int(11)', 'NO', '0', '', null ),
				array( 'price_effective', 'decimal(12,2)', 'NO', '0.00', '', null ),
				array( 'visible', 'tinyint(1) unsigned', 'NO', '1', '', null ),
				array( 'url', 'varchar(255)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'thumb_url', 'varchar(255)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'price_html', 'varchar(1000)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'indexed_at', 'datetime', 'NO', null, '', null ),
			),
			'tokens' => array(
				array( 'token', 'varchar(40)', 'NO', null, '', 'utf8mb4_bin' ),
				array( 'field', 'tinyint(3) unsigned', 'NO', null, '', null ),
				array( 'post_id', 'bigint(20) unsigned', 'NO', null, '', null ),
			),
			'facets' => array(
				array( 'facet', 'varchar(32)', 'NO', null, '', 'utf8mb4_unicode_ci' ),
				array( 'post_id', 'bigint(20) unsigned', 'NO', null, '', null ),
				array( 'value', 'varchar(191)', 'NO', null, '', 'utf8mb4_unicode_ci' ),
				array( 'label', 'varchar(191)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'value_num', 'decimal(12,2)', 'YES', null, '', null ),
				array( 'term_id', 'bigint(20) unsigned', 'YES', null, '', null ),
				array( 'parent_id', 'bigint(20) unsigned', 'YES', null, '', null ),
			),
			'work' => array(
				array( 'id', 'bigint(20) unsigned', 'NO', null, 'auto_increment', null ),
				array( 'scope', 'varchar(16)', 'NO', null, '', 'utf8mb4_unicode_ci' ),
				array( 'run_id', 'varchar(64)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'product_id', 'bigint(20) unsigned', 'NO', null, '', null ),
				array( 'wanted_seq', 'bigint(20) unsigned', 'NO', '0', '', null ),
				array( 'acked_seq', 'bigint(20) unsigned', 'NO', '0', '', null ),
				array( 'outcome', 'varchar(16)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'status', 'varchar(16)', 'NO', 'pending', '', 'utf8mb4_unicode_ci' ),
				array( 'available_at', 'datetime', 'NO', 'current_timestamp()', '', null ),
				array( 'lease_token', 'varchar(64)', 'NO', '', '', 'utf8mb4_unicode_ci' ),
				array( 'lease_seq', 'bigint(20) unsigned', 'NO', '0', '', null ),
				array( 'lease_until', 'datetime', 'YES', null, '', null ),
				array( 'attempts', 'int(10) unsigned', 'NO', '0', '', null ),
				array( 'last_error', 'text', 'YES', null, '', 'utf8mb4_unicode_ci' ),
				array( 'created_at', 'datetime', 'NO', 'current_timestamp()', '', null ),
				array( 'updated_at', 'datetime', 'NO', 'current_timestamp()', 'on update current_timestamp()', null ),
			),
		);

		return array_map(
			static fn( array $row ): object => (object) array(
				'column_name'    => $row[0],
				'column_type'    => $row[1],
				'is_nullable'    => $row[2],
				'column_default' => $row[3],
				'extra'          => $row[4],
				'collation_name' => $row[5],
			),
			$contracts[ $logical ]
		);
	}

	/** @return array<int, object> */
	private function swappable_index_rows( string $logical ): array {
		$contracts = array(
			'docs' => array(
				'PRIMARY' => array( 0, array( 'post_id' ) ), 'idx_sku' => array( 1, array( 'sku' ) ),
				'idx_sku_norm' => array( 1, array( 'sku_norm' ) ), 'idx_visible_stock' => array( 1, array( 'visible', 'stock_rank' ) ),
			),
			'tokens' => array(
				'PRIMARY' => array( 0, array( 'token', 'field', 'post_id' ) ), 'idx_field_token' => array( 1, array( 'field', 'token' ) ), 'idx_post_token' => array( 1, array( 'post_id', 'token' ) ),
			),
			'facets' => array(
				'PRIMARY' => array( 0, array( 'facet', 'post_id', 'value' ) ), 'idx_facet_num' => array( 1, array( 'facet', 'value_num' ) ),
				'idx_facet_value' => array( 1, array( 'facet', 'value' ) ), 'idx_post' => array( 1, array( 'post_id' ) ),
			),
		);
		$rows = array();
		foreach ( $contracts[ $logical ] as $name => $contract ) {
			foreach ( $contract[1] as $offset => $column ) {
				$rows[] = (object) array(
					'index_name' => $name, 'non_unique' => $contract[0], 'seq_in_index' => $offset + 1,
					'column_name' => $column, 'sub_part' => null, 'index_type' => 'BTREE',
				);
			}
		}
		return $rows;
	}

	/**
	 * @param array<int, string> $suffixes Sets swappable a verificar, en orden.
	 * @return array{vars: array<int, string>, rows: array<int, array<int, object>>}
	 */
	private function schema_metadata( array $suffixes ): array {
		$vars = array();
		$rows = array();
		foreach ( $suffixes as $suffix ) {
			unset( $suffix );
			foreach ( array( 'docs', 'tokens', 'facets' ) as $logical ) {
				$vars[] = 'InnoDB';
				$rows[] = $this->column_rows( $logical );
				$rows[] = $this->swappable_index_rows( $logical );
			}
		}
		return array( 'vars' => $vars, 'rows' => $rows );
	}

	/**
	 * @testdox El cambio de idx_post a idx_post_token va en UNA sentencia y es idempotente.
	 *
	 * dbDelta agrega el índice nuevo pero nunca borra el viejo: sin este paso la
	 * tabla se queda con los dos. Y cada ALTER reconstruye 142k filas, así que
	 * DROP y ADD tienen que viajar juntos o la tabla se reconstruye dos veces.
	 */
	public function test_tokens_post_index_migration_is_single_statement_and_idempotent(): void {
		$GLOBALS['wpdb'] = new InstallProtocolWpdb();
		$tokens          = Install::tokens_table();

		// Tabla vieja: sólo idx_post → un solo ALTER con las dos operaciones.
		$GLOBALS['wpdb'] = new InstallProtocolWpdb( array( array( 'PRIMARY', 'idx_post', 'idx_field_token' ) ), 1 );
		self::assertTrue( (bool) $this->invoke_install_static( 'migrate_tokens_post_index', $tokens ) );
		$alters = array_values(
			array_filter( $GLOBALS['wpdb']->queries, static fn( string $q ): bool => str_starts_with( $q, 'ALTER' ) )
		);
		self::assertCount( 1, $alters, 'Dos sentencias reconstruyen la tabla dos veces.' );
		self::assertStringContainsString( 'DROP INDEX idx_post,', $alters[0] );
		self::assertStringContainsString( 'ADD KEY idx_post_token (post_id, token)', $alters[0] );

		// Tabla ya migrada: cero ALTER.
		$GLOBALS['wpdb'] = new InstallProtocolWpdb( array( array( 'PRIMARY', 'idx_post_token', 'idx_field_token' ) ), 1 );
		self::assertTrue( (bool) $this->invoke_install_static( 'migrate_tokens_post_index', $tokens ) );
		self::assertSame(
			array(),
			array_filter( $GLOBALS['wpdb']->queries, static fn( string $q ): bool => str_starts_with( $q, 'ALTER' ) )
		);

		// Migración a medias (ambos índices): se borra el viejo y nada más.
		$GLOBALS['wpdb'] = new InstallProtocolWpdb( array( array( 'idx_post', 'idx_post_token' ) ), 1 );
		self::assertTrue( (bool) $this->invoke_install_static( 'migrate_tokens_post_index', $tokens ) );
		$alters = array_values(
			array_filter( $GLOBALS['wpdb']->queries, static fn( string $q ): bool => str_starts_with( $q, 'ALTER' ) )
		);
		self::assertCount( 1, $alters );
		self::assertStringNotContainsString( 'ADD KEY', $alters[0] );

		// Un ALTER fallido NO puede sellar la migración.
		$GLOBALS['wpdb'] = new InstallProtocolWpdb( array( array( 'idx_post' ) ), false );
		self::assertFalse( (bool) $this->invoke_install_static( 'migrate_tokens_post_index', $tokens ) );
	}

	/** @return mixed */
	private function invoke_install_static( string $method, ...$args ) {
		$reflection = new \ReflectionMethod( Install::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$args );
	}
}
