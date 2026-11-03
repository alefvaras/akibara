<?php
/**
 * Tests del centinela del transporte de correo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Akibara\MailTransportWatch\Watch;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class MailTransportWatchTest extends TestCase {

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

	/** @var mixed Database installed by another test, restored on teardown. */
	private $previous_wpdb;

	/** @var object Advisory-lock database double. */
	private object $database;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options   = array();
		$this->autoloads = array();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->database = new class {
			public string $options = 'wp_options';
			public $owner = null;
			public int $lock_result = 1;
			public bool $throw_on_lock = false;
			public array $queries = array();

			public function prepare( string $query, ...$args ): string {
				return $query . ' ' . json_encode( $args );
			}

			public function get_var( string $query ) {
				$this->queries[] = $query;
				if ( str_starts_with( $query, 'SELECT RELEASE_LOCK' ) ) {
					$this->owner = null;
					return 1;
				}
				if ( $this->throw_on_lock ) {
					throw new \RuntimeException( 'database unavailable' );
				}
				if ( 1 !== $this->lock_result ) {
					return $this->lock_result;
				}
				$caller = \Fiber::getCurrent() ?? 'main';
				if ( null !== $this->owner && $this->owner !== $caller ) {
					\Fiber::suspend();
				}
				$this->owner = $caller;
				return 1;
			}
		};
		$GLOBALS['wpdb'] = $this->database;
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$mu = dirname( __DIR__, 4 ) . '/mu-plugins/';
		// El transporte primero: el centinela pregunta por su clase, y si otro
		// archivo de la suite ya la cargó el resultado dependería del orden.
		if ( ! class_exists( 'Akibara\HostingerMail\Transport', false ) ) {
			require_once $mu . 'akibara-correo-smtp.php';
		}
		if ( ! class_exists( Watch::class, false ) ) {
			require_once $mu . 'akibara-mail-transport-watch.php';
		}

		// El centinela sólo inspecciona una vez por petición; en la suite todas
		// comparten proceso, así que hay que devolver ese candado a cero entre
		// tests o sólo el primero ejercitaría de verdad `inspect()`.
		$inspected = new \ReflectionProperty( Watch::class, 'inspected' );
		$inspected->setAccessible( true );
		$inspected->setValue( null, false );

		// Desde 1.1.0 `inspect()` sólo actúa en el cron o para quien administra la
		// tienda: `admin_init` también corre en la petición de una clienta que va
		// a sus pedidos, y eso bastaba para copiar un archivo en mu-plugins.
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->stub_option_store();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Concurrent state saves preserve both restore events.
	 */
	public function test_concurrent_state_saves_preserve_both_restore_events(): void {
		$save = new \ReflectionMethod( Watch::class, 'save' );
		$save->setAccessible( true );
		$first = null;
		$paused = false;
		Functions\when( 'get_option' )->alias(
			function ( $key, $default_value = false ) use ( &$first, &$paused ) {
				$snapshot = $this->options[ $key ] ?? $default_value;
				if ( Watch::OPTION_STATE === $key && \Fiber::getCurrent() === $first && ! $paused ) {
					$paused = true;
					\Fiber::suspend();
				}
				return $snapshot;
			}
		);

		$first = new \Fiber( static fn() => $save->invoke( null, array( 'restores' => array( '2026-11-02 12:00:00' ) ) ) );
		$second = new \Fiber( static fn() => $save->invoke( null, array( 'restores' => array( '2026-11-02 12:01:00' ) ) ) );
		$first->start();
		$second->start();
		$first->resume();
		if ( $second->isSuspended() ) {
			$second->resume();
		}

		self::assertSame(
			array( '2026-11-02 12:00:00', '2026-11-02 12:01:00' ),
			$this->options[ Watch::OPTION_STATE ]['restores']
		);
		self::assertNull( $this->database->owner );
	}

	/** @testdox A busy lock leaves the stored state untouched. */
	public function test_busy_state_lock_does_not_write(): void {
		$this->database->lock_result = 0;
		$this->options[ Watch::OPTION_STATE ] = array( 'last_cron_gmt' => '2026-11-02 12:00:00' );
		$before = $this->options;
		self::assertFalse( $this->save_state( array( 'last_cron_gmt' => '2026-11-02 12:01:00' ) ) );
		self::assertSame( $before, $this->options );
		self::assertCount( 1, $this->database->queries );
	}

	/** @testdox Lock database errors do not escape into the request. */
	public function test_state_lock_error_does_not_write(): void {
		$this->database->throw_on_lock = true;
		self::assertFalse( $this->save_state( array( 'last_cron_gmt' => '2026-11-02 12:01:00' ) ) );
		self::assertSame( array(), $this->options );
	}

	/** @testdox Fresh database state replaces a stale request cache before merging. */
	public function test_state_save_refreshes_a_stale_option_cache(): void {
		$this->options[ Watch::OPTION_STATE ] = array( 'restores' => array( '2026-11-02 12:00:00' ) );
		$stale = true;
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( $key ) use ( &$stale ): bool {
				if ( Watch::OPTION_STATE === $key ) {
					$stale = false;
				}
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( &$stale ) {
				return $stale ? $default : ( $this->options[ $key ] ?? $default );
			}
		);
		self::assertTrue( $this->save_state( array( 'restores' => array( '2026-11-02 12:01:00' ) ) ) );
		self::assertCount( 2, $this->options[ Watch::OPTION_STATE ]['restores'] );
	}

	/** @testdox A failed write reports failure and always releases the state lock. */
	public function test_failed_state_write_releases_the_lock(): void {
		Functions\when( 'update_option' )->justReturn( false );
		self::assertFalse( $this->save_state( array( 'last_cron_gmt' => '2026-11-02 12:01:00' ) ) );
		self::assertNull( $this->database->owner );
		self::assertSame( array(), $this->options );
	}

	/** @testdox A throwing write also releases the state lock. */
	public function test_throwing_state_write_releases_the_lock(): void {
		Functions\when( 'update_option' )->alias( static function (): void {
			throw new \RuntimeException( 'write unavailable' );
		} );
		self::assertFalse( $this->save_state( array( 'last_cron_gmt' => '2026-11-02 12:01:00' ) ) );
		self::assertNull( $this->database->owner );
	}

	/** @testdox An older snapshot cannot move the acknowledgement or heartbeat backwards. */
	public function test_state_timestamps_do_not_regress(): void {
		$this->options[ Watch::OPTION_STATE ] = array(
			'history_ack' => '2026-11-02 12:02:00',
			'last_cron_gmt' => '2026-11-02 12:03:00',
		);
		self::assertTrue( $this->save_state( array(
			'history_ack' => '2026-11-02 12:00:00',
			'last_cron_gmt' => '2026-11-02 12:01:00',
		) ) );
		self::assertSame( '2026-11-02 12:02:00', $this->options[ Watch::OPTION_STATE ]['history_ack'] );
		self::assertSame( '2026-11-02 12:03:00', $this->options[ Watch::OPTION_STATE ]['last_cron_gmt'] );
	}

	/** @param array<string,mixed> $state State to persist. */
	private function save_state( array $state ): bool {
		$method = new \ReflectionMethod( Watch::class, 'save' );
		$method->setAccessible( true );
		return $method->invoke( null, $state );
	}

	/**
	 * @testdox A hook registered at priority 0 must not read as a dead transport.
	 */
	public function test_una_prioridad_cero_no_significa_transporte_muerto(): void {
		// has_action() devuelve la PRIORIDAD, no un booleano. Con `if ( ! has_action(...) )`
		// una prioridad 0 —perfectamente válida— haría que el centinela declarara
		// muerto un transporte sano y se pusiera a reponer archivos encima de un
		// sitio que funciona.
		Functions\when( 'has_action' )->justReturn( 0 );

		Watch::inspect();

		self::assertArrayNotHasKey( Watch::OPTION_STATE, $this->options );
	}

	/**
	 * @testdox Losing the transport hook must leave a dated trace.
	 */
	public function test_sin_el_hook_el_centinela_anota_el_corte(): void {
		Functions\when( 'has_action' )->justReturn( false );

		Watch::inspect();

		$state = $this->options[ Watch::OPTION_STATE ] ?? null;
		self::assertIsArray( $state );
		self::assertNotEmpty( $state['missing_since'] );
		self::assertSame( $state['missing_since'], $state['last_seen_gmt'] );
		self::assertFalse(
			$this->autoloads[ Watch::OPTION_STATE ],
			'el estado del centinela no debe cargarse en cada petición'
		);
	}

	/**
	 * @testdox The first outage hour survives later checks.
	 */
	public function test_conserva_el_momento_en_que_desaparecio(): void {
		Functions\when( 'has_action' )->justReturn( false );
		$this->options[ Watch::OPTION_STATE ] = array( 'missing_since' => '2026-08-18 08:32:00' );

		Watch::inspect();

		$state = $this->options[ Watch::OPTION_STATE ];
		self::assertSame( '2026-08-18 08:32:00', $state['missing_since'] );
		self::assertNotSame( '2026-08-18 08:32:00', $state['last_seen_gmt'] );
	}

	/**
	 * @testdox Recovering clears the outage but never the history.
	 */
	public function test_el_historial_sobrevive_a_la_cura(): void {
		// Éste es el defecto entero del cron viejo: reparaba y no dejaba señal, así
		// que el archivo podía estar desapareciendo todas las semanas sin que
		// nadie se enterara nunca.
		Functions\when( 'has_action' )->justReturn( PHP_INT_MAX );
		$this->options[ Watch::OPTION_STATE ] = array(
			'missing_since' => '2026-08-18 08:32:00',
			'last_seen_gmt' => '2026-08-18 08:42:00',
			'outcome'       => 'repuesto',
			'restores'      => array( '2026-08-18 08:32:00' ),
		);

		Watch::inspect();

		$state = $this->options[ Watch::OPTION_STATE ];
		self::assertArrayNotHasKey( 'missing_since', $state );
		self::assertArrayNotHasKey( 'outcome', $state );
		self::assertSame( array( '2026-08-18 08:32:00' ), $state['restores'] );
	}

	/**
	 * @testdox A file that is present but inert must never be overwritten.
	 */
	public function test_el_plan_no_pisa_un_archivo_presente(): void {
		self::assertSame( 'presente-pero-inerte', Watch::restore_plan( true, true ) );
		self::assertSame( 'presente-pero-inerte', Watch::restore_plan( true, false ) );
	}

	/**
	 * @testdox Restoring happens only when the file is gone and a copy exists.
	 */
	public function test_el_plan_repone_solo_si_falta_y_hay_copia(): void {
		self::assertSame( 'copiar', Watch::restore_plan( false, true ) );
		self::assertSame( 'sin-copia', Watch::restore_plan( false, false ) );
	}

	/**
	 * @testdox The restore history is capped and keeps the most recent entries.
	 */
	public function test_el_historial_se_acota_a_las_ultimas_diez(): void {
		$history = array();
		for ( $i = 1; $i <= 13; $i++ ) {
			$history = Watch::append_restore( $history, sprintf( '2026-08-%02d 00:00:00', $i ) );
		}

		self::assertCount( 10, $history );
		self::assertSame( '2026-08-04 00:00:00', $history[0] );
		self::assertSame( '2026-08-13 00:00:00', $history[9] );
	}

	/**
	 * @testdox While the transport is gone the notice says what happened and what to do.
	 */
	public function test_el_aviso_de_ausencia_dice_que_paso_y_que_hacer(): void {
		$this->stub_notice_i18n();
		$this->options[ Watch::OPTION_STATE ] = array(
			'missing_since' => '2026-08-18 08:32:00',
			'last_seen_gmt' => '2026-08-18 08:42:00',
			'outcome'       => 'repuesto',
		);

		$salida = $this->render_notice();

		self::assertStringContainsString( 'Falta el transporte de correo de Akibara', $salida );
		self::assertStringContainsString( 'Malware scanner', $salida );
	}

	/**
	 * @testdox A silent self-repair must still be reported afterwards.
	 */
	public function test_el_aviso_recuerda_una_reposicion_ya_curada(): void {
		$this->stub_notice_i18n();
		$this->options[ Watch::OPTION_STATE ] = array(
			'restores' => array( '2026-08-18 08:32:00', '2026-08-20 04:10:00' ),
		);

		$salida = $this->render_notice();

		self::assertStringContainsString( 'desapareció y se repuso solo', $salida );
		self::assertStringContainsString( 'Ocurrió 2 veces', $salida );
	}

	/**
	 * @testdox With nothing to report the notice prints nothing.
	 */
	public function test_sin_incidentes_el_aviso_calla(): void {
		$this->stub_notice_i18n();

		self::assertSame( '', $this->render_notice() );
	}

	/**
	 * @testdox Without the manage_woocommerce capability the notice prints nothing.
	 */
	public function test_el_aviso_calla_sin_capacidad(): void {
		$this->stub_notice_i18n();
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->options[ Watch::OPTION_STATE ] = array( 'missing_since' => '2026-08-18 08:32:00' );

		self::assertSame( '', $this->render_notice() );
	}

	/**
	 * @testdox Acknowledging the notice demands the capability and a valid nonce.
	 */
	public function test_el_acuse_exige_capacidad_y_nonce(): void {
		$this->options[ Watch::OPTION_STATE ] = array(
			'restores' => array( '2026-08-18 08:32:00', '2026-08-20 04:10:00' ),
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_unslash' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'remove_query_arg' )->justReturn( 'https://akibara.cl/wp-admin/' );
		Functions\when( 'wp_safe_redirect' )->alias(
			static function (): void {
				// El código hace `exit` justo después: lanzar es la única forma de
				// probar el camino bueno sin matar la suite.
				throw new \RuntimeException( 'redirigido' );
			}
		);

		$_GET[ 'akibara_mail_watch_ack' ] = '1';
		$_GET['_wpnonce']                 = 'falsificado';

		// 1. Sin capacidad no se acusa nada, aunque el nonce fuera bueno.
		Functions\when( 'current_user_can' )->justReturn( false );
		Watch::handle_dismiss();
		self::assertArrayNotHasKey( 'history_ack', $this->options[ Watch::OPTION_STATE ] );

		// 2. Con capacidad pero con un nonce inválido, tampoco.
		Functions\when( 'current_user_can' )->justReturn( true );
		Watch::handle_dismiss();
		self::assertArrayNotHasKey( 'history_ack', $this->options[ Watch::OPTION_STATE ], 'un enlace falsificado no puede apagar la alarma de otro' );

		// 3. Con las dos cosas, se acusan las reposiciones que hay y se redirige.
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		try {
			Watch::handle_dismiss();
			self::fail( 'debió redirigir' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirigido', $e->getMessage() );
		}
		// S-010cece9 (2026-09-19): el acuse es la FECHA de la última reposición
		// vista, no un conteo — un conteo se congela en HISTORY_LIMIT para
		// siempre y el aviso deja de volver aunque siga desapareciendo el archivo.
		self::assertSame( '2026-08-20 04:10:00', $this->options[ Watch::OPTION_STATE ]['history_ack'] );

		unset( $_GET['akibara_mail_watch_ack'], $_GET['_wpnonce'] );
	}

	/**
	 * @testdox The moment of detection must be recorded, once per episode.
	 */
	public function test_anota_el_contexto_de_la_deteccion_una_vez_por_episodio(): void {
		// Lo que sólo existe en ese instante: desde dónde se vio, con qué
		// desenlace y —en producción— la hora del directorio y la de los dos
		// vecinos de Hostinger. Sin esto, cada episodio obligaba a reconstruirlo
		// por SSH mirando mtimes que el episodio siguiente pisa.
		Functions\when( 'has_action' )->justReturn( false );

		Watch::inspect();

		$contextos = $this->options[ Watch::OPTION_STATE ]['contextos'];
		self::assertCount( 1, $contextos );
		self::assertSame( 'admin', $contextos[0]['origen'] );
		self::assertSame( 'sin-copia', $contextos[0]['desenlace'] );
		self::assertNotEmpty( $contextos[0]['gmt'] );

		// La misma ausencia, comprobada otra vez, no añade una foto nueva: si no,
		// serían doce filas por hora mientras dure el corte.
		$inspected = new \ReflectionProperty( Watch::class, 'inspected' );
		$inspected->setAccessible( true );
		$inspected->setValue( null, false );

		Watch::inspect();

		self::assertCount( 1, $this->options[ Watch::OPTION_STATE ]['contextos'] );
	}

	/**
	 * @testdox Two simultaneous inspections must not lose a detection context either.
	 */
	public function test_los_contextos_tampoco_se_pisan(): void {
		$a = array( 'gmt' => '2026-09-06 23:15:03', 'origen' => 'cron' );
		$b = array( 'gmt' => '2026-09-13 23:17:41', 'origen' => 'admin' );

		self::assertSame( array( $a, $b ), Watch::merge_contexts( array( $a ), array( $b ) ) );
		self::assertSame( array( $a ), Watch::merge_contexts( array( $a ), array( $a ) ) );
		self::assertSame( array(), Watch::merge_contexts( array( array( 'sin' => 'hora' ) ), array() ) );
	}

	/**
	 * @testdox A customer's admin request must never copy files into mu-plugins.
	 */
	public function test_la_peticion_de_un_cliente_no_repone_nada(): void {
		// `admin_init` no es «el dueño entró al escritorio»: corre para cualquier
		// usuario autenticado en /wp-admin/, incluida una clienta en profile.php.
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'has_action' )->justReturn( false );

		Watch::inspect();

		self::assertArrayNotHasKey(
			Watch::OPTION_STATE,
			$this->options,
			'sin capacidad no se inspecciona: la reposición la hace el cron, que corre cada 5 minutos'
		);
	}

	/**
	 * Reponer a ciegas es lo que convierte un aviso en una mentira: el texto
	 * decía «lo repuse, el correo debería volver a salir» con una copia truncada
	 * o del archivo equivocado encima del transporte (S-8517b3c9).
	 *
	 * @testdox A rescue copy that is not the transport must never be copied over.
	 */
	public function test_no_repone_una_copia_que_no_sea_el_transporte(): void {
		$buena = "<?php\ndeclare( strict_types = 1 );\nnamespace Akibara\\HostingerMail;\nfinal class Transport {\n\tpublic static function register(): void {\n\t\tadd_action( 'phpmailer_init', array( self::class, 'configure_phpmailer' ) );\n\t}\n}\n";

		self::assertTrue( Watch::backup_is_valid( $buena ) );
		self::assertFalse( Watch::backup_is_valid( '' ), 'una copia vacía no es el transporte' );
		self::assertFalse(
			Watch::backup_is_valid( "<?php\nnamespace Akibara\\HostingerMail;\nfinal class Tran" ),
			'una copia truncada a la mitad tampoco'
		);
		self::assertFalse(
			Watch::backup_is_valid( "<?php\n// cualquier otro archivo del repositorio\n" ),
			'ni el archivo equivocado'
		);
	}

	/**
	 * @testdox An invalid rescue copy must not promise that mail will flow again.
	 */
	public function test_el_aviso_de_una_copia_invalida_no_promete_que_el_correo_vuelve(): void {
		$this->stub_notice_i18n();
		$this->options[ Watch::OPTION_STATE ] = array(
			'missing_since' => '2026-09-15 08:32:00',
			'last_seen_gmt' => '2026-09-15 08:32:00',
			'outcome'       => 'copia-invalida',
		);

		$salida = $this->render_notice();

		self::assertStringContainsString( 'NO es el transporte', $salida );
		self::assertStringNotContainsString( 'debería volver a salir', $salida );
	}

	/**
	 * @testdox Two inspections in the same minute must not lose a restore.
	 */
	public function test_dos_inspecciones_simultaneas_no_pierden_una_reposicion(): void {
		// El escritorio y el cron caen en el mismo minuto: cada uno leyó la option
		// antes de que el otro escribiera, y la segunda escritura borraba la
		// reposición de la primera (S-80f052f9).
		self::assertSame(
			array( '2026-08-23 20:15:00', '2026-08-30 20:14:00' ),
			Watch::merge_restores( array( '2026-08-23 20:15:00' ), array( '2026-08-30 20:14:00' ) )
		);
		self::assertSame(
			array( '2026-08-23 20:15:00' ),
			Watch::merge_restores( array( '2026-08-23 20:15:00' ), array( '2026-08-23 20:15:00' ) ),
			'la misma reposición no puede contarse dos veces'
		);

		$muchas = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$muchas[] = sprintf( '2026-08-%02d 00:00:00', $i );
		}
		$fundido = Watch::merge_restores( $muchas, array( '2026-09-01 00:00:00' ) );
		self::assertCount( 10, $fundido, 'el tope del historial sigue valiendo tras fundir' );
		self::assertSame( '2026-09-01 00:00:00', end( $fundido ), 'y se conservan las últimas' );
	}

	/**
	 * @testdox The history notice can be acknowledged for good, and comes back if it happens again.
	 */
	public function test_el_aviso_del_historial_se_puede_dar_por_visto(): void {
		$this->stub_notice_i18n();

		$this->options[ Watch::OPTION_STATE ] = array(
			'restores' => array( '2026-08-18 08:32:00', '2026-08-20 04:10:00' ),
		);

		$salida = $this->render_notice();
		self::assertStringContainsString( 'akibara_mail_watch_ack', $salida, 'sin enlace no hay forma de cerrarlo' );
		self::assertStringNotContainsString( 'is-dismissible', $salida, 'is-dismissible de WordPress no guarda nada' );

		// Dado por visto: se acusa la fecha de la última reposición (S-010cece9).
		$this->options[ Watch::OPTION_STATE ]['history_ack'] = '2026-08-20 04:10:00';
		self::assertSame( '', $this->render_notice(), 'el acuse tiene que persistir entre páginas' );

		// Y si vuelve a pasar, vuelve a avisar.
		$this->options[ Watch::OPTION_STATE ]['restores'][] = '2026-09-06 23:15:00';
		self::assertStringContainsString( 'desapareció y se repuso solo', $this->render_notice() );
	}

	/**
	 * @testdox REAPERTURA S-010cece9: the history notice still comes back once the log is capped at HISTORY_LIMIT.
	 */
	public function test_el_aviso_del_historial_vuelve_aunque_el_historial_este_topado(): void {
		$this->stub_notice_i18n();

		// Diez reposiciones ya en el tope (HISTORY_LIMIT), todas acusadas.
		$diez = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$diez[] = sprintf( '2026-06-%02d 00:00:00', $i );
		}
		$this->options[ Watch::OPTION_STATE ] = array(
			'restores'    => $diez,
			'history_ack' => end( $diez ),
		);
		self::assertSame( '', $this->render_notice(), 'ya acusada, no debe repintarse' );

		// La reposición 11ª entra por merge_restores(), que vuelve a recortar a
		// las últimas 10: con un acuse por CONTEO, count($history) seguiría
		// valiendo 10 y la guarda `$total <= $visto` se quedaría en verdadero
		// para siempre. Con un acuse por FECHA, la 11ª es más reciente que la
		// última vista y el aviso vuelve.
		$this->options[ Watch::OPTION_STATE ]['restores'] = Watch::merge_restores(
			$diez,
			array( '2026-09-06 23:15:00' )
		);
		self::assertCount( 10, $this->options[ Watch::OPTION_STATE ]['restores'], 'la lista sigue topada' );
		self::assertStringContainsString(
			'desapareció y se repuso solo',
			$this->render_notice(),
			'una reposición 11ª tiene que reabrir el aviso aunque el conteo no suba de 10'
		);
	}

	/**
	 * @testdox REAPERTURA S-010cece9: a legacy integer acknowledgement (pre-1.1.1) is treated as unacknowledged, not compared lexicographically.
	 */
	public function test_el_acuse_heredado_como_entero_no_apaga_el_aviso_para_siempre(): void {
		$this->stub_notice_i18n();

		// Migración: en producción history_ack vale hoy un entero (conteo). Si se
		// comparara como cadena contra una fecha, '2026-...' <= '3' sale VERDADERO
		// (PHP compara lexicográficamente porque el lado izquierdo no es
		// numérico) y el aviso quedaría apagado para siempre.
		$this->options[ Watch::OPTION_STATE ] = array(
			'restores'    => array( '2026-08-18 08:32:00', '2026-08-20 04:10:00', '2026-09-06 23:15:00' ),
			'history_ack' => 3,
		);

		self::assertStringContainsString(
			'desapareció y se repuso solo',
			$this->render_notice(),
			'un acuse heredado por conteo se trata como sin acusar'
		);
	}

	/**
	 * @testdox When the background queue stops, the sentinel says so.
	 */
	public function test_avisa_cuando_la_cola_de_fondo_dejo_de_correr(): void {
		// Este centinela se cuelga del cron de hPanel: si ese cron muere, deja de
		// vigilar justo cuando más falta hace, y nadie se enteraba (S-81ab3aa2).
		$this->stub_notice_i18n( 'production' );

		$this->options[ Watch::OPTION_STATE ] = array( 'last_cron_gmt' => gmdate( 'Y-m-d H:i:s', time() - 300 ) );
		self::assertSame( '', $this->render_notice(), 'cinco minutos es el ritmo normal, no una alarma' );

		$this->options[ Watch::OPTION_STATE ] = array( 'last_cron_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
		self::assertStringContainsString( 'no están corriendo', $this->render_notice() );
	}

	/**
	 * @testdox Outside production a stopped cron is the normal state, not an alarm.
	 */
	public function test_fuera_de_produccion_el_cron_parado_no_es_alarma(): void {
		$this->stub_notice_i18n();
		$this->options[ Watch::OPTION_STATE ] = array( 'last_cron_gmt' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) );

		self::assertSame( '', $this->render_notice(), 'en el espejo el cron no corre a propósito' );
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
	 * Mínimo de funciones de WordPress que los avisos necesitan.
	 *
	 * `wp_date` va declarada siempre, no sólo cuando hace falta: el centinela la
	 * llama detrás de un `function_exists()`, y bajo Brain Monkey eso depende de
	 * si otro test de la suite la mockeó antes — verde aislado, rojo en la suite
	 * completa.
	 */
	private function stub_notice_i18n( string $entorno = 'local' ): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		// El aviso del cron pregunta el entorno, y `function_exists()` NO es
		// salvaguarda bajo Brain Monkey: da true y la llamada revienta si nadie la
		// declaró. Por defecto «local», donde el cron no corre a propósito; el
		// test que mide ese aviso pide «production» al llamar.
		Functions\when( 'wp_get_environment_type' )->justReturn( $entorno );
		// Las tres del enlace para dar por visto el aviso del historial: van
		// SIEMPRE, no sólo donde hacen falta. Bajo Brain Monkey, que una función
		// esté declarada depende de si otro test la mockeó antes —verde aislado,
		// rojo en la suite completa—, y este archivo ya se quemó con `wp_date`.
		Functions\when( 'wp_create_nonce' )->justReturn( 'n0nc3' );
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args ): string => 'https://akibara.cl/wp-admin/?' . http_build_query( $args )
		);
		Functions\when( 'esc_url' )->alias( static fn( $url ) => $url );
		Functions\when( 'number_format_i18n' )->alias( static fn( $number ) => (string) $number );
		Functions\when( '__' )->alias( static fn( $text ) => $text );
		Functions\when( 'esc_html__' )->alias( static fn( $text ) => $text );
		Functions\when( 'esc_html' )->alias( static fn( $text ) => $text );
		Functions\when( '_n' )->alias(
			static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural
		);
		Functions\when( 'wp_date' )->justReturn( '18 de agosto, 04:32' );
	}

	/**
	 * Captura la salida del aviso del admin.
	 */
	private function render_notice(): string {
		ob_start();
		Watch::render_notice();

		return (string) ob_get_clean();
	}
}
