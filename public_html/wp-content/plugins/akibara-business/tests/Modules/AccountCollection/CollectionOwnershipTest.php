<?php
/** Ownership follows logical volume slots within the exact edition. */
declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AccountCollection;

use Akibara\Business\Modules\AccountCollection\CollectionOwnership;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CollectionOwnershipTest extends TestCase {
	private array $purchased = array();
	private array $titles = array();
	private array $memberships = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_user_meta' )->justReturn( 'generation' );
		Functions\when( 'get_transient' )->alias( fn () => array( 'version' => 3, 'status' => $this->purchased ? 'ok' : 'empty', 'product_ids' => $this->purchased ) );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'get_the_title' )->alias( fn ( $id ) => $this->titles[ $id ] ?? 'Unnumbered special' );
		Functions\when( 'get_the_terms' )->alias( fn ( $id ) => isset( $this->memberships[ $id ] ) ? array_map( static fn ( $tid ) => (object) array( 'term_id' => $tid ), $this->memberships[ $id ] ) : false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Direct ownership is retained strictly for verified purchases. */
	public function test_direct_purchase_ownership_only(): void {
		$this->purchased = array( 1 );
		self::assertTrue( CollectionOwnership::owns_product( 42, 1 ) );
		self::assertFalse( CollectionOwnership::owns_product( 42, 2 ) );
	}

	/** @testdox A cover variant of a volume already owned in the same edition is covered. */
	public function test_same_edition_variant_is_covered(): void {
		$this->purchased   = array( 1 );
		$this->titles      = array( 1 => 'Manga 05', 2 => 'Manga 05 (Portada alternativa)' );
		$this->memberships = array( 1 => array( 10 ), 2 => array( 10 ) );
		self::assertTrue( CollectionOwnership::owns_product( 42, 2 ) );
	}

	/** @testdox Matching volume numbers from a different edition are never conflated. */
	public function test_other_edition_does_not_cover_volume(): void {
		$this->purchased   = array( 1 );
		$this->titles      = array( 1 => 'Manga 05', 2 => 'Manga 05' );
		$this->memberships = array( 1 => array( 10 ), 2 => array( 20 ) );
		self::assertFalse( CollectionOwnership::owns_product( 42, 2 ) );
	}

	/** @testdox A different number in the same edition remains missing. */
	public function test_different_volume_remains_missing(): void {
		$this->purchased = array( 1 );
		$this->titles = array( 1 => 'Manga 05', 2 => 'Manga 06' );
		$this->memberships = array( 1 => array( 10 ), 2 => array( 10 ) );
		self::assertFalse( CollectionOwnership::owns_product( 42, 2 ) );
	}

	/** @testdox Ambiguous memberships never infer equivalent editions. */
	public function test_ambiguous_series_cannot_cover_another_product(): void {
		$this->purchased   = array( 1 );
		$this->titles      = array( 1 => 'Manga 05', 2 => 'Manga 05' );
		$this->memberships = array( 1 => array( 10, 20 ), 2 => array( 10 ) );
		self::assertFalse( CollectionOwnership::owns_product( 42, 2 ) );
	}

	/** @testdox Decimal special volumes cannot supply an integer volume slot. */
	public function test_special_number_is_not_inferred_as_integer(): void {
		$this->purchased   = array( 1 );
		$this->titles      = array( 1 => 'Manga 1.5', 2 => 'Manga 05' );
		$this->memberships = array( 1 => array( 10 ), 2 => array( 10 ) );
		self::assertFalse( CollectionOwnership::owns_product( 42, 2 ) );
	}
}
