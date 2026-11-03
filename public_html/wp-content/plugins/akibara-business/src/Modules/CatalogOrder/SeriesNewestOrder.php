<?php
/**
 * En archivos pa_serie, menu_order representa Novedades primero: ordena
 * por recencia con agotados al final, independientemente de la vitrina global.
 * Lee el stock vigente y no espera al recálculo de menu_order. La tienda,
 * las categorías y las editoriales conservan su orden Destacados propio.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\CatalogOrder;

use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

final class SeriesNewestOrder {

	/** Taxonomía de las landings de serie. */
	private const TAXONOMY = 'pa_serie';

	/**
	 * Orden de WordPress que pide la traducción: fecha y, a igual fecha, ID.
	 *
	 * Es también la marca con la que `sold_out_last()` reconoce su consulta: si
	 * otro código cambió el orden después (el buscador pone `post__in` para
	 * ordenar por relevancia), la cláusula no se toca.
	 */
	private const NEWEST_ORDERBY = 'date ID';

	public function register(): void {
		// Prioridad 20: akibara-business.php resuelve `title` en la 10 y no
		// debe ver este cambio; sólo se traduce `menu_order`.
		add_filter( 'woocommerce_get_catalog_ordering_args', array( $this, 'translate_ordering_args' ), 20, 2 );
	}

	/**
	 * Traduce «Novedades primero» (`menu_order`) a fecha DESC en `pa_serie`.
	 *
	 * Se decide por el `$orderby` que ya resolvió WooCommerce (de `?orderby` o
	 * del orden por defecto), no releyendo `$_GET`: así un shortcode que pida su
	 * propio orden en la misma página no cae aquí por accidente.
	 *
	 * @internal Callback de `woocommerce_get_catalog_ordering_args`.
	 *
	 * @param mixed $args    Argumentos de orden de WooCommerce.
	 * @param mixed $orderby Clave de orden resuelta.
	 * @return mixed
	 */
	public function translate_ordering_args( $args, $orderby ) {
		if ( ! is_array( $args ) || 'menu_order' !== $orderby || ! self::is_series_archive() ) {
			return $args;
		}

		// Lo agotado al final necesita una condición que `WP_Query` no sabe
		// expresar con `orderby`: se añade a la cláusula ORDER BY de la consulta
		// principal, igual que WooCommerce hace con precio y popularidad.
		add_filter( 'posts_orderby', array( $this, 'sold_out_last' ), 10, 2 );

		return array(
			'orderby'  => self::NEWEST_ORDERBY,
			'order'    => 'DESC',
			'meta_key' => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- se VACÍA: no ordena por meta.
		) + $args;
	}

	/**
	 * Antepone «agotado al final» al ORDER BY de la consulta principal.
	 *
	 * `EXISTS` y no un JOIN: un producto con dos filas de `_stock_status` no se
	 * duplica en la rejilla, y cuenta como agotado si alguna lo dice — la misma
	 * lectura que hace el recálculo de la vitrina (`MAX(meta_value)`). Sin fila
	 * cuenta como vendible, que es lo que WooCommerce muestra en la tarjeta.
	 *
	 * Se retira sola al aplicarse: afecta a UNA consulta, la del archivo.
	 *
	 * @internal Callback de `posts_orderby`, registrado por `translate_ordering_args()`.
	 *
	 * @param mixed $orderby Cláusula ORDER BY armada por WordPress.
	 * @param mixed $query   Consulta en curso.
	 * @return mixed
	 */
	public function sold_out_last( $orderby, $query ) {
		if ( ! is_string( $orderby ) || '' === $orderby || ! $query instanceof \WP_Query
			|| ! $query->is_main_query() || self::NEWEST_ORDERBY !== $query->get( 'orderby' ) ) {
			return $orderby;
		}

		remove_filter( 'posts_orderby', array( $this, 'sold_out_last' ), 10 );

		global $wpdb;

		// El estado sale de StockStatus y entra por `prepare()`, no escrito dentro
		// del SQL: un literal en una cadena interpolada esquivaba el candado de
		// literales de stock (revisión B1-REV3-1). Sólo se prepara el criterio
		// propio; el ORDER BY de WordPress se concatena intacto, sin pasar por el
		// escapado de `%`.
		$agotado = $wpdb->prepare(
			"EXISTS ( SELECT 1 FROM {$wpdb->postmeta} akb_agotado WHERE akb_agotado.post_id = {$wpdb->posts}.ID AND akb_agotado.meta_key = %s AND akb_agotado.meta_value = %s ) ASC, ",
			'_stock_status',
			StockStatus::OUT_OF_STOCK
		);

		return $agotado . $orderby;
	}

	/**
	 * ¿Es la consulta de una landing de serie (también paginada o por PJAX)?
	 */
	private static function is_series_archive(): bool {
		return function_exists( 'is_tax' ) && is_tax( self::TAXONOMY );
	}
}
