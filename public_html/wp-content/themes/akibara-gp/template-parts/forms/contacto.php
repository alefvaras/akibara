<?php
/**
 * Template — form de contacto.
 *
 * Renderizado por shortcode [akb_form_contacto] (akibara-business plugin).
 *
 * @package akibara-gp
 */

defined( 'ABSPATH' ) || exit;

/*
 * Contrato de integración con akibara-business: el shortcode [akb_form_contacto]
 * provee la variable opcional `$akb_contacto_estado` ('' | 'ok' | 'error') para
 * reflejar el resultado del envío tras redirección desde admin-post.php.
 */
$akb_contacto_feedback_state = '';
$akb_contacto_feedback_text  = '';
if ( isset( $akb_contacto_estado ) ) {
	if ( 'ok' === $akb_contacto_estado ) {
		$akb_contacto_feedback_state = 'success';
		$akb_contacto_feedback_text  = __( 'Recibido. Te respondemos a la brevedad.', 'akibara-gp' );
	} elseif ( 'error' === $akb_contacto_estado ) {
		$akb_contacto_feedback_state = 'error';
		$akb_contacto_feedback_text  = __( 'No pudimos enviar el formulario. Intenta de nuevo o escríbenos a contacto@akibara.cl.', 'akibara-gp' );
	}
}
?>
<?php
/*
 * `method="post"` + `action` a admin-post.php: antes el `<form>` no declaraba
 * ninguno de los dos, así que sin JS (bloqueado, o el fetch de
 * akibara-forms.js fallando antes de registrarse) el navegador hacía el
 * submit por defecto — GET a la URL actual, nombre/email/mensaje como query
 * string, visibles en historial/referrer/logs, y NINGÚN servidor los
 * procesaba: el mensaje se perdía en silencio. `novalidate` también se
 * retira del markup: con JS, akibara-forms.js lo agrega al inicializar (así
 * manda su propia validación); sin JS, queda la nativa del navegador sobre
 * los `required` de abajo.
 */
?>
<form class="akb-form akb-form--contacto" data-akb-form="contacto" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="akb_contacto">
	<?php echo \Akibara\Business\Modules\Forms\Honeypot::field_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe HTML helper. ?>

	<div class="akb-form__grid">
		<p class="form-row form-row-first">
			<label for="akb-c-nombre">Nombre <span aria-hidden="true">*</span></label>
			<input type="text" id="akb-c-nombre" name="nombre" class="input-text" required maxlength="120" autocomplete="name">
		</p>

		<p class="form-row form-row-last">
			<label for="akb-c-email">Correo <span aria-hidden="true">*</span></label>
			<input type="email" id="akb-c-email" name="email" class="input-text" required maxlength="120" autocomplete="email" inputmode="email" spellcheck="false">
		</p>

		<p class="form-row form-row-wide">
			<label for="akb-c-mensaje">Mensaje <span aria-hidden="true">*</span></label>
			<textarea id="akb-c-mensaje" name="mensaje" class="input-text" rows="6" required maxlength="3000"></textarea>
		</p>
	</div>

	<p class="form-row form-row-wide akb-form__actions">
		<button type="submit" class="button button-primary akb-form__submit">Enviar mensaje</button>
	</p>

	<div class="akb-form__feedback" role="status" aria-live="polite"<?php echo '' !== $akb_contacto_feedback_state ? ' data-state="' . esc_attr( $akb_contacto_feedback_state ) . '"' : ''; ?>><?php echo esc_html( $akb_contacto_feedback_text ); ?></div>
</form>
