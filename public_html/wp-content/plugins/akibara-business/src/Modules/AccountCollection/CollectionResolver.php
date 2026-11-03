<?php
/**
 * Mi cuenta — resolución privada de la colección comprada.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AccountCollection;

use Akibara\Business\Contracts\EtaProvider;
use Akibara\Business\Modules\BackInStock\CaptureForm;
use Akibara\Business\Modules\SeriesBundles\BuilderWidget;
use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Services\VolumeVariant;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * Agrupa el histórico neto del cliente por una única serie y lo compara con
 * el catálogo actual. Es una vista privada: no aplica las reglas SEO/noindex de
 * los directorios públicos y nunca descarta una compra por stock o visibilidad.
 */
final class CollectionResolver {

	public const USER_META_MANUAL_OWNED = '_akb_collection_manual_owned';

	private CustomerCollection $customer_collection;

	/** Base de datos inyectable para pruebas; null usa el `$wpdb` global. */
	private ?object $database;

	/** Fuente canónica opcional de fechas de preventa. */
	private ?EtaProvider $eta;

	/** Resolvedor puro de la siguiente acción editorial. */
	private ContinuityResolver $continuity;

	/**
	 * @param CustomerCollection     $customer_collection Histórico neto de compras.
	 * @param object|null            $database            Dependencia compatible con wpdb.
	 * @param EtaProvider|null       $eta                 Fuente de fechas de preventa.
	 * @param ContinuityResolver|null $continuity          Resolución pura de continuidad.
	 */
	/** @var Settings|null */
	private ?Settings $settings;

	/** @var (callable(int): bool)|null */
	private $byp_checker;

	/**
	 * @param CustomerCollection      $customer_collection Autoridad de pedidos pagados.
	 * @param object|null             $database            Abstracción de $wpdb.
	 * @param EtaProvider|null        $eta                 Proveedor de fechas estimadas.
	 * @param ContinuityResolver|null $continuity          Resolución pura de continuidad.
	 * @param Settings|null           $settings            Configuración de SeriesBundles.
	 * @param (callable(int): bool)|null $byp_checker      Comprobador opcional de BYP por término.
	 */
	public function __construct(
		CustomerCollection $customer_collection,
		?object $database = null,
		?EtaProvider $eta = null,
		?ContinuityResolver $continuity = null,
		?Settings $settings = null,
		?callable $byp_checker = null
	) {
		$this->customer_collection = $customer_collection;
		$this->database            = $database;
		$this->eta                 = $eta;
		$this->continuity          = $continuity ?? new ContinuityResolver();
		$this->settings            = $settings;
		$this->byp_checker         = $byp_checker;
	}

	/**
	 * Comprueba si una serie tiene activo el módulo de packs por volumen (BYP).
	 */
	private function is_byp_enabled_for_term( int $term_id ): bool {
		if ( null !== $this->byp_checker ) {
			return (bool) ( $this->byp_checker )( $term_id );
		}
		if ( null !== $this->settings ) {
			return $this->settings->is_byp_enabled_for_term( $term_id );
		}
		if ( class_exists( Settings::class ) ) {
			return ( new Settings() )->is_byp_enabled_for_term( $term_id );
		}
		return true;
	}

	/**
	 * IDs de productos marcados manualmente por el cliente en su estantería.
	 *
	 * @return list<int>
	 */
	public static function manual_owned_product_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::USER_META_MANUAL_OWNED, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return self::normalize_ids( $raw );
	}

	/**
	 * Marca o desmarca un producto como tenido manualmente en la estantería del cliente.
	 * Invalida la generación de colección del usuario para forzar refresco inmediato.
	 */
	public static function mark_manual_owned( int $user_id, int $product_id, bool $owned ): bool {
		if ( $user_id <= 0 || $product_id <= 0 ) {
			return false;
		}

		$current = self::manual_owned_product_ids( $user_id );
		$lookup  = array_fill_keys( $current, true );
		if ( $owned ) {
			$lookup[ $product_id ] = true;
		} else {
			unset( $lookup[ $product_id ] );
		}

		$updated = array_values( array_keys( $lookup ) );
		sort( $updated, SORT_NUMERIC );
		update_user_meta( $user_id, self::USER_META_MANUAL_OWNED, $updated );

		// Invalida la generación de colección para que el resolver refresque en la siguiente consulta.
		update_user_meta( $user_id, '_akb_byp_owned_generation', (string) microtime( true ) );

		return true;
	}

	/**
	 * Resuelve toda la colección del cliente sin queries por serie o producto.
	 *
	 * `missing_catalog` son los productos publicados y visibles de la serie que
	 * el cliente aún no compró. `historical_only` son compras que ya no forman
	 * parte de ese catálogo actual (ocultas, despublicadas o eliminadas).
	 *
	 * @return array{
	 *   status:'ok'|'empty'|'unavailable',
	 *   series:list<array<string,mixed>>,
	 *   unclassified_product_ids:list<int>,
	 *   unclassified_count:int
	 * }
	 */
	public function resolve( int $user_id ): array {
		$owned_result = $this->customer_collection->owned_product_ids_result( $user_id );
		$manual_ids   = self::manual_owned_product_ids( $user_id );

		if ( CustomerCollection::RESULT_UNAVAILABLE === $owned_result['status'] && empty( $manual_ids ) ) {
			return self::root_result( CustomerCollection::RESULT_UNAVAILABLE );
		}

		if ( CustomerCollection::RESULT_EMPTY === $owned_result['status'] && empty( $manual_ids ) ) {
			return self::root_result( CustomerCollection::RESULT_EMPTY );
		}

		$order_product_ids = is_array( $owned_result['product_ids'] ?? null ) ? $owned_result['product_ids'] : array();
		$owned_product_ids = self::normalize_ids( array_merge( $order_product_ids, $manual_ids ) );
		if ( array() === $owned_product_ids ) {
			return self::root_result( CustomerCollection::RESULT_EMPTY );
		}

		$memberships = $this->query_memberships( $owned_product_ids );
		if ( null === $memberships ) {
			return self::root_result( CustomerCollection::RESULT_UNAVAILABLE );
		}

		$classified   = array();
		$unclassified = array();
		foreach ( $owned_product_ids as $product_id ) {
			$product_memberships = $memberships[ $product_id ] ?? array();
			if ( 1 !== count( $product_memberships ) ) {
				$unclassified[ $product_id ] = $product_id;
				continue;
			}

			$membership = reset( $product_memberships );
			if ( ! is_array( $membership ) ) {
				return self::root_result( CustomerCollection::RESULT_UNAVAILABLE );
			}

			$term_taxonomy_id = $membership['term_taxonomy_id'];
			if ( ! isset( $classified[ $term_taxonomy_id ] ) ) {
				$classified[ $term_taxonomy_id ] = array(
					'term_id'     => $membership['term_id'],
					'product_ids' => array(),
				);
			}
			$classified[ $term_taxonomy_id ]['product_ids'][ $product_id ] = $product_id;
		}

		$terms = $this->load_terms( $classified );
		if ( null === $terms ) {
			return self::root_result( CustomerCollection::RESULT_UNAVAILABLE );
		}

		foreach ( $classified as $term_taxonomy_id => $group ) {
			if ( isset( $terms[ $term_taxonomy_id ] ) ) {
				continue;
			}

			foreach ( $group['product_ids'] as $product_id ) {
				$unclassified[ $product_id ] = $product_id;
			}
			unset( $classified[ $term_taxonomy_id ] );
		}

		if ( array() === $classified ) {
			return self::root_result(
				CustomerCollection::RESULT_OK,
				array(),
				self::sorted_ids( $unclassified )
			);
		}

		$catalog = $this->query_catalog_product_ids( array_keys( $classified ) );
		if ( null === $catalog ) {
			return self::root_result( CustomerCollection::RESULT_UNAVAILABLE );
		}

		$all_product_ids = $owned_product_ids;
		foreach ( $catalog as $catalog_product_ids ) {
			$all_product_ids = array_merge( $all_product_ids, $catalog_product_ids );
		}
		$product_dtos = $this->product_dtos( self::normalize_ids( $all_product_ids ) );

		$series = array();
		foreach ( $classified as $term_taxonomy_id => $group ) {
			$term             = $terms[ $term_taxonomy_id ];
			$owned_ids        = self::order_ids_by_product( array_values( $group['product_ids'] ), $product_dtos );
			$catalog_ids      = self::order_ids_by_product( $catalog[ $term_taxonomy_id ] ?? array(), $product_dtos );
			$owned_lookup     = array_fill_keys( $owned_ids, true );
			$catalog_lookup   = array_fill_keys( $catalog_ids, true );
			$owned_in_catalog = array_values(
				array_filter(
					$owned_ids,
					static fn ( int $product_id ): bool => isset( $catalog_lookup[ $product_id ] )
				)
			);
			$missing_catalog     = self::missing_by_volume(
				$catalog_ids,
				$owned_lookup,
				self::owned_volume_lookup( $owned_ids, $product_dtos ),
				$product_dtos
			);
			$historical_only     = array_values(
				array_filter(
					$owned_ids,
					static fn ( int $product_id ): bool => ! isset( $catalog_lookup[ $product_id ] )
				)
			);
			$owned_products       = self::select_owned_product_dtos( $owned_ids, $catalog_lookup, $product_dtos );
			$catalog_products     = self::select_product_dtos( $catalog_ids, $product_dtos );
			$owned_volume_count   = self::logical_volume_count( $owned_products );
			$edition              = $this->edition_progress( (int) $term->term_id, $owned_volume_count );
			$continuity_result    = $this->resolve_continuity( $owned_products, $catalog_products, $edition );
			$catalog_products     = $continuity_result['catalog_products'];
			$continuity           = $continuity_result['continuity'];
			$missing_lookup       = array_fill_keys( $missing_catalog, true );
			$missing_products     = array_values(
				array_filter(
					$catalog_products,
					static fn ( array $product ): bool => isset( $missing_lookup[ (int) ( $product['id'] ?? 0 ) ] )
				)
			);
			$continuity_product   = is_array( $continuity['product'] ?? null ) ? $continuity['product'] : array();
			$cover_product_id     = (int) ( $continuity_product['id'] ?? ( $owned_products[0]['id'] ?? 0 ) );
			$term_url_result      = get_term_link( $term );
			$term_url             = is_string( $term_url_result ) ? $term_url_result : '';
			$product_fallback_url = isset( $continuity_product['url'] ) && is_string( $continuity_product['url'] )
				? $continuity_product['url']
				: '';
			$completion_url       = '' !== $term_url
				? rtrim( $term_url, '/' ) . '/#completar-coleccion'
				: $product_fallback_url;

			$bundle_suggestion = null;
			$missing_count     = count( $missing_products );
			$is_byp_enabled    = $this->is_byp_enabled_for_term( (int) $term->term_id );

			if ( $is_byp_enabled && $missing_count >= 2 && '' !== $completion_url ) {
				$has_discount    = $missing_count >= 5;
				$suggested_count = min( 5, $missing_count );
				$label           = $has_discount
					? sprintf( '🔥 Completa más rápido: Lleva los siguientes %d tomos en Arma tu Pack con descuento →', $suggested_count )
					: sprintf( '📚 Te faltan solo %d tomos para estar al día: Selecciónalos juntos aquí →', $missing_count );

				$bundle_suggestion = array(
					'available'     => true,
					'has_discount'  => $has_discount,
					'count'         => $suggested_count,
					'total_missing' => $missing_count,
					'url'           => $completion_url,
					'label'         => $label,
				);
			}

			$series[] = array(
				'term_id'                     => (int) $term->term_id,
				'term_taxonomy_id'            => (int) $term->term_taxonomy_id,
				'name'                        => (string) $term->name,
				'slug'                        => (string) $term->slug,
				'url'                         => $term_url,
				'completion_url'              => $completion_url,
				'owned_product_ids'           => $owned_ids,
				'owned_count'                 => count( $owned_ids ),
				'owned_volume_count'          => $owned_volume_count,
				'owned_products'              => $owned_products,
				'manual_owned_product_ids'    => array_values( array_intersect( $owned_ids, $manual_ids ) ),
				'catalog_product_ids'         => $catalog_ids,
				'catalog_count'               => count( $catalog_ids ),
				'catalog_products'            => $catalog_products,
				'owned_in_catalog_product_ids' => $owned_in_catalog,
				'owned_in_catalog_count'      => count( $owned_in_catalog ),
				// Estos dos hablan en TOMOS, no en productos: un número con dos
				// versiones se nombra una vez. Ver `missing_by_volume()`.
				'missing_catalog_product_ids' => $missing_catalog,
				'missing_catalog_count'       => count( $missing_catalog ),
				'missing_catalog_products'    => $missing_products,
				'historical_only_product_ids' => $historical_only,
				'historical_only_count'       => count( $historical_only ),
				'cover_product_id'            => (int) $cover_product_id,
				'edition'                     => $edition,
				'continuity'                  => $continuity,
				'bundle_suggestion'           => $bundle_suggestion,
			);
		}

		usort( $series, array( self::class, 'compare_series' ) );

		return self::root_result(
			CustomerCollection::RESULT_OK,
			$series,
			self::sorted_ids( $unclassified )
		);
	}

	/**
	 * Loads every `pa_serie` membership for the purchased IDs in one query.
	 *
	 * @param list<int> $product_ids Purchased parent-product IDs.
	 * @return array<int,array<int,array{term_id:int,term_taxonomy_id:int}>>|null
	 */
	private function query_memberships( array $product_ids ): ?array {
		$database = $this->database();
		if (
			null === $database
			|| ! method_exists( $database, 'prepare' )
			|| ! method_exists( $database, 'get_results' )
			|| ! isset( $database->term_relationships, $database->term_taxonomy )
		) {
			return null;
		}

		$relationships_table = (string) $database->term_relationships;
		$taxonomy_table      = (string) $database->term_taxonomy;
		$id_placeholders     = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from wpdb and the placeholder list contains generated `%d` tokens only.
		$query = $database->prepare(
			"SELECT
				relationship.object_id AS product_id,
				taxonomy.term_id,
				taxonomy.term_taxonomy_id
			FROM {$relationships_table} relationship
			INNER JOIN {$taxonomy_table} taxonomy
				ON taxonomy.term_taxonomy_id = relationship.term_taxonomy_id
				AND taxonomy.taxonomy = %s
			WHERE relationship.object_id IN ({$id_placeholders})
			ORDER BY relationship.object_id ASC, taxonomy.term_taxonomy_id ASC",
			...array_merge( array( SeriesCatalogEligibility::TAXONOMY ), $product_ids )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $this->read_rows( $database, $query );
		if ( null === $rows ) {
			return null;
		}

		$requested   = array_fill_keys( $product_ids, true );
		$memberships = array_fill_keys( $product_ids, array() );
		foreach ( $rows as $row ) {
			$product_id       = self::normalize_id( $row['product_id'] ?? null );
			$term_id          = self::normalize_id( $row['term_id'] ?? null );
			$term_taxonomy_id = self::normalize_id( $row['term_taxonomy_id'] ?? null );
			if ( $product_id < 1 || $term_id < 1 || $term_taxonomy_id < 1 || ! isset( $requested[ $product_id ] ) ) {
				return null;
			}

			if (
				isset( $memberships[ $product_id ][ $term_taxonomy_id ] )
				&& $term_id !== $memberships[ $product_id ][ $term_taxonomy_id ]['term_id']
			) {
				return null;
			}

			$memberships[ $product_id ][ $term_taxonomy_id ] = array(
				'term_id'          => $term_id,
				'term_taxonomy_id' => $term_taxonomy_id,
			);
		}

		return $memberships;
	}

	/**
	 * Loads current published/catalogue-visible products for every series in one
	 * query. Stock is deliberately absent: an out-of-stock volume still belongs
	 * to the current edition and must remain visible as missing.
	 *
	 * @param list<int> $term_taxonomy_ids Series term-taxonomy IDs.
	 * @return array<int,list<int>>|null
	 */
	private function query_catalog_product_ids( array $term_taxonomy_ids ): ?array {
		$database = $this->database();
		if (
			null === $database
			|| ! method_exists( $database, 'prepare' )
			|| ! method_exists( $database, 'get_results' )
			|| ! isset( $database->posts, $database->terms, $database->term_taxonomy, $database->term_relationships )
		) {
			return null;
		}

		$posts_table         = (string) $database->posts;
		$terms_table         = (string) $database->terms;
		$taxonomy_table      = (string) $database->term_taxonomy;
		$relationships_table = (string) $database->term_relationships;
		$id_placeholders     = implode( ', ', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from wpdb and the placeholder list contains generated `%d` tokens only.
		$query = $database->prepare(
			"SELECT
				series_relationship.term_taxonomy_id,
				product.ID AS product_id
			FROM {$relationships_table} series_relationship
			INNER JOIN {$taxonomy_table} series_taxonomy
				ON series_taxonomy.term_taxonomy_id = series_relationship.term_taxonomy_id
				AND series_taxonomy.taxonomy = %s
			INNER JOIN {$posts_table} product
				ON product.ID = series_relationship.object_id
				AND product.post_type = %s
				AND product.post_status = %s
			WHERE series_relationship.term_taxonomy_id IN ({$id_placeholders})
				AND NOT EXISTS (
					SELECT 1
					FROM {$relationships_table} hidden_relationship
					INNER JOIN {$taxonomy_table} hidden_taxonomy
						ON hidden_taxonomy.term_taxonomy_id = hidden_relationship.term_taxonomy_id
					INNER JOIN {$terms_table} hidden_term
						ON hidden_term.term_id = hidden_taxonomy.term_id
					WHERE hidden_relationship.object_id = product.ID
						AND hidden_taxonomy.taxonomy = %s
						AND hidden_term.slug = %s
				)
			ORDER BY series_relationship.term_taxonomy_id ASC, product.ID ASC",
			...array_merge(
				array( SeriesCatalogEligibility::TAXONOMY, 'product', 'publish' ),
				$term_taxonomy_ids,
				array( SeriesCatalogEligibility::VISIBILITY_TAXONOMY, SeriesCatalogEligibility::HIDDEN_FROM_CATALOG )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $this->read_rows( $database, $query );
		if ( null === $rows ) {
			return null;
		}

		$requested = array_fill_keys( $term_taxonomy_ids, true );
		$catalog   = array_fill_keys( $term_taxonomy_ids, array() );
		foreach ( $rows as $row ) {
			$term_taxonomy_id = self::normalize_id( $row['term_taxonomy_id'] ?? null );
			$product_id       = self::normalize_id( $row['product_id'] ?? null );
			if ( $term_taxonomy_id < 1 || $product_id < 1 || ! isset( $requested[ $term_taxonomy_id ] ) ) {
				return null;
			}

			$catalog[ $term_taxonomy_id ][ $product_id ] = $product_id;
		}

		foreach ( $catalog as $term_taxonomy_id => $product_ids ) {
			$catalog[ $term_taxonomy_id ] = array_values( $product_ids );
		}

		return $catalog;
	}

	/**
	 * Loads included terms together. `hide_empty=false` and no noindex filter are
	 * intentional: a private collection must retain a purchased series even when
	 * it no longer qualifies for a public directory.
	 *
	 * @param array<int,array{term_id:int,product_ids:array<int,int>}> $classified Groups by term-taxonomy ID.
	 * @return array<int,object>|null Terms keyed by term-taxonomy ID.
	 */
	private function load_terms( array $classified ): ?array {
		if ( array() === $classified ) {
			return array();
		}

		$term_ids = array();
		foreach ( $classified as $group ) {
			$term_ids[] = $group['term_id'];
		}

		$loaded = get_terms(
			array(
				'taxonomy'               => SeriesCatalogEligibility::TAXONOMY,
				'hide_empty'             => false,
				'include'                => self::normalize_ids( $term_ids ),
				'orderby'                => 'include',
				'update_term_meta_cache' => true,
			)
		);
		if ( $loaded instanceof \WP_Error || ! is_array( $loaded ) ) {
			return null;
		}

		$expected = array_fill_keys( array_keys( $classified ), true );
		$terms    = array();
		foreach ( $loaded as $term ) {
			if (
				! is_object( $term )
				|| ! isset( $term->term_id, $term->term_taxonomy_id, $term->taxonomy, $term->name, $term->slug )
				|| SeriesCatalogEligibility::TAXONOMY !== (string) $term->taxonomy
			) {
				continue;
			}

			$term_id          = self::normalize_id( $term->term_id );
			$term_taxonomy_id = self::normalize_id( $term->term_taxonomy_id );
			if (
				$term_id < 1
				|| $term_taxonomy_id < 1
				|| ! isset( $expected[ $term_taxonomy_id ] )
				|| $classified[ $term_taxonomy_id ]['term_id'] !== $term_id
			) {
				continue;
			}

			$terms[ $term_taxonomy_id ] = $term;
		}

		return $terms;
	}

	/**
	 * Primes every product together, then builds link-ready DTOs from cache.
	 * `_prime_post_caches()` bounds post/meta/term reads independently of the
	 * number of purchased or missing volumes.
	 *
	 * @param list<int> $product_ids All relevant products.
	 * @return array<int,array{id:int,title:string,url:string,volume_number:?int,is_variant:bool,stock_status:string,is_purchasable:bool,preorder_label:string,availability_state:string,waitlist_url:string}>
	 */
	private function product_dtos( array $product_ids ): array {
		if ( array() === $product_ids ) {
			return array();
		}

		_prime_post_caches( $product_ids, true, true );

		$products = array();
		foreach ( $product_ids as $product_id ) {
			$title = get_the_title( $product_id );
			$url   = get_permalink( $product_id );
			$title = is_string( $title ) ? trim( $title ) : '';
			$url   = is_string( $url ) ? $url : '';

			$products[ $product_id ] = array(
				'id'               => $product_id,
				'title'            => '' !== $title ? $title : 'Producto #' . $product_id,
				'url'              => $url,
				'volume_number'    => VolumeTitle::extract_number( $title ),
				// `_prime_post_caches()` de arriba ya trajo la postmeta de todos
				// estos productos, así que la marca no cuesta una consulta más.
				'is_variant'       => VolumeVariant::is_variant( $product_id ),
				'stock_status'       => 'unknown',
				'is_purchasable'     => false,
				'preorder_label'     => '',
				// El verbo del CTA depende del estado comercial neutro, no de preorder_label.
				'availability_state' => '',
				'waitlist_url'       => '',
			);
		}

		return $products;
	}

	/**
	 * Hidrata stock/ETA sólo para las variantes del tomo candidato. Una edición
	 * larga puede contener cientos de productos: construir objetos WooCommerce
	 * para todos en cada visita privada sería trabajo inútil y abriría un N por
	 * cliente. El primer pase puro identifica el número; el segundo resuelve sólo
	 * sus opciones y vuelve a decidir la variante primaria.
	 *
	 * @param list<array<string,mixed>> $owned_products   Compras de la edición.
	 * @param list<array<string,mixed>> $catalog_products Catálogo básico actual.
	 * @param array<string,mixed>       $edition          Progreso editorial.
	 * @return array{continuity:array<string,mixed>,catalog_products:list<array<string,mixed>>}
	 */
	private function resolve_continuity( array $owned_products, array $catalog_products, array $edition ): array {
		// El primer pase pide SÓLO el número del tomo: ejecutar un `resolve()`
		// entero para leerle un campo significaba normalizar y ranurar el catálogo
		// de la edición dos veces por serie de la colección.
		$volume_number = $this->continuity->candidate_volume_number( $owned_products, $catalog_products, $edition );
		if ( null === $volume_number || $volume_number < 1 ) {
			return array(
				'continuity'      => $this->continuity->resolve( $owned_products, $catalog_products, $edition ),
				'catalog_products' => $catalog_products,
			);
		}

		$waitlist_active = (bool) apply_filters( 'akibara_loop_waitlist_active', false );
		$waitlist_anchor = CaptureForm::anchor();
		try {
			$eta_tz = StoreTime::zone();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$eta_tz = new \DateTimeZone( 'UTC' );
		}
		$eta_year = (int) ( new \DateTimeImmutable( 'now', $eta_tz ) )->format( 'Y' );

		foreach ( $catalog_products as &$product_dto ) {
			if ( $volume_number !== ( $product_dto['volume_number'] ?? null ) ) {
				continue;
			}

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_dto['id'] ) : null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$stock_status  = StockStatus::of( $product );
			$is_purchasable = $product->is_purchasable()
				&& in_array( $stock_status, array( StockStatus::IN_STOCK, StockStatus::ON_BACKORDER ), true );
			$preorder_label     = '';
			$availability_state = '';
			if ( StockStatus::ON_BACKORDER === $stock_status ) {
				$eta_result = null;
				if ( null !== $this->eta ) {
					try {
						$eta_result = $this->eta->calculate( $product );
					} catch ( \Throwable $exception ) {
						unset( $exception );
					}
				}
				// Reutiliza el producto ya resuelto. preorder_label es texto editable;
				// availability_state gobierna el verbo del CTA independientemente del copy.
				$availability_state = Availability::of( $product );
				$preorder_label     = BuilderWidget::preventa_label( $eta_result, $eta_year, $eta_tz, Availability::label_of( $product ) );
			}

			$product_dto['stock_status']       = $stock_status;
			$product_dto['is_purchasable']     = $is_purchasable;
			$product_dto['preorder_label']     = $preorder_label;
			$product_dto['availability_state'] = $availability_state;
			$product_dto['waitlist_url']        = $waitlist_active
				&& StockStatus::OUT_OF_STOCK === $stock_status
				&& '' !== $product_dto['url']
					? $product_dto['url'] . $waitlist_anchor
					: '';
		}
		unset( $product_dto );

		return array(
			'continuity'       => $this->continuity->resolve( $owned_products, $catalog_products, $edition ),
			'catalog_products' => $catalog_products,
		);
	}

	/**
	 * Uses only EDICIÓN metadata. Work totals describe a different entity and
	 * are deliberately irrelevant to what this customer can complete.
	 *
	 * @return array{state:string,total:int,progress_owned:?int,missing_count:?int,is_complete:?bool}
	 */
	private function edition_progress( int $term_id, ?int $owned_count ): array {
		$scoped = PublicationStatus::scoped_for_term( $term_id );
		$state  = (string) $scoped['edicion']['estado'];
		$total  = (int) $scoped['edicion']['tomos'];
		$result = array(
			'state'          => $state,
			'total'          => $total,
			'progress_owned' => null,
			'missing_count'  => null,
			'is_complete'    => null,
		);

		if (
			null === $owned_count
			|| $total < 1
			|| $owned_count > $total
			|| ! in_array( $state, array( PublicationStatus::COMPLETE, PublicationStatus::ONGOING ), true )
		) {
			return $result;
		}

		$result['progress_owned'] = $owned_count;
		$result['missing_count']  = $total - $owned_count;
		$result['is_complete']    = PublicationStatus::COMPLETE === $state && $owned_count === $total;

		return $result;
	}

	/**
	 * Executes one already prepared SELECT and rejects ambiguous wpdb state.
	 *
	 * @return list<array<string,mixed>>|null
	 */
	private function read_rows( object $database, $query ): ?array {
		if (
			! is_string( $query )
			|| '' === $query
			|| ( isset( $database->ready ) && ! (bool) $database->ready )
		) {
			return null;
		}

		$rows = $database->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared by the caller; request-local private projection.
		if (
			! is_array( $rows )
			|| ( isset( $database->last_error ) && '' !== trim( (string) $database->last_error ) )
			|| ( isset( $database->ready ) && ! (bool) $database->ready )
			|| ( isset( $database->last_query ) && (string) $database->last_query !== $query )
		) {
			return null;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return null;
			}
		}

		return array_values( $rows );
	}

	/** Returns the injected database or the normal WordPress global. */
	private function database(): ?object {
		if ( null !== $this->database ) {
			return $this->database;
		}

		global $wpdb;

		return isset( $wpdb ) && is_object( $wpdb ) ? $wpdb : null;
	}

	/**
	 * @param array<int,array<string,mixed>> $products Products by ID.
	 */
	private static function order_ids_by_product( array $product_ids, array $products ): array {
		$product_ids = self::normalize_ids( $product_ids );
		usort(
			$product_ids,
			static function ( int $left, int $right ) use ( $products ): int {
				$left_volume  = $products[ $left ]['volume_number'] ?? null;
				$right_volume = $products[ $right ]['volume_number'] ?? null;
				if ( is_int( $left_volume ) && is_int( $right_volume ) && $left_volume !== $right_volume ) {
					return $left_volume <=> $right_volume;
				}
				if ( is_int( $left_volume ) !== is_int( $right_volume ) ) {
					return is_int( $left_volume ) ? -1 : 1;
				}

				$left_title  = $products[ $left ]['title'] ?? '';
				$right_title = $products[ $right ]['title'] ?? '';
				$comparison  = strnatcasecmp( $left_title, $right_title );

				return 0 !== $comparison ? $comparison : ( $left <=> $right );
			}
		);

		return $product_ids;
	}

	/**
	 * @param list<int>                                      $product_ids Ordered IDs.
	 * @param array<int,array<string,mixed>> $products   Products by ID.
	 * @return list<array<string,mixed>>
	 */
	private static function select_product_dtos( array $product_ids, array $products ): array {
		$selected = array();
		foreach ( $product_ids as $product_id ) {
			if ( isset( $products[ $product_id ] ) ) {
				$selected[] = $products[ $product_id ];
			}
		}

		return $selected;
	}

	/**
	 * Números de tomo que el cliente YA tiene, vengan del producto que vengan.
	 *
	 * Se construye sobre TODAS las compras de la edición, incluidas las que ya
	 * no están en el catálogo: quien compró el tomo 12 lo tiene aunque su ficha
	 * se haya reemplazado por otra. Los productos sin número inequívoco no
	 * aportan cobertura — no se puede afirmar qué posición ocupan.
	 *
	 * @param list<int>                      $owned_ids Compras de la edición.
	 * @param array<int,array<string,mixed>> $products  DTOs por ID.
	 * @return array<int,true>
	 */
	private static function owned_volume_lookup( array $owned_ids, array $products ): array {
		$volumes = array();
		foreach ( $owned_ids as $product_id ) {
			$volume_number = $products[ $product_id ]['volume_number'] ?? null;
			if ( is_int( $volume_number ) && $volume_number > 0 ) {
				$volumes[ $volume_number ] = true;
			}
		}

		return $volumes;
	}

	/**
	 * Qué le falta al cliente, contado en TOMOS y no en productos.
	 *
	 * Ésta es la pregunta que contesta la pantalla: «¿puedo leer la serie de
	 * corrido?». Restar por `product_id` contestaba otra —«¿cuántos objetos del
	 * catálogo no tengo?»— y las dos coincidían sólo mientras ningún número de
	 * tomo tuviera dos productos. En cuanto una edición especial entra en su
	 * serie, la resta por id le dice «te falta 1 tomo» a quien tiene la serie
	 * completa y le ofrece el mismo contenido otra vez.
	 *
	 * Dos reglas, y la simétrica vale igual:
	 *   - un número cubierto por CUALQUIER versión no falta (tener el especial
	 *     del 23 tapa el hueco del 23, y al revés);
	 *   - un número que sí falta se nombra UNA vez, y se nombra con el TOMO
	 *     NORMAL: entre dos productos del mismo número gana el que no está
	 *     marcado como variante. Antes acá se tomaba el primero del orden de
	 *     `order_ids_by_product()` y el docblock afirmaba que ése era el tomo
	 *     normal, cosa que ese orden no garantiza: ordena por `strnatcasecmp`
	 *     del título, y «One Piece 05 (Edición Especial)» va ANTES que «One
	 *     Piece nº 05» porque '0' < 'n'. La marca del dueño es lo único que lo
	 *     sabe, así que es lo que decide.
	 *
	 * Los productos sin número inequívoco (packs, integrales, `1.5`) no se
	 * colapsan: no hay posición que compartir, así que cada uno cuenta por sí.
	 * Es el mismo criterio de `logical_volume_count()` y de
	 * `ContinuityResolver::logical_slots()`, que ya contestaban por número; lo
	 * que cambia es que este campo deje de contradecirlos.
	 *
	 * @param list<int>                      $catalog_ids  Catálogo público ordenado.
	 * @param array<int,true>                $owned_lookup Productos comprados.
	 * @param array<int,true>                $owned_volumes Números ya cubiertos.
	 * @param array<int,array<string,mixed>> $products     DTOs por ID.
	 * @return list<int>
	 */
	private static function missing_by_volume(
		array $catalog_ids,
		array $owned_lookup,
		array $owned_volumes,
		array $products
	): array {
		$missing = array();
		$named   = array();

		foreach ( $catalog_ids as $product_id ) {
			if ( isset( $owned_lookup[ $product_id ] ) ) {
				continue;
			}

			$volume_number = $products[ $product_id ]['volume_number'] ?? null;
			$is_variant    = true === ( $products[ $product_id ]['is_variant'] ?? false );

			if ( is_int( $volume_number ) && $volume_number > 0 ) {
				if ( isset( $owned_volumes[ $volume_number ] ) ) {
					continue;
				}

				if ( isset( $named[ $volume_number ] ) ) {
					// El número ya está nombrado. Sólo se reemplaza al que lo
					// representa si el que estaba era una variante y éste no: el
					// hueco se queda donde apareció por primera vez —ése es el
					// orden que lee el cliente— y cambia únicamente el producto
					// que se le ofrece.
					$slot = $named[ $volume_number ];
					if ( ! $is_variant && true === ( $products[ $missing[ $slot ] ]['is_variant'] ?? false ) ) {
						$missing[ $slot ] = $product_id;
					}
					continue;
				}

				$named[ $volume_number ] = count( $missing );
			}

			$missing[] = $product_id;
		}

		return $missing;
	}

	/**
	 * Cuenta tomos lógicos, no IDs de producto: reediciones y portadas
	 * alternativas del mismo número ocupan una única posición. Los especiales
	 * sin numeración inequívoca no se fuerzan dentro de una secuencia inventada.
	 *
	 * @param list<array<string,mixed>> $products Productos comprados.
	 */
	private static function logical_volume_count( array $products ): ?int {
		$volumes = array();
		foreach ( $products as $product ) {
			$volume_number = $product['volume_number'] ?? null;
			if ( ! is_int( $volume_number ) || $volume_number < 1 ) {
				return null;
			}
			$volumes[ $volume_number ] = true;
		}

		return count( $volumes );
	}

	/**
	 * Purchased products outside the public catalogue remain visible as text,
	 * never as links that may be private, hidden or already return a 404.
	 *
	 * @param list<int>                                      $product_ids    Ordered purchased IDs.
	 * @param array<int,bool>                                $catalog_lookup Current public catalogue IDs.
	 * @param array<int,array<string,mixed>> $products       Products by ID.
	 * @return list<array<string,mixed>>
	 */
	private static function select_owned_product_dtos( array $product_ids, array $catalog_lookup, array $products ): array {
		$selected = self::select_product_dtos( $product_ids, $products );
		foreach ( $selected as &$product ) {
			if ( ! isset( $catalog_lookup[ $product['id'] ] ) ) {
				$product['url'] = '';
			}
		}
		unset( $product );

		return $selected;
	}

	/** Ordena primero las tarjetas accionables y después por nombre visible. */
	private static function compare_series( array $left, array $right ): int {
		$priority = self::continuity_priority( $left['continuity'] ?? array() )
			<=> self::continuity_priority( $right['continuity'] ?? array() );
		if ( 0 !== $priority ) {
			return $priority;
		}

		$comparison = strnatcasecmp( (string) $left['name'], (string) $right['name'] );

		return 0 !== $comparison ? $comparison : ( (int) $left['term_id'] <=> (int) $right['term_id'] );
	}

	/** @param array<string,mixed> $continuity Estado semántico de continuidad. */
	private static function continuity_priority( array $continuity ): int {
		$kind         = (string) ( $continuity['kind'] ?? 'unknown' );
		$availability = (string) ( $continuity['availability'] ?? 'unknown' );

		if ( 'available' === $availability ) {
			return 'gap' === $kind ? 0 : 1;
		}
		if ( 'preorder' === $availability ) {
			return 2;
		}
		if ( 'out_of_stock' === $availability ) {
			return 3;
		}
		if ( in_array( $kind, array( 'gap', 'next', 'start' ), true ) ) {
			return 4;
		}
		if ( 'up_to_date' === $kind ) {
			return 5;
		}
		if ( 'complete' === $kind ) {
			return 6;
		}

		return 7;
	}

	/**
	 * @param array<int,mixed> $values Raw IDs.
	 * @return list<int>
	 */
	private static function normalize_ids( array $values ): array {
		$ids = array();
		foreach ( $values as $value ) {
			$id = self::normalize_id( $value );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/** Normalizes one positive integer ID without accepting lossy values. */
	private static function normalize_id( $value ): int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return 0;
		}

		$value = (int) $value;

		return $value > 0 ? $value : 0;
	}

	/** @param array<int,int> $ids IDs keyed or indexed. */
	private static function sorted_ids( array $ids ): array {
		$ids = self::normalize_ids( array_values( $ids ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * @param 'ok'|'empty'|'unavailable' $status       Resolution state.
	 * @param list<array<string,mixed>>  $series       Series cards.
	 * @param list<int>                  $unclassified Purchased IDs without one usable series.
	 * @return array{status:'ok'|'empty'|'unavailable',series:list<array<string,mixed>>,unclassified_product_ids:list<int>,unclassified_count:int}
	 */
	private static function root_result( string $status, array $series = array(), array $unclassified = array() ): array {
		return array(
			'status'                   => $status,
			'series'                   => $series,
			'unclassified_product_ids' => $unclassified,
			'unclassified_count'       => count( $unclassified ),
		);
	}
}
