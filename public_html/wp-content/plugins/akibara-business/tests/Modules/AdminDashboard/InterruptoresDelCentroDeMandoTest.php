<?php
/**
 * Pruebas para los interruptores del Centro de Mando.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminDashboard;

use Akibara\Business\Modules\AdminDashboard\AdminDashboard;
use PHPUnit\Framework\TestCase;

final class InterruptoresDelCentroDeMandoTest extends TestCase {

	/** @testdox Cada interruptor del tablero apunta a una pestaña y a una option. */
	public function test_every_toggle_is_complete(): void {
		$toggles = AdminDashboard::module_toggles();
		self::assertNotEmpty( $toggles );

		foreach ( $toggles as $clave => $mod ) {
			foreach ( array( 'label', 'option', 'default', 'tab' ) as $campo ) {
				self::assertArrayHasKey( $campo, $mod, "«{$clave}» no declara {$campo}." );
				self::assertNotSame( '', (string) $mod[ $campo ] );
			}
			self::assertContains( $mod['default'], array( 'yes', 'no' ) );
		}
	}
}
