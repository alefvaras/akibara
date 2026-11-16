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
 * `single_add_to_cart_text()` — cero business logic en el theme.
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
if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
	return;
}

?>
<div class="akb-sticky-atc" id="akb-sticky-atc">
	<?php // El nombre sólo se ve en escritorio (woocommerce.css) y es el mismo del H1: para el lector de pantalla sería un duplicado. ?>
	<span class="akb-sticky-atc__title" aria-hidden="true"><?php echo esc_html( $product->get_name() ); ?></span>
	<span class="akb-sticky-atc__info">
		<span class="akb-sticky-atc__price-row">
			<span class="akb-sticky-atc__price"><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_price_html() devuelve el marcado de precio que arma WooCommerce ya escapado (wc_price()); escaparlo otra vez imprimiría las etiquetas al cliente. ?></span>
		</span>
	</span>
	<button type="button" class="akb-sticky-atc__btn" id="akb-sticky-atc-btn">
		<?php echo esc_html( $product->single_add_to_cart_text() ); ?>
	</button>
</div>
