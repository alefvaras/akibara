<?php
/**
 * Expiry — Vencimiento de puntos configurable según antigüedad en el programa.
 *
 * Contabilidad FIFO contra el ledger:
 *   vencible = max(0, ganado_antes_del_corte - total_debitado_historico)
 * Los débitos (canjes, reversas y vencimientos previos) consumen primero los puntos
 * más antiguos. La fórmula es autocorectiva y previene débitos redundantes.
 *
 * Incluye notificación por correo previo al vencimiento (configurable).
 * 0 meses = los puntos no vencen.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.2.0
 */
final class Expiry {

	public const HOOK             = 'akb_rewards_expiry_daily';
	public const HOOK_BATCH       = 'akb_rewards_expiry_batch';
	public const HOOK_WARN_ASYNC  = 'akb_rewards_expiry_warn_async';
	public const META_WARNED      = '_akb_rewards_expiry_warned';
	private const SCHEDULED_MEMO  = 'akb_rewards_expiry_scheduled';
	private const BATCH_SIZE      = 100;

	/**
	 * Meses de vida de los puntos. 0 = no vencen.
	 *
	 * @return int
	 */
	public static function months(): int {
		if ( 'yes' !== (string) get_option( 'akb_rewards_expiry_enabled', 'yes' ) ) {
			return 0; // Interruptor de un clic: apagado = los puntos no vencen.
		}
		return max( 0, (int) get_option( 'akb_rewards_expiry_months', 12 ) );
	}

	/**
	 * Días de anticipación del aviso. 0 = sin aviso.
	 *
	 * @return int
	 */
	public static function warn_days(): int {
		return max( 0, (int) get_option( 'akb_rewards_expiry_warn_days', 30 ) );
	}

	/**
	 * Cuelga los oyentes y la agenda.
	 *
	 * Los oyentes se registran permanentemente para evitar que acciones en cola
	 * de Action Scheduler queden huérfanas si la función se desactiva temporalmente.
	 * Con el carril inactivo, `run_daily()` retorna de inmediato y desprograma la acción.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_daily' ) );
		add_action( self::HOOK_BATCH, array( $this, 'run_batch' ), 10, 2 );
		add_action( self::HOOK_WARN_ASYNC, array( $this, 'fire_warning' ), 10, 3 );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Agenda diaria (patrón memoizado, solo admin/cron/CLI), en las DOS
	 * direcciones: con vencimiento la acción tiene que existir; sin él, no.
	 *
	 * El memo guarda el ESTADO que quedó en la cola ('yes' agendada, 'off'
	 * retirada), no sólo «ya miré»: así apagar el carril retira la acción en
	 * la siguiente petición de admin en vez de esperar a que caduque el memo,
	 * y encenderlo la vuelve a agendar igual de rápido.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$estado = self::months() > 0 ? 'yes' : 'off';
		if ( $estado === get_transient( self::SCHEDULED_MEMO ) ) {
			return;
		}

		if ( 'off' === $estado ) {
			as_unschedule_all_actions( self::HOOK );
			set_transient( self::SCHEDULED_MEMO, 'off', 12 * HOUR_IN_SECONDS );
			return;
		}

		if ( false === as_next_scheduled_action( self::HOOK ) ) {
			$action_id = as_schedule_recurring_action( time() + 2 * HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK );
			if ( ! is_int( $action_id ) || 0 >= $action_id ) {
				return;
			}
		}
		set_transient( self::SCHEDULED_MEMO, 'yes', 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Resta meses SIN el desborde de fin de mes de PHP (31-08 − 6 meses NO es
	 * 03-03): va al día 1 del mes destino y recién ahí fija el día, recortado
	 * al largo real del mes. Monótona respecto de $from — la ventana del
	 * aviso nunca queda detrás del corte de hoy.
	 *
	 * @param \DateTimeImmutable $from   Fecha base.
	 * @param int                $months Meses a restar.
	 * @return \DateTimeImmutable
	 */
	public static function months_before( \DateTimeImmutable $from, int $months ): \DateTimeImmutable {
		$first = $from->modify( 'first day of this month' )->modify( sprintf( '-%d months', $months ) );
		$day   = min( (int) $from->format( 'j' ), (int) $first->format( 't' ) );

		return $first
			->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day )
			->setTime( (int) $from->format( 'G' ), (int) $from->format( 'i' ), (int) $from->format( 's' ) );
	}

	/**
	 * Puntos del usuario que YA están vencidos según el corte de hoy.
	 * Función de LECTURA compartida por la corrida y por Mi cuenta.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $cutoff  'Y-m-d H:i:s' del corte.
	 * @return int
	 */
	public static function expirable_for( int $user_id, string $cutoff, bool $fresh = false ): int {
		global $wpdb;

		// Los movimientos migrados de SPAR conservan su fecha original en el
		// historial, pero para vencer envejecen desde la ÉPOCA del cutover:
		// solo cuentan como «viejos» cuando la época misma quedó antes del
		// corte. Sin época sellada quedan protegidos (fallar hacia no quitar).
		$epoch = (string) get_option( 'akb_rewards_migration_epoch', '' );
		if ( '' === $epoch ) {
			$epoch = '9999-12-31 23:59:59';
		}

		$earned_old = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
				'SELECT COALESCE(SUM(delta),0) FROM ' . Schema::ledger_table() . " WHERE user_id = %d AND delta > 0 AND ( created_at < %s AND action <> 'migration' OR action = 'migration' AND %s < %s )",
				$user_id,
				$cutoff,
				$epoch,
				$cutoff
			)
		);

		$debited = self::debited_for( $user_id );

		// $fresh: bajo candado el saldo se relee DIRECTO de la base — la caché
		// de usermeta puede traer la foto de antes de un canje concurrente.
		$balance = $fresh ? Ledger::raw_user_meta_int( $user_id, Ledger::META_BALANCE ) : Ledger::balance( $user_id );

		return max( 0, min( $balance, $earned_old - $debited ) );
	}

	/** Todos los débitos consumen antigüedad, incluido el propio vencimiento. */
	private static function debited_for( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla interno.
				'SELECT COALESCE(SUM(-delta),0) FROM ' . Schema::ledger_table() . ' WHERE user_id = %d AND delta < 0',
				$user_id
			)
		);
	}

	/**
	 * Primera fecha con puntos aún consumibles, sin ejecutar el cron.
	 *
	 * El acumulado SQL sólo localiza el abono que sobrevive a los débitos.
	 * La cantidad se relee con expirable_for() y el primer día se busca con
	 * months_before(): sumar meses al abono no invierte su recorte mensual.
	 * MariaDB usa una sola agregación privada; no carga todo el historial ni
	 * hace consultas por cada día. Migraciones sin época quedan protegidas.
	 *
	 * @param int $user_id Usuario.
	 * @return array{date:string,points:int,pending:bool}|null
	 */
	public static function next_expiry( int $user_id ): ?array {
		global $wpdb;

		$months = self::months();
		if ( $user_id <= 0 || $months <= 0 || Ledger::balance( $user_id ) <= 0 ) {
			return null;
		}

		$epoch   = (string) get_option( 'akb_rewards_migration_epoch', '' );
		$debited = self::debited_for( $user_id );
		$earned  = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla interno, valores preparados.
				"SELECT effective_at FROM ( SELECT effective_at, SUM(points) OVER (ORDER BY effective_at) AS cumulative_points FROM ( SELECT CASE WHEN action = 'migration' THEN %s ELSE created_at END AS effective_at, SUM(delta) AS points FROM " . Schema::ledger_table() . " WHERE user_id = %d AND delta > 0 AND (action <> 'migration' OR %s <> '') GROUP BY effective_at ) AS credits ) AS fifo WHERE cumulative_points > %d ORDER BY effective_at LIMIT 1",
				$epoch,
				$user_id,
				$epoch,
				$debited
			)
		);
		if ( ! is_string( $earned ) || '' === $earned ) {
			return null;
		}

		$credit = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $earned, wp_timezone() );
		if ( false === $credit ) {
			return null;
		}

		// Días de calendario, no segundos: conserva la fecha del sitio en DST.
		$first = $credit->setTime( 0, 0 );
		$last  = $first->modify( 'first day of this month' )->modify( sprintf( '+%d months', $months + 1 ) );
		$lo    = 0;
		$hi    = (int) $first->diff( $last )->days;
		while ( $lo < $hi ) {
			$mid    = intdiv( $lo + $hi, 2 );
			$day    = $first->modify( sprintf( '+%d days', $mid ) )->setTime( 23, 59, 59 );
			$cutoff = self::months_before( $day, $months )->format( 'Y-m-d H:i:s' );
			if ( $earned < $cutoff ) {
				$hi = $mid;
			} else {
				$lo = $mid + 1;
			}
		}

		$day    = $first->modify( sprintf( '+%d days', $lo ) );
		$cutoff = self::months_before( $day->setTime( 23, 59, 59 ), $months )->format( 'Y-m-d H:i:s' );
		$points = self::expirable_for( $user_id, $cutoff );
		if ( $points <= 0 ) {
			return null;
		}

		$now = new \DateTimeImmutable( 'now', wp_timezone() );
		return array(
			'date'    => $day->format( 'Y-m-d' ),
			'points'  => $points,
			'pending' => $earned < self::months_before( $now, $months )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Lo que vencerá dentro de los próximos $days días (para el aviso y para
	 * la línea de Mi cuenta): mismo cálculo, con el corte adelantado.
	 *
	 * @param int $user_id Usuario.
	 * @param int $days    Días hacia adelante.
	 * @return int
	 */
	public static function expiring_soon( int $user_id, int $days ): int {
		$months = self::months();
		if ( $months <= 0 ) {
			return 0;
		}

		$now    = new \DateTimeImmutable( 'now', wp_timezone() );
		$cutoff = self::months_before( $now->modify( sprintf( '+%d days', $days ) ), $months )->format( 'Y-m-d H:i:s' );
		$hoy    = self::months_before( $now, $months )->format( 'Y-m-d H:i:s' );

		return self::expirable_for( $user_id, max( $hoy, $cutoff ) );
	}

	/**
	 * Corrida diaria: vence lo que corresponde y avisa lo que viene.
	 *
	 * @return void
	 */
	public function run_daily(): void {
		$months = self::months();
		if ( $months <= 0 ) {
			return;
		}

		$now    = new \DateTimeImmutable( 'now', wp_timezone() );
		$cutoff = self::months_before( $now, $months )->format( 'Y-m-d H:i:s' );

		// Dos fases a propósito: primero se RECOLECTAN todos los ids (solo
		// lectura) y recién después se procesan. Paginar con `saldo > 0`
		// mientras el propio vencimiento deja saldos en 0 encoge el conjunto
		// filtrado bajo los pies del paginador y saltaría usuarios.
		$ids   = array();
		$paged = 1;
		do {
			$users = get_users(
				array(
					'meta_key'     => Ledger::META_BALANCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare' => '>',
					'meta_value'   => 0, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'       => 'ids',
					'number'       => 200,
					'paged'        => $paged,
					// Orden por ID indexado para evitar filesorts sobre usuarios paginados.
					'orderby'      => 'ID',
					'count_total'  => false,
				)
			);

			foreach ( (array) $users as $user_id ) {
				$ids[] = (int) $user_id;
			}

			++$paged;
		} while ( count( (array) $users ) === 200 );

		$ids = array_values( array_unique( $ids ) );

		// Procesamiento por lotes en Action Scheduler para prevenir tiempos de espera agotados.
		if ( count( $ids ) > self::BATCH_SIZE && $this->use_batches() ) {
			foreach ( array_chunk( $ids, self::BATCH_SIZE ) as $lote ) {
				as_schedule_single_action( time(), self::HOOK_BATCH, array( $lote, $cutoff ) );
			}
			return;
		}

		$this->run_batch( $ids, $cutoff );
	}

	/**
	 * Procesa un lote de usuarios contra un corte ya calculado.
	 *
	 * @param array<int,int> $ids    Usuarios del lote.
	 * @param string         $cutoff Corte 'Y-m-d H:i:s' calculado por la corrida.
	 * @return void
	 */
	public function run_batch( $ids, $cutoff ): void {
		if ( self::months() <= 0 ) {
			return; // El interruptor pudo apagarse entre encolar y ejecutar.
		}

		$now = new \DateTimeImmutable( 'now', wp_timezone() );

		foreach ( (array) $ids as $user_id ) {
			$this->process_user( (int) $user_id, (string) $cutoff, $now );
		}
	}

	/**
	 * ¿Está disponible el troceo con Action Scheduler?
	 *
	 * @return bool
	 */
	private function use_batches(): bool {
		/**
		 * Permite forzar el modo del cron de vencimiento (los tests lo usan;
		 * en producción el default detecta Action Scheduler, que WooCommerce
		 * siempre trae).
		 *
		 * @param bool $available Si hay troceo disponible.
		 */
		return (bool) apply_filters(
			'akb_rewards_expiry_use_batches',
			function_exists( 'as_schedule_single_action' ) && function_exists( 'as_enqueue_async_action' )
		);
	}

	/**
	 * Re-dispara el hook público del aviso (el correo escucha ahí). Es el
	 * receptor de la acción asíncrona — y el camino directo sin AS.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $soon      Puntos por vencer.
	 * @param int $warn_days Ventana del aviso.
	 * @return void
	 */
	public function fire_warning( $user_id, $soon = null, $warn_days = null ): void {
		$user_id = (int) $user_id;

		// El monto y la ventana se recalculan AQUÍ, al momento de avisar. La
		// acción viaja sólo con el usuario para que el dedupe de Action
		// Scheduler funcione (ver `process_user()`), y de paso el cliente lee
		// la cifra de ahora y no la de cuando se encoló. Los dos argumentos
		// viejos se siguen aceptando: puede quedar alguna acción encolada con
		// la forma anterior cuando esto se despliegue.
		$warn_days = null === $warn_days ? self::warn_days() : (int) $warn_days;
		if ( $warn_days <= 0 ) {
			return;
		}

		$soon = null === $soon ? self::expiring_soon( $user_id, $warn_days ) : (int) $soon;
		if ( $soon <= 0 ) {
			return; // Ya no vence nada: canjeó, o el carril cambió.
		}

		/** Documented in process_user(). */
		do_action( 'akb_rewards_expiry_warning', $user_id, $soon, $warn_days );
	}

	/**
	 * Vence y/o avisa a un usuario.
	 *
	 * @param int                $user_id Usuario.
	 * @param string             $cutoff  Corte de vencimiento.
	 * @param \DateTimeImmutable $now     Ahora (tz del sitio).
	 * @return void
	 */
	private function process_user( int $user_id, string $cutoff, \DateTimeImmutable $now ): void {
		// El monto a vencer se procesa bajo el candado del usuario con saldo recalculado,
		// previniendo colisiones de concurrencia con canjes en checkout simultáneo.
		$moved = Ledger::with_user_lock(
			$user_id,
			function () use ( $user_id, $cutoff, $now ) {
				$to_expire = self::expirable_for( $user_id, $cutoff, true );

				if ( $to_expire <= 0 ) {
					return 0; // Nada vencido: sigue el aviso fuera del candado.
				}

				return Ledger::debit(
					$user_id,
					$to_expire,
					'expiry',
					'user',
					$user_id,
					sprintf( 'Vencimiento de puntos con más de %d meses', self::months() ),
					false,
					sprintf( 'expiry_%d_%s', $user_id, $now->format( 'Ymd' ) )
				);
			}
		);

		if ( 0 !== $moved ) {
			return; // Venció (o quedó para el reintento): sin aviso este ciclo.
		}

		// Aviso de piedad: si algo vence dentro de la ventana y no se ha
		// avisado este ciclo, se dispara el hook (el correo escucha ahí).
		$warn_days = self::warn_days();
		if ( $warn_days <= 0 ) {
			return;
		}

		$soon = self::expiring_soon( $user_id, $warn_days );
		if ( $soon <= 0 ) {
			return;
		}

		$marker = $now->format( 'Y-m' );
		if ( (string) get_user_meta( $user_id, self::META_WARNED, true ) === $marker ) {
			return; // Un aviso por mes, no spam.
		}
		// El marcador lo sella el envío tras confirmar el transporte seguro.

		if ( $this->use_batches() ) {
			// Envíos individuales en segundo plano para aislar la latencia del servidor de correos.
			// Se programa por usuario para recalcular el saldo pendiente al momento de la entrega.
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::HOOK_WARN_ASYNC, array( $user_id ) ) ) {
				return;
			}

			as_enqueue_async_action( self::HOOK_WARN_ASYNC, array( $user_id ) );
			return;
		}

		/**
		 * Puntos por vencer. El correo de aviso escucha acá.
		 *
		 * @param int $user_id   Usuario.
		 * @param int $soon      Puntos que vencerán.
		 * @param int $warn_days Ventana del aviso.
		 */
		do_action( 'akb_rewards_expiry_warning', $user_id, $soon, $warn_days );
	}
}
