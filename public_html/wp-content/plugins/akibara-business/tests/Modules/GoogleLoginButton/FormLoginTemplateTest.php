<?php
/**
 * Regression tests for the customer login template.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\GoogleLoginButton;

use Akibara\Business\Modules\GoogleLoginButton\AccessWall;
use PHPUnit\Framework\TestCase;

final class FormLoginTemplateTest extends TestCase {

	private function template(): string {
		return (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/templates/myaccount/form-login.php'
		);
	}

	/**
	 * @testdox Should complement the page H1 with one descriptive H2 instead of repeating Mi cuenta.
	 */
	public function test_template_has_one_non_redundant_section_heading(): void {
		$template = $this->template();

		self::assertSame(
			1,
			preg_match_all( '/<h([1-6])\\b[^>]*>/i', $template, $headings ),
			'The account access template should expose one section heading.'
		);
		self::assertSame( array( '2' ), $headings[1], 'The section heading should follow the page H1.' );
		self::assertStringNotContainsString( "esc_html_e( 'Mi cuenta', 'akibara-gp' )", $template );
	}

	/**
	 * El encabezado ya no vive cableado en la plantilla: cambiarlo era editar
	 * código, y por eso el muro de `/mi-cuenta/favoritos/` decía «Elige cómo
	 * continuar para comprar…» sin nombrar los favoritos ni una vez.
	 *
	 * @testdox El título y la bajada salen de AccessWall, no de literales en la plantilla.
	 */
	public function test_the_heading_comes_from_the_editable_copy(): void {
		$template = $this->template();

		self::assertStringContainsString( 'AccessWall::current_endpoint()', $template );
		self::assertStringContainsString( 'AccessWall::copy(', $template );
		self::assertStringContainsString( "esc_html( \$akb_wall_copy['title'] )", $template );
		self::assertStringContainsString( "esc_html( \$akb_wall_copy['sub'] )", $template );

		foreach ( AccessWall::defaults() as $literal ) {
			self::assertStringNotContainsString(
				$literal,
				$template,
				'Un texto cableado en la plantilla es un texto que el dueño no puede cambiar desde Textos.'
			);
		}
	}

	/**
	 * @testdox Los textos generales siguen siendo los de siempre.
	 */
	public function test_the_general_wall_keeps_its_historic_wording(): void {
		$defaults = AccessWall::defaults();

		self::assertSame( 'Accede a Akibara', $defaults[ AccessWall::OPTION_TITLE ] );
		self::assertSame(
			'Elige cómo continuar para comprar, seguir tus pedidos y gestionar tus preventas.',
			$defaults[ AccessWall::OPTION_SUB ]
		);
		self::assertSame(
			'Inicia sesión para seguir tus pedidos y gestionar tus preventas.',
			$defaults[ AccessWall::OPTION_SUB_LOGIN ]
		);
	}

	/**
	 * @testdox La línea que nombra el tomo del que viene el cliente se pinta en el encabezado.
	 */
	public function test_the_template_prints_the_return_context(): void {
		$template = $this->template();

		self::assertStringContainsString( 'AccessWall::return_notice()', $template );
		self::assertStringContainsString( 'akb-account-auth__context', $template );
	}

	/**
	 * WooCommerce honra `$_POST['redirect']` al iniciar sesión Y al crear la
	 * cuenta; sin ese campo cae al `Referer`, que en un envío desde el muro es
	 * el muro mismo. Por Google sí volvía, así que la promesa de vuelta se
	 * cumplía o no según el botón que se pulsara.
	 *
	 * @testdox Los DOS formularios llevan el destino de vuelta, no sólo el botón de Google.
	 */
	public function test_both_forms_carry_the_redirect_destination(): void {
		$template = $this->template();

		self::assertSame(
			2,
			substr_count( $template, 'name="redirect"' ),
			'El campo tiene que ir en el de iniciar sesión y en el de crear cuenta.'
		);
		self::assertStringContainsString( 'AccessWall::redirect_field_value(', $template );
		self::assertStringContainsString( 'esc_url( $akb_wall_redirect )', $template );
	}

	/**
	 * @testdox La cabecera @version de nuestro override coincide con la de WooCommerce Core.
	 */
	public function test_override_template_version_matches_woocommerce_core(): void {
		$override = $this->template();
		$core_path = dirname( __DIR__, 4 ) . '/woocommerce/templates/myaccount/form-login.php';

		self::assertFileExists( $core_path, 'El template base de WooCommerce debe existir para comparar versiones.' );
		$core = (string) file_get_contents( $core_path );

		preg_match( '/@version\s+([0-9.]+)/i', $core, $core_match );
		preg_match( '/@version\s+([0-9.]+)/i', $override, $override_match );

		self::assertNotEmpty( $core_match[1] ?? '', 'WooCommerce core form-login.php debe declarar @version.' );
		self::assertNotEmpty( $override_match[1] ?? '', 'El override form-login.php debe declarar @version.' );

		self::assertSame(
			$core_match[1],
			$override_match[1],
			sprintf(
				'El @version de nuestro override (%s) difiere del de WooCommerce Core (%s). WooCommerce actualizó la plantilla y se deben auditar posibles cambios.',
				$override_match[1],
				$core_match[1]
			)
		);
	}

	/**
	 * @testdox Ambos formularios (acceso y registro) cuentan con sus respectivos nonces de seguridad de WooCommerce.
	 */
	public function test_login_and_register_forms_have_security_nonces(): void {
		$template = $this->template();

		self::assertStringContainsString( 'woocommerce-login-nonce', $template );
		self::assertStringContainsString( 'woocommerce-register-nonce', $template );
		self::assertStringContainsString( "wp_nonce_field( 'woocommerce-login'", $template );
		self::assertStringContainsString( "wp_nonce_field( 'woocommerce-register'", $template );
	}
}
