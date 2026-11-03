<?php
/**
 * El umbral de envío gratis se escribe UNA vez, en el panel.
 *
 * Contexto (2026-09-02): subirlo de $55.000 a $60.000 obligó a perseguir el
 * número por tres superficies —el campo del panel, la barra de anuncios y la
 * página de FAQ— porque en dos de ellas estaba escrito a mano. La FAQ sólo
 * apareció porque el dueño se acordó de ella. Una promesa de envío vieja la paga
 * el cliente en la caja, así que el número tiene que salir siempre del mismo
 * sitio.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\ShippingCopy;

use Akibara\Business\Modules\ShippingCopy\ShippingCopy;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ShippingCopyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'number_format_i18n' )->alias(
			static fn ( $n, $d = 0 ) => number_format( (float) $n, (int) $d, ',', '.' )
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'shortcode_atts' )->alias(
			static fn ( array $pares, array $atts ) => array_merge( $pares, array_intersect_key( $atts, $pares ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function con_umbral( $valor ): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => 'akibara_fsp_carrier_threshold' === $option ? $valor : $default_value
		);
	}

	/** @testdox El shortcode devuelve el umbral vivo del panel, formateado. */
	public function test_it_renders_the_live_threshold(): void {
		$this->con_umbral( '60000' );

		self::assertSame( '$60.000', ( new ShippingCopy() )->render( array() ) );
		self::assertSame( 60000, ShippingCopy::threshold() );
	}

	/** @testdox Cambiar el número en el panel cambia todas las superficies a la vez. */
	public function test_changing_the_option_changes_every_surface(): void {
		$this->con_umbral( '55000' );
		$frase = 'Envío GRATIS a todo Chile sobre [akb_envio_gratis]';
		self::assertSame( 'Envío GRATIS a todo Chile sobre $55.000', ShippingCopy::resolve_in( $frase ) );

		// El dueño sube el umbral en el panel: la MISMA frase, sin tocarla, ya dice otro monto.
		$this->con_umbral( '60000' );
		self::assertSame( 'Envío GRATIS a todo Chile sobre $60.000', ShippingCopy::resolve_in( $frase ) );
	}

	/** @testdox Sin umbral configurado no se inventa una promesa de «$0». */
	public function test_no_threshold_means_no_promise(): void {
		$this->con_umbral( '0' );

		self::assertSame( '', ShippingCopy::formatted() );
		self::assertSame( 'Envío gratis sobre ', ShippingCopy::resolve_in( 'Envío gratis sobre [akb_envio_gratis]' ) );
	}

	/** @testdox La variante sin signo sirve cuando la frase ya lo trae escrito. */
	public function test_the_unsigned_variant(): void {
		$this->con_umbral( '60000' );

		self::assertSame( '60.000', ( new ShippingCopy() )->render( array( 'signo' => 'no' ) ) );
		self::assertSame( 'sobre $60.000 pesos', ShippingCopy::resolve_in( 'sobre $[akb_envio_gratis signo="no"] pesos' ) );
	}

	/**
	 * La barra de anuncios imprime con `wp_kses()` y sin `do_shortcode()`, a
	 * propósito: es texto que se pinta en TODAS las páginas del sitio y abrirlo
	 * a shortcodes arbitrarios sería una superficie de ejecución de más. La
	 * resolución tiene que ser quirúrgica.
	 *
	 * @testdox Resolver el umbral no ejecuta ningún otro shortcode.
	 */
	public function test_it_resolves_only_its_own_shortcode(): void {
		$this->con_umbral( '60000' );

		$texto = 'Envío gratis sobre [akb_envio_gratis] · [gallery] · [akb_puntos_resumen] · [script]';
		$salida = ShippingCopy::resolve_in( $texto );

		self::assertStringContainsString( '$60.000', $salida );
		self::assertStringContainsString( '[gallery]', $salida, 'Los shortcodes ajenos se quedan como texto.' );
		self::assertStringContainsString( '[akb_puntos_resumen]', $salida );
		self::assertStringContainsString( '[script]', $salida );
	}

	/** @testdox Un texto sin el shortcode vuelve intacto, sin coste. */
	public function test_untouched_text_is_returned_as_is(): void {
		$this->con_umbral( '60000' );

		$texto = 'Retiro sin costo en Metro San Miguel, con día y hora coordinados';
		self::assertSame( $texto, ShippingCopy::resolve_in( $texto ) );
	}

	/**
	 * @testdox El módulo lee la MISMA opción que la barra de progreso, no una copia.
	 */
	public function test_it_shares_the_option_with_the_progress_bar(): void {
		$copy  = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/ShippingCopy/ShippingCopy.php' );
		$barra = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/FreeShippingProgress/FreeShippingProgress.php' );

		self::assertStringContainsString( "'akibara_fsp_carrier_threshold'", $copy );
		self::assertStringContainsString( "'akibara_fsp_carrier_threshold'", $barra, 'Si la barra cambia de opción, este módulo se queda anunciando otro número.' );
	}

	/**
	 * El dueño reportó con captura (2026-09-02) que el mensaje se cortaba en su
	 * móvil: «RETIRO SIN COSTO EN METRO SAN MIGUEL, CON DÍA Y HORA COORDIN…».
	 * Medido después con la tipografía servida: a 360px entran ~40 caracteres, y
	 * los mensajes tenían 64. El recorte se come el final, que es donde vive la
	 * promesa. El límite tiene que estar escrito donde se redacta.
	 *
	 * @testdox El panel avisa del límite de caracteres y de cómo escribir el umbral.
	 */
	public function test_the_panel_warns_about_the_mobile_limit(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/AnnouncementBar/AnnouncementBar.php' );

		// El número sale de `LINE_MAX` (el mismo que lee el contador del panel)
		// desde que la cinta del teléfono admite dos renglones (QA-RV-7).
		self::assertMatchesRegularExpression( "/Máximo ~(?:\\d+|' \\. self::LINE_MAX \\. ') caracteres por línea/u", $fuente, 'Sin el número escrito, el siguiente mensaje largo vuelve a cortarse.' );
		self::assertStringContainsString( 'akb_envio_gratis', $fuente, 'Y se explica dónde escribir el umbral para no fijarlo a mano.' );
	}

	/**
	 * Se mira sólo el CÓDIGO, no los comentarios: el propio comentario de la
	 * barra explica por qué no se usa la función que ejecuta todos los
	 * shortcodes, y nombrarla ahí no puede hacer fallar la prueba que la
	 * prohíbe.
	 *
	 * @testdox La barra de anuncios resuelve el umbral antes de imprimir, sin ejecutar shortcodes ajenos.
	 */
	public function test_the_announcement_bar_resolves_the_threshold(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/AnnouncementBar/AnnouncementBar.php' );

		self::assertStringContainsString( 'ShippingCopy::resolve_in(', $fuente );

		$codigo = implode(
			"\n",
			array_filter(
				preg_split( '/\R/', $fuente ) ?: array(),
				static function ( string $linea ): bool {
					$t = ltrim( $linea );
					return '' !== $t && '*' !== $t[0] && 0 !== strpos( $t, '//' ) && 0 !== strpos( $t, '/*' );
				}
			)
		);

		self::assertStringNotContainsString(
			'do_shortcode(',
			$codigo,
			'La barra NO debe ejecutar shortcodes arbitrarios: se pinta en todas las páginas del sitio.'
		);
	}
}
