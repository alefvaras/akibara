<?php
/**
 * El carrito y los pedidos no se cachean, aunque la casilla diga lo contrario.
 *
 * LiteSpeed corre en producción con `cache-rest = 1` y `cache-priv = 1`: cachea
 * la REST API, por sesión, media hora. Y `cache-exc` está vacío. La única
 * exclusión configurada (`cache-excludes_qs`) cubre el carrito VIEJO de
 * WooCommerce (`wc-ajax`, `add-to-cart`) y no la Store API
 * `/wp-json/wc/store/v1/*`, que es la que usan el carrito y el checkout de
 * bloques — o sea, la tienda de hoy.
 *
 * Medido por QA el 2026-09-06 con sesión iniciada: quitó tres artículos del
 * carrito, releyó el endpoint y **seguían los cuatro**, cuatro veces seguidas,
 * durante más de cinco minutos. La respuesta llegaba con
 * `x-litespeed-cache: hit,private` y su propio `Cache-Control` decía
 * `no-store, private`. LiteSpeed estaba guardando una respuesta que pide
 * explícitamente no ser guardada. Al visitante anónimo no le ocurre.
 *
 * Se arregla aquí y no en la interfaz de LiteSpeed por la misma razón que las
 * otras barandas de este fichero: la casilla es un estado, no una garantía —
 * vuelve con un clic desde el panel o desde cualquier «optimizar todo» del
 * hosting.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Brain\Monkey;
use Brain\Monkey\Filters;
use PHPUnit\Framework\TestCase;

final class LitespeedCartExclusionTest extends TestCase {

	/** Las rutas de comercio que nunca pueden servirse desde caché. */
	private const RUTAS = array( '/wp-json/wc/store/', '/wp-json/wc/v3/' );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// El fichero registra sus filtros al cargarse; la guarda evita
		// redeclarar la función cuando otro test de la suite ya lo cargó.
		if ( ! function_exists( 'akibara_litespeed_rutas_sin_cache' ) ) {
			require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-litespeed-guardrails.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Aplica el filtro del mu-plugin sobre un valor dado. */
	private function excluidas( $valor_guardado ) {
		$fichero = dirname( __DIR__, 4 ) . '/mu-plugins/akibara-litespeed-guardrails.php';
		$fuente  = (string) file_get_contents( $fichero );

		self::assertStringContainsString(
			"litespeed_conf_load_option_cache-exc",
			$fuente,
			'La exclusión tiene que fijarse por código: la casilla del panel vuelve con un clic.'
		);

		// El filtro se registra al cargar el fichero; se invoca la lógica
		// directamente para no depender del orden de carga de la suite.
		return \akibara_litespeed_rutas_sin_cache( $valor_guardado );
	}

	/**
	 * @testdox Las rutas del carrito y de pedidos quedan excluidas de la caché.
	 */
	public function test_the_commerce_routes_are_excluded(): void {
		$resultado = $this->excluidas( array() );

		foreach ( self::RUTAS as $ruta ) {
			self::assertContains(
				$ruta,
				$resultado,
				'Sin esta exclusión, el cliente lee su carrito desde una caché de media hora.'
			);
		}
	}

	/**
	 * La lista es del sitio, no nuestra: si alguien añade una exclusión desde el
	 * panel, no se la podemos borrar.
	 *
	 * @testdox Se AÑADE a lo que ya hubiera configurado, sin pisarlo.
	 */
	public function test_it_adds_without_replacing(): void {
		$resultado = $this->excluidas( array( '/algo-que-puso-otro/', '/wp-json/wc/store/' ) );

		self::assertContains( '/algo-que-puso-otro/', $resultado, 'Lo que configuró otro se respeta.' );
		self::assertContains( '/wp-json/wc/v3/', $resultado, 'Y lo nuestro se añade.' );
	}

	/**
	 * @testdox Una ruta ya presente no se duplica.
	 */
	public function test_it_does_not_duplicate(): void {
		$resultado = $this->excluidas( self::RUTAS );

		self::assertSame(
			count( self::RUTAS ),
			count( array_unique( $resultado ) ),
			'Duplicar una exclusión no rompe nada, pero ensucia la configuración que otro va a leer.'
		);
		self::assertCount( count( self::RUTAS ), $resultado );
	}

	/**
	 * LiteSpeed guarda esta option como array, pero una instalación a medio
	 * migrar puede traer otra cosa. No se puede fallar aquí: si fallamos, el
	 * carrito se cachea.
	 *
	 * @testdox Un valor guardado con forma inesperada no deja el carrito sin proteger.
	 */
	public function test_a_broken_stored_value_still_protects_the_cart(): void {
		foreach ( array( null, false, '', 'una cadena', 42 ) as $raro ) {
			$resultado = $this->excluidas( $raro );

			self::assertIsArray( $resultado );
			foreach ( self::RUTAS as $ruta ) {
				self::assertContains( $ruta, $resultado, 'Pase lo que pase con el valor guardado, estas rutas se excluyen.' );
			}
		}
	}
}
