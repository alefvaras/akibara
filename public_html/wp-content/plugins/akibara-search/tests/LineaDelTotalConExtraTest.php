<?php
/**
 * La línea del total («30 productos») admite un extra de otro plugin.
 *
 * Nació con la revisión B3-2 de la landing de serie (2026-09-23): el atajo
 * «Último tomo: N →» de akibara-business no cabía en el resumen de la serie sin
 * sumar una línea en 49 de 110 series (y la primera portada quedaba bajo la
 * barra inferior del móvil), mientras que la línea del total ocupa el ancho
 * entero y le sobran ~250 px. Se ofrece como filtro —no como llamada a una
 * clase de business— para que ningún plugin dependa de las clases del otro.
 *
 * Contratos que se fijan:
 *   1. Sin extra, el marcado de siempre, byte a byte: es el target estable del
 *      swap PJAX y lo que el resto del catálogo sigue sirviendo.
 *   2. Con extra, el total va en su propio `<span>` y lo que el pjax anuncia a
 *      lectores de pantalla es ESE span, no el enlace de al lado.
 *   3. El extra pasa por `wp_kses_post()` y el total por `esc_html()`.
 *   4. El CSS que le devuelve el salto de línea al total con extra está en las
 *      DOS hojas: producción sirve la `.min`.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class LineaDelTotalConExtraTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Igual que SearchSurfaceGuardsTest: el fichero registra sus hooks al
		// incluirse, así que se carga después de `Monkey\setUp()`.
		Functions\when( 'akibara_search_facets_active' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		// `wp_kses_post()` de mentira pero con la propiedad que importa aquí:
		// un `<script>` no sobrevive.
		Functions\when( 'wp_kses_post' )->alias(
			static fn( $v ): string => (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $v )
		);
		require_once dirname( __DIR__ ) . '/frontend/facets-render.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fuente( string $relativa ): string {
		$s = file_get_contents( dirname( __DIR__ ) . '/' . $relativa );
		self::assertIsString( $s, "No se pudo leer {$relativa}" );

		return $s;
	}

	/** Código sin comentarios, para no chocar con lo que el propio docblock explica. */
	private function codigo( string $relativa ): string {
		return (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $this->fuente( $relativa ) );
	}

	/**
	 * @testdox Sin extra, la línea del total es la de siempre, byte a byte.
	 */
	public function test_sin_extra_el_marcado_de_siempre(): void {
		self::assertSame( '<p class="akb-result-count">30 productos</p>', \akibara_search_result_count_html( '30 productos', '' ) );
		self::assertSame( '<p class="akb-result-count">30 productos</p>', \akibara_search_result_count_html( '30 productos', "  \n " ) );
		self::assertSame(
			'<p class="akb-result-count">1 producto</p>',
			\akibara_search_result_count_html( '1 producto', '<script>alert(1)</script>' ),
			'Un extra que el saneo deja vacío tampoco cambia el marcado.'
		);
	}

	/**
	 * @testdox Con extra, el total va en su span y el extra a continuación, en la misma línea.
	 */
	public function test_con_extra_el_total_va_en_su_span(): void {
		$enlace = '<a class="akb-series-latest" href="https://akibara.cl/producto-5107/">Último tomo: 107&nbsp;<span aria-hidden="true">→</span></a>';

		self::assertSame(
			'<p class="akb-result-count akb-result-count--extra"><span class="akb-result-count__total">109 productos</span> ' . $enlace . '</p>',
			\akibara_search_result_count_html( '109 productos', $enlace )
		);
	}

	/**
	 * @testdox El total se escapa y el extra pasa por wp_kses_post.
	 */
	public function test_escapa_el_total_y_sanea_el_extra(): void {
		$html = \akibara_search_result_count_html( '<b>3</b> productos', '<a href="#x">ok</a><script>alert(1)</script>' );

		self::assertStringContainsString( '&lt;b&gt;3&lt;/b&gt; productos', $html );
		self::assertStringContainsString( '<a href="#x">ok</a>', $html );
		self::assertStringNotContainsString( '<script', $html );
	}

	/**
	 * @testdox El render del total pide el extra por filtro, con la vista, y lo imprime con el helper.
	 */
	public function test_el_render_pide_el_extra_por_filtro(): void {
		$codigo = $this->codigo( 'frontend/facets-render.php' );

		self::assertMatchesRegularExpression(
			"/\\\$extra\s*=\s*apply_filters\(\s*'akb_search_result_count_extra',\s*'',\s*\\\$view\s*\);/",
			$codigo
		);
		self::assertMatchesRegularExpression( '/echo\s+akibara_search_result_count_html\(/', $codigo );
		self::assertStringNotContainsString( 'SeriesFacts', $codigo, 'Ni una clase de business: sólo el filtro.' );
	}

	/**
	 * @testdox El anuncio a lectores tras el pjax lee sólo el total, en la fuente y en la minificada.
	 */
	public function test_el_anuncio_lee_solo_el_total(): void {
		self::assertMatchesRegularExpression(
			"/var count = document\.querySelector\( '\.akb-result-count__total' \) \|\|\s*document\.querySelector\( '\.akb-result-count' \);/",
			$this->fuente( 'assets/js/akb-search.js' )
		);
		self::assertStringContainsString(
			'document.querySelector(".akb-result-count__total")||document.querySelector(".akb-result-count")',
			$this->fuente( 'assets/js/akb-search.min.js' )
		);
	}

	/**
	 * @testdox El total con extra recupera el salto de línea en las dos hojas; el total sigue sin partirse.
	 */
	public function test_el_css_llega_a_produccion(): void {
		foreach ( array( 'assets/css/akb-search.css', 'assets/css/akb-search.min.css' ) as $hoja ) {
			$css = (string) preg_replace( '/\s+/', ' ', $this->fuente( $hoja ) );

			self::assertMatchesRegularExpression(
				'/\.akb-shop-toolbar \.akb-result-count\.akb-result-count--extra ?,[^{]*\{ ?white-space: ?normal/',
				$css,
				"{$hoja}: la regla del tema (`.akb-shop-toolbar .akb-result-count`, nowrap) tiene que perder."
			);
			self::assertMatchesRegularExpression( '/\.akb-result-count__total ?\{ ?white-space: ?nowrap/', $css, $hoja );
		}
	}
}
