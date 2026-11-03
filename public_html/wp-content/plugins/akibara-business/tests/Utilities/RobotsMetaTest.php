<?php
/**
 * Tests del parser compartido de robots.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Utilities;

use Akibara\Business\Utilities\RobotsMeta;
use PHPUnit\Framework\TestCase;

final class RobotsMetaTest extends TestCase {

	/** @testdox Empty and valid index directives remain indexable in every supported storage shape. */
	public function test_accepts_absent_and_valid_index_directives(): void {
		foreach ( array( '', '  ', array(), array( 'index', 'follow' ), 'index,follow' ) as $robots ) {
			self::assertTrue( RobotsMeta::is_indexable( $robots ) );
		}
	}

	/** @testdox Noindex is case-insensitive in arrays, plain strings and serialized strings. */
	public function test_detects_noindex_case_insensitively(): void {
		foreach (
			array(
				array( 'NOINDEX', 'follow' ),
				array( ' index ', ' NoIndex, follow ' ),
				'NoIndex,follow',
				'a:1:{i:0;s:7:"noindex";}',
			) as $robots
		) {
			self::assertFalse( RobotsMeta::is_indexable( $robots ) );
		}
	}

	/** @testdox Corrupt scalar, object and mixed-array metadata always fail closed. */
	public function test_fails_closed_for_corrupt_metadata(): void {
		foreach ( array( null, false, 7, (object) array(), array( 'index', 7 ) ) as $robots ) {
			self::assertFalse( RobotsMeta::is_indexable( $robots ) );
		}
	}
}
