<?php
/**
 * Cron diario que flippea ORDER_READY_TO_SHIP cuando todos los items preventa
 * de una orden ya están en stock.
 *
 * Política inmutable: una vez flippeada a `yes`, NO se des-flippea aunque el
 * producto vuelva a onbackorder. La orden ya marcó hito operacional.
 *
 * Safety net: una orden que sigue con pendientes pasada la gracia sobre la
 * fecha PROMETIDA (o el tope desde la primera promesa) recibe una order note
 * de aviso pero NO se flippea — el owner la trata caso por caso (producto
 * cancelado por editorial, error de stock, etc.). Ver `maybe_mark_stale()`.
 *
 * El evento `akb_order_preventa_marked_ready` se dispara en cada flip; el
 * orquestador registra el dispatcher único del email cliente sin acoplar este
 * cron al mailer.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Cron handler + scheduling.
 *
 * No marcado `final` desde 1.15.0 para permitir partial mocking en tests
 * de los componentes que dependen del cron (`StockChangeListener`).
 *
 * @since 1.14.0
 */
class ReadyToShipCron {

	public const HOOK             = 'akb_order_preventa_recompute_ready';
	public const SCHEDULE         = 'daily';
	private const BATCH_SIZE      = 100;

	private const SHIPPED_STALE_NOTE_META = '_akb_eta_shipped_stale_noted';

	/**
	 * Clock injectable para tests.
	 *
	 * @var callable():int
	 */
	private $now;

	/**
	 * Recalcula el estimado de arribo cuando cambia lo pendiente. Obligatorio:
	 * era opcional «para los dobles» y un olvido en el módulo degradaba en
	 * silencio (el cron marcaba «listo» pero el estimado del pedido nunca bajaba).
	 */
	private SummaryRecalculator $recalculator;

	/**
	 * @param SummaryRecalculator $recalculator Recalculador del arribo.
	 * @param callable|null       $now          Clock override (tests). Default: `time`.
	 */
	public function __construct( SummaryRecalculator $recalculator, ?callable $now = null ) {
		$this->recalculator = $recalculator;
		$this->now          = $now ?? 'time';
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Mantiene el evento programado incluso si se elimina fuera de activación.
	 *
	 * El chequeo en init repone un evento ausente en la siguiente petición.
	 * No depende de un cambio de versión ni de admin_init: la activación y las
	 * actualizaciones del plugin no cubren por sí solas la eliminación del cron.
	 * wp_next_scheduled() consulta la opción cron ya cargada por WordPress.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( ( $this->now )(), self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Ejecuta una pasada del cron. Procesa TODAS las órdenes del conjunto,
	 * leídas en páginas de BATCH_SIZE — `limit` es tamaño de página, no tope de
	 * trabajo (el docblock previo decía "hasta BATCH_SIZE órdenes": falso desde
	 * que el recorrido pasó al paginador). Si alguna vez hace falta un techo
	 * real, `OrderQueryPaginator::process_all()` acepta `$max_total`; ojo que el
	 * recorrido va de la última página a la primera y estas consultas ordenan
	 * `date ASC`, así que un tope bajo dejaría fuera a las órdenes más antiguas.
	 *
	 * Marca como ready_to_ship las órdenes cuyos items preventa pasaron todos
	 * a instock. Agrega note de aviso a las stale. Actualiza pending_count
	 * incluso si no flippea, así la UI muestra el contador correcto.
	 *
	 * Segunda pasada: alerta órdenes ya `ready_to_ship=yes` pero que llevan
	 * más de SHIPPED_STALE_DAYS hábiles sin pasar a completed (admin olvidó
	 * despacharlas tras el aviso al cliente).
	 *
	 * @return void
	 */
	public function run(): void {
		OrderQueryPaginator::process_all(
			$this->candidate_order_query_args(),
			array( $this, 'process_order' )
		);

		OrderQueryPaginator::process_all(
			$this->ready_unshipped_order_query_args(),
			function ( WC_Order $order ): void {
				$this->maybe_alert_shipped_stale( $order );
			}
		);
	}

	/**
	 * La nota que explica por qué un pedido se cerró sin aviso de llegada.
	 *
	 * Pura y aparte para poder probarla: es una decisión con reglas, y la regla
	 * estaba mal. Las dos ramas terminan igual —el pedido queda listo y el correo
	 * «ya llegó» no sale— pero por motivos opuestos, y el dueño necesita
	 * distinguirlos para saber si tiene que hacer algo.
	 *
	 * @param bool $reembolsado ¿El pedido tiene algo devuelto?
	 */
	public static function nota_de_cierre( bool $conserva_marcas ): string {
		if ( $conserva_marcas ) {
			return 'Ya no queda nada pendiente tras el reembolso — no se envía aviso de llegada.';
		}

		// NO se ofrece «reenviar desde Acciones del pedido», y decirlo sería peor
		// que callar: esa acción no aparece en este pedido —`ReadyEmailDispatcher`
		// la añade sólo si quedan líneas en espera, que es justo lo que aquí no
		// hay—, y forzarla no serviría porque el correo se arma con esas líneas y
		// saldría vacío. Se usa la fórmula que ya emplea la casa en
		// `ReadyToShipEmail`: avisar a mano.
		return 'Este pedido se quedó SIN líneas marcadas como preventa y no hay ningún reembolso: lo más probable es que una edición manual o una importación borrara esa marca. Se marca listo para despachar, pero el aviso «ya llegó a Akibara» ya no puede salir —el correo se arma con las líneas en espera y saldría vacío—. Restaura la marca en las líneas del pedido, o avísale a mano al cliente.';
	}

	/**
	 * ¿Queda alguna línea marcada como preventa en el pedido?
	 *
	 * Es el hecho que separa las dos causas, y el primer intento lo erró: se
	 * decidía con `get_total_refunded()`, que suma **todos** los reembolsos del
	 * pedido. Un reembolso parcial del despacho —o de una línea que no era
	 * preventa— con las marcas borradas por una edición manual devolvía la nota
	 * del reembolso y el caso volvía a ser invisible, que es exactamente el
	 * defecto que esto cierra.
	 *
	 * Si quedan marcas y no hay nada en espera, lo esperado se reembolsó. Si no
	 * queda ninguna, la marca se perdió. Cero consultas nuevas: las líneas y su
	 * meta ya vienen con el pedido.
	 *
	 * @param WC_Order $order Pedido que se está cerrando.
	 */
	private static function conserva_marcas_de_preventa( WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && PreventaItems::era_preventa( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Procesa una sola orden — público para WP-CLI manual.
	 *
	 * @param WC_Order $order Orden con has_preventa=yes y ready_to_ship=no.
	 * @return void
	 */
	public function process_order( WC_Order $order ): void {
		if ( ! $this->is_pending_active_preorder( $order ) ) {
			return;
		}

		if ( ! PreventaItems::has_outstanding_items( $order ) ) {
			$order->update_meta_data( MetaKeys::ORDER_PENDING_COUNT, 0 );
			$order->update_meta_data( MetaKeys::ORDER_READY_TO_SHIP, 'yes' );
			$order->update_meta_data( MetaKeys::ORDER_READY_DATE, ( $this->now )() );

			// El pedido tenía líneas en espera y ya no tiene ninguna. Hay dos
			// caminos para llegar aquí y NO son el mismo:
			//
			//  · Se reembolsó lo que faltaba. No hay nada que anunciar y callar es
			//    lo correcto.
			//  · Las líneas PERDIERON su marca —una edición a mano, una
			//    importación, un plugin que reescribió los ítems— sin que se
			//    devolviera un peso. Entonces el cliente sigue esperando su tomo,
			//    el pedido se da por listo y el aviso «ya llegó» no sale nunca.
			//
			// Hasta 1.116.x los dos dejaban la MISMA nota, que además afirmaba un
			// reembolso que podía no existir: el segundo caso quedaba invisible y
			// el cliente sin aviso. Ahora se distinguen por el dato que los separa,
			// que es si el pedido tiene algo devuelto.
			$order->add_order_note( self::nota_de_cierre( self::conserva_marcas_de_preventa( $order ) ) );

			$order->save();
			return;
		}

		$antes   = (int) $order->get_meta( MetaKeys::ORDER_PENDING_COUNT );
		$pending = count( PreventaItems::pending_items( $order ) );

		$order->update_meta_data( MetaKeys::ORDER_PENDING_COUNT, $pending );

		if ( $pending > 0 ) {
			// Llegó un tomo y otros no: el arribo estimado ya no debe contar el
			// que está en la estantería. SÓLO cuando cambió lo pendiente: la
			// 1.114.12 recalculaba en cada pasada diaria y, con una fecha de
			// salida ya vencida, el estimado se corría un día cada día (la
			// promesa nunca vencía, una nota por día, el aviso «revisar» mudo).
			if ( $pending !== $antes ) {
				$this->recalculator->recompute_for_order( $order );
			}
			$this->maybe_mark_stale( $order );
			$this->maybe_mark_refund_eligible( $order );
			$order->save();
			return;
		}

		$now = ( $this->now )();
		$order->update_meta_data( MetaKeys::ORDER_READY_TO_SHIP, 'yes' );
		$order->update_meta_data( MetaKeys::ORDER_READY_DATE, $now );
		$order->add_order_note( 'Pedido listo para despachar — todo lo que faltaba ya está en stock.' );
		$order->save();

		/**
		 * Notifica que una orden con preventa pasó a estado "ready to ship".
		 *
		 * @since 1.14.0
		 *
		 * @param int $order_id ID de la orden recién marcada lista.
		 */
		do_action( 'akb_order_preventa_marked_ready', $order->get_id() );
	}

	/**
	 * @param WC_Order $order Orden a evaluar.
	 * @return bool
	 */
	private function is_pending_active_preorder( WC_Order $order ): bool {
		return 'yes' === (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA )
			&& 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP )
			&& FulfillmentCapabilities::is_active_order( $order );
	}

	/**
	 * Deja UNA nota interna «revisar manualmente» cuando un pedido lleva
	 * demasiado esperando. No cambia estado ni bandera.
	 *
	 * Dos anclas, porque una sola falla en un sentido u otro:
	 *   - la PROMESA (arribo estimado vigente): «llevas N días de gracia después
	 *     de la fecha que le prometimos al cliente». Medir desde la compra
	 *     acusaba de retraso a preventas perfectamente sanas —un tomo que sale
	 *     en 90 días lleva 30 «pendiente» sin que nadie haya fallado— y callaba
	 *     sobre el pedido #27443, que venció el día que se escribió esto.
	 *   - el TOPE desde la compra: si la editorial pospone en bucle, la promesa
	 *     se corre en vivo y la primera ancla no dispararía nunca.
	 *   Sin promesa (`tba`), la gracia se mide desde la compra.
	 *
	 * @param WC_Order $order Orden.
	 * @return void
	 */
	private function maybe_mark_stale( WC_Order $order ): void {
		// El flag guarda POR QUÉ se avisó. Una nota por tope (o sin promesa) no
		// tapa la del incumplimiento de la promesa real, que es la que importa;
		// 'yes' es el valor heredado de antes de 1.114.16 y cuenta como promesa.
		$avisado = (string) $order->get_meta( MetaKeys::ORDER_STALE_NOTED );
		if ( in_array( $avisado, array( 'yes', 'promesa' ), true ) ) {
			return;
		}

		$created = $order->get_date_created();
		if ( null === $created ) {
			return;
		}

		$ahora   = ( $this->now )();
		$compra  = $created->getTimestamp();
		$promesa = SummaryRecalculator::current_max_ts( $order );

		// El tope se mide desde la PRIMERA promesa (la de compra), no desde la
		// compra: una preventa sana comprada nueve meses antes de la salida no
		// es un atraso. Sin promesa de compra, se cae a la fecha de compra.
		$origen_compra = PromiseWindow::of( $order )['max'];
		$origen        = $origen_compra ?? $compra;

		$dias_origen  = (int) floor( ( $ahora - $origen ) / DAY_IN_SECONDS );
		$dias_compra  = (int) floor( ( $ahora - $compra ) / DAY_IN_SECONDS );
		$dias_promesa = null !== $promesa ? (int) floor( ( $ahora - $promesa ) / DAY_IN_SECONDS ) : null;

		$gracia = Settings::get_int( Settings::OPT_STALE_PENDING_DAYS );
		$tope   = Settings::get_int( Settings::OPT_STALE_MAX_AGE_DAYS );

		$por_promesa = null !== $dias_promesa && $dias_promesa >= $gracia;
		$por_tope    = $dias_origen >= $tope;
		$sin_promesa = null === $dias_promesa && $dias_compra >= $gracia;

		// Ya se avisó por tope: sólo vuelve a hablar el incumplimiento real.
		if ( '' !== $avisado && ! $por_promesa ) {
			return;
		}
		if ( ! $por_promesa && ! $por_tope && ! $sin_promesa ) {
			return;
		}

		if ( $por_promesa ) {
			$motivo = sprintf( '%d días después de la fecha de llegada prometida al cliente', $dias_promesa );
		} elseif ( $por_tope ) {
			$motivo = null !== $origen_compra
				? sprintf( '%d días después de la fecha prometida al comprar, aunque la estimación se haya ido corriendo', $dias_origen )
				: sprintf( '%d días desde la compra (el tomo no tenía fecha al comprar)', $dias_compra );
		} else {
			$motivo = sprintf( '%d días desde la compra sin fecha de llegada', $dias_compra );
		}

		$order->add_order_note(
			sprintf(
				'Pedido con %d producto(s) sin llegar: %s — revisar manualmente.',
				(int) $order->get_meta( MetaKeys::ORDER_PENDING_COUNT ),
				$motivo
			)
		);
		$order->update_meta_data( MetaKeys::ORDER_STALE_NOTED, $por_promesa ? 'promesa' : 'tope' );
	}

	/**
	 * Anota una vez el vencimiento del plazo de reembolso publicado.
	 *
	 * El reloj compara la fecha prometida con Settings::REFUND_AFTER_DAYS. La nota
	 * interna informa al dueño; el reembolso sigue siendo manual y no se envía un
	 * correo al cliente desde este control.
	 *
	 * El plazo contractual es independiente de los umbrales operativos editables
	 * de maybe_mark_stale(). No comparten umbral ni bandera de idempotencia:
	 * cambiar la gracia operativa no debe modificar el plazo de reembolso.
	 * Sin fecha prometida vigente (TBA) no se calcula atraso ni se deja nota.
	 *
	 * @param WC_Order $order Orden.
	 * @return void
	 */
	private function maybe_mark_refund_eligible( WC_Order $order ): void {
		if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_REFUND_ELIGIBLE_NOTED ) ) {
			return;
		}

		$vigente = SummaryRecalculator::current_max_ts( $order );
		if ( null === $vigente ) {
			return;
		}

		// El plazo de reembolso se ancla en la fecha prometida al comprar, no en una
		// ETA que puede posponerse. Sin snapshot de compra usa la vigente. Es una señal
		// interna y debe sobrevivir a las sucesivas postergaciones del proveedor.

		$de_compra = PromiseWindow::of( $order )['max'];
		$promesa   = null !== $de_compra ? min( $de_compra, $vigente ) : $vigente;
		$se_corrio = $promesa < $vigente;

		$dias_promesa = (int) floor( ( ( $this->now )() - $promesa ) / DAY_IN_SECONDS );
		// Más de15días significa que el derecho nace el día16. La comparación debe
		// respetar el límite publicado sin adelantar la señal al día15.

		if ( $dias_promesa <= Settings::REFUND_AFTER_DAYS ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				'El pedido lleva %1$d días de atraso sobre la fecha de llegada %2$s: ya superó el plazo de %3$d días publicado en Términos, FAQ y Devoluciones. El cliente ya tiene derecho a cancelar y a un reembolso del 100%%. El reembolso sigue siendo manual (WhatsApp), a tu criterio.',
				$dias_promesa,
				$se_corrio
					? 'prometida al comprar (la editorial la fue corriendo y al cliente se le avisó cada cambio; la vigente es posterior)'
					: 'prometida al cliente',
				Settings::REFUND_AFTER_DAYS
			)
		);
		$order->update_meta_data( MetaKeys::ORDER_REFUND_ELIGIBLE_NOTED, 'yes' );
	}

	/**
	 * Alerta order note una sola vez cuando una orden lleva ya marcada
	 * `ready_to_ship=yes` hace más de SHIPPED_STALE_DAYS días hábiles pero el
	 * admin no la ha completado. Cierra el gap "cliente recibió email de
	 * preventa lista pero el pedido no se ha movido".
	 *
	 * @param WC_Order $order Orden con ready_to_ship=yes y status=processing.
	 * @return void
	 */
	private function maybe_alert_shipped_stale( WC_Order $order ): void {
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return;
		}
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
			return;
		}
		if ( ! $order->has_status( 'processing' ) ) {
			return;
		}
		if ( ! PreventaItems::has_outstanding_items( $order ) ) {
			return;
		}
		if ( 'yes' === (string) $order->get_meta( self::SHIPPED_STALE_NOTE_META ) ) {
			return;
		}

		$ready_ts = (int) $order->get_meta( MetaKeys::ORDER_READY_DATE );
		if ( $ready_ts <= 0 ) {
			return;
		}

		$now             = ( $this->now )();
		$business_days   = $this->business_days_between( $ready_ts, $now );
		$stale_threshold = Settings::get_int( Settings::OPT_STALE_POST_READY_DAYS );

		if ( $business_days < $stale_threshold ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				// La nota cita la MISMA promesa que leyó el cliente: si el dueño
				// cambia la ventana, el aviso interno no puede quedarse citando otra.
				'⚠️ Preventa lista hace %d días hábiles sin despachar. Cliente fue notificado que salía en %s — priorizar.',
				$business_days,
				ShipPromise::window()
			)
		);
		$order->update_meta_data( self::SHIPPED_STALE_NOTE_META, 'yes' );
		$order->save();
	}

	/**
	 * Cuenta días hábiles (L-V) entre dos timestamps. Lun-Vie cuentan, Sáb-Dom no.
	 * Excluye feriados chilenos fijos hardcoded (MM-DD) — no incluye Semana
	 * Santa ni los movibles porque requeriría tabla externa o cómputo Easter,
	 * que es overkill para reducir falsos positivos del stale alert.
	 *
	 * @param int $from Timestamp inicial.
	 * @param int $to   Timestamp final.
	 * @return int Días hábiles transcurridos (>=0).
	 */
	private function business_days_between( int $from, int $to ): int {
		if ( $to <= $from ) {
			return 0;
		}
		$days   = 0;
		$cursor = $from + DAY_IN_SECONDS;
		while ( $cursor <= $to ) {
			$dow = (int) wp_date( 'N', $cursor ); // 1=Lun... 7=Dom
			if ( $dow >= 1 && $dow <= 5 && ! $this->is_cl_holiday_fixed( $cursor ) ) {
				++$days;
			}
			$cursor += DAY_IN_SECONDS;
		}
		return $days;
	}

	/**
	 * Devuelve true si la fecha del timestamp es un feriado chileno fijo.
	 * Cubre los feriados de fecha exacta MM-DD (no movibles ni Semana Santa).
	 * Permite override via filter `akb_order_preventa_holidays_mmdd`.
	 *
	 * Fuente: https://www.feriadoschilenos.com/
	 *
	 * @param int $ts Timestamp.
	 * @return bool
	 */
	private function is_cl_holiday_fixed( int $ts ): bool {
		$mmdd = wp_date( 'm-d', $ts );

		/**
		 * Lista de feriados fijos chilenos (MM-DD). Filterable para inyectar
		 * feriados regionales/movibles desde otros plugins.
		 *
		 * @since 1.18.5
		 *
		 * @param array<int, string> $mmdds Lista de MM-DD que cuentan como feriado.
		 */
		$mmdds = (array) apply_filters( 'akb_order_preventa_holidays_mmdd', array(
			'01-01', // Año Nuevo
			'05-01', // Día del Trabajador
			'05-21', // Glorias Navales
			'06-20', // Día Nacional de los Pueblos Indígenas
			'06-29', // San Pedro y San Pablo (puede mover por Ley 19.668)
			'07-16', // Virgen del Carmen
			'08-15', // Asunción de la Virgen
			'09-18', // Independencia Nacional
			'09-19', // Glorias del Ejército
			'10-12', // Encuentro de Dos Mundos (puede mover)
			'10-31', // Iglesias Evangélicas (puede mover)
			'11-01', // Día de Todos los Santos
			'12-08', // Inmaculada Concepción
			'12-25', // Navidad
		) );

		return in_array( $mmdd, $mmdds, true );
	}

	/**
	 * Devuelve órdenes preventa processing; el callback filtra ready=yes y la
	 * ausencia de alerta stale con el objeto ya hidratado. Usamos solo meta_key
	 * estándar para funcionar también con el almacenamiento legacy de WC.
	 *
	 * @return array<int, WC_Order>
	 */
	private function ready_unshipped_order_query_args(): array {
		return array(
				'limit'      => self::BATCH_SIZE,
				'status'     => array( 'processing' ),
				'meta_key'   => MetaKeys::ORDER_HAS_PREVENTA,
				'meta_value' => 'yes',
				'return'     => 'objects',
				'orderby'    => 'date',
				'order'      => 'ASC',
		);
	}

	/**
	 * Devuelve órdenes preventa no listas en estado operativo. El paginador las
	 * procesa desde la última página a la primera, por lo que marcarlas listas
	 * durante la pasada no desplaza ni salta las páginas pendientes.
	 *
	 * Por defecto sólo `processing`; los adaptadores agregan sus estados operativos
	 * cuando corresponde. `on-hold` sigue excluido: el pago aún no está confirmado.
	 *
	 * @return array<int, WC_Order>
	 */
	private function candidate_order_query_args(): array {
		return array(
				'limit'      => self::BATCH_SIZE,
				'status'     => FulfillmentCapabilities::active_order_statuses(),
				// La ausencia de meta de listo equivale a pendiente; sólo yes queda fuera.
				// Mantener has_preventa dentro de la consulta para que pedidos ajenos a la
				// reserva no consuman el tamaño de página del barrido.

				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => MetaKeys::ORDER_HAS_PREVENTA,
						'value' => 'yes',
					),
					array(
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
				),
				'return'     => 'objects',
				'orderby'    => 'date',
				'order'      => 'ASC',
		);
	}
}
