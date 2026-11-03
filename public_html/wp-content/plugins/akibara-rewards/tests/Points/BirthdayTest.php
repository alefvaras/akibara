<?php
/**
 * Contrato del regalo de cumpleaños (monto del dueño: 1.000).
 *
 * Fija: solo día/mes se guardan (el año se descarta — dato mínimo), UN pago
 * por año por usuario (meta + event_key contra el UNIQUE), el 29-02 cobra el
 * 01-03 en años no bisiestos, y un fallo de infraestructura NO sella el año
 * (mañana reintenta).
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\Birthday;
use Akibara\Rewards\Points\Emails;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * @since 0.2.0
 */
final class BirthdayTest extends RewardsTestCase {

	private Birthday $birthday;

	protected function setUp(): void {
		parent::setUp();

		$this->birthday = new Birthday();

		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				$md  = (string) ( $args['meta_value'] ?? '' );
				$ids = array();
				foreach ( $this->wpdb->user_meta as $uid => $metas ) {
					if ( ( $metas[ Birthday::META_MD ] ?? '' ) === $md ) {
						$ids[] = $uid;
					}
				}
				return $ids;
			}
		);
	}

	public function test_saving_keeps_only_month_and_day(): void {
		$_POST['akb_rw_birthday_dia'] = '30';
		$_POST['akb_rw_birthday_mes'] = '8';

		$this->birthday->save_field( 7 );

		$this->assertSame( '08-30', $this->wpdb->user_meta[7][ Birthday::META_MD ], 'Solo día y mes: el año jamás se pide ni se almacena.' );

		$_POST['akb_rw_birthday_dia'] = '0';
		$_POST['akb_rw_birthday_mes'] = '0';
		$this->birthday->save_field( 7 );
		$this->assertArrayNotHasKey( Birthday::META_MD, $this->wpdb->user_meta[7] );
		unset( $_POST['akb_rw_birthday_dia'], $_POST['akb_rw_birthday_mes'] );
	}

	public function test_impossible_dates_are_rejected_out_loud(): void {
		$avisos = array();
		Functions\when( 'wc_add_notice' )->alias(
			static function ( string $msg, string $type = 'notice' ) use ( &$avisos ): void {
				$avisos[] = $type;
			}
		);

		$_POST['akb_rw_birthday_dia'] = '31';
		$_POST['akb_rw_birthday_mes'] = '2'; // 31 de febrero no existe.
		$this->birthday->save_field( 7 );

		$this->assertArrayNotHasKey( Birthday::META_MD, $this->wpdb->user_meta[7] ?? array() );
		$this->assertSame( array( 'error' ), $avisos, 'La entrada inválida se DICE, no se traga.' );

		// El 29-02 SÍ es un cumpleaños válido.
		$_POST['akb_rw_birthday_dia'] = '29';
		$_POST['akb_rw_birthday_mes'] = '2';
		$this->birthday->save_field( 7 );
		$this->assertSame( '02-29', $this->wpdb->user_meta[7][ Birthday::META_MD ] );
		unset( $_POST['akb_rw_birthday_dia'], $_POST['akb_rw_birthday_mes'] );
	}

	public function test_the_recovery_window_pays_yesterdays_missed_birthdays(): void {
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );
		$ayer = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) ) )->modify( '-1 day' );
		$this->wpdb->user_meta[7][ Birthday::META_MD ] = $ayer->format( 'm-d' );

		// La corrida de AYER nunca ocurrió (cron caído); la de hoy recupera.
		$this->birthday->run_daily();

		$this->assertSame( 1000, Ledger::balance( 7 ), 'Un día de cron caído no roba el regalo del año.' );
	}

	public function test_la_ventana_aguanta_tres_dias_de_correo_caido(): void {
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );
		$hace_tres = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) ) )->modify( '-3 day' );
		$this->wpdb->user_meta[7][ Birthday::META_MD ] = $hace_tres->format( 'm-d' );

		$this->birthday->run_daily();

		self::assertSame(
			1000,
			Ledger::balance( 7 ),
			'La tienda ya pasó tres días seguidos sin enviar correo: la ventana tiene que cubrirlos.'
		);
		self::assertSame(
			(int) $hace_tres->format( 'Y' ),
			(int) $this->wpdb->user_meta[7][ Birthday::META_PAID_YEAR ],
			'El regalo se sella con el año del CUMPLEAÑOS, no con el de hoy: si no, un 31-12 recuperado en enero se come el regalo del año nuevo.'
		);
	}

	public function test_fuera_de_la_ventana_no_se_paga(): void {
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );
		$hace_cinco = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) ) )->modify( '-5 day' );
		$this->wpdb->user_meta[7][ Birthday::META_MD ] = $hace_cinco->format( 'm-d' );

		$this->birthday->run_daily();

		self::assertSame( 0, Ledger::balance( 7 ), 'La ventana es de recuperación, no un repechaje sin fin.' );
	}

	public function test_pays_once_per_year_with_the_owners_amount(): void {
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );
		$this->wpdb->user_meta[7][ Birthday::META_MD ] = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) ) )->format( 'm-d' );

		$this->birthday->run_daily();
		$this->birthday->run_daily(); // La segunda corrida del día no re-paga.

		$this->assertSame( 1000, Ledger::balance( 7 ), 'El monto es el dictado por el dueño: 1.000.' );
		$anio_santiago = (int) ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) ) )->format( 'Y' );
		$this->assertSame( $anio_santiago, (int) $this->wpdb->user_meta[7][ Birthday::META_PAID_YEAR ], 'El año sellado es el de Santiago, no UTC.' );

		$claves = array_column( array_column( $this->wpdb->inserts, 1 ), 'event_key' );
		$this->assertCount( 1, array_filter( $claves, static fn( $k ) => str_starts_with( (string) $k, 'birthday_7_' ) ) );
	}

	public function test_an_infrastructure_failure_does_not_seal_the_year(): void {
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );
		$this->wpdb->user_meta[7][ Birthday::META_MD ] = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) ) )->format( 'm-d' );
		$this->wpdb->lock_result = '0';

		$this->birthday->run_daily();

		$this->assertSame( 0, Ledger::balance( 7 ) );
		$this->assertArrayNotHasKey( Birthday::META_PAID_YEAR, $this->wpdb->user_meta[7], 'Mañana reintenta.' );
	}

	public function test_the_birthday_email_greets_once_with_the_configured_amount(): void {
		$this->install_fake_wc();
		$u             = new \WP_User( 7 );
		$u->user_email = 'cumple@example.com';
		Functions\when( 'get_user_by' )->justReturn( $u );
		Functions\when( 'wc_get_account_endpoint_url' )->justReturn( 'https://akibara.cl/mi-cuenta/rewards/' );
		$this->wpdb->user_meta[7]['_akb_rewards_points'] = 1400;

		$emails = new \Akibara\Rewards\Points\Emails();
		$emails->send_birthday_email( 7, 1000, 2026 );
		$emails->send_birthday_email( 7, 1000, 2026 ); // Mismo año: un solo saludo.

		$wc = $GLOBALS['akb_rewards_test_wc'];
		$this->assertCount( 1, $wc->the_mailer->sent );
		$mail = $wc->the_mailer->sent[0];
		$this->assertSame( 'cumple@example.com', $mail['to'] );
		$this->assertStringContainsString( '1.000', $mail['subject'] );
		$this->assertStringContainsString( 'Canjear mis puntos', $mail['body'], 'El CTA de canje que pidió el dueño.' );
		$this->assertStringContainsString( '1.400', $mail['body'], 'Con el saldo actual.' );
	}

	public function test_the_field_also_lives_in_the_registration_form(): void {
		// La pregunta del dueño: ¿en qué momento lo coloca? También al CREAR
		// la cuenta. Candado de superficie sobre los hooks registrados.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Points/Birthday.php' );
		$this->assertStringContainsString( "add_action( 'woocommerce_register_form'", $source );
		$this->assertStringContainsString( "add_action( 'woocommerce_created_customer'", $source );
	}

	public function test_the_birthday_pagination_copies_the_expiry_pattern(): void {
		$vistos = array();
		Functions\when( 'get_users' )->alias(
			function ( array $args ) use ( &$vistos ): array {
				$vistos[] = $args;
				return array();
			}
		);
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );

		$this->birthday->run_daily();

		$this->assertNotSame( array(), $vistos );
		foreach ( $vistos as $args ) {
			// Sin orderby=ID el default (user_login) ordena el conjunto
			// COMPLETO en cada página; count_total paga un conteo que nadie
			// lee. El arreglo estaba documentado a dos ficheros de distancia.
			$this->assertSame( 'ID', $args['orderby'] ?? '' );
			$this->assertFalse( $args['count_total'] ?? true );
			$this->assertSame( 200, $args['number'] ?? 0 );
		}
	}

	public function test_zero_config_turns_the_gift_off(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_birthday_points' === $option ? 0 : $default_value
		);
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );
		$this->wpdb->user_meta[7][ Birthday::META_MD ] = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) ) )->format( 'm-d' );

		$this->birthday->run_daily();

		$this->assertCount( 0, $this->wpdb->inserts );
	}

	/**
	 * @testdox Si el regalo se pagó pero el saludo no salió, se vuelve a pedir dentro de la ventana.
	 *
	 * El correo sella su año sólo con transporte confirmado; con el SMTP caído
	 * el día del regalo el cliente se quedaba sin saludo todo el año (QA
	 * akibara-3f, 2026-09-12). El hook se re-dispara; el correo tiene su
	 * propio candado y no repite.
	 */
	public function test_a_paid_birthday_without_greeting_re_fires_the_hook_inside_the_window(): void {
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );
		$hoy  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) );
		$year = (int) $hoy->format( 'Y' );
		$this->wpdb->user_meta[7][ Birthday::META_MD ]        = $hoy->format( 'm-d' );
		$this->wpdb->user_meta[7][ Birthday::META_PAID_YEAR ] = $year; // Ya cobró; el saludo nunca salió.

		Actions\expectDone( 'akb_rewards_birthday_paid' )->once()->with( 7, 1000, $year );

		$this->birthday->run_daily();

		$this->assertSame( 0, Ledger::balance( 7 ), 'Re-pedir el saludo no vuelve a pagar.' );
		$this->assertSame( array(), $this->wpdb->inserts );
	}

	/** @testdox Con el saludo ya enviado este año, no se vuelve a pedir. */
	public function test_a_greeted_birthday_stays_quiet(): void {
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );
		$hoy  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) );
		$year = (int) $hoy->format( 'Y' );
		$this->wpdb->user_meta[7][ Birthday::META_MD ]                    = $hoy->format( 'm-d' );
		$this->wpdb->user_meta[7][ Birthday::META_PAID_YEAR ]             = $year;
		$this->wpdb->user_meta[7][ Emails::META_BIRTHDAY_GREETED_YEAR ]  = $year;

		Actions\expectDone( 'akb_rewards_birthday_paid' )->never();

		$this->birthday->run_daily();
		$this->addToAssertionCount( 1 ); // La expectativa «never» de arriba es la prueba.
	}

	/** @testdox En Mi cuenta avisa si el regalo de este año ya se pagó y el cambio aplicará desde el próximo. */
	public function test_render_field_notes_when_current_year_gift_was_already_paid(): void {
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'selected' )->alias( static fn( $a, $b ): string => (int) $a === (int) $b ? ' selected="selected"' : '' );

		$hoy  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Santiago' ) );
		$year = (int) $hoy->format( 'Y' );
		$this->wpdb->user_meta[7][ Birthday::META_MD ] = '05-14';

		ob_start();
		$this->birthday->render_field();
		$sin_cobrar = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'akb-rw-birthday-note', $sin_cobrar );

		$this->wpdb->user_meta[7][ Birthday::META_PAID_YEAR ] = $year;
		ob_start();
		$this->birthday->render_field();
		$ya_cobrado = (string) ob_get_clean();
		$this->assertStringContainsString( 'akb-rw-birthday-note', $ya_cobrado );
		$this->assertStringContainsString( 'aplicará desde el próximo', $ya_cobrado );
	}

	/** @testdox El dashboard de Mi Cuenta muestra el recordatorio sólo a usuarios sin cumpleaños registrado. */
	public function test_render_dashboard_reminder_shows_banner_when_birthday_empty(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wc_customer_edit_account_url' )->justReturn( 'https://akibara.cl/mi-cuenta/edit-account/' );

		// 1. Sin cumpleaños registrado: imprime banner
		unset( $this->wpdb->user_meta[7][ Birthday::META_MD ] );
		ob_start();
		$this->birthday->render_dashboard_reminder();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'akb-dashboard__birthday-banner', $html );
		$this->assertStringContainsString( '1.000 Puntos Akibara', $html );
		$this->assertStringContainsString( 'https://akibara.cl/mi-cuenta/edit-account/', $html );

		// 2. Con cumpleaños registrado: no imprime nada
		$this->wpdb->user_meta[7][ Birthday::META_MD ] = '05-14';
		ob_start();
		$this->birthday->render_dashboard_reminder();
		$html_silencioso = (string) ob_get_clean();

		$this->assertSame( '', $html_silencioso, 'Si el cliente ya tiene cumpleaños, no se le muestra el banner.' );
	}
}
