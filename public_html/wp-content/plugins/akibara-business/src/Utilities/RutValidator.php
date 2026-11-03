<?php
/**
 * RUT chileno — validador, formateador y limpiador.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Utilities puras (sin side effects, sin hooks WP) para manipulación de RUT chileno.
 *
 * RUT chileno = identificador único persona natural o jurídica.
 * Formato canónico: NNNNNNNN-D donde N son dígitos y D es el dígito verificador (0-9 o K).
 * Ejemplos válidos: "12.345.678-5", "12345678-5", "12345678-K", "5.126.663-3".
 *
 * Algoritmo dígito verificador: módulo 11 con secuencia 2,3,4,5,6,7 cíclica
 * desde el dígito menos significativo del cuerpo.
 *
 * @since 1.0.0
 */
final class RutValidator {

	/**
	 * Constructor privado — clase utilitaria, no instanciable.
	 */
	private function __construct() {}

	/**
	 * Valida un RUT chileno completo (cuerpo + dígito verificador).
	 *
	 * Acepta cualquier formato (con/sin puntos, con/sin guión, K mayúscula o minúscula).
	 * El cuerpo debe tener entre 6 y 9 dígitos para ser plausible (RUTs bajos
	 * de cuerpo 6 — p.ej. 123.456-0 — son legítimos; el gatekeeper es el DV).
	 *
	 * @since 1.0.0
	 *
	 * @param string $rut RUT en cualquier formato.
	 * @return bool True si el RUT es válido (formato + dígito verificador correcto).
	 */
	public static function is_valid( string $rut ): bool {
		$clean = self::clean( $rut );

		if ( strlen( $clean ) < 7 || strlen( $clean ) > 10 ) {
			return false;
		}

		$body  = substr( $clean, 0, -1 );
		$given = substr( $clean, -1 );

		if ( ! ctype_digit( $body ) ) {
			return false;
		}

		// Cuerpo de dígitos todos iguales (11111111-1, 2222222-2, …): patrón trivial
		// no asignable. El frontend (rut.js) ya lo rechaza; igualamos el criterio en
		// el server (autoritativo) para no persistir RUTs basura que igual pasan el DV.
		if ( preg_match( '/^(\d)\1+$/', $body ) ) {
			return false;
		}

		if ( ! preg_match( '/^[0-9K]$/', $given ) ) {
			return false;
		}

		return self::compute_check_digit( $body ) === $given;
	}

	/**
	 * Limpia un RUT removiendo puntos, guiones y espacios. Normaliza K a mayúscula.
	 *
	 * @since 1.0.0
	 *
	 * @param string $rut RUT en cualquier formato.
	 * @return string RUT solo con dígitos y K final si aplica (e.g., "123456785", "12345678K").
	 */
	public static function clean( string $rut ): string {
		$rut = preg_replace( '/[\s\.\-]/', '', $rut );
		return strtoupper( (string) $rut );
	}

	/**
	 * Formatea un RUT al formato canónico con puntos y guión: "12.345.678-5".
	 *
	 * Si el RUT es inválido, devuelve la entrada limpia sin formato cosmético.
	 *
	 * @since 1.0.0
	 *
	 * @param string $rut RUT en cualquier formato.
	 * @return string RUT formateado o entrada limpia si es inválido.
	 */
	public static function format( string $rut ): string {
		$clean = self::clean( $rut );

		if ( strlen( $clean ) < 2 ) {
			return $clean;
		}

		$body  = substr( $clean, 0, -1 );
		$check = substr( $clean, -1 );

		if ( ! ctype_digit( $body ) ) {
			return $clean;
		}

		// Insertar puntos cada 3 dígitos desde la derecha.
		$body_formatted = strrev( implode( '.', str_split( strrev( $body ), 3 ) ) );

		return $body_formatted . '-' . $check;
	}

	/**
	 * Calcula el dígito verificador para un cuerpo de RUT.
	 *
	 * @since 1.0.0
	 *
	 * @param string $body Cuerpo del RUT (solo dígitos, sin DV).
	 * @return string Dígito verificador esperado ('0'-'9' o 'K').
	 */
	public static function compute_check_digit( string $body ): string {
		if ( ! ctype_digit( $body ) || '' === $body ) {
			return '';
		}

		$sum    = 0;
		$factor = 2;
		$length = strlen( $body );

		for ( $i = $length - 1; $i >= 0; $i-- ) {
			$sum   += (int) $body[ $i ] * $factor;
			$factor = ( 7 === $factor ) ? 2 : $factor + 1;
		}

		$remainder = 11 - ( $sum % 11 );

		if ( 11 === $remainder ) {
			return '0';
		}
		if ( 10 === $remainder ) {
			return 'K';
		}
		return (string) $remainder;
	}
}
