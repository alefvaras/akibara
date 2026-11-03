<?php
/**
 * EditionCopy — los textos de las ediciones que el dueño edita en Textos.
 *
 * Cada literal que el cliente lee en el conmutador y en las tarjetas vive
 * aquí como option con su default; CopyManager los expone y los sanea como
 * copys de una línea obligatorios (vacío = vuelve al literal).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.114.18
 */
final class EditionCopy {

	public const OPTION_SWITCH_LABEL   = 'akb_copy_editions_label';
	public const OPTION_SWITCH_COMPARE = 'akb_copy_editions_compare';
	public const OPTION_CARD_CTA       = 'akb_copy_edition_cta';
	public const OPTION_CARD_COUNT     = 'akb_copy_edition_count';
	public const OPTION_CARD_COUNT_ONE = 'akb_copy_edition_count_one';
	public const OPTION_PRICE_FROM     = 'akb_copy_edition_price_from';

	public const DEFAULT_SWITCH_LABEL   = 'Ediciones de %s';
	public const DEFAULT_SWITCH_COMPARE = 'Comparar las dos ediciones';
	public const DEFAULT_CARD_CTA       = 'Ver %s';
	public const DEFAULT_CARD_COUNT     = '%s productos en catálogo';
	public const DEFAULT_CARD_COUNT_ONE = '1 producto en catálogo';
	public const DEFAULT_PRICE_FROM     = 'Desde %s';

	/**
	 * option → default, para CopyManager (exposición y saneado obligatorio).
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			self::OPTION_SWITCH_LABEL   => self::DEFAULT_SWITCH_LABEL,
			self::OPTION_SWITCH_COMPARE => self::DEFAULT_SWITCH_COMPARE,
			self::OPTION_CARD_CTA       => self::DEFAULT_CARD_CTA,
			self::OPTION_CARD_COUNT     => self::DEFAULT_CARD_COUNT,
			self::OPTION_CARD_COUNT_ONE => self::DEFAULT_CARD_COUNT_ONE,
			self::OPTION_PRICE_FROM     => self::DEFAULT_PRICE_FROM,
		);
	}

	/**
	 * El conteo de una edición con su plural correcto («1 producto», «49 productos»).
	 *
	 * @param int $count Productos de la edición.
	 * @return string
	 */
	public static function count( int $count ): string {
		if ( 1 === $count ) {
			return self::text( self::OPTION_CARD_COUNT_ONE );
		}
		return self::text( self::OPTION_CARD_COUNT, number_format_i18n( $count ) );
	}

	/**
	 * Un texto con su marcador `%s` rellenado. Si el dueño borró el marcador,
	 * el valor se añade al final para que nunca desaparezca del todo.
	 *
	 * @param string $option Una de las constantes OPTION_*.
	 * @param string $value  Lo que va en el `%s`.
	 * @return string
	 */
	public static function text( string $option, string $value = '' ): string {
		self::prime();
		$template = Text::one_line_option( $option, self::defaults()[ $option ] ?? '' );
		if ( '' === $value ) {
			return $template;
		}
		// Nunca sprintf con texto del dueño: un «%» suelto sería un fatal.
		if ( false === strpos( $template, '%s' ) ) {
			return trim( $template . ' ' . $value );
		}
		return str_replace( '%s', $value, $template );
	}

	/**
	 * Calienta las options de este componente una vez por request (una consulta
	 * en vez de una por texto); Core anota las que no existen en `notoptions`.
	 *
	 * @since 1.114.18
	 *
	 * @return void
	 */
	private static function prime(): void {
		static $primed = false;
		if ( $primed || ! function_exists( 'wp_prime_option_caches' ) ) {
			return;
		}
		$primed = true;
		wp_prime_option_caches( array_keys( self::defaults() ) );
	}
}
