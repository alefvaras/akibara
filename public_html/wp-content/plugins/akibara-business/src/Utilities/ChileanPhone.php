<?php
/**
 * Pure Chilean mobile phone parsing and validation.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical parser shared by first-party checkout domain and adapters.
 *
 * @since 1.95.0
 */
final class ChileanPhone {

	/**
	 * Utility class.
	 */
	private function __construct() {}

	/**
	 * Extracts the eight national digits from a supported phone format.
	 *
	 * @param string $phone Raw phone.
	 * @return string Eight digits or an empty string when the format is unsupported.
	 *
	 * @since 1.95.0
	 */
	public static function extract_digits( string $phone ): string {
		$phone = trim( $phone );
		if ( '' === $phone || ! self::has_supported_grammar( $phone ) ) {
			return '';
		}

		$has_international_plus = '+' === $phone[0];
		$clean                  = preg_replace( '/\D/', '', $phone ) ?? '';
		$length = strlen( $clean );

		if ( $has_international_plus ) {
			return 11 === $length && str_starts_with( $clean, '569' )
				? substr( $clean, 3 )
				: '';
		}

		if ( 8 === $length ) {
			return $clean;
		}

		if ( 9 === $length && '9' === $clean[0] ) {
			return substr( $clean, 1 );
		}

		if ( 11 === $length && str_starts_with( $clean, '569' ) ) {
			return substr( $clean, 3 );
		}

		return '';
	}

	/**
	 * Validates the supported separator grammar before removing anything.
	 *
	 * @param string $phone Trimmed phone.
	 * @return bool
	 */
	private static function has_supported_grammar( string $phone ): bool {
		if ( 1 !== preg_match( '/\A\+?[0-9\s()\-]+\z/u', $phone ) ) {
			return false;
		}

		$without_plus = '+' === $phone[0] ? substr( $phone, 1 ) : $phone;
		if ( '' === $without_plus
			|| '-' === $without_plus[0]
			|| '-' === substr( $without_plus, -1 )
			|| str_contains( $without_plus, '--' ) ) {
			return false;
		}

		$depth             = 0;
		$parenthesis_digit = false;
		$length            = strlen( $without_plus );

		for ( $index = 0; $index < $length; $index++ ) {
			$character = $without_plus[ $index ];
			if ( '(' === $character ) {
				if ( 0 !== $depth ) {
					return false;
				}
				$depth             = 1;
				$parenthesis_digit = false;
				continue;
			}

			if ( ')' === $character ) {
				if ( 1 !== $depth || ! $parenthesis_digit ) {
					return false;
				}
				$depth = 0;
				continue;
			}

			if ( 1 === $depth && ctype_digit( $character ) ) {
				$parenthesis_digit = true;
			}
		}

		return 0 === $depth;
	}

	/**
	 * Returns the first validation error or null when the phone is valid.
	 *
	 * @param string $phone    Raw phone.
	 * @param bool   $required Whether an empty value is an error.
	 * @return array{code:string,message:string}|null
	 *
	 * @since 1.95.0
	 */
	public static function validation_error( string $phone, bool $required = true ): ?array {
		if ( '' === trim( $phone ) ) {
			return $required
				? array(
					'code'    => 'akibara_phone_required',
					'message' => 'El celular es obligatorio.',
				)
				: null;
		}

		$digits = self::extract_digits( $phone );
		if ( 8 !== strlen( $digits ) ) {
			// El ejemplo de celular debe cumplir esta misma validación.
			// La entrada puede incluir el9 inicial que extract_digits() normaliza; los
			// números locales no empiezan en0 ni1.

			return array(
				'code'    => 'akibara_invalid_phone_length',
				'message' => 'Ingresa tu número de celular (9 dígitos). Ejemplo: 9 8765 4321.',
			);
		}

		if ( preg_match( '/^(\d)\1+$/', $digits ) ) {
			return array(
				'code'    => 'akibara_invalid_phone_repeated',
				'message' => 'El celular ingresado no parece válido.',
			);
		}

		if ( preg_match( '/^[01]/', $digits ) ) {
			return array(
				'code'    => 'akibara_invalid_phone_range',
				'message' => 'El celular no parece válido. Revisa el número.',
			);
		}

		return null;
	}

	/**
	 * Formats a supported phone as E.164.
	 *
	 * OJO: sólo mira el LARGO. Un `11111111` o un `01234567` salen de aquí como
	 * `+56911111111` / `+56901234567` aunque `validation_error()` los rechace.
	 *  (): el primer ejemplo tenía el `9` recortado — daba a
	 * entender que a veces falta, y el código SIEMPRE lo antepone.
	 * Quien persista el resultado tiene que validar primero — ver
	 * `PhoneValidation::normalized_or_null()`.
	 *
	 * @param string $phone Raw phone.
	 * @return string Canonical phone or the original value when unsupported.
	 *
	 * @since 1.95.0
	 */
	public static function format( string $phone ): string {
		$digits = self::extract_digits( $phone );

		return 8 === strlen( $digits ) ? '+569' . $digits : $phone;
	}
}
