<?php
/**
 * Customer refunded order — Akibara branded override.
 *
 * Override de WC core (templates/emails/customer-refunded-order.php). Cambia la voz
 * pasiva ("Se ha reembolsado tu pedido") por activa y en primera persona
 * ("Listo, {nombre}: procesamos el reembolso…"). Conserva la tabla de pedido WC.
 *
 * @package Akibara
 * @version 10.4.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción pública de WooCommerce que esta plantilla sobrescribe: el nombre es el contrato con el que se enganchan WooCommerce y terceros, no un hook propio.
do_action( 'woocommerce_email_header', $email_heading, $email );

$akb_first  = $order->get_billing_first_name();
$akb_number = '#' . $order->get_order_number();
?>

<p><?php echo $akb_first ? esc_html( sprintf( 'Hola %s,', $akb_first ) ) : 'Hola,'; ?></p>

<p>
<?php
if ( ! empty( $partial_refund ) ) {
	printf(
		/* translators: %1$s saludo opcional, %2$s order number */
		esc_html__( 'Listo: procesamos un reembolso parcial de tu pedido %s. Aquí tienes el detalle:', 'akibara-gp' ),
		'<strong>' . esc_html( $akb_number ) . '</strong>'
	);
} else {
	printf(
		/* translators: %s order number */
		esc_html__( 'Listo: procesamos el reembolso de tu pedido %s. Aquí tienes el detalle de lo que reembolsamos:', 'akibara-gp' ),
		'<strong>' . esc_html( $akb_number ) . '</strong>'
	);
}
?>
</p>

<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción pública de WooCommerce que esta plantilla sobrescribe: el nombre es el contrato con el que se enganchan WooCommerce y terceros, no un hook propio.
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción pública de WooCommerce que esta plantilla sobrescribe: el nombre es el contrato con el que se enganchan WooCommerce y terceros, no un hook propio.
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción pública de WooCommerce que esta plantilla sobrescribe: el nombre es el contrato con el que se enganchan WooCommerce y terceros, no un hook propio.
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción pública de WooCommerce que esta plantilla sobrescribe: el nombre es el contrato con el que se enganchan WooCommerce y terceros, no un hook propio.
do_action( 'woocommerce_email_footer', $email );
