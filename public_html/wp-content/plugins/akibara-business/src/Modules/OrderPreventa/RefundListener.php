<?php
/**
 * Recalcula preventas activas tras un reembolso WooCommerce.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Evita que una línea preventa totalmente reembolsada espere hasta el cron
 * diario para dejar de contar como pendiente.
 *
 * @since 1.87.0
 */
final class RefundListener {

	private ReadyToShipCron $cron;

	public function __construct( ReadyToShipCron $cron ) {
		$this->cron = $cron;
	}

	public function register(): void {
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 20, 2 );
	}

	/**
	 * @param int|mixed $order_id  ID de la orden padre.
	 * @param int|mixed $refund_id ID del reembolso (no requerido para el cálculo).
	 * @return void
	 */
	public function on_order_refunded( $order_id, $refund_id = null ): void {
		unset( $refund_id );

		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return;
		}
		if ( ! FulfillmentCapabilities::is_active_order( $order ) ) {
			return;
		}
		if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
			return;
		}

		$this->cron->process_order( $order );
	}
}