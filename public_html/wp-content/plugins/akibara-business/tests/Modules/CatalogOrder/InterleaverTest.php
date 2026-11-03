<?php
/**
 * Candado del cálculo de la vitrina (clase pura, sin WordPress).
 *
 * El 2026-09-22 `/tienda/` abría en producción con 6 de 6 tomos «a pedido»
 * (llegada en 5-7 semanas) y 8 de 24 en la página 1, con 1.096 de 1.643
 * productos en bodega. El dueño decidió «disponibles primero», conservando el
 * intercalado de series. Estas pruebas fijan las dos vitrinas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\CatalogOrder;

use Akibara\Business\Modules\CatalogOrder\Interleaver;
use PHPUnit\Framework\TestCase;

final class InterleaverTest extends TestCase {

	/**
	 * Fila de entrada con la misma forma que arma `CatalogOrder::recompute()`.
	 *
	 * @return array{id:int, serie:string, date:string, sellable:bool, tier:int}
	 */
	private static function fila( int $id, string $serie, string $date, ?string $stock ): array {
		return array(
			'id'       => $id,
			'serie'    => $serie,
			'date'     => $date,
			'sellable' => 'outofstock' !== $stock,
			'tier'     => Interleaver::availability_tier( $stock ),
		);
	}

	/**
	 * La forma del catálogo medida en producción: las series con el tomo más
	 * nuevo son novedades «a pedido»; lo que está en bodega es algo anterior.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function catalogo(): array {
		return array(
			// Haikyuu: el 45 llega en semanas; el 44 y el 43 están en bodega.
			self::fila( 145, 'haikyuu', '2026-09-20 10:00:00', 'onbackorder' ),
			self::fila( 144, 'haikyuu', '2026-08-01 10:00:00', 'instock' ),
			self::fila( 143, 'haikyuu', '2026-07-01 10:00:00', 'instock' ),
			// Gantz: el 15 llega en semanas; el 14 está agotado.
			self::fila( 215, 'gantz', '2026-09-19 10:00:00', 'onbackorder' ),
			self::fila( 214, 'gantz', '2026-08-15 10:00:00', 'outofstock' ),
			// Beastars: todo en bodega.
			self::fila( 311, 'beastars', '2026-09-10 10:00:00', 'instock' ),
			self::fila( 310, 'beastars', '2026-06-10 10:00:00', 'instock' ),
			// Un producto suelto, sin serie, en bodega.
			self::fila( 900, 'p900', '2026-05-01 10:00:00', 'instock' ),
		);
	}

	/**
	 * Estados en el orden en que la vitrina los pinta.
	 *
	 * @param array<int, int>                   $orders product_id => menu_order.
	 * @param array<int, array<string, mixed>> $rows   Entrada.
	 */
	private static function secuencia( array $orders, array $rows ): string {
		$tier = array();
		foreach ( $rows as $row ) {
			$tier[ $row['id'] ] = $row['tier'];
		}
		asort( $orders );

		return implode( '', array_map( static fn( int $id ): string => array( 'i', 'b', 'o' )[ $tier[ $id ] ], array_keys( $orders ) ) );
	}

	/**
	 * @testdox Cada estado de WooCommerce cae en su grupo; lo desconocido es «disponible», como lo pinta WooCommerce
	 */
	public function test_availability_tier_follows_what_the_customer_sees(): void {
		self::assertSame( Interleaver::TIER_AVAILABLE, Interleaver::availability_tier( 'instock' ) );
		self::assertSame( Interleaver::TIER_ON_REQUEST, Interleaver::availability_tier( 'onbackorder' ) );
		self::assertSame( Interleaver::TIER_SOLD_OUT, Interleaver::availability_tier( 'outofstock' ) );
		// `WC_Product::set_stock_status()` convierte todo estado no válido en instock.
		self::assertSame( Interleaver::TIER_AVAILABLE, Interleaver::availability_tier( null ) );
		self::assertSame( Interleaver::TIER_AVAILABLE, Interleaver::availability_tier( '' ) );
		self::assertSame( Interleaver::TIER_AVAILABLE, Interleaver::availability_tier( 'raro' ) );
	}

	/**
	 * @testdox Disponibles primero: todo lo que está en bodega, después lo «a pedido», al final lo agotado
	 */
	public function test_available_first_orders_by_availability_group(): void {
		$rows = self::catalogo();

		self::assertSame( 'iiiiibbo', self::secuencia( Interleaver::compute_available_first( $rows ), $rows ) );
	}

	/**
	 * @testdox El intercalado de siempre abre con la novedad «a pedido»: es el defecto que midió la auditoría
	 */
	public function test_interleaved_still_opens_with_the_newest_series_whatever_its_stock(): void {
		$rows   = self::catalogo();
		$orders = Interleaver::compute( $rows );

		self::assertSame( 1, $orders[145], 'Haikyuu 45 «a pedido» es lo más nuevo: el intercalado lo pone primero.' );
		// Segunda vuelta del round-robin: el agotado de Gantz cae en el puesto 6.
		self::assertSame( 'bbiiioii', self::secuencia( $orders, $rows ) );
	}

	/**
	 * @testdox Dentro de cada grupo se conserva el intercalado de series, con la serie más reciente del grupo arriba
	 */
	public function test_series_are_still_interleaved_inside_each_group(): void {
		$orders = Interleaver::compute_available_first( self::catalogo() );
		asort( $orders );

		self::assertSame(
			array( 311, 144, 900, 310, 143, 145, 215, 214 ),
			array_keys( $orders ),
			'Disponibles: Beastars (10-sep) → Haikyuu (1-ago) → suelto, y la segunda vuelta; luego Haikyuu 45 y Gantz 15; luego el agotado.'
		);
	}

	/**
	 * @testdox Las posiciones son 1..N sin huecos ni repetidos, aunque falte un grupo
	 */
	public function test_positions_are_contiguous(): void {
		$solo_bodega_y_agotado = array_values(
			array_filter( self::catalogo(), static fn( array $row ): bool => Interleaver::TIER_ON_REQUEST !== $row['tier'] )
		);

		foreach ( array( self::catalogo(), $solo_bodega_y_agotado ) as $rows ) {
			$orders = Interleaver::compute_available_first( $rows );
			$valores = array_values( $orders );
			sort( $valores );

			self::assertSame( range( 1, count( $rows ) ), $valores );
		}
	}

	/**
	 * @testdox Mismo catálogo, mismo orden: el recálculo diario no reescribe nada si nada cambió
	 */
	public function test_is_deterministic_under_input_order(): void {
		$rows      = self::catalogo();
		$invertido = array_reverse( $rows );

		self::assertSame( Interleaver::compute_available_first( $rows ), Interleaver::compute_available_first( $invertido ) );
	}

	/**
	 * @testdox Sin filas no hay vitrina
	 */
	public function test_empty_catalog(): void {
		self::assertSame( array(), Interleaver::compute_available_first( array() ) );
	}
}
