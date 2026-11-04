<?php
/**
 * Candado del interruptor «Vitrina» (pestaña del panel, 2026-09-22).
 *
 * Tres contratos: el guardado nunca es más permisivo que el lector (la misma
 * función decide los dos lados), cambiar la vitrina —o desplegar el código que
 * la calcula— encola el recálculo, y el
 * recálculo aplica la vitrina elegida en sus DOS posiciones.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\CatalogOrder;

use Akibara\Business\Modules\CatalogOrder\CatalogOrder;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CatalogOrderVitrinaTest extends TestCase {

	/** Acciones únicas encoladas: hook => timestamp. */
	private array $single = array();

	/** @var mixed */
	private $wpdb_original;

	/** Veces que se disparó `litespeed_purge_all` en la corrida en curso. */
	private int $purgas = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->single                    = array();
		$_POST                           = array();
		$GLOBALS['akibara_test_options'] = array();
		$this->wpdb_original             = $GLOBALS['wpdb'] ?? null;

		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook ): int {
				$this->single[ $hook ] = $timestamp;
				return count( $this->single );
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $option, $value ): bool {
				$GLOBALS['akibara_test_options'][ $option ] = $value;
				return true;
			}
		);
		Functions\when( 'clean_post_cache' )->justReturn( null );

		// UNA sola expectativa para toda la prueba: con una por corrida, la
		// primera se quedaba con las purgas de las siguientes y «no purgó»
		// pasaba siempre.
		$this->purgas = 0;
		Actions\expectDone( 'litespeed_purge_all' )->zeroOrMoreTimes()->whenHappen(
			function (): void {
				++$this->purgas;
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['akibara_test_options'] );
		$GLOBALS['wpdb'] = $this->wpdb_original;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public static function valoresQueNoSonVitrina(): array {
		return array(
			'vacío'          => array( '' ),
			'mayúsculas'     => array( 'Disponibles_Primero' ),
			'con espacios'   => array( ' intercalado' ),
			'otro texto'     => array( 'aleatorio' ),
			'número'         => array( 1 ),
			'nulo'           => array( null ),
			'array'          => array( array( 'intercalado' ) ),
			'booleano'       => array( true ),
		);
	}

	/**
	 * @testdox El lector sólo reconoce las dos vitrinas exactas
	 */
	public function test_parse_accepts_only_the_two_showcases(): void {
		self::assertSame( 'disponibles_primero', CatalogOrder::parse_showcase( 'disponibles_primero' ) );
		self::assertSame( 'intercalado', CatalogOrder::parse_showcase( 'intercalado' ) );
	}

	/**
	 * @testdox Un valor que no es vitrina no pasa el lector
	 * @dataProvider valoresQueNoSonVitrina
	 *
	 * @param mixed $valor Valor a probar.
	 */
	public function test_parse_rejects_anything_else( $valor ): void {
		self::assertNull( CatalogOrder::parse_showcase( $valor ) );
	}

	/**
	 * @testdox Sin fila, la vitrina es «disponibles primero», por decisión del dueño
	 */
	public function test_default_is_the_owners_decision(): void {
		self::assertSame( 'disponibles_primero', CatalogOrder::DEFAULT_SHOWCASE );
		self::assertSame( 'disponibles_primero', CatalogOrder::current_showcase() );
	}

	/**
	 * @testdox Una fila con basura cae a la del dueño en vez de dejar la tienda sin orden
	 */
	public function test_garbage_in_the_row_falls_back_to_the_default(): void {
		$GLOBALS['akibara_test_options'][ CatalogOrder::OPTION_SHOWCASE ] = 'lo-que-sea';
		self::assertSame( 'disponibles_primero', CatalogOrder::current_showcase() );

		$GLOBALS['akibara_test_options'][ CatalogOrder::OPTION_SHOWCASE ] = 'intercalado';
		self::assertSame( 'intercalado', CatalogOrder::current_showcase() );
	}

	/**
	 * @testdox Ida y vuelta: todo lo que el guardado escribe lo acepta el lector
	 * @dataProvider valoresQueNoSonVitrina
	 *
	 * @param mixed $valor Valor enviado.
	 */
	public function test_save_is_never_more_permissive_than_the_reader( $valor ): void {
		$GLOBALS['akibara_test_options'][ CatalogOrder::OPTION_SHOWCASE ] = 'intercalado';

		$guardado = CatalogOrder::sanitize_showcase_for_save( $valor );

		self::assertNotNull( CatalogOrder::parse_showcase( $guardado ) );
		self::assertSame( 'intercalado', $guardado, 'Lo inválido conserva la vitrina vigente; no la cambia a escondidas.' );
	}

	/**
	 * @testdox Un envío fabricado con otra vitrina rechaza la pestaña entera y lo dice
	 */
	public function test_validation_rejects_a_forged_value(): void {
		$_POST[ CatalogOrder::OPTION_SHOWCASE ] = 'todo-a-pedido';
		$errores = CatalogOrder::validate_save( array(), CatalogOrder::PANEL_TAB_ID );

		self::assertCount( 1, $errores );
		self::assertStringContainsString( 'No se guardó nada', $errores[0] );

		$_POST[ CatalogOrder::OPTION_SHOWCASE ] = 'intercalado';
		self::assertSame( array(), CatalogOrder::validate_save( array(), CatalogOrder::PANEL_TAB_ID ) );

		$_POST[ CatalogOrder::OPTION_SHOWCASE ] = 'todo-a-pedido';
		self::assertSame( array(), CatalogOrder::validate_save( array(), 'otra_pestana' ), 'Sólo juzga su pestaña.' );
	}

	/**
	 * @testdox El formulario ofrece exactamente las dos vitrinas que el lector acepta
	 */
	public function test_the_form_offers_only_valid_showcases(): void {
		$campos = array_values(
			array_filter(
				( new CatalogOrder() )->get_settings(),
				static fn( array $campo ): bool => CatalogOrder::OPTION_SHOWCASE === ( $campo['id'] ?? '' )
			)
		);

		self::assertCount( 1, $campos );
		self::assertSame( 'radio', $campos[0]['type'] );
		self::assertSame( CatalogOrder::DEFAULT_SHOWCASE, $campos[0]['default'] );
		self::assertFalse( $campos[0]['autoload'], 'Sólo la lee el recálculo: no tiene por qué cargarse en cada página.' );
		self::assertSame( array( 'disponibles_primero', 'intercalado' ), array_keys( $campos[0]['options'] ) );
	}

	/**
	 * @testdox El formulario no lleva copy que el panel nunca pinta
	 *
	 * B1-R2: el panel borra el título de la primera sección de ajustes para no
	 * repetir el suyo, así que «Qué ve primero el cliente» nunca se veía y su
	 * explicación quedaba pegada al bloque «Recálculo». El encabezado lo pinta
	 * ahora `render_admin_section()` (CatalogOrderPanelTest lo fija).
	 */
	public function test_the_form_carries_no_invisible_copy(): void {
		$titulos = array_values(
			array_filter(
				( new CatalogOrder() )->get_settings(),
				static fn( array $campo ): bool => 'title' === ( $campo['type'] ?? '' )
			)
		);

		self::assertCount( 1, $titulos );
		self::assertSame( '', $titulos[0]['title'] );
		self::assertArrayNotHasKey( 'desc', $titulos[0] );
	}

	/**
	 * @testdox Cambiar la vitrina encola el recálculo al momento, en el alta y en los cambios de la fila
	 */
	public function test_changing_the_showcase_queues_the_recompute_now(): void {
		$modulo = new CatalogOrder();
		$modulo->register();

		self::assertNotFalse( has_action( 'add_option_' . CatalogOrder::OPTION_SHOWCASE, array( $modulo, 'on_showcase_changed' ) ) );
		self::assertNotFalse( has_action( 'update_option_' . CatalogOrder::OPTION_SHOWCASE, array( $modulo, 'on_showcase_changed' ) ) );

		$antes = time();
		$modulo->on_showcase_changed();

		self::assertArrayHasKey( 'akibara_catalog_order_recompute', $this->single );
		self::assertLessThanOrEqual( $antes + 1, $this->single['akibara_catalog_order_recompute'], 'Sin el debounce de las ráfagas de importación.' );
	}

	/**
	 * @testdox Actualizar el plugin encola el recálculo al momento, aunque la vitrina nueva llegue como valor por defecto
	 *
	 * B1-REV2-1: en producción la fila `akb_catalog_order_showcase` no existe.
	 * Desplegar «disponibles primero» como valor por defecto no dispara
	 * `add_option_*` ni `update_option_*`, y guardar la pestaña sin tocarla
	 * tampoco escribe. Sin este gancho, el panel marcaba «Disponibles primero»
	 * y la tienda seguía intercalada hasta las 00:05.
	 */
	public function test_upgrading_the_plugin_queues_the_recompute_now(): void {
		$modulo = new CatalogOrder();
		$modulo->register();

		self::assertNotFalse(
			has_action( 'akibara_business_upgraded', array( $modulo, 'on_business_upgraded' ) ),
			'El despliegue es el único momento que ve la vitrina nueva sin que nadie toque la fila.'
		);

		$antes = time();
		$modulo->on_business_upgraded();

		self::assertArrayHasKey( 'akibara_catalog_order_recompute', $this->single );
		self::assertLessThanOrEqual( $antes + 1, $this->single['akibara_catalog_order_recompute'], 'En la siguiente pasada del cron, no tras el debounce ni a las 00:05.' );
	}

	/**
	 * @testdox Una reanudación del upgrade no duplica un recálculo que ya espera en la cola
	 *
	 * El hook del upgrade se puede re-emitir con el mismo par de versiones; sus
	 * listeners tienen que ser idempotentes.
	 */
	public function test_upgrading_twice_does_not_duplicate_a_pending_recompute(): void {
		$pendientes = 0;
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args ) use ( &$pendientes ): array {
				return 'pending' === ( $args['status'] ?? '' ) && $pendientes > 0 ? array( 4242 ) : array();
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook ) use ( &$pendientes ): int {
				$this->single[ $hook ] = $timestamp;
				return ++$pendientes;
			}
		);

		$modulo = new CatalogOrder();
		$modulo->on_business_upgraded();
		$modulo->on_business_upgraded();

		self::assertSame( 1, $pendientes );
	}

	/**
	 * Catálogo con la forma medida en producción: la novedad es «a pedido».
	 *
	 * @param array<int, int> $current id => menu_order actual.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filas( array $current = array() ): array {
		$base = array(
			array( 'id' => 145, 'date' => '2026-09-20 10:00:00', 'serie_tt' => '10', 'stock_status' => 'onbackorder' ),
			array( 'id' => 144, 'date' => '2026-08-01 10:00:00', 'serie_tt' => '10', 'stock_status' => 'instock' ),
			array( 'id' => 311, 'date' => '2026-09-10 10:00:00', 'serie_tt' => '20', 'stock_status' => 'instock' ),
			array( 'id' => 214, 'date' => '2026-09-19 10:00:00', 'serie_tt' => '30', 'stock_status' => 'outofstock' ),
		);

		return array_map(
			static fn( array $fila ): array => $fila + array( 'current_order' => (string) ( $current[ $fila['id'] ] ?? 0 ) ),
			$base
		);
	}

	/**
	 * Corre `recompute()` contra un doble de `$wpdb` y devuelve lo que escribió.
	 *
	 * @param array<int, int> $current id => menu_order actual.
	 * @return array{0:int, 1:array<int,int>, 2:int} Aplicados, id => menu_order escrito, purgas.
	 */
	private function recalcular( array $current = array() ): array {
		$GLOBALS['wpdb'] = new VitrinaWpdbDouble( self::filas( $current ) );
		$this->purgas    = 0;

		$aplicados = ( new CatalogOrder() )->recompute();

		return array( $aplicados, $GLOBALS['wpdb']->escrito, $this->purgas );
	}

	/**
	 * @testdox Interruptor en «disponibles primero»: lo que está en bodega abre la vitrina
	 */
	public function test_recompute_available_first(): void {
		[ $aplicados, $escrito, $purgas ] = $this->recalcular();

		asort( $escrito );
		self::assertSame( array( 311, 144, 145, 214 ), array_keys( $escrito ) );
		self::assertSame( 4, $aplicados );
		self::assertSame( 1, $purgas, 'Reordenar exige purgar: la caché seguiría sirviendo el orden viejo.' );
	}

	/**
	 * @testdox Interruptor en «intercalado»: el orden de siempre, con la novedad «a pedido» arriba
	 */
	public function test_recompute_interleaved(): void {
		$GLOBALS['akibara_test_options'][ CatalogOrder::OPTION_SHOWCASE ] = 'intercalado';

		[ , $escrito ] = $this->recalcular();

		asort( $escrito );
		self::assertSame( array( 145, 214, 311, 144 ), array_keys( $escrito ) );
	}

	/**
	 * @testdox El plan calcula las dos vitrinas sin escribir nada
	 *
	 * Es lo que usa QA (y la prueba e2e `negocio-vitrina`) para saber qué
	 * vitrina está aplicada sin depender de una muestra de tarjetas: si leer el
	 * plan escribiera, medir cambiaría lo medido.
	 */
	public function test_the_plan_reads_both_showcases_without_writing(): void {
		$GLOBALS['wpdb'] = new VitrinaWpdbDouble( self::filas( array( 145 => 7, 144 => 8, 311 => 9, 214 => 10 ) ) );
		$modulo          = new CatalogOrder();

		$disponibles = $modulo->plan( 'disponibles_primero' );
		$intercalado = $modulo->plan( 'intercalado' );

		self::assertSame( array( 145 => 7, 144 => 8, 311 => 9, 214 => 10 ), $disponibles['current'] );
		asort( $disponibles['orders'] );
		asort( $intercalado['orders'] );
		self::assertSame( array( 311, 144, 145, 214 ), array_keys( $disponibles['orders'] ) );
		self::assertSame( array( 145, 214, 311, 144 ), array_keys( $intercalado['orders'] ) );
		$desconocida = $modulo->plan( 'lo-que-sea' );
		asort( $desconocida['orders'] );
		self::assertSame( $disponibles['orders'], $desconocida['orders'], 'Una vitrina desconocida cae a la del dueño.' );
		self::assertSame( array(), $GLOBALS['wpdb']->escrito, 'Leer el plan no escribe menu_order.' );
		self::assertArrayNotHasKey( 'akb_catalog_order_last_run', $GLOBALS['akibara_test_options'], 'Ni cuenta como corrida.' );
		self::assertSame( 0, $this->purgas );
	}

	/**
	 * @testdox Recalcular dos veces: la segunda no reescribe nada ni purga
	 */
	public function test_second_recompute_changes_nothing(): void {
		[ , $escrito ] = $this->recalcular();

		[ $aplicados, $segunda, $purgas ] = $this->recalcular( $escrito );

		self::assertSame( 0, $aplicados );
		self::assertSame( array(), $segunda );
		self::assertSame( 0, $purgas );
	}
}

/**
 * Doble mínimo de `$wpdb` para `CatalogOrder::recompute()`: devuelve el
 * catálogo pedido y registra los menu_order que el UPDATE por CASE escribe.
 */
final class VitrinaWpdbDouble {

	/** @var string */
	public $prefix = 'wp_';

	/** @var string */
	public $posts = 'wp_posts';

	/** @var string */
	public $term_relationships = 'wp_term_relationships';

	/** @var string */
	public $term_taxonomy = 'wp_term_taxonomy';

	/** @var string */
	public $postmeta = 'wp_postmeta';

	/** @var string */
	public $last_error = '';

	/** @var array<int, int> id => menu_order escrito. */
	public array $escrito = array();

	/** @var array<int, array<string, mixed>> */
	private array $filas;

	/**
	 * @param array<int, array<string, mixed>> $filas Filas del SELECT.
	 */
	public function __construct( array $filas ) {
		$this->filas = $filas;
	}

	/**
	 * @param string $sql    Consulta.
	 * @param string $output Formato.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $sql, string $output ): array {
		unset( $sql, $output );
		return $this->filas;
	}

	/**
	 * @param string $query Plantilla.
	 * @param mixed  ...$args Valores.
	 */
	public function prepare( string $query, ...$args ): string {
		return vsprintf( $query, $args );
	}

	/**
	 * @param string $sql UPDATE por CASE.
	 * @return int Filas tocadas.
	 */
	public function query( string $sql ): int {
		preg_match_all( '/WHEN (\d+) THEN (\d+)/', $sql, $m, PREG_SET_ORDER );
		foreach ( $m as $par ) {
			$this->escrito[ (int) $par[1] ] = (int) $par[2];
		}

		return count( $m );
	}
}
