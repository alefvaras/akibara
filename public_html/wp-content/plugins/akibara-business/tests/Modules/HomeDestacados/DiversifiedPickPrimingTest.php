<?php
/**
 * El cebado de la vitrina: POSTS además de términos.
 *
 * El comentario prometía cero consultas y no las evitaba. `get_the_terms()`
 * empieza por `get_post( $id )`, y la consulta que alimenta la vitrina pide
 * `fields => 'ids'`: ningún post está en caché, así que eran hasta 100
 * `SELECT * FROM wp_posts WHERE ID = %d` por render — justo debajo del
 * comentario que decía que el cebado de términos ya lo resolvía (auditoría
 * 2026-08-31).
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

final class DiversifiedPickPrimingTest extends TestCase {

	/** @var array<int, array{ids: array<int, int>, terminos: bool, meta: bool}> */
	private array $cebados = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->cebados = array();

		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( '_prime_post_caches' )->alias(
			function ( array $ids, bool $terminos = true, bool $meta = true ): void {
				$this->cebados[] = array(
					'ids'      => $ids,
					'terminos' => $terminos,
					'meta'     => $meta,
				);
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Ceba los posts de TODOS los candidatos en una sola llamada.
	 */
	public function test_ceba_los_posts_de_los_candidatos(): void {
		$metodo = new ReflectionMethod( HomeDestacados::class, 'pick_diversified_ids' );
		$metodo->setAccessible( true );
		$metodo->invoke( new HomeDestacados(), array( 1, 2, 3, 4 ), 1, 2 );

		self::assertCount( 1, $this->cebados, 'Un solo cebado, no uno por producto.' );
		self::assertSame( array( 1, 2, 3, 4 ), $this->cebados[0]['ids'] );
		self::assertTrue( $this->cebados[0]['terminos'], 'Los términos siguen cebándose: es lo que hacía el llamado suelto.' );
		self::assertFalse( $this->cebados[0]['meta'], 'La vitrina no lee postmeta: cebarlo sería trabajo regalado.' );
	}
}
