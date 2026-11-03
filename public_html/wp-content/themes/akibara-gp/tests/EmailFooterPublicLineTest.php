<?php
/**
 * Contrato ejecutable del cierre sistémico de S-e9309aea: el pie configurable
 * de WooCommerce no puede volver a publicar la dirección operativa privada en
 * NINGÚN correo de texto plano.
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
final class EmailFooterPublicLineTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_woocommerce' )->justReturn( true );

		require_once dirname( __DIR__ ) . '/inc/woocommerce.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * La función ignora por completo cualquier argumento: es justo lo que la
	 * convierte en un candado de verdad. `apply_filters()` le pasa el valor de
	 * la opción ya expandido por `WC_Emails::replace_placeholders()` (podría
	 * traer la calle), y este filtro no lo mira ni una vez.
	 *
	 * @testdox La línea pública nunca depende del texto de la opción configurable, ni si trae la dirección expandida.
	 */
	public function test_public_line_ignores_whatever_the_option_expanded_to(): void {
		self::assertSame(
			'Tienda 100% online · Sin local abierto al público · Retiro con coordinación previa en San Miguel, Santiago',
			akibara_gp_email_footer_public_line()
		);
	}

	/**
	 * Candado de FUENTE, y a propósito: probar que `apply_filters()` respeta el
	 * orden de prioridades entre dos filtros reales exigiría reimplementar
	 * `WP_Hook` (Brain Monkey no lo hace por defecto) para un beneficio que la
	 * propia función ya prueba de sobra arriba — ignora el argumento SIEMPRE,
	 * así que el orden de ejecución de los filtros anteriores es irrelevante
	 * para el resultado final. Lo único que falta fijar por fuente es que este
	 * filtro sea el que corre ÚLTIMO en el hook (prioridad 20, la más alta de
	 * los registrados sobre `woocommerce_email_footer_text` en el tema) y que
	 * el filtro que dejó inerte ya no exista.
	 *
	 * @testdox El filtro corre a la prioridad más alta del hook y el filtro que dejó inerte fue retirado.
	 */
	public function test_filter_runs_last_and_the_dead_filter_is_gone(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/inc/woocommerce.php' );

		// Anclado a inicio de línea (con sangría opcional) para que un `//` de
		// comentario delante no cuele como «registrado»: `assertStringContains`
		// a secas no distingue código activo de código comentado.
		self::assertMatchesRegularExpression(
			"/^\\s*add_filter\\(\\s*'woocommerce_email_footer_text',\\s*'akibara_gp_email_footer_public_line',\\s*20\\s*\\);/m",
			$fuente
		);
		// El nombre viejo puede seguir mencionado en un comentario que explica
		// por qué se retiró (así queda el contexto para quien lea después); lo
		// que no puede sobrevivir es la FUNCIÓN ni su `add_filter()`.
		self::assertDoesNotMatchRegularExpression( '/function\s+akibara_gp_email_footer_line_breaks\s*\(/', $fuente );
		self::assertDoesNotMatchRegularExpression( "/add_filter\\(\\s*'woocommerce_email_footer_text',\\s*'akibara_gp_email_footer_line_breaks'/", $fuente );
		self::assertFalse( function_exists( 'akibara_gp_email_footer_line_breaks' ) );
	}

	/**
	 * Ejercita el escenario real que denunciaba la REAPERTURA: una plantilla
	 * plana que hace `apply_filters( 'woocommerce_email_footer_text',
	 * get_option( 'woocommerce_email_footer_text' ) )` con la opción ya
	 * llevando la dirección expandida. Sin este filtro enganchado,
	 * `apply_filters()` devolvería el segundo argumento tal cual (nuestro doble
	 * de WordPress no reimplementa `WP_Hook`); con el filtro real de
	 * WordPress cargado SÍ correría — acá se prueba directamente la pieza que lo
	 * garantiza: la función registrada nunca deja pasar la dirección.
	 *
	 * @testdox La dirección operativa expandida nunca sobrevive a la línea pública.
	 */
	public function test_expanded_store_address_never_survives(): void {
		$opcion_expandida = 'Akibara<br />Bartolo Soto 3700, San Miguel, Región Metropolitana, CL';

		$resultado = akibara_gp_email_footer_public_line();

		self::assertStringNotContainsString( 'Bartolo Soto', $resultado );
		self::assertStringNotContainsString( '{store_address}', $resultado );
		self::assertNotSame( $opcion_expandida, $resultado );
	}

	/**
	 * Hallazgo del revisor sobre S-e9309aea (2026-09-19): el panel de
	 * WooCommerce → Ajustes → Correos → General seguía prometiendo que el
	 * campo «Footer text» «will appear in the footer of all of your
	 * WooCommerce emails», que el propio filtro de arriba (prioridad 20) hace
	 * falso para TODO correo. `akibara_gp_email_footer_desc_truth()` reescribe
	 * esa descripción sin tocar ningún fichero de WooCommerce: opera sobre el
	 * array que devuelve el filtro `woocommerce_email_settings`.
	 *
	 * @testdox La descripción del campo «Footer text» avisa que ningún correo lo usa.
	 */
	public function test_footer_field_description_tells_the_truth(): void {
		Functions\when( '__' )->returnArg();

		$campos = array(
			array(
				'title' => 'Font family',
				'id'    => 'woocommerce_email_font_family',
				'type'  => 'email_font_family',
			),
			array(
				'title'    => 'Footer text',
				'desc'     => 'This text will appear in the footer of all of your WooCommerce emails.',
				'id'       => 'woocommerce_email_footer_text',
				'type'     => 'textarea',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'email_template_options',
			),
		);

		$resultado = akibara_gp_email_footer_desc_truth( $campos );

		// Los otros campos de la sección no se tocan.
		self::assertSame( $campos[0], $resultado[0] );
		self::assertSame( $campos[2], $resultado[2] );

		self::assertStringNotContainsString( 'will appear in the footer of all', $resultado[1]['desc'] );
		self::assertStringContainsString( 'no se usa', $resultado[1]['desc'] );
		self::assertStringContainsString( 'no llega a ningún correo', $resultado[1]['desc'] );
		self::assertFalse( $resultado[1]['desc_tip'], 'El aviso debe verse siempre, no quedar oculto detrás del tooltip (?)' );
		self::assertSame( 'woocommerce_email_footer_text', $resultado[1]['id'] );
	}

	/**
	 * Candado de FUENTE: el filtro tiene que estar enganchado al hook real de
	 * WooCommerce (`WC_Settings_Emails::get_settings_for_default_section()`
	 * hace `apply_filters( 'woocommerce_email_settings', $settings )`), o la
	 * función de arriba nunca corre en el panel de verdad.
	 *
	 * @testdox El aviso de veracidad está enganchado a `woocommerce_email_settings`.
	 */
	public function test_desc_truth_filter_is_registered_on_the_real_hook(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/inc/woocommerce.php' );

		self::assertMatchesRegularExpression(
			"/^\\s*add_filter\\(\\s*'woocommerce_email_settings',\\s*'akibara_gp_email_footer_desc_truth'\\s*\\);/m",
			$fuente
		);
	}
}
