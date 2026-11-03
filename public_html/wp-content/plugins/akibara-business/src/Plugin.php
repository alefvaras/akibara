<?php
/**
 * Main plugin class.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business;

use Akibara\Business\Contracts\CheckoutDataProvider;
use Akibara\Business\Internal\UpgradeManager;
use Akibara\Business\Internal\UpgradeRetryRequired;
use Akibara\Business\Integrations\Bluex\Pudo\PudoCheckoutService;
use Akibara\Business\Modules\AddressAutocomplete\AddressAutocomplete;
use Akibara\Business\Modules\AccountCollection\AccountCollection;
use Akibara\Business\Modules\AccountCollection\CollectionBadges;
use Akibara\Business\Modules\AccountCollection\CollectionResolver;
use Akibara\Business\Modules\AccountCollection\ContinuityResolver;
use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use Akibara\Business\Modules\DiscountCopy\DiscountCopy;
use Akibara\Business\Modules\ShippingCopy\ShippingCopy;
use Akibara\Business\Modules\BrandsDirectory\BrandLandings;
use Akibara\Business\Modules\BrandsDirectory\BrandsDirectory;
use Akibara\Business\Modules\BrandsDirectory\EditionFacts;
use Akibara\Business\Modules\PopularProducts\PopularProducts;
use Akibara\Business\Modules\ProductFaq\ProductFaq;
use Akibara\Business\Modules\Blog\Blog;
use Akibara\Business\Modules\CheckoutTaxLabels\CheckoutTaxLabels;
use Akibara\Business\Modules\EstimatedDelivery\EstimatedDelivery;
use Akibara\Business\Modules\EstimatedDelivery\Migration as EdaMigration;
use Akibara\Business\Modules\EstimatedDelivery\MarcaTermFields;
use Akibara\Business\Modules\AgentReadiness\MarkdownNegotiation;
use Akibara\Business\Modules\ScriptDiagnostics\CrossOriginScripts;
use Akibara\Business\Modules\EstimatedDelivery\PaisTermFields;
use Akibara\Business\Modules\Forms\Forms;
use Akibara\Business\Modules\FrontendCompatibility\FrontendCompatibility;
use Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress;
use Akibara\Business\Modules\HomeDestacados\HomeDestacados;
use Akibara\Business\Modules\HomeSeriesDestacadas\HomeSeriesDestacadas;
use Akibara\Business\Modules\HomeHero\HomeHero;
use Akibara\Business\Modules\LlmsManifest\LlmsManifest;
use Akibara\Business\Modules\SeoEnhancements\AuthorIntro;
use Akibara\Business\Modules\SeoEnhancements\SeoEnhancements;
use Akibara\Business\Modules\ProductSchemaShopping\AdultContentFeed;
use Akibara\Business\Modules\ProductSchemaShopping\ProductSchemaShopping;
use Akibara\Business\Modules\GoogleLoginButton\GoogleLoginButton;
use Akibara\Business\Modules\GuestOrderLinking\GuestOrderLinking;
use Akibara\Business\Modules\PickupCoordination\PickupCoordination;
use Akibara\Business\Modules\CatalogOrder\CatalogOrder;
use Akibara\Business\Modules\LoopEnhancer\LoopEnhancer;
use Akibara\Business\Modules\EmailBrand\EmailBrand;
use Akibara\Business\Modules\MailFrom\MailFrom;
use Akibara\Business\Modules\SeoSocial\SeoSocial;
use Akibara\Business\Modules\SeoSocial\SocialImageDerivatives;
use Akibara\Business\Modules\SchemaProductRankMath\BookSchemaEnricher;
use Akibara\Business\Modules\SchemaProductRankMath\SchemaProductRankMath;
use Akibara\Business\Modules\SeoIndexing\FilteredUrlsPolicy;
use Akibara\Business\Modules\SeoIndexing\ProductSitemapStableOrder;
use Akibara\Business\Modules\SeoIndexing\RankMathAdminUi;
use Akibara\Business\Modules\SeoIndexing\SeriesPaginationPolicy;
use Akibara\Business\Modules\MarketingConsent\MarketingConsent;
use Akibara\Business\Modules\MyAccountUx\MyAccountUx;
use Akibara\Business\Modules\OrderPreventa\OrderPreventa;
use Akibara\Business\Modules\OrderTimeline\OrderTimeline;
use Akibara\Business\Modules\PhoneValidation\PhoneValidation;
use Akibara\Business\Modules\PreventasRoute\PreventasRoute;
use Akibara\Business\Modules\DisponiblesRoute\DisponiblesRoute;
use Akibara\Business\Modules\RutValidation\RutValidation;
use Akibara\Business\Modules\RelatedGuides\GuideSeriesCard;
use Akibara\Business\Modules\RelatedGuides\SeriesRelatedGuide;
use Akibara\Business\Modules\Security\Security;
use Akibara\Business\Modules\BackInStock\BackInStock;
use Akibara\Business\Modules\SeriesBundles\SeriesBundles;
use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Akibara\Business\Modules\SeriesCatalog\LandingPageSize;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesDirectory\SeriesIndex;
use Akibara\Business\Modules\SeriesDirectory\Shortcode as SeriesDirectoryShortcode;
use Akibara\Business\Modules\SeriesFollow\SeriesFollow;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\SeriesFacts\PublicationStatusFields;
use Akibara\Business\Modules\SeriesFacts\SeriesFacts;
use Akibara\Business\Modules\SeriesSchema\SchemaTypeFields;
use Akibara\Business\Modules\SeriesSchema\SeriesSchema;
use Akibara\Business\Modules\SeriesDirectory\SeriesDirectory;
use Akibara\Business\Modules\SeriesEditions\SeriesEditions;
use Akibara\Business\Modules\CartQuantity\CartQuantityCeiling;
use Akibara\Business\Modules\NativeCart\NativeCart;
use Akibara\Business\Modules\NativeCart\RejectionCopy;
use Akibara\Business\Modules\NativeCart\SmartCartRecommender;
use Akibara\Business\Modules\TaxonomyDiscount\TaxonomyDiscount;
use Akibara\Business\Modules\UpsellsAutofill\UpsellsAutofill;
use Akibara\Business\Modules\Recommendations\Recommendations;
use Akibara\Business\Modules\Recommendations\Settings as RecommendationSettings;
use Akibara\Business\Modules\QuickView\QuickView;
use Akibara\Business\Modules\WelcomeCoupon\WelcomeCoupon;
use Akibara\Business\Modules\Wishlist\Wishlist;
use Akibara\Business\Modules\ThemeCustomizer\ThemeCustomizer;
use Akibara\Business\Modules\ImageQuality\ImageQuality;
use Akibara\Business\Modules\ImageQuality\Recompressor;
use Akibara\Business\Modules\SiteSpeed\AnalyticsAfterLoad;
use Akibara\Business\Modules\SiteSpeed\CacheWarmer;
use Akibara\Business\Modules\SiteSpeed\EdgePurge;
use Akibara\Business\Modules\SiteSpeed\LeanCart;
use Akibara\Business\Modules\SiteSpeed\SeriesCacheTags;
use Akibara\Business\Modules\SiteSpeed\SiteSpeed;
use Akibara\Business\Modules\CopyManager\CopyManager;
use Akibara\Business\Modules\PdpEnhancer\PdpEnhancer;
use Akibara\Business\Modules\PdpEnhancer\PdpSeriesEnhancer;
use Akibara\Business\Modules\VolumeVariant\ProductDataPanel as VolumeVariantPanel;
use Akibara\Business\Services\CheckoutDataService;
use Akibara\Business\Utilities\RateLimit;
use Akibara\Business\Integrations\OrderPreventaIntegrations;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton bootstrap. Registra todos los módulos del plugin.
 *
 * Cada módulo debe implementar un método `register(): void` que conecte sus
 * hooks. El Plugin solo orquesta — la lógica vive en cada módulo.
 *
 * @since 1.0.0
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Booted flag para idempotencia.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Modules registrados.
	 *
	 * @var array<string, object>
	 */
	private array $modules = array();

	/**
	 * Stable public checkout contract.
	 *
	 * @var CheckoutDataProvider|null
	 */
	private ?CheckoutDataProvider $checkout_data = null;

	/**
	 * Public first-party PUDO domain service for Blocks adapters.
	 *
	 * @var PudoCheckoutService|null
	 */
	private ?PudoCheckoutService $pudo_checkout = null;

	/**
	 * Gestor de upgrades y migraciones de versión.
	 *
	 * @var UpgradeManager|null
	 */
	private ?UpgradeManager $upgrade_manager = null;

	/**
	 * Private constructor — usar instance().
	 */
	private function __construct() {}

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constantes del subsistema de upgrades delegadas a UpgradeManager.
	 */
	public const OPTION_VERSION_INSTALLED              = UpgradeManager::OPTION_VERSION_INSTALLED;
	public const SEARCH_FEED_CONTRACT_VERSION          = UpgradeManager::SEARCH_FEED_CONTRACT_VERSION;
	public const SEARCH_FEED_CONTRACT_ADOPTION_VERSION = UpgradeManager::SEARCH_FEED_CONTRACT_ADOPTION_VERSION;
	public const OPTION_SEARCH_FEED_CONTRACT_VERSION   = UpgradeManager::OPTION_SEARCH_FEED_CONTRACT_VERSION;
	public const OPTION_UPGRADE_TASKS_PENDING          = UpgradeManager::OPTION_UPGRADE_TASKS_PENDING;
	public const UPGRADE_TASK_LEASE_TTL                = UpgradeManager::UPGRADE_TASK_LEASE_TTL;

	/**
	 * Inicializa todos los módulos. Idempotente.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->register_modules();

		// Detección automática de upgrades y reconciliación de contrato con Search
		// en init:999 (ejecutado por WordPress core en todas las peticiones: web, admin,
		// REST, ajax y CLI), asegurando una sola ejecución por petición.
		add_action( 'init', array( $this, 'maybe_upgrade' ), 999 );

		// Limpieza de opciones huérfanas propias al completarse un upgrade.
		add_action( 'akibara_business_upgraded', array( $this, 'cleanup_orphan_options' ) );

		/**
		 * Fires después de que Akibara Business inicializó todos sus módulos.
		 *
		 * Permite a integraciones tardías hookearse al ciclo del plugin.
		 *
		 * @since 1.0.0
		 *
		 * @param Plugin $plugin Instancia singleton.
		 */
		do_action( 'akibara_business_booted', $this );
	}

	/**
	 * Acceso perezoso al gestor de upgrades.
	 *
	 * @return UpgradeManager
	 */
	public function upgrade_manager(): UpgradeManager {
		if ( null === $this->upgrade_manager ) {
			$this->upgrade_manager = new UpgradeManager();
		}
		return $this->upgrade_manager;
	}

	/**
	 * Reconcilia el contrato de Search y ejecuta los upgrades de Business bajo lease.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$this->upgrade_manager()->maybe_upgrade();
	}

	/**
	 * Ejecuta las tareas del bump: flush de rewrites + hook público.
	 *
	 * @param string $current Nueva version (AKIBARA_BUSINESS_VERSION).
	 * @param string $stored  Version previa ('' en fresh install).
	 * @return void
	 */
	public function run_upgrade_tasks( string $current, string $stored ): void {
		flush_rewrite_rules( true );

		/**
		 * Fires después de auto-flush al detectar upgrade del plugin.
		 *
		 * @since 1.16.2
		 *
		 * @param string $current_version Nueva version (AKIBARA_BUSINESS_VERSION).
		 * @param string $previous_version Version stored (puede ser '' en fresh install).
		 */
		do_action( 'akibara_business_upgraded', $current, $stored );
	}

	/**
	 * Purgar caches después de un write directo sobre wp_options.
	 *
	 * @param string $option_name Nombre de la opción a purgar.
	 * @return void
	 */
	public function purge_option_cache( string $option_name ): void {
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Registra los módulos activos. Cada módulo debe tener `register()`.
	 *
	 * @return void
	 */
	/**
	 * Opciones que el front lee y que pueden no viajar en `alloptions`: las NO
	 * autoload y los copys que el dueño nunca guardó (sin fila).
	 *
	 * Son las únicas consultas SQL que Business mete en un request de visitante,
	 * y se pagaban una por una: cada `get_option()` de una option que no viaja en
	 * `alloptions` —y que, en varios casos, nunca se ha escrito— es un SELECT por
	 * nombre. Se calientan todas juntas antes de registrar los módulos, que es
	 * cuando empiezan a leerse (`plugins_loaded`, muy antes del `init:999` donde
	 * `maybe_upgrade()` hace lo propio con las suyas). Una que ya viaja en
	 * `alloptions` no cuesta nada: `wp_prime_option_caches()` la salta.
	 *
	 * El criterio es uno solo: todo copy de `akb_copy_*` que se pinta en una
	 * página de visitante —del tema (`inc/site-copy.php`) o de un módulo—, se lea
	 * en cada página o sólo en una. Quedan fuera los de la cuenta, el correo y la
	 * página de gracias, que no son del visitante anónimo. `FrontOptionPrimingTest`
	 * lo comprueba en los dos sentidos para el tema y el formulario de contacto.
	 *
	 * NO se convierten en autoload a propósito: tres son interruptores que el
	 * dueño mueve desde el panel y tienen que surtir efecto en la petición
	 * siguiente, y ninguna necesita viajar en `alloptions` de todas las páginas
	 * del admin.
	 *
	 * @var array<int, string>
	 */
	private const FRONT_READ_OPTIONS = array(
		'akb_bis_db_version',              // BackInStock\Install::maybe_install()
		'akb_sf_db_version',               // SeriesFollow\Install::maybe_install()
		'akb_bis_capture_enabled',         // BackInStock\CaptureForm::should_render()
		'akb_preventa_t7_enabled',         // OrderPreventa\RemindersCron::maybe_schedule()
		'akb_brand_color_primary',         // ThemeCustomizer::collect_overrides()
		'akb_brand_color_surface',         // ThemeCustomizer::collect_overrides()
		'akb_crossorigin_scripts_enabled', // ScriptDiagnostics\CrossOriginScripts::mark_third_party()
		'akb_analytics_after_load',        // SiteSpeed\AnalyticsAfterLoad (etiquetas de GA4 y Clarity)
		'akb_lean_cart',                   // SiteSpeed\LeanCart (/carrito/: lo que sólo usa la página de pago)
		'akb_wishlist_guest_prompt',       // Wishlist\GuestPrompt (aviso del corazón sin sesión)
		'akb_copy_wl_guest_title',         // Wishlist\GuestPrompt::labels()
		'akb_copy_wl_guest_body',          // Wishlist\GuestPrompt::labels()
		'akb_copy_wl_guest_cta',           // Wishlist\GuestPrompt::labels()
		'akb_copy_wl_guest_dismiss',       // Wishlist\GuestPrompt::labels()
		'akb_reco_relacionados',           // Recommendations\RelatedProducts (ficha)
		'akb_reco_serie_agotada',          // Recommendations\UpsellsDisplay (ficha)
		'akb_reco_mas_vendidos',           // PopularProducts (estados vacíos)
		'akb_reco_mas_vendidos_busqueda',  // Recommendations\Settings (tema: más vendidos en la búsqueda sin resultados)
		'akb_copy_reco_upsells_heading',   // Recommendations\Headings (ficha)
		'akb_copy_reco_related_heading',   // Recommendations\Headings (ficha)
		'akb_copy_series_summary_link',    // SeriesFacts::render_summary() (landing de serie)
		'akb_copy_series_latest_volume',   // SeriesFacts::result_count_extra() (landing de serie)
		'akb_copy_series_offer_hook',      // SeriesBundles\LandingOffer (landing de serie)
		'akb_copy_series_offer_rules',     // SeriesBundles\LandingOffer (landing de serie)
		'akb_copy_pack_error',             // SeriesBundles\SelectionCart::error_text() (ficha con «Arma tu pack» y landing de serie con «Elegir tomos»: texto de un fallo sin respuesta)
		'akb_series_landing_per_page',     // SeriesCatalog\LandingPageSize::per_page() (landing de serie, loop_shop_per_page)
		'akb_copy_eta_bar',                // EstimatedDelivery\BannerRenderer::linea_corta() (barra de la ficha)
		'akb_copy_quick_view_label',       // QuickView::label() (cada tarjeta del catálogo)
		'akb_copy_pdp_series_link',        // PdpEnhancer\PdpSeriesEnhancer::series_link_label() (barra de serie de cada ficha)
		'akb_copy_series_editions_label',  // SeriesEditions (cabecera de las series con otras ediciones)
		'akb_copy_search_placeholder',     // Tema: pista del buscador de la cabecera (todas las páginas)
		'akb_copy_guide_cta_text',         // Tema: cierre de las entradas del blog
		'akb_copy_guide_cta_shop',         // Tema: cierre de las entradas del blog
		'akb_copy_guide_cta_series',       // Tema: cierre de una guía ligada a una serie
		'akb_copy_guide_card_kicker',      // Tema: tarjeta de la serie de una guía
		'akb_copy_guide_card_cta',         // Tema: tarjeta de la serie de una guía
		// Copys de frontend precargados para evitar consultas individuales N+1.
		'akb_copy_search_empty_popular',   // Tema: título de más vendidos en los estados vacíos (búsqueda sin resultados, listado vacío)
		'akb_copy_form_value_missing',     // Forms\Settings::messages() (formulario de /contacto/)
		'akb_copy_form_type_mismatch',     // Forms\Settings::messages() (formulario de /contacto/)
		'akb_copy_form_too_long',          // Forms\Settings::messages() (formulario de /contacto/)
		'akb_copy_promise_installments',   // Tema: promesa de cuotas (CheckoutTrustSignals::OPTION_INSTALLMENTS)
		'akb_copy_footer_support_hours',   // Tema: pie de todas las páginas
		'akb_copy_footer_phone',           // Tema: pie de todas las páginas
		'akb_copy_cta_preventa',           // OrderPreventa\PreventaUX::cta_de() (botón de las tarjetas de preventa)
		'akb_copy_hero_label',             // HomeHero\Renderer::label() (nombre del carrusel de la portada)
		'akb_welcome_coupon_enabled',      // WelcomeCoupon\Settings::is_enabled()
		'akb_welcome_coupon_pct',          // WelcomeCoupon\Settings::discount_pct()
		'akb_welcome_coupon_min_amount',   // WelcomeCoupon\Settings::min_amount()
		'akb_welcome_coupon_validity_days', // WelcomeCoupon\Settings::validity_days()
		'akb_welcome_coupon_delay_seconds', // WelcomeCoupon\Settings::delay_seconds()
		'akb_welcome_coupon_scroll_pct',   // WelcomeCoupon\Settings::scroll_pct()
		'akb_welcome_coupon_dismiss_days', // WelcomeCoupon\Settings::dismiss_days()
		'akb_welcome_coupon_exit_intent',  // WelcomeCoupon\Settings::exit_intent_enabled()
		'akb_welcome_coupon_launcher',     // WelcomeCoupon\Settings::launcher_enabled()
		'akb_welcome_coupon_show_home',    // WelcomeCoupon\Settings::show_on_home()
		'akb_welcome_coupon_show_catalog', // WelcomeCoupon\Settings::show_on_catalog()
		'akb_welcome_coupon_show_product', // WelcomeCoupon\Settings::show_on_product()
		'akb_welcome_coupon_show_series',  // WelcomeCoupon\Settings::show_on_series()
		'akb_welcome_turnstile_enabled',   // WelcomeCoupon\Settings::turnstile_enabled()
		'akb_welcome_turnstile_sitekey',   // WelcomeCoupon\Settings::turnstile_sitekey()
		'akb_welcome_promo_enabled',       // WelcomeCoupon\Settings::is_promo_enabled()
		'akb_welcome_promo_code',          // WelcomeCoupon\Settings::promo_code()
		'akb_welcome_promo_audience',      // WelcomeCoupon\Settings::promo_audience()
		'akb_welcome_promo_delay_seconds', // WelcomeCoupon\Settings::promo_delay_seconds()
		'akb_welcome_promo_dismiss_days',  // WelcomeCoupon\Settings::promo_dismiss_days()
		'akb_welcome_promo_show_home',     // WelcomeCoupon\Settings::promo_show_on_home()
		'akb_welcome_promo_show_catalog',  // WelcomeCoupon\Settings::promo_show_on_catalog()
		'akb_welcome_promo_show_product',  // WelcomeCoupon\Settings::promo_show_on_product()
		'akb_welcome_promo_show_series',   // WelcomeCoupon\Settings::promo_show_on_series()
		'akb_welcome_coupon_cookie_epoch', // WelcomeCoupon\Settings::cookie_epoch()
		'akb_copy_welcome_kicker',         // WelcomeCoupon\Settings::copies()
		'akb_copy_welcome_heading',        // WelcomeCoupon\Settings::copies()
		'akb_copy_welcome_subtitle',       // WelcomeCoupon\Settings::copies()
		'akb_copy_welcome_cta',            // WelcomeCoupon\Settings::copies()
		'akb_copy_welcome_dismiss',        // WelcomeCoupon\Settings::copies()
		'akb_copy_welcome_trust_note',     // WelcomeCoupon\Settings::copies()
		'akb_copy_welcome_legal',          // WelcomeCoupon\Settings::copies()
		'akb_copy_welcome_promo_kicker',   // WelcomeCoupon\Settings::promo_copies()
		'akb_copy_welcome_promo_heading',  // WelcomeCoupon\Settings::promo_copies()
		'akb_copy_welcome_promo_subtitle', // WelcomeCoupon\Settings::promo_copies()
		'akb_copy_welcome_promo_cta',      // WelcomeCoupon\Settings::promo_copies()
		'akb_copy_welcome_promo_legal',    // WelcomeCoupon\Settings::promo_copies()
		'akibara_announcement_rotate',     // AnnouncementBar::render()
		'akibara_announcement_rotate_secs', // AnnouncementBar::render()
		'akb_copy_eta_single_pedido',      // Stock\Availability::get_product_availability()
		'akb_copy_eta_single_preventa',    // Stock\Availability::get_product_availability()
		'akibara_upsells_enabled',         // UpsellsAutofill::register()
		'akb_pa_serie_sitemap_pending_changes', // SeoIndexing\SeriesSitemapLastmod::maybe_reconcile()
		'akb_pa_serie_renderer_state',     // SeoIndexing\SeriesSitemapLastmod::maybe_reconcile()
		'akb_home_destacados_generation',  // HomeDestacados::cache_generation()
		'akibara_blocks_shipping_hint_text', // NativeCart\Settings::OPTION_BLOCKS_SHIPPING_HINT
		'akibara_blocks_collection_notice_text', // NativeCart\Settings::OPTION_BLOCKS_COLLECTION_NOTICE
		// + BrandLandings::front_read_options(): las etiquetas de familia del menú (por familia × campo).
	);

	private function register_modules(): void {
		if ( function_exists( 'wp_prime_option_caches' ) ) {
			wp_prime_option_caches( array_merge( self::FRONT_READ_OPTIONS, BrandLandings::front_read_options() ) );
		}

		// Los rangos de Cloudflare tienen UN dueño. El buscador conserva su
		// propia copia del limitador —viaja en otro riel y no puede depender de
		// que Business esté activo— pero aplica este mismo filtro, así que con
		// Business encendido los dos confían exactamente en la misma lista y
		// actualizar Cloudflare se hace en un solo archivo.
		add_filter( 'akibara_trusted_proxy_ranges', array( RateLimit::class, 'seed_trusted_proxy_ranges' ), 5 );

		$this->register_core_and_seo_modules();
		$this->register_admin_and_marketing_modules();
		$this->register_order_preventa_modules();
		$this->register_catalog_and_sales_modules();
		$this->register_admin_panel();
	}

	/**
	 * Instancia y registra los módulos centrales del sitio y de SEO.
	 *
	 * @return void
	 */
	private function register_core_and_seo_modules(): void {
		$this->modules['mail_from'] = new MailFrom();
		$this->modules['mail_from']->register();

		// EmailBrand: excepción de contraste del botón (`woocommerce_email_styles`)
		// + CTA «Ver mi pedido» en los correos de pedido al cliente. Va junto a
		// MailFrom porque los dos son contrato de TODO el correo saliente, no de
		// un carril concreto.
		$this->modules['email_brand'] = new EmailBrand();
		$this->modules['email_brand']->register();

		// Los archivos de tienda y taxonomías son colecciones, no artículos.
		// Rank Math no expone este contexto desde la UI, así que se corrige
		// únicamente el og:type mediante su filtro oficial.
		$this->modules['seo_social'] = new SeoSocial();
		$this->modules['seo_social']->register();

		// Variantes JPG de portadas para previews sociales (WhatsApp/X no
		// renderizan AVIF); cron diario que solo genera las faltantes.
		$this->modules['social_image_derivatives'] = new SocialImageDerivatives();
		$this->modules['social_image_derivatives']->register();

		// Rank Math es la única fuente del Product schema. El módulo enriquece su
		// entidad con preventa y Book, y suprime el nodo de WC.
		// BookSchemaEnricher concentra la semántica editorial compartida por el
		// owner y su fallback nativo, sin registrar un segundo emisor.
		$book_schema = new BookSchemaEnricher();
		$this->modules['schema_product_rank_math'] = new SchemaProductRankMath( $book_schema );
		$this->modules['schema_product_rank_math']->register();

		// DISALLOW_FILE_MODS protege los archivos del sitio, pero Rank Math usa
		// la misma bandera para desactivar su editor virtual de robots.txt.
		// Habilitamos solo esa superficie administrativa y retiramos el editor
		// de .htaccess para no debilitar la protección de archivos.
		$this->modules['rank_math_admin_ui'] = new RankMathAdminUi();
		$this->modules['rank_math_admin_ui']->register();

		// La UI de Rank Math no permite expresar reglas por gramática de query
		// params. Este módulo es la excepción acotada: noindex,follow para filtros
		// y acciones transaccionales, sin escribir opciones ni crear redirects.
		$this->modules['filtered_urls_policy'] = new FilteredUrlsPolicy();
		$this->modules['filtered_urls_policy']->register();

		// Una sola autoridad de catálogo decide directorio, robots, schema,
		// ediciones y sitemap para evitar superficies SEO contradictorias.
		$this->modules['series_catalog_eligibility'] = new SeriesCatalogEligibility();

		// Las páginas limpias /serie/<slug>/page/N/ muestran tomos distintos y ya
		// reciben impresiones/clics propios. La opción global de Rank Math no puede
		// expresar esa excepción sin afectar categorías, marcas y blog. Este módulo
		// afirma index,follow + canonical propio únicamente para `pa_serie`; las
		// variantes con filtros siguen delegadas a FilteredUrlsPolicy.
		$this->modules['series_pagination_policy'] = new SeriesPaginationPolicy(
			$this->modules['series_catalog_eligibility']
		);
		$this->modules['series_pagination_policy']->register();

		// Cuántos tomos muestra cada página de una landing de serie (panel →
		// Vitrina) y el 301 de las páginas que dejan de existir al subirlo. Usa la
		// misma autoridad de catálogo para saber cuántas páginas hay.
		$this->modules['series_landing_page_size'] = new LandingPageSize(
			$this->modules['series_catalog_eligibility']
		);
		$this->modules['series_landing_page_size']->register();

		// Rank Math pagina productos por fecha de modificación. Cuando varios
		// comparten el mismo segundo, un desempate estable por ID evita saltos
		// o duplicados entre páginas del sitemap sin modificar el plugin padre.
		$this->modules['product_sitemap_stable_order'] = new ProductSitemapStableOrder();
		$this->modules['product_sitemap_stable_order']->register();

		$this->modules['blog'] = new Blog();
		$this->modules['blog']->register();

		$this->modules['seo_enhancements'] = new SeoEnhancements(
			series_catalog_eligibility: $this->modules['series_catalog_eligibility']
		);
		$this->modules['seo_enhancements']->register();

		// AuthorIntro genera el texto de autor que presenta SeoEnhancements.
		// Esta integración ofrece vista previa y edición desde la pantalla del término.
		$this->modules['author_intro'] = new AuthorIntro();
		$this->modules['author_intro']->register();

		// /llms.txt y /llms-full.txt propios: archivo físico regenerado a
		// diario que gana al handler de Rank Math sin desactivar nada.
		$this->modules['llms_manifest'] = new LlmsManifest(
			$this->modules['series_catalog_eligibility'],
			$this->modules['seo_enhancements']
		);
		$this->modules['llms_manifest']->register();

		$this->modules['product_schema_shopping'] = new ProductSchemaShopping();
		$this->modules['product_schema_shopping']->register();

		// Feed de Google Merchant: declara 'adult' en el catálogo de series para adultos (+18).
		$this->modules['adult_content_feed'] = new AdultContentFeed();
		$this->modules['adult_content_feed']->register();

		// Negociación de contenido: sirve representación markdown bajo la misma URL según cabecera Accept.
		$this->modules['markdown_negotiation'] = new MarkdownNegotiation();
		$this->modules['markdown_negotiation']->register();

		// Security: hardening anti user-enumeration en lost-password (1.18.1).
		$this->modules['security'] = new Security();
		$this->modules['security']->register();
	}

	/**
	 * Instancia y registra los módulos administrativos, de fidelización y marketing.
	 *
	 * @return void
	 */
	private function register_admin_and_marketing_modules(): void {
		$this->modules['frontend_compatibility'] = new FrontendCompatibility();
		$this->modules['frontend_compatibility']->register();

		$this->modules['forms'] = new Forms();
		$this->modules['forms']->register();

		$this->modules['announcement_bar'] = new AnnouncementBar();
		$this->modules['announcement_bar']->register();

		// El umbral de envío gratis se escribe una vez en el panel y todas las
		// superficies lo leen con `[akb_envio_gratis]`. Va aquí, junto a la barra,
		// porque la barra es su primer consumidor.
		$this->modules['shipping_copy'] = new ShippingCopy();
		$this->modules['shipping_copy']->register();

		// El porcentaje y la editorial en campaña se leen igual que el umbral de
		// envío: con un shortcode que sale del módulo dueño del dato. Va junto a
		// ShippingCopy porque comparten consumidor —la barra— y motivo: ningún
		// número de la tienda se teclea dos veces.
		$this->modules['discount_copy'] = new DiscountCopy();
		$this->modules['discount_copy']->register();

		$this->modules['address_autocomplete'] = new AddressAutocomplete();
		$this->modules['address_autocomplete']->register();

		$this->modules['brands_directory'] = new BrandsDirectory();
		$this->modules['brands_directory']->register();

		$this->modules['google_login_button'] = new GoogleLoginButton();
		$this->modules['google_login_button']->register();

		// El módulo FacetWP entero (Disponibilidad/Oferta/Formato sobre
		// facetwp_index_row, ShopLoopMonitor, Abilities) se retiró en 1.62.0
		// con la Fase 4.4 del cutover: FacetWP/Relevanssi ya no existen en
		// prod y el buscador propio (plugin akibara-search) cubre facetas,
		// salud del índice (HealthReport) y reconciliación (Reconciler).
		$this->modules['wishlist'] = new Wishlist();
		$this->modules['wishlist']->register();

		$this->modules['estimated_delivery'] = new EstimatedDelivery();
		$this->modules['estimated_delivery']->register();

		// PaisTermFields agrega UI nativa (min/max días) a cada término del
		// taxonomy `pa_pais`. Replace de la "Editoriales → país" UI removida del
		// AdminPanel en el refactor 1.14.0.
		$this->modules['pais_term_fields'] = new PaisTermFields();
		$this->modules['pais_term_fields']->register();

		// La política de PREVENTA vive por editorial, no por país: bajo
		// «Argentina» conviven Ivrea Argentina —350 de las 420 preventas de la
		// tienda— y Panini Argentina, con 372 productos y una sola preventa viva.
		// Ver `PedidoPolicy` para el razonamiento y las mediciones.
		$this->modules['marca_term_fields'] = new MarcaTermFields();

		// Atributos crossorigin e integridad en scripts externos para trazabilidad de errores en checkout.
		$this->modules['crossorigin_scripts'] = new CrossOriginScripts();
		$this->modules['crossorigin_scripts']->register();

		$this->modules['marca_term_fields']->register();

		// Migración idempotente de lead times para pa_pais durante upgrades y reintento administrativo diferido.
		add_action( 'akibara_business_upgraded', array( EdaMigration::class, 'maybe_run_lazy' ), 10, 0 );
		add_action( 'init', array( EdaMigration::class, 'maybe_retry_in_admin' ), 999 );

		// MarketingConsent: registro estructurado del consentimiento en la orden de compra.
		$this->modules['marketing_consent'] = new MarketingConsent();
		$this->modules['marketing_consent']->register();

		// BackInStock: lista de espera y avisos de reposición con kill-switch operativo.
		$this->modules['back_in_stock'] = new BackInStock();
		$this->modules['back_in_stock']->register();

		// WelcomeCoupon: beneficio de bienvenida 10% en primera compra con código
		// único, regla best-wins y verificación anti-abuso (RUT, celular y buzón).
		$this->modules['welcome_coupon'] = new WelcomeCoupon();
		$this->modules['welcome_coupon']->register();

		// MyAccountUx: rename + reorder tabs + override
		// `myaccount/dashboard.php` (1.38.0).
		$this->modules['my_account_ux'] = new MyAccountUx();
		$this->modules['my_account_ux']->register();

		$this->modules['admin_dashboard'] = new \Akibara\Business\Modules\AdminDashboard\AdminDashboard();
		$this->modules['admin_dashboard']->register();
	}

	/**
	 * Instancia y registra los módulos de validación de compra, checkout y preventa.
	 *
	 * @return void
	 */
	private function register_order_preventa_modules(): void {
		$this->modules['rut_validation'] = new RutValidation();
		$this->modules['rut_validation']->register();

		$this->modules['phone_validation'] = new PhoneValidation();
		$this->modules['phone_validation']->register();

		$this->modules['checkout_tax_labels'] = new CheckoutTaxLabels();
		$this->modules['checkout_tax_labels']->register();

		$this->pudo_checkout = new PudoCheckoutService();

		// GuestOrderLinking: personalización de avisos de vinculación segura de pedidos en Mi Cuenta.
		$this->modules['guest_order_linking'] = new GuestOrderLinking();
		$this->modules['guest_order_linking']->register();

		// Coordinación e instrucciones para pedidos con retiro presencial en Metro San Miguel.
		$this->modules['pickup_coordination'] = new PickupCoordination();
		$this->modules['pickup_coordination']->register();

		// OrderPreventa depende del DateCalculator construido por EstimatedDelivery.
		// Reusa el mismo singleton — single source of truth para "es preventa" y ETA.
		$this->modules['order_preventa'] = new OrderPreventa( $this->modules['estimated_delivery']->calculator() );
		$this->modules['order_preventa']->register();

		// Los providers externos se conectan fuera de OrderPreventa mediante sus
		// contratos de capacidades; el núcleo no depende de carriers ni reseñas.
		$this->modules['order_preventa_integrations'] = new OrderPreventaIntegrations();
		$this->modules['order_preventa_integrations']->register();

		// OrderTimeline: progress visual del pedido en /view-order/ con
		// nodo de espera («Preventa»/«A pedido») cuando aplica (1.41.0). Hook
		// woocommerce_order_details_before_order_table priority 5.
		$this->modules['order_timeline'] = new OrderTimeline();
		$this->modules['order_timeline']->register();
	}

	/**
	 * Instancia y registra los módulos de catálogo, vitrina, series y venta.
	 *
	 * @return void
	 */
	private function register_catalog_and_sales_modules(): void {
		$this->modules['loop_enhancer'] = new LoopEnhancer();
		$this->modules['loop_enhancer']->register();

		// Estado comercial (1.109.0): el puente le presta Availability al
		// buscador (faceta 'a_pedido'/'preventa') y el cron barre las preventas
		// cuya fecha venció para que índice y cachés de página se enteren de la
		// degradación (que en runtime es automática).
		$this->modules['search_availability_bridge'] = new \Akibara\Business\Modules\Stock\SearchAvailabilityBridge();
		$this->modules['search_availability_bridge']->register();

		$this->modules['preventa_vencida_cron'] = new \Akibara\Business\Modules\Stock\PreventaVencidaCron();
		$this->modules['preventa_vencida_cron']->register();

		$this->modules['catalog_order'] = new CatalogOrder();
		$this->modules['catalog_order']->register();

		$this->modules['popular_products'] = new PopularProducts();
		$this->modules['popular_products']->register();

		$this->modules['product_faq'] = new ProductFaq();
		$this->modules['product_faq']->register();

		// Relación editorial mantenible desde la UI nativa de cada término
		// pa_serie. El bloque solo aparece en la URL limpia de la serie.
		$this->modules['series_related_guide'] = new SeriesRelatedGuide();
		$this->modules['series_related_guide']->register();

		// La misma relación al revés: la serie de una guía y los datos de su
		// tarjeta, que el tema pinta en la entrada (D6). Los hechos de la serie
		// se piden al pintar: `series_facts` se crea más abajo.
		$this->modules['guide_series_card'] = new GuideSeriesCard( fn (): ?object => $this->modules['series_facts'] ?? null );
		$this->modules['guide_series_card']->register();

		$this->modules['home_destacados'] = new HomeDestacados();
		$this->modules['home_destacados']->register();

		// Vitrina Series más buscadas: enlaza portada y landings de serie, con
		// lista, título e interruptor editables en el hub.
		$this->modules['home_series_destacadas'] = new HomeSeriesDestacadas();
		$this->modules['home_series_destacadas']->register();

		$this->modules['home_hero'] = new HomeHero();
		$this->modules['home_hero']->register();

		// Quick-view bottom-sheet para vista rápida de sinopsis y datos de tomo desde tarjetas de catálogo.
		$this->modules['quick_view'] = new QuickView();
		$this->modules['quick_view']->register();

		$this->modules['free_shipping_progress'] = new FreeShippingProgress();
		$this->modules['free_shipping_progress']->register();

		$this->modules['preventas_route'] = new PreventasRoute();
		$this->modules['preventas_route']->register();

		// Ruta /disponibles/ para explorar productos con entrega inmediata (en stock).
		$this->modules['disponibles_route'] = new DisponiblesRoute();
		$this->modules['disponibles_route']->register();

		$this->modules['pdp_enhancer'] = new PdpEnhancer();
		$this->modules['pdp_enhancer']->register();

		$this->modules['pdp_series_enhancer'] = new PdpSeriesEnhancer();
		$this->modules['pdp_series_enhancer']->register();

		// Panel administrativo de variantes físicas de tomo.
		$this->modules['volume_variant_panel'] = new VolumeVariantPanel();
		$this->modules['volume_variant_panel']->register();

		// Carrito lateral nativo sincronizado con el renderizador de plazos de entrega estimada.
		$this->modules['native_cart'] = new NativeCart(
			null,
			$this->modules['estimated_delivery']->renderer()
		);
		$this->modules['native_cart']->register();

		// Techo de cantidad máxima por línea sincronizado con Store API.
		$this->modules['cart_quantity_ceiling'] = new CartQuantityCeiling();
		$this->modules['cart_quantity_ceiling']->register();

		// Mensajes de rechazo de adición al carrito editables desde Akibara Textos.
		$this->modules['cart_rejection_copy'] = new RejectionCopy();
		$this->modules['cart_rejection_copy']->register();

		$this->modules['smart_cart_recommender'] = new SmartCartRecommender(
			$this->modules['estimated_delivery']->renderer()
		);
		$this->modules['smart_cart_recommender']->register();

		$this->modules['upsells_autofill'] = new UpsellsAutofill();
		$this->modules['upsells_autofill']->register();

		// Qué recomendaciones se MUESTRAN (relacionados y upsells de la ficha) y
		// sus ajustes; UpsellsAutofill decide qué tomos se GUARDAN.
		$this->modules['recommendations'] = new Recommendations();
		$this->modules['recommendations']->register();

		$this->modules['taxonomy_discount'] = new TaxonomyDiscount();
		$this->modules['taxonomy_discount']->register();

		// Apaga el interruptor de campaña al vencer su fecha final, compartiendo
		// Settings con el módulo de la campaña.
		$this->modules['campaign_expiry_cron'] = new \Akibara\Business\Modules\TaxonomyDiscount\CampaignExpiryCron( $this->modules['taxonomy_discount']->settings() );
		$this->modules['campaign_expiry_cron']->register();

		// SeriesFacts: resumen de una línea sobre la rejilla y ficha de datos
		// duros bajo ella, en cada landing `pa_serie`. Las 130 series ya tienen H1/título/descripción y son el
		// 17,6% de los clics del sitio, pero no publicaban NI UNA spec ni una
		// fecha — los dos factores que el experimento factorial de GEO midió
		// como decisivos para no ser citado. Sólo imprime hechos derivados del
		// catálogo vivo; la prosa la escribe el dueño.
		//
		// Se instancia DESPUÉS de taxonomy_discount porque consume sus settings
		// (misma instancia que los filtros de precio) para omitir el rango
		// cuando una campaña vigente hace que la lookup de precios deje de
		// coincidir con lo que muestran las cards. El orden de registro no
		// afecta al DOM: el resumen cuelga de `woocommerce_before_shop_loop`
		// prio 2 y la ficha de `woocommerce_after_shop_loop` prio 20, sin empates.
		$discount_settings                = $this->modules['taxonomy_discount']->settings();
		$this->modules['series_facts']    = new SeriesFacts(
			static fn (): bool => $discount_settings->is_enabled()
		);
		$this->modules['series_facts']->register();

		// Sincroniza la consulta de precios con el estado de descuento editorial activo en tarjetas de edición.
		EditionFacts::set_price_override_probe( static fn (): bool => $discount_settings->is_enabled() );

		// La UI configura el estado editorial de la obra, que no se deduce del
		// número de tomos presentes en catálogo. PublicationStatus comparte enum,
		// saneamiento y texto entre el formulario del término y SeriesFacts.
		$this->modules['series_publication_status'] = new PublicationStatusFields();
		$this->modules['series_publication_status']->register();

		// SeriesEditions mantiene separadas las landings de cada edición física,
		// pero las conecta junto al H1. Así no se mezcla la numeración de Ivrea con
		// un 3-en-1 de Planeta ni se obliga al lector a volver al buscador.
		$this->modules['series_editions'] = new SeriesEditions( $this->modules['series_catalog_eligibility'] );
		$this->modules['series_editions']->register();

		// SeriesSchema adjunta al grafo Rank Math la entidad de la landing, con
		// la misma instancia SeriesFacts que alimenta el bloque visible. Comparte
		// agregado, caché y criterio de precio publicable.
		// El valor explícito non_comic conserva CollectionPage y omite ComicSeries
		// y la relación isPartOf correspondiente en las fichas de producto.
		$this->modules['series_schema'] = new SeriesSchema( $this->modules['series_facts'] );
		$this->modules['series_schema']->register();

		// Campos administrativos para selección de tipo de schema en términos de serie.
		$this->modules['series_schema_fields'] = new SchemaTypeFields();
		$this->modules['series_schema_fields']->register();

		// Directorio alfabético de series vía shortcode [akibara_series_directory].
		$this->modules['series_directory'] = new SeriesDirectory(
			new SeriesDirectoryShortcode(
				new SeriesIndex( $this->modules['series_catalog_eligibility'] )
			)
		);
		$this->modules['series_directory']->register();

		// SeriesBundles: bundles de series (arma tu pack por volumen) con cálculo de plazos de entrega por tomo.
		$customer_collection = new CustomerCollection();
		$this->modules['customer_collection'] = $customer_collection;
		$customer_collection->register();

		$series_bundles = new SeriesBundles(
			$this->modules['estimated_delivery']->calculator(),
			$customer_collection
		);
		$this->modules['series_bundles'] = $series_bundles;
		$series_bundles->register();

		$collection_resolver = new CollectionResolver(
			$customer_collection,
			null,
			$this->modules['estimated_delivery']->calculator(),
			new ContinuityResolver()
		);
		$this->modules['account_collection'] = new AccountCollection( array( $collection_resolver, 'resolve' ) );
		$this->modules['account_collection']->register();

		$this->modules['collection_badges'] = new CollectionBadges( $customer_collection );
		$this->modules['collection_badges']->register();

		$this->checkout_data = new CheckoutDataService(
			$this->modules['estimated_delivery']->calculator(),
			$this->modules['estimated_delivery']->renderer(),
			$series_bundles,
			$this->modules['free_shipping_progress'],
			$this->modules['address_autocomplete']
		);

		// SearchPriceProvider publica el precio efectivo mediante
		// akb_search_effective_price con la instancia memoizada de DiscountResolver.
		// Se registra siempre, independientemente del bootstrap del buscador.
		// Si la campaña no puede determinarse con autoridad, lanza: un índice
		// materializado no debe sellar el precio base como si fuera un valor válido.
		// La cobertura de IDs no demuestra que los precios publicados sean correctos.
		$akb_search_price_resolver = $this->modules['taxonomy_discount']->resolver();
		$akb_search_price_provider = new \Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider( $akb_search_price_resolver );
		add_filter( 'akb_search_effective_price', $akb_search_price_provider, 10, 2 );

		// Search usa el callback efectivo de akb_search_effective_price con
		// prioridad 10 y dos argumentos. Consulta directamente adopt_search_snapshot,
		// snapshot_is_fresh y authoritative_snapshot del mismo objeto, evitando
		// puentes reemplazables que desacoplen precio publicado y foto sellada.

		// El subsistema Search vive en el plugin standalone `akibara-search`
		// (dueño único; extraído en la ola 0). Business ya no instancia ni
		// registra el módulo — solo provee el precio con descuento por el
		// filtro de arriba. El admin del buscador es una PÁGINA PROPIA del
		// plugin akibara-search (menú "Buscador"), no un tab de este panel.

		// SeriesFollow: notificaciones de nuevos lanzamientos para clientes suscritos a series.
		$this->modules['series_follow'] = new SeriesFollow(
			$customer_collection,
			$this->modules['estimated_delivery']->calculator(),
			new PedidoPolicy()
		);
		$this->modules['series_follow']->register();
	}

	/**
	 * Instancia y registra el panel administrativo principal y sus submódulos.
	 *
	 * @return void
	 */
	private function register_admin_panel(): void {
		$panel = new AdminPanel();
		$panel->add_section(
			'dashboard',
			'Dashboard',
			'__return_empty_array',
			array( $this->modules['admin_dashboard'], 'render_admin_section' ),
			true
		);
		$panel->add_section(
			'home_hero',
			'Slider de Inicio',
			'__return_empty_array',
			array( $this->modules['home_hero'], 'render_admin_section' ),
			true,
			'',
			'catalogo'
		);
		$panel->add_section( 'home_series',     'Series en la portada',    array( $this->modules['home_series_destacadas'], 'get_settings' ), null, false, '', 'catalogo' );
		$panel->add_section( 'announcement',    'Barra de anuncios',       array( $this->modules['announcement_bar'],     'get_settings' ), null, false, '', 'fidelizacion' );
		$panel->add_section( 'address',         'Autocompletar dirección', array( $this->modules['address_autocomplete'], 'get_settings' ), null, false, '', 'sitio' );
		// Las familias de editorial con dos ediciones (Panini, Ivrea): etiquetas,
		// hechos por edición y series destacadas. `is_action`: trae su propio form.
		$panel->add_section(
			\Akibara\Business\Modules\BrandsDirectory\EditionsPanel::PANEL_TAB,
			'Editoriales · Ediciones',
			'__return_empty_array',
			array( $this->modules['brands_directory']->editions_panel(), 'render' ),
			true,
			'',
			'catalogo'
		);
		// Sub-panel unificado de ajustes de preventa y plazos de entrega estimada.
		$this->modules['order_preventa']->settings()->set_eda_module( $this->modules['estimated_delivery'] );
		add_filter( 'akibara_panel_section_is_action', array( $this->modules['order_preventa']->settings(), 'filter_section_is_action' ), 10, 2 );
		( new \Akibara\Business\Modules\EstimatedDelivery\CortesPanel() )->register();
		$panel->add_section(
			\Akibara\Business\Modules\OrderPreventa\Settings::PANEL_TAB_ID,
			'Pedidos y preventa · Ajustes',
			array( $this->modules['order_preventa'], 'get_settings' ),
			array( $this->modules['order_preventa'], 'render_panel_header' ),
			false,
			'',
			'venta'
		);
		// Pantalla operativa para gestión de tomos por llegar y pedidos de preventa.
		$panel->add_section(
			'preventas',
			'Por llegar',
			'__return_empty_array',
			array( $this->modules['order_preventa']->preventas_screen(), 'render_admin_section' ),
			true,
			'',
			'venta'
		);
		$panel->add_section(
			'back_in_stock',
			'Lista de espera',
			array( $this->modules['back_in_stock'], 'get_settings' ),
			array( $this->modules['back_in_stock'], 'render_admin_section' ),
			false,
			'',
			'fidelizacion'
		);
		$panel->add_section(
			'series_follow',
			'Sigue esta serie',
			array( $this->modules['series_follow'], 'get_settings' ),
			array( $this->modules['series_follow'], 'render_admin_section' ),
			false,
			'',
			'fidelizacion'
		);
		$panel->add_section( 'free_shipping_progress', 'Envío gratis', array( $this->modules['free_shipping_progress'], 'get_settings' ), null, false, '', 'venta' );
		$panel->add_section( 'native_cart', 'Carrito', array( $this->modules['native_cart'], 'get_settings' ), null, false, '', 'venta' );
		// Sección de recomendaciones y autofill de tomos relacionados y upsells.
		$upsells_autofill = $this->modules['upsells_autofill'];
		$panel->add_section(
			RecommendationSettings::PANEL_TAB_ID,
			'Recomendaciones',
			array( RecommendationSettings::class, 'get_settings' ),
			static function () use ( $upsells_autofill ): void {
				$upsells_autofill->render_admin_section();
				RecommendationSettings::render_heading();
			},
			false,
			'',
			'marketing'
		);
		// Panel de ordenamiento de vitrina y paginación de landings de serie.
		$catalog_order = $this->modules['catalog_order'];
		$panel->add_section(
			CatalogOrder::PANEL_TAB_ID,
			'Vitrina',
			static fn (): array => array_merge( $catalog_order->get_settings(), LandingPageSize::get_settings() ),
			array( $this->modules['catalog_order'], 'render_admin_section' ),
			false,
			'',
			'catalogo'
		);
		$panel->add_section(
			TaxonomyDiscount::PANEL_TAB_ID,
			'Descuentos',
			array( $this->modules['taxonomy_discount'], 'get_settings' ),
			array( $this->modules['taxonomy_discount'], 'render_subtab_nav' ),
			false,
			'',
			'marketing'
		);
		$panel->add_section(
			SeriesBundles::PANEL_TAB_ID,
			'Arma tu Pack',
			array( $this->modules['series_bundles'], 'get_settings' ),
			array( $this->modules['series_bundles'], 'render_header' ),
			false,
			'',
			'marketing'
		);
		$panel->add_section(
			WelcomeCoupon::PANEL_TAB_ID,
			'Pop-ups & Promociones',
			array( $this->modules['welcome_coupon'], 'get_settings' ),
			array( $this->modules['welcome_coupon'], 'render_admin_section' ),
			false,
			'',
			'marketing'
		);

		// El buscador (plugin akibara-search) tiene su PROPIA página de admin
		// (menú "Buscador"), NO un tab de este panel — no se registra acá.

		// Inicializar y registrar submódulos del panel
		$theme_customizer = new ThemeCustomizer( $panel );
		$this->modules['theme_customizer'] = $theme_customizer;
		$theme_customizer->register();

		// Calidad AVIF de las imágenes nuevas y recompresión de las ya subidas
		// (pestaña «Imágenes»). Fuera de cualquier rama de admin a propósito: el
		// filtro de calidad tiene que alcanzar a las subidas por REST y a la cola.
		$image_quality                  = new ImageQuality( $panel, new Recompressor() );
		$this->modules['image_quality'] = $image_quality;
		$image_quality->register();

		// Recalentado nocturno de las fichas, purga por serie, GA4 y Clarity
		// después de la carga, el carrito sin lo que sólo usa la página de pago y
		// etiqueta de Google Ads (pestaña «Velocidad»). Fuera de las ramas de admin: la purga por serie
		// escucha ventas y guardados de cualquier origen, y la cola corre sin admin.
		// El filtro de la etiqueta se engancha antes, desde el archivo principal.
		$site_speed                  = new SiteSpeed( $panel, new CacheWarmer(), new SeriesCacheTags(), new AnalyticsAfterLoad(), new LeanCart() );
		$this->modules['site_speed'] = $site_speed;
		$site_speed->register();

		// La purga de la ficha y de su serie se queda en el origen: Cloudflare
		// guarda su propia copia del HTML. Esto la lleva al borde, por URL.
		$edge_purge                  = new EdgePurge();
		$this->modules['edge_purge'] = $edge_purge;
		$edge_purge->register();

		$copy_manager = new CopyManager( $panel );
		$this->modules['copy_manager'] = $copy_manager;
		$copy_manager->register();

		$financial_rules = new \Akibara\Business\Admin\FinancialRulesAdminPage();
		$this->modules['financial_rules'] = $financial_rules;
		$financial_rules->register();

		$bacs_lifecycle = new \Akibara\Business\Modules\BacsLifecycle\BacsLifecycleManager();
		$this->modules['bacs_lifecycle'] = $bacs_lifecycle;
		$bacs_lifecycle->register();

		$mixed_cart_guard = new \Akibara\Business\Modules\Logistics\MixedCartShipmentGuard();
		$this->modules['mixed_cart_guard'] = $mixed_cart_guard;
		$mixed_cart_guard->register();

		$partial_refund_guard = new \Akibara\Business\Modules\SeriesBundles\PartialRefundBundleGuard();
		$this->modules['partial_refund_bundle_guard'] = $partial_refund_guard;
		$partial_refund_guard->register();

		$this->modules['admin_panel'] = $panel;
		$this->modules['admin_panel']->register();
	}

	/**
	 * Borra opciones propias que ya no tienen ningún lector en el código.
	 *
	 * Corre en `akibara_business_upgraded` de forma idempotente para depurar opciones obsoletas.
	 *
	 * @return void
	 */
	public function cleanup_orphan_options(): void {
		delete_option( 'akibara_robots_policy_version' );
		delete_option( 'akibara_rank_math_product_schema_migration' );
		delete_option( 'akb_series_dispatch_enabled' );
		delete_option( 'akibara_announcement_speed' );

		// Opciones de configuración SMTP obsoletas.
		delete_option( 'akibara_smtp_host' );
		delete_option( 'akibara_smtp_port' );
		delete_option( 'akibara_smtp_secure' );
		delete_option( 'akibara_smtp_user' );
		delete_option( 'akibara_smtp_pass' );
		delete_option( 'akibara_mail_failures' );

		// Opciones de control de flushes históricos.
		delete_option( 'akb_wishlist_flushed' );
		delete_option( 'akb_preventas_rewrite_version' );
		delete_option( 'akb_disponibles_rewrite_version' );

		// Opciones legacy de entregas estimadas anteriores.
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'akibara\\_eda\\_brand\\_%'"
		);
	}

	/**
	 * Devuelve un módulo registrado por su slug.
	 *
	 * @param string $slug Slug del módulo (e.g., "rut_validation").
	 * @return object|null Instancia del módulo o null si no existe.
	 */
	public function module( string $slug ): ?object {
		return $this->modules[ $slug ] ?? null;
	}

	/**
	 * Returns the stable checkout data contract after Business has booted.
	 *
	 * @return CheckoutDataProvider|null
	 *
	 * @since 1.95.0
	 */
	public function checkout_data(): ?CheckoutDataProvider {
		return $this->checkout_data;
	}

	/**
	 * Returns the canonical PUDO checkout domain service after Business boots.
	 *
	 * @return PudoCheckoutService|null
	 *
	 * @since 1.96.0
	 */
	public function pudo_checkout(): ?PudoCheckoutService {
		return $this->pudo_checkout;
	}
}
