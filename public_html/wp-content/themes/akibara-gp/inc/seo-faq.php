<?php
/**
 * FAQ de la ficha de producto (solo UI).
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Render del acordeón de FAQ en la PDP (solo UI).
 *
 * Los DATOS de las FAQs viven en akibara-business (módulo ProductFaq) y el theme
 * solo renderiza el acordeón consumiendo `akibara/product_faq_data`. La inyección
 * personalizada de FAQPage fue retirada; el contenido visible se conserva por
 * su valor para usuarios y citabilidad. Sin el plugin, el filtro devuelve [] y
 * no se renderiza nada (degradación elegante).
 */

add_action( 'woocommerce_after_single_product', 'akibara_render_product_faqs', 5 );

function akibara_render_product_faqs(): void {
	global $product;
	$source = ( $product instanceof WC_Product ) ? $product : null;

	/** @var array<int, array{q:string,a:string}> $faqs */
	$faqs = (array) apply_filters( 'akibara/product_faq_data', array(), $source );
	if ( empty( $faqs ) ) {
		return;
	}

	echo '<div class="product-faqs">';
	echo '<div class="section-header"><h2 class="section-header__title">Preguntas Frecuentes</h2></div>';
	foreach ( $faqs as $faq ) {
		echo '<details class="product-faq">';
		echo '<summary class="product-faq__q">' . esc_html( $faq['q'] ) . '</summary>';
		echo '<div class="product-faq__a"><p>' . wp_kses_post( $faq['a'] ) . '</p></div>';
		echo '</details>';
	}
	echo '</div>';
}
