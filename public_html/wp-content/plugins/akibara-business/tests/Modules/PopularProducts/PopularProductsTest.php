<?php
/**
 * Los cross-sells de 404, carrito vacío y «sin resultados».
 *
 * Tres defectos que arrastraba la lista (auditoría 2026-08-31):
 *
 *   1. Filtraba por `instock` ESTRICTO, y eso dejaba fuera los 422 productos
 *      «A pedido» (`onbackorder`) — el 27 % del catálogo, que se compra igual.
 *   2. Cacheaba una hora el resultado VACÍO, así que un import a medias dejaba
 *      las tres superficies sin cross-sells durante 60 minutos.
 *   3. No la invalidaba NADIE: agotar el best-seller dejaba su tarjeta viva
 *      hasta que el transient caducaba solo, y borrarlo en firme dejaba una
 *      tarjeta enlazando a la nada.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PopularProducts;

use Akibara\Business\Modules\PopularProducts\PopularProducts;
use Akibara\Business\Modules\Stock\StockStatus;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PopularProductsTest extends TestCase {

	/** @var array<string, array{valor: mixed, ttl: int}> */
	private array $transients = array();

	/** @var array<int, string> Claves borradas. */
	private array $borrados = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients = array();
		$this->borrados   = array();

		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ]['valor'] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $valor, int $ttl ): bool {
				$this->transients[ $key ] = array(
					'valor' => $valor,
					'ttl'   => $ttl,
				);
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				$this->borrados[] = $key;
				unset( $this->transients[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox La consulta incluye los «A pedido»: son comprables, no decoración.
	 */
	public function test_incluye_los_productos_a_pedido(): void {
		$meta_query = PopularProducts::query_args( 4 )['meta_query'][0];

		self::assertSame( '_stock_status', $meta_query['key'] );
		self::assertSame( 'IN', $meta_query['compare'] );
		self::assertSame(
			array( StockStatus::IN_STOCK, StockStatus::ON_BACKORDER ),
			$meta_query['value'],
			'Sólo `outofstock` queda fuera: es el único que no se puede comprar.'
		);
	}

	/**
	 * @testdox Una lista vacía se cachea corto, no una hora.
	 */
	public function test_el_vacio_no_se_cachea_una_hora(): void {
		( new PopularProducts() )->get_ids( array(), 4 );

		$guardado = reset( $this->transients );
		self::assertSame( array(), $guardado['valor'] );
		self::assertLessThan(
			HOUR_IN_SECONDS,
			$guardado['ttl'],
			'La lista vacía casi siempre es pasajera: retenerla una hora deja tres superficies sin cross-sells.'
		);
	}

	/**
	 * @testdox Una lista ya cacheada se sirve tal cual, sin volver a consultar.
	 */
	public function test_la_lista_cacheada_se_sirve_tal_cual(): void {
		$this->transients['akb_popular_cross_sell_ids_v4_una_por_serie_4'] = array(
			'valor' => array( 7, 8 ),
			'ttl'   => HOUR_IN_SECONDS,
		);

		self::assertSame( array( 7, 8 ), ( new PopularProducts() )->get_ids( array(), 4 ) );
	}

	/**
	 * @testdox `flush()` tira la lista de los tamaños que las superficies piden.
	 */
	public function test_flush_borra_los_tamanos_servidos(): void {
		( new PopularProducts() )->flush();

		// 4 = 404 y carrito vacío; 6 = «sin resultados». Los dos modos: la
		// lista del modo que no está puesto también puede estar cacheada.
		self::assertContains( 'akb_popular_cross_sell_ids_v4_una_por_serie_4', $this->borrados );
		self::assertContains( 'akb_popular_cross_sell_ids_v4_una_por_serie_6', $this->borrados );
		self::assertContains( 'akb_popular_cross_sell_ids_v4_tal_cual_4', $this->borrados );
	}

	/**
	 * @testdox Borrar un post que no es producto no tira la caché.
	 */
	public function test_el_borrado_de_otro_tipo_no_invalida(): void {
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		( new PopularProducts() )->flush_for_deleted_post( 999 );

		self::assertSame( array(), $this->borrados );
	}

	/**
	 * @testdox Borrar un producto en firme sí la tira: si no, queda una tarjeta a la nada.
	 */
	public function test_el_borrado_de_un_producto_invalida(): void {
		Functions\when( 'get_post_type' )->justReturn( 'product' );

		( new PopularProducts() )->flush_for_deleted_post( 999 );

		self::assertContains( 'akb_popular_cross_sell_ids_v4_una_por_serie_4', $this->borrados );
	}

	/**
	 * @testdox Una tarjeta por serie: de JJK 25, JJK 24, HxH 33, JJK 19… salen cuatro series distintas (UC-13).
	 */
	public function test_una_tarjeta_por_serie(): void {
		$pedidos = array();
		\WP_Query::$akb_resolver = static function ( array $args ) use ( &$pedidos ): array {
			$pedidos[] = $args['posts_per_page'];
			return array( 25, 24, 33, 19, 1, 50 );
		};
		$series = array( 25 => 7, 24 => 7, 33 => 8, 19 => 7, 1 => 9, 50 => 10 );
		// Posts, meta y términos de las candidatas en UN lote (revisión B2-2-R1):
		// con sólo los términos cebados, `get_the_terms()` pedía cada post y su
		// meta por separado. Y ningún `get_post()` suelto por candidata.
		Functions\expect( '_prime_post_caches' )->once()->with( array( 25, 24, 33, 19, 1, 50 ), true, true );
		Functions\expect( 'get_post' )->never();
		Functions\when( 'get_the_terms' )->alias(
			static fn( $id, string $taxonomy ) => 'pa_serie' === $taxonomy && isset( $series[ (int) $id ] )
				? array( new \WP_Term( $series[ (int) $id ], 's', 'S' ) )
				: false
		);

		try {
			$ids = ( new PopularProducts() )->get_ids( array(), 4 );
		} finally {
			\WP_Query::$akb_resolver = null;
		}

		self::assertSame( array( 25, 33, 1, 50 ), $ids );
		self::assertSame( array( 16 ), $pedidos, 'Se piden 4 por tarjeta para tener series de sobra.' );
		self::assertSame( array( 25, 33, 1, 50 ), $this->transients['akb_popular_cross_sell_ids_v4_una_por_serie_4']['valor'] );
	}

	/**
	 * @testdox «Tal cual» en el panel: los más vendidos sin tocar y la consulta de siempre.
	 */
	public function test_tal_cual(): void {
		$GLOBALS['akibara_test_options'] = array( 'akb_reco_mas_vendidos' => 'tal_cual' );
		$pedidos                         = array();
		\WP_Query::$akb_resolver        = static function ( array $args ) use ( &$pedidos ): array {
			$pedidos[] = $args['posts_per_page'];
			return array( 25, 24, 33, 19 );
		};

		try {
			$ids = ( new PopularProducts() )->get_ids( array(), 4 );
		} finally {
			\WP_Query::$akb_resolver = null;
			unset( $GLOBALS['akibara_test_options'] );
		}

		self::assertSame( array( 25, 24, 33, 19 ), $ids );
		self::assertSame( array( 4 ), $pedidos );
	}
}
