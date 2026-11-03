<?php
/**
 * Contrato ejecutable de «Saltar a los filtros» (`inc/flyout-button.php`).
 *
 * QF-gp-R3: en una búsqueda sin resultados y sin filtros marcados el buscador
 * imprime el formulario de la barra igual (para conservar `_buscar`), pero sin
 * ningún grupo: 0 px de alto y ningún control. El enlace llevaba ahí y el Tab
 * siguiente caía en el logo del pie. Sólo se imprime si la barra va a tener
 * algún filtro.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SkipToFiltersTest extends TestCase {

	private const ENLACE = '<a class="akb-skip-filtros" href="#akb-facets-drawer">Saltar a los filtros</a>';

	/**
	 * Lo que devuelve el view-model del buscador en esta prueba.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $view = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_woocommerce' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'akibara_search_facets_active' )->justReturn( true );
		Functions\when( 'akibara_search_facets_view' )->alias( fn() => $this->view );

		require_once dirname( __DIR__ ) . '/inc/flyout-button.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * View-model como lo arma `FacetsView::build()` del buscador.
	 *
	 * @param array<int, array<string, mixed>> $facets Grupos que pinta la barra.
	 * @param string                           $search Búsqueda activa.
	 * @return array<string, mixed>
	 */
	private function view( array $facets, string $search = '' ): array {
		return array(
			'total'       => 0,
			'facets'      => $facets,
			'chips'       => '' === $search ? array() : array( array( 'facet' => 'buscar' ) ),
			'has_filters' => '' !== $search,
			'search'      => $search,
		);
	}

	private function salida(): string {
		ob_start();
		akibara_gp_render_skip_to_filters();
		return (string) ob_get_clean();
	}

	/**
	 * @testdox Una búsqueda sin resultados y sin filtros que mostrar no imprime «Saltar a los filtros».
	 */
	public function test_busqueda_vacia_sin_facetas_no_lleva_el_salto(): void {
		$this->view = $this->view( array(), 'zzqqxxnoexiste' );

		self::assertSame( '', $this->salida(), 'el formulario sale vacío (0 px, ningún control): el salto no llevaba a nada' );
		self::assertFalse( akibara_gp_facets_sidebar_has_filters() );
	}

	/**
	 * @testdox Sin view-model (buscador caído) no hay formulario al que saltar.
	 */
	public function test_sin_view_model_no_lleva_el_salto(): void {
		$this->view = null;

		self::assertSame( '', $this->salida(), 'sin view-model el buscador no imprime #akb-facets-drawer' );
	}

	/**
	 * @testdox Con algún grupo en la barra el salto se imprime, con o sin resultados.
	 */
	public function test_con_facetas_lleva_el_salto(): void {
		$this->view = $this->view( array( array( 'name' => 'disponibilidad' ) ) );
		self::assertSame( self::ENLACE, $this->salida(), 'catálogo con filtros' );

		// Cero resultados con filtros marcados: la barra conserva los marcados para poder quitarlos.
		$this->view = $this->view( array( array( 'name' => 'disponibilidad' ), array( 'name' => 'precio' ) ), 'zzqq' );
		self::assertSame( self::ENLACE, $this->salida(), 'sin resultados pero con filtros que tocar' );
		self::assertTrue( akibara_gp_facets_sidebar_has_filters() );
	}

	/**
	 * @testdox En las landings de serie y autor no hay barra, tenga lo que tenga el view-model.
	 */
	public function test_landing_de_serie_no_lleva_el_salto(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		$this->view = $this->view( array( array( 'name' => 'disponibilidad' ) ) );

		self::assertSame( '', $this->salida() );
	}
}
