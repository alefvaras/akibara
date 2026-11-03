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
		self::assertSame( 'A pedido', StockLabels::label( 'onbackorder' ) );
		self::assertSame( 'Agotado', StockLabels::label( 'outofstock' ) );

		// Fail-closed: un estado desconocido nunca promete disponibilidad.
		self::assertSame( 'Agotado', StockLabels::label( 'lo-que-sea' ) );

		// El estado comercial, sin business enganchado: todo reservable es «a pedido».
		self::assertSame(
			array( 'value' => 'a_pedido', 'label' => 'A pedido', 'tone' => 'order' ),
			StockLabels::availability( 'onbackorder', 7 )
		);
		self::assertSame(
			array( 'value' => 'instock', 'label' => 'Disponible', 'tone' => 'ok' ),
			StockLabels::availability( 'instock', 7 )
		);
		self::assertSame( 'outofstock', StockLabels::availability( 'lo-que-sea', 7 )['value'], 'Fail-closed: un estado desconocido nunca promete disponibilidad.' );
	}

	/** @testdox Con business diciendo «preventa» por ficha, el badge y la faceta lo dicen. */
	public function test_la_preventa_real_llega_por_el_filtro_de_ficha(): void {
		Filters\expectApplied( 'akb_search_availability_value' )
			->andReturnUsing( static fn( string $default, int $post_id ): string => 7 === $post_id ? 'preventa' : $default );

		self::assertSame(
			array( 'value' => 'preventa', 'label' => 'Preventa', 'tone' => 'pre' ),
			StockLabels::availability( 'onbackorder', 7 ),
			'El autocomplete rotulaba «A pedido» una preventa que la faceta del mismo índice ya distinguía.'
		);
		self::assertSame( 'a_pedido', StockLabels::availability( 'onbackorder', 8 )['value'] );
	}

	/** @testdox La palabra de «preventa» también sale del vocabulario compartido. */
	public function test_la_palabra_de_preventa_pasa_por_el_hook(): void {
		Filters\expectApplied( 'akb_search_availability_value' )->andReturn( 'preventa' );
		Filters\expectApplied( 'akb_stock_status_label' )
			->andReturnUsing(
				static fn( string $default, string $status ): string => 'preventa' === $status ? 'Reserva anticipada' : $default
			);

		self::assertSame(
			array( 'value' => 'preventa', 'label' => 'Reserva anticipada', 'tone' => 'pre' ),
			StockLabels::availability( 'onbackorder', 7 ),
			'Era el último literal: el dueño renombraba la palabra y el badge del buscador seguía diciendo «Preventa».'
		);
	}

	/** @testdox Sin proveedor, «preventa» conserva su palabra de fábrica: los dos rieles pueden ir por separado. */
	public function test_sin_proveedor_la_preventa_sigue_diciendo_preventa(): void {
		Filters\expectApplied( 'akb_search_availability_value' )->andReturn( 'preventa' );

		self::assertSame( 'Preventa', StockLabels::availability( 'onbackorder', 7 )['label'] );
	}

	/** @testdox Basura del filtro degrada a «a pedido», nunca a «preventa». */
	public function test_basura_del_filtro_degrada_a_a_pedido(): void {
		Filters\expectApplied( 'akb_search_availability_value' )->andReturn( 'PREVENTA ' );

		self::assertSame( 'a_pedido', StockLabels::availability( 'onbackorder', 7 )['value'] );
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
