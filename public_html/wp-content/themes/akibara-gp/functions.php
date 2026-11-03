<?php
/**
 * Akibara GP child theme — Bootstrap.
 *
 * Solo encadena los módulos en /inc/. Cero lógica aquí.
 * Cada módulo tiene una responsabilidad única (single source of truth):
 *
 *   inc/setup.php                  → theme supports + nav menus.
 *   inc/enqueue.php                → CSS/JS cache-busted enqueue.
 *   inc/class-mainnav-walker.php   → walker BEM para wp_nav_menu.
 *
 * UI se renderea desde header.php / footer.php que cargan
 * template-parts/ atómicos (shell, navigation, mobile-drawer, footer/main,
 * bottom-nav).
 *
 * Dependencia hard:
 *   - **akibara-business plugin** (\Akibara\Business\Services\NavigationLinks
 *     resuelve URLs canónicas: account/cart/contact/etc.).
 *     El theme no hace lookups por slug ni knows page IDs — todas las URLs
 *     vienen del plugin. Si el plugin se desactiva, los templates fail loud
 *     (Class not found). Es intencional: el sitio no funciona sin el plugin
 *     de cualquier forma (catálogo, brands, etc.).
 *   - **GeneratePress** (parent theme).
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$akb_inc = get_stylesheet_directory() . '/inc/';

require_once $akb_inc . 'page-targets.php';
require_once $akb_inc . 'asset-dequeue.php';
require_once $akb_inc . 'setup.php';
require_once $akb_inc . 'generatepress-compat.php';
require_once $akb_inc . 'enqueue.php';
require_once $akb_inc . 'icons.php';
require_once $akb_inc . 'site-copy.php';
require_once $akb_inc . 'class-mainnav-walker.php';
require_once $akb_inc . 'class-mobile-walker.php';
require_once $akb_inc . 'class-footer-walker.php';
require_once $akb_inc . 'woocommerce.php';
require_once $akb_inc . 'page-header.php';
require_once $akb_inc . 'performance.php';
require_once $akb_inc . 'flyout-button.php';
require_once $akb_inc . 'series-order.php';
require_once $akb_inc . 'series-breadcrumb.php';
require_once $akb_inc . 'title-parser.php';
require_once $akb_inc . 'seo-faq.php';
require_once $akb_inc . 'blog.php';
require_once $akb_inc . 'guide-series.php';
require_once $akb_inc . 'rank-math.php';
require_once $akb_inc . 'trust-badges.php';
function akibara_gp_meta_viewport() {
    return '<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">';
}
add_filter( 'generate_meta_viewport', 'akibara_gp_meta_viewport' );

/**
 * GP imprime el viewport en wp_head prio 1, pero wp_preload_resources (core,
 * también prio 1) se registra antes y gana: los <link rel=preload media=...>
 * del hero quedaban ANTES del meta viewport, el preload scanner evaluaba sus
 * media queries con el layout viewport default (980px) y en mobile descargaba
 * hero-desktop.webp ADEMÁS de hero-movile.webp (~108 KB extra en el LCP).
 * Re-hook a prio 0 para que el viewport preceda a todo preload. Va en
 * after_setup_theme porque el functions.php del child carga antes que GP
 * registre su hook.
 */
function akibara_gp_viewport_before_preloads() {
    remove_action( 'wp_head', 'generate_add_viewport', 1 );
    add_action( 'wp_head', 'generate_add_viewport', 0 );
}
add_action( 'after_setup_theme', 'akibara_gp_viewport_before_preloads' );

// Enqueue Persona 5 Reviews styling ONLY for Single Products
function akibara_gp_enqueue_p5_reviews() {
	if ( function_exists('is_product') && is_product() ) {
		list( $css_uri, $version ) = akibara_gp_asset( 'assets/css/reviews-p5-v4.css' );
		wp_enqueue_style( 'akibara-reviews-p5', $css_uri, array( 'akibara-base' ), $version );
	}
}
add_action( 'wp_enqueue_scripts', 'akibara_gp_enqueue_p5_reviews', 20 );

/**
 * SEO: Optimizar LCP (Largest Contentful Paint).
 * Pre-carga la imagen principal del producto con fetchpriority="high".
 *
 * El preload lleva `imagesrcset` e `imagesizes` además del href. Sin ellos el
 * preload scanner descarga la URL fija mientras el <img>, que sí tiene srcset,
 * elige otro candidato para el mismo viewport, duplicando descargas.
 * Con los tres atributos sincronizados, el navegador selecciona idéntica variante.
 */
add_action( 'wp_head', function() {
    // Guarda de disponibilidad: previene fatal errors en llamadas globales a wp_head
    // si WooCommerce se encuentra inactivo o en mantenimiento.
    if ( ! akibara_gp_wc_activo() ) {
        return;
    }
    // Una ficha protegida con contraseña no pinta la galería —el template de
    // WooCommerce devuelve el formulario— así que precargar su portada sería
    // descargar una imagen que nadie va a ver. Antes no se notaba porque este
    // preload no llegaba a emitirse nunca.
    if ( ! is_product() || post_password_required() ) {
        return;
    }
    // `global $product` TODAVÍA está vacío en `wp_head` a prioridad 2: WooCommerce
    // lo puebla al entrar en el loop del contenido, que va mucho más abajo. Por
    // eso este preload —escrito justamente para que la portada baje primero— no
    // llegaba a emitirse en NINGUNA ficha: la función salía en este `return` y el
    // navegador descubría la imagen al parsear el `<img>`. Se pide el producto a
    // la consulta, que a esta altura sí está resuelta.
    $product = wc_get_product( get_queried_object_id() );
    if ( ! $product instanceof WC_Product ) {
        return;
    }
    $image_id = $product->get_image_id();
    if ( ! $image_id ) {
        return;
    }

    // El mismo tamaño que renderiza el template de la galería de WooCommerce:
    // si aquí se pidiera otro, el candidato base ya no coincidiría.
    $size      = 'woocommerce_single';
    $image_url = wp_get_attachment_image_url( $image_id, $size );
    if ( ! $image_url ) {
        return;
    }

    $srcset = wp_get_attachment_image_srcset( $image_id, $size );
    // Pasa por `akibara_gp_single_cover_sizes()` (inc/performance.php), el mismo
    // filtro que da su `sizes` al `<img>` de la galería: los dos declaran el
    // ancho pintado y el escáner elige el mismo candidato.
    $sizes  = wp_get_attachment_image_sizes( $image_id, $size );

    $tag = '<link rel="preload" as="image" href="' . esc_url( $image_url ) . '"';
    // Los DOS o ninguno. `imagesrcset` sin `imagesizes` hace que el scanner
    // asuma `100vw` y pueda elegir un candidato más ancho que el que el `<img>`
    // acabará usando: la doble descarga que este bloque existe para evitar. Y el
    // caso es alcanzable: si el derivado `woocommerce_single` no existe (portada
    // subida antes de registrarlo, o sin regenerar), `srcset` cae al tamaño
    // completo y `sizes` devuelve false.
    if ( is_string( $srcset ) && '' !== $srcset && is_string( $sizes ) && '' !== $sizes ) {
        $tag .= ' imagesrcset="' . esc_attr( $srcset ) . '"';
        $tag .= ' imagesizes="' . esc_attr( $sizes ) . '"';
    }
    $tag .= ' fetchpriority="high">';

    echo $tag . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cada parte se escapa arriba.
}, 2 );
