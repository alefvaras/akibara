<?php
/**
 * El Centro de Mando no puede decir que un módulo está encendido cuando está
 * apagado.
 *
 * Hallazgo S-10351b0b: el mapa de interruptores leía
 * `akibara_taxonomy_discount_enabled` con defecto `yes`, y el módulo lo lee con
 * defecto `no`. Mientras el dueño no enciende la campaña —el estado normal— la
 * fila ni siquiera existe, así que el tablero anunciaba «módulos: todos
 * encendidos» con los descuentos apagados.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminDashboard;

use Akibara\Business\Modules\AdminDashboard\AdminDashboard;
use Akibara\Business\Modules\TaxonomyDiscount\Settings as TaxonomyDiscountSettings;
use PHPUnit\Framework\TestCase;

final class InterruptoresDelCentroDeMandoTest extends TestCase {

	/** @testdox El defecto del tablero es el mismo que usa el módulo para decidir. */
	public function test_the_dashboard_default_matches_the_module(): void {
		$mapa = AdminDashboard::module_toggles();

		self::assertArrayHasKey( 'taxonomy_discount', $mapa );
		self::assertSame( TaxonomyDiscountSettings::OPTION_ENABLED, $mapa['taxonomy_discount']['option'] );
		self::assertSame(
			TaxonomyDiscountSettings::DEFAULT_ENABLED,
			$mapa['taxonomy_discount']['default'],
			'Con la fila ausente, la campaña está apagada: decir lo contrario es mentirle al dueño en su tablero.'
		);
		self::assertSame( 'no', $mapa['taxonomy_discount']['default'] );
	}

	/** @testdox Cada interruptor del tablero apunta a una pestaña y a una option. */
	public function test_every_toggle_is_complete(): void {
		foreach ( AdminDashboard::module_toggles() as $clave => $mod ) {
			foreach ( array( 'label', 'option', 'default', 'tab' ) as $campo ) {
				self::assertArrayHasKey( $campo, $mod, "«{$clave}» no declara {$campo}." );
				self::assertNotSame( '', (string) $mod[ $campo ] );
			}
			self::assertContains( $mod['default'], array( 'yes', 'no' ) );
		}
	}
}
