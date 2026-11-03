<?php
/**
 * El alta de un filtro nuevo no guarda un nombre que el lector rechazaría.
 *
 * `process_new_facet()` sólo rechazaba nombre vacío, duplicado y taxonomía
 * fuera de la lista: el LARGO no se medía nunca. `sanitize_key()` no recorta —y
 * al borrar `:`, `/` y espacios CONCATENA, así que pegar una URL pasa de 64 con
 * facilidad—, el panel decía «Facetas guardadas» y en la petición siguiente el
 * lector invalidaba la configuración entera: indexación, autocompletado y
 * filtros apagados a la vez (reapertura de S-5ef0fea5, auditoría independiente
 * del 2026-09-19).
 *
 * Se ejercita el método REAL del panel con su `$_POST`: un candado de texto
 * sobre el fichero no distinguía el alta de la defensa del bucle de filas.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\FacetRegistry;
use Akibara\Search\SearchAdminSection;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__ ) . '/src/FacetRegistry.php';
require_once dirname( __DIR__ ) . '/src/SearchAdminSection.php';

final class ElAltaDeFiltroNoGuardaLoQueElLectorRechazaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( $v ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) )
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ): string => trim( (string) $v ) );
		Functions\when( 'get_object_taxonomies' )->justReturn(
			array(
				(object) array(
					'name'    => 'pa_formato',
					'public'  => true,
					'show_ui' => true,
					'label'   => 'Formato',
				),
			)
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,string>                $nuevo Campos de `new_facet`.
	 * @param array<string, array<string, mixed>> $out   Overrides ya acumulados.
	 * @return array{0:string,1:array<string,array<string,mixed>>}
	 */
	private function alta( array $nuevo, array $out = array() ): array {
		$_POST['new_facet'] = $nuevo;

		$metodo = new ReflectionMethod( SearchAdminSection::class, 'process_new_facet' );
		$metodo->setAccessible( true );

		$panel  = ( new \ReflectionClass( SearchAdminSection::class ) )->newInstanceWithoutConstructor();
		$codigo = (string) $metodo->invokeArgs( $panel, array( array(), 1, &$out ) );

		return array( $codigo, $out );
	}

	/** @testdox Un nombre de 70 caracteres se rechaza con su aviso y NO se guarda. */
	public function test_un_nombre_largo_se_rechaza(): void {
		list( $codigo, $out ) = $this->alta(
			array(
				'name'     => str_repeat( 'a', FacetRegistry::MAX_NAME_LENGTH + 6 ),
				'taxonomy' => 'pa_formato',
			)
		);

		self::assertSame( 'facet_new_bad_name', $codigo );
		self::assertSame( array(), $out, 'Nada de ese intento llega a la option.' );
	}

	/** @testdox Pegar una URL como nombre no se cuela: sanitize_key la concatena y pasa de 64. */
	public function test_una_url_pegada_no_se_cuela(): void {
		list( $codigo, $out ) = $this->alta(
			array(
				'name'     => 'https://akibara.cl/tienda/?formato=tapa-dura&editorial=ivrea-argentina&orden=precio',
				'taxonomy' => 'pa_formato',
			)
		);

		self::assertSame( 'facet_new_bad_name', $codigo );
		self::assertSame( array(), $out );
	}

	/** @testdox Un nombre justo en el límite sí se agrega. */
	public function test_un_nombre_en_el_limite_entra(): void {
		$justo = str_repeat( 'b', FacetRegistry::MAX_NAME_LENGTH );

		list( $codigo, $out ) = $this->alta(
			array(
				'name'     => $justo,
				'taxonomy' => 'pa_formato',
			)
		);

		self::assertSame( 'added', $codigo );
		self::assertArrayHasKey( $justo, $out );
		self::assertTrue( FacetRegistry::name_is_valid( $justo ) );
	}

	/** @testdox Con el tope de filtros alcanzado, el alta se rechaza diciéndolo. */
	public function test_el_tope_de_filtros_se_respeta(): void {
		$llenos = array();
		for ( $i = 0; $i < FacetRegistry::MAX_FACETS; $i++ ) {
			$llenos[ 'f' . $i ] = array( 'visible' => true );
		}

		list( $codigo, $out ) = $this->alta(
			array(
				'name'     => 'uno-mas',
				'taxonomy' => 'pa_formato',
			),
			$llenos
		);

		self::assertSame( 'facet_new_limit', $codigo );
		self::assertCount( FacetRegistry::MAX_FACETS, $out );
	}
}
