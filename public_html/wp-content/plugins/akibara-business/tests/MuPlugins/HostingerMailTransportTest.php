<?php
/**
 * Tests for the Hostinger-only wp_mail transport.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Akibara\HostingerMail\Transport;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class HostingerMailTransportTest extends TestCase {

	/**
	 * Almacén simulado de options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Tercer argumento con que se guardó cada option.
	 *
	 * @var array<string, mixed>
	 */
	private array $autoloads = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options   = array();
		$this->autoloads = array();

		if ( ! class_exists( Transport::class, false ) ) {
			require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-correo-smtp.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Production must block mail while the private transport is disabled.
	 */
	public function test_blocks_production_fallback_when_disabled(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		self::assertFalse( Transport::guard_configuration( null, array() ) );
	}

	/**
	 * @testdox Local development may remain inert until its capture server is enabled.
	 */
	public function test_disabled_transport_is_inert_only_in_local(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		// Desde 1.2.2 el guard mira el dominio antes de la rama de clones.
		Functions\when( 'home_url' )->justReturn( 'http://localhost:8080' );

		self::assertSame( 'previous', Transport::guard_configuration( 'previous', array() ) );
	}

	/**
	 * @testdox Production must accept only the authenticated Hostinger profiles.
	 */
	public function test_validates_only_hostinger_production_profiles(): void {
		$base = array(
			'host'       => 'smtp.hostinger.com',
			'port'       => 465,
			'encryption' => 'ssl',
			'username'   => 'contacto@akibara.cl',
			'password'   => 'private-test-value',
		);

		self::assertSame( $base, Transport::validate_configuration( $base, 'production' ) );

		$tls               = $base;
		$tls['port']       = 587;
		$tls['encryption'] = 'tls';
		self::assertSame( $tls, Transport::validate_configuration( $tls, 'production' ) );

		$foreign         = $base;
		$foreign['host'] = 'smtp.example.test';
		self::assertNull( Transport::validate_configuration( $foreign, 'production' ) );

		$wrong_sender             = $base;
		$wrong_sender['username'] = 'otro@akibara.cl';
		self::assertNull( Transport::validate_configuration( $wrong_sender, 'production' ) );

		$missing_secret             = $base;
		$missing_secret['password'] = '';
		self::assertNull( Transport::validate_configuration( $missing_secret, 'production' ) );
	}

	/**
	 * @testdox Local Docker may use an authenticated SMTP capture service.
	 */
	public function test_allows_local_smtp_capture_profile(): void {
		$config = array(
			'host'       => 'mailpit',
			'port'       => 1025,
			'encryption' => '',
			'username'   => '',
			'password'   => '',
		);

		self::assertSame( $config, Transport::validate_configuration( $config, 'local' ) );

		/*
		 * Hallazgo S-26a2b389: un clon marcado como `staging` o `development`
		 * —un staging de Hostinger, una copia de QA— devolvía null aquí, el guard
		 * cortaba `wp_mail()` y NO salía un solo correo, mientras el guardarraíl
		 * de captura prometía que salían y llegaban a la bandeja de QA. Ahora
		 * cualquier entorno que no sea producción vale. Que el correo de un clon
		 * no llegue a un cliente real lo garantiza el guardarraíl de captura, que
		 * vive en la misma carpeta de mu-plugins y redirige todo lo que no sea
		 * producción.
		 */
		self::assertSame( $config, Transport::validate_configuration( $config, 'staging' ) );
		self::assertSame( $config, Transport::validate_configuration( $config, 'development' ) );

		$mailer = new class() {
			public bool $smtp = false;
			public string $Host = '';
			public int $Port = 0;
			public string $SMTPSecure = '';
			public bool $SMTPAuth = true;
			public string $Username = 'stale';
			public string $Password = 'stale';
			public bool $SMTPAutoTLS = true;
			public int $SMTPDebug = -1;
			public int $Timeout = 0;
			public array $SMTPOptions = array();
			public string $AuthType = 'LOGIN';
			public bool $SMTPKeepAlive = true;
			public string $From = '';
			public string $FromName = '';
			public string $Sender = '';

			public function isSMTP(): void {
				$this->smtp = true;
			}

			public function setFrom( string $email, string $name, bool $auto ): void {
				unset( $auto );
				$this->From     = $email;
				$this->FromName = $name;
			}
		};

		Transport::apply_configuration( $mailer, $config );

		self::assertTrue( $mailer->smtp );
		self::assertFalse( $mailer->SMTPAuth );
		self::assertSame( '', $mailer->Username );
		self::assertSame( '', $mailer->Password );
	}

	/**
	 * @testdox The validated profile must configure PHPMailer without debug output.
	 */
	public function test_applies_validated_profile_to_phpmailer(): void {
		$mailer = new class() {
			public bool $smtp = false;
			public string $From = '';
			public string $FromName = '';
			public string $Sender = '';
			public string $Host = '';
			public int $Port = 0;
			public string $SMTPSecure = '';
			public bool $SMTPAuth = false;
			public string $Username = '';
			public string $Password = '';
			public bool $SMTPAutoTLS = false;
			public int $SMTPDebug = -1;
			public int $Timeout = 0;
			public array $SMTPOptions = array( 'unsafe' => true );
			public string $AuthType = 'LOGIN';
			public bool $SMTPKeepAlive = true;
			public object $smtp_instance;

			public function __construct() {
				$this->smtp_instance = (object) array( 'Timelimit' => 300 );
			}

			public function isSMTP(): void {
				$this->smtp = true;
			}

			public function setFrom( string $email, string $name, bool $auto ): void {
				unset( $auto );
				$this->From     = $email;
				$this->FromName = $name;
			}

			public function getSMTPInstance(): object {
				return $this->smtp_instance;
			}
		};
		$config = array(
			'host'       => 'smtp.hostinger.com',
			'port'       => 465,
			'encryption' => 'ssl',
			'username'   => 'contacto@akibara.cl',
			'password'   => 'private-test-value',
		);

		Transport::apply_configuration( $mailer, $config );

		self::assertTrue( $mailer->smtp );
		self::assertSame( 'smtp.hostinger.com', $mailer->Host );
		self::assertSame( 465, $mailer->Port );
		self::assertSame( 'ssl', $mailer->SMTPSecure );
		self::assertTrue( $mailer->SMTPAuth );
		self::assertSame( 'contacto@akibara.cl', $mailer->Username );
		self::assertSame( 'private-test-value', $mailer->Password );
		self::assertFalse( $mailer->SMTPAutoTLS );
		self::assertSame( 0, $mailer->SMTPDebug );
		self::assertSame( 20, $mailer->Timeout );
		self::assertSame( array(), $mailer->SMTPOptions );
		self::assertSame( '', $mailer->AuthType );
		self::assertFalse( $mailer->SMTPKeepAlive );
		self::assertSame( 'contacto@akibara.cl', $mailer->From );
		self::assertSame( 'Akibara', $mailer->FromName );
		self::assertSame( 'contacto@akibara.cl', $mailer->Sender );
		self::assertSame( 20, $mailer->smtp_instance->Timelimit );
	}

	/**
	 * @testdox The production transport must contain no legacy provider integration.
	 */
	public function test_transport_has_no_legacy_provider_code(): void {
		$source = strtolower( (string) file_get_contents( dirname( __DIR__, 4 ) . '/mu-plugins/akibara-correo-smtp.php' ) );

		self::assertStringNotContainsString( 'brevo', $source );
		self::assertStringNotContainsString( 'sendinblue', $source );
		self::assertStringNotContainsString( 'resend', $source );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Rastro del corte. Hasta el 2026-08-23 NADA de esto estaba cubierto: como
	// el bootstrap no declara get_option/update_option, `function_exists()`
	// devolvía false y `record_outage()` salía por su guardia sin ejecutar una
	// línea. Se podía vaciar el método entero, renombrar la option o invertir la
	// causa y la suite seguía en verde — y de hecho ocurrió. Estos tests montan
	// un almacén de options de verdad para que el cuerpo se ejecute.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @testdox El rastro se borra cuando un correo SALE, nunca cuando PHPMailer se construye.
	 */
	public function test_clear_outage_cuelga_del_envio_y_no_de_la_construccion(): void {
		Filters\expectAdded( 'pre_wp_mail' )->once();
		Actions\expectAdded( 'phpmailer_init' )->once()
			->with( array( Transport::class, 'configure_phpmailer' ), PHP_INT_MAX, 1 );
		Actions\expectAdded( 'wp_mail_succeeded' )->once()
			->with( array( Transport::class, 'clear_outage' ), PHP_INT_MAX, 0 );
		Actions\expectAdded( 'wp_mail_failed' )->once()
			->with( array( Transport::class, 'record_send_failure' ), PHP_INT_MAX, 1 );
		Actions\expectAdded( 'admin_notices' )->once();

		Transport::register();

		// Brain Monkey verifica las cinco expectativas al cerrar el test (probado:
		// devolver `clear_outage` a `phpmailer_init` hace fallar este test con
		// InvalidCountException). PHPUnit no las cuenta como aserciones, así que
		// sin esto el test sale marcado como «risky» y el fallo real se confunde
		// con ruido.
		$this->addToAssertionCount( 5 );
	}

	/**
	 * @testdox Blocking a send must leave the cause, the hour and a non-autoloaded counter.
	 */
	public function test_el_guard_deja_rastro_del_corte_con_causa_y_sin_autoload(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		$this->stub_option_store();

		self::assertFalse( Transport::guard_configuration( null, array() ) );

		$state = $this->options[ Transport::OPTION_OUTAGE ] ?? null;
		self::assertIsArray( $state, 'el corte no dejó rastro: record_outage() no escribió nada' );
		self::assertSame( Transport::KIND_BLOCKED, $state['kind'] );
		self::assertSame( 'transporte deshabilitado', $state['reason'] );
		self::assertSame( 1, $state['count'] );
		self::assertSame( $state['first_gmt'], $state['last_gmt'] );
		self::assertFalse(
			$this->autoloads[ Transport::OPTION_OUTAGE ],
			'la option del corte no debe cargarse en cada petición'
		);
	}

	/**
	 * @testdox A running outage keeps its first hour and counts up.
	 */
	public function test_conserva_el_primer_corte_e_incrementa_el_conteo(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		$this->stub_option_store();
		$this->options[ Transport::OPTION_OUTAGE ] = array(
			'kind'      => Transport::KIND_BLOCKED,
			'reason'    => 'transporte deshabilitado',
			'first_gmt' => '2026-08-18 00:00:00',
			'last_gmt'  => '2026-08-18 00:00:00',
			'count'     => 4,
		);

		Transport::guard_configuration( null, array() );

		$state = $this->options[ Transport::OPTION_OUTAGE ];
		self::assertSame( 5, $state['count'] );
		self::assertSame( '2026-08-18 00:00:00', $state['first_gmt'] );
		self::assertNotSame( '2026-08-18 00:00:00', $state['last_gmt'] );
	}

	/**
	 * @testdox An SMTP rejection leaves its own trace and restarts the count.
	 */
	public function test_un_rechazo_del_smtp_deja_rastro_propio_y_reinicia_el_conteo(): void {
		$this->stub_option_store();
		$this->options[ Transport::OPTION_OUTAGE ] = array(
			'kind'      => Transport::KIND_BLOCKED,
			'reason'    => 'transporte deshabilitado',
			'first_gmt' => '2026-08-18 00:00:00',
			'last_gmt'  => '2026-08-18 00:00:00',
			'count'     => 4,
		);

		Transport::record_send_failure( $this->wp_error( "SMTP Error:   Could not\n authenticate." ) );

		$state = $this->options[ Transport::OPTION_OUTAGE ];
		self::assertSame( Transport::KIND_SEND_FAILED, $state['kind'] );
		self::assertSame( 1, $state['count'], 'mezclar cortes del guard con rechazos del SMTP daría un número que no describe nada' );
		self::assertSame( 'SMTP Error: Could not authenticate.', $state['reason'] );
	}

	/**
	 * @testdox A listener that mails about the outage must not recurse.
	 */
	public function test_el_candado_corta_la_reentrada(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		$this->stub_option_store();

		$reentered = false;
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) use ( &$reentered ) {
				$this->options[ $key ]   = $value;
				$this->autoloads[ $key ] = $autoload;

				if ( ! $reentered ) {
					$reentered = true;
					// Cualquier oyente del guardado que intente avisar por correo
					// vuelve a entrar por wp_mail() y choca de nuevo contra el
					// guard: recursión sin fondo dentro del pedido de un cliente.
					Transport::guard_configuration( null, array() );
				}

				return true;
			}
		);

		Transport::guard_configuration( null, array() );

		self::assertTrue( $reentered, 'el test no llegó a simular la reentrada' );
		self::assertSame( 1, $this->options[ Transport::OPTION_OUTAGE ]['count'] );
	}

	/**
	 * @testdox Recording an outage must not fire an action nobody listens to.
	 */
	public function test_anotar_el_corte_no_dispara_una_accion_sin_oyente(): void {
		// Había un candado de reentrada, tres líneas de explicación y una prueba
		// dedicada para proteger un mecanismo que no existía: la acción no tenía
		// un solo oyente. Código muerto —que la casa prohíbe— y, peor, una promesa
		// escrita de que alguien reaccionaba al corte (S-8302c3f2).
		$fuente = (string) file_get_contents( dirname( __DIR__, 4 ) . '/mu-plugins/akibara-correo-smtp.php' );

		self::assertStringNotContainsString(
			'do_action(',
			$fuente,
			'quien avisa del corte es el aviso rojo del escritorio, que no depende de que el correo funcione'
		);
	}

	/**
	 * @testdox «Live» must be one single definition, and a priority of 0 must not read as dead.
	 */
	public function test_vivo_es_una_sola_definicion(): void {
		Functions\when( 'has_action' )->justReturn( 0 );
		self::assertTrue( Transport::is_live(), 'has_action devuelve la PRIORIDAD: 0 es válida' );

		Functions\when( 'has_action' )->justReturn( false );
		self::assertFalse( Transport::is_live() );
	}

	/**
	 * @testdox A blocked send must leave WHO it was for and WHAT it said.
	 */
	public function test_anota_que_envio_se_quedo_sin_salir(): void {
		// El incidente de agosto se descubrió porque un cliente reclamó: el rastro
		// decía «se bloquearon 14 envíos» y no QUÉ 14 ni a quién (S-04d13bef).
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		$this->stub_option_store();

		Transport::guard_configuration(
			null,
			array(
				'to'      => 'clienta@example.com',
				'subject' => 'Tu pedido #27720 está en camino',
			)
		);

		$bloqueados = $this->options[ Transport::OPTION_OUTAGE ]['blocked'];
		self::assertCount( 1, $bloqueados );
		self::assertSame( 'clienta@example.com', $bloqueados[0]['to'] );
		self::assertSame( 'Tu pedido #27720 está en camino', $bloqueados[0]['subject'] );
		self::assertNotEmpty( $bloqueados[0]['gmt'] );
	}

	/**
	 * @testdox The list of lost mail is capped, keeps the latest, and skips entries with nothing to say.
	 */
	public function test_la_lista_de_correos_perdidos_se_acota(): void {
		$lista = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$lista = Transport::remember_blocked(
				$lista,
				array( 'to' => "cliente{$i}@example.com", 'subject' => "Pedido {$i}" ),
				'2026-09-15 10:00:00'
			);
		}

		self::assertCount( 20, $lista );
		self::assertSame( 'cliente25@example.com', $lista[19]['to'], 'se conservan los últimos' );

		$sin_datos = Transport::remember_blocked( array(), array(), '2026-09-15 10:00:00' );
		self::assertSame( array(), $sin_datos, 'un rechazo sin datos del envío no añade una fila vacía' );
	}

	/**
	 * @testdox Customer mail kept in the trace must expire: it is there to rewrite it, and that use expires.
	 */
	public function test_los_correos_guardados_caducan(): void {
		$viejo = array(
			array(
				'gmt'     => gmdate( 'Y-m-d H:i:s', time() - ( 40 * 86400 ) ),
				'to'      => 'antigua@example.com',
				'subject' => 'Pedido de hace mes y medio',
			),
		);

		$lista = Transport::remember_blocked(
			$viejo,
			array( 'to' => 'nueva@example.com', 'subject' => 'Pedido de hoy' ),
			gmdate( 'Y-m-d H:i:s' )
		);

		self::assertCount( 1, $lista, 'el tope de veinte no es una caducidad: con poco tráfico serían de hace medio año' );
		self::assertSame( 'nueva@example.com', $lista[0]['to'] );
	}

	/**
	 * @testdox The outage notice must name the mail that did not go out.
	 */
	public function test_el_aviso_nombra_los_correos_que_no_salieron(): void {
		$this->stub_option_store();
		$this->stub_notice_i18n();
		$this->options[ Transport::OPTION_OUTAGE ] = array(
			'kind'      => Transport::KIND_BLOCKED,
			'reason'    => 'transporte deshabilitado',
			'first_gmt' => '2026-09-15 10:00:00',
			'last_gmt'  => '2026-09-15 10:00:00',
			'count'     => 1,
			'blocked'   => array(
				array(
					'gmt'     => '2026-09-15 10:00:00',
					'to'      => 'clienta@example.com',
					'subject' => 'Tu pedido #27720 está en camino',
				),
			),
		);

		$salida = $this->render_notice();

		self::assertStringContainsString( 'clienta@example.com', $salida );
		self::assertStringContainsString( 'Tu pedido #27720', $salida );
	}

	/**
	 * @testdox One rejected address must stay quiet; three in a row must shout.
	 */
	public function test_el_aviso_calla_ante_un_rechazo_aislado_y_grita_al_tercero(): void {
		$this->stub_option_store();
		$this->stub_notice_i18n();
		$this->options[ Transport::OPTION_OUTAGE ] = array(
			'kind'      => Transport::KIND_SEND_FAILED,
			'reason'    => 'Invalid address: (to): no-existe@',
			'first_gmt' => '2026-08-23 00:00:00',
			'last_gmt'  => '2026-08-23 00:00:00',
			'count'     => 2,
		);

		self::assertSame( '', $this->render_notice(), 'una dirección mal escrita no es una caída del correo' );

		$this->options[ Transport::OPTION_OUTAGE ]['count'] = 3;
		$salida = $this->render_notice();

		self::assertStringContainsString( 'El correo de Akibara NO está saliendo', $salida );
		self::assertStringContainsString( 'rechazó 3 envíos seguidos', $salida );
	}

	/**
	 * @testdox A single blocked send shouts immediately, and in the singular.
	 */
	public function test_el_aviso_grita_desde_el_primer_corte_y_concuerda_en_singular(): void {
		$this->stub_option_store();
		$this->stub_notice_i18n();
		$this->options[ Transport::OPTION_OUTAGE ] = array(
			'kind'      => Transport::KIND_BLOCKED,
			'reason'    => 'transporte deshabilitado',
			'first_gmt' => '2026-08-23 00:00:00',
			'last_gmt'  => '2026-08-23 00:00:00',
			'count'     => 1,
		);

		$salida = $this->render_notice();

		self::assertStringContainsString( 'El correo de Akibara NO está saliendo', $salida );
		self::assertStringContainsString( 'Se bloqueó 1 envío,', $salida );
		self::assertStringNotContainsString( '1 envíos', $salida );
	}

	/**
	 * @testdox Without the manage_woocommerce capability the notice prints nothing.
	 */
	public function test_el_aviso_calla_sin_capacidad(): void {
		$this->stub_option_store();
		$this->stub_notice_i18n();
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->options[ Transport::OPTION_OUTAGE ] = array(
			'kind'      => Transport::KIND_BLOCKED,
			'reason'    => 'transporte deshabilitado',
			'first_gmt' => '2026-08-23 00:00:00',
			'last_gmt'  => '2026-08-23 00:00:00',
			'count'     => 9,
		);

		self::assertSame( '', $this->render_notice() );
	}

	/**
	 * Declara get_option/update_option/delete_option sobre un almacén observable.
	 */
	private function stub_option_store(): void {
		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) {
				return $this->options[ $key ] ?? $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) {
				$this->options[ $key ]   = $value;
				$this->autoloads[ $key ] = $autoload;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );

				return true;
			}
		);
	}

	/**
	 * Mínimo de funciones de WordPress que el aviso necesita para renderizarse.
	 */
	private function stub_notice_i18n(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'number_format_i18n' )->alias( static fn( $number ) => (string) $number );
		Functions\when( '__' )->alias( static fn( $text ) => $text );
		Functions\when( 'esc_html__' )->alias( static fn( $text ) => $text );
		Functions\when( 'esc_html' )->alias( static fn( $text ) => $text );
		Functions\when( '_n' )->alias(
			static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural
		);
		// Obligatorio, no opcional. `render_outage_notice()` sólo llama a wp_date()
		// si `function_exists()` la ve, y bajo Brain Monkey eso depende de si OTRO
		// test de la suite la mockeó antes: aislado no existía y el test pasaba,
		// dentro de la suite completa existía y reventaba con
		// MissingFunctionExpectations. Declararla acá hace el resultado igual en
		// los dos casos — es la misma trampa que documenta el mu-plugin sobre
		// `is_admin()`.
		Functions\when( 'wp_date' )->justReturn( '23 de agosto, 00:00' );
	}

	/**
	 * Captura la salida del aviso del admin.
	 */
	private function render_notice(): string {
		ob_start();
		Transport::render_outage_notice();

		return (string) ob_get_clean();
	}

	/**
	 * Doble de WP_Error con lo único que el transporte le pide.
	 *
	 * @param string $message Mensaje del error.
	 */
	private function wp_error( string $message ): object {
		return new class( $message ) {
			/** @var string */
			private string $message;

			public function __construct( string $message ) {
				$this->message = $message;
			}

			public function get_error_message(): string {
				return $this->message;
			}
		};
	}
}
