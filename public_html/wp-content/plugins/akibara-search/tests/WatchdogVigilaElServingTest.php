<?php
/**
 * El watchdog mira el serving en TODAS las vueltas, no sólo cuando no hay run.
 *
 * Tres defectos del inventario de QA vivían en las mismas veinte líneas de
 * `RebuildJob::handle_watchdog()` / `maybe_recover_serving()`, y los tres salían de
 * la misma raíz: la cuenta de la alarma colgaba de POR DÓNDE pasaba la vuelta, no
 * de cómo estaba el buscador.
 *
 *  - S-190efeef: con un run vivo, el watchdog reanudaba y se iba. Un run colgado
 *    después del swap (SWAP_INTENT, SWAPPED, PUBLISHING, CLEANUP) tiene la barrera
 *    de publicación cerrada —cliente sin buscador ni filtros— y nunca alcanzaba
 *    `note_serving_down()`, la única puerta al correo de alarma.
 *  - S-8ab53cfb: con el estado trabado (BLOCKED/ABORTED) se pedía cada 15 minutos
 *    un rebuild que nadie puede consumir —`schedule_request()` devuelve '' mientras
 *    exista la fila de estado— y se escribía cada vez «serving caído sin run
 *    activo; rebuild solicitado» en un cuaderno de 50 entradas.
 *  - S-e52a233e: con el autocompletado apagado no se medía nada, así que un
 *    episodio ya avisado se quedaba con `notified_at` puesto para siempre y la
 *    siguiente caída real no mandaba correo.
 *
 * Son pruebas de COMPORTAMIENTO sobre `handle_watchdog()` entero: los
 * colaboradores (Install, WorkRepository, la publicación editorial) se sustituyen
 * con Patchwork, que el bootstrap carga antes que `src/`. Lo que se afirma es lo
 * que ve el dueño: qué correo sale, qué rebuild se pide y qué queda en el cuaderno.
 *
 * Dos bloques se añadieron tras la revisión independiente del lote (2026-09-19):
 *
 *  - «La foto de la petición»: el watchdog medía con `serving_ready()`, que es UNA
 *    foto por petición, y en la petición de cron esa foto ya la había tomado
 *    `reconcile_on_init()` (`init:23`) con el buscador caído. Las pruebas de arriba
 *    no podían verlo porque sustituyen `serving_ready()` entero; estas dejan el
 *    memo REAL en su sitio y sustituyen sólo la lectura de la base.
 *  - «En todas las fases»: la red que antes era un candado de fuente sobre
 *    `handle_watchdog()` en `BloqueoNoApagaLaAlarmaTest`. La decisión se mudó a
 *    `maybe_recover_serving()` y el candado siguió verde sin vigilar nada; aquí
 *    las fases salen por reflexión de las constantes `PHASE_*`, así que una fase
 *    nueva entra en la matriz sin que nadie se acuerde de añadirla.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\AuditLog;
use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\Indexer;
use Akibara\Search\Install;
use Akibara\Search\RebuildJob;
use Akibara\Search\Search;
use Akibara\Search\SemanticPublication;
use Akibara\Search\WorkRepository;
use Akibara\Search\WriteFence;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

require_once dirname( __DIR__ ) . '/src/AuditLog.php';
require_once dirname( __DIR__ ) . '/src/Search.php';

final class WatchdogVigilaElServingTest extends TestCase {

	private const OPTION_ALARMA = 'akb_search_serving_alarm';

	/**
	 * Todo lo que la prueba le dice al watchdog y todo lo que el watchdog hace.
	 *
	 * Vive en un objeto aparte, y no en propiedades de la prueba, por Patchwork: al
	 * sustituir un método de INSTANCIA re-enlaza el closure al objeto sustituido,
	 * así que un `$this->algo` dentro del doble hablaría del RebuildJob y no de la
	 * prueba (y un closure `static` directamente no se puede enlazar). Los dobles
	 * capturan este objeto con `use` y no tocan `$this`.
	 *
	 * @var \stdClass
	 */
	private \stdClass $mundo;

	private RebuildJob $sut;

	/** @var mixed Runtime estático previo, para no fugarlo a otras pruebas. */
	private $runtime_previo;

	/**
	 * El doble que sustituye `serving_ready()` ENTERO. Las pruebas de «la foto de
	 * la petición» lo retiran para que corra el memo de verdad.
	 *
	 * @var \Patchwork\CallRerouting\Handle
	 */
	private $doble_serving_ready;

	/** @var array<string, mixed> Estáticos de la publicación editorial, para devolverlos como estaban. */
	private array $publicacion_previa = array();

	/** Lo que compone la foto de serving de una petición, más lo que decide si se puede tomar. */
	private const ESTATICOS_DE_LA_FOTO = array(
		'registered',
		'test_serving_override',
		'read_authority_available',
		'serving_capture_done',
		'serving_capture_token',
		'serving_capture_window',
		'serving_final_checked',
		'serving_final_matches_by',
		'serving_final_matches',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$mundo = new \stdClass();
		// Lo que la prueba decide.
		$mundo->estado           = null;  // Estado del rebuild que lee el watchdog.
		$mundo->sirviendo        = false; // ¿El gate público está abierto?
		$mundo->carril_encendido = true;  // Interruptor del autocompletado.
		$mundo->schema_sellado   = true;
		$mundo->reanudar_publica  = false; // ¿El resume_run() de esta vuelta consigue publicar?
		$mundo->reconcile_repara  = false; // ¿La reparación barata de esta vuelta levanta el serving?
		$mundo->lecturas_frescas  = 0;     // Veces que se releyó la base para saber si sirve.
		$mundo->options          = array( 'admin_email' => 'dueno@akibara.test' );
		// Lo que el watchdog hace.
		$mundo->correos             = array(); // Asuntos enviados.
		$mundo->cuaderno            = array(); // Entradas del AuditLog: [acción, detalle].
		$mundo->reanudados          = array(); // Runs mandados a reanudar.
		$mundo->rebuilds_pedidos    = array(); // Motivos de los rebuilds pedidos.
		$mundo->rebuilds_consumidos = 0;
		$mundo->reconciles          = 0;
		$this->mundo                = $mundo;

		Functions\when( 'get_option' )->alias( static fn( string $nombre, $default = false ) => $mundo->options[ $nombre ] ?? $default );
		Functions\when( 'update_option' )->alias(
			static function ( string $nombre, $valor ) use ( $mundo ): bool {
				$mundo->options[ $nombre ] = $valor;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $nombre ) use ( $mundo ): bool {
				unset( $mundo->options[ $nombre ] );
				return true;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			static function ( string $para, string $asunto ) use ( $mundo ): bool {
				$mundo->correos[] = $asunto;
				return true;
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		// Lo que el watchdog hace ANTES de mirar el serving: aquí no se prueba.
		// (Los de instancia NO pueden ser `static`: Patchwork los enlaza al objeto.)
		\Patchwork\redefine( Install::class . '::consume_generation_publications', static fn(): bool => true );
		\Patchwork\redefine( WorkRepository::class . '::recover_expired', function () { return 0; } );
		\Patchwork\redefine( WorkRepository::class . '::outstanding_count', function () { return 0; } );
		\Patchwork\redefine( RebuildJob::class . '::handle_recovery', function (): void {} );

		\Patchwork\redefine( RebuildJob::class . '::read_state', static fn(): ?array => $mundo->estado );
		\Patchwork\redefine(
			RebuildJob::class . '::resume_run',
			function ( string $run_id ) use ( $mundo ): void {
				$mundo->reanudados[] = $run_id;
				if ( $mundo->reanudar_publica ) {
					$mundo->sirviendo = true; // publish_run() es síncrono: el recibo sale en esta petición.
				}
			}
		);

		\Patchwork\redefine( Search::class . '::suggest_configured', static fn(): bool => $mundo->carril_encendido );
		\Patchwork\redefine( Install::class . '::runtime_schema_ready', static fn(): bool => $mundo->schema_sellado );
		\Patchwork\redefine(
			EditorialDiscountPublication::class . '::reconcile_now',
			static function () use ( $mundo ): bool {
				++$mundo->reconciles;
				if ( $mundo->reconcile_repara ) {
					$mundo->sirviendo = true; // El re-probado del recibo abre el gate sin rebuild.
				}
				return $mundo->reconcile_repara;
			}
		);
		$this->doble_serving_ready = \Patchwork\redefine( EditorialDiscountPublication::class . '::serving_ready', static fn(): bool => $mundo->sirviendo );

		foreach ( self::ESTATICOS_DE_LA_FOTO as $nombre ) {
			$estatico = new ReflectionProperty( EditorialDiscountPublication::class, $nombre );
			$estatico->setAccessible( true );
			$this->publicacion_previa[ $nombre ] = $estatico->getValue();
		}

		\Patchwork\redefine( Install::class . '::pending_rebuild_request', static fn(): ?array => null );
		\Patchwork\redefine(
			Install::class . '::request_rebuild',
			static function ( string $motivo = 'manual' ) use ( $mundo ): bool {
				$mundo->rebuilds_pedidos[] = $motivo;
				return true;
			}
		);
		\Patchwork\redefine(
			Install::class . '::consume_pending_rebuild',
			static function () use ( $mundo ): void {
				++$mundo->rebuilds_consumidos;
			}
		);
		\Patchwork\redefine(
			AuditLog::class . '::record',
			static function ( string $accion, string $detalle = '' ) use ( $mundo ): void {
				$mundo->cuaderno[] = array( $accion, $detalle );
			}
		);

		$runtime = new ReflectionProperty( RebuildJob::class, 'runtime' );
		$runtime->setAccessible( true );
		$this->runtime_previo = $runtime->getValue();

		$this->sut = new RebuildJob(
			( new ReflectionClass( Indexer::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( WorkRepository::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( WriteFence::class ) )->newInstanceWithoutConstructor()
		);
	}

	protected function tearDown(): void {
		$runtime = new ReflectionProperty( RebuildJob::class, 'runtime' );
		$runtime->setAccessible( true );
		$runtime->setValue( null, $this->runtime_previo );

		foreach ( $this->publicacion_previa as $nombre => $valor ) {
			$estatico = new ReflectionProperty( EditorialDiscountPublication::class, $nombre );
			$estatico->setAccessible( true );
			$estatico->setValue( null, $valor );
		}

		Monkey\tearDown();
		parent::tearDown();
	}

	// ── S-190efeef ──────────────────────────────────────────────────────────

	/**
	 * @dataProvider fases_con_run_vivo
	 * @testdox S-190efeef: un run colgado en $fase con el serving caído hace sonar la alarma.
	 */
	public function test_un_run_colgado_con_el_serving_caido_hace_sonar_la_alarma( string $fase ): void {
		$this->mundo->estado    = array( 'phase' => $fase, 'run_id' => 'run-colgado' );
		$this->mundo->sirviendo = false;

		// Primera vuelta: sólo anota la hora. Un corte de publicación dura segundos.
		$this->sut->handle_watchdog();
		self::assertSame( array(), $this->mundo->correos, 'A la primera vuelta no se avisa: puede ser el corte normal de un swap.' );
		self::assertGreaterThan( 0, (int) ( $this->alarma()['down_since'] ?? 0 ), 'Pero el reloj del episodio tiene que arrancar AUNQUE haya un run vivo.' );

		// Un cuarto de hora después sigue igual: el run no avanza.
		$this->envejecer_episodio( 1000 );
		$this->sut->handle_watchdog();

		self::assertSame(
			array( 'Akibara: el buscador lleva rato caído' ),
			$this->mundo->correos,
			"Con el run parado en {$fase} el cliente lleva más de 15 minutos sin buscador: el dueño tiene que enterarse."
		);
		self::assertSame( array( 'run-colgado', 'run-colgado' ), $this->mundo->reanudados, 'El run se sigue reanudando en cada vuelta: la alarma no sustituye al reintento.' );
		self::assertSame( array(), $this->mundo->rebuilds_pedidos, 'Con un run vivo NO se pide otro rebuild por encima.' );
		self::assertSame( 0, $this->mundo->reconciles, 'Ni se reconcilia: ésa es la reparación de cuando no hay run.' );
	}

	/** @return array<string, array{0: string}> */
	public static function fases_con_run_vivo(): array {
		return array(
			'intención de swap' => array( RebuildJob::PHASE_SWAP_INTENT ),
			'recién swapeado'   => array( RebuildJob::PHASE_SWAPPED ),
			'publicando'        => array( RebuildJob::PHASE_PUBLISHING ),
			'limpiando'         => array( RebuildJob::PHASE_CLEANUP ),
			// Con la barrera abierta el run no tumba el serving, pero si YA estaba
			// caído y el run no avanza, el cliente lleva el mismo rato sin buscador.
			'preparando'        => array( RebuildJob::PHASE_PREPARING ),
			'construyendo'      => array( RebuildJob::PHASE_BUILDING ),
			'alcanzando'        => array( RebuildJob::PHASE_CATCHING_UP ),
			'hecho sin cerrar'  => array( RebuildJob::PHASE_DONE ),
		);
	}

	/**
	 * @testdox S-190efeef: un rebuild SANO —serving arriba mientras construye— no abre episodio ni avisa.
	 */
	public function test_un_rebuild_sano_no_abre_episodio(): void {
		$this->mundo->estado    = array( 'phase' => RebuildJob::PHASE_BUILDING, 'run_id' => 'run-sano' );
		$this->mundo->sirviendo = true;

		$this->sut->handle_watchdog();

		self::assertSame( array(), $this->alarma(), 'El rebuild construye en tablas sombra: con el serving arriba no hay episodio.' );
		self::assertSame( array(), $this->mundo->correos );
		self::assertSame( array( 'run-sano' ), $this->mundo->reanudados );
	}

	/**
	 * @testdox S-190efeef: cuando el run colgado por fin publica, sale el «volvió» y el episodio se limpia.
	 */
	public function test_el_run_que_por_fin_publica_avisa_de_la_vuelta(): void {
		$this->mundo->estado  = array( 'phase' => RebuildJob::PHASE_PUBLISHING, 'run_id' => 'run-tardio' );
		$this->mundo->options[ self::OPTION_ALARMA ] = array(
			'down_since'  => time() - 3000,
			'notified_at' => time() - 2000,
		);
		$this->mundo->sirviendo = true; // La reanudación de esta vuelta lo consiguió.

		$this->sut->handle_watchdog();

		self::assertSame( array( 'Akibara: el buscador volvió' ), $this->mundo->correos, 'Quien recibió el correo de caída tiene que recibir el de vuelta.' );
		self::assertArrayNotHasKey( self::OPTION_ALARMA, $this->mundo->options );
	}

	// ── S-8ab53cfb ──────────────────────────────────────────────────────────

	/**
	 * @dataProvider estados_trabados
	 * @testdox S-8ab53cfb: con el estado en $fase el watchdog NO pide un rebuild que nadie puede consumir ni llena el cuaderno.
	 */
	public function test_un_estado_trabado_no_pide_rebuild_ni_llena_el_cuaderno( string $fase ): void {
		$this->mundo->estado    = array(
			'phase'  => $fase,
			'error'  => 'orphan_topology_without_state',
			'run_id' => 'cda09edc',
		);
		$this->mundo->sirviendo = false;

		// Doce horas de pestillo puesto: 48 vueltas de 15 minutos.
		for ( $vuelta = 0; $vuelta < 48; ++$vuelta ) {
			$this->sut->handle_watchdog();
			$this->envejecer_episodio( RebuildJob::WATCHDOG_INTERVAL );
		}

		self::assertSame( array(), $this->mundo->rebuilds_pedidos, 'schedule_request() devuelve cadena vacía mientras exista la fila de estado: esa señal no la consume nadie.' );
		self::assertSame( 0, $this->mundo->rebuilds_consumidos, 'Y tampoco se intenta consumirla.' );
		self::assertSame(
			array(),
			array_values( array_filter( $this->mundo->cuaderno, static fn( array $entrada ): bool => 'serving_recovery' === $entrada[0] ) ),
			'«Serving caído sin run activo; rebuild solicitado» era falso dos veces y se escribía cada 15 minutos para siempre.'
		);
		self::assertLessThanOrEqual( 1, count( $this->mundo->cuaderno ), 'En doce horas de bloqueo el cuaderno recibe, como mucho, la entrada del aviso: el resto de sus 50 líneas sigue contando qué pasó ANTES.' );
		self::assertSame( 48, $this->mundo->reconciles, 'La reparación barata se sigue intentando: no depende de ningún run.' );
		self::assertSame( array( 'Akibara: el buscador lleva rato caído' ), $this->mundo->correos, 'Y la alarma suena UNA vez: es lo único que saca a alguien de la cama.' );
		self::assertSame( array(), $this->mundo->reanudados, 'Un estado trabado no lo reanuda nadie.' );
	}

	/** @return array<string, array{0: string}> */
	public static function estados_trabados(): array {
		return array(
			'bloqueado' => array( RebuildJob::PHASE_BLOCKED ),
			'abortado'  => array( RebuildJob::PHASE_ABORTED ),
		);
	}

	/**
	 * @testdox S-8ab53cfb: SIN estado el rebuild de recuperación se sigue pidiendo y consumiendo en la misma vuelta.
	 */
	public function test_sin_estado_el_rebuild_se_pide_y_se_consume(): void {
		$this->mundo->estado    = null;
		$this->mundo->sirviendo = false;

		$this->sut->handle_watchdog();

		self::assertSame( array( 'serving_recovery' ), $this->mundo->rebuilds_pedidos, 'Es el único caso en que pedirlo sirve: no hay fila de estado que lo frene.' );
		self::assertSame( 1, $this->mundo->rebuilds_consumidos );
		self::assertSame( array( array( 'serving_recovery', 'Watchdog: serving caído sin run activo; rebuild solicitado' ) ), $this->mundo->cuaderno, 'Aquí la entrada dice la verdad.' );
		self::assertGreaterThan( 0, (int) ( $this->alarma()['down_since'] ?? 0 ) );
	}

	// ── S-e52a233e ──────────────────────────────────────────────────────────

	/**
	 * @testdox S-e52a233e: tras apagar y volver a encender el autocompletado, la siguiente caída real SÍ avisa.
	 *
	 * Es el daño del hallazgo, de punta a punta y sin mirar el estado intermedio:
	 * lo que importa es el correo que el dueño recibe —o no— días después.
	 */
	public function test_la_caida_siguiente_a_un_apagado_vuelve_a_avisar(): void {
		// Episodio en curso, con el correo de caída ya enviado.
		$this->mundo->options[ self::OPTION_ALARMA ] = array(
			'down_since'  => time() - 5000,
			'notified_at' => time() - 4000,
		);

		// El dueño apaga el interruptor. Pasa una vuelta del watchdog.
		$this->mundo->carril_encendido = false;
		$this->sut->handle_watchdog();

		// Días después lo vuelve a encender, y el buscador se cae de verdad.
		$this->mundo->carril_encendido = true;
		$this->mundo->sirviendo        = false;
		$this->sut->handle_watchdog();
		$this->envejecer_episodio( 1000 );
		$this->sut->handle_watchdog();

		self::assertSame(
			array( 'Akibara: el buscador lleva rato caído' ),
			$this->mundo->correos,
			'Episodio NUEVO, correo nuevo: con el sello «ya avisé» del episodio viejo puesto, esta caída no avisaba nunca.'
		);
	}

	/**
	 * @testdox S-e52a233e: apagar el autocompletado a mitad de un episodio avisado lo cierra sin «volvió», y deja rastro.
	 */
	public function test_apagar_el_carril_cierra_el_episodio_sin_aviso_de_vuelta(): void {
		$this->mundo->options[ self::OPTION_ALARMA ] = array(
			'down_since'  => time() - 5000,
			'notified_at' => time() - 4000,
		);
		$this->mundo->carril_encendido               = false;

		$this->sut->handle_watchdog();

		self::assertArrayNotHasKey( self::OPTION_ALARMA, $this->mundo->options, 'Con el carril apagado nadie más iba a limpiar el «ya avisé»: se cierra aquí.' );
		self::assertSame( array(), $this->mundo->correos, 'Sin «volvió»: el buscador no volvió, lo apagaron.' );
		self::assertSame( 0, $this->mundo->reconciles, 'Y con el carril apagado no se repara ni se mide nada.' );
		self::assertCount( 1, $this->mundo->cuaderno, 'Queda rastro de por qué ese correo de caída no tiene pareja.' );
		self::assertSame( 'serving_alarma', $this->mundo->cuaderno[0][0] );
	}

	/**
	 * @testdox S-e52a233e: con el carril apagado y sin episodio abierto, la vuelta no escribe nada.
	 */
	public function test_carril_apagado_sin_episodio_no_escribe_nada(): void {
		$this->mundo->carril_encendido = false;
		$antes                  = $this->mundo->options;

		$this->sut->handle_watchdog();

		self::assertSame( $antes, $this->mundo->options );
		self::assertSame( array(), $this->mundo->cuaderno, 'Es el estado normal de quien no usa el autocompletado: 96 vueltas al día sin ruido.' );
	}

	/**
	 * @testdox S-e52a233e: el schema sin sellar NO cierra el episodio — el buscador sigue caído y el «volvió» tiene que poder salir.
	 */
	public function test_schema_sin_sellar_conserva_el_episodio(): void {
		$episodio = array(
			'down_since'  => time() - 5000,
			'notified_at' => time() - 4000,
		);
		$this->mundo->options[ self::OPTION_ALARMA ] = $episodio;
		$this->mundo->schema_sellado                 = false;

		$this->sut->handle_watchdog();

		self::assertSame( $episodio, $this->mundo->options[ self::OPTION_ALARMA ] ?? null, 'Una migración a medias no es una decisión del dueño: para el cliente sigue caído.' );

		// La migración sella y el serving vuelve: el dueño recibe la pareja del aviso.
		$this->mundo->schema_sellado = true;
		$this->mundo->sirviendo      = true;
		$this->sut->handle_watchdog();

		self::assertSame( array( 'Akibara: el buscador volvió' ), $this->mundo->correos );
	}

	// ── S-190efeef · la foto de la petición ─────────────────────────────────

	/**
	 * @testdox S-190efeef: si el run publica en ESTA vuelta, no sale «lleva rato caído» aunque la petición ya tuviera la foto del buscador caído.
	 *
	 * El escenario, de madrugada: run colgado en PUBLISHING, serving caído hace 16
	 * minutos, episodio abierto y todavía sin avisar. En la petición de cron,
	 * `reconcile_on_init()` (`init:23`) toma la foto de serving con el buscador
	 * caído; después corre el watchdog, `resume_run()` publica, y el buscador VUELVE
	 * en esa misma petición. Con la foto vieja el dueño recibía la pareja al revés:
	 * «lleva rato caído» justo cuando volvía, y «volvió» un cuarto de hora después.
	 */
	public function test_la_publicacion_de_esta_vuelta_no_manda_el_correo_de_caida(): void {
		$this->con_la_foto_real_de_la_peticion();
		$this->mundo->estado = array( 'phase' => RebuildJob::PHASE_PUBLISHING, 'run_id' => 'run-tardio' );
		$this->mundo->options[ self::OPTION_ALARMA ] = array(
			'down_since'  => time() - 960,
			'notified_at' => 0,
		);

		$this->init_23_toma_la_foto_con_el_buscador_caido();
		$this->mundo->reanudar_publica = true;

		$this->sut->handle_watchdog();

		self::assertSame( array( 'run-tardio' ), $this->mundo->reanudados );
		self::assertSame( array(), $this->mundo->correos, 'El buscador volvió en esta misma petición: avisar ahora de la caída es avisar de algo que ya no pasa.' );
		self::assertArrayNotHasKey( self::OPTION_ALARMA, $this->mundo->options, 'Y el episodio se cierra en la vuelta que lo arregló, no en la siguiente.' );
		self::assertSame( 2, $this->mundo->lecturas_frescas, 'Una lectura en init:23 y OTRA después de reanudar: el watchdog mide bytes frescos, no la foto de la petición.' );
	}

	/**
	 * @testdox S-190efeef: con el episodio ya avisado, el «volvió» sale en la vuelta en que el run publica, no una vuelta tarde.
	 */
	public function test_el_volvio_sale_en_la_vuelta_que_publica(): void {
		$this->con_la_foto_real_de_la_peticion();
		$this->mundo->estado = array( 'phase' => RebuildJob::PHASE_SWAPPED, 'run_id' => 'run-tardio' );
		$this->mundo->options[ self::OPTION_ALARMA ] = array(
			'down_since'  => time() - 3000,
			'notified_at' => time() - 2000,
		);

		$this->init_23_toma_la_foto_con_el_buscador_caido();
		$this->mundo->reanudar_publica = true;

		$this->sut->handle_watchdog();

		self::assertSame( array( 'Akibara: el buscador volvió' ), $this->mundo->correos, 'Quince minutos más de «sigue caído» en la bandeja del dueño, con la tienda ya bien, es el defecto.' );
		self::assertArrayNotHasKey( self::OPTION_ALARMA, $this->mundo->options );
	}

	/**
	 * @testdox S-190efeef: si la reparación barata levanta el serving, el watchdog lo VE: ni pide rebuild ni abre episodio.
	 *
	 * El comentario del código prometía esto («va ANTES de serving_ready()»), pero
	 * el orden no bastaba: la foto ya estaba tomada desde `init:23`.
	 */
	public function test_la_reparacion_barata_de_esta_vuelta_se_ve(): void {
		$this->con_la_foto_real_de_la_peticion();
		$this->mundo->estado = null;

		$this->init_23_toma_la_foto_con_el_buscador_caido();
		$this->mundo->reconcile_repara = true;

		$this->sut->handle_watchdog();

		self::assertSame( 1, $this->mundo->reconciles );
		self::assertSame( array(), $this->mundo->rebuilds_pedidos, 'Un rebuild de recuperación por encima de un serving recién reparado son 30 s de trabajo y una entrada falsa en el cuaderno.' );
		self::assertSame( array(), $this->mundo->cuaderno );
		self::assertSame( array(), $this->alarma(), 'Tampoco se abre un episodio: el buscador sirve.' );
	}

	/**
	 * @testdox S-190efeef: `serving_ready()` sigue siendo UNA foto por petición; releer es sólo de `serving_ready_fresh()`.
	 *
	 * La otra mitad del contrato: las superficies públicas pintan una respuesta
	 * entera con los mismos bytes. Si alguien «arregla» el watchdog quitando el memo,
	 * cada helper del tema vuelve a abrir la base.
	 */
	public function test_la_foto_de_la_peticion_sigue_siendo_una(): void {
		$this->con_la_foto_real_de_la_peticion();
		$this->mundo->sirviendo = true;

		self::assertTrue( EditorialDiscountPublication::serving_ready() );
		$this->mundo->sirviendo = false; // Alguien cierra el gate a mitad de petición.
		self::assertTrue( EditorialDiscountPublication::serving_ready(), 'Para quien pinta la respuesta, la foto no cambia a mitad.' );
		self::assertSame( 1, $this->mundo->lecturas_frescas );

		self::assertFalse( EditorialDiscountPublication::serving_ready_fresh(), 'Quien mide después de actuar sí relee.' );
		self::assertFalse( EditorialDiscountPublication::serving_ready(), 'Y la foto nueva sustituye a la vieja para el resto de la petición: no conviven dos.' );
		self::assertSame( 2, $this->mundo->lecturas_frescas );
	}

	// ── S-190efeef · en todas las fases ─────────────────────────────────────

	/**
	 * @dataProvider todos_los_estados
	 * @testdox S-190efeef: con el estado «$nombre» y el serving caído más de 15 minutos, la alarma suena.
	 *
	 * @param array<string, mixed>|null $estado Estado del rebuild.
	 */
	public function test_la_caida_avisa_en_cualquier_estado( string $nombre, ?array $estado ): void {
		$this->mundo->estado    = $estado;
		$this->mundo->sirviendo = false;

		$this->sut->handle_watchdog();
		$this->envejecer_episodio( 1000 );
		$this->sut->handle_watchdog();

		self::assertSame(
			array( 'Akibara: el buscador lleva rato caído' ),
			$this->mundo->correos,
			"La cuenta del aviso no puede depender de la fase del rebuild ({$nombre}): al cliente le falta el buscador igual."
		);
	}

	/**
	 * @dataProvider todos_los_estados
	 * @testdox S-190efeef: con el estado «$nombre» y un episodio avisado, en cuanto el serving sirve sale el «volvió».
	 *
	 * @param array<string, mixed>|null $estado Estado del rebuild.
	 */
	public function test_la_vuelta_avisa_en_cualquier_estado( string $nombre, ?array $estado ): void {
		$this->mundo->estado    = $estado;
		$this->mundo->sirviendo = true;
		$this->mundo->options[ self::OPTION_ALARMA ] = array(
			'down_since'  => time() - 3000,
			'notified_at' => time() - 2000,
		);

		$this->sut->handle_watchdog();

		self::assertSame(
			array( 'Akibara: el buscador volvió' ),
			$this->mundo->correos,
			"Quien recibió el correo de caída recibe el de vuelta, esté el rebuild como esté ({$nombre})."
		);
		self::assertArrayNotHasKey( self::OPTION_ALARMA, $this->mundo->options );
	}

	/**
	 * Sin estado, más TODAS las fases que declare `RebuildJob`, leídas por reflexión.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>|null}>
	 */
	public static function todos_los_estados(): array {
		$casos = array( 'sin estado' => array( 'sin estado', null ) );
		foreach ( ( new ReflectionClass( RebuildJob::class ) )->getConstants() as $constante => $fase ) {
			if ( 0 === strpos( (string) $constante, 'PHASE_' ) && is_string( $fase ) ) {
				$casos[ $fase ] = array( $fase, array( 'phase' => $fase, 'run_id' => 'run-' . strtolower( $fase ) ) );
			}
		}
		return $casos;
	}

	/**
	 * @testdox La matriz de fases no está vacía: si `PHASE_*` cambia de nombre, las dos pruebas de arriba no pueden pasar en falso.
	 */
	public function test_la_matriz_cubre_las_fases_conocidas(): void {
		$fases = array_keys( self::todos_los_estados() );

		foreach ( array( 'sin estado', RebuildJob::PHASE_BUILDING, RebuildJob::PHASE_PUBLISHING, RebuildJob::PHASE_BLOCKED, RebuildJob::PHASE_ABORTED, RebuildJob::PHASE_DONE ) as $esperada ) {
			self::assertContains( $esperada, $fases );
		}
		self::assertGreaterThanOrEqual( 11, count( $fases ), 'Sin estado más las diez fases de hoy.' );
	}

	/**
	 * Deja correr el memo REAL de `serving_ready()` y sustituye sólo la lectura de
	 * la base: lo que `fresh_publication_identity()` encuentra es `$mundo->sirviendo`.
	 */
	private function con_la_foto_real_de_la_peticion(): void {
		\Patchwork\restore( $this->doble_serving_ready );

		$mundo = $this->mundo;
		\Patchwork\redefine(
			EditorialDiscountPublication::class . '::fresh_publication_identity',
			static function () use ( $mundo ): ?array {
				++$mundo->lecturas_frescas;
				return $mundo->sirviendo ? array(
					'token'               => str_repeat( 'a', 64 ),
					'runtime_fingerprint' => 'huella-de-la-peticion',
					'window_active'       => false,
					'boundary_at'         => null,
					'timezone'            => 'America/Santiago',
				) : null;
			}
		);
		\Patchwork\redefine( SemanticPublication::class . '::request_runtime_fingerprint', static fn(): ?string => 'huella-de-la-peticion' );

		// Una petición recién arrancada: registrada, con autoridad de lectura y sin foto.
		$arranque = array(
			'registered'               => true,
			'test_serving_override'    => null,
			'read_authority_available' => true,
			'serving_capture_done'     => false,
			'serving_capture_token'    => null,
			'serving_capture_window'   => null,
			'serving_final_checked'    => array(),
			'serving_final_matches_by' => array(),
			'serving_final_matches'    => false,
		);
		foreach ( $arranque as $nombre => $valor ) {
			$estatico = new ReflectionProperty( EditorialDiscountPublication::class, $nombre );
			$estatico->setAccessible( true );
			$estatico->setValue( null, $valor );
		}
	}

	/**
	 * Lo que hace `reconcile_on_init()` en la petición de cron cuando el reconcile
	 * no repara nada: pregunta `serving_ready()`, y con eso deja tomada la foto.
	 */
	private function init_23_toma_la_foto_con_el_buscador_caido(): void {
		$this->mundo->sirviendo = false;
		self::assertFalse( EditorialDiscountPublication::serving_ready(), 'Precondición: la foto de la petición se toma con el buscador caído.' );
	}

	/** @return array<string, int> */
	private function alarma(): array {
		$raw = $this->mundo->options[ self::OPTION_ALARMA ] ?? array();
		return is_array( $raw ) ? $raw : array();
	}

	/** Hace como que pasaron `$segundos` desde lo anotado en el episodio. */
	private function envejecer_episodio( int $segundos ): void {
		if ( ! isset( $this->mundo->options[ self::OPTION_ALARMA ] ) ) {
			return;
		}
		foreach ( array( 'down_since', 'notified_at' ) as $clave ) {
			if ( (int) ( $this->mundo->options[ self::OPTION_ALARMA ][ $clave ] ?? 0 ) > 0 ) {
				$this->mundo->options[ self::OPTION_ALARMA ][ $clave ] -= $segundos;
			}
		}
	}
}
