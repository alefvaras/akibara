<?php
/**
 * Customer new account — Akibara branded override.
 *
 * Override de WC core (templates/emails/customer-new-account.php). Cambia la voz
 * robótica de WC ("Hi {user_login}", "Here's a copy of your user details") por la
 * voz Akibara: saluda por NOMBRE (no username), tono cercano chileno, y un BOTÓN
 * de marca en vez de un link de texto pelado.
 *
 * Vars de WC_Email_Customer_New_Account: $email_heading, $blogname, $user_login,
 * $user_email, $password_generated, $set_password_url, $additional_content, $email.
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
$akb_first = $akb_user ? ( $akb_user->first_name ? $akb_user->first_name : $akb_user->display_name ) : '';
$akb_account_url = wc_get_page_permalink( 'myaccount' );
?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<p><?php echo $akb_first ? esc_html( sprintf( '¡Hola %s!', $akb_first ) ) : '¡Hola!'; ?></p>

<p><?php esc_html_e( 'Gracias por sumarte a Akibara, tu rincón de Akihabara en Chile. Ya tienes tu cuenta lista para seguir tus pedidos, preventas y restocks.', 'akibara-gp' ); ?></p>

<p style="margin:0 0 4px;"><?php echo wp_kses( sprintf( __( 'Tu usuario: <strong>%s</strong>', 'akibara-gp' ), esc_html( $user_login ) ), array( 'strong' => array() ) ); ?></p>

<?php if ( $password_generated && $set_password_url ) : ?>
	<p style="margin:20px 0;">
		<a class="link akb-email-btn" href="<?php echo esc_url( $set_password_url ); ?>" style="display:inline-block;padding:15px 28px;background-color:#E60012;background-image:linear-gradient(#E60012,#E60012);color:#ffffff;-webkit-text-fill-color:#ffffff;text-decoration:none;border-radius:0;font-weight:600;line-height:1;text-transform:uppercase;letter-spacing:0.5px;">
			<?php esc_html_e( 'Crear mi contraseña', 'akibara-gp' ); ?>
		</a>
	</p>
<?php else : ?>
	<p style="margin:20px 0;">
		<a class="link akb-email-btn" href="<?php echo esc_url( $akb_account_url ); ?>" style="display:inline-block;padding:15px 28px;background-color:#E60012;background-image:linear-gradient(#E60012,#E60012);color:#ffffff;-webkit-text-fill-color:#ffffff;text-decoration:none;border-radius:0;font-weight:600;line-height:1;text-transform:uppercase;letter-spacing:0.5px;">
			<?php esc_html_e( 'Ir a mi cuenta', 'akibara-gp' ); ?>
		</a>
	</p>
<?php endif; ?>

<p style="font-size:0.92em;color:#6b6459;"><?php esc_html_e( 'Desde Mi cuenta puedes ver tus pedidos, seguir tus preventas y cambiar tu contraseña cuando quieras.', 'akibara-gp' ); ?></p>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción pública de WooCommerce que esta plantilla sobrescribe: el nombre es el contrato con el que se enganchan WooCommerce y terceros, no un hook propio.
do_action( 'woocommerce_email_footer', $email );
