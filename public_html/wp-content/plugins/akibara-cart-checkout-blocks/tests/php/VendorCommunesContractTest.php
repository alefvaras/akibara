<?php
/**
 * Contract tests over the Blue Express commune catalog this plugin consumes.
 *
 * The combobox and the server-side commune validation read
 * `bluex_get_communes_by_region()` from the vendor tree (never modified).
 * If Blue Express moves or reshapes that file, the runtime fails open — but
 * this contract must break loudly so the release is not shipped blind.
 *
 * @package Akibara\CartCheckoutBlocks\Tests
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks\Tests;

use Akibara\CartCheckoutBlocks\ComunaDirectory;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class VendorCommunesContractTest extends TestCase {

	private const VENDOR_FILE = '/bluex-for-woocommerce/includes/data/chile-communes.php';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function vendor_file(): string {
		return dirname( __DIR__, 3 ) . self::VENDOR_FILE;
	}

	/**
	 * @testdox Should find the vendor commune catalog where the directory reads it.
	 */
	public function test_vendor_data_file_exists(): void {
		self::assertFileExists(
			$this->vendor_file(),
			'Blue Express moved its commune catalog: update ComunaDirectory::VENDOR_DATA_RELATIVE and re-verify the fail-open path.'
		);
	}

	/**
	 * @testdox Should load 16 regions with globally-unique commune names via the real vendor file.
	 */
	public function test_vendor_catalog_shape(): void {
		require_once $this->vendor_file();

		self::assertTrue( function_exists( 'bluex_get_communes_by_region' ) );

		$directory = new ComunaDirectory( static fn() => bluex_get_communes_by_region() );
		$catalog   = $directory->all_by_region();

		self::assertCount( 16, $catalog, 'Chile has 16 regions in the Blue Express catalog.' );

		$total = 0;
		foreach ( $catalog as $region => $communes ) {
			self::assertMatchesRegularExpression(
				'/^CL-[A-Z]{2}$/',
				$region,
				'Region keys must match WooCommerce CL state codes (the combobox maps them to the state select).'
			);
			self::assertNotEmpty( $communes );
			$total += count( $communes );
		}

		self::assertGreaterThanOrEqual( 340, $total, 'The commune catalog shrank unexpectedly.' );

		// Global uniqueness is what makes auto-selecting the region on pick
		// unambiguous; if Blue Express ever ships duplicates, the combobox
		// region auto-set must be redesigned before release.
		$normalized = array();
		foreach ( $catalog as $communes ) {
			foreach ( $communes as $name ) {
				$normalized[] = ComunaDirectory::normalize( $name );
			}
		}
		self::assertSame( count( $normalized ), count( array_unique( $normalized ) ) );

		self::assertSame(
			array(
				'name'   => 'Ñuñoa',
				'region' => 'CL-RM',
			),
			$directory->match( 'nunoa' ),
			'Accent-insensitive canonicalization against the real catalog.'
		);
	}
}
