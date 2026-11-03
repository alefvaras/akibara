<?php
/**
 * Akibara Trust Badges
 * Adds trust signals below the add to cart button and at checkout.
 *
 * @package akibara-gp
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML for the trust badges.
 * Uses the same SVG icons and concepts as the homepage.
 *
 * El badge de embalaje enlaza a `/embalaje-seguro/` a propósito. Esa página
 * Explica las garantías de despacho (cartón corrugado, burbuja, funda protectora)
 * y resuelve dudas de coleccionistas directamente junto al botón de compra.
 */
function akibara_get_trust_badges_html() {
	ob_start();
	?>
		<div class="akb-product-trust-badges" role="list" aria-label="Beneficios de compra">
			<div class="akb-badge-trust" role="listitem">
				<svg width="16" height="16" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9"/><path d="M1 3h22"/><path d="M5 9l2-4h10l2 4"/></svg>
				<span>Envíos a todo Chile</span>
			</div>
			<div class="akb-badge-trust akb-badge-trust--linked" role="listitem">
				<a class="akb-badge-trust__link" href="<?php echo esc_url( home_url( '/embalaje-seguro/' ) ); ?>">
					<svg width="16" height="16" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
					<span>Embalaje reforzado</span>
				</a>
			</div>
			<div class="akb-badge-trust" role="listitem">
				<svg width="16" height="16" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
				<span><?php echo esc_html( akibara_gp_promise_installments() ); ?></span>
			</div>
			<div class="akb-badge-trust" role="listitem">
				<?php echo akibara_gp_icon( 'whatsapp', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icono allowlisted. ?>
				<span>Atención por WhatsApp</span>
			</div>
		</div>
		<?php
		return ob_get_clean();
}

/**
 * Imprime los sellos una sola vez por ficha.
 *
 * En productos con existencias se ubican bajo el formulario de compra.
 * En productos agotados (donde WooCommerce suprime el formulario) se renderizan
 * al cierre del resumen para preservar las garantías y el enlace a embalaje seguro.
 * Una variable estática previene renderizados duplicados.
 */
function akibara_gp_render_trust_badges(): void {
	static $impresos = false;
	if ( $impresos ) {
		return;
	}
	$impresos = true;
	echo akibara_get_trust_badges_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML propio ya construido.
}

// Bajo el botón de compra en los productos disponibles.
add_action( 'woocommerce_after_add_to_cart_form', 'akibara_gp_render_trust_badges', 15 );
// …y al cierre del resumen cuando no hubo formulario (agotados y no comprables).
add_action( 'woocommerce_single_product_summary', 'akibara_gp_render_trust_badges', 65 );

// En el checkout de bloques, las señales de confianza se gestionan
// mediante el adaptador first-party (`Services\CheckoutTrustSignals`).
