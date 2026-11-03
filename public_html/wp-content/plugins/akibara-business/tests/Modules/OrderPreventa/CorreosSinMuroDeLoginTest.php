<?php
/**
 * Los correos de preventa no pueden mandar al cliente a un muro de login.
 *
 * Hallazgo S-7fc2defd: el destinatario de estos correos es el correo de
 * FACTURACIÓN del pedido, que existe igual sin cuenta. Un comprador invitado
 * recibía «tu pedido ya llegó», pulsaba «Ver mi pedido» y aterrizaba en un
 * formulario de acceso — la peor pantalla posible justo cuando venía con la
 * mejor noticia.
 *
 * El criterio correcto ya existía en `EmailBrand::order_url()`, que para un
 * invitado usa la página de recibido que WooCommerce autoriza con la `key` del
 * propio pedido. Estos cuatro templates lo tenían resuelto a mano.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use PHPUnit\Framework\TestCase;

final class CorreosSinMuroDeLoginTest extends TestCase {

	/** @return array<int, string> Los cuatro templates: HTML y texto plano. */
	private function plantillas(): array {
		$raiz = dirname( __DIR__, 3 ) . '/templates/emails/';

		return array(
			$raiz . 'customer-preventa-ready.php',
			$raiz . 'customer-preventa-reminder.php',
			$raiz . 'plain/customer-preventa-ready.php',
			$raiz . 'plain/customer-preventa-reminder.php',
		);
	}

	/** @testdox Ningún correo de preventa arma el enlace con `view-order`. */
	public function test_ninguno_usa_la_ruta_que_exige_sesion(): void {
		foreach ( $this->plantillas() as $ruta ) {
			$codigo = (string) file_get_contents( $ruta );
			// Se quitan los comentarios: un docblock que EXPLIQUE por qué no se
			// usa `get_view_order_url()` no puede poner el candado en rojo.
			$codigo = (string) preg_replace( '~(//[^\n]*|/\*.*?\*/)~s', '', $codigo );

			self::assertStringNotContainsString(
				'get_view_order_url',
				$codigo,
				sprintf( '%s manda al invitado a un muro de login.', basename( $ruta ) )
			);
		}
	}

	/** @testdox Los cuatro usan la fuente única del destino. */
	public function test_todos_usan_el_resolvedor_compartido(): void {
		foreach ( $this->plantillas() as $ruta ) {
			self::assertStringContainsString(
				'EmailBrand::order_url( $order )',
				(string) file_get_contents( $ruta ),
				sprintf( '%s resuelve el destino por su cuenta.', basename( $ruta ) )
			);
		}
	}

	/** @testdox Con cuenta va a «mis pedidos»; sin cuenta, a la página que autoriza la key. */
	public function test_el_destino_cambia_segun_haya_cuenta(): void {
		$con_cuenta                     = new \Akibara\Business\Tests\Support\PedidoConDatosDeCliente();
		$con_cuenta->akb_customer_id    = 5;
		$invitado                       = new \Akibara\Business\Tests\Support\PedidoConDatosDeCliente();
		$invitado->akb_customer_id      = 0;

		self::assertStringContainsString( '/view-order/', \Akibara\Business\Modules\EmailBrand\EmailBrand::order_url( $con_cuenta ) );
		self::assertStringContainsString( 'order-received', \Akibara\Business\Modules\EmailBrand\EmailBrand::order_url( $invitado ) );
		self::assertStringNotContainsString( '/view-order/', \Akibara\Business\Modules\EmailBrand\EmailBrand::order_url( $invitado ), 'El invitado no tiene sesión que ofrecer.' );
	}

	/** @testdox El resolvedor sigue siendo público y sigue distinguiendo al invitado. */
	public function test_el_resolvedor_distingue_al_invitado(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/EmailBrand/EmailBrand.php' );

		self::assertStringContainsString( 'public static function order_url( WC_Order $order ): string', $fuente );
		self::assertStringContainsString( 'get_customer_id() > 0', $fuente, 'La decisión es «¿hay cuenta detrás del pedido?».' );
		self::assertStringContainsString( 'get_checkout_order_received_url()', $fuente, 'Y para el invitado, la página que WooCommerce autoriza con la key.' );
	}
}
