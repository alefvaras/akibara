<?php
/**
 * Customer reset password — Akibara branded override.
 *
 * Override de WC core (templates/emails/customer-reset-password.php). Saluda por
 * NOMBRE (no username), voz Akibara cercana y tranquilizadora, botón de marca, y
 * un closing de marca en vez del genérico de WC.
 *
 * Vars de WC_Email_Customer_Reset_Password: $email_heading, $blogname, $user_login,
 * $user_id, $reset_key, $additional_content, $email.
 *
 * @package Akibara
 * @version 10.9.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- código literal de la plantilla original de WooCommerce (templates/emails/): la variable es suya. Prefijarla desviaría el override del upstream sin ninguna ganancia.
$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción pública de WooCommerce que esta plantilla sobrescribe: el nombre es el contrato con el que se enganchan WooCommerce y terceros, no un hook propio.
do_action( 'woocommerce_email_header', $email_heading, $email );

$akb_user  = get_user_by( 'login', $user_login );
$akb_first = $akb_user ? ( $akb_user->first_name ? $akb_user->first_name : '' ) : '';
$akb_reset_link = add_query_arg(
	array(
		'key'   => $reset_key,
		'id'    => $user_id,
		'login' => rawurlencode( $user_login ),
	),
	wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
);
?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<p><?php echo $akb_first ? esc_html( sprintf( 'Hola %s,', $akb_first ) ) : 'Hola,'; ?></p>

<p><?php esc_html_e( 'Recibimos una solicitud para restablecer la contraseña de tu cuenta en Akibara.', 'akibara-gp' ); ?></p>

<p><?php esc_html_e( 'Si fuiste tú, crea una nueva contraseña con el botón de abajo. Si no, puedes ignorar este correo tranquilo: tu cuenta sigue segura.', 'akibara-gp' ); ?></p>

<p style="margin:20px 0;">
	<a class="link akb-email-btn" href="<?php echo esc_url( $akb_reset_link ); ?>" style="display:inline-block;padding:15px 28px;background-color:#E60012;background-image:linear-gradient(#E60012,#E60012);color:#ffffff;-webkit-text-fill-color:#ffffff;text-decoration:none;border-radius:0;font-weight:600;line-height:1;text-transform:uppercase;letter-spacing:0.5px;">
		<?php esc_html_e( 'Restablecer mi contraseña', 'akibara-gp' ); ?>
	</a>
</p>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}
?>

<p style="margin-top:24px;color:#6b6459;font-size:0.95em;"><?php esc_html_e( 'Nos vemos en el distrito. — Equipo Akibara', 'akibara-gp' ); ?></p>

<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción pública de WooCommerce que esta plantilla sobrescribe: el nombre es el contrato con el que se enganchan WooCommerce y terceros, no un hook propio.
do_action( 'woocommerce_email_footer', $email );
