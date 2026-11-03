<?php
/**
 * Tests for taxonomy discount admin save safeguards.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\AdminFieldType;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Modules\TaxonomyDiscount\TaxonomyDiscount;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AdminSafetyTest extends TestCase {

	/** @var array<string, mixed> */
	private array $original_post;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->original_post = $_POST;
		$_POST               = array();
	}

	protected function tearDown(): void {
		$_POST = $this->original_post;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should preserve a term rule when PHP omitted its field.
	 */
	public function test_missing_term_field_is_a_no_op(): void {
		( new AdminFieldType( new Settings() ) )->save_row( array( 'term_id' => 42 ) );

		self::assertSame( array(), $_POST );
	}

	/**
	 * @testdox Should still delete a rule when the operator explicitly clears it.
	 */
	public function test_explicitly_empty_term_field_deletes_the_rule(): void {
		$_POST['akibara_taxonomy_discount_pct_42'] = '';

		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
		Functions\expect( 'delete_term_meta' )
			->once()
			->with( 42, Settings::TERM_META_PCT )
			->andReturn( true );
		Functions\when( 'do_action' )->justReturn( null );

		( new AdminFieldType( new Settings() ) )->save_row( array( 'term_id' => 42 ) );
		self::addToAssertionCount( 1 ); // Mockery expectation is verified in tearDown.
	}

	/**
	 * Invariante de coste del guardado: sólo se escribe lo que cambió.
	 *
	 * El formulario postea ~277 filas aunque el dueño toque una. Cada escritura
	 * de `_akb_taxonomy_discount_pct` —incluido el `delete` de una fila que NO
	 * existe, porque el filtro `delete_term_metadata` corre ANTES de que
	 * WordPress compruebe la existencia— dispara una publicación editorial
	 * completa en akibara-search y encola el catálogo del término. Medido en el
	 * espejo el 2026-09-05: un guardado sin tocar nada subió `wanted_seq` en
	 * ~277 y dejó 7.793 filas `pending` y 401 `blocked`.
	 *
	 * @testdox Una casilla vacía que YA estaba vacía no borra nada.
	 */
	public function test_an_untouched_empty_row_writes_nothing(): void {
		$_POST['akibara_taxonomy_discount_pct_42'] = '';

		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\expect( 'delete_term_meta' )->never();
		Functions\expect( 'update_term_meta' )->never();
		Functions\when( 'do_action' )->justReturn( null );

		( new AdminFieldType( new Settings() ) )->save_row(
			array( 'term_id' => 42, 'current_pct' => 0 )
		);
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox Una casilla con el MISMO porcentaje con el que se pintó no reescribe.
	 */
	public function test_an_untouched_row_with_a_rule_writes_nothing(): void {
		$_POST['akibara_taxonomy_discount_pct_42'] = '10';

		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\expect( 'delete_term_meta' )->never();
		Functions\expect( 'update_term_meta' )->never();
		Functions\when( 'do_action' )->justReturn( null );

		( new AdminFieldType( new Settings() ) )->save_row(
			array( 'term_id' => 42, 'current_pct' => 10 )
		);
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox Un cambio REAL sí se escribe: la guarda no puede tragarse el cambio del dueño.
	 */
	public function test_a_real_change_is_still_written(): void {
		$_POST['akibara_taxonomy_discount_pct_42'] = '10';

		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\expect( 'update_term_meta' )
			->once()
			->with( 42, Settings::TERM_META_PCT, 10 )
			->andReturn( true );
		Functions\when( 'do_action' )->justReturn( null );

		( new AdminFieldType( new Settings() ) )->save_row(
			array( 'term_id' => 42, 'current_pct' => 0 )
		);
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox Vaciar una casilla que tenía porcentaje SÍ borra la regla.
	 */
	public function test_clearing_a_row_that_had_a_rule_deletes_it(): void {
		$_POST['akibara_taxonomy_discount_pct_42'] = '';

		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\expect( 'delete_term_meta' )
			->once()
			->with( 42, Settings::TERM_META_PCT )
			->andReturn( true );
		Functions\when( 'do_action' )->justReturn( null );

		( new AdminFieldType( new Settings() ) )->save_row(
			array( 'term_id' => 42, 'current_pct' => 10 )
		);
		self::addToAssertionCount( 1 );
	}

	/**
	 * @testdox Should reject a truncated taxonomy discount payload before saving.
	 */
	public function test_payload_guard_rejects_truncated_form(): void {
		$module = new TaxonomyDiscount();
		$errors = $module->validate_payload_guard( array(), TaxonomyDiscount::PANEL_TAB_ID );

		self::assertCount( 1, $errors );

		$_POST['akb_taxonomy_discount_payload_complete'] = '1';
		self::assertSame(
			array(),
			$module->validate_payload_guard( array(), TaxonomyDiscount::PANEL_TAB_ID )
		);
	}

	/**
	 * @testdox The discount editor owns cacheable assets instead of inline blobs.
	 */
	public function test_admin_editor_uses_dedicated_assets(): void {
		$module_source = file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/TaxonomyDiscount/TaxonomyDiscount.php'
		);
		$javascript = file_get_contents(
			dirname( __DIR__, 3 ) . '/assets/js/taxonomy-discount-admin.js'
		);
		$stylesheet = file_get_contents(
			dirname( __DIR__, 3 ) . '/assets/css/taxonomy-discount-admin.css'
		);

		self::assertIsString( $module_source );
		self::assertIsString( $javascript );
		self::assertIsString( $stylesheet );
		self::assertStringNotContainsString( '<style>', $module_source );
		self::assertStringNotContainsString( '<script>', $module_source );
		self::assertStringContainsString( 'enqueue_admin_assets', $module_source );
		self::assertStringContainsString( 'initializeDateValidation', $javascript );
		self::assertStringContainsString( '.akb-sticky-save-bar', $stylesheet );
	}
}
