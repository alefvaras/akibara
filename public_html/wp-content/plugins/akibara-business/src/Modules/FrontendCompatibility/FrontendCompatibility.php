<?php
/**
 * Frontend compatibility fixes for third-party scripts and cache optimizers.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\FrontendCompatibility;

use Akibara\Business\Services\NavigationLinks;
use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Rewards\Support\OrderStates;

defined( 'ABSPATH' ) || exit;

final class FrontendCompatibility {

	/**
	 * Script fragments that should stay out of LiteSpeed JS combine/defer.
	 *
	 * MercadoPago checkout bundles include inline config and SDK/browser-parser
	 * code that can be corrupted by local JS combine. Excluding the fragments
	 * keeps payment scripts loading separately without disabling the gateway.
	 *
	 * @var string[]
	 */
	private const LITESPEED_CHECKOUT_SCRIPT_EXCLUSIONS = array(
		'woocommerce-mercadopago/assets/js/checkouts/',
		'woocommerce-mercadopago/assets/js/health/',
		'woocommerce-mercadopago/assets/js/melidata/',
		'woocommerce-mercadopago/assets/js/scripts/',
		'sdk.mercadopago.com/js/v2',
		'wc_mercadopago_',
		'mercadopago_',
		'mp-checkout',
		'mp-plugins-components',
	);

	public function register(): void {
		// El plugin oficial conserva el ID de producción al clonar la base de
		// datos. Sin este gate, Docker y QA contaminan grabaciones, heatmaps y
		// métricas del proyecto productivo.
		if ( ! $this->is_production_frontend() ) {
			remove_action( 'wp_head', 'clarity_add_script_to_header' );
		}

		// GeneratePress imprime el featured image sobre el contenido en TODO
		// single (`is_single()` incluye productos). En fichas el tema lo oculta
		// con `display:none`, pero display:none no evita la descarga: el <img>
		// 1200x1800 (~130 KB) baja igual y con fetchpriority=high, compitiendo
		// con el LCP real (la galería WooCommerce, que es la portada visible).
		// Se suprime la SALIDA en PHP, solo en fichas, antes de que
		// `generate_before_content` dispare.
		add_action( 'template_redirect', array( $this, 'remove_duplicate_product_featured_image' ), 20 );

		// Los clones también heredan GA4 y Google Ads. Estos filtros oficiales
		// mantienen la configuración productiva intacta, pero impiden que un
		// entorno local/QA envíe page views, conversiones o remarketing.
		add_filter( 'option_rank_math_google_analytic_options', array( $this, 'filter_rank_math_analytics_options' ) );
		add_filter( 'option_woocommerce_google_analytics_settings', array( $this, 'filter_woocommerce_analytics_options' ) );
		add_filter( 'woocommerce_gla_disable_gtag_tracking', array( $this, 'disable_google_ads_tracking' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_clarity_commerce_events' ), 30 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_joinchat_trust_clearance_assets' ), 30 );

		/*
		 * WooCommerce 10.5+ enables its dependency detector only while
		 * WP_DEBUG is active. In Chromium, repeated `window.wc = window.wc || {}`
		 * assignments create enough nested detector proxies to hide the real
		 * WooCommerce caller beyond V8's default ten stack frames.
		 */
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			add_action( 'wp_head', array( $this, 'increase_dependency_detection_stack_depth' ), 0 );
		}

		add_filter( 'litespeed_optimize_js_excludes', array( $this, 'add_litespeed_checkout_script_exclusions' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( $this, 'add_litespeed_checkout_script_exclusions' ) );
		add_filter( 'litespeed_optm_gm_js_exc', array( $this, 'add_litespeed_checkout_script_exclusions' ) );

		add_filter( 'cloudinary_lazy_load_bypass_classes', array( $this, 'add_cloudinary_lazy_bypass_classes' ) );
	}

	/**
	 * Quita el featured image duplicado de GeneratePress en fichas de producto.
	 *
	 * `template_redirect` prio 20: GeneratePress ya registró su callback en
	 * `generate_before_content` (lo hace al cargar el tema) y la query
	 * principal ya sabe si esto es una ficha. En cualquier otra plantilla el
	 * bloque del tema queda exactamente como estaba.
	 *
	 * @since 1.105.0
	 */
	public function remove_duplicate_product_featured_image(): void {
		if ( function_exists( 'is_product' ) && is_product() ) {
			remove_action( 'generate_before_content', 'generate_featured_page_header_inside_single', 10 );
		}
	}

	/**
	 * Lets WooCommerce's debug-only dependency detector see the real caller.
	 *
	 * WooCommerce's proxy is installed at wp_head priority 1. This small
	 * development-only guard runs immediately before it and preserves any
	 * higher stack limit already selected by the browser or another tool.
	 * Production never receives the script because the hook is registered only
	 * when WP_DEBUG is true.
	 */
	public function increase_dependency_detection_stack_depth(): void {
		wp_print_inline_script_tag(
			'if(typeof Error.stackTraceLimit==="number"&&Error.stackTraceLimit<50){Error.stackTraceLimit=50;}',
			array( 'id' => 'akibara-wc-dependency-detection-stack' )
		);
	}

	/**
	 * Enqueues commerce lifecycle and coarse AI-attribution events for Clarity.
	 *
	 * The official Clarity plugin owns the tracker. Akibara only supplies
	 * WooCommerce semantics that the tracker cannot infer reliably from generic
	 * clicks. No order, customer, product, URL or query data is sent: the payload
	 * is limited to stable lifecycle names plus closed source/evidence enums.
	 *
	 * @return void
	 */
	public function enqueue_clarity_commerce_events(): void {
		if (
			is_admin()
			|| ! $this->is_production_frontend()
			|| '' === trim( (string) get_option( 'clarity_project_id', '' ) )
		) {
			return;
		}

		$config = array( 'initialEvent' => '' );
		$user   = wp_get_current_user();
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$config['internalTraffic'] = 'staff';
		} elseif ( $this->is_clarity_qa_email( (string) $user->user_email ) ) {
			$config['internalTraffic'] = 'qa';
		} elseif ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			$config = array_merge( $config, $this->clarity_order_events() );
		} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$config['initialEvent'] = 'begin_checkout';
		} elseif ( function_exists( 'is_product' ) && is_product() ) {
			$config['initialEvent'] = 'view_product';
		}
		wp_enqueue_script(
			'akibara-clarity-commerce-events',
			AKIBARA_BUSINESS_URL . 'assets/js/clarity-commerce-events.js',
			$this->clarity_commerce_dependencies(),
			PluginAssetVersion::for_path( 'assets/js/clarity-commerce-events.js' ),
			true
		);

		wp_add_inline_script(
			'akibara-clarity-commerce-events',
			'window.akibaraClarityCommerce=' . wp_json_encode(
				$config,
				JSON_UNESCAPED_SLASHES
			) . ';',
			'before'
		);
	}

	/**
	 * Builds receipt events from WooCommerce state, never from the URL alone.
	 *
	 * `purchase` requires a currently paid WooCommerce/Akibara status and a
	 * recorded payment date. It is not gateway settlement/revenue reporting.
	 * The browser token is only for local deduplication; it is never sent to
	 * Clarity and cannot authorize access to the order.
	 *
	 * @return array<string,mixed>
	 */
	private function clarity_order_events(): array {
		$order_id = absint( get_query_var( 'order-received', 0 ) );
		// Receipt keys authorize this read, exactly as on WooCommerce's thank-you page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order_id || '' === $order_key || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return array();
		}

		// A key alone must not convert another registered customer's receipt.
		if ( $order->get_customer_id() && get_current_user_id() !== $order->get_customer_id() ) {
			return array();
		}

		if ( $this->is_clarity_qa_order( $order ) ) {
			return array( 'internalTraffic' => 'qa' );
		}

		$events = array( 'order_created' );
		if ( $this->is_clarity_paid_order( $order ) ) {
			$events[] = 'purchase';
		}

		return array(
			'orderEvents' => $events,
			'orderToken'  => hash_hmac( 'sha256', (string) $order_id . ':' . $order_key, wp_salt( 'auth' ) ),
		);
	}

	/**
	 * Reuses Akibara's paid lifecycle without altering global WooCommerce state.
	 * If Rewards is unavailable, WooCommerce remains the source of paid states.
	 *
	 * @param \WC_Order $order Validated receipt order.
	 * @return bool
	 */
	private function is_clarity_paid_order( \WC_Order $order ): bool {
		$is_paid = $order->is_paid() || (
			class_exists( OrderStates::class ) && in_array( $order->get_status(), OrderStates::PAID, true )
		);
		return $is_paid && (bool) $order->get_date_paid();
	}

	/**
	 * Matches only the two exact QA identities explicitly supplied by the owner.
	 *
	 * Hashes keep addresses out of the asset/config; spelling variants are not
	 * inferred. The comparison stays on the server, never in Clarity payloads.
	 *
	 * @param string $email Account or order email.
	 * @return bool
	 */
	private function is_clarity_qa_email( string $email ): bool {
		return in_array(
			hash( 'sha256', strtolower( trim( $email ) ) ),
			array(
				'2079aaa0b6f655dbd7e57a7da6a4e23b17119dc3ae33bf5a92ae9d7df6ecae17',
				'63c7ddca2f735b09afb87bde2e6d5157e00d05c336ffb3e221e17aae4c1a707e',
			),
			true
		);
	}

	/**
	 * Recognizes explicit QA evidence without guessing from paid status or role.
	 *
	 * @param \WC_Order $order Validated receipt order.
	 * @return bool
	 */
	private function is_clarity_qa_order( \WC_Order $order ): bool {
		if (
			$this->is_clarity_qa_email( $order->get_billing_email() )
			|| preg_match( '/(?:^|[_-])qa(?:$|[_-])/i', $order->get_created_via() )
		) {
			return true;
		}

		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			if ( ! preg_match( '/(?:^|[_-])qa(?:$|[_-])/i', (string) $data['key'] ) ) {
				continue;
			}
			$value = $data['value'];
			if ( is_string( $value ) ) {
				$value = strtolower( trim( $value ) );
			}
			if ( ! empty( $value ) && ! in_array( $value, array( 'false', 'no', 'off' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Declara wp-data como dependencia sólo en las páginas con bloques de
	 * carrito y checkout. Los eventos de cupón y error de pago se leen de los
	 * stores Blocks; el handle garantiza orden de ejecución.
	 * No añade ese runtime a portada, ficha, gracias o pagar pedido sin bloque.
	 * Usa el handle estable de WordPress en vez de un nombre privado del store
	 * WooCommerce, cuya ausencia descartaría el guion completo.
	 *
	 * @return string[]
	 */
	private function clarity_commerce_dependencies(): array {
		$is_cart     = function_exists( 'is_cart' ) && is_cart();
		$is_checkout = function_exists( 'is_checkout' ) && is_checkout()
			&& ! ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() );

		return $is_cart || $is_checkout ? array( 'wp-data' ) : array();
	}

	/**
	 * Prevents Joinchat's fixed mobile button from covering the trust grid.
	 *
	 * The official Joinchat settings expose a static bottom offset only. That
	 * cannot avoid the fourth trust card at the initial viewport without moving
	 * the button over the hero CTA at the next scroll position. The small
	 * compatibility guard measures the real button/card intersection and hides
	 * the floating control only while both rectangles collide.
	 *
	 * @return void
	 */
	public function enqueue_joinchat_trust_clearance_assets(): void {
		if ( is_admin() || ! NavigationLinks::is_home_surface() ) {
			return;
		}

		wp_enqueue_style(
			'akibara-joinchat-trust-clearance',
			AKIBARA_BUSINESS_URL . 'assets/css/joinchat-trust-clearance.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/joinchat-trust-clearance.css' )
		);

		wp_enqueue_script(
			'akibara-joinchat-trust-clearance',
			AKIBARA_BUSINESS_URL . 'assets/js/joinchat-trust-clearance.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/joinchat-trust-clearance.js' ),
			true
		);
	}

	/**
	 * Clarity de producción solo puede ejecutarse en el host público canónico.
	 *
	 * `WP_ENVIRONMENT_TYPE` no basta: algunos clones de hosting quedan marcados
	 * como production. El host explícito mantiene Docker y qa.akibara.cl fuera
	 * del proyecto aunque compartan la misma base de datos/opciones.
	 */
	private function is_production_frontend(): bool {
		$host = isset( $_SERVER['HTTP_HOST'] )
			? strtolower( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) )
			: '';
		$host = (string) preg_replace( '/:\d+$/', '', $host );

		return 'production' === wp_get_environment_type()
			&& in_array( $host, array( 'akibara.cl', 'www.akibara.cl' ), true );
	}

	/**
	 * Prevent Rank Math from installing the production GA4 tag outside prod.
	 *
	 * @param mixed $options Stored Rank Math Google Analytics options.
	 * @return mixed
	 */
	public function filter_rank_math_analytics_options( $options ) {
		if ( $this->is_production_frontend() || ! is_array( $options ) ) {
			return $options;
		}

		$options['install_code'] = false;
		return $options;
	}

	/**
	 * Prevent WooCommerce's Google Analytics integration from loading in clones.
	 *
	 * @param mixed $options Stored WooCommerce Google Analytics options.
	 * @return mixed
	 */
	public function filter_woocommerce_analytics_options( $options ) {
		if ( $this->is_production_frontend() || ! is_array( $options ) ) {
			return $options;
		}

		$options['ga_id'] = '';
		return $options;
	}

	/**
	 * Disable Google for WooCommerce conversion tracking outside production.
	 */
	public function disable_google_ads_tracking( bool $disabled ): bool {
		return $disabled || ! $this->is_production_frontend();
	}

	/**
	 * Hace que Cloudinary lazy-load respete la convención `skip-lazy` (core
	 * ticket 44427), igual que LiteSpeed Cache. Sin esto, imágenes marcadas
	 * como LCP-critical (hero, primeras covers del loop, logo) quedan detrás
	 * de un placeholder hasta que corre el JS de Cloudinary.
	 *
	 * @param mixed $classes Clases bypass existentes (default: cld-bypass-lazy).
	 * @return string[]
	 */
	public function add_cloudinary_lazy_bypass_classes( $classes ): array {
		$list   = is_array( $classes ) ? $classes : array();
		$list[] = 'skip-lazy';

		return array_values( array_unique( $list ) );
	}

	/**
	 * Adds checkout-script fragments to LiteSpeed exclusion lists.
	 *
	 * @param mixed $exclusions Existing exclusion list from LiteSpeed.
	 * @return string[]
	 */
	public function add_litespeed_checkout_script_exclusions( $exclusions ): array {
		$list = is_array( $exclusions )
			? $exclusions
			: ( preg_split( '/[\r\n]+/', (string) $exclusions ) ?: array() );

		return array_values(
			array_unique(
				array_filter(
					array_map(
						'trim',
						array_merge( $list, self::LITESPEED_CHECKOUT_SCRIPT_EXCLUSIONS )
					)
				)
			)
		);
	}
}
