<?php
/**
 * /mi-cuenta/edit-account/ — override del default WC.
 *
 * Cambios respecto a default:
 *   - Sin field "Display name" (account_display_name). En un e-commerce
 *     de manga el "nombre visible para reviews" es ruido — sacarlo
 *     simplifica el form. WC valida server-side el required → el filter
 *     `woocommerce_save_account_details_required_fields` en
 *     MyAccountUx::required_fields lo desregistra.
 *   - "Datos personales" y "Cambio de contraseña" como secciones visualmente
 *     separadas con headers (cards independientes). Default WC usa un solo
 *     form con fieldset apenas distinguido.
 *
 * Preservados:
 *   - todos los do_action() del template default (before/after/start/end/form_tag)
 *   - nonces y hidden inputs nativos WC
 *   - autocompletes (given-name, family-name, email, current-password, new-password)
 *
 * @package Akibara\Business
 * @version 10.5.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
do_action( 'woocommerce_before_edit_account_form' );
?>

<form class="woocommerce-EditAccountForm edit-account akb-edit-account" action="" method="post" <?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( 'woocommerce_edit_account_form_tag' ); ?>>

	<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( 'woocommerce_edit_account_form_start' ); ?>

	<section class="akb-edit-account__section">
		<h3 class="akb-edit-account__section-title">Datos personales</h3>

		<div class="akb-edit-account__row akb-edit-account__row--split">
			<p class="woocommerce-form-row form-row form-row-first">
				<label for="account_first_name">Nombre <span class="required" aria-hidden="true">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_first_name" id="account_first_name" autocomplete="given-name" value="<?php echo esc_attr( $user->first_name ); ?>" aria-required="true" />
			</p>
			<p class="woocommerce-form-row form-row form-row-last">
				<label for="account_last_name">Apellido <span class="required" aria-hidden="true">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_last_name" id="account_last_name" autocomplete="family-name" value="<?php echo esc_attr( $user->last_name ); ?>" aria-required="true" />
			</p>
		</div>

		<p class="woocommerce-form-row form-row form-row-wide">
			<label for="account_email">Correo electrónico <span class="required" aria-hidden="true">*</span></label>
			<input type="email" class="woocommerce-Input woocommerce-Input--email input-text" name="account_email" id="account_email" autocomplete="email" value="<?php echo esc_attr( $user->user_email ); ?>" aria-required="true" />
		</p>

		<?php
		/**
		 * Slot WC 8.7+ — campos adicionales (e.g. teléfono via
		 * akibara-business PhoneValidation si en el futuro se expone acá).
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo.
		do_action( 'woocommerce_edit_account_form_fields' );
		?>
	</section>

	<section class="akb-edit-account__section">
		<h3 class="akb-edit-account__section-title">Seguridad</h3>
		<p class="akb-edit-account__section-hint">Deja los campos en blanco si no quieres cambiar la contraseña.</p>

		<p class="woocommerce-form-row form-row form-row-wide">
			<label for="password_current">Contraseña actual</label>
			<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_current" id="password_current" autocomplete="current-password" />
		</p>
		<p class="woocommerce-form-row form-row form-row-wide">
			<label for="password_1">Nueva contraseña</label>
			<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_1" id="password_1" autocomplete="new-password" aria-describedby="akb-pwd-strength" />
			<span class="akb-pwd-strength" id="akb-pwd-strength" aria-live="polite"></span>
		</p>
		<p class="woocommerce-form-row form-row form-row-wide">
			<label for="password_2">Confirmar nueva contraseña</label>
			<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_2" id="password_2" autocomplete="new-password" />
		</p>
	</section>

	<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( 'woocommerce_edit_account_form' ); ?>

	<p class="akb-edit-account__submit-wrap">
		<?php wp_nonce_field( 'save_account_details', 'save-account-details-nonce' ); ?>
		<button type="submit" class="akb-edit-account__submit" name="save_account_details" value="Guardar cambios">
			Guardar cambios
		</button>
		<input type="hidden" name="action" value="save_account_details" />
	</p>

	<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( 'woocommerce_edit_account_form_end' ); ?>
</form>

<?php /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de WooCommerce core: el override debe emitirlo con su nombre original o los plugins que lo escuchan dejan de recibirlo. */ do_action( 'woocommerce_after_edit_account_form' ); ?>

<script>
// Password strength meter — bind al input #password_1 usando wp.passwordStrength
// (zxcvbn wrapped) que MyAccountUx::enqueue_password_strength enqueuea.
// Sin enforcement (no bloquea submit débil) — solo feedback visual.
(function(){
	'use strict';
	function init(){
		var input   = document.getElementById('password_1');
		var confirm = document.getElementById('password_2');
		var out     = document.getElementById('akb-pwd-strength');
		if ( ! input || ! confirm || ! out || typeof wp === 'undefined' || ! wp.passwordStrength ) {
			return;
		}
		// Map de scores WP a labels + classes. wp.passwordStrength.meter() retorna:
		// -1 mismatch, 0 empty, 1 short, 2 bad, 3 good, 4 strong, 5 mismatch w/blacklist.
		var labels = {
			0: '',
			1: 'Muy débil',
			2: 'Débil',
			3: 'Media',
			4: 'Fuerte',
			5: 'No coincide'
		};
		var classes = {
			0: '',
			1: 'akb-pwd-strength--weak',
			2: 'akb-pwd-strength--weak',
			3: 'akb-pwd-strength--medium',
			4: 'akb-pwd-strength--strong',
			5: 'akb-pwd-strength--weak'
		};
		// S-f70eab1b (2026-09-19): el tercer argumento de meter() es la contraseña
		// de CONFIRMACIÓN, no la misma contraseña otra vez — compararla consigo
		// misma hacía imposible el score 5 («No coincide»). Se recalcula al
		// tipear en CUALQUIERA de los dos campos: si sólo se escuchara #password_1,
		// escribir la confirmación primero y la contraseña después dejaría el
		// mismatch sin refrescar.
		function actualizar(){
			var pwd = input.value;
			out.className = 'akb-pwd-strength';
			if ( pwd.length === 0 ) {
				out.textContent = '';
				return;
			}
			var score = wp.passwordStrength.meter( pwd, wp.passwordStrength.userInputDisallowedList(), confirm.value );
			var cls = classes[ score ];
			if ( cls ) {
				out.classList.add( cls );
			}
			out.textContent = labels[ score ] || '';
		}
		input.addEventListener('input', actualizar);
		confirm.addEventListener('input', actualizar);
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
</script>
