<?php
/**
 * El filtro «Solo preventa pendiente» de la lista de pedidos tiene que ver los
 * pedidos SIN la meta de «listo», igual que ya lo hacen los crons.
 *
 * `apply_filter()` pedía el literal `'no'` para `ORDER_READY_TO_SHIP` y dejaba
 * fuera a un pedido con líneas en espera que nunca pasó por
 * `OrderTagging::apply_tags()` (importado, creado a mano en el admin, alta por
 * API): sin la meta, no matchea `'no'` y desaparece del filtro sin dejar
 * rastro. La misma corrección se aplicó el 2026-09-02 a `RemindersCron` y a
 * `ReleaseDateChangeListener` (hallazgo S-2d3917fe); este consumidor se quedó
 * atrás (hallazgo S-a4692d62, 2026-09-19).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\AdminFilter;
use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FiltroPendientePreventaVeLosPedidosSinLaMetaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		unset( $_GET[ AdminFilter::QUERY_VAR ] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Los argumentos REALES que produce el filtro «pending» sobre un
	 * `meta_query` vacío, como llega desde el listado de pedidos.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function meta_query_de( string $filtro ): array {
		$_GET[ AdminFilter::QUERY_VAR ] = $filtro;

		return ( new AdminFilter() )->apply_filter( array() )['meta_query'];
	}

	/** @testdox La ausencia de la meta de «listo» es candidata, igual que «no». */
	public function test_la_ausencia_de_la_meta_es_candidata(): void {
		$mq = self::meta_query_de( 'pending' );

		$rama = null;
		foreach ( $mq as $clave => $clausula ) {
			if ( 'relation' !== $clave && is_array( $clausula ) && 'OR' === ( $clausula['relation'] ?? '' ) ) {
				$rama = $clausula;
			}
		}

		self::assertIsArray( $rama, 'La condición sobre «listo» admite dos formas y por eso es un OR.' );

		$formas = array();
		foreach ( $rama as $clave => $clausula ) {
			if ( 'relation' === $clave || ! is_array( $clausula ) ) {
				continue;
			}
			$formas[] = ( $clausula['key'] ?? '' ) . ':' . ( $clausula['compare'] ?? '' );
		}
		sort( $formas );

		self::assertSame(
			array(
				MetaKeys::ORDER_READY_TO_SHIP . ':!=',
				MetaKeys::ORDER_READY_TO_SHIP . ':NOT EXISTS',
			),
			$formas
		);
	}

	/**
	 * El filtro «ready» sigue exigiendo el literal 'yes': un pedido sin la meta
	 * nunca se flippeó y no es «preventa lista» — este caso no tiene el defecto
	 * del «pending», y el candado no debe reescribirlo por error.
	 *
	 * @testdox El filtro «ready» conserva el literal 'yes'.
	 */
	public function test_el_filtro_ready_conserva_el_literal_yes(): void {
		$mq = self::meta_query_de( 'ready' );

		$listo = false;
		foreach ( $mq as $clave => $clausula ) {
			if ( 'relation' === $clave || ! is_array( $clausula ) ) {
				continue;
			}
			if ( MetaKeys::ORDER_READY_TO_SHIP === ( $clausula['key'] ?? '' ) && 'yes' === ( $clausula['value'] ?? '' ) ) {
				$listo = true;
			}
		}

		self::assertTrue( $listo );
	}

	/**
	 * Sin este filtro, «pending» arrastraría cualquier pedido sin preventa que
	 * tampoco tenga la meta de «listo»: el arreglo de una invisibilidad no
	 * puede crear otra.
	 *
	 * @testdox El filtro sigue pidiendo SÓLO pedidos de preventa.
	 */
	public function test_sigue_pidiendo_solo_pedidos_de_preventa(): void {
		$mq = self::meta_query_de( 'pending' );

		$preventa = false;
		foreach ( $mq as $clave => $clausula ) {
			if ( 'relation' === $clave || ! is_array( $clausula ) ) {
				continue;
			}
			if ( MetaKeys::ORDER_HAS_PREVENTA === ( $clausula['key'] ?? '' ) && 'yes' === ( $clausula['value'] ?? '' ) ) {
				$preventa = true;
			}
		}

		self::assertTrue( $preventa );
	}
}
