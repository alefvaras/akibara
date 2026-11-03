<?php
/**
 * Las order-notes de `ReminderEmail` tienen que contar lo que REALMENTE pasó.
 *
 * Defecto (auditoría 2026-08-22): los tres modos escribían la nota
 * «… enviado al cliente.» ANTES de llamar a `send()`. Cuando el transporte
 * devolvía `false`, `SendFailureRearm` agregaba a continuación «… NO salió
 * (fallo del transporte)», así que la orden quedaba con las dos notas
 * contradictorias seguidas y el operador leía «enviado» sobre un correo que
 * nunca salió.
 *
 * INVARIANTE que fijan estas pruebas (no el literal de la nota):
 *
 *   1. El marker anti-doble-envío se persiste ANTES del `send()` — eso NO
 *      cambia, es lo que corta el dispatch concurrente cron ‖ Action Scheduler.
 *   2. La nota afirmativa de envío sólo existe si el transporte NO devolvió
 *      `false`.
 *   3. Ante `false`, la única nota que queda es la de `SendFailureRearm`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\SendFailureRearm;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;

require_once __DIR__ . '/ReminderEmailSpy.php';

final class ReminderEmailNotesTest extends TestCase {

	private WC_Order $order;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) );

		$this->order = $this->eligible_order();

		$order = $this->order;
		Functions\when( 'wc_get_order' )->alias( static fn() => $order );
		Functions\when( 'trailingslashit' )->alias( static fn( string $path ): string => rtrim( $path, '/\\' ) . '/' );
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);
		// Ventana del cap de frecuencia: default canónico de Settings.
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => $default
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Orden que pasa `order_passes_guards()`: en espera, con un tomo pendiente. */
	private function eligible_order(): WC_Order {
		$item               = new WC_Order_Item_Product();
		$item->akb_id       = 7;
		$item->akb_quantity = 1;
		$item->akb_meta     = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );

		$order             = new WC_Order();
		$order->akb_id     = 27188;
		$order->akb_status = 'processing';
		$order->akb_items  = array( $item );
		$order->akb_meta   = array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' );

		return $order;
	}

	/**
	 * @param string $needle Fragmento buscado.
	 * @return array<int, string>
	 */
	private function notes_matching( string $needle ): array {
		return array_values(
			array_filter(
				$this->order->akb_notes,
				static fn( string $note ): bool => str_contains( $note, $needle )
			)
		);
	}

	// -----------------------------------------------------------------------
	// Modo t7.
	// -----------------------------------------------------------------------

	/**
	 * @testdox T-7: el marker se persiste ANTES del send (anti doble envío).
	 */
	public function test_t7_marker_is_written_before_transport(): void {
		$email = new ReminderEmailSpy();

		$email->trigger_t7( $this->order->get_id() );

		self::assertCount( 1, $email->akb_sent );

		// Se mira el estado EN EL INSTANTE del envío, no al terminar.
		//
		// Mirar el estado final no fija nada: se cumple igual con las dos órdenes.
		// Una revisión adversarial lo demostró el 2026-08-24 moviendo el
		// `update_meta_data() + save()` a DESPUÉS del envío — 9/9 en verde con la
		// regresión puesta, o sea con el doble envío concurrente reabierto y el
		// candado callando. Lo que protege esta invariante es que un cliente no
		// reciba dos veces el mismo recordatorio.
		self::assertGreaterThan(
			0,
			$email->akb_saves_at_send,
			'El marker tiene que estar PERSISTIDO cuando arranca el transporte, no después.'
		);
		self::assertNotSame(
			'',
			(string) ( $email->akb_meta_at_send[ MetaKeys::ORDER_T7_REMINDER_SENT ] ?? '' ),
			'Sin el marker escrito antes del envío, dos ejecuciones concurrentes mandan el correo dos veces.'
		);
	}

	/**
	 * @testdox T-7 exitoso: queda la nota afirmativa de envío.
	 */
	public function test_t7_success_leaves_sent_note(): void {
		$email = new ReminderEmailSpy();

		$email->trigger_t7( $this->order->get_id() );

		self::assertCount( 1, $this->notes_matching( 'Recordatorio T-7d' ) );
		self::assertSame( array(), $this->notes_matching( 'NO salió' ) );
		// Guard, no mejora: el camino feliz pagaba UN `save()` antes del arreglo
		// y paga UNO ahora, así que esta aserción también pasaba con el código
		// anterior. Lo que impide es que alguien añada un `save()` detrás de la
		// nota en el futuro — `add_order_note()` inserta el comentario en el acto
		// y no deja meta sucia, así que ese `save()` sería puro coste (32,8
		// consultas en frío sobre un pedido HPOS real, medido el 2026-08-23).
		self::assertSame( 1, $this->order->akb_saves );
	}

	/**
	 * Corazón del defecto: con `false` del transporte NO puede quedar una nota
	 * que afirme que el correo salió.
	 *
	 * @testdox T-7 fallido: NO queda ninguna nota afirmando que se envió.
	 */
	public function test_t7_transport_failure_leaves_no_sent_note(): void {
		$email                   = new ReminderEmailSpy();
		$email->akb_send_returns = false;

		$email->trigger_t7( $this->order->get_id() );

		self::assertSame(
			array(),
			$this->notes_matching( 'enviado al cliente' ),
			'La orden afirma un envío que el transporte rechazó.'
		);
		self::assertCount( 1, $this->notes_matching( 'NO salió' ) );
		self::assertSame( 1, (int) $this->order->get_meta( MetaKeys::ORDER_T7_REMINDER_ATTEMPTS ) );
	}

	/**
	 * @testdox T-7: en el tope de intentos tampoco queda nota afirmativa.
	 */
	public function test_t7_at_attempt_cap_leaves_no_sent_note(): void {
		$this->order->update_meta_data( MetaKeys::ORDER_T7_REMINDER_ATTEMPTS, SendFailureRearm::MAX_ATTEMPTS - 1 );

		$email                   = new ReminderEmailSpy();
		$email->akb_send_returns = false;

		$email->trigger_t7( $this->order->get_id() );

		self::assertSame( array(), $this->notes_matching( 'enviado al cliente' ) );
		self::assertCount( 1, $this->notes_matching( 'se deja de reintentar' ) );
	}

	/**
	 * El transporte no debe ver todavía la nota afirmativa: si la ve, es que se
	 * redactó antes de conocer el resultado.
	 *
	 * @testdox T-7: en el instante del send la nota afirmativa aún no existe.
	 */
	public function test_t7_note_is_not_written_before_transport(): void {
		$email = new ReminderEmailSpy();

		$email->trigger_t7( $this->order->get_id() );

		self::assertSame(
			array(),
			array_values(
				array_filter(
					$email->akb_notes_at_send,
					static fn( string $note ): bool => str_contains( $note, 'enviado al cliente' )
				)
			)
		);
	}

	// -----------------------------------------------------------------------
	// Modo date_changed (mismo patrón en `dispatch_date_email`).
	// -----------------------------------------------------------------------

	/**
	 * @testdox Cambio de fecha exitoso: queda la nota afirmativa y la marca del cap.
	 */
	public function test_date_changed_success_leaves_sent_note(): void {
		$email = new ReminderEmailSpy();

		$email->trigger_date_changed( $this->order->get_id(), 55, '2026-09-01', '2026-10-15' );

		self::assertCount( 1, $email->akb_sent );
		self::assertCount( 1, $this->notes_matching( 'Email de cambio de fecha enviado al cliente.' ) );
		self::assertGreaterThan( 0, (int) $this->order->get_meta( MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT ) );
		// Guard, no mejora: este camino pagaba UN `save()` antes del arreglo y paga
		// UNO ahora, así que la aserción también pasaba con el código anterior. Lo
		// que impide es añadir un `save()` detrás de la nota en el futuro, que
		// sería puro coste: `add_order_note()` inserta el comentario en el acto y
		// no deja meta sucia.
		self::assertSame( 1, $this->order->akb_saves );
	}

	/**
	 * @testdox Cambio de fecha fallido: sin nota afirmativa y con la marca revertida.
	 */
	public function test_date_changed_failure_leaves_no_sent_note(): void {
		$email                   = new ReminderEmailSpy();
		$email->akb_send_returns = false;

		$email->trigger_date_changed( $this->order->get_id(), 55, '2026-09-01', '2026-10-15' );

		self::assertSame( array(), $this->notes_matching( 'enviado al cliente' ) );
		self::assertCount( 1, $this->notes_matching( 'NO salió' ) );
		self::assertSame( '', (string) $this->order->get_meta( MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT ) );
	}

	/**
	 * @testdox Fecha anunciada fallida: sin nota afirmativa.
	 */
	public function test_date_announced_failure_leaves_no_sent_note(): void {
		$email                   = new ReminderEmailSpy();
		$email->akb_send_returns = false;

		$email->trigger_date_announced( $this->order->get_id(), 55, '', '2026-10-15' );

		self::assertSame( array(), $this->notes_matching( 'enviado al cliente' ) );
		self::assertCount( 1, $this->notes_matching( 'NO salió' ) );
	}

	/**
	 * La nota de supresión por ventana no es un envío: debe seguir existiendo.
	 *
	 * @testdox Dentro de la ventana del cap sigue quedando la nota de NO re-notificación.
	 */
	public function test_date_changed_inside_window_keeps_suppression_note(): void {
		$this->order->update_meta_data( MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT, time() );

		$email = new ReminderEmailSpy();

		$email->trigger_date_changed( $this->order->get_id(), 55, '2026-09-01', '2026-10-15' );

		self::assertCount( 0, $email->akb_sent );
		self::assertCount( 1, $this->notes_matching( 'NO re-notificado al cliente' ) );
	}

	// -----------------------------------------------------------------------
	// R3-2f7636fd (2026-09-19): el correo apagado en WooCommerce → Emails ya
	// no se pierde en silencio.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Cambio de fecha con el correo deshabilitado: no se envía, pero queda nota y no se marca el cap.
	 */
	public function test_date_changed_disabled_email_leaves_compensating_note(): void {
		$email          = new ReminderEmailSpy();
		$email->enabled = 'no';

		$email->trigger_date_changed( $this->order->get_id(), 55, '2026-09-01', '2026-10-15' );

		self::assertCount( 0, $email->akb_sent, 'Con el correo apagado, el transporte nunca debe llamarse.' );
		self::assertCount(
			1,
			$this->notes_matching( 'Email de cambio de fecha NO enviado al cliente: el correo' ),
			'Sin esta nota, la postergación se pierde sin dejar rastro en el pedido.'
		);
		self::assertSame(
			'',
			(string) $this->order->get_meta( MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT ),
			'La huella del cap NO se marca: un correo que nunca salió no puede contar como enviado.'
		);
	}

	/**
	 * @testdox Fecha anunciada con el correo deshabilitado: mismo tratamiento, con el copy propio del modo.
	 */
	public function test_date_announced_disabled_email_leaves_compensating_note(): void {
		$email          = new ReminderEmailSpy();
		$email->enabled = 'no';

		$email->trigger_date_announced( $this->order->get_id(), 55, '', '2026-10-15' );

		self::assertCount( 0, $email->akb_sent );
		self::assertCount( 1, $this->notes_matching( 'Email de fecha anunciada NO enviado al cliente: el correo' ) );
		self::assertSame( '', (string) $this->order->get_meta( MetaKeys::ORDER_DATE_ANNOUNCED_LAST_NOTIFIED_AT ) );
	}

	/**
	 * Reencender el correo no debe heredar un estado falso: la signature que
	 * quedó sin avisar por el correo apagado no se marca como notificada, así
	 * que el SIGUIENTE cambio de fecha (nueva signature, ya con el correo
	 * encendido) se notifica con normalidad — no queda «capado» por el intento
	 * mudo anterior.
	 *
	 * @testdox Tras un aviso perdido por correo apagado, el SIGUIENTE cambio de fecha sí se notifica al reencender.
	 */
	public function test_next_date_change_notifies_after_re_enabling_email(): void {
		$email          = new ReminderEmailSpy();
		$email->enabled = 'no';
		$email->trigger_date_changed( $this->order->get_id(), 55, '2026-09-01', '2026-10-15' );
		self::assertCount( 0, $email->akb_sent );

		$email->enabled = 'yes';
		$email->trigger_date_changed( $this->order->get_id(), 55, '2026-10-15', '2026-11-20' );

		self::assertCount( 1, $email->akb_sent, 'Reencendido el correo, el próximo cambio de fecha SÍ se envía.' );
		self::assertCount( 1, $this->notes_matching( 'Email de cambio de fecha enviado al cliente.' ) );
	}
}
