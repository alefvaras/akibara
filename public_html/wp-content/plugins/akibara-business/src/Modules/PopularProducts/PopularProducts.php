<?php
/**
 * PopularProducts — provee los IDs de los productos más vendidos para los
 * cross-sells del theme (404, carrito vacío, sin resultados de búsqueda).
 *
 * La query (best-sellers por `total_sales`, comprables, no excluidos de catálogo)
 * vivía en el theme (`inc/performance.php`), violando la regla "theme = solo UI".
 * Acá vive la lógica; el theme la consume vía el filtro `akibara/popular_product_ids`
 * con fallback, así degrada elegante si el plugin está inactivo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\PopularProducts;

use Akibara\Business\Modules\Recommendations\Picker;
use Akibara\Business\Modules\Recommendations\SeriesShelf;
use Akibara\Business\Modules\Recommendations\Settings;
use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

final class PopularProducts {

	/**
	 * Prefijo del transient (1 h). v4: la lista pasó a una tarjeta por serie
	 * (UC-13) y la clave lleva el modo, así que cambiarlo en el panel no sirve
	 * la lista del modo anterior.
	 */
	private const CACHE_PREFIX = 'akb_popular_cross_sell_ids_v4';

	/**
	 * Amplía el conjunto de más vendidos para ofrecer una tarjeta por serie.
	 *
	 * Solicita un margen de candidatos sin cargar el catálogo completo. Si no hay
	 * series distintas suficientes, Picker::una_por_serie() completa los huecos con
	 * los productos repetidos en el orden recibido.
	 */
	private const CANDIDATOS_POR_TARJETA = 4;

	/**
	 * TTL del resultado VACÍO.
	 *
	 * Corto a propósito: la lista vacía casi siempre significa que algo pasajero
	 * la dejó así (un import a medias, un barrido de stock, una purga de
	 * `total_sales`), y con la hora completa del caso normal las tres superficies
	 * que la consumen —404, carrito vacío y «sin resultados»— se quedaban 60
	 * minutos SIN cross-sells sin que nada volviera a preguntar.
	 */
	private const EMPTY_CACHE_SECONDS = 120;

	public function register(): void {
		add_filter( 'akibara/popular_product_ids', array( $this, 'get_ids' ), 10, 2 );

		// La lista se cachea por horas y NADA la invalidaba: agotar el best-seller
		// dejaba su tarjeta viva hasta que el transient caducaba solo, y borrar el
		// producto en firme dejaba una tarjeta que enlaza a la nada.
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'flush' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'flush' ) );
		add_action( 'before_delete_post', array( $this, 'flush_for_deleted_post' ), 10, 2 );
	}

	/**
	 * `before_delete_post` dispara para TODO tipo de contenido; sólo el borrado
	 * en firme de un producto puede dejar una tarjeta enlazando a la nada.
	 *
	 * @param int          $post_id ID borrado.
	 * @param \WP_Post|null $post   Post borrado (WP 5.5+; null en llamadas legacy).
	 * @return void
	 */
	public function flush_for_deleted_post( $post_id, $post = null ): void {
		$type = $post instanceof \WP_Post
			? $post->post_type
			: (string) get_post_type( (int) $post_id );

		if ( 'product' !== $type && 'product_variation' !== $type ) {
			return;
		}

		$this->flush();
	}

	/**
	 * IDs de los N productos más vendidos, cacheados 1 h.
	 *
	 * @param mixed $fallback Valor por defecto del filtro (se ignora; lo provee este módulo).
	 * @param int   $limit    Cantidad de productos.
	 * @return int[]
	 */
	public function get_ids( $fallback = array(), int $limit = 4 ): array {
		$limit = max( 1, $limit );
		$modo  = Settings::current( Settings::OPTION_MAS_VENDIDOS );
		$cache = self::cache_key( $limit, $modo );

		$ids = get_transient( $cache );
		if ( false !== $ids ) {
			return array_map( 'intval', (array) $ids );
		}

		$una_por_serie = Settings::MAS_VENDIDOS_UNA_POR_SERIE === $modo;
		$query         = new \WP_Query( self::query_args( $una_por_serie ? $limit * self::CANDIDATOS_POR_TARJETA : $limit ) );

		$ids = array_map( 'intval', (array) $query->posts );
		if ( $una_por_serie && array() !== $ids ) {
			$ids = Picker::una_por_serie( $ids, self::series_by_id( $ids ), $limit );
		}
		set_transient( $cache, $ids, array() === $ids ? self::EMPTY_CACHE_SECONDS : HOUR_IN_SECONDS );

		return $ids;
	}

	/**
	 * Resuelve las series principales tras cebar posts, metadatos y términos.
	 *
	 * fields => ids no hidrata esos tres conjuntos. SeriesShelf::prime() los prepara
	 * en lote para que get_the_terms(), que también consulta el post, no provoque
	 * lecturas por candidato. RelatedProducts y UpsellsDisplay comparten este cebado.
	 *
	 * @param array<int, int> $ids Productos.
	 * @return array<int, int|null>
	 */
	private static function series_by_id( array $ids ): array {
		SeriesShelf::prime( $ids );

		$series = array();
		foreach ( $ids as $id ) {
			$series[ $id ] = SeriesShelf::serie_of( $id );
		}

		return $series;
	}

	/**
	 * Argumentos de la consulta de best-sellers.
	 *
	 * Público y aparte del `new WP_Query` para que el criterio —sobre todo el de
	 * stock, que es donde estaba el defecto— se pueda leer y probar sin montar
	 * una consulta.
	 *
	 * @param int $limit Cantidad de productos.
	 * @return array<string, mixed>
	 */
	public static function query_args( int $limit ): array {
		return array(
			'post_type'           => 'product',
			'posts_per_page'      => max( 1, $limit ),
			'post_status'         => 'publish',
			'meta_key'            => 'total_sales',
			'orderby'             => 'meta_value_num',
			'order'               => 'DESC',
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
					'operator' => 'NOT IN',
				),
			),
			// Comprables, no «en bodega»: el filtro estricto a `instock` dejaba
			// fuera los 422 productos «A pedido» (onbackorder), que se venden
			// exactamente igual y son el 27% del catálogo. La única exclusión
			// legítima acá es `outofstock`, que no se puede comprar.
			'meta_query'          => array(
				array(
					'key'     => '_stock_status',
					'value'   => array( StockStatus::IN_STOCK, StockStatus::ON_BACKORDER ),
					'compare' => 'IN',
				),
			),
		);
	}

	/**
	 * Tira la lista cacheada.
	 *
	 * Barre los tamaños que las superficies piden de verdad; el transient no se
	 * puede enumerar sin un LIKE sobre `wp_options`, y el theme llama con 4 (404,
	 * carrito vacío) y 6 («sin resultados»). Un tamaño nuevo se agrega acá.
	 *
	 * @return void
	 */
	public function flush(): void {
		foreach ( array( Settings::MAS_VENDIDOS_UNA_POR_SERIE, Settings::MAS_VENDIDOS_TAL_CUAL ) as $modo ) {
			foreach ( array( 1, 2, 3, 4, 5, 6, 8, 12 ) as $limit ) {
				delete_transient( self::cache_key( $limit, $modo ) );
			}
		}
	}

	private static function cache_key( int $limit, string $modo ): string {
		return self::CACHE_PREFIX . '_' . $modo . '_' . $limit;
	}
}
