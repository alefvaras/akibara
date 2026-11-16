<?php
/**
 * La calidad AVIF: una sola noción de validez para guardar y leer, y un filtro
 * que sólo toca AVIF.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\ImageQuality;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\ImageQuality\ImageQuality;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ImageQualityTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();


	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function module(): ImageQuality {
		return new ImageQuality( new AdminPanel() );
	}

	/** @return array<string, array{0: mixed, 1: ?int}> */
	public static function values(): array {
		return array(
			'entero en rango'      => array( 60, 60 ),
			'texto en rango'       => array( '55', 55 ),
			'con espacios'         => array( ' 40 ', 40 ),
			'borde superior'       => array( '90', 90 ),
			'bajo el mínimo'       => array( '39', null ),
			'sobre el máximo'      => array( 91, null ),
			'decimal'              => array( '60.5', null ),
			'con porcentaje'       => array( '60%', null ),
			'vacío'                => array( '', null ),
			'negativo'             => array( '-60', null ),
			'nulo'                 => array( null, null ),
			'flotante'             => array( 60.0, null ),
		);
	}

	/**
	 * @dataProvider values
	 * @testdox Validez de la calidad: $valor → $esperado
	 *
	 * @param mixed    $valor    Lo enviado o guardado.
	 * @param int|null $esperado Calidad o null.
	 */
	public function test_quality_has_one_notion_of_validity( $valor, ?int $esperado ): void {
		self::assertSame( $esperado, ImageQuality::parse_quality( $valor ) );
	}

	/**
	 * @testdox Sin fila o con una fila inválida rige 60; con una válida, la guardada.
	 */
	public function test_current_quality_falls_back_to_the_owner_choice(): void {
		self::assertSame( 60, ImageQuality::current_quality() );

		$this->options[ ImageQuality::OPTION_QUALITY ] = 'abc';
		self::assertSame( 60, ImageQuality::current_quality() );

		$this->options[ ImageQuality::OPTION_QUALITY ] = '50';
		self::assertSame( 50, ImageQuality::current_quality() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @testdox La constante Twelve-Factor AKB_IMAGE_QUALITY_AVIF gobierna sobre la option o el default.
	 */
	public function test_constant_governs_image_quality(): void {
		define( 'AKB_IMAGE_QUALITY_AVIF', 75 );
		self::assertSame( 75, ImageQuality::current_quality() );
	}

	/**
	 * @testdox El filtro fija la calidad de AVIF y deja intactos JPEG y WebP.
	 */
	public function test_filter_only_touches_avif(): void {
		$this->options[ ImageQuality::OPTION_QUALITY ] = '55';
		$modulo = $this->module();

		self::assertSame( 55, $modulo->filter_quality( 82, 'image/avif' ) );
		self::assertSame( 82, $modulo->filter_quality( 82, 'image/jpeg' ) );
		self::assertSame( 86, $modulo->filter_quality( 86, 'image/webp' ) );
	}

	/**
	 * @testdox Una calidad inválida detiene el guardado de su pestaña, y sólo de ella.
	 */
	public function test_invalid_quality_blocks_only_its_tab(): void {
		$modulo = $this->module();

		$_POST[ ImageQuality::OPTION_QUALITY ] = '30';
		$errores = $modulo->validate_save( array(), ImageQuality::PANEL_TAB_ID );
		self::assertCount( 1, $errores );
		self::assertStringContainsString( 'entre 40 y 90', $errores[0] );

		self::assertSame( array(), $modulo->validate_save( array(), 'address' ), 'Otra pestaña no mira este campo.' );

		$_POST[ ImageQuality::OPTION_QUALITY ] = '60';
		self::assertSame( array(), $modulo->validate_save( array(), ImageQuality::PANEL_TAB_ID ) );

		unset( $_POST[ ImageQuality::OPTION_QUALITY ] );
		self::assertSame( array(), $modulo->validate_save( array(), ImageQuality::PANEL_TAB_ID ) );
	}

	/**
	 * @testdox Lo que se escribe es la calidad normalizada, nunca un valor inválido.
	 */
	public function test_saved_value_is_always_valid(): void {
		self::assertSame( '45', ImageQuality::sanitize_for_save( ' 45 ' ) );

		$this->options[ ImageQuality::OPTION_QUALITY ] = '70';
		self::assertSame( '70', ImageQuality::sanitize_for_save( '9000' ) );
		self::assertSame( '70', ImageQuality::sanitize_for_save( null ), 'Campo ausente: queda la vigente.' );
	}

	/**
	 * @testdox El campo del panel declara el rango y el valor por defecto que valida el código.
	 */
	public function test_panel_field_matches_the_validator(): void {
		$campos = array_column( $this->module()->get_settings(), null, 'id' );
		$campo  = $campos[ ImageQuality::OPTION_QUALITY ];

		self::assertSame( 'number', $campo['type'] );
		self::assertSame( '60', $campo['default'] );
		self::assertSame( ImageQuality::MIN_QUALITY, $campo['custom_attributes']['min'] );
		self::assertSame( ImageQuality::MAX_QUALITY, $campo['custom_attributes']['max'] );
	}

	/**
	 * @testdox La pestaña de Imágenes se registra limpia sin botón manual de recompresión (la optimización es previa en Image Studio).
	 */
	public function test_admin_tab_registers_cleanly_without_manual_recompress_header(): void {
		$panel = new AdminPanel();
		$modulo = new ImageQuality( $panel );
		$modulo->register_admin_tab();

		$reflector = new \ReflectionClass( $panel );
		$property  = $reflector->getProperty( 'sections' );
		$property->setAccessible( true );
		$sections = $property->getValue( $panel );

		self::assertArrayHasKey( ImageQuality::PANEL_TAB_ID, $sections );
		self::assertNull( $sections[ ImageQuality::PANEL_TAB_ID ]['header_fn'], 'No debe existir header_fn con botón manual de recompresión.' );
	}
}
