<?php
/**
 * Tests for the BackInStock transactional email.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BackInStock;

use Akibara\Business\Modules\BackInStock\InstockEmail;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class InstockEmailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should pass the WooCommerce product thumbnail directly to the email template.
	 */
	public function test_passes_product_thumbnail_to_template(): void {
		$image_url = 'https://akibara.cl/wp-content/uploads/portadas/one-piece-107-350x525.webp';
		$args      = array();
		$product   = new class() extends \WC_Product {
			public function get_id(): int {
				return 107;
			}

			public function get_name(): string {
				return 'One Piece 107';
			}

			public function get_image_id(): int {
				return 207;
			}

			public function get_price_html(): string {
				return '$12.990';
			}
		};

		Functions\expect( 'wp_get_attachment_image_url' )
			->once()
			->with( 207, 'woocommerce_thumbnail' )
			->andReturn( $image_url );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/producto/one-piece-107/' );
		Functions\when( 'wc_get_template_html' )->alias(
			static function ( string $template, array $template_args, string $template_path, string $default_path ) use ( &$args ): string {
				unset( $template, $template_path, $default_path );
				$args = $template_args;
				return '<p>rendered</p>';
			}
		);

		/** @var InstockEmail $sut */
		$sut         = ( new ReflectionClass( InstockEmail::class ) )->newInstanceWithoutConstructor();
		$sut->object = $product;

		$result = $sut->get_content_html();

		self::assertSame( '<p>rendered</p>', $result );
		self::assertSame( $image_url, $args['product_image_url'] );
		self::assertSame( 'disponible', $args['availability_state'] );
		self::assertSame( 'Disponible', $args['availability_label'] );
		self::assertStringContainsString( '24 a 48 h hábiles', $args['timeframe_copy'] );
		self::assertSame( 'Comprar ahora', $args['cta_label'] );
	}

	/**
	 * @testdox Distingue «A pedido» y «Preventa» con sus plazos y botones propios en los argumentos de la plantilla.
	 */
	public function test_distingue_a_pedido_y_preventa_en_template_args(): void {
		$args = array();

		$producto_a_pedido = new class() extends \WC_Product {
			public function get_id(): int {
				return 501;
			}

			public function get_name(): string {
				return 'Nana 05';
			}

			public function get_image_id(): int {
				return 0;
			}

			public function get_price_html(): string {
				return '$11.990';
			}

			public function get_stock_status(): string {
				return 'onbackorder';
			}
		};

		$producto_preventa = new class() extends \WC_Product {
			public function get_id(): int {
				return 502;
			}

			public function get_name(): string {
				return 'Berserk 42';
			}

			public function get_image_id(): int {
				return 0;
			}

			public function get_price_html(): string {
				return '$14.990';
			}

			public function get_stock_status(): string {
				return 'onbackorder';
			}
		};

		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/producto/nana-05/' );
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key = '', bool $single = false ) {
				unset( $single );
				if ( 502 === $post_id && '_akb_eta_type' === $key ) {
					return 'tba';
				}
				return '';
			}
		);
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'wc_get_template_html' )->alias(
			static function ( string $template, array $template_args, string $template_path, string $default_path ) use ( &$args ): string {
				unset( $template, $template_path, $default_path );
				$args = $template_args;
				return '<p>rendered</p>';
			}
		);

		/** @var InstockEmail $sut */
		$sut         = ( new ReflectionClass( InstockEmail::class ) )->newInstanceWithoutConstructor();
		$sut->object = $producto_a_pedido;
		$sut->get_content_html();

		self::assertSame( 'a_pedido', $args['availability_state'] );
		self::assertSame( 'A pedido', $args['availability_label'] );
		self::assertSame( 'Pedir ahora', $args['cta_label'] );
		self::assertStringContainsString( 'corte de importación', $args['timeframe_copy'] );

		$sut->object = $producto_preventa;
		$sut->get_content_html();

		self::assertSame( 'preventa', $args['availability_state'] );
		self::assertSame( 'Preventa', $args['availability_label'] );
		self::assertSame( 'Reservar preventa', $args['cta_label'] );
		self::assertStringContainsString( 'lanzamiento a bodega', $args['timeframe_copy'] );
	}
}
