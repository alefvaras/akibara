<?php
/**
 * Reconciler — verificación periódica índice ↔ catálogo real.
 *
 * Es PERMANENTE, no andamiaje temporal: es la condición del diseño para
 * retirar los watchdogs de FacetWP (HealthCheck/IndexerLifecycle) en la fase
 * de erradicación. Tres chequeos:
 *   1. Censo: COUNT(docs) vs COUNT(productos publish) — faltantes se indexan,
 *      sobrantes se borran (hasta un cap por corrida). Más dos detectores
 *      determinísticos: docs stale (post_modified_gmt > indexed_at) y facetas
 *      cuyo term_id ya no existe (borrar un término NO mueve
 *      post_modified_gmt: sin este chequeo nada lo vería hasta el rebuild).
 *   2. Spot-check: N productos al azar re-construidos y comparados campo a
 *      campo (stock_rank, price_effective, visible + los materializados
 *      url/thumb_url/price_html) — la clase de bug "meta stale / counts que
 *      mienten" que este proyecto ya sufrió.
 *   3. Retención del qlog: 180 días + cap duro de filas.
 *
 * Divergencia ⇒ auto-reindex de los divergentes + transient para la tarjeta
 * del AdminDashboard (fase admin UI) + resumen en option.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

use Akibara\Search\Support\RecurringMemo;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class Reconciler {

	/** Hook recurrente diario. */
	public const HOOK = 'akb_search_reconcile';

	/** Transient de alerta para el AdminDashboard. */
	public const ALERT_TRANSIENT = 'akb_search_reconcile_alert';

	/** Option con el resumen de la última corrida (autoload no). */
	public const OPTION_LAST = 'akb_search_reconcile_last';

	/** Último intento fallido; separado para no vestir un parcial como éxito. */
	public const OPTION_LAST_ERROR = 'akb_search_reconcile_last_error';

	/**
	 * Memo de agendamiento recurrente: as_next_scheduled_action emite consultas
	 * a wp_actionscheduler_actions en cada request front dinámico. El transient
	 * amortigua la verificación reduciéndola a un lookup de options.
	 *
	 * Cuánto dura lo decide {@see RecurringMemo::ttl()} para garantizar que si
	 * la acción desaparece, la revisión diaria sea repuesta en el ciclo previsto.
	 */
	private const SCHEDULED_MEMO = 'akb_search_reconciler_scheduled';

	/** Cada cuánto corre la revisión. Gobierna el agendado Y la vida del memo. */
	private const RUN_INTERVAL = DAY_IN_SECONDS;

	/** Productos re-verificados al azar por corrida. */
	private const SPOT_CHECK_SIZE = 10;

	/** Máximo de faltantes/sobrantes corregidos por corrida. */
	private const REPAIR_CAP = 100;

	/** Retención del qlog en días. */
	private const QLOG_RETENTION_DAYS = 180;

	/** Cap duro de filas del qlog. */
	private const QLOG_MAX_ROWS = 50000;

	/** @var Indexer */
	private Indexer $indexer;

	/** @var DocumentBuilder */
	private DocumentBuilder $builder;

	/** Fuente compartida y observable previa a wc_get_product(). */
	private ProductSourceAuthority $source_authority;

	private string $prepare_error = '';

	/**
	 * @param Indexer         $indexer Indexer.
	 * @param DocumentBuilder             $builder          Builder para el spot-check.
	 * @param ProductSourceAuthority|null $source_authority Autoridad compartida.
	 */
	public function __construct( Indexer $indexer, DocumentBuilder $builder, ?ProductSourceAuthority $source_authority = null ) {
		global $wpdb;
		$this->indexer          = $indexer;
		$this->builder          = $builder;
		$this->source_authority = $source_authority ?? new ProductSourceAuthority( $wpdb );
		$this->builder->set_source_authority( $this->source_authority );
	}

	/**
	 * @return void
	 */
	public function register_handlers(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Agenda la corrida diaria si no existe. Memoizado (E.4): el transient
	 * evita consultar Action Scheduler en cada request; cuando expira —como
	 * mucho una hora después, ver {@see RecurringMemo}— la siguiente request
	 * re-verifica y, si alguien borró la recurrente, la re-agenda. Mismo patrón
	 * de auto-sanación del resto del módulo.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( 'yes' === get_transient( self::SCHEDULED_MEMO ) ) {
			return;
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( self::HOOK, array(), Indexer::AS_GROUP ) ) {
			$action_id = as_schedule_recurring_action( time() + HOUR_IN_SECONDS, self::RUN_INTERVAL, self::HOOK, array(), Indexer::AS_GROUP );
			if ( ! is_int( $action_id ) || 0 >= $action_id ) {
				return; // Sin action durable, no mentir con el memo positivo.
			}
		}
		set_transient( self::SCHEDULED_MEMO, 'yes', RecurringMemo::ttl( self::RUN_INTERVAL ) );
	}

	/**
	 * Corrida completa. Devuelve el resumen (lo consume también WP-CLI).
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$summary = array(
			'ran_at'        => time(),
			'missing'       => 0,
			'orphaned'      => 0,
			'stale'         => 0,
			'orphan_facets' => 0,
			'divergent'     => 0,
			'checked'       => 0,
			'arrival_seeded' => 0,
			'repair_queued'  => 0,
			'ok'              => false,
			'error'           => '',
		);

		if ( ! Install::runtime_schema_ready() ) {
			return $this->failed_summary( $summary, 'runtime_schema_not_ready' );
		}

		// Con la indexación desactivada en ajustes, la revisión periódica no altera
		// el índice para preservar la decisión administrativa. Sin embargo, sí ejecuta
		// la poda del registro de consultas (qlog). No se alteran los registros de
		// última revisión del índice.
		if ( Search::indexing_switched_off() ) {
			$poda = $this->prune_qlog();
			if ( null !== $poda ) {
				return $this->failed_summary( $summary, $poda[0], $poda[1] );
			}
			$summary['ok']      = true;
			$summary['skipped'] = 'indexing_off';

			return $summary;
		}

		$price_capture = EditorialDiscountPublication::capture_price_authority();
		if ( ! is_array( $price_capture ) ) {
			return $this->failed_summary( $summary, 'price_authority_capture_failed' );
		}

		global $wpdb;

		$docs_table  = Install::docs_table();
		$repair_ids = array();

		// 1a. Faltantes: publicados sin doc.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identificadores internos seguros, valores preparados.
		$missing = $this->prepared_ids(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$docs_table} d ON d.post_id = p.ID
				WHERE p.post_type = %s AND p.post_status = %s AND d.post_id IS NULL
				LIMIT %d",
				'product',
				'publish',
				self::REPAIR_CAP
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $missing ) {
			return $this->failed_summary( $summary, 'missing_read_failed', $this->sql_error() );
		}
		$summary['missing'] = count( $missing );
		$repair_ids         = array_merge( $repair_ids, $missing );

		// 1b. Sobrantes: docs cuyo post ya no es producto publicado.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphaned = $this->prepared_ids(
				"SELECT d.post_id FROM {$docs_table} d
				LEFT JOIN {$wpdb->posts} p ON p.ID = d.post_id AND p.post_type = %s AND p.post_status = %s
				WHERE p.ID IS NULL
				LIMIT %d",
				'product',
				'publish',
				self::REPAIR_CAP
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $orphaned ) {
			return $this->failed_summary( $summary, 'orphaned_read_failed', $this->sql_error() );
		}
		$summary['orphaned'] = count( $orphaned );
		$repair_ids          = array_merge( $repair_ids, $orphaned );

		// 1c. Desactualizados (stale): docs cuyo post se modificó después de su indexed_at.
		// Corrige desalineaciones determinísticas (por ejemplo, mutaciones fuera de hooks de guardado).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stale = $this->prepared_ids(
				"SELECT d.post_id FROM {$docs_table} d
				JOIN {$wpdb->posts} p ON p.ID = d.post_id
				WHERE p.post_modified_gmt > d.indexed_at
				LIMIT %d",
				self::REPAIR_CAP
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $stale ) {
			return $this->failed_summary( $summary, 'stale_read_failed', $this->sql_error() );
		}
		$summary['stale'] = count( $stale );
		$repair_ids       = array_merge( $repair_ids, $stale );

		// 1d. Facetas huérfanas: filas del índice facetario cuyo term_id ya no existe.
		// La eliminación de términos no altera post_modified_gmt; este chequeo
		// repara relaciones desalineadas contra term_taxonomy.
		$facets_table  = Install::facets_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_facets = $this->prepared_ids(
				"SELECT DISTINCT f.post_id FROM {$facets_table} f
				LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = f.term_id
				WHERE f.term_id IS NOT NULL AND tt.term_id IS NULL
				LIMIT %d",
				self::REPAIR_CAP
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $orphan_facets ) {
			return $this->failed_summary( $summary, 'orphan_facets_read_failed', $this->sql_error() );
		}
		$summary['orphan_facets'] = count( $orphan_facets );
		$repair_ids               = array_merge( $repair_ids, $orphan_facets );

		// 2. Muestreo aleatorio campo a campo.
		// Incluye campos materializados (url, thumb_url, price_html) y escalares
		// que pueden variar ante cambios globales (permalinks, thumbnails, reglas de WooCommerce).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sample = $this->prepared_rows(
				"SELECT post_id, stock_rank, price_effective, visible, url, thumb_url, price_html FROM {$docs_table} ORDER BY RAND() LIMIT %d",
				self::SPOT_CHECK_SIZE
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $sample ) {
			return $this->failed_summary( $summary, 'sample_read_failed', $this->sql_error() );
		}

		foreach ( $sample as $row ) {
			$sample_id = is_object( $row ) && property_exists( $row, 'post_id' )
				? self::canonical_positive_id( $row->post_id )
				: null;
			if ( null === $sample_id ) {
				return $this->failed_summary( $summary, 'sample_row_invalid' );
			}

			++$summary['checked'];

			$eligible = $this->source_authority->published_product_ids( array( $sample_id ) );
			if ( ! $this->source_authority->is_available() ) {
				return $this->failed_summary(
					$summary,
					'sample_product_source_failed',
					$this->source_authority->last_error()
				);
			}
			if ( array( $sample_id ) !== $eligible ) {
				$repair_ids[] = $sample_id;
				continue;
			}

			$this->clear_db_error();
			try {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $sample_id ) : null;
			} catch ( \Throwable $exception ) {
				return $this->failed_summary( $summary, 'sample_product_read_failed', $exception->getMessage() );
			}
			if ( '' !== $this->db_error() ) {
				return $this->failed_summary( $summary, 'sample_product_read_failed', $this->db_error() );
			}
			if ( ! $product instanceof \WC_Product ) {
				// La fuente cruda confirmó publish inmediatamente antes. false/null o
				// una forma ajena de Woo es una lectura fallida, no divergencia sana.
				return $this->failed_summary( $summary, 'sample_product_read_failed', 'Published product could not be hydrated.' );
			}

			$this->clear_db_error();
			try {
				$observed_id = $product->get_id();
			} catch ( \Throwable $exception ) {
				return $this->failed_summary( $summary, 'sample_product_read_failed', $exception->getMessage() );
			}
			if ( '' !== $this->db_error() || ( function_exists( 'is_wp_error' ) && is_wp_error( $observed_id ) )
				|| ! is_int( $observed_id ) || $observed_id <= 0 || $observed_id !== $sample_id ) {
				return $this->failed_summary( $summary, 'sample_product_read_failed', 'Sampled product identity disagrees with the authoritative source.' );
			}

			$this->clear_db_error();
			try {
				$observed_status = $product->get_status();
			} catch ( \Throwable $exception ) {
				return $this->failed_summary( $summary, 'sample_product_read_failed', $exception->getMessage() );
			}
			if ( '' !== $this->db_error() || ( function_exists( 'is_wp_error' ) && is_wp_error( $observed_status ) )
				|| ! is_string( $observed_status ) || 'publish' !== $observed_status ) {
				return $this->failed_summary( $summary, 'sample_product_read_failed', 'Sampled product status disagrees with the authoritative source.' );
			}

			try {
				$this->clear_db_error();
				$fresh = $this->builder->build( $product );
			} catch ( \Throwable $exception ) {
				return $this->failed_summary( $summary, 'sample_build_failed', $exception->getMessage() );
			}
			if ( '' !== $this->db_error() ) {
				return $this->failed_summary( $summary, 'sample_build_read_failed', $this->db_error() );
			}

			$doc = is_array( $fresh['doc'] ?? null ) ? $fresh['doc'] : array();
			if ( ! $this->comparable_document( $doc, $row, $sample_id ) ) {
				return $this->failed_summary( $summary, 'sample_document_invalid' );
			}

			// Los materializados se comparan como STRING exacta: ya vienen
			// truncados y saneados por el propio builder, así que cualquier
			// diferencia es índice que miente (URL vieja, thumb de un tamaño
			// que ya no existe, precio con formato/moneda anterior). Medido en
			// Docker antes de subirlo: 200 docs al azar, 0 divergencias — el
			// churn extra sobre el catálogo actual es nulo, y de todos modos
			// queda acotado a SPOT_CHECK_SIZE reindexados por corrida.
			$diverges = (int) $doc['stock_rank'] !== (int) $row->stock_rank
				|| (int) $doc['visible'] !== (int) $row->visible
				|| abs( (float) $doc['price_effective'] - (float) $row->price_effective ) > 0.01
				|| (string) $doc['url'] !== (string) $row->url
				|| (string) $doc['thumb_url'] !== (string) $row->thumb_url
				|| (string) $doc['price_html'] !== (string) $row->price_html;

			if ( $diverges ) {
				++$summary['divergent'];
				$repair_ids[] = $sample_id;
			}
		}
		if ( ! EditorialDiscountPublication::price_authority_matches( $price_capture ) ) {
			return $this->failed_summary( $summary, 'price_authority_changed' );
		}

		// 3. Retención del qlog.
		$poda = $this->prune_qlog();
		if ( null !== $poda ) {
			return $this->failed_summary( $summary, $poda[0], $poda[1] );
		}

		$repair_ids = self::normalize_ids( $repair_ids );
		if ( null === $repair_ids ) {
			return $this->failed_summary( $summary, 'repair_ids_invalid' );
		}
		if ( ! empty( $repair_ids ) ) {
			if ( ! EditorialDiscountPublication::price_authority_matches( $price_capture ) ) {
				return $this->failed_summary( $summary, 'price_authority_changed' );
			}
			if ( ! $this->indexer->enqueue_products( $repair_ids ) ) {
				return $this->failed_summary( $summary, 'repair_enqueue_failed' );
			}
			$summary['repair_queued'] = count( $repair_ids );
		}

		// 4. Backfill de llegadas: marca preventas que existían antes del
		// módulo Arrival (caso masivo: el cutover). Capado; converge en pocas
		// corridas y después es un SELECT vacío.
		try {
			$this->clear_db_error();
			$summary['arrival_seeded'] = Arrival::seed_pending();
		} catch ( \Throwable $exception ) {
			return $this->failed_summary( $summary, 'arrival_seed_failed', $exception->getMessage() );
		}
		if ( '' !== $this->db_error() ) {
			return $this->failed_summary( $summary, 'arrival_seed_failed', $this->db_error() );
		}

		$summary['ok']    = true;
		$summary['error'] = '';

		// Alerta si hubo reparaciones significativas — la tarjeta admin la lee.
		// `stale` y `orphan_facets` cuentan igual que el resto: son índice que
		// mintió, no ruido (HealthReport ya suma `stale` en su `diverged`).
		$issues = $summary['missing'] + $summary['orphaned'] + $summary['stale']
			+ $summary['orphan_facets'] + $summary['divergent'];
		if ( ! EditorialDiscountPublication::price_authority_matches( $price_capture ) ) {
			return $this->failed_summary( $summary, 'price_authority_changed' );
		}
		if ( ! $this->persist_alert( $summary, $issues > 0 ) ) {
			return $this->failed_summary( $summary, 'alert_persist_failed', $this->db_error() );
		}

		if ( ! EditorialDiscountPublication::price_authority_matches( $price_capture ) ) {
			return $this->failed_summary( $summary, 'price_authority_changed' );
		}
		$this->clear_db_error();
		$updated = update_option( self::OPTION_LAST, $summary, false );
		if ( '' !== $this->db_error() ) {
			return $this->failed_summary( $summary, 'summary_persist_failed', $this->db_error() );
		}
		if ( ! $updated ) {
			$this->clear_db_error();
			$stored = get_option( self::OPTION_LAST, null );
			if ( '' !== $this->db_error() || $summary !== $stored ) {
				return $this->failed_summary( $summary, 'summary_persist_failed', $this->db_error() );
			}
		}

		return $summary;
	}

	/**
	 * Poda del registro de búsquedas: retención por días y tope duro de filas.
	 *
	 * Método desacoplado del ciclo de indexación del catálogo para que continúe
	 * ejecutándose de forma autónoma incluso si la indexación está desactivada.
	 *
	 * @return array{0:string,1:string}|null Código y detalle del fallo, o null si fue bien.
	 */
	private function prune_qlog(): ?array {
		$qlog = Install::qlog_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$retention_deleted = $this->prepared_write(
				"DELETE FROM {$qlog} WHERE day < %s",
				QueryLogger::day( self::QLOG_RETENTION_DAYS )
		);
		if ( ! $retention_deleted ) {
			return array( 'qlog_retention_failed', $this->sql_error() );
		}

		$qlog_rows = $this->read_count( "SELECT COUNT(*) FROM {$qlog}" );
		if ( null === $qlog_rows ) {
			return array( 'qlog_count_failed', $this->db_error() );
		}

		if ( $qlog_rows > self::QLOG_MAX_ROWS ) {
			$capped = $this->prepared_write(
					"DELETE FROM {$qlog} ORDER BY day ASC LIMIT %d",
					$qlog_rows - self::QLOG_MAX_ROWS
			);
			if ( ! $capped ) {
				return array( 'qlog_cap_failed', $this->sql_error() );
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null;
	}

	/**
	 * Lee IDs distinguiendo conjunto vacío de error SQL.
	 *
	 * @param string $query Consulta preparada.
	 * @return array<int, int>|null
	 */
	private function prepared_ids( string $query, ...$args ): ?array {
		$prepared = $this->prepare_query( $query, ...$args );
		return null === $prepared ? null : $this->read_ids( $prepared );
	}

	/** @return array<int, object>|null */
	private function prepared_rows( string $query, ...$args ): ?array {
		$prepared = $this->prepare_query( $query, ...$args );
		return null === $prepared ? null : $this->read_rows( $prepared );
	}

	private function prepared_write( string $query, ...$args ): bool {
		$prepared = $this->prepare_query( $query, ...$args );
		return null !== $prepared && $this->write_query( $prepared );
	}

	private function prepare_query( string $query, ...$args ): ?string {
		global $wpdb;

		$this->prepare_error = '';
		$this->clear_db_error();
		try {
			$prepared = $wpdb->prepare( $query, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query es la plantilla con marcadores que pasan los tres llamadores privados de esta clase, y ésta es justamente la llamada a prepare() que la rellena.
		} catch ( \Throwable $throwable ) {
			$this->prepare_error = $throwable->getMessage() ?: 'SQL prepare threw.';
			return null;
		}
		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $prepared ) )
			|| ! is_string( $prepared ) || '' === trim( $prepared ) || '' !== $this->db_error() ) {
			$this->prepare_error = '' !== $this->db_error() ? $this->db_error() : 'SQL prepare returned an invalid value.';
			return null;
		}
		return $prepared;
	}

	private function read_ids( string $query ): ?array {
		global $wpdb;

		$this->clear_db_error();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- el caller entrega SQL ya preparado y tablas internas.
		$rows = $wpdb->get_col( $query );
		if ( ! is_array( $rows ) || '' !== $this->db_error() ) {
			return null;
		}

		return self::normalize_ids( $rows );
	}

	/**
	 * Lee filas distinguiendo conjunto vacío de error SQL.
	 *
	 * @param string $query Consulta preparada.
	 * @return array<int, object>|null
	 */
	private function read_rows( string $query ): ?array {
		global $wpdb;

		$this->clear_db_error();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- el caller entrega SQL ya preparado y tablas internas.
		$rows = $wpdb->get_results( $query );
		if ( ! is_array( $rows ) || '' !== $this->db_error() ) {
			return null;
		}

		return $rows;
	}

	/**
	 * Lee un COUNT que por contrato nunca puede ser NULL.
	 *
	 * @param string $query Consulta.
	 * @return int|null
	 */
	private function read_count( string $query ): ?int {
		global $wpdb;

		$this->clear_db_error();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- COUNT fijo sobre una tabla interna validada.
		$value = $wpdb->get_var( $query );
		if ( null === $value || '' !== $this->db_error() || ! is_numeric( $value ) ) {
			return null;
		}

		return max( 0, (int) $value );
	}

	/**
	 * Ejecuta una escritura donde cero filas afectadas sigue siendo éxito.
	 *
	 * @param string $query Sentencia preparada.
	 * @return bool
	 */
	private function write_query( string $query ): bool {
		global $wpdb;

		$this->clear_db_error();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- el caller entrega SQL ya preparado y tablas internas.
		$result = $wpdb->query( $query );

		return false !== $result && '' === $this->db_error();
	}

	/**
	 * Normaliza IDs fuente antes de materializar deuda durable.
	 *
	 * @param array<int, mixed> $ids IDs crudos.
	 * @return array<int, int>|null
	 */
	private static function normalize_ids( array $ids ): ?array {
		$normalised = array();
		foreach ( $ids as $raw_id ) {
			$id = self::canonical_positive_id( $raw_id );
			if ( null === $id ) {
				return null;
			}
			$normalised[ $id ] = $id;
		}
		$normalised = array_values( $normalised );
		sort( $normalised, SORT_NUMERIC );

		return $normalised;
	}

	/** @param mixed $raw_id */
	private static function canonical_positive_id( $raw_id ): ?int {
		if ( is_int( $raw_id ) ) {
			return $raw_id > 0 ? $raw_id : null;
		}
		if ( is_string( $raw_id ) && 1 === preg_match( '/^[1-9]\d*$/D', $raw_id ) ) {
			$id = (int) $raw_id;
			return $id > 0 && (string) $id === $raw_id ? $id : null;
		}
		return null;
	}

	/**
	 * Verifica que el spot-check compare una fila completa, no defaults PHP.
	 *
	 * @param array<string, mixed> $doc Documento reconstruido.
	 * @param object               $row Fila materializada.
	 * @return bool
	 */
	private function comparable_document( array $doc, object $row, int $sample_id ): bool {
		if ( ! array_key_exists( 'post_id', $doc ) || ! property_exists( $row, 'post_id' )
			|| ! is_int( $doc['post_id'] ) || $doc['post_id'] <= 0 || $doc['post_id'] !== $sample_id ) {
			return false;
		}
		$fields = array( 'stock_rank', 'price_effective', 'visible', 'url', 'thumb_url', 'price_html' );
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $doc ) || ! property_exists( $row, $field ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Persiste o limpia la alerta y verifica el estado resultante.
	 *
	 * @param array<string, mixed> $summary Resumen completo.
	 * @param bool                 $needed  Si hay divergencias.
	 * @return bool
	 */
	private function persist_alert( array $summary, bool $needed ): bool {
		$this->clear_db_error();
		$result = $needed
			? set_transient( self::ALERT_TRANSIENT, $summary, 2 * DAY_IN_SECONDS )
			: delete_transient( self::ALERT_TRANSIENT );
		if ( '' !== $this->db_error() ) {
			return false;
		}
		if ( $result ) {
			return true;
		}

		$this->clear_db_error();
		$stored = get_transient( self::ALERT_TRANSIENT );
		if ( '' !== $this->db_error() ) {
			return false;
		}

		return $needed ? $summary === $stored : false === $stored;
	}

	/**
	 * Devuelve un fallo explícito sin sellarlo como última corrida completa.
	 *
	 * @param array<string, mixed> $summary Resumen parcial.
	 * @param string               $error   Código estable.
	 * @param string               $detail  Detalle acotado.
	 * @return array<string, mixed>
	 */
	private function failed_summary( array $summary, string $error, string $detail = '' ): array {
		$summary['ok']     = false;
		$summary['error']  = $error;
		$detail            = trim( $detail );
		if ( '' !== $detail ) {
			$summary['error_detail'] = function_exists( 'mb_substr' ) ? mb_substr( $detail, 0, 250 ) : substr( $detail, 0, 250 );
		}
		// Diagnóstico separado: OPTION_LAST conserva exclusivamente una corrida
		// completa, mientras este receipt hace observable el fallo en AS/cron.
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_LAST_ERROR, $summary, false );
		}

		return $summary;
	}

	/** Vacía el error anterior para no confundirlo con la consulta actual. */
	private function clear_db_error(): void {
		global $wpdb;

		$wpdb->last_error = '';
	}

	/** @return string Último error SQL normalizado. */
	private function db_error(): string {
		global $wpdb;

		return trim( (string) ( $wpdb->last_error ?? '' ) );
	}

	private function sql_error(): string {
		return '' !== $this->prepare_error ? $this->prepare_error : $this->db_error();
	}
}
