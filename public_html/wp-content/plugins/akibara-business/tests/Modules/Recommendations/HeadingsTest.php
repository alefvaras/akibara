<?php
/**
 * Los títulos de los dos bloques de recomendaciones de la ficha se editan en
 * Textos (revisión B2-R3, UC-7 de la auditoría del tema).
 *
 * Hasta el 2026-09-22 el bloque de upsells de /frieren-4-ivrea-argentina/
 * decía «También te recomendamos…» y el único modo de cambiarlo era código:
 * ni el tema ni este plugin filtraban `woocommerce_product_upsells_products_heading`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Recommendations;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\CopyManager\CopyManager;
use Akibara\Business\Modules\Recommendations\Headings;
use Akibara\Business\Modules\Recommendations\Recommendations;
use Akibara\Business\Utilities\Text;
use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class HeadingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['akibara_test_options'] = array();
		// El selector de la guía de preventas de Textos lista entradas publicadas.
		Functions\when( 'get_posts' )->justReturn( array() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El módulo engancha los títulos de las dos plantillas de WooCommerce.
	 */
	public function test_engancha_los_dos_titulos(): void {
		( new Recommendations() )->register();

		self::assertNotFalse( Filters\has( 'woocommerce_product_upsells_products_heading', array( Headings::class, 'upsells' ) ) );
		self::assertNotFalse( Filters\has( 'woocommerce_product_related_products_heading', array( Headings::class, 'related' ) ) );
	}

	/**
	 * @testdox Los filtros son los que las plantillas de WooCommerce aplican de verdad.
	 */
	public function test_los_filtros_existen_en_las_plantillas(): void {
		$plantillas = dirname( __DIR__, 4 ) . '/woocommerce/templates/single-product/';
		if ( ! is_dir( $plantillas ) ) {
			self::markTestSkipped( 'WooCommerce no está junto a este plugin.' );
		}

		self::assertStringContainsString( "apply_filters( 'woocommerce_product_upsells_products_heading'", (string) file_get_contents( $plantillas . 'up-sells.php' ) );
		self::assertStringContainsString( "apply_filters( 'woocommerce_product_related_products_heading'", (string) file_get_contents( $plantillas . 'related.php' ) );
	}

	/**
	 * @testdox Sin nada guardado, los títulos son EXACTAMENTE los que se veían: el copy no cambia hasta que el dueño lo cambie.
	 */
	public function test_de_fabrica_son_los_de_siempre(): void {
		self::assertSame( 'También te recomendamos…', Headings::upsells() );
		self::assertSame( 'Productos relacionados', Headings::related() );
	}

	/**
	 * @testdox Lo que el dueño escribe es lo que la ficha pinta.
	 */
	public function test_lo_guardado_es_lo_que_se_pinta(): void {
		$GLOBALS['akibara_test_options'] = array(
			Headings::OPTION_UPSELLS => 'Sigue la serie',
			Headings::OPTION_RELATED => 'Empieza otra serie',
		);

		self::assertSame( 'Sigue la serie', Headings::upsells() );
		self::assertSame( 'Empieza otra serie', Headings::related() );
	}

	/**
	 * @testdox Una fila vacía, con sólo etiquetas o que no es texto vuelve al de fábrica: un bloque de tarjetas sin título no es una decisión.
	 * @dataProvider filasRaras
	 *
	 * @param mixed $fila Valor guardado.
	 */
	public function test_fila_rara_vuelve_al_de_fabrica( $fila ): void {
		$GLOBALS['akibara_test_options'] = array(
			Headings::OPTION_UPSELLS => $fila,
			Headings::OPTION_RELATED => $fila,
		);

		self::assertSame( Headings::DEFAULT_UPSELLS, Headings::upsells() );
		self::assertSame( Headings::DEFAULT_RELATED, Headings::related() );
	}

	/** @return array<string, array{0: mixed}> */
	public static function filasRaras(): array {
		return array(
			'vacía'          => array( '' ),
			'espacios'       => array( "  \n\t " ),
			'sólo etiquetas' => array( '<strong></strong>' ),
			'no es texto'    => array( array( 'x' ) ),
			'falso'          => array( false ),
		);
	}

	/**
	 * @testdox El panel los ofrece en Textos, con el de fábrica, y el guardado no es más permisivo que el lector.
	 * @dataProvider entradas
	 *
	 * @param mixed $entrada Lo que llega del formulario.
	 */
	public function test_guardado_y_lectura_dicen_lo_mismo( $entrada ): void {
		$manager = new CopyManager( new AdminPanel() );
		$campos  = array();
		foreach ( $manager->get_settings() as $campo ) {
			if ( isset( $campo['id'] ) ) {
				$campos[ $campo['id'] ] = $campo;
			}
		}

		foreach ( Headings::defaults() as $option => $fabrica ) {
			self::assertArrayHasKey( $option, $campos, $option . ' tiene que estar en Textos.' );
			self::assertSame( 'text', $campos[ $option ]['type'] );
			self::assertSame( $fabrica, $campos[ $option ]['default'] );

			$guardado                        = $manager->sanitize_required_copy( $entrada, array( 'id' => $option ), $entrada );
			$GLOBALS['akibara_test_options'] = array( $option => $guardado );
			$leido                           = Headings::OPTION_UPSELLS === $option ? Headings::upsells() : Headings::related();

			self::assertSame( $guardado, $leido, 'Lo que el panel guarda es exactamente lo que la ficha pinta.' );

			$GLOBALS['akibara_test_options'] = array( $option => $entrada );
			$crudo                           = Headings::OPTION_UPSELLS === $option ? Headings::upsells() : Headings::related();
			self::assertSame( $guardado, $crudo, 'Una fila escrita a mano (wp option update) se lee con la misma regla del guardado.' );
		}
	}

	/** @return array<string, array{0: mixed}> */
	public static function entradas(): array {
		return array(
			'normal'         => array( 'Sigue la serie' ),
			'con etiquetas'  => array( '<b>Sigue</b> la serie' ),
			'dos líneas'     => array( "Sigue\nla serie" ),
			'vacío'          => array( '' ),
			'larguísimo'     => array( str_repeat( 'á', Text::ONE_LINE_MAX + 40 ) ),
		);
	}
}
