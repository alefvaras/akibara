<?php
/**
 * Política de finalización de órdenes con preventa.
 *
 * El núcleo expone un contrato cooperativo para integraciones que completan
 * pedidos y una red de seguridad para restaurar órdenes que terceros cierren
 * antes de que la preventa esté lista. No conoce carriers ni proveedores de
 * reseñas.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Evita que una preventa pendiente quede completada antes de estar lista.
 *
 * @since 1.87.0
 */
final class CompletionPolicy {

	/**
	 * @var array<int, bool>
	 */
	private array $reverting_order_ids = array();

	public function register(): void {
		add_filter(
			'woocommerce_payment_complete_order_status',
			array( $this, 'keep_unready_preorders_processing' ),
			20,
			3
		);
		add_action( 'woocommerce_before_order_object_save', array( $this, 'prevent_unready_completion_before_save' ), 0, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'restore_unready_completion' ), 0, 3 );
	}

	/**
	 * Convierte un `completed` automático de pago en `processing` si la preventa
	 * todavía no está lista. Cubre gateways que usan WC_Order::payment_complete().
	 *
	 * @param string        $status   Estado que WooCommerce propone al pagar.
	 * @param int           $order_id ID de la orden.
	 * @param WC_Order|null $order    Orden bajo pago.
	 * @return string
	 */
	public function keep_unready_preorders_processing( $status, $order_id, $order = null ): string {
		if ( 'completed' !== (string) $status ) {
			return (string) $status;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order instanceof WC_Order || self::can_complete( $order ) ) {
			return (string) $status;
		}

		return 'processing';
	}

	/**
	 * Impide que la ruta CRUD habitual persista `completed` para una preventa
	 * pendiente. Al sustituir el estado antes de guardar, WooCommerce no publica
	 * `woocommerce_order_status_completed` a carriers, reseñas u otros plugins.
	 *
	 * La transición queda como un no-op operativo (estado previo a sí mismo),
	 * con la nota desactivada. El hook de completed continúa como fallback para
	 * integraciones que eludan este punto de extensión.
	 *
	 * @param WC_Order|mixed $order     Orden que WooCommerce está guardando.
	 * @param mixed          $data_store Data store de WooCommerce.
	 * @return void
	 */
	public function prevent_unready_completion_before_save( $order, $data_store ): void {
		unset( $data_store );

		if ( ! $order instanceof WC_Order || ! $order->has_status( 'completed' ) ) {
			return;
		}
		if ( self::can_complete( $order ) ) {
			return;
		}
		if ( $order->get_id() <= 0 ) {
			$order->set_date_completed( null );
			$order->set_status( 'processing', false );
			return;
		}

		$stored_order = wc_get_order( $order->get_id() );
		if ( ! $stored_order instanceof WC_Order || $stored_order->has_status( 'completed' ) ) {
			return;
		}

		$restore_status = self::restore_status( $stored_order->get_status() );
		$order->set_date_completed( null );
		$order->set_status( $restore_status, false );
		$order->add_order_note(
			'Preventa pendiente: se evitó completar la orden antes de que todos sus productos estuvieran listos para despachar.'
		);
		do_action( 'akb_order_preventa_completion_blocked', $order->get_id(), $restore_status, $order );
	}

	/**
	 * Restaura una preventa pendiente si un tercero la completó directamente.
	 *
	 * Fallback para integraciones que no pasan por el guardado CRUD normal. La
	 * ruta preferida es prevent_unready_completion_before_save(), que evita
	 * publicar `completed` a otros plugins. Integraciones cooperativas deben usar
	 * can_complete() antes de pedir la transición.
	 *
	 * @param int            $order_id   ID de la orden.
	 * @param WC_Order|mixed $order      Orden entregada por WooCommerce.
	 * @param array<string, mixed>|mixed $transition Detalle de la transición.
	 * @return void
	 */
	public function restore_unready_completion( $order_id, $order = null, $transition = array() ): void {
		$order_id = (int) $order_id;
		if ( isset( $this->reverting_order_ids[ $order_id ] ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order || ! $order->has_status( 'completed' ) || self::can_complete( $order ) ) {
			return;
		}

		$old_status     = is_array( $transition ) ? (string) ( $transition['from'] ?? '' ) : '';
		$restore_status = self::restore_status( $old_status );
		$this->reverting_order_ids[ $order_id ] = true;

		try {
			$order->set_date_completed( null );
			$order->update_status(
				$restore_status,
				'Preventa pendiente: se evitó completar la orden antes de que todos sus productos estuvieran listos para despachar.',
				false
			);
		} finally {
			unset( $this->reverting_order_ids[ $order_id ] );
		}
	}

	/**
	 * Indica si una integración puede completar la orden de forma segura.
	 *
	 * @param WC_Order $order Orden a evaluar.
	 * @return bool
	 */
	public static function can_complete( WC_Order $order ): bool {
		$allowed = 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA )
			|| ! PreventaItems::has_outstanding_items( $order )
			|| 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP );

		return (bool) apply_filters( 'akb_order_preventa_can_complete_order', $allowed, $order );
	}

	/**
	 * Indica si una integración de reseñas puede solicitar feedback de la orden.
	 *
	 * @param WC_Order $order Orden a evaluar.
	 * @return bool
	 */
	public static function can_request_review( WC_Order $order ): bool {
		$allowed = 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA )
			|| ( self::can_complete( $order ) && $order->has_status( 'completed' ) );

		return (bool) apply_filters( 'akb_order_preventa_can_request_review', $allowed, $order );
	}

	/**
	 * Normaliza el estado de retorno para una finalización anticipada.
	 *
	 * @param string $old_status Estado previo de WooCommerce.
	 * @return string
	 */
	private static function restore_status( string $old_status ): string {
		$old_status = str_starts_with( $old_status, 'wc-' ) ? substr( $old_status, 3 ) : $old_status;

		return '' !== $old_status && 'completed' !== $old_status ? $old_status : 'processing';
	}
}