<?php
/**
 * El correo del ajuste manual dice los puntos APLICADOS, no los pedidos.
 *
 * Medido en producción el 2026-09-06: el dueño pidió quitar 9.999 puntos a un
 * cliente con saldo 6.500. El sistema hizo lo correcto —quitó 6.500 y dejó el
 * saldo en 0— y se lo dijo bien al dueño en pantalla, pero **al cliente le envió
 * un correo diciendo que se le habían descontado 9.999**.
 *
 * El correo era falso y además se contradecía solo: si se descuentan 9.999 y el
 * saldo queda en 0, el cliente tenía 9.999. Tenía 6.500. Un cliente que intente
 * cuadrar su saldo con ese correo no puede.
 *
 * La causa: `handle()` le pasaba a `notify()` la cifra PEDIDA. `apply()` sí
 * conocía la aplicada —la que devuelve `Ledger::debit()`, ya acotada al saldo—
 * pero sólo la usaba para redactar el aviso del admin y la tiraba.
 *
 * El crédito no tiene el problema: al otorgar no hay acotación, pedido y
 * aplicado coinciden. Se prueba igual, para que siga siendo verdad.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\AdminAdjust;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class AdminAdjustNotifiesTheTruthTest extends RewardsTestCase {

	private const CLIENTE = 7;

	protected function setUp(): void {
		parent::setUp();

		// El ajuste firma la nota de auditoría con el login de quien lo hace.
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_login' => 'qa.owner' ) );
	}

	/**
	 * @return array{0:bool,1:string,2:int}
	 */
	private function quitar( int $puntos ): array {
		return ( new AdminAdjust() )->apply( self::CLIENTE, $puntos, -1, 'prueba de borde' );
	}

	/**
	 * @return array{0:bool,1:string,2:int}
	 */
	private function otorgar( int $puntos ): array {
		return ( new AdminAdjust() )->apply( self::CLIENTE, $puntos, 1, 'prueba de borde' );
	}

	/**
	 * El caso exacto que se midió en producción.
	 *
	 * @testdox Quitar más puntos de los que hay devuelve el delta REALMENTE aplicado.
	 */
	public function test_a_clamped_debit_reports_what_actually_happened(): void {
		Ledger::credit( self::CLIENTE, 6500, 'seed', 'user', self::CLIENTE, 'saldo de partida' );

		$resultado = $this->quitar( 9999 );

		self::assertTrue( $resultado[0], 'El ajuste se aplica: quitar de más no es un error.' );
		self::assertArrayHasKey( 2, $resultado, 'Sin el tercer valor, el correo no puede decir la verdad.' );
		self::assertSame(
			-6500,
			$resultado[2],
			'Se quitaron 6.500, no 9.999. Ese es el número que tiene que ver el cliente.'
		);
		self::assertSame( 0, Ledger::balance( self::CLIENTE ) );
	}

	/**
	 * @testdox Quitar puntos a quien ya está en 0 no cuenta como movimiento.
	 *
	 * Hallazgo S-ac45770c: `Ledger::debit` recorta al saldo, así que devolvía 0,
	 * el delta quedaba en 0 y la comparación `0 >= 0` mandaba al cliente un
	 * correo de regalo —asunto «🎁 Sumaste 0 Puntos Akibara»— por una operación
	 * que encima era una RESTA.
	 */
	public function test_quitar_a_quien_no_tiene_nada_no_es_un_movimiento(): void {
		self::assertSame( 0, Ledger::balance( self::CLIENTE ), 'El cliente parte sin puntos.' );

		$resultado = $this->quitar( 500 );

		self::assertTrue( $resultado[0], 'No es un error: no había nada que quitar.' );
		self::assertSame( 0, $resultado[2], 'Cero movimiento: el cliente no puede recibir ningún aviso.' );
		self::assertStringContainsString(
			'ya estaba en 0 puntos',
			$resultado[1],
			'Y al dueño se le dice con esas palabras, no con un «Quitados 0 puntos (clampado a su saldo)».'
		);
		self::assertStringContainsString( 'No se le envió ningún aviso', $resultado[1] );
	}

	/** @testdox El envío del correo exige que el delta NO sea cero. */
	public function test_el_correo_exige_movimiento(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Points/AdminAdjust.php' );

		self::assertStringContainsString(
			'if ( $ok && $avisar && 0 !== $aplicado && $user instanceof \WP_User )',
			$fuente,
			'Sin ese guard, un ajuste que no movió nada sigue mandando correo.'
		);
	}

	/**
	 * @testdox Un débito que cabe entero informa exactamente lo pedido.
	 */
	public function test_a_debit_that_fits_reports_the_full_amount(): void {
		Ledger::credit( self::CLIENTE, 6500, 'seed', 'user', self::CLIENTE, 'saldo de partida' );

		$resultado = $this->quitar( 2000 );

		self::assertTrue( $resultado[0] );
		self::assertSame( -2000, $resultado[2] );
		self::assertSame( 4500, Ledger::balance( self::CLIENTE ) );
	}

	/**
	 * @testdox Otorgar informa lo otorgado, con signo positivo.
	 */
	public function test_a_credit_reports_the_granted_amount(): void {
		$resultado = $this->otorgar( 6000 );

		self::assertTrue( $resultado[0] );
		self::assertSame( 6000, $resultado[2], 'Al otorgar no hay acotación: pedido y aplicado coinciden.' );
	}

	/**
	 * Un ajuste rechazado no puede anunciar movimiento ninguno.
	 *
	 * @testdox Un ajuste que no se aplica informa delta cero.
	 */
	public function test_a_rejected_adjustment_reports_zero(): void {
		foreach ( array( array( 0, 1 ), array( -5, 1 ), array( 100, 1 ) ) as $caso ) {
			$resultado = ( new AdminAdjust() )->apply(
				100 === $caso[0] ? 0 : self::CLIENTE, // el tercero: cliente inexistente
				100 === $caso[0] ? 100 : $caso[0],
				$caso[1],
				100 === $caso[0] ? 'motivo' : 'motivo'
			);

			self::assertFalse( $resultado[0], 'Precondición: este ajuste se rechaza.' );
			self::assertSame( 0, $resultado[2] ?? 0, 'Sin movimiento no hay nada que anunciar.' );
		}
	}

	/**
	 * @testdox Sin motivo no se aplica nada y el delta es cero.
	 */
	public function test_without_a_reason_nothing_moves(): void {
		$resultado = ( new AdminAdjust() )->apply( self::CLIENTE, 100, 1, '' );

		self::assertFalse( $resultado[0] );
		self::assertSame( 0, $resultado[2] ?? 0 );
	}
}
