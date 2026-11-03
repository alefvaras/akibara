<?php
/**
 * Contrato DORADO del schema de Google.
 *
 * Orden explícita del dueño: la forma debe ser LA DEL PLUGIN DE TERCEROS,
 * campo por campo (ingeniería inversa de CR_StructuredData). Este test es la
 * fotografía de esa forma: aggregateRating con EXACTAMENTE 3 claves (sin
 * bestRating ni ratingCount), review[] de a lo más 5 nodos con 5 claves en
 * orden, ratings como STRING crudo, bestRating '5' y worstRating '1' como
 * strings, datePublished ISO-8601. Cualquier desviación rompe acá.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Schema;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.2.0
 */
final class SchemaTest extends RewardsTestCase {

	private Schema $schema;

	private \WC_Product $product;

	/** @var array<string,mixed>|null Args capturados de get_comments. */
	private ?array $query_args = null;

	protected function setUp(): void {
		parent::setUp();

		$this->schema = new Schema();

		$this->product                 = new \WC_Product();
		$this->product->id             = 777;
		$this->product->rating_count   = 3;
		$this->product->average_rating = '4.67'; // El string de 2 decimales de WooCommerce, SIN redondear.
		$this->product->review_count   = 4;      // Woo cuenta reseñas aunque no tengan estrellas: diverge de rating_count.

		$product = $this->product;
		Functions\when( 'wc_get_product' )->alias( static fn() => $product );

		$args    = &$this->query_args;
		$comment = new \WP_Comment(
			array(
				'comment_ID'      => 10,
				'comment_post_ID' => 777,
				'comment_author'  => 'Camila R.',
				'comment_content' => "Llegó impecable.\nEl tomo es precioso.",
			)
		);
		$this->wpdb->comment_meta[10]['rating'] = array( '5' ); // La BD devuelve string.

		Functions\when( 'get_comments' )->alias(
			static function ( array $a ) use ( &$args, $comment ): array {
				$args = $a;
				return array( $comment );
			}
		);
		Functions\when( 'get_comment_author' )->justReturn( 'Camila R.' );
		// FIEL a producción: get_comment_text() pasa por el filtro
		// `comment_text` del core (wpautop + make_clickable). El doble
		// identidad de antes escondía el HTML que se colaba al JSON-LD.
		Functions\when( 'get_comment_text' )->alias(
			static fn( \WP_Comment $c ): string => '<p>' . str_replace( "\n", "<br />\n", $c->comment_content ) . "</p>\n"
		);
		Functions\when( 'get_comment_date' )->justReturn( '2026-08-30T11:04:07-04:00' );
	}

	public function test_the_aggregate_rating_is_the_exact_third_party_shape(): void {
		$entity = $this->schema->inject( array( '@type' => array( 'Product', 'Book' ) ) );

		// EXACTAMENTE 3 claves, en este orden, con el promedio CRUDO.
		$this->assertSame(
			array(
				'@type'       => 'AggregateRating',
				'ratingValue' => '4.67',
				'reviewCount' => 4,
			),
			$entity['aggregateRating']
		);
	}

	public function test_the_review_node_matches_the_third_party_field_by_field(): void {
		$entity = $this->schema->inject( array() );

		$this->assertCount( 1, $entity['review'] );
		$this->assertSame(
			array(
				'@type'        => 'Review',
				'reviewRating' => array(
					'@type'       => 'Rating',
					'bestRating'  => '5',
					'ratingValue' => '5', // El string crudo de la meta, no un int.
					'worstRating' => '1',
				),
				'author'       => array(
					'@type' => 'Person',
					'name'  => 'Camila R.',
				),
				'reviewBody'   => "Llegó impecable.\nEl tomo es precioso.",
				'datePublished' => '2026-08-30T11:04:07-04:00',
			),
			$entity['review'][0]
		);
	}

	public function test_the_review_body_travels_without_html(): void {
		$entity = $this->schema->inject( array() );

		// El JSON-LD lleva el texto del cliente, no el HTML de la plantilla:
		// wpautop metía <p> y <br /> en reviewBody.
		$this->assertStringNotContainsString( '<', (string) $entity['review'][0]['reviewBody'] );
		$this->assertStringContainsString( "\n", (string) $entity['review'][0]['reviewBody'], 'El salto de línea del cliente se conserva crudo.' );
	}

	public function test_the_comment_query_is_cloned_from_the_third_party(): void {
		$this->schema->inject( array() );

		$this->assertSame( 5, $this->query_args['number'] );
		$this->assertSame( 777, $this->query_args['post_id'] );
		$this->assertSame( 'approve', $this->query_args['status'] );
		$this->assertSame( 'publish', $this->query_args['post_status'] );
		$this->assertSame( 'product', $this->query_args['post_type'] );
		$this->assertSame( 0, $this->query_args['parent'] );
		$this->assertSame(
			array(
				array(
					'key'     => 'rating',
					'type'    => 'NUMERIC',
					'compare' => '>',
					'value'   => 0,
				),
			),
			$this->query_args['meta_query']
		);
		// Sin orderby explícito: WordPress cae a comment_date_gmt DESC (las 5
		// más recientes, como el tercero).
		$this->assertArrayNotHasKey( 'orderby', $this->query_args );
	}

	public function test_without_ratings_nothing_is_emitted_and_rank_math_leftovers_are_removed(): void {
		$this->product->rating_count = 0;

		$entity = $this->schema->inject(
			array(
				'aggregateRating' => array( 'de' => 'rank math' ),
				'review'          => array( 'lista sin límite' ),
			)
		);

		$this->assertArrayNotHasKey( 'aggregateRating', $entity );
		$this->assertArrayNotHasKey( 'review', $entity, 'La forma ajena de Rank Math no puede publicarse a medias.' );
	}

	/**
	 * S-66cc662a: las reseñas premiadas entran por defecto (el dueño ya las
	 * curó al aprobarlas y ahora se leen con su aviso), pero si decide que
	 * las estrellas del buscador no se apoyen en reseñas incentivadas, la
	 * query las deja fuera.
	 *
	 * @testdox Con el interruptor encendido, las reseñas premiadas no viajan a Google.
	 */
	public function test_the_owner_can_keep_rewarded_reviews_out_of_the_structured_data(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_schema_exclude_rewarded' === $option ? 'yes' : $default_value
		);

		$this->schema->inject( array() );

		$this->assertCount( 3, $this->query_args['meta_query'], 'La cláusula de rating sigue intacta y se suman las dos exclusiones.' );
		$this->assertSame(
			array(
				'relation' => 'OR',
				array(
					'key'     => '_akb_rewards_review_points',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_akb_rewards_review_points',
					'type'    => 'NUMERIC',
					'compare' => '<=',
					'value'   => 0,
				),
			),
			$this->query_args['meta_query'][1]
		);
		$this->assertSame( '_akb_rewards_review_counted', $this->query_args['meta_query'][2][0]['key'], 'Con «premiar cada N», la que sumó para el premio también es incentivada.' );
	}

	/** @testdox Apagado —el valor de fábrica—, la query es la del tercero, sin añadidos. */
	public function test_the_switch_is_off_by_default(): void {
		$this->schema->inject( array() );

		$this->assertCount( 1, $this->query_args['meta_query'] );
	}

	public function test_non_array_entities_and_missing_product_pass_through(): void {
		$this->assertSame( 'x', $this->schema->inject( 'x' ) );

		Functions\when( 'wc_get_product' )->justReturn( false );
		$this->assertSame( array( 'a' => 1 ), $this->schema->inject( array( 'a' => 1 ) ) );
	}
}
