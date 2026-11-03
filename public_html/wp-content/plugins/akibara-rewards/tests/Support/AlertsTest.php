<?php
/**
 * Alertas al dueño cuando algo del programa necesita una mano.
 *
 * Hasta la 0.4.0 el código disparaba avisos con comentarios como «las alertas
 * del panel escuchan acá», y nadie escuchaba: un canje sin saldo, una reversa
 * corta o un reembolso borrado quedaban como nota en un pedido que nadie abría
 * (hallado el 2026-09-13 al escribir el manual del dueño).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Support\Alerts;
use Brain\Monkey\Functions;

/**
 * @since 0.4.1
 */
final class AlertsTest extends RewardsTestCase {

	private Alerts $alerts;

	private \Akibara_Rewards_Fake_WC $wc;

	/** @var array<string,mixed> */
	private array $opciones = array( 'admin_email' => 'dueno@example.com' );

	/** @var array<string,mixed> */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();

		$this->alerts = new Alerts();
		$this->wc     = $this->install_fake_wc();

		$opciones = &$this->opciones;
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) use ( &$opciones ) {
				return array_key_exists( $option, $opciones ) ? $opciones[ $option ] : $default_value;
			}
		);
		$transients = &$this->transients;
		// Cierre con referencia, no función flecha: la flecha copia el array al
		// crearse y nunca vería lo que escribe set_transient.
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( &$transients ) {
				return $transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$transients ): bool {
				$transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test/wp-admin/' . $path );
		Functions\when( 'wc_get_order_status_name' )->alias(
			static fn( string $status ): string => array(
				'completed'  => 'Completado',
				'processing' => 'Procesando',
			)[ $status ] ?? $status
		);
	}

	/** @testdox El aviso del premio de padrino nombra el estado que el dueño eligió en el panel. */
	public function test_el_aviso_de_padrino_nombra_el_estado_configurado(): void {
		$this->opciones['akb_rewards_referral_status'] = 'processing';
		$this->opciones['akb_rewards_alerts_enabled']  = 'yes';
		$order                                         = $this->make_order( 100, 7, 10000.0, 1900.0 );

		$this->alerts->on_referral_pay_failed( $order, 7, 500 );

		$cuerpo = implode( ' ', array_column( $this->wc->mailer()->sent, 'body' ) );
		$this->assertStringContainsString( 'vuelve a marcarlo como Procesando', $cuerpo );
	}

	private function pedido(): \WC_Order {
		return $this->make_order( 100, 7, 10000.0, 1900.0 );
	}

	/** @testdox Un canje sin saldo suficiente avisa al dueño con el pedido y la diferencia. */
	public function test_un_canje_sin_respaldo_avisa_al_dueno(): void {
		$this->alerts->on_redemption_shortfall( $this->pedido(), 1000, 400, 600 );

		$this->assertCount( 1, $this->wc->mailer()->sent );
		$correo = $this->wc->mailer()->sent[0];
		$this->assertSame( 'dueno@example.com', $correo['to'] );
		$this->assertStringContainsString( '#100', $correo['subject'] );
		$this->assertStringContainsString( '600', $correo['body'] );
		$this->assertStringContainsString( 'post.php?post=100', $correo['body'], 'Con el enlace directo al pedido.' );
	}

	/** @testdox El mismo aviso no se manda dos veces en el día. */
	public function test_el_mismo_aviso_no_se_repite(): void {
		$this->alerts->on_reversal_failed( $this->pedido(), 250 );
		$this->alerts->on_reversal_failed( $this->pedido(), 250 );

		$this->assertCount( 1, $this->wc->mailer()->sent, 'La reversa corre por el reembolso y por el cambio de estado: una sola alerta.' );
	}

	/** @testdox La invitación agotada avisa una vez al dueño y enlaza al pedido. */
	public function test_exhausted_review_invite_has_an_actionable_alert(): void {
		$order = $this->pedido();
		Functions\when( 'wc_get_order' )->justReturn( $order );
		$this->alerts->on_email_gave_up( \Akibara\Rewards\Reviews\Invitations::HOOK, array( 100, 3 ) );
		$this->alerts->on_email_gave_up( \Akibara\Rewards\Reviews\Invitations::HOOK, array( 100, 3 ) );
		$this->assertCount( 1, $this->wc->mailer()->sent );
		$this->assertStringContainsString( 'invitación a reseñar del pedido #100', $this->wc->mailer()->sent[0]['body'] );
		$this->assertStringContainsString( $order->get_edit_order_url(), $this->wc->mailer()->sent[0]['body'] );
	}

	/**
	 * `akb_rewards_reversal_failed` lo disparan TRES reversas del programa:
	 * pedido anulado, premio de padrino y —desde R4-144b3d33— una reseña con
	 * foto retirada. Antes de esta ficha, `on_reversal_failed()` sólo sabía
	 * de `\WC_Order` y una reseña quedaba SIN aviso al dueño.
	 *
	 * @testdox La reversa corta de una reseña con foto avisa al dueño con el enlace a esa reseña.
	 */
	public function test_la_reversa_corta_de_una_resena_avisa_al_dueno(): void {
		$review = new \WP_Comment(
			array(
				'comment_ID'      => 10,
				'comment_post_ID' => 777,
			)
		);

		$this->alerts->on_reversal_failed( $review, 250 );

		$this->assertCount( 1, $this->wc->mailer()->sent );
		$correo = $this->wc->mailer()->sent[0];
		$this->assertStringContainsString( '#10', $correo['subject'] );
		$this->assertStringContainsString( '250', $correo['body'] );
		$this->assertStringContainsString( 'comment.php?action=editcomment&c=10', $correo['body'], 'Con el enlace directo a la reseña.' );
	}

	/** @testdox La alerta queda en el registro de WooCommerce aunque el correo no salga. */
	public function test_la_alerta_queda_en_el_registro_aunque_el_correo_falle(): void {
		$this->wc->mailer()->send_result = false;

		$this->alerts->on_redemption_stuck( $this->pedido() );

		$this->assertStringContainsString( '#100', implode( ' ', array_column( $this->logger->entries, 'message' ) ), 'Si el correo del sitio está caído, la alerta no puede perderse con él.' );
	}

	/** @testdox Con las alertas apagadas no sale correo, pero el registro sí la anota. */
	public function test_apagadas_no_avisan(): void {
		$this->opciones['akb_rewards_alerts_enabled'] = 'no';

		$this->alerts->on_redemption_stuck( $this->pedido() );

		$this->assertSame( array(), $this->wc->mailer()->sent );
		$this->assertStringContainsString( '#100', implode( ' ', array_column( $this->logger->entries, 'message' ) ) );
	}

	/** @testdox Los destinatarios del panel reemplazan al correo del sitio. */
	public function test_los_destinatarios_del_panel_mandan(): void {
		$this->opciones['akb_rewards_alerts_email'] = 'contacto@akibara.cl, bodega@akibara.cl';

		$this->alerts->on_redemption_stuck( $this->pedido() );

		$this->assertSame( 'contacto@akibara.cl, bodega@akibara.cl', $this->wc->mailer()->sent[0]['to'] );
	}

	/**
	 * @testdox Cada alerta dice qué pasó y qué hacer ($caso).
	 * @dataProvider casos
	 *
	 * @param string   $caso     Nombre.
	 * @param callable $disparar Dispara la alerta.
	 * @param string   $debe     Frase que el correo debe contener.
	 */
	public function test_cada_alerta_explica_que_hacer( string $caso, callable $disparar, string $debe ): void {
		unset( $caso );
		$disparar( $this->alerts, $this->pedido() );

		$this->assertCount( 1, $this->wc->mailer()->sent );
		$this->assertStringContainsString( $debe, $this->wc->mailer()->sent[0]['body'] );
	}

	/** @return array<string,array{0:string,1:callable,2:string}> */
	public static function casos(): array {
		return array(
			'reversa corta'      => array( 'reversa', static fn( Alerts $a, \WC_Order $o ) => $a->on_reversal_failed( $o, 250 ), '250' ),
			'reembolso borrado'  => array( 'reembolso', static fn( Alerts $a, \WC_Order $o ) => $a->on_manual_review_needed( $o, 55 ), 'Otorgar o quitar puntos a mano' ),
			'canje atascado'     => array( 'atascado', static fn( Alerts $a, \WC_Order $o ) => $a->on_redemption_stuck( $o ), 'historial' ),
			'reseña sin premio'  => array(
				'reseña',
				static fn( Alerts $a ) => $a->on_review_pay_failed(
					new \WP_Comment(
						array(
							'comment_ID'      => 10,
							'comment_post_ID' => 777,
						)
					),
					7,
					500
				),
				'vuelve a aprobarla',
			),
			'correo que no sale' => array( 'correo', static fn( Alerts $a ) => $a->on_email_gave_up( 'akb_rewards_order_email_retry', array( 100, 3 ) ), 'correo del sitio' ),
			'premio de padrino'  => array( 'padrino', static fn( Alerts $a, \WC_Order $o ) => $a->on_referral_pay_failed( $o, 7, 500 ), 'Completado' ),
		);
	}

	/** @testdox Las siete alertas quedan colgadas. */
	public function test_register_cuelga_las_alertas(): void {
		$colgados = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$colgados ): void {
				$colgados[] = $hook;
			}
		);

		$this->alerts->register();

		foreach ( array( 'akb_rewards_redemption_shortfall', 'akb_rewards_reversal_failed', 'akb_rewards_manual_review_needed', 'akb_rewards_review_pay_failed', 'akb_rewards_redemption_stuck', 'akb_rewards_email_gave_up', 'akb_rewards_referral_pay_failed' ) as $hook ) {
			$this->assertContains( $hook, $colgados );
		}
	}
}
