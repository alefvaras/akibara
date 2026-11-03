<?php
/**
 * Candado de fuente: R12-email-o-correo (decisión del dueño, 2026-09-20).
 *
 * La casa dice «correo». El 2026-09-20 había veintitrés literales propios de
 * cara al cliente que decían «email» (formularios, plantillas de correo,
 * respuestas de la API, placeholder, JS). La primera pasada arregló seis y la
 * revisión independiente encontró diecisiete más: por eso este candado lee los
 * ficheros, no una lista de seis. Si alguien vuelve a escribir «email» en un
 * texto que lee un cliente, esto se pone rojo.
 *
 * Se excluyen a propósito los usos técnicos (atributos `type="email"`,
 * `name="email"`, `autocomplete`, claves de arrays, nombres de variables,
 * comentarios, el `id`/`for` de los campos y las direcciones reales).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class LaCasaDiceCorreoNoEmailTest extends TestCase {

	/** Frases que un cliente leería y que la casa escribe con «correo». */
	private const FRASES_PROHIBIDAS = array(
		'por email',
		'este email',
		'un email ',
		'Tu email',
		'tu email',
		'Este email',
		'email válido',
		'email y mensaje',
		'tu@email',
		'>Email:<',
		'>Email <',
		'Deja tu email',
		// La versión de texto plano del pie de contacto («Email: hola@…»).
		"'Email: '",
	);

	/** @return array<string, array{0: string}> */
	public static function superficies(): array {
		$plugin = dirname( __DIR__ );
		$tema   = dirname( __DIR__, 3 ) . '/themes/akibara-gp';
		$rutas  = array(
			$plugin . '/src/Modules/BackInStock/CaptureForm.php',
			$plugin . '/src/Modules/BackInStock/CaptureRestController.php',
			$plugin . '/src/Modules/Forms/ContactoSubmit.php',
			$plugin . '/src/Modules/PreventasRoute/PreventasRoute.php',
			$plugin . '/src/Modules/OrderPreventa/CustomerVisibility.php',
			$plugin . '/src/Modules/OrderPreventa/ContactFooter.php',
			$plugin . '/src/Modules/WelcomeCoupon/PopupRenderer.php',
			$plugin . '/src/Modules/WelcomeCoupon/RestController.php',
			$plugin . '/assets/js/bis-form.js',
			$plugin . '/assets/js/welcome-popup.js',
			$plugin . '/templates/emails/customer-preventa-ready.php',
			$plugin . '/templates/emails/plain/customer-preventa-ready.php',
			$plugin . '/templates/emails/customer-preventa-reminder.php',
			$plugin . '/templates/emails/plain/customer-preventa-reminder.php',
			$tema . '/template-parts/forms/contacto.php',
			$tema . '/woocommerce/emails/customer-reset-password.php',
			$tema . '/woocommerce/emails/plain/customer-reset-password.php',
		);
		$casos  = array();
		foreach ( $rutas as $ruta ) {
			$casos[ basename( dirname( $ruta ) ) . '/' . basename( $ruta ) ] = array( $ruta );
		}
		return $casos;
	}

	/**
	 * @testdox Ningún texto de cara al cliente dice «email»
	 * @dataProvider superficies
	 */
	public function test_ningun_texto_de_cara_al_cliente_dice_email( string $ruta ): void {
		self::assertFileExists( $ruta );
		$fuente = (string) file_get_contents( $ruta );
		foreach ( self::FRASES_PROHIBIDAS as $frase ) {
			self::assertStringNotContainsString( $frase, $fuente, sprintf( '«%s» en %s: la casa dice «correo».', $frase, basename( $ruta ) ) );
		}
	}

	/** @testdox Los seis literales de la primera pasada dicen «correo» */
	public function test_los_literales_de_la_primera_pasada_dicen_correo(): void {
		$plugin = dirname( __DIR__ );
		$tema   = dirname( __DIR__, 3 ) . '/themes/akibara-gp';
		$form   = (string) file_get_contents( $plugin . '/src/Modules/BackInStock/CaptureForm.php' );
		self::assertStringContainsString( 'Deja tu correo y te avisamos', $form );
		self::assertStringContainsString( '>Tu correo</label>', $form );
		self::assertStringContainsString( 'placeholder="tu@correo.cl"', $form );
		self::assertStringContainsString( '>Correo <span', (string) file_get_contents( $tema . '/template-parts/forms/contacto.php' ) );
		self::assertStringContainsString( 'te avisamos por correo apenas llegan', (string) file_get_contents( $plugin . '/src/Modules/PreventasRoute/PreventasRoute.php' ) );
		self::assertSame( 2, substr_count( (string) file_get_contents( $plugin . '/src/Modules/OrderPreventa/CustomerVisibility.php' ), 'Te avisaremos por correo cuando los productos lleguen' ) );
		self::assertStringContainsString( '>Correo:</span>', (string) file_get_contents( $plugin . '/src/Modules/OrderPreventa/ContactFooter.php' ) );
	}
}
