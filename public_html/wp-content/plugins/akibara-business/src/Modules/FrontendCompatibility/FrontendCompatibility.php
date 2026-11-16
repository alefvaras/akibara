<?php
/**
 * Frontend compatibility fixes for third-party scripts and cache optimizers.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\FrontendCompatibility;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress HookNotFound
 */
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
		// GeneratePress imprime el featured image sobre el contenido en TODO
		// single (`is_single()` incluye productos). En fichas el tema lo oculta
		// con `display:none`, pero display:none no evita la descarga: el <img>
		// 1200x1800 (~130 KB) baja igual y con fetchpriority=high, compitiendo
		// con el LCP real (la galería WooCommerce, que es la portada visible).
		// Se suprime la SALIDA en PHP, solo en fichas, antes de que
		// `generate_before_content` dispare.
		add_action( 'template_redirect', array( $this, 'remove_duplicate_product_featured_image' ), 20 );

		// Los clones también heredan GA4. Estos filtros oficiales
		// mantienen la configuración productiva intacta, pero impiden que un
		// entorno local/QA envíe page views.
		add_filter( 'option_rank_math_google_analytic_options', array( $this, 'filter_rank_math_analytics_options' ) );
		add_filter( 'option_woocommerce_google_analytics_settings', array( $this, 'filter_woocommerce_analytics_options' ) );

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
