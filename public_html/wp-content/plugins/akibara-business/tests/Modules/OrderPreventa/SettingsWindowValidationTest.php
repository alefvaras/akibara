<?php
/**
 * Tests de la validación cruzada de ventanas del recordatorio de preventa.
 *
 * Primer test dedicado del módulo OrderPreventa (backlog D2 de la auditoría
 * 2026-07-28). Cubre Settings::validate_windows — el guard que impide guardar
 * una combinación min > max que apagaría el email T-7d en silencio.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\Settings;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class SettingsWindowValidationTest extends TestCase {

	/** @var array<string, mixed> */
	private array $original_post;

	private Settings $settings;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->original_post = $_POST;
		$_POST               = array();
		$this->settings      = new Settings();
	}

	protected function tearDown(): void {
		$_POST = $this->original_post;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should reject a minimum window greater than the maximum.
	 */
	public function test_min_greater_than_max_is_rejected(): void {
		$_POST[ Settings::OPT_T7_WINDOW_MAX ] = '10';
		$_POST[ Settings::OPT_T7_WINDOW_MIN ] = '14';

		$errors = $this->settings->validate_windows( array(), Settings::PANEL_TAB_ID );

		self::assertCount( 1, $errors );
		self::assertStringContainsString( 'no puede ser mayor', $errors[0] );
	}

	/**
	 * @testdox Should accept a valid window combination without errors.
	 */
	public function test_valid_combination_passes(): void {
		$_POST[ Settings::OPT_T7_WINDOW_MAX ] = '10';
		$_POST[ Settings::OPT_T7_WINDOW_MIN ] = '1';

		self::assertSame( array(), $this->settings->validate_windows( array(), Settings::PANEL_TAB_ID ) );
	}

	/**
	 * @testdox Should enforce the browser ranges server-side.
	 */
	public function test_out_of_range_values_are_rejected(): void {
		$_POST[ Settings::OPT_T7_WINDOW_MAX ] = '45';
		$_POST[ Settings::OPT_T7_WINDOW_MIN ] = '-2';

		$errors = $this->settings->validate_windows( array(), Settings::PANEL_TAB_ID );

		self::assertCount( 2, $errors );
	}

	/**
	 * @testdox Should not touch errors coming from another panel tab.
	 */
	public function test_other_tab_is_ignored(): void {
		$_POST[ Settings::OPT_T7_WINDOW_MAX ] = '10';
		$_POST[ Settings::OPT_T7_WINDOW_MIN ] = '14';

		$previous = array( 'error ajeno' );

		self::assertSame( $previous, $this->settings->validate_windows( $previous, 'copys' ) );
	}

	/**
	 * @testdox Should skip validation when the posted sub-tab lacks the fields.
	 */
	public function test_other_subtab_without_fields_is_skipped(): void {
		// Sub-tabs fechas/cambio-fecha postean otros ids: sin ambos campos no
		// hay nada que validar y el guard no debe bloquear ese save.
		self::assertSame( array(), $this->settings->validate_windows( array(), Settings::PANEL_TAB_ID ) );
	}

	/**
	 * @testdox Should preserve unrelated errors while appending its own.
	 */
	public function test_appends_to_existing_errors(): void {
		$_POST[ Settings::OPT_T7_WINDOW_MAX ] = '10';
		$_POST[ Settings::OPT_T7_WINDOW_MIN ] = '14';

		$errors = $this->settings->validate_windows( array( 'previo' ), Settings::PANEL_TAB_ID );

		self::assertCount( 2, $errors );
		self::assertSame( 'previo', $errors[0] );
	}
}
