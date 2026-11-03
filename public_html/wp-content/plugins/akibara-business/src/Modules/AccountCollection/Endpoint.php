<?php
/**
 * Endpoint privado /mi-cuenta/coleccion/.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AccountCollection;

use Akibara\Business\Modules\SeriesFollow\CollectionToggle;
use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

final class Endpoint {

	public const SLUG = 'coleccion';
	public const QUERY_VAR = 'coleccion';

	private Renderer $renderer;

	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Registra únicamente hooks nativos de WordPress y WooCommerce.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		// Después de MyAccountUx (20): preserva Colección inmediatamente tras Pedidos.
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ), 30 );
		add_filter( 'woocommerce_endpoint_' . self::SLUG . '_title', array( $this, 'endpoint_title' ) );
		add_filter( 'document_title_parts', array( $this, 'document_title_parts' ), 99 );
		add_filter( 'rank_math/frontend/title', array( $this, 'rank_math_title' ), 99 );
		add_action( 'woocommerce_account_' . self::SLUG . '_endpoint', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'wp_headers', array( $this, 'private_headers' ) );
		add_action( 'template_redirect', array( $this, 'disable_page_cache' ), 0 );
		add_action( 'wp_ajax_akb_collection_mark_owned', array( $this, 'ajax_mark_owned' ) );
	}

	/**
	 * Registra la máscara de rewrite del endpoint de cuenta.
	 */
	public function add_endpoint(): void {
		add_rewrite_endpoint( self::SLUG, EP_ROOT | EP_PAGES );
	}

	/**
	 * Integra la ruta en WC()->query, requisito para endpoints de Mi cuenta.
	 *
	 * @param array<string,string> $vars Query vars de WooCommerce.
	 * @return array<string,string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[ self::QUERY_VAR ] = self::SLUG;
		return $vars;
	}

	/**
	 * Inserta Colección inmediatamente después de Pedidos, sin perder extensiones.
	 *
	 * @param array<string,string> $items Items actuales.
	 * @return array<string,string>
	 */
	public function menu_item( array $items ): array {
		unset( $items[ self::SLUG ] );

		$insert_after = isset( $items['orders'] ) ? 'orders' : ( isset( $items['dashboard'] ) ? 'dashboard' : '' );
		$reordered    = array();

		foreach ( $items as $key => $label ) {
			$reordered[ $key ] = $label;
			if ( $key === $insert_after ) {
				$reordered[ self::SLUG ] = 'Colección';
			}
		}

		if ( ! isset( $reordered[ self::SLUG ] ) ) {
			$reordered = array( self::SLUG => 'Colección' ) + $reordered;
		}

		return $reordered;
	}

	/**
	 * @param string $title Título original del endpoint.
	 */
	public function endpoint_title( string $title ): string {
		return 'Colección';
	}

	/**
	 * Mantiene el título de pestaña alineado con el H1 del endpoint.
	 *
	 * @param array<string,string> $parts Partes del título de WordPress.
	 * @return array<string,string>
	 */
	public function document_title_parts( array $parts ): array {
		if ( $this->is_collection_endpoint() ) {
			$parts['title'] = 'Mi colección';
		}

		return $parts;
	}

	/**
	 * Rank Math genera su título fuera del pipeline nativo de WordPress.
	 *
	 * @param string $title Título calculado por Rank Math.
	 */
	public function rank_math_title( string $title ): string {
		if ( ! $this->is_collection_endpoint() ) {
			return $title;
		}

		$site_name = trim( (string) get_bloginfo( 'name' ) );
		return '' !== $site_name ? 'Mi colección - ' . $site_name : 'Mi colección';
	}

	/**
	 * Carga el CSS del endpoint y el script del interruptor de avisos.
	 *
	 * El enqueue va SÓLO dentro del endpoint y nunca en `wp_head` global: el
	 * fatal de las tandas fue exactamente eso —un `PluginAssetVersion` sin `use`
	 * en un camino de front dejó 500 en todas las fichas con las suites en
	 * verde—, y esta superficie es privada, no una ficha pública.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_collection_endpoint() ) {
			return;
		}

		wp_enqueue_style(
			'akb-account-collection',
			AKIBARA_BUSINESS_URL . 'assets/css/account-collection.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/account-collection.css' )
		);

		wp_enqueue_script(
			'akb-series-follow',
			AKIBARA_BUSINESS_URL . 'assets/js/series-follow.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/series-follow.js' ),
			true
		);

		wp_localize_script(
			'akb-series-follow',
			'akbSeriesFollow',
			array(
				'root'  => esc_url_raw( rest_url( CollectionToggle::REST_NAMESPACE . CollectionToggle::REST_ROUTE ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);

		wp_enqueue_script(
			'akb-account-collection',
			AKIBARA_BUSINESS_URL . 'assets/js/account-collection.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/account-collection.js' ),
			true
		);

		wp_localize_script(
			'akb-account-collection',
			'akbAccountCollection',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'akb_collection_manual' ),
			)
		);
	}

	/**
	 * Endpoint AJAX para marcar/desmarcar tomos como tenidos manualmente en estantería.
	 */
	public function ajax_mark_owned(): void {
		if ( ! check_ajax_referer( 'akb_collection_manual', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => 'Sesión caducada. Reintentando…',
					'code'    => 'stale_nonce',
					'nonce'   => wp_create_nonce( 'akb_collection_manual' ),
				),
				403
			);
			return;
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Debes iniciar sesión.' ), 401 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( $product_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'ID de producto inválido.' ), 400 );
		}

		$mark_action = isset( $_POST['mark_action'] ) ? sanitize_key( (string) $_POST['mark_action'] ) : 'add';
		$owned       = 'add' === $mark_action;

		$success = CollectionResolver::mark_manual_owned( $user_id, $product_id, $owned );
		if ( ! $success ) {
			wp_send_json_error( array( 'message' => 'No se pudo actualizar tu estantería.' ), 500 );
		}

		wp_send_json_success(
			array(
				'product_id'  => $product_id,
				'owned'       => $owned,
				'mark_action' => $mark_action,
			)
		);
	}

	/**
	 * Impide que la colección privada entre a caches compartidas.
	 *
	 * @param array<string,string> $headers Headers de WordPress.
	 * @return array<string,string>
	 */
	public function private_headers( array $headers ): array {
		if ( ! $this->is_collection_endpoint() ) {
			return $headers;
		}

		$headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0, private';
		$headers['Pragma']        = 'no-cache';
		$headers['Expires']       = 'Wed, 11 Jan 1984 05:00:00 GMT';

		return $headers;
	}

	/**
	 * Marca la respuesta antes del template para WordPress y LiteSpeed.
	 */
	public function disable_page_cache(): void {
		if ( ! $this->is_collection_endpoint() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constante estándar de facto de los plugins de caché (LiteSpeed, W3TC, WP Super Cache): prefijarla la dejaría inerte.
		}

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		do_action( 'litespeed_control_set_nocache', 'Cuenta Akibara: colección privada' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook público oficial de LiteSpeed Cache (tercero): el nombre lo fija ese plugin.
	}

	/**
	 * Render server-side del endpoint para el cliente autenticado.
	 */
	public function render(): void {
		$user_id = (int) get_current_user_id();
		echo $this->renderer->render( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- El template escapa cada valor dinámico.
	}

	/**
	 * Resuelve el endpoint contra el permalink explícito de Mi cuenta para
	 * producir URLs absolutas también en cron y Action Scheduler.
	 */
	public static function url(): string {
		if ( function_exists( 'wc_get_endpoint_url' ) ) {
			$base = function_exists( 'wc_get_page_permalink' )
				? (string) wc_get_page_permalink( 'myaccount' )
				: '';

			return (string) wc_get_endpoint_url( self::SLUG, '', $base );
		}

		return home_url( '/mi-cuenta/' . self::SLUG . '/' );
	}

	private function is_collection_endpoint(): bool {
		return function_exists( 'is_account_page' )
			&& is_account_page()
			&& function_exists( 'is_wc_endpoint_url' )
			&& is_wc_endpoint_url( self::SLUG );
	}
}
