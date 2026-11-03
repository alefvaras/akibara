<?php
/**
 * Settings — configuración operativa del destinatario de Contacto.
 *
 * El campo se edita en Akibara → Textos, sección «Formulario de contacto»
 * (CopyManager). No merece pestaña propia: es UN campo, y vive junto al resto
 * de textos del sitio que el dueño ya administra. El default de esa pantalla es
 * exactamente `DEFAULT_NOTIFY_EMAIL`, así que estrenar la pantalla no mueve
 * nada; por eso la constante es pública (la lee CopyManager para no duplicar
 * el literal).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Forms;

use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION_NOTIFY_EMAIL   = 'akibara_forms_notify_email';
	public const DEFAULT_NOTIFY_EMAIL = 'contacto@akibara.cl';

	/**
	 * Mensajes de validación del formulario de contacto, uno por motivo del
	 * navegador (`ValidityState`). Los pinta el tema (akibara-forms.js) junto al
	 * campo cuando el cliente intenta enviar con algo mal (AI-5, T5-R3).
	 *
	 * Sin estos textos, el tema caía al `validationMessage` del navegador, que
	 * sale en el idioma de su INTERFAZ: en un navegador en inglés el cliente
	 * leía «Please fill out this field.» en una tienda en español. El formulario
	 * sólo puede fallar por esos tres motivos: los campos son `required`, el
	 * correo es `type="email"` y los tres llevan `maxlength` (template-parts/
	 * forms/contacto.php).
	 *
	 * @var array<string, array{option:string, default:string}>
	 */
	public const MESSAGES = array(
		'valueMissing' => array(
			'option'  => 'akb_copy_form_value_missing',
			'default' => 'Completa este campo.',
		),
		'typeMismatch' => array(
			'option'  => 'akb_copy_form_type_mismatch',
			'default' => 'Escribe un correo válido, por ejemplo nombre@correo.cl.',
		),
		'tooLong'      => array(
			'option'  => 'akb_copy_form_too_long',
			'default' => 'Es demasiado largo: acórtalo un poco.',
		),
	);

	/**
	 * Los mensajes tal como los lee el formulario: una línea, sin etiquetas y
	 * nunca vacíos (vacío o raro vuelve al de fábrica, igual que al guardar).
	 *
	 * @return array<string, string> Clave de ValidityState => texto plano.
	 */
	public static function messages(): array {
		$mensajes = array();
		foreach ( self::MESSAGES as $motivo => $mensaje ) {
			$mensajes[ $motivo ] = Text::one_line_option( $mensaje['option'], $mensaje['default'] );
		}

		return $mensajes;
	}

	/**
	 * Option => literal de fábrica, para registrarlas como copys obligatorios.
	 *
	 * @return array<string, string>
	 */
	public static function message_defaults(): array {
		return array_column( self::MESSAGES, 'default', 'option' );
	}

	/**
	 * Devuelve el email destinatario configurado o fallback a contacto@akibara.cl.
	 */
	public static function get_notify_email(): string {
		$email = get_option( self::OPTION_NOTIFY_EMAIL, '' );
		return is_string( $email ) && is_email( $email ) ? $email : self::DEFAULT_NOTIFY_EMAIL;
	}
}
