<?php
/**
 * Tests for product FAQ data.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\ProductFaq;

use Akibara\Business\Modules\ProductFaq\ProductFaq;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\CheckoutTrustSignals;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ProductFaqTest extends TestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ProductFaq
	 */
	private ProductFaq $sut;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->sut = new ProductFaq();

		Functions\when( 'wc_price' )->alias(
			static fn( string $price ): string => '$' . $price
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $value ): string => strip_tags( $value )
		);
		Functions\when( 'wp_list_pluck' )->alias(
			static fn( array $items, string $field ): array => array_map(
				static fn( $item ) => is_object( $item ) ? $item->{$field} : $item[ $field ],
				$items
			)
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		// La promesa de cuotas dejó de estar escrita a mano en la FAQ: se
		// siembra un valor DISTINTO del de fábrica para que el test caiga si
		// alguien vuelve a clavar el literal en el módulo.
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => CheckoutTrustSignals::OPTION_INSTALLMENTS === $name
				? '6 cuotas sin interés'
				: $default
		);
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_term' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should describe each canonical stock status without treating a preorder as in stock.
	 *
	 * @dataProvider stock_copy_provider
	 */
	public function test_uses_canonical_stock_status_copy( int $id, string $status, string $expected, string $unexpected ): void {
		$product = new ProductFaqProductStub( $id, 'Blue Lock 37', '12990', $status );

		$faqs = $this->sut->get_faq_data( array(), $product );
		$answer = $faqs[0]['a'];

		self::assertStringContainsString( $expected, $answer );
		self::assertStringNotContainsString( $unexpected, $answer );
		self::assertStringContainsString( 'Despacho a todo Chile.', $answer );
		self::assertStringContainsString( 'Puedes pagar en hasta 6 cuotas sin interés.', $answer );
		self::assertStringNotContainsString( 'nacional', mb_strtolower( $answer, 'UTF-8' ) );
		self::assertStringContainsString( 'todo chile', mb_strtolower( $answer, 'UTF-8' ) );
		self::assertStringNotContainsString( '12.990', $answer, 'PODA 2026-08-31: el precio es volátil y ya vive a 2 cm — jamás en la FAQ/JSON-LD.' );
		self::assertStringContainsString( 'Cómo comprar', $faqs[0]['q'] );
	}

	/**
	 * @return array<string, array{int, string, string, string}>
	 */
	public function stock_copy_provider(): array {
		return array(
			'a pedido'     => array( 51001, StockStatus::ON_BACKORDER, 'disponible a pedido', 'disponible en stock' ),
			'in stock'     => array( 51002, StockStatus::IN_STOCK, 'disponible en stock', 'disponible a pedido' ),
			'out of stock' => array( 51003, StockStatus::OUT_OF_STOCK, 'actualmente agotado', 'disponible en stock' ),
		);
	}

	/**
	 * @testdox Should avoid universal availability promises in series and publisher answers.
	 */
	public function test_does_not_promise_universal_catalog_availability(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key ): string {
				$meta = array(
					'_akibara_serie'      => 'Blue Lock',
					'_akibara_serie_norm' => 'blue-lock',
					'_akibara_numero'     => '37',
				);

				return $meta[ $key ] ?? '';
			}
		);
		Functions\when( 'get_the_terms' )->alias(
			static function ( int $post_id, string $taxonomy ) {
				return 'product_brand' === $taxonomy
					? array( (object) array( 'name' => 'Ivrea Argentina' ) )
					: false;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_term_by' )->justReturn( false );

		$product = new ProductFaqProductStub( 51004, 'Blue Lock 37', '12990', StockStatus::ON_BACKORDER );

		$answers = implode( ' ', array_column( $this->sut->get_faq_data( array(), $product ), 'a' ) );

		self::assertStringNotContainsString( 'Todos los tomos están disponibles', $answers );
		self::assertStringNotContainsString( 'Todos los títulos de Ivrea Argentina están disponibles', $answers );
		self::assertStringNotContainsString( 'Es el tomo', $answers, 'PODA 2026-08-31: la pregunta del tomo repetía el H1.' );
		self::assertStringNotContainsString( 'editorial publica', $answers, 'PODA 2026-08-31: la editorial ya está enlazada a 2 cm.' );
		self::assertSame( 1, substr_count( $answers, 'Despacho a todo Chile.' ) );
		self::assertSame( 1, substr_count( $answers, 'Puedes pagar en hasta 6 cuotas sin interés.' ) );
		self::assertStringNotContainsString( 'nacional', mb_strtolower( $answers, 'UTF-8' ) );
		self::assertStringContainsString( 'todo chile', mb_strtolower( $answers, 'UTF-8' ) );
		self::assertStringNotContainsString( 'stock físico', mb_strtolower( $answers, 'UTF-8' ) );
		self::assertStringNotContainsString( 'bodega', mb_strtolower( $answers, 'UTF-8' ) );
		self::assertStringNotContainsString( 'Todos los mangas', $answers );
		self::assertStringContainsString( 'Es una edición original publicada por Ivrea Argentina y vendida por Akibara', $answers );
		self::assertStringNotContainsString( 'funda', $answers );
	}

	/**
	 * @testdox Should call manga and comics by their actual category and use neutral copy otherwise.
	 *
	 * @dataProvider publication_copy_provider
	 */
	public function test_uses_category_correct_genre_copy( int $id, string $category_slug, string $parent_slug, string $expected, string $unexpected ): void {
		Functions\when( 'get_the_terms' )->alias(
			static function ( int $post_id, string $taxonomy ) use ( $id, $category_slug ) {
				unset( $post_id );
				return match ( $taxonomy ) {
					'product_cat' => array( (object) array( 'term_id' => $id + 1000, 'slug' => $category_slug ) ),
					'pa_genero'   => array( (object) array( 'name' => 'Acción' ) ),
					default       => false,
				};
			}
		);
		Functions\when( 'get_ancestors' )->alias(
			static fn( int $term_id, string $taxonomy, string $resource_type ): array => '' === $parent_slug ? array() : array( $term_id + 1 )
		);
		Functions\when( 'get_term' )->alias(
			static fn( int $term_id, string $taxonomy ) => (object) array( 'term_id' => $term_id, 'slug' => $parent_slug )
		);

		$product = new ProductFaqProductStub( $id, 'Título de prueba', '12990', StockStatus::IN_STOCK );
		$answers = implode( ' ', array_column( $this->sut->get_faq_data( array(), $product ), 'a' ) );

		self::assertStringContainsString( $expected, $answers );
		self::assertStringNotContainsString( $unexpected, $answers );
	}

	/** @return array<string,array{int,string,string,string,string}> */
	public static function publication_copy_provider(): array {
		return array(
			'manga'        => array( 51005, 'manga', '', 'es un manga de Acción', 'es un cómic' ),
			'comic'        => array( 51006, 'comics', '', 'es un cómic de Acción', 'es un manga' ),
			'neutral'      => array( 51007, 'libros', '', 'pertenece a los géneros Acción', 'es un manga' ),
			'manga child'  => array( 51008, 'shonen', 'manga', 'es un manga de Acción', 'es un cómic' ),
			'comic child'  => array( 51009, 'superheroes', 'comics', 'es un cómic de Acción', 'es un manga' ),
		);
	}

	/**
	 * @testdox No debe leer `_akibara_serie_norm`: era código muerto, la meta no existe en producción (S-bb554c3a).
	 */
	public function test_does_not_read_the_dead_series_norm_meta(): void {
		$meta_keys_read = array();
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key ) use ( &$meta_keys_read ): string {
				unset( $post_id );
				$meta_keys_read[] = $key;
				return '_akibara_serie' === $key ? 'Blue Lock' : '';
			}
		);

		$product = new ProductFaqProductStub( 51010, 'Blue Lock 37', '12990', StockStatus::IN_STOCK );
		$this->sut->get_faq_data( array(), $product );

		self::assertSame(
			array( '_akibara_serie' ),
			$meta_keys_read,
			'S-bb554c3a: `$serie_key` no lo consume nadie — leer `_akibara_serie_norm` es una consulta postmeta de sobra contra una meta que produce CERO filas en producción.'
		);
	}
}

final class ProductFaqProductStub extends \WC_Product {

	private int $product_id;

	private string $product_name;

	private string $product_price;

	private string $product_stock_status;

	public function __construct( int $id, string $name, string $price, string $stock_status ) {
		parent::__construct();
		$this->product_id           = $id;
		$this->product_name         = $name;
		$this->product_price        = $price;
		$this->product_stock_status = $stock_status;
	}

	public function get_id(): int {
		return $this->product_id;
	}

	public function get_name(): string {
		return $this->product_name;
	}

	public function get_price(): string {
		return $this->product_price;
	}

	public function get_stock_status(): string {
		return $this->product_stock_status;
	}
}
