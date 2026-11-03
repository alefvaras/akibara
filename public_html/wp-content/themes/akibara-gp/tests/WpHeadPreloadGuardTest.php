<?php
/**
 * Candado de la guarda de WooCommerce en el closure de `wp_head` (prioridad 2)
 * de `functions.php`.
 *
 * REAPERTURA S-bf0c1196 (2026-09-19): este closure llama `is_product()` sin
 * ninguna guarda. Corre en TODAS las peticiones de front (`wp_head`), así que
 * sin WooCommerce activo —desactivado, en cuarentena de Imunify, un deploy a
 * medias— fatalea el sitio entero.
 *
 * Candado de FUENTE, y a propósito: el closure es anónimo (`add_action(
 * 'wp_head', function() {...}, 2 )`), no una función con nombre, así que
 * ejercerlo de verdad exigiría cargar TODO `functions.php` —que encadena otros
 * 19 módulos del tema— sólo para exponerlo. Es desproporcionado para una
 * guarda de una línea; `akibara_gp_wc_activo()` en sí YA tiene su prueba de
 * comportamiento (`PageTargetsTest`), y `akibara_gp_distrito_slug()` —la otra
 * superficie de esta misma REAPERTURA— tiene la suya en `WooCommerceHooksTest`.
 * Lo que falta fijar aquí es que la guarda esté, y que esté ANTES de la
 * primera llamada a una condicional de WooCommerce dentro del closure.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class WpHeadPreloadGuardTest extends TestCase {

	/**
	 * Aísla el cuerpo del closure registrado en `add_action( 'wp_head',
	 * function() {...}, 2 );` sin ejecutar el fichero.
	 */
	private function closure_body(): string {
		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/functions.php' );

		$inicio = strpos( $fuente, "add_action( 'wp_head', function() {" );
		self::assertIsInt( $inicio, 'No se encontró el closure de wp_head prioridad 2 en functions.php.' );

		$fin = strpos( $fuente, "}, 2 );", $inicio );
		self::assertIsInt( $fin, 'No se encontró el cierre `}, 2 );` del closure de wp_head.' );

		return substr( $fuente, $inicio, $fin - $inicio );
	}

	/**
	 * @testdox El closure de `wp_head` prioridad 2 sale temprano sin WooCommerce, antes de llamar cualquier condicional suya.
	 */
	public function test_the_closure_bails_before_any_woocommerce_conditional(): void {
		$cuerpo = $this->closure_body();

		$guarda = strpos( $cuerpo, 'akibara_gp_wc_activo()' );
		// El USO real de la condicional, no su mención en un comentario: el
		// docblock del guarda cita `is_product()` a propósito para explicar el
		// hallazgo, y ese texto aparece ANTES que la guarda en la fuente.
		$is_product = strpos( $cuerpo, '! is_product()' );

		self::assertIsInt( $guarda, 'El closure no llama a akibara_gp_wc_activo().' );
		self::assertIsInt( $is_product, 'El closure ya no llama a is_product(): actualizar este candado si cambió la condicional que dispara el fatal.' );
		self::assertLessThan( $is_product, $guarda, 'La guarda de WooCommerce tiene que ir ANTES de is_product(), o el fatal ya ocurrió.' );

		self::assertMatchesRegularExpression(
			'/if\s*\(\s*!\s*akibara_gp_wc_activo\(\)\s*\)\s*\{\s*\n\s*return;\s*\n\s*\}/',
			$cuerpo,
			'La guarda debe cortar la ejecución (return) sin WooCommerce, no sólo consultarla.'
		);
	}
}
