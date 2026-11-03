<?php
/**
 * Pruebas de SchemaProductRankMath.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SchemaProductRankMath;

use Akibara\Business\Modules\SchemaProductRankMath\SchemaProductRankMath;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SchemaProductRankMathTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.cl' . $path
		);
		Functions\when( 'strip_shortcodes' )->alias(
			static fn( string $value ): string => (string) preg_replace( '/\[[^\]]*\]/', '', $value )
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $value, bool $remove_breaks = false ): string => strip_tags( $value )
		);
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
		Functions\when( 'get_term_meta' )->justReturn( '' );
		// Estado comercial por defecto de la suite: reservable SIN metas de
		// lanzamiento = «A pedido» → BackOrder. Los tests de preventa real
		// re-mockean esta función con sus metas (Availability lee por
		// get_post_meta, no por WC_Product::get_meta — canal de performance).
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_timezone' )->alias(
			static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should let Rank Math own Product schema only while Rank Math is active.
	 */
	public function test_registers_rank_math_owner_and_suppresses_native_product_when_active(): void {
		$sut          = new SchemaProductRankMath( null, true );
		$registration = $this->capture_registration( $sut );
		$product      = $this->filter_registration( $registration['filters'], 'woocommerce_structured_data_product' );
		$final_graph  = $this->filter_registration( $registration['filters'], 'rank_math/json_ld' );

		self::assertContains(
			'rank_math/snippet/rich_snippet_product_entity',
			array_column( $registration['filters'], 0 ),
			'The Rank Math Product entity must be enriched while Rank Math is active.'
		);
		self::assertContains(
			'rank_math/opengraph/facebook/product_availability',
			array_column( $registration['filters'], 0 ),
			'Preorders must not inherit Rank Math’s false instock Open Graph tag.'
		);
		self::assertNotContains( 'init', array_column( $registration['actions'], 0 ), 'Rank Math configuration must never be mutated from plugin runtime.' );
		self::assertSame( '__return_empty_array', $product[1], 'WooCommerce Product schema should be suppressed only behind the active owner.' );
		self::assertSame( 999, $product[2], 'The suppression must run after other WooCommerce schema enrichments.' );
		self::assertSame( 100, $final_graph[2], 'ComicSeries must be restored after Rank Math connects entities in priority 99.' );
		self::assertSame( 2, $final_graph[3], 'The final graph callback should follow the official Rank Math hook signature.' );
	}

	/**
	 * @testdox Should keep and enrich WooCommerce Product schema when Rank Math is inactive.
	 */
	public function test_registers_native_woocommerce_fallback_when_rank_math_is_inactive(): void {
		$sut          = new SchemaProductRankMath( null, false );
		$registration = $this->capture_registration( $sut );
		$product      = $this->filter_registration( $registration['filters'], 'woocommerce_structured_data_product' );

		self::assertNotContains(
			'rank_math/snippet/rich_snippet_product_entity',
			array_column( $registration['filters'], 0 ),
			'No Rank Math callback should be registered without Rank Math.'
		);
		self::assertNotContains( 'init', array_column( $registration['actions'], 0 ), 'Rank Math configuration must remain UI-owned.' );
		self::assertIsArray( $product[1], 'The native Product filter should use Akibara enrichment instead of suppression.' );
		self::assertSame( $sut, $product[1][0], 'The fallback callback must belong to the schema module.' );
		self::assertSame( 'augment_woocommerce_entity', $product[1][1], 'The fallback must preserve the WooCommerce Product node.' );
		self::assertSame( 20, $product[2], 'The fallback enrichment should run after WooCommerce builds its Product node.' );
		self::assertSame( 2, $product[3], 'The fallback needs the native WC_Product argument.' );
	}

	/**
	 * @testdox Should apply preventa and Book semantics to the native WooCommerce fallback.
	 */
	public function test_augments_native_woocommerce_product_fallback(): void {
		// Fecha de lanzamiento LEJANA a propósito: el estado comercial compara
		// contra el reloj real, y una fecha cercana convertiría este test en una
		// bomba de tiempo (pasaría hoy y fallaría al vencer la fecha, cuando la
		// preventa degrada sola a «A pedido»/BackOrder).
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key = '', bool $single = false ) {
				unset( $post_id, $single );
				if ( '_akb_eta_type' === $key ) {
					return 'release_date';
				}
				return '_akb_eta_release_date' === $key ? '2028-08-31' : '';
			}
		);

		$product = new class(
			'onbackorder',
			array(
				'_akb_eta_type'         => 'release_date',
				'_akb_eta_release_date' => '2028-08-31',
			)
		) extends \WC_Product {
			public function get_id(): int {
				return 123;
			}

				public function get_sku(): string {
					return '9791388298844';
				}

				public function get_name(): string {
					return 'Dan Da Dan 22';
				}

			};
			$entity = array(
				'@type'      => 'Product',
				'description' => 'Compra Dan Da Dan 22 al mejor precio en Akibara.',
				'image'      => 'https://akibara.cl/uploads/cover.jpg',
				'offers'     => array(
				array(
					'@type'        => 'Offer',
					'availability' => 'https://schema.org/BackOrder',
				),
			),
		);

		$result = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity( $entity, $product );

		self::assertSame( 'https://schema.org/PreOrder', $result['offers'][0]['availability'], 'Preventas must use Schema.org PreOrder.' );
		self::assertSame( '2028-08-31', $result['offers'][0]['availabilityStarts'], 'A valid release date (real day, within the 5-year cap) must remain crawlable.' );
		self::assertSame( 'https://schema.org/Book', $result['additionalType'], 'Manga should retain Book semantics in the fallback.' );
		self::assertSame( '9791388298844', $result['isbn'], 'A valid ISBN should remain available without Rank Math.' );
		self::assertSame( 'es', $result['inLanguage'], 'The fallback should retain the catalog language.' );
		self::assertSame( 'https://akibara.cl/uploads/cover.jpg', $result['image'], 'The emitter image must remain unchanged.' );
		self::assertSame( 'Dan Da Dan 22.', $result['description'], 'The native fallback must not retain an emitter-wide commercial template.' );
	}

	/**
	 * @testdox Should not publish an impossible release date (a year with an extra digit) as availabilityStarts.
	 */
	public function test_does_not_publish_an_invalid_release_date(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key = '', bool $single = false ) {
				unset( $post_id, $single );
				if ( '_akb_eta_type' === $key ) {
					return 'release_date';
				}
				return '_akb_eta_release_date' === $key ? '2206-08-31' : '';
			}
		);
		$product = new \WC_Product(
			'onbackorder',
			array(
				'_akb_eta_type'         => 'release_date',
				'_akb_eta_release_date' => '2206-08-31',
			)
		);
		$entity  = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer', 'availability' => 'https://schema.org/BackOrder' ) ),
		);

		$result = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity( $entity, $product );

		self::assertArrayNotHasKey( 'availabilityStarts', $result['offers'][0], 'Google would index a launch two centuries away.' );
	}

	/**
	 * @testdox Should preserve WebP Product images without creating a derivative URL.
	 */
	public function test_preserves_webp_product_images(): void {
		$product = new \WC_Product();
		$images  = array(
			array(
				'@type'  => 'ImageObject',
				'url'    => 'https://akibara.cl/wp-content/uploads/portadas/one-piece-107.webp',
				'width'  => 1000,
				'height' => 1500,
			),
			'https://akibara.cl/wp-content/uploads/portadas/one-piece-107-350x525.webp',
		);
		$entity  = array(
			'@type' => 'Product',
			'image' => $images,
		);

		$result = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity( $entity, $product );

		self::assertSame( $images, $result['image'], 'WebP URLs and ImageObject metadata must remain byte-for-byte.' );
	}

	/**
	 * @testdox Should stage Rank Math books for final multi-type promotion and normalize only Schema.org URLs.
	 */
	public function test_rank_math_entity_uses_google_book_multitype_and_https_schema_urls(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}

			public function get_sku(): string {
				return '9791388298844';
			}

			public function get_name(): string {
				return 'Dan Da Dan 22';
			}
		};
		Functions\when( 'get_the_ID' )->justReturn( 123 );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = ( new SchemaProductRankMath( null, true ) )->augment_entity(
			array(
				'@type'      => 'Product',
				'description' => 'Compra Dan Da Dan 22 al mejor precio en Akibara.',
				'url'        => 'http://example.test/producto/',
				'offers'     => array(
					'@type'        => 'http://schema.org/Offer',
					'availability' => 'http://schema.org/InStock',
				),
			)
		);

		self::assertSame( 'Product', $result['@type'], 'Rank Math should see Product until its graph connector finishes.' );
		self::assertSame( 'Dan Da Dan 22', $result['name'], 'Schema name should match the visible catalog product name.' );
		self::assertSame( 'https://schema.org/Book', $result['additionalType'] );
		self::assertSame( '9791388298844', $result['isbn'] );
		self::assertArrayNotHasKey( 'bookFormat', $result, 'Book format must not be invented.' );
		self::assertSame( 'https://schema.org/Offer', $result['offers']['@type'] );
		self::assertSame( 'https://schema.org/InStock', $result['offers']['availability'] );
		self::assertSame( 'http://example.test/producto/', $result['url'], 'Non-Schema URLs must remain unchanged.' );
		self::assertSame( 'Dan Da Dan 22.', $result['description'], 'The Product description must be factual and specific even with partial catalog facts.' );
	}

	/**
	 * @testdox Should replace a global template with sanitized Product and Book facts while omitting publisher copy.
	 */
	public function test_replaces_global_description_with_sanitized_product_book_facts(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}

			public function get_sku(): string {
				return '';
			}

			public function get_name(): string {
				return "&lt;strong&gt;The&nbsp; Climber\xE2\x80\x8B 01&lt;/strong&gt;[gallery]";
			}
		};
		$author              = new \WP_Term( 253, 'shinichi-sakamoto', '&lt;em&gt;Shinichi&nbsp;Sakamoto&lt;/em&gt;' );
		$series              = new \WP_Term( 99, 'the-climber', "The\xC2\xA0Climber" );
		$series->taxonomy    = 'pa_serie';
		$publisher           = new \WP_Term( 401, 'milky-way', 'Milky Way' );
		$publisher->taxonomy = 'product_brand';

		Functions\when( 'get_the_ID' )->justReturn( 123 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => match ( $taxonomy ) {
				'product_cat'   => array( (object) array( 'slug' => 'manga' ) ),
				'pa_autor'      => array( $author ),
				'pa_serie'      => array( $series ),
				'product_brand' => array( $publisher ),
				default         => array(),
			}
		);
		Functions\when( 'get_term_link' )->alias(
			static fn( \WP_Term $term ): string => 'https://akibara.cl/' . $term->taxonomy . '/' . $term->slug . '/'
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = ( new SchemaProductRankMath( null, true ) )->augment_entity(
			array(
				'@type'       => 'Product',
				'description' => 'Compra The Climber 01 al mejor precio. Envío y 3 cuotas en Akibara.',
			)
		);

		self::assertSame(
			'The Climber 01. Tomo 1 de la serie The Climber. Autoría: Shinichi Sakamoto.',
			$result['description']
		);
		self::assertSame( 'Milky Way', $result['publisher']['name'], 'Publisher schema remains available outside description.' );
		self::assertStringNotContainsString( 'Milky Way', $result['description'], 'Publisher copy is intentionally outside this release.' );
		self::assertStringNotContainsString( 'Akibara', $result['description'] );
		self::assertStringNotContainsString( 'envío', strtolower( $result['description'] ) );
		self::assertStringNotContainsString( 'precio', strtolower( $result['description'] ) );
		self::assertStringNotContainsString( 'cuotas', strtolower( $result['description'] ) );
		self::assertDoesNotMatchRegularExpression( '/<[^>]+>/u', $result['description'], 'Encoded HTML must not reappear after entity decoding.' );
	}

	/**
	 * @testdox Should remove encoded shortcodes and separate only adjacent block markup.
	 */
	public function test_product_description_sanitizes_encoded_shortcodes_without_splitting_inline_text(): void {
		$product = new class() extends \WC_Product {
			public function get_name(): string {
				return '&lt;p&gt;Primera&lt;/p&gt;&lt;p&gt;segunda&lt;/p&gt;&#91;gallery ids=&quot;1&quot;&#93; &lt;strong&gt;sin&lt;/strong&gt;&lt;em&gt;corte&lt;/em&gt;';
			}
		};
		Functions\when( 'get_the_terms' )->justReturn( array( (object) array( 'slug' => 'uncategorized' ) ) );

		$result = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity(
			array( '@type' => 'Product', 'description' => 'Plantilla heredada' ),
			$product
		);

		self::assertSame( 'Primera segunda sincorte.', $result['description'] );
		self::assertStringNotContainsString( 'gallery', $result['description'], 'An entity-encoded shortcode must be removed after decoding.' );
	}

	/**
	 * @dataProvider equivalent_product_and_series_names_provider
	 * @testdox Should omit a tautological Series clause when an unnumbered Book has the same name.
	 */
	public function test_product_description_omits_tautological_unnumbered_series_clause(
		string $product_name,
		string $series_name
	): void {
		$product = new class( $product_name ) extends \WC_Product {
			private string $akb_name;

			public function __construct( string $name ) {
				parent::__construct();
				$this->akb_name = $name;
			}

			public function get_id(): int {
				return 123;
			}

			public function get_sku(): string {
				return '';
			}

			public function get_name(): string {
				return $this->akb_name;
			}
		};
		$author           = new \WP_Term( 253, 'junji-ito', 'Junji Ito' );
		$series           = new \WP_Term( 99, 'uzumaki', $series_name );
		$series->taxonomy = 'pa_serie';

		Functions\when( 'get_the_ID' )->justReturn( 123 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => match ( $taxonomy ) {
				'product_cat' => array( (object) array( 'slug' => 'manga' ) ),
				'pa_autor'    => array( $author ),
				'pa_serie'    => array( $series ),
				default       => array(),
			}
		);
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/serie/uzumaki/' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = ( new SchemaProductRankMath( null, true ) )->augment_entity( array( '@type' => 'Product' ) );

		self::assertSame( $product_name . '. Autoría: Junji Ito.', $result['description'] );
		self::assertStringNotContainsString( 'Serie:', $result['description'] );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function equivalent_product_and_series_names_provider(): array {
		return array(
			'exact equality'          => array( 'Uzumaki', 'Uzumaki' ),
			'Unicode case difference' => array( 'ÚZUMAKI', 'úzumaki' ),
		);
	}

	/**
	 * @testdox Should preserve every author in stable order and remove duplicate names.
	 */
	public function test_product_description_preserves_all_unique_authors(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}

			public function get_sku(): string {
				return '';
			}

			public function get_name(): string {
				return 'Master Keaton 01';
			}
		};
		$naoki   = new \WP_Term( 253, 'naoki-urasawa', 'Naoki Urasawa' );
		$takashi = new \WP_Term( 401, 'takashi-nagasaki', 'Takashi Nagasaki' );
		$yoshimi = new \WP_Term( 402, 'yoshimi', 'Yoshimi' );
		$duplicate_takashi = new \WP_Term( 403, 'takashi-nagasaki-duplicate', 'TAKASHI NAGASAKI' );

		Functions\when( 'get_the_ID' )->justReturn( 123 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => match ( $taxonomy ) {
				'product_cat' => array( (object) array( 'slug' => 'manga' ) ),
				'pa_autor'    => array( $yoshimi, $takashi, $naoki, $duplicate_takashi ),
				default       => array(),
			}
		);
		Functions\when( 'get_term_link' )->justReturn( new \WP_Error() );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$result = ( new SchemaProductRankMath( null, true ) )->augment_entity(
			array( '@type' => 'Product' )
		);

		self::assertSame(
			'Master Keaton 01. Autoría: Naoki Urasawa, Takashi Nagasaki y Yoshimi.',
			$result['description']
		);
		self::assertSame( 1, substr_count( strtolower( $result['description'] ), 'takashi nagasaki' ), 'Different term IDs with the same author name must appear only once.' );
	}

	/**
	 * @testdox Should keep non-Book descriptions name-only and remove an inherited description without a name.
	 */
	public function test_product_description_fails_closed_outside_book_facts_or_without_a_name(): void {
		$product = new class() extends \WC_Product {
			public function get_name(): string {
				return 'Producto de prueba';
			}
		};
		Functions\when( 'get_the_terms' )->justReturn( array( (object) array( 'slug' => 'uncategorized' ) ) );

		$result = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity(
			array(
				'@type'       => 'Product',
				'description' => 'Compra al mejor precio en Akibara.',
				'author'      => array( '@type' => 'Person', 'name' => 'No aplica' ),
				'isPartOf'    => array( '@type' => 'ComicSeries', 'name' => 'No aplica' ),
			),
			$product
		);
		self::assertSame( 'Producto de prueba.', $result['description'] );

		$without_name = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity(
			array(
				'@type'       => 'Product',
				'description' => 'Plantilla heredada',
			),
			new \WC_Product()
		);
		self::assertArrayNotHasKey( 'description', $without_name, 'No factual name means no schema description.' );
	}

	/**
	 * @testdox Should add only complete clauses below 500 characters and never truncate them.
	 */
	public function test_product_description_never_truncates_a_clause_at_the_length_limit(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}

			public function get_sku(): string {
				return '';
			}

			public function get_name(): string {
				return str_repeat( 'N', 499 );
			}
		};
		$author           = new \WP_Term( 253, 'autora', 'Autora completa' );
		$series           = new \WP_Term( 99, 'serie', 'Serie completa' );
		$series->taxonomy = 'pa_serie';

		Functions\when( 'get_the_ID' )->justReturn( 123 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => match ( $taxonomy ) {
				'product_cat' => array( (object) array( 'slug' => 'manga' ) ),
				'pa_autor'    => array( $author ),
				'pa_serie'    => array( $series ),
				default       => array(),
			}
		);
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/serie/serie/' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$result = ( new SchemaProductRankMath( null, true ) )->augment_entity( array( '@type' => 'Product' ) );

		self::assertSame( 500, mb_strlen( $result['description'], 'UTF-8' ) );
		self::assertSame( str_repeat( 'N', 499 ) . '.', $result['description'] );
		self::assertStringNotContainsString( 'Serie completa', $result['description'] );
		self::assertStringNotContainsString( 'Autora completa', $result['description'] );
		self::assertStringNotContainsString( '…', $result['description'] );
	}

	/**
	 * @testdox Should remove a description when even the factual name exceeds 500 characters.
	 */
	public function test_product_description_omits_an_overlong_name_instead_of_truncating_it(): void {
		$product = new class() extends \WC_Product {
			public function get_name(): string {
				return str_repeat( 'N', 500 );
			}
		};
		Functions\when( 'get_the_terms' )->justReturn( array( (object) array( 'slug' => 'uncategorized' ) ) );

		$result = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity(
			array( '@type' => 'Product', 'description' => 'Plantilla heredada' ),
			$product
		);

		self::assertArrayNotHasKey( 'description', $result );
	}

	/**
	 * @testdox Should remove synthetic price expiry and canonicalize only the existing own seller.
	 */
	public function test_sanitizes_generated_offer_facts_for_simple_products(): void {
		$product = new \WC_Product(
			'onbackorder',
			array(
				'_akb_eta_type'         => 'auto',
				'_akb_eta_release_date' => '2026-08-31',
			)
		);
		$seller  = array(
			'@type'   => array( 'Organization', 'OnlineStore' ),
			'@id'     => 'https://akibara.cl/',
			'name'    => 'Akibara',
			'url'     => 'https://akibara.cl/',
			'address' => array( 'streetAddress' => 'Bartolomé Soto 3700' ),
		);
		$entity  = array(
			'@type'  => 'Product',
			'offers' => array(
				'@type'           => 'Offer',
				'price'           => '9500',
				'priceValidUntil' => '2027-12-31',
				'availability'    => 'https://schema.org/BackOrder',
				'seller'          => $seller,
			),
		);

		$result = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity( $entity, $product );

		self::assertArrayNotHasKey( 'priceValidUntil', $result['offers'], 'A generated expiry must not survive without a real WooCommerce sale end.' );
		// `auto` = a pedido (ya publicado, se trae): BackOrder, no PreOrder —
		// separación 1.109.0. PreOrder queda reservado al lanzamiento pendiente.
		self::assertSame( 'https://schema.org/BackOrder', $result['offers']['availability'] );
		self::assertArrayNotHasKey( 'availabilityStarts', $result['offers'], 'Automatic ETA must not become an availability start date.' );
		self::assertArrayNotHasKey( 'shippingDetails', $result['offers'], 'Offer sanitation must not invent shipping facts.' );
		self::assertSame( 'https://akibara.cl/#organization', $result['offers']['seller']['@id'] );
		self::assertSame( $seller['@type'], $result['offers']['seller']['@type'] );
		self::assertSame( $seller['name'], $result['offers']['seller']['name'] );
		self::assertSame( $seller['url'], $result['offers']['seller']['url'] );
		self::assertSame( $seller['address'], $result['offers']['seller']['address'] );
	}

	/**
	 * @testdox Should preserve an explicit price expiry backed by a future WooCommerce sale end.
	 */
	public function test_preserves_explicit_price_expiry_with_future_sale_end(): void {
		$product                      = new \WC_Product();
		$product->akb_date_on_sale_to = new \DateTimeImmutable( '+30 days' );
		$entity                      = array(
			'@type'  => 'Product',
			'offers' => array(
				'@type'           => 'Offer',
				'priceValidUntil' => '2026-09-05',
			),
		);

		$result = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity( $entity, $product );

		self::assertSame( '2026-09-05', $result['offers']['priceValidUntil'], 'The explicit emitter value must remain byte-for-byte.' );
		self::assertArrayNotHasKey( 'seller', $result['offers'], 'Sanitation must not invent a seller.' );
	}

	/**
	 * @testdox Should remove price expiry when the WooCommerce sale end is no longer future.
	 */
	public function test_removes_price_expiry_with_past_sale_end(): void {
		$product                      = new \WC_Product();
		$product->akb_date_on_sale_to = new \DateTimeImmutable( '-1 day' );
		$entity                      = array(
			'@type'  => 'Product',
			'offers' => array(
				'@type'           => 'Offer',
				'priceValidUntil' => '2025-12-31',
			),
		);

		$result = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity( $entity, $product );

		self::assertArrayNotHasKey( 'priceValidUntil', $result['offers'], 'A past sale end must not validate an expired price fact.' );
	}

	/**
	 * @testdox Should sanitize every native Offer while preserving external and non-organization sellers.
	 */
	public function test_sanitizes_offer_lists_without_rewriting_unrelated_sellers(): void {
		$product         = new \WC_Product();
		$external_seller = array(
			'@type' => 'Organization',
			'@id'   => 'https://editorial.example/#organization',
			'name'  => 'Editorial externa',
		);
		$person_seller   = array(
			'@type' => 'Person',
			'@id'   => 'https://akibara.cl/',
			'name'  => 'Persona',
		);
		$entity          = array(
			'@type'  => 'Product',
			'offers' => array(
				array(
					'@type'           => 'Offer',
					'priceValidUntil' => '2027-12-31',
					'seller'          => $external_seller,
				),
				array(
					'@type'           => 'Offer',
					'priceValidUntil' => '2027-12-31',
					'seller'          => $person_seller,
				),
			),
		);

		$result = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity( $entity, $product );

		self::assertArrayNotHasKey( 'priceValidUntil', $result['offers'][0] );
		self::assertArrayNotHasKey( 'priceValidUntil', $result['offers'][1] );
		self::assertSame( $external_seller, $result['offers'][0]['seller'], 'External organizations must remain untouched.' );
		self::assertSame( $person_seller, $result['offers'][1]['seller'], 'A Person must never be rewritten as the store Organization.' );
	}

	/**
	 * @testdox Should leave non-simple product Offer facts untouched.
	 */
	public function test_does_not_sanitize_non_simple_product_offers(): void {
		$product           = new \WC_Product();
		$product->akb_type = 'variable';
		$entity            = array(
			'@type'  => 'Product',
			'offers' => array(
				'@type'           => 'AggregateOffer',
				'priceValidUntil' => '2027-12-31',
				'seller'          => array(
					'@type' => 'Organization',
					'@id'   => 'https://akibara.cl/',
				),
			),
		);

		$result = ( new SchemaProductRankMath( null, false ) )->augment_woocommerce_entity( $entity, $product );

		self::assertSame( $entity['offers'], $result['offers'], 'Variable and aggregate offers are outside this sanitation contract.' );
	}

	/**
	 * @testdox Should omit false Open Graph instock only for preorders.
	 */
	public function test_filters_false_opengraph_availability_for_preorders(): void {
		$preorder = new \WC_Product( 'onbackorder' );
		$instock  = new \WC_Product( 'instock' );

		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_the_ID' )->justReturn( 123 );
		Functions\when( 'wc_get_product' )->alias(
			static function () use ( &$preorder ) {
				return $preorder;
			}
		);

		$sut = new SchemaProductRankMath( null, true );

		self::assertSame( '', $sut->filter_facebook_product_availability( 'instock' ) );

		$preorder = $instock;
		self::assertSame( 'instock', $sut->filter_facebook_product_availability( 'instock' ) );
	}

	/**
	 * @testdox Should restore ComicSeries on the existing Product and preserve mainEntityOfPage.
	 */
	public function test_restores_series_after_rank_math_connects_entities_without_creating_product(): void {
		$product = new class() extends \WC_Product {
			public function get_id(): int {
				return 123;
			}
		};
		$series           = new \WP_Term( 99, 'dan-da-dan', 'Dan Da Dan' );
		$series->taxonomy = 'pa_serie';

		Functions\when( 'get_the_ID' )->justReturn( 123 );
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => 'pa_serie' === $taxonomy
				? array( $series )
				: array()
		);
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/serie/dan-da-dan/' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$schemas = array(
			'WebPage'     => array(
				'@type' => 'ItemPage',
				'@id'   => 'https://akibara.cl/producto/dan-da-dan-22/#webpage',
			),
			'richSnippet' => array(
				'@type'            => 'Product',
				'additionalType'    => 'https://schema.org/Book',
				'@id'              => 'https://akibara.cl/producto/dan-da-dan-22/#richSnippet',
				'description'      => 'Dan Da Dan 22. Tomo 22 de la serie Dan Da Dan. Autoría: Yukinobu Tatsu.',
				'isPartOf'         => array( '@id' => 'https://akibara.cl/producto/dan-da-dan-22/#webpage' ),
				'mainEntityOfPage' => array( '@id' => 'https://akibara.cl/producto/dan-da-dan-22/#webpage' ),
				'offers'           => array( '@type' => 'Offer' ),
			),
		);

		$result = ( new SchemaProductRankMath( null, true ) )->restore_book_series_relationship( $schemas );

		self::assertCount( count( $schemas ), $result, 'The final filter must not add graph nodes.' );
		self::assertSame( array( 'Product', 'Book' ), $result['richSnippet']['@type'] );
		self::assertArrayNotHasKey( 'additionalType', $result['richSnippet'] );
		// El `@id` referencia la entidad que `SeriesSchema` declara en la landing
		// `/serie/dan-da-dan/`; el `url` se conserva para quien no resuelva `@id`.
		self::assertSame(
			array(
				'@type' => 'ComicSeries',
				'name'  => 'Dan Da Dan',
				'@id'   => 'https://akibara.cl/serie/dan-da-dan/#serie',
				'url'   => 'https://akibara.cl/serie/dan-da-dan/',
			),
			$result['richSnippet']['isPartOf']
		);
		self::assertSame( $schemas['richSnippet']['mainEntityOfPage'], $result['richSnippet']['mainEntityOfPage'] );
		self::assertSame( $schemas['richSnippet']['offers'], $result['richSnippet']['offers'] );
		self::assertSame( $schemas['richSnippet']['description'], $result['richSnippet']['description'], 'The priority-100 graph repair must preserve the factual description byte-for-byte.' );

		$product_nodes = array_filter(
			$result,
			static function ( $entity ): bool {
				if ( ! is_array( $entity ) ) {
					return false;
				}
				$types = (array) ( $entity['@type'] ?? array() );
				return in_array( 'Product', $types, true );
			}
		);
		self::assertCount( 1, $product_nodes, 'Rank Math must remain the sole Product owner.' );
	}

	/**
	 * @return array{filters:array<int,array{mixed,mixed,int,int}>,actions:array<int,array{mixed,mixed,int,int}>}
	 */
	private function capture_registration( SchemaProductRankMath $sut ): array {
		$filters = array();
		$actions = array();

		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$filters ): bool {
				$filters[] = array( $hook, $callback, $priority, $accepted_args );
				return true;
			}
		);
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$actions ): bool {
				$actions[] = array( $hook, $callback, $priority, $accepted_args );
				return true;
			}
		);

		$sut->register();

		return array(
			'filters' => $filters,
			'actions' => $actions,
		);
	}

	/**
	 * @param array<int,array{mixed,mixed,int,int}> $filters Registros capturados.
	 * @return array{mixed,mixed,int,int}
	 */
	private function filter_registration( array $filters, string $hook ): array {
		foreach ( $filters as $filter ) {
			if ( $hook === $filter[0] ) {
				return $filter;
			}
		}

		self::fail( "{$hook} was not registered." );
	}
}
