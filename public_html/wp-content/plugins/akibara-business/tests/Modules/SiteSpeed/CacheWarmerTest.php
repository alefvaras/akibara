<?php
/**
 * Recalentado nocturno: ventana de la tienda, presupuesto por corrida, orden de
 * trabajo, frenos, clasificación de respuestas y ruta directa al origen.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SiteSpeed;

use Akibara\Business\Modules\SiteSpeed\CacheWarmer;
use Akibara\Business\Tests\Support\OptionsRowDouble;
use Akibara\Business\Tests\Support\OptionsRowWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** Doble de `$wpdb` que además entrega la cola de fichas. */
final class CacheWarmerWpdb extends OptionsRowWpdb {

	/** @var array<int, int> */
	public array $fichas = array();

	/** @return array<int, string> */
	public function get_col( string $sql ): array {
		$this->consultas[] = $sql;
		return array_map( 'strval', $this->fichas );
	}
}

final class CacheWarmerTest extends TestCase {

	use OptionsRowDouble;

	/** @var array<string, mixed> */
	private array $options = array();

	private CacheWarmerWpdb $db;

	private float $ahora = 0.0;

	/** Segundos que «tarda» cada tanda pedida. */
	private float $costo = 1.0;

	/** @var array<int, array{0: array<int, array<string, mixed>>, 1: string}> */
	private array $pedidas = array();

	/** @var array<int|string, array<string, mixed>> Respuesta por ficha; `*` para las demás. */
	private array $respuesta = array();

	/** @var array<int, int> */
	private array $agendadas = array();

	private int $cancelaciones = 0;

	/** @var array<int, int> */
	private array $pendientes = array();

	private string $home = 'https://akibara.cl/';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options       = array();
		$this->pedidas       = array();
		$this->agendadas     = array();
		$this->cancelaciones = 0;
		$this->pendientes    = array();
		$this->costo         = 1.0;
		$this->home          = 'https://akibara.cl/';
		$this->respuesta     = array(
			'*' => array( 'status' => 200, 'headers' => array( 'x-litespeed-cache' => 'miss', 'x-litespeed-cache-control' => 'public,max-age=3600' ) ),
		);

		$this->db = new CacheWarmerWpdb();
		$filas    = array();
		$this->instalar_doble_de_options( $filas, $this->db );

		Functions\when( 'wp_timezone' )->alias( static fn(): DateTimeZone => new DateTimeZone( 'America/Santiago' ) );
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_permalink' )->alias( static fn( int $id ): string => 'https://akibara.cl/tomo-' . $id . '/' );
		Functions\when( 'home_url' )->alias( fn(): string => $this->home );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ) => parse_url( $url ) );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp ): int {
				$this->agendadas[] = $timestamp;
				return 1;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function (): void {
				++$this->cancelaciones;
			}
		);
		Functions\when( 'as_get_scheduled_actions' )->alias( fn(): array => $this->pendientes );
	}

	protected function tearDown(): void {
		$this->retirar_doble_de_options();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function local( string $fecha ): int {
		return ( new DateTimeImmutable( $fecha, new DateTimeZone( 'America/Santiago' ) ) )->getTimestamp();
	}

	private function warmer(): CacheWarmer {
		return new CacheWarmer(
			function ( array $peticiones, string $ruta ): array {
				$this->pedidas[] = array( $peticiones, $ruta );
				$this->ahora    += $this->costo;
				$salida          = array();
				foreach ( array_keys( $peticiones ) as $id ) {
					$salida[ $id ] = $this->respuesta[ $id ] ?? $this->respuesta['*'];
				}
				return $salida;
			},
			fn(): float => $this->ahora
		);
	}

	/** @return array<int, string> Las fichas pedidas, en orden. */
	private function urls_pedidas(): array {
		$urls = array();
		foreach ( $this->pedidas as $tanda ) {
			foreach ( $tanda[0] as $peticion ) {
				$urls[] = $peticion['url'];
			}
		}
		return $urls;
	}

	// ------------------------------------------------------------------ ventana

	/** @testdox La ventana va de las 00:20 a las 06:00 de la tienda, después del reordenado de la vitrina. */
	public function test_window_is_the_store_night(): void {
		self::assertFalse( CacheWarmer::in_window( $this->local( '2026-09-16 00:19:59' ) ) );
		self::assertTrue( CacheWarmer::in_window( $this->local( '2026-09-16 00:20:00' ) ) );
		self::assertTrue( CacheWarmer::in_window( $this->local( '2026-09-16 05:59:59' ) ) );
		self::assertFalse( CacheWarmer::in_window( $this->local( '2026-09-16 06:00:00' ) ) );
		self::assertFalse( CacheWarmer::in_window( $this->local( '2026-09-16 15:00:00' ) ) );
	}

	/** @testdox El próximo comienzo es a las 00:20 locales, con el cambio de hora incluido. */
	public function test_next_window_start_follows_the_store_calendar(): void {
		self::assertSame( $this->local( '2026-09-17 00:20:00' ), CacheWarmer::next_window_start( $this->local( '2026-09-16 15:00:00' ) ) );
		self::assertSame( $this->local( '2026-09-16 00:20:00' ), CacheWarmer::next_window_start( $this->local( '2026-09-16 00:05:00' ) ) );
		self::assertSame( $this->local( '2026-09-17 00:20:00' ), CacheWarmer::next_window_start( $this->local( '2026-09-16 00:20:00' ) ) );

		// La noche del 5 al 6 de septiembre de 2026 el reloj salta de 23:59:59 a 01:00:
		// las 00:20 no existen y el comienzo cae dentro de la ventana del día 6.
		$salto = CacheWarmer::next_window_start( $this->local( '2026-09-05 23:00:00' ) );
		$local = ( new DateTimeImmutable( '@' . $salto ) )->setTimezone( new DateTimeZone( 'America/Santiago' ) );
		self::assertSame( '2026-09-06', $local->format( 'Y-m-d' ) );
		self::assertTrue( CacheWarmer::in_window( $salto ) );
	}

	// ------------------------------------------------------------ clasificación

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function respuestas(): array {
		return array(
			'ya guardada'          => array( array( 'status' => 200, 'headers' => array( 'x-litespeed-cache' => 'hit' ) ), 'hit' ),
			'guardada ahora'       => array( array( 'status' => 200, 'headers' => array( 'x-litespeed-cache' => 'miss', 'x-litespeed-cache-control' => 'public,max-age=86000' ) ), 'miss' ),
			'construida sin caché' => array( array( 'status' => 200, 'headers' => array( 'x-litespeed-cache' => 'miss', 'x-litespeed-cache-control' => 'no-cache' ) ), 'sin_cache' ),
			'sin cabecera'         => array( array( 'status' => 200, 'headers' => array() ), 'sin_cache' ),
			'redirección'          => array( array( 'status' => 301, 'headers' => array() ), 'sin_cache' ),
			'no existe'            => array( array( 'status' => 404, 'headers' => array() ), 'error' ),
			'error del servidor'   => array( array( 'status' => 503, 'headers' => array() ), 'error' ),
			'sin conexión'         => array( array( 'error' => 'timeout' ), 'error' ),
			'vacía'                => array( array(), 'error' ),
		);
	}

	/**
	 * @dataProvider respuestas
	 * @testdox Clasifica la respuesta: $esperado
	 *
	 * @param array<string, mixed> $respuesta Respuesta del origen.
	 * @param string               $esperado  Clase.
	 */
	public function test_classifies_origin_responses( array $respuesta, string $esperado ): void {
		self::assertSame( $esperado, CacheWarmer::classify( $respuesta ) );
	}

	// ----------------------------------------------------------- ruta al origen

	/** @testdox Va directo al origen conservando el nombre del sitio y su puerto. */
	public function test_connects_straight_to_the_origin(): void {
		self::assertSame( 'akibara.cl:443:127.0.0.1:443', CacheWarmer::connect_to( 'https://akibara.cl/tomo-1/' ) );
		self::assertNull( CacheWarmer::connect_to( 'https://otro.cl/tomo-1/' ), 'Nunca pide a otro sitio.' );
		self::assertNull( CacheWarmer::connect_to( 'no es una url' ) );

		$this->home = 'http://localhost:8080/';
		self::assertSame( 'localhost:8080:127.0.0.1:8080', CacheWarmer::connect_to( 'http://localhost:8080/tomo-1/' ) );
	}

	/** @testdox El filtro puede cambiar la ruta, pero no dejarla con otro formato. */
	public function test_connect_to_filter_is_validated(): void {
		Functions\when( 'apply_filters' )->justReturn( 'localhost:8080:127.0.0.1:80' );
		self::assertSame( 'localhost:8080:127.0.0.1:80', CacheWarmer::connect_to( 'https://akibara.cl/tomo-1/' ) );
		Functions\when( 'apply_filters' )->justReturn( 'rm -rf /' );
		self::assertNull( CacheWarmer::connect_to( 'https://akibara.cl/tomo-1/' ) );
		Functions\when( 'apply_filters' )->justReturn( null );
		self::assertNull( CacheWarmer::connect_to( 'https://akibara.cl/tomo-1/' ) );
	}

	/**
	 * @testdox La ficha se pide como un navegador real: con avif y webp en el Accept.
	 *
	 * Si el `.htaccess` separa la copia webp (LiteSpeed lo hace por el Accept), un
	 * Accept sin webp calentaría una copia que casi ningún cliente pide.
	 */
	public function test_request_looks_like_a_real_browser(): void {
		$peticion = CacheWarmer::request_for( 'https://akibara.cl/tomo-1/' );
		self::assertSame( 'https://akibara.cl/tomo-1/', $peticion['url'] );
		self::assertStringContainsString( 'image/webp', $peticion['headers']['Accept'] );
		self::assertStringContainsString( 'image/avif', $peticion['headers']['Accept'] );
		self::assertStringContainsString( 'text/html', $peticion['headers']['Accept'] );
		self::assertStringContainsString( 'AkibaraCacheWarm', $peticion['headers']['User-Agent'] );
	}

	/**
	 * @testdox Cada petición lleva su propio objeto de ganchos, y sin ruta directa falla.
	 *
	 * Requests registra en ese objeto su parseo de la respuesta: compartido entre las
	 * peticiones de una tanda, la segunda recibía la respuesta ya parseada y todas
	 * salían con error (medido en el espejo, 2026-09-15). Y si cURL no acepta
	 * CONNECT_TO, la petición saldría por Cloudflare: tiene que fallar.
	 */
	public function test_transport_source_locks(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/SiteSpeed/CacheWarmer.php' );
		$fuente = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $fuente );
		self::assertMatchesRegularExpression( '/foreach \( \$peticiones as \$clave => \$peticion \) \{\s*\$hooks = new \\\\WpOrg\\\\Requests\\\\Hooks\(\);/', $fuente );
		self::assertSame( 1, substr_count( $fuente, 'new \\WpOrg\\Requests\\Hooks()' ) );
		self::assertMatchesRegularExpression( '/if \( ! curl_setopt\( \$handle, CURLOPT_CONNECT_TO, array\( \$ruta \) \) \) \{\s*throw new/', $fuente );
	}

	// ------------------------------------------------------------------ corridas

	/** @testdox Fuera de la ventana y sin pasada abierta no pide nada y agenda la noche. */
	public function test_outside_the_window_it_only_schedules_the_night(): void {
		$this->ahora      = (float) $this->local( '2026-09-16 15:00:00' );
		$this->db->fichas = array( 1, 2, 3 );

		$this->warmer()->run();

		self::assertSame( array(), $this->pedidas );
		self::assertSame( array( $this->local( '2026-09-17 00:20:00' ) ), $this->agendadas );
		self::assertArrayNotHasKey( CacheWarmer::OPTION_STATE, $this->options );
	}

	/** @testdox De noche abre la pasada, pide todo en orden de a dos fichas y agenda la noche siguiente. */
	public function test_night_run_warms_every_product_in_order(): void {
		$this->ahora      = (float) $this->local( '2026-09-16 00:22:00' );
		$this->db->fichas = array( 30, 10, 20 );

		$this->warmer()->run();

		self::assertSame(
			array( 'https://akibara.cl/tomo-30/', 'https://akibara.cl/tomo-10/', 'https://akibara.cl/tomo-20/' ),
			$this->urls_pedidas()
		);
		self::assertCount( 2, $this->pedidas, 'Dos tandas: [30, 10] y [20].' );
		self::assertSame( array( 30, 10 ), array_keys( $this->pedidas[0][0] ) );
		self::assertSame( 'akibara.cl:443:127.0.0.1:443', $this->pedidas[0][1] );
		$estado = CacheWarmer::state();
		self::assertSame( 3, $estado['cursor'] );
		self::assertSame( 3, $estado['total'] );
		self::assertSame( 3, $estado['calentadas'] );
		self::assertSame( 0, $estado['ya_calientes'] );
		self::assertSame( '2026-09-16', $estado['day'] );
		self::assertGreaterThan( 0, $estado['finished'] );
		self::assertArrayNotHasKey( CacheWarmer::OPTION_QUEUE, $this->options, 'La cola se suelta al terminar.' );
		self::assertSame( $this->local( '2026-09-17 00:20:00' ), end( $this->agendadas ) );
		self::assertArrayNotHasKey( CacheWarmer::LOCK_OPTION, $this->db->filas, 'El candado se suelta.' );
	}

	/** @testdox Cada respuesta cuenta en su casilla. */
	public function test_each_response_is_counted(): void {
		$this->ahora      = (float) $this->local( '2026-09-16 01:00:00' );
		$this->db->fichas = array( 1, 2, 3, 4 );
		$this->respuesta[1] = array( 'status' => 200, 'headers' => array( 'x-litespeed-cache' => 'hit' ) );
		$this->respuesta[3] = array( 'status' => 301, 'headers' => array() );
		$this->respuesta[4] = array( 'status' => 404, 'headers' => array() );

		$this->warmer()->run();

		$estado = CacheWarmer::state();
		self::assertSame( 1, $estado['ya_calientes'] );
		self::assertSame( 1, $estado['calentadas'] );
		self::assertSame( 1, $estado['sin_cache'] );
		self::assertSame( 1, $estado['errores'] );
		self::assertSame( 'https://akibara.cl/tomo-4/: respondió 404', $estado['ultimo_error'] );
		self::assertSame( 4, $estado['cursor'], 'Un 404 no frena: esa ficha no existe, las demás sí.' );
	}

	/** @testdox Una corrida trabaja 20 s como mucho y agenda la siguiente en un minuto. */
	public function test_run_respects_its_budget_and_continues(): void {
		$inicio           = $this->local( '2026-09-16 01:00:00' );
		$this->ahora      = (float) $inicio;
		$this->costo      = 7.0;
		$this->db->fichas = range( 1, 10 );

		$this->warmer()->run();

		self::assertCount( 3, $this->pedidas, 'A 7 s por tanda caben 3 tandas en 20 s.' );
		$estado = CacheWarmer::state();
		self::assertSame( 6, $estado['cursor'] );
		self::assertSame( 0, $estado['finished'] );
		self::assertSame( $inicio + 60, end( $this->agendadas ) );

		// La corrida siguiente retoma desde el cursor, no desde el principio.
		$this->pedidas = array();
		$this->warmer()->run();
		self::assertSame( 'https://akibara.cl/tomo-7/', $this->urls_pedidas()[0] );
	}

	/** @testdox Una tanda de más de 8 s corta la corrida: el servidor está ocupado. */
	public function test_a_slow_origin_stops_the_run(): void {
		$this->ahora      = (float) $this->local( '2026-09-16 01:00:00' );
		$this->costo      = 9.0;
		$this->db->fichas = range( 1, 10 );

		$this->warmer()->run();

		self::assertCount( 1, $this->pedidas );
		self::assertSame( 2, CacheWarmer::state()['cursor'] );
	}

	/** @testdox Un 5xx corta la corrida y sigue en la próxima. */
	public function test_a_server_error_stops_the_run(): void {
		$inicio           = $this->local( '2026-09-16 01:00:00' );
		$this->ahora      = (float) $inicio;
		$this->db->fichas = range( 1, 10 );
		$this->respuesta[2] = array( 'status' => 508, 'headers' => array() );

		$this->warmer()->run();

		self::assertCount( 1, $this->pedidas );
		$estado = CacheWarmer::state();
		self::assertSame( 2, $estado['cursor'] );
		self::assertSame( 1, $estado['errores'] );
		self::assertSame( 1, $estado['calentadas'], 'La otra ficha de la tanda sí cuenta.' );
		self::assertSame( 0, $estado['finished'] );
		self::assertSame( $inicio + 60, end( $this->agendadas ), 'La corrida siguiente se agenda desde el inicio de ésta.' );
	}

	/** @testdox Una petición sin respuesta también corta la corrida. */
	public function test_no_response_stops_the_run(): void {
		$this->ahora      = (float) $this->local( '2026-09-16 01:00:00' );
		$this->db->fichas = range( 1, 10 );
		$this->respuesta[1] = array( 'error' => 'Operation timed out' );

		$this->warmer()->run();

		self::assertCount( 1, $this->pedidas );
		self::assertSame( 2, CacheWarmer::state()['cursor'] );
	}

	/** @testdox A las 06:00 una pasada nocturna se corta sin pedir nada más. */
	public function test_night_pass_is_cut_at_six(): void {
		$this->options[ CacheWarmer::OPTION_STATE ] = array( 'started' => $this->local( '2026-09-16 00:20:00' ), 'day' => '2026-09-16', 'total' => 5, 'cursor' => 2 );
		$this->options[ CacheWarmer::OPTION_QUEUE ] = array( 1, 2, 3, 4, 5 );
		$this->ahora = (float) $this->local( '2026-09-16 06:03:00' );

		$this->warmer()->run();

		self::assertSame( array(), $this->pedidas );
		$estado = CacheWarmer::state();
		self::assertSame( 1, $estado['cortada'] );
		self::assertGreaterThan( 0, $estado['finished'] );
		self::assertSame( $this->local( '2026-09-17 00:20:00' ), end( $this->agendadas ) );
	}

	/** @testdox Una noche ya recalentada no se repite en la misma ventana. */
	public function test_same_night_is_not_repeated(): void {
		$this->options[ CacheWarmer::OPTION_STATE ] = array( 'started' => $this->local( '2026-09-16 00:20:00' ), 'finished' => $this->local( '2026-09-16 02:00:00' ), 'day' => '2026-09-16' );
		$this->ahora      = (float) $this->local( '2026-09-16 03:00:00' );
		$this->db->fichas = array( 1 );

		$this->warmer()->run();

		self::assertSame( array(), $this->pedidas );
	}

	/** @testdox «Recalentar ahora» trabaja a cualquier hora. */
	public function test_manual_pass_ignores_the_window(): void {
		$this->ahora      = (float) $this->local( '2026-09-16 15:00:00' );
		$this->db->fichas = array( 1, 2 );
		$warmer           = $this->warmer();

		self::assertSame( 'encolada', $warmer->start_manual() );
		self::assertSame( 1, CacheWarmer::state()['manual'] );
		self::assertSame( 'ya-en-curso', $warmer->start_manual(), 'No abre una segunda pasada encima.' );

		$warmer->run();
		self::assertCount( 1, $this->pedidas );
		self::assertGreaterThan( 0, CacheWarmer::state()['finished'] );
		self::assertSame( $this->local( '2026-09-17 00:20:00' ), end( $this->agendadas ) );
	}

	/**
	 * @testdox Una pasada manual que termina ya de noche no se come la pasada nocturna.
	 *
	 * Lo calentado antes de medianoche venció a medianoche: si la manual cerraba y
	 * agendaba la noche SIGUIENTE, esa noche quedaba sin recalentar.
	 */
	public function test_manual_pass_crossing_midnight_keeps_the_night_pass(): void {
		$this->options[ CacheWarmer::OPTION_STATE ] = array( 'started' => $this->local( '2026-09-15 23:50:00' ), 'day' => '2026-09-15', 'manual' => 1, 'total' => 2, 'cursor' => 0 );
		$this->options[ CacheWarmer::OPTION_QUEUE ] = array( 1, 2 );
		$this->db->fichas = array( 1, 2 );
		$cierre           = $this->local( '2026-09-16 00:30:00' );
		$this->ahora      = (float) $cierre;
		$this->costo      = 0.0;

		$this->warmer()->run();
		self::assertSame( array( $cierre + 60 ), array_slice( $this->agendadas, -1 ), 'La nocturna empieza en un minuto, no mañana.' );

		$this->pedidas = array();
		$this->ahora   = (float) ( $cierre + 60 );
		$this->warmer()->run();
		self::assertCount( 1, $this->pedidas, 'Se abrió la pasada de esta noche.' );
		self::assertSame( '2026-09-16', CacheWarmer::state()['day'] );
		self::assertSame( 0, CacheWarmer::state()['manual'] );
	}

	/** @testdox Con el candado de otra corrida vivo no hace nada. */
	public function test_live_lock_of_another_run_blocks(): void {
		$this->db->filas[ CacheWarmer::LOCK_OPTION ] = time() . '|otro';
		$this->ahora      = (float) $this->local( '2026-09-16 01:00:00' );
		$this->db->fichas = array( 1 );

		$this->warmer()->run();
		self::assertSame( array(), $this->pedidas );
		self::assertSame( 'ya-en-curso', $this->warmer()->start_manual() );
	}

	/** @testdox Apagado no trabaja ni encola, y apagarlo retira lo agendado, también en el primer guardado. */
	public function test_disabled_does_nothing(): void {
		$this->options[ CacheWarmer::OPTION_ENABLED ] = 'no';
		$this->ahora      = (float) $this->local( '2026-09-16 01:00:00' );
		$this->db->fichas = array( 1 );

		$this->warmer()->run();
		self::assertSame( array(), $this->pedidas );
		self::assertSame( 'apagado', $this->warmer()->start_manual() );

		CacheWarmer::on_toggle( 'yes', 'no' );
		self::assertSame( 1, $this->cancelaciones );
		CacheWarmer::on_toggle( 'no', 'yes' );
		self::assertSame( 1, $this->cancelaciones );

		// La fila no existía: WordPress la crea con add_option.
		CacheWarmer::on_first_save( CacheWarmer::OPTION_ENABLED, 'no' );
		self::assertSame( 2, $this->cancelaciones );
		CacheWarmer::on_first_save( CacheWarmer::OPTION_ENABLED, 'yes' );
		self::assertSame( 2, $this->cancelaciones );
	}

	/** @testdox Las fichas se piden primero las que se venden, al final las agotadas. */
	public function test_queue_orders_sellable_first(): void {
		$this->db->fichas = array( 5, 4 );
		self::assertSame( array( 5, 4 ), CacheWarmer::build_queue() );
		$sql = (string) end( $this->db->consultas );
		self::assertMatchesRegularExpression( "/post_status = 'publish'/", $sql );
		self::assertMatchesRegularExpression( "/ORDER BY \( s\.meta_value = 'outofstock' \) ASC, CAST\( v\.meta_value AS UNSIGNED \) DESC/", $sql );
	}

	/** @testdox Sólo el backend agenda, y una pasada detenida se retoma en un minuto. */
	public function test_maybe_schedule_only_from_the_backend(): void {
		$this->ahora = (float) $this->local( '2026-09-16 15:00:00' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		$this->warmer()->maybe_schedule();
		self::assertSame( array(), $this->agendadas, 'El front no consulta la cola.' );

		Functions\when( 'is_admin' )->justReturn( true );
		$this->warmer()->maybe_schedule();
		self::assertSame( array( $this->local( '2026-09-17 00:20:00' ) ), $this->agendadas );

		$this->agendadas = array();
		$this->options[ CacheWarmer::OPTION_STATE ] = array( 'started' => $this->local( '2026-09-16 00:20:00' ), 'total' => 9, 'cursor' => 4 );
		$this->warmer()->maybe_schedule();
		self::assertSame( array( (int) $this->ahora + 60 ), $this->agendadas );

		$this->agendadas  = array();
		$this->pendientes = array( 99 );
		$this->warmer()->maybe_schedule();
		self::assertSame( array(), $this->agendadas, 'Con una corrida pendiente no agenda otra.' );
	}

	/** @testdox El reordenado de la vitrina corre antes de que empiece la ventana. */
	public function test_catalog_order_runs_before_the_window(): void {
		$metodo = new \ReflectionMethod( \Akibara\Business\Modules\CatalogOrder\CatalogOrder::class, 'next_daily_timestamp' );
		$metodo->setAccessible( true );
		$vitrina = (int) $metodo->invoke( new \Akibara\Business\Modules\CatalogOrder\CatalogOrder(), $this->local( '2026-09-16 15:00:00' ) );

		self::assertLessThan( CacheWarmer::next_window_start( $this->local( '2026-09-16 15:00:00' ) ), $vitrina );
		self::assertFalse( CacheWarmer::in_window( $vitrina ), 'Su purga total no puede caer dentro de la pasada.' );
	}
}
