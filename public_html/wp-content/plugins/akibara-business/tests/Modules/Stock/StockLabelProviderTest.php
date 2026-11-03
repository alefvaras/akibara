<?php
/**
 * Business PROVEE de verdad el vocabulario de stock del sitio.
 *
 * `akb_stock_status_label` se documenta en los DOS plugins como «el ÚNICO punto
 * de override» de las palabras Disponible / A pedido / Agotado, y no existía ni
 * un `add_filter`: eran dos mapas espejados a mano, en dos rieles de despliegue
 * distintos, que sólo coincidían mientras alguien se acordara de tocar los dos
 * (auditoría 2026-08-31). El buscador conserva su mapa local como fallback —es
 * self-contained a propósito— y business, que es el dueño del vocabulario, lo
 * responde.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Stock;

use Akibara\Business\Modules\Stock\SearchAvailabilityBridge;
use Akibara\Business\Modules\Stock\StockStatus;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class StockLabelProviderTest extends TestCase {

	/** @var array<int, array{hook: string, prioridad: int, args: int}> */
	private array $filtros = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->filtros = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $hook, $callback, int $prioridad = 10, int $args = 1 ): bool {
				$this->filtros[] = array(
					'hook'      => $hook,
					'prioridad' => $prioridad,
					'args'      => $args,
				);
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El filtro documentado tiene por fin un proveedor registrado.
	 */
	public function test_el_filtro_tiene_proveedor(): void {
		( new SearchAvailabilityBridge() )->register();

		$hooks = array_column( $this->filtros, 'hook' );

		self::assertContains( 'akb_stock_status_label', $hooks );
		self::assertContains( 'akb_search_availability_value', $hooks );
	}

	/**
	 * @testdox resolve(): preventa con lanzamiento pendiente, a pedido si no, y el default sin producto.
	 */
	public function test_resolve_distingue_preventa_de_a_pedido(): void {
		$futura           = gmdate( 'Y-m-d', time() + 400 * 86400 );
		$preventa         = new \WC_Product( 'onbackorder' );
		$preventa->akb_id = 7;
		$pedido           = new \WC_Product( 'onbackorder' );
		$pedido->akb_id   = 8;
		$productos        = array( 7 => $preventa, 8 => $pedido );

		Functions\when( 'wc_get_product' )->alias( static fn( int $id ) => $productos[ $id ] ?? false );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_post_meta' )->alias(
			static fn( int $id, string $key = '', bool $single = false ) => $GLOBALS['akibara_test_postmeta'][ $id ][ $key ] ?? ''
		);
		$GLOBALS['akibara_test_postmeta'] = array(
			7 => array( '_akb_eta_type' => 'release_date', '_akb_eta_release_date' => $futura ),
			8 => array( '_akb_eta_type' => 'auto' ),
		);

		$bridge = new SearchAvailabilityBridge();
		self::assertSame( 'preventa', $bridge->resolve( 'a_pedido', 7 ) );
		self::assertSame( 'a_pedido', $bridge->resolve( 'a_pedido', 8 ) );
		self::assertSame( 'lo-que-trajo', $bridge->resolve( 'lo-que-trajo', 99 ), 'Sin producto se devuelve el default de quien preguntó.' );
		self::assertSame( 'a_pedido', $bridge->resolve( '', 99 ), 'Un default vacío no vale: a pedido.' );

		unset( $GLOBALS['akibara_test_postmeta'] );
	}

	/**
	 * @testdox Responde la palabra de StockStatus::LABELS, no una copia.
	 *
	 * @dataProvider estados
	 */
	public function test_responde_el_vocabulario_del_dueno( string $status, string $esperado ): void {
		self::assertSame(
			$esperado,
			( new SearchAvailabilityBridge() )->provide_label( 'lo que trajera el llamador', $status )
		);
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function estados(): array {
		return array(
			'en bodega' => array( StockStatus::IN_STOCK, StockStatus::LABELS[ StockStatus::IN_STOCK ] ),
			'a pedido'  => array( StockStatus::ON_BACKORDER, StockStatus::LABELS[ StockStatus::ON_BACKORDER ] ),
			'agotado'   => array( StockStatus::OUT_OF_STOCK, StockStatus::LABELS[ StockStatus::OUT_OF_STOCK ] ),
		);
	}

	/**
	 * @testdox Un estado que business no conoce conserva el fail-closed de quien preguntó.
	 */
	public function test_un_estado_desconocido_no_pisa_el_default_ajeno(): void {
		self::assertSame(
			'Agotado según el buscador',
			( new SearchAvailabilityBridge() )->provide_label( 'Agotado según el buscador', 'estado_marciano' )
		);
	}
}
