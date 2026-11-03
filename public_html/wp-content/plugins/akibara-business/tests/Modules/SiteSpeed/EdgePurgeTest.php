<?php
/**
 * La purga del precio tiene que llegar al BORDE, no sólo al origen.
 *
 * Defecto declarado en el manual del dueño: «a Cloudflare sólo llega la
 * limpieza completa; cambiar el precio de un producto no llega a Cloudflare».
 * Medido en producción el 2026-09-17: una ficha responde `cf-cache-status: HIT`
 * con `age` creciente pese al `cache-control: no-store` del origen.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SiteSpeed;

use Akibara\Business\Modules\SiteSpeed\EdgePurge;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionProperty;

final class EdgePurgeTest extends TestCase {

	/** @var array<int, array<string, mixed>> Llamadas a la API. */
	private array $llamadas = array();

	/** @var array<string, mixed> Options simuladas. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// N-orden-suite (2026-09-19): `$pendientes` es estático y sobrevive entre
		// pruebas del mismo proceso. `SeriesCacheTagsTest` anota URLs con
		// `EdgePurge::anotar()` sin llegar a `despachar()` (el `shutdown` real
		// nunca corre bajo PHPUnit); con `--order-by=reverse` esa prueba corre
		// ANTES y sus URLs se cuelan en `test_solo_urls_propias`. En producción
		// esto no pasa: el proceso PHP muere al terminar cada request.
		self::reset_estatico_de_edge_purge();

		$this->llamadas = array();
		$this->options  = array(
			'litespeed.conf.cdn-cloudflare_zone'  => 'zona123',
			'litespeed.conf.cdn-cloudflare_email' => 'dueno@akibara.cl',
			'litespeed.conf.cdn-cloudflare_key'   => 'clave-secreta',
		);

		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl' );
		Functions\when( 'get_option' )->alias(
			fn( string $k, $default_value = false ) => $this->options[ $k ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $k, $v ) {
				$this->options[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $u ) => parse_url( $u ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true}' );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ) {
				$this->llamadas[] = array( 'url' => $url, 'args' => $args );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);

		// `function_exists('wc_get_logger')` contagia el proceso: si CUALQUIER
		// prueba anterior de la suite completa ya mockeó `wc_get_logger` con
		// Brain Monkey, PHP la da por definida para siempre en este proceso —
		// aunque esta prueba nunca la mencione. Sin este mock, un escenario de
		// fallo (que si llama al logger) revienta con
		// `MissingFunctionExpectations` sólo cuando corre DENTRO de la suite
		// completa, nunca en aislamiento (N-orden-suite, 2026-09-19).
		Functions\when( 'wc_get_logger' )->justReturn(
			new class() {
				public function warning( string $message, array $context = array() ): void {
					unset( $message, $context );
				}
			}
		);
	}

	protected function tearDown(): void {
		self::reset_estatico_de_edge_purge();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Deja `EdgePurge::$pendientes` y `$enganchado` como al cargar la clase:
	 * sin esto, una prueba de ESTE fichero que deje URLs sin despachar (o el
	 * hook ya "enganchado") contamina la siguiente, en éste u otro fichero.
	 */
	private static function reset_estatico_de_edge_purge(): void {
		$pendientes = new ReflectionProperty( EdgePurge::class, 'pendientes' );
		$pendientes->setAccessible( true );
		$pendientes->setValue( null, array() );

		$enganchado = new ReflectionProperty( EdgePurge::class, 'enganchado' );
		$enganchado->setAccessible( true );
		$enganchado->setValue( null, false );
	}

	/** @testdox Purga por URL, con la zona y las credenciales que ya tiene LiteSpeed. */
	public function test_purga_por_url(): void {
		EdgePurge::run( array( 'https://akibara.cl/producto-a/', 'https://akibara.cl/serie/naruto/' ) );

		self::assertCount( 1, $this->llamadas );
		self::assertSame( 'https://api.cloudflare.com/client/v4/zones/zona123/purge_cache', $this->llamadas[0]['url'] );
		self::assertSame( 'Bearer clave-secreta', $this->llamadas[0]['args']['headers']['Authorization'], 'La credencial de Akibara es un token.' );
		// PREFIJOS, no `files`: medido en producción, `files` devuelve success y
		// la copia sigue sirviéndose (estas respuestas llevan `Vary`).
		self::assertSame(
			array( 'prefixes' => array( 'akibara.cl/producto-a/', 'akibara.cl/serie/naruto/' ) ),
			json_decode( $this->llamadas[0]['args']['body'], true ),
			'Se purgan ESAS páginas, no la caché entera del sitio.'
		);
	}

	/**
	 * Cloudflare tiene dos formatos de credencial y una no funciona con las
	 * cabeceras de la otra: el primer intento en producción devolvió 401 por
	 * mandar un token como si fuera clave global.
	 *
	 * @testdox Un token va como Bearer; una clave global, con el correo.
	 */
	public function test_autentica_segun_el_formato(): void {
		// Token (lo que tiene Akibara de verdad).
		$this->options['litespeed.conf.cdn-cloudflare_key'] = 'cfat_unTokenDeCloudflare123';
		EdgePurge::run( array( 'https://akibara.cl/a/' ) );
		$cabeceras = $this->llamadas[0]['args']['headers'];
		self::assertSame( 'Bearer cfat_unTokenDeCloudflare123', $cabeceras['Authorization'] );
		self::assertArrayNotHasKey( 'X-Auth-Key', $cabeceras, 'Con token, la clave global sobra y da 401.' );

		// Clave global: 37-45 hex en minúsculas.
		$this->llamadas = array();
		$this->options['litespeed.conf.cdn-cloudflare_key'] = str_repeat( 'a', 37 );
		EdgePurge::run( array( 'https://akibara.cl/a/' ) );
		$cabeceras = $this->llamadas[0]['args']['headers'];
		self::assertSame( str_repeat( 'a', 37 ), $cabeceras['X-Auth-Key'] );
		self::assertSame( 'dueno@akibara.cl', $cabeceras['X-Auth-Email'] );
		self::assertArrayNotHasKey( 'Authorization', $cabeceras );
	}

	/** @testdox Sin la integración de Cloudflare configurada, no llama a nadie. */
	public function test_sin_credenciales_no_llama(): void {
		$this->options['litespeed.conf.cdn-cloudflare_key'] = '';

		EdgePurge::run( array( 'https://akibara.cl/producto-a/' ) );

		self::assertSame( array(), $this->llamadas );
	}

	/** @testdox Con el interruptor apagado no se purga nada. */
	public function test_el_interruptor_manda(): void {
		$this->options[ EdgePurge::ENABLED_OPTION ] = 'no';

		EdgePurge::run( array( 'https://akibara.cl/producto-a/' ) );

		self::assertSame( array(), $this->llamadas );
	}

	/**
	 * Cloudflare admite 30 ficheros por llamada: con 31 URLs, una sola petición
	 * devolvería un error y no se purgaría NADA.
	 *
	 * @testdox Más de 30 URLs viajan en lotes.
	 */
	public function test_trocea_en_lotes_de_treinta(): void {
		$urls = array();
		for ( $i = 0; $i < 31; $i++ ) {
			$urls[] = 'https://akibara.cl/producto-' . $i . '/';
		}

		EdgePurge::run( $urls );

		self::assertCount( 2, $this->llamadas );
		self::assertCount( 30, json_decode( $this->llamadas[0]['args']['body'], true )['prefixes'] );
		self::assertCount( 1, json_decode( $this->llamadas[1]['args']['body'], true )['prefixes'] );
	}

	/** @testdox Una URL de otro dominio no entra en el lote. */
	public function test_solo_urls_propias(): void {
		EdgePurge::anotar( 'https://otro-sitio.cl/algo/' );
		EdgePurge::anotar( 'https://akibara.cl/producto-a/' );

		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( string $hook, array $args ) {
				$this->llamadas[] = array( 'hook' => $hook, 'urls' => $args[0] );
				return 1;
			}
		);
		EdgePurge::despachar();

		self::assertSame( array( 'https://akibara.cl/producto-a/' ), $this->llamadas[0]['urls'] );
	}

	/** @testdox El resultado queda anotado, y la credencial nunca. */
	public function test_deja_rastro_sin_credenciales(): void {
		EdgePurge::run( array( 'https://akibara.cl/producto-a/' ) );

		$ultimo = $this->options['akibara_edge_purge_last'] ?? array();
		self::assertTrue( $ultimo['ok'] );
		self::assertSame( 1, $ultimo['urls'] );
		self::assertStringNotContainsString( 'clave-secreta', wp_json_encode( $ultimo ) );
	}

	/**
	 * R4-fb4b69e8 (2026-09-19): antes `run()` nunca lanzaba, así que Action
	 * Scheduler daba la acción por completada y un 500/timeout se quedaba sin
	 * reintento alguno. Ahora un fallo con reintentos disponibles agenda UNO
	 * corto (60-90 s) con las MISMAS URLs del lote y el contador subido en uno
	 * — la forma en que Action Scheduler llama de vuelta, posicional
	 * (`array_values($args)`), exige que el orden sea (urls, intento).
	 *
	 * @testdox Un fallo con reintentos disponibles agenda uno corto, con las mismas URLs.
	 */
	public function test_agenda_un_reintento_corto_si_falla(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":false}' );

		$agendadas = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook, array $args, string $grupo ) use ( &$agendadas ) {
				$agendadas[] = array( $timestamp, $hook, $args, $grupo );
				return 1;
			}
		);

		$ahora = time();
		EdgePurge::run( array( 'https://akibara.cl/producto-a/' ) );

		self::assertCount( 1, $agendadas, 'Un fallo con reintentos disponibles SÍ debe agendar.' );
		self::assertSame( EdgePurge::HOOK, $agendadas[0][1] );
		self::assertSame( array( 'https://akibara.cl/producto-a/' ), $agendadas[0][2][0], 'Reintenta las mismas URLs, no prefijos.' );
		self::assertSame( 1, $agendadas[0][2][1], 'El intento sube de 0 a 1.' );
		self::assertGreaterThanOrEqual( $ahora + 60, $agendadas[0][0], 'El reintento es corto: el borde se cura solo a los 10 min.' );
		self::assertLessThanOrEqual( $ahora + 90, $agendadas[0][0] );
	}

	/** @testdox Agotados los reintentos, un fallo ya NO agenda más. */
	public function test_no_agenda_mas_alla_del_tope(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":false}' );

		$agendadas = array();
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( ...$args ) use ( &$agendadas ) {
				$agendadas[] = $args;
				return 1;
			}
		);

		// Simula el último reintento permitido (el propio `EdgePurge::MAX_REINTENTOS`,
		// leído por reflexión para no duplicar el número en la prueba).
		$tope = new ReflectionClassConstant( EdgePurge::class, 'MAX_REINTENTOS' );
		EdgePurge::run( array( 'https://akibara.cl/producto-a/' ), $tope->getValue() );

		self::assertSame( array(), $agendadas, 'Ya no queda reintento: encadenar más sería el defecto original.' );

		$ultimo = $this->options['akibara_edge_purge_last'] ?? array();
		self::assertFalse( $ultimo['ok'] );
		self::assertTrue( $ultimo['agotado'], 'Con el tope agotado, el fallo debe marcarse para la alerta del panel.' );
	}

	/** @testdox Un fallo suelto (con reintento pendiente) NO enciende la alerta del panel. */
	public function test_un_fallo_suelto_no_enciende_la_alerta(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":false}' );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );

		EdgePurge::run( array( 'https://akibara.cl/producto-a/' ) );

		self::assertNull( EdgePurge::health_alert(), 'Con reintento agendado, no es un fallo sostenido todavía.' );
	}

	/** @testdox Reintentos agotados y credenciales rotadas: la alerta nombra el remedio. */
	public function test_la_alerta_distingue_credenciales_rotadas(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":false}' );
		Functions\when( 'human_time_diff' )->justReturn( '20 minutos' );

		$tope = new ReflectionClassConstant( EdgePurge::class, 'MAX_REINTENTOS' );
		EdgePurge::run( array( 'https://akibara.cl/producto-a/' ), $tope->getValue() );

		$alerta = EdgePurge::health_alert();
		self::assertNotNull( $alerta );
		self::assertSame( 'warning', $alerta['level'] );
		self::assertStringContainsString( 'credenciales de Cloudflare en LiteSpeed Cache', $alerta['html'] );
	}

	/** @testdox Vuelto el éxito, la alerta se apaga sola (no hay opción invisible nueva que limpiar). */
	public function test_un_exito_posterior_apaga_la_alerta(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":false}' );
		Functions\when( 'as_schedule_single_action' )->justReturn( null );
		Functions\when( 'human_time_diff' )->justReturn( '1 hora' );

		$tope = new ReflectionClassConstant( EdgePurge::class, 'MAX_REINTENTOS' );
		EdgePurge::run( array( 'https://akibara.cl/producto-a/' ), $tope->getValue() );
		self::assertNotNull( EdgePurge::health_alert() );

		// El siguiente cambio de precio purga con éxito.
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true}' );
		EdgePurge::run( array( 'https://akibara.cl/producto-a/' ) );

		self::assertNull( EdgePurge::health_alert() );
	}
}
