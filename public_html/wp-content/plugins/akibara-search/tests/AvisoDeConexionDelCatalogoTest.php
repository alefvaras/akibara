<?php
/**
 * El aviso de un filtro que no carga por la conexión (REV-C-S-1o-1 y 1o-2,
 * corrección C-S-c1 del 2026-09-26).
 *
 * Un PJAX del catálogo que falla por la red ya no navega solo: en Safari un
 * fallo de red y un Atrás no se distinguen a tiempo, y el location.assign()
 * automático le ganaba al Atrás del cliente. A cambio, `akb-search.js` muestra
 * este aviso con su «Reintentar». Lo pinta el servidor, oculto, en la barra de
 * resultados que el PJAX reemplaza, con el texto que el dueño edita en
 * «Buscador → Ajustes».
 *
 * Contratos que se fijan:
 *   1. El texto de fábrica y el HTML que sale de él, byte a byte: lo que va
 *      entre corchetes es el botón `data-akb-reintentar` que atiende el bundle.
 *   2. El texto del panel se escapa; sin corchetes no hay botón.
 *   3. Sale dentro de un `<template>` con la marca que busca el bundle: su
 *      texto no es texto de la página (buscadores, IA) hasta que hace falta.
 *      Y dentro de un `.woocommerce-notices-wrapper` (REV-C-S-2o-1, corrección
 *      C-S-c2): el tema le da su propia línea al principio de la barra y, desde
 *      1025 px, vuelve a apilar la cabecera cuando la barra trae un aviso.
 *      Suelto en la barra quedaba al lado del H1, que bajaba 27 px.
 *   4. El CSS de la banda está en las DOS hojas: producción sirve la `.min`.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\EmptyStateCopy;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AvisoDeConexionDelCatalogoTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'esc_html' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ): string => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'akibara_search_facets_active' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );

		// El fichero registra sus hooks al incluirse: se carga tras Monkey\setUp().
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

	/**
	 * @testdox De fábrica dice qué pasó y su botón «Reintentar» es el que atiende el bundle.
	 */
	public function test_de_fabrica(): void {
		self::assertSame( 'No pudimos cargar los productos por un problema de conexión. [Reintentar]', EmptyStateCopy::network() );
		self::assertSame(
			'No pudimos cargar los productos por un problema de conexión. <button type="button" class="akb-link" data-akb-reintentar>Reintentar</button>',
			EmptyStateCopy::network_html()
		);
	}

	/**
	 * @testdox El texto del panel se escapa; lo que va entre corchetes es el botón y, sin corchetes, no hay botón.
	 */
	public function test_el_texto_del_panel(): void {
		// Con etiquetas, como puede llegar por `wp option update`: se leen sin ellas.
		$this->options[ EmptyStateCopy::OPTION_NETWORK ] = 'Se cortó la <b>"señal"</b> & no cargó. [Probar otra vez] o escríbenos.';
		self::assertSame(
			'Se cortó la &quot;señal&quot; &amp; no cargó. <button type="button" class="akb-link" data-akb-reintentar>Probar otra vez</button> o escríbenos.',
			EmptyStateCopy::network_html()
		);

		$this->options[ EmptyStateCopy::OPTION_NETWORK ] = 'Sin conexión & sin botón';
		self::assertSame( 'Sin conexión &amp; sin botón', EmptyStateCopy::network_html(), 'Una decisión del dueño, no un error.' );

		$this->options[ EmptyStateCopy::OPTION_NETWORK ] = '   ';
		self::assertSame( EmptyStateCopy::DEFAULT_NETWORK, EmptyStateCopy::network(), 'Vacío vuelve al de fábrica: un fallo sin explicación es peor.' );
	}

	/**
	 * @testdox Sale en un template con la marca que busca el bundle, en un contenedor de avisos de la barra de resultados: no es texto de la página.
	 */
	public function test_sale_en_un_template_con_su_marca(): void {
		self::assertSame(
			'<template data-akb-aviso-red><div class="woocommerce-notices-wrapper"><p class="akb-aviso-red">No pudimos cargar los productos por un problema de conexión. <button type="button" class="akb-link" data-akb-reintentar>Reintentar</button></p></div></template>',
			\akibara_gp_akb_network_notice()
		);

		// Se imprime junto a los otros dos avisos del buscador, dentro de la
		// línea del total: en la barra que el PJAX reemplaza.
		$render = $this->fuente( 'frontend/facets-render.php' );
		$inicio = strpos( $render, 'function akibara_gp_akb_render_result_count' );
		self::assertIsInt( $inicio );
		$cuerpo = substr( $render, $inicio, (int) strpos( $render, "\n}\n", $inicio ) - $inicio );
		self::assertStringContainsString( 'echo akibara_gp_akb_network_notice();', $cuerpo );

		foreach ( array( 'assets/js/akb-search.js', 'assets/js/akb-search.min.js' ) as $bundle ) {
			$js = $this->fuente( $bundle );
			self::assertStringContainsString( 'template[data-akb-aviso-red]', $js, $bundle . ' busca el aviso por su marca.' );
			self::assertStringContainsString( '[data-akb-reintentar]', $js, $bundle . ' atiende su botón.' );
		}
	}

	/**
	 * @testdox El CSS de la banda está en la hoja y en su .min; la línea propia se la da el contenedor de avisos.
	 */
	public function test_el_css_esta_en_las_dos_hojas(): void {
		foreach ( array( 'assets/css/akb-search.css', 'assets/css/akb-search.min.css' ) as $hoja ) {
			$css = (string) preg_replace( '/\s+/', '', (string) preg_replace( '#/\*.*?\*/#s', '', $this->fuente( $hoja ) ) );
			self::assertMatchesRegularExpression( '/\.akb-aviso-red\{[^}]*border-left:3pxsolid/', $css, $hoja . ': la banda del aviso.' );
			self::assertMatchesRegularExpression( '/\.akb-shop-toolbar\.akb-aviso-red\{margin:0;?\}/', $css, $hoja . ': sin margen dentro de la barra (la separación es su gap).' );
			self::assertStringNotContainsString( '.akb-shop-toolbar.akb-aviso-red{flex', $css, $hoja . ': la línea propia es del contenedor de avisos, no del párrafo (un flex ahí no haría nada).' );
			self::assertMatchesRegularExpression( '/\.akb-aviso-red\{[^}]*scroll-margin-top:150px/', $css, $hoja . ': la página sube hasta él sin que la cabecera fija lo tape.' );
		}
	}
}
