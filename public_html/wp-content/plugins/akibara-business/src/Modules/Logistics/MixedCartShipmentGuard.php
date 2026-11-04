<?php
/**
 * MixedCartShipmentGuard — Blindaje contra el doble despacho en carritos mixtos (v1.2).
 *
 * Exige y sella la aceptación de Despacho Único Consolidado cuando el pedido mezcla
 * productos en stock físico y productos "A pedido" (onbackorder).
 *
 * Cubre los 4 canales de checkout y persistencia:
 *   - Checkout clásico: woocommerce_checkout_create_order
 *   - Store API draft: woocommerce_store_api_checkout_update_order_meta
 *   - Checkout Blocks / Store API: woocommerce_store_api_checkout_order_processed
 *   - Guardado genérico: woocommerce_before_order_object_save
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Logistics;

use Akibara\Business\Support\BusinessOptions;
use Akibara\Business\Modules\Stock\StockStatus;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class MixedCartShipmentGuard {

	public const FIELD_NAME = 'akb_accept_consolidated_shipment';
	public const META_KEY   = '_akb_consolidated_shipment_accepted';

	public function register(): void {
		// 1. Checkout clásico.
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_checkout_checkbox' ), 20 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_checkbox' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'seal_order_acceptance' ), 10, 2 );

		// 2. Checkout Blocks / Store API.
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'seal_store_api_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'seal_store_api_order' ), 20, 1 );

		// 3. Guardado genérico (pedidos creados fuera de caja, admin manual, REST).
		add_action( 'woocommerce_before_order_object_save', array( $this, 'maybe_seal_order_on_save' ), 20, 1 );
	}

	public static function cart_is_mixed(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		$has_instock   = false;
		$has_backorder = false;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
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

	public static function cart_has_backorders(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			if ( StockStatus::is_reservable( $product ) || $product->is_on_backorder( 1 ) ) {
				return true;
			}
		}

		return false;
	}

	public static function order_is_mixed( WC_Order $order ): bool {
		$has_instock   = false;
		$has_backorder = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
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

	public static function order_has_backorder( WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			if ( StockStatus::is_reservable( $product ) || $product->is_on_backorder( 1 ) ) {
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
		<div class="akb-mixed-cart-shipment-notice" style="margin:15px 0; padding:14px 18px; border:1px solid #2271b1; background:#f0f6fc; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
			<p style="margin:0 0 10px 0; font-size:13px; color:#1d2327; line-height:1.45;">
				<strong>📦 Despacho Único Consolidado (Ahorro en Envío):</strong><br>
				Tu carrito combina tomos en stock inmediato y tomos A Pedido (reserva oficial). Para evitarte pagar un doble costo de despacho, enviaremos todo tu pedido en un solo paquete consolidado tan pronto arriben los tomos en reserva a nuestra bodega en Santiago.
			</p>
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" style="display:block; font-size:13px; font-weight:600; color:#1d2327; cursor:pointer;">
				<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="<?php echo esc_attr( self::FIELD_NAME ); ?>" id="<?php echo esc_attr( self::FIELD_NAME ); ?>" value="1" <?php checked( $checked ); ?>>
				<span>Comprendo y confirmo mi despacho consolidado para recibir todos mis tomos juntos.</span>
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

	/**
	 * Sella la aceptación en la orden tras verificar si es mixta o tiene reservas.
	 * Idempotente: no sobreescribe si ya está en 'yes'.
	 *
	 * @param WC_Order   $order Orden WooCommerce.
	 * @param mixed|null $data  Datos opcionales de checkout clásico.
	 */
	public function seal_order_acceptance( WC_Order $order, $data = null ): void {
		unset( $data );
		$is_mixed      = self::order_is_mixed( $order ) || self::cart_is_mixed();
		$has_backorder = self::order_has_backorder( $order ) || self::cart_has_backorders();

		if ( ! empty( $_POST[ self::FIELD_NAME ] ) || $is_mixed ) {
			if ( 'yes' !== (string) $order->get_meta( self::META_KEY ) ) {
				$order->update_meta_data( self::META_KEY, 'yes' );
			}
		}

		if ( $has_backorder ) {
			if ( 'yes' !== (string) $order->get_meta( '_akb_order_has_backorder' ) ) {
				$order->update_meta_data( '_akb_order_has_backorder', 'yes' );
			}
		}
	}

	/**
	 * Callback para Store API (draft order update y order processed).
	 *
	 * @param WC_Order $order Orden creada o actualizada vía Store API.
	 */
	public function seal_store_api_order( WC_Order $order ): void {
		$this->seal_order_acceptance( $order );
	}

	/**
	 * Callback perezoso para woocommerce_before_order_object_save.
	 *
	 * @param mixed $order Orden a punto de guardarse.
	 */
	public function maybe_seal_order_on_save( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->seal_order_acceptance( $order );
	}
}
