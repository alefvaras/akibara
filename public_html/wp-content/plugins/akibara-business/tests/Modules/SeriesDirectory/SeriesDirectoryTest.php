<?php
/**
 * Pruebas del bootstrap y los contratos editoriales del directorio de series.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesDirectory;

use Akibara\Business\Modules\SeriesDirectory\SeriesDirectory;
use PHPUnit\Framework\TestCase;

final class SeriesDirectoryTest extends TestCase {

	/** @testdox Should keep the skewed native page title inside the viewport. */
	public function test_directory_asset_contains_the_skewed_page_title(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/series-directory.css' );

		self::assertStringContainsString( 'h1.entry-title', $css );
		self::assertStringContainsString( 'width: fit-content;', $css );
		self::assertStringContainsString( 'max-width: 100%;', $css );
	}

	/**
	 * La reserva SEO debe explicar la doble puerta «serie o edición» y mantener
	 * los tomos acotados al catálogo, sin presentarlos como total editorial.
	 *
	 * @testdox Should describe series and editions while scoping volumes to the catalog.
	 */
	public function test_default_description_explains_series_editions_and_catalog_tomos(): void {
		self::assertSame(
			'Directorio alfabético de series de manga y cómics en Akibara. Busca una serie o edición y revisa sus tomos en catálogo.',
			SeriesDirectory::DEFAULT_DESCRIPTION
		);
		self::assertStringNotContainsString( 'tomos publicados', SeriesDirectory::DEFAULT_DESCRIPTION );
	}

	/**
	 * El contador vivo NO puede tener su propia copia del texto.
	 *
	 * La tenía: el JS reconstruía la frase entera y la escribía en cada carga,
	 * así que la versión del servidor —la única que agrupa los miles con el
	 * locale del sitio— no llegaba a verse nunca, y la unidad editorial vivía
	 * duplicada en dos lenguajes. Ahora las dos plantillas viajan en data-attrs
	 * y el JS sólo sustituye el número.
	 *
	 * @testdox Should keep the live count copy in PHP and leave only the number to the client.
	 */
	public function test_client_count_uses_the_edition_unit(): void {
		$javascript = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/series-directory.js' );

		self::assertStringNotContainsString( "? 'edición' : 'ediciones'", $javascript );
		self::assertStringNotContainsString( "? 'serie' : 'series'", $javascript );
		self::assertStringNotContainsString( 'con tomos en catálogo', $javascript );
		self::assertStringContainsString( "getAttribute( 'data-akb-series-count-all' )", $javascript );
		self::assertStringContainsString( "getAttribute( 'data-akb-series-count-filtered' )", $javascript );
	}
}
