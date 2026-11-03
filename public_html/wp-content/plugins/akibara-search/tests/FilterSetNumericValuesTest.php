<?php
/**
 * Contrato: los valores de faceta viajan SIEMPRE como string.
 *
 * `split_values()` deduplica usando el valor como clave de array, y PHP
 * convierte a int toda clave que sea un string numérico. El retorno de
 * `array_keys()` salía entonces como `[1]` para `_precio=1` y como
 * `['ivrea-argentina']` para `_editorial`. Como `FacetService` valida los
 * valores con `is_string()`, las tres facetas de valores numéricos —precio,
 * novedad y oferta— hacían caer el servicio entero: el filtro no filtraba y el
 * panel de filtros desaparecía de la página [QA 2026-08-17].
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\FilterSet;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 0.1.20
 */
final class FilterSetNumericValuesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// `sanitize_title` real es de WordPress; para estos casos basta con la
		// normalización que aplica a slugs y dígitos.
		Functions\when( 'sanitize_title' )->alias(
			static function ( $texto ) {
				$texto = strtolower( trim( (string) $texto ) );
				return (string) preg_replace( '/[^a-z0-9-]+/', '-', $texto );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function facetas_numericas(): array {
		return array(
			'precio bucket 0'  => array( 'precio', '0' ),
			'precio bucket 1'  => array( 'precio', '1' ),
			'novedad bucket 0' => array( 'novedad', '0' ),
			'oferta activa'    => array( 'oferta', '1' ),
		);
	}

	/**
	 * @dataProvider facetas_numericas
	 * @testdox Los valores numéricos de faceta siguen siendo string tras el parseo.
	 */
	public function test_numeric_values_stay_strings( string $facet, string $valor ): void {
		$filters = FilterSet::from_query(
			array( '_' . $facet => $valor ),
			array( 'precio', 'novedad', 'oferta', 'editorial' ),
			array()
		);

		$values = $filters->values( $facet );
		self::assertSame( array( $valor ), $values, 'El valor debe conservarse tal cual, como string.' );
		foreach ( $values as $v ) {
			self::assertIsString( $v, 'Un valor entero hace fallar is_string() en FacetService y tumba el panel entero.' );
		}

		foreach ( $filters->joined() as $vals ) {
			foreach ( $vals as $v ) {
				self::assertIsString( $v, 'joined() alimenta directamente la validación de FacetService.' );
			}
		}
	}

	/**
	 * @testdox Los slugs no numéricos siguen intactos y se deduplica igual.
	 */
	public function test_slugs_and_dedup_are_untouched(): void {
		$filters = FilterSet::from_query(
			array( '_editorial' => 'ivrea-argentina,panini,ivrea-argentina' ),
			array( 'editorial' ),
			array()
		);

		self::assertSame( array( 'ivrea-argentina', 'panini' ), $filters->values( 'editorial' ) );
	}

	/**
	 * @testdox Varios buckets numéricos conservan orden, dedup y tipo.
	 */
	public function test_multiple_numeric_buckets(): void {
		$filters = FilterSet::from_query(
			array( '_precio' => '2,0,2,1' ),
			array( 'precio' ),
			array()
		);

		self::assertSame( array( '2', '0', '1' ), $filters->values( 'precio' ) );
	}
}
