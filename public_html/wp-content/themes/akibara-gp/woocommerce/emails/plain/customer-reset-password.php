<?php
/**
 * Customer reset password — versión en TEXTO PLANO con la voz de Akibara.
 *
 * Versión en texto plano con redacción oficial de Akibara.
 * En texto plano se prescinde de `esc_html()` y `esc_url()` para evitar
 * la codificación de comillas o apóstrofes en entidades HTML numéricas.
 *
 * Vars de WC_Email_Customer_Reset_Password: $email_heading, $user_login,
 * $reset_key, $user_id, $blogname, $additional_content.
 *
 * @package Akibara
 * @version 10.9.0
 */

defined( 'ABSPATH' ) || exit;

$akb_user  = get_user_by( 'login', $user_login );
$akb_first = $akb_user && $akb_user->first_name ? $akb_user->first_name : '';
$akb_reset = add_query_arg(
	array(
		'key'   => $reset_key,
		'id'    => $user_id,
		'login' => rawurlencode( $user_login ),
	),
	wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
);

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo wp_kses( wp_strip_all_tags( $email_heading ), array() );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo $akb_first ? wp_kses( sprintf( 'Hola %s,', $akb_first ), array() ) . "\n\n" : "Hola,\n\n";
echo wp_kses( __( 'Recibimos una solicitud para restablecer la contraseña de tu cuenta en Akibara.', 'akibara-gp' ), array() ) . "\n\n";
echo wp_kses( __( 'Si fuiste tú, crea una nueva contraseña con este enlace. Si no, puedes ignorar este correo tranquilo: tu cuenta sigue segura.', 'akibara-gp' ), array() ) . "\n\n";
echo esc_url_raw( $akb_reset ) . "\n\n";

if ( $additional_content ) {
	echo wp_kses( wp_strip_all_tags( wptexturize( $additional_content ) ), array() ) . "\n\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo wp_kses( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ), array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro público de WooCommerce: es el pie que configura el dueño.
