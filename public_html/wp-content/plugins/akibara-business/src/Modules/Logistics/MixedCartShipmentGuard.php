<?php
/**
 * MixedCartShipmentGuard — Blindaje contra el doble despacho en carritos mixtos (v1.1).
 *
 * Exige aceptación de Despacho Único Consolidado cuando el pedido mezcla
 * productos en stock físico y productos "A pedido" (onbackorder).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Logistics;

use Akibara\Business\Support\BusinessOptions;
use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

final class MixedCartShipmentGuard {

	public const FIELD_NAME = 'akb_accept_consolidated_shipment';
	public const META_KEY   = '_akb_consolidated_shipment_accepted';

	public function register(): void {
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_checkout_checkbox' ), 20 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_checkbox' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'seal_order_acceptance' ), 10, 2 );
	}

	public static function cart_is_mixed(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		$has_instock   = false;
		$has_backorder = false;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( StockStatus::is_instock( $product ) ) {
				$has_instock = true;
			} elseif ( StockStatus::is_reservable( $product ) || $product->is_on_backorder( 1 ) ) {
				$has_backorder = true;
			}

			if ( $has_instock && $has_backorder ) {
				return true;
			}
		}

		return false;
	}

	public function render_checkout_checkbox(): void {
		if ( ! BusinessOptions::mixed_cart_single_shipment_policy() ) {
			return;
		}
		if ( ! self::cart_is_mixed() ) {
			return;
		}

		$checked = ! empty( $_POST[ self::FIELD_NAME ] );
		?>
		<div class="akb-mixed-cart-shipment-notice" style="margin:15px 0; padding:12px 16px; border:1px solid #dba617; background:#fffcf0; border-radius:4px;">
			<p style="margin:0 0 10px 0; font-size:13px; color:#614700; line-height:1.4;">
				<strong>📦 Tu pedido combina tomos en Stock y tomos A Pedido (Reserva):</strong><br>
				Se despachará en un único envío consolidado cuando la totalidad de los tomos se encuentre en nuestra bodega en Santiago.
			</p>
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" style="display:block; font-size:13px; font-weight:600;">
				<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="<?php echo esc_attr( self::FIELD_NAME ); ?>" id="<?php echo esc_attr( self::FIELD_NAME ); ?>" value="1" <?php checked( $checked ); ?>>
				<span>Entiendo y acepto el Despacho Único Consolidado al llegar los tomos A Pedido.</span>
			</label>
		</div>
		<?php
	}

	public function validate_checkout_checkbox(): void {
		if ( ! BusinessOptions::mixed_cart_single_shipment_policy() ) {
			return;
		}
		if ( ! self::cart_is_mixed() ) {
			return;
		}

		if ( empty( $_POST[ self::FIELD_NAME ] ) ) {
			wc_add_notice(
				'Por favor confirma la casilla de aceptación de <strong>Despacho Único Consolidado</strong> para continuar con tu pedido mixto.',
				'error'
			);
		}
	}

	public function seal_order_acceptance( \WC_Order $order, $data ): void {
		unset( $data );
		if ( ! empty( $_POST[ self::FIELD_NAME ] ) || self::cart_is_mixed() ) {
			$order->update_meta_data( self::META_KEY, 'yes' );
			$order->update_meta_data( '_akb_order_has_backorder', 'yes' );
		}
	}
}
