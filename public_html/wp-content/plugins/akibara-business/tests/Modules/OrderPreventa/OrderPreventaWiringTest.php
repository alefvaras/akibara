<?php
/**
 * El cron diario recibe el recalculador.
 *
 * `ReadyToShipCron` acepta el recalculador como OPCIONAL (sin él sigue
 * marcando «listo»), así que olvidarlo en el módulo no rompe nada visible: el
 * estimado de arribo simplemente deja de bajar cuando un tomo llega o se
 * reembolsa, y el cliente sigue viendo la fecha del tomo que ya no espera.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\OrderPreventa;
use Akibara\Business\Modules\OrderPreventa\ReadyToShipCron;
use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;
use Akibara\Business\Tests\Support\NullEtaProvider;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class OrderPreventaWiringTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El recalculador del cron no puede omitirse: el olvido es un error, no una degradación silenciosa.
	 */
	public function test_the_recalculator_is_mandatory(): void {
		$param = ( new \ReflectionMethod( ReadyToShipCron::class, '__construct' ) )->getParameters()[0];

		self::assertSame( 'recalculator', $param->getName() );
		self::assertFalse( $param->isOptional() );
		self::assertFalse( $param->allowsNull() );
	}

	/**
	 * @testdox El módulo construye el cron CON el recalculador.
	 */
	public function test_the_cron_gets_the_recalculator(): void {
		$module = new OrderPreventa( new NullEtaProvider() );

		$cron_prop = new ReflectionProperty( OrderPreventa::class, 'cron' );
		$cron_prop->setAccessible( true );
		$cron = $cron_prop->getValue( $module );
		self::assertInstanceOf( ReadyToShipCron::class, $cron );

		$rec_prop = new ReflectionProperty( ReadyToShipCron::class, 'recalculator' );
		$rec_prop->setAccessible( true );
		self::assertInstanceOf(
			SummaryRecalculator::class,
			$rec_prop->getValue( $cron ),
			'Sin recalculador el cron marca «listo» pero el estimado del pedido nunca baja.'
		);
	}
}
