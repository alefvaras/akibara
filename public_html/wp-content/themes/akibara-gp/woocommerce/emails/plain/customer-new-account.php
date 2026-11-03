<?php
/**
 * Customer new account — versión en TEXTO PLANO con la voz de Akibara.
 *
 * Versión en texto plano alineada con la redacción y tono oficial de Akibara,
 * manteniendo paridad estricta entre las variantes HTML y texto multipart.
 *
 * En texto plano se prescinde de `esc_html()` y `esc_url()` para evitar la
 * codificación de comillas o apóstrofes en entidades HTML numéricas (`&#039;`),
 * utilizando en su lugar `wp_kses( ..., array() )` y `esc_url_raw()`.
 *
 * Vars de WC_Email_Customer_New_Account: $email_heading, $blogname, $user_login,
 * $user_email, $password_generated, $set_password_url, $additional_content.
 *
 * @package Akibara
 * @version 10.9.0
 */

defined( 'ABSPATH' ) || exit;

$akb_user  = get_user_by( 'login', $user_login );
$akb_first = $akb_user ? ( $akb_user->first_name ? $akb_user->first_name : $akb_user->display_name ) : '';

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo wp_kses( wp_strip_all_tags( $email_heading ), array() );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo $akb_first ? wp_kses( sprintf( '¡Hola %s!', $akb_first ), array() ) . "\n\n" : "¡Hola!\n\n";
echo wp_kses( __( 'Gracias por sumarte a Akibara, tu distrito del manga y cómics. Ya tienes tu cuenta lista para seguir tus pedidos, preventas y restocks.', 'akibara-gp' ), array() ) . "\n\n";
/* translators: %s: usuario */
echo wp_kses( sprintf( __( 'Tu usuario: %s', 'akibara-gp' ), $user_login ), array() ) . "\n\n";

if ( $password_generated && $set_password_url ) {
	echo wp_kses( __( 'Crea tu contraseña aquí:', 'akibara-gp' ), array() ) . "\n";
	echo esc_url_raw( $set_password_url ) . "\n\n";
} else {
	echo wp_kses( __( 'Entra a tu cuenta aquí:', 'akibara-gp' ), array() ) . "\n";
	echo esc_url_raw( wc_get_page_permalink( 'myaccount' ) ) . "\n\n";
}

echo wp_kses( __( 'Desde Mi cuenta puedes ver tus pedidos, seguir tus preventas y cambiar tu contraseña cuando quieras.', 'akibara-gp' ), array() ) . "\n\n";

if ( $additional_content ) {
	echo wp_kses( wp_strip_all_tags( wptexturize( $additional_content ) ), array() ) . "\n\n";
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo wp_kses( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ), array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro público de WooCommerce: es el pie que configura el dueño.
