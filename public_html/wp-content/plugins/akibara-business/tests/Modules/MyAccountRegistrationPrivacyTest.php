<?php
/**
 * Candado: el alta de /mi-cuenta/ conserva el hook que pinta la política de privacidad.
 *
 * S-6ec94115 (2026-09-19): el hallazgo decía que el override
 * `templates/myaccount/form-login.php` ya no emitía `woocommerce_register_form`
 * y que, por eso, `wc_registration_privacy_policy_text()` (enganchada ahí por
 * WooCommerce core, ver `wc-template-hooks.php:314`) dejaba de pintar «Sus
 * datos personales se utilizarán para…» bajo el formulario de crear cuenta.
 *
 * REFUTADO: leído el código vigente, el `do_action( 'woocommerce_register_form' )`
 * sigue ahí, sin envolver en comentario ni condición propia (más allá de que
 * el formulario entero sólo se pinta si el alta está habilitada, que es el
 * comportamiento normal de WooCommerce). Medido en el espejo el 2026-09-19
 * con `woocommerce_enable_myaccount_registration=yes` y una página de
 * política de privacidad configurada (`wp_page_for_privacy_policy=23640`):
 * GET /mi-cuenta/ devuelve 200 y el HTML trae
 * `<div class="woocommerce-privacy-policy-text">` DENTRO del
 * `<form class="woocommerce-form-register register">`, antes del botón
 * «Crear cuenta».
 *
 * Este candado fija la propiedad de código que hace eso posible: los NUEVE
 * `do_action()` de plantilla de WooCommerce que trae el override siguen
 * presentes y activos, para que un futuro refactor no los borre ni los
 * comente en silencio.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules;

use PHPUnit\Framework\TestCase;

final class MyAccountRegistrationPrivacyTest extends TestCase {

	/**
	 * @testdox Los nueve hooks de plantilla de WooCommerce del formulario de acceso siguen activos.
	 */
	public function test_all_woocommerce_template_hooks_are_still_live(): void {
		$path = dirname( __DIR__, 2 ) . '/templates/myaccount/form-login.php';
		$src  = (string) file_get_contents( $path );

		// Sin comentarios ni bloques /* */: un hook nombrado sólo en una nota
		// de phpcs:ignore no es un `do_action()` real.
		$sin_comentarios = (string) preg_replace( '#/\*[\s\S]*?\*/|(?<!:)//[^\n]*#', '', $src );

		$hooks = array(
			'woocommerce_before_customer_login_form',
			'woocommerce_login_form_start',
			'woocommerce_login_form',
			'woocommerce_login_form_end',
			'woocommerce_register_form_tag',
			'woocommerce_register_form_start',
			// El hook clave del hallazgo: WooCommerce cuelga ahí
			// wc_registration_privacy_policy_text() a prioridad 20.
			'woocommerce_register_form',
			'woocommerce_register_form_end',
			'woocommerce_after_customer_login_form',
		);

		foreach ( $hooks as $hook ) {
			self::assertMatchesRegularExpression(
				'/do_action\(\s*\'' . preg_quote( $hook, '/' ) . '\'\s*\)/',
				$sin_comentarios,
				sprintf( 'El hook `%s` debe seguir emitiéndose sin envolver en comentario.', $hook )
			);
		}
	}

	/**
	 * @testdox `woocommerce_register_form` se emite DENTRO del `<form>` de registro, no fuera.
	 *
	 * Un `do_action()` movido fuera del `<form>` seguiría "presente" para el
	 * test anterior pero pintaría el texto de privacidad en un lugar donde no
	 * pertenece al formulario que el cliente envía.
	 */
	public function test_register_form_hook_is_inside_the_register_form_tag(): void {
		$path = dirname( __DIR__, 2 ) . '/templates/myaccount/form-login.php';
		$src  = (string) file_get_contents( $path );

		$pos_form_open  = strpos( $src, 'woocommerce-form-register register' );
		$pos_hook       = strpos( $src, "do_action( 'woocommerce_register_form' )" );
		$pos_form_close = strpos( $src, "do_action( 'woocommerce_register_form_end' )" );

		self::assertNotFalse( $pos_form_open );
		self::assertNotFalse( $pos_hook );
		self::assertNotFalse( $pos_form_close );
		self::assertGreaterThan( $pos_form_open, $pos_hook, 'El hook va después de abrir el <form> de registro.' );
		self::assertLessThan( $pos_form_close, $pos_hook, 'El hook va antes de cerrar el <form> de registro.' );
	}
}
