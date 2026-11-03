<?php
/**
 * Rebuild durable del indice mediante sombra, outbox y publicacion recuperable.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

use Akibara\Search\Support\RecurringMemo;
use Akibara\Search\Support\RestCache;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Coordina el protocolo de rebuild v2.
 *
 * Action Scheduler es solamente un mecanismo de wake-up. La verdad de cada
 * producto vive en WorkRepository y la verdad del cutover vive en OPTION_STATE.
 *
 * @since 0.1.14
 */
final class RebuildJob {

	/** Wake-up de un pump baseline/repair. */
	public const HOOK_BATCH = 'akb_search_rebuild_batch';

	/** Watchdog recurrente que recupera leases y vuelve a despertar deuda. */
	public const HOOK_WATCHDOG = 'akb_search_rebuild_watchdog';

	/** Option JSON del estado recuperable del rebuild. */
	public const OPTION_STATE = 'akb_search_rebuild_state';

	/** Option con el receipt mas reciente. */
	public const OPTION_LAST = 'akb_search_last_rebuild';

	/** Option ring con las ultimas ejecuciones. */
	public const OPTION_HISTORY = 'akb_search_rebuild_history';

	/** Entradas maximas del historial. */
	public const HISTORY_MAX = 10;

	/** Claims procesados por lote. */
	public const BATCH_SIZE = 200;

	/**
	 * Presupuesto de reloj de UNA invocación del bombeo, en segundos.
	 *
	 * Acota el encadenado síncrono de lotes en `pump_scope()`.
	 * Limita la ejecución dentro del presupuesto de tiempo para evitar exceder
	 * `max_execution_time`, minimizando cambios de contexto en Action Scheduler
	 * y reduciendo la latencia de indexación.
	 */
	public const PUMP_BUDGET_SECONDS = 20;

	/** Lease individual de un producto shadow. */
	public const LEASE_SECONDS = 120;

	/**
	 * Intervalo del watchdog en segundos (15 minutos).
	 *
	 * Proporciona una ventana de supervisión equilibrada para rescatar reconstrucciones
	 * bloqueadas o caídas de servido sin saturar la cola de Action Scheduler.
	 *
	 * La sincronización del intervalo es automática: {@see self::repair_watchdog()}
	 * compara el intervalo de la acción programada con esta constante y la recrea
	 * si detecta divergencia ({@see self::watchdog_interval_drifted()}), operando
	 * en el grupo canónico de Action Scheduler:
	 * `as_unschedule_all_actions( 'akb_search_rebuild_watchdog', array(), 'akibara-search' );`
	 */
	public const WATCHDOG_INTERVAL = 900;

	/**
	 * Evita consultas AS en cada request; el hook oficial fuerza reparación.
	 *
	 * La expiración se calcula dinámicamente mediante {@see RecurringMemo::ttl()}
	 * a partir de WATCHDOG_INTERVAL, asegurando una guarda proporcional al intervalo
	 * que evita ventanas ciegas prolongadas.
	 */
	private const WATCHDOG_GUARD_TRANSIENT = 'akb_search_watchdog_guard_v2';

	/** Un estado sin heartbeat hace este tiempo se reporta detenido. */
	public const STALLED_SECONDS = 300;

	public const PHASE_PREPARING   = 'PREPARING';
	public const PHASE_BUILDING    = 'BUILDING';
	public const PHASE_CATCHING_UP = 'CATCHING_UP';
	public const PHASE_SWAP_INTENT = 'SWAP_INTENT';
	public const PHASE_SWAPPED     = 'SWAPPED';
	public const PHASE_PUBLISHING  = 'PUBLISHING';
	public const PHASE_CLEANUP     = 'CLEANUP';
	public const PHASE_DONE        = 'DONE';
	public const PHASE_BLOCKED     = 'BLOCKED';
	public const PHASE_ABORTED     = 'ABORTED';

	/** @var Indexer */
	private Indexer $indexer;

	/** @var WorkRepository */
	private WorkRepository $work;

	/** @var WriteFence */
	private WriteFence $fence;

	/** Runtime unico requerido por la API estatica schedule(). */
	private static ?self $runtime = null;

	/** Distingue option ausente de SELECT fallido. */
	private static bool $state_read_failed = false;

	/** Distingue una fila presente pero vacía/corrupta de ausencia real. */
	private static bool $state_row_present = false;

	/**
	 * @param Indexer        $indexer Indexer transaccional.
	 * @param WorkRepository $work    Outbox/manifest durable.
	 * @param WriteFence     $fence   Fence global de escritores y cutover.
	 */
	public function __construct( Indexer $indexer, WorkRepository $work, WriteFence $fence ) {
		$this->indexer = $indexer;
		$this->work    = $work;
		$this->fence   = $fence;

		self::$runtime = $this;
	}

	/**
	 * Registra handlers sin ejecutar APIs de Action Scheduler antes de init.
	 *
	 * @return void
	 */
	public function register_handlers(): void {
		add_action( self::HOOK_BATCH, array( $this, 'handle_batch' ), 10, 2 );
		add_action( self::HOOK_WATCHDOG, array( $this, 'handle_watchdog' ) );
		add_action( 'init', array( $this, 'handle_recovery' ), 20 );
		add_action( 'init', array( $this, 'register_recurring_guard' ), 21 );
		add_action( 'init', array( Install::class, 'consume_generation_publications' ), 22 );
	}

	/**
	 * Registra el guard moderno de AS y mantiene fallback para versiones viejas.
	 *
	 * @return void
	 */
	public function register_recurring_guard(): void {
		if ( function_exists( 'as_supports' ) && as_supports( 'ensure_recurring_actions_hook' ) ) {
			add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'repair_watchdog' ) );
		}

		$this->ensure_watchdog();
	}

	/** @return void */
	public function repair_watchdog(): void {
		$this->ensure_watchdog( true );
	}

	/**
	 * Asegura un watchdog recurrente. Un retorno 0 no modifica deuda durable.
	 *
	 * @return void
	 */
	public function ensure_watchdog( bool $force = false ): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! $force && function_exists( 'get_transient' ) && 1 === (int) get_transient( self::WATCHDOG_GUARD_TRANSIENT ) ) {
			return;
		}

		$existe = ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::HOOK_WATCHDOG, array(), Indexer::AS_GROUP ) )
			|| ( function_exists( 'as_next_scheduled_action' )
				&& false !== as_next_scheduled_action( self::HOOK_WATCHDOG, array(), Indexer::AS_GROUP ) );

		if ( $existe ) {
			// Sólo en la reparación diaria (`$force`), no en cada petición: es
			// una consulta más a la cola, y un intervalo cambiado por despliegue
			// puede esperar a la vuelta siguiente de la reparación.
			if ( ! $force || ! self::watchdog_interval_drifted() ) {
				self::mark_watchdog_guarded();
				return;
			}
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK_WATCHDOG, array(), Indexer::AS_GROUP );
			}
		}

		$action_id = as_schedule_recurring_action(
			time() + self::WATCHDOG_INTERVAL,
			self::WATCHDOG_INTERVAL,
			self::HOOK_WATCHDOG,
			array(),
			Indexer::AS_GROUP,
			true
		);
		if ( 0 !== (int) $action_id ) {
			self::mark_watchdog_guarded();
		}
	}

	/**
	 * Procedimiento canónico de rescate para el motor de búsqueda.
	 *
	 * Aplica un protocolo de orden estricto para evitar bloqueos huérfanos de topología:
	 * si se retirase el estado persistido antes de eliminar las tablas sombra `_new`,
	 * el sistema clasificaría la topología como huérfana y entraría en bloqueo terminal.
	 *
	 * Secuencia de operaciones:
	 *   1. Preservar integridad si existen tablas `_old` (estado de rollback post-rename).
	 *   2. Respetar ejecuciones activas que sigan progresando.
	 *   3. Eliminar y verificar la remoción de tablas sombra `_new` en primer término.
	 *   4. Una vez confirmada la ausencia de tablas sombra, limpiar el estado persistido y cerrojos.
	 *   5. Cancelar tareas de bombeo residuales encoladas en Action Scheduler.
	 *
	 * No inicia la reconstrucción directamente; delega el re-encolado al invocador.
	 *
	 * @return array{ok:bool,codigo:string,mensaje:string}
	 */
	public static function rescue(): array {
		$topologia = Install::inspect_table_topology();
		if ( empty( $topologia['valid'] ) ) {
			return self::rescue_result( false, 'topologia_ilegible', 'No pude leer qué tablas del índice existen. No toqué nada.' );
		}
		if ( in_array( true, (array) ( $topologia['topology']['old'] ?? array() ), true ) ) {
			return self::rescue_result( false, 'hay_old', 'Hay tablas `_old`: el RENAME ya ocurrió y `_old` es la única vuelta atrás. No toqué nada; mira la foto y decide.' );
		}

		$estado = self::status();
		if ( null !== $estado
			&& ! self::is_terminal_phase( (string) ( $estado['phase'] ?? '' ) )
			&& empty( $estado['stalled'] ) ) {
			return self::rescue_result( false, 'run_vivo', 'Hay un rebuild en marcha y avanza (o va lento: alguien reclamó trabajo hace menos de dos minutos). No toqué nada: espera a que termine, o reintenta en unos minutos si de verdad murió.' );
		}

		if ( ! Install::drop_shadow_tables() || Install::TOPOLOGY_CLEAN_PRE !== Install::classify_topology() ) {
			return self::rescue_result( false, 'sombra_no_suelta', 'No pude soltar las tablas `_new` (¿alguien tiene la cerca de escritura?). NO retiré el estado: hacerlo ahora acuñaría el pestillo permanente. Reintenta.' );
		}

		delete_option( self::OPTION_STATE );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Indexer::HOOK_PUMP, array(), Indexer::AS_GROUP );
		}

		AuditLog::record( 'rescate', 'Rescate del buscador: sombra soltada, estado retirado y pumps limpios; falta el rebuild' );

		return self::rescue_result( true, 'rescatado', 'Sombra soltada, estado retirado y cola limpia. Ahora corre el rebuild.' );
	}

	/**
	 * @param bool   $ok      Si se rescató.
	 * @param string $codigo  Código estable para quien automatiza.
	 * @param string $mensaje Frase para la persona de guardia.
	 * @return array{ok:bool,codigo:string,mensaje:string}
	 */
	private static function rescue_result( bool $ok, string $codigo, string $mensaje ): array {
		return array(
			'ok'      => $ok,
			'codigo'  => $codigo,
			'mensaje' => $mensaje,
		);
	}

	/**
	 * ¿La acción viva del watchdog corre con un intervalo distinto al vigente?
	 *
	 * `as_schedule_recurring_action()` no toca una acción que ya existe, así que
	 * cambiar WATCHDOG_INTERVAL en un despliegue dejaba corriendo el intervalo
	 * viejo para siempre, y el único remedio era un comando a mano —que además
	 * estaba escrito con el grupo equivocado—.
	 *
	 * @return bool False también cuando no se puede saber: ante la duda no se toca.
	 */
	private static function watchdog_interval_drifted(): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$vivas = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK_WATCHDOG,
				'group'    => Indexer::AS_GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			)
		);

		foreach ( (array) $vivas as $accion ) {
			$agenda = is_object( $accion ) && method_exists( $accion, 'get_schedule' ) ? $accion->get_schedule() : null;
			if ( is_object( $agenda ) && method_exists( $agenda, 'get_recurrence' ) ) {
				return (int) $agenda->get_recurrence() !== self::WATCHDOG_INTERVAL;
			}
		}

		return false;
	}

	/**
	 * Programa un rebuild durable. Force despierta uno existente, no lo borra.
	 *
	 * @param bool $force Reintentar recovery/wake-up de un estado existente.
	 * @return bool True si existe un run durable nuevo o reactivado.
	 */
	public static function schedule( bool $force = false ): bool {
		if ( null === self::$runtime ) {
			return false;
		}

		$existing = self::read_state();
		if ( null !== $existing ) {
			if ( ! $force || self::is_terminal_phase( (string) ( $existing['phase'] ?? '' ) ) ) {
				if ( ! $force && ! self::is_terminal_phase( (string) ( $existing['phase'] ?? '' ) ) ) {
					Install::request_rebuild( 'schedule_while_active' );
				}
				return false;
			}

			self::$runtime->handle_recovery();
			self::$runtime->resume_run( (string) ( $existing['run_id'] ?? '' ), true );
			return true;
		}

		if ( self::$state_read_failed || ! Install::runtime_ready() ) {
			return false;
		}
		if ( $force ) {
			self::$runtime->handle_recovery( true );
			$existing = self::read_state();
			if ( self::$state_read_failed || null !== $existing ) {
				return false;
			}
		}

		$request = Install::pending_rebuild_request();
		if ( null !== $request ) {
			$run_id = self::schedule_request( (string) $request['token'], (int) $request['seq'] );
			if ( '' === $run_id ) {
				return false;
			}
			Install::acknowledge_pending_rebuild( (string) $request['token'], (int) $request['seq'] );
			return true;
		}

		return self::$runtime->start_run( true );
	}

	/**
	 * Crea o recupera el run durable que pertenece a una señal concreta.
	 *
	 * @param string $request_token Token de la primera solicitud pendiente.
	 * @param int    $request_seq   Revisión observada de esa solicitud.
	 * @return string Run ID durable, o cadena vacía si hay run ajeno/gate.
	 */
	public static function schedule_request( string $request_token, int $request_seq ): string {
		if ( null === self::$runtime || '' === $request_token || $request_seq <= 0
			|| ! Install::runtime_ready() ) {
			return '';
		}

		$request = Install::pending_rebuild_request();
		if ( null === $request
			|| $request_token !== (string) $request['token']
			|| $request_seq !== (int) $request['seq'] ) {
			return '';
		}

		$durable = self::durable_run_id_for_request( $request_token, $request_seq );
		if ( '' !== $durable ) {
			return $durable;
		}
		$raw_state = self::read_state_raw();
		if ( self::$state_read_failed || null !== $raw_state ) {
			return '';
		}

		self::$runtime->start_run( true, $request_token, $request_seq );
		return self::durable_run_id_for_request( $request_token, $request_seq );
	}

	/**
	 * Identidad de request del estado actual; null también cubre lectura fallida.
	 *
	 * @return array{token: string, seq: int}|null
	 */
	public static function current_request_identity(): ?array {
		$state = self::read_state();
		if ( self::$state_read_failed || null === $state ) {
			return null;
		}
		$token = (string) ( $state['request_token'] ?? '' );
		$seq   = (int) ( $state['request_seq'] ?? 0 );
		return '' !== $token && $seq > 0 ? array( 'token' => $token, 'seq' => $seq ) : null;
	}

	/**
	 * Prueba de correlación usada antes del CAS que retira la señal.
	 *
	 * @param string $request_token Token solicitado.
	 * @param int    $request_seq   Revisión solicitada.
	 * @return string Run correlacionado, o cadena vacía.
	 */
	public static function durable_run_id_for_request( string $request_token, int $request_seq ): string {
		$state = self::read_state();
		if ( self::$state_read_failed || null === $state
			|| $request_token !== (string) ( $state['request_token'] ?? '' )
			|| $request_seq !== (int) ( $state['request_seq'] ?? 0 ) ) {
			return '';
		}

		return (string) ( $state['run_id'] ?? '' );
	}

	/**
	 * Estado observable, conservando las claves consumidas por la UI existente.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function status(): ?array {
		$state = self::read_state();
		if ( null === $state ) {
			if ( self::$state_read_failed || self::$state_row_present ) {
				$error = self::$state_read_failed ? 'state_read_failed' : 'state_decode_failed';
				return array(
					'protocol'     => 2,
					'run_id'       => '',
					'phase'        => self::PHASE_BLOCKED,
					'error'        => $error,
					'offset'       => 0,
					'done'         => 0,
					'total'        => 0,
					'started_at'   => 0,
					'updated_at'   => time(),
					'heartbeat_at' => 0,
					'topology'     => Install::TOPOLOGY_AMBIGUOUS,
					'work'         => array( 'error' => $error ),
					'work_pending' => 0,
					'work_leased'  => 0,
					'work_blocked' => 0,
					'stalled'      => true,
				);
			}
			return null;
		}

		$run_id      = (string) ( $state['run_id'] ?? '' );
		$total       = (int) ( $state['expected'] ?? 0 );
		$outstanding = $total;
		$work        = false;
		$leases      = false;
		if ( null !== self::$runtime && '' !== $run_id ) {
			$count = self::$runtime->work_count( $run_id, WorkRepository::SCOPE_BASELINE );
			if ( null !== $count ) {
				$outstanding = $count;
			}
			$work   = self::$runtime->work->summary( $run_id );
			$leases = self::$runtime->work->active_lease_count( $run_id );
		}
		$done       = max( 0, $total - $outstanding );
		$updated_at = (int) ( $state['updated_at'] ?? ( $state['started_at'] ?? 0 ) );
		$heartbeat  = (int) ( $state['heartbeat_at'] ?? $updated_at );
		$work_stats = self::work_status_totals( is_array( $work ) ? $work : array() );

		return array_merge(
			$state,
			array(
				'offset'     => (int) floor( $done / max( 1, self::BATCH_SIZE ) ),
				'done'       => $done,
				'total'      => $total,
				'started_at' => (int) ( $state['started_at'] ?? 0 ),
				'updated_at' => $updated_at,
				'heartbeat_at' => $heartbeat,
				'topology'   => Install::classify_topology(),
				'work'       => is_array( $work ) ? $work : array( 'error' => null === self::$runtime ? 'runtime_unavailable' : self::$runtime->work->last_error() ),
				'work_pending' => $work_stats['pending'],
				'work_leased'  => $work_stats['leased'],
				'work_blocked' => $work_stats['blocked'],
				// `done` refleja el progreso de la fase baseline (`work_count(run, SCOPE_BASELINE)`).
				// Durante la fase CATCHING_UP, las tareas de reparación se informan en `repair_pending`
				// de forma independiente para mantener claridad sobre el estado de drenaje.
				'repair_pending' => is_array( $work )
					? (int) ( $work[ WorkRepository::SCOPE_REPAIR ][ WorkRepository::STATUS_PENDING ] ?? 0 )
						+ (int) ( $work[ WorkRepository::SCOPE_REPAIR ][ WorkRepository::STATUS_LEASED ] ?? 0 )
					: 0,
				'active_leases' => false === $leases ? -1 : (int) $leases,
				'stalled'       => self::stalled_verdict(
					self::is_terminal_phase( (string) ( $state['phase'] ?? '' ) ),
					time() - $heartbeat,
					$work_stats['pending'] > 0 || $work_stats['leased'] > 0 || $work_stats['blocked'] > 0 || false === $work,
					$leases
				),
			)
		);
	}

	/**
	 * Determina si una ejecución de reconstrucción se encuentra efectivamente detenida.
	 *
	 * Un latido antiguo no garantiza por sí solo la muerte del proceso si existen operaciones
	 * demoradas por contención en base de datos. Para evitar descartar tablas sombra activas,
	 * la condición de detención requiere ausencia total de leases vigentes además de haber
	 * superado el umbral STALLED_SECONDS con trabajo pendiente.
	 *
	 * @param bool      $terminal            La fase ya no avanza por diseño.
	 * @param int       $segundos_sin_latido Tiempo transcurrido desde el último latido.
	 * @param bool      $queda_trabajo       Indica si existe trabajo pendiente o ilegible.
	 * @param int|false $leases_vigentes     Leases sin caducar del run; false si no se pudo contar.
	 * @return bool
	 */
	public static function stalled_verdict( bool $terminal, int $segundos_sin_latido, bool $queda_trabajo, $leases_vigentes ): bool {
		if ( $terminal || $segundos_sin_latido < self::STALLED_SECONDS || ! $queda_trabajo ) {
			return false;
		}

		return ! ( is_int( $leases_vigentes ) && $leases_vigentes > 0 );
	}

	/**
	 * Definición canónica del predicado para productos elegibles en el índice.
	 *
	 * Centraliza el fragmento WHERE y sus parámetros para garantizar coherencia
	 * entre la obtención de identificadores y el conteo en HealthReport.
	 *
	 * @return array{0: string, 1: array<int, string>} Fragmento WHERE y sus parámetros.
	 */
	public static function eligible_products_where(): array {
		return array( 'post_type = %s AND post_status = %s', array( 'product', 'publish' ) );
	}

	/**
	 * Total del universo fuente exacto.
	 *
	 * @return int
	 */
	public static function eligible_product_count(): int {
		$ids = self::eligible_product_ids_snapshot();
		return null === $ids ? 0 : count( $ids );
	}

	/**
	 * Expone el mismo universo fuente del baseline para barreras globales.
	 *
	 * @return array<int, int>|null IDs exactos o null ante cualquier error SQL.
	 */
	public static function eligible_product_ids_snapshot(): ?array {
		return self::query_eligible_product_ids();
	}

	/**
	 * Barrera uncached para lectores públicos durante el cutover.
	 *
	 * `generation_target` se persiste en SWAP_INTENT antes del RENAME y el state
	 * sólo desaparece tras publication receipt + cleanup. Por eso un target
	 * activo cierra el índice tanto antes como después de un crash de RENAME.
	 * Ausencia verificada deja pasar generaciones live normales; error/corrupción
	 * falla cerrado.
	 */
	public static function publication_barrier_open(): bool {
		$raw = self::read_state_raw();
		if ( self::$state_read_failed ) {
			return false;
		}
		if ( null === $raw ) {
			return true;
		}

		$state = json_decode( $raw, true );
		if ( ! is_array( $state ) || ! self::valid_state_shape( $state ) ) {
			return false;
		}
		if ( '' !== (string) ( $state['generation_target'] ?? '' ) ) {
			return false;
		}

		$phase = (string) $state['phase'];
		if ( in_array( $phase, array( self::PHASE_PREPARING, self::PHASE_BUILDING, self::PHASE_CATCHING_UP ), true ) ) {
			return true;
		}
		if ( in_array( $phase, array( self::PHASE_BLOCKED, self::PHASE_ABORTED ), true ) ) {
			return in_array(
				(string) ( $state['topology'] ?? '' ),
				array( Install::TOPOLOGY_CLEAN_PRE, Install::TOPOLOGY_READY_SWAP ),
				true
			);
		}

		return false;
	}

	/**
	 * Despierta deuda de un run existente; AS nunca es el estado durable.
	 *
	 * @param string $run_id Run dueño.
	 * @param string $scope  baseline|repair.
	 * @param int    $delay_seconds Demora mínima.
	 * @return bool
	 */
	public static function wake_existing_run( string $run_id, string $scope, int $delay_seconds = 0 ): bool {
		if ( null === self::$runtime || 0 > $delay_seconds
			|| ! in_array( $scope, array( WorkRepository::SCOPE_BASELINE, WorkRepository::SCOPE_REPAIR ), true ) ) {
			return false;
		}
		$state = self::read_state();
		if ( null === $state || $run_id !== (string) ( $state['run_id'] ?? '' ) ) {
			return false;
		}

		return self::enqueue_wakeup( $run_id, $scope, $delay_seconds );
	}

	/**
	 * Historial mas reciente primero.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function history(): array {
		$raw = get_option( self::OPTION_HISTORY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Recorre la misma maquina de estados sin depender de los wake-ups AS.
	 *
	 * @return array{docs: int, seconds: float, error: string}|null
	 */
	public function run_sync(): ?array {
		/*
		 * Requisito estricto: preparación del esquema físico.
		 *
		 * La vía de recuperación síncrona/manual requiere exclusivamente `runtime_schema_ready()`,
		 * omitiendo el debounce temporal de `pending_rebuild_is_ready()` para evitar que el tráfico
		 * concurrente aplace indefinidamente una intervención requerida.
		 * La existencia de ejecuciones activas se verifica de forma determinista mediante `run_sync_locked()` y `read_state()`.
		 */
		if ( ! Install::runtime_schema_ready() ) {
			return null;
		}

		// La recuperación manual sí espera por la valla. `finally` la repone
		// aunque el trabajo lance: la instancia es de larga vida y dejarla en
		// modo espera contagiaría al carril web.
		$this->sync_fence_wait = self::SYNC_FENCE_WAIT_SECONDS;
		try {
			return $this->run_sync_locked();
		} finally {
			$this->sync_fence_wait = 0;
		}
	}

	/** Cuánto espera la recuperación manual por la valla. */
	private const SYNC_FENCE_WAIT_SECONDS = 10;

	/** @return array<string, mixed>|null */
	private function run_sync_locked(): ?array {
		$this->handle_recovery( true );
		if ( self::$state_read_failed || null !== self::read_state() ) {
			return null;
		}

		$request = Install::pending_rebuild_request();
		$token   = null === $request ? '' : (string) $request['token'];
		$seq     = null === $request ? 0 : (int) $request['seq'];
		if ( ! $this->start_run( false, $token, $seq ) ) {
			return null;
		}
		if ( '' !== $token ) {
			$durable = self::durable_run_id_for_request( $token, $seq );
			if ( '' === $durable || ! Install::acknowledge_pending_rebuild( $token, $seq ) ) {
				return null;
			}
		}

		$started = microtime( true );
		$state   = self::read_state();
		$run_id  = (string) ( $state['run_id'] ?? '' );
		$limit   = max( 100, ( (int) ( $state['expected'] ?? 0 ) * 8 ) + 100 );

		for ( $step = 0; $step < $limit; ++$step ) {
			$state = self::read_state();
			if ( null === $state ) {
				break;
			}
			if ( $run_id !== (string) ( $state['run_id'] ?? '' ) ) {
				return null;
			}

			$phase = (string) ( $state['phase'] ?? '' );
			if ( self::PHASE_BUILDING === $phase ) {
				$this->pump_scope( $run_id, 'baseline', false );
			} elseif ( self::PHASE_CATCHING_UP === $phase ) {
				$baseline = $this->work_count( $run_id, WorkRepository::SCOPE_BASELINE );
				$scope    = null === $baseline || $baseline > 0 ? WorkRepository::SCOPE_BASELINE : WorkRepository::SCOPE_REPAIR;
				$this->pump_scope( $run_id, $scope, false );
			} elseif ( in_array( $phase, array( self::PHASE_SWAP_INTENT, self::PHASE_SWAPPED, self::PHASE_PUBLISHING, self::PHASE_CLEANUP, self::PHASE_DONE ), true ) ) {
				$this->resume_run( $run_id, false );
			} else {
				break;
			}
		}

		$last  = get_option( self::OPTION_LAST, array() );
		$last  = is_array( $last ) ? $last : array();
		$error = (string) ( $last['error'] ?? '' );

		if ( null !== self::read_state() && '' === $error ) {
			$error = 'state_machine_did_not_converge';
		}

		return array(
			'docs'     => (int) ( $last['docs'] ?? 0 ),
			// Lo que ESTE rebuild se comprometió a indexar, contado al empezar:
			// es contra lo que se mide si quedó completo. Un conteo tomado
			// después incluye las fichas publicadas durante la ventana, que no
			// son un agujero del rebuild sino deuda viva que el bombeo ya lleva.
			'expected' => (int) ( $last['expected'] ?? 0 ),
			'seconds'  => round( microtime( true ) - $started, 2 ),
			'error'    => $error,
		);
	}

	/**
	 * Handler de un wake-up. Una accion stale nunca avanza el run vigente.
	 *
	 * @param string $run_id Run dueño de la accion.
	 * @param string $scope  baseline|repair.
	 * @return void
	 */
	public function handle_batch( $run_id = '', $scope = 'baseline' ): void {
		$run_id = (string) $run_id;
		$scope  = (string) $scope;
		$state  = self::read_state();

		if ( null === $state || ! self::action_matches_state( $state, $run_id, $scope ) ) {
			return;
		}

		$this->pump_scope( $run_id, $scope, true );
	}

	/**
	 * Recupera leases vencidas, topologia/fase y reaviva trabajo pendiente.
	 *
	 * @return void
	 */
	public function handle_watchdog(): void {
		Install::consume_generation_publications();
		$this->work->recover_expired();

		// Sólo se despierta la bomba si de verdad hay deuda live. El watchdog
		// corre cada 15 minutos (WATCHDOG_INTERVAL), y antes encolaba una acción SIEMPRE: en
		// producción eso dejó 4.949 acciones completadas que no tenían una sola
		// fila que procesar. Cada una es una fila en la cola de Action Scheduler,
		// un lease y una pasada del runner, y con la cola llena de ruido cuesta
		// más ver el trabajo real cuando algo va mal.
		//
		// Degradación ABIERTA: si el conteo falla (`false`), se despierta igual.
		// Un wake de más no cuesta nada; uno de menos deja el índice congelado.
		$pendiente = $this->work->outstanding_count( '', WorkRepository::SCOPE_LIVE );
		if ( ! is_int( $pendiente ) || $pendiente > 0 ) {
			$this->indexer->wake_live_work();
		}

		$this->handle_recovery( true );

		// Reanuda ejecuciones activas cuando corresponde y evalúa siempre la disponibilidad del servido.
		$state = self::read_state();
		if ( ! self::watchdog_recovers_serving( $state ) ) {
			$this->resume_run( (string) ( $state['run_id'] ?? '' ), true );
		}

		$this->maybe_recover_serving( $state );
	}

	/**
	 * Determina si el watchdog debe intentar recuperación autónoma de servido.
	 *
	 * Cuando el estado es nulo o se encuentra en una fase terminal no reanudable (BLOCKED o ABORTED),
	 * se autoriza la reconciliación autónoma para restablecer el servido sin esperar intervención manual.
	 * Las fases activas (BUILDING, CATCHING_UP, SWAP_INTENT, PUBLISHING) se delegan al flujo de reanudación
	 * para evitar colisiones con procesos en ejecución.
	 *
	 * @param array<string, mixed>|null $state Estado persistido, o null si no existe.
	 * @return bool
	 */
	private static function watchdog_recovers_serving( ?array $state ): bool {
		if ( null === $state ) {
			return true;
		}

		$phase = (string) ( $state['phase'] ?? '' );

		return self::PHASE_BLOCKED === $phase || self::PHASE_ABORTED === $phase;
	}

	/**
	 * Supervisa la disponibilidad del servido semántico en cada ciclo del watchdog.
	 *
	 * Evalúa el estado del sistema bajo tres escenarios operacionales:
	 *   1. Sin estado activo: ejecuta reconciliación inmediata y, si persiste la indisponibilidad,
	 *      solicita una reconstrucción de recuperación.
	 *   2. Estado bloqueado o abortado: ejecuta reconciliación inmediata sin encolar reconstrucciones
	 *      adicionales para evitar saturación de auditoría mientras la exclusión mutua requiera rescate.
	 *   3. Ejecución activa en progreso: monitorea métricas de servido sin interferir con el proceso en curso.
	 *
	 * @param array<string, mixed>|null $state Estado leído durante el ciclo actual.
	 * @return void
	 */
	private function maybe_recover_serving( ?array $state ): void {
		if ( ! Search::suggest_configured() ) {
			// Carril desactivado por configuración: no constituye caída; se cierra cualquier episodio abierto.
			self::note_lane_off();
			return;
		}
		if ( ! Install::runtime_schema_ready() ) {
			// Esquema no sellado: no se puede evaluar ni reconstruir.
			return;
		}

		if ( self::watchdog_recovers_serving( $state ) ) {
			// Reconciliación ligera: revalida el receipt contra las tablas vigentes sin reconstrucción completa.
			EditorialDiscountPublication::reconcile_now();
		}
		// La verificación utiliza `serving_ready_fresh()` para evaluar el estado actualizado en base de datos,
		// evitando falsos positivos derivados de lecturas cacheadas al inicio de la petición.
		if ( EditorialDiscountPublication::serving_ready_fresh() ) {
			self::note_serving_up();
			return;
		}

		if ( null === $state ) {
			self::request_recovery_rebuild();
		}
		self::note_serving_down();
	}

	/**
	 * Pide y consume el rebuild de recuperación. Sólo tiene sentido SIN fila de
	 * estado: con cualquier estado presente —vivo o trabado— `schedule_request()`
	 * no arranca nada y la señal se quedaría esperando a nadie.
	 *
	 * @return void
	 */
	private static function request_recovery_rebuild(): void {
		if ( null === Install::pending_rebuild_request()
			&& ! Install::request_rebuild( 'serving_recovery', time() ) ) {
			return; // La señal no pudo persistirse; el próximo ciclo reintenta.
		}
		AuditLog::record( 'serving_recovery', 'Watchdog: serving caído sin run activo; rebuild solicitado' );
		Install::consume_pending_rebuild();
	}

	/** Cuánto aguanta el serving caído antes de que salga el aviso. */
	private const ALARM_AFTER_SECONDS = 900;

	/** Estado del aviso: desde cuándo está caído y si ya se avisó. */
	private const OPTION_ALARM = 'akb_search_serving_alarm';

	/**
	 * Registra la persistencia de una caída en el servido y despacha alerta al administrador si supera el umbral.
	 *
	 * Emite un único correo de alerta por episodio tras exceder {@see self::ALARM_AFTER_SECONDS}.
	 * El umbral supera el tiempo estándar de reconstrucción para evitar falsas alarmas durante tareas rutinarias.
	 */
	private static function note_serving_down(): void {
		$estado = get_option( self::OPTION_ALARM, array() );
		$estado = is_array( $estado ) ? $estado : array();
		$desde  = (int) ( $estado['down_since'] ?? 0 );
		$avisado = (int) ( $estado['notified_at'] ?? 0 );
		$ahora  = time();

		if ( $desde <= 0 ) {
			update_option( self::OPTION_ALARM, array( 'down_since' => $ahora, 'notified_at' => 0 ), false );
			return;
		}
		if ( $avisado > 0 || ( $ahora - $desde ) < self::ALARM_AFTER_SECONDS ) {
			return;
		}

		update_option( self::OPTION_ALARM, array( 'down_since' => $desde, 'notified_at' => $ahora ), false );
		self::send_alarm(
			'Akibara: el buscador lleva rato caído',
			"El buscador y los filtros del catálogo llevan " . (int) floor( ( $ahora - $desde ) / 60 ) . " minutos sin servir.

"
			. "El cliente ve el catálogo completo SIN filtros y la búsqueda no responde. El sistema ya intentó
"
			. "recuperarse solo varias veces y no lo ha conseguido.

"
			. "Qué mirar, por orden:
"
			. "  wp akb-search status
"
			. "  wp akb-search rebuild

"
			. "Si el rebuild dice que hay uno en curso, comprueba en el estado que «done» avanza y que
"
			. "«work_blocked» está en cero: si avanza, es un rebuild normal y sólo hay que esperar."
		);
		AuditLog::record( 'serving_alarma', 'Serving caído más de ' . self::ALARM_AFTER_SECONDS . ' s: aviso enviado' );
	}

	/**
	 * Limpia el estado del aviso y avisa de la vuelta si se había avisado de la
	 * caída. Sin esto, el dueño se quedaría con el correo de alarma y sin saber
	 * que la tienda ya está bien.
	 */
	private static function note_serving_up(): void {
		if ( self::close_alarm_episode() ) {
			self::send_alarm(
				'Akibara: el buscador volvió',
				"El buscador y los filtros del catálogo vuelven a servir con normalidad."
			);
			AuditLog::record( 'serving_alarma', 'Serving recuperado: aviso de vuelta enviado' );
		}
	}

	/**
	 * Cierra el episodio de alarma cuando el carril de autocompletado es desactivado intencionalmente.
	 *
	 * Elimina el estado de alerta sin emitir notificación de restablecimiento (evitando avisos espurios),
	 * y garantiza que una reactivación futura inicie un ciclo de monitoreo limpio sin sellos residuales.
	 *
	 * @return void
	 */
	private static function note_lane_off(): void {
		if ( self::close_alarm_episode() ) {
			AuditLog::record( 'serving_alarma', 'Autocompletado apagado con un aviso de caída enviado: episodio cerrado sin aviso de vuelta' );
		}
	}

	/**
	 * Borra el estado del aviso. Es la ÚNICA salida de un episodio, la use quien
	 * la use: que el serving volvió o que el carril se apagó.
	 *
	 * @return bool True si el episodio que se cierra ya había mandado el correo de caída.
	 */
	private static function close_alarm_episode(): bool {
		$estado = get_option( self::OPTION_ALARM, array() );
		if ( ! is_array( $estado ) || array() === $estado ) {
			return false; // Lo normal: nada abierto, nada que escribir en cada vuelta.
		}
		delete_option( self::OPTION_ALARM );

		return (int) ( $estado['notified_at'] ?? 0 ) > 0;
	}

	/**
	 * Manda el aviso al correo del sitio.
	 *
	 * Sólo en producción: en el espejo hay Mailpit y un aviso ahí es ruido que
	 * enseña a ignorar los avisos de verdad.
	 *
	 * @param string $asunto  Asunto.
	 * @param string $cuerpo  Cuerpo en texto plano.
	 */
	private static function send_alarm( string $asunto, string $cuerpo ): void {
		if ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
			return;
		}
		$destino = (string) get_option( 'admin_email', '' );
		if ( '' === $destino || ! function_exists( 'wp_mail' ) ) {
			return;
		}
		wp_mail( $destino, $asunto, $cuerpo );
	}

	/**
	 * Reconcilia fase y topologia bajo el mismo fence usado por live writers.
	 * En init normal, un state ausente retorna tras una lectura O(1): sólo el
	 * watchdog o una operación explícita inspeccionan residuos sin owner.
	 *
	 * @param mixed $inspect_orphans True sólo para auditoría periódica/forzada.
	 * @return void
	 */
	/**
	 * Tiempo de espera en segundos para la adquisición del cerrojo en ejecuciones síncronas.
	 *
	 * Mientras el carril web mantiene adquisición no bloqueante (`acquire(0)`) para proteger la
	 * concurrencia del visitante, el carril síncrono de mantenimiento manual admite un tiempo de espera
	 * acotado para adquirir la exclusión mutua frente a tareas concurrentes en segundo plano.
	 */
	private int $sync_fence_wait = 0;

	public function handle_recovery( $inspect_orphans = false ): void {
		$inspect_orphans = true === $inspect_orphans;
		$initial_raw     = self::read_state_raw();
		if ( self::$state_read_failed || ( null === $initial_raw && ! $inspect_orphans ) ) {
			return;
		}

		if ( ! $this->fence->acquire( $this->sync_fence_wait ) ) {
			return;
		}

		$run_id = '';
		$resume = false;
		try {
			$raw = self::read_state_raw();
			if ( self::$state_read_failed || ( null === $raw && ! $inspect_orphans ) ) {
				return;
			}

			$inspection = Install::inspect_table_topology();
			if ( ! (bool) $inspection['valid'] ) {
				return; // Un blip de information_schema nunca acuña BLOCKED durable.
			}
			$state             = self::decode_state( $raw );
			$topology_snapshot = $inspection['topology'];
			$topology          = (string) $inspection['classification'];

			if ( null === $raw ) {
				if ( Install::TOPOLOGY_CLEAN_PRE !== $topology ) {
					$this->persist_orphan_topology_block( $topology );
				}
			} elseif ( null === $state || ! self::valid_state_shape( $state ) ) {
				$legacy = is_array( $state ) ? $state : array();
				$legacy['run_id']     = (string) ( $legacy['run_id'] ?? self::new_run_id() );
				$legacy['started_at'] = (int) ( $legacy['started_at'] ?? time() );
				$this->block_locked( $legacy, 'legacy_or_unreadable_state', $topology );
			} else {
				$run_id   = (string) $state['run_id'];
				$phase    = (string) $state['phase'];
				if ( self::PHASE_PREPARING === $phase
					&& Install::TOPOLOGY_AMBIGUOUS === $topology
					&& Install::is_owned_partial_family( $topology_snapshot, 'new' ) ) {
					$live_schema = Install::inspect_live_writer_schema();
					if ( Install::SCHEMA_CHECK_RETRY === $live_schema ) {
						return;
					}
					if ( Install::SCHEMA_CHECK_INVALID === $live_schema ) {
						$this->block_locked( $state, 'recovery_schema_invalid', $topology );
						return;
					}
					$partial_recovery = Install::recover_owned_partial_shadow_result(
						$topology_snapshot,
						array( $this->fence, 'assert_owned' )
					);
					if ( Install::PARTIAL_RECOVERY_RETRY === $partial_recovery ) {
						return;
					}
					if ( Install::PARTIAL_RECOVERY_RECOVERED !== $partial_recovery ) {
						$this->block_locked( $state, 'partial_shadow_recovery_failed', $topology );
						return;
					}
					$topology = Install::TOPOLOGY_CLEAN_PRE;
				}
				if ( self::PHASE_CLEANUP === $phase
					&& Install::TOPOLOGY_AMBIGUOUS === $topology
					&& Install::is_owned_partial_family( $topology_snapshot, 'old' ) ) {
					if ( ! self::publication_acked( $state, true ) ) {
						$this->block_locked( $state, 'partial_old_recovery_failed', $topology );
						return;
					}
					$live_schema = Install::inspect_live_writer_schema();
					if ( Install::SCHEMA_CHECK_RETRY === $live_schema ) {
						return;
					}
					if ( Install::SCHEMA_CHECK_INVALID === $live_schema ) {
						$this->block_locked( $state, 'recovery_schema_invalid', $topology );
						return;
					}
					$partial_recovery = Install::recover_owned_partial_old_result(
						$topology_snapshot,
						array( $this->fence, 'assert_owned' )
					);
					if ( Install::PARTIAL_RECOVERY_RETRY === $partial_recovery ) {
						return;
					}
					if ( Install::PARTIAL_RECOVERY_RECOVERED !== $partial_recovery ) {
						$this->block_locked( $state, 'partial_old_recovery_failed', $topology );
						return;
					}
					$this->finish_done_locked( $state );
					return;
				}
				if ( Install::TOPOLOGY_READY_SWAP === $topology
					&& in_array( $phase, array( self::PHASE_PREPARING, self::PHASE_BUILDING, self::PHASE_CATCHING_UP, self::PHASE_SWAP_INTENT ), true )
					&& ( ! (bool) ( $state['shadow_schema_verified'] ?? false )
						|| Install::schema_seal() !== (string) ( $state['schema_seal'] ?? '' ) ) ) {
					$ready_schema = Install::inspect_ready_swap_schema();
					if ( Install::SCHEMA_CHECK_RETRY === $ready_schema ) {
						return;
					}
					if ( Install::SCHEMA_CHECK_INVALID === $ready_schema ) {
						$this->block_locked( $state, 'recovery_schema_invalid', $topology );
						return;
					}
					$state['schema_seal']             = Install::schema_seal();
					$state['shadow_schema_verified'] = true;
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						return;
					}
				}
				$decision = self::recovery_decision( (string) $state['phase'], $topology );

				if ( 'block' === $decision ) {
					$this->block_locked( $state, 'phase_topology_mismatch', $topology );
				} elseif ( 'prepare' === $decision ) {
					$resume = $this->prepare_locked( $state );
				} elseif ( 'revalidate' === $decision ) {
					// Un crash libera GET_LOCK. Un writer puede haber creado repair
					// mientras el estado seguia en SWAP_INTENT; volver siempre a
					// CATCHING_UP obliga a drenar y repetir igualdad exacta.
					$state['phase'] = self::PHASE_CATCHING_UP;
					$resume        = $this->write_state( self::touch( $state ) );
				} elseif ( 'mark_swapped' === $decision ) {
					$state['phase'] = self::PHASE_SWAPPED;
					$resume        = $this->write_state( self::touch( $state ) );
				} elseif ( 'finish_done' === $decision ) {
					$this->finish_done_locked( $state );
				} elseif ( 'resume' === $decision ) {
					$resume = true;
				}
			}
		} finally {
			$this->fence->release();
		}

		if ( $resume && '' !== $run_id ) {
			$this->resume_run( $run_id, true );
		}
	}

	/**
	 * Run pre-swap visible al Indexer live dentro de TX + WriteFence.
	 *
	 * @return string|null Run ID, cadena vacía sólo para ausencia verificada,
	 *                     o null ante lectura/topología/schema no verificable.
	 */
	public static function active_pre_swap_run_id(): ?string {
		if ( null === self::$runtime || ! self::$runtime->fence->is_owned() ) {
			return null;
		}

		$state = self::read_state( true );
		if ( self::$state_read_failed ) {
			return null;
		}

		if ( null === $state ) {
			return Install::runtime_schema_ready() ? '' : null;
		}
		if ( ! self::valid_state_shape( $state ) ) {
			return null;
		}

		$phase = (string) ( $state['phase'] ?? '' );
		if ( self::PHASE_PREPARING === $phase && Install::runtime_schema_ready() ) {
			return '';
		}
		if ( in_array( $phase, array( self::PHASE_BUILDING, self::PHASE_CATCHING_UP ), true ) ) {
			if ( ! Install::runtime_schema_ready()
				|| ! (bool) ( $state['shadow_schema_verified'] ?? false )
				|| Install::schema_seal() !== (string) ( $state['schema_seal'] ?? '' ) ) {
				return null;
			}
			$run_id = (string) ( $state['run_id'] ?? '' );
			return '' === $run_id ? null : $run_id;
		}
		if ( self::PHASE_SWAP_INTENT === $phase ) {
			if ( ! Install::runtime_schema_ready()
				|| ! (bool) ( $state['shadow_schema_verified'] ?? false )
				|| Install::schema_seal() !== (string) ( $state['schema_seal'] ?? '' ) ) {
				return null;
			}

			// El estado puede quedar SWAP_INTENT a ambos lados del RENAME. Bajo
			// WriteFence la topología no cambia durante esta decisión: READY_SWAP
			// todavía necesita repair; SWAPPED ya no tiene una sombra escribible.
			return self::swap_intent_writer_target(
				Install::classify_topology(),
				(string) ( $state['run_id'] ?? '' )
			);
		}
		if ( in_array( $phase, array( self::PHASE_SWAPPED, self::PHASE_PUBLISHING, self::PHASE_CLEANUP ), true ) ) {
			return Install::runtime_schema_ready()
				&& Install::schema_seal() === (string) ( $state['schema_seal'] ?? '' ) ? '' : null;
		}
		if ( in_array( $phase, array( self::PHASE_DONE, self::PHASE_BLOCKED, self::PHASE_ABORTED ), true ) ) {
			return Install::runtime_schema_ready()
				&& Install::TOPOLOGY_AMBIGUOUS !== (string) ( $state['topology'] ?? '' ) ? '' : null;
		}

		return null;
	}

	/**
	 * Revalida fase/run dentro de la TX shadow antes de validar el lease.
	 *
	 * No adquiere el fence: el caller ya hizo el claim en la seccion corta y
	 * esta lectura FOR UPDATE serializa la transicion final con el commit shadow.
	 *
	 * @param array<string, mixed> $claim Claim durable.
	 * @return bool
	 */
	public static function validate_shadow_claim_phase_for_update( array $claim ): bool {
		$state = self::read_state( true );
		if ( null === $state
			|| (string) ( $state['run_id'] ?? '' ) !== (string) ( $claim['run_id'] ?? '' )
			|| ! Install::runtime_schema_ready()
			|| ! (bool) ( $state['shadow_schema_verified'] ?? false )
			|| Install::schema_seal() !== (string) ( $state['schema_seal'] ?? '' ) ) {
			return false;
		}

		$scope = (string) ( $claim['scope'] ?? '' );
		$phase = (string) ( $state['phase'] ?? '' );

		return 'baseline' === $scope
			? in_array( $phase, array( self::PHASE_BUILDING, self::PHASE_CATCHING_UP ), true )
			: 'repair' === $scope && self::PHASE_CATCHING_UP === $phase;
	}

	/**
	 * Crea estado, sombra y manifest baseline dentro del fence.
	 *
	 * @param bool $async Despertar AS al terminar.
	 * @return bool
	 */
	private function start_run( bool $async, string $request_token = '', int $request_seq = 0 ): bool {
		/*
		 * En ejecuciones manuales/síncronas se requiere exclusivamente `runtime_schema_ready()`.
		 * Se omite `pending_rebuild_is_ready()` para evitar que solicitudes recurrentes de tráfico
		 * concurrente posterguen indefinidamente la ejecución de un mantenimiento manual.
		 * Las invocaciones web y asíncronas preservan la comprobación completa de `runtime_ready()`.
		 */
		$listo = $this->sync_fence_wait > 0
			? Install::runtime_schema_ready()
			: Install::runtime_ready();
		if ( ! $listo ) {
			return false;
		}
		if ( ! $this->fence->acquire( $this->sync_fence_wait ) ) {
			return false;
		}

		$started = false;
		$run_id  = '';
		try {
			$raw_state = self::read_state_raw();
			if ( self::$state_read_failed || null !== $raw_state
				|| Install::TOPOLOGY_CLEAN_PRE !== Install::classify_topology()
				|| ! Install::live_writer_schema_ready() ) {
				return false;
			}

			$run_id = self::new_run_id();
			// Saneamiento de trabajo huérfano: purga tareas de ejecuciones previas incompletas.
			if ( false === $this->work->purge_other_runs( $run_id ) ) {
				AuditLog::record( 'trabajo_huerfano', 'No se pudo barrer el trabajo de corridas anteriores: ' . $this->work->last_error() );
			}
			$editorial = EditorialDiscountPublication::is_registered()
				? EditorialDiscountPublication::snapshot_for_rebuild()
				: null;
			if ( EditorialDiscountPublication::is_registered() && ! is_array( $editorial ) ) {
				return false;
			}
			$state  = array(
				'protocol'           => 2,
				'run_id'             => $run_id,
				'request_token'      => $request_token,
				'request_seq'        => $request_seq,
				'schema_seal'        => Install::schema_seal(),
				'shadow_schema_verified' => false,
				'phase'              => self::PHASE_PREPARING,
				'started_at'         => time(),
				'updated_at'         => time(),
				'heartbeat_at'       => time(),
				'expected'           => 0,
				'generation_target'  => '',
				'semantic_fingerprint' => '',
				'ack_generation'     => false,
				'ack_rest_purge'     => false,
				'ack_price_buckets'  => false,
				'ack_semantic_fingerprint' => false,
				'editorial_wanted_seq' => is_array( $editorial ) ? (int) $editorial['wanted_seq'] : 0,
				'editorial_fingerprint' => is_array( $editorial ) ? (string) $editorial['fingerprint'] : '',
				'ack_editorial_receipt' => ! is_array( $editorial ),
				'ack_history'        => false,
				'error'              => '',
			);

			if ( ! self::claim_state( $state ) ) {
				return false;
			}

			$started = $this->prepare_locked( $state );
		} finally {
			$this->fence->release();
		}

		if ( $started && $async ) {
			$this->resume_run( $run_id, $async );
		}

		return $started;
	}

	/**
	 * Materializa sombra y manifest. Puede repetirse tras crash en PREPARING.
	 *
	 * @param array<string, mixed> $state Estado PREPARING.
	 * @return bool
	 */
	private function prepare_locked( array $state ): bool {
		if ( ! $this->assert_fence_owned() ) {
			return false;
		}

		$inspection = Install::inspect_table_topology();
		if ( ! (bool) $inspection['valid'] ) {
			return false;
		}
		$topology_snapshot = $inspection['topology'];
		$topology          = (string) $inspection['classification'];

		$live_schema = Install::inspect_live_writer_schema();
		if ( Install::SCHEMA_CHECK_RETRY === $live_schema ) {
			return false;
		}
		if ( Install::SCHEMA_CHECK_INVALID === $live_schema ) {
			$this->block_locked( $state, 'live_schema_invalid', $topology );
			return false;
		}

		if ( Install::TOPOLOGY_CLEAN_PRE === $topology ) {
			if ( ! $this->assert_fence_owned() ) {
				return false;
			}
			Install::create_shadow_tables();
			if ( ! $this->assert_fence_owned() ) {
				return false;
			}
			$post_create = Install::inspect_table_topology();
			if ( ! (bool) $post_create['valid'] ) {
				return false;
			}
			$topology_snapshot = $post_create['topology'];
			$topology          = (string) $post_create['classification'];
		}

		if ( Install::TOPOLOGY_READY_SWAP !== $topology ) {
			if ( ( Install::TOPOLOGY_CLEAN_PRE === $topology
					|| ( Install::TOPOLOGY_AMBIGUOUS === $topology
						&& Install::is_owned_partial_family( $topology_snapshot, 'new' ) ) )
				&& $this->recover_observed_shadow_create_failure( $state, array(
					'valid'          => true,
					'topology'       => $topology_snapshot,
					'classification' => $topology,
				) ) ) {
				$topology = Install::TOPOLOGY_READY_SWAP;
			} else {
				if ( Install::TOPOLOGY_CLEAN_PRE !== $topology
					&& ! ( Install::TOPOLOGY_AMBIGUOUS === $topology
						&& Install::is_owned_partial_family( $topology_snapshot, 'new' ) ) ) {
					$this->block_locked( $state, 'shadow_topology_not_ready', $topology );
				}
				return false;
			}
		}

		$ready_schema = Install::inspect_ready_swap_schema();
		if ( Install::SCHEMA_CHECK_RETRY === $ready_schema ) {
			return false;
		}
		if ( Install::SCHEMA_CHECK_INVALID === $ready_schema ) {
			$this->block_locked( $state, 'shadow_schema_invalid', $topology );
			return false;
		}
		if ( ! $this->assert_fence_owned() ) {
			return false;
		}

		$ids = self::query_eligible_product_ids();
		if ( null === $ids ) {
			$this->block_locked( $state, 'baseline_source_query_failed', $topology );
			return false;
		}
		if ( ! $this->work->seed_baseline( (string) $state['run_id'], $ids ) ) {
			$this->block_locked( $state, 'baseline_manifest_failed', $topology );
			return false;
		}
		if ( ! $this->assert_fence_owned() ) {
			return false;
		}

		$state['expected']               = count( $ids );
		$state['schema_seal']            = Install::schema_seal();
		$state['shadow_schema_verified'] = true;
		$state['phase']                  = self::PHASE_BUILDING;
		$state['error']                  = '';
		unset( $state['recovery_pending'] );

		return $this->write_state( self::touch( $state ) );
	}

	/**
	 * Un dbDelta observado puede haber creado sólo parte de `_new`. Como el
	 * estado sigue en PREPARING y el run es su dueño, retira inmediatamente ese
	 * subconjunto y reintenta una vez. Si vuelve a fallar, conserva PREPARING
	 * observable para que el watchdog repita; nunca lo convierte en BLOCKED.
	 *
	 * @param array<string, mixed>                                                               $state      Estado PREPARING.
	 * @param array{valid: bool, topology: array<string, array<string, bool>>, classification: string}|null $inspection Foto ya leída.
	 * @return bool True sólo si el segundo intento dejó una sombra exacta.
	 */
	private function recover_observed_shadow_create_failure( array $state, ?array $inspection = null ): bool {
		$inspection = null === $inspection ? Install::inspect_table_topology() : $inspection;
		if ( ! (bool) $inspection['valid'] ) {
			return false;
		}
		$topology_snapshot = $inspection['topology'];
		$topology          = (string) $inspection['classification'];
		if ( Install::TOPOLOGY_READY_SWAP === $topology ) {
			$schema = Install::inspect_ready_swap_schema();
			if ( Install::SCHEMA_CHECK_RETRY === $schema ) {
				return false;
			}
			if ( Install::SCHEMA_CHECK_READY === $schema ) {
				return true;
			}
			$this->block_locked( $state, 'shadow_schema_invalid', $topology );
			return false;
		}
		$decision = self::ddl_failure_decision( self::PHASE_PREPARING, $topology, $topology_snapshot );

		if ( 'recover_shadow' === $decision ) {
			$partial_recovery = Install::recover_owned_partial_shadow_result(
				$topology_snapshot,
				array( $this->fence, 'assert_owned' )
			);
			if ( Install::PARTIAL_RECOVERY_RETRY === $partial_recovery ) {
				$this->persist_recoverable_state( $state, 'partial_shadow_create_retry', $topology );
				return false;
			}
			if ( Install::PARTIAL_RECOVERY_RECOVERED !== $partial_recovery ) {
				$this->block_locked( $state, 'shadow_create_failed', $topology );
				return false;
			}
		} elseif ( 'retry' !== $decision ) {
			$this->block_locked( $state, 'shadow_create_failed', $topology );
			return false;
		}

		if ( ! $this->assert_fence_owned() ) {
			return false;
		}
		Install::create_shadow_tables();
		if ( ! $this->assert_fence_owned() ) {
			return false;
		}
		$retry_inspection = Install::inspect_table_topology();
		if ( ! (bool) $retry_inspection['valid'] ) {
			return false;
		}
		$retry_snapshot = $retry_inspection['topology'];
		$retry_topology = (string) $retry_inspection['classification'];
		if ( Install::TOPOLOGY_READY_SWAP === $retry_topology ) {
			$schema = Install::inspect_ready_swap_schema();
			if ( Install::SCHEMA_CHECK_RETRY === $schema ) {
				return false;
			}
			if ( Install::SCHEMA_CHECK_READY === $schema ) {
				return true;
			}
			$this->block_locked( $state, 'shadow_schema_invalid', $retry_topology );
			return false;
		}

		$retry_decision = self::ddl_failure_decision( self::PHASE_PREPARING, $retry_topology, $retry_snapshot );
		if ( in_array( $retry_decision, array( 'recover_shadow', 'retry' ), true ) ) {
			$this->persist_recoverable_state( $state, 'shadow_create_retry', $retry_topology );
			return false;
		}
		$this->block_locked( $state, 'shadow_create_failed', $retry_topology );
		return false;
	}

	/**
	 * Registra un error transitorio sin sacar el run de PREPARING/CLEANUP.
	 *
	 * @param array<string, mixed> $state    Estado dueño.
	 * @param string               $error    Código estable.
	 * @param string               $topology Topología observada.
	 * @return bool
	 */
	private function persist_recoverable_state( array $state, string $error, string $topology ): bool {
		$state['error']            = $error;
		$state['topology']         = $topology;
		$state['recovery_pending'] = true;
		return $this->write_state( self::touch( $state ) );
	}

	/**
	 * Procesa claims del scope y luego intenta avanzar la maquina.
	 *
	 * Encadena lotes de forma síncrona dentro de la misma invocación mientras exista deuda
	 * pendiente y el presupuesto de reloj (`PUMP_BUDGET_SECONDS`) lo permita.
	 * Este encadenado acota la latencia total y reduce la sobrecarga de encolado en Action Scheduler.
	 * El presupuesto temporal acota la duración de la petición de forma segura; si la ejecución
	 * concluye por límite de tiempo, la deuda durable es retomada por el siguiente lote.
	 *
	 * La acción de wake-up en Action Scheduler sólo se programa en la iteración final que agota el presupuesto.
	 *
	 * @param string $run_id Run vigente.
	 * @param string $scope  baseline|repair.
	 * @param bool   $async  Encolar otro wake-up si queda deuda.
	 * @return void
	 */
	private function pump_scope( string $run_id, string $scope, bool $async ): void {
		$deadline = microtime( true ) + self::PUMP_BUDGET_SECONDS;

		while ( true ) {
			$processed = 0;
			for ( ; $processed < self::BATCH_SIZE; ++$processed ) {
				$claim = $this->claim_shadow( $run_id, $scope );
				if ( null === $claim ) {
					break;
				}

				$this->indexer->process_shadow_claim( $claim );
			}

			$continua = self::should_chain( $processed, $deadline );

			$this->advance_run( $run_id, $async, $processed > 0, $processed > 0 && ! $continua );

			if ( ! $continua ) {
				return;
			}
		}
	}

	/**
	 * ¿Corresponde otra vuelta dentro de la MISMA invocación?
	 *
	 * Sólo si el lote salió LLENO —uno corto significa que la deuda del scope se
	 * acabó y la vuelta siguiente no tendría nada que reclamar— y si queda
	 * presupuesto de reloj.
	 *
	 * @param int   $processed Claims procesados en la vuelta.
	 * @param float $deadline  Instante límite de la invocación.
	 * @return bool
	 */
	private static function should_chain( int $processed, float $deadline ): bool {
		return $processed >= self::BATCH_SIZE && microtime( true ) < $deadline;
	}

	/**
	 * Claim shadow dentro de seccion fence corta coordinada con la fase.
	 *
	 * @param string $run_id Run vigente.
	 * @param string $scope  baseline|repair.
	 * @return array<string, mixed>|null
	 */
	private function claim_shadow( string $run_id, string $scope ): ?array {
		if ( ! $this->fence->acquire( 0 ) ) {
			return null;
		}

		try {
			$state = self::read_state();
			if ( null === $state || ! self::action_matches_state( $state, $run_id, $scope ) ) {
				return null;
			}

			return $this->work->claim_one( $scope, $run_id, self::LEASE_SECONDS );
		} finally {
			$this->fence->release();
		}
	}

	/**
	 * Avanza BUILDING/CATCHING_UP y publica solamente con igualdad exacta.
	 *
	 * @param string $run_id Run vigente.
	 * @param bool   $async  Usar wake-ups AS.
	 * @param bool   $made_progress Hubo al menos un claim procesado.
	 * @param bool   $allow_wakeup  Permitir follow-up inmediato; false deja retry al watchdog.
	 * @return void
	 */
	private function advance_run( string $run_id, bool $async, bool $made_progress = false, bool $allow_wakeup = true ): void {
		if ( ! $this->fence->acquire( 0 ) ) {
			return;
		}

		$publish = false;
		$wake    = '';
		$reconcile_editorial = false;
		try {
			do {
				$state = self::read_state();
				if ( null === $state || $run_id !== (string) ( $state['run_id'] ?? '' ) ) {
					break;
				}
				if ( $made_progress && ! $this->write_state( self::touch( $state ) ) ) {
					break;
				}

				$work_summary = $this->work->summary( $run_id );
				if ( ! is_array( $work_summary ) ) {
					$this->block_locked( $state, 'work_summary_failed', Install::classify_topology() );
					break;
				}
				$work_totals = self::work_status_totals( $work_summary );
				if ( $work_totals['blocked'] > 0 ) {
					$this->block_locked( $state, 'work_claim_blocked', Install::classify_topology() );
					break;
				}

				$phase = (string) ( $state['phase'] ?? '' );
				if ( self::PHASE_BUILDING === $phase ) {
					$baseline_outstanding = $this->work_count( $run_id, WorkRepository::SCOPE_BASELINE );
					if ( null === $baseline_outstanding ) {
						$this->block_locked( $state, 'baseline_count_failed', Install::classify_topology() );
						break;
					}
					if ( $baseline_outstanding > 0 ) {
						$wake = 'baseline';
						break;
					}

					$state['phase'] = self::PHASE_CATCHING_UP;
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						$this->block_locked( $state, 'catchup_transition_failed', Install::classify_topology() );
						break;
					}
					$phase = self::PHASE_CATCHING_UP;
				}

				if ( self::PHASE_CATCHING_UP !== $phase ) {
					break;
				}

				$baseline_outstanding = $this->work_count( $run_id, WorkRepository::SCOPE_BASELINE );
				$repair_outstanding   = $this->work_count( $run_id, WorkRepository::SCOPE_REPAIR );
				$baseline_leases      = $this->active_lease_count( $run_id, WorkRepository::SCOPE_BASELINE );
				$repair_leases        = $this->active_lease_count( $run_id, WorkRepository::SCOPE_REPAIR );
				$cutover_counts       = array( $baseline_outstanding, $repair_outstanding, $baseline_leases, $repair_leases );
				if ( in_array( null, $cutover_counts, true ) ) {
					$this->block_locked( $state, 'work_count_failed', Install::classify_topology() );
					break;
				}
				if ( $baseline_outstanding > 0 || $baseline_leases > 0 ) {
					$wake = 'baseline';
					break;
				}
				if ( $repair_outstanding > 0 || $repair_leases > 0 ) {
					$wake = 'repair';
					break;
				}
				if ( ! self::counts_allow_cutover( $cutover_counts ) ) {
					$this->block_locked( $state, 'work_not_zero', Install::classify_topology() );
					break;
				}

				$coverage = self::coverage_snapshot();
				if ( ! $coverage['valid'] ) {
					$this->block_locked( $state, 'coverage_query_failed', Install::classify_topology() );
					break;
				}

				$repair_ids = self::repair_ids_from_coverage( $coverage );
				if ( ! empty( $repair_ids ) ) {
					foreach ( $repair_ids as $product_id ) {
						if ( ! $this->work->mark_repair_dirty( $run_id, $product_id ) ) {
							$this->block_locked( $state, 'coverage_repair_manifest_failed', Install::classify_topology() );
							break 2;
						}
					}

					$state['coverage'] = self::coverage_receipt( $coverage );
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						$this->block_locked( $state, 'coverage_receipt_failed', Install::classify_topology() );
						break;
					}
					$wake = 'repair';
					break;
				}

				if ( EditorialDiscountPublication::is_registered() ) {
					$editorial_seq = (int) ( $state['editorial_wanted_seq'] ?? 0 );
					$editorial_fingerprint = (string) ( $state['editorial_fingerprint'] ?? '' );
					if ( ! EditorialDiscountPublication::rebuild_snapshot_matches( $editorial_seq, $editorial_fingerprint ) ) {
						$current_editorial = EditorialDiscountPublication::snapshot_for_rebuild();
						if ( ! is_array( $current_editorial ) ) {
							$state['error'] = 'editorial_snapshot_unstable';
							$this->write_state( self::touch( $state ) );
							$reconcile_editorial = true;
							break;
						}

						// Los cuatro conteos exactos ya son cero bajo el mismo fence:
						// la revisión nueva fue drenada. Persistirla y dar una vuelta
						// adicional impide que un mismatch observado autorice RENAME.
						$state['editorial_wanted_seq'] = (int) $current_editorial['wanted_seq'];
						$state['editorial_fingerprint'] = (string) $current_editorial['fingerprint'];
						$state['ack_editorial_receipt'] = false;
						$state['error'] = '';
						if ( ! $this->write_state( self::touch( $state ) ) ) {
							$this->block_locked( $state, 'editorial_snapshot_refresh_failed', Install::classify_topology() );
							break;
						}
						$wake = WorkRepository::SCOPE_REPAIR;
						break;
					}
				}

				$state['coverage']          = self::coverage_receipt( $coverage );
				$state['generation_target'] = '' !== (string) ( $state['generation_target'] ?? '' )
					? (string) $state['generation_target']
					: 'akb-search-' . $run_id;
				$state['phase']              = self::PHASE_SWAP_INTENT;

				if ( ! $this->write_state( self::touch( $state ) ) ) {
					$this->block_locked( $state, 'swap_intent_persist_failed', Install::classify_topology() );
					break;
				}

				$publish = $this->rename_locked( $state );
			} while ( false );
		} finally {
			$this->fence->release();
		}

		if ( self::should_enqueue_wakeup( $async, $allow_wakeup, $wake ) ) {
			self::enqueue_wakeup( $run_id, $wake );
		}
		if ( $reconcile_editorial ) {
			EditorialDiscountPublication::reconcile_now();
		}
		if ( $publish ) {
			$this->publish_run( $run_id );
		}
	}

	/**
	 * Ejecuta o recupera el unico RENAME manteniendo _old.
	 *
	 * @param array<string, mixed> $state Estado SWAP_INTENT.
	 * @return bool True si ya puede comenzar publicacion.
	 */
	private function rename_locked( array $state ): bool {
		try {
			$this->fence->assert_owned();
		} catch ( Throwable $throwable ) {
			return false;
		}

		$inspection = Install::inspect_table_topology();
		if ( ! (bool) $inspection['valid'] ) {
			return false;
		}
		$topology = (string) $inspection['classification'];
		if ( Install::TOPOLOGY_READY_SWAP === $topology ) {
			if ( ! Install::live_writer_schema_ready() || ! Install::shadow_schema_ready() ) {
				$this->persist_recoverable_state( $state, 'rename_schema_recheck', $topology );
				return false;
			}
			// Última lectura: nada que consuma fuente editorial puede quedar entre
			// este final-check integrado y el RENAME físico.
			if ( EditorialDiscountPublication::is_registered()
				&& ! EditorialDiscountPublication::rebuild_snapshot_matches(
					(int) ( $state['editorial_wanted_seq'] ?? 0 ),
					(string) ( $state['editorial_fingerprint'] ?? '' )
				) ) {
				$state['phase'] = self::PHASE_CATCHING_UP;
				$this->persist_recoverable_state( $state, 'editorial_snapshot_changed_before_rename', $topology );
				return false;
			}
			$rename = Install::rename_keep_old_result(
				$inspection['topology'],
				array( $this->fence, 'assert_owned' )
			);
			if ( Install::RENAME_RESULT_RETRY === $rename ) {
				$this->persist_recoverable_state( $state, 'rename_retry', $topology );
				return false;
			}
			if ( Install::RENAME_RESULT_SWAPPED !== $rename ) {
				$this->block_locked( $state, 'rename_failed', $topology );
				return false;
			}
		} elseif ( Install::TOPOLOGY_SWAPPED !== $topology ) {
			$this->block_locked( $state, 'rename_topology_ambiguous', $topology );
			return false;
		} elseif ( ! Install::live_writer_schema_ready() || ! Install::old_schema_ready() ) {
			$this->persist_recoverable_state( $state, 'rename_schema_recheck', $topology );
			return false;
		}

		$state['phase'] = self::PHASE_SWAPPED;
		if ( ! $this->write_state( self::touch( $state ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * ACK post-publicacion idempotentes y cleanup final, siempre bajo fence.
	 *
	 * @param string $run_id Run vigente.
	 * @return void
	 */
	private function publish_run( string $run_id ): void {
		if ( ! $this->fence->acquire( 0 ) ) {
			return;
		}

		try {
			$state = self::read_state();
			if ( null === $state || $run_id !== (string) ( $state['run_id'] ?? '' ) ) {
				return;
			}

			$inspection = Install::inspect_table_topology();
			if ( ! (bool) $inspection['valid'] ) {
				return;
			}
			$topology = (string) $inspection['classification'];
			if ( self::PHASE_SWAP_INTENT === (string) $state['phase'] ) {
				$decision = self::recovery_decision( self::PHASE_SWAP_INTENT, $topology );
				if ( 'revalidate' === $decision ) {
					// Al reanudar ya se liberó F: un writer pudo crear repair tras
					// el intent. Volver a drenar/revalidar antes de otro RENAME.
					$state['phase'] = self::PHASE_CATCHING_UP;
					$this->write_state( self::touch( $state ) );
					return;
				}
				if ( 'mark_swapped' !== $decision ) {
					$this->block_locked( $state, 'swap_intent_recovery_mismatch', $topology );
					return;
				}
				if ( ! $this->rename_locked( $state ) ) {
					return;
				}
				$state = self::read_state();
			}

			if ( Install::TOPOLOGY_CLEAN_PRE === $topology && self::PHASE_CLEANUP === (string) ( $state['phase'] ?? '' ) ) {
				$this->finish_done_locked( $state );
				return;
			}

			$inspection = Install::inspect_table_topology();
			if ( ! (bool) $inspection['valid'] ) {
				return;
			}
			$topology = (string) $inspection['classification'];
			if ( Install::TOPOLOGY_SWAPPED !== $topology
				|| ! in_array( (string) ( $state['phase'] ?? '' ), array( self::PHASE_SWAPPED, self::PHASE_PUBLISHING, self::PHASE_CLEANUP ), true ) ) {
				$this->block_locked( $state, 'publish_topology_mismatch', $topology );
				return;
			}
			if ( ! Install::live_writer_schema_ready() || ! Install::old_schema_ready() ) {
				$this->persist_recoverable_state( $state, 'publish_schema_recheck', $topology );
				return;
			}

			if ( self::PHASE_CLEANUP === (string) $state['phase'] && ! self::publication_acked( $state, true ) ) {
				// Un deploy rolling o restore puede traer CLEANUP con ACKs antiguos
				// que nunca sellaron fingerprint. Reabrir PUBLISHING conserva _old y
				// genera evidencia nueva antes de intentar el DROP.
				$state['phase']                        = self::PHASE_PUBLISHING;
				$state['ack_semantic_fingerprint']     = false;
				$state['ack_history']                  = false;
				$this->persist_recoverable_state( $state, 'cleanup_publication_revalidation_required', Install::TOPOLOGY_SWAPPED );
				return;
			}

			if ( self::PHASE_CLEANUP !== (string) $state['phase'] ) {
				$state['phase'] = self::PHASE_PUBLISHING;
				if ( ! $this->write_state( self::touch( $state ) ) ) {
					return;
				}

				// Buckets se persisten primero; el target final invalida una sola vez.
				if ( ! (bool) ( $state['ack_price_buckets'] ?? false ) ) {
					if ( ! PriceBuckets::maybe_refresh_auto( false ) ) {
						return;
					}
					$state['ack_price_buckets'] = true;
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						return;
					}
				}

				// La foto se toma después del write de buckets y antes de su target
				// final. Si otra opción semántica cambió durante un retry, un target
				// nuevo fuerza generación+purge posteriores a esa nueva foto.
				$semantic_fingerprint = SemanticPublication::current_fingerprint();
				if ( null === $semantic_fingerprint ) {
					return;
				}
				$generation_target = self::generation_target_for_fingerprint( $run_id, $semantic_fingerprint );
				if ( '' === $generation_target ) {
					return;
				}
				if ( $semantic_fingerprint !== (string) ( $state['semantic_fingerprint'] ?? '' )
					|| $generation_target !== (string) ( $state['generation_target'] ?? '' ) ) {
					$state['semantic_fingerprint']     = $semantic_fingerprint;
					$state['generation_target']        = $generation_target;
					$state['ack_generation']           = false;
					$state['ack_rest_purge']           = false;
					$state['ack_semantic_fingerprint'] = false;
					$state['ack_history']              = false;
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						return;
					}
				}

				if ( (bool) ( $state['ack_generation'] ?? false )
					&& $generation_target !== (string) get_option( Install::OPTION_INDEX_GEN, '' ) ) {
					$state['ack_generation'] = false;
					$state['ack_rest_purge'] = false;
					$state['ack_semantic_fingerprint'] = false;
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						return;
					}
				}
				if ( (bool) ( $state['ack_rest_purge'] ?? false )
					&& ! RestCache::has_purge_receipt( $generation_target ) ) {
					$state['ack_rest_purge'] = false;
					$state['ack_semantic_fingerprint'] = false;
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						return;
					}
				}

				if ( ! (bool) ( $state['ack_generation'] ?? false ) ) {
					if ( '' === $generation_target || ! Install::bump_index_generation( $generation_target ) ) {
						return;
					}
					$state['ack_generation'] = true;
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						return;
					}
				}

				if ( ! (bool) ( $state['ack_rest_purge'] ?? false ) ) {
					if ( ! RestCache::purge( $generation_target )
						|| ! RestCache::has_purge_receipt( $generation_target ) ) {
						return;
					}
					$state['ack_rest_purge'] = true;
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						return;
					}
				}

				if ( ! (bool) ( $state['ack_semantic_fingerprint'] ?? false ) ) {
					if ( ! SemanticPublication::seal_external_fingerprint( $semantic_fingerprint, $generation_target ) ) {
						return;
					}
					$state['ack_semantic_fingerprint'] = true;
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						return;
					}
				}

				if ( self::editorial_receipt_required( $state )
					&& ! (bool) ( $state['ack_editorial_receipt'] ?? false ) ) {
					if ( ! EditorialDiscountPublication::record_swap_receipt(
						(string) $state['run_id'],
						(int) $state['editorial_wanted_seq'],
						(string) $state['editorial_fingerprint'],
						array(
							'db_version'                  => Install::DB_VERSION,
							'schema_seal'                 => (string) ( $state['schema_seal'] ?? '' ),
							'coverage'                    => (array) ( $state['coverage'] ?? array() ),
								'generation_target'           => $generation_target,
								'semantic_fingerprint'        => $semantic_fingerprint,
								'generation_acked'            => (bool) ( $state['ack_generation'] ?? false ),
							'rest_purge_acked'            => (bool) ( $state['ack_rest_purge'] ?? false ),
							'semantic_fingerprint_acked'  => (bool) ( $state['ack_semantic_fingerprint'] ?? false ),
							'swapped_at'                  => (int) ( $state['updated_at'] ?? time() ),
						)
					) ) {
						return;
					}
					$state['ack_editorial_receipt'] = true;
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						return;
					}
				}

				if ( ! (bool) ( $state['ack_history'] ?? false ) ) {
					if ( ! self::persist_publish_receipt( $state ) ) {
						return;
					}
					$state['ack_history'] = true;
					if ( ! $this->write_state( self::touch( $state ) ) ) {
						return;
					}
				}

				if ( ! (bool) ( $state['ack_semantic_fingerprint'] ?? false )
					|| ! self::publication_acked( $state )
					|| $generation_target !== (string) get_option( Install::OPTION_INDEX_GEN, '' ) ) {
					return;
				}

				$debt = $this->final_work_debt_status( $run_id );
				if ( 'clear' !== $debt ) {
					$this->persist_recoverable_state(
						$state,
						'error' === $debt ? 'cleanup_work_count_failed' : 'cleanup_work_debt_unresolved',
						Install::TOPOLOGY_SWAPPED
					);
					return;
				}

				$state['phase'] = self::PHASE_CLEANUP;
				if ( ! $this->write_state( self::touch( $state ) ) ) {
					return;
				}
			}

			try {
				$this->fence->assert_owned();
			} catch ( Throwable $throwable ) {
				return;
			}

			// Revalida también al reanudar CLEANUP: mientras _old exista, una
			// deuda inesperada debe conservar la vía de rollback y su owner.
			$debt = $this->final_work_debt_status( $run_id );
			if ( 'clear' !== $debt ) {
				$this->persist_recoverable_state(
					$state,
					'error' === $debt ? 'cleanup_work_count_failed' : 'cleanup_work_debt_unresolved',
					Install::TOPOLOGY_SWAPPED
				);
				return;
			}

			if ( ! Install::cleanup_old( array( $this->fence, 'assert_owned' ) ) ) {
				$inspection = Install::inspect_table_topology();
				if ( ! (bool) $inspection['valid'] ) {
					return;
				}
				$topology_snapshot = $inspection['topology'];
				$topology          = (string) $inspection['classification'];
				$decision          = self::ddl_failure_decision( self::PHASE_CLEANUP, $topology, $topology_snapshot );
				if ( 'finish' === $decision ) {
					// DROP terminó; sólo la primera verificación fue transitoria.
				} elseif ( 'recover_old' === $decision ) {
					$partial_recovery = Install::recover_owned_partial_old_result(
						$topology_snapshot,
						array( $this->fence, 'assert_owned' )
					);
					if ( Install::PARTIAL_RECOVERY_RETRY === $partial_recovery ) {
						$this->persist_recoverable_state( $state, 'partial_old_cleanup_retry', $topology );
						return;
					}
					if ( Install::PARTIAL_RECOVERY_RECOVERED !== $partial_recovery ) {
						$this->block_locked( $state, 'cleanup_old_failed', $topology );
						return;
					}
				} elseif ( 'retry' === $decision ) {
					$this->persist_recoverable_state( $state, 'cleanup_old_retry', $topology );
					return;
				} else {
					$this->block_locked( $state, 'cleanup_old_failed', $topology );
					return;
				}
			}

			$state['error'] = '';
			unset( $state['recovery_pending'] );
			$this->finish_done_locked( $state );
		} finally {
			$this->fence->release();
		}
	}

	/**
	 * Reanuda el trabajo apropiado para una fase vigente.
	 *
	 * @param string $run_id Run vigente.
	 * @param bool   $async  Usar wake-ups AS.
	 * @return void
	 */
	private function resume_run( string $run_id, bool $async ): void {
		if ( '' === $run_id ) {
			return;
		}

		$state = self::read_state();
		if ( null === $state || $run_id !== (string) ( $state['run_id'] ?? '' ) ) {
			return;
		}

		$phase = (string) ( $state['phase'] ?? '' );
		if ( self::PHASE_BUILDING === $phase ) {
			if ( $async ) {
				self::enqueue_wakeup( $run_id, 'baseline' );
			} else {
				$this->pump_scope( $run_id, 'baseline', false );
			}
			return;
		}

		if ( self::PHASE_CATCHING_UP === $phase ) {
			$baseline = $this->work_count( $run_id, WorkRepository::SCOPE_BASELINE );
			$scope    = null === $baseline || $baseline > 0 ? WorkRepository::SCOPE_BASELINE : WorkRepository::SCOPE_REPAIR;
			$count    = $this->work_count( $run_id, $scope );
			if ( null !== $count && $count > 0 ) {
				if ( $async ) {
					self::enqueue_wakeup( $run_id, $scope );
				} else {
					$this->pump_scope( $run_id, $scope, false );
				}
			} else {
				$this->advance_run( $run_id, $async );
			}
			return;
		}

		if ( in_array( $phase, array( self::PHASE_SWAP_INTENT, self::PHASE_SWAPPED, self::PHASE_PUBLISHING, self::PHASE_CLEANUP ), true ) ) {
			$this->publish_run( $run_id );
			return;
		}

		if ( self::PHASE_DONE === $phase ) {
			if ( $this->fence->acquire( 0 ) ) {
				try {
					$this->finish_done_locked( $state );
				} finally {
					$this->fence->release();
				}
			}
		}
	}

	/**
	 * Marca DONE, elimina work del run y retira el estado solo al final.
	 *
	 * @param array<string, mixed> $state Estado CLEANUP/DONE.
	 * @return bool
	 */
	private function finish_done_locked( array $state ): bool {
		$inspection = Install::inspect_table_topology();
		if ( ! (bool) $inspection['valid'] ) {
			return false;
		}
		$topology = (string) $inspection['classification'];
		if ( Install::TOPOLOGY_CLEAN_PRE !== $topology || ! self::publication_acked( $state, true ) ) {
			$this->block_locked( $state, 'done_precondition_failed', $topology );
			return false;
		}

		$run_id = (string) ( $state['run_id'] ?? '' );
		$debt   = $this->final_work_debt_status( $run_id );
		if ( 'clear' !== $debt ) {
			$this->persist_recoverable_state(
				$state,
				'error' === $debt ? 'done_work_count_failed' : 'done_work_debt_unresolved',
				$topology
			);
			return false;
		}

		$state['phase'] = self::PHASE_DONE;
		if ( ! $this->write_state( self::touch( $state ) ) ) {
			return false;
		}

		if ( false === $this->work->purge_run( $run_id ) ) {
			$this->persist_recoverable_state( $state, 'done_work_purge_failed', $topology );
			return false;
		}

		// purge_run() sólo elimina ACK. Releer antes de retirar OPTION_STATE
		// evita dejar deuda sin owner si una implementación antigua o una
		// escritura defectuosa hubiera introducido trabajo durante el cierre.
		$debt = $this->final_work_debt_status( $run_id );
		if ( 'clear' !== $debt ) {
			$this->persist_recoverable_state(
				$state,
				'error' === $debt ? 'done_work_count_failed' : 'done_work_debt_unresolved',
				$topology
			);
			return false;
		}

		if ( ! self::persist_final_receipt( $state ) ) {
			return false;
		}
		delete_option( self::OPTION_STATE );
		wp_cache_delete( self::OPTION_STATE, 'options' );

		$raw = self::read_state_raw();
		return ! self::$state_read_failed && null === $raw;
	}

	/**
	 * Persiste un bloqueo observable sin mutar tablas ambiguas.
	 *
	 * @param array<string, mixed> $state    Estado vigente.
	 * @param string               $error    Codigo estable.
	 * @param string               $topology Clasificacion observada.
	 * @return void
	 */
	private function block_locked( array $state, string $error, string $topology ): void {
		$state['protocol'] = 2;
		$state['phase']    = self::PHASE_BLOCKED;
		$state['error']    = $error;
		$state['topology'] = $topology;
		$state             = self::touch( $state );
		$this->write_state( $state );

		$receipt = array(
			'run_id'      => (string) ( $state['run_id'] ?? '' ),
			'started_at'  => (int) ( $state['started_at'] ?? 0 ),
			'finished_at' => time(),
			'docs'        => 0,
			'result'      => 'blocked',
			'error'       => $error,
			'topology'    => $topology,
		);
		update_option( self::OPTION_LAST, $receipt, false );
		self::append_history( $receipt );
	}

	/**
	 * Crea un estado BLOCKED para residuos sin owner conocido.
	 *
	 * @param string $topology Topologia observada.
	 * @return void
	 */
	private function persist_orphan_topology_block( string $topology ): void {
		$state = array(
			'protocol'           => 2,
			'run_id'             => self::new_run_id(),
			'phase'              => self::PHASE_BLOCKED,
			'started_at'         => time(),
			'updated_at'         => time(),
			'heartbeat_at'       => time(),
			'expected'           => 0,
			'generation_target'  => '',
			'ack_generation'     => false,
			'ack_rest_purge'     => false,
			'ack_price_buckets'  => false,
			'ack_history'        => false,
			'error'              => 'orphan_topology_without_state',
			'topology'           => $topology,
		);

		if ( self::claim_state( $state ) ) {
			$this->block_locked( $state, 'orphan_topology_without_state', $topology );
		}
	}

	/**
	 * Foto exacta de universo, docs sombra y huérfanos.
	 *
	 * @return array{valid: bool, eligible: array<int, int>, docs: array<int, int>, missing: array<int, int>, extra: array<int, int>, orphans: array<int, int>}
	 */
	private static function coverage_snapshot(): array {
		global $wpdb;

		$eligible = self::query_eligible_product_ids();
		$docs     = self::safe_get_col( 'SELECT post_id FROM ' . Install::docs_table() . Install::SHADOW_SUFFIX . ' ORDER BY post_id ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- El SQL se compone exclusivamente de identificadores canónicos de tablas del plugin sin entrada externa.
		$tokens   = self::safe_get_col(
			'SELECT DISTINCT child.post_id FROM ' . Install::tokens_table() . Install::SHADOW_SUFFIX . ' child LEFT JOIN '
			. Install::docs_table() . Install::SHADOW_SUFFIX . ' parent ON parent.post_id = child.post_id WHERE parent.post_id IS NULL'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- El SQL se compone exclusivamente de identificadores canónicos de tablas del plugin sin entrada externa.
		$facets   = self::safe_get_col(
			'SELECT DISTINCT child.post_id FROM ' . Install::facets_table() . Install::SHADOW_SUFFIX . ' child LEFT JOIN '
			. Install::docs_table() . Install::SHADOW_SUFFIX . ' parent ON parent.post_id = child.post_id WHERE parent.post_id IS NULL'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- El SQL se compone exclusivamente de identificadores canónicos de tablas del plugin sin entrada externa.

		if ( null === $eligible || ! is_array( $docs ) || ! is_array( $tokens ) || ! is_array( $facets ) ) {
			return array(
				'valid'    => false,
				'eligible' => array(),
				'docs'     => array(),
				'missing'  => array(),
				'extra'    => array(),
				'orphans'  => array(),
			);
		}

		return self::coverage_from_sets( $eligible, $docs, array_merge( $tokens, $facets ) );
	}

	/**
	 * Diferencia exacta reusable por pruebas adversariales.
	 *
	 * @param array<int, mixed> $eligible IDs fuente.
	 * @param array<int, mixed> $docs     IDs sombra.
	 * @param array<int, mixed> $orphans  IDs token/faceta sin doc.
	 * @return array{valid: bool, eligible: array<int, int>, docs: array<int, int>, missing: array<int, int>, extra: array<int, int>, orphans: array<int, int>}
	 */
	private static function coverage_from_sets( array $eligible, array $docs, array $orphans ): array {
		$eligible = self::normalize_ids( $eligible );
		$docs     = self::normalize_ids( $docs );
		$orphans  = self::normalize_ids( $orphans );

		return array(
			'valid'    => true,
			'eligible' => $eligible,
			'docs'     => $docs,
			'missing'  => array_values( array_diff( $eligible, $docs ) ),
			'extra'    => array_values( array_diff( $docs, $eligible ) ),
			'orphans'  => $orphans,
		);
	}

	/**
	 * IDs que deben materializar repair antes de reconsultar cobertura.
	 *
	 * @param array<string, mixed> $coverage Foto de cobertura.
	 * @return array<int, int>
	 */
	private static function repair_ids_from_coverage( array $coverage ): array {
		return self::normalize_ids(
			array_merge(
				(array) ( $coverage['missing'] ?? array() ),
				(array) ( $coverage['extra'] ?? array() ),
				(array) ( $coverage['orphans'] ?? array() )
			)
		);
	}

	/**
	 * Receipt compacto de la ultima comprobacion exacta.
	 *
	 * @param array<string, mixed> $coverage Foto de cobertura.
	 * @return array<string, int>
	 */
	private static function coverage_receipt( array $coverage ): array {
		return array(
			'eligible' => count( (array) ( $coverage['eligible'] ?? array() ) ),
			'docs'     => count( (array) ( $coverage['docs'] ?? array() ) ),
			'missing'  => count( (array) ( $coverage['missing'] ?? array() ) ),
			'extra'    => count( (array) ( $coverage['extra'] ?? array() ) ),
			'orphans'  => count( (array) ( $coverage['orphans'] ?? array() ) ),
		);
	}

	/**
	 * Universo fuente estable y ordenado.
	 *
	 * @return array<int, int>
	 */
	private static function query_eligible_product_ids(): ?array {
		global $wpdb;

		list( $where, $params ) = self::eligible_products_where();

		$ids = self::safe_get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- predicado compartido: los placeholders viajan DENTRO de $where y el sniff no los ve.
				"SELECT ID FROM {$wpdb->posts} WHERE {$where} ORDER BY ID ASC",
				...$params
			)
		);

		return is_array( $ids ) ? self::normalize_ids( $ids ) : null;
	}

	/**
	 * get_col fail-closed: wpdb devuelve [] tanto para cero filas como para
	 * ciertos errores, por lo que last_error forma parte del contrato.
	 *
	 * @param string $sql Consulta interna ya preparada.
	 * @return array<int, mixed>|null
	 */
	private static function safe_get_col( string $sql ): ?array {
		global $wpdb;

		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- el contrato de este helper es recibir la consulta YA preparada por quien llama; lo único que añade es distinguir «vacío» de «la lectura falló».
		$rows             = $wpdb->get_col( $sql );

		return is_array( $rows ) && '' === (string) $wpdb->last_error ? $rows : null;
	}

	/**
	 * Normaliza IDs positivos unicos y ordenados.
	 *
	 * @param array<int, mixed> $ids IDs.
	 * @return array<int, int>
	 */
	private static function normalize_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Decide recuperacion sin ejecutar DDL.
	 *
	 * @param string $phase    Fase persistida.
	 * @param string $topology Topologia clasificada.
	 * @return string resume|prepare|revalidate|mark_swapped|finish_done|block|idle.
	 */
	private static function recovery_decision( string $phase, string $topology ): string {
		if ( self::PHASE_BLOCKED === $phase || self::PHASE_ABORTED === $phase ) {
			return 'idle';
		}

		if ( Install::TOPOLOGY_AMBIGUOUS === $topology ) {
			return 'block';
		}

		if ( Install::TOPOLOGY_CLEAN_PRE === $topology ) {
			if ( self::PHASE_PREPARING === $phase ) {
				return 'prepare';
			}
			if ( in_array( $phase, array( self::PHASE_CLEANUP, self::PHASE_DONE ), true ) ) {
				return 'finish_done';
			}
			return 'block';
		}

		if ( Install::TOPOLOGY_READY_SWAP === $topology ) {
			if ( in_array( $phase, array( self::PHASE_PREPARING, self::PHASE_BUILDING, self::PHASE_CATCHING_UP ), true ) ) {
				return self::PHASE_PREPARING === $phase ? 'prepare' : 'resume';
			}
			return self::PHASE_SWAP_INTENT === $phase ? 'revalidate' : 'block';
		}

		if ( Install::TOPOLOGY_SWAPPED === $topology ) {
			if ( self::PHASE_SWAP_INTENT === $phase ) {
				return 'mark_swapped';
			}
			return in_array( $phase, array( self::PHASE_SWAPPED, self::PHASE_PUBLISHING, self::PHASE_CLEANUP ), true )
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
	private static function swap_intent_writer_target( string $topology, string $run_id ): ?string {
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
	private static function generation_target_for_fingerprint( string $run_id, string $fingerprint ): string {
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
	private static function final_work_debt_decision( ?int $baseline, ?int $repair ): string {
		if ( null === $baseline || null === $repair ) {
			return 'error';
		}

		return 0 === $baseline && 0 === $repair ? 'clear' : 'wait';
	}

	/**
	 * Decide qué hacer cuando el propio request observó un DDL parcial/fallido.
	 *
	 * @param string                              $phase    PREPARING|CLEANUP.
	 * @param string                              $topology Clasificación actual.
	 * @param array<string, array<string, bool>> $snapshot Foto de familias.
	 * @return string recover_shadow|recover_old|retry|finish|block.
	 */
	private static function ddl_failure_decision( string $phase, string $topology, array $snapshot ): string {
		if ( self::PHASE_PREPARING === $phase ) {
			if ( Install::TOPOLOGY_AMBIGUOUS === $topology && Install::is_owned_partial_family( $snapshot, 'new' ) ) {
				return 'recover_shadow';
			}
			return Install::TOPOLOGY_CLEAN_PRE === $topology ? 'retry' : 'block';
		}
		if ( self::PHASE_CLEANUP === $phase ) {
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
	 * Valida que una accion pertenezca al run y fase correctos.
	 *
	 * @param array<string, mixed> $state  Estado vigente.
	 * @param string               $run_id Run de la accion.
	 * @param string               $scope  Scope de la accion.
	 * @return bool
	 */
	private static function action_matches_state( array $state, string $run_id, string $scope ): bool {
		if ( '' === $run_id || $run_id !== (string) ( $state['run_id'] ?? '' ) ) {
			return false;
		}

		$phase = (string) ( $state['phase'] ?? '' );
		return 'baseline' === $scope
			? in_array( $phase, array( self::PHASE_BUILDING, self::PHASE_CATCHING_UP ), true )
			: 'repair' === $scope && self::PHASE_CATCHING_UP === $phase;
	}

	/**
	 * Un pump sin claims no encadena acciones contra deuda con backoff futuro.
	 *
	 * @param bool   $async         Camino AS.
	 * @param bool   $allow_wakeup Hubo progreso o es un arranque explicito.
	 * @param string $scope        Scope a despertar.
	 * @return bool
	 */
	private static function should_enqueue_wakeup( bool $async, bool $allow_wakeup, string $scope ): bool {
		return $async && $allow_wakeup && in_array( $scope, array( WorkRepository::SCOPE_BASELINE, WorkRepository::SCOPE_REPAIR ), true );
	}

	/** @return void */
	private static function mark_watchdog_guarded(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::WATCHDOG_GUARD_TRANSIENT, 1, RecurringMemo::ttl( self::WATCHDOG_INTERVAL ) );
		}
	}

	/**
	 * Cuenta deuda sin convertir un error SQL en cero publicable.
	 *
	 * @param string $run_id Run.
	 * @param string $scope  Scope.
	 * @return int|null
	 */
	private function work_count( string $run_id, string $scope ): ?int {
		$count = $this->work->outstanding_count( $run_id, $scope );
		return self::normalise_count_result( $count );
	}

	/**
	 * Clasifica la deuda completa del run sin convertir fallos SQL en cero.
	 *
	 * @param string $run_id Run dueño del manifest.
	 * @return string clear|wait|error.
	 */
	private function final_work_debt_status( string $run_id ): string {
		return self::final_work_debt_decision(
			$this->work_count( $run_id, WorkRepository::SCOPE_BASELINE ),
			$this->work_count( $run_id, WorkRepository::SCOPE_REPAIR )
		);
	}

	/**
	 * Cuenta leases vigentes sin convertir un error SQL en cero.
	 *
	 * @param string $run_id Run.
	 * @param string $scope  Scope.
	 * @return int|null
	 */
	private function active_lease_count( string $run_id, string $scope ): ?int {
		$count = $this->work->active_lease_count( $run_id, $scope );
		return self::normalise_count_result( $count );
	}

	/**
	 * Verifica el dueño fisico sin dejar escapar una reconexion como DDL seguro.
	 *
	 * @return bool
	 */
	private function assert_fence_owned(): bool {
		try {
			$this->fence->assert_owned();
			return true;
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * false SQL nunca se convierte al cero que habilita cutover.
	 *
	 * @param int|false $count Resultado del repositorio.
	 * @return int|null
	 */
	private static function normalise_count_result( $count ): ?int {
		return false === $count ? null : (int) $count;
	}

	/**
	 * Todos los conteos deben ser consultables y exactamente cero.
	 *
	 * @param array<int, int|null> $counts Conteos outstanding/lease.
	 * @return bool
	 */
	private static function counts_allow_cutover( array $counts ): bool {
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
	private static function work_status_totals( array $summary ): array {
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
	 * Encola wake-up y trata 0 como fallo observable, sin tocar work.
	 *
	 * @param string $run_id Run vigente.
	 * @param string $scope  baseline|repair.
	 * @param int    $delay_seconds Demora mínima; cero usa enqueue async.
	 * @return bool
	 */
	private static function enqueue_wakeup( string $run_id, string $scope, int $delay_seconds = 0 ): bool {
		if ( '' === $run_id || ! in_array( $scope, array( 'baseline', 'repair' ), true )
			|| 0 > $delay_seconds
			|| ( 0 === $delay_seconds && ! function_exists( 'as_enqueue_async_action' ) )
			|| ( 0 < $delay_seconds && ! function_exists( 'as_schedule_single_action' ) ) ) {
			return false;
		}
		if ( function_exists( 'did_action' )
			&& did_action( 'action_scheduler_init' ) <= 0
			&& did_action( 'init' ) <= 0 ) {
			return false;
		}

		$args = array( $run_id, $scope );
		if ( self::has_pending_wakeup( $args ) ) {
			return true;
		}

		if ( 0 < $delay_seconds ) {
			return 0 !== (int) as_schedule_single_action(
				time() + $delay_seconds + 2,
				self::HOOK_BATCH,
				$args,
				Indexer::AS_GROUP,
				false
			);
		}

		return 0 !== (int) as_enqueue_async_action( self::HOOK_BATCH, $args, Indexer::AS_GROUP, false );
	}

	/**
	 * Deduplica solo contra pending; la accion running ya tomo su snapshot.
	 *
	 * @param array<int, string> $args Args exactos del wake-up.
	 * @return bool
	 */
	private static function has_pending_wakeup( array $args ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK_BATCH,
				'args'     => $args,
				'group'    => Indexer::AS_GROUP,
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		);

		return ! empty( $pending );
	}

	/**
	 * Estado raw directo a DB; FOR UPDATE exige una transaccion del caller.
	 *
	 * @param bool $for_update Bloquear la fila option.
	 * @return string|null
	 */
	private static function read_state_raw( bool $for_update = false ): ?string {
		global $wpdb;

		self::$state_read_failed = false;
		self::$state_row_present = false;
		$wpdb->last_error        = '';
		/*
		 * Dos consultas literales completas en vez de concatenar el `FOR UPDATE`.
		 *
		 * Concatenarlo era seguro —es un literal, no entra nada de fuera—, pero
		 * dejaba el análisis estático marcando SQL sin preparar en un método que
		 * sí prepara. Un aviso permanente que hay que aprender a ignorar es un
		 * aviso que también tapa el día que sea de verdad.
		 */
		$raw = $for_update
			? $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
					self::OPTION_STATE
				)
			)
			: $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					self::OPTION_STATE
				)
			);
		if ( '' !== (string) $wpdb->last_error ) {
			self::$state_read_failed = true;
			return null;
		}
		if ( null === $raw ) {
			return null;
		}
		if ( ! is_string( $raw ) ) {
			self::$state_read_failed = true;
			return null;
		}

		self::$state_row_present = true;
		return $raw;
	}

	/**
	 * Lee estado JSON sin cache de options.
	 *
	 * @param bool $for_update Bloquear la fila option.
	 * @return array<string, mixed>|null
	 */
	private static function read_state( bool $for_update = false ): ?array {
		return self::decode_state( self::read_state_raw( $for_update ) );
	}

	/**
	 * @param string|null $raw JSON.
	 * @return array<string, mixed>|null
	 */
	private static function decode_state( ?string $raw ): ?array {
		if ( null === $raw ) {
			return null;
		}

		$state = json_decode( $raw, true );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Claim inicial bajo fence.
	 *
	 * @param array<string, mixed> $state Estado inicial.
	 * @return bool
	 */
	private static function claim_state( array $state ): bool {
		$raw = self::read_state_raw();
		if ( self::$state_read_failed || null !== $raw ) {
			return false;
		}

		global $wpdb;
		$encoded = wp_json_encode( $state );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return false;
		}

		// Insert-only real. Core add_option puede tomar una ruta UPSERT y pisar
		// el run que apareció entre read_state_raw() y este claim.
		$wpdb->last_error = '';
		$added            = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				self::OPTION_STATE,
				$encoded,
				'no'
			)
		);
		wp_cache_delete( self::OPTION_STATE, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$write_error = (string) ( $wpdb->last_error ?? '' );
		$stored      = self::read_state_raw();

		return '' === $write_error
			&& 1 === (int) $added
			&& ! self::$state_read_failed
			&& $encoded === $stored;
	}

	/**
	 * Escritura verificada. Todas las transiciones se llaman bajo fence.
	 *
	 * @param array<string, mixed> $state Estado completo.
	 * @return bool
	 */
	private function write_state( array $state ): bool {
		if ( ! $this->fence->is_owned() ) {
			return false;
		}

		$encoded = (string) wp_json_encode( $state );
		update_option( self::OPTION_STATE, $encoded, false );
		wp_cache_delete( self::OPTION_STATE, 'options' );

		return $encoded === self::read_state_raw();
	}

	/**
	 * Refresca clocks sin cambiar el contenido semantico.
	 *
	 * @param array<string, mixed> $state Estado.
	 * @return array<string, mixed>
	 */
	private static function touch( array $state ): array {
		$state['updated_at']   = time();
		$state['heartbeat_at'] = time();
		return $state;
	}

	/**
	 * @param array<string, mixed> $state Estado.
	 * @return bool
	 */
	private static function valid_state_shape( array $state ): bool {
		return 2 === (int) ( $state['protocol'] ?? 0 )
			&& '' !== (string) ( $state['run_id'] ?? '' )
			&& in_array(
				(string) ( $state['phase'] ?? '' ),
				array(
					self::PHASE_PREPARING,
					self::PHASE_BUILDING,
					self::PHASE_CATCHING_UP,
					self::PHASE_SWAP_INTENT,
					self::PHASE_SWAPPED,
					self::PHASE_PUBLISHING,
					self::PHASE_CLEANUP,
					self::PHASE_DONE,
					self::PHASE_BLOCKED,
					self::PHASE_ABORTED,
				),
				true
			);
	}

	/**
	 * @param string $phase Fase.
	 * @return bool
	 */
	private static function is_terminal_phase( string $phase ): bool {
		return in_array( $phase, array( self::PHASE_DONE, self::PHASE_BLOCKED, self::PHASE_ABORTED ), true );
	}

	/**
	 * @return string
	 */
	private static function new_run_id(): string {
		return function_exists( 'wp_generate_uuid4' ) ? (string) wp_generate_uuid4() : uniqid( 'akb-search-', true );
	}

	/**
	 * Verifica el receipt físico que vincula fingerprint y generación.
	 *
	 * @param string $fingerprint Fingerprint semántico esperado.
	 * @param string $generation  Generación que lo publicó.
	 * @return bool
	 */
	private static function semantic_fingerprint_receipt_matches( string $fingerprint, string $generation ): bool {
		$receipt = get_option( SemanticPublication::OPTION_FINGERPRINT, null );
		return is_array( $receipt )
			&& 1 === (int) ( $receipt['protocol'] ?? 0 )
			&& $fingerprint === (string) ( $receipt['fingerprint'] ?? '' )
			&& $generation === (string) ( $receipt['generation'] ?? '' )
			&& (int) ( $receipt['published_at'] ?? 0 ) > 0;
	}

	/** @param array<string, mixed> $state */
	private static function editorial_receipt_required( array $state ): bool {
		return (int) ( $state['editorial_wanted_seq'] ?? 0 ) > 0
			&& 1 === preg_match( '/^[a-f0-9]{64}$/D', (string) ( $state['editorial_fingerprint'] ?? '' ) );
	}

	/** @param array<string, mixed> $state */
	private static function editorial_receipt_acked( array $state, bool $allow_superseded ): bool {
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
	 * Antes de CLEANUP se exige que target y purge sigan siendo el efecto físico
	 * actual. Una vez en CLEANUP/DONE, un writer live legítimo puede publicar una
	 * generación posterior: ahí basta el receipt durable exacto de este run.
	 *
	 * @param array<string, mixed> $state            Estado post-swap.
	 * @param bool                 $allow_superseded Sólo para CLEANUP/DONE.
	 * @return bool
	 */
	private static function publication_acked( array $state, bool $allow_superseded = false ): bool {
		$generation_target = (string) ( $state['generation_target'] ?? '' );
		$semantic_fingerprint = (string) ( $state['semantic_fingerprint'] ?? '' );
		$flags              = '' !== $generation_target
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
	 * Busca el receipt nuevo exacto en last o en el ring (last puede avanzar).
	 *
	 * @param array<string, mixed> $state Estado del run.
	 * @return array<string, mixed>|null
	 */
	private static function publication_receipt_for_state( array $state ): ?array {
		$candidates = array_merge(
			array( get_option( self::OPTION_LAST, array() ) ),
			self::history()
		);
		foreach ( $candidates as $receipt ) {
			if ( is_array( $receipt )
				&& 1 === (int) ( $receipt['publication_protocol'] ?? 0 )
				&& (bool) ( $receipt['publication_acked'] ?? false )
				&& 'local_dispatch_only' === (string) ( $receipt['purge_ack_scope'] ?? '' )
				&& (bool) ( $receipt['semantic_fingerprint_acked'] ?? false )
				&& ( ! self::editorial_receipt_required( $state )
					|| ( (int) ( $receipt['editorial_wanted_seq'] ?? 0 ) === (int) $state['editorial_wanted_seq']
						&& (string) ( $receipt['editorial_fingerprint'] ?? '' ) === (string) $state['editorial_fingerprint']
						&& (bool) ( $receipt['editorial_receipt_acked'] ?? false )
						&& self::editorial_receipt_acked( $state, true ) ) )
				&& (string) ( $receipt['run_id'] ?? '' ) === (string) ( $state['run_id'] ?? '' )
				&& (string) ( $receipt['generation_target'] ?? '' ) === (string) ( $state['generation_target'] ?? '' )
				&& (string) ( $receipt['semantic_fingerprint'] ?? '' ) === (string) ( $state['semantic_fingerprint'] ?? '' )
				&& 'ok' === (string) ( $receipt['result'] ?? '' )
				&& in_array( (string) ( $receipt['phase'] ?? '' ), array( self::PHASE_PUBLISHING, self::PHASE_DONE ), true ) ) {
				return $receipt;
			}
		}
		return null;
	}

	/**
	 * Persiste receipt/historial de publicacion de forma idempotente por run_id.
	 *
	 * @param array<string, mixed> $state Estado.
	 * @return bool
	 */
	private static function persist_publish_receipt( array $state ): bool {
		global $wpdb;
		$generation_target = (string) ( $state['generation_target'] ?? '' );
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
		$docs = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Install::docs_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- el COUNT se arma concatenando el nombre de la tabla propia ($wpdb->prefix + literal fijo) y no lleva ningún valor que preparar; el sniff señala los dos trozos del identificador como si fueran datos.
		if ( '' !== (string) ( $wpdb->last_error ?? '' )
			|| ( ! is_int( $docs ) && ( ! is_string( $docs ) || 1 !== preg_match( '/^[0-9]+$/D', $docs ) ) ) ) {
			return false;
		}

		$receipt = array(
			'run_id'               => (string) $state['run_id'],
			'started_at'           => (int) ( $state['started_at'] ?? 0 ),
			'finished_at'          => time(),
			'docs'                 => (int) $docs,
			'expected'             => (int) ( $state['expected'] ?? 0 ),
			'result'               => 'ok',
			'phase'                => self::PHASE_PUBLISHING,
			'publication_protocol' => 1,
			'generation_target'    => $generation_target,
			'publication_acked'    => true,
			'purge_ack_scope'      => 'local_dispatch_only',
			'semantic_fingerprint' => $semantic_fingerprint,
			'semantic_fingerprint_acked' => true,
			'editorial_wanted_seq' => (int) ( $state['editorial_wanted_seq'] ?? 0 ),
			'editorial_fingerprint' => (string) ( $state['editorial_fingerprint'] ?? '' ),
			'editorial_receipt_acked' => self::editorial_receipt_acked( $state, true ),
		);

		update_option( self::OPTION_LAST, $receipt, false );
		$history_persisted = self::append_history( $receipt );

		$stored = get_option( self::OPTION_LAST, array() );
		return $history_persisted
			&& is_array( $stored )
			&& (string) ( $stored['run_id'] ?? '' ) === (string) $state['run_id']
			&& null !== self::publication_receipt_for_state( $state );
	}

	/**
	 * Actualiza el receipt final despues de cleanup.
	 *
	 * @param array<string, mixed> $state Estado DONE.
	 * @return bool
	 */
	private static function persist_final_receipt( array $state ): bool {
		$last = get_option( self::OPTION_LAST, array() );
		if ( ! is_array( $last ) || (string) ( $last['run_id'] ?? '' ) !== (string) ( $state['run_id'] ?? '' ) ) {
			return false;
		}

		$last['phase']       = self::PHASE_DONE;
		$last['finished_at'] = time();
		update_option( self::OPTION_LAST, $last, false );
		$history_persisted = self::append_history( $last );
		$stored            = get_option( self::OPTION_LAST, array() );

		return $history_persisted
			&& is_array( $stored )
			&& self::PHASE_DONE === (string) ( $stored['phase'] ?? '' )
			&& (string) ( $stored['run_id'] ?? '' ) === (string) ( $state['run_id'] ?? '' );
	}

	/**
	 * Ring idempotente por run_id.
	 *
	 * @param array<string, mixed> $entry Entrada.
	 * @return bool
	 */
	private static function append_history( array $entry ): bool {
		$run_id  = (string) ( $entry['run_id'] ?? '' );
		$history = array_values(
			array_filter(
				self::history(),
				static fn( $row ): bool => ! is_array( $row ) || '' === $run_id || $run_id !== (string) ( $row['run_id'] ?? '' )
			)
		);
		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::HISTORY_MAX );
		update_option( self::OPTION_HISTORY, $history, false );

		$stored = self::history();
		return isset( $stored[0] )
			&& is_array( $stored[0] )
			&& (string) ( $stored[0]['run_id'] ?? '' ) === $run_id;
	}
}
