<?php
/**
 * Llegar desde un anuncio no puede dejar la página a medias.
 *
 * Hallazgo S-317c0fab: los tres controles de /manga/, de los archives de
 * género/autor y de la canónica usaban `! empty( $_GET )`. Eso trata un
 * `?utm_source=instagram` o un `?fbclid=` —que no cambian ni un producto de la
 * grilla— como si fueran una faceta: quien llegaba desde una campaña veía la
 * página SIN el texto editorial que sí ve quien llega por Google, y sin
 * canónica.
 *
 * La gramática de lo que SÍ es una faceta ya existía (`CatalogQueryGrammar`) y
 * ya se usaba en otros dos módulos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoEnhancements;

use Akibara\Business\Utilities\CatalogQueryGrammar;
use PHPUnit\Framework\TestCase;

final class UnaCampanaNoApagaElTextoTest extends TestCase {

	private function fuente(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/SeoEnhancements/SeoEnhancements.php' );
	}

	/** @testdox Ningún control decide por «hay algo en la query». */
	public function test_ya_no_se_mira_si_la_query_esta_vacia(): void {
		$src = $this->fuente();

		self::assertStringNotContainsString(
			'! empty( $_GET )',
			$src,
			'«Hay algo en la query» mete a las campañas en el mismo saco que a las facetas.'
		);
		self::assertSame(
			4,
			substr_count( $src, 'CatalogQueryGrammar::has_filter_parameter( (array) $_GET )' ),
			'Son cuatro los controles: /manga/, los archives de género/autor, el intro limpio y la canónica.'
		);
	}

	/**
	 * @testdox Los parámetros de campaña NO son facetas.
	 * @dataProvider parametrosDeCampana
	 */
	public function test_una_campana_no_es_una_faceta( string $parametro ): void {
		self::assertFalse(
			CatalogQueryGrammar::has_filter_parameter( array( $parametro => 'x' ) ),
			sprintf( '«%s» no cambia ni un producto de la grilla.', $parametro )
		);
	}

	/** @return array<string, array{0:string}> */
	public static function parametrosDeCampana(): array {
		return array(
			'Google Ads'  => array( 'gclid' ),
			'Meta'        => array( 'fbclid' ),
			'campaña utm' => array( 'utm_source' ),
			'medio utm'   => array( 'utm_medium' ),
			'Mailchimp'   => array( 'mc_cid' ),
		);
	}

	/**
	 * @testdox Las facetas de verdad SÍ apagan el texto, que es para lo que está el control.
	 * @dataProvider facetasDeVerdad
	 */
	public function test_una_faceta_si_lo_es( string $parametro ): void {
		self::assertTrue(
			CatalogQueryGrammar::has_filter_parameter( array( $parametro => 'x' ) ),
			sprintf( '«%s» cambia lo que se ve: ahí el texto canónico sí sobra.', $parametro )
		);
	}

	/** @return array<string, array{0:string}> */
	public static function facetasDeVerdad(): array {
		return array(
			'orden'          => array( 'orderby' ),
			'precio mínimo'  => array( 'min_price' ),
			'faceta propia'  => array( '_disponibilidad' ),
			'búsqueda'       => array( '_buscar' ),
			'filtro de Woo'  => array( 'filter_genero' ),
		);
	}

	/** @testdox El conmutador de ediciones usa la MISMA gramática, no una copia. */
	public function test_el_conmutador_no_tiene_su_propia_lista(): void {
		$callout = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/BrandsDirectory/BrandLandingCallout.php' );

		self::assertStringContainsString( 'CatalogQueryGrammar::has_filter_parameter', $callout );
		self::assertStringNotContainsString(
			"'fbclid', 'gclid'",
			$callout,
			'Una lista propia de parámetros de campaña caduca el día que nazca el siguiente.'
		);
	}
}
