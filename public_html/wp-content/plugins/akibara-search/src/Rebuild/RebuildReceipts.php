<?php
/**
 * Persistencia y validación de receipts de publicación y cutover para el índice de búsqueda.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search\Rebuild;

use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\Install;
use Akibara\Search\RebuildJob;
use Akibara\Search\SemanticPublication;
use Akibara\Search\Support\RestCache;

defined( 'ABSPATH' ) || exit;

/**
 * Gestor de receipts e historial de rebuilds.
 *
 * @since 0.1.85
 */
final class RebuildReceipts {

	/**
	 * Historial más reciente primero.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function history(): array {
		$raw = get_option( RebuildJob::OPTION_HISTORY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Verifica si el receipt de huella semántica coincide con la generación actual.
	 *
	 * @param string $fingerprint Huella calculada.
	 * @param string $generation  Generación que lo publicó.
	 * @return bool
	 */
	public static function semantic_fingerprint_receipt_matches( string $fingerprint, string $generation ): bool {
		$receipt = get_option( SemanticPublication::OPTION_FINGERPRINT, null );
		return is_array( $receipt )
			&& 1 === (int) ( $receipt['protocol'] ?? 0 )
			&& $fingerprint === (string) ( $receipt['fingerprint'] ?? '' )
			&& $generation === (string) ( $receipt['generation'] ?? '' )
			&& (int) ( $receipt['published_at'] ?? 0 ) > 0;
	}

	/**
	 * Determina si el estado requiere receipt de descuento editorial.
	 *
	 * @param array<string, mixed> $state Estado del rebuild.
	 * @return bool
	 */
	public static function editorial_receipt_required( array $state ): bool {
		return (int) ( $state['editorial_wanted_seq'] ?? 0 ) > 0
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', (string) ( $state['editorial_fingerprint'] ?? '' ) );
	}

	/**
	 * Valida si el receipt editorial está acreditado.
	 *
	 * @param array<string, mixed> $state            Estado del rebuild.
	 * @param bool                 $allow_superseded Si admite recibos superados.
	 * @return bool
	 */
	public static function editorial_receipt_acked( array $state, bool $allow_superseded ): bool {
		if ( ! self::editorial_receipt_required( $state ) ) {
			return true;
		}
		return (bool) ( $state['ack_editorial_receipt'] ?? false )
			&& EditorialDiscountPublication::swap_receipt_exists(
				(string) ( $state['run_id'] ?? '' ),
				(int) $state['editorial_wanted_seq'],
				(string) $state['editorial_fingerprint'],
				$allow_superseded
			);
	}

	/**
	 * Acredita la publicación física y documental del rebuild.
	 *
	 * @param array<string, mixed> $state            Estado post-swap.
	 * @param bool                 $allow_superseded Sólo para CLEANUP/DONE.
	 * @return bool
	 */
	public static function publication_acked( array $state, bool $allow_superseded = false ): bool {
		$generation_target    = (string) ( $state['generation_target'] ?? '' );
		$semantic_fingerprint = (string) ( $state['semantic_fingerprint'] ?? '' );
		$flags                = '' !== $generation_target
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $semantic_fingerprint )
			&& (bool) ( $state['ack_generation'] ?? false )
			&& (bool) ( $state['ack_rest_purge'] ?? false )
			&& (bool) ( $state['ack_price_buckets'] ?? false )
			&& (bool) ( $state['ack_semantic_fingerprint'] ?? false )
			&& self::editorial_receipt_acked( $state, true )
			&& (bool) ( $state['ack_history'] ?? false );
		if ( ! $flags ) {
			return false;
		}

		$physical = $generation_target === (string) get_option( Install::OPTION_INDEX_GEN, '' )
			&& RestCache::has_purge_receipt( $generation_target )
			&& self::semantic_fingerprint_receipt_matches( $semantic_fingerprint, $generation_target );
		$receipt  = self::publication_receipt_for_state( $state );
		if ( null === $receipt ) {
			return false;
		}

		return $allow_superseded || $physical;
	}

	/**
	 * Busca el receipt nuevo exacto en last o en el ring.
	 *
	 * @param array<string, mixed> $state Estado del run.
	 * @return array<string, mixed>|null
	 */
	public static function publication_receipt_for_state( array $state ): ?array {
		$candidates = array_merge(
			array( get_option( RebuildJob::OPTION_LAST, array() ) ),
			self::history()
		);
		foreach ( $candidates as $receipt ) {
			if ( is_array( $receipt ) && self::is_matching_receipt( $receipt, $state ) ) {
				return $receipt;
			}
		}
		return null;
	}

	/**
	 * Evalúa si una entrada candidata coincide con el receipt del run actual.
	 *
	 * @param array<string, mixed> $receipt Candidato.
	 * @param array<string, mixed> $state   Estado del run.
	 * @return bool
	 */
	private static function is_matching_receipt( array $receipt, array $state ): bool {
		if ( ! self::receipt_flags_match( $receipt ) ) {
			return false;
		}

		if ( (string) ( $receipt['run_id'] ?? '' ) !== (string) ( $state['run_id'] ?? '' )
			|| (string) ( $receipt['generation_target'] ?? '' ) !== (string) ( $state['generation_target'] ?? '' )
			|| (string) ( $receipt['semantic_fingerprint'] ?? '' ) !== (string) ( $state['semantic_fingerprint'] ?? '' ) ) {
			return false;
		}

		return self::editorial_receipt_matches_state( $receipt, $state );
	}

	/**
	 * Valida las banderas estáticas del receipt.
	 *
	 * @param array<string, mixed> $receipt Candidato.
	 * @return bool
	 */
	private static function receipt_flags_match( array $receipt ): bool {
		return 1 === (int) ( $receipt['publication_protocol'] ?? 0 )
			&& (bool) ( $receipt['publication_acked'] ?? false )
			&& 'local_dispatch_only' === (string) ( $receipt['purge_ack_scope'] ?? '' )
			&& (bool) ( $receipt['semantic_fingerprint_acked'] ?? false )
			&& 'ok' === (string) ( $receipt['result'] ?? '' )
			&& in_array( (string) ( $receipt['phase'] ?? '' ), array( RebuildJob::PHASE_PUBLISHING, RebuildJob::PHASE_DONE ), true );
	}

	/**
	 * Valida concordancia de receipt editorial si es requerido.
	 *
	 * @param array<string, mixed> $receipt Candidato.
	 * @param array<string, mixed> $state   Estado del run.
	 * @return bool
	 */
	private static function editorial_receipt_matches_state( array $receipt, array $state ): bool {
		if ( ! self::editorial_receipt_required( $state ) ) {
			return true;
		}

		return (int) ( $receipt['editorial_wanted_seq'] ?? 0 ) === (int) $state['editorial_wanted_seq']
			&& (string) ( $receipt['editorial_fingerprint'] ?? '' ) === (string) $state['editorial_fingerprint']
			&& (bool) ( $receipt['editorial_receipt_acked'] ?? false )
			&& self::editorial_receipt_acked( $state, true );
	}

	/**
	 * Persiste receipt/historial de publicación de forma idempotente por run_id.
	 *
	 * @param array<string, mixed> $state Estado.
	 * @return bool
	 */
	public static function persist_publish_receipt( array $state ): bool {
		global $wpdb;
		$generation_target    = (string) ( $state['generation_target'] ?? '' );
		$semantic_fingerprint = (string) ( $state['semantic_fingerprint'] ?? '' );
		if ( '' === $generation_target
			|| ! (bool) ( $state['ack_generation'] ?? false )
			|| ! (bool) ( $state['ack_rest_purge'] ?? false )
			|| ! (bool) ( $state['ack_price_buckets'] ?? false )
			|| ! (bool) ( $state['ack_semantic_fingerprint'] ?? false )
			|| ! self::editorial_receipt_acked( $state, true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $semantic_fingerprint )
			|| $generation_target !== (string) get_option( Install::OPTION_INDEX_GEN, '' )
			|| ! RestCache::has_purge_receipt( $generation_target )
			|| $semantic_fingerprint !== SemanticPublication::current_fingerprint()
			|| ! self::semantic_fingerprint_receipt_matches( $semantic_fingerprint, $generation_target ) ) {
			return false;
		}

		$wpdb->last_error = '';
		$docs             = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Install::docs_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== (string) ( $wpdb->last_error ?? '' )
			|| ( ! is_int( $docs ) && ( ! is_string( $docs ) || 1 !== preg_match( '/^[0-9]+$/D', $docs ) ) ) ) {
			return false;
		}

		$receipt = array(
			'run_id'                     => (string) $state['run_id'],
			'started_at'                 => (int) ( $state['started_at'] ?? 0 ),
			'finished_at'                => time(),
			'docs'                       => (int) $docs,
			'expected'                   => (int) ( $state['expected'] ?? 0 ),
			'result'                     => 'ok',
			'phase'                      => RebuildJob::PHASE_PUBLISHING,
			'publication_protocol'       => 1,
			'generation_target'          => $generation_target,
			'publication_acked'          => true,
			'purge_ack_scope'            => 'local_dispatch_only',
			'semantic_fingerprint'       => $semantic_fingerprint,
			'semantic_fingerprint_acked' => true,
			'editorial_wanted_seq'       => (int) ( $state['editorial_wanted_seq'] ?? 0 ),
			'editorial_fingerprint'      => (string) ( $state['editorial_fingerprint'] ?? '' ),
			'editorial_receipt_acked'    => self::editorial_receipt_acked( $state, true ),
		);

		update_option( RebuildJob::OPTION_LAST, $receipt, false );
		$history_persisted = self::append_history( $receipt );

		$stored = get_option( RebuildJob::OPTION_LAST, array() );
		return $history_persisted
			&& is_array( $stored )
			&& (string) ( $stored['run_id'] ?? '' ) === (string) $state['run_id']
			&& null !== self::publication_receipt_for_state( $state );
	}

	/**
	 * Actualiza el receipt final después de cleanup.
	 *
	 * @param array<string, mixed> $state Estado DONE.
	 * @return bool
	 */
	public static function persist_final_receipt( array $state ): bool {
		$last = get_option( RebuildJob::OPTION_LAST, array() );
		if ( ! is_array( $last ) || (string) ( $last['run_id'] ?? '' ) !== (string) ( $state['run_id'] ?? '' ) ) {
			return false;
		}

		$last['phase']       = RebuildJob::PHASE_DONE;
		$last['finished_at'] = time();
		update_option( RebuildJob::OPTION_LAST, $last, false );
		$history_persisted = self::append_history( $last );
		$stored            = get_option( RebuildJob::OPTION_LAST, array() );

		return $history_persisted
			&& is_array( $stored )
			&& RebuildJob::PHASE_DONE === (string) ( $stored['phase'] ?? '' )
			&& (string) ( $stored['run_id'] ?? '' ) === (string) $state['run_id'];
	}

	/**
	 * Ring idempotente por run_id.
	 *
	 * @param array<string, mixed> $entry Entrada.
	 * @return bool
	 */
	public static function append_history( array $entry ): bool {
		$run_id  = (string) ( $entry['run_id'] ?? '' );
		$history = array_values(
			array_filter(
				self::history(),
				static fn( $row ): bool => ! is_array( $row ) || '' === $run_id || $run_id !== (string) ( $row['run_id'] ?? '' )
			)
		);
		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, RebuildJob::HISTORY_MAX );
		update_option( RebuildJob::OPTION_HISTORY, $history, false );

		$stored = self::history();
		return isset( $stored[0] )
			&& is_array( $stored[0] )
			&& (string) ( $stored[0]['run_id'] ?? '' ) === $run_id;
	}
}
