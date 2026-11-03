<?php
/**
 * Recalcula las promesas de pedidos afectados por un cambio de lanzamiento.
 *
 * akb_eta_release_date_changed agenda un worker de Action Scheduler fuera de la
 * petición de guardado del producto. Los eventos accionables recomputan
 * ORDER_SUMMARY_MAX mediante SummaryRecalculator.
 *
 * Reglas de notificación:
 * - Las postergaciones respetan el umbral configurado.
 * - TBA a fecha usa date_announced, sin umbral y con límite de frecuencia.
 * - Fecha a TBA sólo recalcula y anota; no envía correo.
 * - Los adelantos dependen de notify_advance.
 *
 * Los cambios bajo umbral y los adelantos con toggle apagado clasifican none y
 * no recalculan. Un cambio de tipo auto a tba sin modificar la fecha no emite
 * el action de producto y conserva el snapshot. announced exige que, después
 * del recálculo, current_max_ts() tenga una fecha; si faltan datos ETA se deja
 * nota operativa. Completar datos y retirar/reponer la fecha genera nuevas firmas.
 *
 * La firma old__new deduplica evento, nota y correo, pero nunca impide recalcular
 * ni persistir el estimado vigente. Los replays pueden cambiar una ETA rolling.
 * El límite de frecuencia es independiente y se aplica en ReminderEmail.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Utilities\StoreTime;
use DateTimeImmutable;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Subscriber al action de cambio de release_date.
 *
 * No marcado `final` para permitir partial mocking en tests.
 *
 * @since 1.19.0
 */
class ReleaseDateChangeListener {

	private const BATCH_SIZE = 200;

	/**
	 * Hook del worker que hace la pasada real. La ejecución se difiere a Action
	 * Scheduler (mismo patrón que `OrderPreventa::schedule_ready_email_dispatch`)
	 * porque el trabajo NO cabe en el request de guardar el producto: recorre
	 * todas las órdenes en espera, carga sus line items, recalcula el summary,
	 * hace `save()` y encima manda el email transaccional de forma síncrona
	 * (`deferred_transactional_emails` está OFF en este sitio). Con un catálogo
	 * de preventa activo eso choca contra max_execution_time / timeout del proxy,
	 * y al morir el request la pasada se perdía ENTERA sin cursor: las órdenes no
	 * procesadas nunca recibían el aviso y el admin solo veía un 504. Peor: un
	 * segundo guardado con la misma fecha no las rescata, porque
	 * `ProductDataPanel` corta por `old !== new` y el hook ni se dispara.
	 */
	private const BATCH_HOOK = 'akb_order_preventa_release_date_changed_batch';

	/** Caducidad de un candado huérfano (pasada muerta a medias), en segundos. */
	private const LOCK_TTL = 15 * MINUTE_IN_SECONDS;

	/** Nombre de la fila de `wp_options` que hace de candado. */
	private const LOCK_NAME = '_akb_preventa_datechg_lock';

	/**
	 * Grupo fijo de Action Scheduler para los trabajos de cambio de fecha.
	 *
	 * La unicidad por producto reside en los args de as_has_scheduled_action().
	 * Compartir ReadyEmailDispatcher::AS_GROUP evita crear grupos por cada cambio.
	 */

	private const CHANGE_NONE         = 'none';
	private const CHANGE_POSTPONEMENT = 'postponement';
	private const CHANGE_ADVANCE      = 'advance';
	private const CHANGE_ANNOUNCED    = 'announced';
	private const CHANGE_RETRACTED    = 'retracted';

	private SummaryRecalculator $recalculator;

	/**
	 * Reloj inyectable (tests): decide si la fecha nueva ya pasó.
	 *
	 * @var callable():int
	 */
	private $now;

	/**
	 * Planificador del reintento (tests). Null = Action Scheduler.
	 *
	 * @var callable|null
	 */
	private $retry;

	/**
	 * @param SummaryRecalculator $recalculator Recalculador del arribo.
	 * @param callable|null       $now          Reloj (tests). Default: `time`.
	 * @param callable|null       $retry        Quien encola el reintento cuando otra pasada tiene el candado:
	 *                                          `( int $product_id, string $old, string $new): bool`,
	 *                                          true = agendado. Default: Action Scheduler si existe.
	 */
	public function __construct( SummaryRecalculator $recalculator, ?callable $now = null, ?callable $retry = null ) {
		$this->retry = $retry;
		$this->now = $now ?? 'time';
		$this->recalculator = $recalculator;
	}

	public function register(): void {
		add_action( 'akb_eta_release_date_changed', array( $this, 'on_release_date_changed' ), 10, 3 );
		add_action( self::BATCH_HOOK, array( $this, 'run_release_date_change' ), 10, 3 );
	}

	/**
	 * Handler del action: NO hace la pasada, solo la agenda (ver docblock de
	 * `BATCH_HOOK`). Fallback síncrono si Action Scheduler no está disponible
	 * — mismo contrato que `OrderPreventa::schedule_ready_email_dispatch()`.
	 *
	 * Doble disparo cubierto por tres capas: dedup POR ARGS antes de encolar,
	 * el dedup por signature en `process_order()` (meta ORDER_DATE_CHANGE_SIGS),
	 * que hace seguro un replay SECUENCIAL del job, y el candado global de
	 * `run_release_date_change()`, que es lo único que excluye dos pasadas
	 * SOLAPADAS (el CSV de firmas se lee y se escribe sin atomicidad).
	 *
	 * NO se usa el `$unique` de Action Scheduler (mismo motivo documentado en
	 * `StockChangeListener::schedule_product_change`): su chequeo es por hook +
	 * group e IGNORA los args, así que un segundo cambio de fecha del MISMO
	 * producto mientras el primer job sigue pendiente se descartaría entero —
	 * el cliente nunca se enteraría de la postergación más reciente, que es
	 * justo la material bajo Ley 21.719.
	 *
	 * Costo funcional aceptado: el aviso al cliente deja de ser inmediato y sale
	 * en la próxima corrida de Action Scheduler (típicamente <1 min). Para un
	 * cambio de fecha de preventa es irrelevante.
	 *
	 * @param int    $product_id       ID del producto cuyo release_date cambió.
	 * @param string $old_release_date Y-m-d previo (puede ser '' si no existía).
	 * @param string $new_release_date Y-m-d nuevo (puede ser '' si se borró).
	 * @return void
	 */
	public function on_release_date_changed( int $product_id, string $old_release_date, string $new_release_date ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->run_release_date_change( $product_id, $old_release_date, $new_release_date );
			return;
		}

		// Action Scheduler re-expande con `array_values()`: el ORDEN de las
		// claves define los parámetros posicionales del worker.
		$args  = array(
			'product_id'       => $product_id,
			'old_release_date' => $old_release_date,
			'new_release_date' => $new_release_date,
		);
		$group = ReadyEmailDispatcher::AS_GROUP;

		if ( function_exists( 'as_has_scheduled_action' )
			&& \as_has_scheduled_action( self::BATCH_HOOK, $args, $group ) ) {
			return;
		}

		\as_schedule_single_action( time(), self::BATCH_HOOK, $args, $group );
	}

	/**
	 * Pasada real. Clasifica el cambio, busca órdenes afectadas y procesa cada
	 * una (recompute + evento + email según la matriz).
	 *
	 * Público porque lo invoca el worker de Action Scheduler (y el fallback
	 * síncrono). Args sin type hints estrictos: llegan de vuelta desde el store
	 * de AS tras un round-trip JSON — se normalizan acá, como en los demás
	 * handlers async del módulo.
	 *
	 * Disparado solo cuando old !== new, así que valores idénticos no llegan
	 * acá. Aún chequeamos por defensa contra llamadas externas.
	 *
	 * @param int|mixed    $product_id       ID del producto cuyo release_date cambió.
	 * @param string|mixed $old_release_date Y-m-d previo (puede ser '' si no existía).
	 * @param string|mixed $new_release_date Y-m-d nuevo (puede ser '' si se borró).
	 * @return void
	 */
	public function run_release_date_change( $product_id, $old_release_date = '', $new_release_date = '' ): void {
		$product_id       = (int) $product_id;
		$old_release_date = (string) $old_release_date;
		$new_release_date = (string) $new_release_date;

		$change = $this->classify( $old_release_date, $new_release_date );
		if ( self::CHANGE_NONE === $change ) {
			return;
		}

		$signature = $old_release_date . '__' . $new_release_date;

		// UNA pasada a la vez (candado global). Dos guardados seguidos encolan
		// dos acciones con args distintos (el dedup por args no las ve iguales),
		// y con dos workers —WP-Cron y el cron de Hostinger cada 5 min— corren
		// solapadas: cada una lee el CSV de firmas del pedido antes de que la
		// otra lo escriba, y el cliente recibe DOS correos con segundos de
		// diferencia. El perdedor no tira el cambio: lo reintenta.
		$owned = $this->claim_lock();
		if ( ! $owned ) {
			if ( $this->retry_later( $product_id, $old_release_date, $new_release_date ) ) {
				return;
			}
			// Sin Action Scheduler no hay cola donde reintentar: se procesa igual,
			// SIN candado y sin soltar el ajeno. Perder el aviso es peor que el
			// riesgo de solape, y el dedup por firma protege el caso secuencial.
		}

		try {
			OrderQueryPaginator::process_all(
				$this->affected_order_query_args(),
				function ( WC_Order $order ) use ( $product_id, $old_release_date, $new_release_date, $signature, $change ): void {
					if ( ! $this->order_contains_preventa_product( $order, $product_id ) ) {
						return;
					}
					$this->process_order( $order, $product_id, $old_release_date, $new_release_date, $signature, $change );
				}
			);
		} finally {
			if ( $owned ) {
				$this->release_lock();
			}
		}
	}

	/**
	 * Candado ÚNICO para las pasadas de cambio de fecha, atómico de verdad.
	 *
	 * Uno solo, y no por producto: dos tomos del mismo pedido cambiando fecha a
	 * la vez comparten el CSV de firmas y las marcas del tope, y con candados
	 * por producto se pisaban igual. Las pasadas son cortas; serializarlas no
	 * cuesta nada.
	 *
	 * Vive en `wp_options` porque `wc_orders_meta` no tiene índice único sobre
	 * (order_id, meta_key): dos INSERT concurrentes crean dos filas y «ganan»
	 * los dos. `wp_options` sí tiene `UNIQUE KEY option_name`, el mismo patrón
	 * que `WP_Upgrader::create_lock()`. TODO por `$wpdb` y nada por
	 * `get_option()`/`delete_option()`: producción tiene caché de objetos
	 * persistente, y `get_option()` podía responder «no existe» desde
	 * `notoptions` sobre una fila que sí estaba en la tabla, con lo que el
	 * perdedor «robaba» el candado como huérfano y el correo salía dos veces.
	 * Un candado de una pasada muerta a medias caduca a los 15 minutos.
	 *
	 * Sin `$wpdb` (la suite corre sin base de datos) no hay concurrencia que
	 * cuidar: se concede. Las ramas reales se prueban con un doble de `$wpdb`
	 * (`ReleaseDateChangeLockTest`).
	 *
	 * @since 1.114.13
	 * @since 1.114.15 Global y sin pasar por la caché de options.
	 *
	 * @return bool true = esta pasada tiene el candado.
	 */
	private function claim_lock(): bool {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return true;
		}

		$won = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
				self::LOCK_NAME,
				(string) time()
			)
		);
		if ( 1 === $won ) {
			return true;
		}

		$stamp_raw = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s", self::LOCK_NAME )
		);
		$stamp = (int) $stamp_raw;
		if ( $stamp > 0 && ( time() - $stamp ) < self::LOCK_TTL ) {
			return false;
		}

		// Candado huérfano de una pasada que murió a medias (fatal o timeout: el
		// `finally` no corre). Se toma de forma ATÓMICA: sólo gana quien
		// reemplaza exactamente el sello caducado que leyó; dos reintentos
		// coincidentes no pueden ganar los dos.
		$tomado = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
				(string) time(),
				self::LOCK_NAME,
				$stamp_raw
			)
		);

		return 1 === $tomado;
	}

	/**
	 * @return void
	 */
	private function release_lock(): void {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'delete' ) ) {
			return;
		}
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_NAME ) );
	}

	/**
	 * Otra pasada está en vuelo: el aviso NO se pierde, se reintenta en un
	 * minuto. Suprimirlo en silencio es justo lo que el módulo prohíbe (Ley
	 * 21.719). Sin Action Scheduler no hay dónde reintentar: devuelve false y
	 * el llamador procesa igual, sin candado y sin soltar el ajeno.
	 *
	 * @param int    $product_id       Producto.
	 * @param string $old_release_date Fecha previa.
	 * @param string $new_release_date Fecha nueva.
	 * @return bool true = reintento agendado.
	 */
	private function retry_later( int $product_id, string $old_release_date, string $new_release_date ): bool {
		if ( null !== $this->retry ) {
			return (bool) ( $this->retry )( $product_id, $old_release_date, $new_release_date );
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		\as_schedule_single_action(
			time() + MINUTE_IN_SECONDS,
			self::BATCH_HOOK,
			array(
				'product_id'       => $product_id,
				'old_release_date' => $old_release_date,
				'new_release_date' => $new_release_date,
			),
			ReadyEmailDispatcher::AS_GROUP
		);

		return true;
	}

	/**
	 * Clasificador explícito del cambio. Matriz completa (incl. `''↔fecha`,
	 * que el viejo chequeo binario de postergación cortaba — causa raíz de Bug 2):
	 *
	 *   - `''` → fecha válida                     → `announced` (sin umbral, LOCKED #1).
	 *   - fecha válida → `''`                     → `retracted` (sin umbral, LOCKED #4).
	 *   - cualquiera → fecha que YA PASÓ          → `none`: la editorial ya publicó,
	 *     `Availability` trata al tomo como «a pedido» y no hay salida futura
	 *     que anunciar. Sin esto, corregir una fecha vieja por otra vieja
	 *     mandaba «nueva fecha» y «te llegaría alrededor del» una fecha vencida.
	 *   - fecha→fecha, atraso >= threshold        → `postponement`.
	 *   - fecha→fecha, adelanto >= threshold y
	 *     toggle `notify_advance` ON              → `advance`.
	 *   - resto (igual, inválido, sub-umbral,
	 *     adelanto con toggle OFF)                → `none`.
	 *
	 * Threshold y toggle: `Settings::OPT_DATE_CHANGE_*` (defaults 7d / off).
	 *
	 * @param string $old Y-m-d previo ('' posible).
	 * @param string $new Y-m-d nuevo ('' posible).
	 * @return string Una de las constantes CHANGE_*.
	 */
	private function classify( string $old, string $new ): string {
		if ( $old === $new ) {
			return self::CHANGE_NONE;
		}

		$old_valid = '' !== $old && self::valid_ymd( $old );
		$new_valid = '' !== $new && self::valid_ymd( $new );

		if ( $new_valid && self::ymd_to_ts( $new ) < $this->today_start() ) {
			return self::CHANGE_NONE;
		}

		if ( '' === $old && $new_valid ) {
			return self::CHANGE_ANNOUNCED;
		}
		if ( $old_valid && '' === $new ) {
			return self::CHANGE_RETRACTED;
		}
		if ( ! $old_valid || ! $new_valid ) {
			return self::CHANGE_NONE;
		}

		$old_ts = self::ymd_to_ts( $old );
		$new_ts = self::ymd_to_ts( $new );
		if ( $new_ts === $old_ts ) {
			return self::CHANGE_NONE;
		}

		$is_advance = ( $new_ts < $old_ts );
		if ( $is_advance && ! Settings::get_bool( Settings::OPT_DATE_CHANGE_NOTIFY_ADV ) ) {
			return self::CHANGE_NONE;
		}

		$threshold_days = Settings::get_int( Settings::OPT_DATE_CHANGE_THRESHOLD );
		$diff_days      = (int) floor( abs( $new_ts - $old_ts ) / DAY_IN_SECONDS );
		if ( $diff_days < $threshold_days ) {
			return self::CHANGE_NONE;
		}

		return $is_advance ? self::CHANGE_ADVANCE : self::CHANGE_POSTPONEMENT;
	}

	/**
	 * Procesa una orden afectada por un cambio accionable.
	 *
	 * Orden del flujo ( — el save único CUBRE el recompute aun con
	 * signature deduplicada):
	 *   1. Gates de orden (has_preventa / processing / ready=no) intactos.
	 *   2. Recompute SIEMPRE (idempotente; estado ≠ email).
	 *   3. Dedup por signature: decide SOLO evento/note/action, jamás la
	 *      persistencia del recompute.
	 *   4. UN solo `save()` condicional (`$changed || $is_new_sig`) — replay
	 *      idempotente puro = cero saves.
	 *   5. Action según la matriz, solo con signature nueva; `announced`
	 *      además gateado a max vigente ().
	 *
	 * @param WC_Order $order      Orden candidata.
	 * @param int      $product_id Producto que cambió.
	 * @param string   $old        Y-m-d previo.
	 * @param string   $new        Y-m-d nuevo.
	 * @param string   $signature  `old__new`.
	 * @param string   $change     Clasificación CHANGE_*.
	 * @return void
	 */
	private function process_order( WC_Order $order, int $product_id, string $old, string $new, string $signature, string $change ): void {
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return;
		}
		if ( ! $order->has_status( FulfillmentCapabilities::recompute_order_statuses( $order ) ) ) {
			return;
		}
		if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
			return;
		}
		if ( ! PreventaItems::has_outstanding_items( $order ) ) {
			return;
		}

		// Recalcular es para todos los que esperan el tomo, pagado o no; avisar
		// por correo, sólo a quien ya pagó (`waiting_order_statuses`). El que
		// está en espera de pago ve la fecha nueva en su pedido y en el correo
		// de confirmación cuando el pago entre, y aquí queda dicho por qué no
		// se le escribió.
		$puede_avisarse = $order->has_status( FulfillmentCapabilities::waiting_order_statuses( $order ) );

		$changed = $this->recalculator->recompute_for_order( $order );

		$sigs_csv   = (string) $order->get_meta( MetaKeys::ORDER_DATE_CHANGE_SIGS );
		$sigs       = '' === $sigs_csv ? array() : explode( ',', $sigs_csv );
		$is_new_sig = ! in_array( $signature, $sigs, true );

		$announce_ok = true;

		if ( $is_new_sig ) {
			$sigs[] = $signature;
			$order->update_meta_data( MetaKeys::ORDER_DATE_CHANGE_SIGS, implode( ',', $sigs ) );
			// Traza interna SIEMPRE (nunca silenciar un evento). El resultado
			// del envío al cliente (enviado / capado por ventana) lo agrega
			// ReminderEmail en el punto de envío efectivo — CUT-7 Fase 3.
			$order->add_order_note( $this->event_note( $change, $product_id, $old, $new ) );

			if ( self::CHANGE_ANNOUNCED === $change ) {
				// announced sólo puede enviarse si el recálculo produjo max vigente.
				// El correo no debe anunciar una fecha ausente de las superficies del pedido.

				$announce_ok = null !== SummaryRecalculator::current_max_ts( $order );
				if ( ! $announce_ok ) {
					$order->add_order_note(
						sprintf(
							'Fecha anunciada NO notificada al cliente: el producto #%d no tiene ETA calculable (falta pa_pais / lead time).',
							$product_id
						)
					);
				}
			}
		}

		// Sin ETA calculable tras el recálculo no hay «fecha actualizada» que el
		// cliente pueda ver: la nota de «no tiene ETA calculable» ya lo dijo.
		if ( $is_new_sig && ! $puede_avisarse && self::CHANGE_RETRACTED !== $change && $announce_ok ) {
			$order->add_order_note( 'Cambio de fecha NO notificado al cliente: el pedido está en espera de pago. Su fecha estimada quedó actualizada y la verá en su pedido y en el correo de confirmación cuando el pago se confirme.' );
		}

		if ( $changed || $is_new_sig ) {
			$order->save();
		}

		if ( ! $is_new_sig || ! $puede_avisarse ) {
			return;
		}

		if ( self::CHANGE_POSTPONEMENT === $change || self::CHANGE_ADVANCE === $change ) {
			/**
			 * Fires cuando una orden preventa debe recibir notificación de
			 * cambio de fecha (postergación, o adelanto con toggle ON) de un
			 * producto que contiene.
			 *
			 * Hook consumido por `ReminderEmail::trigger_date_changed()`.
			 *
			 * @since 1.19.0
			 *
			 * @param int    $order_id   ID de la orden afectada.
			 * @param int    $product_id ID del producto cuyo release_date cambió.
			 * @param string $old        Y-m-d previo.
			 * @param string $new        Y-m-d nuevo.
			 */
			do_action( 'akb_order_preventa_date_changed', $order->get_id(), $product_id, $old, $new );
			return;
		}

		if ( self::CHANGE_ANNOUNCED === $change && $announce_ok ) {
			/**
			 * Fires cuando una orden preventa comprada TBA recibe fecha
			 * anunciada con ETA calculable (buena noticia).
			 *
			 * Hook consumido por `ReminderEmail::trigger_date_announced()`.
			 *
			 * @since 1.79.0
			 *
			 * @param int    $order_id   ID de la orden afectada.
			 * @param int    $product_id ID del producto que anunció fecha.
			 * @param string $old        Siempre '' en este evento.
			 * @param string $new        Y-m-d anunciado.
			 */
			do_action( 'akb_order_preventa_date_announced', $order->get_id(), $product_id, $old, $new );
		}
		// `retracted`: sin action — recompute on-site + note, sin email (LOCKED #4).
	}

	/**
	 * Order-note de traza por evento.
	 *
	 * El copy de `retracted` es NEUTRO a propósito (): `fecha→''`
	 * ocurre al pasar a `tba` pero TAMBIÉN a `auto`/`disabled` (sanitize_post
	 * anula release_date para cualquier tipo distinto) — no afirmamos "vuelve
	 * a por-anunciar" porque en 2 de 3 transiciones sería falso.
	 *
	 * @param string $change     Clasificación CHANGE_*.
	 * @param int    $product_id Producto.
	 * @param string $old        Y-m-d previo.
	 * @param string $new        Y-m-d nuevo.
	 * @return string
	 */
	private function event_note( string $change, int $product_id, string $old, string $new ): string {
		if ( self::CHANGE_ANNOUNCED === $change ) {
			return sprintf( 'Fecha anunciada · producto #%d: %s.', $product_id, $new );
		}
		if ( self::CHANGE_RETRACTED === $change ) {
			return sprintf( 'Fecha retirada · producto #%d ya no tiene fecha de salida vigente.', $product_id );
		}
		if ( self::CHANGE_ADVANCE === $change ) {
			return sprintf( 'Adelanto registrado · producto #%d: %s → %s.', $product_id, $old, $new );
		}

		return sprintf(
			'Postergación registrada · producto #%d: %s → %s.',
			$product_id,
			'' === $old ? '—' : $old,
			'' === $new ? '—' : $new
		);
	}

	/**
	 * Devuelve los argumentos para paginar órdenes preventa no listas en estado de espera.
	 *
	 * La pertenencia al producto se confirma en `order_contains_preventa_product()`
	 * porque los line items no son consultables mediante wc_get_orders(). El
	 * paginador recorre todas las páginas iniciales, no sólo los primeros 200.
	 *
	 * @return array<string, mixed>
	 */
	private function affected_order_query_args(): array {
		return array(
				'limit'      => self::BATCH_SIZE,
				// Se RECALCULA también al pedido en espera de pago (`on-hold`);
				// escribirle sigue gateado más abajo por los estados de espera.
				'status'     => FulfillmentCapabilities::recompute_order_statuses(),
				// El pedido sin meta de listo es candidato legítimo. Sólo el literal yes
				// lo excluye, también para pedidos manuales, API o importados.

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
				'order'      => 'DESC',
		);
	}

	/**
	 * @param WC_Order $order      Orden candidata.
	 * @param int      $product_id Producto cuyo release date cambió.
	 * @return bool
	 */
	private function order_contains_preventa_product( WC_Order $order, int $product_id ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( (int) $item->get_product_id() !== $product_id ) {
				continue;
			}
			// Sólo si el tomo sigue SIN LLEGAR: a quien ya lo tiene en bodega
			// (o el pedido ya está listo) no le afecta la fecha de la editorial,
			// y mandarle «nueva fecha de llegada» sería mentirle.
			if ( PreventaItems::is_pending_item( $order, $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Medianoche de hoy en la zona de la tienda. Se compara contra el mediodía
	 * UTC de la fecha (`ymd_to_ts`): el propio día de la salida nunca cuenta
	 * como pasado.
	 *
	 * @return int
	 */
	private function today_start(): int {
		return StoreTime::start_of_today( (int) ( $this->now )() );
	}

	/**
	 * Valida `Y-m-d` estricto.
	 *
	 * @param string $ymd
	 * @return bool
	 */
	private static function valid_ymd( string $ymd ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return false;
		}
		$parts = explode( '-', $ymd );
		return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
	}

	/**
	 * Convierte `Y-m-d` a timestamp UTC mediodía (evita off-by-one por DST
	 * en cruce de medianoche).
	 *
	 * @param string $ymd
	 * @return int
	 */
	private static function ymd_to_ts( string $ymd ): int {
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $ymd . ' 12:00:00' );
		return false === $dt ? 0 : $dt->getTimestamp();
	}
}
