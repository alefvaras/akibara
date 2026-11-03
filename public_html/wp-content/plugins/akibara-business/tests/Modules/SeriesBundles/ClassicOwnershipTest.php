<?php
/**
 * Tests for the Series Bundles presentation ownership boundary.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\LoopEnhancer\LoopEnhancer;
use PHPUnit\Framework\TestCase;

final class ClassicOwnershipTest extends TestCase {

	/**
	 * @testdox Should expose semantic notices without owning cart presentation.
	 */
	public function test_notice_provider_has_no_cart_presentation_hooks(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$source      = file_get_contents(
			$plugin_root . '/src/Modules/SeriesBundles/BypCartNoticeProvider.php'
		);

		self::assertIsString( $source, 'The Business notice provider should be readable.' );
		self::assertStringContainsString( 'public function notices()', $source );
		self::assertStringNotContainsString( 'add_action(', $source );
		self::assertStringNotContainsString( 'add_filter(', $source );
		self::assertStringNotContainsString( '<a ', $source );
		self::assertStringNotContainsString( '<button', $source );
		self::assertStringNotContainsString( '?add-to-cart=', $source );
		self::assertStringNotContainsString( 'woocommerce_blocks_', $source );
		self::assertFileDoesNotExist( $plugin_root . '/src/Modules/SeriesBundles/BypCartNoticeInjector.php' );
		self::assertFileDoesNotExist( $plugin_root . '/src/Modules/SeriesBundles/CartIntegration.php' );
	}

	/**
	 * @testdox Product-loop AJAX actions stay non-crawlable outside cart presentation.
	 */
	public function test_product_loop_ajax_actions_do_not_publish_transactional_urls(): void {
		$product = new class() extends \WC_Product {
			public function supports( string $feature ): bool {
				return 'ajax_add_to_cart' === $feature;
			}

			public function is_in_stock(): bool {
				return true;
			}
		};
		$html    = '<a href="https://example.test/?add-to-cart=123" data-quantity="1" class="akb-upsell-item__add add_to_cart_button ajax_add_to_cart" data-product_id="123">Agregar</a>';
		$result  = ( new LoopEnhancer() )->convert_ajax_add_to_cart_link_to_button( $html, $product );

		self::assertStringStartsWith( '<button type="button"', $result );
		self::assertStringEndsWith( '</button>', $result );
		self::assertStringNotContainsString( 'href=', $result );
		self::assertStringNotContainsString( '?add-to-cart=', $result );
	}

	/**
	 * @testdox Should remove all legacy Blocks runtime files from Business.
	 */
	public function test_business_has_no_legacy_blocks_runtime_files(): void {
		$plugin_root = dirname( __DIR__, 3 );

		self::assertFileDoesNotExist(
			$plugin_root . '/src/Modules/OrderPreventa/BlocksIntegration.php',
			'The dedicated adapter must be the sole Store API owner.'
		);
		self::assertFileDoesNotExist(
			$plugin_root . '/assets/js/order-preventa-blocks.js',
			'Preventa Blocks UI must live in the dedicated adapter.'
		);
		self::assertFileDoesNotExist(
			$plugin_root . '/assets/js/series-bundles-blocks.js',
			'Series Bundles Blocks UI must live in the dedicated adapter.'
		);
	}
}
