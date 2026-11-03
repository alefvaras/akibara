<?php
/**
 * El criterio de las recomendaciones (UC-6, UC-7 y UC-13 de la auditoría del
 * tema, 2026-09-22), sin WordPress: registros ya resueltos entran, ids salen.
 *
 * Los casos son los medidos en producción ese día:
 *  - relacionados de Jujutsu Kaisen 25: Dan Da Dan 05, Bleach Remix 03, Black
 *    Clover 02 y Black Clover 01 (serie repetida, un solo tomo 01);
 *  - upsells de la misma ficha: 28, 26, 27, los tres agotados;
 *  - más vendidos del carrito vacío: JJK 25, JJK 24, HxH 33, JJK 19.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Recommendations;

use Akibara\Business\Modules\Recommendations\Picker;
use Akibara\Business\Modules\Recommendations\Settings;
use PHPUnit\Framework\TestCase;

final class PickerTest extends TestCase {

	private const JJK   = 10;
	private const DDD   = 20;
	private const BLEA  = 30;
	private const BC    = 40;
	private const KING  = 50;

	/**
	 * @param int|null $serie  Serie.
	 * @param int|null $numero Tomo.
	 * @return array{id:int, serie:int|null, numero:int|null, comprable:bool}
	 */
	private static function candidato( int $id, ?int $serie, ?int $numero, bool $comprable = true ): array {
		return array(
			'id'        => $id,
			'serie'     => $serie,
			'numero'    => $numero,
			'comprable' => $comprable,
		);
	}

	/**
	 * @return array{id:int, numero:int|null, comprable:bool}
	 */
	private static function tomo( int $id, ?int $numero, bool $comprable = true ): array {
		return array(
			'id'        => $id,
			'numero'    => $numero,
			'comprable' => $comprable,
		);
	}

	/**
	 * @testdox Relacionados: una serie por hueco y cada una con su tomo 01, aunque el pool traiga el 05.
	 */
	public function test_relacionados_una_serie_por_hueco_desde_el_tomo_uno(): void {
		$pool = array(
			self::candidato( 2005, self::DDD, 5 ),
			self::candidato( 3003, self::BLEA, 3 ),
			self::candidato( 4002, self::BC, 2 ),
			self::candidato( 4001, self::BC, 1 ),
		);
		$estanterias = array(
			self::DDD  => array( self::tomo( 2001, 1 ), self::tomo( 2002, 2 ), self::tomo( 2003, 3 ) ),
			self::BLEA => array( self::tomo( 3001, 1 ), self::tomo( 3002, 2 ), self::tomo( 3003, 3 ) ),
			self::BC   => array( self::tomo( 4001, 1 ), self::tomo( 4002, 2 ), self::tomo( 4003, 3 ) ),
		);

		$ids = Picker::related( $pool, array( self::JJK ), $estanterias );

		self::assertSame( array( 2001, 3001, 4001 ), array_slice( $ids, 0, 3 ), 'Tres series, tres tomos 01, en el orden del pool.' );
		self::assertSame( array( 2005, 3003, 4002 ), array_slice( $ids, 3 ), 'Los tomos de la mitad quedan de relleno, detrás.' );
	}

	/**
	 * @testdox Relacionados: si el 01 está agotado, entra el más bajo que se pueda comprar, detrás de las series que sí abren.
	 */
	public function test_relacionados_entrada_con_el_primero_agotado(): void {
		$pool = array(
			self::candidato( 4005, self::BC, 5 ),
			self::candidato( 5009, self::KING, 9 ),
		);
		$estanterias = array(
			self::BC   => array( self::tomo( 4001, 1, false ), self::tomo( 4002, 2 ), self::tomo( 4003, 3 ) ),
			self::KING => array( self::tomo( 5001, 1 ), self::tomo( 5002, 2 ) ),
		);

		$ids = Picker::related( $pool, array(), $estanterias );

		self::assertSame( array( 5001, 4002 ), array_slice( $ids, 0, 2 ), 'Kingdom abre con su 01; Black Clover entra con el 02 y va después.' );
	}

	/**
	 * @testdox Relacionados: si ninguno de los primeros tomos se puede comprar, entra el comprable más bajo del pool.
	 */
	public function test_relacionados_sin_estanteria_comprable_usa_el_pool(): void {
		$pool = array(
			self::candidato( 4009, self::BC, 9 ),
			self::candidato( 4007, self::BC, 7 ),
			self::candidato( 4008, self::BC, 8, false ),
		);
		$estanterias = array(
			self::BC => array( self::tomo( 4001, 1, false ), self::tomo( 4002, 2, false ), self::tomo( 4003, 3, false ) ),
		);

		$ids = Picker::related( $pool, array(), $estanterias );

		self::assertSame( 4007, $ids[0] );
		self::assertSame( array( 4007, 4009, 4008 ), $ids, 'El 9 queda de relleno comprable; el 8 agotado, al final.' );
	}

	/**
	 * @testdox Relacionados: la serie de la ficha no ocupa huecos mientras haya otras series.
	 */
	public function test_relacionados_no_repite_la_serie_de_la_ficha(): void {
		$pool = array(
			self::candidato( 1026, self::JJK, 26 ),
			self::candidato( 2001, self::DDD, 1 ),
		);
		$estanterias = array( self::DDD => array( self::tomo( 2001, 1 ) ) );

		self::assertSame( array( 2001, 1026 ), Picker::related( $pool, array( self::JJK ), $estanterias ) );
	}

	/**
	 * @testdox Relacionados: un producto sin serie es su propia entrada; nada excluido sale.
	 */
	public function test_relacionados_sin_serie_y_excluidos(): void {
		$pool = array(
			self::candidato( 9001, null, null ),
			self::candidato( 9002, null, null, false ),
			self::candidato( 2003, self::DDD, 3 ),
		);
		$estanterias = array( self::DDD => array( self::tomo( 2001, 1 ), self::tomo( 2002, 2 ) ) );

		$ids = Picker::related( $pool, array(), $estanterias, array( 2001 ) );

		self::assertSame( array( 9001, 2003, 9002 ), $ids, 'El 01 excluido (es upsell de la ficha) no sale; su serie sigue con el tomo del pool.' );
	}

	/**
	 * @testdox Relacionados: una serie sin tomos numerados abre con cualquiera de sus productos.
	 */
	public function test_relacionados_serie_sin_numeros_abre(): void {
		$pool = array(
			self::candidato( 5003, self::KING, 3 ),
			self::candidato( 7001, 70, null ),
		);
		$estanterias = array(
			self::KING => array( self::tomo( 5001, 1, false ), self::tomo( 5002, 2 ) ),
			70         => array( self::tomo( 7001, null ) ),
		);

		self::assertSame( array( 7001, 5002, 5003 ), Picker::related( $pool, array(), $estanterias ) );
	}

	/**
	 * @testdox Upsells: orden de lectura y los comprables primero.
	 */
	public function test_upsells_orden_de_lectura_comprables_primero(): void {
		$guardados = array(
			array( 'id' => 7, 'numero' => 7, 'comprable' => true ),
			array( 'id' => 5, 'numero' => 5, 'comprable' => true ),
			array( 'id' => 6, 'numero' => 6, 'comprable' => false ),
		);

		self::assertSame( array( 5, 7, 6 ), Picker::upsells( $guardados, array( 99 ), Settings::AGOTADA_COMPLETAR ), 'Con algo comprable no se completa nada.' );
	}

	/**
	 * @testdox Upsells todos agotados: «mostrar» los ordena, «ocultar» no pinta nada, «completar» pone los siguientes comprables.
	 */
	public function test_upsells_todos_agotados_segun_el_modo(): void {
		$guardados = array(
			array( 'id' => 28, 'numero' => 28, 'comprable' => false ),
			array( 'id' => 26, 'numero' => 26, 'comprable' => false ),
			array( 'id' => 27, 'numero' => 27, 'comprable' => false ),
		);

		self::assertSame( array( 26, 27, 28 ), Picker::upsells( $guardados, array(), Settings::AGOTADA_MOSTRAR ) );
		self::assertSame( array(), Picker::upsells( $guardados, array(), Settings::AGOTADA_OCULTAR ) );
		self::assertSame( array( 31, 32, 26 ), Picker::upsells( $guardados, array( 31, 32 ), Settings::AGOTADA_COMPLETAR ), 'Los comprables delante, completados con los agotados hasta el mismo número de huecos.' );
		self::assertSame( array( 26, 27, 28 ), Picker::upsells( $guardados, array(), Settings::AGOTADA_COMPLETAR ), 'Sin siguientes comprables se muestran los agotados.' );
	}

	/**
	 * @testdox Upsells: un especial sin número va al final, no delante del tomo 1.
	 */
	public function test_upsells_especial_al_final(): void {
		$guardados = array(
			array( 'id' => 50, 'numero' => null, 'comprable' => true ),
			array( 'id' => 2, 'numero' => 2, 'comprable' => true ),
		);

		self::assertSame( array( 2, 50 ), Picker::upsells( $guardados, array(), Settings::AGOTADA_MOSTRAR ) );
	}

	/**
	 * @testdox Más vendidos: una tarjeta por serie; si faltan series, se completa con los saltados.
	 */
	public function test_una_por_serie(): void {
		$ids    = array( 25, 24, 33, 19, 1 );
		$series = array( 25 => self::JJK, 24 => self::JJK, 33 => 80, 19 => self::JJK, 1 => 90 );

		self::assertSame( array( 25, 33, 1, 24 ), Picker::una_por_serie( $ids, $series, 4 ) );
		self::assertSame( array( 25, 33 ), Picker::una_por_serie( $ids, $series, 2 ) );
		self::assertSame( array( 7, 8 ), Picker::una_por_serie( array( 7, 8 ), array(), 4 ), 'Sin serie, cada uno cuenta como distinto.' );
	}
}
