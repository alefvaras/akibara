<?php
/**
 * Un aviso «ya llegó» que no sale porque el pedido ya salió tiene que dejar
 * rastro en el pedido.
 *
 * Hallazgo de la revisión de 1.116.8: el cron marca el pedido listo y agenda
 * el correo; antes del tick que lo despacha (cron de Hostinger cada 5 min) el
 * dueño marca «Listo para enviar» en Blue Express, que cuenta como salida. El
 * worker volvía en silencio, Action Scheduler daba la acción por completada y
 * nadie sabía que el cliente se quedó sin la noticia.
 *
 * Qué estados cuentan como salida NO se toca aquí: es decisión del dueño.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\ReadyEmailDispatcher;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Email;
use WC_Order;
use WC_Order_Item_Product;

require_once __DIR__ . '/ReadyToShipEmailSpy.php';

final class AvisoSuprimidoPorSalidaDejaNotaTest extends TestCase {

	private WC_Order $order;

	/** Doble de $wpdb: sólo GET_LOCK / RELEASE_LOCK y la lectura del marker. */
	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) );

		WC_Email::akb_reset_locale_counters();

		Functions\when( 'wc_get_logger' )->justReturn(
			new class() {
				/**
				 * @param array<string, mixed> $context
				 */
				public function warning( string $message, array $context = array() ): void {
					unset( $message, $context );
				}
			}
		);
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'trailingslashit' )->alias( static fn( string $path ): string => rtrim( $path, '/\\' ) . '/' );
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);
		Functions\when( 'wc_get_order_status_name' )->alias(
			static fn( string $status ): string => 'shipping-progress' === $status ? 'Listo para enviar' : ucfirst( $status )
		);

		// El mundo de producción: Blue Express declara su estado operativo como
		// activo Y como entrega al transportista (Integrations\Bluex\OrderPreventaAdapter).
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $valor ) {
				if ( 'akb_order_preventa_active_order_statuses' === $hook ) {
					return array( 'processing', 'shipping-progress' );
				}
				if ( 'akb_order_preventa_carrier_handoff_statuses' === $hook ) {
					return array( 'shipping-progress' );
				}

				return $valor;
			}
		);

		$item               = new WC_Order_Item_Product();
		$item->akb_id       = 7;
		$item->akb_quantity = 1;
		$item->akb_meta     = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );

		$this->order             = new WC_Order();
		$this->order->akb_id     = 27188;
		$this->order->akb_status = 'shipping-progress';
		$this->order->akb_items  = array( $item );
		$this->order->akb_meta   = array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' );

		$order = $this->order;
		Functions\when( 'wc_get_order' )->alias( static fn() => $order );

		$this->wpdb = new class() {
			/** @var array<int, string> */
			public array $locks = array();

			public string $prefix = 'wp_';

			public string $postmeta = 'wp_postmeta';

			public string $last_error = '';

			public function prepare( string $query, ...$args ): string {
				return $query . '|' . implode( ',', array_map( 'strval', $args ) );
			}

			public function get_var( string $query ) {
				if ( str_contains( $query, 'GET_LOCK' ) ) {
					$this->locks[] = 'acquire';
					return '1';
				}
				if ( str_contains( $query, 'RELEASE_LOCK' ) ) {
					$this->locks[] = 'release';
					return '1';
				}
				return null;
			}
		};
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Con el pedido en «Listo para enviar», el aviso no sale y queda UNA nota que lo dice. */
	public function test_a_departed_order_gets_a_note_instead_of_silence(): void {
		$email = new ReadyToShipEmailSpy();

		$email->trigger( $this->order->get_id() );

		self::assertSame( array(), $email->akb_sent, 'No cambia qué estados cuentan como salida: el correo sigue sin salir.' );
		self::assertCount( 1, $this->order->akb_notes );
		self::assertStringContainsString( 'NO enviado', $this->order->akb_notes[0] );
		self::assertStringContainsString( '«Listo para enviar»', $this->order->akb_notes[0] );
		self::assertSame( 'yes', $this->order->get_meta( MetaKeys::ORDER_READY_EMAIL_DEPARTED_NOTED ) );
		// La nota se escribe bajo el candado de despacho, y lo suelta: dos
		// trabajadores a la vez leían la marca vacía y anotaban los dos
		// (revisión independiente de 1.116.9).
		self::assertSame( array( 'acquire', 'release' ), $this->wpdb->locks );
	}

	/**
	 * Hallazgo S-810de482 (reapertura 2026-09-19): el candado por sí solo no
	 * basta si se anota contra el objeto cargado ANTES de pedirlo. El
	 * trabajador B pasa `can_trigger_for_order()` con la marca vacía y se
	 * queda esperando el candado mientras A anota + marca + libera; si B
	 * anota contra su copia rancia (en memoria, sin la marca), repite la
	 * nota. `trigger()` releerá el pedido DESPUÉS de tomar el candado.
	 *
	 * @testdox Una nota rancia no se repite si otro trabajador ya anotó mientras se esperaba el candado.
	 */
	public function test_a_stale_read_does_not_repeat_the_note_after_the_lock(): void {
		$rancio = $this->order;

		// El pedido «fresco»: mismo pedido, pero con la marca YA puesta por
		// el trabajador A mientras B esperaba el candado.
		$fresco              = new WC_Order();
		$fresco->akb_id      = $rancio->get_id();
		$fresco->akb_status  = $rancio->get_status();
		$fresco->akb_items   = $rancio->akb_items;
		$fresco->akb_meta    = $rancio->akb_meta;
		$fresco->akb_meta[ MetaKeys::ORDER_READY_EMAIL_DEPARTED_NOTED ] = 'yes';

		$llamadas = 0;
		Functions\when( 'wc_get_order' )->alias(
			function () use ( $rancio, $fresco, &$llamadas ) {
				++$llamadas;
				// Primera lectura (fuera del candado, en trigger()): la copia
				// rancia. Segunda en adelante (dentro del candado): la fresca.
				return 1 === $llamadas ? $rancio : $fresco;
			}
		);

		( new ReadyToShipEmailSpy() )->trigger( $rancio->get_id() );

		self::assertSame(
			array(),
			$fresco->akb_notes,
			'El objeto fresco ya llevaba la marca de A: no debía anotar de nuevo.'
		);
		self::assertSame(
			array(),
			$rancio->akb_notes,
			'Anotar contra la copia rancia es exactamente el defecto: debía releerse dentro del candado.'
		);
	}

	/** @testdox Reintentar o volver a disparar el despacho no repite la nota. */
	public function test_the_note_is_written_once(): void {
		$email = new ReadyToShipEmailSpy();

		$email->trigger( $this->order->get_id() );
		$email->trigger( $this->order->get_id() );
		$email->trigger( $this->order->get_id() );

		self::assertCount( 1, $this->order->akb_notes );
	}

	/** @testdox En «Procesando» el aviso sale como siempre y no deja nota de salida. */
	public function test_a_processing_order_still_sends_without_the_note(): void {
		$this->order->akb_status = 'processing';

		$email = new ReadyToShipEmailSpy();
		$email->trigger( $this->order->get_id() );

		self::assertCount( 1, $email->akb_sent );
		self::assertSame( '', $this->order->get_meta( MetaKeys::ORDER_READY_EMAIL_DEPARTED_NOTED ) );
	}

	/** @testdox Si el aviso ya se había enviado, un estado de salida no tiene nada que anotar. */
	public function test_an_already_notified_order_gets_no_note(): void {
		$this->order->update_meta_data( MetaKeys::ORDER_READY_EMAIL_SENT, (string) time() );

		( new ReadyToShipEmailSpy() )->trigger( $this->order->get_id() );

		self::assertSame( array(), $this->order->akb_notes );
	}

	/** @testdox Un pedido completado sin preventa pendiente tampoco deja nota: no había aviso que dar. */
	public function test_an_order_without_outstanding_preventa_gets_no_note(): void {
		$this->order->akb_status   = 'completed';
		$this->order->akb_refunded = array( 7 => 1 );

		( new ReadyToShipEmailSpy() )->trigger( $this->order->get_id() );

		self::assertSame( array(), $this->order->akb_notes );
	}

	/** @testdox El reenvío manual borra la marca: si vuelve a chocar con el estado, se vuelve a decir. */
	public function test_a_manual_resend_rearms_the_note(): void {
		( new ReadyToShipEmailSpy() )->trigger( $this->order->get_id() );
		self::assertSame( 'yes', $this->order->get_meta( MetaKeys::ORDER_READY_EMAIL_DEPARTED_NOTED ) );

		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );

		( new ReadyEmailDispatcher() )->handle_resend_ready_email_action( $this->order );

		self::assertSame( '', $this->order->get_meta( MetaKeys::ORDER_READY_EMAIL_DEPARTED_NOTED ) );
	}
}
