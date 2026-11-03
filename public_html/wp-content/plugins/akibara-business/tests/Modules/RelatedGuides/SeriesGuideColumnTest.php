<?php
/**
 * Columna «Guía» del listado de series (`pa_serie`).
 *
 * Por qué existe: el vínculo serie ⇄ guía sólo se veía entrando a editar cada
 * término. Medido en producción el 2026-08-23: 119 términos `pa_serie` y 18
 * termmeta `_akb_related_guide_post_id`, sin ninguna señal en el listado.
 *
 * La invariante que fija este test no es «hay una columna», sino que el
 * listado distingue los TRES estados que el front trata distinto:
 *   - sin guía: decisión editorial legítima (hay guías temáticas que no cuelgan
 *     de ninguna serie);
 *   - guía viva: `resolve_view_model()` pinta el bloque;
 *   - vínculo roto (borrador, papelera o post borrado): `resolve_view_model()`
 *     devuelve null y la landing se queda sin bloque EN SILENCIO. Ese es el
 *     estado que no se podía ver desde ninguna pantalla.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\RelatedGuides;

use Akibara\Business\Modules\RelatedGuides\SeriesRelatedGuide;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SeriesGuideColumnTest extends TestCase {

	private SeriesRelatedGuide $modulo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->alias(
			static fn( $texto ) => htmlspecialchars( (string) $texto, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'esc_url' )->alias(
			static fn( $url ) => htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' )
		);

		$this->modulo = new SeriesRelatedGuide();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should register the column without dropping the existing ones.
	 */
	public function test_la_columna_se_suma_sin_pisar_las_existentes(): void {
		$columnas = $this->modulo->handle_admin_column( array( 'name' => 'Nombre', 'posts' => 'Cuenta' ) );

		self::assertSame( array( 'name', 'posts', 'akb_related_guide' ), array_keys( $columnas ) );
		self::assertSame( 'Guía', $columnas['akb_related_guide'] );
	}

	/**
	 * @testdox Should leave other columns untouched.
	 */
	public function test_otras_columnas_pasan_intactas(): void {
		self::assertSame(
			'contenido-previo',
			$this->modulo->handle_render_admin_column( 'contenido-previo', 'name', 7 )
		);
	}

	/**
	 * @testdox Should show a dash when the series has no guide.
	 */
	public function test_serie_sin_guia_muestra_guion(): void {
		Functions\when( 'get_term_meta' )->justReturn( '' );

		self::assertSame( '—', $this->modulo->handle_render_admin_column( '', 'akb_related_guide', 7 ) );
	}

	/**
	 * @testdox Should link a live guide to its editor screen with escaped output.
	 */
	public function test_guia_viva_enlaza_al_editor_y_escapa(): void {
		$this->stub_guia( 'publish', 'Guía "peligrosa" <script>' );

		$salida = $this->modulo->handle_render_admin_column( '', 'akb_related_guide', 7 );

		self::assertStringContainsString( 'href="https://akibara.test/wp-admin/post.php?post=22640&amp;action=edit"', $salida );
		self::assertStringContainsString( '&quot;peligrosa&quot;', $salida );
		self::assertStringNotContainsString( '<script>', $salida );
		self::assertStringContainsString( '&lt;script&gt;', $salida );
	}

	/**
	 * @testdox Should flag a broken link instead of pretending there is no guide.
	 */
	public function test_vinculo_roto_no_se_confunde_con_sin_guia(): void {
		$this->stub_guia( 'draft', 'Borrador' );

		$salida = $this->modulo->handle_render_admin_column( '', 'akb_related_guide', 7 );

		self::assertStringContainsString( '22640', $salida );
		self::assertStringNotContainsString( 'Borrador', $salida );
		self::assertNotSame( '—', $salida );
	}

	/**
	 * @testdox Should degrade to plain text when the user cannot edit the guide.
	 */
	public function test_sin_permiso_de_edicion_queda_el_titulo_plano(): void {
		$this->stub_guia( 'publish', 'Cómo leer Naruto' );
		Functions\when( 'get_edit_post_link' )->justReturn( null );

		self::assertSame( 'Cómo leer Naruto', $this->modulo->handle_render_admin_column( '', 'akb_related_guide', 7 ) );
	}

	private function stub_guia( string $estado, string $titulo ): void {
		Functions\when( 'get_term_meta' )->justReturn( '22640' );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_status' )->justReturn( $estado );
		Functions\when( 'get_the_title' )->justReturn( $titulo );
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://akibara.test/wp-admin/post.php?post=22640&action=edit' );
	}
}
