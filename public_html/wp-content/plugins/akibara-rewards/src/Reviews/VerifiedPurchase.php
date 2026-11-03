<?php
/**
 * Prueba de compra del pedido que emitió el enlace personal de reseña.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

final class VerifiedPurchase {

	/**
	 * Revalida la compra de una reseña creada desde el enlace de un pedido.
	 *
	 * @param \WP_Comment $comment Reseña.
	 * @param int         $user_id Cuenta que recibiría los puntos.
	 * @return bool|null Null si no procede del formulario propio.
	 */
	public static function for_review( \WP_Comment $comment, int $user_id ): ?bool {
		$order_id = (int) get_comment_meta( (int) $comment->comment_ID, ReviewForm::META_ORDER, true );
		if ( $order_id <= 0 ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order && self::order_confirms( $order, (int) $comment->comment_post_ID, (string) $comment->comment_author_email, $user_id );
	}

	/**
	 * Comprueba identidad, pago y una unidad conservada del producto.
	 *
	 * WooCommerce no incluye los estados propios de entrega en su consulta.
	 * Sólo sirven como prueba con fecha de pago y una ruta autorizada.
	 *
	 * @param \WC_Order $order      Pedido enlazado por el token.
	 * @param int       $product_id Producto reseñado.
	 * @param string    $email      Correo del autor.
	 * @param int       $user_id    Cuenta del autor, o 0 para un invitado.
	 * @return bool
	 */
	public static function order_confirms( \WC_Order $order, int $product_id, string $email, int $user_id ): bool {
		$status = (string) $order->get_status();
		if ( $product_id <= 0 || OrderStates::is_down( $status ) || in_array( $status, array( 'pending', 'on-hold', 'checkout-draft' ), true ) ) {
			return false;
		}
		if ( ! in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$authorized = in_array( $status, OrderStates::PAID, true ) || Invitations::is_trigger( $status ) || $status === (string) $order->get_meta( Invitations::META_TRIGGER );
			if ( ! $authorized || ! $order->get_date_paid() ) {
				return false;
			}
		}
		$billing_email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' === $billing_email || $billing_email !== strtolower( trim( $email ) ) ) {
			return false;
		}
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 && $customer_id !== $user_id ) {
			return false;
		}
		if ( 0 === $customer_id && $user_id > 0 ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user instanceof \WP_User || strtolower( trim( (string) $user->user_email ) ) !== $billing_email ) {
				return false;
			}
		}
		$total = (float) $order->get_total();
		if ( $total > 0 && (float) $order->get_total_refunded() >= $total ) {
			return false;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( (int) $item->get_product_id() !== $product_id ) {
				continue;
			}
			$quantity = (float) $item->get_quantity();
			if ( $quantity > 0 && abs( (float) $order->get_qty_refunded_for_item( (int) $item_id ) ) < $quantity ) {
				return true;
			}
		}
		return false;
	}
}
