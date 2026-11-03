<?php
/**
 * Contrato del correo consolidado de puntos.
 *
 * Fija las dos lecciones de agosto: UN correo por pedido (compra + bono en el
 * mismo cuerpo, nunca dos correos en el mismo segundo) y el sellado SOLO si
 * el transporte confirmó (los 3 días sin correo se perdieron porque el plugin
 * viejo sellaba a ciegas).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Accrual;
use Akibara\Rewards\Points\Emails;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.1.0
 */
final class EmailsTest extends RewardsTestCase {

	private Emails $emails;

	private \Akibara_Rewards_Fake_WC $wc;

	protected function setUp(): void {
		parent::setUp();

		$this->emails = new Emails();
		$this->wc     = $this->install_fake_wc();

		Functions\when( 'get_page_by_path' )->justReturn( null );
	}

	/**
	 * Pedido acreditado típico: 238 de compra + 500 de bono.
	 */
	private function awarded_order(): \WC_Order {
		$order                = $this->make_order( 100, 7, 10000.0, 1900.0 );
		$order->billing_email = 'cliente@example.com';
		$order->billing_first_name = 'Camila';
		$order->meta[ Accrual::META_EARNED ]        = 738;
		$order->meta[ Accrual::META_WELCOME_BONUS ] = 500;

		return $order;
	}

	public function test_one_email_carries_purchase_and_bonus_together(): void {
		// Saldo DISTINTO del total del pedido: si fueran iguales, la línea de
		// saldo del correo quedaría sin cubrir (hallazgo BAJA de la célula).
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 941;

		$order = $this->awarded_order();
		$this->emails->send_order_email( $order, 7, 238 );

		$this->assertCount( 1, $this->wc->the_mailer->sent );
		$mail = $this->wc->the_mailer->sent[0];

		$this->assertSame( 'cliente@example.com', $mail['to'] );
		$this->assertStringContainsString( '738', $mail['subject'] );
		$this->assertStringContainsString( '238', $mail['body'] );
		$this->assertStringContainsString( 'bono de bienvenida de 500', $mail['body'] );
		$this->assertStringContainsString( '941', $mail['body'], 'El saldo del correo es el saldo REAL, no el total del pedido.' );
		$this->assertStringContainsString( 'Camila', $mail['body'] );

		// Sellado: el transporte confirmó.
		$this->assertArrayHasKey( Emails::META_EMAIL_SENT, $order->saved_meta );
	}

	public function test_transport_failure_leaves_the_order_retryable(): void {
		$this->wc->mailer()->send_result = false;

		$agendados = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( int $ts, string $hook, array $args = array() ) use ( &$agendados ): int {
				$agendados[] = array( $hook, $args );
				return 1;
			}
		);

		$order = $this->awarded_order();
		$this->emails->send_order_email( $order, 7, 238 );

		$this->assertArrayNotHasKey( Emails::META_EMAIL_SENT, $order->meta );
		$this->assertStringContainsString( 'NO pudo enviarse', implode( ' ', $order->notes ) );
		$this->assertSame( array( array( Emails::HOOK_RETRY_ORDER, array( 100, 2 ) ) ), $agendados, 'El reintento queda en la cola, sin esperar a que alguien vuelva a marcar el pedido.' );
	}

	public function test_a_sealed_order_never_emails_twice(): void {
		$order                                     = $this->awarded_order();
		$order->meta[ Emails::META_EMAIL_SENT ] = 1756500000;

		$this->emails->send_order_email( $order, 7, 238 );

		$this->assertCount( 0, $this->wc->mailer()->sent );
	}

	public function test_the_retry_path_resends_only_unsealed_orders(): void {
		$order = $this->awarded_order();

		// already_done con correo pendiente → reenvía el total sellable.
		$this->emails->maybe_resend( $order );
		$this->assertCount( 1, $this->wc->the_mailer->sent );
		$this->assertStringContainsString( '738', $this->wc->the_mailer->sent[0]['subject'] );

		// Y una vez sellado, el mismo camino ya no reenvía.
		$this->emails->maybe_resend( $order );
		$this->assertCount( 1, $this->wc->the_mailer->sent );
	}

	public function test_orders_without_billing_email_are_skipped(): void {
		$order                = $this->awarded_order();
		$order->billing_email = '';

		$this->emails->send_order_email( $order, 7, 238 );

		$this->assertCount( 0, $this->wc->mailer()->sent );
		$this->assertArrayNotHasKey( Emails::META_EMAIL_SENT, $order->meta );
	}

	public function test_the_expiry_warning_seals_only_with_confirmed_transport(): void {
		$user             = new \WP_User( 8 );
		$user->user_email = 'cliente@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'wc_get_account_endpoint_url' )->justReturn( 'https://akibara.cl/mi-cuenta/rewards/' );

		// SMTP caído: el aviso NO se sella — mañana se reintenta (célula
		// 2026-08-31: sellar antes de enviar perdía el aviso para siempre).
		$this->wc->mailer()->send_result = false;
		$this->emails->send_expiry_warning( 8, 500, 30 );
		$this->assertArrayNotHasKey( \Akibara\Rewards\Points\Expiry::META_WARNED, $this->wpdb->user_meta[8] ?? array() );

		// Transporte confirmado: se sella el mes.
		$this->wc->mailer()->send_result = true;
		$this->emails->send_expiry_warning( 8, 500, 30 );
		$marker = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) ) )->format( 'Y-m' );
		$this->assertSame( $marker, $this->wpdb->user_meta[8][ \Akibara\Rewards\Points\Expiry::META_WARNED ] ?? null );

		$ultimo = end( $this->wc->mailer()->sent );
		$this->assertStringContainsString( 'por vencer', $ultimo['subject'] );
		$this->assertStringContainsString( 'Canjear mis puntos', $ultimo['body'] );
	}

	public function test_the_body_filter_always_receives_the_same_shape_of_context(): void {
		// El segundo argumento llegaba con cuatro tipos distintos según el
		// correo (WC_Order, int, WP_Comment) y el docblock documentaba uno
		// solo: quien filtrara tenía que adivinar.
		$vistos = array();
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value, $context = null ) use ( &$vistos ) {
				if ( 'akb_rewards_points_email_body' === $hook ) {
					$vistos[] = $context;
				}
				return $value;
			}
		);

		$user             = new \WP_User( 8 );
		$user->user_email = 'cliente@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'wc_get_account_endpoint_url' )->justReturn( 'https://akibara.cl/mi-cuenta/rewards/' );

		$order = $this->awarded_order();
		$this->emails->send_order_email( $order, 7, 238 );
		$this->emails->send_expiry_warning( 8, 500, 30 );
		$this->emails->send_birthday_email( 8, 1000, 2026 );

		$this->assertCount( 3, $vistos );
		foreach ( $vistos as $context ) {
			$this->assertIsArray( $context, 'El contexto es SIEMPRE un array.' );
			$this->assertSame(
				array( 'email', 'user_id', 'order', 'comment' ),
				array_keys( $context ),
				'Las mismas claves, en el mismo orden, en todos los correos.'
			);
		}

		$this->assertSame( 'order', $vistos[0]['email'] );
		$this->assertSame( 7, $vistos[0]['user_id'] );
		$this->assertSame( $order, $vistos[0]['order'] );
		$this->assertNull( $vistos[0]['comment'] );

		$this->assertSame( 'expiry', $vistos[1]['email'] );
		$this->assertSame( 8, $vistos[1]['user_id'] );
		$this->assertNull( $vistos[1]['order'], 'Un aviso de vencimiento no cuelga de un pedido.' );

		$this->assertSame( 'birthday', $vistos[2]['email'] );
	}

	/**
	 * El carril de puntos salía con CERO destinos táctiles de 44px: cuatro
	 * correos con un enlace de texto y el premio de reseña sin NADA que pulsar
	 * (gate de correos, 2026-09-01).
	 *
	 * La clase `akb-email-btn` no es decoración: estos correos pasan por
	 * `WC_Emails::wrap_message()`, que aplica `wp_kses_post()` y desnuda el
	 * `-webkit-text-fill-color` inline. Sin la clase, la hoja del wrapper deja
	 * el texto rojo sobre el botón rojo.
	 *
	 * @return array<string, string> Identificador del correo => cuerpo enviado.
	 */
	private function bodies_of_the_five_point_emails(): array {
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 941;

		$user             = new \WP_User( 8 );
		$user->user_email = 'cliente@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_the_title' )->justReturn( 'Berserk 42' );
		// `function_exists()` devuelve TRUE por contagio de la suite: sin este
		// stub, `rewards_url()` entra en la rama de WooCommerce y Brain Monkey
		// lanza «not defined nor mocked».
		Functions\when( 'wc_get_account_endpoint_url' )->justReturn( 'https://akibara.test/mi-cuenta/rewards/' );

		$comment = new \WP_Comment(
			array(
				'comment_ID'           => 55,
				'comment_post_ID'      => 6042,
				'comment_author_email' => 'cliente@example.com',
			)
		);

		$this->emails->send_order_email( $this->awarded_order(), 7, 238 );
		$this->emails->send_expiry_warning( 8, 500, 30 );
		$this->emails->send_birthday_email( 8, 1000, 2026 );
		$this->emails->send_review_email( $comment, 8, 500 );

		$enviados = $this->wc->the_mailer->sent;
		$this->assertCount( 4, $enviados, 'Los cuatro emisores de esta clase tienen que haber enviado.' );

		return array(
			'order'     => $enviados[0]['body'],
			'expiry'    => $enviados[1]['body'],
			'birthday'  => $enviados[2]['body'],
			'review'    => $enviados[3]['body'],
		);
	}

	public function test_every_points_email_carries_a_brand_button(): void {
		foreach ( $this->bodies_of_the_five_point_emails() as $correo => $body ) {
			$this->assertStringContainsString(
				'class="akb-email-btn"',
				$body,
				sprintf( 'El correo «%s» necesita la CLASE: es lo único que sobrevive a wp_kses_post().', $correo )
			);
			$this->assertStringContainsString( 'padding:15px 28px', $body, 'Alto 45px: por encima del mínimo táctil de 44px.' );
			$this->assertStringContainsString( 'background-color:#E60012', $body, 'Rojo de marca, no un rojo cualquiera.' );
		}
	}

	public function test_every_points_button_points_at_an_absolute_rewards_url(): void {
		foreach ( $this->bodies_of_the_five_point_emails() as $correo => $body ) {
			$this->assertMatchesRegularExpression(
				'/<a class="akb-email-btn" href="https?:\/\/[^"]+\/mi-cuenta\/rewards\/"/',
				$body,
				sprintf( 'El botón de «%s» tiene que llevar al saldo con una URL ABSOLUTA: en un correo no hay base contra la que resolver.', $correo )
			);
		}
	}

	/**
	 * Era el ÚNICO correo del programa que llegaba sin un solo enlace: avisaba
	 * del premio y dejaba al cliente sin camino a su saldo.
	 */
	public function test_the_review_reward_email_is_no_longer_a_dead_end(): void {
		$body = $this->bodies_of_the_five_point_emails()['review'];

		$this->assertStringContainsString( 'Ver mis puntos', $body );
		$this->assertStringContainsString( 'Berserk 42', $body, 'Sigue nombrando la reseña premiada.' );
	}

	/** @testdox La equivalencia del premio usa los puntos acreditados aunque cambie la oferta vigente. */
	public function test_review_email_converts_actual_awarded_points(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => array(
				'akb_rewards_review_points' => 240,
				'akb_rewards_redeem_value'  => 3,
			)[ $key ] ?? $default
		);
		$this->wpdb->user_meta[8][ Ledger::META_BALANCE ] = 950;
		$body = $this->bodies_of_the_five_point_emails()['review'];
		$this->assertStringContainsString( 'Sumaste <strong>500 Puntos Akibara</strong>', $body );
		$this->assertStringContainsString( '$1.500 CLP de descuento', $body );
		$this->assertStringContainsString( '950 puntos', $body );
		$this->assertStringNotContainsString( '$720 CLP de descuento', $body );
	}

	/**
	 * El ajuste manual del panel vive en otra clase pero comparte el mismo
	 * botón: si alguien duplica la marca ahí, esto lo caza.
	 */
	public function test_the_admin_adjustment_email_shares_the_same_button(): void {
		Functions\when( 'wc_get_account_endpoint_url' )->justReturn( 'https://akibara.test/mi-cuenta/rewards/' );

		$html = Emails::cta( 'Ver mis puntos', Emails::rewards_url() );

		$this->assertStringContainsString( 'class="akb-email-btn"', $html );
		$this->assertStringContainsString( '/mi-cuenta/rewards/', $html );
	}

	/** Sin destino no hay botón: un `<a href="">` es peor que ningún botón. */
	public function test_a_button_without_destination_renders_nothing(): void {
		$this->assertSame( '', Emails::cta( 'Ver mis puntos', '' ) );
		$this->assertSame( '', Emails::cta( 'Ver mis puntos', '   ' ) );
	}

	/**
	 * La parte plana se DERIVA del HTML, y el punto entero de tenerla es que
	 * lleve los MISMOS destinos: un `wp_strip_all_tags()` a secas dejaría
	 * «Canjear mis puntos» sin decir a dónde.
	 */
	public function test_the_plain_part_keeps_every_link_destination(): void {
		$plano = Emails::html_to_plain(
			'<style>a{color:red}</style><p>Hola <strong>Camila</strong>.</p>'
			. '<p><a class="akb-email-btn" href="https://akibara.test/mi-cuenta/rewards/">Canjear mis puntos</a></p>'
		);

		$this->assertStringContainsString( 'Canjear mis puntos: https://akibara.test/mi-cuenta/rewards/', $plano );
		$this->assertStringContainsString( 'Hola Camila.', $plano );
		$this->assertStringNotContainsString( '<', $plano, 'La parte plana no puede llevar markup.' );
		$this->assertStringNotContainsString( 'color:red', $plano, 'La hoja de estilos no es contenido.' );
	}

	/**
	 * Un `mailto:` no gana etiqueta duplicada: «contacto@akibara.cl:
	 * mailto:contacto@akibara.cl» es ruido, no información.
	 */
	public function test_the_plain_part_does_not_duplicate_mailto_links(): void {
		$plano = Emails::html_to_plain( '<p><a href="mailto:contacto@akibara.cl">contacto@akibara.cl</a></p>' );

		$this->assertSame( 'mailto:contacto@akibara.cl', $plano );
	}

	/**
	 * El hook de la parte plana NO puede sobrevivir al envío: vivo, le pegaría
	 * el texto de un correo de puntos al siguiente correo del sitio.
	 */
	public function test_the_plain_part_hook_is_removed_after_sending(): void {
		$this->emails->send_expiry_warning( $this->expiry_user()->ID, 500, 30 );

		$this->assertFalse(
			\Brain\Monkey\Actions\has( 'phpmailer_init', array( Emails::class, 'attach_plain_part' ) ),
			'El hook tiene que descolgarse en el `finally` del envío.'
		);
	}

	/** Usuario con correo para los avisos que no cuelgan de un pedido. */
	private function expiry_user(): \WP_User {
		$user             = new \WP_User( 8 );
		$user->user_email = 'cliente@example.com';
		Functions\when( 'get_user_by' )->justReturn( $user );

		return $user;
	}

	/**
	 * El saldo pertenece a la CUENTA; el correo salía a la dirección de
	 * facturación del PEDIDO. Un pedido creado por el admin para un cliente, o
	 * uno regalado con el correo del destinatario en facturación, mandaba el
	 * saldo de puntos de una persona a la bandeja de otra (hallazgo
	 * S-b1e09185, 2026-09-18).
	 *
	 * @testdox El correo de puntos va a la cuenta dueña del saldo, no a la facturación del pedido.
	 */
	public function test_the_points_email_goes_to_the_account_that_owns_the_balance(): void {
		$this->wpdb->users[7] = array( 'user_email' => 'camila@example.com' );
		$this->wpdb->user_meta[7]['first_name']           = 'Camila';
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 941;

		$order                     = $this->awarded_order();
		$order->billing_email      = 'regalo@otrapersona.cl';
		$order->billing_first_name = 'Rodrigo';

		$this->emails->send_order_email( $order, 7, 238 );

		$mail = $this->wc->the_mailer->sent[0];
		$this->assertSame( 'camila@example.com', $mail['to'], 'El saldo se le cuenta a su dueña.' );
		$this->assertStringContainsString( 'Camila', $mail['body'], 'Y se la saluda por su nombre, no por el de quien recibe el paquete.' );
		$this->assertStringNotContainsString( 'Rodrigo', $mail['body'] );
	}

	/** @testdox Sin cuenta con correo, el aviso cae a la facturación del pedido. */
	public function test_without_an_account_email_it_falls_back_to_billing(): void {
		// Cuenta sin correo (o borrada): el pedido sigue siendo el único
		// contacto conocido y callar el aviso sería peor.
		$this->wpdb->users[7] = array( 'user_email' => '' );

		$order = $this->awarded_order();
		$this->emails->send_order_email( $order, 7, 238 );

		$mail = $this->wc->the_mailer->sent[0];
		$this->assertSame( 'cliente@example.com', $mail['to'] );
		$this->assertStringContainsString( 'Camila', $mail['body'], 'Ahí sí manda el nombre de la facturación: es la misma persona.' );
	}

	/**
	 * El estado que acredita es una palanca del panel: de fábrica «Procesando»
	 * (el pedido acaba de pagarse) y el dueño puede moverlo a «Completado» o a
	 * «Listo para enviar». Cualquier frase del correo que describa DÓNDE va el
	 * paquete —«ya está en camino», «ya salió», «lo despachamos»— se vuelve
	 * mentira el día que mueva la palanca, y el cliente lee la mentira, no la
	 * option (hallazgo S-6a852fdc). «Confirmado» es cierto con los tres.
	 *
	 * @testdox El correo de puntos no describe dónde va el paquete.
	 */
	public function test_the_points_email_never_narrates_the_shipment(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Points/Emails.php' );

		foreach ( array( 'en camino', 'ya salió', 'despachamos', 'ya viene', 'en preparación', 'listo para enviar' ) as $frase ) {
			$this->assertStringNotContainsStringIgnoringCase(
				$frase,
				$fuente,
				sprintf( 'El correo sale en el estado que el dueño elija: «%s» sólo es cierto con uno de ellos.', $frase )
			);
		}

		$this->assertStringContainsString( 'ya está confirmado', $fuente, 'Lo único que el correo puede afirmar del pedido es lo que vale para cualquier estado que acredite.' );
	}

	/**
	 * Las cuentas creadas con Google o migradas no traen `first_name`. Cuando el
	 * correo de la cuenta ES el del pedido —casi siempre—, el nombre de
	 * facturación es el de la misma persona y el saludo no tiene por qué
	 * quedarse en un «¡Hola!» pelado (revisión independiente, 2026-09-19).
	 *
	 * @testdox Una cuenta sin nombre saluda con el de facturación si es la misma persona.
	 */
	public function test_an_account_without_first_name_still_greets_by_name(): void {
		$this->wpdb->users[7] = array( 'user_email' => 'Cliente@Example.com' );

		$this->emails->send_order_email( $this->awarded_order(), 7, 238 );

		$mail = $this->wc->the_mailer->sent[0];
		$this->assertSame( 'Cliente@Example.com', $mail['to'] );
		$this->assertStringContainsString( '¡Hola, Camila!', $mail['body'] );
	}

	/** @testdox …y no usa el de facturación cuando el pedido es de otra persona. */
	public function test_a_nameless_account_never_borrows_a_strangers_name(): void {
		$this->wpdb->users[7] = array( 'user_email' => 'camila@example.com' );

		$order                     = $this->awarded_order();
		$order->billing_email      = 'regalo@otrapersona.cl';
		$order->billing_first_name = 'Rodrigo';

		$this->emails->send_order_email( $order, 7, 238 );

		$mail = $this->wc->the_mailer->sent[0];
		$this->assertStringNotContainsString( 'Rodrigo', $mail['body'] );
		$this->assertStringContainsString( '¡Hola!', $mail['body'] );
	}
}
