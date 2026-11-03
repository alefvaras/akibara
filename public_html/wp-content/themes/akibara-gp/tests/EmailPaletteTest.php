<?php
/**
 * Contrato ejecutable de la hoja de correo servida por el wrapper de marca.
 *
 * Cierra dos hallazgos del gate de correos (2026-09-01):
 *
 *   - **CTA ilegible.** `#body_content_inner a` declara
 *     `-webkit-text-fill-color`, y Emogrifier lo inlina sobre TODOS los `<a>`
 *     del cuerpo. Un botón que sólo trae `color:#ffffff` gana el `color` pero
 *     no el relleno: WebKit pintaba texto rojo sobre botón rojo, 1,23:1.
 *   - **Dos rojos y dos amarillos.** Convivían el `#CC0000` heredado de
 *     WooCommerce y el `#E60012` de marca; el pie usaba `#FFC21A` contra el
 *     token `#FFD700`.
 *
 * Se afirma sobre el HTML RENDERIZADO, no sobre el texto del archivo.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class EmailPaletteTest extends TestCase {

	/** Rojo de marca (theme.json `primary`). Superficies: bandas y botones. */
	private const ROJO_MARCA = '#E60012';

	/** Rojo legible sobre claro (theme.json `primary-readable`). Enlaces de texto. */
	private const ROJO_LEGIBLE = '#CC0010';

	/** Amarillo de marca (theme.json `accent`). */
	private const AMARILLO = '#FFD700';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( 'trailingslashit' )->alias( static fn( $v ) => rtrim( (string) $v, '/' ) . '/' );
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( 'https://akibara.test/wp-content/themes/akibara-gp' );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ) => 'https://akibara.test' . $path );
		Functions\when( 'get_bloginfo' )->justReturn( 'Akibara' );
		Functions\when( 'bloginfo' )->alias( static fn( $show = '' ) => print( 'UTF-8' ) );
		Functions\when( 'language_attributes' )->alias( static fn() => print( 'lang="es"' ) );
		// `esc_url()` y `esc_attr()` NO se re-declaran: `tests/wp-stubs.php` las
		// define antes que Patchwork y redefinirlas revienta con
		// `DefinedTooEarly`.
		Functions\when( 'esc_html' )->alias(
			static fn( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'wp_date' )->justReturn( '2026' );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $value ) => strip_tags( (string) $value )
		);
		Functions\when( 'wp_kses_post' )->returnArg();

		// El pie incluye `akibara_gp_email_footer_public_line()` (inc/woocommerce.php).
		// Esta clase no la cargaba: pasaba sólo si OTRA prueba había cargado ese
		// fichero antes, y con `--order-by=random` caía con «undefined function»
		// según la semilla (2026-09-19). El require va DESPUÉS de Monkey\setUp():
		// el fichero engancha hooks al cargarse y `add_action()` sólo existe como
		// doble una vez inicializado Brain Monkey.
		require_once dirname( __DIR__ ) . '/inc/page-targets.php';
		require_once dirname( __DIR__ ) . '/inc/woocommerce.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function header(): string {
		$email_heading = 'Salió el siguiente tomo';

		ob_start();
		include dirname( __DIR__ ) . '/woocommerce/emails/email-header.php';
		return (string) ob_get_clean();
	}

	private function footer(): string {
		ob_start();
		include dirname( __DIR__ ) . '/woocommerce/emails/email-footer.php';
		return (string) ob_get_clean();
	}

	/**
	 * @testdox El botón de correo repone el relleno blanco con !important.
	 */
	public function test_el_boton_repone_el_relleno_blanco(): void {
		$html = $this->header();

		self::assertMatchesRegularExpression(
			'/a\.akb-email-btn\s*\{[^}]*-webkit-text-fill-color:\s*#ffffff\s*!important/i',
			$html,
			'Sin este relleno el botón sale rojo sobre rojo en Apple Mail, la app de Gmail y Outlook móvil.'
		);
		self::assertMatchesRegularExpression(
			'/a\.akb-email-btn\s*\{[^}]*background-color:\s*' . self::ROJO_MARCA . '/i',
			$html
		);
	}

	/**
	 * La trampa: es esta regla la que rellena de rojo los botones. Si alguien la
	 * quita, el `-webkit-text-fill-color` inline de las plantillas se queda sin
	 * explicación y alguien lo «limpiará». Si alguien la deja SIN la excepción
	 * del botón, vuelve el 1,23:1.
	 *
	 * @testdox La regla que rellena los enlaces existe y va ANTES de la excepción del botón.
	 */
	public function test_la_regla_que_rellena_los_enlaces_precede_a_la_excepcion(): void {
		$html = $this->header();

		$regla     = strpos( $html, '#body_content_inner a {' );
		$excepcion = strpos( $html, 'a.akb-email-btn {' );

		self::assertNotFalse( $regla );
		self::assertNotFalse( $excepcion );
		self::assertLessThan( $excepcion, $regla, 'La excepción tiene que venir después para ganar en igualdad de peso.' );
	}

	/**
	 * @testdox Los enlaces de texto usan el rojo LEGIBLE, no el rojo de superficie.
	 */
	public function test_los_enlaces_de_texto_usan_el_rojo_legible(): void {
		self::assertMatchesRegularExpression(
			'/#body_content_inner a\s*\{[^}]*color:\s*' . self::ROJO_LEGIBLE . '/i',
			$this->header()
		);
	}

	/**
	 * @testdox El correo sirve UN solo rojo de marca y UN solo amarillo.
	 */
	public function test_no_sobrevive_ningun_rojo_ni_amarillo_fuera_de_los_tokens(): void {
		$servido = $this->header() . $this->footer();

		foreach ( array( '#cc0000', '#ffc21a', '#c00', '#8526ff' ) as $prohibido ) {
			self::assertStringNotContainsStringIgnoringCase(
				$prohibido,
				$servido,
				sprintf( '%s no es un token de marca: la fuente de verdad es %s / %s / %s.', $prohibido, self::ROJO_MARCA, self::ROJO_LEGIBLE, self::AMARILLO )
			);
		}
	}

	/**
	 * El tile rojo es la capa que sobrevive al modo oscuro forzado de Gmail
	 * (no recolorea imágenes). Si el PNG y el `background-color` divergen, el
	 * cliente que carga imágenes ve un rojo y el que las bloquea, otro.
	 *
	 * @testdox El tile PNG de las bandas está horneado en el mismo rojo de marca.
	 */
	public function test_el_tile_rojo_coincide_con_el_rojo_de_marca(): void {
		$png = dirname( __DIR__ ) . '/assets/img/akb-btn-red.png';
		self::assertFileExists( $png );

		$imagen = imagecreatefrompng( $png );
		self::assertNotFalse( $imagen );

		$color = imagecolorat( $imagen, 0, 0 );
		imagedestroy( $imagen );

		self::assertSame(
			strtolower( ltrim( self::ROJO_MARCA, '#' ) ),
			sprintf( '%06x', $color & 0xFFFFFF )
		);
	}

	/**
	 * @testdox El pie usa bloque negro, filete superior rojo de marca y enlaces blancos.
	 */
	public function test_el_pie_usa_bloque_negro_filete_rojo_y_enlaces_blancos(): void {
		self::assertMatchesRegularExpression(
			'/#template_footer\s*\{[^}]*background-color:\s*#0d0d0d/i',
			$this->header()
		);
		self::assertMatchesRegularExpression(
			'/#template_footer a\s*\{[^}]*color:\s*#ffffff\s*!important/i',
			$this->header()
		);
		self::assertStringContainsStringIgnoringCase( self::ROJO_MARCA, $this->footer() );
		self::assertStringContainsStringIgnoringCase( 'color: #ffffff', $this->footer() );
	}
}
