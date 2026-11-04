<?php
/**
 * Publica hechos de obra, edición y catálogo en las landings de serie.
 *
 * Los metadatos editoriales proceden del dueño; los agregados de tomos, estado,
 * precios, autor, editorial y fecha proceden del catálogo vivo. No genera prosa
 * editorial, valores supuestos ni placeholders. Sin productos publicados no
 * imprime bloque. Los ceros verdaderos de disponible y reservable se muestran;
 * Agotado se omite cuando vale cero. El vocabulario comercial es compartido.
 *
 * render_summary() presenta una línea sobre la rejilla en prioridad2 con el
 * estado de edición u obra y un enlace a la ficha completa. result_count_extra()
 * aporta el enlace estable al último tomo dentro de la línea del total. render()
 * presenta la ficha después de paginación, en prioridad20, y antes de la guía,
 * en prioridad25. El mismo orden del servidor sirve móvil y escritorio para
 * conservar foco y secuencia de lectura bajo la caché compartida.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFacts;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\VolumeVariant;
use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

/**
 * Ficha de datos del catálogo de una serie.
 *
 * @since 1.100.0
 */
final class SeriesFacts {

	/** Taxonomía de serie. Las landings viven en sus archives. */
	private const TAXONOMY = 'pa_serie';

	/** Taxonomía de editorial en product_brand (WooCommerce 9.4+). */
	private const BRAND_TAXONOMY = 'product_brand';

	/** Taxonomía de autor en pa_autor. */
	private const AUTHOR_TAXONOMY = 'pa_autor';

	/** Ancla de la ficha completa: el resumen de arriba enlaza aquí. */
	public const SHEET_ID = 'ficha-de-la-serie';

	/** Texto del enlace del resumen a la ficha (Akibara → Textos). */
	public const OPTION_SUMMARY_LINK = 'akb_copy_series_summary_link';

	/** El de fábrica. */
	public const DEFAULT_SUMMARY_LINK = 'Ver ficha completa';

	/** Texto del atajo a la ficha del tomo más nuevo (Akibara → Textos). */
	public const OPTION_LATEST_VOLUME = 'akb_copy_series_latest_volume';

	/** El de fábrica. */
	public const DEFAULT_LATEST_VOLUME = 'Último tomo: {tomo}';

	/** Hueco del número del tomo en el atajo. */
	public const VOLUME_MARKER = '{tomo}';

	/** Separador de listas en SQL. */
	private const LIST_SEPARATOR = SeriesFactsShaper::LIST_SEPARATOR;

	/** Separador de pares en SQL. */
	private const PAIR_SEPARATOR = SeriesFactsShaper::PAIR_SEPARATOR;

	/** @var array<int,int> Series que deben recibir una segunda rotación al cierre. */
	private array $pending_final_series_invalidations = array();

	/** @var array<int,true> Primera rotación ya hecha en este request. */
	private array $initial_series_invalidations = array();

	/** Evita registrar más de un finalizador de generaciones. */
	private bool $generation_finalizer_registered = false;

	/** Una falla sin identidad exige otra rotación global al cierre. */
	private bool $pending_global_generation_finalize = false;

	/** Coalesce el fallback global cuando varias consultas fallan juntas. */
	private bool $fallback_invalidation_triggered = false;

	/**
	 * Memoiza hechos verificados por serie, petición y conexión de base de datos.
	 *
	 * @var \WeakMap<object, array<int, array{generation: string, facts: array<string, mixed>}>>|null
	 */
	private static ?\WeakMap $verified_facts = null;

	/**
	 * Gestor de caché y generaciones durables.
	 */
	private SeriesFactsCache $cache;

	/**
	 * Predicado inyectado: ¿hay un override de precio en runtime?
	 *
	 * @var callable|null
	 */
	private $price_is_overridden;

	/**
	 * @param callable|null $price_is_overridden Devuelve true si algún filtro de
	 *                                           runtime está alterando el precio
	 *                                           del catálogo.
	 */
	public function __construct( ?callable $price_is_overridden = null ) {
		$this->price_is_overridden = $price_is_overridden;
		$this->cache               = new SeriesFactsCache();
	}

	/**
	 * option → de fábrica, para CopyManager (exposición y saneado obligatorio).
	 *
	 * @return array<string, string>
	 */
	public static function copy_defaults(): array {
		return array(
			self::OPTION_SUMMARY_LINK  => self::DEFAULT_SUMMARY_LINK,
			self::OPTION_LATEST_VOLUME => self::DEFAULT_LATEST_VOLUME,
		);
	}

	/**
	 * Registra resumen, ficha completa, hoja propia e invalidación de sus hechos.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render_summary' ), 2 );
		add_action( 'woocommerce_after_shop_loop', array( $this, 'render' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_filter( 'akb_search_result_count_extra', array( $this, 'result_count_extra' ) );
		add_filter( 'litespeed_ucss_whitelist', array( $this, 'allowlist_ucss_selectors' ), 20 );

		add_action( 'woocommerce_product_set_stock_status', array( $this, 'invalidate_product' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'invalidate_product_object' ), 10, 1 );

		add_action( 'save_post_product', array( $this, 'invalidate_product' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'invalidate_product' ), 10, 1 );
		add_action( 'added_post_meta', array( $this, 'invalidate_on_product_meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'invalidate_on_product_meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'invalidate_on_product_meta_changed' ), 10, 3 );

		add_action( 'set_object_terms', array( $this, 'invalidate_on_term_change' ), 10, 6 );
		add_action( 'deleted_term_relationships', array( $this, 'invalidate_on_deleted_term_relationships' ), 10, 3 );

		add_action( 'edited_' . self::BRAND_TAXONOMY, array( $this, 'invalidate_on_catalog_term_edited' ), 10, 2 );
		add_action( 'edited_' . self::AUTHOR_TAXONOMY, array( $this, 'invalidate_on_catalog_term_edited' ), 10, 2 );
	}

	/**
	 * Clases de la ficha que el UCSS de LiteSpeed NO debe podar.
	 *
	 * @internal Callback del filtro `litespeed_ucss_whitelist`.
	 *
	 * @param array<int, string> $selectors Lista acumulada.
	 * @return array<int, string>
	 */
	public function allowlist_ucss_selectors( array $selectors ): array {
		$selectors[] = '.akb-series-facts__vols';
		$selectors[] = '.akb-series-facts__link';
		$selectors[] = '.akb-series-facts__note';
		$selectors[] = '.akb-series-latest';
		$selectors[] = '.akb-result-count--extra';
		$selectors[] = '.akb-series-summary__item';
		$selectors[] = '.akb-series-summary__note';

		return array_values( array_unique( $selectors ) );
	}

	/**
	 * Borra la ficha cacheada de todas las series de un producto.
	 *
	 * @param int|string $product_id ID del producto.
	 * @return void
	 */
	public function invalidate_product( $product_id ): void {
		$this->invalidate_series_identifiers( $this->series_identifiers_of( (int) $product_id ) );
	}

	/**
	 * Variante para los hooks que entregan el objeto en vez del ID.
	 *
	 * @param mixed $product Instancia de `WC_Product`.
	 * @return void
	 */
	public function invalidate_product_object( $product ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$this->invalidate_product( (int) $product->get_id() );
	}

	/**
	 * Metas que cambian los hechos públicos sin pasar por save_post_product.
	 *
	 * @var array<int, string>
	 */
	private const WATCHED_PRODUCT_META = array( '_thumbnail_id', VolumeVariant::META_KEY );

	/**
	 * Cubre cambios directos de meta que no pasan por `save_post_product`.
	 *
	 * @param mixed  $meta_id    Meta ID, no usado.
	 * @param int    $object_id  Producto afectado.
	 * @param string $meta_key   Clave modificada.
	 * @return void
	 */
	public function invalidate_on_product_meta_changed( $meta_id, int $object_id, string $meta_key ): void {
		unset( $meta_id );
		if ( ! in_array( $meta_key, self::WATCHED_PRODUCT_META, true ) || 'product' !== get_post_type( $object_id ) ) {
			return;
		}

		$this->invalidate_product( $object_id );
	}

	/**
	 * Reacción a reasignaciones de taxonomía sobre un producto.
	 *
	 * @param int|string           $object_id  Producto afectado.
	 * @param array<int,int>|mixed $terms      Términos asignados.
	 * @param array<int,int>|mixed $tt_ids     Nuevos term_taxonomy_id.
	 * @param string               $taxonomy   Taxonomía modificada.
	 * @param bool                 $append     Si se acumulan o reemplazan.
	 * @param array<int,int>|mixed $old_tt_ids Anteriores term_taxonomy_id.
	 * @return void
	 */
	public function invalidate_on_term_change( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ): void {
		unset( $terms, $append );

		if (
			in_array(
				(string) $taxonomy,
				array( SeriesCatalogEligibility::VISIBILITY_TAXONOMY, self::BRAND_TAXONOMY, self::AUTHOR_TAXONOMY ),
				true
			)
		) {
			$this->invalidate_product( (int) $object_id );
			return;
		}

		if ( self::TAXONOMY !== (string) $taxonomy ) {
			return;
		}

		$ids = array_merge(
			is_array( $tt_ids ) ? $tt_ids : array(),
			is_array( $old_tt_ids ) ? $old_tt_ids : array()
		);

		$this->invalidate_series_term_taxonomy_ids( $ids );
	}

	/**
	 * Invalida relaciones eliminadas por `wp_remove_object_terms()`.
	 *
	 * @param int|string           $object_id Producto.
	 * @param array<int,int>|mixed $tt_ids    Relaciones eliminadas.
	 * @param string               $taxonomy  Taxonomía afectada.
	 * @return void
	 */
	public function invalidate_on_deleted_term_relationships( $object_id, $tt_ids, string $taxonomy ): void {
		if ( in_array( $taxonomy, array( SeriesCatalogEligibility::VISIBILITY_TAXONOMY, self::BRAND_TAXONOMY, self::AUTHOR_TAXONOMY ), true ) ) {
			$this->invalidate_product( (int) $object_id );
			return;
		}

		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}

		$this->invalidate_series_term_taxonomy_ids( is_array( $tt_ids ) ? $tt_ids : array() );
	}

	/**
	 * Invalida las series asociadas cuando cambia un término de marca o autor.
	 *
	 * @param int|string $term_id          ID del término, no usado.
	 * @param int|string $term_taxonomy_id TTID editado.
	 * @return void
	 */
	public function invalidate_on_catalog_term_edited( $term_id, $term_taxonomy_id ): void {
		unset( $term_id );
		$term_taxonomy_id = (int) $term_taxonomy_id;
		if ( $term_taxonomy_id < 1 ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			$this->handle_identifier_lookup_failure();
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombres de tabla desde $wpdb; los valores viajan por placeholders.
		$queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : null;
		$raw_series     = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT CONCAT(series_taxonomy.term_taxonomy_id, ':', series_taxonomy.term_id)
				FROM {$wpdb->term_relationships} changed_relationship
				INNER JOIN {$wpdb->term_relationships} series_relationship
					ON series_relationship.object_id = changed_relationship.object_id
				INNER JOIN {$wpdb->term_taxonomy} series_taxonomy
					ON series_taxonomy.term_taxonomy_id = series_relationship.term_taxonomy_id
					AND series_taxonomy.taxonomy = %s
				WHERE changed_relationship.term_taxonomy_id = %d",
				self::TAXONOMY,
				$term_taxonomy_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->identifier_lookup_is_reliable( $raw_series, $queries_before ) ) {
			$this->handle_identifier_lookup_failure();
			return;
		}

		$this->invalidate_series_identifiers( SeriesFactsCache::normalize_series_identifiers( $raw_series ) );
	}

	/**
	 * Borra los datos derivados y el HTML de cada serie indicada por TTID.
	 *
	 * @param array<int,int|string> $term_taxonomy_ids Identificadores de relaciones.
	 * @return void
	 */
	private function invalidate_series_term_taxonomy_ids( array $term_taxonomy_ids ): void {
		$term_taxonomy_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $term_taxonomy_ids ),
					static fn( int $term_taxonomy_id ): bool => $term_taxonomy_id > 0
				)
			)
		);

		if ( array() === $term_taxonomy_ids ) {
			return;
		}

		$this->begin_series_invalidations(
			array_map(
				static fn( int $term_taxonomy_id ): array => array(
					'term_taxonomy_id' => $term_taxonomy_id,
					'term_id'          => 0,
				),
				$term_taxonomy_ids
			)
		);

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			$this->handle_identifier_lookup_failure();
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
		$args         = array_merge( array( self::TAXONOMY ), $term_taxonomy_ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombres de tabla desde $wpdb; cantidad de placeholders construida desde IDs enteros normalizados.
		$queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : null;
		$raw_series     = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT CONCAT(tt.term_taxonomy_id, ':', tt.term_id)
				FROM {$wpdb->term_taxonomy} tt
				WHERE tt.taxonomy = %s
					AND tt.term_taxonomy_id IN ({$placeholders})",
				...$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->identifier_lookup_is_reliable( $raw_series, $queries_before ) ) {
			$this->handle_identifier_lookup_failure();
			return;
		}

		$resolved_series = SeriesFactsCache::normalize_series_identifiers( $raw_series );
		$this->begin_series_invalidations( $resolved_series );
		foreach ( $resolved_series as $series ) {
			if ( $series['term_id'] > 0 ) {
				do_action( 'litespeed_purge', 'T.' . $series['term_id'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
			}
		}
	}

	/**
	 * Borra el transient y la página completa de una lista ya normalizada.
	 *
	 * @param list<array{term_taxonomy_id:int,term_id:int}> $series Series afectadas.
	 * @return void
	 */
	private function invalidate_series_identifiers( array $series ): void {
		$this->begin_series_invalidations( $series );

		foreach ( $series as $identifier ) {
			if ( $identifier['term_id'] > 0 ) {
				do_action( 'litespeed_purge', 'T.' . $identifier['term_id'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
			}
		}
	}

	/**
	 * Identificadores de las series de un producto.
	 *
	 * @param int $product_id Producto.
	 * @return list<array{term_taxonomy_id:int,term_id:int}>
	 */
	private function series_identifiers_of( int $product_id ): array {
		global $wpdb;

		if ( $product_id < 1 ) {
			return array();
		}
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			$this->handle_identifier_lookup_failure();
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombres de tabla desde $wpdb; los valores viajan por placeholders.
		$queries_before = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : null;
		$ids            = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT CONCAT(tt.term_taxonomy_id, ':', tt.term_id)
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tr.object_id = %d AND tt.taxonomy = %s",
				$product_id,
				self::TAXONOMY
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->identifier_lookup_is_reliable( $ids, $queries_before ) ) {
			$this->handle_identifier_lookup_failure();
			return array();
		}

		return SeriesFactsCache::normalize_series_identifiers( $ids );
	}

	/**
	 * Distingue un conjunto vacío real del `[]` que wpdb devuelve al fallar.
	 *
	 * @param mixed    $rows           Resultado de `get_col()`.
	 * @param int|null $queries_before Contador anterior, si está disponible.
	 * @return bool
	 */
	private function identifier_lookup_is_reliable( $rows, ?int $queries_before ): bool {
		if ( ! is_array( $rows ) ) {
			return false;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		if ( isset( $wpdb->ready ) && ! (bool) $wpdb->ready ) {
			return false;
		}

		$query_ran = null === $queries_before
			|| ! isset( $wpdb->num_queries )
			|| (int) $wpdb->num_queries > $queries_before;

		return ! ( $query_ran && isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) );
	}

	/**
	 * Abre una invalidación con una generación nueva y agenda su cierre.
	 *
	 * @param list<array{term_taxonomy_id:int,term_id:int}> $series Series afectadas.
	 * @return void
	 */
	private function begin_series_invalidations( array $series ): void {
		foreach ( $series as $identifier ) {
			$term_taxonomy_id = max( 0, (int) ( $identifier['term_taxonomy_id'] ?? 0 ) );
			$term_id          = max( 0, (int) ( $identifier['term_id'] ?? 0 ) );
			if ( $term_taxonomy_id < 1 ) {
				continue;
			}

			self::forget_verified( $term_taxonomy_id );
			delete_transient( $this->cache->cache_key( $term_taxonomy_id ) );
			delete_transient( SeriesFactsCache::TRANSIENT_PREFIX . $term_taxonomy_id );

			if ( $term_id > 0 ) {
				$this->pending_final_series_invalidations[ $term_taxonomy_id ] = $term_id;
			} elseif ( ! isset( $this->pending_final_series_invalidations[ $term_taxonomy_id ] ) ) {
				$this->pending_final_series_invalidations[ $term_taxonomy_id ] = 0;
			}

			if ( isset( $this->initial_series_invalidations[ $term_taxonomy_id ] ) ) {
				continue;
			}

			$this->initial_series_invalidations[ $term_taxonomy_id ] = true;
			if ( ! $this->cache->rotate_series_cache_generation( $term_taxonomy_id ) ) {
				$this->handle_identifier_lookup_failure();
			}
		}

		if ( array() !== $this->pending_final_series_invalidations ) {
			$this->schedule_generation_finalizer();
		}
	}

	/**
	 * Cierra las generaciones y repite la purga exacta antes de LiteSpeed.
	 *
	 * @internal Callback de `shutdown` a prioridad -100.
	 * @return void
	 */
	public function finalize_cache_invalidations(): void {
		$series                                   = $this->pending_final_series_invalidations;
		$this->pending_final_series_invalidations = array();

		foreach ( $series as $term_taxonomy_id => $term_id ) {
			self::forget_verified( (int) $term_taxonomy_id );
			delete_transient( $this->cache->cache_key( (int) $term_taxonomy_id ) );
			if ( ! $this->cache->rotate_series_cache_generation( (int) $term_taxonomy_id ) ) {
				$this->cache->rotate_global_cache_generation();
			}
			if ( $term_id > 0 ) {
				do_action( 'litespeed_purge', 'T.' . $term_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
			}
		}

		if ( $this->pending_global_generation_finalize ) {
			$this->pending_global_generation_finalize = false;
			self::forget_verified( null );
			$this->cache->rotate_global_cache_generation();
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fallback global sólo cuando no existe un TTID resoluble.
		}
	}

	/** Registra una sola barrera tardía, antes del header final de LiteSpeed. */
	private function schedule_generation_finalizer(): void {
		if ( $this->generation_finalizer_registered ) {
			return;
		}
		$this->generation_finalizer_registered = true;
		add_action( 'shutdown', array( $this, 'finalize_cache_invalidations' ), -100 );
	}

	/**
	 * Fallback excepcional: rota la generación y purga página global una vez.
	 *
	 * @return void
	 */
	private function handle_identifier_lookup_failure(): void {
		self::forget_verified( null );
		if ( $this->fallback_invalidation_triggered ) {
			return;
		}
		$this->fallback_invalidation_triggered = true;

		if ( ! $this->cache->rotate_global_cache_generation() ) {
			$this->cache->set_local_global_generation( $this->cache->next_cache_generation() );
		}
		$this->pending_global_generation_finalize = true;
		$this->schedule_generation_finalizer();

		$this->mark_page_uncacheable( 'series facts invalidation unavailable' );
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fallback global sólo cuando no existe un TTID resoluble.
	}

	/**
	 * Resumen de serie estable bajo paginación, orden y facetas.
	 *
	 * @return void
	 */
	public function render_summary(): void {
		$facts = $this->renderable_facts();
		if ( null === $facts ) {
			return;
		}

		SeriesFactsView::render_summary( $facts, $this->price_range_is_publishable( $facts ) );
	}

	/**
	 * Añade a la línea del total un enlace a la ficha del último tomo de la serie.
	 *
	 * @param mixed $html Lo que ya aportaron otros módulos.
	 * @return string HTML escapado.
	 */
	public function result_count_extra( $html ): string {
		$html  = is_string( $html ) ? $html : '';
		$facts = $this->renderable_facts();

		return SeriesFactsView::result_count_extra( $html, $facts );
	}

	/**
	 * Imprime la ficha completa de una serie con productos publicados, bajo la rejilla.
	 *
	 * @return void
	 */
	public function render(): void {
		$facts = $this->renderable_facts();
		if ( null === $facts ) {
			return;
		}

		SeriesFactsView::render( $facts, $this->price_range_is_publishable( $facts ) );
	}

	/**
	 * Encola la hoja del resumen, el atajo y la ficha en las landings de serie.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( 0 === $this->queried_series_tt_id() ) {
			return;
		}

		wp_enqueue_style(
			'akb-series-facts',
			AKIBARA_BUSINESS_URL . 'assets/css/series-facts.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/series-facts.css' )
		);
	}

	/**
	 * Hechos de la serie consultada, o null si este request no debe pintar ni el
	 * resumen ni la ficha.
	 *
	 * @return array<string,mixed>|null
	 */
	private function renderable_facts(): ?array {
		$term_taxonomy_id = $this->queried_series_tt_id();
		if ( 0 === $term_taxonomy_id ) {
			return null;
		}

		$facts = $this->facts( $term_taxonomy_id );
		if ( null === $facts || $facts['tomos'] < 1 ) {
			return null;
		}

		return $facts;
	}

	/**
	 * `term_taxonomy_id` de la serie consultada, o 0 si este request no debe
	 * imprimir la ficha.
	 *
	 * @return int
	 */
	private function queried_series_tt_id(): int {
		if ( is_admin() || ! is_tax( self::TAXONOMY ) ) {
			return 0;
		}

		$term = get_queried_object();
		if ( ! is_object( $term )
			|| ! isset( $term->taxonomy, $term->term_taxonomy_id )
			|| self::TAXONOMY !== (string) $term->taxonomy ) {
			return 0;
		}

		return max( 0, (int) $term->term_taxonomy_id );
	}

	/**
	 * Los MISMOS hechos que pinta el bloque, para quien deba declararlos en otro lenguaje.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return array{tomos:int,instock:int,preventa:int,agotados:int,productos:int,productos_instock:int,productos_preventa:int,productos_agotados:int,agotados_tomos:array<int,string>,price_min:?float,price_max:?float,updated:string,autores:array<int,array{name:string,term_id:int}>,editoriales:array<int,array{name:string,term_id:int}>,ultimo_tomo:array{id:int,numero:int}|null}|null
	 */
	public function catalog_facts( int $term_taxonomy_id ): ?array {
		return $this->facts( $term_taxonomy_id );
	}

	/**
	 * ¿El rango de precios de este agregado se puede publicar?
	 *
	 * @param array{price_min:?float,price_max:?float} $facts Hechos normalizados.
	 * @return bool
	 */
	public function price_range_is_publishable( array $facts ): bool {
		if ( null === ( $facts['price_min'] ?? null ) || null === ( $facts['price_max'] ?? null ) ) {
			return false;
		}

		return ! ( null !== $this->price_is_overridden && (bool) ( $this->price_is_overridden )() );
	}

	/**
	 * Lee hechos cacheados por término con verificación de generación durable.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return array{tomos:int,instock:int,preventa:int,agotados:int,productos:int,productos_instock:int,productos_preventa:int,productos_agotados:int,agotados_tomos:array<int,string>,price_min:?float,price_max:?float,updated:string,autores:array<int,array{name:string,term_id:int}>,editoriales:array<int,array{name:string,term_id:int}>,ultimo_tomo:array{id:int,numero:int}|null}|null
	 */
	private function facts( int $term_taxonomy_id ): ?array {
		$verified = self::verified( $term_taxonomy_id );
		if ( null !== $verified ) {
			$now = $this->cache->cache_generation_snapshot( $term_taxonomy_id, true );
			if ( null !== $now && hash_equals( $verified['generation'], $now ) ) {
				return $verified['facts'];
			}
			self::forget_verified( $term_taxonomy_id );
		}

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$generation = $this->cache->cache_generation_snapshot( $term_taxonomy_id, true );
			if ( null === $generation ) {
				$this->mark_page_uncacheable( 'series facts generation unavailable' );
				$row = $this->query_row( $term_taxonomy_id );
				return null === $row ? null : SeriesFactsShaper::shape( $row );
			}
			$key    = $this->cache->cache_key_for_generation( $term_taxonomy_id, $generation );
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['tomos'] ) ) {
				$latest = $this->cache->cache_generation_snapshot( $term_taxonomy_id, true );
				if ( null !== $latest && hash_equals( $generation, $latest ) ) {
					self::remember_verified( $term_taxonomy_id, $generation, $cached );
					return $cached;
				}
				continue;
			}

			$row = $this->query_row( $term_taxonomy_id );
			if ( null === $row ) {
				$this->mark_page_uncacheable( 'series facts unavailable' );
				return null;
			}

			$facts  = SeriesFactsShaper::shape( $row );
			$latest = $this->cache->cache_generation_snapshot( $term_taxonomy_id, true );
			if ( null === $latest || ! hash_equals( $generation, $latest ) ) {
				if ( 0 === $attempt ) {
					continue;
				}
				$this->mark_page_uncacheable( 'series facts cache fill unverified' );
				return $facts;
			}

			set_transient( $key, $facts, 6 * HOUR_IN_SECONDS );

			$latest = $this->cache->cache_generation_snapshot( $term_taxonomy_id, true );
			if ( null === $latest || ! hash_equals( $generation, $latest ) ) {
				delete_transient( $key );
				if ( 0 === $attempt ) {
					continue;
				}
				$this->mark_page_uncacheable( 'series facts cache fill unverified' );
				return $facts;
			}

			self::remember_verified( $term_taxonomy_id, $generation, $facts );
			return $facts;
		}

		$this->mark_page_uncacheable( 'series facts unavailable' );

		return null;
	}

	/**
	 * Hechos ya verificados de esta serie en esta petición.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return array{generation: string, facts: array<string, mixed>}|null
	 */
	private static function verified( int $term_taxonomy_id ): ?array {
		global $wpdb;

		if ( null === self::$verified_facts || ! is_object( $wpdb ) || ! isset( self::$verified_facts[ $wpdb ] ) ) {
			return null;
		}

		return self::$verified_facts[ $wpdb ][ $term_taxonomy_id ] ?? null;
	}

	/**
	 * Guarda lo que acaba de quedar verificado contra la generación durable.
	 *
	 * @param int                  $term_taxonomy_id Término de la serie.
	 * @param string               $generation       Generación contra la que se verificó.
	 * @param array<string, mixed> $facts            Hechos verificados.
	 * @return void
	 */
	private static function remember_verified( int $term_taxonomy_id, string $generation, array $facts ): void {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return;
		}
		self::$verified_facts         ??= new \WeakMap();
		$por_serie                      = self::$verified_facts[ $wpdb ] ?? array();
		$por_serie[ $term_taxonomy_id ] = array(
			'generation' => $generation,
			'facts'      => $facts,
		);
		self::$verified_facts[ $wpdb ]  = $por_serie;
	}

	/**
	 * Descarta lo verificado de una serie, o de todas con null.
	 *
	 * @param int|null $term_taxonomy_id Término de la serie; null = todas.
	 * @return void
	 */
	private static function forget_verified( ?int $term_taxonomy_id ): void {
		global $wpdb;

		if ( null === self::$verified_facts || ! is_object( $wpdb ) || ! isset( self::$verified_facts[ $wpdb ] ) ) {
			return;
		}
		if ( null === $term_taxonomy_id ) {
			unset( self::$verified_facts[ $wpdb ] );
			return;
		}
		$por_serie = self::$verified_facts[ $wpdb ];
		unset( $por_serie[ $term_taxonomy_id ] );
		self::$verified_facts[ $wpdb ] = $por_serie;
	}

	/** Impide que una respuesta de relleno o degradada llegue al page cache. */
	private function mark_page_uncacheable( string $reason ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante pública oficial para impedir page cache.
		}
		do_action( 'litespeed_control_set_nocache', $reason ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
	}

	/**
	 * Una agregación SQL para toda la ficha de la serie.
	 *
	 * @param int $term_taxonomy_id Término de la serie.
	 * @return array<string, mixed>|null Fila cruda, o null si no hay `$wpdb`.
	 */
	private function query_row( int $term_taxonomy_id ): ?array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return null;
		}

		$lookup        = $wpdb->prefix . 'wc_product_meta_lookup';
		$tomo_normal   = VolumeVariant::sql_normal_volume_condition( 'vm.meta_value' );
		$variant_kinds = VolumeVariant::variant_kinds();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- los nombres de tabla salen de $wpdb (prefix y propiedades), nunca de entrada; TODOS los valores viajan por placeholders. El sniff no sabe contar los que llegan con el operador de propagación (`...array_merge()`) y por eso ve «1 parámetro» donde hay 22.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(DISTINCT p.ID) AS productos,
					COUNT(
						DISTINCT CASE WHEN {$tomo_normal} THEN p.ID END
					) AS tomos,
					COUNT(DISTINCT CASE WHEN l.stock_status = %s THEN p.ID END) AS productos_instock,
					COUNT(
						DISTINCT CASE
							WHEN l.stock_status = %s AND {$tomo_normal} THEN p.ID
						END
					) AS instock,
					COUNT(DISTINCT CASE WHEN l.stock_status = %s THEN p.ID END) AS productos_preventa,
					COUNT(
						DISTINCT CASE
							WHEN l.stock_status = %s AND {$tomo_normal} THEN p.ID
						END
					) AS preventa,
					COUNT(DISTINCT CASE WHEN l.stock_status = %s THEN p.ID END) AS productos_agotados,
					COUNT(
						DISTINCT CASE
							WHEN l.stock_status = %s AND {$tomo_normal} THEN p.ID
						END
					) AS agotados,
					GROUP_CONCAT(
						DISTINCT CASE
							WHEN l.stock_status = %s AND {$tomo_normal} THEN p.post_title
						END
						SEPARATOR %s
					) AS agotados_titulos,
					MIN(NULLIF(CASE WHEN l.stock_status <> %s THEN l.min_price END, 0)) AS price_min,
					MAX(NULLIF(CASE WHEN l.stock_status <> %s THEN l.max_price END, 0)) AS price_max,
					MAX(p.post_modified) AS updated,
					GROUP_CONCAT(
						DISTINCT CONCAT_WS(%s, aut.name, aut.term_id)
						ORDER BY CONCAT_WS(%s, aut.name, aut.term_id)
						SEPARATOR %s
					) AS autores,
					GROUP_CONCAT(
						DISTINCT CONCAT_WS(%s, bt.name, bt.term_id)
						ORDER BY CONCAT_WS(%s, bt.name, bt.term_id)
						SEPARATOR %s
					) AS editoriales,
					MAX(
						CASE WHEN {$tomo_normal} THEN CONCAT(p.post_title, %s, p.ID) END
					) AS ultimo_tomo
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				INNER JOIN {$lookup} l ON l.product_id = p.ID
				LEFT JOIN {$wpdb->postmeta} vm ON vm.post_id = p.ID AND vm.meta_key = %s
				LEFT JOIN {$wpdb->term_relationships} btr ON btr.object_id = p.ID
					AND btr.term_taxonomy_id IN (
						SELECT btt2.term_taxonomy_id FROM {$wpdb->term_taxonomy} btt2 WHERE btt2.taxonomy = %s
					)
				LEFT JOIN {$wpdb->term_taxonomy} btt ON btt.term_taxonomy_id = btr.term_taxonomy_id
				LEFT JOIN {$wpdb->terms} bt ON bt.term_id = btt.term_id
				LEFT JOIN {$wpdb->term_relationships} autr ON autr.object_id = p.ID
					AND autr.term_taxonomy_id IN (
						SELECT autt2.term_taxonomy_id FROM {$wpdb->term_taxonomy} autt2 WHERE autt2.taxonomy = %s
					)
				LEFT JOIN {$wpdb->term_taxonomy} autt ON autt.term_taxonomy_id = autr.term_taxonomy_id
				LEFT JOIN {$wpdb->terms} aut ON aut.term_id = autt.term_id
				WHERE tr.term_taxonomy_id = %d
					AND p.post_type = 'product'
					AND p.post_status = 'publish'
					AND NOT EXISTS (
						SELECT 1
						FROM {$wpdb->term_relationships} vtr
						INNER JOIN {$wpdb->term_taxonomy} vtt ON vtt.term_taxonomy_id = vtr.term_taxonomy_id
						INNER JOIN {$wpdb->terms} vt ON vt.term_id = vtt.term_id
						WHERE vtr.object_id = p.ID
							AND vtt.taxonomy = %s
							AND vt.slug = %s
					)",
				...array_merge(
					$variant_kinds,
					array( StockStatus::IN_STOCK ),
					array( StockStatus::IN_STOCK ),
					$variant_kinds,
					array( StockStatus::ON_BACKORDER ),
					array( StockStatus::ON_BACKORDER ),
					$variant_kinds,
					array( StockStatus::OUT_OF_STOCK ),
					array( StockStatus::OUT_OF_STOCK ),
					$variant_kinds,
					array( StockStatus::OUT_OF_STOCK ),
					$variant_kinds,
					array(
						self::LIST_SEPARATOR,
						StockStatus::OUT_OF_STOCK,
						StockStatus::OUT_OF_STOCK,
						self::PAIR_SEPARATOR,
						self::PAIR_SEPARATOR,
						self::LIST_SEPARATOR,
						self::PAIR_SEPARATOR,
						self::PAIR_SEPARATOR,
						self::LIST_SEPARATOR,
					),
					$variant_kinds,
					array(
						self::PAIR_SEPARATOR,
						VolumeVariant::META_KEY,
						self::BRAND_TAXONOMY,
						self::AUTHOR_TAXONOMY,
						$term_taxonomy_id,
						SeriesCatalogEligibility::VISIBILITY_TAXONOMY,
						SeriesCatalogEligibility::HIDDEN_FROM_CATALOG,
					)
				)
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Invalida las entradas de cache que pueden ocultar una escritura directa.
	 *
	 * @param string $option_name Opción a invalidar.
	 * @return void
	 */
	public static function invalidate_generation_option_cache( string $option_name ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Filas de disponibilidad para la ficha y para la tarjeta de guía.
	 *
	 * @param array<string, mixed> $facts Hechos normalizados.
	 * @return array<int,array{status:string,label:string,count:int,texto:string}>
	 */
	public static function stock_rows( array $facts ): array {
		return SeriesFactsView::stock_rows( $facts );
	}
}
