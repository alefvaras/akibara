<?php
/**
 * Canonical Chilean phone normalization for completed Store API orders.
 *
 * Checkout field presentation and validation belong to the dedicated
 * Akibara Cart and Checkout Blocks adapter. Business retains only the
 * renderer-independent order normalization rule.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PhoneValidation;

use Akibara\Business\Utilities\ChileanPhone;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.3.0
 */
final class PhoneValidation {

	/**
	 * Connects the renderer-independent Store API order hook.
	 */
	public function register(): void {
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			array( $this, 'normalize_on_store_api_order' ),
			10,
			1
		);
	}

	/**
	 * Normalizes billing and shipping phones after Checkout Blocks persisted the
	 * order, saving exactly once when either canonical value changed.
	 *
	 * @param mixed $order Store API order.
	 */
	public function normalize_on_store_api_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$billing_raw  = sanitize_text_field( (string) $order->get_billing_phone() );
		$shipping_raw = sanitize_text_field( (string) $order->get_shipping_phone() );

		$billing_changed  = $this->apply_normalized_phone( $order, $billing_raw );
		$shipping_changed = $this->apply_normalized_shipping_phone( $order, $shipping_raw );

		if ( $billing_changed || $shipping_changed ) {
			$order->save();
		}
	}

	/**
	 * Applies a valid canonical billing phone.
	 */
	private function apply_normalized_phone( \WC_Order $order, string $raw ): bool {
		$normalized = $this->normalized_or_null( $raw );
		if ( null === $normalized ) {
			return false;
		}

		$order->set_billing_phone( $normalized );
		return true;
	}

	/**
	 * Applies a valid canonical shipping phone without inventing one.
	 */
	private function apply_normalized_shipping_phone( \WC_Order $order, string $raw ): bool {
		$normalized = $this->normalized_or_null( $raw );
		if ( null === $normalized ) {
			return false;
		}

		$order->set_shipping_phone( $normalized );
		return true;
	}

	/**
	 * Returns a canonical changed value, or null for empty, invalid or already
	 * canonical input.
	 *
	 * La validez se comprueba ANTES de canonizar, y no es un detalle: `format()`
	 * antepone `+569` a cualquier cadena con ocho dígitos, incluidas las que
	 * `validation_error()` rechaza —`00000000`, `11111111`, cualquiera que
	 * empiece en 0 o en 1—. Sin esta guarda el módulo tomaba un teléfono que su
	 * propio validador declara inválido y lo guardaba en el pedido con forma de
	 * número chileno legítimo, que es peor que dejarlo como venía: nadie vuelve a
	 * mirar un `+56911111111`.
	 */
	private function normalized_or_null( string $raw ): ?string {
		if ( '' === $raw ) {
			return null;
		}

		if ( null !== ChileanPhone::validation_error( $raw, false ) ) {
			return null;
		}

		$normalized = ChileanPhone::format( $raw );
		return $normalized === $raw ? null : $normalized;
	}
}
