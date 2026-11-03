<?php
/**
 * Login + Register Form — override theme akibara-gp.
 *
 * Layout unificado: una sola caja con el botón "Continuar con Google"
 * compartido + dos disclosure (`<details>`) para login / registro con
 * correo y contraseña. La elección queda explícita y la opción primaria
 * (Google) tiene jerarquía visual.
 *
 * Diseño:
 *   - `<details name="akb-auth">` agrupa exclusivamente: abrir uno
 *     cierra el otro nativo (HTML spec, browsers actuales soportan).
 *   - Sin JS funciona igual: expand/collapse nativos del browser.
 *   - Si hubo POST con error, se abre el disclosure correspondiente
 *     mediante `open` para que el cliente vea sus errores.
 *
 * Override basado en wp-content/plugins/woocommerce/templates/myaccount/form-login.php
 * @version 9.9.0
 *
 * @package akibara-gp/woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de plantilla de WooCommerce: con prefijo propio no lo escucharía nadie.
do_action( 'woocommerce_before_customer_login_form' );

$akb_register_enabled  = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- sólo se mira SI hubo envío, para reabrir el formulario que falló; el nonce lo verifica WooCommerce al procesarlo.
$akb_login_open_attr    = ! empty( $_POST['login'] ) ? ' open' : '';
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ídem.
$akb_register_open_attr = ! empty( $_POST['register'] ) ? ' open' : '';
$akb_has_google         = shortcode_exists( 'google_login' );

/*
 * Qué dice el muro y a dónde devuelve. Todo el criterio vive en AccessWall
 * —endpoint, copys editables desde Akibara → Textos, saneado del `redirect_to`—
 * para que esta plantilla sólo pinte.
 */
$akb_wall_endpoint = \Akibara\Business\Modules\GoogleLoginButton\AccessWall::current_endpoint();
$akb_wall_copy     = \Akibara\Business\Modules\GoogleLoginButton\AccessWall::copy( $akb_wall_endpoint, $akb_register_enabled );
$akb_wall_back     = \Akibara\Business\Modules\GoogleLoginButton\AccessWall::return_notice();
$akb_wall_redirect = \Akibara\Business\Modules\GoogleLoginButton\AccessWall::redirect_field_value( $akb_wall_endpoint );
?>

<div class="akb-account-auth" id="customer_login">

	<header class="akb-account-auth__header">
		<h2 class="akb-account-auth__title"><?php echo esc_html( $akb_wall_copy['title'] ); ?></h2>
		<p class="akb-account-auth__sub"><?php echo esc_html( $akb_wall_copy['sub'] ); ?></p>
		<?php if ( '' !== $akb_wall_back ) : ?>
			<p class="akb-account-auth__context"><?php
				// El único trozo con HTML: AccessWall lo devuelve ya escapado, con
				// el nombre del tomo dentro de su enlace de vuelta.
				echo $akb_wall_back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AccessWall::return_notice() devuelve el enlace ya armado con esc_url() y esc_html(), y el copy del dueño pasado por esc_html(); escaparlo aquí imprimiría el enlace como texto.
			?></p>
		<?php endif; ?>
		<ul class="akb-account-auth__perks" aria-label="Beneficios de tu cuenta Akibara">
			<li><strong>Mi colección:</strong> lleva tu estantería al día y recibe avisos de nuevos tomos.</li>
			<?php
			// Lo que promete esta línea lo dicta el plugin Rewards (Ajustes del
			// programa): si el dueño apaga compras o reseñas, la frase lo sigue.
			$akb_rw       = '\\Akibara\\Rewards\\Support\\Options';
			$akb_rw_buy   = class_exists( $akb_rw ) && (int) $akb_rw::earn_points() > 0;
			$akb_rw_rev   = class_exists( $akb_rw ) && $akb_rw::reviews_lane_on();
			$akb_rw_parts = array_filter( array( $akb_rw_buy ? 'tus compras' : '', $akb_rw_rev ? 'reseñas por invitación' : '' ) );
			if ( $akb_rw_parts ) :
				?>
			<li><strong>Puntos Akibara:</strong> acumula puntos en <?php echo esc_html( implode( ' y ', $akb_rw_parts ) ); ?>.</li>
			<?php endif; ?>
			<li><strong>Arma tu pack:</strong> descuenta de la selección los tomos que ya tienes.</li>
		</ul>
	</header>

	<?php if ( $akb_has_google ) : ?>
		<div class="akb-google-login-wrap">
			<?php echo do_shortcode( '[google_login button_text="Continuar con Google"]' ); ?>
		</div>

		<div class="akb-account-divider" aria-hidden="true"><span><?php echo esc_html( 'o' ); ?></span></div>
	<?php endif; ?>

	<details class="akb-account-disclosure" name="akb-auth"<?php echo esc_attr( $akb_login_open_attr ); ?>>
		<summary class="akb-account-disclosure__summary">
			<span class="akb-account-disclosure__label"><?php echo esc_html( 'Iniciar sesión con correo y contraseña' ); ?></span>
			<span class="akb-account-disclosure__icon" aria-hidden="true"></span>
		</summary>

		<form class="woocommerce-form woocommerce-form-login login" method="post" novalidate>
			<?php do_action( 'woocommerce_login_form_start' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de plantilla de WooCommerce: con prefijo propio no lo escucharía nadie. ?>

			<?php if ( '' !== $akb_wall_redirect ) : ?>
				<input type="hidden" name="redirect" value="<?php echo esc_url( $akb_wall_redirect ); ?>" />
			<?php endif; ?>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="username"><?php echo esc_html( 'Nombre de usuario o correo electrónico' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="username" autocomplete="username" value="<?php
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- se repinta lo que la persona escribió para que no lo teclee otra vez; el nonce lo verifica WooCommerce al procesar.
					echo ( ! empty( $_POST['username'] ) && is_string( $_POST['username'] ) ) ? esc_attr( sanitize_user( wp_unslash( $_POST['username'] ), true ) ) : '';
				?>" required aria-required="true" />
			</p>
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="password"><?php echo esc_html( 'Contraseña' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="password" id="password" autocomplete="current-password" required aria-required="true" />
			</p>

			<?php do_action( 'woocommerce_login_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de plantilla de WooCommerce: con prefijo propio no lo escucharía nadie. ?>

			<p class="form-row">
				<label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form-login__rememberme">
					<input class="woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever" /> <span><?php echo esc_html( 'Recuérdame' ); ?></span>
				</label>
				<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
				<button type="submit" class="woocommerce-button button woocommerce-form-login__submit<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="login" value="<?php echo esc_attr( 'Iniciar sesión' ); ?>"><?php echo esc_html( 'Iniciar sesión' ); ?></button>
			</p>
			<p class="woocommerce-LostPassword lost_password">
				<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php echo esc_html( '¿Olvidaste tu contraseña?' ); ?></a>
			</p>

			<?php do_action( 'woocommerce_login_form_end' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de plantilla de WooCommerce: con prefijo propio no lo escucharía nadie. ?>
		</form>
	</details>

	<?php if ( $akb_register_enabled ) : ?>
		<details class="akb-account-disclosure" name="akb-auth"<?php echo esc_attr( $akb_register_open_attr ); ?>>
			<summary class="akb-account-disclosure__summary">
				<span class="akb-account-disclosure__label"><?php echo esc_html( 'Crear cuenta con tu correo' ); ?></span>
				<span class="akb-account-disclosure__icon" aria-hidden="true"></span>
			</summary>

			<form method="post" class="woocommerce-form woocommerce-form-register register" <?php do_action( 'woocommerce_register_form_tag' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de plantilla de WooCommerce: con prefijo propio no lo escucharía nadie. ?> >
				<?php do_action( 'woocommerce_register_form_start' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de plantilla de WooCommerce: con prefijo propio no lo escucharía nadie. ?>

				<?php if ( '' !== $akb_wall_redirect ) : ?>
					<input type="hidden" name="redirect" value="<?php echo esc_url( $akb_wall_redirect ); ?>" />
				<?php endif; ?>

				<?php if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) : ?>
					<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
						<label for="reg_username"><?php echo esc_html( 'Nombre de usuario' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
						<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="reg_username" autocomplete="username" value="<?php
							// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ídem: se repinta lo escrito.
							echo ( ! empty( $_POST['username'] ) && is_string( $_POST['username'] ) ) ? esc_attr( sanitize_user( wp_unslash( $_POST['username'] ), true ) ) : '';
						?>" required aria-required="true" />
					</p>
				<?php endif; ?>

				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="reg_email"><?php echo esc_html( 'Dirección de correo electrónico' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
					<input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email" autocomplete="email" value="<?php
						// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ídem: se repinta lo escrito.
						echo ( ! empty( $_POST['email'] ) && is_string( $_POST['email'] ) ) ? esc_attr( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
					?>" required aria-required="true" />
				</p>

				<?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>
					<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
						<label for="reg_password"><?php echo esc_html( 'Contraseña' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
						<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password" id="reg_password" autocomplete="new-password" required aria-required="true" />
					</p>
				<?php else : ?>
					<p><?php echo esc_html( 'Te enviaremos un enlace para crear tu contraseña.' ); ?></p>
				<?php endif; ?>

				<?php do_action( 'woocommerce_register_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de plantilla de WooCommerce: con prefijo propio no lo escucharía nadie. ?>

				<p class="woocommerce-form-row form-row">
					<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
					<button type="submit" class="woocommerce-Button woocommerce-button button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?> woocommerce-form-register__submit" name="register" value="<?php echo esc_attr( 'Crear cuenta' ); ?>"><?php echo esc_html( 'Crear cuenta' ); ?></button>
				</p>

				<?php do_action( 'woocommerce_register_form_end' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de plantilla de WooCommerce: con prefijo propio no lo escucharía nadie. ?>
			</form>
		</details>
	<?php endif; ?>

</div>

<?php do_action( 'woocommerce_after_customer_login_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de plantilla de WooCommerce: con prefijo propio no lo escucharía nadie. ?>
