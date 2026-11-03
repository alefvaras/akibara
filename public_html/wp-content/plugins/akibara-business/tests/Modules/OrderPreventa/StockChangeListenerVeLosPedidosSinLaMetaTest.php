<?php
/**
 * Un pedido de preventa SIN la meta de «listo» tiene que entrar al recompute
 * inmediato que dispara este listener al reponerse el stock.
 *
 * El guard de `process_order()` sólo rechaza el literal `'yes'`, así que la
 * ausencia de la meta es un candidato legítimo. La consulta exigía
 * `meta_value='no'` y lo dejaba fuera: un pedido nacido sin pasar por
 * `OrderTagging::apply_tags()` —importación, alta por API, creado a mano en
 * el admin, migración— no se marcaba listo nunca y su cliente esperaba hasta
 * 24h por el cron diario en vez del recompute inmediato que este listener
 * existe para dar. La misma corrección se aplicó el 2026-09-02 a
 * `ReadyToShipCron` y `RemindersCron`, y el 2026-09-19 a `AdminFilter`; este
 * cuarto consumidor de la misma meta se quedó atrás (S-2d3917fe, R2-dc4972fb).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\ReadyToShipCron;
use Akibara\Business\Modules\OrderPreventa\StockChangeListener;
use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;
use Akibara\Business\Tests\Support\NullEtaProvider;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class StockChangeListenerVeLosPedidosSinLaMetaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Los estados operativos salen de un filtro (`apply_filters`): Brain
		// Monkey lo deja pasar con su valor de fábrica, que es lo que se mide.
		Monkey\setUp();
		Monkey\Functions\when( 'sanitize_key' )->alias(
			static fn( $v ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Los argumentos REALES con los que el listener pide sus candidatos.
	 *
	 * @return array<string,mixed>
	 */
	private static function args(): array {
		$listener = new StockChangeListener( new ReadyToShipCron( new SummaryRecalculator( new NullEtaProvider() ) ) );
		$metodo   = new ReflectionMethod( StockChangeListener::class, 'candidate_order_query_args' );
		$metodo->setAccessible( true );

		return (array) $metodo->invoke( $listener );
	}

	/** @testdox La consulta ya no exige el literal «no». */
	public function test_the_query_no_longer_demands_the_literal_no(): void {
		$args = self::args();

		self::assertArrayNotHasKey( 'meta_value', $args, 'Un pedido sin la meta quedaba invisible.' );
		self::assertArrayNotHasKey( 'meta_key', $args );
		self::assertArrayHasKey( 'meta_query', $args );
	}

	/** @testdox La ausencia de la meta de «listo» es candidata, igual que «no». */
	public function test_a_missing_ready_meta_is_a_candidate(): void {
		$mq = self::args()['meta_query'];

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
	 * Sin este filtro, cada restock arrastraría todos los pedidos activos de
	 * la tienda y los pocos candidatos reales competirían con ellos por el
	 * tamaño del lote: el arreglo de una invisibilidad no puede crear otra.
	 * Este filtro no existía en absoluto antes del arreglo.
	 *
	 * @testdox La consulta pide SÓLO pedidos de preventa.
	 */
	public function test_the_query_asks_only_for_preorders(): void {
		$mq = self::args()['meta_query'];

		self::assertSame( 'AND', $mq['relation'] ?? '' );

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
