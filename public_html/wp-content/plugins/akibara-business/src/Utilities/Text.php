<?php
/**
 * Medición y corte de texto multibyte con degradación explícita.
 *
 * Los límites de SEO se cuentan en CARACTERES, no en bytes: un título de 62
 * caracteres con tildes y «ñ» ocupa más bytes y `strlen()` lo rechazaría por
 * largo sin que nada lo dijera. Vive aquí porque la misma pareja estaba copiada
 * en los dos módulos que recortan meta (`SeoEnhancements` y
 * `SchemaProductRankMath`), y dos copias del mismo criterio de corte son dos
 * sitios donde el mismo título puede caber o no caber.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/** Longitud y subcadena por caracteres, con `mbstring` opcional. */
final class Text {

	/** Largo en caracteres UTF-8; cae a bytes si falta `mbstring`. */
	public static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/** Subcadena por caracteres UTF-8; cae a bytes si falta `mbstring`. */
	public static function substr( string $value, int $start, int $length ): string {
		return function_exists( 'mb_substr' )
			? mb_substr( $value, $start, $length, 'UTF-8' )
			: substr( $value, $start, $length );
	}

	/**
	 * Tope de un copy de una línea: más largo que esto ya no se lee, se salta.
	 *
	 * Público desde 1.116.20 porque el panel avisa al dueño cuando recortó, y
	 * para eso tiene que saber por dónde corta el saneador.
	 */
	public const ONE_LINE_MAX = 300;

	/**
	 * Copy de UNA línea guardado en una option, con su literal de siempre detrás.
	 *
	 * El patrón se repite en cada texto que la configurabilidad sacó del código
	 * (las promesas, los CTA de las dos rutas del catálogo): se imprime dentro de
	 * `esc_html()`, así que HTML pegado a mano no se renderiza sino que se LEE —
	 * y una etiqueta suelta en medio de la frase es peor que no poder editarla.
	 * Vacío cae al literal: una promesa o un enlace sin texto no es una decisión
	 * editorial, es un hueco.
	 *
	 * Se sanea también AL LEER porque la option puede llegar por `wp option
	 * update`, que no pasa por ningún formulario.
	 *
	 * @param string $option   Nombre de la option.
	 * @param string $fallback Literal que la plantilla tenía escrito.
	 */
	public static function one_line_option( string $option, string $fallback ): string {
		return self::one_line( get_option( $option, $fallback ), $fallback );
	}

	/**
	 * Normaliza un candidato a copy de una línea. {@see self::one_line_option()}.
	 *
	 * @param mixed  $raw      Candidato crudo (formulario, option o WP-CLI).
	 * @param string $fallback Literal al que caer.
	 */
	public static function one_line( $raw, string $fallback ): string {
		$clean = self::clean_one_line( $raw );

		if ( '' === $clean ) {
			return $fallback;
		}

		return self::length( $clean ) > self::ONE_LINE_MAX
			? rtrim( self::substr( $clean, 0, self::ONE_LINE_MAX ) )
			: $clean;
	}

	/**
	 * El mismo saneo que `one_line()` —etiquetas fuera, saltos de línea
	 * colapsados, extremos recortados— pero SIN aplicar el tope.
	 *
	 * Existe para que un llamador pueda preguntar «¿esto se habría cortado?»
	 * contra CUALQUIER tope (el genérico de 300, o uno más corto como el de
	 * una palabra de estado) sin perder esa respuesta: pedirle el largo a lo
	 * que YA devolvió `one_line()` nunca puede superar `ONE_LINE_MAX`, porque
	 * el propio corte ya lo dejó ahí — comparar contra un tope de 300 con ese
	 * valor jamás da «se pasó», así el original midiera 3.000 caracteres
	 * (: `CopyManager::recortados()` comparaba así y
	 * nunca detectó un recorte real para ningún campo con tope de 300).
	 *
	 * @param mixed $raw Candidato crudo.
	 */
	public static function clean_one_line( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$clean = wp_strip_all_tags( $raw );

		return trim( (string) preg_replace( '/\s+/u', ' ', $clean ) );
	}

	/**
	 * ¿Este candidato se recortaría al guardarse contra el tope dado?
	 *
	 * Mide sobre el largo SIN truncar ({@see self::clean_one_line()}): medir
	 * sobre el resultado de `one_line()` da siempre «no se pasó», porque ese
	 * resultado ya viene cortado a `ONE_LINE_MAX`.
	 *
	 * @param mixed $raw Candidato crudo.
	 * @param int   $max Tope a comparar (por defecto, el genérico de una línea).
	 */
	public static function would_clip( $raw, int $max = self::ONE_LINE_MAX ): bool {
		return self::length( self::clean_one_line( $raw ) ) > $max;
	}
}
