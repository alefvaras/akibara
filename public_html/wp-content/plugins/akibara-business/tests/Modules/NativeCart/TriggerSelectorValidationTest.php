<?php
/**
 * Tests del validador del "Selector de activadores" del carrito.
 *
 * Regresión de C5 (auditoría 2026-07-28): un texto libre guardado en ese campo
 * hacía que `closest()` lanzara SyntaxError y el carrito dejaba de abrirse, sin
 * ningún aviso en el admin.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class TriggerSelectorValidationTest extends TestCase {

	/** @var array<string, mixed> */
	private array $original_post;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->original_post = $_POST;
		$_POST               = array();

		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_strip_all_tags' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		$_POST = $this->original_post;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider valid_selectors
	 * @testdox Should accept a usable trigger selector.
	 *
	 * @param string $selector Selector candidato.
	 */
	public function test_valid_selectors_are_accepted( string $selector ): void {
		self::assertTrue( Settings::is_valid_trigger_selector( $selector ), $selector );
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	public static function valid_selectors(): array {
		return array(
			array( '.akb-cart-trigger' ),
			array( '#mi-boton' ),
			array( 'a.header-icon--cart' ),
			array( '[data-akb-cart]' ),
			array( '.header .cart-link' ),
			array( '.menu > .item.cart' ),
			array( 'button.cart:not(.disabled)' ),
		);
	}

	/**
	 * @dataProvider invalid_selectors
	 * @testdox Should reject text that is not a usable trigger selector.
	 *
	 * @param string $selector Selector candidato.
	 */
	public function test_invalid_selectors_are_rejected( string $selector ): void {
		self::assertFalse( Settings::is_valid_trigger_selector( $selector ), $selector );
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	public static function invalid_selectors(): array {
		return array(
			array( 'boton rojo del header' ),   // prosa: CSS válido, intención imposible.
			array( 'el carrito' ),
			array( '.roto{color:red}' ),
			array( '.x");alert(1)//' ),
			array( 'div' ),                      // sin clase/id/atributo.
			array( '..dos-puntos' ),
		);
	}

	/**
	 * @testdox Should block the save with an explanatory error.
	 */
	public function test_invalid_value_blocks_the_save(): void {
		$_POST[ Settings::OPTION_TRIGGER_SELECTOR ] = 'boton rojo del header';

		$errors = ( new Settings() )->validate_trigger_selector( array(), Settings::PANEL_TAB_ID );

		self::assertCount( 1, $errors );
		self::assertStringContainsString( 'no es un selector CSS válido', $errors[0] );
	}

	/**
	 * @testdox Should treat an empty value as "restore the default".
	 */
	public function test_empty_value_is_allowed_and_restores_default(): void {
		$_POST[ Settings::OPTION_TRIGGER_SELECTOR ] = '';

		self::assertSame( array(), ( new Settings() )->validate_trigger_selector( array(), Settings::PANEL_TAB_ID ) );
		self::assertSame( Settings::DEFAULT_TRIGGER_SELECTOR, Settings::sanitize_trigger_selector( '' ) );
	}

	/**
	 * @testdox Should keep the valid selectors of a mixed list when sanitizing.
	 */
	public function test_sanitizer_drops_only_the_invalid_parts(): void {
		self::assertSame(
			'.akb-cart-trigger, #otro',
			Settings::sanitize_trigger_selector( '.akb-cart-trigger, texto libre, #otro' )
		);
	}

	/**
	 * @testdox Should ignore other panel tabs.
	 */
	public function test_other_tab_is_ignored(): void {
		$_POST[ Settings::OPTION_TRIGGER_SELECTOR ] = 'boton rojo del header';

		self::assertSame( array(), ( new Settings() )->validate_trigger_selector( array(), 'copys' ) );
	}
}
