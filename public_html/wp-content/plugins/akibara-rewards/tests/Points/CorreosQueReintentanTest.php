<?php
/**
 * Correos del programa que se reintentan solos, y el que faltaba.
 *
 * Hallado el 2026-09-13 al escribir el manual del dueño:
 *
 *   - El padrino no recibía ningún correo cuando ganaba: el aviso
 *     `akb_rewards_referral_paid` no tenía oyente.
 *   - El correo de una reseña premiada que no salía se perdía para siempre.
 *   - El de puntos por pedido sólo se reenviaba si alguien volvía a marcar el
 *     pedido a mano.
 *   - El aviso de vencimiento podía llegar dos veces: el sello del mes lo
 *     escribe el correo, y dos acciones encoladas antes de que corriera la
 *     primera mandaban las dos.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\Emails;
use Akibara\Rewards\Points\Expiry;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Points\Referrals;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.4.1
 */
final class CorreosQueReintentanTest extends RewardsTestCase {

	private Emails $emails;

	private \Akibara_Rewards_Fake_WC $wc;

	/** @var array<int,array{hook:string,args:array<int,mixed>,ts:int}> */
	private array $agendados = array();

	/** @var array<int,\WC_Order> */
	private array $orders = array();

	/** @var array<int,\WP_Comment> */
	private array $comments = array();

	protected function setUp(): void {
		parent::setUp();

		$this->emails = new Emails();
		$this->wc     = $this->install_fake_wc();

		$padrino             = new \WP_User( 7 );
		$padrino->user_email = 'padrino@example.com';

		Functions\when( 'get_user_by' )->alias( static fn( string $by, $id ) => 7 === (int) $id ? $padrino : false );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'get_the_title' )->justReturn( 'Berserk 42' );
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $this->orders[ (int) $id ] ?? false );
		Functions\when( 'get_comment' )->alias( fn( $id ) => $this->comments[ (int) $id ] ?? null );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $ts, string $hook, array $args = array() ): int {
				$this->agendados[] = array(
					'hook' => $hook,
					'args' => $args,
					'ts'   => $ts,
				);
				return count( $this->agendados );
			}
		);
	}

	/** @testdox El padrino recibe un correo cuando su invitado le hace ganar puntos. */
	public function test_el_padrino_recibe_un_correo_cuando_gana(): void {
		Ledger::credit( 7, 500, 'referral', 'user', 42 );
		$this->wpdb->user_meta[42][ Referrals::META_REFERRAL_ORDER ] = 100;
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ]            = 1200;

		$pedido                     = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$pedido->billing_email      = 'invitada@example.com';
		$pedido->billing_first_name = 'Valentina';

		$this->emails->send_referral_email( 7, 42, 500, $pedido );

		$this->assertCount( 1, $this->wc->mailer()->sent );
		$correo = $this->wc->mailer()->sent[0];
		$this->assertSame( 'padrino@example.com', $correo['to'] );
		$this->assertStringContainsString( '500', $correo['subject'] );
		$this->assertStringContainsString( '1.200', $correo['body'], 'Con el saldo real.' );
		$this->assertStringContainsString( 'mi-cuenta/rewards', $correo['body'], 'Con el botón a su saldo.' );
		$this->assertStringNotContainsString( 'Valentina', $correo['body'], 'Al padrino no se le cuentan los datos de quien compró.' );
		$this->assertStringNotContainsString( 'invitada@example.com', $correo['body'] );

		$this->emails->send_referral_email( 7, 42, 500, $pedido );
		$this->assertCount( 1, $this->wc->mailer()->sent, 'Sellado: el mismo premio no se avisa dos veces.' );
	}

	/** @testdox Un premio que ya se revirtió no se avisa. */
	public function test_no_se_avisa_un_premio_que_ya_se_revirtio(): void {
		$this->emails->send_referral_email( 7, 42, 500, $this->make_order( 100, 42, 10000.0, 1900.0 ) );

		$this->assertSame( array(), $this->wc->mailer()->sent );
	}

	/** @testdox Un premio que se descontó en parte ya no se anuncia como ganado. */
	public function test_no_se_avisa_un_premio_con_reversa_corta(): void {
		Ledger::credit( 7, 500, 'referral', 'user', 42 );
		Ledger::debit( 7, 300, 'referral_reversal', 'user', 42 );
		// La reversa corta conserva la marca del pedido a propósito.
		$this->wpdb->user_meta[42][ Referrals::META_REFERRAL_ORDER ] = 100;

		$this->emails->retry_referral_email( 7, 42, 500, 100, 2 );

		$this->assertSame( array(), $this->wc->mailer()->sent );
	}

	/** @testdox El correo al padrino que no sale se reintenta y, al tercer fallo, avisa al dueño. */
	public function test_el_correo_al_padrino_se_reintenta_y_al_tercer_fallo_avisa(): void {
		Ledger::credit( 7, 500, 'referral', 'user', 42 );
		$this->wpdb->user_meta[42][ Referrals::META_REFERRAL_ORDER ] = 100;
		$this->wc->mailer()->send_result                             = false;

		$this->emails->send_referral_email( 7, 42, 500, $this->make_order( 100, 42, 10000.0, 1900.0 ) );

		$this->assertCount( 1, $this->agendados );
		$this->assertSame( Emails::HOOK_RETRY_REFERRAL, $this->agendados[0]['hook'] );
		$this->assertSame( array( 7, 42, 500, 100, 2 ), $this->agendados[0]['args'] );
		$this->assertGreaterThanOrEqual( time() + 6 * HOUR_IN_SECONDS - 5, $this->agendados[0]['ts'] );

		$this->emails->retry_referral_email( 7, 42, 500, 100, 2 );
		$this->assertCount( 2, $this->agendados );

		\Brain\Monkey\Actions\expectDone( 'akb_rewards_email_gave_up' )->once();
		$this->emails->retry_referral_email( 7, 42, 500, 100, 3 );
		$this->assertCount( 2, $this->agendados, 'Tres intentos y no más.' );
	}

	/** @testdox El correo de una reseña premiada que no sale se reintenta solo. */
	public function test_el_correo_de_resena_se_reintenta(): void {
		$resena             = new \WP_Comment(
			array(
				'comment_ID'      => 10,
				'comment_post_ID' => 777,
				'user_id'         => 7,
			)
		);
		$this->comments[10] = $resena;

		$this->wc->mailer()->send_result = false;
		$this->emails->send_review_email( $resena, 7, 500 );

		$this->assertCount( 1, $this->agendados );
		$this->assertSame( Emails::HOOK_RETRY_REVIEW, $this->agendados[0]['hook'] );
		$this->assertSame( array( 10, 7, 500, 2 ), $this->agendados[0]['args'] );

		$this->wc->mailer()->send_result = true;
		$this->emails->retry_review_email( 10, 7, 500, 2 );

		$this->assertNotSame( '', (string) get_comment_meta( 10, '_akb_rewards_review_email_sent', true ), 'El reintento sella al confirmar el transporte.' );
		$this->assertCount( 1, $this->agendados, 'Enviado: no se agenda otro.' );
	}

	/** @testdox El correo de puntos de un pedido se reintenta sin que nadie toque el pedido. */
	public function test_el_correo_de_puntos_del_pedido_se_reintenta_solo(): void {
		$pedido                                    = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$pedido->billing_email                     = 'cliente@example.com';
		$pedido->meta[ Accrual::META_EARNED ]      = 238;
		$this->orders[100]                         = $pedido;
		$this->wc->mailer()->send_result           = false;

		$this->emails->send_order_email( $pedido, 7, 238 );

		$this->assertStringContainsString( 'NO pudo enviarse', implode( ' ', $pedido->notes ) );
		$this->assertCount( 1, $this->agendados );
		$this->assertSame( Emails::HOOK_RETRY_ORDER, $this->agendados[0]['hook'] );
		$this->assertSame( array( 100, 2 ), $this->agendados[0]['args'] );

		$this->wc->mailer()->send_result = true;
		$this->emails->retry_order_email( 100, 2 );

		$this->assertArrayHasKey( Emails::META_EMAIL_SENT, $pedido->saved_meta );
	}

	/** @testdox El aviso de vencimiento sale una vez al mes aunque haya dos acciones en la cola. */
	public function test_el_aviso_de_vencimiento_no_se_repite_en_el_mes(): void {
		$mes = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) ) )->format( 'Y-m' );
		$this->wpdb->user_meta[7][ Expiry::META_WARNED ] = $mes;

		$this->emails->send_expiry_warning( 7, 500, 30 );

		$this->assertSame( array(), $this->wc->mailer()->sent );
	}
}
