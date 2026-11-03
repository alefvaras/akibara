<?php
/**
 * Lo que el dueño otorga a mano NO cuenta como «ganado» por el cliente.
 *
 * `total_earned` es el histórico de puntos que el cliente ganó comprando,
 * reseñando o refiriendo, y por diseño **nunca baja con los débitos**: es una
 * cifra de auditoría, no un saldo.
 *
 * El ajuste manual acreditaba con `counts_as_earned = true`, y eso lo dejaba
 * asimétrico e irreparable: el dueño se equivoca, otorga 5.000 puntos a quien no
 * debía, los quita con el mismo formulario —el saldo vuelve a su sitio— y el
 * histórico se queda inflado en 5.000 **para siempre**, sin forma soportada de
 * corregirlo. Medido por QA el 2026-09-06: otorgó 6.000, el histórico pasó de
 * 3.296 a 9.296; quitó 6.500 y siguió en 9.296. Tuvo que escribir el usermeta a
 * mano, que es justo lo que no se debe hacer.
 *
 * De las tres salidas posibles se eligió esta porque preserva la invariante que
 * hace útil la cifra —nunca baja— en vez de romperla: si el regalo no entra, no
 * hay nada que revertir. Un ajuste manual es un regalo o una corrección; el
 * cliente no lo ganó.
 *
 * Estado al decidirlo: 9 movimientos `admin_adjust` en producción, y ningún
 * lector de `total_earned` fuera de la importación de SPAR. Cambiarlo ahora es
 * barato; cuando lo lean los niveles o las insignias, ya no.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\AdminAdjust;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class AjusteManualNoEsGanadoTest extends RewardsTestCase {

	private const CLIENTE = 7;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_login' => 'qa.owner' ) );
	}

	/**
	 * @testdox Otorgar a mano sube el saldo pero NO el histórico de ganados.
	 */
	public function test_a_manual_grant_does_not_count_as_earned(): void {
		Ledger::credit( self::CLIENTE, 3000, 'order', 'order', 1, 'Compra real' );
		self::assertSame( 3000, Ledger::total_earned( self::CLIENTE ), 'Precondición: lo ganado comprando sí cuenta.' );

		( new AdminAdjust() )->apply( self::CLIENTE, 5000, 1, 'regalo de disculpa' );

		self::assertSame( 8000, Ledger::balance( self::CLIENTE ), 'El saldo sí sube: son puntos gastables.' );
		self::assertSame(
			3000,
			Ledger::total_earned( self::CLIENTE ),
			'Un regalo del dueño no lo ganó el cliente: el histórico no se mueve.'
		);
	}

	/**
	 * El caso que motivó el cambio: equivocarse y corregirlo tiene que dejar las
	 * dos cifras como estaban.
	 *
	 * @testdox Otorgar por error y quitarlo deja el histórico intacto.
	 */
	public function test_granting_by_mistake_and_undoing_it_leaves_no_trace(): void {
		Ledger::credit( self::CLIENTE, 3296, 'order', 'order', 1, 'Compras reales' );

		$ajuste = new AdminAdjust();
		$ajuste->apply( self::CLIENTE, 6000, 1, 'me equivoqué de cliente' );
		$ajuste->apply( self::CLIENTE, 6000, -1, 'corrijo el error anterior' );

		self::assertSame( 3296, Ledger::balance( self::CLIENTE ), 'El saldo vuelve a su sitio.' );
		self::assertSame(
			3296,
			Ledger::total_earned( self::CLIENTE ),
			'Y el histórico también: antes se quedaba inflado en 6.000 para siempre.'
		);
	}

	/**
	 * Lo que NO se toca: la invariante que hace útil la cifra.
	 *
	 * @testdox Un gasto real del cliente sigue sin bajar su histórico de ganados.
	 */
	public function test_a_real_redemption_still_never_lowers_the_history(): void {
		Ledger::credit( self::CLIENTE, 5000, 'order', 'order', 1, 'Compra real' );
		Ledger::debit( self::CLIENTE, 2000, 'redemption', 'order', 2, 'Canje en un pedido' );

		self::assertSame( 3000, Ledger::balance( self::CLIENTE ) );
		self::assertSame(
			5000,
			Ledger::total_earned( self::CLIENTE ),
			'Gastar lo ganado no borra que se ganó: eso es lo que hace útil la cifra.'
		);
	}

	/**
	 * @testdox Los puntos ganados por reseñas o referidos siguen contando.
	 */
	public function test_the_other_ways_of_earning_still_count(): void {
		foreach ( array( 'review', 'referral', 'birthday', 'welcome_bonus' ) as $accion ) {
			$antes = Ledger::total_earned( self::CLIENTE );
			Ledger::credit( self::CLIENTE, 500, $accion, 'user', self::CLIENTE, 'prueba' );

			self::assertSame(
				$antes + 500,
				Ledger::total_earned( self::CLIENTE ),
				'«' . $accion . '» sí es algo que el cliente hizo para ganarlo.'
			);
		}
	}
}
