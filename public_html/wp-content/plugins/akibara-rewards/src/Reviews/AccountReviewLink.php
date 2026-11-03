<?php
/** Recupera la invitación ya enviada desde los pedidos de su titular. */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\Options;
use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

final class AccountReviewLink {

	/** Registra los accesos en la lista y el detalle de pedidos. */
	public function register(): void {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'order_actions' ), 20, 2 );
		add_action( 'woocommerce_view_order', array( $this, 'view_order' ), 20 );
	}

	/** Añade el acceso únicamente si el cliente puede recuperar su invitación. */
	public function order_actions( array $actions, $order ): array {
		$url = $this->url_for_order( $order );
		if ( '' !== $url ) {
			$actions['akb_review_invitation'] = array( 'url' => $url, 'name' => $this->label() );
		}
		return $actions;
	}

	/** Muestra el mismo acceso en el detalle del pedido propio. */
	public function view_order( $order_id ): void {
		$url = $this->url_for_order( wc_get_order( (int) $order_id ) );
		if ( '' !== $url ) {
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $url ), esc_html( $this->label() ) );
		}
	}

	/** Lee un token vigente sin emitir enlaces ni modificar el pedido. */
	public function url_for_order( $order ): string {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 || ! $order instanceof \WC_Order
			|| $user_id !== (int) $order->get_customer_id()
			|| '' === (string) $order->get_meta( Invitations::META_SENT ) ) {
			return '';
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT order_id, token, expires_at, used_at FROM ' . Schema::review_forms_table()
				. ' WHERE order_id = %d AND used_at IS NULL AND expires_at >= %s ORDER BY id DESC LIMIT 1',
				(int) $order->get_id(),
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || (int) ( $row['order_id'] ?? 0 ) !== (int) $order->get_id()
			|| null !== ( $row['used_at'] ?? null )
			|| (string) ( $row['expires_at'] ?? '' ) < gmdate( 'Y-m-d H:i:s' )
			|| ! preg_match( '/^[A-Za-z0-9]{20,64}$/', (string) ( $row['token'] ?? '' ) ) ) {
			return '';
		}

		return ReviewForm::url_for( (string) $row['token'] );
	}

	/** Texto coherente con el carril vigente. */
	private function label(): string {
		return Options::reviews_lane_on() ? 'Reseñar y obtener puntos' : 'Escribir mi reseña';
	}
}
