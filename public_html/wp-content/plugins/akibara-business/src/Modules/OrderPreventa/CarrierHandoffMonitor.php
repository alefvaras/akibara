<?php
/**
 * Monitor agnóstico del handoff de preventas a un transportista.
 *
 * Las integraciones declaran estados de handoff y referencias de tracking a
 * través de FulfillmentCapabilities. Este componente no conoce carriers,
 * plugins externos, metas de terceros ni nombres de estados concretos.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Observabilidad del handoff asíncrono hacia un carrier.
 *
 * @since 1.87.0
 */
final class CarrierHandoffMonitor {

	public const CHECK_HOOK = 'akb_order_preventa_verify_carrier_handoff';

	private const GROUP         = 'akibara-order-preventa';
	private const DELAY_SECONDS = 2 * HOUR_IN_SECONDS;

	/**
	 * @var callable():int
	 */
	private $now;

	/**
	 * @param callable|null $now Clock override para tests.
	 */
	public function __construct( ?callable $now = null ) {
		$this->now = $now ?? 'time';
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( self::CHECK_HOOK, array( $this, 'verify_handoff' ) );
	}

	/**
	 * Agenda una comprobación cuando una integración declara un handoff de carrier.
	 *
	 * @param int            $order_id  ID de la orden.
	 * @param string         $old_status Estado anterior sin prefijo wc-.
	 * @param string         $new_status Estado nuevo sin prefijo wc-.
	 * @param WC_Order|mixed $order      Orden entregada por WooCommerce.
	 * @return void
	 */
	public function on_status_changed( $order_id, $old_status, $new_status, $order = null ): void {
		if ( $old_status === $new_status ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order instanceof WC_Order || ! $this->should_monitor( $order ) ) {
			return;
		}
		if ( ! FulfillmentCapabilities::is_carrier_handoff_status( (string) $new_status, $order ) ) {
			return;
		}

		$args = array( 'order_id' => $order->get_id() );
		if ( ! \as_has_scheduled_action( self::CHECK_HOOK, $args, self::GROUP ) ) {
			\as_schedule_single_action(
				( $this->now )() + self::DELAY_SECONDS,
				self::CHECK_HOOK,
				$args,
				self::GROUP
			);
		}
	}

	/**
	 * Alerta una vez si el carrier no agregó tracking tras la espera configurada.
	 *
	 * @param int $order_id ID de la orden.
	 * @return void
	 */
	public function verify_handoff( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order || ! $this->should_monitor( $order ) ) {
			return;
		}
		if ( ! FulfillmentCapabilities::is_carrier_handoff_status( $order->get_status(), $order ) ) {
			return;
		}
		if ( ! empty( FulfillmentCapabilities::tracking_references( $order ) ) ) {
			return;
		}

		$already_alerted = 'yes' === (string) $order->get_meta( MetaKeys::ORDER_CARRIER_HANDOFF_ALERTED );
		$already_alerted = (bool) apply_filters(
			'akb_order_preventa_carrier_handoff_alerted',
			$already_alerted,
			$order
		);
		if ( $already_alerted ) {
			return;
		}

		$message = apply_filters(
			'akb_order_preventa_carrier_handoff_alert_message',
			'⚠️ El transportista aún no agregó tracking 2 horas después del handoff de esta preventa. Revisa primero el pedido en el portal del carrier antes de reintentar: repetir el envío podría crear un despacho duplicado.',
			$order
		);
		$order->add_order_note( (string) $message );
		$order->update_meta_data( MetaKeys::ORDER_CARRIER_HANDOFF_ALERTED, 'yes' );
		$order->save();
	}

	/**
	 * Determina si una preventa debe monitorearse mediante un carrier.
	 *
	 * @param WC_Order $order Orden a evaluar.
	 * @return bool
	 */
	private function should_monitor( WC_Order $order ): bool {
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return false;
		}
		if ( ! PreventaItems::has_outstanding_items( $order ) ) {
			return false;
		}

		return FulfillmentCapabilities::requires_carrier_fulfillment( $order );
	}
}