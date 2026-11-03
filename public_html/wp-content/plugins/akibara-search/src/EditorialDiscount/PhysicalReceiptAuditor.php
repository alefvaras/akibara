<?php
/**
 * Auditor de recibos físicos y re-prueba de materialización editorial.
 *
 * @package Akibara\Search\EditorialDiscount
 */

declare( strict_types = 1 );

namespace Akibara\Search\EditorialDiscount;

use Akibara\Search\AuditLog;
use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\Indexer;
use Akibara\Search\Install;
use Akibara\Search\RebuildJob;
use Akibara\Search\SemanticPublication;
use Akibara\Search\WorkRepository;
use Throwable;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Re-prueba hechos físicos del recibo vigente contra las tablas vivas sin pagar un rebuild innecesario.
 *
 * Extraído de EditorialDiscountPublication para aislar la verificación de cobertura de docs,
 * detección de divergencia de precios y aplazamiento de trabajo en vuelo.
 */
final class PhysicalReceiptAuditor {

	/**
	 * Segundos máximos permitidos para aplazar un rebuild por trabajo live.
	 */
	public const REBUILD_DEFER_SECONDS = 600;

	/**
	 * Clave de opción que registra el inicio del reloj de aplazamiento.
	 */
	public const OPTION_DEFER_SINCE = 'akb_search_reproof_deferred_since';

	/**
	 * Repositorio de trabajo (claims en vuelo).
	 */
	private WorkRepository $work;

	/**
	 * Indexador de documentos.
	 */
	private Indexer $indexer;

	/**
	 * Constructor.
	 *
	 * @param WorkRepository $work    Repositorio de trabajo.
	 * @param Indexer        $indexer Indexador de documentos.
	 */
	public function __construct( WorkRepository $work, Indexer $indexer ) {
		$this->work    = $work;
		$this->indexer = $indexer;
	}

	/**
	 * Re-prueba los hechos físicos del receipt vigente contra las tablas
	 * VIVAS y, si el mundo real está íntegro, re-sella coverage y target sin
	 * pagar un rebuild.
	 *
	 * @param array<string, mixed> $source Foto estable de la fuente editorial.
	 * @param array<string, mixed> $state  Estado editorial vigente.
	 * @return bool|null True si el gate quedó re-adoptado y listo; null si es transitorio.
	 */
	public function reprove_physical_receipt( array $source, array $state ): ?bool {
		$raw     = null;
		$receipt = $this->read_raw_receipt( $raw );
		if ( ! is_array( $receipt ) ) {
			return $receipt;
		}

		$editorial_debt = (int) $state['wanted_seq'] !== (int) $receipt['wanted_seq']
			|| ! hash_equals( (string) $source['fingerprint'], (string) $receipt['fingerprint'] );
		if ( $editorial_debt && (int) $state['wanted_seq'] <= (int) $receipt['wanted_seq'] ) {
			return false;
		}

		$generation           = null;
		$semantic_fingerprint = null;
		$generation_seal      = $this->verify_physical_generation_seal( $generation, $semantic_fingerprint );
		if ( true !== $generation_seal ) {
			return $generation_seal;
		}

		$eligible_ids = null;
		$doc_ids      = null;
		if ( true !== $this->fetch_coverage_ids( $eligible_ids, $doc_ids ) ) {
			return null;
		}

		$coverage_result = $this->verify_live_coverage( $eligible_ids, $doc_ids );
		if ( true !== $coverage_result ) {
			return $coverage_result;
		}

		$coverage = array(
			'eligible' => count( $eligible_ids ),
			'docs'     => count( $doc_ids ),
			'missing'  => 0,
			'extra'    => 0,
			'orphans'  => 0,
		);

		if ( ! $editorial_debt
			&& $coverage === ( $receipt['coverage'] ?? null )
			&& $generation === (string) ( $receipt['generation_target'] ?? '' )
			&& hash_equals( (string) $semantic_fingerprint, (string) ( $receipt['semantic_fingerprint'] ?? '' ) ) ) {
			return EditorialDiscountPublication::is_ready_from( $source, $state, $receipt );
		}

		if ( ! EditorialDiscountPublication::publication_ready_except_editorial_revision( $source, $state, $receipt )
			|| ! RebuildJob::publication_barrier_open() ) {
			AuditLog::record( 'receipt_reproof_rechazado', 'Deuda editorial o barrera de rebuild activas: no es sólo el conteo' );
			return false;
		}

		if ( $editorial_debt ) {
			$unchanged = $this->editorial_materialization_is_unchanged( $eligible_ids );
			if ( null === $unchanged ) {
				return null;
			}
			if ( false === $unchanged ) {
				AuditLog::record(
					'receipt_reproof_rechazado',
					sprintf(
						'La configuración editorial %d→%d cambia la materialización: cae al rebuild',
						(int) $receipt['wanted_seq'],
						(int) $state['wanted_seq']
					)
				);
				return false;
			}
		}

		return $this->seal_receipt_update( $receipt, $coverage, (string) $generation, (string) $semantic_fingerprint, $editorial_debt, $state, $source, (string) $raw, count( $eligible_ids ), count( $doc_ids ) );
	}

	/**
	 * Lee e inicializa el recibo actual de opciones para re-probación.
	 *
	 * @param string|null $raw Salida con el valor raw de la base de datos.
	 * @return array<string, mixed>|bool|null Array si es válido; false si ausente o inválido; null si falló lectura.
	 */
	private function read_raw_receipt( ?string &$raw ) {
		global $wpdb;

		if ( ! EditorialDiscountPublication::is_read_authority_available() ) {
			return null;
		}

		$wpdb->last_error = '';
		$raw              = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", EditorialDiscountPublication::OPTION_RECEIPT )
		);
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ( null !== $raw && ! is_string( $raw ) ) ) {
			return null;
		}
		if ( null === $raw || '' === $raw ) {
			return false;
		}

		$receipt = maybe_unserialize( $raw );
		if ( ! EditorialDiscountPublication::valid_receipt( $receipt ) ) {
			return false;
		}

		return $receipt;
	}

	/**
	 * Verifica la presencia y sellado de la generación física y huella semántica.
	 *
	 * @param string|null $generation           Salida con la generación viva.
	 * @param string|null $semantic_fingerprint Salida con la huella semántica.
	 * @return bool|null
	 */
	private function verify_physical_generation_seal( ?string &$generation, ?string &$semantic_fingerprint ): ?bool {
		$generation = EditorialDiscountPublication::live_index_generation();
		if ( null === $generation ) {
			return null;
		}

		$semantic             = EditorialDiscountPublication::read_option_uncached( SemanticPublication::OPTION_FINGERPRINT );
		$semantic_fingerprint = is_array( $semantic ) ? (string) ( $semantic['fingerprint'] ?? '' ) : '';
		if ( '' === $generation
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $semantic_fingerprint )
			|| ! EditorialDiscountPublication::physical_publication_matches( $generation, $semantic_fingerprint ) ) {
			AuditLog::record( 'receipt_reproof_rechazado', 'Publicación física sin sellar para la generación viva' );
			return false;
		}

		return true;
	}

	/**
	 * Obtiene los IDs elegibles y los IDs de la tabla de documentos vivos.
	 *
	 * @param array<int>|null $eligible_ids Salida con IDs elegibles.
	 * @param array<int>|null $doc_ids      Salida con IDs indexados.
	 * @return bool|null
	 */
	private function fetch_coverage_ids( ?array &$eligible_ids, ?array &$doc_ids ): ?bool {
		global $wpdb;

		$eligible_ids = RebuildJob::eligible_product_ids_snapshot();
		if ( ! is_array( $eligible_ids ) ) {
			return null;
		}

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identificador interno, sin valores de usuario.
		$raw_doc_ids = $wpdb->get_col( 'SELECT post_id FROM ' . Install::docs_table() );
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ! is_array( $raw_doc_ids ) ) {
			return null;
		}
		$doc_ids = array_map( 'intval', $raw_doc_ids );

		return true;
	}

	/**
	 * Verifica la cobertura de documentos vivos contra elegibles.
	 *
	 * @param array<int> $eligible_ids IDs elegibles.
	 * @param array<int> $doc_ids      IDs en la tabla de docs.
	 * @return bool|null true si coincide; false si está rota; null si está diferida.
	 */
	private function verify_live_coverage( array $eligible_ids, array $doc_ids ): ?bool {
		$missing = array_diff( $eligible_ids, $doc_ids );
		$extra   = array_diff( $doc_ids, $eligible_ids );
		if ( array() === $missing && array() === $extra && count( $eligible_ids ) === count( $doc_ids ) ) {
			return true;
		}

		foreach ( array_merge( $missing, $extra ) as $product_id ) {
			$claim = $this->work->find( WorkRepository::SCOPE_LIVE, '', (int) $product_id );
			if ( '' !== trim( $this->work->last_error() ) ) {
				AuditLog::record(
					'receipt_reproof_diferido',
					sprintf( 'No se pudo leer el claim live de #%d: próximo ciclo', (int) $product_id )
				);
				return null;
			}
			$status = (string) ( $claim['status'] ?? '' );
			if ( WorkRepository::STATUS_PENDING === $status || WorkRepository::STATUS_LEASED === $status ) {
				AuditLog::record(
					'receipt_reproof_diferido',
					sprintf( 'Claim live en vuelo para #%d (%d faltantes, %d sobrantes): próximo ciclo', (int) $product_id, count( $missing ), count( $extra ) )
				);
				return null;
			}
		}
		AuditLog::record(
			'receipt_reproof_rechazado',
			sprintf( 'Cobertura viva rota: %d faltantes, %d sobrantes — cae al rebuild', count( $missing ), count( $extra ) )
		);
		return false;
	}

	/**
	 * Ejecuta el CAS para sellar la actualización del recibo re-probado.
	 *
	 * @param array<string, mixed> $receipt
	 * @param array<string, mixed> $coverage
	 * @param string               $generation
	 * @param string               $semantic_fingerprint
	 * @param bool                 $editorial_debt
	 * @param array<string, mixed> $state
	 * @param array<string, mixed> $source
	 * @param string               $raw
	 * @param int                  $eligible_count
	 * @param int                  $doc_count
	 * @return bool|null
	 */
	private function seal_receipt_update(
		array $receipt,
		array $coverage,
		string $generation,
		string $semantic_fingerprint,
		bool $editorial_debt,
		array $state,
		array $source,
		string $raw,
		int $eligible_count,
		int $doc_count
	): ?bool {
		global $wpdb;

		$sellado                           = $receipt;
		$sellado['coverage']               = $coverage;
		$sellado['generation_target']      = $generation;
		$sellado['semantic_fingerprint']   = $semantic_fingerprint;
		$sellado['generation_advanced_at'] = time();
		if ( $editorial_debt ) {
			$sellado['wanted_seq']  = (int) $state['wanted_seq'];
			$sellado['fingerprint'] = (string) $source['fingerprint'];
		}

		$wpdb->last_error = '';
		$result           = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
				maybe_serialize( $sellado ),
				EditorialDiscountPublication::OPTION_RECEIPT,
				$raw
			)
		);
		wp_cache_delete( EditorialDiscountPublication::OPTION_RECEIPT, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		EditorialDiscountPublication::invalidate_runtime_memo();
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || 1 !== (int) $result ) {
			return null;
		}

		$generacion_tras_sellar = EditorialDiscountPublication::live_index_generation();
		if ( null === $generacion_tras_sellar || $generacion_tras_sellar !== $generation ) {
			AuditLog::record(
				'receipt_reproof_carrera',
				null === $generacion_tras_sellar
					? 'No se pudo releer la generación tras el re-sellado; próximo ciclo re-prueba'
					: 'La generación se movió durante el re-sellado; próximo ciclo re-prueba'
			);
			return null;
		}

		if ( $editorial_debt ) {
			AuditLog::record(
				'receipt_resellado_sin_cambio_de_precio',
				sprintf(
					'Revisión editorial %d→%d re-sellada: %d elegibles con precio y precio_html idénticos a los ya indexados, cero rebuild',
					(int) $receipt['wanted_seq'],
					(int) $state['wanted_seq'],
					$eligible_count
				)
			);
			self::withdraw_option_rebuild_request( $state );
		} else {
			AuditLog::record(
				'receipt_resellado',
				sprintf( 'Coverage re-probado en vivo (%d docs) y target re-adoptado sin rebuild', $doc_count )
			);
		}

		return EditorialDiscountPublication::is_ready_from(
			EditorialDiscountPublication::stable_source_snapshot(),
			EditorialDiscountPublication::read_state(),
			EditorialDiscountPublication::read_option_uncached( EditorialDiscountPublication::OPTION_RECEIPT )
		);
	}

	/**
	 * Comprueba si la materialización editorial permanece sin cambios para los productos elegibles.
	 *
	 * @param array<int> $eligible_ids Universo elegible ya probado contra docs.
	 * @return bool|null
	 */
	public function editorial_materialization_is_unchanged( array $eligible_ids ): ?bool {
		global $wpdb;

		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$price_capture = EditorialDiscountPublication::capture_price_authority();
		if ( ! is_array( $price_capture ) ) {
			return null;
		}

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identificador interno, sin valores de usuario.
		$rows = $wpdb->get_results( 'SELECT post_id, price_effective, price_html FROM ' . Install::docs_table(), ARRAY_A );
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ! is_array( $rows ) ) {
			return null;
		}
		$indexed = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['post_id'] ) ) {
				return null;
			}
			$indexed[ (int) $row['post_id'] ] = $row;
		}

		$builder   = $this->indexer->document_builder();
		$authority = $this->indexer->source_authority();
		foreach ( $eligible_ids as $product_id ) {
			$product_id = (int) $product_id;
			if ( ! array_key_exists( $product_id, $indexed ) ) {
				return null;
			}
			$fresh = $this->resolve_fresh_materialization( $product_id, $authority, $builder );
			if ( null === $fresh ) {
				return null;
			}
			if ( abs( (float) $indexed[ $product_id ]['price_effective'] - $fresh['price_effective'] ) > 0.01
				|| (string) $indexed[ $product_id ]['price_html'] !== $fresh['price_html'] ) {
				$aplazar = $this->live_work_defers_rebuild( $product_id );
				if ( null === $aplazar || true === $aplazar ) {
					return null;
				}
				self::clear_defer_clock();
				return false;
			}
		}

		if ( ! EditorialDiscountPublication::price_authority_matches( $price_capture ) ) {
			return null;
		}
		self::clear_defer_clock();
		return true;
	}

	/**
	 * Resuelve la materialización de precio fresca para un producto dado.
	 *
	 * @param int       $product_id ID del producto.
	 * @param mixed     $authority  Autoridad de fuente.
	 * @param mixed     $builder    Constructor de documentos.
	 * @return array<string, mixed>|null
	 */
	private function resolve_fresh_materialization( int $product_id, $authority, $builder ): ?array {
		global $wpdb;
		$wpdb->last_error = '';
		try {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				return null;
			}
			if ( true === $product->is_type( 'variable' )
				&& ! $authority->prepare_variable_price_source( $product_id ) ) {
				return null;
			}
			$fresh = $builder->editorial_materialization( $product );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return null;
		}
		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ! is_array( $fresh ) ) {
			return null;
		}
		return $fresh;
	}

	/**
	 * Verifica si el trabajo live en curso justifica aplazar el rebuild para este producto.
	 *
	 * @param int $product_id Producto divergente.
	 * @return bool|null true = en vuelo (aplaza) · false = exige el rebuild · null = error de lectura.
	 */
	public function live_work_defers_rebuild( int $product_id ): ?bool {
		$claim = $this->work->find( WorkRepository::SCOPE_LIVE, '', $product_id );

		if ( '' !== trim( $this->work->last_error() ) ) {
			AuditLog::record(
				'receipt_reproof_diferido',
				sprintf( 'No se pudo leer el claim live de #%d (precio divergente): próximo ciclo', $product_id )
			);
			return null;
		}

		$status = (string) ( $claim['status'] ?? '' );
		if ( WorkRepository::STATUS_PENDING !== $status && WorkRepository::STATUS_LEASED !== $status ) {
			return false;
		}

		if ( WorkRepository::STATUS_LEASED === $status && self::lease_expired( is_array( $claim ) ? $claim : array() ) ) {
			AuditLog::record(
				'receipt_reproof_valvula',
				sprintf( 'Claim live de #%d con arriendo vencido: no es trabajo en vuelo, cae al rebuild', $product_id )
			);
			return false;
		}

		$since = (int) get_option( self::OPTION_DEFER_SINCE, 0 );
		$ahora = time();
		if ( $since <= 0 || $since > $ahora ) {
			update_option( self::OPTION_DEFER_SINCE, $ahora, false );
			AuditLog::record(
				'receipt_reproof_diferido',
				sprintf( 'Precio divergente en #%d con claim live %s: próximo ciclo (reloj de aplazamiento arrancado)', $product_id, $status )
			);
			return true;
		}

		$llevamos = $ahora - $since;
		if ( $llevamos > self::REBUILD_DEFER_SECONDS ) {
			self::clear_defer_clock();
			AuditLog::record(
				'receipt_reproof_valvula',
				sprintf( 'Llevamos %d s aplazando por trabajo live (#%d en %s): deja de aplazarse y cae al rebuild', $llevamos, $product_id, $status )
			);
			return false;
		}

		AuditLog::record(
			'receipt_reproof_diferido',
			sprintf( 'Precio divergente en #%d con claim live %s: próximo ciclo (%d s aplazando)', $product_id, $status, $llevamos )
		);
		return true;
	}

	/**
	 * Limpia el reloj de aplazamiento cuando concluye la resolución.
	 *
	 * @return void
	 */
	public static function clear_defer_clock(): void {
		if ( 0 !== (int) get_option( self::OPTION_DEFER_SINCE, 0 ) ) {
			update_option( self::OPTION_DEFER_SINCE, 0, false );
		}
	}

	/**
	 * Determina si el arriendo del claim ha vencido.
	 *
	 * @param array<string, mixed> $claim Fila del claim.
	 * @return bool
	 */
	public static function lease_expired( array $claim ): bool {
		$hasta = trim( (string) ( $claim['lease_until'] ?? '' ) );
		if ( '' === $hasta ) {
			return false;
		}
		$momento = strtotime( $hasta . ' UTC' );

		return false !== $momento && (int) $momento <= time();
	}

	/**
	 * Retira la señal de rebuild que montó un cambio de opción editorial cuando el reproof prueba que no hay cambios.
	 *
	 * @param array<string, mixed> $state Estado editorial ya re-sellado.
	 * @return void
	 */
	public static function withdraw_option_rebuild_request( array $state ): void {
		$request = Install::pending_rebuild_request();
		if ( ! is_array( $request ) ) {
			return;
		}
		$reason                    = (string) ( $request['reason'] ?? '' );
		$staged_by_an_option_write = 0 === strpos( $reason, 'option_update_' )
			|| 0 === strpos( $reason, 'option_add_' )
			|| 0 === strpos( $reason, 'option_delete_' )
			|| 0 === strpos( $reason, 'option_repair_' );
		if ( ! $staged_by_an_option_write
			|| Install::DB_VERSION !== (string) ( $request['version'] ?? '' )
			|| (int) ( $request['requested_at'] ?? 0 ) > (int) ( $state['updated_at'] ?? 0 ) ) {
			return;
		}
		if ( Install::acknowledge_pending_rebuild( (string) $request['token'], (int) $request['seq'] ) ) {
			AuditLog::record(
				'rebuild_editorial_retirado',
				sprintf( 'La señal «%s» no tenía nada que materializar: retirada tras el re-sellado', $reason )
			);
		}
	}
}
