<?php
/**
 * Contrato del codec de `wp_options`.
 *
 * El par serializar/deserializar estaba copiado en tres clases y las copias NO
 * aceptaban lo mismo: la de Install deserializaba con `@unserialize( $value )`
 * a secas y la de PriceBuckets pasaba `array( 'allowed_classes' => false )`. La
 * misma operación con dos posturas de seguridad, según qué fichero la llamara.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\Support\OptionCodec;
use PHPUnit\Framework\TestCase;

/** Clase de prueba: nunca debe llegar a instanciarse desde una fila. */
final class OptionCodecPayload {

	public string $marca = 'sin tocar';
}

final class OptionCodecTest extends TestCase {

	/** @testdox Un valor decodificado NUNCA instancia un objeto desde wp_options. */
	public function test_una_fila_de_options_es_dato_y_no_codigo(): void {
		$raw = serialize( new OptionCodecPayload() );

		$decoded = OptionCodec::decode( $raw );

		self::assertNotInstanceOf( OptionCodecPayload::class, $decoded );
		self::assertInstanceOf( \__PHP_Incomplete_Class::class, $decoded );
	}

	/** @testdox El ida y vuelta conserva los valores que el plugin guarda de verdad. */
	public function test_el_ida_y_vuelta_conserva_los_valores_reales(): void {
		$cases = array(
			'escalar'  => 'gen-42',
			'entero'   => 7,
			'lista'    => array( 'a', 'b' ),
			'mapa'     => array( 'protocol' => 1, 'tag' => 'akb_search_rest' ),
			'anidado'  => array( 'buckets' => array( array( 'min' => 0, 'max' => 9990 ) ) ),
			'vacio'    => array(),
			'nulo'     => null,
			'falso'    => false,
		);

		foreach ( $cases as $name => $value ) {
			$encoded = OptionCodec::encode( $value );
			self::assertIsString( $encoded, $name );
			// Los escalares se guardan como texto plano, igual que hace Core.
			$expected = is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : $value;
			self::assertEquals( $expected, OptionCodec::decode( $encoded ), $name );
		}
	}

	/** @testdox Un valor no serializado vuelve tal cual, sin inventar un false. */
	public function test_un_valor_no_serializado_vuelve_tal_cual(): void {
		self::assertSame( 'texto plano', OptionCodec::decode( 'texto plano' ) );
		self::assertSame( '', OptionCodec::decode( '' ) );

		// El único payload que serializa a `false` sí tiene que devolver false.
		self::assertFalse( OptionCodec::decode( 'b:0;' ) );
	}
}
