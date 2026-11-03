<?php
/**
 * Tests for shared series catalogue eligibility.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesCatalog;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Services\VolumeVariant;
use PHPUnit\Framework\TestCase;

/** wpdb-compatible aggregate-query double. */
final class SeriesCatalogEligibilityWpdb {

	public string $posts              = 'wp_posts';
	public string $terms              = 'wp_terms';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta           = 'wp_postmeta';

	/** @var list<array<string,mixed>> */
	public array $rows = array();

	/** @var list<string> */
	public array $queries = array();

	/** @var list<list<mixed>> */
	public array $prepared_arguments = array();

	public bool $fail = false;

	public bool $fail_with_empty_result = false;

	public bool $ready = true;

	public string $last_error = '';

	public string $last_query = '';

	/**
	 * Records and interpolates a prepared query for inspection.
	 *
	 * @param string $query   SQL with placeholders.
	 * @param mixed  ...$args Placeholder values.
	 */
	public function prepare( string $query, ...$args ): string {
		$this->prepared_arguments[] = $args;
		$sql                        = $query;

		foreach ( $args as $arg ) {
			$replacement = is_int( $arg )
				? (string) $arg
				: "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$sql         = preg_replace( '/%[ds]/', $replacement, $sql, 1 ) ?? $sql;
		}

		return $sql;
	}

	/**
	 * Returns the configured aggregate rows.
	 *
	 * @param string $query  Prepared SQL.
	 * @param string $output WordPress output mode.
	 * @return list<array<string,mixed>>|null
	 */
	public function get_results( string $query, string $output = 'OBJECT' ): ?array {
		unset( $output );
		$this->queries[] = $query;
		if ( ! $this->ready ) {
			return array();
		}
		$this->last_query = $query;
		$this->last_error = $this->fail_with_empty_result ? 'Simulated SQL error' : '';

		return $this->fail ? null : ( $this->fail_with_empty_result ? array() : $this->rows );
	}
}

final class SeriesCatalogEligibilityTest extends TestCase {

	/** The System Under Test. */
	private SeriesCatalogEligibility $sut;

	private SeriesCatalogEligibilityWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb = new SeriesCatalogEligibilityWpdb();
		$this->sut  = new SeriesCatalogEligibility( $this->wpdb );
	}

	/**
	 * Las dos cifras salen de la MISMA consulta y son distintas en cuanto la
	 * serie tiene una edición especial dentro. `counts()` decide si la serie
	 * existe para el catálogo; `volume_counts()` es la que se publica donde el
	 * texto dice «tomos» —el directorio de series y la portada—, que es donde se
	 * leía 24 mientras la landing de la misma serie decía «Completa en 23 tomos».
	 *
	 * @testdox Should count volumes apart from products, from one single aggregate.
	 */
	public function test_counts_volumes_apart_from_products(): void {
		$this->wpdb->rows = array(
			array( 'term_taxonomy_id' => '435', 'product_count' => '24', 'volume_count' => '23' ),
		);

		self::assertSame( array( 435 => 24 ), $this->sut->counts( array( 435 ) ) );
		self::assertSame( array( 435 => 23 ), $this->sut->volume_counts( array( 435 ) ) );
		self::assertSame( 23, $this->sut->volume_count( 435 ) );
		self::assertSame( 0, $this->sut->volume_count( 0 ) );
		self::assertCount( 1, $this->wpdb->queries, 'Las dos cifras salen de una sola consulta.' );
	}

	/**
	 * El `CASE` de la columna de tomos tiene que preguntar por el ENUM CERRADO.
	 * Comparar el meta crudo con «distinto de vacío» ascendía a variante
	 * cualquier tipeo de una importación o de WP-CLI, y entonces el directorio
	 * escondía un tomo que el resto del sitio sigue tratando como normal.
	 *
	 * @testdox Should ask for the closed variant enum, never for a non-empty meta.
	 */
	public function test_volume_column_asks_for_the_closed_enum(): void {
		$this->wpdb->rows = array(
			array( 'term_taxonomy_id' => '435', 'product_count' => '1', 'volume_count' => '1' ),
		);

		$this->sut->counts( array( 435 ) );
		$sql = $this->wpdb->queries[0];

		foreach ( VolumeVariant::variant_kinds() as $kind ) {
			self::assertStringContainsString( "'" . $kind . "'", $sql );
		}
		self::assertStringContainsString( "variant_meta.meta_key = '" . VolumeVariant::META_KEY . "'", $sql );
		self::assertStringContainsString( 'series_relationship.term_taxonomy_id IN (435)', $sql );
		self::assertStringContainsString( "hidden_term.slug = 'exclude-from-catalog'", $sql );
	}

	/**
	 * @testdox Should count a normal published product assigned to a series.
	 */
	public function test_counts_normal_published_products(): void {
		self::assertFalse( $this->sut->is_reliable() );
		$this->wpdb->rows = array(
			array(
				'term_taxonomy_id' => '101',
				'product_count'     => '1',
				'volume_count'      => '1',
			),
		);

		self::assertSame( array( 101 => 1 ), $this->sut->counts( array( 101 ) ) );
		self::assertSame( 1, $this->sut->count( 101 ) );
		self::assertTrue( $this->sut->is_eligible( 101 ) );
		self::assertTrue( $this->sut->is_reliable() );
		self::assertSame( 'product_visibility', SeriesCatalogEligibility::VISIBILITY_TAXONOMY );
		self::assertSame( 'exclude-from-catalog', SeriesCatalogEligibility::HIDDEN_FROM_CATALOG );
		self::assertStringContainsString( "series_taxonomy.taxonomy = 'pa_serie'", $this->wpdb->queries[0] );
		self::assertStringContainsString( "product.post_type = 'product'", $this->wpdb->queries[0] );
		self::assertCount( 1, $this->wpdb->queries, 'Single-item helpers must reuse the batch cache.' );
	}

	/**
	 * @testdox Should exclude draft products from eligibility.
	 */
	public function test_excludes_draft_products(): void {
		self::assertSame( 0, $this->sut->count( 202 ) );
		self::assertFalse( $this->sut->is_eligible( 202 ) );
		self::assertStringContainsString( "product.post_status = 'publish'", $this->wpdb->queries[0] );
	}

	/**
	 * @testdox Should exclude a series whose only product is hidden from the catalogue.
	 */
	public function test_excludes_hidden_only_products(): void {
		self::assertSame( 0, $this->sut->count( 303 ) );
		self::assertFalse( $this->sut->is_eligible( 303 ) );
		self::assertStringContainsString( 'AND NOT EXISTS (', $this->wpdb->queries[0] );
		self::assertStringContainsString( "hidden_taxonomy.taxonomy = 'product_visibility'", $this->wpdb->queries[0] );
		self::assertStringContainsString( "hidden_term.slug = 'exclude-from-catalog'", $this->wpdb->queries[0] );
	}

	/**
	 * @testdox Should return only the published visible count for a mixed catalogue.
	 */
	public function test_counts_only_published_visible_products_in_a_mixed_catalogue(): void {
		$this->wpdb->rows = array(
			array(
				'term_taxonomy_id' => '404',
				'product_count'     => '2',
				'volume_count'      => '2',
			),
		);

		self::assertSame( 2, $this->sut->count( 404 ) );
		self::assertStringContainsString( 'COUNT(DISTINCT product.ID)', $this->wpdb->queries[0] );
		self::assertStringContainsString( "product.post_status = 'publish'", $this->wpdb->queries[0] );
		self::assertStringContainsString( "hidden_term.slug = 'exclude-from-catalog'", $this->wpdb->queries[0] );
	}

	/**
	 * @testdox Should deduplicate IDs and ignore invalid values before querying.
	 */
	public function test_deduplicates_ids_and_ignores_invalid_values(): void {
		$this->wpdb->rows = array(
			array( 'term_taxonomy_id' => '12', 'product_count' => '3', 'volume_count' => '3' ),
			array( 'term_taxonomy_id' => '13', 'product_count' => '1', 'volume_count' => '1' ),
		);

		$counts = $this->sut->counts( array( 12, '12', 0, -4, '', null, 12.5, 13 ) );

		self::assertSame( array( 12 => 3, 13 => 1 ), $counts );
		self::assertStringContainsString( 'IN (12, 13)', $this->wpdb->queries[0] );
		self::assertSame( 0, $this->sut->count( 0 ) );
		self::assertSame( 0, $this->sut->count( -1 ) );
		self::assertCount( 1, $this->wpdb->queries, 'Invalid IDs must not trigger follow-up queries.' );
	}

	/**
	 * @testdox Should cache counts and reset selected IDs or the complete request cache.
	 */
	public function test_caches_and_resets_counts(): void {
		$this->wpdb->rows = array( array( 'term_taxonomy_id' => '77', 'product_count' => '1', 'volume_count' => '1' ) );

		self::assertSame( 1, $this->sut->count( 77 ) );
		$this->wpdb->rows = array( array( 'term_taxonomy_id' => '77', 'product_count' => '3', 'volume_count' => '3' ) );
		self::assertSame( 1, $this->sut->count( 77 ), 'A request-local cache must stay stable until reset.' );
		self::assertCount( 1, $this->wpdb->queries );

		$this->sut->reset_cache( array( 77 ) );
		self::assertSame( 3, $this->sut->count( 77 ) );
		self::assertCount( 2, $this->wpdb->queries );

		$this->wpdb->rows = array( array( 'term_taxonomy_id' => '77', 'product_count' => '4', 'volume_count' => '4' ) );
		$this->sut->reset_cache();
		self::assertSame( 4, $this->sut->count( 77 ) );
		self::assertCount( 3, $this->wpdb->queries );
	}

	/**
	 * @testdox Should fetch many terms in one query and query only later cache misses.
	 */
	public function test_batches_terms_without_n_plus_one_queries(): void {
		$this->wpdb->rows = array(
			array( 'term_taxonomy_id' => '101', 'product_count' => '2', 'volume_count' => '2' ),
			array( 'term_taxonomy_id' => '202', 'product_count' => '1', 'volume_count' => '1' ),
		);

		self::assertSame(
			array( 101 => 2, 202 => 1, 303 => 0 ),
			$this->sut->counts( array( 101, 202, 303 ) )
		);
		self::assertSame( 2, $this->sut->count( 101 ) );
		self::assertSame( 1, $this->sut->count( 202 ) );
		self::assertSame( 0, $this->sut->count( 303 ) );
		self::assertCount( 1, $this->wpdb->queries, 'All requested terms must share one aggregate query.' );
		self::assertStringContainsString( 'IN (101, 202, 303)', $this->wpdb->queries[0] );

		$this->wpdb->rows = array( array( 'term_taxonomy_id' => '404', 'product_count' => '5', 'volume_count' => '5' ) );
		self::assertSame( array( 202 => 1, 404 => 5 ), $this->sut->counts( array( 202, 404 ) ) );
		self::assertCount( 2, $this->wpdb->queries, 'Only the uncached term should need the second query.' );
		self::assertStringContainsString( 'IN (404)', $this->wpdb->queries[1] );
	}

	/** @testdox Should distinguish a database failure from a valid empty catalogue and retry it. */
	public function test_exposes_unreliable_queries_without_caching_false_zeros(): void {
		$this->wpdb->fail = true;

		self::assertSame( array( 808 => 0 ), $this->sut->counts( array( 808 ) ) );
		self::assertFalse( $this->sut->is_reliable() );

		$this->wpdb->fail = false;
		$this->wpdb->rows = array( array( 'term_taxonomy_id' => '808', 'product_count' => '1', 'volume_count' => '1' ) );

		self::assertSame( array( 808 => 1 ), $this->sut->counts( array( 808 ) ) );
		self::assertTrue( $this->sut->is_reliable() );
		self::assertCount( 2, $this->wpdb->queries, 'A failed zero must never poison the request cache.' );
	}

	/** @testdox Should reject wpdb's empty-array error shape and retry instead of caching a false zero. */
	public function test_rejects_realistic_wpdb_empty_results_with_last_error(): void {
		$this->wpdb->fail_with_empty_result = true;

		self::assertSame( array( 909 => 0 ), $this->sut->counts( array( 909 ) ) );
		self::assertFalse( $this->sut->is_reliable() );

		$this->wpdb->fail_with_empty_result = false;
		$this->wpdb->rows = array( array( 'term_taxonomy_id' => '909', 'product_count' => '2', 'volume_count' => '2' ) );

		self::assertSame( array( 909 => 2 ), $this->sut->counts( array( 909 ) ) );
		self::assertTrue( $this->sut->is_reliable() );
		self::assertCount( 2, $this->wpdb->queries );
	}

	/** @testdox Should reject a database connection that was not ready to execute the aggregate. */
	public function test_rejects_a_not_ready_database_even_if_wpdb_returns_an_array(): void {
		$this->wpdb->ready = false;

		self::assertSame( array( 1001 => 0 ), $this->sut->counts( array( 1001 ) ) );
		self::assertFalse( $this->sut->is_reliable() );
	}
}
