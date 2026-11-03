<?php
/**
 * La parte en texto plano de un correo no puede comerse los apóstrofes.
 *
 * Revisión de 1.116.8 (tema 2.1.121): `esc_html()` convierte `'` en `&#039;`, y
 * `WC_Email` pasa la parte plana por `$plain_search`/`$plain_replace`, que
 * traduce las entidades conocidas y BORRA las demás («Unknown/unhandled
 * entities»). `&#039;` no está en la lista: «Hola D'Angelo» salía «Hola
 * DAngelo». Lo mismo pasaba en las plantillas planas de business con los
 * títulos («JoJo's Bizarre Adventure»).
 *
 * Se prueba contra las reglas REALES de WooCommerce, leídas de su fichero: si un
 * día WooCommerce reconoce `&#039;`, la primera prueba lo dirá.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Emails;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class TextoPlanoConservaApostrofesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Las dos listas de `WC_Email`, tal como las declara WooCommerce.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>}
	 */
	private static function reglas_de_woocommerce(): array {
		$fichero = ABSPATH . 'wp-content/plugins/woocommerce/includes/emails/class-wc-email.php';
		self::assertFileExists( $fichero, 'Sin la clase de WooCommerce no hay contra qué medir.' );

		$reglas = array();
		foreach ( array( 'plain_search', 'plain_replace' ) as $propiedad ) {
			$tokens = token_get_all( (string) file_get_contents( $fichero ) );
			$literal = '';
			$dentro  = false;
			$nivel   = 0;
			foreach ( $tokens as $token ) {
				if ( ! $dentro ) {
					if ( is_array( $token ) && T_VARIABLE === $token[0] && '$' . $propiedad === $token[1] ) {
						$dentro = true;
					}
					continue;
				}
				$texto = is_array( $token ) ? $token[1] : $token;
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( '' === trim( $literal ) && ( '=' === $texto || '' === trim( $texto ) ) ) {
					continue; // Hasta el `array(`: el `=` y los espacios no son parte del literal.
				}
				$literal .= $texto;
				if ( '(' === $texto ) {
					++$nivel;
				} elseif ( ')' === $texto && 0 === --$nivel ) {
					break;
				}
			}
			self::assertNotSame( '', trim( $literal ), "No se encontró \${$propiedad} en WC_Email." );
			$reglas[] = eval( 'return ' . $literal . ';' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- literal de array de WooCommerce, sin llamadas.
		}

		return array( $reglas[0], $reglas[1] );
	}

	/** Lo que hace `WC_Email::get_content()` con la parte plana. */
	private static function como_la_envia_woocommerce( string $plano ): string {
		list( $buscar, $reemplazar ) = self::reglas_de_woocommerce();

		return wordwrap( (string) preg_replace( $buscar, $reemplazar, wp_strip_all_tags( $plano ) ), 70 );
	}

	/** @testdox El mecanismo: WooCommerce borra `&#039;` de la parte plana, así que `esc_html()` ahí se come el apóstrofe. */
	public function test_woocommerce_drops_the_entity_that_esc_html_writes(): void {
		self::assertSame( 'Hola DAngelo', self::como_la_envia_woocommerce( 'Hola D&#039;Angelo' ) );
		self::assertSame( "Hola D'Angelo", self::como_la_envia_woocommerce( "Hola D'Angelo" ) );
		self::assertSame( 'Ana & Co', self::como_la_envia_woocommerce( 'Ana &amp; Co' ), 'Lo que wp_kses() normaliza sí lo traduce.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function plantillas_planas(): array {
		$raiz = dirname( __DIR__, 4 );
		$casos = array();
		foreach (
			array_merge(
				(array) glob( $raiz . '/plugins/akibara-business/templates/emails/plain/*.php' ),
				(array) glob( $raiz . '/themes/akibara-gp/woocommerce/emails/plain/*.php' )
			) as $ruta
		) {
			$casos[ str_replace( $raiz . '/', '', (string) $ruta ) ] = array( (string) $ruta );
		}

		return $casos;
	}

	/**
	 * @dataProvider plantillas_planas
	 * @testdox Ninguna plantilla plana escapa para HTML
	 */
	public function test_no_plain_template_escapes_for_html( string $ruta ): void {
		$codigo = '';
		foreach ( token_get_all( (string) file_get_contents( $ruta ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$codigo .= is_array( $token ) ? $token[1] : $token;
		}

		self::assertDoesNotMatchRegularExpression(
			'/\b(?:esc_html|esc_html__|esc_html_e|esc_html_x|esc_attr|esc_attr__|esc_attr_e|esc_url)\s*\(/',
			$codigo,
			'En texto plano el texto sale por EmailBrand::plano() y los enlaces por esc_url_raw(): las entidades HTML se pierden en WC_Email.'
		);

		// Y tampoco por `wp_kses( …, array() )`, que era el camino anterior:
		// quita las etiquetas pero DEJA la entidad, y WooCommerce se la lleva
		// igual (hallazgo S-0dda49e6). Sólo sobrevive lo ya decodificado.
		if ( false !== strpos( $ruta, '/plugins/akibara-business/templates/emails/plain/' ) ) {
			self::assertDoesNotMatchRegularExpression(
				'/\bwp_kses\s*\(/',
				$codigo,
				'Una entidad que llega viva a WC_Email desaparece del correo del cliente.'
			);
		}
	}

	/**
	 * El caso real: el título del producto ya viene GUARDADO con la entidad
	 * («JoJo&#039;s Bizarre Adventure»), no la introduce la plantilla. El
	 * camino viejo la dejaba pasar y WooCommerce se la comía; el cliente leía
	 * «JoJos» (hallazgo S-0dda49e6, medido el 2026-09-18).
	 *
	 * @testdox Un título guardado con la entidad llega con su apóstrofe.
	 */
	public function test_a_stored_entity_reaches_the_customer_intact(): void {
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $texto ): string => (string) strip_tags( (string) $texto )
		);

		$guardado = 'JoJo&#039;s Bizarre Adventure';

		// El camino viejo: `wp_kses( …, array() )` dejaba la entidad tal cual.
		self::assertSame(
			'JoJos Bizarre Adventure',
			self::como_la_envia_woocommerce( $guardado ),
			'Así llegaba al cliente hasta el 2026-09-18.'
		);

		// El vigente: la plantilla la decodifica antes de imprimir.
		self::assertSame(
			"JoJo's Bizarre Adventure",
			self::como_la_envia_woocommerce( \Akibara\Business\Modules\EmailBrand\EmailBrand::plano( $guardado ) )
		);

		// Y el ampersand, que kses además re-escapaba a `&amp;amp;`.
		self::assertSame(
			'Haikyu!! Vol. 1 & 2',
			self::como_la_envia_woocommerce( \Akibara\Business\Modules\EmailBrand\EmailBrand::plano( 'Haikyu!! Vol. 1 &amp; 2' ) )
		);
	}

	/** @testdox «Restablecer contraseña» en texto plano saluda a D'Angelo con su apóstrofe y el enlace llega entero. */
	public function test_the_reset_password_email_keeps_the_apostrophe(): void {
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'first_name' => "D'Angelo" ) );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/mi-cuenta/' );
		Functions\when( 'wc_get_endpoint_url' )->alias( static fn( string $endpoint, string $value, string $base ): string => $base . $endpoint . '/' );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wptexturize' )->returnArg();
		Functions\when( 'get_option' )->justReturn( 'Akibara · tu distrito del manga y cómics' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses' )->alias( static fn( $texto ): string => strip_tags( (string) $texto ) );

		$plano = self::render(
			dirname( __DIR__, 4 ) . '/themes/akibara-gp/woocommerce/emails/plain/customer-reset-password.php',
			array(
				'email_heading'      => 'Recupera el acceso a tu cuenta',
				'user_login'         => 'dangelo',
				'reset_key'          => 'abc123',
				'user_id'            => 9,
				'blogname'           => 'Akibara',
				'additional_content' => "Si no pediste esto, no hagas nada. It's fine.",
			)
		);
		$enviado = self::como_la_envia_woocommerce( $plano );

		self::assertStringContainsString( "Hola D'Angelo,", $enviado );
		self::assertStringContainsString( "It's fine.", $enviado );
		self::assertStringContainsString( 'https://akibara.test/mi-cuenta/lost-password/?key=abc123&id=9&login=dangelo', $enviado );
	}

	/** @testdox «Cuenta nueva» en texto plano conserva el apóstrofe del nombre y del usuario. */
	public function test_the_new_account_email_keeps_the_apostrophe(): void {
		Functions\when( 'get_user_by' )->justReturn(
			(object) array(
				'first_name'   => "D'Angelo",
				'display_name' => "D'Angelo R.",
			)
		);
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/mi-cuenta/' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wptexturize' )->returnArg();
		Functions\when( 'get_option' )->justReturn( 'Akibara' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses' )->alias( static fn( $texto ): string => strip_tags( (string) $texto ) );

		$enviado = self::como_la_envia_woocommerce(
			self::render(
				dirname( __DIR__, 4 ) . '/themes/akibara-gp/woocommerce/emails/plain/customer-new-account.php',
				array(
					'email_heading'      => 'Bienvenido a Akibara',
					'blogname'           => 'Akibara',
					'user_login'         => "d'angelo",
					'user_email'         => 'dangelo@example.test',
					'password_generated' => false,
					'set_password_url'   => '',
					'additional_content' => '',
				)
			)
		);

		self::assertStringContainsString( "¡Hola D'Angelo!", $enviado );
		self::assertStringContainsString( "Tu usuario: d'angelo", $enviado );
		self::assertStringContainsString( 'https://akibara.test/mi-cuenta/', $enviado );
	}

	/**
	 * @param array<string, mixed> $variables Variables del contrato de la plantilla.
	 */
	private static function render( string $ruta, array $variables ): string {
		return ( static function ( string $akb_ruta, array $akb_variables ): string {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- contrato de wc_get_template().
			extract( $akb_variables );
			ob_start();
			include $akb_ruta;

			return (string) ob_get_clean();
		} )( $ruta, $variables );
	}
}
