<?php
/**
 * Series Bundles — riel de conversión para las landings `pa_serie`.
 *
 * El riel vive sobre la grilla y convierte las cards que WooCommerce ya rinde
 * en un selector liviano. No repinta portadas ni precarga la serie completa:
 * cada card recibe un checkbox oculto que JS revela sólo al activar el modo.
 * La selección persiste por serie en `sessionStorage`, de modo que puede cruzar
 * la paginación; un endpoint propio revalida visibilidad, stock, tipo, serie y
 * comprabilidad antes de escribir.
 *
 * El interruptor del módulo controla la superficie completa: al desactivarse,
 * se oculta el selector de packs y se desactivan sus endpoints para asegurar
 * coherencia total entre catálogo y checkout.
 *
 * Los cuatro chips de disponibilidad son un formulario GET server-first sobre
 * la faceta real `_disponibilidad`. Sin JS siguen filtrando, producen una URL
 * compartible y no agregan enlaces rastreables a combinaciones facetadas. Sus
 * conteos salen de `SeriesFacts::catalog_facts()`, el agregado canónico que ya
 * alimenta el bloque visible y el schema.
 *
 * El HTML público es cache-safe: no lee usuario, pedidos ni carrito. El progreso
 * personal se pide sólo después de activar el modo; invitados reciben el enlace
 * canónico a Mi cuenta y usuarios autenticados reutilizan `CustomerCollection`.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Utilities\RateLimit;
use Akibara\Business\Modules\SeriesFacts\SeriesFacts;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\QuickView\QuickView;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\NavigationLinks;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Services\VolumeVariant;
use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Business\Utilities\Text;
use WC_Product;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class LandingOffer {

	/** Taxonomía de las landings de serie. */
	public const TAXONOMY = SeriesDepth::TAXONOMY;

	/** Acción read-only que resuelve la colección del usuario vivo. */
	public const ACTION_COLLECTION_STATE = 'akb_sb_collection_state';

	/** Acción de compra múltiple. Vive y muere con el interruptor del módulo. */
	public const ACTION_ADD_SERIES = 'akb_sb_add_series_selection';

	/** Nonce del POST de compra múltiple. */
	public const NONCE_ADD_SERIES = 'akb_sb_add_series_selection';

	/**
	 * Fragmento estable del riel: deep-link sin crear otra URL indexable. ABRE
	 * el modo de selección (`series-bundles.js`): es el destino de «Completa tu
	 * colección» en Mi cuenta (`AccountCollection\CollectionResolver`).
	 */
	public const FRAGMENT = 'completar-coleccion';

	/**
	 * Ancla de los chips de disponibilidad: devuelve al cliente a la cabecera del
	 * riel tras filtrar, sin forzar la apertura del modo de selección.
	 */
	public const FILTERS_FRAGMENT = 'tomos';

	/**
	 * Gancho del riel, junto al título: el descuento máximo (Akibara → Textos).
	 * Es la primera mitad del disparador del desplegable; la segunda es
	 * `OPTION_RULES_TOGGLE`.
	 */
	public const OPTION_OFFER_HOOK = 'akb_copy_series_offer_hook';

	/** El de fábrica. */
	public const DEFAULT_OFFER_HOOK = 'Hasta {pct}% de descuento';

	/**
	 * Hueco del porcentaje máximo en el gancho. NUNCA `sprintf`: el texto lo
	 * edita el dueño y un «%» suelto sería un fatal en todas las landings.
	 */
	public const PCT_MARKER = '{pct}';

	/** Texto del desplegable con las condiciones del descuento (Akibara → Textos). */
	public const OPTION_RULES_TOGGLE = 'akb_copy_series_offer_rules';

	/** El de fábrica. */
	public const DEFAULT_RULES_TOGGLE = 'Cómo funciona';

	/** Nombre real de la faceta de stock del buscador first-party. */
	public const STOCK_FILTER_PARAM = '_disponibilidad';

	/** Tanda de prime de objetos al resolver si un tramo es alcanzable. */
	private const HYDRATION_CHUNK = 20;

	/** El contrato editorial limita una obra a 999 tomos. */
	private const MAX_CATALOG_IDS = 999;

	/** Defensa en profundidad para cada endpoint público. */
	private const ADD_RATE_PER_MINUTE        = 10;
	private const COLLECTION_RATE_PER_MINUTE = 30;

	/**
	 * Memo por-request de la oferta ya resuelta, por `term_id`.
	 *
	 * @var array<int, array{tiers:array<int, array{min:int, pct:int}>, entry:array{url:string, title:string}|null}|null>
	 */
	private array $offer_memo = array();

	/** @var array<int,array{tomos:int,instock:int,preventa:int,agotados:int}|null> */
	private array $counts_memo = array();

	/** Evita doble salida en modos de archivo que disparan ambos hooks WC. */
	private bool $rendered = false;

	private Settings $settings;
	private EligibilityGuard $guard;
	private VolumeTierResolver $tiers;
	private CartSeriesCollector $collector;
	private SeriesDepth $depth;

	/**
	 * Lector inyectable del agregado de catálogo. Null usa `SeriesFacts`.
	 *
	 * @var callable(int):(?array)|null
	 */
	private $catalog_facts_reader;

	/** Shared customer ownership reader. */
	private CustomerCollection $customer_collection;

	/**
	 * @param callable(int):(?array)|null $catalog_facts_reader Lector para tests.
	 */
	public function __construct(
		Settings $settings,
		EligibilityGuard $guard,
		VolumeTierResolver $tiers,
		CartSeriesCollector $collector,
		SeriesDepth $depth,
		?callable $catalog_facts_reader = null,
		?CustomerCollection $customer_collection = null
	) {
		$this->settings             = $settings;
		$this->guard                = $guard;
		$this->tiers                = $tiers;
		$this->collector            = $collector;
		$this->depth                = $depth;
		$this->catalog_facts_reader = $catalog_facts_reader;
		$this->customer_collection  = $customer_collection ?? new CustomerCollection();
	}

	/**
	 * option → de fábrica, para CopyManager (exposición y saneado obligatorio).
	 *
	 * @return array<string, string>
	 */
	public static function copy_defaults(): array {
		return array(
			self::OPTION_OFFER_HOOK   => self::DEFAULT_OFFER_HOOK,
			self::OPTION_RULES_TOGGLE => self::DEFAULT_RULES_TOGGLE,
		);
	}

	/**
	 * El gancho del dueño con el porcentaje máximo en su hueco, ya escapado.
	 *
	 * Sin el marcador se pinta tal cual lo escribió («Descuento por pack» es una
	 * decisión legítima); el número nunca se añade por su cuenta al final.
	 *
	 * @param int $max_pct Descuento del tramo más alto.
	 */
	public static function offer_hook( int $max_pct ): string {
		return str_replace(
			self::PCT_MARKER,
			(string) max( 0, $max_pct ),
			esc_html( Text::one_line_option( self::OPTION_OFFER_HOOK, self::DEFAULT_OFFER_HOOK ) )
		);
	}

	/** Registra render, controles por card, assets y el estado personal diferido. */
	public function register(): void {
		// 40: después del toolbar (cierra en 35), chips (36) y empty-state (37).
		// Antes de que nada consulte el catálogo: el enlace viejo tiene que
		// llegar a la URL que sí devuelve tomos, no a una rejilla vacía.
		add_action( 'template_redirect', array( $this, 'redirect_legacy_stock_filter' ), 1 );
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render' ), 40 );
		// En cero resultados `before_shop_loop` no dispara: queda entre count (12)
		// y los chips/empty-state del buscador (16/17).
		add_action( 'woocommerce_no_products_found', array( $this, 'render' ), 15 );
		// Fuera del link de la card (WC cierra en 5), después de wishlist/quick-view
		// (6/7) y antes del add-to-cart nativo (10).
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_volume_selector' ), 8 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );

		add_action( 'wp_ajax_' . self::ACTION_COLLECTION_STATE, array( $this, 'handle_collection_state' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION_COLLECTION_STATE, array( $this, 'handle_collection_state' ) );
		add_action( 'wp_ajax_' . self::ACTION_ADD_SERIES, array( $this, 'handle_add_series_selection' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION_ADD_SERIES, array( $this, 'handle_add_series_selection' ) );

		add_filter( 'litespeed_ucss_whitelist', array( $this, 'allowlist_ucss_selectors' ), 20 );
	}

	/**
	 * Clases condicionales que UCSS no debe podar al muestrear otra serie.
	 *
	 * @param array<int, string> $selectors Selectores acumulados.
	 * @return array<int, string>
	 */
	public function allowlist_ucss_selectors( array $selectors ): array {
		$own = array(
			'.akb-sb-landing',
			'.akb-sb-landing--sin-descuento',
			'.akb-sb-landing__head',
			'.akb-sb-landing__copy',
			'.akb-sb-landing__title',
			'.akb-sb-landing__title-serie',
			'.akb-sb-landing__pack-counter',
			'.akb-sb-landing__pack-counter-pill',
			'.akb-sb-landing__pack-progress-meter',
			'.akb-sb-landing__pack-progress-bar',
			'.akb-sb-landing__tier',
			'.akb-sb-landing__rules',
			'.akb-sb-landing__rules-toggle',
			'.akb-sb-landing__hook',
			'.akb-sb-landing__rules-label',
			'.akb-sb-landing__rules-body',
			'.akb-sb-landing__cta',
			'.akb-sb-landing__filters',
			'.akb-sb-landing__filter',
			'.akb-sb-landing__panel',
			'.akb-sb-landing__close',
			'.akb-sb-landing__collection',
			'.akb-sb-landing__summary',
			'.akb-sb-landing__add',
			'.akb-sb-landing__clear',
			'.akb-sb-landing__message',
			'.akb-sb-card-select',
			'.akb-sb-card-select__box',
			'.akb-sb-card-select__text',
			'.akb-sb-card-owned',
		);

		return array_values( array_unique( array_merge( $selectors, $own ) ) );
	}

	/** Encola el asset en toda landing de serie que tenga catálogo comprobable. */
	public function enqueue_assets(): void {
		// Si el módulo está deshabilitado en configuración, se suprime por completo
		// el selector para mantener paridad entre la ficha y la landing.
		if ( ! $this->settings->is_enabled() ) {
			return;
		}

		$term = $this->queried_series();
		if ( null === $term ) {
			return;
		}

		$counts = $this->catalog_counts( $term );
		if ( null === $counts || $counts['tomos'] <= 0 ) {
			return;
		}

		wp_enqueue_style(
			'akb-series-bundles',
			AKIBARA_BUSINESS_URL . 'assets/css/series-bundles.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/series-bundles.css' )
		);

		wp_enqueue_script(
			'akb-series-bundles',
			AKIBARA_BUSINESS_URL . 'assets/js/series-bundles.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/series-bundles.js' ),
			true
		);

		// `defer` explícito. El script ya iba al footer, pero un `<script>` sin
		// atributo detiene el parser justo donde aparece, y este pesa 49 KB. Con
		// `defer` se descarga en paralelo y se ejecuta al terminar el parseo, sin
		// bloquear nada. Es seguro: el objeto `akbSbLanding` lo imprime
		// `wp_localize_script()` en un inline que corre durante el parseo, o sea
		// antes; y el propio script arranca contra `DOMContentLoaded`, que un
		// script diferido siempre precede.
		wp_script_add_data( 'akb-series-bundles', 'strategy', 'defer' );

		wp_localize_script(
			'akb-series-bundles',
			'akbSbLanding',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'addAction'        => self::ACTION_ADD_SERIES,
				'addNonce'         => wp_create_nonce( self::NONCE_ADD_SERIES ),
				'collectionAction' => self::ACTION_COLLECTION_STATE,
				'cartUrl'          => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ),
				'loginUrl'         => NavigationLinks::account_url_returning_here(),
				'maxSelection'     => BuilderWidget::MAX_ADD_PER_REQUEST,
				'i18n'             => array(
					'empty'          => 'Elige al menos un tomo.',
					'adding'         => 'Agregando tus tomos…',
					'added'          => '¡Listo! Agregamos tu selección al carrito.',
					'partial'        => 'Agregamos %1$d de %2$d tomos. Los que no se pudieron agregar siguen seleccionados para que lo intentes otra vez.',
					// Sin respuesta que leer (la red, un 500): el texto del dueño
					// para un fallo sin motivo, el mismo de «Arma tu pack» en la ficha.
					'error'          => SelectionCart::error_text(),
					'collectionError' => 'No pudimos revisar tus compras ahora. Igual puedes elegir los tomos manualmente.',
				),
			)
		);
	}

	/** Imprime el riel una sola vez. */
	public function render(): void {
		// Si el módulo está deshabilitado en configuración, se suprime por completo
		// el selector para mantener paridad entre la ficha y la landing.
		if ( ! $this->settings->is_enabled() ) {
			return;
		}

		if ( $this->rendered ) {
			return;
		}

		$html = $this->build_html();
		if ( '' === $html ) {
			return;
		}

		$this->rendered = true;
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- construido y escapado internamente.
		echo $html;
	}

	/**
	 * Control progresivo de una card. Oculto sin JS: la card conserva su CTA WC.
	 */
	public function render_volume_selector(): void {
		// Si el módulo está deshabilitado en configuración, se suprime por completo
		// el selector para mantener paridad entre la ficha y la landing.
		if ( ! $this->settings->is_enabled() ) {
			return;
		}

		global $product;

		$term = $this->queried_series();
		if ( null === $term || ! $product instanceof WC_Product ) {
			return;
		}

		$term_id    = (int) $term->term_id;
		$product_id = (int) $product->get_id();
		$membership = $this->collector->resolve_series_membership( $product_id, $term_id );
		if ( null === $membership ) {
			return;
		}

		$status = StockStatus::of( $product );
		if ( ! isset( StockStatus::LABELS[ $status ] ) ) {
			$status = StockStatus::OUT_OF_STOCK;
		}

		$can_add           = null !== $this->collector->resolve_catalog_series( $product, $term_id );
		$discount_eligible = null !== $this->collector->resolve_eligible_series_for_term( $product, $term_id );
		$current  = max( 0.0, (float) $product->get_price() );
		$regular = max( 0.0, (float) $product->get_regular_price( 'edit' ) );
		if ( $regular <= 0 ) {
			$regular = $current;
		}

		// Palabra por tomo INDIVIDUAL → estado comercial (distingue «A pedido»
		// de «Preventa»); StockStatus::label queda para los chips agregados.
		$label = $can_add ? 'Seleccionar' : Availability::label_of( $product );
		?>
		<label class="akb-sb-card-select<?php echo $can_add ? '' : ' is-disabled'; ?>" data-akb-sb-card-selector data-status="<?php echo esc_attr( $status ); ?>" hidden>
			<input
				type="checkbox"
				value="<?php echo esc_attr( (string) $product_id ); ?>"
				data-akb-sb-landing-vol
				data-term-id="<?php echo esc_attr( (string) $term_id ); ?>"
				data-price="<?php echo esc_attr( (string) $current ); ?>"
				data-regular-price="<?php echo esc_attr( (string) $regular ); ?>"
				data-title="<?php echo esc_attr( (string) $product->get_name() ); ?>"
				data-status="<?php echo esc_attr( $status ); ?>"
				data-discount-eligible="<?php echo $discount_eligible ? '1' : '0'; ?>"
				aria-label="<?php echo esc_attr( $can_add ? 'Seleccionar ' . $product->get_name() : $label . ': ' . $product->get_name() ); ?>"
				<?php disabled( ! $can_add ); ?>
			>
			<span class="akb-sb-card-select__box" aria-hidden="true"></span>
			<span class="akb-sb-card-select__text"><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	/**
	 * Agrega una selección de UNA serie sin exigir ni conceder BYP desde aquí.
	 *
	 * Es un endpoint público igual que el add-to-cart nativo: no corresponde una
	 * capability de administración. La autorización real es por producto y se
	 * rehace en cada request (serie exacta, simple, comprable, stock/preventa).
	 * El nonce viaja en HTML cacheable y para invitados no es por-sesión; por eso
	 * se complementa con mismo-origen, rate-limit y un techo de 300 IDs. El motor
	 * BYP sigue siendo el único que decide y materializa descuentos en el carrito.
	 *
	 * La operación es deliberadamente parcial: `WC_Cart::add_to_cart()` puede
	 * rechazar un tomo entre validación y escritura por stock/hook concurrente.
	 * No existe transacción de carrito segura para revertir lo ya agregado; la
	 * respuesta enumera `added_ids` y `rejected_ids` (los que no entran por un
	 * motivo que reintentar no cambia: ya en el carrito o agotados), y el
	 * cliente deja marcado sólo el resto, que es lo que un reintento podría
	 * sumar. El motivo y su texto los decide `SelectionCart`, igual que en el
	 * armador de la ficha.
	 */
	public function handle_add_series_selection(): void {
		check_ajax_referer( self::NONCE_ADD_SERIES, 'nonce' );

		if ( ! PublicBatchRequest::origin_allowed() ) {
			wp_send_json( array( 'success' => false, 'message' => SelectionCart::error_text() ) );
			return;
		}
		if ( RateLimit::per_minute( 'series_selection_add', self::ADD_RATE_PER_MINUTE ) ) {
			wp_send_json( array( 'success' => false, 'message' => 'Demasiados intentos seguidos. Espera un momento y vuelve a intentarlo.' ) );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- `check_ajax_referer()` es la primera instrucción del handler.
		$term_id = isset( $_POST['series_id'] ) ? absint( wp_unslash( $_POST['series_id'] ) ) : 0;
		$term    = $term_id > 0 ? get_term( $term_id, self::TAXONOMY ) : null;
		if ( ! $term instanceof WP_Term || self::TAXONOMY !== (string) $term->taxonomy ) {
			wp_send_json( array( 'success' => false, 'message' => 'Serie no disponible.' ) );
			return;
		}

		$request = PublicBatchRequest::posted_product_ids( BuilderWidget::MAX_ADD_PER_REQUEST );
		$ids     = $request['ids'];
		if ( $request['truncated'] ) {
			wp_send_json( array( 'success' => false, 'message' => 'Puedes agregar hasta 300 tomos por vez. Reduce la selección e inténtalo de nuevo.' ) );
			return;
		}
		if ( empty( $ids ) ) {
			wp_send_json( array( 'success' => false, 'message' => 'Selecciona al menos un tomo.' ) );
			return;
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart ) {
			wp_send_json( array( 'success' => false, 'message' => 'Carrito no disponible.' ) );
			return;
		}

		$validated = array();
		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof WC_Product && null !== $this->collector->resolve_catalog_series( $product, $term_id ) ) {
				$validated[] = $product_id;
			}
		}

		SelectionCart::send( $cart, $ids, $validated );
	}

	/**
	 * Estado personal de colección. Es read-only y nunca se ejecuta al cachear HTML.
	 */
	public function handle_collection_state(): void {
		// No se embebe un nonce de usuario en esta landing cacheable: uno emitido
		// para el visitante que calienta LiteSpeed rompería la lectura a los demás.
		// Este endpoint sólo devuelve la colección del usuario de la sesión (nunca
		// acepta user_id), el navegador no puede leerlo cross-origin y además exige
		// señal exacta de mismo origen + rate-limit. No requiere capability:
		// consultar la colección propia es una acción de cliente, no administrativa.
		if ( ! PublicBatchRequest::origin_allowed() ) {
			wp_send_json( array( 'success' => false, 'message' => 'No pudimos revisar la colección.' ) );
			return;
		}
		if ( RateLimit::per_minute( 'series_collection_state', self::COLLECTION_RATE_PER_MINUTE ) ) {
			wp_send_json( array( 'success' => false, 'message' => 'Espera un momento antes de volver a revisar tu colección.' ) );
			return;
		}

		if ( ! is_user_logged_in() ) {
			// SIN `login_url`. Este handler corre sobre `admin-ajax.php`, así que
			// el destino de vuelta que se resolvía aquí era la propia URL del
			// endpoint: el invitado que pulsaba «Inicia sesión» aterrizaba en
			// `/wp-admin/admin-ajax.php` en vez de en la landing. El enlace ya
			// trae el destino correcto desde el render de la página, que es el
			// único momento en que `REQUEST_URI` ES la landing.
			wp_send_json(
				array(
					'success'       => true,
					'authenticated' => false,
				)
			);
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- endpoint read-only cache-safe; same-origin + rate-limit arriba, sin user_id aceptado.
		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0;
		$term    = $term_id > 0 ? get_term( $term_id, self::TAXONOMY ) : null;
		if ( ! $term instanceof WP_Term || self::TAXONOMY !== (string) $term->taxonomy ) {
			wp_send_json( array( 'success' => false, 'message' => 'Serie no disponible.' ) );
			return;
		}

		$candidate_ids = get_objects_in_term( $term_id, self::TAXONOMY );
		if ( is_wp_error( $candidate_ids ) || ! is_array( $candidate_ids ) ) {
			wp_send_json( array( 'success' => false, 'message' => 'No pudimos revisar la colección.' ) );
			return;
		}

		// Mismo universo que el selector y el POST de compra: jamás contar ni
		// revelar un post oculto, variable, no comprable o sin stock. Preventa sí
		// pertenece al universo porque `resolve_catalog_series()` la acepta como el
		// CTA individual de WooCommerce. Mantener esta lista como única entrada de
		// CustomerCollection evita que `owned_ids` filtre IDs que la landing no debe
		// exponer.
		$catalog_ids = $this->collector->resolve_catalog_product_ids( $term_id, $candidate_ids, self::MAX_CATALOG_IDS );

		$user_id   = (int) get_current_user_id();
		$owned_ids = $this->customer_collection->owned_among( $user_id, $catalog_ids );
		if ( $user_id > 0 && class_exists( '\Akibara\Business\Modules\AccountCollection\CollectionResolver' ) ) {
			$manual_ids = \Akibara\Business\Modules\AccountCollection\CollectionResolver::manual_owned_product_ids( $user_id );
			if ( array() !== $manual_ids ) {
				$owned_ids = array_values( array_unique( array_merge( $owned_ids, $manual_ids ) ) );
			}
		}
		$owned_ids = array_values( array_intersect( $catalog_ids, $owned_ids ) );

		// El recuento se hace en TOMOS y no en productos, porque eso es lo que
		// dice el mensaje que sale de acá: «De los N tomos comprables ahora, ya
		// tienes M». Restar por `product_id` daba la respuesta equivocada en
		// cuanto un número de tomo tiene dos productos —una edición especial
		// dentro de su serie—: al cliente que la tenía completa le encendía «te
		// falta 1 tomo» y le ofrecía otra vez el contenido que ya compró.
		//
		// Lo que NO cambia: la variante se sigue vendiendo y sigue sumando al
		// tramo de descuento, porque son dos libros que el cliente paga. Esa es
		// una decisión comercial del dueño, no del código, y vive en
		// `DiscountEngine`, que cuenta por `product_id` a propósito.
		$slots     = self::volume_slots(
			$catalog_ids,
			array_fill_keys( $owned_ids, true ),
			$this->catalog_volume_map( $catalog_ids )
		);
		$missing   = $slots['missing'];
		$near_goal = count( $missing ) > 0 && count( $missing ) <= BuilderWidget::COMPLETE_MAX_MISSING;

		$missing_items = array();
		if ( $near_goal ) {
			foreach ( $missing as $product_id ) {
				$item = $this->collection_item( $product_id, $term_id );
				if ( null !== $item ) {
					$missing_items[] = $item;
				}
			}
			$missing_items = VolumeTitle::sort_records( $missing_items );
		}

		wp_send_json(
			array(
				'success'                 => true,
				'authenticated'           => true,
				'owned_ids'               => $owned_ids,
				'owned_count'             => $slots['owned'],
				'total'                   => $slots['total'],
				'missing_count'           => count( $missing ),
				'near_goal'               => $near_goal,
				'can_complete'             => $near_goal && count( $missing_items ) === count( $missing ),
				'available_missing_count' => count( $missing_items ),
				'missing_items'           => $missing_items,
			)
		);
	}

	/**
	 * Resuelve número de tomo y variante del universo canónico de la serie.
	 *
	 * Ceba en lote posts y postmeta antes de get_the_title() y de distinguir una
	 * variante del tomo normal. Así evita consultas por producto. No carga términos
	 * que esta proyección no necesita.
	 *
	 * @param list<int> $catalog_ids Universo canónico de la serie.
	 * @return array<int, array{number:int|null, variant:bool}>
	 */
	private function catalog_volume_map( array $catalog_ids ): array {
		if ( array() === $catalog_ids ) {
			return array();
		}

		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $catalog_ids, false, true );
		}

		$map = array();
		foreach ( $catalog_ids as $product_id ) {
			$map[ $product_id ] = array(
				'number'  => VolumeTitle::extract_number( (string) get_the_title( $product_id ) ),
				'variant' => VolumeVariant::is_variant( $product_id ),
			);
		}

		return $map;
	}

	/**
	 * Reparte el universo en TOMOS: cuántos hay, cuántos tiene y cuáles faltan.
	 *
	 * Un número con dos productos —el tomo normal y su edición especial— es UN
	 * tomo: se cuenta una vez, lo tapa cualquiera de los dos y, si falta, se
	 * nombra con el TOMO NORMAL. Quién es el tomo normal lo dice la marca del
	 * dueño, no el orden: acá el universo llega en el orden de
	 * `get_objects_in_term()` (object_id ascendente) y nada impide que la
	 * variante tenga el id menor —de hecho una reedición cargada después lo
	 * tendría mayor, y una portada alternativa cargada antes, menor—. Con el
	 * desempate por orden, el botón «Seleccionar el tomo que te falta» podía
	 * meter al carrito la variante cara en lugar del tomo.
	 *
	 * Los productos sin número inequívoco (packs, integrales, `1.5`) no se
	 * colapsan: cada uno es su propio tomo, porque no hay posición que compartir.
	 * Mismo criterio que `AccountCollection\CollectionResolver`.
	 *
	 * Pura y estática a propósito: es la regla que decide un mensaje al cliente
	 * y se prueba sin WooCommerce.
	 *
	 * @param list<int>                                    $catalog_ids  Universo canónico, ya ordenado.
	 * @param array<int,true>                              $owned_lookup Productos que el cliente tiene.
	 * @param array<int, array{number:int|null,variant:bool}> $volumes   Número y marca por producto.
	 * @return array{total:int, owned:int, missing:list<int>}
	 */
	private static function volume_slots( array $catalog_ids, array $owned_lookup, array $volumes ): array {
		$slots = array();

		foreach ( $catalog_ids as $product_id ) {
			$number  = $volumes[ $product_id ]['number'] ?? null;
			$variant = true === ( $volumes[ $product_id ]['variant'] ?? false );
			$key     = is_int( $number ) && $number > 0 ? 'v' . $number : 'p' . $product_id;

			if ( ! isset( $slots[ $key ] ) ) {
				// `variant` arranca en true para que el PRIMER candidato entre
				// siempre: lo que la condición de abajo compara es «el que tengo
				// guardado es una variante y éste no».
				$slots[ $key ] = array( 'owned' => false, 'id' => 0, 'variant' => true );
			}

			if ( isset( $owned_lookup[ $product_id ] ) ) {
				$slots[ $key ]['owned'] = true;
			} elseif ( 0 === $slots[ $key ]['id'] || ( true === $slots[ $key ]['variant'] && ! $variant ) ) {
				$slots[ $key ]['id']      = $product_id;
				$slots[ $key ]['variant'] = $variant;
			}
		}

		$owned   = 0;
		$missing = array();
		foreach ( $slots as $slot ) {
			if ( true === $slot['owned'] ) {
				++$owned;
				continue;
			}
			if ( $slot['id'] > 0 ) {
				$missing[] = $slot['id'];
			}
		}

		return array(
			'total'   => count( $slots ),
			'owned'   => $owned,
			'missing' => $missing,
		);
	}

	/**
	 * DTO de un faltante comprable hoy, o null.
	 *
	 * @return array{id:int,title:string,current:float,regular:float,status:string,discount_eligible:bool,volume_number:?int}|null
	 */
	private function collection_item( int $product_id, int $term_id ): ?array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		if ( null === $this->collector->resolve_catalog_series( $product, $term_id ) ) {
			return null;
		}

		$title   = (string) $product->get_name();
		$current = max( 0.0, (float) $product->get_price() );
		$regular = max( 0.0, (float) $product->get_regular_price( 'edit' ) );
		if ( $regular <= 0 ) {
			$regular = $current;
		}

		return array(
			'id'            => $product_id,
			'title'         => $title,
			'current'       => $current,
			'regular'       => $regular,
			'status'        => StockStatus::of( $product ),
			'discount_eligible' => null !== $this->collector->resolve_eligible_series_for_term( $product, $term_id ),
			'volume_number' => VolumeTitle::extract_number( $title ),
		);
	}

	/** Oferta memoizada por término. */
	private function resolve_offer( int $term_id ): ?array {
		if ( array_key_exists( $term_id, $this->offer_memo ) ) {
			return $this->offer_memo[ $term_id ];
		}

		return $this->offer_memo[ $term_id ] = $this->build_offer( $term_id );
	}

	/**
	 * @return array{tiers:array<int, array{min:int, pct:int}>, entry:array{url:string, title:string}|null}|null
	 */
	private function build_offer( int $term_id ): ?array {
		if ( ! $this->settings->is_enabled() ) {
			return null;
		}

		$cfg = $this->collector->config_for_term( $term_id );
		if ( null === $cfg ) {
			return null;
		}

		$tiers = array();
		foreach ( $cfg['tiers'] as $tier ) {
			if ( is_array( $tier ) && isset( $tier['min'], $tier['pct'] ) && (int) $tier['pct'] > 0 ) {
				$tiers[] = array(
					'min' => (int) $tier['min'],
					'pct' => (int) $tier['pct'],
				);
			}
		}
		if ( empty( $tiers ) ) {
			return null;
		}

		$tiers   = $this->tiers->coalesce_equal_pct_tiers( $this->tiers->sort_tiers( $tiers ) );
		$highest = max( array_column( $tiers, 'min' ) );
		$universe = $this->walk_eligible_volumes( $term_id, (int) $highest );
		if ( null === $universe ) {
			return null;
		}

		$eligible = $universe['count'];
		$tiers    = array_values(
			array_filter(
				$tiers,
				static fn( array $tier ): bool => $tier['min'] <= $eligible
			)
		);

		return empty( $tiers )
			? null
			: array(
				'tiers' => $tiers,
				'entry' => $universe['entry'],
			);
	}

	/** Construye el riel cache-safe. */
	private function build_html(): string {
		$term = $this->queried_series();
		if ( null === $term ) {
			return '';
		}

		$term_id = (int) $term->term_id;
		$offer   = $this->resolve_offer( $term_id );
		$counts  = $this->catalog_counts( $term );
		if ( null === $counts || $counts['tomos'] <= 0 ) {
			return '';
		}

		$tiers       = null === $offer ? array() : $offer['tiers'];
		$entry       = null === $offer ? null : $offer['entry'];
		$has_offer   = ! empty( $tiers );
		$panel_id    = 'akb-sb-landing-panel-' . $term_id;
		$title_id    = 'akb-sb-landing-title-' . $term_id;
		$active      = $this->current_stock_filter();
		$action_url  = $this->landing_url( $term );
		$hidden      = $this->query_string_fields();
		$tiers_json  = wp_json_encode( array_values( $tiers ) );
		$max_pct     = $has_offer ? max( array_column( $tiers, 'pct' ) ) : 0;
		$start_url   = null !== $entry ? $entry['url'] : $this->first_product_url( $term_id );
		$start_url   = '' !== $start_url ? $start_url : $action_url;
		/*
		 * Los valores corresponden al vocabulario comercial publicado por la faceta
		 * (`instock` / `a_pedido` / `outofstock`), garantizando coincidencia con el índice
		 * de búsqueda y los filtros de la tienda.
		 */
		$filter_rows = array(
			''                       => array( 'label' => 'Todos', 'count' => $counts['tomos'] ),
			StockStatus::IN_STOCK    => array( 'label' => 'Disponibles', 'count' => $counts['instock'] ),
			Availability::A_PEDIDO   => array( 'label' => Availability::label_for_state( Availability::A_PEDIDO ), 'count' => $counts['preventa'] ),
			StockStatus::OUT_OF_STOCK => array( 'label' => 'Agotados', 'count' => $counts['agotados'] ),
		);

		ob_start();
		?>
		<section
			id="<?php echo esc_attr( self::FRAGMENT ); ?>"
			class="akb-sb-landing<?php echo $has_offer ? '' : ' akb-sb-landing--sin-descuento'; ?>"
			data-akb-sb-landing
			data-term-id="<?php echo esc_attr( (string) $term_id ); ?>"
			data-tiers="<?php echo esc_attr( (string) $tiers_json ); ?>"
			aria-labelledby="<?php echo esc_attr( $title_id ); ?>"
		>
			<?php
			/*
			 * Contenedor unificado: estructura compacta que integra el título y gancho
			 * de descuento (`__copy`), los chips de disponibilidad y el botón de selección.
			 * En móvil comparte filas para no desplazar el primer tomo bajo el pliegue;
			 * en escritorio se despliega en una sola fila sin alterar el orden accesible.
			 */
			?>
			<div class="akb-sb-landing__head" id="<?php echo esc_attr( self::FILTERS_FRAGMENT ); ?>">
				<div class="akb-sb-landing__copy">
					<?php
					/*
					 * El nombre de la serie va en su propio `<span>`, que en móvil sólo lee el
					 * lector de pantalla: ahí el nombre ya es el titular de la cabecera, un
					 * palmo más arriba, y con un nombre largo partía este título en dos líneas
					 * (revisión B3-2-R4: «Biblioteca Vertigo. John Constantine: Hellblazer»,
					 * 8 de 110 series en 390 px). El título accesible sigue completo.
					 */
					?>
					<h2 class="akb-sb-landing__title" id="<?php echo esc_attr( $title_id ); ?>">Completa tu colección<span class="akb-sb-landing__title-serie"> de <?php echo esc_html( (string) $term->name ); ?></span></h2>
					<?php if ( $has_offer ) : ?>
						<div class="akb-sb-landing__pack-counter" data-akb-sb-pack-counter hidden aria-live="polite">
							<span class="akb-sb-landing__pack-counter-pill" data-akb-sb-pack-pill></span>
							<div class="akb-sb-landing__pack-progress-meter" aria-hidden="true">
								<span class="akb-sb-landing__pack-progress-bar" data-akb-sb-pack-bar style="width: 0%;"></span>
							</div>
						</div>
						<details class="akb-sb-landing__rules">
							<summary class="akb-sb-landing__rules-toggle"><strong class="akb-sb-landing__hook"><?php echo self::offer_hook( (int) $max_pct ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- `offer_hook()` escapa el copy y sólo inserta un entero. ?></strong> <span class="akb-sb-landing__rules-label"><?php echo esc_html( Text::one_line_option( self::OPTION_RULES_TOGGLE, self::DEFAULT_RULES_TOGGLE ) ); ?></span></summary>
							<div class="akb-sb-landing__rules-body">
								<div class="akb-sb-landing__tiers" aria-label="Descuentos por cantidad">
									<?php foreach ( $tiers as $tier ) : ?>
										<span class="akb-sb-landing__tier"><?php echo esc_html( $this->tiers->tier_label( $tier['min'], $tier['pct'] ) ); ?></span>
									<?php endforeach; ?>
								</div>
								<p class="akb-sb-landing__intro">Elige varios tomos y obtén hasta <?php echo (int) $max_pct; ?>% de descuento sobre precio de lista. Se aplica solo al sumarlos al carrito. Siempre se respeta el mejor precio.</p>
								<p class="akb-sb-landing__only">Cada tomo distinto cuenta una vez; el descuento aplica a una unidad de cada tomo elegible.</p>
								<?php if ( ! $this->settings->allows_backorder() ) : ?>
									<p class="akb-sb-landing__only">Cuentan los tomos disponibles ahora; los a pedido no. Puedes agregarlos juntos igual.</p>
								<?php endif; ?>
							</div>
						</details>
					<?php endif; ?>
					<?php
					/*
					 * Sin descuento no hay condiciones que explicar, y la frase que iba aquí
					 * («Selecciona varios tomos y agrégalos juntos al carrito en un solo
					 * paso») repetía lo que ya dicen el botón «Elegir tomos» y las
					 * instrucciones del panel que abre. En móvil eran dos líneas (36 px)
					 * entre la cabecera y la primera portada, en las 49 series sin descuento
					 * (revisión B3-2-R4: con un nombre largo, el riel medía 167 px y la
					 * portada quedaba bajo la barra inferior).
					 */
					?>
				</div>
				<form class="akb-sb-landing__filters" method="get" action="<?php echo esc_url( $action_url . '#' . self::FILTERS_FRAGMENT ); ?>" aria-label="Filtrar tomos por disponibilidad">
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper WC devuelve inputs escapados. ?>
					<?php echo $hidden; ?>
					<div class="akb-sb-landing__filter-list" role="group" aria-label="Disponibilidad">
						<?php foreach ( $filter_rows as $status => $row ) : ?>
							<?php $pressed = $active === $status; ?>
							<?php
							/*
							 * Un filtro con CERO tomos no es una opción: es ruido que además
							 * empuja la fila fuera de pantalla. En móvil, «Agotados 0»
							 * dejaba 92px de la fila detrás de un arrastre horizontal, así
							 * que un filtro con tomos de verdad quedaba escondido detrás de
							 * uno que no lleva a ninguna parte.
							 *
							 * Se conserva si es el filtro ACTIVO: ahí sí hay que decirle al
							 * cliente dónde está parado, aunque el resultado esté vacío.
							 */
							if ( 0 === (int) $row['count'] && ! $pressed ) {
								continue;
							}
							?>
							<button
								type="submit"
								class="akb-sb-landing__filter<?php echo $pressed ? ' is-active' : ''; ?>"
								<?php if ( '' !== $status ) : ?>name="<?php echo esc_attr( self::STOCK_FILTER_PARAM ); ?>" value="<?php echo esc_attr( $status ); ?>"<?php endif; ?>
								aria-pressed="<?php echo $pressed ? 'true' : 'false'; ?>"
								<?php disabled( 0 === (int) $row['count'] && ! $pressed ); ?>
							>
								<span><?php echo esc_html( (string) $row['label'] ); ?></span>
								<?php
								// «productos» y no «tomos»: este número cuenta las CARDS que
								// quedan al pulsar el filtro, y una serie con dos ediciones
								// del mismo tomo tiene más cards que tomos. El bloque de datos
								// de más arriba sí habla en tomos; decir «tomos» en los dos
								// sitios dejaba dos cifras distintas con la misma palabra en
								// la misma página. La cifra visible no lleva unidad, así que
								// esto sólo cambia lo que oye un lector de pantalla.
								?>
								<span class="akb-sb-landing__filter-count" aria-label="<?php echo esc_attr( (string) $row['count'] . ' productos' ); ?>"><?php echo (int) $row['count']; ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				</form>
				<button
					type="button"
					class="akb-sb-landing__cta"
					data-akb-sb-landing-start
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $panel_id ); ?>"
				><span data-akb-sb-landing-start-label>Elegir tomos</span><span class="screen-reader-text"> de <?php echo esc_html( (string) $term->name ); ?></span></button>
			</div>

			<div class="akb-sb-landing__panel" id="<?php echo esc_attr( $panel_id ); ?>" data-akb-sb-landing-panel hidden>
				<?php
				// La indicación de vista rápida se renderiza condicionalmente para
				// viewports que soportan su visualización interactiva.
				?>
				<p class="akb-sb-landing__instructions">Toca una tarjeta —portada, título o «Seleccionar»— para marcar o desmarcar ese tomo. <span class="akb-sb-landing__qv-hint">Usa «<?php echo esc_html( QuickView::label() ); ?>» para ver sus detalles. </span>Tu selección se conserva al cambiar de página.</p>
				<div class="akb-sb-landing__collection" data-akb-sb-landing-collection hidden>
					<p data-akb-sb-landing-collection-status></p>
					<a href="<?php echo esc_url( NavigationLinks::account_url_returning_here() ); ?>" rel="nofollow" data-akb-sb-landing-login hidden>Inicia sesión para armar tu pack solo con los que te faltan</a>
					<button type="button" data-akb-sb-landing-complete hidden></button>
				</div>
				<?php // data-akb-sb-landing-summary identifica el resumen verificable de la promoción.?>
				<div class="akb-sb-landing__summary" data-akb-sb-landing-summary aria-live="polite" aria-atomic="true">
					<button type="button" class="akb-sb-landing__close" data-akb-sb-landing-close aria-label="Cerrar selección"><span aria-hidden="true">×</span></button>
					<div class="akb-sb-landing__totals">
						<strong data-akb-sb-landing-count>0 tomos seleccionados</strong>
						<span data-akb-sb-landing-total>Total estimado del pack: $0</span>
						<span data-akb-sb-landing-discount><?php echo esc_html( $has_offer ? 'Selecciona tomos para ver tu descuento.' : 'Selecciona los tomos que quieres comprar.' ); ?></span>
					</div>
					<div class="akb-sb-landing__actions">
						<button type="button" class="akb-sb-landing__clear" data-akb-sb-landing-clear disabled>Limpiar</button>
						<button type="button" class="akb-sb-landing__add" data-akb-sb-landing-add disabled>Agregar seleccionados</button>
					</div>
					<p class="akb-sb-landing__message" data-akb-sb-landing-message data-akb-sb-msg-class="akb-sb-landing__message" role="status" aria-live="polite"></p>
				</div>
			</div>
			<noscript><p class="akb-sb-landing__noscript">La selección múltiple necesita JavaScript. Puedes usar los botones de cada tomo o <a href="<?php echo esc_url( $start_url ); ?>">abrir una ficha para comprar</a>.</p></noscript>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Conteos canónicos de la serie, o null si no se pueden comprobar.
	 *
	 * @return array{tomos:int,instock:int,preventa:int,agotados:int}|null
	 */
	private function catalog_counts( WP_Term $term ): ?array {
		$term_taxonomy_id = isset( $term->term_taxonomy_id ) ? (int) $term->term_taxonomy_id : 0;
		if ( $term_taxonomy_id <= 0 ) {
			return null;
		}
		if ( array_key_exists( $term_taxonomy_id, $this->counts_memo ) ) {
			return $this->counts_memo[ $term_taxonomy_id ];
		}

		if ( null !== $this->catalog_facts_reader ) {
			$facts = ( $this->catalog_facts_reader )( $term_taxonomy_id );
		} elseif ( class_exists( SeriesFacts::class ) ) {
			$facts = ( new SeriesFacts() )->catalog_facts( $term_taxonomy_id );
		} else {
			$facts = null;
		}

		if ( ! is_array( $facts ) || ! isset( $facts['tomos'], $facts['instock'], $facts['preventa'], $facts['agotados'] ) ) {
			return $this->counts_memo[ $term_taxonomy_id ] = null;
		}

		// Los chips etiquetan la REJILLA, así que cuentan PRODUCTOS: si una serie
		// tiene una edición especial del mismo tomo, la card está ahí y el chip
		// tiene que contarla. Las claves sin prefijo de `SeriesFacts` cuentan
		// tomos —una variante no es un tomo más— y son las que publica su bloque
		// de datos. El `??` deja funcionar a un lector inyectado que sólo entregue
		// la familia de tomos, y a un transient de la forma anterior.
		return $this->counts_memo[ $term_taxonomy_id ] = array(
			'tomos'     => max( 0, (int) ( $facts['productos'] ?? $facts['tomos'] ) ),
			'instock'   => max( 0, (int) ( $facts['productos_instock'] ?? $facts['instock'] ) ),
			'preventa'  => max( 0, (int) ( $facts['productos_preventa'] ?? $facts['preventa'] ) ),
			'agotados'  => max( 0, (int) ( $facts['productos_agotados'] ?? $facts['agotados'] ) ),
		);
	}

	/** Estado de filtro único; `mixed` cuando la faceta trae varios valores. */
	private function current_stock_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- estado visual GET sin escritura; cada valor pasa por sanitize_key debajo.
		$raw = wp_unslash( $_GET[ self::STOCK_FILTER_PARAM ] ?? '' );
		$raw = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$raw = array_values(
			array_filter(
				array_map(
					static fn( $value ): string => sanitize_key( (string) $value ),
					$raw
				)
			)
		);
		// Mismo vocabulario que emiten los chips. Se aceptan también los valores
		// internos de WooCommerce para no romper los enlaces ya compartidos.
		$known = array( StockStatus::IN_STOCK, Availability::A_PEDIDO, StockStatus::OUT_OF_STOCK, StockStatus::ON_BACKORDER );
		$raw   = array_values( array_intersect( $raw, $known ) );

		if ( 1 !== count( $raw ) ) {
			return empty( $raw ) ? '' : 'mixed';
		}

		return self::canonical_stock_value( $raw[0] );
	}

	/**
	 * El valor de faceta con el que se sirve realmente el catálogo.
	 *
	 * Los chips emitieron `onbackorder` —el estado interno de WooCommerce—
	 * desde que se separaron «a pedido» y «preventa» en 1.109.0. El índice sólo
	 * publica el vocabulario comercial, así que ese literal devuelve CERO tomos
	 * en todas las series. Los enlaces con él siguen circulando: se compartieron
	 * durante meses.
	 *
	 * Es la ÚNICA traducción del literal viejo en este módulo. La usan las dos
	 * mitades: la redirección que manda el enlace viejo a su URL buena y el
	 * resaltado del chip, que así queda correcto incluso si la redirección no
	 * llegó a emitirse.
	 *
	 * @param string $valor Valor saneado de la faceta.
	 * @return string
	 */
	private static function canonical_stock_value( string $valor ): string {
		return StockStatus::ON_BACKORDER === $valor ? Availability::A_PEDIDO : $valor;
	}

	/**
	 * Redirige consultas de disponibilidad con valores heredados (301) hacia el
	 * valor comercial canónico (`a_pedido`) para consolidar URLs y asegurar resultados válidos.
	 */
	public function redirect_legacy_stock_filter(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parámetro de navegación; se sanea abajo y sólo se usa para comparar.
		$crudo = wp_unslash( $_GET[ self::STOCK_FILTER_PARAM ] ?? '' );
		if ( ! is_string( $crudo ) || '' === $crudo ) {
			return;
		}

		$valor = sanitize_key( $crudo );
		if ( StockStatus::ON_BACKORDER !== $valor ) {
			return;
		}

		$destino = add_query_arg(
			self::STOCK_FILTER_PARAM,
			self::canonical_stock_value( $valor ),
			home_url( add_query_arg( array() ) )
		);

		wp_safe_redirect( $destino, 301 );
		exit;
	}

	/** Campos GET existentes, salvo stock y paginación que este control reemplaza. */
	private function query_string_fields(): string {
		if ( ! function_exists( 'wc_query_string_form_fields' ) ) {
			return '';
		}

		return (string) wc_query_string_form_fields(
			null,
			array( self::STOCK_FILTER_PARAM, 'paged', 'product-page', '_paged' ),
			'',
			false
		);
	}

	/** URL canónica del término; nunca parte de la página paginada actual. */
	private function landing_url( WP_Term $term ): string {
		$url = get_term_link( $term );

		return is_string( $url ) && '' !== $url ? $url : home_url( '/' );
	}

	/** Primer PDP real disponible como destino progresivo del CTA sin JavaScript. */
	private function first_product_url( int $term_id ): string {
		global $wp_query;

		$loop_posts = isset( $wp_query->posts ) && is_array( $wp_query->posts ) ? $wp_query->posts : array();
		foreach ( $loop_posts as $post ) {
			$product_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
			$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;
			if ( ! $product instanceof WC_Product || null === $this->collector->resolve_series_membership( $product_id, $term_id ) ) {
				continue;
			}
			$permalink = get_permalink( $product_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		$ids = $this->depth->sellable_ids( $term_id );
		if ( ! is_array( $ids ) ) {
			return '';
		}
		foreach ( $this->collector->resolve_catalog_product_ids( $term_id, $ids, 999 ) as $product_id ) {
			$permalink = get_permalink( $product_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		return '';
	}

	/** Término `pa_serie` del request. */
	private function queried_series(): ?WP_Term {
		if ( is_admin() || ! is_tax( self::TAXONOMY ) ) {
			return null;
		}

		$term = get_queried_object();
		if ( ! $term instanceof WP_Term || self::TAXONOMY !== (string) $term->taxonomy ) {
			return null;
		}

		return $term;
	}

	/**
	 * Recorre el universo term-driven hasta comprobar el tramo más alto.
	 *
	 * @return array{count:int, entry:array{url:string, title:string}|null}|null
	 */
	private function walk_eligible_volumes( int $term_id, int $cap ): ?array {
		$ids = $this->depth->sellable_ids( $term_id );
		if ( null === $ids ) {
			return null;
		}

		$count = 0;
		$entry = null;
		foreach ( array_chunk( $ids, self::HYDRATION_CHUNK ) as $chunk ) {
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $chunk, true, true );
			}

			foreach ( $chunk as $product_id ) {
				$product = $this->pack_volume( $product_id, $term_id );
				if ( null === $product ) {
					continue;
				}

				++$count;
				if ( null === $entry ) {
					$entry = $this->entry_from( $product_id, $product, $term_id );
				}
				if ( $count >= $cap && null !== $entry ) {
					return array( 'count' => $count, 'entry' => $entry );
				}
			}
		}

		return array( 'count' => $count, 'entry' => $entry );
	}

	/** Producto si los guards compartidos lo aceptan para ESTA serie. */
	private function pack_volume( int $product_id, int $term_id ): ?WC_Product {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$resolved = $this->collector->resolve_eligible_series_for_term( $product, $term_id );

		return null !== $resolved ? $product : null;
	}

	/**
	 * Fallback no-JS al builder de una ficha resoluble.
	 *
	 * @return array{url:string, title:string}|null
	 */
	private function entry_from( int $product_id, WC_Product $product, int $term_id ): ?array {
		$permalink = get_permalink( $product_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return null;
		}

		return array(
			'url'   => $permalink . '#' . BuilderWidget::TITLE_ID_PREFIX . $term_id,
			'title' => (string) $product->get_name(),
		);
	}
}
