<?php
/**
 * Tests for explicit `pa_serie` schema classification.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesSchema;

use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SeriesSchemaTypeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Should preserve ComicSeries for legacy terms without classification metadata. */
	public function test_preserves_legacy_terms_without_metadata(): void {
		Functions\expect( 'get_term_meta' )
			->once()
			->with( 77, SeriesSchemaType::META_KEY, true )
			->andReturn( '' );

		self::assertTrue( SeriesSchemaType::supports_comic_series( 77 ) );
	}

	/**
	 * @testdox Should accept only the explicit ComicSeries value once metadata exists.
	 * @dataProvider classified_value_provider
	 *
	 * @param mixed $stored_value Stored term metadata.
	 */
	public function test_accepts_only_the_explicit_comic_value( $stored_value, bool $expected ): void {
		Functions\expect( 'get_term_meta' )
			->once()
			->with( 77, SeriesSchemaType::META_KEY, true )
			->andReturn( $stored_value );

		self::assertSame( $expected, SeriesSchemaType::supports_comic_series( 77 ) );
	}

	/** @return array<string,array{mixed,bool}> */
	public static function classified_value_provider(): array {
		return array(
			'explicit comic series' => array( SeriesSchemaType::COMIC_SERIES, true ),
			'explicit non-comic'     => array( SeriesSchemaType::NON_COMIC, false ),
			'unknown scalar'         => array( 'novel', false ),
			'unreadable value'       => array( array( SeriesSchemaType::NON_COMIC ), false ),
		);
	}

	/** @testdox Should fail closed before reading metadata for an invalid term ID. */
	public function test_fails_closed_for_an_invalid_term_id(): void {
		Functions\expect( 'get_term_meta' )->never();

		self::assertFalse( SeriesSchemaType::supports_comic_series( 0 ) );
	}
}
