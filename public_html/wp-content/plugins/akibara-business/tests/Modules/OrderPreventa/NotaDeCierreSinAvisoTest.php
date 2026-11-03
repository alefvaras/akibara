<?php
/**
 * Dos causas opuestas no pueden dejar la misma nota, y la nota no puede mandar a
 * un botón que no existe.
 *
 * El manual del dueño declaraba el defecto así: «Reembolso de todo lo esperado:
 * queda listo sin correo, con la nota "Ya no queda nada pendiente tras el
 * reembolso". **Defecto:** la misma nota sale si las líneas perdieron su marca
 * por una edición manual o importación, sin reembolso, y el cliente se queda sin
 * aviso».
 *
 * El primer arreglo cambió el texto pero mandaba a reenviar el correo «desde
 * Acciones del pedido», y esa acción NO se ofrece en este pedido: el despachador
 * sólo la añade cuando quedan líneas en espera, que es justo lo que aquí no hay.
 * Lo cazó la revisión independiente antes de desplegar. Una nota que manda a un
 * botón inexistente es peor que no decir nada: el cliente sigue sin aviso y el
 * dueño pierde el tiempo buscándolo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\ReadyToShipCron;
use PHPUnit\Framework\TestCase;

final class NotaDeCierreSinAvisoTest extends TestCase {

	/**
	 * @testdox With the preventa marks still on the lines, what was pending was refunded.
	 */
	public function test_con_marcas_intactas_la_nota_habla_del_reembolso(): void {
		$nota = ReadyToShipCron::nota_de_cierre( true );

		self::assertStringContainsString( 'tras el reembolso', $nota );
		self::assertStringNotContainsString( 'a mano', $nota, 'no hay nada que avisar: se devolvió el dinero' );
	}

	/**
	 * @testdox Without marks the note names the cause, says the mail cannot go out, and what to do.
	 */
	public function test_sin_marcas_la_nota_dice_la_verdad_operativa(): void {
		$nota = ReadyToShipCron::nota_de_cierre( false );

		self::assertStringNotContainsString(
			'tras el reembolso',
			$nota,
			'afirmar un reembolso que no existe es lo que hacía invisible este caso'
		);
		self::assertStringContainsString( 'no hay ningún reembolso', $nota );
		self::assertStringContainsString( 'borrara esa marca', $nota, 'el dueño necesita la causa probable' );
		self::assertStringContainsString( 'avísale a mano', $nota, 'la salida real, no un botón que no existe' );
	}

	/**
	 * @testdox The note must never point at the resend action: it is not offered for this order.
	 */
	public function test_la_nota_no_manda_a_un_boton_que_no_existe(): void {
		// `ReadyEmailDispatcher::register_order_actions()` añade «reenviar» sólo si
		// quedan líneas en espera, y en esta rama no queda ninguna. Aunque se
		// forzara, el correo se arma con esas líneas y saldría vacío.
		foreach ( array( true, false ) as $conserva_marcas ) {
			self::assertStringNotContainsString(
				'Acciones del pedido',
				ReadyToShipCron::nota_de_cierre( $conserva_marcas )
			);
		}
	}

	/**
	 * @testdox The two notes must not be the same text.
	 */
	public function test_las_dos_notas_son_distintas(): void {
		self::assertNotSame(
			ReadyToShipCron::nota_de_cierre( true ),
			ReadyToShipCron::nota_de_cierre( false )
		);
	}
}
