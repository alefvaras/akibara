<?php
/**
 * Series Bundles — Admin\AdminAssets.
 *
 * Encola los assets de la experiencia de edición del módulo SOLO en la pestaña
 * "Bundles de Series" del panel Akibara (`toplevel_page_akibara-panel`,
 * `tab=series_bundles`), siguiendo el patrón de carga condicional de
 * `HomeHero\AdminUI::enqueue_admin_assets`.
 *
 * Carga:
 *   - `wc-enhanced-select` (+ estilos) → Select2/selectWoo para el multiselect
 *                                        de "Categorías excluidas" (AdminSection).
 *   - `series-bundles-admin.(js|css)`  → interactividad de "arma tu pack por
 *                                        serie" (BypConfigController): tramos
 *                                        dinámicos, filtro de series y validación.
 *
 * Sin funciones i18n (Req 11.4): textos en español literal.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles\Admin;

use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

final class AdminAssets {

	/** Hook del panel Akibara (igual que `HomeHero\AdminUI`). */
	private const PANEL_HOOK = 'toplevel_page_akibara-panel';

	/** Slug de la pestaña del módulo en el panel. */
	private const PANEL_TAB = 'series_bundles';

	/** Handle compartido de los assets de admin del módulo. */
	private const ASSET_HANDLE = 'akb-series-bundles-admin';

	/**
	 * Conecta el encolado condicional de assets de admin.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Encola los assets solo en la pestaña "Bundles de Series" del panel Akibara.
	 *
	 * @param string $hook Hook suffix de la pantalla actual.
	 */
	public function enqueue( string $hook ): void {
		if ( self::PANEL_HOOK !== $hook ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura del tab activo para gating del enqueue.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : '';
		if ( self::PANEL_TAB !== $current_tab ) {
			return;
		}

		// Select2/selectWoo de WooCommerce: inicializa el multiselect de
		// "Categorías excluidas" (clase `wc-enhanced-select`) de AdminSection.
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );

		wp_enqueue_style(
			self::ASSET_HANDLE,
			AKIBARA_BUSINESS_URL . 'assets/css/series-bundles-admin.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/series-bundles-admin.css' )
		);

		wp_enqueue_script(
			self::ASSET_HANDLE,
			AKIBARA_BUSINESS_URL . 'assets/js/series-bundles-admin.js',
			array( 'jquery' ),
			PluginAssetVersion::for_path( 'assets/js/series-bundles-admin.js' ),
			true
		);
	}
}
