<?php
/**
 * ProductSourceAuthority — hidratación observable de las fuentes de producto.
 *
 * WooCommerce y WordPress esconden algunos fallos de lectura detrás de caches
 * vacíos o retornos `false` (`wc_get_product()`, `update_object_term_cache()`).
 * Esta clase elimina primero cualquier foto previa, repuebla cada fuente por
 * una frontera cuyo error sí es observable y verifica la cobertura física del
 * cache antes de permitir que un WC_Product sea construido.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Una instancia se comparte entre presenter, indexer y reconciler durante el
 * request. La autoridad es sticky: el primer fallo cierra todas las lecturas
 * posteriores y conserva su diagnóstico original.
 *
 * @since 0.1.14
 */
final class ProductSourceAuthority {

	use Support\FailsClosed;

	/** @var \wpdb */
	private $db;

	/** @var callable|null Exclusivamente para dobles locales ya existentes. */
	private $test_reader;

	/** @var callable|null Lector de variaciones aislado, sólo para tests. */
	private $test_variation_reader;

	/** @var array<int, true> Padres cuyo transient de precio quedó expulsado y verificado. */
	private array $prepared_variable_prices = array();

	/**
	 * Foto cruda por producto/taxonomía usada para verificar los lectores
	 * públicos que DocumentBuilder ejecuta después del priming.
	 *
	 * @var array<int, array<string, array{direct: array<int, array{term_id:int,taxonomy:string,parent:int,slug:string,name:string}>, closure: array<int, array{term_id:int,taxonomy:string,parent:int,slug:string,name:string}>}>>
	 */
	private array $authoritative_product_terms = array();

	/** @var array<int, array<string, mixed>> */
	private array $authoritative_product_posts = array();

	/**
	 * @param \wpdb|null   $db          WordPress DB.
	 * @param callable|null $test_reader           Lector aislado habilitado sólo en tests.
	 * @param callable|null $test_variation_reader Variaciones aisladas habilitadas sólo en tests.
	 */
	public function __construct( $db = null, ?callable $test_reader = null, ?callable $test_variation_reader = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
		if ( defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING ) {
			$this->test_reader           = $test_reader;
			$this->test_variation_reader = $test_variation_reader;
		}
	}

	/**
	 * Canonicaliza la identidad de imagen que expone WooCommerce.
	 *
	 * Core admite ausencia como string vacío y los data stores habituales
	 * entregan IDs de meta como strings decimales. No se aceptan coerciones
	 * PHP más amplias (`false`, floats, espacios, signo ni ceros de relleno).
	 *
	 * @param mixed $raw Valor de WC_Product::get_image_id().
	 */
	public static function canonical_image_id( $raw ): ?int {
		if ( is_int( $raw ) ) {
			return $raw >= 0 ? $raw : null;
		}
		if ( '' === $raw ) {
			return 0;
		}
		if ( ! is_string( $raw ) || 1 !== preg_match( '/^(?:0|[1-9]\d*)$/D', $raw ) ) {
			return null;
		}
		$id = (int) $raw;
		return $id >= 0 && (string) $id === $raw ? $id : null;
	}

	/**
	 * Comprueba que get_the_terms() no sustituyó la relación cruda ya fijada.
	 *
	 * @param array<int, \WP_Term> $terms Términos públicos observados.
	 */
	public function verify_product_terms( int $product_id, string $taxonomy, array $terms ): bool {
		if ( ! $this->available ) {
			return false;
		}
		if ( ! isset( $this->authoritative_product_terms[ $product_id ][ $taxonomy ] )
			&& defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING
			&& is_callable( $this->test_reader ) ) {
			return true;
		}
		$snapshot = $this->term_snapshot( $product_id, $taxonomy );
		if ( null === $snapshot ) {
			return false;
		}
		$observed = array();
		foreach ( $terms as $term ) {
			$record = $this->term_object_record( $term, $taxonomy );
			if ( null === $record || isset( $observed[ $record['term_id'] ] ) ) {
				$this->mark_unavailable( 'Public product terms contain invalid or duplicate data.' );
				return false;
			}
			$observed[ $record['term_id'] ] = $record;
		}
		ksort( $observed, SORT_NUMERIC );
		if ( $observed !== $snapshot['direct'] ) {
			$this->mark_unavailable( 'Public product terms disagree with the authoritative relationship source.' );
			return false;
		}
		return true;
	}

	/** Comprueba un término ancestro rehidratado contra el cierre SQL crudo. */
	public function verify_product_term( int $product_id, string $taxonomy, $term ): bool {
		if ( ! $this->available ) {
			return false;
		}
		if ( ! isset( $this->authoritative_product_terms[ $product_id ][ $taxonomy ] )
			&& defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING
			&& is_callable( $this->test_reader ) ) {
			return true;
		}
		$snapshot = $this->term_snapshot( $product_id, $taxonomy );
		$record   = $this->term_object_record( $term, $taxonomy );
		if ( null === $snapshot || null === $record
			|| ! isset( $snapshot['closure'][ $record['term_id'] ] )
			|| $snapshot['closure'][ $record['term_id'] ] !== $record ) {
			$this->mark_unavailable( 'Public product term disagrees with the authoritative taxonomy source.' );
			return false;
		}
		return true;
	}

	/** Verifica orden y cobertura exactos del get_ancestors() filtrable. */
	public function verify_product_ancestor_ids( int $product_id, string $taxonomy, int $term_id, array $ancestor_ids ): bool {
		if ( ! $this->available ) {
			return false;
		}
		if ( ! isset( $this->authoritative_product_terms[ $product_id ][ $taxonomy ] )
			&& defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING
			&& is_callable( $this->test_reader ) ) {
			return true;
		}
		$snapshot = $this->term_snapshot( $product_id, $taxonomy );
		if ( null === $snapshot || ! isset( $snapshot['direct'][ $term_id ] ) ) {
			$this->mark_unavailable( 'Taxonomy ancestor origin is absent from the authoritative relationship source.' );
			return false;
		}
		$observed = array();
		foreach ( $ancestor_ids as $raw_id ) {
			if ( ! is_int( $raw_id ) || $raw_id <= 0 || in_array( $raw_id, $observed, true ) ) {
				$this->mark_unavailable( 'Public taxonomy ancestor identifiers contain invalid data.' );
				return false;
			}
			$observed[] = $raw_id;
		}
		$expected = array();
		$record   = $snapshot['direct'][ $term_id ];
		while ( $record['parent'] > 0 ) {
			$parent_id = $record['parent'];
			if ( ! isset( $snapshot['closure'][ $parent_id ] ) ) {
				$this->mark_unavailable( 'Authoritative taxonomy ancestor closure is incomplete.' );
				return false;
			}
			$expected[] = $parent_id;
			$record     = $snapshot['closure'][ $parent_id ];
		}
		if ( $observed !== $expected ) {
			$this->mark_unavailable( 'Public taxonomy ancestors disagree with the authoritative closure.' );
			return false;
		}
		return true;
	}

	/** Verifica un campo consumido de WC_Product contra la fila posts fijada. */
	public function verify_product_post_field( int $product_id, string $field, $value ): bool {
		if ( ! $this->available ) {
			return false;
		}
		if ( ! isset( $this->authoritative_product_posts[ $product_id ] ) ) {
			if ( defined( 'AKIBARA_SEARCH_TESTING' ) && AKIBARA_SEARCH_TESTING && is_callable( $this->test_reader ) ) {
				return true;
			}
			$this->mark_unavailable( 'Authoritative product post snapshot is unavailable.' );
			return false;
		}
		$allowed = array( 'post_title', 'post_excerpt', 'post_content', 'menu_order' );
		if ( ! in_array( $field, $allowed, true )
			|| $this->authoritative_product_posts[ $product_id ][ $field ] !== $value ) {
			$this->mark_unavailable( 'WooCommerce product field disagrees with the authoritative post source.' );
			return false;
		}
		return true;
	}

	/**
	 * Devuelve únicamente IDs cuyo post fuente existe como producto publicado.
	 * Antes del retorno, post meta, relaciones, términos y termmeta quedaron
	 * repoblados desde una lectura fresca y verificados en object cache.
	 *
	 * @param array<int, int> $raw_ids IDs positivos.
	 * @return array<int, int>
	 */
	public function published_product_ids( array $raw_ids ): array {
		if ( ! $this->available ) {
			return array();
		}

		$ids = $this->normalize_ids( $raw_ids );
		if ( array() === $ids ) {
			return array();
		}

		if ( is_callable( $this->test_reader ) ) {
			$result = $this->read(
				fn() => ( $this->test_reader )( $ids ),
				'Could not read the test product source.'
			);
			if ( ! $this->available || ! is_array( $result ) ) {
				$this->mark_unavailable( 'The test product source returned an invalid shape.' );
				return array();
			}
			$result = $this->normalize_ids( $result );
			return array_values( array_intersect( $ids, $result ) );
		}

		$raw_posts = $this->authoritative_product_post_rows( $ids );
		if ( ! $this->available || null === $raw_posts ) {
			return array();
		}
		foreach ( $ids as $id ) {
			unset( $this->authoritative_product_posts[ $id ] );
		}
		foreach ( $raw_posts as $id => $row ) {
			$this->authoritative_product_posts[ $id ] = $row;
		}

		$this->evict( $ids, array( 'posts', 'post_meta' ) );
		if ( ! $this->available ) {
			return array();
		}

		$this->read(
			static function () use ( $ids ): bool {
				_prime_post_caches( $ids, false, false );
				return true;
			},
			'Could not prime product posts.'
		);
		if ( ! $this->available ) {
			$this->cleanup( $ids );
			return array();
		}

		$published = array();
		foreach ( $ids as $id ) {
			$expected = $raw_posts[ $id ] ?? null;
			$post = $this->read( static fn() => get_post( $id ), 'Could not verify a product post.' );
			if ( ! $this->available ) {
				$this->cleanup( $ids );
				return array();
			}
			if ( null === $expected ) {
				if ( false !== $post && null !== $post ) {
					$this->mark_unavailable( 'WordPress returned a product post absent from the authoritative source.' );
					$this->cleanup( $ids );
					return array();
				}
				continue;
			}
			if ( ! $post instanceof \WP_Post ) {
				$this->mark_unavailable( 'WordPress returned an invalid product post.' );
				$this->cleanup( $ids );
				return array();
			}
			if ( ! isset( $post->ID, $post->post_type, $post->post_status, $post->post_title, $post->post_excerpt, $post->post_content, $post->menu_order,
				$post->post_name, $post->post_date, $post->post_date_gmt, $post->post_modified, $post->post_modified_gmt,
				$post->post_parent, $post->post_password, $post->comment_status )
				|| ! is_int( $post->ID ) || $post->ID !== $id
				|| ! is_string( $post->post_type ) || ! is_string( $post->post_status )
				|| ! is_string( $post->post_title ) || ! is_string( $post->post_excerpt ) || ! is_string( $post->post_content )
				|| ! is_int( $post->menu_order ) || ! is_int( $post->post_parent )
				|| ! is_string( $post->post_name ) || ! is_string( $post->post_date ) || ! is_string( $post->post_date_gmt )
				|| ! is_string( $post->post_modified ) || ! is_string( $post->post_modified_gmt )
				|| ! is_string( $post->post_password ) || ! is_string( $post->comment_status )
				|| $post->post_type !== $expected['post_type'] || $post->post_status !== $expected['post_status']
				|| $post->post_title !== $expected['post_title'] || $post->post_excerpt !== $expected['post_excerpt']
				|| $post->post_content !== $expected['post_content'] || $post->menu_order !== $expected['menu_order']
				|| $post->post_name !== $expected['post_name'] || $post->post_date !== $expected['post_date']
				|| $post->post_date_gmt !== $expected['post_date_gmt'] || $post->post_modified !== $expected['post_modified']
				|| $post->post_modified_gmt !== $expected['post_modified_gmt'] || $post->post_parent !== $expected['post_parent']
				|| $post->post_password !== $expected['post_password'] || $post->comment_status !== $expected['comment_status'] ) {
				$this->mark_unavailable( 'WordPress product post disagrees with its authoritative identity.' );
				$this->cleanup( $ids );
				return array();
			}
			if ( 'product' === $expected['post_type'] && 'publish' === $expected['post_status'] ) {
				$published[] = $id;
			}
		}

		if ( array() === $published ) {
			return array();
		}

		$this->prime_post_meta( $published );
		if ( ! $this->available ) {
			$this->cleanup( $published );
			return array();
		}

		$this->prime_terms( $published );
		if ( ! $this->available ) {
			$this->cleanup( $published );
			return array();
		}

		$this->evict_woocommerce_product_caches( $published );
		if ( ! $this->available ) {
			$this->cleanup( $published );
			return array();
		}

		return $published;
	}

	/**
	 * Variaciones publish directas del padre desde la tabla posts, nunca desde
	 * el transient/cache interno de WC_Product_Variable::get_children().
	 *
	 * @param int $parent_id Producto variable padre.
	 * @param int $limit     Cota de hijos.
	 * @return array<int, \WC_Product>
	 */
	public function published_variations( int $parent_id, int $limit ): array {
		if ( ! $this->available || $parent_id <= 0 || $limit <= 0 || $limit > 50 ) {
			if ( $this->available && ( $parent_id <= 0 || $limit <= 0 || $limit > 50 ) ) {
				$this->mark_unavailable( 'Variation source bounds are invalid.' );
			}
			return array();
		}
		if ( ! $this->prepare_variable_price_source( $parent_id ) ) {
			return array();
		}

		if ( is_callable( $this->test_variation_reader ) ) {
			$products = $this->read(
				fn() => ( $this->test_variation_reader )( $parent_id, $limit + 1 ),
				'Could not read the test variation source.'
			);
			return $this->validated_variation_products( $products, $parent_id, $limit );
		}

		if ( ! is_object( $this->db ) || ! isset( $this->db->posts )
			|| ! is_callable( array( $this->db, 'prepare' ) ) || ! is_callable( array( $this->db, 'get_col' ) ) ) {
			$this->mark_unavailable( 'Authoritative variation table is unavailable.' );
			return array();
		}
		$sql = $this->read(
			fn() => $this->db->prepare(
				"SELECT ID FROM {$this->db->posts}
				WHERE post_parent = %d AND post_type = %s AND post_status = %s
				ORDER BY menu_order ASC, ID ASC
				LIMIT %d",
				$parent_id,
				'product_variation',
				'publish',
				$limit + 1
			),
			'Could not prepare authoritative variation read.'
		);
		if ( ! $this->available || ! is_string( $sql ) || '' === trim( $sql ) ) {
			$this->mark_unavailable( 'Authoritative variation query has an invalid shape.' );
			return array();
		}
		$raw_ids = $this->read(
			fn() => $this->db->get_col( $sql ),
			'Could not read authoritative variations.'
		);
		if ( ! $this->available || ! is_array( $raw_ids ) ) {
			$this->mark_unavailable( 'Authoritative variation identifiers have an invalid shape.' );
			return array();
		}
		$ids = $this->normalize_ids( $raw_ids );
		if ( count( $ids ) !== count( $raw_ids ) || count( $ids ) > $limit ) {
			$this->mark_unavailable( 'Authoritative variation identifiers contain invalid data.' );
			return array();
		}
		if ( array() === $ids ) {
			return array();
		}

		$this->evict( $ids, array( 'posts', 'post_meta' ) );
		$this->read(
			static function () use ( $ids ): bool {
				_prime_post_caches( $ids, false, false );
				return true;
			},
			'Could not prime variation posts.'
		);
		if ( ! $this->available ) {
			$this->cleanup( $ids );
			return array();
		}
		foreach ( $ids as $id ) {
			$post = $this->read( static fn() => get_post( $id ), 'Could not verify a variation post.' );
			if ( ! $this->available || ! $post instanceof \WP_Post
				|| ! isset( $post->ID, $post->post_type, $post->post_status, $post->post_parent )
				|| ! is_int( $post->ID ) || $post->ID !== $id
				|| ! is_string( $post->post_type ) || 'product_variation' !== $post->post_type
				|| ! is_string( $post->post_status ) || 'publish' !== $post->post_status
				|| ! is_int( $post->post_parent ) || $post->post_parent !== $parent_id ) {
				$this->mark_unavailable( 'Variation post disagrees with the authoritative source.' );
				$this->cleanup( $ids );
				return array();
			}
		}
		$this->prime_post_meta( $ids );
		$this->evict_woocommerce_product_caches( $ids );
		if ( ! $this->available ) {
			$this->cleanup( $ids );
			return array();
		}

		$products = array();
		foreach ( $ids as $id ) {
			$product = $this->read(
				static fn() => wc_get_product( $id ),
				'Could not hydrate an authoritative variation.'
			);
			if ( ! $this->available || ! $product instanceof \WC_Product ) {
				$this->mark_unavailable( 'Authoritative variation could not be hydrated.' );
				$this->cleanup( $ids );
				return array();
			}
			$products[] = $product;
		}
		return $this->validated_variation_products( $products, $parent_id, $limit, $ids );
	}

	/**
	 * Expulsa y verifica la foto agregada de precios del padre variable.
	 *
	 * Debe ejecutarse antes del primer getter de precio del padre. La marca
	 * request-local evita repetir una invalidación que ya fue comprobada y hace
	 * que DocumentBuilder, ProductPresenter y el lector de variaciones compartan
	 * exactamente la misma frontera.
	 */
	public function prepare_variable_price_source( int $parent_id ): bool {
		if ( ! $this->available ) {
			return false;
		}
		if ( $parent_id <= 0 ) {
			$this->mark_unavailable( 'Variable product identity is invalid.' );
			return false;
		}
		if ( isset( $this->prepared_variable_prices[ $parent_id ] ) ) {
			return true;
		}
		if ( ! function_exists( 'delete_transient' ) || ! function_exists( 'get_transient' ) ) {
			$this->mark_unavailable( 'WooCommerce variable price transient authority is unavailable.' );
			return false;
		}

		// El precio del padre se regenera leyendo TODOS los hijos visibles. Una
		// variación A en post/meta/ProductCache puede volver a contaminar el nuevo
		// transient aunque éste estuviera ausente. Esta preparación no usa el cap
		// de 50, que sólo protege la cantidad de SKU materializados después.
		if ( is_callable( $this->test_variation_reader ) ) {
			$products = $this->read(
				fn() => ( $this->test_variation_reader )( $parent_id, PHP_INT_MAX ),
				'Could not read all test variation sources.'
			);
			$this->validated_variation_products( $products, $parent_id, PHP_INT_MAX );
			if ( ! $this->available ) {
				return false;
			}
		} else {
			$child_ids = $this->all_price_variation_ids( $parent_id );
			if ( ! $this->available || null === $child_ids ) {
				return false;
			}
			$this->prepare_variation_sources( $child_ids, $parent_id );
			if ( ! $this->available ) {
				return false;
			}
		}

		foreach ( array( 'wc_var_prices_', 'wc_product_children_' ) as $prefix ) {
			$name = $prefix . $parent_id;
			$deleted = $this->read(
				static fn() => delete_transient( $name ),
				'Could not invalidate WooCommerce variable product transient.'
			);
			if ( ! $this->available || ! is_bool( $deleted ) ) {
				$this->mark_unavailable( 'WooCommerce variable product transient deletion returned an invalid shape.' );
				return false;
			}
			$remaining = $this->read(
				static fn() => get_transient( $name ),
				'Could not verify WooCommerce variable product transient.'
			);
			if ( ! $this->available ) {
				return false;
			}
			if ( false !== $remaining ) {
				$this->mark_unavailable( 'WooCommerce variable product transient invalidation could not be verified.' );
				return false;
			}
		}
		$this->prepared_variable_prices[ $parent_id ] = true;
		return true;
	}

	/** @return array<int, int>|null */
	private function all_price_variation_ids( int $parent_id ): ?array {
		if ( ! is_object( $this->db ) || ! isset( $this->db->posts )
			|| ! is_callable( array( $this->db, 'prepare' ) ) || ! is_callable( array( $this->db, 'get_col' ) ) ) {
			$this->mark_unavailable( 'Authoritative variation table is unavailable.' );
			return null;
		}
		$sql = $this->read(
			fn() => $this->db->prepare(
				"SELECT ID FROM {$this->db->posts}
				WHERE post_parent = %d AND post_type = %s AND post_status IN (%s, %s)
				ORDER BY menu_order ASC, ID ASC",
				$parent_id,
				'product_variation',
				'publish',
				'private'
			),
			'Could not prepare all authoritative variation sources.'
		);
		if ( ! $this->available || ! is_string( $sql ) || '' === trim( $sql ) ) {
			$this->mark_unavailable( 'All authoritative variation query has an invalid shape.' );
			return null;
		}
		$raw_ids = $this->read(
			fn() => $this->db->get_col( $sql ),
			'Could not read all authoritative variation sources.'
		);
		if ( ! $this->available || ! is_array( $raw_ids ) ) {
			$this->mark_unavailable( 'All authoritative variation identifiers have an invalid shape.' );
			return null;
		}
		$ids = $this->normalize_ids( $raw_ids );
		if ( count( $ids ) !== count( $raw_ids ) ) {
			$this->mark_unavailable( 'All authoritative variation identifiers contain invalid data.' );
			return null;
		}
		return $ids;
	}

	/** @param array<int, int> $ids */
	private function prepare_variation_sources( array $ids, int $parent_id ): void {
		if ( array() === $ids ) {
			return;
		}
		$this->evict( $ids, array( 'posts', 'post_meta' ) );
		$this->read(
			static function () use ( $ids ): bool {
				_prime_post_caches( $ids, false, false );
				return true;
			},
			'Could not prime all variation posts.'
		);
		if ( ! $this->available ) {
			$this->cleanup( $ids );
			return;
		}
		$expected_statuses = array();
		foreach ( $ids as $id ) {
			$post = $this->read( static fn() => get_post( $id ), 'Could not verify an all-variation post.' );
			if ( ! $this->available || ! $post instanceof \WP_Post
				|| ! isset( $post->ID, $post->post_type, $post->post_status, $post->post_parent )
				|| ! is_int( $post->ID ) || $post->ID !== $id
				|| ! is_string( $post->post_type ) || 'product_variation' !== $post->post_type
				|| ! is_string( $post->post_status ) || ! in_array( $post->post_status, array( 'publish', 'private' ), true )
				|| ! is_int( $post->post_parent ) || $post->post_parent !== $parent_id ) {
				$this->mark_unavailable( 'Variation post disagrees with the all-variation source.' );
				$this->cleanup( $ids );
				return;
			}
			$expected_statuses[ $id ] = $post->post_status;
		}
		$this->prime_post_meta( $ids );
		$this->evict_woocommerce_product_caches( $ids );
		if ( ! $this->available ) {
			$this->cleanup( $ids );
			return;
		}

		foreach ( $ids as $id ) {
			$product = $this->read(
				static fn() => wc_get_product( $id ),
				'Could not hydrate an all-variation product.'
			);
			if ( ! $this->available || ! $product instanceof \WC_Product ) {
				$this->mark_unavailable( 'All-variation product could not be hydrated.' );
				$this->cleanup( $ids );
				return;
			}
			$observed_id = $this->read( static fn() => $product->get_id(), 'Could not read all-variation identity.' );
			if ( ! $this->available || ! is_int( $observed_id ) || $observed_id !== $id ) {
				$this->mark_unavailable( 'All-variation product identity disagrees with its source.' );
				$this->cleanup( $ids );
				return;
			}
			$status = $this->read( static fn() => $product->get_status(), 'Could not read all-variation status.' );
			if ( ! $this->available || ! is_string( $status ) || $status !== $expected_statuses[ $id ] ) {
				$this->mark_unavailable( 'All-variation product status disagrees with its source.' );
				$this->cleanup( $ids );
				return;
			}
			$is_variation = $this->read( static fn() => $product->is_type( 'variation' ), 'Could not read all-variation type.' );
			if ( ! $this->available || true !== $is_variation ) {
				$this->mark_unavailable( 'All-variation product type disagrees with its source.' );
				$this->cleanup( $ids );
				return;
			}
			$observed_parent = $this->read( static fn() => $product->get_parent_id(), 'Could not read all-variation parent.' );
			if ( ! $this->available || ! is_int( $observed_parent ) || $observed_parent !== $parent_id ) {
				$this->mark_unavailable( 'All-variation product parent disagrees with its source.' );
				$this->cleanup( $ids );
				return;
			}
		}
	}

	/**
	 * @param mixed           $raw_products Productos candidatos.
	 * @param int             $parent_id    Padre esperado.
	 * @param int             $limit        Cota.
	 * @param array<int, int> $expected_ids IDs SQL, si aplica.
	 * @return array<int, \WC_Product>
	 */
	private function validated_variation_products( $raw_products, int $parent_id, int $limit, array $expected_ids = array() ): array {
		if ( ! $this->available || ! is_array( $raw_products ) || count( $raw_products ) > $limit ) {
			$this->mark_unavailable( 'Variation products have an invalid shape.' );
			return array();
		}
		$out = array();
		foreach ( array_values( $raw_products ) as $offset => $product ) {
			if ( ! $product instanceof \WC_Product ) {
				$this->mark_unavailable( 'Variation product could not be hydrated.' );
				return array();
			}
			$id = $this->read( static fn() => $product->get_id(), 'Could not read variation identity.' );
			$expected_id = $expected_ids[ $offset ] ?? $id;
			if ( ! $this->available || ! is_int( $id ) || $id <= 0 || $id !== $expected_id ) {
				$this->mark_unavailable( 'Variation product identity disagrees with the authoritative source.' );
				return array();
			}
			$status = $this->read( static fn() => $product->get_status(), 'Could not read variation status.' );
			if ( ! $this->available || ! is_string( $status ) || 'publish' !== $status ) {
				$this->mark_unavailable( 'Variation product status disagrees with the authoritative source.' );
				return array();
			}
			$is_variation = $this->read( static fn() => $product->is_type( 'variation' ), 'Could not read variation type.' );
			if ( ! $this->available || true !== $is_variation ) {
				$this->mark_unavailable( 'Variation product type disagrees with the authoritative source.' );
				return array();
			}
			$observed_parent = $this->read( static fn() => $product->get_parent_id(), 'Could not read variation parent.' );
			if ( ! $this->available || ! is_int( $observed_parent ) || $observed_parent !== $parent_id ) {
				$this->mark_unavailable( 'Variation product parent disagrees with the authoritative source.' );
				return array();
			}
			$out[] = $product;
		}
		return $out;
	}

	/**
	 * @param array<int, int> $ids IDs pedidos.
	 * @return array<int, array<string, mixed>>|null
	 */
	private function authoritative_product_post_rows( array $ids ): ?array {
		if ( ! is_object( $this->db ) || ! isset( $this->db->posts )
			|| ! is_callable( array( $this->db, 'prepare' ) ) || ! is_callable( array( $this->db, 'get_results' ) ) ) {
			$this->mark_unavailable( 'Authoritative product post table is unavailable.' );
			return null;
		}
		$marks = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql = $this->read(
			fn() => $this->db->prepare(
				"SELECT ID, post_type, post_status, post_title, post_excerpt, post_content, menu_order,
				post_name, post_date, post_date_gmt, post_modified, post_modified_gmt, post_parent, post_password, comment_status
				FROM {$this->db->posts} WHERE ID IN ({$marks}) ORDER BY ID ASC",
				...$ids
			),
			'Could not prepare authoritative product post read.'
		);
		if ( ! $this->available || ! is_string( $sql ) || '' === trim( $sql ) ) {
			$this->mark_unavailable( 'Authoritative product post query has an invalid shape.' );
			return null;
		}
		$rows = $this->read(
			fn() => $this->db->get_results( $sql, ARRAY_A ),
			'Could not read authoritative product posts.'
		);
		if ( ! $this->available || ! is_array( $rows ) || count( $rows ) > count( $ids ) ) {
			$this->mark_unavailable( 'Authoritative product posts have an invalid shape.' );
			return null;
		}
		$requested = array_fill_keys( $ids, true );
		$out       = array();
		foreach ( $rows as $raw ) {
			if ( ! is_array( $raw ) ) {
				$this->mark_unavailable( 'Authoritative product post contains invalid data.' );
				return null;
			}
			$id         = $this->positive_integer( $raw['ID'] ?? null );
			$menu_order = $this->canonical_integer( $raw['menu_order'] ?? null );
			$post_type  = $raw['post_type'] ?? null;
			$status     = $raw['post_status'] ?? null;
			$title      = $raw['post_title'] ?? null;
			$excerpt    = $raw['post_excerpt'] ?? null;
			$content    = $raw['post_content'] ?? null;
			$post_parent = $this->non_negative_integer( $raw['post_parent'] ?? null );
			$post_name   = $raw['post_name'] ?? null;
			$post_date   = $raw['post_date'] ?? null;
			$date_gmt    = $raw['post_date_gmt'] ?? null;
			$modified    = $raw['post_modified'] ?? null;
			$modified_gmt = $raw['post_modified_gmt'] ?? null;
			$password    = $raw['post_password'] ?? null;
			$comment_status = $raw['comment_status'] ?? null;
			if ( null === $id || ! isset( $requested[ $id ] ) || isset( $out[ $id ] ) || null === $menu_order || null === $post_parent
				|| ! is_string( $post_type ) || '' === $post_type || ! is_string( $status ) || '' === $status
				|| ! is_string( $title ) || ! is_string( $excerpt ) || ! is_string( $content )
				|| ! is_string( $post_name ) || ! is_string( $post_date ) || ! is_string( $date_gmt )
				|| ! is_string( $modified ) || ! is_string( $modified_gmt )
				|| ! is_string( $password ) || ! is_string( $comment_status ) ) {
				$this->mark_unavailable( 'Authoritative product post contains invalid data.' );
				return null;
			}
			$out[ $id ] = array(
				'ID'           => $id,
				'post_type'    => $post_type,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
				'post_content' => $content,
				'menu_order'   => $menu_order,
				'post_name'     => $post_name,
				'post_date'     => $post_date,
				'post_date_gmt' => $date_gmt,
				'post_modified' => $modified,
				'post_modified_gmt' => $modified_gmt,
				'post_parent'   => $post_parent,
				'post_password' => $password,
				'comment_status'=> $comment_status,
			);
		}
		ksort( $out, SORT_NUMERIC );
		return $out;
	}

	/**
	 * Invalida las dos capas que realmente consume wc_get_product().
	 *
	 * El tipo no vive en `product_type:$id`: Woo lo namespacea con el prefix
	 * `product_$id` dentro de `products`. Los objetos recientes viven además en
	 * ProductCache (`product_objects`). Ambas expulsiones se verifican antes de
	 * permitir una hidratación.
	 *
	 * @param array<int, int> $ids Productos publish.
	 */
	private function evict_woocommerce_product_caches( array $ids ): void {
		if ( ! class_exists( '\\WC_Cache_Helper' )
			|| ! is_callable( array( '\\WC_Cache_Helper', 'get_cache_prefix' ) )
			|| ! is_callable( array( '\\WC_Cache_Helper', 'invalidate_cache_group' ) ) ) {
			$this->mark_unavailable( 'WooCommerce product type cache authority is unavailable.' );
			return;
		}

		foreach ( $ids as $id ) {
			$group = 'product_' . $id;
			$before = $this->read(
				static fn() => \WC_Cache_Helper::get_cache_prefix( $group ),
				'Could not read WooCommerce product cache prefix.'
			);
			if ( ! $this->available || ! is_string( $before ) || '' === $before ) {
				$this->mark_unavailable( 'WooCommerce product cache prefix has an invalid shape.' );
				return;
			}
			$invalidated = $this->read(
				static fn() => \WC_Cache_Helper::invalidate_cache_group( $group ),
				'Could not invalidate WooCommerce product cache prefix.'
			);
			if ( ! $this->available || true !== $invalidated ) {
				$this->mark_unavailable( 'WooCommerce product cache prefix invalidation failed.' );
				return;
			}
			$after = $this->read(
				static fn() => \WC_Cache_Helper::get_cache_prefix( $group ),
				'Could not verify WooCommerce product cache prefix.'
			);
			if ( ! $this->available || ! is_string( $after ) || '' === $after || $before === $after ) {
				$this->mark_unavailable( 'WooCommerce product cache prefix invalidation could not be verified.' );
				return;
			}
		}

		$product_cache_class = 'Automattic\\WooCommerce\\Internal\\Caches\\ProductCache';
		if ( ! function_exists( 'wc_get_container' ) ) {
			$this->mark_unavailable( 'WooCommerce product object cache authority is unavailable.' );
			return;
		}
		$cache = $this->read(
			static fn() => wc_get_container()->get( $product_cache_class ),
			'Could not resolve WooCommerce product object cache.'
		);
		if ( ! $this->available || ! is_object( $cache )
			|| ! is_callable( array( $cache, 'remove' ) ) || ! is_callable( array( $cache, 'is_cached' ) ) ) {
			$this->mark_unavailable( 'WooCommerce product object cache has an invalid shape.' );
			return;
		}

		foreach ( $ids as $id ) {
			$removed = $this->read(
				static fn() => $cache->remove( $id ),
				'Could not invalidate WooCommerce product object cache.'
			);
			if ( ! $this->available || ! is_bool( $removed ) ) {
				$this->mark_unavailable( 'WooCommerce product object cache invalidation returned an invalid shape.' );
				return;
			}
			$cached = $this->read(
				static fn() => $cache->is_cached( $id ),
				'Could not verify WooCommerce product object cache.'
			);
			if ( ! $this->available || ! is_bool( $cached ) || $cached ) {
				$this->mark_unavailable( 'WooCommerce product object cache invalidation could not be verified.' );
				return;
			}
		}
	}

	/** @param array<int, int> $ids */
	private function prime_post_meta( array $ids ): void {
		$this->evict( $ids, array( 'post_meta' ) );
		$meta = $this->read( static fn() => update_postmeta_cache( $ids ), 'Could not prime product metadata.' );
		if ( ! $this->available || ! $this->valid_cache_map( $meta, $ids ) ) {
			$this->mark_unavailable( 'Product metadata coverage is incomplete.' );
			return;
		}

		$cached = $this->read(
			static fn() => wp_cache_get_multiple( $ids, 'post_meta' ),
			'Could not verify product metadata cache.'
		);
		if ( ! $this->available || ! $this->valid_cache_map( $cached, $ids ) ) {
			$this->mark_unavailable( 'Product metadata cache coverage is incomplete.' );
			return;
		}
		foreach ( $ids as $id ) {
			if ( $cached[ $id ] !== $meta[ $id ] ) {
				$this->mark_unavailable( 'Product metadata cache disagrees with the freshly primed source.' );
				return;
			}
		}
	}

	/** @param array<int, int> $ids */
	private function prime_terms( array $ids ): void {
		foreach ( $ids as $id ) {
			unset( $this->authoritative_product_terms[ $id ] );
		}
		$raw_taxonomies = $this->read(
			static fn() => get_object_taxonomies( 'product', 'names' ),
			'Could not enumerate product taxonomies.'
		);
		if ( ! $this->available || ! is_array( $raw_taxonomies ) ) {
			$this->mark_unavailable( 'Product taxonomies have an invalid shape.' );
			return;
		}

		$taxonomies = array();
		foreach ( $raw_taxonomies as $taxonomy ) {
			if ( ! is_string( $taxonomy ) || 1 !== preg_match( '/^[a-z0-9_\-]+$/D', $taxonomy ) ) {
				$this->mark_unavailable( 'Product taxonomies contain an invalid name.' );
				return;
			}
			$taxonomies[] = $taxonomy;
		}
		$taxonomies = array_values( array_unique( $taxonomies, SORT_STRING ) );
		if ( array() === $taxonomies ) {
			$this->mark_unavailable( 'No product taxonomies were registered for an authoritative read.' );
			return;
		}

		$relationship_groups = array_map( static fn( string $taxonomy ): string => $taxonomy . '_relationships', $taxonomies );
		$this->evict( $ids, $relationship_groups );
		if ( ! $this->available ) {
			return;
		}

		// `cache_results=false` sólo evita el cache de la CONSULTA; Core todavía
		// hidrata cada WP_Term desde la key `terms:$term_id`. Por eso la fuente de
		// identidad/parent/slug es SQL crudo y sólo después se expulsa y vuelve a
		// hidratar el object cache. La misma foto incluye todos los ancestros que
		// DocumentBuilder recorrerá mediante get_ancestors()/get_term().
		$source = $this->authoritative_term_source( $ids, $taxonomies );
		if ( ! $this->available || null === $source ) {
			return;
		}
		$relations   = $source['relations'];
		$term_records = $source['terms'];

		foreach ( $relations as $taxonomy => $by_product ) {
			foreach ( $by_product as $id => $related_ids ) {
				$relations[ $taxonomy ][ $id ] = array_values( array_unique( $related_ids, SORT_NUMERIC ) );
			}
			$written = $this->read(
				static fn() => wp_cache_set_multiple( $relations[ $taxonomy ], $taxonomy . '_relationships' ),
				'Could not cache product-term relationships.'
			);
			if ( ! $this->available || ! $this->valid_write_map( $written, $ids ) ) {
				$this->mark_unavailable( 'Product-term relationships could not be cached.' );
				return;
			}
			$cached = $this->read(
				static fn() => wp_cache_get_multiple( $ids, $taxonomy . '_relationships' ),
				'Could not verify product-term relationship cache.'
			);
			if ( ! $this->available || ! is_array( $cached ) ) {
				$this->mark_unavailable( 'Product-term relationship cache has an invalid shape.' );
				return;
			}
			foreach ( $ids as $id ) {
				if ( ! array_key_exists( $id, $cached ) || $cached[ $id ] !== $relations[ $taxonomy ][ $id ] ) {
					$this->mark_unavailable( 'Product-term relationship cache verification failed.' );
					return;
				}
			}
		}

		$term_ids = array_values(
			array_unique(
				array_map( static fn( array $row ): int => $row['term_id'], array_values( $term_records ) ),
				SORT_NUMERIC
			)
		);
		sort( $term_ids, SORT_NUMERIC );
		if ( array() === $term_ids ) {
			$this->store_term_snapshots( $ids, $taxonomies, $relations, $term_records );
			return;
		}

		$this->evict( $term_ids, array( 'terms' ) );
		if ( ! $this->available ) {
			return;
		}

		$term_objects = $this->read(
			static fn() => get_terms(
				array(
					'taxonomy'               => $taxonomies,
					'include'                => $term_ids,
					'hide_empty'             => false,
					'number'                 => 0,
					'fields'                 => 'all',
					'cache_results'          => false,
					'update_term_meta_cache' => false,
				)
			),
			'Could not read product term objects.'
		);
		if ( ! $this->available || ! is_array( $term_objects ) ) {
			$this->mark_unavailable( 'Product term objects have an invalid shape.' );
			return;
		}
		$observed = array();
		foreach ( $term_objects as $term ) {
			if ( ! $term instanceof \WP_Term || (int) $term->term_id <= 0 ) {
				$this->mark_unavailable( 'Product term objects contain invalid data.' );
				return;
			}
			$key = (string) $term->taxonomy . ':' . (int) $term->term_id;
			if ( isset( $observed[ $key ] ) || ! isset( $term_records[ $key ] ) ) {
				$this->mark_unavailable( 'Product term objects do not match the authoritative source.' );
				return;
			}
			$record = $term_records[ $key ];
			if ( (int) $term->parent !== $record['parent']
				|| (string) $term->slug !== $record['slug']
				|| (string) $term->name !== $record['name'] ) {
				$this->mark_unavailable( 'Product term object data disagrees with the authoritative source.' );
				return;
			}
			$observed[ $key ] = true;
		}
		if ( count( $observed ) !== count( $term_records ) ) {
			$this->mark_unavailable( 'Product term object coverage is incomplete.' );
			return;
		}

		$this->evict( $term_ids, array( 'term_meta' ) );
		$term_meta = $this->read(
			static fn() => update_meta_cache( 'term', $term_ids ),
			'Could not prime product term metadata.'
		);
		if ( ! $this->available || ! $this->valid_cache_map( $term_meta, $term_ids ) ) {
			$this->mark_unavailable( 'Product term metadata coverage is incomplete.' );
			return;
		}
		$cached_meta = $this->read(
			static fn() => wp_cache_get_multiple( $term_ids, 'term_meta' ),
			'Could not verify product term metadata cache.'
		);
		if ( ! $this->available || ! $this->valid_cache_map( $cached_meta, $term_ids ) ) {
			$this->mark_unavailable( 'Product term metadata cache coverage is incomplete.' );
			return;
		}
		foreach ( $term_ids as $term_id ) {
			if ( $cached_meta[ $term_id ] !== $term_meta[ $term_id ] ) {
				$this->mark_unavailable( 'Product term metadata cache disagrees with the freshly primed source.' );
				return;
			}
		}
		$this->store_term_snapshots( $ids, $taxonomies, $relations, $term_records );
	}

	/**
	 * Persiste por producto sólo después de verificar relaciones, objetos y
	 * termmeta. Así un filtro posterior puede compararse con la misma foto.
	 *
	 * @param array<int, int> $ids Productos.
	 * @param array<int, string> $taxonomies Taxonomías.
	 * @param array<string, array<int, array<int, int>>> $relations Relaciones directas.
	 * @param array<string, array{term_id:int,taxonomy:string,parent:int,slug:string,name:string}> $records Cierre de términos.
	 */
	private function store_term_snapshots( array $ids, array $taxonomies, array $relations, array $records ): void {
		foreach ( $ids as $id ) {
			foreach ( $taxonomies as $taxonomy ) {
				$direct  = array();
				$closure = array();
				foreach ( $relations[ $taxonomy ][ $id ] ?? array() as $term_id ) {
					$key = $taxonomy . ':' . $term_id;
					if ( ! isset( $records[ $key ] ) ) {
						$this->mark_unavailable( 'Authoritative product-term snapshot is incomplete.' );
						return;
					}
					$record = $records[ $key ];
					$direct[ $term_id ]  = $record;
					$closure[ $term_id ] = $record;
					$seen = array();
					while ( $record['parent'] > 0 ) {
						$parent_key = $taxonomy . ':' . $record['parent'];
						if ( isset( $seen[ $parent_key ] ) || ! isset( $records[ $parent_key ] ) ) {
							$this->mark_unavailable( 'Authoritative product-term snapshot ancestry is incomplete.' );
							return;
						}
						$seen[ $parent_key ] = true;
						$record = $records[ $parent_key ];
						$closure[ $record['term_id'] ] = $record;
					}
				}
				ksort( $direct, SORT_NUMERIC );
				ksort( $closure, SORT_NUMERIC );
				$this->authoritative_product_terms[ $id ][ $taxonomy ] = array(
					'direct'  => $direct,
					'closure' => $closure,
				);
			}
		}
	}

	/** @return array{direct: array<int, array{term_id:int,taxonomy:string,parent:int,slug:string,name:string}>, closure: array<int, array{term_id:int,taxonomy:string,parent:int,slug:string,name:string}>}|null */
	private function term_snapshot( int $product_id, string $taxonomy ): ?array {
		if ( $product_id <= 0 || '' === $taxonomy ) {
			$this->mark_unavailable( 'Product-term snapshot identity is invalid.' );
			return null;
		}
		if ( ! isset( $this->authoritative_product_terms[ $product_id ][ $taxonomy ] ) ) {
			$this->mark_unavailable( 'Authoritative product-term snapshot is unavailable.' );
			return null;
		}
		return $this->authoritative_product_terms[ $product_id ][ $taxonomy ];
	}

	/** @param mixed $term @return array{term_id:int,taxonomy:string,parent:int,slug:string,name:string}|null */
	private function term_object_record( $term, string $taxonomy ): ?array {
		if ( ! $term instanceof \WP_Term
			|| ! isset( $term->term_id, $term->taxonomy, $term->parent, $term->slug, $term->name )
			|| ! is_int( $term->term_id ) || $term->term_id <= 0
			|| ! is_int( $term->parent ) || $term->parent < 0
			|| ! is_string( $term->taxonomy ) || $term->taxonomy !== $taxonomy
			|| ! is_string( $term->slug ) || '' === $term->slug
			|| ! is_string( $term->name ) ) {
			return null;
		}
		return array(
			'term_id'  => $term->term_id,
			'taxonomy' => $term->taxonomy,
			'parent'   => $term->parent,
			'slug'     => $term->slug,
			'name'     => $term->name,
		);
	}

	/**
	 * Lee relaciones y cierre de ancestros directamente de tablas Core.
	 *
	 * @param array<int, int>    $ids        Productos.
	 * @param array<int, string> $taxonomies Taxonomías registradas.
	 * @return array{relations: array<string, array<int, array<int, int>>>, terms: array<string, array{term_id:int,taxonomy:string,parent:int,slug:string,name:string}>}|null
	 */
	private function authoritative_term_source( array $ids, array $taxonomies ): ?array {
		if ( ! is_object( $this->db )
			|| ! isset( $this->db->term_relationships, $this->db->term_taxonomy, $this->db->terms )
			|| ! is_callable( array( $this->db, 'prepare' ) )
			|| ! is_callable( array( $this->db, 'get_results' ) ) ) {
			$this->mark_unavailable( 'Authoritative product term tables are unavailable.' );
			return null;
		}

		$relations = array();
		foreach ( $taxonomies as $taxonomy ) {
			foreach ( $ids as $id ) {
				$relations[ $taxonomy ][ $id ] = array();
			}
		}
		$id_map       = array_fill_keys( $ids, true );
		$taxonomy_map = array_fill_keys( $taxonomies, true );
		$id_marks     = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$tax_marks    = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );
		$sql = $this->read(
			fn() => $this->db->prepare(
				"SELECT tr.object_id, t.term_id, t.name, t.slug, tt.taxonomy, tt.parent
				FROM {$this->db->term_relationships} tr
				INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$this->db->terms} t ON t.term_id = tt.term_id
				WHERE tr.object_id IN ({$id_marks}) AND tt.taxonomy IN ({$tax_marks})
				ORDER BY tr.object_id ASC, tt.taxonomy ASC, t.term_id ASC",
				...array_merge( $ids, $taxonomies )
			),
			'Could not prepare authoritative product-term relationships.'
		);
		if ( ! $this->available || ! is_string( $sql ) || '' === trim( $sql ) ) {
			$this->mark_unavailable( 'Authoritative product-term query has an invalid shape.' );
			return null;
		}
		$rows = $this->read(
			fn() => $this->db->get_results( $sql, ARRAY_A ),
			'Could not read authoritative product-term relationships.'
		);
		if ( ! $this->available || ! is_array( $rows ) ) {
			$this->mark_unavailable( 'Authoritative product-term relationships have an invalid shape.' );
			return null;
		}

		$records = array();
		$seen_relations = array();
		foreach ( $rows as $row ) {
			$record   = $this->term_record( $row );
			$object_id = is_array( $row ) ? $this->positive_integer( $row['object_id'] ?? null ) : null;
			if ( null === $record || null === $object_id
				|| ! isset( $id_map[ $object_id ], $taxonomy_map[ $record['taxonomy'] ] ) ) {
				$this->mark_unavailable( 'Authoritative product-term relationships contain invalid data.' );
				return null;
			}
			$relation_key = $object_id . ':' . $record['taxonomy'] . ':' . $record['term_id'];
			if ( isset( $seen_relations[ $relation_key ] ) ) {
				$this->mark_unavailable( 'Authoritative product-term relationships contain duplicates.' );
				return null;
			}
			$seen_relations[ $relation_key ] = true;
			$relations[ $record['taxonomy'] ][ $object_id ][] = $record['term_id'];
			if ( ! $this->merge_term_record( $records, $record ) ) {
				return null;
			}
		}

		// Completar padres por olas. Cada par taxonomy:id debe tener una fila
		// exacta; un padre ausente, duplicado o que cruza taxonomía cierra la foto.
		$pending = array();
		foreach ( $records as $record ) {
			if ( $record['parent'] > 0 ) {
				$pending[ $record['taxonomy'] . ':' . $record['parent'] ] = array( $record['taxonomy'], $record['parent'] );
			}
		}
		$rounds = 0;
		while ( array() !== $pending ) {
			if ( ++$rounds > 100 || count( $records ) + count( $pending ) > 10000 ) {
				$this->mark_unavailable( 'Product taxonomy ancestry exceeds its safety bound.' );
				return null;
			}
			foreach ( array_keys( $pending ) as $key ) {
				if ( isset( $records[ $key ] ) ) {
					unset( $pending[ $key ] );
				}
			}
			if ( array() === $pending ) {
				break;
			}

			$parent_ids  = array_values( array_unique( array_map( static fn( array $pair ): int => $pair[1], $pending ), SORT_NUMERIC ) );
			$parent_tax  = array_values( array_unique( array_map( static fn( array $pair ): string => $pair[0], $pending ), SORT_STRING ) );
			$id_marks    = implode( ', ', array_fill( 0, count( $parent_ids ), '%d' ) );
			$tax_marks   = implode( ', ', array_fill( 0, count( $parent_tax ), '%s' ) );
			$parent_sql = $this->read(
				fn() => $this->db->prepare(
					"SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent
					FROM {$this->db->terms} t
					INNER JOIN {$this->db->term_taxonomy} tt ON tt.term_id = t.term_id
					WHERE t.term_id IN ({$id_marks}) AND tt.taxonomy IN ({$tax_marks})
					ORDER BY tt.taxonomy ASC, t.term_id ASC",
					...array_merge( $parent_ids, $parent_tax )
				),
				'Could not prepare authoritative product term ancestors.'
			);
			if ( ! $this->available || ! is_string( $parent_sql ) || '' === trim( $parent_sql ) ) {
				$this->mark_unavailable( 'Authoritative product term ancestor query has an invalid shape.' );
				return null;
			}
			$parent_rows = $this->read(
				fn() => $this->db->get_results( $parent_sql, ARRAY_A ),
				'Could not read authoritative product term ancestors.'
			);
			if ( ! $this->available || ! is_array( $parent_rows ) ) {
				$this->mark_unavailable( 'Authoritative product term ancestors have an invalid shape.' );
				return null;
			}
			$found = array();
			foreach ( $parent_rows as $row ) {
				$record = $this->term_record( $row );
				if ( null === $record ) {
					$this->mark_unavailable( 'Authoritative product term ancestor contains invalid data.' );
					return null;
				}
				$key = $record['taxonomy'] . ':' . $record['term_id'];
				if ( ! isset( $pending[ $key ] ) ) {
					continue;
				}
				if ( isset( $found[ $key ] ) || ! $this->merge_term_record( $records, $record ) ) {
					$this->mark_unavailable( 'Authoritative product term ancestor is not unique.' );
					return null;
				}
				$found[ $key ] = true;
			}
			if ( count( $found ) !== count( $pending ) ) {
				$this->mark_unavailable( 'Authoritative product term ancestor coverage is incomplete.' );
				return null;
			}
			$pending = array();
			foreach ( $found as $key => $_present ) {
				$record = $records[ $key ];
				if ( $record['parent'] > 0 ) {
					$pending[ $record['taxonomy'] . ':' . $record['parent'] ] = array( $record['taxonomy'], $record['parent'] );
				}
			}
		}

		foreach ( $records as $record ) {
			$seen = array();
			$cursor = $record;
			while ( $cursor['parent'] > 0 ) {
				$key = $cursor['taxonomy'] . ':' . $cursor['parent'];
				if ( isset( $seen[ $key ] ) || ! isset( $records[ $key ] ) ) {
					$this->mark_unavailable( 'Product taxonomy ancestry is cyclic or incomplete.' );
					return null;
				}
				$seen[ $key ] = true;
				$cursor = $records[ $key ];
			}
		}

		return array( 'relations' => $relations, 'terms' => $records );
	}

	/** @param mixed $raw @return array{term_id:int,taxonomy:string,parent:int,slug:string,name:string}|null */
	private function term_record( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$term_id = $this->positive_integer( $raw['term_id'] ?? null );
		$parent  = $this->non_negative_integer( $raw['parent'] ?? null );
		$taxonomy = $raw['taxonomy'] ?? null;
		$slug     = $raw['slug'] ?? null;
		$name     = $raw['name'] ?? null;
		if ( null === $term_id || null === $parent || ! is_string( $taxonomy )
			|| ! is_string( $slug ) || ! is_string( $name )
			|| '' === $taxonomy || '' === $slug ) {
			return null;
		}
		return compact( 'term_id', 'taxonomy', 'parent', 'slug', 'name' );
	}

	/** @param array<string, array{term_id:int,taxonomy:string,parent:int,slug:string,name:string}> $records */
	private function merge_term_record( array &$records, array $record ): bool {
		$key = $record['taxonomy'] . ':' . $record['term_id'];
		if ( isset( $records[ $key ] ) && $records[ $key ] !== $record ) {
			$this->mark_unavailable( 'Authoritative term identity is inconsistent.' );
			return false;
		}
		foreach ( $records as $existing ) {
			if ( $existing['term_id'] === $record['term_id'] && $existing['taxonomy'] !== $record['taxonomy'] ) {
				$this->mark_unavailable( 'Shared term identifiers are not authoritative.' );
				return false;
			}
		}
		$records[ $key ] = $record;
		return true;
	}

	/** @param mixed $value */
	private function positive_integer( $value ): ?int {
		$integer = $this->non_negative_integer( $value );
		return null !== $integer && $integer > 0 ? $integer : null;
	}

	/** @param mixed $value */
	private function non_negative_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9]\d*)$/D', $value ) ) {
			$integer = (int) $value;
			return (string) $integer === $value ? $integer : null;
		}
		return null;
	}

	/** @param mixed $value */
	private function canonical_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|-?[1-9]\d*)$/D', $value ) ) {
			$integer = (int) $value;
			return (string) $integer === $value ? $integer : null;
		}
		return null;
	}

	/** @param mixed $map @param array<int, int> $ids */
	private function valid_cache_map( $map, array $ids ): bool {
		if ( ! is_array( $map ) ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( ! array_key_exists( $id, $map ) || ! is_array( $map[ $id ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param mixed $map @param array<int, int> $ids */
	private function valid_write_map( $map, array $ids ): bool {
		if ( ! is_array( $map ) ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( ! array_key_exists( $id, $map ) || true !== $map[ $id ] ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<int, int> $ids @param array<int, string> $groups */
	private function evict( array $ids, array $groups ): void {
		foreach ( $groups as $group ) {
			foreach ( $ids as $id ) {
				$deleted = $this->read(
					static fn() => wp_cache_delete( $id, $group ),
					'Could not invalidate a product source cache.'
				);
				if ( ! $this->available || ! is_bool( $deleted ) ) {
					$this->mark_unavailable( 'Product source cache deletion returned an invalid shape.' );
					return;
				}

				// `false` puede significar miss o fallo según el backend. Sólo una
				// lectura forzada que confirme ausencia autoriza continuar a la foto B.
				$found = null;
				$this->read(
					static function () use ( $id, $group, &$found ) {
						return wp_cache_get( $id, $group, true, $found );
					},
					'Could not verify product source cache invalidation.'
				);
				if ( ! $this->available ) {
					return;
				}
				if ( ! is_bool( $found ) || $found ) {
					$this->mark_unavailable( 'Product source cache invalidation could not be verified.' );
					return;
				}
			}
		}
	}

	/** @param array<int, int> $ids */
	private function cleanup( array $ids ): void {
		$taxonomies = array();
		try {
			$raw = get_object_taxonomies( 'product', 'names' );
			if ( is_array( $raw ) ) {
				foreach ( $raw as $taxonomy ) {
					if ( is_string( $taxonomy ) && '' !== $taxonomy ) {
						$taxonomies[] = $taxonomy . '_relationships';
					}
				}
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
		$groups = array_merge( array( 'posts', 'post_meta', 'terms', 'term_meta' ), $taxonomies );
		foreach ( array_values( array_unique( $groups, SORT_STRING ) ) as $group ) {
			foreach ( $ids as $id ) {
				try {
					wp_cache_delete( $id, $group );
				} catch ( \Throwable $throwable ) {
					unset( $throwable );
				}
			}
		}
	}

	/** @param array<int, mixed> $ids @return array<int, int> */
	private function normalize_ids( array $ids ): array {
		$out = array();
		foreach ( $ids as $raw_id ) {
			if ( ! is_int( $raw_id ) && ( ! is_string( $raw_id ) || 1 !== preg_match( '/^[1-9]\d*$/D', $raw_id ) ) ) {
				continue;
			}
			$id = (int) $raw_id;
			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}
		}
		return array_values( $out );
	}

	/** @return mixed */
	private function read( callable $reader, string $fallback ) {
		if ( ! $this->available ) {
			return null;
		}
		$this->clear_db_error();
		try {
			$value = $reader();
		} catch ( \Throwable $throwable ) {
			$this->mark_unavailable( $throwable->getMessage() ?: $fallback );
			return null;
		}
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $value ) ) {
			$this->mark_unavailable( $fallback );
			return null;
		}
		if ( '' !== $this->database_error() ) {
			$this->mark_unavailable( $fallback );
			return null;
		}
		return $value;
	}

	private function clear_db_error(): void {
		if ( is_object( $this->db ) && property_exists( $this->db, 'last_error' ) ) {
			$this->db->last_error = '';
		}
	}

}
