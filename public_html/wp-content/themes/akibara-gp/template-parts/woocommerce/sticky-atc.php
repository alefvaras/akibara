<?php
/**
 * Barra de compra fija de la ficha.
 *
 * Precio + CTA fijos al fondo: en ≤1024 px apilados ENCIMA del bottom-nav; en
 * escritorio, pegados al borde inferior y con el nombre del tomo delante (en
 * 1366x768 el botón real queda bajo el pliegue, UC-4). Cuándo aparece lo decide
 * sticky-atc.js: con el form.cart fuera de la vista, el título ya por encima de
 * la barra y sin un pack listo para enviar en pantalla.
 *
 * El botón es un PROXY: clickea el `.single_add_to_cart_button` real del
 * form. Así respeta cantidad elegida, validaciones WC y cualquier upgrade
 * futuro a AJAX add-to-cart sin duplicar lógica. El texto del CTA viene de
 * `single_add_to_cart_text()` (mismos filtros del plugin: "Reservar en
 * Preventa", etc.) — cero business logic en el theme.
 *
 * La llegada de un tomo a pedido o en preventa («Llega 7 – 13 nov», UM-7) va
 * bajo el precio. La fecha la calcula y la redacta akibara-business
 * (EstimatedDelivery, filtro `akb_eta_linea_corta`; el texto se edita en
 * Akibara → Textos): el tema sólo la pinta. Sin el plugin, o en un tomo en
 * stock, el filtro devuelve vacío y la línea no sale.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

global $product;

if ( ! $product instanceof WC_Product ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- `$product` es el global que WooCommerce publica y consume en toda la ficha; prefijarlo lo dejaría fuera del contrato de WC.
	$product = wc_get_product( get_the_ID() );
}

// Sin producto comprable no hay form.cart → no hay nada que proxear.
// onbackorder (preventa) pasa el is_in_stock() check; outofstock no.
if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
	return;
}

/**
 * La llegada en una línea, redactada por akibara-business (EstimatedDelivery).
 *
 * @param string     $linea   Vacío: sin plugin no hay fecha.
 * @param WC_Product $product Producto de la ficha.
 */
$akb_llegada = trim( (string) apply_filters( 'akb_eta_linea_corta', '', $product ) );

?>
<div class="akb-sticky-atc" id="akb-sticky-atc">
	<div class="akb-sticky-atc__progress" id="akb-sticky-atc-progress" aria-hidden="true" style="width: 0%"></div>
	<button type="button" class="akb-sticky-atc__reset" id="akb-sticky-atc-reset" aria-label="Quitar selección del pack" hidden>×</button>
	<?php // El nombre sólo se ve en escritorio (woocommerce.css) y es el mismo del H1: para el lector de pantalla sería un duplicado. ?>
	<span class="akb-sticky-atc__title" aria-hidden="true"><?php echo esc_html( $product->get_name() ); ?></span>
	<span class="akb-sticky-atc__info">
		<span class="akb-sticky-atc__price-row">
			<span class="akb-sticky-atc__price"><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_price_html() devuelve el marcado de precio que arma WooCommerce ya escapado (wc_price()); escaparlo otra vez imprimiría las etiquetas al cliente. ?></span>
			<span class="akb-sticky-atc__badge" id="akb-sticky-atc-badge" hidden></span>
		</span>
		<?php if ( '' !== $akb_llegada ) : ?>
			<span class="akb-sticky-atc__eta"><?php echo esc_html( $akb_llegada ); ?></span>
		<?php endif; ?>
		<span class="akb-sticky-atc__subtext" id="akb-sticky-atc-subtext" hidden></span>
	</span>
	<?php // Un solo botón de compra, también en preventa: se distingue por el texto (DS-2). ?>
	<button type="button" class="akb-sticky-atc__btn" id="akb-sticky-atc-btn">
		<?php echo esc_html( $product->single_add_to_cart_text() ); ?>
	</button>
</div>
