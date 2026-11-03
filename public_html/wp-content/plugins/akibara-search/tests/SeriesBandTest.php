<?php
/**
 * Banda de serie sobre los resultados del catálogo.
 *
 * Fija los tres contratos que hacen que esto no sea ruido:
 *
 *   1. Sólo match FUERTE. `suggest()` ordena por tier pero no lo devuelve, así
 *      que la banda recalcula: tier 0 (nombre idéntico) o 1 (empieza por la
 *      consulta). El tier 2 —prefijo de palabra interior— pintaría «Chainsaw
 *      Man» al buscar «man», sobre resultados que no son de esa serie.
 *   2. Prioridad 3 en `woocommerce_before_shop_loop`: bajo el H1 y ENCIMA de la
 *      barra de herramientas, que abre en 4. En 4 o más caería dentro de la barra.
 *   3. El CSS existe en las DOS hojas. Producción sirve `.min` (SCRIPT_DEBUG
 *      apagado en prod, ver facets-render.php), así que tocar sólo la fuente
 *      deja el cambio invisible para todo el tráfico real.
 *
 * @package Akibara\Search\Tests
 */

declare(strict_types=1);

namespace Akibara\Search\Tests;

use PHPUnit\Framework\TestCase;

final class SeriesBandTest extends TestCase {

	private function render(): string {
		$s = file_get_contents( dirname( __DIR__ ) . '/frontend/facets-render.php' );
		$this->assertIsString( $s );

		return $s;
	}

	/**
	 * Código sin comentarios.
	 *
	 * Imprescindible para las aserciones NEGATIVAS: el docblock de la banda
	 * EXPLICA por qué no usa SeriesFacts, así que una comprobación sobre el
	 * fichero crudo se pone roja por el propio texto que documenta la decisión.
	 * Es la tercera vez en el día que esta trampa muerde.
	 */
	private function codigo(): string {
		return (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $this->render() );
	}

	private function css( string $fichero ): string {
		$s = file_get_contents( dirname( __DIR__ ) . '/assets/css/' . $fichero );
		$this->assertIsString( $s, "No se pudo leer {$fichero}" );

		return $s;
	}

	public function test_se_engancha_encima_de_la_barra_de_herramientas(): void {
		$s = $this->render();

		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'woocommerce_before_shop_loop',\s*'akibara_search_render_series_band',\s*3\s*\)/",
			$s,
			'La barra de herramientas abre en prioridad 4: en 4 o más la banda cae dentro.'
		);
	}

	/**
	 * @testdox Descarta el tier 2, que es el que produce ruido.
	 */
	public function test_solo_match_fuerte(): void {
		$s = $this->render();

		// El corte: idéntico (con o sin espacios) o prefijo. Nada más.
		$this->assertStringContainsString( "\$exacto = \$name === \$norm", $s );
		$this->assertStringContainsString( "\$sin_espacios( \$name ) === \$sin_espacios( \$norm )", $s );
		$this->assertStringContainsString( "\$prefijo = 0 === strpos( \$name, \$norm )", $s );
		$this->assertStringContainsString( "if ( ! \$exacto && ! \$prefijo ) {", $s );

		// El colapso se compara por IGUALDAD. Con prefijo, «man» volvería a
		// pintar «Chainsaw Man».
		$this->assertStringNotContainsString( "strpos( \$sin_espacios(", $this->codigo() );

		// Un prefijo corto no afirma la serie: «one» pintaba «One Piece» sobre
		// resultados mezclados. El umbral NO aplica al nombre exacto —«Nana»
		// tiene 4 letras y es la serie.
		$this->assertStringContainsString( "if ( ! \$exacto && strlen( \$norm ) < 5 ) {", $s );
	}

	/**
	 * @testdox Usa la normalización del propio plugin, no una copia.
	 */
	public function test_normaliza_con_la_clase_del_plugin(): void {
		$s = $this->render();

		$this->assertStringContainsString( 'Normalizer::normalize(', $s );
		$this->assertStringContainsString( 'SeriesSuggestions::class', $s );
	}

	/**
	 * @testdox No inventa conteos en vivo: sólo termmeta.
	 *
	 * Los conteos vivos («13 disponibles hoy») saldrían de SeriesFacts, que vive
	 * en akibara-business. Traerlos aquí acopla los dos plugins —el par caído ya
	 * provocó un 500 global— y abre la puerta a que la banda contradiga a la
	 * landing. El termmeta es la misma fuente en las tres superficies.
	 */
	public function test_no_depende_del_otro_plugin(): void {
		$s = $this->render();

		$this->assertStringNotContainsString( 'SeriesFacts', $this->codigo() );
		$this->assertStringContainsString( '_akb_serie_estado_edicion', $s );
		$this->assertStringContainsString( '_akb_serie_tomos_edicion', $s );
	}

	/**
	 * @testdox El estilo está en la hoja fuente Y en la minificada.
	 */
	public function test_el_css_llega_a_produccion(): void {
		foreach ( array( 'akb-search.css', 'akb-search.min.css' ) as $hoja ) {
			$css = $this->css( $hoja );

			foreach ( array( '.akb-series-band', '.akb-series-band__name', '.akb-series-band__link' ) as $sel ) {
				$this->assertStringContainsString(
					$sel,
					$css,
					"Falta {$sel} en {$hoja}. Producción sirve la minificada."
				);
			}
		}
	}

	/**
	 * @testdox El enlace no promete un orden que el selector de al lado ya da.
	 *
	 * La toolbar ofrece «Título: A-Z» dos líneas más abajo, y con tomos
	 * numerados eso ES el orden 1→N. Prometerlo en el enlace competía con ese
	 * control sin añadir motivo para pulsar.
	 */
	public function test_el_enlace_no_promete_ordenar(): void {
		$s = $this->render();

		$this->assertStringContainsString( "esc_html( 'Ver la serie' )", $s );
		$this->assertStringNotContainsString( 'del 1 al final', $this->codigo() );
		$this->assertStringNotContainsString( 'ordenada', $this->codigo() );
	}

	/**
	 * @testdox La banda es UNA línea, no tres apiladas.
	 *
	 * La versión apilada medía 115px y empujaba la primera card de 301px a 504px
	 * en móvil, sobre /tienda/ — la página con más abandono absoluto después de
	 * la home. Con `display:flex` en una fila baja a ~48px sin perder el bloque
	 * ni el filete, que es lo que la hace escaneable.
	 */
	public function test_la_banda_es_una_sola_linea(): void {
		$css = $this->css( 'akb-search.css' );
		$ini = strpos( $css, '.akb-series-band {' );
		$this->assertNotFalse( $ini );

		$bloque = substr( $css, $ini, 400 );
		$this->assertMatchesRegularExpression( '/display:\s*flex/', $bloque );
		$this->assertMatchesRegularExpression( '/align-items:\s*baseline/', $bloque );

		// Los hijos son <span> en línea, no <p> en bloque.
		$render = $this->render();
		$this->assertStringContainsString( '<span class="akb-series-band__name">', $render );
		$this->assertStringNotContainsString( '<p class="akb-series-band__name">', $render );
	}

	/**
	 * @testdox El enlace de la banda no usa el rojo de comprar.
	 *
	 * `loop.css` deja escrito que el rojo sólido queda reservado al CTA. La banda
	 * vive a pocos píxeles de los botones de cada card.
	 */
	public function test_no_compite_con_el_boton_de_comprar(): void {
		$css   = $this->css( 'akb-search.css' );
		$ini   = strpos( $css, '.akb-series-band' );
		$bloque = false === $ini ? '' : substr( $css, $ini );

		$this->assertNotSame( '', $bloque );
		$this->assertDoesNotMatchRegularExpression(
			'/\bcolor:\s*var\(\s*--aki-red/',
			$bloque,
			'El rojo sólido es del botón de comprar.'
		);
	}
}
