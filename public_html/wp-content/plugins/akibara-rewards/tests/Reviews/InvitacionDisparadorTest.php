<?php
/**
 * La invitación a reseñar sale con cualquier estado disparador.
 *
 * Hallado el 2026-09-13 al escribir el manual del dueño. El job, al correr,
 * exigía que el pedido SIGUIERA en el estado disparador. Con «Completado» (el
 * valor de fábrica) eso se cumple; con «Procesando» o «Listo para enviar», tres
 * días después el pedido casi siempre ya avanzó y la invitación no salía nunca.
 * Y un pedido con preventa, que sólo se puede invitar al completarse, no se
 * invitaba jamás con un disparador anterior — la ayuda del panel prometía lo
 * contrario («las preventas siempre esperan su entrega»).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Invitations;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * @since 0.4.1
 */
final class InvitacionDisparadorTest extends RewardsTestCase {

	private Invitations $invitations;

	private \Akibara_Rewards_Fake_WC $wc;

	/** @var array<int,array{hook:string,args:array<int,mixed>,ts:int}> */
	private array $scheduled = array();

	/** @var array<int,\WC_Order> */
	private array $orders = array();

	/** @var array<string,mixed> */
	private array $opciones = array();

	/**
	 * Veredicto de la compuerta de preventa de akibara-business. La suite
	 * carga la clase real (autoload de business): se gobierna por su filtro
	 * público, igual que la gobernaría otro plugin.
	 */
	private bool $permitir = true;

	protected function setUp(): void {
		parent::setUp();

		Filters\expectApplied( 'akb_order_preventa_can_request_review' )
			->zeroOrMoreTimes()
			->andReturnUsing( fn(): bool => $this->permitir );

		$this->invitations = new Invitations();
		$this->wc          = $this->install_fake_wc();

		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $ts, string $hook, array $args = array() ): int {
				$this->scheduled[] = array(
					'hook' => $hook,
					'args' => $args,
					'ts'   => $ts,
				);
				return count( $this->scheduled );
			}
		);
		Functions\when( 'as_next_scheduled_action' )->alias(
			function ( string $hook, ?array $args = null ) {
				foreach ( $this->scheduled as $job ) {
					if ( $job['hook'] === $hook && ( null === $args || $job['args'] === $args ) ) {
						return $job['ts'];
					}
				}
				return false;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		Functions\when( 'wp_generate_password' )->justReturn( str_repeat( 'Tk', 20 ) );
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $this->orders[ (int) $id ] ?? false );

		$opciones = &$this->opciones;
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) use ( &$opciones ) {
				return array_key_exists( $option, $opciones ) ? $opciones[ $option ] : $default_value;
			}
		);
	}

	/**
	 * Pedido de un tomo en el estado pedido.
	 *
	 * @param int    $id     Pedido.
	 * @param string $status Estado.
	 */
	private function pedido( int $id, string $status ): \WC_Order {
		$order                     = $this->make_order( $id, 42, 10000.0, 1900.0 );
		$order->status             = $status;
		$order->date_paid          = new \WC_DateTime( '2026-10-01 12:00:00' );
		$order->billing_email      = 'cliente@example.com';
		$order->items['line_item'] = array( new \WC_Order_Item_Fake( 777, 'Atelier of Witch Hat 15' ) );
		$this->orders[ $id ]       = $order;

		return $order;
	}

	/** @testdox Con disparador «Procesando», la invitación sale aunque el pedido ya se haya completado. */
	public function test_con_disparador_procesando_sale_aunque_el_pedido_ya_avanzo(): void {
		$this->opciones['akb_rewards_invite_status'] = 'processing';
		$order = $this->pedido( 100, 'processing' );

		$this->invitations->on_status_changed( 100, 'pending', 'processing' );
		$this->assertCount( 1, $this->scheduled );

		$order->status = 'completed';
		$this->invitations->on_status_changed( 100, 'processing', 'completed' );
		$this->assertCount( 1, $this->scheduled, 'Completarse no agenda un segundo job.' );

		$this->invitations->send_invite( 100 );

		$this->assertNotSame( '', (string) $order->get_meta( Invitations::META_SENT ), 'Tres días después el pedido ya está completado, y la invitación sale igual.' );
	}

	/** @testdox Una preventa se invita al completarse aunque el disparador sea «Listo para enviar». */
	public function test_la_preventa_se_invita_al_completarse(): void {
		$this->opciones['akb_rewards_invite_status'] = 'shipping-progress';
		$this->permitir                              = false;
		$order                                       = $this->pedido( 100, 'shipping-progress' );

		$this->invitations->on_status_changed( 100, 'processing', 'shipping-progress' );
		$this->assertSame( array(), $this->scheduled, 'La preventa espera su entrega.' );

		$this->permitir = true;
		$order->status  = 'completed';
		$this->invitations->on_status_changed( 100, 'shipping-progress', 'completed' );
		$this->assertCount( 1, $this->scheduled, 'Entregada, se invita.' );

		$this->invitations->send_invite( 100 );
		$this->assertNotSame( '', (string) $order->get_meta( Invitations::META_SENT ) );
	}

	/** @testdox Un pedido que llega a Completado sin pasar por el disparador también se invita. */
	public function test_un_pedido_que_salta_el_disparador_se_invita_al_completarse(): void {
		$this->opciones['akb_rewards_invite_status'] = 'shipping-progress';
		$this->pedido( 100, 'completed' );

		$this->invitations->on_status_changed( 100, 'processing', 'completed' );

		$this->assertCount( 1, $this->scheduled );
	}

	/** @testdox Un pedido anulado no se invita aunque su job siga en la cola. */
	public function test_un_pedido_anulado_no_se_invita(): void {
		$this->opciones['akb_rewards_invite_status'] = 'processing';
		$order = $this->pedido( 100, 'processing' );
		$this->invitations->on_status_changed( 100, 'pending', 'processing' );

		$order->status = 'cancelled';
		$this->invitations->send_invite( 100 );

		$this->assertSame( '', (string) $order->get_meta( Invitations::META_SENT ) );
		$this->assertSame( array(), $this->wc->mailer()->sent );
	}

	/** @testdox Un pedido que vuelve a «En espera» no se invita. */
	public function test_un_pedido_en_espera_no_se_invita(): void {
		$this->opciones['akb_rewards_invite_status'] = 'processing';
		$order = $this->pedido( 100, 'processing' );
		$this->invitations->on_status_changed( 100, 'pending', 'processing' );

		$order->status = 'on-hold';
		$this->invitations->send_invite( 100 );

		$this->assertSame( array(), $this->wc->mailer()->sent, 'Sólo se invita un pedido pagado.' );
	}
}
