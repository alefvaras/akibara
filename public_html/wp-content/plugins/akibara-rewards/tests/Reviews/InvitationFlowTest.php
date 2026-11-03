<?php
/**
 * Contrato del flujo de invitación por token (Fase 2b): agenda única con
 * compuerta de preventa, sellado solo con transporte, token de un solo uso
 * y la reseña del form nace PENDIENTE y vinculada al pedido.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Invitations;
use Akibara\Rewards\Reviews\ReviewForm;
use Akibara\Rewards\Reviews\ReviewPoints;
use Akibara\Rewards\Support\Schema;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.3.0
 */
final class InvitationFlowTest extends RewardsTestCase {

	/** @var array<int,array{hook:string,args:array<int,mixed>,ts:int}> */
	private array $scheduled = array();

	private Invitations $invitations;

	private ReviewForm $form;

	protected function setUp(): void {
		parent::setUp();

		// `discard_orphan_photo()` se guarda con `function_exists()`, que en la
		// suite da true por contagio: en orden aleatorio, la prueba del insert
		// fallido reventaba con «wp_delete_attachment is not defined nor mocked».
		Functions\when( 'wp_delete_attachment' )->justReturn( false );

		$this->invitations = new Invitations();
		$this->form        = new ReviewForm();
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
				foreach ( $this->scheduled as $j ) {
					if ( $j['hook'] === $hook && ( null === $args || $j['args'] === $args ) ) {
						return $j['ts'];
					}
				}
				return false;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook, array $args = array() ): void {
				$this->scheduled = array_values(
					array_filter(
						$this->scheduled,
						static fn( array $j ): bool => $j['hook'] !== $hook || $j['args'] !== $args
					)
				);
			}
		);
		Functions\when( 'wp_generate_password' )->justReturn( str_repeat( 'Tk', 20 ) );
		Functions\when( 'wc_get_account_endpoint_url' )->justReturn( 'https://akibara.test/mi-cuenta/orders/' );
		Functions\when( 'wc_get_order' )->alias( fn( $id ) => $this->orders[ (int) $id ] ?? false );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		// Cuando hay foto, `handle_upload()` carga el núcleo de WordPress sólo si la
		// función no existe, y aquí existe: se sustituye y los tests llegan a
		// lo que de verdad prueban.
		Functions\when( 'media_handle_upload' )->justReturn( 8801 );
		Functions\when( 'wp_get_image_mime' )->justReturn( 'image/jpeg' );
		// Y lo que la foto arrastra al adjuntarse a la reseña.
		Functions\when( 'wp_attachment_is' )->justReturn( true );
		Functions\when( 'wp_update_post' )->justReturn( 1 );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_filter_comment' )->returnArg();
		Functions\when( 'wp_insert_comment' )->alias(
			function ( array $data ): int {
				$this->inserted_comment = $data;
				foreach ( $data['comment_meta'] ?? array() as $key => $value ) {
					$this->wpdb->comment_meta[901][ $key ] = array( $value );
				}
				return 901;
			}
		);
	}

	/** @var array<int,\WC_Order> */
	private array $orders = array();

	/** @var array<string,mixed> */
	private array $inserted_comment = array();

	private \Akibara_Rewards_Fake_WC $wc;

	/**
	 * Pedido completado del cliente 42 con un tomo.
	 *
	 * @param int $id Pedido.
	 * @return \WC_Order
	 */
	private function completed_order( int $id ): \WC_Order {
		$order                = $this->make_order( $id, 42, 10000.0, 1900.0 );
		$order->status        = 'completed';
		$order->date_paid     = new \WC_DateTime( '2026-10-01 10:00:00' );
		$order->billing_email = 'cliente@example.com';
		$order->items['line_item'] = array( new \WC_Order_Item_Fake( 777, 'Atelier of Witch Hat 15' ) );
		$this->orders[ $id ]  = $order;

		return $order;
	}

	public function test_the_trigger_status_schedules_exactly_one_job(): void {
		$this->completed_order( 100 );

		$this->invitations->on_status_changed( 100, 'processing', 'completed' );
		$this->invitations->on_status_changed( 100, 'processing', 'completed' );

		$this->assertCount( 1, $this->scheduled, 'Un solo job por pedido.' );
		$this->assertSame( array( 100 ), $this->scheduled[0]['args'] );
	}

	public function test_a_fallen_order_unschedules_its_invite(): void {
		$this->completed_order( 100 );
		$this->invitations->on_status_changed( 100, 'processing', 'completed' );

		$this->invitations->on_status_changed( 100, 'completed', 'cancelled' );

		$this->assertSame( array(), $this->scheduled, 'La reversa desagenda la invitación.' );
	}

	public function test_the_lane_toggle_silences_scheduling(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_invite_enabled' === $option ? 'no' : $default_value
		);
		$this->completed_order( 100 );

		$this->invitations->on_status_changed( 100, 'processing', 'completed' );

		$this->assertSame( array(), $this->scheduled, 'El clic del dueño manda.' );
	}

	public function test_send_invite_seals_only_with_confirmed_transport_and_retries_capped(): void {
		$order = $this->completed_order( 100 );

		$this->wc->mailer()->send_result = false;
		$this->invitations->send_invite( 100 );

		$this->assertSame( '', (string) $order->get_meta( Invitations::META_SENT ), 'SMTP caído: sin sello.' );
		$this->assertSame( 1, (int) $order->get_meta( Invitations::META_ATTEMPTS ) );
		$this->assertCount( 1, $this->scheduled, 'Reintento agendado.' );

		$this->wc->mailer()->send_result = true;
		$this->invitations->send_invite( 100 );

		$this->assertNotSame( '', (string) $order->get_meta( Invitations::META_SENT ), 'Transporte confirmado: sellado.' );
		$correo = end( $this->wc->mailer()->sent );
		// Bajo Mi cuenta y no en `/resena/<token>/`: esa ruta salía cacheada del
		// borde (`cf-cache-status: HIT`, medido en producción el 2026-09-19,
		// hallazgo S-87293fff) y la de Mi cuenta es de las que Cloudflare deja
		// pasar.
		$this->assertStringContainsString( 'https://akibara.test/mi-cuenta/?akb_review_token=TkTkTkTkTkTkTkTkTkTkTkTkTkTkTkTkTkTkTkTk', $correo['body'], 'El enlace lleva el token, por la ruta que no se cachea.' );
		$this->assertStringNotContainsString( '/resena/', $correo['body'] );

		$this->invitations->send_invite( 100 );
		$this->assertCount( 2, array_filter( $this->wc->mailer()->sent, static fn( $m ) => str_contains( $m['subject'], 'pareció' ) ), 'Sellado = no re-invita.' );
	}

	public function test_the_form_creates_a_pending_review_linked_to_the_order_and_burns_the_token(): void {
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );
		$this->assertNotSame( '', $token );

		$_POST = array( 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1',
			'_akbnonce'      => 'x',
			'akb_rf_product' => 777,
			'akb_rf_rating'  => 5,
			'akb_rf_content' => 'Edición impecable, papel excelente.',
		);
		// Este caso incluye una foto opcional.
		$_FILES = array( 'akb_rf_photo' => array( 'name' => 'tomo.jpg', 'tmp_name' => '/tmp/review.jpg', 'size' => 100, 'error' => UPLOAD_ERR_OK ) );

		$resultado = $this->form->process( $token );

		$this->assertTrue( $resultado );
		$this->assertSame( 0, $this->inserted_comment['comment_approved'], 'Nace PENDIENTE (decisión A).' );
		$this->assertSame( 'review', $this->inserted_comment['comment_type'] );
		$this->assertSame( 42, $this->inserted_comment['user_id'], 'Atribuida al cliente REAL del pedido.' );
		$this->assertSame( 'cliente@example.com', $this->inserted_comment['comment_author_email'] );
		$this->assertSame( array( 100 ), $this->wpdb->comment_meta[901][ ReviewForm::META_ORDER ] ?? null, 'Vínculo auditable al pedido.' );

		// El token quedó quemado: reuso = rechazo.
		$this->assertSame( 'Este enlace ya se usó o caducó.', $this->form->process( $token ) );
	}

	/** @testdox El formulario conserva la insignia de compra verificada de Listo para enviar pagado. */
	public function test_token_form_verifies_paid_shipping_progress(): void {
		$order = $this->completed_order( 100 );
		$order->status = 'shipping-progress';
		$order->date_paid = new \WC_DateTime( '2026-10-01 10:00:00' );
		$token = Invitations::issue_token( 100 );
		$_POST = array( 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1', '_akbnonce' => 'x', 'akb_rf_product' => 777, 'akb_rf_rating' => 5, 'akb_rf_content' => 'Edición impecable, papel excelente.' );
		$_FILES = array( 'akb_rf_photo' => array( 'name' => 'tomo.jpg', 'tmp_name' => '/tmp/review.jpg', 'size' => 100, 'error' => UPLOAD_ERR_OK ) );
		$this->assertTrue( $this->form->process( $token ) );
		$this->assertSame( array( 1 ), $this->wpdb->comment_meta[901]['verified'] );
		$this->assertSame( 0, $this->inserted_comment['comment_approved'], 'Verificar la compra no autoaprueba la reseña.' );
	}

	/** @testdox Tres fallos dejan nota y alerta, y los jobs duplicados no vuelven a enviar. */
	public function test_exhausted_invitation_alerts_and_stops(): void {
		$order = $this->completed_order( 100 );
		$this->wc->mailer()->send_result = false;
		\Brain\Monkey\Actions\expectDone( 'akb_rewards_email_gave_up' )->once()->with( Invitations::HOOK, array( 100, 3 ) );

		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$this->invitations->send_invite( 100 );
		}

		$this->assertSame( 3, (int) $order->get_meta( Invitations::META_ATTEMPTS ) );
		$this->assertCount( 3, $this->wc->mailer()->sent );
		$this->assertCount( 2, $this->scheduled, 'Sólo dos reintentos después del intento inicial.' );
		$this->assertStringContainsString( 'tres intentos', implode( ' ', $order->notes ) );
		$this->assertSame( '', (string) $order->get_meta( Invitations::META_SENT ) );
	}

	/** @testdox Invitación y formulario explican cadencia, límite y cuenta pendiente. */
	public function test_invitation_and_form_share_reward_requirements(): void {
		Functions\when( 'wp_nonce_field' )->justReturn( '<input name="_akbnonce" value="test">' );
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => array(
				'akb_rewards_review_every_n'      => 3,
				'akb_rewards_review_max_per_user' => 5,
			)[ $key ] ?? $default
		);
		$this->completed_order( 100 );
		$this->invitations->send_invite( 100 );
		$correo = end( $this->wc->mailer()->sent );
		$form   = $this->form->render_page( str_repeat( 'Tk', 20 ) );
		$thanks = new \ReflectionMethod( ReviewForm::class, 'thanks_body' );
		$thanks->setAccessible( true );
		$_POST = array( 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1', '_akbnonce' => 'x', 'akb_rf_product' => 777, 'akb_rf_rating' => 5, 'akb_rf_content' => 'Edición impecable, papel excelente.', 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1' );
		$_FILES = array( 'akb_rf_photo' => array( 'name' => 'tomo.jpg', 'tmp_name' => '/tmp/review.jpg', 'size' => 100, 'error' => UPLOAD_ERR_OK ) );
		$this->assertTrue( $this->form->process( str_repeat( 'Tk', 20 ) ) );
		foreach ( array( $correo['body'], $form, $thanks->invoke( $this->form ) ) as $surface ) {
			$this->assertStringContainsString( 'por cada 3 reseñas', $surface );
			$this->assertStringContainsString( 'Tope de 5 premios', $surface );
			$this->assertStringContainsString( 'premio queda pendiente', $surface );
			$this->assertStringNotContainsString( 'ahí mismo se acreditan tus puntos', $surface );
		}
	}

	public function test_a_second_submission_landing_mid_insert_creates_no_second_review(): void {
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );

		$_POST = array( 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1',
			'_akbnonce'      => 'x',
			'akb_rf_product' => 777,
			'akb_rf_rating'  => 5,
			'akb_rf_content' => 'Doble clic del cliente impaciente.',
		);
		// Este caso incluye una foto opcional.
		$_FILES = array( 'akb_rf_photo' => array( 'name' => 'tomo.jpg', 'tmp_name' => '/tmp/review.jpg', 'size' => 100, 'error' => UPLOAD_ERR_OK ) );

		// El segundo envío entra JUSTO mientras el primero crea la reseña: es
		// la carrera real del doble clic. Con el token quemado DESPUÉS de
		// crear, el reentrante encontraba el enlace libre y nacían dos reseñas
		// (y dos premios).
		$creadas    = 0;
		$reentrando = false;
		$reentrante = null;
		Functions\when( 'wp_insert_comment' )->alias(
			function ( array $data ) use ( &$creadas, &$reentrando, &$reentrante, $token ): int {
				$this->inserted_comment = $data;
				++$creadas;
				if ( ! $reentrando ) {
					$reentrando = true; // Un solo nivel: sin el candado esto recursaba sin fin.
					$reentrante = $this->form->process( $token );
				}
				return 900 + $creadas;
			}
		);

		$this->assertTrue( $this->form->process( $token ) );
		$this->assertSame( 1, $creadas, 'Una sola reseña nace del doble envío.' );
		$this->assertSame( 'Este enlace ya se usó o caducó.', $reentrante, 'El segundo envío se topa con el token ya tomado.' );
	}

	public function test_a_failed_insert_gives_the_token_back(): void {
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );

		$_POST = array( 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1',
			'_akbnonce'      => 'x',
			'akb_rf_product' => 777,
			'akb_rf_rating'  => 5,
			'akb_rf_content' => 'La base se cayó justo ahora.',
		);
		// Este caso incluye una foto opcional.
		$_FILES = array( 'akb_rf_photo' => array( 'name' => 'tomo.jpg', 'tmp_name' => '/tmp/review.jpg', 'size' => 100, 'error' => UPLOAD_ERR_OK ) );

		Functions\when( 'wp_insert_comment' )->justReturn( 0 );
		$this->assertSame( 'No pudimos guardar tu reseña; inténtalo otra vez.', $this->form->process( $token ) );

		// Sin reseña creada no hay nada que premiar: el enlace debe seguir vivo.
		Functions\when( 'wp_insert_comment' )->alias(
			function ( array $data ): int {
				$this->inserted_comment = $data;
				foreach ( $data['comment_meta'] ?? array() as $key => $value ) {
					$this->wpdb->comment_meta[901][ $key ] = array( $value );
				}
				return 901;
			}
		);
		$this->assertTrue( $this->form->process( $token ), 'El token vuelve al ruedo tras un INSERT fallido.' );
	}

	/**
	 * La foto se sube ANTES de reclamar el token, y ese orden es deliberado: si
	 * la subida falla, el enlace sigue vivo y el cliente reintenta. El precio es
	 * que quien PIERDE la carrera —el doble clic en «Enviar mi reseña», sobre
	 * todo— ya escribió su imagen en `uploads/`, y sin limpiarla se queda ahí
	 * sin reseña, sin `post_parent` y con URL pública. `AdminReviews` ya limpia
	 * en el caso equivalente; esta ruta se saltaba la convención (célula de
	 * auditoría 2026-09-02: un hallazgo que PHPCS no ve).
	 *
	 * Los dos tests de la carrera de arriba corren con `$_FILES` vacío, y por
	 * ese hueco se coló. No se pueden ampliar con foto: `handle_upload()` hace
	 * `require_once ABSPATH . 'wp-admin/includes/media.php'`, que declara
	 * `media_handle_upload()` de verdad y choca con cualquier doble; y con el
	 * core real la subida nunca prospera en CLI porque no hay fichero subido.
	 * Así que el helper se fija por COMPORTAMIENTO y su cableado a las dos
	 * salidas por ESTRUCTURA, que es el patrón que este plugin ya usa para las
	 * superficies que no se pueden ejercer de punta a punta.
	 *
	 * @testdox La foto de una reseña que no llegó a nacer se borra, y un 0 no toca nada.
	 */
	public function test_the_orphan_photo_helper_deletes_only_a_real_attachment(): void {
		$borrados = array();
		Functions\when( 'wp_delete_attachment' )->alias(
			static function ( int $id, bool $forzar = false ) use ( &$borrados ): bool {
				$borrados[] = array( 'id' => $id, 'forzar' => $forzar );
				return true;
			}
		);

		$descartar = new \ReflectionMethod( ReviewForm::class, 'discard_orphan_photo' );
		$descartar->setAccessible( true );

		$descartar->invoke( null, 0 );
		self::assertSame( array(), $borrados, 'Sin foto no hay nada que borrar: el 0 es el caso normal.' );

		$descartar->invoke( null, 512 );
		self::assertSame(
			array( array( 'id' => 512, 'forzar' => true ) ),
			$borrados,
			'Y con foto se borra sin papelera: el punto es que el fichero no se quede en uploads/.'
		);
	}

	/** @testdox Las dos salidas posteriores a la subida descartan la foto huérfana. */
	public function test_both_early_returns_after_the_upload_discard_the_photo(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Reviews/ReviewForm.php' );

		$claim = strpos( $fuente, 'if ( ! self::claim_token( $token ) ) {' );
		$fallo = strpos( $fuente, 'if ( ! $comment_id ) {' );
		self::assertIsInt( $claim, 'La salida por token ya tomado tiene que seguir existiendo.' );
		self::assertIsInt( $fallo, 'Y la del INSERT fallido también.' );

		foreach ( array( 'token ya tomado' => $claim, 'INSERT fallido' => $fallo ) as $nombre => $desde ) {
			$bloque = substr( $fuente, $desde, (int) ( strpos( $fuente, 'return', $desde ) - $desde ) + 120 );
			self::assertStringContainsString(
				'self::discard_orphan_photo( $attachment_id );',
				$bloque,
				sprintf( 'La salida por %s tiene que descartar la foto antes de volver.', $nombre )
			);
		}

		self::assertLessThan(
			$claim,
			(int) strpos( $fuente, '$attachment_id = (int) $subida;' ),
			'Si la subida se mueve DESPUÉS del claim, este candado sobra — pero entonces un fallo de subida quemaría el token del cliente.'
		);
	}

	public function test_the_form_rejects_products_outside_the_order(): void {
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );

		$_POST = array( 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1',
			'_akbnonce'      => 'x',
			'akb_rf_product' => 999999,
			'akb_rf_rating'  => 5,
			'akb_rf_content' => 'Intento de reseñar lo que no compré.',
		);

		$this->assertSame( 'Elige uno de los productos de tu pedido.', $this->form->process( $token ) );
	}

	public function test_an_expired_token_renders_the_invalid_page(): void {
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );
		// Caducarlo a mano en la fila insertada.
		foreach ( $this->wpdb->inserts as $i => $ins ) {
			if ( Schema::review_forms_table() === $ins[0] ) {
				$this->wpdb->inserts[ $i ][1]['expires_at'] = '2000-01-01 00:00:00';
			}
		}

		$html = $this->form->render_page( $token );

		$this->assertStringContainsString( 'ya no sirve', $html );
	}

	/**
	 * El trabajo espera tres días. Si en ese rato el dueño mueve «Estado del
	 * pedido que dispara la invitación» —que es lo primero que haría para
	 * afinar el timing—, todas las invitaciones en cola se quedaban mudas y
	 * nadie se enteraba (hallazgo S-c839eb3a, 2026-09-18).
	 *
	 * @testdox Mover el estado disparador no mata las invitaciones ya agendadas.
	 */
	public function test_moving_the_trigger_does_not_kill_queued_invites(): void {
		// Disparador de hoy: un estado que NO está entre los pagados — el
		// selector del panel ofrece todos los estados reales de la tienda.
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_invite_status' === $option ? 'courier-dispatched' : $default_value
		);

		$order         = $this->completed_order( 100 );
		$order->status = 'courier-dispatched';

		$this->invitations->on_status_changed( 100, 'pending', 'courier-dispatched' );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 'courier-dispatched', (string) $order->get_meta( Invitations::META_TRIGGER ), 'Se sella con qué política se agendó.' );

		// Al día siguiente el dueño mueve la palanca a «Completado».
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_invite_status' === $option ? 'completed' : $default_value
		);

		$this->invitations->send_invite( 100 );

		$this->assertNotSame( '', (string) $order->get_meta( Invitations::META_SENT ), 'La decisión se tomó con la política de ese día.' );
	}

	/** @testdox Un pedido que ya no invita deja dicho por qué en sus notas. */
	public function test_a_discarded_invite_says_why(): void {
		$order = $this->completed_order( 100 );
		$this->invitations->on_status_changed( 100, 'processing', 'completed' );

		// El pedido termina en un estado que no es pagado ni el disparador.
		$order->status = 'on-hold';
		$this->invitations->send_invite( 100 );

		$this->assertSame( array(), $this->wc->mailer()->sent, 'No se invita.' );
		$this->assertStringContainsString( 'Invitación a reseñar detenida', implode( ' ', $order->notes ), 'Y no en silencio.' );
	}

	/**
	 * `wp_insert_comment()` des-escapa por dentro. La ruta del dueño pasaba
	 * por `wp_slash()` y ésta no, así que una barra invertida escrita por el
	 * cliente desaparecía de su propia reseña (hallazgo S-f6999ef2).
	 *
	 * El doble reproduce el viaje completo —escapar en la ruta, des-escapar
	 * dentro del núcleo— porque medir sólo una mitad no prueba nada.
	 *
	 * @testdox El texto del cliente llega entero a la reseña, barras incluidas.
	 */
	public function test_the_customer_text_survives_the_round_trip(): void {
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );

		Functions\when( 'wp_slash' )->alias(
			static fn( $data ) => is_array( $data ) ? array_map( static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v, $data ) : addslashes( (string) $data )
		);
		Functions\when( 'wp_insert_comment' )->alias(
			function ( array $data ): int {
				// Fiel al núcleo: `wp_insert_comment()` empieza con wp_unslash().
				$this->inserted_comment = array_map( static fn( $v ) => is_string( $v ) ? stripslashes( $v ) : $v, $data );
				return 901;
			}
		);

		$_POST = array( 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1',
			'_akbnonce'      => 'x',
			'akb_rf_product' => 777,
			'akb_rf_rating'  => 5,
			'akb_rf_content' => 'El tomo llegó perfecto: ruta C:\\manga\\tomo15 y un 100% de acuerdo.',
		);
		// La foto es obligatoria: sin ella el envío se rechaza antes de llegar
		// al insert que esta prueba mide.
		$_FILES = array( 'akb_rf_photo' => array( 'name' => 'tomo.jpg', 'tmp_name' => '/tmp/review.jpg', 'size' => 100, 'error' => UPLOAD_ERR_OK ) );

		$this->assertTrue( $this->form->process( $token ), 'El envío se acepta.' );
		$_POST  = array();
		$_FILES = array();

		$this->assertStringContainsString(
			'C:\\manga\\tomo15',
			(string) ( $this->inserted_comment['comment_content'] ?? '' ),
			'Lo que el cliente escribió es lo que se guarda.'
		);
	}
	/** @testdox Con el programa apagado el enlace acepta texto sin foto y deja un no explícito. */
	public function test_disabled_program_review_without_photo_records_no_reward_request(): void {
		Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => 'akb_rewards_reviews_enabled' === $key ? 'no' : $default );
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );
		$_POST = array( 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1', '_akbnonce' => 'x', 'akb_rf_product' => 777, 'akb_rf_rating' => 5, 'akb_rf_content' => 'Una edición excelente para mi colección.' );
		$_FILES = array();
		Functions\expect( 'media_handle_upload' )->never();
		$this->assertTrue( $this->form->process( $token ) );
		$this->assertSame( 0, $this->inserted_comment['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ] );
		$this->assertSame( array( 0 ), $this->wpdb->comment_meta[901][ ReviewPoints::META_POINTS_REQUESTED ] );
		$this->assertSame( '', get_comment_meta( 901, \Akibara\Rewards\Reviews\Media::META_LOCAL_IMAGE, true ) );
		$thanks = new \ReflectionMethod( ReviewForm::class, 'thanks_body' );
		$thanks->setAccessible( true );
		$this->assertStringContainsString( 'No solicitaste puntos', $thanks->invoke( $this->form ) );
		$this->assertStringNotContainsString( '500', $thanks->invoke( $this->form ) );
	}

	/** @testdox Pedir puntos sin foto no consume el enlace y permite corregir el envío. */
	public function test_reward_request_requires_photo_without_consuming_token(): void {
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );
		$_POST = array( 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1', '_akbnonce' => 'x', 'akb_rf_product' => 777, 'akb_rf_rating' => 5, 'akb_rf_content' => 'Una edición excelente para mi colección.', 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1' );
		$_FILES = array();
		$this->assertStringContainsString( 'Para solicitar puntos necesitas una foto', $this->form->process( $token ) );
		$this->assertSame( array(), $this->inserted_comment );
		$this->assertNotNull( ReviewForm::validate_token( $token ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		$html = $this->form->render_page( $token, 'Falta foto' );
		$this->assertStringContainsString( 'Una edición excelente para mi colección.</textarea>', $html );
		$this->assertStringContainsString( 'name="akb_rf_rating" value="5" required checked', $html );
		$this->assertStringContainsString( 'name="akb_rf_points_requested" value="1"', $html );
		$this->assertStringContainsString( 'name="akb_rf_product" value="777" required checked', $html );
		$_FILES = array( 'akb_rf_photo' => array( 'name' => 'tomo.jpg', 'tmp_name' => '/tmp/review.jpg', 'size' => 100, 'error' => UPLOAD_ERR_OK ) );
		$this->assertTrue( $this->form->process( $token ) );
		$this->assertSame( array( 1 ), $this->wpdb->comment_meta[901][ ReviewPoints::META_POINTS_REQUESTED ] );
	}

	/** @testdox El enlace original abre sólo la oferta con foto obligatoria y no consume el token al mostrarla. */
	public function test_default_reward_has_no_voluntary_exit_and_keeps_token_unused(): void {
		Functions\when( 'wp_nonce_field' )->justReturn( '<input name="_akbnonce" value="test">' );
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );
		$before = ReviewForm::validate_token( $token );
		$html = $this->form->render_page( $token );
		$this->assertStringContainsString( 'type="hidden" name="akb_rf_points_requested" value="1"', $html );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'Puedes obtener', $html );
		$this->assertStringNotContainsString( 'akb_review_mode=', $html );
		$this->assertStringNotContainsString( 'Prefiero enviar', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]+name="akb_rf_photo"[^>]+required/', $html );
		$this->assertStringContainsString( 'Todavía no lo recibí', $html );
		$this->assertStringContainsString( $before['expires_at'], $html );
		$this->assertSame( $before, ReviewForm::validate_token( $token ) );
		$this->assertSame( array(), $this->inserted_comment );
	}

	/** @testdox Con premios apagados no hay casilla ni se acepta un opt-in inyectado. */
	public function test_disabled_reward_lane_accepts_normal_review_without_reward(): void {
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => 'akb_rewards_reviews_enabled' === $key ? 'no' : $default );
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );
		$this->assertStringContainsString( 'name="akb_rf_points_requested" value="0"', $this->form->render_page( $token ) );
		$_POST = array( 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1', '_akbnonce' => 'x', 'akb_rf_product' => 777, 'akb_rf_rating' => 5, 'akb_rf_content' => 'Una edición excelente para mi colección.', 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1' );
		$_FILES = array();
		$this->assertTrue( $this->form->process( $token ) );
		$this->assertSame( array( 0 ), $this->wpdb->comment_meta[901][ ReviewPoints::META_POINTS_REQUESTED ] );
	}

	/** @testdox Manipular el modo a voluntario no permite eludir la foto en el enlace incentivado. */
	public function test_incentivized_link_rejects_voluntary_mode_without_consuming_token(): void {
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );
		$_POST = array( '_akbnonce' => 'x', 'akb_rf_mode' => 'voluntary', 'akb_rf_points_requested' => '1', 'akb_rf_product' => 777, 'akb_rf_rating' => 2, 'akb_rf_content' => 'Una edición interesante para mi colección.' );
		$_FILES = array( 'akb_rf_photo' => array( 'name' => 'tomo.jpg', 'tmp_name' => '/tmp/review.jpg', 'size' => 100, 'error' => UPLOAD_ERR_OK ) );
		self::assertStringContainsString( 'Este enlace solicita puntos', $this->form->process( $token ) );
		self::assertSame( array(), $this->inserted_comment );
		self::assertNotNull( ReviewForm::validate_token( $token ) );
	}

	/** @testdox El modo de premio exige el sí explícito del formulario antes de consumir un token. */
	public function test_reward_mode_requires_explicit_hidden_request(): void {
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );
		$_POST = array( '_akbnonce' => 'x', 'akb_rf_product' => 777, 'akb_rf_rating' => 5, 'akb_rf_content' => 'Una edición excelente para mi colección.' );
		$_FILES = array();
		self::assertStringContainsString( 'Este enlace solicita puntos', $this->form->process( $token ) );
		self::assertNotNull( ReviewForm::validate_token( $token ) );
	}

	/** @testdox Un fallo de MIME, tamaño o subida mantiene vivo el enlace y no crea una reseña. */
	public function test_invalid_reward_photo_does_not_consume_token(): void {
		$this->completed_order( 100 );
		$token = Invitations::issue_token( 100 );
		$_POST = array( '_akbnonce' => 'x', 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1', 'akb_rf_product' => 777, 'akb_rf_rating' => 5, 'akb_rf_content' => 'Una edición excelente para mi colección.' );
		foreach ( array( 'mime', 'size', 'error' ) as $case ) {
			Functions\when( 'wp_get_image_mime' )->justReturn( 'mime' === $case ? 'text/plain' : 'image/jpeg' );
			$_FILES = array( 'akb_rf_photo' => array( 'name' => 'tomo.jpg', 'tmp_name' => '/tmp/review.jpg', 'size' => 'size' === $case ? 5242881 : 100, 'error' => 'error' === $case ? UPLOAD_ERR_PARTIAL : UPLOAD_ERR_OK ) );
			self::assertStringContainsString( 'La foto no se pudo subir', $this->form->process( $token ), $case );
			self::assertNotNull( ReviewForm::validate_token( $token ), $case );
			self::assertSame( array(), $this->inserted_comment );
		}
	}

	/** @testdox Un comprador invitado conserva la oferta y el pedido vinculados para cobrar tras verificar su cuenta. */
	public function test_guest_reward_form_preserves_late_account_flow(): void {
		$order = $this->completed_order( 100 );
		$order->customer_id = 0;
		$token = Invitations::issue_token( 100 );
		$_POST = array( '_akbnonce' => 'x', 'akb_rf_mode' => 'reward', 'akb_rf_points_requested' => '1', 'akb_rf_product' => 777, 'akb_rf_rating' => 1, 'akb_rf_content' => 'Mi opinión sincera sobre esta compra.' );
		$_FILES = array( 'akb_rf_photo' => array( 'name' => 'tomo.jpg', 'tmp_name' => '/tmp/review.jpg', 'size' => 100, 'error' => UPLOAD_ERR_OK ) );
		self::assertTrue( $this->form->process( $token ) );
		self::assertSame( 0, $this->inserted_comment['user_id'] );
		self::assertSame( 'cliente@example.com', $this->inserted_comment['comment_author_email'] );
		self::assertSame( 1, $this->inserted_comment['comment_meta'][ ReviewPoints::META_POINTS_REQUESTED ] );
		self::assertSame( array( 100 ), $this->wpdb->comment_meta[901][ ReviewForm::META_ORDER ] );
	}

	/** @testdox Con vigencia de 1 día el correo dice «1 día» en singular, y en plural para más de uno. */
	public function test_token_days_singular_and_plural_copy_in_invitation_email(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => 'akb_rewards_invite_token_days' === $key ? 1 : $default
		);
		$this->completed_order( 100 );
		$this->invitations->send_invite( 100 );
		$correo = end( $this->wc->mailer()->sent );
		self::assertStringContainsString( 'vence en 1 día.', $correo['body'] );
		self::assertStringNotContainsString( '1 días', $correo['body'] );
	}

}
