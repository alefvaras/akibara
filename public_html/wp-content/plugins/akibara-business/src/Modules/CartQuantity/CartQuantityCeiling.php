<?php
/**
 * Aplica a todas las puertas del carrito el techo entero de 9999 unidades
 * que utiliza StoreAPI cuando no existe un límite propio.
 * El campo de cantidad usa woocommerce_quantity_input_max y el servidor
 * rechaza excesos mediante woocommerce_add_to_cart_validation. El stock
 * gestionado y los productos individuales conservan sus controles nativos.
 * maximo_para() conserva 0, limita N y convierte el valor -1 sin límite al
 * techo compartido. Desde WooCommerce 10.1 el getter puede traer ya aplicado
 * el filtro de máximo; la traducción mantiene el contrato si el filtro falta.
 * No introduce una option independiente del límite StoreAPI. Esta tienda
 * utiliza cantidades enteras de tomos, no inventario fraccional.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\CartQuantity;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.116.30
 */
final class CartQuantityCeiling {

	/**
	 * Techo de WooCommerce para un producto sin tope propio. Es el literal de
	 * `QuantityLimits::adjust_product_quantity_limit()` (Store API), que no lo
	 * expone como constante.
	 */
	public const MAXIMO = 9999;

	/** Aviso al cliente. Marcadores por `str_replace`, nunca formato de `sprintf`. */
	private const AVISO = 'Puedes llevar hasta {tope} unidades de «{producto}» por pedido.';

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_quantity_input_max', array( $this, 'cap_input_max' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 4 );
	}

	/**
	 * El tope real de una línea a partir de `get_max_purchase_quantity()`.
	 *
	 * @param mixed $max_purchase -1 sin límite · 0 nada comprable · N tope propio.
	 * @return int 0, N (acotado al techo) o el techo.
	 */
	public static function maximo_para( $max_purchase ): int {
		$max_purchase = (int) $max_purchase;
		if ( $max_purchase < 0 ) {
			return self::MAXIMO;
		}

		return min( $max_purchase, self::MAXIMO );
	}

	/**
	 * @param mixed $max     Lo que WooCommerce iba a poner en `max`.
	 * @param mixed $product Producto (no se usa: el techo no depende de él).
	 * @return int
	 */
	public function cap_input_max( $max, $product = null ): int {
		unset( $product );

		return self::maximo_para( $max );
	}

	/**
	 * @param mixed $passed       Resultado de las validaciones anteriores.
	 * @param mixed $product_id   Producto.
	 * @param mixed $quantity     Cantidad pedida.
	 * @param mixed $variation_id Variación, si la hay.
	 * @return bool
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ): bool {
		if ( ! $passed ) {
			return false;
		}

		$product = wc_get_product( (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return true;
		}

		// Con tope propio WooCommerce ya valida y avisa con su mensaje: «de uno
		// en uno» (`is_sold_individually`) y «no hay suficiente stock» (stock
		// gestionado sin reservas). Aquí sólo se cubre el «sin límite». No se
		// pregunta por `get_max_purchase_quantity()`: en el espejo devolvió
		// 9.999 para un tomo sin stock gestionado —el propio filtro
		// `woocommerce_quantity_input_max` de arriba se lo cuela—, y con
		// ese número el guard anterior dejaba pasar 999.999 unidades.
		if ( $product->is_sold_individually() || ( $product->managing_stock() && ! $product->backorders_allowed() ) ) {
			return true;
		}

		if ( (int) $quantity <= self::MAXIMO ) {
			return true;
		}

		wc_add_notice(
			str_replace(
				array( '{tope}', '{producto}' ),
				array( number_format_i18n( self::MAXIMO ), $product->get_name() ),
				self::AVISO
			),
			'error'
		);

		return false;
	}
}
