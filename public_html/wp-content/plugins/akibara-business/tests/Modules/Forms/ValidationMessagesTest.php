<?php
/**
 * AI-5 / T5-R3: los avisos de validación del formulario de contacto son copys
 * del dueño en español, y el tema los recibe por `AkibaraFormsConfig.messages`.
 *
 * Sin ellos, akibara-forms.js pinta el `validationMessage` del navegador, que
 * sale en el idioma de su interfaz («Please fill out this field.»).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Forms;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\CopyManager\CopyManager;
use Akibara\Business\Modules\Forms\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ValidationMessagesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Hay un aviso por cada motivo por el que el formulario puede fallar, con su texto de fábrica en español.
	 */
	public function test_un_aviso_por_motivo(): void {
		Functions\when( 'get_option' )->alias( static fn( $option, $default_value = false ) => $default_value );

		$mensajes = Settings::messages();

		self::assertSame( array( 'valueMissing', 'typeMismatch', 'tooLong' ), array_keys( $mensajes ) );
		self::assertSame( 'Completa este campo.', $mensajes['valueMissing'] );
		self::assertStringContainsString( 'correo', $mensajes['typeMismatch'] );
	}

	/**
	 * El lector no puede ser más permisivo que el guardado: una option escrita a
	 * mano (WP-CLI, importación) vacía, con etiquetas o con saltos de línea sale
	 * igual que si la hubiera guardado el panel.
	 *
	 * @testdox Al leer, un aviso vacío vuelve al de fábrica y uno con etiquetas o saltos se limpia.
	 */
	public function test_el_lector_sanea_igual_que_el_guardado(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) {
				$guardado = array(
					'akb_copy_form_value_missing' => '   ',
					'akb_copy_form_type_mismatch' => "<script>x</script>Correo\nmal",
					'akb_copy_form_too_long'      => array( 'raro' ),
				);

				return $guardado[ $option ] ?? $default_value;
			}
		);

		$mensajes = Settings::messages();

		self::assertSame( 'Completa este campo.', $mensajes['valueMissing'] );
		self::assertStringNotContainsString( '<', $mensajes['typeMismatch'] );
		self::assertStringNotContainsString( "\n", $mensajes['typeMismatch'] );
		self::assertSame( Settings::MESSAGES['tooLong']['default'], $mensajes['tooLong'] );
	}

	/**
	 * @testdox Los tres avisos se editan en Textos, en la sección del formulario de contacto, y guardar vacío vuelve al de fábrica.
	 */
	public function test_se_editan_en_el_panel(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		$manager = new CopyManager( new AdminPanel() );
		$ids     = array();
		foreach ( $manager->get_settings() as $setting ) {
			if ( isset( $setting['id'] ) ) {
				$ids[] = $setting['id'];
			}
		}

		foreach ( Settings::message_defaults() as $option => $default ) {
			self::assertContains( $option, $ids, $option );
			self::assertSame( $default, $manager->sanitize_required_copy( '', array( 'id' => $option ), '  ' ) );
		}
		$seccion = array_search( 'akb_contacto_settings', $ids, true );
		self::assertGreaterThan( $seccion, array_search( 'akb_copy_form_value_missing', $ids, true ), 'Van dentro de la sección del formulario.' );
	}

	/**
	 * @testdox El formulario entrega los avisos al guion del tema en AkibaraFormsConfig.messages.
	 */
	public function test_se_entregan_al_tema(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Forms/Forms.php' );

		self::assertMatchesRegularExpression( "/'AkibaraFormsConfig',\\s*array\\([\\s\\S]*?'messages'\\s*=> Settings::messages\\(\\)/s", $fuente );
	}
}
