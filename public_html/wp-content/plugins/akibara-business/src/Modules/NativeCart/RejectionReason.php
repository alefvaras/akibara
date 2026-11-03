<?php
/**
 * Clasifica el rechazo de WC_Cart::add_to_cart() por estado, sin analizar texto.
 *
 * El aviso público puede traducirse o filtrarse; el código debe ser estable:
 * 1. Vendido individualmente y ya en carrito → ya_en_carrito.
 * 2. No comprable → cadena vacía.
 * 3. Agotado → sin_stock.
 * 4. El carrito contiene todas las unidades gestionadas → ya_en_carrito.
 * 5. No alcanza para la cantidad solicitada → sin_stock.
 * 6. Quedan unidades, pero no alcanzan al sumarlas al carrito → cadena vacía.
 *
 * ya_en_carrito significa que no cabe ninguna unidad más y permite ofrecer
 * Ver carrito. El cálculo no cambia cantidades ni añade avisos: proyecta los
 * mismos guardas de WooCommerce junto al texto drenado por NativeCart.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\NativeCart;

use WC_Product;

defined( 'ABSPATH' ) || exit;

final class RejectionReason {

	/** El carrito ya tiene todo lo que se puede comprar de este tomo: no cabe ni una unidad más. */
	public const YA_EN_CARRITO = 'ya_en_carrito';

	/** No hay unidades, o no alcanzan para lo pedido aunque el carrito no tenga ninguna. */
	public const SIN_STOCK = 'sin_stock';

	/**
	 * Código del rechazo de este producto, o '' si el estado no lo explica.
	 *
	 * @param mixed $producto   Producto que se intentó agregar.
	 * @param int   $cantidad   Unidades pedidas en ese intento.
	 * @param int   $en_carrito Unidades de ese producto que ya hay en el carrito
	 *                          (`WC_Cart::get_cart_item_quantities()` por
	 *                          `get_stock_managed_by_id()`, como WooCommerce).
	 * @return string
	 */
	public static function for_product( $producto, int $cantidad, int $en_carrito ): string {
		if ( ! $producto instanceof WC_Product ) {
			return '';
		}

		$cantidad   = max( 1, $cantidad );
		$en_carrito = max( 0, $en_carrito );

		if ( $producto->is_sold_individually() && $en_carrito > 0 ) {
			return self::YA_EN_CARRITO;
		}
		if ( ! $producto->is_purchasable() ) {
			return '';
		}
		if ( ! $producto->is_in_stock() ) {
			return self::SIN_STOCK;
		}
		if ( $producto->managing_stock() && $en_carrito > 0 && ! $producto->has_enough_stock( $en_carrito + 1 ) ) {
			return self::YA_EN_CARRITO;
		}
		if ( ! $producto->has_enough_stock( $cantidad ) ) {
			return self::SIN_STOCK;
		}

		return '';
	}
}
