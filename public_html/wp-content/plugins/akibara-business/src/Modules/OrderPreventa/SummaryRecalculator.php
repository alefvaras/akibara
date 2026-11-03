<?php
/**
 * Fuente canónica de ORDER_SUMMARY_MAX, el estimado vigente del último arribo.
 *
 * El valor es recomputable y borrable: ausencia no equivale a un timestamp cero.
 * OrderTagging, WpCli y ReleaseDateChangeListener delegan su cálculo aquí; los
 * lectores usan current_max_ts() para interpretar la misma semántica.
 * La clase permite spies en pruebas; los tests de listener pueden conservar
 * el recalculador real con un EtaProvider inyectado por interfaz.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Modules\Stock\Availability;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use Akibara\Business\Contracts\EtaProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Recalculador del estimado vigente de arribo de una orden preventa.
 *
 * @since 1.79.0
 */
class SummaryRecalculator {

	private EtaProvider $calculator;

	/**
	 * @param EtaProvider $calculator Provider de ETA (DateCalculator).
	 */
	public function __construct( EtaProvider $calculator ) {
		$this->calculator = $calculator;
	}

	/**
	 * Max vigente de la orden (0 = sin fecha estimable).
	 *
	 * Contribución por línea PENDIENTE (PreventaItems::pending_items), decidida por el
	 * TIPO ACTUAL del producto — no por el snapshot (cierra el branching
	 * TBA↔fecha del Bug 2):
	 *   - type actual `release_date` con la salida VIGENTE → `EtaProvider::calculate()`
	 *     max_ts vivo, y la línea lo recuerda en ITEM_MIN/MAX_CURRENT; si
	 *     calculate() da null (p.ej. producto ya instock, o sin pa_pais/lead)
	 *     → snapshot ITEM_MAX_AT_PURCHASE (0 si ausente).
	 *   - type actual `release_date` con la salida YA PASADA → la última promesa
	 *     de ESTA línea (ITEM_MAX_CURRENT, incluido «ninguna» = 0) o, si nunca
	 *     se recalculó, el snapshot. Nunca calculate(): anclaría la base a hoy
	 *     y la promesa se correría con el calendario.
	 *   - type actual `tba` → 0 (retractación: sin fecha vigente), y la línea
	 *     lo anota (ITEM_MIN/MAX_CURRENT = 0) para no volver a la de compra.
	 *   - `auto`/`disabled`/producto gone → snapshot ITEM_MAX_AT_PURCHASE, y la
	 *     línea OLVIDA su marca vigente para que la página del pedido lea lo
	 *     mismo que el cartel.
	 *     Anti-inflado: recalcular `auto` en vivo correría la ETA "como si
	 *     comprara hoy" (resolve_base_timestamp ancla a now para todo tipo
	 *     distinto de release_date) — jamás se llama calculate() para esos.
	 *
	 * @param WC_Order $order Orden preventa.
	 * @return int Timestamp máximo vigente, 0 si ninguno.
	 */
	public function compute_max_ts( WC_Order $order ): int {
		$max = 0;

		// Sólo las líneas que siguen sin llegar. Contar las que ya están en
		// bodega o las reembolsadas hacía que el cliente viera una fecha de
		// arribo POSTERIOR a la verdad, y que el tomo que se le devolvió siguiera
		// gobernando su estimado.
		foreach ( PreventaItems::pending_items( $order ) as $item ) {
			$max = max( $max, $this->item_max_ts( $item ) );
		}

		return $max;
	}

	/**
	 * Recomputa y persiste (en memoria) el summary para UNA orden ya gateada
	 * por el caller (has_preventa=yes + processing + ready_to_ship=no).
	 *
	 * Contrato real:
	 *   - max > 0  → `update_meta_data( ORDER_SUMMARY_MAX, max)`.
	 *   - max <= 0 → `delete_meta_data( ORDER_SUMMARY_MAX)` (delete-vs-zero:
	 *     el invariante del checkout es "presente ⇔ fecha concreta", nunca 0).
	 *   - Order-note de traza SOLO si existía un valor previo distinto — un
	 *     primer write (checkout, backfill) NO deja note (evitaría ruido en
	 *     cada checkout Blocks; ajuste 3 del gate GO).
	 *   - NO llama `save()`: el caller batchea la persistencia con sus propias
	 *     escrituras (listener: save único condicional; checkout clásico: WC
	 *     guarda tras el hook; Store API/backfill: save explícito del caller).
	 *
	 * @param WC_Order $order Orden gateada.
	 * @return bool True si el valor efectivo cambió.
	 */
	public function recompute_for_order( WC_Order $order ): bool {
		$previous = self::current_max_ts( $order );
		$max      = $this->compute_max_ts( $order );
		$current  = $max > 0 ? $max : null;

		if ( $previous === $current ) {
			return false;
		}

		// Todo lo vigente ya llegó: no queda nada que estimar, pero borrar la
		// fecha aquí dejaría el cartel sin ella justo antes de que el cron
		// marque el pedido listo. Se conserva la última hasta entonces. El
		// delete-vs-zero sigue vivo para la retractación a `tba` (la línea sigue
		// pendiente y aporta 0); el reembolso total no llega aquí: el cron lo
		// marca listo antes de recalcular.
		if ( null === $current
			&& PreventaItems::has_outstanding_items( $order )
			&& array() === PreventaItems::pending_items( $order ) ) {
			return false;
		}

		if ( null === $current ) {
			$order->delete_meta_data( MetaKeys::ORDER_SUMMARY_MAX );
		} else {
			$order->update_meta_data( MetaKeys::ORDER_SUMMARY_MAX, $current );
		}

		if ( null !== $previous ) {
			$order->add_order_note(
				sprintf(
					'Estimado de arribo recalculado: %s → %s.',
					wp_date( 'Y-m-d', $previous ),
					null === $current ? 'sin fecha vigente' : wp_date( 'Y-m-d', $current )
				)
			);
		}

		// La promesa cambió (también cuando NACE: un pedido sin fecha que la
		// recibe): el aviso interno «revisar» se vuelve a armar, para que el
		// siguiente retraso no quede mudo por haber avisado del anterior. Sin
		// `save()`: el caller batchea (ver arriba).
		$order->delete_meta_data( MetaKeys::ORDER_STALE_NOTED );

		return true;
	}

	/**
	 * Lector canónico (invariante en el LECTOR): normaliza ausente/''/0/neg
	 * a null. Único punto de verdad para "¿la orden tiene fecha vigente?".
	 *
	 * @param WC_Order $order Orden.
	 * @return int|null Timestamp vigente o null si no hay fecha estimable.
	 */
	public static function current_max_ts( WC_Order $order ): ?int {
		$raw = $order->get_meta( MetaKeys::ORDER_SUMMARY_MAX );
		if ( '' === $raw || null === $raw || false === $raw ) {
			return null;
		}
		$ts = (int) $raw;

		return $ts > 0 ? $ts : null;
	}

	/**
	 * Contribución de un ítem al max vigente según el tipo ACTUAL del producto.
	 *
	 * Reglas, en orden: producto borrado → snapshot de compra; `tba` → 0 (sin
	 * fecha vigente); `release_date` con la salida ya vencida → la última promesa
	 * de ESTA línea (o la de compra); `release_date` vigente → cálculo en vivo,
	 * que además se recuerda en la línea; `auto`/`disabled` → snapshot
	 * (anti-inflado, ver docblock de compute_max_ts).
	 *
	 * @param WC_Order_Item_Product $item Line item preventa.
	 * @return int Timestamp (0 = sin contribución).
	 */
	private function item_max_ts( WC_Order_Item_Product $item ): int {
		$snapshot = (int) $item->get_meta( MetaKeys::ITEM_MAX_AT_PURCHASE );

		$product = $item->get_product();
		if ( ! $product instanceof WC_Product ) {
			// Producto borrado: el snapshot inmutable es la única señal.
			return $this->forget_current( $item, $snapshot );
		}

		$type = (string) $product->get_meta( DateCalculator::META_TYPE );

		if ( Availability::TYPE_TBA === $type ) {
			// Retractación explícita: sin fecha vigente. Se anota en la línea
			// (0, distinto de «nunca recalculada»): sin la marca, la línea del
			// pedido volvía a la promesa de compra y decía «Llega entre el 8 y
			// el 14 de octubre» mientras la columna decía «sin fecha».
			$item->update_meta_data( MetaKeys::ITEM_MIN_CURRENT, 0 );
			$item->update_meta_data( MetaKeys::ITEM_MAX_CURRENT, 0 );
			return 0;
		}

		if ( Availability::TYPE_RELEASE_DATE === $type ) {
			// La fecha de salida YA PASÓ y el tomo no llegó: la editorial se
			// atrasó. `calculate()` anclaría la base a HOY y la promesa se
			// correría con el calendario —una fecha que nunca vence—. Lo honesto
			// es conservar la última que ESTA línea le prometió al cliente (o
			// la de compra): vencida, y que el aviso «revisar» la vea. Por línea
			// y no la de la orden: heredar el máximo de la orden hacía que un
			// tomo vencido «recordara» la fecha de OTRO tomo aunque ese otro se
			// retractara, llegara o se reembolsara, y el estimado no podía bajar.
			if ( ! Availability::is_preventa( $product ) ) {
				$vigente = (string) $item->get_meta( MetaKeys::ITEM_MAX_CURRENT );
				return '' !== $vigente ? (int) $vigente : $snapshot;
			}
			$eta = $this->calculator->calculate( $product );
			if ( $eta instanceof EtaResult && null !== $eta->max_ts() ) {
				// La línea recuerda lo que se le prometió: es lo que le enseña
				// al cliente y lo que conserva si la fecha vence sin llegar.
				$item->update_meta_data( MetaKeys::ITEM_MIN_CURRENT, (int) ( $eta->min_ts() ?? 0 ) );
				$item->update_meta_data( MetaKeys::ITEM_MAX_CURRENT, (int) $eta->max_ts() );
				return (int) $eta->max_ts();
			}
			// Producto ya instock o sin pa_pais/lead: cae al snapshot.
			return $this->forget_current( $item, $snapshot );
		}

		// `auto`/`disabled`/desconocido: snapshot (anti-inflado, ver docblock).
		return $this->forget_current( $item, $snapshot );
	}

	/**
	 * Cuando la contribución vuelve al snapshot de compra, la línea olvida la
	 * marca vigente: si se quedara, la línea del pedido seguiría enseñando la
	 * última ventana recalculada (o el 0 de una retractación anterior) mientras
	 * el cartel suma el snapshot — la misma página con dos fechas, por otra
	 * puerta. Sólo se escribe si había algo que olvidar.
	 *
	 * @since 1.114.16
	 *
	 * @param WC_Order_Item_Product $item     Línea.
	 * @param int                   $snapshot Lo que se devuelve.
	 * @return int
	 */
	private function forget_current( WC_Order_Item_Product $item, int $snapshot ): int {
		if ( '' !== (string) $item->get_meta( MetaKeys::ITEM_MAX_CURRENT ) ) {
			$item->delete_meta_data( MetaKeys::ITEM_MIN_CURRENT );
			$item->delete_meta_data( MetaKeys::ITEM_MAX_CURRENT );
		}
		return $snapshot;
	}
}
