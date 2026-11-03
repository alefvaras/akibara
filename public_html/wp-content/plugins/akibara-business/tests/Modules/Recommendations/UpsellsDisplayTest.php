<?php
/**
 * «También te recomendamos…» en la ficha: orden de lectura, comprables primero
 * y la regla de la serie agotada (UC-7). La lista GUARDADA no se toca: el
 * filtro sólo actúa mientras se pinta la ficha.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Recommendations;

use Akibara\Business\Modules\Recommendations\Settings;
use Akibara\Business\Modules\Recommendations\UpsellsDisplay;
use Akibara\Business\Tests\Support\CatalogoDeRecomendaciones;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class UpsellsDisplayTest extends TestCase {

	private const JJK = 7;

	private const FICHA = 24;

	private CatalogoDeRecomendaciones $catalogo;

	private bool $pintando = true;

	private string $modo = Settings::AGOTADA_MOSTRAR;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->catalogo = new CatalogoDeRecomendaciones();
		$this->catalogo->agregar( self::FICHA, 'Jujutsu Kaisen 24', self::JJK );
		$this->catalogo->agregar( 25, 'Jujutsu Kaisen 25', self::JJK, false );
		$this->catalogo->agregar( 26, 'Jujutsu Kaisen 26', self::JJK, false );
		$this->catalogo->agregar( 27, 'Jujutsu Kaisen 27', self::JJK, false );
		$this->catalogo->agregar( 28, 'Jujutsu Kaisen 28', self::JJK, false );
		$this->catalogo->agregar( 29, 'Jujutsu Kaisen 29', self::JJK );
		$this->catalogo->agregar( 30, 'Jujutsu Kaisen 30', self::JJK );
		$this->catalogo->instalar();

		Functions\when( 'doing_action' )->alias( fn( string $hook ) => $this->pintando && 'woocommerce_after_single_product_summary' === $hook );
		Functions\when( 'get_queried_object_id' )->justReturn( self::FICHA );
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => Settings::OPTION_SERIE_AGOTADA === $name ? $this->modo : $default
		);
	}

	protected function tearDown(): void {
		$this->catalogo->desinstalar();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function ficha( int $id = self::FICHA ): \WC_Product {
		$producto         = new \WC_Product();
		$producto->akb_id = $id;
		return $producto;
	}

	/**
	 * @testdox Lo que WooCommerce baraja («28, 26, 27») sale en orden de lectura.
	 */
	public function test_ordena_en_orden_de_lectura(): void {
		self::assertSame( array( 26, 27, 28 ), ( new UpsellsDisplay() )->arrange( array( 28, 26, 27 ), $this->ficha() ) );
	}

	/**
	 * @testdox Los que se pueden comprar van primero, cada grupo en orden de lectura.
	 */
	public function test_comprables_primero(): void {
		self::assertSame( array( 29, 30, 25 ), ( new UpsellsDisplay() )->arrange( array( 30, 25, 29 ), $this->ficha() ) );
	}

	/**
	 * @testdox Fuera del render de la ficha (editor, API, relleno diario) la lista guardada sale intacta.
	 */
	public function test_fuera_de_la_ficha_no_toca_la_lista(): void {
		$this->pintando = false;

		self::assertSame( array( 28, 26, 27 ), ( new UpsellsDisplay() )->arrange( array( 28, 26, 27 ), $this->ficha() ) );
	}

	/**
	 * @testdox Los upsells de OTRO producto pintado en la ficha no se tocan.
	 */
	public function test_otro_producto_no_se_toca(): void {
		self::assertSame( array( 28, 26 ), ( new UpsellsDisplay() )->arrange( array( 28, 26 ), $this->ficha( 99 ) ) );
	}

	/**
	 * @testdox Todos agotados y «completar»: entran los siguientes comprables de la serie, con UNA consulta de tomos.
	 */
	public function test_completar_con_los_siguientes_comprables(): void {
		$this->modo = Settings::AGOTADA_COMPLETAR;

		$lista = ( new UpsellsDisplay() )->arrange( array( 27, 25, 26 ), $this->ficha() );

		self::assertSame( array( 29, 30, 25 ), $lista );
		self::assertSame( 1, $this->catalogo->consultas_de_tomos );
	}

	/**
	 * @testdox Todos agotados y «ocultar»: el bloque se queda sin tomos (WooCommerce no lo pinta).
	 */
	public function test_ocultar(): void {
		$this->modo = Settings::AGOTADA_OCULTAR;

		self::assertSame( array(), ( new UpsellsDisplay() )->arrange( array( 27, 25, 26 ), $this->ficha() ) );
	}

	/**
	 * @testdox La segunda lectura del mismo render (la exclusión de los relacionados) no vuelve a calcular.
	 */
	public function test_memo_por_render(): void {
		$this->modo = Settings::AGOTADA_COMPLETAR;
		$display    = new UpsellsDisplay();

		$primera = $display->arrange( array( 27, 25, 26 ), $this->ficha() );
		$lotes   = count( $this->catalogo->primados );
		$segunda = $display->arrange( array( 27, 25, 26 ), $this->ficha() );

		self::assertSame( $primera, $segunda );
		self::assertSame( $lotes, count( $this->catalogo->primados ) );
		self::assertSame( 1, $this->catalogo->consultas_de_tomos );
	}

	/**
	 * @testdox WooCommerce respeta la lista: `none` + `asc` (con `desc` la invertiría).
	 */
	public function test_woocommerce_conserva_el_orden(): void {
		self::assertSame( 'none', UpsellsDisplay::keep_order() );
		self::assertSame( 'asc', UpsellsDisplay::ascending() );
	}
}
