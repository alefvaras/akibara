<?php
/**
 * Un pedido de preventa SIN la meta de «listo» tiene que entrar al flip.
 *
 * El guard de `process_order()` sólo rechaza el literal `'yes'`, así que la
 * ausencia de la meta es un candidato legítimo. La consulta exigía `'no'` y lo
 * dejaba fuera: un pedido nacido sin pasar por `OrderTagging::apply_tags()`
 * —importación, alta por API, creado a mano en el admin, migración— no se
 * marcaba listo nunca y su cliente no recibía el correo «ya llegó», sin dejar
 * ni nota ni log. La misma corrección se aplicó el 2026-09-02 a
 * `ReleaseDateChangeListener` y a `RemindersCron`; este consumidor se quedó
 * atrás (hallazgo S-2d3917fe, 2026-09-18).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\ReadyToShipCron;
use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;
use Akibara\Business\Tests\Support\NullEtaProvider;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ElFlipVeLosPedidosSinLaMetaTest extends TestCase {

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
	 * Los argumentos REALES con los que el cron pide sus candidatos.
	 *
	 * @return array<string,mixed>
	 */
	private static function args(): array {
		$metodo = new ReflectionMethod( ReadyToShipCron::class, 'candidate_order_query_args' );
		$metodo->setAccessible( true );

		return (array) $metodo->invoke( new ReadyToShipCron( new SummaryRecalculator( new NullEtaProvider() ) ) );
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
	 * Sin este filtro, cada pasada arrastraría todos los pedidos activos de la
	 * tienda y los pocos candidatos reales competirían con ellos por el tamaño
	 * del lote: el arreglo de una invisibilidad no puede crear otra.
	 *
	 * @testdox La consulta sigue pidiendo SÓLO pedidos de preventa.
	 */
	public function test_the_query_still_asks_only_for_preorders(): void {
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
