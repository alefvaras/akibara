<?php
/**
 * Lo que el panel del buscador guarda, el lector de la configuración lo acepta.
 *
 * Tres entradas del panel tenían la misma trampa (2026-09-13): el guardado era
 * más permisivo que el lector, y un solo valor rechazado invalida la
 * configuración entera y apaga indexación, sugerencias y filtros a la vez. Los
 * sinónimos tienen su prueba propia; ésta cubre las palabras ignoradas y las
 * etiquetas de los filtros.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests {

	use Akibara\Search\FacetRegistry;
	use Akibara\Search\Stopwords;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__ ) . '/src/Stopwords.php';
	require_once dirname( __DIR__ ) . '/src/FacetRegistry.php';

	final class PanelNoApagaElBuscadorTest extends TestCase {

		/** @testdox Una palabra ignorada larguísima se descarta al guardar, y se avisa. */
		public function test_palabra_ignorada_larga_se_descarta(): void {
			$larga    = str_repeat( 'a', Stopwords::MAX_WORD_LENGTH + 1 );
			$truncado = false;

			$guardado = Stopwords::sanitize_words( "de\nla, " . $larga . ' los', $truncado );

			$this->assertSame( array( 'de', 'la', 'los' ), $guardado );
			$this->assertTrue( $truncado, 'El panel tiene que decir que se descartó algo.' );
			foreach ( $guardado as $palabra ) {
				$this->assertTrue( Stopwords::word_is_valid( $palabra ), 'Lo guardado tiene que pasar el lector.' );
			}
		}

		/** @testdox Una palabra justo en el límite entra; en minúsculas y sin repetir. */
		public function test_palabra_en_el_limite_entra(): void {
			$justa = str_repeat( 'b', Stopwords::MAX_WORD_LENGTH );

			$this->assertSame( array( $justa, 'el' ), Stopwords::sanitize_words( strtoupper( $justa ) . ', EL, el' ) );
		}

		/** @testdox Una etiqueta de filtro larguísima se recorta al máximo y el lector la acepta. */
		public function test_etiqueta_larga_se_recorta(): void {
			$etiqueta = FacetRegistry::sanitize_label( '  ' . str_repeat( 'Editorial ', 20 ) . '  ' );

			$this->assertLessThanOrEqual( FacetRegistry::MAX_LABEL_LENGTH, mb_strlen( $etiqueta ) );
			$this->assertNotSame( '', trim( $etiqueta ) );
			$this->assertSame( $etiqueta, trim( $etiqueta ), 'Sin espacios en los bordes tras recortar.' );
		}

		/** @testdox Una etiqueta vacía o de puros espacios no se guarda como etiqueta. */
		public function test_etiqueta_vacia_no_se_guarda(): void {
			$this->assertSame( '', FacetRegistry::sanitize_label( '     ' ) );
		}

		/**
		 * El cuarto campo de la misma familia, el contiguo a la etiqueta: el
		 * NOMBRE del filtro. El alta no medía su largo y el lector exige ≤ 64:
		 * pegar un nombre de 70 caracteres daba «Facetas guardadas» y apagaba
		 * indexación, autocompletado y filtros en la petición siguiente
		 * (reapertura de S-5ef0fea5, auditoría independiente del 2026-09-19).
		 *
		 * @testdox Un nombre de filtro largo no es válido; en el límite, sí.
		 */
		public function test_nombre_de_filtro_largo_no_es_valido(): void {
			$this->assertFalse( FacetRegistry::name_is_valid( str_repeat( 'a', FacetRegistry::MAX_NAME_LENGTH + 1 ) ) );
			$this->assertTrue( FacetRegistry::name_is_valid( str_repeat( 'a', FacetRegistry::MAX_NAME_LENGTH ) ) );
			$this->assertTrue( FacetRegistry::name_is_valid( 'formato_tapa-2' ) );

			foreach ( array( '', 'Formato', 'con espacio', 'año', "salto\n", 12, null, array() ) as $malo ) {
				$this->assertFalse( FacetRegistry::name_is_valid( $malo ), var_export( $malo, true ) );
			}
		}

		/**
		 * La prueba que importa: el LECTOR de verdad, con la option que el panel
		 * habría escrito. Se llama al validador real por reflexión, no a una
		 * copia de su regex: si alguien vuelve a escribir el número a mano en
		 * cualquiera de los dos lados, esto lo ve.
		 *
		 * @testdox El lector acepta exactamente lo que el panel deja guardar, y rechaza lo que el panel rechaza.
		 */
		public function test_el_lector_y_el_panel_comparten_la_regla(): void {
			require_once dirname( __DIR__ ) . '/src/SemanticPublication.php';

			$lector = new \ReflectionMethod( \Akibara\Search\SemanticPublication::class, 'valid_facet_overrides' );
			$lector->setAccessible( true );

			$filtro = static fn( string $nombre ): array => array(
				$nombre => array(
					'visible'  => true,
					'order'    => 1,
					'label'    => 'Formato',
					'ui'       => 'checkboxes',
					'type'     => 'taxonomy',
					'taxonomy' => 'pa_formato',
				),
			);

			$justo = str_repeat( 'a', FacetRegistry::MAX_NAME_LENGTH );
			$largo = $justo . 'a';

			$this->assertTrue( FacetRegistry::name_is_valid( $justo ) );
			$this->assertTrue( $lector->invoke( null, $filtro( $justo ) ), 'Lo que el panel deja pasar, el lector lo acepta.' );

			$this->assertFalse( FacetRegistry::name_is_valid( $largo ) );
			$this->assertFalse( $lector->invoke( null, $filtro( $largo ) ), 'Y lo que el lector rechaza, el panel ya no lo guarda.' );
		}

		/** @testdox El tope de filtros es el mismo número en el panel y en el lector. */
		public function test_el_tope_de_filtros_es_uno_solo(): void {
			require_once dirname( __DIR__ ) . '/src/SemanticPublication.php';

			$lector = new \ReflectionMethod( \Akibara\Search\SemanticPublication::class, 'valid_facet_overrides' );
			$lector->setAccessible( true );

			$muchos = array();
			for ( $i = 0; $i <= FacetRegistry::MAX_FACETS; $i++ ) {
				$muchos[ 'f' . $i ] = array(
					'visible'  => true,
					'order'    => $i,
					'label'    => 'Filtro ' . $i,
					'ui'       => 'checkboxes',
					'type'     => 'taxonomy',
					'taxonomy' => 'pa_formato',
				);
			}

			$this->assertFalse( $lector->invoke( null, $muchos ), 'Uno más que el tope: el lector lo rechaza.' );
			array_pop( $muchos );
			$this->assertTrue( $lector->invoke( null, $muchos ), 'Justo en el tope, entra.' );

			// Y el alta del panel mide contra la MISMA constante, no contra un 32 propio.
			$panel = (string) file_get_contents( dirname( __DIR__ ) . '/src/SearchAdminSection.php' );
			$this->assertStringContainsString( 'count( $out ) >= FacetRegistry::MAX_FACETS', $panel );
			$this->assertStringContainsString( '! FacetRegistry::name_is_valid( $name )', $panel );
		}

		/**
		 * `FacetsView` leía `$def['open']` («el admin lo fuerza»), una clave que
		 * ninguna pantalla escribe y que el lector RECHAZA: la única forma de
		 * usarla —a mano en la option— apagaba el buscador entero (S-4d9db54b).
		 *
		 * @testdox La vista no ofrece una palanca que el lector de la configuración rechaza.
		 */
		public function test_la_vista_no_lee_una_clave_que_el_lector_rechaza(): void {
			require_once dirname( __DIR__ ) . '/src/SemanticPublication.php';

			$lector = new \ReflectionMethod( \Akibara\Search\SemanticPublication::class, 'valid_facet_overrides' );
			$lector->setAccessible( true );

			$this->assertFalse(
				$lector->invoke( null, array( 'editorial' => array( 'visible' => true, 'open' => true ) ) ),
				'El lector no admite `open`: escribirla invalida la configuración entera.'
			);

			$vista = (string) file_get_contents( dirname( __DIR__ ) . '/src/FacetsView.php' );
			$sin_comentarios = (string) preg_replace( '#^\s*(//|\*|/\*).*$#m', '', $vista );
			$this->assertStringNotContainsString( "\$def['open']", $sin_comentarios, 'Si la vista la lee, alguien la escribirá.' );
		}
	}
}
