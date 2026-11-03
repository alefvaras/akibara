<?php
/**
 * Resolución privada y batched de Mi colección.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AccountCollection;

use Akibara\Business\Modules\AccountCollection\CollectionResolver;
use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Services\VolumeVariant;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CollectionResolverWpdb {
	public string $posts = 'wp_posts';

	public string $terms = 'wp_terms';

	public string $term_taxonomy = 'wp_term_taxonomy';

	public string $term_relationships = 'wp_term_relationships';

	public bool $ready = true;

	public string $last_error = '';

	public string $last_query = '';

	/** @var list<array<string,mixed>> */
	public array $membership_rows = array();

	/** @var list<array<string,mixed>> */
	public array $catalog_rows = array();

	/** @var list<string> */
	public array $queries = array();

	/** @var list<list<mixed>> */
	public array $prepare_calls = array();

	public function prepare( string $sql, ...$args ): string {
		$this->prepare_calls[] = $args;

		return $sql;
	}

	/** @return list<array<string,mixed>> */
	public function get_results( string $sql, string $format ): array {
		unset( $format );
		$this->last_query = $sql;
		$this->queries[]  = $sql;

		return false !== strpos( $sql, 'relationship.object_id AS product_id' )
			? $this->membership_rows
			: $this->catalog_rows;
	}
}

final class CollectionResolverUnavailableOwnershipWpdb {
	public string $prefix = 'wp_';

	public bool $ready = false;

	public function prepare( string $sql, ...$args ): string {
		unset( $args );

		return $sql;
	}

	/** @return list<int> */
	public function get_col( string $sql ): array {
		unset( $sql );

		return array();
	}
}

final class CollectionResolverTest extends TestCase {
	private CollectionResolverWpdb $database;

	/** @var list<int> */
	private array $owned_product_ids = array();

	/** @var array<int,\WP_Term> */
	private array $terms = array();

	/** @var array<int,string> */
	private array $product_titles = array();

	/**
	 * Marca de variante por producto, tal como la devolvería `wp_postmeta`.
	 *
	 * @var array<int, string>
	 */
	private array $product_variants = array();

	/** @var array<int,\WC_Product> */
	private array $wc_products = array();

	/** @var list<int> */
	private array $wc_product_calls = array();

	/** @var array<int,array<string,mixed>> */
	private array $term_meta = array();

	/** @var list<list<int>> */
	private array $prime_calls = array();

	/** @var array<string,mixed> */
	private array $get_terms_args = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
		Monkey\setUp();
		$this->database          = new CollectionResolverWpdb();
		$this->owned_product_ids = array();
		$this->terms             = array();
		$this->product_titles    = array();
		$this->product_variants  = array();
		$this->wc_products       = array();
		$this->wc_product_calls  = array();
		$this->term_meta         = array();
		$this->prime_calls       = array();
		$this->get_terms_args    = array();

		Functions\when( 'get_user_meta' )->justReturn( 'resolver-generation' );
		// Availability (1.109.0) deriva el estado comercial por get_post_meta:
		// sin metas, todo reservable es «A pedido» — el default de producción.
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $meta_key = '', $single = false ) {
				unset( $single );

				return VolumeVariant::META_KEY === $meta_key
					? ( $this->product_variants[ (int) $post_id ] ?? '' )
					: '';
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_transient' )->alias(
			function () {
				$status = array() === $this->owned_product_ids
					? CustomerCollection::RESULT_EMPTY
					: CustomerCollection::RESULT_OK;

				return array(
					'version'     => 3,
					'status'      => $status,
					'product_ids' => $this->owned_product_ids,
				);
			}
		);
		Functions\when( 'get_terms' )->alias(
			function ( array $args ): array {
				$this->get_terms_args = $args;
				$included             = array_fill_keys( array_map( 'intval', $args['include'] ?? array() ), true );

				return array_values(
					array_filter(
						$this->terms,
						static fn ( \WP_Term $term ): bool => isset( $included[ $term->term_id ] )
					)
				);
			}
		);
		Functions\when( '_prime_post_caches' )->alias(
			function ( array $ids, bool $update_term_cache, bool $update_meta_cache ): void {
				self::assertTrue( $update_term_cache );
				self::assertTrue( $update_meta_cache );
				$this->prime_calls[] = $ids;
			}
		);
		Functions\when( 'get_the_title' )->alias(
			fn( int $product_id ): string => $this->product_titles[ $product_id ] ?? ''
		);
		Functions\when( 'get_permalink' )->alias(
			static fn( int $product_id ): string => 'https://akibara.cl/producto/' . $product_id . '/'
		);
		Functions\when( 'wc_get_product' )->alias(
			function ( int $product_id ): \WC_Product {
				$this->wc_product_calls[] = $product_id;

				return $this->wc_products[ $product_id ] ?? new \WC_Product();
			}
		);
		Functions\when( 'get_term_link' )->alias(
			static fn( \WP_Term $term ): string => 'https://akibara.cl/serie/' . $term->slug . '/'
		);
		Functions\when( 'get_term_meta' )->alias(
			fn( int $term_id, string $key, bool $single ) => $single ? ( $this->term_meta[ $term_id ][ $key ] ?? '' ) : array()
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Purchases are grouped by exactly one series while hidden purchases and stockless catalogue products survive.
	 */
	public function test_resolves_complete_private_collection_in_bounded_batches(): void {
		$this->owned_product_ids = array( 401, 301, 201, 103, 102, 101 );
		$this->term( 10, 100, 'berserk', 'Berserk' );
		$this->term( 20, 200, 'nana', 'Nana' );
		$this->database->membership_rows = array(
			$this->membership( 101, 10, 100 ),
			$this->membership( 102, 10, 100 ),
			$this->membership( 103, 10, 100 ),
			$this->membership( 201, 20, 200 ),
			$this->membership( 401, 10, 100 ),
			$this->membership( 401, 20, 200 ),
		);
		$this->database->catalog_rows = array(
			$this->catalog_product( 100, 101 ),
			$this->catalog_product( 100, 102 ),
			$this->catalog_product( 100, 104 ),
			$this->catalog_product( 200, 202 ),
		);
		$this->product_titles = array(
			101 => 'Berserk 1',
			102 => 'Berserk 2',
			103 => 'Berserk 3',
			104 => 'Berserk 4',
			201 => 'Nana 1',
			202 => 'Nana 2',
			301 => 'Sin serie',
			401 => 'Doble serie',
		);
		$this->edition( 10, PublicationStatus::COMPLETE, 4 );
		$this->edition( 20, PublicationStatus::ONGOING, 2 );

		$result = $this->resolver()->resolve( 42 );

		self::assertSame( CustomerCollection::RESULT_OK, $result['status'] );
		self::assertSame( array( 301, 401 ), $result['unclassified_product_ids'] );
		self::assertSame( 2, $result['unclassified_count'] );
		self::assertSame( array( 'Berserk', 'Nana' ), array_column( $result['series'], 'name' ) );

		$berserk = $result['series'][0];
		self::assertSame( array( 101, 102, 103 ), $berserk['owned_product_ids'] );
		self::assertSame( 3, $berserk['owned_count'] );
		self::assertSame( array( 101, 102, 104 ), $berserk['catalog_product_ids'] );
		self::assertSame( array( 101, 102 ), $berserk['owned_in_catalog_product_ids'] );
		self::assertSame( array( 104 ), $berserk['missing_catalog_product_ids'] );
		self::assertSame( array( 103 ), $berserk['historical_only_product_ids'] );
		self::assertSame( '', $berserk['owned_products'][2]['url'], 'Un tomo histórico se muestra sin enlazar una ficha potencialmente privada o rota.' );
		self::assertSame(
			array(
				array(
					'id'               => 104,
					'title'            => 'Berserk 4',
					'url'              => 'https://akibara.cl/producto/104/',
					'volume_number'    => 4,
					'is_variant'       => false,
					'stock_status'     => 'instock',
					'is_purchasable'   => true,
					'preorder_label'   => '',
					'availability_state' => '',
					'waitlist_url'      => '',
				),
			),
			$berserk['missing_catalog_products']
		);
		self::assertSame( 104, $berserk['cover_product_id'], 'La portada prioriza el próximo tomo disponible.' );
		self::assertSame(
			array(
				'state'          => PublicationStatus::COMPLETE,
				'total'          => 4,
				'progress_owned' => 3,
				'missing_count'  => 1,
				'is_complete'    => false,
			),
			$berserk['edition']
		);

		$nana = $result['series'][1];
		self::assertSame( array( 201 ), $nana['historical_only_product_ids'] );
		self::assertSame( array( 202 ), $nana['missing_catalog_product_ids'] );
		self::assertFalse( $nana['edition']['is_complete'], 'En publicación nunca se presenta como completa.' );

		self::assertCount( 2, $this->database->queries, 'Membresías y catálogo son dos lecturas globales, no N+1 por serie.' );
		self::assertCount( 1, $this->prime_calls, 'Posts, metadatos y términos de producto se priman juntos.' );
		self::assertSame( array( 104, 202 ), $this->wc_product_calls, 'Sólo se hidratan las variantes candidatas, no todo el catálogo.' );
		self::assertFalse( $this->get_terms_args['hide_empty'] );
		self::assertTrue( $this->get_terms_args['update_term_meta_cache'] );
		self::assertSame( array( 10, 20 ), $this->get_terms_args['include'] );
		self::assertStringNotContainsString( '_stock_status', $this->database->queries[1] );
		self::assertStringNotContainsString( 'instock', $this->database->queries[1] );
		self::assertStringContainsString( 'exclude-from-catalog', $this->database->prepare_calls[1][count( $this->database->prepare_calls[1] ) - 1] );
	}

	/**
	 * @dataProvider editorialProgressProvider
	 * @testdox Editorial progress never invents completion from ambiguous metadata or excess products.
	 */
	public function test_editorial_progress_contract(
		string $state,
		int $total,
		int $owned_count,
		?int $expected_progress,
		?int $expected_missing,
		?bool $expected_complete
	): void {
		$this->term( 10, 100, 'serie-prueba', 'Serie prueba' );
		$this->owned_product_ids = range( 1, $owned_count );
		foreach ( $this->owned_product_ids as $product_id ) {
			$this->database->membership_rows[] = $this->membership( $product_id, 10, 100 );
			$this->product_titles[ $product_id ] = 'Tomo ' . $product_id;
		}
		$this->database->catalog_rows = array();
		$this->edition( 10, $state, $total );
		$this->term_meta[10][ PublicationStatus::META_WORK_STATE ] = PublicationStatus::COMPLETE;
		$this->term_meta[10][ PublicationStatus::META_WORK_TOTAL ] = 999;

		$result  = $this->resolver()->resolve( 42 );
		$edition = $result['series'][0]['edition'];

		self::assertSame( $state, $edition['state'] );
		self::assertSame( $total, $edition['total'] );
		self::assertSame( $expected_progress, $edition['progress_owned'] );
		self::assertSame( $expected_missing, $edition['missing_count'] );
		self::assertSame( $expected_complete, $edition['is_complete'] );
	}

	/** @testdox Continuity uses logical volume identity instead of current product IDs. */
	public function test_continuity_ignores_reprint_ids_and_advances_to_the_next_volume(): void {
		$this->term( 10, 100, 'serie-prueba', 'Serie prueba' );
		$this->owned_product_ids = array( 101, 102 );
		$this->database->membership_rows = array(
			$this->membership( 101, 10, 100 ),
			$this->membership( 102, 10, 100 ),
		);
		$this->database->catalog_rows = array(
			$this->catalog_product( 100, 201 ),
			$this->catalog_product( 100, 202 ),
			$this->catalog_product( 100, 203 ),
		);
		$this->product_titles = array(
			101 => 'Serie prueba 1 (Edición anterior)',
			102 => 'Serie prueba 2 (Portada alternativa)',
			201 => 'Serie prueba 1',
			202 => 'Serie prueba 2',
			203 => 'Serie prueba 3',
		);
		$this->wc_products[203] = new \WC_Product( 'onbackorder' );
		$this->edition( 10, PublicationStatus::ONGOING, 3 );

		$result = $this->resolver()->resolve( 42 );
		$series = $result['series'][0];

		self::assertSame( 2, $series['owned_volume_count'] );
		self::assertSame( 2, $series['edition']['progress_owned'] );
		self::assertSame( 'next', $series['continuity']['kind'] );
		self::assertSame( 3, $series['continuity']['volume_number'] );
		self::assertSame( 'preorder', $series['continuity']['availability'] );
		// «A pedido»: reservable sin metas de lanzamiento (Availability 1.109.0);
		// «Preventa» exigiría un `_akb_eta_type` tba/release_date futuro.
		self::assertSame( 'A pedido', $series['continuity']['product']['preorder_label'] );
		self::assertSame( 203, $series['cover_product_id'] );
	}

	/** @testdox Continuity never skips an absent catalogue slot to recommend a later product. */
	public function test_continuity_stops_at_an_absent_catalogue_volume(): void {
		$this->term( 10, 100, 'serie-prueba', 'Serie prueba' );
		$this->owned_product_ids = array( 101, 102, 103, 104 );
		foreach ( $this->owned_product_ids as $product_id ) {
			$this->database->membership_rows[] = $this->membership( $product_id, 10, 100 );
		}
		$this->database->catalog_rows = array(
			$this->catalog_product( 100, 101 ),
			$this->catalog_product( 100, 102 ),
			$this->catalog_product( 100, 103 ),
			$this->catalog_product( 100, 104 ),
			$this->catalog_product( 100, 106 ),
		);
		$this->product_titles = array(
			101 => 'Serie prueba 1',
			102 => 'Serie prueba 2',
			103 => 'Serie prueba 3',
			104 => 'Serie prueba 4',
			106 => 'Serie prueba 6',
		);
		$this->edition( 10, PublicationStatus::COMPLETE, 13 );

		$series = $this->resolver()->resolve( 42 )['series'][0];

		self::assertSame( 'next', $series['continuity']['kind'] );
		self::assertSame( 5, $series['continuity']['volume_number'] );
		self::assertSame( 'unknown', $series['continuity']['availability'] );
		self::assertNull( $series['continuity']['product'], 'Sin producto del tomo 5 no debe fabricarse una URL ni saltarse al 6.' );
		self::assertSame( array(), $series['continuity']['options'] );
		self::assertSame( array(), $this->wc_product_calls, 'No se hidrata el tomo 6 porque aún no es la continuidad válida.' );
	}

	/** @testdox Duplicate purchased product IDs for one volume count as one logical slot. */
	public function test_duplicate_owned_versions_do_not_inflate_editorial_progress(): void {
		$this->term( 10, 100, 'serie-prueba', 'Serie prueba' );
		$this->owned_product_ids = array( 101, 102 );
		$this->database->membership_rows = array(
			$this->membership( 101, 10, 100 ),
			$this->membership( 102, 10, 100 ),
		);
		$this->database->catalog_rows = array(
			$this->catalog_product( 100, 101 ),
			$this->catalog_product( 100, 102 ),
			$this->catalog_product( 100, 103 ),
		);
		$this->product_titles = array(
			101 => 'Serie prueba 1',
			102 => 'Serie prueba 1 (Portada alternativa)',
			103 => 'Serie prueba 2',
		);
		$this->edition( 10, PublicationStatus::COMPLETE, 2 );

		$series = $this->resolver()->resolve( 42 )['series'][0];

		self::assertSame( 2, $series['owned_count'], 'Se conservan ambas compras físicas en el historial.' );
		self::assertSame( 1, $series['owned_volume_count'], 'Ambas compras ocupan un único tomo lógico.' );
		self::assertSame( 1, $series['edition']['progress_owned'] );
		self::assertSame( 1, $series['edition']['missing_count'] );
		self::assertSame( 2, $series['continuity']['volume_number'] );
	}

	/** @testdox Unnumbered purchases keep progress unknown instead of inventing completion. */
	public function test_unnumbered_purchase_does_not_create_false_editorial_progress(): void {
		$this->term( 10, 100, 'integral', 'Integral' );
		$this->owned_product_ids = array( 101 );
		$this->database->membership_rows = array( $this->membership( 101, 10, 100 ) );
		$this->database->catalog_rows = array( $this->catalog_product( 100, 101 ) );
		$this->product_titles = array( 101 => 'Integral edición especial' );
		$this->edition( 10, PublicationStatus::COMPLETE, 1 );

		$series = $this->resolver()->resolve( 42 )['series'][0];

		self::assertNull( $series['owned_volume_count'] );
		self::assertNull( $series['edition']['progress_owned'] );
		self::assertNull( $series['edition']['is_complete'] );
		self::assertSame( 'unknown', $series['continuity']['kind'] );
	}

	/** @testdox Missing term links never receive a series fragment on a product fallback URL. */
	public function test_product_fallback_and_waitlist_urls_use_their_correct_anchors(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'akibara_loop_waitlist_active' === $hook ? true : $value;
			}
		);
		Functions\when( 'get_term_link' )->justReturn( new \WP_Error( 'missing_term', 'Term unavailable' ) );
		$this->term( 10, 100, 'serie-prueba', 'Serie prueba' );
		$this->owned_product_ids = array( 101 );
		$this->database->membership_rows = array( $this->membership( 101, 10, 100 ) );
		$this->database->catalog_rows = array(
			$this->catalog_product( 100, 101 ),
			$this->catalog_product( 100, 102 ),
		);
		$this->product_titles = array( 101 => 'Serie prueba 1', 102 => 'Serie prueba 2' );
		$this->wc_products[102] = new \WC_Product( 'outofstock', array(), false );
		$this->edition( 10, PublicationStatus::ONGOING, 2 );

		$series = $this->resolver()->resolve( 42 )['series'][0];

		self::assertSame( 'out_of_stock', $series['continuity']['availability'] );
		self::assertSame(
			'https://akibara.cl/producto/102/#akb-bis-form',
			$series['continuity']['product']['waitlist_url']
		);
		self::assertSame( 'https://akibara.cl/producto/102/', $series['completion_url'] );
		self::assertStringNotContainsString( '#completar-coleccion', $series['completion_url'] );
	}

	/** @return array<string,array{0:string,1:int,2:int,3:?int,4:?int,5:?bool}> */
	public function editorialProgressProvider(): array {
		return array(
			'complete exact'       => array( PublicationStatus::COMPLETE, 2, 2, 2, 0, true ),
			'ongoing exact'        => array( PublicationStatus::ONGOING, 2, 2, 2, 0, false ),
			'complete excess'      => array( PublicationStatus::COMPLETE, 1, 2, null, null, null ),
			'paused edition'       => array( PublicationStatus::PAUSED, 3, 1, null, null, null ),
			'missing edition data' => array( '', 0, 1, null, null, null ),
		);
	}

	/** @testdox Empty reliable ownership short-circuits before taxonomy or catalogue reads. */
	public function test_empty_collection_short_circuits(): void {
		$result = $this->resolver()->resolve( 42 );

		self::assertSame(
			array(
				'status'                   => CustomerCollection::RESULT_EMPTY,
				'series'                   => array(),
				'unclassified_product_ids' => array(),
				'unclassified_count'       => 0,
			),
			$result
		);
		self::assertSame( array(), $this->database->queries );
		self::assertSame( array(), $this->prime_calls );
	}

	/** @testdox Unavailable ownership stays unavailable instead of displaying a false empty state. */
	public function test_unavailable_ownership_is_preserved(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		$collection = new CustomerCollection( new CollectionResolverUnavailableOwnershipWpdb() );
		$result     = ( new CollectionResolver( $collection, $this->database ) )->resolve( 42 );

		self::assertSame( CustomerCollection::RESULT_UNAVAILABLE, $result['status'] );
		self::assertSame( array(), $result['series'] );
		self::assertSame( array(), $this->database->queries );
	}

	/** @testdox A silent membership SQL failure stays unavailable and never becomes unclassified ownership. */
	public function test_silent_membership_error_is_unavailable(): void {
		$this->owned_product_ids = array( 10 );
		$this->database->last_error = 'database offline';

		$result = $this->resolver()->resolve( 42 );

		self::assertSame( CustomerCollection::RESULT_UNAVAILABLE, $result['status'] );
		self::assertSame( array(), $result['unclassified_product_ids'] );
	}

	/**
	 * El escenario de la edición especial: un número de tomo con DOS productos.
	 *
	 * `pa_serie` 10 tiene los tomos 1..3 y, además, una edición especial del 3.
	 * Es el primer caso del catálogo con dos productos del mismo número, y la
	 * pregunta que contesta esta pantalla —«¿puedo leer la serie de corrido?»—
	 * se responde por TOMO, no por producto.
	 *
	 * @param array<int, int> $owned Compras del cliente.
	 */
	private function serie_con_variante_del_tomo_3( array $owned ): array {
		$this->owned_product_ids = $owned;
		$this->term( 10, 100, 'demon-slayer', 'Demon Slayer' );

		$this->database->membership_rows = array();
		foreach ( $owned as $product_id ) {
			$this->database->membership_rows[] = $this->membership( $product_id, 10, 100 );
		}
		$this->database->catalog_rows = array(
			$this->catalog_product( 100, 101 ),
			$this->catalog_product( 100, 102 ),
			$this->catalog_product( 100, 103 ),
			$this->catalog_product( 100, 113 ),
		);
		$this->product_titles = array(
			101 => 'Demon Slayer 1',
			102 => 'Demon Slayer 2',
			103 => 'Demon Slayer 3',
			113 => 'Demon Slayer 3 (Edición Especial)',
		);
		$this->product_variants = array( 113 => VolumeVariant::KIND_EDICION_ESPECIAL );
		$this->edition( 10, PublicationStatus::COMPLETE, 3 );

		$result = $this->resolver()->resolve( 42 );

		return $result['series'][0];
	}

	/**
	 * @testdox Quien tiene los 3 tomos no tiene faltantes aunque exista la edición especial del 3.
	 */
	public function test_la_serie_completa_no_tiene_faltantes_con_una_variante_en_catalogo(): void {
		$serie = $this->serie_con_variante_del_tomo_3( array( 101, 102, 103 ) );

		self::assertSame( array(), $serie['missing_catalog_product_ids'] );
		self::assertSame( 0, $serie['missing_catalog_count'] );
		self::assertSame( 3, $serie['owned_volume_count'], 'Tres tomos lógicos, cuatro productos en catálogo.' );
	}

	/**
	 * @testdox La simétrica: quien sólo tiene la edición especial del 3 tampoco necesita el 3 normal.
	 */
	public function test_tener_solo_la_variante_tapa_el_hueco_de_ese_tomo(): void {
		$serie = $this->serie_con_variante_del_tomo_3( array( 101, 102, 113 ) );

		self::assertSame( array(), $serie['missing_catalog_product_ids'] );
		self::assertSame( 0, $serie['missing_catalog_count'] );
	}

	/**
	 * @testdox Un tomo que falta de verdad se nombra UNA vez, con el tomo normal y no con la variante.
	 */
	public function test_un_tomo_que_falta_se_nombra_una_vez_y_con_el_tomo_normal(): void {
		$serie = $this->serie_con_variante_del_tomo_3( array( 101, 102 ) );

		self::assertSame( array( 103 ), $serie['missing_catalog_product_ids'] );
		self::assertSame( 1, $serie['missing_catalog_count'] );
	}

	/**
	 * El desempate no puede depender del orden del título.
	 *
	 * `order_ids_by_product()` ordena por `strnatcasecmp`, y hay títulos reales
	 * del catálogo donde la variante gana ese orden: «One Piece 05 (Edición
	 * Especial)» va ANTES que «One Piece nº 05» porque '0' < 'n'. Con el
	 * desempate por orden, el botón «Seleccionar el tomo que te falta» ofrecía la
	 * variante cara en lugar del tomo. Lo que decide es la marca del dueño.
	 *
	 * @testdox Si la variante ordena ANTES que su gemelo, el faltante se sigue nombrando con el tomo normal.
	 */
	public function test_el_desempate_usa_la_marca_y_no_el_orden_del_titulo(): void {
		$this->owned_product_ids = array( 101 );
		$this->term( 10, 100, 'one-piece', 'One Piece' );
		$this->database->membership_rows = array( $this->membership( 101, 10, 100 ) );
		$this->database->catalog_rows    = array(
			$this->catalog_product( 100, 101 ),
			// El id de la variante es el MENOR de los dos, además del título que
			// ordena primero: los dos desempates viejos fallan a la vez.
			$this->catalog_product( 100, 105 ),
			$this->catalog_product( 100, 205 ),
		);
		$this->product_titles = array(
			101 => 'One Piece nº 01',
			105 => 'One Piece 05 (Edición Especial)',
			205 => 'One Piece nº 05',
		);
		$this->product_variants = array( 105 => VolumeVariant::KIND_EDICION_ESPECIAL );
		$this->edition( 10, PublicationStatus::ONGOING, 5 );

		$serie = $this->resolver()->resolve( 42 )['series'][0];

		self::assertSame( array( 205 ), $serie['missing_catalog_product_ids'], 'El tomo 5 se ofrece con el tomo normal, no con la edición especial.' );
		self::assertSame( 1, $serie['missing_catalog_count'] );
	}

	/**
	 * @testdox [REG] Sin números repetidos, el cálculo por tomo da lo mismo que la resta por producto.
	 *
	 * Es la migración: hoy ninguna de las 110 series de producción tiene dos
	 * productos con el mismo número, así que el cambio tiene que ser un no-op
	 * medible, no una mejora que además mueva lo que ya estaba bien.
	 */
	public function test_sin_numeros_repetidos_el_resultado_no_cambia(): void {
		$this->owned_product_ids = array( 101, 103 );
		$this->term( 10, 100, 'berserk', 'Berserk' );
		$this->database->membership_rows = array(
			$this->membership( 101, 10, 100 ),
			$this->membership( 103, 10, 100 ),
		);
		$this->database->catalog_rows = array(
			$this->catalog_product( 100, 101 ),
			$this->catalog_product( 100, 102 ),
			$this->catalog_product( 100, 103 ),
			$this->catalog_product( 100, 104 ),
		);
		$this->product_titles = array(
			101 => 'Berserk 1',
			102 => 'Berserk 2',
			103 => 'Berserk 3',
			104 => 'Berserk 4',
		);
		$this->edition( 10, PublicationStatus::COMPLETE, 4 );

		$serie = $this->resolver()->resolve( 42 )['series'][0];

		self::assertSame( array( 102, 104 ), $serie['missing_catalog_product_ids'] );
		self::assertSame( 2, $serie['missing_catalog_count'] );
	}

	/**
	 * @testdox Un producto sin número inequívoco no se colapsa con ningún otro.
	 */
	public function test_los_productos_sin_numero_no_se_colapsan(): void {
		$this->owned_product_ids = array( 101 );
		$this->term( 10, 100, 'berserk', 'Berserk' );
		$this->database->membership_rows = array( $this->membership( 101, 10, 100 ) );
		$this->database->catalog_rows    = array(
			$this->catalog_product( 100, 101 ),
			$this->catalog_product( 100, 201 ),
			$this->catalog_product( 100, 202 ),
		);
		$this->product_titles = array(
			101 => 'Berserk 1',
			201 => 'Berserk (Integral)',
			202 => 'Pack Berserk 1+2+3',
		);
		$this->edition( 10, PublicationStatus::COMPLETE, 3 );

		$serie = $this->resolver()->resolve( 42 )['series'][0];

		self::assertSame( array( 201, 202 ), $serie['missing_catalog_product_ids'] );
	}

	/**
	 * @testdox Manual owned items can be marked, retrieved and unmarked in user meta.
	 */
	public function test_manual_owned_items_can_be_marked_and_retrieved(): void {
		$meta_store = array();
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) use ( &$meta_store ) {
				unset( $single );
				return $meta_store[ $user_id ][ $key ] ?? array();
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( int $user_id, string $key, $value ) use ( &$meta_store ) {
				$meta_store[ $user_id ][ $key ] = $value;
				return true;
			}
		);

		self::assertSame( array(), CollectionResolver::manual_owned_product_ids( 42 ) );

		CollectionResolver::mark_manual_owned( 42, 104, true );
		self::assertSame( array( 104 ), CollectionResolver::manual_owned_product_ids( 42 ) );

		CollectionResolver::mark_manual_owned( 42, 102, true );
		self::assertSame( array( 102, 104 ), CollectionResolver::manual_owned_product_ids( 42 ) );

		CollectionResolver::mark_manual_owned( 42, 104, false );
		self::assertSame( array( 102 ), CollectionResolver::manual_owned_product_ids( 42 ) );
	}

	/**
	 * @testdox Manual owned item unblocks gap in continuity resolution.
	 */
	public function test_manual_owned_item_unblocks_gap_in_continuity(): void {
		$meta_store = array(
			42 => array(
				CollectionResolver::USER_META_MANUAL_OWNED => array( 102 ),
			),
		);
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) use ( &$meta_store ) {
				unset( $single );
				return $meta_store[ $user_id ][ $key ] ?? 'generation';
			}
		);

		// Cliente compró el 101 y el 103 en Akibara; marcó manualmente el 102 (comprado afuera).
		$this->owned_product_ids = array( 101, 103 );
		$this->term( 10, 100, 'berserk', 'Berserk' );
		$this->database->membership_rows = array(
			$this->membership( 101, 10, 100 ),
			$this->membership( 102, 10, 100 ),
			$this->membership( 103, 10, 100 ),
		);
		$this->database->catalog_rows = array(
			$this->catalog_product( 100, 101 ),
			$this->catalog_product( 100, 102 ),
			$this->catalog_product( 100, 103 ),
			$this->catalog_product( 100, 104 ),
		);
		$this->product_titles = array(
			101 => 'Berserk 1',
			102 => 'Berserk 2',
			103 => 'Berserk 3',
			104 => 'Berserk 4',
		);
		$this->edition( 10, PublicationStatus::COMPLETE, 4 );

		$serie = $this->resolver()->resolve( 42 )['series'][0];

		// Gracias al tomo manual 102, ya no hay gap en el 2: el siguiente tomo sugerido es el 4.
		self::assertSame( 'next', $serie['continuity']['kind'] );
		self::assertSame( 4, $serie['continuity']['volume_number'] );
		self::assertSame( array( 102 ), $serie['manual_owned_product_ids'] );
	}

	/**
	 * @testdox Bundle suggestion is generated when 2 or more missing volumes are available in stock.
	 */
	public function test_bundle_suggestion_is_generated_for_multiple_missing_volumes(): void {
		$this->owned_product_ids = array( 101 );
		$this->term( 10, 100, 'berserk', 'Berserk' );
		$this->database->membership_rows = array(
			$this->membership( 101, 10, 100 ),
		);
		$this->database->catalog_rows = array(
			$this->catalog_product( 100, 101 ),
			$this->catalog_product( 100, 102 ),
			$this->catalog_product( 100, 103 ),
		);
		$this->product_titles = array(
			101 => 'Berserk 1',
			102 => 'Berserk 2',
			103 => 'Berserk 3',
		);
		$this->edition( 10, PublicationStatus::COMPLETE, 3 );

		$serie = $this->resolver()->resolve( 42 )['series'][0];

		self::assertNotNull( $serie['bundle_suggestion'] );
		self::assertTrue( $serie['bundle_suggestion']['available'] );
		self::assertFalse( $serie['bundle_suggestion']['has_discount'] );
		self::assertSame( 2, $serie['bundle_suggestion']['count'] );
		self::assertStringContainsString( 'Te faltan solo 2 tomos', $serie['bundle_suggestion']['label'] );
	}

	public function test_bundle_suggestion_is_null_when_series_bundle_is_disabled(): void {
		$this->owned_product_ids = array( 101 );
		$this->term( 10, 100, 'berserk', 'Berserk' );
		$this->database->membership_rows = array(
			$this->membership( 101, 10, 100 ),
		);
		$this->database->catalog_rows = array(
			$this->catalog_product( 100, 101 ),
			$this->catalog_product( 100, 102 ),
			$this->catalog_product( 100, 103 ),
		);
		$this->product_titles = array(
			101 => 'Berserk 1',
			102 => 'Berserk 2',
			103 => 'Berserk 3',
		);
		$this->edition( 10, PublicationStatus::COMPLETE, 3 );

		// Resolver con BYP deshabilitado para el término 10
		$resolver = new CollectionResolver(
			new CustomerCollection(),
			$this->database,
			null,
			null,
			null,
			static fn( int $tid ): bool => false
		);

		$serie = $resolver->resolve( 42 )['series'][0];
		self::assertNull( $serie['bundle_suggestion'] );
	}

	public function test_bundle_suggestion_promises_discount_when_missing_at_least_five_volumes(): void {
		$this->owned_product_ids = array( 101 );
		$this->term( 10, 100, 'berserk', 'Berserk' );
		$this->database->membership_rows = array(
			$this->membership( 101, 10, 100 ),
		);
		$this->database->catalog_rows = array(
			$this->catalog_product( 100, 101 ),
			$this->catalog_product( 100, 102 ),
			$this->catalog_product( 100, 103 ),
			$this->catalog_product( 100, 104 ),
			$this->catalog_product( 100, 105 ),
			$this->catalog_product( 100, 106 ),
		);
		$this->product_titles = array(
			101 => 'Berserk 1',
			102 => 'Berserk 2',
			103 => 'Berserk 3',
			104 => 'Berserk 4',
			105 => 'Berserk 5',
			106 => 'Berserk 6',
		);
		$this->edition( 10, PublicationStatus::COMPLETE, 6 );

		$resolver = new CollectionResolver(
			new CustomerCollection(),
			$this->database,
			null,
			null,
			null,
			static fn( int $tid ): bool => true
		);

		$serie = $resolver->resolve( 42 )['series'][0];
		self::assertNotNull( $serie['bundle_suggestion'] );
		self::assertTrue( $serie['bundle_suggestion']['has_discount'] );
		self::assertSame( 5, $serie['bundle_suggestion']['count'] );
		self::assertStringContainsString( 'con descuento', $serie['bundle_suggestion']['label'] );
	}

	private function resolver(): CollectionResolver {
		return new CollectionResolver( new CustomerCollection(), $this->database );
	}

	private function term( int $term_id, int $term_taxonomy_id, string $slug, string $name ): void {
		$term                   = new \WP_Term( $term_id, $slug, $name );
		$term->taxonomy         = 'pa_serie';
		$term->term_taxonomy_id = $term_taxonomy_id;
		$this->terms[ $term_id ] = $term;
	}

	/** @return array{product_id:int,term_id:int,term_taxonomy_id:int} */
	private function membership( int $product_id, int $term_id, int $term_taxonomy_id ): array {
		return compact( 'product_id', 'term_id', 'term_taxonomy_id' );
	}

	/** @return array{term_taxonomy_id:int,product_id:int} */
	private function catalog_product( int $term_taxonomy_id, int $product_id ): array {
		return compact( 'term_taxonomy_id', 'product_id' );
	}

	private function edition( int $term_id, string $state, int $total ): void {
		$this->term_meta[ $term_id ][ PublicationStatus::META_EDITION_STATE ] = $state;
		$this->term_meta[ $term_id ][ PublicationStatus::META_EDITION_TOTAL ] = $total;
	}
}
