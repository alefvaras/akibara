<?php
/**
 * Tests for the shared anti-spam honeypot.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Forms;

use Akibara\Business\Modules\Forms\Honeypot;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class HoneypotTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->alias(
			static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Keeps the text field available to bots and form serialization but removes it from the accessibility tree.
	 */
	public function test_field_is_inert_and_aria_hidden(): void {
		$html = Honeypot::field_html();

		self::assertStringContainsString( 'aria-hidden="true" inert', $html );
		self::assertStringContainsString( 'name="akb_hp_url"', $html );
		self::assertStringContainsString( 'type="text"', $html );
		self::assertStringContainsString( 'tabindex="-1"', $html );
	}

	/**
	 * @testdox Detects only non-empty string submissions.
	 */
	public function test_tripped_requires_non_empty_string(): void {
		self::assertFalse( Honeypot::tripped( array() ) );
		self::assertFalse( Honeypot::tripped( array( 'akb_hp_url' => '' ) ) );
		self::assertFalse( Honeypot::tripped( array( 'akb_hp_url' => array( 'bot' ) ) ) );
		self::assertTrue( Honeypot::tripped( array( 'akb_hp_url' => 'https://spam.example' ) ) );
	}
}
