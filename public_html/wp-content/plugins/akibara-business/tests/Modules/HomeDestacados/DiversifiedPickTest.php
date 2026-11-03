<?php
/**
 * Contrato de la selección diversificada de la portada.
 *
 * El shortcode pide N productos con un tope por serie para que la retícula no
 * se llene de tomos de la misma obra. Con una sola pasada, una categoría con
 * menos series que slots devolvía SIEMPRE menos productos de los pedidos:
 * «Distrito Cómics» pedía 8 y solo podía dar 7 —el catálogo tiene 7 series de
 * cómics y el tope era 1 por serie—, dejando un hueco de 308×672 px en
 * escritorio y media fila vacía en móvil [QA 2026-08-17].
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\HomeDestacados;

use Akibara\Business\Modules\HomeDestacados\HomeDestacados;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @since 1.107.5
 */
final class DiversifiedPickTest extends TestCase {

	/** @var array<int, string> Serie de cada producto de prueba. */
	private array $series = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'update_object_term_cache' )->justReturn( true );
		Functions\when( 'get_the_terms' )->alias(
			function ( $post_id ) {
				$slug = $this->series[ $post_id ] ?? null;
				if ( null === $slug ) {
					return false;
				}
				return array( (object) array( 'slug' => $slug ) );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<int, int>
	 */
	private function elegir( array $ids, int $max_per_serie, int $count ): array {
		$metodo = new ReflectionMethod( HomeDestacados::class, 'pick_diversified_ids' );
		$metodo->setAccessible( true );

		return $metodo->invoke( new HomeDestacados(), $ids, $max_per_serie, $count );
	}

	/**
	 * @testdox Con series de sobra, respeta el tope y no repite serie.
	 */
	public function test_diversity_is_respected_when_there_are_enough_series(): void {
		$this->series = array( 1 => 'a', 2 => 'a', 3 => 'b', 4 => 'c', 5 => 'd', 6 => 'e' );

		$elegidos = $this->elegir( array( 1, 2, 3, 4, 5, 6 ), 1, 4 );

		self::assertCount( 4, $elegidos );
		self::assertSame( array( 1, 3, 4, 5 ), $elegidos, 'Debe tomar el primero de cada serie distinta.' );
	}

	/**
	 * @testdox Con menos series que slots, rellena igual hasta completar la retícula.
	 */
	public function test_backfills_when_series_run_out(): void {
		// Tres series para cuatro slots: el caso de «Distrito Cómics».
		$this->series = array( 1 => 'a', 2 => 'a', 3 => 'b', 4 => 'c' );

		$elegidos = $this->elegir( array( 1, 2, 3, 4 ), 1, 4 );

		self::assertCount( 4, $elegidos, 'La retícula no puede quedar con un hueco por falta de series.' );
		self::assertSame( array( 1, 3, 4, 2 ), $elegidos, 'Primero uno por serie; el repetido va al final.' );
	}

	/**
	 * @testdox Nunca devuelve más de lo pedido ni repite un id.
	 */
	public function test_never_exceeds_count_nor_duplicates(): void {
		$this->series = array( 1 => 'a', 2 => 'a', 3 => 'a', 4 => 'a', 5 => 'a' );

		$elegidos = $this->elegir( array( 1, 2, 3, 4, 5 ), 1, 3 );

		self::assertCount( 3, $elegidos );
		self::assertSame( $elegidos, array_unique( $elegidos ), 'Un producto no puede salir dos veces en la misma retícula.' );
	}

	/**
	 * @testdox Si no hay candidatos suficientes, devuelve los que hay sin inventar.
	 */
	public function test_returns_what_exists_when_candidates_are_short(): void {
		$this->series = array( 1 => 'a', 2 => 'b' );

		self::assertSame( array( 1, 2 ), $this->elegir( array( 1, 2 ), 1, 8 ) );
	}
}
