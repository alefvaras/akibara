<?php
/**
 * «Avísame cuando llegue»: cuándo se dispara y cuándo NO.
 *
 * Es el correo con más carga de expectativa de la tienda —alguien dejó su
 * dirección esperándolo— y el que peor se ve si sale de más: un aviso por un
 * tomo que sigue agotado, o tres avisos por el mismo restock.
 *
 * Aquí se fija la decisión del vigilante, que hasta hoy no tenía prueba propia
 * (ampliación de cobertura, 2026-09-18). El envío en sí lo cubre
 * `InstockEmailTest`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	// El doble de `wpdb` de esta suite lo declara `CaptureRestControllerTest`,
	// que carga antes por orden alfabético. Se REQUIERE explícitamente en vez de
	// confiar en ese orden: declarar aquí una versión propia con otras firmas
	// rompería al otro según quién llegue primero
	// [[function-exists-miente-en-la-suite]].
	if ( ! class_exists( 'wpdb' ) ) {
		require_once __DIR__ . '/CaptureRestControllerTest.php';
	}
}

namespace Akibara\Business\Tests\Modules\BackInStock {

use Akibara\Business\Modules\BackInStock\StockWatcher;
use Akibara\Business\Modules\BackInStock\SubscriberRepository;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class VigilanteDeStockTest extends TestCase {

	/** @var array<int, array{0:string,1:array<string,mixed>,2:string}> */
	private array $encolado = array();

	/** @var array<int, int> Cuánta gente espera cada producto. */
	private array $esperando = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->encolado  = array();
		$this->esperando = array();

		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( string $hook, array $args = array(), string $grupo = '' ): int {
				$this->encolado[] = array( $hook, $args, $grupo );
				return count( $this->encolado );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Vigilante con un repositorio REAL sobre un `wpdb` de mentira.
	 *
	 * `SubscriberRepository` es `final` a propósito, así que no se hereda: se
	 * le da la base de datos que contesta lo que el caso necesita. Así lo que
	 * se prueba es el repositorio de verdad, no un doble que puede mentir
	 * [[dobles-que-no-modelan-produccion]].
	 */
	private function vigilante(): StockWatcher {
		$db = new class( $this->esperando ) extends \wpdb {
			/** @param array<int, int> $esperando Mapa producto → cuántos esperan. */
			public function __construct( private array $esperando ) {} // phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- documentado arriba.

			/**
			 * @param string $query SQL ya preparada por `prepare()`.
			 * @return int Cuántos esperan ese producto.
			 */
			public function get_var( string $query ): int {
				preg_match( '/product_id = (\d+)/', $query, $m );

				return (int) ( $this->esperando[ (int) ( $m[1] ?? 0 ) ] ?? 0 );
			}

			/**
			 * @param string $query   Plantilla.
			 * @param mixed  ...$args Valores.
			 * @return string
			 */
			public function prepare( string $query, ...$args ): string {
				foreach ( $args as $valor ) {
					$query = (string) preg_replace( '/%[ds]/', is_int( $valor ) ? (string) $valor : "'" . $valor . "'", $query, 1 );
				}

				return $query;
			}
		};

		return new StockWatcher( new SubscriberRepository( $db, 'wp_akb_back_in_stock' ) );
	}

	/** @testdox Un tomo que vuelve a stock CON gente esperando encola el aviso. */
	public function test_encola_cuando_vuelve_y_hay_quien_espere(): void {
		$this->esperando = array( 700 => 3 );

		$this->vigilante()->on_stock_status_change( 700, 'instock' );

		self::assertCount( 1, $this->encolado );
		self::assertSame( StockWatcher::ASYNC_HOOK, $this->encolado[0][0] );
		self::assertSame( array( 'product_id' => 700 ), $this->encolado[0][1], 'El id encolado es EXACTAMENTE el que transicionó, variación incluida.' );
		self::assertSame( StockWatcher::AS_GROUP, $this->encolado[0][2] );
	}

	/**
	 * @testdox Un tomo agotado que pasa a reservable (A pedido / Preventa) encola el aviso por defecto.
	 */
	public function test_encola_cuando_pasa_a_reservable_con_el_ajuste_encendido(): void {
		$this->esperando = array( 700 => 3 );

		$this->vigilante()->on_stock_status_change( 700, 'onbackorder' );

		self::assertCount( 1, $this->encolado );
		self::assertSame( StockWatcher::ASYNC_HOOK, $this->encolado[0][0] );
		self::assertSame( array( 'product_id' => 700 ), $this->encolado[0][1] );
		self::assertSame( StockWatcher::AS_GROUP, $this->encolado[0][2] );
	}

	/**
	 * @testdox Si el dueño apaga el aviso en reservables, pasar a onbackorder no encola nada.
	 */
	public function test_no_encola_cuando_pasa_a_reservable_si_el_ajuste_esta_apagado(): void {
		$this->esperando = array( 700 => 3 );
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => 'akb_bis_notify_on_backorder' === $key ? 'no' : $default
		);

		$this->vigilante()->on_stock_status_change( 700, 'onbackorder' );

		self::assertSame( array(), $this->encolado );
	}

	/**
	 * @testdox Ningún otro estado dispara el aviso.
	 * @dataProvider estadosQueNoSonLlegada
	 */
	public function test_los_demas_estados_no_avisan( string $estado ): void {
		$this->esperando = array( 700 => 3 );

		$this->vigilante()->on_stock_status_change( 700, $estado );

		self::assertSame( array(), $this->encolado, 'Agotado o estados desconocidos nunca disparan el aviso.' );
	}

	/** @return array<string, array{0:string}> */
	public static function estadosQueNoSonLlegada(): array {
		return array(
			'agotado'   => array( 'outofstock' ),
			'inventado' => array( 'lo-que-sea' ),
			'vacío'     => array( '' ),
		);
	}

	/** @testdox Sin nadie esperando no se encola nada: es el no-op barato. */
	public function test_sin_suscriptores_no_encola(): void {
		$this->esperando = array( 700 => 0 );

		$this->vigilante()->on_stock_status_change( 700, 'instock' );

		self::assertSame( array(), $this->encolado );
	}

	/** @testdox Un id inválido no llega ni a preguntar por suscriptores. */
	public function test_un_id_invalido_se_descarta(): void {
		$this->esperando = array( 0 => 5 );

		$this->vigilante()->on_stock_status_change( 0, 'instock' );
		$this->vigilante()->on_stock_status_change( -3, 'instock' );

		self::assertSame( array(), $this->encolado );
	}

	/** @testdox Si ya hay un job pendiente para ESE producto, no se encola otro. */
	public function test_no_duplica_el_job_del_mismo_producto(): void {
		$this->esperando = array( 700 => 3 );
		Functions\when( 'as_has_scheduled_action' )->alias(
			static fn( string $hook, $args = null, string $grupo = '' ): bool => is_array( $args ) && 700 === ( $args['product_id'] ?? 0 )
		);

		$this->vigilante()->on_stock_status_change( 700, 'instock' );

		self::assertSame( array(), $this->encolado, 'El dedup mira los ARGUMENTOS: con `$unique` un restock masivo descartaría B y C en silencio.' );
	}

	/** @testdox Sin Action Scheduler el aviso no se pierde: sale al cerrar la petición. */
	public function test_sin_cola_se_despacha_al_final(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/BackInStock/StockWatcher.php' );

		self::assertStringContainsString( "'shutdown',", $fuente, 'El respaldo sin Action Scheduler tiene que seguir existiendo.' );
		self::assertStringContainsString( 'do_action( self::ASYNC_HOOK, $product_id )', $fuente );
	}

	/** @testdox Se engancha DESPUÉS del oyente de preventa, para que el orden sea siempre el mismo. */
	public function test_el_orden_del_hook_es_determinista(): void {
		$vigilante = $this->vigilante();

		$vigilante->register();

		self::assertSame( 11, Actions\has( 'woocommerce_product_set_stock_status', array( $vigilante, 'on_stock_status_change' ) ) );
		self::assertSame( 11, Actions\has( 'woocommerce_variation_set_stock_status', array( $vigilante, 'on_stock_status_change' ) ) );
	}
}
}
