<?php
/**
 * Agregación denormalizada a nivel order de "tiene preventa / esperando / lista".
 *
 * Lee los line items recién snapeados por LineItemSnapshot y escribe los metas
 * agregados que la UI admin consume para columna + filtro.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\Stock\StockStatus;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Calcula y persiste los metas agregados del order a partir de los line items
 * ya snapeados por LineItemSnapshot.
 *
 * Cuatro superficies, un cálculo:
 *   - Checkout clásico: `woocommerce_checkout_create_order` (orden en memoria,
 *     WC la guarda después → sin save() propio).
 *   - Store API draft: `woocommerce_store_api_checkout_update_order_meta`
 *     reconcilia metas si el cliente modifica el carrito antes de pagar.
 *   - Checkout Blocks / Store API: `woocommerce_store_api_checkout_order_processed`
 *     conserva un último guard idempotente antes del pago.
 *   - CUALQUIER otra vía (`woocommerce_before_order_object_save`, ver
 *     `maybe_tag_order_created_outside_checkout`): admin manual, REST,
 *     importación — ninguna de esas pasa por los tres hooks de arriba
 *.
 *
 * `apply_tags` es idempotente por recomputación, así que un re-disparo del hook de Store API
 * (p. ej. ruta "pagar orden existente" o reintento de pago) deja los metas estables.
 *
 * @since 1.14.0
 */
final class OrderTagging {

	private SummaryRecalculator $recalculator;

	/** Dueño único de qué metas lleva una línea de preventa (ver stamp_preventa_details). */
	private LineItemSnapshot $line_item_snapshot;

	/**
	 * @param SummaryRecalculator $recalculator       Fuente única del summary_max.
	 * @param LineItemSnapshot    $line_item_snapshot  Snapshot de línea, reusado por el etiquetado perezoso.
	 */
	public function __construct( SummaryRecalculator $recalculator, LineItemSnapshot $line_item_snapshot ) {
		$this->recalculator       = $recalculator;
		$this->line_item_snapshot = $line_item_snapshot;
	}

	public function register(): void {
		add_action(
			'woocommerce_checkout_create_order',
			array( $this, 'on_create_order' ),
			20,
			1
		);
		add_action(
			'woocommerce_store_api_checkout_update_order_meta',
			array( $this, 'on_store_api_order_meta_updated' ),
			20,
			1
		);
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			array( $this, 'on_store_api_order_processed' ),
			20,
			1
		);
		// El guardado genérico de WC_Abstract_Order cubre altas manuales, REST e
		// importación que no pasan por checkout. El etiquetado en ese hook es perezoso
		// e idempotente y funciona tanto con HPOS como con CPT.

		add_action(
			'woocommerce_before_order_object_save',
			array( $this, 'maybe_tag_order_created_outside_checkout' ),
			20,
			1
		);
	}

	/**
	 * Checkout clásico: la orden aún no se persiste, WC la guarda tras el hook.
	 *
	 * @param WC_Order $order Orden recién armada (aún no persistida).
	 * @return void
	 */
	public function on_create_order( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->apply_tags( $order );
	}

	/**
	 * Store API: reconcilia un draft cuando el carrito cambia antes de pagar.
	 *
	 * @param mixed $order Orden draft actualizada por Store API.
	 * @return void
	 */
	public function on_store_api_order_meta_updated( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->apply_tags( $order );
		$order->save();
	}

	/**
	 * Checkout Blocks / Store API: la orden ya está persistida cuando corre el
	 * hook, así que hay que guardar explícitamente los metas recién pintados.
	 *
	 * @param mixed $order Orden Store API ya procesada (WC_Order).
	 * @return void
	 *
	 * @since 1.71.0
	 */
	public function on_store_api_order_processed( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->apply_tags( $order );
		$order->save();
	}

	/**
	 * Etiquetado PEREZOSO e IDEMPOTENTE para pedidos que NUNCA pasaron por
	 * checkout: alta manual en wp-admin, REST API, importación.
	 *
	 * Colgado de `woocommerce_before_order_object_save`, que dispara
	 * `WC_Abstract_Order::save()` en TODO pedido —clásico, Store API, admin,
	 * REST— antes de persistir props/meta y de guardar los items
	 * (`save_items()`, más abajo en el mismo `save()`). Por eso esta función
	 * NUNCA llama `$order->save()` ni `$item->save()`: sólo encola
	 * `update_meta_data()` sobre objetos que el propio `save()` en curso va a
	 * persistir dos líneas después. Un `save()` explícito aquí reentraría en
	 * `WC_Abstract_Order::save()` desde dentro de su propio hook `before_*`.
	 *
	 * Guard barato PRIMERO — un solo `get_meta()`, sin iterar items — para no
	 * costar nada en el camino normal: un pedido que ya pasó por caja (o que ya
	 * procesó esta misma función en un guardado anterior) siempre tiene
	 * `ORDER_HAS_PREVENTA` escrito (`apply_tags()` lo deja en 'yes' o 'no', nunca
	 * ausente), así que el 99% de los guardados de la tienda —cualquier orden ya
	 * taggeada— retorna en la primera línea.
	 *
	 * Sólo el resto —pedidos que NUNCA pasaron por `apply_tags()`— paga la
	 * vuelta completa: por línea reservable sin snapshot, se estampa con el
	 * estado ACTUAL del producto (es lo mejor que se sabe para un pedido que no
	 * pasó por la caja) y se dice en una nota de pedido. Un pedido con líneas
	 * pero ninguna reservable ahora mismo también queda resuelto: `apply_tags()`
	 * corre igual y deja `ORDER_HAS_PREVENTA='no'`, así el guard de arriba no
	 * lo vuelve a escanear en cada guardado futuro.
	 *
	 * @param mixed $order Orden en curso de guardarse.
	 * @return void
	 */
	public function maybe_tag_order_created_outside_checkout( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return;
		}

		// Un pedido cancelado/reembolsado/fallido/en la papelera no tiene avisos
		// futuros que recibir: etiquetarlo ahora no sirve y deja una nota de
		// pedido sobre algo que ya cerró su ciclo. Misma lista que audita
		// PromiseWindow — una sola fuente de "esto ya no tiene promesa viva".
		if ( in_array( $order->get_status(), PromiseWindow::ESTADOS_SIN_PROMESA, true ) ) {
			return;
		}

		// No reetiquetar pedidos que ya salieron o terminaron al guardar una nota,
		// seguimiento o reembolso: su snapshot no debe adoptar el estado actual del tomo.
		// Una integración que cree un pedido ya completado en un único guardado también
		// queda excluida. El backfill histórico es un camino explícito separado.

		if ( $order->has_status( FulfillmentCapabilities::departed_order_statuses( $order ) ) ) {
			return;
		}

		$items = $order->get_items();
		if ( array() === $items ) {
			// P. ej. el auto-draft que wp-admin guarda ANTES de que el dueño añada
			// el primer producto (`PageController::setup_action_new_order` de
			// WooCommerce). No hay nada que snapshotear todavía; a propósito NO se
			// marca `has_preventa=no` acá, para que el próximo guardado —ya con
			// líneas— vuelva a intentarlo.
			return;
		}

		$tagged_any = false;

		foreach ( $items as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( '' !== (string) $item->get_meta( MetaKeys::ITEM_WAS_PREVENTA ) ) {
				// Ya decidido — por caja, por el back-fill histórico, o por un
				// guardado anterior de esta misma función. No se reescribe un
				// snapshot existente.
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof WC_Product || ! StockStatus::is_reservable( $product ) ) {
				continue;
			}

			$item->update_meta_data( MetaKeys::ITEM_WAS_PREVENTA, 'yes' );
			$this->line_item_snapshot->stamp_preventa_details( $item, $product );
			$tagged_any = true;
		}

		// Corre SIEMPRE, aunque no se haya taggeado ninguna línea: es lo que deja
		// `ORDER_HAS_PREVENTA` definitivo ('yes' o 'no') y cierra el guard de
		// arriba para los próximos guardados de este mismo pedido.
		$this->apply_tags( $order );

		if ( $tagged_any ) {
			$order->add_order_note(
				'Pedido creado fuera de la caja: la fecha estimada se tomó hoy, no al comprar.'
			);
		}
	}

	/**
	 * Itera line items y pinta los metas agregados. Idempotente: recalcula desde
	 * los line items y sobrescribe, sin incrementar.
	 *
	 * `ORDER_READY_TO_SHIP` se pinta 'no' solo si aún no existe, para que un
	 * re-disparo tardío del hook de Store API no pise un 'yes' ya volteado por el
	 * cron ready-to-ship. En una orden recién nacida el meta no existe → se setea
	 * igual que en el clásico.
	 *
	 * `ORDER_SUMMARY_MAX` se delega a `SummaryRecalculator::recompute_for_order`
	 * (): resultado idéntico al viejo max() de snapshots en el checkout
	 * recién snapeado (misma ETA, mismo reloj), pero con la semántica
	 * delete-vs-zero uniforme en TODOS los disparos — incluido el re-disparo
	 * tardío de Store API ("pagar orden existente" días después): si el
	 * producto mutó a `tba` entre checkout y pago, el meta se BORRA en vez de
	 * conservar el valor stale del primer intento ().
	 *
	 * @param WC_Order $order Orden con line items ya snapeados.
	 * @return void
	 *
	 * @since 1.71.0
	 */
	private function apply_tags( WC_Order $order ): void {
		$pending_count = 0;
		$instock_count = 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$qty = max( 1, (int) $item->get_quantity() );
			if ( 'yes' === (string) $item->get_meta( MetaKeys::ITEM_WAS_PREVENTA ) ) {
				$pending_count += $qty;
			} else {
				$instock_count += $qty;
			}
		}

		if ( $pending_count < 1 ) {
			$order->delete_meta_data( '_akb_mixed_consolidation_required' );
			$this->clear_order_preventa_meta( $order );
			return;
		}

		$order->update_meta_data( MetaKeys::ORDER_HAS_PREVENTA, 'yes' );

		// Alerta y metadato de consolidación obligatoria en bodega para pedidos mixtos:
		if ( $instock_count > 0 ) {
			$order->update_meta_data( '_akb_mixed_consolidation_required', 'yes' );
			$order->update_meta_data( '_akb_mixed_instock_count', $instock_count );
			$order->update_meta_data( '_akb_mixed_pending_count', $pending_count );

			if (
				'' === (string) $order->get_meta( '_akb_mixed_consolidation_note_added' )
				&& method_exists( $order, 'add_order_note' )
				&& ( ! method_exists( $order, 'get_id' ) || (int) $order->get_id() > 0 )
				&& ( ! method_exists( $order, 'get_status' ) || 'checkout-draft' !== (string) $order->get_status() )
			) {
				$order->update_meta_data( '_akb_mixed_consolidation_note_added', '1' );
				$order->add_order_note(
					sprintf(
						'📦 CONSOLIDACIÓN OBLIGATORIA EN BODEGA: Este pedido combina %d tomo(s) en stock físico con %d tomo(s) A pedido. Apartar físicamente los %d tomo(s) hoy mismo en la caja de «Reservas en Espera de Consolidación» hasta que arriben los tomos importados.',
						$instock_count,
						$pending_count,
						$instock_count
					)
				);
			}
		} else {
			$order->delete_meta_data( '_akb_mixed_consolidation_required' );
		}

		if ( '' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
			$order->update_meta_data( MetaKeys::ORDER_READY_TO_SHIP, 'no' );
		}
		$order->update_meta_data( MetaKeys::ORDER_PENDING_COUNT, $pending_count );

		$this->recalculator->recompute_for_order( $order );
	}

	/**
	 * Borra los derivados de preventa cuando un draft ya no contiene líneas
	 * preventa. Conserva el marcador `has_preventa=no` para que el backfill
	 * histórico no vuelva a escanear la orden en futuras ejecuciones.
	 *
	 * @param WC_Order $order Orden a normalizar.
	 * @return void
	 */
	private function clear_order_preventa_meta( WC_Order $order ): void {
		$order->update_meta_data( MetaKeys::ORDER_HAS_PREVENTA, 'no' );

		foreach (
			array(
				MetaKeys::ORDER_READY_TO_SHIP,
				MetaKeys::ORDER_READY_DATE,
				MetaKeys::ORDER_SUMMARY_MAX,
				MetaKeys::ORDER_PENDING_COUNT,
				MetaKeys::ORDER_T7_REMINDER_SENT,
				MetaKeys::ORDER_T7_REMINDER_ATTEMPTS,
				MetaKeys::ORDER_READY_EMAIL_SENT,
				MetaKeys::ORDER_READY_EMAIL_ATTEMPTS,
				MetaKeys::ORDER_READY_EMAIL_WORKER_FAILURES,
				MetaKeys::ORDER_READY_EMAIL_DEPARTED_NOTED,
				MetaKeys::ORDER_CARRIER_HANDOFF_ALERTED,
				MetaKeys::ORDER_DATE_CHANGE_SIGS,
				MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT,
				MetaKeys::ORDER_DATE_CHANGE_SEND_ATTEMPTS,
				MetaKeys::ORDER_DATE_ANNOUNCED_LAST_NOTIFIED_AT,
				MetaKeys::ORDER_DATE_ANNOUNCED_SEND_ATTEMPTS,
			) as $meta_key
		) {
			$order->delete_meta_data( $meta_key );
		}
	}
}
