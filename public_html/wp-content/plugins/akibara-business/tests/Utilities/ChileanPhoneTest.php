<?php
/**
 * Tests for the canonical Chilean mobile phone utility.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Utilities\ChileanPhone;
use PHPUnit\Framework\TestCase;

final class ChileanPhoneTest extends TestCase {

	/**
	 * @testdox Should extract national digits only from explicitly supported formats.
	 *
	 * @dataProvider supported_phone_formats
	 */
	public function test_extracts_supported_formats( string $input, string $expected ): void {
		self::assertSame(
			$expected,
			ChileanPhone::extract_digits( $input ),
			'Supported phone formats should resolve to the same eight national digits.'
		);
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function supported_phone_formats(): array {
		return array(
			'national digits'    => array( '2345 6789', '23456789' ),
			'legacy mobile'      => array( '9 2345 6789', '23456789' ),
			'parenthesized mobile' => array( '(9) 2345-6789', '23456789' ),
			'country digits'     => array( '569 2345 6789', '23456789' ),
			'international E164' => array( '+56 9 2345 6789', '23456789' ),
			'compact E164'       => array( '+56923456789', '23456789' ),
		);
	}

	/**
	 * @testdox Should reject unsupported lengths instead of silently truncating them.
	 *
	 * @dataProvider unsupported_phone_formats
	 */
	public function test_rejects_unsupported_formats_without_truncation( string $input ): void {
		self::assertSame(
			'',
			ChileanPhone::extract_digits( $input ),
			'Unsupported input must fail closed instead of losing digits.'
		);
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function unsupported_phone_formats(): array {
		return array(
			'too short'              => array( '2345678' ),
			'ten arbitrary digits'   => array( '2234567890' ),
			'wrong country prefix'   => array( '+57 9 2345 6789' ),
			'malformed international' => array( '+56 8 2345 6789' ),
			'letters'                => array( '+56 9 2345 6789 ext 1' ),
			'double plus'            => array( '++56923456789' ),
			'middle plus'            => array( '56+923456789' ),
			'unbalanced parentheses' => array( '(9 2345 6789' ),
			'reversed parentheses'   => array( '9) 2345 (6789' ),
			'empty parentheses'      => array( '9 () 2345 6789' ),
			'unsupported punctuation' => array( '+56.9.2345.6789' ),
		);
	}

	/**
	 * @testdox Should return stable validation codes for every invalid category.
	 *
	 * @dataProvider invalid_phone_categories
	 */
	public function test_returns_stable_validation_errors( string $input, bool $required, string $expected_code ): void {
		$error = ChileanPhone::validation_error( $input, $required );

		self::assertIsArray( $error, 'Invalid phones should return a validation error.' );
		self::assertSame( $expected_code, $error['code'], 'The adapter relies on stable validation codes.' );
		self::assertNotSame( '', $error['message'], 'Every validation error should include customer-facing copy.' );
	}

	/**
	 * @return array<string,array{string,bool,string}>
	 */
	public function invalid_phone_categories(): array {
		return array(
			'required'       => array( '', true, 'akibara_phone_required' ),
			'length'         => array( '2345678901', true, 'akibara_invalid_phone_length' ),
			'repeated'       => array( '22222222', true, 'akibara_invalid_phone_repeated' ),
			'unassigned range' => array( '12345678', true, 'akibara_invalid_phone_range' ),
		);
	}

	/**
	 * @testdox Should accept an optional empty phone and format valid input as E.164.
	 */
	public function test_optional_empty_and_canonical_format(): void {
		self::assertNull(
			ChileanPhone::validation_error( '', false ),
			'Partial Store API updates may omit the phone.'
		);
		self::assertNull(
			ChileanPhone::validation_error( '2345 6789' ),
			'A plausible Chilean mobile should validate.'
		);
		self::assertSame(
			'+56923456789',
			ChileanPhone::format( '9 2345 6789' ),
			'Valid input should be persisted in one canonical format.'
		);
		self::assertSame(
			'2234567890',
			ChileanPhone::format( '2234567890' ),
			'Unsupported input must never be silently truncated during formatting.'
		);
	}

	/**
	 * @testdox Los ejemplos que el mensaje le da al cliente tienen que pasar su propio validador.
	 *
	 * El mensaje de longitud decía «Ejemplo: 1234 5678», y este mismo validador
	 * rechaza los números que empiezan en 0 o en 1 (ningún celular chileno lo
	 * hace). Quien copiaba el ejemplo tal cual saltaba de «debe tener 8 dígitos»
	 * a «no parece válido» sin entender qué había cambiado. El mismo número
	 * estaba de placeholder en el checkout.
	 *
	 * El candado es por INVARIANTE, no por literal: se extraen los números que
	 * aparezcan en CUALQUIER mensaje de error y se exige que el validador los
	 * acepte. Da igual cómo se reescriba el copy — no puede volver a sugerir un
	 * número que él mismo rechaza.
	 */
	public function test_every_example_inside_an_error_message_is_itself_valid(): void {
		$entradas_malas = array( '', '123', '1234 5678', '9 5555 5555', '0000 0000', 'abc' );
		$revisados      = 0;

		foreach ( $entradas_malas as $entrada ) {
			$error = ChileanPhone::validation_error( $entrada, true );
			if ( null === $error || ! isset( $error['message'] ) ) {
				continue;
			}

			// Cualquier secuencia de 8+ dígitos del mensaje (con o sin espacios)
			// se interpreta como un ejemplo ofrecido al cliente.
			if ( ! preg_match_all( '/(?:\d[\d ]{6,}\d)/u', (string) $error['message'], $coincidencias ) ) {
				continue;
			}

			foreach ( $coincidencias[0] as $ejemplo ) {
				$ejemplo = trim( $ejemplo );
				++$revisados;
				self::assertNull(
					ChileanPhone::validation_error( $ejemplo, true ),
					sprintf( 'El mensaje «%s» propone «%s», que el propio validador rechaza.', $error['message'], $ejemplo )
				);
			}
		}

		self::assertGreaterThan( 0, $revisados, 'Se esperaba al menos un mensaje con ejemplo numérico.' );
	}

	/**
	 * @testdox Acepta el celular escrito como lo escribe un chileno.
	 */
	public function test_accepts_the_formats_people_actually_type(): void {
		foreach ( array( '8765 4321', '87654321', '9 8765 4321', '987654321', '+56 9 8765 4321', '+56987654321' ) as $entrada ) {
			self::assertNull(
				ChileanPhone::validation_error( $entrada, true ),
				sprintf( 'Debería aceptar «%s».', $entrada )
			);
		}
	}
}
