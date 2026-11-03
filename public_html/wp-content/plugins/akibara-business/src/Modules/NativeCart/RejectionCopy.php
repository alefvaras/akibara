<?php
/**
 * Textos editables de los rechazos al añadir un tomo al carrito.
 *
 * RejectionReason identifica ya_en_carrito cuando el carrito contiene todas las
 * unidades comprables, y sin_stock cuando el tomo está agotado. Sus mensajes se
 * configuran en Akibara → Textos. Los demás rechazos mantienen los textos de
 * WooCommerce para conservar sus detalles sobre cantidades y disponibilidad.
 *
 * Los filtros de WC_Cart::add_to_cart() aplican el mismo mensaje en ficha, cajón,
 * tarjeta y barra de compra, incluyendo el aviso drenado por NativeCart. Se
 * conserva el enlace Ver carrito que añade WooCommerce. El marcador {producto}
 * se sustituye con str_replace; el texto del dueño no es un formato de sprintf.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\NativeCart;

use Akibara\Business\Utilities\Text;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class RejectionCopy {

	/** Texto cuando el carrito ya tiene todas las unidades que se pueden comprar. */
	public const OPTION_YA_EN_CARRITO = 'akb_copy_cart_ya_en_carrito';

	public const DEFAULT_YA_EN_CARRITO = 'Ya tienes en tu carrito todas las unidades que quedan de este tomo.';

	/** Texto cuando el tomo está agotado. */
	public const OPTION_SIN_STOCK = 'akb_copy_cart_sin_stock';

	/** La frase de WooCommerce en español, tal cual la veía el cliente. */
	public const DEFAULT_SIN_STOCK = 'No puedes añadir «{producto}» al carrito porque el producto está agotado.';

	/** Marcador del nombre del tomo. */
	public const PRODUCT_MARKER = '{producto}';

	/** Enlace «Ver carrito» que WooCommerce pega a su aviso. */
	private const CART_LINK_PATTERN = '#<a\b[^>]*\bwc-forward\b[^>]*>.*?</a>#is';

	/**
	 * Literales de fábrica, para registrar y sanear los campos del panel.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			self::OPTION_YA_EN_CARRITO => self::DEFAULT_YA_EN_CARRITO,
			self::OPTION_SIN_STOCK     => self::DEFAULT_SIN_STOCK,
		);
	}

	/**
	 * Unidades por producto que ya hay en el carrito, o null para leer el de la
	 * sesión (`WC()->cart`).
	 *
	 * @var callable():array<int,int>|null
	 */
	private $cart_quantities;

	/**
	 * @param callable():array<int,int>|null $cart_quantities Lector de las cantidades del carrito, para las pruebas:
	 *                                                        definir `WC()` en la suite cambia el camino de todas las
	 *                                                        clases que preguntan `function_exists( 'WC')` después.
	 */
	public function __construct( ?callable $cart_quantities = null ) {
		$this->cart_quantities = $cart_quantities;
	}

	public function register(): void {
		add_filter( 'woocommerce_cart_product_not_enough_stock_already_in_cart_message', array( $this, 'handle_already_in_cart_message' ), 10, 4 );
		add_filter( 'woocommerce_cart_product_not_enough_stock_message', array( $this, 'handle_not_enough_stock_message' ), 10, 3 );
		add_filter( 'woocommerce_cart_product_out_of_stock_message', array( $this, 'handle_out_of_stock_message' ), 10, 2 );
	}

	/**
	 * «Ya tienes N en tu carrito»: el texto del dueño sólo si no cabe ni una más.
	 *
	 * @internal Callback de `woocommerce_cart_product_not_enough_stock_already_in_cart_message`.
	 *
	 * @param mixed $message    Aviso de WooCommerce (con su enlace «Ver carrito»).
	 * @param mixed $product    Tomo.
	 * @param mixed $stock      Unidades que hay.
	 * @param mixed $en_carrito Unidades que ya están en el carrito.
	 * @return mixed
	 */
	public function handle_already_in_cart_message( $message, $product, $stock = 0, $en_carrito = 0 ) {
		unset( $stock );
		if ( ! is_string( $message ) || ! $product instanceof WC_Product
			|| RejectionReason::YA_EN_CARRITO !== RejectionReason::for_product( $product, 1, (int) $en_carrito ) ) {
			return $message;
		}

		return self::with_cart_link( self::text( RejectionReason::YA_EN_CARRITO, $product ), $message );
	}

	/**
	 * «No hay suficientes existencias»: si además el carrito ya está lleno, el
	 * motivo de verdad es ése (el código que recibe el tema dice `ya_en_carrito`).
	 *
	 * @internal Callback de `woocommerce_cart_product_not_enough_stock_message`.
	 *
	 * @param mixed $message Aviso de WooCommerce.
	 * @param mixed $product Tomo.
	 * @param mixed $stock   Unidades que hay.
	 * @return mixed
	 */
	public function handle_not_enough_stock_message( $message, $product, $stock = 0 ) {
		unset( $stock );
		if ( ! is_string( $message ) || ! $product instanceof WC_Product
			|| RejectionReason::YA_EN_CARRITO !== RejectionReason::for_product( $product, 1, $this->in_cart( $product ) ) ) {
			return $message;
		}

		return self::with_cart_link( self::text( RejectionReason::YA_EN_CARRITO, $product ), $message );
	}

	/**
	 * Tomo agotado.
	 *
	 * @internal Callback de `woocommerce_cart_product_out_of_stock_message`.
	 *
	 * @param mixed $message Aviso de WooCommerce.
	 * @param mixed $product Tomo.
	 * @return mixed
	 */
	public function handle_out_of_stock_message( $message, $product ) {
		if ( ! is_string( $message ) || ! $product instanceof WC_Product ) {
			return $message;
		}

		return self::with_cart_link( self::text( RejectionReason::SIN_STOCK, $product ), $message );
	}

	/**
	 * El texto del dueño para un código, en texto plano, con el nombre del tomo.
	 *
	 * Lee con el MISMO saneador con que el panel guarda (`Text::one_line()`, por
	 * `Text::one_line_option()`): lo que el lector acepta es exactamente lo que el
	 * guardado deja pasar, y un valor vacío o ilegible cae al de fábrica.
	 *
	 * @param string     $code    `RejectionReason::YA_EN_CARRITO` o `::SIN_STOCK`.
	 * @param WC_Product $product Tomo.
	 */
	public static function text( string $code, WC_Product $product ): string {
		$texto = RejectionReason::YA_EN_CARRITO === $code
			? Text::one_line_option( self::OPTION_YA_EN_CARRITO, self::DEFAULT_YA_EN_CARRITO )
			: Text::one_line_option( self::OPTION_SIN_STOCK, self::DEFAULT_SIN_STOCK );

		return str_replace( self::PRODUCT_MARKER, $product->get_name(), $texto );
	}

	/**
	 * El texto, escapado, con el «Ver carrito» del aviso original si lo traía.
	 *
	 * @param string $texto    Texto plano.
	 * @param string $original Aviso de WooCommerce.
	 */
	private static function with_cart_link( string $texto, string $original ): string {
		$html = esc_html( $texto );

		return 1 === preg_match( self::CART_LINK_PATTERN, $original, $enlace )
			? $html . ' ' . $enlace[0]
			: $html;
	}

	/**
	 * Unidades de este tomo en el carrito, contadas como las cuenta WooCommerce.
	 *
	 * @param WC_Product $product Tomo.
	 */
	private function in_cart( WC_Product $product ): int {
		if ( null !== $this->cart_quantities ) {
			$cantidades = (array) ( $this->cart_quantities )();
		} elseif ( function_exists( 'WC' ) && null !== WC()->cart ) {
			$cantidades = (array) WC()->cart->get_cart_item_quantities();
		} else {
			return 0;
		}

		return (int) ( $cantidades[ $product->get_stock_managed_by_id() ] ?? 0 );
	}
}
