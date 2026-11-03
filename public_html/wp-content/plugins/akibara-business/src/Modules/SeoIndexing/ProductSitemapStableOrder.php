<?php
/**
 * Stable product ordering for paginated Rank Math sitemaps.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoIndexing;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a deterministic tie-breaker to Rank Math's product sitemap query.
 *
 * Rank Math orders the inner product query only by `post_modified`. Multiple
 * products can share that value down to the second, so LIMIT/OFFSET pages may
 * overlap or omit products. Rank Math does not expose an order-by filter here:
 * this module marks only its product WHERE clause through the official filter
 * and then applies one exact, fail-safe replacement through WordPress' `query`
 * filter. If Rank Math changes the SQL shape, the query remains untouched.
 *
 * @since 1.93.0
 */
final class ProductSitemapStableOrder {

	/**
	 * Unique marker added only to the Rank Math product sitemap query.
	 */
	public const QUERY_MARKER = '/* akibara:stable-product-sitemap-order */';

	private const RANK_MATH_ORDER = 'ORDER BY p.post_modified DESC LIMIT';
	private const STABLE_ORDER    = 'ORDER BY p.post_modified DESC, p.ID DESC LIMIT';

	/**
	 * Si el filtro `query` ya se enganchó en esta petición.
	 *
	 * @var bool
	 */
	private bool $query_filter_added = false;

	/**
	 * Lector de la ventana de una página: `fn( int $count, int $offset): array`
	 * de objetos con `ID`, `post_modified_gmt`, `post_date_gmt`. Inyectable
	 * para las pruebas; en producción es {@see RankMathProductPages}.
	 *
	 * @var callable|null
	 */
	private $page_reader;

	/**
	 * `fn( int $post_id): bool`: lo que Rank Math descarta DESPUÉS de cortar
	 * la ventana (`Sitemap::is_object_indexable()`: exclusiones y noindex).
	 *
	 * @var callable|null
	 */
	private $indexable;

	/**
	 * @param callable|null $page_reader Ver {@see $page_reader}.
	 * @param callable|null $indexable   Ver {@see $indexable}.
	 */
	public function __construct( ?callable $page_reader = null, ?callable $indexable = null ) {
		$this->page_reader = $page_reader;
		$this->indexable   = $indexable;
	}

	/**
	 * Registra los filtros acotados al sitemap de productos y su invalidación.
	 *
	 * El filtro global query sólo se instala cuando Rank Math construye el WHERE
	 * de product. No se ejecuta sobre todas las consultas del frontend para buscar
	 * una operación que pertenece exclusivamente al sitemap.
	 *
	 * @since 1.93.0
	 */
	public function register(): void {
		add_filter( 'rank_math/sitemap/get_posts/where', array( $this, 'mark_product_sitemap_where' ), 10, 2 );
		add_filter( 'rank_math/sitemap/index/entry', array( $this, 'handle_sitemap_index_entry' ), 20, 3 );
		add_action( 'akibara_business_upgraded', array( $this, 'invalidate_product_sitemap_cache' ) );
	}

	/** Página del índice de sitemaps de producto, o 0 si la entrada no es de producto. */
	private const INDEX_LOC_PATTERN = '#/product-sitemap(\d*)\.xml$#';

	/**
	 * Calcula lastmod para la ventana real de cada sitemap de productos.
	 *
	 * Usa la consulta, tamaño de página y exclusiones de RankMathProductPages.
	 * Una fila descartada por is_object_indexable() ocupa posición en la ventana,
	 * aunque no se imprima en XML. Cada fecha coincide con get_url(): el máximo
	 * entre post_modified_gmt y post_date_gmt de las filas publicadas en esa página.
	 *
	 * @param mixed $url       Entrada del índice (`loc`, `lastmod`).
	 * @param mixed $type      'post' | 'term' | …
	 * @param mixed $post_type Tipo de contenido de la entrada.
	 * @return mixed
	 */
	public function handle_sitemap_index_entry( $url, $type, $post_type ) {
		if ( ! is_array( $url ) || 'post' !== $type || 'product' !== (string) $post_type || ! isset( $url['loc'] ) ) {
			return $url;
		}
		if ( 1 !== preg_match( self::INDEX_LOC_PATTERN, (string) $url['loc'], $m ) ) {
			return $url;
		}

		try {
			$page     = '' === $m[1] ? 1 : max( 1, (int) $m[1] );
			$per_page = self::links_per_page();
			$lastmod  = $this->page_lastmod_gmt( ( $page - 1 ) * $per_page, $per_page );
		} catch ( \Throwable $throwable ) {
			return $url; // Con Rank Math a medio cargar, mejor su fecha que ninguna.
		}
		if ( '' !== $lastmod ) {
			$url['lastmod'] = $lastmod;
		}

		return $url;
	}

	/**
	 * Enlaces por página del sitemap, como los lee el generador de Rank Math.
	 *
	 * `Helper::get_settings( 'sitemap.items_per_page', 100)` en
	 * `modules/sitemap/class-generator.php`; la option se llama
	 * `rank-math-options-sitemap` (revisión R8, H1: `rank_math_sitemap` no
	 * existe y el módulo usaba 200 fijo). El 100 es el mismo respaldo del
	 * generador cuando el ajuste nunca se guardó.
	 *
	 * @return int
	 */
	private static function links_per_page(): int {
		if ( class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'get_settings' ) ) {
			return max( 1, (int) \RankMath\Helper::get_settings( 'sitemap.items_per_page', 100 ) );
		}
		$settings = get_option( 'rank-math-options-sitemap', array() );
		$n        = is_array( $settings ) ? (int) ( $settings['items_per_page'] ?? 0 ) : 0;

		return $n > 0 ? $n : 100;
	}

	/**
	 * Obtiene la fecha más reciente de una página de productos del sitemap.
	 *
	 * El cálculo carga las ventanas que Rank Math sirve, con su contenido y caché
	 * de metadatos, sólo al construir un índice cuya caché fue invalidada. Se modela
	 * la exclusión de canónicas propias manteniendo su posición en la ventana.
	 * La página inicial puede añadir el archivo de tienda con la fecha global;
	 * el resultado debe seguir concordando con la ventana correspondiente.
	 *
	 * @param int $offset   Desplazamiento.
	 * @param int $per_page Tamaño de página.
	 * @return string ISO 8601 en UTC, o '' si no hay filas.
	 */
	private function page_lastmod_gmt( int $offset, int $per_page ): string {
		$reader = $this->page_reader;
		if ( null === $reader ) {
			if ( ! class_exists( '\RankMath\Sitemap\Providers\Post_Type' ) ) {
				return '';
			}
			$provider = new RankMathProductPages();
			$reader   = static fn( int $count, int $offset ): array => $provider->page_posts( $count, $offset );
		}
		$indexable = $this->indexable;
		if ( null === $indexable ) {
			$indexable = class_exists( '\RankMath\Sitemap\Sitemap' ) && method_exists( '\RankMath\Sitemap\Sitemap', 'is_object_indexable' )
				? static fn( int $post_id ): bool => (bool) \RankMath\Sitemap\Sitemap::is_object_indexable( $post_id )
				: static fn( int $post_id ): bool => true;
		}

		$max = 0;
		foreach ( $reader( $per_page, $offset ) as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->ID ) || ! $indexable( (int) $post->ID ) ) {
				continue;
			}
			// `get_url()` devuelve false —la fila no sale en el hijo— cuando la
			// ficha declara una canónica distinta de sí misma.
			if ( function_exists( 'get_post_meta' ) && '' !== trim( (string) get_post_meta( (int) $post->ID, 'rank_math_canonical_url', true ) ) ) {
				continue;
			}
			$modified = max( (string) ( $post->post_modified_gmt ?? '' ), (string) ( $post->post_date_gmt ?? '' ) );
			if ( '' === $modified || '0000-00-00 00:00:00' === $modified ) {
				continue;
			}
			$timestamp = strtotime( $modified . ' UTC' );
			if ( false !== $timestamp ) {
				$max = max( $max, $timestamp );
			}
		}

		return 0 === $max ? '' : gmdate( 'c', $max );
	}

	/**
	 * Marks only Rank Math requests whose post type is exactly `product`.
	 *
	 * @param mixed $where     Current extra WHERE clause.
	 * @param mixed $post_type Sitemap post type.
	 * @return string
	 *
	 * @since 1.93.0
	 */
	public function mark_product_sitemap_where( $where, $post_type ): string {
		$where = is_string( $where ) ? $where : '';

		if ( 'product' !== $post_type ) {
			return $where;
		}

		// Rank Math aplica este filtro justo antes de ejecutar la sentencia
		// (providers/class-post-type.php:408 → DB_Helper::get_results en:429),
		// así que engancharlo aquí llega a tiempo para esa misma consulta.
		$this->ensure_query_filter();

		if ( false !== strpos( $where, self::QUERY_MARKER ) ) {
			return $where;
		}

		return rtrim( $where ) . ' ' . self::QUERY_MARKER;
	}

	/**
	 * Engancha el filtro `query` una sola vez por petición.
	 *
	 * NO se desengancha tras la primera sustitución: una misma petición de
	 * sitemap puede construir más de una consulta de productos (índice y
	 * páginas), y quitarlo dejaría a las siguientes con el ORDER BY inestable
	 * de Rank Math, que es exactamente el defecto que este módulo existe para
	 * cerrar.
	 */
	private function ensure_query_filter(): void {
		if ( $this->query_filter_added ) {
			return;
		}

		$this->query_filter_added = true;
		add_filter( 'query', array( $this, 'stabilize_product_sitemap_query' ), 10 );
	}

	/**
	 * Adds the product ID tie-breaker only to the marked, expected SQL shape.
	 *
	 * Requiring exactly one occurrence prevents broad or ambiguous rewrites.
	 * The stable form no longer contains the original exact token, making this
	 * method idempotent if WordPress applies the filter more than once.
	 *
	 * @param mixed $query SQL about to run.
	 * @return string
	 *
	 * @since 1.93.0
	 */
	public function stabilize_product_sitemap_query( $query ): string {
		$query = is_string( $query ) ? $query : '';

		if (
			false === strpos( $query, self::QUERY_MARKER )
			|| 1 !== substr_count( $query, self::RANK_MATH_ORDER )
		) {
			return $query;
		}

		return str_replace( self::RANK_MATH_ORDER, self::STABLE_ORDER, $query );
	}

	/**
	 * Invalidates only the product sitemap after an Akibara Business upgrade.
	 *
	 * Rank Math regenerates it on demand with the deterministic order. The
	 * class check keeps upgrades safe when Rank Math is disabled or unavailable.
	 *
	 * @since 1.93.0
	 */
	public function invalidate_product_sitemap_cache(): void {
		if ( class_exists( '\RankMath\Sitemap\Cache' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage( 'product' );
			// El ÍNDICE se cachea aparte, bajo el tipo '1' (`Cache::get_storage_key()`
			// con tipo nulo), y `invalidate_storage('product')` no lo toca: sin
			// esto, tras un despliegue `sitemap_index.xml` seguía sirviendo los
			// `lastmod` viejos hasta el próximo guardado de producto (revisión R8,
			// 2.1). Rank Math hace lo mismo en su `Cache_Watcher`.
			\RankMath\Sitemap\Cache::invalidate_storage( '1' );
		}
	}
}
