<?php
/**
 * Elegir «Relevancia» tiene que dejar mandando al buscador.
 *
 * Hallazgo S-e29148ab: la primera opción del selector se llama «Relevancia»
 * cuando hay búsqueda, pero su valor técnico es `menu_order`. Al elegirla, el
 * formulario manda `orderby=menu_order`, esto se leía como «el cliente pidió
 * otro orden», el scorer se apagaba y la lista se reordenaba por la vitrina —
 * con la etiqueta cambiando sola a «Destacados».
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\FacetQueryIntegration;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RelevanciaNoSeVuelveDestacadosTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ): string => trim( (string) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function eligio_orden(): bool {
		$m = new \ReflectionMethod( FacetQueryIntegration::class, 'user_chose_orderby' );
		$m->setAccessible( true );
		$objeto = ( new \ReflectionClass( FacetQueryIntegration::class ) )->newInstanceWithoutConstructor();

		return (bool) $m->invoke( $objeto );
	}

	/** @testdox Sin orderby en la URL manda el buscador. */
	public function test_sin_orderby_manda_el_buscador(): void {
		self::assertFalse( $this->eligio_orden() );
	}

	/** @testdox «Relevancia» (menu_order) también deja mandando al buscador. */
	public function test_relevancia_deja_mandar_al_buscador(): void {
		$_GET['orderby'] = 'menu_order';

		self::assertFalse( $this->eligio_orden(), 'Era el defecto: se leía como orden elegido y apagaba el scorer.' );
	}

	/**
	 * @testdox Un orden de verdad elegido por el cliente sí pisa la relevancia.
	 * @dataProvider ordenes
	 */
	public function test_un_orden_elegido_pisa_la_relevancia( string $orden ): void {
		$_GET['orderby'] = $orden;

		self::assertTrue( $this->eligio_orden() );
	}

	/** @return array<int, array{0:string}> */
	public static function ordenes(): array {
		return array( array( 'price' ), array( 'price-desc' ), array( 'title' ), array( 'date' ), array( 'popularity' ) );
	}

	/** @testdox Un orderby vacío o con espacios no cuenta como elección. */
	public function test_vacio_no_cuenta(): void {
		$_GET['orderby'] = '   ';

		self::assertFalse( $this->eligio_orden() );
	}
}
