<?php
/**
 * SeoEnhancements — fixes de SEO pequeños a nivel plugin.
 *
 * Rank Math es la fuente única del Product schema. Esta clase conserva los
 * ajustes editoriales/SEO que no compiten con esa propiedad.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeoEnhancements;

use Akibara\Business\Utilities\CatalogQueryGrammar;

use Akibara\Business\Modules\SeoIndexing\SeriesDirectorySearchPolicy;
use Akibara\Business\Modules\SeoIndexing\SeriesSitemapLastmod;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\SeriesDirectory\SeriesIndex;
use Akibara\Business\Modules\SeriesDirectory\Shortcode as SeriesDirectoryShortcode;
use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Modules\SeriesFacts\SeriesFacts;
use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Akibara\Business\Modules\TaxonomyDiscount\Settings as DiscountSettings;
use Akibara\Business\Services\SeriesHubUrl;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Utilities\SchemaGraph;
use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class SeoEnhancements {

	/**
	 * Versión explícita de las superficies públicas de una landing de serie.
	 *
	 * Debe subir cuando cambie la plantilla meta, la semántica del breadcrumb o
	 * cualquier renderer global de `/serie/*`. `SeriesSitemapLastmod` registra la
	 * primera activación de cada versión una sola vez, no en cada request.
	 */
	public const SERIES_RENDERER_VERSION = '2026-08-22.1';

	/**
	 * Taxonomía de SERIE — verificada, no inferida.
	 *
	 * Las landings viven en `/serie/<slug>/` sobre `pa_serie` (130 términos).
	 * El mismo dato lo confirman `SeriesFacts::TAXONOMY` y
	 * `PdpEnhancer::GRID_TAXONOMIES`.
	 */
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

	/** Hash de la foto de título; impide ignorar una edición manual posterior. */
	public const SERIES_TITLE_SNAPSHOT_HASH_KEY = '_akibara_rank_math_title_snapshot_sha256';

	/** Valor versionado: la fila es una foto regenerable, no copy editorial. */
	public const SERIES_META_GENERATED = 'generated_snapshot_v1';

	/**
	 * Cierres canónicos de plantillas anteriores del generador de metadatos de serie.
	 *
	 * Permite clasificar valores preexistentes en `wp_termmeta` como instantáneas
	 * generadas automáticamente en lugar de modificaciones editoriales manuales,
	 * permitiendo que el generador dinámico actualice los conteos de tomos en tiempo real
	 * sin necesidad de migraciones destructivas en la base de datos.
	 *
	 * Al introducir nuevas plantillas de generación, los cierres anteriores deben
	 * incorporarse a este arreglo para preservar la actualización dinámica.
	 *
	 * @var array<int,string>
	 */
	private const SERIES_META_RETIRED_CLOSINGS = array(
		'Revisa precio y disponibilidad en Akibara.',
		// Patrones de sufijos generados automáticamente para series sin firma criptográfica explícita:
		// Permite sincronizar dinámicamente el conteo de tomos con el catálogo activo.
		' en español | Akibara Chile',
		' tomos | Akibara',
		' en español | Comprar en Akibara Chile',
	);

	/** El campo está vacío: el filtro del front lo compone en cada request. */
	private const SERIES_META_AUTO_LIVE = 'auto-vivo';

	/** Hay valor guardado y su hash lo acredita como foto generada. */
	private const SERIES_META_AUTO_STORED = 'auto-guardado';

	/** Hay valor guardado sin marca válida: editorial, y por tanto congelado. */
	private const SERIES_META_MANUAL = 'manual';

	/** Acción de `admin-post.php` que devuelve una serie a meta automática. */
	private const SERIES_META_RESTORE_ACTION = 'akibara_serie_seo_auto';

	/** Capability real de la pantalla de términos de producto. */
	private const SERIES_TERMS_CAP = 'manage_product_terms';

	/**
	 * Vocabulario CERRADO de medios publicables.
	 *
	 * Es la lista que produce `medium_from_categories()` y la que valida lo que
	 * vuelve del transient: un valor guardado por otra versión —o corrupto— no
	 * puede convertirse en un sustantivo publicado en un `<title>`.
	 *
	 * @var array<int,string>
	 */
	private const SERIES_MEDIA = array( 'manga', 'cómic', 'manhwa' );

	/**
	 * Transient del medio resuelto de una serie (`manga`, `cómic`, `manhwa`).
	 *
	 * Resolver el medio exige inspeccionar un producto testigo y sus categorías asociadas.
	 * Para evitar consultas repetitivas por cada landing de serie y durante crons de catálogo,
	 * el medio resuelto se almacena en caché transitoria con TTL de 36 h.
	 *
	 * Sólo se cachea el medio resuelto. Las series vacías o sin categorías reconocibles
	 * no se almacenan para reflejar inmediatamente la publicación del primer tomo.
	 */
	private const SERIES_MEDIUM_TRANSIENT_PREFIX = 'akb_serie_medio_';

	/**
	 * Vida del medio cacheado (TTL de 36 h).
	 *
	 * Diseñado para cubrir la cadencia de regeneración de manifiestos y catálogos,
	 * complementado por la invalidación reactiva en `forget_series_medium()`.
	 */
	private const SERIES_MEDIUM_TTL = 36 * HOUR_IN_SECONDS;

	private ?bool $rank_math_active;

	private ?bool $rank_math_breadcrumbs_enabled;

	/**
	 * Miga de WooCommerce de la ficha en curso: `[ crumbs ]`. Ver
	 * {@see align_product_breadcrumb_schema()}.
	 *
	 * @var array{0:mixed}|null
	 */
	private ?array $product_crumbs_memo = null;

	/**
	 * Reader/replacer inyectables para probar la integración sin cargar Rank Math.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $rank_math_settings_reader;

	private ?\Closure $rank_math_variable_replacer;

	private SeriesSitemapLastmod $series_sitemap_lastmod;

	private SeriesIndex $series_index;

	/**
	 * Indexing policy for client-side directory searches.
	 *
	 * @var SeriesDirectorySearchPolicy
	 */
	private SeriesDirectorySearchPolicy $series_directory_search_policy;

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
		$this->rank_math_variable_replacer = null === $rank_math_variable_replacer
			? null
			: \Closure::fromCallable( $rank_math_variable_replacer );
		$series_catalog_eligibility   = $series_catalog_eligibility ?? new SeriesCatalogEligibility();
		$this->series_sitemap_lastmod = $series_sitemap_lastmod ?? new SeriesSitemapLastmod(
			self::SERIES_RENDERER_VERSION,
			null,
			null,
			null,
			$series_catalog_eligibility
		);
		$this->series_index = $series_index ?? new SeriesIndex( $series_catalog_eligibility );

		$this->series_directory_search_policy = $series_directory_search_policy ?? new SeriesDirectorySearchPolicy();
	}

	public function register(): void {
		// La descripción del contenedor /tienda/ queda al pie. Las taxonomías
		// conservan su posición nativa arriba, salvo los archivos con UI propia:
		// serie/editorial ya tienen hero y género/autor usan el intro compacto
		// de este módulo en la URL limpia.
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

			// Rank Math conserva la propiedad completa de las plantillas de
			// productos. Akibara solo añade el marcador de página cuando una
			// plantilla/override editorial no incluyó `%page%`.
			add_filter( 'rank_math/frontend/title', array( $this, 'add_pagination_to_title' ), 99 );
			add_filter( 'rank_math/frontend/description', array( $this, 'prefer_product_description_template' ), 20 );

			// Misma prioridad 20 que el callback de producto y NO un empate
			// accidental: los dos son mutuamente excluyentes por guard
			// (`is_singular('product')` vs `is_tax('pa_serie')`), así que ningún
			// request los ve competir, y ambos deben correr ANTES del marcador de
			// paginación de la 99 —que sufija el copy ya resuelto—.
			add_filter( 'rank_math/frontend/description', array( $this, 'prefer_series_term_description' ), 20 );
			// Y la de autor, con el mismo guard excluyente (`is_tax('pa_autor')`).
			add_filter( 'rank_math/frontend/description', array( $this, 'prefer_author_term_description' ), 20 );

			// TITLE de serie: las queries reales de GSC («the climber manga»,
			// Compone dinámicamente un título con medio y número verificado de tomos
			// cuando la landing no posee un `rank_math_title` redactado manualmente.
			add_filter( 'rank_math/frontend/title', array( $this, 'prefer_series_term_title' ), 20 );

			// TITLE de ficha: mismo contrato, mutuamente excluyente por guard
			// (`is_singular('product')` vs `is_tax('pa_serie')`). Las 171
			// fichas con `rank_math_title` manual conservan su título.
			add_filter( 'rank_math/frontend/title', array( $this, 'prefer_product_title' ), 20 );

			add_filter( 'rank_math/frontend/description', array( $this, 'add_pagination_to_description' ), 99 );
			add_filter( 'rank_math/json_ld', array( $this, 'align_series_breadcrumb_schema' ), SchemaGraph::PAGE_ATTACHMENTS, 2 );
			add_filter( 'rank_math/json_ld', array( $this, 'align_product_breadcrumb_schema' ), SchemaGraph::PAGE_ATTACHMENTS, 2 );
			add_filter( 'rank_math/json_ld', array( $this, 'add_series_hub_schema' ), SchemaGraph::PAGE_ATTACHMENTS, 2 );
			add_filter( 'rank_math/json_ld', array( $this, 'add_shipping_page_schema' ), SchemaGraph::PAGE_ATTACHMENTS, 2 );

			// Higiene del @graph global: el Place hueco que la UI de Rank Math
			// no puede quitar, y la autoría apuntando a un archive 404.
			add_filter( 'rank_math/json_ld', array( $this, 'cleanup_global_schema' ), SchemaGraph::GLOBAL_CLEANUP, 2 );

			// Rank Math solo emite BreadcrumbList cuando breadcrumbs está
			// habilitado. WooCommerce conserva su nodo nativo si la opción está
			// apagada o la API efectiva no está disponible.
			if ( $this->are_rank_math_breadcrumbs_enabled() ) {
				add_filter( 'woocommerce_structured_data_breadcrumblist', '__return_empty_array', 99 );
			}

			// /tienda/ es el contenedor del archive de productos, no una página
			// editorial independiente. El archive sigue en product-sitemap.xml.
			add_filter( 'rank_math/sitemap/posts_to_exclude', array( $this, 'exclude_shop_page_from_rank_math_sitemap' ) );

			// Procedencia visible del meta de serie. Identifica en la interfaz administrativa
			// si el título o descripción fueron generados automáticamente o redactados
			// manualmente, preservando ediciones directas y permitiendo sincronización dinámica.
			add_action( self::SERIES_TAXONOMY . '_edit_form_fields', array( $this, 'render_series_meta_provenance' ), 20, 2 );
			add_filter( 'manage_edit-' . self::SERIES_TAXONOMY . '_columns', array( $this, 'add_series_meta_provenance_column' ) );
			add_filter( 'manage_' . self::SERIES_TAXONOMY . '_custom_column', array( $this, 'render_series_meta_provenance_column' ), 10, 3 );
			add_action( 'admin_post_' . self::SERIES_META_RESTORE_ACTION, array( $this, 'handle_restore_generated_series_meta' ) );
			add_action( 'admin_notices', array( $this, 'render_series_meta_restore_notice' ) );
		}

		// Desactiva la adivinación de permalinks 404 del core para evitar redirecciones 301
		// erróneas por prefijo parcial y prevenir envenenamiento de caché en endpoints principales.
		add_filter( 'redirect_guess_404_permalink', '__return_false' );

		// Canonical autorreferente de respaldo en archivos de producto únicamente cuando
		// Rank Math no está activo, limitándose a URLs limpias no filtradas.
		add_action( 'wp_head', array( $this, 'archive_canonical_fallback' ), 1 );

		// Invalidación del medio cacheado por serie. Va FUERA del guard de Rank
		// Math y fuera de cualquier `is_admin()`: los productos también cambian de
		// categoría por importaciones, REST y WP-CLI, y un medio congelado se
		// publicaría en el `<title>` de la landing.
		add_action( 'set_object_terms', array( $this, 'forget_series_medium' ), 10, 4 );

	}

	/**
	 * Conserva la descripción nativa arriba en taxonomías sin presentación propia.
	 *
	 * @internal
	 */
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
			<div class="term-description akb-archive-desc__lead akb-archive-desc__lead--manga"><?php echo $parts['lead']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- allowlist local con wp_kses. ?></div>
			<?php
			$this->render_category_updated_line();
			return;
		}

		woocommerce_taxonomy_archive_description();
		if ( is_tax( 'product_cat' ) && $this->is_clean_product_cat_archive() ) {
			$this->render_category_updated_line();
		}
	}

	/**
	 * Limita la señal de frescura a la URL canónica limpia de la categoría, página uno.
	 *
	 * @internal
	 */
	public function is_clean_product_cat_archive(): bool {
		if ( is_admin()
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo se leen claves.
			|| CatalogQueryGrammar::has_filter_parameter( (array) $_GET )
			|| (int) get_query_var( 'paged' ) > 1 ) {
			return false;
		}

		return is_tax( 'product_cat' );
	}

	/**
	 * Obtiene la fecha más reciente de modificación de productos para una categoría.
	 *
	 * @param int $term_id ID del término de product_cat.
	 * @return string Fecha en formato YYYY-MM-DD o cadena vacía.
	 */
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

	/**
	 * Invalida el transient de fecha de categoría al guardar un producto.
	 *
	 * @internal
	 * @param int             $post_id ID del post.
	 * @param \WP_Post|object $post    Objeto post.
	 */
	public function invalidate_category_updated_transient_on_save( int $post_id, $post ): void {
		if ( ! is_object( $post ) || ! isset( $post->post_type ) || 'product' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::invalidate_product_category_transients( $post_id );
	}

	/**
	 * Invalida antes de que Core elimine las relaciones del producto.
	 *
	 * @internal
	 * @param int $post_id ID del post.
	 */
	public function invalidate_category_updated_transient_on_delete( int $post_id ): void {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		self::invalidate_product_category_transients( $post_id );
	}

	/**
	 * Invalida categorías anteriores y actuales tras una reasignación.
	 *
	 * @internal Callback de `set_object_terms`.
	 * @param int        $object_id  ID del producto.
	 * @param mixed      $terms      Términos solicitados por Core.
	 * @param array<int> $tt_ids     Nuevos IDs de term_taxonomy.
	 * @param string     $taxonomy   Taxonomía modificada.
	 * @param bool       $append     Si se añaden relaciones.
	 * @param array<int> $old_tt_ids IDs de term_taxonomy anteriores.
	 */
	public function invalidate_category_updated_transient_on_terms_set( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		unset( $terms, $append );
		if ( 'product_cat' !== $taxonomy || 'product' !== get_post_type( $object_id ) ) {
			return;
		}
		self::invalidate_category_term_taxonomy_ids( array_merge( $old_tt_ids, $tt_ids ) );
	}

	/**
	 * Cubre `wp_remove_object_terms()` y relaciones retiradas por Core.
	 *
	 * @internal Callback de `deleted_term_relationships`.
	 * @param int        $object_id ID del producto.
	 * @param array<int> $tt_ids    IDs de term_taxonomy retirados.
	 * @param string     $taxonomy Taxonomía modificada.
	 */
	public function invalidate_category_updated_transient_on_terms_deleted( int $object_id, array $tt_ids, string $taxonomy ): void {
		if ( 'product_cat' !== $taxonomy || 'product' !== get_post_type( $object_id ) ) {
			return;
		}
		self::invalidate_category_term_taxonomy_ids( $tt_ids );
	}

	/**
	 * Resuelve TTIDs a term IDs: Core no garantiza que ambos coincidan.
	 *
	 * @param array<int> $tt_ids IDs de term_taxonomy afectados.
	 */
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

	/**
	 * Borra los transients de frescura de todas las categorías asociadas al producto.
	 *
	 * @param int $post_id ID del producto.
	 */
	public static function invalidate_product_category_transients( int $post_id ): void {
		$term_ids = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_array( $term_ids ) ) {
			foreach ( $term_ids as $term_id ) {
				delete_transient( 'akb_cat_updated_' . (int) $term_id );
			}
		}
	}

	/**
	 * Imprime la línea de frescura del catálogo para la categoría consultada.
	 *
	 * @internal
	 */
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

	/**
	 * Limita el split editorial a la URL canónica limpia de Manga, página uno.
	 *
	 * Los filtros, búsquedas y páginas 2+ de Manga quedan sin descripción para no
	 * volver a empujar la grilla ni duplicar la copy canónica. Las demás categorías
	 * sí conservan el flujo nativo de WooCommerce.
	 *
	 * @internal
	 */
	public function is_clean_manga_archive(): bool {
		if ( is_admin()
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo se miran las CLAVES; no se lee ningún valor ni se muta nada.
			|| CatalogQueryGrammar::has_filter_parameter( (array) $_GET )
			|| (int) get_query_var( 'paged' ) > 1 ) {
			return false;
		}

		return $this->is_manga_archive();
	}

	/**
	 * Reconoce Manga incluso en variantes no canónicas que deben quedar sin copy.
	 *
	 * @internal
	 */
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

	/**
	 * Imprime al pie el contenido posterior al primer párrafo de Manga.
	 *
	 * @internal
	 */
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
			<?php echo $parts['rest']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- allowlist local con wp_kses. ?>
		</section>
		<?php
	}

	/**
	 * Convierte la descripción administrada en WooCommerce en dos fragmentos.
	 *
	 * `term_description()` normalmente ya aplica `wpautop`; repetirlo aquí es
	 * intencional e idempotente para cubrir también texto guardado desde la UI
	 * como párrafos separados por una línea en blanco.
	 *
	 * @param string $description Descripción filtrada del término.
	 * @return array{lead:string,rest:string}
	 * @internal Función determinista expuesta para pruebas de contrato.
	 */
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

		// Contenido antiguo sin párrafos reconocibles: no inventar un corte que
		// pueda separar listas o enlaces; se conserva completo como lead único.
		return array(
			'lead' => $safe,
			'rest' => '',
		);
	}

	/**
	 * Obtiene el término y su descripción desde la única fuente editable: Woo UI.
	 *
	 * @return array{lead:string,rest:string,name:string}
	 */
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

	/**
	 * Indica si corresponde mostrar el intro editorial de género/autor.
	 *
	 * @internal
	 */
	public function is_clean_taxonomy_intro_archive(): bool {
		if ( is_admin()
			|| ! is_tax( array( 'pa_genero', 'pa_autor' ) )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo se miran las CLAVES; no se lee ningún valor ni se muta nada.
			|| CatalogQueryGrammar::has_filter_parameter( (array) $_GET )
			|| (int) get_query_var( 'paged' ) > 1 ) {
			return false;
		}

		$term = get_queried_object();
		return is_object( $term )
			&& isset( $term->term_id, $term->taxonomy, $term->name )
			&& in_array( (string) $term->taxonomy, array( 'pa_genero', 'pa_autor' ), true );
	}

	/**
	 * Meta description de una landing de autor sin descripción propia ni
	 * meta escrita a mano en Rank Math: primera frase del texto automático generado.
	 * Misma prioridad 20 que las de producto y serie; los tres guards son excluyentes.
	 *
	 * @param string $description La que Rank Math iba a emitir.
	 * @return string
	 */
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

		// Manda lo que el dueño escribió PARA ESE AUTOR: la meta del término en
		// Prioridad para contenido explícito del autor: si existe descripción manual en
		// Rank Math o en el término de taxonomía, se preserva. De lo contrario, se genera
		// dinámicamente a partir de las obras y editoriales del autor.
		if ( '' !== trim( (string) get_term_meta( (int) $term->term_id, 'rank_math_description', true ) )
			|| '' !== trim( (string) ( $term->description ?? '' ) ) ) {
			return $description;
		}

		$generated = ( new AuthorIntro() )->meta_description( $term );

		return '' !== $generated ? $generated : $description;
	}

	/**
	 * Renderiza la descripción nativa una vez, antes de controles y grilla.
	 *
	 * No permite headings para proteger el H1 único del archive. El texto
	 * completo permanece en el DOM y se pliega a tres líneas solo en móvil.
	 *
	 * @internal
	 */
	public function handle_render_taxonomy_intro(): void {
		if ( ! $this->is_clean_taxonomy_intro_archive() ) {
			return;
		}

		$term = get_queried_object();
		// El 2º parámetro (taxonomía) está deprecado desde WP 4.9.2: el término
		// ya la lleva consigo y pasarla dispara un aviso de deprecación.
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
			// Sin descripción manual propia, la landing de autor adopta la introducción generada del catálogo.
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
			><?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- allowlist local con wp_kses. ?></div>
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

	/**
	 * Reconoce el índice, hijos y estilos XSL de sitemaps.
	 *
	 * @internal Función pura expuesta para pruebas.
	 */
	public static function is_sitemap_path( string $path ): bool {
		return 1 === preg_match( '#(?:^|/)[^/]*sitemap[^/]*\.(?:xml|xsl)$#i', $path );
	}

	/**
	 * Canonical autorreferente (paginación incluida: page/2 → page/2) para
	 * archives de producto cuando NO hay plugin SEO que lo emita. Cualquier
	 * query string aborta: las URLs con parámetros van por la vía noindex y
	 * canonicalizarlas escondería errores de rastreo.
	 *
	 * @return void
	 */
	public function archive_canonical_fallback(): void {
		if ( $this->is_rank_math_active() ) {
			return;
		}
		if ( ! function_exists( 'is_shop' ) || ! ( is_shop() || is_product_taxonomy() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sólo se miran las CLAVES; no se consume ningún valor.
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

	/**
	 * Excluye la página contenedora de Tienda del sitemap de páginas.
	 *
	 * @internal Callback del hook `rank_math/sitemap/posts_to_exclude`.
	 *
	 * @param int[] $post_ids IDs excluidos configurados en Rank Math.
	 * @return int[]
	 */
	public function exclude_shop_page_from_rank_math_sitemap( array $post_ids ): array {
		$shop_page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		if ( $shop_page_id > 0 && ! in_array( $shop_page_id, $post_ids, true ) ) {
			$post_ids[] = $shop_page_id;
		}

		return $post_ids;
	}

	/**
	 * Alinea el BreadcrumbList de Rank Math con el breadcrumb visible.
	 *
	 * El theme ya muestra `Inicio > Series > Nombre`. Rank Math derivaba en
	 * cambio `Inicio > Nombre`, dejando el hub fuera del grafo aunque sea el
	 * padre navegable real. Este filtro inserta el mismo nodo, es idempotente y
	 * renumera las posiciones sin reemplazar el `@id` del BreadcrumbList.
	 *
	 * @internal Callback de `rank_math/json_ld`.
	 *
	 * @param array<string,mixed> $data   Grafo emitido por Rank Math.
	 * @param mixed               $jsonld Instancia interna de Rank Math, no usada.
	 * @return array<string,mixed>
	 */
	public function align_series_breadcrumb_schema( array $data, $jsonld = null ): array {
		unset( $jsonld );

		if ( ! function_exists( 'is_tax' ) || is_admin() || ! is_tax( self::SERIES_TAXONOMY ) ) {
			return $data;
		}

		// Resuelto dinámicamente vía SeriesHubUrl en lugar de path literal para
		// respetar la configuración canónica de la página de series.
		$hub_url = SeriesHubUrl::url();
		if ( function_exists( 'user_trailingslashit' ) ) {
			$hub_url = user_trailingslashit( $hub_url );
		}

		foreach ( $data as $key => $node ) {
			if (
				! is_array( $node )
				|| 'BreadcrumbList' !== ( $node['@type'] ?? '' )
				|| ! isset( $node['itemListElement'] )
				|| ! is_array( $node['itemListElement'] )
			) {
				continue;
			}

			$items   = array_values( $node['itemListElement'] );
			$has_hub = false;
			foreach ( $items as &$item ) {
				$item_data = is_array( $item ) && isset( $item['item'] ) && is_array( $item['item'] )
					? $item['item']
					: array();
				$item_url  = (string) ( $item_data['@id'] ?? '' );
				$item_name = (string) ( $item_data['name'] ?? '' );
				if ( rtrim( $item_url, '/' ) === rtrim( $hub_url, '/' ) || 'Series' === $item_name ) {
					$has_hub = true;
					if ( ! empty( $item_data ) ) {
						$item['item']['@id']  = $hub_url;
						$item['item']['name'] = 'Series';
					}
					break;
				}
			}
			unset( $item );

			if ( ! $has_hub ) {
				array_splice(
					$items,
					min( 1, count( $items ) ),
					0,
					array(
						array(
							'@type'    => 'ListItem',
							'position' => 2,
							'item'     => array(
								'@id'  => $hub_url,
								'name' => 'Series',
							),
						),
					)
				);
			}

			foreach ( $items as $position => &$item ) {
				if ( is_array( $item ) ) {
					$item['position'] = $position + 1;
				}
			}
			unset( $item );

			$node['itemListElement'] = $items;
			$data[ $key ]             = $node;
		}

		return $data;
	}

	/**
	 * Alinea el marcado BreadcrumbList (JSON-LD) con la miga visible en la ficha de producto.
	 *
	 * Reconstruye los niveles intermedios de taxonomía y serie a partir de `WC_Breadcrumb::generate()`
	 * para garantizar correspondencia exacta entre los fragmentos estructurados y la jerarquía
	 * visual renderizada, conservando el nodo inicial de Inicio y el nodo terminal del producto.
	 *
	 * @internal Callback de `rank_math/json_ld`.
	 *
	 * @param array<string,mixed> $data   Grafo emitido por Rank Math.
	 * @param mixed               $jsonld Instancia interna de Rank Math, no usada.
	 * @return array<string,mixed>
	 */
	public function align_product_breadcrumb_schema( array $data, $jsonld = null ): array {
		unset( $jsonld );

		if ( ! function_exists( 'is_singular' ) || is_admin() || ! is_singular( 'product' ) || ! class_exists( '\WC_Breadcrumb' ) ) {
			return $data;
		}

		// Descarta nodos sin enlace o con errores antes de aislar el nivel terminal.
		// Todo se envuelve en try-catch para garantizar resiliencia en wp_head.
		try {
			// Memoización por petición para optimizar llamadas repetidas al grafo JSON-LD.
			if ( null === $this->product_crumbs_memo ) {
				$this->product_crumbs_memo = array( ( new \WC_Breadcrumb() )->generate() );
			}
			$crumbs   = $this->product_crumbs_memo[0];
			$enlaces  = array();
			foreach ( is_array( $crumbs ) ? $crumbs : array() as $crumb ) {
				if ( ! is_array( $crumb ) || ! is_scalar( $crumb[0] ?? null ) || ! is_scalar( $crumb[1] ?? null ) ) {
					continue;
				}
				$nombre = trim( (string) $crumb[0] );
				$url    = trim( (string) $crumb[1] );
				if ( '' === $nombre || '' === $url ) {
					continue;
				}
				$enlaces[] = array( $nombre, $url );
			}
		} catch ( \Throwable $throwable ) {
			return $data;
		}
		$middle = array();
		foreach ( array_slice( $enlaces, 0, -1 ) as $crumb ) {
			$middle[] = array(
				'@type' => 'ListItem',
				'item'  => array(
					'@id'  => $crumb[1],
					'name' => $crumb[0],
				),
			);
		}
		if ( array() === $middle ) {
			return $data;
		}

		foreach ( $data as $key => $node ) {
			if (
				! is_array( $node )
				|| ! SchemaGraph::node_has_type( $node, 'BreadcrumbList' )
				|| ! isset( $node['itemListElement'] )
				|| ! is_array( $node['itemListElement'] )
				|| count( $node['itemListElement'] ) < 2
			) {
				continue;
			}

			$items = array_values( $node['itemListElement'] );
			$items = array_merge( array( $items[0] ), $middle, array( end( $items ) ) );
			foreach ( $items as $position => &$item ) {
				if ( is_array( $item ) ) {
					$item['position'] = $position + 1;
				}
			}
			unset( $item );

			$node['itemListElement'] = $items;
			$data[ $key ]             = $node;
		}

		return $data;
	}

	/**
	 * Declara `/envios/` como WebPage factual conectada al grafo de Rank Math.
	 *
	 * Reutiliza un WebPage existente y exige el BreadcrumbList de la misma
	 * respuesta para no crear referencias colgantes. La identidad comercial
	 * sigue perteneciendo a Rank Math mediante el `WebSite` global.
	 *
	 * @internal Callback de `rank_math/json_ld`.
	 *
	 * @param array<string,mixed> $data   Grafo emitido por Rank Math.
	 * @param mixed               $jsonld Instancia interna de Rank Math, no usada.
	 * @return array<string,mixed>
	 */
	public function add_shipping_page_schema( array $data, $jsonld = null ): array {
		unset( $jsonld );

		if ( ! function_exists( 'is_page' ) || ! is_page( trim( self::SHIPPING_PATH, '/' ) ) ) {
			return $data;
		}

		$page_key      = null;
		$breadcrumb_id = '';
		foreach ( $data as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( SchemaGraph::node_has_type( $node, 'WebPage' ) ) {
				$page_key = $key;
			}
			if ( SchemaGraph::node_has_type( $node, 'BreadcrumbList' ) ) {
				$breadcrumb_id = trim( (string) ( $node['@id'] ?? '' ) );
			}
		}

		if ( '' === $breadcrumb_id ) {
			return $data;
		}

		$url      = home_url( self::SHIPPING_PATH );
		$web_page = null !== $page_key && is_array( $data[ $page_key ] )
			? $data[ $page_key ]
			: array();

		$web_page['@type']       = 'WebPage';
		$web_page['@id']         = $url . '#webpage';
		$web_page['url']         = $url;
		$web_page['name']        = 'Envíos y retiro de pedidos';
		$web_page['description'] = 'Consulta opciones de despacho a todo Chile y retiro coordinado en Metro San Miguel. El costo se muestra antes de pagar.';
		$web_page['isPartOf']    = array( '@id' => home_url( '/#website' ) );
		$web_page['breadcrumb']  = array( '@id' => $breadcrumb_id );

		$data[ $page_key ?? 'ShippingInformationWebPage' ] = $web_page;

		return $data;
	}

	/**
	 * Declara el hub `/series/` como CollectionPage con su ItemList real.
	 *
	 * `SeriesIndex` es la misma fuente que usa el shortcode: catálogo visible, sin
	 * `noindex`, URL resoluble y orden alfabético estable. El schema enumera esas
	 * URLs. Los términos que admiten semántica de cómic enlazan su `ComicSeries`;
	 * un término marcado explícitamente `non_comic` permanece en la lista como
	 * `Thing`, sin `@id` de serie. No añade Product, Offer, precio ni disponibilidad
	 * porque el directorio no representa una oferta individual.
	 *
	 * @internal Callback de `rank_math/json_ld`.
	 *
	 * @param array<string,mixed> $data   Grafo emitido por Rank Math.
	 * @param mixed               $jsonld Instancia interna de Rank Math, no usada.
	 * @return array<string,mixed>
	 */
	public function add_series_hub_schema( array $data, $jsonld = null ): array {
		unset( $jsonld );

		$post = $this->current_series_directory_post();
		if ( null === $post || ! isset( $post->ID ) ) {
			return $data;
		}

		$entries = $this->series_index->entries();
		if ( ! $this->series_index->is_reliable() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constante estándar de facto de los plugins de caché (LiteSpeed, W3TC, WP Super Cache): prefijarla la dejaría inerte.
			}
			do_action( 'litespeed_control_set_nocache', 'series catalogue eligibility unavailable' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.

			// El HTML del shortcode puede provenir de su transient válido mientras
			// esta lectura falla. Sin este guard, el edge podría congelar esa página
			// visible sin CollectionPage/ItemList hasta el siguiente purge.
			return $data;
		}
		$url     = get_permalink( (int) $post->ID );
		if ( empty( $entries ) || ! is_string( $url ) || '' === trim( $url ) ) {
			return $data;
		}
		if ( function_exists( 'user_trailingslashit' ) ) {
			$url = user_trailingslashit( $url );
		}

		$title = get_the_title( (int) $post->ID );
		$title = is_string( $title ) ? trim( wp_strip_all_tags( $title, true ) ) : '';
		$title = '' !== $title ? $title : 'Series';

		$list_id = $url . '#series-list';
		$items   = array();
		foreach ( $entries as $position => $entry ) {
			$item = array(
				'@type' => 'Thing',
				'name'  => $entry['name'],
				'url'   => $entry['url'],
			);
			if ( SeriesSchemaType::supports_comic_series( (int) $entry['term_id'] ) ) {
				$item['@type'] = 'ComicSeries';
				$item['@id']   = rtrim( $entry['url'], '#' ) . '#serie';
			}
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position + 1,
				'item'     => $item,
			);
		}
		$item_list = array(
			'@type'           => 'ItemList',
			'@id'             => $list_id,
			'name'            => 'Series de manga y cómics en Akibara',
			'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);

		$page_key = null;
		$list_key = null;
		foreach ( $data as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( SchemaGraph::node_has_type( $node, 'ItemList' ) && (string) ( $node['@id'] ?? '' ) === $list_id ) {
				$list_key = $key;
			}
			if ( SchemaGraph::node_has_type( $node, 'CollectionPage' ) || SchemaGraph::node_has_type( $node, 'WebPage' ) ) {
				$page_key = $key;
			}
		}

		$collection_page = null !== $page_key && is_array( $data[ $page_key ] )
			? $data[ $page_key ]
			: array();
		$collection_page['@type']      = 'CollectionPage';
		$collection_page['@id']        = $url . '#webpage';
		$collection_page['url']        = $url;
		$collection_page['name']       = $title;
		$collection_page['mainEntity'] = array( '@id' => $list_id );

		$data[ $page_key ?? 'SeriesDirectoryCollectionPage' ] = $collection_page;
		$data[ $list_key ?? 'SeriesDirectoryItemList' ]       = $item_list;

		return $data;
	}

	/**
	 * Devuelve la página singular que contiene el shortcode del directorio.
	 */
	private function current_series_directory_post(): ?object {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return null;
		}

		$post = get_post();
		if ( ! is_object( $post ) || ! is_string( $post->post_content ?? null ) ) {
			return null;
		}

		return has_shortcode( $post->post_content, SeriesDirectoryShortcode::TAG ) ? $post : null;
	}

	/**
	 * Hace únicos los títulos de páginas 2+ sin reemplazar la decisión editorial.
	 *
	 * Rank Math no ofrece un sufijo global: `%page%` vive en cada plantilla y
	 * los overrides de páginas/términos pueden omitirlo. Este fallback usa su
	 * filtro oficial y solo actúa cuando el título expandido aún no contiene el
	 * marcador localizado.
	 */
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

	/**
	 * Diferencia las meta descriptions de páginas 2+ preservando frases completas.
	 *
	 * En una landing de serie, la plantilla de página uno puede quedar cerca de
	 * 160 caracteres. Si el marcador no cabe, se retiene su primera frase
	 * (nombre + Chile) y la última (intención transaccional), y se omite la frase
	 * intermedia de estado. No se cortan palabras ni se añaden elipsis.
	 */
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

	/**
	 * Respeta la plantilla de producto mantenida en la UI de Rank Math.
	 *
	 * Rank Math prioriza `post_excerpt` antes de
	 * `titles.pt_product_description`; por ello un extracto comercial extenso
	 * puede reemplazar silenciosamente la plantilla configurada en su UI. No
	 * existe un ajuste de interfaz para invertir esa precedencia. Este callback
	 * usa el filtro oficial y solo corrige ese caso: una ficha de producto sin
	 * descripción SEO individual. Rank Math sigue expandiendo las variables y
	 * sigue siendo el único emisor de la etiqueta meta.
	 *
	 * @param string $description Descripción que Rank Math iba a emitir.
	 * @return string
	 *
	 * @since 1.93.0
	 */
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

		// Primero la variante rica (autor + editorial + tomo + serie + medio).
		// Si el catálogo no la sostiene, la plantilla de la UI sigue mandando.
		$meta = $this->generated_product_meta( $post_id );
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

	/**
	 * Limpia la plantilla expandida y acorta solo los resultados sobre 160.
	 *
	 * La salida larga se lleva a un máximo editorial de 155 caracteres,
	 * conservando una palabra completa siempre que sea razonable. Así queda
	 * espacio para variaciones de presentación del buscador y se mantiene
	 * estrictamente bajo 160.
	 *
	 * @internal Función pura salvo por el sanitizador estándar de WordPress.
	 */
	public static function sanitize_product_meta_description( string $description ): string {
		$description = trim( wp_strip_all_tags( $description, true ) );
		$description = preg_replace( '/\s+/u', ' ', $description );
		$description = is_string( $description ) ? trim( $description ) : '';

		if ( Text::length( $description ) <= 160 ) {
			return $description;
		}

		$cut       = Text::substr( $description, 0, 154 );
		$word_safe = preg_replace( '/\s+\S*$/u', '', $cut );
		if ( is_string( $word_safe ) && Text::length( trim( $word_safe ) ) >= 100 ) {
			$cut = $word_safe;
		}

		return rtrim( trim( $cut ), " \t\n\r\0\x0B,;:-" ) . '…';
	}

	/**
	 * Publica una meta híbrida estable para la intención de compra chilena.
	 *
	 * La obra original y el catálogo son ejes distintos. Por eso el estado, si
	 * existe, siempre se rotula como estado de la obra; el cierre transaccional
	 * invita a revisar el catálogo sin prometer stock, completitud ni cantidades.
	 * Una descripción SEO individual de Rank Math conserva precedencia.
	 *
	 * @param string $description Descripción que Rank Math iba a emitir.
	 * @return string
	 */
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

		// Primero la variante rica (tomos + autor + editorial + medio). Si el
		// catálogo no puede responder por ella —sin producto testigo, sin
		// categoría raíz reconocible, facts caídos— se conserva la plantilla
		// genérica de siempre, que no afirma cifras.
		$meta = $this->generated_series_meta( $term );
		if ( null !== $meta && '' !== $meta['description'] ) {
			return $meta['description'];
		}
		if ( '' !== trim( (string) $custom_description ) ) {
			// Si el catálogo falla, se conserva la última foto válida en vez de
			// degradar una URL que sí tenía una descripción comprobada.
			return $description;
		}

		$publication = PublicationStatus::scoped_for_term( $term_id );
		$generated   = self::series_meta_description(
			(string) $term->name,
			(string) ( $publication['obra']['estado'] ?? '' )
		);

		return '' !== $generated ? $generated : $description;
	}

	/**
	 * Título rico para landings de serie sin `rank_math_title` propio.
	 *
	 * Contrato de verdad idéntico al de la descripción: el número de tomos del
	 * catálogo solo se afirma cuando la obra NO está completa con más tomos de
	 * los que vendemos (caso Naruto: 5 productos de una obra de 72; un título
	 * «5 tomos» leería como el largo de la serie). El marcador de paginación de
	 * la prioridad 99 sufija después sobre este resultado.
	 *
	 * @param string $title Título que Rank Math iba a emitir.
	 * @return string
	 *
	 * @since 1.104.0
	 */
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

		$meta = $this->generated_series_meta( $term );

		return ( null !== $meta && '' !== $meta['title'] ) ? $meta['title'] : $title;
	}

	/**
	 * Resuelve la nota de una serie para superficies derivadas como llms.txt.
	 *
	 * Las descripciones editoriales siempre ganan. Una fila marcada como foto
	 * generada se recompone con el catálogo actual; si ese catálogo no puede
	 * responder de forma fiable, se conserva la última foto persistida.
	 *
	 * @param object $term Término de `pa_serie`.
	 * @return string Descripción manual, generada o última foto comprobada.
	 */
	public function series_manifest_description( object $term ): string {
		$term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;
		if ( $term_id < 1 ) {
			return '';
		}

		$stored    = trim( (string) get_term_meta( $term_id, 'rank_math_description', true ) );
		$generated = $this->is_generated_series_meta_field( $term_id, 'description' );
		if ( '' !== $stored && ! $generated ) {
			return $stored;
		}

		$meta = $this->generated_series_meta( $term );
		if ( null !== $meta && '' !== $meta['description'] ) {
			return $meta['description'];
		}

		return $stored;
	}

	/**
	 * Comprueba que una fila sigue siendo la foto generada que fue registrada.
	 *
	 * La propiedad y su hash son inseparables: si alguien edita el título o la
	 * descripción desde Rank Math, el hash deja de coincidir y el valor pasa a
	 * considerarse editorial de forma automática y fail-safe.
	 *
	 * La marca no es la ÚNICA prueba de autoría automática: una fila escrita por
	 * una plantilla retirada del propio generador también lo es, aunque no lleve
	 * marca. Ver {@see SERIES_META_RETIRED_CLOSINGS}.
	 *
	 * @param int    $term_id Término de serie.
	 * @param string $field   `title` o `description`.
	 */
	private function is_generated_series_meta_field( int $term_id, string $field ): bool {
		if ( $term_id < 1 || ! in_array( $field, array( 'title', 'description' ), true ) ) {
			return false;
		}

		$is_title     = 'title' === $field;
		$value_key    = $is_title ? 'rank_math_title' : 'rank_math_description';
		$owner_key    = $is_title ? self::SERIES_TITLE_OWNERSHIP_KEY : self::SERIES_DESCRIPTION_OWNERSHIP_KEY;
		$snapshot_key = $is_title ? self::SERIES_TITLE_SNAPSHOT_HASH_KEY : self::SERIES_DESCRIPTION_SNAPSHOT_HASH_KEY;

		$value = (string) get_term_meta( $term_id, $value_key, true );
		if ( self::is_retired_generated_meta( $value ) ) {
			return true;
		}

		$owner = (string) get_term_meta( $term_id, $owner_key, true );
		if ( self::SERIES_META_GENERATED !== $owner ) {
			return false;
		}

		$snapshot_hash = strtolower( trim( (string) get_term_meta( $term_id, $snapshot_key, true ) ) );
		if ( '' === trim( $value ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $snapshot_hash ) ) {
			return false;
		}

		return hash_equals( $snapshot_hash, hash( 'sha256', $value ) );
	}

	/**
	 * ¿El valor lo escribió una plantilla retirada del generador?
	 *
	 * Se exige el cierre EXACTO al final del texto, no una coincidencia en
	 * cualquier posición: el criterio tiene que ser tan estrecho que ninguna
	 * frase que el dueño escriba sobre una serie pueda caer dentro por accidente.
	 * Confundir copy editorial con una foto generada lo descartaría en silencio,
	 * que es un daño peor que el congelamiento que esta función viene a
	 * deshacer.
	 */
	private static function is_retired_generated_meta( string $value ): bool {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		if ( '' === $value ) {
			return false;
		}

		foreach ( self::SERIES_META_RETIRED_CLOSINGS as $closing ) {
			$offset = Text::length( $value ) - Text::length( $closing );
			if ( $offset >= 0 && $closing === Text::substr( $value, $offset, Text::length( $closing ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Qué es hoy cada campo SEO de la serie, con las MISMAS tres ramas que usa
	 * el front.
	 *
	 * La tercera rama es la que se me pasó en el primer intento: un campo VACÍO
	 * no está congelado. `prefer_series_term_title()` (l. ~990) y
	 * `prefer_series_term_description()` sólo respetan el termmeta cuando tiene
	 * contenido, así que una serie recién creada ya es automática aunque no
	 * tenga ninguna meta de propiedad. Anunciarla como «manual» sería mentir
	 * justo en la pantalla que existe para no mentir.
	 *
	 * @param int $term_id Término de serie.
	 * @return array{title:string,description:string} Uno de los tres estados.
	 */
	private function series_meta_provenance( int $term_id ): array {
		$state = array();
		foreach ( array( 'title' => 'rank_math_title', 'description' => 'rank_math_description' ) as $field => $value_key ) {
			$stored = trim( (string) get_term_meta( $term_id, $value_key, true ) );
			if ( '' === $stored ) {
				$state[ $field ] = self::SERIES_META_AUTO_LIVE;
				continue;
			}
			$state[ $field ] = $this->is_generated_series_meta_field( $term_id, $field )
				? self::SERIES_META_AUTO_STORED
				: self::SERIES_META_MANUAL;
		}

		return $state;
	}

	/** Etiqueta humana de un estado de procedencia. */
	private static function series_meta_provenance_label( string $state ): string {
		return match ( $state ) {
			self::SERIES_META_AUTO_LIVE   => 'automático (se compone al servir la página)',
			self::SERIES_META_AUTO_STORED => 'automático (foto guardada del catálogo)',
			default                       => 'manual: congelado, ya no sigue al catálogo',
		};
	}

	/**
	 * Aviso de procedencia junto a los campos SEO del término.
	 *
	 * Por qué existe: la UI de Rank Math pinta el título y la descripción
	 * generados igual que los escritos a mano, y `is_generated_series_meta_field()`
	 * los da por editoriales en cuanto el hash deja de coincidir — es decir, un
	 * retoque de una coma congela el conteo de tomos de esa serie para siempre y
	 * NADA se lo dice al dueño. Este renglón dice el estado, enseña lo que
	 * emitiría el generador hoy y ofrece la vuelta atrás.
	 *
	 * @internal Callback de `pa_serie_edit_form_fields`.
	 *
	 * @param mixed  $term     Término editado.
	 * @param string $taxonomy Taxonomía recibida por WordPress.
	 * @return void
	 */
	public function render_series_meta_provenance( $term, $taxonomy = '' ): void {
		if ( self::SERIES_TAXONOMY !== (string) $taxonomy || ! is_object( $term ) || ! isset( $term->term_id ) ) {
			return;
		}

		$term_id    = (int) $term->term_id;
		$provenance = $this->series_meta_provenance( $term_id );
		$generated  = $this->generated_series_meta( $term );
		$frozen     = self::SERIES_META_MANUAL === $provenance['title']
			|| self::SERIES_META_MANUAL === $provenance['description'];
		?>
		<tr class="form-field">
			<th scope="row">Procedencia del SEO</th>
			<td>
				<p>
					<strong>Título:</strong> <?php echo esc_html( self::series_meta_provenance_label( $provenance['title'] ) ); ?><br>
					<strong>Descripción:</strong> <?php echo esc_html( self::series_meta_provenance_label( $provenance['description'] ) ); ?>
				</p>
				<p class="description">
					El título y la descripción automáticos se componen con el catálogo
					de la serie (tomos publicados, autor y editorial) cada vez que se
					sirve la landing. Si editas el campo de Rank Math, ese valor queda
					congelado: deja de seguir al catálogo y ya no cambia cuando entra
					un tomo nuevo.
				</p>
				<?php if ( null !== $generated ) : ?>
					<p class="description">
						<strong>Lo que publicaría el modo automático hoy</strong><br>
						Título: <?php echo esc_html( '' !== $generated['title'] ? $generated['title'] : '(sin variante rica: el catálogo no la sostiene)' ); ?><br>
						Descripción: <?php echo esc_html( '' !== $generated['description'] ? $generated['description'] : '(sin variante rica: el catálogo no la sostiene)' ); ?>
					</p>
				<?php else : ?>
					<p class="description">
						El catálogo de esta serie no sostiene hoy la variante rica
						(hace falta un medio reconocible: manga, cómic o manhwa).
						Se publican las plantillas genéricas de Rank Math.
					</p>
				<?php endif; ?>
				<?php if ( $frozen && null !== $generated ) : ?>
					<p>
						<a class="button" href="<?php echo esc_url( $this->series_meta_restore_url( $term_id ) ); ?>">Volver a automático</a>
					</p>
					<p class="description">
						Reemplaza el título y la descripción guardados por la foto del
						catálogo de ahora y vuelve a marcarlos como automáticos. El
						texto manual actual se pierde.
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * URL firmada que devuelve una serie a meta automática.
	 *
	 * Va por `admin-post.php` y no por un submit propio porque el renglón vive
	 * DENTRO del formulario del término: un `<form>` anidado es HTML inválido y
	 * los navegadores lo descartan sin avisar.
	 *
	 * @param int $term_id Término de serie.
	 */
	private function series_meta_restore_url( int $term_id ): string {
		$url = add_query_arg(
			array(
				'action'  => self::SERIES_META_RESTORE_ACTION,
				'term_id' => $term_id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::SERIES_META_RESTORE_ACTION . '_' . $term_id );
	}

	/**
	 * Restaura los metadatos de serie a generación automática: reescribe valor, marca y hash.
	 *
	 * Permite a los administradores devolver una serie a sincronización dinámica con el catálogo
	 * si fue editada manualmente o marcada accidentalmente.
	 *
	 * @internal Callback de `admin_post_akibara_serie_seo_auto`.
	 *
	 * @return void
	 */
	public function handle_restore_generated_series_meta(): void {
		$term_id = isset( $_GET['term_id'] ) ? (int) $_GET['term_id'] : 0;
		if ( $term_id < 1 ) {
			wp_die( 'Serie no indicada.' );
		}
		check_admin_referer( self::SERIES_META_RESTORE_ACTION . '_' . $term_id );
		if ( ! current_user_can( self::SERIES_TERMS_CAP ) ) {
			wp_die( 'Sin permisos.' );
		}

		$term   = get_term( $term_id, self::SERIES_TAXONOMY );
		$result = 'error';
		if ( is_object( $term ) && ! ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) ) ) {
			$result = $this->restore_generated_series_meta( $term ) ? 'ok' : 'sin-foto';
		}

		// La landing se sirve desde la caché de PÁGINA de LiteSpeed y ningún
		// evento de post se dispara al escribir termmeta. Misma etiqueta
		// (`T.<term_id>`, el archive completo con sus páginas 2+) y mismo motivo
		// que `PublicationStatusFields::purge_landing()`.
		if ( 'ok' === $result ) {
			do_action( 'litespeed_purge', 'T.' . $term_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público oficial de LiteSpeed Cache.
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'taxonomy'  => self::SERIES_TAXONOMY,
					'post_type' => 'product',
					'tag_ID'    => $term_id,
					'akb_seo'   => $result,
				),
				admin_url( 'term.php' )
			)
		);
		exit;
	}

	/**
	 * Escribe la foto del catálogo como meta de Rank Math y la marca automática.
	 *
	 * El hash es inseparable del valor: se guarda el sha256 del texto REALMENTE
	 * escrito, que es lo que `is_generated_series_meta_field()` va a comparar.
	 * Un campo sin foto (el generador devuelve '') no se toca ni se marca: el
	 * automático no puede publicar una cadena vacía.
	 *
	 * @param object $term Término de serie.
	 * @return bool true si al menos un campo volvió a automático.
	 */
	private function restore_generated_series_meta( object $term ): bool {
		$meta = $this->generated_series_meta( $term );
		if ( null === $meta ) {
			return false;
		}

		$term_id  = (int) $term->term_id;
		$restored = false;
		$fields   = array(
			'title'       => array( 'rank_math_title', self::SERIES_TITLE_OWNERSHIP_KEY, self::SERIES_TITLE_SNAPSHOT_HASH_KEY ),
			'description' => array( 'rank_math_description', self::SERIES_DESCRIPTION_OWNERSHIP_KEY, self::SERIES_DESCRIPTION_SNAPSHOT_HASH_KEY ),
		);
		foreach ( $fields as $field => $keys ) {
			$value = (string) ( $meta[ $field ] ?? '' );
			if ( '' === trim( $value ) ) {
				continue;
			}
			list( $value_key, $owner_key, $hash_key ) = $keys;
			update_term_meta( $term_id, $value_key, $value );
			update_term_meta( $term_id, $owner_key, self::SERIES_META_GENERATED );
			update_term_meta( $term_id, $hash_key, hash( 'sha256', $value ) );
			$restored = true;
		}

		return $restored;
	}

	/**
	 * Aviso del resultado tras «Volver a automático».
	 *
	 * @internal Callback de `admin_notices`.
	 *
	 * @return void
	 */
	public function render_series_meta_restore_notice(): void {
		$result = isset( $_GET['akb_seo'] ) ? sanitize_key( wp_unslash( (string) $_GET['akb_seo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bandera de sólo lectura puesta por nuestro propio wp_safe_redirect; no cambia estado.
		if ( '' === $result ) {
			return;
		}

		$message = match ( $result ) {
			'ok'       => array( 'success', 'Título y descripción SEO vuelven a generarse con el catálogo. Se limpió la caché de esta landing.' ),
			'sin-foto' => array( 'warning', 'No se pudo volver a automático: el catálogo de esta serie no sostiene hoy una foto publicable.' ),
			'error'    => array( 'error', 'No se pudo volver a automático: la serie no existe.' ),
			default    => null,
		};
		if ( null === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $message[0] ),
			esc_html( $message[1] )
		);
	}

	/**
	 * Columna «SEO» en el listado de series.
	 *
	 * @internal Callback de `manage_edit-pa_serie_columns`.
	 *
	 * @param mixed $columns Columnas recibidas.
	 * @return mixed
	 */
	public function add_series_meta_provenance_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$columns['akb_seo'] = 'SEO';

		return $columns;
	}

	/**
	 * Contenido de la columna «SEO»: de un vistazo, qué series están congeladas.
	 *
	 * @internal Callback de `manage_pa_serie_custom_column`.
	 *
	 * @param mixed  $content Contenido acumulado.
	 * @param mixed  $column  Columna pedida.
	 * @param mixed  $term_id Término de la fila.
	 * @return mixed
	 */
	public function render_series_meta_provenance_column( $content, $column = '', $term_id = 0 ) {
		if ( 'akb_seo' !== (string) $column ) {
			return $content;
		}

		$provenance = $this->series_meta_provenance( (int) $term_id );
		$title_auto = self::SERIES_META_MANUAL !== $provenance['title'];
		$desc_auto  = self::SERIES_META_MANUAL !== $provenance['description'];
		if ( $title_auto && $desc_auto ) {
			return 'auto';
		}
		if ( ! $title_auto && ! $desc_auto ) {
			return 'manual';
		}

		return $title_auto ? 'título auto · desc. manual' : 'título manual · desc. auto';
	}

	/**
	 * Compone una plantilla natural sin señales comerciales volátiles.
	 *
	 * Se prueban variantes completas, de más a menos específicas. Nunca se corta
	 * un título o una palabra: si un nombre futuro no cabe, se usa el fallback
	 * genérico en vez de publicar una frase rota o terminar en elipsis.
	 *
	 * @internal Función pura salvo por el sanitizador estándar de WordPress.
	 *
	 * @param string $series_name Nombre público del término.
	 * @param string $work_state  Estado validado de la obra original.
	 */
	public static function series_meta_description( string $series_name, string $work_state = '' ): string {
		$name = function_exists( 'strip_shortcodes' )
			? (string) strip_shortcodes( $series_name )
			: $series_name;
		$name = trim( wp_strip_all_tags( $name, true ) );
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = (string) str_replace( array( "\xc2\xa0", "\xe2\x80\x8b" ), ' ', $name );
		$name = preg_replace( '/\s+/u', ' ', $name );
		$name = is_string( $name ) ? trim( $name ) : '';
		if ( '' === $name ) {
			return '';
		}

		$clause = match ( PublicationStatus::sanitize_work_state( $work_state ) ) {
			PublicationStatus::COMPLETE  => 'La obra original está finalizada.',
			PublicationStatus::ONGOING   => 'La obra original sigue en publicación.',
			PublicationStatus::PAUSED    => 'La obra original está en pausa.',
			PublicationStatus::CANCELLED => 'La obra original fue cancelada.',
			default                      => 'Conoce la serie.',
		};
		// Se utiliza «tomos a pedido» acorde a la semántica comercial del catálogo.
		// La forma concisa permite asegurar que descripciones extensas no superen los 160 caracteres.
		// Constantine: Hellblazer» en publicación).
		$candidates = array(
			sprintf( '%s en Akibara Chile. %s Revisa el catálogo, los tomos a pedido y el envío según destino.', $name, $clause ),
			sprintf( '%s en Akibara Chile. %s Revisa catálogo, tomos a pedido y envío según destino.', $name, $clause ),
			sprintf( '%s en Akibara Chile. Revisa el catálogo, los tomos a pedido y el envío según destino.', $name ),
			'Series de manga y cómics en Akibara Chile. Revisa el catálogo, los tomos a pedido y el envío según destino.',
		);

		foreach ( $candidates as $candidate ) {
			if ( Text::length( $candidate ) <= 160 ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Mantiene entidades coherentes en el @graph global de Rank Math.
	 *
	 * Elimina un Place sin datos y su referencia Organization.location. Si hay una
	 * dirección real conserva el nodo; no rellena una ubicación ni publica una
	 * dirección privada como LocalBusiness.
	 *
	 * El Person editorial de Akibara usa una identidad canónica en @id, url y todas
	 * las referencias exactas al identificador sustituido. El guard de pertenencia
	 * a Akibara impide modificar mangakas, reseñadores y otros autores. AboutPage
	 * lo declara mainEntity en Nosotros para mantener la referencia durante la
	 * validación de schema de Rank Math.
	 *
	 * @param mixed $data   Nodos del @graph que Rank Math va a emitir.
	 * @param mixed $jsonld Contexto de Rank Math; no se usa.
	 * @return mixed
	 *
	 * @since 1.105.0
	 */
	public function cleanup_global_schema( $data, $jsonld = null ) {
		unset( $jsonld );
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$home_url = function_exists( 'home_url' )
			? rtrim( (string) home_url( '/' ), '/' ) . '/'
			: 'https://akibara.cl/';
		$about_url        = rtrim( $home_url, '/' ) . self::ABOUT_PATH;
		$editor_person_id = $about_url . '#' . self::EDITOR_PERSON_FRAGMENT;
		$organization_id  = $home_url . '#organization';

		$removed_place_ids = array();
		foreach ( $data as $key => $node ) {
			if ( is_array( $node ) && self::node_is_empty_place( $node ) ) {
				$id = (string) ( $node['@id'] ?? '' );
				if ( '' !== $id ) {
					$removed_place_ids[] = $id;
				}
				unset( $data[ $key ] );
			}
		}

		$id_replacements  = array();
		$has_editor_person = false;
		foreach ( $data as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( isset( $node['location'] ) ) {
				$location    = $node['location'];
				$location_id = is_array( $location ) ? (string) ( $location['@id'] ?? '' ) : '';
				$dangling    = in_array( $location_id, $removed_place_ids, true );
				$inline_hole = is_array( $location ) && self::node_is_empty_place( $location );
				if ( $dangling || $inline_hole ) {
					unset( $data[ $key ]['location'] );
				}
			}

			// Mismo bug, otra casa: Rank Math emite `address` con solo
			// `@type: PostalAddress` en el nodo OnlineStore/Organization
			// cuando la dirección local está vacía (la tienda es 100 %
			// online y la dirección es deliberadamente privada). Un consumidor
			// lee «hay una dirección sin ningún dato». Se retira SOLO si no
			// trae ningún campo real; una dirección cargada pasa intacta.
			// Rank Math borra las claves vacías en validate_schema(), que corre
			// DESPUÉS de este filtro: aquí la dirección llega con sus campos
			// presentes pero en '' — por eso se juzga por valor, no por clave.
			if ( isset( $node['address'] ) && is_array( $node['address'] ) ) {
				$address_filled = array_filter(
					array_diff_key( $node['address'], array( '@type' => true ) ),
					static function ( $value ) {
						return is_array( $value ) ? array() !== array_filter( $value ) : '' !== trim( (string) $value );
					}
				);
				if ( array() === $address_filled ) {
					unset( $data[ $key ]['address'] );
				}
			}

			if ( self::node_is_editor_person( $node, $organization_id ) ) {
				$old_id = isset( $node['@id'] ) && is_string( $node['@id'] )
					? $node['@id']
					: '';
				if ( '' !== $old_id && $editor_person_id !== $old_id ) {
					$id_replacements[ $old_id ] = $editor_person_id;
				}

				$data[ $key ]['@id'] = $editor_person_id;
				$data[ $key ]['url'] = $about_url;
				$has_editor_person   = true;
			}
		}

		if ( array() !== $id_replacements ) {
			$data = self::rewrite_schema_id_references( $data, $id_replacements );
		}

		if ( $has_editor_person ) {
			foreach ( $data as $key => $node ) {
				if ( ! is_array( $node ) || ! self::node_is_about_page( $node, $about_url ) ) {
					continue;
				}

				$main_entity = $node['mainEntity'] ?? null;
				if ( null === $main_entity || '' === $main_entity || array() === $main_entity ) {
					$data[ $key ]['mainEntity'] = array( '@id' => $editor_person_id );
				}
			}
		}

		return $data;
	}

	/**
	 * ¿Este nodo es el responsable editorial público de Akibara?
	 *
	 * Nombre y pertenencia son obligatorios. No basta el tipo Person ni una URL
	 * bajo `/author/`: eso identificaría erróneamente a futuros colaboradores.
	 *
	 * @param array<string,mixed> $node            Nodo del @graph.
	 * @param string              $organization_id @id canónico de Akibara.
	 */
	private static function node_is_editor_person( array $node, string $organization_id ): bool {
		$types = (array) ( $node['@type'] ?? array() );
		if ( ! in_array( 'Person', $types, true )
			|| self::EDITOR_PERSON_NAME !== trim( (string) ( $node['name'] ?? '' ) ) ) {
			return false;
		}

		$works_for = $node['worksFor'] ?? null;
		if ( is_array( $works_for )
			&& isset( $works_for['@id'] )
			&& is_string( $works_for['@id'] ) ) {
			return $organization_id === $works_for['@id'];
		}
		if ( ! is_array( $works_for ) ) {
			return false;
		}

		foreach ( $works_for as $organization ) {
			if ( is_array( $organization )
				&& isset( $organization['@id'] )
				&& $organization_id === $organization['@id'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reemplaza únicamente referencias JSON-LD `@id` con igualdad exacta.
	 *
	 * @param array<mixed>         $value        Fragmento del grafo.
	 * @param array<string,string> $replacements Mapa identificador viejo => nuevo.
	 * @return array<mixed>
	 */
	private static function rewrite_schema_id_references( array $value, array $replacements ): array {
		foreach ( $value as $key => $item ) {
			if ( '@id' === $key && is_string( $item ) && isset( $replacements[ $item ] ) ) {
				$value[ $key ] = $replacements[ $item ];
				continue;
			}
			if ( is_array( $item ) ) {
				$value[ $key ] = self::rewrite_schema_id_references( $item, $replacements );
			}
		}

		return $value;
	}

	/**
	 * ¿Este nodo representa la página pública /nosotros/?
	 *
	 * @param array<string,mixed> $node      Nodo del @graph.
	 * @param string              $about_url URL canónica con slash final.
	 */
	private static function node_is_about_page( array $node, string $about_url ): bool {
		$types = (array) ( $node['@type'] ?? array() );
		if ( ! in_array( 'AboutPage', $types, true ) ) {
			return false;
		}

		$url = isset( $node['url'] ) && is_string( $node['url'] )
			? rtrim( $node['url'], '/' ) . '/'
			: '';
		if ( $about_url === $url ) {
			return true;
		}

		$id = isset( $node['@id'] ) && is_string( $node['@id'] ) ? $node['@id'] : '';
		return 0 === strpos( $id, $about_url . '#' );
	}

	/**
	 * ¿Este nodo es el Place hueco de Rank Math?
	 *
	 * Vacío significa: tipo Place, sin name/geo/telephone, y una address sin
	 * ninguna propiedad con contenido (las claves `@type`/`@id` no cuentan).
	 *
	 * @param array<string,mixed> $node Nodo del @graph.
	 */
	private static function node_is_empty_place( array $node ): bool {
		$types = (array) ( $node['@type'] ?? array() );
		if ( ! in_array( 'Place', $types, true ) ) {
			return false;
		}

		foreach ( array( 'name', 'geo', 'telephone' ) as $signal ) {
			if ( isset( $node[ $signal ] ) && '' !== $node[ $signal ] && array() !== $node[ $signal ] ) {
				return false;
			}
		}

		$address = $node['address'] ?? null;
		if ( null === $address || '' === $address ) {
			return true;
		}
		if ( ! is_array( $address ) ) {
			return false; // Una dirección en string es contenido: se respeta.
		}
		foreach ( $address as $property => $value ) {
			if ( '@type' === $property || '@id' === $property ) {
				continue;
			}
			if ( '' !== $value && array() !== $value && null !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Memo por request del meta compuesto de la serie servida.
	 *
	 * El título (prio 20) y la descripción (prio 20) del mismo request llegan
	 * por filtros distintos; sin memo, la foto del catálogo se tomaría dos
	 * veces y podría diferir entre ambas superficies.
	 *
	 * @var array<int,array{title:string,description:string}|null>
	 */
	private array $series_meta_memo = array();

	/**
	 * Memo por request del medio por término.
	 *
	 * @var array<int,string>
	 */
	private array $series_medium_memo = array();

	/**
	 * Compone (una vez por request) el meta rico del término de serie.
	 *
	 * @param object $term Término servido, ya validado por el caller.
	 * @return array{title:string,description:string}|null Null si el catálogo
	 *                                                     no sostiene la variante rica.
	 *
	 * @since 1.104.0
	 */
	private function generated_series_meta( object $term ): ?array {
		$term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;
		if ( $term_id < 1 ) {
			return null;
		}
		if ( array_key_exists( $term_id, $this->series_meta_memo ) ) {
			return $this->series_meta_memo[ $term_id ];
		}

		$meta     = null;
		$snapshot = $this->series_catalog_snapshot( $term );
		if ( null !== $snapshot ) {
			$publication = PublicationStatus::scoped_for_term( $term_id );
			$composed    = self::compose_series_meta(
				(string) $term->name,
				(string) ( $publication['obra']['estado'] ?? '' ),
				(int) ( $publication['obra']['tomos'] ?? 0 ),
				$snapshot['count'],
				$snapshot['author'],
				$snapshot['brand'],
				$snapshot['medium'],
				$snapshot['price_from'],
				// Se puede comprar entera sólo si NINGÚN tomo está agotado. Con
				// `instock` en 0 (sin dato) la afirmación se cae sola.
				$snapshot['count'] > 0 && (int) ( $snapshot['instock'] ?? 0 ) === $snapshot['count'],
				// El eje de la EDITORIAL que la trae, distinto del de Japón.
				(string) ( $publication['edicion']['estado'] ?? '' ),
				(int) ( $publication['edicion']['tomos'] ?? 0 )
			);
			if ( '' !== $composed['title'] || '' !== $composed['description'] ) {
				$meta = $composed;
			}
		}

		$this->series_meta_memo[ $term_id ] = $meta;

		return $meta;
	}

	/**
	 * Foto verificable del catálogo de la serie: conteo, autor, editorial, medio.
	 *
	 * Cada dato se afirma solo si es inequívoco: el autor únicamente cuando la
	 * serie tiene EXACTAMENTE uno, la editorial únicamente cuando hay una sola.
	 * Sin medio reconocible (manga/cómic/manhwa desde la categoría raíz real)
	 * no hay foto: mejor la plantilla genérica que un «manga» adivinado sobre
	 * un cómic.
	 *
	 * @param object $term Término servido.
	 * @return array{count:int,author:string,brand:string,medium:string}|null
	 *
	 * @since 1.104.0
	 */
	private function series_catalog_snapshot( object $term ): ?array {
		try {
			$medium = $this->series_medium( (int) $term->term_id );
			if ( '' === $medium ) {
				return null;
			}

			$count      = isset( $term->count ) ? max( 0, (int) $term->count ) : 0;
			$author     = '';
			$brand      = '';
			$price_from = '';
			// 0 hasta que los facts digan otra cosa: sin dato NO se afirma que la
			// colección se pueda comprar entera. El degradado seguro es callar.
			$instock    = 0;

			$term_taxonomy_id = isset( $term->term_taxonomy_id ) ? (int) $term->term_taxonomy_id : 0;
			if ( $term_taxonomy_id > 0 ) {
				// Mismo patrón que `SeriesBundles\LandingOffer`: los facts viven
				// en un transient compartido con invalidación propia, así que una
				// instancia efímera lee la misma foto que el bloque visible. El
				// callable es el MISMO gate que recibe la instancia registrada en
				// Plugin.php: con una campaña de descuento vigente el precio no
				// se publica (ni en el renglón, ni en el JSON-LD, ni aquí).
				$facts_service = new SeriesFacts( static fn (): bool => ( new DiscountSettings() )->is_enabled() );
				$facts         = $facts_service->catalog_facts( $term_taxonomy_id );
				if ( is_array( $facts ) ) {
					if ( $facts_service->price_range_is_publishable( $facts ) && (float) $facts['price_min'] > 0 ) {
						$price_from = '$' . number_format( (float) $facts['price_min'], 0, ',', '.' );
					}
					$instock = (int) ( $facts['instock'] ?? 0 );
					if ( (int) ( $facts['tomos'] ?? 0 ) > 0 ) {
						$count = (int) $facts['tomos'];
					}
					$autores = $facts['autores'] ?? array();
					if ( is_array( $autores ) && 1 === count( $autores ) ) {
						$author = trim( (string) ( reset( $autores )['name'] ?? '' ) );
					}
					$editoriales = $facts['editoriales'] ?? array();
					if ( is_array( $editoriales ) && 1 === count( $editoriales ) ) {
						$brand = trim( (string) ( reset( $editoriales )['name'] ?? '' ) );
					}
				}
			}

			return array(
				'count'      => $count,
				'author'     => $author,
				'brand'      => $brand,
				'medium'     => $medium,
				'price_from' => $price_from,
				// Cuántos de esos tomos se pueden comprar HOY. No entra en el
				// texto —el title y la meta viven días en Google y mañana
				// mentirían—, sólo decide si la tienda puede afirmar «completo».
				'instock'    => $instock,
			);
		} catch ( \Throwable $throwable ) {
			return null;
		}
	}

	/**
	 * Sustantivo del medio según la categoría raíz REAL de un producto testigo.
	 *
	 * 7 de las 106 series actuales son cómic, no manga; un «manga» por defecto
	 * publicaría «Invencible manga» en un título. Devuelve '' cuando la raíz no
	 * es reconocible y el caller degrada a la plantilla sin medio.
	 *
	 * @param int $term_id Término de la serie.
	 * @return string 'manga' | 'cómic' | 'manhwa' | ''.
	 *
	 * @since 1.104.0
	 */
	private function series_medium( int $term_id ): string {
		if ( $term_id < 1 ) {
			return '';
		}
		if ( isset( $this->series_medium_memo[ $term_id ] ) ) {
			return $this->series_medium_memo[ $term_id ];
		}

		$cached = $this->cached_series_medium( $term_id );
		if ( '' !== $cached ) {
			$this->series_medium_memo[ $term_id ] = $cached;

			return $cached;
		}

		$medium = $this->resolve_series_medium( $term_id );
		$this->series_medium_memo[ $term_id ] = $medium;
		if ( '' !== $medium && function_exists( 'set_transient' ) ) {
			set_transient( self::SERIES_MEDIUM_TRANSIENT_PREFIX . $term_id, $medium, self::SERIES_MEDIUM_TTL );
		}

		return $medium;
	}

	/**
	 * Lee el medio cacheado, rechazando cualquier valor fuera del vocabulario.
	 *
	 * El transient vive en `wp_options`: un valor corrupto o de otra versión no
	 * puede convertirse en un sustantivo publicado. Se valida contra la MISMA
	 * lista que produce `medium_from_categories()`.
	 *
	 * @param int $term_id Término de la serie.
	 * @return string Medio válido cacheado, o '' si no hay foto utilizable.
	 */
	private function cached_series_medium( int $term_id ): string {
		if ( ! function_exists( 'get_transient' ) ) {
			return '';
		}

		$cached = get_transient( self::SERIES_MEDIUM_TRANSIENT_PREFIX . $term_id );

		return ( is_string( $cached ) && in_array( $cached, self::SERIES_MEDIA, true ) ) ? $cached : '';
	}

	/**
	 * Olvida el medio cacheado de las series de un producto recategorizado.
	 *
	 * Lo único que puede cambiar un medio YA resuelto es que el producto testigo
	 * cambie de categoría raíz. Se engancha sólo a eso: entrar y salir de una
	 * serie no altera el sustantivo del medio, y el TTL de 36 h cierra
	 * cualquier caso residual.
	 *
	 * @internal Callback de `set_object_terms`.
	 *
	 * @param mixed $object_id Objeto cuyos términos cambiaron.
	 * @param mixed $terms     Términos recibidos (no se usan).
	 * @param mixed $tt_ids    Term taxonomy ids (no se usan).
	 * @param mixed $taxonomy  Taxonomía afectada.
	 * @return void
	 */
	public function forget_series_medium( $object_id, $terms = null, $tt_ids = null, $taxonomy = '' ): void {
		unset( $terms, $tt_ids );
		if ( 'product_cat' !== (string) $taxonomy ) {
			return;
		}
		$product_id = (int) $object_id;
		if ( $product_id < 1 || ! function_exists( 'get_the_terms' ) || ! function_exists( 'delete_transient' ) ) {
			return;
		}

		$series = get_the_terms( $product_id, self::SERIES_TAXONOMY );
		if ( ! is_array( $series ) ) {
			return;
		}
		foreach ( $series as $term ) {
			$term_id = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : 0;
			if ( $term_id > 0 ) {
				unset( $this->series_medium_memo[ $term_id ] );
				delete_transient( self::SERIES_MEDIUM_TRANSIENT_PREFIX . $term_id );
			}
		}
	}

	/**
	 * Resuelve el medio consultando el catálogo (el tramo caro).
	 *
	 * @param int $term_id Término de la serie.
	 * @return string 'manga' | 'cómic' | 'manhwa' | ''.
	 */
	private function resolve_series_medium( int $term_id ): string {
		try {
			if ( $term_id < 1 || ! function_exists( 'get_posts' ) || ! function_exists( 'get_the_terms' ) ) {
				return '';
			}

			$ids = get_posts(
				array(
					'post_type'     => 'product',
					'post_status'   => 'publish',
					'numberposts'   => 1,
					'fields'        => 'ids',
					'no_found_rows' => true,
					'tax_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Un solo producto testigo, solo en landings de serie sin meta propia (cacheadas 1 h por página).
						array(
							'taxonomy' => self::SERIES_TAXONOMY,
							'field'    => 'term_id',
							'terms'    => $term_id,
						),
					),
				)
			);
			if ( ! is_array( $ids ) || array() === $ids ) {
				return '';
			}

			$categories = get_the_terms( (int) $ids[0], 'product_cat' );
			if ( ! is_array( $categories ) ) {
				return '';
			}

			return self::medium_from_categories( $categories );
		} catch ( \Throwable $throwable ) {
			return '';
		}
	}

	/**
	 * Sustantivo del medio para UN producto, desde sus propias categorías.
	 *
	 * @param int $product_id Producto publicado.
	 * @return string 'manga' | 'cómic' | 'manhwa' | ''.
	 *
	 * @since 1.105.0
	 */
	private function product_medium( int $product_id ): string {
		try {
			if ( $product_id < 1 || ! function_exists( 'get_the_terms' ) ) {
				return '';
			}
			$categories = get_the_terms( $product_id, 'product_cat' );

			return is_array( $categories ) ? self::medium_from_categories( $categories ) : '';
		} catch ( \Throwable $throwable ) {
			return '';
		}
	}

	/**
	 * Resuelve el medio subiendo cada categoría hasta su raíz real.
	 *
	 * @param array<int,object> $categories Términos `product_cat` del testigo.
	 * @return string 'manga' | 'cómic' | 'manhwa' | ''.
	 *
	 * @since 1.105.0
	 */
	private static function medium_from_categories( array $categories ): string {
		foreach ( $categories as $category ) {
			$guard = 0;
			while ( is_object( $category ) && (int) ( $category->parent ?? 0 ) > 0 && $guard++ < 6 ) {
				$parent = get_term( (int) $category->parent, 'product_cat' );
				if ( ! is_object( $parent ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $parent ) ) ) {
					break;
				}
				$category = $parent;
			}
			$medium = match ( is_object( $category ) ? (string) ( $category->slug ?? '' ) : '' ) {
				'manga'  => 'manga',
				'comics' => 'cómic',
				'manhwa' => 'manhwa',
				default  => '',
			};
			if ( '' !== $medium ) {
				return $medium;
			}
		}

		return '';
	}

	/**
	 * Compone título y descripción estructurados para una landing de serie.
	 *
	 * Selecciona variantes completas de mayor a menor especificidad sin truncar oraciones.
	 *
	 * Regla de verdad central: `obra completa con más tomos que el catálogo`
	 * (Naruto: 72 vs 5) publica los DOS ejes rotulados y jamás una sola cifra
	 * ambigua. «Serie completa en N tomos» exige que el catálogo alcance el
	 * total de la obra.
	 *
	 * @internal Función pura salvo por el sanitizador estándar de WordPress.
	 *
	 * @param string $series_name   Nombre público del término.
	 * @param string $work_state    Estado de la obra original (crudo, se sanitiza acá).
	 * @param int    $work_total    Tomos de la obra original; 0 = desconocido.
	 * @param int    $catalog_count Productos publicados del término.
	 * @param string $author        Autor único de la serie, '' si hay varios o ninguno.
	 * @param string $brand         Editorial única, '' si hay varias o ninguna.
	 * @param string $medium        'manga' | 'cómic' | 'manhwa'.
	 * @param string $price_from    Precio mínimo YA formateado («$9.990») o '' cuando
	 *                              el rango no es publicable (SeriesFacts decide:
	 *                              extremos desconocidos o campaña de descuento).
	 * @return array{title:string,description:string} Piezas vacías si ninguna variante entera cupo.
	 *
	 * @since 1.104.0
	 */
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
		$name = function_exists( 'strip_shortcodes' )
			? (string) strip_shortcodes( $series_name )
			: $series_name;
		$name = trim( wp_strip_all_tags( $name, true ) );
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = (string) str_replace( array( "\xc2\xa0", "\xe2\x80\x8b" ), ' ', $name );
		$name = preg_replace( '/\s+/u', ' ', $name );
		$name = is_string( $name ) ? trim( $name ) : '';

		$empty = array(
			'title'       => '',
			'description' => '',
		);
		if ( '' === $name || ! in_array( $medium, self::SERIES_MEDIA, true ) ) {
			return $empty;
		}

		$state          = PublicationStatus::sanitize_work_state( $work_state );
		$work_total     = max( 0, $work_total );
		$catalog_count  = max( 0, $catalog_count );
		$work_complete  = PublicationStatus::COMPLETE === $state && $work_total > 0;

		// Distinción entre cobertura de catálogo y disponibilidad inmediata para compra:
		// evita prometer "serie completa" si no todos los tomos están disponibles para entrega.
		$work_covered   = $work_complete && $catalog_count >= $work_total;
		$partial_sold   = $work_complete && ! $work_covered;
		$complete_sold  = $work_covered && $fully_buyable;

		// Distinción entre el estado de la obra original y el estado de la edición local:
		// obras con publicación finalizada en origen pueden tener ediciones locales aún en curso
		// o equivalencias de tomos consolidados (ej. ediciones kanzenban o remix).
		$edition_state_clean = PublicationStatus::sanitize_edition_state( $edition_state );
		$edition_closed = PublicationStatus::COMPLETE === $edition_state_clean && $edition_total > 0;
		$edition_covered = $edition_closed && $catalog_count >= $edition_total;
		// Sólo cuando la obra no da ya el título más fuerte, para no competir.
		$edition_sold   = ! $complete_sold && $edition_covered && $fully_buyable;
		$edition_total_label = 1 === $edition_total ? '1 tomo' : sprintf( '%d tomos', $edition_total );
		$critical_state = in_array( $state, array( PublicationStatus::PAUSED, PublicationStatus::CANCELLED ), true );
		$work_total_label = 1 === $work_total ? '1 tomo' : sprintf( '%d tomos', $work_total );

		$title_candidates = self::build_series_title_candidates(
			$name,
			$medium,
			$complete_sold,
			$work_total_label,
			$edition_sold,
			$edition_total_label,
			$partial_sold,
			$catalog_count
		);

		$title = '';
		foreach ( $title_candidates as $candidate ) {
			if ( Text::length( $candidate ) <= 62 ) {
				$title = $candidate;
				break;
			}
		}

		$description_candidates = self::build_series_description_candidates(
			$state,
			$complete_sold,
			$partial_sold,
			$catalog_count,
			$work_total_label,
			$author,
			$name,
			$brand,
			$price_from,
			$critical_state
		);

		$description = '';
		foreach ( $description_candidates as $candidate ) {
			$candidate = trim( (string) preg_replace( '/\s+/u', ' ', $candidate ) );
			if ( Text::length( $candidate ) <= 155 ) {
				$description = $candidate;
				break;
			}
		}

		return array(
			'title'       => $title,
			'description' => $description,
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function build_series_title_candidates(
		string $name,
		string $medium,
		bool $complete_sold,
		string $work_total_label,
		bool $edition_sold,
		string $edition_total_label,
		bool $partial_sold,
		int $catalog_count
	): array {
		$candidates = array();
		if ( $complete_sold ) {
			$candidates[] = sprintf( '%s %s completo: %s | Akibara Chile', $name, $medium, $work_total_label );
			$candidates[] = sprintf( '%s: %s completo en %s | Akibara', $name, $medium, $work_total_label );
			$candidates[] = sprintf( '%s %s completo: %s | Akibara', $name, $medium, $work_total_label );
		} elseif ( $edition_sold ) {
			$candidates[] = sprintf( '%s %s: edición completa en %s | Akibara Chile', $name, $medium, $edition_total_label );
			$candidates[] = sprintf( '%s: edición completa en %s | Akibara Chile', $name, $edition_total_label );
			$candidates[] = sprintf( '%s: edición completa en %s | Akibara', $name, $edition_total_label );
		} elseif ( ! $partial_sold && $catalog_count > 1 ) {
			$candidates[] = sprintf( '%s %s: %d tomos en español | Akibara Chile', $name, $medium, $catalog_count );
			$candidates[] = sprintf( '%s %s en español: %d tomos | Akibara', $name, $medium, $catalog_count );
			$candidates[] = sprintf( '%s %s: %d tomos | Akibara', $name, $medium, $catalog_count );
		}
		$candidates[] = sprintf( '%s %s en español | Comprar en Akibara Chile', $name, $medium );
		$candidates[] = sprintf( '%s %s en español | Akibara Chile', $name, $medium );
		$candidates[] = sprintf( '%s %s | Akibara Chile', $name, $medium );
		$candidates[] = sprintf( '%s | Akibara', $name );

		return $candidates;
	}

	/**
	 * @return array<int, string>
	 */
	private static function build_series_description_candidates(
		string $state,
		bool $complete_sold,
		bool $partial_sold,
		int $catalog_count,
		string $work_total_label,
		string $author,
		string $name,
		string $brand,
		string $price_from,
		bool $critical_state
	): array {
		$state_phrase = match ( $state ) {
			PublicationStatus::COMPLETE  => 'Serie finalizada.',
			PublicationStatus::ONGOING   => 'Serie en publicación.',
			PublicationStatus::PAUSED    => 'Publicación en pausa.',
			PublicationStatus::CANCELLED => 'Serie cancelada.',
			default                      => '',
		};

		if ( $complete_sold ) {
			$core = sprintf( 'serie completa en %s', $work_total_label );
		} elseif ( $partial_sold ) {
			$core = sprintf( 'obra completa en %s', $work_total_label );
		} elseif ( 1 === $catalog_count ) {
			$core = '1 tomo catalogado';
		} elseif ( $catalog_count > 1 ) {
			$core = sprintf( '%d tomos catalogados', $catalog_count );
		} else {
			$core = 'tomos en el catálogo';
		}

		$subject = '' !== $author ? sprintf( '%s de %s', $name, $author ) : $name;

		$closes = array();
		if ( '' !== $price_from ) {
			$closes[] = array(
				'long'  => sprintf( 'Desde %s en Akibara, con despacho a todo Chile.', $price_from ),
				'short' => sprintf( 'Desde %s. Despacho a todo Chile.', $price_from ),
				'local' => sprintf( 'desde %s en Akibara Chile.', $price_from ),
			);
		}
		$closes[] = array(
			'long'  => 'Cómpralo en Akibara con despacho a todo Chile.',
			'short' => 'Despacho a todo Chile.',
			'local' => 'en Akibara Chile.',
		);

		$candidates = array();
		foreach ( $closes as $close ) {
			$long_close  = $close['long'];
			$short_close = $close['short'];
			$local_close = $close['local'];
			if ( $partial_sold ) {
				$catalogued = 1 === $catalog_count
					? '1 tomo catalogado'
					: ( $catalog_count > 1 ? sprintf( '%d tomos catalogados', $catalog_count ) : 'Tomos en el catálogo' );
				if ( '' !== $brand ) {
					$candidates[] = sprintf( '%s: %s. %s de la edición %s %s', $subject, $core, ucfirst( $catalogued ), $brand, $local_close );
				}
				$candidates[] = sprintf( '%s: %s. %s %s', $subject, $core, ucfirst( $catalogued ), $local_close );
				$candidates[] = sprintf( '%s: %s. %s en Akibara.', $name, $core, ucfirst( $catalogued ) );
			} elseif ( $critical_state ) {
				if ( '' !== $brand ) {
					$candidates[] = sprintf( '%s: %s de la edición %s. %s %s', $subject, $core, $brand, $state_phrase, $long_close );
				}
				$candidates[] = sprintf( '%s: %s en español. %s %s', $subject, $core, $state_phrase, $long_close );
				$candidates[] = sprintf( '%s: %s. %s %s', $subject, $core, $state_phrase, $short_close );
				$candidates[] = sprintf( '%s: %s en español. %s %s', $name, $core, $state_phrase, $short_close );
			} else {
				if ( '' !== $brand ) {
					$candidates[] = sprintf( '%s: %s de la edición %s en español. %s', $subject, $core, $brand, $long_close );
					$candidates[] = sprintf( '%s: %s, edición %s. %s', $subject, $core, $brand, $long_close );
					$candidates[] = sprintf( '%s: %s, edición %s. %s', $subject, $core, $brand, $short_close );
				}
				$candidates[] = trim( sprintf( '%s: %s en español. %s %s', $subject, $core, $state_phrase, $long_close ) );
				$candidates[] = sprintf( '%s: %s en español. %s', $subject, $core, $long_close );
				$candidates[] = sprintf( '%s: %s en español. %s', $subject, $core, $short_close );
				$candidates[] = sprintf( '%s: %s en español. %s', $name, $core, $short_close );
			}
		}

		return $candidates;
	}

	/**
	 * Compone una variante paginada con frases enteras de la plantilla de serie.
	 *
	 * @param string $base  Descripción base sin terminador final.
	 * @param string $label Marcador localizado de paginación.
	 */
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

	/**
	 * Devuelve el marcador localizado de la página de archive actual.
	 */
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

	/**
	 * Memo por request del meta compuesto de la ficha servida.
	 *
	 * @var array<int,array{title:string,description:string}|null>
	 */
	private array $product_meta_memo = array();

	/**
	 * Título rico para fichas de producto sin `rank_math_title` propio.
	 *
	 * La plantilla «%title% | Comprar en Chile - Akibara» no dice «manga»,
	 * «español» ni la editorial — lo que desambigua la SERP chilena. Mismo
	 * contrato que las series: el meta manual (171 fichas) siempre gana y la
	 * paginación de la prioridad 99 sufija después.
	 *
	 * @param string $title Título que Rank Math iba a emitir.
	 * @return string
	 *
	 * @since 1.105.0
	 */
	public function prefer_product_title( string $title ): string {
		if ( ! function_exists( 'is_singular' ) || is_admin() || ! is_singular( 'product' ) ) {
			return $title;
		}

		$post_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		if ( $post_id < 1 ) {
			return $title;
		}

		$custom_title = get_post_meta( $post_id, 'rank_math_title', true );
		if ( '' !== trim( (string) $custom_title ) ) {
			return $title;
		}

		$meta = $this->generated_product_meta( $post_id );

		return ( null !== $meta && '' !== $meta['title'] ) ? $meta['title'] : $title;
	}

	/**
	 * Compone (una vez por request) el meta rico de la ficha servida.
	 *
	 * @param int $post_id Producto consultado.
	 * @return array{title:string,description:string}|null Null si el catálogo
	 *                                                     no sostiene la variante rica.
	 *
	 * @since 1.105.0
	 */
	/**
	 * Estado de encargo del producto con el VOCABULARIO de la separación
	 * 1.109: Availability decide preventa real; el resto del backorder es
	 * «a pedido». Fallar hacia a_pedido (la verdad del 99% del catálogo).
	 *
	 * @param int $post_id Producto.
	 * @return string '' | 'a_pedido' | 'preventa'.
	 */
	private static function encargo_state( int $post_id ): string {
		if ( \Akibara\Business\Modules\Stock\StockStatus::ON_BACKORDER !== (string) get_post_meta( $post_id, '_stock_status', true ) ) {
			return '';
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product instanceof \WC_Product
				&& \Akibara\Business\Modules\Stock\Availability::is_preventa( $product ) ) {
				return 'preventa';
			}
		}

		return 'a_pedido';
	}

	private function generated_product_meta( int $post_id ): ?array {
		if ( $post_id < 1 ) {
			return null;
		}
		if ( array_key_exists( $post_id, $this->product_meta_memo ) ) {
			return $this->product_meta_memo[ $post_id ];
		}

		$meta = null;
		try {
			$medium = $this->product_medium( $post_id );
			$post   = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
			$name   = is_object( $post ) && isset( $post->post_title ) ? (string) $post->post_title : '';
			if ( '' !== $medium && '' !== $name ) {
				$composed = self::compose_product_meta(
					$name,
					self::single_term_name( $post_id, 'pa_serie', true ),
					self::single_term_name( $post_id, 'pa_autor' ),
					self::single_term_name( $post_id, 'product_brand' ),
					$medium,
					VolumeTitle::extract_number( $name ),
					self::encargo_state( $post_id )
				);
				if ( '' !== $composed['title'] || '' !== $composed['description'] ) {
					$meta = $composed;
				}
			}
		} catch ( \Throwable $throwable ) {
			$meta = null;
		}

		$this->product_meta_memo[ $post_id ] = $meta;

		return $meta;
	}

	/**
	 * Nombre del término asignado, solo cuando el dato es inequívoco.
	 *
	 * Autor y editorial exigen EXACTAMENTE uno (afirmar el primero de varios
	 * sería falso); la serie admite tomar la primera porque el pipeline asigna
	 * una sola por producto.
	 *
	 * @param int    $post_id     Producto.
	 * @param string $taxonomy    Taxonomía a leer.
	 * @param bool   $allow_first Tomar el primero aunque haya varios.
	 *
	 * @since 1.105.0
	 */
	private static function single_term_name( int $post_id, string $taxonomy, bool $allow_first = false ): string {
		if ( ! function_exists( 'get_the_terms' ) ) {
			return '';
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return '';
		}
		if ( ! $allow_first && 1 !== count( $terms ) ) {
			return '';
		}
		$first = reset( $terms );

		return is_object( $first ) && isset( $first->name ) ? trim( (string) $first->name ) : '';
	}

	/**
	 * Compone título y descripción ricos de una ficha de producto.
	 *
	 * Mismo diseño validado de `compose_series_meta`: variantes enteras de más
	 * a menos específicas, nunca una frase cortada, y NINGÚN dato volátil — el
	 * precio y el estado de stock quedan fuera a propósito; la única excepción
	 * es la preventa, que es un estado editorial estable hasta el lanzamiento
	 * y la ficha no se cachea como página (gate de precios), así que la meta
	 * sale fresca en cada render.
	 *
	 * @internal Función pura salvo por el sanitizador estándar de WordPress.
	 *
	 * @param string   $product_title Título del producto (termina en nº de tomo por convención).
	 * @param string   $serie_name    Serie del producto, '' si no tiene.
	 * @param string   $author        Autor único, '' si hay varios o ninguno.
	 * @param string   $brand         Editorial única, '' si hay varias o ninguna.
	 * @param string   $medium        'manga' | 'cómic' | 'manhwa'.
	 * @param int|null $volume        Nº de tomo extraído del título, null si no hay.
	 * @param string   $encargo       '' en stock · 'a_pedido' · 'preventa'. El estado
	 *                                comercial lo determina Availability, distinguiendo
	 *                                entre pedidos de importación y preventas reales.
	 * @return array{title:string,description:string} Piezas vacías si ninguna variante entera cupo.
	 *
	 * @since 1.105.0
	 */
	public static function compose_product_meta(
		string $product_title,
		string $serie_name,
		string $author,
		string $brand,
		string $medium,
		?int $volume,
		string $encargo = ''
	): array {
		$name = trim( wp_strip_all_tags( $product_title, true ) );
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = (string) preg_replace( '/\s+/u', ' ', $name );
		$name = trim( $name );

		$empty = array(
			'title'       => '',
			'description' => '',
		);
		if ( '' === $name || ! in_array( $medium, array( 'manga', 'cómic', 'manhwa' ), true ) ) {
			return $empty;
		}

		// ── Título ────────────────────────────────────────────────────────
		$title_candidates = array();
		// La editorial se incluye en el título cuando el espacio lo permite,
		// desambiguando ediciones y procedencias editoriales.
		if ( '' !== $brand ) {
			$title_candidates[] = sprintf( '%s · %s · %s en español | Akibara', $name, $brand, $medium );
		}
		$title_candidates = array_merge(
			$title_candidates,
			array(
				sprintf( '%s %s en español | Akibara Chile', $name, $medium ),
			sprintf( '%s %s en español | Akibara', $name, $medium ),
			sprintf( '%s %s | Akibara Chile', $name, $medium ),
			sprintf( '%s | Comprar en Chile - Akibara', $name ),
				sprintf( '%s | Akibara', $name ),
			)
		);
		if ( 'preventa' === $encargo ) {
			array_unshift(
				$title_candidates,
				sprintf( '%s en preventa | %s en español | Akibara', $name, ucfirst( $medium ) )
			);
		} elseif ( 'a_pedido' === $encargo ) {
			array_unshift(
				$title_candidates,
				sprintf( '%s a pedido | %s en español | Akibara', $name, ucfirst( $medium ) )
			);
		}

		$title = '';
		foreach ( $title_candidates as $candidate ) {
			if ( Text::length( $candidate ) <= 62 ) {
				$title = $candidate;
				break;
			}
		}

		// ── Descripción ───────────────────────────────────────────────────
		$serie_clause = ( '' !== $serie_name && null !== $volume )
			? sprintf( 'Tomo %d de la serie %s.', $volume, $serie_name )
			: '';
		if ( 'preventa' === $encargo ) {
			$close       = 'Resérvalo en preventa en Akibara con despacho a todo Chile.';
			$short_close = 'Resérvalo en Akibara. Despacho a todo Chile.';
		} elseif ( 'a_pedido' === $encargo ) {
			$close       = 'Pídelo a pedido en Akibara con despacho a todo Chile.';
			$short_close = 'Pídelo en Akibara. Despacho a todo Chile.';
		} else {
			$close       = 'Cómpralo en Akibara con despacho a todo Chile.';
			$short_close = 'Despacho a todo Chile desde Akibara.';
		}

		$description_candidates = array();
		if ( '' !== $author && '' !== $brand ) {
			$description_candidates[] = trim( sprintf( '%s de %s, edición %s en español. %s %s', $name, $author, $brand, $serie_clause, $close ) );
			$description_candidates[] = trim( sprintf( '%s de %s, edición %s en español. %s', $name, $author, $brand, $close ) );
		}
		if ( '' !== $brand ) {
			$description_candidates[] = trim( sprintf( '%s, edición %s en español. %s %s', $name, $brand, $serie_clause, $close ) );
			$description_candidates[] = trim( sprintf( '%s, edición %s en español. %s', $name, $brand, $close ) );
		}
		if ( '' !== $author ) {
			$description_candidates[] = trim( sprintf( '%s de %s en español. %s %s', $name, $author, $serie_clause, $close ) );
		}
		$description_candidates[] = trim( sprintf( '%s %s en español. %s %s', $name, $medium, $serie_clause, $close ) );
		$description_candidates[] = trim( sprintf( '%s %s en español. %s', $name, $medium, $close ) );
		$description_candidates[] = trim( sprintf( '%s %s en español. %s', $name, $medium, $short_close ) );

		$description = '';
		foreach ( $description_candidates as $candidate ) {
			$candidate = trim( (string) preg_replace( '/\s+/u', ' ', $candidate ) );
			if ( Text::length( $candidate ) <= 155 ) {
				$description = $candidate;
				break;
			}
		}

		return array(
			'title'       => $title,
			'description' => $description,
		);
	}

	/**
	 * Detecta Rank Math en runtime o usa el valor inyectado en pruebas.
	 */
	private function is_rank_math_active(): bool {
		return $this->rank_math_active ?? defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * Detecta si Rank Math sustituirá efectivamente el BreadcrumbList nativo.
	 */
	private function are_rank_math_breadcrumbs_enabled(): bool {
		if ( null !== $this->rank_math_breadcrumbs_enabled ) {
			return $this->rank_math_breadcrumbs_enabled;
		}

		return class_exists( '\RankMath\Helper' )
			&& method_exists( '\RankMath\Helper', 'is_breadcrumbs_enabled' )
			&& (bool) \RankMath\Helper::is_breadcrumbs_enabled();
	}

	/**
	 * Rank Math puede estar desactivado durante un despliegue o una prueba.
	 */
	private function can_use_rank_math_api(): bool {
		return (
			null !== $this->rank_math_settings_reader
			&& null !== $this->rank_math_variable_replacer
		) || class_exists( '\RankMath\Helper' );
	}

	/**
	 * Lee la opción desde Rank Math o desde el adaptador de pruebas.
	 *
	 * @return mixed
	 */
	private function rank_math_setting( string $key ) {
		if ( null !== $this->rank_math_settings_reader ) {
			return ( $this->rank_math_settings_reader )( $key );
		}

		return \RankMath\Helper::get_settings( $key );
	}

	/**
	 * Expande variables con el contexto del producto.
	 *
	 * @param object $post Producto consultado como WP_Post.
	 * @return mixed
	 */
	private function rank_math_replace_vars( string $template, object $post ) {
		if ( null !== $this->rank_math_variable_replacer ) {
			return ( $this->rank_math_variable_replacer )( $template, $post );
		}

		return \RankMath\Helper::replace_vars( $template, $post );
	}

}
