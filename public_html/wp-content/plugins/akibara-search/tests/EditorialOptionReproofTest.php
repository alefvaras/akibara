<?php
/**
 * Regresión del BUG 2 de la ronda de QA en producción del 2026-09-02.
 *
 * Cambiar CUALQUIER option de descuento editorial (`akibara_taxonomy_discount_*`)
 * dejaba el buscador en 503 hasta que alguien corría un rebuild completo de
 * 100-160 s: `stage_global()` sube `state.wanted_seq`, el receipt se queda en la
 * revisión anterior, `materialization_matches()` falla y `serving_ready()`
 * devuelve false. `reprove_physical_receipt()` no reparaba nada porque salía por
 * su primera guarda en cuanto `state.wanted_seq !== receipt.wanted_seq`.
 *
 * Lo caro del caso medido: NINGÚN término tenía `_akb_taxonomy_discount_pct`, o
 * sea que la configuración vieja y la nueva materializan EXACTAMENTE los mismos
 * precios. El buscador se caía por un cambio que no movía un solo peso.
 *
 * Y con ese estado a medias, un `wp option delete` de esas mismas options
 * FATALEABA: `before_delete_option()` lanzaba `RuntimeException` desde un
 * `do_action`, tumbando la petición entera.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace {
	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
	defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );

	// WC_Product vive como doble ÚNICO en Support/wp-function-stubs.php: tres
	// copias distintas tras `class_exists` hacían que la forma ganadora dependiera
	// del orden de ejecución (flake medido con --order-by=random).
}

namespace Akibara\Business\Modules\AdminPanel {
	// Doble con el FQCN del panel de Business: el aviso de rechazo se calla sólo
	// mientras ESTA clase tiene enganchado `skip_untouched_default`. El
	// `class_exists()` SÍ autocarga: bajo el runner integrado manda la clase real
	// de Business y este doble no llega a declararse (mismo patrón que el doble
	// de `SearchPriceProvider`).
	if ( ! class_exists( AdminPanel::class ) ) {
		final class AdminPanel {
			/** @param mixed $value @return mixed */
			public function skip_untouched_default( $value ) {
				return $value;
			}
		}
	}
}

namespace Akibara\Search\Tests {

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

	/**
	 * Doble de wpdb con lo que el reproof editorial toca: options crudas, el
	 * universo elegible, las filas vivas de `docs` (ids + precio + precio_html),
	 * el fence y el CAS byte-exacto.
	 */
	final class EditorialOptionWpdb {

		public string $prefix     = 'wp_';
		public string $options    = 'wp_options';
		public string $termmeta   = 'wp_termmeta';
		public string $posts      = 'wp_posts';
		public string $last_error = '';

		/** @var array<string, string> */
		public array $raw_options = array();

		/** @var array<int, int> Universo fuente (SELECT ID FROM wp_posts …). */
		public array $eligible_ids = array( 11, 12, 13 );

		/** @var array<int, array{post_id:int, price_effective:string, price_html:string}> Filas vivas de docs. */
		public array $doc_rows = array();

		/** Las escrituras en la tabla de trabajo fallan como un error de MySQL. */
		public bool $fail_work_writes = false;

		/** Valor que devuelve GET_LOCK: '0' modela el fence tomado por otro proceso. */
		public string $get_lock = '1';

		/** @var array<int, string> */
		public array $queries = array();

		public function __construct() {
			foreach ( array( 11, 12, 13 ) as $post_id ) {
				$this->doc_rows[ $post_id ] = array(
					'post_id'         => $post_id,
					'price_effective' => '9990.00',
					'price_html'      => '$9.990',
				);
			}
		}

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

		/** @param mixed $output @return array<int, mixed> */
		public function get_results( string $query, $output = OBJECT ): array {
			$this->last_error = '';
			if ( false !== stripos( $query, 'price_effective, price_html FROM ' . Install::docs_table() ) ) {
				return array_values( $this->doc_rows );
			}
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
				return array();
			}
			return array();
		}

		/**
		 * Claim live. Por defecto NO hay deuda en vuelo —es el caso de casi
		 * todos los escenarios— pero se puede sembrar una para el producto que
		 * interese: sin esto, la tabla de trabajo está VACÍA por construcción y
		 * cualquier parche que consulte «¿hay trabajo live?» se pondría verde
		 * sin haber probado nada. (Pedido por bis-avenida, 2026-09-06.)
		 *
		 * @var array<int, string> product_id => estado (pending|leased|acked)
		 */
		public array $live_claims = array();

		/**
		 * Sello `updated_at` del claim, en UTC («Y-m-d H:i:s»).
		 *
		 * Sin sello, la fila sale como salía y el código la trata como recién
		 * tocada — que es el caso (c1). Con sello viejo se prueba la VÁLVULA:
		 * un claim que lleva atascado más de la cuenta deja de aplazar.
		 *
		 * @var array<int, string> product_id => sello UTC
		 */
		public array $live_claim_updated_at = array();

		/**
		 * `lease_until` del claim, en UTC. Sólo tiene sentido con estado `leased`.
		 * Vencido = worker muerto: la válvula no debe esperarlo.
		 *
		 * @var array<int, string> product_id => sello UTC
		 */
		public array $live_claim_lease_until = array();

		/** @return object|null */
		public function get_row( string $query ) {
			$this->last_error = '';

			if ( false === stripos( $query, 'wp_akb_search_work' ) ) {
				return null;
			}
			if ( false === stripos( $query, "scope = 'live'" ) ) {
				return null;
			}
			if ( 1 !== preg_match( '/product_id = (\\d+)/', $query, $match ) ) {
				return null;
			}

			$product_id = (int) $match[1];
			if ( ! isset( $this->live_claims[ $product_id ] ) ) {
				return null;
			}

			$fila = array(
				'id'         => $product_id,
				'scope'      => 'live',
				'run_id'     => '',
				'product_id' => $product_id,
				'status'     => $this->live_claims[ $product_id ],
				'wanted_seq' => 1,
				'acked_seq'  => 0,
				'lease_seq'  => 0,
				'attempts'   => 0,
			);

			// Como la tabla real: `updated_at` NUNCA falta (DEFAULT CURRENT_TIMESTAMP).
			$fila['updated_at'] = $this->live_claim_updated_at[ $product_id ] ?? gmdate( 'Y-m-d H:i:s' );
			if ( isset( $this->live_claim_lease_until[ $product_id ] ) ) {
				$fila['lease_until'] = $this->live_claim_lease_until[ $product_id ];
			}

			return (object) $fila;
		}

		/** @return int|string|null */
		public function get_var( string $query ) {
			$this->last_error = '';
			if ( false !== stripos( $query, 'GET_LOCK(' ) ) {
				return $this->get_lock;
			}
			if ( false !== stripos( $query, 'RELEASE_LOCK(' ) ) {
				return '1';
			}
			if ( false !== stripos( $query, 'CONNECTION_ID()' ) || false !== stripos( $query, 'IS_USED_LOCK(' ) ) {
				return '37';
			}
			if ( false !== stripos( $query, 'COUNT(*)' ) && false !== stripos( $query, Install::docs_table() ) ) {
				return (string) count( $this->doc_rows );
			}
			if ( 1 === preg_match( "/option_name = '((?:''|[^'])*)'/", $query, $match ) ) {
				$name  = str_replace( "''", "'", $match[1] );
				$valor = $this->raw_options[ $name ] ?? null;
				// Como `wpdb::get_var()` de Core (wp-includes/class-wpdb.php): una
				// columna con '' vuelve como null, IGUAL que una fila ausente. Este
				// doble devolvía '' y por eso la suite aprobaba justo lo que
				// producción rompía: `gmt_offset` presente y vacío (0.1.64).
				return '' === $valor ? null : $valor;
			}
			return '0';
		}

		/** @return array<int, string> */
		public function get_col( string $query ): array {
			$this->last_error = '';
			if ( false !== stripos( $query, 'FROM ' . Install::docs_table() ) ) {
				return array_map( 'strval', array_keys( $this->doc_rows ) );
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
			if ( $this->fail_work_writes && false !== strpos( $query, 'wp_akb_search_work' ) ) {
				$this->last_error = 'work write failed';
				return false;
			}
			if ( 1 === preg_match( "/INSERT IGNORE INTO .* VALUES \('((?:''|[^'])*)', '((?:''|[^'])*)', 'no'\)/s", $query, $match ) ) {
				$name  = str_replace( "''", "'", $match[1] );
				$value = str_replace( "''", "'", $match[2] );
				if ( ! array_key_exists( $name, $this->raw_options ) ) {
					$this->raw_options[ $name ] = $value;
				}
				return 1;
			}
			// CAS byte-exacto de baja (Install::replace_option_cas con $delete).
			if ( 1 === preg_match(
				"/DELETE FROM .*options WHERE option_name = '((?:''|[^'])*)' AND BINARY option_value = BINARY '((?:''|[^'])*)'/s",
				$query,
				$match
			) ) {
				$name     = str_replace( "''", "'", $match[1] );
				$expected = str_replace( "''", "'", $match[2] );
				if ( ( $this->raw_options[ $name ] ?? null ) !== $expected ) {
					return 0;
				}
				unset( $this->raw_options[ $name ] );
				return 1;
			}
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
				return 1;
			}
			return 0;
		}
	}

	/** @covers \Akibara\Search\EditorialDiscountPublication */
	final class EditorialOptionReproofTest extends TestCase {
		use \Akibara\Search\Tests\Support\MarcosEditorialesVigilados;


		/** Fingerprint de la revisión editorial YA materializada en el índice. */
		private const RECEIPT_FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

		private EditorialOptionWpdb $db;

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
			$this->db                = new EditorialOptionWpdb();
			$GLOBALS['wpdb']         = $this->db;
			unset( $GLOBALS['wp_filter']['akb_search_effective_price'] );

			Functions\when( 'taxonomy_exists' )->justReturn( false );
			Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
			Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value ) );
			Functions\when( 'do_action' )->justReturn( null );
			Functions\when( 'wp_cache_delete' )->justReturn( true );
			Functions\when( 'wp_kses_post' )->alias( static fn( string $html ): string => $html );
			Functions\when( 'wc_get_product' )->alias( static fn( int $id ) => new \WC_Product( $id ) );
			Functions\when( 'maybe_serialize' )->alias( static fn( $value ): string => is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value );
			Functions\when( 'update_option' )->alias(
				function ( string $name, $value ): bool {
					$this->db->raw_options[ $name ] = is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
					return true;
				}
			);
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
			// `wp_get_current_user` NO se mockea acá: vive como stub real en
			// Support/wp-function-stubs.php porque su sola presencia cambia el
			// resultado de otros archivos (AuditLog la llama tras function_exists).
			Functions\when( 'wp_prime_option_caches' )->justReturn( null );
			// Sin usuario no hay pantalla donde avisar, que es el caso de WP-CLI y
			// del cron. `function_exists()` da true por contagio dentro de la suite,
			// así que el guard de producción no basta: hay que stubearla.
			Functions\when( 'get_current_user_id' )->justReturn( 0 );
			Functions\when( 'sanitize_key' )->alias( static fn( string $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) );
			Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
			Functions\when( 'as_has_scheduled_action' )->justReturn( false );
			Functions\when( 'as_next_scheduled_action' )->justReturn( false );

			foreach ( $this->publication_static_defaults() as $name => $value ) {
				$this->original_static[ $name ] = $this->static_value( $name );
				$this->set_static_value( $name, $value );
			}
			SemanticPublication::reset_authoritative_state_for_tests();
			FacetRegistry::reset_cache();
			$this->install_editorial_debt_bundle();
		}

		protected function tearDown(): void {
			// Primero, con los stubs montados: un marco abierto no puede cerrarse al apagar PHP.
			$this->soltar_publicaciones_vigiladas();
			unset( $GLOBALS['wp_filter']['akb_search_effective_price'], $GLOBALS['wp_filter']['pre_update_option'] );
			if ( $this->rebuild_runtime_saved ) {
				$runtime = new ReflectionProperty( RebuildJob::class, 'runtime' );
				$runtime->setAccessible( true );
				$runtime->setValue( null, $this->rebuild_runtime_backup );
				$this->rebuild_runtime_saved = false;
			}
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

		/**
		 * @testdox (a) Cambio de option SIN términos con descuento: el precio de los 3 elegibles
		 *              no se mueve, el receipt se re-sella a la revisión nueva y el serving NO cae.
		 */
		public function test_an_option_change_that_moves_no_price_reseals_the_receipt_without_a_rebuild(): void {
			$publication = $this->reconcile_runtime();
			$this->reset_production_latches();
			self::assertNull(
				EditorialDiscountPublication::capture_serving_token(),
				'Precondición: la revisión editorial nueva cierra el gate (el 503 del bug).'
			);
			$this->reset_production_latches();

			self::assertTrue( $publication->reconcile_fingerprint() );

			$receipt = unserialize( $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], array( 'allowed_classes' => false ) );
			self::assertSame( 2, $receipt['wanted_seq'], 'El receipt adopta la revisión editorial nueva.' );
			self::assertSame( $this->source_fingerprint(), $receipt['fingerprint'] );
			self::assertSame( array( 'eligible' => 3, 'docs' => 3, 'missing' => 0, 'extra' => 0, 'orphans' => 0 ), $receipt['coverage'] );
			self::assertSame( 'generation-a', $receipt['generation_target'] );
			self::assertTrue( $this->invoke_static( 'valid_receipt', $receipt ), 'Sigue dentro del set cerrado de claves/tipos.' );
			foreach ( array( 'run_id', 'swapped_at', 'published_at', 'db_version', 'schema_seal', 'purge_all_dispatched_at' ) as $immutable ) {
				self::assertSame( $this->sealed_receipt()[ $immutable ], $receipt[ $immutable ], "$immutable no puede moverse en un re-sellado." );
			}
			self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options, 'Cero rebuild: era el 100-160 s que costaba el bug.' );
			self::assertSame( array( 'receipt_resellado_sin_cambio_de_precio' ), $this->audit_actions() );
			self::assertStringContainsString( '1→2', $this->audit_details()[0] );

			$this->reset_production_latches();
			self::assertIsString(
				EditorialDiscountPublication::capture_serving_token(),
				'El gate público reabre sin rebuild: el suggest sigue en 200.'
			);
		}

		/**
		 * @testdox La señal de rebuild que montó la escritura de la option se RETIRA tras el
		 *          re-sellado: sin eso el rebuild corría igual y su ventana de publicación volvía a dar 503.
		 */
		public function test_the_rebuild_signal_staged_by_the_option_write_is_withdrawn_after_the_reseal(): void {
			$publication = $this->reconcile_runtime();
			$this->seed_pending_rebuild( 'option_update_akibara_taxonomy_discount_enabled', Install::DB_VERSION );

			self::assertTrue( $publication->reconcile_fingerprint() );

			self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options, 'La señal se retiró: cero rebuild.' );
			self::assertContains( 'rebuild_editorial_retirado', $this->audit_actions() );
		}

		/** @testdox Una señal de `schema_migration` NUNCA se retira, aunque el precio no se haya movido. */
		public function test_a_schema_migration_signal_is_never_withdrawn(): void {
			$publication = $this->reconcile_runtime();
			$this->seed_pending_rebuild( 'schema_migration', '1.8.0' );

			self::assertTrue( $publication->reconcile_fingerprint() );

			self::assertArrayHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options, 'La migración de esquema sobrevive.' );
			self::assertNotContains( 'rebuild_editorial_retirado', $this->audit_actions() );
			self::assertContains( 'receipt_resellado_sin_cambio_de_precio', $this->audit_actions() );
		}

		/**
		 * @testdox (b) Cambio de option CON un término con descuento (el índice tiene $9.000 y la
		 *              configuración nueva produce $9.990): NO se sella y se sigue exigiendo el rebuild.
		 */
		public function test_an_option_change_that_moves_a_price_still_demands_the_rebuild(): void {
			$publication = $this->reconcile_runtime();
			// El índice quedó materializado con Arechi al 10%; la configuración
			// nueva ya no aplica ese descuento → el precio del índice miente.
			$this->db->doc_rows[12]['price_effective'] = '8991.00';
			$before = $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ];

			self::assertFalse( $publication->reconcile_fingerprint(), 'Con el gate cerrado y el rebuild pedido, «listo» sería mentira.' );

			self::assertSame( $before, $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], 'Ni un byte del receipt.' );
			self::assertArrayHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options, 'El camino caro sigue vivo cuando hay precios que mover.' );
			self::assertSame( array( 'receipt_reproof_rechazado' ), $this->audit_actions() );
			self::assertStringContainsString( 'cambia la materialización', $this->audit_details()[0] );
			$this->reset_production_latches();
			self::assertNull( EditorialDiscountPublication::capture_serving_token(), 'Fail-closed: el gate sigue cerrado.' );
		}

		/**
		 * LA FIXTURE QUE FALTABA (bis-avenida, 2026-09-06). Los dos casos van
		 * JUNTOS a propósito: el segundo es el control que impide que un parche
		 * se ponga verde apagando la vía cara del todo.
		 *
		 * Caso 1 — divergencia CON trabajo live en vuelo sobre ese producto.
		 * El índice no está roto: está a medio escribir. Pedir un rebuild
		 * completo aquí es tirar la caché de borde por trabajo que ya venía en
		 * camino. HOY ESTE TEST FALLA: `editorial_materialization_is_unchanged()`
		 * no pregunta por la cola, sólo compara precios.
		 *
		 * @testdox (c1) Divergencia con claim live PENDING: NO se exige el rebuild.
		 */
		public function test_a_divergence_with_live_work_in_flight_does_not_demand_the_rebuild(): void {
			$publication = $this->reconcile_runtime();

			// Mismo montaje que (b1): el índice miente sobre el precio…
			$this->db->doc_rows[12]['price_effective'] = '8991.00';
			// …pero ese producto YA tiene trabajo live pendiente.
			$this->db->live_claims[12] = 'pending';

			$publication->reconcile_fingerprint();

			self::assertArrayNotHasKey(
				Install::OPTION_PENDING_REBUILD,
				$this->db->raw_options,
				'Con trabajo live en vuelo sobre el producto divergente, el índice no está roto: está a medio escribir. Un rebuild completo aquí purga Cloudflare por nada.'
			);
		}

		/**
		 * Caso 2 — EL CONTROL. Misma divergencia, cola en cero. Aquí el rebuild
		 * SÍ debe exigirse: si un parche también apaga este camino, el parche
		 * está mal y este test lo caza.
		 *
		 * @testdox (c2) CONTROL — divergencia con la cola VACÍA: el rebuild se sigue exigiendo.
		 */
		public function test_a_divergence_with_an_empty_queue_still_demands_the_rebuild(): void {
			$publication = $this->reconcile_runtime();

			$this->db->doc_rows[12]['price_effective'] = '8991.00';
			self::assertSame( array(), $this->db->live_claims, 'Precondición explícita: la cola live está vacía.' );

			self::assertFalse( $publication->reconcile_fingerprint() );

			self::assertArrayHasKey(
				Install::OPTION_PENDING_REBUILD,
				$this->db->raw_options,
				'Sin trabajo en vuelo, una divergencia de precio SÍ es un índice que miente.'
			);
		}

		/**
		 * Caso 3 — LA VÁLVULA. Misma divergencia, mismo claim PENDING, pero
		 * llevamos media hora aplazando.
		 *
		 * Aplazar es barato mientras el trabajo AVANZA. Un `null` que se repite
		 * sin tope es lo que el 2026-09-05 dejó `purge_post_seq` en 0 y el
		 * suggest en 503 permanente. La válvula acota CUÁNTO LLEVAMOS APLAZANDO
		 * (option propia, `time()` de PHP), no la edad del claim — ver (c5).
		 *
		 * @testdox (c3) VÁLVULA — media hora aplazando: el rebuild SÍ se exige y el reloj se limpia.
		 */
		public function test_a_long_deferral_stops_deferring_the_rebuild(): void {
			$publication = $this->reconcile_runtime();

			$this->db->doc_rows[12]['price_effective'] = '8991.00';
			$this->db->live_claims[12]                 = 'pending';
			$this->db->raw_options['akb_search_reproof_deferred_since'] = (string) ( time() - 1800 );

			self::assertFalse( $publication->reconcile_fingerprint() );

			self::assertArrayHasKey(
				Install::OPTION_PENDING_REBUILD,
				$this->db->raw_options,
				'Media hora aplazando no es trabajo en vuelo: si se sigue aplazando, el índice se queda mintiendo para siempre.'
			);
			self::assertSame(
				'0',
				$this->db->raw_options['akb_search_reproof_deferred_since'] ?? '0',
				'Tras abrir la válvula el reloj tiene que quedar a cero, o el siguiente aplazamiento nacería ya vencido.'
			);
		}

		/**
		 * @testdox (c4) Primer aplazamiento: se aplaza Y arranca el reloj.
		 *
		 * El contrapeso de (c3): que la válvula no se abra por el simple hecho de
		 * existir. Y que deje huella: sin reloj arrancado, (c3) nunca podría
		 * dispararse en producción.
		 */
		public function test_a_fresh_deferral_defers_and_starts_the_clock(): void {
			$publication = $this->reconcile_runtime();

			$this->db->doc_rows[12]['price_effective'] = '8991.00';
			$this->db->live_claims[12]                 = 'pending';
			self::assertArrayNotHasKey( 'akb_search_reproof_deferred_since', $this->db->raw_options, 'Precondición: sin reloj.' );

			$publication->reconcile_fingerprint();

			self::assertArrayNotHasKey(
				Install::OPTION_PENDING_REBUILD,
				$this->db->raw_options,
				'Un claim en vuelo recién visto es trabajo normal: el pump cierra claims en segundos.'
			);
			$since = (int) ( $this->db->raw_options['akb_search_reproof_deferred_since'] ?? 0 );
			self::assertGreaterThan( 0, $since, 'El primer aplazamiento tiene que arrancar el reloj.' );
			self::assertLessThanOrEqual( time(), $since );
		}

		/**
		 * Caso 5 — EL QUE SE LE ESCAPÓ A LA PRIMERA VERSIÓN. El claim se
		 * RE-TOCÓ hace 5 s (una venta, un cambio de stock, un `mark_live_dirty`)
		 * pero llevamos media hora aplazando.
		 *
		 * La válvula de la mañana medía `updated_at` y aquí habría dicho
		 * «claim fresco, sigo aplazando» para siempre. Revisión de Opus,
		 * 2026-09-11 tarde.
		 *
		 * @testdox (c5) Un claim re-tocado hace 5 s NO reinicia la válvula: media hora aplazando → rebuild.
		 */
		public function test_a_retouched_claim_does_not_reset_the_valve(): void {
			$publication = $this->reconcile_runtime();

			$this->db->doc_rows[12]['price_effective'] = '8991.00';
			$this->db->live_claims[12]                 = 'pending';
			$this->db->live_claim_updated_at[12]       = gmdate( 'Y-m-d H:i:s', time() - 5 );
			$this->db->raw_options['akb_search_reproof_deferred_since'] = (string) ( time() - 1800 );

			self::assertFalse( $publication->reconcile_fingerprint() );

			self::assertArrayHasKey(
				Install::OPTION_PENDING_REBUILD,
				$this->db->raw_options,
				'La edad de la fila no dice nada: se re-toca con cada venta. Lo que acota es cuánto llevamos aplazando.'
			);
		}

		/**
		 * Caso 6 — LEASED con el arriendo VENCIDO. No es trabajo en vuelo: es un
		 * worker muerto que `recover_expired()` devolverá a la cola. Esperar los
		 * 600 s aquí es esperar a nadie.
		 *
		 * @testdox (c6) LEASED con arriendo vencido: no se espera, cae al rebuild de inmediato.
		 */
		public function test_an_expired_lease_is_not_work_in_flight(): void {
			$publication = $this->reconcile_runtime();

			$this->db->doc_rows[12]['price_effective'] = '8991.00';
			$this->db->live_claims[12]                 = 'leased';
			$this->db->live_claim_lease_until[12]      = gmdate( 'Y-m-d H:i:s', time() - 60 );

			self::assertFalse( $publication->reconcile_fingerprint() );

			self::assertArrayHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options );
		}

		/**
		 * @testdox (c7) LEASED con arriendo vigente: es trabajo en vuelo y se aplaza.
		 */
		public function test_a_live_lease_still_defers(): void {
			$publication = $this->reconcile_runtime();

			$this->db->doc_rows[12]['price_effective'] = '8991.00';
			$this->db->live_claims[12]                 = 'leased';
			$this->db->live_claim_lease_until[12]      = gmdate( 'Y-m-d H:i:s', time() + 60 );

			$publication->reconcile_fingerprint();

			self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options );
		}

		/**
		 * @testdox (b2) El precio_html cuenta como precio: mismo importe, distinto tachado → tampoco se sella.
		 */
		public function test_a_price_html_divergence_alone_is_enough_to_demand_the_rebuild(): void {
			$publication = $this->reconcile_runtime();
			$this->db->doc_rows[13]['price_html'] = '<del>$11.100</del> <ins>$9.990</ins>';
			$before = $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ];

			self::assertFalse( $publication->reconcile_fingerprint() );

			self::assertSame( $before, $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] );
			self::assertSame( array( 'receipt_reproof_rechazado' ), $this->audit_actions() );
		}

		/**
		 * @testdox Un receipt POR DELANTE del state no es deuda sino corrupción: nunca se re-sella hacia atrás.
		 */
		public function test_a_receipt_ahead_of_the_state_is_never_resealed_backwards(): void {
			$publication = $this->reconcile_runtime();
			$state = json_decode( $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ], true );
			$receipt = unserialize( $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], array( 'allowed_classes' => false ) );
			$receipt['wanted_seq'] = (int) $state['wanted_seq'] + 1;
			$this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] = serialize( $receipt );
			$before = $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ];

			self::assertFalse( $publication->reconcile_fingerprint() );

			self::assertSame( $before, $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] );
			self::assertNotContains( 'receipt_resellado_sin_cambio_de_precio', $this->audit_actions() );
		}

		/**
		 * @testdox (c) `delete_option` sin fence disponible ya NO fatalea: deja rastro y el reconcile monta la deuda.
		 */
		public function test_deleting_an_editorial_option_without_a_fence_does_not_fatal(): void {
			$publication = $this->reconcile_runtime();
			$this->db->get_lock = '0'; // El rebuild que disparó el cambio anterior tiene el fence.
			// Core sólo dispara `delete_option` cuando la fila existe.
			$this->db->raw_options['akibara_taxonomy_discount_enabled'] = 'no';

			$publication->before_delete_option( 'akibara_taxonomy_discount_enabled' );

			self::assertSame( array( 'option_editorial_sin_cercar' ), $this->audit_actions() );
			self::assertStringContainsString( 'Baja de akibara_taxonomy_discount_enabled', $this->audit_details()[0] );
		}

		/** @testdox (c) `add_option` sin fence disponible tampoco fatalea. */
		public function test_adding_an_editorial_option_without_a_fence_does_not_fatal(): void {
			$publication = $this->reconcile_runtime();
			$this->db->get_lock = '0';

			// 'no': este arnés no tiene proveedor de precio, y 'yes' sin él deja la
			// foto ilegible —eso se veta, y lo prueba otro caso—.
			$publication->before_add_option( 'akibara_taxonomy_discount_enabled', 'no' );

			self::assertSame( array( 'option_editorial_sin_cercar' ), $this->audit_actions() );
			self::assertStringContainsString( 'Alta de akibara_taxonomy_discount_enabled', $this->audit_details()[0] );
		}

		/**
		 * El gate cierra solo ante una escritura sin cercar y el reconcile monta
		 * la deuda — pero «se auto-repara» tiene que ocurrir en la petición
		 * SIGUIENTE, no cuando expire la ventana de 60 s del estrangulador.
		 * Medido en el espejo el 2026-09-02: sin esto, `suggest` se quedaba en
		 * 503 y las dos primeras llamadas a `reconcile_now()` devolvían false
		 * por estar estranguladas, no por falta de trabajo. La ruta cercada ya
		 * lo hacía al cerrar el frame; la no cercada es la que más lo necesita,
		 * porque nadie va a cerrar un frame por ella.
		 *
		 * @testdox La escritura sin cercar libera el estrangulador para que la petición siguiente reconcilie ya.
		 */
		public function test_the_unfenced_path_clears_the_reconcile_throttle(): void {
			$publication = $this->reconcile_runtime();
			$this->db->get_lock = '0';
			$this->db->raw_options['akb_search_editorial_reconcile_at'] = (string) time();
			$this->db->raw_options['akibara_taxonomy_discount_enabled'] = 'no'; // Core sólo borra filas que existen.

			$publication->before_delete_option( 'akibara_taxonomy_discount_enabled' );

			self::assertSame(
				'0',
				(string) ( $this->db->raw_options['akb_search_editorial_reconcile_at'] ?? 'ausente' ),
				'Con la marca vigente el reconcile de la petición siguiente queda estrangulado y el 503 se alarga.'
			);

			$this->db->raw_options['akb_search_editorial_reconcile_at'] = (string) time();
			unset( $this->db->raw_options['akibara_taxonomy_discount_enabled'] );
			$publication->before_add_option( 'akibara_taxonomy_discount_enabled', 'no' );

			self::assertSame(
				'0',
				(string) ( $this->db->raw_options['akb_search_editorial_reconcile_at'] ?? 'ausente' ),
				'El alta sin cercar cierra el gate igual que la baja: también tiene que soltar el estrangulador.'
			);
		}

		/**
		 * Hallazgo S-4d0e7c11, reproducido en el espejo el 2026-09-13: una option
		 * vigilada con un valor FUERA DE DOMINIO (`apply_to` en «stock») deja la
		 * foto de precios sin autoridad. Desde ahí, `stage_global()` no consigue
		 * frame y este filtro devolvía el valor viejo SIEMPRE: ni el panel ni
		 * `wp option update` podían devolverle un valor válido, y el buscador
		 * quedaba en 503 sin salida. La única reparación era SQL a mano.
		 *
		 * @testdox Devolverle a una option vigilada un valor de su dominio nunca se veta.
		 */
		public function test_a_write_that_repairs_an_invalid_option_is_never_vetoed(): void {
			$publication = $this->reconcile_runtime();
			$this->reset_production_latches();
			$this->db->raw_options['akibara_taxonomy_discount_apply_to'] = 'stock';

			self::assertSame(
				'instock_only',
				$publication->filter_pre_update_option( 'instock_only', 'akibara_taxonomy_discount_apply_to', 'stock' ),
				'Sin esta salida, el valor inválido se arregla sólo con SQL a mano.'
			);

			$this->reset_production_latches();
			self::assertSame(
				'stock',
				$publication->filter_pre_update_option( 'tampoco_existe', 'akibara_taxonomy_discount_apply_to', 'stock' ),
				'Un valor que sigue fuera de dominio no entra: la salida es para reparar, no para saltarse el cerco.'
			);
		}

		/**
		 * Hallazgo S-abb1b5fe: el cerco vetaba la escritura y WordPress seguía
		 * diciendo «Ajustes guardados». En Ajustes → Generales y en WooCommerce →
		 * Productos —las dos pantallas donde viven la zona horaria y los
		 * decimales— el dueño se quedaba con el valor viejo y sin una palabra.
		 *
		 * @testdox Una escritura vetada deja aviso para el dueño, en cristiano y una sola vez.
		 */
		public function test_a_vetoed_write_leaves_a_notice_for_the_owner(): void {
			$publication = $this->reconcile_runtime();
			$this->reset_production_latches();
			$this->db->get_lock = '0'; // El cerco lo tiene otro proceso.

			$transients = array();
			Functions\when( 'get_current_user_id' )->justReturn( 9 );
			Functions\when( 'set_transient' )->alias(
				static function ( string $clave, $valor ) use ( &$transients ): bool {
					$transients[ $clave ] = $valor;
					return true;
				}
			);
			// Cierre con referencia, no función flecha: la flecha copia el array al
			// crearse y nunca vería lo que escribe set_transient.
			Functions\when( 'get_transient' )->alias(
				static function ( string $clave ) use ( &$transients ) {
					return $transients[ $clave ] ?? false;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				static function ( string $clave ) use ( &$transients ): bool {
					unset( $transients[ $clave ] );
					return true;
				}
			);
			Functions\when( 'esc_html' )->returnArg();

			$publication->filter_pre_update_option( '2', 'woocommerce_price_num_decimals', '0' );

			self::assertNotSame( array(), $transients, 'El veto tiene que dejar el aviso guardado.' );

			ob_start();
			EditorialDiscountPublication::render_rejected_write_notice();
			$html = (string) ob_get_clean();

			self::assertStringContainsString( 'los decimales de los precios', $html );
			self::assertStringContainsString( 'notice-warning', $html );

			ob_start();
			EditorialDiscountPublication::render_rejected_write_notice();
			self::assertSame( '', (string) ob_get_clean(), 'El aviso se consume una sola vez.' );
		}

		/** @testdox Con la foto sana, el cerco sigue mandando: una escritura sin fence se veta. */
		public function test_a_healthy_source_still_vetoes_an_unfenced_write(): void {
			$publication = $this->reconcile_runtime();
			$this->reset_production_latches();
			$this->db->get_lock = '0'; // Otro proceso tiene el cerco.
			// Sin usuario no hay pantalla donde avisar: el veto se limita a vetar.
			// (`function_exists()` da true por contagio dentro de la suite, así que
			// el guard de producción no basta para que esta prueba no toque nada.)
			Functions\when( 'get_current_user_id' )->justReturn( 0 );

			self::assertSame(
				'no',
				$publication->filter_pre_update_option( 'yes', 'akibara_taxonomy_discount_enabled', 'no' ),
				'El valor viejo era válido: aquí no hay nada que reparar y el cerco manda.'
			);
		}

		/** @testdox Una option ajena sin fence no toca nada: ni AuditLog ni excepción. */
		public function test_a_foreign_option_is_untouched_by_the_unfenced_path(): void {
			$publication = $this->reconcile_runtime();
			$this->db->get_lock = '0';

			$publication->before_delete_option( 'blogname' );
			$publication->before_add_option( 'blogname', 'Akibara' );

			self::assertSame( array(), $this->audit_actions() );
		}

		/**
		 * Hallazgo A1: la rama CERCADA no miraba el valor entrante. Con todo sano,
		 * un valor fuera del dominio del lector pasaba el cerco con la foto vieja,
		 * `confirm_frame()` ya no podía leer la configuración, `purge_post_seq` no
		 * se marcaba y el buscador quedaba en 503 sin que nadie lo hubiera pedido.
		 *
		 * @dataProvider valores_que_el_lector_rechaza
		 * @testdox Con la configuración sana, un valor que el lector rechaza se veta antes de cercar nada.
		 */
		public function test_a_value_the_reader_rejects_never_reaches_the_fence( string $option, string $entrante, string $guardado ): void {
			$publication = $this->reconcile_runtime();
			if ( '' !== $guardado ) {
				$this->db->raw_options[ $option ] = $guardado;
			}
			$this->reset_production_latches();
			$estado_antes = $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ];

			self::assertSame(
				$guardado,
				(string) $publication->filter_pre_update_option( $entrante, $option, $guardado ),
				'El valor viejo se conserva: el lector no sabría leer el nuevo.'
			);

			self::assertSame(
				$estado_antes,
				$this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ],
				'Ni la revisión editorial se toca: el veto ocurre ANTES de montar deuda.'
			);
			self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options, 'Tampoco se pide un rebuild por un valor que no entró.' );
			self::assertSame( array( 'option_editorial_rechazada' ), $this->audit_actions() );
			self::assertStringContainsString( 'invalido', $this->audit_details()[0] );
		}

		/** @return array<string, array{0: string, 1: string, 2: string}> */
		public static function valores_que_el_lector_rechaza(): array {
			return array(
				'apply_to fuera de dominio'      => array( 'akibara_taxonomy_discount_apply_to', 'stock', 'instock_only' ),
				'decimales sin tope de Woo'      => array( 'woocommerce_price_num_decimals', '10', '0' ),
				'fecha que no existe'            => array( 'akibara_taxonomy_discount_to', '2026-02-30', '2026-02-28' ),
				'zona horaria que PHP no sabe'   => array( 'timezone_string', 'Marte/Olympus', 'America/Santiago' ),
				'tope de descuento fuera de 90'  => array( 'akb_discount_max_pct', '120', '15' ),
			);
		}

		/**
		 * Hallazgo A2-ii: la salida de reparación trataba la option AUSENTE como
		 * inválida —`(string) false` es ''— cuando el lector la lee con su default.
		 * Con el cerco tomado por un rebuild, subir el tope 15→20 abría el cerco,
		 * creaba la fila sin deuda ni recibo y tumbaba el buscador.
		 *
		 * @testdox Una option ausente con el cerco tomado se veta, no se «repara».
		 */
		public function test_an_absent_option_with_the_fence_taken_is_vetoed(): void {
			$publication = $this->reconcile_runtime();
			$this->db->get_lock = '0'; // El rebuild en curso tiene el cerco.
			$this->reset_production_latches();

			self::assertFalse(
				$publication->filter_pre_update_option( '20', 'akb_discount_max_pct', false ),
				'Devolver el valor viejo (false) es lo que hace que Core no escriba ni cree la fila.'
			);

			self::assertArrayNotHasKey( 'akb_discount_max_pct', $this->db->raw_options );
			self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options );
			self::assertSame( array( 'option_editorial_rechazada' ), $this->audit_actions() );
			self::assertStringContainsString( 'ocupado', $this->audit_details()[0] );
		}

		/**
		 * Hallazgo A2-i: la salida de reparación usaba `valid_source_option_value()`,
		 * que acepta «2026-02-30» —el lector la rechaza en `window_snapshot()`—, y
		 * leía el valor viejo de `get_option()` (caché de objetos) en vez de la
		 * fila. Con esa fecha guardada, el dueño no podía arreglarla ni desde el
		 * panel ni con `wp option update`: el buscador quedaba en 503 sin salida.
		 *
		 * @testdox Una fecha imposible guardada por SQL se puede corregir desde el panel.
		 */
		public function test_an_impossible_date_can_be_repaired_from_the_panel(): void {
			$publication = $this->reconcile_runtime();
			$this->db->raw_options['akibara_taxonomy_discount_to'] = '2026-02-30';
			$this->db->get_lock = '0'; // Con la foto ilegible no hay cerco que valga.
			$this->reset_production_latches();

			self::assertSame(
				'2026-02-28',
				$publication->filter_pre_update_option(
					'2026-02-28',
					'akibara_taxonomy_discount_to',
					// El viejo llega de get_option(): la caché de objetos puede tener
					// otra fecha —válida— mientras la FILA guarda la imposible.
					// Decide la fila, que es de donde lee el lector.
					'2026-02-27'
				)
			);

			self::assertSame(
				'option_repair_akibara_taxonomy_discount_to',
				Install::pending_rebuild_request()['reason'] ?? null,
				'La reparación pide la reconstrucción que la republica.'
			);
			self::assertSame( array( 'option_editorial_reparada' ), $this->audit_actions() );
		}

		/** @testdox Un valor no escalar guardado por SQL también se repara con uno del dominio. */
		public function test_a_serialized_array_saved_by_sql_can_be_repaired(): void {
			$publication = $this->reconcile_runtime();
			$this->db->raw_options['akibara_taxonomy_discount_apply_to'] = serialize( array( 'instock_only' ) );
			$this->reset_production_latches();

			self::assertSame(
				'all',
				$publication->filter_pre_update_option( 'all', 'akibara_taxonomy_discount_apply_to', array( 'instock_only' ) )
			);
			self::assertSame( array( 'option_editorial_reparada' ), $this->audit_actions() );
		}

		/**
		 * Con DOS datos rotos, «¿queda legible si cambio éste?» contesta que no a
		 * cada uno por separado y el dueño no podría arreglar ninguno desde el
		 * panel. Se juzga además el grupo de la option escrita.
		 *
		 * @testdox Con dos fechas imposibles guardadas, cada una se puede arreglar por separado.
		 */
		public function test_two_broken_values_are_repaired_one_at_a_time(): void {
			$publication = $this->reconcile_runtime();
			$this->db->raw_options['akibara_taxonomy_discount_from'] = '2026-02-31';
			$this->db->raw_options['akibara_taxonomy_discount_to']   = '2026-13-01';
			$this->reset_production_latches();

			self::assertSame(
				'2026-02-01',
				$publication->filter_pre_update_option( '2026-02-01', 'akibara_taxonomy_discount_from', '2026-02-31' ),
				'Arregla SU dato aunque el otro siga roto.'
			);

			$this->db->raw_options['akibara_taxonomy_discount_from'] = '2026-02-01';
			$this->reset_production_latches();

			self::assertSame(
				'2026-02-28',
				$publication->filter_pre_update_option( '2026-02-28', 'akibara_taxonomy_discount_to', '2026-13-01' )
			);
		}

		/** @testdox Con la configuración rota, el cambio que no la arregla se veta y el aviso dice cuál corregir. */
		public function test_a_write_that_does_not_fix_the_broken_config_is_vetoed_and_named(): void {
			$publication = $this->reconcile_runtime();
			$this->db->raw_options['akibara_taxonomy_discount_to'] = '2026-13-01';
			$this->capture_notices();
			$this->reset_production_latches();

			self::assertSame(
				'15',
				$publication->filter_pre_update_option( '20', 'akb_discount_max_pct', '15' )
			);

			$html = $this->render_notices();
			self::assertStringContainsString( 'el tope de descuento', $html );
			self::assertStringContainsString( 'la fecha de término del descuento', $html, 'El aviso nombra el dato que hay que corregir primero.' );
		}

		/**
		 * Hallazgo M1: Ajustes → Generales no envía `gmt_offset`; `options.php`
		 * guarda '' y el viejo llega CALCULADO por Core desde la zona con nombre
		 * (-3/-4), así que `same_value()` los veía distintos. Cambiar sólo el
		 * título del sitio subía la revisión editorial, marcaba los 1.568
		 * productos, pedía un rebuild y purgaba el sitio entero — y encima el
		 * UPDATE tocaba cero filas, así que el frame nunca se cerraba.
		 *
		 * @testdox Guardar Ajustes → Generales sin tocar la zona horaria no monta deuda.
		 */
		public function test_saving_general_settings_does_not_stage_debt_for_an_untouched_zone(): void {
			$publication = $this->reconcile_runtime();
			$this->db->raw_options['timezone_string'] = 'America/Santiago';
			$this->db->raw_options['gmt_offset']      = '';
			$this->reset_production_latches();
			$estado_antes = $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ];

			self::assertSame(
				'',
				$publication->filter_pre_update_option( '', 'gmt_offset', -3.0 ),
				'La escritura pasa: los bytes de la fila no cambian.'
			);

			self::assertSame( $estado_antes, $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ] );
			self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options );
			self::assertSame( array(), $this->audit_actions() );
		}

		/**
		 * DEFECTO ALTA medido en el espejo el 2026-09-14 (en producción desde
		 * 0.1.63). La condición real de producción: fila `gmt_offset` PRESENTE y
		 * VACÍA, `timezone_string` = ciudad. Ajustes → Generales no manda el campo,
		 * así que `options.php` llama `update_option( 'gmt_offset', null )`; Core
		 * compara contra el viejo FILTRADO (`pre_option_gmt_offset` lo calcula
		 * desde la zona: -3), los ve distintos y llega al filtro. El veredicto
		 * leía la fila con `wpdb::get_var()`, que devuelve null para '' — la fila
		 * presente parecía ausente, se cercaba, subía la revisión, se marcaban los
		 * 1.632 productos y el UPDATE tocaba cero filas: marco sin cerrar.
		 *
		 * @dataProvider entradasDeGeneralesSinZona
		 * @testdox Guardar Generales con gmt_offset presente y vacío no cerca ni monta deuda ($entrada)
		 *
		 * @param mixed $entrada Lo que llega a `update_option()`: '' (probe) o null (formulario).
		 */
		public function test_gmt_offset_presente_y_vacio_no_cerca_ni_monta_deuda( $entrada ): void {
			$this->install_healthy_bundle();
			list( $publication ) = $this->staging_runtime();
			$this->db->raw_options['timezone_string'] = 'America/Santiago';
			$this->db->raw_options['gmt_offset']      = '';
			$this->reset_production_latches();
			$estado_antes = $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ];
			$sentencias   = count( $this->db->queries );

			self::assertSame(
				'sin_cambio',
				$this->invoke_static( 'write_verdict', 'gmt_offset', $this->invoke_static( 'value_row', $entrada ) ),
				'La fila que dejaría Core es la que ya está: WRITE_UNCHANGED.'
			);
			// -3.0 es lo que `get_option( 'gmt_offset' )` devuelve con el filtro
			// `pre_option_gmt_offset` de Core y la zona America/Santiago.
			self::assertSame( $entrada, $publication->filter_pre_update_option( $entrada, 'gmt_offset', -3.0 ) );

			$nuevas = array_slice( $this->db->queries, $sentencias );
			self::assertSame( array(), preg_grep( '/GET_LOCK\(/', $nuevas ), 'Sin cerco.' );
			self::assertSame( array(), preg_grep( '/INSERT INTO `?wp_akb_search_work/', $nuevas ), 'Sin mark_live_dirty_many.' );
			self::assertSame( $estado_antes, $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ], 'Sin subir wanted_seq.' );
			self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options, 'Sin rebuild.' );

			$marcos = new ReflectionProperty( EditorialDiscountPublication::class, 'option_frames' );
			$marcos->setAccessible( true );
			self::assertSame( array(), $marcos->getValue( $publication ), 'Sin marco.' );

			// Core no confirma (el UPDATE toca 0 filas): el cierre no tiene nada que avisar.
			$publication->release_on_shutdown();
			self::assertNotContains( 'marco_editorial_sin_cerrar', $this->audit_actions() );
			self::assertSame( array(), $this->audit_actions() );
		}

		/** @return array<string, array{0: mixed}> */
		public static function entradasDeGeneralesSinZona(): array {
			return array(
				"'' (update_option directo)"   => array( '' ),
				'null (formulario de Generales)' => array( null ),
			);
		}

		/**
		 * BARRIDO del mismo defecto sobre TODAS las options vigiladas. La trampa no
		 * necesita un filtro `pre_option_*` (sólo `gmt_offset` tiene uno, Core
		 * `default-filters.php`): basta una fila PRESENTE con '' y una escritura
		 * `null`/`false`, porque Core compara `null === ''` y `maybe_serialize()`
		 * de los dos (null frente a ''), los ve distintos y llega al filtro. Con el
		 * veredicto leyendo por `get_var()` cualquiera de las diez se cercaba.
		 *
		 * La BAJA es el revés [B2 de la revisión de 0.1.64]: borrar una fila
		 * presente y vacía SÍ cambia la tabla. En 0.1.63 `get_var()` la leía como
		 * ausente, el veredicto daba «sin cambio» y `before_delete_option()` salía
		 * sin cerco.
		 *
		 * @testdox Fila presente y vacía: escribir null es «sin cambio» y borrarla no lo es, en todas las options vigiladas.
		 */
		public function test_fila_presente_y_vacia_con_escritura_null_es_sin_cambio_en_todas(): void {
			$this->reconcile_runtime();
			foreach ( EditorialDiscountPublication::options() as $option ) {
				$this->db->raw_options[ $option ] = '';
			}
			foreach ( EditorialDiscountPublication::options() as $option ) {
				$this->reset_production_latches();
				foreach ( array( null, false, '' ) as $entrada ) {
					self::assertSame(
						'sin_cambio',
						$this->invoke_static( 'write_verdict', $option, $this->invoke_static( 'value_row', $entrada ) ),
						$option . ' con fila vacía y escritura ' . var_export( $entrada, true ) . ' no cambia un byte.'
					);
				}
				self::assertNotSame(
					'sin_cambio',
					$this->invoke_static( 'write_verdict', $option, array( 'present' => false, 'raw' => '' ) ),
					$option . ': borrar la fila vacía la quita de la tabla; no es «sin cambio».'
				);
			}
		}

		/**
		 * La baja concreta del caso de producción: `gmt_offset` presente y vacío
		 * con zona de ciudad. Se cerca como cualquier cambio real, y cuando Core
		 * borra la fila y dispara `deleted_option` el marco se cierra.
		 *
		 * @testdox Borrar gmt_offset presente y vacío se cerca y Core lo confirma.
		 */
		public function test_borrar_gmt_offset_presente_y_vacio_se_cerca(): void {
			$this->install_healthy_bundle();
			list( $publication ) = $this->staging_runtime();
			$this->db->raw_options['timezone_string'] = 'America/Santiago';
			$this->db->raw_options['gmt_offset']      = '';
			$this->reset_production_latches();

			self::assertSame( 'cercar', $this->invoke_static( 'write_verdict', 'gmt_offset', array( 'present' => false, 'raw' => '' ) ) );
			$publication->before_delete_option( 'gmt_offset' );
			self::assertSame( 2, $this->editorial_state()['wanted_seq'], 'La baja monta su deuda.' );

			unset( $this->db->raw_options['gmt_offset'] );
			Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
			Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
			$publication->after_delete_option( 'gmt_offset' );
			self::assertSame( 2, $this->editorial_state()['purge_post_seq'], 'Core confirmó la baja: la revisión se cierra.' );
		}

		/**
		 * La otra mitad del mismo defecto: con la fila AUSENTE, escribir '' SÍ
		 * cambia la tabla (Core inserta la fila). Ése es el marco sin cerrar que
		 * mide V6 y no se afloja.
		 *
		 * @testdox Con gmt_offset AUSENTE, escribir '' sigue cercándose.
		 */
		public function test_gmt_offset_ausente_sigue_cercandose(): void {
			$this->install_healthy_bundle();
			list( $publication ) = $this->staging_runtime();
			$this->db->raw_options['timezone_string'] = 'America/Santiago';
			unset( $this->db->raw_options['gmt_offset'] );
			$this->reset_production_latches();

			self::assertSame( 'cercar', $this->invoke_static( 'write_verdict', 'gmt_offset', $this->invoke_static( 'value_row', '' ) ) );
			self::assertSame( '', $publication->filter_pre_update_option( '', 'gmt_offset', -3.0 ) );
			self::assertSame( 2, $this->editorial_state()['wanted_seq'], 'El marco monta su deuda.' );

			$publication->release_on_shutdown();
			self::assertContains( 'marco_editorial_sin_cerrar', $this->audit_actions() );
		}

		/**
		 * @dataProvider cambiosRealesDeZona
		 * @testdox Un cambio REAL de zona horaria sigue cercándose ($option)
		 *
		 * @param string               $option  Option que cambia.
		 * @param array<string, string> $filas   Filas crudas de partida.
		 * @param string               $nuevo   Valor nuevo.
		 */
		public function test_un_cambio_real_de_zona_sigue_cercandose( string $option, array $filas, string $nuevo ): void {
			$this->install_healthy_bundle();
			list( $publication ) = $this->staging_runtime();
			foreach ( $filas as $nombre => $valor ) {
				$this->db->raw_options[ $nombre ] = $valor;
			}
			$this->reset_production_latches();

			self::assertSame( 'cercar', $this->invoke_static( 'write_verdict', $option, $this->invoke_static( 'value_row', $nuevo ) ) );
			self::assertSame( $nuevo, $publication->filter_pre_update_option( $nuevo, $option, $filas[ $option ] ) );
			self::assertSame( 2, $this->editorial_state()['wanted_seq'], 'Mover la hora mueve las ventanas de campaña: se cerca.' );

			// Un cambio REAL sí lo escribe Core, y entonces dispara `updated_option`.
			// Sin modelar esa confirmación el marco quedaba abierto hasta el apagado
			// de PHP, donde `release_on_shutdown()` corría con Brain Monkey ya
			// desmontado: la suite imprimía OK y salía con rc=255.
			$this->db->raw_options[ $option ] = $nuevo;
			Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
			Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
			$publication->after_update_option( $option, $filas[ $option ], $nuevo );
			self::assertSame( 2, $this->editorial_state()['purge_post_seq'], 'Core confirmó: la purga posterior cierra la revisión.' );
			self::assertNotContains( 'marco_editorial_sin_cerrar', $this->audit_actions() );
		}

		/** @return array<string, array{0: string, 1: array<string, string>, 2: string}> */
		public static function cambiosRealesDeZona(): array {
			return array(
				'timezone_string ciudad → otra ciudad' => array( 'timezone_string', array( 'timezone_string' => 'America/Santiago', 'gmt_offset' => '' ), 'America/Bogota' ),
				'gmt_offset -3 → -4 con zona por desfase' => array( 'gmt_offset', array( 'timezone_string' => '', 'gmt_offset' => '-3' ), '-4' ),
			);
		}

		/**
		 * `add_option` es una ACCIÓN: no puede devolver el valor viejo y lanzar
		 * desde ahí tumbaba la petición entera. El único corte que deja Core entre
		 * la acción y el INSERT es el filtro `query` de `$wpdb`.
		 *
		 * @testdox Un alta que el lector rechaza no llega a insertarse.
		 */
		public function test_an_add_that_the_reader_rejects_never_reaches_the_insert(): void {
			$publication = $this->reconcile_runtime();
			$this->reset_production_latches();

			$publication->before_add_option( 'woocommerce_price_num_decimals', '10' );

			$insert = "INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES ('woocommerce_price_num_decimals', '10', 'auto') ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`)";
			self::assertSame( '', $publication->filter_blocked_option_write( $insert ), 'Una consulta vacía hace que $wpdb->query() y add_option() devuelvan false.' );
			self::assertSame(
				$insert,
				$publication->filter_blocked_option_write( $insert ),
				'El veto es de un solo uso: no puede quedarse bloqueando altas posteriores.'
			);
			self::assertSame( array( 'option_editorial_rechazada' ), $this->audit_actions() );
		}

		/** @testdox El veto de un alta no toca el INSERT de otra option. */
		public function test_the_add_veto_only_blocks_its_own_insert(): void {
			$publication = $this->reconcile_runtime();
			$this->reset_production_latches();

			$publication->before_add_option( 'woocommerce_price_num_decimals', '10' );

			$ajena = "INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES ('blogname', 'Akibara', 'auto')";
			self::assertSame( $ajena, $publication->filter_blocked_option_write( $ajena ) );
			// Fin de la petición: Core no llegó a insertar y el veto se suelta, igual
			// que en producción. Sin esto la guarda de marcos abiertos lo reclama.
			$publication->release_on_shutdown();
		}

		/**
		 * Hallazgo M3: el aviso prometía «espera y vuelve a guardar» incluso con un
		 * valor que el lector nunca va a aceptar —volver a guardarlo daba el mismo
		 * rechazo— y afirmaba «lo demás quedó guardado», que con el par zona
		 * horaria/`gmt_offset` es falso.
		 *
		 * @testdox Cada causa tiene su aviso y ninguno promete lo que no puede cumplir.
		 */
		public function test_each_rejection_cause_has_its_own_notice(): void {
			$publication = $this->reconcile_runtime();
			$this->capture_notices();
			$this->reset_production_latches();

			$publication->filter_pre_update_option( 'stock', 'akibara_taxonomy_discount_apply_to', 'instock_only' );
			$this->db->get_lock = '0';
			$this->reset_production_latches();
			$publication->filter_pre_update_option( '2', 'woocommerce_price_num_decimals', '0' );

			$html = $this->render_notices();
			self::assertStringContainsString( 'a qué productos se aplica el descuento', $html );
			self::assertStringContainsString( 'no podría leer la configuración de precios', $html );
			self::assertStringContainsString( 'los decimales de los precios', $html );
			self::assertStringContainsString( 'espera un par de minutos', $html );
			self::assertStringNotContainsString( 'Lo demás', $html, 'Con el par zona horaria/gmt_offset esa promesa era falsa.' );
			self::assertSame( '', $this->render_notices(), 'El aviso se consume una sola vez.' );
		}

		/**
		 * Akibara → Descuentos compara lo enviado con lo guardado y ya avisa de
		 * cada option que no entró con un único texto: «espera a que termine y
		 * vuelve a guardar». Para la causa «ocupado» ese texto es verdad, y los dos
		 * avisos juntos decían lo mismo dos veces.
		 *
		 * (Hasta la revisión de 0.1.63 este caso escribía `enabled = yes` sin
		 * proveedor de precio: un valor INVÁLIDO, no «ocupado». La prueba daba
		 * por buena justo la mudez que dejaba al dueño reintentando sin fin.)
		 *
		 * @testdox Durante un guardado del panel de Akibara, «ocupado» no se duplica y business lo sigue nombrando.
		 */
		public function test_the_business_panel_save_is_not_told_twice(): void {
			$publication = $this->reconcile_runtime();
			$this->db->raw_options['akibara_taxonomy_discount_apply_to'] = 'instock_only';
			$this->capture_notices();
			$this->db->get_lock = '0'; // El cerco lo tiene otro proceso: causa «ocupado».
			$this->hook_business_panel();
			$this->reset_production_latches();

			$publication->filter_pre_update_option( 'all', 'akibara_taxonomy_discount_apply_to', 'instock_only' );

			self::assertSame( '', $this->render_notices(), 'El panel de business ya avisa de esta misma option, y con la causa correcta.' );
			self::assertSame(
				array( 'akibara_taxonomy_discount_apply_to' ),
				EditorialDiscountPublication::filter_business_options_not_saved( array( 'akibara_taxonomy_discount_apply_to' ) ),
				'Business la sigue nombrando: su «espera y vuelve a guardar» es verdad para esta causa.'
			);
			self::assertSame( array( 'option_editorial_rechazada' ), $this->audit_actions(), 'El rastro para WP-CLI sí queda.' );

			unset( $GLOBALS['wp_filter']['pre_update_option'] );
		}

		/**
		 * Hallazgo A1 de la revisión de 0.1.63: el aviso se callaba para CUALQUIER
		 * causa dentro del panel de business, y business pinta siempre «espera a
		 * que termine y vuelve a guardar». Con un valor que el lector nunca va a
		 * aceptar, el dueño reintentaba sin fin y ninguna pantalla nombraba el dato.
		 *
		 * @testdox Un valor inválido guardado desde Akibara → Descuentos se avisa con su causa y sin la promesa de business.
		 */
		public function test_an_invalid_value_from_the_business_panel_is_told_with_its_cause(): void {
			$publication = $this->reconcile_runtime();
			$this->db->raw_options['akibara_taxonomy_discount_apply_to'] = 'instock_only';
			$this->capture_notices();
			$this->hook_business_panel();
			$this->reset_production_latches();

			self::assertSame(
				'instock_only',
				$publication->filter_pre_update_option( 'stock', 'akibara_taxonomy_discount_apply_to', 'instock_only' )
			);

			self::assertSame(
				array( 'akb_discount_max_pct' ),
				EditorialDiscountPublication::filter_business_options_not_saved( array( 'akibara_taxonomy_discount_apply_to', 'akb_discount_max_pct' ) ),
				'Business no pinta su «espera y vuelve a guardar» para un valor que nunca va a entrar; lo demás lo sigue nombrando.'
			);
			self::assertSame(
				array( 'akibara_taxonomy_discount_apply_to' ),
				EditorialDiscountPublication::filter_business_options_not_saved( array( 'akibara_taxonomy_discount_apply_to' ) ),
				'La retirada es de ESTE guardado: no puede silenciar el siguiente.'
			);

			$html = $this->render_notices();
			self::assertStringContainsString( 'a qué productos se aplica el descuento', $html );
			self::assertStringContainsString( 'no podría leer la configuración de precios', $html );
			self::assertStringNotContainsString( 'espera un par de minutos', $html );

			unset( $GLOBALS['wp_filter']['pre_update_option'] );
		}

		/**
		 * Hallazgo B3: el veto del % por editorial devolvía false y nadie lo miraba
		 * —business `Settings::set_term_pct()` ignora el retorno—, así que el dueño
		 * guardaba en Akibara → Descuentos y seguía viendo el porcentaje viejo sin
		 * una palabra. Este aviso NO se calla en el panel: ese canal sólo compara
		 * options, no term-meta.
		 *
		 * @testdox El % por editorial vetado deja causa registrada aunque venga del panel.
		 */
		public function test_a_vetoed_term_percentage_is_reported(): void {
			$publication = $this->reconcile_runtime();
			$this->capture_notices();
			$this->db->get_lock = '0';
			$this->hook_business_panel();
			$this->reset_production_latches();

			self::assertFalse(
				$publication->filter_update_term_metadata( null, 306, EditorialDiscountPublication::META_KEY, 5, '' )
			);

			$html = $this->render_notices();
			self::assertStringContainsString( 'el % de descuento de una editorial', $html );
			self::assertStringContainsString( 'espera un par de minutos', $html );
			self::assertSame( array( 'option_editorial_rechazada' ), $this->audit_actions() );
			self::assertStringContainsString( 'del término 306', $this->audit_details()[0] );

			unset( $GLOBALS['wp_filter']['pre_update_option'] );
		}

		/**
		 * Pasar de «UTC-3» a «America/Santiago» escribe primero `gmt_offset` = ''
		 * —ilegible con la zona todavía vacía— y después la zona. La zona se
		 * guardó y es lo único que el dueño ve: avisar «no se guardó la zona
		 * horaria» era mentir sobre un valor que WordPress ya ignora.
		 *
		 * @testdox Un gmt_offset rechazado no se avisa si la zona quedó con nombre.
		 */
		public function test_a_rejected_gmt_offset_is_silent_once_the_zone_has_a_name(): void {
			$publication = $this->reconcile_runtime();
			$this->db->raw_options['gmt_offset'] = '-3';
			$this->capture_notices();
			$this->reset_production_latches();

			self::assertSame( '-3', $publication->filter_pre_update_option( '', 'gmt_offset', '-3' ) );

			$this->db->raw_options['timezone_string'] = 'America/Santiago';
			self::assertSame( '', $this->render_notices() );
		}

		/** @testdox La señal de una reparación también se retira cuando el re-sellado prueba que no movió precios. */
		public function test_the_repair_signal_is_withdrawn_after_the_reseal(): void {
			$publication = $this->reconcile_runtime();
			$this->seed_pending_rebuild( 'option_repair_akibara_taxonomy_discount_to', Install::DB_VERSION );

			self::assertTrue( $publication->reconcile_fingerprint() );

			self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options );
			self::assertContains( 'rebuild_editorial_retirado', $this->audit_actions() );
		}

		/**
		 * @testdox Con la configuración ilegible, status() nombra el dato y la pantalla donde se corrige.
		 */
		public function test_status_names_the_unreadable_value_and_where_to_fix_it(): void {
			$this->reconcile_runtime();
			$this->db->raw_options['akibara_taxonomy_discount_to'] = '2026-13-01';
			$this->reset_production_latches();

			$status = EditorialDiscountPublication::status();

			self::assertSame( 'source_snapshot_unreadable', $status['error'] );
			self::assertSame(
				array( array( 'label' => 'la fecha de término del descuento', 'where' => 'Akibara → Descuentos' ) ),
				$status['broken_options']
			);
		}

		/** @testdox Con la configuración legible, status() no paga la búsqueda del dato roto. */
		public function test_a_readable_status_has_no_broken_options(): void {
			$this->reconcile_runtime();
			$this->reset_production_latches();

			self::assertSame( array(), EditorialDiscountPublication::status()['broken_options'] );
		}

		/**
		 * Hallazgo M-1 de la revisión de 0.1.63, medido en el espejo con
		 * `update_option( 'gmt_offset', '' )` y la fila ausente: el marco se montó
		 * (revisión subida, productos marcados), el UPDATE no tocó filas, Core no
		 * disparó `updated_option` y el cierre de la petición tiró el marco sin
		 * soltar el estrangulador. El servido quedaba cerrado hasta 60 s más el
		 * reproof. Aquí se mide lo mismo con el protocolo real.
		 *
		 * @testdox Un marco que Core nunca confirmó reabre el servido en las peticiones siguientes, sin rebuild.
		 */
		public function test_a_frame_core_never_confirmed_reopens_serving_without_a_rebuild(): void {
			$this->install_healthy_bundle();
			list( $publication, $work, $indexer ) = $this->staging_runtime();
			$this->reset_production_latches();
			self::assertIsString( EditorialDiscountPublication::capture_serving_token(), 'Precondición: la tienda sirve.' );

			$this->db->raw_options['akb_search_editorial_reconcile_at'] = (string) time();
			$this->reset_production_latches();
			self::assertSame( 'all', $publication->filter_pre_update_option( 'all', 'akibara_taxonomy_discount_apply_to', false ) );
			// Core NO escribe: el UPDATE no tocó filas y `updated_option` nunca llega.
			self::assertSame( 2, $this->editorial_state()['wanted_seq'], 'El marco montó su deuda.' );

			// Sin soltar la marca, la petición siguiente queda estrangulada y no cierra nada.
			$estrangulada = $this->vigilar_publicacion( new EditorialDiscountPublication( $work, $indexer, new WriteFence( $this->db, 'akb-editorial-otra' ) ) );
			$this->reset_production_latches();
			$estrangulada->reconcile_on_init();
			self::assertSame( 0, $this->editorial_state()['purge_post_seq'], 'El defecto medido: nadie cierra el marco mientras dure la ventana.' );
			self::assertNull( EditorialDiscountPublication::capture_serving_token() );

			$publication->release_on_shutdown();
			self::assertSame( '0', (string) $this->db->raw_options['akb_search_editorial_reconcile_at'], 'El cierre suelta el estrangulador.' );
			self::assertContains( 'marco_editorial_sin_cerrar', $this->audit_actions() );

			$siguiente = $this->vigilar_publicacion( new EditorialDiscountPublication( $work, $indexer, new WriteFence( $this->db, 'akb-editorial-siguiente' ) ) );
			$this->reset_production_latches();
			$siguiente->reconcile_on_init();
			// Cinco segundos después, que es el reintento que deja un servido caído.
			$this->db->raw_options['akb_search_editorial_reconcile_at'] = (string) ( time() - 60 );
			$this->reset_production_latches();
			$siguiente->reconcile_on_init();

			$this->reset_production_latches();
			self::assertIsString( EditorialDiscountPublication::capture_serving_token(), 'El servido vuelve sin reconstruir.' );
			self::assertContains( 'receipt_resellado_sin_cambio_de_precio', $this->audit_actions() );
		}

		/**
		 * La misma familia que M-1, por otra entrada: el primer CAS de
		 * `stage_locked()` sube la revisión y DESPUÉS falla la escritura de
		 * trabajo. Hasta 0.1.63 la escritura se vetaba como «ocupado» con la
		 * revisión ya subida —servido cerrado— y el estrangulador sin soltar.
		 *
		 * @testdox Una revisión que subió sin cerrar su deuda suelta el estrangulador y el servido vuelve.
		 */
		public function test_a_revision_left_half_staged_releases_the_throttle(): void {
			$this->install_healthy_bundle();
			list( $publication ) = $this->staging_runtime();
			$this->db->raw_options['akb_search_editorial_reconcile_at'] = (string) time();
			$this->db->fail_work_writes = true;
			$this->reset_production_latches();

			self::assertFalse(
				$publication->filter_pre_update_option( 'all', 'akibara_taxonomy_discount_apply_to', false ),
				'La escritura se veta.'
			);

			$estado = $this->editorial_state();
			self::assertSame( 2, $estado['wanted_seq'], 'El estado a medias: la revisión YA subió…' );
			self::assertFalse( $estado['debt_staged'], '…sin su deuda, así que el servido no casa.' );
			self::assertSame(
				'0',
				(string) $this->db->raw_options['akb_search_editorial_reconcile_at'],
				'Sin soltar la marca, el reconcile que retoma la deriva espera la ventana entera con el buscador cerrado.'
			);
			self::assertContains( 'deuda_editorial_a_medias', $this->audit_actions() );
			self::assertStringContainsString( 'marcar el trabajo live', implode( ' ', $this->audit_details() ) );

			// MySQL se recupera: las peticiones siguientes retoman la revisión.
			$this->db->fail_work_writes = false;
			$servido = null;
			for ( $peticion = 0; $peticion < 5 && null === $servido; ++$peticion ) {
				$this->db->raw_options['akb_search_editorial_reconcile_at'] = (string) ( time() - 60 );
				$this->reset_production_latches();
				$publication->reconcile_on_init();
				$this->reset_production_latches();
				$servido = EditorialDiscountPublication::capture_serving_token();
			}
			self::assertIsString( $servido, 'El reconcile retoma la revisión y el servido vuelve.' );
		}

		/**
		 * Hallazgo M-3 de la revisión de 0.1.63: la baja no pasaba por el lector.
		 * Con Business activo, el contrato del feed es obligatorio para leer la
		 * foto; borrarlo dejaba el buscador en 503 y sin aviso.
		 *
		 * @testdox Borrar el contrato del feed con Business activo se veta y la caché de options vuelve a la verdad.
		 */
		public function test_deleting_the_feed_contract_with_business_active_is_vetoed(): void {
			$publication = $this->reconcile_runtime();
			$this->install_business_provider();
			$this->db->raw_options['akibara_business_search_feed_contract_version'] = '2';
			$this->reset_production_latches();
			$estado = $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ];

			$publication->before_delete_option( 'akibara_business_search_feed_contract_version' );

			$delete = "DELETE FROM `wp_options` WHERE `option_name` = 'akibara_business_search_feed_contract_version'";
			self::assertSame( '', $publication->filter_blocked_option_write( $delete ), 'Una consulta vacía hace que delete_option() devuelva false.' );
			self::assertSame( $estado, $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ], 'Ni deuda ni revisión nueva.' );
			self::assertArrayNotHasKey( Install::OPTION_PENDING_REBUILD, $this->db->raw_options );
			self::assertSame( array( 'option_editorial_rechazada' ), $this->audit_actions() );
			self::assertStringContainsString( 'invalido', $this->audit_details()[0] );

			// `delete_option()` marca la option como inexistente en caché aunque el
			// DELETE falle: al cerrar la petición, esa mentira se retira.
			$borradas = array();
			Functions\when( 'wp_cache_delete' )->alias(
				static function ( $clave, $grupo = '' ) use ( &$borradas ): bool {
					$borradas[] = $grupo . ':' . $clave;
					return true;
				}
			);
			$publication->release_on_shutdown();
			self::assertContains( 'options:notoptions', $borradas );
			self::assertContains( 'options:akibara_business_search_feed_contract_version', $borradas );
		}

		/** @testdox Borrar la fila ilegible que tumbaba la foto se acepta como reparación y no se anula. */
		public function test_deleting_an_unreadable_row_repairs_the_config(): void {
			$publication = $this->reconcile_runtime();
			$this->db->raw_options['akibara_taxonomy_discount_apply_to'] = 'stock';
			$this->reset_production_latches();

			$publication->before_delete_option( 'akibara_taxonomy_discount_apply_to' );

			$delete = "DELETE FROM `wp_options` WHERE `option_name` = 'akibara_taxonomy_discount_apply_to'";
			self::assertSame( $delete, $publication->filter_blocked_option_write( $delete ) );
			self::assertSame( 'option_repair_akibara_taxonomy_discount_apply_to', Install::pending_rebuild_request()['reason'] ?? null );
			self::assertSame( array( 'option_editorial_reparada' ), $this->audit_actions() );
		}

		/**
		 * B-1: el veto se ancla a la forma EXACTA de Core. Un INSERT propio de otro
		 * código con la misma option —otra forma de columnas— no es la llamada
		 * vetada y no se toca.
		 *
		 * @testdox El veto de un alta no anula consultas que no son el INSERT de add_option().
		 */
		public function test_the_add_veto_is_anchored_to_the_core_statement(): void {
			$publication = $this->reconcile_runtime();
			$this->reset_production_latches();

			$publication->before_add_option( 'woocommerce_price_num_decimals', '10' );

			$otra_forma = "INSERT INTO `wp_options` (option_name, option_value, autoload) VALUES ('woocommerce_price_num_decimals', '10', 'no')";
			self::assertSame( $otra_forma, $publication->filter_blocked_option_write( $otra_forma ) );
			// Fin de la petición: Core no llegó a insertar y el veto se suelta, igual
			// que en producción. Sin esto la guarda de marcos abiertos lo reclama.
			$publication->release_on_shutdown();
		}

		/** @testdox El filtro `query` sólo vive mientras hay un veto pendiente. */
		public function test_the_query_filter_is_removed_once_the_veto_is_spent(): void {
			$publication = $this->reconcile_runtime();
			$this->reset_production_latches();
			$callback = array( $publication, 'filter_blocked_option_write' );

			$publication->before_add_option( 'woocommerce_price_num_decimals', '10' );
			self::assertNotFalse( has_filter( 'query', $callback ), 'Enganchado tras el veto.' );

			$publication->filter_blocked_option_write( "INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES ('woocommerce_price_num_decimals', '10', 'auto')" );
			self::assertFalse( has_filter( 'query', $callback ), 'Sin vetos pendientes, cada consulta del sitio deja de pasar por aquí.' );
		}

		/**
		 * B-1: si la consulta vetada nunca se ejecuta (otro listener cortó la
		 * llamada), el veto no puede quedarse esperando y anular la siguiente
		 * escritura LEGÍTIMA de la misma option.
		 *
		 * @testdox Un veto que no llegó a ejecutarse no anula la siguiente alta válida de la misma option.
		 */
		public function test_a_stale_veto_does_not_block_the_next_valid_add(): void {
			$publication = $this->reconcile_runtime();
			$this->reset_production_latches();

			$publication->before_add_option( 'woocommerce_price_num_decimals', '10' );
			// El INSERT de '10' no llega. Ahora la misma option con un valor válido:
			$this->db->get_lock = '0';
			$this->reset_production_latches();
			$publication->before_add_option( 'woocommerce_price_num_decimals', '2' );

			$valida = "INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES ('woocommerce_price_num_decimals', '2', 'auto')";
			self::assertSame( $valida, $publication->filter_blocked_option_write( $valida ) );
		}

		// ------------------------------------------------------------------ arnés

		/** @var array<string, mixed> Transients del arnés de avisos. */
		private array $transients = array();

		/** @var mixed `RebuildJob::$runtime` previo, devuelto en tearDown. */
		private $rebuild_runtime_backup = null;

		private bool $rebuild_runtime_saved = false;

		/**
		 * Protocolo completo: RebuildJob y la publicación comparten el cerco, así
		 * `stage_locked()` monta un marco de verdad (sin RebuildJob,
		 * `active_pre_swap_run_id()` da null y ningún marco llega a montarse).
		 *
		 * @return array{0: EditorialDiscountPublication, 1: WorkRepository, 2: Indexer}
		 */
		private function staging_runtime(): array {
			$runtime = new ReflectionProperty( RebuildJob::class, 'runtime' );
			$runtime->setAccessible( true );
			if ( ! $this->rebuild_runtime_saved ) {
				$this->rebuild_runtime_backup = $runtime->getValue();
				$this->rebuild_runtime_saved  = true;
			}
			$work    = new WorkRepository( $this->db, 'wp_akb_search_work' );
			$fence   = new WriteFence( $this->db, 'akb-editorial-option' );
			$indexer = new Indexer( new DocumentBuilder(), $work, $fence, new ProductSourceAuthority( $this->db, static fn( array $ids ): array => $ids ) );
			new RebuildJob( $indexer, $work, $fence );

			return array( $this->vigilar_publicacion( new EditorialDiscountPublication( $work, $indexer, $fence ) ), $work, $indexer );
		}

		/** Engancha el filtro que marca un guardado del panel de Business. */
		private function hook_business_panel(): void {
			$GLOBALS['wp_filter']['pre_update_option'] = (object) array(
				'callbacks' => array(
					10 => array(
						'panel' => array(
							'function'      => array( $this->business_panel(), 'skip_untouched_default' ),
							'accepted_args' => 3,
						),
					),
				),
			);
		}

		/**
		 * Proveedor de precio de Business —real o doble— sin constructor: para el
		 * veredicto sólo cuenta que ocupe el filtro con su clase y sus métodos.
		 */
		private function install_business_provider(): void {
			$clase = 'Akibara\\Business\\Modules\\TaxonomyDiscount\\SearchPriceProvider';
			if ( ! class_exists( $clase ) ) {
				self::markTestSkipped( 'SearchPriceProvider no disponible en este runner.' );
			}
			$GLOBALS['wp_filter']['akb_search_effective_price'] = (object) array(
				'callbacks' => array(
					10 => array(
						'provider' => array(
							'function'      => ( new \ReflectionClass( $clase ) )->newInstanceWithoutConstructor(),
							'accepted_args' => 2,
						),
					),
				),
			);
		}

		/**
		 * La tienda sana: revisión editorial publicada y recibo al día con la foto.
		 */
		private function install_healthy_bundle(): void {
			$state                   = $this->editorial_state();
			$state['wanted_seq']     = 1;
			$state['purge_pre_seq']  = 1;
			$state['purge_post_seq'] = 1;
			$state['reason']         = 'test-healthy';
			$this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ] = (string) json_encode( $state );

			$receipt                = $this->sealed_receipt();
			$receipt['fingerprint'] = $this->source_fingerprint();
			$this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] = serialize( $receipt );
		}

		/** @return array<string, mixed> Estado editorial crudo. */
		private function editorial_state(): array {
			return (array) json_decode( (string) $this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ], true );
		}

		/**
		 * Instancia del panel de Business —real o doble— sin pasar por su
		 * constructor: lo único que importa es el nombre de la clase y el método.
		 *
		 * @return object
		 */
		private function business_panel(): object {
			return ( new \ReflectionClass( \Akibara\Business\Modules\AdminPanel\AdminPanel::class ) )->newInstanceWithoutConstructor();
		}

		/** Stubea los transients y el usuario para poder leer el aviso del dueño. */
		private function capture_notices(): void {
			$this->transients = array();
			Functions\when( 'get_current_user_id' )->justReturn( 9 );
			Functions\when( 'esc_html' )->returnArg();
			// Cierres con referencia, no funciones flecha: la flecha copia el array
			// al crearse y nunca vería lo que escribe set_transient.
			Functions\when( 'set_transient' )->alias(
				function ( string $clave, $valor ): bool {
					$this->transients[ $clave ] = $valor;
					return true;
				}
			);
			Functions\when( 'get_transient' )->alias(
				function ( string $clave ) {
					return $this->transients[ $clave ] ?? false;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				function ( string $clave ): bool {
					unset( $this->transients[ $clave ] );
					return true;
				}
			);
		}

		/** Pinta los avisos pendientes y devuelve su HTML. */
		private function render_notices(): string {
			ob_start();
			EditorialDiscountPublication::render_rejected_write_notice();

			return (string) ob_get_clean();
		}

		/** Runtime editorial con los colaboradores reales (nada mockeado del propio buscador). */
		private function reconcile_runtime(): EditorialDiscountPublication {
			$work    = new WorkRepository( $this->db, 'wp_akb_search_work' );
			$fence   = new WriteFence( $this->db, 'akb-editorial-option' );
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

		/** Señal de rebuild pendiente tal como la deja `Install::request_rebuild()`. */
		private function seed_pending_rebuild( string $reason, string $version ): void {
			$this->db->raw_options[ Install::OPTION_PENDING_REBUILD ] = serialize(
				array(
					'protocol' => 2,
					'requests' => array(
						array(
							'token'        => 'token-de-la-escritura',
							'seq'          => 1,
							'version'      => $version,
							'reason'       => $reason,
							'requested_at' => 1700000000,
							'not_before'   => 1700000010,
						),
					),
				)
			);
		}

		/** Fingerprint de la configuración editorial VIVA (la nueva). */
		private function source_fingerprint(): string {
			$options_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_options_from_bundle' );
			$options_method->setAccessible( true );
			$source_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_snapshot_from_options' );
			$source_method->setAccessible( true );
			$options = $options_method->invoke( null, array( Install::OPTION_INDEX_GEN => 'generation-a' ) );
			$source  = $source_method->invoke( null, $options, array( 'meta' => array(), 'attributes' => array() ) );
			self::assertIsArray( $source );
			return (string) $source['fingerprint'];
		}

		/** @return array<string, mixed> Receipt tal como quedó sellado por el último swap. */
		private function sealed_receipt(): array {
			$now = 1700000000;
			return array(
				'protocol'                => 1,
				'contract'                => 'akb-editorial-materialization-v1',
				'run_id'                  => 'run-ready-1',
				'wanted_seq'              => 1,
				'fingerprint'             => self::RECEIPT_FINGERPRINT,
				'swapped_at'              => $now,
				'published_at'            => $now,
				'db_version'              => Install::DB_VERSION,
				'schema_seal'             => Install::schema_seal(),
				'coverage'                => array( 'eligible' => 3, 'docs' => 3, 'missing' => 0, 'extra' => 0, 'orphans' => 0 ),
				'generation_target'       => 'generation-a',
				'semantic_fingerprint'    => $this->semantic_fingerprint(),
				'publication_acked'       => true,
				'purge_all_dispatched_at' => $now,
			);
		}

		private function semantic_fingerprint(): string {
			$semantic = SemanticPublication::fingerprints_from_raw_bundle( array( Install::OPTION_INDEX_GEN => 'generation-a' ) );
			self::assertIsArray( $semantic );
			return (string) $semantic['semantic'];
		}

		/**
		 * El mundo EXACTO que dejó el bug: índice íntegro y publicado, y el state
		 * una revisión editorial por delante del receipt porque alguien tocó una
		 * option de descuento.
		 */
		private function install_editorial_debt_bundle(): void {
			$now   = 1700000000;
			$state = array(
				'protocol'           => 1,
				'contract'           => 'akb-editorial-materialization-v1',
				'wanted_seq'         => 2,
				'wanted_fingerprint' => $this->source_fingerprint(),
				'window_active'      => false,
				'next_boundary_at'   => null,
				'source_term_ids'    => array(),
				'debt_staged'        => true,
				'active_run_id'      => '',
				'purge_pre_seq'      => 2,
				'purge_post_seq'     => 2,
				'reason'             => 'option_update_akibara_taxonomy_discount_enabled',
				'updated_at'         => $now,
			);

			$this->db->raw_options = array(
				Install::OPTION_INDEX_GEN                    => 'generation-a',
				Install::OPTION_KEY                          => Install::DB_VERSION,
				Install::OPTION_SCHEMA_SEAL                  => Install::schema_seal(),
				EditorialDiscountPublication::OPTION_STATE   => (string) json_encode( $state ),
				EditorialDiscountPublication::OPTION_RECEIPT => serialize( $this->sealed_receipt() ),
				SemanticPublication::OPTION_FINGERPRINT      => serialize(
					array( 'protocol' => 1, 'generation' => 'generation-a', 'fingerprint' => $this->semantic_fingerprint(), 'published_at' => $now )
				),
				RestCache::OPTION_PURGE_RECEIPT              => serialize(
					array( 'protocol' => 1, 'generation_target' => 'generation-a', 'tag' => RestCache::TAG, 'ack_scope' => 'local_dispatch_only', 'dispatched_at' => $now )
				),
			);
			unset( $this->db->raw_options[ RebuildJob::OPTION_STATE ] );
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
				'precise_panel_rejections'  => array(),
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
}
