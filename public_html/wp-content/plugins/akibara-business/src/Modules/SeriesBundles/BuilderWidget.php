<?php
/**
 * Series Bundles — BuilderWidget (Single Product).
 *
 * CAPA DE HOOKS WC. Renderiza el bloque "Arma tu pack" después del resumen de la
 * ficha de producto (`woocommerce_after_single_product_summary`) y antes de las
 * tabs nativas. Así puede ocupar el ancho completo sin comprimir el selector de
 * tomos dentro de la columna derecha ni dejar una columna de galería vacía.
 *
 * Se muestra solo si (Req 12.2–12.4):
 *   - el módulo está habilitado (`Settings::is_enabled()`),
 *   - el producto pertenece a un término `pa_serie` con Build_Your_Pack
 *     habilitado en `Settings::get_byp_config()` (el config usa `term_id` como
 *     clave), y
 *   - el producto es elegible según `EligibilityGuard::is_eligible()`.
 * Si no se cumplen, `render()` no emite NADA (sin HTML ni error).
 *
 * Encola sus assets (CSS/JS) solo en `is_product()` (Req 12.10), versionados por
 * `filemtime` para invalidar correctamente caché/CDN en cada cambio.
 *
 * La extracción y el orden natural de volúmenes se delegan al servicio compartido
 * `VolumeTitle`, evitando reglas divergentes entre el builder y otras superficies
 * comerciales.
 *
 * La decisión de render se expone como método público `should_render_for(int
 * $product_id): bool` para poder testearla de forma limpia sin reflection; el
 * helper privado `should_render(\WC_Product)` solo extrae el id y delega.
 *
 * Sin funciones i18n (Req 11.4): textos en español literal. Toda la salida se
 * escapa.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Contracts\EtaProvider;
use Akibara\Business\Modules\BackInStock\CaptureForm;
use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Utilities\RateLimit;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\FreeShippingThreshold;
use Akibara\Business\Services\NavigationLinks;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Business\Utilities\StoreTime;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class BuilderWidget {

	/** Taxonomía de serie. El literal vive en `SeriesDepth`, que es la capa sin hooks. */
	public const TAXONOMY    = SeriesDepth::TAXONOMY;

	/**
	 * Prefijo compartido del título del builder y su ancla accesible.
	 *
	 * LandingOffer enlaza directamente al selector con este identificador y el
	 * term_id. La constante conserva alineados el id, aria-labelledby y los enlaces
	 * desde las landings al cambiar el marcado.
	 */
	public const TITLE_ID_PREFIX = 'akb-sb-builder-title-';

	/**
	 * Tope de tomos a renderizar en el grid del builder. Subido a 300 para cubrir
	 * series largas completas (One Piece 106, Kochikame ~200) — el cliente debe poder
	 * elegir CUALQUIER tomo para "ponerse al día". Render-all server-side se banca a
	 * esta escala porque (a) `series_volumes()` primea caches en batch (sin N+1) y
	 * (b) la CSS usa `content-visibility:auto` para que las cards fuera de la ventana
	 * de scroll no cuesten layout/paint. Ajustable por serie con `akibara_byp_suggest_qty`.
	 */
	private const SUGGEST_QTY = 300;

	/**
	 * Tope de tomos que UN POST puede agregar al carrito.
	 *
	 * Constante propia y no `SUGGEST_QTY`: son dos límites distintos que se venían
	 * mezclando. `SUGGEST_QTY` es cuántas cards pinta el grid (costo de render);
	 * esto es cuántos `add_to_cart()` puede disparar un request público (costo de
	 * escritura). Atarlos dejaba el techo del endpoint amarrado al largo de la
	 * serie más larga del catálogo. Hoy comparten valor a propósito: "Seleccionar
	 * visibles" sobre una serie completa (One Piece = 106 tomos) es una compra
	 * legítima y truncarla mostraría "Agregamos 60 de 106" sin motivo. Se pueden
	 * mover por separado sin tocar el render.
	 */
	public const MAX_ADD_PER_REQUEST = 300;

	/**
	 * Distancia máxima a la meta en la que "completar" sigue siendo una ayuda
	 * concreta y no una presión absurda para series largas.
	 *
	 * Lo comparten la ficha y la landing: la misma persona no puede estar "cerca"
	 * con seis faltantes en una superficie y "lejos" en la otra.
	 */
	public const COMPLETE_MAX_MISSING = 6;

	/**
	 * Requests por minuto y por IP del alta de pack, solo para invitados
	 * (`RateLimit::per_minute` exime a logueados). Un humano arma y suma un pack un
	 * par de veces por minuto; 10 deja margen de sobra y corta el bucle scriptado,
	 * que hasta ahora no tenía ningún freno: el nonce de invitado es constante de
	 * sitio (ver `handle_add_to_cart`) y cada POST dispara hasta
	 * MAX_ADD_PER_REQUEST escrituras en el carrito.
	 */
	private const ADD_RATE_PER_MINUTE = 10;

	/** Option (no autoload) que versiona el cache de tomos por serie para invalidarlo en cambios de catálogo. */
	private const VOLS_CACHE_VER_OPTION = 'akb_sb_vols_cache_ver';

	/** Acción AJAX para agregar al carrito los tomos seleccionados en el builder. */
	public const ACTION_ADD_BYP = 'akb_sb_add_byp';

	/** Nombre del nonce del builder. */
	public const NONCE_ACTION = 'akb_sb_add_byp';

	private Settings $settings;
	private EligibilityGuard $guard;
	private VolumeTierResolver $tiers;
	private CartSeriesCollector $collector;
	private CustomerCollection $customer_collection;

	/**
	 * Proveedor de ETA de preventa (interfaz, no clase concreta) para etiquetar los
	 * tomos en preventa con su fecha por-tomo (BYP-PREVENTA-CLARIDAD). Nullable: si
	 * es null (tests, o EstimatedDelivery ausente) el widget degrada al comportamiento
	 * previo (sin etiqueta de fecha; el predicado `StockStatus::is_reservable` sigue
	 * marcando "Preventa" pelado). Inyectado desde `Plugin.php` vía `SeriesBundles`.
	 */
	private ?EtaProvider $eta;

	/**
	 * Conteo de tomos vendibles por serie. Se inyecta para COMPARTIR el memo con
	 * la landing (`LandingOffer`): las dos superficies preguntan lo mismo del
	 * mismo término y sin instancia común serían dos COUNT idénticos por request.
	 * Nullable para no romper los call sites de 4 argumentos que ya existen.
	 */
	private SeriesDepth $depth;

	/** Memo por-request del guard de profundidad (#4), por term_id. */
	private array $depth_memo = array();

	/** Evita varios bumps globales durante un mismo save/API/CLI request. */
	private bool $series_vols_cache_bumped = false;

	/** Evita una segunda rotación si un entorno de test dispara shutdown dos veces. */
	private bool $series_vols_cache_committed = false;

	public function __construct( Settings $settings, EligibilityGuard $guard, VolumeTierResolver $tiers, CartSeriesCollector $collector, ?EtaProvider $eta = null, ?SeriesDepth $depth = null, ?CustomerCollection $customer_collection = null ) {
		$this->settings  = $settings;
		$this->guard     = $guard;
		$this->tiers     = $tiers;
		$this->collector = $collector;
		$this->eta       = $eta;
		$this->depth     = $depth ?? new SeriesDepth( $settings );
		$this->customer_collection = $customer_collection ?? new CustomerCollection();
	}

	/**
	 * Conecta los hooks del widget.
	 *
	 * - Render en `woocommerce_after_single_product_summary` prio 5, antes de las
	 *   tabs (prio 10). El builder queda después de galería+resumen, usa el ancho
	 *   disponible y no empuja el CTA principal del tomo fuera del primer viewport.
	 * - Enqueue condicional en `wp_enqueue_scripts` prio 20 (Req 12.10).
	 * - Handler AJAX (logueado y visitante) para agregar al carrito la selección.
	 */
	public function register(): void {
		add_action( 'woocommerce_after_single_product_summary', array( $this, 'render' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_action( 'wp_ajax_' . self::ACTION_ADD_BYP, array( $this, 'handle_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION_ADD_BYP, array( $this, 'handle_add_to_cart' ) );

		// El cache de tomos por serie guarda la MEMBRESÍA (qué productos integran la
		// serie), NO el stock (que `series_volumes()` lee en vivo por render). Se
		// invalida cuando cambia el catálogo (alta/edición/baja de un producto) con
		// un bump O(1) de versión, evitando que un tomo recién publicado tarde hasta
		// el TTL en aparecer en el builder. Los hooks genéricos de papelera pasan
		// por un guard de post type; otras entradas no invalidan este cache.
		add_action( 'save_post_product', array( $this, 'bump_series_vols_cache' ) );
		add_action( 'deleted_post_product', array( $this, 'handle_deleted_product' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'handle_catalog_post_change' ) );
		add_action( 'untrashed_post', array( $this, 'handle_catalog_post_change' ) );
		add_action( 'added_term_relationship', array( $this, 'handle_series_relationship_change' ), 10, 3 );
		add_action( 'deleted_term_relationships', array( $this, 'handle_series_relationship_change' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'handle_series_terms_set' ), 10, 6 );
	}

	/**
	 * Versión actual del cache de tomos por serie (namespace de invalidación).
	 */
	private function series_vols_cache_version(): string {
		$token = (string) get_option( self::VOLS_CACHE_VER_OPTION, 'legacy' );
		return '' !== $token ? $token : 'legacy';
	}

	/**
	 * Agenda una única rotación del namespace al final del request.
	 *
	 * `save_post_product` puede correr ANTES de que REST/WP-CLI guarde `pa_serie`.
	 * Rotar de inmediato permitiría que otro request recacheara la membresía vieja
	 * bajo el token nuevo y que el posterior `set_object_terms` quedara absorbido
	 * por el coalescing. En shutdown todas las mutaciones ya terminaron: cualquier
	 * cache intermedio sigue bajo el token anterior y queda inaccesible al rotar.
	 */
	public function bump_series_vols_cache(): void {
		if ( $this->series_vols_cache_bumped ) {
			return;
		}

		$this->series_vols_cache_bumped = true;
		add_action( 'shutdown', array( $this, 'commit_series_vols_cache_bump' ), PHP_INT_MAX );
	}

	/** Ejecuta la rotación tardía agendada por `bump_series_vols_cache()`. */
	public function commit_series_vols_cache_bump(): void {
		if ( ! $this->series_vols_cache_bumped || $this->series_vols_cache_committed ) {
			return;
		}

		$this->series_vols_cache_committed = true;
		// Token write-only: dos requests concurrentes no colapsan en N+1.
		update_option( self::VOLS_CACHE_VER_OPTION, wp_generate_uuid4(), false );
	}

	/**
	 * Invalida después de un hard-delete REST/WP-CLI del producto.
	 *
	 * @param int|mixed      $post_id Producto eliminado (informativo).
	 * @param \WP_Post|mixed $post    Snapshot previo (informativo).
	 * @internal Callback del hook dinámico `deleted_post_product`.
	 */
	public function handle_deleted_product( $post_id, $post = null ): void {
		unset( $post_id, $post );
		$this->bump_series_vols_cache();
	}

	/**
	 * Filtra hooks genéricos de trash/untrash antes de invalidar el catálogo.
	 *
	 * @param int|mixed $post_id Post afectado.
	 * @internal Callback de `trashed_post` y `untrashed_post`.
	 */
	public function handle_catalog_post_change( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0
			|| ! function_exists( 'get_post_type' )
			|| 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$this->bump_series_vols_cache();
	}

	/**
	 * Cubre APIs directas de relación que no pasan por `set_object_terms`.
	 *
	 * `wp_add_object_terms()` emite `added_term_relationship` con un tt_id;
	 * `wp_remove_object_terms()` emite `deleted_term_relationships` con una lista.
	 * El mismo bump por request coalesce estos hooks con `set_object_terms`.
	 *
	 * @param int|mixed              $object_id Objeto afectado.
	 * @param int|array<int,mixed>   $tt_ids    Relación o relaciones afectadas.
	 * @param string|mixed           $taxonomy  Taxonomía afectada.
	 * @internal Callback de relaciones de términos.
	 */
	public function handle_series_relationship_change( $object_id, $tt_ids, $taxonomy ): void {
		$object_id = (int) $object_id;
		$ids       = is_array( $tt_ids ) ? $tt_ids : array( $tt_ids );
		if ( self::TAXONOMY !== (string) $taxonomy
			|| $object_id <= 0
			|| array() === $this->normalize_term_taxonomy_ids( $ids )
			|| ! function_exists( 'get_post_type' )
			|| 'product' !== get_post_type( $object_id ) ) {
			return;
		}

		$this->bump_series_vols_cache();
	}

	/**
	 * Invalida la membresía cuando WordPress cambia `pa_serie` por API o WP-CLI.
	 *
	 * `save_post_product` no es una frontera suficiente: `wp_set_object_terms()`
	 * también puede ejecutarse directamente. Se comparan term-taxonomy IDs en
	 * reemplazos para ignorar escrituras idempotentes, se descartan otras
	 * taxonomías/tipos de post y el bump se coalesce con el del save del producto.
	 * En modo append WordPress no entrega el set anterior; una lista vacía es el
	 * único no-op demostrable desde este hook.
	 *
	 * @param int|mixed          $object_id  Objeto al que se asignaron términos.
	 * @param array<int,mixed>   $terms      IDs/slugs solicitados (informativo).
	 * @param array<int,mixed>   $tt_ids     Term-taxonomy IDs resultantes.
	 * @param string|mixed       $taxonomy   Taxonomía modificada.
	 * @param bool|mixed         $append     Si se agregaron sin reemplazar.
	 * @param array<int,mixed>   $old_tt_ids Term-taxonomy IDs anteriores.
	 * @internal Callback de `set_object_terms` (seis argumentos desde WP 2.8).
	 */
	public function handle_series_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		unset( $terms );
		$object_id = (int) $object_id;
		if ( self::TAXONOMY !== (string) $taxonomy
			|| $object_id <= 0
			|| ! function_exists( 'get_post_type' )
			|| 'product' !== get_post_type( $object_id ) ) {
			return;
		}

		$current  = $this->normalize_term_taxonomy_ids( $tt_ids );
		$previous = $this->normalize_term_taxonomy_ids( $old_tt_ids );
		if ( (bool) $append ? array() === $current : $current === $previous ) {
			return;
		}

		$this->bump_series_vols_cache();
	}

	/**
	 * @param mixed $ids Lista potencial de term-taxonomy IDs.
	 * @return array<int,int>
	 */
	private function normalize_term_taxonomy_ids( $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$normalized = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static fn ( int $id ): bool => $id > 0
				)
			)
		);
		sort( $normalized, SORT_NUMERIC );

		return $normalized;
	}

	/**
	 * Encola los assets de presentación solo en la ficha de producto (Req 12.10).
	 */
	public function enqueue_assets(): void {
		if ( ! is_product() ) {
			return;
		}

		// No cargar ~30KB de CSS/JS en PDP donde el builder NO se muestra: la
		// inmensa mayoría de los productos no son de una serie con BYP habilitado.
		$product_id = (int) get_queried_object_id();
		if ( ! $product_id || ! $this->should_render_for( $product_id ) ) {
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
			'akbSbBuilder',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::ACTION_ADD_BYP,
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'cartUrl' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ),
				'i18n'    => array(
					'empty'   => 'Elige al menos un tomo.',
					'adding'  => 'Sumando tu pack…',
					'added'   => '¡Listo, pack en el carrito!',
					'partial' => 'Agregamos %1$d de %2$d tomos. Dejamos marcados los que faltan.',
					// Sin respuesta que leer (la red, un 500): el mismo texto del dueño
					// que el servidor da cuando no sabe el motivo.
					'error'   => SelectionCart::error_text(),
					'noDisc'  => 'Selecciona tomos para ver el total y tu ahorro.',
					'discFmt' => '%1$d tomos elegibles · %2$d%% de descuento',
				),
			)
		);
	}

	/**
	 * Comprueba si el PDP expone un sistema activo de aviso de reposición.
	 *
	 * Un tomo agotado enlaza a su ficha, donde el módulo de lista de espera gestiona
	 * su formulario. El builder no envía un nonce incluido en HTML cacheado.
	 * WooCommerce sólo vincula a la sesión de invitado los nonces cuya acción
	 * empieza con woocommerce; no existe una vinculación universal de cualquier
	 * nonce de invitado. BackInStock puede activar el gate mediante
	 * akb_sb_waitlist_active cuando su captura está habilitada.
	 */
	private function waitlist_active(): bool {
		$active = false; // default muerto post-cutover; BackInStock hookea el filter.

		return (bool) apply_filters( 'akb_sb_waitlist_active', $active );
	}

	/**
	 * Render del bloque "Arma tu pack" en el single product summary.
	 *
	 * Si el producto no debe mostrar el widget, no emite NADA (Req 12.3, 12.4).
	 */
	public function render(): void {
		$product = $this->resolve_product();
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( ! $this->should_render( $product ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML construido con datos escapados internamente.
		echo $this->build_html( $product );
	}

	/**
	 * Decisión de render para un producto dado (testeable sin WC).
	 *
	 * True solo si el módulo está habilitado, el producto pertenece a una serie
	 * `pa_serie` con Build_Your_Pack habilitado (el config usa `term_id` como
	 * clave) y el producto es elegible (Req 12.2–12.4).
	 */
	public function should_render_for( int $product_id ): bool {
		if ( ! $this->settings->is_enabled() ) {
			return false;
		}

		if ( ! $this->guard->is_eligible( $product_id ) ) {
			return false;
		}

		$resolved = $this->collector->resolve_enabled_series( $product_id );
		if ( null === $resolved ) {
			return false;
		}
		$term_id = $resolved['term_id'];

		// Guard de profundidad (#4): si la serie no tiene suficientes tomos
		// vendibles para alcanzar el tramo más chico, NO se ofrece BYP — ni se
		// encolan los ~30KB de assets. Alinea el enqueue con `build_html` (que ya
		// suprime el render por profundidad) y permite habilitar series "de más"
		// (incluso todas) sin que aparezca un tramo inalcanzable.
		return $this->series_has_reachable_tier( $term_id );
	}

	/**
	 * Tramos de volumen configurados para una serie, ya ordenados por `min`.
	 *
	 * La resolución de serie/config vive en el `CartSeriesCollector` compartido
	 * (`resolve_enabled_series` itera TODOS los términos `pa_serie`, B3;
	 * `config_for_term` tolera claves int/string del JSON decodificado).
	 *
	 * @return array<int, array{min:int,pct:int}>
	 */
	private function get_tiers_for_term( int $term_id ): array {
		$entry = $this->collector->config_for_term( $term_id );

		if ( null === $entry ) {
			return array();
		}

		$tiers = array();
		foreach ( $entry['tiers'] as $tier ) {
			if ( is_array( $tier ) && isset( $tier['min'], $tier['pct'] ) ) {
				$tiers[] = array(
					'min' => (int) $tier['min'],
					'pct' => (int) $tier['pct'],
				);
			}
		}

		// Coalesce de pct repetidos: sanea configs persistidos con pills duplicadas
		// ("5+ · -10%" y "7+ · -10%"); los futuros los rechaza normalize_tiers.
		return $this->tiers->coalesce_equal_pct_tiers( $this->tiers->sort_tiers( $tiers ) );
	}

	/**
	 * Guard de profundidad (#4): ¿la serie tiene tomos vendibles suficientes para
	 * alcanzar al menos el tramo más chico? Memoizado por-request. Fail-safe: si no
	 * se puede contar (sin `$wpdb`, p.ej. en tests unitarios), devuelve true (no
	 * suprime; `build_html` hace igual el chequeo en vivo al renderizar).
	 */
	private function series_has_reachable_tier( int $term_id ): bool {
		if ( array_key_exists( $term_id, $this->depth_memo ) ) {
			return $this->depth_memo[ $term_id ];
		}

		$available = $this->depth->sellable_count( $term_id );
		if ( null === $available ) {
			return $this->depth_memo[ $term_id ] = true;
		}

		return $this->depth_memo[ $term_id ] = $this->tier_reachable( $available, $this->get_tiers_for_term( $term_id ) );
	}

	/**
	 * Decisión pura (testeable sin WC): ¿hay algún tramo cuyo `min` no supere los
	 * tomos disponibles? Sin tramos ⇒ false.
	 *
	 * @param array<int, array{min:int,pct:int}> $tiers
	 */
	private function tier_reachable( int $available, array $tiers ): bool {
		$mins = array();
		foreach ( $tiers as $tier ) {
			if ( is_array( $tier ) && isset( $tier['min'] ) ) {
				$mins[] = (int) $tier['min'];
			}
		}

		return ! empty( $mins ) && $available >= min( $mins );
	}

	/**
	 * Monto adicional que falta para el envío gratis del carrito actual.
	 *
	 * Se resuelve contra la zona, los paquetes, cupones y la política de descuentos
	 * reales de WooCommerce. Si todavía no hay una dirección resoluble o la zona no
	 * ofrece envío gratis, devuelve 0 y el builder no hace esa promesa.
	 */
	private function free_shipping_threshold(): float {
		// El builder comparte el umbral del transportista con FreeShippingProgress.
		// La cotización BlueX puede conceder gratuidad sin instancias core free_shipping;
		// las dos superficies deben calcular el mismo importe que falta.

		$carrier_threshold = max( 0.0, (float) get_option( 'akibara_fsp_carrier_threshold', 0 ) );

		$state = FreeShippingThreshold::for_current_cart( 0.0, $carrier_threshold );
		if ( FreeShippingThreshold::STATE_PROGRESS !== ( $state['state'] ?? '' ) ) {
			return 0.0;
		}

		$remaining = max( 0.0, (float) ( $state['remaining'] ?? 0.0 ) );

		/** @var float $remaining Filtrable por compatibilidad con personalizaciones existentes. */
		$remaining = (float) apply_filters( 'akibara_byp_free_shipping_threshold', $remaining );

		return max( 0.0, $remaining );
	}

	/**
	 * Helper privado: extrae el id del producto y delega en `should_render_for`.
	 */
	private function should_render( WC_Product $product ): bool {
		return $this->should_render_for( $product->get_id() );
	}

	/**
	 * Obtiene el producto global del single product, con fallback al post actual.
	 */
	private function resolve_product(): ?WC_Product {
		global $product;

		if ( $product instanceof WC_Product ) {
			return $product;
		}

		$resolved = wc_get_product( get_the_ID() );
		return $resolved instanceof WC_Product ? $resolved : null;
	}

	/**
	 * Consulta y decisión del builder: todo lo que hay que saber ANTES de pintar.
	 *
	 * Se separó de `build_html()` porque ese método eran ~370 líneas que
	 * consultaban la base, decidían reglas de negocio y emitían HTML en el mismo
	 * cuerpo: cualquier prueba de una de las tres tenía que pasar por las otras
	 * dos, y las reglas —qué tramos son alcanzables, cuándo «te faltan N» motiva
	 * en vez de desmotivar, cuándo «completar» es literal— sólo se podían
	 * observar buscando subcadenas en el marcado.
	 *
	 * Devuelve `null` cuando no hay builder que pintar, con las mismas cuatro
	 * salidas de siempre: producto sin serie BYP, sin tomos resolubles, sin
	 * tramos alcanzables.
	 *
	 * @return array{term_id:int,serie_name:string,tiers:array<int,array{min:int,pct:int}>,tiers_json:string,max_tier:int,volumes:array<int,array<string,mixed>>,user_id:int,owned_ids:array<int,int>,owned_count:int,series_total:int,faltan:int,near_goal:bool,missing_ids:array<int,int>,can_complete:bool,scarce_count:int,has_preventa:bool,has_preventa_real:bool,has_offer:bool,show_picker_tools:bool,show_tools:bool,volume_ranges:array<int,array<string,mixed>>}|null
	 */
	private function builder_view_model( WC_Product $product ): ?array {
		$product_id = $product->get_id();
		$resolved = $this->collector->resolve_enabled_series( $product_id );
		if ( null === $resolved ) {
			return null;
		}
		$term_id = $resolved['term_id'];

		$serie_name = $this->get_serie_name( $term_id );
		$tiers      = $this->get_tiers_for_term( $term_id );

		/**
		 * Tope de tomos a sugerir en el builder, ajustable por serie.
		 *
		 * @param int $suggest_qty Default self::SUGGEST_QTY.
		 * @param int $term_id     Serie (pa_serie) del producto actual.
		 */
		$suggest_qty = (int) apply_filters( 'akibara_byp_suggest_qty', self::SUGGEST_QTY, $term_id );
		$suggest_qty = max( VolumeTierResolver::DISCOUNT_MIN_QUANTITY, $suggest_qty );
		$volumes     = $this->series_volumes( $product_id, $suggest_qty, $term_id );

		// Sin tomos resolubles ⇒ no renderiza el builder (sin error).
		if ( empty( $volumes ) ) {
			return null;
		}

		// Guard de profundidad: un tomo comprable puede seguir siendo una compra
		// ordinaria sin derecho a BYP. El tramo visible se limita al mismo universo
		// `discount_eligible` que usa el cálculo del navegador y el carrito.
		$tiers = self::reachable_tiers_for_volumes( $tiers, $volumes );
		if ( empty( $tiers ) ) {
			return null;
		}

		// data-tiers para que el JS resuelva el % por volumen en el navegador.
		$tiers_json = wp_json_encode( array_values( $tiers ) );

		// Progress bar: calcula el máximo min para escalar
		$max_tier = empty( $tiers ) ? 0 : max( array_column( $tiers, 'min' ) );

		// Progreso de colección (solo cliente logueado; invitado = 0 queries).
		// Degradación elegante: si la query falla, $owned_ids = [] y el builder
		// queda idéntico al actual (la feature es puramente aditiva).
		$user_id      = get_current_user_id();
		$owned_ids    = array();
		$series_total = 0;
		if ( $user_id > 0 ) {
			// Privacidad: la franja es per-usuario → que LiteSpeed NO cachee esta
			// PDP para logueados (que un cliente no vea el progreso de otro). Es
			// no-op si LiteSpeed está ausente. Default de LS ya no cachea logueados,
			// esto es cinturón-y-tiradores.
			do_action( 'litespeed_control_set_nocache', 'akb byp collection per-user' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook público oficial de LiteSpeed Cache (tercero): el nombre lo fija ese plugin.

			$candidates   = get_objects_in_term( $term_id, self::TAXONOMY );
			$candidates   = is_array( $candidates ) ? $candidates : array();
			$catalog_ids  = $this->collector->resolve_catalog_product_ids( $term_id, $candidates, 999 );
			$series_total = count( $catalog_ids );
			$owned_ids    = $this->customer_collection->owned_among( $user_id, $catalog_ids );
			if ( class_exists( '\Akibara\Business\Modules\AccountCollection\CollectionResolver' ) ) {
				$manual_ids = \Akibara\Business\Modules\AccountCollection\CollectionResolver::manual_owned_product_ids( $user_id );
				if ( array() !== $manual_ids ) {
					$owned_ids = array_values( array_unique( array_merge( $owned_ids, $manual_ids ) ) );
				}
			}
			$owned_ids    = array_values( array_intersect( $catalog_ids, $owned_ids ) );
		}
		$owned_count = count( $owned_ids );

		// --- Lente "series largas" + "completá con 1 clic" --------------------
		// $faltan = tomos de la serie que el cliente no tiene. $near_goal acota
		// el lenguaje de "completar" a cuando la meta está cerca (≤6): en series
		// largas/en curso (One Piece = 106 tomos) "te faltan 101 para completarla"
		// desmotiva; lejos de la meta mostramos solo el conteo, sin presión.
		$faltan    = max( 0, $series_total - $owned_count );
		$near_goal = ( $user_id > 0 && $faltan > 0 && $faltan <= self::COMPLETE_MAX_MISSING );

		// "Completá con 1 clic": IDs de tomos que el cliente no tiene y están
		// disponibles. Solo ofrecemos el botón cuando TODOS los que faltan están
		// en stock (count === $faltan): así "completá" es literal, no una promesa
		// a medias. Si hay huecos agotados, ese cierre lo cubre el aviso de restock.
		$missing_ids = array();
		foreach ( $volumes as $vol ) {
			if ( ! empty( $vol['is_purchasable'] ) && ! in_array( (int) $vol['id'], $owned_ids, true ) ) {
				$missing_ids[] = (int) $vol['id'];
			}
		}
		$can_complete = ( $near_goal && count( $missing_ids ) > 0 && count( $missing_ids ) === $faltan );

		// El builder arranca VACÍO a propósito (sin pre-marcado): pre-tildar tomos que
		// el cliente paga se siente presuntuoso y entra en zona gris de consumidor
		// (casillas pre-marcadas con cargo, restringidas en UE / vigiladas por SERNAC).
		// El empujón goal-gradient ya vive en los chips de tramo + la barra de progreso
		// + el aviso "suma 1 tomo más y baja un X%"; no hace falta tildar nada por él.

		// #18 escasez: ¿la serie tiene tomos de copia única (qty=1)? Si sí, una
		// línea a nivel serie reframea la limitación como valor (coleccionable),
		// en vez de un badge por tomo que con qty=1 ubicuo sería puro ruido.
		$scarce_count = 0;
		foreach ( $volumes as $vol ) {
			if ( ! empty( $vol['is_purchasable'] ) && 1 === (int) ( $vol['stock_qty'] ?? -1 ) ) {
				++$scarce_count;
			}
		}

		// BYP-PREVENTA-CLARIDAD:
		//  - $has_preventa (M2): ¿la selección incluye ≥1 tomo comprable en preventa?
		//    Gatea el aviso a nivel pack + el toggle "solo en stock" (S1).
		//  - $has_offer (M3): ¿hay ≥1 tomo ya en oferta (price < regular)? Gatea la
		//    aclaración "sobre precio de lista" (si no hay ofertas, el % nominal se
		//    materializa entero y la nota sobra).
		list( $has_preventa, $has_preventa_real, $has_offer ) = self::pack_flags( $volumes );

		$show_picker_tools = count( $volumes ) > 12;
		// El bloque de herramientas se muestra con atajos rápidos siempre que haya >= 2 tomos.
		$show_tools        = count( $volumes ) >= 2;
		// Tramos más anchos en series largas: con tamaño 5, One Piece (106) daría 22
		// chips ilegibles y 60 tomos darían 12. A partir de ~40 tomos pasamos a 10 por
		// tramo (One Piece → ~11 chips, 60 → 6); series cortas siguen en 5 (1-5/6-10),
		// más granular y útil cuando hay pocos tomos.
		$range_size        = count( $volumes ) > 40 ? 10 : 5;
		$volume_ranges     = $show_picker_tools ? $this->build_volume_ranges( $volumes, $range_size ) : array();

		return array(
			'term_id'           => (int) $term_id,
			'serie_name'        => $serie_name,
			'tiers'             => $tiers,
			'tiers_json'        => (string) $tiers_json,
			'max_tier'          => (int) $max_tier,
			'volumes'           => $volumes,
			'user_id'           => (int) $user_id,
			'owned_ids'         => $owned_ids,
			'owned_count'       => (int) $owned_count,
			'series_total'      => (int) $series_total,
			'faltan'            => (int) $faltan,
			'near_goal'         => (bool) $near_goal,
			'missing_ids'       => $missing_ids,
			'can_complete'      => (bool) $can_complete,
			'scarce_count'      => (int) $scarce_count,
			'has_preventa'      => (bool) $has_preventa,
			// La bandera has_preventa debe viajar en el modelo que consume la plantilla.
			// pack_notice(bool) necesita un booleano explícito también al incluir reservables.

			'has_preventa_real' => (bool) $has_preventa_real,
			'has_offer'         => (bool) $has_offer,
			'show_picker_tools' => (bool) $show_picker_tools,
			'show_tools'        => (bool) $show_tools,
			'volume_ranges'     => $volume_ranges,
		);
	}

	/**
	 * Construye el HTML del builder interactivo "Arma tu pack" con toda la salida
	 * escapada. El cliente marca los tomos de la serie que quiere; el JS calcula
	 * el descuento por volumen en vivo y los agrega todos juntos al carrito vía
	 * AJAX. Sin JS degrada a enlaces a cada tomo (cada uno comprable por separado).
	 *
	 * Sólo RENDERIZA: las consultas y las decisiones de negocio viven en
	 * {@see builder_view_model()}, que devuelve datos y por eso se puede probar
	 * sin buscar subcadenas en el marcado.
	 */
	private function build_html( WC_Product $product ): string {
		$view = $this->builder_view_model( $product );
		if ( null === $view ) {
			return '';
		}

		$term_id           = $view['term_id'];
		$serie_name        = $view['serie_name'];
		$tiers             = $view['tiers'];
		$tiers_json        = $view['tiers_json'];
		$max_tier          = $view['max_tier'];
		$volumes           = $view['volumes'];
		$user_id           = $view['user_id'];
		$owned_ids         = $view['owned_ids'];
		$owned_count       = $view['owned_count'];
		$series_total      = $view['series_total'];
		$faltan            = $view['faltan'];
		$near_goal         = $view['near_goal'];
		$missing_ids       = $view['missing_ids'];
		$can_complete      = $view['can_complete'];
		$scarce_count      = $view['scarce_count'];
		$has_preventa      = $view['has_preventa'];
		$has_preventa_real = $view['has_preventa_real'];
		$has_offer         = $view['has_offer'];
		$show_picker_tools = $view['show_picker_tools'];
		$show_tools        = $view['show_tools'];
		$volume_ranges     = $view['volume_ranges'];
		$free_ship_thresh  = $this->free_shipping_threshold();
		$title_id_prefix   = self::TITLE_ID_PREFIX;

		$template = dirname( __DIR__, 3 ) . '/templates/series-bundles/builder-widget.php';
		if ( ! file_exists( $template ) ) {
			return '';
		}

		ob_start();
		include $template;
		return (string) ob_get_clean();
	}

	/**
	 * Conserva solo los tramos alcanzables por tomos con derecho a descuento.
	 *
	 * @param array<int, array{min:int,pct:int}>              $tiers   Tramos configurados.
	 * @param array<int, array{discount_eligible?:bool}>      $volumes Tomos visibles.
	 * @return array<int, array{min:int,pct:int}>
	 */
	private static function reachable_tiers_for_volumes( array $tiers, array $volumes ): array {
		$eligible_count = count(
			array_filter(
				$volumes,
				static fn( array $volume ): bool => ! empty( $volume['discount_eligible'] )
			)
		);

		return array_values(
			array_filter(
				$tiers,
				static fn( array $tier ): bool => isset( $tier['min'] ) && (int) $tier['min'] <= $eligible_count
			)
		);
	}

	/**
	 * Handler AJAX: agrega al carrito los tomos seleccionados en el builder.
	 *
	 * Verifica nonce, valida que los productos pertenezcan a una serie con BYP
	 * habilitado y sean elegibles, y los agrega como ítems individuales. El
	 * descuento por volumen lo aplica el `BundleCouponEngine` en el carrito (no
	 * se materializa acá), de modo que la fuente de verdad del descuento sigue
	 * siendo única. Responde JSON con el resultado.
	 */
	public function handle_add_to_cart(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// El nonce del builder está incluido en HTML público cacheable y su acción
		// no vincula al invitado con una sesión WooCommerce. No usarlo como única
		// barrera: validar mismo origen y cuota por IP. Un nonce ligado a sesión
		// requeriría un canal no cacheable de renovación antes de enviar el formulario.

		if ( ! PublicBatchRequest::origin_allowed() ) {
			wp_send_json( array( 'success' => false, 'message' => SelectionCart::error_text() ) );
			return;
		}

		if ( RateLimit::per_minute( 'byp_add', self::ADD_RATE_PER_MINUTE ) ) {
			wp_send_json( array( 'success' => false, 'message' => 'Demasiados intentos seguidos. Espera un momento y vuelve a intentarlo.' ) );
			return;
		}

		$request = PublicBatchRequest::posted_product_ids( self::MAX_ADD_PER_REQUEST );
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

		$validated       = array();
		$expected_series = null;
		$requested_series = isset( $_POST['series_id'] )
			? absint( wp_unslash( $_POST['series_id'] ) )
			: 0;
		foreach ( $ids as $product_id ) {
			$selected_product = wc_get_product( $product_id );
			if ( ! $selected_product instanceof WC_Product ) {
				continue;
			}

			// La serie llega explícita desde el builder nuevo. Para HTML cacheado de
			// una versión anterior conservamos un fallback sólo si existe UNA config
			// BYP inequívoca; toda ruta termina en las dos puertas exactas: catálogo comprable
			// (publicado/visible/simple/stock o preventa) + elegibilidad promocional.
			$series_id = $requested_series;
			if ( $series_id <= 0 ) {
				$fallback  = $this->collector->resolve_enabled_series( $product_id );
				$series_id = null !== $fallback ? (int) $fallback['term_id'] : 0;
			}
			// Una puerta: pertenecer al catálogo comprable de la serie
			// (publicado / visible / simple / con stock o reservable). La
			// elegibilidad PROMOCIONAL no se exige acá —igual que en el endpoint
			// de la landing—: decide cuánto descuento corresponde, no si el
			// cliente puede comprar el tomo. Exigirla dejaba caer en silencio los
			// tomos a pedido que el cliente acababa de seleccionar.
			if ( $series_id <= 0
				|| null === $this->collector->resolve_catalog_series( $selected_product, $series_id ) ) {
				continue;
			}
			if ( null === $expected_series ) {
				$expected_series = $series_id;
			}
			if ( $series_id !== $expected_series ) {
				continue;
			}
			$validated[] = $product_id;
		}

		// El alta, el motivo de lo que no entra y la respuesta, en `SelectionCart`:
		// los comparte con «Elegir tomos» de la landing.
		SelectionCart::send( $cart, $ids, $validated );
	}

	/**
	 * Nombre legible de la serie (`pa_serie`) a partir de su `term_id`.
	 * Fallback a "tu serie" si no se puede resolver.
	 */
	private function get_serie_name( int $term_id ): string {
		$term = get_term( $term_id, self::TAXONOMY );
		if ( $term instanceof \WP_Term && '' !== $term->name ) {
			return $term->name;
		}
		return 'tu serie';
	}

	/**
	 * Construye rangos de tomos para series largas (1-5, 6-10,...), omitiendo
	 * rangos vacíos para no mostrar chips que no llevan a ningún tomo.
	 *
	 * @param array<int, array{volume_number:?int}> $volumes
	 *
	 * @return array<int, array{min:int,max:int}>
	 */
	private function build_volume_ranges( array $volumes, int $size ): array {
		$numbers = array();
		foreach ( $volumes as $vol ) {
			if ( isset( $vol['volume_number'] ) && null !== $vol['volume_number'] ) {
				$numbers[] = (int) $vol['volume_number'];
			}
		}

		if ( empty( $numbers ) ) {
			return array();
		}

		$max    = max( $numbers );
		$ranges = array();
		for ( $min = 1; $min <= $max; $min += $size ) {
			$range_max = $min + $size - 1;
			$has_items = false;
			foreach ( $numbers as $number ) {
				if ( $number >= $min && $number <= $range_max ) {
					$has_items = true;
					break;
				}
			}

			if ( $has_items ) {
				$ranges[] = array(
					'min' => $min,
					'max' => $range_max,
				);
			}
		}

		return $ranges;
	}

	/**
	 * Tomos de la serie RESUELTA (`$term_id`, incluido el tomo actual) para poblar
	 * el builder, ordenados por `post_title` ASC. Devuelve DTOs de presentación con
	 * precio numérico (para el cálculo en vivo) y precio HTML (para mostrar).
	 *
	 * El catálogo de Akibara usa padding numérico en `post_title` (vol 1 → 01),
	 * por lo que el orden alfabético equivale al orden lineal de tomos. Solo
	 * incluye productos elegibles y comprables (con precio).
	 *
	 * @param int $product_id Tomo desde cuya PDP se renderiza el builder.
	 * @param int $count      Tope de tomos a traer.
	 * @param int $term_id    Serie `pa_serie` ya resuelta por `CartSeriesCollector`.
	 *
	 * @return array<int, array{id:int,title:string,url:string,price:float,regular_price:float,price_html:string,thumb_html:string,is_current:bool,is_purchasable:bool,discount_eligible:bool,volume_number:?int,manage_stock:bool,stock_qty:?int,is_preventa:bool,is_preventa_real:bool,eta_label:string}>
	 */
	private function series_volumes( int $product_id, int $count, int $term_id ): array {
		// Object-term cache (0 queries en caliente), ver BundleCouponEngine.
		$serie_terms = get_the_terms( $product_id, self::TAXONOMY );
		if ( ! is_array( $serie_terms ) || empty( $serie_terms ) ) {
			return array();
		}

		// La rejilla contiene sólo la serie resuelta por resolve_enabled_series().
		// Su título, tiers, contador y expected_series del handler deben describir
		// el mismo conjunto, sin mezclar todas las relaciones de serie del producto.

		$serie_tt_ids = array();
		foreach ( $serie_terms as $serie_term ) {
			if ( (int) $serie_term->term_id === $term_id ) {
				$serie_tt_ids[] = (int) $serie_term->term_taxonomy_id;
			}
		}
		if ( empty( $serie_tt_ids ) ) {
			return array();
		}

		// El `$tt_ids_in` acotado también renueva la `$cache_key` de abajo, así que un
		// producto multiserie no reusa el transient del set mezclado.
		$tt_ids_in = implode( ',', $serie_tt_ids );

		global $wpdb;

		$cache_key = 'akb_series_vols_' . md5( $tt_ids_in . '_' . $count . '_v' . $this->series_vols_cache_version() );
		$ids       = get_transient( $cache_key );

		if ( false === $ids ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tt_ids_in es un implode de enteros ya casteados; el LIMIT sí va por marcador %d.
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				   AND tr.term_taxonomy_id IN ( {$tt_ids_in} )
				 ORDER BY p.post_title ASC
				 LIMIT %d",
				$count
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene armado por $wpdb->prepare() justo arriba.
			$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );
			set_transient( $cache_key, $ids, HOUR_IN_SECONDS );
		}

		if ( empty( $ids ) ) {
			return array();
		}

		// Prime de caches en batch: sin esto, hidratar N tomos es N+1 (is_eligible +
		// wc_get_product + get_image + get_price_html por tomo). Con el prime en frío
		// el costo pasa de ~5N queries a ~constante. Clave para series largas (100+).
		_prime_post_caches( $ids, true, true );

		// Pase 1: resolver productos elegibles y juntar los IDs de portada para primear
		// también esos attachments (si no, get_image los leería de a uno).
		$products  = array();
		$image_ids = array();
		foreach ( $ids as $id ) {
			if ( ! $this->guard->is_eligible( $id ) ) {
				continue;
			}
			$vol_product = wc_get_product( $id );
			if ( ! $vol_product instanceof WC_Product || ! $vol_product->is_type( 'simple' ) ) {
				continue;
			}
			if ( method_exists( $vol_product, 'get_status' ) && 'publish' !== $vol_product->get_status() ) {
				continue;
			}
			if ( method_exists( $vol_product, 'is_visible' ) && ! $vol_product->is_visible() ) {
				continue;
			}
			$products[ $id ] = $vol_product;
			$image_id        = (int) $vol_product->get_image_id();
			if ( $image_id > 0 ) {
				$image_ids[] = $image_id;
			}
		}
		if ( ! empty( $image_ids ) ) {
			_prime_post_caches( $image_ids, true, true );
		}

		// Pase 2: armar los DTO de presentación (ya en caliente, sin queries extra).
		// Contexto de fecha para la etiqueta de preventa (M1): año/tz local del sitio,
		// deterministas por día (la ETA se floorea a medianoche local) → cache-safe.
		$eta_tz   = StoreTime::zone();
		$eta_year = (int) ( new \DateTimeImmutable( 'now', $eta_tz ) )->format( 'Y' );

		$volumes = array();
		foreach ( $products as $id => $vol_product ) {
			// Precio filtrado de vista: refleja ofertas runtime como
			// TaxonomyDiscount. El JS replica DiscountEngine contra este valor.
			$title         = (string) $vol_product->get_name();
			$price         = (float) $vol_product->get_price();
			$regular_price = (float) $vol_product->get_regular_price( 'edit' );
			// `skip-lazy`: LiteSpeed (y WP core, ticket #44427) excluyen esta clase del
			// lazyload JS. El grid hace scroll interno; el lazyload por-viewport de
			// LiteSpeed no dispara bajo el pliegue → la portada quedaba en placeholder
			// 1x1. Con skip-lazy se sirve directo; el loading="lazy" nativo (que sí
			// respeta el contenedor con scroll) queda como diferido.
			// `sizes` explícito: el thumb se muestra a ~130-200px (grid
			// `minmax(130px,1fr)`, aspect-ratio 2/3). Sin esto, WC emite el `sizes`
			// por defecto (~100vw) y el browser baja el candidato de ~768px del
			// srcset para una caja de ~170px → over-fetch de ~4-9× en píxeles, que
			// en series largas (100+ tomos) multiplica el peso de la PDP. Con
			// 45vw/200px elige ~350-400px: nítido a 2x sin malgastar bytes.
			$thumb_html    = $vol_product->get_image(
				'woocommerce_thumbnail',
				array(
					'class' => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail skip-lazy',
					'sizes' => '(max-width: 600px) 45vw, 200px',
				)
			);

			// BYP-PREVENTA-CLARIDAD (M1): predicado ÚNICO de reservable =
			// StockStatus::is_reservable (onbackorder). La PALABRA la pone el
			// estado comercial («A pedido» o «Preventa», Availability 1.109.0) y
			// la FECHA la delega en el EtaProvider (calculate() puede ser null si
			// el tomo es `disabled` → etiqueta pelada, sin fecha).
			$is_preventa      = StockStatus::is_reservable( $vol_product );
			$is_preventa_real = $is_preventa && Availability::is_preventa( $vol_product );
			// El interruptor de reservables determina discount_eligible para sumar al
			// tramo, no si el producto puede comprarse. Ficha y landing comparten el
			// predicado comprable y permiten añadir los tomos sin prometer descuento indebido.

			$is_purchasable    = null !== $this->collector->resolve_catalog_series( $vol_product, $term_id );
			$discount_eligible = null !== $this->collector->resolve_eligible_series_for_term( $vol_product, $term_id );
			$manage_stock   = (bool) $vol_product->managing_stock();
			$eta_label   = '';
			$avail_label = Availability::label_of( $vol_product );
			if ( $is_preventa ) {
				$eta_result = null !== $this->eta ? $this->eta->calculate( $vol_product ) : null;
				$eta_label  = self::preventa_label( $eta_result, $eta_year, $eta_tz, $avail_label );
			}

			$volumes[] = array(
				'id'             => $id,
				'title'          => $title,
				'url'            => (string) get_permalink( $id ),
				'price'          => $price,
				'regular_price'  => $regular_price,
				'price_html'     => (string) $vol_product->get_price_html(),
				'thumb_html'     => (string) $thumb_html,
				'is_current'     => ( $id === $product_id ),
				'is_purchasable' => $is_purchasable,
				'discount_eligible' => $discount_eligible,
				'volume_number'  => VolumeTitle::extract_number( $title ),
				// #18 escasez: stock por tomo (null si no maneja stock).
				'manage_stock'   => $manage_stock,
				'stock_qty'      => $manage_stock ? (int) $vol_product->get_stock_quantity() : null,
				// BYP-PREVENTA-CLARIDAD: flag de reservable + etiqueta de fecha y
				// palabra del estado comercial por tomo (Availability 1.109.0).
				'is_preventa'    => $is_preventa,
				'is_preventa_real' => $is_preventa_real,
				'eta_label'      => $eta_label,
				'avail_label'    => $avail_label,
			);
		}

		// Orden lineal por número de tomo. El SQL ordena por post_title, que es
		// LEXICOGRÁFICO: con 100+ tomos "100" cae antes que "11" (el padding a 2
		// dígitos del catálogo no alcanza). Re-ordenar por volume_number garantiza
		// 1,2,…,N. Los tomos sin número detectable van al final, por título.
		return VolumeTitle::sort_records( $volumes );
	}

	/**
	 * Las tres banderas del pack, de los tomos COMPRABLES:
	 *   - hay alguno reservable (gatea el aviso y el toggle «solo en stock»),
	 *   - alguno es preventa DE VERDAD (decide la palabra del aviso: «en
	 *     preventa» o la etiqueta de «a pedido»),
	 *   - alguno ya está en oferta (gatea la aclaración «sobre precio de lista»).
	 *
	 * @since 1.114.16 Extraído para poder probar la palabra del aviso.
	 *
	 * @param array<int, array<string, mixed>> $volumes Tomos del pack.
	 * @return array{0:bool,1:bool,2:bool} [has_preventa, has_preventa_real, has_offer].
	 */
	public static function pack_flags( array $volumes ): array {
		$has_preventa      = false;
		$has_preventa_real = false;
		$has_offer         = false;
		foreach ( $volumes as $vol ) {
			if ( empty( $vol['is_purchasable'] ) ) {
				continue;
			}
			if ( ! empty( $vol['is_preventa'] ) ) {
				$has_preventa = true;
			}
			if ( ! empty( $vol['is_preventa_real'] ) ) {
				$has_preventa_real = true;
			}
			$reg = (float) ( $vol['regular_price'] ?? 0 );
			if ( $reg > 0 && (float) ( $vol['price'] ?? 0 ) < $reg ) {
				$has_offer = true;
			}
		}
		return array( $has_preventa, $has_preventa_real, $has_offer );
	}

	/**
	 * La nota del pack cuando incluye tomos que aún no están: «en preventa» si
	 * alguno es un lanzamiento pendiente de verdad; si no, la MISMA palabra que
	 * la etiqueta de cada tomo («a pedido»).
	 *
	 * @since 1.114.16
	 *
	 * @param bool $has_preventa_real Alguno es preventa de verdad.
	 * @return string HTML de la frase (con la palabra en negrita).
	 */
	public static function pack_notice( bool $has_preventa_real ): string {
		$estado  = $has_preventa_real ? Availability::PREVENTA : Availability::A_PEDIDO;
		$palabra = ( $has_preventa_real ? 'en ' : '' )
			. '<strong>' . esc_html( mb_strtolower( Availability::label_for_state( $estado ) ) ) . '</strong>';

		return 'La serie incluye tomos ' . $palabra . ': se despachan más adelante. Revisa la fecha de cada tomo o muestra solo los disponibles ahora.';
	}

	/**
	 * Etiqueta inline de preventa para un tomo (BYP-PREVENTA-CLARIDAD, M1). Pura y
	 * estática para testear sin WooCommerce.
	 *
	 * El PREDICADO "es reservable" lo decide el caller con `StockStatus::is_reservable`
	 * (fuente única para etiqueta/DTO/`data-*`/aviso-de-pack/carrito). Este método
	 * solo compone el TEXTO a partir de la ETA ya calculada, delegando el formato de
	 * fecha en `BannerRenderer` (misma fuente que el banner ETA → sin divergencia):
	 *
	 *   - $eta null (tipo `disabled`, TBA sin lead, o sin proveedor) → "Preventa"
	 *     pelado. Un `disabled` es "es preventa pero sin fecha comprometida": mostrar
	 *     "Preventa" sin fecha es honesto.
	 *   - rango concreto → "Preventa · 27 may – 1 jun".
	 *   - TBA con lead   → "Preventa · llega ~2 semanas".
	 *
	 * @param EtaResult|null     $eta          ETA del tomo (o null).
	 * @param int                $current_year Año actual (inyectado para determinismo).
	 * @param \DateTimeZone|null  $tz           Timezone para formatear la fecha.
	 * @return string
	 */
	public static function preventa_label( ?EtaResult $eta, int $current_year, ?\DateTimeZone $tz = null, string $word = 'Preventa' ): string {
		// `$word` es la palabra del estado COMERCIAL («A pedido» o «Preventa»,
		// Availability::label_of) que pone cada llamador; el default conserva la
		// firma histórica para los tests y consumidores que no distinguen.
		if ( null === $eta ) {
			return $word;
		}

		if ( $eta->is_tba() ) {
			if ( $eta->has_tba_lead() ) {
				return $word . ' · llega ' . BannerRenderer::format_lead_text(
					(int) $eta->tba_min_days(),
					(int) $eta->tba_max_days()
				);
			}
			return $word;
		}

		return $word . ' · ' . BannerRenderer::format_range(
			(int) $eta->min_ts(),
			(int) $eta->max_ts(),
			$current_year,
			$tz
		);
	}
}
