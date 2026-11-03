<?php
/**
 * Los docblocks del módulo BIS citan el tope de RateLimit real, no el de 2026-07 (S-fce0fa53).
 *
 * `RateLimit::MAX_POR_TIPO['bis']` subió de 3 a 12 el 2026-09-02 (caso real:
 * un cliente se anotó a varios tomos seguidos y el tope de 3/hora le cortó a
 * mitad de la serie). El docblock de BackInStock.php seguía describiendo el
 * riesgo viejo — «RateLimit 3/h/IP … mitigable: login lo exime» — cuando el
 * tope real ya es 12 y la exención de 2026-07-28 dejó de cubrir a cualquier
 * logueado, sólo a staff.
 *
 * Reapertura 2026-09-19 (S-fce0fa53): el arreglo original sólo tocó el
 * docblock de BackInStock.php. La MISMA cita vieja («3/h/IP») seguía sin
 * tocar en otros dos ficheros del módulo que la usan para justificar un
 * análisis de riesgo — SubscriptionNotice.php (costo acotado del envío
 * SÍNCRONO de correo) y CaptureRestController.php (oráculo
 * 'already_subscribed' con «valor de ataque ≈ 0») — ambos subestimando el
 * tope real en 4×. Este candado se extiende a los tres ficheros para que la
 * causa completa (todas las citas del módulo), no sólo el síntoma puntual,
 * quede cubierta.
 *
 * Candado por invariante: si `MAX_POR_TIPO['bis']` vuelve a cambiar sin tocar
 * estos docblocks, el número que citan quedará stale otra vez sin que este
 * test lo note — por eso compara el NÚMERO vigente contra la propia
 * constante, no un literal duplicado a mano.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BackInStock;

use Akibara\Business\Utilities\RateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DocblockRateLimitMatchesRealTest extends TestCase {

	/**
	 * @return int Tope vigente para 'bis' leído por Reflection, nunca duplicado a mano.
	 */
	private function tope_real_bis(): int {
		$reflexion = new ReflectionClass( RateLimit::class );
		$mapa      = $reflexion->getConstant( 'MAX_POR_TIPO' );
		self::assertIsArray( $mapa );

		return (int) $mapa['bis'];
	}

	/** @testdox El docblock de BackInStock cita el mismo tope/hora que RateLimit::MAX_POR_TIPO['bis']. */
	public function test_docblock_de_backinstock_cita_el_tope_real(): void {
		$tope_real = $this->tope_real_bis();

		$docblock = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/BackInStock/BackInStock.php'
		);

		self::assertStringContainsString(
			(string) $tope_real . '/hora',
			$docblock,
			"El docblock tiene que citar el tope vigente ({$tope_real}/hora), no uno superado."
		);
		self::assertStringNotContainsString(
			'RateLimit 3/h/IP',
			$docblock,
			'El riesgo de 3/h ya no describe el tope real desde el 2026-09-02.'
		);
	}

	/**
	 * @testdox El docblock de SubscriptionNotice cita el mismo tope/h/IP que RateLimit::MAX_POR_TIPO['bis'] (S-fce0fa53).
	 */
	public function test_docblock_de_subscription_notice_cita_el_tope_real(): void {
		$tope_real = $this->tope_real_bis();

		$docblock = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/BackInStock/SubscriptionNotice.php'
		);

		self::assertStringContainsString(
			(string) $tope_real . '/h/IP',
			$docblock,
			"El análisis de costo del envío síncrono tiene que citar el tope vigente ({$tope_real}/h/IP), no uno superado."
		);
		self::assertStringNotContainsString(
			'3/h/IP',
			$docblock,
			'El riesgo de 3/h/IP ya no describe el tope real de "bis" desde el 2026-09-02.'
		);
	}

	/**
	 * @testdox El docblock de CaptureRestController cita el mismo tope/h/IP que RateLimit::MAX_POR_TIPO['bis'] (S-fce0fa53).
	 */
	public function test_docblock_de_capture_rest_controller_cita_el_tope_real(): void {
		$tope_real = $this->tope_real_bis();

		$docblock = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/BackInStock/CaptureRestController.php'
		);

		self::assertStringContainsString(
			(string) $tope_real . '/h/IP',
			$docblock,
			"La mitigación del oráculo 'already_subscribed' tiene que citar el tope vigente ({$tope_real}/h/IP), no uno superado."
		);
		self::assertStringNotContainsString(
			'3/h/IP',
			$docblock,
			'El riesgo de 3/h/IP ya no describe el tope real de "bis" desde el 2026-09-02.'
		);
	}
}
