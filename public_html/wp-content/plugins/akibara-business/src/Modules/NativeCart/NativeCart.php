<?php
/**
 * Native Cart — Carrito lateral (Off-Canvas) nativo de Akibara.
 *
 * Reemplaza al plugin de terceros (side-cart-woocommerce/XootiX) por una solución
 * nativa, hiper-ligera y con lógica de Bundles inyectada desde el backend.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\NativeCart;

use Akibara\Business\Modules\CartQuantity\CartQuantityCeiling;
use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\Settings as SeriesBundlesSettings;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

final class NativeCart {

	private Settings $settings;

	/**
	 * Resumen de llegada del carrito. Opcional: el drawer sabe funcionar sin él
	 * (payload sin nota), y así las pruebas del módulo no arrastran el
	 * calculador entero para verificar cantidades y avisos.
	 */
	private ?BannerRenderer $eta_renderer;

	/**
	 * ¿WooCommerce ya calculó los totales en ESTA petición?
	 *
	 * `calculate_totals()` recorre líneas, cupones y envío, y los dos handlers de
	 * mutación ya lo disparan por dentro antes de delegar en `ajax_get_cart()`:
	 * `remove_cart_item()` lo llama vía `woocommerce_cart_item_removed` y
	 * `set_quantity()` lo llama directo. La sesión hace lo mismo al cargar el
	 * carrito cuando los totales no venían cacheados. Cada mutación del drawer
	 * pagaba, por tanto, dos cálculos completos.
	 *
	 * Se observa el hook propio de WooCommerce en vez de llevar la cuenta a mano:
	 * así el ahorro vale para cualquier camino que ya haya calculado (aplicar un
	 * cupón, un `add_to_cart` en la misma petición) y no hay una segunda lista de
	 * «quién recalcula» que mantener sincronizada.
	 */
	private bool $totals_calculated = false;

	public function __construct( ?Settings $settings = null, ?BannerRenderer $eta_renderer = null ) {
		$this->settings     = $settings ?? new Settings();
		$this->eta_renderer = $eta_renderer;
	}

	public function register(): void {
		$this->settings->register();

		// Endpoint AJAX para leer estado del carrito
		add_action( 'wc_ajax_akb_get_cart', array( $this, 'ajax_get_cart' ) );
		// Endpoint AJAX para eliminar items
		add_action( 'wc_ajax_akb_remove_cart_items', array( $this, 'ajax_remove_cart_items' ) );
		// Endpoint AJAX para actualizar cantidades
		add_action( 'wc_ajax_akb_update_cart_item', array( $this, 'ajax_update_cart_item' ) );

		// Testigo del cálculo de totales (ver `$totals_calculated`).
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'mark_totals_calculated' ) );

		// Scripts y estilos
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Footer HTML
		add_action( 'wp_footer', array( $this, 'render_drawer_html' ) );
	}

	/**
	 * Oyente de `woocommerce_after_calculate_totals`. No toca el carrito: sólo
	 * anota que los totales de esta petición ya están al día.
	 */
	public function mark_totals_calculated(): void {
		$this->totals_calculated = true;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return $this->settings->get_settings();
	}

	public function enqueue_assets(): void {
		if ( ! $this->settings->is_enabled() || is_checkout() || is_cart() ) {
			return; // No cargamos el minicart en páginas dedicadas de checkout/cart
		}

		wp_enqueue_style(
			'akibara-native-cart',
			AKIBARA_BUSINESS_URL . 'assets/css/native-cart-v2.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/native-cart-v2.css' )
		);

		wp_enqueue_script(
			'akibara-native-cart',
			AKIBARA_BUSINESS_URL . 'assets/js/native-cart.js',
			array( 'jquery' ), // added_to_cart es evento jQuery de WC; sin la dep el binding se pierde si jQuery aún no cargó.
			PluginAssetVersion::for_path( 'assets/js/native-cart.js' ),
			true
		);

		// Variables necesarias para JS.
		wp_localize_script(
			'akibara-native-cart',
			'akbNativeCart',
			array_merge(
				array(
					'ajaxUrl'     => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
					'cartUrl'     => wc_get_cart_url(),
					'checkoutUrl' => wc_get_checkout_url(),
					'nonce'       => wp_create_nonce( Settings::NONCE_ACTION ),
				),
				$this->settings->to_script_data()
			)
		);
	}

	public function render_drawer_html(): void {
		if ( ! $this->settings->is_enabled() || is_checkout() || is_cart() ) {
			return;
		}
		?>
		<div id="akb-native-cart-overlay" class="akb-cart-overlay" aria-hidden="true"></div>
		<div
			id="akb-native-cart-drawer"
			class="akb-cart-drawer <?php echo $this->settings->mobile_fullscreen() ? 'akb-cart-drawer--mobile-full' : ''; ?> <?php echo $this->settings->prefers_reduced_motion() ? 'akb-reduced-motion' : ''; ?>"
			role="dialog"
			aria-modal="true"
			aria-labelledby="akb-native-cart-title"
			aria-hidden="true"
			tabindex="-1"
			data-side="<?php echo esc_attr( $this->settings->get_drawer_side() ); ?>"
			style="--akb-cart-width: <?php echo esc_attr( (string) $this->settings->get_drawer_width() ); ?>px;"
		>
			<div class="akb-cart-header">
				<h3 id="akb-native-cart-title">Tu carrito</h3>
				<button type="button" class="akb-cart-close" aria-label="Cerrar carrito">
					<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path d="M6 6l12 12M18 6 6 18"></path>
					</svg>
				</button>
			</div>
			<div class="akb-cart-live" aria-live="polite" aria-atomic="true"></div>
			<div class="akb-cart-body">
				<!--
					Avisos gateados (mezcla preventa/despacho, "Arma tu Pack") ARRIBA de
					los ítems: los cross-sells se inyectan dentro de items-container, así
					que con el status al final quedaba enterrado bajo el scroll —
					invisible al abrir el drawer. aria-live="polite" para que renderStatus
					los anuncie a lectores de pantalla (antes escribía al DOM sin pasar por
					ninguna live-region).
				-->
				<div class="akb-cart-status" data-akb-cart-status aria-live="polite"></div>
				<div class="akb-cart-items-container">
					<!-- Se inyecta vía JS -->
					<div class="akb-cart-loading" role="status" aria-label="<?php echo esc_attr( 'Cargando carrito...' ); ?>">
						<span class="screen-reader-text"><?php echo esc_html( 'Cargando carrito...' ); ?></span>
						<div class="akb-cart-skeleton-list" aria-hidden="true">
							<div class="akb-cart-skeleton-item">
								<div class="akb-cart-skeleton-thumb"></div>
								<div class="akb-cart-skeleton-details">
									<div class="akb-cart-skeleton-line akb-cart-skeleton-line--title"></div>
									<div class="akb-cart-skeleton-line akb-cart-skeleton-line--sub"></div>
									<div class="akb-cart-skeleton-line akb-cart-skeleton-line--price"></div>
								</div>
							</div>
							<div class="akb-cart-skeleton-item">
								<div class="akb-cart-skeleton-thumb"></div>
								<div class="akb-cart-skeleton-details">
									<div class="akb-cart-skeleton-line akb-cart-skeleton-line--title"></div>
									<div class="akb-cart-skeleton-line akb-cart-skeleton-line--sub"></div>
									<div class="akb-cart-skeleton-line akb-cart-skeleton-line--price"></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="akb-cart-footer">
				<?php if ( $this->settings->show_free_shipping_progress() ) : ?>
					<?php
					/*
					 * Slot VACÍO a propósito. El JS lo vacía y lo rellena con el
					 * `fsp_html` de cada respuesta (`renderCart()`), así que pintar
					 * aquí la barra costaba un cálculo completo de umbral —cupones,
					 * paquetes y tarifas— en el `wp_footer` de CADA página del sitio
					 * para producir un marcado que nadie llegaba a ver.
					 */
					?>
					<div class="akb-cart-progress-slot"></div>
				<?php endif; ?>
				<div class="akb-cart-totals" <?php echo $this->settings->show_subtotal() ? '' : 'hidden'; ?>>
					<div class="akb-cart-totals__row akb-cart-subtotal">
						<span>Subtotal:</span>
						<span class="akb-cart-subtotal-val"></span>
					</div>
					<div class="akb-cart-totals__row akb-cart-discount" hidden>
						<span>Descuentos:</span>
						<span class="akb-cart-discount-val"></span>
					</div>
					<div class="akb-cart-totals__row akb-cart-total" hidden>
						<span>Total:</span>
						<span class="akb-cart-total-val"></span>
					</div>
				</div>
				<div class="akb-cart-shipping-notice"></div>
				<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="akb-cart-checkout-btn"><?php echo esc_html( $this->settings->get_checkout_copy() ); ?></a>
				<a href="<?php echo esc_url( $this->settings->get_continue_url() ); ?>" class="akb-cart-continue-btn"><?php echo esc_html( $this->settings->get_continue_copy() ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Devuelve el estado del carrito al drawer.
	 *
	 * @param string $notice Aviso puntual de esta mutación (p. ej. una línea que
	 *                       se retiró por quedarse sin stock). El drawer lo pinta
	 *                       en su región viva, así que el cliente se entera de por
	 *                       qué cambió su carrito en vez de ver desaparecer algo.
	 */
	public function ajax_get_cart( string $notice = '' ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ver el docblock de `drain_error_notices()`: el drenaje es opt-in y sólo toca la cola de avisos de quien llama.
		$pide_drenaje = isset( $_GET['drain_notices'] ) && '1' === $_GET['drain_notices'];
		$errores      = $pide_drenaje ? $this->drain_error_notices() : array();

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( 'No cart available' );
		}

		if ( ! $this->totals_calculated ) {
			WC()->cart->calculate_totals();
		}

		// El CÓDIGO del rechazo, junto al texto (): quien pide el drenaje
		// dice qué producto y cuántas unidades intentó agregar, y la respuesta
		// trae `ya_en_carrito` / `sin_stock` para que la ficha y las tarjetas
		// cambien el botón sin clasificar el aviso, que es texto libre y
		// traducible. Sólo si hubo un motivo que mostrar: sin él no hay rechazo
		// que explicar. Ver `RejectionReason`.
		$motivo = array() !== $errores ? $this->rejection_reason() : '';

		$items_payload = array();
		$discount_total = (float) WC()->cart->get_discount_total();
		if ( WC()->cart->display_prices_including_tax() ) {
			$discount_total += (float) WC()->cart->get_discount_tax();
		}
		$has_discount = $discount_total > 0;

		// Recolectar los ítems del carrito (todos sueltos: no hay packs curados).
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de WooCommerce core: se re-aplica con su nombre original para que los callbacks de terceros sigan viéndolo.
			if ( ! $_product || ! $_product->exists() ) {
				continue;
			}

			// La URL de la miniatura se resuelve UNA vez y sirve a las dos claves:
			// antes cada línea pedía `get_image_id()` cuatro veces y hacía dos
			// `wp_get_attachment_image_url()` idénticas para el mismo adjunto.
			$thumbnail_url = $this->thumbnail_url( $_product );

			$item_eta = null !== $this->eta_renderer && StockStatus::is_reservable( $_product )
				? $this->eta_renderer->summarize_cart( array( $cart_item ) )
				: null;

			$regular_price_subtotal = (float) $_product->get_regular_price() * (float) $cart_item['quantity'];
			$line_subtotal = WC()->cart->display_prices_including_tax()
				? (float) ( $cart_item['line_subtotal'] ?? 0 ) + (float) ( $cart_item['line_subtotal_tax'] ?? 0 )
				: (float) ( $cart_item['line_subtotal'] ?? 0 );
			$line_total = WC()->cart->display_prices_including_tax()
				? (float) ( $cart_item['line_total'] ?? 0 ) + (float) ( $cart_item['line_tax'] ?? 0 )
				: (float) ( $cart_item['line_total'] ?? 0 );

			$base_subtotal = max( $regular_price_subtotal, $line_subtotal );
			if ( round( $line_total ) < round( $base_subtotal ) && $base_subtotal > 0 ) {
				$item_price_html = '<del>' . wc_price( round( $base_subtotal ) ) . '</del> <ins>' . wc_price( round( $line_total ) ) . '</ins>';
			} else {
				$item_price_html = apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de WooCommerce core: se re-aplica con su nombre original para que los callbacks de terceros sigan viéndolo.
			}

			$items_payload[] = array(
				'id'             => $cart_item['product_id'],
				'title'          => $_product->get_name(),
				'stock_label'    => Availability::label_of( $_product ),
				'eta'            => $item_eta['value'] ?? '',
				'quantity'       => $cart_item['quantity'],
				'price_html'     => $item_price_html,
				'thumbnail'      => '' !== $thumbnail_url
					? '<img src="' . esc_url( $thumbnail_url ) . '" alt="' . esc_attr( $_product->get_name() ) . '" loading="lazy" width="72" height="96">'
					: wc_placeholder_img( 'woocommerce_thumbnail' ),
				'thumbnail_url'  => $thumbnail_url,
				'keys_to_remove' => array( $cart_item_key ),
				// Conservar cero y acotar cantidades positivas al máximo. El sentinel -1
				// sin límite se convierte al techo de WooCommerce9999 antes de enviarlo al JS,
				// para que drawer y Store API compartan el mismo límite.

				'max'            => CartQuantityCeiling::maximo_para( $_product->get_max_purchase_quantity() ),
			);
		}

		// get_shipping_total() igual a cero no distingue envío gratuito de envío
		// pendiente de cotizar. Consultar por filtro FreeShippingProgress::is_qualified()
		// para compartir la regla de umbral sin acoplar el drawer a ese módulo.

		$shipping_notice = 'El envío se calcula al finalizar la compra.';
		if ( WC()->cart->needs_shipping()
			&& ( WC()->cart->get_shipping_total() > 0 || apply_filters( 'akb_native_cart_fsp_qualified', false ) ) ) {
			$shipping_notice = 'Envío incluido en el total. Impuestos y descuentos finales al pagar.';
		}

		// 3. Obtener cross-sells basados en los ítems del carrito
		$cross_sells = array();
		if ( $this->settings->show_recommendations()
			&& Settings::RECOMMENDATION_SOURCE_CROSS_SELLS === $this->settings->get_recommendation_source() ) {
			$cross_sells_ids = \WC()->cart->get_cross_sells();
			$limit           = $this->settings->get_recommendation_limit();

			$con_miniatura = $this->settings->show_thumbnails();

			if ( ! empty( $cross_sells_ids ) && function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( array_map( 'intval', $cross_sells_ids ), true, true );
			}

			foreach ( $cross_sells_ids as $cs_id ) {
				$cs = wc_get_product( $cs_id );
				if ( $cs && $cs->is_type( 'simple' ) && $cs->is_purchasable() && $cs->is_in_stock() ) {
					$thumbnail_url = $this->thumbnail_url( $cs );

					$cross_sells[] = array(
						'id'            => $cs->get_id(),
						'title'         => $cs->get_name(),
						'price_html'    => $cs->get_price_html(),
						'thumbnail'     => $con_miniatura
							? ( '' !== $thumbnail_url
								? '<img src="' . esc_url( $thumbnail_url ) . '" alt="' . esc_attr( $cs->get_name() ) . '" loading="lazy" width="40" height="55">'
								: wc_placeholder_img( 'woocommerce_thumbnail' ) )
							: '',
						'thumbnail_url' => $thumbnail_url,
					);
					if ( count( $cross_sells ) >= $limit ) {
						break;
					}
				}
			}
		}

		// Banderas de contenido del carrito para gatear los avisos del drawer: sin
		// esto, "Arma tu Pack" y el de preventa mixta se pintaban con los toggles
		// globales ON aunque el carrito no tuviera ni pack ni preventa (copy falso).
		$cart_flags = $this->detect_cart_flags( WC()->cart, $this->make_series_collector() );

		wp_send_json_success( array(
			'cart_count'      => WC()->cart->get_cart_contents_count(),
			'subtotal_html'   => WC()->cart->get_cart_subtotal(),
			// El subtotal de WooCommerce es deliberadamente previo a cupones. El
			// drawer debe mostrar también descuento y total cuando corresponda para
			// no presentar $49.950 como si fuera el precio final de un pack de
			// $44.955. Estos valores vienen del carrito ya calculado: no se replica
			// ni altera la lógica de stacking/mejor-precio del motor de bundles.
			'has_discount'    => $has_discount,
			'discount_html'   => $has_discount ? '-' . wc_price( $discount_total ) : '',
			'total_html'      => WC()->cart->get_total(),
			'items'           => $items_payload,
			// Gates de los avisos del drawer (los consume renderStatus en el JS): el
			// aviso de pack solo si el carrito tiene membresía BYP; el de preventa
			// solo si algún tomo es preventa.
			'has_preventa'    => $cart_flags['has_preventa'],
			// La frase YA elegida para este carrito: el JS la prefiere sobre
			// la copy estática (payloads viejos caen al fallback sin mentir).
			'preventa_policy' => $this->settings->build_preventa_policy_copy( (string) $cart_flags['preventa_tipo'] ),
			// En qué pedido entra la compra y cuánto viaja DESDE ESE CIERRE. El
			// dato ya existía en la ficha y moría ahí: el cliente decidía con la
			// explicación completa y llegaba a pagar con una fecha sin porqué,
			// que es justo donde la duda «¿desde cuándo cuentan las semanas?»
			// cuesta la venta. Texto plano; el drawer lo pinta con textContent.
			'preventa_nota'   => $this->nota_de_llegada(),
			'has_byp'         => $cart_flags['has_byp'],
			'cross_sells'     => $cross_sells,
			'shipping_notice' => $shipping_notice,
			'notice'          => $notice,
			// Motivo del rechazo ya sin markup, su código y a dónde mandar al cliente.
			'errors'          => $errores,
			'motivo'          => $motivo,
			'cart_url'        => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			/**
			 * Filtra el HTML de la barra de envío gratis que se reinyecta en el
			 * payload del Native Cart en cada mutación AJAX (el cajón no usa
			 * wc-cart-fragments: refresca por su propio endpoint, por eso va por filtro).
			 *
			 * @since 1.49.9
			 * @param string $html HTML de la barra; cadena vacía si no aplica.
			 */
			'fsp_html'        => apply_filters( 'akb_native_cart_fsp_html', '' ),
			'settings'        => $this->settings->to_script_data(),
			// Nonce fresco en CADA respuesta: el nonce embebido por wp_localize_script
			// viaja en HTML cacheable (TTL público 7d ≫ vida del nonce 24h) y muere
			// para invitados con página cacheada. Este endpoint es wc-ajax (no-cache),
			// y remove/update delegan aquí, así que toda lectura/mutación exitosa
			// transporta un nonce vigente que el JS adopta (auto-sanado sin ESI).
			'nonce'           => wp_create_nonce( Settings::NONCE_ACTION ),
		) );
	}

	/**
	 * Drena los avisos de error de la sesión y devuelve texto plano.
	 *
	 * WC_AJAX::add_to_cart() responde error y product_url; el motivo detallado queda
	 * en los avisos de sesión de WC_Cart::add_to_cart(). Se extrae como texto plano
	 * porque el aviso puede contener HTML propio de WooCommerce o de terceros.
	 *
	 * Drenar muta la sesión y requiere drain_notices=1: el consumidor debe mostrar
	 * los textos recibidos para no perder el motivo del rechazo ni trasladarlo a
	 * una página posterior. No requiere nonce porque sólo opera sobre los avisos
	 * de la sesión que llama. Las fichas públicas pueden estar cacheadas; un nonce
	 * incluido en su HTML no debe impedir recibir el error. Cada respuesta entrega
	 * un nonce fresco para las mutaciones que sí lo requieren.
	 *
	 * @return array<int, string>
	 */
	private function drain_error_notices(): array {
		if ( ! function_exists( 'wc_get_notices' ) ) {
			return array();
		}

		$errores = array();
		foreach ( (array) wc_get_notices( 'error' ) as $aviso ) {
			$texto = is_array( $aviso ) ? (string) ( $aviso['notice'] ?? '' ) : (string) $aviso;

			// El aviso de WooCommerce ya trae su propio enlace «Ver carrito» dentro
			// de un <a>. Si sólo se quitan las etiquetas, su TEXTO sobrevive y el
			// cliente ve «Ver carrito» dos veces: la del mensaje y la que arma el
			// cliente. Se elimina el ancla entera, no sus etiquetas.
			$texto = (string) preg_replace( '#<a\b[^>]*>.*?</a>#is', '', $texto );
			$texto = wp_strip_all_tags( $texto );

			// Y se decodifican las entidades: el mensaje trae `&mdash;`, que sin
			// decodificar se le muestra al cliente tal cual, en crudo.
			$texto = trim( html_entity_decode( $texto, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' !== $texto ) {
				$errores[] = $texto;
			}
		}

		if ( array() !== $errores ) {
			wc_clear_notices( 'error' );
		}

		return $errores;
	}

	/**
	 * Código del rechazo del producto que la petición dice haber intentado
	 * agregar (`product_id` y `quantity` en la query), leído del estado actual
	 * del producto y del carrito. '' si no lo dice o el estado no lo explica.
	 *
	 * Sólo lee: el producto y las cantidades del carrito de la propia sesión.
	 *
	 * @return string
	 */
	private function rejection_reason(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- sólo lee el catálogo y el carrito de quien llama; ver el docblock de `drain_error_notices()`.
		$producto_id = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
		$cantidad    = isset( $_GET['quantity'] ) ? absint( wp_unslash( $_GET['quantity'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $producto_id < 1 || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$producto = wc_get_product( $producto_id );
		if ( ! $producto instanceof \WC_Product ) {
			return '';
		}

		$en_carrito = (array) WC()->cart->get_cart_item_quantities();

		return RejectionReason::for_product(
			$producto,
			$cantidad,
			(int) ( $en_carrito[ $producto->get_stock_managed_by_id() ] ?? 0 )
		);
	}

	public function ajax_remove_cart_items(): void {
		check_ajax_referer( Settings::NONCE_ACTION, 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( 'No cart available' );
		}

		$keys = isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ? wc_clean( wp_unslash( $_POST['keys'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() es el saneador de WooCommerce (sanitize_text_field recursivo); el sniff sólo conoce la lista de WP core.

		foreach ( $keys as $key ) {
			WC()->cart->remove_cart_item( $key );
		}

		// Recalculate and return full cart state again
		$this->ajax_get_cart();
	}

	public function ajax_update_cart_item(): void {
		check_ajax_referer( Settings::NONCE_ACTION, 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( 'No cart available' );
		}

		$key = isset( $_POST['key'] ) ? wc_clean( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() es el saneador de WooCommerce (sanitize_text_field recursivo); el sniff sólo conoce la lista de WP core.
		$qty = isset( $_POST['qty'] ) ? (int) $_POST['qty'] : 1;

		if ( ! empty( $key ) && $qty > 0 ) {
			// Clamp server-side al máximo comprable: la UI puede saltarse el límite,
			// pero un qty > stock deja el carrito sin poder pasar el checkout.
			$cart_item = WC()->cart->get_cart_item( $key );
			if ( ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) {
				// Convertir el sentinel -1 al máximo de WooCommerce9999 antes de validar,
				// para que el drawer no acepte cantidades que Store API recortará después.

				$max = CartQuantityCeiling::maximo_para( $cart_item['data']->get_max_purchase_quantity() );

				// get_max_purchase_quantity() tiene TRES valores: -1 sin límite, N como
				// máximo real y cero sin unidades comprables. El servidor, el drawer y
				// Store API deben respetarlos: cero bloquea el aumento y -1 usa el techo nativo.

				if ( 0 === $max ) {
					WC()->cart->remove_cart_item( $key );
					$this->ajax_get_cart(
						sprintf(
							'%s se agotó y lo quitamos de tu carrito.',
							$cart_item['data']->get_name()
						)
					);

					return;
				}

				if ( $max > 0 ) {
					// Clamp incondicional: neutraliza también carritos que ya venían
					// con qty > stock (agregados antes de este fix u otro flujo).
					$qty = min( $qty, $max );
				}
			}
			WC()->cart->set_quantity( $key, $qty );
		} elseif ( ! empty( $key ) && $qty <= 0 ) {
			WC()->cart->remove_cart_item( $key );
		}

		$this->ajax_get_cart();
	}

	/**
	 * La URL de la miniatura del producto, o '' si no tiene imagen destacada.
	 *
	 * `get_image_id()` devuelve un STRING (así lo guarda el CRUD de WooCommerce),
	 * y `wp_get_attachment_image_url()` devuelve `false` cuando el adjunto ya no
	 * existe: las dos puntas se normalizan aquí para que los consumidores decidan
	 * con una sola comparación contra ''.
	 */
	private function thumbnail_url( \WC_Product $product ): string {
		$image_id = (int) $product->get_image_id();
		if ( 0 === $image_id ) {
			return '';
		}

		return (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
	}

	/**
	 * La nota de tanda del carrito, o '' si no aplica.
	 *
	 * Delega ENTERA en `BannerRenderer::summarize_cart()`, que ya resuelve el
	 * corte más tardío con la misma regla conservadora que la fecha agregada. El
	 * drawer no calcula nada de esto: una segunda cuenta aquí acabaría nombrando
	 * un cierre distinto del que produjo la fecha que el cliente vio en la ficha.
	 */
	private function nota_de_llegada(): string {
		if ( null === $this->eta_renderer || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$resumen = $this->eta_renderer->summarize_cart( WC()->cart->get_cart() );

		return null === $resumen ? '' : (string) ( $resumen['nota'] ?? '' );
	}

	/**
	 * Deriva las banderas de avisos desde el contenido efectivo del carrito.
	 *
	 * StockStatus::is_reservable() detecta líneas que esperan una tanda, con
	 * independencia del motor de packs. Availability distingue su palabra A pedido
	 * y Preventa. CartSeriesCollector::collect() determina has_byp tras aplicar
	 * categoría elegible, comprabilidad, stock y tipo simple, igual que el engine.
	 *
	 * Sin collector porque el motor global está apagado, has_byp permanece falso
	 * aunque existan configuraciones de serie; has_preventa se evalúa siempre.
	 * El barrido de palabras corta al conocer ambas. El collector reutiliza términos
	 * y configuración ya cebados durante calculate_totals(), sin añadir consultas.
	 *
	 * @param \WC_Cart                 $cart      Carrito vivo.
	 * @param CartSeriesCollector|null $collector Resolutor de series compartido, o
	 *                                            null si el motor de bundles está apagado.
	 * @return array{has_preventa:bool, has_byp:bool, preventa_tipo:string}
	 */
	private function detect_cart_flags( \WC_Cart $cart, ?CartSeriesCollector $collector ): array {
		$has_preventa = false;
		$hay_preventa = false;
		$hay_a_pedido = false;
		$hay_instock  = false;

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( StockStatus::is_reservable( $product ) ) {
				$has_preventa = true;

				// Elegir la palabra comercial a partir de las líneas reales del carrito:
				// A pedido y Preventa no son etiquetas intercambiables.

				if ( \Akibara\Business\Modules\Stock\Availability::is_preventa( $product ) ) {
					$hay_preventa = true;
				} else {
					$hay_a_pedido = true;
				}
			} elseif ( $product->is_in_stock() ) {
				$hay_instock = true;
			}

			if ( $has_preventa && $hay_instock && $hay_preventa && $hay_a_pedido ) {
				break; // Ya no hay más que aprender del resto del carrito.
			}
		}

		$is_mixed = $has_preventa && $hay_instock;

		$preventa_tipo = 'pedidos';
		if ( $hay_preventa ) {
			$preventa_tipo = $hay_a_pedido ? 'preventas y pedidos' : 'preventas';
		}

		// has_byp solo si el motor arma un pack de verdad: collect() aplica los
		// guards de elegibilidad/stock/tipo y devuelve las series con ≥1 miembro
		// REALMENTE elegible. Con el motor apagado (collector null) queda en false.
		$has_byp = null !== $collector && ! empty( $collector->collect( $cart ) );

		return array(
			// Candado logístico: el aviso de mezcla («Si mezclas...») solo se muestra
			// cuando el carrito realmente combina preventa/a pedido con stock listo en bodega.
			'has_preventa'  => $is_mixed,
			'is_mixed'      => $is_mixed,
			'has_byp'       => $has_byp,
			'preventa_tipo' => $preventa_tipo,
		);
	}

	/**
	 * Arma el resolutor de series compartido. NativeCart no recibe el módulo
	 * SeriesBundles por inyección (se cablea aparte en Plugin.php), así que
	 * construye su propia instancia: los constructores son puros y
	 * `Settings::get_byp_config()` memoiza el decode del blob JSON, de modo que la
	 * resolución por ítem no agrega queries. Misma composición que usan el resto
	 * de consumidores (una `Settings` compartida por collector y guard).
	 *
	 * Devuelve null cuando el motor GLOBAL de bundles está apagado
	 * (`akb_series_bundles_enabled` = 'no'): si un admin apaga el motor dejando
	 * configs por-serie vivas, el aviso "Arma tu Pack" prometería un descuento que
	 * el motor ya no otorga. Sin collector, `detect_cart_flags` omite `has_byp` y
	 * el aviso no se pinta. La preventa no depende de este toggle.
	 */
	private function make_series_collector(): ?CartSeriesCollector {
		$bundles_settings = new SeriesBundlesSettings();
		if ( ! $bundles_settings->is_enabled() ) {
			return null;
		}
		return new CartSeriesCollector( $bundles_settings, new EligibilityGuard( $bundles_settings ) );
	}
}
