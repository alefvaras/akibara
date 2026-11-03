<?php
/**
 * Muestra al operador el bloqueo de completar una reserva pendiente.
 *
 * CompletionPolicy restaura el estado anterior y deja una nota del pedido.
 * Este aviso explica el bloqueo después del redirect. Se almacena en meta del
 * usuario que realizó la acción, para que una operación individual o en lote
 * informe a esa persona sin compartir el mensaje con otras sesiones.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.108.11
 */
final class CompletionBlockedNotice {

	private const USER_META = '_akb_preventa_completion_blocked';

	/** Tope de pedidos recordados: una acción en lote no puede inflar la meta. */
	private const MAX_ORDERS = 20;

	public function register(): void {
		add_action( 'akb_order_preventa_completion_blocked', array( $this, 'record' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Anota el bloqueo para el usuario que lo provocó.
	 *
	 * @param int|mixed      $order_id       ID del pedido.
	 * @param string|mixed   $restore_status Estado al que volvió.
	 * @param WC_Order|mixed $order          Pedido.
	 */
	public function record( $order_id, $restore_status = '', $order = null ): void {
		unset( $restore_status );

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return; // Cron o WP-CLI: no hay a quién avisarle en pantalla.
		}

		$order_id = (int) $order_id;
		if ( $order_id < 1 ) {
			return;
		}

		$pending = $this->pending( $user_id );
		$pending[ $order_id ] = $this->missing_labels( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );

		if ( count( $pending ) > self::MAX_ORDERS ) {
			$pending = array_slice( $pending, -self::MAX_ORDERS, null, true );
		}

		update_user_meta( $user_id, self::USER_META, $pending );
	}

	/**
	 * Pinta el aviso una sola vez y lo consume.
	 */
	public function render(): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$pending = $this->pending( $user_id );
		if ( empty( $pending ) ) {
			return;
		}
		delete_user_meta( $user_id, self::USER_META );

		$lines = array();
		foreach ( $pending as $order_id => $missing ) {
			$order  = wc_get_order( (int) $order_id );
			$number = $order instanceof WC_Order ? $order->get_order_number() : (string) $order_id;
			$link   = $order instanceof WC_Order ? $order->get_edit_order_url() : '';
			$label  = '' !== $link
				? '<a href="' . esc_url( $link ) . '">#' . esc_html( $number ) . '</a>'
				: '#' . esc_html( $number );

			$lines[] = empty( $missing )
				? sprintf(
					'Pedido %s: todavía hay productos de preventa sin llegar.',
					$label
				)
				: sprintf(
					'Pedido %1$s: falta por llegar %2$s.',
					$label,
					esc_html( implode( ', ', $missing ) )
				);
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:20px">%s</ul><p>%s</p></div>',
			esc_html(
				1 === count( $lines )
					? 'No se pudo completar un pedido de preventa'
					: 'No se pudieron completar algunos pedidos de preventa'
			),
			// Cada línea ya viene escapada; sólo el enlace lleva HTML propio.
			implode( '', array_map( static fn( string $line ): string => '<li>' . $line . '</li>', $lines ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cada línea se armó arriba con esc_url() y esc_html() sobre sus partes variables; aquí sólo se envuelven en etiquetas de lista literales, y volver a escapar imprimiría el marcado del enlace como texto.
			esc_html( 'El pedido volvió a su estado anterior para no avisarle al cliente antes de tiempo. Cuando llegue la mercadería, marca el pedido como listo para despachar y podrás completarlo.' )
		);
	}

	/**
	 * Nombres de los productos que todavía no llegan.
	 *
	 * @param WC_Order|null|false $order Pedido.
	 * @return array<int, string>
	 */
	private function missing_labels( $order ): array {
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$labels = array();
		// Lo que SIGUE SIN LLEGAR: con un tomo ya en bodega y otro no, el
		// aviso listaba los dos como «falta por llegar».
		foreach ( PreventaItems::pending_items( $order ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$name = trim( (string) $item->get_name() );
			if ( '' !== $name ) {
				$labels[] = $name;
			}
			if ( count( $labels ) >= 5 ) {
				break; // Con cinco basta para identificar el bloqueo.
			}
		}

		return $labels;
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	private function pending( int $user_id ): array {
		$pending = get_user_meta( $user_id, self::USER_META, true );

		return is_array( $pending ) ? $pending : array();
	}
}
