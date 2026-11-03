<?php
/**
 * Lo que el checkout promete tiene que poder acreditarse.
 *
 * Dos compuertas que el aviso del carrito ignoraba y la acreditación no: la
 * vigencia del programa (`program_start`, un campo de texto del panel que puede
 * quedar malformado y bloquea la acreditación entera) y el orden de los hooks
 * que hace que el canje esté sellado antes de calcular la base.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.4.4
 */
final class VigenciaYPromesaTest extends RewardsTestCase {

	private function con_inicio( string $valor ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_program_start' === $option ? $valor : $default_value
		);
	}

	/** @testdox Con el programa ya vigente, la puerta está abierta. */
	public function test_programa_vigente(): void {
		$this->con_inicio( '2026-08-17 00:00:00' );

		self::assertTrue( Accrual::program_open_now() );
	}

	/** @testdox Con una fecha de inicio futura, todavía no. */
	public function test_programa_que_aun_no_empieza(): void {
		$this->con_inicio( gmdate( 'Y-m-d H:i:s', time() + 86400 ) );

		self::assertFalse( Accrual::program_open_now() );
	}

	/**
	 * El campo es texto libre: si el dueño escribe «2026-08-17» sin hora,
	 * `in_program()` bloquea TODA la acreditación. El checkout no puede seguir
	 * prometiendo puntos mientras tanto.
	 *
	 * @testdox Con la fecha malformada, la puerta se cierra igual que al acreditar.
	 */
	public function test_fecha_malformada_cierra_la_puerta(): void {
		// La cadena vacía NO entra: `Options::program_start()` cae al valor de
		// fábrica, que es una fecha válida y ya pasada.
		foreach ( array( '2026-08-17', 'ayer', '17/08/2026', '2026-13-45 00:00:00' ) as $malo ) {
			$this->con_inicio( $malo );
			self::assertFalse( Accrual::program_open_now(), sprintf( '«%s» no es una fecha que el motor entienda.', $malo ) );
		}
	}

	/**
	 * `Redemption::on_status_changed` sella lo canjeado a prioridad 10 y
	 * `Accrual::on_status_changed` calcula la base a prioridad 20. Desde que
	 * el estado que acredita es «Procesando», las dos corren en la MISMA
	 * transición: si alguien invierte las prioridades, el pedido acreditaría
	 * puntos sobre dinero pagado con puntos y ninguna prueba lo vería
	 * (lo levantó la revisión independiente del 2026-09-16).
	 *
	 * @testdox El canje se sella ANTES de calcular lo que se acredita.
	 */
	public function test_el_orden_de_los_hooks_sostiene_la_base(): void {
		$canje  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Points/Redemption.php' );
		$abono  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Points/Accrual.php' );
		$leer   = static function ( string $codigo ): int {
			preg_match( "/add_action\(\s*'woocommerce_order_status_changed',\s*array\(\s*\\\$this,\s*'on_status_changed'\s*\),\s*(\d+)/", $codigo, $m );
			return isset( $m[1] ) ? (int) $m[1] : -1;
		};

		$prioridad_canje = $leer( $canje );
		$prioridad_abono = $leer( $abono );

		self::assertGreaterThan( 0, $prioridad_canje, 'No encontré la prioridad del canje: la prueba no estaría midiendo.' );
		self::assertGreaterThan(
			$prioridad_canje,
			$prioridad_abono,
			'La acreditación tiene que correr DESPUÉS del canje: si no, la base no ve lo pagado con puntos.'
		);
	}
}
