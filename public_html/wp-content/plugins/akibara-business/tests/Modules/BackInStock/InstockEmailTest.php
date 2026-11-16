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
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.cl' . $path );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.cl/mi-cuenta/' );
		Functions\when( 'wc_get_endpoint_url' )->alias( static fn ( string $endpoint, string $value = '', string $permalink = '' ): string => 'https://akibara.cl/mi-cuenta/' . $endpoint . '/' );
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
	 * @testdox Entrega argumentos canónicos de producto en stock para la plantilla de correo.
	 */
	public function test_template_args_para_producto_en_stock(): void {
		$args = array();

		$producto = new class() extends \WC_Product {
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
				return 'instock';
			}
		};

		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/producto/nana-05/' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
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
		$sut->object = $producto;
		$sut->get_content_html();

		self::assertSame( 'disponible', $args['availability_state'] );
		self::assertSame( 'Disponible', $args['availability_label'] );
		self::assertSame( 'Comprar ahora', $args['cta_label'] );
		self::assertStringContainsString( 'En stock en bodega', $args['timeframe_copy'] );
		self::assertFalse( $args['is_registered'] );
		self::assertSame( '', $args['collection_url'] );
	}
}
