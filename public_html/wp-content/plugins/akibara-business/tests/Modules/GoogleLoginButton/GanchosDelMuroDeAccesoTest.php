<?php
/**
 * Los ocho ganchos del muro de acceso tienen que EJECUTARSE.
 *
 * Hallazgo S-42e147f3: cada uno llevaba delante un `// phpcs:ignore …` en la
 * MISMA línea, y el comentario de una barra llega hasta el final de la línea:
 * el `do_action()` que iba detrás nunca corría. Desde fuera se ve como que
 * WooCommerce y sus extensiones no pueden añadir nada al formulario de entrar
 * ni al de crear cuenta (un captcha, un campo, un aviso legal): el sitio los
 * ignora en silencio.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\GoogleLoginButton;

use PHPUnit\Framework\TestCase;

final class GanchosDelMuroDeAccesoTest extends TestCase {

	/** @return array<int, string> */
	private static function hooks(): array {
		return array(
			'woocommerce_before_customer_login_form',
			'woocommerce_login_form_start',
			'woocommerce_login_form',
			'woocommerce_login_form_end',
			'woocommerce_register_form_tag',
			'woocommerce_register_form_start',
			'woocommerce_register_form',
			'woocommerce_register_form_end',
			'woocommerce_after_customer_login_form',
		);
	}

	/** @testdox Ningún gancho del muro queda dentro de un comentario. */
	public function test_every_hook_is_live_code(): void {
		$ruta   = dirname( __DIR__, 3 ) . '/templates/myaccount/form-login.php';
		$codigo = '';
		foreach ( token_get_all( (string) file_get_contents( $ruta ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$codigo .= is_array( $token ) ? $token[1] : $token;
		}

		foreach ( self::hooks() as $hook ) {
			self::assertStringContainsString(
				"do_action( '{$hook}' )",
				$codigo,
				"«{$hook}» no se ejecuta: quedó dentro de un comentario y nadie puede engancharse al formulario."
			);
		}
	}
}
