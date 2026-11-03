<?php
/**
 * Honeypot — campo trampa anti-bot.
 *
 * Renderiza un input oculto via CSS (no display:none — los bots lo skipean).
 * Si llega con valor en el submit, descartar como spam.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Forms;

defined( 'ABSPATH' ) || exit;

final class Honeypot {

	private const FIELD = 'akb_hp_url';

	/**
	 * Devuelve el HTML del honeypot — incluir en cada form.
	 */
	public static function field_html(): string {
		return sprintf(
			'<div class="akb-form__hp" aria-hidden="true" inert><label>Si eres humano, deja este campo vacío:<input type="text" name="%s" tabindex="-1" autocomplete="off"></label></div>',
			esc_attr( self::FIELD )
		);
	}

	/**
	 * Verifica si el submission disparó el honeypot.
	 *
	 * @param array<string, mixed> $params Sanitized request params.
	 */
	public static function tripped( array $params ): bool {
		$value = $params[ self::FIELD ] ?? '';
		return is_string( $value ) && $value !== '';
	}
}
