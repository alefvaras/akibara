<?php
/**
 * Vitrinas de portada con selección diversificada por pa_serie.
 * akb_destacados selecciona instock por categoría y orden solicitado.
 * El módulo selecciona IDs y delega el render a products ids con post__in,
 * conservando tarjetas, precios y botones nativos. No usa visibility=instock,
 * que no es un valor admitido por el shortcode WooCommerce.
 * Las selecciones se cachean una hora mediante claves versionadas.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\HomeDestacados;

use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookNotFound
 */
// @phpstan-ignore complexity.classLike
final class HomeDestacados {

	/**
	 * Testigo que versiona las claves de ambas vitrinas.
	 *
	 * Las dos listas se cacheaban una hora sin invalidación de ninguna clase, y
	 * la portada SÍ se purga cuando cambia un producto: el resultado era que la
	 * página se reconstruía entera para volver a pintar la misma selección vieja.
	 * Publicar una preventa, agotar un tomo o esconder un producto del catálogo
	 * no se veía en la portada hasta que el transient caducaba solo.
	 *
	 * Se versiona la clave en vez de borrar transients porque las claves llevan
	 * los atributos del shortcode: no hay una lista finita que enumerar sin
	 * recorrer `wp_options` con un LIKE.
	 */
	private const CACHE_GENERATION_OPTION = 'akb_home_destacados_generation';

	/** @var bool Bandera de proceso para evitar escrituras redundantes a wp_options en la misma petición. */
	private bool $bumped = false;

	public function register(): void {
		add_shortcode( 'akb_preventas_destacadas', array( $this, 'render_a_pedido_destacados' ) );
		add_shortcode( 'akb_destacados', array( $this, 'render_destacados' ) );

		// Todo lo que puede cambiar QUÉ productos entran en las vitrinas: alta,
		// edición, borrado, stock, ventas y visibilidad/categoría/serie.
		add_action( 'woocommerce_new_product', array( $this, 'bump_cache_generation' ), 10, 0 );
		add_action( 'woocommerce_update_product', array( $this, 'bump_cache_generation' ), 10, 0 );
		add_action( 'woocommerce_delete_product', array( $this, 'bump_cache_generation' ), 10, 0 );
		add_action( 'woocommerce_trash_product', array( $this, 'bump_cache_generation' ), 10, 0 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'bump_cache_generation' ), 10, 0 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'bump_cache_generation' ), 10, 0 );
		add_action( 'woocommerce_updated_product_sales', array( $this, 'bump_cache_generation' ), 10, 0 );
		add_action( 'woocommerce_recorded_sales', array( $this, 'bump_cache_generation' ), 10, 0 );
		add_action( 'transition_post_status', array( $this, 'maybe_bump_on_post_transition' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'maybe_bump_on_terms' ), 10, 6 );
	}

	/**
	 * Normaliza atributos de shortcode rescatando entradas con comillas tipográficas
	 * o entidades HTML (p. ej. `»`, `«`, `“`, `”`, `″`, `′`, `&Prime;`) incluso cuando
	 * WordPress los entregó como tokens posicionales `clave=valor`.
	 *
	 * @param array<int|string, mixed>|string $atts Atributos crudos del shortcode.
	 * @return array<string, string>
	 */
	private function clean_shortcode_atts( $atts ): array {
		if ( ! is_array( $atts ) ) {
			return array();
		}

		$typographic = array( '»', '«', '“', '”', '„', '‟', '″', '′', '‘', '’', '‹', '›', '"', "'" );
		$cleaned     = array();

		foreach ( $atts as $key => $val ) {
			if ( ! is_scalar( $val ) ) {
				continue;
			}
			$raw = html_entity_decode( (string) $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$raw = (string) preg_replace( '/[\x{00a0}\x{200b}]+/u', ' ', $raw );
			if ( is_int( $key ) ) {
				$eq = strpos( $raw, '=' );
				if ( false !== $eq && $eq > 0 ) {
					$raw_key    = trim( str_replace( $typographic, '', substr( $raw, 0, $eq ) ) );
					$parsed_key = function_exists( 'sanitize_key' )
						? sanitize_key( $raw_key )
						: strtolower( (string) preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw_key ) );
					$parsed_val = trim( str_replace( $typographic, '', substr( $raw, $eq + 1 ) ) );
					if ( '' !== $parsed_key ) {
						$cleaned[ $parsed_key ] = $parsed_val;
					}
				}
				continue;
			}

			$raw_key   = trim( str_replace( $typographic, '', $key ) );
			$clean_key = function_exists( 'sanitize_key' )
				? sanitize_key( $raw_key )
				: strtolower( (string) preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw_key ) );
			if ( '' !== $clean_key ) {
				$cleaned[ $clean_key ] = trim( str_replace( $typographic, '', $raw ) );
			}
		}

		return $cleaned;
	}

	/**
	 * Shortcode akb_preventas_destacadas con count, columns y max_per_serie.
	 * Selecciona reservables por _stock_status=onbackorder y fecha descendente,
	 * con diversidad de pa_serie. No distingue A pedido de Preventa dentro del
	 * grupo ni utiliza una categoría comercial como fuente del stock.
	 * Muestrea hasta cien candidatos antes de aplicar el límite por serie.
	 *
	 * @param array<int|string, mixed>|string $atts Atributos del shortcode.
	 * @return string HTML rendered, vacío si no hay reservables.
	 */
	public function render_a_pedido_destacados( $atts ): string {
		$atts = shortcode_atts(
			array(
				'count'         => 8,
				'columns'       => 4,
				'max_per_serie' => 2,
			),
			$this->clean_shortcode_atts( $atts ),
			'akb_preventas_destacadas'
		);

		$count_int         = max( 1, (int) $atts['count'] );
		$columns_int       = max( 1, (int) $atts['columns'] );
		$max_per_serie_int = max( 1, (int) $atts['max_per_serie'] );

		// Bump v1 → v2: cambió la fuente de verdad de tax_query a meta_query.
		// Cache key versionado invalida transients viejos automáticamente.
		$cache_key = sprintf(
			'akb_preventas_destacadas_v2_%s_%d_%d_%d',
			$this->cache_generation(),
			$count_int,
			$columns_int,
			$max_per_serie_int
		);
		$ids_csv = get_transient( $cache_key );

		if ( false === $ids_csv ) {
			$candidate_ids = get_posts(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'posts_per_page'         => 100,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'fields'                 => 'ids',
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_stock_status',
							'value'   => StockStatus::ON_BACKORDER,
							'compare' => '=',
						),
					),
					'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => 'product_visibility',
							'field'    => 'name',
							'terms'    => array( 'exclude-from-catalog' ),
							'operator' => 'NOT IN',
						),
					),
					'update_post_meta_cache' => false,
				)
			);

			if ( empty( $candidate_ids ) ) {
				if ( empty( $GLOBALS['wpdb']->last_error ) ) {
					set_transient( $cache_key, '__EMPTY__', HOUR_IN_SECONDS );
				}
				return '';
			}

			$picked = $this->pick_diversified_ids( $candidate_ids, $max_per_serie_int, $count_int );

			if ( empty( $picked ) ) {
				if ( empty( $GLOBALS['wpdb']->last_error ) ) {
					set_transient( $cache_key, '__EMPTY__', HOUR_IN_SECONDS );
				}
				return '';
			}

			$ids_csv = implode( ',', $picked );
			set_transient( $cache_key, $ids_csv, HOUR_IN_SECONDS );
		}

		if ( '__EMPTY__' === $ids_csv || '' === trim( (string) $ids_csv ) ) {
			return '';
		}

		return do_shortcode(
			sprintf(
				'[products ids="%s" columns="%d" orderby="post__in"]',
				esc_attr( (string) $ids_csv ),
				$columns_int
			)
		);
	}

	/**
	 * Shortcode `[akb_destacados category="" count=8 columns=4 orderby="popularity|date" max_per_serie=1]`.
	 *
	 * Productos en stock (estricto: excluye outofstock y
	 * onbackorder, ya cubiertos por la sección Preventas), excluyendo además
	 * `exclude-from-catalog` (visibility WC nativa).
	 *
	 * - `orderby="popularity"` ordena por `total_sales` DESC con desempate por
	 *   fecha DESC (productos nuevos rompen empate sobre IDs históricos).
	 * - `orderby="date"` ordena por fecha de publicación DESC.
	 * - `max_per_serie=1` por defecto: diversidad máxima en vitrina (8 series
	 *   distintas en 8 slots). En home el contexto es "descubrimiento", no
	 *   "continuidad" (para eso está el archive `/pa-serie/{slug}/`).
	 *
	 * @param array<string, mixed>|string $atts
	 * @return string HTML rendered, vacío si no hay productos.
	 */
	public function render_destacados( $atts ): string {
		$atts = shortcode_atts(
			array(
				'category'      => '',
				'count'         => 8,
				'columns'       => 4,
				'orderby'       => 'popularity',
				'max_per_serie' => 1,
			),
			$this->clean_shortcode_atts( $atts ),
			'akb_destacados'
		);

		$count_int         = max( 1, (int) $atts['count'] );
		$columns_int       = max( 1, (int) $atts['columns'] );
		$max_per_serie_int = max( 1, (int) $atts['max_per_serie'] );
		$orderby           = in_array( (string) $atts['orderby'], array( 'popularity', 'date' ), true )
			? (string) $atts['orderby']
			: 'popularity';
		$category          = is_string( $atts['category'] ) ? trim( $atts['category'] ) : '';
		$category_key      = '' !== $category ? sanitize_key( $category ) : 'all';

		$cache_key = sprintf(
			'akb_destacados_v1_%s_%s_%s_%d_%d_%d',
			$this->cache_generation(),
			$orderby,
			$category_key,
			$count_int,
			$columns_int,
			$max_per_serie_int
		);
		$ids_csv = get_transient( $cache_key );

		if ( false === $ids_csv ) {
			$args = array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'fields'                 => 'ids',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_stock_status',
						'value'   => StockStatus::IN_STOCK,
						'compare' => '=',
					),
				),
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => array( 'exclude-from-catalog' ),
						'operator' => 'NOT IN',
					),
				),
				'update_post_meta_cache' => false,
			);

			if ( '' !== $category ) {
				$args['tax_query'][] = array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => array( $category ),
				);
			}

			if ( 'popularity' === $orderby ) {
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = array(
					'meta_value_num' => 'DESC',
					'date'           => 'DESC',
				);
			} else {
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
			}

			$candidate_ids = get_posts( $args );

			if ( empty( $candidate_ids ) ) {
				if ( empty( $GLOBALS['wpdb']->last_error ) ) {
					set_transient( $cache_key, '__EMPTY__', HOUR_IN_SECONDS );
				}
				return '';
			}

			$picked = $this->pick_diversified_ids( $candidate_ids, $max_per_serie_int, $count_int );

			if ( empty( $picked ) ) {
				if ( empty( $GLOBALS['wpdb']->last_error ) ) {
					set_transient( $cache_key, '__EMPTY__', HOUR_IN_SECONDS );
				}
				return '';
			}

			$ids_csv = implode( ',', $picked );
			set_transient( $cache_key, $ids_csv, HOUR_IN_SECONDS );
		}

		if ( '__EMPTY__' === $ids_csv || '' === trim( (string) $ids_csv ) ) {
			return '';
		}

		return do_shortcode(
			sprintf(
				'[products ids="%s" columns="%d" orderby="post__in"]',
				esc_attr( (string) $ids_csv ),
				$columns_int
			)
		);
	}

	/**
	 * Selecciona IDs respetando un cap de productos por `pa_serie`.
	 *
	 * Compartido entre `render_a_pedido_destacados` y `render_destacados`.
	 * Productos sin `pa_serie` se agrupan bajo la clave `sin-serie` (en
	 * Akibara hoy: 0/1368 productos, pero el bucket es defensivo).
	 *
	 * @param array<int, int|string> $candidate_ids IDs ordenados (orden de preferencia).
	 * @param int                    $max_per_serie Máximo de productos por serie.
	 * @param int                    $count         Total a seleccionar.
	 * @return array<int, int>
	 */
	private function pick_diversified_ids( array $candidate_ids, int $max_per_serie, int $count ): array { // @phpstan-ignore complexity.functionLike
		$picked     = array();
		$counts_per = array();

		// Primar POSTS y términos de todos los candidatos en dos queries, no
		// término por término.
		//
		// El cebado de términos a secas NO evitaba el N+1, y el comentario que
		// decía lo contrario sobrevivió a la medición: `get_the_terms()` empieza
		// por `get_post( $pid )`, y la consulta de arriba pide `fields => 'ids'`,
		// así que NINGÚN post está en caché — eran 100 `SELECT * FROM wp_posts
		// WHERE ID = %d` por render de vitrina, justo debajo del comentario que
		// prometía cero. `_prime_post_caches` los trae en una sola consulta y de
		// paso ceba los términos (segundo argumento), que es lo que el llamado
		// suelto hacía. Meta cache OFF: la vitrina no lee postmeta.
		$ids = array_map( 'intval', $candidate_ids );
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, true, false );
		} elseif ( function_exists( 'update_object_term_cache' ) ) {
			update_object_term_cache( $ids, 'product' );
		}

		foreach ( $ids as $pid ) {
			// Consulta los términos recién primados sin pasar por get_the_terms.
			// El filtro de Rank Math puede consultar la serie primaria por candidato;
			// la selección sólo necesita el término de serie para diversificar.
			$serie_terms = function_exists( 'get_object_term_cache' )
				? get_object_term_cache( $pid, 'pa_serie' )
				: false;
			if ( false === $serie_terms ) {
				$serie_terms = get_the_terms( $pid, 'pa_serie' );
			}
			$serie = 'sin-serie';
			if ( is_array( $serie_terms ) && ! empty( $serie_terms ) ) {
				$first = reset( $serie_terms );
				if ( is_object( $first ) && isset( $first->slug ) ) { // @phpstan-ignore isset.property
					$serie = (string) $first->slug;
				}
			}

			$current = $counts_per[ $serie ] ?? 0;
			if ( $current >= $max_per_serie ) {
				continue;
			}

			$picked[]             = $pid;
			$counts_per[ $serie ] = $current + 1;

			if ( count( $picked ) >= $count ) {
				break;
			}
		}

		// Rellena en una segunda pasada los huecos relajando el límite por serie.
		// Sólo repite series cuando no quedan nuevas, manteniendo el orden original
		// de candidatos del shortcode.
		if ( count( $picked ) < $count ) {
			$ya = array_flip( $picked );
			foreach ( $ids as $pid ) {
				if ( isset( $ya[ $pid ] ) ) {
					continue;
				}
				$picked[]    = $pid;
				$ya[ $pid ]  = true;
				if ( count( $picked ) >= $count ) {
					break;
				}
			}
		}

		return $picked;
	}

	/**
	 * Marca las vitrinas como caducadas.
	 *
	 * Sólo escribe una option corta; NO purga páginas. La purga de la portada ya
	 * la dispara quien corresponde, y encadenar un `purge_all` aquí lo pondría a
	 * correr en cada venta (`total_sales` cambia y con él `woocommerce_update_product`).
	 * Protegido contra escrituras redundantes dentro de una misma petición.
	 *
	 * @param bool $force Si es true, fuerza la escritura incluso si ya se ejecutó en la petición.
	 * @return void
	 */
	public function bump_cache_generation( bool $force = false ): void {
		if ( $this->bumped && ! $force ) {
			return;
		}
		$gen = str_replace( '.', '', sprintf( '%.6F', microtime( true ) ) );
		update_option( self::CACHE_GENERATION_OPTION, $gen, false );
		$this->bumped = true;
	}

	/**
	 * Dispara invalidación de vitrinas ante cambios de estado de un producto.
	 *
	 * @param string   $new_status Nuevo estado.
	 * @param string   $old_status Estado anterior.
	 * @param \WP_Post $post       Post afectado.
	 * @return void
	 */
	public function maybe_bump_on_post_transition( string $new_status, string $old_status, $post ): void {
		if ( $new_status === $old_status || ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return;
		}
		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			$this->bump_cache_generation();
		}
	}

	/**
	 * Dispara invalidación cuando cambian categoría, serie o visibilidad de un producto.
	 *
	 * @param int               $object_id  ID del objeto.
	 * @param array<int, mixed> $terms      Términos asignados.
	 * @param array<int, int>   $tt_ids     IDs de term_taxonomy.
	 * @param string            $taxonomy   Taxonomía.
	 * @param bool              $append     Si agrega en vez de reemplazar.
	 * @param array<int, int>   $old_tt_ids Términos anteriores.
	 * @return void
	 */
	public function maybe_bump_on_terms( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		unset( $object_id, $terms, $tt_ids, $append, $old_tt_ids );
		if ( in_array( $taxonomy, array( 'product_cat', 'pa_serie', 'product_visibility' ), true ) ) {
			$this->bump_cache_generation();
		}
	}

	/**
	 * Testigo actual, saneado antes de entrar a una clave de transient.
	 */
	private function cache_generation(): string {
		$raw = get_option( self::CACHE_GENERATION_OPTION, '0' );
		$raw = is_scalar( $raw ) ? (string) $raw : '0';

		return substr( (string) preg_replace( '/[^A-Za-z0-9]/', '', $raw ), 0, 24 );
	}
}
