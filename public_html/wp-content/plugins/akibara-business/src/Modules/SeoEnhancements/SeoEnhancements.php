<?php
/**
 * SeoEnhancements — fixes de SEO pequeños a nivel plugin.
 *
 * Rank Math es la fuente única del Product schema. Esta clase conserva los
 * ajustes editoriales/SEO que no compiten con esa propiedad y orquesta los
 * submódulos especializados de Schema, metadatos de serie y metadatos de producto.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoEnhancements;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\SeoIndexing\SeriesDirectorySearchPolicy;
use Akibara\Business\Modules\SeoIndexing\SeriesSitemapLastmod;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesDirectory\SeriesIndex;
use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Utilities\CatalogQueryGrammar;
use Akibara\Business\Utilities\SchemaGraph;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class SeoEnhancements {

	/**
	 * Versión explícita de las superficies públicas de una landing de serie.
	 */
	public const SERIES_RENDERER_VERSION = '2026-08-22.1';

	/** Taxonomía de series. */
	private const SERIES_TAXONOMY = 'pa_serie';

	/** Nombre público exacto del responsable editorial presentado en /nosotros/. */
	private const EDITOR_PERSON_NAME = 'Alejandro Fernández';

	/** Fragmento estable del nodo Person editorial. */
	private const EDITOR_PERSON_FRAGMENT = 'alejandro-fernandez';

	/** Página pública que describe al responsable editorial. */
	private const ABOUT_PATH = '/nosotros/';

	/** Página factual de opciones de despacho y retiro coordinado. */
	private const SHIPPING_PATH = '/envios/';

	/** Procedencia explícita de una descripción persistida por el generador. */
	public const SERIES_DESCRIPTION_OWNERSHIP_KEY = '_akibara_rank_math_description_ownership';

	/** Hash de la foto que seguía siendo generada al momento de marcarla. */
	public const SERIES_DESCRIPTION_SNAPSHOT_HASH_KEY = '_akibara_rank_math_description_snapshot_sha256';

	/** Procedencia explícita de un título persistido por el generador. */
	public const SERIES_TITLE_OWNERSHIP_KEY = '_akibara_rank_math_title_ownership';

	/** Hash de la foto de título. */
	public const SERIES_TITLE_SNAPSHOT_HASH_KEY = '_akibara_rank_math_title_snapshot_sha256';

	/** Valor versionado: la fila es una foto regenerable, no copy editorial. */
	public const SERIES_META_GENERATED = 'generated_snapshot_v1';

	/** Cierres canónicos de plantillas anteriores del generador. */
	private const SERIES_META_RETIRED_CLOSINGS = array(
		'Revisa precio y disponibilidad en Akibara.',
		' en español | Akibara Chile',
		' tomos | Akibara',
		' en español | Comprar en Akibara Chile',
	);

	/** El campo está vacío: el filtro del front lo compone en cada request. */
	private const SERIES_META_AUTO_LIVE = 'auto-vivo';

	/** Hay valor guardado y su hash lo acredita como foto generada. */
	private const SERIES_META_AUTO_STORED = 'auto-guardado';

	/** Hay valor guardado sin marca válida: manual/congelado. */
	private const SERIES_META_MANUAL = 'manual';

	/** Acción de admin-post.php que devuelve una serie a meta automática. */
	private const SERIES_META_RESTORE_ACTION = 'akibara_serie_seo_auto';

	/** Capability real de la pantalla de términos de producto. */
	private const SERIES_TERMS_CAP = 'manage_product_terms';

	/** Vocabulario cerrado de medios publicables. */
	private const SERIES_MEDIA = array( 'manga', 'cómic', 'manhwa' );

	/** Transient del medio resuelto de una serie. */
	private const SERIES_MEDIUM_TRANSIENT_PREFIX = 'akb_serie_medio_';

	/** Vida del medio cacheado (TTL de 36 h). */
	private const SERIES_MEDIUM_TTL = 36 * HOUR_IN_SECONDS;

	private ?bool $rank_math_active;

	private ?bool $rank_math_breadcrumbs_enabled;

	/** Reader/replacer inyectables para probar la integración sin cargar Rank Math. */
	private ?\Closure $rank_math_settings_reader;

	private ?\Closure $rank_math_variable_replacer;

	private SeriesSitemapLastmod $series_sitemap_lastmod;

	private SeriesIndex $series_index;

	private SeriesDirectorySearchPolicy $series_directory_search_policy;

	/** Submódulo de saneamiento y alineación de Schema JSON-LD. */
	private SeoSchemaEnhancer $schema_enhancer;

	/** Submódulo de composición de metadatos de serie y medio. */
	private SeriesMetaComposer $series_meta_composer;

	/** Submódulo de composición de metadatos de producto. */
	private ProductMetaComposer $product_meta_composer;

	/** Submódulo de interfaz administrativa de procedencia SEO de series. */
	private SeriesMetaProvenanceAdmin $provenance_admin;

	public function __construct(
		?bool $rank_math_active = null,
		?callable $rank_math_settings_reader = null,
		?callable $rank_math_variable_replacer = null,
		?bool $rank_math_breadcrumbs_enabled = null,
		?SeriesSitemapLastmod $series_sitemap_lastmod = null,
		?SeriesIndex $series_index = null,
		?SeriesDirectorySearchPolicy $series_directory_search_policy = null,
		?SeriesCatalogEligibility $series_catalog_eligibility = null
	) {
		$this->rank_math_active              = $rank_math_active;
		$this->rank_math_breadcrumbs_enabled = $rank_math_breadcrumbs_enabled;
		$this->rank_math_settings_reader     = null === $rank_math_settings_reader
			? null
			: \Closure::fromCallable( $rank_math_settings_reader );
		$this->rank_math_variable_replacer   = null === $rank_math_variable_replacer
			? null
			: \Closure::fromCallable( $rank_math_variable_replacer );
		$series_catalog_eligibility          = $series_catalog_eligibility ?? new SeriesCatalogEligibility();
		$this->series_sitemap_lastmod        = $series_sitemap_lastmod ?? new SeriesSitemapLastmod(
			self::SERIES_RENDERER_VERSION,
			null,
			null,
			null,
			$series_catalog_eligibility
		);
		$this->series_index                  = $series_index ?? new SeriesIndex( $series_catalog_eligibility );

		$this->series_directory_search_policy = $series_directory_search_policy ?? new SeriesDirectorySearchPolicy();

		$this->series_meta_composer  = new SeriesMetaComposer();
		$this->product_meta_composer = new ProductMetaComposer();
		$this->schema_enhancer       = new SeoSchemaEnhancer( $this->series_index );
		$this->provenance_admin      = new SeriesMetaProvenanceAdmin( $this->series_meta_composer );
	}

	public function register(): void {
		remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
		add_action( 'woocommerce_archive_description', array( $this, 'handle_native_taxonomy_description' ), 10 );
		remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );
		add_action( 'woocommerce_after_main_content', 'woocommerce_product_archive_description', 5 );
		add_action( 'woocommerce_after_main_content', array( $this, 'handle_render_manga_archive_description_rest' ), 5 );
		add_action( 'woocommerce_before_shop_loop', array( $this, 'handle_render_taxonomy_intro' ), 3 );
		add_action( 'save_post_product', array( $this, 'invalidate_category_updated_transient_on_save' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'invalidate_category_updated_transient_on_delete' ), 10, 1 );
		add_action( 'set_object_terms', array( $this, 'invalidate_category_updated_transient_on_terms_set' ), 10, 6 );
		add_action( 'deleted_term_relationships', array( $this, 'invalidate_category_updated_transient_on_terms_deleted' ), 10, 3 );
		$this->series_directory_search_policy->register();

		if ( $this->is_rank_math_active() ) {
			$this->series_sitemap_lastmod->register();

			add_filter( 'rank_math/frontend/title', array( $this, 'add_pagination_to_title' ), 99 );
			add_filter( 'rank_math/frontend/description', array( $this, 'prefer_product_description_template' ), 20 );
			add_filter( 'rank_math/frontend/description', array( $this, 'prefer_series_term_description' ), 20 );
			add_filter( 'rank_math/frontend/description', array( $this, 'prefer_author_term_description' ), 20 );
			add_filter( 'rank_math/frontend/title', array( $this, 'prefer_series_term_title' ), 20 );
			add_filter( 'rank_math/frontend/title', array( $this, 'prefer_product_title' ), 20 );
			add_filter( 'rank_math/frontend/description', array( $this, 'add_pagination_to_description' ), 99 );

			add_filter( 'rank_math/json_ld', array( $this, 'align_series_breadcrumb_schema' ), SchemaGraph::PAGE_ATTACHMENTS, 2 );
			add_filter( 'rank_math/json_ld', array( $this, 'align_product_breadcrumb_schema' ), SchemaGraph::PAGE_ATTACHMENTS, 2 );
			add_filter( 'rank_math/json_ld', array( $this, 'add_series_hub_schema' ), SchemaGraph::PAGE_ATTACHMENTS, 2 );
			add_filter( 'rank_math/json_ld', array( $this, 'add_shipping_page_schema' ), SchemaGraph::PAGE_ATTACHMENTS, 2 );
			add_filter( 'rank_math/json_ld', array( $this, 'cleanup_global_schema' ), SchemaGraph::GLOBAL_CLEANUP, 2 );

			if ( $this->are_rank_math_breadcrumbs_enabled() ) {
				add_filter( 'woocommerce_structured_data_breadcrumblist', '__return_empty_array', 99 );
			}

			add_filter( 'rank_math/sitemap/posts_to_exclude', array( $this, 'exclude_shop_page_from_rank_math_sitemap' ) );

			add_action( self::SERIES_TAXONOMY . '_edit_form_fields', array( $this, 'render_series_meta_provenance' ), 20, 2 );
			add_filter( 'manage_edit-' . self::SERIES_TAXONOMY . '_columns', array( $this, 'add_series_meta_provenance_column' ) );
			add_filter( 'manage_' . self::SERIES_TAXONOMY . '_custom_column', array( $this, 'render_series_meta_provenance_column' ), 10, 3 );
			add_action( 'admin_post_' . self::SERIES_META_RESTORE_ACTION, array( $this, 'handle_restore_generated_series_meta' ) );
			add_action( 'admin_notices', array( $this, 'render_series_meta_restore_notice' ) );
		}

		add_filter( 'redirect_guess_404_permalink', '__return_false' );
		add_action( 'wp_head', array( $this, 'archive_canonical_fallback' ), 1 );
		add_action( 'set_object_terms', array( $this, 'forget_series_medium' ), 10, 4 );
	}

	public function handle_native_taxonomy_description(): void {
		if ( is_tax( array( 'product_brand', 'pa_serie', 'pa_genero', 'pa_autor' ) ) ) {
			return;
		}
		if ( $this->is_manga_archive() ) {
			if ( ! $this->is_clean_manga_archive() ) {
				return;
			}
			$parts = $this->manga_archive_description_parts();
			if ( '' === $parts['lead'] ) {
				return;
			}
			?>
			<div class="term-description akb-archive-desc__lead akb-archive-desc__lead--manga"><?php echo $parts['lead']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php
			$this->render_category_updated_line();
			return;
		}

		woocommerce_taxonomy_archive_description();
		if ( is_tax( 'product_cat' ) && $this->is_clean_product_cat_archive() ) {
			$this->render_category_updated_line();
		}
	}

	public function is_clean_product_cat_archive(): bool {
		if ( is_admin()
			|| CatalogQueryGrammar::has_filter_parameter( (array) $_GET )
			|| (int) get_query_var( 'paged' ) > 1 ) {
			return false;
		}

		return is_tax( 'product_cat' );
	}

	public static function category_last_updated_date( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return '';
		}

		$transient_key = 'akb_cat_updated_' . $term_id;
		$cached        = get_transient( $transient_key );
		if ( is_string( $cached ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $cached ) ) {
			return $cached;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return '';
		}

		$date = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(p.post_modified) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tt.term_id = %d",
				$term_id
			)
		);

		if ( ! is_string( $date ) || '' === $date ) {
			return '';
		}

		$iso = substr( (string) $date, 0, 10 );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $iso ) ) {
			return '';
		}

		set_transient( $transient_key, $iso, 6 * HOUR_IN_SECONDS );
		return $iso;
	}

	public function invalidate_category_updated_transient_on_save( int $post_id, $post ): void {
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || 'product' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::invalidate_product_category_transients( $post_id );
	}

	public function invalidate_category_updated_transient_on_delete( int $post_id ): void {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		self::invalidate_product_category_transients( $post_id );
	}

	public function invalidate_category_updated_transient_on_terms_set( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		unset( $terms, $append );
		if ( 'product_cat' !== $taxonomy || 'product' !== get_post_type( $object_id ) ) {
			return;
		}
		self::invalidate_category_term_taxonomy_ids( array_merge( $old_tt_ids, $tt_ids ) );
	}

	public function invalidate_category_updated_transient_on_terms_deleted( int $object_id, array $tt_ids, string $taxonomy ): void {
		if ( 'product_cat' !== $taxonomy || 'product' !== get_post_type( $object_id ) ) {
			return;
		}
		self::invalidate_category_term_taxonomy_ids( $tt_ids );
	}

	private static function invalidate_category_term_taxonomy_ids( array $tt_ids ): void {
		foreach ( array_unique( array_map( 'intval', $tt_ids ) ) as $tt_id ) {
			if ( $tt_id < 1 ) {
				continue;
			}
			$term = get_term_by( 'term_taxonomy_id', $tt_id, 'product_cat' );
			if ( is_object( $term ) && isset( $term->term_id, $term->taxonomy ) && 'product_cat' === $term->taxonomy ) {
				delete_transient( 'akb_cat_updated_' . (int) $term->term_id );
			}
		}
	}

	public static function invalidate_product_category_transients( int $post_id ): void {
		$term_ids = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_array( $term_ids ) ) {
			foreach ( $term_ids as $term_id ) {
				delete_transient( 'akb_cat_updated_' . (int) $term_id );
			}
		}
	}

	public function render_category_updated_line(): void {
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term || 'product_cat' !== (string) $term->taxonomy ) {
			return;
		}

		$iso = self::category_last_updated_date( (int) $term->term_id );
		if ( '' === $iso ) {
			return;
		}

		$parts = explode( '-', $iso );
		if ( count( $parts ) !== 3 ) {
			return;
		}

		$month_num  = (int) $parts[1];
		$month_name = PedidoPolicy::mes( $month_num );
		if ( '' === $month_name ) {
			return;
		}

		$human = sprintf( '%d de %s de %d', (int) $parts[2], $month_name, (int) $parts[0] );
		?>
		<p class="akb-route-updated">
			Catálogo actualizado el
			<time datetime="<?php echo esc_attr( $iso ); ?>"><?php echo esc_html( $human ); ?></time>
		</p>
		<?php
	}

	public function is_clean_manga_archive(): bool {
		if ( is_admin()
			|| CatalogQueryGrammar::has_filter_parameter( (array) $_GET )
			|| (int) get_query_var( 'paged' ) > 1 ) {
			return false;
		}

		return $this->is_manga_archive();
	}

	public function is_manga_archive(): bool {
		if ( ! is_tax( 'product_cat' ) ) {
			return false;
		}

		$term = get_queried_object();
		return is_object( $term )
			&& isset( $term->term_id, $term->taxonomy, $term->slug, $term->name )
			&& 'product_cat' === (string) $term->taxonomy
			&& 'manga' === (string) $term->slug;
	}

	public function handle_render_manga_archive_description_rest(): void {
		if ( ! $this->is_clean_manga_archive() ) {
			return;
		}

		$parts = $this->manga_archive_description_parts();
		if ( '' === $parts['rest'] ) {
			return;
		}
		?>
		<section class="akb-archive-desc akb-archive-desc--manga" aria-label="<?php echo esc_attr( 'Más información sobre ' . $parts['name'] ); ?>">
			<?php echo $parts['rest']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php
	}

	public static function split_manga_archive_description( string $description ): array {
		$allowed = array(
			'p'      => array(),
			'a'      => array(
				'href' => true,
				'rel'  => true,
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
		$safe    = trim( (string) wp_kses( wpautop( $description ), $allowed ) );
		$visible = trim( (string) wp_strip_all_tags( $safe ) );
		if ( '' === $visible ) {
			return array(
				'lead' => '',
				'rest' => '',
			);
		}

		if ( 1 === preg_match( '#^\s*(<p>.*?</p>)(.*)$#is', $safe, $matches ) ) {
			return array(
				'lead' => trim( (string) $matches[1] ),
				'rest' => trim( (string) $matches[2] ),
			);
		}

		return array(
			'lead' => $safe,
			'rest' => '',
		);
	}

	private function manga_archive_description_parts(): array {
		$empty = array(
			'lead' => '',
			'rest' => '',
			'name' => 'Manga',
		);
		$term  = get_queried_object();
		if ( ! is_object( $term ) || ! isset( $term->term_id, $term->name ) ) {
			return $empty;
		}

		$parts = self::split_manga_archive_description( (string) term_description( (int) $term->term_id ) );
		return array(
			'lead' => $parts['lead'],
			'rest' => $parts['rest'],
			'name' => (string) $term->name,
		);
	}

	public function is_clean_taxonomy_intro_archive(): bool {
		if ( is_admin()
			|| ! is_tax( array( 'pa_genero', 'pa_autor' ) )
			|| CatalogQueryGrammar::has_filter_parameter( (array) $_GET )
			|| (int) get_query_var( 'paged' ) > 1 ) {
			return false;
		}

		$term = get_queried_object();
		return is_object( $term )
			&& isset( $term->term_id, $term->taxonomy, $term->name )
			&& in_array( (string) $term->taxonomy, array( 'pa_genero', 'pa_autor' ), true );
	}

	public function prefer_author_term_description( string $description ): string {
		if ( ! function_exists( 'is_tax' ) || is_admin() || ! is_tax( AuthorIntro::TAXONOMY ) || (int) get_query_var( 'paged' ) > 1 ) {
			return $description;
		}

		$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( ! is_object( $term )
			|| ! isset( $term->term_id, $term->taxonomy, $term->name )
			|| AuthorIntro::TAXONOMY !== (string) $term->taxonomy ) {
			return $description;
		}

		if ( '' !== trim( (string) get_term_meta( (int) $term->term_id, 'rank_math_description', true ) )
			|| '' !== trim( (string) ( $term->description ?? '' ) ) ) {
			return $description;
		}

		$generated = ( new AuthorIntro() )->meta_description( $term );

		return '' !== $generated ? $generated : $description;
	}

	public function handle_render_taxonomy_intro(): void {
		if ( ! $this->is_clean_taxonomy_intro_archive() ) {
			return;
		}

		$term        = get_queried_object();
		$description = term_description( $term->term_id );
		$allowed     = array(
			'p'      => array(),
			'a'      => array(
				'href' => true,
				'rel'  => true,
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
		$description = trim( (string) wp_kses( (string) $description, $allowed ) );
		if ( '' === trim( wp_strip_all_tags( $description ) ) && AuthorIntro::TAXONOMY === (string) $term->taxonomy ) {
			$description = trim( (string) wp_kses( ( new AuthorIntro() )->for_term( $term ), $allowed ) );
		}
		if ( '' === trim( wp_strip_all_tags( $description ) ) ) {
			return;
		}

		$content_id = 'akb-archive-intro-' . $term->taxonomy . '-' . $term->term_id;
		?>
		<section class="akb-archive-intro akb-archive-intro--taxonomy" aria-label="<?php echo esc_attr( 'Sobre ' . $term->name ); ?>">
			<div
				class="akb-archive-intro__content is-collapsed"
				id="<?php echo esc_attr( $content_id ); ?>"
				data-akb-archive-intro
			><?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<button
				type="button"
				class="akb-archive-intro__toggle"
				data-akb-archive-intro-toggle
				data-label-expand="Leer más"
				data-label-collapse="Leer menos"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $content_id ); ?>"
				hidden
			>Leer más</button>
			<noscript><style>.akb-archive-intro__content.is-collapsed{display:block;-webkit-line-clamp:unset;line-clamp:unset;overflow:visible}</style></noscript>
		</section>
		<?php
	}

	public static function is_sitemap_path( string $path ): bool {
		return 1 === preg_match( '#(?:^|/)[^/]*sitemap[^/]*\.(?:xml|xsl)$#i', $path );
	}

	public function archive_canonical_fallback(): void {
		if ( $this->is_rank_math_active() ) {
			return;
		}
		if ( ! function_exists( 'is_shop' ) || ! ( is_shop() || is_product_taxonomy() ) ) {
			return;
		}
		if ( CatalogQueryGrammar::has_filter_parameter( (array) $_GET ) ) {
			return;
		}
		global $wp;
		$path = isset( $wp->request ) ? (string) $wp->request : '';
		if ( '' === $path ) {
			return;
		}
		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( home_url( user_trailingslashit( $path ) ) ) );
	}

	public function exclude_shop_page_from_rank_math_sitemap( array $post_ids ): array {
		$shop_page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		if ( $shop_page_id > 0 && ! in_array( $shop_page_id, $post_ids, true ) ) {
			$post_ids[] = $shop_page_id;
		}

		return $post_ids;
	}

	public function align_series_breadcrumb_schema( array $data, $jsonld = null ): array {
		unset( $jsonld );
		return $this->schema_enhancer->align_series_breadcrumb_schema( $data );
	}

	public function align_product_breadcrumb_schema( array $data, $jsonld = null ): array {
		unset( $jsonld );
		return $this->schema_enhancer->align_product_breadcrumb_schema( $data );
	}

	public function add_shipping_page_schema( array $data, $jsonld = null ): array {
		unset( $jsonld );
		return $this->schema_enhancer->add_shipping_page_schema( $data );
	}

	public function add_series_hub_schema( array $data, $jsonld = null ): array {
		unset( $jsonld );
		return $this->schema_enhancer->add_series_hub_schema( $data );
	}

	public function cleanup_global_schema( $data, $jsonld = null ) {
		unset( $jsonld );
		return $this->schema_enhancer->cleanup_global_schema( $data );
	}

	public function add_pagination_to_title( string $title ): string {
		$label = $this->current_pagination_label();
		if ( '' === $label || 1 === preg_match( '/\bPágina\s+\d+\b/iu', $title ) ) {
			return $title;
		}

		$with_label = preg_replace(
			'/(\s+(?:\||-|–)\s+Akibara)$/u',
			' – ' . $label . '$1',
			trim( $title ),
			1,
			$replacements
		);

		if ( is_string( $with_label ) && 1 === $replacements ) {
			return $with_label;
		}

		return rtrim( $title ) . ' – ' . $label;
	}

	public function add_pagination_to_description( string $description ): string {
		$label = $this->current_pagination_label();
		if ( '' === $label || 1 === preg_match( '/\bPágina\s+\d+\b/iu', $description ) ) {
			return $description;
		}

		$base = preg_replace( '/(?:\s|\x00|\x0B|\.|…)+$/u', '', $description );
		$base = is_string( $base ) ? $base : rtrim( $description, " \t\n\r\0\x0B." );
		$result = $base . '. ' . $label . '.';

		if (
			Text::length( $result ) > 160
			&& function_exists( 'is_tax' )
			&& is_tax( self::SERIES_TAXONOMY )
		) {
			$compact = self::compact_paginated_series_description( $base, $label );
			if ( '' !== $compact ) {
				return $compact;
			}
		}

		return $result;
	}

	public function prefer_product_description_template( string $description ): string {
		if (
			! $this->is_rank_math_active()
			|| ! function_exists( 'is_singular' )
			|| ! is_singular( 'product' )
			|| ! $this->can_use_rank_math_api()
		) {
			return $description;
		}

		$post_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		if ( $post_id < 1 ) {
			return $description;
		}

		$custom_description = get_post_meta( $post_id, 'rank_math_description', true );
		if ( '' !== trim( (string) $custom_description ) ) {
			return $description;
		}

		$post = get_post( $post_id );
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || 'product' !== $post->post_type ) {
			return $description;
		}

		$meta = $this->product_meta_composer->generated_product_meta( $post_id );
		if ( null !== $meta && '' !== $meta['description'] ) {
			return $meta['description'];
		}

		$template = $this->rank_math_setting( 'titles.pt_product_description' );
		if ( ! is_string( $template ) || '' === trim( $template ) ) {
			return $description;
		}

		$expanded = $this->rank_math_replace_vars( $template, $post );
		if ( ! is_string( $expanded ) ) {
			return $description;
		}

		$expanded = self::sanitize_product_meta_description( $expanded );

		return '' !== $expanded ? $expanded : $description;
	}

	public static function sanitize_product_meta_description( string $description ): string {
		return ProductMetaComposer::sanitize_product_meta_description( $description );
	}

	public function prefer_series_term_description( string $description ): string {
		if ( ! function_exists( 'is_tax' ) || is_admin() || ! is_tax( self::SERIES_TAXONOMY ) ) {
			return $description;
		}

		$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( ! is_object( $term )
			|| ! isset( $term->term_id, $term->taxonomy, $term->name )
			|| self::SERIES_TAXONOMY !== (string) $term->taxonomy ) {
			return $description;
		}

		$term_id            = (int) $term->term_id;
		$custom_description = get_term_meta( $term_id, 'rank_math_description', true );
		if ( '' !== trim( (string) $custom_description ) && ! $this->is_generated_series_meta_field( $term_id, 'description' ) ) {
			return $description;
		}

		$meta = $this->series_meta_composer->generated_series_meta( $term );
		if ( null !== $meta && '' !== $meta['description'] ) {
			return $meta['description'];
		}
		if ( '' !== trim( (string) $custom_description ) ) {
			return $description;
		}

		$publication = PublicationStatus::scoped_for_term( $term_id );
		$generated   = self::series_meta_description(
			(string) $term->name,
			(string) ( $publication['obra']['estado'] ?? '' )
		);

		return '' !== $generated ? $generated : $description;
	}

	public function prefer_series_term_title( string $title ): string {
		if ( ! function_exists( 'is_tax' ) || is_admin() || ! is_tax( self::SERIES_TAXONOMY ) ) {
			return $title;
		}

		$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( ! is_object( $term )
			|| ! isset( $term->term_id, $term->taxonomy, $term->name )
			|| self::SERIES_TAXONOMY !== (string) $term->taxonomy ) {
			return $title;
		}

		$term_id      = (int) $term->term_id;
		$custom_title = get_term_meta( $term_id, 'rank_math_title', true );
		if ( '' !== trim( (string) $custom_title ) && ! $this->is_generated_series_meta_field( $term_id, 'title' ) ) {
			return $title;
		}

		$meta = $this->series_meta_composer->generated_series_meta( $term );

		return ( null !== $meta && '' !== $meta['title'] ) ? $meta['title'] : $title;
	}

	public function series_manifest_description( object $term ): string {
		$term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;
		if ( $term_id < 1 ) {
			return '';
		}

		$is_generated = $this->is_generated_series_meta_field( $term_id, 'description' );
		return $this->series_meta_composer->series_manifest_description( $term, $is_generated );
	}

	/**
	 * Comprueba si una fila sigue siendo la foto generada que fue registrada.
	 *
	 * Conservado como método privado para soporte total de pruebas por reflexión.
	 *
	 * @param int    $term_id ID del término.
	 * @param string $field   'title' | 'description'.
	 * @return bool
	 */
	private function is_generated_series_meta_field( int $term_id, string $field ): bool {
		return $this->provenance_admin->is_generated_series_meta_field( $term_id, $field );
	}

	public function render_series_meta_provenance( $term, $taxonomy = '' ): void {
		$this->provenance_admin->render_series_meta_provenance( $term, (string) $taxonomy );
	}

	public function handle_restore_generated_series_meta(): void {
		$this->provenance_admin->handle_restore_generated_series_meta();
	}

	/**
	 * Escribe la foto del catálogo como meta de Rank Math y la marca como automática.
	 *
	 * Conservado como método privado para soporte total de pruebas por reflexión.
	 *
	 * @param object $term Término de serie.
	 * @return bool
	 */
	private function restore_generated_series_meta( object $term ): bool {
		return $this->provenance_admin->restore_generated_series_meta( $term );
	}

	public function render_series_meta_restore_notice(): void {
		$this->provenance_admin->render_series_meta_restore_notice();
	}

	public function add_series_meta_provenance_column( $columns ) {
		return $this->provenance_admin->add_series_meta_provenance_column( $columns );
	}

	public function render_series_meta_provenance_column( $content, $column = '', $term_id = 0 ) {
		return $this->provenance_admin->render_series_meta_provenance_column( $content, $column, (int) $term_id );
	}

	public static function series_meta_description( string $series_name, string $work_state = '' ): string {
		return SeriesMetaComposer::series_meta_description( $series_name, $work_state );
	}

	public function forget_series_medium( $object_id, $terms = null, $tt_ids = null, $taxonomy = '' ): void {
		unset( $terms, $tt_ids );
		$this->series_meta_composer->forget_series_medium( $object_id, (string) $taxonomy );
	}

	public static function compose_series_meta(
		string $series_name,
		string $work_state,
		int $work_total,
		int $catalog_count,
		string $author,
		string $brand,
		string $medium,
		string $price_from = '',
		bool $fully_buyable = false,
		string $edition_state = '',
		int $edition_total = 0
	): array {
		return SeriesMetaComposer::compose_series_meta(
			$series_name,
			$work_state,
			$work_total,
			$catalog_count,
			$author,
			$brand,
			$medium,
			$price_from,
			$fully_buyable,
			$edition_state,
			$edition_total
		);
	}

	private static function compact_paginated_series_description( string $base, string $label ): string {
		$locale_marker = ' en Akibara Chile.';
		$first_end     = strpos( $base, $locale_marker );
		$last_start    = strrpos( $base, 'Revisa ' );
		if ( false !== $first_end && false !== $last_start ) {
			$first = trim( substr( $base, 0, $first_end + strlen( $locale_marker ) ) );
			$last  = trim( substr( $base, $last_start ) );
		} else {
			$sentences = preg_split( '/(?<=[.!?])\s+/u', trim( $base ) );
			if ( ! is_array( $sentences ) || count( $sentences ) < 2 ) {
				return '';
			}

			$first = trim( (string) reset( $sentences ) );
			$last  = trim( (string) end( $sentences ) );
		}

		if ( '' === $first || '' === $last ) {
			return '';
		}

		$first     = 1 === preg_match( '/[.!?]$/u', $first ) ? $first : $first . '.';
		$last      = 1 === preg_match( '/[.!?]$/u', $last ) ? $last : $last . '.';
		$candidate = $first . ' ' . $last . ' ' . $label . '.';

		return Text::length( $candidate ) <= 160 ? $candidate : '';
	}

	private function current_pagination_label(): string {
		if ( ! function_exists( 'get_query_var' ) ) {
			return '';
		}

		$paged = (int) get_query_var( 'paged' );
		if ( $paged < 2 ) {
			return '';
		}

		global $wp_query;
		$total = is_object( $wp_query ) && isset( $wp_query->max_num_pages )
			? (int) $wp_query->max_num_pages
			: 0;

		return $total >= $paged ? sprintf( 'Página %d de %d', $paged, $total ) : sprintf( 'Página %d', $paged );
	}

	public function prefer_product_title( string $title ): string {
		return $this->product_meta_composer->prefer_product_title( $title );
	}

	public static function compose_product_meta(
		string $product_title,
		string $serie_name,
		string $author,
		string $brand,
		string $medium,
		?int $volume,
		string $encargo = ''
	): array {
		return ProductMetaComposer::compose_product_meta(
			$product_title,
			$serie_name,
			$author,
			$brand,
			$medium,
			$volume,
			$encargo
		);
	}

	private function is_rank_math_active(): bool {
		return $this->rank_math_active ?? defined( 'RANK_MATH_VERSION' );
	}

	private function are_rank_math_breadcrumbs_enabled(): bool {
		if ( null !== $this->rank_math_breadcrumbs_enabled ) {
			return $this->rank_math_breadcrumbs_enabled;
		}

		return class_exists( '\RankMath\Helper' )
			&& method_exists( '\RankMath\Helper', 'is_breadcrumbs_enabled' )
			&& (bool) \RankMath\Helper::is_breadcrumbs_enabled();
	}

	private function can_use_rank_math_api(): bool {
		return (
			null !== $this->rank_math_settings_reader
			&& null !== $this->rank_math_variable_replacer
		) || class_exists( '\RankMath\Helper' );
	}

	private function rank_math_setting( string $key ) {
		if ( null !== $this->rank_math_settings_reader ) {
			return ( $this->rank_math_settings_reader )( $key );
		}

		return \RankMath\Helper::get_settings( $key );
	}

	private function rank_math_replace_vars( string $template, object $post ) {
		if ( null !== $this->rank_math_variable_replacer ) {
			return ( $this->rank_math_variable_replacer )( $template, $post );
		}

		return \RankMath\Helper::replace_vars( $template, $post );
	}
}
