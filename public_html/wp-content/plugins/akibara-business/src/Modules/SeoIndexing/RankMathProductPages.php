<?php
/**
 * La ventana de productos de UNA página del sitemap, tal como la corta Rank Math.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoIndexing;

defined( 'ABSPATH' ) || exit;

/**
 * Expone la consulta paginada del proveedor de Rank Math sin copiarla.
 *
 * `Post_Type::get_posts()` es `protected` y es la ÚNICA fuente fiel de qué
 * productos caen en `product-sitemapN.xml`: aplica el `noindex` por meta, el
 * `post_password`, el JOIN de canónicas y el filtro `sitemap/get_posts/where`
 * (donde este mismo módulo cuelga su marcador de orden estable). Replicar ese
 * SQL a mano se desalinea en cuanto Rank Math cambie una cláusula (revisión
 * R8, H3); heredar la clase no toca el tercero y sigue a su versión.
 *
 * El método público `get_sitemap_links()` NO sirve para esto: llama a
 * `Sitemap::maybe_redirect()`, que fuera de una petición de página redirige y
 * muere. Esta clase sólo se instancia cuando el padre existe (Rank Math activo).
 *
 * @since 1.116.32
 */
final class RankMathProductPages extends \RankMath\Sitemap\Providers\Post_Type {

	/**
	 * Filas de la ventana [offset, offset+count) del sitemap de producto.
	 *
	 * @param int $count  Tamaño de página.
	 * @param int $offset Desplazamiento.
	 * @return array<int, object> Objetos con `ID`, `post_modified_gmt` y `post_date_gmt`.
	 */
	public function page_posts( int $count, int $offset ): array {
		$posts = $this->get_posts( 'product', $count, $offset );

		return is_array( $posts ) ? $posts : array();
	}
}
