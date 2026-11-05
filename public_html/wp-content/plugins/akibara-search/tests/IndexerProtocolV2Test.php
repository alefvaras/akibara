<?php
/**
 * Contrato focal del worker transaccional del protocolo de indexacion v2.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

	// WC_Product vive como doble ÚNICO en Support/wp-function-stubs.php: tres
	// copias distintas tras `class_exists` hacían que la forma ganadora dependiera
	// del orden de ejecución (flake medido con --order-by=random).

	// WP_Post vive como doble ÚNICO en Support/wp-function-stubs.php: las copias
	// por archivo hacían que la forma ganadora dependiera del orden de ejecución.

	// WP_Error vive como doble ÚNICO en Support/wp-function-stubs.php: las copias
	// por archivo hacían que la forma ganadora dependiera del orden de ejecución.

	// WP_Term vive como doble ÚNICO en Support/wp-function-stubs.php: las copias
	// por archivo hacían que la forma ganadora dependiera del orden de ejecución.
}

namespace Akibara\Search\Tests {

	use Akibara\Search\DocumentBuilder;
	use Akibara\Search\AuditLog;
	use Akibara\Search\FacetRegistry;
	use Akibara\Search\Indexer;
	use Akibara\Search\Install;
	use Akibara\Search\ProductSourceAuthority;
	use Akibara\Search\RebuildJob;
	use Akibara\Search\SemanticPublication;
	use Akibara\Search\WorkRepository;
	use Akibara\Search\WriteFence;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__ ) . '/src/Normalizer.php';
	require_once dirname( __DIR__ ) . '/src/FacetRegistry.php';
	require_once dirname( __DIR__ ) . '/src/StockLabels.php';
	require_once dirname( __DIR__ ) . '/src/AuditLog.php';
	require_once dirname( __DIR__ ) . '/src/WorkRepository.php';
	require_once dirname( __DIR__ ) . '/src/WriteFence.php';

	/**
	 * Doble stateful de wpdb para claims, fence y transacciones del Indexer.
	 */
	final class IndexerProtocolWpdb {

		public string $prefix = 'wp_';
		public string $dbname = 'akibara_test';
		public string $options = 'wp_options';
		public string $posts = 'wp_posts';
		public string $last_error = '';

		/** @var callable|null */
		public $post_source_reader;

		/** @var array<int, string> */
		public array $queries = array();

		/** @var array<string, mixed>|null */
		public ?array $work_row = null;

		/** @var array<int, int|false> */
		public array $commit_results = array();

		/** @var array<string, mixed>|null */
		private ?array $transaction_snapshot = null;
		private bool $transaction_open = false;

		/** @var array<string, string> option_name => option_value serializado. */
		public array $option_values = array();

		public bool $stale_validation = false;
		public ?string $state_json = null;
		public bool $lock_active = false;

		/** @var array<int, string> */
		public array $events = array();

		/** @var array<int, int> */
		public array $durable_products = array();

		/** @var array<int, int> */
		public array $fail_dirty_products = array();

		/**
		 * Productos que YA tienen fila en la tabla viva de documentos.
		 *
		 * Desde 0.1.64 el claim live compara contra las filas vivas antes de
		 * escribir: reescribir lo idéntico no publica generación. Un escenario
		 * que quiera probar una baja REAL (o la publicación que la acompaña)
		 * tiene que declarar aquí que el producto estaba indexado; si no, la
		 * baja de un producto ausente es —correctamente— un no-op.
		 *
		 * @var array<int, int>
		 */
		public array $indexed_products = array();

		/**
		 * @param string $query Sentencia con placeholders.
		 * @param mixed  ...$args Valores.
		 * @return string
		 */
		public function prepare( string $query, ...$args ): string {
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
		 * @param string $query Sentencia.
		 * @return int|false
		 */
		public function query( string $query ) {
			$this->queries[] = $query;
			$trimmed         = ltrim( $query );

			if ( 0 === stripos( $trimmed, 'START TRANSACTION' ) ) {
				$this->transaction_snapshot = $this->work_row;
				$this->transaction_open     = true;
				return 0;
			}

			if ( 0 === stripos( $trimmed, 'COMMIT' ) ) {
				$this->events[] = 'commit';
				$result = array() === $this->commit_results ? 0 : array_shift( $this->commit_results );
				if ( false !== $result ) {
					$this->transaction_snapshot = null;
					$this->transaction_open     = false;
				}
				return $result;
			}

			if ( 0 === stripos( $trimmed, 'ROLLBACK' ) ) {
				$this->work_row            = $this->transaction_snapshot;
				$this->transaction_snapshot = null;
				$this->transaction_open     = false;
				return 0;
			}

			if ( false !== stripos( $trimmed, 'INSERT IGNORE INTO wp_options' )
				&& 1 === preg_match( "/VALUES \\('((?:''|[^'])*)', '((?:''|[^'])*)', '((?:''|[^'])*)'\\)/s", $query, $matches ) ) {
				$name = str_replace( "''", "'", $matches[1] );
				if ( array_key_exists( $name, $this->option_values ) ) {
					return 0;
				}
				$this->option_values[ $name ] = str_replace( "''", "'", $matches[2] );
				return 1;
			}

			if ( false !== stripos( $trimmed, 'UPDATE wp_options SET option_value' )
				&& 1 === preg_match( "/SET option_value = '((?:''|[^'])*)' WHERE option_name = '((?:''|[^'])*)' AND (?:BINARY )?option_value = (?:BINARY )?'((?:''|[^'])*)'/s", $query, $matches ) ) {
				$replacement = str_replace( "''", "'", $matches[1] );
				$name        = str_replace( "''", "'", $matches[2] );
				$expected    = str_replace( "''", "'", $matches[3] );
				if ( ! array_key_exists( $name, $this->option_values ) || $expected !== $this->option_values[ $name ] ) {
					return 0;
				}
				$this->option_values[ $name ] = $replacement;
				return 1;
			}

			if ( false !== stripos( $trimmed, 'DELETE FROM wp_options WHERE option_name' )
				&& 1 === preg_match( "/option_name = '((?:''|[^'])*)' AND (?:BINARY )?option_value = (?:BINARY )?'((?:''|[^'])*)'/s", $query, $matches ) ) {
				$name     = str_replace( "''", "'", $matches[1] );
				$expected = str_replace( "''", "'", $matches[2] );
				if ( ! array_key_exists( $name, $this->option_values ) || $expected !== $this->option_values[ $name ] ) {
					return 0;
				}
				unset( $this->option_values[ $name ] );
				return 1;
			}

			if ( false !== strpos( $query, 'INSERT INTO `wp_akb_search_work`' ) ) {
				$this->events[] = 'dirty';
				preg_match_all( "/\\('live', '', '([0-9]+)', 1, 0, '', 'pending'/", $query, $matches );
				$product_ids = array_map( 'intval', $matches[1] ?? array() );
				if ( array() !== array_intersect( $product_ids, $this->fail_dirty_products ) ) {
					$this->last_error = 'forced dirty failure';
					return false;
				}

				foreach ( $product_ids as $product_id ) {
					$this->durable_products[] = $product_id;
					if ( null === $this->work_row || $product_id !== (int) $this->work_row['product_id'] ) {
						$this->work_row = $this->row( WorkRepository::SCOPE_LIVE, '', $product_id, WorkRepository::STATUS_PENDING );
					} else {
						$this->work_row['wanted_seq'] = (int) $this->work_row['wanted_seq'] + 1;
						if ( WorkRepository::STATUS_LEASED !== $this->work_row['status'] ) {
							$this->work_row['status']      = WorkRepository::STATUS_PENDING;
							$this->work_row['lease_token'] = '';
							$this->work_row['lease_seq']   = 0;
						}
					}
				}
				return max( 1, count( $product_ids ) );
			}

			if ( false !== strpos( $query, "SET status = 'leased'" ) ) {
				if ( null === $this->work_row || 1 !== preg_match( "/lease_token = '([^']+)'/", $query, $matches ) ) {
					return 0;
				}
				$this->work_row['status']      = WorkRepository::STATUS_LEASED;
				$this->work_row['lease_token'] = $matches[1];
				$this->work_row['lease_seq']   = (int) $this->work_row['wanted_seq'];
				$this->work_row['attempts']    = (int) $this->work_row['attempts'] + 1;
				return 1;
			}

			if ( false !== strpos( $query, 'SET acked_seq = GREATEST' ) ) {
				if ( null === $this->work_row ) {
					return 0;
				}
				$lease_seq                    = (int) $this->work_row['lease_seq'];
				$this->work_row['acked_seq'] = max( (int) $this->work_row['acked_seq'], $lease_seq );
				$this->work_row['outcome']   = false !== strpos( $query, "outcome = 'indexed'" )
					? WorkRepository::OUTCOME_INDEXED
					: WorkRepository::OUTCOME_ABSENT;
				$this->work_row['status']      = (int) $this->work_row['wanted_seq'] > $lease_seq
					? WorkRepository::STATUS_PENDING
					: WorkRepository::STATUS_ACKED;
				$this->work_row['lease_token'] = '';
				$this->work_row['lease_seq']   = 0;
				$this->work_row['lease_until'] = null;
				$this->work_row['attempts']    = 0;
				return 1;
			}

			if ( false !== strpos( $query, "SET status = 'pending'" ) ) {
				if ( null === $this->work_row ) {
					return 0;
				}
				$this->work_row['status']      = WorkRepository::STATUS_PENDING;
				$this->work_row['lease_token'] = '';
				$this->work_row['lease_seq']   = 0;
				$this->work_row['lease_until'] = null;
				return 1;
			}

			if ( 0 === stripos( $trimmed, 'DELETE FROM wp_akb_search_' ) ) {
				$this->events[] = false !== strpos( $query, '_new ' ) ? 'shadow_write' : 'live_write';
				return 1;
			}

			if ( 0 === stripos( $trimmed, 'INSERT IGNORE INTO ' ) ) {
				return substr_count( $query, '), (' ) + 1;
			}

			if ( 0 === stripos( $trimmed, 'INSERT INTO wp_akb_search_' )
				|| 0 === stripos( $trimmed, 'UPDATE wp_akb_search_' ) ) {
				$this->events[] = false !== strpos( $query, '_new' ) ? 'shadow_write' : 'live_write';
				return 1;
			}

			return 1;
		}

		/** @return object|null */
		public function get_row( string $query ): ?object {
			$this->queries[] = $query;
			if ( null === $this->work_row ) {
				return null;
			}

			if ( false !== strpos( $query, 'LIMIT 1 FOR UPDATE' )
				&& false === strpos( $query, 'WHERE id =' )
				&& WorkRepository::STATUS_PENDING !== $this->work_row['status'] ) {
				return null;
			}

			if ( 1 === preg_match( "/product_id = '([0-9]+)'/", $query, $matches )
				&& (int) $matches[1] !== (int) $this->work_row['product_id'] ) {
				return null;
			}

			return (object) $this->work_row;
		}

		/** @return array<int, array<string, string>> */
		public function get_results( string $query, $output = null ): array {
			unset( $output );
			$this->queries[] = $query;
			if ( false !== strpos( $query, 'FROM wp_akb_search_docs WHERE post_id' )
				&& 1 === preg_match( "/post_id = '([0-9]+)'/", $query, $docs_match )
				&& in_array( (int) $docs_match[1], $this->indexed_products, true ) ) {
				// Basta con que la fila EXISTA: cualquiera de sus columnas difiere
				// de lo que el builder produce, así que el claim decide «cambió» —
				// que es justo lo que pasa cuando un producto indexado se da de
				// baja o se reescribe.
				return array( array( 'post_id' => $docs_match[1] ) );
			}
			if ( false === strpos( $query, 'FROM wp_posts' ) || ! is_callable( $this->post_source_reader ) ) {
				return array();
			}
			if ( 1 !== preg_match( "/WHERE ID = '([0-9]+)'/", $query, $matches ) ) {
				return array();
			}
			$row = ( $this->post_source_reader )( (int) $matches[1] );
			return is_array( $row ) ? array( $row ) : array();
		}

		/** @return mixed */
		public function get_var( string $query ) {
			$this->queries[] = $query;

			if ( false !== strpos( $query, '@@in_transaction' ) ) {
				return $this->transaction_open ? '1' : '0';
			}

			if ( false !== strpos( $query, 'GET_LOCK(' ) ) {
				$this->lock_active = true;
				return 1;
			}
			if ( false !== strpos( $query, 'CONNECTION_ID()' ) ) {
				return 91;
			}
			if ( false !== strpos( $query, 'IS_USED_LOCK(' ) ) {
				return $this->lock_active ? 91 : null;
			}
			if ( false !== strpos( $query, 'RELEASE_LOCK(' ) ) {
				$this->lock_active = false;
				$this->events[]    = 'release';
				return 1;
			}
			if ( false !== strpos( $query, 'SELECT option_value FROM wp_options' )
				&& 1 === preg_match( "/option_name = '((?:''|[^'])*)'/", $query, $matches ) ) {
				$name = str_replace( "''", "'", $matches[1] );
				if ( RebuildJob::OPTION_STATE === $name ) {
					return $this->state_json;
				}
				return $this->option_values[ $name ] ?? null;
			}
			if ( false !== strpos( $query, 'SELECT id FROM `wp_akb_search_work`' ) ) {
				return $this->stale_validation || null === $this->work_row
					? null
					: (int) $this->work_row['id'];
			}

			return null;
		}

		/** @return array<int, string> */
		public function get_col( string $query ): array {
			$this->queries[] = $query;
			return array(
				'wp_akb_search_docs',
				'wp_akb_search_tokens',
				'wp_akb_search_facet_index',
				'wp_akb_search_docs_new',
				'wp_akb_search_tokens_new',
				'wp_akb_search_facet_index_new',
			);
		}

		/**
		 * @param string $scope      Scope.
		 * @param string $run_id     Run.
		 * @param int    $product_id Producto.
		 * @param string $status     Estado.
		 * @return array<string, mixed>
		 */
		public function row( string $scope, string $run_id, int $product_id, string $status ): array {
			return array(
				'id'          => $product_id,
				'scope'       => $scope,
				'run_id'      => $run_id,
				'product_id'  => $product_id,
				'wanted_seq'  => 1,
				'acked_seq'   => 0,
				'outcome'     => '',
				'status'      => $status,
				'available_at'=> '2026-01-01 00:00:00',
				'lease_token' => WorkRepository::STATUS_LEASED === $status ? 'shadow-token' : '',
				'lease_seq'   => WorkRepository::STATUS_LEASED === $status ? 1 : 0,
				'lease_until' => WorkRepository::STATUS_LEASED === $status ? '2099-01-01 00:00:00' : null,
				'attempts'    => 0,
			);
		}
	}

	/**
	 * @covers \Akibara\Search\Indexer
	 */
	final class IndexerProtocolV2Test extends TestCase {

		private IndexerProtocolWpdb $wpdb;
		private WorkRepository $work;
		private Indexer $sut;

		/** @var array<string, mixed> */
		private array $options = array();

		private bool $persist_generation = true;
		private string $source_type = 'post';
		private string $source_status = 'trash';
		private ?\WC_Product $source_product = null;
		private bool $source_read_error = false;
		private bool $source_type_returns_false = false;
		private ?string $cached_source_type = null;
		private bool $source_status_clears_error = false;

		/** @var array<int, int>|null Foto de autoridad explícita para casos A→B. */
		private ?array $authoritative_source_ids = null;
		private bool $builder_read_error = false;
		private bool $dirty_during_source = false;
		private int $as_action_id = 1;
		private int $source_parent_id = 0;
		private bool $source_parent_read_error = false;

		/** @var array<int, \WC_Product|null> */
		private array $product_sources = array();

		/** @var array<int, int> Hijos directos de la foto SQL, independiente de get_children(). */
		private array $authoritative_variation_ids = array();

		/** @var array<string, mixed> */
		private array $variable_price_transients = array();

		private bool $variable_transient_delete_noop = false;

		/** @var mixed */
		private $effective_price_override = null;

		private bool $has_effective_price_override = false;

		/** @var array<string, mixed> */
		private array $builder_terms_by_taxonomy = array();

		/** @var array<int, int> */
		private array $ancestor_ids = array();

		/** @var array<int, mixed> */
		private array $ancestor_terms = array();

		/** @var mixed */
		private $direct_children = array();

		private bool $term_query_error = false;

		/** @var array<int, string> */
		private array $wake_hooks = array();

		/**
		 * Cola de Action Scheduler modelada, no sólo contada.
		 *
		 * El doble anterior registraba el wake y lo olvidaba, así que
		 * `as_get_scheduled_actions()` respondía «no hay nada pendiente» un
		 * instante después de encolar. Con el dedup preguntando a la cola
		 * —y no a un memo de proceso— ese doble dejó de modelar producción:
		 * ahí el INSERT es síncrono y la segunda llamada de la misma request
		 * SÍ ve la primera.
		 *
		 * @var array<int, array{hook: string, args: array<int|string, mixed>}>
		 */
		private array $cola_programada = array();

		/** @var array<int, int> */
		private array $term_ids = array();

		/** @var array<int, array<string, mixed>> */
		private array $get_posts_args = array();

		/** @var array<int, array<string, mixed>> */
		private array $get_terms_args = array();

		/** @var array<int, array{hook: string, accepted_args: int}> */
		private array $registered_actions = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			$this->wpdb                = new IndexerProtocolWpdb();
			$this->options             = array(
				Install::OPTION_KEY         => Install::DB_VERSION,
				Install::OPTION_SCHEMA_SEAL => '1.7.0-exact-1',
			);
			$this->persist_generation        = true;
			$this->source_type               = 'post';
			$this->source_status             = 'trash';
			$this->source_product            = null;
			$this->source_read_error         = false;
			$this->source_type_returns_false = false;
			$this->cached_source_type         = null;
			$this->source_status_clears_error = false;
			$this->authoritative_source_ids   = null;
			$this->builder_read_error        = false;
			$this->dirty_during_source       = false;
			$this->as_action_id              = 1;
			$this->source_parent_id          = 0;
			$this->source_parent_read_error = false;
			$this->product_sources           = array();
			$this->authoritative_variation_ids = array();
			$this->variable_price_transients = array();
			$this->variable_transient_delete_noop = false;
			$this->effective_price_override = null;
			$this->has_effective_price_override = false;
			$this->builder_terms_by_taxonomy = array();
			$this->ancestor_ids        = array();
			$this->ancestor_terms      = array();
			$this->direct_children     = array();
			$this->term_query_error    = false;
			$this->wake_hooks          = array();
			$this->cola_programada     = array();
			$this->term_ids            = array();
			$this->get_posts_args      = array();
			$this->get_terms_args      = array();
			$this->registered_actions  = array();
			$GLOBALS['wpdb']           = $this->wpdb;
			$this->wpdb->post_source_reader = function ( int $post_id ): ?array {
				if ( $this->source_read_error ) {
					$this->wpdb->last_error = 'source read failed';
					return null;
				}
				return array(
					'ID'          => (string) $post_id,
					'post_type'   => $this->source_type,
					'post_parent' => (string) $this->source_parent_id,
				);
			};

			$this->work = new WorkRepository( $this->wpdb, 'wp_akb_search_work' );
			$fence     = new WriteFence( $this->wpdb, 'akb-indexer-test' );
			$source_authority = new ProductSourceAuthority(
				$this->wpdb,
				function ( array $ids ): array {
					if ( $this->source_read_error ) {
						$this->wpdb->last_error = 'source read failed';
					}
					if ( $this->dirty_during_source ) {
						$this->dirty_during_source = false;
						$this->work->mark_live_dirty( (int) reset( $ids ) );
					}
					if ( null !== $this->authoritative_source_ids ) {
						return $this->authoritative_source_ids;
					}
					return 'product' === $this->source_type && 'publish' === $this->source_status
						? $ids
						: array();
				},
				function ( int $parent_id, int $probe_limit ): array {
					unset( $parent_id );
					$products = array();
					foreach ( array_slice( $this->authoritative_variation_ids, 0, $probe_limit ) as $id ) {
						$products[] = $this->product_sources[ $id ] ?? null;
					}
					return $products;
				}
			);
			$this->sut = new Indexer( new DocumentBuilder(), $this->work, $fence, $source_authority );
			new RebuildJob( $this->sut, $this->work, $fence );

			$options = &$this->options;
			Functions\when( 'get_option' )->alias(
				static function ( string $name, $default = false ) use ( &$options ) {
					return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
				}
			);
			Functions\when( 'update_option' )->alias(
				function ( string $name, $value ): bool {
					if ( ! $this->persist_generation ) {
						return false;
					}
					$this->options[ $name ] = $value;
					if ( Install::OPTION_INDEX_GEN === $name ) {
						$this->wpdb->events[] = 'generation';
					}
					return true;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				function ( string $name ): bool {
					$this->wpdb->events[] = 'variable_price_transient_delete';
					if ( ! $this->variable_transient_delete_noop ) {
						unset( $this->variable_price_transients[ $name ] );
					}
					return true;
				}
			);
			Functions\when( 'get_transient' )->alias(
				function ( string $name ) {
					return $this->variable_price_transients[ $name ] ?? false;
				}
			);
			Functions\when( 'wp_cache_delete' )->alias(
				function ( string $key ): bool {
					if ( Install::OPTION_INDEX_GEN === $key ) {
						unset( $this->options[ $key ] );
					}
					return true;
				}
			);
			Functions\when( 'add_action' )->alias(
				function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
					$this->registered_actions[] = array(
						'hook'          => $hook,
						'accepted_args' => $accepted_args,
					);
					return true;
				}
			);
			Functions\when( 'as_enqueue_async_action' )->alias(
				function ( string $hook ): int {
					$this->wpdb->events[] = 'wake';
					$this->wake_hooks[]   = $hook;
					return $this->as_action_id;
				}
			);
			Functions\when( 'as_schedule_single_action' )->alias(
				function ( int $timestamp, string $hook, array $args = array() ): int {
					unset( $timestamp );
					$this->wpdb->events[] = 'wake_delayed';
					$this->wake_hooks[]   = $hook;
					if ( 0 !== $this->as_action_id ) {
						$this->cola_programada[] = array(
							'hook' => $hook,
							'args' => $args,
						);
					}
					return $this->as_action_id;
				}
			);
			Functions\when( 'as_get_scheduled_actions' )->alias(
				function ( array $query ): array {
					foreach ( $this->cola_programada as $pendiente ) {
						if ( $pendiente['hook'] === ( $query['hook'] ?? '' )
							&& $pendiente['args'] === ( $query['args'] ?? array() ) ) {
							return array( 1 );
						}
					}
					return array();
				}
			);
			Functions\when( 'get_post_type' )->alias(
				function () {
					if ( $this->source_read_error ) {
						$this->wpdb->last_error = 'source read failed';
					}
					return $this->source_type_returns_false ? false : ( $this->cached_source_type ?? $this->source_type );
				}
			);
			Functions\when( 'get_post_status' )->alias(
				function ( int $product_id ): string {
					if ( $this->source_status_clears_error ) {
						$this->wpdb->last_error = '';
					}
					if ( $this->dirty_during_source ) {
						$this->dirty_during_source = false;
						$this->work->mark_live_dirty( $product_id );
					}
					return $this->source_status;
				}
			);
			Functions\when( 'wp_get_post_parent_id' )->alias(
				function (): int {
					if ( $this->source_parent_read_error ) {
						$this->wpdb->last_error = 'parent source read failed';
					}
					return $this->source_parent_id;
				}
			);
			Functions\when( 'wc_get_product' )->alias(
				function ( int $product_id ) {
					return array_key_exists( $product_id, $this->product_sources )
						? $this->product_sources[ $product_id ]
						: $this->source_product;
				}
			);
			Functions\when( 'get_term' )->alias(
				function ( $term_id, string $taxonomy = '' ) {
					$term_id = (int) $term_id;
					if ( array_key_exists( $term_id, $this->ancestor_terms ) ) {
						return $this->ancestor_terms[ $term_id ];
					}
					return new \WP_Term( '' === $taxonomy ? 'product_cat' : $taxonomy, $term_id );
				}
			);
			Functions\when( 'get_posts' )->alias(
				function ( array $args ): array {
					$this->get_posts_args[] = $args;
					if ( $this->term_query_error ) {
						$this->wpdb->last_error = 'term fanout query failed';
						return array();
					}
					$per_page = (int) ( $args['posts_per_page'] ?? 100 );
					if ( -1 === $per_page ) {
						return $this->term_ids;
					}
					$page     = max( 1, (int) ( $args['paged'] ?? 1 ) );
					return array_slice( $this->term_ids, ( $page - 1 ) * $per_page, $per_page );
				}
			);
			Functions\when( 'get_the_terms' )->alias(
				function ( int $post_id, string $taxonomy ) {
					unset( $post_id );
					if ( $this->builder_read_error ) {
						$this->wpdb->last_error = 'term read failed';
					}
					return array_key_exists( $taxonomy, $this->builder_terms_by_taxonomy )
						? $this->builder_terms_by_taxonomy[ $taxonomy ]
						: false;
				}
			);
			Functions\when( 'get_ancestors' )->alias(
				function ( int $object_id, string $object_type = '', string $resource_type = '' ): array {
					unset( $object_id, $object_type, $resource_type );
					return $this->ancestor_ids;
				}
			);
			Functions\when( 'get_terms' )->alias(
				function ( array $args ) {
					$this->get_terms_args[] = $args;
					return $this->direct_children;
				}
			);
			Functions\when( 'is_taxonomy_hierarchical' )->alias( static fn( string $taxonomy ): bool => 'product_cat' === $taxonomy );
			Functions\when( 'is_wp_error' )->alias( static fn( $value ): bool => $value instanceof \WP_Error );
			Functions\when( 'get_permalink' )->alias( static fn( int $id ): string => 'https://akibara.cl/producto/' . $id );
			Functions\when( 'wp_kses_post' )->alias( static fn( string $html ): string => $html );
			Functions\when( 'current_time' )->justReturn( '2026-08-05 23:00:00' );
			Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $value ): string => strip_tags( $value ) );
			Functions\when( 'sanitize_key' )->alias( static fn( string $value ): string => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ) );
			Functions\when( 'wc_placeholder_img_src' )->justReturn( 'https://akibara.cl/placeholder.png' );
			Functions\when( 'apply_filters' )->alias(
				function ( string $hook, $value ) {
					return 'akb_search_effective_price' === $hook && $this->has_effective_price_override
						? $this->effective_price_override
						: $value;
				}
			);

			// Defensa: la foto semántica es estado estático compartido entre
			// clases; otra prueba puede dejarla adoptada con un snapshot inválido
			// y el indexador devolvería 'failed' donde se espera 'indexed'
			// (semilla 424242, QA 2026-09-24).
			SemanticPublication::reset_authoritative_state_for_tests();
			FacetRegistry::reset_cache();
		}

		protected function tearDown(): void {
			unset( $GLOBALS['wpdb'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * @testdox COMMIT igual a cero confirma el write, ACK y bump live.
		 */
		public function test_commit_zero_is_success(): void {
			$this->seed_live( 71 );
			// El producto ESTÁ indexado: darlo de baja retira filas vivas, o sea
			// cambia lo servido y exige publicar la generación. (Una baja de algo
			// que ya estaba ausente no publica nada desde 0.1.64.)
			$this->wpdb->indexed_products = array( 71 );
			$this->wpdb->commit_results = array( 0, 0 );

			self::assertSame( WorkRepository::OUTCOME_ABSENT, $this->sut->process_live_product( 71 ) );
			self::assertSame( WorkRepository::STATUS_ACKED, $this->wpdb->work_row['status'] ?? null );
			self::assertArrayHasKey( Install::OPTION_INDEX_GEN, $this->options );
			$commit_positions = array_keys( $this->wpdb->events, 'commit', true );
			$generation_pos   = array_search( 'generation', $this->wpdb->events, true );
			self::assertGreaterThan(
				$commit_positions[1],
				$generation_pos,
				'Generation must publish only after TX1 committed rows and repair debt.'
			);
			self::assertLessThan(
				end( $commit_positions ),
				$generation_pos,
				'Generation must be durable before TX2 ACK closes the work claim.'
			);
			self::assertSame( 2, $this->event_count( 'release' ), 'Live and publication fences must each release once.' );
		}

		/**
		 * @testdox COMMIT false revierte el ACK, omite bump y deja retry durable.
		 */
		public function test_commit_false_rolls_back_without_durable_ack_or_bump(): void {
			$this->seed_live( 72 );
			$this->wpdb->commit_results = array( 0, false );

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 72 ) );
			self::assertSame( WorkRepository::STATUS_PENDING, $this->wpdb->work_row['status'] ?? null );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
			self::assertArrayNotHasKey( Install::OPTION_INDEX_GEN, $this->options );
			self::assertSame( 1, $this->event_count( 'release' ), 'Fence must be released exactly once after rollback.' );
		}

		/**
		 * @testdox Un lease stale no escribe filas ni genera cache.
		 */
		public function test_stale_lease_never_writes(): void {
			$this->seed_live( 73 );
			$this->wpdb->stale_validation = true;

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 73 ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertArrayNotHasKey( Install::OPTION_INDEX_GEN, $this->options );
		}

		/**
		 * @testdox Un error de lectura de fuente nunca se ACKea como absent.
		 */
		public function test_source_read_error_is_failed_not_absent(): void {
			$this->seed_live( 74 );
			$this->source_read_error = true;

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 74 ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
		}

		/**
		 * @testdox Un error de tipo no puede ser borrado por la lectura posterior de status.
		 */
		public function test_post_type_error_cannot_be_erased_by_later_status_read(): void {
			$this->seed_live( 740 );
			$this->source_read_error          = true;
			$this->source_type_returns_false  = true;
			$this->source_status              = 'publish';
			$this->source_status_clears_error = true;

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 740 ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
		}

		/** @testdox La foto autoritativa B gana a prechecks get_post_type/status obsoletos A. */
		public function test_authoritative_source_snapshot_ignores_stale_post_prechecks(): void {
			$this->seed_live( 7401 );
			$this->source_type              = 'post';
			$this->source_status            = 'trash';
			$this->authoritative_source_ids = array( 7401 );
			$this->source_product           = new \WC_Product( 7401 );

			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->process_live_product( 7401 ) );
			self::assertGreaterThan( 0, $this->event_count( 'live_write' ) );
			self::assertSame( WorkRepository::STATUS_ACKED, $this->wpdb->work_row['status'] ?? null );
		}

		/** @testdox Publish crudo y status WC incompatible conserva retry sin DELETE ni ACK. */
		public function test_wc_status_mismatch_after_authority_is_failed_not_absent(): void {
			$this->seed_live( 7402 );
			$this->authoritative_source_ids = array( 7402 );
			$this->source_product = new class( 7402 ) extends \WC_Product {
				public function get_status(): string {
					return 'draft';
				}
			};

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 7402 ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
		}

		/**
		 * @testdox Un error SQL durante DocumentBuilder tampoco publica un documento parcial.
		 */
		public function test_builder_database_error_never_writes_or_acks(): void {
			$this->seed_live( 741 );
			$this->source_type       = 'product';
			$this->source_status     = 'publish';
			$this->source_product    = new \WC_Product( 741 );
			$this->builder_read_error = true;

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 741 ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
		}

		/** @testdox Un regular vacío en producto simple equivale a cero y permite publicar. */
		public function test_simple_empty_regular_price_indexes_as_zero(): void {
			$this->seed_live( 7411 );
			$this->source_type    = 'product';
			$this->source_status  = 'publish';
			$this->source_product = new class( 7411 ) extends \WC_Product {
				public function get_regular_price( string $context = 'view' ) {
					unset( $context );
					return '';
				}
			};

			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->process_live_product( 7411 ) );
			self::assertSame( WorkRepository::STATUS_ACKED, $this->wpdb->work_row['status'] ?? null );
			self::assertGreaterThan( 0, $this->event_count( 'live_write' ) );
			self::assertStringNotContainsString( "'oferta'", implode( "\n", $this->wpdb->queries ) );
		}

		/** @testdox Un regular variable false equivale a cero después de invalidar su fuente. */
		public function test_variable_false_regular_price_indexes_as_zero(): void {
			$this->seed_live( 7412 );
			$this->source_type    = 'product';
			$this->source_status  = 'publish';
			$this->source_product = new class( 7412 ) extends \WC_Product {
				public function is_type( string $type ): bool {
					return 'variable' === $type;
				}

				public function get_variation_regular_price( string $mode = 'min' ) {
					unset( $mode );
					return false;
				}
			};

			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->process_live_product( 7412 ) );
			self::assertSame( WorkRepository::STATUS_ACKED, $this->wpdb->work_row['status'] ?? null );
			self::assertGreaterThan( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 2, $this->event_count( 'variable_price_transient_delete' ) );
			self::assertStringNotContainsString( "'oferta'", implode( "\n", $this->wpdb->queries ) );
		}

		/**
		 * @dataProvider invalidPriceSourcesProvider
		 * @testdox Precio base, regular o efectivo inválido conserva deuda sin write ni ACK ($source: $kind)
		 * @param mixed $value Valor inválido.
		 */
		public function test_invalid_price_sources_never_write_or_ack( string $source, string $kind, $value ): void {
			$product_id = 7420;
			$this->seed_live( $product_id );
			$this->source_type   = 'product';
			$this->source_status = 'publish';

			if ( 'price' === $source ) {
				$this->source_product = new class( $product_id, $value ) extends \WC_Product {
					/** @var mixed */
					private $value;

					public function __construct( int $id, $value ) {
						parent::__construct( $id );
						$this->value = $value;
					}

					public function get_price() {
						return $this->value;
					}
				};
			} elseif ( 'regular' === $source ) {
				$this->source_product = new class( $product_id, $value ) extends \WC_Product {
					/** @var mixed */
					private $value;

					public function __construct( int $id, $value ) {
						parent::__construct( $id );
						$this->value = $value;
					}

					public function get_regular_price( string $context = 'view' ) {
						unset( $context );
						return $this->value;
					}
				};
			} else {
				$this->source_product              = new \WC_Product( $product_id );
				$this->effective_price_override    = $value;
				$this->has_effective_price_override = true;
			}

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( $product_id ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
			self::assertSame( WorkRepository::STATUS_PENDING, $this->wpdb->work_row['status'] ?? null );
		}

		/** @return array<string, array{0: string, 1: string, 2: mixed}> */
		public static function invalidPriceSourcesProvider(): array {
			$cases = array();
			foreach (
				array(
					'nonnumeric' => 'not-a-price',
					'negative'   => -1,
					'nonfinite'  => INF,
				) as $kind => $value
			) {
				foreach ( array( 'price', 'regular', 'effective' ) as $source ) {
					$cases[ $source . '-' . $kind ] = array( $source, $kind, $value );
				}
			}
			return $cases;
		}

		/**
		 * @testdox Un WP_Error de términos falla el claim y jamás se convierte en taxonomía vacía.
		 */
		public function test_builder_term_wp_error_never_writes_or_acks(): void {
			$this->seed_live( 742 );
			$this->source_type     = 'product';
			$this->source_status   = 'publish';
			$this->source_product  = new \WC_Product( 742 );
			$this->builder_terms_by_taxonomy['pa_genero'] = new \WP_Error();

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 742 ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
		}

		/**
		 * @testdox Un ancestro WP_Error falla el claim en vez de omitir una faceta materializada.
		 */
		public function test_builder_ancestor_wp_error_never_writes_or_acks(): void {
			$this->seed_live( 743 );
			$this->source_type     = 'product';
			$this->source_status   = 'publish';
			$this->source_product  = new \WC_Product( 743 );
			$this->builder_terms_by_taxonomy['product_cat'] = array(
				new \WP_Term( 'product_cat', 55, 44, 'shonen', 'Shonen' ),
			);
			$this->ancestor_ids       = array( 44 );
			$this->ancestor_terms[44] = new \WP_Error();

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 743 ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
		}

		/**
		 * @testdox Una variación hija ilegible falla el documento variable y conserva retry durable.
		 */
		public function test_unreadable_variation_sku_child_never_writes_or_acks(): void {
			$this->seed_live( 744 );
			$this->source_type   = 'product';
			$this->source_status = 'publish';
			$this->source_product = new class( 744 ) extends \WC_Product {
				public function is_type( string $type ): bool {
					return 'variable' === $type;
				}
				/** @return array<int, int> */
				public function get_children(): array {
					return array( 999999 );
				}
			};
			$this->authoritative_variation_ids = array( 9744 );
			$this->product_sources[9744] = null;

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 744 ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
		}

		/** @testdox La primera variación con ID distinto corta antes de tocar el segundo hijo. */
		public function test_variation_validation_stops_immediately_on_wrong_first_identity(): void {
			$authority = new ProductSourceAuthority( $this->wpdb );
			$first     = new \WC_Product( 999 );
			$second    = new class( 902 ) extends \WC_Product {
				public function get_id(): int {
					throw new \RuntimeException( 'second variation must not be read' );
				}
			};
			$validator = new \ReflectionMethod( ProductSourceAuthority::class, 'validated_variation_products' );
			$validator->setAccessible( true );

			self::assertSame( array(), $validator->invoke( $authority, array( $first, $second ), 744, 50, array( 901, 902 ) ) );
			self::assertFalse( $authority->is_available() );
			self::assertStringContainsString( 'identity disagrees', $authority->last_error() );
		}

		/**
		 * @testdox Una variación hija verificable enriquece el padre sin bloquear el claim.
		 */
		public function test_readable_variation_sku_child_indexes_parent(): void {
			$this->seed_live( 7441 );
			$this->source_type   = 'product';
			$this->source_status = 'publish';
			$this->source_product = new class( 7441 ) extends \WC_Product {
				public function is_type( string $type ): bool {
					return 'variable' === $type;
				}
				public function get_price(): string {
					$GLOBALS['wpdb']->events[] = 'parent_price';
					return parent::get_price();
				}
				public function get_regular_price( string $context = 'view' ): string {
					$GLOBALS['wpdb']->events[] = 'parent_regular_price';
					return parent::get_regular_price( $context );
				}
				public function get_price_html(): string {
					$GLOBALS['wpdb']->events[] = 'parent_price_html';
					return parent::get_price_html();
				}
				/** @return array<int, int> */
				public function get_children(): array {
					return array( 999998 );
				}
			};
			$this->authoritative_variation_ids = array( 97441 );
			$this->product_sources[97441] = new class( 97441 ) extends \WC_Product {
				public function is_type( string $type ): bool {
					return 'variation' === $type;
				}
				public function get_parent_id(): int {
					return 7441;
				}
				public function get_sku(): string {
					return '978-956-123';
				}
			};

			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->process_live_product( 7441 ) );
			self::assertSame( WorkRepository::STATUS_ACKED, $this->wpdb->work_row['status'] ?? null );
			self::assertGreaterThan( 0, $this->event_count( 'live_write' ) );
			$delete = array_search( 'variable_price_transient_delete', $this->wpdb->events, true );
			self::assertIsInt( $delete );
			self::assertLessThan( array_search( 'parent_price', $this->wpdb->events, true ), $delete );
			self::assertLessThan( array_search( 'parent_regular_price', $this->wpdb->events, true ), $delete );
			self::assertLessThan( array_search( 'parent_price_html', $this->wpdb->events, true ), $delete );
			self::assertSame( 2, $this->event_count( 'variable_price_transient_delete' ), 'Cada transient acotado se elimina una vez; Builder y variaciones comparten la preparación.' );
		}

		/** @testdox El hijo publish 51 no se omite: exceder el cap conserva retry sin documento parcial. */
		public function test_variation_source_overflow_fails_instead_of_silently_truncating(): void {
			$this->seed_live( 7442 );
			$this->source_type = 'product';
			$this->source_status = 'publish';
			$this->source_product = new class( 7442 ) extends \WC_Product {
				public function is_type( string $type ): bool {
					return 'variable' === $type;
				}
			};
			$this->authoritative_variation_ids = range( 98001, 98051 );
			foreach ( $this->authoritative_variation_ids as $variation_id ) {
				$this->product_sources[ $variation_id ] = new class( $variation_id ) extends \WC_Product {
					public function is_type( string $type ): bool {
						return 'variation' === $type;
					}
					public function get_parent_id(): int {
						return 7442;
					}
				};
			}

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 7442 ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
		}

		/** @testdox Un transient viejo de precios variables que no se borra conserva retry. */
		public function test_variable_price_transient_noop_fails_before_partial_document(): void {
			$this->seed_live( 7443 );
			$this->source_type = 'product';
			$this->source_status = 'publish';
			$this->source_product = new class( 7443 ) extends \WC_Product {
				public function is_type( string $type ): bool {
					return 'variable' === $type;
				}
			};
			$this->variable_price_transients['wc_var_prices_7443'] = array( 'snapshot-a' );
			$this->variable_transient_delete_noop = true;

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 7443 ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
		}

		/**
		 * @testdox Una faceta de 192 bytes se rechaza antes de cualquier DELETE o INSERT.
		 */
		public function test_oversized_facet_value_is_rejected_before_writes(): void {
			$this->seed_live( 745 );
			$this->source_type     = 'product';
			$this->source_status   = 'publish';
			$this->source_product  = new \WC_Product( 745 );
			$this->builder_terms_by_taxonomy['pa_genero'] = array(
				new \WP_Term( 'pa_genero', 65, 0, str_repeat( 'a', 192 ), 'Género futuro' ),
			);

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 745 ) );
			self::assertSame( 0, $this->event_count( 'live_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
		}

		/**
		 * @testdox Una salida incompleta del builder se rechaza antes de persistir.
		 */
		public function test_invalid_builder_shape_is_rejected(): void {
			$validator = new \ReflectionMethod( Indexer::class, 'valid_built_document' );
			$validator->setAccessible( true );

			self::assertFalse( (bool) $validator->invoke( $this->sut, 742, array( 'doc' => array( 'post_id' => 742 ) ) ) );

			$built = $this->valid_document( 742 );
			$built['facets'][] = array(
				'facet'     => 'genero',
				'post_id'   => 999,
				'value'     => 'accion',
				'label'     => 'Acción',
				'value_num' => null,
				'term_id'   => 5,
				'parent_id' => 0,
			);
			self::assertFalse( (bool) $validator->invoke( $this->sut, 742, $built ) );
		}

		/**
		 * @testdox El validador refleja límites byte, tipos y rangos de las tres tablas.
		 */
		public function test_builder_validator_rejects_schema_coercions_and_overflows(): void {
			$validator = new \ReflectionMethod( Indexer::class, 'valid_built_document' );
			$validator->setAccessible( true );
			$valid = $this->valid_document( 746 );

			self::assertTrue( (bool) $validator->invoke( $this->sut, 746, $valid ) );

			$oversized_title                 = $valid;
			$oversized_title['doc']['title'] = str_repeat( 'x', 65536 );
			self::assertFalse( (bool) $validator->invoke( $this->sut, 746, $oversized_title ) );

			$fractional_overflow                           = $valid;
			$fractional_overflow['doc']['price_effective'] = 10.001;
			self::assertFalse( (bool) $validator->invoke( $this->sut, 746, $fractional_overflow ) );

			$invalid_token           = $valid;
			$invalid_token['tokens'] = array(
				array( 'token' => str_repeat( 'x', 41 ), 'field' => DocumentBuilder::FIELD_TITLE ),
			);
			self::assertFalse( (bool) $validator->invoke( $this->sut, 746, $invalid_token ) );

			$invalid_facet           = $valid;
			$invalid_facet['facets'] = array(
				array(
					'facet'     => 'genero',
					'post_id'   => 746,
					'value'     => 'accion',
					'label'     => str_repeat( 'x', 192 ),
					'value_num' => null,
					'term_id'   => 5,
					'parent_id' => 0,
				),
			);
			self::assertFalse( (bool) $validator->invoke( $this->sut, 746, $invalid_facet ) );

			$facet_boundary           = $valid;
			$facet_boundary['facets'] = array(
				array(
					'facet'     => 'genero',
					'post_id'   => 746,
					'value'     => str_repeat( 'x', 191 ),
					'label'     => 'Límite válido',
					'value_num' => null,
					'term_id'   => 5,
					'parent_id' => 0,
				),
			);
			self::assertTrue( (bool) $validator->invoke( $this->sut, 746, $facet_boundary ) );
		}

		/**
		 * @testdox Dirty n mas uno conserva el claim n y queda pending tras su ACK.
		 */
		public function test_dirty_next_revision_remains_pending(): void {
			$this->seed_live( 75 );
			$this->dirty_during_source = true;

			self::assertSame( WorkRepository::OUTCOME_ABSENT, $this->sut->process_live_product( 75 ) );
			self::assertSame( 2, $this->wpdb->work_row['wanted_seq'] ?? null );
			self::assertSame( 1, $this->wpdb->work_row['acked_seq'] ?? null );
			self::assertSame( WorkRepository::STATUS_PENDING, $this->wpdb->work_row['status'] ?? null );
			self::assertContains( Indexer::HOOK_PUMP, $this->wake_hooks );
		}

		/**
		 * @testdox Publicación fallida conserva filas comprometidas y claim reintentable sin ACK.
		 */
		public function test_generation_bump_failure_rolls_back_live_work(): void {
			$this->seed_live( 76 );
			$this->wpdb->indexed_products = array( 76 ); // La baja retira filas: hay que publicarla.
			$this->persist_generation = false;

			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_live_product( 76 ) );
			self::assertSame( WorkRepository::STATUS_PENDING, $this->wpdb->work_row['status'] ?? null );
			self::assertSame( 1, $this->wpdb->work_row['wanted_seq'] ?? null );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
			self::assertSame( 2, $this->event_count( 'commit' ), 'Claim corto y TX1 de filas deben quedar comprometidos.' );
		}

		/**
		 * @testdox La fachada CLI distingue indexed, absent, pending y failed.
		 */
		public function test_index_product_outcome_exposes_tri_state(): void {
			$this->source_type    = 'product';
			$this->source_status  = 'publish';
			$this->source_product = new \WC_Product( 761 );

			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->index_product_outcome( 761 ) );
			$this->source_product = new \WC_Product( 762 );
			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->index_product_outcome( 762 ) );

			$this->source_type    = 'post';
			$this->source_status  = 'trash';
			$this->source_product = null;
			self::assertSame( WorkRepository::OUTCOME_ABSENT, $this->sut->index_product_outcome( 763 ) );
			self::assertSame( WorkRepository::OUTCOME_ABSENT, $this->sut->index_product_outcome( 764 ) );

			$this->wpdb->work_row = $this->wpdb->row(
				WorkRepository::SCOPE_LIVE,
				'',
				765,
				WorkRepository::STATUS_LEASED
			);
			self::assertSame( Indexer::RESULT_PENDING, $this->sut->index_product_outcome( 765 ) );
			self::assertSame( Indexer::RESULT_FAILED, $this->sut->index_product_outcome( 0 ) );
			self::assertContains( Indexer::HOOK_PUMP, $this->wake_hooks );
		}

		/**
		 * @testdox Enqueue cero deja la deuda durable y no registra shutdown.
		 */
		public function test_enqueue_zero_keeps_debt_without_shutdown_fallback(): void {
			$this->as_action_id = 0;

			$this->sut->enqueue_product( 77 );

			self::assertSame( array( 'dirty', 'wake' ), $this->wpdb->events );
			self::assertSame( WorkRepository::STATUS_PENDING, $this->wpdb->work_row['status'] ?? null );
			self::assertSame( array( Indexer::HOOK_PUMP ), $this->wake_hooks );
			self::assertFalse( $this->sut->wake_live_work(), 'Action id zero must be observable as a failed wake-up.' );
		}

		/**
		 * @testdox La accion legacy vuelve a crear deuda y despierta el pump nuevo.
		 */
		public function test_legacy_reindex_action_is_a_durable_adapter(): void {
			$this->sut->handle_reindex( 78 );

			self::assertSame( array( 'dirty', 'wake' ), $this->wpdb->events );
			self::assertSame( 78, $this->wpdb->work_row['product_id'] ?? null );
			self::assertSame( array( Indexer::HOOK_PUMP ), $this->wake_hooks );
		}

		/**
		 * @testdox Force delete usa el snapshot post y no pierde la baja durable.
		 */
		public function test_deleted_post_snapshot_preserves_force_delete(): void {
			// Core todavía puede devolver el objeto cacheado como publish en este
			// hook. La ruta known-absent no debe consultar esa fuente obsoleta.
			$this->source_type       = 'product';
			$this->source_status     = 'publish';
			$this->source_product    = new \WC_Product( 79 );
			$this->source_read_error = true;
			$this->wpdb->indexed_products = array( 79 ); // Estaba indexado: los tres DELETE tienen algo que retirar.
			$this->sut->on_deleted_post( 79, new \WP_Post( 'product', 0, 'publish', 79 ) );

			self::assertSame( 79, $this->wpdb->work_row['product_id'] ?? null );
			self::assertSame( WorkRepository::STATUS_ACKED, $this->wpdb->work_row['status'] ?? null );
			self::assertSame( WorkRepository::OUTCOME_ABSENT, $this->wpdb->work_row['outcome'] ?? null );
			self::assertSame( 3, $this->event_count( 'live_write' ), 'All three live index tables must delete the product.' );
		}

		/**
		 * @testdox Papelera escucha trashed_post y aplica absent después de persistir status trash.
		 */
		public function test_trash_listener_runs_after_status_change_and_applies_absence(): void {
			$this->sut->register_listeners();
			$hooks = array_column( $this->registered_actions, 'hook' );

			self::assertContains( 'trashed_post', $hooks );
			self::assertContains( 'woocommerce_new_product', $hooks );
			self::assertContains( 'woocommerce_update_product', $hooks );
			self::assertContains( 'woocommerce_new_product_variation', $hooks );
			self::assertContains( 'woocommerce_update_product_variation', $hooks );
			self::assertContains( 'woocommerce_before_product_object_save', $hooks );
			self::assertContains( 'untrashed_post', $hooks );
			self::assertContains( 'edit_terms', $hooks );
			self::assertContains( 'before_delete_post', $hooks );
			self::assertContains( 'wp_trash_post', $hooks );
			self::assertContains( 'untrash_post', $hooks );
			$trash_action = array_values(
				array_filter(
					$this->registered_actions,
					static fn( array $action ): bool => 'trashed_post' === $action['hook']
				)
			)[0] ?? array();
			self::assertSame( 2, $trash_action['accepted_args'] ?? null );

			$this->source_type   = 'product';
			$this->source_status = 'trash';
			$this->wpdb->indexed_products = array( 80 ); // Estaba indexado: la papelera retira filas vivas.
			$this->sut->on_deleted_post( 80, 'publish' );

			self::assertSame( WorkRepository::OUTCOME_ABSENT, $this->wpdb->work_row['outcome'] ?? null );
			self::assertSame( WorkRepository::STATUS_ACKED, $this->wpdb->work_row['status'] ?? null );
			self::assertSame( 3, $this->event_count( 'live_write' ) );
		}

		/**
		 * @testdox Borrar o enviar a papelera una variación deja deuda durable del padre.
		 */
		public function test_variation_delete_and_trash_enqueue_parent_after_state_change(): void {
			$this->sut->on_deleted_post( 9101, new \WP_Post( 'product_variation', 811, 'publish', 9101 ) );
			$this->source_type      = 'product_variation';
			$this->source_parent_id = 812;
			$this->sut->on_deleted_post( 9102, 'publish' );

			self::assertContains( 811, $this->wpdb->durable_products );
			self::assertContains( 812, $this->wpdb->durable_products );
			self::assertSame( 2, $this->event_count( 'dirty' ) );
		}

		/** @testdox Un snapshot de delete con ID distinto no autoriza baja ni deuda del post equivocado. */
		public function test_deleted_post_rejects_mismatched_snapshot_identity(): void {
			$this->sut->on_deleted_post( 9120, new \WP_Post( 'product', 0, 'publish', 9999 ) );

			self::assertSame( array(), $this->wpdb->durable_products );
			self::assertSame( 0, $this->event_count( 'live_delete' ) );
			self::assertSame( 'post_state_source_unreadable', Install::pending_rebuild_request()['reason'] ?? null );
		}

		/**
		 * @testdox Pre delete trash restore conserva deuda aun sin alcanzar el hook post.
		 */
		public function test_pre_post_state_change_persists_deferred_product_or_parent_debt(): void {
			$this->sut->on_before_post_state_change( 821, new \WP_Post( 'product', 0, 'publish', 821 ) );
			$this->sut->on_before_post_state_change( 9922, new \WP_Post( 'product_variation', 822, 'publish', 9922 ) );

			self::assertContains( 821, $this->wpdb->durable_products );
			self::assertContains( 822, $this->wpdb->durable_products );
			self::assertSame( 2, $this->event_count( 'dirty' ) );
			self::assertSame( 1, $this->event_count( 'wake_delayed' ), 'La misma request coalesce sólo su wake futuro.' );
		}

		/**
		 * @testdox Restaurar producto o variación reabre deuda del documento correcto.
		 */
		public function test_untrash_enqueues_product_or_variation_parent(): void {
			$this->source_type = 'product';
			$this->sut->on_untrashed_post( 813, 'trash' );

			$this->source_type      = 'product_variation';
			$this->source_parent_id = 814;
			$this->sut->on_untrashed_post( 9103, 'trash' );

			self::assertContains( 813, $this->wpdb->durable_products );
			self::assertContains( 814, $this->wpdb->durable_products );
			self::assertSame( 2, $this->event_count( 'dirty' ) );
		}

		/**
		 * @testdox Los hooks WC post-persistencia materializan producto y padre de variación.
		 */
		public function test_woocommerce_post_persistence_hooks_enqueue_final_source_state(): void {
			$this->sut->on_product_persisted( 801, new \WC_Product( 801 ) );
			$this->source_type      = 'product_variation';
			$this->source_parent_id = 802;
			$variation = new class() {
				public function get_parent_id(): int {
					return 999;
				}
			};
			$this->sut->on_variation_persisted( 9001, $variation );

			self::assertContains( 801, $this->wpdb->durable_products );
			self::assertContains( 802, $this->wpdb->durable_products );
			self::assertSame( 2, $this->event_count( 'dirty' ) );
			self::assertSame( array( Indexer::HOOK_PUMP, Indexer::HOOK_PUMP ), $this->wake_hooks );
		}

		/**
		 * @testdox La relación de término deja deuda y wake diferidos antes del write fuente.
		 */
		public function test_pre_term_relationship_hook_survives_gap_before_post_hook(): void {
			$this->source_type = 'product';

			$this->sut->on_pre_term_relationship_change( 815, 77, 'product_cat' );

			self::assertContains( 815, $this->wpdb->durable_products );
			self::assertSame( 1, $this->event_count( 'dirty' ) );
			self::assertSame( 1, $this->event_count( 'wake_delayed' ) );
			self::assertSame( array( Indexer::HOOK_PUMP ), $this->wake_hooks );
			$dirty_sql = (string) current(
				array_filter( $this->wpdb->queries, static fn( string $sql ): bool => false !== strpos( $sql, 'INSERT INTO `wp_akb_search_work`' ) )
			);
			self::assertStringContainsString( 'INTERVAL \'10\' SECOND', $dirty_sql );
		}

		/** @testdox Los listeners no pierden deuda cuando get_post_type conserva un no-producto stale. */
		public function test_listener_sources_bypass_stale_post_type_cache(): void {
			$this->source_type       = 'product';
			$this->cached_source_type = 'post';
			$this->direct_children   = array();

			$this->sut->on_pre_term_relationship_change( 825, 77, 'product_cat' );
			$this->sut->on_set_object_terms( 826, array(), array(), 'product_cat' );
			$this->sut->on_before_post_state_change( 827, 'publish' );
			$this->sut->on_untrashed_post( 828, 'trash' );
			$this->sut->on_pre_delete_term( 74, 'product_cat' );
			$this->sut->on_delete_term( 74, 0, 'product_cat', null, array( 829 ) );

			foreach ( array( 825, 826, 827, 828, 829 ) as $product_id ) {
				self::assertContains( $product_id, $this->wpdb->durable_products );
			}
			self::assertNotEmpty(
				array_filter( $this->wpdb->queries, static fn( string $sql ): bool => false !== strpos( $sql, 'FROM wp_posts' ) )
			);
		}

		/**
		 * @testdox Una fuente ilegible en pre relationship deja rebuild durable y no desaparece en silencio.
		 */
		public function test_pre_term_relationship_unreadable_source_requests_rebuild(): void {
			$this->source_type       = 'product';
			$this->source_read_error = true;

			$this->sut->on_pre_term_relationship_change( 815, 77, 'product_cat' );

			self::assertSame( array(), $this->wpdb->durable_products );
			$request = Install::pending_rebuild_request();
			self::assertSame( 'term_relationship_source_unreadable', $request['reason'] ?? null );
		}

		/**
		 * @testdox Stock de variación con padre ilegible deja rebuild durable.
		 */
		public function test_variation_stock_unreadable_parent_requests_rebuild(): void {
			$this->source_product = null;

			$this->sut->on_variation_stock_status( 9815 );

			self::assertSame( array(), $this->wpdb->durable_products );
			$request = Install::pending_rebuild_request();
			self::assertSame( 'variation_parent_unreadable', $request['reason'] ?? null );
		}

		/**
		 * @testdox WC pre-save deja barrera del producto antes de precio SKU y meta.
		 */
		public function test_woocommerce_pre_save_persists_deferred_product_debt(): void {
			$this->sut->on_before_product_persisted( new \WC_Product( 816 ) );

			self::assertContains( 816, $this->wpdb->durable_products );
			self::assertSame( 1, $this->event_count( 'dirty' ) );
			self::assertSame( 1, $this->event_count( 'wake_delayed' ) );
		}

		/**
		 * @testdox WC pre-save de variación protege el documento padre.
		 */
		public function test_woocommerce_pre_save_variation_targets_parent(): void {
			$variation = new class() {
				public function get_id(): int {
					return 9917;
				}
				public function get_parent_id(): int {
					return 817;
				}
				public function is_type( string $type ): bool {
					return 'variation' === $type;
				}
			};

			$this->sut->on_before_product_persisted( $variation );

			self::assertContains( 817, $this->wpdb->durable_products );
			self::assertNotContains( 9917, $this->wpdb->durable_products );
		}

		/**
		 * @testdox Reasignar variación protege al padre persistido viejo y al nuevo.
		 */
		public function test_woocommerce_pre_save_variation_targets_old_and_new_parent(): void {
			$this->source_type      = 'product_variation';
			$this->source_parent_id = 818;
			$variation = new class() {
				public function get_id(): int {
					return 9918;
				}
				public function get_parent_id(): int {
					return 819;
				}
				public function is_type( string $type ): bool {
					return 'variation' === $type;
				}
			};

			$this->sut->on_before_product_persisted( $variation );

			self::assertSame( array( 818, 819 ), $this->wpdb->durable_products );
			self::assertSame( 1, $this->event_count( 'dirty' ) );
			self::assertSame( 1, $this->event_count( 'wake_delayed' ) );
		}

		/**
		 * @testdox Padre viejo ilegible conserva deuda del padre nuevo y solicita rebuild explícito.
		 */
		public function test_woocommerce_pre_save_variation_unreadable_old_parent_keeps_new_parent_debt(): void {
			$this->source_parent_id         = 0;
			$this->source_read_error        = true;
			$variation = new class() {
				public function get_id(): int {
					return 9919;
				}
				public function get_parent_id(): int {
					return 820;
				}
				public function is_type( string $type ): bool {
					return 'variation' === $type;
				}
			};

			$this->sut->on_before_product_persisted( $variation );

			self::assertSame( array( 820 ), $this->wpdb->durable_products );
			self::assertSame( 1, $this->event_count( 'dirty' ) );
			$request = Install::pending_rebuild_request();
			self::assertSame( 'variation_old_parent_unreadable', $request['reason'] ?? null );
		}

		/**
		 * @testdox Ofertas programadas materializan el lote completo con un solo wake-up.
		 */
		public function test_scheduled_sales_use_batch_debt_and_single_wakeup(): void {
			$this->sut->on_scheduled_sales( array( '804', 803, 804, 0 ) );

			self::assertSame( array( 803, 804 ), $this->wpdb->durable_products );
			self::assertSame( 1, $this->event_count( 'dirty' ) );
			self::assertSame( array( Indexer::HOOK_PUMP ), $this->wake_hooks );
		}

		/**
		 * @testdox Un evento de termino persiste todos sus productos antes de un unico wake fallido.
		 */
		public function test_term_event_materializes_all_product_debt_before_zero_wakeup(): void {
			$this->term_ids    = range( 801, 902 );
			$this->as_action_id = 0;

			$this->sut->on_edited_term( 44, 0, 'product_cat' );

			self::assertSame( $this->term_ids, $this->wpdb->durable_products );
			self::assertSame( 2, $this->event_count( 'dirty' ) );
			self::assertSame( array( Indexer::HOOK_PUMP ), $this->wake_hooks );
			self::assertSame( WorkRepository::STATUS_PENDING, $this->wpdb->work_row['status'] ?? null );
			self::assertCount( 1, $this->get_posts_args );
			self::assertSame( -1, $this->get_posts_args[0]['posts_per_page'] ?? null );
			self::assertArrayNotHasKey( 'paged', $this->get_posts_args[0] );
		}

		/**
		 * @testdox Un lote de termino fallido no corta el siguiente y queda en AuditLog.
		 */
		public function test_term_partial_failure_continues_and_is_audited(): void {
			$this->term_ids                   = range( 1201, 1402 );
			$this->wpdb->fail_dirty_products = array( 1350 );
			$this->as_action_id              = 0;

			$this->sut->on_edited_term( 45, 0, 'product_cat' );

			self::assertSame(
				array_merge( range( 1201, 1300 ), range( 1401, 1402 ) ),
				$this->wpdb->durable_products
			);
			self::assertSame( 3, $this->event_count( 'dirty' ) );
			$audit = $this->options[ AuditLog::OPTION_KEY ][0] ?? array();
			self::assertSame( 'index_term_debt_failed', $audit['action'] ?? null );
			self::assertStringContainsString( 'reason=work_persist_failed', (string) ( $audit['detail'] ?? '' ) );
			self::assertStringContainsString( 'ids=1301,1302', (string) ( $audit['detail'] ?? '' ) );
			self::assertSame( array( Indexer::HOOK_PUMP ), $this->wake_hooks );
			self::assertCount( 1, $this->get_posts_args );
		}

		/**
		 * Hallazgo S-95894772: `enqueue_term()` pedía una reconstrucción ENTERA
		 * antes de repartir la deuda por producto. Guardar una editorial sin tocar
		 * nombre, slug ni padre —para cambiar una term-meta— reconstruía el índice
		 * aunque el filtro de `edit_terms` dijera que no hacía falta.
		 *
		 * @testdox Un reparto de término completo no pide reconstruir el índice entero.
		 */
		public function test_a_complete_term_fanout_does_not_request_a_full_rebuild(): void {
			$this->term_ids     = range( 801, 810 );
			$this->as_action_id = 0;

			$this->sut->on_edited_term( 44, 0, 'product_cat' );

			self::assertSame( $this->term_ids, $this->wpdb->durable_products, 'Cada producto queda con su deuda durable.' );
			self::assertNull( Install::pending_rebuild_request(), 'La deuda por producto basta: sin reconstrucción completa.' );
		}

		/**
		 * `edit_terms` ya decide si la edición toca nombre, slug o padre —las tres
		 * cosas del término que el índice usa—. Cuando no toca ninguna, repartir
		 * deuda a todos sus productos los reindexa idénticos: en una editorial
		 * grande son cientos de documentos por pulsar «Actualizar».
		 *
		 * @testdox Guardar un término sin tocar nombre, slug ni padre no reparte deuda.
		 */
		public function test_an_inert_term_edit_does_not_fan_out(): void {
			$this->term_ids = range( 801, 810 );

			$this->sut->on_pre_edit_term( 44, 'product_cat', array( 'name' => 'Manga', 'slug' => 'manga', 'parent' => 0 ) );
			$this->sut->on_edited_term( 44, 0, 'product_cat' );

			self::assertSame( array(), $this->wpdb->durable_products, 'Ningún producto queda marcado por una edición que no cambia nada del índice.' );
			self::assertNull( Install::pending_rebuild_request(), 'Ni reconstrucción: `edit_terms` ya dijo que no hacía falta.' );
			self::assertSame( array(), $this->get_posts_args, 'Tampoco se consulta el subárbol.' );
		}

		/** @testdox Cambiar el nombre del término sigue repartiendo la deuda de todos sus productos. */
		public function test_a_renamed_term_still_fans_out(): void {
			$this->term_ids = range( 801, 810 );

			$this->sut->on_pre_edit_term( 44, 'product_cat', array( 'name' => 'Manga renombrado', 'slug' => 'manga', 'parent' => 0 ) );
			$this->sut->on_edited_term( 44, 0, 'product_cat' );

			self::assertSame( $this->term_ids, $this->wpdb->durable_products );
			self::assertSame( 'term_edit', Install::pending_rebuild_request()['reason'] ?? null, 'La red previa al UPDATE se conserva.' );
		}

		/**
		 * @testdox Una edición sin decisión previa (otra vía) se reparte como siempre.
		 *
		 * Fail-safe: el memo sólo puede AHORRAR trabajo cuando `edit_terms` corrió
		 * y dijo que no hacía falta; sin él, el reparto es el de siempre.
		 */
		public function test_a_term_edit_without_a_previous_verdict_still_fans_out(): void {
			$this->term_ids = range( 801, 810 );

			$this->sut->on_edited_term( 44, 0, 'product_cat' );

			self::assertSame( $this->term_ids, $this->wpdb->durable_products );
		}

		/**
		 * @testdox Si la deuda de algún producto no se pudo guardar, la reconstrucción es la red.
		 */
		public function test_a_term_fanout_that_loses_debt_requests_the_rebuild(): void {
			$this->term_ids                  = range( 1201, 1210 );
			$this->wpdb->fail_dirty_products = array( 1205 );
			$this->as_action_id              = 0;

			$this->sut->on_edited_term( 45, 0, 'product_cat' );

			self::assertSame( 'term_fanout', Install::pending_rebuild_request()['reason'] ?? null );
		}

		/**
		 * @testdox Borrar un término consulta una vez todos los subárboles de sus hijos directos.
		 */
		public function test_term_delete_uses_one_fanout_query_for_all_direct_child_subtrees(): void {
			$this->source_type    = 'product';
			$this->direct_children = array( 51, 52 );
			$this->term_ids       = array( 1601, 1602 );

			$this->sut->on_pre_delete_term( 50, 'product_cat' );
			$this->sut->on_delete_term( 50, 0, 'product_cat', null, array( 1603 ) );

			self::assertCount( 1, $this->get_terms_args );
			self::assertSame( 50, $this->get_terms_args[0]['parent'] ?? null );
			self::assertSame( 'ids', $this->get_terms_args[0]['fields'] ?? null );
			self::assertCount( 1, $this->get_posts_args, 'Todos los hijos comparten una sola tax_query.' );
			self::assertSame( array( 51, 52 ), $this->get_posts_args[0]['tax_query'][0]['terms'] ?? null );
			self::assertTrue( $this->get_posts_args[0]['tax_query'][0]['include_children'] ?? false );
			self::assertSame( array( 1601, 1602, 1603 ), $this->wpdb->durable_products );
			self::assertSame( 1, $this->event_count( 'dirty' ) );
			self::assertSame( array( Indexer::HOOK_PUMP ), $this->wake_hooks );
		}

		/**
		 * @testdox Un WP_Error al capturar hijos conserva directos y deja deuda observable.
		 */
		public function test_term_delete_children_wp_error_keeps_direct_ids_and_rebuild_debt(): void {
			$this->source_type     = 'product';
			$this->direct_children = new \WP_Error();

			$this->sut->on_pre_delete_term( 60, 'product_cat' );
			$this->sut->on_delete_term( 60, 0, 'product_cat', null, array( 1701 ) );

			self::assertCount( 0, $this->get_posts_args );
			self::assertSame( array( 1701 ), $this->wpdb->durable_products );
			$details = array_column( $this->options[ AuditLog::OPTION_KEY ] ?? array(), 'detail' );
			self::assertNotEmpty(
				array_filter( $details, static fn( string $detail ): bool => false !== strpos( $detail, 'child_terms_query_failed' ) )
			);
			self::assertSame( 'term_delete', Install::pending_rebuild_request()['reason'] ?? null );
		}

		/**
		 * @testdox Un error en la única consulta de fanout conserva directos y solicita rebuild.
		 */
		public function test_term_delete_fanout_query_error_keeps_direct_ids_and_rebuild_debt(): void {
			$this->source_type     = 'product';
			$this->direct_children = array( 71, 72 );
			$this->term_query_error = true;

			$this->sut->on_pre_delete_term( 70, 'product_cat' );
			$this->sut->on_delete_term( 70, 0, 'product_cat', null, array( 1801 ) );

			self::assertCount( 1, $this->get_posts_args );
			self::assertSame( array( 1801 ), $this->wpdb->durable_products );
			$details = array_column( $this->options[ AuditLog::OPTION_KEY ] ?? array(), 'detail' );
			self::assertNotEmpty(
				array_filter( $details, static fn( string $detail ): bool => false !== strpos( $detail, 'source_query_failed' ) )
			);
			self::assertNotNull( Install::pending_rebuild_request() );
		}

		/**
		 * @testdox Shadow diferencia indexed, absent y failed sin ACK del fallo.
		 */
		public function test_shadow_processing_is_explicitly_tri_state(): void {
			$this->wpdb->state_json = (string) json_encode(
				array(
					'protocol'               => 2,
					'run_id'                 => 'run-shadow',
					'phase'                  => RebuildJob::PHASE_BUILDING,
					'schema_seal'            => Install::schema_seal(),
					'shadow_schema_verified' => true,
				)
			);

			$indexed_claim       = $this->seed_shadow( 81 );
			$this->source_type   = 'product';
			$this->source_status = 'publish';
			$this->source_product = new \WC_Product( 81 );
			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->process_shadow_claim( $indexed_claim ) );

			$absent_claim         = $this->seed_shadow( 82 );
			$this->source_type    = 'post';
			$this->source_status  = 'trash';
			$this->source_product = null;
			self::assertSame( WorkRepository::OUTCOME_ABSENT, $this->sut->process_shadow_claim( $absent_claim ) );

			$failed_claim         = $this->seed_shadow( 83 );
			$this->source_type    = 'product';
			$this->source_status  = 'publish';
			$this->source_product = null;
			$writes_before        = $this->event_count( 'shadow_write' );
			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_shadow_claim( $failed_claim ) );
			self::assertSame( $writes_before, $this->event_count( 'shadow_write' ) );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
		}

		/**
		 * @testdox Un follow-up con gracia futura no detiene el claim de un run shadow activo.
		 */
		public function test_shadow_active_run_ignores_future_follow_up_grace(): void {
			$this->wpdb->state_json = (string) json_encode(
				array(
					'protocol'               => 2,
					'run_id'                 => 'run-shadow',
					'phase'                  => RebuildJob::PHASE_BUILDING,
					'schema_seal'            => Install::schema_seal(),
					'shadow_schema_verified' => true,
				)
			);
			$claim               = $this->seed_shadow( 84 );
			$this->source_type   = 'product';
			$this->source_status = 'publish';
			$this->source_product = new \WC_Product( 84 );

			self::assertTrue( Install::request_rebuild( 'editorial_materialization_pending', time() + 3600 ) );
			$follow_up = Install::pending_rebuild_request();
			self::assertNotNull( $follow_up );
			self::assertFalse( Install::runtime_ready(), 'La gracia del follow-up debe permanecer observable.' );
			self::assertSame( WorkRepository::OUTCOME_INDEXED, $this->sut->process_shadow_claim( $claim ) );
			self::assertSame( WorkRepository::STATUS_ACKED, $this->wpdb->work_row['status'] ?? null );
			self::assertSame( '', $this->wpdb->work_row['lease_token'] ?? null );
			self::assertNull( $this->wpdb->work_row['lease_until'] ?? null );
			self::assertSame( $follow_up, Install::pending_rebuild_request(), 'El claim activo no debe consumir el follow-up.' );
			self::assertFalse( Install::runtime_ready(), 'La gracia futura debe seguir bloqueando sólo el próximo run.' );
		}

		/**
		 * @testdox Un schema no listo devuelve el claim shadow a pending sin dejar un lease huérfano.
		 */
		public function test_shadow_schema_unready_releases_claim_for_retry(): void {
			$this->wpdb->state_json = (string) json_encode(
				array(
					'protocol'               => 2,
					'run_id'                 => 'run-shadow',
					'phase'                  => RebuildJob::PHASE_BUILDING,
					'schema_seal'            => Install::schema_seal(),
					'shadow_schema_verified' => true,
				)
			);
			$claim = $this->seed_shadow( 85 );
			unset( $this->options[ Install::OPTION_SCHEMA_SEAL ] );

			self::assertFalse( Install::runtime_schema_ready() );
			self::assertSame( Indexer::RESULT_FAILED, $this->sut->process_shadow_claim( $claim ) );
			self::assertSame( WorkRepository::STATUS_PENDING, $this->wpdb->work_row['status'] ?? null );
			self::assertSame( '', $this->wpdb->work_row['lease_token'] ?? null );
			self::assertSame( 0, $this->wpdb->work_row['lease_seq'] ?? null );
			self::assertNull( $this->wpdb->work_row['lease_until'] ?? null );
			self::assertSame( 0, $this->wpdb->work_row['acked_seq'] ?? null );
			self::assertSame( 'runtime_schema_unready', Install::pending_rebuild_request()['reason'] ?? null );
		}

		/**
		 * @testdox La fachada publica ya no acepta el bypass booleano shadow.
		 */
		public function test_public_api_has_no_shadow_boolean_bypass(): void {
			$constructor = new \ReflectionMethod( Indexer::class, '__construct' );
			$outcome     = new \ReflectionMethod( Indexer::class, 'index_product_outcome' );

			self::assertSame( 3, $constructor->getNumberOfRequiredParameters() );
			// `index_product()` (bool) no tenía llamador en producción: se retiró en 0.1.64.
			self::assertFalse( method_exists( Indexer::class, 'index_product' ) );
			// El único booleano de la fachada es la palanca de reparación de la CLI,
			// nunca un bypass shadow.
			self::assertSame(
				array( 'product_id', 'forzar_publicacion' ),
				array_map( static fn( \ReflectionParameter $parametro ): string => $parametro->getName(), $outcome->getParameters() )
			);
			self::assertSame( 1, $outcome->getNumberOfRequiredParameters() );
			self::assertTrue( $outcome->isPublic() );
			self::assertSame( 'string', (string) $outcome->getReturnType() );
			self::assertTrue( ( new \ReflectionMethod( Indexer::class, 'process_shadow_claim' ) )->isPublic() );
		}

		/**
		 * @testdox APIs publicas mark_batch_dirty y sync_products_batch_immediate permiten desacoplamiento entre plugins.
		 */
		public function test_mark_batch_dirty_and_sync_immediate_public_api(): void {
			self::assertTrue( method_exists( Indexer::class, 'mark_batch_dirty' ) );
			self::assertTrue( method_exists( Indexer::class, 'sync_products_batch_immediate' ) );

			$resultado_empty = $this->sut->mark_batch_dirty( array() );
			self::assertFalse( $resultado_empty );

			$resultado_sync_empty = $this->sut->sync_products_batch_immediate( array() );
			self::assertSame( 0, $resultado_sync_empty );
		}

		private function seed_live( int $product_id ): void {
			$this->wpdb->work_row = $this->wpdb->row(
				WorkRepository::SCOPE_LIVE,
				'',
				$product_id,
				WorkRepository::STATUS_PENDING
			);
		}

		/** @return array<string, mixed> */
		private function valid_document( int $product_id ): array {
			return array(
				'doc'    => array(
					'post_id'         => $product_id,
					'title'           => 'Producto',
					'title_norm'      => 'producto',
					'serie_norm'      => '',
					'autores_norm'    => '',
					'sku'             => '',
					'sku_norm'        => '',
					'tomo'            => null,
					'stock_rank'      => 0,
					'menu_order'      => 0,
					'price_effective' => 0.0,
					'visible'         => 1,
					'url'             => '',
					'thumb_url'       => '',
					'price_html'      => '',
					'indexed_at'      => '2026-08-06 00:00:00',
				),
				'tokens' => array(),
				'facets' => array(),
			);
		}

		/** @return array<string, mixed> */
		private function seed_shadow( int $product_id ): array {
			$this->wpdb->work_row = $this->wpdb->row(
				WorkRepository::SCOPE_BASELINE,
				'run-shadow',
				$product_id,
				WorkRepository::STATUS_LEASED
			);

			return $this->wpdb->work_row;
		}

		private function event_count( string $event ): int {
			return count( array_filter( $this->wpdb->events, static fn( string $value ): bool => $event === $value ) );
		}
	}
}
