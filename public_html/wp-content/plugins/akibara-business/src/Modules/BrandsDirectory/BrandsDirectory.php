<?php
/**
 * Directorio de editoriales basado en la taxonomía nativa product_brand.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use Akibara\Business\Utilities\ThemeAssetVersion;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress HookNotFound
 */
final class BrandsDirectory {

	private RegionMeta $region_meta;
	private BrandFeatured $brand_featured;
	private Shortcode $shortcode;
	private MenuInjector $menu_injector;
	private HomeCarousel $home_carousel;
	private MenuItemFields $menu_item_fields;

	public function __construct() {
		$this->region_meta      = new RegionMeta();
		$this->brand_featured   = new BrandFeatured();
		$this->shortcode        = new Shortcode();
		$this->menu_injector    = new MenuInjector();
		$this->home_carousel    = new HomeCarousel();
		$this->menu_item_fields = new MenuItemFields();
	}

	public function register(): void {
		$this->region_meta->register();
		$this->brand_featured->register();
		$this->shortcode->register();
		$this->menu_injector->register();
		$this->home_carousel->register();
		$this->menu_item_fields->register();

		// Conditional CSS enqueue (solo cuando se renderiza el directorio).
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'akibara_brands_directory_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Encola brands.css y el filter JS del theme solo si la página actual
	 * contiene el shortcode. Evita pagar el costo en otras páginas.
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! is_string( $post->post_content ) ) {
			return;
		}

		if ( ! has_shortcode( $post->post_content, Shortcode::TAG ) ) {
			return;
		}

		$this->enqueue_assets();
	}

	/**
	 * Encola los assets del directorio.
	 */
	public function enqueue_assets(): void {
		$theme_dir = get_stylesheet_directory();
		$theme_uri = get_stylesheet_directory_uri();

		$css_path = 'assets/css/brands.css';
		if ( file_exists( $theme_dir . '/' . $css_path ) ) {
			$css_served = ThemeAssetVersion::served_path( $css_path );
			wp_enqueue_style(
				'akibara-brands',
				$theme_uri . '/' . $css_served,
				array(),
				ThemeAssetVersion::for_path( $css_served )
			);
		}

		$js_path = 'assets/js/akibara-brands-filter.js';
		if ( file_exists( $theme_dir . '/' . $js_path ) && $this->brands_count() >= 6 ) {
			$js_served = ThemeAssetVersion::served_path( $js_path );
			wp_enqueue_script(
				'akibara-brands-filter',
				$theme_uri . '/' . $js_served,
				array(),
				ThemeAssetVersion::for_path( $js_served ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
	}

	private function brands_count(): int {
		static $count = null;
		if ( null !== $count ) {
			return $count;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => Shortcode::TAXONOMY,
				'hide_empty' => true,
				'fields'     => 'ids',
			)
		);
		$count = is_array( $terms ) ? count( $terms ) : 0;
		return $count;
	}
}
