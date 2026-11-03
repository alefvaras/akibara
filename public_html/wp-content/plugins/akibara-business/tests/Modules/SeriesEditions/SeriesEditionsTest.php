<?php
/**
 * Pruebas de navegación y persistencia de ediciones relacionadas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesEditions;

use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesEditions\SeriesEditions;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SeriesEditionsEligibilityWpdb {
	public string $posts = 'wp_posts';
	public string $terms = 'wp_terms';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta            = 'wp_postmeta';
	/** @var list<array<string,mixed>> */
	public array $rows = array();
	public bool $fail = false;

	public function prepare( string $query, ...$args ): string {
		unset( $args );
		return $query;
	}

	/** @return list<array<string,mixed>>|null */
	public function get_results( string $query, string $output = 'OBJECT' ): ?array {
		unset( $query, $output );
		return $this->fail ? null : $this->rows;
	}
}

final class SeriesEditionsTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	private array $meta = array();

	/** @var array<int,\WP_Term> */
	private array $terms = array();

	/** @var array<string,mixed> */
	private array $original_post = array();

	/** @var array<int,int> */
	private array $catalog_counts = array();

	private SeriesEditionsEligibilityWpdb $eligibility_database;

	/** @var mixed */
	private $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_post = $_POST;
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$_POST               = array();
		$this->meta          = array();
		$this->catalog_counts = array();
		$this->eligibility_database = new SeriesEditionsEligibilityWpdb();
		$this->terms         = array(
			1 => $this->term( 1, 'one-piece', 'One Piece' ),
			2 => $this->term( 2, 'one-piece-no', 'One Piece 3 en 1 (Planeta Cómic)' ),
			3 => $this->term( 3, 'one-piece-pack', 'Pack promocional' ),
		);
		$GLOBALS['wpdb']     = (object) array(
			'num_queries' => 0,
			'last_error'  => '',
			'ready'       => true,
		);

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'term_exists' )->alias(
			fn( int $id, string $taxonomy = '' ) => 'pa_serie' === $taxonomy && isset( $this->terms[ $id ] ) ? $id : null
		);
		Functions\when( 'get_term' )->alias(
			fn( int $id, string $taxonomy = '' ) => 'pa_serie' === $taxonomy ? ( $this->terms[ $id ] ?? null ) : null
		);
		Functions\when( 'get_terms' )->alias(
			function ( array $args ): array {
				if ( 'ids' === ( $args['fields'] ?? '' ) ) {
					return array_keys( $this->terms );
				}

				$ids = isset( $args['include'] ) && is_array( $args['include'] )
					? array_map( 'intval', $args['include'] )
					: array_keys( $this->terms );

				return array_values(
					array_filter(
						array_map( fn( int $id ) => $this->terms[ $id ] ?? null, $ids )
					)
				);
			}
		);
		Functions\when( 'get_term_link' )->alias(
			static fn( \WP_Term $term ): string => 'https://akibara.cl/serie/' . $term->slug . '/'
		);
		Functions\when( 'wp_get_object_terms' )->justReturn( array() );
		Functions\when( 'update_meta_cache' )->justReturn( true );
		Functions\when( 'get_term_meta' )->alias(
			fn( int $id, string $key, bool $single = false ) => $this->meta[ $id ][ $key ] ?? ''
		);
		Functions\when( 'update_term_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_term_meta' )->alias(
			function ( int $id, string $key ): bool {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_POST = $this->original_post;
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	private function term( int $id, string $slug, string $name ): \WP_Term {
		$term           = new \WP_Term( $id, $slug, $name );
		$term->taxonomy = 'pa_serie';
		$term->count    = 1;
		return $term;
	}

	private function module(): SeriesEditions {
		$this->eligibility_database->rows = array_map(
			fn( \WP_Term $term ): array => array(
				'term_taxonomy_id' => $term->term_taxonomy_id,
				'product_count'     => $this->catalog_counts[ $term->term_id ] ?? $term->count,
			),
			array_values( $this->terms )
		);

		return new SeriesEditions( new SeriesCatalogEligibility( $this->eligibility_database ) );
	}

	/** @testdox Should connect the native form, reciprocal cleanup and hero navigation hooks. */
	public function test_registers_all_contract_hooks(): void {
		$module = $this->module();
		$module->register();

		self::assertNotFalse( Monkey\Actions\has( 'akibara_gp_series_hero_after_title', array( $module, 'render' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'pa_serie_edit_form_fields', array( $module, 'handle_edit_form_fields' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'edited_pa_serie', array( $module, 'handle_save_term' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'edited_pa_serie', array( $module, 'handle_peer_term_changed' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'updated_term_meta', array( $module, 'handle_peer_term_meta_changed' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'edited_term_taxonomy', array( $module, 'handle_peer_term_taxonomy_changed' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'set_object_terms', array( $module, 'handle_catalog_relationship_changed' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'deleted_term_relationships', array( $module, 'handle_catalog_relationship_deleted' ) ) );
		self::assertNotFalse( Monkey\Actions\has( 'pre_delete_term', array( $module, 'handle_pre_delete_term' ) ) );
	}

	/** @testdox Should purge inbound peers when a related edition changes even if reciprocity drifted. */
	public function test_peer_term_change_purges_inbound_dependents_and_coalesces_the_request(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );
		Functions\when( 'get_terms' )->justReturn( array( 1, 2, 3 ) );
		$purges = array();
		Monkey\Actions\expectDone( 'litespeed_purge' )
			->twice()
			->whenHappen( static function ( string $tag ) use ( &$purges ): void { $purges[] = $tag; } );

		$module = $this->module();
		$module->handle_peer_term_changed( 2 );
		$module->handle_peer_term_meta_changed( 99, 2, 'rank_math_robots', array( 'noindex' ) );

		sort( $purges, SORT_STRING );
		self::assertSame( array( 'T.1', 'T.2' ), $purges );
	}

	/** @testdox Should invalidate related pages after a catalog count update. */
	public function test_term_count_change_purges_the_edition_and_its_peer(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );
		$this->meta[2][ SeriesEditions::META_KEY ] = array( 1 );
		Functions\when( 'get_terms' )->justReturn( array( 1, 2, 3 ) );
		Functions\when( 'get_term_by' )->alias(
			fn( string $field, int $value, string $taxonomy ) => 'term_taxonomy_id' === $field && 22 === $value && 'pa_serie' === $taxonomy
				? $this->terms[2]
				: false
		);
		$purges = array();
		Monkey\Actions\expectDone( 'litespeed_purge' )
			->twice()
			->whenHappen( static function ( string $tag ) use ( &$purges ): void { $purges[] = $tag; } );

		$this->module()->handle_peer_term_taxonomy_changed( 22, 'pa_serie' );

		sort( $purges, SORT_STRING );
		self::assertSame( array( 'T.1', 'T.2' ), $purges );
	}

	/** @testdox Should ignore unrelated metadata and term-taxonomy events. */
	public function test_peer_invalidation_ignores_unrelated_changes(): void {
		Functions\when( 'get_term_by' )->justReturn( $this->terms[2] );

		$module = $this->module();
		$module->handle_peer_term_meta_changed( 99, 2, '_stock', '3' );
		$module->handle_peer_term_taxonomy_changed( 22, 'product_cat' );

		self::assertTrue( true );
	}

	/** @testdox Should purge an edition and its owners when product catalogue visibility changes. */
	public function test_catalog_visibility_change_purges_related_pages(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );
		Functions\when( 'get_terms' )->justReturn( array( 1, 2, 3 ) );
		Functions\when( 'wp_get_object_terms' )->justReturn( array( 2 ) );
		$purges = array();
		Monkey\Actions\expectDone( 'litespeed_purge' )
			->twice()
			->whenHappen( static function ( string $tag ) use ( &$purges ): void { $purges[] = $tag; } );

		$this->module()->handle_catalog_relationship_changed( 77, array(), array(), 'product_visibility' );

		sort( $purges, SORT_STRING );
		self::assertSame( array( 'T.1', 'T.2' ), $purges );
	}

	/** @testdox Should render an explicit crawlable link to the other physical edition. */
	public function test_renders_the_related_edition_by_the_h1(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );

		ob_start();
		$this->module()->render( $this->terms[1] );
		$html = (string) ob_get_clean();

		// El rótulo es del panel (Textos → Landing de serie); corto, para que
		// vaya en una línea con el enlace (revisión B3-fix, UM-1).
		self::assertStringContainsString( '<span class="akb-series-editions__label">' . SeriesEditions::DEFAULT_LABEL . '</span>', $html );
		self::assertStringContainsString( 'href="https://akibara.cl/serie/one-piece-no/"', $html );
		self::assertStringContainsString( 'One Piece 3 en 1 (Planeta Cómic)', $html );
		self::assertStringContainsString( 'aria-label="Otras ediciones de esta obra"', $html );
	}

	/** @testdox Should never link an edition that is noindex or has no catalog products. */
	public function test_hides_non_indexable_related_terms(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2, 3 );
		$this->meta[2]['rank_math_robots']          = array( 'noindex' );
		$this->catalog_counts[3]                    = 0;

		ob_start();
		$this->module()->render( $this->terms[1] );
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
	}

	/** @testdox Should hide a hidden-only related edition even when its raw term count is positive. */
	public function test_hides_hidden_only_related_edition(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );
		$this->catalog_counts[2] = 0;

		ob_start();
		$this->module()->render( $this->terms[1] );
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
	}

	/** @testdox Should omit related links without caching a partial landing when eligibility fails. */
	public function test_does_not_cache_missing_related_links_after_an_eligibility_failure(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );
		$this->eligibility_database->fail = true;
		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series catalogue eligibility unavailable' );

		ob_start();
		$this->module()->render( $this->terms[1] );
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
	}

	/** @testdox Should load related terms once and never cache a partial batch after a database error. */
	public function test_does_not_cache_a_failed_related_term_batch(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2, 3 );
		Functions\when( 'get_terms' )->alias(
			static function (): array {
				++$GLOBALS['wpdb']->num_queries;
				$GLOBALS['wpdb']->last_error = 'temporary database failure';
				return array();
			}
		);
		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series related editions unavailable' );

		ob_start();
		$this->module()->render( $this->terms[1] );
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
		self::assertSame( 1, $GLOBALS['wpdb']->num_queries, 'Una sola lectura batch falló; no hubo get_term por peer.' );
	}

	/** @testdox Should fail closed instead of linking a peer with corrupt robots metadata. */
	public function test_hides_a_related_term_with_corrupt_robots_metadata(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );
		$this->meta[2]['rank_math_robots']          = 42;

		ob_start();
		$this->module()->render( $this->terms[1] );
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
	}

	/** @testdox Should save a relation in both directions and purge both archives. */
	public function test_saves_reciprocal_relation_and_purges_both_terms(): void {
		$_POST = array(
			'akb_serie_ediciones_nonce'        => 'valid',
			'akb_serie_ediciones_relacionadas' => array( '2', '2', '999', '1' ),
		);
		$purges = array();
		Monkey\Actions\expectDone( 'litespeed_purge' )
			->twice()
			->whenHappen( static function ( string $tag ) use ( &$purges ): void { $purges[] = $tag; } );

		$this->module()->handle_save_term( 1 );

		self::assertSame( array( 2 ), $this->meta[1][ SeriesEditions::META_KEY ] );
		self::assertSame( array( 1 ), $this->meta[2][ SeriesEditions::META_KEY ] );
		sort( $purges, SORT_STRING );
		self::assertSame( array( 'T.1', 'T.2' ), $purges );
	}

	/** @testdox Should roll back a peer write if the owner metadata write fails. */
	public function test_persistence_failure_cannot_leave_a_one_way_relation(): void {
		$_POST = array(
			'akb_serie_ediciones_nonce'        => 'valid',
			'akb_serie_ediciones_relacionadas' => array( '2' ),
		);
		Functions\when( 'update_term_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				if ( 1 === $id && SeriesEditions::META_KEY === $key ) {
					return false;
				}
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Monkey\Actions\expectDone( 'litespeed_purge' )->twice();

		$this->module()->handle_save_term( 1 );

		self::assertArrayNotHasKey( SeriesEditions::META_KEY, $this->meta[1] ?? array() );
		self::assertArrayNotHasKey( SeriesEditions::META_KEY, $this->meta[2] ?? array() );
	}

	/** @testdox Should remove the reverse edge when the owner clears a relation. */
	public function test_clearing_a_relation_removes_its_reverse_edge(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );
		$this->meta[2][ SeriesEditions::META_KEY ] = array( 1 );
		$_POST = array( 'akb_serie_ediciones_nonce' => 'valid' );
		Monkey\Actions\expectDone( 'litespeed_purge' )->twice();

		$this->module()->handle_save_term( 1 );

		self::assertArrayNotHasKey( SeriesEditions::META_KEY, $this->meta[1] ?? array() );
		self::assertArrayNotHasKey( SeriesEditions::META_KEY, $this->meta[2] );
	}

	/** @testdox Should remove reverse edges while the deleted term metadata still exists. */
	public function test_pre_delete_hook_removes_reverse_edges_before_core_deletes_meta(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );
		$this->meta[2][ SeriesEditions::META_KEY ] = array( 1 );
		Functions\when( 'get_terms' )->justReturn( array( 1, 2, 3 ) );
		Monkey\Actions\expectDone( 'litespeed_purge' )->once()->with( 'T.2' );

		$this->module()->handle_pre_delete_term( 1, 'pa_serie' );

		self::assertArrayNotHasKey( SeriesEditions::META_KEY, $this->meta[2] );
	}

	/** @testdox Should remove a dangling inbound edge when its target is deleted. */
	public function test_pre_delete_hook_repairs_one_way_historical_drift(): void {
		$this->meta[2][ SeriesEditions::META_KEY ] = array( 1 );
		Functions\when( 'get_terms' )->justReturn( array( 1, 2, 3 ) );
		Monkey\Actions\expectDone( 'litespeed_purge' )->once()->with( 'T.2' );

		$this->module()->handle_pre_delete_term( 1, 'pa_serie' );

		self::assertArrayNotHasKey( SeriesEditions::META_KEY, $this->meta[2] );
	}

	/** @testdox Should ignore a pre-delete event from another taxonomy. */
	public function test_pre_delete_hook_ignores_other_taxonomies(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );
		$this->meta[2][ SeriesEditions::META_KEY ] = array( 1 );

		$this->module()->handle_pre_delete_term( 1, 'product_cat' );

		self::assertSame( array( 1 ), $this->meta[2][ SeriesEditions::META_KEY ] );
	}

	/** @testdox Should repair a missing reciprocal edge even when the selection is unchanged. */
	public function test_unchanged_selection_repairs_missing_reciprocal_edge(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );
		$_POST = array(
			'akb_serie_ediciones_nonce'        => 'valid',
			'akb_serie_ediciones_relacionadas' => array( '2' ),
		);
		Monkey\Actions\expectDone( 'litespeed_purge' )->once()->with( 'T.2' );

		$this->module()->handle_save_term( 1 );

		self::assertSame( array( 2 ), $this->meta[1][ SeriesEditions::META_KEY ] );
		self::assertSame( array( 1 ), $this->meta[2][ SeriesEditions::META_KEY ] );
	}

	/** @testdox Should reject a new one-way relation when the peer reached its cap. */
	public function test_full_peer_cannot_create_a_one_way_relation(): void {
		for ( $id = 4; $id <= 11; ++$id ) {
			$this->terms[ $id ] = $this->term( $id, 'serie-' . $id, 'Serie ' . $id );
		}
		$this->meta[2][ SeriesEditions::META_KEY ] = range( 4, 11 );
		$_POST = array(
			'akb_serie_ediciones_nonce'        => 'valid',
			'akb_serie_ediciones_relacionadas' => array( '2' ),
		);

		$this->module()->handle_save_term( 1 );

		self::assertArrayNotHasKey( SeriesEditions::META_KEY, $this->meta[1] ?? array() );
		self::assertSame( range( 4, 11 ), $this->meta[2][ SeriesEditions::META_KEY ] );
	}

	/** @testdox Should leave stored relationships untouched when authorization fails. */
	public function test_refuses_unauthorized_writes(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );
		$_POST = array( 'akb_serie_ediciones_nonce' => 'invalid' );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->module()->handle_save_term( 1 );

		self::assertSame( array( 2 ), $this->meta[1][ SeriesEditions::META_KEY ] );
	}

	/** @testdox Should whitelist all conditional edition-navigation selectors only once. */
	public function test_whitelists_conditional_css_selectors(): void {
		$result = $this->module()->allowlist_ucss_selectors( array( '.akb-series-editions' ) );

		self::assertSame( $result, array_values( array_unique( $result ) ) );
		self::assertContains( '.akb-series-editions__link', $result );
		self::assertCount( 4, $result );
	}
	/**
	 * Captura el HTML del campo de edición del término.
	 */
	private function edit_field_html( int $term_id ): string {
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$buffers = ob_get_level();
		ob_start();
		try {
			$this->module()->handle_edit_form_fields( $this->terms[ $term_id ], 'pa_serie' );
			return (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffers ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * @testdox El campo NO puede ser un control donde elegir una opción borre las demás.
	 *
	 * Era un `<select multiple>` con las 119 series: un clic simple deseleccionaba
	 * todo lo anterior, y como el guardado es recíproco la relación desaparecía
	 * también del otro término. El candado fija la propiedad —marcar una no puede
	 * desmarcar otra—, no el marcado concreto que la cumple hoy.
	 */
	public function test_the_control_cannot_clear_other_choices_with_one_click(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );

		$html = $this->edit_field_html( 1 );

		self::assertStringNotContainsString( '<select', $html, 'Un <select multiple> pierde toda la selección con un clic simple.' );
		self::assertStringContainsString( 'type="checkbox"', $html );
		self::assertSame(
			2,
			substr_count( $html, 'name="akb_serie_ediciones_relacionadas[]"' ),
			'Una casilla por serie candidata, sin contar el propio término.'
		);
	}

	/**
	 * @testdox Las series ya relacionadas se muestran marcadas y antes que el resto.
	 */
	public function test_related_editions_come_first_and_are_checked(): void {
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 3 );

		$html = $this->edit_field_html( 1 );

		self::assertStringContainsString( 'value="3" checked', $html );
		self::assertStringNotContainsString( 'value="2" checked', $html );
		self::assertLessThan(
			strpos( $html, 'value="2"' ),
			strpos( $html, 'value="3"' ),
			'Al abrir el término la pregunta es «¿con qué está enlazada?»: lo relacionado va arriba.'
		);
	}

	/**
	 * @testdox El propio término nunca aparece como candidato.
	 */
	public function test_the_term_itself_is_never_offered(): void {
		$html = $this->edit_field_html( 1 );

		self::assertStringNotContainsString( 'value="1"', $html );
	}

	/**
	 * @testdox El tope de relaciones que anuncia la interfaz es el mismo que aplica el servidor.
	 */
	public function test_the_announced_limit_matches_the_enforced_one(): void {
		$reflection = new \ReflectionClassConstant( SeriesEditions::class, 'MAX_RELATED' );
		$max        = (int) $reflection->getValue();

		$html = $this->edit_field_html( 1 );

		self::assertStringContainsString( 'data-max="' . $max . '"', $html );
		self::assertStringContainsString( 'de ' . $max . ' seleccionadas', $html );
	}

	/**
	 * @testdox Las ediciones de la misma obra de un producto salen como term_taxonomy_id, sin las suyas propias (B2-2-R2).
	 */
	public function test_ediciones_de_la_misma_obra_de_un_producto(): void {
		$propia                       = $this->terms[1];
		$propia->term_taxonomy_id     = 101;
		$this->terms[2]->term_taxonomy_id = 102;
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2, 1, 'x', 0 );
		$pedidos                      = array();
		Functions\when( 'get_the_terms' )->alias(
			static fn( $id, string $taxonomy ) => 777 === (int) $id && 'pa_serie' === $taxonomy ? array( $propia ) : false
		);
		Functions\when( 'get_terms' )->alias(
			function ( array $args ) use ( &$pedidos ): array {
				$pedidos[] = $args;
				return array_map( fn( int $id ): int => $this->terms[ $id ]->term_taxonomy_id, array_map( 'intval', $args['include'] ) );
			}
		);

		self::assertSame( array( 102 ), SeriesEditions::same_work_series_tt_ids( 777 ) );
		self::assertSame( 'tt_ids', $pedidos[0]['fields'] );
		self::assertSame( array( 2 ), $pedidos[0]['include'], 'Ni la propia serie ni valores basura.' );

		// Un producto sin serie, o una serie sin ediciones, no consulta nada.
		$pedidos = array();
		self::assertSame( array(), SeriesEditions::same_work_series_tt_ids( 5 ) );
		unset( $this->meta[1][ SeriesEditions::META_KEY ] );
		self::assertSame( array(), SeriesEditions::same_work_series_tt_ids( 777 ) );
		self::assertSame( array(), $pedidos );
	}

	/**
	 * @testdox El sello de origen editorial no repite el país si ya forma parte de la editorial.
	 */
	public function test_format_edition_origin_deduplicates_country_in_brand_name(): void {
		self::assertSame( 'Ivrea Argentina', SeriesEditions::format_edition_origin( 'Ivrea Argentina', 'Argentina' ) );
		self::assertSame( 'Panini España', SeriesEditions::format_edition_origin( 'Panini España', 'España' ) );
		self::assertSame( 'Planeta Cómic · España', SeriesEditions::format_edition_origin( 'Planeta Cómic', 'España' ) );
		self::assertSame( 'Ovni Press · Argentina', SeriesEditions::format_edition_origin( 'Ovni Press', 'Argentina' ) );
		self::assertSame( 'Argentina', SeriesEditions::format_edition_origin( '', 'Argentina' ) );
		self::assertSame( 'Milky Way', SeriesEditions::format_edition_origin( 'Milky Way', '' ) );
	}

	/**
	 * @testdox Renderiza el comparador de ediciones en la ficha de producto con el tomo homólogo y su disponibilidad en vivo.
	 */
	public function test_renders_pdp_editions_with_homologous_volume_and_live_availability(): void {
		$this->terms[1] = $this->term( 1, 'fumando-juntos-detras-del-super', 'Fumando juntos detrás del súper' );
		$this->terms[2] = $this->term( 2, 'fumando-atras-del-super-con-vos', 'Fumando atrás del súper con vos' );
		$this->catalog_counts[1] = 5;
		$this->catalog_counts[2] = 9;
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );

		$brand_ivrea   = new \WP_Term( 50, 'ivrea-argentina', 'Ivrea Argentina' );
		$country_arg   = new \WP_Term( 98, 'argentina', 'Argentina' );
		$brand_ivrea->taxonomy = 'product_brand';
		$country_arg->taxonomy = 'pa_pais';

		$current_product         = new \WC_Product( 'instock' );
		$current_product->akb_id = 501;

		$matched_product                    = new \WC_Product( 'onbackorder' );
		$matched_product->akb_id            = 603;
		$matched_product->akb_regular_price = '10990';

		Functions\when( 'get_the_title' )->alias(
			static function ( int $id ): string {
				if ( 501 === $id ) {
					return 'Fumando juntos detrás del súper 03';
				}
				return 'Fumando atrás del súper con vos 0' . ( $id - 600 );
			}
		);
		Functions\when( 'get_the_terms' )->alias(
			function ( int $post_id, string $taxonomy ) use ( $brand_ivrea, $country_arg ) {
				if ( 501 === $post_id && 'pa_serie' === $taxonomy ) {
					return array( $this->terms[1] );
				}
				if ( 603 === $post_id && 'product_brand' === $taxonomy ) {
					return array( $brand_ivrea );
				}
				if ( 603 === $post_id && 'pa_pais' === $taxonomy ) {
					return array( $country_arg );
				}
				return array();
			}
		);
		Functions\when( 'get_posts' )->alias(
			static fn(): array => array( 601, 602, 603 )
		);
		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ) use ( $matched_product ): ?\WC_Product {
				if ( 603 === $id ) {
					return $matched_product;
				}
				$other         = new \WC_Product( 'instock' );
				$other->akb_id = $id;
				return $other;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_permalink' )->alias(
			static fn( int $id ): string => 'https://akibara.cl/producto/tomo-' . $id . '/'
		);
		Functions\when( 'wp_kses_post' )->returnArg();

		ob_start();
		$this->module()->render_pdp_editions( $current_product );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Otras ediciones de esta obra', $html );
		self::assertStringContainsString( 'Ivrea Argentina', $html );
		self::assertStringContainsString( '9 tomos en catálogo', $html );
		self::assertStringContainsString( 'Fumando atrás del súper con vos 03', $html );
		self::assertStringContainsString( 'A pedido', $html );
		self::assertStringContainsString( '$10.990', $html );
		self::assertStringContainsString( 'Ver tomo 03 →', $html );
		self::assertStringContainsString( 'https://akibara.cl/producto/tomo-603/', $html );
		self::assertStringContainsString( 'https://akibara.cl/serie/fumando-atras-del-super-con-vos/', $html );
	}

	/**
	 * @testdox Cuando el tomo homólogo aún no existe en la otra edición, muestra un aviso claro y enlaza a la edición completa.
	 */
	public function test_renders_pdp_editions_fallback_when_volume_does_not_exist_in_related_edition(): void {
		$this->terms[1] = $this->term( 1, 'fumando-atras-del-super-con-vos', 'Fumando atrás del súper con vos' );
		$this->terms[2] = $this->term( 2, 'fumando-juntos-detras-del-super', 'Fumando juntos detrás del súper' );
		$this->catalog_counts[1] = 9;
		$this->catalog_counts[2] = 5;
		$this->meta[1][ SeriesEditions::META_KEY ] = array( 2 );

		$brand_panini = new \WP_Term( 51, 'panini-espana', 'Panini España' );
		$brand_panini->taxonomy = 'product_brand';

		$current_product         = new \WC_Product( 'instock' );
		$current_product->akb_id = 609;

		Functions\when( 'get_the_title' )->alias(
			static function ( int $id ): string {
				if ( 609 === $id ) {
					return 'Fumando atrás del súper con vos 09';
				}
				return 'Fumando juntos detrás del súper 0' . ( $id - 500 );
			}
		);
		Functions\when( 'get_the_terms' )->alias(
			function ( int $post_id, string $taxonomy ) use ( $brand_panini ) {
				if ( 609 === $post_id && 'pa_serie' === $taxonomy ) {
					return array( $this->terms[1] );
				}
				if ( 501 === $post_id && 'product_brand' === $taxonomy ) {
					return array( $brand_panini );
				}
				return array();
			}
		);
		Functions\when( 'get_posts' )->alias(
			static fn(): array => array( 501, 502 )
		);
		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ): \WC_Product {
				$other         = new \WC_Product( 'instock' );
				$other->akb_id = $id;
				return $other;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );

		ob_start();
		$this->module()->render_pdp_editions( $current_product );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Panini España', $html );
		self::assertStringContainsString( 'El tomo 09 aún no está publicado en esta edición o lleva otra numeración.', $html );
		self::assertStringContainsString( 'Ver los 5 tomos de esta edición →', $html );
	}
}

