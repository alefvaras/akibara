<?php
/**
 * Arrival — captura de primera llegada física a bodega (`instock`) por producto.
 *
 * Registra de forma persistente los hitos cronológicos de ciclo de vida del producto:
 *   - `_akb_first_instock_at`: instante de primera llegada física a bodega.
 *
 * Representa un dato fuente histórico inmutable (se escribe una sola vez por producto
 * y nunca es alterado por reconstrucciones de índices).
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.60.0
 * @api
 */
final class Arrival {

	/** Meta: primera llegada real instock (timestamp UTC). */
	public const META_FIRST_INSTOCK_AT = '_akb_first_instock_at';

	/** Cap de productos backfilleados por corrida del Reconciler. */
	public const SEED_CAP = 200;

	/**
	 * Escucha los cambios de stock del producto padre. WooCommerce dispara el
	 * hook solo cuando el status realmente cambió — el "una sola vez" fino lo
	 * garantizan igual los guards por meta (idempotente ante replays).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_product_set_stock_status', array( self::class, 'on_stock_status' ), 10, 2 );
	}

	/**
	 * Transición de stock observada.
	 *
	 * @param int|string $product_id Producto.
	 * @param string     $status     Nuevo stock status (instock|outofstock).
	 * @return void
	 */
	public static function on_stock_status( $product_id, $status ): void {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		if ( 'instock' === $status
			&& '' === (string) get_post_meta( $product_id, self::META_FIRST_INSTOCK_AT, true )
		) {
			update_post_meta( $product_id, self::META_FIRST_INSTOCK_AT, (string) time() );
		}
	}

	/**
	 * Backfill: en modelo binario estricto (instock/outofstock) no hay preventas pendientes.
	 *
	 * @return int Productos marcados en esta corrida.
	 */
	public static function seed_pending(): int {
		return 0;
	}
}
