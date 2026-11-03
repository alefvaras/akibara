<?php
/**
 * Añade una selección de tomos y explica los rechazos por estado.
 *
 * BuilderWidget y LandingOffer comparten este camino. RejectionReason clasifica
 * también los productos que los guardas de catálogo descartan antes del intento.
 * rejected_ids identifica agotados y tomos ya completos en carrito, para que el
 * cliente los desmarque; otros motivos pueden conservarse para reintentar.
 * message usa textos editables saneados por Text::one_line_option(), nunca como
 * formato sprintf. Es vacío si toda la selección quedó en carrito, incluidos
 * los tomos que ya estaban. La cola de avisos de WooCommerce conserva su estado
 * previo porque la respuesta ya comunica el resultado en esta superficie.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Modules\NativeCart\RejectionReason;
use Akibara\Business\Utilities\Text;
use WC_Cart;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class SelectionCart {

	/** Texto cuando todo lo que no entró ya estaba en el carrito, con todas las unidades que quedan. */
	public const OPTION_YA_EN_CARRITO = 'akb_copy_pack_ya_en_carrito';

	public const DEFAULT_YA_EN_CARRITO = 'Ya tienes en tu carrito todas las unidades que quedan de esos tomos.';

	/** Texto cuando alguno de los elegidos se agotó. */
	public const OPTION_SIN_STOCK = 'akb_copy_pack_sin_stock';

	public const DEFAULT_SIN_STOCK = 'Uno o más de los tomos que elegiste se agotaron y no entraron al carrito. Recarga la página para ver los que quedan.';

	/** Texto cuando el motivo no se sabe: el único caso en que reintentar puede servir. */
	public const OPTION_ERROR = 'akb_copy_pack_error';

	public const DEFAULT_ERROR = 'No se pudo agregar. Inténtalo de nuevo.';

	/**
	 * Literales de fábrica, para registrar y sanear los campos del panel.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			self::OPTION_YA_EN_CARRITO => self::DEFAULT_YA_EN_CARRITO,
			self::OPTION_SIN_STOCK     => self::DEFAULT_SIN_STOCK,
			self::OPTION_ERROR         => self::DEFAULT_ERROR,
		);
	}

	/**
	 * El texto del dueño para un fallo sin motivo conocido.
	 */
	public static function error_text(): string {
		return Text::one_line_option( self::OPTION_ERROR, self::DEFAULT_ERROR );
	}

	/**
	 * Responde el alta de una selección: `success` si entró al menos uno.
	 *
	 * El cliente desmarca `added_ids` y `rejected_ids`; lo que queda marcado es
	 * lo que un reintento podría sumar. `message` es el motivo de lo que no
	 * entró ('' si no hace falta explicar nada). Los dos handlers ya cortaron
	 * antes una selección vacía, así que si nada entró hay motivo que decir.
	 *
	 * @param WC_Cart         $cart       Carrito de la sesión.
	 * @param array<int, int> $requested  Tomos que el cliente eligió.
	 * @param array<int, int> $authorized Los que pasaron la puerta del catálogo de la serie.
	 */
	public static function send( WC_Cart $cart, array $requested, array $authorized ): void {
		$resultado = self::add( $cart, $requested, $authorized );
		$added_ids = $resultado['added_ids'];
		if ( array() === $added_ids ) {
			wp_send_json(
				array(
					'success'      => false,
					'message'      => $resultado['message'],
					'rejected_ids' => $resultado['rejected_ids'],
				)
			);
			return;
		}

		wp_send_json(
			array(
				'success'      => true,
				'message'      => $resultado['message'],
				'added'        => count( $added_ids ),
				'added_ids'    => $added_ids,
				'rejected_ids' => $resultado['rejected_ids'],
			)
		);
	}

	/**
	 * Intenta sumar una unidad de cada tomo autorizado y explica el resto.
	 *
	 * @param WC_Cart         $cart       Carrito de la sesión.
	 * @param array<int, int> $requested  Tomos que el cliente eligió.
	 * @param array<int, int> $authorized Los que pasaron la puerta del catálogo de la serie.
	 * @return array{added_ids: array<int, int>, rejected_ids: array<int, int>, message: string}
	 */
	public static function add( WC_Cart $cart, array $requested, array $authorized ): array {
		$avisos       = wc_get_notices();
		$added_ids    = array();
		$calc_removed = remove_action( 'woocommerce_add_to_cart', array( $cart, 'calculate_totals' ), 20 );

		foreach ( $authorized as $product_id ) {
			if ( $cart->add_to_cart( $product_id, 1 ) ) {
				$added_ids[] = $product_id;
			}
		}

		if ( $calc_removed ) {
			add_action( 'woocommerce_add_to_cart', array( $cart, 'calculate_totals' ), 20, 0 );
			if ( method_exists( $cart, 'calculate_totals' ) ) {
				$cart->calculate_totals();
			}
		}

		wc_set_notices( $avisos );

		$fallidos     = array_values( array_diff( $requested, $added_ids ) );
		$en_carrito   = array() === $fallidos ? array() : (array) $cart->get_cart_item_quantities();
		$motivos      = array();
		$rejected_ids = array();
		foreach ( $fallidos as $product_id ) {
			$producto = wc_get_product( $product_id );
			$motivo   = RejectionReason::for_product(
				$producto,
				1,
				$producto instanceof WC_Product ? (int) ( $en_carrito[ $producto->get_stock_managed_by_id() ] ?? 0 ) : 0
			);

			$motivos[] = $motivo;
			if ( '' !== $motivo ) {
				$rejected_ids[] = $product_id;
			}
		}

		return array(
			'added_ids'    => $added_ids,
			'rejected_ids' => $rejected_ids,
			'message'      => self::message( $motivos, array() !== $added_ids ),
		);
	}

	/**
	 * Lo que se le dice al cliente de los que no entraron, o '' si no hace falta.
	 *
	 * Un motivo desconocido manda: hay tomos que siguen marcados y reintentar
	 * puede servir. Después, un agotado: hay que recargar para verlo. Si todo lo
	 * que no entró ya estaba en el carrito y algo sí entró, el pack completo está
	 * en el carrito y no hay nada que explicar.
	 *
	 * @param array<int, string> $motivos    Un código de `RejectionReason` por tomo que no entró.
	 * @param bool               $algo_entro Si se sumó al menos uno.
	 */
	private static function message( array $motivos, bool $algo_entro ): string {
		if ( array() === $motivos ) {
			return '';
		}
		if ( in_array( '', $motivos, true ) ) {
			return self::error_text();
		}
		if ( in_array( RejectionReason::SIN_STOCK, $motivos, true ) ) {
			return Text::one_line_option( self::OPTION_SIN_STOCK, self::DEFAULT_SIN_STOCK );
		}

		return $algo_entro ? '' : Text::one_line_option( self::OPTION_YA_EN_CARRITO, self::DEFAULT_YA_EN_CARRITO );
	}
}
