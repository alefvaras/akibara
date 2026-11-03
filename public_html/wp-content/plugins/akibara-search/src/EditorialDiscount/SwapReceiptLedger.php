<?php
/**
 * Libro mayor de recibos de swap y avance de generación del índice.
 *
 * @package Akibara\Search\EditorialDiscount
 */

declare( strict_types = 1 );

namespace Akibara\Search\EditorialDiscount;

use Akibara\Search\AuditLog;
use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\Install;
use Akibara\Search\SemanticPublication;
use Akibara\Search\Support\OptionCodec;

defined( 'ABSPATH' ) || exit;

/**
 * Gestión inmutable y atómica de recibos de swap y generación editorial.
 *
 * Extraído de EditorialDiscountPublication para aislar el protocolo de persistencia,
 * auditoría y poda de comprobantes de publicación física.
 */
final class SwapReceiptLedger {

	/**
	 * Prefijo para options inmutables de recibos por corrida.
	 */
	public const RUN_RECEIPT_PREFIX = 'akb_search_editorial_run_receipt_';

	/**
	 * Cantidad máxima de recibos históricos retenidos tras poda.
	 */
	public const RUN_RECEIPT_KEEP = 20;

	/**
	 * Límite de exploración en escaneo de poda.
	 */
	public const RUN_RECEIPT_SCAN_LIMIT = 500;

	/**
	 * Receipt exclusivo de PUBLISHING, después de generación, purge REST,
	 * fingerprint semántico y cobertura exacta. Siempre conserva historia; sólo
	 * mueve el receipt corriente si la intención sigue siendo esa revisión.
	 *
	 * @param string               $run_id      Identificador de la corrida.
	 * @param int                  $seq         Número de secuencia editorial.
	 * @param string               $fingerprint Huella digital del snapshot.
	 * @param array<string, mixed> $proof       Prueba física aportada por RebuildJob.
	 * @return bool
	 */
	public static function record_swap_receipt( string $run_id, int $seq, string $fingerprint, array $proof ): bool {
		EditorialDiscountPublication::invalidate_runtime_memo();
		if ( ! EditorialDiscountPublication::is_fence_owned()
			|| '' === $run_id || $seq <= 0 || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
			return false;
		}
		$price_capture = EditorialDiscountPublication::capture_price_authority();
		if ( ! is_array( $price_capture ) || $seq !== $price_capture['wanted_seq']
			|| ! hash_equals( $fingerprint, $price_capture['fingerprint'] ) ) {
			return self::record_superseded_swap_receipt( $run_id, $seq, $fingerprint, $proof );
		}
		if ( ! self::swap_proof_is_complete( $proof ) ) {
			return false;
		}
		$generation           = (string) $proof['generation_target'];
		$semantic_fingerprint = (string) $proof['semantic_fingerprint'];
		if ( ! self::swap_authority_matches( $price_capture, $seq, $fingerprint, $proof, $generation, $semantic_fingerprint )
			|| ! EditorialDiscountPublication::dispatch_receipt_purge() ) {
			return false;
		}

		$entry = self::swap_receipt_entry( $run_id, $seq, $fingerprint, $proof );
		if ( ! self::swap_authority_matches( $price_capture, $seq, $fingerprint, $proof, $generation, $semantic_fingerprint )
			|| ! self::persist_run_receipt( $entry ) ) {
			return false;
		}

		$state   = EditorialDiscountPublication::read_state();
		$current = EditorialDiscountPublication::current_fingerprint();
		if ( EditorialDiscountPublication::valid_state( $state ) && null !== $current
			&& $seq === (int) $state['wanted_seq']
			&& hash_equals( $fingerprint, (string) $state['wanted_fingerprint'] )
			&& hash_equals( $fingerprint, $current ) ) {
			if ( ! self::swap_authority_matches( $price_capture, $seq, $fingerprint, $proof, $generation, $semantic_fingerprint ) ) {
				return false;
			}
			update_option( EditorialDiscountPublication::OPTION_RECEIPT, $entry, false );
			wp_cache_delete( EditorialDiscountPublication::OPTION_RECEIPT, 'options' );
			EditorialDiscountPublication::invalidate_runtime_memo();
			$stored = EditorialDiscountPublication::read_option_uncached( EditorialDiscountPublication::OPTION_RECEIPT );
			return EditorialDiscountPublication::valid_receipt( $stored )
				&& $seq === (int) $stored['wanted_seq']
				&& hash_equals( $fingerprint, (string) $stored['fingerprint'] );
		}

		// La opción inmutable por run/revisión basta para cleanup del supersedido;
		// jamás mueve el receipt corriente ni abre el gate de la revisión nueva.
		return null !== self::run_receipt( $run_id, $seq, $fingerprint );
	}

	/**
	 * Recibo de un run cuya revisión editorial ya quedó ATRÁS.
	 *
	 * @param string               $run_id      Identificador de la corrida.
	 * @param int                  $seq         Número de secuencia editorial.
	 * @param string               $fingerprint Huella digital del snapshot.
	 * @param array<string, mixed> $proof       Prueba física aportada por RebuildJob.
	 * @return bool
	 */
	public static function record_superseded_swap_receipt( string $run_id, int $seq, string $fingerprint, array $proof ): bool {
		$state = EditorialDiscountPublication::read_state();
		if ( ! EditorialDiscountPublication::valid_state( $state ) || ! self::swap_proof_is_complete( $proof ) ) {
			return false; // Sin estado legible no se afirma ningún relevo.
		}
		$vigente = (int) $state['wanted_seq'];
		if ( $vigente === $seq ) {
			$live = EditorialDiscountPublication::fresh_source_snapshot();
			if ( ! hash_equals( $fingerprint, (string) $state['wanted_fingerprint'] )
				|| ! is_array( $live ) || hash_equals( $fingerprint, (string) $live['fingerprint'] ) ) {
				return false; // Misma revisión y misma foto (o ilegible): fallo transitorio, no un relevo.
			}
		} elseif ( $vigente < $seq ) {
			return false;
		}
		if ( ! EditorialDiscountPublication::dispatch_receipt_purge()
			|| ! self::persist_run_receipt( self::swap_receipt_entry( $run_id, $seq, $fingerprint, $proof ) ) ) {
			return false;
		}
		AuditLog::record(
			'recibo_editorial_superado',
			sprintf(
				'La reconstrucción publicó la revisión editorial %d, que ya quedó atrás (vigente: %d%s): se cierra con su recibo propio y la configuración vigente la publica el reproof o un rebuild nuevo',
				$seq,
				$vigente,
				$vigente === $seq ? ', con la configuración cambiada sin montar deuda' : ''
			)
		);

		return true;
	}

	/**
	 * Prueba física completa del swap: esquema, generación, purga, fingerprint
	 * semántico y cobertura exacta.
	 *
	 * @param array<string, mixed> $proof Prueba aportada por RebuildJob.
	 * @return bool
	 */
	public static function swap_proof_is_complete( array $proof ): bool {
		$coverage             = is_array( $proof['coverage'] ?? null ) ? $proof['coverage'] : array();
		$generation           = (string) ( $proof['generation_target'] ?? '' );
		$semantic_fingerprint = (string) ( $proof['semantic_fingerprint'] ?? '' );

		if ( Install::DB_VERSION !== (string) ( $proof['db_version'] ?? '' )
			|| Install::schema_seal() !== (string) ( $proof['schema_seal'] ?? '' )
			|| '' === $generation
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $semantic_fingerprint ) ) {
			return false;
		}

		if ( ! (bool) ( $proof['generation_acked'] ?? false )
			|| ! (bool) ( $proof['rest_purge_acked'] ?? false )
			|| ! (bool) ( $proof['semantic_fingerprint_acked'] ?? false )
			|| (int) ( $proof['swapped_at'] ?? 0 ) <= 0 ) {
			return false;
		}

		return self::coverage_proof_is_complete( $coverage )
			&& EditorialDiscountPublication::physical_publication_matches( $generation, $semantic_fingerprint );
	}

	/**
	 * Verifica la cobertura de documentos del proof.
	 *
	 * @param array<string, mixed> $coverage Array de métricas de cobertura.
	 * @return bool
	 */
	private static function coverage_proof_is_complete( array $coverage ): bool {
		$eligible = (int) ( $coverage['eligible'] ?? -1 );
		$docs     = (int) ( $coverage['docs'] ?? -1 );

		return $eligible >= 0
			&& $docs >= 0
			&& $eligible === $docs
			&& 0 === (int) ( $coverage['missing'] ?? -1 )
			&& 0 === (int) ( $coverage['extra'] ?? -1 )
			&& 0 === (int) ( $coverage['orphans'] ?? -1 );
	}

	/**
	 * Recibo con shape cerrado aunque el proof interno gane campos diagnósticos
	 * en versiones futuras.
	 *
	 * @param string               $run_id      Identificador de la corrida.
	 * @param int                  $seq         Número de secuencia editorial.
	 * @param string               $fingerprint Huella digital.
	 * @param array<string, mixed> $proof       Prueba ya validada por swap_proof_is_complete().
	 * @return array<string, mixed>
	 */
	public static function swap_receipt_entry( string $run_id, int $seq, string $fingerprint, array $proof ): array {
		$coverage = (array) $proof['coverage'];

		return array(
			'protocol'                => EditorialDiscountPublication::PROTOCOL,
			'contract'                => EditorialDiscountPublication::CONTRACT,
			'run_id'                  => $run_id,
			'wanted_seq'              => $seq,
			'fingerprint'             => $fingerprint,
			'swapped_at'              => (int) ( $proof['swapped_at'] ?? time() ),
			'published_at'            => time(),
			'db_version'              => Install::DB_VERSION,
			'schema_seal'             => Install::schema_seal(),
			'coverage'                => array(
				'eligible' => (int) $coverage['eligible'],
				'docs'     => (int) $coverage['docs'],
				'missing'  => (int) $coverage['missing'],
				'extra'    => (int) $coverage['extra'],
				'orphans'  => (int) $coverage['orphans'],
			),
			'generation_target'       => (string) $proof['generation_target'],
			'semantic_fingerprint'    => (string) $proof['semantic_fingerprint'],
			'publication_acked'       => true,
			'purge_all_dispatched_at' => time(),
		);
	}

	/**
	 * Recheck combinado para que ninguna mutación entre proof/purge/receipts
	 * pueda promover una foto editorial o física distinta.
	 *
	 * @param array<string, mixed> $capture              Captura de precio adoptada.
	 * @param int                  $seq                  Número de secuencia.
	 * @param string               $fingerprint          Huella digital.
	 * @param array<string, mixed> $proof                Proof de publicación.
	 * @param string               $generation           Generación física destino.
	 * @param string               $semantic_fingerprint Huella semántica.
	 * @return bool
	 */
	public static function swap_authority_matches( array $capture, int $seq, string $fingerprint, array $proof, string $generation, string $semantic_fingerprint ): bool {
		return Install::DB_VERSION === (string) ( $proof['db_version'] ?? '' )
			&& Install::schema_seal() === (string) ( $proof['schema_seal'] ?? '' )
			&& $seq === ( $capture['wanted_seq'] ?? null )
			&& is_string( $capture['fingerprint'] ?? null )
			&& hash_equals( $fingerprint, $capture['fingerprint'] )
			&& EditorialDiscountPublication::physical_publication_matches( $generation, $semantic_fingerprint )
			&& EditorialDiscountPublication::price_authority_matches( $capture );
	}

	/**
	 * Comprueba si existe un recibo válido para la corrida y secuencia.
	 *
	 * @param string $run_id           Identificador de la corrida.
	 * @param int    $seq              Número de secuencia.
	 * @param string $fingerprint      Huella digital.
	 * @param bool   $allow_superseded Permitir si fue supersedido.
	 * @return bool
	 */
	public static function swap_receipt_exists( string $run_id, int $seq, string $fingerprint, bool $allow_superseded ): bool {
		if ( $allow_superseded && null !== self::run_receipt( $run_id, $seq, $fingerprint ) ) {
			return true;
		}
		$receipt = EditorialDiscountPublication::read_option_uncached( EditorialDiscountPublication::OPTION_RECEIPT );
		return EditorialDiscountPublication::valid_receipt( $receipt )
			&& $run_id === (string) $receipt['run_id']
			&& $seq === (int) $receipt['wanted_seq']
			&& hash_equals( $fingerprint, (string) $receipt['fingerprint'] );
	}

	/**
	 * Encadena el receipt corriente a una generación live/semántica posterior,
	 * sólo después de sus receipts físicos y sin modificar el receipt inmutable
	 * que acredita el swap original.
	 *
	 * @param string $generation Generación destino.
	 * @return bool
	 */
	public static function advance_generation_receipt( string $generation ): bool {
		EditorialDiscountPublication::invalidate_runtime_memo();
		$source               = EditorialDiscountPublication::stable_source_snapshot();
		$state                = EditorialDiscountPublication::read_state();
		$receipt              = EditorialDiscountPublication::read_option_uncached( EditorialDiscountPublication::OPTION_RECEIPT );
		$semantic             = EditorialDiscountPublication::read_option_uncached( SemanticPublication::OPTION_FINGERPRINT );
		$semantic_fingerprint = is_array( $semantic ) ? (string) ( $semantic['fingerprint'] ?? '' ) : '';

		if ( ! EditorialDiscountPublication::materialization_matches( $source, $state, $receipt ) ) {
			AuditLog::record( 'avance_generacion_rechazado', 'La materialización editorial no casa con state/receipt' );
			return false;
		}
		if ( '' === $generation || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $semantic_fingerprint ) ) {
			AuditLog::record( 'avance_generacion_rechazado', 'Generación o fingerprint semántico inválidos' );
			return false;
		}
		if ( ! EditorialDiscountPublication::physical_publication_matches( $generation, $semantic_fingerprint ) ) {
			AuditLog::record( 'avance_generacion_rechazado', 'Publicación física (purge/semántica) sin sellar para ' . substr( $generation, -12 ) );
			return false;
		}

		if ( $generation === (string) ( $receipt['generation_target'] ?? '' )
			&& hash_equals( $semantic_fingerprint, (string) ( $receipt['semantic_fingerprint'] ?? '' ) ) ) {
			return EditorialDiscountPublication::is_ready_from( $source, $state, $receipt );
		}

		$receipt['generation_target']      = $generation;
		$receipt['semantic_fingerprint']   = $semantic_fingerprint;
		$receipt['generation_advanced_at'] = time();

		$generacion_viva = self::live_index_generation();
		if ( null === $generacion_viva || $generation !== $generacion_viva ) {
			AuditLog::record(
				'avance_generacion_rechazado',
				null === $generacion_viva
					? 'No se pudo leer la generación viva durante el avance; próximo ciclo re-prueba'
					: 'La generación cambió durante el avance; próximo ciclo re-prueba'
			);
			return false;
		}
		update_option( EditorialDiscountPublication::OPTION_RECEIPT, $receipt, false );
		wp_cache_delete( EditorialDiscountPublication::OPTION_RECEIPT, 'options' );
		EditorialDiscountPublication::invalidate_runtime_memo();

		return EditorialDiscountPublication::is_ready_from(
			EditorialDiscountPublication::stable_source_snapshot(),
			EditorialDiscountPublication::read_state(),
			EditorialDiscountPublication::read_option_uncached( EditorialDiscountPublication::OPTION_RECEIPT )
		);
	}

	/**
	 * Generación viva del índice leída de MySQL, sin object cache ni memos.
	 *
	 * @return string|null null si la lectura falló; '' si no hay generación.
	 */
	public static function live_index_generation(): ?string {
		global $wpdb;
		$wpdb->last_error = '';
		$raw              = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Install::OPTION_INDEX_GEN )
		);

		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			return null;
		}
		if ( null === $raw ) {
			return '';
		}

		return is_string( $raw ) ? $raw : null;
	}

	/**
	 * Persiste el recibo inmutable por corrida en wp_options.
	 *
	 * @param array<string, mixed> $entry Receipt inmutable por run/revisión.
	 * @return bool
	 */
	public static function persist_run_receipt( array $entry ): bool {
		global $wpdb;
		$key     = self::run_receipt_option(
			(string) ( $entry['run_id'] ?? '' ),
			(int) ( $entry['wanted_seq'] ?? 0 ),
			(string) ( $entry['fingerprint'] ?? '' )
		);
		$encoded = maybe_serialize( $entry );
		if ( '' === $key || ! is_string( $encoded ) || '' === $encoded ) {
			return false;
		}

		$wpdb->last_error = '';
		$result           = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				$encoded,
				'no'
			)
		);
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( false === $result || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			return false;
		}

		$persisted = null !== self::run_receipt(
			(string) $entry['run_id'],
			(int) $entry['wanted_seq'],
			(string) $entry['fingerprint']
		);

		if ( $persisted ) {
			self::prune_run_receipts( $key );
		}

		return $persisted;
	}

	/**
	 * Poda los receipts supersedidos, conservando los más recientes.
	 *
	 * @param string $keep_key Option del receipt de esta publicación.
	 * @return void
	 */
	public static function prune_run_receipts( string $keep_key ): void {
		global $wpdb;

		$like = $wpdb->esc_like( self::RUN_RECEIPT_PREFIX ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				 WHERE option_name LIKE %s AND option_name <> %s
				 LIMIT %d",
				$like,
				$keep_key,
				self::RUN_RECEIPT_SCAN_LIMIT
			)
		);
		if ( ! is_array( $rows ) || count( $rows ) < self::RUN_RECEIPT_KEEP ) {
			return;
		}

		$stamped = array();
		foreach ( $rows as $row ) {
			$decoded = OptionCodec::decode( (string) $row->option_value );
			$stamped[ (string) $row->option_name ] = is_array( $decoded )
				? (int) ( $decoded['purge_all_dispatched_at'] ?? 0 )
				: 0;
		}

		arsort( $stamped );
		$obsolete = array_slice( array_keys( $stamped ), self::RUN_RECEIPT_KEEP );
		foreach ( $obsolete as $name ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
			wp_cache_delete( (string) $name, 'options' );
		}
	}

	/**
	 * Lee un recibo inmutable específico por corrida.
	 *
	 * @param string $run_id      Identificador de la corrida.
	 * @param int    $seq         Número de secuencia.
	 * @param string $fingerprint Huella digital.
	 * @return array<string, mixed>|null
	 */
	public static function run_receipt( string $run_id, int $seq, string $fingerprint ): ?array {
		$key = self::run_receipt_option( $run_id, $seq, $fingerprint );
		if ( '' === $key ) {
			return null;
		}
		$receipt = EditorialDiscountPublication::read_option_uncached( $key );
		return EditorialDiscountPublication::valid_receipt( $receipt )
			&& $run_id === (string) $receipt['run_id']
			&& $seq === (int) $receipt['wanted_seq']
			&& hash_equals( $fingerprint, (string) $receipt['fingerprint'] )
			? $receipt
			: null;
	}

	/**
	 * Genera la clave de opción para el recibo de una corrida.
	 *
	 * @param string $run_id      Identificador de la corrida.
	 * @param int    $seq         Número de secuencia.
	 * @param string $fingerprint Huella digital.
	 * @return string
	 */
	public static function run_receipt_option( string $run_id, int $seq, string $fingerprint ): string {
		if ( '' === $run_id || $seq <= 0 || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
			return '';
		}
		return self::RUN_RECEIPT_PREFIX . hash( 'sha256', $run_id . "\0" . $seq . "\0" . $fingerprint );
	}
}
