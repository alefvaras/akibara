<?php
/**
 * Constantes centralizadas de meta keys para OrderPreventa.
 *
 * Convención: namespace `_akb_eta_*_at_purchase` para snapshot en line item
 * (alinea con el namespace del módulo EstimatedDelivery que produce el ETA),
 * y `_akb_eta_*` (sin `_at_purchase`) para meta agregada en order.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

defined( 'ABSPATH' ) || exit;

/**
 * Contenedor de constants. No instanciable.
 *
 * @since 1.14.0
 */
final class MetaKeys {

	// -----------------------------------------------------------------------
	// Line item meta (snapshot al checkout, immutable).
	// -----------------------------------------------------------------------

	/**
	 * Flag yes: este line item se compró estando el producto en
	 * stock_status=onbackorder. Está ausente en items normales para no añadir
	 * metadata a cada compra sin preventa.
	 */
	public const ITEM_WAS_PREVENTA = '_akb_eta_was_preventa';

	/**
	 * Tipo de preventa al momento de la compra: auto | release_date | tba | disabled.
	 * Solo presente cuando ITEM_WAS_PREVENTA = yes.
	 */
	public const ITEM_TYPE_AT_PURCHASE = '_akb_eta_type_at_purchase';

	/**
	 * Timestamp ETA mínimo capturado. Solo presente cuando hay rango concreto
	 * (no TBA y no disabled).
	 */
	public const ITEM_MIN_AT_PURCHASE = '_akb_eta_min_at_purchase';

	/**
	 * Timestamp ETA máximo capturado. Solo presente cuando hay rango concreto.
	 */
	public const ITEM_MAX_AT_PURCHASE = '_akb_eta_max_at_purchase';

	/**
	 * Slug del término `pa_pais` del producto al momento de la compra
	 * (`argentina`, `espana`,...). Solo presente cuando ITEM_WAS_PREVENTA = yes
	 * y el producto tenía pa_pais asignado.
	 *
	 * Desde 1.14.0 (refactor pa_pais): el valor cambió de código ISO ('AR'/'ES')
	 * a slug del término. El display name se persiste por separado en
	 * ITEM_COUNTRY_NAME_AT_PURCHASE para resistir rename del término.
	 */
	public const ITEM_COUNTRY_AT_PURCHASE = '_akb_eta_country_at_purchase';

	/**
	 * Display name del país (`Argentina`, `España`) al momento de la compra.
	 * Snapshot inmutable — si owner renombra el término después, este meta
	 * preserva el nombre histórico para órdenes ya emitidas.
	 */
	public const ITEM_COUNTRY_NAME_AT_PURCHASE = '_akb_eta_country_name_at_purchase';

	/**
	 * Flag yes/no: el ETA capturado era TBA (con o sin lead time). Solo presente
	 * cuando ITEM_WAS_PREVENTA = yes.
	 */
	public const ITEM_IS_TBA_AT_PURCHASE = '_akb_eta_is_tba_at_purchase';

	/**
	 * La promesa VIGENTE de esta línea (min/max), escrita por SummaryRecalculator
	 * cada vez que la calcula en vivo (tomo con fecha de salida futura). Es lo
	 * que la línea le muestra al cliente y lo que se conserva cuando la fecha
	 * de salida vence sin que el tomo llegue. Sin ella, la línea seguía
	 * imprimiendo el rango de COMPRA dos renglones encima de un cartel con la
	 * fecha postergada, y una línea vencida heredaba la promesa de OTRO tomo.
	 * Ausente = la promesa de compra sigue vigente.
	 *
	 * @since 1.114.16
	 */
	public const ITEM_MIN_CURRENT = '_akb_eta_min_current';
	public const ITEM_MAX_CURRENT = '_akb_eta_max_current';

	/**
	 * Flag yes: este line item ya fue recibido en bodega y apartado físicamente
	 * para este pedido. Inmutable para esta orden: protege la línea contra
	 * des-marcados si el producto en el catálogo vuelve a onbackorder u outofstock.
	 *
	 * @since 1.117.0
	 */
	public const ITEM_ARRIVED = '_akb_eta_item_arrived';

	/**
	 * Timestamp UNIX de cuándo se apartó físicamente en bodega para este pedido.
	 *
	 * @since 1.117.0
	 */
	public const ITEM_ARRIVED_AT = '_akb_eta_item_arrived_at';

	/**
	 * Cantidad de unidades físicas ya apartadas en bodega para este line item.
	 * Soporta entregas parciales en líneas multicopia (ej. compró 3, llegaron 2).
	 *
	 * @since 1.117.0
	 */
	public const ITEM_ARRIVED_QTY = '_akb_eta_item_arrived_qty';

	// -----------------------------------------------------------------------
	// Order meta (denormalizado para query rápida y UI admin).
	// -----------------------------------------------------------------------

	/**
	 * Flag yes/no: la orden contiene al menos un line item con
	 * ITEM_WAS_PREVENTA = yes. El valor conserva la idempotencia del
	 * backfill histórico; los demás derivados sólo existen para preventa.
	 */
	public const ORDER_HAS_PREVENTA = '_akb_eta_has_preventa';

	/**
	 * Flag yes/no: todos los productos preventa de la orden ya pasaron a
	 * stock_status=instock. Lo flippea el cron diario.
	 */
	public const ORDER_READY_TO_SHIP = '_akb_eta_ready_to_ship';

	/**
	 * Timestamp en que se flippeó ORDER_READY_TO_SHIP a yes. Lo escribe el cron.
	 */
	public const ORDER_READY_DATE = '_akb_eta_ready_date';

	/**
	 * Timestamp en que el pedido entró POR PRIMERA VEZ en un estado despachable.
	 *
	 * No es lo mismo que ORDER_READY_DATE, y la diferencia importa: aquélla es
	 * cuando el CRON vio todos los items de vuelta en stock; ésta es cuando el
	 * pedido se pudo despachar de hecho. En el pedido 24483 el dueño lo pasó a
	 * «Listo para enviar» 39 días ANTES de que el cron lo detectara, así que
	 * medir el cumplimiento sólo con la señal del cron lo acusaba de 32 días de
	 * atraso cuando el cliente lo había recibido en plazo.
	 *
	 * Se escribe UNA sola vez (primer cruce): un pedido que va y vuelve de estado
	 * no puede reescribir su propia historia. Existe desde 1.108.22, así que los
	 * pedidos anteriores no la tienen y el informe de cumplimiento los marca.
	 */
	public const ORDER_SHIPPABLE_AT = '_akb_eta_shippable_at';

	/**
	 * Estimado VIGENTE del último arribo de la orden (timestamp). Se escribe al
	 * checkout y se RECOMPUTA ante cambios accionables de release_date
	 * (postergación/adelanto/anuncio/retractación). AUSENTE ⇔ sin fecha
	 * estimable — nunca se escribe 0 (delete-vs-zero). Fuente única de
	 * escritura y lector canónico: `SummaryRecalculator`. Solo presente si
	 * ORDER_HAS_PREVENTA = yes.
	 */
	public const ORDER_SUMMARY_MAX = '_akb_eta_summary_max';

	/**
	 * Cuántos line items con ITEM_WAS_PREVENTA = yes siguen onbackorder hoy.
	 * Actualizado por el cron en cada run.
	 */
	public const ORDER_PENDING_COUNT = '_akb_eta_pending_count';

	/**
	 * Por qué se dejó la nota interna «revisar manualmente»: `promesa` (la
	 * fecha prometida al cliente venció) o `tope` (demasiado tiempo desde la
	 * primera promesa / sin fecha). Una nota por `tope` no tapa la de `promesa`.
	 * `yes` es el valor heredado (cuenta como `promesa`). La borra
	 * `SummaryRecalculator` cuando la promesa cambia.
	 *
	 * @since 1.114.12 Antes constante privada de ReadyToShipCron.
	 * @since 1.114.16 Guarda el motivo, no sólo `yes`.
	 */
	public const ORDER_STALE_NOTED = '_akb_eta_stale_noted';

	/**
	 * Flag yes: ya quedó la nota interna diciendo que este pedido cruzó el
	 * plazo de reembolso publicado (`Settings::REFUND_AFTER_DAYS`) sobre su
	 * fecha de llegada prometida — el cliente ya tiene derecho a cancelar con
	 * reembolso del 100%. Independiente de `ORDER_STALE_NOTED`: esa otra nota
	 * es un recordatorio operativo (revisar a mano) con SU PROPIO plazo
	 * editable; ésta es la señal de un derecho contractual con un plazo fijo,
	 * y una vez dejada no se repite ni se retira aunque la promesa se vuelva a
	 * mover. La escribe `ReadyToShipCron::maybe_mark_refund_eligible()`.
	 *
	 * @since 1.116.27
	 */
	public const ORDER_REFUND_ELIGIBLE_NOTED = '_akb_eta_refund_eligible_noted';

	/**
	 * Timestamp del envío del reminder T-7d. Presente solo cuando ya se mandó.
	 * Idempotencia: el cron weekly chequea NOT EXISTS para evitar duplicados.
	 *
	 * @since 1.19.0
	 */
	public const ORDER_T7_REMINDER_SENT = '_akb_eta_t7_reminder_sent';

	/**
	 * Cuenta los rechazos de transporte del recordatorio T-7d.
	 *
	 * La marca de enviado se escribe antes de send() para evitar doble dispatch.
	 * Si el transporte rechaza el mensaje se revierte esa marca y aumenta este
	 * contador. Al alcanzar el tope no se rearma contra un proveedor que sigue
	 * fallando.
	 *
	 * @since 1.99.0
	 */
	public const ORDER_T7_REMINDER_ATTEMPTS = '_akb_eta_t7_reminder_attempts';

	/**
	 * Timestamp del envío del email ready-to-ship. Presente solo cuando ya se
	 * mandó. Idempotencia: worker AS y cron diario pueden procesar la misma
	 * orden en paralelo (process_order es read-modify-write sin lock); sin
	 * este guard el cliente recibe el email duplicado.
	 *
	 * @since 1.47.3
	 */
	public const ORDER_READY_EMAIL_SENT = '_akb_eta_ready_email_sent';

	/**
	 * Cuántas veces el transporte rechazó el email ready-to-ship de esta orden.
	 * Espejo del contrato de ORDER_T7_REMINDER_ATTEMPTS: al revertir el marker
	 * ante un `send()` en false, la orden vuelve al conjunto elegible de
	 * `OrderPreventa::maybe_resend_ready_email()` y este contador acota los
	 * re-armados.
	 *
	 * @since 1.99.0
	 */
	public const ORDER_READY_EMAIL_ATTEMPTS = '_akb_eta_ready_email_attempts';

	/**
	 * Cuenta los fallos del worker anteriores a la cuenta del propio correo.
	 *
	 * Un error al iniciar el mailer no incrementa ORDER_READY_EMAIL_ATTEMPTS.
	 * Este contador limita esos reintentos con el mismo tope de SendFailureRearm;
	 * el reenvío manual reinicia el presupuesto.
	 *
	 * @since 1.116.6
	 */
	public const ORDER_READY_EMAIL_WORKER_FAILURES = '_akb_eta_ready_email_worker_failures';

	/**
	 * Flag `yes`: ya quedó la nota «aviso "ya llegó" NO enviado porque el
	 * pedido ya había salido». Sin ella, marcar el pedido como entregado al
	 * transportista antes del tick que despachaba el correo lo apagaba sin
	 * rastro: Action Scheduler daba la acción por completada y nadie sabía que
	 * el cliente no se enteró. Una nota por pedido; el reenvío manual la borra
	 * para que un reenvío que vuelva a chocar con el estado también quede dicho.
	 *
	 * @since 1.116.9
	 */
	public const ORDER_READY_EMAIL_DEPARTED_NOTED = '_akb_eta_ready_email_departed_noted';

	/*
	 * ORDER_SHIPPED_EMAIL_SENT (`_akb_eta_shipped_email_sent`) se retiró en CUT-7
	 * Fase 2 () junto con `ShippedEmail`. La meta pudo quedar escrita en
	 * las pocas órdenes que llegaron a `completed`; es inerte (ningún lector) y no
	 * requiere migración.
	 */

	/**
	 * Flag yes/no: ya se agregó la alerta operativa porque un carrier no agregó
	 * tracking dentro de la ventana esperada después de un handoff declarado por
	 * su adaptador de fulfillment.
	 *
	 * @since 1.87.0
	 */
	public const ORDER_CARRIER_HANDOFF_ALERTED = '_akb_eta_carrier_handoff_alerted';

	/**
	 * CSV de signatures `old__new` (doble underscore — la notación previa
	 * `oldYmd→newYmd` de este docblock era errónea) de cambios de release_date
	 * ya registrados. Dedup por signature: si el admin posterga 2 veces a
	 * fechas distintas se registran 2 eventos, pero el mismo cambio nunca se
	 * repite. Desde Rev 2.1 también registra `__fecha` (announced) y
	 * `fecha__` (retracted, solo traza — sin email).
	 *
	 * @since 1.19.0
	 */
	public const ORDER_DATE_CHANGE_SIGS = '_akb_eta_date_change_sigs';

	/**
	 * Epoch (int, UTC) del último email de postergación efectivamente enviado al
	 * cliente. Lo escribe `ReminderEmail::trigger_date_changed` EN EL PUNTO DE
	 * ENVÍO (después de los guards enabled/recipient/order_passes_guards), NUNCA
	 * el listener — el email es asíncrono y marcarlo antes suprimiría un aviso
	 * real sin que el cliente reciba nada (Ley 21.719).
	 *
	 * Base del cap por FRECUENCIA (CUT-7 Fase 3): no se re-notifica al cliente
	 * más de 1 vez por ventana (`OPT_DATE_CHANGE_NOTIFY_WINDOW_DAYS`, default
	 * 14d). Ausente (= 0) => primer aviso siempre pasa.
	 *
	 * Cap EXCLUSIVO del modo `date_changed` (mala noticia): jamás se escribe
	 * desde `date_announced` — una buena noticia no debe suprimir una
	 * postergación posterior. Cross-ref: ORDER_DATE_ANNOUNCED_LAST_NOTIFIED_AT.
	 *
	 * @since 1.64.8
	 */
	public const ORDER_DATE_CHANGE_LAST_NOTIFIED_AT = '_akb_eta_date_change_last_notified_at';

	/**
	 * Intentos fallidos del email de postergación. Necesario porque la marca de
	 * arriba es TAMBIÉN el cap de frecuencia: un envío que el transporte rechaza
	 * quemaba la ventana, de modo que una postergación posterior dentro de esos
	 * días se suprimía con nota sin que el cliente hubiera recibido jamás el
	 * primer aviso — exactamente lo que la ventana quiere evitar (Ley 21.719).
	 * Al revertir la marca se libera la ventana; este contador acota el ciclo.
	 *
	 * @since 1.99.0
	 */
	public const ORDER_DATE_CHANGE_SEND_ATTEMPTS = '_akb_eta_date_change_send_attempts';

	/**
	 * Epoch (int, UTC) del último email de "fecha anunciada" (TBA→fecha)
	 * efectivamente enviado al cliente. Lo escribe
	 * `ReminderEmail::trigger_date_announced` EN EL PUNTO DE ENVÍO, espejo del
	 * contrato de ORDER_DATE_CHANGE_LAST_NOTIFIED_AT.
	 *
	 * Cap asimétrico por diseño: el trigger de announced LEE el max() de AMBAS
	 * claves contra la ventana (la buena noticia cede ante CUALQUIER email
	 * reciente) pero ESCRIBE solo esta — así una postergación (material bajo
	 * Ley 21.719, con opción de cancelar) nunca queda suprimida por un anuncio
	 * previo. Ausente (= 0) => primer aviso pasa. Sin migración.
	 *
	 * @since 1.79.0
	 */
	public const ORDER_DATE_ANNOUNCED_LAST_NOTIFIED_AT = '_akb_eta_date_announced_last_notified_at';

	/**
	 * Intentos fallidos del email de "fecha anunciada". Espejo del contrato de
	 * ORDER_DATE_CHANGE_SEND_ATTEMPTS, sobre la marca de announced.
	 *
	 * @since 1.99.0
	 */
	public const ORDER_DATE_ANNOUNCED_SEND_ATTEMPTS = '_akb_eta_date_announced_send_attempts';

	private function __construct() {}
}
