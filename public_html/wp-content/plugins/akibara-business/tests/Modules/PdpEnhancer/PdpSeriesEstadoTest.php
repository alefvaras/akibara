<?php
/**
 * Estado de la edición en el enlace a la serie de la ficha (release 1.108.37).
 *
 * El contrato que importa: esta frase y la de la landing de serie tienen que
 * decir LO MISMO. El comentario de `render_series_nav()` dejó escrito por qué
 * no se puso un número aquí antes — «un número que se contradice a un click de
 * distancia es peor que ningún número» —, y la única forma de cumplirlo es que
 * las dos superficies compongan la frase con la misma clase.
 *
 * @package Akibara\Business\Tests
 */

declare(strict_types=1);

namespace Akibara\Business\Tests\Modules\PdpEnhancer;

use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PdpSeriesEstadoTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// `complete_total_detail()` formatea el total con la función de WordPress.
		// El doble replica su comportamiento real —separador de miles— en vez de
		// devolver el entero pelado: un doble que no modela producción esconde
		// justo la clase de fallo que estas pruebas buscan.
		Functions\when( 'number_format_i18n' )->alias(
			static fn( $n, $decimals = 0 ): string => number_format( (float) $n, (int) $decimals, ',', '.' )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fuente(): string {
		$s = file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/PdpEnhancer/PdpSeriesEnhancer.php'
		);
		$this->assertIsString( $s );

		return $s;
	}

	/**
	 * Código sin comentarios. Imprescindible: el docblock del método EXPLICA que
	 * 'en_curso' no existe, así que una aserción sobre el fichero crudo se pone
	 * roja por el propio texto que documenta el arreglo.
	 */
	private function codigo(): string {
		return (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $this->fuente() );
	}

	/**
	 * @testdox La frase se compone con PublicationStatus, no con un mapeo propio.
	 */
	public function test_reutiliza_la_clase_canonica(): void {
		$f = $this->fuente();

		$this->assertStringContainsString( 'PublicationStatus::edition_phrase(', $f );
		$this->assertStringContainsString( 'PublicationStatus::scoped_for_term(', $f );

		// Un switch propio sobre estados es exactamente lo que hace divergir las
		// dos redacciones en cuanto alguien añade un estado.
		$this->assertDoesNotMatchRegularExpression(
			"/case\s+'(completa|en_publicacion|al_dia|en_pausa|cancelada)'/",
			$f,
			'No dupliques el mapeo de estados: úsalo desde PublicationStatus.'
		);
	}

	/**
	 * @testdox No se inventan estados que no existen en producción.
	 *
	 * La primera versión de este método usó 'en_curso' y 'pausada'. Ninguno
	 * existe: medido en prod, los valores de `_akb_serie_estado_edicion` son
	 * 'completa' (55 series), 'en_publicacion' (46) y 'al_dia' (3).
	 */
	public function test_no_inventa_estados(): void {
		$f = $this->fuente();

		foreach ( array( 'en_curso', 'en-curso', 'pausada' ) as $inventado ) {
			$this->assertStringNotContainsString(
				"'" . $inventado . "'",
				$this->codigo(),
				"El estado «{$inventado}» no existe en producción."
			);
		}
	}

	/**
	 * @testdox «Completa» conserva el titular: nunca queda en «en 30 tomos».
	 *
	 * `edition_phrase('completa', 30)` devuelve titular «Completa» y detalle
	 * «en 30 tomos» — el detalle YA trae la preposición. Devolver sólo el
	 * detalle dejaba la ficha diciendo «en 30 tomos», que no es una frase.
	 */
	public function test_la_frase_de_completa_no_pierde_el_titular(): void {
		$phrase = PublicationStatus::edition_phrase( PublicationStatus::COMPLETE, 30 );

		$this->assertSame( 'Completa', $phrase['titular'] );
		$this->assertSame( 'en 30 tomos', $phrase['detalle'] );

		// El código debe unirlos con espacio, no devolver sólo el detalle.
		$this->assertStringContainsString(
			"? \$titular . ' ' . \$detalle",
			$this->codigo(),
			'«Completa» se une con espacio para formar «Completa en 30 tomos».'
		);
	}

	/**
	 * @testdox Sin dato no se pinta nada, que es el caso mayoritario.
	 *
	 * Sólo 67 de las series tienen `_akb_serie_tomos_edicion` relleno. Media
	 * frase en las otras sería peor que el silencio.
	 */
	public function test_sin_dato_devuelve_vacio(): void {
		$this->assertSame( array(), PublicationStatus::edition_phrase( '', 0 ) );
		$this->assertSame( array(), PublicationStatus::edition_phrase( 'inventado', 12 ) );

		$f = $this->fuente();
		$this->assertStringContainsString( "if ( array() === \$phrase ) {", $f );
	}

	/**
	 * @testdox El estado no se pinta si el enlace a la serie no existe.
	 */
	public function test_el_estado_cuelga_del_enlace(): void {
		$f = $this->fuente();

		$this->assertMatchesRegularExpression(
			"/if \( '' !== \( \\\$series_link\['estado'\] \?\? '' \) \)/",
			$f,
			'El estado sólo se emite cuando hay frase.'
		);
	}
}
