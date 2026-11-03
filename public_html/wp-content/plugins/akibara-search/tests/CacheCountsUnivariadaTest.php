<?php
/**
 * Test: Extensión del Sello Criptográfico TOCTOU a consultas univariadas.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\FacetRegistry;
use Akibara\Search\FacetService;
use Akibara\Search\FilterSet;
use Akibara\Search\Install;
use Akibara\Search\Novedad;
use Akibara\Search\SemanticPublication;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CacheCountsUnivariadaWpdb {
	public string $prefix     = 'wp_';
	public string $posts      = 'wp_posts';
	public string $last_error = '';
	public array $queries     = array();
}

final class CacheCountsUnivariadaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['wpdb'] = new CacheCountsUnivariadaWpdb();

		SemanticPublication::reset_authoritative_state_for_tests();
		FacetRegistry::invalidate_runtime_memo();
		SemanticPublication::adopt_authoritative_snapshot(
			array(
				Install::OPTION_INDEX_GEN => 'gen-test-1',
				Novedad::OPTION           => 'no',
				FacetRegistry::OPTION_KEY => serialize(
					array(
						'editorial' => array( 'label' => 'Editorial', 'type' => 'taxonomy', 'taxonomy' => 'product_brand' ),
						'genero'    => array( 'label' => 'Género', 'type' => 'taxonomy', 'taxonomy' => 'pa_genero' ),
					)
				),
			)
		);
	}

	protected function tearDown(): void {
		FacetRegistry::invalidate_runtime_memo();
		SemanticPublication::reset_authoritative_state_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_service(): FacetService {
		return new FacetService( new CacheCountsUnivariadaWpdb() );
	}

	/**
	 * @testdox is_univariate_selection reconoce selecciones de 1 faceta con 1 valor válido.
	 */
	public function test_reconoce_seleccion_univariada(): void {
		$service = $this->make_service();
		$method  = new ReflectionMethod( FacetService::class, 'is_univariate_selection' );
		$method->setAccessible( true );

		// 1 faceta con 1 valor (univariada legítima)
		$univariada = new FilterSet( array( 'editorial' => array( 'ivrea-argentina' ) ) );
		self::assertTrue( $method->invoke( $service, $univariada ) );

		// 1 faceta con 2 valores (multivalor -> no univariada)
		$multivalor = new FilterSet( array( 'editorial' => array( 'ivrea-argentina', 'panini-manga' ) ) );
		self::assertFalse( $method->invoke( $service, $multivalor ) );

		// 2 facetas distintas (bivariada -> no univariada)
		$bivariada = new FilterSet( array(
			'editorial' => array( 'ivrea-argentina' ),
			'genero'    => array( 'accion' ),
		) );
		self::assertFalse( $method->invoke( $service, $bivariada ) );

		// Vacía (0 facetas)
		$vacia = new FilterSet( array() );
		self::assertFalse( $method->invoke( $service, $vacia ) );
	}

	/**
	 * @testdox counts_cache_key es canónica e insensible al orden de inserción de facetas o valores.
	 */
	public function test_cache_key_canonica(): void {
		$service = $this->make_service();
		$method  = new ReflectionMethod( FacetService::class, 'counts_cache_key' );
		$method->setAccessible( true );

		$f1 = new FilterSet( array(
			'genero'    => array( 'shonen', 'accion' ),
			'editorial' => array( 'ivrea-argentina' ),
		) );

		$f2 = new FilterSet( array(
			'editorial' => array( 'ivrea-argentina' ),
			'genero'    => array( 'accion', 'shonen' ),
		) );

		$key1 = $method->invoke( $service, $f1 );
		$key2 = $method->invoke( $service, $f2 );

		self::assertStringStartsWith( 'akb_lc_', $key1 );
		self::assertSame( $key1, $key2 );
	}
}
