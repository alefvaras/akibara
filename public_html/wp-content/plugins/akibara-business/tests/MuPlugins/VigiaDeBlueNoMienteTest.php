<?php
/**
 * El vigía de Blue Express no puede dar una buena noticia falsa ni duplicar la alarma.
 *
 * Tres defectos medidos el 2026-09-12 y cerrados el 2026-09-18:
 *
 *   · S-4b42f9da — «plugin ausente» y «plugin sano» devolvían lo mismo (una lista
 *     vacía de roturas). Con la marca de rotura puesta, borrar o renombrar el
 *     plugin le mandaba al dueño «Blue arregló su paquete» justo cuando la tienda
 *     se había quedado sin transportista.
 *   · S-11a8d96f — la acción semanal se agendaba sin `unique` y sin grupo: dos
 *     duplicados son dos correos de alarma por el mismo incendio.
 *   · S-bf0573b4 — la consulta a Action Scheduler se pagaba en cada `admin-ajax`,
 *     que es por donde se hidrata el carrito del cliente, y con la versión cara.
 *
 * REAPERTURA de las dos últimas, 2026-09-19: el candado de ambas era un grep al
 * propio fuente que nunca ejecutaba el cierre de `init:30`.
 *
 *   · S-11a8d96f: faltaba la migración de la acción heredada SIN grupo que
 *     producción ya tenía programada antes del 2026-09-18 — sin
 *     `as_unschedule_all_actions()`, esa acción queda viva para siempre junto a
 *     la nueva (con grupo) y el dueño recibe DOS alarmas por el mismo incendio.
 *   · S-bf0573b4: el candado no podía distinguir «el memo evita la consulta» de
 *     «la consulta se sigue pagando»: subir `as_has_scheduled_action` por
 *     encima del `get_transient(...)`, o borrar el `return`, dejaba pasar la
 *     regresión en verde. Se extrajo el cierre a `akibara_bluex_watch_agendar()`
 *     para poder ejecutarlo con Brain Monkey.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class VigiaDeBlueNoMienteTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array{0:string,1:string}> */
	private array $correos = array();

	private static function ruta(): string {
		return dirname( __DIR__, 4 ) . '/mu-plugins/akibara-bluex-integrity-watch.php';
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$this->correos = array();

		// Un directorio de plugins que NO contiene bluex: es el escenario del
		// hallazgo (plugin borrado o renombrado).
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/akibara-sin-plugins' );
		}
		defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
		defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
		defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );

		Functions\when( 'get_option' )->alias( fn( string $n, $def = false ) => $this->options[ $n ] ?? $def );
		Functions\when( 'update_option' )->alias(
			function ( string $n, $v ): bool {
				$this->options[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $n ): bool {
				unset( $this->options[ $n ] );
				return true;
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( string $para, string $asunto ): bool {
				$this->correos[] = array( $para, $asunto );
				return true;
			}
		);

		if ( ! function_exists( 'akibara_bluex_integrity_check' ) ) {
			require_once self::ruta();
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Sin el plugin instalado, el vigía distingue «no está» de «está sano». */
	public function test_plugin_ausente_no_es_plugin_sano(): void {
		self::assertNull( akibara_bluex_broken_includes(), 'Una lista vacía diría «sano», que es falso.' );
	}

	/** @testdox Borrar el plugin NO dispara el correo de buena noticia. */
	public function test_no_hay_buena_noticia_sin_plugin(): void {
		$this->options = array(
			'admin_email'                    => 'ale.fvaras@gmail.com',
			'akb_bluex_watch_baseline_seen'  => 'yes',
		);

		akibara_bluex_integrity_check( 'prueba' );

		self::assertSame( array(), $this->correos, 'Le decía al dueño que Blue lo arregló cuando en realidad la tienda se quedó sin transportista.' );
		self::assertArrayNotHasKey( 'akb_bluex_watch_resolved_notified', $this->options, 'Tampoco puede sellar la buena noticia: si el plugin vuelve sano, ese correo sí corresponde.' );
	}

	/** @testdox La acción semanal se agenda una sola vez, con grupo. */
	public function test_el_agendado_es_unico(): void {
		$src = (string) file_get_contents( self::ruta() );

		self::assertMatchesRegularExpression(
			"/as_schedule_recurring_action\(\s*time\(\) \+ HOUR_IN_SECONDS,\s*WEEK_IN_SECONDS,\s*'akibara_bluex_integrity_weekly',\s*array\(\),\s*'akibara',\s*true\s*\)/",
			$src,
			'Sin grupo ni `unique`, cada duplicado es otro correo de alarma.'
		);
	}

	/** @testdox La consulta a la cola no se paga en cada petición del carrito. */
	public function test_la_cola_no_se_consulta_en_cada_ajax(): void {
		$src = (string) file_get_contents( self::ruta() );

		self::assertStringContainsString( "get_transient( 'akb_bluex_watch_agendado' )", $src, 'Sin memo, `admin-ajax` paga la consulta en cada hidratación del carrito.' );
		self::assertStringContainsString( 'as_has_scheduled_action(', $src, 'La pregunta es sí/no: no hace falta armar el objeto entero.' );
		self::assertStringNotContainsString( 'as_next_scheduled_action(', $src );
	}

	/**
	 * Deja el contexto en «carrito por admin-ajax»: `is_admin()` true y
	 * `wp_doing_ajax()` true. Ninguno de los tres caminos de reparación aplica
	 * ahí, así que `akibara_bluex_watch_agendar()` debe salir en la primera
	 * línea, sin tocar transients ni Action Scheduler.
	 */
	private function contexto_carrito_por_ajax(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
	}

	/** Deja el contexto en «escritorio»: puede reparar y no es ajax. */
	private function contexto_escritorio(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
	}

	/**
	 * @testdox REAPERTURA S-bf0573b4: el carrito (admin-ajax) no paga ni el memo ni la cola.
	 */
	public function test_el_carrito_no_paga_ni_el_memo_ni_la_cola(): void {
		$this->contexto_carrito_por_ajax();
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'as_has_scheduled_action' )->never();
		Functions\expect( 'as_schedule_recurring_action' )->never();

		self::assertNull( akibara_bluex_watch_agendar(), 'debe salir en la primera línea, sin efectos' );
	}

	/**
	 * @testdox REAPERTURA S-bf0573b4: con el memo puesto, no se consulta la cola.
	 *
	 * Regresión concreta que el grep del fuente deja pasar en verde: subir
	 * `as_has_scheduled_action` por encima del `get_transient(...)`, o borrar
	 * el `return` que sigue, mantiene las tres cadenas de
	 * `test_la_cola_no_se_consulta_en_cada_ajax` presentes pero vuelve a pagar
	 * la consulta en cada admin-ajax. Este candado sí lo cazaría.
	 */
	public function test_con_el_memo_puesto_no_se_consulta_la_cola(): void {
		$this->contexto_escritorio();
		Functions\when( 'get_transient' )->justReturn( 'ya' );
		Functions\expect( 'as_has_scheduled_action' )->never();
		Functions\expect( 'as_schedule_recurring_action' )->never();
		Functions\expect( 'set_transient' )->never();

		self::assertNull( akibara_bluex_watch_agendar(), 'con el memo puesto no debe tocar Action Scheduler' );
	}

	/** @testdox Sin memo, se pregunta a la cola una vez y se sella el memo por 24 h. */
	public function test_sin_memo_pregunta_una_vez_y_sella_24h(): void {
		$this->contexto_escritorio();
		Functions\when( 'get_transient' )->justReturn( false );
		$this->options['akb_bluex_watch_migracion_grupo'] = 'yes';
		Functions\expect( 'set_transient' )
			->once()
			->with( 'akb_bluex_watch_agendado', 'ya', DAY_IN_SECONDS );
		Functions\expect( 'as_has_scheduled_action' )
			->once()
			->with( 'akibara_bluex_integrity_weekly', null, 'akibara' )
			->andReturn( true );
		Functions\expect( 'as_schedule_recurring_action' )->never();

		self::assertNull( akibara_bluex_watch_agendar() );
	}

	/** @testdox Ya agendada con grupo, no se vuelve a programar. */
	public function test_ya_agendada_no_se_reprograma(): void {
		$this->contexto_escritorio();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$this->options['akb_bluex_watch_migracion_grupo'] = 'yes';
		Functions\when( 'as_has_scheduled_action' )->justReturn( true );
		Functions\expect( 'as_schedule_recurring_action' )->never();

		self::assertNull( akibara_bluex_watch_agendar() );
	}

	/**
	 * @testdox REAPERTURA S-11a8d96f: la heredada sin grupo se retira ANTES de programar la nueva, una sola vez.
	 */
	public function test_la_heredada_sin_grupo_se_retira_antes_de_programar(): void {
		$this->contexto_escritorio();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );

		$orden = array();
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook ) use ( &$orden ): void {
				$orden[] = 'unschedule:' . $hook;
			}
		);
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( ...$args ) use ( &$orden ): void {
				$orden[] = 'schedule';
			}
		);

		akibara_bluex_watch_agendar();

		self::assertSame(
			array( 'unschedule:akibara_bluex_integrity_weekly', 'schedule' ),
			$orden,
			'la baja tiene que ir ANTES de programar la nueva, o mataría la que se acaba de crear'
		);
		self::assertSame( 'yes', $this->options['akb_bluex_watch_migracion_grupo'] ?? null, 'la migración queda sellada para no repetirse' );
	}

	/** @testdox La migración ya sellada no vuelve a dar de baja nada. */
	public function test_la_migracion_ya_sellada_no_se_repite(): void {
		$this->contexto_escritorio();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$this->options['akb_bluex_watch_migracion_grupo'] = 'yes';
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_unschedule_all_actions' )->never();
		Functions\when( 'as_schedule_recurring_action' )->justReturn( 1 );

		self::assertNull( akibara_bluex_watch_agendar() );
	}
}
