<?php
/**
 * Page header behavior — controla el `entry-header` (H1 + meta) que GP
 * renderea por default arriba del contenido en pages/posts.
 *
 * Hoy:
 *   - Home (`is_front_page`): GP emite `<h1 class="entry-title">Inicio</h1>`,
 *     pero la home tiene su propio hero `.akb-hero` desde Akibara Business.
 *   - Pages con `.akb-page__hero` en el contenido (las declaradas en
 *     `AKIBARA_GP_CONTENT_PAGES`): el hero provee el H1 visible con styling
 *     propio.
 *   - Carrito y checkout: el H1 se renderiza dentro del contenido de cada
 *     página (en carrito vía shortcode y en checkout mediante el bloque
 *     `<header class="akb-page-title"><h1>Finalizar compra</h1></header>`).
 *
 *   En todos esos casos el entry-title por defecto de GeneratePress generaría
 *   un H1 duplicado en el DOM, por lo que se suprime vía `generate_show_title`.
 *
 * Mantenimiento: las páginas con `.akb-page__hero` se declaran en
 * `AKIBARA_GP_CONTENT_PAGES`; este filtro agrega los overrides de carrito y checkout.
 * En particular, el slug `'checkout'` cubre también los endpoints `order-received`
 * y `order-pay`, asegurando un único H1 en confirmaciones de compra.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Suprime el entry-header de GP (que incluye el H1 `entry-title`) en pages
 * que tienen su propio H1 vía `.akb-page__hero` o vía hero de home.
 *
 * @param bool $show Si GP debe renderear el entry-header.
 */
function akibara_gp_hide_entry_title( bool $show ): bool {
	if ( is_front_page() ) {
		return false;
	}

	$pages_with_own_h1_slugs = array_merge(
		AKIBARA_GP_CONTENT_PAGES,
		array(
			// Páginas de bloques cuyo H1 va en su propio contenido (ver arriba).
			'carrito',
			'checkout',
		)
	);

	if ( is_page( $pages_with_own_h1_slugs ) ) {
		return false;
	}

	return $show;
}
add_filter( 'generate_show_title', 'akibara_gp_hide_entry_title' );
