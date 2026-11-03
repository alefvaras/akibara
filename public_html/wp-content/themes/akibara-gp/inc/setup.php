<?php
/**
 * Theme setup — supports + menu locations.
 *
 * Single source of truth para feature declarations del theme. Sin lógica
 * de negocio, sin queries: solo declaraciones que WP/WC leen al cargar.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Declara theme supports estándar WP + WooCommerce.
 *
 * Por qué cada uno:
 *   - title-tag             → SEO: WP genera <title> dinámico (no hardcoded en header).
 *   - post-thumbnails       → habilita featured image (WC lo requiere).
 *   - automatic-feed-links  → RSS auto-discovery en <head>.
 *   - html5                 → output semántico moderno (form, gallery, script tags).
 *   - custom-logo           → habilita Customizer → Site Identity → Logo.
 *   - woocommerce           → declara compat WC (oculta warning admin).
 *   - wc-product-gallery-*  → activa zoom/lightbox/slider en single product.
 *
 * Menu locations:
 *   - akibara-primary         → menú principal (desktop mainnav + mobile drawer).
 *   - akibara-footer-catalog  → columna "Catálogo" del footer.
 *   - akibara-footer-explore  → columna "Explorar" del footer.
 *   - akibara-footer-help     → columna "Ayuda" del footer.
 *   - akibara-footer-mobile   → atajos del footer en mobile (Catálogo/Carrito/Contacto).
 *
 * Owner edita todos en wp-admin → Apariencia → Menús, asignando cada menú
 * creado a la location correspondiente. Si una location no tiene menú asignado
 * el theme oculta esa columna sin romper el layout.
 */
function akibara_gp_setup(): void {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );

	add_theme_support( 'custom-logo', array(
		'height'      => 80,
		'width'       => 201,
		'flex-height' => true,
		'flex-width'  => true,
	) );

	add_theme_support( 'woocommerce' );
	// Zoom (lupa al hover) DESACTIVADO a propósito: las portadas del catálogo son
	// ~550-610px y el área de display de la PDP es mayor → el zoom de WooCommerce,
	// que necesita una fuente MÁS grande que lo mostrado, dibujaba una copia más
	// chica encima de la imagen (efecto "duplicado" al pasar el cursor). Con covers
	// chicos no aporta nada útil. El lightbox (click) sigue mostrando la imagen full.
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	register_nav_menus( array(
		'akibara-primary'        => 'Akibara Principal',
		'akibara-footer-catalog' => 'Akibara Footer — Catálogo',
		'akibara-footer-explore' => 'Akibara Footer — Explorar',
		'akibara-footer-help'    => 'Akibara Footer — Ayuda',
		'akibara-footer-mobile'  => 'Akibara Footer — Atajos mobile',
	) );

	// Skip-link único (a11y): el theme ya emite su propio skip-link a #main (el
	// landmark <main>) en header.php. El de GeneratePress (generate_do_skip_to_content_link)
	// apunta a #content —que esta estructura no renderiza, ancla muerta— y duplica el landmark.
	// Se quita acá (after_setup_theme, cuando GP ya registró su add_action prio 2).
	remove_action( 'generate_before_header', 'generate_do_skip_to_content_link', 2 );
}
add_action( 'after_setup_theme', 'akibara_gp_setup' );

// GeneratePress (padre) reactiva `wc-product-gallery-zoom` en inc/plugin-compat.php
// (`after_setup_theme` prio 10), así que comentarlo en akibara_gp_setup() no alcanza.
// Lo removemos a prio 99 (después del padre y del child). Motivo: con portadas
// chicas (~550-610px) y un área de display mayor, el zoom de WooCommerce dibujaba
// una copia más chica encima al pasar el cursor (efecto "duplicado"). El lightbox
// (click) sigue activo para ver la portada completa.
add_action(
	'after_setup_theme',
	static function (): void {
		remove_theme_support( 'wc-product-gallery-zoom' );
	},
	99
);

/*
 * El sidebar layout NO se decide aquí. Vivía partido entre este archivo y
 * `inc/woocommerce.php`: dos callbacks del propio tema sobre
 * `generate_sidebar_layout` en la misma prioridad, encadenados, de modo que la
 * respuesta real era la composición de los dos y ninguno la contaba entera.
 * El dueño único es ahora `akibara_gp_archive_sidebar_layout()`
 * (inc/woocommerce.php), que reproduce esa composición —incluida la
 * preferencia por página `_generate-sidebar-layout-meta`— en un solo sitio.
 */
