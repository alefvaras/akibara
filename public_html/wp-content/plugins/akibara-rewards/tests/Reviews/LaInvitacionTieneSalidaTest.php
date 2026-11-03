<?php
/**
 * La invitación a reseñar tiene salida para el cliente.
 *
 * Es el único correo del programa que PIDE algo. Hasta la 0.4.9 el único
 * interruptor era global y vivía en el panel del dueño: un cliente que no
 * quería que le pidieran reseñas no tenía más salida que escribir a la tienda
 * (hallazgo S-e19b2e8b, 2026-09-18). Este archivo fija las cuatro cosas que
 * hacen que esa salida exista de verdad: el enlace en el correo, la cabecera
 * que pinta el botón nativo de Gmail, que abrir el enlace NO dé de baja solo,
 * y que la baja calle el correo siguiente.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Invitations;
use Akibara\Rewards\Reviews\InviteOptOut;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.4.9
 */
final class LaInvitacionTieneSalidaTest extends RewardsTestCase {

	private const TOKEN = 'TkTkTkTkTkTkTkTkTkTkTkTkTkTkTkTkTkTkTkTk';

	/** «<pedido>-<firma>» del pedido 100: lo que viaja en el enlace de baja. */
	private string $ref = '';

	/** @var array<string,mixed> Store real de options para esta prueba. */
	private array $options = array();

	/** @var array<int,\WC_Order> */
	private array $orders = array();

	private \Akibara_Rewards_Fake_WC $wc;

	private Invitations $invitations;

	protected function setUp(): void {
		parent::setUp();

		$this->wc          = $this->install_fake_wc();
		$this->invitations = new Invitations();

		// Store de options de verdad: la lista de bajas se lee y se escribe, y
		// con el doble del caso base (get_option → default, update_option →
		// true) la baja no sobreviviría a su propia línea.
		Functions\when( 'get_option' )->alias(
			fn( string $option, $default_value = false ) => $this->options[ $option ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $option, $value ): bool {
				$this->options[ $option ] = $value;
				return true;
			}
		);

		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $this->orders[ (int) $id ] ?? false );
		Functions\when( 'wp_generate_password' )->justReturn( self::TOKEN );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
		Functions\when( 'wp_delete_attachment' )->justReturn( false );

		// La sal del sitio firma el enlace: fija aquí, para que la firma sea
		// reproducible y una prueba pueda falsificarla a propósito.
		Functions\when( 'status_header' )->justReturn( null );
		// El «empujón» enlaza a la ficha cuando el pedido trae un solo tomo.
		// Con el guard `function_exists()` del código, esta función existe o
		// no según qué prueba corrió antes: se dobla aquí para que el
		// resultado no dependa del orden.
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.test/atelier-of-witch-hat-15/' );

		$order                     = $this->make_order( 100, 42, 10000.0, 1900.0 );
		$order->status             = 'completed';
		$order->date_paid          = new \WC_DateTime( '2026-10-01' );
		$order->billing_email      = 'cliente@example.com';
		$order->items['line_item'] = array( new \WC_Order_Item_Fake( 777, 'Atelier of Witch Hat 15' ) );
		$this->orders[100]         = $order;

		$this->ref = (string) preg_replace( '/^.*=/', '', InviteOptOut::url_for( $order ) );
	}

	/** @testdox El correo de invitación lleva el enlace de baja y la cabecera de cancelación. */
	public function test_the_invite_offers_the_way_out(): void {
		$this->invitations->send_invite( 100 );

		$correo = end( $this->wc->mailer()->sent );
		self::assertIsArray( $correo, 'La invitación salió.' );

		$enlace = InviteOptOut::url_for( $this->orders[100] );
		self::assertStringContainsString( $enlace, $correo['body'], 'El cliente ve su salida en el cuerpo.' );
		self::assertStringContainsString( 'no te pidamos reseñas', $correo['body'] );
		self::assertStringContainsString( 'List-Unsubscribe: <' . $enlace . '>', (string) $correo['headers'], 'Gmail pinta su propio botón con esta cabecera.' );
		self::assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', (string) $correo['headers'] );
	}

	/** @testdox La invitación ofrece solo recompensa con foto obligatoria. */
	public function test_reward_invite_explains_amount_and_photo_requirement(): void {
		$this->options['akb_rewards_review_points'] = 240;
		$this->options['akb_rewards_redeem_value'] = 3;
		$this->invitations->send_invite( 100 );
		$correo = end( $this->wc->mailer()->sent );
		$this->assertStringContainsString( 'Reseñar y obtener puntos', $correo['body'] );
		$this->assertStringContainsString( '240 Puntos Akibara = $720 CLP de descuento', $correo['body'] );
		$this->assertStringContainsString( 'debes adjuntar una foto real', $correo['body'] );
		$this->assertStringNotContainsString( 'Reseña voluntaria sin puntos', $correo['body'] );
		$this->assertStringNotContainsString( 'puedes adjuntar una foto si quieres', $correo['body'] );
	}

	/** @testdox Con el carril apagado la invitación ofrece una reseña voluntaria sin recompensa. */
	public function test_disabled_reward_invite_is_voluntary(): void {
		$this->options['akb_rewards_reviews_enabled'] = 'no';
		$this->invitations->send_invite( 100 );
		$correo = end( $this->wc->mailer()->sent );
		$this->assertStringContainsString( 'Escribir mi reseña', $correo['body'] );
		$this->assertStringContainsString( 'reseña voluntaria sin puntos; la foto es opcional', $correo['body'] );
		$this->assertStringNotContainsString( 'Reseñar y obtener puntos', $correo['body'] );
		$this->assertStringNotContainsString( 'CLP de descuento', $correo['body'] );
	}

	/** @testdox Abrir el enlace NO da de baja: pregunta primero. */
	public function test_opening_the_link_does_not_unsubscribe(): void {
		$html = InviteOptOut::responder( $this->ref, 'GET' )['html'];

		self::assertStringContainsString( '¿Dejamos de pedirte reseñas?', $html );
		self::assertStringContainsString( 'method="post"', $html, 'La baja viaja por POST: un prefetch de correo no puede dispararla.' );
		self::assertFalse( InviteOptOut::opted_out( 'cliente@example.com' ), 'Un GET no cambia nada.' );
	}

	/** @testdox El POST del botón da de baja, y la baja calla la invitación siguiente. */
	public function test_the_post_unsubscribes_and_silences_the_next_invite(): void {
		$html = InviteOptOut::responder( $this->ref, 'POST', array( 'akb_rw_pref' => 'baja' ) )['html'];

		self::assertTrue( InviteOptOut::opted_out( 'cliente@example.com' ) );
		self::assertStringContainsString( 'No volveremos a pedirte reseñas', $html );

		$this->invitations->send_invite( 100 );
		self::assertSame( array(), $this->wc->mailer()->sent, 'A quien pidió la baja no se le escribe.' );
	}

	/** @testdox El «un clic» de Gmail da de baja sin pasar por la página. */
	public function test_one_click_unsubscribe_is_honoured(): void {
		InviteOptOut::responder( $this->ref, 'POST', array( 'List-Unsubscribe' => 'One-Click' ) );

		self::assertTrue( InviteOptOut::opted_out( 'cliente@example.com' ) );
	}

	/** @testdox La baja se puede revertir desde la misma página. */
	public function test_the_way_back_exists(): void {
		InviteOptOut::responder( $this->ref, 'POST', array( 'akb_rw_pref' => 'baja' ) );
		self::assertTrue( InviteOptOut::opted_out( 'cliente@example.com' ) );

		$html = InviteOptOut::responder( $this->ref, 'POST', array( 'akb_rw_pref' => 'alta' ) )['html'];

		self::assertFalse( InviteOptOut::opted_out( 'cliente@example.com' ), 'Una baja sin vuelta atrás obliga a escribir, que es lo que vinimos a evitar.' );
		self::assertStringContainsString( 'Volvimos a activarlas', $html );

		$this->invitations->send_invite( 100 );
		self::assertNotSame( array(), $this->wc->mailer()->sent, 'Reactivada, la invitación vuelve a salir.' );
	}

	/** @testdox La baja no guarda la dirección en claro. */
	public function test_the_list_keeps_no_readable_address(): void {
		InviteOptOut::responder( $this->ref, 'POST', array( 'akb_rw_pref' => 'baja' ) );

		$guardado = wp_json_encode( $this->options['akb_rewards_invite_optout'] ?? array() );
		self::assertStringNotContainsString( 'cliente@example.com', (string) $guardado );
		self::assertStringContainsString( hash( 'sha256', 'cliente@example.com' ), (string) $guardado );
	}

	/** @testdox Mayúsculas y espacios no crean una baja distinta. */
	public function test_the_address_is_normalised(): void {
		InviteOptOut::responder( $this->ref, 'POST', array( 'akb_rw_pref' => 'baja' ) );

		self::assertTrue( InviteOptOut::opted_out( '  Cliente@Example.COM ' ) );
	}

	/**
	 * El «Cancelar suscripción» de Gmail es un POST sin pantalla que lee
	 * cualquier 2xx como «hecho». Un enlace que no se reconoce tiene que
	 * responder 404, o el cliente cree que ya se dio de baja y sigue recibiendo
	 * correos (revisión independiente previa al despliegue, 2026-09-19).
	 *
	 * @testdox Un enlace que no se reconoce no da de baja a nadie y NO responde 200.
	 */
	public function test_an_unknown_link_changes_nothing_and_is_not_a_200(): void {
		foreach ( array( '999-' . str_repeat( 'a', 32 ), 'NoEsUnaReferencia', '100-' . str_repeat( '0', 32 ) ) as $falso ) {
			$res = InviteOptOut::responder( $falso, 'POST', array( 'List-Unsubscribe' => 'One-Click' ) );

			self::assertSame( 404, $res['status'], $falso );
			self::assertStringContainsString( 'Ese enlace ya no sirve', $res['html'] );
		}

		self::assertSame( array(), $this->options['akb_rewards_invite_optout'] ?? array() );
		self::assertSame( 0, InviteOptOut::total() );
	}

	/**
	 * La primera versión reutilizaba el token del formulario de reseña: el
	 * mismo secreto publicaba una reseña a nombre del cliente y le acreditaba
	 * puntos, y viajaba en una cabecera que leen pasarelas de correo. Además
	 * esa fila se purga a los seis meses si nadie reseñó — justo el perfil de
	 * quien quiere la baja.
	 *
	 * @testdox El enlace de baja no es el token de la reseña ni depende de su tabla.
	 */
	public function test_the_link_is_not_the_review_token_and_needs_no_table(): void {
		$this->invitations->send_invite( 100 );
		$correo = end( $this->wc->mailer()->sent );

		self::assertStringNotContainsString( 'akb_rw_baja=' . self::TOKEN, $correo['body'] . $correo['headers'], 'El secreto del formulario no sale por la cabecera de baja.' );
		self::assertMatchesRegularExpression( '/akb_rw_baja=100-[a-f0-9]{32}/', (string) $correo['headers'] );

		// Sin una sola fila en la tabla de enlaces, la baja sigue funcionando.
		$this->wpdb->var_answers = array();
		$res = InviteOptOut::responder( $this->ref, 'POST', array( 'akb_rw_pref' => 'baja' ) );

		self::assertSame( 200, $res['status'] );
		self::assertTrue( InviteOptOut::opted_out( 'cliente@example.com' ) );
	}

	/** @testdox Conocer el número de un pedido no basta para dar de baja a su dueño. */
	public function test_the_signature_is_bound_to_the_order_and_its_email(): void {
		// La firma del pedido 100 no abre el 101.
		$otro                = $this->make_order( 101, 43, 5000.0, 950.0 );
		$otro->billing_email = 'otra@example.com';
		$this->orders[101]   = $otro;

		$firma = substr( $this->ref, strpos( $this->ref, '-' ) + 1 );
		$res   = InviteOptOut::responder( '101-' . $firma, 'POST', array( 'akb_rw_pref' => 'baja' ) );

		self::assertSame( 404, $res['status'] );
		self::assertFalse( InviteOptOut::opted_out( 'otra@example.com' ) );
	}

	/**
	 * Soporte corrige a menudo un correo mal escrito —y justo en los pedidos
	 * cuyo cliente escribe molesto—. La primera firma incluía el correo, así
	 * que arreglar el tipeo mataba el enlace de baja ya enviado y el «Cancelar
	 * suscripción» de Gmail le decía al cliente que había fallado (segunda
	 * revisión independiente, 2026-09-19).
	 *
	 * @testdox Corregir el correo del pedido no mata el enlace de baja ya enviado.
	 */
	public function test_fixing_the_order_email_keeps_the_link_alive(): void {
		$this->orders[100]->billing_email = 'cliente.corregido@example.com';

		$res = InviteOptOut::responder( $this->ref, 'POST', array( 'List-Unsubscribe' => 'One-Click' ) );

		self::assertSame( 200, $res['status'] );
		self::assertTrue( InviteOptOut::opted_out( 'cliente.corregido@example.com' ), 'La baja vale para la dirección a la que hoy se le escribiría.' );
	}

	/**
	 * `nocache_headers()` no decide nada en esta tienda: LiteSpeed resuelve con
	 * sus ajustes y la regla de Cloudflare pisa el `no-store` del origen
	 * (medido: `cf-cache-status: HIT` con `no-store, private` en la misma
	 * respuesta). Una página de preferencias cacheada le enseña al cliente el
	 * estado de hace diez minutos y le esconde el botón de volver atrás.
	 *
	 * @testdox La página de baja vive bajo Mi cuenta, la ruta que el borde no cachea.
	 */
	public function test_the_page_lives_where_the_edge_does_not_cache(): void {
		self::assertStringStartsWith( 'https://akibara.test/mi-cuenta/?akb_rw_baja=100-', InviteOptOut::url_for( $this->orders[100] ) );

		$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Reviews/InviteOptOut.php' );
		self::assertStringContainsString( 'PrivatePage::no_cache(', $fuente, 'Y apaga la caché de página de LiteSpeed, que tampoco lee las cabeceras.' );
	}

	/** @testdox Quien pidió la baja tampoco recibe el «empujón» a reseñar en los correos de su pedido. */
	public function test_the_nudge_also_respects_the_opt_out(): void {
		$email     = new \stdClass();
		$email->id = 'customer_completed_order';

		Functions\when( 'wc_get_order_status_name' )->justReturn( 'Completado' );
		$nudge = new \Akibara\Rewards\Reviews\ReviewNudge();

		ob_start();
		$nudge->render( $this->orders[100], false, false, $email );
		$antes = (string) ob_get_clean();
		self::assertStringContainsString( 'Puntos Akibara', $antes, 'Sin baja, el párrafo sale.' );

		InviteOptOut::responder( $this->ref, 'POST', array( 'akb_rw_pref' => 'baja' ) );

		ob_start();
		$nudge->render( $this->orders[100], false, false, $email );
		self::assertSame( '', (string) ob_get_clean(), 'La página de baja prometió que sus correos traerían sólo lo del pedido.' );
	}

	/** @testdox La página de preferencias responde aunque el motor de reseñas esté apagado. */
	public function test_the_page_lives_outside_the_engine_gate(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );

		$gate  = strpos( $plugin, 'Options::reviews_engine_on()' );
		$linea = strpos( $plugin, 'InviteOptOut() )->register()' );

		self::assertIsInt( $gate );
		self::assertIsInt( $linea );
		self::assertLessThan( $gate, $linea, 'Apagar el motor no puede convertir una baja prometida en un 404.' );
	}
}
