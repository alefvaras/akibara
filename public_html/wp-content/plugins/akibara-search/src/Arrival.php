<?php
/**
 * Arrival — captura del primer cambio de estado `onbackorder → instock` por producto.
 *
 * Registra de forma persistente los hitos cronológicos de ciclo de vida del producto:
 *   - `_akb_onbackorder_at`: instante en que el producto entró por primera vez a preventa.
 *   - `_akb_first_instock_at`: instante de primera llegada física a bodega (`onbackorder → instock`).
 *
 * Ambas marcas temporales representan datos fuente históricos inmutables (se escriben una
 * sola vez por producto y nunca son alteradas por reconstrucciones de índices).
 *
 * Poblamiento inicial (`seed_pending()`): sincronizado automáticamente durante la reconciliación
 * periódica del Reconciler para productos que ya se encontraban en preventa.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.60.0
 */
final class Arrival {

	/** Meta: primer momento observado en preventa (timestamp UTC). */
	public const META_ONBACKORDER_AT = '_akb_onbackorder_at';

	/** Meta: primera llegada real onbackorder→instock (timestamp UTC). */
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
	 * @param string     $status     Nuevo stock status (instock|outofstock|onbackorder).
	 * @return void
	 */
	public static function on_stock_status( $product_id, $status ): void {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}

		if ( 'onbackorder' === $status ) {
			if ( '' === (string) get_post_meta( $product_id, self::META_ONBACKORDER_AT, true ) ) {
				update_post_meta( $product_id, self::META_ONBACKORDER_AT, (string) time() );
			}
			return;
		}

		if ( 'instock' === $status
			&& '' !== (string) get_post_meta( $product_id, self::META_ONBACKORDER_AT, true )
			&& '' === (string) get_post_meta( $product_id, self::META_FIRST_INSTOCK_AT, true )
		) {
			update_post_meta( $product_id, self::META_FIRST_INSTOCK_AT, (string) time() );
		}
	}

	/**
	 * Backfill: marca `_akb_onbackorder_at` en productos publicados que YA
	 * están en preventa pero no tienen la meta (los que entraron a preventa
	 * antes de que este módulo existiera — el caso masivo es el cutover).
	 * Cap por corrida; converge en pocas corridas diarias y después cuesta un
	 * SELECT vacío. API de WP (cache-aware), no SQL directo a postmeta.
	 *
	 * @return int Productos marcados en esta corrida.
	 */
	public static function seed_pending(): int {
		if ( ! function_exists( 'get_posts' ) ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => self::SEED_CAP,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- corrida diaria async del Reconciler, capada.
				'meta_query'     => array(
					array(
						'key'   => '_stock_status',
						'value' => 'onbackorder',
					),
					array(
						'key'     => self::META_ONBACKORDER_AT,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$now = (string) time();
		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, self::META_ONBACKORDER_AT, $now );
		}

		return count( $ids );
	}
}
