<?php
/**
 * ReadyEmailDispatcher — la máquina de despacho del aviso «preventa lista».
 *
 * Vivía dentro de `OrderPreventa`, que es la RAÍZ DE COMPOSICIÓN del módulo: la
 * clase que instancia dieciocho colaboradores y les dice cuándo registrarse. Con
 * la máquina adentro, esa misma clase era además el dueño de una lógica con
 * estado propio —agendado, reintento, idempotencia por marker, supresión del
 * email nativo y reenvío manual desde el metabox—, y no había forma de leer una
 * sin leer la otra ni de probar la máquina sin construir el módulo entero.
 *
 * Lo que hace, en un solo lugar:
 *
 *   - agenda UNA acción por pedido cuando el flip lo marca listo (el group lleva
 *     el ID porque Action Scheduler dedupea por hook + group);
 *   - instancia el mailer DENTRO del worker, cuando WooCommerce ya cargó
 *     `WC_Email`, y deja que el email aplique su guard persistente y su lock;
 *   - reintenta con grupo propio cuando Action Scheduler declara fallido al
 *     worker, y también al entrar a un estado que el adaptador de fulfillment
 *     declara apto;
 *   - suprime el `customer_completed_order` nativo en pedidos con preventa
 *     pendiente;
 *   - ofrece el reenvío manual de los tres avisos en «Acciones del pedido»
 *     («ya llegó», «previo a la llegada» y «cambio de fecha»).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Utilities\Log;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.1
 */
final class ReadyEmailDispatcher {

	private const READY_EMAIL_DISPATCH_HOOK = 'akb_order_preventa_dispatch_ready_email';

	/**
	 * Grupo único del módulo en Action Scheduler.
	 *
	 * Utiliza un grupo compartido para evitar la proliferación de filas en
	 * `actionscheduler_groups`. La unicidad por pedido se gestiona mediante los argumentos.
	 */
	public const AS_GROUP = 'akibara-order-preventa';

	private const READY_EMAIL_RETRY_DELAY_SECONDS = 305;

	/** Marca de «ya dejé la nota de aviso no enviado por antigüedad». */
	private const AVISO_VIEJO_META = '_akb_eta_ready_email_too_old_noted';

	/**
	 * Acciones del selector "Acciones del pedido" (metabox nativo de WooCommerce).
	 *
	 * Permiten el reenvío manual de correos específicos de preventa desde la interfaz
	 * administrativa del pedido en WooCommerce.
	 */
	private const ORDER_ACTION_RESEND_READY    = 'akb_resend_preventa_ready';
	private const ORDER_ACTION_RESEND_REMINDER = 'akb_resend_preventa_reminder';

	/**
	 * Acción para reenvío manual del aviso de cambio de fecha en pedidos de preventa.
	 *
	 * Permite reenviar la notificación al cliente cuando una actualización de fecha
	 * requiere renotificación manual desde el panel.
	 */
	private const ORDER_ACTION_RESEND_DATE_CHANGE = 'akb_resend_preventa_date_change';

	public function register(): void {
		add_action( 'akb_order_preventa_marked_ready', array( $this, 'schedule_ready_email_dispatch' ), 10, 1 );
		add_action( self::READY_EMAIL_DISPATCH_HOOK, array( $this, 'dispatch_ready_email' ), 10, 1 );
		add_action( 'action_scheduler_failed_execution', array( $this, 'maybe_retry_failed_ready_email_dispatch' ), 10, 3 );
		add_action( 'action_scheduler_failed_action', array( $this, 'maybe_retry_failed_ready_email_dispatch' ), 10, 2 );
		// Los fatales que no son Throwable (memoria, tiempo máximo) no pasan por
		// los dos de arriba: Action Scheduler los marca fallidos desde su monitor
		// de apagado y sólo avisa por este.
		add_action( 'action_scheduler_unexpected_shutdown', array( $this, 'maybe_retry_failed_ready_email_dispatch' ), 10, 2 );

		// Suprime el email nativo `customer_completed_order` SOLO para órdenes
		// preventa — `ReadyToShipEmail` ya cubre el aviso (dispara en shipping-
		// progress/processing con copy de preventa + seguimiento condicional,
		// CUT-7 Fase 0/1). El genérico "tu pedido está completo" sería redundante y
		// con copy ajeno. (Antes lo cubría ShippedEmail, retirado en Fase 2 por
		// estar funcionalmente muerto.) Para órdenes sin preventa el nativo sigue.
		add_filter( 'woocommerce_email_enabled_customer_completed_order', array( $this, 'maybe_suppress_native_completed_email' ), 10, 2 );

		// Reintento desacoplado del email "ya llegó": cada adaptador de fulfillment
		// declara los estados operativos donde el aviso sigue siendo válido. Así el
		// core no depende de carriers, estados custom ni plugins de terceros.
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_resend_ready_email_on_status_change' ), 20, 4 );

		// Reenvío manual de los emails de preventa — 100% vía hooks nativos de
		// WooCommerce, sin tocar el metabox de terceros.
		add_filter( 'woocommerce_order_actions', array( $this, 'register_order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_' . self::ORDER_ACTION_RESEND_READY, array( $this, 'handle_resend_ready_email_action' ), 10, 1 );
		add_action( 'woocommerce_order_action_' . self::ORDER_ACTION_RESEND_REMINDER, array( $this, 'handle_resend_reminder_email_action' ), 10, 1 );
		add_action( 'woocommerce_order_action_' . self::ORDER_ACTION_RESEND_DATE_CHANGE, array( $this, 'handle_resend_date_change_email_action' ), 10, 1 );
	}

	/**
	 * Ofrece el reenvío del aviso de preventa que corresponda al estado de la
	 * orden: si ya está lista, el "ya llegó"; si sigue esperando, el T-7d —
	 * nunca ambos, el operador no tiene que elegir cuál aplica— y, mientras
	 * sigue esperando, también el de cambio de fecha si el pedido tiene un
	 * cambio registrado que todavía es el vigente (ver `last_date_change()`).
	 *
	 * @param array<string, string>|mixed $actions Acciones ofrecidas por WooCommerce.
	 * @param \WC_Order|mixed|null        $order   Orden en pantalla (null en pantallas sin orden).
	 * @return array<string, string>
	 */
	public function register_order_actions( $actions, $order = null ): array {
		$actions = (array) $actions;

		if ( ! $order instanceof \WC_Order ) {
			return $actions;
		}
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return $actions;
		}
		if ( ! PreventaItems::has_outstanding_items( $order ) ) {
			return $actions;
		}

		if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
			$actions[ self::ORDER_ACTION_RESEND_READY ] = 'Reenviar aviso «tu pedido ya llegó»';
			return $actions;
		}

		// Los avisos de espera aplican exclusivamente a pedidos en curso: pedidos
		// cancelados o reembolsados quedan excluidos para evitar notificaciones incoherentes.
		if ( ! self::waiting_resend_allowed( $order ) ) {
			return $actions;
		}

		// El aviso previo «Tu pedido llega pronto» solo se ofrece si la orden se
		// encuentra dentro de la ventana de anticipación configurada (`RemindersCron::within_t7_window`).
		$dias = RemindersCron::days_until_arrival( $order, time() );
		if ( null !== $dias && RemindersCron::within_t7_window( $dias ) ) {
			$actions[ self::ORDER_ACTION_RESEND_REMINDER ] = 'Reenviar aviso previo a la llegada';
		}

		if ( null !== self::last_date_change( $order ) ) {
			$actions[ self::ORDER_ACTION_RESEND_DATE_CHANGE ] = 'Reenviar aviso de cambio de fecha';
		}

		return $actions;
	}

	/**
	 * Todo lo que `ReminderEmail::order_passes_guards()` va a exigir antes de
	 * mandar un aviso de espera, más el destinatario: pedido con preventa, con
	 * líneas vigentes, todavía no listo, en estado de espera y con correo. Una
	 * sola función para el desplegable y para los dos handlers.
	 */
	private static function waiting_resend_allowed( \WC_Order $order ): bool {
		return 'yes' === (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA )
			&& PreventaItems::has_outstanding_items( $order )
			&& 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP )
			&& $order->has_status( FulfillmentCapabilities::waiting_order_statuses( $order ) )
			&& '' !== (string) $order->get_billing_email();
	}

	/**
	 * El ÚLTIMO cambio de fecha del pedido, si sigue vigente: se mira sólo la
	 * firma más reciente del CSV `old__new` que escribe
	 * `ReleaseDateChangeListener`. Tiene que ser una postergación o un adelanto
	 * (las dos fechas presentes: «anunciada» `__fecha` y «retirada» `fecha__`
	 * no son un cambio de fecha, y si son lo último que pasó, el cliente ya
	 * recibió su aviso o no hay fecha que avisar), y su `new` tiene que
	 * coincidir con la fecha de salida ACTUAL de exactamente una línea de
	 * preventa pendiente (vigente y sin llegar: la misma lista que pinta el
	 * correo). Si la fecha volvió a moverse, se retiró, o hay dos tomos con esa
	 * misma fecha, no hay nada honesto que reenviar: un aviso de una fecha que
	 * ya no rige, o a nombre del tomo equivocado, sería mentirle al cliente.
	 *
	 * Las firmas no llevan producto (se dedupean por cambio, no por línea), por
	 * eso el producto se resuelve por la fecha y por eso sólo se reenvía el
	 * último cambio del pedido, nunca un historial: el aviso perdido de un tomo
	 * cuya fecha cambió ANTES que la de otro tomo no se puede rescatar desde
	 * aquí (hay que avisar a mano).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array{product_id:int, old:string, new:string}|null
	 */
	public static function last_date_change( \WC_Order $order ): ?array {
		$sigs = array_values( array_filter( array_map( 'trim', explode( ',', (string) $order->get_meta( MetaKeys::ORDER_DATE_CHANGE_SIGS ) ) ) ) );
		if ( array() === $sigs ) {
			return null;
		}
		$partes = explode( '__', (string) end( $sigs ), 2 );
		if ( 2 !== count( $partes ) || '' === $partes[0] || '' === $partes[1] ) {
			return null;
		}

		$candidatos = array();
		foreach ( PreventaItems::pending_items( $order ) as $item ) {
			$product_id = (int) $item->get_product_id();
			if ( $product_id > 0 && (string) get_post_meta( $product_id, Availability::META_RELEASE_DATE, true ) === $partes[1] ) {
				$candidatos[ $product_id ] = true;
			}
		}
		if ( 1 !== count( $candidatos ) ) {
			return null;
		}

		return array(
			'product_id' => (int) array_key_first( $candidatos ),
			'old'        => $partes[0],
			'new'        => $partes[1],
		);
	}

	/**
	 * Reenvío manual del aviso de cambio de fecha. Mismo criterio que los dos
	 * de arriba: se sueltan el tope de frecuencia y su contador (lo pide el
	 * operador, la ventana no manda) y se re-dispara el evento de siempre, que
	 * WooCommerce re-emite con sufijo `_notification` hacia
	 * `ReminderEmail::trigger_date_changed()`. Así el reenvío pasa por la misma
	 * puerta que el envío automático: si el correo sigue apagado, deja la nota
	 * «NO enviado… deshabilitado» en vez de fingir; si el transporte falla,
	 * `SendFailureRearm` lo cuenta; si sale, la nota afirmativa de siempre.
	 *
	 * Sin idempotencia entre peticiones, a propósito y como en los otros dos
	 * reenvíos: cada «Actualizar» con la acción elegida manda un correo (el
	 * `did_action()` del metabox de WooCommerce sólo cubre la misma petición).
	 * No pasa por el candado global de `ReleaseDateChangeListener`: si un
	 * cambio de fecha del mismo pedido se está procesando en ese mismo segundo,
	 * pueden salir dos correos; ventana de milisegundos, aceptada.
	 *
	 * @param \WC_Order|mixed $order Orden entregada por el metabox de WooCommerce.
	 * @return void
	 */
	public function handle_resend_date_change_email_action( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! self::waiting_resend_allowed( $order ) ) {
			$order->add_order_note( 'Reenvío del aviso de cambio de fecha NO posible: el pedido ya no espera (listo, cancelado, sin líneas de preventa o sin correo del cliente).' );
			$order->save();
			return;
		}

		$cambio = self::last_date_change( $order );
		if ( null === $cambio ) {
			$order->add_order_note( 'Reenvío del aviso de cambio de fecha NO posible: el último cambio registrado ya no rige (la fecha volvió a moverse o se retiró) o dos tomos del pedido comparten esa fecha y no se sabe cuál cambió; avísale a mano.' );
			$order->save();
			return;
		}

		$order->delete_meta_data( MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT );
		$order->delete_meta_data( MetaKeys::ORDER_DATE_CHANGE_SEND_ATTEMPTS );
		$order->add_order_note(
			sprintf(
				'Reenvío manual del aviso de cambio de fecha solicitado desde Acciones del pedido (producto #%d: %s → %s).',
				$cambio['product_id'],
				$cambio['old'],
				$cambio['new']
			)
		);
		$order->save();

		do_action( 'akb_order_preventa_date_changed', $order->get_id(), $cambio['product_id'], $cambio['old'], $cambio['new'] );
	}

	/**
	 * Reenvío manual del email "preventa lista". Borra el marker (y su contador
	 * de intentos) para que el guard persistente de `ReadyToShipEmail` deje
	 * pasar esta pasada, y delega en el dispatcher único de siempre — así el
	 * reenvío manual y el automático comparten lock e idempotencia.
	 *
	 * @param \WC_Order|mixed $order Orden entregada por el metabox de WooCommerce.
	 * @return void
	 */
	public function handle_resend_ready_email_action( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order->delete_meta_data( MetaKeys::ORDER_READY_EMAIL_SENT );
		$order->delete_meta_data( MetaKeys::ORDER_READY_EMAIL_ATTEMPTS );
		$order->delete_meta_data( MetaKeys::ORDER_READY_EMAIL_WORKER_FAILURES );
		// Si el reenvío vuelve a chocar con un estado de salida, que se diga otra
		// vez: la nota anterior es de antes de que el dueño lo pidiera.
		$order->delete_meta_data( MetaKeys::ORDER_READY_EMAIL_DEPARTED_NOTED );
		$order->add_order_note( 'Reenvío manual del aviso «tu pedido ya llegó» solicitado desde Acciones del pedido.' );
		$order->save();

		$this->schedule_ready_email_dispatch( $order->get_id() );
	}

	/**
	 * Reenvío manual del recordatorio T-7d. Mismo criterio que arriba: se borra
	 * el marker de idempotencia y se re-dispara la acción transaccional de
	 * siempre (WooCommerce bootea el mailer y re-emite con sufijo
	 * `_notification`, que es lo que `ReminderEmail` escucha).
	 *
	 * @param \WC_Order|mixed $order Orden entregada por el metabox de WooCommerce.
	 * @return void
	 */
	public function handle_resend_reminder_email_action( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! self::waiting_resend_allowed( $order ) ) {
			$order->add_order_note( 'Reenvío del aviso previo a la llegada NO posible: el pedido ya no espera (listo, cancelado, sin líneas de preventa o sin correo del cliente).' );
			$order->save();
			return;
		}

		$dias = RemindersCron::days_until_arrival( $order, time() );
		if ( null === $dias || ! RemindersCron::within_t7_window( $dias ) ) {
			$order->add_order_note(
				sprintf(
					'Reenvío del aviso previo a la llegada NO posible: %s, fuera de la ventana de %d a %d días del aviso (Ajustes → Aviso previo a la llegada). «Llega pronto» sólo se manda cuando es verdad.',
					null === $dias ? 'el pedido no tiene arribo estimado' : sprintf( 'al pedido le quedan %d días', $dias ),
					Settings::get_int( Settings::OPT_T7_WINDOW_MIN ),
					Settings::get_int( Settings::OPT_T7_WINDOW_MAX )
				)
			);
			$order->save();
			return;
		}

		$order->delete_meta_data( MetaKeys::ORDER_T7_REMINDER_SENT );
		$order->delete_meta_data( MetaKeys::ORDER_T7_REMINDER_ATTEMPTS );
		$order->add_order_note( 'Reenvío manual del recordatorio T-7d solicitado desde Acciones del pedido.' );
		$order->save();

		do_action( 'akb_order_preventa_t7_reminder', $order->get_id() );
	}

	/**
	 * Re-dispara el email "ya llegó" al entrar a un estado que el adaptador de
	 * fulfillment declara apto para este aviso. Cubre el caso donde el flip ocurrió
	 * antes del cambio operativo y el envío falló en ese instante.
	 *
	 * @param int            $order_id  ID de la orden.
	 * @param string         $old_status Estado previo sin prefijo wc-.
	 * @param string         $new_status Estado nuevo sin prefijo wc-.
	 * @param \WC_Order|mixed $order    Orden entregada por WooCommerce.
	 * @return void
	 */
	public function maybe_resend_ready_email_on_status_change( $order_id, $old_status, $new_status, $order = null ): void {
		if ( $old_status === $new_status ) {
			return;
		}
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( ! FulfillmentCapabilities::can_send_ready_email( $order ) ) {
			return;
		}

		$this->maybe_resend_ready_email( $order->get_id() );
	}

	/**
	 * Re-dispara el email "ya llegó" si la orden ya está lista pero el email no
	 * salió. El guard de ReadyToShipEmail conserva la idempotencia final.
	 *
	 * @param int|mixed $order_id ID de la orden a revisar.
	 * @return void
	 */
	public function maybe_resend_ready_email( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return;
		}
		if ( ! PreventaItems::has_outstanding_items( $order ) ) {
			return;
		}
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( MetaKeys::ORDER_READY_EMAIL_SENT ) ) {
			return;
		}
		if ( (int) $order->get_meta( MetaKeys::ORDER_READY_EMAIL_WORKER_FAILURES ) >= SendFailureRearm::MAX_ATTEMPTS ) {
			return; // Agotado por fallos del worker: la nota ya prometió recuperación manual.
		}
		if ( ! FulfillmentCapabilities::can_send_ready_email( $order ) ) {
			return;
		}
		if ( $this->demasiado_viejo( $order ) ) {
			return;
		}
		$this->schedule_ready_email_dispatch( $order->get_id() );
	}

	/**
	 * Evalúa si el pedido superó el umbral de antigüedad máxima para el envío automático.
	 *
	 * Previene el despacho de correos extemporáneos cuando una orden antigua cambia de estado.
	 * En caso de superar el umbral, se añade una nota informativa en el pedido para gestión manual.
	 *
	 * El tope se configura en Akibara → Pedidos y preventa; con 0 se desactiva.
	 *
	 * @since 1.114.7
	 *
	 * @param \WC_Order $order Pedido.
	 * @return bool
	 */
	private function demasiado_viejo( \WC_Order $order ): bool {
		$tope_dias = Settings::get_int( Settings::OPT_READY_EMAIL_MAX_AGE_DAYS );
		if ( $tope_dias <= 0 ) {
			return false;
		}

		$listo = (int) $order->get_meta( MetaKeys::ORDER_READY_DATE );
		if ( $listo <= 0 ) {
			return false; // Sin fecha de «listo» no hay antigüedad que juzgar.
		}

		$dias = (int) floor( ( time() - $listo ) / DAY_IN_SECONDS );
		if ( $dias <= $tope_dias ) {
			return false;
		}

		// Una sola nota por pedido: mover el estado varias veces no debe llenar
		// el historial de avisos repetidos.
		if ( 'yes' !== (string) $order->get_meta( self::AVISO_VIEJO_META ) ) {
			$order->add_order_note(
				sprintf(
					'Aviso «ya llegó» NO enviado: el pedido quedó listo hace %d días (tope: %d). Si el cliente todavía espera este pedido, avísale a mano; si ya lo recibió, ciérralo como Completado.',
					$dias,
					$tope_dias
				)
			);
			$order->update_meta_data( self::AVISO_VIEJO_META, 'yes' );
			$order->save();
		}

		return true;
	}

	/**
	 * Agenda el email "preventa lista" una sola vez por orden. El group lleva
	 * el ID porque el store de Action Scheduler dedupea por hook + group.
	 *
	 * @param int|mixed $order_id ID de la orden lista.
	 * @return void
	 */
	public function schedule_ready_email_dispatch( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->dispatch_ready_email( $order_id );
			return;
		}

		$args = array( 'order_id' => $order_id );
		if ( function_exists( 'as_has_scheduled_action' )
			&& \as_has_scheduled_action( self::READY_EMAIL_DISPATCH_HOOK, $args, self::AS_GROUP ) ) {
			return;
		}

		\as_schedule_single_action( time(), self::READY_EMAIL_DISPATCH_HOOK, $args, self::AS_GROUP );
	}

	/**
	 * Instancia el mailer dentro del worker, cuando WooCommerce ya cargó
	 * `WC_Email`, y deja que el email aplique su guard persistente y lock.
	 *
	 * @param int|mixed $order_id ID de la orden lista.
	 * @return void
	 */
	public function dispatch_ready_email( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! function_exists( 'WC' ) || ! WC() ) {
			return;
		}

		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ ReadyToShipEmail::EMAIL_ID ] ?? null;
		if ( $email instanceof ReadyToShipEmail ) {
			$email->trigger( $order_id );
		}
	}

	/**
	 * Reintenta un worker de email fallido tras una espera breve. El lock de
	 * conexión se libera al terminar su worker; Action Scheduler marca la
	 * acción como fallida ANTES de disparar los hooks de fallo, así que la
	 * consulta por args no la ve y el reintento sí se agenda. Si el marker ya
	 * fue persistido, la pasada posterior termina como no-op.
	 *
	 * @param int|mixed $order_id ID de la orden lista.
	 * @return void
	 */
	public function schedule_ready_email_dispatch_retry( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$args = array( 'order_id' => $order_id );

		if ( function_exists( 'as_has_scheduled_action' )
			&& \as_has_scheduled_action( self::READY_EMAIL_DISPATCH_HOOK, $args, self::AS_GROUP ) ) {
			return;
		}

		\as_schedule_single_action(
			time() + self::READY_EMAIL_RETRY_DELAY_SECONDS,
			self::READY_EMAIL_DISPATCH_HOOK,
			$args,
			self::AS_GROUP
		);
	}

	/**
	 * Agenda otra pasada si Action Scheduler marca fallido un worker de email.
	 * Si el marker ya fue persistido antes de un fatal posterior, el nuevo job
	 * termina como no-op; si no, entrega la recuperación que faltaba.
	 *
	 * @param int|mixed $action_id ID de la acción fallida.
	 * @param mixed     $error     Excepción o timeout reportado por Action Scheduler.
	 * @param mixed     $context   Contexto opcional del runner.
	 * @return void
	 */
	public function maybe_retry_failed_ready_email_dispatch( $action_id, $error = null, $context = null ): void {
		unset( $error, $context );

		if ( ! class_exists( '\ActionScheduler' ) ) {
			return;
		}

		try {
			$action = \ActionScheduler::store()->fetch_action( (int) $action_id );
		} catch ( \Throwable $exception ) {
			// Si la acción no se puede recuperar, se registra en log para diagnóstico.
			Log::warn(
				'preventa',
				sprintf(
					'fetch_action(%d) falló evaluando retry del email ready-to-ship: %s — la orden afectada no reintentará.',
					(int) $action_id,
					$exception->getMessage()
				)
			);
			return;
		}

		if ( ! is_object( $action )
			|| ! is_callable( array( $action, 'get_hook' ) )
			|| self::READY_EMAIL_DISPATCH_HOOK !== $action->get_hook()
			|| ! is_callable( array( $action, 'get_args' ) ) ) {
			return;
		}

		$args     = (array) $action->get_args();
		$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
		// Sin try, un fallo de base de datos o de un tercero enganchado al guardado
		// del pedido salía del manejador de errores y cortaba el lote entero de
		// la cola.
		try {
			$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			if ( '' !== (string) $order->get_meta( MetaKeys::ORDER_READY_EMAIL_SENT ) ) {
				return; // Ya salió: el fallo fue posterior al envío y no hay nada que recuperar.
			}

			// Si el propio email ya agotó su presupuesto (y dejó su nota), este
			// fallo es el mismo que él contó: ni otro contador ni otra nota.
			if ( (int) $order->get_meta( MetaKeys::ORDER_READY_EMAIL_ATTEMPTS ) >= SendFailureRearm::MAX_ATTEMPTS ) {
				return;
			}

			// Límite de fallos de worker para evitar bucles de reintento infinitos.
			$fallos = (int) $order->get_meta( MetaKeys::ORDER_READY_EMAIL_WORKER_FAILURES ) + 1;
			$order->update_meta_data( MetaKeys::ORDER_READY_EMAIL_WORKER_FAILURES, $fallos );

			if ( $fallos >= SendFailureRearm::MAX_ATTEMPTS ) {
				if ( SendFailureRearm::MAX_ATTEMPTS === $fallos ) {
					$order->add_order_note(
						sprintf(
							'El aviso «tu pedido ya llegó» falló %d veces por un error interno y se dejó de reintentar. Revisar el registro «akibara-preventa» y reenviarlo desde Acciones del pedido.',
							$fallos
						)
					);
					Log::warn(
						'preventa',
						sprintf( 'Aviso ready-to-ship del pedido #%d: %d fallos del worker, sin más reintentos automáticos.', $order_id, $fallos )
					);
				}
				$order->save();
				return;
			}

			$order->save();
			$this->schedule_ready_email_dispatch_retry( $order_id );
		} catch ( \Throwable $exception ) {
			Log::warn(
				'preventa',
				sprintf( 'No se pudo evaluar el reintento del aviso ready-to-ship del pedido #%d: %s', $order_id, $exception->getMessage() )
			);
		}
	}

	/**
	 * Suprime el correo nativo de pedido completado en órdenes con preventa pendiente,
	 * delegando la comunicación operativa a las notificaciones especializadas del flujo de preventa.
	 * Para órdenes regulares, preserva el comportamiento por defecto ($enabled).
	 *
	 * @param bool                 $enabled Valor actual del enable check.
	 * @param \WC_Order|mixed|null $order   Orden bajo evaluación (WC pasa el order).
	 * @return bool
	 */
	public function maybe_suppress_native_completed_email( $enabled, $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return (bool) $enabled;
		}
		if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA )
			&& PreventaItems::has_outstanding_items( $order ) ) {
			return false;
		}
		return (bool) $enabled;
	}
}
