<?php
/**
 * Series Bundles — clase raíz del módulo (solo Build_Your_Pack).
 *
 * Compone por inyección de dependencias los subcomponentes de "Arma tu pack"
 * (BYP): el cliente elige tomos de una serie y recibe un descuento por volumen.
 * La feature de "packs curados" (creados por el admin) fue eliminada; este módulo
 * ya no registra el custom product type `akb_bundle`, ni la ruta `/packs/`, ni la
 * vitrina/CRUD de packs.
 *
 * Convención de módulos del plugin (Req 10.4): la clase `Plugin` instancia este
 * módulo y llama a `register()`. Namespace PSR-4
 * `Akibara\Business\Modules\SeriesBundles` (Req 11.1). Todos los textos en
 * español sin i18n (Req 11.4).
 *
 * Cableado de dependencias (single source of truth por colaborador):
 *
 *   Settings ─┬─► EligibilityGuard
 *             └─► (consumido por casi todos)
 *
 *   Settings + guard ─► CartSeriesCollector (rutina ÚNICA carrito→serie→config)
 *   Settings ─► SeriesDepth (conteo ÚNICO de tomos vendibles por término)
 *   VolumeTierResolver ─► DiscountEngine
 *   Settings + engine + collector ─► BundleCouponEngine
 *   Settings + guard + tiers + collector + depth ─► BuilderWidget   (ficha)
 *   Settings + guard + tiers + collector + depth ─► LandingOffer    (landing)
 *   Settings + tiers + collector ─► BypCartNoticeProvider
 *   tiers + collector ─► OrderInstrumentation
 *   Settings ─► Admin\AdminSection
 *   Settings + tiers ─► Admin\BypConfigController
 *
 * El "mejor descuento gana" frente a `TaxonomyDiscount` NO necesita un guard
 * dedicado: lo garantiza el `DiscountEngine`, que descuenta sobre el precio
 * regular pero cede si el precio filtrado (que ya incluye la taxonomía) es ≤ el
 * target del tramo → best-wins real, sin apilar fuentes.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Contracts\EtaProvider;
use Akibara\Business\Internal\UpgradeRetryRequired;
use Akibara\Business\Modules\SeriesBundles\Admin\AdminSection;
use Akibara\Business\Modules\SeriesBundles\Admin\AdminAssets;
use Akibara\Business\Modules\SeriesBundles\Admin\BypConfigController;

defined( 'ABSPATH' ) || exit;

final class SeriesBundles {

	/** Slug de la pestaña del Admin_Panel del módulo. */
	public const PANEL_TAB_ID = 'series_bundles';

	/** Renderer-independent fallback label for virtual bundle coupons. */
	public const GENERIC_DISCOUNT_LABEL = 'Descuento de pack';

	private Settings $settings;
	private VolumeTierResolver $tiers;
	private DiscountEngine $engine;
	private EligibilityGuard $guard;
	private CartSeriesCollector $collector;
	private SeriesDepth $depth;
	private CustomerCollection $customer_collection;
	private PromotionPolicy $promotion_policy;

	private BundleCouponEngine $coupon_engine;
	private BuilderWidget $builder_widget;
	private LandingOffer $landing_offer;
	private BypCartNoticeProvider $cart_notice;
	private OrderInstrumentation $order_instrumentation;

	private AdminSection $admin_section;
	private BypConfigController $byp_config;
	private AdminAssets $admin_assets;

	/**
	 * Compone todos los subcomponentes por inyección de dependencias, usando una
	 * única instancia compartida de cada colaborador base.
	 *
	 * @param EtaProvider|null $eta Proveedor de ETA de preventa (el
	 *        DateCalculator de EstimatedDelivery, vía interfaz). Se propaga al
	 *        BuilderWidget para etiquetar los tomos en preventa con su fecha por-tomo
	 *        (BYP-PREVENTA-CLARIDAD). Null degrada a "Preventa" sin fecha.
	 */
	public function __construct( ?EtaProvider $eta = null, ?CustomerCollection $customer_collection = null ) {
		// --- Núcleo compartido ---------------------------------------------
		$this->settings  = new Settings();
		$this->tiers     = new VolumeTierResolver();
		$this->engine    = new DiscountEngine( $this->tiers );
		$this->guard     = new EligibilityGuard( $this->settings );
		$this->collector = new CartSeriesCollector( $this->settings, $this->guard );
		$this->customer_collection = $customer_collection ?? new CustomerCollection();
		// Instancia COMPARTIDA por ficha y landing: el memo del conteo vive en el
		// objeto, así que compartirlo es lo que garantiza un solo COUNT por término
		// y por request aunque las dos superficies pregunten.
		$this->depth     = new SeriesDepth( $this->settings );
		$this->promotion_policy = new PromotionPolicy( $this->settings, $this->depth, $this->collector );

		// --- Capa de hooks WC ----------------------------------------------
		$this->coupon_engine     = new BundleCouponEngine(
			$this->settings,
			$this->engine,
			$this->collector
		);
		$this->builder_widget    = new BuilderWidget( $this->settings, $this->guard, $this->tiers, $this->collector, $eta, $this->depth, $this->customer_collection );
		// Landing de serie: el pack no existía en la superficie cuyo propósito
		// literal es la serie entera. NO es el builder movido — es el enunciado del
		// umbral, cache-safe (sin carrito ni usuario) y sin repintar las portadas.
		$this->landing_offer     = new LandingOffer( $this->settings, $this->guard, $this->tiers, $this->collector, $this->depth, null, $this->customer_collection );
		$this->cart_notice       = new BypCartNoticeProvider( $this->settings, $this->tiers, $this->collector, $this->promotion_policy );
		$this->order_instrumentation = new OrderInstrumentation( $this->tiers, $this->collector );

		// --- Admin ----------------------------------------------------------
		$this->admin_section = new AdminSection( $this->settings );
		$this->byp_config    = new BypConfigController( $this->settings, $this->tiers, $this->promotion_policy );
		$this->admin_assets  = new AdminAssets();
	}

	/**
	 * Conecta los hooks de cada subcomponente que los tenga (Req 10.4).
	 *
	 * Los componentes de lógica pura (`DiscountEngine`, `VolumeTierResolver`,
	 * `EligibilityGuard`, `SeriesDepth`) no registran hooks.
	 */
	public function register(): void {
		add_action( 'akibara_business_upgraded', array( $this, 'standardize_promotion_policy' ), 10, 2 );
		$this->coupon_engine->register();
		$this->builder_widget->register();
		$this->landing_offer->register();
		$this->order_instrumentation->register();
		$this->byp_config->register();
		$this->admin_assets->register();
	}

	/**
	 * Estandariza promociones legacy al esquema de margen vigente.
	 *
	 * El listener es idempotente porque el upgrade de Business puede reanudar sus
	 * tareas si el primer request muere dentro de la ventana protegida por CAS.
	 * Las series deshabilitadas y los tramos personalizados quedan intactos.
	 *
	 * @param string $current_version  Versión instalada.
	 * @param string $previous_version Versión anterior.
	 * @return void
	 */
	public function standardize_promotion_policy( string $current_version, string $previous_version ): void {
		unset( $previous_version );

		if ( version_compare( $current_version, '1.102.9', '<' ) ) {
			return;
		}

		if ( ! $this->promotion_policy->standardize_persisted_config() ) {
			throw new UpgradeRetryRequired( 'La política BYP cambió durante los tres intentos CAS.' );
		}
	}

	/**
	 * Returns structured bundle data for renderer-agnostic adapters.
	 *
	 * @return array{
	 *   coupon_prefix:string,
	 *   coupon_labels:array<string,string>,
	 *   generic_coupon_label:string,
	 *   notices:array<int,array{
	 *     id:string,
	 *     kind:string,
	 *     tone:string,
	 *     badge:string,
	 *     series_name:string,
	 *     title:string,
	 *     message:string,
	 *     missing_count:int,
	 *     discount_percent:int
	 *   }>
	 * }
	 *
	 * @since 1.96.0
	 */
	public function structured_cart_presentation(): array {
		$labels = array();
		$cart   = function_exists( 'WC' ) ? WC()->cart : null;
		if ( $cart instanceof \WC_Cart ) {
			foreach ( $this->collector->collect( $cart ) as $hash => $data ) {
				$labels[ BundleCouponEngine::COUPON_BYP_PREFIX . $hash ] = 'Pack ' . self::plain_text( (string) $data['name'] );
			}
		}

		return array(
			'coupon_prefix'       => BundleCouponEngine::COUPON_PREFIX,
			'coupon_labels'       => $labels,
			'generic_coupon_label' => self::GENERIC_DISCOUNT_LABEL,
			'notices'             => $this->cart_notice->notices(),
		);
	}

	/**
	 * Settings fields globales del módulo para el `AdminPanel`. Delega en
	 * `Admin\AdminSection::get_settings()`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return $this->admin_section->get_settings();
	}

	/**
	 * Header de la pestaña del `AdminPanel`. Renderiza la status card (estado del
	 * módulo), el atajo a los ajustes globales y la configuración de
	 * Build_Your_Pack.
	 */
	public function render_header(): void {
		$this->admin_section->render_header();
		$this->render_global_settings_shortcut();
		$this->byp_config->render();
	}

	/**
	 * Enlaza a los ajustes globales situados al final de la pantalla.
	 *
	 * AdminPanel invoca header_fn antes de abrir su formulario. El ancla deriva de
	 * la constante de la opción para seguir el id que WooCommerce da al interruptor.
	 * Los formularios del panel y de BypConfigController son hermanos: cada botón
	 * envía sólo su formulario y descarta entradas sin guardar del otro. El aviso
	 * expone ese comportamiento al operador.
	 *
	 * @return void
	 */
	private function render_global_settings_shortcut(): void {
		$switch_anchor = Settings::OPTION_ENABLED;
		?>
		<section class="akb-sb-status-card" aria-labelledby="akb-sb-globals-heading">
			<h2 id="akb-sb-globals-heading" class="akb-sb-status-card__heading">Ajustes globales</h2>
			<div class="akb-sb-status">
				<a class="button" href="#<?php echo esc_attr( $switch_anchor ); ?>">
					Activar o apagar «Arma tu Pack»
				</a>
				<div class="akb-sb-status__meta">
					<span>
						El interruptor del módulo, las <strong>categorías sin descuento</strong> y las
						<strong>preventas</strong> están al final de la página y se guardan con
						<strong>Guardar ajustes</strong>.
					</span>
					<span>
						La configuración por serie de aquí abajo tiene su propio botón
						<strong>Guardar configuración</strong>: guarda uno antes de editar el otro,
						porque cada botón envía sólo su formulario.
					</span>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Normalizes dynamic copy to a single plain-text line.
	 *
	 * @param string $text Raw copy.
	 * @return string
	 */
	private static function plain_text( string $text ): string {
		$decoded = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain   = preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', $decoded ) );

		return trim( is_string( $plain ) ? $plain : '' );
	}
}
