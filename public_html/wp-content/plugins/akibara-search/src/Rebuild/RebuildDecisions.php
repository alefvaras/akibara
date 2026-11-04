<?php
/**
 * Decisiones y transiciones de estado para el protocolo de rebuild de búsqueda.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search\Rebuild;

use Akibara\Search\Install;
use Akibara\Search\RebuildJob;
use Akibara\Search\WorkRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Matriz de decisiones y reglas de cutover de RebuildJob.
 *
 * @since 0.1.85
 */
final class RebuildDecisions {

	/**
	 * Decide recuperación sin ejecutar DDL según la fase persistida y la topología física.
	 *
	 * @param string $phase    Fase persistida.
	 * @param string $topology Topología clasificada.
	 * @return string resume|prepare|revalidate|mark_swapped|finish_done|block|idle.
	 */
	public static function recovery_decision( string $phase, string $topology ): string {
		if ( RebuildJob::PHASE_BLOCKED === $phase || RebuildJob::PHASE_ABORTED === $phase ) {
			return 'idle';
		}

		if ( Install::TOPOLOGY_AMBIGUOUS === $topology ) {
			return 'block';
		}

		if ( Install::TOPOLOGY_CLEAN_PRE === $topology ) {
			if ( RebuildJob::PHASE_PREPARING === $phase ) {
				return 'prepare';
			}
			if ( in_array( $phase, array( RebuildJob::PHASE_CLEANUP, RebuildJob::PHASE_DONE ), true ) ) {
				return 'finish_done';
			}
			return 'block';
		}

		if ( Install::TOPOLOGY_READY_SWAP === $topology ) {
			if ( in_array( $phase, array( RebuildJob::PHASE_PREPARING, RebuildJob::PHASE_BUILDING, RebuildJob::PHASE_CATCHING_UP ), true ) ) {
				return RebuildJob::PHASE_PREPARING === $phase ? 'prepare' : 'resume';
			}
			return RebuildJob::PHASE_SWAP_INTENT === $phase ? 'revalidate' : 'block';
		}

		if ( Install::TOPOLOGY_SWAPPED === $topology ) {
			if ( RebuildJob::PHASE_SWAP_INTENT === $phase ) {
				return 'mark_swapped';
			}
			return in_array( $phase, array( RebuildJob::PHASE_SWAPPED, RebuildJob::PHASE_PUBLISHING, RebuildJob::PHASE_CLEANUP ), true )
				? 'resume'
				: 'block';
		}

		return 'block';
	}

	/**
	 * Decide el destino del writer live cuando SWAP_INTENT sobrevivió un crash.
	 *
	 * @param string $topology Topología física observada bajo WriteFence.
	 * @param string $run_id   Run dueño de la sombra.
	 * @return string|null Run pre-swap, vacío post-swap, null si no es demostrable.
	 */
	public static function swap_intent_writer_target( string $topology, string $run_id ): ?string {
		if ( Install::TOPOLOGY_READY_SWAP === $topology ) {
			return '' === $run_id ? null : $run_id;
		}
		if ( Install::TOPOLOGY_SWAPPED === $topology ) {
			return '';
		}

		return null;
	}

	/**
	 * Vincula el target final a la foto semántica que debe quedar acreditada.
	 *
	 * @param string $run_id      Run dueño del cutover.
	 * @param string $fingerprint SHA-256 byte-exacto de options semánticas.
	 * @return string Target determinístico o vacío ante identidad inválida.
	 */
	public static function generation_target_for_fingerprint( string $run_id, string $fingerprint ): string {
		if ( '' === $run_id || 1 !== preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return '';
		}

		return substr( 'akb-search-' . $run_id . '-' . $fingerprint, 0, 191 );
	}

	/**
	 * Un run sólo puede perder su estado cuando ambos scopes están sin deuda.
	 *
	 * @param int|null $baseline Deuda baseline o null ante error SQL.
	 * @param int|null $repair   Deuda repair o null ante error SQL.
	 * @return string clear|wait|error.
	 */
	public static function final_work_debt_decision( ?int $baseline, ?int $repair ): string {
		if ( null === $baseline || null === $repair ) {
			return 'error';
		}

		return 0 === $baseline && 0 === $repair ? 'clear' : 'wait';
	}

	/**
	 * Decide qué hacer cuando el propio request observó un DDL parcial/fallido.
	 *
	 * @param string                             $phase    PREPARING|CLEANUP.
	 * @param string                             $topology Clasificación actual.
	 * @param array<string, array<string, bool>> $snapshot Foto de familias.
	 * @return string recover_shadow|recover_old|retry|finish|block.
	 */
	public static function ddl_failure_decision( string $phase, string $topology, array $snapshot ): string {
		if ( RebuildJob::PHASE_PREPARING === $phase ) {
			if ( Install::TOPOLOGY_AMBIGUOUS === $topology && Install::is_owned_partial_family( $snapshot, 'new' ) ) {
				return 'recover_shadow';
			}
			return Install::TOPOLOGY_CLEAN_PRE === $topology ? 'retry' : 'block';
		}
		if ( RebuildJob::PHASE_CLEANUP === $phase ) {
			if ( Install::TOPOLOGY_CLEAN_PRE === $topology ) {
				return 'finish';
			}
			if ( Install::TOPOLOGY_AMBIGUOUS === $topology && Install::is_owned_partial_family( $snapshot, 'old' ) ) {
				return 'recover_old';
			}
			return Install::TOPOLOGY_SWAPPED === $topology ? 'retry' : 'block';
		}
		return 'block';
	}

	/**
	 * Valida que una acción pertenezca al run y fase correctos.
	 *
	 * @param array<string, mixed> $state  Estado vigente.
	 * @param string               $run_id Run de la acción.
	 * @param string               $scope  Scope de la acción.
	 * @return bool
	 */
	public static function action_matches_state( array $state, string $run_id, string $scope ): bool {
		if ( '' === $run_id || $run_id !== (string) ( $state['run_id'] ?? '' ) ) {
			return false;
		}

		$phase = (string) ( $state['phase'] ?? '' );
		return 'baseline' === $scope
			? in_array( $phase, array( RebuildJob::PHASE_BUILDING, RebuildJob::PHASE_CATCHING_UP ), true )
			: 'repair' === $scope && RebuildJob::PHASE_CATCHING_UP === $phase;
	}

	/**
	 * Un pump sin claims no encadena acciones contra deuda con backoff futuro.
	 *
	 * @param bool   $async        Camino AS.
	 * @param bool   $allow_wakeup Hubo progreso o es un arranque explícito.
	 * @param string $scope        Scope a despertar.
	 * @return bool
	 */
	public static function should_enqueue_wakeup( bool $async, bool $allow_wakeup, string $scope ): bool {
		return $async && $allow_wakeup && in_array( $scope, array( WorkRepository::SCOPE_BASELINE, WorkRepository::SCOPE_REPAIR ), true );
	}

	/**
	 * Normaliza el conteo de filas de repositorios.
	 *
	 * @param int|false $count Resultado del repositorio.
	 * @return int|null
	 */
	public static function normalise_count_result( $count ): ?int {
		return false === $count ? null : (int) $count;
	}

	/**
	 * Todos los conteos deben ser consultables y exactamente cero.
	 *
	 * @param array<int, int|null> $counts Conteos outstanding/lease.
	 * @return bool
	 */
	public static function counts_allow_cutover( array $counts ): bool {
		foreach ( $counts as $count ) {
			if ( null === $count || 0 !== $count ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Agrega estados de work de todos los scopes del run.
	 *
	 * @param array<string, array<string, int>> $summary Resumen del repositorio.
	 * @return array{pending: int, leased: int, blocked: int}
	 */
	public static function work_status_totals( array $summary ): array {
		$totals = array(
			'pending' => 0,
			'leased'  => 0,
			'blocked' => 0,
		);

		foreach ( $summary as $statuses ) {
			if ( ! is_array( $statuses ) ) {
				continue;
			}
			$totals['pending'] += (int) ( $statuses[ WorkRepository::STATUS_PENDING ] ?? 0 );
			$totals['leased']  += (int) ( $statuses[ WorkRepository::STATUS_LEASED ] ?? 0 );
			$totals['blocked'] += (int) ( $statuses[ WorkRepository::STATUS_BLOCKED ] ?? 0 );
		}

		return $totals;
	}

	/**
	 * Evalúa si debe encadenar otro lote en el mismo hilo síncrono.
	 *
	 * @param int   $processed  Claims procesados en la vuelta.
	 * @param float $deadline   Instante límite de la invocación.
	 * @param int   $batch_size Tamaño de lote.
	 * @return bool
	 */
	public static function should_chain( int $processed, float $deadline, int $batch_size = 50 ): bool {
		return $processed >= $batch_size && microtime( true ) < $deadline;
	}

	/**
	 * Diagnostica si una reconstrucción se encuentra trabada/estancada.
	 *
	 * @param bool      $terminal              Si la fase actual es terminal.
	 * @param int       $segundos_sin_latido   Segundos transcurridos desde el último latido.
	 * @param bool      $queda_trabajo         Si aún queda trabajo pendiente.
	 * @param int|false $leases_vigentes       Leases sin caducar del run.
	 * @param int       $stalled_seconds       Umbral de segundos sin latido.
	 * @return bool
	 */
	public static function stalled_verdict( bool $terminal, int $segundos_sin_latido, bool $queda_trabajo, $leases_vigentes, int $stalled_seconds = 300 ): bool {
		if ( $terminal || $segundos_sin_latido < $stalled_seconds || ! $queda_trabajo ) {
			return false;
		}

		return ! ( is_int( $leases_vigentes ) && $leases_vigentes > 0 );
	}

	/**
	 * Verifica si una fase es terminal (sin acciones futuras).
	 *
	 * @param string $phase Fase del protocolo.
	 * @return bool
	 */
	public static function is_terminal_phase( string $phase ): bool {
		return in_array( $phase, array( RebuildJob::PHASE_DONE, RebuildJob::PHASE_BLOCKED, RebuildJob::PHASE_ABORTED ), true );
	}
}
