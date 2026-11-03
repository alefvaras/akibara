<?php
/**
 * Test: Inferencia determinista de país a partir de la marca/editorial
 * cuando el producto carece del atributo explícito pa_pais.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\DocumentBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Term;

final class InferenciaPaisEditorialTest extends TestCase {

	/**
	 * @testdox DocumentBuilder infiere Argentina ante marcas argentinas sin pa_pais.
	 */
	public function test_infiere_argentina_ante_editorial_argentina(): void {
		$builder = new DocumentBuilder();
		$method  = new ReflectionMethod( DocumentBuilder::class, 'infer_country_term_from_brands' );
		$method->setAccessible( true );

		$brand = new WP_Term( 'product_brand', 101, 0, 'ivrea-argentina', 'Ivrea Argentina' );

		$term = $method->invoke( $builder, array( $brand ) );
		self::assertInstanceOf( WP_Term::class, $term );
		self::assertSame( 'argentina', $term->slug );
		self::assertSame( 98, $term->term_id );
	}

	/**
	 * @testdox DocumentBuilder infiere España ante marcas españolas sin pa_pais.
	 */
	public function test_infiere_espana_ante_editorial_espanola(): void {
		$builder = new DocumentBuilder();
		$method  = new ReflectionMethod( DocumentBuilder::class, 'infer_country_term_from_brands' );
		$method->setAccessible( true );

		$brand = new WP_Term( 'product_brand', 102, 0, 'distrito-manga', 'Distrito Manga' );

		$term = $method->invoke( $builder, array( $brand ) );
		self::assertInstanceOf( WP_Term::class, $term );
		self::assertSame( 'espana', $term->slug );
		self::assertSame( 154, $term->term_id );
	}

	/**
	 * @testdox DocumentBuilder no inventa país ante marcas no mapeadas o ambiguas.
	 */
	public function test_omite_pais_ante_marca_desconocida(): void {
		$builder = new DocumentBuilder();
		$method  = new ReflectionMethod( DocumentBuilder::class, 'infer_country_term_from_brands' );
		$method->setAccessible( true );

		$brand = new WP_Term( 'product_brand', 999, 0, 'marca-generica-sin-region', 'Genérica' );

		$term = $method->invoke( $builder, array( $brand ) );
		self::assertNull( $term );
	}
}
