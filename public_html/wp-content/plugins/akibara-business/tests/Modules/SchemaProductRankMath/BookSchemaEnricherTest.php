<?php
/**
 * Tests for BookSchemaEnricher.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SchemaProductRankMath;

use Akibara\Business\Modules\SchemaProductRankMath\BookSchemaEnricher;
use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Brain\Monkey;
use Akibara\Business\Services\VolumeVariant;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class BookSchemaEnricherTest extends TestCase {

	/** Marca `_akb_variante_tomo` del producto bajo prueba. */
	private string $variante = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->variante = '';

		Functions\when( 'wp_list_pluck' )->alias(
			static fn( array $items, string $field ): array => array_values(
				array_map( static fn( object $item ) => $item->{$field}, $items )
			)
		);
		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => 'product_cat' === $taxonomy
				? array( (object) array( 'slug' => 'manga' ) )
				: array()
		);
		Functions\when( 'get_term_link' )->alias(
			static fn( \WP_Term $term ): string => 'https://akibara.cl/' . $term->taxonomy . '/' . $term->slug . '/'
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'get_post_meta' )->alias(
			fn ( int $id, string $key = '', bool $single = false ): string => VolumeVariant::META_KEY === $key
				? $this->variante
				: ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should emit only ISBN values with a valid checksum.
	 * @dataProvider isbn_data
	 */
	public function test_emits_only_valid_isbn_values( string $sku, ?string $expected ): void {
		$product = new class( $sku ) extends \WC_Product {
			private string $sku;

			public function __construct( string $sku ) {
				parent::__construct();
				$this->sku = $sku;
			}

			public function get_id(): int {
				return 123;
			}

			public function get_sku(): string {
				return $this->sku;
			}
		};

		$result = ( new BookSchemaEnricher() )->enrich( array( '@type' => 'Product' ), $product );

		self::assertSame( $expected, $result['isbn'] ?? null, 'Schema ISBN should contain only a normalized valid ISBN.' );
		self::assertArrayNotHasKey( 'bookEdition', $result, 'Publisher must not be used as bookEdition.' );
		self::assertArrayNotHasKey( 'bookFormat', $result, 'Book format must not be inferred without catalog data.' );
	}

	/**
	 * Valid ISBN values and identifiers that are not ISBNs.
	 *
	 * @return array<string,array{string,string|null}>
	 */
	public static function isbn_data(): array {
		return array(
			'Valid ISBN-13'      => array( '9791388298844', '9791388298844' ),
			'Hyphenated ISBN-10' => array( '0-306-40615-2', '0306406152' ),
			'Non-ISBN EAN-13'    => array( '7503034771937', null ),
			'Invalid checksum'   => array( '9791388298845', null ),
		);
	}

	/**
	 * @testdox Should preserve every assigned coauthor in a stable deduplicated list.
	 */
	public function test_preserves_every_assigned_coauthor(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}

			public function get_sku(): string {
				return '';
			}
		};
		$naoki   = new \WP_Term( 253, 'naoki-urasawa', 'Naoki Urasawa' );
		$takashi = new \WP_Term( 401, 'takashi-nagasaki', 'Takashi Nagasaki' );
		$yoshimi = new \WP_Term( 402, 'yoshimi', 'Yoshimi' );

		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => match ( $taxonomy ) {
				'product_cat' => array( (object) array( 'slug' => 'manga' ) ),
				'pa_autor'    => array( $yoshimi, $takashi, $naoki, $takashi ),
				default       => array(),
			}
		);

		$result = ( new BookSchemaEnricher() )->enrich( array( '@type' => 'Product' ), $product );

		self::assertSame(
			array( 'Naoki Urasawa', 'Takashi Nagasaki', 'Yoshimi' ),
			array_column( $result['author'], 'name' ),
			'No assigned coauthor should be truncated.'
		);
		self::assertCount( 3, $result['author'], 'Duplicate author terms must not duplicate Person entities.' );
	}

	/**
	 * @testdox Should preserve one Person as an object instead of a one-item list.
	 */
	public function test_preserves_single_author_shape(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}

			public function get_sku(): string {
				return '';
			}
		};
		$author = new \WP_Term( 253, 'naoki-urasawa', 'Naoki Urasawa' );

		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => match ( $taxonomy ) {
				'product_cat' => array( (object) array( 'slug' => 'manga' ) ),
				'pa_autor'    => array( $author ),
				default       => array(),
			}
		);

		$result = ( new BookSchemaEnricher() )->enrich( array( '@type' => 'Product' ), $product );

		self::assertSame( 'Person', $result['author']['@type'] );
		self::assertSame( 'Naoki Urasawa', $result['author']['name'] );
	}

	/**
	 * @testdox Should restore ComicSeries without changing the page relationship.
	 */
	public function test_restores_comic_series_without_changing_main_entity_of_page(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}
		};
		$series           = new \WP_Term( 99, 'dan-da-dan', 'Dan Da Dan' );
		$series->taxonomy = 'pa_serie';

		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => 'pa_serie' === $taxonomy
				? array( $series )
				: array()
		);

		$entity = array(
			'@type'           => array( 'Product', 'Book' ),
			'isPartOf'        => array( '@id' => 'https://akibara.cl/producto/dan-da-dan-22/#webpage' ),
			'mainEntityOfPage' => array( '@id' => 'https://akibara.cl/producto/dan-da-dan-22/#webpage' ),
		);
		$result = ( new BookSchemaEnricher() )->restore_series_relationship( $entity, $product );

		// El `@id` es lo que conecta la ficha con la entidad que `SeriesSchema`
		// declara en la landing: en JSON-LD dos nodos son el mismo cuando
		// comparten identificador, no cuando comparten `url`. Lleva el sufijo
		// `#serie` porque en la landing la URL pelada ya la usan como `@id` el
		// `CollectionPage` de Rank Math y el último breadcrumb, y sin fragmento
		// la serie se fusionaría con la página.
		self::assertSame(
			array(
				'@type' => 'ComicSeries',
				'name'  => 'Dan Da Dan',
				'@id'   => 'https://akibara.cl/pa_serie/dan-da-dan/#serie',
				'url'   => 'https://akibara.cl/pa_serie/dan-da-dan/',
			),
			$result['isPartOf']
		);
		self::assertSame( $entity['mainEntityOfPage'], $result['mainEntityOfPage'] );
	}

	/**
	 * @testdox Should preserve the existing product relationship for an explicitly non-comic term.
	 */
	public function test_preserves_existing_relationship_for_a_non_comic_term(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}
		};
		$series           = new \WP_Term( 99, 'novela-ejemplo', 'Novela ejemplo' );
		$series->taxonomy = 'pa_serie';

		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => 'pa_serie' === $taxonomy
				? array( $series )
				: array()
		);
		Functions\when( 'get_term_meta' )->alias(
			static fn( int $term_id, string $key, bool $single ): string => 99 === $term_id && SeriesSchemaType::META_KEY === $key && $single
				? SeriesSchemaType::NON_COMIC
				: ''
		);

		$entity = array(
			'@type'    => array( 'Product', 'Book' ),
			'isPartOf' => array( '@id' => 'https://akibara.cl/producto/novela-ejemplo/#webpage' ),
		);
		$result = ( new BookSchemaEnricher() )->restore_series_relationship( $entity, $product );

		self::assertSame( $entity, $result );
		self::assertStringNotContainsString( 'ComicSeries', (string) json_encode( $result ) );
	}

	/** @testdox Should remove an upstream ComicSeries relationship from a non-comic product. */
	public function test_removes_an_upstream_comic_series_relationship_for_a_non_comic_term(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}
		};
		$series           = new \WP_Term( 99, 'novela-ejemplo', 'Novela ejemplo' );
		$series->taxonomy = 'pa_serie';

		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => 'pa_serie' === $taxonomy
				? array( $series )
				: array()
		);
		Functions\when( 'get_term_meta' )->justReturn( SeriesSchemaType::NON_COMIC );

		$entity = array(
			'@type'           => array( 'Product', 'Book' ),
			'isPartOf'        => array(
				'@type' => array( 'CreativeWorkSeries', 'ComicSeries' ),
				'@id'   => 'https://akibara.cl/serie/novela-ejemplo/#serie',
			),
			'position'        => 1,
			'mainEntityOfPage' => array( '@id' => 'https://akibara.cl/producto/novela-ejemplo/#webpage' ),
		);
		$result = ( new BookSchemaEnricher() )->restore_series_relationship( $entity, $product );

		self::assertArrayNotHasKey( 'isPartOf', $result );
		self::assertArrayNotHasKey( 'position', $result );
		self::assertSame( $entity['mainEntityOfPage'], $result['mainEntityOfPage'] );
		self::assertStringNotContainsString( 'ComicSeries', (string) json_encode( $result ) );
	}

	/**
	 * @testdox Should not invent a series identifier when the term link fails.
	 */
	public function test_omits_the_series_identifier_without_a_usable_term_link(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}
		};
		$series           = new \WP_Term( 99, 'dan-da-dan', 'Dan Da Dan' );
		$series->taxonomy = 'pa_serie';

		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => 'pa_serie' === $taxonomy
				? array( $series )
				: array()
		);
		Functions\when( 'get_term_link' )->justReturn( new \WP_Error() );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = ( new BookSchemaEnricher() )
			->restore_series_relationship( array( '@type' => array( 'Product', 'Book' ) ), $product );

		self::assertSame(
			array(
				'@type' => 'ComicSeries',
				'name'  => 'Dan Da Dan',
			),
			$result['isPartOf'],
			'Sin enlace utilizable la referencia degrada a nodo anónimo, nunca a un @id inventado.'
		);
	}

	/**
	 * `position` (CreativeWork) es el nº de tomo machine-readable: la respuesta
	 * a «¿qué tomo es este?» que el título ya lleva por convención del pipeline.
	 *
	 * @testdox Should declare the volume number as position when the title carries it.
	 */
	public function test_declares_the_volume_number_as_position(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}
			public function get_name( $context = 'view' ): string {
				unset( $context );
				return 'Dan Da Dan 22';
			}
		};
		$series           = new \WP_Term( 99, 'dan-da-dan', 'Dan Da Dan' );
		$series->taxonomy = 'pa_serie';

		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => 'pa_serie' === $taxonomy
				? array( $series )
				: array()
		);

		$result = ( new BookSchemaEnricher() )
			->restore_series_relationship( array( '@type' => array( 'Product', 'Book' ) ), $product );

		self::assertSame( 22, $result['position'] );
	}

	/**
	 * Dos productos del mismo número bajo el mismo ComicSeries no pueden
	 * publicar los dos `position: 23`: es una contradicción machine-readable.
	 * Sin la propiedad el consumidor no sabe la posición; con dos iguales, sabe
	 * algo falso. La pertenencia a la serie SÍ se conserva.
	 *
	 * @testdox Should omit position for a marked variant, keeping isPartOf.
	 */
	public function test_omits_position_for_a_marked_variant(): void {
		$this->variante = VolumeVariant::KIND_EDICION_ESPECIAL;
		$product        = new class() extends \WC_Product {
			public function get_id(): int {
				return 27916;
			}
			public function get_name( $context = 'view' ): string {
				unset( $context );
				return 'Demon Slayer 23 (Edición Especial)';
			}
		};
		$series           = new \WP_Term( 435, 'demon-slayer', 'Demon Slayer' );
		$series->taxonomy = 'pa_serie';

		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => 'pa_serie' === $taxonomy
				? array( $series )
				: array()
		);

		$result = ( new BookSchemaEnricher() )
			->restore_series_relationship( array( '@type' => array( 'Product', 'Book' ) ), $product );

		self::assertArrayNotHasKey( 'position', $result );
		self::assertSame( 'ComicSeries', $result['isPartOf']['@type'] );
	}

	/**
	 * @testdox Should omit position when the parser cannot extract a volume number.
	 */
	public function test_omits_position_without_an_extractable_volume(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}
			public function get_name( $context = 'view' ): string {
				unset( $context );
				return 'Pack Vinland Saga 1+2+3';
			}
		};
		$series           = new \WP_Term( 99, 'vinland-saga', 'Vinland Saga' );
		$series->taxonomy = 'pa_serie';

		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => 'pa_serie' === $taxonomy
				? array( $series )
				: array()
		);

		$result = ( new BookSchemaEnricher() )
			->restore_series_relationship( array( '@type' => array( 'Product', 'Book' ) ), $product );

		self::assertArrayNotHasKey( 'position', $result, 'El guard es el contrato del parser: cero dato inventado.' );
	}

	/**
	 * @testdox Should preserve an existing relationship when the catalog has no series.
	 */
	public function test_preserves_existing_relationship_without_catalog_series(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}
		};
		Functions\when( 'get_the_terms' )->justReturn( array() );

		$entity = array(
			'@type'    => array( 'Product', 'Book' ),
			'isPartOf' => array( '@id' => 'https://akibara.cl/producto/dan-da-dan-22/#webpage' ),
		);

		self::assertSame(
			$entity,
			( new BookSchemaEnricher() )->restore_series_relationship( $entity, $product ),
			'Missing pa_serie data must not erase a relationship owned by another schema provider.'
		);
	}
}
