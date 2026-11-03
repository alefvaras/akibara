<?php
/**
 * Doble parcial de `ReadyToShipEmail` para las pruebas de despacho.
 *
 * La clase de producción NO es `final` justamente para esto (ver su docblock):
 * acá se interceptan los tres puntos que deciden el contrato de seguridad ante
 * excepciones — construir el contenido, entregar al transporte, y lo que ocurre
 * inmediatamente después de que el transporte aceptó.
 *
 * Vive en su propio archivo porque el bootstrap de PHPUnit sólo carga los
 * `*Test.php`; el `require_once` del test lo trae explícitamente.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\ReadyToShipEmail;
use RuntimeException;

final class ReadyToShipEmailSpy extends ReadyToShipEmail {

	/**
	 * Envíos entregados al transporte, en orden.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $akb_sent = array();

	/** Valor que devuelve el transporte (false = fallo declarado). */
	public bool $akb_send_returns = true;

	/**
	 * Lanzar al construir el cuerpo: excepción ANTES de cualquier entrega.
	 *
	 * Se intercepta `get_content_html()` y NO `get_content()`, para que el
	 * ciclo real de `$sending` de la clase base siga corriendo — sobrescribir
	 * `get_content()` ocultaba justamente el estado que hay que observar
	 * [Codex P1-B].
	 */
	public bool $akb_throw_on_build = false;

	/** Lanzar DENTRO de `setup_locale()`, con el locale ya cambiado [P1-A]. */
	public bool $akb_throw_on_locale_setup = false;

	/** Resultado forzado de la verificación de durabilidad del marker [P1-D]. */
	public bool $akb_marker_durable = true;

	/** Órdenes cuya durabilidad se consultó, en orden. */
	public array $akb_durability_checks = array();

	/**
	 * Lanzar DESPUÉS de que el transporte aceptó. Modela lo ambiguo: un filtro,
	 * un listener de `wp_mail_succeeded` o una integración que revienta con el
	 * mensaje ya entregado.
	 */
	public bool $akb_throw_on_send = false;

	public function get_content_html(): string {
		if ( $this->akb_throw_on_build ) {
			throw new RuntimeException( 'plantilla rota' );
		}

		return '<p>Tu preventa ya llegó</p>';
	}

	/**
	 * Cambia el locale como la clase base y recién entonces lanza: el defecto
	 * que persigue P1-A es que el `setup_locale()` a medio camino quedaba fuera
	 * del tramo protegido.
	 */
	public function setup_locale(): void {
		parent::setup_locale();

		if ( $this->akb_throw_on_locale_setup ) {
			throw new RuntimeException( 'plugin_locale reventó' );
		}
	}

	/**
	 * @param int $order_id Pedido.
	 * @param int $expected Sello escrito.
	 * @return bool
	 */
	protected function marker_is_durable( int $order_id, int $expected ): bool {
		unset( $expected );
		$this->akb_durability_checks[] = $order_id;

		return $this->akb_marker_durable;
	}

	/**
	 * @param string           $to          Destinatario.
	 * @param string           $subject     Asunto.
	 * @param string           $message     Cuerpo.
	 * @param string           $headers     Cabeceras.
	 * @param array<int,mixed> $attachments Adjuntos.
	 * @return bool
	 */
	public function send( $to, $subject, $message, $headers, $attachments ): bool {
		$this->akb_sent[] = array(
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
		);

		unset( $headers, $attachments );

		if ( $this->akb_throw_on_send ) {
			throw new RuntimeException( 'listener post-transporte' );
		}

		return $this->akb_send_returns;
	}
}
