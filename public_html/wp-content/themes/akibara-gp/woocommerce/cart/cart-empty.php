<?php
/**
 * Cart Empty — Akibara branded override.
 *
 * Override del template default `woocommerce/templates/cart/cart-empty.php`.
 * Reemplaza el banner amarillo + 1 CTA por:
 *   - Subheading claro "TU CARRITO ESTÁ VACÍO" en Bebas Neue
 *   - Copy editable desde Akibara > Carrito
 *   - CTA "EXPLORAR CATÁLOGO" red primary
 *   - Trust signals (envío, cuotas, atención local)
 *   - Cross-sell con 4 productos más vendidos
 *
 * Por qué NO confiar en woo-fly-cart suggested:
 *   El plugin renderiza `<div class="woofc-suggested">` dentro del HTML del
 *   cart, pero `hide_pages: ["6"]` apaga el wrapper del fly-cart en /carrito/,
 *   dejando ese suggested invisible (CSS scope al .woofc-popup). Setting de
 *   suggested_empty en woofc_settings solo afecta el drawer en otras pages.
 *
 * @package akibara-gp
 * @see WooCommerce/templates/cart/cart-empty.php (template original)
 * @version 7.0.1
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Services\NavigationLinks;
use Akibara\Business\Modules\NativeCart\Settings as NativeCartSettings;

$akb_shop_url   = class_exists( NavigationLinks::class ) ? NavigationLinks::shop_url() : home_url( '/tienda/' );
$akb_empty_copy = class_exists( NativeCartSettings::class )
	? ( new NativeCartSettings() )->get_empty_copy()
	: 'Aún no agregas títulos. Explora nuestro catálogo de manga y cómics y encuentra tu próxima lectura.';

$akb_empty_message_priority = has_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message' );
if ( false !== $akb_empty_message_priority ) {
	remove_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message', $akb_empty_message_priority );
}

// Conserva el contrato público de WooCommerce sin duplicar su aviso vacío.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción pública de WooCommerce que esta plantilla sobrescribe: el nombre es el contrato con el que se enganchan terceros, no un hook propio.
do_action( 'woocommerce_cart_is_empty' );

if ( false !== $akb_empty_message_priority ) {
	add_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message', $akb_empty_message_priority );
}
?>

<section class="akb-empty akb-cart-empty" aria-labelledby="akb-cart-empty-title">
	<div class="akb-empty__hero">
		<h2 id="akb-cart-empty-title" class="akb-empty__title">
			<?php echo esc_html( 'TU CARRITO ESTÁ VACÍO' ); ?>
		</h2>

		<p class="akb-empty__subtitle">
			<?php echo esc_html( $akb_empty_copy ); ?>
		</p>

		<div class="akb-empty__cta-row">
			<a class="akb-empty__btn akb-empty__btn--primary" href="<?php echo esc_url( $akb_shop_url ); ?>">
				<?php echo 'Explorar catálogo'; ?>
			</a>
		</div>

		<div class="akb-empty__trust">
			<span class="akb-empty__trust-item"><?php echo 'Envíos a todo Chile'; ?></span>
			<span class="akb-empty__trust-item"><?php echo esc_html( akibara_gp_promise_installments() ); ?></span>
			<span class="akb-empty__trust-item"><?php echo 'Atención por WhatsApp'; ?></span>
		</div>
	</div>

	<?php
	// Cross-sell "más vendidos": parte compartida por los empty-states.
	get_template_part(
		'template-parts/empty/cross-sell',
		null,
		array(
			'heading_id'   => 'akb-cart-empty-popular-title',
			'heading_tag'  => 'h2',
			'heading_text' => 'Más vendidos',
		)
	);
	?>
</section>
