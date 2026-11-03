<?php
/**
 * Rearma los correos de reserva rechazados por el transporte.
 *
 * ReadyToShipEmail y ReminderEmail escriben la marca antes de send() para
 * cortar despachos concurrentes. Si wp_mail() devuelve false, no basta con
 * esperar reintentos de Action Scheduler: no hubo una excepción del worker.
 * Se revierte la marca sólo ante fallo de transporte confirmado y se incrementa
 * un contador acotado, de modo que los guardas recuperen la elegibilidad sin
 * reintentar indefinidamente contra un proveedor caído.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Helper compartido por los dos emails custom. No instanciable.
 *
 * @since 1.99.0
 */
final class SendFailureRearm {

	/**
	 * Tope de re-armados por orden y por email. Sin él, un proveedor caído de forma
	 * permanente haría que cada pasada del cron revierta el marker, reintente y
	 * llene la orden de notas.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Incrementa el contador y, mientras quede presupuesto, borra el marker para
	 * que la orden vuelva al conjunto elegible. Deja siempre order-note: el
	 * operador tiene que ver la diferencia entre "salió" y "quedó re-armado".
	 *
	 * Recuperación por camino, una vez borrado el marker:
	 *   - ready-to-ship → `OrderPreventa::maybe_resend_ready_email()` en el
	 *     siguiente `woocommerce_order_status_changed`;
	 *   - T-7 → la próxima pasada de `RemindersCron`;
	 *   - modos de fecha → se libera la ventana del cap de frecuencia.
	 *
	 * @param WC_Order $order        Orden objetivo.
	 * @param string   $marker_key   Meta de "ya enviado" a revertir.
	 * @param string   $attempts_key Meta contador de intentos fallidos.
	 * @param string   $label        Sujeto de la order-note ("El recordatorio T-7d").
	 * @return void
	 */
	public static function apply( WC_Order $order, string $marker_key, string $attempts_key, string $label ): void {
		$attempts = (int) $order->get_meta( $attempts_key ) + 1;
		$order->update_meta_data( $attempts_key, $attempts );

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$order->add_order_note(
				sprintf(
					'%s NO salió (fallo del transporte) y ya van %d intentos: se deja de reintentar automáticamente. Revisar el proveedor de correo y reenviar desde Acciones del pedido.',
					$label,
					$attempts
				)
			);
			$order->save();
			return;
		}

		$order->delete_meta_data( $marker_key );
		$order->add_order_note(
			sprintf(
				'%s NO salió (fallo del transporte). Queda re-armado para el próximo intento (%d/%d).',
				$label,
				$attempts,
				self::MAX_ATTEMPTS
			)
		);
		$order->save();
	}

	private function __construct() {}
}
