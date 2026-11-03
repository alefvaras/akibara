<?php
/**
 * Pruebas de la medición y el corte de texto de los límites SEO.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Utilities\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase {

	/**
	 * Los límites de SEO se cuentan en CARACTERES, no en bytes: un título con
	 * tildes y «ñ» ocupa más bytes y `strlen()` lo rechazaría por largo sin que
	 * nada lo dijera.
	 *
	 * @testdox Should count characters, not bytes.
	 */
	public function test_cuenta_caracteres_y_no_bytes(): void {
		$titulo = 'El Verano que Hikaru Murió | Akibara Chile';

		self::assertSame( 42, Text::length( $titulo ) );
		self::assertSame( 43, strlen( $titulo ), 'La «ó» ocupa dos bytes: por eso strlen() no sirve para el límite.' );
	}

	/** @testdox Should cut on character boundaries, never mid-codepoint. */
	public function test_corta_por_caracteres(): void {
		self::assertSame( 'Murió', Text::substr( 'El Verano que Hikaru Murió', 21, 5 ) );
		self::assertSame( '', Text::substr( '', 0, 5 ) );
	}

	/**
	 * `would_clip()` tiene que medir sobre el largo SIN truncar. Medir sobre
	 * lo que `one_line()` ya devolvió es la trampa: ese resultado viene
	 * cortado a `ONE_LINE_MAX`, así que comparado contra un tope de 300 jamás
	 * da «se pasó» — daría lo mismo que el original midiera 301 caracteres o
	 * 3.000. Esto tumbó en silencio el aviso de recorte de `CopyManager`
	 * hasta el 2026-09-19 (R2-0a2ff112): NINGÚN campo con tope de 300 —ni
	 * siquiera los dos de `PickupCoordination` que la lista corta SÍ cubría—
	 * podía disparar el aviso, porque el propio corte se comía la prueba de
	 * que hubo corte.
	 *
	 * @testdox Should detect a clip against the untruncated length, not against one_line()'s own already-clipped output.
	 */
	public function test_would_clip_mide_antes_de_truncar(): void {
		$largo = str_repeat( 'x', 500 );

		self::assertTrue( Text::would_clip( $largo, Text::ONE_LINE_MAX ), 'Medir sobre one_line() ya cortado nunca detecta esto.' );
		self::assertSame( Text::ONE_LINE_MAX, Text::length( Text::one_line( $largo, '' ) ), 'Fixture: one_line() sí corta a 300 — por eso medir sobre su salida es la trampa.' );

		self::assertFalse( Text::would_clip( 'cabe entero', Text::ONE_LINE_MAX ) );

		// El tope de una palabra de estado (24) sí se detectaba antes, porque
		// 300 (lo que one_line() deja pasar) sigue siendo mayor que 24 — el
		// bug era invisible ahí y sólo se veía en un tope igual al de one_line().
		self::assertTrue( Text::would_clip( $largo, 24 ) );
		self::assertFalse( Text::would_clip( 'diez letras', 24 ) );
	}

	/**
	 * INVARIANTE: una sola implementación. Estaba copiada en los dos módulos que
	 * recortan meta, y dos criterios de corte son dos sitios donde el mismo
	 * título puede caber o no caber.
	 *
	 * @testdox Should keep a single implementation of the character-length rule.
	 */
	public function test_no_hay_copias_privadas_de_la_regla(): void {
		$raiz     = dirname( __DIR__, 2 ) . '/src';
		$copias   = array();
		$ficheros = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz ) );

		foreach ( $ficheros as $fichero ) {
			if ( ! $fichero->isFile() || 'php' !== $fichero->getExtension() ) {
				continue;
			}
			$relativa = str_replace( $raiz . '/', '', (string) $fichero->getPathname() );
			if ( 'Utilities/Text.php' === $relativa ) {
				continue;
			}
			$contenido = (string) file_get_contents( (string) $fichero->getPathname() );
			if ( 1 === preg_match( '#function\s+text_(length|substr)\s*\(#', $contenido ) ) {
				$copias[] = $relativa;
			}
		}

		self::assertSame( array(), $copias );
	}
}
