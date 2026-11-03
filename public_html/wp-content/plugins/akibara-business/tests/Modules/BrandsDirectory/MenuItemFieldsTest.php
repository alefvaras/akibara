<?php
/**
 * Tests del sync de clases mágicas del megamenú desde los campos nativos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BrandsDirectory;

use Akibara\Business\Modules\BrandsDirectory\MenuInjector;
use Akibara\Business\Modules\BrandsDirectory\MenuItemFields;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class MenuItemFieldsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $original_post;

	private MenuItemFields $fields;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->original_post = $_POST;
		$_POST               = array();
		$this->fields        = new MenuItemFields();
	}

	protected function tearDown(): void {
		$_POST = $this->original_post;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should be a no-op when the screen did not post its fields.
	 */
	public function test_programmatic_save_without_fields_is_a_no_op(): void {
		Functions\expect( 'update_post_meta' )->never();

		$this->fields->save_fields( 5, 42 );

		self::assertTrue( true ); // La expectativa de Monkey es el assert real.
	}

	/**
	 * @testdox Should write the selected magic classes preserving foreign ones.
	 */
	public function test_selected_controls_write_managed_classes(): void {
		$_POST['akb-menu-fields-present'][42] = '1';
		$_POST['akb-menu-mega'][42]           = '1';
		$_POST['akb-menu-layout'][42]         = 'mainnav__sub--auto';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'get_post_meta' )->justReturn( array( 'mi-clase-custom', 'mainnav__sub--cols-2' ) );

		Functions\expect( 'update_post_meta' )
			->once()
			->with(
				42,
				'_menu_item_classes',
				array( 'mi-clase-custom', MenuInjector::TRIGGER_CLASS, 'mainnav__sub--auto' )
			)
			->andReturn( true );

		$this->fields->save_fields( 5, 42 );

		self::assertTrue( true );
	}

	/**
	 * @testdox Should strip every managed class when the controls are cleared.
	 */
	public function test_unchecked_controls_remove_managed_classes(): void {
		$_POST['akb-menu-fields-present'][42] = '1';
		$_POST['akb-menu-layout'][42]         = '';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'get_post_meta' )->justReturn(
			array( 'akb-brands-mega', 'mainnav__sub--auto', 'mainnav__sub-label', 'otra-clase' )
		);

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_menu_item_classes', array( 'otra-clase' ) )
			->andReturn( true );

		$this->fields->save_fields( 5, 42 );

		self::assertTrue( true );
	}

	/**
	 * @testdox Should reject an unknown layout value.
	 */
	public function test_unknown_layout_falls_back_to_none(): void {
		$_POST['akb-menu-fields-present'][42] = '1';
		$_POST['akb-menu-layout'][42]         = 'mainnav__sub--cols-99';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'get_post_meta' )->justReturn( array() );

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 42, '_menu_item_classes', array() )
			->andReturn( true );

		$this->fields->save_fields( 5, 42 );

		self::assertTrue( true );
	}

	/**
	 * @testdox Should refuse to write without the menus capability.
	 */
	public function test_without_capability_is_a_no_op(): void {
		$_POST['akb-menu-fields-present'][42] = '1';
		$_POST['akb-menu-mega'][42]           = '1';

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->never();

		$this->fields->save_fields( 5, 42 );

		self::assertTrue( true );
	}
}
