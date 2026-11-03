<?php
/**
 * Agenda un único recordatorio de llegada T-7d por pedido elegible.
 *
 * Omite reservas cuyo plazo original entre compra y summary_max es menor de
 * 14 días. La marca _akb_eta_t7_reminder_sent limita el recordatorio a uno por
 * pedido; los rechazos de transporte se recuperan mediante SendFailureRearm.
 * El cron semanal se ancla al próximo domingo a las 03:00 de la zona de la
 * tienda y corrige su ancla cuando cambia el desfase horario.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Utilities\StoreTime;
use DateTimeImmutable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Scan + dispatch del milestone T-7d.
 *
 * No marcado `final` para permitir partial mocking en tests.
 *
 * @since 1.19.0
 */
class RemindersCron {

	public const HOOK     = 'akb_order_preventa_t7_reminder_scan';
	public const SCHEDULE = 'weekly';

	private const BATCH_SIZE = 100;

	/**
	 * Clock injectable para tests.
	 *
	 * @var callable():int
	 */
	private $now;

	/**
	 * @param callable|null $now Clock override (tests). Default: `time`.
	 */
	public function __construct( ?callable $now = null ) {
		$this->now = $now ?? 'time';
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Días enteros que faltan para el arribo estimado del pedido, o null si no
	 * hay estimación. Una sola cuenta para el barrido y para el reenvío manual
	 * (`ReadyEmailDispatcher`): «tu pedido llega pronto» sólo es verdad dentro
	 * de la ventana, venga por el cron o por Acciones del pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @param int      $now   Instante de la decisión.
	 */
	public static function days_until_arrival( WC_Order $order, int $now ): ?int {
		$summary_max = SummaryRecalculator::current_max_ts( $order );
		if ( null === $summary_max ) {
			return null;
		}
		return (int) floor( ( $summary_max - $now ) / DAY_IN_SECONDS );
	}

	/**
	 * ¿Esos días caen dentro de la ventana del aviso previo
	 * (Ajustes → Aviso previo a la llegada; de fábrica, de 1 a 10 días)?
	 */
	public static function within_t7_window( int $days_remaining ): bool {
		return $days_remaining <= Settings::get_int( Settings::OPT_T7_WINDOW_MAX )
			&& $days_remaining >= Settings::get_int( Settings::OPT_T7_WINDOW_MIN );
	}

	/**
	 * Agenda el evento semanal al próximo domingo a las 03:00 de la tienda.
	 *
	 * WordPress repite weekly con un intervalo fijo de 604800 segundos. Como ese
	 * intervalo no conserva la hora civil ante cambios de UTC offset, se comprueba
	 * y corrige el ancla local también cuando el evento ya existe.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		// Si el toggle del panel está apagado, no agendar (y desschedulear si
		// hubiera quedado un evento de un toggle previo). El listener en
		// Settings::on_t7_enabled_change() también cubre el toggle en vivo.
		if ( ! Settings::get_bool( Settings::OPT_T7_ENABLED ) ) {
			$existing = wp_next_scheduled( self::HOOK );
			if ( false !== $existing ) {
				wp_unschedule_event( $existing, self::HOOK );
			}
			return;
		}

		$existing = wp_next_scheduled( self::HOOK );
		if ( false === $existing ) {
			wp_schedule_event( $this->next_sunday_03_ts(), self::SCHEDULE, self::HOOK );
			return;
		}

		$anchor = $this->anchor_for( (int) $existing );
		if ( null === $anchor ) {
			return;
		}
		wp_unschedule_event( (int) $existing, self::HOOK );
		wp_schedule_event( $anchor, self::SCHEDULE, self::HOOK );
	}

	/**
	 * Adónde hay que mover un evento ya agendado, o null si se queda donde está.
	 *
	 * Se queda si ya cae en domingo 03:00 de la tienda, o si ya venció: un
	 * evento vencido está esperando a que WP-Cron lo corra, y moverlo sería
	 * saltarse la pasada de esta semana.
	 *
	 * El ancla nueva es el 03:00 del MISMO domingo del evento —el cambio de
	 * horario lo corre una hora, no de día—. Si ese 03:00 ya pasó (estamos en
	 * la hora corrida), tampoco se toca: corre una hora tarde esta vez y el
	 * evento de la semana siguiente se corrige en la próxima petición. Sólo un
	 * evento que ni siquiera cae en domingo se re-ancla contando desde ahora.
	 *
	 * @param int $event_ts Instante UTC del evento agendado.
	 * @return int|null
	 */
	private function anchor_for( int $event_ts ): ?int {
		$now = (int) ( $this->now )();
		if ( $event_ts <= $now ) {
			return null;
		}

		$local = ( new DateTimeImmutable( '@' . $event_ts ) )->setTimezone( StoreTime::zone() );
		if ( '0 03:00:00' === $local->format( 'w H:i:s' ) ) {
			return null;
		}

		$anchor = '0' === $local->format( 'w' )
			? $local->setTime( 3, 0, 0 )->getTimestamp()
			: $this->next_sunday_03_ts();

		return $anchor > $now ? $anchor : null;
	}

	/**
	 * Calcula el timestamp UTC del próximo domingo a las 03:00 hora Chile.
	 *
	 * Pública por tests (verificar offset DST correcto en abril/septiembre).
	 *
	 * @return int Timestamp UTC.
	 */
	public function next_sunday_03_ts(): int {
		// La zona de la tienda procede de StoreTime::zone(), incluido su fallback
		// cuando WordPress aún no está disponible.

		$tz  = StoreTime::zone();
		$now = ( new DateTimeImmutable( '@' . ( $this->now )() ) )->setTimezone( $tz );
		$target = $now->modify( 'next sunday' )->setTime( 3, 0, 0 );

		return $target->getTimestamp();
	}

	/**
	 * Ejecuta una pasada del scan. Itera órdenes candidatas, evalúa elegibilidad
	 * por ventana + lead time + meta dedup, dispara action por cada match.
	 *
	 * @return void
	 */
	public function run(): void {
		// Defensive: el toggle podría haberse apagado entre el last-schedule y
		// la ejecución del cron. Si está off, no procesar.
		if ( ! Settings::get_bool( Settings::OPT_T7_ENABLED ) ) {
			return;
		}
		OrderQueryPaginator::process_all(
			$this->candidate_order_query_args(),
			array( $this, 'maybe_dispatch' )
		);
	}

	/**
	 * Evalúa una orden y dispara el action si cumple todos los gates.
	 *
	 * Gates:
	 *   1. Estimado VIGENTE presente (lector canónico `SummaryRecalculator::
	 *      current_max_ts`; una orden comprada TBA cuyo producto anunció fecha
	 *      pasa este gate tras el recompute del listener).
	 *   2. days_remaining ∈ [WINDOW_MIN, WINDOW_MAX] (estamos en milestone).
	 *   3. lead_days >= MIN_LEAD_DAYS (preventa original era larga).
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	public function maybe_dispatch( WC_Order $order ): void {
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return;
		}
		if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
			return;
		}
		if ( ! PreventaItems::has_outstanding_items( $order ) ) {
			return;
		}
		if ( ! $order->has_status( FulfillmentCapabilities::waiting_order_statuses( $order ) ) ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( MetaKeys::ORDER_T7_REMINDER_SENT ) ) {
			return;
		}

		$summary_max = SummaryRecalculator::current_max_ts( $order );
		if ( null === $summary_max ) {
			return;
		}

		$now            = ( $this->now )();
		$min_lead_days  = Settings::get_int( Settings::OPT_T7_LEAD_DAYS );
		$days_remaining = (int) self::days_until_arrival( $order, $now );

		if ( ! self::within_t7_window( $days_remaining ) ) {
			return;
		}

		$created = $order->get_date_created();
		if ( null === $created ) {
			return;
		}
		$lead_days = (int) floor( ( $summary_max - $created->getTimestamp() ) / DAY_IN_SECONDS );
		if ( $lead_days < $min_lead_days ) {
			return;
		}

		/**
		 * Fires cuando una orden preventa entra en milestone T-7d y debe
		 * recibir el recordatorio único de vigencia.
		 *
		 * Hook consumido por `ReminderEmail::trigger_t7()`.
		 *
		 * @since 1.19.0
		 *
		 * @param int $order_id       ID de la orden elegible.
		 * @param int $days_remaining Días hasta `summary_max` al momento del dispatch.
		 */
		do_action( 'akb_order_preventa_t7_reminder', $order->get_id(), $days_remaining );
	}

	/**
	 * Devuelve órdenes no listas en estado de espera. maybe_dispatch() aplica el
	 * gate de deduplicación en memoria para evitar meta_query no portable.
	 *
	 * Excluye on-hold (BACS pendiente — un reminder de vigencia confundiría al
	 * cliente que todavía no transfirió).
	 *
	 * @return array<int, WC_Order>
	 */
	private function candidate_order_query_args(): array {
		return array(
				'limit'      => self::BATCH_SIZE,
				'status'     => FulfillmentCapabilities::waiting_order_statuses(),
				// Incluir pedidos con meta de listo ausente: sólo yes descarta una reserva
				// pendiente, independientemente del camino con que se creó el pedido.

				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => MetaKeys::ORDER_READY_TO_SHIP,
						'value'   => 'yes',
						'compare' => '!=',
					),
					array(
						'key'     => MetaKeys::ORDER_READY_TO_SHIP,
						'compare' => 'NOT EXISTS',
					),
				),
				'return'     => 'objects',
				'orderby'    => 'date',
				'order'      => 'ASC',
		);
	}
}
