<?php
/**
 * Title & Brand Renderer: Muestra la editorial de la taxonomía product_brand.
 * 
 * Reemplaza los hooks de WooCommerce para inyectar la marca (editorial)
 * como un eyebrow por encima del título principal, utilizando metadatos nativos
 * en lugar de regex, siguiendo los estándares de WooCommerce.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// ─── SHOP LOOP TITLE ────────────────────────────────────────────────────────

remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );
add_action( 'woocommerce_shop_loop_item_title', 'akb_template_loop_product_title', 10 );

/**
 * Jerarquía semántica accesible para el título de la tarjeta (WCAG 1.3.1):
 * Retorna `h3` cuando la tarjeta se ubica dentro de secciones con encabezado `h2`
 * (portada, productos relacionados en ficha, venta cruzada en carrito vacío/404),
 * y `h2` en archivos de catálogo donde la rejilla cuelga directamente del `h1`.
 *
 * Los estilos CSS apuntan a la clase `.woocommerce-loop-product__title` sin
 * acoplarse al tag HTML específico.
 */
function akb_loop_title_level(): string {
	// Secciones con h2 intermedio: portada, relacionados, cross-sell y página 1 de series.
	$bajo_seccion_h2 = ( function_exists( 'is_front_page' ) && is_front_page() )
		|| ( function_exists( 'is_product' ) && is_product() )
		|| ( function_exists( 'is_cart' ) && is_cart() )
		|| ( function_exists( 'is_404' ) && is_404() )
		|| ( function_exists( 'is_tax' ) && is_tax( 'pa_serie' ) && ! ( function_exists( 'is_paged' ) && is_paged() ) );

	return $bajo_seccion_h2 ? 'h3' : 'h2';
}

function akb_template_loop_product_title(): void {
	global $product;
	$nivel = akb_loop_title_level();
	if ( ! $product ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapa cada parte.
		echo '<' . $nivel . ' class="woocommerce-loop-product__title">' . akb_loop_title_markup( (string) get_the_title() ) . '</' . $nivel . '>';
		return;
	}

	// `get_the_terms()` y NO `wp_get_post_terms()`: el segundo va a la base en cada
	// tarjeta porque no consulta el term-cache, y la rejilla lo llama una vez por
	// producto — 24 consultas por página de catálogo para leer un nombre que
	// `update_object_term_cache()` ya cebó con la consulta principal del loop.
	$brands = get_the_terms( $product->get_id(), 'product_brand' );

	if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) {
		$brand = reset( $brands );
		echo '<span class="akb-editorial-eyebrow">' . esc_html( $brand->name ) . '</span>';
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapa cada parte.
	echo '<' . $nivel . ' class="woocommerce-loop-product__title">' . akb_loop_title_markup( (string) get_the_title() ) . '</' . $nivel . '>';
}

/**
 * Identidad de coleccionista: separa el nombre de serie del número de tomo final
 * para darle al número peso de "lomo" (Bebas amarillo, como en BookWalker /
 * Distrito Manga). El <h3> conserva el título completo como texto (SEO + lectores
 * de pantalla). Si el título no termina en número, se emite tal cual. Cada parte
 * va escapada.
 *
 * El nombre se envuelve en `.akb-title-name` para aplicar el clamp de líneas
 * mediante CSS flex, manteniendo el número de tomo fuera del clamp para evitar
 * que quede truncado por puntos suspensivos.
 *
 * @param string $title Título del producto.
 * @return string HTML escapado.
 */
/**
 * Compone las dos piezas de la tarjeta, con el nombre ya limpio.
 *
 * Al sacar el número del nombre puede quedar colgando el marcador ordinal que
 * lo introducía («One Piece nº» + 01). Se retira acá y no en la expresión
 * regular para que las dos formas del título compartan la limpieza. Medido
 * contra el catálogo: hoy sólo afecta a los 13 omnibus «One Piece nº NN
 * (3 en 1)»; ningún otro título termina en marcador + número.
 *
 * @param string $name   Nombre visible, ya sin el número.
 * @param string $vol    Número de tomo tal como lo escribe el título.
 * @param string $suffix Sufijo entre paréntesis que iba detrás del número, si lo había.
 * @return string HTML escapado.
 */
function akb_loop_title_parts( string $name, string $vol, string $suffix = '' ): string {
	// El marcador se retira ANTES de volver a pegar el sufijo: en «One Piece nº
	// 01 (3 en 1)» lo que queda colgando es el «nº», no el paréntesis.
	$name = (string) preg_replace( '/\s*(?:,?\s*[Vv]ol\.?|[Nn]\.?[º°]|#)\s*$/u', '', $name );
	if ( '' !== $suffix ) {
		$name .= ' ' . $suffix;
	}
	$name = trim( (string) preg_replace( '/\s{2,}/u', ' ', $name ) );

	return '<span class="akb-title-name">' . esc_html( $name ) . '</span> <span class="akb-vol">' . esc_html( $vol ) . '</span>';
}

function akb_loop_title_markup( string $title ): string {
	// Variantes con sufijo entre paréntesis («Demon Slayer 23 (Edición Especial)» o «One Piece #01 (3 en 1)»).
	if ( preg_match( '/^(.*\S)\s*(?:[Nn]\.?[º°]|#)\s*(\d+)\s*(\([^()]*\))\s*$/u', $title, $m ) ) {
		return akb_loop_title_parts( $m[1], $m[2], $m[3] );
	}
	if ( preg_match( '/^(.*\S)\s*(?:[Nn]\.?[º°]|#)\s*(\d+)\s*$/u', $title, $m ) ) {
		return akb_loop_title_parts( $m[1], $m[2] );
	}
	if ( preg_match( '/^(.*\S)\s+(\d+)\s*(\([^()]*\))\s*$/u', $title, $m ) ) {
		return akb_loop_title_parts( $m[1], $m[2], $m[3] );
	}
	if ( preg_match( '/^(.*\S)\s+(\d+)\s*$/u', $title, $m ) ) {
		return akb_loop_title_parts( $m[1], $m[2] );
	}
	return '<span class="akb-title-name">' . esc_html( $title ) . '</span>';
}

// ─── SINGLE PRODUCT TITLE ───────────────────────────────────────────────────

remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
add_action( 'woocommerce_single_product_summary', 'akb_template_single_title', 5 );

function akb_template_single_title(): void {
	global $product;
	$raw_title = (string) get_the_title();
	if ( ! $product ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapa cada parte.
		echo '<h1 class="product_title entry-title">' . akb_loop_title_markup( $raw_title ) . '</h1>';
		return;
	}

	// Mismo criterio que en la tarjeta: el term-cache ya tiene la editorial.
	$brands = get_the_terms( $product->get_id(), 'product_brand' );

	if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) {
		$brand = reset( $brands );
		echo '<span class="akb-editorial-eyebrow akb-editorial-eyebrow--single">' . esc_html( $brand->name ) . '</span>';
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapa cada parte.
	echo '<h1 class="product_title entry-title">' . akb_loop_title_markup( $raw_title ) . '</h1>';
}

// ─── CART & ORDER ITEM TITLES ───────────────────────────────────────────────

/**
 * Aplica el marcado de tomo (.akb-vol) a los nombres de ítems en carrito, checkout y detalle de pedidos.
 *
 * @param string $item_name Nombre HTML o plano del ítem.
 * @return string HTML con .akb-title-name y .akb-vol.
 */
function akb_format_item_title_with_volume( string $item_name ): string {
	if ( preg_match( '/^(<a\b[^>]*>)(.*)(<\/a>)$/isu', $item_name, $m ) ) {
		$raw_title = html_entity_decode( wp_strip_all_tags( $m[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return $m[1] . akb_loop_title_markup( $raw_title ) . $m[3];
	}
	$raw_title = html_entity_decode( wp_strip_all_tags( $item_name ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return akb_loop_title_markup( $raw_title );
}

add_filter(
	'woocommerce_cart_item_name',
	static function ( string $item_name ): string {
		return akb_format_item_title_with_volume( $item_name );
	},
	20
);

add_filter(
	'woocommerce_order_item_name',
	static function ( string $item_name ): string {
		return akb_format_item_title_with_volume( $item_name );
	},
	20
);

