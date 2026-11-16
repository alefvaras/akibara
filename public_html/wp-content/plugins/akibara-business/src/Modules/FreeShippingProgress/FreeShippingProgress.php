<?php
/**
 * Calcula el restante para envío gratis y renderiza la barra del carrito
 * nativo. Blocks consume el estado estructurado mediante el adaptador propio.
 * Estados de presentación: active, qualified y empty. La razón concreta de
 * calificación se consulta en structured_state()[reason], sin clases CSS
 * por motivo. Los textos admiten free_shipping_amount, remaining y subtotal.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\FreeShippingProgress;

use Akibara\Business\Services\FreeShippingThreshold;
use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress HookNotFound
 * @psalm-suppress InvalidOperand
 */
// @phpstan-ignore complexity.classLike
final class FreeShippingProgress {

	public const OPTION_ENABLED              = 'akibara_fsp_enabled';
	public const OPTION_THRESHOLD            = 'akibara_fsp_threshold';
	public const OPTION_CARRIER_THRESHOLD    = 'akibara_fsp_carrier_threshold';
	public const OPTION_SHOW_CART            = 'akibara_fsp_show_cart';
	public const OPTION_SHOW_MINI_CART       = 'akibara_fsp_show_mini_cart';
	public const OPTION_SHOW_CHECKOUT        = 'akibara_fsp_show_checkout';
	public const OPTION_SHOW_QUALIFIED       = 'akibara_fsp_show_qualified';
	public const OPTION_DISABLE_LOCAL_PICKUP = 'akibara_fsp_disable_local_pickup';
	public const OPTION_TITLE                = 'akibara_fsp_title';
	public const OPTION_MESSAGE              = 'akibara_fsp_message';
	public const OPTION_QUALIFIED            = 'akibara_fsp_qualified';

	// Override inicial del operador: cero utiliza el min_amount real de WooCommerce.
	public const DEFAULT_THRESHOLD = 0;
	// Umbral único de envío gratis a domicilio (Blue Express): $60.000 CLP por defecto.
	public const DEFAULT_CARRIER_THRESHOLD = 60000;
	// El copy nombra "a domicilio" a propósito: el retiro en Punto Blue se cotiza
	// aparte y no queda gratis por monto. Prometer "envío gratis" a secas se
	// contradecía con el cargo que el cliente ve al elegir un punto.
	private const DEFAULT_TITLE     = 'Envío GRATIS a domicilio sobre {free_shipping_amount}';
	private const DEFAULT_MESSAGE   = 'Agrega {remaining} más para envío gratis a domicilio';
	private const DEFAULT_QUALIFIED = '¡Listo! Tu pedido tiene envío gratis a domicilio';

	/**
	 * HTML ya armado en ESTA petición, o null si aún no se pidió.
	 *
	 * Un refresco de fragments lo pedía DOS veces —una por el widget del
	 * mini-carrito y otra por el filtro de fragments— y cada una recorría cupones,
	 * paquetes y tarifas para producir exactamente la misma cadena.
	 */
	private ?string $html_cache = null;

	public function register(): void {
		// Garantiza que akibara_fsp_threshold se mantenga en 0 para evitar pisos fantasmas en DB.
		add_action( 'update_option_' . self::OPTION_CARRIER_THRESHOLD, static function (): void {
			if ( (int) get_option( self::OPTION_THRESHOLD, 0 ) !== 0 ) {
				update_option( self::OPTION_THRESHOLD, 0 );
			}
		} );
		add_filter( 'pre_update_option_' . self::OPTION_THRESHOLD, static function (): int {
			return 0;
		} );

		if ( 'yes' !== get_option( self::OPTION_ENABLED, 'yes' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ), 20 );

		// El drawer consulta por filtro la misma regla de calificación que la barra.
		// Un shipping_total cero no distingue gratis de aún no calculado. La regla
		// se publica aunque Mostrar en mini carrito esté apagado.
		add_filter( 'akb_native_cart_fsp_qualified', array( $this, 'is_qualified' ) );

		// Garantiza la paridad de envío gratis pre-cupones sobre instancias WC_Shipping_Free_Shipping.
		add_filter( 'woocommerce_shipping_free_shipping_is_available', array( $this, 'filter_free_shipping_is_available' ), 20, 3 );

		if ( 'yes' === get_option( self::OPTION_SHOW_MINI_CART, 'yes' ) ) {
			// Widget WC nativo (woocommerce.php template).
			add_action( 'woocommerce_widget_shopping_cart_before_buttons', array( $this, 'render' ) );
			// El Native Cart refresca vía AJAX por su propio endpoint (no usa
			// wc-cart-fragments). Exponemos el HTML por filtro para que el
			// payload de ajax_get_cart reinyecte la barra en cada mutación. Es la
			// ÚNICA vía del drawer: pintarla también en el esqueleto del `wp_footer`
			// costaba un cálculo completo de umbral en CADA página del sitio y el JS
			// lo borraba antes de que nadie lo viera (`renderCart()` vacía el slot y
			// lo rellena con `fsp_html`).
			add_filter( 'akb_native_cart_fsp_html', array( $this, 'native_cart_html' ) );


			// Los fragments refrescan la barra del WIDGET, que sólo existe con el
			// mini-carrito encendido. Fuera del `if` el interruptor apagaba la barra
			// y seguía pagando el cálculo en cada add-to-cart.
			add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'fragments' ) );

			// El HTML es el mismo dentro de una petición mientras el carrito no se
			// mueva, y cada mutación pasa por `calculate_totals()`. Va dentro del
			// gate porque sin mini-carrito nadie pide el HTML.
			add_action( 'woocommerce_after_calculate_totals', array( $this, 'flush_html_cache' ) );
		}
	}

	/**
	 * Encola el CSS sólo en superficies donde puede aparecer la barra.
	 */
	public function enqueue_styles(): void {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}

		// Sin mini-carrito no hay superficie: ni el widget ni el drawer la pintan.
		if ( 'yes' !== get_option( self::OPTION_SHOW_MINI_CART, 'yes' ) ) {
			return;
		}

		// Carrito y checkout son bloques y el drawer no se monta ahí
		// (`NativeCart::enqueue_assets()` sale temprano en las dos superficies).
		if ( function_exists( 'is_cart' ) && function_exists( 'is_checkout' ) && ( is_cart() || is_checkout() ) ) {
			return;
		}

		wp_enqueue_style(
			'akb-fsp',
			AKIBARA_BUSINESS_URL . 'assets/css/free-shipping-progress.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/free-shipping-progress.css' )
		);
	}

	/**
	 * Olvida el HTML memoizado. Cuelga de `woocommerce_after_calculate_totals`:
	 * toda mutación del carrito (líneas, cupones, método de envío elegido) pasa
	 * por ahí, así que el memo no puede sobrevivir a un carrito que cambió.
	 */
	public function flush_html_cache(): void {
		$this->html_cache = null;
	}

	/**
	 * Echo del HTML para los hooks WC.
	 */
	public function render(): void {
		echo $this->cached_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado y escapado dentro.
	}

	/**
	 * Inyecta el HTML como fragment AJAX para refresh sin reload.
	 *
	 * @param array<string, string> $fragments Fragments existentes.
	 * @return array<string, string>
	 */
	public function fragments( $fragments ): array {
		$fragments['.akb-fsp-wrap'] = $this->cached_html();
		return $fragments;
	}

	/**
	 * Provee el HTML de la barra para el hook `akb_native_cart_fsp_html` (el payload
	 * AJAX del Native Cart lo reinyecta en cada mutación). Método nombrado en vez de
	 * closure para que sea desenganchable (remove_filter) y testeable en aislamiento.
	 *
	 * @since 1.49.9
	 * @return string
	 */
	public function native_cart_html(): string {
		return $this->cached_html();
	}

	/**
	 * ¿Este carrito YA calificó para envío gratis, según la MISMA regla que
	 * decide la barra (`structured_state()`)? Fuente única para cualquier otra
	 * superficie que necesite la pregunta como booleano en vez de HTML —hoy
	 * `NativeCart::ajax_get_cart()`, vía el filtro `akb_native_cart_fsp_qualified`—
	 * y así evitar una segunda cuenta que se desincronice de la barra.
	 *
	 * @since 1.116.27
	 * @return bool
	 */
	public function is_qualified(): bool {
		return 'qualified' === $this->structured_state()['state'];
	}

	/**
	 * El HTML de la barra, armado como mucho UNA vez por petición.
	 */
	private function cached_html(): string {
		if ( null === $this->html_cache ) {
			$this->html_cache = $this->get_html();
		}

		return $this->html_cache;
	}

	/**
	 * Returns the renderer-agnostic free-shipping state.
	 *
	 * @return array{
	 *   state:string,
	 *   reason:string,
	 *   visible_on_cart:bool,
	 *   visible_on_checkout:bool,
	 *   show_qualified:bool,
	 *   title:string,
	 *   message:string,
	 *   threshold:float,
	 *   total:float,
	 *   remaining:float,
	 *   progress_percent:float,
	 *   almost_there:bool
	 * }
	 *
	 * @since 1.96.0
	 */
	public function structured_state(): array {
		$enabled             = 'yes' === get_option( self::OPTION_ENABLED, 'yes' );
		$visible_on_cart     = $enabled && 'yes' === get_option( self::OPTION_SHOW_CART, 'yes' );
		$visible_on_checkout = $enabled && 'yes' === get_option( self::OPTION_SHOW_CHECKOUT, 'yes' );
		$show_qualified      = 'yes' === get_option( self::OPTION_SHOW_QUALIFIED, 'yes' );
		$base                = array(
			'state'               => 'hidden',
			'reason'              => 'unavailable',
			'visible_on_cart'     => $visible_on_cart,
			'visible_on_checkout' => $visible_on_checkout,
			'show_qualified'      => $show_qualified,
			'title'               => '',
			'message'             => '',
			'threshold'           => 0.0,
			'total'               => 0.0,
			'remaining'           => 0.0,
			'progress_percent'    => 0.0,
			'almost_there'        => false,
		);

		if ( ! $enabled || ! $this->cart_ready() ) {
			return $base;
		}

		if ( 'yes' === get_option( self::OPTION_DISABLE_LOCAL_PICKUP, 'yes' )
			&& $this->is_local_pickup_chosen() ) {
			$base['reason'] = 'local_pickup';
			return $base;
		}

		if ( $this->has_chosen_zero_fee() ) {
			$base['reason'] = 'zero_fee';
			if ( ! $show_qualified ) {
				return $base;
			}

			$base['state']   = 'qualified';
			$base['title']   = 'Envío gratis a domicilio';
			$base['message'] = $this->apply_placeholders_plain(
				$this->get_string( 'qualified' ),
				0.0,
				0.0,
				0.0
			);
			return $base;
		}

		$shipping_state = FreeShippingThreshold::for_current_cart(
			$this->get_threshold_override(),
			$this->get_carrier_threshold()
		);
		$reason         = (string) ( $shipping_state['reason'] ?? '' );
		$reason         = in_array( $reason, array( 'order_amount', 'coupon' ), true )
			? $reason
			: 'unavailable';
		$threshold      = max( 0.0, (float) ( $shipping_state['threshold'] ?? 0.0 ) );
		$total          = max( 0.0, (float) ( $shipping_state['total'] ?? 0.0 ) );
		$remaining      = max( 0.0, (float) ( $shipping_state['remaining'] ?? 0.0 ) );

		$base['reason']    = $reason;
		$base['threshold'] = $threshold;
		$base['total']     = $total;
		$base['remaining'] = $remaining;

		if ( FreeShippingThreshold::STATE_QUALIFIED === (string) ( $shipping_state['state'] ?? '' ) ) {
			if ( ! $show_qualified ) {
				return $base;
			}

			$base['state']            = 'qualified';
			$base['title']            = 'Envío gratis a domicilio';
			$base['message']          = $this->apply_placeholders_plain(
				$this->get_string( 'qualified' ),
				0.0,
				$threshold,
				$total
			);
			$base['progress_percent'] = 100.0;
			return $base;
		}

		if ( FreeShippingThreshold::STATE_PROGRESS !== (string) ( $shipping_state['state'] ?? '' )
			|| $threshold <= 0.0 ) {
			return $base;
		}

		$percent = self::progress_percent( $total, $threshold );

		$base['state']            = 'progress';
		$base['title']            = $this->apply_placeholders_plain(
			$this->get_string( 'title' ),
			$remaining,
			$threshold,
			$total
		);
		$base['message']          = $this->apply_placeholders_plain(
			$this->get_string( 'message' ),
			$remaining,
			$threshold,
			$total
		);
		$base['progress_percent'] = $percent;
		$base['almost_there']     = $percent >= 80.0;

		return $base;
	}

	/**
	 * Genera el HTML completo de la barra según estado del cart.
	 */
	private function get_html(): string {
		if ( ! $this->cart_ready() ) {
			return '<div class="akb-fsp-wrap akb-fsp-empty"></div>';
		}

		// Retiro en tienda → ocultar (Akibara siempre usa PUDO Bluex u offer-pickup).
		if ( 'yes' === get_option( self::OPTION_DISABLE_LOCAL_PICKUP, 'yes' )
			&& $this->is_local_pickup_chosen() ) {
			return '<div class="akb-fsp-wrap akb-fsp-empty"></div>';
		}

		$show_qualified = 'yes' === get_option( self::OPTION_SHOW_QUALIFIED, 'yes' );

		// Un rate elegido de costo cero puede venir de otro método/plugin. Es un
		// resultado real del cálculo de WooCommerce y, por tanto, sí es seguro
		// comunicarlo aunque no sea una instancia core `free_shipping`.
		if ( $this->has_chosen_zero_fee() ) {
			if ( ! $show_qualified ) {
				return '<div class="akb-fsp-wrap akb-fsp-empty"></div>';
			}
			return $this->render_qualified( 0.0 );
		}

		$shipping_state = FreeShippingThreshold::for_current_cart(
			$this->get_threshold_override(),
			$this->get_carrier_threshold()
		);

		if ( FreeShippingThreshold::STATE_QUALIFIED === $shipping_state['state'] ) {
			if ( ! $show_qualified ) {
				return '<div class="akb-fsp-wrap akb-fsp-empty"></div>';
			}
			return $this->render_qualified( (float) $shipping_state['threshold'] );
		}

		if ( FreeShippingThreshold::STATE_PROGRESS !== $shipping_state['state'] ) {
			return '<div class="akb-fsp-wrap akb-fsp-empty"></div>';
		}

		return $this->render_progress(
			(float) $shipping_state['total'],
			(float) $shipping_state['threshold']
		);
	}

	/**
	 * Determina si procede mostrar distancia al umbral, sin exigir la dirección
	 * completa que show_shipping necesita para presentar tarifas en Blocks.
	 * Conserva las guardas de envío activo, métodos, contenido y filtros de
	 * terceros. FreeShippingThreshold exige país antes de resolver la zona,
	 * para no prometer un umbral sin destino resoluble.
	 */
	private function cart_ready(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		$cart = WC()->cart ?? null; // @phpstan-ignore nullCoalesce.property
		if ( ! $cart || ! $cart->needs_shipping() || ! $cart->get_cart_contents() ) {
			return false;
		}

		if ( function_exists( 'wc_shipping_enabled' ) && ! wc_shipping_enabled() ) {
			return false;
		}

		if ( function_exists( 'wc_get_shipping_method_count' )
			&& 0 === wc_get_shipping_method_count( true ) ) {
			return false;
		}

		/** This filter is documented in woocommerce/includes/class-wc-cart.php */
		return (bool) apply_filters( 'woocommerce_cart_ready_to_calc_shipping', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de WooCommerce core: se re-aplica con su nombre original para que los callbacks de terceros sigan viéndolo.
	}

	/**
	 * Detecta un método elegido de costo cero que no sea retiro.
	 *
	 * Para las instancias core `free_shipping`, la autoridad es
	 * FreeShippingThreshold::for_current_cart(). Este fallback cubre rates de
	 * costo cero aportados por integraciones externas sin inventar umbrales.
	 */
	private function has_chosen_zero_fee(): bool { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
		$customer = WC()->customer ?? null; // @phpstan-ignore nullCoalesce.property
		if ( ! is_object( $customer )
			|| ! method_exists( $customer, 'has_shipping_address' )
			|| ! $customer->has_shipping_address()
			|| ! WC()->cart->needs_shipping()
			|| WC()->cart->get_shipping_total() > 0 ) {
			return false;
		}

		$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : null;
		if ( ! is_array( $chosen ) || empty( $chosen ) ) {
			return false;
		}

		$packages = WC()->shipping ? WC()->shipping->get_packages() : array(); // @phpstan-ignore property.notFound
		if ( ! is_array( $packages ) || empty( $packages ) ) {
			return false;
		}

		foreach ( $chosen as $package_key => $rate_id ) {
			$rate_id = (string) $rate_id;
			if ( '' === $rate_id || $this->is_pickup_rate( $rate_id ) ) {
				return false;
			}

			$rates = $packages[ $package_key ]['rates'] ?? array();
			$rate  = is_array( $rates ) ? ( $rates[ $rate_id ] ?? null ) : null;
			if ( ! is_object( $rate ) ) {
				return false;
			}

			$cost = method_exists( $rate, 'get_cost' )
				? (float) $rate->get_cost()
				: (float) ( $rate->cost ?? 0 );
			if ( $cost > 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Override visual conservador.
	 * Se mantiene en 0 para que la única fuente de verdad sea el umbral del transportista (carrier threshold).
	 */
	private function get_threshold_override(): float {
		return 0.0;
	}

	/**
	 * Umbral que el transportista aplica dentro de su propia tarifa (fuente única de verdad).
	 *
	 * En Akibara el envío gratis lo otorga Blue Express (`isShipmentFree`), no una
	 * instancia core `free_shipping`: las de las zonas están deshabilitadas a
	 * propósito para no publicar una tarifa paralela sin servicio Blue asociado.
	 * Sin este valor la barra queda permanentemente en `unavailable`.
	 *
	 * @since 1.97.0
	 */
	private function get_carrier_threshold(): float {
		$threshold = (float) get_option( self::OPTION_CARRIER_THRESHOLD, self::DEFAULT_CARRIER_THRESHOLD );
		return $threshold > 0.0 ? $threshold : (float) self::DEFAULT_CARRIER_THRESHOLD;
	}

	private function get_chosen_shipping_id(): ?string {
		$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : null;
		if ( ! is_array( $chosen ) || empty( $chosen[0] ) ) {
			return null;
		}
		return (string) $chosen[0];
	}

	private function is_pickup_rate( string $rate_id ): bool {
		if ( 0 === strpos( $rate_id, 'local_pickup' ) || 0 === strpos( $rate_id, 'bluex-pudo' ) ) {
			return true;
		}
		$title = $this->get_chosen_shipping_title();
		return strpos( $title, 'retiro' ) !== false || strpos( $title, 'pickup' ) !== false;
	}

	private function get_chosen_shipping_title(): string {
		$chosen_id = $this->get_chosen_shipping_id();
		if ( ! $chosen_id ) {
			return '';
		}
		$packages = WC()->shipping ? WC()->shipping->get_packages() : array(); // @phpstan-ignore property.notFound
		if ( empty( $packages ) ) {
			return '';
		}
		$package = reset( $packages );
		if ( isset( $package['rates'][ $chosen_id ] ) ) {
			return strtolower( (string) $package['rates'][ $chosen_id ]->get_label() );
		}
		return '';
	}

	/**
	 * ¿El customer eligió explícitamente retiro en tienda?
	 *
	 * WC asigna automáticamente el primer rate disponible cuando no hay sesión
	 * de shipping todavía. Si la única opción que el package pudo calcular
	 * es retiro, el customer NO eligió pickup — fue default forzado.
	 *
	 * Tratamos como elección real solo si:
	 *   - el package tiene >= 2 rates disponibles (hubo alternativas), Y
	 *   - estamos en /checkout/ (UI visible para elegir) o hay shipping
	 *     address completa (customer pasó por el shipping selector).
	 */
	private function is_local_pickup_chosen(): bool {
		$chosen = $this->get_chosen_shipping_id();
		if ( null === $chosen || ! $this->is_pickup_rate( $chosen ) ) {
			return false;
		}

		$packages   = WC()->shipping ? WC()->shipping->get_packages() : array(); // @phpstan-ignore property.notFound
		$package    = reset( $packages );
		$rate_count = $package && ! empty( $package['rates'] ) ? count( $package['rates'] ) : 0;

		// Única opción disponible → WC lo asignó por default, no fue elección.
		if ( $rate_count < 2 ) {
			return false;
		}

		// Hay alternativas: respetar la elección solo si hay UI visible (checkout)
		// o si el customer ya completó address (pasó por el shipping selector).
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( WC()->customer && WC()->customer->has_shipping_address() ) {
			return true;
		}

		return false;
	}

	/**
	 * El porcentaje de avance, con UNA sola definición.
	 *
	 * El marcado lo usaba sin redondear y el estado estructurado redondeado a dos
	 * decimales, así que un carrito de 79,996 % pintaba «¡CASI LO LOGRAS!» y una
	 * barra de ancho 79.99600000000001 % mientras el estado que consumen los
	 * bloques decía 80,0 y `almost_there`. Dos superficies discrepando sobre el
	 * mismo carrito.
	 */
	private static function progress_percent( float $cart_total, float $threshold ): float {
		if ( $threshold <= 0.0 ) {
			return 0.0;
		}

		return round( min( 100.0, max( 0.0, ( $cart_total / $threshold ) * 100 ) ), 2 );
	}

	private function render_progress( float $cart_total, float $threshold ): string {
		$remaining = max( 0.0, $threshold - $cart_total );
		$percent   = self::progress_percent( $cart_total, $threshold );

		$title   = $this->apply_placeholders( $this->get_string( 'title' ), $remaining, $threshold );
		$message = $this->apply_placeholders( $this->get_string( 'message' ), $remaining, $threshold );

		// El styling del estado "casi lo logras" vive en free-shipping-progress.css
		// (.akb-fsp-almost-there); acá solo se emite la clase switch. Antes el
		// <style> viajaba dentro del fragment y se acumulaba en el DOM con cada
		// add-to-cart / update_order_review.
		$almost_there_class = $percent >= 80 ? ' akb-fsp-almost-there' : '';

		ob_start();
		?>
		<div class="akb-fsp-wrap akb-fsp-active<?php echo esc_attr( $almost_there_class ); ?>">
			<div class="akb-fsp-title"><?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $this->kses() es un wrapper de wp_kses() con lista blanca estricta (bdi/span/strong/em); el sniff no conoce el wrapper. */ echo $this->kses( $title ); ?></div>
			<?php
			/*
			 * Semántica de progreso explícita, igual que la barra de Blocks
			 * (cart-checkout-blocks.js). Sin ella el track era un <div> decorativo:
			 * un lector de pantalla oía el mensaje pero no tenía forma de consultar
			 * "¿cuánto llevo?" como valor. El aria-label repite el título ya
			 * calculado en texto plano para no depender del sello, que el drawer
			 * oculta por densidad.
			 */
			?>
			<div
				class="akb-fsp-track"
				role="progressbar"
				aria-valuemin="0"
				aria-valuemax="100"
				aria-valuenow="<?php echo esc_attr( (string) (int) round( $percent ) ); ?>"
				aria-label="<?php echo esc_attr( self::plain_text( $title ) ); ?>"
			>
				<span class="akb-fsp-fill" style="width:<?php echo esc_attr( (string) $percent ); ?>%">
					<span class="akb-fsp-truck" aria-hidden="true"></span>
				</span>
			</div>
			<div class="akb-fsp-message">
				<?php if ( $percent >= 80 ) : ?>
					<?php /* SVG inline del icono "warning": NO depende de la fuente dashicons (el módulo nunca la encola para invitados → un glifo dashicons renderiza tofu). currentColor hereda el color del texto; sin request de fuente extra. */ ?>
					<svg class="akb-fsp-warn" width="1em" height="1em" viewBox="0 0 24 24" aria-hidden="true" focusable="false" style="vertical-align:-.125em;fill:currentColor"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg> ¡CASI LO LOGRAS! <?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $this->kses() es un wrapper de wp_kses() con lista blanca estricta (bdi/span/strong/em); el sniff no conoce el wrapper. */ echo $this->kses( $message ); ?>
				<?php else : ?>
					<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $this->kses() es un wrapper de wp_kses() con lista blanca estricta (bdi/span/strong/em); el sniff no conoce el wrapper. */ echo $this->kses( $message ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function render_qualified( float $threshold ): string {
		$qualified = $this->apply_placeholders( $this->get_string( 'qualified' ), 0.0, $threshold );

		ob_start();
		?>
		<div class="akb-fsp-wrap akb-fsp-qualified">
			<div class="akb-fsp-message"><?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $this->kses() es un wrapper de wp_kses() con lista blanca estricta (bdi/span/strong/em); el sniff no conoce el wrapper. */ echo $this->kses( $qualified ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function get_string( string $key ): string {
		$option_map = array(
			'title'     => self::OPTION_TITLE,
			'message'   => self::OPTION_MESSAGE,
			'qualified' => self::OPTION_QUALIFIED,
		);
		$default_map = array(
			'title'     => self::DEFAULT_TITLE,
			'message'   => self::DEFAULT_MESSAGE,
			'qualified' => self::DEFAULT_QUALIFIED,
		);
		$raw = (string) get_option( $option_map[ $key ], '' );
		return '' !== trim( $raw ) ? $raw : $default_map[ $key ];
	}

	private function apply_placeholders( string $template, float $remaining, float $threshold ): string {
		$subtotal = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_subtotal() : '';

		return str_replace(
			array( '{remaining}', '{free_shipping_amount}', '{subtotal}' ),
			array( wc_price( $remaining ), wc_price( $threshold ), $subtotal ),
			$template
		);
	}

	/**
	 * Applies free-shipping placeholders as plain text.
	 *
	 * @param string $template  Copy template.
	 * @param float  $remaining Remaining amount.
	 * @param float  $threshold Free-shipping threshold.
	 * @param float  $subtotal  Eligible cart total.
	 * @return string
	 */
	private function apply_placeholders_plain(
		string $template,
		float $remaining,
		float $threshold,
		float $subtotal
	): string {
		return self::plain_text(
			str_replace(
				array( '{remaining}', '{free_shipping_amount}', '{subtotal}' ),
				array(
					self::plain_price( $remaining ),
					self::plain_price( $threshold ),
					self::plain_price( $subtotal ),
				),
				$template
			)
		);
	}

	/**
	 * Formats a monetary amount without markup.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function plain_price( float $amount ): string {
		$formatted = function_exists( 'wc_price' )
			? (string) wc_price( $amount )
			: '$' . number_format( $amount, 0, ',', '.' );

		return self::plain_text( $formatted );
	}

	/**
	 * Normalizes dynamic copy to a single plain-text line.
	 *
	 * @param string $text Raw copy.
	 * @return string
	 */
	private static function plain_text( string $text ): string {
		$decoded = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain   = preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', $decoded ) );

		return trim( is_string( $plain ) ? $plain : '' );
	}

	private function kses( string $html ): string {
		return wp_kses(
			$html,
			array(
				'bdi'    => array(),
				'span'   => array( 'class' => array() ),
				'strong' => array(),
				'em'     => array(),
			)
		);
	}

	public function get_settings(): array {
		return array(
			array(
				'title' => 'Barra de envío gratis',
				'type'  => 'title',
				'desc'  => 'Indica cuánto falta para alcanzar envío gratis. Business renderiza el carrito nativo y Akibara Cart and Checkout Blocks presenta el estado estructurado en carro/checkout. Acepta <code>{remaining}</code>, <code>{free_shipping_amount}</code> y <code>{subtotal}</code>.',
				'id'    => 'akibara_fsp_section',
			),
			// Los checkboxes necesitan desc: WooCommerce lo inserta dentro del label
			// y lo usa como nombre accesible.
			array(
				'title'   => 'Activar',
				'desc'    => 'Muestra la barra de envío gratis en la tienda. Al desactivarla se conservan los ajustes.',
				'id'      => self::OPTION_ENABLED,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'             => 'Umbral de envío gratis a domicilio (CLP)',
				'desc'              => 'Monto sobre el cual Blue Express entrega gratis a domicilio dentro de su propia tarifa ($60.000 CLP por defecto). Este monto único gobierna la barra de envío gratis, el envío gratis a domicilio con Blue Express, los avisos de despacho y la protección de envío gratis al canjear Puntos Akibara (el retiro en Punto Blue y retiro en tienda no aplican).',
				'id'                => self::OPTION_CARRIER_THRESHOLD,
				'type'              => 'number',
				'default'           => (string) self::DEFAULT_CARRIER_THRESHOLD,
				'custom_attributes' => array( 'min' => '0', 'step' => '1000' ),
				'css'               => 'width:120px;',
			),
			array(
				'title'   => 'Mostrar en mini-carro',
				'desc'    => 'La barra aparece en el mini-carro (el cajón lateral).',
				'id'      => self::OPTION_SHOW_MINI_CART,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => 'Mostrar en carrito',
				'desc'    => 'La barra aparece en la página del carrito.',
				'id'      => self::OPTION_SHOW_CART,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => 'Mostrar en checkout',
				'desc'    => 'La barra aparece en el checkout, sobre el resumen del pedido.',
				'id'      => self::OPTION_SHOW_CHECKOUT,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => 'Mostrar mensaje "calificado"',
				'desc'    => 'Mensaje que aparece cuando el cliente ya alcanzó el umbral.',
				'id'      => self::OPTION_SHOW_QUALIFIED,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => 'Ocultar si retiro en tienda',
				'desc'    => 'Si el cliente eligió retiro en tienda como método de envío, la barra no aparece.',
				'id'      => self::OPTION_DISABLE_LOCAL_PICKUP,
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => 'Título (con barra)',
				'desc'    => 'Sello superior cuando aún falta llegar al umbral.',
				'id'      => self::OPTION_TITLE,
				'type'    => 'text',
				'default' => self::DEFAULT_TITLE,
				'css'     => 'width:480px;',
			),
			array(
				'title'   => 'Mensaje (con barra)',
				'desc'    => 'Mensaje principal cuando aún falta llegar al umbral.',
				'id'      => self::OPTION_MESSAGE,
				'type'    => 'text',
				'default' => self::DEFAULT_MESSAGE,
				'css'     => 'width:480px;',
			),
			array(
				'title'   => 'Mensaje calificado',
				'desc'    => 'Mensaje cuando el cliente alcanzó el umbral.',
				'id'      => self::OPTION_QUALIFIED,
				'type'    => 'text',
				'default' => self::DEFAULT_QUALIFIED,
				'css'     => 'width:480px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akibara_fsp_section',
			),
		);
	}

	/**
	 * Evalúa el umbral comercial de envío gratis previo a la aplicación de descuentos por cupones.
	 *
	 * Previene que cupones porcentuales (ej. popup10) reduzcan el subtotal bajo los $60.000 CLP
	 * y penalicen al cliente con cobro de flete.
	 *
	 * @param bool                $is_available Disponibilidad calculada por WooCommerce.
	 * @param array<string,mixed> $package      Paquete de envío.
	 * @param mixed               $method       Instancia de método de envío.
	 * @return bool
	 */
	public function filter_free_shipping_is_available( $is_available, $package, $method ): bool {
		unset( $package, $method );

		$threshold = (float) get_option( self::OPTION_CARRIER_THRESHOLD, 60000.0 );
		if ( $threshold <= 0.0 ) {
			$threshold = 60000.0;
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			$subtotal = (float) WC()->cart->get_displayed_subtotal();
			if ( $subtotal >= $threshold ) {
				return true;
			}
		}

		return (bool) $is_available;
	}
}
