<?php
/**
 * Contrato cruzado del vocabulario de stock (`akb_stock_status_label`).
 *
 * Los dos plugins documentaban este hook como «el ÚNICO punto de override» y
 * ninguno lo registraba: eran dos mapas espejados a mano en dos rieles de
 * despliegue distintos. Este test sella el papel del BUSCADOR —consumidor con
 * fallback local— para que la mitad de business (registrar el proveedor) se
 * pueda desplegar por separado sin partir el vocabulario en el intervalo.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\StockLabels;
use Brain\Monkey;
use Brain\Monkey\Filters;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

require_once dirname( __DIR__ ) . '/src/StockLabels.php';

final class StockLabelContractTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Sin proveedor registrado el buscador etiqueta con su fallback local. */
	public function test_sin_proveedor_gana_el_fallback_local(): void {
		self::assertSame( 'Disponible', StockLabels::label( 'instock' ) );
		self::assertSame( 'Agotado', StockLabels::label( 'outofstock' ) );

		// Fail-closed: un estado desconocido (incluyendo onbackorder legado) nunca promete disponibilidad.
		self::assertSame( 'Agotado', StockLabels::label( 'onbackorder' ) );
		self::assertSame( 'Agotado', StockLabels::label( 'lo-que-sea' ) );

		// El estado comercial solo opera en instock y outofstock.
		self::assertSame(
			array( 'value' => 'instock', 'label' => 'Disponible', 'tone' => 'ok' ),
			StockLabels::availability( 'instock', 7 )
		);
		self::assertSame(
			array( 'value' => 'outofstock', 'label' => 'Agotado', 'tone' => 'out' ),
			StockLabels::availability( 'outofstock', 7 )
		);
		self::assertSame(
			array( 'value' => 'outofstock', 'label' => 'Agotado', 'tone' => 'out' ),
			StockLabels::availability( 'onbackorder', 7 ),
			'Fail-closed: onbackorder degrada estrictamente a outofstock.'
		);
		self::assertSame( 'outofstock', StockLabels::availability( 'lo-que-sea', 7 )['value'], 'Fail-closed: un estado desconocido nunca promete disponibilidad.' );
	}

	/** @testdox Con proveedor registrado, la palabra del proveedor gana. */
	public function test_el_proveedor_registrado_gana(): void {
		Filters\expectApplied( 'akb_stock_status_label' )
			->andReturnUsing( static fn( string $default, string $status ): string => 'instock' === $status ? 'En stock' : $default );

		self::assertSame( 'En stock', StockLabels::label( 'instock' ) );
		self::assertSame( 'Agotado', StockLabels::label( 'outofstock' ) );
	}

	/**
	 * @testdox Un proveedor que devuelve basura no llega a la tarjeta.
	 *
	 * La palabra viaja al HTML del autocomplete: aceptar cualquier cosa que
	 * devuelva un hook de otro riel sería confiar el copy de la tienda a un
	 * plugin que puede estar a medio desplegar.
	 */
	public function test_un_proveedor_invalido_falla_cerrado(): void {
		foreach ( array( '', '   ', str_repeat( 'x', 101 ), "roto\x00" ) as $invalid ) {
			Monkey\tearDown();
			Monkey\setUp();
			Filters\expectApplied( 'akb_stock_status_label' )->andReturn( $invalid );

			try {
				StockLabels::label( 'instock' );
				self::fail( 'Un valor inválido del proveedor tiene que abortar, no pintarse.' );
			} catch ( UnexpectedValueException $expected ) {
				self::assertStringContainsString( 'Stock label provider', $expected->getMessage() );
			}
		}
	}
}
