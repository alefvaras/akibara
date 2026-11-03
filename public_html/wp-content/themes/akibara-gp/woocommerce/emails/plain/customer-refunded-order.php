<?php
/**
 * Customer refunded order — versión en TEXTO PLANO con la voz de Akibara.
 *
 * Versión en texto plano con redacción oficial de Akibara.
 * En texto plano se prescinde de `esc_html()` y `esc_url()` para evitar
 * la codificación de comillas o apóstrofes en entidades HTML numéricas.
 *
 * Vars de WC_Email_Customer_Refunded_Order: $order, $email_heading,
 * $partial_refund, $sent_to_admin, $plain_text, $email, $additional_content.
 *
 * @package Akibara
 * @version 10.9.0
 */

defined( 'ABSPATH' ) || exit;

$akb_first  = $order->get_billing_first_name();
$akb_number = '#' . $order->get_order_number();

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo wp_kses( wp_strip_all_tags( $email_heading ), array() );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo $akb_first ? wp_kses( sprintf( 'Hola %s,', $akb_first ), array() ) . "\n\n" : "Hola,\n\n";

if ( ! empty( $partial_refund ) ) {
	/* translators: %s: número de pedido */
	echo wp_kses( sprintf( __( 'Listo: procesamos un reembolso parcial de tu pedido %s. Aquí tienes el detalle:', 'akibara-gp' ), $akb_number ), array() ) . "\n\n";
} else {
	/* translators: %s: número de pedido */
	echo wp_kses( sprintf( __( 'Listo: procesamos el reembolso de tu pedido %s. Aquí tienes el detalle de lo que reembolsamos:', 'akibara-gp' ), $akb_number ), array() ) . "\n\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acciones públicas de WooCommerce: son su contrato con el resto del sitio.
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

if ( $additional_content ) {
	echo "\n" . wp_kses( wp_strip_all_tags( wptexturize( $additional_content ) ), array() ) . "\n\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo wp_kses( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ), array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro público de WooCommerce: es el pie que configura el dueño.
