<?php
/**
 * Doble parcial de `ReminderEmail` para las pruebas de order-notes.
 *
 * La clase de producción NO es `final` justamente para esto (ver su docblock).
 * Acá se interceptan los dos puntos que deciden qué queda escrito en la orden:
 * la construcción del cuerpo (para no depender de `wc_get_template_html()`) y
 * la entrega al transporte (para forzar el `false` que documenta el fallo).
 *
 * Vive en su propio archivo porque el bootstrap de PHPUnit sólo carga los
 * `*Test.php`; el `require_once` del test lo trae explícitamente.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\ReminderEmail;

final class ReminderEmailSpy extends ReminderEmail {

	/**
	 * Envíos entregados al transporte, en orden.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $akb_sent = array();

	/** Valor que devuelve el transporte (false = fallo declarado). */
	public bool $akb_send_returns = true;

	/**
	 * Notas que la orden ya tenía en el instante EXACTO en que el transporte
	 * recibió el mensaje. Es la única forma de distinguir "la nota se redactó
	 * antes de saber el resultado" de "se redactó después".
	 *
	 * @var array<int, string>
	 */
	public array $akb_notes_at_send = array();

	/**
	 * Estado del pedido EN EL INSTANTE del envío, no al terminar.
	 *
	 * Sin esta foto, un candado que quiera fijar «el marker se persiste ANTES del
	 * envío» no fija nada: mirar el estado final da el mismo resultado con las dos
	 * órdenes. Una revisión adversarial lo demostró el 2026-08-24 moviendo el
	 * `update_meta_data() + save()` a después del envío en los tres modos — 9/9 en
	 * verde con la regresión puesta, o sea con el doble envío concurrente reabierto.
	 */
	public int $akb_saves_at_send = 0;

	/** @var array<string,mixed> Meta del pedido en el instante del envío. */
	public array $akb_meta_at_send = array();

	/**
	 * `WC_Email::format_string()` existe en WooCommerce pero no en el stub
	 * compartido de la suite (`tests/wp-stubs.php`), que sólo cubre lo que
	 * consumía `ReadyToShipEmail`. Se replica acá —sustitución de placeholders,
	 * que es todo lo que el path no-t7 de `get_subject()` necesita— en vez de
	 * ampliar el stub compartido.
	 *
	 * @param string $string Texto con placeholders.
	 * @return string
	 */
	public function format_string( $string ): string {
		return strtr( (string) $string, $this->placeholders );
	}

	public function get_content_html(): string {
		return '<p>Tu preventa se acerca</p>';
	}

	public function get_content_plain(): string {
		return 'Tu preventa se acerca';
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
		);

		unset( $message, $headers, $attachments );

		if ( $this->object instanceof \WC_Order ) {
			$this->akb_notes_at_send = $this->object->akb_notes;
			$this->akb_saves_at_send = (int) $this->object->akb_saves;
			$this->akb_meta_at_send  = (array) $this->object->akb_meta;
		}

		return $this->akb_send_returns;
	}
}
