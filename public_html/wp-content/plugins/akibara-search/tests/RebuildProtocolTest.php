<?php
/**
 * Pruebas adversariales focales de la maquina de rebuild v2.
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

/** Doble minimo para comprobar get_col + last_error. */
final class RebuildProtocolWpdb {

	public string $prefix = 'wp_';

	public string $posts = 'wp_posts';

	public string $last_error = '';

	/** @var array<int, array{0: array<int, mixed>, 1: string}> */
	private array $results;

	/**
	 * @param array<int, array{0: array<int, mixed>, 1: string}> $results Resultados y error por consulta.
	 */
	public function __construct( array $results ) {
		$this->results = $results;
	}

	/** @return string */
	public function prepare( string $query, ...$args ): string {
		return $query . implode( '|', array_map( 'strval', $args ) );
	}

	/** @return array<int, mixed> */
	public function get_col( string $query ): array {
		unset( $query );
		$result           = array_shift( $this->results ) ?? array( array(), '' );
		$this->last_error = $result[1];
		return $result[0];
	}
}

/** Doble que hace fallar la lectura de OPTION_STATE. */
final class RebuildStateFailureWpdb {

	public string $options = 'wp_options';

	public string $last_error = '';

	/** @return string */
	public function prepare( string $query, ...$args ): string {
		return $query . implode( '|', array_map( 'strval', $args ) );
	}

	/** @return null */
	public function get_var( string $query ) {
		unset( $query );
		$this->last_error = 'state select failed';
		return null;
	}
}

/** Doble que hace fallar el COUNT usado por el receipt de publicación. */
final class RebuildReceiptFailureWpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';

	/** @return null */
	public function get_var( string $query ) {
		unset( $query );
		$this->last_error = 'count failed';
		return null;
	}
}

/** Doble de la carrera absent-read / insert de OPTION_STATE. */
final class RebuildClaimStateWpdb {

	public string $options = 'wp_options';

	public string $last_error = '';

	/** @var array<int, string> */
	public array $queries = array();

	/** @var array<int, string|null> */
	private array $reads;

	private int $insert_result;

	/** @param array<int, string|null> $reads Lecturas raw sucesivas. */
	public function __construct( array $reads, int $insert_result ) {
		$this->reads         = $reads;
		$this->insert_result = $insert_result;
	}

	/** @param mixed ...$args Valores SQL. */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$replacement = "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace( '/%s/', $replacement, $query, 1 );
		}
		return $query;
	}

	/** @return string|null */
	public function get_var( string $query ): ?string {
		$this->queries[]  = $query;
		$this->last_error = '';
		return array_shift( $this->reads );
	}

	public function query( string $query ): int {
		$this->queries[]  = $query;
		$this->last_error = '';
		return $this->insert_result;
	}
}

/** Doble para probar el fast-path y un blip de information_schema. */
final class RebuildRecoveryProbeWpdb {

	public string $prefix = 'wp_';

	public string $options = 'wp_options';

	public string $last_error = '';

	/** @var array<int, string> */
	public array $queries = array();

	private int $metadata_failures_remaining;

	public function __construct( bool $metadata_failure ) {
		$this->metadata_failures_remaining = $metadata_failure ? 1 : 0;
	}

	/** @param mixed ...$args Valores SQL. */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
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
		if ( false !== stripos( $query, 'GET_LOCK(' ) ) {
			return '1';
		}
		if ( false !== stripos( $query, 'CONNECTION_ID()' ) || false !== stripos( $query, 'IS_USED_LOCK(' ) ) {
			return '41';
		}
		if ( false !== stripos( $query, 'RELEASE_LOCK(' ) ) {
			return '1';
		}

		return null; // OPTION_STATE ausente.
	}

	/** @return array<int, string> */
	public function get_col( string $query ): array {
		$this->queries[]  = $query;
		$this->last_error = 0 < $this->metadata_failures_remaining ? 'metadata blip' : '';
		$this->metadata_failures_remaining = max( 0, $this->metadata_failures_remaining - 1 );
		return array();
	}

	public function query( string $query ): int {
		$this->queries[]  = $query;
		$this->last_error = '';
		return 1;
	}
}

/** Doble READY_SWAP con estado durable y un único blip al leer el schema. */
final class RebuildSchemaBlipWpdb {

	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $last_error = '';

	/** @var array<int, string> */
	public array $queries = array();

	private string $state;
	private bool $schema_blipped = false;

	/** @param array<string, mixed> $state Estado durable. */
	public function __construct( array $state ) {
		$this->state = (string) json_encode( $state );
	}

	/** @param mixed ...$args Valores SQL. */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
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
		if ( false !== stripos( $query, 'GET_LOCK(' ) ) {
			return '1';
		}
		if ( false !== stripos( $query, 'CONNECTION_ID()' ) || false !== stripos( $query, 'IS_USED_LOCK(' ) ) {
			return '51';
		}
		if ( false !== stripos( $query, 'RELEASE_LOCK(' ) ) {
			return '1';
		}
		if ( false !== stripos( $query, 'option_value' ) && false !== stripos( $query, RebuildJob::OPTION_STATE ) ) {
			return $this->state;
		}
		if ( false !== stripos( $query, 'SELECT ENGINE FROM information_schema.TABLES' ) ) {
			if ( ! $this->schema_blipped ) {
				$this->schema_blipped = true;
				$this->last_error     = 'metadata blip';
				return null;
			}
			return 'InnoDB';
		}

		return null;
	}

	/** @return array<int, string> */
	public function get_col( string $query ): array {
		$this->queries[]  = $query;
		$this->last_error = '';
		return array(
			'wp_akb_search_docs',
			'wp_akb_search_tokens',
			'wp_akb_search_facet_index',
			'wp_akb_search_docs_new',
			'wp_akb_search_tokens_new',
			'wp_akb_search_facet_index_new',
		);
	}

	/** @return array<int, object> */
	public function get_results( string $query ): array {
		$this->queries[]  = $query;
		$this->last_error = '';
		return array();
	}

	public function query( string $query ): int {
		$this->queries[]  = $query;
		$this->last_error = '';
		return 1;
	}
}

/**
 * Doble para llevar un run REAL hasta el final de la publicación.
 *
 * Modela lo que ese camino consulta de verdad: el fence, las filas de
 * `wp_options`, la topología física y —desde los contratos del propio
 * `Install`— la metadata de columnas e índices. Así el caso ejercita el
 * protocolo entero (recibo editorial, receipt de publicación, DROP de `_old` y
 * cierre) sin redefinir una sola función del producto.
 */
final class RebuildPublishWpdb {

	public string $prefix     = 'wp_';
	public string $options    = 'wp_options';
	public string $termmeta   = 'wp_termmeta';
	public string $posts      = 'wp_posts';
	public string $last_error = '';

	/** @var array<string, string> */
	public array $raw_options = array();

	/** Las `_old` siguen puestas hasta que el cleanup las suelta. */
	public bool $old_tables = true;

	public int $docs_count = 3;

	/** @var array<int, string> */
	public array $queries = array();

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/** @param array<string, mixed> $where @return int */
	public function delete( string $table, array $where ): int {
		$name = (string) ( $where['option_name'] ?? '' );
		if ( ! array_key_exists( $name, $this->raw_options ) ) {
			return 0;
		}
		unset( $this->raw_options[ $name ] );
		return 1;
	}

	/** @return int|string|null */
	public function get_var( string $query ) {
		$this->last_error = '';
		if ( false !== stripos( $query, 'GET_LOCK(' ) || false !== stripos( $query, 'RELEASE_LOCK(' ) ) {
			return '1';
		}
		if ( false !== stripos( $query, 'CONNECTION_ID()' ) || false !== stripos( $query, 'IS_USED_LOCK(' ) ) {
			return '37';
		}
		if ( false !== stripos( $query, '@@in_transaction' ) ) {
			return '0';
		}
		if ( false !== stripos( $query, 'SELECT ENGINE FROM information_schema' ) ) {
			return 'InnoDB';
		}
		if ( false !== stripos( $query, 'COUNT(*)' ) ) {
			return false !== stripos( $query, Install::work_table() ) ? '0' : (string) $this->docs_count;
		}
		if ( 1 === preg_match( "/option_name = '((?:''|[^'])*)'/", $query, $match ) ) {
			return $this->raw_options[ str_replace( "''", "'", $match[1] ) ] ?? null;
		}
		return '0';
	}

	/** @return array<int, string> */
	public function get_col( string $query ): array {
		$this->last_error = '';
		if ( false === stripos( $query, 'information_schema.TABLES' ) ) {
			return array();
		}
		$tables = array( Install::docs_table(), Install::tokens_table(), Install::facets_table() );
		if ( $this->old_tables ) {
			foreach ( array( Install::docs_table(), Install::tokens_table(), Install::facets_table() ) as $table ) {
				$tables[] = $table . Install::OLD_SUFFIX;
			}
		}
		return $tables;
	}

	/** @return array<int, object> */
	public function get_results( string $query ): array {
		$this->last_error = '';
		if ( false !== stripos( $query, 'information_schema.COLUMNS' ) ) {
			return $this->column_rows( $this->queried_table( $query ) );
		}
		if ( false !== stripos( $query, 'information_schema.STATISTICS' ) ) {
			return $this->index_rows( $this->queried_table( $query ) );
		}
		if ( false !== stripos( $query, "SELECT 'meta' source_kind" ) ) {
			return array();
		}
		if ( false === stripos( $query, 'option_name, option_value' ) ) {
			return array();
		}

		$rows = array();
		$raw  = $this->raw_options;
		ksort( $raw, SORT_STRING );
		if ( 1 === preg_match( "/LIKE '((?:''|[^'])*)%'/", $query, $like ) ) {
			$prefix  = str_replace( array( "''", '\\_' ), array( "'", '_' ), $like[1] );
			$exclude = 1 === preg_match( "/option_name <> '((?:''|[^'])*)'/", $query, $skip )
				? str_replace( "''", "'", $skip[1] )
				: '';
			foreach ( $raw as $name => $value ) {
				if ( 0 === strpos( (string) $name, $prefix ) && $name !== $exclude ) {
					$rows[] = (object) array( 'option_name' => $name, 'option_value' => $value );
				}
			}
			return $rows;
		}
		foreach ( $raw as $name => $value ) {
			if ( false !== strpos( $query, "'" . str_replace( "'", "''", (string) $name ) . "'" ) ) {
				$rows[] = (object) array( 'option_name' => $name, 'option_value' => $value );
			}
		}
		return $rows;
	}

	/** @return int|false */
	public function query( string $query ) {
		$this->queries[]  = $query;
		$this->last_error = '';
		if ( 0 === stripos( ltrim( $query ), 'DROP TABLE' ) ) {
			$this->old_tables = false;
			return 1;
		}
		if ( 0 === stripos( ltrim( $query ), 'DELETE FROM `' . Install::work_table() ) ) {
			return 0;
		}
		if ( 1 === preg_match( "/INSERT IGNORE INTO .* VALUES \('((?:''|[^'])*)', '((?:''|[^'])*)', '[^']*'\)/s", $query, $match ) ) {
			$name = str_replace( "''", "'", $match[1] );
			if ( ! array_key_exists( $name, $this->raw_options ) ) {
				$this->raw_options[ $name ] = str_replace( "''", "'", $match[2] );
			}
			return 1;
		}
		if ( 1 === preg_match(
			"/UPDATE .*options SET option_value = '((?:''|[^'])*)'(?:, autoload = '[^']*')? WHERE option_name = '((?:''|[^'])*)' AND BINARY option_value = BINARY '((?:''|[^'])*)'/s",
			$query,
			$match
		) ) {
			$name     = str_replace( "''", "'", $match[2] );
			$expected = str_replace( "''", "'", $match[3] );
			if ( ( $this->raw_options[ $name ] ?? null ) !== $expected ) {
				return 0;
			}
			$this->raw_options[ $name ] = str_replace( "''", "'", $match[1] );
			return 1;
		}
		return 0;
	}

	private function queried_table( string $query ): string {
		return 1 === preg_match( "/TABLE_NAME = '([^']*)'/", $query, $match ) ? $match[1] : '';
	}

	/**
	 * Columnas EXACTAS del contrato: el doble no inventa un esquema, lo copia de
	 * la única fuente que el producto considera válida.
	 *
	 * @return array<int, object>
	 */
	private function column_rows( string $table ): array {
		$contract = $this->contracts()[ $table ] ?? null;
		if ( null === $contract ) {
			return array();
		}
		$rows = array();
		foreach ( (array) $contract['columns'] as $column ) {
			$rows[] = (object) array(
				'column_name'    => $column['name'],
				'column_type'    => $column['type'],
				'is_nullable'    => $column['nullable'] ? 'YES' : 'NO',
				'column_default' => $column['default'],
				'extra'          => $column['extra'],
				'collation_name' => $column['collation'],
			);
		}
		return $rows;
	}

	/** @return array<int, object> */
	private function index_rows( string $table ): array {
		$contract = $this->contracts()[ $table ] ?? null;
		if ( null === $contract ) {
			return array();
		}
		$indexes = (array) ( $contract['indexes'] ?? array() );
		ksort( $indexes, SORT_STRING );
		$rows = array();
		foreach ( $indexes as $name => $index ) {
			$seq = 0;
			foreach ( (array) $index['columns'] as $column ) {
				++$seq;
				$rows[] = (object) array(
					'index_name'   => $name,
					'non_unique'   => $index['unique'] ? 0 : 1,
					'seq_in_index' => $seq,
					'column_name'  => $column[0],
					'sub_part'     => $column[1],
					'index_type'   => 'BTREE',
				);
			}
		}
		return $rows;
	}

	/** @return array<string, array<string, mixed>> */
	private function contracts(): array {
		$contracts = array();
		$tables    = $this->install_value( 'swappable_table_map' );
		foreach ( (array) $this->install_value( 'swappable_table_contracts' ) as $logical => $contract ) {
			foreach ( array( '', Install::SHADOW_SUFFIX, Install::OLD_SUFFIX ) as $suffix ) {
				$contracts[ $tables[ $logical ] . $suffix ] = $contract;
			}
		}
		$contracts[ Install::work_table() ] = $this->install_value( 'work_table_contract' );

		return $contracts;
	}

	/** @return mixed */
	private function install_value( string $method ) {
		$reflection = new ReflectionMethod( Install::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( null );
	}
}

/**
 * @covers \Akibara\Search\RebuildJob
 */
final class RebuildProtocolTest extends TestCase {
	use \Akibara\Search\Tests\Support\MarcosEditorialesVigilados;


	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ): string => (string) json_encode( $value ) );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		// Primero, con los stubs montados: un marco abierto no puede cerrarse al apagar PHP.
		$this->soltar_publicaciones_vigiladas();
		$this->restore_publication_statics();
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Hallazgo A3, reproducido en este arnés antes de tocar nada: tras el swap,
	 * un paso de publicación falla una vez; antes del reintento se guarda una
	 * option editorial y la revisión pasa de 1 a 2. Desde ahí
	 * `record_swap_receipt()` devolvía false PARA SIEMPRE —la captura de precio
	 * dice 2 y el run pide 1—, `publish_run()` volvía sin cambiar nada y cada
	 * reintento repetía lo mismo: 503 indefinido con las tablas `_old` puestas,
	 * «Reintentar» sin efecto, `wp akb-search rebuild` contestando «ya hay un
	 * rebuild en curso» y `ops/rescate-buscador.sh` parándose en `old=3`.
	 *
	 * @testdox Un run cuya revisión editorial se movió tras el swap cierra igual y suelta las _old.
	 */
	public function test_a_run_whose_editorial_revision_moved_after_the_swap_still_finishes(): void {
		$job = $this->install_publishing_run( 2 );

		$this->resume_publication( $job );
		$this->resume_publication( $job );

		self::assertArrayNotHasKey(
			RebuildJob::OPTION_STATE,
			$this->publish_db->raw_options,
			'El run llega a DONE: sin estado, «Reconstruir» y el rescate vuelven a tener salida.'
		);
		self::assertFalse( $this->publish_db->old_tables, 'Las tablas _old se sueltan: la topología vuelve a CLEAN_PRE.' );
		self::assertNotSame(
			array(),
			$this->run_receipt_keys(),
			'Queda el recibo inmutable del run, que es lo que acredita su publicación.'
		);
		self::assertSame(
			'run-anterior',
			$this->current_editorial_receipt()['run_id'],
			'El recibo VIGENTE no se mueve: la revisión 2 sigue sin publicar y el gate sólo lo abre su prueba.'
		);
		self::assertContains( 'recibo_editorial_superado', $this->publish_audit_actions() );
	}

	/**
	 * Hallazgo M-2 de la revisión de 0.1.63: la salida de reparación (y el SQL
	 * directo, o un alta/baja sin cercar) cambia la foto viva SIN subir la
	 * revisión. El estado sigue diciendo 1, igual que el run, así que el relevo
	 * «revisión mayor» no se reconocía y el atasco de A3 volvía hasta que un
	 * reconcile montara la deriva.
	 *
	 * @testdox Un run cuya configuración cambió sin subir la revisión cierra igual y no mueve el recibo vigente.
	 */
	public function test_a_run_whose_config_changed_without_staging_still_finishes(): void {
		$job = $this->install_publishing_run( 1 );
		$this->publish_db->raw_options['akibara_taxonomy_discount_apply_to'] = 'all';

		$this->resume_publication( $job );
		$this->resume_publication( $job );

		self::assertArrayNotHasKey( RebuildJob::OPTION_STATE, $this->publish_db->raw_options, 'El run llega a DONE.' );
		self::assertFalse( $this->publish_db->old_tables );
		self::assertSame(
			'run-anterior',
			$this->current_editorial_receipt()['run_id'],
			'El recibo vigente no se mueve: la configuración nueva la publica el reproof o un rebuild.'
		);
		self::assertContains( 'recibo_editorial_superado', $this->publish_audit_actions() );
	}

	/**
	 * @testdox Una configuración ilegible no cuenta como relevo: el run espera.
	 *
	 * Ilegible no prueba que la configuración publicada haya quedado atrás —puede
	 * ser un hipo de lectura—, así que el run no se da por cerrado.
	 */
	public function test_an_unreadable_config_is_not_a_relay(): void {
		$job = $this->install_publishing_run( 1 );
		$this->publish_db->raw_options['akibara_taxonomy_discount_apply_to'] = 'stock';

		$this->resume_publication( $job );

		self::assertArrayHasKey( RebuildJob::OPTION_STATE, $this->publish_db->raw_options );
		self::assertTrue( $this->publish_db->old_tables, 'Las _old siguen siendo el rollback.' );
		self::assertNotContains( 'recibo_editorial_superado', $this->publish_audit_actions() );
	}

	/**
	 * El control del caso anterior: con la revisión intacta, la publicación sella
	 * el recibo vigente como siempre. Sin esto, «cerrar igual» podría ser cerrar
	 * MAL para todos.
	 *
	 * @testdox Con la revisión editorial intacta, la publicación sí mueve el recibo vigente.
	 */
	public function test_a_publication_on_the_current_revision_seals_the_receipt(): void {
		$job = $this->install_publishing_run( 1 );

		$this->resume_publication( $job );

		self::assertArrayNotHasKey( RebuildJob::OPTION_STATE, $this->publish_db->raw_options );
		$receipt = $this->current_editorial_receipt();
		self::assertSame( 'run-publish-1', $receipt['run_id'] );
		self::assertSame( 1, $receipt['wanted_seq'] );
		self::assertNotContains( 'recibo_editorial_superado', $this->publish_audit_actions() );
	}

	/** Doble de $wpdb del run de publicación. */
	private ?RebuildPublishWpdb $publish_db = null;

	/** @var array<string, mixed> Estáticos a devolver a su sitio en tearDown. */
	private array $publication_statics = array();

	/**
	 * Monta un run REAL detenido justo en el recibo editorial de PUBLISHING.
	 *
	 * @param int $revision_vigente Revisión editorial del estado; la del run es 1.
	 * @return RebuildJob
	 */
	private function install_publishing_run( int $revision_vigente ): RebuildJob {
		$db               = new RebuildPublishWpdb();
		$this->publish_db = $db;
		$GLOBALS['wpdb']  = $db;

		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( $db ) {
				if ( ! array_key_exists( $name, $db->raw_options ) ) {
					return $default;
				}
				$raw     = $db->raw_options[ $name ];
				$decoded = @unserialize( $raw, array( 'allowed_classes' => false ) );
				return false === $decoded && 'b:0;' !== $raw ? $raw : $decoded;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( $db ): bool {
				$db->raw_options[ $name ] = is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) use ( $db ): bool {
				unset( $db->raw_options[ $name ] );
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value = null ) => $value );
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'wp_prime_option_caches' )->justReturn( null );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );

		$this->remember_publication_statics();
		SemanticPublication::reset_authoritative_state_for_tests();
		FacetRegistry::reset_cache();

		$run_id      = 'run-publish-1';
		$semantic    = SemanticPublication::fingerprints_from_raw_bundle( array( Install::OPTION_INDEX_GEN => 'previa' ) );
		self::assertIsArray( $semantic );
		$semantic_fp = (string) $semantic['semantic'];
		$generation  = (string) $this->invoke_static( 'generation_target_for_fingerprint', $run_id, $semantic_fp );
		$now         = 1700000000;
		$coverage    = array( 'eligible' => 3, 'docs' => 3, 'missing' => 0, 'extra' => 0, 'orphans' => 0 );

		$db->raw_options = array(
			Install::OPTION_INDEX_GEN      => $generation,
			Install::OPTION_KEY            => Install::DB_VERSION,
			Install::OPTION_SCHEMA_SEAL    => Install::schema_seal(),
			RestCache::OPTION_PURGE_RECEIPT => serialize(
				array(
					'protocol'          => 1,
					'generation_target' => $generation,
					'tag'               => RestCache::TAG,
					'ack_scope'         => 'local_dispatch_only',
					'dispatched_at'     => $now,
				)
			),
			SemanticPublication::OPTION_FINGERPRINT => serialize(
				array(
					'protocol'     => 1,
					'generation'   => $generation,
					'fingerprint'  => $semantic_fp,
					'published_at' => $now,
				)
			),
		);

		// Foto editorial viva: la revisión del ESTADO es la que decide si la del
		// run sigue vigente o ya quedó atrás.
		$source_fp    = $this->editorial_source_fingerprint( $generation );
		$revision_run = 1;
		$fp_run       = 1 === $revision_vigente ? $source_fp : str_repeat( 'a', 64 );
		$db->raw_options[ EditorialDiscountPublication::OPTION_STATE ] = (string) json_encode(
			array(
				'protocol'           => 1,
				'contract'           => 'akb-editorial-materialization-v1',
				'wanted_seq'         => $revision_vigente,
				'wanted_fingerprint' => $source_fp,
				'window_active'      => false,
				'next_boundary_at'   => null,
				'source_term_ids'    => array(),
				'debt_staged'        => true,
				'active_run_id'      => '',
				'purge_pre_seq'      => $revision_vigente,
				'purge_post_seq'     => $revision_vigente,
				'reason'             => 'test-publishing',
				'updated_at'         => $now,
			)
		);
		// Recibo vigente: el de la publicación ANTERIOR a este run.
		$db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] = serialize(
			array(
				'protocol'                => 1,
				'contract'                => 'akb-editorial-materialization-v1',
				'run_id'                  => 'run-anterior',
				'wanted_seq'              => 1,
				'fingerprint'             => str_repeat( 'b', 64 ),
				'swapped_at'              => $now,
				'published_at'            => $now,
				'db_version'              => Install::DB_VERSION,
				'schema_seal'             => Install::schema_seal(),
				'coverage'                => $coverage,
				'generation_target'       => 'generacion-anterior',
				'semantic_fingerprint'    => $semantic_fp,
				'publication_acked'       => true,
				'purge_all_dispatched_at' => $now,
			)
		);

		$db->raw_options[ RebuildJob::OPTION_STATE ] = (string) json_encode(
			array(
				'protocol'                 => 2,
				'run_id'                   => $run_id,
				'phase'                    => RebuildJob::PHASE_PUBLISHING,
				'topology'                 => Install::TOPOLOGY_SWAPPED,
				'started_at'               => $now,
				'updated_at'               => $now,
				'heartbeat_at'             => $now,
				'expected'                 => 3,
				'schema_seal'              => Install::schema_seal(),
				'shadow_schema_verified'   => true,
				'generation_target'        => $generation,
				'semantic_fingerprint'     => $semantic_fp,
				'ack_price_buckets'        => true,
				'ack_generation'           => true,
				'ack_rest_purge'           => true,
				'ack_semantic_fingerprint' => true,
				'ack_editorial_receipt'    => false,
				'ack_history'              => false,
				'coverage'                 => $coverage,
				'editorial_wanted_seq'     => $revision_run,
				'editorial_fingerprint'    => $fp_run,
				'error'                    => '',
			)
		);

		$work    = new WorkRepository( $db, Install::work_table() );
		$fence   = new WriteFence( $db, 'akb-publish-a3' );
		$indexer = new Indexer( new DocumentBuilder(), $work, $fence, new ProductSourceAuthority( $db, static fn( array $ids ): array => $ids ) );
		$job     = new RebuildJob( $indexer, $work, $fence );
		$this->vigilar_publicacion( new EditorialDiscountPublication( $work, $indexer, $fence ) );
		$this->set_static_value( EditorialDiscountPublication::class, 'registered', true );

		return $job;
	}

	/** Reanuda el run como lo hacen el watchdog y el recovery. */
	private function resume_publication( RebuildJob $job ): void {
		$method = new ReflectionMethod( RebuildJob::class, 'resume_run' );
		$method->setAccessible( true );
		$method->invoke( $job, 'run-publish-1', false );
		SemanticPublication::reset_authoritative_state_for_tests();
		$this->set_static_value( EditorialDiscountPublication::class, 'source_memo_set', false );
		$this->set_static_value( EditorialDiscountPublication::class, 'source_memo', null );
		$this->set_static_value( EditorialDiscountPublication::class, 'serving_capture_done', false );
	}

	/** @return array<string, mixed> */
	private function current_editorial_receipt(): array {
		return (array) unserialize(
			(string) ( $this->publish_db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] ?? '' ),
			array( 'allowed_classes' => false )
		);
	}

	/** @return array<int, string> */
	private function run_receipt_keys(): array {
		return array_values(
			array_filter(
				array_keys( $this->publish_db->raw_options ),
				static fn( string $key ): bool => 0 === strpos( $key, 'akb_search_editorial_run_receipt_' )
			)
		);
	}

	/** @return array<int, string> */
	private function publish_audit_actions(): array {
		$raw = $this->publish_db->raw_options[ AuditLog::OPTION_KEY ] ?? '';

		return array_column( '' === $raw ? array() : (array) unserialize( $raw, array( 'allowed_classes' => false ) ), 'action' );
	}

	/** Fingerprint de la configuración editorial viva, con la foto del arnés. */
	private function editorial_source_fingerprint( string $generation ): string {
		$options_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_options_from_bundle' );
		$options_method->setAccessible( true );
		$source_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_snapshot_from_options' );
		$source_method->setAccessible( true );
		$source = $source_method->invoke(
			null,
			$options_method->invoke( null, array( Install::OPTION_INDEX_GEN => $generation ) ),
			array( 'meta' => array(), 'attributes' => array() )
		);
		self::assertIsArray( $source, 'La foto editorial del arnés debe ser legible.' );

		return (string) $source['fingerprint'];
	}

	/**
	 * Guarda los estáticos que este caso ensucia y los deja en su valor de
	 * PRODUCCIÓN antes de empezar; tearDown devuelve los originales.
	 *
	 * Sin el reseteo, un caso anterior que dejó `test_serving_override` puesto
	 * hacía que `capture_price_authority()` devolviera la captura de prueba y el
	 * run no llegaba a publicar: un rojo del arnés, no del producto, y sólo en
	 * orden aleatorio.
	 */
	private function remember_publication_statics(): void {
		foreach ( self::publication_static_defaults() as $clave => $default ) {
			list( $clase, $nombre ) = explode( '::', $clave, 2 );
			$property               = new ReflectionProperty( $clase, $nombre );
			$property->setAccessible( true );
			$this->publication_statics[ $clave ] = $property->getValue();
			$property->setValue( null, $default );
		}
	}

	/** @return array<string, mixed> Estático => valor con el que corre producción. */
	private static function publication_static_defaults(): array {
		return array(
			EditorialDiscountPublication::class . '::runtime'                   => null,
			EditorialDiscountPublication::class . '::registered'                => false,
			EditorialDiscountPublication::class . '::state_read_failed'         => false,
			EditorialDiscountPublication::class . '::test_serving_override'     => null,
			EditorialDiscountPublication::class . '::test_price_match_sequence' => null,
			EditorialDiscountPublication::class . '::term_meta_row_failed'      => false,
			EditorialDiscountPublication::class . '::source_memo_set'           => false,
			EditorialDiscountPublication::class . '::source_memo'               => null,
			EditorialDiscountPublication::class . '::read_authority_available'  => true,
			EditorialDiscountPublication::class . '::read_authority_error'      => '',
			EditorialDiscountPublication::class . '::serving_capture_done'      => false,
			EditorialDiscountPublication::class . '::serving_capture_token'     => null,
			EditorialDiscountPublication::class . '::serving_capture_window'    => null,
			EditorialDiscountPublication::class . '::serving_final_checked'     => array(),
			EditorialDiscountPublication::class . '::serving_final_matches_by'  => array(),
			EditorialDiscountPublication::class . '::serving_final_matches'     => false,
			EditorialDiscountPublication::class . '::adopted_price_provider'    => null,
			EditorialDiscountPublication::class . '::adopted_price_token'       => null,
			EditorialDiscountPublication::class . '::adopted_price_snapshot'    => null,
			EditorialDiscountPublication::class . '::adopted_provider_absent'   => false,
			RebuildJob::class . '::runtime'                                     => null,
			RebuildJob::class . '::state_read_failed'                           => false,
			RebuildJob::class . '::state_row_present'                           => false,
			Install::class . '::topology_read_failed'                           => false,
			Install::class . '::schema_read_failed'                             => false,
			// `schema_options_primed` NO se toca: es un memo de rendimiento, no un
			// pestillo, y resetearlo obliga a que TODA prueba posterior stubee
			// `wp_prime_option_caches`.
			Install::class . '::option_read_failed'                             => false,
		);
	}

	private function restore_publication_statics(): void {
		foreach ( $this->publication_statics as $clave => $valor ) {
			list( $clase, $nombre ) = explode( '::', $clave, 2 );
			$this->set_static_value( $clase, $nombre, $valor );
		}
		$this->publication_statics = array();
		SemanticPublication::reset_authoritative_state_for_tests();
		FacetRegistry::reset_cache();
	}

	/** @param mixed $value */
	private function set_static_value( string $clase, string $nombre, $value ): void {
		$property = new ReflectionProperty( $clase, $nombre );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	/**
*
	 * @testdox Recovery distingue crash pre-RENAME, post-RENAME y topologia ambigua sin adivinar.
	 */
	public function test_recovery_decision_is_phase_and_topology_fenced(): void {
		self::assertSame( 'revalidate', $this->invoke_static( 'recovery_decision', RebuildJob::PHASE_SWAP_INTENT, Install::TOPOLOGY_READY_SWAP ) );
		self::assertSame( 'mark_swapped', $this->invoke_static( 'recovery_decision', RebuildJob::PHASE_SWAP_INTENT, Install::TOPOLOGY_SWAPPED ) );
		self::assertSame( 'resume', $this->invoke_static( 'recovery_decision', RebuildJob::PHASE_PUBLISHING, Install::TOPOLOGY_SWAPPED ) );
		self::assertSame( 'finish_done', $this->invoke_static( 'recovery_decision', RebuildJob::PHASE_CLEANUP, Install::TOPOLOGY_CLEAN_PRE ) );
		self::assertSame( 'block', $this->invoke_static( 'recovery_decision', RebuildJob::PHASE_BUILDING, Install::TOPOLOGY_SWAPPED ) );
		self::assertSame( 'block', $this->invoke_static( 'recovery_decision', RebuildJob::PHASE_CATCHING_UP, Install::TOPOLOGY_AMBIGUOUS ) );
		self::assertSame( 'idle', $this->invoke_static( 'recovery_decision', RebuildJob::PHASE_BLOCKED, Install::TOPOLOGY_AMBIGUOUS ) );
		self::assertSame( 'idle', $this->invoke_static( 'recovery_decision', RebuildJob::PHASE_ABORTED, Install::TOPOLOGY_AMBIGUOUS ) );
	}

	/**
	 * @testdox Recovery de partial _new consume la foto validada y un blip interno queda retryable, no BLOCKED.
	 */
	public function test_partial_shadow_recovery_reinspection_preserves_metadata_failure_as_retry(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );
		$start  = strpos( $source, 'Install::recover_owned_partial_shadow_result(' );
		$end    = strpos( $source, "if ( self::PHASE_CLEANUP === \$phase", (int) $start );
		$branch = substr( $source, (int) $start, (int) $end - (int) $start );

		$retry  = strpos( $branch, 'Install::PARTIAL_RECOVERY_RETRY === $partial_recovery' );
		$return = strpos( $branch, 'return;', (int) $retry );
		$block  = strpos( $branch, 'partial_shadow_recovery_failed', (int) $return );

		self::assertIsInt( $retry );
		self::assertIsInt( $return );
		self::assertIsInt( $block );
		self::assertTrue( $retry < $return && $return < $block );
		self::assertStringContainsString( 'recover_owned_partial_shadow_result(', $branch );
		self::assertStringContainsString( "array( \$this->fence, 'assert_owned' )", $branch );
	}

	/**
	 * @testdox Init con OPTION_STATE ausente hace una lectura O(1), sin GET_LOCK ni information_schema.
	 */
	public function test_absent_state_recovery_hot_path_skips_lock_and_metadata(): void {
		$wpdb            = new RebuildRecoveryProbeWpdb( false );
		$GLOBALS['wpdb'] = $wpdb;
		$job             = ( new \ReflectionClass( RebuildJob::class ) )->newInstanceWithoutConstructor();

		$job->handle_recovery();

		$queries = implode( "\n", $wpdb->queries );
		self::assertStringContainsString( 'wp_options', $queries );
		self::assertStringNotContainsString( 'GET_LOCK(', $queries );
		self::assertStringNotContainsString( 'information_schema', $queries );
		self::assertCount( 1, $wpdb->queries );
	}

	/**
	 * @testdox Una fila OPTION_STATE vacía es corrupción BLOCKED observable, no ausencia reclamable.
	 */
	public function test_empty_state_row_is_present_corruption_not_absence(): void {
		$wpdb            = new RebuildClaimStateWpdb( array( '' ), 0 );
		$GLOBALS['wpdb'] = $wpdb;

		$status = RebuildJob::status();

		self::assertIsArray( $status );
		self::assertSame( RebuildJob::PHASE_BLOCKED, $status['phase'] );
		self::assertSame( 'state_decode_failed', $status['error'] );

		$claim_probe     = new RebuildClaimStateWpdb( array( '' ), 1 );
		$GLOBALS['wpdb'] = $claim_probe;
		self::assertFalse(
			(bool) $this->invoke_static(
				'claim_state',
				array( 'protocol' => 2, 'run_id' => 'must-not-overwrite-empty-row', 'phase' => RebuildJob::PHASE_PREPARING )
			)
		);
		self::assertStringNotContainsString( 'INSERT IGNORE INTO wp_options', implode( "\n", $claim_probe->queries ) );
	}

	/**
	 * @testdox Auditoría orphan no persiste BLOCKED cuando falla la única foto de metadata.
	 */
	public function test_orphan_audit_metadata_failure_does_not_create_blocked_state(): void {
		$wpdb            = new RebuildRecoveryProbeWpdb( true );
		$GLOBALS['wpdb'] = $wpdb;
		$fence           = new WriteFence( $wpdb, 'akb-search-recovery-probe' );
		$reflection      = new \ReflectionClass( RebuildJob::class );
		$job             = $reflection->newInstanceWithoutConstructor();
		$property        = $reflection->getProperty( 'fence' );
		$property->setAccessible( true );
		$property->setValue( $job, $fence );

		$job->handle_recovery( true );

		$queries = implode( "\n", $wpdb->queries );
		self::assertStringContainsString( 'GET_LOCK(', $queries );
		self::assertStringContainsString( 'information_schema.TABLES', $queries );
		self::assertStringNotContainsString( 'INSERT IGNORE INTO wp_options', $queries );
		self::assertStringNotContainsString( 'UPDATE wp_options', $queries );
	}

	/**
	 * @testdox PREPARING con un único blip de topología reintenta sin acuñar BLOCKED.
	 */
	public function test_prepare_single_topology_blip_is_retryable_without_block(): void {
		$wpdb            = new RebuildRecoveryProbeWpdb( true );
		$GLOBALS['wpdb'] = $wpdb;
		$fence           = new WriteFence( $wpdb, 'akb-search-prepare-blip' );
		self::assertTrue( $fence->acquire( 0 ) );

		$reflection = new \ReflectionClass( RebuildJob::class );
		$job        = $reflection->newInstanceWithoutConstructor();
		$property   = $reflection->getProperty( 'fence' );
		$property->setAccessible( true );
		$property->setValue( $job, $fence );
		$method = $reflection->getMethod( 'prepare_locked' );
		$method->setAccessible( true );

		self::assertFalse(
			(bool) $method->invoke(
				$job,
				array( 'protocol' => 2, 'run_id' => 'prepare-blip', 'phase' => RebuildJob::PHASE_PREPARING )
			)
		);
		$fence->release();

		$queries = implode( "\n", $wpdb->queries );
		self::assertSame( 1, substr_count( $queries, 'information_schema.TABLES' ) );
		self::assertStringNotContainsString( 'INSERT IGNORE INTO wp_options', $queries );
		self::assertStringNotContainsString( 'UPDATE wp_options', $queries );
	}

	/**
	 * @testdox READY_SWAP con sello viejo y un único blip de schema conserva el estado para retry.
	 */
	public function test_recovery_single_schema_blip_does_not_create_recovery_schema_invalid(): void {
		$state = array(
			'protocol'               => 2,
			'run_id'                 => 'schema-blip',
			'phase'                  => RebuildJob::PHASE_BUILDING,
			'schema_seal'            => 'old-seal',
			'shadow_schema_verified' => false,
			'started_at'             => 1,
		);
		$wpdb            = new RebuildSchemaBlipWpdb( $state );
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				if ( Install::OPTION_KEY === $name ) {
					return Install::DB_VERSION;
				}
				if ( Install::OPTION_SCHEMA_SEAL === $name ) {
					return Install::schema_seal();
				}
				return $default;
			}
		);

		$fence      = new WriteFence( $wpdb, 'akb-search-schema-blip' );
		$reflection = new \ReflectionClass( RebuildJob::class );
		$job        = $reflection->newInstanceWithoutConstructor();
		$property   = $reflection->getProperty( 'fence' );
		$property->setAccessible( true );
		$property->setValue( $job, $fence );

		$job->handle_recovery();

		$queries = implode( "\n", $wpdb->queries );
		self::assertSame( 1, substr_count( $queries, 'SELECT ENGINE FROM information_schema.TABLES' ) );
		self::assertStringNotContainsString( 'INSERT IGNORE INTO wp_options', $queries );
		self::assertStringNotContainsString( 'UPDATE wp_options', $queries );
	}

	/**
	 * @testdox Un crash post-RENAME con estado SWAP_INTENT no dirige el writer live hacia la sombra ya renombrada.
	 */
	public function test_swap_intent_writer_target_depends_on_physical_topology(): void {
		self::assertSame(
			'run-before-rename',
			$this->invoke_static( 'swap_intent_writer_target', Install::TOPOLOGY_READY_SWAP, 'run-before-rename' )
		);
		self::assertSame( '', $this->invoke_static( 'swap_intent_writer_target', Install::TOPOLOGY_SWAPPED, 'run-after-rename' ) );
		self::assertNull( $this->invoke_static( 'swap_intent_writer_target', Install::TOPOLOGY_AMBIGUOUS, 'run-ambiguous' ) );
		self::assertNull( $this->invoke_static( 'swap_intent_writer_target', Install::TOPOLOGY_READY_SWAP, '' ) );

		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );
		$start  = strpos( $source, 'public static function active_pre_swap_run_id' );
		$end    = strpos( $source, 'public static function validate_shadow_claim_phase_for_update', (int) $start );
		$method = substr( $source, (int) $start, (int) $end - (int) $start );

		self::assertStringContainsString( 'self::PHASE_SWAP_INTENT === $phase', $method );
		self::assertStringContainsString( 'self::swap_intent_writer_target(', $method );
		self::assertStringContainsString( 'Install::classify_topology()', $method );
	}

	/**
	 * @testdox DONE conserva su estado si baseline/repair tienen deuda o si COUNT falla, incluso tras purge de ACK.
	 */
	public function test_done_never_removes_state_with_unresolved_run_debt(): void {
		self::assertSame( 'error', $this->invoke_static( 'final_work_debt_decision', null, 0 ) );
		self::assertSame( 'error', $this->invoke_static( 'final_work_debt_decision', 0, null ) );
		self::assertSame( 'wait', $this->invoke_static( 'final_work_debt_decision', 1, 0 ) );
		self::assertSame( 'wait', $this->invoke_static( 'final_work_debt_decision', 0, 1 ) );
		self::assertSame( 'clear', $this->invoke_static( 'final_work_debt_decision', 0, 0 ) );

		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );
		$start  = strpos( $source, 'private function finish_done_locked' );
		$end    = strpos( $source, 'private function block_locked', (int) $start );
		$method = substr( $source, (int) $start, (int) $end - (int) $start );

		$pre_count  = strpos( $method, '$debt   = $this->final_work_debt_status' );
		$done       = strpos( $method, "\$state['phase'] = self::PHASE_DONE" );
		$purge      = strpos( $method, '$this->work->purge_run' );
		$post_count = strpos( $method, '$debt = $this->final_work_debt_status', false === $purge ? 0 : $purge + 1 );
		$delete     = strpos( $method, 'delete_option( self::OPTION_STATE )' );

		self::assertIsInt( $pre_count );
		self::assertIsInt( $done );
		self::assertIsInt( $purge );
		self::assertIsInt( $post_count );
		self::assertIsInt( $delete );
		self::assertTrue( $pre_count < $done && $done < $purge && $purge < $post_count && $post_count < $delete );
		self::assertStringContainsString( "'done_work_count_failed'", $method );
		self::assertStringContainsString( "'done_work_debt_unresolved'", $method );
	}

	/**
	 * @testdox PUBLISHING y CLEANUP conservan _old mientras un COUNT falle o quede deuda baseline/repair.
	 */
	public function test_cleanup_keeps_old_tables_until_all_run_debt_is_clear(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );
		$start  = strpos( $source, 'private function publish_run' );
		$end    = strpos( $source, 'private function resume_run', (int) $start );
		$method = substr( $source, (int) $start, (int) $end - (int) $start );

		$published_gate = strpos( $method, 'self::publication_acked( $state )' );
		$pre_debt       = strpos( $method, '$debt = $this->final_work_debt_status( $run_id )', (int) $published_gate );
		$cleanup_phase  = strpos( $method, "\$state['phase'] = self::PHASE_CLEANUP", (int) $pre_debt );
		$post_debt      = strpos( $method, '$debt = $this->final_work_debt_status( $run_id )', false === $cleanup_phase ? 0 : $cleanup_phase + 1 );
		$drop_old       = strpos( $method, 'Install::cleanup_old(', (int) $post_debt );

		self::assertIsInt( $published_gate );
		self::assertIsInt( $pre_debt );
		self::assertIsInt( $cleanup_phase );
		self::assertIsInt( $post_debt );
		self::assertIsInt( $drop_old );
		self::assertTrue(
			$published_gate < $pre_debt
			&& $pre_debt < $cleanup_phase
			&& $cleanup_phase < $post_debt
			&& $post_debt < $drop_old
		);
		self::assertStringContainsString( "'cleanup_work_count_failed'", $method );
		self::assertStringContainsString( "'cleanup_work_debt_unresolved'", $method );
	}

	/**
	 * @testdox DDL parcial observado conserva owner/fase y elige recovery inmediato, no BLOCKED.
	 */
	public function test_observed_partial_ddl_is_recoverable_in_owner_phase(): void {
		$base = array(
			'live' => array( 'docs' => true, 'tokens' => true, 'facets' => true ),
			'new'  => array( 'docs' => false, 'tokens' => false, 'facets' => false ),
			'old'  => array( 'docs' => false, 'tokens' => false, 'facets' => false ),
		);
		$partial_new         = $base;
		$partial_new['new']  = array( 'docs' => true, 'tokens' => false, 'facets' => true );
		$partial_old         = $base;
		$partial_old['old']  = array( 'docs' => false, 'tokens' => true, 'facets' => true );
		$swapped             = $base;
		$swapped['old']      = array( 'docs' => true, 'tokens' => true, 'facets' => true );

		self::assertSame(
			'recover_shadow',
			$this->invoke_static( 'ddl_failure_decision', RebuildJob::PHASE_PREPARING, Install::TOPOLOGY_AMBIGUOUS, $partial_new )
		);
		self::assertSame(
			'recover_old',
			$this->invoke_static( 'ddl_failure_decision', RebuildJob::PHASE_CLEANUP, Install::TOPOLOGY_AMBIGUOUS, $partial_old )
		);
		self::assertSame(
			'retry',
			$this->invoke_static( 'ddl_failure_decision', RebuildJob::PHASE_PREPARING, Install::TOPOLOGY_CLEAN_PRE, $base )
		);
		self::assertSame(
			'retry',
			$this->invoke_static( 'ddl_failure_decision', RebuildJob::PHASE_CLEANUP, Install::TOPOLOGY_SWAPPED, $swapped )
		);
		self::assertSame(
			'finish',
			$this->invoke_static( 'ddl_failure_decision', RebuildJob::PHASE_CLEANUP, Install::TOPOLOGY_CLEAN_PRE, $base )
		);

		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );
		self::assertStringContainsString( 'recover_observed_shadow_create_failure(', $source );
		self::assertStringContainsString( "'partial_old_cleanup_retry'", $source );
	}

	/**
	 * @testdox El claim inicial no clobbera un run que aparece tras la lectura ausente.
	 */
	public function test_initial_state_claim_is_insert_only_under_race(): void {
		$ours = array(
			'protocol' => 2,
			'run_id'   => 'run-ours',
			'phase'    => RebuildJob::PHASE_PREPARING,
		);
		$foreign = (string) json_encode(
			array(
				'protocol' => 2,
				'run_id'   => 'run-foreign',
				'phase'    => RebuildJob::PHASE_PREPARING,
			)
		);

		$loser = new RebuildClaimStateWpdb( array( null, $foreign ), 0 );
		$GLOBALS['wpdb'] = $loser;
		self::assertFalse( (bool) $this->invoke_static( 'claim_state', $ours ) );
		self::assertStringContainsString( 'INSERT IGNORE INTO wp_options', implode( "\n", $loser->queries ) );
		self::assertStringNotContainsString( 'UPDATE wp_options', implode( "\n", $loser->queries ) );

		$encoded = (string) json_encode( $ours );
		$winner  = new RebuildClaimStateWpdb( array( null, $encoded ), 1 );
		$GLOBALS['wpdb'] = $winner;
		self::assertTrue( (bool) $this->invoke_static( 'claim_state', $ours ) );
	}

	/**
	 * @testdox Reanudar SWAP_INTENT enlaza la decisión probada y nunca renombra una sombra READY_SWAP sin redrenar repair.
	 */
	public function test_publish_swap_intent_is_wired_to_revalidation_decision(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );
		$start  = strpos( $source, 'private function publish_run' );
		$end    = strpos( $source, 'private function resume_run', (int) $start );
		$method = substr( $source, (int) $start, (int) $end - (int) $start );

		$decision   = strpos( $method, 'self::recovery_decision( self::PHASE_SWAP_INTENT, $topology )' );
		$revalidate = strpos( $method, "'revalidate' === \$decision" );
		$catchup    = strpos( $method, "\$state['phase'] = self::PHASE_CATCHING_UP" );
		$rename     = strpos( $method, '$this->rename_locked( $state )' );

		self::assertIsInt( $decision );
		self::assertIsInt( $revalidate );
		self::assertIsInt( $catchup );
		self::assertIsInt( $rename );
		self::assertTrue( $decision < $revalidate && $revalidate < $catchup && $catchup < $rename );
	}

	/**
	 * @testdox Una sombra 99 de 100 materializa repair y no obtiene permiso de swap.
	 */
	public function test_ninety_nine_of_one_hundred_is_not_exact(): void {
		$coverage = $this->invoke_static( 'coverage_from_sets', range( 1, 100 ), range( 1, 99 ), array() );
		$repairs  = $this->invoke_static( 'repair_ids_from_coverage', $coverage );

		self::assertSame( array( 100 ), $coverage['missing'] );
		self::assertSame( array( 100 ), $repairs );
		self::assertNotSame( array(), $repairs, '99/100 debe bloquear el cutover y crear deuda repair.' );
	}

	/**
	 * @testdox Un doc extra y un token huerfano se corrigen antes de volver a consultar cobertura.
	 */
	public function test_extra_doc_and_orphan_are_repair_debt(): void {
		$coverage = $this->invoke_static( 'coverage_from_sets', range( 1, 100 ), range( 1, 101 ), array( 77, 130 ) );
		$repairs  = $this->invoke_static( 'repair_ids_from_coverage', $coverage );

		self::assertSame( array( 101 ), $coverage['extra'] );
		self::assertSame( array( 77, 101, 130 ), $repairs );
	}

	/**
	 * @testdox Igualdad exacta y ausencia de huerfanos deja cero repair.
	 */
	public function test_exact_set_has_no_repair_debt(): void {
		$coverage = $this->invoke_static( 'coverage_from_sets', range( 1, 100 ), range( 1, 100 ), array() );

		self::assertSame( array(), $coverage['missing'] );
		self::assertSame( array(), $coverage['extra'] );
		self::assertSame( array(), $this->invoke_static( 'repair_ids_from_coverage', $coverage ) );
	}

	/**
	 * @testdox Un get_col vacio con last_error no puede parecer catalogo vacio exacto.
	 */
	public function test_database_error_makes_coverage_invalid(): void {
		$GLOBALS['wpdb'] = new RebuildProtocolWpdb(
			array(
				array( array(), 'source query failed' ),
				array( array(), '' ),
				array( array(), '' ),
				array( array(), '' ),
			)
		);

		$coverage = $this->invoke_static( 'coverage_snapshot' );

		self::assertFalse( $coverage['valid'] );
		self::assertSame( array(), $this->invoke_static( 'repair_ids_from_coverage', $coverage ) );
	}

	/**
	 * @testdox Un count SQL false queda invalido y nunca se coerciona a cero publicable.
	 */
	public function test_failed_work_count_cannot_allow_cutover(): void {
		$failed = $this->invoke_static( 'normalise_count_result', false );

		self::assertNull( $failed );
		self::assertFalse( $this->invoke_static( 'counts_allow_cutover', array( 0, 0, 0, $failed ) ) );
		self::assertFalse( $this->invoke_static( 'counts_allow_cutover', array( 0, 1, 0, 0 ) ) );
		self::assertTrue( $this->invoke_static( 'counts_allow_cutover', array( 0, 0, 0, 0 ) ) );
	}

	/**
	 * @testdox Status expone BLOCKED cuando falla SELECT de estado y no lo oculta como null.
	 */
	public function test_status_surfaces_state_read_failure(): void {
		$GLOBALS['wpdb'] = new RebuildStateFailureWpdb();

		$status = RebuildJob::status();

		self::assertIsArray( $status );
		self::assertSame( RebuildJob::PHASE_BLOCKED, $status['phase'] );
		self::assertSame( 'state_read_failed', $status['error'] );
		self::assertTrue( $status['stalled'] );
	}

	/**
	 * @testdox Una accion stale de otro run o scope no puede reclamar ni avanzar el vigente.
	 */
	public function test_stale_action_does_not_match_current_run(): void {
		$state = array(
			'run_id' => 'run-current',
			'phase'  => RebuildJob::PHASE_BUILDING,
		);

		self::assertFalse( $this->invoke_static( 'action_matches_state', $state, 'run-stale', 'baseline' ) );
		self::assertFalse( $this->invoke_static( 'action_matches_state', $state, 'run-current', 'repair' ) );
		self::assertTrue( $this->invoke_static( 'action_matches_state', $state, 'run-current', 'baseline' ) );
	}

	/**
	 * @testdox Un enqueue AS igual a cero reporta fallo y no toca options ni deuda durable.
	 */
	public function test_zero_action_id_does_not_ack_or_delete_debt(): void {
		$option_writes = 0;
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 0 );
		Functions\when( 'update_option' )->alias(
			static function () use ( &$option_writes ): bool {
				++$option_writes;
				return true;
			}
		);

		self::assertFalse( $this->invoke_static( 'enqueue_wakeup', 'run-7', 'baseline' ) );
		self::assertSame( 0, $option_writes, 'El wake-up no puede borrar ni ACKear la deuda durable.' );
	}

	/**
	 * @testdox Un pump con cero claims no hace busy-loop sobre deuda con backoff futuro.
	 */
	public function test_zero_claim_pump_leaves_delayed_debt_to_watchdog(): void {
		self::assertFalse( $this->invoke_static( 'should_enqueue_wakeup', true, false, 'baseline' ) );
		self::assertTrue( $this->invoke_static( 'should_enqueue_wakeup', true, true, 'baseline' ) );
		self::assertFalse( $this->invoke_static( 'should_enqueue_wakeup', false, true, 'repair' ) );
	}

	/**
	 * @testdox El source conserva todas las fases recuperables y terminales observables.
	 */
	public function test_all_protocol_phases_are_distinct(): void {
		$phases = array(
			RebuildJob::PHASE_PREPARING,
			RebuildJob::PHASE_BUILDING,
			RebuildJob::PHASE_CATCHING_UP,
			RebuildJob::PHASE_SWAP_INTENT,
			RebuildJob::PHASE_SWAPPED,
			RebuildJob::PHASE_PUBLISHING,
			RebuildJob::PHASE_CLEANUP,
			RebuildJob::PHASE_DONE,
			RebuildJob::PHASE_BLOCKED,
			RebuildJob::PHASE_ABORTED,
		);

		self::assertCount( 10, array_unique( $phases ) );
	}

	/**
	 * @testdox Buckets corren antes del generation target final y este se verifica antes de CLEANUP.
	 */
	public function test_publish_order_preserves_generation_target(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );
		$start  = strpos( $source, 'private function publish_run' );
		$end    = strpos( $source, 'private function resume_run', (int) $start );
		$method = substr( $source, (int) $start, (int) $end - (int) $start );

		$price      = strpos( $method, "\$state['ack_price_buckets']" );
		$generation = strpos( $method, "\$state['ack_generation']" );
		$purge      = strpos( $method, "\$state['ack_rest_purge']" );
		$verify     = strrpos( $method, 'get_option( Install::OPTION_INDEX_GEN' );
		$cleanup    = strpos( $method, "\$state['phase'] = self::PHASE_CLEANUP" );

		self::assertIsInt( $price );
		self::assertIsInt( $generation );
		self::assertIsInt( $purge );
		self::assertTrue( $price < $generation && $generation < $purge );
		self::assertIsInt( $verify );
		self::assertIsInt( $cleanup );
		self::assertTrue( $verify < $cleanup, 'El generation target debe releerse antes de habilitar CLEANUP.' );
	}

	/**
	 * @testdox Rebuild toma fingerprint tras buckets, publica un target ligado a la foto y sólo después la sella.
	 */
	public function test_rebuild_publication_seals_the_exact_semantic_fingerprint(): void {
		$fingerprint_a = str_repeat( 'a', 64 );
		$fingerprint_b = str_repeat( 'b', 64 );
		$target_a      = $this->invoke_static( 'generation_target_for_fingerprint', 'run-semantic', $fingerprint_a );
		$target_b      = $this->invoke_static( 'generation_target_for_fingerprint', 'run-semantic', $fingerprint_b );

		self::assertNotSame( $target_a, $target_b );
		self::assertStringContainsString( $fingerprint_a, $target_a );
		self::assertSame( '', $this->invoke_static( 'generation_target_for_fingerprint', '', $fingerprint_a ) );
		self::assertSame( '', $this->invoke_static( 'generation_target_for_fingerprint', 'run-semantic', 'invalid' ) );

		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );
		$start  = strpos( $source, 'private function publish_run' );
		$end    = strpos( $source, 'private function resume_run', (int) $start );
		$method = substr( $source, (int) $start, (int) $end - (int) $start );

		$buckets     = strpos( $method, 'PriceBuckets::maybe_refresh_auto( false )' );
		$fingerprint = strpos( $method, 'SemanticPublication::current_fingerprint()' );
		$generation  = strpos( $method, 'Install::bump_index_generation( $generation_target )' );
		$purge       = strpos( $method, 'RestCache::purge( $generation_target )' );
		$seal        = strpos( $method, 'SemanticPublication::seal_external_fingerprint( $semantic_fingerprint, $generation_target )' );
		$cleanup     = strpos( $method, "\$state['phase'] = self::PHASE_CLEANUP" );

		self::assertIsInt( $buckets );
		self::assertIsInt( $fingerprint );
		self::assertIsInt( $generation );
		self::assertIsInt( $purge );
		self::assertIsInt( $seal );
		self::assertIsInt( $cleanup );
		self::assertTrue(
			$buckets < $fingerprint
			&& $fingerprint < $generation
			&& $generation < $purge
			&& $purge < $seal
			&& $seal < $cleanup
		);
	}

	/**
	 * @testdox Error SQL del COUNT no puede producir un receipt de publicación exitoso.
	 */
	public function test_publish_receipt_count_error_fails_closed(): void {
		$GLOBALS['wpdb'] = new RebuildReceiptFailureWpdb();
		$target          = 'count-failure-generation';
		$options         = array(
			Install::OPTION_INDEX_GEN       => $target,
			RestCache::OPTION_PURGE_RECEIPT => array(
				'protocol'          => 1,
				'generation_target' => $target,
				'tag'               => RestCache::TAG,
				'dispatched_at'     => 1,
				'ack_scope'         => 'local_dispatch_only',
			),
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		$fingerprint = SemanticPublication::current_fingerprint();
		self::assertIsString( $fingerprint );
		$options[ SemanticPublication::OPTION_FINGERPRINT ] = array(
			'protocol'     => 1,
			'fingerprint'  => $fingerprint,
			'generation'   => $target,
			'published_at' => 1,
		);

		self::assertFalse(
			$this->invoke_static(
				'persist_publish_receipt',
				array(
					'run_id'                     => 'run-count-failure',
					'started_at'                  => 1,
					'expected'                    => 4,
					'generation_target'           => $target,
					'semantic_fingerprint'        => $fingerprint,
					'ack_generation'              => true,
					'ack_rest_purge'              => true,
					'ack_price_buckets'           => true,
					'ack_semantic_fingerprint'    => true,
				)
			)
		);
	}

	/**
	 * @testdox CLEANUP y DONE aceptan receipt exacto del run aunque una generación live posterior lo haya supersedido.
	 */
	public function test_cleanup_receipt_survives_later_live_generation(): void {
		$target  = 'rebuild-generation-old';
		$run_id  = 'run-cleanup-crash';
		$fingerprint = str_repeat( 'c', 64 );
		$receipt = array(
			'run_id'               => $run_id,
			'result'               => 'ok',
			'phase'                => RebuildJob::PHASE_PUBLISHING,
			'publication_protocol' => 1,
			'generation_target'    => $target,
			'publication_acked'    => true,
			'purge_ack_scope'       => 'local_dispatch_only',
			'semantic_fingerprint' => $fingerprint,
			'semantic_fingerprint_acked' => true,
		);
		$options = array(
			Install::OPTION_INDEX_GEN => 'live-generation-newer',
			RebuildJob::OPTION_LAST    => $receipt,
			RebuildJob::OPTION_HISTORY => array( $receipt ),
		);
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => array_key_exists( $name, $options ) ? $options[ $name ] : $default
		);

		foreach ( array( RebuildJob::PHASE_CLEANUP, RebuildJob::PHASE_DONE ) as $phase ) {
			$state = array(
				'run_id'             => $run_id,
				'phase'              => $phase,
				'generation_target'  => $target,
				'semantic_fingerprint' => $fingerprint,
				'ack_generation'     => true,
				'ack_rest_purge'     => true,
				'ack_price_buckets'  => true,
				'ack_semantic_fingerprint' => true,
				'ack_history'        => true,
			);
			self::assertFalse( (bool) $this->invoke_static( 'publication_acked', $state, false ) );
			self::assertTrue( (bool) $this->invoke_static( 'publication_acked', $state, true ) );
		}

		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );
		self::assertStringContainsString( 'self::publication_acked( $state, true )', $source );
		self::assertGreaterThanOrEqual( 2, substr_count( $source, 'self::publication_acked( $state, true )' ) );
	}

	/**
	 * @testdox CLEANUP legacy sin fingerprint sellado vuelve a PUBLISHING y no llega a cleanup_old.
	 */
	public function test_cleanup_legacy_publication_cannot_drop_old_tables(): void {
		$legacy = array(
			'run_id'             => 'run-legacy-cleanup',
			'phase'              => RebuildJob::PHASE_CLEANUP,
			'generation_target'  => 'legacy-generation',
			'ack_generation'     => true,
			'ack_rest_purge'     => true,
			'ack_price_buckets'  => true,
			'ack_history'        => true,
		);
		self::assertFalse( (bool) $this->invoke_static( 'publication_acked', $legacy, true ) );

		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );
		$start  = strpos( $source, 'private function publish_run' );
		$end    = strpos( $source, 'private function resume_run', (int) $start );
		$method = substr( $source, (int) $start, (int) $end - (int) $start );

		$legacy_gate = strpos( $method, "self::PHASE_CLEANUP === (string) \$state['phase'] && ! self::publication_acked( \$state, true )" );
		$rewind      = strpos( $method, "\$state['phase']                        = self::PHASE_PUBLISHING", (int) $legacy_gate );
		$return      = strpos( $method, 'return;', (int) $rewind );
		$drop_old    = strpos( $method, 'Install::cleanup_old(', (int) $legacy_gate );

		self::assertIsInt( $legacy_gate );
		self::assertIsInt( $rewind );
		self::assertIsInt( $return );
		self::assertIsInt( $drop_old );
		self::assertTrue( $legacy_gate < $rewind && $rewind < $return && $return < $drop_old );
		self::assertStringContainsString( "'cleanup_publication_revalidation_required'", $method );
	}

	/**
	 * @testdox PREPARING revalida el dueño fisico antes de dbDelta y antes de sellar manifest/estado.
	 */
	public function test_prepare_asserts_fence_around_ddl_and_manifest(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/RebuildJob.php' );
		$start  = strpos( $source, 'private function prepare_locked' );
		$end    = strpos( $source, 'private function recover_observed_shadow_create_failure', (int) $start );
		$method = substr( $source, (int) $start, (int) $end - (int) $start );

		$create = strpos( $method, 'Install::create_shadow_tables()' );
		$seed   = strpos( $method, '$this->work->seed_baseline' );
		$asserts = array();
		$cursor  = 0;
		while ( false !== ( $found = strpos( $method, '$this->assert_fence_owned()', $cursor ) ) ) {
			$asserts[] = $found;
			$cursor    = $found + 1;
		}

		$before_create = array_values( array_filter( $asserts, static fn( int $position ): bool => $position < $create ) );
		$after_create  = array_values( array_filter( $asserts, static fn( int $position ): bool => $position > $create ) );
		$before_seed   = array_values( array_filter( $asserts, static fn( int $position ): bool => $position < $seed ) );
		$after_seed    = array_values( array_filter( $asserts, static fn( int $position ): bool => $position > $seed ) );

		self::assertGreaterThanOrEqual( 5, count( $asserts ) );
		self::assertNotEmpty( $before_create );
		self::assertNotEmpty( $after_create );
		self::assertNotEmpty( $before_seed );
		self::assertNotEmpty( $after_seed );
		self::assertTrue( max( $before_create ) < $create && $create < min( $after_create ) );
		self::assertTrue( max( $before_seed ) < $seed && $seed < min( $after_seed ) );
	}

	/**
	 * @testdox El watchdog recurrente se agenda con SU intervalo, en su hook y su grupo.
	 *
	 * El intervalo subió de 300 a 900 s porque el vigilante era el 36 % de la
	 * cola de Action Scheduler y no dependía de que hubiera trabajo (5.668
	 * acciones en 31 dias frente a 756 rebuild_batch, medido en produccion). Lo
	 * que fija este test es que la constante gobierne de verdad las dos puntas
	 * del agendado —arranque y recurrencia—: si sólo gobernara una, cambiarla
	 * dejaría un vigilante con cadencia distinta de la declarada.
	 */
	public function test_watchdog_interval_is_scheduled_and_stays_inside_recovery_window(): void {
		self::assertGreaterThan( 0, RebuildJob::WATCHDOG_INTERVAL );

		$scheduled = array();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_recurring_action' )->alias(
			static function ( int $start, int $interval, string $hook, array $args, string $group ) use ( &$scheduled ): int {
				$scheduled = compact( 'start', 'interval', 'hook', 'group' );
				return 7;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$job = ( new \ReflectionClass( RebuildJob::class ) )->newInstanceWithoutConstructor();
		$job->ensure_watchdog();

		self::assertSame( RebuildJob::WATCHDOG_INTERVAL, $scheduled['interval'] ?? null );
		self::assertSame( RebuildJob::HOOK_WATCHDOG, $scheduled['hook'] ?? null );
		self::assertSame( Indexer::AS_GROUP, $scheduled['group'] ?? null );
		self::assertGreaterThanOrEqual(
			time() + RebuildJob::WATCHDOG_INTERVAL - 2,
			$scheduled['start'] ?? 0,
			'La primera vuelta también sale del intervalo declarado.'
		);
	}

	/**
	 * Invoca un helper puro privado sin abrir superficie publica de runtime.
	 *
	 * @param string $method Nombre.
	 * @param mixed  ...$args Argumentos.
	 * @return mixed
	 */
	private function invoke_static( string $method, ...$args ) {
		$reflection = new ReflectionMethod( RebuildJob::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$args );
	}
}
