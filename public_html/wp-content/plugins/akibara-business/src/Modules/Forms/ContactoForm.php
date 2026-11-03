<?php
/**
 * ContactoForm — registra el shortcode `[akb_form_contacto]`.
 *
 * Delega render al theme via template-parts/forms/contacto.php.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Forms;

defined( 'ABSPATH' ) || exit;

final class ContactoForm {

	public const SHORTCODE = 'akb_form_contacto';

	/** Valores que la plantilla del tema sabe pintar; cualquier otro se ignora. */
	private const ESTADOS_VALIDOS = array( 'ok', 'error' );

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	public function render(): string {
		$template = locate_template( 'template-parts/forms/contacto.php' );
		if ( $template !== '' ) {
			// El respaldo sin JS vuelve con akb_contacto=ok|error, sin datos personales
			// en la URL. Resuelve esta variable mediante lista blanca antes del template;
			// no pasa valores GET crudos al tema.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- de sólo lectura: decide qué AVISO se pinta, no muta nada.
			$crudo               = isset( $_GET['akb_contacto'] ) ? sanitize_key( wp_unslash( $_GET['akb_contacto'] ) ) : '';
			$akb_contacto_estado = in_array( $crudo, self::ESTADOS_VALIDOS, true ) ? $crudo : '';

			ob_start();
			include $template;
			return (string) ob_get_clean();
		}
		return '<p><em>Template del form de contacto no disponible.</em></p>';
	}
}
