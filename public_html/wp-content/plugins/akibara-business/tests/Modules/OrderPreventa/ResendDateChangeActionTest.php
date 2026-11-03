<?php
/**
 * Acción de pedido «Reenviar aviso de cambio de fecha»
 * (R3-reenvio-aviso-de-fecha, decisión del dueño 2026-09-20).
 *
 * Fija: cuándo se ofrece (sólo con un cambio de fecha registrado y vigente),
 * qué cambio reenvía (el último cuyo `new` coincide con la fecha actual de una
 * línea pendiente), que suelta el tope de frecuencia y su contador, que deja
 * la nota de traza y que dispara el MISMO evento que el envío automático —la
 * única puerta que decide si el correo sale—, y que sin cambio vigente no
 * dispara nada y lo dice.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\ReadyEmailDispatcher;
use Akibara\Business\Modules\Stock\Availability;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;

final class ResendDateChangeActionTest extends TestCase {

	private const ACCION = 'akb_resend_preventa_date_change';

	/** @var array<int, string> fecha de salida actual por producto */
	private array $fechas = array();

	/** @var array<int, array<int, mixed>> eventos disparados con do_action */
	private array $eventos = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->fechas  = array();
		$this->eventos = array();
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key = '', $single = false ) => Availability::META_RELEASE_DATE === $key ? ( $this->fechas[ (int) $id ] ?? '' ) : '' );
		// `FulfillmentCapabilities::waiting_order_statuses()` pasa por el filtro y
		// sanea cada estado: sin estos dos dobles la puerta de «pedido en espera»
		// no se puede ejercer.
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value ) => $value );
		Functions\when( 'get_option' )->alias( static fn( $key, $default_value = false ) => $default_value );
		Functions\when( 'do_action' )->alias( function ( string $hook, ...$args ): void {
			$this->eventos[] = array_merge( array( $hook ), $args );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, int> $productos Productos de preventa pendientes.
	 */
	private function pedido( array $productos, string $sigs ): WC_Order {
		$order           = new WC_Order();
		$order->akb_id   = 4242;
		$order->akb_meta = array(
			MetaKeys::ORDER_HAS_PREVENTA               => 'yes',
			MetaKeys::ORDER_DATE_CHANGE_SIGS           => $sigs,
			MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT => 1789900000,
			MetaKeys::ORDER_DATE_CHANGE_SEND_ATTEMPTS  => 2,
		);
		$n               = 0;
		foreach ( $productos as $pid ) {
			$item                 = new WC_Order_Item_Product();
			$item->akb_id         = 10 + $n++;
			$item->akb_product_id = $pid;
			$item->akb_quantity   = 1;
			$item->akb_meta       = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
			$order->akb_items[]   = $item;
		}
		return $order;
	}

	/** @testdox Se ofrece junto al aviso previo cuando el último cambio de fecha sigue vigente */
	public function test_se_ofrece_cuando_hay_un_cambio_vigente(): void {
		$this->fechas[501] = '2026-11-20';
		$acciones          = ( new ReadyEmailDispatcher() )->register_order_actions( array(), $this->pedido( array( 501 ), '2026-10-15__2026-11-20' ) );

		self::assertSame( 'Reenviar aviso de cambio de fecha', $acciones[ self::ACCION ] ?? null );
		self::assertArrayNotHasKey( 'akb_resend_preventa_reminder', $acciones, 'Sin arribo estimado no hay «llega pronto» que reenviar; el aviso previo tiene su propia ventana (ver test_el_aviso_previo_respeta_su_ventana).' );
	}

	/** @testdox No se ofrece si la fecha volvió a cambiar o se retiró, si el pedido ya está listo, o si nunca hubo cambio */
	public function test_no_se_ofrece_sin_cambio_vigente(): void {
		$d = new ReadyEmailDispatcher();

		$this->fechas[501] = '2026-12-01';
		self::assertArrayNotHasKey( self::ACCION, $d->register_order_actions( array(), $this->pedido( array( 501 ), '2026-10-15__2026-11-20' ) ), 'La fecha de hoy no es la de la firma: reenviarla mentiría.' );

		$this->fechas[501] = '';
		self::assertArrayNotHasKey( self::ACCION, $d->register_order_actions( array(), $this->pedido( array( 501 ), '2026-10-15__2026-11-20,2026-11-20__' ) ), 'Fecha retirada.' );

		$this->fechas[501] = '2026-11-20';
		self::assertArrayNotHasKey( self::ACCION, $d->register_order_actions( array(), $this->pedido( array( 501 ), '__2026-11-20' ) ), 'Una fecha anunciada no es un cambio de fecha.' );
		self::assertArrayNotHasKey( self::ACCION, $d->register_order_actions( array(), $this->pedido( array( 501 ), '' ) ), 'Sin cambios registrados.' );

		$listo = $this->pedido( array( 501 ), '2026-10-15__2026-11-20' );
		$listo->update_meta_data( MetaKeys::ORDER_READY_TO_SHIP, 'yes' );
		self::assertArrayNotHasKey( self::ACCION, $d->register_order_actions( array(), $listo ), 'Ya llegó: no hay fecha que avisar.' );
	}

	/** @testdox Un pedido cancelado o sin correo no ofrece los avisos de espera y, si se fuerza, lo dice sin fingir */
	public function test_pedido_que_no_espera(): void {
		$this->fechas[501] = '2026-11-20';
		$d                 = new ReadyEmailDispatcher();

		$cancelado             = $this->pedido( array( 501 ), '2026-10-15__2026-11-20' );
		$cancelado->akb_status = 'cancelled';
		$acciones              = $d->register_order_actions( array(), $cancelado );
		self::assertArrayNotHasKey( self::ACCION, $acciones );
		self::assertArrayNotHasKey( 'akb_resend_preventa_reminder', $acciones, 'El aviso previo tampoco: el correo lo cortaría en silencio.' );

		$d->handle_resend_date_change_email_action( $cancelado );
		self::assertSame( array(), $this->eventos );
		self::assertSame( 1789900000, $cancelado->akb_meta[ MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT ] );
		self::assertStringContainsString( 'NO posible: el pedido ya no espera', $cancelado->akb_notes[0] ?? '' );

		$d->handle_resend_reminder_email_action( $cancelado );
		self::assertSame( array(), $this->eventos );
		self::assertStringContainsString( 'aviso previo a la llegada NO posible', $cancelado->akb_notes[1] ?? '' );

		$sin_correo                    = $this->pedido( array( 501 ), '2026-10-15__2026-11-20' );
		$sin_correo->akb_billing_email = '';
		self::assertArrayNotHasKey( self::ACCION, $d->register_order_actions( array(), $sin_correo ) );

		// Entre el desplegable y el POST el pedido pasó a «listo»: el handler no
		// borra el tope ni finge la solicitud.
		$listo = $this->pedido( array( 501 ), '2026-10-15__2026-11-20' );
		$listo->update_meta_data( MetaKeys::ORDER_READY_TO_SHIP, 'yes' );
		$this->eventos = array();
		$d->handle_resend_date_change_email_action( $listo );
		self::assertSame( array(), $this->eventos );
		self::assertSame( 1789900000, $listo->akb_meta[ MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT ] );
		self::assertStringContainsString( 'ya no espera', $listo->akb_notes[0] ?? '' );
	}

	/** @testdox Dos tomos con la misma fecha nueva: no se ofrece ni se reenvía (no se sabe cuál cambió) */
	public function test_dos_tomos_con_la_misma_fecha_no_se_reenvian(): void {
		$this->fechas[501] = '2026-11-20';
		$this->fechas[502] = '2026-11-20';
		$d                 = new ReadyEmailDispatcher();
		$order             = $this->pedido( array( 501, 502 ), '2026-10-15__2026-11-20' );

		self::assertArrayNotHasKey( self::ACCION, $d->register_order_actions( array(), $order ) );
		$d->handle_resend_date_change_email_action( $order );
		self::assertSame( array(), $this->eventos );
		self::assertStringContainsString( 'dos tomos del pedido comparten esa fecha', $order->akb_notes[0] ?? '' );
	}

	/** @testdox Sólo cuenta la ÚLTIMA firma: tras una fecha anunciada o retirada no hay cambio que reenviar */
	public function test_solo_cuenta_la_ultima_firma(): void {
		$this->fechas[501] = '2026-11-20';
		$d                 = new ReadyEmailDispatcher();

		self::assertArrayNotHasKey( self::ACCION, $d->register_order_actions( array(), $this->pedido( array( 501 ), '2026-10-15__2026-11-20,2026-11-20__,__2026-11-20' ) ), 'Retirada y vuelta a anunciar la misma fecha: el cliente ya recibió «fecha anunciada».' );
		self::assertArrayNotHasKey( self::ACCION, $d->register_order_actions( array(), $this->pedido( array( 501 ), '2026-10-15__2026-11-20,2026-11-20__' ) ), 'Retirada al final.' );
		self::assertArrayHasKey( self::ACCION, $d->register_order_actions( array(), $this->pedido( array( 501 ), '__2026-10-15,2026-10-15__2026-11-20' ) ), 'Anunciada y luego postergada: la postergación es la última y rige.' );
	}

	/** @testdox Reenvía el ÚLTIMO cambio vigente, resolviendo el producto por su fecha actual */
	public function test_reenvia_el_ultimo_cambio_vigente(): void {
		$this->fechas[501] = '2026-11-20';
		$this->fechas[502] = '2027-01-10';
		$order             = $this->pedido( array( 501, 502 ), '2026-10-15__2026-11-20,2026-12-01__2027-01-10' );

		( new ReadyEmailDispatcher() )->handle_resend_date_change_email_action( $order );

		self::assertSame( array( array( 'akb_order_preventa_date_changed', 4242, 502, '2026-12-01', '2027-01-10' ) ), $this->eventos, 'El mismo evento que el envío automático, con el cambio más reciente.' );
		self::assertArrayNotHasKey( MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT, $order->akb_meta, 'El tope de frecuencia no manda sobre un reenvío pedido a mano.' );
		self::assertArrayNotHasKey( MetaKeys::ORDER_DATE_CHANGE_SEND_ATTEMPTS, $order->akb_meta );
		self::assertSame( 1, $order->akb_saves, 'Las marcas se sueltan ANTES de disparar el evento.' );
		self::assertSame( array( 'Reenvío manual del aviso de cambio de fecha solicitado desde Acciones del pedido (producto #502: 2026-12-01 → 2027-01-10).' ), $order->akb_notes );
	}

	/** @testdox Sin cambio vigente no dispara nada y lo dice en el pedido */
	public function test_sin_cambio_vigente_no_dispara_y_lo_dice(): void {
		$this->fechas[501] = '2026-12-25';
		$order             = $this->pedido( array( 501 ), '2026-10-15__2026-11-20' );

		( new ReadyEmailDispatcher() )->handle_resend_date_change_email_action( $order );

		self::assertSame( array(), $this->eventos );
		self::assertSame( 1789900000, $order->akb_meta[ MetaKeys::ORDER_DATE_CHANGE_LAST_NOTIFIED_AT ], 'Nada que reenviar: las marcas se respetan.' );
		self::assertCount( 1, $order->akb_notes );
		self::assertStringContainsString( 'NO posible', $order->akb_notes[0] );
	}

	/** @testdox «Reenviar aviso previo» sólo se ofrece (y sólo sale) dentro de la ventana de 1 a 10 días; fuera, deja nota */
	public function test_el_aviso_previo_respeta_su_ventana(): void {
		$this->fechas[501] = '2026-11-20';
		$d                 = new ReadyEmailDispatcher();

		$cerca = $this->pedido( array( 501 ), '' );
		$cerca->update_meta_data( MetaKeys::ORDER_SUMMARY_MAX, time() + 5 * DAY_IN_SECONDS );
		self::assertArrayHasKey( 'akb_resend_preventa_reminder', $d->register_order_actions( array(), $cerca ) );

		$lejos = $this->pedido( array( 501 ), '' );
		$lejos->update_meta_data( MetaKeys::ORDER_SUMMARY_MAX, time() + 150 * DAY_IN_SECONDS );
		self::assertArrayNotHasKey( 'akb_resend_preventa_reminder', $d->register_order_actions( array(), $lejos ), '«Llega pronto» a cinco meses vista no es verdad.' );
		$d->handle_resend_reminder_email_action( $lejos );
		self::assertSame( array(), $this->eventos );
		self::assertStringContainsString( 'fuera de la ventana de 1 a 10 días', $lejos->akb_notes[0] ?? '' );

		$sin_estimado = $this->pedido( array( 501 ), '' );
		self::assertArrayNotHasKey( 'akb_resend_preventa_reminder', $d->register_order_actions( array(), $sin_estimado ) );
	}

	/** @testdox Algo que no es un pedido no hace nada */
	public function test_sin_pedido_no_hace_nada(): void {
		( new ReadyEmailDispatcher() )->handle_resend_date_change_email_action( null );
		self::assertSame( array(), $this->eventos );
	}
}
